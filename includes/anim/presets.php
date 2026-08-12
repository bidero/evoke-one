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
        // ── Efekty tekstowe ────────────────────────────────────────────────
        //
        // Te presety nie mają from/to i mieć nie mogą: docelowym tekstem jest
        // treść, którą element ma już w sobie, a tablica w PHP nie przeniesie
        // funkcji, która by ją odczytała. Zamiast tego niosą znacznik 'textFx',
        // a varsy dla wtyczki składa silnik (patrz textFxVars w animator.js).
        //
        // Pracują na treści elementu, więc mają sens wyłącznie na elementach
        // z czystym tekstem — znaczniki w środku zostaną zastąpione.
        'typewriter' => [
            'label'    => 'Tekst: maszyna do pisania',
            'textFx'   => 'type',
            'duration' => 1.8,
            'easing'   => 'none',
        ],
        'scramble' => [
            'label'    => 'Tekst: losowe znaki',
            'textFx'   => 'scramble',
            'duration' => 1.4,
            'easing'   => 'none',
        ],
        // Wymaga listy słów w polu „Słowa" wiersza biblioteki — bez niej
        // silnik nie ma czym cyklować i preset nic nie robi.
        'rotating-words' => [
            'label'    => 'Tekst: zmieniające się słowa',
            'textFx'   => 'words',
            'duration' => 0.6,
            'easing'   => 'none',
        ],

        // ── Efekty wskaźnika ───────────────────────────────────────────────
        //
        // Nie są tweenem od–do, tylko śledzeniem kursora, więc działają
        // NIEZALEŻNIE OD WYZWALACZA — pole „Wyzwalacz" nic dla nich nie znaczy.
        // 'to' opisuje stan spoczynku i jest potrzebne w jednym miejscu:
        // przy redukcji ruchu silnik nakłada je zamiast podpinać śledzenie.
        // 'strength' skaluje wychylenie; można je nadpisać w data-evk-anim.
        'magnetic' => [
            'label'    => 'Wskaźnik: przyciąganie',
            'pointer'  => 'magnetic',
            'strength' => 0.35,
            'to'       => ['x' => 0, 'y' => 0],
            'duration' => 0.5,
        ],
        'tilt' => [
            'label'    => 'Wskaźnik: przechył 3D',
            'pointer'  => 'tilt',
            'strength' => 1.0,
            'to'       => ['rotationX' => 0, 'rotationY' => 0],
            'duration' => 0.5,
        ],

        /*
         * ── WYJŚCIA ───────────────────────────────────────────────────────
         *
         * Znacznik `'exit' => true` odróżnia je od wejść. To on, a nie prefiks
         * w nazwie: testy i panel czytają już znaczniki tablicy (`textFx`,
         * `pointer`), a rozpoznawanie po nazwie byłoby trzecią kopią tej wiedzy
         * i pierwszą, która rozjedzie się przy zmianie nazwy.
         *
         * `from` to stan SPOCZYNKU, `to` — stan po zniknięciu. Odwrotnie niż
         * przy wejściach, i to ma konsekwencje w silniku: przy redukcji ruchu
         * nie wolno nakładać ich `to` (element zgasłby na stałe), a oś wyjściowa
         * nie może renderować swojego `from` z wyprzedzeniem.
         *
         * Krzywa `.in` zamiast `.out`: wyjście ma przyspieszać ku końcowi, a nie
         * zwalniać. Obie wersje `.in` dopisane są do evk_anim_easings() niżej —
         * bez tego sanityzacja odrzuciłaby je przy zapisie wiersza biblioteki.
         */
        'fade-out' => [
            'label'    => 'Wyjście: zanik',
            'exit'     => true,
            'from'     => ['opacity' => 1],
            'to'       => ['opacity' => 0],
            'duration' => 0.6,
            'easing'   => 'power2.in',
        ],
        'fade-out-up' => [
            'label'    => 'Wyjście: zanik w górę',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'y' => 0],
            'to'       => ['opacity' => 0, 'y' => -40],
            'duration' => 0.6,
            'easing'   => 'power2.in',
        ],
        'fade-out-down' => [
            'label'    => 'Wyjście: zanik w dół',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'y' => 0],
            'to'       => ['opacity' => 0, 'y' => 40],
            'duration' => 0.6,
            'easing'   => 'power2.in',
        ],
        'scale-out' => [
            'label'    => 'Wyjście: zmniejszenie',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'scale' => 1],
            'to'       => ['opacity' => 0, 'scale' => 0.9],
            'duration' => 0.6,
            'easing'   => 'power2.in',
        ],
        'blur-out' => [
            'label'    => 'Wyjście: rozmycie',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'filter' => 'blur(0px)'],
            'to'       => ['opacity' => 0, 'filter' => 'blur(12px)'],
            'duration' => 0.6,
            'easing'   => 'power2.in',
        ],
        'fade-out-left' => [
            'label'    => 'Wyjście: zanik w lewo',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'x' => 0],
            'to'       => ['opacity' => 0, 'x' => -40],
            'duration' => 0.6,
            'easing'   => 'power2.in',
        ],
        'fade-out-right' => [
            'label'    => 'Wyjście: zanik w prawo',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'x' => 0],
            'to'       => ['opacity' => 0, 'x' => 40],
            'duration' => 0.6,
            'easing'   => 'power2.in',
        ],
        // Lustro „zoom out" z wejść: tam element dojeżdża ze 1,15 do 1, tu
        // odjeżdża w drugą stronę. Powiększenie przy zanikaniu czyta się jak
        // oddalenie, a nie jak znikanie w punkt — stąd osobna pozycja obok
        // „zmniejszenia".
        'zoom-out-exit' => [
            'label'    => 'Wyjście: powiększenie',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'scale' => 1],
            'to'       => ['opacity' => 0, 'scale' => 1.15],
            'duration' => 0.7,
            'easing'   => 'power2.in',
        ],
        'rotate-out' => [
            'label'    => 'Wyjście: obrót',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'rotate' => 0, 'y' => 0],
            'to'       => ['opacity' => 0, 'rotate' => 6, 'y' => 20],
            'duration' => 0.7,
            'easing'   => 'power2.in',
        ],
        'skew-out' => [
            'label'    => 'Wyjście: skew',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'skewY' => 0, 'y' => 0],
            'to'       => ['opacity' => 0, 'skewY' => 6, 'y' => 40],
            'duration' => 0.7,
            'easing'   => 'power2.in',
        ],
        'swing-out' => [
            'label'    => 'Wyjście: wychylenie',
            'exit'     => true,
            // transformOrigin w obu stanach: to nie jest wartość animowana,
            // tylko punkt, wokół którego liczy się obrót.
            'from'     => ['opacity' => 1, 'rotation' => 0, 'y' => 0,   'transformOrigin' => 'top center'],
            'to'       => ['opacity' => 0, 'rotation' => 9, 'y' => -30, 'transformOrigin' => 'top center'],
            'duration' => 0.7,
            'easing'   => 'power2.in',
        ],
        'roll-out' => [
            'label'    => 'Wyjście: wytoczenie',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'x' => 0,   'rotation' => 0],
            'to'       => ['opacity' => 0, 'x' => 120, 'rotation' => 120],
            'duration' => 0.8,
            'easing'   => 'power3.in',
        ],
        'flip-x-out' => [
            'label'    => 'Wyjście: flip 3D (w poziomie)',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'rotationX' => 0,  'transformPerspective' => 800],
            'to'       => ['opacity' => 0, 'rotationX' => 70],
            'duration' => 0.7,
            'easing'   => 'power2.in',
        ],
        'flip-y-out' => [
            'label'    => 'Wyjście: flip 3D (w pionie)',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'rotationY' => 0,  'transformPerspective' => 800],
            'to'       => ['opacity' => 0, 'rotationY' => 70],
            'duration' => 0.7,
            'easing'   => 'power2.in',
        ],
        'blur-out-down' => [
            'label'    => 'Wyjście: rozmycie w dół',
            'exit'     => true,
            'from'     => ['opacity' => 1, 'y' => 0,  'filter' => 'blur(0px)'],
            'to'       => ['opacity' => 0, 'y' => 30, 'filter' => 'blur(10px)'],
            'duration' => 0.8,
            'easing'   => 'power2.in',
        ],
        'mask-out-up' => [
            'label'    => 'Wyjście: zasłona w górę',
            'exit'     => true,
            'from'     => ['clipPath' => 'inset(0% 0% 0% 0%)'],
            'to'       => ['clipPath' => 'inset(0% 0% 100% 0%)'],
            'duration' => 0.6,
            'easing'   => 'power3.in',
        ],
        'mask-out-down' => [
            'label'    => 'Wyjście: zasłona w dół',
            'exit'     => true,
            'from'     => ['clipPath' => 'inset(0% 0% 0% 0%)'],
            'to'       => ['clipPath' => 'inset(100% 0% 0% 0%)'],
            'duration' => 0.6,
            'easing'   => 'power3.in',
        ],
        'mask-out-left' => [
            'label'    => 'Wyjście: zasłona w lewo',
            'exit'     => true,
            'from'     => ['clipPath' => 'inset(0% 0% 0% 0%)'],
            'to'       => ['clipPath' => 'inset(0% 100% 0% 0%)'],
            'duration' => 0.6,
            'easing'   => 'power3.in',
        ],
        'mask-out-right' => [
            'label'    => 'Wyjście: zasłona w prawo',
            'exit'     => true,
            'from'     => ['clipPath' => 'inset(0% 0% 0% 0%)'],
            'to'       => ['clipPath' => 'inset(0% 0% 0% 100%)'],
            'duration' => 0.6,
            'easing'   => 'power3.in',
        ],
        // Lustro „wjazdu zza maski": treść chowa się za własną krawędzią
        // zamiast przelatywać przez pół ekranu. Krótki dystans jest celowy.
        'slide-mask-out-left' => [
            'label'    => 'Wyjście: zjazd za maskę (w lewo)',
            'exit'     => true,
            'from'     => ['clipPath' => 'inset(0% 0% 0% 0%)',   'x' => 0],
            'to'       => ['clipPath' => 'inset(0% 100% 0% 0%)', 'x' => -40],
            'duration' => 0.8,
            'easing'   => 'power3.in',
        ],
        'slide-mask-out-right' => [
            'label'    => 'Wyjście: zjazd za maskę (w prawo)',
            'exit'     => true,
            'from'     => ['clipPath' => 'inset(0% 0% 0% 0%)',   'x' => 0],
            'to'       => ['clipPath' => 'inset(0% 0% 0% 100%)', 'x' => 40],
            'duration' => 0.8,
            'easing'   => 'power3.in',
        ],

        // Bez from/to — wartości bierze się wyłącznie z pól „Własne from/to"
        // w wierszu biblioteki. Silnik schodzi wtedy warstwę niżej.
        'custom' => [
            'label'    => 'Własne (from/to poniżej)',
            'duration' => 0.8,
        ],
    ];
}

