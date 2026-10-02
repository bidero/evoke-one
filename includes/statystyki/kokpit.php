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
    $ile = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT wizyta) FROM $tab WHERE czas >= %s", $od));
    $strony = [];
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT sciezka, COUNT(DISTINCT wizyta) AS ile FROM $tab WHERE czas >= %s GROUP BY sciezka ORDER BY ile DESC LIMIT 5", $od), ARRAY_A) as $w) {
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
        $ile += (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . evk_stat_tabela('odslony') . ' WHERE sciezka = %s AND dzien BETWEEN %s AND %s', $sciezka, $od_sur, $do));
    }
    return $ile;
}

/** Mały wykres słupkowy (SVG) do widżetu: odsłony z ostatnich dni. @param list<int> $wartosci */
function evk_stat_mini_wykres(array $wartosci, string $opis): string {
    $max = max(1, max($wartosci ?: [0]));
    $n = max(1, count($wartosci));
    $w = 280; $h = 48; $slot = $w / $n; $bw = min($slot * 0.6, 24);
    $out = '<svg class="evk-stat-mini" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . esc_attr($opis) . '">';
    foreach (array_values($wartosci) as $i => $v) {
        if ($v <= 0) continue;
        $bh = max(2, $v / $max * ($h - 2));
        $out .= '<rect x="' . round($i * $slot + ($slot - $bw) / 2, 2) . '" y="' . round($h - $bh, 2) . '" width="' . round($bw, 2) . '" height="' . round($bh, 2) . '" rx="2" />';
    }
    return $out . '</svg>';
}

// =========================================================================
// WIDŻET KOKPITU
// =========================================================================

add_action('wp_dashboard_setup', function (): void {
    if (!evk_stat_wlaczone() || !evk_stat_moze_czytac()) return;
    wp_add_dashboard_widget('evk_stat_widzet', 'Statystyki — 7 dni', 'evk_stat_render_widzet');
});

function evk_stat_render_widzet(): void {
    [$od, $do] = evk_stat_zakres('7');
    $seria = evk_stat_seria($od, $do);
    $pusto = ['odslony' => 0, 'unikalni' => 0, 'czas_suma' => 0, 'czas_ile' => 0, 'przewiniecie_suma' => 0];
    $teraz = evk_stat_dane('razem', $od, $do)[''] ?? $pusto;
    $przed = evk_stat_dane('razem', $seria[0]['poprz_dzien'], $seria[count($seria) - 1]['poprz_dzien'])[''] ?? $pusto;
    $na_stronie = evk_stat_teraz();
    $strony = array_slice(evk_stat_dane('strona', $od, $do), 0, 5, true);
    ?>
    <div class="evk-stat-widzet">
        <style>
            .evk-stat-widzet .evk-sw-liczby { display: flex; flex-wrap: wrap; gap: 8px 24px; margin-bottom: 8px; }
            .evk-stat-widzet .evk-sw-liczby div { min-width: 90px; }
            .evk-stat-widzet .evk-sw-liczby span, .evk-stat-widzet small { display: block; color: #50575e; font-size: 12px; }
            .evk-stat-widzet .evk-sw-liczby strong { font-size: 20px; line-height: 1.3; }
            .evk-stat-mini { width: 100%; height: 48px; display: block; margin: 4px 0 8px; }
            .evk-stat-mini rect { fill: #2a78d6; }
            .evk-stat-widzet ol { margin: 0 0 8px 18px; }
            .evk-stat-widzet li { display: flex; justify-content: space-between; gap: 8px; margin: 0 0 2px; }
            .evk-stat-widzet li span { word-break: break-word; }
        </style>
        <div class="evk-sw-liczby">
            <div><span>Odsłony</span><strong><?php echo esc_html(number_format_i18n($teraz['odslony'])); ?></strong>
                <?php $z = evk_stat_zmiana($teraz['odslony'], $przed['odslony']); if ($z !== ''): ?><small><?php echo esc_html($z . ' wobec poprzednich 7 dni'); ?></small><?php endif; ?></div>
            <div><span>Unikalni</span><strong><?php echo esc_html(number_format_i18n($teraz['unikalni'])); ?></strong>
                <?php $z = evk_stat_zmiana($teraz['unikalni'], $przed['unikalni']); if ($z !== ''): ?><small><?php echo esc_html($z . ' wobec poprzednich 7 dni'); ?></small><?php endif; ?></div>
            <div><span>Teraz na stronie</span><strong class="evk-sw-teraz"><?php echo (int) $na_stronie['wizyty']; ?></strong><small>ostatnie <?php echo (int) EVK_STAT_TERAZ_MIN; ?> min</small></div>
        </div>
        <?php echo evk_stat_mini_wykres(array_column($seria, 'odslony'), 'Odsłony w ostatnich 7 dniach: ' . implode(', ', array_column($seria, 'odslony'))); // phpcs:ignore — SVG z liczb ?>
        <?php if ($strony): ?>
        <ol class="evk-sw-strony">
            <?php foreach ($strony as $s => $l): ?><li><span><?php echo esc_html((string) $s); ?></span><strong><?php echo esc_html(number_format_i18n($l['odslony'])); ?></strong></li><?php endforeach; ?>
        </ol>
        <?php else: ?>
        <p class="evo-muted">Brak odsłon w ostatnich 7 dniach.</p>
        <?php endif; ?>
        <p><a href="<?php echo esc_url(evk_stat_adres_raportu()); ?>">Pełny raport →</a></p>
    </div>
    <?php
}

// =========================================================================
// LICZNIK W PASKU ADMINA (na stronie)
// =========================================================================

add_action('admin_bar_menu', function (WP_Admin_Bar $pasek): void {
    if (is_admin() || !evk_stat_wlaczone() || empty(evk_stat_ustawienia()['licznik']) || !evk_stat_moze_czytac()) return;
    $sciezka = evk_stat_sciezka((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), (string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY));
    $dzis = wp_date('Y-m-d');
    $d = evk_stat_odslony_strony($sciezka, $dzis, $dzis);
    $m = evk_stat_odslony_strony($sciezka, wp_date('Y-m-d', time() - 29 * DAY_IN_SECONDS), $dzis);
    $pasek->add_node([
        'id'    => 'evk-statystyki',
        'title' => '<span class="ab-icon dashicons dashicons-chart-area" style="top:2px" aria-hidden="true"></span><span class="ab-label">'
            . esc_html(number_format_i18n($d) . ' / ' . number_format_i18n($m)) . '</span>',
        'href'  => evk_stat_adres_raportu(),
        'meta'  => ['title' => sprintf('Odsłony tej strony: dziś %d, 30 dni %d', $d, $m)],
    ]);
}, 90);
