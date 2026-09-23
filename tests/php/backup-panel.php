<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — przygotowanie i odczyt stanu dla testu panelu w przeglądarce
 * (tests/backup-panel.test.js) na testowym WordPressie.
 *
 *   przygotuj           moduł włączony, pusto: bez zadań, bez kopii
 *   duzy                plik 150 MB nieściśliwych danych w wp-content (kopia trwa)
 *   bez-loopbacku 0|1   mu-plugin wyłącza żądania zwrotne i WP-Cron — napęd
 *                       zostaje wyłącznie w odpytywaniu z otwartej karty
 *   fakty               kopie, pliki .part, katalogi robocze, zadania
 *   sprzataj            wszystko, co test po sobie zostawił
 *
 * Dla tests/backup-panel-przywracanie.test.js:
 *   przygotuj-przywracanie  kopia zrobiona tu (krokami, w CLI), potem znacznik
 *                           w bazie i pliku, który przywrócenie ma cofnąć; ta
 *                           sama kopia w katalogu FTP (stara) i plik świeży;
 *                           komunikat o nieudanej kopii do zamknięcia
 *   fakty-przywracania      znaczniki, komunikat, katalog FTP, zadania
 *
 * Dla tests/backup-panel-drive.test.js:
 *   przygotuj-dysk <adres> <pośrednik>  atrapa Google i pośrednik tokenów
 *                           (mu-plugin), jedna kopia
 *   fakty-dysku             połączenie, kopie z identyfikatorami Dysku, zadania
 */

$evk_pliki = [
    'settings' => 'evk_backup_get_settings', 'tables' => 'evk_backup_create_tables',
    'storage' => 'evk_backup_dir', 'environment' => 'evk_backup_bytes_label',
    'zip-writer' => 'evk_zip_crc32_combine', 'db-dump' => 'evk_backup_db_dump_step',
    'file-collector' => 'evk_backup_excluded', 'manifest' => 'evk_backup_manifest',
    'engine' => 'evk_backup_tick', 'zip-reader' => 'evk_zip_safe_name', 'serialize-replace' => 'evk_sr_build_pairs',
    'restore' => 'evk_restore_start', 'schedule' => 'evk_backup_next_run',
];
require __DIR__ . '/_testowy-wp.php';
global $wpdb;

$mu    = WP_CONTENT_DIR . '/mu-plugins/evk-test-panel.php';
$duzy  = WP_CONTENT_DIR . '/evk-test-panel-duzy.txt';
$wynik = [];

