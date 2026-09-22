<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Wspólne wejście sond, które potrzebują PRAWDZIWEGO WordPressa i bazy
 * (środowisko: tools/testowy-wp.sh, katalog w EVK_WP_PATH).
 *
 *   $evk_pliki = ['db-dump' => 'evk_backup_db_dump_step', …];
 *   require __DIR__ . '/_testowy-wp.php';
 *
 * Dołączany z ZAKRESU GLOBALNEGO, nie wołany jako funkcja: wp-config.php
 * i wp-settings.php zakładają zmienne globalne ($table_prefix i dziesiątki
 * innych) — z wnętrza funkcji stałyby się lokalne.
 *
 * Bez środowiska sonda oddaje {"brak": "…"} i kończy — test zapala się na
 * czerwono z instrukcją, zamiast po cichu nic nie sprawdzać.
 *
 * $evk_pliki: pliki z includes/backup/ (bez .php) => funkcja, po której
 * poznać, że plik jest już załadowany. Wtyczka w testowym WordPressie jest
 * DOWIĄZANIEM do repozytorium, więc jej pliki mogą być załadowane spod innej
 * ścieżki — require_once by tego nie rozpoznał; pytamy o funkcję, nie o plik.
 *
 * Nie używać zmiennej `$wp` w sondzie: tak nazywa się globalny obiekt
 * WordPressa, a jego nadpisanie wywraca rejestrację taksonomii.
 */

$evk_sciezka_wp = getenv('EVK_WP_PATH') ?: (getenv('HOME') . '/.cache/evk-testowy-wp');
if (!is_file($evk_sciezka_wp . '/wp-load.php') || !is_file($evk_sciezka_wp . '/wp-config.php')) {
    echo json_encode(['brak' => 'Brak testowego WordPressa w ' . $evk_sciezka_wp . ' — uruchom tools/testowy-wp.sh']);
    exit;
}
$_SERVER['HTTP_HOST']   = 'stara.test';
$_SERVER['REQUEST_URI'] = '/';
if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', false);
require $evk_sciezka_wp . '/wp-load.php';

$evk_root = getenv('EVK_TEST_ROOT') ?: dirname(__DIR__, 2);
foreach ($evk_pliki ?? [] as $evk_plik => $evk_fn) {
    if (!function_exists($evk_fn)) require $evk_root . '/includes/backup/' . $evk_plik . '.php';
}
