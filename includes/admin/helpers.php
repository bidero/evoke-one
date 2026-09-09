<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Admin helpers
 * Funkcje pomocnicze używane przez wszystkie zakładki.
 */

/**
 * ZAKŁADKI PANELU — najwyższy poziom nawigacji.
 *
 * Stały wcześniej w `evoke_one_render_settings()`, czyli były niewidoczne dla
 * palety wyszukiwania i dla ekranu startowego, które renderują się w osobnych
 * funkcjach. Razem z `evoke_one_ekrany()` niżej tworzą komplet struktury
 * panelu: osiem zakładek i 33 ekrany w środku.
 */
function evoke_one_zakladki(): array {
    return [
        'dashboard'      => ['label' => 'Pulpit',          'icon' => 'dashicons-dashboard'],
        'wydajnosc'      => ['label' => 'Frontend',        'icon' => 'dashicons-desktop'],
        'strona'         => ['label' => 'SEO',             'icon' => 'dashicons-search'],
        'bezpieczenstwo' => ['label' => 'Bezpieczeństwo',  'icon' => 'dashicons-shield'],
        'narzedzia'      => ['label' => 'Narzędzia',       'icon' => 'dashicons-admin-tools'],
        'admin_panel'    => ['label' => 'Panel admina',    'icon' => 'dashicons-admin-settings'],
        'newsletter'     => ['label' => 'Newsletter',      'icon' => 'dashicons-email-alt'],
        'forminbox'      => ['label' => 'Formularze',      'icon' => 'dashicons-feedback'],
    ];
}

/**
 * MAPA EKRANÓW PANELU — jedno miejsce, trzech odbiorców.
 *
 * Do 1.138.0 każdy plik zakładki trzymał swoją listę podzakładek u siebie,
 * w lokalnej zmiennej `$subs`. Widział ją wyłącznie ten plik, który akurat się
 * renderował — więc pasek boczny nie mógł pokazać, co jest w środku sekcji,
 * a wyszukiwarka miała własną listę czternastu pozycji wpisanych z ręki obok
 * `$tabs`. Panel ma 33 ekrany; ta lista rozjeżdżała się z rzeczywistością przy
 * pierwszym dołożonym module i nie było jak tego zauważyć.
 *
 * Teraz czytają stąd trzy rzeczy: drugi poziom paska bocznego, paleta
 * wyszukiwania i same pliki zakładek (sprawdzają, czy `?sub=` z adresu
 * istnieje). Dołożenie modułu w jednym miejscu pokazuje go wszędzie.
 *
 * Czwartym odbiorcą były do 1.139.1 paski podzakładek nad treścią
 * (`evoke_one_render_subtabs()`). Od 1.138.0 wypisywały to samo, co pasek
 * boczny, więc odeszły razem z funkcją.
 *
 * `szukaj` to słowa pomocnicze do wyszukiwarki. Etykiety są polskie, a nazwy,
 * pod którymi ludzie znają te rzeczy — nie: „dark mode", „gsap", „301".
 * Bez nich wpisanie „dark" nie znajduje „Trybu ciemnego".
 *
 * `opis` i `przelaczniki` czyta EKRAN PRZEGLĄDU SEKCJI (1.163.0). Opis to jedno
 * zdanie do wiersza listy; `przelaczniki` to pary „opcja/pole", którymi ten
 * ekran daje się włączyć — patrz `evoke_one_przelaczniki()` niżej. Na razie ma
 * je wyłącznie Frontend: przegląd jest próbą kształtu, a nie gotową zmianą we
 * wszystkich sekcjach.
 */
