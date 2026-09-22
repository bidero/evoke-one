<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — silnik zadań na PRAWDZIWYM WordPressie (tools/testowy-wp.sh).
 *
 * Kroki wołane wprost (evk_backup_tick), bez WP-Cron i bez żądań zwrotnych —
 * te są w sondzie wyłączone, żeby nic nie ruszało zadania poza testem.
 * Budżet kroku zbijany do 1 ms: każdy krok robi jedną porcję, więc zadanie
 * przechodzi przez dziesiątki kroków, jak na wolnym hostingu.
 *
 *   php tests/php/backup-silnik.php <scenariusz>
 *
 * Scenariusze: pelny, lock, ubity, zabity, pamiec, limit_php, anuluj,
 * konserwacja, retencja, nazwy, zajety. `krok <id> <budżet> [pamiec]` —
 * pomocniczy: jeden krok w osobnym procesie.
 */

$evk_pliki = [
    'settings' => 'evk_backup_get_settings', 'tables' => 'evk_backup_create_tables',
    'storage' => 'evk_backup_dir', 'environment' => 'evk_backup_bytes_label',
    'zip-writer' => 'evk_zip_crc32_combine', 'db-dump' => 'evk_backup_db_dump_step',
    'file-collector' => 'evk_backup_excluded', 'manifest' => 'evk_backup_manifest',
    'engine' => 'evk_backup_tick',
];
require __DIR__ . '/_testowy-wp.php';

add_filter('evk_backup_loopback', '__return_false');
global $wpdb;

$scen = $argv[1] ?? '';

// Pomocniczy: jeden krok z budżetem z argumentu — w osobnym procesie.
// Czwarty argument `pamiec`: limit pamięci tuż nad bieżącym zużyciem.
if ($scen === 'krok') {
    /* Limit względem pamięci PRZYDZIELONEJ (true): PHP odmawia limitu
       niższego niż ona. Od memory_get_usage() bez `true` dzieliło ją 2,7 MB
       po dołożeniu plików przywracania (1.227.0) — ini_set() zwracało false,
       limitu nie było i fatal się nie zdarzał. Odmowa idzie teraz na wyjście. */
    if (($argv[4] ?? '') === 'pamiec' && ini_set('memory_limit', (string) (memory_get_usage(true) + 1048576)) === false) {
        echo 'SONDA: limit pamięci odrzucony';
    }
    evk_backup_tick((int) $argv[2], (int) ($argv[3] ?? 20000));
    exit;
}

// ── Przygotowanie ───────────────────────────────────────────────────────────
evk_backup_create_tables();
$wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
/* Wtyczka w testowym WordPressie jest DOWIĄZANIEM do repozytorium (z
   node_modules i vendor) — w kopii nie ma czego szukać, a pakowałaby się
   minutami. Wykluczamy ją jak każdy inny katalog. */
$ustawienia = ['enabled' => 1, 'retention_count' => 50,
               'exclusions' => implode("\n", array_merge(evk_backup_default_exclusions(), ['plugins/evoke-one/']))];
update_option(EVK_BACKUP_OPTION, $ustawienia);
$kopie_przed = array_column(evk_backup_list_archives(), 'archive');
$sprzataj_kopie = static function () use ($kopie_przed) {
    foreach (evk_backup_list_archives() as $k) {
        if (!in_array($k['archive'], $kopie_przed, true)) evk_backup_delete_archive($k['archive']);
    }
    foreach (glob(evk_backup_dir() . '/*.part') ?: [] as $p) { @unlink($p); @unlink($p . '.cd'); }
    foreach (glob(evk_backup_dir() . '/.praca-*') ?: [] as $d) evk_backup_rmdir($d);
};
register_shutdown_function($sprzataj_kopie);

/** 60 MB nieściśliwych danych w .txt (deflate trwa sekundy); sprzątany na końcu. */
function duzy_plik(): string {
    $duzy = WP_CONTENT_DIR . '/evk-test-duzy.txt';
    $f = fopen($duzy, 'w');
    mt_srand(3);
    for ($i = 0; $i < 60; $i++) { $b = ''; for ($k = 0; $k < 262144; $k++) $b .= pack('N', mt_rand()); fwrite($f, $b); }
    fclose($f);
    register_shutdown_function(static function () use ($duzy) { @unlink($duzy); });
    return $duzy;
}

