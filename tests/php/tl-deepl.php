<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * DeepL jako czwarty dostawca tłumaczenia AI (1.277.0) — prawdziwy WordPress,
 * DeepL przez atrapę (tests/php/_ai-atrapa.php, filtr pre_http_request na
 * prawdziwych adresach api.deepl.com i api-free.deepl.com).
 *
 *   php tests/php/tl-deepl.php wp
 *   php tests/php/tl-deepl.php przygotuj               języki (warianty), mapa pól, moduł, strona A
 *   php tests/php/tl-deepl.php warianty                język docelowy DeepL dla każdego języka z ustawień
 *   php tests/php/tl-deepl.php ajax-ustawienia <free|pro> <slowniczek> [opisy]
 *                                                      zapis ustawień jak z zakładki (formalność EN formalna, DE nieformalna)
 *   php tests/php/tl-deepl.php krok <jezyk> [scenariusz] krok hurtu strony A
 *   php tests/php/tl-deepl.php jeden <jezyk> <klucz>   „Przetłumacz ponownie” jednego tekstu
 *   php tests/php/tl-deepl.php builder <jezyk>         tłumaczenie z buildera (61), bez zapisu
 *   php tests/php/tl-deepl.php opisy <klucze>          dostawca do opisu obrazu przy DeepL („claude,deepl”, „deepl”)
 *   php tests/php/tl-deepl.php wyczysc                 pola języków strony A i stan (następny krok tłumaczy od nowa)
 *   php tests/php/tl-deepl.php sprzataj
 *
 * Strona A: nagłówek z nazwą ze słowniczka, tekst z tagiem {post_title},
 * znakiem „&” i nową linią, tekst ze shortcodem [tl key=…] (zarejestrowany
 * przez moduł), czysty tytuł „Zespół & partnerzy” w przycisku.
 */
require __DIR__ . '/_testowy-wp.php';
require __DIR__ . '/_ai-atrapa.php';

$krok  = $argv[1] ?? '';
$plik  = sys_get_temp_dir() . '/evk-t-tl-deepl.json';
$opcje = ['tl_languages', 'evk_tl_module_enabled', 'evk_tl_el_pola', 'evk_tl_ai', 'evk_tl_ai_pamiec', 'evk_tl_deepl_glosariusze', 'tl_translations'];
$out   = ['krok' => $krok];
$tresc = '_bricks_page_content_2';

function evk_tdl_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}
function evk_tdl_pola(int $id): array {
    $out = [];
    foreach ((array) get_post_meta($id, '_bricks_page_content_2', true) as $el) {
        foreach ((array) ($el['settings'] ?? []) as $k => $v) if (strpos((string) $k, 'evk_tl_') === 0) $out[$el['id'] . '|' . $k] = $v;
    }
    ksort($out);
    return $out;
}
/** Żądania do DeepL z dziennika atrapy — bez klucza w nagłówku (tylko czy jest i jakiego rodzaju). */
function evk_tdl_zadania(): array {
    return array_map(static function ($z) {
        $a = (string) ($z['headers']['Authorization'] ?? '');
        return ['url' => $z['url'], 'metoda' => $z['metoda'] ?? 'POST', 'auth' => strpos($a, 'DeepL-Auth-Key ') === 0 ? (substr($a, -3) === ':fx' ? 'free' : 'pro') : $a,
            'body' => $z['body']];
    }, array_values(array_filter($GLOBALS['evk_t_ai_zadania'], static function ($z) { return strpos($z['url'], 'deepl.com') !== false; })));
}
/** AJAX jak z przeglądarki (wp_send_json kończy się wyjątkiem). */
function evk_tdl_ajax(array $post) {
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

if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

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
    $zapis = evk_tdl_zapis();
    /* Warianty: „en” bez regionu → EN-GB, „us” = en-US, „pt” bez regionu → PT-PT, „br” = pt-BR. */
    update_option('tl_languages', [
        ['code' => 'en', 'name' => 'Angielski', 'html' => 'en'],
        ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
        ['code' => 'us', 'name' => 'Angielski (USA)', 'html' => 'en-US'],
        ['code' => 'pt', 'name' => 'Portugalski', 'html' => 'pt'],
        ['code' => 'br', 'name' => 'Portugalski (Brazylia)', 'html' => 'pt-BR'],
    ]);
    update_option('evk_tl_module_enabled', 1);
    update_option('evk_tl_el_pola', ['heading' => ['pola' => ['text'], 'listy' => []], 'text-basic' => ['pola' => ['text'], 'listy' => []],
        'button' => ['pola' => ['text'], 'listy' => []]], false);
    update_option('tl_translations', ['groups' => []]);
    delete_option('evk_tl_ai_pamiec');
    delete_option('evk_tl_deepl_glosariusze');
    global $wpdb;
    foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_title = 'Strona DeepL A'") as $stary) wp_delete_post((int) $stary, true);
    $id = (int) wp_insert_post(['post_title' => 'Strona DeepL A', 'post_type' => 'page', 'post_status' => 'publish']);
    update_post_meta($id, $tresc, wp_slash([
        ['id' => 'dh1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Nasze usługi w Evoke']],
        ['id' => 'dt1', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => "<p>Witamy na {post_title} &amp; razem</p>\nDruga linia"]],
        ['id' => 'dt2', 'name' => 'text-basic', 'parent' => 0, 'settings' => ['text' => 'Zobacz [tl key=fraza] teraz']],
        ['id' => 'db1', 'name' => 'button', 'parent' => 0, 'settings' => ['text' => 'Zespół & partnerzy']],
    ]));
    $zapis['strona'] = $id;
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['strona' => $id, 'gotowe' => $id > 0];
    break;