function evoke_one_ekrany(): array {
    return [
        'wydajnosc' => [
            'parallax'    => ['label' => 'Parallax',           'icon' => 'dashicons-image-flip-vertical', 'szukaj' => 'paralaksa scroll tło',
                              'opis' => 'Tło sekcji przesuwa się wolniej niż treść.',
                              'przelaczniki' => [['evk_parallax', 'enabled']]],
            'darkmode'    => ['label' => 'Tryb ciemny',        'icon' => 'dashicons-lightbulb',           'szukaj' => 'dark mode ciemny motyw',
                              'opis' => 'Przełączanie motywu z przejściami CSS i View Transition API.',
                              'przelaczniki' => [['evk_darkmode', 'enabled']]],
            'cursor'      => ['label' => 'Kursor',             'icon' => 'dashicons-arrow-up-alt',        'szukaj' => 'cursor wskaźnik myszka',
                              'opis' => 'Niestandardowy kursor zintegrowany z GSAP.',
                              'przelaczniki' => [['evk_cursor', 'enabled']]],
            'lenis'       => ['label' => 'Płynne przewijanie', 'icon' => 'dashicons-sort',                'szukaj' => 'lenis smooth scroll',
                              'opis' => 'Płynne przewijanie strony oparte o bibliotekę Lenis.',
                              'przelaczniki' => [['evk_lenis', 'enabled']]],
            'animator'    => ['label' => 'Animator',           'icon' => 'dashicons-controls-play',       'szukaj' => 'gsap animacje scrolltrigger presety',
                              'opis' => 'Animacje GSAP dla elementów Bricks przez klasę evk-anim-{slug}.',
                              'przelaczniki' => [['evk_animator', 'enabled']]],
            'bgshift'     => ['label' => 'Tło przy scrollu',   'icon' => 'dashicons-art',                 'szukaj' => 'background kolor sekcji',
                              'opis' => 'Kolor tła przewija się płynnie od sekcji do sekcji.',
                              'przelaczniki' => [['evk_bgshift', 'enabled']]],
            'fonts'       => ['label' => 'Czcionki (FOUT)',    'icon' => 'dashicons-editor-textcolor',    'szukaj' => 'fonts webfont typografia',
                              'opis' => 'Preload lokalnych plików czcionek — ogranicza miganie tekstu.',
                              'przelaczniki' => [['evk_fonts', 'enabled']]],
            'sierotki'    => ['label' => 'Sierotki',           'icon' => 'dashicons-editor-paragraph',    'szukaj' => 'typografia spójniki twarda spacja nbsp wdowy',
                              'opis' => 'Spójniki jednoliterowe nie zostają na końcu wiersza.',
                              'przelaczniki' => [['evk_sierotki', 'enabled']]],
            'themecolor'  => ['label' => 'Paski przeglądarki', 'icon' => 'dashicons-smartphone',          'szukaj' => 'theme-color pasek telefon',
                              'opis' => 'Kolor pasków Safari na telefonie, stały mimo zmiany sekcji.',
                              'przelaczniki' => [['evk_theme_color', 'enabled']]],
            'a11y'        => ['label' => 'Dostępność',         'icon' => 'dashicons-universal-access',    'szukaj' => 'accessibility a11y kontrast wcag',
                              'opis' => 'Widget WCAG — kontrast, czcionki, sterowanie głosem.',
                              'przelaczniki' => [['evk_a11y', 'enabled']]],
            /* Lista przełączników PROSTO Z REJESTRU, tak jak w
               `evk_toggle_allowlist()` — przepisana rozjechałaby się przy
               pierwszym nowym elemencie. Rozwiązuje ją `evoke_one_przelaczniki()`. */
            'elementy'    => ['label' => 'Elementy Bricks',    'icon' => 'dashicons-screenoptions',       'szukaj' => 'marquee hscroll offcanvas splide',
                              'opis' => 'Elementy dokładane do edytora Bricks.',
                              'przelaczniki' => 'rejestr-elementow'],
            'tlumaczenia' => ['label' => 'Tłumaczenia',        'icon' => 'dashicons-translation',         'szukaj' => 'języki wielojęzyczność i18n',
                              'opis' => 'Silnik wielojęzyczności i pływający przycisk edycji.',
                              'przelaczniki' => [['evk_tl_module_enabled', '_scalar'], ['evk_tl_fab_enabled', '_scalar']]],
        ],
        'strona' => [
            'meta'    => ['label' => 'Meta SEO',    'icon' => 'dashicons-edit',         'szukaj' => 'tytuł opis description',
                          'opis' => 'Tytuły i opisy stron w wynikach wyszukiwania.'],
            /* BEZ PRZEŁĄCZNIKA, i to jest decyzja, nie przeoczenie. Jedyna flaga
               tego ekranu — `tl_sitemap_settings['enabled']` — jest w panelu
               podpisana „Włącz sekcję tłumaczeń w wp-sitemap.xml", więc
               przełącznik obok nazwy „Mapa strony" mówiłby nieprawdę. Nie ma jej
               też na `evk_toggle_allowlist()` i nie dokładamy jej tam po to,
               żeby wiersz wyglądał jak reszta. */
            'sitemap' => ['label' => 'Mapa strony', 'icon' => 'dashicons-networking',   'szukaj' => 'sitemap xml indeksowanie',
                          'opis' => 'Sekcja tłumaczeń w wp-sitemap.xml i diagnostyka noindex.'],
            'schema'  => ['label' => 'Schema',      'icon' => 'dashicons-database',     'szukaj' => 'json-ld dane strukturalne',
                          'opis' => 'Dane strukturalne JSON-LD dla wyszukiwarek.',
                          'przelaczniki' => [['evk_schema', 'enabled']]],
            'og'      => ['label' => 'OpenGraph',   'icon' => 'dashicons-format-image', 'szukaj' => 'og:image social facebook podgląd',
                          'opis' => 'Obrazek i opis podglądu przy udostępnianiu.',
                          'przelaczniki' => [['evk_og', 'enabled']]],
        ],
        'bezpieczenstwo' => [
            'login'     => ['label' => 'Limit logowań', 'icon' => 'dashicons-lock',       'szukaj' => 'brute force blokada ip',
                            'opis' => 'Blokada adresu po serii nieudanych logowań.',
                            'przelaczniki' => [['evk_security', 'limit_login_enabled']]],
            'rest'      => ['label' => 'REST API',      'icon' => 'dashicons-rest-api',   'szukaj' => 'api json wp-json',
                            'opis' => 'Ograniczenie dostępu do REST API.',
                            'przelaczniki' => [['evk_security', 'rest_block_all']]],
            /* Dwa niezależne pola, zapisywane formularzem na własnym ekranie.
               Licznik tylko CZYTA ich stan — nie wymaga ani przełącznika, ani
               nowego wpisu na białej liście. */
            'hardening' => ['label' => 'Ochrona WP',    'icon' => 'dashicons-shield-alt', 'szukaj' => 'hardening wersja edytor plików',
                            'opis' => 'Ukrycie wersji WP i motywów z paczki.',
                            'przelaczniki' => [['evk_security', 'hide_wp_version'], ['evk_security', 'disable_bundled_themes']]],
            'cleanup'   => ['label' => 'Czyszczenie',   'icon' => 'dashicons-trash',      'szukaj' => 'xml-rpc rss rewizje śmietnik',
                            'opis' => 'Wyłączenie XML-RPC i kanałów RSS.',
                            'przelaczniki' => [['evk_cleanup', 'disable_xmlrpc'], ['evk_cleanup', 'remove_rss']]],
        ],
        'narzedzia' => [
            'snippets'    => ['label' => 'Fragmenty kodu',    'icon' => 'dashicons-editor-code',     'szukaj' => 'snippety skrypty php kod functions.php css js',
                              'opis' => 'Własny PHP, CSS i JS bez ruszania functions.php.',
                              'przelaczniki' => [['evk_snippets_enabled', '_scalar'], ['evk_snippets_advanced_enabled', '_scalar']]],
            'smtp'        => ['label' => 'SMTP',              'icon' => 'dashicons-email-alt',       'szukaj' => 'poczta mail wysyłka serwer',
                              'opis' => 'Wysyłka poczty przez serwer SMTP zamiast mail().',
                              'przelaczniki' => [['evk_smtp', 'enabled']]],
            /* PRZEŁĄCZNIK TYLKO TUTAJ, ekran modułu zostaje formularzowy.
               Na własnym ekranie te dwa moduły mają włącznik jadący submitem,
               bo AJAX i POST razem strzelały podwójnie (patrz komentarz
               w `tools-redirect301.php`). Przegląd to osobny ekran z jedną drogą
               zapisu, więc tamten problem tu nie wraca — a obie opcje są już na
               `evk_toggle_allowlist()`, więc nie poszerzamy granicy. */
            'redirect'    => ['label' => 'Przekierowania 301','icon' => 'dashicons-redo',            'szukaj' => '301 redirect przekierowanie',
                              'opis' => 'Przekierowania z licznikiem kliknięć i wildcards.',
                              'przelaczniki' => [['evk_301_enabled', '_scalar']]],
            'logs404'     => ['label' => 'Logi 404',          'icon' => 'dashicons-warning',         'szukaj' => '404 nieistniejące adresy',
                              'opis' => 'Rejestr nieistniejących adresów, z pomijaniem botów.',
                              'przelaczniki' => [['evk_404_enabled', '_scalar']]],
            'rewizje'     => ['label' => 'Rewizje',           'icon' => 'dashicons-backup',          'szukaj' => 'historia wersje sprzątanie bazy wp_posts limit',
                              'opis' => 'Limit rewizji wpisów i sprzątanie bazy.',
                              'przelaczniki' => [['evk_rewizje', 'limit_on']]],
            /* JEDYNY PRZEŁĄCZNIK Z POTWIERDZENIEM. Na liście stoi obok dziewięciu
               innych, wygląda tak samo, a kosztuje widoczność całej strony dla
               gości. Pytamy wyłącznie przy włączaniu — wyłączenie przywraca stan
               normalny i zwłoka w nim nikomu nie służy. */
            'maintenance' => ['label' => 'Konserwacja',       'icon' => 'dashicons-admin-tools',     'szukaj' => 'maintenance przerwa techniczna',
                              'opis' => 'Strona niedostępna dla gości, widoczna dla zalogowanych.',
                              'przelaczniki' => [['maintenance_mode', '_scalar']],
                              'potwierdzenie' => 'Strona przestanie być widoczna dla gości. Włączyć konserwację?'],
            'io'          => ['label' => 'Eksport / Import',  'icon' => 'dashicons-database-import', 'szukaj' => 'kopia migracja ustawień json',
                              'opis' => 'Kopia ustawień wtyczki do pliku i z powrotem.'],
        ],
        'admin_panel' => [
            'interface'  => ['label' => 'Interfejs',     'icon' => 'dashicons-admin-appearance',  'szukaj' => 'kokpit menu porządki',
                             'opis' => 'Porządki w listach wpisów i menu WordPressa.'],
            'dashboard'  => ['label' => 'Kokpit',        'icon' => 'dashicons-dashboard',         'szukaj' => 'bricks ekran startowy',
                             'opis' => 'Kokpit Bricks Builder zamiast ekranu WordPressa.',
                             'przelaczniki' => [['evoke_dashboard_active', '_scalar'], ['evoke_dashboard_remove_native', '_scalar'],
                                                ['evoke_dashboard_remove_help', '_scalar'], ['evoke_dashboard_fit_content', '_scalar'],
                                                ['evoke_dashboard_shadow', '_scalar']]],
            'avatar'     => ['label' => 'Avatar',        'icon' => 'dashicons-admin-users',       'szukaj' => 'gravatar zdjęcie profilowe',
                             'opis' => 'Zdjęcia profilowe użytkowników bez Gravatara.'],
            /* Bez „rewizji" w słowach pomocniczych: ten ekran ich nie dotyka
               i nigdy nie dotykał, a wyszukiwarka prowadziła po tym słowie
               właśnie tutaj. Od 1.150.0 mają własny ekran w Narzędziach. */
            'content'    => ['label' => 'Treść',         'icon' => 'dashicons-admin-comments',    'szukaj' => 'komentarze autozapis edytor',
                             'opis' => 'Komentarze i zachowanie edytora treści.',
                             'przelaczniki' => [['evoke_disable_global_comments', '_scalar'], ['evoke_require_reg_to_comment', '_scalar']]],
            'whitelabel' => ['label' => 'White label',   'icon' => 'dashicons-admin-customizer',  'szukaj' => 'logo stopka marka',
                             'opis' => 'Własne logo i stopka w panelu WordPressa.',
                             'przelaczniki' => [['evk_white_label', 'enabled']]],
            'roles'      => ['label' => 'Role Manager',  'icon' => 'dashicons-groups',            'szukaj' => 'role uprawnienia capabilities dostępy',
                             'opis' => 'Role użytkowników i ich uprawnienia.'],
        ],
    ];
}

