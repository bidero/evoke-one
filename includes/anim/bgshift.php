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
const EVK_BGSHIFT_VERSION = '1.4.0';

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
        /* Zasięg koloru liter. `dziedziczenie` to zachowanie sprzed 1.88.0:
           kolor schodzi na sekcję i dalej DZIEDZICZENIEM, więc omija każdy
           element z własnym kolorem — a Bricks nadaje własny kolor niemal
           każdemu tekstowi, po identyfikatorze. W praktyce znaczyło to, że
           litery nie zmieniały się prawie nigdzie.

           Od 1.89.0 domyślna jest `wszystko`. Przez jedną wersję domyślną było
           dziedziczenie — z rozumowania, że szerszy zasięg zabiera kontrolę nad
           typografią i musi być decyzją. Praktyka pokazała odwrotnie: moduł
           włącza się osobno i osobno zaznacza się każdą sekcję, więc decyzja
           „litery mają iść za tłem" zapadła już dwa razy, zanim ta opcja
           w ogóle działa — a domyślna kazała podjąć ją trzeci raz w miejscu,
           którego nikt nie szukał. Kto woli wąski zasięg, ma go wprost w opcji;
           strony, które wybrały go świadomie po 1.88.0, mają klucz zapisany
           i zostają przy swoim. */
        'text_scope' => 'wszystko',
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
            'text_scope' => in_array($input['text_scope'] ?? '', ['dziedziczenie', 'wszystko'], true)
                ? $input['text_scope'] : 'wszystko',
        ];
    }

    /** Czy jesteśmy w builderze Bricks (tam efekt tylko przeszkadza w edycji). */
    private function in_builder(): bool {
        return function_exists('bricks_is_builder_main') && bricks_is_builder_main();
    }

    public function render_css(): void {
        if ($this->in_builder()) return;
        $s = $this->get_settings();
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
    /* ŻADNYCH PRZEJŚĆ CSS NA WARSTWIE — i to jest warunek działania całego
       koloru liter, nie kosmetyka.

       Warstwa służy silnikowi za miarkę: żeby rozwinąć kolor globalny Bricks
       (`var(--bricks-color-…)`) do konkretnego `rgb()`, wpisuje go tutaj
       i ODCZYTUJE W TEJ SAMEJ CHWILI (resolveColor() w bg-shift.js). Gdy na
       warstwie wisi `transition: color`, getComputedStyle zwraca wartość
       SPRZED zmiany — czyli kolor odziedziczony po dokumencie. Każde
       wywołanie oddaje wtedy tę samą wartość, wszystkie sekcje dostają jeden
       kolor liter i tween nie ma czego przenikać.

       Zbieg nie jest wydumany — zdarzył się na żywej stronie: do
       `global_selectors` trybu ciemnego dopisano `div`, a warstwa jest divem.
       Zmierzone: trzy sekcje z kolorami `#81D4FA`, `#f5f5f5`, `#81D4FA`,
       a zmienna przez całe przewijanie stała na `rgb(26, 26, 26)` — kolorze
       tekstu dokumentu.

       Reguła jest tutaj, a nie w samym pomiarze, bo warstwą steruje wyłącznie
       GSAP i żadne przejście CSS nie ma na niej nic do roboty — ani przy
       mierzeniu, ani przy malowaniu tła, gdzie zjadałoby płynność scruba.
       To ta sama myśl co `.evk-bg-measure` przy odczycie kolorów sekcji,
       tyle że stała, bo warstwa jest nasza od początku do końca. */
    transition: none !important;
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
<?php if (($s['text_scope'] ?? 'wszystko') === 'wszystko') : ?>
/* Zasięg „wszystkie teksty": kolor dosięga też elementów Z WŁASNYM kolorem.
   Bez tego wariantu funkcja działała prawie nigdzie — Bricks nadaje kolor
   niemal każdemu tekstowi regułą po identyfikatorze, a wtedy dziedziczenie do
   niego nie dochodzi i litery zostają w swoim kolorze. Zgłoszone jako „zmiana
   koloru tekstu nie działa".

   `!important` bije regułę Bricksa NIEZALEŻNIE od jej szczegółowości — i to
   jest tu jedyna droga: reguła po identyfikatorze ma wyższą wagę niż
   cokolwiek, co da się napisać klasami.

   Pominięte są elementy, dla których `color` znaczy co innego niż „kolor
   liter": obrazy, pola formularzy i wszystko, co samo deklaruje `evk-bg-keep`.
   Bez tego przemalowywałoby się też to, co rysuje się `currentColor` — ikony
   w przyciskach potrafią przez to zniknąć na tle własnego przycisku. */
.evk-bg-handoff *:not(img):not(svg):not(input):not(textarea):not(select):not(.evk-bg-keep):not(.evk-bg-keep *) {
    color: var(--evk-bg-text, inherit) !important;
}
<?php endif; ?>
/* Przejścia CSS WYŁĄCZONE na czas przewijania koloru liter.
   Kolor piszemy do zmiennej CO KLATKĘ, a cudze `transition: color` restartuje
   się przy każdym zapisie — tekst nie dogania celu przez cały czas przewijania
   i wygląda, jakby zmiana koloru nie działała wcale. Sam moduł trybu ciemnego
   tej wtyczki dokłada domyślnie sekundowe przejście na `color` do `.brxe-text`,
   `.brxe-heading` i `.brxe-text-link`, a 0,4-sekundowe do `section` — więc
   zbieg nie jest teoretyczny, tylko domyślny.

   REGUŁA SIĘGA POTOMKÓW I ROBI TO ZAWSZE, niezależnie od zasięgu koloru. Przy
   zasięgu przez dziedziczenie kolor dostaje właśnie POTOMEK, a zmiana wartości
   odziedziczonej uruchamia jego własne przejście dokładnie tak samo jak
   ustawiona wprost. Zmierzone na potomku z sekundowym przejściem: zmienna
   `rgb(0, 200, 0)`, sekcja `rgb(0, 200, 0)`, potomek `rgb(117, 225, 117)` —
   i dopiero sekundę PO zatrzymaniu przewijania dochodził do celu.

   Bez wyjątków w rodzaju `:not(img):not(svg)`, którymi obwarowana jest reguła
   koloru wyżej. Tamte są po to, żeby czegoś NIE PRZEMALOWAĆ; tutaj nic nie
   malujemy, tylko gasimy animację na czas gestu. Wycięcie `svg` zostawiłoby
   lag na ikonach rysowanych `currentColor`, bo tryb ciemny daje `svg`
   sekundowe przejście na `color` i `fill`.

   Znacznik na <html> nakłada i zdejmuje silnik, więc poza samym przewijaniem
   przejścia działają normalnie — hover i reszta zostają nietknięte. Gdy
   znacznik schodzi, kolor jest już docelowy i nie ma czego animować.

   `!important` z tego samego powodu co przy kolorze: reguła po identyfikatorze
   ma wyższą wagę niż cokolwiek, co da się napisać klasami. */
html.evk-bg-scrub .evk-bg-handoff,
html.evk-bg-scrub .evk-bg-handoff * {
    transition: none !important;
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
