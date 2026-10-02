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
 * Dni wykresu: odsłony i unikalni w zakresie oraz odsłony poprzedniego okresu
 * tej samej długości, dzień do dnia (1.285.0).
 *
 * @return list<array{dzien:string,odslony:int,unikalni:int,poprz:int,poprz_dzien:string}>
 */
function evk_stat_seria(string $od, string $do): array {
    $n = (int) round((strtotime($do) - strtotime($od)) / DAY_IN_SECONDS) + 1;
    $pod = gmdate('Y-m-d', strtotime($od . ' -' . $n . ' day'));
    $teraz = evk_stat_dane('razem', $od, $do, true);
    $przed = evk_stat_dane('razem', $pod, gmdate('Y-m-d', strtotime($od . ' -1 day')), true);
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $d = gmdate('Y-m-d', strtotime($od . ' +' . $i . ' day'));
        $p = gmdate('Y-m-d', strtotime($pod . ' +' . $i . ' day'));
        $out[] = ['dzien' => $d, 'odslony' => (int) ($teraz[$d]['odslony'] ?? 0), 'unikalni' => (int) ($teraz[$d]['unikalni'] ?? 0),
                  'poprz' => (int) ($przed[$p]['odslony'] ?? 0), 'poprz_dzien' => $p];
    }
    return $out;
}

/**
 * Wykres dzienny w SVG (1.285.0): słupki odsłon albo linie — odsłony,
 * unikalni i przerywana linia odsłon poprzedniego okresu. Oba widoki w jednym
 * SVG, przełącza je klasa pudełka (wybór pamięta przeglądarka). Niewidoczne
 * pasy dni niosą podpowiedź po najechaniu; dokładne liczby są w tabeli pod spodem.
 *
 * @param list<array{dzien:string,odslony:int,unikalni:int,poprz:int,poprz_dzien:string}> $seria
 */
function evk_stat_wykres(array $seria): string {
    $n = max(1, count($seria));
    $max = 1;
    foreach ($seria as $r) $max = max($max, $r['odslony'], $r['unikalni'], $r['poprz']);
    $w = 720; $h = 160; $slot = $w / $n;
    $y = static function (int $v) use ($max, $h): float { return round($h - $v / $max * ($h - 4), 2); };
    $x = static function (int $i) use ($slot): float { return round($i * $slot + $slot / 2, 2); };
    $linia = static function (string $pole, string $klasa) use ($seria, $x, $y): string {
        $pkt = [];
        foreach ($seria as $i => $r) $pkt[] = $x($i) . ',' . $y($r[$pole]);
        return '<polyline class="' . $klasa . '" vector-effect="non-scaling-stroke" points="' . implode(' ', $pkt) . '" />';
    };
    $od = $seria[0]['dzien'] ?? ''; $do = $seria[$n - 1]['dzien'] ?? '';
    $out = '<svg class="evk-stat-wykres" viewBox="0 0 ' . $w . ' ' . ($h + 2) . '" preserveAspectRatio="none" role="img" aria-label="'
        . esc_attr(sprintf('Odsłony dziennie od %s do %s, najwięcej %d. Liczby w tabeli pod wykresem.', $od, $do, $max)) . '">';
    $out .= '<line class="evk-w-os" vector-effect="non-scaling-stroke" x1="0" y1="' . $h . '" x2="' . $w . '" y2="' . $h . '" />';
    $out .= '<g class="evk-w-slupki">';
    $bw = min($slot * 0.6, 24);
    foreach ($seria as $i => $r) {
        if ($r['odslony'] <= 0) continue;
        $bh = max(2, $h - $y($r['odslony']));
        $out .= '<rect class="evk-w-slupek" x="' . round($x($i) - $bw / 2, 2) . '" y="' . round($h - $bh, 2) . '" width="' . round($bw, 2) . '" height="' . round($bh, 2) . '" />';
    }
    $out .= '</g><g class="evk-w-linie">' . $linia('poprz', 'evk-w-poprz') . $linia('unikalni', 'evk-w-unikalni') . $linia('odslony', 'evk-w-odslony') . '</g>';
    $out .= '<g class="evk-w-pasy">';
    foreach ($seria as $i => $r) {
        $out .= '<rect class="evk-w-pas" data-i="' . $i . '" x="' . round($i * $slot, 2) . '" y="0" width="' . round($slot, 2) . '" height="' . $h . '" />';
    }
    return $out . '</g></svg>';
}

