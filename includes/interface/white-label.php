<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — White Label
 * Personalizacja panelu administracyjnego: logo, nazwa, kolory, pasek górny, menu boczne.
 */

// =========================================================================
// DEFAULTS & GETTERS
// =========================================================================

function evk_wl_defaults(): array {
    return [
        'enabled'                  => 0,
        'logo_url'                 => '',
        'logo_width'               => 160,
        'logo_height'              => 60,
        'site_name'                => '',
        'footer_text'              => '',
        'footer_logo_url'          => '',
        'footer_logo_width'        => 32,
        'footer_logo_height'       => 32,
        'color_primary'            => '#2563eb',
        'color_menu_bg'            => '',
        'color_menu_text'          => '',
        'color_menu_icon'          => '',
        'color_menu_hover'         => '',
        'color_menu_hover_text'    => '',
        'color_menu_active'        => '',
        'color_menu_active_text'   => '',
        'color_menu_badge'         => '',
        'color_menu_badge_text'    => '',
        'color_body_bg'            => '',
        'color_content_bg'         => '',
        'color_content_text'       => '',
        'color_link'               => '',
        'color_notice_bg'          => '',
        'admin_bar_title'          => '',
        'admin_bar_color'          => '',
        'admin_bar_hover_color'    => '',
        'admin_bar_sub_color'      => '',
        'hide_admin_bar_logo'      => 0,
        'hide_wp_logo'             => 1,
        'hide_help_tab'            => 1,
        'hide_screen_opts'         => 0,
        'hide_footer_wp'           => 1,
        'admin_font_family'        => '',
        'color_admin_bar_link'     => '',
        'color_submenu_current_bg' => '',
        'color_submenu_current_tx' => '',
        'custom_css_admin'         => '',
        'bar_nodes_extra'          => [],  // ['node-id' => 'Etykieta']
        'sidebar_labels'           => [],  // ['slug' => 'Własna nazwa']
        'bar_nodes_hidden'         => [],
        'sidebar_hidden'           => [],
        'bar_nodes_order'          => [],
        'bar_nodes_side'           => [],  // ['node-id' => 'left'|'right']
        'sidebar_menu_order'       => [],
    ];
}

function evk_wl_normalize(array $data): array {
    $result = array_merge(evk_wl_defaults(), $data);
    if (!is_array($result['bar_nodes_extra']))    $result['bar_nodes_extra']    = [];
    if (!is_array($result['sidebar_labels']))     $result['sidebar_labels']     = [];
    if (!is_array($result['bar_nodes_hidden']))   $result['bar_nodes_hidden']   = [];
    if (!is_array($result['sidebar_hidden']))     $result['sidebar_hidden']     = [];
    if (!is_array($result['bar_nodes_order']))    $result['bar_nodes_order']    = [];
    if (!is_array($result['bar_nodes_side']))     $result['bar_nodes_side']     = [];
    if (!is_array($result['sidebar_menu_order'])) $result['sidebar_menu_order'] = [];
    return $result;
}

/**
 * Własny CSS panelu — bez możliwości wyjścia z bloku `<style>`.
 *
 * Do 1.132.0 treść z pola „Własny CSS" szła do strony dokładnie taka, jaka
 * przyszła z formularza: `trim()` przy zapisie, sklejenie z resztą reguł
 * i `echo '<style id="evk-white-label">'.$css.'</style>'`. Ciąg
 * `</style><script>…` wychodził z bloku i wykonywał się w panelu każdego
 * administratora. Samo pole wymaga `manage_options`, więc to nie było
 * podniesienie uprawnień — ale ta sama opcja jedzie przez import ustawień,
 * a wtedy droga robi się krótsza, niż wygląda.
 *
 * WYCINAMY WYŁĄCZNIE `</style`, nie wszystkie znaki `<`. Parser HTML kończy
 * blok RAWTEXT dokładnie na tej sekwencji i na niczym innym; usuwanie każdego
 * `<` popsułoby poprawny, współczesny CSS — zapytania zakresowe piszą się
 * `@media (400px <= width <= 700px)`.
 */
function evk_wl_bezpieczny_css(string $css): string {
    return trim((string) preg_replace('#</\s*style\s*>?#i', '', $css));
}

function evk_wl_get(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $stored = get_option('evk_white_label', []);
    $stored = is_array($stored) ? $stored : [];
    $cached = evk_wl_normalize($stored);
    return $cached;
}

function evk_wl_bar_items_get(): array {
    static $cached = null;
    if ($cached !== null) return $cached;
    $raw    = get_option('evk_wl_bar_items', '[]');
    $items  = is_string($raw) ? json_decode($raw, true) : [];
    $cached = is_array($items) ? $items : [];
    return $cached;
}

function evk_wl_sanitize_menu_slug(string $slug): string {
    return preg_replace('/[^a-zA-Z0-9._\-?=&%]/', '', $slug);
}

function evk_wl_sanitize_order(array $raw): array {
    $clean = [];
    foreach ($raw as $node_id => $order) {
        $node_id = sanitize_key((string) $node_id);
        $order   = max(0, min(99, (int) $order));
        if ($node_id !== '' && $order > 0) $clean[$node_id] = $order;
    }
    return $clean;
}

function evk_wl_sanitize_href(string $href): string {
    $href = trim($href);
    if ($href === '' || $href === '#') return '#';
    // Relative URL (zaczyna się od /) — sanitize bez wymuszania protokołu
    if (str_starts_with($href, '/')) return sanitize_text_field($href);
    // Absolute URL
    $clean = esc_url_raw($href);
    return $clean !== '' ? $clean : '#';
}

function evk_hex_to_rgb(string $hex): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    if (strlen($hex) !== 6) return '37, 99, 235';
    return hexdec(substr($hex,0,2)).', '.hexdec(substr($hex,2,2)).', '.hexdec(substr($hex,4,2));
}

// =========================================================================
// REJESTRACJA USTAWIEŃ
// =========================================================================

