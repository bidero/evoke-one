<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Claude Desktop przez MCP (1.278.0) — prawdziwy WordPress i prawdziwy
 * MCP Adapter z paczki wydania (EVK_MCP_ZIP, tools/testowy-wp.sh). Sonda
 * stawia stan; same wywołania MCP idą w teście przez HTTP (php -S).
 *
 *   php tests/php/tl-mcp.php wp
 *   php tests/php/tl-mcp.php przygotuj          języki, ustawienia AI, słownik, strony A i B, użytkownicy z hasłami aplikacji
 *   php tests/php/tl-mcp.php ajax-adapter [kto] przycisk „Zainstaluj MCP Adapter” (prawdziwy AJAX; kto: admin | tlumacz | zly — admin, zepsuta paczka)
 *   php tests/php/tl-mcp.php ajax-haslo [http]  przycisk „Utwórz hasło aplikacji” (http — bez HTTPS i bez środowiska local)
 *   php tests/php/tl-mcp.php stan <jezyk>       pola tłumaczeń strony A i stan „Do sprawdzenia”
 *   php tests/php/tl-mcp.php zmien              zmiana polskiego tekstu at1 na stronie A
 *   php tests/php/tl-mcp.php sprawdzone <klucz> „Sprawdzone” na jednym miejscu strony A
 *   php tests/php/tl-mcp.php sprzataj
 *
 * Strona A: nagłówek, tekst z <p>…{post_title}…</p>, przycisk „Kontakt”
 * (w słowniku fraz — pamięć tłumaczeń). Strona B: 65 krótkich tekstów
 * (porcja MCP to 60).
 */
/* Hasła aplikacji WordPress daje tylko przez HTTPS albo w środowisku „local”
   — jak serwer testu (EVK_WP_SRODOWISKO w routerze). Krok „ajax-haslo http”
   sprawdza stronę bez tego. */
if (!(($argv[1] ?? '') === 'ajax-haslo' && ($argv[2] ?? '') === 'http')) define('WP_ENVIRONMENT_TYPE', 'local');
require __DIR__ . '/_testowy-wp.php';

$krok  = $argv[1] ?? '';
$plik  = sys_get_temp_dir() . '/evk-t-tl-mcp.json';
$opcje = ['tl_languages', 'evk_tl_module_enabled', 'evk_tl_el_pola', 'evk_tl_ai', 'evk_tl_ai_pamiec', 'tl_translations', 'active_plugins'];
$out   = ['krok' => $krok];
$tresc = '_bricks_page_content_2';
$zip   = getenv('EVK_MCP_ZIP') ?: (getenv('HOME') . '/.cache/evk-mcp-adapter.zip');

function evk_tmc_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}
/** AJAX jak z przeglądarki (wp_send_json kończy się wyjątkiem). */
function evk_tmc_ajax(array $post) {
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
function evk_tmc_uzytkownik(string $login, string $rola, bool $tlumacz): int {
    $u = get_user_by('login', $login);
    if ($u) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u->ID); }
    $id = (int) wp_insert_user(['user_login' => $login, 'user_pass' => wp_generate_password(20), 'user_email' => $login . '@stara.test', 'role' => $rola]);
    if ($tlumacz) (new WP_User($id))->add_cap('evk_access_translations');
    return $id;
}

if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', ($argv[2] ?? '') === 'tlumacz' ? 'tlumacz-mcp' : 'admin')->ID);

switch ($krok) {

case 'wp':
    $out += ['wp' => rtrim(ABSPATH, '/'), 'zip' => is_file($zip) ? $zip : '', 'api' => function_exists('wp_register_ability')];
    break;

case 'przygotuj':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed]));
    }
    $zapis = evk_tmc_zapis();
    update_option('tl_languages', [['code' => 'en', 'name' => 'Angielski', 'html' => 'en-GB'], ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE']]);
    update_option('evk_tl_module_enabled', 1);
    update_option('evk_tl_el_pola', ['heading' => ['pola' => ['text'], 'listy' => []], 'text-basic' => ['pola' => ['text'], 'listy' => []],
        'button' => ['pola' => ['text'], 'listy' => []]], false);
    update_option('evk_tl_ai', ['dostawca' => 'gemini', 'klucze' => [], 'opis' => 'Studio projektowe Evoke z Krakowa.',
        'wskazowki' => ['en' => 'British spelling.'], 'slowniczek' => "usługi | services | Leistungen\n!Evoke"]);
    update_option('tl_translations', ['groups' => [['name' => 'Ogólne', 'rows' => [['pl' => 'Kontakt', 'en' => 'Contact', 'de' => 'Kontakt']]]]]);
    delete_option('evk_tl_ai_pamiec');
    /* Słownik fraz siedzi też w transiencie konfiguracji — zapis panelu go czyści. */
    tl_invalidate_cache();
    global $wpdb;
    foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_title IN ('Strona MCP A', 'Strona MCP B')") as $stary) wp_delete_post((int) $stary, true);
    $a = (int) wp_insert_post(['post_title' => 'Strona MCP A', 'post_type' => 'page', 'post_status' => 'publish']);
    update_post_meta($a, $tresc, wp_slash([
        ['id' => 'ah1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Nasze usługi w Evoke']],
        ['id' => 'at1', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => '<p>Witamy na <strong>{post_title}</strong></p>']],
        ['id' => 'ab1', 'name' => 'button', 'parent' => 0, 'settings' => ['text' => 'Kontakt']],
    ]));
    $b = (int) wp_insert_post(['post_title' => 'Strona MCP B', 'post_type' => 'page', 'post_status' => 'publish']);
    $el = [];
    for ($i = 1; $i <= 65; $i++) $el[] = ['id' => 'bt' . $i, 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => 'Akapit numer ' . $i]];
    update_post_meta($b, $tresc, wp_slash($el));
    /* Hasła aplikacji: administrator, tłumacz (redaktor z prawem Tłumaczeń),
       redaktor bez tego prawa, subskrybent. */
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $hasla = [];
    foreach (['tlumacz-mcp' => ['editor', true], 'redaktor-mcp' => ['editor', false], 'subskrybent-mcp' => ['subscriber', false]] as $login => [$rola, $tl]) {
        evk_tmc_uzytkownik($login, $rola, $tl);
    }
    foreach (['admin', 'tlumacz-mcp', 'redaktor-mcp', 'subskrybent-mcp'] as $login) {
        $u = get_user_by('login', $login);
        WP_Application_Passwords::delete_all_application_passwords($u->ID);
        $hasla[$login] = (string) WP_Application_Passwords::create_new_application_password($u->ID, ['name' => 'test MCP'])[0];
    }
    $zapis += ['a' => $a, 'b' => $b];
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['a' => $a, 'b' => $b, 'hasla' => $hasla, 'gotowe' => $a > 0 && $b > 0, 'zip' => is_file($zip)];
    break;

