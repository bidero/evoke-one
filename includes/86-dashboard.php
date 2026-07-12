<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł Kokpitu (Bricks Builder iframe)
 */

// =========================================================================
// REJESTRACJA USTAWIEŃ
// =========================================================================

add_action('admin_init', function () {
    // Osobna grupa 'evoke_one_dashboard' — wcześniej wspólna grupa 'evoke_one_other'
    // powodowała, że zapis INNEGO formularza z tej grupy (np. zakładki Treść)
    // zerował wszystkie opcje kokpitu (options.php aktualizuje całą grupę).
    $settings = [
        'evoke_dashboard_active',
        'evoke_dashboard_remove_native',
        'evoke_dashboard_remove_help',
        'evoke_dashboard_page_id',
        'evoke_dashboard_mode',
        'evoke_dashboard_width',
        'evoke_dashboard_height',
        'evoke_dashboard_scrolling',
        'evoke_dashboard_fit_content',
        'evoke_dashboard_shadow',
    ];
    foreach ($settings as $s) {
        register_setting('evoke_one_dashboard', $s);
    }
});

// =========================================================================
// UKRYJ PASEK ADMINA WEWNĄTRZ IFRAME
// =========================================================================

add_filter('show_admin_bar', function ($show) {
    if (isset($_GET['evoke_dashboard_context'])) return false;
    return $show;
});

// =========================================================================
// CZYSZCZENIE INTERFEJSU WP
// =========================================================================

add_action('wp_dashboard_setup', function () {
    if (get_option('evoke_dashboard_active') !== '1') return;
    if (get_option('evoke_dashboard_remove_native') !== '1') return;

    global $wp_meta_boxes;
    unset($wp_meta_boxes['dashboard']);
}, 999);

add_action('admin_head', function () {
    if (get_option('evoke_dashboard_active') !== '1') return;

    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'dashboard') return;

    $styles = '#wpbody-content { padding-bottom: 0 !important; } .wrap > h1:first-child { display: none !important; }';

    if (get_option('evoke_dashboard_remove_native') === '1') {
        $styles .= '#dashboard-widgets, .postbox-container .empty-container { display: none !important; }';
    }

    if (get_option('evoke_dashboard_remove_help') === '1') {
        $styles .= '#contextual-help-link-wrap { display: none !important; }';
        $screen->remove_help_tabs();
    }

    echo '<style>' . $styles . '</style>';
});

// =========================================================================
// WSTRZYKIWANIE IFRAME BRICKS
// =========================================================================

add_action('admin_notices', function () {
    if (get_option('evoke_dashboard_active') !== '1') return;

    $screen = get_current_screen();
    if (!$screen || $screen->id !== 'dashboard') return;

    $page_id = (int) get_option('evoke_dashboard_page_id', 0);
    if (!$page_id || get_post_status($page_id) !== 'publish') return;

    $mode        = get_option('evoke_dashboard_mode', 'above');
    $width       = esc_attr(get_option('evoke_dashboard_width', '100%'));
    $height      = esc_attr(get_option('evoke_dashboard_height', '600px'));
    $scrolling   = esc_attr(get_option('evoke_dashboard_scrolling', 'auto'));
    $fit_content = get_option('evoke_dashboard_fit_content') === '1';
    $shadow      = get_option('evoke_dashboard_shadow', '1') === '1';

    $wrap_style   = 'margin: 20px 20px ' . ($mode === 'replace' ? '0' : '20px') . ' 0; position:relative;';
    $iframe_style = "width:{$width};height:{$height};border:none;display:block;";
    if ($shadow) $iframe_style .= 'border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,.05);';

    $url = add_query_arg('evoke_dashboard_context', '1', get_permalink($page_id));

    echo '<div class="evoke-bricks-dashboard-wrap" style="' . $wrap_style . '">';
    echo '<iframe id="evoke-bricks-dashboard-iframe" src="' . esc_url($url) . '" style="' . $iframe_style . '" scrolling="' . $scrolling . '" loading="lazy"></iframe>';
    echo '</div>';

    if ($fit_content): ?>
    <script>
    document.getElementById('evoke-bricks-dashboard-iframe').addEventListener('load', function () {
        var iframe = this;
        try {
            iframe.contentWindow.addEventListener('wheel', function (e) {
                window.scrollBy({ top: e.deltaY, left: e.deltaX, behavior: 'auto' });
            }, { passive: true });
        } catch (err) { console.warn('Evoke Dashboard: scroll bridge failed', err); }
        try {
            var target = iframe.contentWindow.document.documentElement;
            new iframe.contentWindow.ResizeObserver(function () {
                iframe.style.height = target.scrollHeight + 'px';
            }).observe(target);
            iframe.style.height = target.scrollHeight + 'px';
        } catch (err) {
            iframe.style.height = iframe.contentWindow.document.documentElement.scrollHeight + 'px';
        }
    });
    </script>
    <?php endif;
});

