<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Panel Evoke ONE dla roli bez praw administratora (1.292.0) — prawdziwy
 * WordPress. Krok `macierz` woła PRAWDZIWE zapisy AJAX (do_action
 * wp_ajax_…) jako różne konta, z nonce tych kont, i oddaje, które przeszły:
 * granica bezpieczeństwa siedzi po stronie serwera, nie w tym, co panel pokazuje.
 *
 *   php tests/php/uprawnienia-manager.php wp
 *   php tests/php/uprawnienia-manager.php przygotuj   role evk_t_manager (SEO, przekierowania, kopia, logowanie) i evk_t_seo (samo SEO),
 *                                                     konta t_manager, t_seo, t_nikt („test-haslo”), t_red2fa i t_admin2 z 2FA; kopie i 2FA włączone
 *   php tests/php/uprawnienia-manager.php macierz
 *   php tests/php/uprawnienia-manager.php stan
 *   php tests/php/uprawnienia-manager.php sprzataj
 */
require __DIR__ . '/_testowy-wp.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$krok = $argv[1] ?? '';
$plik = sys_get_temp_dir() . '/evk-t-manager.json';
$zap  = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
$out  = ['krok' => $krok];
$opcje = ['evk_backup', 'evk_2fa', 'evk_schema'];
$role = ['evk_t_manager' => ['evk_access_seo', 'evk_access_przekierowania', 'evk_access_kopie', 'evk_access_logowanie'], 'evk_t_seo' => ['evk_access_seo']];
$konta = ['t_manager' => 'evk_t_manager', 't_seo' => 'evk_t_seo', 't_nikt' => 'subscriber', 't_red2fa' => 'editor', 't_admin2' => 'administrator'];
$id = static function (string $l): int { $u = get_user_by('login', $l); return $u ? (int) $u->ID : 0; };
$wlacz2fa = static function (int $uid): void {
    $k = evk_2fa_konto($uid);
    $k['sekret'] = evk_2fa_zaszyfruj('KRUGS4ZANFZSAYJAORSXG5BAONSWG4TF');
    $k['od'] = time();
    evk_2fa_zapisz_konto($uid, $k);
};

class EvkTWyjscie extends Exception {}

/** Prawdziwa akcja AJAX jako dane konto; nonce liczony dla tego konta. „ok” = success:true. */
function evk_t_ajax(string $akcja, int $uid, array $post, string $nonce_akcja, string $pole = 'nonce'): string {
    wp_set_current_user($uid);
    $post[$pole] = wp_create_nonce($nonce_akcja);
    $_POST = $_REQUEST = $post + ['action' => $akcja];
    ob_start();
    try { do_action('wp_ajax_' . $akcja); } catch (EvkTWyjscie $e) { /* koniec jak wp_die */ }
    $o = (string) ob_get_clean();
    $j = json_decode($o, true);
    return is_array($j) ? (!empty($j['success']) ? 'ok' : 'blad') : 'blad';
}

