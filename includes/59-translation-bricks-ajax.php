<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — żądania AJAX Bricksa na stronach w języku (1.253.1).
 *
 * Filtr zapytania, stronicowanie AJAX, nieskończone przewijanie i popup
 * wysyłają POST na `bricksData.restApiUrl` (`/wp-json/bricks/v1/query_result`,
 * `load_query_page`, `load_popup_content`) i podmieniają kawałek strony
 * odpowiedzią. Adres REST nie ma prefiksu języka, a język wtyczka bierze
 * z pierwszego członu adresu — więc na `/en/…` po kliknięciu filtra nazwy
 * kategorii, tytuły, pola języków w elementach i linki wracały po polsku
 * (zgłoszenie ze strony).
 *
 * 1. Strona w języku przestawia `bricksData.restApiUrl` na `/en/wp-json/bricks/v1/`.
 *    WordPress zdejmuje ścieżkę strony głównej (z prefiksem, filtr `home_url`)
 *    przy dopasowaniu reguł, więc żądanie trafia do REST, a język bierze się
 *    z prefiksu jak na każdej stronie. `bricksData.language` zostaje: Bricks
 *    wysyła go w ciele żądania, a co robi z nim na serwerze (WPML, Polylang),
 *    tego tu nie widać.
 * 2. Adres bez prefiksu (strona z pamięci podręcznej sprzed 1.253.1): język
 *    z nagłówka Referer (10-language-system.php, `tl_jezyk_z_referera`).
 * 3. Odpowiedź: każdy napis przechodzi przez `tl_przetworz_html()` — to samo,
 *    co bufor strony robi z całą stroną. Bez tego słownik zostawiał tokeny
 *    `##TL_…##`: `bricks/frontend/render_data` zamienia frazy na tokeny,
 *    a rozwija je dopiero bufor strony, którego w REST nie ma. Style też:
 *    słownik tłumaczy tylko całe węzły tekstu (`>…<`), więc CSS-u nie rusza,
 *    a mapa obrazów (zakładka „Obrazki") ma podmienić adres tła, jak na
 *    stronie.
 */

/* 1. Adres REST Bricksa z prefiksem języka — po `bricksData` (drukuje go stopka
   Bricksa, `wp_print_footer_scripts` na priorytecie 20). */
add_action('wp_footer', function () {
    if (is_admin() || tl_is_bricks_editor() || tl_is_bricks_preview()) return;
    $lang = get_current_lang();
    if ($lang === 'pl' || !wp_script_is('bricks-scripts', 'done')) return;
    $adres = rest_url('bricks/v1/');
    $przed = (string) wp_parse_url($adres, PHP_URL_PATH);
    $po    = (string) wp_parse_url(tl_add_lang_prefix_to_url($adres, $lang), PHP_URL_PATH);
    if ($przed === '' || $po === '' || $przed === $po) return;
    /* Porównanie samej ścieżki: Bricks może podać adres pełny albo względny. */
    echo '<script id="evk-tl-bricks-rest">(function(){var d=window.bricksData;if(!d||!d.restApiUrl)return;'
        . 'try{var u=new URL(d.restApiUrl,location.href);if(u.pathname===' . wp_json_encode($przed) . '){u.pathname='
        . wp_json_encode($po) . ';d.restApiUrl=u.href;}}catch(e){}})();</script>' . "\n";
}, 100);

/**
 * Napisy odpowiedzi (także w zagnieżdżonych tablicach i obiektach) przez
 * `tl_przetworz_html()`. Obiekty zostają obiektami: `popups` Bricks czyta
 * przez `Object.entries`, więc `{}` nie może się zamienić w `[]`.
 *
 * @param mixed $dane
 * @return mixed
 */
function evk_tl_rest_obrob($dane, string $lang) {
    if (is_string($dane)) return tl_przetworz_html($dane, $lang);
    if (is_array($dane)) {
        foreach ($dane as $k => $v) $dane[$k] = evk_tl_rest_obrob($v, $lang);
        return $dane;
    }
    if ($dane instanceof stdClass) {
        foreach (get_object_vars($dane) as $k => $v) $dane->{$k} = evk_tl_rest_obrob($v, $lang);
    }
    return $dane;
}

/* 3a. Główna droga: dane odpowiedzi REST, zanim WordPress zamieni je na JSON. */
add_filter('rest_pre_echo_response', function ($wynik, $serwer, $zadanie) {
    if (!($zadanie instanceof WP_REST_Request) || !tl_trasa_bricksa_z_jezykiem($zadanie->get_route())) return $wynik;
    $GLOBALS['evk_tl_rest_obrobione'] = true;
    return evk_tl_rest_obrob($wynik, get_current_lang());
}, 10, 3);

/* 3b. Zapas: gdyby Bricks wysyłał odpowiedź sam (`wp_send_json` + `die`),
   filtru wyżej nie ma — bufor łapie wynik na końcu żądania. Po filtrze nic nie
   robi. JSON dekodowany do obiektów, żeby `{}` zostało `{}`. */
add_action('rest_api_init', function () {
    if (!tl_trasa_bricksa_z_jezykiem(tl_trasa_rest())) return;
    ob_start(function ($bufor) {
        if (!empty($GLOBALS['evk_tl_rest_obrobione']) || !is_string($bufor) || $bufor === '') return $bufor;
        $dane = json_decode($bufor);
        if (json_last_error() !== JSON_ERROR_NONE || !(is_object($dane) || is_array($dane))) return $bufor;
        $nowe = wp_json_encode(evk_tl_rest_obrob($dane, get_current_lang()));
        return is_string($nowe) ? $nowe : $bufor;
    });
});
