<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — SEO w wersjach językowych: dane dla zakładki i przeniesienie
 * znaczników `{tl_…}` do pól języków (1.251.0).
 *
 * Do 1.250.0 tytuł i opis SEO w innym języku dało się uzyskać tylko
 * znacznikiem tłumaczenia wpisanym w pole: `{tl_klucz}` (fraza słownika),
 * `{tl:pl=…|en=…}` albo `[tl key=…]`. Działa to dalej — łańcuch w 85-seo.php
 * (`evk_seo_w_jezyku()`) bierze taki tekst przed polską wartością. Przycisk
 * w zakładce SEO zamienia go na zwykłe pola: polski tekst wraca tam, gdzie
 * stał znacznik (Bricks albo zakładka), a tłumaczenia trafiają do PUSTYCH pól
 * języków. Pola „Media społecznościowe" Bricksa zostają nietknięte — nie mają
 * odpowiedników w zakładce, a znacznik w nich działa jak dotąd.
 */

/**
 * Pola SEO z Bricksa i zakładki: klucz => [ustawienie Bricksa, meta zakładki,
 * pole wersji językowej, nazwa dla ludzi].
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
 */
function evk_seo_pola_zrodla(): array {
    return [
        'title'    => ['documentTitle',   '_evoke_seo_title',    'seo_title',    'Tytuł'],
        'desc'     => ['metaDescription', '_evoke_seo_desc',     'seo_desc',     'Opis'],
        'keywords' => ['metaKeywords',    '_evoke_seo_keywords', 'seo_keywords', 'Słowa kluczowe'],
    ];
}

/**
 * Polskie wartości pól SEO wpisu do panelu: Bricks (surowo, bez renderowania
 * danych dynamicznych) i zakładka. Bricks ma pierwszeństwo na stronie.
 *
 * @return array<string, array{bricks: string, zakladka: string}>
 */
function evk_seo_wartosci_panelu(int $pid): array {
    $b = get_post_meta($pid, '_bricks_page_settings', true);
    $b = is_array($b) ? $b : [];
    $out = [];
    foreach (evk_seo_pola_zrodla() as $klucz => [$bk, $mk]) {
        $out[$klucz] = [
            'bricks'   => is_string($b[$bk] ?? null) ? trim($b[$bk]) : '',
            'zakladka' => trim((string) get_post_meta($pid, $mk, true)),
        ];
    }
    return $out;
}

/**
 * Polskie pola SEO wpisu, które działają na stronie (1.271.0): Bricks przed
 * zakładką, surowo (bez renderu danych dynamicznych). Klucz jak w
 * evk_seo_pola_jezykowe(): title, desc, keywords. Źródło tłumaczeń i AI.
 *
 * @return array<string,string>
 */
function evk_seo_pl_pola(int $pid): array {
    $out = [];
    foreach (evk_seo_wartosci_panelu($pid) as $klucz => $w) $out[$klucz] = $w['bricks'] !== '' ? $w['bricks'] : $w['zakladka'];
    return $out;
}

/** Źródło tłumaczenia pola SEO w języku (`_evk_tl_{język}__seo_*__zrodlo`, 1.271.0). */
function evk_seo_zrodlo_jezyka(int $pid, string $lang, string $pole): string {
    return (string) get_post_meta($pid, '_evk_tl_' . $lang . '__' . $pole . '__zrodlo', true);
}

/** „Sprawdzone” (1.271.0): źródło = bieżący polski tekst, bez znacznika AI. Fałsz — brak tłumaczenia albo polskiego tekstu. */
function evk_seo_sprawdzone(int $pid, string $lang, string $pole): bool {
    $klucz = array_search($pole, evk_seo_pola_jezykowe(), true);
    if (!is_string($klucz) || !function_exists('evk_tlw_zrodlo') || evk_seo_meta_jezyka($pid, $lang, $pole) === '') return false;
    $pl = evk_seo_pl_pola($pid)[$klucz] ?? '';
    if (trim($pl) === '') return false;
    update_post_meta($pid, '_evk_tl_' . $lang . '__' . $pole . '__zrodlo', evk_tlw_zrodlo($pl));
    return true;
}

/**
 * Pasek pod polem SEO wersji językowej (1.271.0): ✦ „Przetłumacz” (z kluczem
 * API i dostępem do Tłumaczeń), znak „AI — do sprawdzenia”, „Sprawdzone”
 * i komunikat. Wspólny dla zakładki SEO i metaboksu SEO we wpisie
 * (assets/admin/tl-seo-ai.js). Ukryte pole `.evk-seo-zrodlo` niesie decyzję
 * do zapisu: `ai` (wpis z ✦), `teraz` („Sprawdzone”), pusto — bez zmian albo
 * ręczna poprawka (zapis porówna tekst). $nazwa — `name` pola w formularzu
 * metaboksu; w zakładce SEO zbiera je admin.js.
 */
