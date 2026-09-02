<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Dwa drobiazgi domykające audyt — obydwa o wychodzeniu poza swoje miejsce.
 *
 * 1. WŁASNY CSS PANELU szedł do strony taki, jaki przyszedł z formularza:
 *    `trim()` przy zapisie i `echo '<style …>'.$css.'</style>'`. Ciąg
 *    `</style><script>…` wychodził z bloku i wykonywał się w panelu każdego
 *    administratora. Pole wymaga `manage_options`, więc to nie było podniesienie
 *    uprawnień — ale ta sama opcja jedzie przez import ustawień.
 *
 * 2. KOMUNIKAT BLOKADY LOGOWANIA dopuszczał `span` z atrybutem `style`, a
 *    wyświetla się na PUBLICZNEJ stronie logowania. Do tego dwie drogi zapisu
 *    miały dwie różne listy dozwolonych znaczników: `register_setting()`
 *    przepuszczał tekst przez `wp_kses_post()` (lista dla treści wpisów),
 *    a zapis przez AJAX przez własną, wąską.
 *
 * Sanityzacja komunikatu chodzi na PRAWDZIWYM `wp_kses` (kopia WordPressa
 * w `tests/php/wp/`) — atrapa sita sprawdzałaby wyłącznie samą siebie.
 */
require __DIR__ . '/_wp-stubs.php';

function apply_filters($hook, $value) {
    foreach ($GLOBALS['hooks'][$hook] ?? [] as $cb) { $value = $cb($value); }
    return $value;
}
function _deep_replace($search, $subject) {
    $subject = (string) $subject;
    $count = 1;
    while ($count) {
        foreach ((array) $search as $val) { $subject = str_replace($val, '', $subject, $count); }
    }
    return $subject;
}
function wp_allowed_protocols() { return ['http', 'https', 'mailto', 'tel']; }
function _x($s, $c, $d = '') { return $s; }
function wp_parse_str($s, &$a) { parse_str($s, $a); }
function did_action($h) { return 1; }
function current_time($type = 'mysql') { return $type === 'timestamp' ? 1800000000 : '2027-01-15 12:00:00'; }
function home_url($path = '') { return 'https://example.test' . $path; }
function site_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function get_bloginfo($co = 'name') { return 'Serwis'; }
function wp_get_current_user() { return (object) ['ID' => 1, 'roles' => ['administrator']]; }
function is_admin_bar_showing() { return false; }
function wp_create_nonce($a = -1) { return 'testnonce'; }
function do_action($h, ...$a) {}
function wp_login_url() { return 'https://example.test/wp-login.php'; }
function wp_safe_redirect($u, $s = 302) { return true; }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function is_user_logged_in() { return false; }
function get_current_screen() { return null; }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function sanitize_hex_color_no_hash($s) { return ltrim((string) $s, '#'); }

function tl_get_active_lang_codes() { return ['pl']; }
function bricks_is_builder_main() { return false; }

require_once EVK_TEST_ROOT . '/tests/php/wp/kses.php';
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';
// `evk_preserve_toggle()` — sanityzator White Label pilnuje nim przełącznika
// modułu, żeby zapis formularza nie gasił czegoś, czym steruje osobny AJAX.
require_once EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';
require_once EVK_TEST_ROOT . '/includes/interface/white-label.php';
require_once EVK_TEST_ROOT . '/includes/security/settings.php';

$out = [];

// ── 1. Własny CSS panelu ──────────────────────────────────────────────────
$proby = [
    'wyjscie_ze_stylu'  => '.evk{color:red}</style><script>alert(1)</script>',
    'wielkie_litery'    => '.evk{color:red}</STYLE><script>alert(1)</script>',
    'ze_spacja'         => '.evk{color:red}</ style><script>alert(1)</script>',
    'zwykly_css'        => '#adminmenu{background:#111}',
    // Zapytania zakresowe piszą się ze znakiem „<" — usuwanie wszystkich
    // ostrych nawiasów popsułoby poprawny, współczesny CSS.
    'zapytanie_zakresu' => '@media (400px <= width <= 700px){.evk{display:none}}',
];
foreach ($proby as $nazwa => $css) {
    $wynik = evk_wl_bezpieczny_css($css);
    /* Wyrażeniem, nie `stripos('</style')`. Pierwsza wersja szukała dosłownego
       ciągu i przez to przypadek `</ style` wychodził czysty także BEZ sita —
       sprawdzenie było prawdziwe w obie strony i przy mutacji nie drgnęło. */
    $out['css'][$nazwa] = [
        'wynik'         => $wynik,
        'ma_zamkniecie' => (bool) preg_match('#</\s*style#i', $wynik),
    ];
}

/* Zapis prawdziwą drogą: sanityzator jest domknięciem podpiętym przez
   `register_setting()`, więc bierzemy go z rejestru, a nie wołamy z nazwy. */
foreach ($GLOBALS['hooks']['admin_init'] ?? [] as $cb) { $cb(); }
$sanit = $GLOBALS['sanitizers']['evk_white_label'] ?? null;
$out['css']['sanityzator_zarejestrowany'] = is_callable($sanit);
if (is_callable($sanit)) {
    $GLOBALS['options']['evk_white_label'] = [];
    $zapisane = $sanit(['custom_css_admin' => '.a{}</style><script>alert(1)</script>']);
    $out['css']['przez_zapis'] = $zapisane['custom_css_admin'] ?? null;
}

// ── 2. Komunikat blokady logowania ────────────────────────────────────────
$KOMUNIKAT = '<strong>Blokada.</strong> <em>Wróć</em> za <span style="position:fixed;inset:0;'
           . 'background:#fff;z-index:9999">godzinę</span> <a href="/pomoc" title="pomoc">pomoc</a>'
           . '<script>alert(1)</script><img src=x onerror=alert(2)>';

$przez_grupe  = evk_security_sanitize(['limit_login_message' => $KOMUNIKAT])['limit_login_message'];
$przez_sekcje = evk_security_sanitize_section('login', ['limit_login_message' => $KOMUNIKAT])['limit_login_message'];

$out['komunikat'] = [
    'przez_grupe'   => $przez_grupe,
    'przez_sekcje'  => $przez_sekcje,
    'obie_drogi_te_same' => $przez_grupe === $przez_sekcje,
    'ma_style'      => stripos($przez_grupe, 'style=') !== false,
    'ma_script'     => stripos($przez_grupe, '<script') !== false,
    'ma_onerror'    => stripos($przez_grupe, 'onerror') !== false,
    'ma_strong'     => stripos($przez_grupe, '<strong>') !== false,
    'ma_link'       => stripos($przez_grupe, '<a href="/pomoc"') !== false,
    'ma_span'       => stripos($przez_grupe, '<span>') !== false,
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