/** Czy preset jest wyjściem z kadru — jedno miejsce, żeby nie rozpoznawać po nazwie. */
function evk_anim_preset_is_exit(string $slug): bool {
    $presets = evk_anim_presets();
    return !empty($presets[$slug]['exit']);
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

/**
 * Lista słów dla presetu „zmieniające się słowa" — po jednym na linię.
 *
 * Limit jest po to, żeby wklejenie całego akapitu nie zbudowało osi czasu
 * na kilkaset kroków. Puste linie wypadają, bo pusty krok wyglądałby jak
 * zawieszenie animacji.
 */
function evk_anim_parse_words(string $text): array {
    $out = [];
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (count($out) >= 20) break;
        $word = sanitize_text_field(trim($line));
        if ($word !== '') $out[] = $word;
    }
    return $out;
}

/** Odwrotność evk_anim_parse_words() — z powrotem na tekst do pola w panelu. */
function evk_anim_words_to_text(array $words): string {
    return implode("\n", $words);
}

/** Odwrotność evk_anim_parse_props() — tablica z powrotem na tekst do pola w panelu. */
function evk_anim_props_to_text(array $props): string {
    $lines = [];
    foreach ($props as $prop => $value) {
        $lines[] = $prop . ': ' . $value;
    }
    return implode("\n", $lines);
}

