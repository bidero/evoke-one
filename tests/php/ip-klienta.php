<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Adres IP odwiedzającego (includes/security/ip-klienta.php) na PRAWDZIWYM
 * WordPressie: funkcje sieci, tryby pośrednika, limit logowań za Cloudflare,
 * log 404, zapis ustawień przez AJAX i ekran w panelu.
 *
 *   php tests/php/ip-klienta.php
 *
 * Stan opcji (evk_security, blokady, logi 404) wraca do sprzed sondy.
 */

require __DIR__ . '/_testowy-wp.php';

$out = [];
$sprzatanie = [];
register_shutdown_function(static function () use (&$sprzatanie) {
    foreach (array_reverse($sprzatanie) as $f) { try { $f(); } catch (Throwable $e) {} }
});
wp_set_current_user(1);

foreach (['evk_security', 'evk_blocked_ips', 'evk_failed_logins', 'evk_404_enabled'] as $opcja) {
    $przed = get_option($opcja, null);
    $sprzatanie[] = static function () use ($opcja, $przed) {
        $przed === null ? delete_option($opcja) : update_option($opcja, $przed);
    };
}
$serwer_przed = $_SERVER;
$sprzatanie[] = static function () use ($serwer_przed) { $_SERVER = $serwer_przed; };

/** Ustawia tryb pośrednika wprost w opcji (z pominięciem panelu). */
function evk_t_tryb(string $tryb, string $zaufane = '', array $inne = []): void {
    update_option('evk_security', array_merge((array) get_option('evk_security', []), $inne,
        ['proxy_tryb' => $tryb, 'proxy_zaufane' => $zaufane]));
}

/** Nagłówki żądania jak od serwera WWW. */
function evk_t_zadanie(string $zdalny, array $naglowki = []): void {
    unset($_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);
    $_SERVER['REMOTE_ADDR'] = $zdalny;
    foreach ($naglowki as $k => $v) $_SERVER[$k] = $v;
}

/** Uruchamia WYŁĄCZNIE te funkcje haka, które zdefiniowano w danym pliku. */
function evk_t_hak_z_pliku(string $hak, string $plik, array $argumenty = []): int {
    global $wp_filter;
    $ile = 0;
    foreach ((array) ($wp_filter[$hak]->callbacks ?? []) as $priorytet => $funkcje) {
        foreach ($funkcje as $f) {
            $cb = $f['function'];
            try {
                $r = is_string($cb) || $cb instanceof Closure ? new ReflectionFunction($cb) : null;
            } catch (ReflectionException $e) { $r = null; }
            if ($r && basename((string) $r->getFileName()) === $plik) { $cb(...$argumenty); $ile++; }
        }
    }
    return $ile;
}

// ── Funkcje sieci ───────────────────────────────────────────────────────────
$pary = [
    ['104.16.1.1', '104.16.0.0/13'], ['104.24.0.1', '104.16.0.0/13'], ['104.23.255.255', '104.16.0.0/13'],
    ['2606:4700:10::6816:1', '2606:4700::/32'], ['2606:4701::1', '2606:4700::/32'], ['1.2.3.4', '2606:4700::/32'],
    ['10.0.0.5', '10.0.0.5'], ['10.0.0.6', '10.0.0.5'], ['192.168.200.7', '192.168.0.0/16'],
    ['10.0.0.1', '10.0.0.0/31'], ['10.0.0.2', '10.0.0.0/31'], ['10.0.0.1', '10.0.0.0/x'],
];
$out['w_sieci'] = array_map(static function ($p) { return evk_ip_w_sieci($p[0], $p[1]); }, $pary);
$out['lista_sieci'] = evk_ip_lista_sieci("10.0.0.5\n0.0.0.0/0\n::/0\nśmieci\n192.168.0.0/16, 2001:db8::/33\n10.0.0.0/40\n10.0.0.5");
$out['mapowany_v4'] = evk_ip_poprawny('::ffff:1.2.3.4');

