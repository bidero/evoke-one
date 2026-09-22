<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — mapa strony (wp-sitemap.xml)
 *
 * JEDNA MAPA, NATYWNA. Do 1.220.0 plik trzymał drugi, własny generator pod
 * `/sitemap.xml`, z tagami `hreflang` w każdym wpisie. Ten generator NIGDY nie
 * odpowiadał na żywej stronie: regułę `^sitemap\.xml$` dokładał `init`, ale
 * `flush_rewrite_rules()` wołało się wyłącznie przy aktywacji wtyczki i przy
 * zapisie języków lub slugów (`10-language-system.php`) — po samym włączeniu
 * modułu tłumaczeń reguły w bazie zostawały bez niej. Żądanie szło więc do
 * WordPressa, a ten od 5.5 sam przekierowuje `/sitemap.xml` na
 * `/wp-sitemap.xml`. Sto trzydzieści linii, których nikt nigdy nie zobaczył.
 *
 * HREFLANG W MAPIE MIMO TO JEST — od 1.224.0, tylko inną drogą. Renderer
 * rdzenia (`WP_Sitemaps_Renderer::get_sitemap_xml()`) przyjmuje dla adresu
 * wyłącznie `loc`, `lastmod`, `changefreq` i `priority`, każdy inny klucz
 * kwituje `_doing_it_wrong()`, a przestrzeni `xmlns:xhtml` nie da się dołożyć
 * filtrem. Dlatego sekcja `hreflang` bierze od rdzenia to, co rdzeń robi
 * dobrze — adres, regułę przepisywania i wpis w indeksie — a samą TREŚĆ
 * wypisuje sama, przechwytując żądanie na `template_redirect` przed nim.
 * Własnej reguły przepisywania nie ma tu nigdzie i to jest różnica wobec
 * generatora sprzed 1.222.0: tamten nie odpowiadał właśnie dlatego, że jego
 * reguła nigdy nie trafiła do bazy.
 *
 * Tagi `hreflang` jadą RÓWNOLEGLE w `<head>` każdej podstrony
 * (`12-seo-url-filters.php`). Oba źródła są równorzędne i oba czytają ten sam
 * wybór języka domyślnego (`evk_sitemap_jezyk_domyslny()`) — rozbieżność
 * byłaby sygnałem sprzecznym, rozstrzyganym przez wyszukiwarkę po swojemu.
 *
 * PLIK ŁADUJE SIĘ ZAWSZE, nie tylko przy włączonych tłumaczeniach. Steruje
 * mapą, którą WordPress wystawia na każdej stronie, więc związanie go
 * z modułem tłumaczeń znaczyłoby, że na stronie bez tłumaczeń nie ma czym
 * sterować. Sekcja hreflang (dawny provider `translations`) siedzi na końcu
 * i rejestruje się tylko wtedy, gdy silnik języków faktycznie jest.
 */

// =========================================================================
// KTÓRE TYPY TREŚCI I TAKSONOMIE TRAFIAJĄ DO MAPY
// =========================================================================

/**
 * Typy treści „poza indeksem" — suma dwóch źródeł.
 *
 * Panel Evoke ONE zna każdy zarejestrowany typ, bez względu na to, kto go
 * zarejestrował (Evoke FIELDS, ACF, Metabox, motyw). Evoke FIELDS zna własne
 * typy w miejscu, w którym się je definiuje, i wystawia je przez
 * `evk_noindex_post_types()`. Flaga z FIELDS jest wiążąca — panel jej nie
 * odznaczy, bo odznaczenie w drugim miejscu wyglądałoby na skuteczne, a przy
 * następnym zapisie tamtej wtyczki wracałoby bez śladu.
 */
function evk_sitemap_typy_noindex(): array {
    $ustawienia = tl_get_sitemap_settings();
    $z_panelu   = array_map('sanitize_key', (array) ($ustawienia['noindex_types'] ?? []));
    $z_fields   = function_exists('evk_noindex_post_types') ? evk_noindex_post_types() : [];

    return array_values(array_unique(array_merge($z_panelu, array_map('sanitize_key', (array) $z_fields))));
}

/** Taksonomie „poza indeksem" — jak wyżej, źródło w FIELDS to `evk_noindex_taxonomies()`. */
function evk_sitemap_taksonomie_noindex(): array {
    $ustawienia = tl_get_sitemap_settings();
    $z_panelu   = array_map('sanitize_key', (array) ($ustawienia['noindex_taxonomies'] ?? []));
    $z_fields   = function_exists('evk_noindex_taxonomies') ? evk_noindex_taxonomies() : [];

    return array_values(array_unique(array_merge($z_panelu, array_map('sanitize_key', (array) $z_fields))));
}

