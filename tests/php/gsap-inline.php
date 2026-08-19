<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/** Drukuje skrypt inline dopinany do ScrollTriggera (konfiguracja + evkOdswiez). */
require __DIR__ . '/_wp-stubs.php';

define('EVOKE_ONE_URL',     'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', 'test');

require EVK_TEST_ROOT . '/includes/89-gsap.php';

evk_register_gsap_libs();

foreach ($GLOBALS['inline'] as $item) {
    if ($item['handle'] === 'evk-scrolltrigger') echo $item['data'];
}