case 'warianty':
    $out['warianty'] = [];
    foreach (array_keys(tl_get_languages()) as $j) $out['warianty'][$j] = [evk_tl_deepl_jezyk((string) $j), evk_tl_deepl_jezyk_glosariusza((string) $j)];
    $out['shortcode_tl'] = shortcode_exists('tl');
    break;

case 'ajax-ustawienia':
    $klucz = ($argv[2] ?? 'free') === 'free' ? 'test-klucz-deepl:fx' : 'test-klucz-deepl';
    $odp = evk_tdl_ajax(['action' => 'evk_tl_ai_ustawienia', 'nonce' => wp_create_nonce('evk_tl_ai'), 'dostawca' => 'deepl', 'klucz' => $klucz,
        'model' => 'cokolwiek', 'opis' => 'Studio', 'slowniczek' => str_replace('\\n', "\n", (string) ($argv[3] ?? '')), 'opisy' => (string) ($argv[4] ?? ''),
        'wskazowki' => ['en' => 'British'], 'formalnosc' => ['en' => 'formalna', 'de' => 'nieformalna', 'pt' => 'zla-wartosc']]);
    $u = get_option('evk_tl_ai');
    $out += ['odp' => $odp, 'formalnosc' => $u['formalnosc'] ?? null, 'opisy' => $u['opisy'] ?? null, 'dostawca' => $u['dostawca'] ?? null,
        'model_deepl' => $u['modele']['deepl'] ?? null, 'rodzaj' => evk_tl_ai_model(evk_tl_ai_ustawienia()), 'podpis' => evk_tl_ai_podpis(evk_tl_ai_ustawienia())];
    break;

case 'krok':
    $id = (int) (evk_tdl_zapis()['strona'] ?? 0);
    $GLOBALS['evk_t_ai_scenariusz'] = (string) ($argv[3] ?? 'ok');
    $out['wynik'] = evk_tl_ai_krok($id, $tresc, (string) ($argv[2] ?? 'en'));
    $out['zadania'] = evk_tdl_zadania();
    $out['pola'] = evk_tdl_pola($id);
    $out['glosariusze'] = get_option('evk_tl_deepl_glosariusze', null);
    break;

case 'jeden':
    $id = (int) (evk_tdl_zapis()['strona'] ?? 0);
    $out['wynik'] = evk_tl_ai_jeden($id, $tresc, (string) ($argv[2] ?? 'en'), (string) ($argv[3] ?? ''), evk_tl_ai_ustawienia());
    $out['zadania'] = evk_tdl_zadania();
    break;

case 'builder':
    $id = (int) (evk_tdl_zapis()['strona'] ?? 0);
    $kontekst = [['el' => 'heading', 'pole' => 'text', 'poz' => 0, 'pl' => 'Nagłówek z buildera', 'tl' => ''],
        ['el' => 'text-basic', 'pole' => 'text', 'poz' => 0, 'pl' => 'Tekst z buildera {post_title}', 'tl' => '']];
    $we = evk_tl_ai_builder_wejscie($kontekst, ['k1' => ['n' => 1, 'bylo' => ''], 'k2' => ['n' => 2, 'bylo' => '']]);
    $out['wynik'] = is_array($we) ? evk_tl_ai_builder($id, (string) ($argv[2] ?? 'en'), $we[0], $we[1]) : $we;
    $out['zadania'] = evk_tdl_zadania();
    break;

case 'opisy':
    $u = evk_tl_ai_ustawienia();
    $u['dostawca'] = 'deepl';
    $u['klucze'] = array_fill_keys(array_filter(explode(',', (string) ($argv[2] ?? ''))), 'test-klucz');
    $u['opisy'] = (string) ($argv[3] ?? '');
    $out['dla_opisow'] = evk_tl_ai_dla_opisow($u)['dostawca'];
    $id = (int) wp_insert_post(['post_title' => 'obraz-deepl', 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image/png']);
    $r = evk_tl_ai_opisz_obraz($id, $u);
    wp_delete_post($id, true);
    $out['opisz'] = $r;
    break;

case 'wyczysc':
    $id = (int) (evk_tdl_zapis()['strona'] ?? 0);
    /* Jak w tl-ai: builder otwarty — inaczej hak 53 wpisałby dopisane pola z powrotem. */
    evk_tl_el_builder_otwarty($id);
    $d = (array) get_post_meta($id, $tresc, true);
    foreach ($d as &$el) {
        foreach (array_keys((array) ($el['settings'] ?? [])) as $k) if (strpos((string) $k, 'evk_tl_') === 0) unset($el['settings'][$k]);
    }
    unset($el);
    update_post_meta($id, $tresc, wp_slash($d));
    delete_post_meta($id, EVK_TL_EL_STAN);
    delete_option('evk_tl_ai_pamiec');
    $out['ok'] = true;
    break;

case 'sprzataj':
    $zapis = evk_tdl_zapis();
    if (!empty($zapis['strona'])) wp_delete_post((int) $zapis['strona'], true);
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    @unlink($plik);
    $out['ok'] = true;
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
