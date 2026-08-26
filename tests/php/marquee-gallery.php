<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Marquee: wiersz „galeria" z danych dynamicznych — z PRAWDZIWEGO element.php.
 *
 * Do 1.103.1 element sam sięgał do Evoke Fields przez `evk_get_field()`.
 * Od 1.104.0 czyta JEDEN tag danych dynamicznych, więc nie zna już żadnej
 * wtyczki pól — zna tylko listę numerów załączników, która z tagu wychodzi.
 *
 * Czego NIE DA SIĘ zmierzyć w przeglądarce, a widać stąd:
 *
 *   · z czym wywołano `bricks_render_dynamic_data()` — czy poszedł tam tag
 *     z wiersza i wpis z kontekstu elementu, czy coś wpisanego na sztywno;
 *   · że obie kopie taśmy dostały TĘ SAMĄ wylosowaną kolejność — na stronie
 *     widać to dopiero jako przeskok na złączeniu pętli, i to nie zawsze;
 *   · że goły tag galerii (bez `__ids`) daje PUSTKĘ, a nie jedno zdjęcie
 *     udające działającą galerię.
 *
 * Kolejność w tym pliku jest istotna: PIERWSZY render leci ZANIM powstanie
 * atrapa `bricks_render_dynamic_data()`, bo `function_exists()` rozstrzyga się
 * w chwili wywołania.
 *
 * I dlatego atrapa stoi w bloku `if (!function_exists(...))`. To nie jest
 * ostrożność na wypadek kolizji — bez tego bloku NIE DA SIĘ jej ukryć przed
 * pierwszym renderem: PHP definiuje bezwarunkowe deklaracje z góry pliku,
 * zanim wykona pierwszą linię. Zmierzone: przypadek „bez Bricksa" rysował
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
foreach ([11, 12, 13, 14, 15, 16, 21] as $id) {
    $GLOBALS['attachments'][$id] = ['/media/' . $id . '.jpg', 800, 600];
}
/* DWUDZIESTKA DWÓJKA CELOWO BEZ WYMIARÓW — załącznik, któremu WordPress nie
   zapisał metadanych. Bez takiego przypadku „obraz niesie wymiary" i „bez
   metadanych nie dostaje pustych atrybutów" byłyby jednym sprawdzeniem. */
$GLOBALS['attachments'][22] = '/media/22.jpg';
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
        'type'        => 'gallery',
        'gallery_tag' => '{evk_field_logotypy__ids}',
        'image_width' => '90px',
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
    preg_match_all('#<img([^>]*)>#', $html, $znaczniki);
    $atrybuty = array_map(function ($z) {
        preg_match('#\swidth="(\d+)"#', $z, $aw);
        preg_match('#\sheight="(\d+)"#', $z, $ah);
        preg_match('#\sloading="([^"]*)"#', $z, $al);
        preg_match('#\sstyle="([^"]*)"#', $z, $as);
        return [
            'w'    => $aw[1] ?? null,
            'h'    => $ah[1] ?? null,
            'lad'  => $al[1] ?? null,
            'styl' => $as[1] ?? '',
        ];
    }, $znaczniki[1]);
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
        // Wymiary z załącznika, sposób wczytywania i pełny styl — po jednym
        // wpisie na obraz, w kolejności wystąpienia.
        'atrybuty'    => $atrybuty,
    ];
}

$wyniki = [];

// ── 1. BEZ Evoke Fields ──────────────────────────────────────────────────────
// Musi paść tutaj, przed definicją atrap.
$wyniki['brakWtyczki'] = rozbior(rysuj([
    'items' => [ wiersz(), [ 'type' => 'text', 'text' => 'EVOKE' ] ],
]));
$wyniki['funkcjeIstnialy'] = function_exists('bricks_render_dynamic_data');

// ── Atrapa danych dynamicznych Bricksa ───────────────────────────────────────
$GLOBALS['wywolania'] = [];

if (!function_exists('bricks_render_dynamic_data')) {
/**
 * Renderowanie tagu. Odwzorowane jest to, co robi Evoke Fields: wariant
 * `__ids` oddaje TEKST po przecinkach (`implode(',', $ids)`), a goły tag
 * galerii — adres PIERWSZEGO obrazu. Ta druga droga jest tu po to, żeby dało
 * się zmierzyć, co się dzieje przy wyborze złego wariantu z listy.
 */
function bricks_render_dynamic_data($tresc, $post_id = 0) {
    $GLOBALS['wywolania'][] = ['tag' => $tresc, 'post' => $post_id];
    $dane = [
        '{evk_field_logotypy__ids}' => '11,12,13,14',
        '{evk_field_duza__ids}'     => '11,12,13,14,15,16',
        '{evk_field_dziury__ids}'   => '11,0,99,12',   // 99 nie ma w bibliotece
        '{evk_field_logotypy}'      => '/media/11.jpg', // goły tag = jeden adres
        // Inna wtyczka pól potrafi oddać surową tablicę wierszy.
        '{acf_logotypy}'            => [['img' => 11], ['img' => 12], ['img' => 13]],
        // A ta sama galeria z innego wpisu — patrz `post_id` niżej.
        '{evk_field_tamten__ids}'   => '21,22',
    ];
    return $dane[$tresc] ?? '';
}
} // koniec bloku ukrywającego atrapę przed pierwszym renderem

// ── 2. Tag, kontekst wpisu i cudze kształty ─────────────────────────────────
// ── 2. Trzy źródła ───────────────────────────────────────────────────────────
$GLOBALS['wywolania'] = [];
$wyniki['zTagu'] = rozbior(rysuj([ 'items' => [ wiersz() ] ]));
$wyniki['wywolanieTag'] = $GLOBALS['wywolania'][0] ?? null;

