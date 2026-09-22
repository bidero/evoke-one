<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — zbieranie plików (lista + pakowanie), wprost z PRAWDZIWEGO modułu.
 *
 * Drzewo „wp-content" powstaje w sys_get_temp_dir() i jest sprzątane na
 * końcu, także po błędzie. Zawiera każdy przypadek, o który zbieranie może
 * się potknąć: wykluczenia zakotwiczone i po nazwie, katalog z samymi
 * wykluczonymi plikami, pusty katalog, polskie znaki, nazwę spoza UTF-8,
 * plik większy niż krok, dowiązania: na zewnątrz, do przodka, do korzenia
 * systemu, pętlę przez dwa katalogi zewnętrzne, zerwane, oraz FIFO.
 *
 *   php tests/php/backup-pliki.php <scenariusz>
 *
 * Scenariusze: wykluczenia, pelny, ubity.
 */

define('ABSPATH', __DIR__ . '/');
$root = getenv('EVK_TEST_ROOT') ?: dirname(__DIR__, 2);
require $root . '/includes/backup/zip-writer.php';
require $root . '/includes/backup/file-collector.php';

$tmp = sys_get_temp_dir() . '/evk-pliki-' . getmypid();
register_shutdown_function(static function () use ($tmp) {
    if (!is_dir($tmp)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $p = $f->getPathname();
        (is_dir($p) && !is_link($p)) ? @rmdir($p) : @unlink($p);
    }
    @rmdir($tmp);
});

$scen = $argv[1] ?? '';

