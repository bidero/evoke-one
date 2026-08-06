<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Co panel wtyczki realnie zgłasza do WordPressa.
 *
 * Sedno: skrypt panelu MUSI deklarować `jquery-ui-sortable` jako ZALEŻNOŚĆ.
 * Zgłoszenie biblioteki osobnym enqueue nie wystarcza — WordPress drukuje
 * skrypty w kolejności zgłoszeń, więc admin.js ląduje wtedy przed biblioteką
 * i `$.fn.sortable` jeszcze nie istnieje w chwili jego uruchomienia.
 * Tak wyglądała usterka 1.37.0: przeciąganie nie działało, bez śladu w konsoli.
 */
require __DIR__ . '/_wp-stubs.php';

define('EVOKE_ONE_URL',     'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', 'test');

// ── Atrapy tego, czego dotyka callback enqueue ────────────────────────────
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }
function wp_create_nonce($a = -1) { return 'nonce-' . $a; }
function wp_enqueue_style(...$a) {}
function wp_enqueue_media(...$a) {}

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

require EVK_TEST_ROOT . '/includes/admin/page.php';

// evoke_one_get_io_modules() definiuje sam page.php — nie podstawiamy atrapy.

// Callback jest zarejestrowany na admin_enqueue_scripts i sam sprawdza $hook.
foreach ($GLOBALS['hooks']['admin_enqueue_scripts'] ?? [] as $cb) {
    $cb('settings_page_evoke-one');
}

$admin = $GLOBALS['enqueued']['evoke-one-admin'] ?? null;

echo json_encode([
    'deps'    => $admin['deps'] ?? null,
    'handles' => array_keys($GLOBALS['enqueued']),
    // Panel bez adresu i bez nonce'a nie zapisze kolejności, choćby sortowanie
    // działało — to druga połowa tego samego połączenia.
    'anim'    => array_keys($GLOBALS['localized']['evoOneAnimData'] ?? []),
], JSON_UNESCAPED_UNICODE), "\n";
