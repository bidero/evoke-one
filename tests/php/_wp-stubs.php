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
// Stałe formatu wyniku $wpdb — moduły przekazują je do get_results()/get_row().
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('ARRAY_N')) define('ARRAY_N', 'ARRAY_N');
if (!defined('OBJECT'))  define('OBJECT',  'OBJECT');

$GLOBALS['hooks']    = [];
$GLOBALS['options']  = [];
$GLOBALS['enqueued']   = [];
$GLOBALS['registered'] = [];
$GLOBALS['inline']    = [];
$GLOBALS['localized'] = [];
$GLOBALS['sanitizers'] = [];
$GLOBALS['new_allowed_options'] = [];

function add_filter($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $cb; }
function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $cb; }
function apply_filters($hook, $value) { return $value; }

function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default;
}
// Sanityzacja odpala się PRZY ZAPISIE, nie przed nim — tak jak w WordPressie,
// gdzie update_option() woła sanitize_option(). Endpointy, które sanityzują
// z ręki i dopiero potem zapisują, przepuszczają dane przez sanityzator DWA
// RAZY; atrapa musi to pokazywać, a nie ukrywać.
function update_option($key, $value) {
    if (isset($GLOBALS['sanitizers'][$key])) {
        $value = call_user_func($GLOBALS['sanitizers'][$key], $value);
    }
    $GLOBALS['options'][$key] = $value;
    return true;
}
/**
 * Rejestr ustawień — na tyle wierny, na ile potrzebuje tego generyczny zapis.
 *
 * WordPress trzyma listę opcji należących do grupy w $new_allowed_options
 * (przed 5.5: $new_whitelist_options) i podpina `sanitize_callback` pod filtr
 * `sanitize_option_{$opcja}`, który odpala `update_option()`. Endpoint zapisu
 * stoi na obu tych rzeczach, więc atrapa musi je odwzorować — inaczej test
 * sprawdzałby zapis do tablicy, a nie zapis ustawień.
 */
function register_setting($group, $option, $args = []) {
    $GLOBALS['new_allowed_options'][$group][] = $option;
    if (!empty($args['sanitize_callback'])) {
        $GLOBALS['sanitizers'][$option] = $args['sanitize_callback'];
    }
}

function __($s, $d = '') { return $s; }
function _e($s, $d = '') { echo $s; }
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
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
/**
 * Zwraca `''` dla pustej wartości, a `null` dla niepustej, ale nieprawidłowej
 * — dokładnie jak oryginał. Ta atrapa oddawała wcześniej `''` w obu
 * przypadkach i była przez to ŁAGODNIEJSZA od funkcji, którą udaje: kod
 * porównujący wynik z `''` przechodził w teście, a na żywej stronie dostawał
 * `null` i wpuszczał go dalej. Atrapa łagodniejsza od oryginału nie jest
 * uproszczeniem, tylko ukrytą różnicą.
 */
function sanitize_hex_color($s) {
    if ($s === '' || $s === null) return '';
    return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $s) ? $s : null;
}
function sanitize_email($s) { return filter_var((string) $s, FILTER_VALIDATE_EMAIL) ?: ''; }
function esc_url_raw($s) { return (string) $s; }
function wp_parse_args($a, $d) { return array_merge($d, is_array($a) ? $a : []); }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); }
// Enqueue'y zapisujemy zamiast połykać: literówka w nazwie handle'a niczego
// nie wywala — skrypt po prostu nie trafia na stronę i efekt cicho nie działa.
function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $args = false) {
    $GLOBALS['enqueued'][$handle] = ['src' => $src, 'deps' => (array) $deps];
}
// Rejestracja to nie to samo co enqueue: skrypt zarejestrowany jest znany
// WordPressowi, ale trafia na stronę dopiero przez wp_enqueue_script($handle).
// Panel korzysta z tej różnicy — GSAP rejestruje moduł, a panel tylko dociąga.
function wp_register_script($handle, $src = '', $deps = [], $ver = false, $args = false) {
    $GLOBALS['registered'][$handle] = ['src' => $src, 'deps' => (array) $deps];
}
function wp_script_is($handle, $list = 'enqueued') {
    if ($list === 'registered') return isset($GLOBALS['registered'][$handle]);
    return isset($GLOBALS['enqueued'][$handle]);
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
// Test może chcieć wiedzieć, O JAKI nonce endpoint prosi — nazwa musi zgadzać
// się z tym, co drukuje settings_fields(). Własna nazwa oznaczałaby, że każdy
// zapis pada w produkcji, a testy dalej świecą na zielono.
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $field = false, $die = true) {
        $GLOBALS['nonce_asked'] = $action;
        return 1;
    }
}
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
