<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Logi 404
 * Rejestruje nieistniejące URL-e z datą, IP, referrerem i user agentem.
 * Automatycznie ignoruje URLe z aktywnym przekierowaniem 301.
 */

// =========================================================================
// OPCJE
// =========================================================================

function evk_404_is_enabled(): bool {
    return !empty(get_option('evk_404_enabled'));
}

function evk_404_max_logs(): int {
    return max(10, (int) get_option('evk_404_max_logs', 200));
}

function evk_404_skip_bots(): bool {
    return !empty(get_option('evk_404_skip_bots', 1));
}

function evk_404_bot_list(): array {
    $raw = get_option('evk_404_bot_list', '');
    if (empty($raw)) {
        $raw = "gptbot\ngooglebot\nyandexbot\nbytespider\nspider\npetalbot\nsemrushbot\nahrefsbot\nbingbot\nimagesiftbot\nbarkrowler\ntwitterbot\nfacebook\ndataforseobot\nmeta-externalagent";
    }
    return array_filter(array_map('trim', explode("\n", $raw)));
}

// =========================================================================
// REJESTRACJA CPT
// =========================================================================

add_action('init', function () {
    register_post_type('evk_404_log', [
        'public'   => false,
        'show_ui'  => false,
        'supports' => ['title'],
    ]);
});

// =========================================================================
// PRZECHWYTYWANIE 404
// =========================================================================

add_action('template_redirect', function () {
    if (!is_404() || !evk_404_is_enabled()) return;

    // Pomiń jeśli istnieje redirect 301
    if (evk_301_has_redirect($_SERVER['REQUEST_URI'] ?? '')) return;

    // Pomiń boty
    if (evk_404_skip_bots()) {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        foreach (evk_404_bot_list() as $bot) {
            if ($ua && strpos($ua, strtolower($bot)) !== false) return;
        }
    }

    // Wyczyść stare logi
    evk_404_cleanup();

    wp_insert_post([
        'post_type'   => 'evk_404_log',
        'post_status' => 'publish',
        'post_title'  => ($_SERVER['REQUEST_URI'] ?? '/') . ' — ' . current_time('mysql'),
        'meta_input'  => [
            'url'       => sanitize_text_field($_SERVER['REQUEST_URI'] ?? ''),
            'referrer'  => sanitize_text_field($_SERVER['HTTP_REFERER']   ?? ''),
            'ip'        => evk_ip_klienta(),   // za Cloudflare adres odwiedzającego, nie węzła
            'ua'        => sanitize_text_field($_SERVER['HTTP_USER_AGENT']?? ''),
            'method'    => sanitize_text_field($_SERVER['REQUEST_METHOD'] ?? ''),
            'logged_at' => current_time('mysql'),
        ],
    ]);
});

function evk_404_cleanup(): void {
    global $wpdb;
    $limit = evk_404_max_logs();
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='evk_404_log' AND post_status='publish'");
    if ($count >= $limit) {
        $to_delete = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type='evk_404_log' AND post_status='publish' ORDER BY post_date ASC LIMIT %d",
            $count - $limit + 1
        ));
        if ($to_delete) {
            $ids = implode(',', array_map('intval', $to_delete));
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($ids)");
            $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($ids)");
        }
    }
}

// =========================================================================
// AJAX — wyczyść logi
// =========================================================================

add_action('wp_ajax_evk_clear_404_logs', function () {
    check_ajax_referer('evk_tools_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='evk_404_log'");
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)");
        $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($in)");
    }
    wp_send_json_success();
});
