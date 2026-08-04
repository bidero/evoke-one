<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia - Bricks language switcher dynamic data
 */

// ====================================================================
// 9. BRICKS QUERY LOOP - LANGUAGE SWITCHER
// ====================================================================
/**
 * Rejestracja custom query type dla języków
 */
add_filter('bricks/setup/control_options', function($options) {
    $options['queryTypes']['tl_languages'] = esc_html__('Języki', 'flavor');
    return $options;
});
/**
 * Wykonanie query - zwraca języki inne niż bieżący
 */
add_filter('bricks/query/run', function($results, $query_obj) {
    if ($query_obj->object_type !== 'tl_languages') {
        return $results;
    }
    $current_lang = get_current_lang();
    $languages = [];
    // Dodaj Polski jeśli nie jesteśmy w polskim
    if ($current_lang !== 'pl') {
        $pl_flag_id = get_option('tl_pl_flag', 0);
        $languages[] = (object) [
            'id'        => 'pl',
            'code'      => 'pl',
            'name'      => 'Polski',
            'html_tag'  => 'pl-PL',
            'flag_id'   => $pl_flag_id,
            'flag_url'  => $pl_flag_id ? wp_get_attachment_image_url($pl_flag_id, 'thumbnail') : '',
            'url'       => lang_switch_url_with_translated_slug('pl'),
            'is_current'=> false,
        ];
    }
    // Dodaj pozostałe języki (bez bieżącego)
    foreach (tl_get_languages() as $code => $lang) {
        if ($code === $current_lang) continue;
        $flag_id = absint($lang['flag'] ?? 0);
        $languages[] = (object) [
            'id'        => $code,
            'code'      => $code,
            'name'      => $lang['name'],
            'html_tag'  => $lang['html'] ?? $code,
            'flag_id'   => $flag_id,
            'flag_url'  => $flag_id ? tl_get_flag_url($flag_id) : '',
            'url'       => lang_switch_url_with_translated_slug($code),
            'is_current'=> false,
        ];
    }
    return $languages;
}, 10, 2);
/**
 * Ustawienie kontekstu loop
 */
add_filter('bricks/query/loop_object', function($loop_object, $loop_key, $query_obj) {
    if ($query_obj->object_type !== 'tl_languages') {
        return $loop_object;
    }
    return $loop_object;
}, 10, 3);
/**
 * Dynamic Data tags dla language loop
 */
add_filter('bricks/dynamic_tags_list', function($tags) {
    $tags[] = [
        'name'  => '{tl_lang_code}',
        'label' => 'TL: Kod języka',
        'group' => 'Tłumaczenia',
    ];
    $tags[] = [
        'name'  => '{tl_lang_name}',
        'label' => 'TL: Nazwa języka',
        'group' => 'Tłumaczenia',
    ];
    $tags[] = [
        'name'  => '{tl_lang_flag}',
        'label' => 'TL: URL flagi',
        'group' => 'Tłumaczenia',
    ];
    $tags[] = [
        'name'  => '{tl_lang_flag_id}',
        'label' => 'TL: ID flagi (dla Image)',
        'group' => 'Tłumaczenia',
    ];
    $tags[] = [
        'name'  => '{tl_lang_url}',
        'label' => 'TL: URL przełącznika',
        'group' => 'Tłumaczenia',
    ];
    $tags[] = [
        'name'  => '{tl_current_lang}',
        'label' => 'TL: Bieżący język (kod)',
        'group' => 'Tłumaczenia',
    ];
    $tags[] = [
        'name'  => '{tl_current_lang_name}',
        'label' => 'TL: Bieżący język (nazwa)',
        'group' => 'Tłumaczenia',
    ];
    $tags[] = [
        'name'  => '{tl_current_lang_flag}',
        'label' => 'TL: Bieżący język (flaga URL)',
        'group' => 'Tłumaczenia',
    ];
    return $tags;
});

add_filter('bricks/query/loop_object', function($loop_object, $loop_key, $query_obj) {
    if (is_object($loop_object) && isset($loop_object->code)) {
        $GLOBALS['tl_current_loop_lang'] = $loop_object;
    }
    return $loop_object;
}, 10, 3);

