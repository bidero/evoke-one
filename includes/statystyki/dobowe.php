<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Statystyki: zbiórka dzienna i odczyt do raportów (1.283.0).
 *
 * Raz na dobę (WP-Cron, a gdy cron nie chodzi — przy otwarciu raportu)
 * surowe odsłony z zamkniętych dni zbierają się w `evk_stat_dni`, a surowe
 * starsze niż czas z panelu znikają. Zbiórka dnia jest powtarzalna: najpierw
 * kasuje wiersze tego dnia, potem liczy od nowa.
 *
 * Raport za zakres łączy dwa źródła: dni zebrane — z tabeli zbiorczej,
 * dni jeszcze niezebrane (dziś, czasem wczoraj) — tym samym zapytaniem wprost
 * z surowych. Liczby są więc takie same przed zbiórką i po niej.
 *
 * Unikalni w zakresie dłuższym niż dzień to SUMA dziennych: sól zmienia się
 * o północy, więc tej samej osoby z dwóch dni nie da się połączyć — z założenia.
 */

/** Wymiary raportu: nazwa → kolumna surowej tabeli. `razem` to cały dzień. */
const EVK_STAT_WYMIARY = [
    'razem' => '', 'strona' => 'sciezka', 'zrodlo' => 'zrodlo', 'urzadzenie' => 'urzadzenie',
    'przegladarka' => 'przegladarka', 'system' => 'system_op', 'jezyk' => 'jezyk',
    'utm_source' => 'utm_source', 'utm_medium' => 'utm_medium', 'utm_campaign' => 'utm_campaign',
];

/**
 * SELECT zliczający surowe odsłony w zakresie dni dla jednego wymiaru —
 * ten sam dla zbiórki i dla dni niezebranych. Kolumny jak w `evk_stat_dni`.
 * Odsłona bez beaconu końca ma przewinięcie 0, więc średnie czasu
 * i przewinięcia dzielą przez `czas_ile` (odsłony z beaconem końca).
 */
function evk_stat_sql_surowe(string $wymiar, string $od, string $do): string {
    global $wpdb;
    $kol = EVK_STAT_WYMIARY[$wymiar];
    $wart = $kol === '' ? "''" : $kol;
    $gdzie = $kol === '' ? '' : " AND $kol <> ''";
    return $wpdb->prepare(
        "SELECT dzien, %s AS wymiar, $wart AS wartosc, COUNT(*) AS odslony, COUNT(DISTINCT wizyta) AS unikalni,
                SUM(czas_s) AS czas_suma, SUM(przewiniecie > 0) AS czas_ile, SUM(przewiniecie) AS przewiniecie_suma
         FROM " . evk_stat_tabela('odslony') . " WHERE dzien BETWEEN %s AND %s$gdzie GROUP BY dzien" . ($kol === '' ? '' : ", $kol"),
        $wymiar, $od, $do
    );
}

/** Zbiera jeden dzień do tabeli zbiorczej (od nowa). */
function evk_stat_zbierz_dzien(string $dzien): void {
    global $wpdb;
    $dni = evk_stat_tabela('dni');
    $wpdb->delete($dni, ['dzien' => $dzien]);
    foreach (array_keys(EVK_STAT_WYMIARY) as $w) {
        $wpdb->query("INSERT INTO $dni (dzien, wymiar, wartosc, odslony, unikalni, czas_suma, czas_ile, przewiniecie_suma) " . evk_stat_sql_surowe($w, $dzien, $dzien));
    }
}

/** Ostatni zebrany dzień ('' — jeszcze żaden). */
function evk_stat_zebrane_do(): string {
    return (string) get_option('evk_stat_zebrane', '');
}

/**
 * Zbiera wszystkie zamknięte dni od ostatniej zbiórki do wczoraj i kasuje
 * surowe starsze niż czas trzymania. Zwraca liczbę zebranych dni.
 */
