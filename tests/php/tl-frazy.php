<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Frazy słownika i etykiety menu w tłumaczeniu AI (1.279.0) — prawdziwy
 * WordPress, AI przez atrapę (tests/php/_ai-atrapa.php).
 *
 *   php tests/php/tl-frazy.php wp
 *   php tests/php/tl-frazy.php przygotuj                  języki, klucze AI, słownik „Ogólne”, strona i menu
 *   php tests/php/tl-frazy.php jednostki [ponownie]       lista hurtu dla zakresu „frazy” (dopisuje etykiety menu)
 *   php tests/php/tl-frazy.php krok <grupa> <jezyk> [dostawca] [model] [ponownie]
 *   php tests/php/tl-frazy.php krok-w-trakcie <grupa> <jezyk> <wiersz>   ponownie, a w trakcie zapytania ktoś wpisuje tłumaczenie wiersza
 *   php tests/php/tl-frazy.php ajax-krok <grupa> <jezyk> [kto]   prawdziwy AJAX kroku (kto: admin | subskrybent)
 *   php tests/php/tl-frazy.php zmien <grupa> <wiersz> <jezyk> <tekst>   tłumaczenie wpisane obok (jak z zakładki)
 *   php tests/php/tl-frazy.php mcp <grupa> <jezyk> [zmien]   pobranie i zapis przez zdolności MCP (zmien: ktoś wpisał tłumaczenie pomiędzy)
 *   php tests/php/tl-frazy.php render <jezyk> <html>       silnik fraz na HTML-u strony
 *   php tests/php/tl-frazy.php pamiec <jezyk> <pl>         pamięć tłumaczeń hurtu dla tekstu
 *   php tests/php/tl-frazy.php lista                       „Do sprawdzenia”: frazy z AI
 *   php tests/php/tl-frazy.php sprzataj
 */
require __DIR__ . '/_testowy-wp.php';
require __DIR__ . '/_ai-atrapa.php';

$krok  = $argv[1] ?? '';
$plik  = sys_get_temp_dir() . '/evk-t-tl-frazy.json';
$opcje = ['tl_languages', 'evk_tl_module_enabled', 'evk_tl_ai', 'evk_tl_ai_pamiec', 'tl_translations', 'evk_tl_frazy_ai', 'tl_dd_keys'];
$out   = ['krok' => $krok];

function evk_tfr_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}
function evk_tfr_grupa(string $gk): array {
    return (array) (get_option('tl_translations')['groups'][$gk]['rows'] ?? []);
}
function evk_tfr_ajax(array $post) {
    $_POST = $_REQUEST = wp_slash($post);
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    add_filter('wp_die_ajax_handler', static function () {
        return static function ($k = '') { if (is_scalar($k)) echo $k; throw new RuntimeException('koniec'); };
    });
    ob_start();
    try { do_action('wp_ajax_' . $post['action']); } catch (RuntimeException $e) { /* koniec */ }
    $w = (string) ob_get_clean();
    return json_decode($w, true) ?? $w;
}

$kto = $krok === 'ajax-krok' && ($argv[4] ?? '') === 'subskrybent' ? 'subskrybent-frazy' : 'admin';
if ($krok !== 'wp' && get_user_by('login', $kto)) wp_set_current_user((int) get_user_by('login', $kto)->ID);

