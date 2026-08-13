<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Kolor pasków przeglądarki (theme-color)
 *
 * Safari koloruje swój górny i dolny pasek pod stronę. Gdy strona nie mówi mu,
 * jakiego koloru ma być, przeglądarka BIERZE GO Z TEGO, CO WIDZI — i wtedy
 * cokolwiek zamaluje kadr, przemalowuje przy okazji paski. Najbardziej rzuca
 * się to w oczy przy menu pełnoekranowym: Circular Menu i Offcanvas Menu kładą
 * na cały kadr nieprzezroczysty panel, więc po otwarciu paski przejmują jego
 * kolor. Nie jest to usterka menu — to domyślne zachowanie przeglądarki wobec
 * strony, która o kolorze nic nie powiedziała.
 *
 * `theme-color` odbiera tę decyzję próbkowaniu: podany kolor obowiązuje
 * niezależnie od tego, co akurat jest namalowane. Dlatego to jest naprawa
 * PRZYCZYNY, a nie objawu — działa też na podstronach bez żadnego menu,
 * przy pełnoekranowej galerii, sekcji z ciemnym tłem czy filmie na cały ekran.
 * Menu nie musi wiedzieć o tym module nic i nic tu nie robi.
 *
 * Czego ten moduł NIE przeskoczy: przeglądarka bierze PIERWSZY pasujący
 * znacznik w dokumencie, więc `theme-color` wpisany na sztywno w szablon
 * motywu przed `wp_head()` wygra z naszym. Stąd priorytet 1 — wchodzimy tak
 * wcześnie, jak `wp_head` pozwala.
 */

class EVK_Theme_Color {

    private static $instance = null;

    private $defaults = [
        'enabled' => 0,
        'light'   => '#ffffff',
        // Pusty znaczy „ten sam co jasny" — ta sama konwencja co przy kolorach
        // burgera i kadru menu. Jeden kolor wystarcza, gdy strona nie ma trybu
        // ciemnego, i nie zmusza do wypełniania drugiego pola.
        'dark'    => '',
    ];

    public static function get_instance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
        if (empty($this->get_settings()['enabled'])) return; // nic nie rób gdy wyłączone
        // Priorytet 1: przeglądarka czyta PIERWSZY pasujący znacznik, więc im
        // wcześniej w <head>, tym mniejsza szansa, że ubiegnie nas cudzy.
        add_action('wp_head', [$this, 'render_head'], 1);
    }

    public function get_settings(): array {
        return wp_parse_args(get_option('evk_theme_color', []), $this->defaults);
    }

    public function register_settings(): void {
        register_setting('evoke_one_themecolor', 'evk_theme_color', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input): array {
        $clean = [];
        // 'enabled' zarządzany przez AJAX toggle — zachowaj gdy brak w POST
        $clean['enabled'] = evk_preserve_toggle($input, 'evk_theme_color');
        $clean['light']   = sanitize_hex_color($input['light'] ?? '');
        $clean['dark']    = sanitize_hex_color($input['dark'] ?? '');
        return $clean;
    }

    /**
     * Znaczniki do <head>.
     *
     * Przy podanych OBU kolorach wychodzą dwa znaczniki rozdzielone `media`,
     * a nie jeden: przeglądarka bierze pierwszy, którego zapytanie pasuje, więc
     * jasny musi stać PRZED ciemnym. Przeglądarka, która `media` przy tym
     * znaczniku nie rozumie, weźmie po prostu pierwszy — czyli jasny — i to
     * jest właściwy zapasowy wybór, a nie przypadek.
     *
     * Bez koloru jasnego nie emitujemy NICZEGO. Zgadywanie wartości byłoby tu
     * gorsze od milczenia: pomyłka przemalowuje paski na całej stronie,
     * a milczenie zostawia zachowanie sprzed włączenia modułu.
     */
    public function render_head(): void {
        $s = $this->get_settings();
        /* Sanityzacja TAKŻE tutaj, nie tylko przy zapisie. Opcja bywa zapisana
           inaczej niż przez ten formularz — kodem, importem ustawień, migracją
           ze starszej wersji — a wtedy „bez koloru nie emitujemy niczego"
           przestawałoby być prawdą i do <head> szłaby dowolna treść. `esc_attr`
           broni przed wyjściem z atrybutu, ale nie robi z niej koloru. */
        /* Rzutowanie na łańcuch NIE jest ozdobą: `sanitize_hex_color()` zwraca
           `null` dla wartości niepustej, ale nieprawidłowej, a `''` dla pustej.
           Bez tego `null` przechodziłby przez porównanie z `''` poniżej
           i do <head> szedł drugi znacznik z pustą treścią. */
        $light = (string) sanitize_hex_color($s['light']);
        $dark  = (string) sanitize_hex_color($s['dark']);

        if ($light === '') return;

        if ($dark === '' || $dark === $light) {
            printf('<meta name="theme-color" content="%s">' . "\n", esc_attr($light));
            return;
        }

        printf('<meta name="theme-color" content="%s" media="(prefers-color-scheme: light)">' . "\n",
            esc_attr($light));
        printf('<meta name="theme-color" content="%s" media="(prefers-color-scheme: dark)">' . "\n",
            esc_attr($dark));
    }
}

EVK_Theme_Color::get_instance();
