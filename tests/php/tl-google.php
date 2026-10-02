<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Google Cloud Translation v3 jako piąty dostawca (1.280.0) — prawdziwy
 * WordPress, Google przez atrapę (tests/php/_ai-atrapa.php). Konto usługi
 * testu ma klucz RSA wygenerowany TU, przy przygotowaniu (żaden prawdziwy
 * klucz nie leży w repozytorium); atrapa sprawdza nim podpis JWT.
 *
 *   php tests/php/tl-google.php wp
 *   php tests/php/tl-google.php przygotuj                języki, para kluczy, strona A
 *   php tests/php/tl-google.php jezyki                   kod języka Google dla każdego języka
 *   php tests/php/tl-google.php ajax-ustawienia <json|zly> <zasobnik> <slowniczek>
 *   php tests/php/tl-google.php krok <jezyk> [scenariusz]
 *   php tests/php/tl-google.php jeden <jezyk> <klucz>
 *   php tests/php/tl-google.php opisy                    model do opisów obrazów przy Google
 *   php tests/php/tl-google.php wyczysc [token]          pola języków strony A (token — także zapamiętany token)
 *   php tests/php/tl-google.php sprzataj
 */
require __DIR__ . '/_testowy-wp.php';
require __DIR__ . '/_ai-atrapa.php';

$krok  = $argv[1] ?? '';
$plik  = sys_get_temp_dir() . '/evk-t-tl-google.json';
$opcje = ['tl_languages', 'evk_tl_module_enabled', 'evk_tl_el_pola', 'evk_tl_ai', 'evk_tl_ai_pamiec', 'evk_tl_google_glosariusze', 'tl_translations'];
$out   = ['krok' => $krok];
$tresc = '_bricks_page_content_2';

function evk_tgo_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}
function evk_tgo_pola(int $id): array {
    $out = [];
    foreach ((array) get_post_meta($id, '_bricks_page_content_2', true) as $el) {
        foreach ((array) ($el['settings'] ?? []) as $k => $v) if (strpos((string) $k, 'evk_tl_') === 0) $out[$el['id'] . '|' . $k] = $v;
    }
    ksort($out);
    return $out;
}
/** Żądania do Google z dziennika atrapy — bez tokenu i bez podpisu (tylko czy są). */
function evk_tgo_zadania(): array {
    return array_map(static function ($z) {
        $b = $z['body'];
        if (is_array($b) && isset($b['assertion'])) $b['assertion'] = substr_count((string) $b['assertion'], '.') === 2 ? 'jwt' : 'zly';
        return ['url' => $z['url'], 'metoda' => $z['metoda'] ?? 'POST', 'auth' => (string) (($z['headers'] ?? [])['Authorization'] ?? '') !== '' ? 'bearer' : '', 'body' => $b];
    }, array_values(array_filter($GLOBALS['evk_t_ai_zadania'], static function ($z) { return strpos($z['url'], 'googleapis.com') !== false && strpos($z['url'], 'generativelanguage') === false; })));
}
function evk_tgo_ajax(array $post) {
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

$zapis = evk_tgo_zapis();
$GLOBALS['evk_t_google_pub'] = (string) ($zapis['pub'] ?? '');
$GLOBALS['evk_t_google_email'] = 'evoke-test@projekt-test.iam.gserviceaccount.com';
if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

switch ($krok) {

case 'wp':
    $out += ['wp' => rtrim(ABSPATH, '/'), 'openssl' => function_exists('openssl_sign')];
    break;

case 'przygotuj':
    if (!isset($zapis['opcje'])) {
        foreach ($opcje as $o) $zapis['opcje'][$o] = get_option($o, null);
    }
    $para = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($para, $priv);
    $zapis['priv'] = $priv;
    $zapis['pub'] = (string) openssl_pkey_get_details($para)['key'];
    update_option('tl_languages', [
        ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-GB'],
        ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
        ['code' => 'pt', 'name' => 'Portugalski', 'html' => 'pt'],
        ['code' => 'br', 'name' => 'Portugalski (Brazylia)', 'html' => 'pt-BR'],
        ['code' => 'tw', 'name' => 'Chiński (Tajwan)', 'html' => 'zh-TW'],
    ]);
    update_option('evk_tl_module_enabled', 1);
    update_option('evk_tl_el_pola', ['heading' => ['pola' => ['text'], 'listy' => []], 'text-basic' => ['pola' => ['text'], 'listy' => []],
        'button' => ['pola' => ['text'], 'listy' => []]], false);
    update_option('tl_translations', ['groups' => []]);
    foreach (['evk_tl_ai_pamiec', 'evk_tl_google_glosariusze', 'evk_tl_ai'] as $o) delete_option($o);
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%evk\\_tl\\_google\\_token\\_%'");
    @unlink(sys_get_temp_dir() . '/evk-t-google-stan.json');
    foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_title = 'Strona Google A'") as $stary) wp_delete_post((int) $stary, true);
    $id = (int) wp_insert_post(['post_title' => 'Strona Google A', 'post_type' => 'page', 'post_status' => 'publish']);
    update_post_meta($id, $tresc, wp_slash([
        ['id' => 'gh1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Nasze usługi w Evoke']],
        ['id' => 'gt1', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => "<p>Witamy na {post_title} &amp; razem</p>\nDruga linia"]],
        ['id' => 'gt2', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => 'Zobacz [tl key=fraza] teraz']],
        ['id' => 'gb1', 'name' => 'button', 'parent' => 0, 'settings' => ['text' => "Zespół & O'Neill"]],
    ]));
    $zapis['strona'] = $id;
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['strona' => $id, 'gotowe' => $id > 0 && $zapis['pub'] !== ''];
    break;