/**
 * Czy WordPress w ogóle wystawia mapę strony.
 *
 * TEN SAM WARUNEK, KTÓREGO UŻYWA RDZEŃ w `WP_Sitemaps::sitemaps_enabled()`:
 * opcja „widoczność dla wyszukiwarek" plus filtr, którym wtyczki mapę wyłączają.
 * Ekran startowy pytał wcześniej o `tl_sitemap_settings['enabled']`, a to pole
 * od 1.222.0 znaczy wyłącznie „sekcja hreflang włączona" — strona bez
 * tłumaczeń miała więc poprawną mapę i czerwoną kontrolkę obok niej.
 *
 * Czerwień znaczy teraz coś, co warto zobaczyć: Ustawienia → Czytanie
 * odradzają wyszukiwarkom indeksowanie, więc mapy nie ma wcale.
 */
function evk_sitemap_wystawiana(): bool {
    return (bool) apply_filters('wp_sitemaps_enabled', (bool) get_option('blog_public'));
}

function evk_sitemap_typ_poza_indeksem(string $typ): bool {
    return in_array($typ, evk_sitemap_typy_noindex(), true);
}

function evk_sitemap_taksonomia_poza_indeksem(string $taksonomia): bool {
    return in_array($taksonomia, evk_sitemap_taksonomie_noindex(), true);
}

/**
 * Typy treści usunięte z mapy — „poza indeksem" plus te odznaczone w panelu.
 *
 * Różnica między jednym a drugim: „poza indeksem" wyprowadza treść z wyników
 * wyszukiwania (`noindex` + `exclude_from_search`), samo odznaczenie w mapie
 * mówi tylko „nie zgłaszaj tego Google", zostawiając stronę indeksowalną, gdy
 * ktoś na nią trafi linkiem.
 */
function evk_sitemap_typy_wykluczone(): array {
    $ustawienia = tl_get_sitemap_settings();
    $odznaczone = array_map('sanitize_key', (array) ($ustawienia['excluded_types'] ?? []));

    return array_values(array_unique(array_merge($odznaczone, evk_sitemap_typy_noindex())));
}

function evk_sitemap_taksonomie_wykluczone(): array {
    $ustawienia = tl_get_sitemap_settings();
    $odznaczone = array_map('sanitize_key', (array) ($ustawienia['excluded_taxonomies'] ?? []));

    return array_values(array_unique(array_merge($odznaczone, evk_sitemap_taksonomie_noindex())));
}

add_filter('wp_sitemaps_post_types', function ($types) {
    foreach (evk_sitemap_typy_wykluczone() as $slug) {
        unset($types[$slug]);
    }
    return $types;
});

add_filter('wp_sitemaps_taxonomies', function ($taxonomies) {
    foreach (evk_sitemap_taksonomie_wykluczone() as $slug) {
        unset($taxonomies[$slug]);
    }
    return $taxonomies;
});

/**
 * Sekcja użytkowników — dopiero teraz sterowana.
 *
 * Pole `include_users` stało w ustawieniach i w panelu od 1.95.0, zapisywało
 * się poprawnie i NIE BYŁO CZYTANE PRZEZ NIC. `wp-sitemap-users-1.xml` wisiał
 * niezależnie od tego, co pokazywał checkbox — a pokazywał domyślnie „wyłączone".
 * Domyślna wartość zostaje przy 0, więc pierwszy request po aktualizacji
 * usuwa sekcję z mapy; to jest zmiana zachowania ZGODNA z tym, co panel
 * deklarował od początku.
 */
add_filter('wp_sitemaps_add_provider', function ($provider, $name) {
    if ($name !== 'users') return $provider;
    return !empty(tl_get_sitemap_settings()['include_users']) ? $provider : false;
}, 10, 2);

/**
 * Wykluczone pojedyncze wpisy — dla KAŻDEGO typu treści, nie tylko stron i wpisów.
 *
 * Do 1.220.0 filtr odpuszczał wszystko poza `page` i `post`, więc wpis CPT
 * odznaczony w panelu i tak jechał do mapy.
 */
add_filter('wp_sitemaps_posts_query_args', function ($args, $post_type) {
    $ustawienia = tl_get_sitemap_settings();
    $wykluczone = tl_get_sitemap_excluded_ids($ustawienia);
    if (empty($wykluczone)) return $args;

    $istniejace = isset($args['post__not_in']) ? (array) $args['post__not_in'] : [];
    $args['post__not_in'] = array_values(array_unique(array_merge(array_map('absint', $istniejace), $wykluczone)));
    return $args;
}, 10, 2);

// =========================================================================
// „POZA INDEKSEM" NA POZIOMIE REJESTRACJI TYPU
// =========================================================================

/**
 * `exclude_from_search` dokładane typom oznaczonym jako „poza indeksem".
 *
 * Filtr `register_post_type_args` działa na typ KAŻDEGO pochodzenia — także
 * taki, którego rejestracji Evoke ONE nie kontroluje (ACF, Metabox, motyw).
 * Dlatego ta jedna linia załatwia „slajdy nie wyskakują w wyszukiwarce"
 * niezależnie od tego, czym slajdy zostały zrobione.
 */
