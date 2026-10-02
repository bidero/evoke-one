<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Statystyki (1.283.0) — prawdziwy WordPress i prawdziwe tabele; beacony
 * i raport test wysyła przez serwer (php -S) i Chromium.
 *
 *   php tests/php/statystyki.php wp
 *   php tests/php/statystyki.php wylacz                  moduł wyłączony (odłożony stary stan)
 *   php tests/php/statystyki.php przygotuj               moduł włączony, puste tabele, dwie strony
 *   php tests/php/statystyki.php ustaw <json>            pola ustawień (scalane)
 *   php tests/php/statystyki.php odslony                 surowe odsłony
 *   php tests/php/statystyki.php dane <wymiar> <od> <do> [dni]   evk_stat_dane()
 *   php tests/php/statystyki.php postarz <dni>           przesuwa surowe odsłony w przeszłość
 *   php tests/php/statystyki.php zbiorka                 evk_stat_zbiorka() + stan
 *   php tests/php/statystyki.php zasiej <ua> <ile>       odsłony wizyty 127.0.0.1 + ua (limit dzienny)
 *   php tests/php/statystyki.php funkcje                 źródło, przeglądarka, urządzenie, bot
 *   php tests/php/statystyki.php uprawnienia             kto czyta raporty
 *   php tests/php/statystyki.php stan                    ustawienia, tabele, cron
 *   php tests/php/statystyki.php strona-zdarzen            strona z linkami tel/mailto/PDF/wychodzącym, formularzem i data-evk-zdarzenie
 *   php tests/php/statystyki.php zdarzenia                 zapisane zdarzenia (rodzaj, etykieta)
 *   php tests/php/statystyki.php cele <json>               AJAX zapisu celów (1.285.0)
 *   php tests/php/statystyki.php dbip <sekundy> [adres]   jeden krok importu bazy krajów (adres — podmiana pliku DB-IP)
 *   php tests/php/statystyki.php kraj <ip…>                kraje adresów
 *   php tests/php/statystyki.php usun <json> [autor]     AJAX kasowania za okres (1.285.0), jako admin albo autor
 *   php tests/php/statystyki.php wstaw <json>              {odslony:[{pola}], zdarzenia:[{pola}]} — dziś, wizyta i klucz losowe, jeśli brak
 *   php tests/php/statystyki.php csv <od> <do>             evk_stat_csv() (1.285.0)
 *   php tests/php/statystyki.php csv-pobierz <login>       admin_post_evk_stat_csv jako <login> (z ważnym nonce) — odmowa albo początek pliku
 *   php tests/php/statystyki.php czytelnicy [usun]         konta HTTP: statyk_csv (uprawnienie „Statystyki”) i statyk_csv_bez, hasło „test-haslo”
 *   php tests/php/statystyki.php sprzataj
 */
require __DIR__ . '/_testowy-wp.php';

$krok = $argv[1] ?? '';
$plik = sys_get_temp_dir() . '/evk-t-statystyki.json';
$out  = ['krok' => $krok];
$zap  = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);
global $wpdb;

$odloz = static function () use ($plik, &$zap): void {
    if (isset($zap['opcje'])) return;
    foreach (['evk_statystyki', 'evk_stat_db_version', 'evk_stat_sol', 'evk_stat_zebrane', 'evk_stat_cele', 'evk_stat_dbip'] as $o) $zap['opcje'][$o] = get_option($o, null);
    global $wpdb;
    $zap['tabele'] = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'evk_stat_odslony'));
    file_put_contents($plik, (string) wp_json_encode($zap));
};
$tabele = static function (): bool {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->prefix . 'evk_stat_odslony'));
};

