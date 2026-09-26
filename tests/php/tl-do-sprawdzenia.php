<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * „Do sprawdzenia" dla pól języków w elementach (1.242.0) — prawdziwe
 * update_post_meta() w testowym WordPressie (tools/testowy-wp.sh).
 *
 *   php tests/php/tl-do-sprawdzenia.php scenariusz   kolejne zapisy i lista po każdym
 *   php tests/php/tl-do-sprawdzenia.php ui-ustaw     moduł włączony + strona z jednym miejscem do sprawdzenia
 *   php tests/php/tl-do-sprawdzenia.php stan <id>    lista dla wpisu
 *   php tests/php/tl-do-sprawdzenia.php sprzataj     wpisy testu usunięte, opcja modułu przywrócona
 *
 * Moduł tłumaczeń ładuje się tylko przy włączonej opcji, więc sonda dociąga
 * jego pliki sama, jeśli funkcji jeszcze nie ma (wtyczka jest DOWIĄZANIEM —
 * pytamy o funkcję, nie o ścieżkę, jak w _testowy-wp.php).
 */
require __DIR__ . '/_testowy-wp.php';

foreach (['evk_tl_el_niepuste' => '51-translation-element-fields.php', 'evk_tl_el_meta_zmieniona' => '52-translation-element-review.php'] as $evk_fn => $evk_plik) {
    if (!function_exists($evk_fn)) require $evk_root . '/includes/' . $evk_plik;
}

const EVK_TEST_TL_TYTUL  = 'Test TL — do sprawdzenia';
const EVK_TEST_TL_KOPIA2 = 'evk_test_tl_sprawdzenia_kopia';

/** Wpisy tego testu (także po przerwanym przebiegu). */
function evk_test_wpisy(): array {
    global $wpdb;
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TEST_TL_TYTUL)));
}
function evk_test_sprzataj(): void {
    foreach (evk_test_wpisy() as $id) wp_delete_post($id, true);
}
function evk_test_wpis(string $typ = 'page'): int {
    return (int) wp_insert_post(['post_title' => EVK_TEST_TL_TYTUL, 'post_type' => $typ, 'post_status' => 'publish']);
}
/** Treść w kształcie Bricksa: nagłówek i akordeon. */
function evk_test_tresc(string $naglowek, string $naglowek_en, string $poz1, string $poz1_en): array {
    return [
        ['id' => 'h1abcd', 'name' => 'heading', 'parent' => 0, 'children' => [],
         'settings' => ['text' => $naglowek, 'tag' => 'h1', 'evk_tl_en__text' => $naglowek_en, 'evk_tl_de__text' => '']],
        ['id' => 'a1abcd', 'name' => 'accordion', 'parent' => 0, 'children' => [],
         'settings' => ['accordions' => [
             ['id' => 'p1', 'title' => $poz1, 'evk_tl_en__title' => $poz1_en],
             ['id' => 'p2', 'title' => 'Dwa', 'evk_tl_en__title' => ''],
         ]]],
    ];
}
function evk_test_lista(int $id): array {
    return array_values(array_map(fn($m) => ['pole' => $m['pole'], 'jezyk' => $m['jezyk'], 'oryginal' => $m['oryginal'],
        'tlumaczenie' => $m['tlumaczenie'], 'meta' => $m['meta_key'], 'klucz' => $m['klucz']],
        array_filter(evk_tl_el_do_sprawdzenia(), fn($m) => $m['post_id'] === $id)));
}

$tryb = $argv[1] ?? '';
$wynik = [];

