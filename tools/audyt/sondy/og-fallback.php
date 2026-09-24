<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Co trafia do og:image dla strony, która nie ma jeszcze wygenerowanego obrazka OG?
$_SERVER['HTTP_HOST'] = 'stara.test';
require (getenv('EVK_WP_PATH') ?: getenv('HOME') . '/.cache/evk-testowy-wp') . '/wp-load.php';
$s = get_option('evk_og', []); $s['enabled'] = 1; update_option('evk_og', $s);
$id = wp_insert_post(['post_title' => 'Stara strona', 'post_type' => 'page', 'post_status' => 'publish']);
delete_post_meta($id, '_evk_og_url');           // jak strona sprzed włączenia generatora
$m = evk_seo_get_meta($id);
echo "og:image = " . $m['og_image'] . "\n";
$sciezka = str_replace(home_url('/'), ABSPATH, $m['og_image']);
echo "plik istnieje: " . (file_exists($sciezka) ? 'TAK' : 'NIE') . "\n";
wp_delete_post($id, true);
