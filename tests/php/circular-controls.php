<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Kontrolki i atrybuty elementu Circular Menu — z PRAWDZIWEGO element.php.
 *
 * Sedno: lista krzywych ma pochodzić z evk_anim_easings(), czyli z tego samego
 * miejsca co Animator i Offcanvas Menu. Do 1.68.0 stała tu własna kopia
 * z samymi rodzinami GSAP-a („power2" zamiast „power2.out") plus pole na
 * wartość wpisywaną ręcznie — czyli słownik, którego nie było nigdzie indziej
 * we wtyczce. Kopii nie da się złapać pomiarem na stronie: obie listy dają
 * krzywe, które GSAP przyjmie, więc różnicę widać tylko stąd.
 */
require __DIR__ . '/_wp-stubs.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
require __DIR__ . '/_bricks-stubs.php';

define('EVK_BRICKS_CATEGORY', 'evoke-one');

require EVK_TEST_ROOT . '/includes/bricks-elements/evoke-circular-menu/element.php';

$el = new \Bricks\Evk_Circular_Menu();
$el->set_controls();

// Render z ustawieniami domyślnymi (pusta tablica) — tak wygląda element
// świeżo wstawiony w builderze.
ob_start();
$el->render();
$plain = ob_get_clean();

// I drugi raz z wypełnionymi polami, bo puste `0`/`false` przechodzą inną
// ścieżką niż wartości ustawione.
$el2 = new \Bricks\Evk_Circular_Menu();
$el2->settings = [
    'easing'       => 'back.out(1.7)',
    'contentDelay' => 0.25,
    'animateExit'  => true,
    'exitWait'     => 0.15,
];
ob_start();
$el2->render();
$filled = ob_get_clean();

// Trzeci raz z czekaniem ustawionym na ZERO — „oba ruchy naraz". To osobna
// ścieżka, nie wariant poprzedniej: `! empty()` widzi zero jako brak wartości
// i po cichu wróciłby do grania jeden ruch po drugim.
$el3 = new \Bricks\Evk_Circular_Menu();
$el3->settings = [ 'animateExit' => true, 'exitWait' => 0 ];
ob_start();
$el3->render();
$zero = ob_get_clean();

echo json_encode([
    'easingOptions'   => array_keys($el->controls['easing']['options']),
    'sharedEasings'   => evk_anim_easings(),
    // Pole na ręcznie wpisaną krzywą zniknęło razem z własną listą.
    'hasCustomEasing' => isset($el->controls['customEasing']),
    'hasContentDelay' => isset($el->controls['contentDelay']),
    'hasAnimateExit'  => isset($el->controls['animateExit']),
    'hasExitWait'     => isset($el->controls['exitWait']),
    // Czekanie ma sens tylko przy włączonym wyjściu — kontrolka bez tej
    // bramki wisiałaby w panelu jako pole, które nic nie robi.
    'exitWaitGate'    => $el->controls['exitWait']['required'] ?? null,
    'renderPlain'     => $plain,
    'renderFilled'    => $filled,
    'renderZero'      => $zero,
    'triggers'        => array_keys(evk_anim_triggers()),
], JSON_UNESCAPED_UNICODE), "\n";
