<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Statystyki: hotspoty (1.286.0, etap 3).
 *
 * Nagrywanie WYBRANYCH stron na czas (domyślnie 14 dni albo 1000 wizyt, co
 * pierwsze): kliknięcia względem elementu, głębokość przewinięcia, kliknięcia
 * ze złości (3 w 1 s w promieniu 30 px) i martwe (nie w link ani przycisk,
 * a przez 1 s nic się na stronie nie zmieniło). Bez ruchu myszy, bez treści
 * pól, bez nagrań sesji. Dane zostają do ręcznego usunięcia.
 *
 * O nagrywaniu decyduje serwer, a nie HTML strony: skrypt statystyk pyta
 * o plik `hotspoty.json` w katalogu przesłanych plików (lista nagrywanych
 * adresów z końcem nagrania), więc strona z pamięci podręcznej zapisana przed
 * włączeniem nagrywa od razu. Serwer i tak sprawdza każdą wiadomość.
 *
 * Włącza, zatrzymuje i kasuje administrator (pasek admina na stronie i lista
 * w zakładce); ogląda także rola z uprawnieniem „Statystyki” — podgląd to
 * raport z parametrem `hotspoty` (strona w ramce o szerokości urządzenia,
 * nakładka rysowana w ramce).
 */

const EVK_STAT_HOT_OPCJA = 'evk_stat_hotspoty';
const EVK_STAT_HOT_DNI = 14;
const EVK_STAT_HOT_WIZYTY = 1000;
/** Najwięcej kliknięć jednej odsłony. */
const EVK_STAT_HOT_LIMIT_KLIKOW = 100;
/**
 * Ramka podglądu: szerokość okna dla urządzenia. Komputer to stałe 1440 px
 * (1.292.0): przy pełnej szerokości ramki laptop z panelem obok dawał stronie
 * ok. 760 px, czyli jej układ MOBILNY. Węższe miejsce pomniejsza ramkę.
 */
const EVK_STAT_HOT_SZEROKOSCI = ['telefon' => 390, 'tablet' => 820, 'komputer' => 1440];

/** @return array<string,array{od:int,do:int,wizyty:int,koniec:int}> nagrania po stronie */
function evk_stat_hot_nagrania(): array {
    $n = get_option(EVK_STAT_HOT_OPCJA, []);
    return is_array($n) ? $n : [];
}

/** Ścieżka strony z adresu albo ścieżki wpisanej w panelu („/oferta/”, „https://…/?page_id=5”). */
function evk_stat_hot_strona(string $adres): string {
    $adres = trim($adres);
    if ($adres === '') return '';
    $u = wp_parse_url($adres);
    if (!is_array($u)) return '';
    return evk_stat_sciezka((string) ($u['path'] ?? '/'), (string) ($u['query'] ?? ''));
}

/** Liczba wizyt nagranych od początku bieżącego nagrania. */
function evk_stat_hot_wizyty(string $strona, int $od): int {
    global $wpdb;
    if ((int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) return 0;
    return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT wizyta) FROM ' . evk_stat_tabela('hot_odslony') . ' WHERE strona = %s AND czas >= %s',
        $strona, gmdate('Y-m-d H:i:s', $od)));
}

/**
 * Stan nagrania strony.
 *
 * @return array{nagrywa:bool,od:int,do:int,limit:int,wizyty:int,koniec:int,odslony:int}|null
 */
function evk_stat_hot_stan(string $strona): ?array {
    global $wpdb;
    $n = evk_stat_hot_nagrania()[$strona] ?? null;
    if (!$n) return null;
    $wizyty = evk_stat_hot_wizyty($strona, (int) $n['od']);
    $koniec = (int) $n['koniec'];
    if (!$koniec && (time() >= (int) $n['do'] || $wizyty >= (int) $n['wizyty'])) $koniec = min(time(), (int) $n['do']);
    $odslony = (int) get_option('evk_stat_db_version', 0) === EVK_STAT_DB_WERSJA
        ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . evk_stat_tabela('hot_odslony') . ' WHERE strona = %s', $strona)) : 0;
    return ['nagrywa' => !$koniec, 'od' => (int) $n['od'], 'do' => (int) $n['do'], 'limit' => (int) $n['wizyty'], 'wizyty' => $wizyty, 'koniec' => $koniec, 'odslony' => $odslony];
}

