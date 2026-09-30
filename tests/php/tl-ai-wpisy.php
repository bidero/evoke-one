<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Hurt AI tekstów wpisów (1.268.0): tytuł, treść, zajawka i adres z tytułu —
 * pierwszy testowy WordPress, dostawca AI — atrapa (_ai-atrapa.php, Gemini:
 * „EN:” przed każdym węzłem tekstu).
 *
 *   php tests/php/tl-ai-wpisy.php wp
 *   php tests/php/tl-ai-wpisy.php modul                    kopia opcji, moduł Tłumaczeń i języki (osobny proces)
 *   php tests/php/tl-ai-wpisy.php ustaw                    wpisy testu, języki, klucz AI, klasyczny edytor (mu)
 *   php tests/php/tl-ai-wpisy.php jednostki POLA [adres]   lista hurtu z flagami (POLA: np. post_title,post_content; „-” = żadne)
 *   php tests/php/tl-ai-wpisy.php krok W L POLA [adres]    kroki części „evk_wpis” wpisu W do końca
 *   php tests/php/tl-ai-wpisy.php stan                     tłumaczenia, źródła i mapa adresów wpisów testu
 *   php tests/php/tl-ai-wpisy.php zmien W POLE WARTOŚĆ     polski tekst wpisu (wp_update_post)
 *   php tests/php/tl-ai-wpisy.php lista                    „Do sprawdzenia”: wiersze wpisów testu i HTML sekcji
 *   php tests/php/tl-ai-wpisy.php ajax-sprawdzone W KLUCZ  „Sprawdzone” z listy (prawdziwy AJAX)
 *   php tests/php/tl-ai-wpisy.php sprzataj
 *
 * Wpisy:
 *   P1  wpis „Nasza nowa oferta AI”: treść, zajawka, bez tłumaczeń;
 *   P2  strona w Bricksie (_bricks_editor_mode = bricks): treść WordPressa pomijana,
 *       elementy Bricksa — kontekst;
 *   P3  strona „Kontakt AI”: tytuł EN wpisany ręcznie („Contact AI”) — sam adres;
 *   P4  strona z polskim adresem „price-list-ai”;
 *   P5  strona „Cennik AI” z tytułem EN „Price list AI” — adres z jej tytułu
 *       to polski adres P4, czyli konflikt (bez zapisu, z powodem).
 */

$krok = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';
require __DIR__ . '/_ai-atrapa.php';

$plik  = sys_get_temp_dir() . '/evk-t-tl-ai-wpisy.json';
$mu    = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
$muPlik = $mu . '/evk-t-tl-ai-wpisy.php';
$opcje = ['evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'tl_url_slugs', 'evk_tl_ai', 'evk_tl_ai_pamiec', 'evk_tl_el_pola'];
$out   = ['krok' => $krok];

function evk_taw_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}
function evk_taw_id(string $l): int {
    return (int) ((evk_taw_zapis()['wpisy'] ?? [])[$l] ?? 0);
}
/** @return list<string> */
function evk_taw_pola(string $a): array {
    return $a === '-' || $a === '' ? [] : explode(',', $a);
}
function evk_taw_ajax(array $post) {
    $_POST = $_REQUEST = wp_slash($post);
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    add_filter('wp_die_ajax_handler', static function () {
        return static function ($komunikat = '') {
            if (is_scalar($komunikat)) echo $komunikat;
            throw new RuntimeException('koniec');
        };
    });
    ob_start();
    try {
        do_action('wp_ajax_' . $post['action']);
    } catch (RuntimeException $e) {
        // wp_send_json() kończy tu.
    }
    $w = (string) ob_get_clean();
    return json_decode($w, true) ?? $w;
}

if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

switch ($krok) {

case 'wp':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

/* Moduł Tłumaczeń wczytuje się przy starcie procesu — włączony tu, działa od następnego kroku. */
case 'modul':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'wpisy' => []]));
    }
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US'], ['code' => 'de', 'name' => 'Deutsch', 'html' => 'de-DE']]);
    $out['gotowe'] = true;
    break;