case 'ajax-adapter':
    $kopia = sys_get_temp_dir() . '/evk-t-mcp-adapter-' . getmypid() . '.zip';
    /* „zly”: paczka, która nie jest zipem — instalacja ma skończyć się komunikatem. */
    if (($argv[2] ?? '') === 'zly') file_put_contents($kopia, 'to nie jest zip'); else copy($zip, $kopia);
    add_filter('evk_tl_mcp_adapter_zip', static function () use ($kopia) { return $kopia; });
    $out['przed'] = evk_tl_mcp_adapter_stan();
    $out['odp'] = evk_tmc_ajax(['action' => 'evk_tl_mcp_adapter', 'nonce' => wp_create_nonce('evk_tl_mcp')]);
    $out['po'] = evk_tl_mcp_adapter_stan();
    $out['aktywne'] = array_values((array) get_option('active_plugins'));
    @unlink($kopia);
    break;

case 'ajax-haslo':
    $out['odp'] = evk_tmc_ajax(['action' => 'evk_tl_mcp_haslo', 'nonce' => wp_create_nonce('evk_tl_mcp')]);
    $out['nazwy'] = array_column(WP_Application_Passwords::get_user_application_passwords(get_current_user_id()), 'name');
    break;

case 'stan':
    $id = (int) (evk_tmc_zapis()['a'] ?? 0);
    $lang = (string) ($argv[2] ?? 'en');
    $pola = [];
    foreach ((array) get_post_meta($id, $tresc, true) as $el) $pola[$el['id']] = $el['settings']['evk_tl_' . $lang . '__text'] ?? null;
    $stan = (array) (get_post_meta($id, EVK_TL_EL_STAN, true)[$tresc] ?? []);
    $out['pola'] = $pola;
    $out['stan'] = array_map(static function ($s) { return ['src' => $s['src'] ?? null, 'model' => $s['model'] ?? null]; },
        array_filter($stan, static function ($k) use ($lang) { return substr($k, -strlen('|' . $lang)) === '|' . $lang; }, ARRAY_FILTER_USE_KEY));
    break;

case 'zmien':
    $id = (int) (evk_tmc_zapis()['a'] ?? 0);
    $d = get_post_meta($id, $tresc, true);
    foreach ($d as &$el) if ($el['id'] === 'at1') $el['settings']['text'] = '<p>Witamy serdecznie na <strong>{post_title}</strong></p>';
    unset($el);
    update_post_meta($id, $tresc, wp_slash($d));
    $out['ok'] = true;
    break;

case 'sprawdzone':
    $id = (int) (evk_tmc_zapis()['a'] ?? 0);
    $out['ok'] = evk_tl_el_oznacz_sprawdzone($id, $tresc, (string) ($argv[2] ?? ''));
    break;

case 'sprzataj':
    $zapis = evk_tmc_zapis();
    /* MCP Adapter zainstalowany przez test — wyłączony i usunięty (to zwykły
       katalog z paczki, nie dowiązanie). */
    if (function_exists('evk_tl_mcp_adapter_stan')) {
        $s = evk_tl_mcp_adapter_stan();
        if ($s['plik'] !== '') {
            deactivate_plugins($s['plik'], true);
            $kat = WP_PLUGIN_DIR . '/' . dirname($s['plik']);
            if (dirname($s['plik']) !== '.' && is_dir($kat) && !is_link($kat)) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
                WP_Filesystem();
                $GLOBALS['wp_filesystem']->delete($kat, true);
            }
        }
    }
    foreach (['a', 'b'] as $k) if (!empty($zapis[$k])) wp_delete_post((int) $zapis[$k], true);
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach (['tlumacz-mcp', 'redaktor-mcp', 'subskrybent-mcp'] as $login) {
        $u = get_user_by('login', $login);
        if ($u) wp_delete_user($u->ID);
    }
    WP_Application_Passwords::delete_all_application_passwords((int) get_user_by('login', 'admin')->ID);
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $v) {
        if ($v === null) delete_option($o); else update_option($o, $v);
    }
    if (function_exists('tl_invalidate_cache')) tl_invalidate_cache();
    @unlink($plik);
    $out['ok'] = true;
    break;
}

echo wp_json_encode($out);
