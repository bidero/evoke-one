<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Zrzut PRAWDZIWEJ tablicy presetów Animatora do JSON-a.
 *
 * Test buduje z niego stronę, więc sprawdza to, co realnie jedzie na front —
 * a nie kopię listy przepisaną do fixture'a, która zaczęłaby żyć własnym życiem.
 */
require __DIR__ . '/_wp-stubs.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';

echo json_encode([
    'presets' => evk_anim_presets(),
    'easings' => evk_anim_easings(),
], JSON_UNESCAPED_UNICODE), "\n";
