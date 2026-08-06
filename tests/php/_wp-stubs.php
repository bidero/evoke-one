<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Minimalne atrapy WordPressa — tyle, ile potrzebują testowane pliki.
 *
 * Sens: sprawdzamy PRAWDZIWE filtry i sanityzację wtyczki, a nie ich opis.
 * Zarejestrowane callbacki lądują w $GLOBALS['hooks'], żeby test mógł je wywołać.
 */
define('ABSPATH', 1);

$GLOBALS['hooks']    = [];
$GLOBALS['options']  = [];
$GLOBALS['enqueued']  = [];
$GLOBALS['inline']    = [];
$GLOBALS['localized'] = [];

function add_filter($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $cb; }
function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $cb; }
function apply_filters($hook, $value) { return $value; }

function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default;
}
function update_option($key, $value) { $GLOBALS['options'][$key] = $value; return true; }
function register_setting(...$a) {}

function esc_html__($s, $d = '') { return $s; }
function esc_html_e($s, $d = '') { echo $s; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function esc_js($s) { return $s; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function sanitize_title($s) {
    $s = strtolower(trim((string) $s));
    return trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', $s)), '-');
}
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function wp_parse_args($a, $d) { return array_merge($d, is_array($a) ? $a : []); }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); }
// Enqueue'y zapisujemy zamiast połykać: literówka w nazwie handle'a niczego
// nie wywala — skrypt po prostu nie trafia na stronę i efekt cicho nie działa.
function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $args = false) {
    $GLOBALS['enqueued'][$handle] = ['src' => $src, 'deps' => (array) $deps];
}
function wp_add_inline_script($handle, $data, $position = 'after') {
    $GLOBALS['inline'][] = ['handle' => $handle, 'data' => $data, 'position' => $position];
}
function wp_localize_script($handle, $name, $data) {
    $GLOBALS['localized'][$name] = $data;
}
function is_admin() { return false; }
function checked($a, $b = true, $echo = true) { if ($a == $b) echo ' checked'; }

// ── AJAX ────────────────────────────────────────────────────────────────
// wp_send_json_* normalnie kończą żądanie — tu rzucamy wyjątkiem, żeby test
// mógł zobaczyć odpowiedź zamiast tracić cały proces.
class EVK_Test_Json extends Exception {
    public $payload;
    public function __construct(array $payload) { $this->payload = $payload; parent::__construct('json'); }
}
function wp_send_json_success($data = null) { throw new EVK_Test_Json(['success' => true,  'data' => $data]); }
function wp_send_json_error($data = null, $code = 0) { throw new EVK_Test_Json(['success' => false, 'data' => $data]); }
function check_ajax_referer($action, $field = false, $die = true) { return 1; }
function current_user_can($cap) { return true; }
function wp_unslash($v) { return $v; }
function absint($v) { return abs((int) $v); }
function wp_list_pluck($list, $field) {
    return array_map(function ($row) use ($field) { return is_array($row) ? ($row[$field] ?? null) : null; }, $list);
}

/** Odpala wszystkie callbacki podpięte pod hook i zwraca wypisaną treść. */
function evk_test_fire($hook) {
    ob_start();
    foreach ($GLOBALS['hooks'][$hook] ?? [] as $cb) { $cb(); }
    return ob_get_clean();
}

/** Pierwszy callback podpięty pod hook — do filtrów, które coś zwracają. */
function evk_test_filter($hook) {
    return $GLOBALS['hooks'][$hook][0] ?? null;
}

const EVK_TEST_ROOT = __DIR__ . '/../..';
