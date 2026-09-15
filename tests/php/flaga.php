<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Wspólny odczyt pól włącz/wyłącz — evk_flaga().
 *
 * Sonda woła PRAWDZIWĄ funkcję z includes/bricks-elements/flaga.php, a nie jej
 * opis. Mierzy trzy rzeczy, których nie widać nigdzie indziej:
 *
 *   · brak klucza oddaje wartość domyślną (element nietknięty w builderze),
 *   · zapis po starej liście wyboru („tak" / „nie") dalej znaczy to samo,
 *   · loader.php NAPRAWDĘ dociąga ten plik — bo sonda dociąga go sama i bez
 *     tego sprawdzenia rozjazd między sondą a produkcją byłby niewidoczny.
 */
require __DIR__ . '/_wp-stubs.php';
require EVK_TEST_ROOT . '/includes/bricks-elements/flaga.php';

/* Każdy wiersz: [ustawienia, klucz, domyślna]. Nazwy mówią, co udają. */
$przypadki = [
    'brak klucza, domyślnie włączone'   => [ [],                      'x', true  ],
    'brak klucza, domyślnie wyłączone'  => [ [],                      'x', false ],
    'stara lista: nie'                  => [ [ 'x' => 'nie' ],        'x', true  ],
    'stara lista: tak'                  => [ [ 'x' => 'tak' ],        'x', false ],
    'pole zaznaczone'                   => [ [ 'x' => true ],         'x', false ],
    'pole odznaczone (false)'           => [ [ 'x' => false ],        'x', true  ],
    'pole odznaczone (pusty łańcuch)'   => [ [ 'x' => '' ],           'x', true  ],
    'Bricks zapisuje jedynkę'           => [ [ 'x' => '1' ],          'x', false ],
    'Bricks zapisuje zero'              => [ [ 'x' => '0' ],          'x', true  ],
    'klucz obok nie ma wpływu'          => [ [ 'y' => 'nie' ],        'x', true  ],
];

$wynik = [];
foreach ($przypadki as $nazwa => [$ust, $klucz, $dom]) {
    $wynik[$nazwa] = evk_flaga($ust, $klucz, $dom);
}

/* STRAŻNIK ROZJAZDU. Sonda ładuje flaga.php wprost, więc sama w sobie nie
   dowodzi, że plik dociera do produkcji. Elementy widzą tę funkcję wyłącznie
   dlatego, że dociąga ją loader.php — i to jest jedyne miejsce, gdzie da się
   to sprawdzić bez stawiania WordPressa. */
$loader = (string) @file_get_contents(EVK_TEST_ROOT . '/includes/bricks-elements/loader.php');

echo json_encode([
    'wyniki'         => $wynik,
    'loader_dociaga' => (bool) preg_match("#require_once\s+__DIR__\s*\.\s*'/flaga\.php'#", $loader),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
