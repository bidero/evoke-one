<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Elementy Bricks (zintegrowane elementy + wspólne biblioteki)
 *
 * - Każdy element ma osobny włącznik (opcja evk_elements, domyślnie OFF).
 * - Wszystkie elementy trafiają do własnej grupy „Evoke ONE" w builderze
 *   (EVK_BRICKS_CATEGORY) — nie mieszają się z elementami Bricks.
 * - Etykieta elementu w builderze = etykieta z evk_elements_registry().
 *   Zmieniając jedną, zmień drugą — nazwy mają być spójne w panelu i builderze.
 * - Wspólne biblioteki (GSAP / ScrollTrigger / Observer) rejestrowane raz pod
 *   stałymi handle'ami — dzięki temu nie dublują się między elementami.
 * - Guard coexistence: jeśli samodzielna wtyczka elementu jest aktywna
 *   (klasa już istnieje), Evoke ONE pomija rejestrację (zero konfliktu).
 */

/** Slug grupy elementów w builderze Bricks. */
const EVK_BRICKS_CATEGORY = 'evoke-one';

/** Nazwa grupy widoczna w panelu elementów Bricks. */
add_filter('bricks/builder/i18n', function ($i18n) {
    if (!is_array($i18n)) return $i18n;
    $i18n[EVK_BRICKS_CATEGORY] = 'Evoke ONE';
    return $i18n;
});

