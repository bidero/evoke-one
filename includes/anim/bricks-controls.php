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

function evk_bgshift_controls_active(): bool {
    return function_exists('evk_bgshift_enabled') && evk_bgshift_enabled();
}

function evk_parallax_controls_active(): bool {
    if (!class_exists('EVK_Parallax')) return false;
    return !empty(EVK_Parallax::get_instance()->get_settings()['enabled']);
}

/**
 * Grupa, do której trafiają kontrolki Evoke ONE.
 *
 * Docelowo grupa Atrybuty Bricks — jako jedyna droga do paska skrótów po lewej.
 * Własna grupa go nie dostaje (patrz komentarz niżej), a grupa Atrybuty ma tam
 * swoją pozycję, więc dokładając się do niej jesteśmy w zasięgu jednego kliknięcia.
 *
 * Klucz wykrywany z tablicy $groups, którą i tak dostajemy z filtra — zamiast
 * zgadywać nazwę. Gdy żaden kandydat nie pasuje, wracamy do własnej grupy
 * (evk_bricks_control_groups() zarejestruje ją wtedy jako zapas).
 */
function evk_bricks_target_group(?array $groups = null): string {
    static $key = null;

    if ($groups !== null && $key === null) {
        foreach (['_attributes', 'attributes'] as $candidate) {
            if (array_key_exists($candidate, $groups)) { $key = $candidate; break; }
        }
        if ($key === null) $key = 'evk_one';
    }

    // Filtr 'controls' może teoretycznie odpalić przed 'control_groups' —
    // wtedy nie mamy jeszcze wykrycia i bierzemy najbardziej prawdopodobny klucz.
    return $key ?? '_attributes';
}

/** Kontrolki dzielą zakładkę z grupą, do której trafiają — Atrybuty żyją w Style. */
function evk_bricks_controls_tab(): string {
    return evk_bricks_target_group() === 'evk_one' ? 'content' : 'style';
}

/*
 * QUICK ACCESS BAR — droga zamknięta, świadomie nie próbujemy
 *
 * Pionowy pasek ikon po lewej to #bricks-panel-element-quick-access. Dump z żywej
 * instalacji pokazał pozycje o indeksach 0–8 BEZ LUKI: 0 = cała zakładka Treść,
 * 1–8 = grupy własne Bricks (Układ, Typografia, Tło, Obramowanie, Gradient,
 * Transformacja, CSS, Atrybuty). Grupy dodanej filtrem tam nie ma i nie ma po niej
 * miejsca — pasek budowany jest z zamkniętego zestawu Bricks.
 *
 * Wcześniejsza próba dorysowania ikony CSS-em była martwa z dwóch powodów naraz:
 * nie było <li> do złapania, a CSS wstrzykiwany przez wp_head trafia do dokumentu
 * ze stroną (iframe podglądu), nie do okna powłoki buildera, gdzie żyje panel.
 * Jedyną drogą byłoby wstrzykiwanie <li> JS-em do panelu Vue — kruche wobec
 * aktualizacji Bricks i nieproporcjonalne do zysku.
 *
 * Ikonę nagłówka samej grupy ustawia natomiast klucz 'icon' — patrz niżej.
 */

// =========================================================================
// REJESTRACJA FILTRÓW
// =========================================================================