switch ($krok) {
case 'wp':
    $out['wp'] = rtrim(ABSPATH, '/');
    break;

case 'wylacz':
    $odloz();
    update_option('evk_statystyki', ['enabled' => 0] + (array) get_option('evk_statystyki', []));
    $out['ok'] = true;
    break;

case 'przygotuj':
    $odloz();
    delete_option('evk_stat_zebrane');
    delete_option('evk_stat_db_version');
    /* Przez przełącznik tak jak panel: zapis opcji z `enabled` tworzy tabele. */
    delete_option('evk_statystyki');
    add_option('evk_statystyki', ['enabled' => 1]);
    $out['tabele_po_wlaczeniu'] = $tabele();
    if ($out['tabele_po_wlaczeniu']) {
        $wpdb->query('TRUNCATE ' . evk_stat_tabela('odslony'));
        $wpdb->query('TRUNCATE ' . evk_stat_tabela('dni'));
        $wpdb->query('TRUNCATE ' . evk_stat_tabela('zdarzenia'));
    }
    foreach (['Strona statystyk A', 'Strona statystyk B'] as $i => $t) {
        foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", $t)) as $stary) wp_delete_post((int) $stary, true);
        $id = (int) wp_insert_post(['post_title' => $t, 'post_type' => 'page', 'post_status' => 'publish',
            'post_content' => str_repeat('<p>' . $t . ' — akapit do przewijania.</p>', 120)]);
        $zap['strony'][$i] = $id;
    }
    file_put_contents($plik, (string) wp_json_encode($zap));
    $out['strony'] = $zap['strony'];
    break;

case 'ustaw':
    $nowe = json_decode((string) ($argv[2] ?? '{}'), true) ?: [];
    update_option('evk_statystyki', array_merge(evk_stat_ustawienia(), $nowe));
    $out['ust'] = evk_stat_ustawienia();
    break;

case 'odslony':
    $out['wiersze'] = $tabele() ? $wpdb->get_results('SELECT * FROM ' . evk_stat_tabela('odslony') . ' ORDER BY id', ARRAY_A) : null;
    break;

case 'dane':
    $out['dane'] = evk_stat_dane((string) ($argv[2] ?? 'razem'), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''), ($argv[5] ?? '') === 'dni');
    break;

case 'postarz':
    $n = (int) ($argv[2] ?? 1);
    foreach (['odslony', 'zdarzenia'] as $t) {
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', evk_stat_tabela($t)))) {
            $wpdb->query($wpdb->prepare('UPDATE ' . evk_stat_tabela($t) . ' SET dzien = DATE_SUB(dzien, INTERVAL %d DAY), czas = DATE_SUB(czas, INTERVAL %d DAY)', $n, $n));
        }
    }
    $out['ok'] = true;
    break;

case 'zbiorka':
    /* `od-nowa`: zapomina ostatni zebrany dzień — dla danych przesuniętych sondą w dni już zamknięte. */
    if (($argv[2] ?? '') === 'od-nowa') delete_option('evk_stat_zebrane');
    $out['zebrane_dni'] = evk_stat_zbiorka();
    $out['zebrane_do'] = evk_stat_zebrane_do();
    $out['wczoraj'] = wp_date('Y-m-d', time() - DAY_IN_SECONDS);
    $out['surowe'] = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . evk_stat_tabela('odslony'));
    $out['dni'] = $wpdb->get_results('SELECT * FROM ' . evk_stat_tabela('dni') . " WHERE wymiar IN ('razem','strona','zrodlo') ORDER BY dzien, wymiar, wartosc", ARRAY_A);
    break;

case 'zasiej':
    $wiz = evk_stat_wizyta('127.0.0.1', (string) ($argv[2] ?? ''));
    for ($i = 0; $i < (int) ($argv[3] ?? 0); $i++) {
        $wpdb->insert(evk_stat_tabela('odslony'), ['czas' => gmdate('Y-m-d H:i:s'), 'dzien' => wp_date('Y-m-d'), 'klucz' => bin2hex(random_bytes(8)), 'wizyta' => $wiz, 'sciezka' => '/zasiane']);
    }
    $out['wizyta'] = $wiz;
    break;

case 'funkcje':
    $out['zrodla'] = array_map('evk_stat_zrodlo', ['', 'https://www.google.com/search?q=x', 'https://Facebook.com/', home_url('/inna/'), 'android-app://com.slack/', 'nie-adres']);
    $out['przegladarki'] = array_map('evk_stat_przegladarka', [
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36 Edg/130.0',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.5; rv:131.0) Gecko/20100101 Firefox/131.0',
        'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/26.0 Chrome/122.0 Mobile Safari/537.36',
    ]);
    $out['urzadzenia'] = array_map('evk_stat_urzadzenie', [0, 390, 767, 768, 1099, 1100, 1920]);
    $out['boty'] = array_map('evk_stat_bot', ['', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'curl/8.5',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/130.0 Safari/537.36',
        'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36']);
    $out['ip'] = [evk_stat_ip_wykluczony('10.1.2.3', "10.0.0.0/8"), evk_stat_ip_wykluczony('10.1.2.3', "10.1.2.4\n192.168.0.0/16"),
                  evk_stat_ip_wykluczony('2001:db8::5', '2001:db8::/32'), evk_stat_ip_wykluczony('', '10.0.0.0/8')];
    $out['sanitize'] = evk_stat_sanitize(['retencja' => '45', 'dnt' => '', 'wyklucz_role' => '1', 'wyklucz_ip' => "10.0.0.1\nzle\n10.0.0.0/0, 2001:db8::/32", 'menu' => 'zle']);
    break;