function evk_elements_registry(): array {
    $dir = EVOKE_ONE_DIR . 'includes/bricks-elements/';
    $url = EVOKE_ONE_URL . 'includes/bricks-elements/';

    return [
        'marquee' => [
            'label' => 'Marquee',
            'desc'  => 'Nieskończony marquee z przyspieszeniem przy scrollu.',
            'icon'  => 'dashicons-controls-repeat',
            'class' => 'Evk_Marquee_Element',
            'name'  => 'evk-marquee',
            'file'  => $dir . 'evoke-marquee/element.php',
            'consts'=> [
                'EVK_MARQUEE_VERSION' => '1.6.0',
                'EVK_MARQUEE_URL'     => $url . 'evoke-marquee/',
                'EVK_MARQUEE_PATH'    => $dir . 'evoke-marquee/',
            ],
            /* `evk-scrolltrigger` NIE jest tu na wyrost: marquee używa i Observera
               (prędkość przewijania), i ScrollTriggera (zatrzymanie poza kadrem).
               Brakowało tego drugiego, więc na KAŻDEJ stronie z marquee wchodził
               loader awaryjny w marquee.js i dociągał ScrollTriggera z cdnjs —
               osobne DNS + TCP + TLS, i to dopiero po wykonaniu skryptu. */
            'script'=> ['evk-marquee', $url . 'evoke-marquee/assets/marquee.js', ['evk-gsap', 'evk-observer', 'evk-scrolltrigger'], '1.6.0'],
            'style' => ['evk-marquee', $url . 'evoke-marquee/assets/marquee.css', '1.5.1'],
        ],
        'hscroll' => [
            'label' => 'Horizontal Scroll',
            'desc'  => 'Poziomy scroll z GSAP ScrollTrigger (pin + snap).',
            'icon'  => 'dashicons-leftright',
            'class' => 'Evk_Horizontal_Scroll_Element',
            'name'  => 'evk-horizontal-scroll',
            'file'  => $dir . 'evoke-horizontal-scroll/element.php',
            'consts'=> [
                'EVK_HSCROLL_VERSION' => '1.1.2',
                'EVK_HSCROLL_URL'     => $url . 'evoke-horizontal-scroll/',
                'EVK_HSCROLL_PATH'    => $dir . 'evoke-horizontal-scroll/',
            ],
            'script'=> ['evk-horizontal-scroll', $url . 'evoke-horizontal-scroll/assets/hscroll.js', ['evk-gsap', 'evk-scrolltrigger'], '1.1.3'],
            'style' => ['evk-horizontal-scroll', $url . 'evoke-horizontal-scroll/assets/hscroll.css', '1.1.1'],
        ],
        'scroll_reading' => [
            'label' => 'Scroll Reading',
            'desc'  => 'Tekst rozjaśniany przy scrollu (SplitText z Bricks Animator).',
            'icon'  => 'dashicons-editor-textcolor',
            'class' => 'Evk_Scroll_Reading_Element',
            'name'  => 'evk-scroll-reading',
            'file'  => $dir . 'evoke-scroll-reading/element.php',
            'consts'=> [
                'EVK_SR_VERSION' => '1.2.0',
                'EVK_SR_URL'     => $url . 'evoke-scroll-reading/',
                'EVK_SR_PATH'    => $dir . 'evoke-scroll-reading/',
            ],
            'script'=> ['evk-scroll-reading', $url . 'evoke-scroll-reading/assets/scroll-reading.js', ['evk-gsap', 'evk-scrolltrigger', 'evk-splittext'], '1.2.0'],
            'style' => ['evk-scroll-reading', $url . 'evoke-scroll-reading/assets/scroll-reading.css', '1.2.0'],
        ],
        'circular_title' => [
            'label' => 'Circular Title',
            'desc'  => 'Tekst po okręgu reagujący na prędkość scrolla (Lenis).',
            'icon'  => 'dashicons-image-rotate',
            'class' => 'Evk_Circular_Title',
            'name'  => '', // register_element(file) — Bricks odczyta klasę z pliku
            'file'  => $dir . 'evoke-circular-title/element.php',
            'consts'=> [
                'EVK_CIRCULAR_VERSION' => '1.1.5',
                'EVK_CIRCULAR_URL'     => $url . 'evoke-circular-title/',
                'EVK_CIRCULAR_PATH'    => $dir . 'evoke-circular-title/',
            ],
            // asety: self-enqueue w element.php (EVK_CIRCULAR_URL)
        ],
        'circular_menu' => [
            'label' => 'Circular Menu',
            'desc'  => 'Menu z animacją clip-path (portal do body).',
            'icon'  => 'dashicons-menu-alt',
            'class' => 'Evk_Circular_Menu',
            // Klasa żyje w namespace Bricks — guard musi sprawdzić obie formy,
            // inaczej samodzielna wtyczka nigdy nie zostałaby wykryta.
            'guard' => ['Evk_Circular_Menu', 'Bricks\\Evk_Circular_Menu'],
            'name'  => 'evk-circular-menu',
            'file'  => $dir . 'evoke-circular-menu/element.php',
            'consts'=> [
                'EVK_CIRCULAR_MENU_VERSION' => '1.9.0',
                'EVK_CIRCULAR_MENU_URL'     => $url . 'evoke-circular-menu/',
                'EVK_CIRCULAR_MENU_PATH'    => $dir . 'evoke-circular-menu/',
            ],
            // asety: self-enqueue w element.php (EVK_CIRCULAR_MENU_URL), gsap -> evk-gsap
        ],
        'offcanvas_menu' => [
            'label' => 'Offcanvas Menu',
            'desc'  => 'Wysuwane menu: jeden swobodny panel albo kilka poziomów.',
            'icon'  => 'dashicons-align-pull-left',
            'class' => 'Evk_Offcanvas_Menu',
            'guard' => ['Evk_Offcanvas_Menu', 'Bricks\\Evk_Offcanvas_Menu'],
            'name'  => 'evk-offcanvas-menu',
            'file'  => $dir . 'evoke-offcanvas-menu/element.php',
            'consts'=> [
                'EVK_OFFCANVAS_MENU_VERSION' => '1.11.0',
                'EVK_OFFCANVAS_MENU_URL'     => $url . 'evoke-offcanvas-menu/',
                'EVK_OFFCANVAS_MENU_PATH'    => $dir . 'evoke-offcanvas-menu/',
            ],
            // asety: self-enqueue w element.php (EVK_OFFCANVAS_MENU_URL)
        ],
        'burger' => [
            'label' => 'Burger',
            'desc'  => 'Animowany przycisk menu. Stan CZYTA z menu, nie trzyma własnego.',
            'icon'  => 'dashicons-menu',
            'class' => 'Evk_Burger',
            'guard' => ['Evk_Burger', 'Bricks\\Evk_Burger'],
            'name'  => 'evk-burger',
            'file'  => $dir . 'evoke-burger/element.php',
            'consts'=> [
                'EVK_BURGER_VERSION' => '1.8.0',
                'EVK_BURGER_URL'     => $url . 'evoke-burger/',
                'EVK_BURGER_PATH'    => $dir . 'evoke-burger/',
            ],
            'script'=> ['evk-burger', $url . 'evoke-burger/assets/burger.js', ['bricks-scripts'], '1.1.0'],
            'style' => ['evk-burger', $url . 'evoke-burger/assets/burger.css', '1.8.0'],
        ],
        'stacking_cards' => [
            'label' => 'Stacking Cards',
            'desc'  => 'Karty nakładające się przy scrollu (position: sticky, bez pinu).',
            'icon'  => 'dashicons-images-alt2',
            'class' => 'Evk_Stacking_Cards_Element',
            'name'  => 'evk-stacking-cards',
            'file'  => $dir . 'evoke-stacking-cards/element.php',
            'consts'=> [
                'EVK_SC_VERSION' => '1.3.1',
                'EVK_SC_URL'     => $url . 'evoke-stacking-cards/',
                'EVK_SC_PATH'    => $dir . 'evoke-stacking-cards/',
            ],
            'script'=> ['evk-stacking-cards', $url . 'evoke-stacking-cards/assets/stacking-cards.js', ['evk-gsap', 'evk-scrolltrigger'], '1.3.1'],
            'style' => ['evk-stacking-cards', $url . 'evoke-stacking-cards/assets/stacking-cards.css', '1.3.0'],
        ],
        'wave_bg' => [
            'label' => 'Wave Background',
            'desc'  => 'Animowane tło gradientowe Three.js (samodzielny moduł ESM).',
            'icon'  => 'dashicons-art',
            'class' => 'Evk_Wave_Bg_Element',
            'name'  => 'evk-wave-bg',
            'file'  => $dir . 'evoke-wave-bg/element.php',
            'consts'=> [
                'EVK_WB_VERSION' => '1.3.0',
                'EVK_WB_PATH'    => $dir . 'evoke-wave-bg/',
            ],
            // asety: self-contained ESM w render()
        ],
    ];
}