switch ($krok) {

case 'wp':
    $out['wp'] = rtrim(ABSPATH, '/');
    break;

case 'przygotuj':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed]));
    }
    $zapis = evk_tfr_zapis();
    update_option('tl_languages', [['code' => 'en', 'name' => 'Angielski', 'html' => 'en-GB'], ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE']]);
    update_option('evk_tl_module_enabled', 1);
    update_option('evk_tl_ai', ['dostawca' => 'gemini', 'klucze' => ['gemini' => 'test-klucz-g', 'claude' => 'test-klucz-c'], 'opis' => 'Studio']);
    update_option('tl_translations', ['groups' => ['ogolne' => ['name' => 'Ogólne', 'rows' => [
        'row_r1' => ['pl' => 'Kontakt', 'dd_key' => '', 'en' => 'Contact', 'de' => 'Kontakt'],
        'row_r2' => ['pl' => 'Oferta', 'dd_key' => '', 'en' => '', 'de' => ''],
        'row_r3' => ['pl' => 'Nasza firma', 'dd_key' => '', 'en' => '', 'de' => ''],
        'row_r4' => ['pl' => '<strong>Nowość</strong> w ofercie', 'dd_key' => '', 'en' => '', 'de' => ''],
    ]]]]);
    delete_option('evk_tl_frazy_ai');
    delete_option('evk_tl_ai_pamiec');
    tl_invalidate_cache();
    global $wpdb;
    foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_title = 'O nas F'") as $stary) wp_delete_post((int) $stary, true);
    $strona = (int) wp_insert_post(['post_title' => 'O nas F', 'post_type' => 'page', 'post_status' => 'publish']);
    $m = wp_get_nav_menu_object('Główne F');
    if ($m) wp_delete_nav_menu($m->term_id);
    $menu = (int) wp_create_nav_menu('Główne F');
    foreach ([['Oferta', '#oferta'], ['Blog', '#blog'], ['123', '#liczba']] as [$t, $u]) {
        wp_update_nav_menu_item($menu, 0, ['menu-item-title' => $t, 'menu-item-url' => $u, 'menu-item-status' => 'publish', 'menu-item-type' => 'custom']);
    }
    wp_update_nav_menu_item($menu, 0, ['menu-item-object-id' => $strona, 'menu-item-object' => 'page', 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish']);
    $sub = get_user_by('login', 'subskrybent-frazy');
    require_once ABSPATH . 'wp-admin/includes/user.php';
    if ($sub) wp_delete_user($sub->ID);
    wp_insert_user(['user_login' => 'subskrybent-frazy', 'user_pass' => wp_generate_password(20), 'user_email' => 'sf@stara.test', 'role' => 'subscriber']);
    $zapis += ['strona' => $strona, 'menu' => $menu];
    file_put_contents($plik, wp_json_encode($zapis));
    $out['gotowe'] = $strona > 0 && $menu > 0;
    $out['grupy'] = array_keys((array) get_option('tl_translations')['groups']);
    break;

case 'jednostki':
    $out['jednostki'] = array_map(static function ($j) { return [$j['opcje'], $j['tytul'], $j['braki'], (array) $j['ai'], $j['typ']]; },
        evk_tl_ai_jednostki(($argv[2] ?? '') === 'ponownie', ['frazy' => ['frazy']]));
    $out['menu'] = array_values(array_map(static function ($r) { return $r['pl']; }, evk_tfr_grupa('menu')));
    $out['nazwa_menu'] = get_option('tl_translations')['groups']['menu']['name'] ?? null;
    $out['wiersze'] = evk_tl_ai_wiersze_zakresu()['frazy'] ?? null;
    break;

case 'krok':
    $GLOBALS['evk_t_ai_kod'] = (string) ($argv[3] ?? 'en');
    $GLOBALS['evk_t_ai_model'] = (string) ($argv[5] ?? '');
    $out['wynik'] = evk_tl_ai_krok(0, EVK_TL_AI_FRAZY, (string) ($argv[3] ?? 'en'), [],
        ['opcje' => (string) ($argv[2] ?? ''), 'dostawca' => (string) ($argv[4] ?? ''), 'model' => (string) ($argv[5] ?? ''), 'ponownie' => ($argv[6] ?? '') === 'ponownie']);
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    $out['url'] = $GLOBALS['evk_t_ai_zadania'][0]['url'] ?? '';
    $out['wiersze'] = evk_tfr_grupa((string) ($argv[2] ?? ''));
    $out['znaczniki'] = get_option('evk_tl_frazy_ai', null);
    break;

case 'krok-w-trakcie':
    /* W trakcie zapytania do AI ktoś zapisuje tłumaczenie wiersza w zakładce (cały słownik). */
    $GLOBALS['evk_t_ai_kod'] = (string) ($argv[3] ?? 'en');
    $GLOBALS['evk_t_ai_w_trakcie'] = static function () use ($argv): void {
        $d = get_option('tl_translations');
        $d['groups'][$argv[2]]['rows'][$argv[4]][$argv[3]] = 'Wpisane w trakcie';
        update_option('tl_translations', $d);
    };
    $out['wynik'] = evk_tl_ai_krok(0, EVK_TL_AI_FRAZY, (string) ($argv[3] ?? 'en'), [], ['opcje' => (string) ($argv[2] ?? ''), 'ponownie' => true]);
    $out['wiersze'] = evk_tfr_grupa((string) ($argv[2] ?? ''));
    break;

case 'ajax-krok':
    $GLOBALS['evk_t_ai_kod'] = (string) ($argv[3] ?? 'en');
    $out['odp'] = evk_tfr_ajax(['action' => 'evk_tl_ai_krok', 'nonce' => wp_create_nonce('evk_tl_ai'), 'post_id' => 0, 'meta_key' => 'evk_frazy',
        'lang' => (string) ($argv[3] ?? 'en'), 'opcje' => (string) ($argv[2] ?? ''), 'czesci' => ['frazy']]);
    $out['wiersze'] = evk_tfr_grupa((string) ($argv[2] ?? ''));
    break;

case 'zmien':
    $d = get_option('tl_translations');
    $d['groups'][$argv[2]]['rows'][$argv[3]][$argv[4]] = (string) ($argv[5] ?? '');
    update_option('tl_translations', $d);
    tl_invalidate_cache();
    $out['ok'] = true;
    break;

case 'mcp':
    $czesc = 'frazy|0|evk_frazy|' . ($argv[2] ?? '') . '|0';
    $lang = (string) ($argv[3] ?? 'en');
    $p = evk_tl_mcp_pobierz(['czesc' => $czesc, 'jezyk' => $lang]);
    $out['pobierz'] = is_wp_error($p) ? ['blad' => $p->get_error_message()] : $p;
    $tl = [];
    foreach ((array) ($p['teksty'] ?? []) as $t) $tl[] = ['klucz' => $t['klucz'], 'h' => $t['h'], 'tekst' => strtoupper($lang) . '-MCP:' . $t['pl']];
    /* „zmien”: między pobraniem a zapisem ktoś wpisał tłumaczenie pierwszej frazy. */
    if (($argv[4] ?? '') === 'zmien' && !empty($p['teksty'])) {
        $rid = strtok((string) $p['teksty'][0]['klucz'], '|');
        $d = get_option('tl_translations');
        $d['groups'][$argv[2]]['rows'][$rid][$lang] = 'Wpisane ręcznie';
        update_option('tl_translations', $d);
        tl_invalidate_cache();
    }
    $z = $tl ? evk_tl_mcp_zapisz(['czesc' => $czesc, 'jezyk' => $lang, 'model' => 'claude-test', 'tlumaczenia' => $tl]) : null;
    $out['zapisz'] = is_wp_error($z) ? ['blad' => $z->get_error_message()] : $z;
    $out['wiersze'] = evk_tfr_grupa((string) ($argv[2] ?? ''));
    $out['znaczniki'] = get_option('evk_tl_frazy_ai', null);
    $out['braki'] = array_values(array_filter(array_map(static function ($c) { return $c['czesc']; }, (array) (evk_tl_mcp_braki(['jezyk' => $lang])['czesci'] ?? [])),
        static function ($c) { return strpos($c, 'frazy|') === 0; }));
    break;

case 'render':
    $out['html'] = tl_przetworz_html((string) ($argv[3] ?? ''), (string) ($argv[2] ?? 'en'));
    break;

case 'pamiec':
    $out['tl'] = evk_tl_ai_z_pamieci((string) ($argv[3] ?? ''), (string) ($argv[2] ?? 'en'));
    break;

case 'lista':
    $out['lista'] = array_map(static function ($m) { return [$m['klucz'], $m['jezyk'], $m['oryginal'], $m['tlumaczenie'], $m['pole'], $m['meta_key']]; },
        evk_tl_ai_frazy_do_sprawdzenia());
    break;

case 'sprzataj':
    $zapis = evk_tfr_zapis();
    if (!empty($zapis['strona'])) wp_delete_post((int) $zapis['strona'], true);
    if (!empty($zapis['menu'])) wp_delete_nav_menu((int) $zapis['menu']);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $sub = get_user_by('login', 'subskrybent-frazy');
    if ($sub) wp_delete_user($sub->ID);
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $v) {
        if ($v === null) delete_option($o); else update_option($o, $v);
    }
    if (function_exists('tl_invalidate_cache')) tl_invalidate_cache();
    @unlink($plik);
    $out['ok'] = true;
    break;
}

echo wp_json_encode($out);
