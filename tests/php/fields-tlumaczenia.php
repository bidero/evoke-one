<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Tłumaczenia wartości pól Evoke FIELDS (Fields 1.70.0, Evoke ONE 1.250.0) na
 * CZWARTYM testowym WordPressie: pola.test, obie wtyczki (tools/testowy-wp.sh).
 *
 *   php tests/php/fields-tlumaczenia.php ustaw      grupy pól, wpisy, term, strona ustawień, języki
 *   php tests/php/fields-tlumaczenia.php zapis      save_post z formularza jak z przeglądarki (wiersze z szablonu)
 *   php tests/php/fields-tlumaczenia.php panel      metabox po zapisie: przełącznik, pola języków, szablon, etykiety
 *   php tests/php/fields-tlumaczenia.php sprawdz    „Do sprawdzenia": zmiana oryginału, „Sprawdzone", poprawka tłumacza, czyszczenie
 *   php tests/php/fields-tlumaczenia.php przenies   język wyłączony i „Nie tłumacz": tłumaczenia przeżywają zapis
 *   php tests/php/fields-tlumaczenia.php odczyt     tagi w każdym języku, pętla, opcje, term, link, builder
 *   php tests/php/fields-tlumaczenia.php term       term: panel i zapis przez edited_category
 *   php tests/php/fields-tlumaczenia.php opcje      strona ustawień: prawdziwy zapis (handler kończy się exit)
 *   php tests/php/fields-tlumaczenia.php adresy     evk_tl_fields_url() na przypadkach
 *   php tests/php/fields-tlumaczenia.php wylaczone  bez języków: panel bez zmian, odczyt po polsku, wiersze bez strat
 *   php tests/php/fields-tlumaczenia.php stan       identyfikatory i stan do testu w przeglądarce
 *
 * Formularz składa sonda tak jak przeglądarka: bierze WYRENDEROWANY metabox,
 * zbiera pola (bez zawartości <template>), a wiersz dodaje z szablonu
 * z podmienionym znacznikiem — jak admin.js. Dzięki temu test widzi nazwy
 * i pola ukryte dokładnie takie, jakie wyjdą ze strony.
 */
$evk_czwarty = true;
$evk_tryb    = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';

if (!function_exists('evk_rep_tl_langs') || !function_exists('evk_rep_render_metabox')) {
    echo json_encode(['brak' => 'Evoke FIELDS 1.70.0+ nieaktywny na ' . home_url() . ' — tools/testowy-wp.sh (repozytorium Fields: EVK_FIELDS_REPO, domyślnie ../evoke-fields)']);
    exit;
}

const EVK_TP_TYTUL = 'Test pól — tłumaczenia';

// =========================================================================
// DANE TESTU
// =========================================================================

function evk_tp_pola_wpis(): array {
    return [
        'zakladka_tresc' => ['type' => 'tab', 'label' => 'Treść'],
        'tytul'    => ['type' => 'text', 'label' => 'Tytuł sekcji'],
        'opis'     => ['type' => 'textarea', 'label' => 'Opis'],
        'tresc'    => ['type' => 'wysiwyg', 'label' => 'Treść'],
        'przycisk' => ['type' => 'link', 'label' => 'Przycisk'],
        'pusty'    => ['type' => 'text', 'label' => 'Pusty oryginał'],
        'lista'    => ['type' => 'repeater', 'label' => 'Lista', 'sub_fields' => [
            'naglowek' => ['type' => 'text', 'label' => 'Nagłówek'],
            'tekst'    => ['type' => 'textarea', 'label' => 'Tekst'],
            'odnosnik' => ['type' => 'link', 'label' => 'Odnośnik'],
            'ikona'    => ['type' => 'text', 'label' => 'Ikona', 'no_translate' => true],
        ]],
        'zakladka_tech' => ['type' => 'tab', 'label' => 'Techniczne'],
        'kod'      => ['type' => 'text', 'label' => 'Kod produktu', 'no_translate' => true],
        'liczba'   => ['type' => 'number', 'label' => 'Liczba'],
    ];
}

function evk_tp_grupa(string $klucz, string $etykieta, array $pola, array $typy, array $meta = []): int {
    $id = (int) wp_insert_post(['post_type' => 'evk_field_group', 'post_status' => 'publish', 'post_title' => $etykieta]);
    update_post_meta($id, '_evk_test_pola', '1');
    update_post_meta($id, '_evk_key', $klucz);
    update_post_meta($id, '_evk_fields', wp_slash(wp_json_encode($pola)));
    update_post_meta($id, '_evk_post_types', $typy);
    update_post_meta($id, '_evk_object_type', 'post');
    foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
    return $id;
}

function evk_tp_cpt(): void {
    if (!post_type_exists('pola_test')) {
        register_post_type('pola_test', ['label' => 'Pola test', 'public' => true, 'show_ui' => true, 'show_in_rest' => false, 'supports' => ['title', 'editor']]);
    }
}

/** Wpis testu danego typu (po tytule — sondy chodzą w osobnych procesach). */
function evk_tp_id(string $typ): int {
    global $wpdb;
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s AND post_type = %s ORDER BY ID DESC LIMIT 1", EVK_TP_TYTUL, $typ));
}

function evk_tp_term(): int {
    $t = get_term_by('slug', 'kategoria-pola', 'category');
    return $t ? (int) $t->term_id : 0;
}

// =========================================================================
// FORMULARZ JAK Z PRZEGLĄDARKI
// =========================================================================