function budzet(int $id, int $ms): void { evk_backup_job_update($id, ['budget_ms' => $ms]); }

/** Kroki do końca zadania (budżet 1 ms — jedna porcja na krok). */
function do_konca(int $id, int $limit = 20000): array {
    $n = 0;
    while (true) {
        $job = evk_backup_job_get($id);
        if (!in_array($job['status'], ['queued', 'running'], true)) return $job;
        budzet($id, 1);
        evk_backup_tick($id);
        if (++$n > $limit) throw new RuntimeException('zadanie się nie kończy');
    }
}

/** Fakty o gotowym archiwum. */
function archiwum_fakty(string $zip): array {
    $z = new ZipArchive();
    if ($z->open($zip, ZipArchive::CHECKCONS | ZipArchive::RDONLY) !== true) return ['otwarte' => false];
    $nazwy = [];
    for ($i = 0; $i < $z->numFiles; $i++) $nazwy[] = $z->getNameIndex($i);
    $m = json_decode((string) $z->getFromName('manifest.json'), true) ?: [];
    $db = (string) $z->getFromName('database.jsonl');
    $wierszy = 0; $naglowkow = 0;
    foreach (explode("\n", trim($db)) as $l) {
        $j = json_decode($l, true);
        if (isset($j['create'])) $naglowkow++; elseif (isset($j['row'])) $wierszy++;
    }
    $z->close();
    return [
        'otwarte'       => true,
        'pierwszy'      => $nazwy[0] ?? '',
        'drugi'         => $nazwy[1] ?? '',
        'wpisow'        => count($nazwy),
        'manifest_url'  => $m['siteurl'] ?? null,
        'manifest_rows' => $m['db_rows'] ?? null,
        'wierszy_db'    => $wierszy,
        'tabel_db'      => $naglowkow,
        'ma_index'      => in_array('wp-content/index.php', $nazwy, true),
        'ma_motyw'      => (bool) preg_grep('#^wp-content/themes/[^/]+/style\.css$#', $nazwy),
        'ma_wtyczke'    => (bool) preg_grep('#^wp-content/plugins/evoke-one/#', $nazwy),
        'ma_kopie'      => (bool) preg_grep('#^wp-content/evk-backups-#', $nazwy),
        'ma_root'       => (bool) preg_grep('#^_root/#', $nazwy),
    ];
}

