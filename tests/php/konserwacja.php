<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Kto i czym wchodzi za zasłonę trybu konserwacji.
 *
 * Do 1.127.0 ciasteczko wpuszczające za zasłonę MIAŁO WARTOŚĆ RÓWNĄ HASŁU,
 * leciało bez `secure` i bez `SameSite`, a porównania szły przez `===`.
 * Do tego termin ważności był wyłącznie datą wygaśnięcia ciasteczka, czyli
 * ustawieniem po stronie przeglądarki — kto ją zignorował, wchodził
 * bezterminowo.
 *
 * Test wywołuje PRAWDZIWY hook `parse_request` i patrzy na trzy rzeczy, których
 * nie da się sprawdzić czytaniem kodu:
 *
 * * **co naprawdę ląduje w ciasteczku** (atrapa `setcookie` zapisuje argumenty);
 * * **którą funkcją leci przekierowanie** — `wp_redirect` i `wp_safe_redirect`
 *   są osobnymi atrapami, więc podmiana jednej na drugą jest widoczna;
 * * **co przechodzi, a co nie** przy ciasteczku starym, przeterminowanym
 *   i podpisanym innym kluczem.
 *
 * CZEGO TEN TEST NIE SPRAWDZA: stałego czasu porównania. `hash_equals` kontra
 * `===` jest z zewnątrz nie do odróżnienia — to pozycja do przeczytania
 * w kodzie, nie asercja, i udawanie inaczej byłoby tu jedynym kłamstwem.
 */
require __DIR__ . '/_wp-stubs.php';

if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

function is_user_logged_in() { return !empty($GLOBALS['zalogowany']); }
function is_ssl() { return !empty($GLOBALS['ssl']); }
function wp_salt($scheme = 'auth') { return 'sol-testowa-' . $scheme; }
function home_url($path = '') { return 'https://example.test' . $path; }
function status_header($code) {}
function nocache_headers() {}
function locate_template($t) { return ''; }
function get_post_status($id) { return 'publish'; }

/* Przekierowania zapisujemy zamiast wykonywać. `wp_redirect` i `wp_safe_redirect`
   MUSZĄ być osobnymi atrapami: gdyby była jedna, podmiana bezpiecznego wariantu
   na zwykły przeszłaby przez test niezauważona. */
class EVK_Test_Redirect extends Exception {
    public $cel; public $bezpieczne;
    public function __construct($cel, $bezpieczne) {
        parent::__construct('redirect');
        $this->cel = $cel; $this->bezpieczne = $bezpieczne;
    }
}
function wp_redirect($cel, $status = 302) { throw new EVK_Test_Redirect($cel, false); }
function wp_safe_redirect($cel, $status = 302) { throw new EVK_Test_Redirect($cel, true); }
/** Odwzorowuje rdzeń: adres spoza serwisu (w tym `//obcy`) leci na zapasowy. */
function wp_validate_redirect($cel, $zapasowy = '') {
    $cel = (string) $cel;
    if (strpos($cel, '//') === 0) return $zapasowy;          // protokołowo-względny
    if (preg_match('#^https?://#i', $cel)) {
        return strpos($cel, 'https://example.test') === 0 ? $cel : $zapasowy;
    }
    return $cel !== '' && $cel[0] === '/' ? $cel : $zapasowy;
}

require_once EVK_TEST_ROOT . '/includes/95-maintenance.php';

/* CIASTECZKA TEN TEST NIE WIDZI I NIE UDAJE, ŻE WIDZI. `setcookie()` jest
   funkcją wbudowaną — atrapa jej nie przykryje, a w CLI nie zostawia śladu
   w `headers_list()`. Sprawdzamy więc WARTOŚCI, które moduł jej podaje:
   podpis przez `evoke_one_wpm_sign()` i atrybuty przez
   `evoke_one_wpm_cookie_args()` — te same funkcje, z których korzysta kod
   produkcyjny. Samo wywołanie zostaje poza zasięgiem testu. */

$handler = null;
foreach ($GLOBALS['hooks']['parse_request'] ?? [] as $cb) { $handler = $cb; }

/**
 * Jedno żądanie. Zwraca, co się stało: przekierowanie, przepuszczenie
 * albo pokazanie zasłony.
 */
function zadanie(array $get = [], array $cookie = [], string $uri = '/'): array {
    global $handler, $wpm_show_maintenance;
    $_GET    = $get;
    $_COOKIE = $cookie;
    $_SERVER['REQUEST_URI'] = $uri;
    $wpm_show_maintenance = null;
    try {
        $handler();
    } catch (EVK_Test_Redirect $e) {
        return ['co' => 'przekierowanie', 'cel' => $e->cel, 'bezpieczne' => $e->bezpieczne];
    }
    return ['co' => empty($GLOBALS['wpm_show_maintenance']) ? 'wpuszczony' : 'zaslona'];
}

$KLUCZ = 'klucz-podgladu-2026';
$GLOBALS['options'] = [
    'maintenance_mode'             => 1,
    'maintenance_bypass_password'  => $KLUCZ,
    'maintenance_bypass_hours'     => 2,
    'maintenance_excluded_paths'   => "/podglad\n/logmein",
];
$GLOBALS['zalogowany'] = false;
$GLOBALS['ssl']        = true;

$out = [];

