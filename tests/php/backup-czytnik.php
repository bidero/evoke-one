<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — czytnik ZIP (includes/backup/zip-reader.php) w gołym PHP-ie.
 *
 * Archiwa pisze PRAWDZIWY EVK_Zip_Writer (to one są przywracane), obce
 * i złośliwe — ZipArchive. Pliki robocze wyłącznie w sys_get_temp_dir(),
 * sprzątane także po błędzie.
 *
 *   php tests/php/backup-czytnik.php <scenariusz>
 */

define('ABSPATH', __DIR__ . '/');
$root = getenv('EVK_TEST_ROOT') ?: dirname(__DIR__, 2);
require $root . '/includes/backup/zip-writer.php';
require $root . '/includes/backup/zip-reader.php';

$tmp = sys_get_temp_dir() . '/evk-czytnik-' . getmypid();
@mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
});

function losowe(int $n, int $ziarno): string {
    mt_srand($ziarno);
    $s = '';
    while (strlen($s) < $n) $s .= pack('N', mt_rand());
    return substr($s, 0, $n);
}

function tekstowe(int $n): string {
    $s = '';
    for ($i = 0; strlen($s) < $n; $i++) $s .= '{"table":"wp_postmeta","row":{"meta_id":"' . $i . '","meta_value":"zażółć ' . ($i * 7) . "\"}}\n";
    return substr($s, 0, $n);
}

/** Źródła: nazwa w archiwum => treść (null = katalog). */
function zrodla(): array {
    return [
        'manifest.json'            => '{"format":1}',
        'database.jsonl'           => tekstowe(3 * 1048576 + 17),   // deflate, kilka porcji
        'wp-content/uploads/film.mp4' => losowe(2 * 1048576 + 5, 1), // bez kompresji
        'wp-content/losowy.txt'    => losowe(1048576 + 3, 2),       // deflate nieściśliwy: wiele porcji wejścia
        'wp-content/zażółć.txt'    => "gęślą jaźń\n",
        'wp-content/pusty.txt'     => '',
        'wp-content/uploads/pusty/' => null,
        'wp-content/maly.css'      => str_repeat('.a{color:red}', 3),   // < 64 B: bez kompresji
    ];
}

/** Archiwum pisarzem wtyczki. $porcje: plik po pliku z terminem „już", czyli wiele wznowień. */
function zapisz(string $zip, bool $porcje, bool $z64 = false): void {
    global $tmp;
    EVK_Zip_Writer::$force_zip64 = $z64;
    $w = EVK_Zip_Writer::create($zip);
    foreach (zrodla() as $n => $tresc) {
        if ($tresc === null) { $w->add_dir($n); continue; }
        if ($n === 'manifest.json') { $w->add_string($n, $tresc); continue; }
        $src = $tmp . '/src-' . md5($n);
        file_put_contents($src, $tresc);
        do {
            $st = $w->state();
            $w = EVK_Zip_Writer::resume($zip, $st);
            $wynik = $w->add_file($src, $n, $porcje ? 0.0 : microtime(true) + 60);
        } while ($wynik === 'partial');
    }
    $w->finish();
    EVK_Zip_Writer::$force_zip64 = false;
}

function wpisy(string $zip): array {
    $w = [];
    EVK_Zip_Reader::scan($zip, static function ($e) use (&$w) { $w[$e['n']] = $e; });
    return $w;
}

/**
 * Rozpakowuje każdy wpis; $termin = 0 → termin „już": każde wywołanie robi
 * jedną porcję, więc duże wpisy przechodzą przez dziesiątki wznowień.
 */
function rozpakuj(string $zip, array $wpisy, float $termin): array {
    global $tmp;
    $wyn = ['zgodne' => 0, 'rozne' => [], 'wywolan' => []];
    $zr = zrodla();
    foreach ($wpisy as $n => $e) {
        if (!empty($e['d'])) { if (array_key_exists($n, $zr) && $zr[$n] === null) $wyn['zgodne']++; continue; }
        $cel = $tmp . '/out-' . md5($n);
        @unlink($cel);
        $st = ['pos' => 0, 'crc' => 0];
        $ile = 0;
        do {
            $st = EVK_Zip_Reader::extract($zip, $e, $cel, $st, $termin > 0 ? microtime(true) + $termin : 0.0);
            $ile++;
        } while (empty($st['done']));
        $wyn['wywolan'][$n] = $ile;
        if ((string) file_get_contents($cel) === $zr[$n]) $wyn['zgodne']++; else $wyn['rozne'][] = $n;
    }
    return $wyn;
}

$scen = $argv[1] ?? '';
$wynik = [];

