<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Język WordPressa na wersjach językowych (1.258.0) — stan dla testu tl-locale.
 *
 *   php tests/php/tl-locale.php wp
 *   php tests/php/tl-locale.php przygotuj http://127.0.0.1:<port>
 *   php tests/php/tl-locale.php przeplucz http://127.0.0.1:<port>   (osobny proces: reguły archiwów)
 *   php tests/php/tl-locale.php filtr                  (get_locale() dla różnych adresów, w procesie)
 *   php tests/php/tl-locale.php panel                  (to samo z WP_ADMIN)
 *   php tests/php/tl-locale.php mapa                   (tl_locale_z_kodu wprost)
 *   php tests/php/tl-locale.php stan                   (tl_paczki_jezykow)
 *   php tests/php/tl-locale.php pobierz http://127.0.0.1:<port> <locale> [tlumacz]
 *   php tests/php/tl-locale.php sprzataj
 *
 * Testowy WordPress ma sam angielski (CLAUDE.md), więc sonda stawia minimalne
 * paczki jako pliki `.l10n.php` (WordPress 6.5+): pl_PL rdzenia (miesiąc
 * i jeden tekst) oraz dziedziny `evk-t-dom` w languages/plugins — tak leżą
 * paczki motywów i wtyczek, np. Bricksa. Paczkę de_DE test pobiera przyciskiem
 * z zakładki Języki: WordPress.org udaje wpis w `available_translations`,
 * a zip podaje serwer testowy.
 *
 * Mu-plugin testu drukuje w stopce znacznik z tym, co WordPress myśli
 * o języku (get_locale, miesiąc z wp_date, teksty z obu dziedzin), i wystawia
 * trasę `bricks/v1/query_result` — tą drogą filtr i stronicowanie Bricksa
 * pytają o HTML w języku strony (1.253.1). Bricksa tu nie ma, więc trasa jest
 * wolna.
 */

if (in_array($argv[1] ?? '', ['przygotuj', 'przeplucz', 'pobierz'], true) && preg_match('#^http://127\.0\.0\.1:\d+$#', $argv[2] ?? '')) {
    define('WP_HOME', $argv[2]);
    define('WP_SITEURL', $argv[2]);
}
if (($argv[1] ?? '') === 'panel') define('WP_ADMIN', true);
require __DIR__ . '/_testowy-wp.php';

$krok     = $argv[1] ?? '';
$plik     = sys_get_temp_dir() . '/evk-t-tl-locale.json';
$mu       = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
$muPlik   = $mu . '/evk-t-tl-locale.php';
$jezyki   = rtrim(wp_normalize_path(WP_LANG_DIR), '/');
$zipDir   = rtrim(wp_normalize_path(WP_CONTENT_DIR), '/') . '/uploads/evk-t-lok';
$opcje    = ['permalink_structure', 'evk_tl_module_enabled', 'tl_languages', 'WPLANG'];
$out      = ['krok' => $krok];

/** Znana data: 15 marca 2026, południe UTC — marzec / March / März. */
const EVK_T_LOK_CHWILA = 1773576000;

/** Minimalna paczka `.l10n.php`: nagłówki i komunikaty. */
function evk_t_lok_paczka(string $jezyk, array $komunikaty): string {
    return '<?php return ' . var_export(['domain' => null, 'plural-forms' => 'nplurals=2; plural=(n != 1);',
        'language' => $jezyk, 'messages' => $komunikaty], true) . ';';
}

/** Pliki paczek, które kładzie test (względem languages/). */
function evk_t_lok_pliki(): array {
    return ['pl_PL.l10n.php', 'plugins/evk-t-dom-pl_PL.l10n.php', 'de_DE.l10n.php'];
}

