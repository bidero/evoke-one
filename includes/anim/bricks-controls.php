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

/**
 * Zakładka panelu, w której ląduje sekcja Evoke ONE.
 *
 * 'content' (domyślnie) — pewne, sprawdzone, sekcja jest zwykłym zwijanym blokiem.
 * 'style'   — sekcja trafia do pionowego paska ikon po lewej, za CSS i Attributes.
 *             Eksperymentalne: Bricks nie udostępnia klucza na ikonę grupy, więc
 *             ikonę dorysowuje CSS w builderze (patrz sekcja poniżej).
 *             Gdyby grupa nie renderowała się w pasku, wyłącz przełącznik.
 */
function evk_bricks_controls_tab(): string {
    if (!class_exists('EVK_Animator')) return 'content';
    return !empty(EVK_Animator::get_instance()->get_settings()['style_tab']) ? 'style' : 'content';
}

// =========================================================================
// IKONA W PIONOWYM PASKU — tylko w builderze i tylko w trybie 'style'
// =========================================================================

/**
 * Czy jesteśmy w oknie buildera Bricks?
 *
 * Reszta wtyczki używa bricks_is_builder_main() jako strażnika POMIJAJĄCEGO
 * („jeśli builder — nie rób"), więc brak funkcji jest tam nieszkodliwy. Tutaj
 * warunek jest odwrotny — coś ma się wydarzyć TYLKO w builderze — więc brak
 * funkcji nie może oznaczać ciszy. Stąd trzy niezależne próby wykrycia.
 */
function evk_is_bricks_builder(): bool {
    if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) return true;
    if (function_exists('bricks_is_builder')      && bricks_is_builder())      return true;
    // Builder startuje z ?bricks=run — ostatnia deska ratunku.
    return isset($_GET['bricks']) && sanitize_key(wp_unslash($_GET['bricks'])) === 'run';
}

/**
 * Pozycje paska to <li data-balloon="Tytuł grupy"> z osadzonym SVG. Grupa dodana
 * filtrem nie dostaje SVG, bo publiczne API nie zna klucza na ikonę — więc
 * podkładamy ikonę tłem, celując w tooltip. Sam <li> musi istnieć; jeśli Bricks
 * w ogóle nie renderuje grup z filtra w pasku, ten CSS nie ma czego złapać.
 */
add_action('wp_head', function (): void {
    if (!evk_is_bricks_builder()) return;
    if (evk_bricks_controls_tab() !== 'style') return;
    if (!evk_anim_controls_active() && !evk_parallax_controls_active()) return;

    $svg = rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" '
        . 'stroke="#c8c8c8" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
        . '<path d="M13 2 4 14h7l-1 8 9-12h-7z"/></svg>'
    );

    // Selektor podciągiem (*=), nie dokładnym dopasowaniem — tooltip może nieść
    // dodatkowy tekst albo inny cudzysłów zależnie od wersji Bricks.
    echo '<style id="evk-bricks-group-icon">
li[data-balloon*="Evoke ONE"] {
    background-image: url("data:image/svg+xml,' . $svg . '");
    background-repeat: no-repeat;
    background-position: center;
    background-size: 20px 20px;
    min-height: 40px;
}
li[data-balloon*="Evoke ONE"] .bricks-svg-wrapper { display: none; }
</style>';
});

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
 * Zakładka zależy od przełącznika (evk_bricks_controls_tab()). Domyślnie 'content'
 * — zwykły zwijany blok, wariant pewny. Po włączeniu 'style' sekcja ląduje
 * w pionowym pasku ikon; ikonę trzeba wtedy dorysować CSS-em, bo publiczne API
 * grup zna wyłącznie 'tab' i 'title'.
 */
function evk_bricks_control_groups($groups) {
    if (!is_array($groups)) return $groups;

    // unset + dopisanie na końcu: kolejność wstawiania decyduje o kolejności w panelu.
    unset($groups['evk_animator'], $groups['evk_parallax'], $groups['evk_one']);

    if (!evk_anim_controls_active() && !evk_parallax_controls_active()) return $groups;

    $groups['evk_one'] = [
        'title' => 'Evoke ONE',
        'tab'   => evk_bricks_controls_tab(),
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
        'tab'   => evk_bricks_controls_tab(),
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
        'tab'         => evk_bricks_controls_tab(),
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
        'tab'      => evk_bricks_controls_tab(),
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
            'tab'         => evk_bricks_controls_tab(),
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
        'tab'         => evk_bricks_controls_tab(),
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
        'tab'   => evk_bricks_controls_tab(),
        'group' => 'evk_one',
        'type'  => 'separator',
        'label' => esc_html__('Parallax', 'evoke-one'),
    ];

    $controls['evkParallax'] = [
        'tab'     => evk_bricks_controls_tab(),
        'group'   => 'evk_one',
        'label'   => esc_html__('Włącz parallax', 'evoke-one'),
        'type'    => 'checkbox',
        'default' => false,
    ];

    // Placeholder pokazuje wartość globalną — puste pole ją właśnie oznacza.
    $controls['evkParallaxValue'] = [
        'tab'         => evk_bricks_controls_tab(),
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
        'tab'         => evk_bricks_controls_tab(),
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