$wynik = [];
switch ($scen) {

    case 'pelny':
        $id = evk_backup_start('manual');
        $job = do_konca($id);
        $zip = evk_backup_dir() . '/' . $job['archive'];
        $meta = json_decode((string) @file_get_contents($zip . '.json'), true) ?: [];
        $wynik = [
            'status'      => $job['status'],
            'blad'        => $job['error'],
            'krokow'      => $job['ticks'],
            'postep'      => [$job['progress_done'], $job['progress_total']],
            'archiwum'    => is_file($zip),
            'nazwa_ok'    => (bool) preg_match('/^[a-z0-9-]+-\d{4}-\d{2}-\d{2}-\d{4}-reczna-[a-f0-9]{8}\.zip$/', $job['archive']),
            'part_zostal' => (bool) glob(evk_backup_dir() . '/*.part*'),
            'praca_zostala' => is_dir(evk_backup_work_dir($id)),
            'meta'        => ['source' => $meta['source'] ?? null, 'pinned' => $meta['pinned'] ?? null,
                              'size_ok' => ($meta['size'] ?? 0) === filesize($zip), 'db_rows' => $meta['db_rows'] ?? null],
            'na_liscie'   => in_array($job['archive'], array_column(evk_backup_list_archives(), 'archive'), true),
            'zaplanowany' => (bool) wp_next_scheduled('evk_backup_tick', [$id]),
            'lock'        => $job['lock_until'],
            'log_fazy'    => array_values(array_unique(array_filter(array_map(static function ($l) {
                return preg_match('/Krok \d+: ([^,]+),/u', $l, $m) ? $m[1] : null; }, explode("\n", (string) $job['log']))))),
            'fakty'       => archiwum_fakty($zip),
        ];
        break;

    case 'lock':
        $id = evk_backup_start('manual');
        $wpdb->update(evk_backup_jobs_table(), ['lock_until' => time() + 100], ['id' => $id]);
        $r = evk_backup_tick($id);
        $po = evk_backup_job_get($id);
        $wynik['zajety_krok'] = $r === null && $po['ticks'] === 0 && $po['phase'] === 'init';
        $wpdb->update(evk_backup_jobs_table(), ['lock_until' => 0], ['id' => $id]);
        budzet($id, 1);
        evk_backup_tick($id);
        $po2 = evk_backup_job_get($id);
        $wynik['wolny_krok'] = $po2['ticks'] === 1 && $po2['phase'] !== 'init';
        $wynik['lock_zdjety_po_kroku'] = $po2['lock_until'] === 0;
        evk_backup_cancel($id);
        break;

    case 'ubity':
        $id = evk_backup_start('manual');
        for ($i = 0; $i < 3; $i++) { budzet($id, 1); evk_backup_tick($id); }
        /* Krok, który nie dożył końca: lock niezerowy, termin minął. */
        $wpdb->update(evk_backup_jobs_table(), ['lock_until' => time() - 1, 'budget_ms' => 20000], ['id' => $id]);
        /* Budżet kroku nadpisany na 1 ms: krok robi porcję i się kończy, więc
           widać stan PO wykryciu (20 s → 10 s), a nie po skończonej kopii. */
        evk_backup_tick($id, 1);
        $po = evk_backup_job_get($id);
        $wynik['budzet_po'] = $po['budget_ms'];
        $wynik['log_przerwany'] = strpos((string) $po['log'], 'Poprzedni krok przerwany') !== false;
        $wynik['kills_po_udanym'] = $po['kills'];
        $job = do_konca($id);
        $wynik['status'] = $job['status'];
        $wynik['fakty'] = archiwum_fakty(evk_backup_dir() . '/' . $job['archive']);
        // Poddanie się: tyle ubitych z rzędu, ile limit.
        $id2 = evk_backup_start('manual');
        for ($i = 0; $i < EVK_BACKUP_MAX_KILLS; $i++) {
            $wpdb->update(evk_backup_jobs_table(), ['lock_until' => time() - 1], ['id' => $id2]);
            // Krok wykrywa ubitego poprzednika i sam „ginie": lock zostaje.
            $j = evk_backup_job_get($id2);
            if ($j['status'] !== 'running' && $j['status'] !== 'queued') break;
            evk_backup_tick($id2, 1);
            $wpdb->update(evk_backup_jobs_table(), ['lock_until' => time() - 1, 'kills' => $i + 1], ['id' => $id2]);
        }
        evk_backup_tick($id2, 1);
        $j2 = evk_backup_job_get($id2);
        $wynik['poddanie'] = ['status' => $j2['status'], 'blad' => $j2['error']];
        break;

    case 'zabity':
    case 'pamiec':
        /* Krok w OSOBNYM procesie, na ciężkim pliku (60 MB nieściśliwych
           danych w .txt — deflate trwa sekundy):
             zabity — SIGKILL po 1,5 s: tak kończy limit serwera (nginx,
                      LiteSpeed, FPM), którego PHP nie widzi i nie zdąży nic
                      zapisać;
             pamiec — limit pamięci tuż nad zużyciem: fatal PHP, który ma
                      trafić do logu zadania. */
        $duzy = duzy_plik();

        $id = evk_backup_start('manual');
        $n = 0;
        while (($j = evk_backup_job_get($id))['phase'] !== 'pack' || ($j['state']['pack_sub'] ?? '') !== 'files') {
            budzet($id, 1); evk_backup_tick($id);
            if (++$n > 5000) throw new RuntimeException('nie doszło do pakowania');
        }
        /* Krok w połowie dużego pliku (duży idzie pierwszy — pliki z korzenia
           przed podkatalogami): postęp ma wliczać już przeczytane bajty tego
           pliku, inaczej pasek stoi przez cały jego czas (1.226.1). */
        $przed_krokiem = evk_backup_job_get($id);
        budzet($id, 1); evk_backup_tick($id);
        $w_polowie = evk_backup_job_get($id);
        $wynik['wisi_duzy'] = ($w_polowie['state']['zip']['pending']['name'] ?? '') === 'wp-content/evk-test-duzy.txt';
        $wynik['postep_z_polowy'] = $w_polowie['progress_done'] - $przed_krokiem['progress_done'];
        $wynik['wisi_bajtow'] = (int) ($w_polowie['state']['zip']['pending']['pos'] ?? 0);

        $przed = evk_backup_job_get($id);
        $cmd = [PHP_BINARY, __FILE__, 'krok', (string) $id, '20000'];
        if ($scen === 'pamiec') $cmd[] = 'pamiec';
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rury, null, getenv() ?: null);
        if ($scen === 'zabity') {
            usleep(1500000);
            proc_terminate($p, 9);
        }
        $out = stream_get_contents($rury[1]) . stream_get_contents($rury[2]);
        proc_close($p);
        $po = evk_backup_job_get($id);
        $wynik['postep_w_zabitym'] = $po['progress_done'] > $przed['progress_done'];
        $wynik['lock_zostal'] = $po['lock_until'] > 0;
        $wynik['fatal_w_wyjsciu'] = strpos($out, 'Allowed memory size') !== false;
        $wynik['log_fatal'] = strpos((string) $po['log'], 'Krok przerwany błędem PHP: Allowed memory size') !== false;
        // Termin locka „mija" (zamiast czekać minutę) — następny krok ma to rozpoznać.
        $wpdb->update(evk_backup_jobs_table(), ['lock_until' => time() - 1, 'budget_ms' => 20000], ['id' => $id]);
        evk_backup_tick($id, 1);
        $po2 = evk_backup_job_get($id);
        $wynik['log_wykryty'] = strpos((string) $po2['log'], 'Poprzedni krok przerwany') !== false;
        $wynik['budzet_po_wykryciu'] = $po2['budget_ms'];
        $job = do_konca($id);
        $wynik['status'] = $job['status'];
        $wynik['fakty'] = archiwum_fakty(evk_backup_dir() . '/' . $job['archive']);
        $zip = new ZipArchive(); $zip->open(evk_backup_dir() . '/' . $job['archive']);
        $st = $zip->statName('wp-content/evk-test-duzy.txt');
        $wynik['duzy_w_archiwum'] = $st && $st['size'] === filesize($duzy) && ($st['crc'] & 0xFFFFFFFF) === (int) hexdec(hash_file('crc32b', $duzy));
        $zip->close();
        break;

    case 'limit_php':
        /* set_time_limit() zablokowane, max_execution_time = 2 s: budżet ma
           się zmieścić w limicie MINUS czas ładowania WordPressa — krok kończy
           się sam, zamiast zginąć fatalem. Z dużym plikiem: bez przycięcia
           budżetu (20 s) krok MUSIAŁBY przekroczyć 2 s. */
        duzy_plik();
        $id = evk_backup_start('manual');
        $cmd = [PHP_BINARY, '-d', 'max_execution_time=2', '-d', 'disable_functions=set_time_limit', __FILE__, 'krok', (string) $id, '20000'];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rury, null, getenv() ?: null);
        $out = stream_get_contents($rury[1]) . stream_get_contents($rury[2]);
        proc_close($p);
        $po = evk_backup_job_get($id);
        $wynik['fatal'] = strpos($out, 'Maximum execution time') !== false;
        $wynik['krok_zakonczony'] = $po['lock_until'] === 0 && $po['ticks'] === 1;
        $wynik['postep'] = $po['phase'];
        $wynik['wyjscie'] = substr(trim($out), 0, 300);
        evk_backup_cancel($id);
        break;

    case 'anuluj':
        $id = evk_backup_start('manual');
        $n = 0;
        while (evk_backup_job_get($id)['phase'] !== 'pack') { budzet($id, 1); evk_backup_tick($id); if (++$n > 5000) break; }
        budzet($id, 1); evk_backup_tick($id);
        $j = evk_backup_job_get($id);
        $wynik['part_przed'] = is_file($j['state']['zip_path'] ?? '');
        $wynik['anulowane'] = evk_backup_cancel($id);
        $j = evk_backup_job_get($id);
        $wynik['status'] = $j['status'];
        $wynik['part_po'] = (bool) glob(evk_backup_dir() . '/*.part*');
        $wynik['praca_po'] = is_dir(evk_backup_work_dir($id));
        $wynik['krok_po_anulowaniu'] = evk_backup_tick($id) === null;
        $wynik['drugie_anulowanie'] = evk_backup_cancel($id);
        break;

    case 'konserwacja':
        update_option(EVK_BACKUP_OPTION, $ustawienia + ['maintenance_db' => 1]);
        foreach (['' => 'wyłączona', '1' => 'włączona'] as $przed => $opis) {
            update_option('maintenance_mode', $przed);
            $id = evk_backup_start('manual');
            budzet($id, 1); evk_backup_tick($id);   // init
            $w_trakcie = (string) get_option('maintenance_mode');
            $n = 0;
            while (evk_backup_job_get($id)['phase'] === 'db') { budzet($id, 1); evk_backup_tick($id); if (++$n > 5000) break; }
            $po_bazie = (string) get_option('maintenance_mode');
            evk_backup_cancel($id);
            // Anulowanie W TRAKCIE zrzutu też musi przywrócić stan.
            $id2 = evk_backup_start('manual');
            budzet($id2, 1); evk_backup_tick($id2);
            evk_backup_cancel($id2);
            $po_anulowaniu = (string) get_option('maintenance_mode');
            $wynik[$opis] = ['w_trakcie' => $w_trakcie, 'po_bazie' => $po_bazie, 'po_anulowaniu' => $po_anulowaniu];
        }
        update_option('maintenance_mode', '');
        break;

    case 'retencja':
        $dir = evk_backup_dir();
        evk_backup_ensure_dir($dir);
        $t = time();
        $plan = [
            ['r1', 'manual', $t - 500, false], ['r2', 'manual', $t - 400, false], ['r3', 'schedule', $t - 300, false],
            ['r4', 'manual', $t - 200, false], ['r5', 'schedule', $t - 100, false],
            ['stara-przypieta', 'manual', $t - 900, true], ['snapshot', 'snapshot', $t - 800, false],
        ];
        foreach ($plan as [$n, $src, $czas, $pin]) {
            file_put_contents("$dir/test-$n.zip", 'x');
            evk_backup_meta_write("$dir/test-$n.zip", ['created_at' => $czas, 'source' => $src, 'pinned' => $pin]);
        }
        file_put_contents("$dir/test-wgrana.zip", 'x');   // bez opisu — z zewnątrz
        touch("$dir/test-wgrana.zip", $t - 1000);
        update_option(EVK_BACKUP_OPTION, array_merge($ustawienia, ['retention_count' => 2]));
        $usuniete = evk_backup_rotate();
        sort($usuniete);
        $wynik['usuniete'] = $usuniete;
        $zostaly = array_values(array_filter(array_column(evk_backup_list_archives(), 'archive'), static function ($a) { return strpos($a, 'test-') === 0; }));
        sort($zostaly);
        $wynik['zostaly'] = $zostaly;
        $wynik['opisy_usunietych'] = count(array_filter($usuniete, static function ($a) use ($dir) { return is_file("$dir/$a.json"); }));
        // Przypinanie zdejmuje kopię z rotacji.
        evk_backup_set_pinned('test-r4.zip', true);
        $wynik['po_przypieciu'] = evk_backup_rotate();
        foreach (glob("$dir/test-*") ?: [] as $f) @unlink($f);
        break;

    case 'nazwy':
        $dir = evk_backup_dir();
        evk_backup_ensure_dir($dir);
        file_put_contents("$dir/prawdziwa-kopia.zip", 'x');
        file_put_contents(WP_CONTENT_DIR . '/poza.zip', 'x');
        foreach (['prawdziwa-kopia.zip', '../poza.zip', 'a/b.zip', '.htaccess', 'prawdziwa-kopia.zip.json',
                  'nie-ma.zip', '', '.ukryta.zip', "prawdziwa-kopia.zip\0.txt"] as $n) {
            $wynik[$n === '' ? '(pusta)' : str_replace("\0", '\\0', $n)] = evk_backup_archive_path($n) !== null;
        }
        @unlink("$dir/prawdziwa-kopia.zip");
        @unlink(WP_CONTENT_DIR . '/poza.zip');
        break;

    case 'zajety':
        $id = evk_backup_start('manual');
        $drugi = evk_backup_start('manual');
        $wynik['drugi_odrzucony'] = is_wp_error($drugi);
        $wynik['komunikat'] = is_wp_error($drugi) ? $drugi->get_error_message() : '';
        evk_backup_cancel($id);
        $trzeci = evk_backup_start('manual');
        $wynik['po_anulowaniu_mozna'] = !is_wp_error($trzeci);
        if (!is_wp_error($trzeci)) evk_backup_cancel($trzeci);
        break;

    default:
        fwrite(STDERR, "Nieznany scenariusz: $scen\n");
        exit(2);
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
