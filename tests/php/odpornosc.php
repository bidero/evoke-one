<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Trzy miejsca, w których wtyczka dawała się przewrócić albo obejść.
 *
 * 1. IMPORT CSV przyjmował plik bez ŻADNEGO sprawdzenia poza „czy `tmp_name`
 *    niepuste": ani kodu błędu z PHP, ani rozmiaru, ani typu. Zawartość szła
 *    prosto do `file_get_contents()`, stamtąd `preg_split()` robił tablicę
 *    linii, a import kolejną tablicę adresów — każdy krok to osobna kopia
 *    w pamięci.
 *
 * 2. LIMITER LOGOWANIA trzymał próby i blokady w opcjach indeksowanych adresem
 *    IP, nieprzycinanych i AUTOLOADOWANYCH — czyli wczytywanych przy każdym
 *    żądaniu do serwisu. Kto ma pulę IPv6, rozdmuchiwał je do megabajtów.
 *
 * 3. TYP WPISU SNIPPETÓW miał `capability_type => 'post'`, więc prawo do edycji
 *    kodu wykonywanego przez `eval()` mapowało się na `edit_others_posts`.
 */
require __DIR__ . '/_wp-stubs.php';

if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);

$GLOBALS['czas'] = 1800000000;
function current_time($type = 'mysql') {
    return $type === 'timestamp' ? $GLOBALS['czas'] : '2027-01-15 12:00:00';
}
function do_action($hook, ...$args) {}
function wp_login_url() { return 'https://example.test/wp-login.php'; }
function wp_safe_redirect($url, $status = 302) { return true; }
function wp_kses($s, $allowed) { return $s; }
function wp_strip_all_tags($s) { return strip_tags((string) $s); }
function is_user_logged_in() { return false; }
function sanitize_email_default($s) { return $s; }

/* Rejestr typów wpisów — sprawdzamy, z jakimi uprawnieniami snippety wchodzą
   do WordPressa, a nie co o nich napisano w komentarzu. */
$GLOBALS['typy'] = [];
function register_post_type($slug, $args = []) { $GLOBALS['typy'][$slug] = $args; }

require_once EVK_TEST_ROOT . '/includes/security/settings.php';
require_once EVK_TEST_ROOT . '/includes/security/login-limit.php';
require_once EVK_TEST_ROOT . '/includes/newsletter/ajax.php';

/* Snippety: rejestracja typu siedzi pod `init`, a plik silnika potrzebuje
   swoich stałych z definitions.php. */
require_once EVK_TEST_ROOT . '/includes/snippets/definitions.php';
require_once EVK_TEST_ROOT . '/includes/snippets/engine.php';
foreach ($GLOBALS['hooks']['init'] ?? [] as $cb) { $cb(); }

$out = [];

// =========================================================================
// 1. IMPORT CSV
// =========================================================================

/** Kładzie treść w pliku i podaje ją walidatorowi jak wgranie z formularza. */
function plik(string $tresc, string $nazwa = 'lista.csv', int $blad = UPLOAD_ERR_OK, ?int $rozmiar = null): array {
    $tmp = tempnam(sys_get_temp_dir(), 'csv');
    file_put_contents($tmp, $tresc);
    return [
        'name'     => $nazwa,
        'tmp_name' => $tmp,
        'error'    => $blad,
        'size'     => $rozmiar ?? strlen($tresc),
    ];
}

/* `is_uploaded_file()` jest funkcją wbudowaną i w teście z wiersza poleceń
   zawsze oddaje `false`, bo żaden plik nie przyszedł przez POST. Sprawdzenia
   metadanych stoją PRZED nią, więc dają się przetestować; kontrola zawartości
   stoi za nią i dlatego siedzi w osobnej funkcji, wołanej tu wprost. Ta jedna
   linijka z `is_uploaded_file()` zostaje poza zasięgiem testu — i tak jest
   napisane, zamiast udawać, że jej nie ma. */
