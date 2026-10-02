<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Security: Rejestracja ustawień i helpery
 * Wspólna baza dla wszystkich modułów security.
 */

add_action('admin_init', function () {
    register_setting('evoke_one_security', 'evk_security', [
        'sanitize_callback' => 'evk_security_sanitize',
        'default'           => [],
    ]);
});

/**
 * Komunikat blokady logowania — jedna lista dla obu dróg zapisu.
 *
 * Do 1.132.0 zapis przez `register_setting()` przepuszczał go przez
 * `wp_kses_post()` (lista dla treści wpisów, z atrybutami `style` na wielu
 * znacznikach), a zapis przez AJAX przez własną, wąską listę. Ten sam tekst
 * dostawał więc różne sita zależnie od tego, którym przyciskiem go zapisano —
 * a wyświetla się na PUBLICZNEJ stronie logowania.
 */
function evk_security_sanitize_message($tekst): string {
    return wp_kses((string) $tekst, [
        'strong' => [], 'em' => [], 'br' => [],
        'a'      => ['href' => [], 'title' => []],
        'p'      => [], 'span' => [],
    ]);
}

function evk_security_sanitize($input): array {
    $input = is_array($input) ? $input : [];
    return [
        'limit_login_enabled'     => !empty($input['limit_login_enabled'])     ? 1 : 0,
        'max_attempts'            => max(1, min(100, absint($input['max_attempts']   ?? 5))),
        'reset_hours'             => max(1, min(720, absint($input['reset_hours']    ?? 24))),
        'limit_login_message'     => evk_security_sanitize_message($input['limit_login_message'] ?? ''),
        'hide_wp_version'         => !empty($input['hide_wp_version'])         ? 1 : 0,
        'disable_bundled_themes'  => !empty($input['disable_bundled_themes'])  ? 1 : 0,
        'rest_block_all'          => !empty($input['rest_block_all'])          ? 1 : 0,
        'disabled_rest_endpoints' => evk_security_trasy_rest($input['disabled_rest_endpoints'] ?? []),
        'rest_uzytkownicy'        => array_key_exists('rest_uzytkownicy', $input) ? (!empty($input['rest_uzytkownicy']) ? 1 : 0) : 1,
        'rest_wyjatki'            => evk_security_wyjatki_rest($input['rest_wyjatki'] ?? EVK_REST_WYJATKI),
    ] + evk_security_sanitize_proxy($input) + evk_security_sanitize_naglowki($input);
}

/**
 * REST (1.281.0): przestrzenie tras dostępne dla gości mimo blokady całego
 * REST API — front ich potrzebuje: AJAX-owe pętle, filtry i stronicowanie
 * Bricksa, koszyk WooCommerce (Store API), formularze Contact Form 7, oEmbed,
 * statystyki Evoke.
 */
const EVK_REST_WYJATKI = ['bricks/v1', 'wc/store', 'contact-form-7/v1', 'oembed/1.0', 'evoke/v1'];

/**
 * Trasy REST do blokowania — w DOKŁADNYM brzmieniu z serwera REST. Do 1.280.0
 * szły przez sanitize_text_field() bez zdjęcia ukośników: „(?P<id>[\d]+)”
 * tracił „<id>” i dostawał podwójne ukośniki, więc trasa z parametrem
 * (np. /wp/v2/users/(?P<id>[\d]+) — wyliczanie użytkowników) NIGDY nie była
 * blokowana, a panel pokazywał ją jako odznaczoną.
 *
 * @param mixed $trasy Lista już bez ukośników magic quotes.
 * @return list<string>
 */
function evk_security_trasy_rest($trasy): array {
    /* Po `init` serwer REST zna trasy wszystkich wtyczek (rest_get_server() sam odpala rest_api_init);
       wcześniej — bez sprawdzania istnienia, tylko kształt. */
    $znane = function_exists('rest_get_server') && did_action('init') ? array_keys(rest_get_server()->get_routes()) : null;
    $out = [];
    foreach ((array) $trasy as $t) {
        $t = (string) $t;
        if ($t === '' || strlen($t) > 300 || $t[0] !== '/' || preg_match('/[\x00-\x1f]/', $t)) continue;
        if ($znane !== null && !in_array($t, $znane, true)) continue;
        $out[] = $t;
    }
    return array_values(array_unique($out));
}

