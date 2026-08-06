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

require EVK_TEST_ROOT . '/includes/anim/motion.php';
require EVK_TEST_ROOT . '/includes/anim/bgshift.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
require EVK_TEST_ROOT . '/includes/anim/bricks-controls.php';

$settings = json_decode($argv[1] ?? '{}', true) ?: [];

$el = new stdClass();
$el->settings = $settings;

$cb  = evk_test_filter('bricks/element/render_attributes');
$out = $cb(['_root' => ['class' => ['brxe-section']]], '_root', $el);

echo json_encode([
    'anim' => $out['_root']['data-evk-anim'][0] ?? null,
    'bg'   => array_key_exists('data-evk-bg', $out['_root']) ? '' : null,
], JSON_UNESCAPED_UNICODE), "\n";
