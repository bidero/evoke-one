<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Tłumaczenie AI pól Evoke FIELDS (Fields 1.75.0, Evoke ONE 1.267.0) na
 * CZWARTYM testowym WordPressie (pola.test, obie wtyczki). Dostawca AI —
 * atrapa (_ai-atrapa.php, Gemini: „EN:” przed każdym węzłem tekstu).
 *
 *   php tests/php/fields-ai.php ustaw                  grupy, typ treści, wpis z polami, ustawienia AI, konto bez prawa edycji
 *   php tests/php/fields-ai.php teksty                 evk_fields_tl_teksty() wpisu
 *   php tests/php/fields-ai.php wpisz K L T [1]        evk_fields_tl_wpisz() (z 1 — ze znacznikiem AI)
 *   php tests/php/fields-ai.php sprawdzone K L         evk_fields_tl_sprawdzone()
 *   php tests/php/fields-ai.php meta K W               oryginał pola grupy pojedynczej (zmiana tekstu podstawowego)
 *   php tests/php/fields-ai.php surowe                 meta wpisu (bliźniaki i wiersze)
 *   php tests/php/fields-ai.php jednostki 0|1          lista hurtu bez / z polami Fields — tylko ten wpis
 *   php tests/php/fields-ai.php krok L [scen] [od-nowa] kroki hurtu części „evk_fields” do końca
 *   php tests/php/fields-ai.php lista                  „Do sprawdzenia”: wiersze pól Fields tego wpisu i HTML sekcji
 *   php tests/php/fields-ai.php ajax-sprawdzone K      „Sprawdzone” z listy przez prawdziwy AJAX
 *   php tests/php/fields-ai.php dane KTO               filtr evk_fields_tl_ai (dane przycisków w metaboksie)
 *   php tests/php/fields-ai.php ajax-pola KTO PLIK [S] żądanie z metaboksu (ciało z pliku) przez prawdziwy AJAX
 *   php tests/php/fields-ai.php ai-klucz on|off        klucz API w ustawieniach
 *   php tests/php/fields-ai.php sprzataj               wszystko jak przed testem
 */
$evk_czwarty = true;
$evk_tryb    = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';
require __DIR__ . '/_ai-atrapa.php';

if (!function_exists('evk_fields_tl_teksty') || !function_exists('evk_tl_ai_teksty_pol')) {
    echo json_encode(['brak' => 'Evoke FIELDS 1.75.0+ albo moduł Tłumaczeń nieaktywny na ' . home_url()
        . ' — tools/testowy-wp.sh (repozytorium Fields: EVK_FIELDS_REPO, domyślnie ../evoke-fields)']);
    exit;
}

const EVK_FA_TYTUL = 'Test pól — AI';
$plik  = sys_get_temp_dir() . '/evk-t-fields-ai.json';
$mu    = WP_CONTENT_DIR . '/mu-plugins/evk-pola-ai-test.php';
$opcje = ['evk_tl_ai', 'evk_tl_ai_pamiec', 'evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'evk_tl_el_pola',
    'evk_rep_settings_pages', 'evk_rep_opt_ai_opcje'];
const EVK_FA_STRONA = 'pola-ai-ustawienia';