case 'uprawnienia':
    foreach (['statyk_bez' => false, 'statyk_z' => true] as $login => $cap) {
        if ($u = get_user_by('login', $login)) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u->ID); }
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(), 'role' => 'author', 'user_email' => $login . '@example.test']);
        if ($cap) (new WP_User($id))->add_cap('evk_access_stats');
        wp_set_current_user($id);
        $out[$login] = evk_stat_moze_czytac();
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($id);
    }
    wp_set_current_user((int) get_user_by('login', 'admin')->ID);
    $out['admin'] = evk_stat_moze_czytac();
    break;

case 'strona-zdarzen':
    foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_title = 'Strona zdarzeń'") as $stary) wp_delete_post((int) $stary, true);
    $id = (int) wp_insert_post(['post_title' => 'Strona zdarzeń', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' =>
        '<p><a id="z-tel" href="tel:+48 123 456 789">Zadzwoń</a> <a id="z-mail" href="mailto:Biuro@Example.com?subject=Pytanie">Napisz</a> '
        . '<a id="z-pdf" href="/wp-content/uploads/cennik.pdf">Cennik</a> <a id="z-out" href="https://www.example.org/oferta">Partner</a> '
        . '<a id="z-wew" href="/?page_id=1">Wewnętrzny</a> <button id="z-wl" type="button" data-evk-zdarzenie="zapis">Zapisz się</button></p>'
        . '<form id="kontakt" action="#"><input name="x" aria-label="x"><button id="z-form" type="submit">Wyślij</button></form>']);
    $zap['strony'][] = $id;
    file_put_contents($plik, (string) wp_json_encode($zap));
    $out['id'] = $id;
    break;

case 'zdarzenia':
    $out['wiersze'] = $wpdb->get_results('SELECT rodzaj, etykieta, wizyta FROM ' . evk_stat_tabela('zdarzenia') . ' ORDER BY id', ARRAY_A);
    break;

case 'cele':
    $_POST = $_REQUEST = wp_slash(['action' => 'evk_stat_cele', 'nonce' => wp_create_nonce('evk_stat'), 'cele' => (string) ($argv[2] ?? '[]')]);
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    add_filter('wp_die_ajax_handler', static function () {
        return static function ($k = '') { if (is_scalar($k)) echo $k; throw new RuntimeException('koniec'); };
    });
    ob_start();
    try { do_action('wp_ajax_evk_stat_cele'); } catch (RuntimeException $e) { /* koniec */ }
    $out['odp'] = json_decode((string) ob_get_clean(), true);
    $out['cele'] = evk_stat_cele();
    break;

case 'dbip':
    if (!empty($argv[3])) { $adr = (string) $argv[3]; add_filter('evk_stat_dbip_adres', static function () use ($adr) { return $adr; }); }
    /* Plik testowy ma 30 tys. zakresów — próg „uszkodzonej bazy” w teście niżej niż 100 tys. */
    add_filter('evk_stat_dbip_minimum', static function () { return (int) (getenv('EVK_TEST_DBIP_MIN') ?: 1000); });
    if (!empty(getenv('EVK_TEST_DBIP_PORCJA'))) add_filter('evk_stat_dbip_porcja', static function () { return (int) getenv('EVK_TEST_DBIP_PORCJA'); });
    $t0 = microtime(true);
    $out['wynik'] = evk_stat_dbip_krok((float) ($argv[2] ?? 8));
    $out['s'] = round(microtime(true) - $t0, 2);
    $out['stan'] = evk_stat_dbip_stan();
    break;

case 'dbip-wersja':
    $st = evk_stat_dbip_stan();
    $st['wersja'] = (string) ($argv[2] ?? '');
    update_option('evk_stat_dbip', $st, false);
    $out['stan'] = $st;
    break;

case 'kraj':
    $out['kraje'] = [];
    foreach (array_slice($argv, 2) as $ip) $out['kraje'][$ip] = evk_stat_kraj((string) $ip);
    break;

