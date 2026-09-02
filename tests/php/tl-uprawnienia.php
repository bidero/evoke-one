<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Kto może wołać punkty AJAX Tłumaczeń — i czego NIE MOŻE.
 *
 * Do 1.126.0 uprawnienia dla `tl_*` dokładał hook w Role Managerze: na
 * `admin_init` doklejał `manage_options` każdemu z `evk_access_translations`,
 * jeśli tylko nazwa akcji w żądaniu zaczynała się od `tl_`. Ponieważ
 * `admin-ajax.php` też odpala `admin_init`, wystarczyło zawołać `tl_import` —
 * punkt zapisujący `evk_snippets_advanced_content`, czyli PHP wykonywany
 * potem przez `eval()`. Rola do tłumaczenia fraz dawała wykonanie dowolnego
 * kodu na serwerze.
 *
 * Ten plik sprawdza trzy rzeczy, każdą osobno, bo każda może się zepsuć sama:
 *
 * 1. **Hooka nie ma.** Nie „nie działa" — nie istnieje. Sprawdzamy to
 *    licznikiem wywołań `add_cap()` na atrapie użytkownika: gdyby ktoś
 *    przywrócił doklejanie w jakiejkolwiek postaci, licznik podskoczy.
 * 2. **Bramka wpuszcza tłumacza do zapisów tłumaczeń** — bo inaczej naprawa
 *    byłaby po prostu odebraniem roli jej pracy.
 * 3. **Import nie wpuszcza tłumacza do modułów spoza `tl_*`** — snippety PHP,
 *    hasło SMTP, hasło obejścia konserwacji, ustawienia bezpieczeństwa.
 *    Sprawdzamy przez PRAWDZIWY handler i prawdziwy ładunek, nie przez pytanie
 *    helpera o zdanie.
 */
require __DIR__ . '/_wp-stubs.php';

// ── Atrapy, których potrzebują ładowane moduły ────────────────────────────
function tl_get_active_lang_codes() { return ['pl', 'en']; }
function bricks_is_builder_main() { return false; }
function wp_rand($min = 0, $max = 0) { return $min; }
function wp_slash($v) { return $v; }
function current_time($type = 'mysql') { return '2026-01-01 00:00:00'; }
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function wp_create_nonce($action = -1) { return 'testnonce'; }
function tl_invalidate_cache() {}
function tl_flush_rewrite_rules() {}

/**
 * Użytkownik, który ZAPAMIĘTUJE każdą próbę nadania uprawnienia.
 *
 * To jest cały czujnik do punktu 1: hook eskalacji wołał `add_cap()`, więc
 * jego powrót w dowolnej postaci zostawi tu ślad. Sprawdzanie „czy w pliku
 * nie ma słowa add_cap" byłoby sprawdzaniem tekstu, nie zachowania.
 */
class EVK_Test_User {
    public $ID = 7;
    public $roles = ['tlumacz'];
    public $nadane = [];
    private $caps;
    public function __construct(array $caps) { $this->caps = $caps; }
    public function has_cap($cap) { return !empty($this->caps[$cap]); }
    public function add_cap($cap, $grant = true) { $this->nadane[] = $cap; }
    public function remove_cap($cap) {}
}
$GLOBALS['user'] = new EVK_Test_User(['evk_access_translations' => true]);
function wp_get_current_user() { return $GLOBALS['user']; }

/** Rola zapamiętująca, co jej nadano i co zabrano. */
class EVK_Test_Role {
    public $capabilities = ['read' => true];
    public function has_cap($cap) { return !empty($this->capabilities[$cap]); }
    public function add_cap($cap, $grant = true) { $this->capabilities[$cap] = (bool) $grant; }
    public function remove_cap($cap) { unset($this->capabilities[$cap]); }
}
class WP_Roles { public $role_objects = []; }
$GLOBALS['role'] = new EVK_Test_Role();
$GLOBALS['role_admin'] = new EVK_Test_Role();
function get_role($slug) {
    return $slug === 'administrator' ? $GLOBALS['role_admin'] : $GLOBALS['role'];
}
function get_editable_roles() { return ['redaktor' => ['name' => 'Redaktor']]; }
function wp_verify_nonce($nonce, $action = -1) { return $nonce === 'testnonce' ? 1 : false; }
function add_settings_error($a, $b, $c, $d = 'error') {}

/* Baza — potrzebna tylko po to, żeby zablokowana gałąź newslettera padła
   WARTOŚCIĄ, a nie błędem krytycznym. Test ma pokazywać, co się nie zapisało,
   nie wywalać się na braku $wpdb. */
class EVK_Test_Wpdb {
    public $prefix = 'wp_';
    public $zapytania = [];
    public function get_results($q, $mode = null) { $this->zapytania[] = $q; return []; }
    public function query($q) { $this->zapytania[] = $q; return true; }
    public function insert($t, $d) { $this->zapytania[] = 'INSERT ' . $t; return true; }
}
$GLOBALS['wpdb'] = new EVK_Test_Wpdb();

