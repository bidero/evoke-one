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

/* Rejestr elementów Bricksa — przegląd sekcji liczy z niego przełączniki wiersza
   „Elementy Bricks", dokładnie tak jak robi to `evk_toggle_allowlist()`. Bez
   rejestru wiersz pokazałby „0 z 0" i sprawdzenie licznika nie mierzyłoby nic. */
require EVK_TEST_ROOT . '/includes/bricks-elements/loader.php';

/* Kolejność jak w evoke-one.php:133-134 — `page.php` woła
   `evoke_one_zakladki()` i `evoke_one_ekrany()` z helpers. */
require EVK_TEST_ROOT . '/includes/admin/helpers.php';
require EVK_TEST_ROOT . '/includes/admin/page.php';

/* Zakładka inna niż pulpit wciąga plik swojej treści, a ten potrzebuje całego
 * modułu, który obsługuje. Tutaj chodzi WYŁĄCZNIE o powłokę — sidebar, nagłówek
 * i paletę — więc `EVOKE_ONE_DIR` wskazuje katalog z pustymi plikami zakładek.
 * Treść zakładek ma własne pokrycie w tests/php/tab.php i nie ma powodu
 * powtarzać go tutaj drugi raz.
 *
 * Stała musi stać PRZED `--mapa`: rejestr elementów Bricksa buduje z niej
 * ścieżki, a mapa przełączników czyta z rejestru listę elementów. */
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

/* Struktura panelu na żądanie — `--mapa` zamiast renderu.
   Test porównuje to, co widać na ekranie, z tym, co deklaruje wtyczka; obie
   rzeczy muszą pochodzić z niej samej, nie z listy przepisanej w teście. */
if (($argv[1] ?? '') === '--mapa') {
    /* `przelaczniki` rozwinięte przez wtyczkę, nie surowa mapa: wiersz „Elementy
       Bricks" deklaruje w niej rejestr, a test ma zobaczyć te same pary, które
       trafiają na ekran. */
    $przelaczniki = [];
    foreach (evoke_one_ekrany() as $zakladka => $ekrany) {
        foreach (array_keys($ekrany) as $sub) {
            $przelaczniki[$zakladka][$sub] = evoke_one_przelaczniki($zakladka, $sub);
        }
    }

    echo json_encode([
        'zakladki'     => evoke_one_zakladki(),
        'ekrany'       => evoke_one_ekrany(),
        'przeglad'     => evoke_one_sekcje_z_przegladem(),
        'przelaczniki' => $przelaczniki,
        'wyjatki'      => evoke_one_przelacznik_tylko_na_przegladzie(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── Zasiew ────────────────────────────────────────────────────────────────
//
// Trzy kształty, wszystkie jednoznaczne:
//
//   {"evk_animator": 1}                 moduł z flagą `enabled`
//   {"evk_rewizje": {"limit_on": 1}}    opcja tablicowa o innym polu
//   {"maintenance_mode": 1}             opcja PŁASKA (skalar w bazie)
//
// Tablicę zapisujemy DOSŁOWNIE. Wcześniej stały tu wyliczone z ręki wyjątki
// (`evk_cleanup`, `evk_security`, `evk_elements`), a wszystko inne szło przez
// `['enabled' => (int) $wartosc]` — czyli opcja o polu innym niż `enabled`
// cicho zamieniała się w `enabled` i zasiew mówił co innego niż test prosił.
//
// Lista opcji płaskich powstaje Z MAPY EKRANÓW: każda para z polem `_scalar`
// jest z definicji płaska. Przepisana z ręki rozjeżdżałaby się przy pierwszym
// dołożonym przełączniku.
$plaskie = ['evk_301_enabled', 'evk_404_enabled', 'maintenance_mode',
    'evk_tl_module_enabled', 'evk_tl_fab_enabled'];

foreach (evoke_one_ekrany() as $zakladka => $ekrany) {
    foreach (array_keys($ekrany) as $sub) {
        foreach (evoke_one_przelaczniki($zakladka, $sub) as [$opcja, $pole]) {
            if ($pole === '_scalar') $plaskie[] = $opcja;
        }
    }
}
$plaskie = array_unique($plaskie);

$zasiew = json_decode($argv[1] ?? '{}', true) ?: [];
foreach ($zasiew as $klucz => $wartosc) {
    if ($klucz === 'ssl')  { $GLOBALS['ssl'] = (bool) $wartosc; continue; }
    if (is_array($wartosc)) { $GLOBALS['options'][$klucz] = $wartosc; continue; }
    if (in_array($klucz, $plaskie, true)) { $GLOBALS['options'][$klucz] = $wartosc; continue; }
    $GLOBALS['options'][$klucz] = ['enabled' => (int) $wartosc];
}

$_GET['tab'] = $argv[2] ?? 'dashboard';
/* Trzeci argument to `?sub=`. Bez niego nie da się pokazać, że sekcja
   z przeglądem prowadzi starym adresem dalej na ekran modułu — a to jest
   dokładnie ta zmiana, która mogłaby po cichu popsuć zapisane odsyłacze. */
if (($argv[3] ?? '') !== '') $_GET['sub'] = $argv[3];
$GLOBALS['caps']['manage_options'] = true;

evoke_one_render_settings();