$out['csv'] = [
    'brak_pliku'       => evk_nl_sprawdz_csv(null),
    'blad_rozmiaru'    => evk_nl_sprawdz_csv(plik('a@b.pl', 'lista.csv', UPLOAD_ERR_INI_SIZE)),
    'blad_czesciowy'   => evk_nl_sprawdz_csv(plik('a@b.pl', 'lista.csv', UPLOAD_ERR_PARTIAL)),
    'nic_nie_wybrano'  => evk_nl_sprawdz_csv(plik('', 'lista.csv', UPLOAD_ERR_NO_FILE)),
    'pusty'            => evk_nl_sprawdz_csv(plik('', 'lista.csv', UPLOAD_ERR_OK, 0)),
    'za_duzy'          => evk_nl_sprawdz_csv(plik('a@b.pl', 'lista.csv', UPLOAD_ERR_OK, EVK_NL_CSV_MAX + 1)),
    'zle_rozszerzenie' => evk_nl_sprawdz_csv(plik('a@b.pl', 'lista.php')),
    // Poprawny plik przechodzi WSZYSTKIE sprawdzenia metadanych i zatrzymuje
    // się dopiero na `is_uploaded_file()` — czyli tam, gdzie w CLI zatrzymać
    // się musi. Inny komunikat znaczyłby, że odrzuciło go coś wcześniej.
    'poprawny_do_uploadu'=> evk_nl_sprawdz_csv(plik("a@b.pl\nc@d.pl", 'lista.csv')),
    'limit_bajtow'     => EVK_NL_CSV_MAX,
];

// Kontrola zawartości — wołana wprost, bo w handlerze stoi za `is_uploaded_file()`.
$png = plik("\x89PNG\r\n\x1a\n\0\0\0", 'lista.csv');
$txt = plik("adres@example.test\nkto@example.test\n", 'lista.csv');
$out['csv']['obrazek_to_nie_tekst'] = evk_nl_wyglada_na_tekst($png['tmp_name']);
$out['csv']['csv_to_tekst']         = evk_nl_wyglada_na_tekst($txt['tmp_name']);

// =========================================================================
// 2. LIMITER LOGOWANIA
// =========================================================================

$GLOBALS['options']['evk_security'] = ['limit_login_enabled' => 1, 'max_attempts' => 5, 'reset_hours' => 24];

// Tysiąc adresów, jak z puli IPv6.
$duzo = [];
for ($i = 0; $i < 1000; $i++) {
    $duzo['2001:db8::' . dechex($i)] = ['count' => 1, 'last' => $GLOBALS['czas'] - $i];
}
// Plus dwieście wpisów sprzed okna resetu — te mają wylecieć niezależnie od limitu.
for ($i = 0; $i < 200; $i++) {
    $duzo['10.0.0.' . $i] = ['count' => 1, 'last' => $GLOBALS['czas'] - (48 * HOUR_IN_SECONDS)];
}

$przyciete = evk_login_przytnij($duzo, 'last');
$out['limiter'] = [
    'przed'          => count($duzo),
    'po'             => count($przyciete),
    'sufit'          => EVK_LOGIN_MAX_WPISOW,
    'stare_wylecialy'=> !isset($przyciete['10.0.0.0']),
    'najswiezszy_zostal' => isset($przyciete['2001:db8::0']),
];

// Zapis przez prawdziwą ścieżkę: nieudane logowanie.
$GLOBALS['options']['evk_failed_logins'] = $duzo;
$GLOBALS['autoload'] = [];
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
foreach ($GLOBALS['hooks']['wp_login_failed'] ?? [] as $cb) { $cb('admin'); }

$out['limiter']['po_zapisie']       = count($GLOBALS['options']['evk_failed_logins']);
$out['limiter']['autoload_prob']    = $GLOBALS['autoload']['evk_failed_logins'] ?? 'nie ustawiono';
$out['limiter']['nowy_ip_zapisany'] = isset($GLOBALS['options']['evk_failed_logins']['198.51.100.7']);

// Blokada — druga z dwóch opcji.
$GLOBALS['options']['evk_blocked_ips'] = [];
$GLOBALS['autoload'] = [];
evk_login_block_ip('198.51.100.9', 'admin');
$out['limiter']['autoload_blokad'] = $GLOBALS['autoload']['evk_blocked_ips'] ?? 'nie ustawiono';

// =========================================================================
// 3. TYP WPISU SNIPPETÓW
// =========================================================================

$typ = $GLOBALS['typy']['evk_code_snippet'] ?? [];
$out['snippety'] = [
    'zarejestrowany'  => !empty($typ),
    'capability_type' => $typ['capability_type'] ?? null,
    'map_meta_cap'    => $typ['map_meta_cap'] ?? null,
    'uprawnienia'     => $typ['capabilities'] ?? [],
    'wszystkie_admin' => !empty($typ['capabilities'])
        && count(array_unique($typ['capabilities'])) === 1
        && reset($typ['capabilities']) === 'manage_options',
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
