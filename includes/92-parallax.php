<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł Parallax
 */

class EVK_Parallax {

    private static $instance = null;

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $settings = $this->get_settings();
        if (!empty($settings['enabled'])) {
            add_action('wp_enqueue_scripts',              [$this, 'enqueue_scripts']);
            /* Priorytet 1: warstwa ma być w arkuszu ZANIM przeglądarka
               pomaluje stronę pierwszy raz. To jest cała poprawka. */
            add_action('wp_head',                         [$this, 'print_layer_css'], 1);
            add_filter('bricks/dynamic_tags_list',        [$this, 'register_bricks_tag']);
            add_filter('bricks/dynamic_data/render_tag',  [$this, 'render_bricks_tag'], 10, 3);
            add_filter('bricks/dynamic_data/render_content', [$this, 'render_bricks_content'], 10, 3);
            add_filter('bricks/frontend/render_data',     [$this, 'render_bricks_content'], 10, 2);
        }
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function get_settings(): array {
        $defaults = ['enabled' => 0];
        $saved    = get_option('evk_parallax', []);
        return array_merge($defaults, is_array($saved) ? $saved : []);
    }

    public function register_settings(): void {
        register_setting('evoke_one_parallax', 'evk_parallax_value', [
            'type'              => 'number',
            'default'           => 0.3,
            'sanitize_callback' => [self::class, 'sanitize_parallax_value'],
        ]);
        register_setting('evoke_one_parallax', 'evk_parallax_scale', [
            'type'              => 'number',
            'default'           => 1.2,
            'sanitize_callback' => [self::class, 'sanitize_scale_value'],
        ]);
        register_setting('evoke_one_parallax', 'evk_parallax', [
            'sanitize_callback' => [self::class, 'sanitize_settings'],
        ]);
    }

    public static function sanitize_settings($input): array {
        return ['enabled' => evk_preserve_toggle($input, 'evk_parallax')];
    }

    /**
     * WARSTWA PARALLAKSY POWSTAJE W CSS, NIE W SKRYPCIE.
     *
     * ZGŁOSZONE Z UŻYCIA: „przy włączonym parallaksie ekran miga podczas
     * ładowania — dokładnie zdjęcie w tle; wyłączenie parallaksu rozwiązuje
     * problem". Przyczyna była w kolejności zdarzeń, nie w samej animacji:
     *
     *  1. przeglądarka malowała sekcję z jej własnym tłem — widać obraz;
     *  2. skrypt (na `DOMContentLoaded`) wstawiał warstwę z `opacity: 0`
     *     i ZDEJMOWAŁ tło z sekcji — ekran robił się pusty;
     *  3. dwie klatki później warstwa wjeżdżała `opacity` 0 → 1 przez 0,1 s.
     *
     * Czyli „widać → pusto → wraca". Im później rusza skrypt, tym dłuższa
     * dziura; przy przechodzeniu między podstronami powtarzała się za każdym
     * razem.
     *
     * Teraz warstwą jest `::before`, opisany regułą wydrukowaną w `<head>`.
     * Pierwsze malowanie pokazuje już stan docelowy, bo nie ma czego czekać:
     * pseudoelement dziedziczy tło z sekcji (`background-image: inherit`),
     * więc nie trzeba go nawet nikomu podawać. Skrypt nie tworzy już nic —
     * ustawia wyłącznie `--evk-par-y` przy przewijaniu.
     *
     * GRANICA: skala pojedynczego elementu (`data-skala`) NIE JEST tutaj
     * znana. `evk_bricks_set_attr()` nadpisuje atrybut, więc wpisanie jej
     * w `style` skasowałoby style Bricksa. Reguła niesie skalę domyślną
     * z ustawień, a element z własną dostaje ją ze skryptu klatkę później —
     * zmiana samej skali, nie zniknięcie obrazu.
     */
    public function print_layer_css(): void {
        $scale = esc_html((string) $this->get_scale_value());
        /* Domyślny zakres ruchu w pikselach. Skrypt liczył go jako
           `wartość × 100` i tę samą liczbę wpisujemy tutaj, żeby oś natywna
           ruszała warstwę dokładnie tak samo jak dotąd. */
        $amp   = esc_html((string) round($this->get_parallax_value() * 100, 2));

        printf(
            '<style id="evk-parallax-layer">%s</style>' . "\n",
            '[data-parallax-css]{position:relative;overflow:hidden;isolation:isolate}'
          . '[data-parallax-css]::before{content:"";position:absolute;top:-10%;bottom:-10%;'
          . 'left:0;right:0;z-index:-1;pointer-events:none;'
          . 'background-image:inherit;background-position:inherit;background-repeat:no-repeat;'
          /* `cover` jak w dotychczasowej ścieżce skryptowej, która `auto`
             zamieniała właśnie na `cover`. Zmienna zostaje jako furtka dla
             sekcji, które potrzebują czegoś innego. */
          . 'background-size:var(--evk-par-size,cover);'
          . 'will-change:transform;backface-visibility:hidden;'
          . 'transform:translate3d(0,var(--evk-par-y,0px),0) scale(var(--evk-par-scale,'
          . $scale . '))}'

          /* ZATRZYMANIE DZIEDZICZENIA — jeden wiersz, który zdejmuje jedną
             trzecią kosztu ścieżki skryptowej.
             `--evk-par-y` i `--evk-par-amp` to własności NIESTANDARDOWE, a te
             dziedziczą się na całe poddrzewo. Zapis na sekcji unieważniał więc
             styl każdego jej potomka co klatkę przewijania. Zmierzone przy
             sekcjach z 400 potomkami: 1898 → 1235 ms przy układzie płaskim
             i 2582 → 883 ms przy zagnieżdżonym.
             Selektor bierze ELEMENTY (`> *`), więc `::before` go nie dostaje
             — warstwa dalej widzi obie wartości i dalej się rusza. Pilnuje
             tego osobne sprawdzenie w tests/parallax.test.js. */
          . '[data-parallax-css] > *{--evk-par-y:initial;--evk-par-amp:initial}'

          /* Przy „ogranicz ruch" warstwa stoi. Skala zostaje: element bywa
             przeskalowany po to, żeby ruch nie odsłaniał krawędzi. */
          . '@media (prefers-reduced-motion: reduce){[data-parallax-css]::before'
          . '{transform:translate3d(0,0,0) scale(var(--evk-par-scale,'
          . $scale . '))}}'

          /* RUCH PROWADZONY PRZEZ PRZEGLĄDARKĘ, bez ani jednej linii skryptu
             na klatkę.
             Zmierzone przy sekcjach z 400 potomkami, dławienie CPU 4×:
             dzisiejsza droga 1914 ms przeliczania stylu, ta sama animacja na
             osi natywnej — 121 ms. Różnica bierze się stąd, że nie ma czego
             unieważniać: przeglądarka prowadzi animację sama, a skrypt nie
             dotyka już żadnej własności przy przewijaniu.

             OŚ NAZWANA NA SEKCJI, nie `view()` na pseudoelemencie. Sekcja ma
             `overflow:hidden`, więc SAMA jest kontenerem przewijania —
             `view()` postawione na `::before` mierzyłoby ruch pseudoelementu
             względem tej sekcji, a tam nic się nie przesuwa. W pomiarze
             wyszło z tego „za darmo": wariant nie kosztował nic, bo nie robił
             nic. Sonda „warstwa naprawdę się rusza" to złapała.

             Kto tej składni nie zna — dziś Firefox — dostaje ścieżkę skryptową
             wyżej, nietkniętą. */
          . '@supports (animation-timeline: view()){'
          .   '[data-parallax-css]{view-timeline-name:--evk-par;view-timeline-axis:block}'
          .   '[data-parallax-css]::before{animation:evk-par linear both;'
          .     'animation-timeline:--evk-par;animation-range:cover 0% cover 100%}'
          .   '@keyframes evk-par{'
          .     'from{transform:translate3d(0,var(--evk-par-amp,' . $amp . 'px),0) '
          .       'scale(var(--evk-par-scale,' . $scale . '))}'
          .     'to{transform:translate3d(0,calc(-1 * var(--evk-par-amp,' . $amp . 'px)),0) '
          .       'scale(var(--evk-par-scale,' . $scale . '))}}'
          /* Animacja przebija statyczną transformację z reguły „ogranicz
             ruch", więc tam trzeba ją zdjąć wprost, a nie liczyć na kolejność. */
          .   '@media (prefers-reduced-motion: reduce){'
          .     '[data-parallax-css]::before{animation-name:none}}'
          . '}'
        );
    }

