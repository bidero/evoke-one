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
 * panelu: osiem zakładek i 31 ekranów w środku.
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
 * `$tabs`. Panel ma 34 ekrany; ta lista rozjeżdżała się z rzeczywistością przy
 * pierwszym dołożonym module i nie było jak tego zauważyć.
 *
 * Teraz czytają stąd trzy rzeczy: paski podzakładek (`evoke_one_render_subtabs`),
 * drugi poziom paska bocznego i paleta wyszukiwania. Dołożenie modułu w jednym
 * miejscu pokazuje go we wszystkich trzech.
 *
 * `szukaj` to słowa pomocnicze do wyszukiwarki. Etykiety są polskie, a nazwy,
 * pod którymi ludzie znają te rzeczy — nie: „dark mode", „gsap", „301".
 * Bez nich wpisanie „dark" nie znajduje „Trybu ciemnego".
 */
function evoke_one_ekrany(): array {
    return [
        'wydajnosc' => [
            'parallax'    => ['label' => 'Parallax',           'icon' => 'dashicons-image-flip-vertical', 'szukaj' => 'paralaksa scroll tło'],
            'darkmode'    => ['label' => 'Tryb ciemny',        'icon' => 'dashicons-lightbulb',           'szukaj' => 'dark mode ciemny motyw'],
            'cursor'      => ['label' => 'Kursor',             'icon' => 'dashicons-arrow-up-alt',        'szukaj' => 'cursor wskaźnik myszka'],
            'lenis'       => ['label' => 'Płynne przewijanie', 'icon' => 'dashicons-sort',                'szukaj' => 'lenis smooth scroll'],
            'animator'    => ['label' => 'Animator',           'icon' => 'dashicons-controls-play',       'szukaj' => 'gsap animacje scrolltrigger presety'],
            'bgshift'     => ['label' => 'Tło przy scrollu',   'icon' => 'dashicons-art',                 'szukaj' => 'background kolor sekcji'],
            'fonts'       => ['label' => 'Czcionki (FOUT)',    'icon' => 'dashicons-editor-textcolor',    'szukaj' => 'fonts webfont typografia'],
            'themecolor'  => ['label' => 'Paski przeglądarki', 'icon' => 'dashicons-smartphone',          'szukaj' => 'theme-color pasek telefon'],
            'a11y'        => ['label' => 'Dostępność',         'icon' => 'dashicons-universal-access',    'szukaj' => 'accessibility a11y kontrast wcag'],
            'elementy'    => ['label' => 'Elementy Bricks',    'icon' => 'dashicons-screenoptions',       'szukaj' => 'marquee hscroll offcanvas splide'],
            'tlumaczenia' => ['label' => 'Tłumaczenia',        'icon' => 'dashicons-translation',         'szukaj' => 'języki wielojęzyczność i18n'],
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
            'snippets'    => ['label' => 'Skrypty PHP',       'icon' => 'dashicons-editor-code',     'szukaj' => 'snippety kod functions.php css js'],
            'smtp'        => ['label' => 'SMTP',              'icon' => 'dashicons-email-alt',       'szukaj' => 'poczta mail wysyłka serwer'],
            'redirect'    => ['label' => 'Przekierowania 301','icon' => 'dashicons-redo',            'szukaj' => '301 redirect przekierowanie'],
            'logs404'     => ['label' => 'Logi 404',          'icon' => 'dashicons-warning',         'szukaj' => '404 nieistniejące adresy'],
            'maintenance' => ['label' => 'Konserwacja',       'icon' => 'dashicons-admin-tools',     'szukaj' => 'maintenance przerwa techniczna'],
            'io'          => ['label' => 'Eksport / Import',  'icon' => 'dashicons-database-import', 'szukaj' => 'kopia migracja ustawień json'],
        ],
        'admin_panel' => [
            'interface'  => ['label' => 'Interfejs',     'icon' => 'dashicons-admin-appearance',  'szukaj' => 'kokpit menu porządki'],
            'dashboard'  => ['label' => 'Kokpit',        'icon' => 'dashicons-dashboard',         'szukaj' => 'bricks ekran startowy'],
            'avatar'     => ['label' => 'Avatar',        'icon' => 'dashicons-admin-users',       'szukaj' => 'gravatar zdjęcie profilowe'],
            'content'    => ['label' => 'Treść',         'icon' => 'dashicons-admin-comments',    'szukaj' => 'rewizje autozapis edytor'],
            'whitelabel' => ['label' => 'White label',   'icon' => 'dashicons-admin-customizer',  'szukaj' => 'logo stopka marka'],
            'roles'      => ['label' => 'Role Manager',  'icon' => 'dashicons-groups',            'szukaj' => 'role uprawnienia capabilities dostępy'],
        ],
    ];
}

/**
 * Renderuje nawigację podzakładek — spójny styl w całym panelu.
 */
function evoke_one_render_subtabs(array $subs, string $active, string $base_url): void {
    echo '<div class="evk-subtabs">';
    foreach ($subs as $key => $s) {
        $is_active = ($active === $key);
        printf(
            '<a href="%s" class="evk-subtab%s">',
            esc_url(add_query_arg('sub', $key, $base_url)),
            $is_active ? ' is-active' : ''
        );
        printf(
            '<span class="dashicons %s"></span>%s</a>',
            esc_attr($s['icon']),
            esc_html($s['label'])
        );
    }
    echo '</div>';
}
