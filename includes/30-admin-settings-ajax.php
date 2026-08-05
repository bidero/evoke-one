<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia - admin settings, sanitizers and AJAX
 */

// ====================================================================
// 3. ADMIN SETTINGS + AJAX
// ====================================================================
add_action('admin_init', function () {
    register_setting('tl_group_languages', 'tl_menu_location', ['sanitize_callback' => 'sanitize_text_field', 'default' => 'options-general.php']);
    register_setting('tl_group_translations', 'tl_translations', ['sanitize_callback' => 'tl_sanitize_translations_payload']);
    register_setting('tl_group_languages', 'tl_pl_flag', ['sanitize_callback' => 'absint', 'default' => 0]);
    register_setting('tl_group_images', 'tl_images', ['sanitize_callback' => 'tl_sanitize_images_payload']);
    register_setting('tl_group_slugs', 'tl_url_slugs', ['sanitize_callback' => 'tl_sanitize_slugs_payload']);
    register_setting('tl_group_sitemap', 'tl_sitemap_settings', ['sanitize_callback' => 'tl_sanitize_sitemap_settings']);
    if (get_option('ustawienia') && !get_option('tl_translations')) {
        update_option('tl_translations', get_option('ustawienia'));
    }
});

function tl_sanitize_translations_payload($input): array {
    $codes = tl_get_active_lang_codes();
    $clean = ['groups' => []];
    foreach (($input['groups'] ?? []) as $group_id => $group) {
        $group_key = sanitize_key($group_id) ?: ('group_' . wp_rand(1000, 9999));
        $clean['groups'][$group_key] = ['name' => sanitize_text_field($group['name'] ?? ''), 'rows' => []];
        foreach (($group['rows'] ?? []) as $row_id => $row) {
            $row_key = sanitize_key($row_id) ?: ('row_' . wp_rand(1000, 9999));
            $clean['groups'][$group_key]['rows'][$row_key] = [
                'pl'     => sanitize_textarea_field($row['pl'] ?? ''),
                'dd_key' => sanitize_key($row['dd_key'] ?? ''),
            ];
            foreach ($codes as $code) {
                $clean['groups'][$group_key]['rows'][$row_key][$code] = sanitize_textarea_field($row[$code] ?? '');
            }
        }
    }
    return $clean;
}

function tl_sanitize_languages_payload($input): array {
    $clean = [];
    foreach ((array) $input as $lang) {
        $code = strtolower(preg_replace('/[^a-zA-Z]/', '', $lang['code'] ?? ''));
        if ($code && $code !== 'pl') {
            $clean[] = [
                'code' => $code,
                'name' => sanitize_text_field($lang['name'] ?? $code),
                'html' => sanitize_text_field($lang['html'] ?? $code),
                'flag' => absint($lang['flag'] ?? 0),
            ];
        }
    }
    return $clean;
}

function tl_sanitize_images_payload($input): array {
    $codes = tl_get_active_lang_codes();
    $clean = [];
    foreach ((array) $input as $key => $entry) {
        $image_key = sanitize_key($key);
        if (!$image_key) continue;
        $clean[$image_key] = ['pl' => absint($entry['pl'] ?? 0)];
        foreach ($codes as $code) { $clean[$image_key][$code] = absint($entry[$code] ?? 0); }
    }
    return $clean;
}

function tl_sanitize_slugs_payload($input): array {
    $codes = tl_get_active_lang_codes();
    $clean = [];
    foreach ((array) $input as $entry) {
        $pl_slug = sanitize_title($entry['pl'] ?? '');
        if (!$pl_slug) continue;
        $row = ['pl' => $pl_slug];
        foreach ($codes as $code) {
            $row[$code] = sanitize_title($entry[$code] ?? '');
        }
        $clean[] = $row;
    }
    return $clean;
}

function tl_get_sitemap_settings(): array {
    $defaults = [
        'enabled'                  => 0,
        'include_home'             => 1,
        'include_pages'            => 1,
        'include_posts'            => 1,
        'include_polish'           => 0,
        'only_translated_slugs'    => 0,
        'auto_exclude_noindex'     => 1,
        'excluded_ids'             => [],
		'include_users'            => 0,
    ];
    $saved = get_option('tl_sitemap_settings', []);
    return array_merge($defaults, is_array($saved) ? $saved : []);
}