add_action('admin_init', function () {
    register_setting('evk_white_label_settings', 'evk_white_label', [
        'sanitize_callback' => function ($input) {
            $current = evk_wl_get();
            $input   = is_array($input) ? $input : [];

            $resets = [];
            if (!empty($input['_resets'])) {
                $decoded = json_decode(wp_unslash($input['_resets']), true);
                if (is_array($decoded)) $resets = $decoded;
            }

            $color = function (string $key, string $fallback = '') use ($input, $resets, $current): string {
                if (in_array($key, $resets, true)) return '';
                if (array_key_exists($key, $input)) {
                    $sanitized = sanitize_hex_color($input[$key]);
                    // Jeśli sanitize zwróciło '' (np. niepoprawny format) — zostaw poprzednią wartość
                    // zamiast zerować kolor. Fallback tylko gdy nie ma też poprzedniej wartości.
                    if ($sanitized !== '') return $sanitized;
                    return $current[$key] ?: $fallback;
                }
                return $current[$key] ?? $fallback;
            };

            $bar_nodes_hidden = $current['bar_nodes_hidden'];
            if (array_key_exists('bar_nodes_hidden', $input)) {
                $bar_nodes_hidden = [];
                if (is_array($input['bar_nodes_hidden'])) {
                    foreach ($input['bar_nodes_hidden'] as $node) {
                        $node = sanitize_key((string) $node);
                        if ($node !== '') $bar_nodes_hidden[] = $node;
                    }
                }
            }

            $sidebar_hidden = $current['sidebar_hidden'];
            if (array_key_exists('sidebar_hidden', $input)) {
                $sidebar_hidden = [];
                if (is_array($input['sidebar_hidden'])) {
                    foreach ($input['sidebar_hidden'] as $slug) {
                        $slug = evk_wl_sanitize_menu_slug((string) $slug);
                        if ($slug !== '') $sidebar_hidden[] = $slug;
                    }
                }
            }

            $bar_nodes_order = $current['bar_nodes_order'];
            if (array_key_exists('bar_nodes_order', $input) && is_array($input['bar_nodes_order'])) {
                $bar_nodes_order = evk_wl_sanitize_order($input['bar_nodes_order']);
            }

            $bar_nodes_side = $current['bar_nodes_side'];
            if (array_key_exists('bar_nodes_side', $input) && is_array($input['bar_nodes_side'])) {
                $bar_nodes_side = [];
                foreach ($input['bar_nodes_side'] as $node_id => $side) {
                    $node_id = sanitize_key((string) $node_id);
                    $side    = in_array($side, ['left', 'right'], true) ? $side : 'left';
                    if ($node_id !== '') $bar_nodes_side[$node_id] = $side;
                }
            }

            $sidebar_menu_order = $current['sidebar_menu_order'];
            if (array_key_exists('sidebar_menu_order', $input) && is_array($input['sidebar_menu_order'])) {
                $sidebar_menu_order = array_values(array_filter(array_map(
                    'evk_wl_sanitize_menu_slug',
                    $input['sidebar_menu_order']
                )));
            } elseif (!empty($input['sidebar_menu_order_json'])) {
                $decoded = json_decode(wp_unslash($input['sidebar_menu_order_json']), true);
                if (is_array($decoded)) {
                    $sidebar_menu_order = array_values(array_filter(array_map(
                        'evk_wl_sanitize_menu_slug',
                        $decoded
                    )));
                }
            }

            // Bar items — zapisywane przez ten sam submit (JSON z hidden inputa)
            if (!empty($input['bar_items_json'])) {
                $raw_items = json_decode(wp_unslash($input['bar_items_json']), true);
                if (is_array($raw_items)) {
                    $clean_items = [];
                    foreach ($raw_items as $item) {
                        $title = trim((string) ($item['title'] ?? ''));
                        if ($title === '') continue;
                        $clean_items[] = [
                            'id'     => sanitize_key($item['id'] ?? '') ?: 'evk-' . substr(md5(uniqid('', true)), 0, 8),
                            'title'  => sanitize_text_field($title),
                            'href'   => !empty($item['href']) ? evk_wl_sanitize_href($item['href']) : '',
                            'icon'   => sanitize_html_class($item['icon'] ?? ''),
                            'parent' => sanitize_key($item['parent'] ?? ''),
                            'target' => (($item['target'] ?? '') === '_blank') ? '_blank' : '',
                            'type'   => (($item['type'] ?? '') === 'parent') ? 'parent' : 'item',
                        ];
                    }
                    update_option('evk_wl_bar_items', wp_json_encode($clean_items));
                }
            }

            $output = [
                'enabled'                => evk_preserve_toggle($input, 'evk_white_label'),
                'logo_url'               => array_key_exists('logo_url', $input)           ? esc_url_raw($input['logo_url'])                          : $current['logo_url'],
                'logo_width'             => array_key_exists('logo_width', $input)         ? max(40, min(400, absint($input['logo_width'])))           : $current['logo_width'],
                'logo_height'            => array_key_exists('logo_height', $input)        ? max(20, min(200, absint($input['logo_height'])))          : $current['logo_height'],
                'site_name'              => array_key_exists('site_name', $input)          ? sanitize_text_field($input['site_name'])                  : $current['site_name'],
                'footer_text'            => array_key_exists('footer_text', $input)        ? wp_kses_post($input['footer_text'])                       : $current['footer_text'],
                'footer_logo_url'        => array_key_exists('footer_logo_url', $input)    ? esc_url_raw($input['footer_logo_url'])                    : $current['footer_logo_url'],
                'footer_logo_width'      => array_key_exists('footer_logo_width', $input)  ? max(16, min(300, absint($input['footer_logo_width'])))     : $current['footer_logo_width'],
                'footer_logo_height'     => array_key_exists('footer_logo_height', $input) ? max(16, min(200, absint($input['footer_logo_height'])))    : $current['footer_logo_height'],
                'color_primary'          => $color('color_primary', '#2563eb'),
                'color_menu_bg'          => $color('color_menu_bg'),
                'color_menu_text'        => $color('color_menu_text'),
                'color_menu_icon'        => $color('color_menu_icon'),
                'color_menu_hover'       => $color('color_menu_hover'),
                'color_menu_hover_text'  => $color('color_menu_hover_text'),
                'color_menu_active'      => $color('color_menu_active'),
                'color_menu_active_text' => $color('color_menu_active_text'),
                'color_menu_badge'       => $color('color_menu_badge'),
                'color_menu_badge_text'  => $color('color_menu_badge_text'),
                'hide_admin_bar_logo'    => !empty($input['hide_admin_bar_logo']) ? 1 : 0,
                'admin_bar_title'        => array_key_exists('admin_bar_title', $input)    ? sanitize_text_field($input['admin_bar_title'])            : $current['admin_bar_title'],
                'admin_bar_color'        => $color('admin_bar_color'),
                'admin_bar_hover_color'  => $color('admin_bar_hover_color'),
                'admin_bar_sub_color'    => $color('admin_bar_sub_color'),
                'hide_wp_logo'           => !empty($input['hide_wp_logo']) ? 1 : 0,
                'hide_help_tab'          => !empty($input['hide_help_tab']) ? 1 : 0,
                'hide_screen_opts'       => !empty($input['hide_screen_opts']) ? 1 : 0,
                'hide_footer_wp'         => !empty($input['hide_footer_wp']) ? 1 : 0,
                'color_body_bg'          => $color('color_body_bg'),
                'color_content_bg'       => $color('color_content_bg'),
                'color_content_text'     => $color('color_content_text'),
                'color_link'             => $color('color_link'),
                'color_notice_bg'        => $color('color_notice_bg'),
                'admin_font_family'      => array_key_exists('admin_font_family', $input)   ? sanitize_text_field($input['admin_font_family'])            : $current['admin_font_family'],
                'color_admin_bar_link'   => $color('color_admin_bar_link'),
                'color_submenu_current_bg' => $color('color_submenu_current_bg'),
                'color_submenu_current_tx' => $color('color_submenu_current_tx'),
                'custom_css_admin'       => array_key_exists('custom_css_admin', $input)   ? evk_wl_bezpieczny_css(wp_unslash((string) $input['custom_css_admin'])) : $current['custom_css_admin'],
                'bar_nodes_extra'        => (function() use ($input, $current) {
                    if (!array_key_exists('bar_nodes_extra', $input)) return $current['bar_nodes_extra'];
                    $extra = [];
                    if (is_array($input['bar_nodes_extra'])) {
                        foreach ($input['bar_nodes_extra'] as $nid => $lbl) {
                            $nid = sanitize_key((string) $nid);
                            $lbl = sanitize_text_field((string) $lbl);
                            if ($nid !== '' && $lbl !== '') $extra[$nid] = $lbl;
                        }
                    }
                    return $extra;
                })(),
                'sidebar_labels'         => (function() use ($input, $current) {
                    if (!array_key_exists('sidebar_labels', $input)) return $current['sidebar_labels'];
                    $labels = [];
                    if (is_array($input['sidebar_labels'])) {
                        foreach ($input['sidebar_labels'] as $slug => $lbl) {
                            $slug = evk_wl_sanitize_menu_slug((string) $slug);
                            $lbl  = sanitize_text_field((string) $lbl);
                            if ($slug !== '') $labels[$slug] = $lbl;
                        }
                    }
                    return $labels;
                })(),
                'bar_nodes_hidden'       => array_values(array_unique($bar_nodes_hidden)),
                'sidebar_hidden'         => array_values(array_unique($sidebar_hidden)),
                'bar_nodes_order'        => $bar_nodes_order,
                'bar_nodes_side'         => $bar_nodes_side,
                'sidebar_menu_order'     => $sidebar_menu_order,
            ];

            return evk_wl_normalize($output);
        },
    ]);
});

