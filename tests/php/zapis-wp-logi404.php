<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Logi 404 na PRAWDZIWYM WordPressie (1.234.0): jeden wiersz na adres
 * w tabeli evk_404, limit na IP, przeniesienie starych wpisów, „Przekieruj".
 *
 *   php tests/php/zapis-wp-logi404.php logika
 *   php tests/php/zapis-wp-logi404.php przygotuj-panel
 *   php tests/php/zapis-wp-logi404.php stan
 *   php tests/php/zapis-wp-logi404.php sprzataj
 *
 * „logika" robi wszystko w jednym procesie i sama sprząta. Kroki „panel"
 * obsługują test w przeglądarce (ekran i przycisk „Przekieruj" przez php -S);
 * opcje sprzed nich leżą w pliku tymczasowym do „sprzataj".
 *
 * Tabela evk_404 jest czyszczona — to testowy WordPress, a żaden inny test
 * nie trzyma w niej danych między przebiegami (ip-klienta czyta i usuwa swój
 * wiersz od razu).
 */

define('SAVEQUERIES', true);
require __DIR__ . '/_testowy-wp.php';

global $wpdb, $wp_query;
$krok  = $argv[1] ?? '';
$plik  = sys_get_temp_dir() . '/evk-t-logi404.json';
$opcje = ['evk_404_enabled', 'evk_404_max_logs', 'evk_404_skip_bots', 'evk_301_enabled'];
$out   = ['krok' => $krok];

/** Wywołuje hooki danego pliku (np. template_redirect z logs-404.php). */
function evk_t_hak(string $hak, string $plik): int {
    global $wp_filter;
    $ile = 0;
    foreach ((array) ($wp_filter[$hak]->callbacks ?? []) as $funkcje) {
        foreach ($funkcje as $f) {
            $cb = $f['function'];
            try { $r = ($cb instanceof Closure || is_string($cb)) ? new ReflectionFunction($cb) : null; } catch (ReflectionException $e) { $r = null; }
            if ($r && basename((string) $r->getFileName()) === $plik) { $cb(); $ile++; }
        }
    }
    return $ile;
}

/** Jedno żądanie 404 przez prawdziwy hook: zapytania i save_post w jego trakcie. */
function evk_t_404(string $uri, string $ip, string $ua = 'Mozilla/5.0 test', string $skad = ''): array {
    global $wpdb, $wp_query, $evk_t_save_post;
    $_SERVER['REQUEST_URI']     = $uri;
    $_SERVER['REMOTE_ADDR']     = $ip;
    $_SERVER['HTTP_USER_AGENT'] = $ua;
    $_SERVER['HTTP_REFERER']    = $skad;
    $wp_query->set_404();
    $q  = count($wpdb->queries);
    $sp = $evk_t_save_post;
    evk_t_hak('template_redirect', 'logs-404.php');
    return ['zapytan' => count($wpdb->queries) - $q, 'save_post' => $evk_t_save_post - $sp];
}

function evk_t_wiersz(string $url): ?array {
    global $wpdb;
    $r = $wpdb->get_row($wpdb->prepare('SELECT url, hits, first_seen, last_seen, referrer, ip, ua FROM ' . evk_404_table() . ' WHERE url = %s', $url), ARRAY_A);
    return $r ? array_map(static function ($v) { return is_numeric($v) ? (int) $v : $v; }, $r) : null;
}

function evk_t_zachowaj(string $plik, array $opcje): void {
    if (is_file($plik)) return;
    $przed = [];
    foreach ($opcje as $o) $przed[$o] = get_option($o, null);
    file_put_contents($plik, wp_json_encode($przed));
}

function evk_t_przywroc(string $plik): void {
    foreach ((array) (is_file($plik) ? json_decode((string) file_get_contents($plik), true) : []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    @unlink($plik);
}

/** Reguły przekierowań sondy (adresy /t404-…) — znikają przy sprzątaniu. */
function evk_t_usun_reguly(): void {
    foreach (evk_301_get_all() as $r) {
        if (strpos((string) ($r['from'] ?? ''), '/t404-') === 0) wp_delete_post((int) $r['ID'], true);
    }
    evk_301_clear_cache();
}

$evk_t_save_post = 0;
add_action('save_post', static function () { $GLOBALS['evk_t_save_post']++; });

switch ($krok) {

case 'logika':
    evk_t_zachowaj($plik, $opcje);
    update_option('evk_404_enabled', 1);
    update_option('evk_404_skip_bots', 1);
    update_option('evk_404_max_logs', 5000);
    update_option('evk_301_enabled', 0);
    evk_404_maybe_upgrade();
    $wpdb->query('DELETE FROM ' . evk_404_table());

    // ── Koszt trafienia ────────────────────────────────────────────────────
    $out['koszt'] = [
        'nowy'    => evk_t_404('/t404-koszt/', '198.51.100.1'),
        'kolejny' => evk_t_404('/t404-koszt/', '198.51.100.1'),
    ];

    // ── Jeden wiersz na adres ──────────────────────────────────────────────
    evk_t_404('/t404-Oferta/', '198.51.100.2', 'Mozilla/5.0 A', 'https://a.example/');
    evk_t_404('/t404-oferta', '198.51.100.3', 'Mozilla/5.0 B', 'https://b.example/');
    evk_t_404('/t404-oferta/?utm_source=x', '198.51.100.3', 'Mozilla/5.0 B', 'https://b.example/');
    $out['oferta'] = evk_t_wiersz('/t404-Oferta/');
    $out['oferta_wierszy'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . evk_404_table() . " WHERE url LIKE '/t404-%ferta%'");

    // ── Roboty ────────────────────────────────────────────────────────────
    evk_t_404('/t404-robot/', '198.51.100.4', 'Mozilla/5.0 (compatible; Googlebot/2.1)');
    $out['robot'] = evk_t_wiersz('/t404-robot/');

    // ── Limit na IP ────────────────────────────────────────────────────────
    $wyniki = [];
    for ($i = 1; $i <= 25; $i++) $wyniki[] = evk_404_zapisz('/t404-skaner-' . $i . '/', '', '192.0.2.9', 'skaner');
    $out['limit'] = [
        'wyniki'      => array_count_values($wyniki),
        'wierszy_ip'  => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . evk_404_table() . ' WHERE ip = %s', '192.0.2.9')),
        'inny_ip'     => evk_404_zapisz('/t404-czlowiek/', '', '192.0.2.10', 'Mozilla/5.0'),
        'po_minucie'  => evk_404_zapisz('/t404-skaner-99/', '', '192.0.2.9', 'skaner', time() + MINUTE_IN_SECONDS + 1),
    ];

    // ── Maks. adresów ──────────────────────────────────────────────────────
    $wpdb->query('DELETE FROM ' . evk_404_table());
    update_option('evk_404_max_logs', 10);
    $t0 = time() - 3600;
    for ($i = 0; $i < 15; $i++) evk_404_zapisz('/t404-max-' . $i . '/', '', '203.0.113.' . ($i + 1), 'x', $t0 + $i);
    $out['max'] = $wpdb->get_col('SELECT url FROM ' . evk_404_table() . ' ORDER BY last_seen ASC');
    update_option('evk_404_max_logs', 5000);

    // ── Adres z przekierowaniem nie trafia do logu ─────────────────────────
    update_option('evk_301_enabled', 1);
    evk_t_usun_reguly();
    $regula = evk_301_dodaj('/t404-przekierowany/', 'cel-bez-ukosnika');
    evk_t_404('/t404-przekierowany/', '198.51.100.5');
    $out['przekierowany'] = [
        'cel'   => is_wp_error($regula) ? $regula->get_error_message() : (string) get_post_meta($regula, 'redirect_to', true),
        'z'     => is_wp_error($regula) ? '' : (string) get_post_meta($regula, 'redirect_from', true),
        'w_logu' => evk_t_wiersz('/t404-przekierowany/'),
        'ten_sam_z' => is_wp_error($regula) ? null : (evk_301_dodaj('/T404-Przekierowany', '/inny-cel') === $regula),
        'petla' => (($p = evk_301_dodaj('/t404-petla', '/t404-petla/')) instanceof WP_Error) ? $p->get_error_code() : 'brak błędu',
    ];
    evk_t_usun_reguly();
    update_option('evk_301_enabled', 0);

    // ── Przeniesienie wpisów sprzed 1.234.0 ────────────────────────────────
    $wpdb->query('DELETE FROM ' . evk_404_table());
    $stare = [
        ['/t404-stary/', '2026-09-20 10:00:00', '203.0.113.1', 'https://x.example/'],
        ['/t404-stary/', '2026-09-22 12:30:00', '203.0.113.2', 'https://y.example/'],
        ['/t404-stary/', '2026-09-21 08:00:00', '203.0.113.3', ''],
        ['/t404-inny/',  '2026-09-23 09:00:00', '203.0.113.4', ''],
    ];
    foreach ($stare as [$url, $kiedy, $ip, $skad]) {
        wp_insert_post(['post_type' => 'evk_404_log', 'post_status' => 'publish', 'post_title' => $url . ' — ' . $kiedy,
            'meta_input' => ['url' => $url, 'referrer' => $skad, 'ip' => $ip, 'ua' => 'Stara', 'method' => 'GET', 'logged_at' => $kiedy]]);
    }
    delete_option('evk_404_db_version');
    evk_404_maybe_upgrade();
    $out['przeniesione'] = [
        'stary'  => evk_t_wiersz('/t404-stary/'),
        'inny'   => evk_t_wiersz('/t404-inny/'),
        'czas_od' => (int) get_gmt_from_date('2026-09-20 10:00:00', 'U'),
        'czas_do' => (int) get_gmt_from_date('2026-09-22 12:30:00', 'U'),
        'zostalo_starych' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'evk_404_log'"),
        'wersja' => get_option('evk_404_db_version'),
    ];

    $wpdb->query('DELETE FROM ' . evk_404_table());
    evk_t_przywroc($plik);
    break;

case 'przygotuj-panel':
    evk_t_zachowaj($plik, $opcje);
    update_option('evk_404_enabled', 1);
    update_option('evk_404_max_logs', 200);
    update_option('evk_301_enabled', 0);   // ekran ma ostrzec, że przekierowania nie działają
    evk_404_maybe_upgrade();
    $wpdb->query('DELETE FROM ' . evk_404_table());
    evk_t_usun_reguly();
    $t = time() - 600;
    foreach ([['/t404-stara-oferta/', 3], ['/t404-kontakt-old/', 1]] as [$url, $ile]) {
        for ($i = 0; $i < $ile; $i++) evk_404_zapisz($url, 'https://google.example/', '198.51.100.' . (10 + $i), 'Mozilla/5.0 panel', $t + $i);
    }
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'stan':
    $out['wiersze'] = $wpdb->get_col('SELECT url FROM ' . evk_404_table() . ' ORDER BY url');
    $out['reguly'] = array_values(array_map(static function ($r) { return [$r['from'], $r['to']]; },
        array_filter(evk_301_get_all(), static function ($r) { return strpos((string) ($r['from'] ?? ''), '/t404-') === 0; })));
    break;

case 'sprzataj':
    if (evk_404_tabela_gotowa()) $wpdb->query("DELETE FROM " . evk_404_table() . " WHERE url LIKE '/t404-%'");
    evk_t_usun_reguly();
    evk_t_przywroc($plik);
    break;
}

echo wp_json_encode($out);