/**
 * Wyjątki blokady REST: przestrzenie tras (np. „bricks/v1”), z pola tekstowego
 * (linia na przestrzeń) albo listy.
 *
 * @param mixed $w
 * @return list<string>
 */
function evk_security_wyjatki_rest($w): array {
    $linie = is_array($w) ? $w : preg_split('/[\r\n,]+/', (string) $w);
    $out = [];
    foreach ((array) $linie as $l) {
        $l = trim(strtolower((string) $l), " \t/");
        if ($l !== '' && preg_match('#^[a-z0-9._-]+(/[a-z0-9._-]+)*$#', $l)) $out[] = $l;
    }
    return array_values(array_unique($out));
}

/* Nagłówki bezpieczeństwa (1.280.0): stałe i domyślne funkcje — tu, a nie
   w security/naglowki.php, bo walidacja niżej potrzebuje ich także bez niego. */
/** Funkcje Permissions-Policy do wyboru w panelu: klucz → opis. */
const EVK_NAGLOWKI_UPRAWNIENIA = ['camera' => 'Kamera', 'microphone' => 'Mikrofon', 'geolocation' => 'Geolokalizacja', 'payment' => 'Płatności (Payment Request)'];
const EVK_NAGLOWKI_REFERRER = ['strict-origin-when-cross-origin', 'strict-origin', 'same-origin', 'no-referrer'];
/** HSTS: sekundy → opis. Rok to próg list preload, ale `preload` celowo nie idzie. */
const EVK_NAGLOWKI_HSTS = [300 => '5 minut (próba)', 86400 => '1 dzień', 2592000 => '30 dni', 31536000 => '1 rok'];

/** Domyślne funkcje blokowane: wszystkie cztery, bez płatności przy WooCommerce. @return list<string> */
function evk_naglowki_uprawnienia_domyslne(): array {
    $l = array_keys(EVK_NAGLOWKI_UPRAWNIENIA);
    return class_exists('WooCommerce') ? array_values(array_diff($l, ['payment'])) : $l;
}

/**
 * Nagłówki bezpieczeństwa (1.280.0, security/naglowki.php) — wspólne dla
 * obu dróg zapisu, jak pośrednik niżej.
 *
 * @return array<string,mixed>
 */
function evk_security_sanitize_naglowki(array $input): array {
    $d = evk_security_get();
    $war = (string) ($input['hdr_referrer_wartosc'] ?? $d['hdr_referrer_wartosc']);
    $wiek = (int) ($input['hdr_hsts_wiek'] ?? $d['hdr_hsts_wiek']);
    $upr = isset($input['hdr_uprawnienia']) ? (array) $input['hdr_uprawnienia'] : (array) $d['hdr_uprawnienia'];
    $b = static function (string $k) use ($input, $d): int { return array_key_exists($k, $input) ? (!empty($input[$k]) ? 1 : 0) : (int) $d[$k]; };
    return [
        'hdr_nosniff'          => $b('hdr_nosniff'),
        'hdr_referrer'         => $b('hdr_referrer'),
        'hdr_referrer_wartosc' => in_array($war, EVK_NAGLOWKI_REFERRER, true) ? $war : EVK_NAGLOWKI_REFERRER[0],
        'hdr_permissions'      => $b('hdr_permissions'),
        'hdr_uprawnienia'      => array_values(array_intersect(array_keys(EVK_NAGLOWKI_UPRAWNIENIA), array_map('strval', $upr))),
        'hdr_ramki'            => $b('hdr_ramki'),
        'hdr_hsts'             => $b('hdr_hsts'),
        'hdr_hsts_wiek'        => isset(EVK_NAGLOWKI_HSTS[$wiek]) ? $wiek : 300,
        'hdr_hsts_sub'         => $b('hdr_hsts_sub'),
    ];
}

/**
 * Pośrednik przed stroną (includes/security/ip-klienta.php) — wspólne dla obu
 * dróg zapisu. Ta funkcja stoi W `evk_security_sanitize()` z tego samego
 * powodu, co komunikat blokady: `register_setting()` przepuszcza przez nią
 * KAŻDY zapis opcji, także ten z AJAX-a, a klucza, którego tu nie ma, po
 * cichu nie zapisze. Adresy zostają tekstem administratora — parser przy
 * użyciu odrzuca to, co nie jest adresem ani siecią.
 */
