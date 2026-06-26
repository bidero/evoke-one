<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Draft Revision
 * Pozwala tworzyć kopię roboczą dowolnego posta i synchronizować z oryginałem.
 * Adapted from SNN Draft Revision System.
 */

function evk_draft_revision_is_enabled(): bool {
    return !empty(get_option('evk_draft_revision_enabled'));
}

add_action('init', function () {
    if (!evk_draft_revision_is_enabled()) return;

    // Row actions
    add_filter('post_row_actions', 'evk_dr_row_actions', 10, 2);
    add_filter('page_row_actions', 'evk_dr_row_actions', 10, 2);

    // Admin notices
    add_action('admin_notices', 'evk_dr_notices');
});

function evk_dr_row_actions(array $actions, \WP_Post $post): array {
    if (!current_user_can('edit_post', $post->ID)) return $actions;

    $original_id = get_post_meta($post->ID, '_evk_original_post_id', true);

    if ($original_id && get_post($original_id)) {
        $url = wp_nonce_url(
            admin_url('admin.php?action=evk_sync_revision&post=' . $post->ID),
            'evk_sync_' . $post->ID
        );
        $actions['evk_sync'] = '<a href="' . esc_url($url) . '" style="color:#16a34a;font-weight:bold;">Synchronizuj z oryginałem</a>';
    } else {
        $url = wp_nonce_url(
            admin_url('admin.php?action=evk_create_revision&post=' . $post->ID),
            'evk_create_revision_' . $post->ID
        );
        $actions['evk_revision'] = '<a href="' . esc_url($url) . '" style="color:#2563eb;font-weight:bold;">Utwórz wersję roboczą</a>';
    }
    return $actions;
}

// Utwórz kopię roboczą
add_action('admin_action_evk_create_revision', function () {
    $post_id = absint($_GET['post'] ?? 0);
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'evk_create_revision_' . $post_id)) wp_die('Błąd bezpieczeństwa.');
    if (!current_user_can('edit_post', $post_id)) wp_die('Brak uprawnień.');

    $orig = get_post($post_id);
    if (!$orig) wp_die('Post nie istnieje.');

    $new_id = wp_insert_post([
        'post_author'  => get_current_user_id(),
        'post_content' => $orig->post_content,
        'post_title'   => $orig->post_title . ' (Wersja robocza)',
        'post_excerpt' => $orig->post_excerpt,
        'post_status'  => 'draft',
        'post_type'    => $orig->post_type,
    ]);

    if (is_wp_error($new_id)) wp_die($new_id->get_error_message());

    // Kopiuj meta
    foreach (get_post_meta($orig->ID) as $key => $values) {
        if ($key === '_evk_original_post_id') continue;
        foreach ($values as $val) add_post_meta($new_id, $key, maybe_unserialize($val));
    }
    update_post_meta($new_id, '_evk_original_post_id', $post_id);

    // Kopiuj featured image
    $thumb = get_post_thumbnail_id($orig->ID);
    if ($thumb) set_post_thumbnail($new_id, $thumb);

    // Kopiuj taksonomie
    foreach (get_object_taxonomies($orig->post_type) as $tax) {
        $terms = wp_get_object_terms($orig->ID, $tax, ['fields' => 'ids']);
        if (!is_wp_error($terms) && $terms) wp_set_object_terms($new_id, $terms, $tax);
    }

    wp_redirect(add_query_arg(['post' => $new_id, 'action' => 'edit', 'evk_rev_created' => 1], admin_url('post.php')));
    exit;
});

// Synchronizuj z oryginałem
add_action('admin_action_evk_sync_revision', function () {
    $draft_id = absint($_GET['post'] ?? 0);
    if (!wp_verify_nonce($_GET['_wpnonce'] ?? '', 'evk_sync_' . $draft_id)) wp_die('Błąd bezpieczeństwa.');
    if (!current_user_can('edit_post', $draft_id)) wp_die('Brak uprawnień.');

    $draft       = get_post($draft_id);
    $original_id = get_post_meta($draft_id, '_evk_original_post_id', true);
    $original    = get_post($original_id);

    if (!$draft || !$original) wp_die('Post nie istnieje.');
    if (!current_user_can('edit_post', $original_id)) wp_die('Brak uprawnień do edycji oryginału.');

    // Aktualizuj oryginał
    wp_update_post([
        'ID'           => $original_id,
        'post_content' => $draft->post_content,
        'post_title'   => str_replace(' (Wersja robocza)', '', $draft->post_title),
        'post_excerpt' => $draft->post_excerpt,
    ]);

    // Zastąp meta
    foreach (get_post_meta($original_id) as $key => $vals) {
        if (!in_array($key, ['_edit_lock', '_edit_last'], true)) delete_post_meta($original_id, $key);
    }
    foreach (get_post_meta($draft_id) as $key => $vals) {
        if (in_array($key, ['_evk_original_post_id', '_edit_lock', '_edit_last'], true)) continue;
        foreach ($vals as $val) add_post_meta($original_id, $key, maybe_unserialize($val));
    }

    // Featured image
    $thumb = get_post_thumbnail_id($draft_id);
    $thumb ? set_post_thumbnail($original_id, $thumb) : delete_post_thumbnail($original_id);

    // Taksonomie
    foreach (get_object_taxonomies($original->post_type) as $tax) {
        $terms = wp_get_object_terms($draft_id, $tax, ['fields' => 'ids']);
        if (!is_wp_error($terms)) wp_set_object_terms($original_id, $terms, $tax);
    }

    wp_trash_post($draft_id);
    wp_redirect(add_query_arg(['post' => $original_id, 'action' => 'edit', 'evk_synced' => 1], admin_url('post.php')));
    exit;
});

function evk_dr_notices(): void {
    if (!empty($_GET['evk_rev_created'])):
        echo '<div class="notice notice-success is-dismissible"><p><strong>Wersja robocza utworzona.</strong> Edytuj ją, a gdy będzie gotowa kliknij "Synchronizuj z oryginałem".</p></div>';
    endif;
    if (!empty($_GET['evk_synced'])):
        echo '<div class="notice notice-success is-dismissible"><p><strong>Synchronizacja zakończona.</strong> Zmiany z wersji roboczej zostały przeniesione do oryginału.</p></div>';
    endif;
}
