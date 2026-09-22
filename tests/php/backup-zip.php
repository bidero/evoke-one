<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — strumieniowy zapis ZIP, wprost z PRAWDZIWEGO modułu.
 *
 * Archiwa odczytuje ZipArchive — ten sam, którym czyta restore — z flagą
 * CHECKCONS (spójność katalogu centralnego z nagłówkami lokalnymi), a treść
 * każdego wpisu jest porównywana z plikiem źródłowym.
 *
 * Pliki robocze powstają WYŁĄCZNIE w sys_get_temp_dir() i są sprzątane na
 * końcu, także po błędzie (register_shutdown_function) — katalog tests/
 * jedzie na żywe strony, więc nic nie może zostać w drzewie wtyczki.
 *
 *   php tests/php/backup-zip.php <scenariusz>
 *
 * Wyjście: JSON z FAKTAMI (liczby, sumy, flagi) — oceniają je sprawdzenia
 * w tests/backup-zip.test.js.
 */

define('ABSPATH', __DIR__ . '/');
$root = getenv('EVK_TEST_ROOT') ?: dirname(__DIR__, 2);
require $root . '/includes/backup/zip-writer.php';

$tmp = sys_get_temp_dir() . '/evk-zip-' . getmypid();
@mkdir($tmp, 0700, true);
register_shutdown_function(static function () use ($tmp) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($tmp);
});

/** Pseudolosowe bajty — powtarzalne dla ziarna, nieściśliwe. */
function losowe(int $n, int $ziarno): string {
    mt_srand($ziarno);
    $s = '';
    while (strlen($s) < $n) $s .= pack('N', mt_rand());
    return substr($s, 0, $n);
}

/** Tekst ściśliwy — jak CSS/PHP/JSON. */
function tekstowe(int $n): string {
    $s = '';
    for ($i = 0; strlen($s) < $n; $i++) $s .= ".klasa-$i { color: #6e00a5; margin: {$i}px; } /* zażółć */\n";
    return substr($s, 0, $n);
}

/**
 * Otwiera archiwum ZipArchive z CHECKCONS i porównuje każdy wpis z oczekiwaną
 * treścią. $oczekiwane: nazwa => treść (null = katalog).
 */
function sprawdz(string $zip, array $oczekiwane): array {
    $z = new ZipArchive();
    $kod = $z->open($zip, ZipArchive::CHECKCONS | ZipArchive::RDONLY);
    if ($kod !== true) return ['otwarte' => false, 'kod' => $kod];

    $zgodne = 0; $rozne = []; $metody = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i);
        $nazwa = $st['name'];
        $metody[$nazwa] = $st['comp_method'];
        if (!array_key_exists($nazwa, $oczekiwane)) { $rozne[] = 'nadmiarowy: ' . $nazwa; continue; }
        if ($oczekiwane[$nazwa] === null) { $zgodne++; continue; }
        // Odczyt strumieniem — tak jak restore, który nie trzyma pliku w pamięci.
        $strumien = $z->getStream($nazwa);
        $tresc = $strumien ? stream_get_contents($strumien) : false;
        if ($strumien) fclose($strumien);
        /* CRC z katalogu porównujemy z treścią OSOBNO: ZipArchive czytający
           strumieniem go nie sprawdza (mutacja „CRC bez składania" przeszła
           przez samo porównanie treści na zielono). */
        if ($tresc !== false && ($st['crc'] & 0xFFFFFFFF) !== crc32($tresc)) {
            $rozne[] = $nazwa . ' (CRC w katalogu nie zgadza się z treścią)';
        } elseif ($tresc === $oczekiwane[$nazwa]) $zgodne++;
        else $rozne[] = $nazwa . ' (' . ($tresc === false ? 'nie czyta się' : strlen($tresc) . ' B zamiast ' . strlen($oczekiwane[$nazwa])) . ')';
    }
    $wynik = ['otwarte' => true, 'wpisow' => $z->numFiles, 'zgodne' => $zgodne, 'rozne' => array_slice($rozne, 0, 5),
              'brakujace' => max(0, count($oczekiwane) - $z->numFiles), 'metody' => $metody];
    $z->close();
    return $wynik;
}

$scen = $argv[1] ?? '';
$wynik = [];