switch ($krok) {
case 'wp':
    $out['wp'] = rtrim(ABSPATH, '/');
    break;

case 'przygotuj':
    if (!isset($zap['opcje'])) foreach ($opcje as $o) $zap['opcje'][$o] = get_option($o, null);
    foreach ($role as $r => $caps) {
        remove_role($r);
        add_role($r, $r, array_fill_keys(array_merge(['read'], $caps), true));
    }
    foreach ($konta as $login => $rola) {
        if ($u = get_user_by('login', $login)) wp_delete_user($u->ID);
        wp_insert_user(['user_login' => $login, 'user_pass' => 'test-haslo', 'role' => $rola, 'user_email' => $login . '@example.test']);
    }
    $b = get_option('evk_backup', []);
    update_option('evk_backup', array_merge(is_array($b) ? $b : [], ['enabled' => 1]));
    update_option('evk_2fa', ['enabled' => 1, 'role' => [], 'pamietaj' => 1]);
    $wlacz2fa($id('t_red2fa'));
    $wlacz2fa($id('t_admin2'));
    file_put_contents($plik, (string) wp_json_encode($zap));
    $out['id'] = array_map($id, array_combine(array_keys($konta), array_keys($konta)));
    break;

case 'macierz':
    add_filter('wp_doing_ajax', '__return_true');
    add_filter('wp_die_ajax_handler', static function () { return static function () { throw new EvkTWyjscie(); }; });
    $schema = (array) get_option('evk_schema', []);
    $dark = (array) get_option('evk_darkmode', []);
    $strona = (int) (get_posts(['post_type' => 'page', 'numberposts' => 1, 'fields' => 'ids'])[0] ?? 0);
    $seo_przed = get_post_meta($strona, '_evoke_seo_title', true);
    foreach (['admin', 't_manager', 't_seo', 't_nikt'] as $l) {
        $u = $id($l);
        $m = [];
        $m['przelacznik_schema'] = evk_t_ajax('evk_ajax_toggle', $u, ['option' => 'evk_schema', 'field' => 'enabled', 'value' => empty($schema['enabled']) ? 0 : 1], 'evk-toggle-nonce');
        $m['przelacznik_darkmode'] = evk_t_ajax('evk_ajax_toggle', $u, ['option' => 'evk_darkmode', 'field' => 'enabled', 'value' => empty($dark['enabled']) ? 0 : 1], 'evk-toggle-nonce');
        $m['seo_wpis'] = evk_t_ajax('evoke_save_seo_ajax', $u, ['post_id' => $strona, 'seo_title' => (string) $seo_przed, 'seo_desc' => (string) get_post_meta($strona, '_evoke_seo_desc', true),
            'seo_keywords' => (string) get_post_meta($strona, '_evoke_seo_keywords', true), 'seo_robots' => (string) wp_json_encode((array) get_post_meta($strona, '_evoke_seo_robots', true))], 'evoke_seo_nonce');
        $m['przekierowanie_301'] = evk_t_ajax('evk_301_save', $u, ['from' => '/evk-t-manager-' . $l, 'to' => '/'], 'evk_tools_nonce');
        $m['kopia_stan'] = evk_t_ajax('evk_backup_status', $u, ['id' => 0], 'evk_backup');
        $m['kopia_lista'] = evk_t_ajax('evk_backup_list', $u, [], 'evk_backup');
        $m['kopia_usun'] = evk_t_ajax('evk_backup_delete', $u, ['archive' => 'evk-t-nie-ma.zip'], 'evk_backup');
        if ($l !== 'admin') $m['2fa_reset_admina'] = evk_t_ajax('evk_2fa', $u, ['akcja' => 'reset', 'user' => $id('t_admin2')], 'evk_2fa_' . $id('t_admin2'));
        wp_set_current_user($u);
        $m['options_php_schema'] = apply_filters('option_page_capability_evoke_one_schema', 'manage_options');
        $m['options_php_og'] = apply_filters('option_page_capability_evoke_one_og', 'manage_options');
        $out['macierz'][$l] = $m;
    }
    /* Reset 2FA zwykłego konta — tylko ostatni krok (kasuje mu 2FA): najpierw rola bez dostępu, potem Manager. */
    $out['macierz']['t_seo']['2fa_reset_konta'] = evk_t_ajax('evk_2fa', $id('t_seo'), ['akcja' => 'reset', 'user' => $id('t_red2fa')], 'evk_2fa_' . $id('t_red2fa'));
    $out['macierz']['t_manager']['2fa_reset_konta'] = evk_t_ajax('evk_2fa', $id('t_manager'), ['akcja' => 'reset', 'user' => $id('t_red2fa')], 'evk_2fa_' . $id('t_red2fa'));
    $out['2fa_po'] = ['red' => evk_2fa_ma($id('t_red2fa')), 'admin2' => evk_2fa_ma($id('t_admin2'))];
    /* Kontrola: administrator resetuje 2FA innego administratora — ta sama akcja działa. */
    $out['macierz']['admin']['2fa_reset_admina_przez_admina'] = evk_t_ajax('evk_2fa', $id('admin'), ['akcja' => 'reset', 'user' => $id('t_admin2')], 'evk_2fa_' . $id('t_admin2'));
    $wlacz2fa($id('t_red2fa'));
    $wlacz2fa($id('t_admin2'));
    break;

case 'stan':
    $out['przekierowania'] = array_values(array_filter(array_map(static function ($p) { return (string) get_post_meta($p, 'redirect_from', true); },
        get_posts(['post_type' => 'evk_301_redirect', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids'])), static function ($f) { return strpos($f, '/evk-t-manager') === 0; }));
    $out['schema'] = get_option('evk_schema', null);
    $out['2fa'] = ['red' => evk_2fa_ma($id('t_red2fa')), 'admin2' => evk_2fa_ma($id('t_admin2'))];
    break;

case 'sprzataj':
    foreach (get_posts(['post_type' => 'evk_301_redirect', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']) as $p) {
        if (strpos((string) get_post_meta($p, 'redirect_from', true), '/evk-t-manager') === 0) wp_delete_post($p, true);
    }
    if (function_exists('evk_301_clear_cache')) evk_301_clear_cache();
    foreach ($konta as $login => $x) if ($u = get_user_by('login', $login)) wp_delete_user($u->ID);
    foreach (array_keys($role) as $r) remove_role($r);
    foreach ((array) ($zap['opcje'] ?? []) as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v); }
    @unlink($plik);
    $out['ok'] = true;
    break;

default:
    $out['blad'] = 'nieznany krok';
}
echo wp_json_encode($out);
