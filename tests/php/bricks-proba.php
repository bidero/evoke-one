<?php
namespace Bricks {
    // Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd
    // aktualizatorem na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

    /** Rejestr elementów — zapamiętuje, co loader zarejestrował. */
    if (!class_exists('Bricks\\Elements')) {
        class Elements {
            /** @var array<string,mixed> */
            public static $elements = [];
            /** @var array<int,array<string,string>> */
            public static $zarejestrowane = [];
            public static function register_element($file, $name = '', $class = '') {
                self::$zarejestrowane[] = ['plik' => basename((string) $file), 'nazwa' => (string) $name, 'klasa' => (string) $class];
            }
        }
    }
}

namespace {
/**
 * Element próbny przełączników (1.243.0) — PRAWDZIWY loader.php i prawdziwy
 * plik elementu na atrapach.
 *
 *   php tests/php/bricks-proba.php bez     bez stałej
 *   php tests/php/bricks-proba.php false   define('EVK_BRICKS_PROBA', false)
 *   php tests/php/bricks-proba.php napis   define('EVK_BRICKS_PROBA', '1') — ma być ściśle true
 *   php tests/php/bricks-proba.php tak     define('EVK_BRICKS_PROBA', true) + kontrolki i render
 *
 * Stała żyje do końca procesu, więc każdy wariant to osobne wywołanie.
 */
require __DIR__ . '/_wp-stubs.php';
require __DIR__ . '/_bricks-stubs.php';

$tryb = $argv[1] ?? 'bez';
if ($tryb === 'false') define('EVK_BRICKS_PROBA', false);
if ($tryb === 'napis') define('EVK_BRICKS_PROBA', '1');
if ($tryb === 'tak')   define('EVK_BRICKS_PROBA', true);

if (!defined('EVOKE_ONE_DIR'))     define('EVOKE_ONE_DIR', EVK_TEST_ROOT . '/');
if (!defined('EVOKE_ONE_URL'))     define('EVOKE_ONE_URL', 'https://example.test/wp-content/plugins/evoke-one/');
if (!defined('EVOKE_ONE_VERSION')) define('EVOKE_ONE_VERSION', 'test');
require EVK_TEST_ROOT . '/includes/bricks-elements/loader.php';

foreach ($GLOBALS['hooks']['init'] ?? [] as $cb) $cb();

$wynik = ['zarejestrowane' => \Bricks\Elements::$zarejestrowane];

if ($tryb === 'tak' && class_exists('Evk_Proba_Przelacznikow_Element')) {
    $el = new Evk_Proba_Przelacznikow_Element();
    $el->set_controls();
    $wynik['kontrolki'] = array_map(static function ($d) {
        return array_intersect_key($d, array_flip(['type', 'default', 'required']));
    }, $el->controls);
    $render = static function (array $ust): string {
        $e = new Evk_Proba_Przelacznikow_Element();
        $e->settings = $ust;
        ob_start();
        $e->render();
        return (string) ob_get_clean();
    };
    // Nowy element: tak zapisałby się, gdyby Bricks wpisał domyślne (JSON Stacking Cards).
    $wynik['nowy'] = $render(['proba_znacznik' => 2, 'proba_jawny' => 2, 'proba_wlacz' => true, 'proba_nowy' => true]);
    // „Stary" element: wklejony bez żadnego klucza.
    $wynik['stary'] = $render([]);
    // Odznaczony i wyczyszczony: null i pusty napis muszą dać się odróżnić od braku klucza.
    $wynik['odznaczony'] = $render(['proba_znacznik' => 2, 'proba_wlacz' => null, 'proba_tekst' => '']);
}

echo json_encode($wynik, JSON_UNESCAPED_UNICODE);
}
