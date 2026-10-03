<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Statystyki: „teraz na stronie”, widżet Kokpitu i licznik
 * w pasku admina (1.285.0, decyzje zgłaszającego z 02.10).
 *
 *   - teraz = różne wizyty z odsłoną w ostatnich 5 minutach;
 *   - widżet: 7 dni — odsłony i unikalni ze zmianą wobec poprzednich 7 dni,
 *     mały wykres dzienny, 5 najczęściej oglądanych stron, „teraz”;
 *   - licznik w pasku admina na stronie (przełącznik w zakładce, domyślnie
 *     wyłączony): odsłony TEJ strony — dziś / 30 dni.
 */

const EVK_STAT_TERAZ_MIN = 5;

/**
 * Kto jest teraz na stronie: liczba wizyt i ich strony.
 *
 * @return array{wizyty:int,strony:array<string,int>}
 */
function evk_stat_teraz(): array {
    global $wpdb;
    if ((int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) return ['wizyty' => 0, 'strony' => []];
    $od  = gmdate('Y-m-d H:i:s', time() - EVK_STAT_TERAZ_MIN * MINUTE_IN_SECONDS);
    $tab = evk_stat_tabela('odslony');
    $ile = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT wizyta) FROM $tab WHERE czas >= %s AND blad = 0", $od));
    $strony = [];
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT sciezka, COUNT(DISTINCT wizyta) AS ile FROM $tab WHERE czas >= %s AND blad = 0 GROUP BY sciezka ORDER BY ile DESC LIMIT 5", $od), ARRAY_A) as $w) {
        $strony[(string) $w['sciezka']] = (int) $w['ile'];
    }
    return ['wizyty' => $ile, 'strony' => $strony];
}

/**
 * Odsłony jednej strony (ścieżki) w zakresie dni — z tabeli zbiorczej i surowej,
 * bez czytania całego wymiaru.
 */
function evk_stat_odslony_strony(string $sciezka, string $od, string $do): int {
    global $wpdb;
    if ((int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) return 0;
    $zeb = evk_stat_zebrane_do();
    $ile = 0;
    if ($zeb !== '' && $od <= $zeb) {
        $ile += (int) $wpdb->get_var($wpdb->prepare('SELECT SUM(odslony) FROM ' . evk_stat_tabela('dni') . " WHERE wymiar = 'strona' AND wartosc = %s AND dzien BETWEEN %s AND %s",
            $sciezka, $od, min($do, $zeb)));
    }
    $od_sur = $zeb !== '' && $zeb >= $od ? gmdate('Y-m-d', strtotime($zeb . ' +1 day')) : $od;
    if ($od_sur <= $do) {
        $ile += (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . evk_stat_tabela('odslony') . ' WHERE sciezka = %s AND dzien BETWEEN %s AND %s AND blad = 0', $sciezka, $od_sur, $do));
    }
    return $ile;
}

/** Mały wykres do widżetu (1.286.0): słupki i linia w jednym SVG na całą szerokość. @param list<int> $wartosci */
function evk_stat_mini_wykres(array $wartosci, string $opis): string {
    $max = max(1, max($wartosci ?: [0]));
    $n = max(1, count($wartosci));
    $w = 280; $h = 56; $slot = $w / $n; $bw = $slot * 0.7;
    $out = '<svg class="evk-stat-mini" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img" aria-label="' . esc_attr($opis) . '"><g class="evk-sw-slupki">';
    $pkt = [];
    foreach (array_values($wartosci) as $i => $v) {
        $pkt[] = round($i * $slot + $slot / 2, 2) . ',' . round($h - 1 - $v / $max * ($h - 4), 2);
        if ($v <= 0) continue;
        $bh = max(2, $v / $max * ($h - 2));
        $out .= '<rect x="' . round($i * $slot + ($slot - $bw) / 2, 2) . '" y="' . round($h - $bh, 2) . '" width="' . round($bw, 2) . '" height="' . round($bh, 2) . '" />';
    }
    return $out . '</g><g class="evk-sw-linia"><polyline vector-effect="non-scaling-stroke" points="' . implode(' ', $pkt) . '" /></g></svg>';
}

