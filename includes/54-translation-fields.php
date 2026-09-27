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
 * Adres zapisany w polu Link → ten sam adres w języku $lang.
 *
 * Wewnętrzny (host strony albo ścieżka od `/`): prefiks języka i przetłumaczone
 * slugi, jak w permalinkach i menu (10-language-system.php). Adres z prefiksem
 * innego języka (`/de/…`) przechodzi przez polski, jak przełącznik języków.
 * Zewnętrzny, `mailto:`, `tel:`, sama kotwica, pliki i wp-admin/wp-content —
 * bez zmian: Evoke ONE nie ma ich wersji językowych.
 *
 * Surowy adres z pola nie przechodzi przez filtry permalinków, więc bez tego
 * link na stronie EN prowadziłby na polską wersję.
 */
function evk_tl_fields_url(string $url, string $lang): string {
    $url   = trim($url);
    $kody  = tl_get_active_lang_codes();
    if ($url === '' || $lang === 'pl' || !in_array($lang, $kody, true)) return $url;
    $p = wp_parse_url($url);
    if (!is_array($p)) return $url;

    $wzgledny = !isset($p['host']) && !isset($p['scheme']);
    if ($wzgledny) {
        if ($url[0] !== '/' || strpos($url, '//') === 0) return $url;   // kotwica, zapytanie, ścieżka względna
    } else {
        $home = wp_parse_url((string) get_option('home'));
        $bezWww = static function ($h) { return preg_replace('/^www\./', '', strtolower((string) $h)); };
        if (!in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true)) return $url;
        if (!isset($p['host']) || $bezWww($p['host']) !== $bezWww($home['host'] ?? '')) return $url;
    }

    $sciezka = (string) ($p['path'] ?? '/');
    if ($sciezka === '') $sciezka = '/';
    // WordPress w podkatalogu: prefiks języka stoi za katalogiem strony.
    $baza = rtrim((string) (wp_parse_url((string) get_option('home'), PHP_URL_PATH) ?? ''), '/');
    if ($baza !== '') {
        if (strpos($sciezka . '/', $baza . '/') !== 0) return $url;
        $sciezka = (string) substr($sciezka, strlen($baza));
        if ($sciezka === '') $sciezka = '/';
    }
    if (preg_match('#^/(wp-admin|wp-content|wp-includes|wp-json)(/|$)#', $sciezka)) return $url;
    if (preg_match('#\.[a-z0-9]{2,5}$#i', $sciezka)) return $url;   // plik, nie strona

    // Język, w którym adres zapisano: prefiks /en/… — inaczej polski.
    $z = 'pl';
    if (preg_match('#^/([a-z0-9_-]+)(/|$)#i', $sciezka, $m) && in_array($m[1], $kody, true)) {
        $z = $m[1];
        $sciezka = (string) substr($sciezka, strlen($m[1]) + 1);
        if ($sciezka === '') $sciezka = '/';
    }
    if (trim($sciezka, '/') === '') {
        $nowa = '/' . $lang . '/';
    } else {
        $nowa = '/' . $lang . rtrim(tl_translate_url_path($sciezka, $z, $lang), '/') . (substr($sciezka, -1) === '/' ? '/' : '');
    }

    $wynik = $wzgledny ? '' : $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $wynik .= $baza . $nowa;
    if (isset($p['query']))    $wynik .= '?' . $p['query'];
    if (isset($p['fragment'])) $wynik .= '#' . $p['fragment'];
    return $wynik;
}