add_filter('register_post_type_args', function ($args, $post_type) {
    if (evk_sitemap_typ_poza_indeksem((string) $post_type)) {
        $args['exclude_from_search'] = true;
    }
    return $args;
}, 10, 2);

// =========================================================================
// SEKCJE Z KOTWICAMI — WŁASNE WPISY W MAPIE
// =========================================================================

/**
 * Sekcje kotwic z ustawień: nazwa, adres bazowy i lista kotwic.
 *
 * PO CO. Strona jednoekranowa — menu lokalu, cennik, program wydarzenia —
 * jest w mapie JEDNYM adresem, choć niesie kilkanaście osobnych treści.
 * Sekcja kotwic pozwala zgłosić je z osobna: `/menu/#desery`, `/menu/#napoje`.
 * Każda sekcja to własny plik w indeksie mapy, nazwany po swojemu
 * (`wp-sitemap-menu-1.xml`), więc w Search Console widać ją jako oddzielną
 * pozycję, a nie wymieszaną z resztą.
 *
 * ADRES BAZOWY: wybrana strona ALBO wpisany ręcznie adres. Ręczny wygrywa,
 * bo bywa, że jednoekranowa treść nie jest stroną WordPressa (szablon Bricks
 * pod własnym adresem, podstrona archiwum).
 *
 * Kształt wpisu: ['name' => 'Menu', 'slug' => 'menu', 'page' => 12,
 *                 'url' => '', 'anchors' => ['desery', 'napoje']].
 */
function evk_sitemap_sekcje_kotwic(): array {
    $surowe = (array) (tl_get_sitemap_settings()['anchor_sections'] ?? []);
    $out    = [];
    $uzyte  = [];

    foreach ($surowe as $sekcja) {
        if (!is_array($sekcja)) continue;

        $nazwa = trim((string) ($sekcja['name'] ?? ''));
        $slug  = sanitize_title((string) ($sekcja['slug'] ?? $nazwa));
        if ($nazwa === '' || $slug === '') continue;

        /* Nazwy zajęte przez rdzeń i przez sekcję tłumaczeń są pomijane:
           `WP_Sitemaps_Registry::add_sitemap()` odmawia rejestracji drugiego
           providera o tej samej nazwie i sekcja po cichu by nie powstała. */
        if (in_array($slug, ['posts', 'taxonomies', 'users', 'translations'], true)) continue;
        if (isset($uzyte[$slug])) continue;
        $uzyte[$slug] = true;

        $kotwice = [];
        foreach ((array) ($sekcja['anchors'] ?? []) as $kotwica) {
            $kotwica = sanitize_title(ltrim((string) $kotwica, '#'));
            if ($kotwica !== '' && !in_array($kotwica, $kotwice, true)) $kotwice[] = $kotwica;
        }

        /* Sekcji bez kotwic NIE odsiewamy tutaj, choć kusi. Robi to warunek
           przy rejestracji („sekcja bez adresów nie powstaje"), a ten jest
           szerszy: łapie także sekcję z kotwicami, której strona bazowa
           została skasowana. Drugi strażnik na węższym przypadku niczego by
           nie dokładał — byłby wyłącznie miejscem, w którym warunek może się
           rozjechać z tamtym. */

        $out[] = [
            'name'    => $nazwa,
            'slug'    => $slug,
            'page'    => absint($sekcja['page'] ?? 0),
            'url'     => trim((string) ($sekcja['url'] ?? '')),
            'anchors' => $kotwice,
        ];
    }

    return $out;
}

/**
 * Adres bazowy sekcji — ręczny przed wybraną stroną.
 *
 * Adres względny (`/menu/`) dostaje adres witryny z przodu; bez tego w mapie
 * wylądowałby `loc` bez domeny, który jest błędem formatu, a nie literówką
 * do naprawienia przez wyszukiwarkę.
 */
function evk_sitemap_sekcja_baza(array $sekcja): string {
    $url = $sekcja['url'] ?? '';

    if ($url !== '') {
        if (strpos($url, '//') === false) $url = home_url('/' . ltrim($url, '/'));
        /* Ukośnik na końcu tylko dla adresów bez zapytania — `?p=12/` nie jest
           tym samym adresem co `?p=12`. */
        return strpos($url, '?') === false ? trailingslashit($url) : $url;
    }

    if (!empty($sekcja['page'])) {
        $link = get_permalink((int) $sekcja['page']);
        if ($link) return (string) $link;
    }

    return '';
}

