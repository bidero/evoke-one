<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia - translated image replacement
 */

// ====================================================================
// 8. IMAGE REPLACEMENT
// ====================================================================
function tl_get_image_url_map(string $lang): array {
    static $maps = [];
    if (isset($maps[$lang])) return $maps[$lang];

    $images = get_option('tl_images', []);
    $map = [];

    foreach ($images as $entry) {
        $pl_id = absint($entry['pl'] ?? 0);
        $lang_id = absint($entry[$lang] ?? 0);

        if (!$pl_id || !$lang_id || $pl_id === $lang_id) continue;

        $pl_url = wp_get_attachment_url($pl_id);
        $lang_url = wp_get_attachment_url($lang_id);

        if (!$pl_url || !$lang_url) continue;

        $map[$pl_url] = $lang_url;
        $pl_meta = wp_get_attachment_metadata($pl_id);
        $lang_meta = wp_get_attachment_metadata($lang_id);

        if (is_array($pl_meta['sizes'] ?? null) && is_array($lang_meta['sizes'] ?? null)) {
            $pl_base = trailingslashit(dirname($pl_url));
            $lang_base = trailingslashit(dirname($lang_url));

            foreach ($pl_meta['sizes'] as $size => $info) {
                if (!empty($lang_meta['sizes'][$size]['file'])) {
                    $map[$pl_base . $info['file']] = $lang_base . $lang_meta['sizes'][$size]['file'];
                }
            }
        }
    }

    $maps[$lang] = $map;
    return $map;
}

add_filter('wp_get_attachment_image_src', function ($image, $attachment_id, $size) {
    if (!$image) return $image;

    $lang = get_current_lang();
    if ($lang === 'pl' || tl_is_bricks_editor()) return $image;

    foreach (get_option('tl_images', []) as $entry) {
        $pl_id = absint($entry['pl'] ?? 0);
        $lang_id = absint($entry[$lang] ?? 0);

        if ($pl_id !== $attachment_id || !$lang_id || $lang_id === $attachment_id) continue;

        $lang_url = wp_get_attachment_url($lang_id);
        $lang_meta = wp_get_attachment_metadata($lang_id);

        if (!$lang_url || !$lang_meta) break;

        if (is_string($size) && $size !== 'full' && !empty($lang_meta['sizes'][$size])) {
            $s = $lang_meta['sizes'][$size];
            $base_url = trailingslashit(dirname($lang_url));
            return [$base_url . $s['file'], $s['width'], $s['height'], true];
        }

        return [$lang_url, $lang_meta['width'] ?? $image[1], $lang_meta['height'] ?? $image[2], false];
    }

    return $image;
}, 10, 3);

add_filter('wp_calculate_image_srcset', function ($sources, $size_array, $src, $meta, $attachment_id) {
    $lang = get_current_lang();
    if ($lang === 'pl' || tl_is_bricks_editor()) return $sources;

    foreach (get_option('tl_images', []) as $entry) {
        $pl_id = absint($entry['pl'] ?? 0);
        $lang_id = absint($entry[$lang] ?? 0);

        if ($pl_id !== $attachment_id || !$lang_id || $lang_id === $attachment_id) continue;

        $lang_meta = wp_get_attachment_metadata($lang_id);
        $lang_url = wp_get_attachment_url($lang_id);

        if (!$lang_meta || !$lang_url) break;

        $lang_base = trailingslashit(dirname($lang_url));
        $new_sources = [];

        foreach ($sources as $width => $source) {
            $matched = false;

            foreach ($lang_meta['sizes'] ?? [] as $size_data) {
                if (absint($size_data['width']) === absint($width)) {
                    $new_sources[$width] = [
                        'url'        => $lang_base . $size_data['file'],
                        'descriptor' => $source['descriptor'],
                        'value'      => $source['value'],
                    ];
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                $new_sources[$width] = (absint($lang_meta['width'] ?? 0) === absint($width))
                    ? ['url' => $lang_url, 'descriptor' => $source['descriptor'], 'value' => $source['value']]
                    : $source;
            }
        }

        return $new_sources;
    }

    return $sources;
}, 10, 5);

// Final frontend fallback. Do not run in Bricks Builder, as Builder canvas is handled by the preview DOM script.
add_action('wp', function () {
    if (is_admin() || wp_doing_ajax() || tl_is_bricks_editor() || tl_is_bricks_preview()) return;

    $lang = get_current_lang();

    ob_start(function ($buffer) use ($lang) {
        if (empty($buffer)) return $buffer;

        /* `{tl:pl=…|en=…}` poza treścią Bricksa (np. opis SEO) — do 1.250.0
           rozwijany tylko w językach obcych, więc po polsku zostawał dosłownie. */
        if ($lang === 'pl') {
            $buffer = tl_replace_tl_tags_in_html($buffer, 'pl');
            if (strpos($buffer, '{tl:') !== false) {
                $buffer = preg_replace_callback('/\{tl:([^}]+)\}/i', fn($match) => tl_parse_inline_tag($match[1]), $buffer);
            }
            return $buffer;
        }

        $buffer = tl_replace_tl_tags_in_html($buffer, $lang);
        $buffer = tl_detokenize_content(tl_tokenize_content($buffer, $lang), $lang);

        if (strpos($buffer, '{tl:') !== false) {
            $buffer = preg_replace_callback('/\{tl:([^}]+)\}/i', fn($match) => tl_parse_inline_tag($match[1]), $buffer);
        }

        $map = tl_get_image_url_map($lang);
        if (!empty($map)) {
            uksort($map, fn($a, $b) => strlen($b) - strlen($a));
            $buffer = str_replace(array_keys($map), array_values($map), $buffer);
        }

        return $buffer;
    });
}, 0);