function evk_security_sanitize_proxy(array $input): array {
    $tryb = (string) ($input['proxy_tryb'] ?? 'brak');
    return [
        'proxy_tryb'    => in_array($tryb, EVK_IP_TRYBY, true) ? $tryb : 'brak',
        'proxy_zaufane' => sanitize_textarea_field((string) ($input['proxy_zaufane'] ?? '')),
    ];
}

function evk_security_get(): array {
    return wp_parse_args((array) get_option('evk_security', []), [
        'limit_login_enabled'     => 0,
        'max_attempts'            => 5,
        'reset_hours'             => 24,
        'limit_login_message'     => '',
        'hide_wp_version'         => 0,
        'disable_bundled_themes'  => 0,
        'rest_block_all'          => 0,
        'disabled_rest_endpoints' => [],
        /* REST (1.281.0): wyliczanie użytkowników zablokowane domyślnie (decyzja 02.10). */
        'rest_uzytkownicy'        => 1,
        'rest_wyjatki'            => EVK_REST_WYJATKI,
        'proxy_tryb'              => 'brak',
        'proxy_zaufane'           => '',
        /* Nagłówki (1.280.0): domyślnie włączone, poza HSTS. */
        'hdr_nosniff'             => 1,
        'hdr_referrer'            => 1,
        'hdr_referrer_wartosc'    => 'strict-origin-when-cross-origin',
        'hdr_permissions'         => 1,
        'hdr_uprawnienia'         => evk_naglowki_uprawnienia_domyslne(),
        'hdr_ramki'               => 1,
        'hdr_hsts'                => 0,
        'hdr_hsts_wiek'           => 300,
        'hdr_hsts_sub'            => 0,
    ]);
}

// =========================================================================
// AJAX SAVE — merge z istniejącymi ustawieniami
// =========================================================================

add_action('wp_ajax_evk_save_security_section', function () {
    check_ajax_referer('evk_security_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.');

    $section = sanitize_key($_POST['section'] ?? '');
    $raw     = $_POST['data'] ?? [];
    if (!is_array($raw)) $raw = [];

    // Pobierz aktualne ustawienia
    $current = evk_security_get();

    // Merge tylko pól należących do danej sekcji
    $merged = array_merge($current, evk_security_sanitize_section($section, $raw));

    update_option('evk_security', $merged);
    wp_send_json_success(['saved' => $section]);
});

function evk_security_sanitize_section(string $section, array $raw): array {
    switch ($section) {
        case 'login':
            // Ekran limitu logowań ma też pole pośrednika (adres odwiedzających).
            return [
                'limit_login_enabled' => !empty($raw['limit_login_enabled']) ? 1 : 0,
                'max_attempts'        => max(1, min(100, absint($raw['max_attempts'] ?? 5))),
                'reset_hours'         => max(1, min(720, absint($raw['reset_hours'] ?? 24))),
                'limit_login_message' => evk_security_sanitize_message($raw['limit_login_message'] ?? ''),
            ] + (isset($raw['proxy_tryb']) ? evk_security_sanitize_proxy(wp_unslash($raw)) : []);
        case 'rest':
            $raw = wp_unslash($raw);
            return [
                'rest_block_all'          => !empty($raw['rest_block_all']) ? 1 : 0,
                'disabled_rest_endpoints' => evk_security_trasy_rest(is_array($raw['disabled_rest_endpoints'] ?? null) ? $raw['disabled_rest_endpoints'] : []),
                'rest_uzytkownicy'        => !empty($raw['rest_uzytkownicy']) ? 1 : 0,
                'rest_wyjatki'            => evk_security_wyjatki_rest($raw['rest_wyjatki'] ?? ''),
            ];
        case 'naglowki':
            /* Pola wyboru niezaznaczone przychodzą jako 0, lista funkcji — pusta lista. */
            return evk_security_sanitize_naglowki(wp_unslash($raw) + ['hdr_uprawnienia' => []]);
        case 'hardening':
            return [
                'hide_wp_version'        => !empty($raw['hide_wp_version']) ? 1 : 0,
                'disable_bundled_themes' => !empty($raw['disable_bundled_themes']) ? 1 : 0,
            ];
        default:
            return [];
    }
}
