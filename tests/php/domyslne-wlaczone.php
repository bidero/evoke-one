<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Pola zaznaczenia domyślnie WŁĄCZONE — czy domyślna naprawdę obowiązuje.
 *
 * REGUŁA, KTÓRA TO SPRAWDZA, JEST OGÓLNA I NIE ZNA ŻADNEGO ELEMENTU Z NAZWY:
 *
 *     jeśli kontrolka ma 'type' => 'checkbox' i 'default' => true,
 *     to render() z PUSTYMI ustawieniami musi dać DOKŁADNIE TO SAMO,
 *     co render() z tą jedną kontrolką ustawioną na true.
 *
 * Bo to znaczy dokładnie tyle, co „domyślna obowiązuje". Nie trzeba przy tym
 * wiedzieć, w jaki atrybut dana kontrolka pisze — a właśnie ta wiedza robi ze
 * sprawdzeń rzecz pisaną osobno dla każdego elementu i zapominaną przy nowych.
 *
 * CO TO ŁAPIE. Odczyt `! empty( $s['klucz'] )` przy domyślnej WŁĄCZONEJ: Bricks
 * przy nietkniętym elemencie nie ma klucza w ustawieniach, `! empty()` daje
 * wtedy `false` i zadeklarowane `'default' => true` nie obowiązuje. Z panelu
 * buildera wygląda to normalnie — pole jest zaznaczone.
 *
 * Wyjście: lista kontrolek, przy których oba wyjścia się różnią.
 */
require __DIR__ . '/_wp-stubs.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
require __DIR__ . '/_bricks-stubs.php';
require EVK_TEST_ROOT . '/includes/bricks-elements/flaga.php';

define('EVK_BRICKS_CATEGORY', 'evoke-one');
if (!defined('EVOKE_ONE_URL')) define('EVOKE_ONE_URL', '/');

/** Ładuje element i oddaje nazwę klasy, która przez to powstała. */
function zaladuj(string $plik): ?string {
    $przed = get_declared_classes();
    require_once $plik;
    foreach (array_values(array_diff(get_declared_classes(), $przed)) as $k) {
        if (is_subclass_of($k, 'Bricks\\Element')) { return $k; }
    }
    return null;
}

/** Wyjście render() dla podanych ustawień — świeży obiekt za każdym razem. */
function wyjscie(string $klasa, array $ustawienia): string {
    $el = new $klasa();
    $el->settings = $ustawienia;
    ob_start();
    try { $el->render(); } catch (\Throwable $e) { ob_end_clean(); return 'WYJĄTEK: ' . $e->getMessage(); }
    return (string) ob_get_clean();
}

$rozjazdy = [];
$zbadanych = 0;
$elementow = 0;
$pominiete = [];

foreach (glob(EVK_TEST_ROOT . '/includes/bricks-elements/*/element.php') as $plik) {
    $nazwa = basename(dirname($plik));
    $klasa = zaladuj($plik);
    if (!$klasa) { $pominiete[] = $nazwa . ' (klasa się nie załadowała)'; continue; }

    $el = new $klasa();
    $el->set_controls();
    $elementow++;

    /* Element musi dać STABILNE wyjście dla tych samych ustawień — inaczej
       porównanie dwóch wyjść nie znaczy nic. Losowy identyfikator albo znacznik
       czasu w znaczniku dyskwalifikuje element z tego sprawdzenia, i lepiej
       powiedzieć to wprost, niż cicho go przepuścić. */
    if (wyjscie($klasa, []) !== wyjscie($klasa, [])) {
        $pominiete[] = $nazwa . ' (render nie jest powtarzalny)';
        continue;
    }

    foreach ($el->controls as $klucz => $def) {
        if (!is_array($def)) { continue; }
        if (($def['type'] ?? '') !== 'checkbox') { continue; }
        if (($def['default'] ?? null) !== true) { continue; }

        $zbadanych++;
        $puste     = wyjscie($klasa, []);
        $zWlaczona = wyjscie($klasa, [ $klucz => true ]);
        if ($puste !== $zWlaczona) {
            $rozjazdy[] = $nazwa . '/' . $klucz;
        }
    }
}

echo json_encode([
    'elementow'  => $elementow,
    'zbadanych'  => $zbadanych,
    'rozjazdy'   => $rozjazdy,
    'pominiete'  => $pominiete,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