/** Plik z listą nagrywanych stron: {"strony":{"/oferta/":koniec_ts}}. Czyta go skrypt statystyk. */
function evk_stat_hot_plik(): string {
    return evk_stat_dbip_katalog() . '/hotspoty.json';
}

function evk_stat_hot_zapisz_plik(): void {
    $lista = [];
    foreach (evk_stat_hot_nagrania() as $s => $n) {
        if (!(int) $n['koniec'] && (int) $n['do'] > time()) $lista[$s] = (int) $n['do'];
    }
    wp_mkdir_p(evk_stat_dbip_katalog());
    file_put_contents(evk_stat_hot_plik(), (string) wp_json_encode(['strony' => (object) $lista]));
}

/** Każda zmiana nagrań przepisuje plik. */
add_action('update_option_' . EVK_STAT_HOT_OPCJA, 'evk_stat_hot_zapisz_plik');
add_action('add_option_' . EVK_STAT_HOT_OPCJA, 'evk_stat_hot_zapisz_plik');
add_action('delete_option_' . EVK_STAT_HOT_OPCJA, static function (): void { @unlink(evk_stat_hot_plik()); });

/** Start nagrania; `od_nowa` kasuje wcześniejsze dane tej strony. */
function evk_stat_hot_start(string $strona, int $dni, int $wizyty, bool $od_nowa): void {
    if ($od_nowa) evk_stat_hot_usun_dane($strona);
    $n = evk_stat_hot_nagrania();
    $n[$strona] = ['od' => time(), 'do' => time() + max(1, min(365, $dni)) * DAY_IN_SECONDS, 'wizyty' => max(1, min(1000000, $wizyty)), 'koniec' => 0];
    if ((int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) evk_stat_utworz_tabele();
    update_option(EVK_STAT_HOT_OPCJA, $n, false);
}

function evk_stat_hot_stop(string $strona): void {
    $n = evk_stat_hot_nagrania();
    if (!isset($n[$strona])) return;
    $n[$strona]['koniec'] = $n[$strona]['koniec'] ?: time();
    update_option(EVK_STAT_HOT_OPCJA, $n, false);
}

function evk_stat_hot_usun_dane(string $strona): void {
    global $wpdb;
    if ((int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) return;
    $wpdb->delete(evk_stat_tabela('hot_kliki'), ['strona' => $strona]);
    $wpdb->delete(evk_stat_tabela('hot_odslony'), ['strona' => $strona]);
}

/** Usunięcie strony z listy razem z danymi. */
function evk_stat_hot_usun(string $strona): void {
    evk_stat_hot_usun_dane($strona);
    $n = evk_stat_hot_nagrania();
    unset($n[$strona]);
    update_option(EVK_STAT_HOT_OPCJA, $n, false);
}

/* Koniec czasu nagrania utrwala zbiórka dobowa — plik przestaje wymieniać stronę. */
add_action('evk_stat_dobowy', static function (): void {
    $n = evk_stat_hot_nagrania();
    $zmiana = false;
    foreach ($n as $s => $x) {
        if (!(int) $x['koniec'] && (int) $x['do'] <= time()) { $n[$s]['koniec'] = (int) $x['do']; $zmiana = true; }
    }
    if ($zmiana) update_option(EVK_STAT_HOT_OPCJA, $n, false);
});

/**
 * Wiadomość hotspotów (`t: h`) z beaconu — po sprawdzeniach wspólnych ze
 * statystykami (włączone, DNT, bot, IP, klucz). Odsłona raz (klucz), potem
 * przewinięcie rośnie, a kliknięcia dochodzą do limitu odsłony.
 *
 * @param array<string,mixed> $d
 */
function evk_stat_hot_zapisz(array $d, string $wizyta): string {
    global $wpdb;
    $strona = evk_stat_sciezka((string) ($d['s'] ?? '/'), (string) ($d['q'] ?? ''));
    $n = evk_stat_hot_nagrania()[$strona] ?? null;
    if (!$n) return 'nie-nagrywane';
    if ((int) $n['koniec'] || time() >= (int) $n['do']) return 'koniec';
    $tab = evk_stat_tabela('hot_odslony');
    $klucz = (string) $d['k'];
    $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $tab WHERE klucz = %s", $klucz));
    $przew = max(0, min(100, (int) ($d['d'] ?? 0)));
    $wys = max(0, min(200000, (int) ($d['h'] ?? 0)));
    $szer = max(0, min(10000, (int) ($d['w'] ?? 0)));
    if (!$id) {
        /* Nowa odsłona: limit wizyt liczy się tutaj — wizyta ponad limit kończy nagranie. */
        $wizyty = evk_stat_hot_wizyty($strona, (int) $n['od']);
        $juz = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tab WHERE strona = %s AND wizyta = %s AND czas >= %s", $strona, $wizyta, gmdate('Y-m-d H:i:s', (int) $n['od'])));
        if (!$juz && $wizyty >= (int) $n['wizyty']) { evk_stat_hot_stop($strona); return 'limit'; }
        /* INSERT IGNORE: przy wyjściu ze strony „visibilitychange” i „pagehide” wysyłają dwa beacony naraz —
           drugi trafia na już wstawioną odsłonę i dopisuje się do niej, zamiast zgubić swoje kliknięcia. */
        $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $tab (klucz, strona, czas, wizyta, urzadzenie, szer, wys, przewiniecie) VALUES (%s, %s, %s, %s, %s, %d, %d, %d)",
            $klucz, $strona, gmdate('Y-m-d H:i:s'), $wizyta, evk_stat_urzadzenie($szer), $szer, $wys, $przew));
        $id = (int) $wpdb->insert_id ?: (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM $tab WHERE klucz = %s", $klucz));
        if (!$id) return 'blad';
        if (!$juz && $wizyty + 1 >= (int) $n['wizyty']) evk_stat_hot_stop($strona);
    } else {
        $wpdb->query($wpdb->prepare("UPDATE $tab SET przewiniecie = GREATEST(przewiniecie, %d), wys = GREATEST(wys, %d) WHERE id = %d", $przew, $wys, $id));
    }
    $kliki = is_array($d['c'] ?? null) ? $d['c'] : [];
    if (!$kliki) return 'ok';
    $tk = evk_stat_tabela('hot_kliki');
    $miejsce = EVK_STAT_HOT_LIMIT_KLIKOW - (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tk WHERE odslona = %d", $id));
    $urz = evk_stat_urzadzenie($szer);
    foreach (array_slice($kliki, 0, max(0, $miejsce)) as $k) {
        if (!is_array($k)) continue;
        $sel = (string) ($k['s'] ?? '');
        if ($sel === '' || strlen($sel) > 255 || !preg_match('/^[A-Za-z0-9_#:>().\- ]+$/', $sel)) continue;
        $wpdb->insert($tk, ['odslona' => $id, 'strona' => $strona, 'urzadzenie' => $urz, 'czas' => gmdate('Y-m-d H:i:s'), 'selektor' => $sel,
            'etykieta' => mb_substr(sanitize_text_field((string) ($k['l'] ?? '')), 0, 100),
            'rx' => max(0, min(1000, (int) ($k['x'] ?? 0))), 'ry' => max(0, min(1000, (int) ($k['y'] ?? 0))),
            'px' => max(0, min(100000, (int) ($k['px'] ?? 0))), 'py' => max(0, min(1000000, (int) ($k['py'] ?? 0))),
            'szer' => $szer, 'zlosc' => !empty($k['z']) ? 1 : 0, 'martwe' => !empty($k['m']) ? 1 : 0]);
    }
    return 'ok';
}

// =========================================================================
// STEROWANIE: admin-post (pasek admina, lista w zakładce)
// =========================================================================

/**
 * Kto zarządza nagraniami (1.291.0): administrator i rola z dostępem „Hotspoty”
 * (edycja roli). Oglądanie idzie przez raport — dostęp „Statystyki”.
 */
function evk_stat_hot_moze(): bool {
    return current_user_can('manage_options') || current_user_can('evk_access_hotspoty');
}

/** Adres akcji nagrania (GET z paska, z nonce). */
function evk_stat_hot_adres_akcji(string $akcja, string $strona): string {
    return wp_nonce_url(admin_url('admin-post.php?action=evk_stat_hot&akcja=' . $akcja . '&strona=' . rawurlencode($strona)), 'evk_stat_hot');
}

/** Adres podglądu hotspotów strony (raport z parametrem). */
function evk_stat_hot_adres_podgladu(string $strona, string $urz = ''): string {
    $a = add_query_arg('hotspoty', rawurlencode($strona), evk_stat_adres_raportu());
    return $urz !== '' ? add_query_arg('urz', $urz, $a) : $a;
}

add_action('admin_post_evk_stat_hot', function (): void {
    check_admin_referer('evk_stat_hot');
    if (!evk_stat_hot_moze()) wp_die('Brak uprawnień.', '', ['response' => 403]);
    $akcja = sanitize_key($_REQUEST['akcja'] ?? '');
    $strona = evk_stat_hot_strona((string) wp_unslash($_REQUEST['strona'] ?? ''));
    if ($strona === '') wp_die('Podaj adres strony.', '', ['response' => 400, 'back_link' => true]);
    if ($akcja === 'start') {
        evk_stat_hot_start($strona, (int) ($_REQUEST['dni'] ?? EVK_STAT_HOT_DNI), (int) ($_REQUEST['wizyty'] ?? EVK_STAT_HOT_WIZYTY), !empty($_REQUEST['od_nowa']));
    } elseif ($akcja === 'stop') {
        evk_stat_hot_stop($strona);
    } elseif ($akcja === 'usun') {
        evk_stat_hot_usun($strona);
    } else {
        wp_die('Nieznana akcja.', '', ['response' => 400]);
    }
    wp_safe_redirect(wp_get_referer() ?: admin_url('options-general.php?page=evoke-one&tab=statystyki'));
    exit;
});

// =========================================================================
// PASEK ADMINA NA STRONIE
// =========================================================================

add_action('admin_bar_menu', function (WP_Admin_Bar $pasek): void {
    if (is_admin() || !evk_stat_wlaczone() || (!evk_stat_moze_czytac() && !evk_stat_hot_moze()) || isset($_GET['evk_hot_podglad']) || !function_exists('evk_pasek_grupa')) return;
    $strona = evk_stat_sciezka((string) wp_parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), (string) ($_SERVER['QUERY_STRING'] ?? ''));
    $st = evk_stat_hot_stan($strona);
    $admin = evk_stat_hot_moze();
    if (!$st && !$admin) return;
    /* Od 1.290.0 grupa „Hotspoty” w menu „Evoke”: nagłówek ze stanem, pod nim pozycje (jeden poziom — wygodny także na telefonie). */
    $g = evk_pasek_grupa('hot');
    evk_pasek_naglowek($pasek, 'hot', 'evk-hotspoty', $st && $st['nagrywa'] ? 'Hotspoty: nagrywanie' : 'Hotspoty');
    if ($st) {
        /* Podgląd jest w raporcie — tylko z dostępem „Statystyki”. */
        if (evk_stat_moze_czytac()) $pasek->add_node(['parent' => $g, 'id' => 'evk-hotspoty-pokaz', 'title' => 'Pokaż hotspoty (' . (int) $st['odslony'] . ' odsłon)', 'href' => evk_stat_hot_adres_podgladu($strona)]);
        $pasek->add_node(['parent' => $g, 'id' => 'evk-hotspoty-stan', 'title' => $st['nagrywa']
            ? sprintf('Do %s albo %d wizyt (jest %d)', wp_date('j.m.Y', $st['do']), $st['limit'], $st['wizyty'])
            : 'Nagrywanie zakończone ' . wp_date('j.m.Y', $st['koniec'])]);
    }
    if (!$admin) return;
    if ($st && $st['nagrywa']) {
        $pasek->add_node(['parent' => $g, 'id' => 'evk-hotspoty-stop', 'title' => 'Zatrzymaj nagrywanie', 'href' => evk_stat_hot_adres_akcji('stop', $strona)]);
    } else {
        /* Zakończone nagranie: usunięcie z paska (1.291.0) — dotąd tylko w panelu, czyli dla administratora. */
        if ($st) $pasek->add_node(['parent' => $g, 'id' => 'evk-hotspoty-usun', 'title' => 'Usuń nagranie tej strony', 'href' => evk_stat_hot_adres_akcji('usun', $strona),
            'meta' => ['onclick' => "return confirm('Usunąć nagranie hotspotów tej strony? Kliknięć nie da się przywrócić.');"]]);
        $pasek->add_node(['parent' => $g, 'id' => 'evk-hotspoty-start', 'title' => sprintf('Nagrywaj tę stronę (%d dni albo %d wizyt)', EVK_STAT_HOT_DNI, EVK_STAT_HOT_WIZYTY),
            'href' => add_query_arg(['dni' => EVK_STAT_HOT_DNI, 'wizyty' => EVK_STAT_HOT_WIZYTY], evk_stat_hot_adres_akcji('start', $strona))]);
    }
}, 101);

/* Podgląd w ramce: bez paska admina (układ jak u odwiedzającego) i bez liczenia. */
add_action('init', function (): void {
    if (isset($_GET['evk_hot_podglad']) && !is_admin()) add_filter('show_admin_bar', '__return_false');
});

// =========================================================================
// DANE PODGLĄDU
// =========================================================================

/**
 * Dane nakładki dla strony i urządzenia: kliknięcia (do 20 000 najnowszych),
 * przewinięcia odsłon i liczby na urządzenie.
 *
 * @return array{kliki:list<array<int,int|string>>,przewiniecia:list<int>,urzadzenia:array<string,int>}
 */
function evk_stat_hot_dane(string $strona, string $urz): array {
    global $wpdb;
    $out = ['kliki' => [], 'przewiniecia' => [], 'urzadzenia' => ['telefon' => 0, 'tablet' => 0, 'komputer' => 0]];
    if ((int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) return $out;
    foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT urzadzenie, COUNT(*) AS n FROM ' . evk_stat_tabela('hot_odslony') . ' WHERE strona = %s GROUP BY urzadzenie', $strona), ARRAY_A) as $w) {
        if (isset($out['urzadzenia'][$w['urzadzenie']])) $out['urzadzenia'][$w['urzadzenie']] = (int) $w['n'];
    }
    $out['przewiniecia'] = array_map('intval', (array) $wpdb->get_col($wpdb->prepare('SELECT przewiniecie FROM ' . evk_stat_tabela('hot_odslony') . ' WHERE strona = %s AND urzadzenie = %s', $strona, $urz)));
    foreach ((array) $wpdb->get_results($wpdb->prepare('SELECT selektor, etykieta, rx, ry, px, py, szer, zlosc, martwe FROM ' . evk_stat_tabela('hot_kliki')
        . ' WHERE strona = %s AND urzadzenie = %s ORDER BY id DESC LIMIT 20000', $strona, $urz), ARRAY_A) as $w) {
        $out['kliki'][] = [(string) $w['selektor'], (string) $w['etykieta'], (int) $w['rx'], (int) $w['ry'], (int) $w['px'], (int) $w['py'], (int) $w['szer'], (int) $w['zlosc'], (int) $w['martwe']];
    }
    return $out;
}

/** Adres pliku listy dla skryptu statystyk. */
function evk_stat_hot_adres_pliku(): string {
    $u = wp_upload_dir(null, false);
    return set_url_scheme(trailingslashit((string) $u['baseurl']) . 'evk-statystyki/hotspoty.json');
}
