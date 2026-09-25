<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Wysyłka maili na PRAWDZIWYM WordPressie, przez PRAWDZIWE `wp_mail()`
 * i PHPMailera, do atrapy serwera SMTP (tests/lib/smtp-atrapa.js).
 *
 *   php tests/php/newsletter-wysylka.php <scenariusz> <port atrapy>
 *
 * Scenariusze:
 *   log       — trzy maile w jednym żądaniu, trzeci odrzucony: log SMTP ma
 *               mieć trzy wpisy z właściwym wynikiem (do 1.232.0 każdy kolejny
 *               mail dopisywał wszystkie poprzednie jeszcze raz).
 *   kampania  — paczka kampanii przez SMTP Evoke: nagłówki, jedno połączenie,
 *               bez wpisów w logu SMTP; zwykły mail po paczce bez naleciałości.
 *   limit     — atrapa przyjmuje jeden mail, dalej 451: bezpiecznik paczki
 *               i odpowiedź serwera w błędzie.
 *   inny      — SMTP Evoke wyłączony, pocztę ustawia „inna wtyczka": kampania
 *               i potwierdzenie zapisu wychodzą nią.
 *   transport — co panel mówi o transporcie w trzech układach, plus
 *               mu-plugin podpinający pocztę (ścieżka zamiast samej nazwy).
 *   transport-dowiazanie — to samo, gdy WordPress stoi pod ścieżką PRZEZ
 *               dowiązanie symboliczne (ABSPATH ustawione przed wp-load.php,
 *               jak w wp-config.php z twardą ścieżką). Zgłoszenie po 1.233.4:
 *               „Pocztę ustawiają dwie wtyczki: SMTP Evoke i pluggable.php"
 *               — rdzeniowe wp_mail() brane za obcą wtyczkę.
 *   odbicia   — skrzynka nie istnieje (5.1.1): wykluczenie i wypisanie ze
 *               wszystkich list, bez bezpiecznika; odmowa przekazania (5.7.1)
 *               to NIE odbicie.
 *
 * Ustawienia SMTP, newslettera i log wracają do stanu sprzed sondy; listy,
 * szablony i kampanie sondy znikają.
 */

/* Scenariusz z dowiązaniem: ABSPATH musi stać, ZANIM wp-load.php je ustawi
   z własnego __DIR__ (rozwiniętego). Dowiązanie znika przy sprzątaniu. */
$evk_dowiazanie = '';
if (($argv[1] ?? '') === 'transport-dowiazanie') {
    $evk_cel = getenv('EVK_WP_PATH') ?: (getenv('HOME') . '/.cache/evk-testowy-wp');
    $evk_dowiazanie = sys_get_temp_dir() . '/evk-t-wp-dowiazanie';
    if (is_link($evk_dowiazanie)) unlink($evk_dowiazanie);
    if (is_dir($evk_cel)) {
        symlink($evk_cel, $evk_dowiazanie);
        define('ABSPATH', $evk_dowiazanie . '/');
    }
}

require __DIR__ . '/_testowy-wp.php';

$scenariusz = $argv[1] ?? '';
$port       = (int) ($argv[2] ?? 0);
$out = ['scenariusz' => $scenariusz];
$sprzatanie = [];
register_shutdown_function(static function () use (&$sprzatanie) {
    foreach (array_reverse($sprzatanie) as $f) { try { $f(); } catch (Throwable $e) {} }
});
wp_set_current_user(1);

/** Ustawia SMTP Evoke na atrapę; stan sprzed sondy wraca przy sprzątaniu. */
function evk_t_smtp_na_atrape(int $port, array $inne = []): void {
    global $sprzatanie;
    foreach (['evk_smtp', 'evk_smtp_log'] as $opcja) {
        $przed = get_option($opcja, null);
        $sprzatanie[] = static function () use ($opcja, $przed) {
            $przed === null ? delete_option($opcja) : update_option($opcja, $przed);
        };
    }
    update_option('evk_smtp', array_merge([
        'enabled' => 1, 'host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none',
        'username' => 'atrapa', 'password' => 'test-haslo', 'from_email' => 'nadawca@example.com',
        'from_name' => 'Evoke Test', 'log_enabled' => 1, 'log_max' => 100,
    ], $inne));
    delete_option('evk_smtp_log');
}

