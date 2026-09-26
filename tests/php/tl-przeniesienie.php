<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Tłumaczenia ze słownika w polach języków elementów (1.244.0) — prawdziwe
 * update_post_meta() w testowym WordPressie (tools/testowy-wp.sh).
 *
 *   php tests/php/tl-przeniesienie.php scenariusz   kolejne zapisy i stan po każdym
 *   php tests/php/tl-przeniesienie.php ui-ustaw     słownik, mapa, moduł włączony, strona sprzed 1.244.0
 *   php tests/php/tl-przeniesienie.php stan <id>    pola języków zapisane we wpisie
 *   php tests/php/tl-przeniesienie.php sprzataj     wpisy testu usunięte, opcje przywrócone
 *
 * Moduł tłumaczeń ładuje się tylko przy włączonej opcji, więc sonda dociąga
 * jego pliki sama, jeśli funkcji jeszcze nie ma (jak tl-do-sprawdzenia.php).
 * Słownik i mapę ustawia PRZED pierwszym użyciem: konfiguracja słownika ma
 * pamięć na czas żądania.
 */
require __DIR__ . '/_testowy-wp.php';

foreach (['evk_tl_el_niepuste' => '51-translation-element-fields.php', 'evk_tl_el_meta_zmieniona' => '52-translation-element-review.php',
          'evk_tl_el_uzupelnij' => '53-translation-element-transfer.php'] as $evk_fn => $evk_plik) {
    if (!function_exists($evk_fn)) require $evk_root . '/includes/' . $evk_plik;
}

const EVK_TEST_TL_TYTUL = 'Test TL — przeniesienie';
const EVK_TEST_TL_KOPIA = 'evk_test_tl_przeniesienie_kopia';

function evk_test_wpisy(): array {
    global $wpdb;
    return array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TEST_TL_TYTUL)));
}
function evk_test_wpis(string $typ = 'page'): int {
    return (int) wp_insert_post(['post_title' => EVK_TEST_TL_TYTUL, 'post_type' => $typ, 'post_status' => 'publish']);
}

