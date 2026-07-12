<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł Smooth Scroll (Lenis)
 */

class EVK_Lenis {

    private static $instance = null;

    private $defaults = [
        'enabled'             => 0,
        'auto_raf'            => 1,
        'duration'            => 1.0,
        'lerp'                => 0.1,
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
            'lerp'             => [0.01, 1.0,   0.1],
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
        echo '<style id="evk-lenis-css">
html.lenis,html.lenis body{height:auto;}
.lenis.lenis-smooth{scroll-behavior:auto!important;}
.lenis.lenis-smooth [data-lenis-prevent]{overscroll-behavior:contain;}
.lenis.lenis-stopped{overflow:hidden;}
.lenis.lenis-scrolling iframe{pointer-events:none;}
</style>';
    }

    public function enqueue_assets(): void {
        $s = $this->get_settings();
        if (empty($s['enabled'])) return;
        if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) return;
        if (is_admin()) return;

        wp_enqueue_script(
            'evk-lenis-lib',
            'https://unpkg.com/@studio-freight/lenis@1.0.42/dist/lenis.min.js',
            [],
            '1.0.42',
            true
        );

        $js = sprintf(
            "document.addEventListener('DOMContentLoaded',function(){
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
    if (%s) {
        function raf(time){ lenis.raf(time); requestAnimationFrame(raf); }
        requestAnimationFrame(raf);
    }
    document.querySelectorAll('a[href^=\"#\"]').forEach(function(anchor){
        anchor.addEventListener('click', function(e){
            e.preventDefault();
            lenis.scrollTo(this.getAttribute('href'));
        });
    });
    window.evkLenis = lenis;
});",
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
            $s['overscroll']   ? 'true' : 'false',
            $s['auto_raf']     ? 'true' : 'false'
        );

        wp_add_inline_script('evk-lenis-lib', $js);
    }
}

EVK_Lenis::get_instance();
