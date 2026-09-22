<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Backup — ocena środowiska serwera i to, co z niej wynika w zakładce.
 *
 * Fakty są PODSTAWIANE (argument 1, JSON nakładany na zestaw „wszystko
 * w porządku"), więc da się zbadać serwer bez ZipArchive albo z nginx bez
 * posiadania takiego serwera. Ocena i render są prawdziwe.
 *
 *   php tests/php/backup-srodowisko.php '{"zip":false}' [wlaczony]
 *
 * Wyjście: {"checks":[…], "blocked":bool, "html":"…zakładka…", "bajty":{…}}
 */

require __DIR__ . '/_wp-stubs.php';
function disabled($a, $b = true, $echo = true) {
    $out = ($a == $b) ? ' disabled' : '';
    if ($echo) echo $out;
    return $out;
}

require EVK_TEST_ROOT . '/includes/backup/settings.php';
require EVK_TEST_ROOT . '/includes/backup/tables.php';
require EVK_TEST_ROOT . '/includes/backup/environment.php';

$dobre = [
    'php' => '8.3.0', 'zip' => true, 'libzip' => '1.10.1', 'deflate' => true, 'multisite' => false,
    'server' => 'Apache', 'max_execution' => 30, 'memory_limit' => '256M', 'upload_max' => '64M',
    'post_max' => '128M', 'disk_free' => 50.0 * 1073741824, 'content_write' => true,
    'wp_cron_off' => false, 'jobs_table' => null,
];
$bk_facts = array_merge($dobre, (array) json_decode($argv[1] ?? '{}', true));
$GLOBALS['options']['evk_backup'] = ['enabled' => ($argv[2] ?? '') === 'wlaczony' ? 1 : 0];

$checks = evk_backup_environment_checks($bk_facts);
ob_start();
require EVK_TEST_ROOT . '/includes/admin/tab-backup.php';
$html = ob_get_clean();

echo json_encode([
    'checks'  => $checks,
    'blocked' => evk_backup_environment_blocked($checks),
    'html'    => $html,
    'bajty'   => array_map('evk_backup_ini_bytes', ['128M' => '128M', '1G' => '1G', '512K' => '512K', '-1' => '-1', '' => '', '1000' => '1000']),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
