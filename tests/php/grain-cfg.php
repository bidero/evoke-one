<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Element „Grain" — kontrolki i to, co render() wypisuje na korzeniu.
 *
 * Ustawienia jadą do skryptu ATRYBUTAMI DANYCH, nie wplecione w kod modułu.
 * Pomyłka w nazwie atrybutu daje więc pole, które zapisuje się w builderze
 * poprawnie i nie zmienia na stronie niczego — a z okna przeglądarki
 * nieustawiona kontrolka i kontrolka pisząca nie tam, gdzie trzeba, wyglądają
 * identycznie. Tę parę trzyma w zgodzie wyłącznie ten plik.
 *
 * Argument: JSON z ustawieniami elementu.
 */
require __DIR__ . '/_wp-stubs.php';
require __DIR__ . '/_bricks-stubs.php';
/* Na produkcji dociąga to loader.php — element.php nigdy nie trafia do PHP-a
   inaczej niż przez niego. Że loader NAPRAWDĘ to robi, pilnuje flaga.php. */
require EVK_TEST_ROOT . '/includes/bricks-elements/flaga.php';

define('EVK_BRICKS_CATEGORY',  'evoke');
define('EVK_GRAIN_VERSION',    'test');
define('EVK_GRAIN_URL',        'https://example.test/grain/');

require EVK_TEST_ROOT . '/includes/bricks-elements/evoke-grain/element.php';

$el = new \Evk_Grain_Element();
$el->settings = json_decode($argv[1] ?? '{}', true) ?: [];
$el->set_controls();

ob_start();
$el->render();
$html = ob_get_clean();

/* Atrybuty wyciągamy z PRAWDZIWEGO wyjścia render(), nie z tablicy atrybutów —
   liczy się to, co dojdzie do przeglądarki. */
$atrybuty = [];
if (preg_match_all('/([a-z-]+)="([^"]*)"/', $html, $m, PREG_SET_ORDER)) {
    foreach ($m as $p) $atrybuty[$p[1]] = $p[2];
}

echo json_encode([
    'kontrolki' => array_keys($el->controls),
    'typy'      => array_map(static fn($k) => $k['type'] ?? '', $el->controls),
    'bramki'    => array_map(static fn($k) => $k['required'] ?? null, $el->controls),
    'atrybuty'  => $atrybuty,
    'html'      => $html,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
