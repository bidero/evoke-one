<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — kontrolki Animatora i Parallaxu w panelu elementu Bricks
 *
 * Dokłada jedną wspólną sekcję „Evoke ONE" do KAŻDEGO zarejestrowanego elementu,
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
// ZAPIS ATRYBUTU — helper odporny na kształt tablicy
// =========================================================================

/**
 * Bricks grupuje atrybuty po kluczu fragmentu HTML — $attributes[$key]['data-x'] —
 * tak pokazuje to oficjalna dokumentacja filtra bricks/element/render_attributes.
 * Wcześniejsze użycia w tym repo zapisywały płasko i po cichu nie działały.
 * Zamiast wybierać kształt w ciemno, wykrywamy go w locie.
 *
 * Dyskryminator jest bezpieczny: w formie płaskiej nie ma atrybutu o nazwie
 * '_root', a element ustawia sobie 'class' na '_root' przed renderem, więc
 * w formie grupowanej $attributes['_root'] na pewno istnieje.
 */
function evk_bricks_set_attr(array $attributes, string $key, string $name, string $value): array {
    if (isset($attributes[$key]) && is_array($attributes[$key])) {
        $attributes[$key][$name] = [$value];
        return $attributes;
    }
    $attributes[$name] = [$value];
    return $attributes;
}

// =========================================================================
// GRUPY
// =========================================================================

/**
 * Jedna wspólna sekcja zamiast dwóch osobnych grup — wewnątrz rozdzielona
 * separatorami „Animator" / „Parallax".
 *
 * Zakładka 'content', nie 'style': w Bricks 2.x zakładka Style renderuje grupy
 * jako pionowy pasek ikon (<li> z SVG i tooltipem). Grupa dodana filtrem nie ma
 * ikony — publiczne API zna tylko 'tab' i 'title' — więc w pasku powstaje pusty,
 * niewidoczny element i kontrolki znikają z panelu. 'content' jest jedynym
 * wariantem potwierdzonym na żywej instalacji.
 */
function evk_bricks_control_groups($groups) {
    if (!is_array($groups)) return $groups;

    // unset + dopisanie na końcu: kolejność wstawiania decyduje o kolejności w panelu.
    unset($groups['evk_animator'], $groups['evk_parallax'], $groups['evk_one']);

    if (!evk_anim_controls_active() && !evk_parallax_controls_active()) return $groups;

    $groups['evk_one'] = [
        'title' => 'Evoke ONE',
        'tab'   => 'content',
        // Klucz nieudokumentowany — Bricks zignoruje go, jeśli go nie zna.
        // Nic na nim nie polega, ale gdyby był wspierany, dostajemy ikonę gratis.
        'icon'  => 'bolt',
    ];

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

    // Nagłówek sekcji wewnątrz wspólnej grupy — dodawany tylko gdy sekcja istnieje,
    // żeby przy jednym włączonym module nie został osierocony nagłówek.
    $controls['evkSepAnimator'] = [
        'tab'   => 'content',
        'group' => 'evk_one',
        'type'  => 'separator',
        'label' => esc_html__('Animator', 'evoke-one'),
    ];

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
        'group'       => 'evk_one',
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
        'group'    => 'evk_one',
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
            'group'       => 'evk_one',
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
        'group'       => 'evk_one',
        'label'       => esc_html__('Start (ScrollTrigger)', 'evoke-one'),
        'type'        => 'text',
        'placeholder' => esc_html__('z biblioteki', 'evoke-one'),
        'required'    => $required,
    ];

    return $controls;
}

function evk_bricks_parallax_controls(array $controls): array {
    $controls['evkSepParallax'] = [
        'tab'   => 'content',
        'group' => 'evk_one',
        'type'  => 'separator',
        'label' => esc_html__('Parallax', 'evoke-one'),
    ];

    $controls['evkParallax'] = [
        'tab'     => 'content',
        'group'   => 'evk_one',
        'label'   => esc_html__('Włącz parallax', 'evoke-one'),
        'type'    => 'checkbox',
        'default' => false,
    ];

    // Placeholder pokazuje wartość globalną — puste pole ją właśnie oznacza.
    $controls['evkParallaxValue'] = [
        'tab'         => 'content',
        'group'       => 'evk_one',
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
        'group'       => 'evk_one',
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

        $attributes = evk_bricks_set_attr($attributes, $key, 'data-evk-anim', wp_json_encode($cfg));
    }

    // ── Parallax ──────────────────────────────────────────────────────────
    // Pusty atrybut jest znaczący: assets/js/parallax.js czyta go jako
    // „użyj wartości globalnej", więc nie wypełniamy go domyślnymi tutaj.
    if (evk_parallax_controls_active() && !empty($s['evkParallax'])) {
        $value = (isset($s['evkParallaxValue']) && $s['evkParallaxValue'] !== '')
            ? (string) floatval($s['evkParallaxValue']) : '';
        $scale = (isset($s['evkParallaxScale']) && $s['evkParallaxScale'] !== '')
            ? (string) floatval($s['evkParallaxScale']) : '';

        $attributes = evk_bricks_set_attr($attributes, $key, 'data-parallax', $value);
        $attributes = evk_bricks_set_attr($attributes, $key, 'data-skala',    $scale);
    }

    return $attributes;
}, 10, 3);