case 'ustaw':
    if (!function_exists('evk_tl_ai_teksty_wpisu')) { $out['brak'] = 'moduł Tłumaczeń nie wczytany — najpierw krok „modul”'; break; }
    $zapis = evk_taw_zapis();
    foreach ((array) ($zapis['wpisy'] ?? []) as $id) wp_delete_post((int) $id, true);
    update_option('tl_translations', ['groups' => []]);
    update_option('tl_url_slugs', []);
    update_option('evk_tl_ai', ['dostawca' => 'gemini', 'klucze' => ['gemini' => 'test-klucz-ai-123'], 'modele' => [], 'opis' => '',
        'wskazowki' => [], 'slowniczek' => ''], false);
    delete_option('evk_tl_ai_pamiec');
    evk_tl_el_kontrolki(['text' => ['tab' => 'content', 'type' => 'text'], 'tag' => ['tab' => 'content', 'type' => 'select']], 'heading');
    evk_tl_el_zapisz_mape();
    // Klasyczny edytor jak u zgłaszającego — tylko na czas testu.
    wp_mkdir_p($mu);
    file_put_contents($muPlik, "<?php\n// Wyłącznie test tl-ai-wpisy (tests/php/tl-ai-wpisy.php) — usuwany po teście.\nadd_filter('use_block_editor_for_post', '__return_false');\n");

    $w = [];
    $nowy = static function (array $a): int { return (int) wp_insert_post($a + ['post_status' => 'publish']); };
    $w['P1'] = $nowy(['post_type' => 'post', 'post_title' => 'Nasza nowa oferta AI', 'post_name' => 'nasza-nowa-oferta-ai',
        'post_content' => '<p>Treść wpisu o <strong>ofercie</strong>.</p>', 'post_excerpt' => 'Krótka zajawka oferty']);
    $w['P2'] = $nowy(['post_type' => 'page', 'post_title' => 'Strona Bricks AI', 'post_name' => 'strona-bricks-ai', 'post_content' => 'Stara treść WordPressa']);
    update_post_meta($w['P2'], '_bricks_editor_mode', 'bricks');
    update_post_meta($w['P2'], '_bricks_page_content_2', [['id' => 'ab1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Tekst z Bricksa']]]);
    $w['P3'] = $nowy(['post_type' => 'page', 'post_title' => 'Kontakt AI', 'post_name' => 'kontakt-ai']);
    update_post_meta($w['P3'], '_evk_tl_en__post_title', 'Contact AI');
    update_post_meta($w['P3'], '_evk_tl_en__post_title__zrodlo', evk_tlw_zrodlo('Kontakt AI'));
    $w['P4'] = $nowy(['post_type' => 'page', 'post_title' => 'Lista cen', 'post_name' => 'price-list-ai']);
    $w['P5'] = $nowy(['post_type' => 'page', 'post_title' => 'Cennik AI', 'post_name' => 'cennik-ai']);
    update_post_meta($w['P5'], '_evk_tl_en__post_title', 'Price list AI');
    update_post_meta($w['P5'], '_evk_tl_en__post_title__zrodlo', evk_tlw_zrodlo('Cennik AI'));
    $zapis['wpisy'] = $w;
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['wpisy' => $w, 'gotowe' => true];
    break;

case 'jednostki':
    $ids = array_map('intval', (array) (evk_taw_zapis()['wpisy'] ?? []));
    $out['jednostki'] = array_values(array_filter(evk_tl_ai_jednostki(false, false, evk_taw_pola((string) ($argv[2] ?? '-')), ($argv[3] ?? '') === 'adres'),
        static function ($j) use ($ids) { return in_array((int) $j['post_id'], $ids, true) && $j['meta_key'] === EVK_TL_AI_WPIS; }));
    break;

case 'krok':
    $id = evk_taw_id((string) ($argv[2] ?? 'P1'));
    $lang = (string) ($argv[3] ?? 'en');
    $GLOBALS['evk_t_ai_kod'] = $lang;
    $opc = ['wpis_pola' => evk_taw_pola((string) ($argv[4] ?? '-')), 'adres' => ($argv[5] ?? '') === 'adres'];
    $pomin = [];
    $out['kroki'] = [];
    for ($i = 0; $i < 10; $i++) {
        $r = evk_tl_ai_krok($id, EVK_TL_AI_WPIS, $lang, $pomin, $opc);
        $out['kroki'][] = $r;
        $pomin = array_merge($pomin, $r['odrzucone'], $r['pominiete'], $r['zapisane_klucze']);
        if (!$r['zostalo'] || !empty($r['stop'])) break;
    }
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    $z = end($GLOBALS['evk_t_ai_zadania']);
    $out['wiadomosc'] = $z ? (string) ($z['body']['contents'][0]['parts'][0]['text'] ?? '') : '';
    break;

case 'stan':
    foreach ((array) (evk_taw_zapis()['wpisy'] ?? []) as $l => $id) {
        $m = [];
        foreach (get_post_meta((int) $id) as $k => $v) {
            if (strpos($k, '_evk_tl_') === 0) $m[$k] = $v[0];
        }
        $out['wpisy'][$l] = ['meta' => $m, 'post_content' => get_post_field('post_content', (int) $id, 'raw')];
    }
    $out['mapa'] = get_option('tl_url_slugs');
    $out['skroty'] = ['P1_tytul' => evk_tlw_zrodlo('Nasza nowa oferta AI'), 'P1_tresc' => evk_tlw_zrodlo('<p>Treść wpisu o <strong>ofercie</strong>.</p>'),
        'P1_zajawka' => evk_tlw_zrodlo('Krótka zajawka oferty')];
    break;

case 'zmien':
    wp_update_post(['ID' => evk_taw_id((string) ($argv[2] ?? '')), (string) ($argv[3] ?? '') => (string) ($argv[4] ?? '')]);
    $out['ok'] = true;
    break;

case 'lista':
    $ids = array_map('intval', (array) (evk_taw_zapis()['wpisy'] ?? []));
    $out['wiersze'] = array_values(array_filter(evk_tl_ai_wpisy_do_sprawdzenia(),
        static function ($m) use ($ids) { return in_array((int) $m['post_id'], $ids, true); }));
    ob_start();
    evk_tl_el_sekcja_do_sprawdzenia();
    $out['html'] = (string) ob_get_clean();
    break;

case 'ajax-sprawdzone':
    $out['odp'] = evk_taw_ajax(['action' => 'evk_tl_el_sprawdzone', 'nonce' => wp_create_nonce('evk_tl_el_sprawdzone'),
        'post_id' => (string) evk_taw_id((string) ($argv[2] ?? '')), 'meta_key' => EVK_TL_AI_WPIS, 'klucz' => (string) ($argv[3] ?? '')]);
    break;

case 'sprzataj':
    $zapis = evk_taw_zapis();
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['wpisy'] ?? []) as $id) wp_delete_post((int) $id, true);
    @unlink($muPlik);
    @unlink($plik);
    $out['ok'] = true;
    break;

default:
    $out['brak'] = 'nieznane polecenie: ' . $krok;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