case 'jezyki':
    $out['jezyki'] = [];
    foreach (array_keys(tl_get_languages()) as $j) $out['jezyki'][$j] = evk_tl_google_jezyk((string) $j);
    break;

case 'ajax-ustawienia':
    $json = ($argv[2] ?? '') === 'zly' ? '{"type":"authorized_user","client_id":"x"}'
        : (string) wp_json_encode(['type' => 'service_account', 'project_id' => 'projekt-test', 'private_key_id' => 'kid-test', 'private_key' => (string) ($zapis['priv'] ?? ''),
            'client_email' => $GLOBALS['evk_t_google_email'], 'client_id' => '123', 'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token', 'universe_domain' => 'googleapis.com'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $odp = evk_tgo_ajax(['action' => 'evk_tl_ai_ustawienia', 'nonce' => wp_create_nonce('evk_tl_ai'), 'dostawca' => 'google', 'klucz' => $json,
        'zasobnik' => (string) ($argv[3] ?? ''), 'model' => 'cokolwiek', 'opis' => 'Studio', 'slowniczek' => str_replace('\\n', "\n", (string) ($argv[4] ?? '')),
        'wskazowki' => ['en' => 'British']]);
    $u = get_option('evk_tl_ai');
    $zap = json_decode((string) ($u['klucze']['google'] ?? ''), true);
    $out += ['odp' => $odp, 'dostawca' => $u['dostawca'] ?? null, 'pola_klucza' => is_array($zap) ? array_keys($zap) : null,
        'zasobnik' => $u['zasobnik'] ?? null, 'model_google' => $u['modele']['google'] ?? null, 'podpis' => evk_tl_ai_podpis(evk_tl_ai_ustawienia())];
    break;

case 'krok':
    $id = (int) ($zapis['strona'] ?? 0);
    $GLOBALS['evk_t_ai_scenariusz'] = (string) ($argv[3] ?? 'ok');
    $out['wynik'] = evk_tl_ai_krok($id, $tresc, (string) ($argv[2] ?? 'en'));
    $out['zadania'] = evk_tgo_zadania();
    $out['pola'] = evk_tgo_pola($id);
    $out['glosariusze'] = get_option('evk_tl_google_glosariusze', null);
    $out['stan'] = evk_t_google_stan();
    break;

case 'jeden':
    $id = (int) ($zapis['strona'] ?? 0);
    $out['wynik'] = evk_tl_ai_jeden($id, $tresc, (string) ($argv[2] ?? 'en'), (string) ($argv[3] ?? ''), evk_tl_ai_ustawienia());
    $out['zadania'] = evk_tgo_zadania();
    break;

case 'opisy':
    $u = evk_tl_ai_ustawienia();
    $out['bez'] = evk_tl_ai_dla_opisow($u)['dostawca'];
    $out['opisz'] = evk_tl_ai_opisz_obraz(0, $u);
    $u['klucze']['claude'] = 'test-klucz-c';
    $out['z_claude'] = evk_tl_ai_dla_opisow($u)['dostawca'];
    break;

case 'wyczysc':
    $id = (int) ($zapis['strona'] ?? 0);
    if (function_exists('evk_tl_el_builder_otwarty')) evk_tl_el_builder_otwarty($id);
    $d = get_post_meta($id, $tresc, true);
    foreach ($d as &$el) foreach (array_keys($el['settings']) as $k) if (strpos($k, 'evk_tl_') === 0) unset($el['settings'][$k]);
    unset($el);
    update_post_meta($id, $tresc, wp_slash($d));
    delete_post_meta($id, EVK_TL_EL_STAN);
    delete_option('evk_tl_ai_pamiec');
    if (($argv[2] ?? '') === 'token') {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%evk\\_tl\\_google\\_token\\_%'");
    }
    $out['ok'] = true;
    break;

case 'sprzataj':
    if (!empty($zapis['strona'])) wp_delete_post((int) $zapis['strona'], true);
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $v) {
        if ($v === null) delete_option($o); else update_option($o, $v);
    }
    global $wpdb;
    $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient%evk\\_tl\\_google\\_token\\_%'");
    @unlink(sys_get_temp_dir() . '/evk-t-google-stan.json');
    @unlink($plik);
    $out['ok'] = true;
    break;
}

echo wp_json_encode($out);