/** Odsłony dziś godzina po godzinie (strefa strony), z surowych odsłon. @return list<int> */
function evk_stat_godziny(): array {
    global $wpdb;
    $out = array_fill(0, 24, 0);
    if ((int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) return $out;
    foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT czas FROM ' . evk_stat_tabela('odslony') . ' WHERE dzien = %s AND blad = 0', wp_date('Y-m-d')), ARRAY_A) as $w) {
        $out[(int) wp_date('G', (int) strtotime($w['czas'] . ' UTC'))]++;
    }
    return $out;
}

/**
 * Jeden okres widżetu: liczby, zmiany wobec poprzedniego okresu, punkty wykresu
 * i podpisy pod nim (godziny, dni tygodnia albo daty).
 *
 * @return array{odslony:int,unikalni:int,zm_o:string,zm_u:string,wobec:string,punkty:list<int>,podpisy:list<string>,opis:string}
 */
function evk_stat_widzet_okres(string $okres): array {
    [$od, $do] = evk_stat_zakres($okres);
    $pusto = ['odslony' => 0, 'unikalni' => 0, 'czas_suma' => 0, 'czas_ile' => 0, 'przewiniecie_suma' => 0];
    $seria = evk_stat_seria($od, $do);
    $teraz = evk_stat_dane('razem', $od, $do)[''] ?? $pusto;
    $przed = evk_stat_dane('razem', $seria[0]['poprz_dzien'], $seria[count($seria) - 1]['poprz_dzien'])[''] ?? $pusto;
    if ($okres === 'dzis') {
        $punkty = evk_stat_godziny();
        $podpisy = array_map(static function (int $g): string { return in_array($g, [0, 6, 12, 18], true) ? $g . ':00' : ''; }, range(0, 23));
        $opis = 'Odsłony dziś, godzina po godzinie: ' . implode(', ', $punkty);
    } else {
        $punkty = array_map('intval', array_column($seria, 'odslony'));
        $dni = ['nd', 'pn', 'wt', 'śr', 'czw', 'pt', 'sob'];
        $n = count($seria);
        $podpisy = [];
        foreach ($seria as $i => $r) {
            $ts = (int) strtotime($r['dzien'] . ' 12:00');
            $podpisy[] = $n <= 7 ? $dni[(int) gmdate('w', $ts)] : (in_array($i, [0, intdiv($n - 1, 2), $n - 1], true) ? gmdate('j.m', $ts) : '');
        }
        $opis = 'Odsłony dziennie od ' . $od . ' do ' . $do . ': ' . implode(', ', $punkty);
    }
    return ['odslony' => (int) $teraz['odslony'], 'unikalni' => (int) $teraz['unikalni'],
        'zm_o' => evk_stat_zmiana($teraz['odslony'], $przed['odslony']), 'zm_u' => evk_stat_zmiana($teraz['unikalni'], $przed['unikalni']),
        'wobec' => $okres === 'dzis' ? 'wobec wczoraj' : 'wobec poprzednich ' . EVK_STAT_OKRESY[$okres][0],
        'punkty' => $punkty, 'podpisy' => $podpisy, 'opis' => $opis];
}

// =========================================================================
// WIDŻET KOKPITU
// =========================================================================

add_action('wp_dashboard_setup', function (): void {
    if (!evk_stat_wlaczone() || !evk_stat_moze_czytac()) return;
    wp_add_dashboard_widget('evk_stat_widzet', 'Statystyki', 'evk_stat_render_widzet');
});

/**
 * Widżet (1.286.0, decyzje z 02.10): bez listy adresów. Teraz na stronie,
 * zakładki okresu (Dziś / 7 dni / 30 dni) z odsłonami, unikalnymi i wykresem,
 * pod wykresem przełącznik Słupki / Linie, na końcu przycisk „Pełny raport”.
 * Wybór zakładki i wykresu pamięta przeglądarka.
 */
