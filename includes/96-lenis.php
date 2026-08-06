<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł Smooth Scroll (Lenis)
 *
 * Paczka `@studio-freight/lenis` została porzucona na 1.0.42 — biblioteka żyje
 * dalej pod nazwą `lenis`. Stara wersja nie znała `touchInertiaExponent`ani
 * `overscroll`, więc dwie kontrolki w panelu były martwe: widełki suwaka
 * bezwładności (1.0–5.0, domyślnie 1.7) opisują opcję z 1.3, nie z 1.0.42.
 */

/** Wersja Lenisa — pojedyncze miejsce dla URL-a i cache-bustera. */
const EVK_LENIS_VERSION = '1.3.26';

class EVK_Lenis {

    private static $instance = null;

    private $defaults = [
        'enabled'             => 0,
        'auto_raf'            => 1,
        'duration'            => 1.0,
        'lerp'                => 0.08,
        'wheel_multiplier'    => 1.0,
        'smooth_wheel'        => 1,
        'orientation'         => 'vertical',
        'gesture_orientation' => 'vertical',
        'sync_touch'          => 0,
        'sync_touch_lerp'     => 0.075,
        'touch_multiplier'    => 1.0,
        'touch_inertia'       => 1.7,
        'infinite'            => 0,
        'overscroll'          => 1,
    ];

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        if (empty($this->get_settings()['enabled'])) return; // nie ładuj asetów gdy wyłączone
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 20);
        add_action('wp_head',            [$this, 'render_css'], 10);
    }

    public function get_settings(): array {
        return wp_parse_args(get_option('evk_lenis', []), $this->defaults);
    }

    public function register_settings(): void {
        register_setting('evoke_one_lenis', 'evk_lenis', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input): array {
        $clean = [];

        // 'enabled' zarządzany przez AJAX toggle — zachowaj gdy brak w POST
        $clean['enabled'] = evk_preserve_toggle($input, 'evk_lenis');

        foreach (['auto_raf', 'smooth_wheel', 'sync_touch', 'infinite', 'overscroll'] as $key) {
            $clean[$key] = !empty($input[$key]) ? 1 : 0;
        }

        $floats = [
            'duration'         => [0.1, 10.0,  1.0],
            'lerp'             => [0.01, 1.0,   0.08],
            'wheel_multiplier' => [0.1, 10.0,  1.0],
            'sync_touch_lerp'  => [0.01, 1.0,   0.075],
            'touch_multiplier' => [0.1, 10.0,  1.0],
            'touch_inertia'    => [1.0,  5.0,   1.7],
        ];
        foreach ($floats as $key => [$min, $max, $default]) {
            $clean[$key] = isset($input[$key])
                ? max($min, min($max, floatval($input[$key])))
                : $default;
        }

        $allowed = ['vertical', 'horizontal'];
        $clean['orientation'] = in_array($input['orientation'] ?? '', $allowed, true)
            ? $input['orientation'] : 'vertical';
        $clean['gesture_orientation'] = in_array($input['gesture_orientation'] ?? '', $allowed, true)
            ? $input['gesture_orientation'] : 'vertical';

        return $clean;
    }

    public function render_css(): void {
        $s = $this->get_settings();
        if (empty($s['enabled'])) return;
        if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) return;
        // Odpowiednik dist/lenis.css z przypiętej wersji — trzymamy go inline,
        // żeby nie dokładać osobnego żądania. Przy podbiciu Lenisa sprawdź,
        // czy arkusz się nie zmienił: https://unpkg.com/lenis/dist/lenis.css
        echo '<style id="evk-lenis-css">
html.lenis,html.lenis body{height:auto;}
.lenis:not(.lenis-autoToggle).lenis-stopped{overflow:clip;}
.lenis [data-lenis-prevent],
.lenis [data-lenis-prevent-wheel],
.lenis [data-lenis-prevent-touch],
.lenis [data-lenis-prevent-vertical],
.lenis [data-lenis-prevent-horizontal]{overscroll-behavior:contain;}
.lenis.lenis-smooth iframe{pointer-events:none;}
.lenis.lenis-autoToggle{transition-property:overflow;transition-duration:1ms;transition-behavior:allow-discrete;}
</style>';
    }

    public function enqueue_assets(): void {
        $s = $this->get_settings();
        if (empty($s['enabled'])) return;
        if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) return;
        if (is_admin()) return;

        wp_enqueue_script(
            'evk-lenis-lib',
            'https://unpkg.com/lenis@' . EVK_LENIS_VERSION . '/dist/lenis.min.js',
            [],
            EVK_LENIS_VERSION,
            true
        );

        $js = sprintf(
            "document.addEventListener('DOMContentLoaded',function(){
    var autoRaf = %s;

    // Redukcja ruchu: przewijanie zostaje natywne. Lenis nie powstaje w ogóle —
    // wygładzanie zmienia tempo każdego ruchu strony, więc nie da się go
    // „przyciszyć”, można tylko z niego zrezygnować. Kotwice działają dalej,
    // bo bez Lenisa obsługuje je przeglądarka.
    // Wspólna polityka: includes/anim/motion.php.
    var evkReduced = (window.evkMotion && typeof window.evkMotion.reduced === 'function')
        ? window.evkMotion.reduced()
        : !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    if (evkReduced) return;

    var lenis = new Lenis({
        duration: %s,
        lerp: %s,
        wheelMultiplier: %s,
        smoothWheel: %s,
        orientation: '%s',
        gestureOrientation: '%s',
        syncTouch: %s,
        syncTouchLerp: %s,
        touchMultiplier: %s,
        touchInertiaExponent: %s,
        infinite: %s,
        overscroll: %s,
    });
    window.evkLenis = lenis;

    var rafId = null;
    function raf(time){ lenis.raf(time); rafId = requestAnimationFrame(raf); }
    if (autoRaf) rafId = requestAnimationFrame(raf);

    // Spięcie z GSAP-em. Bez lenis.on('scroll', ScrollTrigger.update) ScrollTrigger
    // polega na natywnych zdarzeniach scrolla, które Lenis modyfikuje — scrub
    // zostaje w tyle za obrazem. Bez wspólnego tickera lecą dwie niezależne pętle
    // rAF, każda z własnym czasem. Lenis i GSAP jadą z dwóch różnych CDN-ów i oba
    // lądują w stopce, więc kolejność nie jest gwarantowana — stąd odpytanie.
    function when(test, done, tries){
        tries = tries || 0;
        if (test()) { done(); return; }
        if (tries < 50) setTimeout(function(){ when(test, done, tries + 1); }, 100);
    }
    when(function(){ return window.ScrollTrigger; }, function(){
        lenis.on('scroll', ScrollTrigger.update);
    });
    when(function(){ return window.gsap; }, function(){
        if (!autoRaf) return;   // pętlę prowadzi ktoś inny — nie odbieramy jej
        if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
        gsap.ticker.add(function(t){ lenis.raf(t * 1000); });
        gsap.ticker.lagSmoothing(0);
    });

    document.querySelectorAll('a[href^=\"#\"]').forEach(function(anchor){
        anchor.addEventListener('click', function(e){
            e.preventDefault();
            lenis.scrollTo(this.getAttribute('href'));
        });
    });
});",
            $s['auto_raf']     ? 'true' : 'false',
            number_format($s['duration'],         2, '.', ''),
            number_format($s['lerp'],             3, '.', ''),
            number_format($s['wheel_multiplier'], 2, '.', ''),
            $s['smooth_wheel'] ? 'true' : 'false',
            esc_js($s['orientation']),
            esc_js($s['gesture_orientation']),
            $s['sync_touch']   ? 'true' : 'false',
            number_format($s['sync_touch_lerp'],  3, '.', ''),
            number_format($s['touch_multiplier'], 2, '.', ''),
            number_format($s['touch_inertia'],    2, '.', ''),
            $s['infinite']     ? 'true' : 'false',
            $s['overscroll']   ? 'true' : 'false'
        );

        wp_add_inline_script('evk-lenis-lib', $js);
    }
}

EVK_Lenis::get_instance();
