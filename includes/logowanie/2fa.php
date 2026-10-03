<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — logowanie dwuetapowe (2FA), rdzeń (1.287.0).
 *
 * TOTP (RFC 6238: 30 s, 6 cyfr, SHA-1 — tak liczą Google Authenticator,
 * Microsoft Authenticator, 1Password, Bitwarden) i 10 jednorazowych kodów
 * zapasowych. Decyzje zgłaszającego (02–03.10, docs/plan-kolejka.md):
 * 2FA dobrowolne, administrator wymusza je dla wybranych ról; „zapamiętaj to
 * urządzenie” na 30 dni, domyślnie odznaczone; hasła aplikacji (MCP z Claude
 * Desktop) działają jak dotąd, a zwykłe hasło konta z 2FA przez XML-RPC
 * i REST jest odrzucane; odzyskanie — reset przez innego administratora albo
 * awaryjna stała EVK_2FA_WYLACZ w wp-config.php.
 *
 * Sekret leży zaszyfrowany (sodium secretbox, klucz z soli WordPressa) —
 * wyciek samej bazy nie daje kodów. Zmiana soli w wp-config.php unieważnia
 * sekrety; wtedy pomaga reset przez administratora albo stała awaryjna.
 * Kody zapasowe i zapamiętane urządzenia — tylko skróty.
 *
 * Drugi krok: hasło sprawdza WordPress, ten moduł zatrzymuje logowanie na
 * filtrze `authenticate` i prosi o kod (wp-login.php — osobny ekran,
 * formularz Bricksa — w tym samym miejscu, `2fa-logowanie.php`).
 */

const EVK_2FA_OPCJA = 'evk_2fa';
const EVK_2FA_META = 'evk_2fa';
const EVK_2FA_DZIENNIK = 'evk_2fa_dziennik';
const EVK_2FA_KROK = 30;
const EVK_2FA_KODY = 10;
const EVK_2FA_PROBY = 5;
const EVK_2FA_TOKEN_MIN = 10;
const EVK_2FA_URZADZENIE_DNI = 30;
const EVK_2FA_CIASTKO = 'evk_2fa_urz';
/** Kody zapasowe: bez liter i cyfr do pomylenia (0/o, 1/l/i). */
const EVK_2FA_ALFABET = 'abcdefghjkmnpqrstuvwxyz23456789';

/** @return array{enabled:int,role:list<string>,pamietaj:int} */
function evk_2fa_ustawienia(): array {
    $z = get_option(EVK_2FA_OPCJA, []);
    $z = is_array($z) ? $z : [];
    return ['enabled' => (int) !empty($z['enabled']), 'role' => array_values(array_map('strval', (array) ($z['role'] ?? []))), 'pamietaj' => (int) ($z['pamietaj'] ?? 1)];
}

/** Stała awaryjna w wp-config.php: wyłącza 2FA na całej stronie (zgubiony telefon jedynego administratora). */
function evk_2fa_awaryjnie(): bool {
    return defined('EVK_2FA_WYLACZ') && constant('EVK_2FA_WYLACZ');
}

function evk_2fa_wlaczone(): bool {
    return !empty(evk_2fa_ustawienia()['enabled']) && !evk_2fa_awaryjnie();
}

// =========================================================================
// TOTP I BASE32
// =========================================================================

function evk_2fa_base32(string $bajty): string {
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bity = '';
    foreach (str_split($bajty) as $b) $bity .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
    $out = '';
    foreach (str_split($bity, 5) as $k) $out .= $abc[bindec(str_pad($k, 5, '0'))];
    return $out;
}

function evk_2fa_base32_dekoduj(string $tekst): string {
    $abc = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $tekst = strtoupper((string) preg_replace('/[\s=-]/', '', $tekst));
    $bity = '';
    foreach (str_split($tekst) as $z) {
        $p = strpos($abc, $z);
        if ($p === false) return '';
        $bity .= str_pad(decbin($p), 5, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bity, 8) as $b) if (strlen($b) === 8) $out .= chr(bindec($b));
    return $out;
}

/** Nowy sekret: 160 bitów (jak w RFC 4226), base32 — 32 znaki. */
function evk_2fa_nowy_sekret(): string {
    return evk_2fa_base32(random_bytes(20));
}

