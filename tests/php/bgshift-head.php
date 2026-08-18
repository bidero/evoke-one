<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/** Drukuje CSS modułu Tło przy scrollu. */
require __DIR__ . '/_wp-stubs.php';
function evk_preserve_toggle($input, $key, $field = 'enabled', $default = 0) {
    return isset($input[$field]) ? (int) !empty($input[$field]) : $default;
}
function evk_register_gsap_libs() {}
/* Zasięg koloru liter z argumentu — bez tego dało się zmierzyć tylko jedną
   z dwóch stron ustawienia, a to ta druga jest odpowiedzią na zgłoszenie
   „zmiana koloru tekstu nie działa". */
$GLOBALS['options']['evk_bgshift'] = ['enabled' => 1]
    + (isset($argv[1]) ? ['text_scope' => $argv[1]] : []);
require EVK_TEST_ROOT . '/includes/anim/bgshift.php';
echo evk_test_fire('wp_head');