function evk_tp_dom(string $html): DOMXPath {
    $d = new DOMDocument();
    $stary = libxml_use_internal_errors(true);
    $d->loadHTML('<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($stary);
    return new DOMXPath($d);
}

function evk_tp_w_szablonie(DOMNode $n): bool {
    for ($p = $n->parentNode; $p; $p = $p->parentNode) {
        if ($p->nodeName === 'template') return true;
    }
    return false;
}

function evk_tp_klasa(string $klasa): string {
    return 'contains(concat(" ", normalize-space(@class), " "), " ' . $klasa . ' ")';
}

/** Pola formularza [nazwa, wartość] w kolejności — bez zawartości <template>, jak przeglądarka. */
function evk_tp_formularz(string $html): array {
    $x = evk_tp_dom($html);
    $pary = [];
    foreach ($x->query('//input|//textarea|//select') as $el) {
        /** @var DOMElement $el */
        if (evk_tp_w_szablonie($el)) continue;
        $name = $el->getAttribute('name');
        if ($name === '' || $el->hasAttribute('disabled')) continue;
        if ($el->nodeName === 'textarea') { $pary[] = [$name, $el->textContent]; continue; }
        if ($el->nodeName === 'select') {
            $wybrane = [];
            $pierwsza = null;
            foreach ($x->query('.//option', $el) as $o) {
                /** @var DOMElement $o */
                $v = $o->hasAttribute('value') ? $o->getAttribute('value') : $o->textContent;
                if ($pierwsza === null) $pierwsza = $v;
                if ($o->hasAttribute('selected')) $wybrane[] = $v;
            }
            if (!$wybrane && !$el->hasAttribute('multiple') && $pierwsza !== null) $wybrane = [$pierwsza];
            foreach ($wybrane as $v) $pary[] = [$name, $v];
            continue;
        }
        $typ = strtolower($el->getAttribute('type') ?: 'text');
        if (in_array($typ, ['button', 'submit', 'reset', 'image', 'file'], true)) continue;
        if (in_array($typ, ['checkbox', 'radio'], true) && !$el->hasAttribute('checked')) continue;
        $pary[] = [$name, $el->hasAttribute('value') ? $el->getAttribute('value') : 'on'];
    }
    return $pary;
}

/** Wiersz z szablonu repeatera `data-group` — znacznik podmieniony jak w admin.js. */
function evk_tp_wiersz(string $html, string $grupa, string $uid): array {
    $x = evk_tp_dom($html);
    foreach ($x->query('//div[' . evk_tp_klasa('evk-rep') . ']') as $rep) {
        /** @var DOMElement $rep */
        if ($rep->getAttribute('data-group') !== $grupa || evk_tp_w_szablonie($rep)) continue;
        foreach ($rep->childNodes as $c) {
            if ($c->nodeName !== 'template') continue;
            $wnetrze = '';
            foreach ($c->childNodes as $cc) $wnetrze .= $c->ownerDocument->saveHTML($cc);
            $glebokosc = (int) $rep->getAttribute('data-depth');
            $znacznik  = $glebokosc <= 0 ? '__INDEX__' : '__IDX' . $glebokosc . '__';
            return evk_tp_formularz(str_replace($znacznik, $uid, $wnetrze));
        }
    }
    return [];
}

function evk_tp_ustaw(array &$pary, string $name, string $v): void {
    $jest = false;
    foreach ($pary as $i => $p) {
        if ($p[0] === $name) { $pary[$i][1] = $v; $jest = true; }
    }
    if (!$jest) $pary[] = [$name, $v];
}

function evk_tp_wartosc(array $pary, string $name): ?string {
    $w = null;
    foreach ($pary as $p) if ($p[0] === $name) $w = $p[1];
    return $w;
}

function evk_tp_dane(array $pary): array {
    $q = [];
    foreach ($pary as $p) $q[] = rawurlencode($p[0]) . '=' . rawurlencode($p[1]);
    parse_str(implode('&', $q), $dane);
    return $dane;
}

function evk_tp_metabox(int $id, string $grupa): string {
    ob_start();
    evk_rep_render_metabox(get_post($id), ['args' => ['group_key' => $grupa]]);
    return (string) ob_get_clean();
}

/** Zapis wpisu z formularza: prawdziwe save_post Fields (nonce z formularza). */
function evk_tp_zapisz_wpis(int $id, array $pary): void {
    $_POST = wp_slash(evk_tp_dane($pary));
    $_POST['post_ID'] = $id;
    $_REQUEST = $_POST;
    wp_update_post(['ID' => $id]);
    $_POST = $_REQUEST = [];
}

/** Stan pól języków w wyrenderowanym HTML-u: pole → [język => [wartość, do sprawdzenia]]. */
function evk_tp_stan_pol(string $html): array {
    $x = evk_tp_dom($html);
    $out = [];
    foreach ($x->query('//div[' . evk_tp_klasa('evk-tl-pole') . ']') as $p) {
        /** @var DOMElement $p */
        if (evk_tp_w_szablonie($p)) continue;
        $w = $x->query('.//*[' . evk_tp_klasa('evk-tl-wejscie') . ']', $p)->item(0);
        if (!$w instanceof DOMElement) continue;
        $n = $w->getAttribute('name');
        $out[$n] = [
            'wartosc'   => $w->nodeName === 'textarea' ? $w->textContent : $w->getAttribute('value'),
            'sprawdz'   => $p->hasAttribute('data-sprawdz'),
            'zrodlo'    => ($z = $x->query('.//input[' . evk_tp_klasa('evk-tl-zrodlo') . ']', $p)->item(0)) instanceof DOMElement ? $z->getAttribute('value') : null,
        ];
    }
    return $out;
}

function evk_tp_skrot(string $t): string {
    return evk_rep_tl_hash($t);
}

// =========================================================================
// TRYBY
// =========================================================================

wp_set_current_user(1);
evk_tp_cpt();
$wynik = [];

switch ($evk_tryb) {

case 'ustaw':
    // Typ treści bez REST = klasyczny edytor (test w przeglądarce). Plik tylko w testowym WordPressie.
    $mu = WP_CONTENT_DIR . '/mu-plugins';
    wp_mkdir_p($mu);
    file_put_contents($mu . '/evk-pola-test.php', "<?php\n// Testy fields-* (Evoke ONE, tests/php/fields-tlumaczenia.php): typ treści bez REST = klasyczny edytor.\n"
        . "add_action('init', function () {\n    register_post_type('pola_test', ['label' => 'Pola test', 'public' => true, 'show_ui' => true, 'show_in_rest' => false, 'supports' => ['title', 'editor']]);\n});\n");
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US'], ['code' => 'de', 'name' => 'Deutsch', 'html' => 'de-DE']]);
    update_option('tl_url_slugs', [['pl' => 'kontakt', 'en' => 'contact', 'de' => 'kontakt-de']]);
    delete_transient(TL_TRANSIENT_SLUGS);

    global $wpdb;
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TP_TYTUL)) as $stary) wp_delete_post((int) $stary, true);
    foreach (get_posts(['post_type' => 'evk_field_group', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_evk_test_pola', 'meta_value' => '1']) as $g) wp_delete_post($g->ID, true);
    if (evk_tp_term()) wp_delete_term(evk_tp_term(), 'category');
    foreach (['nowa-kategoria-pola', 'druga-kategoria-pola'] as $slug) {
        $nt = get_term_by('slug', $slug, 'category');
        if ($nt) wp_delete_term((int) $nt->term_id, 'category');
    }
    delete_option('evk_rep_opt_grupa_opcje');

    evk_tp_grupa('grupa_wpis', 'Pola wpisu', evk_tp_pola_wpis(), ['post', 'pola_test']);
    evk_tp_grupa('grupa_wiersze', 'Hasła', ['haslo' => ['type' => 'text', 'label' => 'Hasło'], 'numer' => ['type' => 'number', 'label' => 'Numer']],
        ['post', 'pola_test'], ['_evk_repeater' => 1]);
    evk_tp_grupa('grupa_term', 'Pola kategorii', ['podpis' => ['type' => 'text', 'label' => 'Podpis'], 'baner' => ['type' => 'wysiwyg', 'label' => 'Baner']],
        [], ['_evk_object_type' => 'term', '_evk_taxonomies' => ['category']]);
    evk_tp_grupa('grupa_opcje', 'Pola ustawień', [
        'slogan'    => ['type' => 'text', 'label' => 'Slogan'],
        'stopka'    => ['type' => 'textarea', 'label' => 'Stopka'],
        'przyciski' => ['type' => 'repeater', 'label' => 'Przyciski', 'sub_fields' => [
            'etykieta' => ['type' => 'text', 'label' => 'Etykieta'],
            'adres'    => ['type' => 'link', 'label' => 'Adres'],
        ]],
    ], ['evk_brak']);
    update_option('evk_rep_settings_pages', ['pola-ustawienia' => [
        'label' => 'Ustawienia pól', 'slug' => 'pola-ustawienia', 'icon' => 'dashicons-admin-generic',
        'capability' => 'manage_options', 'parent' => '', 'hide_title' => 0,
        'tabs' => [['label' => 'Ogólne', 'groups' => ['grupa_opcje']]],
    ]]);
    evk_groups_cache_clear();

    $wynik = [
        'wpis'    => (int) wp_insert_post(['post_title' => EVK_TP_TYTUL, 'post_type' => 'post', 'post_status' => 'publish']),
        'klasyk'  => (int) wp_insert_post(['post_title' => EVK_TP_TYTUL, 'post_type' => 'pola_test', 'post_status' => 'publish']),
        'kontakt' => (int) wp_insert_post(['post_title' => EVK_TP_TYTUL, 'post_name' => 'kontakt', 'post_type' => 'page', 'post_status' => 'publish']),
    ];
    $t = wp_insert_term('Kategoria pola', 'category', ['slug' => 'kategoria-pola']);
    $wynik['term'] = is_wp_error($t) ? 0 : (int) $t['term_id'];
    $wynik['jezyki_one'] = get_option('tl_languages');
    $wynik['wp'] = rtrim(ABSPATH, '/');
    break;

case 'zapis':
    // Formularz klasycznego edytora: dwie grupy (pojedyncza i repeater) jak na ekranie.
    $id   = evk_tp_id('pola_test');
    $html = evk_tp_metabox($id, 'grupa_wpis') . evk_tp_metabox($id, 'grupa_wiersze');
    $pary = evk_tp_formularz($html);
    $wynik['pola_formularza_przed'] = count($pary);
    foreach ([
        'evk_single[tytul]' => 'Tytuł PL', 'evk_single[evk_tl_en__tytul]' => 'Title EN', 'evk_single[evk_tl_de__tytul]' => 'Titel DE',
        'evk_single[opis]' => "Opis PL\nw dwóch liniach", 'evk_single[evk_tl_en__opis]' => "Description EN\nin two lines",
        'evk_single[tresc]' => '<p>Treść <strong>PL</strong></p>', 'evk_single[evk_tl_en__tresc]' => '<p>Content <strong>EN</strong></p>',
        'evk_single[przycisk][url]' => home_url('/kontakt/'), 'evk_single[przycisk][title]' => 'Napisz do nas',
        'evk_single[evk_tl_en__przycisk]' => 'Write to us',
        'evk_single[kod]' => 'ABC-1', 'evk_single[liczba]' => '5',
    ] as $n => $v) evk_tp_ustaw($pary, $n, $v);

    // Dwa wiersze listy z szablonu (jak klik „Dodaj wiersz") i jeden wiersz grupy-repeatera.
    foreach (['w1' => ['Nagłówek 1', 'Heading 1', 'Überschrift 1'], 'w2' => ['Nagłówek 2', 'Heading 2', '']] as $uid => [$pl, $en, $de]) {
        $w = evk_tp_wiersz($html, 'evk_single[lista]', $uid);
        $wynik['szablon_' . $uid] = array_column($w, 0);
        $b = 'evk_single[lista][' . $uid . ']';
        foreach ([$b . '[naglowek]' => $pl, $b . '[evk_tl_en__naglowek]' => $en, $b . '[evk_tl_de__naglowek]' => $de,
                  $b . '[tekst]' => 'Tekst ' . $uid, $b . '[evk_tl_en__tekst]' => 'Text ' . $uid,
                  $b . '[odnosnik][url]' => 'https://example.com/' . $uid . '/', $b . '[odnosnik][title]' => 'Więcej ' . $uid,
                  $b . '[evk_tl_en__odnosnik]' => 'More ' . $uid, $b . '[ikona]' => 'ikona-' . $uid] as $n => $v) evk_tp_ustaw($w, $n, $v);
        $pary = array_merge($pary, $w);
    }
    $w = evk_tp_wiersz($html, 'grupa_wiersze', 'h1');
    evk_tp_ustaw($w, 'grupa_wiersze[h1][haslo]', 'Hasło 1');
    evk_tp_ustaw($w, 'grupa_wiersze[h1][evk_tl_en__haslo]', 'Slogan 1');
    evk_tp_ustaw($w, 'grupa_wiersze[h1][numer]', '7');
    $wynik['szablon_grupy'] = array_column($w, 0);
    $pary = array_merge($pary, $w);

    evk_tp_zapisz_wpis($id, $pary);

    $m = static function (string $k) use ($id) { return get_post_meta($id, $k, true); };
    $wynik['meta'] = [];
    foreach (['tytul', 'evk_tl_en__tytul', 'evk_tl_de__tytul', 'evk_tl_en__tytul__zrodlo', 'evk_tl_de__tytul__zrodlo',
              'opis', 'evk_tl_en__opis', 'evk_tl_de__opis', 'tresc', 'evk_tl_en__tresc', 'przycisk', 'evk_tl_en__przycisk',
              'evk_tl_en__przycisk__zrodlo', 'kod', 'evk_tl_en__kod', 'liczba', 'evk_tl_en__liczba'] as $k) $wynik['meta'][$k] = $m($k);
    $wynik['meta_istnieje'] = ['evk_tl_de__opis' => metadata_exists('post', $id, 'evk_tl_de__opis'), 'evk_tl_de__opis__zrodlo' => metadata_exists('post', $id, 'evk_tl_de__opis__zrodlo')];
    $wynik['lista']   = $m('lista');
    $wynik['wiersze'] = $m('grupa_wiersze');
    $wynik['skroty']  = ['tytul' => evk_tp_skrot('Tytuł PL'), 'przycisk' => evk_tp_skrot('Napisz do nas'), 'naglowek1' => evk_tp_skrot('Nagłówek 1')];
    break;

case 'panel':
    $id   = evk_tp_id('pola_test');
    $html = evk_tp_metabox($id, 'grupa_wpis');
    $x    = evk_tp_dom($html);
    $wynik['przelacznik'] = [];
    foreach ($x->query('//div[' . evk_tp_klasa('evk-tl-przelacznik') . ']') as $prz) {
        /** @var DOMElement $prz */
        $wynik['przelacznik_rola'] = [$prz->getAttribute('role'), $prz->getAttribute('aria-label')];
        foreach ($x->query('.//button', $prz) as $b) {
            /** @var DOMElement $b */
            $wynik['przelacznik'][] = [$b->getAttribute('data-lang'), $b->getAttribute('aria-pressed'), trim((string) preg_replace('/\s+/u', ' ', $b->textContent))];
        }
    }
    $wynik['grupa'] = ($g = $x->query('//div[' . evk_tp_klasa('evk-tl-grupa') . ']')->item(0)) instanceof DOMElement
        ? [$g->getAttribute('data-evk-jezyk'), $g->getAttribute('data-evk-baza')] : null;
    $wynik['pola'] = [];
    foreach ($x->query('//div[' . evk_tp_klasa('evk-s-field') . '][@data-key]') as $f) {
        /** @var DOMElement $f */
        if (evk_tp_w_szablonie($f)) continue;
        $jez = [];
        foreach ($x->query('./div[' . evk_tp_klasa('evk-tl-pole') . ']', $f) as $p) $jez[] = $p->getAttribute('data-lang');
        $kl = ' ' . $f->getAttribute('class') . ' ';
        $wynik['pola'][$f->getAttribute('data-key')][] = [
            'tak' => strpos($kl, ' evk-tl-tak ') !== false, 'zawiera' => strpos($kl, ' evk-tl-zawiera ') !== false,
            'pl'  => $x->query('./div[' . evk_tp_klasa('evk-tl-pl') . ']', $f)->length, 'jezyki' => $jez,
        ];
    }
    $wynik['zakladki'] = [];
    foreach ($x->query('//button[' . evk_tp_klasa('evk-s-tab') . ']') as $b) {
        /** @var DOMElement $b */
        $wynik['zakladki'][trim($b->textContent)] = strpos(' ' . $b->getAttribute('class') . ' ', ' evk-tl-bez ') !== false;
    }
    // Etykiety pól języków wskazują istniejące pola; identyfikatory bez powtórzeń.
    $idy = [];
    $zle = [];
    foreach ($x->query('//*[@id]') as $e) {
        /** @var DOMElement $e */
        if (!evk_tp_w_szablonie($e)) $idy[] = $e->getAttribute('id');
    }
    foreach ($x->query('//label[' . evk_tp_klasa('evk-tl-etykieta') . ']') as $l) {
        /** @var DOMElement $l */
        if (evk_tp_w_szablonie($l)) continue;
        $cel = $x->query('//*[@id="' . $l->getAttribute('for') . '"]')->item(0);
        if (!$cel instanceof DOMElement || strpos(' ' . $cel->getAttribute('class') . ' ', ' evk-tl-wejscie ') === false) $zle[] = $l->getAttribute('for');
    }
    $wynik['etykiety_bez_pola'] = $zle;
    $wynik['etykiety'] = [];
    foreach ($x->query('//label[' . evk_tp_klasa('evk-tl-etykieta') . ']') as $l) {
        if (!evk_tp_w_szablonie($l)) $wynik['etykiety'][] = trim($l->textContent);
    }
    $wynik['id_powtorzone'] = array_values(array_unique(array_diff_assoc($idy, array_unique($idy))));
    // Szablon wiersza: każdy identyfikator pola języka zawiera znacznik wiersza.
    $wynik['szablon_id'] = [];
    foreach ($x->query('//template//*[' . evk_tp_klasa('evk-tl-wejscie') . ']') as $e) {
        /** @var DOMElement $e */
        $wynik['szablon_id'][] = $e->getAttribute('id');
    }
    $wynik['stan'] = evk_tp_stan_pol($html);
    $wynik['uwaga_link'] = 0;
    foreach ($x->query('//p[' . evk_tp_klasa('evk-tl-uwaga') . ']') as $e) if (!evk_tp_w_szablonie($e)) $wynik['uwaga_link']++;
    $wynik['wysiwyg'] = $x->query('//textarea[' . evk_tp_klasa('evk-tl-wysiwyg') . ']')->length;
    $wynik['lang_atr'] = [];
    foreach ($x->query('//*[' . evk_tp_klasa('evk-tl-wejscie') . ']') as $e) {
        /** @var DOMElement $e */
        if (!evk_tp_w_szablonie($e)) $wynik['lang_atr'][] = $e->getAttribute('lang');
    }
    $wynik['lang_atr'] = array_values(array_unique($wynik['lang_atr']));

    // Skrypt i style tylko tam, gdzie skrypt pól — i z regułą widoczności na każdy język.
    require_once ABSPATH . 'wp-admin/includes/admin.php';
    set_current_screen('pola_test');
    ob_start();   // inne moduły drukują przy tym haku (pasek konserwacji) — nie do wyniku
    do_action('admin_enqueue_scripts', 'post.php');
    ob_end_clean();
    $wynik['skrypt'] = wp_script_is('evk-rep-tl', 'enqueued') && wp_style_is('evk-rep-tl', 'enqueued');
    $wynik['style_jezykow'] = implode('', (array) wp_styles()->get_data('evk-rep-tl', 'after'));
    break;

case 'sprawdz':
    $id = evk_tp_id('pola_test');
    $etapy = [];
    $zapisz = static function (callable $zmien) use ($id): string {
        $html = evk_tp_metabox($id, 'grupa_wpis');
        $pary = evk_tp_formularz($html);
        $zmien($pary);
        evk_tp_zapisz_wpis($id, $pary);
        return evk_tp_metabox($id, 'grupa_wpis');
    };
    $pole = static function (string $html, string $n): array { return evk_tp_stan_pol($html)[$n] ?? []; };

    // 1. Zmiana samego oryginału → oba tłumaczenia „Do sprawdzenia", opis nie.
    $h = $zapisz(static function (&$p) { evk_tp_ustaw($p, 'evk_single[tytul]', 'Tytuł PL zmieniony'); });
    $etapy['po_zmianie_pl'] = ['en' => $pole($h, 'evk_single[evk_tl_en__tytul]'), 'de' => $pole($h, 'evk_single[evk_tl_de__tytul]'),
        'opis_en' => $pole($h, 'evk_single[evk_tl_en__opis]')];
    // 2. „Sprawdzone" przy EN (JS wpisuje „teraz") → EN aktualne, DE dalej do sprawdzenia.
    $h = $zapisz(static function (&$p) { evk_tp_ustaw($p, 'evk_single[evk_tl_en__tytul__zrodlo]', 'teraz'); });
    $etapy['po_sprawdzone'] = ['en' => $pole($h, 'evk_single[evk_tl_en__tytul]'), 'de' => $pole($h, 'evk_single[evk_tl_de__tytul]'),
        'zrodlo_en' => get_post_meta($id, 'evk_tl_en__tytul__zrodlo', true)];
    // 3. Tłumacz poprawia DE → aktualne.
    $h = $zapisz(static function (&$p) { evk_tp_ustaw($p, 'evk_single[evk_tl_de__tytul]', 'Titel DE neu'); });
    $etapy['po_poprawce_de'] = ['de' => $pole($h, 'evk_single[evk_tl_de__tytul]')];
    // 4. Oryginał w wierszu → tłumaczenie w tym wierszu do sprawdzenia, w drugim nie.
    $h = $zapisz(static function (&$p) {
        foreach ($p as $i => $para) {
            if (preg_match('/^evk_single\[lista\]\[0\]\[naglowek\]$/', $para[0])) $p[$i][1] = 'Nagłówek 1 zmieniony';
        }
    });
    $st = evk_tp_stan_pol($h);
    $etapy['wiersz'] = ['w0' => $st['evk_single[lista][0][evk_tl_en__naglowek]'] ?? null, 'w1' => $st['evk_single[lista][1][evk_tl_en__naglowek]'] ?? null];
    // 5. Wyczyszczone tłumaczenie → meta znika razem ze źródłem.
    $zapisz(static function (&$p) { evk_tp_ustaw($p, 'evk_single[evk_tl_en__opis]', '   '); });
    $etapy['wyczyszczone'] = ['wartosc' => metadata_exists('post', $id, 'evk_tl_en__opis'), 'zrodlo' => metadata_exists('post', $id, 'evk_tl_en__opis__zrodlo')];
    // 6. Tłumaczenie do pustego oryginału, potem oryginał wpisany → do sprawdzenia.
    $h = $zapisz(static function (&$p) { evk_tp_ustaw($p, 'evk_single[evk_tl_en__pusty]', 'Only EN'); });
    $etapy['pusty_oryginal'] = ['zrodlo' => get_post_meta($id, 'evk_tl_en__pusty__zrodlo', true), 'stan' => $pole($h, 'evk_single[evk_tl_en__pusty]')];
    $h = $zapisz(static function (&$p) { evk_tp_ustaw($p, 'evk_single[pusty]', 'Teraz jest'); });
    $etapy['pusty_wpisany'] = $pole($h, 'evk_single[evk_tl_en__pusty]');
    // 7. Formatowanie w WYSIWYG bez zmiany tekstu → bez „Do sprawdzenia".
    $h = $zapisz(static function (&$p) { evk_tp_ustaw($p, 'evk_single[tresc]', "<p>Treść\n<em>PL</em></p>"); });
    $etapy['wysiwyg_format'] = $pole($h, 'evk_single[evk_tl_en__tresc]');
    $wynik = $etapy;
    break;

case 'przenies':
    $id = evk_tp_id('pola_test');
    $przed = ['lista' => get_post_meta($id, 'lista', true), 'de_tytul' => get_post_meta($id, 'evk_tl_de__tytul', true)];
    // A. Niemiecki wyłączony w Tłumaczeniach: zwykły zapis wpisu nie gubi niemieckich tłumaczeń.
    add_filter('evk_fields_jezyki', static function ($j) { unset($j['de']); return $j; }, 20);
    $html = evk_tp_metabox($id, 'grupa_wpis');
    $x = evk_tp_dom($html);
    $wynik['a_widoczne_de'] = $x->query('//div[' . evk_tp_klasa('evk-tl-pole') . '][@data-lang="de"]')->length;
    $wynik['a_ukryte_de'] = [];
    foreach (evk_tp_formularz($html) as $p) if (strpos($p[0], 'evk_tl_de__') !== false) $wynik['a_ukryte_de'][] = $p[0];
    evk_tp_zapisz_wpis($id, evk_tp_formularz($html));
    $po = get_post_meta($id, 'lista', true);
    $wynik['a_de_w_wierszu'] = [$przed['lista'][0]['evk_tl_de__naglowek'] ?? null, $po[0]['evk_tl_de__naglowek'] ?? null,
        $przed['lista'][0]['evk_tl_de__naglowek__zrodlo'] ?? null, $po[0]['evk_tl_de__naglowek__zrodlo'] ?? null];
    $wynik['a_de_pojedyncze'] = [$przed['de_tytul'], get_post_meta($id, 'evk_tl_de__tytul', true)];
    remove_all_filters('evk_fields_jezyki', 20);

    // B. „Nie tłumacz" zaznaczone po fakcie na nagłówku: pola znikają, dane zostają.
    $grupy = evk_rep_groups();
    $grupy['grupa_wpis']['fields']['lista']['sub_fields']['naglowek']['no_translate'] = true;
    $GLOBALS['evk_rep_groups_memo'] = $grupy;
    $html = evk_tp_metabox($id, 'grupa_wpis');
    $x = evk_tp_dom($html);
    $wynik['b_pola_naglowka'] = 0;
    foreach ($x->query('//*[' . evk_tp_klasa('evk-tl-wejscie') . ']') as $e) {
        /** @var DOMElement $e */
        if (!evk_tp_w_szablonie($e) && strpos($e->getAttribute('name'), '__naglowek') !== false) $wynik['b_pola_naglowka']++;
    }
    evk_tp_zapisz_wpis($id, evk_tp_formularz($html));
    $po2 = get_post_meta($id, 'lista', true);
    $wynik['b_en_w_wierszu'] = [$po[0]['evk_tl_en__naglowek'] ?? null, $po2[0]['evk_tl_en__naglowek'] ?? null];
    // Odczyt z „Nie tłumacz": polski, choć tłumaczenie leży w wierszu.
    $GLOBALS['lang_code'] = 'en';
    evk_rep_stack_push(['row' => $po2[0], 'fields' => $grupy['grupa_wpis']['fields']['lista']['sub_fields'], 'post_id' => $id, 'path' => 'lista']);
    $wynik['b_odczyt_en'] = evk_rep_resolve('naglowek');
    evk_rep_stack_pop();
    unset($GLOBALS['evk_rep_groups_memo']);
    break;

case 'odczyt':
    // Dane wprost w bazie (drogę zapisu sprawdza „zapis"), wpis z edytorem bloków.
    $id  = evk_tp_id('post');
    $tid = evk_tp_term();
    foreach (['tytul' => 'Tytuł', 'evk_tl_en__tytul' => 'Title', 'evk_tl_de__tytul' => 'Titel',
              'opis' => 'Opis', 'evk_tl_en__opis' => 'Description',
              'tresc' => '<p>Treść</p>', 'evk_tl_en__tresc' => '<p>Content</p>', 'evk_tl_de__tresc' => '<p> </p>',
              'pusty' => '', 'evk_tl_en__pusty' => 'Ghost',
              'kod' => 'ABC-1', 'evk_tl_en__kod' => 'IGNORED'] as $k => $v) update_post_meta($id, $k, $v);
    update_post_meta($id, 'przycisk', ['url' => home_url('/kontakt/'), 'title' => 'Napisz']);
    update_post_meta($id, 'evk_tl_en__przycisk', 'Write');
    update_post_meta($id, 'lista', [
        ['naglowek' => 'Nagłówek', 'evk_tl_en__naglowek' => 'Heading', 'ikona' => 'ikona', 'evk_tl_en__ikona' => 'icon',
         'odnosnik' => ['url' => 'https://example.com/a/', 'title' => 'Więcej'], 'evk_tl_en__odnosnik' => 'More'],
    ]);
    update_term_meta($tid, 'podpis', 'Podpis');
    update_term_meta($tid, 'evk_tl_en__podpis', 'Caption');
    update_option('evk_rep_opt_grupa_opcje', ['slogan' => 'Hasło', 'evk_tl_en__slogan' => 'Motto', 'stopka' => 'Stopka',
        'przyciski' => [['etykieta' => 'Sklep', 'evk_tl_en__etykieta' => 'Shop', 'adres' => ['url' => '/kontakt/', 'title' => 'Tu'], 'evk_tl_en__adres' => 'Here']]], false);

    $czytaj = static function () use ($id, $tid) {
        $r = [
            'tytul' => evk_get_field('tytul', $id), 'opis' => evk_get_field('opis', $id), 'tresc' => evk_get_field('tresc', $id),
            'pusty' => evk_get_field('pusty', $id), 'kod' => evk_get_field('kod', $id),
            'przycisk_url' => evk_get_field('przycisk', $id), 'przycisk_title' => evk_get_field('przycisk', $id, 'title'),
            'przycisk_html' => evk_get_field('przycisk', $id, 'html'),
            'slogan' => evk_rep_resolve_option('grupa_opcje_slogan'), 'stopka' => evk_rep_resolve_option('grupa_opcje_stopka'),
        ];
        $lista = get_post_meta($id, 'lista', true);
        $pola  = evk_rep_groups()['grupa_wpis']['fields']['lista']['sub_fields'];
        evk_rep_stack_push(['row' => $lista[0], 'fields' => $pola, 'post_id' => $id, 'path' => 'lista']);
        $r['wiersz'] = [evk_rep_resolve('naglowek'), evk_rep_resolve('ikona'), evk_rep_resolve('odnosnik'), evk_rep_resolve('odnosnik', 'title')];
        evk_rep_stack_pop();
        $opcje = get_option('evk_rep_opt_grupa_opcje');
        $pola_o = evk_rep_groups()['grupa_opcje']['fields']['przyciski']['sub_fields'];
        evk_rep_stack_push(['row' => $opcje['przyciski'][0], 'fields' => $pola_o, 'path' => 'evk_opt_grupa_opcje.przyciski']);
        $r['wiersz_opcji'] = [evk_rep_resolve('etykieta'), evk_rep_resolve('adres'), evk_rep_resolve('adres', 'title')];
        evk_rep_stack_pop();
        $GLOBALS['evk_rep_current_term'] = $tid;
        $r['term'] = evk_rep_resolve('podpis');
        unset($GLOBALS['evk_rep_current_term']);
        return $r;
    };
    foreach (['pl' => '', 'en' => 'en', 'de' => 'de', 'fr' => 'fr'] as $nazwa => $kod) {
        $GLOBALS['lang_code'] = $kod;
        $wynik[$nazwa] = $czytaj();
    }
    // Builder Bricksa w widoku EN (?lang=en ustawia język) — i tak polski.
    $GLOBALS['lang_code'] = 'en';
    $_GET['bricks'] = 'run';
    $wynik['builder'] = $czytaj();
    unset($_GET['bricks']);
    $GLOBALS['lang_code'] = '';
    $wynik['home'] = home_url();
    break;

case 'term':
    $tid  = evk_tp_term();
    $term = get_term($tid);
    ob_start();
    evk_rep_term_edit_fields($term, 'category');
    $html = (string) ob_get_clean();
    $x = evk_tp_dom($html);
    $wynik['przelacznik'] = $x->query('//div[' . evk_tp_klasa('evk-tl-przelacznik') . ']//button')->length;
    $wynik['pola_en'] = $x->query('//div[' . evk_tp_klasa('evk-tl-pole') . '][@data-lang="en"]')->length;
    $pary = evk_tp_formularz($html);
    evk_tp_ustaw($pary, 'evk_single[podpis]', 'Podpis kategorii');
    evk_tp_ustaw($pary, 'evk_single[evk_tl_en__podpis]', 'Category caption');
    evk_tp_ustaw($pary, 'evk_single[evk_tl_de__baner]', '<p>Banner DE</p>');
    $_POST = wp_slash(evk_tp_dane($pary));
    do_action('edited_category', $tid, (int) $term->term_taxonomy_id);
    $_POST = [];
    $wynik['meta'] = ['podpis' => get_term_meta($tid, 'podpis', true), 'en' => get_term_meta($tid, 'evk_tl_en__podpis', true),
        'en_zrodlo' => get_term_meta($tid, 'evk_tl_en__podpis__zrodlo', true), 'de_baner' => get_term_meta($tid, 'evk_tl_de__baner', true),
        'skrot' => evk_tp_skrot('Podpis kategorii')];
    // Profil użytkownika: bez pól języków (grupa użytkownika nie ma przełącznika).
    ob_start();
    evk_rep_render_group_object('user', 1, 'grupa_term', evk_rep_groups()['grupa_term']);
    $wynik['uzytkownik_przelacznik'] = substr_count((string) ob_get_clean(), 'evk-tl-przelacznik');
    break;

case 'opcje':
    $grupa = evk_rep_groups()['grupa_opcje'];
    update_option('evk_rep_opt_grupa_opcje', ['slogan' => 'Stare', 'evk_tl_de__slogan' => 'Alt DE', 'evk_tl_de__slogan__zrodlo' => 'abc123abc123'], false);
    add_filter('evk_fields_jezyki', static function ($j) { unset($j['de']); return $j; }, 20);   // DE wyłączony: ma przetrwać jako pole ukryte
    ob_start();
    evk_rep_render_option_group('grupa_opcje', $grupa);
    $html = (string) ob_get_clean();
    remove_all_filters('evk_fields_jezyki', 20);
    $pary = evk_tp_formularz($html);
    evk_tp_ustaw($pary, 'evk_opt[grupa_opcje][slogan]', 'Nowe hasło');
    evk_tp_ustaw($pary, 'evk_opt[grupa_opcje][evk_tl_en__slogan]', 'New motto');
    $w = evk_tp_wiersz($html, 'evk_opt[grupa_opcje][przyciski]', 'p1');
    evk_tp_ustaw($w, 'evk_opt[grupa_opcje][przyciski][p1][etykieta]', 'Sklep');
    evk_tp_ustaw($w, 'evk_opt[grupa_opcje][przyciski][p1][evk_tl_en__etykieta]', 'Shop');
    evk_tp_ustaw($w, 'evk_opt[grupa_opcje][przyciski][p1][adres][url]', '/kontakt/');
    evk_tp_ustaw($w, 'evk_opt[grupa_opcje][przyciski][p1][adres][title]', 'Tu');
    evk_tp_ustaw($w, 'evk_opt[grupa_opcje][przyciski][p1][evk_tl_en__adres]', 'Here');
    $pary = array_merge($pary, $w);
    $wynik['ukryte_de'] = evk_tp_wartosc($pary, 'evk_opt[grupa_opcje][evk_tl_de__slogan]');
    $_POST = wp_slash(evk_tp_dane($pary) + ['evk_settings_page' => 'pola-ustawienia', 'evk_settings_tab' => '0',
        'evk_settings_nonce' => wp_create_nonce('evk_settings_save_pola-ustawienia')]);
    // Handler zapisu kończy się przekierowaniem i exit — wynik drukuje funkcja zamknięcia.
    register_shutdown_function(static function () use (&$wynik) {
        $wynik['opcja'] = get_option('evk_rep_opt_grupa_opcje');
        echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });
    add_filter('wp_redirect', static function () { return false; });
    require_once ABSPATH . 'wp-admin/includes/admin.php';
    do_action('admin_init');
    $wynik['blad'] = 'handler nie zakończył żądania';
    exit;

case 'adresy':
    $h = home_url();
    $przypadki = [
        ['http://pola.test/kontakt/', 'en'], ['http://pola.test/kontakt/', 'de'], ['/kontakt/', 'en'], ['/kontakt', 'en'],
        ['http://pola.test/', 'en'], ['http://pola.test', 'en'], ['http://pola.test/de/kontakt-de/', 'en'],
        ['http://pola.test/en/contact/', 'en'], ['http://pola.test/kontakt/?a=1#b', 'en'], ['http://www.pola.test/kontakt/', 'en'],
        ['http://pola.test/o-nas/zespol/', 'en'], ['https://example.com/kontakt/', 'en'], ['mailto:a@b.pl', 'en'],
        ['tel:+48123', 'en'], ['#sekcja', 'en'], ['http://pola.test/wp-content/uploads/plik.pdf', 'en'], ['/cennik.pdf', 'en'],
        ['//cdn.example.com/x/', 'en'], ['http://pola.test/kontakt/', 'pl'], ['http://pola.test/kontakt/', 'fr'],
    ];
    foreach ($przypadki as [$u, $l]) $wynik[] = [$u, $l, evk_tl_fields_url($u, $l)];
    $wynik = ['home' => $h, 'przypadki' => $wynik];
    break;

case 'wylaczone':
    // Tłumaczenia wyłączone = nikt nie podaje języków.
    remove_all_filters('evk_fields_jezyki');
    $id   = evk_tp_id('pola_test');
    $przed = get_post_meta($id, 'lista', true);
    $html = evk_tp_metabox($id, 'grupa_wpis');
    $wynik['przelacznik'] = substr_count($html, 'evk-tl-przelacznik');
    $wynik['pola_jezykow'] = substr_count($html, 'evk-tl-pole');
    $wynik['klasy'] = substr_count($html, 'evk-tl-tak') + substr_count($html, 'evk-tl-pl"');
    $ukryte = [];
    foreach (evk_tp_formularz($html) as $p) if (strpos($p[0], '[evk_tl_') !== false) $ukryte[] = $p[0];
    $wynik['ukryte'] = count($ukryte);
    evk_tp_zapisz_wpis($id, evk_tp_formularz($html));
    $po = get_post_meta($id, 'lista', true);
    $wynik['wiersz_przed_po'] = [$przed[0] ?? null, $po[0] ?? null];
    $GLOBALS['lang_code'] = 'en';
    $wynik['odczyt_en'] = evk_get_field('tytul', $id);
    $wynik['odczyt_pl'] = get_post_meta($id, 'tytul', true);
    $wynik['meta_en_tytul'] = get_post_meta($id, 'evk_tl_en__tytul', true);
    break;

case 'stan':
    $id   = evk_tp_id('pola_test');
    $wpis = evk_tp_id('post');
    $tid  = evk_tp_term();
    $meta = [];
    foreach (get_post_meta($id) as $k => $v) {
        if (strpos($k, 'evk_tl_') === 0 || in_array($k, ['tytul', 'opis', 'tresc', 'pusty', 'lista', 'grupa_wiersze'], true)) $meta[$k] = maybe_unserialize($v[0]);
    }
    $wynik = [
        'klasyk' => $id, 'wpis' => $wpis, 'term' => $tid, 'meta' => $meta,
        'skrot_tytul' => evk_rep_tl_hash((string) get_post_meta($id, 'tytul', true)),
        'wpis_meta' => ['tytul' => get_post_meta($wpis, 'tytul', true), 'evk_tl_en__tytul' => get_post_meta($wpis, 'evk_tl_en__tytul', true),
            'evk_tl_en__tresc' => get_post_meta($wpis, 'evk_tl_en__tresc', true)],
        'term_meta' => ['podpis' => get_term_meta($tid, 'podpis', true), 'evk_tl_en__podpis' => get_term_meta($tid, 'evk_tl_en__podpis', true)],
        'opcje' => get_option('evk_rep_opt_grupa_opcje'),
        'nowe_termy' => [],
    ];
    foreach (['nowa-kategoria-pola', 'druga-kategoria-pola'] as $slug) {
        $nt = get_term_by('slug', $slug, 'category');
        $wynik['nowe_termy'][$slug] = $nt ? ['podpis' => get_term_meta((int) $nt->term_id, 'podpis', true),
            'evk_tl_en__podpis' => get_term_meta((int) $nt->term_id, 'evk_tl_en__podpis', true)] : null;
    }
    break;

default:
    $wynik = ['brak' => 'nieznane polecenie: ' . $evk_tryb];
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