/**
 * Wyzwalacze — klucz => etykieta w panelu.
 *
 * „Zamknięcie menu" jest OSOBNĄ pozycją, a nie wariantem wyjścia z kadru,
 * i to nie jest podział na wyrost: wyjście z kadru wisi na ScrollTriggerze
 * i mierzy opuszczanie okna, a przy zamykaniu menu żadnego kadru się nie
 * opuszcza — panel znika spod treści, która stoi w miejscu. Animacja z tym
 * wyzwalaczem NIE GRA sama z siebie; czeka, aż menu zawoła evkAnimatorExit().
 */
function evk_anim_triggers(): array {
    return [
        'viewport'   => 'Wejście w viewport',
        'exit'       => 'Wyjście z kadru',
        'menu-close' => 'Zamknięcie menu',
        'scrub'      => 'Scrub przy scrollu',
        'hover'      => 'Hover',
        'click'      => 'Klik',
        'load'       => 'Load strony',
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
        // Krzywe przyspieszające — dla wyjść z kadru. Wejście ma zwalniać ku
        // końcowi, wyjście odwrotnie, więc bez nich presety wyjściowe nie mają
        // czym zagrać (a sanityzacja odrzuciłaby wpisaną ręcznie wartość).
        'power2.in', 'power3.in',
    ];
}

