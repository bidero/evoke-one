<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Blokowanie REST API (1.281.0) — prawdziwy WordPress; odpowiedzi REST test
 * czyta przez serwer (php -S).
 *
 *   php tests/php/rest-api.php wp
 *   php tests/php/rest-api.php domyslne          ustawienia REST bez zapisanych pól (odłożony stary stan); hasło aplikacji admina
 *   php tests/php/rest-api.php ajax <json>       zapis sekcji `rest` prawdziwym AJAX-em zakładki
 *   php tests/php/rest-api.php stan              zapisane pola REST + dostawcy mapy strony WordPressa
 *   php tests/php/rest-api.php trasy <json>      evk_rest_zablokowany() dla par [trasa, ustawienia]
 *   php tests/php/rest-api.php sprzataj
 */
define('WP_ENVIRONMENT_TYPE', 'local');
require __DIR__ . '/_testowy-wp.php';

$krok = $argv[1] ?? '';
$plik = sys_get_temp_dir() . '/evk-t-rest-api.json';
$out  = ['krok' => $krok];
if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

$rest = static function (): array {
    return array_intersect_key((array) get_option('evk_security', []), array_flip(['rest_block_all', 'disabled_rest_endpoints', 'rest_uzytkownicy', 'rest_wyjatki']));
};

switch ($krok) {
case 'wp':
    $out['wp'] = rtrim(ABSPATH, '/');
    break;

case 'domyslne':
    if (!is_file($plik)) file_put_contents($plik, (string) wp_json_encode(['evk_security' => get_option('evk_security', null), 'mapa' => get_option('tl_sitemap_settings', null)]));
    /* Moduł mapy strony sam wyklucza autorów (`include_users` = 0) — bez
       włączenia sprawdzenie przełącznika REST przechodziłoby na pusto. */
    update_option('tl_sitemap_settings', ['include_users' => 1] + (array) get_option('tl_sitemap_settings', []));
    $s = (array) get_option('evk_security', []);
    foreach (['rest_block_all', 'disabled_rest_endpoints', 'rest_uzytkownicy', 'rest_wyjatki'] as $k) unset($s[$k]);
    global $wpdb;
    /* Wprost do bazy — bez sanityzacji, która dopisałaby wartości domyślne. */
    if ($wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = 'evk_security'")) {
        $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($s)], ['option_name' => 'evk_security']);
    } else {
        $wpdb->insert($wpdb->options, ['option_name' => 'evk_security', 'option_value' => maybe_serialize($s), 'autoload' => 'yes']);
    }
    wp_cache_flush();
    $u = get_user_by('login', 'admin');
    WP_Application_Passwords::delete_all_application_passwords($u->ID);
    $out['haslo'] = (string) WP_Application_Passwords::create_new_application_password($u->ID, ['name' => 'test REST'])[0];
    $out['id'] = (int) $u->ID;
    $out['zapisane'] = $rest();
    break;

case 'trasy':
    $out['wyniki'] = [];
    foreach ((array) json_decode((string) ($argv[2] ?? '[]'), true) as $p) $out['wyniki'][] = evk_rest_zablokowany((string) $p[0], (array) $p[1]);
    break;

case 'ajax':
    $_POST = $_REQUEST = wp_slash(['action' => 'evk_save_security_section', 'nonce' => wp_create_nonce('evk_security_nonce'), 'section' => 'rest',
        'data' => json_decode((string) ($argv[2] ?? '{}'), true) ?: []]);
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    add_filter('wp_die_ajax_handler', static function () {
        return static function ($k = '') { if (is_scalar($k)) echo $k; throw new RuntimeException('koniec'); };
    });
    ob_start();
    try { do_action('wp_ajax_evk_save_security_section'); } catch (RuntimeException $e) { /* koniec */ }
    $out['odp'] = json_decode((string) ob_get_clean(), true);
    $out['zapisane'] = $rest();
    break;

case 'stan':
    $out['zapisane'] = $rest();
    $out['mapa'] = function_exists('wp_sitemaps_get_server') ? array_keys(wp_sitemaps_get_server()->registry->get_providers()) : null;
    break;

case 'sprzataj':
    $z = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    if (array_key_exists('evk_security', $z)) {
        global $wpdb;
        if ($z['evk_security'] === null) $wpdb->delete($wpdb->options, ['option_name' => 'evk_security']);
        else $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($z['evk_security'])], ['option_name' => 'evk_security']);
        wp_cache_flush();
    }
    if (array_key_exists('mapa', $z)) {
        if ($z['mapa'] === null) delete_option('tl_sitemap_settings'); else update_option('tl_sitemap_settings', $z['mapa']);
    }
    WP_Application_Passwords::delete_all_application_passwords((int) get_user_by('login', 'admin')->ID);
    @unlink($plik);
    $out['ok'] = true;
    break;
}
echo wp_json_encode($out);