require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';
require_once EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';
require_once EVK_TEST_ROOT . '/includes/admin/page.php';   // evoke_one_get_io_modules()
require_once EVK_TEST_ROOT . '/includes/admin/role-manager-logic.php';

$out = [];

// ── 1. Hook eskalacji nie istnieje ────────────────────────────────────────
// Odpalamy WSZYSTKIE callbacki `admin_init` z żądaniem, które kiedyś
// wystarczało: użytkownik z samym `evk_access_translations` woła `tl_import`.
$GLOBALS['caps'] = ['evk_access_translations' => true];
$_POST = ['action' => 'tl_import'];
$_GET  = [];
foreach ($GLOBALS['hooks']['admin_init'] ?? [] as $cb) { $cb(); }
$out['nadane_uprawnienia'] = $GLOBALS['user']->nadane;

// ── 2. Bramka punktów AJAX ────────────────────────────────────────────────
/** Woła handler i mówi, czy przeszedł: null = przeszedł, tablica = odmowa. */
function zawolaj(string $akcja, array $post) {
    $handler = null;
    foreach ($GLOBALS['hooks']['wp_ajax_' . $akcja] ?? [] as $cb) { $handler = $cb; }
    if (!$handler) return ['brak_handlera' => $akcja];
    $_POST = $post;
    try { $handler(); } catch (EVK_Test_Json $e) { return $e->payload; }
    catch (EVK_Test_Die $e) { return ['wp_die' => true]; }
    return null;
}

$bramka = [];
$akcje  = ['tl_save_translations', 'tl_save_images', 'tl_save_settings', 'tl_save_dd_keys',
           'tl_save_slugs', 'tl_save_sitemap_settings', 'tl_inline_get', 'tl_inline_save_full'];
foreach (['tlumacz'    => ['evk_access_translations' => true],
          'admin'      => ['manage_options' => true],
          'redaktor'   => ['edit_posts' => true]] as $kto => $caps) {
    $GLOBALS['caps'] = $caps;
    foreach ($akcje as $akcja) {
        // Pusty ładunek: interesuje nas WYŁĄCZNIE, czy bramka przepuściła.
        // „Nieprawidlowy JSON" znaczy, że handler doszedł do swojej roboty.
        $res = zawolaj($akcja, ['nonce' => 'testnonce']);
        $odmowa = is_array($res) && ($res['data'] ?? null) === 'Brak uprawnien.';
        $bramka[$kto][$akcja] = $odmowa ? 'odmowa' : 'wpuszczony';
    }
}
$out['bramka'] = $bramka;

// Nazwy nonce'ów muszą zostać takie, jakie drukują strony — inaczej każdy
// zapis pada w produkcji, a test świeci na zielono, bo atrapa nonce'a
// niczego nie weryfikuje.
$GLOBALS['caps'] = ['manage_options' => true];
zawolaj('tl_save_translations', ['nonce' => 'testnonce']);
$out['nonce_zapisu'] = $GLOBALS['nonce_asked'];
zawolaj('tl_inline_save_full', ['nonce' => 'testnonce']);
$out['nonce_inline'] = $GLOBALS['nonce_asked'];

// ── 3. Import: co wolno wgrać komu ────────────────────────────────────────
// Ładunek niesie i tłumaczenia, i wszystko, czego tłumacz tknąć nie może.
$paczka = [
    'tl_translations' => ['groups' => ['g1' => ['name' => 'Grupa', 'rows' => [
        'r1' => ['pl' => 'Witaj', 'en' => 'Hello', 'dd_key' => 'witaj'],
    ]]]],
    'tl_dd_keys'                    => ['klucz' => 'Witaj'],
    'evk_snippets_advanced_content' => '<?php file_put_contents("shell.php", $_GET["c"]);',
    'evk_snippets_enabled'          => 1,
    'evk_snippets_advanced_enabled' => 1,
    'evk_smtp'                      => ['password' => 'tajne-haslo-smtp'],
    'evk_security'                  => ['limit_login_enabled' => 0],
    'maintenance_bypass_password'   => 'nowe-haslo',
    'evk_white_label'               => ['custom_css_admin' => '</style><script>alert(1)</script>'],
    'evk_newsletter'                => ['enabled' => 1],
    'evk_nl_subscribers'            => [['id' => 1, 'email' => 'kto@example.test']],
];

function importuj(array $caps, array $paczka): array {
    $GLOBALS['caps']   = $caps;
    $GLOBALS['options'] = [];
    zawolaj('tl_import', ['nonce' => 'testnonce', 'json' => json_encode($paczka)]);
    return $GLOBALS['options'];
}

