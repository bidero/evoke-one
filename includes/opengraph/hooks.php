<?php
if (!defined('ABSPATH')) exit;


// =========================================================================
// HOOKI GENEROWANIA
// =========================================================================

add_action('save_post', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

    $og_settings = evk_og_get_settings();
    if (empty($og_settings['enabled'])) return;

    // Obsługa meta box
    if (isset($_POST['_evk_og_nonce']) && wp_verify_nonce($_POST['_evk_og_nonce'], 'evk_og_meta')) {
        update_post_meta($post_id, '_evk_og_disable', !empty($_POST['evk_og_disable']) ? '1' : '0');
    }

    $force = !empty($_POST['evk_og_force_regenerate']);
    evk_og_create($post_id, $force);
});

// Generuj przy przypisaniu miniatury
add_action('updated_post_meta', 'evk_og_on_thumbnail_change', 10, 4);
add_action('added_post_meta',   'evk_og_on_thumbnail_change', 10, 4);

function evk_og_on_thumbnail_change($meta_id, $post_id, $meta_key, $meta_value): void {
    if ($meta_key === '_thumbnail_id') {
        $og_settings = evk_og_get_settings();
        if (!empty($og_settings['enabled'])) {
            evk_og_create((int)$post_id, true);
        }
    }
}

// =========================================================================
// BRICKS BUILDER — whitelist
// =========================================================================

add_filter('bricks/code/echo_functions', function ($functions) {
    $functions[] = 'evk_og_get_url';
    $functions[] = 'get_final_og_image_url';
    return $functions;
});

add_filter('bricks/code/echo_function_names', function ($functions) {
    if (!is_array($functions)) $functions = [];
    $functions[] = 'evk_og_get_url';
    $functions[] = 'get_final_og_image_url';
    return $functions;
});

add_filter('bricks/code/echo_function_whitelist', function ($allowed) {
    $allowed[] = 'evk_og_get_url';
    $allowed[] = 'get_final_og_image_url';
    return $allowed;
});

// Publiczna funkcja do użycia w Bricks / shortcode
function evk_og_get_url(int $post_id = 0): string {
    global $post;
    if (!$post_id) {
        $post_id = get_queried_object_id();
        if (!$post_id && isset($post->ID)) $post_id = $post->ID;
    }

    $s = evk_og_get_settings();
    $fallback = $s['fallback_url'] ?: home_url('/wp-content/uploads/og-fallback.jpg');

    if (!$post_id) return $fallback;

    // Jeśli wyłączony generator — użyj miniatury
    if (get_post_meta($post_id, '_evk_og_disable', true) === '1') {
        return has_post_thumbnail($post_id)
            ? get_the_post_thumbnail_url($post_id, 'full')
            : $fallback;
    }

    $url = get_post_meta($post_id, '_evk_og_url', true);
    if (!$url) return $fallback;

    $upload_dir = wp_upload_dir();
    $path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], strtok($url, '?'));
    return file_exists($path) ? $url . '?v=' . filemtime($path) : $fallback;
}

// Alias dla wstecznej kompatybilności z oryginalnym kodem
function get_final_og_image_url(): string {
    return evk_og_get_url();
}

add_shortcode('og_url', 'evk_og_get_url');

// =========================================================================
// META BOX (per post)
// =========================================================================

add_action('add_meta_boxes', function () {
    $s = evk_og_get_settings();
    $types = (array)($s['post_types'] ?? ['post']);
    foreach ($types as $pt) {
        add_meta_box('evk_og_box', 'Generator OG', 'evk_og_render_meta_box', $pt, 'side', 'default');
    }
});

function evk_og_render_meta_box(WP_Post $post): void {
    wp_nonce_field('evk_og_meta', '_evk_og_nonce');

    $is_disabled = get_post_meta($post->ID, '_evk_og_disable', true) === '1';
    $url         = get_post_meta($post->ID, '_evk_og_url', true);

    if (!has_post_thumbnail($post->ID)) {
        echo '<p style="color:#dc2626;font-size:12px;">Dodaj obrazek wyróżniający i zapisz wpis, aby wygenerować OG.</p>';
    } else {
        if ($url) {
            $preview_url = $url . '?v=' . time();
            $img_style   = $is_disabled ? 'opacity:.3;filter:grayscale(1);' : '';
            echo '<div style="background:#f0f0f0;padding:4px;border-radius:4px;margin-bottom:10px;">';
            echo '<img src="' . esc_url($preview_url) . '" style="width:100%;display:block;border-radius:2px;' . $img_style . '">';
            echo '</div>';
        }
    }

    echo '<div style="padding:10px 0;border-top:1px solid #ddd;">';

    echo '<label style="display:flex;align-items:center;gap:7px;margin-bottom:8px;cursor:pointer;font-size:13px;">';
    echo '<input type="checkbox" name="evk_og_disable" value="1" ' . checked($is_disabled, true, false) . '>';
    echo 'Użyj standardowego zdjęcia wyróżniającego';
    echo '</label>';

    echo '<label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;color:#666;">';
    echo '<input type="checkbox" name="evk_og_force_regenerate" value="1">';
    echo 'Wymuś regenerację';
    echo '</label>';

    echo '</div>';

    if ($is_disabled) {
        echo '<p style="font-size:11px;color:#dc2626;margin-top:5px;line-height:1.4;">⚠️ Generator jest pominięty. Używane jest oryginalne zdjęcie z biblioteki.</p>';
    }

    // Link do ustawień
    $settings_url = admin_url('options-general.php?page=evoke-one&tab=strona&sub=og');
    echo '<p style="font-size:11px;color:#999;margin-top:8px;"><a href="' . esc_url($settings_url) . '">Ustawienia generatora OG ↗</a></p>';
}

// =========================================================================
// AJAX: regeneruj pojedynczy post
// =========================================================================

add_action('wp_ajax_evk_og_regenerate', function () {
    check_ajax_referer('evk_og_regen', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Brak uprawnień.');

    $post_id = absint($_POST['post_id'] ?? 0);
    if (!$post_id) wp_send_json_error('Brak post ID.');

    evk_og_create($post_id, true);
    $url = evk_og_get_url($post_id);
    wp_send_json_success(['url' => $url]);
});

// =========================================================================
// AJAX: regeneruj wszystkie
// =========================================================================

add_action('wp_ajax_evk_og_regenerate_all', function () {
    check_ajax_referer('evk_og_regen', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.');

    $s     = evk_og_get_settings();
    $types = (array)($s['post_types'] ?? ['post']);

    $posts = get_posts([
        'post_type'      => $types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
    ]);

    $count = 0;
    foreach ($posts as $pid) {
        if (has_post_thumbnail((int)$pid)) {
            evk_og_create((int)$pid, true);
            $count++;
        }
    }

    wp_send_json_success(['count' => $count]);
});
