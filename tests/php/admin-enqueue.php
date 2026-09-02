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

// Ładujemy PRAWDZIWY moduł GSAP, a nie atrapę jego funkcji: panel woła
// evk_register_gsap_libs() z ręki (rejestracja wisi na 'wp_enqueue_scripts',
// czyli tylko na froncie) i test ma pilnować, że ta droga naprawdę działa.
require EVK_TEST_ROOT . '/includes/89-gsap.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
// Warstwy OG: panel przekazuje ich liczbę i etykiety typów do admin.js.
// Moduł ładujemy PRAWDZIWY, bo o to właśnie chodzi — że lista typów jest
// jedna dla PHP i dla przeglądarki.
require EVK_TEST_ROOT . '/includes/opengraph/settings.php';
/* Kolejność jak w evoke-one.php:133-134 — `page.php` woła
   `evoke_one_zakladki()` i `evoke_one_ekrany()` z helpers. */
require EVK_TEST_ROOT . '/includes/admin/helpers.php';
require EVK_TEST_ROOT . '/includes/admin/page.php';

// evoke_one_get_io_modules() definiuje sam page.php — nie podstawiamy atrapy.

// Callback jest zarejestrowany na admin_enqueue_scripts i sam sprawdza $hook.
foreach ($GLOBALS['hooks']['admin_enqueue_scripts'] ?? [] as $cb) {
    $cb('settings_page_evoke-one');
}

$admin = $GLOBALS['enqueued']['evoke-one-admin'] ?? null;

// Podgląd w bibliotece stoi na TYM SAMYM silniku, co strona. Sprawdzamy więc
// nie tylko, że skrypt jest, ale i że dostaje GSAP oraz wtyczki tekstowe —
// bez nich presety dzielące tekst i piszące cicho nie działają.
$anim_js = $GLOBALS['enqueued']['evk-animator'] ?? null;
// UWAGA: nazwa zmiennej nie może brzmieć $inline — plik działa w zasięgu
// globalnym, więc $inline TO JEST $GLOBALS['inline'] i przypisanie skasowałoby
// tablicę, po której zaraz iterujemy.
$anim_inline = '';
foreach ($GLOBALS['inline'] as $i) {
    if ($i['handle'] === 'evk-animator') $anim_inline = $i['data'];
}
$cfg = null;
if (preg_match('/window\.evkAnimator = (.*);$/s', trim($anim_inline), $m)) {
    $cfg = json_decode($m[1], true);
}

echo json_encode([
    'deps'    => $admin['deps'] ?? null,
    'handles' => array_keys($GLOBALS['enqueued']),
    // Panel bez adresu i bez nonce'a nie zapisze kolejności, choćby sortowanie
    // działało — to druga połowa tego samego połączenia.
    'anim'    => array_keys($GLOBALS['localized']['evoOneAnimData'] ?? []),

    // Warstwy OG. Bez `types` dodana warstwa dostaje nazwę „qr" zamiast
    // „Kod QR", bez `layerCount` wchodzi pod indeks już zajęty i nadpisuje
    // istniejącą przy zapisie — obie usterki są ciche.
    'og'      => array_keys($GLOBALS['localized']['evoOgData'] ?? []),
    // CAŁY ładunek, nie tylko klucze — test w przeglądarce wstrzykuje go
    // do fixture'a zamiast trzymać własną kopię listy typów. Kopia zaczęłaby
    // żyć własnym życiem i „warstwa ma czytelną nazwę typu" przestałoby
    // sprawdzać cokolwiek.
    'ogData'  => $GLOBALS['localized']['evoOgData'] ?? null,
    'ogTypes' => array_keys(($GLOBALS['localized']['evoOgData']['types'] ?? [])),
    'ogCount' => $GLOBALS['localized']['evoOgData']['layerCount'] ?? null,
    // Ile warstw naprawdę ma domyślna konfiguracja — indeks nowej warstwy
    // musi ruszać właśnie stąd.
    'ogReal'  => count(evk_og_get_settings()['layers'] ?? []),

    'engine'      => $anim_js ? $anim_js['deps'] : null,
    'engineSrc'   => $anim_js['src'] ?? null,
    'registered'  => array_keys($GLOBALS['registered']),
    // Presety muszą dojechać do panelu — inaczej podgląd nie ma czego odegrać.
    'presetCount' => is_array($cfg['presets'] ?? null) ? count($cfg['presets']) : 0,
    'presetKeys'  => array_slice(array_keys($cfg['presets'] ?? []), 0, 3),
    // Pusta biblioteka: silnik nie ma czego szukać w panelu i start() kończy od razu.
    'library'     => $cfg['library'] ?? 'BRAK',
], JSON_UNESCAPED_UNICODE), "\n";
