<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł Konserwacji
 */

// =========================================================================
// REJESTRACJA USTAWIEŃ
// =========================================================================

add_action('admin_init', function () {
    // UWAGA: 'maintenance_mode' celowo NIE jest rejestrowany w tej grupie.
    // Jest zapisywany wyłącznie przez AJAX toggle (evk_ajax_toggle) — rejestracja
    // powodowała zerowanie stanu przy zapisie formularza options.php.
    register_setting('evoke_one_maintenance', 'maintenance_bypass_password');
    register_setting('evoke_one_maintenance', 'maintenance_bypass_hours');
    register_setting('evoke_one_maintenance', 'maintenance_page_id');
    register_setting('evoke_one_maintenance', 'maintenance_excluded_paths');
});

// =========================================================================
// ADMIN BAR TOGGLE
// =========================================================================

add_action('admin_bar_menu', function ($wp_admin_bar) {
    if (!current_user_can('manage_options') && !current_user_can('evk_access_maintenance')) return;

    $status     = (int) get_option('maintenance_mode', 0);
    $bg_color   = $status === 1 ? '#6e00a5' : '#72777c';
    $dot_pos    = $status === 1 ? 'left: 18px;' : 'left: 2px;';
    $text_color = $status === 1 ? '#000' : '#fff';

    $title = '<div style="display:flex;align-items:center;gap:10px;padding:0 5px;color:' . $text_color . '">'
        . 'Konserwacja'
        . '<span style="display:inline-block;width:34px;height:18px;border-radius:18px;background:' . $bg_color . ';position:relative;transition:background 0.3s;">'
        . '<span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:#fff;position:absolute;top:2px;' . $dot_pos . 'transition:left 0.3s;"></span>'
        . '</span></div>';

    $wp_admin_bar->add_node([
        'id'    => 'maintenance_toggle_node',
        'title' => $title,
        'href'  => '#',
        'meta'  => [
            'onclick' => 'toggleMaintenanceMode(event);',
            'title'   => 'Włącz/wyłącz tryb konserwacji',
        ],
    ]);

    if ($status === 1) {
        add_action('wp_head',    'evoke_one_adminbar_orange_style');
        add_action('admin_head', 'evoke_one_adminbar_orange_style');
    }
}, 999);

function evoke_one_adminbar_orange_style(): void {
    echo '<style>#wpadminbar #wp-admin-bar-maintenance_toggle_node>.ab-item{background:#ea580c!important;color:#fff!important;}#wpadminbar #wp-admin-bar-maintenance_toggle_node>.ab-item:hover{background:#c2410c!important;}</style>';
}

add_action('admin_enqueue_scripts', 'evoke_one_maintenance_bar_js');
add_action('wp_enqueue_scripts',    'evoke_one_maintenance_bar_js');

function evoke_one_maintenance_bar_js(): void {
    if (!current_user_can('manage_options') && !current_user_can('evk_access_maintenance')) return;
    ?>
    <script>
    function toggleMaintenanceMode(e){
        e.preventDefault();
        var n = document.getElementById('wp-admin-bar-maintenance_toggle_node');
        if (!n) return;
        n.style.opacity = '0.5';
        var x = new XMLHttpRequest();
        x.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
        x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded;');
        x.onload = function () {
            if (x.status === 200) location.reload();
            else { alert('Błąd.'); n.style.opacity = '1'; }
        };
        x.send('action=toggle_maintenance_status&nonce=<?php echo wp_create_nonce('maintenance_bar_nonce'); ?>');
    }
    </script>
    <?php if ((int) get_option('maintenance_mode', 0) === 1): ?>
    <style>#wpadminbar #wp-admin-bar-maintenance_toggle_node>.ab-item{background:#ffd64f!important;}#wpadminbar #wp-admin-bar-maintenance_toggle_node:hover>.ab-item{background:#ffd13b!important;}</style>
    <?php endif;
}

add_action('wp_ajax_toggle_maintenance_status', function () {
    check_ajax_referer('maintenance_bar_nonce', 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('evk_access_maintenance')) wp_send_json_error();
    update_option('maintenance_mode', (int) get_option('maintenance_mode', 0) === 1 ? 0 : 1);
    wp_send_json_success();
});

