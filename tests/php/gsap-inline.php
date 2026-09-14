<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Skrypty inline rejestrowane razem z bibliotekami GSAP.
 *
 * Bez argumentu: skrypt dopinany do ScrollTriggera (konfiguracja + evkOdswiez) —
 * tak, jak czyta go tests/odswiezanie.test.js.
 *
 * Z argumentem `json`: CAŁA kolejka inline jako JSON, razem z uchwytem
 * i pozycją. Pozycja jest tu treścią, nie ozdobą: `gsap.ticker` istnieje
 * dopiero PO wykonaniu gsap.min.js, więc ustawienie wygładzania długich klatek
 * musi iść jako `after`. Wydrukowane `before` wykonałoby się na pustym oknie
 * i po cichu nic nie zrobiło.
 */
require __DIR__ . '/_wp-stubs.php';

define('EVOKE_ONE_URL',     'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', 'test');

require EVK_TEST_ROOT . '/includes/89-gsap.php';

evk_register_gsap_libs();

if (($argv[1] ?? '') === 'json') {
    echo json_encode($GLOBALS['inline'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}

foreach ($GLOBALS['inline'] as $item) {
    if ($item['handle'] === 'evk-scrolltrigger') echo $item['data'];
}
