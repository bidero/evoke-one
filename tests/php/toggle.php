<?php
// Tylko z wiersza poleceń — patrz tab.php.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Przełącznik AJAX dla KAŻDEGO elementu z rejestru.
 *
 * Handler `evk_ajax_toggle` ma własną białą listę „opcja => dozwolone pola".
 * To drugie miejsce, w którym trzeba pamiętać o nowym elemencie — i dokładnie
 * na tym poległ Offcanvas Menu w 1.56.0: element był w rejestrze, miał
 * włącznik w panelu, a przełącznik odbijał się od whitelisty z komunikatem
 * `not_allowed`. Widoczne dopiero z użycia, bo panel rysował się poprawnie.
 *
 * Test woła PRAWDZIWY handler, nie porównuje dwóch list — porównanie byłoby
 * tautologią po tym, jak lista zaczęła powstawać z rejestru.
 */
require __DIR__ . '/_wp-stubs.php';

// Loader buduje ścieżki z tych stałych; bez nich rejestr nie powstanie.
define('EVOKE_ONE_DIR', EVK_TEST_ROOT . '/');
define('EVOKE_ONE_URL', 'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', '0.0.0-test');

require EVK_TEST_ROOT . '/includes/bricks-elements/loader.php';
require EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';

$toggle = null;
foreach ($GLOBALS['hooks']['wp_ajax_evk_ajax_toggle'] ?? [] as $cb) $toggle = $cb;

$out = [];
foreach (array_keys(evk_elements_registry()) as $key) {
    $_POST = ['option' => 'evk_elements', 'field' => $key, 'value' => '1', 'nonce' => 'x'];
    try {
        $toggle();
        $out[$key] = 'brak odpowiedzi';
    } catch (EVK_Test_Json $e) {
        $d = $e->payload ?? [];
        $out[$key] = !empty($d['success']) ? 'ok' : ('ODRZUCONY: ' . json_encode($d['data'] ?? null));
    }
}

echo json_encode(['elements' => array_keys(evk_elements_registry()), 'result' => $out],
    JSON_UNESCAPED_UNICODE), "\n";