switch ($krok) {

case 'wp':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'przygotuj':
    if (!defined('WP_HOME')) { $out['brak'] = 'przygotuj bez adresu serwera testowego'; break; }
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'wpisy' => [], 'mu_bylo' => is_dir($mu),
            'jezyki_bylo' => is_dir($jezyki), 'pluginy_bylo' => is_dir($jezyki . '/plugins'),
            'upgrade_bylo' => is_dir(WP_CONTENT_DIR . '/upgrade'),
            'tlumaczenia' => get_site_transient('available_translations')]));
    }
    $zapis = json_decode((string) file_get_contents($plik), true);

    // Paczki: pl_PL rdzenia i dziedziny wtyczki. de_DE przyjdzie przyciskiem.
    if (!is_dir($jezyki . '/plugins')) mkdir($jezyki . '/plugins', 0755, true);
    file_put_contents($jezyki . '/pl_PL.l10n.php', evk_t_lok_paczka('pl_PL', ['March' => 'marzec', 'Search' => 'Szukaj']));
    file_put_contents($jezyki . '/plugins/evk-t-dom-pl_PL.l10n.php', evk_t_lok_paczka('pl_PL', ['Hello' => 'Cześć']));
    @unlink($jezyki . '/de_DE.l10n.php');

    // Zip paczki de_DE dla przycisku (podaje go serwer testowy jak plik statyczny).
    if (!is_dir($zipDir)) mkdir($zipDir, 0755, true);
    $zip = new ZipArchive();
    $zip->open($zipDir . '/de_DE.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('de_DE.l10n.php', evk_t_lok_paczka('de_DE', ['March' => 'März', 'Search' => 'Suchen']));
    $zip->close();

    if (!is_dir($mu)) mkdir($mu, 0755, true);
    file_put_contents($muPlik, <<<'PHP'
<?php
// Wyłącznie test tl-locale (tests/php/tl-locale.php) — usuwany po teście.
add_action('wp_footer', function () {
    $chwila = 1773576000;
    /* switch_to_locale() (np. mail w języku odbiorcy) ma wygrać z filtrem
       wersji językowej, a restore_previous_locale() wrócić do niej. */
    $przelaczony = switch_to_locale('pl_PL') ? wp_date('F', $chwila) : '';
    if ($przelaczony !== '') restore_previous_locale();
    printf('<i id="evk-t-lok" data-locale="%s" data-miesiac="%s" data-szukaj="%s" data-dom="%s" data-jezyk="%s" data-przelaczony="%s" data-po="%s"></i>',
        esc_attr(get_locale()), esc_attr(wp_date('F', $chwila)), esc_attr(__('Search')), esc_attr(__('Hello', 'evk-t-dom')),
        esc_attr(get_bloginfo('language')), esc_attr($przelaczony), esc_attr(wp_date('F', $chwila)));
}, 999);
add_action('rest_api_init', function () {
    register_rest_route('bricks/v1', '/query_result', ['methods' => 'GET', 'permission_callback' => '__return_true',
        'callback' => function () { return ['locale' => get_locale(), 'miesiac' => wp_date('F', 1773576000)]; }]);
});
PHP
    );

    $wpis = (int) wp_insert_post(['post_title' => 'Wpis LOK', 'post_name' => 'wpis-lok', 'post_status' => 'publish',
        'post_content' => 'Treść.', 'post_date' => '2026-03-15 12:00:00']);
    $zapis['wpisy'] = array_merge($zapis['wpisy'], [$wpis]);
    file_put_contents($plik, wp_json_encode($zapis));

    update_option('tl_languages', [
        ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'],
        ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
    ]);
    update_option('evk_tl_module_enabled', 1);
    /* Język witryny wprost do bazy: update_option() przepuszcza WPLANG przez
       listę paczek, a ta w tym procesie mogła być policzona przed plikami. */
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", 'WPLANG'));
    $wpdb->insert($wpdb->options, ['option_name' => 'WPLANG', 'option_value' => 'pl_PL', 'autoload' => 'yes']);
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('WPLANG', 'options');
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
    flush_rewrite_rules(false);
    $out['gotowe'] = $wpis > 0 && is_file($zipDir . '/de_DE.zip');
    break;

case 'przeplucz':
    flush_rewrite_rules(false);
    $out['tl']     = function_exists('tl_filtr_locale');
    $out['wplang'] = get_option('WPLANG');
    break;

case 'filtr':
case 'panel':
    /* get_locale() dla adresów wprost w procesie — bez serwera. Pamięć filtra
       jest kluczowana adresem i Refererem, a straż buildera stoi przed nią. */
    $przypadki = [
        'pl'            => ['/wpis-lok/', [], ''],
        'en'            => ['/en/wpis-lok/', [], ''],
        'de'            => ['/de/wpis-lok/', [], ''],
        'builder'       => ['/en/wpis-lok/', ['bricks' => 'run'], ''],
        'kanwa'         => ['/en/wpis-lok/', ['bricks' => 'run', 'brickspreview' => '1'], ''],
        'podglad'       => ['/en/wpis-lok/', ['bricks_preview' => '1'], ''],
        'rest_referer'  => ['/wp-json/bricks/v1/query_result', [], 'http://' . $_SERVER['HTTP_HOST'] . '/en/wpis-lok/'],
        'rest_bez'      => ['/wp-json/bricks/v1/query_result', [], ''],
        'rest_inna'     => ['/wp-json/wp/v2/posts', [], 'http://' . $_SERVER['HTTP_HOST'] . '/en/wpis-lok/'],
        'nieznany'      => ['/fr/wpis-lok/', [], ''],
    ];
    $wyniki = [];
    foreach ($przypadki as $nazwa => [$uri, $get, $ref]) {
        $_SERVER['REQUEST_URI'] = $uri;
        $_GET = $get;
        if ($ref !== '') $_SERVER['HTTP_REFERER'] = $ref; else unset($_SERVER['HTTP_REFERER']);
        $wyniki[$nazwa] = get_locale();
    }
    $out['locale']  = $wyniki;
    $out['witryna'] = function_exists('tl_locale_witryny') ? tl_locale_witryny() : null;
    $out['admin']   = is_admin();
    break;

case 'mapa':
    $zainst = ['pl_PL', 'de_CH', 'uk'];
    $kody = [
        'en-US' => 'en', 'en-gb' => 'en', 'de-DE' => 'de', 'de' => 'de', 'en' => 'en', 'fr' => 'fr',
        'uk' => 'uk', 'de-DE-formal' => 'de', 'pt-BR' => 'pt', '' => 'es', '??' => 'es', 'x' => 'xx1',
    ];
    foreach ($kody as $html => $lang) $out['mapa'][$html . '|' . $lang] = tl_locale_z_kodu((string) $html, $lang, $zainst);
    break;

case 'stan':
    $out['stan'] = tl_paczki_jezykow();
    break;

case 'pobierz':
    /* Przycisk „Pobierz paczkę” z zakładki Języki: akcja AJAX jak z przeglądarki.
       WordPress.org udaje wpis w `available_translations` (to samo, co zwraca
       translations_api), a paczkę podaje serwer testowy. */
    if (!defined('WP_HOME')) { $out['brak'] = 'pobierz bez adresu serwera testowego'; break; }
    $locale = (string) ($argv[3] ?? '');
    set_site_transient('available_translations', [
        'de_DE' => ['language' => 'de_DE', 'version' => get_bloginfo('version'), 'updated' => '2026-03-01 00:00:00',
            'english_name' => 'German', 'native_name' => 'Deutsch',
            'package' => WP_HOME . '/wp-content/uploads/evk-t-lok/de_DE.zip',
            'iso' => [1 => 'de', 2 => 'deu'], 'strings' => ['continue' => 'Weiter']],
    ], HOUR_IN_SECONDS);
    if (($argv[4] ?? '') === 'tlumacz') {
        /* Tłumacz: ma dostęp do Tłumaczeń (Role Manager), ale nie instaluje języków. */
        $u = get_user_by('login', 'evk-t-tlumacz');
        $id = $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => 'evk-t-tlumacz', 'user_pass' => wp_generate_password(),
            'user_email' => 'evk-t-tlumacz@example.test', 'role' => 'editor']);
        $u = get_user_by('id', $id);
        $u->add_cap('evk_access_translations');
        $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
        $zapis['uzytkownicy'] = array_values(array_unique(array_merge($zapis['uzytkownicy'] ?? [], [$id])));
        file_put_contents($plik, wp_json_encode($zapis));
        wp_set_current_user($id);
    } else {
        $admin = get_user_by('login', 'admin');
        if (!$admin) { $out['brak'] = 'brak użytkownika admin'; break; }
        wp_set_current_user($admin->ID);
    }
    $_POST = $_REQUEST = wp_slash(['action' => 'tl_pobierz_paczke', 'nonce' => wp_create_nonce('tl_ajax_nonce'), 'locale' => $locale]);
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    add_filter('wp_die_ajax_handler', static function () {
        return static function ($komunikat = '') {
            if (is_scalar($komunikat)) echo $komunikat;
            throw new RuntimeException('koniec');
        };
    });
    ob_start();
    try {
        do_action('wp_ajax_tl_pobierz_paczke');
    } catch (RuntimeException $e) {
        // wp_send_json() kończy tu.
    }
    $wyjscie = (string) ob_get_clean();
    $out['odp']    = json_decode($wyjscie, true) ?? $wyjscie;
    $out['plik']   = is_file($jezyki . '/de_DE.l10n.php');
    break;