/**
 * Ta sama krzywa, ale w zapisie, który rozumie CSS.
 *
 * Lista wyżej jest listą GSAP-a: `power2.out`, `back.out(1.7)`. Elementy
 * animowane przejściami CSS (menu offcanvas) dostają ją tą samą kontrolką,
 * bo jedna lista dla całej wtyczki znaczy, że użytkownik uczy się jednego
 * słownika. Tyle że CSS o nazwach GSAP-a nie ma pojęcia — a nieznana funkcja
 * czasu unieważnia CAŁĄ deklarację `transition`, razem z czasem trwania.
 * Wybranie krzywej gasiło więc przejście do zera: menu przestawało wyjeżdżać,
 * a panele przeskakiwały, przez co podmenu wyglądało, jakby zasłaniało
 * rodzica zamiast go wypychać. Zgłoszone z użycia (1.61.0).
 *
 * Zwraca pusty łańcuch dla wartości nieznanej albo pustej — wołający ma wtedy
 * NIE ustawiać zmiennej i zostawić domyślną krzywą z arkusza. Podstawienie
 * czegokolwiek na siłę byłoby gorsze: cicha zamiana krzywej jest trudniejsza
 * do zauważenia niż jej brak.
 *
 * `bounce` i `elastic` nie mają odpowiednika w `cubic-bezier()` — jedna krzywa
 * Béziera nie zawróci kilka razy. Idą jako `linear()` z próbkowania prawdziwego
 * wzoru GSAP-a (patrz niżej), więc odbicie jest odbiciem, a nie „czymś
 * miękkim". Starsza przeglądarka bez `linear()` odrzuci wartość — dlatego
 * strona sprawdza ją jeszcze przez `CSS.supports()`, zanim ją ustawi.
 */
function evk_anim_easing_css(string $gsap): string {
    $gsap = trim($gsap);
    if ($gsap === '') return '';

    // Odpowiedniki z easings.net. GSAP: power1 = quad, power2 = cubic,
    // power3 = quart, power4 = quint.
    static $map = [
        'none'         => 'linear',
        'power1.out'   => 'cubic-bezier(0.5, 1, 0.89, 1)',
        'power2.out'   => 'cubic-bezier(0.33, 1, 0.68, 1)',
        'power3.out'   => 'cubic-bezier(0.25, 1, 0.5, 1)',
        'power4.out'   => 'cubic-bezier(0.22, 1, 0.36, 1)',
        'power2.in'    => 'cubic-bezier(0.32, 0, 0.67, 0)',
        'power3.in'    => 'cubic-bezier(0.5, 0, 0.75, 0)',
        'power2.inOut' => 'cubic-bezier(0.65, 0, 0.35, 1)',
        'power3.inOut' => 'cubic-bezier(0.76, 0, 0.24, 1)',
        'back.out(1.7)' => 'cubic-bezier(0.34, 1.56, 0.64, 1)',
        'back.out(2.2)' => 'cubic-bezier(0.34, 1.8, 0.64, 1)',
        'expo.out'     => 'cubic-bezier(0.16, 1, 0.3, 1)',
        'circ.out'     => 'cubic-bezier(0, 0.55, 0.45, 1)',
        'sine.inOut'   => 'cubic-bezier(0.37, 0, 0.63, 1)',
    ];

    if (isset($map[$gsap])) return $map[$gsap];

    if ($gsap === 'bounce.out') {
        return evk_anim_easing_linear(static function (float $p): float {
            if ($p < 1 / 2.75)      { return 7.5625 * $p * $p; }
            if ($p < 2 / 2.75)      { $p -= 1.5   / 2.75; return 7.5625 * $p * $p + 0.75; }
            if ($p < 2.5 / 2.75)    { $p -= 2.25  / 2.75; return 7.5625 * $p * $p + 0.9375; }
            $p -= 2.625 / 2.75;     return 7.5625 * $p * $p + 0.984375;
        });
    }

    if (strpos($gsap, 'elastic.out') === 0) {
        // Wzór GSAP-a: amplituda 1, okres 0,5 — jedyny wariant na liście.
        $amp = 1.0; $period = 0.5;
        $s = $period / (2 * M_PI) * asin(1 / $amp);
        return evk_anim_easing_linear(static function (float $p) use ($amp, $period, $s): float {
            if ($p === 0.0 || $p === 1.0) return $p;
            return $amp * pow(2, -10 * $p) * sin(($p - $s) * (2 * M_PI) / $period) + 1;
        });
    }

    return '';
}

/**
 * Próbkuje krzywą do wartości `linear()`.
 *
 * `linear()` łączy próbki ODCINKAMI PROSTYMI, więc gęstość decyduje o tym, czy
 * ostre zawinięcia odbicia zostaną odbiciem. Czterdzieści próbek wystarcza,
 * a wartość mieści się w jednej linii pliku.
 */
function evk_anim_easing_linear(callable $fn, int $steps = 40): string {
    $out = [];
    for ($i = 0; $i <= $steps; $i++) {
        $out[] = rtrim(rtrim(number_format($fn($i / $steps), 4, '.', ''), '0'), '.') ?: '0';
    }
    return 'linear(' . implode(', ', $out) . ')';
}
