<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Statystyki: zdarzenia i cele (1.285.0, decyzje z 02.10).
 *
 * Zdarzenia automatyczne: kliknięcia w telefon i e-mail, pobrania plików,
 * linki wychodzące, wysłane formularze (zdarzenie „submit” — także gdy
 * formularz potem odrzuci dane) — każde do wyłączenia w zakładce. Własne:
 * atrybut `data-evk-zdarzenie="nazwa"` na dowolnym elemencie albo formularzu.
 *
 * Cele: zdarzenie (rodzaj, opcjonalnie etykieta) albo wejście na adres.
 * Konwersja = wizyty z celem / wszystkie wizyty, osobno dla źródła wejścia
 * i kampanii (utm_campaign). Wizyta to skrót z soli dnia, więc „wizyta” to
 * jedna osoba w jednym dniu. Cele liczą się z danych szczegółowych — tak
 * daleko wstecz, jak je trzymamy (ustawienie „Szczegółowe wpisy trzymaj przez”).
 */

/** Przełączniki zdarzeń w ustawieniach (`zd_…`). `tel` obejmuje też e-mail. */
const EVK_STAT_ZD_PRZELACZNIKI = ['tel', 'pobrania', 'wychodzace', 'formularze'];
/** Rodzaje zdarzeń: klucz → nazwa w raporcie. */
const EVK_STAT_ZDARZENIA = ['tel' => 'Telefon', 'mail' => 'E-mail', 'pobranie' => 'Pobranie', 'wychodzacy' => 'Link wychodzący', 'formularz' => 'Formularz', 'wlasne' => 'Własne'];
/** Najwięcej zdarzeń jednej wizyty dziennie. */
const EVK_STAT_LIMIT_ZDARZEN = 500;

/** Zapis zdarzenia z beaconu (`t: z`). Zwraca powód odrzucenia albo „ok”. */
function evk_stat_zapisz_zdarzenie(array $d, string $wizyta): string {
    global $wpdb;
    $rodzaj = (string) ($d['r'] ?? '');
    if (!isset(EVK_STAT_ZDARZENIA[$rodzaj])) return 'rodzaj';
    /* Wyłączony w panelu rodzaj odpada także po stronie serwera (stary skrypt z pamięci podręcznej). */
    $prz = ['tel' => 'tel', 'mail' => 'tel', 'pobranie' => 'pobrania', 'wychodzacy' => 'wychodzace', 'formularz' => 'formularze'];
    if (isset($prz[$rodzaj]) && empty(evk_stat_ustawienia()['zd_' . $prz[$rodzaj]])) return 'wylaczone';
    $etykieta = trim(sanitize_text_field((string) ($d['e'] ?? '')));
    if ($etykieta === '') return 'etykieta';
    $tab = evk_stat_tabela('zdarzenia');
    $dzien = wp_date('Y-m-d');
    $ile = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tab WHERE dzien = %s AND wizyta = %s", $dzien, $wizyta));
    if ($ile >= EVK_STAT_LIMIT_ZDARZEN) return 'limit';
    $wpdb->insert($tab, ['czas' => gmdate('Y-m-d H:i:s'), 'dzien' => $dzien, 'klucz' => (string) $d['k'], 'wizyta' => $wizyta,
        'rodzaj' => $rodzaj, 'etykieta' => mb_substr($etykieta, 0, 191)]);
    return 'ok';
}

// =========================================================================
// CELE
// =========================================================================

/** @return list<array{id:string,nazwa:string,typ:string,wartosc:string}> */
function evk_stat_cele(): array {
    $c = get_option('evk_stat_cele', []);
    return is_array($c) ? array_values(array_filter($c, 'is_array')) : [];
}

/**
 * Walidacja celu z panelu: typ `zdarzenie` (wartość „rodzaj” albo
 * „rodzaj:etykieta”) albo `adres` (ścieżka jak w raporcie, np. „/dziekujemy/”).
 *
 * @param mixed $c
 * @return array{id:string,nazwa:string,typ:string,wartosc:string}|null
 */
function evk_stat_cel($c): ?array {
    if (!is_array($c)) return null;
    $nazwa = trim(sanitize_text_field((string) ($c['nazwa'] ?? '')));
    $typ = (string) ($c['typ'] ?? '');
    $w = trim(sanitize_text_field((string) ($c['wartosc'] ?? '')));
    if ($nazwa === '' || $w === '') return null;
    if ($typ === 'zdarzenie') {
        $rodzaj = strtok($w, ':');
        if (!isset(EVK_STAT_ZDARZENIA[(string) $rodzaj])) return null;
    } elseif ($typ === 'adres') {
        $czesci = explode('?', $w, 2);
        $w = evk_stat_sciezka($czesci[0], $czesci[1] ?? '');
    } else {
        return null;
    }
    $id = preg_replace('/[^a-z0-9]/', '', (string) ($c['id'] ?? '')) ?: substr(md5($typ . $w . microtime()), 0, 8);
    return ['id' => (string) $id, 'nazwa' => mb_substr($nazwa, 0, 80), 'typ' => $typ, 'wartosc' => mb_substr($w, 0, 191)];
}