// Wpis z kontekstu elementu ma trafić do danych dynamicznych — inaczej tag
// rozwinąłby się względem złej strony.
$GLOBALS['wywolania'] = [];
$el7 = new \Evk_Marquee_Element();
$el7->post_id  = 7;
$el7->settings = [ 'items' => [ wiersz(['gallery_tag' => '{evk_field_tamten__ids}']) ] ];
ob_start(); $el7->render(); $wyniki['zKontekstu'] = rozbior(ob_get_clean());
$wyniki['wywolanieKontekst'] = $GLOBALS['wywolania'][0] ?? null;

// Cudza wtyczka pól oddająca surową tablicę wierszy.
$wyniki['cudzyKsztalt'] = rozbior(rysuj([ 'items' => [
    wiersz(['gallery_tag' => '{acf_logotypy}']),
] ]));

// Goły tag galerii — oddaje ADRES pierwszego obrazu, nie listę.
$wyniki['golyTag'] = rozbior(rysuj([ 'items' => [
    wiersz(['gallery_tag' => '{evk_field_logotypy}']),
] ]));

// ── 3. Kolejność i limit ─────────────────────────────────────────────────────
$wyniki['odwrotnaZLimitem'] = rozbior(rysuj([ 'items' => [
    wiersz(['gallery_order' => 'reverse', 'gallery_limit' => 3]),
] ]));

$wyniki['losowa'] = rozbior(rysuj([ 'items' => [
    wiersz(['gallery_tag' => '{evk_field_duza__ids}', 'gallery_order' => 'random']),
] ]));

// ── 4. Zwykły obraz i tekst obok galerii ─────────────────────────────────────
$wyniki['mieszane'] = rozbior(rysuj([ 'items' => [
    [ 'type' => 'text',  'text' => 'EVOKE' ],
    [ 'type' => 'image', 'image' => ['id' => 21], 'image_width' => '200px' ],
    wiersz(),
] ]));

// ── 5. Klucz, którego nie ma ─────────────────────────────────────────────────
$wyniki['zlyKlucz'] = rozbior(rysuj([ 'items' => [
    wiersz(['gallery_tag' => '{nie_ma_takiego}']),
    [ 'type' => 'text', 'text' => 'EVOKE' ],
] ]));

// ── 6. Śmieci w galerii ──────────────────────────────────────────────────────
// Zero i numer spoza biblioteki. Ani jedno, ani drugie nie ma prawa zostawić
// pustego pudełka rozpychającego odstępy taśmy.
$wyniki['dziury'] = rozbior(rysuj([ 'items' => [ wiersz(['gallery_tag' => '{evk_field_dziury__ids}']) ] ]));

// ── 7. Pusto: front milczy, builder mówi ─────────────────────────────────────
$wyniki['pustoFront'] = rozbior(rysuj([ 'items' => [ wiersz(['gallery_tag' => '{nie_ma_takiego}']) ] ]));
$_GET['bricks'] = 'run';
$wyniki['pustoBuilder'] = rozbior(rysuj([ 'items' => [ wiersz(['gallery_tag' => '{nie_ma_takiego}']) ] ]));
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
// ── 8. Wymiary obrazu i sposób wczytywania (1.113.0) ─────────────────────────
// Wysokość pusta = „z proporcji", podana = zamrożony wiersz taśmy. Wczytywanie
// przełączane z panelu, bo przy taśmie poziomej leniwe jest wyborem z gruntu złym.
$wyniki['wymiaryDomyslne'] = rozbior(rysuj([ 'items' => [
    [ 'type' => 'image', 'image' => ['id' => 21], 'image_width' => '200px' ],
] ]));

$wyniki['wymiaryPodane'] = rozbior(rysuj([ 'items' => [
    [ 'type' => 'image', 'image' => ['id' => 21],
      'image_width' => '200px', 'image_height' => '150px', 'image_loading' => 'eager' ],
] ]));

// Sama jednostka bez liczby — z pola liczbowego Bricksa wychodzi tablica.
$wyniki['wysokoscPustaTablica'] = rozbior(rysuj([ 'items' => [
    [ 'type' => 'image', 'image' => ['id' => 21], 'image_width' => '200px',
      'image_height' => ['value' => '', 'unit' => 'px'] ],
] ]));

// Załącznik bez zapisanych wymiarów — atrybutów ma NIE BYĆ, nie mają być puste.
$wyniki['bezMetadanych'] = rozbior(rysuj([ 'items' => [
    [ 'type' => 'image', 'image' => ['id' => 22], 'image_width' => '200px' ],
] ]));

// Galeria idzie tą samą ścieżką co pojedynczy obraz.
$wyniki['galeriaWymiary'] = rozbior(rysuj([ 'items' => [
    wiersz(['image_height' => '150px', 'image_loading' => 'eager']),
] ]));

$pola = $el->controls['items']['fields'];
$wyniki['kontrolki'] = [
    'typy'          => array_keys($pola['type']['options']),
    'szerokoscReq'  => $pola['image_width']['required'] ?? null,
    'tagReq'        => $pola['gallery_tag']['required'] ?? null,
    'tagDynamic'    => $pola['gallery_tag']['hasDynamicData'] ?? null,
    // Cztery pola z 1.103.0 mają ZNIKNĄĆ, nie tylko przestać być wymagane.
    'stareUsuniete' => array_values(array_filter(
        ['gallery_source', 'gallery_group', 'gallery_key', 'gallery_post_id'],
        function ($k) use ($pola) { return isset($pola[$k]); } )),
    'polaWiersza'   => array_keys($pola),
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
