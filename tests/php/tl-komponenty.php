<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Komponenty Bricksa a tłumaczenia (1.272.0): właściwości „… EN” przy zapisie
 * komponentów i po zmianie języków, teksty właściwości instancji w hurcie AI,
 * liście tekstów i „Do sprawdzenia”, teksty stałe komponentów. Pierwszy
 * testowy WordPress, AI — atrapa (_ai-atrapa.php, „EN:” przed tekstem).
 * Bricksa tu nie ma: opcja komponentów pod nazwą z Bricksa 2.4 (`bricks_components`),
 * kształt z próby na testowej (docs/proby-komponenty.md).
 *
 *   php tests/php/tl-komponenty.php wp
 *   php tests/php/tl-komponenty.php modul            kopia opcji, moduł Tłumaczeń, języki EN i DE (osobny proces)
 *   php tests/php/tl-komponenty.php wektory PLIK     czysta funkcja: przypadki z pliku (te same co w JS) i identyfikatory
 *   php tests/php/tl-komponenty.php ustaw            mapa pól, komponenty K1 i K2 (zapis opcją — z filtrem), strona z instancjami
 *   php tests/php/tl-komponenty.php opcja            opcja komponentów
 *   php tests/php/tl-komponenty.php jezyki KODY      nowa lista języków („en,de,fr”) — przejście po komponentach
 *   php tests/php/tl-komponenty.php jednostki        lista hurtu: strona testu i komponenty
 *   php tests/php/tl-komponenty.php krok L           kroki treści strony testu w języku L
 *   php tests/php/tl-komponenty.php krok-kp CID L    kroki tekstów stałych komponentu
 *   php tests/php/tl-komponenty.php ajax-krok-kp KTO CID L   to samo przez AJAX (admin / redaktor)
 *   php tests/php/tl-komponenty.php strona           treść strony testu
 *   php tests/php/tl-komponenty.php lista            „Do sprawdzenia”: wiersze strony testu i komponentów, HTML sekcji
 *   php tests/php/tl-komponenty.php teksty           „Teksty w elementach”: wiersze strony testu
 *   php tests/php/tl-komponenty.php ajax-sprawdzone META KLUCZ [POST]
 *   php tests/php/tl-komponenty.php sprzataj
 */

$krok = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';
require __DIR__ . '/_ai-atrapa.php';

$plik  = sys_get_temp_dir() . '/evk-t-tl-komponenty.json';
$opcje = ['evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'evk_tl_ai', 'evk_tl_ai_pamiec', 'evk_tl_el_pola',
    'bricks_components', 'evk_tl_kp_stan', 'evk_tl_kp_przejscie'];
$out   = ['krok' => $krok];
const EVK_TKP_TYTUL = 'Strona komponentów KP';

function evk_tkp_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}
function evk_tkp_strona(): int {
    return (int) (evk_tkp_zapis()['strona'] ?? 0);
}
function evk_tkp_ajax(array $post) {
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
        file_put_contents($plik, wp_json_encode(['opcje' => $przed]));
    }
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US'], ['code' => 'de', 'name' => 'Deutsch', 'html' => 'de-DE']]);
    $out['gotowe'] = true;
    break;

case 'wektory':
    if (!function_exists('evk_tl_kp_uzupelnij')) { $out['brak'] = 'moduł Tłumaczeń nie wczytany — najpierw krok „modul”'; break; }
    $w = json_decode((string) file_get_contents((string) ($argv[2] ?? '')), true);
    $out['przypadki'] = [];
    foreach ((array) ($w['przypadki'] ?? []) as $p) $out['przypadki'][] = evk_tl_kp_uzupelnij((array) $p['komponenty'], (array) $p['jezyki'], (array) $p['mapa']);
    $out['idy'] = array_map(static function ($x) { return evk_tl_kp_id((string) $x[0], (string) $x[1], (string) $x[2]); }, (array) ($w['idy'] ?? []));
    break;

