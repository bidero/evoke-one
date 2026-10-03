<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — ukryty adres logowania (1.288.0). Decyzje z 02.10
 * (docs/plan-kolejka.md, punkt 4).
 *
 * Włączony: `wp-login.php` i `/wp-admin/` dla niezalogowanego bez klucza
 * odpowiadają stroną 404 motywu. Kluczem jest WŁASNY ADRES strony
 * (np. `/panel-x7k2q9`): ustawia podpisane ciasteczko na 10 minut
 * i otwiera `wp-login.php`. Po zalogowaniu ciasteczko żyje tyle, co sesja
 * logowania — inaczej wylogowanie (`wp-login.php?loggedout=true`) i okno
 * „Sesja wygasła” w kokpicie kończyłyby się stroną 404.
 *
 * Wyjątki, bez których coś przestałoby działać:
 * - `admin-ajax.php` i `admin-post.php` — formularze, w tym logowanie
 *   Bricksa (idzie przez admin-ajax);
 * - hasło strony chronionej (`action=postpass`, wysłany formularz);
 * - link z e-maila resetu hasła (`action=rp` / `resetpass`) — tylko
 *   z WAŻNYM kluczem resetu; link potwierdzenia prośby o dane osobowe
 *   (`action=confirmaction`) — tylko z ważnym kluczem prośby.
 *
 * Zalogowanego blokada nie dotyczy wcale, więc wylogowanie działa zawsze.
 * Niezalogowany dostałby pod `action=logout` tylko pytanie „Na pewno?”
 * (bez sesji nonce nie przejdzie) — czyli zdradzenie strony; stąd 404.
 *
 * `?brx_use_wp_login` (obejście stron logowania Bricksa) bez klucza nie
 * działa: blokada idzie na `wp_loaded` z priorytetem 0, przed Bricksem (10).
 * Linki „Nie pamiętasz hasła?”, „Zarejestruj się” i „Zaloguj” prowadzą na
 * strony logowania z ustawień Bricksa, gdy są — wp-login.php i tak by ich
 * nie pokazał.
 *
 * Awaryjnie: stała `EVK_UKRYTY_ADRES_WYLACZ` w wp-config.php.
 */

const EVK_UA_OPCJA    = 'evk_ukryty_adres';
const EVK_UA_CIASTKO  = 'evk_ua';
const EVK_UA_MINUTY   = 10;
/* Adresy, które coś już znaczą dla WordPressa albo serwera — kluczem być nie mogą. */
const EVK_UA_ZAJETE   = ['wp-admin', 'wp-login', 'wp-login-php', 'wp-content', 'wp-includes', 'wp-json', 'login', 'admin', 'dashboard',
    'logowanie', 'feed', 'xmlrpc', 'xmlrpc-php', 'wp-cron', 'wp-cron-php', 'sitemap', 'wp-sitemap', 'robots-txt', 'en'];

/** @return array{enabled:int, adres:string} */
function evk_ua_ustawienia(): array {
    $z = get_option(EVK_UA_OPCJA, []);
    $z = is_array($z) ? $z : [];
    return ['enabled' => (int) !empty($z['enabled']), 'adres' => (string) ($z['adres'] ?? '')];
}

function evk_ua_awaryjnie(): bool {
    return defined('EVK_UKRYTY_ADRES_WYLACZ') && constant('EVK_UKRYTY_ADRES_WYLACZ');
}

function evk_ua_wlaczony(): bool {
    $u = evk_ua_ustawienia();
    return $u['enabled'] && $u['adres'] !== '' && !evk_ua_awaryjnie();
}

