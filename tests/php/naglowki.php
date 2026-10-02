<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Nagłówki bezpieczeństwa (1.280.0) — prawdziwy WordPress; same nagłówki
 * test czyta z odpowiedzi serwera (php -S).
 *
 *   php tests/php/naglowki.php wp
 *   php tests/php/naglowki.php domyslne                stan bez zapisanych pól nagłówków (odłożony stary)
 *   php tests/php/naglowki.php ajax <json danych>       zapis sekcji `naglowki` prawdziwym AJAX-em zakładki
 *   php tests/php/naglowki.php lista <https 0|1>        nagłówki z bieżących ustawień
 *   php tests/php/naglowki.php woo                      domyślne funkcje przy WooCommerce
 *   php tests/php/naglowki.php stan                     zapisane pola nagłówków
 *   php tests/php/naglowki.php sprzataj
 */
require __DIR__ . '/_testowy-wp.php';

$krok = $argv[1] ?? '';
$plik = sys_get_temp_dir() . '/evk-t-naglowki.json';
$out  = ['krok' => $krok];
if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

$hdr = static function (): array {
    return array_filter((array) get_option('evk_security', []), static function ($k) { return strpos((string) $k, 'hdr_') === 0; }, ARRAY_FILTER_USE_KEY);
};

switch ($krok) {
case 'wp':
    $out['wp'] = rtrim(ABSPATH, '/');
    break;

case 'domyslne':
    if (!is_file($plik)) file_put_contents($plik, (string) wp_json_encode(['evk_security' => get_option('evk_security', null)]));
    $s = (array) get_option('evk_security', []);
    foreach (array_keys($s) as $k) if (strpos((string) $k, 'hdr_') === 0) unset($s[$k]);
    /* Wprost do bazy — bez filtra sanityzacji, który dopisałby wartości domyślne. */
    global $wpdb;
    $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($s)], ['option_name' => 'evk_security']);
    wp_cache_delete('evk_security', 'options');
    wp_cache_delete('alloptions', 'options');
    $out['zapisane'] = $hdr();
    break;

case 'ajax':
    $_POST = $_REQUEST = ['action' => 'evk_save_security_section', 'nonce' => wp_create_nonce('evk_security_nonce'), 'section' => 'naglowki',
        'data' => json_decode((string) ($argv[2] ?? '{}'), true) ?: []];
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    add_filter('wp_die_ajax_handler', static function () {
        return static function ($k = '') { if (is_scalar($k)) echo $k; throw new RuntimeException('koniec'); };
    });
    ob_start();
    try { do_action('wp_ajax_evk_save_security_section'); } catch (RuntimeException $e) { /* koniec */ }
    $out['odp'] = json_decode((string) ob_get_clean(), true);
    $out['zapisane'] = $hdr();
    break;

case 'lista':
    $out['lista'] = evk_naglowki_lista(evk_security_get(), ($argv[2] ?? '0') === '1');
    break;

case 'woo':
    $out['bez'] = evk_naglowki_uprawnienia_domyslne();
    if (!class_exists('WooCommerce')) { eval('class WooCommerce {}'); }
    $out['z'] = evk_naglowki_uprawnienia_domyslne();
    break;

case 'stan':
    $out['zapisane'] = $hdr();
    break;

case 'sprzataj':
    $z = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    if (array_key_exists('evk_security', $z)) {
        global $wpdb;
        if ($z['evk_security'] === null) delete_option('evk_security');
        else $wpdb->update($wpdb->options, ['option_value' => maybe_serialize($z['evk_security'])], ['option_name' => 'evk_security']);
        wp_cache_flush();
    }
    @unlink($plik);
    $out['ok'] = true;
    break;
}
echo wp_json_encode($out);