/**
 * Renderowanie Dynamic Data tags
 */
add_filter('bricks/dynamic_data/render_tag', function($tag, $post, $context) {
    if (!is_string($tag)) return $tag;
    // Current language tags (działają wszędzie)
    if ($tag === '{tl_current_lang}') {
        return get_current_lang();
    }
    if ($tag === '{tl_current_lang_name}') {
        $current = get_current_lang();
        if ($current === 'pl') return 'Polski';
        $langs = tl_get_languages();
        return $langs[$current]['name'] ?? $current;
    }
    if ($tag === '{tl_current_lang_flag}') {
        $current = get_current_lang();
        if ($current === 'pl') {
            $flag_id = get_option('tl_pl_flag', 0);
            return $flag_id ? tl_get_flag_url($flag_id) : '';
        }
        $langs = tl_get_languages();
        $flag_id = absint($langs[$current]['flag'] ?? 0);
        return $flag_id ? tl_get_flag_url($flag_id) : '';
    }
    // Loop context tags
$loop_object = \Bricks\Query::get_loop_object();
if (!is_object($loop_object) || !isset($loop_object->code)) {
    $loop_object = $GLOBALS['tl_current_loop_lang'] ?? null;
}
if (!is_object($loop_object) || !isset($loop_object->code)) {
    return $tag;
}
    switch ($tag) {
        case '{tl_lang_code}':
            return $loop_object->code ?? '';
        case '{tl_lang_name}':
            return $loop_object->name ?? '';
        case '{tl_lang_flag}':
            return $loop_object->flag_url ?? '';
        case '{tl_lang_flag_id}':
            return $loop_object->flag_id ?? '';
        case '{tl_lang_url}':
            return $loop_object->url ?? '';
    }
    return $tag;
}, 5, 3);
/**
 * Obsługa Dynamic Data w atrybucie src dla obrazków
 */
add_filter('bricks/dynamic_data/render_content', function($content, $post, $context) {
    if (empty($content) || !is_string($content)) return $content;
    // Zamień tagi języka w kontekście loop
    $loop_object = \Bricks\Query::get_loop_object();
    if (is_object($loop_object) && isset($loop_object->code)) {
        $content = str_replace('{tl_lang_code}', $loop_object->code ?? '', $content);
        $content = str_replace('{tl_lang_name}', $loop_object->name ?? '', $content);
        $content = str_replace('{tl_lang_flag}', $loop_object->flag_url ?? '', $content);
        $content = str_replace('{tl_lang_flag_id}', $loop_object->flag_id ?? '', $content);
        $content = str_replace('{tl_lang_url}', $loop_object->url ?? '', $content);
    }
    // Current language tags
    $content = str_replace('{tl_current_lang}', get_current_lang(), $content);
    if (strpos($content, '{tl_current_lang_name}') !== false) {
        $current = get_current_lang();
        $name = ($current === 'pl') ? 'Polski' : (tl_get_languages()[$current]['name'] ?? $current);
        $content = str_replace('{tl_current_lang_name}', $name, $content);
    }
    if (strpos($content, '{tl_current_lang_flag}') !== false) {
        $current = get_current_lang();
        if ($current === 'pl') {
            $flag_id = get_option('tl_pl_flag', 0);
            $flag_url = $flag_id ? tl_get_flag_url($flag_id) : '';
        } else {
            $langs = tl_get_languages();
            $flag_id = absint($langs[$current]['flag'] ?? 0);
            $flag_url = $flag_id ? tl_get_flag_url($flag_id) : '';
        }
        $content = str_replace('{tl_current_lang_flag}', $flag_url, $content);
    }
    return $content;
}, 5, 3);
/**
 * Helper functions dla użycia w kodzie/szablonach
 */
