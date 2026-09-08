<?php
/**
 * Kolory, które Wave Background faktycznie wypuszcza do shadera.
 *
 * Idzie przez PRAWDZIWY render() elementu i wyciąga tablicę CONFIG z wygenerowanego
 * modułu — a nie przez regex po źródle, który sprawdzałby naszą interpretację
 * pliku, nie jego wynik.
 *
 * Argument: JSON z ustawieniami elementu.
 *
 * Bloki namespace, bo element dziedziczy po \Bricks\Element. Deklaracja
 * przestrzeni musi być pierwszą instrukcją w pliku, więc bramka CLI siedzi
 * w bloku globalnym niżej, a nie na górze jak w pozostałych generatorach.
 */

namespace Bricks {
    /** Tyle klasy bazowej Bricks, ile potrzebuje ten element. */
    class Element {
        public $settings = [];
        public $id       = 'test';
        public $controls = [];
    }
}

namespace {
    // Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd
    // aktualizatorem na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

    require __DIR__ . '/_wp-stubs.php';

    define('EVK_BRICKS_CATEGORY', 'evoke');
    define('EVK_GSAP_VERSION', '3.15.0');

    require EVK_TEST_ROOT . '/includes/bricks-elements/evoke-wave-bg/element.php';

    $el = new Evk_Wave_Bg_Element();
    $el->settings = json_decode($argv[1] ?? '{}', true) ?: [];

    /* Biblioteka mediów dla kontrolki obrazu. Atrapa `wp_get_attachment_image_src()`
       czyta załączniki z $GLOBALS, a podajemy je w tym samym JSON-ie co ustawienia
       — jeden argument zamiast dwóch, i widać w teście, że obraz i ustawienie
       należą do siebie. */
    if (!empty($el->settings['_zalaczniki'])) {
        $GLOBALS['attachments'] = $el->settings['_zalaczniki'];
        unset($el->settings['_zalaczniki']);
    }

    ob_start();
    $el->render();
    $html = ob_get_clean();

    /* Tryb `html`: całe wyjście render(), bez wyciągania czegokolwiek.
       Potrzebne fixturowi pomiarowemu — mierzymy PRAWDZIWY element, a nie
       jego odtworzenie w znaczniku testu. */
    if (($argv[2] ?? '') === 'html') { echo $html; exit; }

    if (!preg_match('/const CONFIG\s+= (\{.*?\});/s', $html, $m)) {
        fwrite(STDERR, "Nie znaleziono CONFIG w wyjściu render().\n");
        exit(1);
    }
    $cfg = json_decode($m[1], true);

    /* Tryb `cfg`: sam CONFIG. Sprawdzenia wydajnościowe pytają o pojedyncze
       wartości i nie mają po co dostawać całej reszty. */
    if (($argv[2] ?? '') === 'cfg') { echo json_encode($cfg), "\n"; exit; }

    // Lista wariantów palety — do sprawdzenia, że kontrolka i render znają te same.
    $el->set_controls();

    // Maska jedzie w atrybucie `style` korzenia, nie w CONFIG-u — czytamy ją
    // z prawdziwego wyjścia render(), a nie odtwarzamy regexem ze źródła.
    $maska = preg_match('/mask-image:\s*(linear-gradient\([^;"]*\))/', $html, $mm)
        ? $mm[1] : null;

    echo json_encode([
        'colors'   => $cfg['colors'] ?? null,
        'palettes' => array_keys($el->controls['palette']['options'] ?? []),
        'maska'    => $maska,
    ], JSON_UNESCAPED_UNICODE), "\n";
}
