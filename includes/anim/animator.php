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
        // Pętla to co innego niż 'repeat'. Tamto znaczy „odtwórz ponownie przy
        // każdym wejściu w kadr", to — „kręć się bez końca".
        'loop'      => 0,
        'loop_yoyo' => 0,
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
        // Lista słów dla presetu „zmieniające się słowa" — po jednym na linię.
        // Trzymana jak from/to: tekstem w opcji, tablicą na froncie.
        'words'    => '',
        /* Zakres szerokości okna, w którym animacja w ogóle ma istnieć.
           Trzymane KLUCZEM breakpointu Bricks, nie liczbą: zmiana progu
           w builderze przestawia wtedy wszystkie wiersze naraz, zamiast
           zostawiać piksele, które kiedyś się zgadzały. Puste = bez granicy;
           na piksele rozwija je enqueue_assets() tuż przed wysłaniem. */
        'bp_min'   => '',
        'bp_max'   => '',
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
        /* Priorytet 3 — PO `wp_enqueue_scripts` (WordPress woła je z `wp_head`
           na priorytecie 1), więc uchwyty są już zarejestrowane i adresy da się
           przeczytać z kolejki zamiast składać je drugi raz. */
        add_action('wp_head', [$this, 'render_preload'], 3);
    }

    /**
     * Czy silnik nałoży temu wierszowi stan początkowy, który trzeba ukryć.
     *
     * Reguła jest PRZEPISANA Z SILNIKA, nie wymyślona — gdyby się rozjechała,
     * zasłona albo chowa bez powodu (to naprawiamy), albo puszcza błysk treści
     * (to gorsze). Punkt po punkcie, z `assets/js/animator.js`:
     *
     * — `textFx` → zawsze. `startVars()` zwraca stan początkowy dla efektów
     *   tekstowych NIEZALEŻNIE od `bezFrom`, więc maszyna do pisania zaczyna
     *   od pustego pola nawet pod hoverem.
     * — `hover`/`click` → nigdy. `attachInteractive()` woła
     *   `buildTimeline(..., bezFrom = !cfg.stan)`, więc `from` nie jest
     *   nakładane; a presety stanowe (`stan => true`) trzymają w `from` stan
     *   SPOCZYNKU, czyli to, co i tak widać.
     * — `exit`/`menu-close` → nigdy. Obie ścieżki budują tween
     *   z `immediateRender: false`, właśnie po to, żeby stan wyjściowy nie
     *   przykrył tego, co jest na ekranie.
     * — `viewport`/`load`/`scrub` z niepustym `from` → zasłona. Tam leci
     *   `fromTo`, które renderuje stan początkowy natychmiast.
     */
    private function wiersz_zaslania(array $row, array $presets): bool {
        $preset = $presets[$row['preset']] ?? [];
        if (!empty($preset['textFx'])) return true;

        if (!in_array($row['trigger'], ['viewport', 'load', 'scrub'], true)) return false;

        // `from` z wiersza jest tekstem z panelu — dopiero parser mówi, czy coś
        // z niego zostaje. Sama niepusta zawartość pola nie wystarcza: „ZŁA
        // LINIA" parsuje się na pustą tablicę i nic nie nakłada.
        if (evk_anim_parse_props((string) ($row['from'] ?? ''))) return true;

        return !empty($preset['from']);
    }

    /**
     * Selektory zasłony — tylko dla wierszy, które naprawdę coś ukrywają.
     *
     * ZGŁOSZONE Z UŻYCIA: „na wolniejszym komputerze elementy z animacjami
     * pojawiają się później (nawet jeśli animacja to hover)". Zasłona chowała
     * WSZYSTKO z `data-evk-anim` i `evk-anim-*` do czasu, aż silnik skończy
     * pierwszy przebieg — czyli po pobraniu i wykonaniu ~200 KiB GSAP-a.
     * Element z samym hoverem nie ma żadnego stanu początkowego do ukrycia,
     * więc czekał bez powodu; na mierzonej stronie 14 z 39 elementów.
     *
     * Atrybut łapiemy po FRAGMENCIE JSON-a (`"animation":"slug"`), bo tak go
     * zapisuje panel. Zapis z odstępami się nie dopasuje — i dobrze: pomyłka
     * w tę stronę zostawia dzisiejsze zachowanie (za dużo ukryte), a nigdy nie
     * puszcza błysku.
     *
     * BEZPIECZNIK: atrybut z własnym `preset` albo `trigger` nadpisuje wiersz,
     * a PHP nie wie na co — taki element zostaje pod zasłoną bez pytania.
     */
    private function selektory_zaslony(array $s): array {
        $presets = evk_anim_presets();
        $sel     = [];

        foreach ($s['animations'] as $row) {
            $row = $this->row_with_defaults($row);
            if ($row['slug'] === '' || !$this->wiersz_zaslania($row, $presets)) continue;
            $sel[] = '.evk-veil .evk-anim-' . $row['slug'];
            $sel[] = '.evk-veil [data-evk-anim*=\'"animation":"' . $row['slug'] . '"\']';
        }

        $sel[] = '.evk-veil [data-evk-anim*=\'"preset"\']';
        $sel[] = '.evk-veil [data-evk-anim*=\'"trigger"\']';

        return $sel;
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
            && evk_w_builderze()) {
            return;
        }
        $selektory = $this->selektory_zaslony($s);
        ?>
