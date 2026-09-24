<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Czy „Ograniczenia stron" roli (Role Manager) faktycznie blokują edycję innych stron?
$_SERVER['HTTP_HOST'] = 'stara.test';
require (getenv('EVK_WP_PATH') ?: getenv('HOME') . '/.cache/evk-testowy-wp') . '/wp-load.php';
remove_role('evk_klient_test');
add_role('evk_klient_test', 'Klient test', get_role('editor')->capabilities);
$a = wp_insert_post(['post_title' => 'Strona A', 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => 1]);
$b = wp_insert_post(['post_title' => 'Strona B', 'post_type' => 'page', 'post_status' => 'publish', 'post_author' => 1]);
$u = wp_insert_user(['user_login' => 'klient_test_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => 'evk_klient_test']);
evk_role_set_restrictions('evk_klient_test', [$a]);   // wolno TYLKO stronę A
wp_set_current_user($u);
echo "ograniczenia: " . json_encode(evk_role_get_restrictions()) . "\n";
echo "edycja strony A (dozwolonej):   " . var_export(current_user_can('edit_post', $a), true) . "\n";
echo "edycja strony B (zabronionej):  " . var_export(current_user_can('edit_post', $b), true) . "   ← powinno być false\n";
echo "edit_page B:                    " . var_export(current_user_can('edit_page', $b), true) . "\n";
echo "usunięcie strony B:             " . var_export(current_user_can('delete_post', $b), true) . "\n";
// sprzątanie
wp_set_current_user(1);
wp_delete_post($a, true); wp_delete_post($b, true);
require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($u);
evk_role_set_restrictions('evk_klient_test', []); remove_role('evk_klient_test');
