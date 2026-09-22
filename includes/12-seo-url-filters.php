<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia - hreflang, canonical and URL filters
 */

// ====================================================================
// 1c. SEO - HREFLANG TAGS
// ====================================================================

add_filter('language_attributes', function ($output) {
    if (tl_is_bricks_editor()) return $output;
    $lang     = get_current_lang();
    $langs    = tl_get_languages();

    if ($lang === 'pl') {
        return 'lang="pl-PL"';
    }

    $html_tag = $langs[$lang]['html'] ?? $lang;
    return 'lang="' . esc_attr($html_tag) . '"';
}, 20);

add_action('wp_head', function () {
    if (tl_is_bricks_editor()) return;

    // Raw home URL bez filtrów — unikamy podwójnego prefiksu języka
    // (filtr home_url dodaje prefiks, co psuje hreflang i canonical)
    $home_raw = untrailingslashit(get_option('home'));

    $current_lang = get_current_lang();
    $request_uri  = $_SERVER['REQUEST_URI'] ?? '/';
    $path         = parse_url($request_uri, PHP_URL_PATH) ?? '/';
    $path         = tl_remove_lang_prefix_from_path($path);

    // Przełóż z powrotem na PL jeśli jesteśmy na wersji językowej
    if ($current_lang !== 'pl') {
        $path = tl_get_original_path_from_translated($path, $current_lang);
    }

    $pl_path = rtrim($path, '/') . '/';
    if ($pl_path === '//') $pl_path = '/';

    // Canonical — wskażuje na przetłumaczony URL aktualnej wersji językowej
    if ($current_lang === 'pl') {
        $canonical = $home_raw . $pl_path;
    } else {
        $translated_path = tl_translate_url_path($path, 'pl', $current_lang);
        $canonical = $home_raw . '/' . $current_lang . rtrim($translated_path, '/') . '/';
    }
    echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";

    /* hreflang PL — JEDEN tag, `pl`.
     *
     * Do 1.223.2 leciały dwa: `pl` i `pl-PL`, oba na ten sam adres. Sprzeczności
     * w tym nie ma, ale `pl-PL` niczego nie dokładał: sam kod języka jest
     * pełnoprawną wartością (Google: „you can specify a language code by
     * itself"), a dopisany region ZAWĘŻA — `pl-PL` znaczy „polski w Polsce",
     * czyli mówi o mniejszej grupie niż `pl`. Region ma sens wyłącznie wtedy,
     * gdy istnieją OSOBNE strony dla tego samego języka w różnych krajach
     * (`en-GB` obok `en-US`, inne ceny i dostawa). Przy jednej wersji polskiej
     * drugi tag to tylko szum, w dodatku rozjeżdżający się z sekcją `hreflang`
     * w mapie strony, która wypisuje samo `pl`.
     *
     * Atrybut `lang` dokumentu (filtr `language_attributes` na górze pliku)
     * zostaje przy `pl-PL` — to inna deklaracja, dla przeglądarki i czytników
     * ekranu, i tam pełny tag BCP 47 jest w porządku. */
    $pl_url = $home_raw . $pl_path;
    echo '<link rel="alternate" hreflang="pl" href="' . esc_url($pl_url) . '" />' . "\n";

    // hreflang pozostałe języki z przetłumaczonymi slugami
    foreach (tl_get_languages() as $code => $lang) {
        $translated_path = tl_translate_url_path($path, 'pl', $code);
        $lang_url = $home_raw . '/' . $code . rtrim($translated_path, '/') . '/';
        echo '<link rel="alternate" hreflang="' . esc_attr($lang['html'] ?? $code) . '" href="' . esc_url($lang_url) . '" />' . "\n";
    }

    /* x-default — język wskazany na ekranie SEO → Mapa strony.
       TEN SAM wybór czyta sekcja `hreflang` w mapie (`80-sitemap.php`).
       Dwa źródła deklaracji wskazujące różne wersje domyślne to sygnał
       sprzeczny: wyszukiwarka rozstrzyga go po swojemu, nie po naszemu. */
    $domyslny = function_exists('evk_sitemap_jezyk_domyslny') ? evk_sitemap_jezyk_domyslny() : 'pl';
    $x_default = $pl_url;
    if ($domyslny !== 'pl') {
        $tr_path   = tl_translate_url_path($path, 'pl', $domyslny);
        $x_default = $home_raw . '/' . $domyslny . rtrim($tr_path, '/') . '/';
    }
    echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($x_default) . '" />' . "\n";
}, 1);

