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
            add_action('wp_head',                         [$this, 'print_early_positioner'], 2);
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
             trzecią kosztu ruchu.

             BYŁ TU TAKŻE BLOK `@supports (animation-timeline: view())`,
             prowadzący ten sam ruch osią widoku za 121 zamiast 1914 ms.
             WYCOFANY w 1.155.1: oś widoku mierzy położenie względem
             NAJBLIŻSZEGO PRZODKA BĘDĄCEGO KONTENEREM PRZEWIJANIA, a sekcje
             Bricksa siedzą w kontenerach z `overflow:hidden`, które nigdy się
             nie przewijają. Postęp animacji stał wtedy w miejscu i parallax
             nie działał wcale. Sprawdzone na żywej stronie: 11 z 11 sekcji
             miało takiego przodka. Zielony test brał się stąd, że fixture był
             płaski — dziś ma ten sam kontener co strona.
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

        );
    }

    /**
     * Ustawienie przesunięcia ZANIM przeglądarka pomaluje sekcję.
     *
     * ZGŁOSZONE Z UŻYCIA: „obraz pojawia się i momentalnie przesuwa się w górę
     * minimalnie". Zgłaszający wyłączył Animatora i objaw został — to zawęziło
     * rzecz do parallaxu.
     *
     * PRZYCZYNA. Reguła warstwy jest w nagłówku, więc maluje się od razu — ale
     * bierze `var(--evk-par-y, 0px)`, czyli SPOCZYNEK. Prawdziwe przesunięcie
     * zależy od pozycji przewinięcia i wysokości okna, których PHP nie zna,
     * więc wpisuje je dopiero `parallax.js` ze stopki. Zmierzone na sekcji nad
     * zgięciem: przez pierwsze ~30–70 ms warstwa stoi na zerze, po czym jedną
     * klatką wskakuje na 19 px. To jest cały przeskok.
     *
     * DLACZEGO OBSERWATOR DRZEWA, A NIE PĘTLA KLATEK. Obie ustawiają wartość
     * przed pierwszym malowaniem, więc obie usuwają przeskok. Zmierzone na
     * stronie z 3000 węzłów, dławienie CPU 4×:
     *
     *     obserwator drzewa   7 wywołań    3,4 ms
     *     pętla rAF           3 wywołania   20 ms
     *
     * Spodziewałem się odwrotnie — obserwator zapala się przy każdej partii
     * węzłów, więc wyglądał drożej. Pętla jednak przeszukuje CAŁE rosnące
     * drzewo w każdej klatce, a obserwator dostaje wyłącznie dołożone węzły
     * i robi na nich tani test atrybutu.
     *
     * WZÓR MUSI BYĆ CO DO ZNAKU TEN SAM co w `parallax.js`. Inaczej zamienimy
     * jeden przeskok na drugi — mniejszy, ale przy pierwszym przewinięciu.
     * Pilnuje tego osobne sprawdzenie porównujące obie drogi na tej samej
     * stronie, bo to jest kopia wzoru i sama z siebie zacznie kiedyś odjeżdżać.
     */
    public function print_early_positioner(): void {
        $sila  = $this->get_parallax_value();
        $skala = $this->get_scale_value();
        ?>
<script id="evk-parallax-wczesnie">
(function () {
  var SILA = <?php echo wp_json_encode($sila); ?>, SKALA = <?php echo wp_json_encode($skala); ?>;
  // Przy „ogranicz ruch" reguła i tak zeruje przesunięcie — nie ma co liczyć.
  if (window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  var ustaw = function (el) {
    var s = parseFloat(el.getAttribute('data-parallax')) || SILA;
    var r = el.getBoundingClientRect();
    var p = ((r.top + r.height / 2) - innerHeight / 2) / (innerHeight / 2);
    el.style.setProperty('--evk-par-y', (p * (s * 100)) + 'px');
    /* Skala własna elementu miała dotąd tę samą wadę co przesunięcie: reguła
       niosła domyślną, a skrypt nadpisywał ją klatkę później. Skoro i tak tu
       jesteśmy, zamykamy to za darmo. */
    var sk = parseFloat(el.getAttribute('data-skala')) || SKALA;
    if (Math.abs(sk - SKALA) > 0.001) el.style.setProperty('--evk-par-scale', String(sk));
  };
  /* Pomiar idzie OD RAZU, w wywołaniu obserwatora. Próbowałem odłożyć go do
     `requestAnimationFrame` — po układzie, przed malowaniem — bo wyglądało to
     na bezpieczniejsze. Okazało się niepotrzebne (rozbieżność, która mnie do
     tego popchnęła, brała się z dwóch różnych sił w teście, nie z układu),
     a kosztowało jedną klatkę opóźnienia: wartość wchodziła już po pierwszym
     odczycie. Wersja prostsza jest tu i szybsza, i dokładniejsza. */
  var obs = new MutationObserver(function (paczki) {
    for (var i = 0; i < paczki.length; i++) {
      var dodane = paczki[i].addedNodes;
      for (var j = 0; j < dodane.length; j++) {
        var n = dodane[j];
        if (n.nodeType === 1 && n.hasAttribute && n.hasAttribute('data-parallax-css')) ustaw(n);
      }
    }
  });
  obs.observe(document.documentElement, { childList: true, subtree: true });
  // Po sparsowaniu dokumentu nie ma czego wyprzedzać — dalej prowadzi
  // `parallax.js`. Obserwator zostawiony na stałe pracowałby przy każdej
  // podmianie treści, nic już nie wnosząc.
  document.addEventListener('DOMContentLoaded', function () { obs.disconnect(); });
})();
</script>
        <?php
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
