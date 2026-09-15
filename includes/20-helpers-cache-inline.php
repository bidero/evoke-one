<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia - helpers, caches and inline phrase discovery
 */

// ====================================================================
// 2a. HELPERS
// ====================================================================

function tl_normalize_text_for_match(string $text): string {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = str_replace('&nbsp;', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim((string) $text);
}

function tl_split_paragraphs(string $text): array {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = trim($text);
    if ($text === '') return [];
    $parts = preg_split('/\n[ \t]*\n+/u', $text);
    $parts = array_map(function ($part) {
        $part = preg_replace('/[ \t]+/u', ' ', $part);
        $part = preg_replace('/\n+/u', ' ', $part);
        return trim((string) $part);
    }, $parts ?: []);
    return array_values(array_filter($parts, fn($p) => $p !== ''));
}

/**
 * Znaczniki, które wolno zostawić we frazie tłumaczenia.
 *
 * ZASADA, nie lista z sufitu: przechodzi to, co mieści się W ŚRODKU ZDANIA
 * i nie buduje układu strony. Łamanie wiersza, wyróżnienie, odnośnik. Nie ma
 * tu `div`, `p`, `script`, `iframe` ani niczego, co otwiera blok — fraza jest
 * kawałkiem tekstu wstawianym w cudzy element, więc znacznik blokowy i tak
 * rozjechałby jego układ.
 *
 * NAZWY MAŁYMI LITERAMI — `wp_kses()` porównuje je po sprowadzeniu do małych,
 * a w wyniku zostawia pisownię nienaruszoną. To samo, co przy sicie SVG
 * (`tl_svg_allowed_tags()` w 70-bricks-language-switcher.php).
 *
 * `style` przechodzi przez własne sito WordPressa (`safecss_filter_attr`),
 * które przepuszcza wyłącznie znane właściwości CSS — nie jest to więc furtka
 * na dowolną treść atrybutu.
 */
function tl_phrase_allowed_tags(): array {
    $liniowe = ['class' => [], 'id' => [], 'style' => [], 'lang' => [], 'dir' => []];

    return [
        'br'     => [],
        'strong' => $liniowe, 'b'   => $liniowe,
        'em'     => $liniowe, 'i'   => $liniowe,
        'u'      => $liniowe, 's'   => $liniowe,
        'small'  => $liniowe, 'sup' => $liniowe, 'sub' => $liniowe,
        'span'   => $liniowe,
        'a'      => $liniowe + ['href' => [], 'title' => [], 'target' => [], 'rel' => []],
    ];
}

/**
 * Sanityzacja frazy tłumaczenia — jedna dla WSZYSTKICH punktów zapisu.
 *
 * ZGŁOSZONE Z UŻYCIA: „moduł tłumaczeń nie wyświetla poprawnie elementów
 * z zapisanym <br> przy użyciu {tl_...}. Pomija <br>".
 *
 * Stało tu wszędzie `sanitize_textarea_field()`, a ono woła w środku
 * `wp_strip_all_tags()` — znacznik nie tyle „nie wyświetlał się", co NIE
 * DOJEŻDŻAŁ DO BAZY. Zmierzone na tej samej drodze, którą jedzie panel:
 * „Pierwsza linia<br>druga linia" zapisywało się jako „Pierwsza liniadruga
 * linia", czyli razem ze sklejeniem słów.
 *
 * ŚCIEŻKA BEZ ZNACZNIKÓW ZOSTAJE BAJT W BAJT TAKA JAK BYŁA. Fraza bez `<`
 * idzie dalej przez `sanitize_textarea_field()`, więc zachowuje całe
 * dotychczasowe czyszczenie (nieprawidłowy UTF-8, oktety, znaki sterujące),
 * a zmiana dotyka wyłącznie tych fraz, które znacznik naprawdę niosą.
 */
function tl_sanitize_phrase($value): string {
    $value = (string) $value;
    if ($value === '') return '';
    if (strpos($value, '<') === false) return sanitize_textarea_field($value);

    return trim(wp_kses($value, tl_phrase_allowed_tags()));
}

function tl_invalidate_cache(): void {
    delete_transient(TL_TRANSIENT_CONFIG);
    delete_transient(TL_TRANSIENT_INLINE);
    delete_transient(TL_TRANSIENT_SLUGS);
    foreach (tl_get_active_lang_codes() as $code) {
        delete_transient(TL_TRANSIENT_TOKENS . $code);
    }
}

// ====================================================================
// 2b. TRANSLATION CACHE
// ====================================================================
function get_translation_config(): array {
    static $mem_cache = null;
    if ($mem_cache !== null) return $mem_cache;
    $cached = get_transient(TL_TRANSIENT_CONFIG);
    if ($cached !== false) {
        $mem_cache = is_array($cached) ? $cached : ['strings' => [], 'meta' => []];
        return $mem_cache;
    }
    $data   = get_option('tl_translations', ['groups' => []]);
    $codes  = tl_get_active_lang_codes();
    $config = ['strings' => [], 'meta' => []];
    foreach (($data['groups'] ?? []) as $group) {
        foreach (($group['rows'] ?? []) as $row) {
            $pl = trim($row['pl'] ?? '');
            if ($pl === '') continue;
            $entry = [];
            foreach ($codes as $code) { $entry[$code] = trim($row[$code] ?? ''); }
            $config['strings'][$pl] = $entry;
            $pl_parts = tl_split_paragraphs($pl);
            if (count($pl_parts) < 2) continue;
            foreach ($pl_parts as $index => $pl_part) {
                if (isset($config['strings'][$pl_part])) continue;
                $part_entry = [];
                foreach ($codes as $code) {
                    $translated_parts = tl_split_paragraphs($entry[$code] ?? '');
                    $part_entry[$code] = $translated_parts[$index] ?? '';
                }
                $config['strings'][$pl_part] = $part_entry;
                $config['meta'][$pl_part] = ['parent_pl' => $pl, 'part_index' => $index];
            }
        }
    }
    set_transient(TL_TRANSIENT_CONFIG, $config, TL_CACHE_TTL);
    $mem_cache = $config;
    return $mem_cache;
}

/**
 * Indeks dopasowań: znormalizowana fraza PL → token i tłumaczenie.
 *
 * PO CO. Silnik porównywał tekst ze strony z KAŻDĄ frazą biblioteki po kolei,
 * a przy każdym porównaniu normalizował frazę wyszukiwaną od nowa. Koszt rósł
 * jak `węzły × frazy`: przy 600 akapitach i 300 frazach to 180 000 normalizacji
 * na jedno żądanie, z czego 179 400 wyrzucanych. Zmierzone przed zmianą:
 * 40,3 ms na samo tokenizowanie strony.
 *
 * Porównanie w obu miejscach było DOKŁADNĄ RÓWNOŚCIĄ (`===`), więc nie było
 * czego szukać liniowo — wystarczy tablica asocjacyjna i jedno sięgnięcie.
 * Normalizacja fraz dzieje się raz, przy budowie indeksu.
 *
 * KOLEJNOŚĆ WEDŁUG DŁUGOŚCI ZOSTAJE, choć wygląda na zbędną: gdy dwie różne
 * frazy sprowadzą się do tego samego klucza (np. różnią się tylko odstępami),
 * wygrać ma dłuższa — dokładnie tak, jak wygrywała w pętli po `uksort`.
 * Dlatego budujemy od najdłuższych i NIE nadpisujemy istniejącego klucza.
 *
 * BEZ WŁASNEGO TRANSIENTA. Indeks powstaje z `get_translation_config()`, który
 * już jest w pamięci podręcznej, a jego zbudowanie to jeden przebieg po frazach.
 * Własny transient trzeba by unieważniać razem z resztą — czyli dołożyć kolejne
 * miejsce, w którym da się o tym zapomnieć. Statyk na żądanie wystarcza.
 */
function tl_get_match_index(string $lang): array {
    static $mem = [];
    if (isset($mem[$lang])) return $mem[$lang];

    $strings = get_translation_config()['strings'] ?? [];
    uksort($strings, fn($a, $b) => mb_strlen($b) - mb_strlen($a));

    $index = [];
    foreach ($strings as $search => $translations) {
        if (empty($translations[$lang])) continue;

        $trimmed = trim($search);
        $klucz   = mb_strtolower(tl_normalize_text_for_match($trimmed));
        if ($klucz === '' || isset($index[$klucz])) continue;

        $index[$klucz] = [
            'token' => '##TL_' . md5($trimmed) . '##',
            'tlum'  => $translations[$lang],
        ];
    }

    $mem[$lang] = $index;
    return $index;
}

function tl_get_token_map(string $lang): array {
    static $mem = [];
    if (isset($mem[$lang])) return $mem[$lang];
    $cached = get_transient(TL_TRANSIENT_TOKENS . $lang);
    if ($cached !== false) { $mem[$lang] = is_array($cached) ? $cached : []; return $mem[$lang]; }
    $strings = get_translation_config()['strings'] ?? [];
    uksort($strings, fn($a, $b) => mb_strlen($b) - mb_strlen($a));
    $map = [];
    foreach ($strings as $search => $translations) {
        if (!empty($translations[$lang])) {
            $map['##TL_' . md5(trim($search)) . '##'] = $translations[$lang];
        }
    }
    set_transient(TL_TRANSIENT_TOKENS . $lang, $map, TL_CACHE_TTL);
    $mem[$lang] = $map;
    return $mem[$lang];
}

add_action('update_option_tl_translations', 'tl_invalidate_cache');
add_action('update_option_tl_languages', 'tl_invalidate_cache');
add_action('update_option_tl_dd_keys', 'tl_invalidate_cache');
add_action('update_option_tl_url_slugs', 'tl_invalidate_cache');

add_filter('bricks/code/echo_functions', function ($functions) {
    return array_merge($functions, ['lang_switch_url', 'get_current_lang']);
});

// ====================================================================
// 2c. INLINE PHRASE DISCOVERY
// ====================================================================
function tl_get_inline_phrases(): array {
    static $mem = null;
    if ($mem !== null) return $mem;
    $cached = get_transient(TL_TRANSIENT_INLINE);
    if ($cached !== false) { $mem = is_array($cached) ? $cached : []; return $mem; }
    $phrases = [];
    $posts = get_posts(['post_type' => ['bricks_template','page','post'], 'posts_per_page' => 200, 'post_status' => 'publish', 'fields' => 'ids']);
    foreach ($posts as $post_id) {
        $content = get_post_meta($post_id, '_bricks_page_content_2', true);
        if (empty($content)) continue;
        $json = is_string($content) ? $content : wp_json_encode($content);
        preg_match_all('/\{tl:([^}]+)\}/i', $json, $matches);
        foreach ($matches[0] as $index => $full_match) {
            $pairs = explode('|', $matches[1][$index]);
            $translations = [];
            foreach ($pairs as $pair) {
                if (strpos($pair, '=') === false) continue;
                [$code, $text] = explode('=', $pair, 2);
                $translations[strtolower(trim($code))] = trim($text);
            }
            if (!empty($translations)) {
                $key = $translations['pl'] ?? reset($translations);
                $phrases[$key] = ['source' => 'inline', 'raw' => $full_match, 'translations' => $translations];
            }
        }
        preg_match_all('/\[tl\s+([^\]]+)\]/i', $json, $shortcode_matches);
        foreach ($shortcode_matches[0] as $index => $full_match) {
            $attrs = $shortcode_matches[1][$index];
            $translations = [];
            preg_match_all('/(\w+)=["\']([^"\']+)["\']/i', $attrs, $attr_matches);
            foreach ($attr_matches[1] as $i => $attr_name) {
                $translations[strtolower($attr_name)] = $attr_matches[2][$i];
            }
            if (!empty($translations)) {
                $key = $translations['pl'] ?? reset($translations);
                $phrases[$key] = ['source' => 'shortcode', 'raw' => $full_match, 'translations' => $translations];
            }
        }
    }
    set_transient(TL_TRANSIENT_INLINE, $phrases, TL_CACHE_TTL);
    $mem = $phrases;
    return $mem;
}
foreach (['bricks_template','page','post'] as $_tl_post_type) {
    add_action('save_post_' . $_tl_post_type, function () { delete_transient(TL_TRANSIENT_INLINE); });
}