/** Adresy jednej sekcji: adres bazowy z każdą kotwicą po kolei. */
function evk_sitemap_kotwice_urls(array $sekcja): array {
    $baza = evk_sitemap_sekcja_baza($sekcja);
    if ($baza === '') return [];

    /* `lastmod` bierzemy ze strony bazowej — wszystkie kotwice wskazują na tę
       samą treść, więc data jej ostatniej zmiany jest jedyną prawdziwą.
       Tylko wtedy, gdy adres NAPRAWDĘ pochodzi z tej strony: przy ręcznie
       wpisanym adresie wybrana wcześniej strona nie jest już tym dokumentem
       i jej data opisywałaby coś innego niż zgłaszany adres. Bez strony pola
       nie wysyłamy wcale — jest opcjonalne, a dzisiejsza data przy treści
       sprzed roku to fałszywa informacja, nie brak informacji. */
    $lastmod = '';
    if (($sekcja['url'] ?? '') === '' && !empty($sekcja['page'])) {
        $wpis = get_post((int) $sekcja['page']);
        if ($wpis && $wpis->post_modified_gmt) {
            $czas    = strtotime($wpis->post_modified_gmt);
            $lastmod = $czas ? gmdate(DATE_W3C, $czas) : '';
        }
    }

    $urls = [];
    foreach ($sekcja['anchors'] as $kotwica) {
        $wpis = ['loc' => $baza . '#' . $kotwica];
        if ($lastmod !== '') $wpis['lastmod'] = $lastmod;
        $urls[] = $wpis;
    }

    return $urls;
}

// =========================================================================
// REJESTRACJA WŁASNYCH SEKCJI MAPY
// =========================================================================

add_action('wp_sitemaps_init', function () {
    if (!class_exists('WP_Sitemaps_Provider') || !function_exists('wp_register_sitemap_provider')) return;

    $ustawienia = tl_get_sitemap_settings();

    /* Sekcje kotwic — po jednym providerze na sekcję, każdy pod własną nazwą.
       Rejestrowana tylko sekcja, która ma adresy: provider bez adresów dokłada
       do indeksu pozycję zgłaszaną Google'owi po to, by odpowiedzieć zerem
       wpisów. */
    if (!class_exists('EVK_Sitemap_Kotwice_Provider')) {
        class EVK_Sitemap_Kotwice_Provider extends WP_Sitemaps_Provider {
            const NA_STRONE = 2000;

            /** @var array<int,array<string,string>> */
            private $urls;

            public function __construct(string $slug, array $urls) {
                $this->name        = $slug;
                $this->object_type = $slug;
                $this->urls        = $urls;
            }

            public function get_url_list($page_num, $object_subtype = '') {
                return array_slice($this->urls, max(0, $page_num - 1) * self::NA_STRONE, self::NA_STRONE);
            }

            public function get_max_num_pages($object_subtype = '') {
                return (int) ceil(count($this->urls) / self::NA_STRONE);
            }
        }
    }

    foreach (evk_sitemap_sekcje_kotwic() as $sekcja) {
        $urls = evk_sitemap_kotwice_urls($sekcja);
        if (empty($urls)) continue;
        wp_register_sitemap_provider($sekcja['slug'], new EVK_Sitemap_Kotwice_Provider($sekcja['slug'], $urls));
    }

    /* Sekcja hreflang — tylko przy żywym silniku języków. Plik ładuje się
       teraz zawsze, a `tl_translate_slug()` przychodzi z modułu tłumaczeń. */
    if (empty($ustawienia['enabled']) || !function_exists('tl_get_languages') || !function_exists('tl_translate_slug')) return;

    if (!class_exists('EVK_Sitemap_Hreflang_Provider')) {
        /**
         * Provider istnieje po to, żeby sekcja ZNALAZŁA SIĘ W INDEKSIE
         * `wp-sitemap.xml` i dostała od rdzenia własny adres
         * (`wp-sitemap-hreflang-1.xml`) razem z regułą przepisywania.
         *
         * Zawartości tego adresu rdzeń jednak nie wyrenderuje poprawnie:
         * `WP_Sitemaps_Renderer::get_sitemap_xml()` przyjmuje wyłącznie `loc`,
         * `lastmod`, `changefreq` i `priority`, a powiązania językowe to
         * `xhtml:link` z atrybutami, w cudzej przestrzeni nazw. Dlatego
         * żądanie przechwytuje `evk_sitemap_hreflang_renderuj()` niżej, na
         * `template_redirect` PRZED rdzeniem.
         *
         * `get_url_list()` zostaje uczciwą listą adresów: jest drogą zapasową
         * na wypadek, gdyby przechwycenie nie doszło do skutku. Lepiej wydać
         * poprawną mapę bez powiązań niż pustą.
         */
        class EVK_Sitemap_Hreflang_Provider extends WP_Sitemaps_Provider {
            const NA_STRONE = 2000;

            public function __construct() {
                $this->name        = 'hreflang';
                $this->object_type = 'hreflang';
            }

            public function get_url_list($page_num, $object_subtype = '') {
                $urls = [];
                foreach (evk_sitemap_hreflang_adresy() as $wpis) {
                    $urls[] = ['loc' => $wpis['loc'], 'lastmod' => $wpis['lastmod']];
                }
                return array_slice($urls, max(0, $page_num - 1) * self::NA_STRONE, self::NA_STRONE);
            }

            public function get_max_num_pages($object_subtype = '') {
                return (int) ceil(count(evk_sitemap_hreflang_adresy()) / self::NA_STRONE);
            }
        }
    }

    wp_register_sitemap_provider('hreflang', new EVK_Sitemap_Hreflang_Provider());
});