/** Losowy adres na start: „panel-” i 6 znaków bez mylących się (0/o, 1/l/i). */
function evk_ua_losuj(): string {
    $a = 'abcdefghjkmnpqrstuvwxyz23456789';
    $s = '';
    for ($i = 0; $i < 6; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return 'panel-' . $s;
}

/** Pusty łańcuch = adres dobry; inaczej powód odmowy. */
function evk_ua_sprawdz_adres(string $adres): string {
    if (!preg_match('/^[a-z0-9][a-z0-9-]{2,49}$/', $adres)) return 'Adres: małe litery, cyfry i myślniki, 3–50 znaków, bez myślnika na początku.';
    if (in_array($adres, EVK_UA_ZAJETE, true)) return 'Ten adres coś już znaczy dla WordPressa — wybierz inny.';
    $typy = array_values(get_post_types(['public' => true]));
    if (get_page_by_path($adres, OBJECT, $typy)) return 'Pod tym adresem jest już strona albo wpis — wybierz inny.';
    return '';
}

/** Adres-klucz do pokazania: przy zwykłych adresach WordPressa `/?klucz` (serwer bez przepisywania adresów nie poda `/klucz` WordPressowi). */
function evk_ua_url(string $adres = ''): string {
    $adres = $adres !== '' ? $adres : evk_ua_ustawienia()['adres'];
    return get_option('permalink_structure') ? home_url('/' . $adres) : home_url('/?' . $adres);
}

// =========================================================================
// CIASTECZKO-KLUCZ
// =========================================================================

/** Podpis: termin i adres — zmiana adresu unieważnia wszystkie wydane klucze. */
function evk_ua_podpis(int $termin, string $adres): string {
    return hash_hmac('sha256', $termin . '|' . $adres . '|evk-ukryty-adres', wp_salt('auth'));
}

function evk_ua_klucz_wazny(): bool {
    $c = (string) ($_COOKIE[EVK_UA_CIASTKO] ?? '');
    if (!preg_match('/^(\d{9,11})\|([a-f0-9]{64})$/', $c, $m)) return false;
    $termin = (int) $m[1];
    if ($termin < time()) return false;
    return hash_equals(evk_ua_podpis($termin, evk_ua_ustawienia()['adres']), $m[2]);
}

/**
 * @param int $termin  do kiedy klucz ważny (sprawdza serwer)
 * @param int $wygasa  data wygaśnięcia ciasteczka w przeglądarce; 0 = do zamknięcia przeglądarki
 */
function evk_ua_daj_klucz(int $termin, int $wygasa): void {
    $wartosc = $termin . '|' . evk_ua_podpis($termin, evk_ua_ustawienia()['adres']);
    $_COOKIE[EVK_UA_CIASTKO] = $wartosc;
    if (headers_sent()) return;
    /* Strona i WordPress mogą siedzieć w różnych katalogach — klucz musi dojść i do adresu-klucza, i do wp-login.php. */
    $sciezki = array_unique([defined('COOKIEPATH') ? (string) constant('COOKIEPATH') : '/', defined('SITECOOKIEPATH') ? (string) constant('SITECOOKIEPATH') : '/']);
    foreach ($sciezki as $sciezka) {
        setcookie(EVK_UA_CIASTKO, $wartosc, ['expires' => $wygasa, 'path' => $sciezka !== '' ? $sciezka : '/',
            'domain' => defined('COOKIE_DOMAIN') ? (string) constant('COOKIE_DOMAIN') : '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
    }
}

/* Zalogowany: klucz na czas sesji logowania (ten sam termin co ciasteczko WordPressa). */
add_action('set_auth_cookie', function ($cookie, $expire, $expiration) {
    if (!evk_ua_wlaczony()) return;
    evk_ua_daj_klucz((int) $expiration, (int) $expire);
}, 10, 3);

// =========================================================================
// ADRES-KLUCZ I BLOKADA
// =========================================================================

/** Ścieżka żądania względem adresu strony, bez ukośników na brzegach. */
function evk_ua_sciezka(): string {
    $sciezka = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $dom = rtrim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    if ($dom !== '' && strpos($sciezka, $dom) === 0) $sciezka = substr($sciezka, strlen($dom));
    return trim(rawurldecode($sciezka), '/');
}

function evk_ua_to_adres_klucz(string $adres): bool {
    $sciezka = evk_ua_sciezka();
    if ($sciezka === $adres) return true;
    /* `/?klucz` — przy zwykłych adresach WordPressa. */
    return ($sciezka === '' || $sciezka === 'index.php') && array_key_exists($adres, $_GET);
}

/** Wyjątki na wp-login.php (patrz nagłówek pliku). */
function evk_ua_wyjatek_logowania(): bool {
    $akcja = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? $_REQUEST['action'] : 'login';
    if ($akcja === 'postpass') return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($akcja === 'rp' || $akcja === 'resetpass') {
        $klucz = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
        $login = isset($_GET['login']) && is_string($_GET['login']) ? $_GET['login'] : '';
        $c = 'wp-resetpass-' . (defined('COOKIEHASH') ? (string) constant('COOKIEHASH') : '');
        if (($klucz === '' || $login === '') && isset($_COOKIE[$c]) && is_string($_COOKIE[$c]) && strpos($_COOKIE[$c], ':') !== false) {
            [$login, $klucz] = explode(':', wp_unslash($_COOKIE[$c]), 2);
        }
        if ($klucz === '' || $login === '') return false;
        return !is_wp_error(check_password_reset_key($klucz, $login));
    }
    if ($akcja === 'confirmaction') {
        $id = isset($_GET['request_id']) ? (int) $_GET['request_id'] : 0;
        $klucz = isset($_GET['confirm_key']) && is_string($_GET['confirm_key']) ? $_GET['confirm_key'] : '';
        return $id > 0 && $klucz !== '' && wp_validate_user_request_key($id, $klucz) === true;
    }
    return false;
}

/** Strona 404 motywu w miejscu wp-login.php — ten sam adres, kod 404, nic, co zdradzałoby logowanie. */
function evk_ua_strona_404(): void {
    if (!defined('WP_USE_THEMES')) define('WP_USE_THEMES', true);
    /* Odgadywanie adresu przy 404 (redirect_canonical) przekierowałoby na podobny wpis. */
    remove_action('template_redirect', 'redirect_canonical');
    $GLOBALS['pagenow'] = 'index.php';
    wp(['error' => '404']);
    require ABSPATH . WPINC . '/template-loader.php';
    exit;
}

add_action('wp_loaded', function () {
    if (!evk_ua_wlaczony()) return;
    $adres = evk_ua_ustawienia()['adres'];
    $teraz = (string) ($GLOBALS['pagenow'] ?? '');

    if (!is_admin() && $teraz !== 'wp-login.php' && evk_ua_to_adres_klucz($adres)) {
        if (is_user_logged_in()) { wp_safe_redirect(admin_url()); exit; }
        evk_ua_daj_klucz(time() + EVK_UA_MINUTY * MINUTE_IN_SECONDS, time() + EVK_UA_MINUTY * MINUTE_IN_SECONDS);
        $cel = isset($_GET['redirect_to']) && is_string($_GET['redirect_to']) ? wp_unslash($_GET['redirect_to']) : '';
        wp_safe_redirect($cel !== '' ? add_query_arg('redirect_to', rawurlencode($cel), site_url('wp-login.php', 'login')) : site_url('wp-login.php', 'login'));
        exit;
    }

    if (is_user_logged_in()) return;
    $logowanie = $teraz === 'wp-login.php';
    $kokpit = is_admin() && !wp_doing_ajax() && !in_array($teraz, ['admin-ajax.php', 'admin-post.php'], true);
    if (!$logowanie && !$kokpit) return;

    if (evk_ua_klucz_wazny()) {
        /* Z kluczem wp-login.php jest wp-login.php — także gdy Bricks ma własną stronę logowania. */
        if ($logowanie) $_COOKIE['brx_use_wp_login'] = '1';
        return;
    }
    if ($logowanie && evk_ua_wyjatek_logowania()) return;
    if ($kokpit) {
        /* Kokpit nie wyrenderuje strony motywu (WP_ADMIN) — idzie na 404 strony, bez zdradzania wp-login.php. */
        wp_safe_redirect(add_query_arg('error', '404', home_url('/')));
        exit;
    }
    evk_ua_strona_404();
}, 0);

// =========================================================================
// LINKI NA STRONY LOGOWANIA BRICKSA
// =========================================================================

/** Opublikowana strona z ustawień Bricksa („Custom authentication pages”) albo 0. */
function evk_ua_strona_bricks(string $klucz): int {
    $u = get_option('bricks_global_settings', []);
    $id = is_array($u) ? (int) ($u[$klucz] ?? 0) : 0;
    return $id && get_post_status($id) === 'publish' ? $id : 0;
}

/*
 * Filtry dopiero PO `wp_loaded` (priorytet 20): Bricks na `wp_loaded` (10)
 * rozpoznaje swoją stronę logowania, porównując adres żądania ze ścieżką
 * `wp_login_url()`. Podmieniony wcześniej adres to adres SAMEJ strony Bricksa,
 * więc Bricks przekierowywał ją na nią samą bez końca (ERR_TOO_MANY_REDIRECTS,
 * logowanie-adres-bricks). Linki w treści strony powstają później — dostają
 * już adresy Bricksa.
 */
add_action('wp_loaded', function () {
    add_filter('login_url', function ($url, $cel = '') {
        if (!evk_ua_wlaczony() || !($id = evk_ua_strona_bricks('login_page'))) return $url;
        $nowy = (string) get_permalink($id);
        return $cel !== '' ? add_query_arg('redirect_to', rawurlencode((string) $cel), $nowy) : $nowy;
    }, 20, 2);
    add_filter('lostpassword_url', function ($url) {
        if (!evk_ua_wlaczony() || !($id = evk_ua_strona_bricks('lost_password_page'))) return $url;
        return (string) get_permalink($id);
    }, 20);
    add_filter('register_url', function ($url) {
        if (!evk_ua_wlaczony() || !($id = evk_ua_strona_bricks('registration_page'))) return $url;
        return (string) get_permalink($id);
    }, 20);
}, 20);

// =========================================================================
// ZAPIS Z PANELU
// =========================================================================

add_action('wp_ajax_evk_ukryty_adres', function () {
    check_ajax_referer('evk_ukryty_adres', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.', 403);
    $adres = strtolower(trim(sanitize_text_field(wp_unslash((string) ($_POST['adres'] ?? '')))));
    $wlacz = !empty($_POST['enabled']);
    $stary = evk_ua_ustawienia();
    if ($adres === '' && !$wlacz) {
        update_option(EVK_UA_OPCJA, ['enabled' => 0, 'adres' => ''], false);
        wp_send_json_success(['url' => '']);
    }
    $blad = evk_ua_sprawdz_adres($adres);
    if ($blad !== '') wp_send_json_error($blad);
    update_option(EVK_UA_OPCJA, ['enabled' => (int) $wlacz, 'adres' => $adres], false);
    /* Klucz dla tej przeglądarki od razu — inaczej po wylogowaniu administrator, który właśnie włączył, trafiłby na 404. */
    if ($wlacz && ($stary['adres'] !== $adres || !$stary['enabled'])) {
        $sesja = wp_parse_auth_cookie('', 'logged_in');
        evk_ua_daj_klucz($sesja ? (int) $sesja['expiration'] : time() + 2 * DAY_IN_SECONDS, 0);
    }
    wp_send_json_success(['url' => evk_ua_url($adres)]);
});
