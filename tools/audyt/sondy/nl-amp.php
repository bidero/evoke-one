<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Czy śledzenie kliknięć zachowuje parametry linku zapisanego poprawnie jako &amp; (tak zapisuje TinyMCE)?
$_SERVER['HTTP_HOST'] = 'stara.test';
require (getenv('EVK_WP_PATH') ?: getenv('HOME') . '/.cache/evk-testowy-wp') . '/wp-load.php';
$home = home_url('/');
$html = '<p><a href="' . $home . 'oferta/?utm_source=nl&amp;utm_medium=email&amp;utm_campaign=wrzesien">Oferta</a></p>';
$out  = evk_nl_inject_tracking($html, 'TOKEN123', 7);
preg_match('/href="([^"]+)"/', $out, $m);
$link = html_entity_decode($m[1]);                 // tak, jak przeglądarka odczyta atrybut
parse_str(parse_url($link, PHP_URL_QUERY), $q);
echo "link w mailu:           " . $link . "\n";
echo "parametr url (cel):     " . $q['url'] . "\n";
// co zrobi uchwyt kliknięcia
$_GET = ['url' => wp_slash($q['url']), 'sig' => $q['sig']];
$cel = evk_nl_click_target(esc_url_raw(wp_unslash($_GET['url'])), $q['sig'], 7);
echo "przekierowanie na:      " . $cel . "\n";
parse_str(parse_url($cel, PHP_URL_QUERY), $p);
echo "parametry u celu:       " . json_encode(array_keys($p)) . "\n";