// =========================================================================
// AJAX
// =========================================================================

add_action('wp_ajax_evk_wl_get_sidebar_menu', function () {
    check_ajax_referer('evoke-one-wl-bar', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);

    global $menu;
    $items = [];
    foreach ((array) $menu as $pos => $item) {
        $slug   = $item[2] ?? '';
        $raw    = $item[0] ?? '';
        $class  = $item[4] ?? '';
        $title  = wp_strip_all_tags((string) $raw);
        $title  = preg_replace('/<span.*<\/span>/U', '', (string) $title);
        $title  = trim(strip_tags((string) $title));
        $is_sep = (strpos((string) $slug, 'separator') === 0 || $class === 'wp-menu-separator');
        $items[] = [
            'slug'  => $slug ?: 'separator-' . (int) $pos,
            'label' => $is_sep ? '— separator —' : ($title ?: $slug),
            'pos'   => (int) $pos,
            'sep'   => $is_sep,
        ];
    }
    $wl = evk_wl_get();
    wp_send_json_success(['items' => $items, 'saved_order' => $wl['sidebar_menu_order'] ?? []]);
});

add_action('wp_ajax_evk_wl_save_menu_order', function () {
    check_ajax_referer('evoke-one-wl-bar', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);

    $raw   = wp_unslash($_POST['order'] ?? '[]');
    $order = json_decode($raw, true);
    if (!is_array($order)) wp_send_json_error('invalid_json', 400);

    $clean  = array_values(array_filter(array_map('evk_wl_sanitize_menu_slug', $order)));
    $stored = get_option('evk_white_label', []);
    $stored = is_array($stored) ? $stored : evk_wl_defaults();
    $stored = evk_wl_normalize($stored);
    $stored['sidebar_menu_order'] = $clean;
    update_option('evk_white_label', $stored);

    wp_send_json_success(['count' => count($clean)]);
});

add_action('wp_ajax_evk_wl_save_bar_items', function () {
    check_ajax_referer('evoke-one-wl-bar', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);

    $raw   = wp_unslash($_POST['items'] ?? '[]');
    $items = json_decode($raw, true);
    if (!is_array($items)) wp_send_json_error('invalid_json', 400);

    $clean = [];
    foreach ($items as $item) {
        $title = trim((string) ($item['title'] ?? ''));
        if ($title === '') continue;
        $clean[] = [
            'id'     => sanitize_key($item['id'] ?? '') ?: 'evk-' . substr(md5(uniqid('', true)), 0, 8),
            'title'  => sanitize_text_field($title),
            'href'   => !empty($item['href']) ? evk_wl_sanitize_href($item['href']) : '',
            'icon'   => sanitize_html_class($item['icon'] ?? ''),
            'parent' => sanitize_key($item['parent'] ?? ''),
            'target' => (($item['target'] ?? '') === '_blank') ? '_blank' : '',
            'type'   => (($item['type'] ?? '') === 'parent') ? 'parent' : 'item',
        ];
    }
    update_option('evk_wl_bar_items', wp_json_encode($clean));
    wp_send_json_success(['count' => count($clean)]);
});

// =========================================================================
// APLIKOWANIE WHITE LABEL
// =========================================================================

add_action('admin_init', function () {
    $wl = evk_wl_get();
    if (empty($wl['enabled'])) return;

    if (!empty($wl['hide_wp_logo'])) {
        add_action('wp_before_admin_bar_render', function () {
            global $wp_admin_bar;
            if ($wp_admin_bar instanceof WP_Admin_Bar) $wp_admin_bar->remove_node('wp-logo');
        }, 100);
    }

    if (!empty($wl['hide_help_tab'])) {
        add_filter('contextual_help', '__return_empty_string', 999);
        add_action('admin_head', function () {
            echo '<style>#contextual-help-link-wrap{display:none!important;}</style>';
        });
    }

    if (!empty($wl['hide_screen_opts'])) {
        add_action('admin_head', function () {
            echo '<style>#screen-options-link-wrap{display:none!important;}</style>';
        });
    }

    if (!empty($wl['logo_url'])) {
        add_action('login_head', function () use ($wl) {
            $url   = esc_url($wl['logo_url']);
            $width = (int) $wl['logo_width'];
            echo "<style>.login h1 a{background-image:url('{$url}')!important;background-size:contain!important;background-position:center center!important;background-repeat:no-repeat!important;width:{$width}px!important;height:80px!important;}</style>";
        });
        add_filter('login_headerurl',  fn() => home_url());
        add_filter('login_headertext', fn() => get_bloginfo('name'));
    }
});

// -------------------------------------------------------------------------
// Admin bar: tytuł witryny
// -------------------------------------------------------------------------
add_action('admin_bar_menu', function (WP_Admin_Bar $bar) {
    $wl = evk_wl_get();
    if (empty($wl['enabled']) || empty($wl['admin_bar_title'])) return;
    $node = $bar->get_node('site-name');
    if (!$node) return;
    $bar->add_node(['id' => 'site-name', 'title' => esc_html($wl['admin_bar_title']), 'href' => $node->href, 'meta' => $node->meta]);
}, 9999);