function tl_sanitize_sitemap_settings($input): array {
    $input = is_array($input) ? $input : [];
    $excluded_ids = [];
    foreach ((array) ($input['excluded_ids'] ?? []) as $post_id) {
        $post_id = absint($post_id);
        if ($post_id) $excluded_ids[] = $post_id;
    }
    $excluded_ids = array_values(array_unique($excluded_ids));

    return [
        'enabled'              => !empty($input['enabled']) ? 1 : 0,
        'include_home'         => !empty($input['include_home']) ? 1 : 0,
        'include_pages'        => !empty($input['include_pages']) ? 1 : 0,
        'include_posts'        => !empty($input['include_posts']) ? 1 : 0,
        'include_polish'       => !empty($input['include_polish']) ? 1 : 0,
        'only_translated_slugs'=> !empty($input['only_translated_slugs']) ? 1 : 0,
        'auto_exclude_noindex' => !empty($input['auto_exclude_noindex']) ? 1 : 0,
		'include_users'        => !empty($input['include_users']) ? 1 : 0,
        'excluded_ids'         => $excluded_ids,
    ];
}

function tl_rebuild_dd_keys_from_rows(array $payload, array $previous_keys = []): array {
    $dd_keys = $previous_keys;
    foreach (($payload['groups'] ?? []) as $group) {
        foreach (($group['rows'] ?? []) as $row) {
            $pl     = sanitize_textarea_field($row['pl'] ?? '');
            $dd_key = sanitize_key($row['dd_key'] ?? '');
            if ($pl && $dd_key) $dd_keys[$dd_key] = $pl;
        }
    }
    return $dd_keys;
}

