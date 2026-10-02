<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Statystyki: strona raportu (1.283.0, etap 1).
 *
 * Okres, cztery liczby (odsłony, unikalni, średni czas, średnie przewinięcie),
 * wykres dzienny w SVG i listy: strony, źródła, urządzenia, przeglądarki,
 * systemy, języki, kampanie UTM. Porównanie okresów, „teraz na stronie”,
 * CSV, widżet Kokpitu i licznik w pasku admina — etap 2 (1.284.0).
 */

/** Okresy raportu: klucz → [etykieta, liczba dni wstecz włącznie z dziś]. */
const EVK_STAT_OKRESY = ['dzis' => ['Dziś', 1], '7' => ['7 dni', 7], '30' => ['30 dni', 30], '90' => ['90 dni', 90], '365' => ['Rok', 365]];

/** @return array{0:string,1:string} [od, do] w strefie strony */
function evk_stat_zakres(string $okres): array {
    $dni = EVK_STAT_OKRESY[$okres][1] ?? 30;
    return [wp_date('Y-m-d', time() - ($dni - 1) * DAY_IN_SECONDS), wp_date('Y-m-d')];
}

/** Czas w sekundach jako „1 min 05 s”. */
function evk_stat_czas(float $s): string {
    $s = (int) round($s);
    return $s < 60 ? $s . ' s' : intdiv($s, 60) . ' min ' . str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT) . ' s';
}

/**
 * Wykres słupkowy odsłon po dniach (SVG). Każdy słupek ma <title>, a całość
 * opis dla czytnika ekranu.
 *
 * @param array<string,array<string,int>> $dni dzień → liczby
 */
function evk_stat_wykres(array $dni, string $od, string $do): string {
    $seria = [];
    for ($d = $od; $d <= $do; $d = gmdate('Y-m-d', strtotime($d . ' +1 day'))) $seria[$d] = (int) ($dni[$d]['odslony'] ?? 0);
    $max = max(1, max($seria ?: [0]));
    $n = count($seria);
    $w = 720; $h = 160; $szer = $w / max(1, $n);
    $out = '<svg class="evk-stat-wykres" viewBox="0 0 ' . $w . ' ' . ($h + 4) . '" preserveAspectRatio="none" role="img" aria-label="'
        . esc_attr(sprintf('Odsłony dziennie od %s do %s, najwięcej %d', $od, $do, $max)) . '">';
    $i = 0;
    foreach ($seria as $d => $v) {
        $bh = $v > 0 ? max(2, $v / $max * $h) : 0;
        $out .= '<rect x="' . round($i * $szer + $szer * 0.12, 2) . '" y="' . round($h - $bh, 2) . '" width="' . round($szer * 0.76, 2) . '" height="' . round($bh, 2)
            . '"><title>' . esc_html($d . ': ' . $v . ' odsłon') . '</title></rect>';
        $i++;
    }
    return $out . '<line x1="0" y1="' . $h . '" x2="' . $w . '" y2="' . $h . '" /></svg>';
}

/**
 * Tabela jednego wymiaru (10 pierwszych wartości).
 *
 * @param array<string,array<string,int>> $dane
 * @param array<string,string>            $nazwy wartość → etykieta
 */
function evk_stat_tabela_html(string $tytul, string $kolumna, array $dane, array $nazwy = []): string {
    $html = '<div class="evo-box evk-stat-lista"><h3>' . esc_html($tytul) . '</h3>';
    if (!$dane) return $html . '<p class="evo-muted">Brak danych w tym okresie.</p></div>';
    $html .= '<div class="evo-tbl-wrap"><table class="evo-table"><thead><tr><th scope="col">' . esc_html($kolumna)
        . '</th><th scope="col" class="num">Odsłony</th><th scope="col" class="num">Unikalni</th></tr></thead><tbody>';
    foreach (array_slice($dane, 0, 10, true) as $w => $l) {
        $html .= '<tr><td>' . esc_html($nazwy[$w] ?? (string) $w) . '</td><td class="num">' . number_format_i18n($l['odslony'])
            . '</td><td class="num">' . number_format_i18n($l['unikalni']) . '</td></tr>';
    }
    return $html . '</tbody></table></div></div>';
}

