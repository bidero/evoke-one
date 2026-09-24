<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Czy zapis snippetu z panelu zachowuje ukośniki odwrotne w kodzie?
$_SERVER['HTTP_HOST'] = 'stara.test';
define('WP_ADMIN', true);
require (getenv('EVK_WP_PATH') ?: getenv('HOME') . '/.cache/evk-testowy-wp') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user(1);
$kod = 'echo "Linia 1\nLinia 2"; $ok = preg_match(\'/^\d{2}-\d{3}$/\', $kod_pocztowy);';
// tak, jak robi to przeglądarka + WordPress (magic quotes: $_POST jest „slashed”)
$id = evk_snippet_zapisz_wpis(['tytul' => 'Test ukośników', 'kod' => $kod, 'rodzaj' => 'php', 'miejsce' => 'head', 'wlaczony' => 0]);
echo "wysłany kod:   $kod\n";
echo "zapisany kod:  " . get_post($id)->post_content . "\n";
echo (get_post($id)->post_content === $kod ? "ZGODNY\n" : "RÓŻNY — ukośniki zjedzone\n");
wp_delete_post($id, true);