add_action('wp_ajax_tl_save_translations', function () {
    check_ajax_referer('tl_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnien.');
    $raw  = isset($_POST['tl_translations']) ? wp_unslash($_POST['tl_translations']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    $sanitized = tl_sanitize_translations_payload($data);
    $dd_keys   = tl_rebuild_dd_keys_from_rows($sanitized, get_option('tl_dd_keys', []));
    update_option('tl_translations', $sanitized);
    update_option('tl_dd_keys', $dd_keys);
    tl_invalidate_cache();
    wp_send_json_success('Zapisano.');
});

add_action('wp_ajax_tl_save_images', function () {
    check_ajax_referer('tl_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnien.');
    $raw  = isset($_POST['tl_images']) ? wp_unslash($_POST['tl_images']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    update_option('tl_images', tl_sanitize_images_payload($data));
    wp_send_json_success('Zapisano.');
});

add_action('wp_ajax_tl_save_settings', function () {
    check_ajax_referer('tl_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnien.');
    $raw  = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    if (isset($data['tl_languages']))     update_option('tl_languages',     tl_sanitize_languages_payload($data['tl_languages']));
    if (isset($data['tl_menu_location'])) update_option('tl_menu_location', sanitize_text_field($data['tl_menu_location']));
    if (isset($data['tl_pl_flag']))       update_option('tl_pl_flag',       absint($data['tl_pl_flag']));
    tl_invalidate_cache();
    tl_flush_rewrite_rules();
    wp_send_json_success('Zapisano ustawienia.');
});

add_action('wp_ajax_tl_save_dd_keys', function () {
    check_ajax_referer('tl_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnien.');
    $raw  = isset($_POST['tl_dd_keys']) ? wp_unslash($_POST['tl_dd_keys']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    $clean = [];
    foreach ($data as $key => $phrase) {
        $key = sanitize_key($key);
        if ($key) $clean[$key] = sanitize_textarea_field($phrase);
    }
    update_option('tl_dd_keys', $clean);
    tl_invalidate_cache();
    wp_send_json_success('Zapisano klucze DD.');
});

add_action('wp_ajax_tl_save_slugs', function () {
    check_ajax_referer('tl_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnien.');
    $raw  = isset($_POST['tl_url_slugs']) ? wp_unslash($_POST['tl_url_slugs']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    update_option('tl_url_slugs', tl_sanitize_slugs_payload($data));
    tl_invalidate_cache();
    tl_flush_rewrite_rules();
    wp_send_json_success('Zapisano slugi URL.');
});

add_action('wp_ajax_tl_save_sitemap_settings', function () {
    check_ajax_referer('tl_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnien.');
    $raw  = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
    $data = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) wp_send_json_error('Nieprawidlowy JSON.');
    update_option('tl_sitemap_settings', tl_sanitize_sitemap_settings($data));
    wp_send_json_success('Zapisano ustawienia mapy strony.');
});

add_action('wp_ajax_tl_export', function () {
    check_ajax_referer('tl_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_die();

    // Które moduły eksportować
    $raw_mods  = isset($_POST['modules']) ? wp_unslash($_POST['modules']) : '[]';
    $modules   = json_decode($raw_mods, true);
    if (!is_array($modules)) $modules = array_keys(evoke_one_get_io_modules());

    $data = ['_evoke_one_export' => true, 'exported_at' => current_time('c'), 'version' => EVOKE_ONE_VERSION];

    // Mapa: klucz modułu → funkcja zbierająca dane
    $collectors = [
        'tl_translations'     => fn() => ['tl_translations' => get_option('tl_translations', ['groups' => []])],
        'tl_languages'        => fn() => [
            'tl_languages'    => get_option('tl_languages', []),
            'tl_menu_location'=> get_option('tl_menu_location', 'options-general.php'),
            'tl_pl_flag'      => get_option('tl_pl_flag', 0),
        ],
        'tl_images'           => fn() => ['tl_images'           => get_option('tl_images', [])],
        'tl_url_slugs'        => fn() => ['tl_url_slugs'        => get_option('tl_url_slugs', [])],
        'tl_sitemap_settings' => fn() => ['tl_sitemap_settings' => get_option('tl_sitemap_settings', [])],
        'tl_dd_keys'          => fn() => ['tl_dd_keys'          => get_option('tl_dd_keys', [])],
        'evk_darkmode'        => fn() => ['evk_darkmode'        => get_option('evk_darkmode', [])],
        'evk_cursor'          => fn() => ['evk_cursor'          => get_option('evk_cursor', [])],
        'evk_lenis'           => fn() => ['evk_lenis'           => get_option('evk_lenis', [])],
        'evk_animator'        => fn() => ['evk_animator'        => get_option('evk_animator', [])],
        'evk_forminbox'       => fn() => ['evk_forminbox' => get_option('evk_forminbox', [])],
        'evk_parallax'        => fn() => [
            'evk_parallax'       => get_option('evk_parallax', []),
            'evk_parallax_value' => get_option('evk_parallax_value', 0.3),
            'evk_parallax_scale' => get_option('evk_parallax_scale', 1.2),
        ],
        'evk_a11y'            => fn() => ['evk_a11y'            => get_option('evk_a11y', [])],
        'evk_schema'          => fn() => ['evk_schema'          => get_option('evk_schema', [])],
        'evk_og'              => fn() => ['evk_og'              => get_option('evk_og', [])],
        'evk_white_label'     => fn() => [
            'evk_white_label'    => get_option('evk_white_label', []),
            'evk_wl_bar_items'   => get_option('evk_wl_bar_items', '[]'),
        ],
        'evk_security'        => fn() => ['evk_security'        => get_option('evk_security', [])],
        'evk_smtp'            => fn() => ['evk_smtp'            => get_option('evk_smtp', [])],
        'evk_maintenance'     => fn() => [
            'maintenance_mode'             => get_option('maintenance_mode', ''),
            'maintenance_page_id'          => get_option('maintenance_page_id', 0),
            'maintenance_excluded_paths'   => get_option('maintenance_excluded_paths', ''),
            'maintenance_bypass_hours'     => get_option('maintenance_bypass_hours', 24),
            'maintenance_bypass_password'  => get_option('maintenance_bypass_password', ''),
        ],
        'evk_redirects'       => fn() => ['evk_301_enabled' => get_option('evk_301_enabled', '')],
        'evk_logs404'         => fn() => [
            'evk_404_enabled'   => get_option('evk_404_enabled', ''),
            'evk_404_max_logs'  => get_option('evk_404_max_logs', 200),
            'evk_404_skip_bots' => get_option('evk_404_skip_bots', 1),
            'evk_404_bot_list'  => get_option('evk_404_bot_list', ''),
        ],
        'evk_dashboard'       => fn() => [
            'evoke_dashboard_active'        => get_option('evoke_dashboard_active', ''),
            'evoke_dashboard_page_id'       => get_option('evoke_dashboard_page_id', 0),
            'evoke_dashboard_mode'          => get_option('evoke_dashboard_mode', 'above'),
            'evoke_dashboard_width'         => get_option('evoke_dashboard_width', '100%'),
            'evoke_dashboard_height'        => get_option('evoke_dashboard_height', '600px'),
            'evoke_dashboard_scrolling'     => get_option('evoke_dashboard_scrolling', 'auto'),
            'evoke_dashboard_fit_content'   => get_option('evoke_dashboard_fit_content', ''),
            'evoke_dashboard_shadow'        => get_option('evoke_dashboard_shadow', '1'),
            'evoke_dashboard_remove_native' => get_option('evoke_dashboard_remove_native', ''),
            'evoke_dashboard_remove_help'   => get_option('evoke_dashboard_remove_help', ''),
        ],
        'evk_snippets'        => function () {
            // Snippety są CPT (evk_code_snippet) + 3 opcje WP
            $posts = get_posts([
                'post_type'      => 'evk_code_snippet',
                'posts_per_page' => -1,
                'post_status'    => 'private',
                'suppress_filters' => true,
            ]);
            $snippets = [];
            foreach ($posts as $p) {
                $snippets[] = [
                    'slug'    => $p->post_name,
                    'title'   => $p->post_title,
                    'content' => $p->post_content,
                ];
            }
            return [
                'evk_snippets_posts'            => $snippets,
                'evk_snippets_enabled'          => get_option('evk_snippets_enabled', 0),
                'evk_snippets_advanced_enabled' => get_option('evk_snippets_advanced_enabled', 0),
                'evk_snippets_advanced_content' => get_option('evk_snippets_advanced_content', ''),
            ];
        },
        'evk_other'           => fn() => [
            'evoke_disable_global_comments' => get_option('evoke_disable_global_comments', ''),
            'evoke_require_reg_to_comment'  => get_option('evoke_require_reg_to_comment', ''),
            'evoke_move_bricks_bottom'      => get_option('evoke_move_bricks_bottom', ''),
            'evk_draft_revision_enabled'    => get_option('evk_draft_revision_enabled', ''),
            'favicon_url'                   => get_option('favicon_url', ''),
            'evk_elements'                  => get_option('evk_elements', []),
            'evk_cleanup'                   => get_option('evk_cleanup', []),
        ],
        'evk_newsletter'      => function () {
            global $wpdb;
            return [
                'evk_newsletter'      => get_option('evk_newsletter', []),
                'evk_nl_lists'        => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_lists", ARRAY_A) ?: [],
                'evk_nl_subscribers'  => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_subscribers", ARRAY_A) ?: [],
                'evk_nl_templates'    => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_templates", ARRAY_A) ?: [],
                'evk_nl_campaigns'    => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_campaigns", ARRAY_A) ?: [],
                'evk_nl_queue'        => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_queue", ARRAY_A) ?: [],
                'evk_nl_logs'         => $wpdb->get_results("SELECT * FROM {$wpdb->prefix}evk_nl_logs", ARRAY_A) ?: [],
            ];
        },
    ];

    foreach ($modules as $mod) {
        if (isset($collectors[$mod])) {
            $data = array_merge($data, ($collectors[$mod])());
        }
    }

    $filename = 'evoke-one-export-' . date('Y-m-d') . '.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
});

add_action('wp_ajax_tl_import', function () {
    check_ajax_referer('tl_ajax_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.');

    $raw  = isset($_POST['json'])      ? wp_unslash($_POST['json'])      : '';
    $decs = isset($_POST['decisions']) ? wp_unslash($_POST['decisions']) : '{}';
    $data = json_decode($raw, true);
    $dec  = json_decode($decs, true) ?: [];

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        wp_send_json_error('Nieprawidłowy JSON.');
    }

    // Helper: zapisz jeśli decyzja = overwrite (lub brak decyzji = zawsze nadpisz)
    $should = function(string $mod) use ($dec): bool {
        return !isset($dec[$mod]) || $dec[$mod] === 'overwrite';
    };

    $imported = 0;

    // TL
    if ($should('tl_translations') && isset($data['tl_translations'])) {
        // Backward compat: stary format 'ustawienia'
        $trans = $data['tl_translations'] ?: ($data['ustawienia'] ?? null);
        if ($trans) { update_option('tl_translations', $trans); $imported++; }
    }
    if ($should('tl_languages')) {
        if (isset($data['tl_languages']))     { update_option('tl_languages',     $data['tl_languages']); $imported++; }
        if (isset($data['tl_menu_location'])) update_option('tl_menu_location',  sanitize_text_field($data['tl_menu_location']));
        if (isset($data['tl_pl_flag']))       update_option('tl_pl_flag',         absint($data['tl_pl_flag']));
    }
    if ($should('tl_images')           && isset($data['tl_images']))           { update_option('tl_images',           $data['tl_images']); $imported++; }
    if ($should('tl_url_slugs')        && isset($data['tl_url_slugs']))        { update_option('tl_url_slugs',        $data['tl_url_slugs']); $imported++; }
    if ($should('tl_sitemap_settings') && isset($data['tl_sitemap_settings'])) { update_option('tl_sitemap_settings', tl_sanitize_sitemap_settings($data['tl_sitemap_settings'])); $imported++; }
    if ($should('tl_dd_keys')          && isset($data['tl_dd_keys']))          { update_option('tl_dd_keys',          $data['tl_dd_keys']); $imported++; }

    // Frontend modules
    foreach (['evk_darkmode','evk_cursor','evk_lenis','evk_animator','evk_a11y','evk_schema','evk_og','evk_security','evk_smtp'] as $opt) {
        $mod = str_replace(['evk_','evoke_one_'], ['evk_','evk_'], $opt);
        if ($should($mod) && isset($data[$opt])) { update_option($opt, $data[$opt]); $imported++; }
    }

    // Parallax (dwa klucze → jeden moduł)
    if ($should('evk_parallax')) {
        if (isset($data['evk_parallax']))       { update_option('evk_parallax',       $data['evk_parallax']); $imported++; }
        if (isset($data['evk_parallax_value'])) update_option('evk_parallax_value', $data['evk_parallax_value']);
        if (isset($data['evk_parallax_scale'])) update_option('evk_parallax_scale', $data['evk_parallax_scale']);
    }

    // White Label
    if ($should('evk_white_label')) {
        if (isset($data['evk_white_label']))  { update_option('evk_white_label',  $data['evk_white_label']); $imported++; }
        if (isset($data['evk_wl_bar_items'])) update_option('evk_wl_bar_items', $data['evk_wl_bar_items']);
    }

    // Maintenance
    if ($should('evk_maintenance')) {
        $scalar_maintenance = ['maintenance_mode','maintenance_page_id','maintenance_excluded_paths','maintenance_bypass_hours','maintenance_bypass_password'];
        foreach ($scalar_maintenance as $k) {
            if (isset($data[$k])) update_option($k, $data[$k]);
        }
        $imported++;
    }

    // Redirects, 404, Dashboard, Snippets, Other
    if ($should('evk_redirects')   && isset($data['evk_301_enabled']))        { update_option('evk_301_enabled', $data['evk_301_enabled']); $imported++; }
    if ($should('evk_logs404')) {
        foreach (['evk_404_enabled','evk_404_max_logs','evk_404_skip_bots','evk_404_bot_list'] as $k) {
            if (isset($data[$k])) update_option($k, $data[$k]);
        }
        $imported++;
    }
    if ($should('evk_dashboard')) {
        $dash_keys = ['evoke_dashboard_active','evoke_dashboard_page_id','evoke_dashboard_mode','evoke_dashboard_width','evoke_dashboard_height','evoke_dashboard_scrolling','evoke_dashboard_fit_content','evoke_dashboard_shadow','evoke_dashboard_remove_native','evoke_dashboard_remove_help'];
        foreach ($dash_keys as $k) { if (isset($data[$k])) update_option($k, $data[$k]); }
        $imported++;
    }
    if ($should('evk_snippets')) {
        if (isset($data['evk_snippets_posts']) && is_array($data['evk_snippets_posts'])) {
            foreach ($data['evk_snippets_posts'] as $s) {
                $slug    = sanitize_key($s['slug'] ?? '');
                $title   = sanitize_text_field($s['title'] ?? '');
                $content = wp_slash($s['content'] ?? '');
                if ($slug && function_exists('evk_snippet_save')) {
                    evk_snippet_save($slug, $title, $content);
                }
            }
        }
        if (isset($data['evk_snippets_enabled']))          update_option('evk_snippets_enabled',          absint($data['evk_snippets_enabled']));
        if (isset($data['evk_snippets_advanced_enabled'])) update_option('evk_snippets_advanced_enabled', absint($data['evk_snippets_advanced_enabled']));
        if (isset($data['evk_snippets_advanced_content'])) update_option('evk_snippets_advanced_content', wp_slash($data['evk_snippets_advanced_content']));
        $imported++;
    }
    if ($should('evk_other')) {
        foreach (['evoke_disable_global_comments','evoke_require_reg_to_comment','evoke_move_bricks_bottom','evk_draft_revision_enabled','favicon_url','evk_elements','evk_cleanup'] as $k) {
            if (isset($data[$k])) update_option($k, $data[$k]);
        }
        $imported++;
    }

    // Skrzynka wiadomości — pełne ustawienia (w tym header_layout, sidebar_layout, subject_field)
    if ($should('evk_forminbox') && isset($data['evk_forminbox']) && is_array($data['evk_forminbox'])) {
        update_option('evk_forminbox', $data['evk_forminbox']);
        $imported++;
    }

    // Newsletter — ustawienia + tabele DB
    if ($should('evk_newsletter') && isset($data['evk_newsletter'])) {
        global $wpdb;

        update_option('evk_newsletter', $data['evk_newsletter']);

        $tables = [
            'evk_nl_lists'       => ['id','name','fields_config','status','created_at'],
            'evk_nl_subscribers' => ['id','list_id','email','fields_json','status','token','subscribed_at','unsubscribed_at'],
            'evk_nl_templates'   => ['id','name','subject','body_html','attachments_json','created_at','updated_at'],
            'evk_nl_campaigns'   => ['id','name','template_id','lists_json','status','scheduled_at','batch_size','batch_interval','tracking_enabled','created_at'],
            'evk_nl_queue'       => ['id','campaign_id','subscriber_id','status','attempts','sent_at','opened_at','error_message'],
            'evk_nl_logs'        => ['id','campaign_id','event','subscriber_id','data_json','created_at'],
        ];

        foreach ($tables as $table_key => $columns) {
            $table = $wpdb->prefix . $table_key;
            $rows  = $data[$table_key] ?? [];
            if (empty($rows) || !is_array($rows)) continue;

            // Wyczyść tabelę przed importem
            $wpdb->query("TRUNCATE TABLE $table");

            foreach ($rows as $row) {
                // Filtruj tylko znane kolumny
                $clean = array_intersect_key($row, array_flip($columns));
                if (!empty($clean)) {
                    $wpdb->insert($table, $clean);
                }
            }
        }

        $imported++;
    }

    // Przebuduj cache TL
    if (function_exists('tl_invalidate_cache'))    tl_invalidate_cache();
    if (function_exists('tl_flush_rewrite_rules')) tl_flush_rewrite_rules();

    wp_send_json_success('Zaimportowano ' . $imported . ' modułów — odśwież stronę.');
});

add_action('wp_ajax_tl_inline_get', function () {
    check_ajax_referer('tl_inline_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnien.');
    $pl = sanitize_textarea_field(wp_unslash($_POST['pl'] ?? ''));
    if (!$pl) wp_send_json_error('Brak frazy.');
    $dd_keys = get_option('tl_dd_keys', []);
    $existing_key = '';
    foreach ($dd_keys as $key => $phrase) {
        if ($phrase === $pl) { $existing_key = $key; break; }
    }
    $config = get_translation_config();
    if (isset($config['strings'][$pl])) {
        $meta      = $config['meta'][$pl] ?? [];
        $parent_pl = $meta['parent_pl'] ?? $pl;
        if ($parent_pl !== $pl && isset($config['strings'][$parent_pl])) {
            wp_send_json_success(['pl' => $parent_pl, 'translations' => $config['strings'][$parent_pl], 'type' => 'database', 'dd_key' => $existing_key]);
        }
        wp_send_json_success(['pl' => $pl, 'translations' => $config['strings'][$pl], 'type' => 'database', 'dd_key' => $existing_key]);
    }
    $inline = tl_get_inline_phrases();
    if (isset($inline[$pl])) {
        wp_send_json_success(['pl' => $pl, 'translations' => $inline[$pl]['translations'], 'type' => 'inline', 'raw' => $inline[$pl]['raw'] ?? '', 'dd_key' => $existing_key]);
    }
    wp_send_json_success(['pl' => $pl, 'translations' => [], 'type' => 'new', 'dd_key' => $existing_key]);
});

add_action('wp_ajax_tl_inline_save_full', function () {
    check_ajax_referer('tl_inline_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnien.');
    $old_pl           = sanitize_textarea_field(wp_unslash($_POST['old_pl'] ?? ''));
    $pl               = sanitize_textarea_field(wp_unslash($_POST['pl'] ?? ''));
    $translations_raw = isset($_POST['translations']) ? wp_unslash($_POST['translations']) : '';
    $translations     = json_decode($translations_raw, true);
    $dd_key           = sanitize_key(wp_unslash($_POST['dd_key'] ?? ''));
    $group_id         = sanitize_key(wp_unslash($_POST['group_id'] ?? ''));
    if (!$pl) wp_send_json_error('Brak frazy PL.');
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($translations)) wp_send_json_error('Nieprawidlowy JSON tlumaczen.');
    $lookup_pl = $old_pl ?: $pl;
    $codes     = tl_get_active_lang_codes();
    $data      = get_option('tl_translations', ['groups' => []]);
    $found     = false;
    foreach ($data['groups'] as &$group) {
        foreach ($group['rows'] as &$row) {
            if (trim($row['pl'] ?? '') === $lookup_pl) {
                $row['pl'] = $pl; $row['dd_key'] = $dd_key;
                foreach ($codes as $code) { if (isset($translations[$code])) $row[$code] = sanitize_textarea_field($translations[$code]); }
                $found = true; break 2;
            }
        }
    }
    unset($group, $row);
    if (!$found) {
        if (empty($data['groups'])) $data['groups']['group_inline'] = ['name' => 'Inline Editor', 'rows' => []];
        $row_id = 'row_' . time() . '_' . wp_rand(1000, 9999);
        $new_row = ['pl' => $pl, 'dd_key' => $dd_key];
        foreach ($codes as $code) { $new_row[$code] = sanitize_textarea_field($translations[$code] ?? ''); }
        $target_group = ($group_id && isset($data['groups'][$group_id])) ? $group_id : array_key_first($data['groups']);
        $data['groups'][$target_group]['rows'][$row_id] = $new_row;
    }
    update_option('tl_translations', $data);
    $keys = get_option('tl_dd_keys', []);
    foreach ($keys as $existing_key_loop => $phrase) {
        if ($phrase === $lookup_pl && (!$dd_key || $existing_key_loop !== $dd_key)) unset($keys[$existing_key_loop]);
    }
    if ($dd_key) $keys[$dd_key] = $pl;
    update_option('tl_dd_keys', $keys);
    tl_invalidate_cache();
    wp_send_json_success(['pl' => $pl, 'old_pl' => $lookup_pl, 'created' => !$found, 'updated' => $found, 'dd_key' => $dd_key]);
});



// =========================================================================
// HELPER — zachowanie pól przełączników przy zapisie formularza
// =========================================================================

/**
 * Zwraca wartość pola przełącznika (np. 'enabled') z zachowaniem stanu.
 *
 * Formularze options.php NIE zawierają pól zarządzanych przez AJAX toggle
 * (evk_ajax_toggle) — WordPress przy zapisie grupy ustawień aktualizuje
 * wszystkie zarejestrowane opcje, więc brakujący klucz zerował przełącznik.
 * Zasada: klucz obecny w $input → sanityzuj; klucz nieobecny → zachowaj
 * aktualnie zapisaną wartość opcji. Zapis przez AJAX toggle zawsze przekazuje
 * pełną tablicę z kluczem, więc wyłączanie działa poprawnie.
 */
function evk_preserve_toggle($input, string $option, string $field = 'enabled', int $default = 0): int {
    if (is_array($input) && array_key_exists($field, $input)) {
        return !empty($input[$field]) ? 1 : 0;
    }
    $current = get_option($option, null);
    if (is_array($current) && array_key_exists($field, $current)) {
        return !empty($current[$field]) ? 1 : 0;
    }
    // Opcja nigdy nie zapisana — użyj domyślnej wartości modułu
    return $default;
}

// =========================================================================
// AJAX TOGGLE — uniwersalny handler włączników/wyłączników
// =========================================================================

add_action('wp_ajax_evk_ajax_toggle', function () {
    check_ajax_referer('evk-toggle-nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden');

    $option = sanitize_key($_POST['option'] ?? '');
    $field  = sanitize_key($_POST['field']  ?? '');
    $value  = absint($_POST['value'] ?? 0) ? 1 : 0;

    // Whitelist: opcja => dozwolone pola (lub '_scalar' dla flat options)
    $allowed = [
        'evk_darkmode'              => ['enabled'],
        'evk_cursor'                => ['enabled'],
        'evk_lenis'                 => ['enabled'],
        'evk_a11y'                  => ['enabled'],
        'evk_smtp'                  => ['enabled'],
        'evk_parallax'              => ['enabled'],
        'evk_schema'                => ['enabled'],
        'evk_og'                    => ['enabled'],
        'evk_fonts'                 => ['enabled'],
        'evk_animator'              => ['enabled'],
        'evk_white_label'           => ['enabled'],
        'evk_newsletter'            => ['enabled'],
        'evk_security'              => ['limit_login_enabled', 'hide_wp_version', 'rest_block_all', 'disable_bundled_themes'],
        // Scalar (flat) options
        'maintenance_mode'          => ['_scalar'],
        'evk_draft_revision_enabled'=> ['_scalar'],
        'evk_301_enabled'           => ['_scalar'],
        'evk_404_enabled'           => ['_scalar'],
        'evk_404_skip_bots'         => ['_scalar'],
        'evoke_disable_global_comments' => ['_scalar'],
        'evoke_require_reg_to_comment'  => ['_scalar'],
        'evoke_move_bricks_bottom'      => ['_scalar'],
        'evoke_dashboard_active'        => ['_scalar'],
        'evoke_dashboard_remove_native' => ['_scalar'],
        'evoke_dashboard_remove_help'   => ['_scalar'],
        'evoke_dashboard_fit_content'   => ['_scalar'],
        'evoke_dashboard_shadow'        => ['_scalar'],
        'evk_tl_module_enabled'         => ['_scalar'],
        'evk_tl_fab_enabled'             => ['_scalar'],
        'evk_forminbox'                  => ['enabled'],
        'evk_snippets_enabled'          => ['_scalar'],
        'evk_elements'                  => ['marquee', 'hscroll', 'scroll_reading', 'circular_title', 'circular_menu', 'stacking_cards', 'wave_bg'],
        'evk_cleanup'                   => ['disable_xmlrpc', 'remove_rss'],
    ];

    if (!isset($allowed[$option]) || !in_array($field, $allowed[$option])) {
        wp_send_json_error('not_allowed: ' . $option . '/' . $field);
    }

    if ($field === '_scalar') {
        update_option($option, $value ? '1' : '');
    } else {
        $current = (array) get_option($option, []);
        $current[$field] = $value;
        update_option($option, $current);
    }

    wp_send_json_success(['option' => $option, 'field' => $field, 'value' => $value]);
});

// =========================================================================
// AJAX: zapisz pojedynczą opcję (prosty klucz → wartość skalarna)
// Używany m.in. przez toggle modułu tłumaczeń.
// =========================================================================
add_action('wp_ajax_evk_save_option', function () {
    check_ajax_referer('evk_save_option', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);

    $allowed = [
        'evk_tl_module_enabled',
        'evk_tl_fab_enabled',
    ];

    $option = sanitize_key(wp_unslash($_POST['option'] ?? ''));
    if (!in_array($option, $allowed, true)) {
        wp_send_json_error('not_allowed: ' . $option, 403);
    }

    $value = !empty($_POST['value']) ? 1 : 0;
    update_option($option, $value);
    wp_send_json_success(['option' => $option, 'value' => $value]);
});