// -------------------------------------------------------------------------
// Admin bar: ukrywanie węzłów + własne pozycje
// -------------------------------------------------------------------------
add_action('wp_before_admin_bar_render', function () {
    $wl = evk_wl_get();
    if (empty($wl['enabled'])) return;
    global $wp_admin_bar;
    if (!($wp_admin_bar instanceof WP_Admin_Bar)) return;

    foreach ((array) $wl['bar_nodes_hidden'] as $node_id) {
        $wp_admin_bar->remove_node($node_id);
    }

    foreach (evk_wl_bar_items_get() as $item) {
        $title = esc_html($item['title'] ?? '');
        if (!empty($item['icon'])) {
            $title = '<span class="ab-icon dashicons '.esc_attr($item['icon']).'" style="top:2px;"></span>'.$title;
        }
        $args = [
            'id'     => $item['id'] ?? ('evk-'.wp_generate_uuid4()),
            'title'  => $title,
            'href'   => !empty($item['href']) ? esc_url($item['href']) : false,
            'parent' => !empty($item['parent']) ? sanitize_key($item['parent']) : false,
            'meta'   => [],
        ];
        if (!empty($item['target']) && $item['target'] === '_blank') {
            $args['meta']['target'] = '_blank';
            $args['meta']['rel']    = 'noopener noreferrer';
        }
        if (($item['type'] ?? '') === 'parent') $args['href'] = false;
        $wp_admin_bar->add_node($args);
    }
}, 999);

// -------------------------------------------------------------------------
// Admin bar: przenoszenie węzłów między strefami (lewa ↔ prawa)
// -------------------------------------------------------------------------
add_action('wp_before_admin_bar_render', function () {
    $wl = evk_wl_get();
    if (empty($wl['enabled'])) return;
    $bar_nodes_side = $wl['bar_nodes_side'] ?? [];
    if (empty($bar_nodes_side)) return;
    global $wp_admin_bar;
    if (!($wp_admin_bar instanceof WP_Admin_Bar)) return;

    // Węzły prawej strefy WP (top-secondary) — domyślnie
    $right_defaults = ['my-account','user-actions','search','customize','edit','appearance','recovery-mode','logout'];

    foreach ($bar_nodes_side as $node_id => $side) {
        $node = $wp_admin_bar->get_node($node_id);
        if (!$node) continue;

        $is_right_default = in_array($node_id, $right_defaults, true);
        $want_right = ($side === 'right');

        // Nie zmieniaj jeśli węzeł jest już we właściwej strefie
        if ($want_right && $is_right_default) continue;
        if (!$want_right && !$is_right_default) continue;

        // Pobierz dane węzła, zmień parent i dodaj ponownie
        $args = [
            'id'     => $node->id,
            'title'  => $node->title,
            'href'   => $node->href,
            'parent' => $want_right ? 'top-secondary' : 'root-default',
            'meta'   => (array) $node->meta,
            'group'  => $node->group,
        ];
        $wp_admin_bar->remove_node($node_id);
        $wp_admin_bar->add_node($args);
    }
}, 1000);

// -------------------------------------------------------------------------
// Menu boczne: ukrywanie dla non-adminów
// -------------------------------------------------------------------------
// Zmiana nazw pozycji menu bocznego
add_action('admin_menu', function () {
    global $menu;
    $wl     = evk_wl_get();
    $labels = $wl['sidebar_labels'] ?? [];
    if (empty($wl['enabled']) || empty($labels) || !is_array($menu)) return;
    foreach ($menu as &$item) {
        $slug = $item[2] ?? '';
        if (isset($labels[$slug]) && $labels[$slug] !== '') {
            $item[0] = esc_html($labels[$slug]);
        }
    }
    unset($item);
}, 998);

add_action('admin_menu', function () {
    $wl = evk_wl_get();
    if (empty($wl['enabled']) || current_user_can('administrator')) return;
    foreach ((array) $wl['sidebar_hidden'] as $slug) {
        remove_menu_page($slug);
    }
}, 999);

// -------------------------------------------------------------------------
// Kolejność menu bocznego + separatory
// -------------------------------------------------------------------------
add_filter('custom_menu_order', function ($enabled) {
    $wl = evk_wl_get();
    return (!empty($wl['enabled']) && !empty($wl['sidebar_menu_order'])) ? true : $enabled;
}, 999);

add_filter('menu_order', function (array $menu_order) {
    $wl = evk_wl_get();
    if (empty($wl['enabled']) || empty($wl['sidebar_menu_order'])) return $menu_order;
    $saved    = array_values(array_unique(array_filter($wl['sidebar_menu_order'])));
    $existing = $saved;
    foreach ($menu_order as $slug) {
        if (!in_array($slug, $existing, true)) $existing[] = $slug;
    }
    return $existing;
}, 999);

// Przebuduj $menu bezpośrednio — jedyna pewna metoda dla custom separatorów
add_action('admin_menu', function () {
    global $menu;
    $wl    = evk_wl_get();
    $saved = $wl['sidebar_menu_order'] ?? [];
    if (empty($wl['enabled']) || empty($saved) || !is_array($menu)) return;

    // Wstrzyknij brakujące custom separatory
    $tmp_pos = 9000;
    foreach ($saved as $slug) {
        if (strpos($slug, 'separator-custom-') !== 0) continue;
        $exists = false;
        foreach ($menu as $item) {
            if (($item[2] ?? '') === $slug) { $exists = true; break; }
        }
        if (!$exists) $menu[$tmp_pos++] = ['', 'read', $slug, '', 'wp-menu-separator', '', ''];
    }

    // Zbuduj mapę slug → item
    $map = [];
    foreach ($menu as $pos => $item) {
        $slug = $item[2] ?? '';
        if ($slug !== '') $map[$slug] = [$pos, $item];
    }

    // Przebuduj wg saved
    $new_menu = [];
    $new_pos  = 1;
    foreach ($saved as $slug) {
        if (isset($map[$slug])) {
            $new_menu[$new_pos] = $map[$slug][1];
            unset($map[$slug]);
            $new_pos += 2;
        }
    }
    foreach ($map as [$old_pos, $item]) {
        $new_menu[$new_pos] = $item;
        $new_pos += 2;
    }
    $menu = $new_menu;
}, 9999);

// -------------------------------------------------------------------------
// Footer admina
// -------------------------------------------------------------------------
add_filter('admin_footer_text', function ($text) {
    $wl = evk_wl_get();
    if (empty($wl['enabled'])) return $text;
    if (!empty($wl['hide_footer_wp'])) {
        $out = '';
        if (!empty($wl['footer_logo_url'])) {
            $w   = (int) ($wl['footer_logo_width']  ?: 32);
            $h   = (int) ($wl['footer_logo_height'] ?: 32);
            $out .= '<img src="' . esc_url($wl['footer_logo_url']) . '" width="' . $w . '" height="' . $h . '" style="vertical-align:middle;margin-right:6px;object-fit:contain;" alt="">';
        }
        if (!empty($wl['footer_text'])) {
            $out .= wp_kses_post($wl['footer_text']);
        }
        return $out ?: '';
    }
    return $text;
});

add_filter('update_footer', function ($text) {
    $wl = evk_wl_get();
    return (empty($wl['enabled']) || empty($wl['hide_footer_wp'])) ? $text : '';
}, 11);

// -------------------------------------------------------------------------
// Czcionka admina — @font-face wstrzykiwany jako PIERWSZA rzecz w <head>
// Priorytet -10 → przed wszystkimi innymi admin_head hookami → brak FOUT
// -------------------------------------------------------------------------

