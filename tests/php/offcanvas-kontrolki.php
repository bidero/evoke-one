<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Kolejność kontrolek Offcanvas Menu — z PRAWDZIWEGO `set_controls()`.
 *
 * ZGŁOSZONE Z UŻYCIA: „kontrolkę »Trzymaj otwarte w builderze« trzeba przesunąć
 * na górę, jak w Circular Menu". To jedyna kontrolka używana PODCZAS składania
 * menu, a nie przy jego ustawianiu — na dole listy trafiało się na nią dopiero
 * wtedy, gdy nie była już potrzebna.
 *
 * Kolejności nie widać z przeglądarki: panel Bricksa to osobna aplikacja Vue
 * w oknie buildera, a testy chodzą po froncie. Widać ją wyłącznie stąd —
 * z kolejności kluczy, którą `set_controls()` wpisuje do tablicy.
 *
 * Wypisuje JSON: klucze w kolejności wpisania, dla obu elementów naraz.
 * Circular Menu jest tu WZORCEM, a nie ozdobą: zgłoszenie brzmiało „jak
 * w Circular Menu", więc sprawdzenie ma pilnować tej pary, a nie samej liczby
 * porządkowej w Offcanvasie.
 */
require __DIR__ . '/_wp-stubs.php';
require __DIR__ . '/_bricks-stubs.php';

define('EVK_OFFCANVAS_MENU_URL',     'https://example.test/oc/');
define('EVK_OFFCANVAS_MENU_VERSION', 'test');
define('EVK_CIRCULAR_MENU_URL',      'https://example.test/cm/');
define('EVK_CIRCULAR_MENU_VERSION',  'test');
define('EVK_BRICKS_CATEGORY',        'evoke');

/* Krzywe idą ze wspólnego słownika (`includes/anim/presets.php`) — tu wystarczy
   atrapa, bo pytanie dotyczy KOLEJNOŚCI kontrolek, nie ich zawartości. */
function evk_anim_easings() { return ['power2.out']; }
function evk_anim_easing_css($e) { return 'cubic-bezier(0.33, 1, 0.68, 1)'; }

require EVK_TEST_ROOT . '/includes/bricks-elements/evoke-offcanvas-menu/element.php';
require EVK_TEST_ROOT . '/includes/bricks-elements/evoke-circular-menu/element.php';

$oc = new \Bricks\Evk_Offcanvas_Menu();
$oc->set_controls();

$cm = new \Bricks\Evk_Circular_Menu();
$cm->set_controls();

echo json_encode([
    'offcanvas' => array_keys($oc->controls),
    'circular'  => array_keys($cm->controls),
], JSON_UNESCAPED_UNICODE), "\n";