function tl_get_other_languages(): array {
    $current_lang = get_current_lang();
    $languages = [];
    if ($current_lang !== 'pl') {
        $pl_flag_id = get_option('tl_pl_flag', 0);
        $languages['pl'] = [
            'code'      => 'pl',
            'name'      => 'Polski',
            'html_tag'  => 'pl-PL',
            'flag_id'   => $pl_flag_id,
            'flag_url'  => $pl_flag_id ? wp_get_attachment_image_url($pl_flag_id, 'thumbnail') : '',
            'url'       => lang_switch_url_with_translated_slug('pl'),
        ];
    }
    foreach (tl_get_languages() as $code => $lang) {
        if ($code === $current_lang) continue;
        $flag_id = absint($lang['flag'] ?? 0);
        $languages[$code] = [
            'code'      => $code,
            'name'      => $lang['name'],
            'html_tag'  => $lang['html'] ?? $code,
            'flag_id'   => $flag_id,
            'flag_url'  => $flag_id ? tl_get_flag_url($flag_id) : '',
            'url'       => lang_switch_url_with_translated_slug($code),
        ];
    }
    return $languages;
}
function tl_get_current_language_info(): array {
    $current = get_current_lang();
    if ($current === 'pl') {
        $flag_id = get_option('tl_pl_flag', 0);
        return [
            'code'      => 'pl',
            'name'      => 'Polski',
            'html_tag'  => 'pl-PL',
            'flag_id'   => $flag_id,
            'flag_url'  => $flag_id ? tl_get_flag_url($flag_id) : '',
        ];
    }
    $langs = tl_get_languages();
    $lang = $langs[$current] ?? [];
    $flag_id = absint($lang['flag'] ?? 0);
    return [
        'code'      => $current,
        'name'      => $lang['name'] ?? $current,
        'html_tag'  => $lang['html'] ?? $current,
        'flag_id'   => $flag_id,
        'flag_url'  => $flag_id ? tl_get_flag_url($flag_id) : '',
    ];
}
// Dodaj funkcje do dozwolonych w Bricks
add_filter('bricks/code/echo_functions', function ($functions) {
    return array_merge($functions, ['tl_get_other_languages', 'tl_get_current_language_info']);
});

// ====================================================================
// 10. DYNAMIC DATA - IMAGE PROVIDER DLA LANGUAGE LOOP
// ====================================================================

/**
 * Obsługa {tl_lang_flag_id} jako źródła obrazka w Bricks Image element
 */
add_filter('bricks/dynamic_data/render_content', function($content, $post, $context) {
    if (empty($content) || !is_string($content)) return $content;

    // Sprawdź czy to jest kontekst obrazka (Bricks przekazuje sam tag dla image source)
    if (strpos($content, '{tl_lang_flag_id}') !== false) {
        $loop_object = \Bricks\Query::get_loop_object();

        if (is_object($loop_object) && isset($loop_object->flag_id) && $loop_object->flag_id > 0) {
            $content = str_replace('{tl_lang_flag_id}', $loop_object->flag_id, $content);
        } else {
            // Fallback - usuń tag jeśli brak flagi
            $content = str_replace('{tl_lang_flag_id}', '', $content);
        }
    }

    return $content;
}, 1, 3); // Priorytet 1 - przed innymi filtrami

/**
 * Rejestracja tagu jako źródła obrazka dla Bricks
 */
add_filter('bricks/dynamic_data/render_tag', function($tag, $post, $context) {
    if (!is_string($tag)) return $tag;

    // Obsługa {tl_lang_flag_id} - zwraca attachment ID
    if ($tag === '{tl_lang_flag_id}') {
        $loop_object = \Bricks\Query::get_loop_object();

        if (is_object($loop_object) && isset($loop_object->flag_id) && $loop_object->flag_id > 0) {
            return (int) $loop_object->flag_id;
        }

        return '';
    }

    return $tag;
}, 1, 3);

/**
 * Dodatkowy filtr dla elementu Image w Bricks
 * Obsługuje przypadek gdy Dynamic Data jest użyte jako "External URL" lub "Dynamic Data"
 */
add_filter('bricks/image/tag_content', function($image_id, $element) {
    // Sprawdź czy używamy dynamic data z language loop
    $settings = $element->settings ?? [];

    if (!empty($settings['image']['useDynamicData']) &&
        strpos($settings['image']['useDynamicData'], 'tl_lang_flag_id') !== false) {

        $loop_object = \Bricks\Query::get_loop_object();

        if (is_object($loop_object) && isset($loop_object->flag_id) && $loop_object->flag_id > 0) {
            return (int) $loop_object->flag_id;
        }
    }

    return $image_id;
}, 10, 2);