function evk_wl_get_bricks_font_face(string $family): string {
    // Bricks przechowuje custom fonts w opcji bricks_custom_fonts (tablica)
    // Każdy wpis: ['name' => 'NazwaRodziny', 'files' => [['format'=>'woff2','file'=>URL], ...]]
    $bricks_fonts = get_option('bricks_custom_fonts', []);
    if (!is_array($bricks_fonts)) return '';

    $family_lower = strtolower(trim($family));
    foreach ($bricks_fonts as $font) {
        $fname = strtolower(trim($font['name'] ?? ''));
        if ($fname !== $family_lower) continue;

        $files = $font['files'] ?? [];
        if (empty($files)) continue;

        // Zbuduj src dla @font-face
        $srcs = [];
        foreach ($files as $f) {
            $url    = esc_url($f['file'] ?? '');
            $format = sanitize_text_field($f['format'] ?? 'woff2');
            if ($url) $srcs[] = "url('{$url}') format('{$format}')";
        }
        if (empty($srcs)) continue;

        $src_str = implode(',', $srcs);
        $weight  = sanitize_text_field($font['weight'] ?? 'normal');
        $style   = sanitize_text_field($font['style']  ?? 'normal');

        return "@font-face{font-family:'{$family}';src:{$src_str};font-weight:{$weight};font-style:{$style};font-display:block;}";
    }
    return '';
}

// Czcionka admina — preload + @font-face + font-family
// Preload rejestrowany przez wp_enqueue_style z atrybutem rel=preload —
// WP umieszcza go w <head> przed innymi stylami przez kolejność enqueue
add_action('admin_enqueue_scripts', function () {
    $wl = evk_wl_get();
    if (empty($wl['enabled']) || empty($wl['admin_font_family'])) return;

    $ff           = sanitize_text_field($wl['admin_font_family']);
    $bricks_fonts = get_option('bricks_custom_fonts', []);
    if (!is_array($bricks_fonts)) return;

    foreach ($bricks_fonts as $font) {
        if (strtolower(trim($font['name'] ?? '')) !== strtolower($ff)) continue;
        foreach ((array)($font['files'] ?? []) as $file) {
            $url    = $file['file'] ?? '';
            $format = sanitize_text_field($file['format'] ?? 'woff2');
            if (!$url || $format !== 'woff2') continue;

            // Zarejestruj preload jako osobny "styl" z atrybutem rel=preload
            // To jest standardowy WP sposób na preload zasobów
            wp_enqueue_style('evk-wl-font-preload', esc_url($url), [], null);
            add_filter('style_loader_tag', function ($tag, $handle) use ($url) {
                if ($handle !== 'evk-wl-font-preload') return $tag;
                // Zamień <link rel="stylesheet"> na <link rel="preload">
                return '<link rel="preload" href="' . esc_url($url) . '" as="font" type="font/woff2" crossorigin="anonymous">' . "
";
            }, 10, 2);
            break 2;
        }
    }
});

// @font-face + font-family — base64 inline eliminuje FOUT całkowicie
// (brak requestu sieciowego = czcionka dostępna natychmiast)
add_action('admin_head', function () {
    $wl = evk_wl_get();
    if (empty($wl['enabled']) || empty($wl['admin_font_family'])) return;

    $ff     = sanitize_text_field($wl['admin_font_family']);
    $ff_esc = esc_attr($ff);

    // Spróbuj zbudować @font-face z base64 (cache w transiencie 7 dni)
    $transient_key = 'evk_wl_font_b64_' . md5($ff);
    $font_face     = get_transient($transient_key);

    if ($font_face === false) {
        $font_face = ''; // domyślnie pusty
        $bricks_fonts = get_option('bricks_custom_fonts', []);
        if (is_array($bricks_fonts)) {
            foreach ($bricks_fonts as $font) {
                if (strtolower(trim($font['name'] ?? '')) !== strtolower($ff)) continue;
                foreach ((array)($font['files'] ?? []) as $file) {
                    $url    = $file['file'] ?? '';
                    $format = sanitize_text_field($file['format'] ?? 'woff2');
                    if (!$url || $format !== 'woff2') continue;

                    // Pobierz plik lokalnie (przez ścieżkę lub HTTP)
                    $local_path = str_replace(
                        [site_url('/'), home_url('/')],
                        [ABSPATH, ABSPATH],
                        $url
                    );
                    $data = false;
                    if (file_exists($local_path)) {
                        $data = file_get_contents($local_path);
                    } else {
                        $response = wp_remote_get($url, ['timeout' => 5]);
                        if (!is_wp_error($response)) {
                            $data = wp_remote_retrieve_body($response);
                        }
                    }

                    if ($data) {
                        $b64       = base64_encode($data);
                        $weight    = sanitize_text_field($font['weight'] ?? 'normal');
                        $style     = sanitize_text_field($font['style']  ?? 'normal');
                        $font_face = "@font-face{font-family:'{$ff_esc}';src:url('data:font/woff2;base64,{$b64}') format('woff2');font-weight:{$weight};font-style:{$style};font-display:block;}";
                    }
                    break 2;
                }
            }
        }
        // Zapisz w transiencie (nawet pusty string — żeby nie próbować ponownie przy każdym req)
        set_transient($transient_key, $font_face, 7 * DAY_IN_SECONDS);
    }

    // Fallback do normalnego @font-face gdy base64 niedostępne
    if (!$font_face) {
        $font_face = evk_wl_get_bricks_font_face($ff);
    }

    echo '<style id="evk-wl-font">';
    if ($font_face) echo $font_face;
    // Uwaga: nie używamy #wpadminbar * — nadpisałoby font-family dashicons (ikony → kwadraciki).
    // Targetujemy tylko elementy tekstowe paska, z jawnym wykluczeniem klas dashicons.
    $bar_text = "#wpadminbar .ab-item,"
              . "#wpadminbar a.ab-item,"
              . "#wpadminbar .ab-label,"
              . "#wpadminbar #wp-admin-bar-site-name>a,"
              . "#wpadminbar .display-name,"
              . "#wpadminbar .menupop .ab-item";
    echo "body,body.wp-admin,#wpcontent,#adminmenu{font-family:'{$ff_esc}',sans-serif!important;}";
    echo "#wpadminbar{{font-family:'{$ff_esc}',sans-serif!important;}}";
    echo "{$bar_text}{font-family:'{$ff_esc}',sans-serif!important;}";
    echo '</style>';
}, 1);

// Wyczyść transient gdy zmieni się czcionka
add_action('update_option_evk_white_label', function ($old, $new) {
    if (($old['admin_font_family'] ?? '') !== ($new['admin_font_family'] ?? '')) {
        delete_transient('evk_wl_font_b64_' . md5(sanitize_text_field($old['admin_font_family'] ?? '')));
        delete_transient('evk_wl_font_b64_' . md5(sanitize_text_field($new['admin_font_family'] ?? '')));
    }
}, 10, 2);


// -------------------------------------------------------------------------
// Podmiana nazwy "WordPress" → site_name (bez gettext — przez CSS + title)
// -------------------------------------------------------------------------
add_action('admin_head', function () {
    $wl = evk_wl_get();
    if (empty($wl['enabled']) || empty($wl['site_name'])) return;
    $name = esc_js($wl['site_name']);
    // Podmiana przez JS tylko raz przy DOMContentLoaded — nie spowalnia jak gettext
    echo "<script>document.addEventListener('DOMContentLoaded',function(){";
    echo "document.querySelectorAll('#footer-thankyou,#wp-admin-bar-wp-logo .ab-label').forEach(function(el){";
    echo "el.innerHTML=el.innerHTML.replace(/WordPress/g,'" . $name . "');});";
    echo "});</script>";
});

