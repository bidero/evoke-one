<?php
namespace Bricks {
    // Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd
    // aktualizatorem na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

    /** Rejestr elementów — tyle, ile czyta pętla rejestracji (Animator czyta to samo). */
    if (!class_exists('Bricks\\Elements')) {
        class Elements {
            /** @var array<string,array<string,string>> */
            public static $elements = [
                'heading'   => ['name' => 'heading'],
                'accordion' => ['name' => 'accordion'],
                'form'      => ['name' => 'form'],
            ];
        }
    }
}

namespace {
/**
 * Pola języków w elementach Bricksa (1.241.0) — PRAWDZIWE funkcje modułu na
 * tablicach w kształcie Bricksa.
 *
 *   php tests/php/tl-pola-elementow.php
 *
 * Kontrolki próbek są przepisane z elementów rdzenia Bricksa (nagłówek,
 * obrazek, formularz, akordeon, licznik, skrótkod) i z elementu Evoke
 * z selektorem — tyle, ile potrzeba, żeby każda gałąź werdyktu
 * „tłumaczalna / techniczna" miała przykład. Czy Bricks POKAŻE te pola
 * w panelu, stąd nie widać — to sprawdza się na stronie (CLAUDE.md).
 *
 * Ostatnia część przepuszcza wynik podmiany przez PRAWDZIWY tokenizer słownika
 * (50-translation-engine.php), żeby sprawdzić kolejność: pole w elemencie →
 * słownik → oryginał.
 */
require __DIR__ . '/_wp-stubs.php';

function bricks_is_builder_main() { return false; }
if (!function_exists('wp_strip_all_tags')) {
    function wp_strip_all_tags($s) { return trim(strip_tags((string) $s)); }
}
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
function tl_get_active_lang_codes() { return $GLOBALS['jezyki']; }
function get_current_lang() { return $GLOBALS['jezyk']; }
$GLOBALS['jezyki'] = ['en', 'de'];
$GLOBALS['jezyk']  = 'pl';

require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';
require EVK_TEST_ROOT . '/includes/20-helpers-cache-inline.php';
require EVK_TEST_ROOT . '/includes/50-translation-engine.php';
require EVK_TEST_ROOT . '/includes/51-translation-element-fields.php';

$wynik = [];

// ── Rejestracja: filtry dla każdego elementu z rejestru ──────────────────
foreach ($GLOBALS['hooks']['init'] ?? [] as $cb) $cb();
$wynik['rejestracja'] = [];
foreach (array_keys(\Bricks\Elements::$elements) as $n) {
    $wynik['rejestracja'][$n] = [
        'kontrolki' => !empty($GLOBALS['hooks']["bricks/elements/{$n}/controls"]),
        'grupy'     => !empty($GLOBALS['hooks']["bricks/elements/{$n}/control_groups"]),
    ];
}

// ── Grupa ────────────────────────────────────────────────────────────────
$wynik['grupy'] = evk_tl_el_grupy(['content' => ['title' => 'Treść'], '_attributes' => ['title' => 'Atrybuty']]);
$GLOBALS['jezyki'] = [];
$wynik['grupy_bez_jezykow'] = evk_tl_el_grupy(['content' => ['title' => 'Treść']]);
$GLOBALS['jezyki'] = ['en', 'de'];

// ── Kontrolki ────────────────────────────────────────────────────────────
$probki = [
    'heading' => [
        'text'      => ['tab' => 'content', 'label' => 'Tekst', 'type' => 'text', 'hasDynamicData' => 'text',
                        'inlineEditing' => ['selector' => '.text'], 'default' => 'Nagłówek'],
        'tag'       => ['tab' => 'content', 'label' => 'Tag HTML', 'type' => 'select'],
        'customTag' => ['tab' => 'content', 'label' => 'Własny tag', 'type' => 'text', 'required' => ['tag', '=', 'custom']],
        'link'      => ['tab' => 'content', 'label' => 'Link', 'type' => 'link'],
        '_cssId'    => ['tab' => 'style', 'label' => 'CSS ID', 'type' => 'text'],
        'separatorText' => ['tab' => 'style', 'label' => 'Kolor', 'type' => 'text'],
        'maxWidth'  => ['tab' => 'content', 'label' => 'Szerokość', 'type' => 'text', 'css' => [['property' => 'max-width']]],
    ],
    'image' => [
        'altText'       => ['tab' => 'content', 'label' => 'Tekst alternatywny', 'type' => 'text'],
        'captionCustom' => ['tab' => 'content', 'label' => 'Podpis', 'type' => 'textarea'],
        'image'         => ['tab' => 'content', 'label' => 'Obrazek', 'type' => 'image'],
    ],
    'form' => [
        'fields' => ['tab' => 'content', 'label' => 'Pola', 'type' => 'repeater', 'fields' => [
            'type'        => ['label' => 'Typ', 'type' => 'select'],
            'label'       => ['label' => 'Etykieta', 'type' => 'text'],
            'placeholder' => ['label' => 'Tekst zastępczy', 'type' => 'text'],
            'value'       => ['label' => 'Wartość', 'type' => 'text'],
            'options'     => ['label' => 'Opcje', 'type' => 'textarea'],
        ]],
        'submitButtonText' => ['tab' => 'content', 'label' => 'Tekst przycisku', 'type' => 'text'],
        'successMessage'   => ['tab' => 'content', 'label' => 'Komunikat sukcesu', 'type' => 'textarea'],
        'emailSubject'     => ['tab' => 'content', 'label' => 'Temat', 'type' => 'text'],
        'redirect'         => ['tab' => 'content', 'label' => 'Przekieruj', 'type' => 'text'],
        'fromName'         => ['tab' => 'content', 'label' => 'Od (nazwa)', 'type' => 'text'],
    ],
    'accordion' => [
        'accordions' => ['tab' => 'content', 'label' => 'Pozycje', 'type' => 'repeater', 'fields' => [
            'title'   => ['label' => 'Tytuł', 'type' => 'text'],
            'content' => ['label' => 'Treść', 'type' => 'editor'],
            'icon'    => ['label' => 'Ikona', 'type' => 'icon'],
        ]],
    ],
    'counter' => [
        'countTo' => ['tab' => 'content', 'label' => 'Licz do', 'type' => 'text'],
        'prefix'  => ['tab' => 'content', 'label' => 'Przedrostek', 'type' => 'text'],
    ],
    'shortcode' => [
        'shortcode' => ['tab' => 'content', 'label' => 'Skrótkod', 'type' => 'text'],
    ],
    'evoke-offcanvas-menu' => [
        'toggleSelector' => ['tab' => 'content', 'label' => 'Przełącznik', 'type' => 'text'],
        'closeLabel'     => ['tab' => 'content', 'label' => 'Etykieta zamknięcia', 'type' => 'text'],
        'triggerAttr'    => ['tab' => 'content', 'label' => 'Adres URL wyzwalacza', 'type' => 'text'],
    ],
    'bez-tekstu' => [
        'tag' => ['tab' => 'content', 'label' => 'Tag', 'type' => 'select'],
    ],
    /* Pola bez etykiety — tak wygląda tekst nagłówka w Bricksie (tylko edycja
       na kanwie). Do 1.242.0 panel pokazywał wtedy klucz: „text — EN". */
    'bez-etykiety' => [
        'text' => ['tab' => 'content', 'type' => 'text'],
    ],
    'bez-etykiet-kilka' => [
        'text'     => ['tab' => 'content', 'type' => 'text'],
        'title'    => ['tab' => 'content', 'type' => 'text'],
        'heroLine' => ['tab' => 'content', 'type' => 'text'],
    ],
];
$wynik['kontrolki'] = [];
foreach ($probki as $el => $k) {
    $po = evk_tl_el_kontrolki($k, $el);
    $wynik['kontrolki'][$el] = [
        'nowe'         => array_values(array_diff(array_keys($po), array_keys($k))),
        'kolejnosc'    => array_keys($po),
        'bez_zmian'    => $po === $k,
        'definicje'    => array_intersect_key($po, array_flip(array_diff(array_keys($po), array_keys($k)))),
        'pola_listy'   => array_map(fn($d) => is_array($d) && isset($d['fields']) ? array_keys($d['fields']) : null, $po),
        'defs_listy'   => array_map(fn($d) => is_array($d) && isset($d['fields']) ? array_intersect_key($d['fields'], array_flip(array_filter(array_keys($d['fields']), fn($x) => strncmp($x, 'evk_tl_', 7) === 0))) : null, $po),
    ];
}

// ── Podmiana przed renderem ──────────────────────────────────────────────
$filtr = $GLOBALS['hooks']['bricks/element/settings'][0] ?? null;
$ustawienia = [
    'text' => 'Zapytaj o wycenę',
    'evk_tl_en__text' => 'Get a quote',
    'evk_tl_de__text' => '<p></p>',
    'link' => ['type' => 'external', 'url' => 'https://example.test/'],
    'accordions' => [
        ['title' => 'Jeden', 'evk_tl_en__title' => 'One', 'content' => '<p>Treść</p>', 'evk_tl_en__content' => '<p>Body</p>'],
        ['title' => 'Dwa', 'evk_tl_en__title' => '   ', 'content' => '<p>Druga</p>'],
    ],
    'caption' => 'Podpis',
    'evk_tl_en__caption' => ['nie', 'tekst'],
];
$wynik['podmiana'] = [];
foreach (['pl', 'en', 'de'] as $j) {
    $GLOBALS['jezyk'] = $j;
    $wynik['podmiana'][$j] = $filtr ? $filtr($ustawienia, null) : null;
}
$GLOBALS['jezyk'] = 'en';
$_GET['bricks'] = 'run';
$wynik['podmiana']['builder_en'] = $filtr ? $filtr($ustawienia, null) : null;
unset($_GET['bricks']);
$GLOBALS['is_admin'] = true;
$wynik['podmiana']['admin_en'] = $filtr ? $filtr($ustawienia, null) : null;
$GLOBALS['is_admin'] = false;
// Kod języka z łącznikiem: klucz pola ma podkreślnik.
$GLOBALS['jezyki'] = ['pt-br'];
$GLOBALS['jezyk']  = 'pt-br';
$wynik['podmiana']['pt_br'] = $filtr ? $filtr(['text' => 'Oryginał', 'evk_tl_pt_br__text' => 'Original'], null) : null;
$wynik['klucz_pt_br'] = evk_tl_el_klucz('pt-br', 'text');
$GLOBALS['jezyki'] = ['en', 'de'];

// ── Kolejność: pole w elemencie → słownik → oryginał ─────────────────────
$GLOBALS['options']['tl_translations'] = ['groups' => ['g' => ['name' => 'G', 'rows' => [
    'r1' => ['pl' => 'Zapytaj o wycenę', 'en' => 'Ask for a quote (słownik)'],
    'r2' => ['pl' => 'Kontakt',          'en' => 'Contact (słownik)'],
]]]];
$GLOBALS['jezyk'] = 'en';
$strona = function (array $ust): string {
    $po = $GLOBALS['hooks']['bricks/element/settings'][0]($ust, null);
    $html = '<h2>' . $po['text'] . '</h2>';
    return tl_detokenize_content(tl_tokenize_content($html, 'en'), 'en');
};
$wynik['kolejnosc'] = [
    'pole_elementu'  => $strona(['text' => 'Zapytaj o wycenę', 'evk_tl_en__text' => 'Get a quote']),
    'slownik'        => $strona(['text' => 'Zapytaj o wycenę', 'evk_tl_en__text' => '']),
    'oryginal'       => $strona(['text' => 'Bez tłumaczenia nigdzie']),
    // SEDNO ZGŁOSZENIA: polski tekst zmieniony po tłumaczeniu — słownik już go
    // nie zna, a pole w elemencie zostaje i dalej działa.
    'po_zmianie_pl'  => $strona(['text' => 'Zapytaj o wycenę projektu', 'evk_tl_en__text' => 'Get a quote']),
    'po_zmianie_bez' => $strona(['text' => 'Zapytaj o wycenę projektu']),
];

echo json_encode($wynik, JSON_UNESCAPED_UNICODE);
}