case 'ustaw':
    if (!function_exists('evk_tl_kp_uzupelnij')) { $out['brak'] = 'moduł Tłumaczeń nie wczytany — najpierw krok „modul”'; break; }
    $zapis = evk_tkp_zapis();
    update_option('evk_tl_ai', ['dostawca' => 'gemini', 'klucze' => ['gemini' => 'test-klucz-ai-123'], 'modele' => [], 'opis' => '',
        'wskazowki' => [], 'slowniczek' => ''], false);
    delete_option('evk_tl_ai_pamiec');
    delete_option('evk_tl_kp_stan');
    /* Mapa pól tak, jak zapisałby ją builder (prawdziwy filtr kontrolek, jak w fields-ai). */
    evk_tl_el_kontrolki(['text' => ['tab' => 'content', 'type' => 'text'], 'tag' => ['tab' => 'content', 'type' => 'select']], 'heading');
    evk_tl_el_kontrolki(['text' => ['tab' => 'content', 'type' => 'editor']], 'text-basic');
    evk_tl_el_kontrolki(['text' => ['tab' => 'content', 'type' => 'text'], 'link' => ['tab' => 'content', 'type' => 'link']], 'button');
    evk_tl_el_zapisz_mape();
    /* K1 jak „Testowy” z próby: nagłówek i tekst z właściwościami, do tego tekst stały.
       Nagłówek ma tłumaczenie z komponentu — to ono pokazywało się w instancji z innym tekstem.
       K2 jak przycisk z próby: ręczna właściwość „Tłumaczenie” połączona z `evk_tl_en__text`. */
    update_option('bricks_components', [
        ['id' => 'kpkomp', 'category' => '', 'desc' => '', 'elements' => [
            ['id' => 'kpkomp', 'name' => 'block', 'parent' => 0, 'children' => ['kpnagl', 'kptekst', 'kpstal'], 'settings' => [], 'label' => 'Karta oferty'],
            ['id' => 'kpnagl', 'name' => 'heading', 'parent' => 'kpkomp', 'children' => [], 'settings' => ['text' => 'Nagłówek komponentu', 'evk_tl_en__text' => 'KOMPONENT EN']],
            ['id' => 'kptekst', 'name' => 'text-basic', 'parent' => 'kpkomp', 'children' => [], 'settings' => ['text' => 'Tekst komponentu']],
            ['id' => 'kpstal', 'name' => 'text-basic', 'parent' => 'kpkomp', 'children' => [], 'settings' => ['text' => 'Tekst stały komponentu']],
        ], 'properties' => [
            ['label' => 'Tekst', 'type' => 'text', 'id' => 'kpwtek', 'connections' => ['kptekst' => ['text']]],
            ['label' => 'Nagłówek', 'type' => 'text', 'id' => 'kpwnag', 'connections' => ['kpnagl' => ['text']]],
        ]],
        ['id' => 'kpprzy', 'category' => '', 'desc' => '', 'elements' => [
            ['id' => 'kpprzy', 'name' => 'button', 'parent' => 0, 'children' => [], 'settings' => ['text' => 'Przycisk komponentu', 'evk_tl_en__text' => 'BUTTON EN'], 'label' => 'Przycisk'],
        ], 'properties' => [
            ['label' => 'Treść', 'type' => 'text', 'id' => 'kpwtre', 'connections' => ['kpprzy' => ['text']]],
            ['label' => 'Link', 'type' => 'link', 'id' => 'kpwlin', 'connections' => ['kpprzy' => ['link']]],
            ['label' => 'Tłumaczenie', 'type' => 'text', 'id' => 'kpwrec', 'connections' => ['kpprzy' => ['evk_tl_en__text']]],
        ]],
    ]);
    global $wpdb;
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TKP_TYTUL)) as $stary) wp_delete_post((int) $stary, true);
    $id = (int) wp_insert_post(['post_title' => EVK_TKP_TYTUL, 'post_type' => 'page', 'post_status' => 'publish']);
    $k = evk_tl_kp_komponenty();
    $bl = static function (string $cid, string $pid, string $lang) { return evk_tl_kp_id($cid, $pid, $lang); };
    update_post_meta($id, '_bricks_page_content_2', wp_slash([
        ['id' => 'kps1', 'name' => 'section', 'parent' => 0, 'children' => ['kpia', 'kpib', 'kpic', 'kph1'], 'settings' => []],
        /* A: oba teksty i gotowe tłumaczenie nagłówka EN; B: tylko nagłówek; C: wstawiona bez wartości. */
        ['id' => 'kpia', 'name' => 'block', 'parent' => 'kps1', 'children' => [], 'settings' => [], 'cid' => 'kpkomp',
            'properties' => ['kpwnag' => 'Nagłówek A', 'kpwtek' => 'Tekst A', $bl('kpkomp', 'kpwnag', 'en') => 'Heading A']],
        ['id' => 'kpib', 'name' => 'block', 'parent' => 'kps1', 'children' => [], 'settings' => [], 'cid' => 'kpkomp', 'properties' => ['kpwnag' => 'Nagłówek B']],
        ['id' => 'kpic', 'name' => 'block', 'parent' => 'kps1', 'children' => [], 'settings' => [], 'cid' => 'kpkomp'],
        ['id' => 'kpid', 'name' => 'button', 'parent' => 'kps1', 'children' => [], 'settings' => [], 'cid' => 'kpprzy', 'properties' => ['kpwtre' => 'Zadzwoń']],
        ['id' => 'kph1', 'name' => 'heading', 'parent' => 'kps1', 'children' => [], 'settings' => ['text' => 'Zwykły nagłówek']],
    ]));
    $zapis['strona'] = $id;
    file_put_contents($plik, wp_json_encode($zapis));
    $u = get_user_by('login', 'evk-t-kp-redaktor');
    $uid = $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => 'evk-t-kp-redaktor', 'user_pass' => 'evk-t-haslo-kp', 'user_email' => 'evk-t-kp-redaktor@example.test', 'role' => 'editor']);
    get_user_by('id', $uid)->add_cap('evk_access_translations');
    $out += ['strona' => $id, 'opcja' => $k, 'gotowe' => $id > 0];
    break;

