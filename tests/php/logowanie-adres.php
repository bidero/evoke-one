<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Ukryty adres logowania (1.288.0) — prawdziwy WordPress; żądania test
 * wysyła przez serwer (php -S) i Chromium.
 *
 *   php tests/php/logowanie-adres.php wp
 *   php tests/php/logowanie-adres.php ustaw <json>          opcja evk_ukryty_adres (zastępowana)
 *   php tests/php/logowanie-adres.php stan                   opcja i czy działa
 *   php tests/php/logowanie-adres.php sprawdz <json-lista>   evk_ua_sprawdz_adres() dla każdego adresu
 *   php tests/php/logowanie-adres.php ciastko <termin> <adres>  wartość ciasteczka-klucza podpisana jak przez wtyczkę
 *   php tests/php/logowanie-adres.php reset <login>          klucz resetu hasła (jak w e-mailu)
 *   php tests/php/logowanie-adres.php prosba                 prośba o eksport danych + klucz potwierdzenia
 *   php tests/php/logowanie-adres.php chroniona              wpis chroniony hasłem „test-haslo”
 *   php tests/php/logowanie-adres.php linki <0|1>            strony logowania „Bricksa” w opcji bricks_global_settings + linki WordPressa
 *   php tests/php/logowanie-adres.php odzyskiwanie            link trybu odzyskiwania (jak w e-mailu o błędzie krytycznym)
 *   php tests/php/logowanie-adres.php awaryjnie <0|1>        mu-plugin ze stałą EVK_UKRYTY_ADRES_WYLACZ
 *   php tests/php/logowanie-adres.php sprzataj
 */
require __DIR__ . '/_testowy-wp.php';

$krok = $argv[1] ?? '';
$plik = sys_get_temp_dir() . '/evk-t-ukryty-adres.json';
$out  = ['krok' => $krok];
$zap  = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
$mu   = WP_CONTENT_DIR . '/mu-plugins/evk-test-ua-wylacz.php';
/** Zapamiętaj stan opcji przed pierwszą zmianą — sprzątanie go przywraca. */
$zachowaj = function (string $o) use (&$zap, $plik): void {
    if (array_key_exists($o, $zap['opcje'] ?? [])) return;
    $zap['opcje'][$o] = get_option($o, null);
    file_put_contents($plik, (string) wp_json_encode($zap));
};
$zapamietaj = function (string $k, $v) use (&$zap, $plik): void {
    $zap[$k][] = $v;
    file_put_contents($plik, (string) wp_json_encode($zap));
};

switch ($krok) {
case 'wp':
    $out['wp'] = rtrim(ABSPATH, '/');
    break;

case 'ustaw':
    $zachowaj('evk_ukryty_adres');
    update_option('evk_ukryty_adres', json_decode((string) ($argv[2] ?? '{}'), true) ?: [], false);
    $out['ust'] = evk_ua_ustawienia();
    $out['wlaczony'] = evk_ua_wlaczony();
    break;

case 'stan':
    $out['ust'] = evk_ua_ustawienia();
    $out['wlaczony'] = evk_ua_wlaczony();
    break;

case 'sprawdz':
    foreach ((array) json_decode((string) ($argv[2] ?? '[]'), true) as $a) $out['wynik'][(string) $a] = evk_ua_sprawdz_adres((string) $a);
    $out['losowy'] = evk_ua_losuj();
    $out['losowy_dobry'] = evk_ua_sprawdz_adres($out['losowy']) === '';
    break;

case 'ciastko':
    $t = (int) ($argv[2] ?? 0);
    $out['wartosc'] = $t . '|' . evk_ua_podpis($t, (string) ($argv[3] ?? ''));
    break;

case 'reset':
    $login = (string) ($argv[2] ?? '');
    if (!get_user_by('login', $login)) {
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => 'test-haslo', 'role' => 'subscriber', 'user_email' => $login . '@example.test']);
        $zapamietaj('konta', $login);
    }
    $u = get_user_by('login', $login);
    $out['klucz'] = get_password_reset_key($u);
    $out['login'] = $login;
    break;

case 'prosba':
    $id = wp_create_user_request('ua-prosba@example.test', 'export_personal_data');
    if (is_wp_error($id)) {
        $p = get_posts(['post_type' => 'user_request', 'post_status' => 'any', 'title' => 'ua-prosba@example.test', 'fields' => 'ids']);
        $id = (int) ($p[0] ?? 0);
    } else {
        $zapamietaj('posty', (int) $id);
    }
    $out['id'] = (int) $id;
    $out['klucz'] = wp_generate_user_request_key((int) $id);
    break;

case 'chroniona':
    $id = wp_insert_post(['post_title' => 'UA chroniony', 'post_status' => 'publish', 'post_password' => 'test-haslo', 'post_content' => 'Treść za hasłem UA.']);
    $zapamietaj('posty', (int) $id);
    $out['id'] = (int) $id;
    $out['url'] = get_permalink((int) $id);
    break;

case 'linki':
    $zachowaj('bricks_global_settings');
    if (($argv[2] ?? '') === '1') {
        $ids = [];
        foreach (['login_page' => 'UA logowanie', 'lost_password_page' => 'UA hasło', 'registration_page' => 'UA rejestracja'] as $k => $tytul) {
            $ids[$k] = wp_insert_post(['post_title' => $tytul, 'post_type' => 'page', 'post_status' => 'publish']);
            $zapamietaj('posty', (int) $ids[$k]);
        }
        $g = get_option('bricks_global_settings', []);
        update_option('bricks_global_settings', array_merge(is_array($g) ? $g : [], $ids));
        foreach ($ids as $k => $id) $out['strony'][$k] = get_permalink((int) $id);
    }
    $out['login'] = wp_login_url('http://cel.test/x');
    $out['haslo'] = wp_lostpassword_url();
    $out['rejestracja'] = wp_registration_url();
    break;

case 'odzyskiwanie':
    /* Link z e-maila „Twoja strona ma błąd krytyczny” — ta sama usługa kluczy, której używa WordPress. */
    $svc = new WP_Recovery_Mode_Link_Service(new WP_Recovery_Mode_Cookie_Service(), new WP_Recovery_Mode_Key_Service());
    $out['url'] = $svc->generate_url();
    $out['sciezka'] = (string) wp_parse_url($out['url'], PHP_URL_PATH) . '?' . (string) wp_parse_url($out['url'], PHP_URL_QUERY);
    break;

case 'awaryjnie':
    if (($argv[2] ?? '') === '1') {
        wp_mkdir_p(dirname($mu));
        file_put_contents($mu, "<?php\ndefine('EVK_UKRYTY_ADRES_WYLACZ', true);\n");
    } else {
        @unlink($mu);
    }
    $out['jest'] = is_file($mu);
    break;

case 'sprzataj':
    require_once ABSPATH . 'wp-admin/includes/user.php';
    @unlink($mu);
    foreach ($zap['opcje'] ?? [] as $o => $v) {
        if ($v === null) delete_option($o); else update_option($o, $v);
    }
    foreach ($zap['posty'] ?? [] as $id) wp_delete_post((int) $id, true);
    foreach ($zap['konta'] ?? [] as $l) if ($u = get_user_by('login', $l)) wp_delete_user($u->ID);
    @unlink($plik);
    $out['wlaczony'] = evk_ua_wlaczony();
    break;

default:
    $out['blad'] = 'nieznany krok';
}
echo wp_json_encode($out);
