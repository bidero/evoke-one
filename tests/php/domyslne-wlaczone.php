<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Pola zaznaczenia domyślnie WŁĄCZONE — czy domyślna naprawdę obowiązuje.
 *
 * DWIE REGUŁY, OBIE OGÓLNE I NIEZNAJĄCE ŻADNEGO ELEMENTU Z NAZWY. Dla każdej
 * kontrolki z 'type' => 'checkbox' i 'default' => true:
 *
 *   1. DOMYŚLNA OBOWIĄZUJE
 *      render([]) === render([klucz => true])
 *
 *   2. DA SIĘ JĄ WYŁĄCZYĆ
 *      render([klucz => null]) === render([klucz => false])
 *
 * Nie trzeba przy tym wiedzieć, w jaki atrybut dana kontrolka pisze — a właśnie
 * ta wiedza robi ze sprawdzeń rzecz pisaną osobno dla każdego elementu
 * i zapominaną przy nowych.
 *
 * CO ŁAPIE REGUŁA 1. Odczyt `! empty( $s['klucz'] )` przy domyślnej WŁĄCZONEJ:
 * Bricks przy nietkniętym elemencie nie ma klucza w ustawieniach, `! empty()`
 * daje wtedy `false` i zadeklarowane `'default' => true` nie obowiązuje.
 * Z panelu buildera wygląda to normalnie — pole jest zaznaczone.
 *
 * CO ŁAPIE REGUŁA 2 — i dlaczego jej brak kosztował zgłoszenie. Do 1.213.0
 * stała tu wyłącznie reguła 1, czyli sprawdzana była POŁOWA UMOWY: że domyślna
 * działa. Nikt nie pytał, czy da się ją zdjąć. Stacking Cards czytał swoje dwa
 * pola tak:
 *
 *     'shadow' => ! isset( $s['shadow'] ) || ! empty( $s['shadow'] ),
 *
 * i przechodził regułę 1 na zielono, będąc NIE DO WYŁĄCZENIA. Zgłoszone
 * z użycia: „nie działa wyłączanie cienia kart. Zawsze się wyświetla".
 *
 * Sedno to `isset()` wobec `array_key_exists()`. `isset()` oddaje `false` dla
 * wartości `null`, więc pierwszy człon zapala się przy ODZNACZONYM polu
 * zapisanym jako `null` i wraca domyślna — włączona. `evk_flaga()` pyta
 * `array_key_exists()`, które dla `null` oddaje `true`, i dopiero wtedy
 * sprawdza `! empty()`. Dlatego reguła 2 porównuje właśnie `null` z `false`:
 * to jedyna para, która te dwa odczyty rozróżnia.
 *
 * Wyjście: listy kontrolek, przy których wyjścia się różnią — osobno dla obu
 * reguł, bo to dwie różne usterki i dwie różne naprawy.
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
$nieDoWylaczenia = [];
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

        // Reguła 1: domyślna obowiązuje.
        $puste     = wyjscie($klasa, []);
        $zWlaczona = wyjscie($klasa, [ $klucz => true ]);
        if ($puste !== $zWlaczona) {
            $rozjazdy[] = $nazwa . '/' . $klucz;
        }

        /* Reguła 2: da się ją wyłączyć. `null` to postać, w jakiej odznaczenie
           potrafi trafić do ustawień — i jedyna, którą `isset()` myli z brakiem
           klucza. Porównujemy z `false`, bo „odznaczone" ma znaczyć to samo
           niezależnie od tego, jak zostało zapisane. */
        $jakoNull  = wyjscie($klasa, [ $klucz => null ]);
        $jakoFalse = wyjscie($klasa, [ $klucz => false ]);
        if ($jakoNull !== $jakoFalse) {
            $nieDoWylaczenia[] = $nazwa . '/' . $klucz;
        }
    }
}

echo json_encode([
    'elementow'  => $elementow,
    'zbadanych'  => $zbadanych,
    'rozjazdy'   => $rozjazdy,
    'nieDoWylaczenia' => $nieDoWylaczenia,
    'pominiete'  => $pominiete,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
