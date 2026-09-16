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
 * Mierzone jest sześć rzeczy:
 *  · długość NAJDŁUŻSZEGO opisu w elemencie — po to, żeby tekst mógł już tylko
 *    ubywać, tak jak wpisy w pliku bazowym PHPStana;
 *  · sekcje puste, czyli separator, pod którym nie ma ani jednej kontrolki;
 *  · liczba separatorów, bo „zero pustych sekcji" jest prawdą także wtedy,
 *    gdy sekcji nie ma wcale;
 *  · grupy puste — zwijany nagłówek bez zawartości to ten sam problem;
 *  · grupy wiszące, czyli `'group' => …` wskazujące coś, czego
 *    `set_control_groups()` nie zadeklarował;
 *  · **bramki przez granicę grupy** — patrz niżej.
 *
 * BRAMKA PRZEZ GRANICĘ GRUPY. W całej wtyczce nie ma ani jednego warunku
 * `required` wskazującego pole z INNEJ grupy, więc nie wiadomo, czy Bricks to
 * obsługuje. Objawem byłaby kontrolka, która po prostu się nie pokazuje, przy
 * stronie wyglądającej normalnie — ta sama klasa cichej usterki co łańcuchy
 * w `required` (1.103.1, 1.107.0). Dopóki nie ma dowodu ze strony, reguła
 * brzmi: warunek zostaje w swojej grupie.
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

    /* Grupy: zadeklarowane, użyte i przynależność każdej kontrolki. */
    $zadeklarowane = array_keys($el->control_groups ?? []);
    $wGrupie = [];
    foreach ($el->controls as $k => $d) {
        if (is_array($d) && isset($d['group'])) { $wGrupie[$k] = (string) $d['group']; }
    }
    $uzyte      = array_values(array_unique($wGrupie));
    $pusteGrupy = array_values(array_diff($zadeklarowane, $uzyte));
    $wiszaceGrupy = array_values(array_diff($uzyte, $zadeklarowane));

    /* Warunek wskazujący pole z innej grupy — albo z grupy, gdy sam jest poza
       nią, i odwrotnie. Pole spoza grup traktujemy jak jedną wspólną
       przestrzeń, bo tak zachowuje się panel: wszystko poza grupami leży na
       wierzchu, jedno pod drugim. */
    $przezGranice = [];
    foreach ($el->controls as $k => $d) {
        if (!is_array($d) || empty($d['required'][0]) || !is_string($d['required'][0])) { continue; }
        $cel = $d['required'][0];
        if (!array_key_exists($cel, $el->controls)) { continue; }   // wiszące łapie bricks-required
        $mojaGrupa  = $wGrupie[$k]   ?? '';
        $celuGrupa  = $wGrupie[$cel] ?? '';
        if ($mojaGrupa !== $celuGrupa) {
            $przezGranice[] = $k . ' [' . ($mojaGrupa ?: 'poza grupami') . '] ← '
                . $cel . ' [' . ($celuGrupa ?: 'poza grupami') . ']';
        }
    }

    /* ZLICZANIE SCHODZI W `fields`, czyli w kontrolki repeatera.
     *
     * Kontrolka `info` i separator niosą tekst w `description` tak samo jak
     * każda inna kontrolka — i tak samo się liczą, bo w panelu zajmują tyle
     * samo miejsca.
     *
     * A POLA REPEATERA NIE SIEDZĄ W `$el->controls` WCALE — leżą w `fields`
     * kontrolki nadrzędnej. Pierwsza wersja tej sondy tam nie schodziła i przez
     * to nie widziała SZEŚCIU najgęstszych opisów Marquee (jedyny element
     * z repeaterem w całej wtyczce): „Galeria to JEDEN wiersz…", „Leniwe
     * wczytywanie…", notka o wariancie `__ids`. Sufit ustawiony na 434 pilnował
     * opisów drugiego planu, a te z repeatera mogły rosnąć bez końca.
     *
     * To ta sama klasa dziury co pomijanie opisów separatorów, załatana
     * w 1.205.0: licznik, który nie widzi połowy miejsc, gdzie tekst może
     * urosnąć, nie pilnuje niczego.
     *
     * Ścieżka w `gdzie` dostaje kropkę — `items.loading` — żeby po przekroczeniu
     * sufitu było wiadomo, którego pola szukać, a nie tylko w której kontrolce. */
    $zlicz = function (array $def, string $sciezka) use (&$zlicz, &$najdluzszy, &$gdzie, &$suma, &$opisow) {
        $opis = (string) ($def['description'] ?? '');
        if ($opis !== '') {
            $dl = mb_strlen($opis);
            $suma += $dl; $opisow++;
            if ($dl > $najdluzszy) { $najdluzszy = $dl; $gdzie = $sciezka; }
        }
        if (!empty($def['fields']) && is_array($def['fields'])) {
            foreach ($def['fields'] as $k => $pole) {
                if (is_array($pole)) { $zlicz($pole, $sciezka . '.' . $k); }
            }
        }
    };

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

        $zlicz($def, $klucz);
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
        'grup'        => count($zadeklarowane),
        'pusteGrupy'  => $pusteGrupy,
        'wiszaceGrupy'=> $wiszaceGrupy,
        'przezGranice'=> $przezGranice,
    ];
}

ksort($wynik);
echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
