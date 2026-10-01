<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Kategorie i tagi z AI (1.270.0): nazwa, opis i adres z nazwy — hurt (część
 * `evk_term`), ✦ w edycji termu, lista „Do sprawdzenia”. Pierwszy testowy
 * WordPress, dostawca AI — atrapa (_ai-atrapa.php, Gemini: „EN:” przed każdym
 * węzłem tekstu).
 *
 *   php tests/php/tl-ai-termy.php wp
 *   php tests/php/tl-ai-termy.php modul                    kopia opcji, moduł Tłumaczeń i języki (osobny proces)
 *   php tests/php/tl-ai-termy.php ustaw                    termy testu, strona z adresem, klucz AI
 *   php tests/php/tl-ai-termy.php jednostki POLA [adres]   lista hurtu (POLA: np. name,description; „-” = żadne)
 *   php tests/php/tl-ai-termy.php krok T L POLA [adres]    kroki części „evk_term” termu T do końca
 *   php tests/php/tl-ai-termy.php ajax-krok T L POLA       jeden krok przez prawdziwy AJAX (uprawnienia)
 *   php tests/php/tl-ai-termy.php stan                     tłumaczenia, źródła i mapa adresów termów testu
 *   php tests/php/tl-ai-termy.php zmien T POLE WARTOŚĆ     polski tekst termu (wp_update_term)
 *   php tests/php/tl-ai-termy.php lista                    „Do sprawdzenia”: wiersze termów testu i HTML sekcji
 *   php tests/php/tl-ai-termy.php ajax-sprawdzone T KLUCZ  „Sprawdzone” z listy (prawdziwy AJAX)
 *   php tests/php/tl-ai-termy.php ajax-pola PLIK           ✦ w edycji termu: żądanie z przeglądarki przez prawdziwy AJAX
 *   php tests/php/tl-ai-termy.php sprzataj
 *
 * Termy:
 *   K1  kategoria „Usługi AI” z opisem, bez tłumaczeń;
 *   K2  tag „Promocja AI” z ręczną nazwą EN „Promotion AI” — EN tylko adres;
 *   K3  kategoria „Cennik kat AI” z nazwą EN „Price list kat AI” — adres z niej
 *       to polski adres strony S1, czyli konflikt (bez zapisu, z powodem).
 */

$krok = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';
require __DIR__ . '/_ai-atrapa.php';

$plik  = sys_get_temp_dir() . '/evk-t-tl-ai-termy.json';
$opcje = ['evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'tl_url_slugs', 'evk_tl_ai', 'evk_tl_ai_pamiec'];
$out   = ['krok' => $krok];

function evk_tat_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}
function evk_tat_id(string $l): int {
    return (int) ((evk_tat_zapis()['termy'] ?? [])[$l] ?? 0);
}
/** @return list<string> */
function evk_tat_pola(string $a): array {
    return $a === '-' || $a === '' ? [] : explode(',', $a);
}
function evk_tat_ajax(array $post) {
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
function evk_tat_sprzataj_termy(array $zapis): void {
    foreach ((array) ($zapis['termy'] ?? []) as $l => $id) {
        $t = get_term((int) $id);
        if ($t instanceof WP_Term) wp_delete_term((int) $id, $t->taxonomy);
    }
    foreach ((array) ($zapis['strony'] ?? []) as $id) wp_delete_post((int) $id, true);
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
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'termy' => [], 'strony' => []]));
    }
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US'], ['code' => 'de', 'name' => 'Deutsch', 'html' => 'de-DE']]);
    $out['gotowe'] = true;
    break;

case 'ustaw':
    if (!function_exists('evk_tl_ai_teksty_termu') || !function_exists('evk_tlt_meta')) { $out['brak'] = 'moduł Tłumaczeń nie wczytany — najpierw krok „modul”'; break; }
    $zapis = evk_tat_zapis();
    evk_tat_sprzataj_termy($zapis);
    update_option('tl_translations', ['groups' => []]);
    update_option('tl_url_slugs', []);
    update_option('evk_tl_ai', ['dostawca' => 'gemini', 'klucze' => ['gemini' => 'test-klucz-ai-123'], 'modele' => [], 'opis' => '',
        'wskazowki' => [], 'slowniczek' => ''], false);
    delete_option('evk_tl_ai_pamiec');
    $nowy = static function (string $nazwa, string $tax, string $slug, string $opis = ''): int {
        $r = wp_insert_term($nazwa, $tax, ['slug' => $slug, 'description' => $opis]);
        return is_array($r) ? (int) $r['term_id'] : 0;
    };
    $t = [];
    $t['K1'] = $nowy('Usługi AI', 'category', 'uslugi-ai', 'Krótki opis usług AI');
    $t['K2'] = $nowy('Promocja AI', 'post_tag', 'promocja-ai');
    update_term_meta($t['K2'], '_evk_tl_en__name', 'Promotion AI');
    update_term_meta($t['K2'], '_evk_tl_en__name__zrodlo', evk_tlw_zrodlo('Promocja AI'));
    $t['K3'] = $nowy('Cennik kat AI', 'category', 'cennik-kat-ai');
    update_term_meta($t['K3'], '_evk_tl_en__name', 'Price list kat AI');
    update_term_meta($t['K3'], '_evk_tl_en__name__zrodlo', evk_tlw_zrodlo('Cennik kat AI'));
    $s1 = (int) wp_insert_post(['post_type' => 'page', 'post_title' => 'Lista cen kat', 'post_name' => 'price-list-kat-ai', 'post_status' => 'publish']);
    $zapis['termy'] = $t;
    $zapis['strony'] = [$s1];
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['termy' => $t, 'gotowe' => !in_array(0, $t, true) && $s1 > 0];
    break;

