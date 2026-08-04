<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — kontrolki Animatora i Parallaxu w panelu elementu Bricks
 *
 * Dokłada dwie grupy kontrolek do KAŻDEGO zarejestrowanego elementu Bricks,
 * przez oficjalne filtry `bricks/elements/{name}/control_groups` i
 * `.../controls` (dostępne od Bricks 1.3.2). Ustawienia trafiają na front jako
 * atrybuty `data-*` przez `bricks/element/render_attributes`.
 *
 * Warstwa wejścia, nie silnik — oba silniki JS (assets/js/animator.js,
 * assets/js/parallax.js) czytają dokładnie te same atrybuty co dotąd i nie
 * wymagają żadnych zmian. Wyłączenie modułu w panelu cofa wszystko, a klasy
 * `evk-anim-{slug}` i ręcznie wpisany `data-parallax` działają niezależnie.
 */

// =========================================================================
// BRAMKOWANIE — kontrolki tylko gdy moduł włączony
// =========================================================================

function evk_anim_controls_active(): bool {
    if (!class_exists('EVK_Animator')) return false;
    return !empty(EVK_Animator::get_instance()->get_settings()['enabled']);
}

function evk_parallax_controls_active(): bool {
    if (!class_exists('EVK_Parallax')) return false;
    return !empty(EVK_Parallax::get_instance()->get_settings()['enabled']);
}

// =========================================================================
// REJESTRACJA FILTRÓW
// =========================================================================

add_action('init', function (): void {
    if (!class_exists('\Bricks\Elements')) return;
    if (!evk_anim_controls_active() && !evk_parallax_controls_active()) return;

    $elements = \Bricks\Elements::$elements ?? null;
    if (!is_array($elements)) return;

    foreach ($elements as $key => $el) {
        // Bricks kluczuje tablicę nazwą elementu, a wartość również niesie 'name'.
        // Bierzemy 'name' gdy jest, klucz w przeciwnym razie — odporne na obie formy.
        $name = (is_array($el) && !empty($el['name'])) ? $el['name'] : (string) $key;
        if ($name === '') continue;

        add_filter("bricks/elements/{$name}/control_groups", 'evk_bricks_control_groups');
        add_filter("bricks/elements/{$name}/controls",       'evk_bricks_controls');
    }
// PHP_INT_MAX, nie 10/20 — Bricks rejestruje elementy na init/10, nasz loader
// elementów na init/11, a inne wtyczki mogą jeszcze później. Niższy priorytet
// dałby niepełną listę i część elementów zostałaby bez kontrolek.
}, PHP_INT_MAX);

// =========================================================================
// GRUPY
// =========================================================================

function evk_bricks_control_groups($groups) {
    if (!is_array($groups)) return $groups;

    if (evk_anim_controls_active()) {
        $groups['evk_animator'] = [
            'title' => 'Evoke Animator',
            'tab'   => 'content',
        ];
    }
    if (evk_parallax_controls_active()) {
        $groups['evk_parallax'] = [
            'title' => 'Evoke Parallax',
            'tab'   => 'content',
        ];
    }
    return $groups;
}

// =========================================================================
// KONTROLKI
// =========================================================================

function evk_bricks_controls($controls) {
    if (!is_array($controls)) return $controls;

    if (evk_anim_controls_active()) {
        $controls = evk_bricks_animator_controls($controls);
    }
    if (evk_parallax_controls_active()) {
        $controls = evk_bricks_parallax_controls($controls);
    }
    return $controls;
}

function evk_bricks_animator_controls(array $controls): array {
    $settings = EVK_Animator::get_instance()->get_settings();

    // Lista zasilana biblioteką — nie da się wybrać animacji, której nie ma.
    $options = ['' => '— brak —'];
    foreach ((array) $settings['animations'] as $row) {
        $slug = $row['slug'] ?? '';
        if ($slug === '') continue;
        $label = ($row['label'] ?? '') !== '' ? $row['label'] : $slug;
        $options[$slug] = $label . ' (' . $slug . ')';
    }

    $controls['evkAnimAnimation'] = [
        'tab'         => 'content',
        'group'       => 'evk_animator',
        'label'       => esc_html__('Animacja', 'evoke-one'),
        'type'        => 'select',
        'options'     => $options,
        'default'     => '',
        'description' => count($options) > 1
            ? esc_html__('Z biblioteki: Ustawienia → Evoke ONE → Frontend → Animator.', 'evoke-one')
            : esc_html__('Biblioteka jest pusta — dodaj animację w Ustawienia → Evoke ONE → Frontend → Animator.', 'evoke-one'),
    ];

    // Nadpisania — puste pole zostawia wartość z biblioteki.
    $required = ['evkAnimAnimation', '!=', ''];

    $trigger_options = ['' => '— z biblioteki —'];
    foreach (evk_anim_triggers() as $k => $label) {
        $trigger_options[$k] = $label;
    }

    $controls['evkAnimTrigger'] = [
        'tab'      => 'content',
        'group'    => 'evk_animator',
        'label'    => esc_html__('Wyzwalacz', 'evoke-one'),
        'type'     => 'select',
        'options'  => $trigger_options,
        'default'  => '',
        'required' => $required,
    ];

    $overrides = [
        'evkAnimDuration' => [esc_html__('Czas (s)', 'evoke-one'),        0.05, 10, 0.05],
        'evkAnimDelay'    => [esc_html__('Opóźnienie (s)', 'evoke-one'),  0,    10, 0.05],
        'evkAnimStagger'  => [esc_html__('Stagger (s)', 'evoke-one'),     0,    2,  0.005],
    ];
    foreach ($overrides as $id => [$label, $min, $max, $step]) {
        $controls[$id] = [
            'tab'         => 'content',
            'group'       => 'evk_animator',
            'label'       => $label,
            'type'        => 'number',
            'min'         => $min,
            'max'         => $max,
            'step'        => $step,
            'placeholder' => esc_html__('z biblioteki', 'evoke-one'),
            'required'    => $required,
        ];
    }

    $controls['evkAnimStart'] = [
        'tab'         => 'content',
        'group'       => 'evk_animator',
        'label'       => esc_html__('Start (ScrollTrigger)', 'evoke-one'),
        'type'        => 'text',
        'placeholder' => esc_html__('z biblioteki', 'evoke-one'),
        'required'    => $required,
    ];

    return $controls;
}

