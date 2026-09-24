<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Publiczny zapis do newslettera — kto decyduje o double opt-in.
 *
 * Do 1.129.0 handler czytał `confirm` i `consent` wprost z żądania, a to są
 * atrybuty shortcode'u, czyli decyzja autora strony. `confirm=0` w POST-cie
 * wpisywało adres na listę OD RAZU JAKO POTWIERDZONY, razem z wpisem zgody
 * (`_consent_at`, `_consent_ip`, `_consent_text`) zbudowanym z tego, co
 * przysłał wysyłający. Dowód zgody, którego treścią sterował składający
 * żądanie, nie jest dowodem.
 *
 * Test bierze podpis Z PRAWDZIWEGO FORMULARZA — renderuje shortcode i wyłuskuje
 * `sig` z jego wyjścia. Gdyby wystarczyło policzyć podpis tą samą funkcją, co
 * kod produkcyjny, sprawdzalibyśmy wyłącznie, że `hash_hmac` jest deterministyczne;
 * tak sprawdzamy, że formularz i handler mówią o tym samym.
 *
 * Mail z potwierdzeniem przechodzi przez atrapę `evk_nl_wyslij()`, która go
 * zapisuje (albo na życzenie odrzuca) — samą wysyłkę przez wp_mail() i SMTP
 * sprawdza newsletter-wysylka na prawdziwym WordPressie.
 *
 * Od 1.233.0 formularz nie ma nonce (zbuforowane strony: nonce wygasał po
 * dobie i każdy zapis kończył się „Nieprawidłowy token"). Atrapa
 * `check_ajax_referer()` jest tu PRAWDZIWA — przepuszcza wyłącznie świeży
 * nonce — żeby powrót tego sprawdzenia zapalił test, a nie przeszedł obok.
 */
function check_ajax_referer($action, $field = false, $die = true) {
    $GLOBALS['nonce_asked'] = $action;
    return (($_REQUEST[$field] ?? '') === 'swiezy-nonce') ? 1 : false;
}
require __DIR__ . '/_wp-stubs.php';

if (!defined('DAY_IN_SECONDS')) define('DAY_IN_SECONDS', 86400);
if (!class_exists('WP_Error')) {
    class WP_Error {
        private $m;
        public function __construct($c = '', $m = '') { $this->m = $m; }
        public function get_error_message() { return $this->m; }
    }
}
if (!function_exists('is_wp_error')) { function is_wp_error($x) { return $x instanceof WP_Error; } }

/* Wysyłka — atrapa zapisuje mail; `$GLOBALS['wysylka_ok'] = false` udaje
   serwer, który odrzucił wiadomość. */
$GLOBALS['maile'] = [];
$GLOBALS['wysylka_ok'] = true;
function evk_nl_wyslij(array $args) {
    $GLOBALS['maile'][] = $args;
    return $GLOBALS['wysylka_ok'] ? true : new WP_Error('mail_error', 'atrapa: serwer odrzucił');
}
function evk_nl_confirm_url($token) { return 'https://example.test/nl/confirm/' . $token; }
if (!function_exists('get_bloginfo')) { function get_bloginfo($co = '') { return 'Strona testowa'; } }

if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

function wp_salt($scheme = 'auth') { return 'sol-testowa-' . $scheme; }
function home_url($path = '') { return 'https://example.test' . $path; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function wp_create_nonce($action = -1) { return 'testnonce'; }
function wp_rand($min = 0, $max = 0) { return $min; }
function current_time($type = 'mysql') { return '2026-01-01 00:00:00'; }
function is_email($email) { return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL); }
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function sanitize_html_class($s) { return preg_replace('/[^A-Za-z0-9_-]/', '', (string) $s); }
function shortcode_atts($pairs, $atts, $shortcode = '') {
    $atts = (array) $atts;
    $out  = [];
    foreach ($pairs as $name => $default) {
        $out[$name] = array_key_exists($name, $atts) ? $atts[$name] : $default;
    }
    return $out;
}
function add_shortcode($tag, $cb) { $GLOBALS['shortcodes'][$tag] = $cb; }

/* Transienty — limit 10/godz. stoi na nich i ma dalej działać. */
$GLOBALS['transients'] = [];
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }

