<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł Dark Mode
 */

class EVK_DarkMode {

    private static $instance = null;

    private $defaults = [
        'enabled'           => 0,
        // Przełącznik
        'toggle_selector'   => '.brxe-toggle-mode',
        // Przejście między stronami (wipe)
        'wipe_enabled'      => 1,
        'nav_trans_type'    => 'wipe',   // wipe | fade | zoom-out | zoom-in | slide-push | iris | nav-ripple
        'wipe_direction'    => 'to bottom',
        'wipe_color'        => '#ffffff',
        'wipe_duration'     => 1.5,
        'wipe_blur'         => 15,
        'wipe_easing'       => 'cubic-bezier(0.4, 0, 0.2, 1)',
        // Nav ripple (klik → fala)
        'nav_ripple_color'  => '#ffffff',
        'nav_ripple_blur'   => 20,
        // Globalne przejścia CSS
        'global_duration'   => 0.4,
        'global_easing'     => 'ease',
        'global_selectors'  => "[data-brx-theme]\nbody\n#brx-content\nsection",
        'global_properties' => "background-color\ncolor\nborder-color\nfill\nstroke\nfilter",
        // Elementy Bricks
        'bricks_enabled'    => 1,
        'bricks_duration'   => 1.0,
        'bricks_easing'     => 'cubic-bezier(0.33, 1, 0.68, 1)',
        'bricks_selectors'  => ".brxe-text\n.brxe-text-basic\n.brxe-heading\n.brxe-text-link\n.brx-submenu-toggle\ninput::placeholder\n.form-group\n.form-group textarea\ninput[type=checkbox]+label\n.splide\n.brxe-slider-nested\nsvg\n.brxe-div",
        'bricks_properties' => "color\nfilter\nborder-color\nbackground-color",
        // Logo transition
        'logo_enabled'      => 1,
        'logo_light_class'  => 'item-light',
        'logo_dark_class'   => 'item-dark',
        'logo_duration'     => 1.0,
        'logo_easing'       => 'ease-in-out',
        // Ripple
        'ripple_enabled'    => 1,
        'ripple_duration'   => 1200,
        'ripple_blur'       => 20,
        'ripple_easing'     => 'cubic-bezier(0.4, 0, 0.2, 1)',
        // Przejścia wpis lista → pojedynczy wpis
        'post_trans_enabled'       => 0,
        'post_trans_title_class'   => '',
        'post_trans_image_class'   => '',
        'post_trans_title_single'  => '',
        'post_trans_image_single'  => '',
        'post_trans_duration'      => 0.5,
        'post_trans_easing'        => 'ease-in-out',
    ];

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_head',    [$this, 'render_logo_block_script'], 1);
        add_action('wp_head',    [$this, 'render_styles'], 5);
        add_action('wp_head',    [$this, 'render_post_trans_single'], 6);
        add_action('wp_footer',  [$this, 'render_scripts'], 20);
        add_action('admin_init', [$this, 'register_settings']);
        add_filter('bricks/element/render_attributes', [$this, 'inject_post_trans_attrs'], 10, 3);
    }

    public function get_settings(): array {
        return wp_parse_args(get_option('evk_darkmode', []), $this->defaults);
    }

    public function register_settings(): void {
        register_setting('evoke_one_darkmode', 'evk_darkmode', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input): array {
        $clean = [];

        // 'enabled' zarządzany przez AJAX toggle — zachowaj gdy brak w POST
        $clean['enabled'] = evk_preserve_toggle($input, 'evk_darkmode');

        foreach (['bricks_enabled', 'logo_enabled', 'ripple_enabled', 'wipe_enabled', 'post_trans_enabled'] as $key) {
            $clean[$key] = !empty($input[$key]) ? 1 : 0;
        }

        $floats = [
            'global_duration'      => [0.1, 5.0, 0.4],
            'bricks_duration'      => [0.1, 5.0, 1.0],
            'logo_duration'        => [0.1, 5.0, 1.0],
            'wipe_duration'        => [0.3, 5.0, 1.5],
            'post_trans_duration'  => [0.1, 3.0, 0.5],
        ];
        foreach ($floats as $key => [$min, $max, $default]) {
            $clean[$key] = isset($input[$key]) ? max($min, min($max, floatval($input[$key]))) : $default;
        }

        $ints = [
            'ripple_duration' => [200, 5000, 1200],
            'ripple_blur'     => [0, 100, 20],
            'wipe_blur'       => [0, 50, 15],
            'nav_ripple_blur' => [0, 100, 20],
        ];
        foreach ($ints as $key => [$min, $max, $default]) {
            $clean[$key] = isset($input[$key]) ? max($min, min($max, intval($input[$key]))) : $default;
        }

        $texts = [
            'global_selectors', 'global_properties',
            'bricks_selectors', 'bricks_properties',
            'logo_light_class', 'logo_dark_class',
            'toggle_selector',
            'post_trans_title_class', 'post_trans_image_class',
            'post_trans_title_single', 'post_trans_image_single',
        ];
        foreach ($texts as $key) {
            $clean[$key] = isset($input[$key]) ? sanitize_textarea_field($input[$key]) : $this->defaults[$key];
        }

        $allowed_nav_types = ['wipe', 'fade', 'zoom-out', 'zoom-in', 'slide-push', 'iris', 'nav-ripple'];
        $clean['nav_trans_type'] = in_array($input['nav_trans_type'] ?? '', $allowed_nav_types, true)
            ? $input['nav_trans_type']
            : $this->defaults['nav_trans_type'];

        $nav_ripple_color = $input['nav_ripple_color'] ?? $this->defaults['nav_ripple_color'];
        $clean['nav_ripple_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $nav_ripple_color) ? $nav_ripple_color : $this->defaults['nav_ripple_color'];

        $color = $input['wipe_color'] ?? $this->defaults['wipe_color'];
        $clean['wipe_color'] = preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : $this->defaults['wipe_color'];

        $allowed_directions = ['to bottom', 'to top', 'to right', 'to left'];
        $clean['wipe_direction'] = in_array($input['wipe_direction'] ?? '', $allowed_directions, true)
            ? $input['wipe_direction']
            : $this->defaults['wipe_direction'];

        $easings = ['global_easing', 'bricks_easing', 'logo_easing', 'ripple_easing', 'wipe_easing', 'post_trans_easing'];
        $allowed_easings = ['ease', 'ease-in', 'ease-out', 'ease-in-out', 'linear',
                            'cubic-bezier(0.33, 1, 0.68, 1)', 'cubic-bezier(0.4, 0, 0.2, 1)'];
        $odrzucone = [];
        foreach ($easings as $key) {
            $val = $input[$key] ?? $this->defaults[$key];
            if (preg_match('/^cubic-bezier\(\s*[\d.]+\s*,\s*[\d.-]+\s*,\s*[\d.]+\s*,\s*[\d.-]+\s*\)$/', $val)) {
                $clean[$key] = $val;
            } elseif (in_array($val, $allowed_easings, true)) {
                $clean[$key] = $val;
            } else {
                $clean[$key] = $this->defaults[$key] ?? 'ease';
                /*
                 * CICHA PODMIANA MUSI SIĘ ODEZWAĆ.
                 *
                 * Do 1.114.0 wszystkie te pola poza dwoma były listami wyboru —
                 * nie było jak wpisać czegoś nie tak, więc ciche cofnięcie do
                 * wartości domyślnej nikomu nie szkodziło. Przy polu tekstowym
                 * to znaczy, że literówka w `cubic-bezier` kasuje wpis bez słowa,
                 * a strona wygląda tak samo jak przed zapisem.
                 *
                 * Pustej wartości NIE zgłaszamy: wyczyszczone pole to świadome
                 * „wróć do domyślnego", a nie pomyłka.
                 */
                if ('' !== trim((string) $val)) {
                    $odrzucone[] = $key . ' = „' . $val . '”';
                }
            }
        }

        if ($odrzucone && function_exists('add_settings_error')) {
            add_settings_error(
                'evk_darkmode',
                'evk_darkmode_easing',
                'Nie rozpoznano easingu: ' . implode('; ', $odrzucone)
                    . '. Wpisz nazwę (ease, ease-in-out, linear…) albo pełne '
                    . 'cubic-bezier(0.33, 1, 0.68, 1). Te pola wróciły do wartości domyślnej.',
                'error'
            );
        }

        return $clean;
    }

    private function parse_lines(string $text): array {
        return array_filter(array_map('trim', explode("\n", $text)));
    }

    /**
     * Blokuje flash podwójnego logo tuż po załadowaniu strony.
     * Musi działać SYNCHRONICZNIE przed renderem — umieszczamy jako
     * pierwszy skrypt w <head>, bez defer/async.
     */
    public function render_logo_block_script(): void {
        $s = $this->get_settings();
        if (empty($s['enabled']) || empty($s['logo_enabled'])) return;
        $light = esc_js($s['logo_light_class']);
        $dark  = esc_js($s['logo_dark_class']);
        ?>
<script id="evk-logo-init">
/* EVK: blok flash logo — synchroniczny, przed renderem */
(function(){
    var m = localStorage.getItem('brx_mode') || 'light';
    var h = document.documentElement;
    h.setAttribute('data-theme', m);
    if (m === 'dark') h.classList.add('dark');
    var s = document.createElement('style');
    if (m === 'dark') {
        s.textContent = '.<?php echo $light; ?>{display:none!important}';
    } else {
        s.textContent = '.<?php echo $dark; ?>{display:none!important}';
    }
    s.id = 'evk-logo-init-css';
    document.head.appendChild(s);
})();
</script>
        <?php
    }

    public function render_styles(): void {
        $s = $this->get_settings();
        if (empty($s['enabled'])) return;

        $global_selectors  = $this->parse_lines($s['global_selectors']);
        $global_properties = $this->parse_lines($s['global_properties']);
        $bricks_selectors  = $this->parse_lines($s['bricks_selectors']);
        $bricks_properties = $this->parse_lines($s['bricks_properties']);

        $global_transition = implode(', ', array_map(
            fn($prop) => "{$prop} {$s['global_duration']}s {$s['global_easing']}",
            $global_properties
        ));

        $bricks_transition = implode(', ', array_map(
            fn($prop) => "{$prop} {$s['bricks_duration']}s {$s['bricks_easing']}",
            $bricks_properties
        ));

        $dir = $s['wipe_direction'];
        $axis_map = [
            'to bottom' => ['to bottom', 'to top'],
            'to top'    => ['to top',    'to bottom'],
            'to right'  => ['to right',  'to left'],
            'to left'   => ['to left',   'to right'],
        ];
        $gradient_dir = $axis_map[$dir][0] ?? 'to bottom';

        $wipe_blur   = intval($s['wipe_blur']);
        $wipe_dur    = floatval($s['wipe_duration']);
        $wipe_easing = esc_attr($s['wipe_easing']);
        $wipe_color  = esc_attr($s['wipe_color']);
        $ripple_blur = intval($s['ripple_blur']);

        echo "<style id=\"evk-darkmode-css\">\n";

        if (!empty($global_selectors) && !empty($global_properties)) {
            echo implode(",\n", $global_selectors) . " {\n";
            echo "    transition: {$global_transition};\n";
            echo "    -webkit-transition: {$global_transition};\n";
            echo "}\n\n";
        }

        if (!empty($s['bricks_enabled']) && !empty($bricks_selectors) && !empty($bricks_properties)) {
            $prefixed = array_map(fn($sel) => "[data-brx-theme] {$sel}", $bricks_selectors);
            echo implode(",\n", $prefixed) . " {\n";
            echo "    transition: {$bricks_transition};\n";
            echo "}\n\n";
        }

        if (!empty($s['logo_enabled'])) {
            $light = esc_attr($s['logo_light_class']);
            $dark  = esc_attr($s['logo_dark_class']);
            echo <<<CSS
.{$light},
.{$dark} {
    view-transition-name: site-logo;
}
::view-transition-group(site-logo) {
    animation-duration: {$s['logo_duration']}s;
    animation-timing-function: {$s['logo_easing']};
}
[data-theme="light"] .{$dark} { display: none !important; }
[data-theme="dark"]  .{$light} { display: none !important; }

CSS;
        }

        echo <<<CSS
@property --wipe-pos {
    syntax: '<percentage>';
    inherits: false;
    initial-value: -20%;
}
@property --ripple-radius {
    syntax: '<length>';
    inherits: false;
    initial-value: 0px;
}
@property --nav-circle-r {
    syntax: '<percentage>';
    inherits: true;
    initial-value: 0%;
}
@property --nav-click-x {
    syntax: '<percentage>';
    inherits: true;
    initial-value: 50%;
}
@property --nav-click-y {
    syntax: '<percentage>';
    inherits: true;
    initial-value: 50%;
}

CSS;

        if (!empty($s['wipe_enabled'])) {
            $nav_type       = $s['nav_trans_type'] ?? 'wipe';
            $nav_ripple_col = esc_attr($s['nav_ripple_color'] ?? '#ffffff');
            $nav_ripple_blur= intval($s['nav_ripple_blur'] ?? 20);

            // @view-transition zawsze gdy nawigacja włączona
            echo <<<CSS
@view-transition {
    navigation: auto;
}
::view-transition-group(root) {
    animation-duration: {$wipe_dur}s;
}
::view-transition-image-pair(root) {
    isolation: isolate;
}

CSS;
            if ($nav_type === 'wipe') {
                $mask_gradient = "linear-gradient({$gradient_dir}, {$wipe_color} calc(var(--wipe-pos) - {$wipe_blur}%), transparent var(--wipe-pos))";
                echo <<<CSS
::view-transition-old(root) {
    animation: none;
    z-index: 1;
}
::view-transition-new(root) {
    z-index: 2;
    animation: evk-wipe {$wipe_dur}s {$wipe_easing} both;
    -webkit-mask-image: {$mask_gradient};
    mask-image: {$mask_gradient};
}
@keyframes evk-wipe {
    from { --wipe-pos: -20%; }
    to   { --wipe-pos: 120%; }
}

CSS;
            } elseif ($nav_type === 'nav-ripple') {
                // Identyczny mechanizm co wipe — @view-transition + animowany ::view-transition-new
                // JS ustawia --nav-click-x/y na <html> przed nawigacją.
                // Animujemy zarejestrowaną --nav-circle-r (interpolowalną), pozycja z var().
                echo <<<CSS
::view-transition-old(root) {
    animation: none;
    z-index: 1;
}
::view-transition-new(root) {
    z-index: 2;
    clip-path: circle(var(--nav-circle-r, 0%) at var(--nav-click-x, 50%) var(--nav-click-y, 50%));
    animation: evk-nav-ripple {$wipe_dur}s {$wipe_easing} both;
}
@keyframes evk-nav-ripple {
    from { --nav-circle-r: 0%; }
    to   { --nav-circle-r: 150%; }
}

CSS;
            } elseif ($nav_type === 'fade') {
                echo <<<CSS
::view-transition-old(root) {
    animation: evk-nav-fade-out {$wipe_dur}s {$wipe_easing} both;
    z-index: 1;
}
::view-transition-new(root) {
    animation: evk-nav-fade-in {$wipe_dur}s {$wipe_easing} both;
    z-index: 2;
}
@keyframes evk-nav-fade-out { from { opacity:1; } to { opacity:0; } }
@keyframes evk-nav-fade-in  { from { opacity:0; } to { opacity:1; } }

CSS;
            } elseif ($nav_type === 'zoom-out') {
                echo <<<CSS
::view-transition-old(root) {
    animation: evk-zoom-out-old {$wipe_dur}s {$wipe_easing} both;
    z-index: 1;
}
::view-transition-new(root) {
    animation: evk-zoom-out-new {$wipe_dur}s {$wipe_easing} both;
    z-index: 2;
}
@keyframes evk-zoom-out-old {
    from { transform:scale(1);    opacity:1; }
    to   { transform:scale(0.85); opacity:0; }
}
@keyframes evk-zoom-out-new {
    from { transform:scale(1.1); opacity:0; }
    to   { transform:scale(1);   opacity:1; }
}

CSS;
            } elseif ($nav_type === 'zoom-in') {
                echo <<<CSS
::view-transition-old(root) {
    animation: evk-zoom-in-old {$wipe_dur}s {$wipe_easing} both;
    z-index: 1;
}
::view-transition-new(root) {
    animation: evk-zoom-in-new {$wipe_dur}s {$wipe_easing} both;
    z-index: 2;
}
@keyframes evk-zoom-in-old {
    from { transform:scale(1);    opacity:1; }
    to   { transform:scale(1.15); opacity:0; }
}
@keyframes evk-zoom-in-new {
    from { transform:scale(0.9); opacity:0; }
    to   { transform:scale(1);   opacity:1; }
}

CSS;
            } elseif ($nav_type === 'slide-push') {
                echo <<<CSS
::view-transition-old(root) {
    animation: evk-slide-out {$wipe_dur}s {$wipe_easing} both;
    z-index: 1;
}
::view-transition-new(root) {
    animation: evk-slide-in {$wipe_dur}s {$wipe_easing} both;
    z-index: 2;
}
@keyframes evk-slide-out {
    from { transform:translateX(0); }
    to   { transform:translateX(-100%); }
}
@keyframes evk-slide-in {
    from { transform:translateX(100%); }
    to   { transform:translateX(0); }
}

CSS;
            } elseif ($nav_type === 'iris') {
                echo <<<CSS
::view-transition-old(root) {
    animation: none;
    z-index: 1;
}
::view-transition-new(root) {
    z-index: 2;
    animation: evk-iris {$wipe_dur}s {$wipe_easing} both;
    clip-path: circle(0% at 50% 50%);
}
@keyframes evk-iris {
    from { clip-path: circle(0% at 50% 50%); }
    to   { clip-path: circle(150% at 50% 50%); }
}

CSS;
            }
        }

        if (!empty($s['ripple_enabled'])) {
            /*
             * GLOBALNE PRZEJŚCIE MILKNIE DOPIERO PO ZROBIENIU MIGAWEK.
             *
             * `::view-transition-new` w Chrome pokazuje ŻYWY dokument, nie
             * zamrożony obrazek. Fala odsłaniała więc powierzchnię, która sama
             * jeszcze się przefarbowywała: przez pierwsze 400 ms — tyle trwa
             * globalne przejście — jej wnętrze było szare zamiast docelowego
             * koloru. Stąd zgłoszenie: „ripple dziwnie przeskakuje zaraz po
             * rozpoczęciu rozchodzenia się fali… w Safari jest OK".
             *
             * W 1.116.0 wyciszyłem to przejście na CAŁY czas fali — i tym
             * zabiłem samo odsłanianie. Chromium robi migawkę „starego" stanu
             * tak, że jej kolory biorą się z TRWAJĄCEGO przejścia: w chwili
             * migawki przejście stoi jeszcze na wartości wyjściowej, więc
             * migawka wygląda staro. Bez przejścia migawka od razu ma kolory
             * docelowe — stara warstwa jest identyczna z nową i nie ma czego
             * odsłaniać.
             *
             * Zmierzone na lustrze żywej strony, sama warstwa stara:
             *   przejście żywe:      255 (jasna, stary motyw)
             *   przejście wyciszone:  26 (ciemna, JUŻ nowy motyw)
             *
             * Dlatego klasę zakłada dopiero `transition.ready` — obie migawki
             * są wtedy zrobione, a żywy dokument pod falą przeskakuje na kolory
             * docelowe, więc znika i szara smuga, i utrata odsłaniania.
             *
             * Lista selektorów celowo zostaje w formie „potomek": na stronie
             * z `[data-brx-theme]` na `html` reguła nie trafia w korzeń, a to
             * właśnie nietknięte przejście korzenia trzyma stary kolor pod
             * nieodsłoniętą częścią ekranu.
             */
            $bez_przejscia = array_merge($global_selectors, $bricks_selectors);
            if ($bez_przejscia) {
                echo "html.is-theme-settled " . implode(",\nhtml.is-theme-settled ", $bez_przejscia) . " {\n";
                echo "    transition: none !important;\n";
                echo "    -webkit-transition: none !important;\n";
                echo "}\n\n";
            }

            echo <<<CSS
html.is-theme-toggling {
    view-transition-name: theme-ripple;
}
::view-transition-group(theme-ripple) {
    animation: none !important;
    background-color: transparent !important;
}
::view-transition-old(theme-ripple) {
    animation: none !important;
    z-index: 1;
    opacity: 1;
}
::view-transition-new(theme-ripple) {
    animation: none !important;
    z-index: 2;
    -webkit-mask-image: radial-gradient(
        circle at var(--ripple-x, 50%) var(--ripple-y, 50%),
        black calc(max(0px, var(--ripple-radius) - {$ripple_blur}px)),
        transparent var(--ripple-radius)
    ) !important;
    mask-image: radial-gradient(
        circle at var(--ripple-x, 50%) var(--ripple-y, 50%),
        black calc(max(0px, var(--ripple-radius) - {$ripple_blur}px)),
        transparent var(--ripple-radius)
    ) !important;
}

CSS;
        }

        echo "</style>\n";
    }

    /**
     * Wstrzykuje view-transition-name na elementach Bricks w query loop (lista wpisów).
     * Bricks przekazuje $key = 'root'|'image'|itp. i $element — obiekt z settings.
     */
    public function inject_post_trans_attrs(array $attributes, string $key, $element): array {
        $s = $this->get_settings();
        if (empty($s['enabled']) || empty($s['post_trans_enabled'])) return $attributes;
        if (!in_the_loop() && !is_singular()) return $attributes;
        if (is_singular()) return $attributes; // na singlu używamy wp_head CSS

        $post_id = get_the_ID();
        if (!$post_id) return $attributes;

        // Klasy podane przez usera (może być kilka oddzielone spacją/przecinkiem)
        $title_classes = array_filter(array_map('trim', preg_split('/[\s,]+/', $s['post_trans_title_class'])));
        $image_classes = array_filter(array_map('trim', preg_split('/[\s,]+/', $s['post_trans_image_class'])));

        // Bricks używa '_root' dla korzenia elementu; 'root' dopuszczone dla
        // bezpieczeństwa, bo tak było tu sprawdzane wcześniej.
        if ($key !== '_root' && $key !== 'root') return $attributes;

        $el_classes = (array)($element->settings['_cssClasses'] ?? []);
        // Bricks przechowuje klasy jako string lub array
        if (is_string($el_classes)) {
            $el_classes = array_filter(array_map('trim', explode(' ', $el_classes)));
        }

        $is_title = !empty($title_classes) && count(array_intersect($title_classes, $el_classes)) > 0;
        $is_image = !empty($image_classes) && count(array_intersect($image_classes, $el_classes)) > 0;

        if (!$is_title && !$is_image) return $attributes;

        $name = $is_title ? "post-title-{$post_id}" : "post-img-{$post_id}";

        // Bricks grupuje atrybuty po kluczu ($attributes[$key]['style']).
        // Zapis płaski był po cichu ignorowany — stąd ta funkcja nigdy nie działała.
        $grouped  = isset($attributes[$key]) && is_array($attributes[$key]);
        $current  = $grouped ? ($attributes[$key]['style'] ?? '') : ($attributes['style'] ?? '');
        $existing = is_array($current) ? implode(' ', $current) : (string) $current;
        $style    = trim($existing . " view-transition-name: {$name};");

        if ($grouped) $attributes[$key]['style'] = [$style];
        else          $attributes['style']       = $style;

        return $attributes;
    }

    /**
     * Na stronie pojedynczego wpisu: wstrzykuje view-transition-name dla tytułu i obrazka
     * poprzez CSS targetujący selektory podane w ustawieniach.
     */
    public function render_post_trans_single(): void {
        $s = $this->get_settings();
        if (empty($s['enabled']) || empty($s['post_trans_enabled'])) return;
        if (!is_singular()) return;

        $post_id  = get_queried_object_id();
        if (!$post_id) return;

        $dur    = floatval($s['post_trans_duration']);
        $easing = esc_attr($s['post_trans_easing']);

        $title_sel = trim($s['post_trans_title_single']);
        $image_sel = trim($s['post_trans_image_single']);

        if (!$title_sel && !$image_sel) return;

        echo "<style id=\"evk-post-trans\">\n";

        if ($title_sel) {
            echo esc_html($title_sel) . " {\n";
            echo "    view-transition-name: post-title-{$post_id};\n";
            echo "}\n";
        }
        if ($image_sel) {
            echo esc_html($image_sel) . " {\n";
            echo "    view-transition-name: post-img-{$post_id};\n";
            echo "}\n";
        }

        $names = array_filter(["post-title-{$post_id}", "post-img-{$post_id}"]);
        foreach ($names as $n) {
            echo "::view-transition-group({$n}) {\n";
            echo "    animation-duration: {$dur}s;\n";
            echo "    animation-timing-function: {$easing};\n";
            echo "}\n";
        }

        echo "</style>\n";
    }

    public function render_scripts(): void {
        $s = $this->get_settings();
        if (empty($s['enabled'])) return;

        $ripple_enabled   = !empty($s['ripple_enabled']);
        $ripple_duration  = intval($s['ripple_duration']);
        $ripple_easing    = esc_js($s['ripple_easing']);
        $toggle_selector  = esc_js($s['toggle_selector'] ?: '.brxe-toggle-mode');
        $nav_trans_type   = esc_js($s['nav_trans_type'] ?? 'wipe');
        $nav_enabled      = !empty($s['wipe_enabled']);
        $nav_duration_ms  = intval(floatval($s['wipe_duration']) * 1000);
        $nav_easing       = esc_js($s['wipe_easing']);
        ?>
<script id="evk-darkmode-js">
(function () {
    var html       = document.documentElement;
    var storageKey = 'brx_mode';
    var rippleEnabled  = <?php echo $ripple_enabled ? 'true' : 'false'; ?>;
    var rippleDuration = <?php echo $ripple_duration; ?>;
    var rippleEasing   = '<?php echo $ripple_easing; ?>';
    var toggleSelector = '<?php echo $toggle_selector; ?>';
    var navTransType   = '<?php echo $nav_trans_type; ?>';
    var navEnabled     = <?php echo $nav_enabled ? 'true' : 'false'; ?>;
    var navDuration    = <?php echo $nav_duration_ms; ?>;
    var navEasing      = '<?php echo $nav_easing; ?>';

    var savedMode = localStorage.getItem(storageKey) || 'light';
    html.setAttribute('data-theme', savedMode);
    if (savedMode === 'dark') html.classList.add('dark');
    window.evkTheme = savedMode;

    window.addEventListener('DOMContentLoaded', function () {
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                var tmpStyle = document.getElementById('evk-logo-init-css');
                if (tmpStyle) tmpStyle.remove();
            });
        });

        var toggleBtns = document.querySelectorAll(toggleSelector);
        toggleBtns.forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                var currentMode = html.getAttribute('data-theme');
                var newMode     = currentMode === 'light' ? 'dark' : 'light';

                if (!rippleEnabled || !document.startViewTransition) {
                    updateTheme(newMode);
                    return;
                }

                var rect = this.getBoundingClientRect();
                var x    = rect.left + rect.width  / 2;
                var y    = rect.top  + rect.height / 2;
                var endRadius = Math.hypot(
                    Math.max(x, window.innerWidth  - x),
                    Math.max(y, window.innerHeight - y)
                );

                html.style.setProperty('--ripple-x', x + 'px');
                html.style.setProperty('--ripple-y', y + 'px');
                html.classList.add('is-theme-toggling');

                var transition = document.startViewTransition(function () {
                    updateTheme(newMode);
                });

                transition.ready.then(function () {
                    // Obie migawki są już zrobione — dopiero teraz można
                    // wyciszyć globalne przejście, żeby odsłaniana powierzchnia
                    // była od razu w kolorze docelowym. Wcześniej wyciszenie
                    // zabierało starej migawce jej stary kolor.
                    html.classList.add('is-theme-settled');

                    html.animate(
                        { '--ripple-radius': ['0px', (endRadius + 150) + 'px'] },
                        {
                            duration: rippleDuration,
                            easing: rippleEasing,
                            pseudoElement: '::view-transition-new(theme-ripple)',
                            fill: 'forwards'
                        }
                    );
                    html.animate(
                        { opacity: [1, 0.8] },
                        {
                            duration: rippleDuration,
                            easing: rippleEasing,
                            pseudoElement: '::view-transition-old(theme-ripple)',
                            fill: 'forwards'
                        }
                    );
                });

                transition.finished.then(function () {
                    html.classList.remove('is-theme-toggling');
                    html.classList.remove('is-theme-settled');
                });
            });
        });
    });

    function updateTheme(mode) {
        html.setAttribute('data-theme', mode);
        localStorage.setItem(storageKey, mode);
        if (mode === 'dark') {
            html.classList.add('dark');
        } else {
            html.classList.remove('dark');
        }
        window.evkTheme = mode;
        // Sygnał dla elementów, które wyliczają kolory w JS (np. Scroll Reading) —
        // bez niego trzymają wartości wyliczone przy starcie strony aż do odświeżenia.
        document.dispatchEvent(new CustomEvent('evk:theme-change', { detail: { mode: mode } }));
    }

    // ── Overlay nawigacyjny: zakrywa ekran PRZED przeładowaniem ──
    // Wipe (zasłona) działa przez View Transition CSS — bez JS.
    // Pozostałe typy używają JS overlay który zakrywa ekran,
    // nawiguje, a po załadowaniu nowej strony odkrywa.
    // ── Nav Ripple: ustaw pozycję kliknięcia jako CSS vars przed MPA nawigacją ──
    // @view-transition CSS sam obsługuje animację — JS tylko przekazuje X/Y.
    // ::view-transition-new renderuje się na NOWEJ stronie, więc pozycję
    // przekazujemy przez sessionStorage i odtwarzamy natychmiast przy starcie.
    if (navEnabled && navTransType === 'nav-ripple') {
        // Odtwórz pozycję z poprzedniego kliknięcia (dla snapshotu new)
        var ripplePos = sessionStorage.getItem('evk_ripple_pos');
        if (ripplePos) {
            try {
                var rp = JSON.parse(ripplePos);
                html.style.setProperty('--nav-click-x', rp.x);
                html.style.setProperty('--nav-click-y', rp.y);
            } catch (e) {}
            sessionStorage.removeItem('evk_ripple_pos');
        }

        document.addEventListener('click', function (e) {
            var link = e.target.closest ? e.target.closest('a') : (function () {
                var el = e.target;
                while (el && el.tagName !== 'A') el = el.parentNode;
                return (el && el.tagName === 'A') ? el : null;
            }());
            if (!link) return;
            var href = link.getAttribute('href');
            if (!href || link.target === '_blank') return;
            if (/^[#?]|^(mailto|tel|javascript):/i.test(href.trim())) return;
            var x = Math.round(e.clientX / window.innerWidth  * 100) + '%';
            var y = Math.round(e.clientY / window.innerHeight * 100) + '%';
            html.style.setProperty('--nav-click-x', x);
            html.style.setProperty('--nav-click-y', y);
            // Zapisz dla nowej strony (snapshot ::view-transition-new)
            sessionStorage.setItem('evk_ripple_pos', JSON.stringify({ x: x, y: y }));
        }, true);
    }


})();
</script>
        <?php
    }
}

EVK_DarkMode::get_instance();
