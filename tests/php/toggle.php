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
 * tautologią po tym, jak lista zaczęła powstawać z rejestru. Wołanie uchwytu
 * ma nad porównaniem jeszcze jedną przewagę, zmierzoną mutacją: łapie też
 * uchwyt, który białej listy w ogóle nie czyta.
 *
 * Bez argumentu sprawdza elementy Bricksa z rejestru. Z argumentem — dowolne
 * pary „opcja/pole" podane jako JSON, czym posługuje się tests/admin-tabs:
 * wyciąga z panelu WSZYSTKIE `data-option`/`data-field` i pyta uchwyt, czy
 * je przyjmie. Sierotki (1.147.0) wpadły dokładnie w tę dziurę: moduł, panel
 * i testy panelu były w porządku, a przełącznik nie działał.
 *
 *   php tests/php/toggle.php '[["evk_lenis","enabled"], ...]'
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

/** Pyta prawdziwy uchwyt, czy przyjmie tę parę. */
function evk_test_toggle(callable $toggle, string $option, string $field): string {
    $_POST = ['option' => $option, 'field' => $field, 'value' => '1', 'nonce' => 'x'];
    try {
        $toggle();
        return 'brak odpowiedzi';
    } catch (EVK_Test_Json $e) {
        $d = $e->payload ?? [];
        return !empty($d['success']) ? 'ok' : ('ODRZUCONY: ' . json_encode($d['data'] ?? null));
    }
}

$pary = json_decode($argv[1] ?? '', true);

if (is_array($pary) && $pary) {
    $out = [];
    foreach ($pary as $para) {
        $klucz = $para[0] . '/' . $para[1];
        $out[$klucz] = evk_test_toggle($toggle, (string)$para[0], (string)$para[1]);
    }
    echo json_encode(['elements' => array_keys($out), 'result' => $out],
        JSON_UNESCAPED_UNICODE), "\n";
    exit;
}

$out = [];
foreach (array_keys(evk_elements_registry()) as $key) {
    $out[$key] = evk_test_toggle($toggle, 'evk_elements', $key);
}

echo json_encode(['elements' => array_keys(evk_elements_registry()), 'result' => $out],
    JSON_UNESCAPED_UNICODE), "\n";
