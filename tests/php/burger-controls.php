<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Kontrolki i znacznik elementu Burger — z PRAWDZIWEGO element.php.
 *
 * Sedno: znacznik ma być WYLICZANY z liczby kresek zapisanej w tablicy stylów,
 * a nie wklejany osobno dla każdego stylu. Wzór, od którego uciekamy, miał
 * `render()` na 750 linii `if/else` — przy takim kształcie dołożenie stylu
 * znaczy dopisanie gałęzi kodu, a nie wiersza danych, i nic tego nie pilnuje.
 *
 * Tego NIE DA SIĘ zmierzyć w przeglądarce: strona z jednym stylem wygląda
 * identycznie niezależnie od tego, czy znacznik powstał z pętli, czy z wklejki.
 * Widać to dopiero stąd — po liczbie kresek zgodnej z tablicą.
 */
require __DIR__ . '/_wp-stubs.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
require __DIR__ . '/_bricks-stubs.php';

define('EVK_BRICKS_CATEGORY', 'evoke-one');

require EVK_TEST_ROOT . '/includes/bricks-elements/evoke-burger/element.php';

$el = new \Bricks\Evk_Burger();
$el->set_controls();

// Render domyślny — tak wygląda element świeżo wstawiony w builderze.
ob_start();
$el->render();
$plain = ob_get_clean();

// I z wypełnionymi polami. Krzywa idzie osobną drogą niż reszta: kontrolki
// `css` wpisałyby surową nazwę GSAP-a, której CSS nie zna — a nieznana funkcja
// czasu unieważnia CAŁĄ deklarację `transition` razem z czasem trwania.
$el2 = new \Bricks\Evk_Burger();
$el2->settings = [
    'style'     => 'cross',
    'easing'    => 'back.out(1.7)',
    'ariaLabel' => 'Otwórz menu',
    'mode'      => 'self',
];
ob_start();
$el2->render();
$filled = ob_get_clean();

// Nieznany styl nie może wywalić rendera — zapisane strony przeżywają
// przemianowanie stylu, a element wraca do domyślnego.
$el3 = new \Bricks\Evk_Burger();
$el3->settings = [ 'style' => 'nie-ma-takiego' ];
ob_start();
$el3->render();
$bogus = ob_get_clean();

// Tryb „wskazany element" — kliknięcie przestawia CUDZY element.
$el4 = new \Bricks\Evk_Burger();
$el4->settings = [ 'mode' => 'target', 'target' => '#moj-panel', 'targetClass' => 'is-otwarte' ];
ob_start();
$el4->render();
$target = ob_get_clean();

/* Strona zapisana PRZED zamianą checkboxa na listę trybów. Bez tej ścieżki
   burger z włączonym starym „Sam się przełącza" po cichu przestałby cokolwiek
   robić — a nikt by tego nie zauważył, dopóki nie otworzyłby elementu
   w builderze. */
$el5 = new \Bricks\Evk_Burger();
$el5->settings = [ 'selfToggle' => true ];
ob_start();
$el5->render();
$legacy = ob_get_clean();

$styles = \Bricks\Evk_Burger::styles();
$lines  = [];
foreach ($styles as $key => $def) $lines[$key] = $def['lines'];

echo json_encode([
    'styles'        => array_keys($styles),
    'lines'         => $lines,
    'controls'      => array_keys($el->controls),
    'easingOptions' => array_keys($el->controls['easing']['options']),
    'sharedEasings' => evk_anim_easings(),
    'renderPlain'   => $plain,
    'renderFilled'  => $filled,
    'renderBogus'   => $bogus,
    'renderTarget'  => $target,
    'renderLegacy'  => $legacy,
    // Ile <span class="evk-burger__line"> naprawdę wyszło z rendera.
    'plainLines'    => substr_count($plain, 'evk-burger__line'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
