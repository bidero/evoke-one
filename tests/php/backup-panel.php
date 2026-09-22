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
 */

$evk_pliki = [
    'settings' => 'evk_backup_get_settings', 'tables' => 'evk_backup_create_tables',
    'storage' => 'evk_backup_dir', 'environment' => 'evk_backup_bytes_label',
    'zip-writer' => 'evk_zip_crc32_combine', 'db-dump' => 'evk_backup_db_dump_step',
    'file-collector' => 'evk_backup_excluded', 'manifest' => 'evk_backup_manifest',
    'engine' => 'evk_backup_tick',
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

    case 'sprzataj':
        @unlink($mu);
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
