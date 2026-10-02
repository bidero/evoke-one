<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Statystyki: eksport CSV (1.285.0).
 *
 * Okres raportu w jednym pliku, wiersz na wartość: „Sekcja;Wartość;Odsłony;
 * Unikalni;Średni czas (s);Średnie przewinięcie (%)”. Sekcje jak w raporcie,
 * ale CAŁE listy (raport pokazuje po 10), do tego „Razem” i „Dzień”.
 * Średnik i BOM UTF-8, bo tak otwiera go polski Excel bez importu.
 *
 * Wartości tekstowe pochodzą od odwiedzających (adres, źródło, utm_*),
 * więc pole zaczynające się od „=”, „+”, „-”, „@” albo tabulatora dostaje
 * apostrof z przodu — inaczej arkusz potraktuje je jako formułę.
 */

/** Pole tekstowe CSV bez formuły arkusza. */
function evk_stat_csv_pole(string $v): string {
    return $v !== '' && strpos("=+-@\t\r", $v[0]) !== false ? "'" . $v : $v;
}

/** Zawartość pliku CSV dla zakresu dni. */
function evk_stat_csv(string $od, string $do): string {
    $f = fopen('php://temp', 'r+');
    if ($f === false) return '';
    $wiersz = static function (string $sekcja, string $wartosc, array $l) use ($f): void {
        $ile = (int) $l['czas_ile'];
        fputcsv($f, [$sekcja, evk_stat_csv_pole($wartosc), (int) $l['odslony'], (int) $l['unikalni'],
            $ile ? (int) round($l['czas_suma'] / $ile) : '', $ile ? (int) round($l['przewiniecie_suma'] / $ile) : ''], ';', '"', '');
    };
    fputcsv($f, ['Sekcja', 'Wartość', 'Odsłony', 'Unikalni', 'Średni czas (s)', 'Średnie przewinięcie (%)'], ';', '"', '');
    $pusto = ['odslony' => 0, 'unikalni' => 0, 'czas_suma' => 0, 'czas_ile' => 0, 'przewiniecie_suma' => 0];
    $wiersz('Razem', $od . ' – ' . $do, evk_stat_dane('razem', $od, $do)[''] ?? $pusto);
    $dni = evk_stat_dane('razem', $od, $do, true);
    for ($d = $od; $d <= $do; $d = gmdate('Y-m-d', strtotime($d . ' +1 day'))) $wiersz('Dzień', $d, $dni[$d] ?? $pusto);

    $nazwy = ['urzadzenie' => ['telefon' => 'Telefon', 'tablet' => 'Tablet', 'komputer' => 'Komputer']];
    foreach (array_keys(evk_stat_dane('kraj', $od, $do)) as $kod) $nazwy['kraj'][$kod] = evk_stat_nazwa_kraju((string) $kod);
    foreach (array_keys(evk_stat_dane('zdarzenie', $od, $do)) as $k) {
        [$r, $e] = array_pad(explode(':', (string) $k, 2), 2, '');
        $nazwy['zdarzenie'][$k] = (EVK_STAT_ZDARZENIA[$r] ?? $r) . ': ' . $e;
    }
    $sekcje = ['strona' => 'Strony', 'zrodlo' => 'Źródła', 'odsylacz' => 'Adresy odsyłające', 'urzadzenie' => 'Urządzenia', 'przegladarka' => 'Przeglądarki', 'system' => 'Systemy',
               'jezyk' => 'Języki strony', 'kraj' => 'Kraje', 'zdarzenie' => 'Zdarzenia',
               'utm_source' => 'Kampanie: źródło', 'utm_medium' => 'Kampanie: medium', 'utm_campaign' => 'Kampanie: nazwa'];
    foreach ($sekcje as $wymiar => $sekcja) {
        foreach (evk_stat_dane($wymiar, $od, $do) as $w => $l) $wiersz($sekcja, (string) ($nazwy[$wymiar][$w] ?? $w), $l);
    }
    rewind($f);
    $csv = (string) stream_get_contents($f);
    fclose($f);
    return "\xEF\xBB\xBF" . $csv;
}

/** Adres pobrania CSV dla okresu raportu. */
function evk_stat_adres_csv(string $okres): string {
    return wp_nonce_url(admin_url('admin-post.php?action=evk_stat_csv&okres=' . rawurlencode($okres)), 'evk_stat_csv');
}

/* Pobranie — każdy, kto czyta raporty (administrator i rola z uprawnieniem „Statystyki”). */
add_action('admin_post_evk_stat_csv', function (): void {
    check_admin_referer('evk_stat_csv');
    if (!evk_stat_moze_czytac()) wp_die('Brak uprawnień.', '', ['response' => 403]);
    $okres = sanitize_key($_GET['okres'] ?? '30');
    if (!isset(EVK_STAT_OKRESY[$okres])) $okres = '30';
    [$od, $do] = evk_stat_zakres($okres);
    $host = (string) preg_replace('/[^a-z0-9.-]/', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="statystyki-' . $host . '-' . $od . '-' . $do . '.csv"');
    header('X-Content-Type-Options: nosniff');
    echo evk_stat_csv($od, $do); // phpcs:ignore — plik CSV, pola w fputcsv
    exit;
});