case 'sprzataj':
    $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        if ($o === 'WPLANG') {
            global $wpdb;
            $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s", 'WPLANG'));
            if ($w !== null) $wpdb->insert($wpdb->options, ['option_name' => 'WPLANG', 'option_value' => (string) $w, 'autoload' => 'yes']);
            wp_cache_delete('alloptions', 'options');
            continue;
        }
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['wpisy'] ?? []) as $id) wp_delete_post((int) $id, true);
    if (!empty($zapis['uzytkownicy'])) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($zapis['uzytkownicy'] as $id) wp_delete_user((int) $id);
    }
    if (!empty($zapis['tlumaczenia'])) set_site_transient('available_translations', $zapis['tlumaczenia']);
    else delete_site_transient('available_translations');
    foreach (evk_t_lok_pliki() as $p) @unlink($jezyki . '/' . $p);
    if (array_key_exists('upgrade_bylo', $zapis) && !$zapis['upgrade_bylo']) @rmdir(WP_CONTENT_DIR . '/upgrade');
    if (empty($zapis['pluginy_bylo'])) @rmdir($jezyki . '/plugins');
    if (empty($zapis['jezyki_bylo'])) @rmdir($jezyki);
    @unlink($zipDir . '/de_DE.zip');
    @rmdir($zipDir);
    @unlink($muPlik);
    if (empty($zapis['mu_bylo'])) @rmdir($mu);
    $GLOBALS['wp_rewrite']->init();
    flush_rewrite_rules(false);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
