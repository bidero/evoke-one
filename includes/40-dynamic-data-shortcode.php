<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia - dynamic data and shortcode support
 */

// ====================================================================
// 5. DYNAMIC DATA + SHORTCODE
// ====================================================================
function tl_get_dd_value(string $key, string $lang = ''): string {
    $key = sanitize_key($key);
    if (!$key) return '';

    $keys = get_option('tl_dd_keys', []);
    $pl_phrase = $keys[$key] ?? '';

    if (!$pl_phrase) {
        $data = get_option('tl_translations', ['groups' => []]);

        foreach (($data['groups'] ?? []) as $group) {
            foreach (($group['rows'] ?? []) as $row) {
                if (sanitize_key($row['dd_key'] ?? '') === $key) {
                    $pl_phrase = trim($row['pl'] ?? '');
                    break 2;
                }
            }
        }
    }

    if (!$pl_phrase) return '';

    if (tl_is_bricks_editor() || tl_is_bricks_preview()) {
        return $pl_phrase;
    }

    if ($lang === '') {
        $lang = get_current_lang();
    }

    if ($lang === 'pl') {
        return $pl_phrase;
    }

    $config = get_translation_config();
    return $config['strings'][$pl_phrase][$lang] ?? $pl_phrase;
}

function tl_parse_inline_tag(string $tag_content): string {
    $lang = get_current_lang();
    $codes = array_merge(['pl'], tl_get_active_lang_codes());
    $pairs = explode('|', $tag_content);
    $translations = [];

    foreach ($pairs as $pair) {
        if (strpos($pair, '=') === false) continue;
        [$code, $text] = explode('=', $pair, 2);
        $translations[strtolower(trim($code))] = trim($text);
    }

    if (!empty($translations[$lang])) return $translations[$lang];

    foreach ($codes as $code) {
        if (!empty($translations[$code])) return $translations[$code];
    }

    return '';
}

function tl_render_dd_tags_in_content(string $content, string $lang = ''): string {
    if ($content === '') return $content;

    return preg_replace_callback('/\{tl_([a-z0-9_]+)\}/i', function ($match) use ($lang) {
        $value = tl_get_dd_value($match[1], $lang);
        return $value !== '' ? $value : $match[0];
    }, $content);
}

function tl_replace_tl_tags_in_html(string $html, string $lang = ''): string {
    if ($html === '') return $html;

    $html = tl_render_dd_tags_in_content($html, $lang);

    /* `tl_sanitize_phrase()` zamiast `esc_html()` — patrz komentarz przy
       skrótkodzie `[tl]` niżej. */
    $html = preg_replace_callback('/\[tl\s+key=["\']?([a-z0-9_]+)["\']?\s*(?:fallback=["\']([^"\']*)["\'])?\s*\]/i', function ($match) use ($lang) {
        $value = tl_get_dd_value($match[1], $lang);
        if ($value !== '') return tl_sanitize_phrase($value);
        return isset($match[2]) ? tl_sanitize_phrase($match[2]) : $match[0];
    }, $html);

    return $html;
}

add_filter('bricks/dynamic_data/register_provider', function ($providers) {
    $providers[] = 'TL_Bricks_DD_Provider';
    return $providers;
});

if (!class_exists('TL_Bricks_DD_Provider')) {
    class TL_Bricks_DD_Provider {
        public static function get_tags(): array {
            $keys = get_option('tl_dd_keys', []);
            $tags = [];

            foreach ($keys as $key => $pl_phrase) {
                $tags[] = [
                    'name'  => '{tl_' . $key . '}',
                    'label' => 'TL: ' . ($pl_phrase ?: $key),
                    'group' => 'Tłumaczenia',
                ];
            }

            return $tags;
        }

        public static function render($tag, $post, $context) {
            if (!preg_match('/^\{tl_([a-z0-9_]+)\}$/i', $tag, $match)) return null;
            return tl_get_dd_value($match[1]);
        }
    }
}

add_filter('bricks/dynamic_data/render_tag', function ($tag, $post, $context) {
    if (!is_string($tag)) return $tag;

    if (preg_match('/^\{tl_([a-z0-9_]+)\}$/i', $tag, $match)) {
        $value = tl_get_dd_value($match[1], (tl_is_bricks_editor() || tl_is_bricks_preview()) ? 'pl' : '');
        return $value !== '' ? $value : $tag;
    }

    if (preg_match('/^\{tl:([^}]+)\}$/i', $tag, $match)) {
        $result = tl_parse_inline_tag($match[1]);
        return $result !== '' ? $result : $tag;
    }

    return $tag;
}, 1, 3);