/* Lista i zapisy — atrapy notujące, KTÓRĄ drogą poszedł handler. */
$GLOBALS['dodani'] = [];
function evk_nl_get_list($id) { return $id === 4 ? ['id' => 4, 'name' => 'Lista testowa'] : null; }
function evk_nl_add_pending_subscriber($list_id, $email, $consent) {
    $GLOBALS['dodani'][] = ['jak' => 'pending', 'email' => $email, 'zgoda' => $consent];
    return ['ok' => true, 'status' => 2, 'token' => 'tok123'];
}
function evk_nl_add_subscriber($list_id, $email, $consent) {
    $GLOBALS['dodani'][] = ['jak' => 'natychmiast', 'email' => $email, 'zgoda' => $consent];
    return 99;
}

require_once EVK_TEST_ROOT . '/includes/newsletter/settings.php';
require_once EVK_TEST_ROOT . '/includes/security/ip-klienta.php';   // adres w zgodzie i w limicie
require_once EVK_TEST_ROOT . '/includes/newsletter/public.php';

$GLOBALS['options']['evk_newsletter'] = ['enabled' => 1];

$LISTA   = 4;
$ZGODA   = 'Wyrażam zgodę na otrzymywanie newslettera.';

/** Renderuje PRAWDZIWY shortcode i wyłuskuje podpis, który trafia do formularza. */
function podpis_z_formularza(array $atts): string {
    $html = $GLOBALS['shortcodes']['evk_newsletter_form']($atts);
    return preg_match("/fd\.append\('sig',\"([a-f0-9]+)\"\)/", $html, $m) ? $m[1] : '';
}

/** Jedno zgłoszenie. Zwraca odpowiedź i to, jak subskrybent został dodany. */
function zapisz(array $post): array {
    $GLOBALS['dodani'] = [];
    $_POST = $_REQUEST = $post;
    $odp = null;
    try {
        evk_nl_handle_public_subscribe();
    } catch (EVK_Test_Json $e) {
        $odp = $e->payload;
    }
    return ['odpowiedz' => $odp, 'dodani' => $GLOBALS['dodani']];
}

$out = [];

// ── Podpis z formularza z confirm="0" ─────────────────────────────────────
// Autor strony świadomie wyłączył double opt-in — ma to działać.
$sig_bez_potw = podpis_z_formularza(['list' => $LISTA, 'confirm' => '0', 'consent' => $ZGODA]);
$out['podpis_w_formularzu'] = $sig_bez_potw !== '';

$out['z_podpisem_confirm0'] = zapisz([
    'list' => $LISTA, 'email' => 'kto@example.test', 'confirm' => '0',
    'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_bez_potw,
]);

// ── To samo żądanie BEZ podpisu — stara, zbuforowana strona ───────────────
$out['bez_podpisu_confirm0'] = zapisz([
    'list' => $LISTA, 'email' => 'kto@example.test', 'confirm' => '0',
    'consent' => $ZGODA, 'consent_ok' => '1',
]);

// ── Podpis, który nie pasuje ──────────────────────────────────────────────
// Podpis wzięty z formularza z confirm="1", podstawiony pod confirm=0.
$sig_z_potw = podpis_z_formularza(['list' => $LISTA, 'confirm' => '1', 'consent' => $ZGODA]);
$out['cudzy_podpis'] = zapisz([
    'list' => $LISTA, 'email' => 'kto@example.test', 'confirm' => '0',
    'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_z_potw,
]);

// Podmieniona treść zgody przy zachowanym podpisie.
$out['podmieniona_zgoda'] = zapisz([
    'list' => $LISTA, 'email' => 'kto@example.test', 'confirm' => '0',
    'consent' => 'Zgoda na coś zupełnie innego', 'consent_ok' => '1', 'sig' => $sig_bez_potw,
]);

// Podpis z innej listy.
$sig_inna_lista = podpis_z_formularza(['list' => 9, 'confirm' => '0', 'consent' => $ZGODA]);
$out['podpis_innej_listy'] = zapisz([
    'list' => $LISTA, 'email' => 'kto@example.test', 'confirm' => '0',
    'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_inna_lista,
]);

// ── Poprawna droga z potwierdzeniem ───────────────────────────────────────
$out['z_podpisem_confirm1'] = zapisz([
    'list' => $LISTA, 'email' => 'kto@example.test', 'confirm' => '1',
    'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_z_potw,
]);