switch ($scen) {

    // ── Zwykłe archiwum: różne rodzaje wpisów ──────────────────────────────
    case 'podstawowe':
        @mkdir("$tmp/src/sub", 0700, true);
        $pliki = [
            'style.css'          => tekstowe(200000),
            'uploads/foto.jpg'   => losowe(100000, 1),
            'pusty.txt'          => '',
            'krótki.txt'         => 'abc',
            'zażółć gęślą.txt'   => tekstowe(5000),
            'sub/głęboko/a.php'  => tekstowe(70000),
        ];
        $zip = "$tmp/a.zip";
        $w = EVK_Zip_Writer::create($zip);
        $oczekiwane = [];
        foreach ($pliki as $nazwa => $tresc) {
            $src = "$tmp/src/" . md5($nazwa);
            file_put_contents($src, $tresc);
            $wynik['statusy'][$nazwa] = $w->add_file($src, $nazwa, microtime(true) + 60);
            $oczekiwane[$nazwa] = $tresc;
        }
        $w->add_dir('uploads/pusty-katalog');
        $oczekiwane['uploads/pusty-katalog/'] = null;
        $w->add_string('manifest.json', '{"siteurl":"https://stara.pl"}');
        $oczekiwane['manifest.json'] = '{"siteurl":"https://stara.pl"}';
        $w->finish();
        $wynik['zip'] = sprawdz($zip, $oczekiwane);
        $wynik['oczekiwanych'] = count($oczekiwane);
        $wynik['plik_poboczny_zostal'] = file_exists($zip . '.cd');
        $wynik['rozmiar_zip'] = filesize($zip);
        $wynik['rozmiar_zrodel'] = array_sum(array_map('strlen', $pliki));
        break;

    // ── Plik dłuższy niż tick: każde wywołanie to nowy obiekt ze stanu ─────
    case 'wznawianie':
        $duzy = tekstowe(5 * 1048576) . losowe(3 * 1048576 + 123, 2);
        file_put_contents("$tmp/duzy.bin.txt", $duzy);   // .txt → deflate przez całość
        file_put_contents("$tmp/duzy.jpg", $duzy);       // .jpg → bez kompresji
        $zip = "$tmp/w.zip";
        $stan = EVK_Zip_Writer::create($zip)->state();
        $wywolan = [];
        foreach (['duzy.bin.txt', 'duzy.jpg'] as $n) {
            $wywolan[$n] = 0;
            do {
                /* Termin już minął: każde wywołanie robi jedną porcję i oddaje
                   'partial' — tak wygląda plik większy niż budżet ticka. */
                $w = EVK_Zip_Writer::resume($zip, $stan);
                $s = $w->add_file("$tmp/$n", $n, 0.0);
                $stan = $w->state();
                unset($w);
                $wywolan[$n]++;
            } while ($s === 'partial');
            $wynik['status'][$n] = $s;
        }
        EVK_Zip_Writer::resume($zip, $stan)->finish();
        $wynik['wywolan'] = $wywolan;
        $wynik['zip'] = sprawdz($zip, ['duzy.bin.txt' => $duzy, 'duzy.jpg' => $duzy]);
        break;

    // ── Tick ubity: śmieci za stanem zatwierdzonym, stan sprzed wywołania ──
    case 'ubity':
        $a = tekstowe(3 * 1048576);
        $b = losowe(2 * 1048576, 3);
        file_put_contents("$tmp/a.txt", $a);
        file_put_contents("$tmp/b.dat", $b);
        $zip = "$tmp/u.zip";
        $w = EVK_Zip_Writer::create($zip);
        $w->add_file("$tmp/a.txt", 'a.txt', 0.0);          // partial po pierwszej porcji
        $stan = $w->state();                                // ← ostatni zapisany stan
        $w->add_file("$tmp/a.txt", 'a.txt', 0.0);          // ta praca „nie zdążyła" zapisać stanu
        $w->add_file("$tmp/a.txt", 'a.txt', microtime(true) + 60);
        $w->add_file("$tmp/b.dat", 'b.dat', microtime(true) + 60);
        unset($w);
        // Dodatkowo śmieci dopisane na końcu obu plików, jak po ubitym zapisie.
        file_put_contents($zip, str_repeat("\xAB", 70000), FILE_APPEND);
        file_put_contents($zip . '.cd', str_repeat("\xCD", 333), FILE_APPEND);
        $wynik['rozmiar_przed_wznowieniem'] = filesize($zip);
        $wynik['stan_offset'] = $stan['offset'];

        $w = EVK_Zip_Writer::resume($zip, $stan);
        do { $s = $w->add_file("$tmp/a.txt", 'a.txt', microtime(true) + 60); } while ($s === 'partial');
        $w->add_file("$tmp/b.dat", 'b.dat', microtime(true) + 60);
        $w->finish();
        $wynik['zip'] = sprawdz($zip, ['a.txt' => $a, 'b.dat' => $b]);
        break;

    // ── Plik kurczy się między tickami ─────────────────────────────────────
    case 'kurczy':
        file_put_contents("$tmp/log.txt", tekstowe(4 * 1048576));
        file_put_contents("$tmp/inny.txt", 'inny plik, pełny');
        $zip = "$tmp/k.zip";
        $w = EVK_Zip_Writer::create($zip);
        $wynik['pierwszy'] = $w->add_file("$tmp/log.txt", 'log.txt', 0.0);
        $offset_przed = $w->state()['offset'];
        $f = fopen("$tmp/log.txt", 'r+'); ftruncate($f, 1000); fclose($f);
        $wynik['po_skurczeniu'] = $w->add_file("$tmp/log.txt", 'log.txt', microtime(true) + 60);
        $wynik['wisi_po'] = $w->has_pending();
        $wynik['offset_po'] = $w->state()['offset'];
        $wynik['offset_przed'] = $offset_przed;
        $wynik['zniknal'] = $w->add_file("$tmp/nie-ma-mnie.txt", 'nie-ma-mnie.txt', microtime(true) + 60);
        $w->add_file("$tmp/inny.txt", 'inny.txt', microtime(true) + 60);
        $w->finish();
        $wynik['zip'] = sprawdz($zip, ['inny.txt' => 'inny plik, pełny']);
        break;

    // ── ZIP64 wymuszony dla każdego wpisu i rekordu końcowego ──────────────
    case 'zip64':
        EVK_Zip_Writer::$force_zip64 = true;
        $t = tekstowe(300000);
        $r = losowe(200000, 4);
        file_put_contents("$tmp/t.txt", $t);
        file_put_contents("$tmp/r.jpg", $r);
        $zip = "$tmp/z.zip";
        $w = EVK_Zip_Writer::create($zip);
        $w->add_file("$tmp/t.txt", 't.txt', microtime(true) + 60);
        do { $s = $w->add_file("$tmp/r.jpg", 'r.jpg', 0.0); } while ($s === 'partial');
        $w->add_dir('katalog');
        $w->add_string('m.json', '{}');
        $w->finish();
        $surowe = (string) file_get_contents($zip);
        $wynik['rekord_zip64'] = strpos($surowe, pack('V', 0x06064b50)) !== false;
        $wynik['lokator_zip64'] = strpos($surowe, pack('V', 0x07064b50)) !== false;
        $wynik['zip'] = sprawdz($zip, ['t.txt' => $t, 'r.jpg' => $r, 'katalog/' => null, 'm.json' => '{}']);
        break;

    // ── Ponad 65 535 wpisów: ZIP64 musi włączyć się SAM ─────────────────────
    case 'wiele':
        $ile = (int) ($argv[2] ?? 70000);
        $zip = "$tmp/m.zip";
        $w = EVK_Zip_Writer::create($zip);
        $t0 = microtime(true);
        for ($i = 0; $i < $ile; $i++) $w->add_string("uploads/2026/m-$i.txt", "plik $i");
        $w->finish();
        $wynik['sekund'] = round(microtime(true) - $t0, 2);
        $surowe = (string) file_get_contents($zip, false, null, max(0, filesize($zip) - 200));
        $wynik['rekord_zip64'] = strpos($surowe, pack('V', 0x06064b50)) !== false;
        $z = new ZipArchive();
        $wynik['otwarte'] = $z->open($zip, ZipArchive::CHECKCONS | ZipArchive::RDONLY) === true;
        $wynik['wpisow'] = $wynik['otwarte'] ? $z->numFiles : 0;
        $wynik['ostatni'] = $wynik['otwarte'] ? $z->getFromName('uploads/2026/m-' . ($ile - 1) . '.txt') : null;
        $wynik['ile'] = $ile;
        break;

    // ── Składanie CRC kontra crc32() z całości ─────────────────────────────
    case 'crc':
        mt_srand(7);
        $zle = 0; $prob = 300;
        for ($i = 0; $i < $prob; $i++) {
            $dane = losowe(mt_rand(0, 5000), $i);
            $ciecie = mt_rand(0, strlen($dane));
            $a = substr($dane, 0, $ciecie);
            $b = substr($dane, $ciecie);
            if (evk_zip_crc32_combine(crc32($a), crc32($b), strlen($b)) !== crc32($dane)) $zle++;
        }
        $wynik = ['prob' => $prob, 'zle' => $zle];
        break;

    default:
        fwrite(STDERR, "Nieznany scenariusz: $scen\n");
        exit(2);
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