function evk_seo_narzedzia_pola(string $lang, string $klucz, string $pl, string $wartosc, string $zrodlo, string $etykieta, string $nazwa = ''): void {
    static $ai = false;
    if ($ai === false) $ai = function_exists('evk_tl_ai_dane_przyciskow') ? evk_tl_ai_dane_przyciskow() : null;
    $znak = $wartosc !== '' && function_exists('evk_tlw_ai') && evk_tlw_ai($zrodlo);
    echo '<div class="evk-seo-ai-narzedzia" data-lang="' . esc_attr($lang) . '" data-pole="' . esc_attr($klucz) . '" data-pl="' . esc_attr($pl) . '"'
        . ($znak ? ' data-ai="1"' : '') . '>';
    echo '<input type="hidden" class="evk-seo-zrodlo"' . ($nazwa !== '' ? ' name="' . esc_attr($nazwa) . '"' : '') . ' value="">';
    if ($ai !== null && trim($pl) !== '' && !evk_seo_ma_tl($pl)) {
        echo '<button type="button" class="button button-small evk-seo-ai" aria-label="' . esc_attr('Przetłumacz (AI) — ' . $etykieta) . '">'
            . '<svg class="evk-seo-ai-ikona" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 0C8.6 4.6 11.4 7.4 16 8C11.4 8.6 8.6 11.4 8 16C7.4 11.4 4.6 8.6 0 8C4.6 7.4 7.4 4.6 8 0Z"/></svg>'
            . '<span>Przetłumacz</span></button>';
    }
    echo '<span class="evk-seo-ai-znak">AI — do sprawdzenia</span>';
    echo '<button type="button" class="button button-small evk-seo-sprawdzone" aria-label="' . esc_attr('Sprawdzone: ' . $etykieta) . '">Sprawdzone</button>';
    echo '<span class="evk-seo-ai-stan" role="status"></span>';
    echo '</div>';
}

/** Skrypt i style ✦ przy polach SEO (zakładka SEO, metaboks SEO) z danymi tłumacza AI. */
function evk_seo_ai_zasoby(): void {
    wp_enqueue_style('evk-tl-seo-ai', EVOKE_ONE_URL . 'assets/admin/tl-seo-ai.css', [], EVOKE_ONE_VERSION);
    wp_enqueue_script('evk-tl-seo-ai', EVOKE_ONE_URL . 'assets/admin/tl-seo-ai.js', ['jquery'], EVOKE_ONE_VERSION, true);
    $ai = function_exists('evk_tl_ai_dane_przyciskow') ? evk_tl_ai_dane_przyciskow() : null;
    if ($ai !== null) wp_add_inline_script('evk-tl-seo-ai', 'window.evkSeoAi = ' . wp_json_encode($ai) . ';', 'before');
}

/**
 * Tekst ze znacznikami tłumaczenia wyrenderowany w języku $lang, tak jak na
 * stronie: `{tl_klucz}` i `[tl key=…]` (40-dynamic-data-shortcode.php) oraz
 * `{tl:pl=…|en=…}`. Ten ostatni czyta bieżący język, więc na czas renderu
 * podstawiamy go w `$GLOBALS['lang_code']`.
 */
function evk_seo_tl_tekst(string $surowy, string $lang): string {
    if (!function_exists('tl_replace_tl_tags_in_html')) return $surowy;
    $bylo = array_key_exists('lang_code', $GLOBALS);
    $przed = $GLOBALS['lang_code'] ?? '';
    $GLOBALS['lang_code'] = $lang === 'pl' ? '' : $lang;
    try {
        $tekst = tl_replace_tl_tags_in_html($surowy, $lang);
        if (stripos($tekst, '{tl:') !== false) {
            $tekst = (string) preg_replace_callback('/\{tl:([^}]+)\}/i', static function ($m) {
                return tl_parse_inline_tag($m[1]);
            }, $tekst);
        }
    } finally {
        if ($bylo) {
            $GLOBALS['lang_code'] = $przed;
        } else {
            unset($GLOBALS['lang_code']);
        }
    }
    return trim(wp_strip_all_tags($tekst));
}

/**
 * Wpisy, których pola SEO niosą znacznik tłumaczenia — jedno zapytanie po
 * metadanych zamiast przeglądania wszystkich wpisów.
 *
 * @return list<int>
 */
function evk_seo_tl_wpisy(): array {
    global $wpdb;
    $ids = $wpdb->get_col(
        "SELECT DISTINCT post_id FROM {$wpdb->postmeta}
         WHERE meta_key IN ('_evoke_seo_title', '_evoke_seo_desc', '_evoke_seo_keywords', '_bricks_page_settings')
           AND (meta_value LIKE '%{tl%' OR meta_value LIKE '%[tl %')
         ORDER BY post_id"
    );
    return array_map('intval', (array) $ids);
}

