<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Atrybuty generowane przez kontrolki Bricks — przez PRAWDZIWY filtr
 * bricks/element/render_attributes, nie przez jego opis.
 *
 * Argument: JSON z ustawieniami elementu. Wypisuje wartość data-evk-anim
 * i data-evk-bg (albo „—”, gdy atrybut nie powstał).
 */
require __DIR__ . '/_wp-stubs.php';

function evk_preserve_toggle($input, $key, $field = 'enabled', $default = 0) {
    return isset($input[$field]) ? (int) !empty($input[$field]) : $default;
}
function evk_register_gsap_libs() {}
function esc_textarea($s) { return $s; }
function bricks_is_builder_main() { return false; }

define('EVOKE_ONE_URL',     'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', 'test');

$GLOBALS['options']['evk_bgshift'] = ['enabled' => 1];

/* PRAWDZIWY EVK_Animator, nie atrapa z jedną metodą.
 *
 * Filtr atrybutów pyta go od 1.183.0, czy element ma czekać pod zasłoną
 * (`element_zaslania()`) — a odpowiedź zależy od wiersza biblioteki i presetu.
 * Atrapa musiałaby przepisać u siebie regułę `wiersz_zaslania()` i od tej
 * chwili żyć własnym życiem: sprawdzenie przechodziłoby także wtedy, gdyby
 * wtyczka zaczęła odpowiadać inaczej.
 *
 * Trzy wiersze na trzy brzegi: wejście w kadrze nakłada stan początkowy i musi
 * czekać, hover nie nakłada niczego i czekać nie ma po co, a cel zewnętrzny
 * nakłada stan GDZIE INDZIEJ — element z atrybutem jest tam samym wyzwalaczem.
 *
 * Trzeci wiersz ma DOKŁADNIE ten kształt, który przyszedł ze zgłoszenia: ten sam
 * preset z `from` co wiersz czekający, ten sam wyzwalacz z listy chowających —
 * różni się wyłącznie celem. Gdyby różnił się czymkolwiek jeszcze, sprawdzenie
 * nie dowodziłoby, że rozstrzyga właśnie cel.
 */
$GLOBALS['options']['evk_animator'] = [
    'enabled'    => 1,
    'animations' => [
        ['slug' => 'wejscie',    'preset' => 'fade-up', 'trigger' => 'viewport'],
        ['slug' => 'najazd',     'preset' => 'lift',    'trigger' => 'hover'],
        ['slug' => 'zewnetrzny', 'preset' => 'fade-up', 'trigger' => 'viewport',
         'targets' => 'external', 'selector' => '.cel'],
    ],
];

/* Wspólne wykrywanie buildera — moduły frontowe pytają o nie przez
   `evk_w_builderze()`. Plik jest liściem: potrzebuje tylko `is_admin()`
   z atrap i niczego więcej. */
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';

require EVK_TEST_ROOT . '/includes/anim/motion.php';
require EVK_TEST_ROOT . '/includes/anim/bgshift.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
/* Kontrolki parallaksu wchodzą tylko przy WŁĄCZONYM module
   (`evk_parallax_controls_active()` pyta `EVK_Parallax`). Bez tego pliku
   gałąź parallaksu w filtrze nigdy się nie wykonuje, a sprawdzenia jej
   dotyczące przechodziłyby na „null" — czyli na nieobecności, nie na braku
   usterki. */
$GLOBALS['options']['evk_parallax'] = ['enabled' => 1];
require EVK_TEST_ROOT . '/includes/92-parallax.php';

require EVK_TEST_ROOT . '/includes/anim/animator.php';
require EVK_TEST_ROOT . '/includes/anim/bricks-controls.php';

$settings = json_decode($argv[1] ?? '{}', true) ?: [];

$el = new stdClass();
$el->settings = $settings;

$cb  = evk_test_filter('bricks/element/render_attributes');
$out = $cb(['_root' => ['class' => ['brxe-section']]], '_root', $el);

echo json_encode([
    'anim' => $out['_root']['data-evk-anim'][0] ?? null,
    /* Znacznik dla zasłony. Rozróżnienie brak/„0" jest tu całą treścią:
       „0" WYPISUJE element z bezpiecznika w <head>, brak znacznika zostawia go
       pod nim. Dlatego wartość, nie sama obecność. */
    'zaslona' => array_key_exists('data-evk-anim-zaslona', $out['_root'])
        ? (string) ($out['_root']['data-evk-anim-zaslona'][0] ?? '') : null,
    // WARTOŚĆ atrybutu, nie sama jego obecność: od 1.53.0 `data-evk-bg` może
    // nieść procent, na którym sekcja przejmuje tło. Pusty ciąg nadal znaczy
    // „wartość globalna", więc rozróżnienie pusty/brak musi zostać.
    'bg'   => array_key_exists('data-evk-bg', $out['_root'])
        ? (string) ($out['_root']['data-evk-bg'][0] ?? '') : null,
    // Brak atrybutu to sygnał „dobierz kolor liter z jasności tła", więc
    // rozróżnienie brak/pusty musi tu zostać tak samo jak wyżej.
    'bgText' => array_key_exists('data-evk-bg-text', $out['_root'])
        ? (string) ($out['_root']['data-evk-bg-text'][0] ?? '') : null,
    // Parallax: wartość, skala i ZNACZNIK warstwy z serwera. Bez znacznika
    // reguła `[data-parallax-css]::before` nie trafia w żaden element, więc
    // warstwa nie powstaje i wraca migotanie — po cichu, bo atrybuty
    // `data-parallax`/`data-skala` nadal wyglądają poprawnie.
    'par'    => array_key_exists('data-parallax', $out['_root'])
        ? (string) ($out['_root']['data-parallax'][0] ?? '') : null,
    'parCss' => array_key_exists('data-parallax-css', $out['_root'])
        ? (string) ($out['_root']['data-parallax-css'][0] ?? '') : null,
], JSON_UNESCAPED_UNICODE), "\n";
