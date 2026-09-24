<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Zapis danych na PRAWDZIWYM WordPressie (tools/testowy-wp.sh).
 *
 * PO CO OSOBNO. Atrapy w tests/php nie zdejmują ukośników w wp_insert_post()
 * i nie mapują uprawnień — a dokładnie tam siedziały błędy znalezione w audycie
 * 1.229.6: snippety i wersje robocze gubiły `\`, ograniczenia ról nie
 * blokowały niczego, import ustawień przy wyłączonych Tłumaczeniach kończył
 * się błędem krytycznym, eksport snippetów gubił ich rodzaj. Każdy z tych
 * scenariuszy odtwarza drogę, którą idzie panel, i oddaje JSON do oceny.
 *
 *   php tests/php/zapis-wp.php <scenariusz>
 *
 * Scenariusze: snippety, wersje, import, eksport, role.
 * Każdy sprząta po sobie (także przy wyjątku).
 */

require __DIR__ . '/_testowy-wp.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';

$scenariusz = $argv[1] ?? '';
$out = ['scenariusz' => $scenariusz];
$sprzatanie = [];
register_shutdown_function(static function () use (&$sprzatanie) {
    foreach (array_reverse($sprzatanie) as $f) { try { $f(); } catch (Throwable $e) {} }
});

/** Kod z ukośnikami w każdej postaci, która w praktyce psuła się przy zapisie. */
const EVK_T_KOD = "echo \"Linia 1\\nLinia 2\"; \$ok = preg_match('/^\\d{2}-\\d{3}\$/', \$kod); \$css = '.x:before{content:\"\\f101\"}'; \$sciezka = 'C:\\\\Users\\\\x';";

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

wp_set_current_user(1);

switch ($scenariusz) {

// ── Snippety: zapis z edytora i wykrywanie zjedzonych ukośników ─────────────
case 'snippety':
    $id = evk_snippet_zapisz_wpis(['tytul' => 'Test ukośników', 'kod' => EVK_T_KOD, 'rodzaj' => 'php', 'miejsce' => 'head', 'wlaczony' => 0]);
    $sprzatanie[] = static function () use ($id) { wp_delete_post($id, true); };
    $out['po_zapisie']   = get_post($id)->post_content;
    evk_snippet_zapisz_wpis(['id' => $id, 'tytul' => 'Test ukośników', 'kod' => get_post($id)->post_content, 'rodzaj' => 'php', 'miejsce' => 'head', 'wlaczony' => 0]);
    clean_post_cache($id);
    $out['po_drugim']    = get_post($id)->post_content;
    $out['wzor']         = EVK_T_KOD;
    $out['znacznik']     = (string) get_post_meta($id, EVK_SNIPPET_META_UKOSNIKI_OK, true);
    $wpis = static function (int $pid): array {
        foreach (evk_snippety_wszystkie() as $w) if ($w['id'] === $pid) return $w;
        return [];
    };
    $out['podejrzenie_po_zapisie'] = evk_snippet_podejrzenie_ukosnikow($wpis($id));

    /* Wpisy „sprzed naprawy": bez znacznika, zapisane tak, jak zapisywał je
       stary kod (kod już pozbawiony ukośników). */
    $stary = static function (string $kod, string $rodzaj) use (&$sprzatanie): int {
        $pid = (int) wp_insert_post(wp_slash(['post_title' => 'stary', 'post_content' => $kod, 'post_status' => 'private', 'post_type' => 'evk_code_snippet']));
        update_post_meta($pid, EVK_SNIPPET_META_RODZAJ, $rodzaj);
        update_post_meta($pid, EVK_SNIPPET_META_WLACZ, 0);
        $sprzatanie[] = static function () use ($pid) { wp_delete_post($pid, true); };
        return $pid;
    };
    $out['podejrzenie_regex'] = evk_snippet_podejrzenie_ukosnikow($wpis($stary("\$ok = preg_match('/^d{2}-d{3}\$/', \$x);", 'php')));
    $out['podejrzenie_js']    = evk_snippet_podejrzenie_ukosnikow($wpis($stary("if (/^s+\$/.test(v)) {}", 'js')));
    $out['podejrzenie_css']   = evk_snippet_podejrzenie_ukosnikow($wpis($stary('.ikona:before { content: "f101"; }', 'css')));
    $out['podejrzenie_czysty'] = evk_snippet_podejrzenie_ukosnikow($wpis($stary("\$ok = preg_match('/^\\d+\$/', \$x); echo 'add+';", 'php')));

    // Historia zmian: starsza wersja ma więcej ukośników niż bieżąca.
    /* Rewizja powstaje przy AKTUALIZACJI i trzyma nową treść — dlatego dwa
       zapisy: pierwszy jeszcze z ukośnikiem (zostaje w historii), drugi już
       bez, jak robił to stary edytor. */
    $h = $stary("\$a = 1;", 'php');
    wp_update_post(wp_slash(['ID' => $h, 'post_content' => "\$a = \"x\\ny\";"]));
    wp_update_post(wp_slash(['ID' => $h, 'post_content' => "\$a = \"xny\"; // v2"]));
    $out['podejrzenie_historia'] = evk_snippet_podejrzenie_ukosnikow($wpis($h));
    break;

// ── Wersje robocze: kopia i synchronizacja z oryginałem ─────────────────────
case 'wersje':
    update_option('evk_draft_revision_enabled', '1');
    $tresc_bricks = [['id' => 'abc', 'name' => 'code', 'settings' => ['code' => EVK_T_KOD, 'css' => '.x:before{content:"\\f101"}']]];
    $id = (int) wp_insert_post(wp_slash(['post_title' => 'Oryginał', 'post_content' => 'Ścieżka C:\\Users\\x', 'post_status' => 'publish', 'post_type' => 'page']));
    update_post_meta($id, '_bricks_page_content_2', wp_slash($tresc_bricks));
    $sprzatanie[] = static function () use ($id) { wp_delete_post($id, true); };
    $out['wzor_meta']   = $tresc_bricks[0]['settings'];
    $out['wzor_tresc']  = get_post($id)->post_content;

    add_filter('wp_redirect', static function ($l) { throw new RuntimeException($l); });
    $_GET = $_REQUEST = ['action' => 'evk_create_revision', 'post' => $id, '_wpnonce' => wp_create_nonce('evk_create_revision_' . $id)];
    $kopia = 0;
    try { do_action('admin_action_evk_create_revision'); } catch (RuntimeException $e) {
        parse_str((string) parse_url($e->getMessage(), PHP_URL_QUERY), $q); $kopia = (int) ($q['post'] ?? 0);
    }
    $sprzatanie[] = static function () use ($kopia) { if ($kopia) wp_delete_post($kopia, true); };
    $out['kopia_meta']  = get_post_meta($kopia, '_bricks_page_content_2', true)[0]['settings'] ?? null;
    $out['kopia_tresc'] = $kopia ? get_post($kopia)->post_content : null;

    $_GET = $_REQUEST = ['action' => 'evk_sync_revision', 'post' => $kopia, '_wpnonce' => wp_create_nonce('evk_sync_' . $kopia)];
    try { do_action('admin_action_evk_sync_revision'); } catch (RuntimeException $e) {}
    clean_post_cache($id);
    wp_cache_delete($id, 'post_meta');
    $out['oryginal_meta']  = get_post_meta($id, '_bricks_page_content_2', true)[0]['settings'] ?? null;
    $out['oryginal_tresc'] = get_post($id)->post_content;
    break;

// ── Import ustawień przy wyłączonych Tłumaczeniach ──────────────────────────
case 'import':
    $out['tl_wlaczone']   = (bool) get_option('evk_tl_module_enabled', 0);
    $out['tl_zaladowane'] = function_exists('tl_get_active_lang_codes');
    $stare = [];
    foreach (['evk_darkmode', 'tl_translations', 'evk_snippets_advanced_content'] as $o) $stare[$o] = get_option($o, null);
    $sprzatanie[] = static function () use ($stare) {
        foreach ($stare as $o => $v) { $v === null ? delete_option($o) : update_option($o, $v); }
    };
    do_action('admin_init');   // register_setting → sanityzacja jak w prawdziwym żądaniu

    $paczka = [
        '_evoke_one_export' => true,
        'evk_darkmode'      => ['enabled' => 0, 'logo_duration' => 2],
        'tl_translations'   => ['groups' => ['g1' => ['name' => 'Menu', 'rows' => ['r1' => ['pl' => 'Kontakt', 'en' => 'Contact', 'de' => 'Kontakt DE']]]]],
        'evk_snippets_posts' => [
            ['slug' => 'evk-t-php', 'title' => 'Z innej strony', 'content' => EVK_T_KOD, 'rodzaj' => 'php', 'miejsce' => 'footer', 'grupa' => 'Import', 'wlaczony' => '1', 'kolejnosc' => 7],
            ['slug' => 'evk-t-stary', 'title' => 'Stary eksport', 'content' => "add_filter('x', '__return_true');"],
        ],
        'evk_snippets_advanced_content' => "<?php\n" . EVK_T_KOD,
    ];
    $sprzatanie[] = static function () {
        foreach (['evk-t-php', 'evk-t-stary'] as $slug) { $pid = evk_snippet_get_id($slug); if ($pid) wp_delete_post($pid, true); }
    };
    $_POST = $_REQUEST = ['action' => 'tl_import', 'nonce' => wp_create_nonce('tl_ajax_nonce'),
                          'json' => wp_slash(wp_json_encode($paczka)), 'decisions' => '{}'];
    $wynik = evk_t_ajax('tl_import');
    $out['blad']       = $wynik['blad'];
    $out['odpowiedz']  = $wynik['odpowiedz'];
    $out['darkmode']   = get_option('evk_darkmode');
    $out['frazy']      = get_option('tl_translations');
    $out['advanced']   = evk_snippets_advanced_get();
    $out['wzor']       = EVK_T_KOD;
    $out['snippety']   = [];
    foreach (evk_snippety_wszystkie() as $w) {
        if (in_array($w['slug'], ['evk-t-php', 'evk-t-stary'], true)) $out['snippety'][$w['slug']] = $w;
    }
    $out['wyjscie_starego'] = isset($out['snippety']['evk-t-stary']) && $out['snippety']['evk-t-stary']['wlaczony']
        ? evk_snippet_wykonaj_wpis($out['snippety']['evk-t-stary']) : '';
    break;

// ── Eksport snippetów: czy niesie rodzaj, miejsce, grupę, włącznik ──────────
case 'eksport':
    $id = evk_snippet_zapisz_wpis(['tytul' => 'Eksportowany', 'kod' => 'body{}', 'rodzaj' => 'css', 'miejsce' => 'footer', 'grupa' => 'G', 'wlaczony' => 1, 'kolejnosc' => 3]);
    $sprzatanie[] = static function () use ($id) { wp_delete_post($id, true); };
    $_POST = $_REQUEST = ['action' => 'tl_export', 'nonce' => wp_create_nonce('tl_ajax_nonce'), 'modules' => wp_slash('["evk_snippets"]')];
    // Handler wypisuje plik i kończy exit — to, co wypisze, JEST wynikiem scenariusza.
    do_action('wp_ajax_tl_export');
    exit;

// ── Role: ograniczenie edycji stron ─────────────────────────────────────────
case 'role':
    $stare = evk_role_get_restrictions();
    $sprzatanie[] = static function () use ($stare) { update_option(EVK_ROLE_RESTRICTIONS_OPTION, $stare); };
    $sprzatanie[] = static function () { remove_role('evk_t_klient'); remove_role('evk_t_wolny'); };
    add_role('evk_t_klient', 'Klient test', get_role('editor')->capabilities);
    add_role('evk_t_wolny',  'Wolny test',  get_role('editor')->capabilities);
    $nowy = static function (array $d) use (&$sprzatanie): int {
        $pid = (int) wp_insert_post($d + ['post_status' => 'publish', 'post_author' => 1]);
        $sprzatanie[] = static function () use ($pid) { wp_delete_post($pid, true); };
        return $pid;
    };
    $a   = $nowy(['post_title' => 'Strona A', 'post_type' => 'page']);
    $b   = $nowy(['post_title' => 'Strona B', 'post_type' => 'page']);
    $wp_ = $nowy(['post_title' => 'Wpis', 'post_type' => 'post']);
    /* Szablony Bricksa (1.231.2). Bricksa tu nie ma, więc typ wpisu
       rejestrujemy sami — niepubliczny, z uprawnieniami jak wpisy. */
    $bez_bricksa = !post_type_exists('bricks_template');
    if ($bez_bricksa) register_post_type('bricks_template', ['public' => false, 'show_ui' => true, 'capability_type' => 'post', 'map_meta_cap' => true]);
    $szablon_t = $nowy(['post_title' => 'Nagłówek testowy', 'post_type' => 'bricks_template']);
    update_post_meta($szablon_t, '_bricks_template_type', 'header');
    $szablon_u = $nowy(['post_title' => 'Stopka testowa', 'post_type' => 'bricks_template']);
    update_post_meta($szablon_u, '_bricks_template_type', 'footer');
    $zal = (int) wp_insert_attachment(['post_title' => 'Obraz', 'post_mime_type' => 'image/png', 'post_status' => 'inherit', 'post_author' => 1]);
    $sprzatanie[] = static function () use ($zal) { wp_delete_attachment($zal, true); };
    $uzyt = static function (string $rola) use (&$sprzatanie): int {
        $uid = (int) wp_insert_user(['user_login' => 'evk_t_' . $rola . '_' . wp_rand(), 'user_pass' => wp_generate_password(), 'role' => $rola]);
        $sprzatanie[] = static function () use ($uid) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user($uid); };
        return $uid;
    };
    $klient = $uzyt('evk_t_klient');
    $wolny  = $uzyt('evk_t_wolny');
    $admin2 = $uzyt('administrator');
    update_option(EVK_ROLE_RESTRICTIONS_OPTION, ['evk_t_klient' => [$a, $szablon_t]]);
    delete_option(EVK_ROLE_POWIADOMIENIE_OPCJA);

    $moze = static fn(int $uid, string $cap, int $pid): bool => user_can($uid, $cap, $pid);
    $out['klient'] = [
        'edit_A' => $moze($klient, 'edit_post', $a),   'delete_A' => $moze($klient, 'delete_post', $a),
        'edit_B' => $moze($klient, 'edit_post', $b),   'delete_B' => $moze($klient, 'delete_post', $b),
        'edit_page_B' => $moze($klient, 'edit_page', $b), 'publish_B' => $moze($klient, 'publish_post', $b),
        'edit_wpis' => $moze($klient, 'edit_post', $wp_), 'edit_zalacznik' => $moze($klient, 'edit_post', $zal),
        'edit_pages' => user_can($klient, 'edit_pages'),
        'edit_szablon_zaznaczony' => $moze($klient, 'edit_post', $szablon_t),
        'edit_szablon_inny'       => $moze($klient, 'edit_post', $szablon_u),
    ];

    // Lista w panelu Role Managera: szablony obok stron, zaznaczenie z zapisu.
    require_once ABSPATH . 'wp-admin/includes/user.php';
    $ekran = static function (): string {
        $_GET = ['role_action' => 'edit', 'edit_role' => 'evk_t_klient'];
        ob_start();
        include EVOKE_ONE_DIR . 'includes/admin/admin-roles.php';
        return (string) ob_get_clean();
    };
    $zaznaczony = static fn(string $html, int $id): bool => (bool) preg_match('/value="' . $id . '"\s+checked=/', $html);
    wp_set_current_user(1);
    $html = $ekran();
    $out['lista'] = [
        'naglowek'         => strpos($html, 'data-evk-role-szablony') !== false,
        'zaznaczony'       => $zaznaczony($html, $szablon_t),
        'inny_jest'        => strpos($html, 'value="' . $szablon_u . '"') !== false,
        'inny_zaznaczony'  => $zaznaczony($html, $szablon_u),
        'typ'              => strpos($html, '(nagłówek)') !== false,
        'strona_zaznaczona'=> $zaznaczony($html, $a),
    ];
    if ($bez_bricksa) {
        unregister_post_type('bricks_template');
        $out['lista_bez_bricksa'] = strpos($ekran(), 'data-evk-role-szablony') === false;
    }
    $out['wolny'] = ['edit_B' => $moze($wolny, 'edit_post', $b), 'edit_wpis' => $moze($wolny, 'edit_post', $wp_)];
    $out['admin'] = ['edit_B' => $moze($admin2, 'edit_post', $b)];
    // Dwie role naraz: klient + druga rola z inną listą → suma list.
    add_role('evk_t_drugi', 'Drugi test', get_role('editor')->capabilities);
    $sprzatanie[] = static function () { remove_role('evk_t_drugi'); };
    update_option(EVK_ROLE_RESTRICTIONS_OPTION, ['evk_t_klient' => [$a], 'evk_t_drugi' => [$b]]);
    (new WP_User($klient))->add_role('evk_t_drugi');
    $out['dwie_role'] = ['edit_A' => $moze($klient, 'edit_post', $a), 'edit_B' => $moze($klient, 'edit_post', $b), 'edit_wpis' => $moze($klient, 'edit_post', $wp_)];

    // Powiadomienie administratora: jest przy zapisanych ograniczeniach…
    wp_set_current_user(1);
    ob_start(); do_action('admin_notices'); $n = (string) ob_get_clean();
    $out['powiadomienie'] = strpos($n, 'od tej wersji działają') !== false;
    // …a bez ograniczeń zamyka się samo.
    update_option(EVK_ROLE_RESTRICTIONS_OPTION, []);
    delete_option(EVK_ROLE_POWIADOMIENIE_OPCJA);
    ob_start(); do_action('admin_notices'); $n2 = (string) ob_get_clean();
    $out['powiadomienie_bez_ograniczen'] = strpos($n2, 'od tej wersji działają') !== false;
    $out['zamkniete_samo'] = (bool) get_option(EVK_ROLE_POWIADOMIENIE_OPCJA);
    delete_option(EVK_ROLE_POWIADOMIENIE_OPCJA);
    break;

default:
    $out['blad'] = 'nieznany scenariusz';
}

echo wp_json_encode($out);