$po_tlumaczu = importuj(['evk_access_translations' => true], $paczka);
$out['tlumacz_import'] = [
    'tl_translations'  => isset($po_tlumaczu['tl_translations']),
    'tl_dd_keys'       => isset($po_tlumaczu['tl_dd_keys']),
    'snippet_php'      => $po_tlumaczu['evk_snippets_advanced_content'] ?? null,
    'snippety_wlaczone'=> $po_tlumaczu['evk_snippets_enabled'] ?? null,
    'smtp'             => $po_tlumaczu['evk_smtp'] ?? null,
    'bezpieczenstwo'   => $po_tlumaczu['evk_security'] ?? null,
    'haslo_konserwacji'=> $po_tlumaczu['maintenance_bypass_password'] ?? null,
    'white_label'      => $po_tlumaczu['evk_white_label'] ?? null,
    'newsletter'       => $po_tlumaczu['evk_newsletter'] ?? null,
    'zapytania_do_bazy'=> count($GLOBALS['wpdb']->zapytania),
];

// Administrator ma dalej importować WSZYSTKO — naprawa uprawnień nie może
// po cichu obciąć funkcji, z której korzysta panel Import/Eksport.
$po_adminie = importuj(['manage_options' => true], $paczka);
$out['admin_import'] = [
    'tl_translations'  => isset($po_adminie['tl_translations']),
    'snippet_php'      => isset($po_adminie['evk_snippets_advanced_content']),
    'smtp'             => isset($po_adminie['evk_smtp']),
    'haslo_konserwacji'=> isset($po_adminie['maintenance_bypass_password']),
    'newsletter'       => isset($po_adminie['evk_newsletter']),
];

// Ktoś bez żadnego z dwóch uprawnień nie ma tu czego szukać.
$GLOBALS['caps'] = ['edit_posts' => true];
$out['obcy_import'] = zawolaj('tl_import', ['nonce' => 'testnonce', 'json' => json_encode($paczka)]);
$out['obcy_eksport'] = zawolaj('tl_export', ['nonce' => 'testnonce']);

// ── 4. Ograniczenie modułów ───────────────────────────────────────────────
$GLOBALS['caps'] = ['manage_options' => true];
$out['limit_admin'] = evk_io_ograniczenie_modulow();
$GLOBALS['caps'] = ['evk_access_translations' => true];
$out['limit_tlumacz'] = evk_io_ograniczenie_modulow();
$GLOBALS['caps'] = ['edit_posts' => true];
$out['limit_obcy'] = evk_io_ograniczenie_modulow();
$out['moduly_io'] = array_keys(evoke_one_get_io_modules());

// ── 5. Dostępy do modułów w Role Managerze ────────────────────────────────
// Evoke FIELDS dołącza do czwórki, którą Role Manager obsługiwał do 1.134.0.
// FIELDS jest OSOBNĄ WTYCZKĄ — Evoke ONE potrafi tylko nadać uprawnienie
// i pokazać je w panelu; sprawdzić je musi sam FIELDS. Test pilnuje więc tej
// połowy, którą mamy: że uprawnienie da się nadać, odebrać i że dostaje je
// administrator.
$GLOBALS['caps'] = ['manage_evk_roles' => true];

/** Zapisuje rolę przez PRAWDZIWY handler formularza Role Managera. */
function zapisz_role(array $post): array {
    $GLOBALS['role'] = new EVK_Test_Role();
    $_POST = array_merge([
        'evk_role_action' => 'edit_role',
        'evk_role_nonce'  => 'testnonce',
        'role_id'         => 'redaktor',
    ], $post);
    foreach ($GLOBALS['hooks']['admin_init'] ?? [] as $cb) { $cb(); }
    return $GLOBALS['role']->capabilities;
}

$z_dostepem = zapisz_role(['evk_fields_access' => '1', 'evk_tl_access' => '1']);
$bez_dostepu = zapisz_role([]);

$out['role_manager'] = [
    'fields_nadane'   => !empty($z_dostepem['evk_access_fields']),
    'tlumaczenia_obok'=> !empty($z_dostepem['evk_access_translations']),
    'fields_odebrane' => !isset($bez_dostepu['evk_access_fields']),
];

// Administrator dostaje komplet dostępów sam z siebie, przy `init`.
$GLOBALS['role_admin'] = new EVK_Test_Role();
foreach ($GLOBALS['hooks']['init'] ?? [] as $cb) { $cb(); }
$out['role_manager']['admin_dostaje'] = array_values(array_filter(array_keys(
    $GLOBALS['role_admin']->capabilities
), fn($c) => strpos($c, 'evk_access_') === 0));

// Filtr `user_has_cap` przepisuje administratorowi każdy z dostępów — bez tego
// admin nie widziałby własnych modułów, dopóki nie przeładuje sesji.
$filtr = $GLOBALS['hooks']['user_has_cap'][0] ?? null;
$out['role_manager']['filtr_dla_admina'] = is_callable($filtr)
    ? !empty($filtr(['manage_options' => true], ['evk_access_fields'], [])['evk_access_fields'])
    : null;

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
