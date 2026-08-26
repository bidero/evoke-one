<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * CSS i skrypt modułu Dark mode — PRAWDZIWE, prosto z `93-darkmode.php`.
 *
 * Atrapa przeglądarkowa nie może mieć kopii tej fali: gdyby miała, mierzyłaby
 * kopię, a nie to, co jedzie na stronę. Stąd ten plik — drukuje `wp_head`
 * i `wp_footer` modułu, a fixture wkleja je u siebie.
 *
 * Argument 1: JSON z nadpisaniami ustawień (np. rozmycie fali, czas trwania).
 */
require __DIR__ . '/_wp-stubs.php';

function evk_preserve_toggle($input, $key, $field = 'enabled', $default = 0) {
    return isset($input[$field]) ? (int) !empty($input[$field]) : $default;
}

/* Wspólne wykrywanie buildera — moduły frontowe pytają o nie przez
   `evk_w_builderze()`. */
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';

$GLOBALS['options']['evk_darkmode'] = array_merge(
    ['enabled' => 1, 'ripple_enabled' => 1],
    json_decode($argv[1] ?? '{}', true) ?: []
);

require EVK_TEST_ROOT . '/includes/93-darkmode.php';

echo evk_test_fire('wp_head');
echo evk_test_fire('wp_footer');