// ── Tryby ───────────────────────────────────────────────────────────────────
$WEZEL = '172.68.1.10';     // sieć Cloudflare 172.64.0.0/13
$A     = '5.6.7.8';
$B     = '5.6.7.9';
$tryby = [];
evk_t_tryb('brak');
evk_t_zadanie($WEZEL, ['HTTP_CF_CONNECTING_IP' => $A]);
$tryby['brak_za_cf'] = evk_ip_klienta();
evk_t_tryb('cloudflare');
$tryby['cf'] = evk_ip_klienta();
evk_t_zadanie('9.9.9.9', ['HTTP_CF_CONNECTING_IP' => '1.1.1.1']);
$tryby['cf_podrobiony'] = evk_ip_klienta();
evk_t_zadanie($WEZEL, ['HTTP_CF_CONNECTING_IP' => 'nie-adres']);
$tryby['cf_smiec'] = evk_ip_klienta();
evk_t_zadanie('::ffff:' . $WEZEL, ['HTTP_CF_CONNECTING_IP' => $A]);
$tryby['cf_wezel_jako_v6'] = evk_ip_klienta();
evk_t_zadanie('10.0.0.5', ['HTTP_CF_CONNECTING_IP' => $A]);
$tryby['cf_lokalny_bez_zaufania'] = evk_ip_klienta();
evk_t_tryb('cloudflare', '10.0.0.5');
$tryby['cf_lokalny_zaufany'] = evk_ip_klienta();
evk_t_tryb('inne', '10.0.0.5');
evk_t_zadanie('10.0.0.5', ['HTTP_X_FORWARDED_FOR' => '6.6.6.6, ' . $A]);
$tryby['inne'] = evk_ip_klienta();
evk_t_tryb('inne', "10.0.0.5\n203.0.113.0/24");
evk_t_zadanie('10.0.0.5', ['HTTP_X_FORWARDED_FOR' => $A . ', 203.0.113.9']);
$tryby['inne_lancuch'] = evk_ip_klienta();
evk_t_zadanie('9.9.9.9', ['HTTP_X_FORWARDED_FOR' => '1.1.1.1']);
$tryby['inne_obcy'] = evk_ip_klienta();
evk_t_zadanie('10.0.0.5', []);
$tryby['inne_bez_naglowka'] = evk_ip_klienta();
evk_t_zadanie('10.0.0.5', ['HTTP_X_FORWARDED_FOR' => '1.1.1.1, śmieć, ' . $A]);
$tryby['inne_smiec_w_srodku'] = evk_ip_klienta();
evk_t_zadanie('10.0.0.5', ['HTTP_X_FORWARDED_FOR' => '1.1.1.1, śmieć']);
$tryby['inne_smiec_z_prawej'] = evk_ip_klienta();
evk_t_tryb('nieznany-tryb');
evk_t_zadanie($WEZEL, ['HTTP_CF_CONNECTING_IP' => $A]);
$tryby['nieznany_tryb'] = evk_ip_klienta();
$out['tryby'] = $tryby;

// ── Limit logowań za Cloudflare ─────────────────────────────────────────────
$limit = ['limit_login_enabled' => 1, 'max_attempts' => 2, 'reset_hours' => 24];
$proba = static function (string $zdalny, string $cf) {
    evk_t_zadanie($zdalny, ['HTTP_CF_CONNECTING_IP' => $cf]);
    evk_login_record_failure('ktos');
};
$czy_zablokowany = static function (string $zdalny, string $cf): bool {
    evk_t_zadanie($zdalny, ['HTTP_CF_CONNECTING_IP' => $cf]);
    return evk_login_is_blocked(evk_login_get_ip());
};
$logowanie = [];
foreach (['brak', 'cloudflare'] as $tryb) {
    delete_option('evk_blocked_ips');
    delete_option('evk_failed_logins');
    evk_t_tryb($tryb, '', $limit);
    $proba($WEZEL, $A);
    $proba($WEZEL, $A);
    $logowanie[$tryb] = ['a' => $czy_zablokowany($WEZEL, $A), 'b' => $czy_zablokowany($WEZEL, $B),
                         'klucze' => array_keys((array) get_option('evk_blocked_ips', []))];
}
// Podrobiony nagłówek spoza Cloudflare: każda próba z „innym" adresem.
delete_option('evk_blocked_ips');
delete_option('evk_failed_logins');
evk_t_tryb('cloudflare', '', $limit);
$proba('9.9.9.9', '1.1.1.1');
$proba('9.9.9.9', '1.1.1.2');
$logowanie['podrobiony'] = ['zablokowany' => $czy_zablokowany('9.9.9.9', '1.1.1.3'),
                            'klucze' => array_keys((array) get_option('evk_blocked_ips', []))];
$out['logowanie'] = $logowanie;
delete_option('evk_blocked_ips');
delete_option('evk_failed_logins');

// ── Newsletter: adres w zapisie zgody ───────────────────────────────────────
evk_t_tryb('cloudflare');
evk_t_zadanie($WEZEL, ['HTTP_CF_CONNECTING_IP' => $A]);
$out['newsletter_ip'] = evk_nl_client_ip();
evk_t_zadanie('', []);
$out['newsletter_bez_adresu'] = evk_nl_client_ip();

