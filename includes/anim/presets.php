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
    ];
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
