<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * SEO z AI (1.271.0): tytuł, opis i słowa kluczowe SEO w wersjach językowych —
 * hurt (część `evk_seo`), ✦ w zakładce SEO i w metaboksie SEO wpisu, lista
 * „Do sprawdzenia”. Pierwszy testowy WordPress, dostawca AI — atrapa
 * (_ai-atrapa.php, Gemini: „EN:” przed każdym węzłem tekstu).
 *
 *   php tests/php/tl-ai-seo.php wp
 *   php tests/php/tl-ai-seo.php modul                 kopia opcji, moduł Tłumaczeń i języki (osobny proces)
 *   php tests/php/tl-ai-seo.php ustaw                 strony testu z SEO, klucz AI, klasyczny edytor (mu)
 *   php tests/php/tl-ai-seo.php jednostki             lista hurtu z kolumną „SEO” przy Stronach
 *   php tests/php/tl-ai-seo.php krok S L              kroki części „evk_seo” strony S do końca
 *   php tests/php/tl-ai-seo.php stan                  pola SEO stron testu (PL i języki ze źródłami)
 *   php tests/php/tl-ai-seo.php pl S KLUCZ WARTOŚĆ    polskie pole zakładki SEO (_evoke_seo_*)
 *   php tests/php/tl-ai-seo.php lista                 „Do sprawdzenia”: wiersze SEO stron testu i HTML sekcji
 *   php tests/php/tl-ai-seo.php ajax-sprawdzone S K   „Sprawdzone” z listy (prawdziwy AJAX)
 *   php tests/php/tl-ai-seo.php ajax-pola PLIK        ✦ z przeglądarki przez prawdziwy AJAX
 *   php tests/php/tl-ai-seo.php mb-bez-nonce S         zapis metaboksu SEO bez nonce (save_post) — nic nie zmienia
 *   php tests/php/tl-ai-seo.php sprzataj
 *
 * Strony:
 *   S1  „Usługi SEO AI”: tytuł, opis i słowa kluczowe w zakładce SEO;
 *   S2  „Bricks SEO AI”: tytuł w ustawieniach strony Bricksa i inny w zakładce —
 *       tłumaczy się ten z Bricksa (ma pierwszeństwo na stronie);
 *   S3  „Znacznik SEO AI”: tytuł ze znacznikiem `{tl_…}` — tłumaczy się sam, AI go pomija.
 */

$krok = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';
require __DIR__ . '/_ai-atrapa.php';

$plik  = sys_get_temp_dir() . '/evk-t-tl-ai-seo.json';
$mu    = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
$muPlik = $mu . '/evk-t-tl-ai-seo.php';
$opcje = ['evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'tl_url_slugs', 'evk_tl_ai', 'evk_tl_ai_pamiec'];
$out   = ['krok' => $krok];

function evk_tas_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}
function evk_tas_id(string $l): int {
    return (int) ((evk_tas_zapis()['strony'] ?? [])[$l] ?? 0);
}
function evk_tas_ajax(array $post) {
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
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'strony' => []]));
    }
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US'], ['code' => 'de', 'name' => 'Deutsch', 'html' => 'de-DE']]);
    $out['gotowe'] = true;
    break;

case 'ustaw':
    if (!function_exists('evk_tl_ai_teksty_seo')) { $out['brak'] = 'moduł Tłumaczeń nie wczytany — najpierw krok „modul”'; break; }
    $zapis = evk_tas_zapis();
    foreach ((array) ($zapis['strony'] ?? []) as $id) wp_delete_post((int) $id, true);
    update_option('evk_tl_ai', ['dostawca' => 'gemini', 'klucze' => ['gemini' => 'test-klucz-ai-123'], 'modele' => [], 'opis' => '',
        'wskazowki' => [], 'slowniczek' => ''], false);
    delete_option('evk_tl_ai_pamiec');
    wp_mkdir_p($mu);
    file_put_contents($muPlik, "<?php\n// Wyłącznie test tl-ai-seo (tests/php/tl-ai-seo.php) — usuwany po teście.\nadd_filter('use_block_editor_for_post', '__return_false');\n");
    $nowa = static function (string $t): int { return (int) wp_insert_post(['post_type' => 'page', 'post_title' => $t, 'post_status' => 'publish']); };
    $s = [];
    $s['S1'] = $nowa('Usługi SEO AI');
    update_post_meta($s['S1'], '_evoke_seo_title', 'Usługi firmy SEO');
    update_post_meta($s['S1'], '_evoke_seo_desc', 'Opis usług firmy');
    update_post_meta($s['S1'], '_evoke_seo_keywords', 'usługi, firma');
    $s['S2'] = $nowa('Bricks SEO AI');
    update_post_meta($s['S2'], '_bricks_page_settings', ['documentTitle' => 'Tytuł z Bricksa']);
    update_post_meta($s['S2'], '_evoke_seo_title', 'Tytuł z zakładki');
    $s['S3'] = $nowa('Znacznik SEO AI');
    update_post_meta($s['S3'], '_evoke_seo_title', '{tl_seo_ai_test}');
    update_post_meta($s['S3'], '_evoke_seo_desc', 'Opis strony ze znacznikiem');
    $zapis['strony'] = $s;
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['strony' => $s, 'gotowe' => !in_array(0, $s, true)];
    break;