switch ($argv[1] ?? '') {
    case 'przygotuj':
        evk_backup_create_tables();
        $wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
        /* Wtyczka jest DOWIĄZANIEM do repozytorium (node_modules, vendor) —
           wykluczona, jak każdy inny katalog. */
        update_option(EVK_BACKUP_OPTION, ['enabled' => 1, 'retention_count' => 50,
            'exclusions' => implode("\n", array_merge(evk_backup_default_exclusions(), ['plugins/evoke-one/']))]);
        foreach (evk_backup_list_archives() as $k) evk_backup_delete_archive($k['archive']);
        delete_option('evk_test_bez_loopbacku');
        delete_transient('evk_backup_exposed');
        wp_mkdir_p(dirname($mu));
        file_put_contents($mu, "<?php\n// Wyłącznie test backup-panel (tests/php/backup-panel.php) — usuwany po teście.\n"
            . "if (get_option('evk_test_bez_loopbacku')) {\n    add_filter('evk_backup_loopback', '__return_false');\n"
            . "    if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON', true);\n}\n");
        $wynik = ['wp' => rtrim(ABSPATH, '/')];
        break;

    case 'duzy':
        $f = fopen($duzy, 'w');
        mt_srand(9);
        for ($i = 0; $i < 150; $i++) { $b = ''; for ($k = 0; $k < 262144; $k++) $b .= pack('N', mt_rand()); fwrite($f, $b); }
        fclose($f);
        $wynik = ['rozmiar' => filesize($duzy)];
        break;

    case 'bez-loopbacku':
        update_option('evk_test_bez_loopbacku', ($argv[2] ?? '0') === '1' ? 1 : 0);
        $wynik = ['ok' => true];
        break;

    case 'fakty':
        $wynik = [
            'kopie'   => array_map(static function ($k) {
                return ['archive' => $k['archive'], 'size' => $k['size'], 'pinned' => !empty($k['pinned']), 'source' => $k['source']];
            }, evk_backup_list_archives()),
            'part'    => array_map('basename', glob(evk_backup_dir() . '/*.part*') ?: []),
            'praca'   => array_map('basename', glob(evk_backup_dir() . '/.praca-*') ?: []),
            'zadania' => $wpdb->get_results('SELECT id, status, ticks FROM ' . evk_backup_jobs_table() . ' ORDER BY id', ARRAY_A),
        ];
        break;

    case 'przygotuj-przywracanie':
        evk_backup_create_tables();
        $wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
        update_option(EVK_BACKUP_OPTION, ['enabled' => 1, 'retention_count' => 50, 'notify_notice' => 1,
            'exclusions' => implode("\n", array_merge(evk_backup_default_exclusions(), ['plugins/evoke-one/']))]);
        foreach (evk_backup_list_archives() as $k) evk_backup_delete_archive($k['archive']);
        update_option('home', 'http://stara.test');
        update_option('siteurl', 'http://stara.test');
        delete_option('evk_test_znacznik_panel');
        @unlink(WP_CONTENT_DIR . '/uploads/evk-test-znacznik.txt');
        add_filter('evk_backup_loopback', '__return_false');
        $id = evk_backup_start('manual');
        for ($n = 0; $n < 5000; $n++) {
            $j = evk_backup_job_get($id);
            if (!in_array($j['status'], ['queued', 'running'], true)) break;
            evk_backup_tick($id, 20000);
        }
        // Po kopii: to, co przywrócenie ma cofnąć.
        update_option('evk_test_znacznik_panel', 'po-kopii');
        file_put_contents(WP_CONTENT_DIR . '/uploads/evk-test-znacznik.txt', 'po kopii');
        $imp = evk_backup_import_dir();
        evk_backup_ensure_dir($imp);
        copy(evk_backup_dir() . '/' . $j['archive'], $imp . '/z ftp (1).zip');
        touch($imp . '/z ftp (1).zip', time() - 300);
        file_put_contents($imp . '/wgrywa-sie.zip', 'jeszcze nie cała');
        update_option('evk_backup_alert', ['title' => 'Kopia zapasowa nie powiodła się', 'text' => 'Testowy komunikat.', 'time' => time()], false);
        /* Wgrywanie z przeglądarki: ta sama kopia jako plik „z komputera"
           i kawałek 1 MB (mu-plugin), żeby archiwum ~45 MB szło dziesiątkami kawałków. */
        $zKomputera = sys_get_temp_dir() . '/kopia z komputera.zip';
        copy(evk_backup_dir() . '/' . $j['archive'], $zKomputera);
        update_option('evk_test_chunk', 1048576);
        wp_mkdir_p(dirname($mu));
        file_put_contents($mu, "<?php\n// Wyłącznie testy panelu (tests/php/backup-panel.php) — usuwany po teście.\n"
            . "if (\$evk_k = (int) get_option('evk_test_chunk')) add_filter('evk_backup_upload_chunk', static function () use (\$evk_k) { return \$evk_k; });\n");
        $wynik = ['wp' => rtrim(ABSPATH, '/'), 'status' => $j['status'], 'archive' => $j['archive'], 'plik' => $zKomputera,
                  'rozmiar' => filesize($zKomputera)];
        break;

    case 'ftp-postarz':
        // Plik „wgrywający się" skończył się wgrywać — ma już ponad minutę.
        touch(evk_backup_import_dir() . '/wgrywa-sie.zip', time() - 120);
        $wynik = ['ok' => true];
        break;

    case 'fakty-przywracania':
        wp_cache_flush();
        $imp = evk_backup_import_dir();
        $wynik = [
            'znacznik' => get_option('evk_test_znacznik_panel'),
            'plik'     => file_exists(WP_CONTENT_DIR . '/uploads/evk-test-znacznik.txt'),
            'alert'    => get_option('evk_backup_alert'),
            'ftp'      => array_map('basename', glob($imp . '/*.zip') ?: []),
            'kopie'    => array_map(static function ($k) { return [$k['archive'], $k['source']]; }, evk_backup_list_archives()),
            'zadania'  => $wpdb->get_results('SELECT id, type, status, error FROM ' . evk_backup_jobs_table() . ' ORDER BY id', ARRAY_A),
            'tabele'   => (array) $wpdb->get_col("SHOW TABLES LIKE 'evk%'"),
            'plan'     => get_option('evk_backup_sched'),
            'czesci'   => array_map('basename', glob(evk_backup_dir() . '/.wgrywanie-*') ?: []),
            'wgrana_zgodna' => is_file(evk_backup_dir() . '/kopia-z-komputera.zip')
                && md5_file(evk_backup_dir() . '/kopia-z-komputera.zip') === md5_file(sys_get_temp_dir() . '/kopia z komputera.zip'),
            'ustawienia' => get_option(EVK_BACKUP_OPTION),
        ];
        break;

    case 'przygotuj-dysk':
        /* Dysk Google przez atrapę (tests/php/_google-atrapa.php) pod adresem
           z argumentu: mu-plugin kieruje tam adresy Google i tnie kawałki do
           256 KB. Na liście jedna kopia ~1,3 MB (5 kawałków). */
        evk_backup_create_tables();
        $wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
        update_option(EVK_BACKUP_OPTION, ['enabled' => 1, 'retention_count' => 50, 'notify_address' => 'test@example.com',
            'exclusions' => implode("\n", array_merge(evk_backup_default_exclusions(), ['plugins/evoke-one/']))]);
        foreach (evk_backup_list_archives() as $k) evk_backup_delete_archive($k['archive']);
        delete_option('evk_backup_gdrive');
        delete_option('evk_backup_alert');
        delete_option('evk_test_bez_loopbacku');
        update_option('evk_test_google', (string) ($argv[2] ?? ''));
        update_option('evk_test_google_posrednik', (string) ($argv[3] ?? ''));
        $zip = evk_backup_dir() . '/panel-dysk.zip';
        evk_backup_ensure_dir(evk_backup_dir());
        $w = EVK_Zip_Writer::create($zip);
        $w->add_string('manifest.json', (string) json_encode(['format' => 1, 'created_at' => gmdate('c'), 'home' => 'http://zrodlo-dysku.test',
            'siteurl' => 'http://zrodlo-dysku.test', 'db_rows' => 0, 'files' => 1]));
        mt_srand(21);
        $b = '';
        while (strlen($b) < 1300000) $b .= pack('N', mt_rand());
        file_put_contents(sys_get_temp_dir() . '/evk-panel-dysk.bin', $b);
        $w->add_file(sys_get_temp_dir() . '/evk-panel-dysk.bin', 'wp-content/uploads/evk-dysk.bin', microtime(true) + 60);
        $w->finish();
        @unlink(sys_get_temp_dir() . '/evk-panel-dysk.bin');
        evk_backup_meta_write($zip, ['archive' => 'panel-dysk.zip', 'created_at' => time(), 'source' => 'manual', 'pinned' => false,
            'db_rows' => 0, 'files' => 1]);
        wp_mkdir_p(dirname($mu));
        file_put_contents($mu, "<?php\n// Wyłącznie testy panelu (tests/php/backup-panel.php) — usuwany po teście.\n"
            . "if (\$evk_g = (string) get_option('evk_test_google')) {\n"
            . "    \$evk_p = (string) get_option('evk_test_google_posrednik');\n"
            . "    add_filter('evk_backup_gdrive_endpoints', static function (\$c) use (\$evk_g, \$evk_p) { return array_merge(\$c, ['auth' => \$evk_g . '/o/oauth2/v2/auth',\n"
            . "        'token' => \$evk_p, 'revoke' => \$evk_g . '/revoke', 'api' => \$evk_g . '/drive/v3', 'upload' => \$evk_g . '/upload/drive/v3',\n"
            . "        'redirect' => \$evk_g . '/evk-oauth/', 'client_id' => 'test-klient']); });\n"
            . "    add_filter('evk_backup_gdrive_chunk', static function () { return 262144; });\n}\n"
            . "if (get_option('evk_test_bez_loopbacku')) {\n    add_filter('evk_backup_loopback', '__return_false');\n"
            . "    if (!defined('DISABLE_WP_CRON')) define('DISABLE_WP_CRON', true);\n}\n");
        $wynik = ['wp' => rtrim(ABSPATH, '/'), 'md5' => md5_file($zip), 'rozmiar' => filesize($zip)];
        break;

    case 'fakty-dysku':
        wp_cache_flush();
        $g = get_option('evk_backup_gdrive');
        $wynik = [
            'polaczone' => is_array($g) && !empty($g['refresh']),
            'email'     => is_array($g) ? ($g['email'] ?? '') : '',
            'kopie'     => array_map(static function ($k) {
                $p = evk_backup_dir() . '/' . $k['archive'];
                return ['archive' => $k['archive'], 'source' => $k['source'], 'drive_id' => $k['drive_id'] ?? '', 'md5' => md5_file($p)];
            }, evk_backup_list_archives()),
            'zadania'   => $wpdb->get_results('SELECT id, type, status, error, log FROM ' . evk_backup_jobs_table() . ' ORDER BY id', ARRAY_A),
            'czesci'    => array_map('basename', glob(evk_backup_dir() . '/.pobieranie-*') ?: []),
        ];
        break;

    case 'sprzataj':
        @unlink($mu);
        delete_option('evk_test_google');
        delete_option('evk_test_google_posrednik');
        delete_option('evk_backup_gdrive');
        // Po przywracaniu przez panel adres w bazie mógł przyjąć adres serwera testowego.
        update_option('home', 'http://stara.test');
        update_option('siteurl', 'http://stara.test');
        delete_option('evk_test_znacznik_panel');
        delete_option('evk_backup_alert');
        delete_option('evk_test_chunk');
        @unlink(sys_get_temp_dir() . '/kopia z komputera.zip');
        foreach (glob(evk_backup_dir() . '/.wgrywanie-*') ?: [] as $p) @unlink($p);
        @unlink(WP_CONTENT_DIR . '/uploads/evk-test-znacznik.txt');
        foreach (glob(evk_backup_import_dir() . '/*.zip') ?: [] as $p) @unlink($p);
        wp_unschedule_hook('evk_backup_nightly');
        delete_option('evk_backup_sched');
        @unlink($duzy);
        delete_option('evk_test_bez_loopbacku');
        foreach (evk_backup_list_archives() as $k) evk_backup_delete_archive($k['archive']);
        foreach (glob(evk_backup_dir() . '/*.part*') ?: [] as $p) @unlink($p);
        foreach (glob(evk_backup_dir() . '/.praca-*') ?: [] as $d) evk_backup_rmdir($d);
        $wpdb->query('DELETE FROM ' . evk_backup_jobs_table());
        $wynik = ['ok' => true];
        break;

    default:
        fwrite(STDERR, "Nieznany tryb\n");
        exit(2);
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
