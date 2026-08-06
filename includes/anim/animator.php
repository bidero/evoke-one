<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Moduł Animator
 *
 * Biblioteka nazwanych animacji. Każdy wiersz dostaje slug, a slug daje klasę
 * `evk-anim-{slug}` do przypięcia dowolnemu elementowi w Bricks. Konfiguracja
 * jedzie na front jako JSON i jest scalana w silniku (assets/js/animator.js)
 * w kolejności: preset ⊕ wiersz biblioteki ⊕ atrybut `data-evk-anim` elementu.
 *
 * Slug jest osobnym polem, a nie pochodną nazwy — zmiana nazwy w panelu nie
 * może po cichu zerwać klas wpisanych już na stronach.
 */

require_once __DIR__ . '/presets.php';

class EVK_Animator {

    private static $instance = null;

    private $defaults = [
        'enabled'          => 0,
        'reduced_motion'   => 1,   // szanuj prefers-reduced-motion
        'builder_preview'  => 0,   // animuj też w canvasie buildera
        'animations'       => [],
    ];

    private $row_defaults = [
        'slug'     => '',
        'label'    => '',
        'preset'   => 'fade-up',
        'trigger'  => 'viewport',
        // Puste = dziedzicz z presetu. Twarda wartość domyślna przesłaniałaby
        // czasy presetów (np. stagger 0.08 w split-lines) i cicho je gasiła.
        // Easing z tego samego powodu: preset „bounce-in" bez własnej krzywej
        // nie odbija, a domyślne power2.out zawsze by ją przykryło.
        'duration' => '',
        'stagger'  => '',
        'delay'    => 0,
        'easing'   => '',
        'start'    => 'top 85%',
        'end'      => 'bottom 40%',
        'scrub'    => 1,
        'repeat'   => 0,
        'order'    => 0,
        // Cel animacji: sam element, jego dzieci albo selektor w środku.
        // Dopiero 'children'/'selector' nadaje sens polu 'stagger' poza tekstem.
        'targets'  => 'self',
        'selector' => '',
        'pin'      => 0,   // tylko przy wyzwalaczu 'scrub'
        // Własne from/to — trzymane jako tekst („właściwość: wartość" na linię),
        // żeby pole w panelu wracało dokładnie takie, jakie je wpisano.
        // Na front idą sparsowane (patrz enqueue_assets()).
        'from'     => '',
        'to'       => '',
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
        // Priorytet 1 — musi trafić do <head> przed pierwszym malowaniem.
        add_action('wp_head', [$this, 'render_preveil'], 1);
    }

    /**
     * Zasłona przeciw błyskowi treści.
     *
     * Skrypt animatora leci ze stopki, razem z GSAP z CDN. Zanim dojedzie
     * i nałoży stan początkowy (np. opacity: 0), element stoi wyrenderowany
     * normalnie — widać go, potem skacze do stanu „from" i dopiero animuje.
     *
     * Klasę na <html> ustawia mikroskrypt, a nie PHP: przy wyłączonym
     * JavaScripcie nie wejdzie w ogóle, więc treść nigdy nie zniknie.
     * Do tego bezpiecznik czasowy — awaria CDN-u nie może ukryć strony na stałe.
     *
     * visibility, nie opacity: zachowuje layout, więc pomiary ScrollTriggera
     * pozostają poprawne.
     *
     * NAZWA KLASY NIE MOŻE ZAWIERAĆ CIĄGU „evk-anim-". Silnik zbiera elementy
     * selektorem [class*="evk-anim-"], a querySelectorAll przeszukuje dokument
     * razem z <html> — korzeń strony wpadał wtedy do wyników jak zwykły element
     * z animacją i silnik szukał w bibliotece animacji o slugu „pending"
     * (tak było do 1.28.1). Dopasowanie jest po PODCIĄGU, nie po prefiksie,
     * więc sam brak prefiksu nie wystarczy.
     */
    public function render_preveil(): void {
        $s = $this->get_settings();
        if (empty($s['enabled']) || empty($s['animations'])) return;
        if (empty($s['builder_preview'])
            && function_exists('bricks_is_builder_main') && bricks_is_builder_main()) {
            return;
        }
        ?>
<style id="evk-anim-preveil">
.evk-veil [data-evk-anim],
.evk-veil [class*="evk-anim-"] { visibility: hidden !important; }
</style>
<script id="evk-anim-preveil-js">
(function () {
    var h = document.documentElement;
    h.classList.add('evk-veil');
    setTimeout(function () { h.classList.remove('evk-veil'); }, 3000);
})();
</script>
        <?php
    }

    public function get_settings(): array {
        $saved = get_option('evk_animator', []);
        $s     = wp_parse_args(is_array($saved) ? $saved : [], $this->defaults);
        $s['animations'] = is_array($s['animations']) ? $s['animations'] : [];
        return $s;
    }