// =========================================================================
// SEKCJA HREFLANG — POWIĄZANIA WERSJI JĘZYKOWYCH
// =========================================================================

/**
 * Język, na który wskazuje `x-default` — kod z ustawień albo polski.
 *
 * Z tej samej funkcji korzystają OBA źródła deklaracji: ta sekcja mapy
 * i tagi `<link rel="alternate">` w `<head>` (`12-seo-url-filters.php`).
 * Rozbieżność między nimi to sygnał sprzeczny, który wyszukiwarka rozstrzyga
 * po swojemu — a nie tak, jak chciał piszący.
 */
function evk_sitemap_jezyk_domyslny(): string {
    $kod = sanitize_key((string) (tl_get_sitemap_settings()['hreflang_default'] ?? 'pl'));
    if ($kod === '' || $kod === 'pl') return 'pl';
    if (!function_exists('tl_get_languages')) return 'pl';

    return isset(tl_get_languages()[$kod]) ? $kod : 'pl';
}

/**
 * `lastmod` strony głównej — data treści, nie moment wygenerowania pliku.
 *
 * ZGŁOSZONE Z ŻYWEJ STRONY: wpis strony głównej niósł `gmdate(DATE_W3C)`, czyli
 * godzinę bieżącego żądania. Dwa wejścia pod ten sam adres w odstępie czterech
 * minut dawały dwie różne daty ostatniej zmiany — a to pole ma znaczyć „kiedy
 * treść się zmieniła". Zmyślona świeżość podana przy każdym pobraniu jest
 * gorsza niż brak pola: wyszukiwarka przestaje wierzyć wszystkim datom w tym
 * pliku, nie tylko tej jednej.
 *
 * Kolejność: statyczna strona startowa (jeśli jest), inaczej najnowsza zmiana
 * wśród treści, które i tak trafiają do sekcji. Gdy nie ma ani jednej — puste,
 * a renderer pomija wtedy `lastmod` w całości.
 */
function evk_sitemap_lastmod_glownej(array $posty): string {
    $front = (int) get_option('page_on_front');
    if ($front) {
        $wpis = get_post($front);
        if ($wpis && !empty($wpis->post_modified_gmt)) {
            $czas = strtotime($wpis->post_modified_gmt);
            if ($czas) return gmdate(DATE_W3C, $czas);
        }
    }

    $najnowszy = 0;
    foreach ($posty as $post) {
        $czas = !empty($post->post_modified_gmt) ? (int) strtotime($post->post_modified_gmt) : 0;
        if ($czas > $najnowszy) $najnowszy = $czas;
    }

    return $najnowszy ? gmdate(DATE_W3C, $najnowszy) : '';
}

/**
 * Adresy sekcji hreflang — po jednym wpisie na wersję językową strony.
 *
 * Każdy wpis niesie KOMPLET powiązań, nie tylko wskazanie na siebie: taka jest
 * reguła protokołu. Wersje językowe muszą wskazywać na siebie nawzajem,
 * a wyszukiwarka odrzuca deklarację, której druga strona nie potwierdza —
 * dlatego ten sam zestaw `xhtml:link` powtarza się w bloku każdej wersji.
 *
 * Zwraca: [['loc' => …, 'lastmod' => …, 'alternaty' => [tag => url],
 *           'x_default' => url], …].
 */