add_filter('bricks/dynamic_data/render_content', function ($content, $post, $context) {
    if (empty($content) || !is_string($content)) return $content;

    if (tl_is_bricks_editor() || tl_is_bricks_preview()) {
        return tl_replace_tl_tags_in_html($content, 'pl');
    }

    $content = tl_render_dd_tags_in_content($content);
    $content = preg_replace_callback('/\{tl:([^}]+)\}/i', fn($match) => tl_parse_inline_tag($match[1]), $content);

    return $content;
}, 1, 3);

add_shortcode('tl', function ($atts) {
    $atts = shortcode_atts(['key' => '', 'fallback' => ''], $atts ?? [], 'tl');

    /*
     * SITO ZAMIAST ESCAPOWANIA.
     *
     * Stało tu `esc_html()`, co przy frazie z `<br>` wypisywało na stronie
     * `&lt;br&gt;` jako widoczny tekst. Fraza przechodzi przy ZAPISIE przez
     * `tl_sanitize_phrase()`, więc w bazie siedzi już wyłącznie to, co jest
     * na liście znaczników liniowych — puszczenie jej tu przez to samo sito
     * jest tańsze niż zaufanie bazie i jednocześnie nie gubi łamania wiersza.
     *
     * `fallback` idzie tą samą drogą: wpisuje go osoba edytująca stronę, więc
     * ma podlegać tej samej regule co fraza, a nie ostrzejszej.
     */
    if (!empty($atts['key'])) {
        $value = tl_get_dd_value(sanitize_key($atts['key']));
        return tl_sanitize_phrase($value !== '' ? $value : $atts['fallback']);
    }

    return tl_sanitize_phrase($atts['fallback']);
});

foreach (['the_content', 'widget_text'] as $_tl_filter) {
    add_filter($_tl_filter, function ($content) {
        if (empty($content) || !is_string($content)) return $content;

        if (strpos($content, '{tl:') !== false) {
            $content = preg_replace_callback('/\{tl:([^}]+)\}/i', fn($match) => tl_parse_inline_tag($match[1]), $content);
        }

        return $content;
    }, 5);
}

// Bricks Builder does not always render Dynamic Data in canvas; this only changes the visible preview DOM.
add_action('wp_footer', function () {
    if (!(tl_is_bricks_editor() || tl_is_bricks_preview())) return;
    if (is_admin()) return;

    $keys = get_option('tl_dd_keys', []);
    if (empty($keys)) return;
    ?>
    <script>
    (function() {
        const TL_DD_LABELS = <?php echo wp_json_encode($keys, JSON_UNESCAPED_UNICODE); ?>;

        function replaceTextNode(node) {
            let text = node.nodeValue;
            if (!text || (!text.includes('{tl_') && !text.includes('[tl'))) return;

            text = text.replace(/\{tl_([a-z0-9_]+)\}/gi, function(match, key) {
                return TL_DD_LABELS[key] || match;
            });

            text = text.replace(/\[tl\s+key=["']?([a-z0-9_]+)["']?[^\]]*\]/gi, function(match, key) {
                return TL_DD_LABELS[key] || match;
            });

            node.nodeValue = text;
        }

        function walk(root) {
            if (!root) return;

            const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
                acceptNode(node) {
                    const parent = node.parentElement;
                    if (!parent) return NodeFilter.FILTER_REJECT;

                    const tag = parent.tagName ? parent.tagName.toLowerCase() : '';
                    if (['script', 'style', 'textarea', 'input'].includes(tag)) return NodeFilter.FILTER_REJECT;

                    return NodeFilter.FILTER_ACCEPT;
                }
            });

            const nodes = [];
            let node;
            while ((node = walker.nextNode())) nodes.push(node);
            nodes.forEach(replaceTextNode);
        }

        function run() { walk(document.body); }

        run();
        setTimeout(run, 300);
        setTimeout(run, 1000);

        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                mutation.addedNodes.forEach(function(node) {
                    if (node.nodeType === Node.TEXT_NODE) {
                        replaceTextNode(node);
                    } else if (node.nodeType === Node.ELEMENT_NODE) {
                        walk(node);
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    })();
    </script>
    <?php
}, 999);


