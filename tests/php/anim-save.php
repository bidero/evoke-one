<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Zapis całej biblioteki animacji przez AJAX.
 *
 * Sedno: wynik ma być IDENTYCZNY z tym, co zapisałby zwykły formularz przez
 * options.php — obie drogi idą przez tę samą sanityzację. Gdyby endpoint miał
 * własne reguły, różnica wyszłaby dopiero na żywej stronie.
 *
 * Argument: JSON z ładunkiem formularza (zawartość klucza evk_animator).
 */
require __DIR__ . '/_wp-stubs.php';

// evk_preserve_toggle() definiuje sam 30-admin-settings-ajax.php — bierzemy
// prawdziwą, bo to ona decyduje o losie przełącznika przy zapisie z formularza.
function tl_get_active_lang_codes() { return ['pl']; }
function bricks_is_builder_main() { return false; }

require EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';
require EVK_TEST_ROOT . '/includes/anim/animator.php';

$input = json_decode($argv[1] ?? '{}', true) ?: [];

// Stan wyjściowy: moduł WŁĄCZONY. Formularz nie niesie tego pola (steruje nim
// osobny przełącznik AJAX), więc zapis nie może go po drodze zgasić.
$GLOBALS['options']['evk_animator'] = ['enabled' => 1, 'animations' => []];

$_POST = ['evk_animator' => $input];

$cb = null;
foreach ($GLOBALS['hooks']['wp_ajax_evk_anim_save'] ?? [] as $c) { $cb = $c; }

$res = null;
try { $cb(); }
catch (EVK_Test_Json $e) { $res = $e->payload; }

$saved = $GLOBALS['options']['evk_animator'];

// Ta sama sanityzacja wywołana wprost — droga formularza przez options.php.
$direct = EVK_Animator::get_instance()->sanitize_settings($input);

echo json_encode([
    'response' => $res,
    'saved'    => $saved,
    'same'     => $saved == $direct,
], JSON_UNESCAPED_UNICODE), "\n";