case 'jednostki':
    $ids = array_map('intval', (array) (evk_tas_zapis()['strony'] ?? []));
    $out['wiersze'] = evk_tl_ai_wiersze_zakresu()['page'] ?? null;
    $out['jednostki'] = array_values(array_filter(evk_tl_ai_jednostki(false, evk_tl_ai_zakres_z(['page' => ['seo']])),
        static function ($j) use ($ids) { return in_array((int) $j['post_id'], $ids, true); }));
    break;

case 'krok':
    $id = evk_tas_id((string) ($argv[2] ?? 'S1'));
    $lang = (string) ($argv[3] ?? 'en');
    $GLOBALS['evk_t_ai_kod'] = $lang;
    $pomin = [];
    $out['kroki'] = [];
    for ($i = 0; $i < 10; $i++) {
        $r = evk_tl_ai_krok($id, EVK_TL_AI_SEO, $lang, $pomin, evk_tl_ai_opcje_czesci(['seo']));
        $out['kroki'][] = $r;
        $pomin = array_merge($pomin, $r['odrzucone'], $r['pominiete'], $r['zapisane_klucze']);
        if (!$r['zostalo'] || !empty($r['stop'])) break;
    }
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    $z = end($GLOBALS['evk_t_ai_zadania']);
    $out['wiadomosc'] = $z ? (string) ($z['body']['contents'][0]['parts'][0]['text'] ?? '') : '';
    break;

case 'stan':
    foreach ((array) (evk_tas_zapis()['strony'] ?? []) as $l => $id) {
        $m = [];
        foreach (get_post_meta((int) $id) as $k => $v) {
            if (strpos($k, '_evk_tl_') === 0 || strpos($k, '_evoke_seo_') === 0) $m[$k] = $v[0];
        }
        $out['strony'][$l] = $m;
    }
    $out['skroty'] = ['S1_title' => evk_tlw_zrodlo('Usługi firmy SEO'), 'S1_desc' => evk_tlw_zrodlo('Opis usług firmy'),
        'S1_keywords' => evk_tlw_zrodlo('usługi, firma'), 'S2_title' => evk_tlw_zrodlo('Tytuł z Bricksa')];
    break;

case 'pl':
    update_post_meta(evk_tas_id((string) ($argv[2] ?? '')), '_evoke_seo_' . (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''));
    $out['ok'] = true;
    break;

case 'lista':
    $ids = array_map('intval', (array) (evk_tas_zapis()['strony'] ?? []));
    $out['wiersze'] = array_values(array_filter(evk_tl_ai_seo_do_sprawdzenia(),
        static function ($m) use ($ids) { return in_array((int) $m['post_id'], $ids, true); }));
    ob_start();
    evk_tl_el_sekcja_do_sprawdzenia();
    $out['html'] = (string) ob_get_clean();
    break;

case 'ajax-sprawdzone':
    $out['odp'] = evk_tas_ajax(['action' => 'evk_tl_el_sprawdzone', 'nonce' => wp_create_nonce('evk_tl_el_sprawdzone'),
        'post_id' => (string) evk_tas_id((string) ($argv[2] ?? '')), 'meta_key' => EVK_TL_AI_SEO, 'klucz' => (string) ($argv[3] ?? '')]);
    break;

/* ✦ z przeglądarki: ciało żądania z pliku przez prawdziwy AJAX, nonce świeży dla tego konta. */
case 'ajax-pola':
    parse_str((string) @file_get_contents((string) ($argv[2] ?? '')), $post);
    if (($post['nonce'] ?? '') === 'auto') $post['nonce'] = wp_create_nonce('evk_tl_ai_pola');
    $GLOBALS['evk_t_ai_kod'] = is_string($post['lang'] ?? null) ? $post['lang'] : 'en';
    $out['odp'] = evk_tas_ajax($post);
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    $z = end($GLOBALS['evk_t_ai_zadania']);
    $out['wiadomosc'] = $z ? (string) ($z['body']['contents'][0]['parts'][0]['text'] ?? '') : '';
    break;

/* Formularz metaboksu bez nonce (np. podrzucony z innej strony): zapis wpisu nie może zmienić SEO. */
case 'mb-bez-nonce':
    $id = evk_tas_id((string) ($argv[2] ?? 'S1'));
    $_POST = wp_slash(['evk_seo_mb' => ['pl' => ['title' => 'Podrzucony tytuł'], 'en' => ['title' => 'Injected', 'title__zrodlo' => 'ai']]]);
    do_action('save_post', $id, get_post($id), true);
    $_POST = [];
    $out['tytul'] = (string) get_post_meta($id, '_evoke_seo_title', true);
    $out['en'] = (string) get_post_meta($id, '_evk_tl_en__seo_title', true);
    break;

case 'sprzataj':
    $zapis = evk_tas_zapis();
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['strony'] ?? []) as $id) wp_delete_post((int) $id, true);
    @unlink($muPlik);
    @unlink($plik);
    $out['ok'] = true;
    break;

default:
    $out['brak'] = 'nieznane polecenie: ' . $krok;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