/**
 * SEKCJE, KTÓRE OTWIERAJĄ SIĘ EKRANEM PRZEGLĄDU.
 *
 * Wszystkie pięć, które mają ekrany w środku. Do 1.163.0 stał tu sam Frontend —
 * przegląd był próbą kształtu i miał zostać obejrzany, zanim rozejdzie się
 * dalej. Został obejrzany.
 *
 * Newslettera i Formularzy tu nie ma i nie będzie, dopóki nie dostaną ekranów:
 * to zakładki z jednym modułem, więc przegląd byłby listą o jednej pozycji
 * prowadzącą tam, gdzie już jesteś. Lista bierze się z mapy ekranów, więc
 * dołożenie im podzakładek włączy przegląd samo.
 *
 * Lista rozstrzyga trzy rzeczy naraz — co robi `?tab=` bez `?sub=`, czy pasek
 * boczny dokłada pozycję „Przegląd" i czy paleta zna ten ekran — więc żadna
 * z nich nie może się od pozostałych oderwać.
 */
function evoke_one_sekcje_z_przegladem(): array {
    return array_keys(evoke_one_ekrany());
}

/**
 * EKRANY, KTÓRYCH WŁĄCZNIK ŻYJE WYŁĄCZNIE NA PRZEGLĄDZIE.
 *
 * PUSTA OD 1.166.0 — i niech taka zostanie.
 *
 * Stały tu Przekierowania 301 i Logi 404: na własnych ekranach miały włącznik
 * jadący submitem formularza, bo AJAX i POST razem strzelały tam podwójnie
 * (1.14.4). Oba jadą już AJAX-em, więc wyjątek zniknął razem z powodem.
 *
 * Funkcja zostaje, bo sprawdzenie porównuje listę znalezioną z zadeklarowaną
 * W OBIE STRONY: dopisanie tu ekranu bez powodu zapali tak samo, jak pojawienie
 * się ekranu z włącznikiem tylko na przeglądzie. Pusta lista jest najmocniejszym
 * stanem, jaki to sprawdzenie może mieć — każdy przełącznik na przeglądzie
 * przełącza to samo, co ekran modułu.
 */
