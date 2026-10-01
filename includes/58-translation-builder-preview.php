<?php
if (!defined('ABSPATH')) exit;

/**
 * Podgląd tłumaczeń w builderze Bricksa (1.257.0, #82): przełącznik PL | EN | DE
 * w pasku buildera, obok breakpointów, i kanwa w wybranym języku — na żywo
 * z pól „Tłumaczenie EN", „Obraz EN" i „SVG EN". Tekst bez tłumaczenia zostaje
 * polski z obrysem i znaczkiem „brak EN".
 *
 * Cała logika w `assets/admin/tl-builder-podglad.js`; tu wpięcie w kanwę i dane:
 * języki, mapa pól tłumaczalnych (ta sama co przy przeniesieniu ze słownika)
 * i słownik `{tl_…}` — skrypt przejmuje w kanwie rozwijanie tych tagów
 * (40-dynamic-data-shortcode.php robi to już tylko poza kanwą).
 *
 * Ładowane WYŁĄCZNIE w kanwie. Przełącznik trafia do paska powłoki z kanwy
 * (`window.parent`, to samo pochodzenie), więc nie trzeba skryptu w powłoce
 * ani wąskiego `bricks_is_builder_main()` (tests/builder-context.test.js).
 *
 * Ten sam skrypt stawia przyciski „Przetłumacz (AI)” (1.265.0): przy
 * przełączniku i pod polami „Tłumaczenie EN” w panelu powłoki. Dane `ai`
 * (adres AJAX, nonce, wpis, model) daje 61 — bez dostępu do Tłumaczeń albo
 * bez klucza API są puste i przycisków nie ma.
 */

/** Słownik `{tl_klucz}` → fraza PL i jej tłumaczenia, tą samą drogą co tl_get_dd_value(). */
function evk_tl_podglad_slownik(array $jezyki): array {
    $klucze = (array) get_option('tl_dd_keys', []);
    $dane   = get_option('tl_translations', ['groups' => []]);
    foreach ((is_array($dane) ? ($dane['groups'] ?? []) : []) as $grupa) {
        foreach ((array) ($grupa['rows'] ?? []) as $wiersz) {
            $k = sanitize_key((string) ($wiersz['dd_key'] ?? ''));
            if ($k !== '' && empty($klucze[$k])) $klucze[$k] = trim((string) ($wiersz['pl'] ?? ''));
        }
    }
    $config = get_translation_config();
    $wynik  = [];
    foreach ($klucze as $k => $pl) {
        $k  = sanitize_key((string) $k);
        $pl = is_scalar($pl) ? (string) $pl : '';
        if ($k === '' || $pl === '') continue;
        $w = ['pl' => $pl];
        foreach ($jezyki as $j) {
            if ($j === 'pl') continue;
            $t = $config['strings'][$pl][$j] ?? '';
            if (is_string($t) && $t !== '') $w[$j] = $t;
        }
        $wynik[$k] = $w;
    }
    return $wynik;
}

/** Dane skryptu kanwy. */
function evk_tl_podglad_dane(): array {
    $jezyki = array_values(array_unique(array_merge(['pl'], array_map('strval', evk_tl_kody_jezykow()))));
    $mapa   = function_exists('evk_tl_el_mapa') ? evk_tl_el_mapa() : [];
    /* Elementy zarejestrowane w tym żądaniu, zanim mapa trafi do bazy na `shutdown`. */
    if (!empty($GLOBALS['evk_tl_el_mapa_nowa']) && is_array($GLOBALS['evk_tl_el_mapa_nowa'])) {
        $mapa = array_merge($mapa, $GLOBALS['evk_tl_el_mapa_nowa']);
    }
    return [
        'jezyki'  => $jezyki,
        'mapa'    => (object) $mapa,
        'slownik' => (object) evk_tl_podglad_slownik($jezyki),
        /* Przyciski AI (1.265.0): null bez dostępu do Tłumaczeń albo bez klucza API. */
        'ai'      => function_exists('evk_tl_ai_builder_dane') ? evk_tl_ai_builder_dane() : null,
        'napisy'  => [
            'brak'     => 'brak %s',
            'grupa'    => 'Podgląd języka',
            'przycisk' => 'Podgląd: %s',
        ],
    ];
}

/** Kanwa buildera i ktoś, kto może edytować — tylko wtedy podgląd. */
function evk_tl_podglad_wlaczony(): bool {
    return evk_tl_kanwa_buildera() && current_user_can('edit_posts');
}

add_action('wp_enqueue_scripts', function () {
    if (!evk_tl_podglad_wlaczony()) return;
    /* Właściwości tłumaczeń komponentów (1.272.0) — czysty moduł, ta sama logika co 51. */
    wp_enqueue_script('evk-tl-komponenty', EVOKE_ONE_URL . 'assets/admin/tl-komponenty.js', [], EVOKE_ONE_VERSION, true);
    wp_enqueue_script('evk-tl-podglad', EVOKE_ONE_URL . 'assets/admin/tl-builder-podglad.js', ['evk-tl-komponenty'], EVOKE_ONE_VERSION, true);
}, 20);

/* Dane przed skryptami stopki (`wp_print_footer_scripts` ma priorytet 20). JSON
   w <script type="application/json"> — dane, nie kod; JSON_HEX_TAG nie wypuści
   `</script>` z frazy słownika. */
add_action('wp_footer', function () {
    if (!evk_tl_podglad_wlaczony()) return;
    echo '<script type="application/json" id="evk-tl-podglad-dane">'
        . wp_json_encode(evk_tl_podglad_dane(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP)
        . '</script>' . "\n";
}, 5);
