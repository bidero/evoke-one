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
 * Skasowanie ich niczego nie zabiera, bo `hreflang` i tak jedzie w `<head>`
 * każdej podstrony (`12-seo-url-filters.php`) — a to jest źródło równorzędne
 * z mapą. Do `wp-sitemap.xml` `hreflang` nie wejdzie w żadnym wariancie:
 * `WP_Sitemaps_Renderer::get_sitemap_xml()` przyjmuje dla adresu wyłącznie
 * `loc`, `lastmod`, `changefreq` i `priority`, a każdy inny klucz kwituje
 * `_doing_it_wrong()`. Przestrzeni nazw `xmlns:xhtml` nie da się dołożyć
 * filtrem — trzeba by podmienić cały renderer.
 *
 * PLIK ŁADUJE SIĘ ZAWSZE, nie tylko przy włączonych tłumaczeniach. Steruje
 * mapą, którą WordPress wystawia na każdej stronie, więc związanie go
 * z modułem tłumaczeń znaczyłoby, że na stronie bez tłumaczeń nie ma czym
 * sterować. Sekcja tłumaczeń (dawny provider `translations`) siedzi na końcu
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

    /* Sekcja tłumaczeń — tylko przy żywym silniku języków. Plik ładuje się
       teraz zawsze, a `tl_translate_slug()` przychodzi z modułu tłumaczeń. */
    if (empty($ustawienia['enabled']) || !function_exists('tl_get_languages') || !function_exists('tl_translate_slug')) return;

    if (!class_exists('TL_Translated_Sitemap_Provider')) {
        class TL_Translated_Sitemap_Provider extends WP_Sitemaps_Provider {
            const NA_STRONE = 2000;

            public function __construct() {
                $this->name        = 'translations';
                $this->object_type = 'translations';
            }

            public function get_url_list($page_num, $object_subtype = '') {
                $urls = tl_get_translated_sitemap_urls();
                return array_slice($urls, max(0, $page_num - 1) * self::NA_STRONE, self::NA_STRONE);
            }

            public function get_max_num_pages($object_subtype = '') {
                return (int) ceil(count(tl_get_translated_sitemap_urls()) / self::NA_STRONE);
            }
        }
    }

    wp_register_sitemap_provider('translations', new TL_Translated_Sitemap_Provider());
});

// =========================================================================
// WYKLUCZENIA POJEDYNCZYCH WPISÓW
// =========================================================================

function tl_post_has_noindex_meta(int $post_id): bool {
    foreach (get_post_meta($post_id) as $meta_key => $values) {
        foreach ((array) $values as $value) {
            if (tl_meta_value_means_noindex($value, (string) $meta_key)) {
                return true;
            }
        }
    }

    return false;
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
// SEKCJA TŁUMACZEŃ — ADRESY Z PRZETŁUMACZONYMI SLUGAMI
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

function tl_get_translated_sitemap_urls(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $settings = tl_get_sitemap_settings();
    if (empty($settings['enabled']) || !function_exists('tl_get_languages')) return $cache = [];

    $home_raw   = untrailingslashit(get_option('home'));
    $langs      = tl_get_languages();
    $lang_codes = array_keys($langs);
    $urls       = [];

    if (!empty($settings['include_home'])) {
        if (!empty($settings['include_polish'])) {
            $urls[] = ['loc' => $home_raw . '/', 'lastmod' => gmdate('Y-m-d')];
        }
        foreach ($lang_codes as $code) {
            $urls[] = ['loc' => $home_raw . '/' . $code . '/', 'lastmod' => gmdate('Y-m-d')];
        }
    }

    $post_types = [];
    if (!empty($settings['include_pages'])) $post_types[] = 'page';
    if (!empty($settings['include_posts'])) $post_types[] = 'post';
    if (empty($post_types)) return $cache = $urls;

    $posts = get_posts([
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ]);

    foreach ($posts as $post) {
        if (tl_is_post_excluded_from_sitemap((int) $post->ID, $settings)) continue;

        $pl_path = tl_get_post_pl_path($post);
        if (!$pl_path) continue;

        $lastmod_time = $post->post_modified_gmt ? strtotime($post->post_modified_gmt) : false;
        $lastmod = $lastmod_time ? gmdate('Y-m-d', $lastmod_time) : gmdate('Y-m-d');

        if (!empty($settings['include_polish'])) {
            $urls[] = ['loc' => $home_raw . '/' . trim($pl_path, '/') . '/', 'lastmod' => $lastmod];
        }

        foreach ($lang_codes as $code) {
            if (!empty($settings['only_translated_slugs']) && !tl_has_translated_path($pl_path, $code)) {
                continue;
            }

            $segments    = array_values(array_filter(explode('/', $pl_path)));
            $tr_segments = array_map(fn($s) => tl_translate_slug($s, $code), $segments);
            $tr_path     = implode('/', $tr_segments);
            $urls[] = ['loc' => $home_raw . '/' . $code . '/' . $tr_path . '/', 'lastmod' => $lastmod];
        }
    }

    return $cache = $urls;
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