    public function register_settings(): void {
        register_setting('evoke_one_animator', 'evk_animator', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input): array {
        $clean = [];

        // 'enabled' zarządzany przez AJAX toggle — zachowaj gdy brak w POST
        $clean['enabled']         = evk_preserve_toggle($input, 'evk_animator');
        $clean['reduced_motion']  = !empty($input['reduced_motion'])  ? 1 : 0;
        $clean['builder_preview'] = !empty($input['builder_preview']) ? 1 : 0;

        $presets  = evk_anim_presets();
        $triggers = evk_anim_triggers();
        $easings  = evk_anim_easings();

        $clean['animations'] = [];
        $seen_slugs = [];

        foreach ((array) ($input['animations'] ?? []) as $row) {
            if (!is_array($row)) continue;

            // Slug jest kluczem klasy — bez niego wiersz jest bezużyteczny.
            $slug = sanitize_title($row['slug'] ?? '');
            if ($slug === '' || isset($seen_slugs[$slug])) continue;
            $seen_slugs[$slug] = true;

            $preset  = isset($presets[$row['preset'] ?? ''])   ? $row['preset']  : 'fade-up';
            $trigger = isset($triggers[$row['trigger'] ?? '']) ? $row['trigger'] : 'viewport';

            // Pusty easing jest ZNACZĄCY („— z presetu —"), więc przechodzi
            // obok listy dopuszczalnych wartości, a nie przez nią. Wiersze
            // zapisane wcześniej mają jawne power2.out i nic w nich nie zmienia.
            $raw_easing = $row['easing'] ?? '';
            $easing     = ($raw_easing === '' || in_array($raw_easing, $easings, true))
                ? $raw_easing : 'power2.out';

            // Pole puste → zapisz pusty string, żeby silnik zszedł do wartości presetu.
            $inherit = function ($val, float $min, float $max) {
                if ($val === '' || $val === null) return '';
                return max($min, min($max, floatval($val)));
            };

            $clean['animations'][] = [
                'slug'     => $slug,
                'label'    => sanitize_text_field($row['label'] ?? ''),
                'preset'   => $preset,
                'trigger'  => $trigger,
                'easing'   => $easing,
                'duration' => $inherit($row['duration'] ?? '', 0.05, 10.0),
                'stagger'  => $inherit($row['stagger']  ?? '', 0.0,  2.0),
                'delay'    => max(0.0,  min(10.0, floatval($row['delay']    ?? 0))),
                'scrub'    => max(0.0,  min(5.0,  floatval($row['scrub']    ?? 1))),
                'start'    => sanitize_text_field($row['start'] ?? 'top 85%'),
                'end'      => sanitize_text_field($row['end']   ?? 'bottom 40%'),
                'repeat'   => !empty($row['repeat']) ? 1 : 0,
                'order'    => max(0, min(999, intval($row['order'] ?? 0))),
                'targets'  => in_array($row['targets'] ?? '', ['self', 'children', 'selector'], true)
                    ? $row['targets'] : 'self',
                'selector' => sanitize_text_field($row['selector'] ?? ''),
                'pin'      => !empty($row['pin']) ? 1 : 0,
                // Zapisujemy tekst po normalizacji przez parser — dzięki temu
                // do opcji nie trafiają śmieci, a pole w panelu pokazuje to,
                // co silnik faktycznie dostanie.
                'from'     => evk_anim_props_to_text(evk_anim_parse_props((string) ($row['from'] ?? ''))),
                'to'       => evk_anim_props_to_text(evk_anim_parse_props((string) ($row['to']   ?? ''))),
            ];
        }

        return $clean;
    }

    /** Wiersz uzupełniony o domyślne — używane przez zakładkę admina. */
    public function row_with_defaults(array $row): array {
        return wp_parse_args($row, $this->row_defaults);
    }

    public function row_defaults(): array {
        return $this->row_defaults;
    }

    public function enqueue_assets(): void {
        $s = $this->get_settings();
        if (empty($s['enabled']) || empty($s['animations'])) return;
        if (is_admin()) return;
        if (empty($s['builder_preview'])
            && function_exists('bricks_is_builder_main') && bricks_is_builder_main()) {
            return;
        }

        $presets = evk_anim_presets();

        // Biblioteka kluczowana slugiem — silnik czyta ją po klasie evk-anim-{slug}.
        $library    = [];
        $needs_split = false;
        foreach ($s['animations'] as $row) {
            $row = $this->row_with_defaults($row);

            // Tekst → obiekt dla GSAP. Pusty klucz MUSI zniknąć, a nie pojechać
            // jako []: w JS pusta tablica jest prawdziwa, więc `lib.from || pre.from`
            // zatrzymałoby się na niej i preset przestałby działać.
            foreach (['from', 'to'] as $key) {
                $props = evk_anim_parse_props((string) $row[$key]);
                if ($props) $row[$key] = $props;
                else        unset($row[$key]);
            }

            $library[$row['slug']] = $row;
            if (!empty($presets[$row['preset']]['split'])) $needs_split = true;
        }

        $deps = ['evk-gsap', 'evk-scrolltrigger'];
        if ($needs_split) $deps[] = 'evk-splittext';

        wp_enqueue_script('evk-animator', EVOKE_ONE_URL . 'assets/js/animator.js',
            $deps, EVOKE_ONE_VERSION, true);

        wp_add_inline_script('evk-animator', 'window.evkAnimator = ' . wp_json_encode([
            'library'        => $library,
            'presets'        => $presets,
            'reducedMotion'  => !empty($s['reduced_motion']),
            // Czekanie na webfonty ma sens WYŁĄCZNIE przy podziale tekstu —
            // tylko tam metryki fontu decydują o łamaniu linii. Przy pozostałych
            // animacjach to czyste opóźnienie startu i źródło błysku treści.
            'needsFonts'     => $needs_split,
        ]) . ';', 'before');
    }
}

EVK_Animator::get_instance();