function evk_bricks_parallax_controls(array $controls): array {
    $controls['evkParallax'] = [
        'tab'     => 'content',
        'group'   => 'evk_parallax',
        'label'   => esc_html__('Włącz parallax', 'evoke-one'),
        'type'    => 'checkbox',
        'default' => false,
    ];

    // Placeholder pokazuje wartość globalną — puste pole ją właśnie oznacza.
    $controls['evkParallaxValue'] = [
        'tab'         => 'content',
        'group'       => 'evk_parallax',
        'label'       => esc_html__('Siła', 'evoke-one'),
        'type'        => 'number',
        'min'         => -1,
        'max'         => 1,
        'step'        => 0.05,
        'placeholder' => (string) evk_get_parallax_value(),
        'description' => esc_html__('Puste = wartość globalna z panelu Parallax.', 'evoke-one'),
        'required'    => ['evkParallax', '=', true],
    ];

    $controls['evkParallaxScale'] = [
        'tab'         => 'content',
        'group'       => 'evk_parallax',
        'label'       => esc_html__('Skala', 'evoke-one'),
        'type'        => 'number',
        'min'         => 1,
        'max'         => 2,
        'step'        => 0.05,
        'placeholder' => (string) evk_get_parallax_scale(),
        'description' => esc_html__('Puste = wartość globalna z panelu Parallax.', 'evoke-one'),
        'required'    => ['evkParallax', '=', true],
    ];

    return $controls;
}

// =========================================================================
// EMISJA ATRYBUTÓW NA FRONT
// =========================================================================

add_filter('bricks/element/render_attributes', function ($attributes, $key, $element) {
    if ($key !== '_root' || !is_array($attributes)) return $attributes;

    $s = (array) ($element->settings ?? []);

    // ── Animator ──────────────────────────────────────────────────────────
    if (evk_anim_controls_active() && !empty($s['evkAnimAnimation'])) {
        $cfg = ['animation' => sanitize_key($s['evkAnimAnimation'])];

        // Tylko realnie wypełnione pola — pusty klucz w JSON przesłoniłby
        // wartość z biblioteki (silnik pomija '' , ale nie 0).
        if (!empty($s['evkAnimTrigger'])) {
            $cfg['trigger'] = sanitize_key($s['evkAnimTrigger']);
        }
        foreach (['evkAnimDuration' => 'duration', 'evkAnimDelay' => 'delay', 'evkAnimStagger' => 'stagger'] as $id => $prop) {
            if (isset($s[$id]) && $s[$id] !== '') $cfg[$prop] = floatval($s[$id]);
        }
        if (!empty($s['evkAnimStart'])) {
            $cfg['start'] = sanitize_text_field($s['evkAnimStart']);
        }

        $attributes['data-evk-anim'] = [wp_json_encode($cfg)];
    }

    // ── Parallax ──────────────────────────────────────────────────────────
    // Pusty atrybut jest znaczący: assets/js/parallax.js czyta go jako
    // „użyj wartości globalnej", więc nie wypełniamy go domyślnymi tutaj.
    if (evk_parallax_controls_active() && !empty($s['evkParallax'])) {
        $value = (isset($s['evkParallaxValue']) && $s['evkParallaxValue'] !== '')
            ? (string) floatval($s['evkParallaxValue']) : '';
        $scale = (isset($s['evkParallaxScale']) && $s['evkParallaxScale'] !== '')
            ? (string) floatval($s['evkParallaxScale']) : '';

        $attributes['data-parallax'] = [$value];
        $attributes['data-skala']    = [$scale];
    }

    return $attributes;
}, 10, 3);