switch ($tryb) {
    case 'scenariusz':
        evk_test_sprzataj();
        $id = evk_test_wpis();
        $K = '_bricks_page_content_2';
        $krok = function (string $opis, array $tresc) use ($id, $K, &$wynik) {
            update_post_meta($id, $K, $tresc);
            $stan = get_post_meta($id, EVK_TL_EL_STAN, true);
            $wynik[$opis] = ['lista' => evk_test_lista($id), 'stan' => is_array($stan) ? array_keys($stan[$K] ?? []) : null];
        };
        $krok('1 przetłumaczone', evk_test_tresc('Witaj', 'Welcome', 'Jeden', 'One'));
        $krok('2 zmiana nagłówka PL', evk_test_tresc('Witaj ponownie', 'Welcome', 'Jeden', 'One'));
        $krok('3 zmiana pozycji PL', evk_test_tresc('Witaj ponownie', 'Welcome', 'Pierwszy', 'One'));
        $krok('4 nowe tłumaczenie nagłówka', evk_test_tresc('Witaj ponownie', 'Welcome back', 'Pierwszy', 'One'));
        $krok('5 pozycja PL wraca do dawnej', evk_test_tresc('Witaj ponownie', 'Welcome back', 'Jeden', 'One'));
        $krok('6 znów zmiana nagłówka PL', evk_test_tresc('Witaj po raz trzeci', 'Welcome back', 'Jeden', 'One'));
        $wynik['7 sprawdzone'] = ['ok' => evk_tl_el_oznacz_sprawdzone($id, $K, 'h1abcd|text|en'), 'lista' => evk_test_lista($id)];
        $wynik['7b obcy klucz meta'] = evk_tl_el_oznacz_sprawdzone($id, '_obca_meta', 'h1abcd|text|en');
        $krok('8 tłumaczenie nagłówka wyczyszczone', evk_test_tresc('Witaj po raz trzeci', '', 'Jeden', 'One'));
        // Szablon nagłówka strony: inny klucz metadanych, typ spoza wyszukiwania.
        $sz = evk_test_wpis('bricks_template');
        update_post_meta($sz, '_bricks_page_header_2', evk_test_tresc('Menu', 'Menu EN', 'Jeden', ''));
        update_post_meta($sz, '_bricks_page_header_2', evk_test_tresc('Nawigacja', 'Menu EN', 'Jeden', ''));
        $wynik['9 szablon nagłówka'] = evk_test_lista($sz);
        // Inny klucz metadanych w tym samym kształcie — poza zakresem.
        $obcy = evk_test_wpis();
        update_post_meta($obcy, '_moja_meta', evk_test_tresc('A', 'A EN', 'B', 'B EN'));
        update_post_meta($obcy, '_moja_meta', evk_test_tresc('A2', 'A EN', 'B', 'B EN'));
        $wynik['10 obca meta'] = ['stan' => get_post_meta($obcy, EVK_TL_EL_STAN, true), 'lista' => evk_test_lista($obcy)];
        // Treść bez żadnych tłumaczeń — stanu nie ma w ogóle.
        $pusty = evk_test_wpis();
        update_post_meta($pusty, $K, [['id' => 'x', 'name' => 'text', 'settings' => ['text' => 'Sam polski']]]);
        $wynik['11 bez tłumaczeń'] = get_post_meta($pusty, EVK_TL_EL_STAN, true);
        // Sekcja w panelu dla jednego miejsca do sprawdzenia.
        update_post_meta($id, $K, evk_test_tresc('Witaj', 'Welcome', 'Jeden', 'One'));
        update_post_meta($id, $K, evk_test_tresc('Witaj <b>znów</b>', 'Welcome', 'Jeden', 'One'));
        ob_start();
        evk_tl_el_sekcja_do_sprawdzenia();
        $wynik['12 sekcja'] = ob_get_clean();
        evk_test_sprzataj();
        $wynik['13 bez miejsc sekcji nie ma'] = (function () { ob_start(); evk_tl_el_sekcja_do_sprawdzenia(); return ob_get_clean(); })();
        break;

    case 'ui-ustaw':
        evk_test_sprzataj();
        if (get_option(EVK_TEST_TL_KOPIA2, null) === null) {
            update_option(EVK_TEST_TL_KOPIA2, ['modul' => get_option('evk_tl_module_enabled', null)], false);
        }
        update_option('evk_tl_module_enabled', 1);
        $id = evk_test_wpis();
        update_post_meta($id, '_bricks_page_content_2', evk_test_tresc('Witaj', 'Welcome', 'Jeden', 'One'));
        update_post_meta($id, '_bricks_page_content_2', evk_test_tresc('Witaj ponownie', 'Welcome', 'Jeden', 'One'));
        $wynik = ['wp' => rtrim(ABSPATH, '/'), 'id' => $id, 'lista' => evk_test_lista($id)];
        break;

    case 'stan':
        $wynik = ['lista' => evk_test_lista((int) ($argv[2] ?? 0))];
        break;

    case 'sprzataj':
        evk_test_sprzataj();
        $kopia = get_option(EVK_TEST_TL_KOPIA2, null);
        if (is_array($kopia)) {
            if ($kopia['modul'] === null) delete_option('evk_tl_module_enabled');
            else update_option('evk_tl_module_enabled', $kopia['modul']);
            delete_option(EVK_TEST_TL_KOPIA2);
        }
        $wynik = ['ok' => true];
        break;

    default:
        $wynik = ['brak' => 'nieznane polecenie: ' . $tryb];
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE);