/** Włącza newsletter na czas sondy; kolejkę WordPress ładuje tylko przy włączonym module. */
function evk_t_newsletter_wlacz(): void {
    global $sprzatanie;
    $przed = get_option('evk_newsletter', null);
    $sprzatanie[] = static function () use ($przed) {
        $przed === null ? delete_option('evk_newsletter') : update_option('evk_newsletter', $przed);
    };
    update_option('evk_newsletter', array_merge(is_array($przed) ? $przed : [], ['enabled' => 1, 'unsub_mailto' => '']));
    evk_nl_create_tables();
    if (!function_exists('evk_nl_process_batch')) require dirname(__DIR__, 2) . '/includes/newsletter/queue.php';
    // Bez rozkładania maili w czasie — paczka ma przejść w sekundę, nie w cztery minuty.
    add_filter('evk_nl_batch_spread_max_seconds', '__return_zero');
    add_filter('evk_nl_send_delay_ms', '__return_zero');
}

/** Lista z aktywnymi adresami, szablon i uruchomiona kampania. Znikają przy sprzątaniu. */
function evk_t_kampania(array $adresy): array {
    global $sprzatanie;
    $lista = (int) evk_nl_create_list('Sonda wysyłki ' . wp_rand());
    $sub = [];
    foreach ($adresy as $a) $sub[$a] = (int) evk_nl_add_subscriber($lista, $a, ['imie' => 'Ola']);
    $szablon = (int) evk_nl_create_template(['name' => 'Sonda ' . wp_rand(), 'subject' => 'Kampania {imie}',
        'body_html' => '<p>Cześć {imie}, <a href="https://example.com/oferta?a=1&amp;b=2">oferta</a>.</p>']);
    $kampania = (int) evk_nl_create_campaign(['name' => 'Sonda', 'template_id' => $szablon, 'lists' => [$lista],
        'batch_size' => 10, 'batch_interval' => 5, 'tracking_enabled' => 1]);
    $sprzatanie[] = static function () use ($lista, $szablon, $kampania) {
        wp_clear_scheduled_hook('evk_nl_process_batch', [$kampania]);
        evk_nl_delete_campaign($kampania);
        evk_nl_delete_template($szablon);
        evk_nl_delete_list($lista);
    };
    evk_nl_launch_campaign($kampania);
    return ['lista' => $lista, 'kampania' => $kampania, 'subskrybenci' => $sub];
}

/** Stan kolejki kampanii: adres → status (+ błąd). */
function evk_t_kolejka(int $kampania): array {
    global $wpdb;
    $q = evk_nl_table('queue');
    $s = evk_nl_table('subscribers');
    $wynik = [];
    foreach ($wpdb->get_results($wpdb->prepare(
        "SELECT q.status, q.error_message, s.email, s.token FROM $q q JOIN $s s ON s.id = q.subscriber_id WHERE q.campaign_id=%d ORDER BY q.id", $kampania
    ), ARRAY_A) as $r) {
        $wynik[$r['email']] = ['status' => $r['status'], 'blad' => (string) $r['error_message'], 'token' => $r['token']];
    }
    return $wynik;
}

/**
 * „Inna wtyczka pocztowa" — prawdziwy plik w katalogu wtyczek, podpięty pod
 * phpmailer_init, jak WP Mail SMTP i podobne. Kieruje pocztę do atrapy.
 */
function evk_t_inna_wtyczka(int $port): void {
    global $sprzatanie;
    $katalog = WP_PLUGIN_DIR . '/evk-t-inny-smtp';
    if (!is_dir($katalog)) mkdir($katalog);
    file_put_contents($katalog . '/inny-smtp.php', "<?php\nfunction evk_t_inny_smtp_init(\$pm) {\n"
        . "    \$pm->isSMTP(); \$pm->Host = '127.0.0.1'; \$pm->Port = " . $port . "; \$pm->SMTPAuth = false; \$pm->SMTPSecure = '';\n}\n"
        . "add_action('phpmailer_init', 'evk_t_inny_smtp_init');\n");
    require_once $katalog . '/inny-smtp.php';
    if (!has_action('phpmailer_init', 'evk_t_inny_smtp_init')) add_action('phpmailer_init', 'evk_t_inny_smtp_init');
    $sprzatanie[] = static function () use ($katalog) {
        @unlink($katalog . '/inny-smtp.php');
        @rmdir($katalog);
    };
}

/** Log SMTP skrótem: „temat → adresat: ok|błąd". */
function evk_t_log_smtp(): array {
    return array_map(static function ($w) {
        return $w['subject'] . ' → ' . $w['to'] . ': ' . ($w['success'] ? 'ok' : 'błąd');
    }, (array) get_option('evk_smtp_log', []));
}