function evk_elements_enabled(): array {
    $def = ['marquee' => 0, 'hscroll' => 0, 'scroll_reading' => 0, 'circular_title' => 0, 'circular_menu' => 0, 'offcanvas_menu' => 0, 'stacking_cards' => 0, 'wave_bg' => 0];
    return array_merge($def, (array) get_option('evk_elements', []));
}

/**
 * Czy klasa elementu pochodzi z samodzielnej wtyczki (już załadowana)?
 * Sprawdza wszystkie warianty nazwy klasy z wpisu rejestru.
 */
function evk_element_class_loaded(array $el): bool {
    foreach ((array) ($el['guard'] ?? [$el['class']]) as $class) {
        if (class_exists($class)) return true;
    }
    return false;
}

// ── Rejestracja elementów w Bricks (tylko włączone) ──────────────────────
add_action('init', function (): void {
    if (!class_exists('\Bricks\Elements')) return;

    $reg = evk_elements_registry();
    $en  = evk_elements_enabled();
    $GLOBALS['evk_loaded_elements'] = [];

    foreach ($reg as $key => $el) {
        if (empty($en[$key])) continue;
        if (evk_element_class_loaded($el)) continue;   // samodzielna wtyczka aktywna → pomiń
        if (!is_readable($el['file'])) continue;

        foreach ($el['consts'] as $c => $v) {
            if (!defined($c)) define($c, $v);
        }

        require_once $el['file'];

        if (!empty($el['name'])) {
            \Bricks\Elements::register_element($el['file'], $el['name'], $el['class']);
        } else {
            \Bricks\Elements::register_element($el['file']);
        }

        $GLOBALS['evk_loaded_elements'][$key] = true;
    }
}, 11);

// ── Wspólne biblioteki + skrypty/style elementów ─────────────────────────
add_action('wp_enqueue_scripts', function (): void {
    if (!class_exists('\Bricks\Frontend')) return;

    // Wspólne biblioteki GSAP — rejestrowane w includes/89-gsap.php, żeby
    // Animator (nie będący elementem Bricks) korzystał z tych samych handle'ów.
    evk_register_gsap_libs();

    // Handle skryptów/stylów, których oczekuje element->enqueue_scripts()
    $reg    = evk_elements_registry();
    $loaded = $GLOBALS['evk_loaded_elements'] ?? [];

    foreach ($reg as $key => $el) {
        if (empty($loaded[$key])) continue;
        if (!empty($el['script'])) {
            wp_register_script($el['script'][0], $el['script'][1], $el['script'][2], $el['script'][3], true);
        }
        if (!empty($el['style'])) {
            wp_register_style($el['style'][0], $el['style'][1], [], $el['style'][2]);
        }
    }
}, 5);
