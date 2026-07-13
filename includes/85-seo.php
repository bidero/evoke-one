<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł SEO
 *
 * Evoke ONE renderuje komplet meta tagów (title, description, keywords,
 * robots, og:*). Dane per strona wg łańcucha priorytetów:
 *   1. Bricks — Ustawienia strony → SEO / Media społecznościowe
 *      (documentTitle, metaDescription, metaKeywords, metaRobots,
 *       sharingTitle, sharingDescription, sharingImage; fallback na
 *       ustawienia aktywnego szablonu treści Bricks),
 *   2. zakładka SEO Evoke ONE (_evoke_seo_*),
 *   3. fallback automatyczny (tytuł strony, generator OG, miniatura).
 * Z tego samego resolvera korzysta moduł Schema (evk_seo_get_meta()).
 * Natywne meta tagi SEO/OG Bricksa są wyłączane, żeby nie dublować wpisów.
 */

// =========================================================================
// RESOLVER META DANYCH (wspólny dla SEO, OG i Schema)
// =========================================================================

/**
 * Ustawienia strony Bricks (_bricks_page_settings) z fallbackiem
 * na ustawienia aktywnego szablonu treści.
 */
function evk_seo_bricks_settings(int $pid): array {
    static $cache = [];
    if (isset($cache[$pid])) return $cache[$pid];

    $settings = get_post_meta($pid, '_bricks_page_settings', true);
    $settings = is_array($settings) ? $settings : [];

    if (class_exists('\Bricks\Database') && !empty(\Bricks\Database::$active_templates['content'])) {
        $tpl_id = (int) \Bricks\Database::$active_templates['content'];
        if ($tpl_id && $tpl_id !== $pid) {
            $tpl = get_post_meta($tpl_id, '_bricks_page_settings', true);
            if (is_array($tpl)) $settings += $tpl; // strona ma pierwszeństwo przed szablonem
        }
    }

    return $cache[$pid] = $settings;
}

/**
 * Renderuje wartość z Bricksa (dynamic data {tags}) do czystego tekstu.
 */
function evk_seo_render_bricks_value($value, int $pid): string {
    if (!is_string($value) || trim($value) === '') return '';
    if (function_exists('bricks_render_dynamic_data')) {
        $value = bricks_render_dynamic_data($value, $pid);
    }
    return trim(wp_strip_all_tags((string) $value));
}

/**
 * URL obrazka z pola sharingImage Bricksa (id / url / dynamic data).
 */
function evk_seo_bricks_sharing_image($img, int $pid): string {
    if (empty($img) || !is_array($img)) return '';

    if (!empty($img['useDynamicData']) && class_exists('\Bricks\Integrations\Dynamic_Data\Providers')) {
        $images = \Bricks\Integrations\Dynamic_Data\Providers::render_tag($img['useDynamicData'], $pid, 'image');
        if (!empty($images[0])) {
            return is_numeric($images[0])
                ? (string) wp_get_attachment_image_url((int) $images[0], 'full')
                : (string) $images[0];
        }
        return '';
    }

    if (!empty($img['url'])) return (string) $img['url'];
    if (!empty($img['id']))  return (string) wp_get_attachment_image_url((int) $img['id'], 'full');
    return '';
}

/**
 * Komplet meta danych strony.
 *
 * Zwraca: title, title_custom (puste = brak nadpisania, WP dokleja nazwę
 * witryny), desc, keywords, robots[], og_title, og_desc, og_image.
 */
function evk_seo_get_meta(int $pid): array {
    static $cache = [];
    if (isset($cache[$pid])) return $cache[$pid];

    $b = evk_seo_bricks_settings($pid);

    // Tytuł — title_custom tylko gdy jawnie ustawiony (Bricks lub zakładka SEO)
    $title_custom = evk_seo_render_bricks_value($b['documentTitle'] ?? '', $pid);
    if ($title_custom === '') $title_custom = trim((string) get_post_meta($pid, '_evoke_seo_title', true));
    $title = $title_custom !== '' ? $title_custom : get_the_title($pid);

    // Opis
    $desc = evk_seo_render_bricks_value($b['metaDescription'] ?? '', $pid);
    if ($desc === '') $desc = trim((string) get_post_meta($pid, '_evoke_seo_desc', true));

    // Słowa kluczowe (Bricks: metaKeywords, inaczej zakładka SEO)
    $keywords = evk_seo_render_bricks_value($b['metaKeywords'] ?? '', $pid);
    if ($keywords === '') $keywords = trim((string) get_post_meta($pid, '_evoke_seo_keywords', true));

    // Robots
    $valid_robots = ['index', 'noindex', 'follow', 'nofollow', 'noarchive', 'nosnippet'];
    $robots = array_values(array_intersect((array) ($b['metaRobots'] ?? []), $valid_robots));
    if (empty($robots)) {
        $robots = array_values(array_intersect(
            (array) (get_post_meta($pid, '_evoke_seo_robots', true) ?: []),
            $valid_robots
        ));
    }

    // OG — najpierw Media społecznościowe Bricksa, potem łańcuch meta
    $og_title = evk_seo_render_bricks_value($b['sharingTitle'] ?? '', $pid);
    if ($og_title === '') $og_title = $title;

    $og_desc = evk_seo_render_bricks_value($b['sharingDescription'] ?? '', $pid);
    if ($og_desc === '') $og_desc = $desc;

    // Obrazek OG: Bricks sharingImage → generator OG Evoke → miniatura
    $og_image = evk_seo_bricks_sharing_image($b['sharingImage'] ?? null, $pid);
    if ($og_image === '' && function_exists('evk_og_get_url') && !empty(evk_og_get_settings()['enabled'])) {
        $og_image = (string) evk_og_get_url($pid);
    }
    if ($og_image === '' && has_post_thumbnail($pid)) {
        $og_image = (string) get_the_post_thumbnail_url($pid, 'full');
    }

    return $cache[$pid] = compact('title', 'title_custom', 'desc', 'keywords', 'robots', 'og_title', 'og_desc', 'og_image');
}