switch ($scenariusz) {

// ── Log SMTP: jeden wpis na mail ────────────────────────────────────────────
case 'log':
    evk_t_smtp_na_atrape($port);
    $out['wyniki'] = [
        wp_mail('pierwsza@example.com', 'Pierwszy', 'Treść 1'),
        wp_mail('druga@example.com', 'Drugi', 'Treść 2'),
        wp_mail('odrzucona@example.com', 'Trzeci', 'Treść 3'),   // atrapa odrzuca RCPT
    ];
    $out['log'] = evk_t_log_smtp();
    $log = (array) get_option('evk_smtp_log', []);
    $out['blad_trzeciego'] = (string) ($log[0]['error'] ?? '');
    break;

// ── Kampania przez SMTP Evoke ───────────────────────────────────────────────
case 'kampania':
    evk_t_smtp_na_atrape($port);
    evk_t_newsletter_wlacz();
    $k = evk_t_kampania(['k1@example.com', 'k2@example.com', 'k3@example.com']);
    evk_nl_process_batch($k['kampania']);
    $out['kolejka'] = evk_t_kolejka($k['kampania']);
    $out['status_kampanii'] = evk_nl_get_campaign($k['kampania'])['status'] ?? '';
    $out['log_po_paczce'] = evk_t_log_smtp();
    $out['unsub'] = [];
    foreach ($out['kolejka'] as $adres => $w) $out['unsub'][$adres] = evk_nl_unsubscribe_url($w['token']);
    // Zwykły mail po paczce: własne połączenie, nagłówki bez naleciałości.
    $out['zwykly'] = wp_mail('po@example.com', 'Po kampanii', 'Zwykły mail');
    $out['log_po_zwyklym'] = evk_t_log_smtp();
    $out['nazwa_strony'] = get_bloginfo('name');
    break;

// ── Limit dostawcy: bezpiecznik paczki ──────────────────────────────────────
case 'limit':
    evk_t_smtp_na_atrape($port);
    evk_t_newsletter_wlacz();
    $k = evk_t_kampania(['l1@example.com', 'l2@example.com', 'l3@example.com', 'l4@example.com', 'l5@example.com']);
    evk_nl_process_batch($k['kampania']);
    $out['kolejka'] = array_map(static function ($w) { unset($w['token']); return $w; }, evk_t_kolejka($k['kampania']));
    global $wpdb;
    $out['logi'] = $wpdb->get_col($wpdb->prepare("SELECT data_json FROM " . evk_nl_table('logs') . " WHERE campaign_id=%d AND event='error'", $k['kampania']));
    break;

// ── Inna wtyczka pocztowa, SMTP Evoke wyłączony ─────────────────────────────
case 'inny':
    evk_t_smtp_na_atrape($port, ['enabled' => 0]);
    evk_t_inna_wtyczka($port);
    evk_t_newsletter_wlacz();
    $out['transport'] = evk_nl_transport();
    $out['ostrzezenie'] = evk_nl_ostrzezenie_transportu();
    $k = evk_t_kampania(['i1@example.com']);
    evk_nl_process_batch($k['kampania']);
    $out['kolejka'] = array_map(static function ($w) { unset($w['token']); return $w; }, evk_t_kolejka($k['kampania']));
    /* Oba limity z poprzednich przebiegów zostają w bazie: na adres IP
       i na maile z potwierdzeniem do jednego adresu (3 na dobę) — bez
       wyczyszczenia czwarty przebieg w ciągu doby nie wysłałby maila. */
    delete_transient('evk_nl_rl_' . md5(evk_nl_client_ip()));
    delete_transient('evk_nl_pt_' . md5('potwierdz@example.com'));
    $out['zapis'] = evk_nl_zapisz_z_formularza($k['lista'], 'potwierdz@example.com', [], 'Zgoda testowa', true, 'sonda');
    $out['log'] = evk_t_log_smtp();
    $out['nazwa_strony'] = get_bloginfo('name');
    break;

// ── Co panel mówi o transporcie ─────────────────────────────────────────────
case 'transport':
    evk_t_smtp_na_atrape($port, ['enabled' => 0]);
    $out['bez_niczego'] = [evk_nl_transport(), evk_nl_ostrzezenie_transportu()];
    update_option('evk_smtp', array_merge(get_option('evk_smtp'), ['enabled' => 1]));
    $out['sam_evoke'] = [evk_nl_transport(), evk_nl_ostrzezenie_transportu()];
    evk_t_inna_wtyczka($port);
    $out['evoke_i_inna'] = [evk_nl_transport(), evk_nl_ostrzezenie_transportu()];
    /* mu-plugin ustawiający SMTP (tak robią mu-pluginy hostingu): leży poza
       katalogiem wtyczek, więc nazwą jest ścieżka od katalogu WordPressa —
       do 1.233.4 sama nazwa pliku, z której nie wynikało, gdzie go szukać. */
    $mu = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
    $mu_bylo = is_dir($mu);
    if (!$mu_bylo) mkdir($mu, 0755, true);
    file_put_contents($mu . '/evk-t-mu-poczta.php', "<?php\nfunction evk_t_mu_poczta(\$pm) { \$pm->isSMTP(); \$pm->Host = '127.0.0.1'; }\n"
        . "add_action('phpmailer_init', 'evk_t_mu_poczta');\n");
    $sprzatanie[] = static function () use ($mu, $mu_bylo) {
        @unlink($mu . '/evk-t-mu-poczta.php');
        if (!$mu_bylo) @rmdir($mu);
    };
    require_once $mu . '/evk-t-mu-poczta.php';
    $out['z_mu_plugin'] = evk_nl_transport();
    /* Funkcja wbudowana PHP jako wywołanie zwrotne: nie ma pliku
       (getFileName() === false), a realpath('') oddałby bieżący katalog. */
    add_filter('pre_wp_mail', 'is_null');
    $out['z_wbudowana'] = evk_nl_transport();
    remove_filter('pre_wp_mail', 'is_null');
    break;

// ── Transport, gdy WordPress stoi pod ścieżką przez dowiązanie ──────────────
case 'transport-dowiazanie':
    $sprzatanie[] = static function () use ($evk_dowiazanie) { if (is_link($evk_dowiazanie)) unlink($evk_dowiazanie); };
    $out['warunek'] = [
        'abspath_przez_dowiazanie' => $evk_dowiazanie !== '' && strpos(ABSPATH, $evk_dowiazanie) === 0,
        // Tak PHP widzi plik rdzeniowego wp_mail(): po rozwinięciu dowiązania.
        'wp_mail_poza_abspath'     => strpos(wp_normalize_path((string) (new ReflectionFunction('wp_mail'))->getFileName()), wp_normalize_path(ABSPATH)) !== 0,
    ];
    evk_t_smtp_na_atrape($port);
    $out['sam_evoke'] = [evk_nl_transport(), evk_nl_ostrzezenie_transportu()];
    evk_t_inna_wtyczka($port);
    $out['evoke_i_inna'] = [evk_nl_transport(), evk_nl_ostrzezenie_transportu()];
    break;

// ── Odbicia: skrzynka nie istnieje ──────────────────────────────────────────
case 'odbicia':
    evk_t_smtp_na_atrape($port);
    evk_t_newsletter_wlacz();
    $wyk_przed = get_option('evk_nl_wykluczenia', null);
    $sprzatanie[] = static function () use ($wyk_przed) {
        $wyk_przed === null ? delete_option('evk_nl_wykluczenia') : update_option('evk_nl_wykluczenia', $wyk_przed);
    };
    delete_option('evk_nl_wykluczenia');
    $k = evk_t_kampania(['b1@example.com', 'b2@example.com', 'b3@example.com', 'relay@example.com', 'ok1@example.com', 'ok2@example.com']);
    // Ten sam adres na drugiej liście — odbicie wyłącza go wszędzie.
    $druga = (int) evk_nl_create_list('Sonda odbić druga ' . wp_rand());
    $sprzatanie[] = static function () use ($druga) { evk_nl_delete_list($druga); };
    evk_nl_add_subscriber($druga, 'b1@example.com');
    evk_nl_process_batch($k['kampania']);
    $out['kolejka'] = array_map(static function ($w) { unset($w['token']); return $w; }, evk_t_kolejka($k['kampania']));
    $out['wykluczenia'] = array_map(static function ($w) { return $w['powod'] ?? ''; }, (array) get_option('evk_nl_wykluczenia', []));
    global $wpdb;
    $out['b1_na_listach'] = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT status FROM ' . evk_nl_table('subscribers') . ' WHERE email = %s ORDER BY id', 'b1@example.com')));
    $out['relay_status'] = (int) $wpdb->get_var($wpdb->prepare('SELECT status FROM ' . evk_nl_table('subscribers') . ' WHERE email = %s', 'relay@example.com'));
    $out['logi'] = $wpdb->get_col($wpdb->prepare("SELECT data_json FROM " . evk_nl_table('logs') . " WHERE campaign_id=%d AND event='error'", $k['kampania']));
    break;

default:
    $out['blad'] = 'nieznany scenariusz';
}

echo wp_json_encode($out);
