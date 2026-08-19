<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Zależności skryptu Offcanvas Menu — dla danego efektu otwierania.
 *
 * Wygięta ściana potrzebuje GSAP-a (ścieżkę trzeba wpisywać co klatkę), reszta
 * menu jedzie na przejściach CSS i biblioteki nie potrzebuje. Obietnica brzmi:
 * GSAP doładowuje się TYLKO przy tym jednym efekcie. Sprawdzić ją da się
 * wyłącznie tutaj — z przeglądarki nie widać, czego strona NIE pobrała.
 *
 * Argument: nazwa efektu ('slide', 'reveal', 'curve').
 */
require __DIR__ . '/_wp-stubs.php';
require __DIR__ . '/_bricks-stubs.php';

define('EVK_OFFCANVAS_MENU_URL',     'https://example.test/oc/');
define('EVK_OFFCANVAS_MENU_VERSION', 'test');
define('EVK_BRICKS_CATEGORY',        'evoke');

/* Atrapy stylów — `_wp-stubs.php` ma tylko skrypty, bo dotąd żaden test nie
   pytał o arkusze. Element enqueue'uje oba, więc bez tego pada na pierwszej
   linii i o zależnościach skryptu nie dowiedzielibyśmy się nic. */
if (!function_exists('wp_enqueue_style')) {
    function wp_enqueue_style($handle, $src = '', $deps = [], $ver = false, $media = 'all') {
        $GLOBALS['style'][$handle] = ['src' => $src, 'deps' => $deps];
    }
}

// Rejestracja GSAP-a jak w includes/89-gsap.php — liczy się to, czy element
// dopisze uchwyt do zależności, a nie skąd biblioteka pochodzi.
function evk_register_gsap_libs() {
    $GLOBALS['gsap_zarejestrowany'] = true;
}
function evk_anim_easings() { return ['power2.out']; }
function evk_anim_easing_css($e) { return 'cubic-bezier(0.33, 1, 0.68, 1)'; }

require EVK_TEST_ROOT . '/includes/bricks-elements/evoke-offcanvas-menu/element.php';

$el = new \Bricks\Evk_Offcanvas_Menu();
$el->settings = ['openEffect' => $argv[1] ?? 'slide'];
$el->enqueue_scripts();

echo json_encode([
    'deps'  => $GLOBALS['enqueued']['evk-offcanvas-menu-js']['deps'] ?? null,
    'gsap'  => !empty($GLOBALS['gsap_zarejestrowany']),
], JSON_UNESCAPED_UNICODE), "\n";