// -------------------------------------------------------------------------
// Custom CSS + kolory (admin_head)
// -------------------------------------------------------------------------
add_action('admin_head', function () {
    $wl = evk_wl_get();
    if (empty($wl['enabled'])) return;

    $css = '#adminmenu,#adminmenu *{transition:none!important;animation:none!important;}';

    if (!empty($wl['logo_url'])) {
        $url   = esc_url($wl['logo_url']);
        $width = (int) $wl['logo_width'];
        $height = (int) $wl['logo_height'];
        $css .= "#adminmenu::before{content:'';display:block;width:{$width}px;height:{$height}px;max-width:calc(100% - 16px);margin:12px auto 8px;background:url('{$url}') center/contain no-repeat;}";
    }

    if (!empty($wl['color_primary'])) {
        $c   = esc_attr($wl['color_primary']);
        $rgb = evk_hex_to_rgb($wl['color_primary']);
        $css .= ":root{--wp-admin-theme-color:{$c}!important;--wp-admin-theme-color--rgb:{$rgb}!important;--wp-admin-theme-color-darker-10:color-mix(in srgb,{$c} 90%,#000 10%)!important;--wp-admin-theme-color-darker-20:color-mix(in srgb,{$c} 80%,#000 20%)!important;}";
        $css .= ".wp-core-ui .button-primary{background:{$c}!important;border-color:{$c}!important;box-shadow:none!important;}";
        $css .= ".wp-core-ui .button-primary:hover,.wp-core-ui .button-primary:focus{background:color-mix(in srgb,{$c} 85%,#000)!important;border-color:color-mix(in srgb,{$c} 85%,#000)!important;}";
        $css .= "#wpcontent a:not(.button):not(.wp-block-button__link){color:{$c};}";
        $css .= "input[type=checkbox]:checked,input[type=radio]:checked{border-color:{$c}!important;background:{$c}!important;}";
    }

    if (!empty($wl['color_menu_bg'])) {
        $bg     = esc_attr($wl['color_menu_bg']);
        $sub_bg = "color-mix(in srgb,{$bg} 75%,#000 25%)";
        $css   .= "#adminmenu,#adminmenuback,#adminmenuwrap{background:{$bg}!important;}";
        $css   .= "#adminmenu .wp-submenu,#adminmenu .wp-submenu-wrap{background:{$sub_bg}!important;}";
        $css   .= "#adminmenu li.wp-has-current-submenu .wp-submenu,#adminmenu li:hover .wp-submenu{background:{$sub_bg}!important;}";
    }

    if (!empty($wl['color_menu_text'])) {
        $text  = esc_attr($wl['color_menu_text']);
        $css  .= "#adminmenu a.menu-top,#adminmenu .wp-menu-name{color:{$text}!important;}";
        $css  .= "#adminmenu .wp-submenu a{color:{$text}!important;opacity:.8;}";
    }

    if (!empty($wl['color_menu_icon'])) {
        $css .= "#adminmenu .wp-menu-image:before{color:".esc_attr($wl['color_menu_icon'])."!important;}";
    }

    $hbg_raw = !empty($wl['color_menu_hover']) ? $wl['color_menu_hover'] : (!empty($wl['color_menu_bg']) ? $wl['color_menu_bg'] : '');
    if ($hbg_raw) {
        $hbg_set   = esc_attr($hbg_raw);
        $htxt      = !empty($wl['color_menu_hover_text']) ? esc_attr($wl['color_menu_hover_text']) : '#ffffff';
        $hbg_final = !empty($wl['color_menu_hover']) ? $hbg_set : "color-mix(in srgb,{$hbg_set} 78%,#000 22%)";
        $css .= "#adminmenu a:hover,#adminmenu li.menu-top:hover,#adminmenu li.opensub>a.menu-top,#adminmenu li>a.menu-top:focus{background:{$hbg_final}!important;color:{$htxt}!important;}";
        $css .= "#adminmenu li.menu-top:hover .wp-menu-name,#adminmenu li.opensub>a.menu-top .wp-menu-name{color:{$htxt}!important;}";
        $css .= "#adminmenu li:hover .wp-menu-image:before,#adminmenu li.opensub .wp-menu-image:before{color:{$htxt}!important;opacity:1;}";
        $css .= "#adminmenu .wp-submenu a:hover{color:{$htxt}!important;opacity:1;}";
    }

    if (!empty($wl['color_menu_active'])) {
        $abg  = esc_attr($wl['color_menu_active']);
        $atxt = !empty($wl['color_menu_active_text']) ? esc_attr($wl['color_menu_active_text']) : '#ffffff';
        $css .= "#adminmenu .wp-has-current-submenu>a.wp-has-current-submenu,#adminmenu .wp-has-current-submenu>a.wp-has-current-submenu:focus,#adminmenu .wp-has-current-submenu>a.wp-has-current-submenu:hover,.folded #adminmenu .wp-has-current-submenu>a.wp-has-current-submenu,#adminmenu a.current,#adminmenu a.current:focus,#adminmenu li.current>a.menu-top{background:{$abg}!important;color:{$atxt}!important;}";
        $css .= "#adminmenu .wp-has-current-submenu .wp-menu-image:before,#adminmenu li.current .wp-menu-image:before{color:{$atxt}!important;opacity:1!important;}";
        $css .= "#adminmenu .wp-has-current-submenu .wp-menu-name,#adminmenu li.current .wp-menu-name{color:{$atxt}!important;}";
        $css .= "#adminmenu .wp-submenu li.current>a,#adminmenu .wp-submenu li.current>a:hover{color:{$atxt}!important;font-weight:600;}";
    }

    $badge_bg = !empty($wl['color_menu_badge']) ? esc_attr($wl['color_menu_badge']) : (!empty($wl['color_primary']) ? esc_attr($wl['color_primary']) : '');
    $badge_tx = !empty($wl['color_menu_badge_text']) ? esc_attr($wl['color_menu_badge_text']) : '#ffffff';
    if ($badge_bg) {
        $css .= "#adminmenu .awaiting-mod,#adminmenu .menu-counter,#adminmenu .update-plugins,#adminmenu .update-themes,#adminmenu .update-count,#adminmenu .plugin-count,#adminmenu .theme-count,#adminmenu .pending-count,#adminmenu .wp-ui-notification,.wp-ui-notification{background:{$badge_bg}!important;color:{$badge_tx}!important;}";
    }

    if (!empty($wl['admin_bar_color'])) {
        $bar = esc_attr($wl['admin_bar_color']);
        $hbg = !empty($wl['admin_bar_hover_color']) ? esc_attr($wl['admin_bar_hover_color']) : "color-mix(in srgb,{$bar} 80%,#000 20%)";
        $sbg = !empty($wl['admin_bar_sub_color'])   ? esc_attr($wl['admin_bar_sub_color'])   : "color-mix(in srgb,{$bar} 85%,#000 15%)";
        $css .= "#wpadminbar{background:{$bar}!important;}";
        $css .= "#wpadminbar .ab-item,#wpadminbar a.ab-item,#wpadminbar .ab-icon{color:#eee!important;}";
        $css .= "#wpadminbar .ab-top-menu>li:hover>.ab-item,#wpadminbar .ab-top-menu>li.hover>.ab-item{background:{$hbg}!important;color:#fff!important;}";
        $css .= "#wpadminbar .menupop .ab-sub-wrapper{background:{$sbg}!important;}";
        $css .= "#wpadminbar .ab-submenu .ab-item{color:#ddd!important;}";
        $css .= "#wpadminbar .ab-submenu .ab-item:hover{background:color-mix(in srgb,{$sbg} 80%,#000 20%)!important;color:#fff!important;}";
    }

    if (!empty($wl['color_body_bg'])) {
        $bg = esc_attr($wl['color_body_bg']);
        $css .= "body.wp-admin{background:{$bg}!important;}";
    }

    if (!empty($wl['color_content_bg'])) {
        $bg   = esc_attr($wl['color_content_bg']);
        $css .= "#wpwrap,#wpcontent,#wpbody,#wpbody-content{background:{$bg}!important;}";
        // Strzałki submenu (pseudo-elementy ::after) muszą pasować do tła treści
        $css .= "ul#adminmenu a.wp-has-current-submenu:after,ul#adminmenu>li.current>a.current:after{border-right-color:{$bg}!important;}";
        $css .= "#adminmenu li.wp-has-submenu.wp-not-current-submenu.opensub:hover:after,#adminmenu li.wp-has-submenu.wp-not-current-submenu:focus-within:after{border-right-color:{$bg}!important;}";
    }

    if (!empty($wl['color_content_text'])) {
        $fg   = esc_attr($wl['color_content_text']);
        $css .= "#wpcontent,#wpbody{color:{$fg}!important;}";
        $css .= "#wpcontent h1,#wpcontent h2,#wpcontent h3,#wpcontent h4{color:{$fg}!important;}";
    }

    if (!empty($wl['color_link'])) {
        $lc   = esc_attr($wl['color_link']);
        $css .= "#wpcontent a:not(.button){color:{$lc}!important;}";
        $css .= "#wpcontent a:not(.button):hover{opacity:.8;}";
        $css .= "#wpadminbar #wp-admin-bar-site-name a,#wpadminbar #wp-admin-bar-site-name a.ab-item{color:#eee!important;}";
    }

    if (!empty($wl['color_notice_bg'])) {
        $nb   = esc_attr($wl['color_notice_bg']);
        $css .= ".notice,.notice-success,.notice-error,.notice-warning,.notice-info{background:{$nb}!important;border-color:rgba(0,0,0,.1)!important;}";
    }

    if (!empty($wl['color_admin_bar_link'])) {
        $abl = esc_attr($wl['color_admin_bar_link']);
        $css .= "#wpadminbar .quicklinks .ab-sub-wrapper .menupop.hover>a,"
              . "#wpadminbar .quicklinks .menupop ul li a:focus,"
              . "#wpadminbar .quicklinks .menupop ul li a:focus strong,"
              . "#wpadminbar .quicklinks .menupop ul li a:hover,"
              . "#wpadminbar .quicklinks .menupop ul li a:hover strong,"
              . "#wpadminbar .quicklinks .menupop.hover ul li a:focus,"
              . "#wpadminbar .quicklinks .menupop.hover ul li a:hover,"
              . "#wpadminbar li #adminbarsearch.adminbar-focused:before,"
              . "#wpadminbar li .ab-item:focus .ab-icon:before,"
              . "#wpadminbar li .ab-item:focus:before,"
              . "#wpadminbar li a:focus .ab-icon:before,"
              . "#wpadminbar li.hover .ab-icon:before,"
              . "#wpadminbar li.hover .ab-item:before,"
              . "#wpadminbar li:hover #adminbarsearch:before,"
              . "#wpadminbar li:hover .ab-icon:before,"
              . "#wpadminbar li:hover .ab-item:before,"
              . "#wpadminbar.nojs .quicklinks .menupop:hover ul li a:focus,"
              . "#wpadminbar.nojs .quicklinks .menupop:hover ul li a:hover"
              . "{color:{$abl}!important;}";
        $css .= "#wpadminbar:not(.mobile)>#wp-toolbar a:focus span.ab-label,"
              . "#wpadminbar:not(.mobile)>#wp-toolbar li.hover span.ab-label,"
              . "#wpadminbar:not(.mobile)>#wp-toolbar li:hover span.ab-label"
              . "{color:{$abl}!important;}";
        $css .= "#wpadminbar .ab-top-menu>li.hover>.ab-item,"
              . "#wpadminbar.nojq .quicklinks .ab-top-menu>li>.ab-item:focus,"
              . "#wpadminbar:not(.mobile) .ab-top-menu>li:hover>.ab-item,"
              . "#wpadminbar:not(.mobile) .ab-top-menu>li>.ab-item:focus"
              . "{color:{$abl}!important;}";
    }

    if (!empty($wl['color_admin_bar_link'])) {
        // Aplikowane też przez wp_head — patrz niżej
    }

    if (!empty($wl['color_submenu_current_bg'])) {
        $scbg = esc_attr($wl['color_submenu_current_bg']);
        $sctx = !empty($wl['color_submenu_current_tx']) ? esc_attr($wl['color_submenu_current_tx']) : '#ffffff';
        $css .= "#adminmenu .opensub .wp-submenu li.current a,"
              . "#adminmenu .wp-submenu li.current,"
              . "#adminmenu .wp-submenu li.current a,"
              . "#adminmenu .wp-submenu li.current a:focus,"
              . "#adminmenu .wp-submenu li.current a:hover,"
              . "#adminmenu a.wp-has-current-submenu:focus+.wp-submenu li.current a"
              . "{background:{$scbg}!important;color:{$sctx}!important;}";
    }

    if (!empty($wl['custom_css_admin'])) {
        // Także przy wypisywaniu, nie tylko przy zapisie: w bazie mogą leżeć
        // wartości sprzed 1.132.0, zapisane wtedy, gdy nikt ich nie sprawdzał.
        $css .= "\n" . evk_wl_bezpieczny_css($wl['custom_css_admin']);
    }

    $bar_order = $wl['bar_nodes_order'] ?? [];
    if (!empty($bar_order)) {
        // top-secondary jest w DOM PO root-default — float:right nie działa gdy poprzedni element jest blokiem/flexem.
        // Rozwiązanie: position:absolute right:0 na top-secondary + padding-right na toolbar żeby left nie zachodził pod right.
        $css .= '#wpadminbar #wp-toolbar{position:relative!important;}';
        $css .= '#wpadminbar #wp-admin-bar-top-secondary{'
              . 'position:absolute!important;'
              . 'right:0!important;'
              . 'top:0!important;'
              . 'height:32px!important;'
              . 'display:flex!important;'
              . 'align-items:stretch!important;'
              . 'flex-wrap:nowrap!important;'
              . 'float:none!important;'
              . '}';
        $css .= '#wpadminbar #wp-admin-bar-top-secondary>li{'
              . 'float:none!important;'
              . 'flex-shrink:0!important;'
              . '}';
        $css .= '#wpadminbar #wp-admin-bar-root-default{'
              . 'display:flex!important;'
              . 'align-items:stretch!important;'
              . 'padding-right:var(--evk-secondary-w,220px)!important;'  // rezerwuje miejsce dla prawej strefy
              . '}';
        $css .= '#wpadminbar #wp-admin-bar-root-default>li{'
              . 'float:none!important;'
              . 'flex-shrink:0!important;'
              . '}';
        foreach ($bar_order as $node_id => $order) {
            $css .= '#wpadminbar #wp-admin-bar-'.esc_attr($node_id).'{order:'.(int)$order.'!important;}';
        }
    }

    if ($css !== '') echo '<style id="evk-white-label">'.$css.'</style>';

    // JS: mierzy szerokość top-secondary i ustawia --evk-secondary-w na #wpadminbar
    if (!empty($bar_order)) {
        echo '<script>
(function(){
    function evkSetSecondaryWidth(){
        var sec = document.getElementById("wp-admin-bar-top-secondary");
        if(!sec) return;
        var w = sec.offsetWidth;
        document.getElementById("wpadminbar").style.setProperty("--evk-secondary-w", w+"px");
    }
    if(document.readyState === "loading"){
        document.addEventListener("DOMContentLoaded", evkSetSecondaryWidth);
    } else {
        evkSetSecondaryWidth();
    }
    window.addEventListener("resize", evkSetSecondaryWidth);
})();
</script>';
    }
}, 9999);