/**
 * Obsługa wszystkich języków (włącznie z bieżącym) dla pełnego language switcher
 */
add_filter('bricks/setup/control_options', function($options) {
    // Dodaj drugi typ query - wszystkie języki
    $options['queryTypes']['tl_all_languages'] = esc_html__('Języki (wszystkie)', 'flavor');
    return $options;
}, 11);

add_filter('bricks/query/run', function($results, $query_obj) {
    if ($query_obj->object_type !== 'tl_all_languages') {
        return $results;
    }

    $current_lang = get_current_lang();
    $languages = [];

    // Dodaj Polski
    $pl_flag_id = get_option('tl_pl_flag', 0);
    $languages[] = (object) [
        'id'        => 'pl',
        'code'      => 'pl',
        'name'      => 'Polski',
        'html_tag'  => 'pl-PL',
        'flag_id'   => $pl_flag_id,
        'flag_url'  => $pl_flag_id ? wp_get_attachment_image_url($pl_flag_id, 'thumbnail') : '',
        'url'       => lang_switch_url_with_translated_slug('pl'),
        'is_current'=> ($current_lang === 'pl'),
    ];

    // Dodaj pozostałe języki
    foreach (tl_get_languages() as $code => $lang) {
        $flag_id = absint($lang['flag'] ?? 0);
        $languages[] = (object) [
            'id'        => $code,
            'code'      => $code,
            'name'      => $lang['name'],
            'html_tag'  => $lang['html'] ?? $code,
            'flag_id'   => $flag_id,
            'flag_url'  => $flag_id ? tl_get_flag_url($flag_id) : '',
            'url'       => lang_switch_url_with_translated_slug($code),
            'is_current'=> ($code === $current_lang),
        ];
    }

    return $languages;
}, 10, 2);

/**
 * Dodatkowy tag - czy język jest bieżący (dla warunkowego stylowania)
 */
add_filter('bricks/dynamic_tags_list', function($tags) {
    $tags[] = [
        'name'  => '{tl_lang_is_current}',
        'label' => 'TL: Czy bieżący język (true/false)',
        'group' => 'Tłumaczenia',
    ];
    return $tags;
}, 11);

add_filter('bricks/dynamic_data/render_tag', function($tag, $post, $context) {
    if ($tag === '{tl_lang_is_current}') {
        $loop_object = \Bricks\Query::get_loop_object();
        if (is_object($loop_object) && isset($loop_object->is_current)) {
            return $loop_object->is_current ? 'true' : 'false';
        }
        return 'false';
    }
    return $tag;
}, 1, 3);

// ====================================================================
// 11. BRICKS IMAGE - DYNAMIC DATA FIX FOR LANGUAGE LOOP
// ====================================================================

/**
 * Filtr dla Bricks Image element - obsługa {tl_lang_flag_id} jako attachment ID
 */
add_filter('bricks/element/settings', function($settings, $element) {
    if ($element->name !== 'image') return $settings;

    // Sprawdź czy używamy naszego dynamic data
    $dynamic_data = $settings['image']['useDynamicData'] ?? '';

    if (strpos($dynamic_data, 'tl_lang_flag_id') !== false) {
        $loop_object = \Bricks\Query::get_loop_object();

        if (is_object($loop_object) && !empty($loop_object->flag_id)) {
            // Podmień na rzeczywiste ID załącznika
            $settings['image'] = [
                'id'  => (int) $loop_object->flag_id,
                'url' => wp_get_attachment_url($loop_object->flag_id),
            ];
            unset($settings['image']['useDynamicData']);
        }
    }

    return $settings;
}, 10, 2);

/**
 * Alternatywna metoda - filtr render_attributes dla img
 */
