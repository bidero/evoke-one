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
/* Strona z pełnymi źródłami (minifikacja: wstawki z komentarzami i bez nich
   porównywane token po tokenie). Przed WordPressem, który inaczej ustawi
   SCRIPT_DEBUG na false. */
if (getenv('EVK_SCRIPT_DEBUG') === '1') define('SCRIPT_DEBUG', true);
/* Brakujący plik z kropką w ostatnim członie idzie do WordPressa, jak
   „!-f, !-d” z .htaccess. Wbudowany serwer podaje index.php tylko adresom
   BEZ kropki, więc /wp-sitemap-….xml (tam WordPress przekierowuje ?sitemap=…
   przy ładnych adresach) kończył gołym 404 serwera, a nie odpowiedzią
   WordPressa (zapis-wp-strony-techniczne, mapa strony dla zalogowanego). */
$evk_sciezka = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$evk_plik = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') . $evk_sciezka;
if (strpos(basename($evk_sciezka), '.') !== false && !file_exists($evk_plik)) {
    $_SERVER['SCRIPT_NAME'] = $_SERVER['PHP_SELF'] = '/index.php';
    $_SERVER['SCRIPT_FILENAME'] = rtrim((string) $_SERVER['DOCUMENT_ROOT'], '/') . '/index.php';
    require $_SERVER['SCRIPT_FILENAME'];
    return true;
}
return false;