// -------------------------------------------------------------------------
// Frontend: czcionka admina dla paska górnego (wp_head)
// -------------------------------------------------------------------------
add_action('wp_head', function () {
    $wl = evk_wl_get();
    if (empty($wl['enabled']) || empty($wl['admin_font_family'])) return;
    if (!is_admin_bar_showing()) return;

    $ff     = sanitize_text_field($wl['admin_font_family']);
    $ff_esc = esc_attr($ff);

    // Spróbuj zbudować @font-face z base64 (ten sam transient co admin_head)
    $transient_key = 'evk_wl_font_b64_' . md5($ff);
    $font_face     = get_transient($transient_key);
    if ($font_face === false) {
        $font_face    = '';
        $bricks_fonts = get_option('bricks_custom_fonts', []);
        if (is_array($bricks_fonts)) {
            foreach ($bricks_fonts as $font) {
                if (strtolower(trim($font['name'] ?? '')) !== strtolower($ff)) continue;
                foreach ((array)($font['files'] ?? []) as $file) {
                    $url    = $file['file'] ?? '';
                    $format = sanitize_text_field($file['format'] ?? 'woff2');
                    if (!$url || $format !== 'woff2') continue;
                    $local_path = str_replace([site_url('/'), home_url('/')], [ABSPATH, ABSPATH], $url);
                    $data = file_exists($local_path) ? file_get_contents($local_path) : false;
                    if (!$data) {
                        $response = wp_remote_get($url, ['timeout' => 5]);
                        if (!is_wp_error($response)) $data = wp_remote_retrieve_body($response);
                    }
                    if ($data) {
                        $b64       = base64_encode($data);
                        $weight    = sanitize_text_field($font['weight'] ?? 'normal');
                        $style     = sanitize_text_field($font['style']  ?? 'normal');
                        $font_face = "@font-face{font-family:'{$ff_esc}';src:url('data:font/woff2;base64,{$b64}') format('woff2');font-weight:{$weight};font-style:{$style};font-display:block;}";
                    }
                    break 2;
                }
            }
        }
        set_transient($transient_key, $font_face, 7 * DAY_IN_SECONDS);
    }
    if (!$font_face) {
        $font_face = evk_wl_get_bricks_font_face($ff);
    }

    echo '<style id="evk-wl-font-fe">';
    if ($font_face) echo $font_face;
    $bar_text_fe = "#wpadminbar .ab-item,"
                 . "#wpadminbar a.ab-item,"
                 . "#wpadminbar .ab-label,"
                 . "#wpadminbar #wp-admin-bar-site-name>a,"
                 . "#wpadminbar .display-name,"
                 . "#wpadminbar .menupop .ab-item";
    echo "#wpadminbar{font-family:'{$ff_esc}',sans-serif!important;}";
    echo "{$bar_text_fe}{font-family:'{$ff_esc}',sans-serif!important;}";
    echo '</style>';
}, 9999);