add_filter('bricks/element/render_attributes', function($attributes, $key, $element) {
    if ($key !== 'img' || $element->name !== 'image') return $attributes;

    $settings = $element->settings ?? [];
    $dynamic_data = $settings['image']['useDynamicData'] ?? '';

    if (strpos($dynamic_data, 'tl_lang_flag_id') !== false) {
        $loop_object = \Bricks\Query::get_loop_object();

        if (is_object($loop_object) && !empty($loop_object->flag_url)) {
            // Bricks grupuje atrybuty po kluczu ($attributes[$key]['src']).
            // Zapis płaski był po cichu ignorowany.
            if (isset($attributes[$key]) && is_array($attributes[$key])) {
                $attributes[$key]['src'] = [$loop_object->flag_url];
            } else {
                $attributes['src'] = $loop_object->flag_url;
            }
        }
    }

    return $attributes;
}, 10, 3);

// ====================================================================
// 12. INLINE SVG FLAG TAG
// ====================================================================

/**
 * Rejestracja tagu {tl_lang_flag_svg} w liście tagów
 */
add_filter('bricks/dynamic_tags_list', function($tags) {
    $tags[] = [
        'name'  => '{tl_lang_flag_svg}',
        'label' => 'TL: Flaga SVG (inline kod)',
        'group' => 'Tłumaczenia',
    ];
    $tags[] = [
        'name'  => '{tl_current_lang_flag_svg}',
        'label' => 'TL: Bieżący język (flaga SVG inline)',
        'group' => 'Tłumaczenia',
    ];
    return $tags;
}, 12);

/**
 * Pomocnicza funkcja - pobiera zawartość SVG z Media Library
 */
function tl_get_svg_content(int $attachment_id): string {
    if (!$attachment_id) return '';

    $mime = get_post_mime_type($attachment_id);
    if ($mime !== 'image/svg+xml') return '';

    $file_path = get_attached_file($attachment_id);
    if (!$file_path || !file_exists($file_path)) return '';

    $svg = file_get_contents($file_path);
    if (!$svg) return '';

    // Usuń deklarację XML i doctype
    $svg = preg_replace('/<\?xml[^>]*\?>/i', '', $svg);
    $svg = preg_replace('/<!DOCTYPE[^>]*>/i', '', $svg);
    $svg = trim($svg);

    return $svg;
}

/**
 * Renderowanie tagów SVG
 */
add_filter('bricks/dynamic_data/render_tag', function($tag, $post, $context) {
    if (!is_string($tag)) return $tag;

    // SVG bieżącego języka
    if ($tag === '{tl_current_lang_flag_svg}') {
        $current = get_current_lang();
        if ($current === 'pl') {
            $flag_id = get_option('tl_pl_flag', 0);
        } else {
            $langs = tl_get_languages();
            $flag_id = absint($langs[$current]['flag'] ?? 0);
        }
        return tl_get_svg_content($flag_id);
    }

    // SVG z loop
    if ($tag === '{tl_lang_flag_svg}') {
        $loop_object = \Bricks\Query::get_loop_object();
        if (!is_object($loop_object) || !isset($loop_object->code)) {
            $loop_object = $GLOBALS['tl_current_loop_lang'] ?? null;
        }
        if (is_object($loop_object) && !empty($loop_object->flag_id)) {
            return tl_get_svg_content((int) $loop_object->flag_id);
        }
        return '';
    }

    return $tag;
}, 5, 3);

/**
 * render_content dla SVG w loop
 */
add_filter('bricks/dynamic_data/render_content', function($content, $post, $context) {
    if (empty($content) || !is_string($content)) return $content;

    if (strpos($content, '{tl_lang_flag_svg}') !== false) {
        $loop_object = \Bricks\Query::get_loop_object();
        if (is_object($loop_object) && !empty($loop_object->flag_id)) {
            $svg = tl_get_svg_content((int) $loop_object->flag_id);
            $content = str_replace('{tl_lang_flag_svg}', $svg, $content);
        } else {
            $content = str_replace('{tl_lang_flag_svg}', '', $content);
        }
    }

    if (strpos($content, '{tl_current_lang_flag_svg}') !== false) {
        $current = get_current_lang();
        $flag_id = ($current === 'pl')
            ? get_option('tl_pl_flag', 0)
            : absint(tl_get_languages()[$current]['flag'] ?? 0);
        $svg = tl_get_svg_content($flag_id);
        $content = str_replace('{tl_current_lang_flag_svg}', $svg, $content);
    }

    return $content;
}, 5, 3);

