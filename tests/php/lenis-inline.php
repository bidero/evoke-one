<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Drukuje skrypt inline modułu płynnego przewijania.
 *
 * Tryb tempa z argumentu ($argv[1]: 'lerp' albo 'duration'), bo cała rzecz
 * polega na tym, że emitowany jest DOKŁADNIE JEDEN parametr — a to widać
 * dopiero po porównaniu obu wariantów.
 */
require __DIR__ . '/_wp-stubs.php';

define('EVOKE_ONE_URL',     'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', 'test');

function evk_preserve_toggle($input, $key, $field = 'enabled', $default = 0) {
    return isset($input[$field]) ? (int) !empty($input[$field]) : $default;
}
$GLOBALS['options']['evk_lenis'] = ['enabled' => 1]
    + (isset($argv[1]) ? ['tempo' => $argv[1]] : []);
function bricks_is_builder_main() { return false; }

/* Wspólne wykrywanie buildera — moduły frontowe pytają o nie przez
   `evk_w_builderze()`. Plik jest liściem: potrzebuje tylko `is_admin()`
   z atrap i niczego więcej. */
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';

require EVK_TEST_ROOT . '/includes/96-lenis.php';

EVK_Lenis::get_instance()->enqueue_assets();

/* Atrapy zapisują skrypty inline do $GLOBALS['inline'] — bierzemy ten
   podpięty pod uchwyt biblioteki, bo to on niesie konfigurację. */
foreach ($GLOBALS['inline'] as $item) {
    if ($item['handle'] === 'evk-lenis-lib') echo $item['data'];
}
