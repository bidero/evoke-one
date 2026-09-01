<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — TL: AJAX handlers
 */

// AJAX endpoint do pobierania wszystkich slugów stron/postów
add_action('wp_ajax_tl_get_all_slugs', function() {
    evk_tl_ajax_check();

    $slugs = [];

    // Pobierz strony
    $pages = get_posts([
        'post_type' => 'page',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'fields' => 'ids'
    ]);

    foreach ($pages as $page_id) {
        $post = get_post($page_id);
        if ($post && $post->post_name) {
            $slugs[] = $post->post_name;
        }
    }

    // Pobierz posty
    $posts = get_posts([
        'post_type' => 'post',
        'posts_per_page' => 100,
        'post_status' => 'publish',
        'fields' => 'ids'
    ]);

    foreach ($posts as $post_id) {
        $post = get_post($post_id);
        if ($post && $post->post_name) {
            $slugs[] = $post->post_name;
        }
    }

    $slugs = array_unique($slugs);
    sort($slugs);

    wp_send_json_success($slugs);
});

// admin JS — dołączany przez osobny hook żeby nie mieszać z PHP render
