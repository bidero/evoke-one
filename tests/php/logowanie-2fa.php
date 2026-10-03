<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Logowanie dwuetapowe (1.287.0) — prawdziwy WordPress; logowanie, profil
 * i zakładkę test przechodzi przez serwer (php -S) i Chromium.
 *
 *   php tests/php/logowanie-2fa.php wp
 *   php tests/php/logowanie-2fa.php przygotuj             2FA włączone, konta dwa_admin (administrator) i dwa_red (redaktor), hasło „test-haslo”
 *   php tests/php/logowanie-2fa.php ustaw <json>          ustawienia 2FA (scalane)
 *   php tests/php/logowanie-2fa.php konto <login>         stan konta: czy włączone, licznik, ile kodów, urządzenia, czy sekret jawny w bazie
 *   php tests/php/logowanie-2fa.php wlacz <login> <sekret>  włącza 2FA kontu ze znanym sekretem (bez profilu) + kody zapasowe
 *   php tests/php/logowanie-2fa.php totp <rfc>            funkcje: wektory RFC 6238, base32, okno i powtórka
 *   php tests/php/logowanie-2fa.php haslo-aplikacji <login>   nowe hasło aplikacji (REST)
 *   php tests/php/logowanie-2fa.php awaryjnie <0|1>       mu-plugin ze stałą EVK_2FA_WYLACZ
 *   php tests/php/logowanie-2fa.php haslo <login> <nowe>   zmiana hasła (unieważnia zapamiętane urządzenia)
 *   php tests/php/logowanie-2fa.php dziennik
 *   php tests/php/logowanie-2fa.php sprzataj
 */
require __DIR__ . '/_testowy-wp.php';

$krok = $argv[1] ?? '';
$plik = sys_get_temp_dir() . '/evk-t-2fa.json';
$out  = ['krok' => $krok];
$zap  = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
$mu   = WP_CONTENT_DIR . '/mu-plugins/evk-test-2fa-wylacz.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

switch ($krok) {
case 'wp':
    $out['wp'] = rtrim(ABSPATH, '/');
    break;

case 'przygotuj':
    if (!isset($zap['opcje'])) {
        foreach (['evk_2fa', 'evk_2fa_dziennik'] as $o) $zap['opcje'][$o] = get_option($o, null);
        file_put_contents($plik, (string) wp_json_encode($zap));
    }
    update_option('evk_2fa', ['enabled' => 1, 'role' => [], 'pamietaj' => 1]);
    delete_option('evk_2fa_dziennik');
    foreach (['dwa_admin' => 'administrator', 'dwa_red' => 'editor'] as $login => $rola) {
        if ($u = get_user_by('login', $login)) wp_delete_user($u->ID);
        $out['id'][$login] = wp_insert_user(['user_login' => $login, 'user_pass' => 'test-haslo', 'role' => $rola, 'user_email' => $login . '@example.test']);
    }
    delete_user_meta((int) get_user_by('login', 'admin')->ID, 'evk_2fa');
    @unlink($mu);
    break;

case 'ustaw':
    update_option('evk_2fa', array_merge(evk_2fa_ustawienia(), json_decode((string) ($argv[2] ?? '{}'), true) ?: []));
    $out['ust'] = evk_2fa_ustawienia();
    break;

case 'konto':
    $u = get_user_by('login', (string) ($argv[2] ?? ''));
    $k = $u ? evk_2fa_konto($u->ID) : [];
    $surowe = $u ? (string) wp_json_encode(get_user_meta($u->ID, 'evk_2fa', true)) : '';
    $out['konto'] = $u ? ['ma' => evk_2fa_ma($u->ID), 'licznik' => $k['licznik'], 'kody' => count($k['kody']), 'urzadzenia' => count($k['urzadzenia']),
        'oczekujacy' => $k['oczekujacy'] !== '', 'od' => $k['od'],
        /* Sekret ani kody nie leżą w bazie jawnie: jawny sekret w JSON-ie meta = błąd. */
        'sekret_jawny' => $k['sekret'] !== '' && (bool) preg_match('/"[A-Z2-7]{32}"/', $surowe)] : null;
    break;

case 'wlacz':
    $u = get_user_by('login', (string) ($argv[2] ?? ''));
    $k = evk_2fa_konto($u->ID);
    $k['sekret'] = evk_2fa_zaszyfruj((string) $argv[3]);
    $k['od'] = time();
    $k['licznik'] = 0;
    evk_2fa_zapisz_konto($u->ID, $k);
    $out['kody'] = evk_2fa_nowe_kody($u->ID);
    break;

case 'totp':
    $s = evk_2fa_base32('12345678901234567890');
    foreach ([59, 1111111109, 1111111111, 1234567890, 2000000000, 20000000000] as $t) $out['rfc'][(string) $t] = evk_2fa_totp($s, intdiv($t, 30));
    $out['base32'] = $s;
    $out['okno'] = [evk_2fa_sprawdz_totp($s, '287082', 0, 59), evk_2fa_sprawdz_totp($s, '287082', 1, 59), evk_2fa_sprawdz_totp($s, '287082', 0, 89), evk_2fa_sprawdz_totp($s, '287082', 0, 125)];
    $out['szyfr'] = ['tam_i_z_powrotem' => evk_2fa_odszyfruj(evk_2fa_zaszyfruj('ABC')) === 'ABC', 'zly' => evk_2fa_odszyfruj('zly') === ''];
    break;

case 'haslo-aplikacji':
    $u = get_user_by('login', (string) ($argv[2] ?? ''));
    $out['haslo'] = (string) WP_Application_Passwords::create_new_application_password($u->ID, ['name' => 'test 2FA'])[0];
    break;

case 'awaryjnie':
    if (($argv[2] ?? '') === '1') {
        wp_mkdir_p(dirname($mu));
        file_put_contents($mu, "<?php\ndefine('EVK_2FA_WYLACZ', true);\n");
    } else {
        @unlink($mu);
    }
    $out['jest'] = is_file($mu);
    break;

case 'haslo':
    $u = get_user_by('login', (string) ($argv[2] ?? ''));
    wp_set_password((string) ($argv[3] ?? ''), $u->ID);
    $out['ok'] = true;
    break;

case 'dziennik':
    $out['dziennik'] = array_map(static function ($w) { $a = get_userdata((int) $w['konto']); $b = get_userdata((int) $w['kto']);
        return [$w['co'], $a ? $a->user_login : '', $b ? $b->user_login : '']; }, (array) get_option('evk_2fa_dziennik', []));
    break;

case 'sprzataj':
    foreach (['dwa_admin', 'dwa_red'] as $login) if ($u = get_user_by('login', $login)) wp_delete_user($u->ID);
    foreach ((array) ($zap['opcje'] ?? []) as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v); }
    @unlink($mu);
    @unlink($plik);
    $out['ok'] = true;
    break;
}

echo wp_json_encode($out);
