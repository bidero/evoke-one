<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Logi 404
 *
 * Od 1.234.0 jeden wiersz na ADRES we własnej tabeli `evk_404`: ile razy,
 * kiedy pierwszy i ostatni raz, oraz skąd, z jakiego IP i jaką przeglądarką
 * było ostatnie wejście. Do 1.233.5 każde trafienie było nowym wpisem typu
 * `evk_404_log` — zmierzone na testowym WordPressie: 26–32 zapytania na
 * trafienie i `save_post` za każdym razem, a na ten hak reagują inne wtyczki
 * (czyszczenie pamięci stron, indeksy wyszukiwarek). Skaner podatności,
 * który sprawdza setki adresów na minutę, robił z tego lawinę zapisów.
 *
 * Teraz trafienie to zapytanie o limit i jeden INSERT … ON DUPLICATE KEY
 * UPDATE; nowy adres dokłada sprzątanie nadmiaru ponad „Maks. adresów".
 * Z jednego IP najwyżej EVK_404_LIMIT_NA_MINUTE adresów na minutę — ponad to
 * trafienia nie są zapisywane.
 *
 * Czasy to znaczniki UNIX (int), jak w tabeli kopii: porównujemy je z time()
 * PHP-a, a NOW() serwera bazy potrafi stać w innej strefie.
 *
 * Adresy z aktywnym przekierowaniem 301 są pomijane; przycisk „Przekieruj"
 * w panelu tworzy takie przekierowanie wprost z wiersza.
 */

const EVK_404_DB_VERSION      = '2';
const EVK_404_LIMIT_NA_MINUTE = 20;

// =========================================================================
// OPCJE
// =========================================================================

function evk_404_is_enabled(): bool {
    return !empty(get_option('evk_404_enabled'));
}

function evk_404_max_logs(): int {
    return max(10, (int) get_option('evk_404_max_logs', 200));
}

function evk_404_skip_bots(): bool {
    return !empty(get_option('evk_404_skip_bots', 1));
}

function evk_404_bot_list(): array {
    $raw = get_option('evk_404_bot_list', '');
    if (empty($raw)) {
        $raw = "gptbot\ngooglebot\nyandexbot\nbytespider\nspider\npetalbot\nsemrushbot\nahrefsbot\nbingbot\nimagesiftbot\nbarkrowler\ntwitterbot\nfacebook\ndataforseobot\nmeta-externalagent";
    }
    return array_filter(array_map('trim', explode("\n", $raw)));
}

// =========================================================================
// TABELA
// =========================================================================

function evk_404_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'evk_404';
}

function evk_404_tabela_gotowa(): bool {
    return get_option('evk_404_db_version', '') === EVK_404_DB_VERSION;
}

function evk_404_create_table(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    $t       = evk_404_table();
    $charset = $wpdb->get_charset_collate();

    /* dbDelta jest wybredny: dwie spacje po PRIMARY KEY, każde pole w osobnej
       linii, KEY zamiast INDEX (jak w tabeli kopii). Adres jest za długi na
       unikalny indeks, więc kluczem jest skrót adresu po normalizacji. */
    dbDelta("CREATE TABLE $t (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        url_hash char(32) NOT NULL DEFAULT '',
        url varchar(2000) NOT NULL DEFAULT '',
        hits int(10) UNSIGNED NOT NULL DEFAULT 0,
        first_seen int(10) UNSIGNED NOT NULL DEFAULT 0,
        last_seen int(10) UNSIGNED NOT NULL DEFAULT 0,
        referrer varchar(2000) NOT NULL DEFAULT '',
        ip varchar(45) NOT NULL DEFAULT '',
        ua varchar(500) NOT NULL DEFAULT '',
        PRIMARY KEY  (id),
        UNIQUE KEY url_hash (url_hash),
        KEY last_seen (last_seen),
        KEY ip_last_seen (ip,last_seen)
    ) $charset;");
}

/**
 * Tabela i przeniesienie starych wpisów — raz, przy pierwszym trafieniu 404
 * albo otwarciu ekranu po aktualizacji. Nie przy każdym żądaniu: zwykła
 * strona nie ma tu nic do roboty.
 */
function evk_404_maybe_upgrade(): void {
    if (evk_404_tabela_gotowa()) return;
    evk_404_create_table();
    evk_404_przenies_stare();
    update_option('evk_404_db_version', EVK_404_DB_VERSION, true);
}

/**
 * Wpisy `evk_404_log` sprzed 1.234.0 → wiersze tabeli, zgrupowane po adresie
 * (liczba wejść, pierwsze i ostatnie, dane ostatniego). Stare wpisy znikają.
 * Zwraca, ile wpisów przeniesiono.
 */
