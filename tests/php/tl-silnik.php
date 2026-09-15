<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Silnik podmiany tłumaczeń — zachowanie i koszt.
 *
 * DO 1.185.0 TEN KOD NIE MIAŁ ŻADNEGO POKRYCIA. Tokenizer, detokenizer
 * i tłumaczenie atrybutów szły na front bez jednego sprawdzenia, mimo że to
 * przez nie przechodzi każdy znak każdej obcojęzycznej podstrony.
 *
 * Plik odpowiada na dwa pytania naraz:
 *   1. czy podmiana trafia tam, gdzie ma, i omija resztę,
 *   2. ile to kosztuje — bo koszt rósł jak `węzły × frazy`.
 *
 * Argument 1:
 *   zachowanie      — wynik tokenizacji dla przypadków brzegowych (JSON)
 *   koszt <ile>     — mediana czasu tokenizacji przy bibliotece <ile> fraz (JSON)
 *
 * KOSZT MIERZY SIĘ JEDEN ROZMIAR NA PROCES — i to nie jest ostrożność, tylko
 * warunek poprawności. `get_translation_config()` trzyma wynik w statyku, więc
 * drugi rozmiar w tym samym procesie NIGDY by nie wszedł i obie liczby
 * opisywałyby pierwszą bibliotekę. Pierwsza wersja tego pomiaru miała ten błąd
 * i pokazywała, że koszt nie rośnie — podczas gdy rósł liniowo.
 */
require __DIR__ . '/_wp-stubs.php';

function bricks_is_builder_main() { return false; }
function get_transient($k) { return $GLOBALS['tr'][$k] ?? false; }
function set_transient($k, $v, $t = 0) { $GLOBALS['tr'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['tr'][$k]); return true; }
define('TL_TRANSIENT_CONFIG', 'c');
define('TL_TRANSIENT_INLINE', 'i');
define('TL_TRANSIENT_SLUGS',  's');
define('TL_TRANSIENT_TOKENS', 't');
define('TL_CACHE_TTL', 60);
function add_shortcode($tag, $cb) {}
function wp_rand($min = 0, $max = 0) { return 1234; }
function tl_get_active_lang_codes() { return ['en']; }
function get_current_lang() { return 'en'; }

require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';
require EVK_TEST_ROOT . '/includes/20-helpers-cache-inline.php';
require EVK_TEST_ROOT . '/includes/50-translation-engine.php';

$tryb = $argv[1] ?? 'zachowanie';

// =========================================================================
// ZACHOWANIE
// =========================================================================
if ($tryb === 'zachowanie') {
    /* Frazy celowo podchwytliwe: odstępy wokół, zdwojone spacje, pusty
       przekład, różnice wielkości liter. Normalizacja ma je wyrównać, a pusty
       przekład ma zostawić tekst w spokoju. */
    $GLOBALS['options']['tl_translations'] = ['groups' => ['g' => ['name' => 'G', 'rows' => [
        'r1' => ['pl' => 'Zapytaj o wycenę',      'en' => 'Ask for a quote'],
        'r2' => ['pl' => '  Z odstępami  ',       'en' => 'With spaces'],
        'r3' => ['pl' => 'WIELKIE litery',        'en' => 'CAPITAL letters'],
        'r4' => ['pl' => 'Bez tłumaczenia',       'en' => ''],
        'r5' => ['pl' => 'Ze  zdwojoną   spacją', 'en' => 'Double space'],
        'r6' => ['pl' => 'Znak & encja',          'en' => 'Amp entity'],
    ]]]];

    $przypadki = [
        'dokladne'        => '<p>Zapytaj o wycenę</p>',
        'inna_wielkosc'   => '<p>zapytaj O WYCENĘ</p>',
        'odstepy_wokol'   => '<p>   Zapytaj o wycenę   </p>',
        'fraza_z_odstepami' => '<p>Z odstępami</p>',
        'zdwojona_spacja' => '<p>Ze  zdwojoną   spacją</p>',
        'twarda_spacja'   => '<p>Ze&nbsp; zdwojoną   spacją</p>',
        'encja'           => '<p>Znak &amp; encja</p>',
        'brak_tlumaczenia' => '<p>Bez tłumaczenia</p>',
        'nic_nie_pasuje'  => '<p>Zupełnie inny tekst</p>',
        'pusty_wezel'     => '<p>   </p>',
        'atrybut_title'   => '<p title="Zapytaj o wycenę">tekst</p>',
        'atrybut_obcy'    => '<p title="Cokolwiek innego">tekst</p>',
        'po_polsku'       => '<p>Zapytaj o wycenę</p>',
    ];

    $wynik = [];
    foreach ($przypadki as $nazwa => $html) {
        $lang = $nazwa === 'po_polsku' ? 'pl' : 'en';
        $tok  = tl_tokenize_content($html, $lang);
        $wynik[$nazwa] = [
            'token'  => $tok,
            // Pełna droga: tokenizacja, a potem podstawienie przekładów.
            'gotowe' => tl_detokenize_content($tok, $lang),
        ];
    }

    echo json_encode($wynik, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    exit;
}

// =========================================================================
// KOSZT
// =========================================================================
/* Koszt rósł jak `węzły × frazy`, bo każdy węzeł tekstowy przeglądał CAŁĄ
   bibliotekę, normalizując przy tym każdą frazę od nowa. Mierzymy przy stałej
   liczbie węzłów — wołający puszcza ten plik dwa razy, na dwóch rozmiarach. */
$fraz = max(1, (int) ($argv[2] ?? 30));

$rows = [];
for ($i = 0; $i < $fraz; $i++) {
    $rows['r' . $i] = ['pl' => 'Fraza numer ' . $i, 'en' => 'Phrase number ' . $i];
}
$GLOBALS['options']['tl_translations'] = ['groups' => ['g' => ['name' => 'G', 'rows' => $rows]]];

/* Same węzły NIETRAFIAJĄCE — to one obnażają pętlę, bo trafienie kończyło ją
   wcześniej. Strona z samymi trafieniami mierzyłaby najlepszy przypadek. */
$html = '<div>';
for ($i = 0; $i < 600; $i++) $html .= '<p>Zwykły akapit numer ' . $i . '</p>';
$html .= '</div>';

tl_tokenize_content($html, 'en');   // rozgrzewka pamięci podręcznej

$czasy = [];
for ($i = 0; $i < 5; $i++) {
    $t = microtime(true);
    tl_tokenize_content($html, 'en');
    $czasy[] = (microtime(true) - $t) * 1000;
}
sort($czasy);

echo json_encode([
    'ms'   => round($czasy[2], 2),
    // Ile fraz NAPRAWDĘ weszło do konfiguracji — strażnik przed pomiarem,
    // który mierzy nie to, co myśli.
    'fraz' => count(get_translation_config()['strings'] ?? []),
], JSON_UNESCAPED_UNICODE), "\n";