<style id="evk-anim-preveil">
<?php echo implode(",\n", $selektory); ?> { visibility: hidden !important; }

/* Zasłona POJEDYNCZEGO elementu, niezależna od tej wyżej.
 *
 * Zasłona dokumentu schodzi, gdy silnik skończy pierwszy przebieg. Element
 * z podziałem tekstu kończy wtedy dopiero połowę drogi — jego animacja powstaje
 * po wczytaniu webfontów, bo to metryki fontu decydują o łamaniu linii.
 * Pokazany w międzyczasie mrugnie treścią i skoczy do stanu początkowego,
 * czyli dokładnie ten błysk, przeciw któremu zasłona powstała w 1.27.2.
 *
 * Znacznik zakłada i zdejmuje silnik (assets/js/animator.js), a bezpiecznik
 * niżej zdejmuje go razem z zasłoną dokumentu — awaria wczytywania fontów nie
 * może ukryć treści na stałe. */
[data-evk-anim-czeka] { visibility: hidden !important; }
</style>
<script id="evk-anim-preveil-js">
(function () {
    /* REDUKCJA RUCHU — ZASŁONY NIE MA W OGÓLE.
     *
     * Przy `prefers-reduced-motion` silnik nakłada stan końcowy bez ruchu, więc
     * nie ma czego chować. Do 1.125.0 taki użytkownik i tak czekał na GSAP-a
     * tylko po to, żeby zobaczyć stronę — na dławionym procesorze 1,1–1,4 s.
     * Pytamy tu wprost, a nie przez `window.evkMotion`: oba skrypty drukują się
     * w <head> na priorytecie 1 i kolejność między nimi nie jest zagwarantowana. */
    var szanuj = <?php echo evk_motion_respect_reduced() ? 'true' : 'false'; ?>;
    if (szanuj && window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var h = document.documentElement;
    h.classList.add('evk-veil');

    /* BEZPIECZNIK: 1,5 s zamiast 3 s, ale z jednym ustępstwem dla wolnej sieci.
     *
     * Ma ratować przed awarią wczytywania, a nie chronić animację kosztem
     * treści — trzy sekundy patrzenia w puste miejsce są gorsze niż błysk,
     * przed którym zasłona broni.
     *
     * Ale gdy silnik JUŻ się wczytał (`window.evkAnimatorObecny`), odsłonięcie
     * na siłę pokazałoby treść, którą on za moment schowa do stanu
     * początkowego. Zmierzone na dławionej sieci: bezpiecznik wypadał 200 ms
     * przed końcem pierwszego przebiegu. Dajemy mu wtedy JEDNO dodatkowe okno,
     * więc w najgorszym razie i tak odsłaniamy po 3 s — tyle, ile było zawsze. */
    var proba = 0;
    (function czekaj() {
        setTimeout(function () {
            if (window.evkAnimatorObecny && proba < 1) { proba++; czekaj(); return; }
            h.classList.remove('evk-veil');
            /* Ten sam bezpiecznik obejmuje elementy czekające na fonty. Bez tego
               strona z niedostępnym webfontem trzymałaby podzielone teksty ukryte
               bez końca — a to gorsze niż brak animacji. */
            document.querySelectorAll('[data-evk-anim-czeka]').forEach(function (el) {
                el.removeAttribute('data-evk-anim-czeka');
            });
        }, 1500);
    })();
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
        $bpoints  = evk_anim_breakpoints();

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
                'repeat'    => !empty($row['repeat'])    ? 1 : 0,
                'loop'      => !empty($row['loop'])      ? 1 : 0,
                'loop_yoyo' => !empty($row['loop_yoyo']) ? 1 : 0,
                'order'    => max(0, min(999, intval($row['order'] ?? 0))),
                // 'external' szuka selektora w CAŁYM dokumencie, nie wśród
                // potomków — wyzwalacz zostaje na elemencie, rusza się ktoś inny.
                'targets'  => in_array($row['targets'] ?? '', ['self', 'children', 'selector', 'external'], true)
                    ? $row['targets'] : 'self',
                'selector' => sanitize_text_field($row['selector'] ?? ''),
                'pin'      => !empty($row['pin']) ? 1 : 0,
                // Zapisujemy tekst po normalizacji przez parser — dzięki temu
                // do opcji nie trafiają śmieci, a pole w panelu pokazuje to,
                // co silnik faktycznie dostanie.
                'from'     => evk_anim_props_to_text(evk_anim_parse_props((string) ($row['from'] ?? ''))),
                'to'       => evk_anim_props_to_text(evk_anim_parse_props((string) ($row['to']   ?? ''))),
                'words'    => evk_anim_words_to_text(evk_anim_parse_words((string) ($row['words'] ?? ''))),
                /* Tylko znane klucze. Nieznany zapisujemy jako pusty, czyli
                   „bez granicy" — a nie odrzucamy całego wiersza, bo próg mógł
                   po prostu zniknąć z Bricks po zapisie. */
                'bp_min'   => isset($bpoints[$row['bp_min'] ?? '']) ? (string) $row['bp_min'] : '',
                'bp_max'   => isset($bpoints[$row['bp_max'] ?? '']) ? (string) $row['bp_max'] : '',
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

    /**
     * Które wtyczki GSAP-a są tej bibliotece w ogóle potrzebne.
     *
     * Jedno źródło dla enqueue i dla preloadu — inaczej preload obiecywałby
     * przeglądarce plik, którego strona nie pobierze (albo odwrotnie), a to
     * kosztuje albo ostrzeżenie w konsoli, albo pobranie na darmo.
     */
    private function wtyczki_gsap(array $s): array {
        $presets = evk_anim_presets();
        $out     = ['split' => false, 'text' => false, 'scramble' => false];

        foreach ($s['animations'] as $row) {
            $row    = $this->row_with_defaults($row);
            $preset = $presets[$row['preset']] ?? [];
            if (!empty($preset['split'])) $out['split'] = true;
            $fx = $preset['textFx'] ?? '';
            if ($fx === 'type' || $fx === 'words') $out['text'] = true;
            if ($fx === 'scramble')                $out['scramble'] = true;
        }
        return $out;
    }

    /**
     * Adres, pod którym WordPress FAKTYCZNIE poda ten skrypt.
     *
     * Składany tak jak w `WP_Scripts::do_item()` — z wersją w query stringu
     * i przepuszczony przez `script_loader_src`. Gdyby preload różnił się od
     * adresu w `<script src>` choćby o `?ver=`, przeglądarka pobrałaby plik
     * DWA RAZY: preload trafiłby w próżnię, a strona i tak poszłaby po swoje.
     */
    private function url_skryptu(string $handle): string {
        $reg = wp_scripts()->registered[$handle] ?? null;
        if (!$reg || empty($reg->src)) return '';

        $src = $reg->src;
        if (!empty($reg->ver)) $src = add_query_arg('ver', $reg->ver, $src);

        return (string) apply_filters('script_loader_src', $src, $handle);
    }

    /**
     * Preload bibliotek animacji.
     *
     * ZGŁOSZONE Z UŻYCIA: „elementy pojawiają się z opóźnieniem", najmocniej
     * na Chrome na Androidzie i na starszym komputerze. Skrypty stoją w stopce
     * i bez `defer`, więc ich pobieranie rusza dopiero, gdy parser dojdzie na
     * koniec dokumentu — a treść z animacją czeka pod zasłoną do końca
     * pierwszego przebiegu silnika. Preload przesuwa START POBIERANIA na
     * początek dokumentu; wykonanie zostaje tam, gdzie było.
     *
     * Dlaczego nie `strategy => defer`: `evk-gsap` jest zależnością kilkunastu
     * innych uchwytów (marquee, hscroll, scroll reading, circular menu, tło
     * przy scrollu), a WordPress obniża strategię zależności do poziomu
     * najbardziej blokującego zależnego. `defer` na samym Animatorze nie
     * zmieniłby więc nic — a przestawienie wszystkich naraz to osobna zmiana.
     */
    public function render_preload(): void {
        $s = $this->get_settings();
        if (empty($s['enabled']) || empty($s['animations'])) return;
        if (is_admin()) return;
        if (empty($s['builder_preview'])
            && evk_w_builderze()) {
            return;
        }

        $w        = $this->wtyczki_gsap($s);
        $uchwyty  = ['evk-gsap', 'evk-scrolltrigger'];
        if ($w['split'])    $uchwyty[] = 'evk-splittext';
        if ($w['text'])     $uchwyty[] = 'evk-textplugin';
        if ($w['scramble']) $uchwyty[] = 'evk-scrambletext';
        $uchwyty[] = 'evk-animator';

        foreach ($uchwyty as $handle) {
            $url = $this->url_skryptu($handle);
            if ($url === '') continue;
            echo '<link rel="preload" as="script" fetchpriority="high" href="'
                . esc_url($url) . '">' . "\n";
        }
    }

    public function enqueue_assets(): void {
        $s = $this->get_settings();
        if (empty($s['enabled']) || empty($s['animations'])) return;
        if (is_admin()) return;
        if (empty($s['builder_preview'])
            && evk_w_builderze()) {
            return;
        }

        $presets = evk_anim_presets();

        // Biblioteka kluczowana slugiem — silnik czyta ją po klasie evk-anim-{slug}.
        $library = [];

        // Wtyczki GSAP-a liczy `wtyczki_gsap()` — ta sama funkcja, z której
        // korzysta preload. Dwa liczenia tego samego rozjechałyby się przy
        // pierwszym nowym presecie.
        $w = $this->wtyczki_gsap($s);

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

            // Słowa z tego samego powodu: pusta tablica przesłoniłaby brak listy.
            $words = evk_anim_parse_words((string) $row['words']);
            if ($words) $row['words'] = $words;
            else        unset($row['words']);

            /* Klucze breakpointów → piksele. Przeglądarka dostaje liczby, więc
               silnik nie musi wiedzieć nic o Bricks ani o nazwach progów.
               Klucz nieznany (próg usunięty w builderze po zapisie wiersza)
               znaczy „bez granicy" — i wtedy klucz MUSI zniknąć z payloadu,
               a nie pojechać jako 0: zero to granica na zerze, czyli warunek
               zawsze prawdziwy dla dolnej i zawsze fałszywy dla górnej. */
            foreach (['bp_min' => 'minW', 'bp_max' => 'maxW'] as $pole => $klucz) {
                $px = evk_anim_breakpoint_width((string) ($row[$pole] ?? ''));
                if ($px !== null) $row[$klucz] = $px;
                unset($row[$pole]);
            }

            $library[$row['slug']] = $row;
        }

        // Wtyczki tekstowe dociągamy tylko tam, gdzie są faktycznie użyte —
        // to dwa dodatkowe pobrania na stronę, która ich nie potrzebuje.
        $deps = ['evk-gsap', 'evk-scrolltrigger'];
        if ($w['split'])    $deps[] = 'evk-splittext';
        if ($w['text'])     $deps[] = 'evk-textplugin';
        if ($w['scramble']) $deps[] = 'evk-scrambletext';

        /* Na stronę jedzie wersja SKRÓCONA: 83,4 → 17,1 KiB, czyli o 79% mniej
           do pobrania i sparsowania. Komentarze w źródle są dokumentacją tego
           silnika i mają tam zostać — na stronie nie są nikomu potrzebne.
           Zgłoszone z użycia „elementy pojawiają się z opóźnieniem": zmierzone
           na lustrze przy dławieniu 6× silnik zaczynał działać dopiero o
           1757 ms, bo wcześniej trzeba pobrać i wykonać ~200 KiB JS-a.

           Gdyby skróconego pliku nie było — ktoś skopiował wtyczkę bez kroku
           budowania — bierzemy źródło: lepiej wolniej niż wcale. `SCRIPT_DEBUG`
           wymusza źródło świadomie, zgodnie ze zwyczajem WordPressa. */
        $skrocony = dirname(__DIR__, 2) . '/assets/js/animator.min.js';
        $plik = (!defined('SCRIPT_DEBUG') || !SCRIPT_DEBUG) && file_exists($skrocony)
            ? 'assets/js/animator.min.js'
            : 'assets/js/animator.js';

        wp_enqueue_script('evk-animator', EVOKE_ONE_URL . $plik,
            $deps, EVOKE_ONE_VERSION, true);

        wp_add_inline_script('evk-animator', 'window.evkAnimator = ' . wp_json_encode([
            'library'        => $library,
            'presets'        => $presets,
            'reducedMotion'  => !empty($s['reduced_motion']),
            /* `needsFonts` już nie wysyłamy — od 1.126.0 silnik na fonty nie
               czeka wcale. Poprawki po ich wczytaniu robi sam SplitText, przez
               zdarzenie `loadingdone` i tylko przy podziale na linie. Nasze
               czekanie rozbijało pierwszy przebieg na dwa: zmierzone na lustrze
               przy dławieniu 6× pierwsze kawałki podziału wychodziły o 6137 ms,
               choć fonty były gotowe o 1932 ms. */
        ]) . ';', 'before');
    }
}

EVK_Animator::get_instance();