case 'jednostki':
    $ids = array_map('intval', (array) (evk_tat_zapis()['termy'] ?? []));
    $out['jednostki'] = array_values(array_filter(evk_tl_ai_jednostki(false, false, [], false, evk_tat_pola((string) ($argv[2] ?? '-')), ($argv[3] ?? '') === 'adres'),
        static function ($j) use ($ids) { return in_array((int) $j['post_id'], $ids, true) && $j['meta_key'] === EVK_TL_AI_TERM; }));
    break;

case 'krok':
    $id = evk_tat_id((string) ($argv[2] ?? 'K1'));
    $lang = (string) ($argv[3] ?? 'en');
    $GLOBALS['evk_t_ai_kod'] = $lang;
    $opc = ['term_pola' => evk_tat_pola((string) ($argv[4] ?? '-')), 'term_adres' => ($argv[5] ?? '') === 'adres'];
    $pomin = [];
    $out['kroki'] = [];
    for ($i = 0; $i < 10; $i++) {
        $r = evk_tl_ai_krok($id, EVK_TL_AI_TERM, $lang, $pomin, $opc);
        $out['kroki'][] = $r;
        $pomin = array_merge($pomin, $r['odrzucone'], $r['pominiete'], $r['zapisane_klucze']);
        if (!$r['zostalo'] || !empty($r['stop'])) break;
    }
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    $z = end($GLOBALS['evk_t_ai_zadania']);
    $out['wiadomosc'] = $z ? (string) ($z['body']['contents'][0]['parts'][0]['text'] ?? '') : '';
    break;

/* Jeden krok przez prawdziwy AJAX hurtu — uprawnienie do termu, nie do wpisu. Z „bez-prawa” — konto bez edycji termów. */
case 'ajax-krok':
    $GLOBALS['evk_t_ai_kod'] = (string) ($argv[3] ?? 'en');
    if (($argv[5] ?? '') === 'bez-prawa') {
        $u = get_user_by('login', 'evk-t-autor-termy');
        $uid = $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => 'evk-t-autor-termy', 'user_pass' => wp_generate_password(), 'role' => 'author']);
        (new WP_User($uid))->add_cap('evk_access_translations');
        wp_set_current_user($uid);
    }
    $out['odp'] = evk_tat_ajax(['action' => 'evk_tl_ai_krok', 'nonce' => wp_create_nonce('evk_tl_ai'), 'post_id' => (string) evk_tat_id((string) ($argv[2] ?? 'K1')),
        'meta_key' => EVK_TL_AI_TERM, 'lang' => (string) ($argv[3] ?? 'en'), 'term_pola' => evk_tat_pola((string) ($argv[4] ?? '-'))]);
    break;

case 'stan':
    foreach ((array) (evk_tat_zapis()['termy'] ?? []) as $l => $id) {
        $m = [];
        foreach (get_term_meta((int) $id) as $k => $v) {
            if (strpos($k, '_evk_tl_') === 0) $m[$k] = $v[0];
        }
        $out['termy'][$l] = ['meta' => $m];
    }
    $out['mapa'] = get_option('tl_url_slugs');
    $out['skroty'] = ['K1_nazwa' => evk_tlw_zrodlo('Usługi AI'), 'K1_opis' => evk_tlw_zrodlo('Krótki opis usług AI')];
    break;

case 'zmien':
    $id = evk_tat_id((string) ($argv[2] ?? ''));
    $t = get_term($id);
    wp_update_term($id, $t instanceof WP_Term ? $t->taxonomy : 'category', [(string) ($argv[3] ?? '') => (string) ($argv[4] ?? '')]);
    $out['ok'] = true;
    break;

case 'lista':
    $ids = array_map('intval', (array) (evk_tat_zapis()['termy'] ?? []));
    $out['wiersze'] = array_values(array_filter(evk_tl_ai_termy_do_sprawdzenia(),
        static function ($m) use ($ids) { return in_array((int) $m['post_id'], $ids, true); }));
    ob_start();
    evk_tl_el_sekcja_do_sprawdzenia();
    $out['html'] = (string) ob_get_clean();
    break;

case 'ajax-sprawdzone':
    $out['odp'] = evk_tat_ajax(['action' => 'evk_tl_el_sprawdzone', 'nonce' => wp_create_nonce('evk_tl_el_sprawdzone'),
        'post_id' => (string) evk_tat_id((string) ($argv[2] ?? '')), 'meta_key' => EVK_TL_AI_TERM, 'klucz' => (string) ($argv[3] ?? '')]);
    break;

/* ✦ w edycji termu: żądanie przeglądarki (ciało z pliku) przez prawdziwy AJAX, nonce świeży dla tego konta. */
case 'ajax-pola':
    parse_str((string) @file_get_contents((string) ($argv[2] ?? '')), $post);
    if (($post['nonce'] ?? '') === 'auto') $post['nonce'] = wp_create_nonce('evk_tl_ai_pola');
    $GLOBALS['evk_t_ai_kod'] = is_string($post['lang'] ?? null) ? $post['lang'] : 'en';
    $out['odp'] = evk_tat_ajax($post);
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    $z = end($GLOBALS['evk_t_ai_zadania']);
    $out['wiadomosc'] = $z ? (string) ($z['body']['contents'][0]['parts'][0]['text'] ?? '') : '';
    break;

case 'sprzataj':
    $zapis = evk_tat_zapis();
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    evk_tat_sprzataj_termy($zapis);
    $u = get_user_by('login', 'evk-t-autor-termy');
    if ($u) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user((int) $u->ID);
    }
    @unlink($plik);
    $out['ok'] = true;
    break;

default:
    $out['brak'] = 'nieznane polecenie: ' . $krok;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
