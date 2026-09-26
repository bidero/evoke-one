<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Widok „Teksty w elementach" (1.245.0) — prawdziwe dane w testowym
 * WordPressie (tools/testowy-wp.sh).
 *
 *   php tests/php/tl-teksty.php scenariusz   wiersze, pochodzenie, filtry, stronicowanie
 *   php tests/php/tl-teksty.php ui-ustaw     dane zostają, moduł włączony (Chromium)
 *   php tests/php/tl-teksty.php ekran        zasiew, HTML całej zakładki, sprzątanie (admin-etykiety)
 *   php tests/php/tl-teksty.php sprzataj     wpisy testu usunięte, opcje przywrócone
 *
 * Strony zapisywane BEZ filtra uzupełniania (53), żeby widok pokazał teksty
 * tłumaczone słownikiem, a nie przepisane już do pól.
 */
require __DIR__ . '/_testowy-wp.php';

foreach (['evk_tl_el_niepuste' => '51-translation-element-fields.php', 'evk_tl_el_meta_zmieniona' => '52-translation-element-review.php',
          'evk_tl_el_uzupelnij' => '53-translation-element-transfer.php'] as $evk_fn => $evk_plik) {
    if (!function_exists($evk_fn)) require $evk_root . '/includes/' . $evk_plik;
}

const EVK_TEST_TL_TYTUL = 'Test TL — teksty';
const EVK_TEST_TL_KOPIA = 'evk_test_tl_teksty_kopia';
const EVK_TEST_TL_BAZA  = 'https://example.test/wp-admin/options-general.php?page=evoke-tlumaczenia';

function evk_test_wpisy(): array {
    global $wpdb;
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_title LIKE %s", $wpdb->esc_like(EVK_TEST_TL_TYTUL) . '%')));
}
function evk_test_wpis(string $dopisek, string $typ = 'page'): int {
    return (int) wp_insert_post(['post_title' => EVK_TEST_TL_TYTUL . $dopisek, 'post_type' => $typ, 'post_status' => 'publish']);
}
/** Zapis bez uzupełniania ze słownika (53) — „Do sprawdzenia" (52) działa dalej. */
function evk_test_zapisz(int $id, string $klucz, array $tresc): void {
    remove_filter('update_post_metadata', 'evk_tl_el_przed_zapisem', 10);
    remove_filter('add_post_metadata', 'evk_tl_el_przed_zapisem', 10);
    update_post_meta($id, $klucz, $tresc);
    add_filter('update_post_metadata', 'evk_tl_el_przed_zapisem', 10, 5);
    add_filter('add_post_metadata', 'evk_tl_el_przed_zapisem', 10, 5);
}

function evk_test_sprzataj(): void {
    foreach (evk_test_wpisy() as $id) wp_delete_post($id, true);
    $kopia = get_option(EVK_TEST_TL_KOPIA, null);
    if (!is_array($kopia)) return;
    foreach (['slownik' => 'tl_translations', 'mapa' => EVK_TL_EL_MAPA, 'jezyki' => 'tl_languages', 'modul' => 'evk_tl_module_enabled'] as $k => $opcja) {
        if ($kopia[$k] === null) delete_option($opcja); else update_option($opcja, $kopia[$k]);
    }
    delete_option(EVK_TEST_TL_KOPIA);
    tl_invalidate_cache();
}