    public function enqueue_scripts(): void {
        wp_enqueue_script(
            'evk-parallax',
            EVOKE_ONE_URL . 'assets/js/parallax.js',
            [],
            EVOKE_ONE_VERSION,
            true
        );
        wp_localize_script('evk-parallax', 'evkParallaxSettings', [
            'defaultValue' => $this->get_parallax_value(),
            'defaultScale' => $this->get_scale_value(),
        ]);
    }

    public function get_parallax_value(): float {
        return floatval(get_option('evk_parallax_value', 0.3));
    }

    public function get_scale_value(): float {
        return floatval(get_option('evk_parallax_scale', 1.2));
    }

    public function register_bricks_tag($tags): array {
        $tags[] = ['name' => '{evk_parallax}',       'label' => 'Evoke Parallax - Wartość', 'group' => 'Evoke Parallax'];
        $tags[] = ['name' => '{evk_parallax_scale}',  'label' => 'Evoke Parallax - Skala',   'group' => 'Evoke Parallax'];
        return $tags;
    }

    public function render_bricks_tag($tag, $post, $context) {
        if ($tag === 'evk_parallax')       return $this->get_parallax_value();
        if ($tag === 'evk_parallax_scale') return $this->get_scale_value();
        return $tag;
    }

    public function render_bricks_content($content, $post = null, $context = 'text') {
        if (is_array($content)) return $content;
        $content = str_replace('{evk_parallax}',       $this->get_parallax_value(), $content);
        $content = str_replace('{evk_parallax_scale}', $this->get_scale_value(),    $content);
        return $content;
    }

    public static function sanitize_parallax_value($value): float {
        return max(-1.0, min(1.0, floatval($value)));
    }

    public static function sanitize_scale_value($value): float {
        return max(1.0, min(2.0, floatval($value)));
    }
}

EVK_Parallax::get_instance();

function evk_get_parallax_value(): float {
    return EVK_Parallax::get_instance()->get_parallax_value();
}

function evk_get_parallax_scale(): float {
    return EVK_Parallax::get_instance()->get_scale_value();
}