/** Opcje, które test zmienia — kopia raz, przed pierwszą zmianą. */
function evk_test_ustaw_srodowisko(bool $modul = false): void {
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
    // Mapa tak, jak zapisałby ją builder: prawdziwy filtr kontrolek na kontrolkach w kształcie Bricksa.
    delete_option(EVK_TL_EL_MAPA);
    evk_tl_el_kontrolki(['text' => ['tab' => 'content', 'type' => 'text'], 'tag' => ['tab' => 'content', 'type' => 'select']], 'heading');
    evk_tl_el_kontrolki(['accordions' => ['tab' => 'content', 'type' => 'repeater', 'fields' => [
        'title' => ['label' => 'Tytuł', 'type' => 'text'], 'content' => ['label' => 'Treść', 'type' => 'editor'],
        'icon' => ['label' => 'Ikona', 'type' => 'icon']]]], 'accordion');
    evk_tl_el_kontrolki(['tag' => ['tab' => 'content', 'type' => 'select']], 'container');
    evk_tl_el_zapisz_mape();
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

/** Treść w kształcie Bricksa; $tl = pola języków do dołożenia: [id elementu][klucz] = wartość. */
function evk_test_tresc(string $naglowek, array $tl = []): array {
    $el = [
        ['id' => 'h1abcd', 'name' => 'heading', 'parent' => 0, 'children' => [], 'settings' => ['text' => $naglowek, 'tag' => 'h1']],
        ['id' => 'h2abcd', 'name' => 'heading', 'parent' => 0, 'children' => [], 'settings' => ['text' => 'Kontakt', 'tag' => 'h2', 'evk_tl_en__text' => 'Get in touch']],
        ['id' => 'a1abcd', 'name' => 'accordion', 'parent' => 0, 'children' => [], 'settings' => ['accordions' => [
            ['id' => 'p1', 'title' => 'Jeden', 'content' => '<p>Jeden</p>'],
            ['id' => 'p2', 'title' => 'Witaj <b>świecie</b>', 'content' => '<p>Witaj świecie</p><p>Kontakt</p>'],
        ]]],
        ['id' => 'c1abcd', 'name' => 'container', 'parent' => 0, 'children' => [], 'settings' => ['tag' => 'section']],
        ['id' => 'x1abcd', 'name' => 'nieznany-element', 'parent' => 0, 'children' => [], 'settings' => ['text' => 'Kontakt']],
    ];
    foreach ($el as &$e) {
        foreach ($tl[$e['id']] ?? [] as $k => $v) {
            if ($v === null) unset($e['settings'][$k]); else $e['settings'][$k] = $v;
        }
    }
    unset($e);
    return $el;
}

/** Pola języków zapisane we wpisie: „id|ścieżka" => wartość (pozycje list po id pozycji). */
function evk_test_pola(int $id, string $meta = '_bricks_page_content_2'): array {
    $out = [];
    foreach ((array) get_post_meta($id, $meta, true) as $el) {
        foreach ((array) ($el['settings'] ?? []) as $k => $v) {
            if (strncmp((string) $k, 'evk_tl_', 7) === 0) $out[$el['id'] . '|' . $k] = $v;
            if (is_array($v) && $v && array_keys($v) === range(0, count($v) - 1)) {
                foreach ($v as $poz) {
                    foreach ((array) $poz as $pk => $pv) {
                        if (strncmp((string) $pk, 'evk_tl_', 7) === 0) $out[$el['id'] . '|' . $k . '.' . ($poz['id'] ?? '?') . '.' . $pk] = $pv;
                    }
                }
            }
        }
    }
    ksort($out);
    return $out;
}

$tryb = $argv[1] ?? '';
$wynik = [];

switch ($tryb) {
    case 'scenariusz':
        evk_test_sprzataj();
        evk_test_ustaw_srodowisko();
        $wynik['mapa'] = evk_tl_el_mapa();
        $K = '_bricks_page_content_2';
        $id = evk_test_wpis();

        $wykaz = static function () use ($id, $K) { return array_keys(evk_tl_el_dopisane($id, $K)); };

        // 1. Pierwszy zapis (add_post_meta pod spodem update_post_meta).
        update_post_meta($id, $K, evk_test_tresc('Kontakt'));
        $wynik['1 pierwszy zapis'] = evk_test_pola($id);
        $wynik['1 wykaz'] = $wykaz();

        /* 2. Builder otwarty przed uzupełnieniem: wysyła stan BEZ pól dopisanych
           przez serwer (wpisane przez użytkownika „Get in touch" zna) i zmienia
           polski nagłówek. */
        update_post_meta($id, $K, evk_test_tresc('Kontakt z nami'));
        $wynik['2 zapis bez pól, zmiana PL'] = ['pola' => evk_test_pola($id),
            'do_sprawdzenia' => array_values(array_map(static function ($m) { return $m['pole'] . ':' . $m['jezyk']; },
                array_filter(evk_tl_el_do_sprawdzenia(), static function ($m) use ($id) { return $m['post_id'] === $id; })))];

        /* 2r. Kopia w rewizji: dane pod identyfikatorem rewizji (add_metadata, jak
           przy kopiowaniu metadanych do rewizji), tu surowy stan z buildera.
           update_post_meta() i delete_post_meta() rewizji WordPress
           przekierowuje na rodzica — wykaz i stan „Do sprawdzenia" strony
           nie mogą się od tego zmienić, a rewizja zostaje, jak przyszła. */
        $stan_przed = get_post_meta($id, EVK_TL_EL_STAN, true);
        $wykaz_przed = $wykaz();
        $rew = (int) _wp_put_post_revision(get_post($id));
        add_metadata('post', $rew, $K, wp_slash(evk_test_tresc('Kontakt z nami')));
        $wynik['2r rewizja'] = [
            'rewizja'   => $rew > 0 && (int) wp_is_post_revision($rew) === $id,
            'w_rewizji' => evk_test_pola($rew),
            'wykaz'     => $wykaz() === $wykaz_przed && $wykaz_przed ? 'bez zmian' : $wykaz(),
            'stan'      => get_post_meta($id, EVK_TL_EL_STAN, true) === $stan_przed && $stan_przed ? 'bez zmian' : 'zmieniony',
        ];

        // 2b. Kolejny zapis z tej samej sesji buildera, polski już bez zmian — pola dalej nieznane builderowi.
        update_post_meta($id, $K, evk_test_tresc('Kontakt z nami'));
        $wynik['2b drugi zapis tej samej sesji'] = evk_test_pola($id);

        // 3. Pole EN jawnie puste (klucz jest) przy polskim spoza słownika — zostaje puste; DE (brak klucza) przeniesione.
        update_post_meta($id, $K, evk_test_tresc('Kontakt z nami', ['h1abcd' => ['evk_tl_en__text' => '']]));
        $wynik['3 jawnie puste EN'] = evk_test_pola($id);

        // 4. To samo pole puste, a polski wraca do frazy ze słownika — pole dostaje tłumaczenie.
        update_post_meta($id, $K, evk_test_tresc('Kontakt', ['h1abcd' => ['evk_tl_en__text' => '']]));
        $wynik['4 puste EN, PL ze słownika'] = evk_test_pola($id);

        /* 4a. Adres buildera („?bricks=run") otwarty przez gościa — dopisać go
           może każdy, więc wykaz zostaje. Zapytanie główne o tę stronę, jak
           przy żądaniu jej adresu; hak woła się wprost, bez reszty
           template_redirect (przekierowania rdzenia kończą żądanie). */
        $wynik['4 wykaz'] = $wykaz();
        $wynik['4 hak'] = has_action('template_redirect', 'evk_tl_el_otwarcie_buildera');
        $_GET['bricks'] = 'run';
        $GLOBALS['wp_the_query'] = $GLOBALS['wp_query'] = new WP_Query(['page_id' => $id]);
        wp_set_current_user(0);
        evk_tl_el_otwarcie_buildera();
        $wynik['4a gosc z ?bricks=run'] = $wykaz();

        // 4b. Otwarcie wpisu w builderze przez administratora — builder zna już wszystko, wykaz znika.
        wp_set_current_user(1);
        evk_tl_el_otwarcie_buildera();
        $wynik['4b wykaz po otwarciu buildera'] = $wykaz();
        unset($_GET['bricks']);
        wp_set_current_user(0);

        /* 4c. Po otwarciu użytkownik czyści pola: nagłówek EN i DE (polski spoza
           słownika), DE drugiego nagłówka i pozycję akordeonu (polski ze
           słownika). Bricks usuwa klucz wyczyszczonego pola (próba 1.243.0). */
        update_post_meta($id, $K, evk_test_tresc('Kontakt z nami'));
        $wynik['4c wyczyszczone po otwarciu'] = evk_test_pola($id);
        $wynik['4c wykaz'] = $wykaz();

        // 5. Obca metadana w tym samym kształcie — nietknięta.
        $obcy = evk_test_wpis();
        update_post_meta($obcy, '_moja_meta', evk_test_tresc('Kontakt'));
        $wynik['5 obca meta'] = evk_test_pola($obcy, '_moja_meta');

        // 6. Szablon nagłówka (typ spoza wyszukiwania, inny klucz metadanych).
        $sz = evk_test_wpis('bricks_template');
        update_post_meta($sz, '_bricks_page_header_2', evk_test_tresc('Kontakt'));
        $wynik['6 szablon nagłówka'] = evk_test_pola($sz, '_bricks_page_header_2');

        // 7. Strona zapisana przed 1.244.0 (bez filtra) — przycisk: podgląd, zapis, podgląd jeszcze raz.
        $stara = evk_test_wpis();
        remove_filter('update_post_metadata', 'evk_tl_el_przed_zapisem', 10);
        remove_filter('add_post_metadata', 'evk_tl_el_przed_zapisem', 10);
        update_post_meta($stara, $K, evk_test_tresc('Kontakt'));
        add_filter('update_post_metadata', 'evk_tl_el_przed_zapisem', 10, 5);
        add_filter('add_post_metadata', 'evk_tl_el_przed_zapisem', 10, 5);
        /* Wcześniejszy wpis wykazu: pole dopisane przez serwer przed
           przyciskiem, którego otwarty builder też nie zna. Przycisk ma go
           zostawić — zapis przez hak wziąłby wszystkie pola z danych za
           przysłane przez builder i wyczyścił wykaz. */
        evk_tl_el_zapisz_dopisane($stara, $K, ['h2abcd|evk_tl_en__text' => true]);
        $wynik['7 wykaz przed'] = array_keys(evk_tl_el_dopisane($stara, $K));
        $moja = static function (array $p) use ($stara) {
            $s = array_values(array_filter($p['strony'], static function ($x) use ($stara) { return $x['post_id'] === $stara; }));
            return ['strona' => $s[0] ?? null, 'nieznane' => $p['nieznane'], 'znane' => $p['znane']];
        };
        $wynik['7a przed przyciskiem'] = evk_test_pola($stara);
        $wynik['7b podgląd'] = $moja(evk_tl_el_przenies(false));
        $wynik['7c po podglądzie nic nie zapisane'] = evk_test_pola($stara);
        $wynik['7d zapis'] = $moja(evk_tl_el_przenies(true));
        $wynik['7e po zapisie'] = evk_test_pola($stara);
        $wynik['7e wykaz'] = array_keys(evk_tl_el_dopisane($stara, $K));
        $wynik['7f drugi podgląd'] = $moja(evk_tl_el_przenies(false));
        evk_test_sprzataj();
        break;

    case 'ui-ustaw':
        evk_test_sprzataj();
        evk_test_ustaw_srodowisko(true);
        $id = evk_test_wpis();
        remove_filter('update_post_metadata', 'evk_tl_el_przed_zapisem', 10);
        remove_filter('add_post_metadata', 'evk_tl_el_przed_zapisem', 10);
        update_post_meta($id, '_bricks_page_content_2', evk_test_tresc('Kontakt'));
        $wynik = ['wp' => rtrim(ABSPATH, '/'), 'id' => $id, 'pola' => evk_test_pola($id)];
        break;

    case 'stan':
        $wynik = ['pola' => evk_test_pola((int) ($argv[2] ?? 0))];
        break;

    case 'sprzataj':
        evk_test_sprzataj();
        $wynik = ['ok' => true];
        break;

    default:
        $wynik = ['brak' => 'nieznane polecenie: ' . $tryb];
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE);
