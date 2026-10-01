<?php
/**
 * Router serwera testowego `php -S` dla testowego WordPressa — wspólny dla
 * wszystkich testów przez tests/lib/wp-serwer.js (backup-panel, zapis-wp-…).
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
/* Strona z pełnymi źródłami (minifikacja: wstawki z komentarzami i bez nich
   porównywane token po tokenie). Przed WordPressem, który inaczej ustawi
   SCRIPT_DEBUG na false. */
if (getenv('EVK_SCRIPT_DEBUG') === '1') define('SCRIPT_DEBUG', true);
/* Plik, którego nie ma, idzie do WordPressa — jak reguła „!-f, !-d"
   z .htaccess na prawdziwym serwerze. Wbudowany serwer sam podaje index.php
   tylko adresom bez kropki w ostatnim członie; adres z kropką bez pliku na
   dysku kończy gołym 404 serwera, zanim WordPress go zobaczy. Tak padała mapa
   strony: przy ładnych adresach ?sitemap=… robi 301 na /wp-sitemap-….xml,
   którego na dysku nie ma (zapis-wp-strony-techniczne). To samo dotyczy
   arkusza mapy (.xsl) i robots.txt. */
$evk_sciezka = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
if (strpos(basename($evk_sciezka), '.') !== false && !file_exists($_SERVER['DOCUMENT_ROOT'] . $evk_sciezka)) {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = $_SERVER['DOCUMENT_ROOT'] . '/index.php';
    require $_SERVER['SCRIPT_FILENAME'];
    return true;
}
return false;
