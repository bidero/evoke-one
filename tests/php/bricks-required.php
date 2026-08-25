<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Warunki widoczności kontrolek — WSZYSTKIE elementy Bricksa naraz.
 *
 * Bricks nie obsługuje ŁAŃCUCHÓW w `required`: warunek złożony z dwóch członów
 * sprawia, że kontrolka nie pokazuje się wcale. Zjadło to najpierw repeater
 * marquee (1.103.1), a potem czternaście pól wskaźnika w Horizontal Scroll
 * (1.107.0) — za drugim razem dlatego, że pierwszy wniosek był za wąski
 * i sprawdzenie pilnowało tylko jednego repeatera.
 *
 * Tego NIE DA SIĘ zobaczyć ani w znaczniku, ani w przeglądarce: pole po prostu
 * nie pojawia się w panelu buildera, a strona wygląda normalnie. Widać to
 * dopiero stąd — po kształcie tablicy.
 *
 * Człony liczone są PO ROZWINIĘCIU w PHP, nie po przecinkach w źródle: burger
 * ma `[ 'style', '=', array_keys( array_filter( … ) ) ]`, czyli jeden warunek
 * z tablicą w trzecim członie, i to jest forma poprawna — jedyna, jaką
 * dokumentacja Bricksa opisuje dla alternatywy.
 */
require __DIR__ . '/_wp-stubs.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
require __DIR__ . '/_bricks-stubs.php';

define('EVK_BRICKS_CATEGORY', 'evoke-one');

/** Ładuje element i oddaje nazwę klasy, która przez to powstała. */
function zaladuj(string $plik): ?string {
    $przed = get_declared_classes();
    require_once $plik;
    $nowe = array_values(array_diff(get_declared_classes(), $przed));
    foreach ($nowe as $k) {
        if (is_subclass_of($k, 'Bricks\\Element')) { return $k; }
    }
    return null;
}

/**
 * Warunki jednej tablicy kontrolek — razem z polami wierszy repeaterów.
 * Oddaje listę `[element, kontrolka, ile członów, warunek]`.
 */
function warunki(string $element, array $controls, string $prefiks = ''): array {
    $out = [];
    // Warunek wolno wskazać tylko pole z TEJ SAMEJ przestrzeni: kontrolka
    // najwyższego poziomu widzi kontrolki, pole wiersza — pola tego wiersza.
    $rodzenstwo = array_keys($controls);

    foreach ($controls as $nazwa => $def) {
        if (!is_array($def)) { continue; }

        if (isset($def['required']) && is_array($def['required'])) {
            $out[] = [
                'element'  => $element,
                'pole'     => $prefiks . $nazwa,
                'czlonow'  => count($def['required']),
                'warunek'  => $def['required'],
                'wskazuje' => $def['required'][0] ?? null,
                'istnieje' => in_array($def['required'][0] ?? '', $rodzenstwo, true),
            ];
        }
        // Repeater niesie własną przestrzeń nazw — te same reguły obowiązują
        // w środku.
        if (!empty($def['fields']) && is_array($def['fields'])) {
            $out = array_merge($out, warunki($element, $def['fields'], $prefiks . $nazwa . '/'));
        }
    }
    return $out;
}

$wszystkie = [];
$plikow = 0;
$niezaladowane = [];
foreach (glob(EVK_TEST_ROOT . '/includes/bricks-elements/*/element.php') as $plik) {
    $klasa = zaladuj($plik);
    if (!$klasa) { $niezaladowane[] = basename(dirname($plik)); continue; }
    $plikow++;
    $el = new $klasa();
    $el->set_controls();
    if (method_exists($el, 'set_control_groups')) { $el->set_control_groups(); }
    $wszystkie = array_merge($wszystkie, warunki(basename(dirname($plik)), $el->controls));
}

// Łańcuch = więcej niż trzy człony najwyższego poziomu.
$lancuchy = array_values(array_filter($wszystkie, fn($w) => $w['czlonow'] > 3));

// Warunki wskazujące pole, którego w tym samym elemencie nie ma, to osobna
// klasa cichej usterki — kontrolka też się wtedy nie pokaże.
// Warunek wskazujący nieistniejące pole to druga klasa cichej usterki:
// kontrolka też się wtedy nie pokaże, a w źródle wygląda poprawnie.
$wiszace = array_values(array_filter($wszystkie, fn($w) => !$w['istnieje']));

echo json_encode([
    // Ile plików elementów naprawdę weszło pod strażnika. Bez tej liczby „zero
    // łańcuchów" byłoby prawdą także wtedy, gdyby żaden element się nie załadował.
    'plikow'      => $plikow,
    'niezaladowane' => $niezaladowane,
    'elementow'   => count(array_unique(array_column($wszystkie, 'element'))),
    'warunkow'    => count($wszystkie),
    'wiszace'     => array_map(fn($w) => $w['element'] . '/' . $w['pole'] . ' → ' . $w['wskazuje'], $wiszace),
    'lancuchy'    => array_map(fn($w) => $w['element'] . '/' . $w['pole'] . ' (' . $w['czlonow'] . ')', $lancuchy),
    // Ile warunków używa tablicowej formy alternatywy — to ma być droga,
    // którą zapisuje się „którakolwiek z tych wartości".
    'tablicowe'   => count(array_filter($wszystkie, fn($w) => isset($w['warunek'][2]) && is_array($w['warunek'][2]))),
    // Wskaźnik Horizontal Scroll — pola, o które poszło zgłoszenie.
    'wskaznik'    => array_values(array_map(
        fn($w) => $w['pole'] . ': ' . json_encode($w['warunek'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        array_filter($wszystkie, fn($w) => $w['element'] === 'evoke-horizontal-scroll'
            && (str_starts_with($w['pole'], 'progressbar') || str_starts_with($w['pole'], 'seg_')
                || str_starts_with($w['pole'], 'num_') || $w['pole'] === 'current_rest')))),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