function evoke_one_przelacznik_tylko_na_przegladzie(): array {
    return [];
}

/**
 * PRZEŁĄCZNIKI EKRANU — pary „opcja/pole", którymi da się go włączyć.
 *
 * Zwraca listę, nie pojedynczą parę, bo ekrany dzielą się na trzy przypadki
 * i przegląd rysuje każdy inaczej: zero par to sam odsyłacz, jedna para to
 * przełącznik, wiele par to licznik „N z M włączonych". Rozstrzyga o tym
 * DŁUGOŚĆ listy, więc nie ma tu gałęzi per ekran.
 *
 * Elementy Bricksa są jedynym wpisem liczonym z rejestru — dokładnie tak, jak
 * robi to `evk_toggle_allowlist()`, i z tego samego powodu: lista przepisana
 * ręcznie rozjechała się w 1.56.0 przy pierwszym nowym elemencie.
 */
function evoke_one_przelaczniki(string $tab, string $sub): array {
    $ekran = evoke_one_ekrany()[$tab][$sub] ?? [];
    $spis  = $ekran['przelaczniki'] ?? [];

    if ($spis === 'rejestr-elementow') {
        if (!function_exists('evk_elements_registry')) return [];
        $pary = [];
        foreach (array_keys(evk_elements_registry()) as $klucz) $pary[] = ['evk_elements', $klucz];
        return $pary;
    }

    return is_array($spis) ? $spis : [];
}

