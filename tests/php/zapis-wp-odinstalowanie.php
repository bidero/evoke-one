<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Deaktywacja i odinstalowanie (1.232.0) na TRZECIM, jednorazowym
 * WordPressie (usun.test, tools/testowy-wp.sh) — tu kasowanie danych
 * wtyczki nikomu nie przeszkadza.
 *
 * Trzy kroki, każdy w osobnym procesie, jak w prawdziwym życiu:
 *   przygotuj <0|1>  wtyczka WŁĄCZONA: zasiew danych (naszych i cudzych
 *                    w kształcie Evoke Fields), przełącznik „Usuń dane",
 *                    zaplanowany cron — potem deaktywacja przez WordPressa,
 *   wykonaj          wtyczka WYŁĄCZONA (jej kod się nie ładuje): WordPress
 *                    woła uninstall.php przez uninstall_plugin(), potem spis,
 *   przywroc         aktywacja z powrotem i sprzątanie cudzych danych.
 *
 * Stan między krokami (ID, ścieżki) w pliku tymczasowym.
 */

$evk_trzeci = true;
require __DIR__ . '/_testowy-wp.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

const EVK_T_WTYCZKA = 'evoke-one/evoke-one.php';
$stan_plik = sys_get_temp_dir() . '/evk-t-odinstalowanie.json';
$krok = $argv[1] ?? '';
$out = ['krok' => $krok];
global $wpdb;

