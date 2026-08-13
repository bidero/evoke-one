<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Kontrolki i znacznik elementu Burger — z PRAWDZIWEGO element.php.
 *
 * Sedno: znacznik ma być WYLICZANY z liczby kresek zapisanej w tablicy stylów,
 * a nie wklejany osobno dla każdego stylu. Wzór, od którego uciekamy, miał
 * `render()` na 750 linii `if/else` — przy takim kształcie dołożenie stylu
 * znaczy dopisanie gałęzi kodu, a nie wiersza danych, i nic tego nie pilnuje.
 *
 * Tego NIE DA SIĘ zmierzyć w przeglądarce: strona z jednym stylem wygląda
 * identycznie niezależnie od tego, czy znacznik powstał z pętli, czy z wklejki.
 * Widać to dopiero stąd — po liczbie kresek zgodnej z tablicą.
 */
require __DIR__ . '/_wp-stubs.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
require __DIR__ . '/_bricks-stubs.php';

define('EVK_BRICKS_CATEGORY', 'evoke-one');

require EVK_TEST_ROOT . '/includes/bricks-elements/evoke-burger/element.php';

$el = new \Bricks\Evk_Burger();
$el->set_controls();

// Render domyślny — tak wygląda element świeżo wstawiony w builderze.
ob_start();
$el->render();
$plain = ob_get_clean();

// I z wypełnionymi polami. Krzywa idzie osobną drogą niż reszta: kontrolki
// `css` wpisałyby surową nazwę GSAP-a, której CSS nie zna — a nieznana funkcja
// czasu unieważnia CAŁĄ deklarację `transition` razem z czasem trwania.
$el2 = new \Bricks\Evk_Burger();
$el2->settings = [
    'style'     => 'cross',
    'easing'    => 'back.out(1.7)',
    'ariaLabel' => 'Otwórz menu',
    'mode'      => 'self',
];
ob_start();
$el2->render();
$filled = ob_get_clean();

// Nieznany styl nie może wywalić rendera — zapisane strony przeżywają
// przemianowanie stylu, a element wraca do domyślnego.
$el3 = new \Bricks\Evk_Burger();
$el3->settings = [ 'style' => 'nie-ma-takiego' ];
ob_start();
$el3->render();
$bogus = ob_get_clean();

// Tryb „wskazany element" — kliknięcie przestawia CUDZY element.
$el4 = new \Bricks\Evk_Burger();
$el4->settings = [ 'mode' => 'target', 'target' => '#moj-panel', 'targetClass' => 'is-otwarte' ];
ob_start();
$el4->render();
$target = ob_get_clean();

/* Strona zapisana PRZED zamianą checkboxa na listę trybów. Bez tej ścieżki
   burger z włączonym starym „Sam się przełącza" po cichu przestałby cokolwiek
   robić — a nikt by tego nie zauważył, dopóki nie otworzyłby elementu
   w builderze. */
$el5 = new \Bricks\Evk_Burger();
$el5->settings = [ 'selfToggle' => true ];
ob_start();
$el5->render();
$legacy = ob_get_clean();

/* Trzy źródła rysunku razy tekst. Te zrzuty idą PROSTO DO FIXTURE'A, więc test
   w przeglądarce mierzy znacznik, który naprawdę wychodzi z render(), a nie
   jego ręcznie przepisaną kopię. Kopia zaczęłaby żyć własnym życiem przy
   pierwszej zmianie nazwy klasy. */
$warianty = [
    // Kreski PLUS tekst — tekst jest osobną osią, dochodzi do każdego źródła.
    'tekst'     => [ 'textClosed' => 'MENU', 'textOpen' => 'ZAMKNIJ' ],
    // Jedno wypełnione pole: drugi stan ma nieść ten sam napis, a nie pustkę.
    'tekstJeden'=> [ 'textClosed' => 'MENU' ],
    'tekstNad'  => [ 'textClosed' => 'MENU', 'textOpen' => 'ZAMKNIJ', 'textPosition' => 'nad' ],
    // Własne ikony — obie wychodzą i przełącza je sama klasa.
    'ikona'     => [
        'iconSource' => 'ikona',
        'iconClosed' => [ 'library' => 'themify', 'icon' => 'ti-menu' ],
        'iconOpen'   => [ 'library' => 'themify', 'icon' => 'ti-close' ],
    ],
    // „Nic" plus tekst = wariant czysto tekstowy, bez ani jednej nowej gałęzi.
    'brak'      => [ 'iconSource' => 'brak', 'textClosed' => 'MENU', 'textOpen' => 'ZAMKNIJ' ],
];
$render_wariant = [];
foreach ($warianty as $nazwa => $ustawienia) {
    $e = new \Bricks\Evk_Burger();
    $e->settings = $ustawienia;
    ob_start();
    $e->render();
    $render_wariant[$nazwa] = ob_get_clean();
}

/* Render KAŻDEGO stylu, nie tylko domyślnego. Liczba kresek w znaczniku ma się
   zgadzać z rejestrem dla wszystkich — przy jednym sprawdzanym stylu pomyłka
   w nowym wpisie przechodziłaby bez śladu. */
$styles = \Bricks\Evk_Burger::styles();
$lines  = [];
$render = [];
foreach ($styles as $key => $def) {
    $lines[$key] = $def['lines'];
    $e = new \Bricks\Evk_Burger();
    $e->settings = [ 'style' => $key ];
    ob_start();
    $e->render();
    $out = ob_get_clean();
    $render[$key] = [
        'kresek' => substr_count($out, 'evk-burger__line'),
        'klasa'  => strpos($out, 'evk-burger--' . $key) !== false,
    ];
}

echo json_encode([
    'styles'        => array_keys($styles),
    'lines'         => $lines,
    'controls'      => array_keys($el->controls),
    'easingOptions' => array_keys($el->controls['easing']['options']),
    'sharedEasings' => evk_anim_easings(),
    'renderPlain'   => $plain,
    'renderFilled'  => $filled,
    'renderBogus'   => $bogus,
    'renderTarget'  => $target,
    'renderLegacy'  => $legacy,
    'wariant'       => $render_wariant,
    // Style, które czytają „Długość krótszej kreski" — z rejestru, oraz lista
    // wpisana w warunek widoczności tej kontrolki. Mają być tym samym.
    'shortStyles'   => array_keys(array_filter($styles,
        function ($d) { return !empty($d['short']); })),
    'shortRequired' => $el->controls['shortLine']['required'][2],
    'ariaRequired'  => $el->controls['ariaLabel']['required'] ?? null,
    // Okablowanie paddingu napisu: MUSI celować w slot, a nie w korzeń —
    // padding korzenia odsuwałby napis razem z rysunkiem.
    'textPaddingCss' => $el->controls['textPadding']['css'][0] ?? null,
    'textPaddingType'=> $el->controls['textPadding']['type'] ?? null,
    'styleRequired' => $el->controls['style']['required'] ?? null,
    'pozycje'       => array_keys(\Bricks\Evk_Burger::text_positions()),
    // Ile <span class="evk-burger__line"> naprawdę wyszło z rendera.
    'plainLines'    => substr_count($plain, 'evk-burger__line'),
    'render'        => $render,
    // Lista dla fixture'a: „klucz:kresek,…". Idzie wprost z rejestru, więc
    // galeria w teście nie może się z nim rozjechać.
    'galeria'       => implode(',', array_map(
        function ($k) use ($lines) { return $k . ':' . $lines[$k]; }, array_keys($styles))),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
