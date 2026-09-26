<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * White Label: zapis formularza tak, jak robi to WordPress (1.238.0).
 *
 *   php tests/php/admin-whitelabel.php '<ciało żądania jako JSON-owy łańcuch>'
 *
 * Ciało przychodzi z PRAWDZIWEGO formularza zakładki, zserializowanego
 * w przeglądarce (FormData). parse_str() rozbiera je z tą samą semantyką co
 * $_POST: z dwóch pól o tej samej nazwie wygrywa OSTATNIE. Tak przepadała
 * edycja etykiety własnej pozycji paska — za polem tekstowym stało ukryte pole
 * o tej samej nazwie ze starą etykietą. Dalej prawdziwy sanityzator z rejestru
 * register_setting(), tą samą drogą co w drobiazgi.php.
 */
require __DIR__ . '/_wp-stubs.php';

function apply_filters($hook, $value) { return $value; }
function wp_allowed_protocols() { return ['http', 'https', 'mailto', 'tel']; }
function _x($s, $c, $d = '') { return $s; }
function did_action($h) { return 1; }
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function do_action($h, ...$a) {}
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function sanitize_hex_color_no_hash($s) { return ltrim((string) $s, '#'); }
function get_current_screen() { return null; }
function is_admin_bar_showing() { return false; }

require_once EVK_TEST_ROOT . '/tests/php/wp/kses.php';
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';
require_once EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';
require_once EVK_TEST_ROOT . '/includes/interface/white-label.php';

$cialo = (string) json_decode($argv[1] ?? '""');
parse_str($cialo, $post);

foreach ($GLOBALS['hooks']['admin_init'] ?? [] as $cb) { $cb(); }
$sanit = $GLOBALS['sanitizers']['evk_white_label'] ?? null;
if (!is_callable($sanit)) { echo json_encode(['brak' => 'sanityzator White Label niezarejestrowany']); exit; }

// Zapisany stan przed wysłaniem — ten sam, z którego narysowano formularz.
$GLOBALS['options']['evk_white_label'] = json_decode($argv[2] ?? '{}', true) ?: [];
$wl = is_array($post['evk_white_label'] ?? null) ? $post['evk_white_label'] : [];

echo json_encode([
    'z_formularza' => $wl['bar_nodes_extra'] ?? null,
    'zapisane'     => $sanit($wl)['bar_nodes_extra'] ?? null,
], JSON_UNESCAPED_UNICODE);