add_action('init', function (): void {
    if (!class_exists('\Bricks\Elements')) return;
    if (!evk_anim_controls_active() && !evk_parallax_controls_active()
        && !evk_bgshift_controls_active()) return;

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
 * Kontrolki dokładamy do istniejącej grupy Atrybuty, więc zwykle NIE rejestrujemy
 * własnej grupy — tu tylko wykrywamy klucz docelowy i sprzątamy po starszych
 * wersjach, które własną grupę zakładały.
 *
 * Własna grupa powstaje wyłącznie awaryjnie: gdy w tablicy nie ma grupy Atrybuty
 * (inna wersja Bricks, inna nazwa klucza). Lepiej mieć sekcję w zakładce Content
 * niż kontrolki wskazujące na nieistniejącą grupę, czyli niewidoczne.
 */
function evk_bricks_control_groups($groups) {
    if (!is_array($groups)) return $groups;

    // Grupy z wersji 1.22.x–1.23.x — usuwane, żeby nie zostawały puste sekcje.
    unset($groups['evk_animator'], $groups['evk_parallax'], $groups['evk_one']);

    if (!evk_anim_controls_active() && !evk_parallax_controls_active()
        && !evk_bgshift_controls_active()) return $groups;

    if (evk_bricks_target_group($groups) === 'evk_one') {
        $groups['evk_one'] = [
            'title' => 'Evoke ONE',
            'tab'   => 'content',
            // Nazwa z wewnętrznego rejestru SVG Bricks — klucz działa, mimo że nie
            // ma go w dokumentacji filtra. Nazwy potwierdzone na żywej instalacji:
            // box, tab-layout, tab-typography, tab-background, tab-border,
            // tab-gradient, tab-transform, css3, html, arrow-left.
            'icon'  => 'tab-transform',
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
    if (evk_bgshift_controls_active()) {
        $controls = evk_bricks_bgshift_controls($controls);
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
        'group' => evk_bricks_target_group(),
        'type'  => 'separator',
        'label' => esc_html__('Evoke ONE — Animator', 'evoke-one'),
    ];

    // Lista zasilana biblioteką — nie da się wybrać animacji, której nie ma.
    $options = ['' => '— brak —'];
    foreach ((array) $settings['animations'] as $row) {
        $slug = $row['slug'] ?? '';
        if ($slug === '') continue;
        $label = ($row['label'] ?? '') !== '' ? $row['label'] : $slug;
        $options[$slug] = $label . ' (' . $slug . ')';
    }

    $trigger_options = ['' => '— z biblioteki —'];
    foreach (evk_anim_triggers() as $k => $label) {
        $trigger_options[$k] = $label;
    }

    $easing_options = ['' => esc_html__('— z biblioteki —', 'evoke-one')];
    foreach (evk_anim_easings() as $e) {
        $easing_options[$e] = $e;
    }

    /*
     * Wartości logiczne jako SELECT, nie checkbox.
     *
     * Checkbox ma dwa stany, a potrzebne są trzy: „z biblioteki", „tak" i „nie".
     * Odznaczony checkbox znaczyłby „nie" i odbierałby możliwość zwykłego
     * dziedziczenia — a jednocześnie nie dałoby się nim WYŁĄCZYĆ czegoś, co
     * w bibliotece jest włączone.
     */
    $bool_options = [
        ''  => esc_html__('— z biblioteki —', 'evoke-one'),
        '1' => esc_html__('Tak', 'evoke-one'),
        '0' => esc_html__('Nie', 'evoke-one'),
    ];

    /*
     * ── JEDNA lista, żadnych pól obok ─────────────────────────────────────
     *
     * Do 1.66.0 obok repeatera stał komplet płaskich kontrolek `evkAnim*` —
     * ta sama animacja dała się ustawić na dwa sposoby, a przy przenoszeniu
     * konfiguracji na inny element trzeba było przepisać KAŻDE pole z osobna.
     * Repeater umie wszystko, co umiały pola płaskie, więc pola zniknęły,
     * a ich role przejęły kolumny wiersza.
     *
     * `default => []` jest tu WARUNKIEM BEZPIECZEŃSTWA, nie preferencją.
     * Ta kontrolka wchodzi filtrem do KAŻDEGO zarejestrowanego elementu Bricks,
     * więc domyślny wiersz — jak w evoke-marquee, gdzie repeater jest własną
     * kontrolką treści jednego elementu — dołożyłby animację wszystkiemu
     * na stronie.
     *
     * Pola wiersza są BEZ prefiksu \`evk\`: repeater niesie własną przestrzeń
     * nazw, a evk_bricks_anim_cfg() czyta dokładnie te klucze.
     */
    $gate = ['animation', '!=', ''];

    $row_fields = [
        'animation' => [
            'label'   => esc_html__('Animacja', 'evoke-one'),
            'type'    => 'select',
            'options' => $options,
            'default' => '',
        ],
        'trigger' => [
            'label'    => esc_html__('Wyzwalacz', 'evoke-one'),
            'type'     => 'select',
            'options'  => $trigger_options,
            'default'  => '',
            'required' => $gate,
        ],
        'duration' => [
            'label'    => esc_html__('Czas (s)', 'evoke-one'),
            'type'     => 'number', 'min' => 0.05, 'max' => 10, 'step' => 0.05,
            'required' => $gate,
        ],
        'delay' => [
            'label'    => esc_html__('Opóźnienie (s)', 'evoke-one'),
            'type'     => 'number', 'min' => 0, 'max' => 10, 'step' => 0.05,
            'required' => $gate,
        ],
        'stagger' => [
            'label'    => esc_html__('Stagger (s)', 'evoke-one'),
            'type'     => 'number', 'min' => 0, 'max' => 2, 'step' => 0.005,
            'required' => $gate,
        ],
        'order' => [
            'label'       => esc_html__('Kolejność (tylko „Load")', 'evoke-one'),
            'type'        => 'number', 'min' => 0, 'max' => 999, 'step' => 1,
            'required'    => $gate,
        ],
        'start' => [
            'label'       => esc_html__('Start (ScrollTrigger)', 'evoke-one'),
            'type'        => 'text',
            'placeholder' => 'top 85%',
            'required'    => $gate,
        ],
        'end' => [
            'label'       => esc_html__('Koniec (scrub / wyjście)', 'evoke-one'),
            'type'        => 'text',
            'placeholder' => 'bottom top',
            'required'    => $gate,
        ],
        'scrub' => [
            'label'       => esc_html__('Scrub (tylko scrub)', 'evoke-one'),
            'type'        => 'number', 'min' => 0, 'max' => 5, 'step' => 0.1,
            'placeholder' => esc_html__('z biblioteki', 'evoke-one'),
            'required'    => $gate,
        ],
        'easing' => [
            'label'    => esc_html__('Easing', 'evoke-one'),
            'type'     => 'select',
            'options'  => $easing_options,
            'default'  => '',
            'required' => $gate,
        ],
        'targets' => [
            'label'   => esc_html__('Cel animacji', 'evoke-one'),
            'type'    => 'select',
            'options' => [
                ''         => esc_html__('— z biblioteki —', 'evoke-one'),
                'self'     => esc_html__('Sam element', 'evoke-one'),
                'children' => esc_html__('Dzieci elementu', 'evoke-one'),
                'selector' => esc_html__('Selektor w środku', 'evoke-one'),
                'external' => esc_html__('Element poza tym (cała strona)', 'evoke-one'),
            ],
            'default'  => '',
            'required' => $gate,
        ],
        /*
         * Selektor obsługuje oba cele — wewnętrzny i zewnętrzny — więc warunek
         * widoczności musi wymienić oba. Tablica jako trzeci człon \`required\`
         * znaczy w Bricksie „równa się KTÓREJKOLWIEK z tych wartości"; to jedyna
         * forma alternatywy, jaką dokumentacja Bricksa opisuje (przykład wprost
         * z niej: \`['layout', '=', ['list', 'grid']]\`).
         */
        'selector' => [
            'label'       => esc_html__('Selektor celu', 'evoke-one'),
            'type'        => 'text',
            'placeholder' => '.karta',
            'description' => esc_html__(
                'Przy celu „poza tym elementem" selektor przeszukuje całą stronę: '
                . 'przewinięcie do TEGO elementu animuje wskazany.',
                'evoke-one'
            ),
            'required'    => ['targets', '=', ['selector', 'external']],
        ],
        'repeat' => [
            'label'    => esc_html__('Powtarzaj przy każdym wejściu', 'evoke-one'),
            'type'     => 'select', 'options' => $bool_options, 'default' => '',
            'required' => $gate,
        ],
        'loop' => [
            'label'    => esc_html__('Zapętl', 'evoke-one'),
            'type'     => 'select', 'options' => $bool_options, 'default' => '',
            'required' => $gate,
        ],
        'loopYoyo' => [
            'label'    => esc_html__('Pętla z odbiciem', 'evoke-one'),
            'type'     => 'select', 'options' => $bool_options, 'default' => '',
            'required' => $gate,
        ],
        'pin' => [
            'label'    => esc_html__('Pin (tylko scrub)', 'evoke-one'),
            'type'     => 'select', 'options' => $bool_options, 'default' => '',
            'required' => $gate,
        ],
        // Lista słów ma sens per element — każdy może cyklować po innych.
        'words' => [
            'label'       => esc_html__('Słowa (preset „zmieniające się słowa")', 'evoke-one'),
            'type'        => 'textarea',
            'placeholder' => esc_html__('szybciej', 'evoke-one'),
            'description' => esc_html__('Po jednym słowie na linię. Puste = lista z biblioteki.', 'evoke-one'),
            'required'    => $gate,
        ],
    ];

    $controls['evkAnimList'] = [
        'tab'           => evk_bricks_controls_tab(),
        'group'         => evk_bricks_target_group(),
        'label'         => esc_html__('Animacje', 'evoke-one'),
        'type'          => 'repeater',
        'titleProperty' => 'animation',
        'default'       => [],
        'fields'        => $row_fields,
        'description'   => esc_html__(
            'Każdy wiersz to jedna animacja; pusty wiersz nic nie robi. Wyjście '
            . 'z kadru to zwykła pozycja listy — wybierz preset z grupy „Wyjścia" '
            . 'i wyzwalacz „Wyjście z kadru". '
            . 'PRZENIESIENIE NA INNY ELEMENT: prawy przycisk → „Kopiuj atrybuty" '
            . 'bierze tylko listę Atrybuty poniżej, nie tę kontrolkę. Wpisz tam '
            . 'atrybut data-evk-anim o wartości równej nazwie animacji (np. wjazd) '
            . 'albo JSON z dopasowaniami: {"animation":"wjazd","delay":0.2}. '
            . 'Kilka animacji naraz to tablica takich obiektów. Wpis ręczny '
            . 'WYGRYWA z tą listą, więc wklejenie atrybutów zawsze działa. '
            . 'Druga droga bez atrybutów: klasa evk-anim-nazwa na elemencie.',
            'evoke-one'
        ),
    ];

    return $controls;
}

function evk_bricks_bgshift_controls(array $controls): array {
    $controls['evkSepBgShift'] = [
        'tab'   => evk_bricks_controls_tab(),
        'group' => evk_bricks_target_group(),
        'type'  => 'separator',
        'label' => esc_html__('Evoke ONE — Tło przy scrollu', 'evoke-one'),
    ];

    $controls['evkBgShift'] = [
        'tab'         => evk_bricks_controls_tab(),
        'group'       => evk_bricks_target_group(),
        'label'       => esc_html__('Przenikaj tło przy scrollu', 'evoke-one'),
        'type'        => 'checkbox',
        'default'     => false,
        'description' => esc_html__('Sekcja oddaje swój kolor tła wspólnej warstwie pod stroną i sama robi się przezroczysta. Kolor przewija się płynnie do następnej takiej sekcji — bez szwu na granicy. Ustaw tło sekcji normalnie, także kolorem globalnym; nie ma osobnego pola na kolor. Sekcja z tłem graficznym albo gradientowym zostanie pominięta.', 'evoke-one'),
    ];

    /*
     * Moment przełączenia dla TEJ sekcji. Puste = wartość globalna z panelu,
     * i to jest ważniejsze niż wygląda: `data-evk-bg` był do 1.53.0 pustym
     * znacznikiem i taki niosą wszystkie istniejące strony. Pusta wartość musi
     * więc znaczyć dokładnie to, co znaczyła — „użyj globalnej".
     */
    $controls['evkBgShiftStart'] = [
        'tab'         => evk_bricks_controls_tab(),
        'group'       => evk_bricks_target_group(),
        'label'       => esc_html__('Początek przejścia (%)', 'evoke-one'),
        'type'        => 'number',
        'min'         => 0,
        'max'         => 200,
        'step'        => 5,
        'placeholder' => esc_html__('z ustawień globalnych', 'evoke-one'),
        'description' => esc_html__('Na jakiej wysokości ekranu TA sekcja przejmuje tło. 100 = gdy jej górna krawędź wjeżdża od dołu; mniej = zmiana następuje później.', 'evoke-one'),
        'required'    => ['evkBgShift', '=', true],
    ];

    /*
     * Kolor liter dla TEJ sekcji.
     *
     * Puste pole znaczy „dobierz sam z jasności tła" — ta sama konwencja, co
     * przy początku przejścia wyżej, gdzie puste znaczy „wartość globalna".
     * Dzięki temu wstawienie sekcji nie wymaga ustawiania niczego, a tam, gdzie
     * automat trafi nie tak, da się go nadpisać.
     */
    $controls['evkBgShiftText'] = [
        'tab'         => evk_bricks_controls_tab(),
        'group'       => evk_bricks_target_group(),
        'label'       => esc_html__('Kolor liter', 'evoke-one'),
        'type'        => 'color',
        'description' => esc_html__(
            'Kolor tekstu, gdy tło jest na tej sekcji — przenika razem z tłem. '
            . 'PUSTE = dobierany automatycznie z jasności tła, spośród dwóch kolorów '
            . 'z panelu (Frontend → Tło przy scrollu). '
            . 'Kolor schodzi dziedziczeniem, więc element z własnym kolorem ustawionym '
            . 'w builderze zostanie nietknięty — jeśli ma mimo to podążać za tłem, '
            . 'dodaj mu klasę evk-bg-text.',
            'evoke-one'
        ),
        'required'    => ['evkBgShift', '=', true],
    ];

    return $controls;
}

/**
 * Wartość z kontrolki koloru Bricks jako jeden łańcuch.
 *
 * Kontrolka niesie tablicę i nie zawsze te same klucze: `raw` przy kolorze
 * globalnym (`var(--marka)`), `rgb` przy wybranym z przezroczystością, `hex`
 * przy zwykłym. Kolejność ma znaczenie — `raw` jest najbliżej tego, co wybrał
 * użytkownik, i jako jedyny zachowuje powiązanie z kolorem globalnym, które
 * przy zmianie motywu ma się przeliczyć.
 */
function evk_bricks_color_value($color): string {
    if (is_string($color)) return trim($color);
    if (!is_array($color)) return '';
    foreach (['raw', 'rgb', 'hex'] as $k) {
        if (!empty($color[$k]) && is_string($color[$k])) return trim($color[$k]);
    }
    return '';
}

function evk_bricks_parallax_controls(array $controls): array {
    $controls['evkSepParallax'] = [
        'tab'   => evk_bricks_controls_tab(),
        'group' => evk_bricks_target_group(),
        'type'  => 'separator',
        'label' => esc_html__('Evoke ONE — Parallax', 'evoke-one'),
    ];

    $controls['evkParallax'] = [
        'tab'     => evk_bricks_controls_tab(),
        'group'   => evk_bricks_target_group(),
        'label'   => esc_html__('Włącz parallax', 'evoke-one'),
        'type'    => 'checkbox',
        'default' => false,
    ];

    // Placeholder pokazuje wartość globalną — puste pole ją właśnie oznacza.
    $controls['evkParallaxValue'] = [
        'tab'         => evk_bricks_controls_tab(),
        'group'       => evk_bricks_target_group(),
        'label'       => esc_html__('Siła', 'evoke-one'),
        'type'        => 'number',
        'min'         => -1,
        'max'         => 1,
        'step'        => 0.05,
        'placeholder' => (string) evk_get_parallax_value(),
        'description' => esc_html__(
            'Puste = wartość globalna z panelu Parallax. Na inny element przenosisz '
            . 'to przez „Kopiuj atrybuty": data-parallax (siła) i data-skala.',
            'evoke-one'
        ),
        'required'    => ['evkParallax', '=', true],
    ];

    $controls['evkParallaxScale'] = [
        'tab'         => evk_bricks_controls_tab(),
        'group'       => evk_bricks_target_group(),
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

/**
 * Konfiguracja JEDNEJ animacji z wiersza ustawień.
 *
 * Klucze wiersza są BEZ prefiksu `evk` — takie niesie repeater. Ścieżka
 * z płaskimi kontrolkami mapuje na nie swoje `evkAnim*` i woła to samo,
 * więc obie drogi dają identyczny kształt i nie ma dwóch miejsc do rozejścia.
 *
 * Zwraca pustą tablicę, gdy wiersz nie ma wybranej animacji — wiersz bez niej
 * to wiersz, którego użytkownik nie wypełnił, a nie konfiguracja „domyślna".
 */
function evk_bricks_anim_cfg(array $row): array {
    if (empty($row['animation'])) return [];

    $cfg = ['animation' => sanitize_key($row['animation'])];

    // Tylko realnie wypełnione pola — pusty klucz w JSON przesłoniłby
    // wartość z biblioteki (silnik pomija '' , ale nie 0).
    if (!empty($row['trigger'])) {
        $cfg['trigger'] = sanitize_key($row['trigger']);
    }
    foreach (['duration', 'delay', 'stagger'] as $prop) {
        if (isset($row[$prop]) && $row[$prop] !== '') $cfg[$prop] = floatval($row[$prop]);
    }
    // Osobno, bo kolejność to numer kroku — pętla obok rzutuje na float.
    if (isset($row['order']) && $row['order'] !== '') {
        $cfg['order'] = intval($row['order']);
    }
    if (isset($row['scrub']) && $row['scrub'] !== '') {
        $cfg['scrub'] = floatval($row['scrub']);
    }

    foreach (['start', 'end', 'easing', 'targets', 'selector'] as $prop) {
        if (!empty($row[$prop])) $cfg[$prop] = sanitize_text_field($row[$prop]);
    }

    /*
     * Wartości logiczne przez !== '', NIE przez !empty().
     *
     * Kontrolka jest trójstanowa i wysyła '' / '1' / '0'. Dla !empty()
     * ciąg '0' jest PUSTY, więc jawne „Nie" wypadałoby tak samo jak
     * „z biblioteki" — nie dałoby się wyłączyć w elemencie czegoś, co
     * w bibliotece jest włączone. Ta sama pułapka co przy „Kolejności"
     * równej zero, domknięta w 1.28.1.
     */
    foreach (['repeat', 'loop', 'loopYoyo', 'pin'] as $prop) {
        if (isset($row[$prop]) && $row[$prop] !== '') $cfg[$prop] = $row[$prop] ? 1 : 0;
    }

    if (!empty($row['words'])) {
        $words = evk_anim_parse_words((string) $row['words']);
        if ($words) $cfg['words'] = $words;
    }

    return $cfg;
}

/**
 * Wszystkie konfiguracje animacji elementu.
 *
 * Jedno źródło: repeater. Do 1.66.0 istniała druga droga — komplet płaskich
 * kontrolek `evkAnim*` — i to ona wymuszała przepisywanie każdego pola
 * z osobna przy przenoszeniu ustawień na inny element. Wtyczka była wtedy
 * dopiero w testach, więc zniknęła razem z danymi, które niosła.
 */
function evk_bricks_anim_cfgs(array $s): array {
    if (empty($s['evkAnimList']) || !is_array($s['evkAnimList'])) return [];

    $out = [];
    // Konfigurację składa evk_bricks_anim_cfg() z WHITELISTY kluczy, nie
    // `foreach ($row as ...)`: builder dokłada wierszom repeatera własne pola
    // (m.in. `id`), a te nie mają prawa wyjść na stronę.
    foreach ((array) $s['evkAnimList'] as $row) {
        if (!is_array($row)) continue;
        $cfg = evk_bricks_anim_cfg($row);
        if ($cfg) $out[] = $cfg;
    }
    return $out;
}

/**
 * Czy element ma TEN atrybut wpisany ręcznie w kontrolce Atrybuty Bricksa.
 *
 * To jest droga, którą kopiuje się ustawienia z elementu na element: prawy
 * przycisk → „Kopiuj atrybuty" bierze WYŁĄCZNIE natywną kontrolkę
 * `_attributes` (schowek niesie `source: bricksCopiedElementAttributes`),
 * a nie kontrolki dokładane przez wtyczki. Skoro oba silniki Evoke i tak
 * czytają zwykłe atrybuty `data-*`, ta droga działa bez niczego po naszej
 * stronie — trzeba jej tylko NIE PSUĆ.
 *
 * Dlatego wpis ręczny WYGRYWA z kontrolkami: kto właśnie wkleił atrybuty,
 * oczekuje, że zadziałają. Bez tej bramki wynik zależałby od kolejności,
 * w jakiej Bricks nakłada `_attributes` i filtr `render_attributes` — a tej
 * kolejności nie kontrolujemy.
 */
function evk_bricks_attr_declared(array $s, string $name): bool {
    if (empty($s['_attributes']) || !is_array($s['_attributes'])) return false;
    foreach ($s['_attributes'] as $row) {
        if (is_array($row) && ($row['name'] ?? '') === $name) return true;
    }
    return false;
}

add_filter('bricks/element/render_attributes', function ($attributes, $key, $element) {
    if ($key !== '_root' || !is_array($attributes)) return $attributes;

    $s = (array) ($element->settings ?? []);

    // ── Animator ──────────────────────────────────────────────────────────
    if (evk_anim_controls_active() && !evk_bricks_attr_declared($s, 'data-evk-anim')) {
        $cfgs = evk_bricks_anim_cfgs($s);
        if ($cfgs) {
            /*
             * Jedna animacja jedzie jako OBIEKT, nie jednoelementowa tablica.
             *
             * Trzy linie, które kupują odporność na najczęstszy układ przy
             * aktualizacji: nowe PHP już działa, a `animator.js` siedzi jeszcze
             * w cache przeglądarki albo za CDN-em. Stary silnik uznaje JSON
             * zaczynający się od `[` za goły slug i element przestaje się
             * animować bez śladu w konsoli.
             */
            $payload = count($cfgs) === 1 ? $cfgs[0] : $cfgs;
            $attributes = evk_bricks_set_attr($attributes, $key, 'data-evk-anim', wp_json_encode($payload));
        }
    }

    // ── Tło przy scrollu ──────────────────────────────────────────────────
    // Sam znacznik — kolor silnik odczytuje z getComputedStyle sekcji, więc nie
    // ma tu czego przekazywać i nie powstaje drugie źródło prawdy.
    if (evk_bgshift_controls_active() && !empty($s['evkBgShift'])
        && !evk_bricks_attr_declared($s, 'data-evk-bg')) {
        // Pusty atrybut to nadal „użyj wartości globalnej" — silnik czyta go
        // tak samo jak brak liczby, więc strony sprzed 1.53.0 się nie zmieniają.
        $bg_start = '';
        if (isset($s['evkBgShiftStart']) && $s['evkBgShiftStart'] !== '') {
            $bg_start = (string) max(0, min(200, intval($s['evkBgShiftStart'])));
        }
        $attributes = evk_bricks_set_attr($attributes, $key, 'data-evk-bg', $bg_start);

        /* Kolor liter — atrybut powstaje TYLKO gdy kontrolka jest wypełniona.
           Pusty atrybut nie znaczy tu „wartość globalna" jak przy początku
           przejścia, tylko byłby kolorem pustym; brak atrybutu to sygnał
           „dobierz z jasności tła", a to jest zachowanie domyślne. */
        $text = evk_bricks_color_value($s['evkBgShiftText'] ?? null);
        if ($text !== '' && !evk_bricks_attr_declared($s, 'data-evk-bg-text')) {
            $attributes = evk_bricks_set_attr($attributes, $key, 'data-evk-bg-text', $text);
        }
    }

    // ── Parallax ──────────────────────────────────────────────────────────
    // Pusty atrybut jest znaczący: assets/js/parallax.js czyta go jako
    // „użyj wartości globalnej", więc nie wypełniamy go domyślnymi tutaj.
    if (evk_parallax_controls_active() && !empty($s['evkParallax'])
        && !evk_bricks_attr_declared($s, 'data-parallax')) {
        $value = (isset($s['evkParallaxValue']) && $s['evkParallaxValue'] !== '')
            ? (string) floatval($s['evkParallaxValue']) : '';
        $scale = (isset($s['evkParallaxScale']) && $s['evkParallaxScale'] !== '')
            ? (string) floatval($s['evkParallaxScale']) : '';

        $attributes = evk_bricks_set_attr($attributes, $key, 'data-parallax', $value);
        $attributes = evk_bricks_set_attr($attributes, $key, 'data-skala',    $scale);
    }

    return $attributes;
}, 10, 3);