function evk_sitemap_hreflang_adresy(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $ustawienia = tl_get_sitemap_settings();
    if (empty($ustawienia['enabled']) || !function_exists('tl_get_languages') || !function_exists('tl_translate_slug')) {
        return $cache = [];
    }

    $home     = untrailingslashit(get_option('home'));
    $langi    = tl_get_languages();
    $domyslny = evk_sitemap_jezyk_domyslny();
    $wpisy    = [];

    /* Jeden „dokument" = strona plus jej wersje językowe. Tutaj powstaje mapa
       [kod języka => adres]; dopiero z niej robią się bloki `<url>`. */
    $zbuduj = static function (array $adresy, string $lastmod) use ($langi, $domyslny, $ustawienia): array {
        $tagi = [];
        foreach ($adresy as $kod => $url) {
            /* Etykieta języka: dla polskiego „pl", dla reszty wartość z
               ustawień języków („en-US"). W `<head>` polski dostaje dodatkowo
               `pl-PL`; tutaj tego nie powtarzamy, bo drugi tag na ten sam
               adres nic nie wnosi, a podwaja rozmiar pliku. */
            $tagi[$kod === 'pl' ? 'pl' : ($langi[$kod]['html'] ?? $kod)] = $url;
        }

        $x_default = $adresy[$domyslny] ?? ($adresy['pl'] ?? reset($adresy));

        $bloki = [];
        foreach ($adresy as $kod => $url) {
            /* Polski blok pomijamy, gdy ekran mapy tak mówi — ale polski
               adres ZOSTAJE w powiązaniach każdego innego bloku. Wersja
               nieobecna w deklaracjach to zerwane powiązanie, a nie
               oszczędność. */
            if ($kod === 'pl' && empty($ustawienia['include_polish'])) continue;

            $bloki[] = [
                'loc'       => $url,
                'lastmod'   => $lastmod,
                'alternaty' => $tagi,
                'x_default' => $x_default,
            ];
        }
        return $bloki;
    };

    $typy = [];
    if (!empty($ustawienia['include_pages'])) $typy[] = 'page';
    if (!empty($ustawienia['include_posts'])) $typy[] = 'post';

    $posty = $typy ? get_posts([
        'post_type'      => $typy,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ]) : [];

    /* Strona główna PO pobraniu wpisów, bo jej `lastmod` liczy się z treści. */
    if (!empty($ustawienia['include_home'])) {
        $adresy = ['pl' => $home . '/'];
        foreach (array_keys($langi) as $kod) $adresy[$kod] = $home . '/' . $kod . '/';
        $wpisy = array_merge($wpisy, $zbuduj($adresy, evk_sitemap_lastmod_glownej($posty)));
    }

    if (empty($typy)) return $cache = $wpisy;

    foreach ($posty as $post) {
        if (tl_is_post_excluded_from_sitemap((int) $post->ID, $ustawienia)) continue;

        $pl_path = tl_get_post_pl_path($post);
        if (!$pl_path) continue;

        $czas    = $post->post_modified_gmt ? strtotime($post->post_modified_gmt) : false;
        $lastmod = gmdate(DATE_W3C, $czas ?: time());

        $adresy = ['pl' => $home . '/' . trim($pl_path, '/') . '/'];
        foreach (array_keys($langi) as $kod) {
            if (!empty($ustawienia['only_translated_slugs']) && !tl_has_translated_path($pl_path, $kod)) continue;

            $segmenty = array_values(array_filter(explode('/', $pl_path)));
            $tr       = implode('/', array_map(static fn($s) => tl_translate_slug($s, $kod), $segmenty));
            $adresy[$kod] = $home . '/' . $kod . '/' . $tr . '/';
        }

        $wpisy = array_merge($wpisy, $zbuduj($adresy, $lastmod));
    }

    return $cache = $wpisy;
}

/**
 * Renderowanie `wp-sitemap-hreflang-*.xml` z pełnymi powiązaniami.
 *
 * PRZECHWYTUJE ŻĄDANIE PRZED RDZENIEM (priorytet 5 na `template_redirect`;
 * `WP_Sitemaps::render_sitemaps()` siedzi na domyślnym 10). Adres, reguła
 * przepisywania i wpis w indeksie pochodzą od rdzenia — własnej reguły nie ma
 * tu wcale i to jest cała odporność tego rozwiązania: martwy generator
 * `/sitemap.xml` sprzed 1.222.0 nie odpowiadał właśnie dlatego, że jego reguła
 * nigdy nie trafiła do bazy.
 */