/** Zmiana względem poprzedniego okresu jako „+12%” / „−5%” / „nowe” / „0%”. */
function evk_stat_zmiana(float $teraz, float $przed): string {
    if ($przed <= 0) return $teraz > 0 ? 'nowe' : '';
    $p = (int) round(($teraz - $przed) / $przed * 100);
    return ($p > 0 ? '+' : ($p < 0 ? '−' : '')) . abs($p) . '%';
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
    $pusto = ['odslony' => 0, 'unikalni' => 0, 'czas_suma' => 0, 'czas_ile' => 0, 'przewiniecie_suma' => 0];
    $razem = evk_stat_dane('razem', $od, $do)[''] ?? $pusto;
    /* Poprzedni okres tej samej długości (1.285.0) — do zmiany przy liczbach i przerywanej linii. */
    $seria = evk_stat_seria($od, $do);
    $prz = evk_stat_dane('razem', $seria[0]['poprz_dzien'], $seria[count($seria) - 1]['poprz_dzien'])[''] ?? $pusto;
    $sr = static function (array $r, string $p): float { return $r['czas_ile'] ? $r[$p] / $r['czas_ile'] : 0.0; };
    $zmiany = [
        'Odsłony' => evk_stat_zmiana($razem['odslony'], $prz['odslony']), 'Unikalni' => evk_stat_zmiana($razem['unikalni'], $prz['unikalni']),
        'Średni czas' => evk_stat_zmiana($sr($razem, 'czas_suma'), $sr($prz, 'czas_suma')),
        'Średnie przewinięcie' => evk_stat_zmiana($sr($razem, 'przewiniecie_suma'), $sr($prz, 'przewiniecie_suma')),
    ];
    $poprzedni = $okres === 'dzis' ? 'wczoraj' : 'poprzednie ' . EVK_STAT_OKRESY[$okres][0];
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
            .evk-stat-teraz { margin: 0 0 12px; font-size: 14px; }
            .evk-stat-cele-tytul { margin: 24px 0 4px; }
            .evk-stat-konwersja { font-size: 22px; margin-right: 6px; }
            .evk-stat-cel-raport .evo-tbl-wrap + .evo-tbl-wrap { margin-top: 12px; }
            .evk-stat-kropka { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #1baf7a; margin-right: 6px; vertical-align: middle; }
            .evk-stat-liczba small { display: block; color: #50575e; font-size: 12px; margin-top: 2px; }
            /* Wykres (1.285.0): seria 1 niebieska, seria 2 pomarańczowa, poprzedni okres — seria 1 przerywana. */
            .evk-stat-wykres-box { --s1: #2a78d6; --s2: #eb6834; --siatka: #dcdcde; position: relative; }
            .evk-stat-wykres-gora { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
            .evk-stat-wykres-gora h3 { margin: 0; }
            .evk-stat-tryby { display: flex; gap: 4px; }
            .evk-stat-tryby .button[aria-pressed="true"] { background: #2271b1; border-color: #2271b1; color: #fff; }
            .evk-stat-legenda { display: flex; flex-wrap: wrap; gap: 6px 16px; margin: 10px 0 0; padding: 0; list-style: none; font-size: 13px; color: #50575e; }
            .evk-stat-legenda li { display: flex; align-items: center; gap: 6px; margin: 0; }
            .evk-stat-legenda i { display: inline-block; width: 18px; height: 0; border-top: 2px solid var(--s1); }
            .evk-stat-legenda .l2 i { border-top-color: var(--s2); }
            .evk-stat-legenda .l3 i { border-top-style: dashed; opacity: .6; }
            .evk-stat-max { font-size: 12px; color: #50575e; margin: 8px 0 2px; }
            .evk-stat-wykres { width: 100%; height: 180px; display: block; overflow: visible; }
            .evk-stat-wykres .evk-w-os { stroke: var(--siatka); stroke-width: 1; }
            .evk-stat-wykres .evk-w-slupek { fill: var(--s1); }
            .evk-stat-wykres polyline { fill: none; stroke-width: 2; stroke-linejoin: round; stroke-linecap: round; }
            .evk-stat-wykres .evk-w-odslony { stroke: var(--s1); }
            .evk-stat-wykres .evk-w-unikalni { stroke: var(--s2); }
            .evk-stat-wykres .evk-w-poprz { stroke: var(--s1); stroke-dasharray: 5 4; opacity: .6; }
            .evk-stat-wykres .evk-w-pas { fill: transparent; }
            .evk-stat-wykres .evk-w-pas.is-aktywny { fill: rgba(0, 0, 0, .05); }
            .evk-stat-wykres-box[data-tryb="slupki"] .evk-w-linie, .evk-stat-wykres-box[data-tryb="slupki"] .evk-stat-legenda { display: none; }
            .evk-stat-wykres-box[data-tryb="linie"] .evk-w-slupki { display: none; }
            .evk-stat-dymek { position: absolute; z-index: 2; pointer-events: none; background: #1d2327; color: #fff; border-radius: 6px; padding: 6px 10px; font-size: 12px; line-height: 1.5; white-space: nowrap; }
            .evk-stat-dymek[hidden] { display: none; }
            .evk-stat-dni summary { cursor: pointer; margin-top: 8px; }
            .evk-stat-siatka { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 12px; }
            /* Zero tylko w siatce list (odstęp daje gap). Do 1.284.0 reguła łapała też pudełko wykresu,
               a jego `evo-mb` przegrywało — lista przylegała do wykresu (zgłoszenie z testowej). */
            .evk-stat-siatka .evo-box { margin: 0; }
            .evk-stat .evk-stat-wykres-box { margin: 0 0 16px; }
            .evk-stat table .num { text-align: right; white-space: nowrap; }
            .evk-stat td:first-child { word-break: break-word; }
        </style>
        <nav class="evk-stat-okresy" aria-label="Okres raportu">
            <?php foreach (EVK_STAT_OKRESY as $k => [$etykieta]): $k = (string) $k; /* klucz „7” PHP trzyma jako liczbę */ ?>
            <a class="button<?php echo $k === $okres ? ' button-primary' : ''; ?>" href="<?php echo esc_url(add_query_arg('okres', $k, $baza)); ?>"<?php echo $k === $okres ? ' aria-current="page"' : ''; ?>><?php echo esc_html($etykieta); ?></a>
            <?php endforeach; ?>
        </nav>
        <?php $evk_teraz = evk_stat_teraz(); ?>
        <p class="evk-stat-teraz"><span class="evk-stat-kropka" aria-hidden="true"></span>Teraz na stronie: <strong><?php echo (int) $evk_teraz['wizyty']; ?></strong>
            <span class="evo-muted">(ostatnie <?php echo (int) EVK_STAT_TERAZ_MIN; ?> min<?php echo $evk_teraz['strony'] ? ': ' . esc_html(implode(', ', array_map(static function ($s, $l) { return $s . ' ' . $l; }, array_keys($evk_teraz['strony']), $evk_teraz['strony']))) : ''; ?>)</span></p>
        <div class="evk-stat-liczby">
            <?php foreach ($liczby as $etykieta => $wartosc): ?>
            <div class="evk-stat-liczba"><span><?php echo esc_html($etykieta); ?></span><strong><?php echo esc_html($wartosc); ?></strong>
                <?php if ($zmiany[$etykieta] !== ''): ?><small><?php echo esc_html($zmiany[$etykieta] . ' wobec: ' . $poprzedni); ?></small><?php endif; ?></div>
            <?php endforeach; ?>
        </div>
        <?php $evk_max = max(array_map(static function ($r) { return max($r['odslony'], $r['unikalni'], $r['poprz']); }, $seria) ?: [0]); ?>
        <div class="evo-box evk-stat-wykres-box" data-tryb="slupki" data-seria="<?php echo esc_attr((string) wp_json_encode($seria)); ?>">
            <div class="evk-stat-wykres-gora">
                <h3>Odsłony dziennie</h3>
                <div class="evk-stat-tryby" role="group" aria-label="Rodzaj wykresu">
                    <button type="button" class="button" data-tryb="slupki" aria-pressed="true">Słupki</button>
                    <button type="button" class="button" data-tryb="linie" aria-pressed="false">Linie</button>
                </div>
            </div>
            <ul class="evk-stat-legenda">
                <li><i></i>Odsłony</li><li class="l2"><i></i>Unikalni</li><li class="l3"><i></i>Odsłony: <?php echo esc_html($poprzedni); ?></li>
            </ul>
            <p class="evk-stat-max">Najwięcej: <?php echo esc_html(number_format_i18n($evk_max)); ?></p>
            <?php echo evk_stat_wykres($seria); // phpcs:ignore — SVG składany z liczb i esc_* ?>
            <div class="evk-stat-dymek" role="status" hidden></div>
            <details class="evk-stat-dni">
                <summary>Tabela dzienna</summary>
                <div class="evo-tbl-wrap"><table class="evo-table">
                    <thead><tr><th scope="col">Dzień</th><th scope="col" class="num">Odsłony</th><th scope="col" class="num">Unikalni</th><th scope="col" class="num">Odsłony: <?php echo esc_html($poprzedni); ?></th></tr></thead>
                    <tbody><?php foreach ($seria as $r): ?><tr><td><?php echo esc_html($r['dzien']); ?></td><td class="num"><?php echo (int) $r['odslony']; ?></td><td class="num"><?php echo (int) $r['unikalni']; ?></td><td class="num"><?php echo (int) $r['poprz']; ?></td></tr><?php endforeach; ?></tbody>
                </table></div>
            </details>
            <p class="evo-hint">Unikalni to suma dziennych: bez cookies tej samej osoby z dwóch dni nie da się połączyć.</p>
        </div>
        <script>
        (function () {
            var box = document.querySelector('.evk-stat-wykres-box');
            if (!box) return;
            var seria = JSON.parse(box.getAttribute('data-seria') || '[]'), dymek = box.querySelector('.evk-stat-dymek');
            function tryb(t) {
                box.setAttribute('data-tryb', t);
                box.querySelectorAll('.evk-stat-tryby [data-tryb]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-tryb') === t)); });
                try { localStorage.setItem('evkStatWykres', t); } catch (e) { /* bez pamięci przeglądarki — zostaje na tę wizytę */ }
            }
            box.querySelectorAll('.evk-stat-tryby [data-tryb]').forEach(function (b) { b.addEventListener('click', function () { tryb(b.getAttribute('data-tryb')); }); });
            try { if (localStorage.getItem('evkStatWykres') === 'linie') tryb('linie'); } catch (e) { /* jw. */ }
            box.querySelectorAll('.evk-w-pas').forEach(function (pas) {
                pas.addEventListener('mouseenter', function () {
                    var r = seria[+pas.getAttribute('data-i')], linie = box.getAttribute('data-tryb') === 'linie';
                    box.querySelectorAll('.evk-w-pas.is-aktywny').forEach(function (x) { x.classList.remove('is-aktywny'); });
                    pas.classList.add('is-aktywny');
                    dymek.textContent = '';
                    [r.dzien, 'Odsłony: ' + r.odslony].concat(linie ? ['Unikalni: ' + r.unikalni, 'Poprzednio (' + r.poprz_dzien + '): ' + r.poprz] : [])
                        .forEach(function (t, i) { var d = document.createElement('div'); d.textContent = t; if (!i) d.style.fontWeight = '600'; dymek.appendChild(d); });
                    dymek.hidden = false;
                    var p = pas.getBoundingClientRect(), b = box.getBoundingClientRect();
                    dymek.style.top = (p.top - b.top + 8) + 'px';
                    dymek.style.left = Math.max(8, Math.min(b.width - dymek.offsetWidth - 8, p.left - b.left + p.width / 2 - dymek.offsetWidth / 2)) + 'px';
                });
            });
            box.querySelector('.evk-stat-wykres').addEventListener('mouseleave', function () {
                dymek.hidden = true;
                box.querySelectorAll('.evk-w-pas.is-aktywny').forEach(function (x) { x.classList.remove('is-aktywny'); });
            });
        })();
        </script>
        <div class="evk-stat-siatka">
            <?php
            echo evk_stat_tabela_html('Strony', 'Adres', evk_stat_dane('strona', $od, $do));
            echo evk_stat_tabela_html('Źródła', 'Skąd', evk_stat_dane('zrodlo', $od, $do));
            echo evk_stat_tabela_html('Urządzenia', 'Urządzenie', evk_stat_dane('urzadzenie', $od, $do), ['telefon' => 'Telefon', 'tablet' => 'Tablet', 'komputer' => 'Komputer']);
            echo evk_stat_tabela_html('Przeglądarki', 'Przeglądarka', evk_stat_dane('przegladarka', $od, $do));
            echo evk_stat_tabela_html('Systemy', 'System', evk_stat_dane('system', $od, $do));
            if (count($jezyki) > 1) echo evk_stat_tabela_html('Języki strony', 'Język', $jezyki, $nazwy_jezykow);
            /* Kraje (1.285.0, DB-IP Lite, CC BY 4.0 — podpis pod listą). */
            if (evk_stat_kraje_gotowe()) {
                $kr = evk_stat_dane('kraj', $od, $do);
                $nazwy_kr = [];
                foreach (array_keys($kr) as $kod) $nazwy_kr[$kod] = evk_stat_nazwa_kraju((string) $kod);
                echo str_replace('</div></div>', '</div><p class="evo-hint evk-stat-dbip"><a href="https://db-ip.com" rel="noopener" target="_blank">IP Geolocation by DB-IP</a> (CC BY 4.0)</p></div>',
                    evk_stat_tabela_html('Kraje', 'Kraj', $kr, $nazwy_kr));
            }
            /* Zdarzenia (1.285.0): „Telefon: +48…”, „Pobranie: cennik.pdf”. */
            $zd = evk_stat_dane('zdarzenie', $od, $do);
            $nazwy_zd = [];
            foreach (array_keys($zd) as $k) { [$r, $e] = array_pad(explode(':', (string) $k, 2), 2, ''); $nazwy_zd[$k] = (EVK_STAT_ZDARZENIA[$r] ?? $r) . ': ' . $e; }
            echo evk_stat_tabela_html('Zdarzenia', 'Zdarzenie', $zd, $nazwy_zd);
            foreach (['utm_source' => 'Kampanie: źródło (utm_source)', 'utm_medium' => 'Kampanie: medium (utm_medium)', 'utm_campaign' => 'Kampanie: nazwa (utm_campaign)'] as $w => $t) {
                $d = evk_stat_dane($w, $od, $do);
                if ($d) echo evk_stat_tabela_html($t, 'Wartość', $d);
            }
            ?>
        </div>
        <?php evk_stat_render_cele($od, $do); ?>
    </div>
    <?php
}

/** Sekcja celów raportu (1.285.0): konwersja razem, po źródle i po kampanii. */
function evk_stat_render_cele(string $od, string $do): void {
    $k = evk_stat_konwersje($od, $do);
    if (!$k['cele']) return;
    $ret = (int) evk_stat_ustawienia()['retencja'];
    $proc = static function (int $z, int $w): string { return $w ? number_format_i18n($z / $w * 100, 1) . '%' : '—'; };
    $tabela = static function (string $kol, array $wiersze) use ($proc): string {
        $h = '<div class="evo-tbl-wrap"><table class="evo-table"><thead><tr><th scope="col">' . esc_html($kol) . '</th><th scope="col" class="num">Wizyty</th>'
            . '<th scope="col" class="num">Z celem</th><th scope="col" class="num">Konwersja</th></tr></thead><tbody>';
        foreach (array_slice($wiersze, 0, 10, true) as $n => [$w, $z]) {
            $h .= '<tr><td>' . esc_html((string) $n) . '</td><td class="num">' . (int) $w . '</td><td class="num">' . (int) $z . '</td><td class="num">' . esc_html($proc($z, $w)) . '</td></tr>';
        }
        return $h . '</tbody></table></div>';
    };
    ?>
    <h2 class="evk-stat-cele-tytul">Cele</h2>
    <p class="evo-hint">Wizyta to jedna osoba w jednym dniu. Cele liczą się z danych szczegółowych — najdalej <?php echo (int) $ret; ?> dni wstecz.</p>
    <div class="evk-stat-siatka">
        <?php foreach ($k['cele'] as $c): ?>
        <div class="evo-box evk-stat-cel-raport">
            <h3><?php echo esc_html($c['cel']['nazwa']); ?></h3>
            <p><strong class="evk-stat-konwersja"><?php echo esc_html($proc($c['z_celem'], $k['wizyty'])); ?></strong>
                <span class="evo-muted"><?php echo esc_html(sprintf('%d z %d wizyt · %s', $c['z_celem'], $k['wizyty'], $c['cel']['typ'] === 'adres' ? 'adres ' . $c['cel']['wartosc'] : 'zdarzenie ' . $c['cel']['wartosc'])); ?></span></p>
            <?php echo $tabela('Źródło wejścia', $c['zrodla']); // phpcs:ignore — esc_* w środku ?>
            <?php if ($c['kampanie']) echo $tabela('Kampania', $c['kampanie']); // phpcs:ignore ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php
}
