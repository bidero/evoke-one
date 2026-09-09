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
            'meta'    => ['label' => 'Meta SEO',    'icon' => 'dashicons-edit',         'szukaj' => 'tytuł opis description'],
            'sitemap' => ['label' => 'Mapa strony', 'icon' => 'dashicons-networking',   'szukaj' => 'sitemap xml indeksowanie'],
            'schema'  => ['label' => 'Schema',      'icon' => 'dashicons-database',     'szukaj' => 'json-ld dane strukturalne'],
            'og'      => ['label' => 'OpenGraph',   'icon' => 'dashicons-format-image', 'szukaj' => 'og:image social facebook podgląd'],
        ],
        'bezpieczenstwo' => [
            'login'     => ['label' => 'Limit logowań', 'icon' => 'dashicons-lock',       'szukaj' => 'brute force blokada ip'],
            'rest'      => ['label' => 'REST API',      'icon' => 'dashicons-rest-api',   'szukaj' => 'api json wp-json'],
            'hardening' => ['label' => 'Ochrona WP',    'icon' => 'dashicons-shield-alt', 'szukaj' => 'hardening wersja edytor plików'],
            'cleanup'   => ['label' => 'Czyszczenie',   'icon' => 'dashicons-trash',      'szukaj' => 'xml-rpc rss rewizje śmietnik'],
        ],
        'narzedzia' => [
            'snippets'    => ['label' => 'Fragmenty kodu',    'icon' => 'dashicons-editor-code',     'szukaj' => 'snippety skrypty php kod functions.php css js'],
            'smtp'        => ['label' => 'SMTP',              'icon' => 'dashicons-email-alt',       'szukaj' => 'poczta mail wysyłka serwer'],
            'redirect'    => ['label' => 'Przekierowania 301','icon' => 'dashicons-redo',            'szukaj' => '301 redirect przekierowanie'],
            'logs404'     => ['label' => 'Logi 404',          'icon' => 'dashicons-warning',         'szukaj' => '404 nieistniejące adresy'],
            'rewizje'     => ['label' => 'Rewizje',           'icon' => 'dashicons-backup',          'szukaj' => 'historia wersje sprzątanie bazy wp_posts limit'],
            'maintenance' => ['label' => 'Konserwacja',       'icon' => 'dashicons-admin-tools',     'szukaj' => 'maintenance przerwa techniczna'],
            'io'          => ['label' => 'Eksport / Import',  'icon' => 'dashicons-database-import', 'szukaj' => 'kopia migracja ustawień json'],
        ],
        'admin_panel' => [
            'interface'  => ['label' => 'Interfejs',     'icon' => 'dashicons-admin-appearance',  'szukaj' => 'kokpit menu porządki'],
            'dashboard'  => ['label' => 'Kokpit',        'icon' => 'dashicons-dashboard',         'szukaj' => 'bricks ekran startowy'],
            'avatar'     => ['label' => 'Avatar',        'icon' => 'dashicons-admin-users',       'szukaj' => 'gravatar zdjęcie profilowe'],
            /* Bez „rewizji" w słowach pomocniczych: ten ekran ich nie dotyka
               i nigdy nie dotykał, a wyszukiwarka prowadziła po tym słowie
               właśnie tutaj. Od 1.150.0 mają własny ekran w Narzędziach. */
            'content'    => ['label' => 'Treść',         'icon' => 'dashicons-admin-comments',    'szukaj' => 'komentarze autozapis edytor'],
            'whitelabel' => ['label' => 'White label',   'icon' => 'dashicons-admin-customizer',  'szukaj' => 'logo stopka marka'],
            'roles'      => ['label' => 'Role Manager',  'icon' => 'dashicons-groups',            'szukaj' => 'role uprawnienia capabilities dostępy'],
        ],
    ];
}

/**
 * SEKCJE, KTÓRE OTWIERAJĄ SIĘ EKRANEM PRZEGLĄDU.
 *
 * Na razie jedna. Przegląd jest próbą kształtu: zanim rozejdzie się na
 * pozostałe cztery sekcje, ma zostać obejrzany na Frontendzie — tam, gdzie
 * ekranów jest najwięcej (12) i gdzie są oba przypadki brzegowe: ekran
 * z jednym włącznikiem i ekran z listą włączników pod spodem.
 *
 * Lista rozstrzyga trzy rzeczy naraz — co robi `?tab=` bez `?sub=`, czy pasek
 * boczny dokłada pozycję „Przegląd" i czy paleta zna ten ekran — więc żadna
 * z nich nie może się od pozostałych oderwać.
 */
function evoke_one_sekcje_z_przegladem(): array {
    return ['wydajnosc'];
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

/* `evoke_one_render_subtabs()` stała tutaj do 1.139.1. Rysowała nad treścią
   pasek ekranów bieżącej sekcji — czyli od 1.138.0 to samo, co drugi poziom
   paska bocznego, tylko innym krojem. Ekran snippetów ma własny pasek WIDOKÓW
   (`.evo-viewtabs` w `includes/snippets/panel.php`), ale to inny poziom: widoki
   jednego ekranu, których pasek boczny nie zna. */