// ====================================================================
// 2. URL FILTERS - DODAWANIE PREFIKSU DO WSZYSTKICH LINKÓW
// ====================================================================

/**
 * Filtr dla home_url
 */
add_filter('home_url', function ($url, $path) {
    if (tl_is_bricks_editor() || is_admin()) return $url;

    $lang = $GLOBALS['lang_code'] ?? '';
    if (empty($lang) || $lang === 'pl') return $url;

    // Nie modyfikuj URL-i do wp-admin, wp-content itp.
    if (strpos($url, 'wp-admin') !== false ||
        strpos($url, 'wp-content') !== false ||
        strpos($url, 'wp-includes') !== false ||
        strpos($url, 'wp-json') !== false) {
        return $url;
    }

    return tl_add_lang_prefix_to_url($url, $lang);
}, 10, 2);

/**
 * Filtry dla linków do postów i stron
 */
$tl_link_filters = ['post_type_link', 'term_link', 'post_type_archive_link', 'get_pagenum_link'];

foreach ($tl_link_filters as $_tl_filter) {
    add_filter($_tl_filter, function ($url) {
        if (tl_is_bricks_editor() || is_admin()) return $url;

        $lang = $GLOBALS['lang_code'] ?? '';
        if (empty($lang) || $lang === 'pl') return $url;

        return tl_add_lang_prefix_to_url($url, $lang);
    }, 10);
}

/**
 * Filtr dla nav menu items - z tłumaczeniem slugów
 */
add_filter('nav_menu_link_attributes', function ($atts, $item, $args, $depth) {
    if (tl_is_bricks_editor() || is_admin()) return $atts;

    $lang = $GLOBALS['lang_code'] ?? '';
    if (empty($lang) || $lang === 'pl') return $atts;

    if (!empty($atts['href']) && strpos($atts['href'], home_url()) === 0) {
        // Dla stron i postów - użyj tłumaczenia slugów
        if ($item->type === 'post_type' && $item->object_id) {
            $post = get_post($item->object_id);
            if ($post) {
                // Pobierz pełną ścieżkę (z ancestorami dla stron)
                if ($post->post_type === 'page') {
                    $ancestors = get_post_ancestors($post->ID);
                    $path_parts = [];

                    foreach (array_reverse($ancestors) as $ancestor_id) {
                        $ancestor = get_post($ancestor_id);
                        if ($ancestor) {
                            $path_parts[] = tl_translate_slug($ancestor->post_name, $lang);
                        }
                    }
                    $path_parts[] = tl_translate_slug($post->post_name, $lang);

                    $translated_path = implode('/', $path_parts);
                    $atts['href'] = home_url('/' . $lang . '/' . $translated_path . '/');
                } else {
                    $translated_slug = tl_translate_slug($post->post_name, $lang);
                    if ($translated_slug !== $post->post_name) {
                        $atts['href'] = str_replace('/' . $post->post_name, '/' . $translated_slug, $atts['href']);
                    }
                    $atts['href'] = tl_add_lang_prefix_to_url($atts['href'], $lang);
                }
            }
        } else {
            $atts['href'] = tl_add_lang_prefix_to_url($atts['href'], $lang);
        }
    }

    return $atts;
}, 10, 4);

/**
 * Redirect jeśli ktoś wejdzie na stronę z prefiksem językowym który nie istnieje
 */
add_action('template_redirect', function () {
    $lang_prefix = get_query_var('lang_prefix');

    if ($lang_prefix && !in_array($lang_prefix, tl_get_active_lang_codes(), true)) {
        // Nieznany prefix językowy - przekieruj do wersji bez prefiksu
        $clean_url = tl_remove_lang_prefix_from_url(home_url($_SERVER['REQUEST_URI']));
        wp_redirect($clean_url, 301);
        exit;
    }
});