switch ($scen) {

    case 'zwykle':
    case 'zip64':
        $zip = $tmp . '/a.zip';
        zapisz($zip, false, $scen === 'zip64');
        $w = wpisy($zip);
        $r = rozpakuj($zip, $w, 60);
        $wynik = [
            'oczekiwanych' => count(zrodla()),
            'wpisow'       => count($w),
            'nazwy_zgodne' => array_keys($w) === array_keys(zrodla()),
            'metody'       => array_map(static function ($e) { return $e['m']; }, $w),
            'katalog'      => !empty($w['wp-content/uploads/pusty/']['d']),
        ] + $r;
        break;

    case 'wznawianie':
        // Pisane w porcjach (pełne opróżnienia deflate w środku), czytane w porcjach.
        $zip = $tmp . '/b.zip';
        zapisz($zip, true);
        $w = wpisy($zip);
        $r = rozpakuj($zip, $w, 0.0);
        $wynik = ['oczekiwanych' => count(zrodla()), 'wpisow' => count($w)] + $r;
        break;

    case 'ubity':
        /* Krok ubity po zapisie danych, a przed zapisem stanu: na dysku jest
           więcej, niż mówi stan. Następne wywołanie ma obciąć plik i dać
           wynik co do bajtu. */
        $zip = $tmp . '/c.zip';
        zapisz($zip, false);
        $w = wpisy($zip);
        $wynik = [];
        foreach (['wp-content/losowy.txt', 'wp-content/uploads/film.mp4'] as $n) {
            $cel = $tmp . '/u-' . md5($n);
            $st = EVK_Zip_Reader::extract($zip, $w[$n], $cel, ['pos' => 0, 'crc' => 0], 0.0);
            $zatwierdzony = $st;
            $st = EVK_Zip_Reader::extract($zip, $w[$n], $cel, $st, 0.0);   // ta porcja „zginie"
            $smieci = filesize($cel) > $zatwierdzony['pos'];
            do { $zatwierdzony = EVK_Zip_Reader::extract($zip, $w[$n], $cel, $zatwierdzony, 0.0); } while (empty($zatwierdzony['done']));
            $wynik[$n] = ['smieci_byly' => $smieci, 'zgodny' => file_get_contents($cel) === zrodla()[$n]];
        }
        // Plik docelowy już był — i był dłuższy (np. po poprzedniej, przerwanej próbie).
        $cel = $tmp . '/u-istnial';
        file_put_contents($cel, str_repeat('X', 3 * 1048576));
        $st = ['pos' => 0, 'crc' => 0];
        do { $st = EVK_Zip_Reader::extract($zip, $w['wp-content/zażółć.txt'], $cel, $st, microtime(true) + 60); } while (empty($st['done']));
        $wynik['istnial_dluzszy'] = file_get_contents($cel) === zrodla()['wp-content/zażółć.txt'];
        break;

    case 'uszkodzone':
        $zip = $tmp . '/d.zip';
        zapisz($zip, false);
        $w = wpisy($zip);
        $wynik = [];
        // Jeden bajt danych zmieniony w pliku bez kompresji i w deflate.
        foreach (['wp-content/uploads/film.mp4' => 'mp4', 'database.jsonl' => 'jsonl', 'wp-content/losowy.txt' => 'txt'] as $n => $klucz) {
            $kopia = $tmp . '/d-' . $klucz . '.zip';
            copy($zip, $kopia);
            $fh = fopen($kopia, 'r+b');
            fseek($fh, $w[$n]['o'] + 30 + strlen($n) + 1000);
            $b = fread($fh, 1);
            fseek($fh, $w[$n]['o'] + 30 + strlen($n) + 1000);
            fwrite($fh, chr(ord($b) ^ 0x55));
            fclose($fh);
            try {
                $st = ['pos' => 0, 'crc' => 0];
                $n_wyw = 0;
                do { $st = EVK_Zip_Reader::extract($kopia, $w[$n], $tmp . '/d-out', $st, microtime(true) + 60); }
                while (empty($st['done']) && ++$n_wyw < 50);
                if (empty($st['done'])) { $wynik[$klucz] = 'bez postępu'; continue; }
                $wynik[$klucz] = 'przeszło';
            } catch (RuntimeException $e) {
                $wynik[$klucz] = $e->getMessage();
            }
        }
        // Archiwum ucięte w połowie — rekord końcowy zniknął.
        $uciete = $tmp . '/d-uciete.zip';
        file_put_contents($uciete, substr((string) file_get_contents($zip), 0, (int) (filesize($zip) / 2)));
        try { wpisy($uciete); $wynik['uciete'] = 'przeszło'; } catch (RuntimeException $e) { $wynik['uciete'] = $e->getMessage(); }
        // Zwykły plik tekstowy.
        file_put_contents($tmp . '/nie.zip', str_repeat('to nie jest zip ', 100));
        try { wpisy($tmp . '/nie.zip'); $wynik['nie_zip'] = 'przeszło'; } catch (RuntimeException $e) { $wynik['nie_zip'] = $e->getMessage(); }
        break;

    case 'obce':
        // Archiwum z ZipArchive (inny pisarz): nagłówki z deskryptorem, inna kolejność pól.
        $zip = $tmp . '/obce.zip';
        $z = new ZipArchive();
        $z->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        foreach (zrodla() as $n => $t) { if ($t === null) $z->addEmptyDir(rtrim($n, '/')); else $z->addFromString($n, $t); }
        $z->close();
        $w = wpisy($zip);
        $wynik = rozpakuj($zip, array_filter($w, static function ($e) { return empty($e['d']); }), 0.0) + ['wpisow' => count($w)];
        break;

    case 'nazwy':
        $wynik = [];
        foreach (['wp-content/a.txt', 'wp-content/uploads/', '../wp-config.php', 'wp-content/../../x', '/etc/passwd',
                  'C:/x', 'wp-content\\..\\x', "wp-content/a\0.php", 'wp-content//a', 'wp-content/./a', '', 'manifest.json'] as $n) {
            $wynik[] = [$n, evk_zip_safe_name($n) !== null];
        }
        break;

    case 'czas':
        // Rozpakowanie z terminem: wywołanie nie przekracza terminu o więcej niż porcję.
        $zip = $tmp . '/e.zip';
        zapisz($zip, false);
        $w = wpisy($zip);
        $t = microtime(true);
        $st = EVK_Zip_Reader::extract($zip, $w['wp-content/losowy.txt'], $tmp . '/e-out', ['pos' => 0, 'crc' => 0], 0.0);
        $wynik = ['czas_ms' => (int) round((microtime(true) - $t) * 1000), 'pos' => $st['pos'],
                  'calosc' => $w['wp-content/losowy.txt']['z'], 'done' => !empty($st['done'])];
        break;
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
