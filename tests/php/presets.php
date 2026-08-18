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

/* Odpowiedzi helperów rodzinowych, liczone w PHP dla KAŻDEGO presetu.
   Test porównuje je ze znacznikami w tablicy — bez tego helper mógłby zwracać
   prawdę dla wszystkiego i nikt by nie zauważył, bo panel i tak by grupował. */
$rodziny = [];
foreach (array_keys(evk_anim_presets()) as $slug) {
    $rodziny[$slug] = [
        'exit'  => evk_anim_preset_is_exit($slug),
        'stan'  => evk_anim_preset_is_state($slug),
    ];
}

echo json_encode([
    'presets' => evk_anim_presets(),
    'rodziny' => $rodziny,
    'easings' => evk_anim_easings(),
    'breakpoints' => evk_anim_breakpoints(),
    // Ta sama lista w zapisie CSS-a. Elementy animowane przejściami CSS
    // (menu offcanvas) dostają krzywą tą samą kontrolką, a CSS nazw GSAP-a
    // nie zna — nieznana funkcja czasu unieważnia CAŁĄ deklarację
    // `transition`, razem z czasem trwania.
    'easingsCss' => array_combine(evk_anim_easings(),
                                  array_map('evk_anim_easing_css', evk_anim_easings())),
], JSON_UNESCAPED_UNICODE), "\n";
