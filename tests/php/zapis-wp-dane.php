<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Dane i prywatność (wydanie 1.232.0) na PRAWDZIWYM WordPressie
 * (tools/testowy-wp.sh):
 *   — kolizje z innymi wtyczkami: tylko znane nazwy (bez „system*.php"),
 *   — przegenerowanie obrazka OG tylko z prawem do tego wpisu,
 *   — eksport ustawień bez haseł, chyba że ktoś zaznaczy „Dołącz hasła",
 *   — RODO: eksport i usuwanie danych osoby, tekst do polityki prywatności.
 *
 * Odinstalowanie ma własną sondę na TRZECIEJ, jednorazowej stronie
 * (tests/php/zapis-wp-odinstalowanie.php) — tu kasowałoby dane innym testom.
 *
 *   php tests/php/zapis-wp-dane.php <scenariusz>
 *
 * Każdy scenariusz sprząta po sobie.
 */

require __DIR__ . '/_testowy-wp.php';

$scenariusz = $argv[1] ?? '';
$out = ['scenariusz' => $scenariusz];
$sprzatanie = [];
register_shutdown_function(static function () use (&$sprzatanie) {
    foreach (array_reverse($sprzatanie) as $f) { try { $f(); } catch (Throwable $e) {} }
});

/** Uchwyt AJAX-a: wp_send_json kończy się wp_die — zamieniamy je na wyjątek. */
function evk_t_ajax(string $akcja): array {
    add_filter('wp_die_ajax_handler', static function () {
        return static function ($m) { throw new RuntimeException('wp_die'); };
    });
    if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
    ob_start();
    $blad = '';
    try { do_action('wp_ajax_' . $akcja); }
    catch (RuntimeException $e) { /* koniec przez wp_send_json */ }
    catch (Throwable $e) { $blad = get_class($e) . ': ' . $e->getMessage(); }
    $tekst = (string) ob_get_clean();
    return ['blad' => $blad, 'odpowiedz' => json_decode($tekst, true), 'surowe' => substr($tekst, 0, 300)];
}

/** Użytkownik testowy o danej roli, usuwany na końcu. */
function evk_t_uzytkownik(string $rola, array &$sprzatanie): int {
    $uid = (int) wp_insert_user(['user_login' => 'evk_t_' . $rola . '_' . wp_rand(), 'user_pass' => wp_generate_password(),
                                 'user_email' => 'evk_t_' . $rola . '_' . wp_rand() . '@example.com', 'role' => $rola]);
    $sprzatanie[] = static function () use ($uid) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($uid); };
    return $uid;
}

wp_set_current_user(1);