function evk_stat_zbiorka(): int {
    global $wpdb;
    if ((int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) return 0;
    $odsl  = evk_stat_tabela('odslony');
    $wczor = wp_date('Y-m-d', time() - DAY_IN_SECONDS);
    $ost   = evk_stat_zebrane_do();
    $od    = $ost !== '' ? gmdate('Y-m-d', strtotime($ost . ' +1 day')) : (string) $wpdb->get_var("SELECT MIN(dzien) FROM $odsl");
    $ile   = 0;
    if ($od !== '' && $od <= $wczor) {
        for ($d = $od; $d <= $wczor; $d = gmdate('Y-m-d', strtotime($d . ' +1 day'))) {
            evk_stat_zbierz_dzien($d);
            $ile++;
        }
        update_option('evk_stat_zebrane', $wczor, false);
    }
    $ret  = (int) evk_stat_ustawienia()['retencja'];
    $prog = wp_date('Y-m-d', time() - $ret * DAY_IN_SECONDS);
    $zeb  = evk_stat_zebrane_do();
    if ($zeb !== '') {
        /* Kasujemy tylko dni już zebrane — surowe bez zbiórki byłyby stratą danych. */
        $wpdb->query($wpdb->prepare("DELETE FROM $odsl WHERE dzien < %s AND dzien <= %s", $prog, $zeb));
    }
    return $ile;
}

add_action('evk_stat_dobowy', 'evk_stat_zbiorka');

add_action('init', function (): void {
    $jest = wp_next_scheduled('evk_stat_dobowy');
    if (evk_stat_wlaczone()) {
        /* 03:10 czasu strony — po północy soli i z dala od pełnych godzin. */
        if (!$jest) wp_schedule_event((new DateTimeImmutable('tomorrow 03:10', wp_timezone()))->getTimestamp(), 'daily', 'evk_stat_dobowy');
    } elseif ($jest) {
        wp_clear_scheduled_hook('evk_stat_dobowy');
    }
});

/**
 * Zsumowane liczby wymiaru w zakresie dni, z tabeli zbiorczej i surowej.
 *
 * @param bool $po_dniach Klucz wyniku: dzień (wykres) zamiast wartości wymiaru.
 * @return array<string,array{odslony:int,unikalni:int,czas_suma:int,czas_ile:int,przewiniecie_suma:int}>
 */
function evk_stat_dane(string $wymiar, string $od, string $do, bool $po_dniach = false): array {
    global $wpdb;
    if (!isset(EVK_STAT_WYMIARY[$wymiar]) || (int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) return [];
    $zeb = evk_stat_zebrane_do();
    $wiersze = [];
    if ($zeb !== '' && $od <= $zeb) {
        $wiersze = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT dzien, wymiar, wartosc, odslony, unikalni, czas_suma, czas_ile, przewiniecie_suma FROM " . evk_stat_tabela('dni') .
            " WHERE wymiar = %s AND dzien BETWEEN %s AND %s", $wymiar, $od, min($do, $zeb)
        ), ARRAY_A);
    }
    $od_sur = $zeb !== '' && $zeb >= $od ? gmdate('Y-m-d', strtotime($zeb . ' +1 day')) : $od;
    if ($od_sur <= $do) $wiersze = array_merge($wiersze, (array) $wpdb->get_results(evk_stat_sql_surowe($wymiar, $od_sur, $do), ARRAY_A));
    $out = [];
    foreach ($wiersze as $w) {
        $k = $po_dniach ? (string) $w['dzien'] : (string) $w['wartosc'];
        $out[$k] = $out[$k] ?? ['odslony' => 0, 'unikalni' => 0, 'czas_suma' => 0, 'czas_ile' => 0, 'przewiniecie_suma' => 0];
        foreach ($out[$k] as $p => $v) $out[$k][$p] = $v + (int) $w[$p];
    }
    if (!$po_dniach) uasort($out, static function ($a, $b) { return $b['odslony'] <=> $a['odslony']; });
    return $out;
}

// =========================================================================
// KASOWANIE ZA OKRES (1.285.0)
// =========================================================================

/** Zakres „wszystko” — od początku świata do końca. */
const EVK_STAT_WSZYSTKO = ['1970-01-01', '9999-12-31'];

/** Data „Y-m-d” albo '' (zła). */
function evk_stat_data(string $d): string {
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int) substr($d, 5, 2), (int) substr($d, 8, 2), (int) substr($d, 0, 4)) ? $d : '';
}

/** Ile odsłon zniknie (z tabeli zbiorczej i surowej, jak w raporcie). */
function evk_stat_do_usuniecia(string $od, string $do): int {
    return (int) (evk_stat_dane('razem', $od, $do)['']['odslony'] ?? 0);
}

/**
 * Kasuje statystyki dni od–do (włącznie): surowe odsłony i podsumowania
 * dzienne. Bez cofania. Zwraca liczby skasowanych wierszy.
 *
 * @return array{surowe:int,dzienne:int}
 */
function evk_stat_usun_okres(string $od, string $do): array {
    global $wpdb;
    if ((int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) return ['surowe' => 0, 'dzienne' => 0];
    $s = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . evk_stat_tabela('odslony') . ' WHERE dzien BETWEEN %s AND %s', $od, $do));
    $d = (int) $wpdb->query($wpdb->prepare('DELETE FROM ' . evk_stat_tabela('dni') . ' WHERE dzien BETWEEN %s AND %s', $od, $do));
    return ['surowe' => $s, 'dzienne' => $d];
}
