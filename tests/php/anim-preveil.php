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
$GLOBALS['options']['evk_animator'] = [
    'enabled'    => 1,
    'animations' => [[
        'slug'    => 'wjazd',
        'preset'  => 'split-lines',
        'trigger' => 'viewport',
    ]],
];

/* Wspólne wykrywanie buildera — moduły frontowe pytają o nie przez
   `evk_w_builderze()`. Plik jest liściem: potrzebuje tylko `is_admin()`
   z atrap i niczego więcej. */
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';

require EVK_TEST_ROOT . '/includes/anim/animator.php';

echo evk_test_fire('wp_head');