// ── Wykluczenia: tabela przypadków, bez systemu plików ──────────────────────
if ($scen === 'wykluczenia') {
    $wz = ['cache/', 'upgrade/', '*.log', 'backwpup-*/', 'evk-backups-*/', 'uploads/prywatne/', 'Thumbs.db'];
    $przypadki = [
        // [ścieżka względna, katalog?, oczekiwane]
        ['cache', true, true],                       // zakotwiczony katalog w korzeniu
        ['plugins/foo/cache', true, false],          // ta sama nazwa głębiej — kod wtyczki
        ['cache', false, false],                     // plik o nazwie cache — wzorzec tylko dla katalogów
        ['debug.log', false, true],                  // *.log w korzeniu
        ['plugins/foo/logs/error.log', false, true], // *.log gdziekolwiek
        ['plugins/foo/catalog.php', false, false],   // „log" w środku nazwy nie wystarczy
        ['backwpup-a1b2c3', true, true],             // glob w zakotwiczonym
        ['uploads/backwpup-a1b2c3', true, false],    // …ale tylko w korzeniu
        ['evk-backups-9f8e7d', true, true],
        ['uploads/prywatne', true, true],            // zakotwiczony z „/" w środku
        ['uploads/2026/prywatne', true, false],
        ['uploads/Thumbs.db', false, true],          // nazwa bez globu, gdziekolwiek
        ['upgrade-temp-backup', true, false],        // upgrade/ nie łapie upgrade-temp-backup
    ];
    $wynik = [];
    foreach ($przypadki as [$rel, $kat, $ocz]) {
        $jest = evk_backup_excluded($rel, $kat, $wz);
        $wynik[] = ['rel' => $rel . ($kat ? '/' : ''), 'ok' => $jest === $ocz, 'jest' => $jest];
    }
    echo json_encode($wynik, JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Drzewo ──────────────────────────────────────────────────────────────────
$src = "$tmp/wp-content";
$pliki = [];   // nazwa w archiwum => treść (oczekiwane)
$zapisz = static function (string $rel, string $tresc) use ($src) {
    @mkdir(dirname("$src/$rel"), 0700, true);
    file_put_contents("$src/$rel", $tresc);
};
mt_srand(5);
$los = static function (int $n): string { $s = ''; while (strlen($s) < $n) $s .= pack('N', mt_rand()); return substr($s, 0, $n); };

foreach ([
    'index.php'                     => "<?php // Silence is golden.\n",
    'plugins/foo/foo.php'           => str_repeat("<?php echo 'foo';\n", 500),
    'plugins/foo/cache/kod.php'     => "<?php // kod wtyczki w katalogu cache — ma zostać\n",
    'themes/bricks/style.css'       => str_repeat(".a{color:red}\n", 2000),
    'uploads/2026/09/foto.jpg'      => $los(300000),
    'uploads/zażółć gęślą.txt'      => 'polskie znaki w nazwie',
    'duzy.bin'                      => str_repeat('abcdefghij', 350000),   // 3,5 MB: kilka kroków
] as $rel => $tresc) {
    $zapisz($rel, $tresc);
    $pliki['wp-content/' . $rel] = $tresc;
}
// Nazwa spoza UTF-8: „ą" w cp1250 to bajt 0xB9.
$cp = "uploads/cp1250-\xB9.txt";
$zapisz($cp, 'nazwa w cp1250');
$pliki['wp-content/' . $cp] = 'nazwa w cp1250';

// Wykluczone — nie mogą trafić do archiwum.
$zapisz('cache/strona.html', 'cache');
$zapisz('plugins/foo/debug.log', 'log');
$zapisz('backwpup-abc/stara-kopia.zip', 'stara kopia');
$zapisz('evk-backups-xyz/kopia.zip', 'nasza kopia');
// Katalog z samymi wykluczonymi plikami — istniał, więc ma wpis katalogu.
$zapisz('uploads/tylko-logi/a.log', 'log');
$pliki['wp-content/uploads/tylko-logi/'] = null;
// Pusty katalog.
@mkdir("$src/uploads/2026/10", 0700, true);
$pliki['wp-content/uploads/2026/10/'] = null;

// Dowiązania.
@mkdir("$tmp/media", 0700, true);
file_put_contents("$tmp/media/m1.jpg", 'media z innego dysku');
symlink("$tmp/media", "$src/link-media");                    // na zewnątrz → śledzone
$pliki['wp-content/link-media/m1.jpg'] = 'media z innego dysku';
symlink("$src/uploads", "$src/uploads/2026/petla");           // do przodka → pętla
symlink('/', "$src/plugins/foo/do-korzenia");                 // do korzenia → nadrzędne
symlink($tmp, "$src/link-wyzej");                             // do katalogu nad wp-content → nadrzędne
symlink("$tmp/nie-ma", "$src/zerwany");                       // zerwane
// Pętla przez dwa katalogi zewnętrzne: A → B → A. Bez pamięci skoków nieskończona.
@mkdir("$tmp/zewA", 0700, true); @mkdir("$tmp/zewB", 0700, true);
file_put_contents("$tmp/zewA/a.txt", 'A'); file_put_contents("$tmp/zewB/b.txt", 'B');
symlink("$tmp/zewB", "$tmp/zewA/doB");
symlink("$tmp/zewA", "$tmp/zewB/doA");
symlink("$tmp/zewA", "$src/zewA");
$pliki['wp-content/zewA/a.txt'] = 'A';
$pliki['wp-content/zewA/doB/b.txt'] = 'B';
// Plik specjalny.
$fifo = function_exists('posix_mkfifo') && posix_mkfifo("$src/uploads/kolejka", 0600);

// Podgląd z katalogu głównego.
@mkdir("$tmp/root", 0700, true);
file_put_contents("$tmp/root/.htaccess", "# BEGIN WordPress\n");
$extra = ['_root/.htaccess' => "$tmp/root/.htaccess", '_root/brak.txt' => "$tmp/root/nie-ma.txt"];
$pliki['_root/.htaccess'] = "# BEGIN WordPress\n";

$wzorce = ['cache/', 'upgrade/', '*.log', 'backwpup-*/', 'evk-backups-*/'];

// ── Pomocnicze ──────────────────────────────────────────────────────────────

/** Lista do końca, każdy krok = nowe wywołanie ze stanu; bezpiecznik na pętlę. */
function lista_do_konca(array $st, int &$krokow): array {
    while (!$st['done']) {
        $st = evk_backup_list_step($st, 0.0);
        if (++$krokow > 5000) throw new RuntimeException('lista się nie kończy — pętla w dowiązaniach?');
    }
    return $st;
}

/** Pakowanie do końca: nowy obiekt archiwum w każdym kroku. */
function pakuj_do_konca(string $zip, array $zst, array $pst, int &$krokow): array {
    while (!$pst['done']) {
        $w = EVK_Zip_Writer::resume($zip, $zst);
        $pst = evk_backup_pack_step($w, $pst, 0.0);
        $zst = $w->state();
        unset($w);
        if (++$krokow > 50000) throw new RuntimeException('pakowanie się nie kończy');
    }
    return [$zst, $pst];
}

/** Zawartość archiwum: nazwa => treść (null dla katalogu). */
function archiwum(string $zip): array {
    $z = new ZipArchive();
    if ($z->open($zip, ZipArchive::CHECKCONS | ZipArchive::RDONLY) !== true) return ['BŁĄD' => 'nie otwiera się'];
    $out = [];
    for ($i = 0; $i < $z->numFiles; $i++) {
        $n = $z->getNameIndex($i, ZipArchive::FL_ENC_RAW);
        $out[$n] = substr($n, -1) === '/' ? null : $z->getFromIndex($i);
    }
    $z->close();
    return $out;
}

/** Fakty: czy archiwum = oczekiwane, co nadmiarowe, czego brak. */
function porownanie(array $arch, array $pliki): array {
    $b64 = static function ($n) { return preg_match('//u', $n) ? $n : 'base64:' . base64_encode($n); };
    $brak = []; $rozne = []; $nadmiar = [];
    foreach ($pliki as $n => $t) {
        if (!array_key_exists($n, $arch)) $brak[] = $b64($n);
        elseif ($arch[$n] !== $t) $rozne[] = $b64($n);
    }
    foreach ($arch as $n => $t) if (!array_key_exists($n, $pliki)) $nadmiar[] = $b64($n);
    return ['wpisow' => count($arch), 'oczekiwanych' => count($pliki), 'brak' => $brak, 'rozne' => $rozne, 'nadmiar' => $nadmiar];
}

$lista = "$tmp/lista.jsonl";
$zip   = "$tmp/kopia.zip";
$wynik = [];

switch ($scen) {
    case 'pelny':
        $kl = 0; $kp = 0;
        $st  = lista_do_konca(evk_backup_list_start($src, 'wp-content/', $wzorce, $lista, $extra), $kl);
        [$zst, $pst] = pakuj_do_konca($zip, EVK_Zip_Writer::create($zip)->state(), evk_backup_pack_start($lista), $kp);
        EVK_Zip_Writer::resume($zip, $zst)->finish();
        $wynik = porownanie(archiwum($zip), $pliki) + [
            'krokow_listy' => $kl, 'krokow_pakowania' => $kp, 'log' => $st['log'], 'log_n' => $st['log_n'],
            'fifo' => $fifo, 'lista_plikow' => $st['files'], 'spakowane' => $pst['files'], 'pominiete' => $pst['skipped'],
            'rozmiar_listy' => $st['size'],
        ];
        break;

    case 'ubity':
        /* Lista: kilka kroków, zapamiętany stan, praca bez zapisu stanu,
           śmieci na końcu pliku, wznowienie ze starego stanu. Pakowanie:
           to samo, razem ze stanem archiwum — tick ubity w połowie dużego
           pliku. Wynik ma być identyczny jak bez przerw. */
        $st = evk_backup_list_start($src, 'wp-content/', $wzorce, $lista, $extra);
        for ($i = 0; $i < 4; $i++) $st = evk_backup_list_step($st, 0.0);
        $zapisany = $st;
        for ($i = 0; $i < 3; $i++) $st = evk_backup_list_step($st, 0.0);
        file_put_contents($lista, "{\"n\":\"śmieci", FILE_APPEND);
        $kl = 0;
        $st = lista_do_konca($zapisany, $kl);

        $zst = EVK_Zip_Writer::create($zip)->state();
        $pst = evk_backup_pack_start($lista);
        // Do chwili, gdy duży plik jest w połowie — wtedy zapamiętujemy oba stany.
        $kroki = 0;
        while (true) {
            $w = EVK_Zip_Writer::resume($zip, $zst);
            $pst = evk_backup_pack_step($w, $pst, 0.0);
            $zst = $w->state(); unset($w);
            if (++$kroki > 1000) throw new RuntimeException('duży plik nie trafił w połowę');
            if (($zst['pending']['name'] ?? '') === 'wp-content/duzy.bin') break;
        }
        $zapZip = $zst; $zapPak = $pst;
        for ($i = 0; $i < 6; $i++) {   // praca, której stan „przepadł"
            $w = EVK_Zip_Writer::resume($zip, $zst);
            $pst = evk_backup_pack_step($w, $pst, 0.0);
            $zst = $w->state(); unset($w);
        }
        $kp = 0;
        [$zst, $pst] = pakuj_do_konca($zip, $zapZip, $zapPak, $kp);
        EVK_Zip_Writer::resume($zip, $zst)->finish();
        $wynik = porownanie(archiwum($zip), $pliki) + ['wisial' => 'wp-content/duzy.bin'];
        break;

    default:
        fwrite(STDERR, "Nieznany scenariusz: $scen\n");
        exit(2);
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