// =========================================================================
// LOGIKA KONSERWACJI
// =========================================================================

function evoke_one_wpm_get_excluded_paths(): array {
    $hardcoded  = ['/wp-login.php', '/wp-admin', '/wp-cron.php'];
    $custom_raw = get_option('maintenance_excluded_paths', '');
    $custom     = array_filter(array_map('trim', explode("\n", $custom_raw)));
    return array_merge($hardcoded, array_values($custom));
}

add_action('parse_request', function () {
    global $wpm_show_maintenance;
    $wpm_show_maintenance = false;

    if ((int) get_option('maintenance_mode', 0) !== 1) return;
    if (is_user_logged_in()) return;

    $request_uri = strtok($_SERVER['REQUEST_URI'], '?');
    foreach (evoke_one_wpm_get_excluded_paths() as $path) {
        if (strpos($request_uri, $path) !== false) return;
    }

    $temp_pass  = get_option('maintenance_bypass_password', '');
    $hours      = max(1, (int) get_option('maintenance_bypass_hours', 1));
    $expiration = time() + ($hours * 3600);
    $has_param  = !empty($temp_pass) && isset($_GET['haslo']) && $_GET['haslo'] === $temp_pass;
    $has_cookie = !empty($temp_pass) && isset($_COOKIE['maintenance_bypass']) && $_COOKIE['maintenance_bypass'] === $temp_pass;

    if ($has_param) {
        setcookie('maintenance_bypass', $temp_pass, $expiration, '/', '', false, true);
        wp_redirect(strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }

    if ($has_cookie) return;

    if ($request_uri !== '/' && $request_uri !== '') {
        wp_redirect(home_url('/'), 302);
        exit;
    }

    $wpm_show_maintenance = true;
});

add_action('wp', function () {
    global $wpm_show_maintenance, $wp_query, $post;
    if (empty($wpm_show_maintenance)) return;

    $page_id = (int) get_option('maintenance_page_id', 0);
    if (!$page_id || get_post_status($page_id) !== 'publish') return;

    $maintenance_post = get_post($page_id);
    $wp_query->init();
    $wp_query->queried_object    = $maintenance_post;
    $wp_query->queried_object_id = $page_id;
    $wp_query->is_page           = true;
    $wp_query->is_singular       = true;
    $wp_query->is_home           = false;
    $wp_query->is_front_page     = false;
    $wp_query->is_404            = false;
    $wp_query->posts             = [$maintenance_post];
    $wp_query->post              = $maintenance_post;
    $wp_query->post_count        = 1;
    $wp_query->found_posts       = 1;
    $post = $maintenance_post;
    setup_postdata($post);
});

add_filter('template_include', function ($template) {
    global $wpm_show_maintenance;
    if (empty($wpm_show_maintenance)) return $template;

    $page_id = (int) get_option('maintenance_page_id', 0);
    status_header(503);
    nocache_headers();
    header('Retry-After: 3600');

    if ($page_id && get_post_status($page_id) === 'publish') {
        $slug = get_post_meta($page_id, '_wp_page_template', true);
        if ($slug && $slug !== 'default') {
            $located = locate_template($slug);
            if ($located) return $located;
        }
        $fallback = locate_template(['page.php', 'singular.php', 'index.php']);
        if ($fallback) return $fallback;
    }

    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Przerwa techniczna</title>'
        . '<style>*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wpm-fallback{text-align:center;padding:40px 20px}.wpm-fallback h1{font-size:clamp(28px,5vw,52px);font-weight:700;color:#111827;letter-spacing:-.02em;margin-bottom:16px}.wpm-fallback p{font-size:clamp(14px,2vw,18px);color:#6b7280}</style>'
        . '</head><body><div class="wpm-fallback"><h1>Przerwa techniczna</h1><p>Niedługo wracamy.</p></div></body></html>';
    exit;
}, 999);

add_filter('robots_txt', function ($output, $public) {
    if ((int) get_option('maintenance_mode', 0) === 1) {
        return "User-agent: *\nDisallow: /\n";
    }
    return $output;
}, 10, 2);