function evk_sitemap_hreflang_renderuj(): void {
    if ((string) get_query_var('sitemap') !== 'hreflang') return;

    $wpisy = evk_sitemap_hreflang_adresy();
    if (empty($wpisy)) return; // niech rdzeń odpowie tak, jak umie

    $strona = max(1, (int) get_query_var('paged'));
    $wpisy  = array_slice($wpisy, ($strona - 1) * 2000, 2000);

    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Robots-Tag: noindex, follow', true);

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";

    /* ARKUSZ STYLÓW RDZENIA — ta jedna linia decyduje o tym, co widać po
       wejściu w adres z przeglądarki. Każda sekcja `wp-sitemap-*.xml` niesie
       `<?xml-stylesheet ?>` wskazujący `wp-sitemap.xsl`, który zamienia XML
       w tabelę. Bez niego przeglądarka pokazuje dokument po swojemu: Chrome
       drzewko z ostrzeżeniem „no style information", Safari — sam tekst
       z wnętrza znaczników, czyli adresy i daty zlepione w ciąg. Plik był
       przez cały czas poprawny, wyglądał na zepsuty.

       Adres bierzemy z rdzenia, nie wpisujemy: bez ładnych odnośników
       `get_sitemap_stylesheet_url()` oddaje `?sitemap-stylesheet=sitemap`,
       a wpisany na sztywno `/wp-sitemap.xsl` byłby wtedy pustym strzałem. */
    if (function_exists('wp_sitemaps_get_server')) {
        $xsl = wp_sitemaps_get_server()->renderer->get_sitemap_stylesheet_url();
        if ($xsl !== '') echo '<?xml-stylesheet type="text/xsl" href="' . esc_url($xsl) . '" ?>' . "\n";
    }

    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
    echo '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

    foreach ($wpisy as $wpis) {
        echo "\t<url>\n";
        echo "\t\t<loc>" . esc_url($wpis['loc']) . "</loc>\n";
        if (!empty($wpis['lastmod'])) echo "\t\t<lastmod>" . esc_html($wpis['lastmod']) . "</lastmod>\n";
        foreach ($wpis['alternaty'] as $tag => $url) {
            echo "\t\t" . '<xhtml:link rel="alternate" hreflang="' . esc_attr($tag) . '" href="' . esc_url($url) . '" />' . "\n";
        }
        echo "\t\t" . '<xhtml:link rel="alternate" hreflang="x-default" href="' . esc_url($wpis['x_default']) . '" />' . "\n";
        echo "\t</url>\n";
    }

    echo '</urlset>';
    exit;
}
add_action('template_redirect', 'evk_sitemap_hreflang_renderuj', 5);

// =========================================================================
// WYKLUCZENIA POJEDYNCZYCH WPISÓW
// =========================================================================

/**
 * Klucze metadanych, w których szukamy `noindex` — ZNANE, nie zgadywane.
 *
 * DO 1.224.3 SKAN CHODZIŁ PO WSZYSTKICH METADANYCH i uznawał za `noindex`
 * każdy klucz zawierający „noindex" albo „robots" o niepustej wartości. Pole
 * z Evoke FIELDS czy ACF nazwane `noindex_uwagi` („sprawdzić z klientem")
 * albo `robots_txt_snippet` wyrzucało stronę z mapy — po cichu, bez śladu na
 * ekranie edycji wpisu. Kierunek błędu był najgorszy z możliwych: heurystyka
 * myliła się PRZEZ USUNIĘCIE treści z mapy, a nie przez jej zostawienie.
 *
 * Teraz pytamy wyłącznie o klucze, o których wiadomo, co znaczą. Wtyczka SEO
 * spoza listy nie zostanie rozpoznana — ale to jest pomyłka odwracalna jednym
 * checkboksem na liście wykluczeń, w przeciwieństwie do strony, która zniknęła
 * z mapy i nikt nie wie dlaczego. Listę rozszerza filtr.
 *
 * Tryby: `flaga` — sama niepusta wartość znaczy `noindex` (Yoast, Genesis,
 * SEOPress trzymają tam „1" albo „yes"); `wartosc` — trzeba zajrzeć do środka,
 * bo pole niesie całą konfigurację (Bricks: JSON z `metaRobots`, Rank Math:
 * tablica dyrektyw).
 */
function evk_sitemap_klucze_noindex(): array {
    $klucze = [
        '_bricks_page_settings'            => 'wartosc', // Bricks — JSON z metaRobots
        '_evoke_seo_robots'                => 'wartosc', // zakładka SEO Evoke ONE
        '_yoast_wpseo_meta-robots-noindex' => 'flaga',
        '_yoast_wpseo_meta-robots-adv'     => 'wartosc',
        'rank_math_robots'                 => 'wartosc',
        '_genesis_noindex'                 => 'flaga',
        '_seopress_robots_index'           => 'flaga',   // „yes" = nie indeksuj
        '_aioseo_robots_noindex'           => 'flaga',   // AIOSEO ≤ 3; nowsze trzymają to we własnej tabeli
    ];

    $out = [];
    foreach ((array) apply_filters('evk_sitemap_klucze_noindex', $klucze) as $klucz => $tryb) {
        $klucz = (string) $klucz;
        if ($klucz !== '') $out[$klucz] = $tryb === 'flaga' ? 'flaga' : 'wartosc';
    }
    return $out;
}

/** Czy wartość pola-przełącznika znaczy „włączone". */
function evk_sitemap_flaga_wlaczona($wartosc): bool {
    if (is_bool($wartosc)) return $wartosc;
    if (is_array($wartosc)) return !empty(array_filter($wartosc));

    return !in_array(strtolower(trim((string) $wartosc)), ['', '0', 'false', 'no', 'off', 'none'], true);
}