// ── 1. Klucz z adresu ─────────────────────────────────────────────────────
$out['dobry_klucz'] = zadanie(['haslo' => $KLUCZ], [], '/home-alt/?haslo=' . $KLUCZ);
$out['zly_klucz']   = zadanie(['haslo' => 'nie-ten'], [], '/home-alt/');

// Adres protokołowo-względny nie ma wyprowadzić poza serwis.
$out['obcy_adres'] = zadanie(['haslo' => $KLUCZ], [], '//obcy-adres.test/x?haslo=' . $KLUCZ);

// ── 2. Ciasteczko ─────────────────────────────────────────────────────────
$termin_ok    = time() + 3600;
$termin_minal = time() - 60;

$out['ciastko_dobre']   = zadanie([], ['maintenance_bypass' => evoke_one_wpm_sign($termin_ok, $KLUCZ)], '/home-alt/');
$out['ciastko_stare']   = zadanie([], ['maintenance_bypass' => $KLUCZ], '/home-alt/');
$out['ciastko_po_czasie'] = zadanie([], ['maintenance_bypass' => evoke_one_wpm_sign($termin_minal, $KLUCZ)], '/home-alt/');
$out['ciastko_obcy_klucz'] = zadanie([], ['maintenance_bypass' => evoke_one_wpm_sign($termin_ok, 'inny-klucz')], '/home-alt/');
// Podpis ważny, ale termin w treści podmieniony na późniejszy — podpis ma paść.
$podrobione = ($termin_ok + 86400) . '|' . explode('|', evoke_one_wpm_sign($termin_ok, $KLUCZ), 2)[1];
$out['ciastko_podrobiony_termin'] = zadanie([], ['maintenance_bypass' => $podrobione], '/home-alt/');

// Atrybuty ciasteczka — przy HTTPS i bez niego.
$GLOBALS['ssl'] = true;
$out['ciastko_atrybuty_https'] = evoke_one_wpm_cookie_args($termin_ok);
$GLOBALS['ssl'] = false;
$out['ciastko_atrybuty_http']  = evoke_one_wpm_cookie_args($termin_ok);
$GLOBALS['ssl'] = true;

// Sama treść podpisu: nie ma nieść klucza.
$out['podpis'] = [
    'wartosc'      => evoke_one_wpm_sign($termin_ok, $KLUCZ),
    'zawiera_klucz'=> strpos(evoke_one_wpm_sign($termin_ok, $KLUCZ), $KLUCZ) !== false,
];

// ── 3. Wykluczone ścieżki ─────────────────────────────────────────────────
/* Przypadki dobrane tak, żeby NAPRAWDĘ rozróżniały. Pierwsza wersja tego
   testu miała tu `/blog/moj-wp-admin` — który nie zawiera `/wp-admin`, tylko
   `-wp-admin`, więc przechodził i przed naprawą, i po niej. Wyszło to dopiero
   na mutacji: przywrócenie starego `strpos(...) !== false` nie zaczerwieniło
   testu. Adres musi zawierać dopasowywany ciąg RAZEM z ukośnikiem. */
$sciezki = [
    '/wp-admin'                => 'wbudowana, dokładnie',
    '/wp-admin/edit.php'       => 'wbudowana + podścieżka',
    '/wp-login.php'            => 'wbudowana, dokładnie',
    '/blog/wp-admin-po-polsku' => 'ZAWIERA /wp-admin w środku — NIE ma wykluczać',
    '/wp-administracja'        => 'zaczyna się od /wp-admin — NIE ma wykluczać',
    '/podglad'                 => 'wpis użytkownika, dokładnie',
    '/podglad/cokolwiek'       => 'wpis użytkownika + podścieżka',
    '/kategoria/podglad-x'     => 'ZAWIERA /podglad w środku — NIE ma wykluczać',
    '/podglad-produktu'        => 'zaczyna się od /podglad — NIE ma wykluczać',
];
foreach ($sciezki as $uri => $opis) {
    $out['sciezki'][$uri] = evoke_one_wpm_is_excluded($uri) ? 'wykluczona' : 'objeta';
}
// Wpis bez wiodącego ukośnika ma być znormalizowany, nie zignorowany.
$GLOBALS['options']['maintenance_excluded_paths'] = "logmein";
$out['bez_ukosnika'] = evoke_one_wpm_is_excluded('/logmein/panel') ? 'wykluczona' : 'objeta';
$GLOBALS['options']['maintenance_excluded_paths'] = "/podglad\n/logmein";

// ── 4. Zalogowany i wyłączony tryb ────────────────────────────────────────
$GLOBALS['zalogowany'] = true;
$out['zalogowany'] = zadanie([], [], '/home-alt/');
$GLOBALS['zalogowany'] = false;
$GLOBALS['options']['maintenance_mode'] = 0;
$out['tryb_wylaczony'] = zadanie([], [], '/home-alt/');
$GLOBALS['options']['maintenance_mode'] = 1;

// ── 5. Sanityzacja ustawień ───────────────────────────────────────────────
foreach ($GLOBALS['hooks']['admin_init'] ?? [] as $cb) { $cb(); }
$out['sanityzatory'] = array_keys($GLOBALS['sanitizers']);
$out['godziny_z_zakresu'] = [
    evoke_one_wpm_sanitize_hours(0),
    evoke_one_wpm_sanitize_hours('12'),
    evoke_one_wpm_sanitize_hours(99999),
];
$out['klucz_sanityzowany'] = evoke_one_wpm_sanitize_key("  <script>alert(1)</script>klucz  ");

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