/** Kod TOTP dla licznika (czas / 30 s). */
function evk_2fa_totp(string $sekret, int $licznik): string {
    $h = hash_hmac('sha1', pack('N2', ($licznik >> 32) & 0xffffffff, $licznik & 0xffffffff), evk_2fa_base32_dekoduj($sekret), true);
    $o = ord($h[19]) & 0x0f;
    $v = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($v % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Sprawdza kod z aplikacji: bieżące okno ±1 (zegar telefonu bywa przesunięty
 * o kilkanaście sekund). Kod z okna nie późniejszego niż ostatnio przyjęte
 * odpada — raz użyty kod nie wchodzi drugi raz. Zwraca licznik albo -1.
 */
function evk_2fa_sprawdz_totp(string $sekret, string $kod, int $ostatni, ?int $czas = null): int {
    $kod = (string) preg_replace('/\D/', '', $kod);
    if (strlen($kod) !== 6 || $sekret === '') return -1;
    $teraz = intdiv($czas ?? time(), EVK_2FA_KROK);
    foreach ([0, -1, 1] as $d) {
        $l = $teraz + $d;
        if ($l > $ostatni && hash_equals(evk_2fa_totp($sekret, $l), $kod)) return $l;
    }
    return -1;
}

/** Adres otpauth:// dla aplikacji (kod QR w profilu). */
function evk_2fa_adres_otpauth(string $sekret, WP_User $u): string {
    $wydawca = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES) ?: (string) wp_parse_url(home_url(), PHP_URL_HOST);
    return 'otpauth://totp/' . rawurlencode($wydawca . ':' . $u->user_login) . '?secret=' . $sekret . '&issuer=' . rawurlencode($wydawca) . '&algorithm=SHA1&digits=6&period=30';
}

// =========================================================================
// SZYFROWANIE SEKRETU
// =========================================================================

function evk_2fa_klucz(): string {
    return sodium_crypto_generichash('evk-2fa|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}

function evk_2fa_zaszyfruj(string $tekst): string {
    $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($n . sodium_crypto_secretbox($tekst, $n, evk_2fa_klucz()));
}

/** Pusty łańcuch, gdy się nie da (zmieniona sól, uszkodzony wpis). */
function evk_2fa_odszyfruj(string $b64): string {
    $s = base64_decode($b64, true);
    if ($s === false || strlen($s) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return '';
    $t = sodium_crypto_secretbox_open(substr($s, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($s, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), evk_2fa_klucz());
    return is_string($t) ? $t : '';
}

// =========================================================================
// STAN KONTA
// =========================================================================

/**
 * @return array{sekret:string,od:int,licznik:int,sol:string,kody:list<string>,urzadzenia:list<array<string,mixed>>,oczekujacy:string}
 */
function evk_2fa_konto(int $id): array {
    $k = get_user_meta($id, EVK_2FA_META, true);
    $k = is_array($k) ? $k : [];
    return ['sekret' => (string) ($k['sekret'] ?? ''), 'od' => (int) ($k['od'] ?? 0), 'licznik' => (int) ($k['licznik'] ?? 0), 'sol' => (string) ($k['sol'] ?? ''),
            'kody' => array_values(array_map('strval', (array) ($k['kody'] ?? []))), 'urzadzenia' => array_values((array) ($k['urzadzenia'] ?? [])),
            'oczekujacy' => (string) ($k['oczekujacy'] ?? '')];
}

/** @param array<string,mixed> $k */
function evk_2fa_zapisz_konto(int $id, array $k): void {
    update_user_meta($id, EVK_2FA_META, $k);
}

/** Czy konto ma włączone 2FA. */
function evk_2fa_ma(int $id): bool {
    $k = evk_2fa_konto($id);
    return $k['sekret'] !== '' && $k['od'] > 0;
}

/** Czy rola konta ma 2FA wymuszone. */
function evk_2fa_wymagane(WP_User $u): bool {
    return evk_2fa_wlaczone() && (bool) array_intersect($u->roles, evk_2fa_ustawienia()['role']);
}

function evk_2fa_wylacz(int $id): void {
    delete_user_meta($id, EVK_2FA_META);
}

/** Dziennik zdarzeń 2FA (włączenie, wyłączenie, reset przez administratora) — 100 ostatnich. */
function evk_2fa_dziennik(string $co, int $konto): void {
    $d = get_option(EVK_2FA_DZIENNIK, []);
    $d = is_array($d) ? $d : [];
    array_unshift($d, ['czas' => time(), 'co' => $co, 'konto' => $konto, 'kto' => get_current_user_id()]);
    update_option(EVK_2FA_DZIENNIK, array_slice($d, 0, 100), false);
}

// =========================================================================
// KODY ZAPASOWE
// =========================================================================

function evk_2fa_normuj_kod(string $kod): string {
    return strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $kod));
}

/** Nowe kody (stare przestają działać). Zwraca jawne kody — jedyny raz, kiedy istnieją poza głową właściciela. @return list<string> */
function evk_2fa_nowe_kody(int $id): array {
    $k = evk_2fa_konto($id);
    $k['sol'] = bin2hex(random_bytes(16));
    $jawne = [];
    $k['kody'] = [];
    for ($i = 0; $i < EVK_2FA_KODY; $i++) {
        $kod = '';
        for ($j = 0; $j < 8; $j++) $kod .= EVK_2FA_ALFABET[random_int(0, strlen(EVK_2FA_ALFABET) - 1)];
        $jawne[] = substr($kod, 0, 4) . '-' . substr($kod, 4);
        $k['kody'][] = hash('sha256', $k['sol'] . $kod);
    }
    evk_2fa_zapisz_konto($id, $k);
    return $jawne;
}

function evk_2fa_uzyj_kodu_zapasowego(int $id, string $kod): bool {
    $k = evk_2fa_konto($id);
    $kod = evk_2fa_normuj_kod($kod);
    if (strlen($kod) !== 8 || $k['sol'] === '') return false;
    $h = hash('sha256', $k['sol'] . $kod);
    foreach ($k['kody'] as $i => $z) {
        if (hash_equals($z, $h)) {
            unset($k['kody'][$i]);
            $k['kody'] = array_values($k['kody']);
            evk_2fa_zapisz_konto($id, $k);
            return true;
        }
    }
    return false;
}

/**
 * Kod przy logowaniu: z aplikacji (6 cyfr) albo zapasowy (8 znaków).
 * Zwraca rodzaj albo '' (zły kod).
 */
function evk_2fa_weryfikuj(int $id, string $kod): string {
    $k = evk_2fa_konto($id);
    $l = evk_2fa_sprawdz_totp(evk_2fa_odszyfruj($k['sekret']), $kod, $k['licznik']);
    if ($l >= 0) {
        $k['licznik'] = $l;
        evk_2fa_zapisz_konto($id, $k);
        return 'totp';
    }
    return evk_2fa_uzyj_kodu_zapasowego($id, $kod) ? 'zapasowy' : '';
}

// =========================================================================
// ZAPAMIĘTANE URZĄDZENIA
// =========================================================================

/** Odcisk hasła: zmiana hasła unieważnia zapamiętane urządzenia. */
function evk_2fa_odcisk_hasla(int $id): string {
    $u = get_userdata($id);
    return $u ? substr(hash('sha256', $u->user_pass . wp_salt('auth')), 0, 16) : '';
}

function evk_2fa_nazwa_urzadzenia(string $ua): string {
    $p = function_exists('evk_stat_przegladarka') ? evk_stat_przegladarka($ua) : ['', ''];
    $n = trim(implode(', ', array_filter([(string) $p[0], (string) $p[1]])));
    return $n !== '' ? $n : 'Przeglądarka';
}

function evk_2fa_zapamietaj(int $id): void {
    $t = bin2hex(random_bytes(32));
    $k = evk_2fa_konto($id);
    $k['urzadzenia'] = array_values(array_filter($k['urzadzenia'], static function ($u) { return (int) ($u['do'] ?? 0) > time(); }));
    $k['urzadzenia'][] = ['h' => hash('sha256', $t), 'do' => time() + EVK_2FA_URZADZENIE_DNI * DAY_IN_SECONDS,
        'nazwa' => evk_2fa_nazwa_urzadzenia((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 'od' => time(), 'p' => evk_2fa_odcisk_hasla($id)];
    $k['urzadzenia'] = array_slice($k['urzadzenia'], -20);
    evk_2fa_zapisz_konto($id, $k);
    if (!headers_sent()) {
        setcookie(EVK_2FA_CIASTKO . '_' . $id, $t, ['expires' => time() + EVK_2FA_URZADZENIE_DNI * DAY_IN_SECONDS, 'path' => defined('COOKIEPATH') ? (string) constant('COOKIEPATH') : '/',
            'domain' => defined('COOKIE_DOMAIN') ? (string) constant('COOKIE_DOMAIN') : '', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax']);
    }
}

function evk_2fa_urzadzenie_zaufane(int $id): bool {
    if (empty(evk_2fa_ustawienia()['pamietaj'])) return false;
    $t = (string) ($_COOKIE[EVK_2FA_CIASTKO . '_' . $id] ?? '');
    if ($t === '') return false;
    $h = hash('sha256', $t);
    $p = evk_2fa_odcisk_hasla($id);
    foreach (evk_2fa_konto($id)['urzadzenia'] as $u) {
        if (hash_equals((string) ($u['h'] ?? ''), $h) && (int) ($u['do'] ?? 0) > time() && hash_equals((string) ($u['p'] ?? ''), $p)) return true;
    }
    return false;
}

function evk_2fa_zapomnij_urzadzenia(int $id): void {
    $k = evk_2fa_konto($id);
    $k['urzadzenia'] = [];
    evk_2fa_zapisz_konto($id, $k);
}

// =========================================================================
// TOKEN DRUGIEGO KROKU
// =========================================================================

/** @param array<string,mixed> $dane */
function evk_2fa_token_nowy(int $id, array $dane): string {
    $t = bin2hex(random_bytes(24));
    set_transient('evk_2fa_t_' . hash('sha256', $t), ['id' => $id, 'proby' => 0] + $dane, EVK_2FA_TOKEN_MIN * MINUTE_IN_SECONDS);
    return $t;
}

/** @return array<string,mixed>|null */
function evk_2fa_token(string $t): ?array {
    if (!preg_match('/^[0-9a-f]{48}$/', $t)) return null;
    $d = get_transient('evk_2fa_t_' . hash('sha256', $t));
    return is_array($d) && (int) ($d['proby'] ?? 0) < EVK_2FA_PROBY ? $d : null;
}

/** Nieudana próba — zwraca, ile zostało. */
function evk_2fa_token_proba(string $t): int {
    $d = evk_2fa_token($t);
    if (!$d) return 0;
    $d['proby'] = (int) $d['proby'] + 1;
    set_transient('evk_2fa_t_' . hash('sha256', $t), $d, EVK_2FA_TOKEN_MIN * MINUTE_IN_SECONDS);
    return max(0, EVK_2FA_PROBY - $d['proby']);
}

function evk_2fa_token_usun(string $t): void {
    delete_transient('evk_2fa_t_' . hash('sha256', $t));
}

// =========================================================================
// LOGOWANIE: FILTR authenticate
// =========================================================================

/** Czy to logowanie formularzem Bricksa (AJAX). */
function evk_2fa_z_bricksa(): bool {
    return wp_doing_ajax() && ($_POST['action'] ?? '') === 'bricks_form_submit';
}

/** Odpowiedź dla formularza Bricksa — kształt jak `set_result()` akcji logowania, plus dane drugiego kroku. @param array<string,mixed> $evk */
function evk_2fa_odpowiedz_bricks(string $komunikat, array $evk): void {
    wp_send_json(['success' => false, 'data' => ['action' => 'login', 'type' => 'error', 'message' => esc_html($komunikat), 'evk2fa' => $evk]]);
}

/**
 * Po sprawdzeniu hasła (priorytet 90 — po rdzeniu i po limicie logowań):
 * konto z 2FA bez zaufanego urządzenia musi podać kod. Pierwsze podejście
 * dostaje token drugiego kroku; drugie niesie token i kod.
 */
add_filter('authenticate', function ($user, $login = '', $haslo = '') {
    if (!($user instanceof WP_User) || !evk_2fa_wlaczone() || !evk_2fa_ma($user->ID)) return $user;
    /* Hasło aplikacji (Claude Desktop, MCP) — osobny, odwoływalny klucz tylko do API. Przez ten filtr
       przechodzi w XML-RPC; w REST rdzeń sprawdza je poza nim. Zwykłe hasło w API kończy się niżej
       błędem „zaloguj się na stronie logowania” — kodu tam nie ma gdzie podać. */
    if (did_action('application_password_did_authenticate')) return $user;
    if (evk_2fa_urzadzenie_zaufane($user->ID)) return $user;
    $bricks = evk_2fa_z_bricksa();
    $token = sanitize_key((string) ($_POST['evk_2fa_token'] ?? ''));
    if ($token !== '') {
        $t = evk_2fa_token($token);
        if (!$t || (int) $t['id'] !== $user->ID) {
            if ($bricks) evk_2fa_odpowiedz_bricks('Weryfikacja wygasła. Zaloguj się jeszcze raz.', ['wygasl' => true]);
            return new WP_Error('evk_2fa_wygasl', 'Weryfikacja wygasła. Zaloguj się jeszcze raz.');
        }
        $rodzaj = evk_2fa_weryfikuj($user->ID, (string) wp_unslash($_POST['evk_2fa_kod'] ?? ''));
        if ($rodzaj === '') {
            $zostalo = evk_2fa_token_proba($token);
            do_action('wp_login_failed', $user->user_login, new WP_Error('evk_2fa_kod', 'Zły kod 2FA'));
            $kom = $zostalo ? sprintf('Nieprawidłowy kod. Zostało prób: %d.', $zostalo) : 'Nieprawidłowy kod. Zaloguj się jeszcze raz.';
            if ($bricks) evk_2fa_odpowiedz_bricks($kom, $zostalo ? ['token' => $token, 'blad' => true] : ['wygasl' => true]);
            return new WP_Error('evk_2fa_kod', $kom);
        }
        evk_2fa_token_usun($token);
        if (!empty($_POST['evk_2fa_pamietaj']) && !empty(evk_2fa_ustawienia()['pamietaj'])) evk_2fa_zapamietaj($user->ID);
        return $user;
    }
    $nowy = evk_2fa_token_nowy($user->ID, ['pamietaj_mnie' => !empty($_POST['rememberme']), 'cel' => esc_url_raw((string) wp_unslash($_REQUEST['redirect_to'] ?? ''))]);
    if ($bricks) evk_2fa_odpowiedz_bricks('Wpisz kod z aplikacji uwierzytelniającej.', ['token' => $nowy, 'pamietaj' => (bool) evk_2fa_ustawienia()['pamietaj']]);
    if (($GLOBALS['pagenow'] ?? '') === 'wp-login.php' && function_exists('evk_2fa_ekran_kodu')) {
        evk_2fa_ekran_kodu($user, $nowy, '');
        exit;
    }
    return new WP_Error('evk_2fa_wymagany', 'To konto ma logowanie dwuetapowe — zaloguj się na stronie logowania.');
}, 90, 3);

// =========================================================================
// WYMUSZENIE DLA RÓL
// =========================================================================

/** Konto z wymuszonym 2FA, które go jeszcze nie ma — wszędzie poza Profilem odsyłane na Profil. */
function evk_2fa_musi_wlaczyc(): bool {
    if (!is_user_logged_in()) return false;
    $u = wp_get_current_user();
    return evk_2fa_wymagane($u) && !evk_2fa_ma($u->ID);
}

add_action('admin_init', function (): void {
    if (wp_doing_ajax() || !evk_2fa_musi_wlaczyc()) return;
    global $pagenow;
    if (in_array($pagenow, ['profile.php', 'admin-post.php'], true)) return;
    wp_safe_redirect(admin_url('profile.php?evk_2fa=wymagane#evk-2fa'));
    exit;
});

add_action('template_redirect', function (): void {
    if (!is_admin_bar_showing() || !evk_2fa_musi_wlaczyc()) return;
    wp_safe_redirect(admin_url('profile.php?evk_2fa=wymagane#evk-2fa'));
    exit;
});

/* Zapis z zakładki „Logowanie” (AJAX) — role z wymuszeniem i zapamiętywanie; włącznik ma własny zapis. */
add_action('wp_ajax_evk_2fa_ustawienia', function (): void {
    check_ajax_referer('evk_2fa_ustawienia', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.');
    $u = evk_2fa_ustawienia();
    $role = array_map('sanitize_key', (array) wp_unslash($_POST['role'] ?? []));
    $u['role'] = array_values(array_intersect($role, array_keys(wp_roles()->get_names())));
    $u['pamietaj'] = !empty($_POST['pamietaj']) ? 1 : 0;
    update_option(EVK_2FA_OPCJA, $u);
    wp_send_json_success($u);
});
