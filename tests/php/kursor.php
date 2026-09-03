<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Skrypt kursora — wypisany przez PRAWDZIWY moduł.
 *
 * Silnik kursora jest generowany w PHP i wstawiany na stronę jako kod przy
 * `evk-gsap`. Fixture przeglądarkowy wstrzykuje dokładnie to, co zwróci ten
 * plik, zamiast trzymać kopię: kopia rozjechałaby się przy pierwszej zmianie
 * i sprawdzenie „wygrywa ostatnia reguła" pilnowałoby wtedy testu, nie wtyczki.
 *
 *   php tests/php/kursor.php '[{"selector":"a","size":80}, ...]'
 */
function evk_register_gsap_libs() {}

require __DIR__ . '/_wp-stubs.php';

$reguly = json_decode($argv[1] ?? '[]', true) ?: [];

$elements = [];
foreach ($reguly as $r) {
    $elements[] = array_merge([
        'selector' => 'a', 'size' => 60, 'text' => '', 'backgroundColor' => '',
        'cursorBlendMode' => '', 'cursorBackdropFilter' => '', 'textBlendMode' => '',
        'textColor' => '', 'arrows' => 0, 'invert' => 0,
    ], $r);
}

$GLOBALS['options']['evk_cursor'] = ['enabled' => 1, 'elements' => $elements];

require EVK_TEST_ROOT . '/includes/94-cursor.php';

EVK_Cursor::get_instance()->enqueue_assets();

/* Kod bierzemy z tego, co moduł PODAŁ przez `wp_add_inline_script` — tak samo,
   jak dostaje go strona. Wołanie prywatnej metody z ręki pomijałoby pytanie,
   czy moduł w ogóle ten skrypt komukolwiek oddaje. */
foreach ($GLOBALS['inline'] ?? [] as $wpis) echo $wpis['data'];