case 'opcja':
    $out['opcja'] = get_option('bricks_components');
    break;

case 'jezyki':
    $kody = array_filter(explode(',', (string) ($argv[2] ?? '')));
    update_option('tl_languages', array_map(static function ($k) { return ['code' => $k, 'name' => strtoupper($k), 'html' => $k]; }, array_values($kody)));
    $out['opcja'] = get_option('bricks_components');
    break;

case 'jednostki':
    $id = evk_tkp_strona();
    $out['wiersz'] = evk_tl_ai_wiersze_zakresu()['komponenty'] ?? null;
    $out['jednostki'] = array_values(array_filter(evk_tl_ai_jednostki(false, evk_tl_ai_zakres_z(['page' => ['bricks'], 'komponenty' => ['bricks']])),
        static function ($j) use ($id) { return (int) $j['post_id'] === $id || $j['meta_key'] === EVK_TL_KP; }));
    break;

case 'krok':
case 'krok-kp':
    $kp = $krok === 'krok-kp';
    $lang = (string) ($argv[$kp ? 3 : 2] ?? 'en');
    $GLOBALS['evk_t_ai_kod'] = $lang;
    $pomin = [];
    $out['kroki'] = [];
    for ($i = 0; $i < 10; $i++) {
        $r = $kp ? evk_tl_ai_krok(0, EVK_TL_KP, $lang, $pomin, ['opcje' => (string) ($argv[2] ?? '')])
            : evk_tl_ai_krok(evk_tkp_strona(), '_bricks_page_content_2', $lang, $pomin);
        $out['kroki'][] = $r;
        $pomin = array_merge($pomin, $r['odrzucone'], $r['pominiete'], $r['zapisane_klucze']);
        if (!$r['zostalo'] || !empty($r['stop'])) break;
    }
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    $z = end($GLOBALS['evk_t_ai_zadania']);
    $out['wiadomosc'] = $z ? (string) ($z['body']['contents'][0]['parts'][0]['text'] ?? '') : '';
    $out['strona'] = get_post_meta(evk_tkp_strona(), '_bricks_page_content_2', true);
    $out['opcja'] = get_option('bricks_components');
    $out['stan'] = get_post_meta(evk_tkp_strona(), EVK_TL_EL_STAN, true);
    $out['stan_kp'] = get_option(EVK_TL_KP_STAN);
    break;

case 'ajax-krok-kp':
    $u = ($argv[2] ?? 'admin') === 'admin' ? get_user_by('login', 'admin') : get_user_by('login', 'evk-t-kp-redaktor');
    wp_set_current_user((int) $u->ID);
    $GLOBALS['evk_t_ai_kod'] = (string) ($argv[4] ?? 'en');
    $out['odp'] = evk_tkp_ajax(['action' => 'evk_tl_ai_krok', 'nonce' => wp_create_nonce('evk_tl_ai'), 'post_id' => '0', 'meta_key' => EVK_TL_KP,
        'opcje' => (string) ($argv[3] ?? ''), 'lang' => (string) ($argv[4] ?? 'en'), 'czesci' => ['bricks']]);
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    break;

case 'strona':
    $out['strona'] = get_post_meta(evk_tkp_strona(), '_bricks_page_content_2', true);
    break;

case 'lista':
    $id = evk_tkp_strona();
    $out['wiersze'] = array_values(array_filter(evk_tl_el_do_sprawdzenia(), static function ($m) use ($id) { return (int) $m['post_id'] === $id; }));
    $out['kp'] = evk_tl_kp_do_sprawdzenia();
    ob_start();
    evk_tl_el_sekcja_do_sprawdzenia();
    $out['html'] = (string) ob_get_clean();
    break;

case 'teksty':
    $id = evk_tkp_strona();
    $out['wiersze'] = array_values(array_map(static function ($w) {
        return ['id' => $w['id'], 'sciezka' => $w['sciezka'], 'element' => $w['element'], 'opis' => $w['opis'], 'pl' => $w['pl'],
            'en' => [$w['jezyki']['en']['tekst'] ?? null, $w['jezyki']['en']['zrodlo'] ?? null, $w['jezyki']['en']['stan'] ?? null]];
    }, array_filter(evk_tl_el_teksty()['wiersze'], static function ($w) use ($id) { return (int) $w['post_id'] === $id; })));
    break;

case 'ajax-sprawdzone':
    $out['odp'] = evk_tkp_ajax(['action' => 'evk_tl_el_sprawdzone', 'nonce' => wp_create_nonce('evk_tl_el_sprawdzone'),
        'post_id' => (string) ($argv[4] ?? '0'), 'meta_key' => (string) ($argv[2] ?? ''), 'klucz' => (string) ($argv[3] ?? '')]);
    break;

case 'sprzataj':
    $zapis = evk_tkp_zapis();
    if (evk_tkp_strona()) wp_delete_post(evk_tkp_strona(), true);
    $u = get_user_by('login', 'evk-t-kp-redaktor');
    if ($u) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user((int) $u->ID);
    }
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    @unlink($plik);
    $out['ok'] = true;
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