/**
 * Pola SEO ze znacznikiem tłumaczenia, gotowe do przeniesienia. Na wpis i pole:
 * źródło (to, które strona naprawdę pokazuje: Bricks przed zakładką), tekst
 * polski i tłumaczenia. `stan`:
 *   ok        — da się przenieść;
 *   nieznany  — znacznik bez frazy w słowniku: zostaje, jak jest.
 * Tłumaczenie równe polskiemu tekstowi = brak tłumaczenia (słownik oddaje
 * wtedy frazę polską). `obecna` — wartość już wpisana w pole języka;
 * przeniesienie jej nie nadpisuje.
 *
 * @return list<array{post: int, tytul: string, klucz: string, pole: string, zrodlo: string, surowa: string, pl: string,
 *                     stan: string, jezyki: array<string, array{tekst: string, obecna: string}>}>
 */
function evk_seo_tl_kandydaci(): array {
    $jezyki = function_exists('evk_seo_jezyki') ? evk_seo_jezyki() : [];
    $out = [];
    foreach (evk_seo_tl_wpisy() as $pid) {
        $post = get_post($pid);
        if (!$post || in_array($post->post_status, ['trash', 'auto-draft', 'inherit'], true)) continue;
        $wartosci = evk_seo_wartosci_panelu($pid);
        foreach (evk_seo_pola_zrodla() as $klucz => [, , $pole, $nazwa]) {
            $zBricks = $wartosci[$klucz]['bricks'] !== '';
            $surowa  = $zBricks ? $wartosci[$klucz]['bricks'] : $wartosci[$klucz]['zakladka'];
            if (!evk_seo_ma_tl($surowa)) continue;
            $pl = evk_seo_tl_tekst($surowa, 'pl');
            $wiersz = [
                'post' => $pid, 'tytul' => wp_strip_all_tags(get_the_title($pid)) ?: 'ID ' . $pid,
                'klucz' => $klucz, 'pole' => $nazwa, 'zrodlo' => $zBricks ? 'bricks' : 'zakladka',
                'surowa' => $surowa, 'pl' => $pl, 'stan' => evk_seo_ma_tl($pl) || $pl === '' ? 'nieznany' : 'ok', 'jezyki' => [],
            ];
            foreach (array_keys($jezyki) as $kod) {
                $tekst = $wiersz['stan'] === 'ok' ? evk_seo_tl_tekst($surowa, $kod) : '';
                $wiersz['jezyki'][$kod] = [
                    'tekst'  => ($tekst !== $pl && !evk_seo_ma_tl($tekst)) ? $tekst : '',
                    'obecna' => evk_seo_meta_jezyka($pid, $kod, $pole),
                ];
            }
            $out[] = $wiersz;
        }
    }
    return $out;
}

/**
 * Przeniesienie: tłumaczenia do pustych pól języków, polski tekst na miejsce
 * znacznika. Pole ze znacznikiem nieznanym zostaje nietknięte. Drugi raz nie
 * ma już czego przenosić (w polach nie ma znaczników).
 *
 * @return array{pola: int, jezyki: int, pominiete: int}
 */
function evk_seo_tl_przenies(): array {
    $wynik = ['pola' => 0, 'jezyki' => 0, 'pominiete' => 0];
    $zrodla = evk_seo_pola_zrodla();
    foreach (evk_seo_tl_kandydaci() as $k) {
        if ($k['stan'] !== 'ok') {
            $wynik['pominiete']++;
            continue;
        }
        [$bk, $mk, $pole] = $zrodla[$k['klucz']];
        $czysc = static function (string $v) use ($k): string {
            return $k['klucz'] === 'desc' ? sanitize_textarea_field($v) : sanitize_text_field($v);
        };
        foreach ($k['jezyki'] as $kod => $j) {
            if ($j['tekst'] === '' || $j['obecna'] !== '') continue;
            update_post_meta($k['post'], '_evk_tl_' . $kod . '__' . $pole, wp_slash($czysc($j['tekst'])));
            $wynik['jezyki']++;
        }
        if ($k['zrodlo'] === 'bricks') {
            $b = get_post_meta($k['post'], '_bricks_page_settings', true);
            if (!is_array($b)) continue;
            $b[$bk] = $czysc($k['pl']);
            update_post_meta($k['post'], '_bricks_page_settings', wp_slash($b));
        } else {
            update_post_meta($k['post'], $mk, wp_slash($czysc($k['pl'])));
        }
        $wynik['pola']++;
    }
    return $wynik;
}

/* Ten sam nonce co zapis wierszy zakładki (`evoSeoAjax` w includes/admin/page.php). */
add_action('wp_ajax_evoke_seo_tl_podglad', function () {
    check_ajax_referer('evoke_seo_nonce', 'nonce');
    if (!evk_moze('seo')) wp_send_json_error();
    wp_send_json_success(['wiersze' => evk_seo_tl_kandydaci(), 'jezyki' => evk_seo_jezyki()]);
});

add_action('wp_ajax_evoke_seo_tl_przenies', function () {
    check_ajax_referer('evoke_seo_nonce', 'nonce');
    if (!evk_moze('seo')) wp_send_json_error();
    wp_send_json_success(evk_seo_tl_przenies());
});

/* ✦ przy polach SEO w zakładce SEO (1.271.0) — tylko z wersjami językowymi. */
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook === 'settings_page_evoke-one' && evk_seo_jezyki()) evk_seo_ai_zasoby();
});