/**
 * Czy wpis ma ustawione `noindex` w którymś ze znanych pól.
 *
 * Zwraca też, KTÓRE pole zadecydowało — ekran diagnostyki pokazuje to wprost,
 * żeby zniknięcie strony z mapy dawało się prześledzić bez czytania kodu.
 *
 * @return array<string,string> [klucz => wartość w skrócie]; pusta = brak noindex
 */
function evk_sitemap_noindex_wpisu(int $post_id): array {
    $znalezione = [];

    foreach (evk_sitemap_klucze_noindex() as $klucz => $tryb) {
        foreach ((array) get_post_meta($post_id, $klucz, false) as $wartosc) {
            $trafienie = $tryb === 'flaga'
                ? evk_sitemap_flaga_wlaczona($wartosc)
                : tl_meta_value_means_noindex($wartosc, $klucz);

            if ($trafienie) {
                $znalezione[$klucz] = is_scalar($wartosc) ? (string) $wartosc : wp_json_encode($wartosc);
                break;
            }
        }
    }

    return $znalezione;
}

function tl_post_has_noindex_meta(int $post_id): bool {
    return !empty(evk_sitemap_noindex_wpisu($post_id));
}

function tl_get_sitemap_excluded_ids($settings = null): array {
    static $cache = [];
    $settings = $settings ?: tl_get_sitemap_settings();
    $cache_key = md5(wp_json_encode($settings));
    if (isset($cache[$cache_key])) return $cache[$cache_key];

    $ids = array_map('absint', (array) ($settings['excluded_ids'] ?? []));

    /* Skan metadanych chodzi po stronach i wpisach, nie po wszystkich typach.
       Przy CPT liczonych w tysiącach (slajdy, pozycje menu) `get_post_meta`
       na każdy wpis kosztowałby więcej niż cała reszta mapy razem wzięta,
       a te typy wyklucza się dziś jednym checkboksem, nie po metadanych. */
    if (!empty($settings['auto_exclude_noindex'])) {
        $posts = get_posts([
            'post_type'      => ['page', 'post'],
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ]);

        foreach ($posts as $post_id) {
            if (tl_post_has_noindex_meta((int) $post_id)) {
                $ids[] = (int) $post_id;
            }
        }
    }

    return $cache[$cache_key] = array_values(array_unique(array_filter($ids)));
}

function tl_is_post_excluded_from_sitemap(int $post_id, $settings = null): bool {
    return in_array($post_id, tl_get_sitemap_excluded_ids($settings), true);
}

// =========================================================================
// ŚCIEŻKI POLSKIE — WSPÓLNE DLA SEKCJI HREFLANG
// =========================================================================

function tl_get_post_pl_path(WP_Post $post): string {
    if ($post->post_type === 'page') {
        $ancestors  = array_reverse(get_post_ancestors($post->ID));
        $path_parts = [];
        foreach ($ancestors as $ancestor_id) {
            $ancestor = get_post($ancestor_id);
            if ($ancestor && $ancestor->post_name) $path_parts[] = $ancestor->post_name;
        }
        $path_parts[] = $post->post_name;
        return implode('/', array_filter($path_parts));
    }

    return $post->post_name;
}

function tl_has_translated_path(string $pl_path, string $lang): bool {
    $segments = array_values(array_filter(explode('/', $pl_path)));
    if (empty($segments)) return true;

    foreach ($segments as $segment) {
        if (tl_translate_slug($segment, $lang) === $segment) {
            return false;
        }
    }

    return true;
}


// =========================================================================
// ROBOTS.TXT
// =========================================================================

/**
 * Wskazanie mapy w `robots.txt`.
 *
 * WordPress dokłada tę samą linię sam, dopóki mapa jest włączona — warunek
 * `strpos` pilnuje, żeby nie było jej dwa razy. Filtr zostaje na wypadek
 * konfiguracji, w której rdzeń swojej linii nie wypisze.
 */
add_filter('robots_txt', function ($output) {
    if (strpos($output, 'sitemap.xml') !== false) return $output;

    return $output . "\nSitemap: " . untrailingslashit(get_option('home')) . "/wp-sitemap.xml\n";
});

/**
 * Jednorazowe przebudowanie reguł po skasowaniu `/sitemap.xml`.
 *
 * Reguła `^sitemap\.xml$` została wypchnięta na żywe strony i siedzi
 * w `rewrite_rules` w bazie. Bez tego sprzątnięcia żądanie `/sitemap.xml`
 * trafiałoby w `index.php?tl_sitemap=1`, którego już nikt nie obsługuje —
 * czyli w 404 zamiast w przekierowanie rdzenia na `wp-sitemap.xml`.
 */
add_action('init', function () {
    if (get_option('evk_sitemap_rules_cleaned') === '1') return;

    $rules = get_option('rewrite_rules');
    if (is_array($rules) && isset($rules['^sitemap\.xml$'])) {
        flush_rewrite_rules(false);
    }

    update_option('evk_sitemap_rules_cleaned', '1');
}, 99);