// =========================================================================
// WYŁĄCZENIE NATYWNYCH META TAGÓW BRICKSA (Evoke przejmuje renderowanie)
// =========================================================================

add_filter('bricks/frontend/disable_seo', '__return_true');
add_filter('bricks/frontend/disable_opengraph', '__return_true');

// =========================================================================
// ZAPIS PÓL ZAKŁADKI SEO (AJAX)
// =========================================================================

add_action('wp_ajax_evoke_save_seo_ajax', function () {
    if (!current_user_can('manage_options') || empty($_POST['post_id'])) wp_send_json_error();
    $pid = absint($_POST['post_id']);
    update_post_meta($pid, '_evoke_seo_title',    sanitize_text_field(wp_unslash($_POST['seo_title']    ?? '')));
    update_post_meta($pid, '_evoke_seo_desc',     sanitize_textarea_field(wp_unslash($_POST['seo_desc'] ?? '')));
    update_post_meta($pid, '_evoke_seo_keywords', sanitize_text_field(wp_unslash($_POST['seo_keywords'] ?? '')));
    $robots = json_decode(wp_unslash($_POST['seo_robots'] ?? '[]'), true);
    $valid  = array_values(array_intersect((array)$robots, ['index','noindex','follow','nofollow','noarchive','nosnippet']));
    update_post_meta($pid, '_evoke_seo_robots', $valid);
    wp_send_json_success();
});

add_action('wp_ajax_evoke_save_seo_bulk', function () {
    if (!current_user_can('manage_options')) wp_send_json_error();
    $rows = json_decode(wp_unslash($_POST['rows'] ?? '[]'), true);
    $count = 0;
    foreach ((array)$rows as $row) {
        $pid = absint($row['post_id'] ?? 0);
        if (!$pid) continue;
        update_post_meta($pid, '_evoke_seo_title',    sanitize_text_field($row['seo_title']    ?? ''));
        update_post_meta($pid, '_evoke_seo_desc',     sanitize_textarea_field($row['seo_desc'] ?? ''));
        update_post_meta($pid, '_evoke_seo_keywords', sanitize_text_field($row['seo_keywords'] ?? ''));
        $robots = (array)($row['seo_robots'] ?? []);
        $valid  = array_values(array_intersect($robots, ['index','noindex','follow','nofollow','noarchive','nosnippet']));
        update_post_meta($pid, '_evoke_seo_robots', $valid);
        $count++;
    }
    wp_send_json_success(['saved' => $count]);
});

// =========================================================================
// FRONTEND — tytuł dokumentu i meta tagi w <head>
// =========================================================================

// 1. Filtr tytułu — nadpisuje tylko, gdy tytuł jest jawnie ustawiony
add_filter('pre_get_document_title', function ($title) {
    if (!is_singular() && !is_home()) return $title;
    $pid = get_queried_object_id();
    if (!$pid) return $title;
    $custom = evk_seo_get_meta($pid)['title_custom'];
    return $custom !== '' ? $custom : $title;
}, 999);

// 2. Meta tagi w <head>
add_action('wp_head', function () {
    if (!is_singular() && !is_home()) return;

    $pid = get_queried_object_id();
    if (!$pid) return;

    $m = evk_seo_get_meta($pid);

    if ($m['desc'])     echo '<meta name="description" content="' . esc_attr($m['desc']) . '">' . "\n";
    if ($m['keywords']) echo '<meta name="keywords" content="' . esc_attr($m['keywords']) . '">' . "\n";
    if ($m['robots'])   echo '<meta name="robots" content="' . esc_attr(implode(', ', $m['robots'])) . '">' . "\n";

    // OpenGraph
    if (is_home()) {
        $og_type = 'blog';
    } elseif (get_post_type($pid) === 'post') {
        $og_type = 'article';
    } else {
        $og_type = 'website';
    }

    echo '<meta property="og:title" content="' . esc_attr($m['og_title']) . '">' . "\n";
    echo '<meta property="og:type" content="' . esc_attr($og_type) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url(get_permalink($pid)) . '">' . "\n";
    echo '<meta property="og:site_name" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
    if ($m['og_desc'])  echo '<meta property="og:description" content="' . esc_attr($m['og_desc']) . '">' . "\n";
    if ($m['og_image']) echo '<meta property="og:image" content="' . esc_url($m['og_image']) . '">' . "\n";
}, 5);
