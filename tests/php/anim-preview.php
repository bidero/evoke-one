<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Dane dla podglądu animacji: tablica presetów i wynik parsera „opacity: 0".
 *
 * Presety bierzemy z PRAWDZIWEGO evk_anim_presets() — przepisanie ich do
 * fixture'a sprawdzałoby naszą kopię, a nie tablicę wtyczki.
 *
 * Parser jest w dwóch miejscach: PHP (evk_anim_parse_props) obsługuje zapis,
 * JS (evkAnimatorParseProps) — podgląd. Dwie implementacje tego samego formatu
 * to dwa miejsca do rozjechania się, więc test porównuje je na tych samych
 * danych. Ten plik dostarcza połowę PHP-ową.
 */
require __DIR__ . '/_wp-stubs.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';

$cases = json_decode($argv[1] ?? '[]', true) ?: [];

echo json_encode([
    'presets' => evk_anim_presets(),
    'parsed'  => array_map(function ($t) { return evk_anim_parse_props((string) $t); }, $cases),
], JSON_UNESCAPED_UNICODE);
