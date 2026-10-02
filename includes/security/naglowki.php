<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke One — Security: nagłówki bezpieczeństwa (1.280.0).
 *
 * Decyzje zgłaszającego (02.10): przełączniki w panelu (Bezpieczeństwo →
 * Nagłówki). Domyślnie włączone: X-Content-Type-Options, Referrer-Policy,
 * Permissions-Policy (kamera, mikrofon, geolokalizacja, płatności) i ramki
 * tylko z tej strony (`frame-ancestors 'self'` + `X-Frame-Options`). HSTS
 * domyślnie wyłączony, z wyborem czasu, bez `preload`. Pełne CSP — później.
 *
 * Płatności przy WooCommerce: `payment=()` wyłącza Payment Request API —
 * przyciski Apple Pay / Google Pay (np. Stripe) przestałyby działać. Domyślna
 * lista pomija wtedy `payment`; włączyć można świadomie w panelu.
 *
 * Ramki z tej samej strony zostają (builder Bricksa, podgląd w Customizerze,
 * okienka panelu), obce strony nie osadzą jej w <iframe> (clickjacking).
 */

/* Stałe i domyślna lista funkcji siedzą w settings.php — walidacja ustawień
   potrzebuje ich także tam, gdzie ten plik nie jest wczytany. */

/**
 * Nagłówki z ustawień (`evk_security_get()`): nazwa → wartość. HSTS tylko
 * przez HTTPS — przez HTTP przeglądarka i tak go pomija, a strona bez
 * certyfikatu nie powinna go obiecywać.
 *
 * @param array<string,mixed> $s
 * @return array<string,string>
 */
function evk_naglowki_lista(array $s, bool $https): array {
    $out = [];
    if (!empty($s['hdr_nosniff'])) $out['X-Content-Type-Options'] = 'nosniff';
    if (!empty($s['hdr_referrer'])) {
        $out['Referrer-Policy'] = in_array($s['hdr_referrer_wartosc'] ?? '', EVK_NAGLOWKI_REFERRER, true) ? (string) $s['hdr_referrer_wartosc'] : EVK_NAGLOWKI_REFERRER[0];
    }
    if (!empty($s['hdr_permissions'])) {
        $f = array_values(array_intersect(array_keys(EVK_NAGLOWKI_UPRAWNIENIA), (array) ($s['hdr_uprawnienia'] ?? [])));
        if ($f) $out['Permissions-Policy'] = implode(', ', array_map(static function ($x) { return $x . '=()'; }, $f));
    }
    if (!empty($s['hdr_ramki'])) {
        $out['Content-Security-Policy'] = "frame-ancestors 'self'";
        $out['X-Frame-Options'] = 'SAMEORIGIN';
    }
    if (!empty($s['hdr_hsts']) && $https) {
        $wiek = isset(EVK_NAGLOWKI_HSTS[(int) ($s['hdr_hsts_wiek'] ?? 0)]) ? (int) $s['hdr_hsts_wiek'] : 300;
        $out['Strict-Transport-Security'] = 'max-age=' . $wiek . (!empty($s['hdr_hsts_sub']) ? '; includeSubDomains' : '');
    }
    return $out;
}

function evk_naglowki_wyslij(): void {
    if (headers_sent() || !function_exists('evk_security_get')) return;
    foreach (evk_naglowki_lista(evk_security_get(), is_ssl()) as $n => $w) header($n . ': ' . $w);
}
/* Strona (także REST), panel i logowanie. */
add_action('send_headers', 'evk_naglowki_wyslij');
add_action('admin_init', 'evk_naglowki_wyslij');
add_action('login_init', 'evk_naglowki_wyslij');