switch ($scenariusz) {

// ── Kolizje z innymi wtyczkami ──────────────────────────────────────────────
case 'konflikty':
    $przy = static function (array $wtyczki): bool {
        $f = static function () use ($wtyczki) { return array_merge(['evoke-one/evoke-one.php'], $wtyczki); };
        add_filter('pre_option_active_plugins', $f);
        $jest = evoke_one_check_conflicts();
        remove_filter('pre_option_active_plugins', $f);
        return $jest;
    };
    $out['nic']               = $przy([]);
    $out['system_dashboard']  = $przy(['system-dashboard/system-dashboard.php']);
    $out['systempay']         = $przy(['systempay/systempay.php']);
    $out['stare_tlumaczenia'] = $przy(['evoke-tlumaczenia/evoke-tlumaczenia.php']);
    $out['parallax']          = $przy(['evk-parallax/evk-parallax.php']);
    $out['wp_maintenance']    = $przy(['wp-maintenance-mode/wp-maintenance-mode.php']);
    break;

// ── Przegenerowanie obrazka OG ──────────────────────────────────────────────
case 'og-prawo':
    $autor = evk_t_uzytkownik('author', $sprzatanie);
    $nowy = static function (array $d) use (&$sprzatanie): int {
        $pid = (int) wp_insert_post($d + ['post_status' => 'publish']);
        $sprzatanie[] = static function () use ($pid) { wp_delete_post($pid, true); };
        return $pid;
    };
    $cudzy = $nowy(['post_title' => 'Cudzy wpis', 'post_type' => 'post', 'post_author' => 1]);
    $wlasny = $nowy(['post_title' => 'Własny wpis', 'post_type' => 'post', 'post_author' => $autor]);

    $regeneruj = static function (int $uid, int $pid): array {
        wp_set_current_user($uid);
        $_POST = $_REQUEST = ['action' => 'evk_og_regenerate', 'nonce' => wp_create_nonce('evk_og_regen'), 'post_id' => $pid];
        $r = evk_t_ajax('evk_og_regenerate');
        return ['sukces' => $r['odpowiedz']['success'] ?? null, 'dane' => $r['odpowiedz']['data'] ?? null, 'blad' => $r['blad']];
    };
    $out['autor_cudzy']  = $regeneruj($autor, $cudzy);
    $out['autor_wlasny'] = $regeneruj($autor, $wlasny);
    $out['admin_cudzy']  = $regeneruj(1, $cudzy);
    break;

// ── Eksport ustawień: hasła tylko na życzenie ───────────────────────────────
// `eksport [z]` wypisuje PRAWDZIWY plik z uchwytu (kończy się exit), więc
// każdy wariant to osobny proces. Stan wraca przy zamknięciu.
case 'eksport':
    $smtp_przed = get_option('evk_smtp', null);
    $obejscie_przed = get_option('maintenance_bypass_password', null);
    $sprzatanie[] = static function () use ($smtp_przed, $obejscie_przed) {
        $smtp_przed === null ? delete_option('evk_smtp') : update_option('evk_smtp', $smtp_przed);
        $obejscie_przed === null ? delete_option('maintenance_bypass_password') : update_option('maintenance_bypass_password', $obejscie_przed);
    };
    update_option('evk_smtp', ['enabled' => 1, 'host' => 'smtp.example.com', 'username' => 'nadawca', 'password' => 'test-haslo-smtp']);
    update_option('maintenance_bypass_password', 'test-haslo-obejscia');
    $_POST = $_REQUEST = ['action' => 'tl_export', 'nonce' => wp_create_nonce('tl_ajax_nonce'),
                          'modules' => wp_slash('["evk_smtp","evk_maintenance"]'), 'hasla' => ($argv[2] ?? '') === 'z' ? '1' : ''];
    do_action('wp_ajax_tl_export');
    exit;

// `import-hasla <plik>`: na stronie INNE hasła i inny host; import pliku.
case 'import-hasla':
    $smtp_przed = get_option('evk_smtp', null);
    $obejscie_przed = get_option('maintenance_bypass_password', null);
    $sprzatanie[] = static function () use ($smtp_przed, $obejscie_przed) {
        $smtp_przed === null ? delete_option('evk_smtp') : update_option('evk_smtp', $smtp_przed);
        $obejscie_przed === null ? delete_option('maintenance_bypass_password') : update_option('maintenance_bypass_password', $obejscie_przed);
    };
    update_option('evk_smtp', ['enabled' => 1, 'host' => 'inny.example.com', 'username' => 'ktos', 'password' => 'haslo-na-stronie']);
    update_option('maintenance_bypass_password', 'obejscie-na-stronie');
    $_POST = $_REQUEST = ['action' => 'tl_import', 'nonce' => wp_create_nonce('tl_ajax_nonce'),
                          'json' => wp_slash((string) file_get_contents((string) ($argv[2] ?? ''))), 'decisions' => '{}'];
    $wynik = evk_t_ajax('tl_import');
    $smtp = (array) get_option('evk_smtp', []);
    $out['sukces']   = $wynik['odpowiedz']['success'] ?? null;
    $out['blad']     = $wynik['blad'];
    $out['host']     = $smtp['host'] ?? null;
    $out['haslo']    = $smtp['password'] ?? null;
    $out['obejscie'] = get_option('maintenance_bypass_password');
    break;

// ── Eksport newslettera: subskrybenci tylko na życzenie ─────────────────────
// `eksport-nl [z]` — jak `eksport`: plik z prawdziwego uchwytu, osobny proces.
case 'eksport-nl':
    evk_nl_create_tables();
    $r = wp_rand();
    $lista = (int) evk_nl_create_list('IO lista ' . $r, [['key' => 'imie', 'label' => 'Imię']]);
    evk_nl_add_subscriber($lista, 'io.osoba@example.com', ['imie' => 'Ola', '_consent_ip' => '5.6.7.8']);
    $szablon = (int) evk_nl_create_template(['name' => 'IO szablon ' . $r, 'subject' => 'Temat z pliku', 'body_html' => '<p>Treść</p>']);
    $kampania = (int) evk_nl_create_campaign(['name' => 'IO kampania ' . $r, 'template_id' => $szablon, 'lists' => [$lista]]);
    $sprzatanie[] = static function () use ($lista, $szablon, $kampania) {
        global $wpdb;
        $wpdb->delete(evk_nl_table('campaigns'), ['id' => $kampania]);
        evk_nl_delete_template($szablon);
        evk_nl_delete_list($lista);
    };
    $_POST = $_REQUEST = ['action' => 'tl_export', 'nonce' => wp_create_nonce('tl_ajax_nonce'),
                          'modules' => wp_slash('["evk_newsletter"]'), 'subskrybenci' => ($argv[2] ?? '') === 'z' ? '1' : ''];
    do_action('wp_ajax_tl_export');
    exit;

// `import-nl <plik>`: strona ma WŁASNYCH subskrybentów, listę o nazwie z pliku
// (inna konfiguracja) i szablon o nazwie z pliku (inny temat). Tabele
// newslettera wracają po sondzie z kopii.
case 'import-nl':
    global $wpdb;
    evk_nl_create_tables();
    foreach (['lists', 'subscribers', 'templates', 'campaigns', 'queue', 'logs'] as $t) {
        $tab   = evk_nl_table($t);
        $kopia = $wpdb->prefix . 'evk_t_kopia_' . $t;
        $wpdb->query("DROP TABLE IF EXISTS $kopia");
        $wpdb->query("CREATE TABLE $kopia LIKE $tab");
        $wpdb->query("INSERT INTO $kopia SELECT * FROM $tab");
        $sprzatanie[] = static function () use ($tab, $kopia) {
            global $wpdb;
            $wpdb->query("TRUNCATE TABLE $tab");
            $wpdb->query("INSERT INTO $tab SELECT * FROM $kopia");
            $wpdb->query("DROP TABLE $kopia");
        };
    }
    $opcja_przed = get_option('evk_newsletter', null);
    $sprzatanie[] = static function () use ($opcja_przed) {
        $opcja_przed === null ? delete_option('evk_newsletter') : update_option('evk_newsletter', $opcja_przed);
    };
    $plik = json_decode((string) file_get_contents((string) ($argv[2] ?? '')), true) ?: [];
    $nazwa_listy   = (string) ($plik['evk_nl_lists'][0]['name'] ?? '');
    $nazwa_szablonu = (string) ($plik['evk_nl_templates'][0]['name'] ?? '');

    $moja_lista = (int) evk_nl_create_list('Klienci strony ' . wp_rand());
    evk_nl_add_subscriber($moja_lista, 'na.stronie@example.com');
    $ta_sama = (int) evk_nl_create_list($nazwa_listy, []);
    evk_nl_update_list($ta_sama, ['status' => 0]);
    evk_nl_create_template(['name' => $nazwa_szablonu, 'subject' => 'Temat na stronie']);
    evk_nl_create_campaign(['name' => 'Kampania strony', 'template_id' => 1, 'lists' => [$moja_lista]]);
    $kampanie_przed = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . evk_nl_table('campaigns'));

    $_POST = $_REQUEST = ['action' => 'tl_import', 'nonce' => wp_create_nonce('tl_ajax_nonce'),
                          'json' => wp_slash(wp_json_encode($plik)), 'decisions' => '{}'];
    $wynik = evk_t_ajax('tl_import');
    $out['sukces'] = $wynik['odpowiedz']['success'] ?? null;
    $out['blad']   = $wynik['blad'];

    $moj = $wpdb->get_row($wpdb->prepare('SELECT s.list_id, l.name FROM ' . evk_nl_table('subscribers') . ' s LEFT JOIN '
        . evk_nl_table('lists') . ' l ON l.id = s.list_id WHERE s.email = %s', 'na.stronie@example.com'), ARRAY_A);
    $out['moj_subskrybent'] = $moj ? ['lista_ta_sama' => (int) $moj['list_id'] === $moja_lista, 'nazwa' => $moj['name']] : null;
    $listy = $wpdb->get_results($wpdb->prepare('SELECT id, fields_config, status FROM ' . evk_nl_table('lists') . ' WHERE name = %s', $nazwa_listy), ARRAY_A);
    $out['lista_z_pliku'] = ['ile' => count($listy), 'ten_sam_numer' => (int) ($listy[0]['id'] ?? 0) === $ta_sama,
                             'pola' => json_decode((string) ($listy[0]['fields_config'] ?? ''), true), 'status' => (int) ($listy[0]['status'] ?? -1)];
    $szablony = $wpdb->get_col($wpdb->prepare('SELECT subject FROM ' . evk_nl_table('templates') . ' WHERE name = %s', $nazwa_szablonu));
    $out['szablon_z_pliku'] = $szablony;
    $out['kampanie'] = ['przed' => $kampanie_przed, 'po' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . evk_nl_table('campaigns'))];
    $out['osoba_z_pliku'] = (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . evk_nl_table('subscribers') . ' WHERE email = %s', 'io.osoba@example.com'));
    break;

