<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Marquee: wiersz „galeria" z Evoke Fields — z PRAWDZIWEGO element.php.
 *
 * PIERWSZE sprawdzenia rendera tego elementu. Do 1.103.0 marquee miało tylko
 * testy przeglądarkowe o pauzie poza kadrem, a `render()` nie miał żadnych.
 *
 * Czego NIE DA SIĘ zmierzyć w przeglądarce, a widać stąd:
 *
 *   · z czym wywołano `evk_get_field()` — „bieżący wpis" to zero przekazane
 *     do Evoke Fields, a nie żadna nasza pętla; z ekranu wygląda to tak samo
 *     jak wpisany na sztywno numer;
 *   · że obie kopie taśmy dostały TĘ SAMĄ wylosowaną kolejność — na stronie
 *     widać to dopiero jako przeskok na złączeniu pętli, i to nie zawsze;
 *   · że bez wtyczki Evoke Fields nic się nie wywraca — nieznana funkcja
 *     zabiłaby cały render, a nie samo marquee.
 *
 * Kolejność w tym pliku jest istotna: PIERWSZY render leci ZANIM powstaną
 * atrapy `evk_*`, bo `function_exists()` rozstrzyga się w chwili wywołania.
 *
 * I dlatego atrapy stoją w bloku `if (!function_exists(...))`. To nie jest
 * ostrożność na wypadek kolizji — bez tego bloku ich NIE DA SIĘ ukryć przed
 * pierwszym renderem: PHP definiuje bezwarunkowe deklaracje z góry pliku,
 * zanim wykona pierwszą linię. Zmierzone: przypadek „bez wtyczki" rysował
 * komplet obrazów i świecił na zielono z powodu, który z niego nie wynikał.
 */
require __DIR__ . '/_wp-stubs.php';
require __DIR__ . '/_bricks-stubs.php';

define('EVK_BRICKS_CATEGORY', 'evoke-one');
require EVK_TEST_ROOT . '/includes/bricks-elements/evoke-marquee/element.php';

// ── Biblioteka mediów ────────────────────────────────────────────────────────
// Sześć obrazów, nie cztery: sprawdzenie „losowanie zapada raz" porównuje dwie
// kopie taśmy, a przy czterech pozycjach mutacja losująca w pętli kopii trafiała
// w tę samą kolejność raz na 24 przebiegi. Przy sześciu — raz na 720.
foreach ([11, 12, 13, 14, 15, 16, 21, 22] as $id) {
    $GLOBALS['attachments'][$id] = '/media/' . $id . '.jpg';
}
$GLOBALS['post_meta'][12]['_wp_attachment_image_alt'] = 'logo dwunastki';

/** Render jednego zestawu ustawień. */
function rysuj(array $settings) {
    $el = new \Evk_Marquee_Element();
    $el->settings = $settings;
    ob_start();
    $el->render();
    return ob_get_clean();
}

/** Wiersz „galeria" z domyślnymi polami — testy nadpisują, co im trzeba. */
function wiersz(array $nadpisz = []) {
    return array_merge([
        'type'           => 'gallery',
        'gallery_source' => 'post',
        'gallery_key'    => 'logotypy',
        'image_width'    => '90px',
    ], $nadpisz);
}

/**
 * Rozbiór znacznika na to, co da się sprawdzić.
 *
 * Adresy obrazów rozdzielone PER KOPIA taśmy — bez tego „obie kopie mają tę
 * samą kolejność" nie miałoby czego porównać.
 */
function rozbior($html) {
    preg_match_all('#<div class="evk-marquee-track"[^>]*>(.*?)</div>#s', $html, $m);
    $kopie = array_map(function ($k) {
        preg_match_all('#<img[^>]*src="([^"]*)"#', $k, $s);
        return $s[1];
    }, $m[1]);

    preg_match_all('#<img[^>]*style="width:([^;]*);#', $html, $w);
    preg_match_all('#<img[^>]*alt="([^"]*)"#', $html, $a);
    preg_match_all('#<span>([^<]*)</span>#', $html, $t);

    return [
        'kopii'       => count($kopie),
        'kopiaA'      => $kopie[0] ?? [],
        'kopiaB'      => $kopie[1] ?? [],
        'obrazow'     => substr_count($html, '<img'),
        'szerokosci'  => array_values(array_unique($w[1])),
        'alty'        => $a[1],
        'teksty'      => $t[1],
        'placeholder' => strpos($html, 'bricks-element-placeholder') !== false,
        'pustePudelka'=> substr_count($html, '<span class="evk-marquee-item"></span>'),
    ];
}

$wyniki = [];

// ── 1. BEZ Evoke Fields ──────────────────────────────────────────────────────
// Musi paść tutaj, przed definicją atrap.
$wyniki['brakWtyczki'] = rozbior(rysuj([
    'items' => [ wiersz(), [ 'type' => 'text', 'text' => 'EVOKE' ] ],
]));
$wyniki['funkcjeIstnialy'] = function_exists('evk_get_field');

// ── Atrapy Evoke Fields ──────────────────────────────────────────────────────
$GLOBALS['wywolania'] = [];