switch ($krok) {

case 'przygotuj':
    if (!is_plugin_active(EVK_T_WTYCZKA)) activate_plugin(EVK_T_WTYCZKA);
    wp_set_current_user(1);
    update_option('evk_usun_dane', ($argv[2] ?? '0') === '1' ? 1 : 0);

    // Newsletter i kopie: tabele z wierszami.
    evk_nl_create_tables();
    $lista = (int) evk_nl_create_list('Lista do odinstalowania');
    evk_nl_add_subscriber($lista, 'odinst@example.com');
    evk_backup_create_tables();

    // Opcje z różnych modułów, transienty (także dynamiczne nazwy).
    update_option('evk_smtp', ['enabled' => 1, 'host' => 'smtp.example.com', 'password' => 'test-haslo']);
    update_option('evk_darkmode', ['enabled' => 1]);
    update_option('tl_translations', ['groups' => []]);
    update_option('maintenance_bypass_password', 'test-obejscie');
    update_option('favicon_url', 'https://usun.test/favicon.png');
    update_option('evk_nl_backoff_7', 3);
    update_option('evoke_dashboard_active', 1);
    set_transient('evk_301_cache', [1], 3600);
    set_transient('evk_gdrive_msg_abc', 'x', 3600);
    set_transient('tl_compiled_config', [1], 3600);

    // Wpisy wtyczki, meta na zwykłej stronie, meta użytkownika.
    $wpisy = [];
    foreach (['evk_code_snippet' => 'private', 'evk_301_redirect' => 'publish', 'evk_301_log' => 'publish', 'evk_404_log' => 'publish'] as $typ => $status) {
        $wpisy[$typ] = (int) wp_insert_post(['post_type' => $typ, 'post_status' => $status, 'post_title' => 'Test ' . $typ]);
    }
    $strona = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Zwykła strona']);
    update_post_meta($strona, '_evk_og_url', 'https://usun.test/og.jpg');
    update_post_meta($strona, '_evoke_seo_title', 'Tytuł SEO');
    update_post_meta($strona, '_evk_access_key', 'klucz-evoke-fields');   // CUDZE: Evoke Fields
    update_user_meta(1, 'evk_avatar_id', 123);

    // Role: utworzona w Role Managerze (z użytkownikiem) i cudza.
    remove_role('evk_t_odinst');
    evk_role_utworz('evk_t_odinst', 'Rola do odinstalowania', ['read' => true]);
    $uzytkownik = (int) wp_insert_user(['user_login' => 'evk_t_odinst_' . wp_rand(), 'user_pass' => wp_generate_password(),
                                        'user_email' => 'odinst' . wp_rand() . '@example.com', 'role' => 'evk_t_odinst']);
    add_role('obca_rola', 'Cudza rola', ['read' => true]);
    get_role('editor')->add_cap('evk_access_newsletter');
    get_role('editor')->add_cap('evk_access_fields');                        // CUDZE: wejście do Evoke Fields

    // Katalogi: kopie, import, obrazki OG — i cudzy katalog kopii Evoke Fields.
    $kopie = evk_backup_dir();
    evk_backup_ensure_dir($kopie);
    file_put_contents($kopie . '/kopia-test.zip', 'x');
    $import = WP_CONTENT_DIR . '/evk-backups-import';
    wp_mkdir_p($import);
    file_put_contents($import . '/wgrana.zip', 'x');
    $uploads = wp_upload_dir();
    wp_mkdir_p($uploads['basedir'] . '/og-images');
    file_put_contents($uploads['basedir'] . '/og-images/og-1.jpg', 'x');
    $obcy = WP_CONTENT_DIR . '/evk-backups-obcytest';
    wp_mkdir_p($obcy);
    file_put_contents($obcy . '/cudza-kopia.zip', 'x');
    update_option('evk_backups_dir', 'evk-backups-obcytest');                 // CUDZE: Evoke Fields
    update_option('evk_custom_post_types', ['ksiazka' => []]);               // CUDZE: Evoke Fields
    update_option('obca_opcja', 'zostaje');

    // Cron.
    wp_schedule_single_event(time() + 3600, 'evk_backup_tick', [5]);
    wp_schedule_event(time() + 3600, 'daily', 'evk_backup_nightly');
    wp_schedule_single_event(time() + 3600, 'evk_nl_process_batch');
    $out['cron_przed'] = (bool) wp_next_scheduled('evk_backup_tick', [5]) && (bool) wp_next_scheduled('evk_backup_nightly')
                         && (bool) wp_next_scheduled('evk_nl_process_batch');

    file_put_contents($stan_plik, wp_json_encode(['strona' => $strona, 'wpisy' => $wpisy, 'uzytkownik' => $uzytkownik,
        'kopie' => $kopie, 'import' => $import, 'og' => $uploads['basedir'] . '/og-images', 'obcy' => $obcy]));

    // Deaktywacja tak, jak robi ją WordPress.
    deactivate_plugins(EVK_T_WTYCZKA);
    $out['aktywna_po_deaktywacji'] = is_plugin_active(EVK_T_WTYCZKA);
    $out['cron_po_deaktywacji'] = [
        'tick'     => (bool) wp_next_scheduled('evk_backup_tick', [5]),
        'nocna'    => (bool) wp_next_scheduled('evk_backup_nightly'),
        'wysylka'  => (bool) wp_next_scheduled('evk_nl_process_batch'),
    ];
    $out['reguly_po_deaktywacji'] = get_option('rewrite_rules', 'brak') === 'brak';
    break;

case 'wykonaj':
    $s = json_decode((string) @file_get_contents($stan_plik), true) ?: [];
    // Warunek testu: kod wtyczki NIE jest załadowany — jak przy prawdziwym usuwaniu.
    $out['wtyczka_zaladowana'] = function_exists('evk_nl_create_tables') || function_exists('evk_backup_dir');
    uninstall_plugin(EVK_T_WTYCZKA);

    $opcja = static function (string $n): bool { return get_option($n, null) !== null; };
    $tabela = static function (string $t) use ($wpdb): bool {
        return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix . $t)));
    };
    $wpisy = [];
    foreach (($s['wpisy'] ?? []) as $typ => $id) $wpisy[$typ] = get_post((int) $id) !== null;
    $u = get_userdata((int) ($s['uzytkownik'] ?? 0));
    $out['nasze'] = [
        'opcje'      => array_filter(['evk_smtp' => $opcja('evk_smtp'), 'evk_darkmode' => $opcja('evk_darkmode'), 'tl_translations' => $opcja('tl_translations'),
                         'maintenance_bypass_password' => $opcja('maintenance_bypass_password'), 'favicon_url' => $opcja('favicon_url'),
                         'evk_nl_backoff_7' => $opcja('evk_nl_backoff_7'), 'evoke_dashboard_active' => $opcja('evoke_dashboard_active'),
                         'evk_usun_dane' => $opcja('evk_usun_dane'), 'evk_role_utworzone' => $opcja('evk_role_utworzone'),
                         'evk_backup_dir' => $opcja('evk_backup_dir')]),
        'transienty' => array_filter(['evk_301_cache' => get_transient('evk_301_cache') !== false,
                         'evk_gdrive_msg_abc' => get_transient('evk_gdrive_msg_abc') !== false,
                         'tl_compiled_config' => get_transient('tl_compiled_config') !== false]),
        'tabele'     => array_filter(['evk_nl_subscribers' => $tabela('evk_nl_subscribers'), 'evk_backup_jobs' => $tabela('evk_backup_jobs')]),
        'wpisy'      => array_filter($wpisy),
        'meta'       => array_filter(['_evk_og_url' => get_post_meta((int) ($s['strona'] ?? 0), '_evk_og_url', true) !== '',
                         '_evoke_seo_title' => get_post_meta((int) ($s['strona'] ?? 0), '_evoke_seo_title', true) !== '',
                         'evk_avatar_id' => get_user_meta(1, 'evk_avatar_id', true) !== '']),
        'rola'       => get_role('evk_t_odinst') !== null,
        'uprawnienie'=> get_role('editor')->has_cap('evk_access_newsletter'),
        'katalogi'   => array_filter(['kopie' => is_dir((string) ($s['kopie'] ?? '')), 'import' => is_dir((string) ($s['import'] ?? '')),
                         'og' => is_dir((string) ($s['og'] ?? ''))]),
    ];
    $out['uzytkownik_role'] = $u ? array_values($u->roles) : null;
    $out['domyslna_rola'] = get_option('default_role');
    $out['cudze'] = [
        'evk_custom_post_types' => $opcja('evk_custom_post_types'),
        'evk_backups_dir'       => $opcja('evk_backups_dir'),
        'obca_opcja'            => $opcja('obca_opcja'),
        'katalog_evoke_fields'  => is_file((string) ($s['obcy'] ?? '') . '/cudza-kopia.zip'),
        '_evk_access_key'       => get_post_meta((int) ($s['strona'] ?? 0), '_evk_access_key', true) === 'klucz-evoke-fields',
        'evk_access_fields'     => get_role('editor')->has_cap('evk_access_fields'),
        'obca_rola'             => get_role('obca_rola') !== null,
        'strona'                => get_post((int) ($s['strona'] ?? 0)) !== null,
        'blogname'              => get_option('blogname') === 'Evoke usun',
    ];
    break;