function evk_stat_render_widzet(): void {
    $na_stronie = evk_stat_teraz();
    $okresy = ['dzis' => 'Dziś', '7' => '7 dni', '30' => '30 dni'];
    ?>
    <div class="evk-stat-widzet" data-tryb="slupki">
        <style>
            .evk-stat-widzet .evk-sw-teraz-wiersz { margin: 0 0 10px; }
            .evk-stat-widzet .evk-sw-kropka { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #1baf7a; margin-right: 6px; }
            .evk-stat-widzet .evk-sw-zakladki, .evk-stat-widzet .evk-sw-tryby { display: flex; gap: 4px; margin: 0 0 10px; }
            .evk-stat-widzet .evk-sw-zakladki .button[aria-selected="true"], .evk-stat-widzet .evk-sw-tryby .button[aria-pressed="true"] { background: #2271b1; border-color: #2271b1; color: #fff; }
            .evk-stat-widzet .evk-sw-liczby { display: flex; flex-wrap: wrap; gap: 8px 24px; margin-bottom: 8px; }
            .evk-stat-widzet .evk-sw-liczby div { min-width: 110px; }
            .evk-stat-widzet .evk-sw-liczby span, .evk-stat-widzet small { display: block; color: #50575e; font-size: 12px; }
            .evk-stat-widzet .evk-sw-liczby strong { font-size: 20px; line-height: 1.3; }
            .evk-stat-mini { width: 100%; height: 56px; display: block; margin: 4px 0 2px; }
            .evk-stat-mini rect { fill: #2a78d6; }
            .evk-stat-mini polyline { fill: none; stroke: #2a78d6; stroke-width: 2; stroke-linejoin: round; }
            .evk-stat-widzet[data-tryb="slupki"] .evk-sw-linia, .evk-stat-widzet[data-tryb="linie"] .evk-sw-slupki { display: none; }
            .evk-stat-widzet .evk-sw-os { display: grid; font-size: 11px; color: #50575e; margin-bottom: 10px; }
            .evk-stat-widzet .evk-sw-os span { text-align: center; white-space: nowrap; overflow: visible; }
            .evk-stat-widzet .evk-sw-dol { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 8px; }
            .evk-stat-widzet .evk-sw-dol .evk-sw-tryby { margin: 0; }
        </style>
        <p class="evk-sw-teraz-wiersz"><span class="evk-sw-kropka" aria-hidden="true"></span>Teraz na stronie: <strong class="evk-sw-teraz"><?php echo (int) $na_stronie['wizyty']; ?></strong>
            <span class="evo-muted">(ostatnie <?php echo (int) EVK_STAT_TERAZ_MIN; ?> min)</span></p>
        <div class="evk-sw-zakladki" role="tablist" aria-label="Okres">
            <?php foreach ($okresy as $k => $n): $k = (string) $k; ?>
            <button type="button" class="button" role="tab" id="evk-sw-tab-<?php echo esc_attr($k); ?>" aria-controls="evk-sw-okres-<?php echo esc_attr($k); ?>"
                aria-selected="<?php echo $k === '7' ? 'true' : 'false'; ?>" data-okres="<?php echo esc_attr($k); ?>"
                data-raport="<?php echo esc_url(add_query_arg('okres', $k, evk_stat_adres_raportu())); ?>"><?php echo esc_html($n); ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($okresy as $k => $n): $k = (string) $k; $o = evk_stat_widzet_okres($k); ?>
        <div class="evk-sw-okres" role="tabpanel" id="evk-sw-okres-<?php echo esc_attr($k); ?>" aria-labelledby="evk-sw-tab-<?php echo esc_attr($k); ?>"<?php echo $k === '7' ? '' : ' hidden'; ?>>
            <div class="evk-sw-liczby">
                <div><span>Odsłony</span><strong><?php echo esc_html(number_format_i18n($o['odslony'])); ?></strong>
                    <?php if ($o['zm_o'] !== ''): ?><small><?php echo esc_html($o['zm_o'] . ' ' . $o['wobec']); ?></small><?php endif; ?></div>
                <div><span>Unikalni</span><strong><?php echo esc_html(number_format_i18n($o['unikalni'])); ?></strong>
                    <?php if ($o['zm_u'] !== ''): ?><small><?php echo esc_html($o['zm_u'] . ' ' . $o['wobec']); ?></small><?php endif; ?></div>
            </div>
            <?php echo evk_stat_mini_wykres($o['punkty'], $o['opis']); // phpcs:ignore — SVG z liczb ?>
            <div class="evk-sw-os" aria-hidden="true" style="grid-template-columns:repeat(<?php echo count($o['podpisy']); ?>,1fr)">
                <?php foreach ($o['podpisy'] as $p): ?><span><?php echo esc_html($p); ?></span><?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <div class="evk-sw-dol">
            <div class="evk-sw-tryby" role="group" aria-label="Rodzaj wykresu">
                <button type="button" class="button button-small" data-tryb="slupki" aria-pressed="true">Słupki</button>
                <button type="button" class="button button-small" data-tryb="linie" aria-pressed="false">Linie</button>
            </div>
            <a class="button button-primary evk-sw-raport" href="<?php echo esc_url(add_query_arg('okres', '7', evk_stat_adres_raportu())); ?>">Pełny raport</a>
        </div>
        <script>
        (function () {
            var w = document.querySelector('.evk-stat-widzet');
            if (!w) return;
            function pamietaj(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* bez pamięci przeglądarki */ } }
            function okres(k) {
                w.querySelectorAll('[role="tab"]').forEach(function (b) { b.setAttribute('aria-selected', String(b.getAttribute('data-okres') === k)); if (b.getAttribute('data-okres') === k) w.querySelector('.evk-sw-raport').href = b.getAttribute('data-raport'); });
                w.querySelectorAll('[role="tabpanel"]').forEach(function (p) { p.hidden = p.id !== 'evk-sw-okres-' + k; });
                pamietaj('evkStatWidzet', k);
            }
            function tryb(t) {
                w.setAttribute('data-tryb', t);
                w.querySelectorAll('.evk-sw-tryby [data-tryb]').forEach(function (b) { b.setAttribute('aria-pressed', String(b.getAttribute('data-tryb') === t)); });
                pamietaj('evkStatWykres', t);
            }
            w.querySelectorAll('[role="tab"]').forEach(function (b) { b.addEventListener('click', function () { okres(b.getAttribute('data-okres')); }); });
            w.querySelectorAll('.evk-sw-tryby [data-tryb]').forEach(function (b) { b.addEventListener('click', function () { tryb(b.getAttribute('data-tryb')); }); });
            try {
                var k = localStorage.getItem('evkStatWidzet'), t = localStorage.getItem('evkStatWykres');
                if (k && w.querySelector('[data-okres="' + k + '"]')) okres(k);
                if (t === 'linie') tryb('linie');
            } catch (e) { /* jw. */ }
        })();
        </script>
    </div>
    <?php
}

// =========================================================================
// LICZNIK W PASKU ADMINA (na stronie)
// =========================================================================

add_action('admin_bar_menu', function (WP_Admin_Bar $pasek): void {
    /* Od 1.290.0 grupa „Statystyki” w menu „Evoke” (includes/interface/pasek-evoke.php); licznik — w tytule menu. */
    if (is_admin() || !evk_stat_wlaczone() || !evk_stat_moze_czytac() || !function_exists('evk_pasek_grupa')) return;
    evk_pasek_naglowek($pasek, 'stat', 'evk-statystyki-tytul', 'Statystyki');
    if (!empty(evk_stat_ustawienia()['licznik'])) {
        $sciezka = evk_stat_sciezka((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY));
        $dzis = wp_date('Y-m-d');
        $d = evk_stat_odslony_strony($sciezka, $dzis, $dzis);
        $m = evk_stat_odslony_strony($sciezka, wp_date('Y-m-d', time() - 29 * DAY_IN_SECONDS), $dzis);
        $opis = sprintf('Odsłony tej strony: dziś %d, 30 dni %d', $d, $m);
        evk_pasek_licznik(number_format_i18n($d) . ' / ' . number_format_i18n($m), $opis);
        $pasek->add_node(['parent' => evk_pasek_grupa('stat'), 'id' => 'evk-statystyki', 'title' => esc_html($opis), 'href' => evk_stat_adres_raportu()]);
    }
    $pasek->add_node(['parent' => evk_pasek_grupa('stat'), 'id' => 'evk-statystyki-raport', 'title' => 'Raport statystyk', 'href' => evk_stat_adres_raportu()]);
}, 90);