if (!function_exists('evk_get_field')) {
/**
 * Pole wpisu. Oddaje TEKST po przecinkach — dokładnie tak, jak robi to
 * Evoke Fields: `if ($prop === 'ids') return implode(',', $ids);`
 */
function evk_get_field($klucz, $post_id = 0, $prop = '') {
    $GLOBALS['wywolania'][] = ['fn' => 'field', 'klucz' => $klucz, 'post' => $post_id, 'prop' => $prop];
    if ((int) $post_id === 7) { return '21,22'; }
    $dane = [
        'logotypy' => '11,12,13,14',
        'duza'     => '11,12,13,14,15,16',
        'dziury'   => '11,0,99,12',   // 99 nie ma w bibliotece, 0 jest śmieciem
    ];
    return $dane[$klucz] ?? '';
}

/**
 * Pole ze strony ustawień. Oddaje SUROWĄ tablicę wierszy — Evoke Fields nie
 * formatuje tej drogi wcale (`evk_rep_get_option()` zwraca to, co w opcji).
 * Na tym polega cały sens wspólnego normalizatora.
 */
function evk_get_option_field($grupa, $klucz = '', $default = '') {
    $GLOBALS['wywolania'][] = ['fn' => 'option', 'grupa' => $grupa, 'klucz' => $klucz];
    if ('globalne' === $grupa && 'logotypy' === $klucz) {
        return [
            ['img' => 11, 'cat' => 'a'],
            ['img' => 12, 'cat' => 'b'],
            ['img' => 13, 'cat' => 'a'],
        ];
    }
    return '';
}
} // koniec bloku ukrywającego atrapy przed pierwszym renderem

// ── 2. Trzy źródła ───────────────────────────────────────────────────────────
$GLOBALS['wywolania'] = [];
$wyniki['zWpisu'] = rozbior(rysuj([ 'items' => [ wiersz() ] ]));
$wyniki['wywolanieWpis'] = $GLOBALS['wywolania'][0] ?? null;

$GLOBALS['wywolania'] = [];
$wyniki['zeWskazanego'] = rozbior(rysuj([ 'items' => [
    wiersz(['gallery_source' => 'post_id', 'gallery_post_id' => 7]),
] ]));
$wyniki['wywolanieWskazany'] = $GLOBALS['wywolania'][0] ?? null;

$GLOBALS['wywolania'] = [];
$wyniki['zOpcji'] = rozbior(rysuj([ 'items' => [
    wiersz(['gallery_source' => 'option', 'gallery_group' => 'globalne']),
] ]));
$wyniki['wywolanieOpcje'] = $GLOBALS['wywolania'][0] ?? null;

// ── 3. Kolejność i limit ─────────────────────────────────────────────────────
$wyniki['odwrotnaZLimitem'] = rozbior(rysuj([ 'items' => [
    wiersz(['gallery_order' => 'reverse', 'gallery_limit' => 3]),
] ]));

$wyniki['losowa'] = rozbior(rysuj([ 'items' => [
    wiersz(['gallery_key' => 'duza', 'gallery_order' => 'random']),
] ]));

// ── 4. Zwykły obraz i tekst obok galerii ─────────────────────────────────────
$wyniki['mieszane'] = rozbior(rysuj([ 'items' => [
    [ 'type' => 'text',  'text' => 'EVOKE' ],
    [ 'type' => 'image', 'image' => ['id' => 21], 'image_width' => '200px' ],
    wiersz(),
] ]));

// ── 5. Klucz, którego nie ma ─────────────────────────────────────────────────
$wyniki['zlyKlucz'] = rozbior(rysuj([ 'items' => [
    wiersz(['gallery_key' => 'nie-ma-takiego']),
    [ 'type' => 'text', 'text' => 'EVOKE' ],
] ]));

// ── 6. Śmieci w galerii ──────────────────────────────────────────────────────
// Zero i numer spoza biblioteki. Ani jedno, ani drugie nie ma prawa zostawić
// pustego pudełka rozpychającego odstępy taśmy.
$wyniki['dziury'] = rozbior(rysuj([ 'items' => [ wiersz(['gallery_key' => 'dziury']) ] ]));

// ── 7. Pusto: front milczy, builder mówi ─────────────────────────────────────
$wyniki['pustoFront'] = rozbior(rysuj([ 'items' => [ wiersz(['gallery_key' => 'nie-ma-takiego']) ] ]));
$_GET['bricks'] = 'run';
$wyniki['pustoBuilder'] = rozbior(rysuj([ 'items' => [ wiersz(['gallery_key' => 'nie-ma-takiego']) ] ]));
unset($_GET['bricks']);

// ── 8. Normalizator wprost ───────────────────────────────────────────────────
$wyniki['normalizator'] = [
    'tekst'   => \Evk_Marquee_Element::ids_z_wartosci('11,12,13'),
    'wiersze' => \Evk_Marquee_Element::ids_z_wartosci([['img' => 11], ['img' => 12], ['img' => 13]]),
    'liczby'  => \Evk_Marquee_Element::ids_z_wartosci([11, 12, 13]),
    'puste'   => \Evk_Marquee_Element::ids_z_wartosci(''),
    'nic'     => \Evk_Marquee_Element::ids_z_wartosci(null),
];

// ── 9. Kontrolki ─────────────────────────────────────────────────────────────
$el = new \Evk_Marquee_Element();
$el->set_controls();
$pola = $el->controls['items']['fields'];
$wyniki['kontrolki'] = [
    'typy'          => array_keys($pola['type']['options']),
    'szerokoscReq'  => $pola['image_width']['required'] ?? null,
    'grupaReq'      => $pola['gallery_group']['required'] ?? null,
    'idReq'         => $pola['gallery_post_id']['required'] ?? null,
    'kolejnosc'     => array_keys($pola['gallery_order']['options']),
    /*
     * Warunki KAŻDEGO pola wiersza, nie tylko nowych.
     *
     * Repeater Bricksa przyjmuje w polach wiersza pojedynczy warunek — tak
     * działa repeater Animatora, jedyny sprawdzony w boju w tej wtyczce.
     * Łańcuch dwóch warunków rozłożył w 1.103.0 dokładanie wierszy, a z PHP-a
     * widać to tylko po długości tablicy.
     */
    'wszystkieReq'  => array_map(
        function ($f) { return $f['required'] ?? null; }, $pola ),
];

echo json_encode($wyniki, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
