<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — TL: rejestracja menu, enqueue, helpery bazowe
 */
// ====================================================================
// 4. ADMIN PAGE
// ====================================================================
add_action('admin_menu', function () {
    if (empty(get_option('evk_tl_module_enabled', 0))) return;
    $parent = get_option('tl_menu_location', 'options-general.php');
    if ($parent === 'none') {
        add_menu_page(TL_MENU_TITLE, TL_MENU_TITLE, 'evk_access_translations', TL_MENU_SLUG, 'tl_render_page', 'dashicons-translation', 81);
    } else {
        add_submenu_page($parent, TL_MENU_TITLE, TL_MENU_TITLE, 'evk_access_translations', TL_MENU_SLUG, 'tl_render_page');
    }
}, 99);

add_action('admin_enqueue_scripts', function ($hook) {
    if (strpos($hook, TL_MENU_SLUG) === false) return;
    wp_enqueue_script('jquery-ui-sortable');
    wp_enqueue_media();
    // Załaduj główny CSS/JS admina Evoke (potrzebny dla evo-toggle, evo-status-card itp.)
    wp_enqueue_style('evoke-one-admin',
        EVOKE_ONE_URL . 'assets/admin/admin.css', [], EVOKE_ONE_VERSION);
    wp_enqueue_script('evoke-one-admin',
        EVOKE_ONE_URL . 'assets/admin/admin.js', ['jquery'], EVOKE_ONE_VERSION, true);
    wp_localize_script('evoke-one-admin', 'evkToggle', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('evk-toggle-nonce'),
    ]);
});

function tl_base_url(): string {
    $parent = get_option('tl_menu_location', 'options-general.php');
    if ($parent === 'options-general.php') return admin_url('options-general.php?page=' . TL_MENU_SLUG);
    return admin_url('admin.php?page=' . TL_MENU_SLUG);
}

function tl_coverage_stats(): array {
    $data   = get_option('tl_translations', ['groups' => []]);
    $codes  = tl_get_active_lang_codes();
    $total  = 0;
    $filled = array_fill_keys($codes, 0);
    foreach (($data['groups'] ?? []) as $group) {
        foreach (($group['rows'] ?? []) as $row) {
            if (trim($row['pl'] ?? '') === '') continue;
            $total++;
            foreach ($codes as $code) { if (trim($row[$code] ?? '') !== '') $filled[$code]++; }
        }
    }
    $result = [];
    foreach ($codes as $code) { $result[$code] = $total > 0 ? round($filled[$code] / $total * 100) : 0; }
    return ['total' => $total, 'by_lang' => $result];
}

function tl_get_flag_url(int $flag_id): string {
    if (!$flag_id) return '';
    $url = wp_get_attachment_url($flag_id);
    return $url ?: '';
}

