<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Przenikanie tła przy scrollu
 *
 * Kolor NIE jest animowany na samych sekcjach. Gdyby każda sekcja przewijała
 * własne tło, przez całe przejście na ich granicy sąsiadowałyby dwa różne
 * kolory — widać byłoby szew, a efekt czytałby się jak przenikanie prostokątów,
 * nie jak jedno tło. Zamiast tego pod całą stroną leży JEDNA warstwa: sekcje
 * z włączoną opcją oddają jej swój kolor i same robią się przezroczyste,
 * a ScrollTrigger przewija kolor tej warstwy od sekcji do sekcji.
 *
 * Nie ma osobnej kontrolki na kolor. Silnik odczytuje `background-color`
 * z getComputedStyle, gdzie kolory globalne Bricks są już rozwinięte do
 * konkretnego rgb() — dzięki temu nie powstaje drugie źródło prawdy, które
 * rozjeżdżałoby się przy każdej zmianie koloru sekcji.
 */

/** Wersja asetów modułu — osobna od wersji wtyczki, żeby cache-buster był celny. */
const EVK_BGSHIFT_VERSION = '1.1.0';

class EVK_Bg_Shift {

    private static $instance = null;

    private $defaults = [
        'enabled' => 0,
        'length'  => 0.5,   // jaką część widoku zajmuje przejście między sekcjami
        'smooth'  => 0.3,   // wygładzanie scruba w sekundach (0 = bez wygładzania)
        // Procent wysokości okna, na którym ZACZYNA się przejście. 100 = górna
        // krawędź nadchodzącej sekcji dotyka dołu okna — wartość zahardkodowana
        // w silniku do 1.53.0, więc domyślna zachowuje dotychczasowy wygląd.
        'start'   => 100,
        // Dwa kolory, spośród których AUTOMAT wybiera kolor liter, gdy sekcja
        // nie ma własnego. Wybór idzie po kontraście wobec tła, nie po progu
        // jasności — przy tłach pośrednich próg potrafi wskazać gorszy z dwóch.
        'text_light' => '#ffffff',
        'text_dark'  => '#111111',
    ];

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        if (empty($this->get_settings()['enabled'])) return;   // nie ładuj asetów gdy wyłączone
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets'], 20);
        add_action('wp_head',            [$this, 'render_css'], 10);
    }

    public function get_settings(): array {
        return wp_parse_args(get_option('evk_bgshift', []), $this->defaults);
    }

    public function register_settings(): void {
        register_setting('evoke_one_bgshift', 'evk_bgshift', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input): array {
        return [
            // 'enabled' zarządzany przez AJAX toggle — zachowaj gdy brak w POST
            'enabled' => evk_preserve_toggle($input, 'evk_bgshift'),
            'length'  => max(0.1, min(1.0, floatval($input['length'] ?? 0.5))),
            'smooth'  => max(0.0, min(2.0, floatval($input['smooth'] ?? 0.3))),
            // Powyżej 100% sekcja jest jeszcze pod oknem — dozwolone, bo pozwala
            // przełączyć tło ZAWCZASU. Poniżej 0 nie ma sensu: ScrollTrigger
            // dostałby punkt nad górną krawędzią okna, którego nigdy nie minie.
            'start'   => max(0,   min(200, intval($input['start'] ?? 100))),
            // sanitize_hex_color() zwraca null przy śmieciach — wtedy wracamy
            // do domyślnej, bo pusty kolor zostawiłby automat bez czego wybierać.
            'text_light' => sanitize_hex_color($input['text_light'] ?? '') ?: '#ffffff',
            'text_dark'  => sanitize_hex_color($input['text_dark']  ?? '') ?: '#111111',
        ];
    }

    /** Czy jesteśmy w builderze Bricks (tam efekt tylko przeszkadza w edycji). */
    private function in_builder(): bool {
        return function_exists('bricks_is_builder_main') && bricks_is_builder_main();
    }

    public function render_css(): void {
        if ($this->in_builder()) return;
        ?>
<style id="evk-bgshift-css">
/* Warstwa pod całą stroną. Ujemny z-index sprawia, że maluje się NAD tłem
   <body>, ale POD jego treścią — dokładnie tam, gdzie ma być. Warunek: żaden
   pojemnik między <body> a sekcjami nie może mieć własnego, krytego tła,
   bo zasłoniłby warstwę. */
.evk-bg-layer {
    position: fixed;
    inset: 0;
    z-index: -1;
    pointer-events: none;
}
/* !important, bo Bricks maluje tło sekcji regułą z klasy elementu, a tę trzeba
   przebić — kolor nie ginie, silnik odczytuje go przed zdjęciem tła. */
.evk-bg-handoff {
    background-color: transparent !important;
}
/* Kolor liter jedzie razem z tłem.
   Zmienną `--evk-bg-text` ustawia silnik na <html> — jedna wartość dla całej
   strony, bo w danej chwili widać jedno tło. Zasięg ogranicza jednak SELEKTOR:
   dostają ją tylko sekcje z włączonym tłem przy scrollu. Sekcja pominięta
   (tło graficzne, gradient) traci `evk-bg-handoff` i tym samym wypada też
   z przemalowywania liter — jedno i drugie z tego samego powodu.

   `!important` z tego samego powodu co przy tle wyżej: Bricks maluje sekcję
   regułą z klasy elementu i trzeba ją przebić.

   Kolor schodzi DZIEDZICZENIEM, więc element z własnym kolorem ustawionym
   w builderze zostanie nietknięty — i tak ma być, bo inaczej wtyczka
   odbierałaby kontrolę nad typografią. Kto chce, żeby taki element mimo to
   podążał za tłem, dokłada mu klasę `evk-bg-text`. */
.evk-bg-handoff,
.evk-bg-handoff .evk-bg-text {
    color: var(--evk-bg-text, inherit) !important;
}
/* Zakładana wyłącznie na czas pomiaru koloru. Tryb ciemny dokłada na sekcje
   `transition: background-color`, a wtedy getComputedStyle zwraca wartość
   w trakcie animacji zamiast docelowej — silnik odczytałby kolor poprzedniego
   motywu. Szczegóły w readColors() w assets/js/bg-shift.js. */
.evk-bg-measure {
    transition: none !important;
}
</style>
        <?php
    }

    public function enqueue_assets(): void {
        if ($this->in_builder() || is_admin()) return;

        $s = $this->get_settings();

        evk_register_gsap_libs();
        wp_enqueue_script(
            'evk-bgshift',
            EVOKE_ONE_URL . 'assets/js/bg-shift.js',
            ['evk-gsap', 'evk-scrolltrigger'],
            EVK_BGSHIFT_VERSION,
            true
        );
        wp_add_inline_script('evk-bgshift', 'window.evkBgShift = ' . wp_json_encode([
            'length' => (float) $s['length'],
            'smooth' => (float) $s['smooth'],
            'start'  => (int)   $s['start'],
            'textLight' => (string) $s['text_light'],
            'textDark'  => (string) $s['text_dark'],
        ]) . ';', 'before');
    }
}

EVK_Bg_Shift::get_instance();

/** Czy moduł jest włączony — używane przez kontrolki Bricks. */
function evk_bgshift_enabled(): bool {
    return !empty(EVK_Bg_Shift::get_instance()->get_settings()['enabled']);
}
