<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Statystyki: zbieranie odsłon (1.283.0).
 *
 * Skrypt na stronie (assets/js/statystyki.js, ~1 KB po minifikacji) wysyła
 * `navigator.sendBeacon` na trasę REST `evoke/v1/stat`: raz przy wczytaniu
 * (odsłona) i przy schowaniu karty (czas widoczności i przewinięcie). Beacon
 * idzie z przeglądarki, więc liczy także strony podawane z pamięci podręcznej
 * serwera — PHP strony się wtedy nie wykonuje, a trasa REST tak.
 *
 * `evoke/v1` jest na domyślnej liście wyjątków blokady REST (1.281.0).
 */

const EVK_STAT_BOTY = '/bot\b|bot\/|crawl|spider|slurp|facebookexternalhit|headless|lighthouse|pingdom|uptime|monitor|preview|python|curl|wget|httpclient|java\/|go-http|okhttp|scrapy|phantom|selenium|puppeteer|playwright/i';
/** Najwięcej odsłon jednej wizyty dziennie — reszta to automat albo zabawa beaconem. */
const EVK_STAT_LIMIT_WIZYTY = 300;

/** Sól dnia: losowa, zmieniana o północy (strefa strony). Wczorajsza ginie bezpowrotnie. */
function evk_stat_sol(): string {
    $dzis = wp_date('Y-m-d');
    $z    = get_option('evk_stat_sol', []);
    if (is_array($z) && ($z['dzien'] ?? '') === $dzis && !empty($z['sol'])) return (string) $z['sol'];
    $sol = bin2hex(random_bytes(32));
    update_option('evk_stat_sol', ['dzien' => $dzis, 'sol' => $sol], false);
    return $sol;
}

/**
 * Skrót wizyty: sól dnia + IP + przeglądarka. 16 znaków szesnastkowych.
 * Bez domeny: sól i tak jest osobna dla każdej strony, a domena w skrócie
 * liczyłaby jedną osobę dwa razy, gdy strona odpowiada pod dwoma adresami
 * (z „www” i bez).
 */
function evk_stat_wizyta(string $ip, string $ua): string {
    return substr(hash_hmac('sha256', $ip . '|' . $ua, evk_stat_sol()), 0, 16);
}

/** Czy żądanie pochodzi od bota (po nagłówku User-Agent). */
function evk_stat_bot(string $ua): bool {
    return trim($ua) === '' || (bool) preg_match(EVK_STAT_BOTY, $ua);
}

/** Urządzenie z szerokości okna (CSS px) — tak, jak widzi je układ strony. */
function evk_stat_urzadzenie(int $szer): string {
    if ($szer <= 0) return '';
    return $szer < 768 ? 'telefon' : ($szer < 1100 ? 'tablet' : 'komputer');
}

/** @return array{0:string,1:string} [przeglądarka, system] */
function evk_stat_przegladarka(string $ua): array {
    $p = 'Inna';
    foreach (['Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung', 'Firefox/' => 'Firefox', 'FxiOS' => 'Firefox',
              'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari'] as $znak => $nazwa) {
        if (strpos($ua, $znak) !== false) { $p = $nazwa; break; }
    }
    $s = 'Inny';
    foreach (['iPhone' => 'iOS', 'iPad' => 'iOS', 'Android' => 'Android', 'Windows' => 'Windows', 'CrOS' => 'ChromeOS',
              'Mac OS X' => 'macOS', 'Linux' => 'Linux'] as $znak => $nazwa) {
        if (strpos($ua, $znak) !== false) { $s = $nazwa; break; }
    }
    return [$p, $s];
}

/**
 * Źródło odsłony z adresu odsyłającego: domena bez „www.”, „(bezpośrednio)”
 * bez odsyłacza, pusty łańcuch przy przejściu wewnątrz strony (nie jest
 * źródłem, raport go nie liczy w źródłach).
 */