// -------------------------------------------------------------------------
// Frontend admin bar CSS
// -------------------------------------------------------------------------
add_action('wp_head', function () {
    $wl = evk_wl_get();
    if (empty($wl['enabled']) || empty($wl['admin_bar_color'])) return;

    $bar = esc_attr($wl['admin_bar_color']);
    $hbg = !empty($wl['admin_bar_hover_color']) ? esc_attr($wl['admin_bar_hover_color']) : "color-mix(in srgb,{$bar} 80%,#000 20%)";
    $sbg = !empty($wl['admin_bar_sub_color'])   ? esc_attr($wl['admin_bar_sub_color'])   : "color-mix(in srgb,{$bar} 85%,#000 15%)";

    $css  = "#wpadminbar,#wpadminbar .quicklinks{background:{$bar}!important;}";
    $css .= "#wpadminbar .ab-item,#wpadminbar .ab-icon,#wpadminbar a.ab-item{color:#eee!important;}";
    $css .= "#wpadminbar .ab-top-menu>li:hover>.ab-item,#wpadminbar .ab-top-menu>li.hover>.ab-item{background:{$hbg}!important;color:#fff!important;}";
    $css .= "#wpadminbar .ab-top-menu>li:hover>.ab-item:before,#wpadminbar .ab-top-menu>li.hover .ab-icon{color:#fff!important;}";
    $css .= "#wpadminbar .menupop .ab-sub-wrapper{background:{$sbg}!important;}";
    $css .= "#wpadminbar .ab-submenu .ab-item{color:#ddd!important;}";
    $css .= "#wpadminbar .ab-submenu .ab-item:hover{background:color-mix(in srgb,{$sbg} 80%,#000 20%)!important;color:#fff!important;}";
    if (!empty($wl['color_admin_bar_link'])) {
        $abl = esc_attr($wl['color_admin_bar_link']);
        $css .= "#wpadminbar .quicklinks .menupop ul li a:hover,"
              . "#wpadminbar .quicklinks .menupop ul li a:focus,"
              . "#wpadminbar li:hover .ab-icon:before,"
              . "#wpadminbar li:hover .ab-item:before,"
              . "#wpadminbar li.hover .ab-icon:before,"
              . "#wpadminbar li.hover .ab-item:before,"
              . "#wpadminbar:not(.mobile) .ab-top-menu>li:hover>.ab-item,"
              . "#wpadminbar:not(.mobile)>#wp-toolbar li:hover span.ab-label"
              . "{color:{$abl}!important;}";
    }

    echo '<style id="evk-wl-frontend">'.$css.'</style>';
}, 9999);