function evk_404_przenies_stare(): int {
    global $wpdb;
    $ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'evk_404_log'"));
    if (!$ids) return 0;

    $adresy = [];
    foreach (array_chunk($ids, 500) as $paczka) {
        $in    = implode(',', $paczka);
        $daty  = [];
        foreach ($wpdb->get_results("SELECT ID, post_date_gmt FROM {$wpdb->posts} WHERE ID IN ($in)", ARRAY_A) ?: [] as $d) {
            $daty[(int) $d['ID']] = (string) $d['post_date_gmt'];
        }
        $meta  = [];
        foreach ($wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($in)"
            . " AND meta_key IN ('url','referrer','ip','ua','logged_at')", ARRAY_A) ?: [] as $m) {
            $meta[(int) $m['post_id']][$m['meta_key']] = (string) $m['meta_value'];
        }
        foreach ($paczka as $id) {
            $m    = $meta[$id] ?? [];
            $czas = !empty($m['logged_at']) ? (int) get_gmt_from_date($m['logged_at'], 'U')
                  : (int) strtotime(($daty[$id] ?? '') . ' UTC');
            $wpis = evk_404_wiersz((string) ($m['url'] ?? '/'), (string) ($m['referrer'] ?? ''), (string) ($m['ip'] ?? ''), (string) ($m['ua'] ?? ''));
            $k    = $wpis['url_hash'];
            $a    = $adresy[$k] ?? ($wpis + ['hits' => 0, 'first_seen' => $czas, 'last_seen' => 0]);
            $a['hits']++;
            $a['first_seen'] = min((int) $a['first_seen'], $czas);
            // Dane ostatniego wejścia z NAJPÓŹNIEJSZEGO wpisu, nie z ostatniego w kolejności.
            if ($czas >= (int) $a['last_seen']) {
                $a['last_seen'] = $czas;
                $a['referrer']  = $wpis['referrer'];
                $a['ip']        = $wpis['ip'];
                $a['ua']        = $wpis['ua'];
            }
            $adresy[$k] = $a;
        }
    }
    foreach ($adresy as $a) {
        $wpdb->query($wpdb->prepare(
            'INSERT INTO ' . evk_404_table() . ' (url_hash, url, hits, first_seen, last_seen, referrer, ip, ua)'
            . ' VALUES (%s, %s, %d, %d, %d, %s, %s, %s)'
            . ' ON DUPLICATE KEY UPDATE hits = hits + %d',
            $a['url_hash'], $a['url'], $a['hits'], $a['first_seen'], $a['last_seen'], $a['referrer'], $a['ip'], $a['ua'], $a['hits']
        ));
    }
    evk_404_usun_stare_wpisy();
    evk_404_sprzatnij();
    return count($ids);
}

function evk_404_usun_stare_wpisy(): void {
    global $wpdb;
    $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'evk_404_log'");
    if (!$ids) return;
    $in = implode(',', array_map('intval', $ids));
    $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($in)");
    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID IN ($in)");
}

// =========================================================================
// ZAPIS TRAFIENIA
// =========================================================================

/**
 * Pola wiersza z surowego trafienia. Adres bez zapytania: skanery doklejają
 * różne `?…` do tej samej ścieżki, a przekierowanie i tak działa po ścieżce.
 * Kluczem jest ścieżka po tej samej normalizacji co w przekierowaniach 301
 * (małe litery, bez końcowego ukośnika), więc `/Oferta/` i `/oferta` to
 * jeden wiersz, a „Przekieruj" trafia w dokładnie ten adres.
 */
function evk_404_wiersz(string $uri, string $skad, string $ip, string $ua): array {
    $sciezka = (string) strtok($uri, '?');
    if ($sciezka === '' || $sciezka[0] !== '/') $sciezka = '/' . $sciezka;
    return [
        'url_hash' => md5(evk_301_normalize(rawurldecode($sciezka))),
        'url'      => mb_substr(sanitize_text_field($sciezka), 0, 2000),
        'referrer' => mb_substr(sanitize_text_field($skad), 0, 2000),
        'ip'       => mb_substr(sanitize_text_field($ip), 0, 45),
        'ua'       => mb_substr(sanitize_text_field($ua), 0, 500),
    ];
}

/**
 * Jedno trafienie 404: 'nowy' (pierwszy raz ten adres), 'kolejny', 'limit'
 * (ten IP przekroczył EVK_404_LIMIT_NA_MINUTE) albo 'blad'.
 */
