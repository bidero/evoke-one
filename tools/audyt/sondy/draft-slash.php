<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Czy „Utwórz wersję roboczą" i „Synchronizuj z oryginałem" zachowują ukośniki odwrotne?
$_SERVER['HTTP_HOST'] = 'stara.test'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
define('WP_ADMIN', true);
require (getenv('EVK_WP_PATH') ?: getenv('HOME') . '/.cache/evk-testowy-wp') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user(1);
update_option('evk_draft_revision_enabled', '1');
$tresc_bricks = [['id' => 'abc', 'name' => 'code', 'settings' => ['code' => "<script>var re = /\\d+/; console.log('a\\nb');</script>", 'css' => '.x:before{content:"\\f101"}']]];
$id = wp_insert_post(wp_slash(['post_title' => 'Oryginał', 'post_content' => 'Ścieżka C:\\Users\\radek', 'post_status' => 'publish', 'post_type' => 'page']));
update_post_meta($id, '_bricks_page_content_2', wp_slash($tresc_bricks));
echo "oryginał meta:    " . json_encode(get_post_meta($id, '_bricks_page_content_2', true)[0]['settings']) . "\n";
echo "oryginał treść:   " . get_post($id)->post_content . "\n";
add_filter('wp_redirect', function ($l) { throw new Exception($l); });
$_GET = $_REQUEST = ['action' => 'evk_create_revision', 'post' => $id, '_wpnonce' => wp_create_nonce('evk_create_revision_' . $id)];
try { do_action('admin_action_evk_create_revision'); } catch (Exception $e) { parse_str(parse_url($e->getMessage(), PHP_URL_QUERY), $q); $kopia = (int) $q['post']; }
echo "kopia meta:       " . json_encode(get_post_meta($kopia, '_bricks_page_content_2', true)[0]['settings']) . "\n";
echo "kopia treść:      " . get_post($kopia)->post_content . "\n";
// synchronizacja z powrotem (bez edycji kopii)
$_GET = $_REQUEST = ['action' => 'evk_sync_revision', 'post' => $kopia, '_wpnonce' => wp_create_nonce('evk_sync_' . $kopia)];
try { do_action('admin_action_evk_sync_revision'); } catch (Exception $e) {}
clean_post_cache($id);
echo "PO SYNC oryg meta:  " . json_encode(get_post_meta($id, '_bricks_page_content_2', true)[0]['settings']) . "\n";
echo "PO SYNC oryg treść: " . get_post($id)->post_content . "\n";
wp_delete_post($id, true); wp_delete_post($kopia, true);