// =========================================================================
// INNE USTAWIENIA GLOBALNE (Bricks, Komentarze)
// =========================================================================

add_action('admin_init', function () {
    // Grupa 'evoke_one_content' — tylko opcje z formularza zakładki Treść.
    // 'evoke_move_bricks_bottom' celowo NIE jest rejestrowany w żadnej grupie:
    // nie ma go w żadnym formularzu (zapis wyłącznie przez AJAX toggle),
    // a rejestracja zerowałaby go przy zapisie grupy.
    register_setting('evoke_one_content', 'evoke_disable_global_comments', ['sanitize_callback' => 'absint']);
    register_setting('evoke_one_content', 'evoke_require_reg_to_comment',  ['sanitize_callback' => 'absint']);
});

// Opcja "Przesuń Bricks na dół" zastąpiona przez edytor menu (86-menu-editor.php)

// Wyłącz komentarze globalnie
add_action('init', function () {
    if (!get_option('evoke_disable_global_comments')) return;
    foreach (get_post_types() as $pt) {
        if (post_type_supports($pt, 'comments')) {
            remove_post_type_support($pt, 'comments');
            remove_post_type_support($pt, 'trackbacks');
        }
    }
});

add_filter('comments_open', function ($open) {
    return get_option('evoke_disable_global_comments') ? false : $open;
});

// Wymagaj rejestracji do komentarzy
add_action('init', function () {
    if (get_option('evoke_require_reg_to_comment')) {
        update_option('comment_registration', 1);
    }
});

// Ukryj stopkę i zbędne elementy gdy strona wyświetlana jest jako kokpit w iframe
add_action('wp_head', function () {
    if (!isset($_GET['evoke_dashboard_context'])) return;
    ?>
    <style id="evoke-dashboard-iframe-css">
        /* Ukryj stopkę, header i admin bar w trybie iframe-kokpitu */
        #wpadminbar,
        header, footer, .site-footer, .footer,
        [class*="footer"], [id*="footer"],
        [class*="header"]:not(#evoke-dashboard-iframe-css),
        [id*="header"],
        .brx-footer, #brx-footer,
        nav, .nav, .navigation,
        .cookie-banner, #cookie-banner,
        .popup-overlay, .modal {
            display: none !important;
        }
        body {
            padding: 0 !important;
            margin: 0 !important;
            background: transparent !important;
        }
        /* Pierwszy section/main na pełną szerokość */
        main, .main, [class*="main-content"], section:first-of-type {
            padding-top: 0 !important;
            margin-top: 0 !important;
        }
    </style>
    <script>
    (function () {
        function patchLinks(root) {
            root.querySelectorAll('a[href]').forEach(function (a) {
                var href = a.getAttribute('href');
                if (href && href.charAt(0) !== '#' && href.indexOf('javascript') !== 0) {
                    a.setAttribute('target', '_top');
                }
            });
        }
        document.addEventListener('DOMContentLoaded', function () {
            patchLinks(document.body);
            new MutationObserver(function (mutations) {
                mutations.forEach(function (m) {
                    m.addedNodes.forEach(function (n) {
                        if (n.nodeType === 1) patchLinks(n);
                    });
                });
            }).observe(document.body, { childList: true, subtree: true });
        });
    })();
    </script>
    <?php
});