function evk_stat_zrodlo(string $ref): string {
    if (trim($ref) === '') return '(bezpośrednio)';
    $host = strtolower((string) wp_parse_url($ref, PHP_URL_HOST));
    if ($host === '') return '(bezpośrednio)';
    $host = preg_replace('/^www\./', '', $host);
    $moj  = preg_replace('/^www\./', '', strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST)));
    return $host === $moj ? '' : substr($host, 0, 191);
}

/** Parametry adresu, które wskazują stronę przy zwykłych adresach (bez „ładnych”). */
const EVK_STAT_PARAMETRY_STRONY = ['p', 'page_id', 'cat', 'tag', 'author', 'post_type', 'paged', 'm', 'year', 'monthnum', 'day', 'attachment_id'];

/**
 * Ścieżka odsłony (1.285.0): ścieżka adresu plus parametry wskazujące stronę,
 * posortowane. Przy zwykłych adresach (`/?page_id=12`) sama ścieżka „/”
 * zlewała wszystkie strony w jedną; UTM i parametry śledzące odpadają.
 */
function evk_stat_sciezka(string $sciezka, string $zapytanie = ''): string {
    $s = '/' . ltrim((string) wp_parse_url('/' . ltrim($sciezka, '/'), PHP_URL_PATH), '/');
    parse_str(ltrim($zapytanie, '?'), $q);
    $q = array_intersect_key($q, array_flip(EVK_STAT_PARAMETRY_STRONY));
    $q = array_filter(array_map(static function ($v): string { return is_scalar($v) ? substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $v), 0, 40) : ''; }, $q),
        static function (string $v): bool { return $v !== ''; });
    ksort($q);
    return substr($q ? $s . '?' . http_build_query($q) : $s, 0, 255);
}

/** Czy adres IP jest na liście wykluczonych. */
function evk_stat_ip_wykluczony(string $ip, string $lista): bool {
    if ($ip === '' || trim($lista) === '') return false;
    foreach (preg_split('/[\s,;]+/', $lista) ?: [] as $siec) {
        if (evk_ip_w_sieci($ip, $siec)) return true;
    }
    return false;
}

/**
 * Zapis jednej wiadomości beaconu. Zwraca powód odrzucenia albo „ok” —
 * odpowiedź trasy jest zawsze 204, powód widać tylko w testach.
 *
 * @param array<string,mixed> $d       Treść beaconu.
 * @param array<string,mixed> $serwer  $_SERVER (nagłówki).
 */