/**
 * Konwersje celów w zakresie dni: dla każdego celu wizyty z celem, razem
 * i po źródle wejścia / kampanii. Źródło wizyty to pierwsza niepusta wartość
 * `zrodlo` jej odsłon tego dnia (wejście), kampania — pierwsza niepusta utm_campaign.
 *
 * @return array{wizyty:int,cele:list<array{cel:array<string,string>,z_celem:int,zrodla:array<string,array{0:int,1:int}>,kampanie:array<string,array{0:int,1:int}>}>}
 */
function evk_stat_konwersje(string $od, string $do): array {
    global $wpdb;
    $cele = evk_stat_cele();
    if (!$cele || (int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) return ['wizyty' => 0, 'cele' => []];
    $odsl = evk_stat_tabela('odslony');
    $zd = evk_stat_tabela('zdarzenia');
    /* Wizyty: dzień + skrót; wejście = pierwsza odsłona ze źródłem (przejścia wewnętrzne mają puste). */
    $wiz = [];
    foreach ((array) $wpdb->get_results($wpdb->prepare(
        "SELECT dzien, wizyta, SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(zrodlo, '') ORDER BY czas SEPARATOR '\n'), '\n', 1) AS zrodlo,
                SUBSTRING_INDEX(GROUP_CONCAT(NULLIF(utm_campaign, '') ORDER BY czas SEPARATOR '\n'), '\n', 1) AS kampania
         FROM $odsl WHERE dzien BETWEEN %s AND %s AND blad = 0 GROUP BY dzien, wizyta", $od, $do), ARRAY_A) as $w) {
        $wiz[$w['dzien'] . '|' . $w['wizyta']] = [(string) ($w['zrodlo'] ?? '') ?: '(bezpośrednio)', (string) ($w['kampania'] ?? '')];
    }
    $out = ['wizyty' => count($wiz), 'cele' => []];
    foreach ($cele as $cel) {
        if ($cel['typ'] === 'adres') {
            $traf = (array) $wpdb->get_col($wpdb->prepare("SELECT DISTINCT CONCAT(dzien, '|', wizyta) FROM $odsl WHERE dzien BETWEEN %s AND %s AND sciezka = %s", $od, $do, $cel['wartosc']));
        } else {
            [$rodzaj, $etykieta] = array_pad(explode(':', $cel['wartosc'], 2), 2, '');
            $traf = (array) ($etykieta === ''
                ? $wpdb->get_col($wpdb->prepare("SELECT DISTINCT CONCAT(dzien, '|', wizyta) FROM $zd WHERE dzien BETWEEN %s AND %s AND rodzaj = %s", $od, $do, $rodzaj))
                : $wpdb->get_col($wpdb->prepare("SELECT DISTINCT CONCAT(dzien, '|', wizyta) FROM $zd WHERE dzien BETWEEN %s AND %s AND rodzaj = %s AND etykieta = %s", $od, $do, $rodzaj, $etykieta)));
        }
        $traf = array_flip(array_map('strval', $traf));
        $zrodla = []; $kampanie = [];
        foreach ($wiz as $k => [$zr, $kamp]) {
            $jest = isset($traf[$k]) ? 1 : 0;
            $zrodla[$zr] = [($zrodla[$zr][0] ?? 0) + 1, ($zrodla[$zr][1] ?? 0) + $jest];
            if ($kamp !== '') $kampanie[$kamp] = [($kampanie[$kamp][0] ?? 0) + 1, ($kampanie[$kamp][1] ?? 0) + $jest];
        }
        $sort = static function (array $a, array $b): int { return $b[1] <=> $a[1] ?: $b[0] <=> $a[0]; };
        uasort($zrodla, $sort);
        uasort($kampanie, $sort);
        $out['cele'][] = ['cel' => $cel, 'z_celem' => count(array_intersect_key($traf, $wiz)), 'zrodla' => $zrodla, 'kampanie' => $kampanie];
    }
    return $out;
}

/* Cele zapisuje administrator z zakładki (cała lista naraz). */
add_action('wp_ajax_evk_stat_cele', function (): void {
    check_ajax_referer('evk_stat', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.');
    $lista = json_decode(wp_unslash((string) ($_POST['cele'] ?? '[]')), true);
    $cele = array_values(array_filter(array_map('evk_stat_cel', is_array($lista) ? array_slice($lista, 0, 50) : [])));
    update_option('evk_stat_cele', $cele, false);
    wp_send_json_success($cele);
});