// ── Log 404 ─────────────────────────────────────────────────────────────────
update_option('evk_404_enabled', 1);
evk_t_zadanie($WEZEL, ['HTTP_CF_CONNECTING_IP' => $A]);
$_SERVER['REQUEST_URI'] = '/evk-t-nie-ma-' . wp_rand();
$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (test)';
global $wp_query;
$wp_query->set_404();
$przed_404 = get_posts(['post_type' => 'evk_404_log', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any']);
$out['hak_404'] = evk_t_hak_z_pliku('template_redirect', 'logs-404.php');
$nowe = array_diff(get_posts(['post_type' => 'evk_404_log', 'posts_per_page' => -1, 'fields' => 'ids', 'post_status' => 'any']), $przed_404);
$out['log_404_ip'] = $nowe ? (string) get_post_meta((int) reset($nowe), 'ip', true) : '(brak wpisu)';
foreach ($nowe as $id) wp_delete_post((int) $id, true);

// ── Zapis ustawień przez AJAX (jak z panelu) ────────────────────────────────
/* `register_setting()` przepuszcza KAŻDY zapis `evk_security` przez
   evk_security_sanitize() — klucza, którego tam nie ma, nie zapisze. Hak
   z admin_init odpalony tak jak w prawdziwym żądaniu do admin-ajax.php. */
evk_t_hak_z_pliku('admin_init', 'settings.php');
add_filter('wp_die_ajax_handler', static function () {
    return static function () { throw new RuntimeException('wp_die'); };
});
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
$zapisz = static function (array $dane): array {
    $_POST = $_REQUEST = ['nonce' => wp_create_nonce('evk_security_nonce'), 'section' => 'login', 'data' => wp_slash($dane)];
    ob_start();
    try { do_action('wp_ajax_evk_save_security_section'); } catch (RuntimeException $e) {}
    $odp = json_decode((string) ob_get_clean(), true);
    $s = (array) get_option('evk_security', []);
    return ['sukces' => $odp['success'] ?? null, 'tryb' => $s['proxy_tryb'] ?? null, 'zaufane' => $s['proxy_zaufane'] ?? null,
            'limit' => $s['max_attempts'] ?? null];
};
$pola = ['limit_login_enabled' => 0, 'max_attempts' => 7, 'reset_hours' => 24, 'limit_login_message' => ''];
$out['zapis'] = $zapisz($pola + ['proxy_tryb' => 'cloudflare', 'proxy_zaufane' => "10.0.0.5\n192.168.0.0/16"]);
$out['zapis_zly_tryb'] = $zapisz($pola + ['proxy_tryb' => 'wszystkim-ufaj', 'proxy_zaufane' => '']);
// Pełna sanityzacja (droga register_setting / import ustawień) nie gubi pól.
$pelna = evk_security_sanitize(['proxy_tryb' => 'inne', 'proxy_zaufane' => '10.1.1.1']);
$out['sanityzacja_pelna'] = ['tryb' => $pelna['proxy_tryb'] ?? null, 'zaufane' => $pelna['proxy_zaufane'] ?? null];

// ── Ekran w panelu ──────────────────────────────────────────────────────────
require_once ABSPATH . 'wp-admin/includes/template.php';   // submit_button() paska zapisu
$ekran = static function (): string {
    $evk_sec   = evk_security_get();
    $sec_nonce = wp_create_nonce('evk_security_nonce');
    ob_start();
    require EVK_TEST_ROOT_WTYCZKI . '/includes/admin/security-login.php';
    return (string) ob_get_clean();
};
define('EVK_TEST_ROOT_WTYCZKI', getenv('EVK_TEST_ROOT') ?: dirname(__DIR__, 2));
evk_t_tryb('brak');
evk_t_zadanie($WEZEL, ['HTTP_CF_CONNECTING_IP' => $A]);
$html_brak = $ekran();
evk_t_tryb('cloudflare');
$html_cf = $ekran();
evk_t_tryb('brak');
evk_t_zadanie('5.5.5.5', []);
$html_wprost = $ekran();
$out['ekran'] = [
    'pole_trybu'         => (bool) preg_match('#<label for="evk-proxy-tryb">.*?<select id="evk-proxy-tryb" name="evk_security\[proxy_tryb\]">#s', $html_brak),
    'pole_adresow'       => (bool) preg_match('#<label for="evk-proxy-zaufane">.*?<textarea id="evk-proxy-zaufane" name="evk_security\[proxy_zaufane\]"#s', $html_brak),
    'ostrzezenie_cf'     => strpos($html_brak, 'data-evk-proxy-ostrzezenie') !== false,
    'ostrzezenie_po'     => strpos($html_cf, 'data-evk-proxy-ostrzezenie') !== false,
    'ostrzezenie_wprost' => strpos($html_wprost, 'data-evk-proxy-ostrzezenie') !== false,
    'adres_przy_cf'      => (bool) preg_match('#Twój adres przy „Cloudflare"</th><td><code>' . preg_quote($A, '#') . '</code>#', $html_brak),
    'wybrany_cf'         => (bool) preg_match('#<option value="cloudflare"\s+selected#', $html_cf),
];

echo wp_json_encode($out);