function evk_stat_zapisz(array $d, array $serwer): string {
    global $wpdb;
    $s  = evk_stat_ustawienia();
    if (empty($s['enabled'])) return 'wylaczone';
    $ua = (string) ($serwer['HTTP_USER_AGENT'] ?? '');
    if (!empty($s['dnt']) && (($serwer['HTTP_DNT'] ?? '') === '1' || ($serwer['HTTP_SEC_GPC'] ?? '') === '1')) return 'dnt';
    if (evk_stat_bot($ua)) return 'bot';
    $ip = function_exists('evk_ip_klienta') ? evk_ip_klienta($serwer) : (string) ($serwer['REMOTE_ADDR'] ?? '');
    if (evk_stat_ip_wykluczony($ip, (string) $s['wyklucz_ip'])) return 'ip';
    $klucz = (string) ($d['k'] ?? '');
    if (!preg_match('/^[0-9a-f]{16}$/', $klucz)) return 'klucz';
    if ((int) get_option('evk_stat_db_version', 0) !== EVK_STAT_DB_WERSJA) evk_stat_utworz_tabele();
    $tab = evk_stat_tabela('odslony');

    if (($d['t'] ?? '') === 'k') {
        /* Koniec odsłony: czas widoczności (do 30 min) i największe przewinięcie.
           Beacon może przyjść kilka razy (każde schowanie karty) — bierzemy większe. */
        $wpdb->query($wpdb->prepare(
            "UPDATE $tab SET czas_s = GREATEST(czas_s, %d), przewiniecie = GREATEST(przewiniecie, %d) WHERE klucz = %s AND czas > %s",
            max(0, min(1800, (int) ($d['c'] ?? 0))), max(0, min(100, (int) ($d['d'] ?? 0))), $klucz, gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS)
        ));
        return 'ok';
    }
    if (($d['t'] ?? '') !== 'v') return 'typ';

    $dzien  = wp_date('Y-m-d');
    $wizyta = evk_stat_wizyta($ip, $ua);
    $ile = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $tab WHERE dzien = %s AND wizyta = %s", $dzien, $wizyta));
    if ($ile >= EVK_STAT_LIMIT_WIZYTY) return 'limit';

    $sciezka = evk_stat_sciezka((string) ($d['s'] ?? '/'), (string) ($d['q'] ?? ''));
    [$przegl, $system] = evk_stat_przegladarka($ua);
    $utm = static function ($w): string { return substr(strtolower(trim(sanitize_text_field((string) $w))), 0, 100); };
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO $tab (czas, dzien, klucz, wizyta, sciezka, post_id, zrodlo, utm_source, utm_medium, utm_campaign, urzadzenie, przegladarka, system_op, jezyk)
         VALUES (%s, %s, %s, %s, %s, %d, %s, %s, %s, %s, %s, %s, %s, %s)",
        gmdate('Y-m-d H:i:s'), $dzien, $klucz, $wizyta, substr($sciezka, 0, 255), max(0, (int) ($d['p'] ?? 0)),
        evk_stat_zrodlo((string) ($d['r'] ?? '')), $utm($d['us'] ?? ''), $utm($d['um'] ?? ''), $utm($d['uc'] ?? ''),
        evk_stat_urzadzenie((int) ($d['w'] ?? 0)), $przegl, $system, substr(sanitize_key((string) ($d['j'] ?? '')), 0, 10)
    ));
    return 'ok';
}

add_action('rest_api_init', function (): void {
    if (!evk_stat_wlaczone()) return;
    register_rest_route('evoke/v1', '/stat', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => static function (WP_REST_Request $r) {
            $d = json_decode((string) $r->get_body(), true);
            $wynik = is_array($d) ? evk_stat_zapisz($d, $_SERVER) : 'tresc';
            $odp = new WP_REST_Response(null, 204);
            $odp->header('X-Evk-Stat', $wynik);
            $odp->header('Cache-Control', 'no-store');
            return $odp;
        },
    ]);
});

/** Czy na tej stronie drukować skrypt statystyk. */
function evk_stat_liczyc_strone(): bool {
    if (!evk_stat_wlaczone() || is_admin() || is_feed() || is_404() || is_preview() || is_customize_preview()) return false;
    if (function_exists('bricks_is_builder') && (bricks_is_builder() || (function_exists('bricks_is_builder_iframe') && bricks_is_builder_iframe()))) return false;
    if (!empty(evk_stat_ustawienia()['wyklucz_role']) && current_user_can('edit_posts')) return false;
    return true;
}

add_action('wp_enqueue_scripts', function (): void {
    if (!evk_stat_liczyc_strone()) return;
    $s = evk_stat_ustawienia();
    wp_enqueue_script('evk-statystyki', evk_zasob_url(EVOKE_ONE_URL . 'assets/js/statystyki.js'), [], EVOKE_ONE_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
    wp_add_inline_script('evk-statystyki', 'window.evkStat=' . wp_json_encode([
        'u' => rest_url('evoke/v1/stat'),
        'p' => is_singular() ? (int) get_queried_object_id() : 0,
        'j' => function_exists('get_current_lang') ? get_current_lang() : '',
        'd' => !empty($s['dnt']) ? 1 : 0,
    ]) . ';', 'before');
});
