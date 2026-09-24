<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Jak import CSV newslettera czyta plik z polskiego Excela (średnik, BOM, nagłówek)?
$_SERVER['HTTP_HOST'] = 'stara.test';
require (getenv('EVK_WP_PATH') ?: getenv('HOME') . '/.cache/evk-testowy-wp') . '/wp-load.php';
$csv = "\xEF\xBB\xBFemail;imie;nazwisko\r\njan.kowalski@firma.pl;Jan;Kowalski\r\nanna@example.com;Anna;Nowak\r\n";
foreach (evk_nl_parse_csv($csv) as $e) {
    $s = sanitize_email(trim($e));
    printf("%-45s → sanitize_email: %-32s is_email: %s\n", json_encode($e), $s, is_email($s) ? 'TAK' : 'nie');
}