case 'przywroc':
    $s = json_decode((string) @file_get_contents($stan_plik), true) ?: [];
    $wynik = activate_plugin(EVK_T_WTYCZKA);
    $out['aktywna'] = is_plugin_active(EVK_T_WTYCZKA) && !is_wp_error($wynik);
    // Sprzątanie: cudze dane testu i to, czego odinstalowanie celowo nie rusza.
    wp_delete_post((int) ($s['strona'] ?? 0), true);
    foreach (($s['wpisy'] ?? []) as $id) wp_delete_post((int) $id, true);
    if (!empty($s['uzytkownik'])) wp_delete_user((int) $s['uzytkownik']);
    foreach (['evk_custom_post_types', 'evk_backups_dir', 'obca_opcja', 'evk_role_utworzone'] as $n) delete_option($n);
    remove_role('obca_rola');
    remove_role('evk_t_odinst');
    get_role('editor')->remove_cap('evk_access_fields');
    get_role('editor')->remove_cap('evk_access_newsletter');
    foreach (['evk_backup_tick', 'evk_backup_nightly', 'evk_nl_process_batch'] as $hak) wp_unschedule_hook($hak);
    $rm = static function (string $dir) use (&$rm) {
        if ($dir === '' || !is_dir($dir)) return;
        foreach (array_diff((array) scandir($dir), ['.', '..']) as $e) {
            $p = $dir . '/' . $e;
            is_dir($p) && !is_link($p) ? $rm($p) : @unlink($p);
        }
        @rmdir($dir);
    };
    foreach (['obcy', 'kopie', 'import', 'og'] as $k) $rm((string) ($s['' . $k] ?? ''));
    @unlink($stan_plik);
    break;

default:
    $out['blad'] = 'nieznany krok';
}

echo wp_json_encode($out);