/**
 * Czy ta para „opcja/pole" jest włączona.
 *
 * `_scalar` odpowiada gałęzi w `evk_ajax_toggle`: opcje płaskie handler zapisuje
 * jako '1' albo '' i tak samo trzeba je czytać. Bez tego rozróżnienia przegląd
 * pokazywałby przy Tłumaczeniach stan wyłączony niezależnie od bazy — czyli
 * kłamałby po cichu, bo nic by się nie wywróciło.
 */
function evoke_one_wlaczony(string $option, string $field): bool {
    if ($field === '_scalar') return (bool) get_option($option, 0);

    $wartosc = get_option($option, []);
    return is_array($wartosc) && !empty($wartosc[$field]);
}

/**
 * Ile modułów sekcji jest włączonych i ile da się włączyć.
 *
 * MIANOWNIKIEM SĄ EKRANY Z JEDNYM WŁĄCZNIKIEM, nie wszystkie ekrany sekcji.
 * Frontend ma 12 ekranów, ale Elementy Bricksa i Tłumaczenia mają pod sobą po
 * kilka niezależnych przełączników i własne liczniki w wierszu. Wrzucenie ich
 * do zbiorczej liczby dawałoby „13 z 12" albo kazałoby zgadywać, czy ekran
 * z czterema włączonymi elementami liczy się jako jeden włączony — a liczba
 * w nagłówku ma odpowiadać temu, co widać na liście obok.
 */
function evoke_one_stan_sekcji(string $tab): array {
    $wlaczone = 0;
    $wszystkie = 0;

    foreach (array_keys(evoke_one_ekrany()[$tab] ?? []) as $sub) {
        $pary = evoke_one_przelaczniki($tab, $sub);
        if (count($pary) !== 1) continue;

        $wszystkie++;
        if (evoke_one_wlaczony($pary[0][0], $pary[0][1])) $wlaczone++;
    }

    return ['wlaczone' => $wlaczone, 'wszystkie' => $wszystkie];
}