function evk_stat_render_raport(): void {
    if (!evk_stat_moze_czytac()) wp_die('Brak uprawnień.');
    /* Zbiórka przy otwarciu raportu, gdy WP-Cron nie zdążył albo nie chodzi. */
    if (evk_stat_zebrane_do() < wp_date('Y-m-d', time() - DAY_IN_SECONDS)) evk_stat_zbiorka();

    $okres = sanitize_key($_GET['okres'] ?? '30');
    if (!isset(EVK_STAT_OKRESY[$okres])) $okres = '30';
    [$od, $do] = evk_stat_zakres($okres);
    $razem = evk_stat_dane('razem', $od, $do)[''] ?? ['odslony' => 0, 'unikalni' => 0, 'czas_suma' => 0, 'czas_ile' => 0, 'przewiniecie_suma' => 0];
    $baza  = evk_stat_adres_raportu();
    wp_enqueue_style('evoke-one-admin', EVOKE_ONE_URL . 'assets/admin/admin.css', [], EVOKE_ONE_VERSION);
    $liczby = [
        'Odsłony'             => number_format_i18n($razem['odslony']),
        'Unikalni'            => number_format_i18n($razem['unikalni']),
        'Średni czas'         => $razem['czas_ile'] ? evk_stat_czas($razem['czas_suma'] / $razem['czas_ile']) : '—',
        'Średnie przewinięcie'=> $razem['czas_ile'] ? (int) round($razem['przewiniecie_suma'] / $razem['czas_ile']) . '%' : '—',
    ];
    $jezyki = evk_stat_dane('jezyk', $od, $do);
    $nazwy_jezykow = [];
    if (function_exists('tl_get_languages')) foreach (tl_get_languages() as $kod => $j) $nazwy_jezykow[$kod] = (string) ($j['name'] ?? $kod);
    $nazwy_jezykow['pl'] = $nazwy_jezykow['pl'] ?? 'Polski';
    ?>
    <div class="wrap evk-stat">
        <h1>Statystyki</h1>
        <style>
            .evk-stat-okresy { display: flex; flex-wrap: wrap; gap: 6px; margin: 12px 0 16px; }
            .evk-stat-liczby { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 16px; }
            .evk-stat-liczba { background: #fff; border: 1px solid #dcdcde; border-radius: 8px; padding: 14px 16px; }
            .evk-stat-liczba span { display: block; color: #50575e; font-size: 13px; }
            .evk-stat-liczba strong { font-size: 24px; line-height: 1.3; }
            .evk-stat-wykres { width: 100%; height: 180px; display: block; }
            .evk-stat-wykres rect { fill: #2563eb; }
            .evk-stat-wykres line { stroke: #c3c4c7; }
            .evk-stat-siatka { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; }
            .evk-stat-siatka .evo-box, .evk-stat .evo-box { margin: 0; }
            .evk-stat table .num { text-align: right; white-space: nowrap; }
            .evk-stat td:first-child { word-break: break-word; }
        </style>
        <nav class="evk-stat-okresy" aria-label="Okres raportu">
            <?php foreach (EVK_STAT_OKRESY as $k => [$etykieta]): $k = (string) $k; /* klucz „7” PHP trzyma jako liczbę */ ?>
            <a class="button<?php echo $k === $okres ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg('okres', $k, $baza)); ?>"<?php echo $k === $okres ? ' aria-current="page"' : ''; ?>><?php echo esc_html($etykieta); ?></a>
            <?php endforeach; ?>
        </nav>
        <div class="evk-stat-liczby">
            <?php foreach ($liczby as $etykieta => $wartosc): ?>
            <div class="evk-stat-liczba"><span><?php echo esc_html($etykieta); ?></span><strong><?php echo esc_html($wartosc); ?></strong></div>
            <?php endforeach; ?>
        </div>
        <div class="evo-box evo-mb">
            <h3>Odsłony dziennie</h3>
            <?php echo evk_stat_wykres(evk_stat_dane('razem', $od, $do, true), $od, $do); // phpcs:ignore — SVG składany z liczb i esc_* ?>
            <p class="evo-hint">Unikalni to suma dziennych: bez cookies tej samej osoby z dwóch dni nie da się połączyć.</p>
        </div>
        <div class="evk-stat-siatka">
            <?php
            echo evk_stat_tabela_html('Strony', 'Adres', evk_stat_dane('strona', $od, $do));
            echo evk_stat_tabela_html('Źródła', 'Skąd', evk_stat_dane('zrodlo', $od, $do));
            echo evk_stat_tabela_html('Urządzenia', 'Urządzenie', evk_stat_dane('urzadzenie', $od, $do), ['telefon' => 'Telefon', 'tablet' => 'Tablet', 'komputer' => 'Komputer']);
            echo evk_stat_tabela_html('Przeglądarki', 'Przeglądarka', evk_stat_dane('przegladarka', $od, $do));
            echo evk_stat_tabela_html('Systemy', 'System', evk_stat_dane('system', $od, $do));
            if (count($jezyki) > 1) echo evk_stat_tabela_html('Języki strony', 'Język', $jezyki, $nazwy_jezykow);
            foreach (['utm_source' => 'Kampanie: źródło (utm_source)', 'utm_medium' => 'Kampanie: medium (utm_medium)', 'utm_campaign' => 'Kampanie: nazwa (utm_campaign)'] as $w => $t) {
                $d = evk_stat_dane($w, $od, $do);
                if ($d) echo evk_stat_tabela_html($t, 'Wartość', $d);
            }
            ?>
        </div>
    </div>
    <?php
}