// ── Zabezpieczenia, które już były i mają nie ucierpieć ───────────────────
$out['honeypot'] = zapisz([
    'list' => $LISTA, 'email' => 'bot@example.test', 'evk_nl_hp' => 'jestem botem',
]);
$out['zly_email'] = zapisz([
    'list' => $LISTA, 'email' => 'to-nie-jest-email', 'confirm' => '1',
    'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_z_potw,
]);
$out['zgoda_niezaznaczona'] = zapisz([
    'list' => $LISTA, 'email' => 'kto@example.test', 'confirm' => '1',
    'consent' => $ZGODA, 'sig' => $sig_z_potw,
]);
/* Podpis MUSI pasować do wysyłanych wartości, inaczej sprawdzalibyśmy podpis,
   a nie istnienie listy. Pierwsza wersja tego przypadku brała podpis zrobiony
   z `confirm="0"` i wysyłała `confirm=1` — odpowiedź brzmiała „Formularz
   wygasł" i wyglądała na sukces testu, choć gałąź listy w ogóle nie ruszyła. */
$sig_lista9 = podpis_z_formularza(['list' => 9, 'confirm' => '1', 'consent' => $ZGODA]);
$out['nieznana_lista'] = zapisz([
    'list' => 9, 'email' => 'kto@example.test', 'confirm' => '1',
    'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_lista9,
]);

// Limit 10/godz. na adres IP.
$GLOBALS['transients'] = [];
for ($i = 0; $i < 10; $i++) {
    zapisz(['list' => $LISTA, 'email' => 'kto@example.test', 'confirm' => '1',
            'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_z_potw]);
}
$out['po_limicie'] = zapisz([
    'list' => $LISTA, 'email' => 'kto@example.test', 'confirm' => '1',
    'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_z_potw,
]);

// ── Bez nonce (1.233.0) ───────────────────────────────────────────────────
$html = $GLOBALS['shortcodes']['evk_newsletter_form'](['list' => $LISTA, 'confirm' => '1', 'consent' => $ZGODA]);
$out['formularz_bez_nonce'] = strpos($html, "fd.append('nonce'") === false;
$GLOBALS['transients'] = [];
$out['bez_nonce'] = zapisz([
    'list' => $LISTA, 'email' => 'nonce1@example.test', 'confirm' => '1',
    'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_z_potw,
]);
// Strona z cache sprzed doby: stary nonce w żądaniu nie może niczego zepsuć.
$out['stary_nonce'] = zapisz([
    'list' => $LISTA, 'email' => 'nonce2@example.test', 'confirm' => '1',
    'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_z_potw, 'nonce' => 'wygasly-nonce',
]);

// ── Mail z potwierdzeniem ─────────────────────────────────────────────────
$GLOBALS['transients'] = [];
$GLOBALS['maile'] = [];
$out['potwierdzenie'] = zapisz([
    'list' => $LISTA, 'email' => 'potw@example.test', 'confirm' => '1',
    'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_z_potw,
]);
$mail = $GLOBALS['maile'][0] ?? [];
$out['mail_potwierdzenia'] = [
    'ile'       => count($GLOBALS['maile']),
    'do'        => $mail['to'] ?? '',
    'link'      => strpos((string) ($mail['body'] ?? ''), 'https://example.test/nl/confirm/tok123') !== false,
    'kampania'  => !empty($mail['kampania']),
];
// Serwer odrzucił wiadomość: osoba ma to usłyszeć, a nie „sprawdź skrzynkę".
$GLOBALS['transients'] = [];
$GLOBALS['wysylka_ok'] = false;
$out['mail_nie_wyszedl'] = zapisz([
    'list' => $LISTA, 'email' => 'nieposzlo@example.test', 'confirm' => '1',
    'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_z_potw,
]);
$GLOBALS['wysylka_ok'] = true;

/* Limit maili z potwierdzeniem na JEDEN adres: pięć zapisów tego samego
   adresu z pięciu różnych IP (limit na IP nie ma tu nic do rzeczy). */
$GLOBALS['transients'] = [];
$GLOBALS['maile'] = [];
$odpowiedzi = [];
for ($i = 1; $i <= 5; $i++) {
    $_SERVER['REMOTE_ADDR'] = '198.51.100.' . $i;
    $odpowiedzi[] = zapisz([
        'list' => $LISTA, 'email' => 'Ofiara@example.test', 'confirm' => '1',
        'consent' => $ZGODA, 'consent_ok' => '1', 'sig' => $sig_z_potw,
    ])['odpowiedz']['success'] ?? null;
}
$out['limit_potwierdzen'] = ['maile' => count($GLOBALS['maile']), 'odpowiedzi' => $odpowiedzi];
unset($_SERVER['REMOTE_ADDR']);

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
