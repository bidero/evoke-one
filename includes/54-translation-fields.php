<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — języki dla Evoke FIELDS (1.250.0).
 *
 * Fields (osobna wtyczka) ma wersje językowe wartości pól: tekst, tekst
 * wielowierszowy, WYSIWYG i etykieta linku (Fields 1.70.0,
 * includes/translations.php). Sam nie zna żadnych języków — pyta filtrami,
 * a odpowiada ten moduł. Ładuje się tylko przy włączonych Tłumaczeniach:
 * wyłączone = nikt nie podaje języków = Fields bez żadnych zmian.
 *
 *   evk_fields_jezyki          języki z zakładki Tłumaczeń (bez polskiego);
 *   evk_fields_biezacy_jezyk   język strony, którą właśnie składamy;
 *   evk_fields_url_jezyka      adres z pola Link → adres wersji językowej.
 */

add_filter('evk_fields_jezyki', function ($jezyki) {
    $out = is_array($jezyki) ? $jezyki : [];
    foreach (tl_get_languages() as $kod => $l) {
        $out[(string) $kod] = (string) ($l['name'] ?? $kod);
    }
    return $out;
});

add_filter('evk_fields_biezacy_jezyk', function () {
    return get_current_lang();
});

add_filter('evk_fields_url_jezyka', 'evk_tl_fields_url', 10, 2);

/**
 * Adres zapisany w polu Link → ten sam adres w języku $lang. Surowy adres
 * z pola nie przechodzi przez filtry permalinków, więc bez tego link na
 * stronie EN prowadziłby na polską wersję. Logika od 1.251.0 wspólna
 * z linkami termów i typów treści: `tl_url_jezyka()` (10-language-system.php).
 */
function evk_tl_fields_url(string $url, string $lang): string {
    return tl_url_jezyka($url, $lang);
}
