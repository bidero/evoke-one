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
        $wynik = ['wp' => rtrim(ABSPATH, '/'), 'status' => $j['status'], 'archive' => $j['archive']];
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
            'ustawienia' => get_option(EVK_BACKUP_OPTION),
        ];
        break;

    case 'sprzataj':
        @unlink($mu);
        // Po przywracaniu przez panel adres w bazie mógł przyjąć adres serwera testowego.
        update_option('home', 'http://stara.test');
        update_option('siteurl', 'http://stara.test');
        delete_option('evk_test_znacznik_panel');
        delete_option('evk_backup_alert');
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
