<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Opisy i sekcje kontrolek — kształt panelu buildera.
 *
 * ZGŁOSZONE Z UŻYCIA: „trzeba uporządkować opisy w moich elementach bricks —
 * sensowne krótkie informacje, sensowniejsze ułożenie sekcji. Ogólnie dużo tam
 * tekstu w circular czy offcanvas".
 *
 * PANELU BUILDERA NIE DA SIĘ TU ZOBACZYĆ — to osobna aplikacja Vue w oknie
 * Bricksa, a testy chodzą po froncie. Widać go wyłącznie stąd: z kształtu
 * tablicy, którą wpisuje `set_controls()`.
 *
 * Mierzone są trzy rzeczy:
 *  · długość NAJDŁUŻSZEGO opisu w elemencie — po to, żeby tekst mógł już tylko
 *    ubywać, tak jak wpisy w pliku bazowym PHPStana;
 *  · sekcje puste, czyli separator, pod którym nie ma ani jednej kontrolki;
 *  · liczba separatorów, bo „zero pustych sekcji" jest prawdą także wtedy,
 *    gdy sekcji nie ma wcale.
 */
require __DIR__ . '/_wp-stubs.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
require __DIR__ . '/_bricks-stubs.php';
require EVK_TEST_ROOT . '/includes/bricks-elements/flaga.php';

define('EVK_BRICKS_CATEGORY', 'evoke-one');

/** Ładuje element i oddaje nazwę klasy, która przez to powstała. */
function zaladuj(string $plik): ?string {
    $przed = get_declared_classes();
    require_once $plik;
    foreach (array_values(array_diff(get_declared_classes(), $przed)) as $k) {
        if (is_subclass_of($k, 'Bricks\\Element')) { return $k; }
    }
    return null;
}

$wynik = [];
foreach (glob(EVK_TEST_ROOT . '/includes/bricks-elements/*/element.php') as $plik) {
    $klasa = zaladuj($plik);
    if (!$klasa) { continue; }
    $el = new $klasa();
    $el->set_controls();
    if (method_exists($el, 'set_control_groups')) { $el->set_control_groups(); }

    $najdluzszy = 0; $gdzie = null; $suma = 0; $opisow = 0;
    $separatorow = 0; $puste = [];
    $ostatniSeparator = null; $odOstatniego = 0;

    foreach ($el->controls as $klucz => $def) {
        if (!is_array($def)) { continue; }

        $jestSeparatorem = ($def['type'] ?? '') === 'separator';

        if ($jestSeparatorem) {
            /* Separator zamyka poprzednią sekcję. Pusta znaczy, że nagłówek
               stoi sam — w panelu wygląda to jak brakująca zawartość. */
            if ($ostatniSeparator !== null && $odOstatniego === 0) { $puste[] = $ostatniSeparator; }
            $ostatniSeparator = $klucz;
            $odOstatniego = 0;
            $separatorow++;
            /* ALE OPIS SEPARATORA LICZY SIĘ TAK SAMO. Pierwsza wersja tej sondy
               przechodziła tu do następnej kontrolki i przez to nie widziała
               notek sekcji — a w Circular Menu stała taka na 531 znaków
               („Styl zawartości"), czyli jedna z najdłuższych w całej wtyczce.
               Sufit, który nie widzi połowy miejsc, gdzie tekst może urosnąć,
               nie pilnuje niczego. */
        } else if ($ostatniSeparator !== null) {
            $odOstatniego++;
        }

        /* Kontrolka `info` i separator niosą tekst w `description` tak samo jak
           każda inna kontrolka — i tak samo się liczą, bo w panelu zajmują
           tyle samo miejsca. */
        $opis = (string) ($def['description'] ?? '');
        if ($opis === '') { continue; }
        $dl = mb_strlen($opis);
        $suma += $dl; $opisow++;
        if ($dl > $najdluzszy) { $najdluzszy = $dl; $gdzie = $klucz; }
    }
    // Ostatnia sekcja w pliku też może być pusta.
    if ($ostatniSeparator !== null && $odOstatniego === 0) { $puste[] = $ostatniSeparator; }

    $wynik[basename(dirname($plik))] = [
        'najdluzszy'  => $najdluzszy,
        'gdzie'       => $gdzie,
        'znakow'      => $suma,
        'opisow'      => $opisow,
        'separatorow' => $separatorow,
        'puste'       => $puste,
    ];
}

ksort($wynik);
echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