function evk_404_zapisz(string $uri, string $skad, string $ip, string $ua, ?int $czas = null): string {
    global $wpdb;
    evk_404_maybe_upgrade();
    $t    = evk_404_table();
    $czas = $czas ?? time();
    $w    = evk_404_wiersz($uri, $skad, $ip, $ua);

    /* Limit liczy adresy, których OSTATNIE wejście było z tego IP w ciągu
       minuty. Człowiek odświeżający jeden zły adres to jeden wiersz i limitu
       nie dotknie; skaner sprawdzający kolejne adresy dobija do limitu po
       kilku sekundach i dalej nie pisze nic. */
    if ($w['ip'] !== '' && (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE ip = %s AND last_seen > %d", $w['ip'], $czas - MINUTE_IN_SECONDS
    )) >= EVK_404_LIMIT_NA_MINUTE) {
        return 'limit';
    }

    $wynik = $wpdb->query($wpdb->prepare(
        "INSERT INTO $t (url_hash, url, hits, first_seen, last_seen, referrer, ip, ua) VALUES (%s, %s, 1, %d, %d, %s, %s, %s)"
        . ' ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = %d, referrer = %s, ip = %s, ua = %s',
        $w['url_hash'], $w['url'], $czas, $czas, $w['referrer'], $w['ip'], $w['ua'],
        $czas, $w['referrer'], $w['ip'], $w['ua']
    ));
    if ($wynik === false) return 'blad';
    // Dla ON DUPLICATE KEY UPDATE MySQL oddaje 1 przy nowym wierszu, 2 przy zmianie istniejącego.
    if ((int) $wynik === 1) {
        evk_404_sprzatnij();
        return 'nowy';
    }
    return 'kolejny';
}

/** Ponad „Maks. adresów" znikają adresy najdawniej odwiedzane. */
function evk_404_sprzatnij(): void {
    global $wpdb;
    $t       = evk_404_table();
    $nadmiar = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t") - evk_404_max_logs();
    if ($nadmiar > 0) {
        $wpdb->query($wpdb->prepare("DELETE FROM $t ORDER BY last_seen ASC, id ASC LIMIT %d", $nadmiar));
    }
}

// =========================================================================
// PRZECHWYTYWANIE 404
// =========================================================================

add_action('template_redirect', function () {
    if (!is_404() || !evk_404_is_enabled()) return;

    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

    // Pomiń jeśli istnieje redirect 301
    if (evk_301_has_redirect($uri)) return;

    // Pomiń boty
    if (evk_404_skip_bots()) {
        $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        foreach (evk_404_bot_list() as $bot) {
            if ($ua && strpos($ua, strtolower($bot)) !== false) return;
        }
    }

    evk_404_zapisz(
        $uri,
        (string) wp_unslash($_SERVER['HTTP_REFERER'] ?? ''),
        evk_ip_klienta(),   // za Cloudflare adres odwiedzającego, nie węzła
        (string) wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? '')
    );
});

// =========================================================================
// AJAX — wyczyść logi, przekieruj adres
// =========================================================================

add_action('wp_ajax_evk_clear_404_logs', function () {
    check_ajax_referer('evk_tools_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    if (evk_404_tabela_gotowa()) $wpdb->query('DELETE FROM ' . evk_404_table());
    evk_404_usun_stare_wpisy();
    wp_send_json_success();
});

/**
 * „Przekieruj" z wiersza logu: przekierowanie 301 z tego adresu i usunięcie
 * wiersza — kolejne wejścia i tak nie trafią już do logu (pomija adresy
 * z przekierowaniem).
 */
add_action('wp_ajax_evk_404_przekieruj', function () {
    check_ajax_referer('evk_tools_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.', 403);
    global $wpdb;
    $id  = absint($_POST['id'] ?? 0);
    $url = ($id && evk_404_tabela_gotowa())
        ? (string) $wpdb->get_var($wpdb->prepare('SELECT url FROM ' . evk_404_table() . ' WHERE id = %d', $id))
        : '';
    if ($url === '') wp_send_json_error('Tego adresu nie ma już w logu — odśwież stronę.');

    $regula = evk_301_dodaj(rawurldecode($url), (string) wp_unslash($_POST['to'] ?? ''));
    if (is_wp_error($regula)) wp_send_json_error($regula->get_error_message());

    $wpdb->delete(evk_404_table(), ['id' => $id]);
    wp_send_json_success([
        'z'        => (string) get_post_meta($regula, 'redirect_from', true),
        'na'       => (string) get_post_meta($regula, 'redirect_to', true),
        'wlaczone' => evk_301_is_enabled(),
    ]);
});
