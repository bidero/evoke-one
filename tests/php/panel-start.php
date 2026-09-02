<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Ekran startowy panelu — renderowany PRAWDZIWĄ funkcją, nie kopią znaczników.
 *
 * `evoke_one_render_settings()` jest jedynym miejscem, które zna komplet
 * zakładek, liczy wynik gotowości i buduje paletę wyszukiwania. Kopia tego
 * znacznika w fixturze zaczęłaby żyć własnym życiem: sprawdzenie „licznik
 * modułów mówi prawdę" przechodziłoby na zielono także wtedy, gdyby panel
 * czytał nieistniejącą opcję.
 *
 * Konfigurację zasiewamy z wiersza poleceń, bo o to właśnie chodzi — o to,
 * czy liczby na ekranie odpowiadają temu, co siedzi w bazie:
 *
 *   php tests/php/panel-start.php '{"evk_animator":1,"evk_smtp":1}' [zakładka]
 */
require __DIR__ . '/_wp-stubs.php';

define('EVOKE_ONE_URL',     'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', 'test');
define('BRICKS_VERSION',    '1.12');

function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce($a = -1) { return 'nonce-' . $a; }
function wp_enqueue_style(...$a) {}
function wp_enqueue_media(...$a) {}
function is_ssl() { return !empty($GLOBALS['ssl']); }

class EVK_Animator {
    private static $i = null;
    public static function get_instance() { return self::$i ?: (self::$i = new self()); }
    public function get_settings() { return ['enabled' => 1, 'animations' => []]; }
}
class EVK_Cursor {
    private static $i = null;
    public static function get_instance() { return self::$i ?: (self::$i = new self()); }
    public function get_settings() { return ['elements' => []]; }
}

require EVK_TEST_ROOT . '/includes/89-gsap.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
require EVK_TEST_ROOT . '/includes/opengraph/settings.php';

/* PRAWDZIWE moduły, z których pulpit czyta stan. Atrapa tych funkcji
   przepuściłaby literówkę w nazwie opcji — czyli dokładnie tę klasę błędu,
   dla której ten plik powstał. */
require EVK_TEST_ROOT . '/includes/security/settings.php';
require EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';

require EVK_TEST_ROOT . '/includes/admin/page.php';

// ── Zasiew ────────────────────────────────────────────────────────────────
// Klucz → wartość. Moduły z flagą `enabled` podajemy jako 1/0, opcje płaskie
// tak samo; plik sam wie, które są tablicami.
$plaskie = ['evk_301_enabled', 'evk_404_enabled', 'maintenance_mode'];

$zasiew = json_decode($argv[1] ?? '{}', true) ?: [];
foreach ($zasiew as $klucz => $wartosc) {
    if ($klucz === 'ssl')  { $GLOBALS['ssl'] = (bool) $wartosc; continue; }
    if (in_array($klucz, $plaskie, true)) { $GLOBALS['options'][$klucz] = $wartosc; continue; }
    if ($klucz === 'evk_cleanup')  { $GLOBALS['options'][$klucz] = $wartosc; continue; }
    if ($klucz === 'evk_security') { $GLOBALS['options'][$klucz] = $wartosc; continue; }
    $GLOBALS['options'][$klucz] = ['enabled' => (int) $wartosc];
}

$_GET['tab'] = $argv[2] ?? 'dashboard';
$GLOBALS['caps']['manage_options'] = true;

/* Zakładka inna niż pulpit wciąga plik swojej treści, a ten potrzebuje całego
 * modułu, który obsługuje. Tutaj chodzi WYŁĄCZNIE o powłokę — sidebar, nagłówek
 * i paletę — więc `EVOKE_ONE_DIR` wskazuje katalog z pustymi plikami zakładek.
 * Treść zakładek ma własne pokrycie w tests/php/tab.php i nie ma powodu
 * powtarzać go tutaj drugi raz. */
$katalog = sys_get_temp_dir() . '/evk-panel-start-' . getmypid();
@mkdir($katalog . '/includes/admin', 0700, true);
foreach (['wydajnosc', 'strona', 'bezpieczenstwo', 'narzedzia', 'admin', 'newsletter', 'forminbox'] as $nazwa) {
    file_put_contents($katalog . '/includes/admin/tab-' . $nazwa . '.php', '<?php // pusta treść zakładki');
}
define('EVOKE_ONE_DIR', $katalog . '/');
register_shutdown_function(static function () use ($katalog) {
    foreach (glob($katalog . '/includes/admin/*.php') ?: [] as $plik) unlink($plik);
    @rmdir($katalog . '/includes/admin'); @rmdir($katalog . '/includes'); @rmdir($katalog);
});

evoke_one_render_settings();
