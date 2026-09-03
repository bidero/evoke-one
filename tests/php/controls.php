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

class EVK_Animator {
    private static $i = null;
    public static function get_instance() { return self::$i ?: (self::$i = new self()); }
    public function get_settings() { return ['enabled' => 1]; }
}
function evk_preserve_toggle($input, $key, $field = 'enabled', $default = 0) {
    return isset($input[$field]) ? (int) !empty($input[$field]) : $default;
}
function evk_register_gsap_libs() {}
function bricks_is_builder_main() { return false; }

$GLOBALS['options']['evk_bgshift'] = ['enabled' => 1];

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

require EVK_TEST_ROOT . '/includes/anim/bricks-controls.php';

$settings = json_decode($argv[1] ?? '{}', true) ?: [];

$el = new stdClass();
$el->settings = $settings;

$cb  = evk_test_filter('bricks/element/render_attributes');
$out = $cb(['_root' => ['class' => ['brxe-section']]], '_root', $el);

echo json_encode([
    'anim' => $out['_root']['data-evk-anim'][0] ?? null,
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