/**
 * PASEK ZAPISU — przycisk i potwierdzenie, jedno miejsce dla całego panelu.
 *
 * Do 1.167.0 pasków było dwadzieścia dziewięć i każdy budował się u siebie.
 * Wychodziło z tego pięć różnych sposobów potwierdzania zapisu: zielony
 * `.evo-save-msg` obok przycisku (4 ekrany), zielony `.tl-save-status` z własną
 * klasą i stylem wpisanym w PHP (6 ekranów tłumaczeń), CZARNE natywne
 * powiadomienie WordPressa u góry strony (Logi 404, snippety, newsletter),
 * napis wpisywany w sam przycisk (Meta SEO) — a na dwudziestu pięciu paskach
 * nie było niczego. ZGŁOSZONE Z UŻYCIA: „czasami jest przesunięty maksymalnie
 * w prawo, a czasami czarny".
 *
 * „Maksymalnie w prawo" brało się z `.evo-save-bar .evo-save-msg { margin-left:
 * auto }` — reguła odpychała komunikat na drugi koniec paska, więc przy szerokiej
 * karcie stał metr od przycisku, którego dotyczył.
 *
 * Teraz jest jedna funkcja i jeden wygląd: zielony tekst tuż na prawo od
 * przycisku. Ekran, który nie woła tej funkcji, nie ma paska zapisu w ogóle —
 * nie ma jak mieć własnego wariantu.
 *
 * `$zapisano === null` (domyślnie) pyta o `?settings-updated`, które WordPress
 * dokłada wracając z `options.php` — czyli ekrany na Settings API dostają
 * potwierdzenie bez ani jednej linii u siebie. Ekrany z własnym POST-em podają
 * `true`, gdy właśnie zapisały. `false` renderuje komunikat ukryty, dla ekranów,
 * które pokazują go z JavaScriptu po zapisie AJAX-em.
 */
function evoke_one_pasek_zapisu(string $etykieta = 'Zapisz ustawienia', ?bool $zapisano = null, string $klasy = ''): void {
    echo '<div class="evo-save-bar">';
    submit_button($etykieta, 'primary', 'submit', false);
    evoke_one_komunikat_zapisu($zapisano, $klasy);
    echo '</div>';
}

/**
 * SAM KOMUNIKAT, bez paska.
 *
 * Pięć pasków w panelu ma przycisk innego kształtu, niż umie `submit_button()`
 * z funkcji wyżej: przycisk typu `button` z własnym `onclick` (Mapa strony),
 * przyciski o własnych nazwach pola i przycisk z odsyłaczem „Anuluj" obok
 * (snippety). Dokładanie na to trzech kolejnych parametrów zrobiłoby z paska
 * funkcję, której nikt nie czyta ze zrozumieniem — więc rozdzielone jest to,
 * co naprawdę ma być wspólne: WYGLĄD I TREŚĆ KOMUNIKATU. Pasek typowy woła tę
 * funkcję u siebie, nietypowy woła ją wprost.
 *
 * `role="status"` czyta czytnik ekranu. Przy zapisie AJAX-em nie ma ani nowej
 * strony, ani powiadomienia WordPressa, więc bez tego niewidzący nie dostaje
 * żadnego sygnału, że zapis się wydarzył.
 */
function evoke_one_komunikat_zapisu(?bool $zapisano = null, string $klasy = ''): void {
    /* Domyślnie pytamy o `?settings-updated`, które WordPress dokłada wracając
       z `options.php` — ekrany na Settings API dostają potwierdzenie bez ani
       jednej linii u siebie. */
    if ($zapisano === null) $zapisano = !empty($_GET['settings-updated']);

    printf(
        '<span class="evo-save-msg%s%s" role="status">✓ Zapisano</span>',
        $zapisano ? ' is-widoczny' : '',
        $klasy !== '' ? ' ' . esc_attr($klasy) : ''
    );
}

/* `evoke_one_render_subtabs()` stała tutaj do 1.139.1. Rysowała nad treścią
   pasek ekranów bieżącej sekcji — czyli od 1.138.0 to samo, co drugi poziom
   paska bocznego, tylko innym krojem. Ekran snippetów ma własny pasek WIDOKÓW
   (`.evo-viewtabs` w `includes/snippets/panel.php`), ale to inny poziom: widoki
   jednego ekranu, których pasek boczny nie zna. */
