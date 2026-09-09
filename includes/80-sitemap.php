<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia - sitemap integration
 */

// ====================================================================
// 11. SITEMAP XML
// ====================================================================

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

function tl_get_translated_sitemap_urls(): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $settings = tl_get_sitemap_settings();
    if (empty($settings['enabled'])) return $cache = [];

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

add_filter('wp_sitemaps_posts_query_args', function ($args, $post_type) {
    if (!in_array($post_type, ['page', 'post'], true)) return $args;

    $settings = tl_get_sitemap_settings();
    $excluded = tl_get_sitemap_excluded_ids($settings);
    if (empty($excluded)) return $args;

    $existing = isset($args['post__not_in']) ? (array) $args['post__not_in'] : [];
    $args['post__not_in'] = array_values(array_unique(array_merge(array_map('absint', $existing), $excluded)));
    return $args;
}, 10, 2);

add_action('wp_sitemaps_init', function () {
    $settings = tl_get_sitemap_settings();
    if (empty($settings['enabled']) || !class_exists('WP_Sitemaps_Provider') || !function_exists('wp_register_sitemap_provider')) return;

    if (!class_exists('TL_Translated_Sitemap_Provider')) {
        class TL_Translated_Sitemap_Provider extends WP_Sitemaps_Provider {
            public function __construct() {
                $this->name        = 'translations';
                $this->object_type = 'translations';
            }

            public function get_url_list($page_num, $object_subtype = '') {
                $per_page = 2000;
                $urls = tl_get_translated_sitemap_urls();
                return array_slice($urls, max(0, $page_num - 1) * $per_page, $per_page);
            }

            public function get_max_num_pages($object_subtype = '') {
                $per_page = 2000;
                $count = count(tl_get_translated_sitemap_urls());
                return (int) ceil($count / $per_page);
            }
        }
    }

    wp_register_sitemap_provider('translations', new TL_Translated_Sitemap_Provider());
});

/**
 * Rejestracja rewrite rule dla /sitemap.xml
 */
add_action('init', function () {
    add_rewrite_rule('^sitemap\.xml$', 'index.php?tl_sitemap=1', 'top');
}, 10);

add_filter('query_vars', function ($vars) {
    $vars[] = 'tl_sitemap';
    return $vars;
});

/**
 * Generowanie sitemap przy żądaniu /sitemap.xml
 */
add_action('template_redirect', function () {
    if (!get_query_var('tl_sitemap')) return;

    $home_raw  = untrailingslashit(get_option('home'));
    $langs     = tl_get_languages();
    $lang_codes = array_keys($langs);
    $sitemap_settings = tl_get_sitemap_settings();

    // Pobierz wszystkie opublikowane strony i posty
    $posts = get_posts([
        'post_type'      => ['page', 'post'],
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ]);

    // Zbuduj listę wszystkich URL-i każdego posta
    // Format: [ [pl_url, en_url, de_url, ...], ... ]
    $entries = [];

    foreach ($posts as $post) {
        if (tl_is_post_excluded_from_sitemap((int) $post->ID, $sitemap_settings)) continue;

        // Pobierz pełną PL ścieżkę (z ancestorami dla stron)
        if ($post->post_type === 'page') {
            $ancestors  = array_reverse(get_post_ancestors($post->ID));
            $path_parts = [];
            foreach ($ancestors as $ancestor_id) {
                $ancestor = get_post($ancestor_id);
                if ($ancestor) $path_parts[] = $ancestor->post_name;
            }
            $path_parts[] = $post->post_name;
            $pl_path = implode('/', $path_parts);
        } else {
            $pl_path = $post->post_name;
        }

        $lastmod = gmdate('Y-m-d', strtotime($post->post_modified_gmt));

        // PL URL (bez prefiksu)
        $entry = [
            'lastmod'   => $lastmod,
            'hreflang'  => [],
        ];

        $pl_url = $home_raw . '/' . $pl_path . '/';
        $entry['hreflang']['pl'] = $pl_url;
        $entry['hreflang']['pl-PL'] = $pl_url;
        $entry['loc']               = $pl_url; // domyślnie PL jako loc

        // URL-e dla pozostałych języków
        foreach ($lang_codes as $code) {
            $segments        = array_values(array_filter(explode('/', $pl_path)));
            $tr_segments     = array_map(fn($s) => tl_translate_slug($s, $code), $segments);
            $tr_path         = implode('/', $tr_segments);
            $lang_url        = $home_raw . '/' . $code . '/' . $tr_path . '/';
            $html_tag        = $langs[$code]['html'] ?? $code;
            $entry['hreflang'][$html_tag] = $lang_url;
        }

        // x-default = PL
        $entry['hreflang']['x-default'] = $pl_url;

        $entries[] = $entry;
    }

    // Strona główna
    $home_hreflang = ['pl' => $home_raw . '/', 'pl-PL' => $home_raw . '/'];
    foreach ($lang_codes as $code) {
        $html_tag = $langs[$code]['html'] ?? $code;
        $home_hreflang[$html_tag] = $home_raw . '/' . $code . '/';
    }
    $home_hreflang['x-default'] = $home_raw . '/';

    array_unshift($entries, [
        'loc'      => $home_raw . '/',
        'lastmod'  => gmdate('Y-m-d'),
        'hreflang' => $home_hreflang,
    ]);

    // Output XML
    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Robots-Tag: noindex');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
    echo '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

    foreach ($entries as $entry) {
        // Główny wpis (loc = wersja PL)
        echo "  <url>\n";
        echo '    <loc>' . esc_url($entry['loc']) . "</loc>\n";
        echo '    <lastmod>' . esc_html($entry['lastmod']) . "</lastmod>\n";
        foreach ($entry['hreflang'] as $hl => $url) {
            echo '    <xhtml:link rel="alternate" hreflang="' . esc_attr($hl) . '" href="' . esc_url($url) . '" />' . "\n";
        }
        echo "  </url>\n";

        // Osobny wpis dla każdej wersji językowej
        foreach ($lang_codes as $code) {
            $html_tag = $langs[$code]['html'] ?? $code;
            $lang_url = $entry['hreflang'][$html_tag] ?? null;
            if (!$lang_url) continue;

            echo "  <url>\n";
            echo '    <loc>' . esc_url($lang_url) . "</loc>\n";
            echo '    <lastmod>' . esc_html($entry['lastmod']) . "</lastmod>\n";
            foreach ($entry['hreflang'] as $hl => $url) {
                echo '    <xhtml:link rel="alternate" hreflang="' . esc_attr($hl) . '" href="' . esc_url($url) . '" />' . "\n";
            }
            echo "  </url>\n";
        }
    }

    echo '</urlset>';
    exit;
}, 1);

/**
 * Dodaj link do sitemap w robots.txt
 */
 add_filter('robots_txt', function ($output) {
     $home_raw = untrailingslashit(get_option('home'));
     $sitemap_url = $home_raw . '/sitemap.xml';
     if (strpos($output, 'sitemap.xml') === false) {
         $output .= "\nSitemap: " . $sitemap_url . "\n";
     }
     return $output;
 });
