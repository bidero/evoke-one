<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Animator: presety
 *
 * Preset to nazwana para from/to plus rozsądne domyślne czasy. Silnik JS
 * scala trzy warstwy w kolejności: preset ⊕ wiersz biblioteki ⊕ atrybut elementu.
 *
 * 'split' (lines|words|chars) każe silnikowi rozbić tekst przez SplitText
 * i animować kawałki ze staggerem — dlatego preset ze splitem dociąga
 * handle 'evk-splittext'.
 */

function evk_anim_presets(): array {
    return [
        'fade' => [
            'label'    => 'Fade',
            'from'     => ['opacity' => 0],
            'to'       => ['opacity' => 1],
            'duration' => 0.8,
        ],
        'fade-up' => [
            'label'    => 'Fade z dołu',
            'from'     => ['opacity' => 0, 'y' => 40],
            'to'       => ['opacity' => 1, 'y' => 0],
            'duration' => 0.8,
        ],
        'fade-down' => [
            'label'    => 'Fade z góry',
            'from'     => ['opacity' => 0, 'y' => -40],
            'to'       => ['opacity' => 1, 'y' => 0],
            'duration' => 0.8,
        ],
        'fade-left' => [
            'label'    => 'Fade z lewej',
            'from'     => ['opacity' => 0, 'x' => -40],
            'to'       => ['opacity' => 1, 'x' => 0],
            'duration' => 0.8,
        ],
        'fade-right' => [
            'label'    => 'Fade z prawej',
            'from'     => ['opacity' => 0, 'x' => 40],
            'to'       => ['opacity' => 1, 'x' => 0],
            'duration' => 0.8,
        ],
        'scale-in' => [
            'label'    => 'Skala w górę',
            'from'     => ['opacity' => 0, 'scale' => 0.9],
            'to'       => ['opacity' => 1, 'scale' => 1],
            'duration' => 0.8,
        ],
        'zoom-out' => [
            'label'    => 'Zoom out',
            'from'     => ['scale' => 1.15],
            'to'       => ['scale' => 1],
            'duration' => 1.2,
        ],
        'rotate-in' => [
            'label'    => 'Obrót',
            'from'     => ['opacity' => 0, 'rotate' => -6, 'y' => 20],
            'to'       => ['opacity' => 1, 'rotate' => 0, 'y' => 0],
            'duration' => 0.9,
        ],
        'blur-in' => [
            'label'    => 'Rozmycie',
            'from'     => ['opacity' => 0, 'filter' => 'blur(12px)'],
            'to'       => ['opacity' => 1, 'filter' => 'blur(0px)'],
            'duration' => 1.0,
        ],
        'mask-up' => [
            'label'    => 'Odsłona maską (z dołu)',
            'from'     => ['clipPath' => 'inset(100% 0% 0% 0%)', 'y' => 20],
            'to'       => ['clipPath' => 'inset(0% 0% 0% 0%)',   'y' => 0],
            'duration' => 1.0,
        ],
        'split-lines' => [
            'label'    => 'Tekst po liniach',
            'split'    => 'lines',
            'from'     => ['opacity' => 0, 'y' => 24],
            'to'       => ['opacity' => 1, 'y' => 0],
            'duration' => 0.7,
            'stagger'  => 0.08,
        ],
        'split-words' => [
            'label'    => 'Tekst po słowach',
            'split'    => 'words',
            'from'     => ['opacity' => 0, 'y' => 18],
            'to'       => ['opacity' => 1, 'y' => 0],
            'duration' => 0.6,
            'stagger'  => 0.03,
        ],
        'split-chars' => [
            'label'    => 'Tekst po znakach',
            'split'    => 'chars',
            'from'     => ['opacity' => 0, 'y' => 12],
            'to'       => ['opacity' => 1, 'y' => 0],
            'duration' => 0.5,
            'stagger'  => 0.015,
        ],
        // Bez from/to — wartości bierze się wyłącznie z pól „Własne from/to"
        // w wierszu biblioteki. Silnik schodzi wtedy warstwę niżej.
        'custom' => [
            'label'    => 'Własne (from/to poniżej)',
            'duration' => 0.8,
        ],
    ];
}

/**
 * Zamienia zapis „właściwość: wartość" (po jednej na linię) na tablicę dla GSAP.
 *
 *   opacity: 0
 *   y: 40
 *   filter: blur(12px)
 *
 * Liczby trafiają do JSON-a jako liczby, reszta jako tekst — GSAP rozumie oba.
 * Nazwy właściwości ograniczone do bezpiecznego zestawu znaków; wartości i tak
 * jadą przez wp_json_encode / esc_attr, ale limit liczby pól chroni przed
 * wklejeniem czegoś absurdalnego.
 */
function evk_anim_parse_props(string $text): array {
    $out   = [];
    $lines = preg_split('/\r\n|\r|\n/', $text);

    foreach ($lines as $line) {
        if (count($out) >= 20) break;

        $line = trim($line);
        if ($line === '' || strpos($line, ':') === false) continue;

        [$prop, $value] = explode(':', $line, 2);
        $prop  = trim($prop);
        $value = trim($value);

        // Właściwości GSAP/CSS: litery, cyfry, myślnik, podkreślenie.
        if ($prop === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $prop)) continue;
        if ($value === '') continue;

        $out[$prop] = is_numeric($value) ? (float) $value : sanitize_text_field($value);
    }

    return $out;
}

/** Odwrotność evk_anim_parse_props() — tablica z powrotem na tekst do pola w panelu. */
function evk_anim_props_to_text(array $props): string {
    $lines = [];
    foreach ($props as $prop => $value) {
        $lines[] = $prop . ': ' . $value;
    }
    return implode("\n", $lines);
}

/** Wyzwalacze — klucz => etykieta w panelu. */
function evk_anim_triggers(): array {
    return [
        'viewport' => 'Wejście w viewport',
        'scrub'    => 'Scrub przy scrollu',
        'hover'    => 'Hover',
        'click'    => 'Klik',
        'load'     => 'Load strony',
    ];
}

/** Krzywe easingu GSAP dostępne w panelu. */
function evk_anim_easings(): array {
    return [
        'none', 'power1.out', 'power2.out', 'power3.out', 'power4.out',
        'power2.inOut', 'power3.inOut', 'back.out(1.7)', 'expo.out',
        'circ.out', 'sine.inOut', 'elastic.out(1, 0.5)',
    ];
}
