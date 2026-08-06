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
 *
 * 'easing' jest opcjonalne i wchodzi tylko wtedy, gdy wiersz biblioteki ma
 * pole easingu puste („— z presetu —"). Bez tego preset nie mógłby narzucić
 * krzywej, a część efektów bez niej nie istnieje: „odbicie" z liniowym
 * easingiem to zwykłe skalowanie.
 *
 * WŁAŚCIWOŚCI STATYCZNE (podkład pod animowaną wartość — np. gradient pod
 * animowanym background-size) PODAJEMY TYLKO WE 'from'. Zmierzone w Chromium:
 * GSAP nakłada je przy starcie i zostają, a wynik jest identyczny jak przy
 * powtórzeniu ich w 'to'. Trzymanie ich po jednej stronie ma tę zaletę, że
 * własne 'to' wpisane w panelu zastępuje wyłącznie wartości animowane
 * i nie gubi podkładu.
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
        'mask-left' => [
            'label'    => 'Odsłona maską (z lewej)',
            'from'     => ['clipPath' => 'inset(0% 100% 0% 0%)'],
            'to'       => ['clipPath' => 'inset(0% 0% 0% 0%)'],
            'duration' => 1.0,
        ],
        'mask-right' => [
            'label'    => 'Odsłona maską (z prawej)',
            'from'     => ['clipPath' => 'inset(0% 0% 0% 100%)'],
            'to'       => ['clipPath' => 'inset(0% 0% 0% 0%)'],
            'duration' => 1.0,
        ],
        'flip-x' => [
            'label'    => 'Flip 3D (w poziomie)',
            'from'     => ['opacity' => 0, 'rotationX' => -70, 'transformPerspective' => 800],
            'to'       => ['opacity' => 1, 'rotationX' => 0],
            'duration' => 1.0,
        ],
        'flip-y' => [
            'label'    => 'Flip 3D (w pionie)',
            'from'     => ['opacity' => 0, 'rotationY' => -70, 'transformPerspective' => 800],
            'to'       => ['opacity' => 1, 'rotationY' => 0],
            'duration' => 1.0,
        ],
        'skew-in' => [
            'label'    => 'Skew',
            'from'     => ['opacity' => 0, 'skewY' => 6, 'y' => 40],
            'to'       => ['opacity' => 1, 'skewY' => 0, 'y' => 0],
            'duration' => 0.9,
        ],
        // transformOrigin w obu stanach: to nie jest wartość animowana, tylko
        // punkt zaczepienia obrotu. Podana raz we 'from' zostaje — ale przy
        // wychyleniu musi obowiązywać także po cofnięciu osi czasu (hover),
        // więc tu wyjątkowo idzie po obu stronach.
        'swing-in' => [
            'label'    => 'Wychylenie',
            'from'     => ['opacity' => 0, 'rotation' => -9, 'y' => -30, 'transformOrigin' => 'top center'],
            'to'       => ['opacity' => 1, 'rotation' => 0,  'y' => 0,   'transformOrigin' => 'top center'],
            'duration' => 0.9,
            'easing'   => 'back.out(1.7)',
        ],
        'bounce-in' => [
            'label'    => 'Odbicie',
            'from'     => ['opacity' => 0, 'scale' => 0.4],
            'to'       => ['opacity' => 1, 'scale' => 1],
            'duration' => 0.9,
            'easing'   => 'back.out(2.2)',
        ],
        'roll-in' => [
            'label'    => 'Wtoczenie',
            'from'     => ['opacity' => 0, 'x' => -120, 'rotation' => -120],
            'to'       => ['opacity' => 1, 'x' => 0,    'rotation' => 0],
            'duration' => 1.0,
            'easing'   => 'power3.out',
        ],
        'blur-up' => [
            'label'    => 'Rozmycie z dołu',
            'from'     => ['opacity' => 0, 'y' => 30, 'filter' => 'blur(10px)'],
            'to'       => ['opacity' => 1, 'y' => 0,  'filter' => 'blur(0px)'],
            'duration' => 1.0,
        ],
        // Maska + przesunięcie: treść wyjeżdża zza własnej krawędzi zamiast
        // przelatywać przez pół ekranu. Krótki dystans jest tu celowy.
        'slide-mask-left' => [
            'label'    => 'Wjazd zza maski (z lewej)',
            'from'     => ['clipPath' => 'inset(0% 100% 0% 0%)', 'x' => -40],
            'to'       => ['clipPath' => 'inset(0% 0% 0% 0%)',   'x' => 0],
            'duration' => 1.0,
            'easing'   => 'power3.out',
        ],
        'slide-mask-right' => [
            'label'    => 'Wjazd zza maski (z prawej)',
            'from'     => ['clipPath' => 'inset(0% 0% 0% 100%)', 'x' => 40],
            'to'       => ['clipPath' => 'inset(0% 0% 0% 0%)',   'x' => 0],
            'duration' => 1.0,
            'easing'   => 'power3.out',
        ],

        // ── Stany najechania ───────────────────────────────────────────────
        //
        // Do wyzwalacza „Hover": silnik buduje z nich WSTRZYMANĄ oś czasu,
        // gra ją do przodu na wejściu wskaźnika i fokusie, cofa na wyjściu.
        // Stan 'from' jest więc stanem spoczynku elementu i musi wyglądać
        // jak jego zwykły wygląd — stąd np. jawny cień wyjściowy w 'lift'.
        'lift' => [
            'label'    => 'Hover: uniesienie',
            'from'     => ['y' => 0,  'scale' => 1,    'boxShadow' => '0 2px 6px rgba(0,0,0,0.08)'],
            'to'       => ['y' => -6, 'scale' => 1.02, 'boxShadow' => '0 18px 40px rgba(0,0,0,0.18)'],
            'duration' => 0.35,
            'easing'   => 'power2.out',
        ],
        'glow' => [
            'label'    => 'Hover: poświata',
            'from'     => ['filter' => 'brightness(1) saturate(1)'],
            'to'       => ['filter' => 'brightness(1.1) saturate(1.2)'],
            'duration' => 0.35,
            'easing'   => 'power2.out',
        ],
        // Podkreślenie rysowane gradientem tła: currentColor bierze kolor
        // tekstu, więc działa na dowolnej typografii bez dodatkowego pola.
        // Gradient, powtarzanie i pozycja to podkład — nie animują się.
        'underline-sweep' => [
            'label'    => 'Hover: podkreślenie',
            'from'     => [
                'backgroundImage'    => 'linear-gradient(currentColor, currentColor)',
                'backgroundRepeat'   => 'no-repeat',
                'backgroundPosition' => '0% 100%',
                'backgroundSize'     => '0% 2px',
            ],
            'to'       => ['backgroundSize' => '100% 2px'],
            'duration' => 0.35,
            'easing'   => 'power2.out',
        ],
        // Ramka rysowana cieniem wewnętrznym, nie border — border zmienia
        // rozmiar pudełka i przy najechaniu przesuwałby sąsiadów.
        // Kolor jawny, nie currentColor: GSAP musi umieć rozłożyć go na kanały.
        'border-draw' => [
            'label'    => 'Hover: rysowana ramka',
            'from'     => ['boxShadow' => 'inset 0 0 0 0px rgba(17,24,39,0.9)'],
            'to'       => ['boxShadow' => 'inset 0 0 0 2px rgba(17,24,39,0.9)'],
            'duration' => 0.35,
            'easing'   => 'power2.out',
        ],
        'split-lines' => [
            'label'    => 'Tekst po liniach',
            'split'    => 'lines',
            'from'     => ['opacity' => 0, 'y' => 24],
            'to'       => ['opacity' => 1, 'y' => 0],
            'duration' => 0.7,
            'stagger'  => 0.08,
        ],
        // 'mask' => 'lines' każe SplitText owinąć każdą linię w overflow:hidden,
        // dzięki czemu tekst wyjeżdża zza krawędzi zamiast po prostu się przesuwać.
        // Wymaga GSAP 3.13+ (ładujemy dokładnie tę wersję).
        'split-lines-mask' => [
            'label'    => 'Tekst po liniach (zza maski)',
            'split'    => 'lines',
            'mask'     => 'lines',
            'from'     => ['yPercent' => 110],
            'to'       => ['yPercent' => 0],
            'duration' => 0.9,
            'stagger'  => 0.1,
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

/**
 * Krzywe easingu GSAP dostępne w panelu.
 *
 * To jednocześnie lista dopuszczalnych wartości przy zapisie. Pusta wartość
 * („— z presetu —") jest obsługiwana osobno w EVK_Animator::sanitize_settings()
 * i oznacza dziedziczenie po preseecie — tak samo jak przy czasie i staggerze.
 */
function evk_anim_easings(): array {
    return [
        'none', 'power1.out', 'power2.out', 'power3.out', 'power4.out',
        'power2.inOut', 'power3.inOut', 'back.out(1.7)', 'back.out(2.2)',
        'expo.out', 'circ.out', 'sine.inOut', 'bounce.out', 'elastic.out(1, 0.5)',
    ];
}
