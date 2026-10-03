<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Ukryty adres logowania z prawdziwym Bricksem (1.288.0) — piąty testowy
 * WordPress. Strona logowania z ustawień Bricksa („Custom authentication
 * pages”) z formularzem logowania; Bricks przekierowuje na nią wp-login.php.
 *
 *   php tests/php/logowanie-adres-bricks.php wp
 *   php tests/php/logowanie-adres-bricks.php przygotuj    strona logowania Bricksa, konto ua_bricks / „test-haslo”
 *   php tests/php/logowanie-adres-bricks.php ustaw <json> opcja evk_ukryty_adres
 *   php tests/php/logowanie-adres-bricks.php sprzataj
 */
$krok = $argv[1] ?? '';
$evk_piaty = true;
require __DIR__ . '/_testowy-wp.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$plik = sys_get_temp_dir() . '/evk-t-ua-bricks.json';
$zap  = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
$out  = ['krok' => $krok];
if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

switch ($krok) {
case 'wp':
    $out['wp'] = rtrim(ABSPATH, '/');
    $out['bricks'] = defined('BRICKS_VERSION') ? BRICKS_VERSION : null;
    break;

case 'przygotuj':
    if (!isset($zap['opcje'])) {
        foreach (['evk_ukryty_adres', 'bricks_global_settings'] as $o) $zap['opcje'][$o] = get_option($o, null);
    }
    if ($u = get_user_by('login', 'ua_bricks')) wp_delete_user($u->ID);
    wp_insert_user(['user_login' => 'ua_bricks', 'user_pass' => 'test-haslo', 'role' => 'subscriber', 'user_email' => 'ua_bricks@example.test']);
    $p = (int) wp_insert_post(['post_title' => 'UA logowanie Bricks', 'post_type' => 'page', 'post_status' => 'publish']);
    update_post_meta($p, '_bricks_editor_mode', 'bricks');
    update_post_meta($p, '_bricks_page_content_2', wp_slash([
        ['id' => 'uas001', 'name' => 'section', 'parent' => 0, 'children' => ['uaf001'], 'settings' => []],
        ['id' => 'uaf001', 'name' => 'form', 'parent' => 'uas001', 'children' => [], 'settings' => [
            'fields' => [
                ['id' => 'lgn001', 'type' => 'text', 'label' => 'Login', 'required' => true],
                ['id' => 'pwd001', 'type' => 'password', 'label' => 'Hasło', 'required' => true],
            ],
            'actions' => ['login'], 'loginName' => 'lgn001', 'loginPassword' => 'pwd001',
            'submitButtonText' => 'Zaloguj się', 'successMessage' => 'Zalogowano.',
        ]],
    ]));
    $zap['strony'][] = $p;
    $g = get_option('bricks_global_settings', []);
    update_option('bricks_global_settings', array_merge(is_array($g) ? $g : [], ['login_page' => $p]));
    file_put_contents($plik, (string) wp_json_encode($zap));
    $out['strona'] = $p;
    $out['url'] = get_permalink($p);
    break;

case 'ustaw':
    update_option('evk_ukryty_adres', json_decode((string) ($argv[2] ?? '{}'), true) ?: [], false);
    $out['wlaczony'] = evk_ua_wlaczony();
    break;

case 'sprzataj':
    if ($u = get_user_by('login', 'ua_bricks')) wp_delete_user($u->ID);
    foreach ((array) ($zap['strony'] ?? []) as $p) wp_delete_post((int) $p, true);
    foreach ((array) ($zap['opcje'] ?? []) as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v); }
    @unlink($plik);
    $out['wlaczony'] = evk_ua_wlaczony();
    break;
}

echo wp_json_encode($out);