case 'usun':
    if (($argv[3] ?? '') === 'autor') {
        if (!($u = get_user_by('login', 'statyk_autor'))) {
            $u = get_user_by('id', wp_insert_user(['user_login' => 'statyk_autor', 'user_pass' => wp_generate_password(), 'role' => 'author', 'user_email' => 'statyk_autor@example.test']));
        }
        $u->add_cap('evk_access_stats');
        wp_set_current_user($u->ID);
    }
    $_POST = $_REQUEST = wp_slash(['action' => 'evk_stat_usun', 'nonce' => wp_create_nonce('evk_stat')] + (json_decode((string) ($argv[2] ?? '{}'), true) ?: []));
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    add_filter('wp_die_ajax_handler', static function () {
        return static function ($k = '') { if (is_scalar($k)) echo $k; throw new RuntimeException('koniec'); };
    });
    ob_start();
    try { do_action('wp_ajax_evk_stat_usun'); } catch (RuntimeException $e) { /* koniec */ }
    $out['odp'] = json_decode((string) ob_get_clean(), true);
    if (($argv[3] ?? '') === 'autor') { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user((int) get_user_by('login', 'statyk_autor')->ID); }
    $out['dni'] = $wpdb->get_col('SELECT DISTINCT dzien FROM ' . evk_stat_tabela('dni') . ' ORDER BY dzien');
    $out['surowe_dni'] = $wpdb->get_col('SELECT DISTINCT dzien FROM ' . evk_stat_tabela('odslony') . ' ORDER BY dzien');
    break;

case 'wstaw':
    $wej = json_decode((string) ($argv[2] ?? '{}'), true) ?: [];
    foreach (['odslony', 'zdarzenia'] as $t) {
        foreach ((array) ($wej[$t] ?? []) as $w) {
            $wpdb->insert(evk_stat_tabela($t), array_merge(['czas' => gmdate('Y-m-d H:i:s'), 'dzien' => wp_date('Y-m-d'), 'klucz' => bin2hex(random_bytes(8)),
                'wizyta' => bin2hex(random_bytes(8))], $t === 'zdarzenia' ? ['rodzaj' => 'wlasne'] : [], (array) $w));
            $out['bledy'][] = $wpdb->last_error;
        }
    }
    break;

case 'csv':
    $out['csv'] = evk_stat_csv((string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''));
    break;

case 'csv-pobierz':
    wp_set_current_user((int) get_user_by('login', (string) ($argv[2] ?? 'admin'))->ID);
    $_GET = $_REQUEST = ['action' => 'evk_stat_csv', 'okres' => '7', '_wpnonce' => wp_create_nonce('evk_stat_csv')];
    add_filter('wp_die_handler', static function () {
        return static function ($m = '', $t = '', $a = []) { echo 'ODMOWA ' . (is_array($a) ? ($a['response'] ?? '') : '') . ' ' . (is_string($m) ? $m : ''); throw new RuntimeException('koniec'); };
    });
    ob_start();
    try { do_action('admin_post_evk_stat_csv'); } catch (RuntimeException $e) { /* koniec */ }
    $out['odp'] = substr((string) ob_get_clean(), 0, 80);
    break;

case 'czytelnicy':
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach (['statyk_csv' => true, 'statyk_csv_bez' => false] as $login => $cap) {
        if ($u = get_user_by('login', $login)) wp_delete_user($u->ID);
        if (($argv[2] ?? '') === 'usun') continue;
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => 'test-haslo', 'role' => 'author', 'user_email' => $login . '@example.test']);
        if ($cap) (new WP_User($id))->add_cap('evk_access_stats');
        $out[$login] = $id;
    }
    break;

case 'stan':
    $out['ust'] = get_option('evk_statystyki', null);
    $out['tabele'] = $tabele();
    $out['cron'] = (bool) wp_next_scheduled('evk_stat_dobowy');
    $out['dzis'] = wp_date('Y-m-d');
    break;

case 'sprzataj':
    foreach ((array) ($zap['strony'] ?? []) as $id) wp_delete_post((int) $id, true);
    if (empty($zap['tabele']) && $tabele()) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'evk_stat_odslony, ' . $wpdb->prefix . 'evk_stat_dni, ' . $wpdb->prefix . 'evk_stat_zdarzenia');
    }
    foreach ((array) ($zap['opcje'] ?? []) as $o => $v) {
        if ($v === null) delete_option($o); else update_option($o, $v);
    }
    wp_clear_scheduled_hook('evk_stat_dobowy');
    wp_clear_scheduled_hook('evk_stat_dbip');
    /* Baza krajów (1.285.0): bez bazy przed testem — bez niej po teście. */
    if (array_key_exists('evk_stat_dbip', (array) ($zap['opcje'] ?? [])) && $zap['opcje']['evk_stat_dbip'] === null) {
        $wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'evk_stat_kraje, ' . $wpdb->prefix . 'evk_stat_kraje_nowe');
    }
    $u = wp_upload_dir(null, false);
    foreach (['/evk-statystyki/dbip.csv', '/evk-statystyki/dbip.csv.gz', '/evk-test-dbip.csv.gz'] as $pl) @unlink($u['basedir'] . $pl);
    @unlink($plik);
    $out['ok'] = true;
    break;
}
echo wp_json_encode($out);
