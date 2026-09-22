<?php
/**
 * Router serwera testowego `php -S` dla testowego WordPressa (backup-panel).
 *
 * Działa WYŁĄCZNIE pod SAPI `cli-server`, które istnieje tylko we wbudowanym
 * serwerze PHP — na każdym prawdziwym serwerze (Apache, LiteSpeed, FPM) plik
 * odpowiada 403 i kończy. Bramka jest inna niż w sondach (`cli`), bo router
 * z definicji działa w żądaniu HTTP.
 *
 * Adres strony bierze z portu, na którym serwer słucha — bez zmiany opcji
 * siteurl/home w bazie, więc test nie zostawia po sobie innego adresu.
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(403); exit; }
$evk_adres = 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'];
define('WP_HOME', $evk_adres);
define('WP_SITEURL', $evk_adres);
return false;
