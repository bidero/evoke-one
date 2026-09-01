<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Co dostaje w paczce eksportu ktoś, kto ma TYLKO `evk_access_translations`.
 *
 * Osobny plik, bo `tl_export` kończy się `exit` — całe wyjście tego procesu
 * JEST paczką eksportu i test w przeglądarce parsuje ją wprost. Sprawdzanie
 * tego przez helpera („czy lista modułów jest przycięta") byłoby sprawdzaniem
 * własnego arytmetycznego kroku; tu widać dane, które naprawdę wychodzą.
 *
 * W opcjach siedzą celowo rzeczy najgorsze do wyniesienia: hasło SMTP, hasło
 * obejścia trybu konserwacji i źródło snippetu PHP. Żadna z nich nie ma prawa
 * pojawić się w wyniku.
 *
 * Argument 1 wybiera scenariusz:
 *   tlumacz  — rola od tłumaczeń, żądanie BEZ pola `modules` (strona Tłumaczeń)
 *   admin    — administrator, żądanie bez pola `modules` (ma dostać wszystko)
 *   puste    — administrator, `modules` = [] (nic nie zaznaczono w panelu I/O)
 */
require __DIR__ . '/_wp-stubs.php';

define('EVOKE_ONE_VERSION', '0.0.0-test');   // paczka nosi wersję wtyczki
function tl_get_active_lang_codes() { return ['pl', 'en']; }
function bricks_is_builder_main() { return false; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function wp_create_nonce($action = -1) { return 'testnonce'; }
function current_time($type = 'mysql') { return '2026-01-01 00:00:00'; }
/* Zbieracze modułów administratora sięgają po snippety (CPT) i tabele
   newslettera — atrapy oddają puste zbiory, bo tu liczy się KTÓRE klucze
   wychodzą w paczce, a nie ile w nich wierszy. */
function get_posts($args = []) { return []; }
class EVK_Test_Wpdb {
    public $prefix = 'wp_';
    public function get_results($q, $mode = null) { return []; }
}
$GLOBALS['wpdb'] = new EVK_Test_Wpdb();

require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';
require_once EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';
require_once EVK_TEST_ROOT . '/includes/admin/page.php';

$GLOBALS['options'] = [
    'tl_translations'               => ['groups' => ['g1' => ['name' => 'Grupa', 'rows' => []]]],
    'tl_dd_keys'                    => ['witaj' => 'Witaj'],
    'evk_smtp'                      => ['password' => 'tajne-haslo-smtp'],
    'maintenance_bypass_password'   => 'tajne-haslo-konserwacji',
    'evk_snippets_advanced_content' => '<?php echo "kod snippetu";',
    'evk_security'                  => ['limit_login_enabled' => 1],
];

$scenariusz = $argv[1] ?? 'tlumacz';
$GLOBALS['caps'] = $scenariusz === 'tlumacz'
    ? ['evk_access_translations' => true]
    : ['manage_options' => true];

$handler = null;
foreach ($GLOBALS['hooks']['wp_ajax_tl_export'] ?? [] as $cb) { $handler = $cb; }

// Bez pola `modules` — jak „Eksportuj wszystko" na stronie Tłumaczeń.
// Ze pustym polem — jak panel I/O z odznaczonymi wszystkimi modułami.
$_POST = ['nonce' => 'testnonce'];
if ($scenariusz === 'puste') $_POST['modules'] = '[]';

$handler();   // kończy się exit — wyjście procesu to paczka
