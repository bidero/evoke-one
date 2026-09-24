<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Snippet PHP przez Eksport → Import ustawień: czy wraca jako PHP?
$_SERVER['HTTP_HOST'] = 'stara.test';
define('WP_ADMIN', true);
require (getenv('EVK_WP_PATH') ?: getenv('HOME') . '/.cache/evk-testowy-wp') . '/wp-load.php';
wp_set_current_user(1);
$id = evk_snippet_zapisz_wpis(['tytul' => 'Klucz API', 'kod' => "add_filter('evk_test_klucz', function () { return 'sk_live_TAJNE'; });", 'rodzaj' => 'php', 'miejsce' => 'head', 'wlaczony' => 1]);
$p = get_post($id);
$eksport = ['slug' => $p->post_name, 'title' => $p->post_title, 'content' => $p->post_content];   // jak kolektor 'evk_snippets'
echo "eksport: " . json_encode($eksport, JSON_UNESCAPED_UNICODE) . "\n";
wp_delete_post($id, true);
evk_snippet_save(sanitize_key($eksport['slug']), $eksport['title'], wp_slash($eksport['content']));   // jak tl_import
foreach (evk_snippety_wszystkie(true) as $w) {
    if ($w['tytul'] !== 'Klucz API') continue;
    echo "po imporcie: rodzaj={$w['rodzaj']} miejsce={$w['miejsce']} wlaczony={$w['wlaczony']}\n";
    echo "wyjście na stronie: " . evk_snippet_wykonaj_wpis($w) . "\n";
    wp_delete_post($w['id'], true);
}
