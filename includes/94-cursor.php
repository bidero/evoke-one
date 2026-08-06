<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł Kursora
 */

class EVK_Cursor {

    private static $instance = null;

    private $cursor_defaults = [
        'size'                 => 16,
        'background_color'     => 'white',
        'blend_mode'           => 'exclusion',
        'backdrop_filter'      => 'blur(0px)',
        'inertia'              => 0.5,
        'enter_duration'       => 0.6,
        'leave_duration'       => 0.3,
        'mobile_breakpoint'    => 1024,
        'hide_native'          => 1,
        'restore_on_inputs'    => 1,
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
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 99);
    }

    public function get_default_elements(): array {
        return [];
    }

    public function get_settings(): array {
        $saved    = get_option('evk_cursor', []);
        $defaults = [
            'enabled'        => 0,
            'elements'       => $this->get_default_elements(),
            'cursor_default' => $this->cursor_defaults,
        ];
        $settings = wp_parse_args($saved, $defaults);
        $settings['cursor_default'] = wp_parse_args(
            $settings['cursor_default'] ?? [],
            $this->cursor_defaults
        );
        return $settings;
    }

    public function register_settings(): void {
        register_setting('evoke_one_cursor', 'evk_cursor', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input): array {
        $clean            = [];
        $clean['enabled'] = evk_preserve_toggle($input, 'evk_cursor');
        $clean['elements'] = [];

        if (!empty($input['elements']) && is_array($input['elements'])) {
            foreach ($input['elements'] as $el) {
                if (empty(trim($el['selector']))) continue;
                $clean['elements'][] = [
                    'selector'             => sanitize_text_field($el['selector']),
                    'size'                 => max(10, intval($el['size'])),
                    'text'                 => wp_kses_post($el['text']),
                    'backgroundColor'      => sanitize_text_field($el['backgroundColor']),
                    'cursorBlendMode'      => sanitize_text_field($el['cursorBlendMode']),
                    'cursorBackdropFilter' => sanitize_text_field($el['cursorBackdropFilter']),
                    'textBlendMode'        => sanitize_text_field($el['textBlendMode']),
                    'textColor'            => sanitize_text_field($el['textColor']),
                    'arrows'               => !empty($el['arrows']) ? 1 : 0,
                    'invert'               => !empty($el['invert']) ? 1 : 0,
                ];
            }
        }

        $cd = $input['cursor_default'] ?? [];
        $clean['cursor_default'] = [
            'size'              => max(4, min(200, intval($cd['size'] ?? 16))),
            'background_color'  => sanitize_text_field($cd['background_color'] ?? 'white'),
            'blend_mode'        => sanitize_text_field($cd['blend_mode'] ?? 'exclusion'),
            'backdrop_filter'   => sanitize_text_field($cd['backdrop_filter'] ?? 'blur(0px)'),
            'inertia'           => max(0.1, min(1.0, floatval($cd['inertia'] ?? 0.5))),
            'enter_duration'    => max(0.1, min(5.0, floatval($cd['enter_duration'] ?? 0.6))),
            'leave_duration'    => max(0.1, min(5.0, floatval($cd['leave_duration'] ?? 0.3))),
            'mobile_breakpoint' => max(0, min(2560, intval($cd['mobile_breakpoint'] ?? 1024))),
            'hide_native'       => !empty($cd['hide_native']) ? 1 : 0,
            'restore_on_inputs' => !empty($cd['restore_on_inputs']) ? 1 : 0,
        ];

        return $clean;
    }

    public function enqueue_assets(): void {
        $s = $this->get_settings();
        if (empty($s['enabled'])) return;

        $js_elements = [];
        foreach ($s['elements'] as $el) {
            $js_elements[] = [
                'selector' => $el['selector'],
                'cursor'   => [
                    'size'                 => (int) $el['size'],
                    'text'                 => $el['text'],
                    'backgroundColor'      => $el['backgroundColor'],
                    'cursorBlendMode'      => $el['cursorBlendMode'],
                    'cursorBackdropFilter' => $el['cursorBackdropFilter'],
                    'textBlendMode'        => $el['textBlendMode'],
                    'textColor'            => $el['textColor'],
                    'arrows'               => (bool) $el['arrows'],
                    'invert'               => (bool) $el['invert'],
                ],
            ];
        }

        // Wspólny handle z includes/89-gsap.php. Wcześniej moduł ciągnął własną
        // kopię GSAP-a spod innego URL-a — na stronie z Animatorem albo elementem
        // Bricks ładowały się dwie biblioteki naraz, w dwóch różnych wersjach.
        evk_register_gsap_libs();
        wp_enqueue_script('evk-gsap');
        wp_add_inline_script('evk-gsap', $this->get_cursor_js(
            wp_json_encode($js_elements),
            $s['cursor_default']
        ));
    }

    private function get_cursor_js(string $json_elements, array $cd): string {
        $mobile_bp      = intval($cd['mobile_breakpoint']);
        $cursor_size    = intval($cd['size']);
        $bg_color       = esc_js($cd['background_color']);
        $blend_mode     = esc_js($cd['blend_mode']);
        $backdrop       = esc_js($cd['backdrop_filter']);
        $inertia        = floatval($cd['inertia']);
        $enter_dur      = floatval($cd['enter_duration']);
        $leave_dur      = floatval($cd['leave_duration']);
        $hide_native    = !empty($cd['hide_native'])       ? 'true' : 'false';
        $restore_inputs = !empty($cd['restore_on_inputs']) ? 'true' : 'false';

        ob_start();
        ?>
    document.addEventListener('DOMContentLoaded', function () {
        if (window.innerWidth <= <?php echo $mobile_bp; ?>) return;

        // Redukcja ruchu: własny kursor w ogóle nie powstaje, zostaje systemowy.
        // Kursor to czysta dekoracja podążająca za wskaźnikiem z bezwładnością —
        // dla kogoś, kto prosi o mniej ruchu, jest ruchem stale obecnym na ekranie.
        // Wspólna polityka: includes/anim/motion.php.
        if (window.evkMotion && typeof window.evkMotion.reduced === 'function'
            ? window.evkMotion.reduced()
            : (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) return;

        var cursorSize    = <?php echo $cursor_size; ?>;
        var defaultBg     = '<?php echo $bg_color; ?>';
        var defaultBlend  = '<?php echo $blend_mode; ?>';
        var defaultFilter = '<?php echo $backdrop; ?>';
        var inertia       = <?php echo $inertia; ?>;
        var enterDuration = <?php echo $enter_dur; ?>;
        var leaveDuration = <?php echo $leave_dur; ?>;
        var hideNative    = <?php echo $hide_native; ?>;
        var restoreInputs = <?php echo $restore_inputs; ?>;

        var style = document.createElement('style');
        style.textContent =
            '.evoke-cursor{position:fixed;top:0;left:0;pointer-events:none;z-index:999999;' +
            'border-radius:50%;background-color:' + defaultBg + ';mix-blend-mode:' + defaultBlend + ';' +
            'transition:mix-blend-mode .1s,backdrop-filter .1s,-webkit-backdrop-filter .1s,filter .1s;}' +
            '.evoke-cursor-content{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);' +
            'white-space:nowrap;font-size:12px;font-weight:500;color:white;mix-blend-mode:difference;' +
            'pointer-events:none;align-items:center;display:flex;text-align:center;z-index:999999;opacity:0;}' +
            '.left-arrow,.right-arrow{width:52px;height:52px;stroke-width:2;}' +
            '.evoke-cursor-text{margin:0 -12px;}' +
            (hideNative    ? '*{cursor:none!important;}' : '') +
            (hideNative && restoreInputs ? 'input,textarea,select{cursor:auto!important;}' : '');
        document.head.appendChild(style);

        var evkCursor = document.createElement('div');
        evkCursor.className = 'evoke-cursor';
        evkCursor.innerHTML = '<div class="evoke-cursor-content"></div>';
        document.body.appendChild(evkCursor);

        var evkContent = evkCursor.querySelector('.evoke-cursor-content');

        var mouse   = { x: -999, y: -999 };
        var pos     = { x: -999, y: -999 };
        var visible = false;

        gsap.set(evkCursor, { xPercent: -50, yPercent: -50, opacity: 0, scale: 0,
                               width: cursorSize + 'px', height: cursorSize + 'px' });

        gsap.ticker.add(function () {
            if (!visible) return;
            pos.x += (mouse.x - pos.x) * inertia;
            pos.y += (mouse.y - pos.y) * inertia;
            gsap.set(evkCursor, { x: pos.x, y: pos.y });
        });

        window.addEventListener('mousemove', function onFirstMove(e) {
            pos.x = e.clientX;
            pos.y = e.clientY;
            mouse.x = e.clientX;
            mouse.y = e.clientY;
            visible = true;
            gsap.to(evkCursor, { duration: 0.4, opacity: 1, scale: 1 });
            window.removeEventListener('mousemove', onFirstMove);
        });

        window.addEventListener('mousemove', function (e) {
            mouse.x = e.clientX;
            mouse.y = e.clientY;
        });

        function setCursorSize(size) {
            gsap.to(evkCursor, { duration: enterDuration, width: size + 'px', height: size + 'px' });
            gsap.to(evkContent, { delay: enterDuration * 0.25, duration: enterDuration * 0.75,
                                   opacity: size === cursorSize ? 0 : 1 });
        }

        function setCursorContent(cfg) {
            var isDark = document.documentElement.classList.contains('dark')
                      || document.documentElement.getAttribute('data-theme') === 'dark';

            if (cfg.arrows) {
                evkContent.innerHTML =
                    '<svg class="left-arrow" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="' + cfg.backgroundColor + '" style="mix-blend-mode:' + cfg.cursorBlendMode + '">' +
                    '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>' +
                    '<span class="evoke-cursor-text" style="color:' + cfg.textColor + ';mix-blend-mode:' + cfg.textBlendMode + '">' + cfg.text + '</span>' +
                    '<svg class="right-arrow" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="' + cfg.backgroundColor + '" style="mix-blend-mode:' + cfg.cursorBlendMode + '">' +
                    '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>';
            } else {
                evkContent.innerHTML =
                    '<span class="evoke-cursor-text" style="color:' + cfg.textColor + ';mix-blend-mode:' + cfg.textBlendMode + '">' + (cfg.text || '') + '</span>';
            }
            evkCursor.style.filter = (cfg.invert && isDark) ? 'invert(1)' : '';
        }

        function onEnter(e) {
            gsap.killTweensOf(evkCursor);
            gsap.killTweensOf(evkContent);
            var cfg = JSON.parse(e.currentTarget.getAttribute('data-cursor'));
            setCursorSize(cfg.size);
            setCursorContent(cfg);
            evkCursor.style.backgroundColor     = cfg.backgroundColor;
            evkCursor.style.mixBlendMode         = cfg.cursorBlendMode;
            evkCursor.style.backdropFilter       = cfg.cursorBackdropFilter;
            evkCursor.style.webkitBackdropFilter = cfg.cursorBackdropFilter;
        }

        function onLeave() {
            gsap.to(evkCursor, {
                duration: leaveDuration,
                width:    cursorSize + 'px',
                height:   cursorSize + 'px',
                backgroundColor:      defaultBg,
                mixBlendMode:         defaultBlend,
                backdropFilter:       defaultFilter,
                webkitBackdropFilter: defaultFilter,
                filter: 'none',
                onComplete: function () {
                    evkCursor.style.backdropFilter       = '';
                    evkCursor.style.webkitBackdropFilter = '';
                    evkCursor.style.filter               = '';
                }
            });
            setCursorContent({ text: '', arrows: false, invert: false,
                               textColor: '', textBlendMode: '',
                               backgroundColor: '', cursorBlendMode: '' });
            gsap.to(evkContent, { duration: 0.15, opacity: 0 });
        }

        var customCursorElements = <?php echo $json_elements; ?>;

        function bindElements() {
            customCursorElements.forEach(function (item) {
                document.querySelectorAll(item.selector).forEach(function (el) {
                    if (!el.hasAttribute('data-cursor')) {
                        el.setAttribute('data-cursor', JSON.stringify(item.cursor));
                        el.addEventListener('mouseenter', onEnter);
                        el.addEventListener('mouseleave', onLeave);
                    }
                });
            });
        }

        var observer = new MutationObserver(bindElements);
        observer.observe(document.body, { childList: true, subtree: true });
        bindElements();
    });
        <?php
        return ob_get_clean();
    }
}

EVK_Cursor::get_instance();