/** Słownik, mapa i trzy wpisy: strona, strona z „do sprawdzenia", szablon nagłówka. */
function evk_test_zasiej(bool $modul = false): array {
    if (get_option(EVK_TEST_TL_KOPIA, null) === null) {
        update_option(EVK_TEST_TL_KOPIA, [
            'slownik' => get_option('tl_translations', null), 'mapa' => get_option(EVK_TL_EL_MAPA, null),
            'jezyki' => get_option('tl_languages', null), 'modul' => get_option('evk_tl_module_enabled', null),
        ], false);
    }
    update_option('tl_languages', [['code' => 'en', 'name' => 'English'], ['code' => 'de', 'name' => 'Deutsch']]);
    update_option('tl_translations', ['groups' => ['g' => ['name' => 'Test', 'rows' => [
        'r1' => ['pl' => 'Kontakt', 'en' => 'Contact', 'de' => 'Kontakt (DE)'],
        'r2' => ['pl' => 'Jeden', 'en' => 'One', 'de' => ''],
        'r3' => ['pl' => 'Witaj świecie', 'en' => 'Hello world', 'de' => 'Hallo Welt'],
    ]]]]);
    tl_invalidate_cache();
    if ($modul) update_option('evk_tl_module_enabled', 1);
    delete_option(EVK_TL_EL_MAPA);
    evk_tl_el_kontrolki(['text' => ['tab' => 'content', 'type' => 'text']], 'heading');
    evk_tl_el_kontrolki(['accordions' => ['tab' => 'content', 'type' => 'repeater', 'fields' => [
        'title' => ['label' => 'Tytuł', 'type' => 'text'], 'content' => ['label' => 'Treść', 'type' => 'editor']]]], 'accordion');
    evk_tl_el_kontrolki(['tag' => ['tab' => 'content', 'type' => 'select']], 'container');
    evk_tl_el_zapisz_mape();

    $K = '_bricks_page_content_2';
    $a = evk_test_wpis(' A');
    evk_test_zapisz($a, $K, [
        ['id' => 'h1abcd', 'name' => 'heading', 'settings' => ['text' => 'Kontakt', 'evk_tl_en__text' => 'Contact us']],
        ['id' => 'a1abcd', 'name' => 'accordion', 'settings' => ['accordions' => [
            ['id' => 'p1', 'title' => 'Jeden', 'content' => ''],
            ['id' => 'p2', 'title' => '', 'content' => '<p>Witaj świecie</p><p>Nieznany tekst</p>'],
        ]]],
        ['id' => 'c1abcd', 'name' => 'container', 'settings' => ['tag' => 'section']],
        ['id' => 'x1abcd', 'name' => 'nieznany-element', 'settings' => ['text' => 'Kontakt']],
    ]);
    $b = evk_test_wpis(' B');
    evk_test_zapisz($b, $K, [['id' => 'h2abcd', 'name' => 'heading', 'settings' => ['text' => 'Oferta', 'evk_tl_en__text' => 'Offer']]]);
    evk_test_zapisz($b, $K, [['id' => 'h2abcd', 'name' => 'heading', 'settings' => ['text' => 'Oferta specjalna', 'evk_tl_en__text' => 'Offer']]]);
    $c = evk_test_wpis(' C', 'bricks_template');
    evk_test_zapisz($c, '_bricks_page_header_2', [['id' => 'h3abcd', 'name' => 'heading', 'settings' => ['text' => 'Kontakt']]]);
    return [$a, $b, $c];
}

/** Wiersze testu w zwięzłej postaci: „strona|element|opis|pl|en:zrodlo:tekst|de:zrodlo:tekst|S". */
function evk_test_wiersze(array $d, array $ids): array {
    $out = [];
    foreach ($d['wiersze'] as $w) {
        $nr = array_search($w['post_id'], $ids, true);
        if ($nr === false) continue;
        $l = [];
        foreach ($w['jezyki'] as $j => $x) $l[] = $j . ':' . $x['zrodlo'] . ':' . $x['tekst'] . ($x['sprawdz'] ? ':S' : '');
        $out[] = 'ABC'[$nr] . '|' . $w['czesc'] . '|' . $w['element'] . '|' . $w['opis'] . '|' . $w['pl'] . '|' . implode('|', $l);
    }
    return $out;
}

$tryb = $argv[1] ?? '';
$wynik = [];

switch ($tryb) {
    case 'scenariusz':
        evk_test_sprzataj();
        $ids = evk_test_zasiej();
        $d = evk_tl_el_teksty();
        $wynik['wiersze'] = evk_test_wiersze($d, $ids);
        $wynik['liczby'] = $d['liczby'];
        $wynik['nieznane'] = $d['nieznane'];
        $render = static function (string $pokaz, int $str, int $na) {
            ob_start();
            evk_tl_el_widok_tekstow($pokaz, $str, EVK_TEST_TL_BAZA, $na);
            return (string) ob_get_clean();
        };
        $wynik['html_wszystko'] = $render('wszystko', 1, 50);
        $wynik['html_braki'] = $render('braki', 1, 50);
        $wynik['html_sprawdz'] = $render('sprawdz', 1, 50);
        $wynik['html_nieznany_filtr'] = $render('<script>', 1, 50);
        $wynik['html_str2'] = $render('wszystko', 2, 2);
        $wynik['html_str99'] = $render('wszystko', 99, 2);
        $wynik['ile_wierszy_na_str'] = [2 => (int) ceil($d['liczby']['wszystko'] / 2)];
        // Mapa pusta: komunikat zamiast listy.
        delete_option(EVK_TL_EL_MAPA);
        $wynik['html_bez_mapy'] = $render('wszystko', 1, 50);
        evk_test_sprzataj();
        break;

    case 'ui-ustaw':
        evk_test_sprzataj();
        $ids = evk_test_zasiej(true);
        $wynik = ['wp' => rtrim(ABSPATH, '/'), 'ids' => $ids];
        break;

    case 'ekran':
        evk_test_sprzataj();
        evk_test_zasiej();
        ob_start();
        require $evk_root . '/includes/admin/tl/tab-elementy.php';
        $html = (string) ob_get_clean();
        evk_test_sprzataj();
        echo $html;
        exit;

    case 'sprzataj':
        evk_test_sprzataj();
        $wynik = ['ok' => true];
        break;

    default:
        $wynik = ['brak' => 'nieznane polecenie: ' . $tryb];
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE);
