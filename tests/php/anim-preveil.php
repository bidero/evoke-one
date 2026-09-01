<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Zasłona przeciw błyskowi treści — to, co Animator drukuje w <head>.
 *
 * Fixtures miały tę regułę PRZEPISANĄ u siebie, a kopia zaczyna żyć własnym
 * życiem: sprawdzenie „element czekający jest niewidoczny" przechodziłoby
 * nawet wtedy, gdyby wtyczka przestała drukować regułę w ogóle. Bierzemy ją
 * stąd, czyli z jedynego miejsca, które naprawdę trafia na stronę.
 */
require __DIR__ . '/_wp-stubs.php';

define('EVOKE_ONE_URL',     'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', 'test');

function evk_preserve_toggle($input, $key, $field = 'enabled', $default = 0) {
    return isset($input[$field]) ? (int) !empty($input[$field]) : $default;
}
function esc_textarea($s) { return $s; }
function bricks_is_builder_main() { return false; }

/* Opcja PRZED wczytaniem pliku: moduł powołuje instancję sam, na require,
   a konstruktor przy wyłączonym module w ogóle nie podpina się pod `wp_head`.
   Zasłona wychodzi tylko przy włączonym module Z JAKĄKOLWIEK animacją — pusta
   biblioteka nie ma czego chować. */
$wiersze = [[
    'slug'    => 'wjazd',
    'preset'  => 'split-lines',
    'trigger' => 'viewport',
]];

/* Argument 1: JSON z wierszami biblioteki — do sprawdzenia, KTÓRE z nich
   trafiają do zasłony. Bez niego jeden wiersz wejściowy, jak dotąd. */
if (isset($argv[1]) && $argv[1] !== '') {
    $podane = json_decode($argv[1], true);
    if (is_array($podane) && $podane) $wiersze = $podane;
}

$GLOBALS['options']['evk_animator'] = [
    'enabled'    => 1,
    'animations' => $wiersze,
];

/* Wspólne wykrywanie buildera — moduły frontowe pytają o nie przez
   `evk_w_builderze()`. Plik jest liściem: potrzebuje tylko `is_admin()`
   z atrap i niczego więcej. */
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';

/* Polityka ruchu — zasłona pyta ją o to, czy szanujemy `prefers-reduced-motion`
   (przy redukcji nie zakłada się w ogóle). Bez tego pliku byłby fatal. */
require_once EVK_TEST_ROOT . '/includes/anim/motion.php';

require EVK_TEST_ROOT . '/includes/89-gsap.php';
require EVK_TEST_ROOT . '/includes/anim/animator.php';

/* Kolejka skryptów PRZED nagłówkiem — dokładnie tak jak w WordPressie, gdzie
   `wp_head` woła `wp_enqueue_scripts` na priorytecie 1. Preload czyta adresy
   z tej kolejki, więc bez niej nie miałby czego wypisać. Atrapa `add_action`
   ignoruje priorytety i odpala w kolejności rejestracji — a ta zgadza się tu
   z priorytetami, bo GSAP wczytujemy przed Animatorem. */
evk_test_fire('wp_enqueue_scripts');

echo evk_test_fire('wp_head');

/* Kolejka skryptów w formie do odczytania przez test.
 *
 * Preload obiecuje przeglądarce adres; `<script src>` po niego idzie. Jeśli
 * różnią się choćby o `?ver=`, plik jedzie DWA RAZY. Test porównuje jedno
 * z drugim, więc musi widzieć obie strony. */
echo "\n<!-- evk-test-skrypty " . json_encode([
    'enqueued'   => $GLOBALS['enqueued']   ?? [],
    'registered' => $GLOBALS['registered'] ?? [],
]) . " -->\n";