// ── RODO: eksport i usuwanie danych osoby ───────────────────────────────────
case 'rodo':
    global $wpdb;
    evk_nl_create_tables();
    $przed = [];
    foreach (['evk_smtp_log', 'evk_blocked_ips', 'evk_failed_logins', 'evk_nl_zablokowane', 'evk_newsletter', 'evk_404_enabled'] as $n) $przed[$n] = get_option($n, null);
    $sprzatanie[] = static function () use ($przed) {
        foreach ($przed as $n => $v) { $v === null ? delete_option($n) : update_option($n, $v); }
    };
    $ja = 'rodo@example.com';
    $inna = 'inna-rodo@example.com';
    $lista_a = (int) evk_nl_create_list('RODO A ' . wp_rand());
    $lista_b = (int) evk_nl_create_list('RODO B ' . wp_rand());
    $sprzatanie[] = static function () use ($lista_a, $lista_b) { evk_nl_delete_list($lista_a); evk_nl_delete_list($lista_b); };
    delete_option('evk_nl_zablokowane');

    // Zapis przez formularz (zgoda z IP) + potwierdzenie; druga lista; inna osoba.
    $zgoda = ['_consent_at' => '2026-03-01 10:00:00', '_consent_ip' => '5.6.7.8', '_consent_text' => 'Zgadzam się na newsletter'];
    $p = evk_nl_add_pending_subscriber($lista_a, $ja, $zgoda);
    evk_nl_confirm_subscriber($p['token']);
    evk_nl_add_subscriber($lista_b, $ja);
    evk_nl_add_subscriber($lista_a, $inna);
    $sub = static function (int $lista, string $email) use ($wpdb): int {
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . evk_nl_table('subscribers') . ' WHERE list_id=%d AND email=%s', $lista, $email));
    };
    $moj = $sub($lista_a, $ja);
    $jej = $sub($lista_a, $inna);
    $wpdb->insert(evk_nl_table('campaigns'), ['name' => 'Kampania RODO', 'template_id' => 0, 'lists_json' => '[]', 'status' => 'sent']);
    $kampania = (int) $wpdb->insert_id;
    $sprzatanie[] = static function () use ($wpdb, $kampania) {
        $wpdb->delete(evk_nl_table('campaigns'), ['id' => $kampania]);
        $wpdb->delete(evk_nl_table('queue'), ['campaign_id' => $kampania]);
        $wpdb->delete(evk_nl_table('logs'), ['campaign_id' => $kampania]);
    };
    foreach ([$moj, $jej] as $s) {
        $wpdb->insert(evk_nl_table('queue'), ['campaign_id' => $kampania, 'subscriber_id' => $s, 'status' => 'clicked',
                                              'sent_at' => '2026-03-02 09:00:00', 'opened_at' => '2026-03-02 09:05:00']);
        $wpdb->insert(evk_nl_table('logs'), ['campaign_id' => $kampania, 'event' => 'click', 'subscriber_id' => $s,
                                             'data_json' => wp_json_encode(['url' => 'https://example.com/oferta-rodo'])]);
    }

    // Log SMTP: do mnie, do nas dwojga, tylko do niej.
    update_option('evk_smtp_log', [
        ['time' => '2026-03-01 10:00:00', 'to' => $ja, 'subject' => 'Potwierdź zapis', 'success' => true, 'error' => ''],
        ['time' => '2026-03-01 11:00:00', 'to' => 'Inna <' . $inna . '>, ' . strtoupper($ja), 'subject' => 'Do dwojga', 'success' => true, 'error' => ''],
        ['time' => '2026-03-01 12:00:00', 'to' => $inna, 'subject' => 'Tylko do niej', 'success' => false, 'error' => 'timeout'],
    ]);
    // Blokady logowania: moim loginem, moim adresem, cudzym loginem.
    $uzyt = (int) wp_insert_user(['user_login' => 'rodo_login_' . wp_rand(), 'user_pass' => wp_generate_password(), 'user_email' => $ja]);
    $sprzatanie[] = static function () use ($uzyt) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($uzyt); };
    $login = get_userdata($uzyt)->user_login;
    $teraz = current_time('timestamp');
    update_option('evk_blocked_ips', ['10.0.0.1' => ['blocked_at' => $teraz, 'username' => $login, 'attempts' => 5],
                                      '10.0.0.2' => ['blocked_at' => $teraz, 'username' => strtoupper($ja), 'attempts' => 7],
                                      '10.0.0.3' => ['blocked_at' => $teraz, 'username' => 'ktos_inny', 'attempts' => 5]], false);
    update_option('evk_failed_logins', ['10.0.0.1' => ['count' => 5, 'last' => $teraz], '10.0.0.2' => ['count' => 7, 'last' => $teraz],
                                        '10.0.0.3' => ['count' => 5, 'last' => $teraz]], false);

    // Eksport — jak Narzędzia → Eksport danych osobowych.
    $eksportery = apply_filters('wp_privacy_personal_data_exporters', []);
    $plasko = static function (array $wynik): array {
        $out = [];
        foreach ($wynik['data'] ?? [] as $pozycja) {
            $wiersz = ['grupa' => $pozycja['group_id']];
            foreach ($pozycja['data'] as $pole) $wiersz[$pole['name']] = $pole['value'];
            $out[] = $wiersz;
        }
        return $out;
    };
    foreach (['newsletter', 'smtp', 'logowanie'] as $k) {
        $e = $eksportery['evoke-one-' . $k] ?? null;
        $out['eksport'][$k] = $e ? $plasko(call_user_func($e['callback'], $ja, 1)) : 'NIE ZAREJESTROWANY';
    }

    // Usunięcie — jak Narzędzia → Usuń dane osobowe.
    $usuwacze = apply_filters('wp_privacy_personal_data_erasers', []);
    foreach (['newsletter', 'smtp', 'logowanie'] as $k) {
        $e = $usuwacze['evoke-one-' . $k] ?? null;
        $out['usuniecie'][$k] = $e ? call_user_func($e['callback'], $ja, 1) : 'NIE ZAREJESTROWANY';
    }
    $ile = static function (string $tabela, string $pole, int $id) use ($wpdb): int {
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . evk_nl_table($tabela) . " WHERE $pole=%d", $id));
    };
    $out['po'] = [
        'moje_zapisy'  => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . evk_nl_table('subscribers') . ' WHERE email=%s', $ja)),
        'moje_wysylki' => $ile('queue', 'subscriber_id', $moj),
        'moje_zdarzenia' => $ile('logs', 'subscriber_id', $moj),
        'jej_zapis'    => $sub($lista_a, $inna) === $jej,
        'jej_wysylki'  => $ile('queue', 'subscriber_id', $jej),
        'jej_zdarzenia'=> $ile('logs', 'subscriber_id', $jej),
        'smtp'         => array_map(static function ($w) { return $w['subject'] . ' → ' . $w['to']; }, (array) get_option('evk_smtp_log', [])),
        'blokady'      => array_keys((array) get_option('evk_blocked_ips', [])),
        'proby'        => array_keys((array) get_option('evk_failed_logins', [])),
        'skrot_moj'    => evk_nl_zablokowany($ja),
        'skrot_jej'    => evk_nl_zablokowany($inna),
        'adres_w_opcji'=> strpos((string) wp_json_encode(get_option('evk_nl_zablokowane')), 'rodo') !== false,
    ];

    // Import po usunięciu: mój adres pominięty, nowy dodany.
    $out['import'] = evk_nl_import_emails($lista_a, [$ja, 'nowa-po-rodo@example.com']);
    $out['import_moj_zapis'] = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . evk_nl_table('subscribers') . ' WHERE email=%s', $ja));
    // Zapis samej osoby: sam formularz (niepotwierdzony) blokady nie zdejmuje, potwierdzenie tak.
    $p2 = evk_nl_add_pending_subscriber($lista_a, $ja, $zgoda);
    $out['po_formularzu_zablokowany'] = evk_nl_zablokowany($ja);
    evk_nl_confirm_subscriber($p2['token']);
    $out['po_potwierdzeniu_zablokowany'] = evk_nl_zablokowany($ja);

    // Tekst do polityki prywatności — tylko o działających modułach.
    update_option('evk_newsletter', array_merge((array) $przed['evk_newsletter'], ['enabled' => 1]));
    update_option('evk_404_enabled', 1);
    $tekst = evk_rodo_tekst_polityki();
    $out['polityka'] = ['newsletter' => strpos($tekst, '<h3>Newsletter</h3>') !== false, 'skrot' => strpos($tekst, 'skrót') !== false,
                        'logi404' => strpos($tekst, 'Nieistniejące strony') !== false];
    update_option('evk_newsletter', array_merge((array) $przed['evk_newsletter'], ['enabled' => 0]));
    $out['polityka_bez_newslettera'] = strpos(evk_rodo_tekst_polityki(), '<h3>Newsletter</h3>') === false;
    break;

default:
    $out['blad'] = 'nieznany scenariusz';
}

echo wp_json_encode($out);
