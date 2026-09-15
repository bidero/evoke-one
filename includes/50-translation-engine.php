<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia - translation tokenizer and render engine
 */

// ====================================================================
// 7. TRANSLATION ENGINE
// ====================================================================
/**
 * Tłumaczenie wartości atrybutu — jedno sięgnięcie do indeksu, nie pętla.
 *
 * `$strings` zostaje w sygnaturze, choć funkcja już go nie przegląda: wołający
 * (`tl_tokenize_attributes()`) i tak ma go pod ręką, a zmiana sygnatury zerwałaby
 * zgodność bez żadnego zysku. Rozstrzyga indeks z `tl_get_match_index()`.
 */
function tl_translate_attr_value(string $value, string $lang, array $strings): string {
    if ($value === '') return $value;

    $klucz = mb_strtolower(tl_normalize_text_for_match($value));
    if ($klucz === '') return $value;

    $trafienie = tl_get_match_index($lang)[$klucz] ?? null;
    return $trafienie === null ? $value : $trafienie['tlum'];
}

function tl_tokenize_attributes(string $content, string $lang): string {
    if ($lang === 'pl' || $content === '') return $content;

    $strings = get_translation_config()['strings'] ?? [];
    if (empty($strings)) return $content;

    $attrs_always = ['placeholder', 'aria-label', 'aria-placeholder', 'title'];

    return preg_replace_callback('/<([a-zA-Z][a-zA-Z0-9]*)\b([^>]*)>/u', function ($tag_match) use ($lang, $strings, $attrs_always) {
        $tag_name = strtolower($tag_match[1]);
        $attrs_str = $tag_match[2];
        $is_input = in_array($tag_name, ['input', 'textarea', 'select', 'button'], true);

        foreach ($attrs_always as $attr) {
            $attrs_str = preg_replace_callback('/(' . preg_quote($attr, '/') . '\s*=\s*)(["\'])(.*?)\2/iu', function ($match) use ($lang, $strings) {
                $prefix = $match[1];
                $quote  = $match[2];
                $value  = $match[3];
                return $prefix . $quote . esc_attr(tl_translate_attr_value($value, $lang, $strings)) . $quote;
            }, $attrs_str);
        }

        if ($is_input) {
            $attrs_str = preg_replace_callback('/(value\s*=\s*)(["\'])(.*?)\2/iu', function ($match) use ($lang, $strings) {
                $prefix = $match[1];
                $quote  = $match[2];
                $value  = $match[3];
                if (trim($value) === '') return $match[0];
                return $prefix . $quote . esc_attr(tl_translate_attr_value($value, $lang, $strings)) . $quote;
            }, $attrs_str);
        }

        return '<' . $tag_match[1] . $attrs_str . '>';
    }, $content);
}

function tl_tokenize_content(string $content, string $lang): string {
    if ($lang === 'pl' || $content === '') return $content;

    $strings = get_translation_config()['strings'] ?? [];
    if (empty($strings)) return $content;

    /* Indeks zamiast pętli po wszystkich frazach na KAŻDY węzeł tekstowy —
       patrz `tl_get_match_index()`. Porównanie było i jest dokładną równością,
       więc tablica asocjacyjna daje ten sam wynik jednym sięgnięciem. */
    $index = tl_get_match_index($lang);
    if (empty($index)) return tl_tokenize_attributes($content, $lang);

    $content = preg_replace_callback('/>([^<]+)</u', function ($matches) use ($index) {
        $klucz = mb_strtolower(tl_normalize_text_for_match($matches[1]));
        if ($klucz === '') return $matches[0];

        return isset($index[$klucz]) ? '>' . $index[$klucz]['token'] . '<' : $matches[0];
    }, $content);

    return tl_tokenize_attributes($content, $lang);
}

function tl_detokenize_content(string $content, string $lang): string {
    if ($lang === 'pl' || $content === '') return $content;

    $map = tl_get_token_map($lang);
    return empty($map) ? $content : strtr($content, $map);
}

add_filter('bricks/frontend/render_data', function ($content) {
    if (tl_is_bricks_editor() || tl_is_bricks_preview()) {
        return is_string($content) ? tl_replace_tl_tags_in_html($content, 'pl') : $content;
    }

    if (!is_string($content)) return $content;

    $content = preg_replace_callback('/\{tl:([^}]+)\}/i', fn($match) => tl_parse_inline_tag($match[1]), $content);
    return tl_tokenize_content($content, get_current_lang());
}, 1);