function evk_fa_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}
function evk_fa_wpis(): int {
    return (int) (evk_fa_zapis()['wpis'] ?? 0);
}
function evk_fa_kto(string $kto): int {
    $u = get_user_by('login', $kto === 'admin' ? 'admin' : 'evk-t-fa-' . $kto);
    return $u ? (int) $u->ID : 0;
}
function evk_fa_ustawienia_ai(bool $klucz): void {
    update_option('evk_tl_ai', ['dostawca' => 'gemini', 'klucze' => $klucz ? ['gemini' => 'test-klucz-ai-123'] : [],
        'modele' => [], 'opis' => '', 'wskazowki' => [], 'slowniczek' => ''], false);
}
/** AJAX w procesie: wp_send_json() kończy się wyjątkiem zamiast exit. */
function evk_fa_ajax(array $post) {
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

$out = ['krok' => $evk_tryb];
wp_set_current_user(evk_fa_kto('admin'));
if (!post_type_exists('pola_ai')) {
    register_post_type('pola_ai', ['label' => 'Pola AI', 'public' => true, 'show_ui' => true, 'show_in_rest' => false, 'supports' => ['title']]);
}
$id = evk_fa_wpis();

switch ($evk_tryb) {

case 'ustaw':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'mu_bylo' => is_file($mu)]));
    }
    $zapis = evk_fa_zapis();
    // Typ treści bez REST = klasyczny edytor (metabox w przeglądarce). Plik tylko w testowym WordPressie.
    wp_mkdir_p(dirname($mu));
    file_put_contents($mu, "<?php\n// Test fields-ai (Evoke ONE, tests/php/fields-ai.php): typ treści bez REST — usuwany po teście.\n"
        . "add_action('init', function () {\n    register_post_type('pola_ai', ['label' => 'Pola AI', 'public' => true, 'show_ui' => true, 'show_in_rest' => false, 'supports' => ['title']]);\n});\n");
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US'], ['code' => 'de', 'name' => 'Deutsch', 'html' => 'de-DE']]);
    update_option('tl_translations', ['groups' => []]);
    evk_fa_ustawienia_ai(true);
    delete_option('evk_tl_ai_pamiec');
    @unlink(sys_get_temp_dir() . '/evk-t-tl-ai-429-raz');

    global $wpdb;
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_FA_TYTUL)) as $stary) wp_delete_post((int) $stary, true);
    foreach (get_posts(['post_type' => 'evk_field_group', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_evk_test_ai', 'meta_value' => '1']) as $g) wp_delete_post($g->ID, true);
    $grupa = static function (string $klucz, string $etykieta, array $pola, bool $repeater) {
        $g = (int) wp_insert_post(['post_type' => 'evk_field_group', 'post_status' => 'publish', 'post_title' => $etykieta]);
        update_post_meta($g, '_evk_test_ai', '1');
        update_post_meta($g, '_evk_key', $klucz);
        update_post_meta($g, '_evk_fields', wp_slash(wp_json_encode($pola)));
        update_post_meta($g, '_evk_post_types', ['pola_ai']);
        update_post_meta($g, '_evk_object_type', 'post');
        update_post_meta($g, '_evk_repeater', $repeater ? 1 : 0);
        return $g;
    };
    $grupa('ai_pola', 'Pola oferty', [
        'tytul'    => ['type' => 'text', 'label' => 'Tytuł'],
        'opis'     => ['type' => 'textarea', 'label' => 'Opis'],
        'tresc'    => ['type' => 'wysiwyg', 'label' => 'Treść'],
        'przycisk' => ['type' => 'link', 'label' => 'Przycisk'],
        'kod'      => ['type' => 'text', 'label' => 'Kod', 'no_translate' => true],
        'lista'    => ['type' => 'repeater', 'label' => 'Lista', 'sub_fields' => [
            'naglowek' => ['type' => 'text', 'label' => 'Nagłówek'],
            'tekst'    => ['type' => 'textarea', 'label' => 'Tekst'],
        ]],
    ], false);
    $grupa('ai_wiersze', 'Hasła', ['haslo' => ['type' => 'text', 'label' => 'Hasło']], true);
    /* Strona ustawień z grupą pojedynczą i repeaterem (1.268.0: ✦ na stronach ustawień). */
    $go = $grupa('ai_opcje', 'Opcje AI', ['slogan' => ['type' => 'text', 'label' => 'Slogan'],
        'przyciski' => ['type' => 'repeater', 'label' => 'Przyciski', 'sub_fields' => ['etykieta' => ['type' => 'text', 'label' => 'Etykieta']]]], false);
    update_post_meta($go, '_evk_object_type', 'options');
    $strony = get_option('evk_rep_settings_pages', []);
    $strony = is_array($strony) ? $strony : [];
    $strony[EVK_FA_STRONA] = ['label' => 'Ustawienia AI', 'slug' => EVK_FA_STRONA, 'icon' => 'dashicons-admin-generic', 'capability' => 'manage_options',
        'parent' => '', 'hide_title' => 0, 'tabs' => [['label' => 'Ogólne', 'groups' => ['ai_opcje']]]];
    update_option('evk_rep_settings_pages', $strony);
    update_option('evk_rep_opt_ai_opcje', ['slogan' => 'Najlepsza oferta', 'przyciski' => [['etykieta' => 'Zadzwoń teraz']]], false);
    evk_groups_cache_clear();

    $id = (int) wp_insert_post(['post_title' => EVK_FA_TYTUL, 'post_type' => 'pola_ai', 'post_status' => 'publish']);
    update_post_meta($id, 'tytul', 'Nasza oferta');
    update_post_meta($id, 'evk_tl_en__tytul', 'Our offer');
    update_post_meta($id, 'evk_tl_en__tytul__zrodlo', evk_rep_tl_src('Nasza oferta'));
    update_post_meta($id, 'opis', 'Opis usług firmy');
    update_post_meta($id, 'tresc', '<p>Treść <strong>główna</strong></p>');
    update_post_meta($id, 'przycisk', ['url' => 'https://example.com/kontakt/', 'title' => 'Napisz do nas', 'target' => '']);
    update_post_meta($id, 'kod', 'ABC-1');
    update_post_meta($id, 'lista', [
        ['naglowek' => 'Pierwszy punkt', 'tekst' => 'Tekst pierwszego punktu'],
        ['naglowek' => 'Drugi punkt', 'tekst' => '', 'evk_tl_en__naglowek' => 'Second point', 'evk_tl_en__naglowek__zrodlo' => evk_rep_tl_src('Drugi punkt')],
    ]);
    update_post_meta($id, 'ai_wiersze', [['haslo' => 'Hasło przewodnie']]);
    /* Treść Bricksa tej strony — kontekst dla pól w hurcie. Mapa pól nagłówka
       tak, jak zapisałby ją builder (prawdziwy filtr kontrolek, jak w tl-sprawdz). */
    evk_tl_el_kontrolki(['text' => ['tab' => 'content', 'type' => 'text'], 'tag' => ['tab' => 'content', 'type' => 'select']], 'heading');
    evk_tl_el_zapisz_mape();
    update_post_meta($id, '_bricks_page_content_2', [['id' => 'fa1', 'name' => 'heading', 'parent' => 0, 'settings' => ['text' => 'Nagłówek strony Bricks']]]);

    // Dostęp do Tłumaczeń bez prawa edycji wpisu.
    $login = 'evk-t-fa-czytelnik';
    $u = get_user_by('login', $login);
    $uid = $u ? (int) $u->ID : (int) wp_insert_user(['user_login' => $login, 'user_pass' => 'evk-t-haslo-fa', 'user_email' => $login . '@example.test', 'role' => 'subscriber']);
    get_user_by('id', $uid)->add_cap('evk_access_translations');

    $zapis['wpis'] = $id;
    $zapis['uzytkownicy'] = [$uid];
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['wpis' => $id, 'wp' => rtrim(ABSPATH, '/'), 'gotowe' => true];
    break;

case 'teksty':
    $out['teksty'] = evk_fields_tl_teksty($id);
    break;

case 'wpisz':
    $out['ok'] = evk_fields_tl_wpisz($id, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''), (string) ($argv[4] ?? ''), ($argv[5] ?? '') === '1');
    $out['teksty'] = evk_fields_tl_teksty($id);
    break;

case 'sprawdzone':
    $out['ok'] = evk_fields_tl_sprawdzone($id, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''));
    $out['teksty'] = evk_fields_tl_teksty($id);
    break;

case 'meta':
    update_post_meta($id, (string) ($argv[2] ?? ''), (string) ($argv[3] ?? ''));
    $out['teksty'] = evk_fields_tl_teksty($id);
    break;

case 'surowe':
    foreach (get_post_meta($id) as $k => $v) {
        if ($k[0] === '_' && $k !== '_bricks_page_content_2') continue;
        $out['meta'][$k] = maybe_unserialize($v[0]);
    }
    $out['skroty'] = ['opis' => evk_rep_tl_hash('Opis usług firmy'), 'pierwszy' => evk_rep_tl_hash('Pierwszy punkt')];
    break;

case 'jednostki':
    $out['jednostki'] = array_values(array_filter(evk_tl_ai_jednostki(false, ($argv[2] ?? '') === '1'),
        static function ($j) use ($id) { return (int) $j['post_id'] === $id; }));
    break;

case 'krok':
    $lang = (string) ($argv[2] ?? 'en');
    $GLOBALS['evk_t_ai_scenariusz'] = (string) ($argv[3] ?? 'ok');
    $GLOBALS['evk_t_ai_kod'] = $lang;
    $pomin = [];
    $out['kroki'] = [];
    for ($i = 0; $i < 10; $i++) {
        $w = evk_tl_ai_krok($id, EVK_TL_AI_POLA, $lang, $pomin, ['ponownie' => ($argv[4] ?? '') === 'od-nowa']);
        $out['kroki'][] = $w;
        $pomin = array_merge($pomin, $w['odrzucone'], $w['pominiete'], $w['zapisane_klucze']);
        if (!empty($w['blad']) && !empty($w['stop'])) break;
        if (!$w['zostalo']) break;
    }
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    $z = end($GLOBALS['evk_t_ai_zadania']);
    $out['wiadomosc'] = $z ? (string) ($z['body']['contents'][0]['parts'][0]['text'] ?? '') : '';
    $out['teksty'] = evk_fields_tl_teksty($id);
    $out['bricks'] = get_post_meta($id, '_bricks_page_content_2', true);
    break;

case 'lista':
    $out['wiersze'] = array_values(array_filter(evk_tl_ai_pola_do_sprawdzenia(),
        static function ($m) use ($id) { return (int) $m['post_id'] === $id; }));
    ob_start();
    evk_tl_el_sekcja_do_sprawdzenia();
    $out['html'] = (string) ob_get_clean();
    break;

case 'ajax-sprawdzone':
    $out['odp'] = evk_fa_ajax(['action' => 'evk_tl_el_sprawdzone', 'nonce' => wp_create_nonce('evk_tl_el_sprawdzone'),
        'post_id' => (string) $id, 'meta_key' => EVK_TL_AI_POLA, 'klucz' => (string) ($argv[2] ?? '')]);
    $out['teksty'] = evk_fields_tl_teksty($id);
    break;

case 'dane':
    wp_set_current_user(evk_fa_kto((string) ($argv[2] ?? 'admin')));
    $out['dane'] = apply_filters('evk_fields_tl_ai', null, $id);
    $out['z_kluczem'] = strpos((string) wp_json_encode($out['dane']), 'test-klucz-ai-123') !== false;
    break;

case 'ajax-pola':
    /* Żądanie z metaboksu: ciało POST z pliku, jak wysłała je przeglądarka
       (nonce „auto” — świeży dla tego konta). Bez zapisu: meta wpisu przed i po. */
    wp_set_current_user(evk_fa_kto((string) ($argv[2] ?? 'admin')));
    parse_str((string) @file_get_contents((string) ($argv[3] ?? '')), $post);
    if (($post['nonce'] ?? '') === 'auto') $post['nonce'] = wp_create_nonce('evk_tl_ai_pola');
    $GLOBALS['evk_t_ai_scenariusz'] = (string) ($argv[4] ?? 'ok');
    $GLOBALS['evk_t_ai_kod'] = is_string($post['lang'] ?? null) ? $post['lang'] : 'en';
    $przed = get_post_meta($id);
    $out['odp'] = evk_fa_ajax($post);
    $out['bez_zapisu'] = $przed === get_post_meta($id);
    $out['zadania'] = count($GLOBALS['evk_t_ai_zadania']);
    $z = end($GLOBALS['evk_t_ai_zadania']);
    $out['wiadomosc'] = $z ? (string) ($z['body']['contents'][0]['parts'][0]['text'] ?? '') : '';
    break;

case 'opcje':
    $out['opcje'] = get_option('evk_rep_opt_ai_opcje');
    break;

case 'dane-strona':
    wp_set_current_user(evk_fa_kto((string) ($argv[2] ?? 'admin')));
    $out['dane'] = apply_filters('evk_fields_tl_ai', null, 0, ['strona' => EVK_FA_STRONA, 'tytul' => 'Ustawienia AI']);
    $out['strona'] = function_exists('evk_fields_tl_strona') ? evk_fields_tl_strona(EVK_FA_STRONA) : 'brak';
    break;

case 'ai-klucz':
    evk_fa_ustawienia_ai(($argv[2] ?? 'on') !== 'off');
    $out['ok'] = true;
    break;

case 'sprzataj':
    $zapis = evk_fa_zapis();
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    global $wpdb;
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_FA_TYTUL)) as $stary) wp_delete_post((int) $stary, true);
    foreach (get_posts(['post_type' => 'evk_field_group', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_evk_test_ai', 'meta_value' => '1']) as $g) wp_delete_post($g->ID, true);
    evk_groups_cache_clear();
    if (!empty($zapis['uzytkownicy'])) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($zapis['uzytkownicy'] as $uid) wp_delete_user((int) $uid);
    }
    if (empty($zapis['mu_bylo'])) @unlink($mu);
    @unlink(sys_get_temp_dir() . '/evk-t-tl-ai-429-raz');
    @unlink($plik);
    $out['ok'] = true;
    break;

default:
    $out['brak'] = 'nieznane polecenie: ' . $evk_tryb;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
