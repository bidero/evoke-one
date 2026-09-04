<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Sierotki
 *
 * Po polsku spójnik jednoliterowy nie ma prawa zostać na końcu wiersza — to
 * reguła składu, nie upodobanie. Moduł zamienia spację po nim na nierozdzielającą,
 * więc słowo przechodzi do następnego wiersza razem z nim. To samo robi
 * z liczbą i jej jednostką: „5 km" ma się nie rozjeżdżać między wierszami.
 *
 * GDZIE, I DLACZEGO AKURAT TAM. Gotowe wtyczki tego rodzaju wpinają się
 * w `the_content`, `the_title` i `the_excerpt` — a na stronie budowanej
 * Bricksem większość tekstu przez te filtry NIE PRZECHODZI: nagłówki, teksty
 * i przyciski builder renderuje sam. Dlatego głównym wejściem jest tu
 * `bricks/frontend/render_data`, ten sam, którym jedzie silnik tłumaczeń
 * (includes/50-translation-engine.php) i podmiany parallaksu. Klasyczne filtry
 * zostają dla wpisów bloga i tytułów.
 *
 * KOLEJNOŚĆ WZGLĘDEM TŁUMACZEŃ jest tu regułą, nie szczegółem. Tłumaczenia
 * pracują na priorytecie 1; sierotki muszą pójść PO nich, inaczej poprawiałyby
 * tekst, który za chwilę zostanie podmieniony na inny język.
 *
 * ZAPIS W BAZIE ZOSTAJE NIETKNIĘTY — zamiana dzieje się przy wyświetlaniu.
 * Twarda spacja zapisana do bazy psułaby wyszukiwanie („w Polsce" nie
 * znalazłoby się po wpisaniu frazy ze zwykłą spacją) i wracałaby do edytora.
 */

class EVK_Sierotki {

    /** Twarda spacja jako bajty UTF-8. Znak, nie encja — patrz `zamien()`. */
    const NBSP = "\xC2\xA0";

    /**
     * Znaczniki, których wnętrza nie ruszamy.
     *
     * W kodzie i w polu formularza twarda spacja zmienia to, co pokazujesz albo
     * co użytkownik wyśle. `<textarea>` jest tu z tego drugiego powodu i łatwo
     * o nim zapomnieć — jego treść wygląda w źródle jak zwykły tekst.
     */
    const POMIJANE = ['pre', 'code', 'script', 'style', 'textarea', 'kbd', 'samp'];

    /** Znaczniki bez treści — nie wchodzą na stos, bo się nie zamykają. */
    const PUSTE = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
                   'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private static $instance = null;

    private $defaults = [
        'enabled'  => 0,
        'jednostki' => 1,
        /* Klasy i identyfikatory, nie pełne selektory CSS. Po stronie serwera
           mamy ciąg znaków, a nie drzewo — dopasowanie potomka czy sąsiada nie
           miałoby się tu o co oprzeć. Obietnica „selektory CSS" byłaby więc
           obietnicą bez pokrycia; pole mówi wprost, co przyjmuje. */
        'wyjatki'  => '',
    ];

    public static function get_instance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_init', [$this, 'register_settings']);

        if (empty($this->get_settings()['enabled'])) return;

        /* PO tłumaczeniach (priorytet 1) i po podmianach parallaksu. */
        add_filter('bricks/frontend/render_data', [$this, 'filtr'], 20);

        /* Klasyczne filtry — dla treści, która nie przechodzi przez Bricksa.
           `the_content` po `wpautop` (10), żeby akapity były już zbudowane. */
        add_filter('the_content', [$this, 'filtr'], 20);
        add_filter('the_excerpt', [$this, 'filtr'], 20);
        add_filter('the_title',   [$this, 'filtr_tytulu'], 20);
    }

    public function get_settings(): array {
        return wp_parse_args(get_option('evk_sierotki', []), $this->defaults);
    }

    public function register_settings(): void {
        register_setting('evoke_one_sierotki', 'evk_sierotki', [
            'type'              => 'array',
            'sanitize_callback' => [$this, 'sanitize_settings'],
        ]);
    }

    public function sanitize_settings($input): array {
        return [
            // Przełącznik jedzie AJAX-em — zachowujemy go, gdy nie ma go w POST.
            'enabled'   => evk_preserve_toggle($input, 'evk_sierotki'),
            'jednostki' => !empty($input['jednostki']) ? 1 : 0,
            'wyjatki'   => sanitize_text_field((string)($input['wyjatki'] ?? '')),
        ];
    }

    /**
     * Tytuł bywa używany tam, gdzie twarda spacja przeszkadza.
     *
     * `the_title` odpala się także w panelu (listy wpisów), w kanale RSS i przy
     * budowaniu `<title>` — a tam znak U+00A0 potrafi trafić do wyników
     * wyszukiwania i do kanałów. Poprawiamy więc wyłącznie to, co czytelnik
     * widzi na stronie.
     */
    public function filtr_tytulu($tytul) {
        if (is_admin() || is_feed()) return $tytul;
        if (doing_filter('wp_title') || doing_filter('document_title_parts')) return $tytul;
        return $this->filtr($tytul);
    }

    public function filtr($html) {
        if (!is_string($html) || $html === '') return $html;
        if (is_admin() || is_feed()) return $html;
        return $this->popraw($html);
    }

    /** Lista klas i identyfikatorów do pominięcia, znormalizowana. */
    private function wyjatki(): array {
        $surowe = (string)$this->get_settings()['wyjatki'];
        if ($surowe === '') return [];

        $out = [];
        foreach (preg_split('/[\s,]+/', $surowe) as $w) {
            $w = trim($w);
            if ($w === '') continue;
            // Kropka i krzyżyk wolno zostawić albo pominąć — obie formy piszą
            // ludzie i obie znaczą to samo.
            $out[] = ltrim($w, '.#');
        }
        return $out;
    }

    /**
     * Poprawia tekst POMIĘDZY znacznikami, nie dotykając ich wnętrza.
     *
     * Rozbicie na znaczniki i tekst jest tu warunkiem poprawności, nie
     * optymalizacją. Wyrażenie puszczone na cały HTML weszłoby w atrybuty —
     * `alt="a to jest opis"`, `href`, nazwy klas — i twarda spacja wylądowałaby
     * w adresie albo w nazwie klasy.
     *
     * Stan „pomijamy" trzymamy GŁĘBOKOŚCIĄ STOSU, a nie flagą: `<pre>` bywa
     * zagnieżdżony w czymś, co też pomijamy, a flaga skasowana pierwszym
     * zamknięciem wypuściłaby nas z pomijania o jeden poziom za wcześnie.
     */
    public function popraw(string $html): string {
        if ($html === '' || strpos($html, ' ') === false) return $html;

        $wyjatki = $this->wyjatki();
        $czesci  = preg_split('/(<[^>]*+>)/u', $html, -1,
                              PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($czesci === false) return $html;

        $stos    = [];
        $pomijaj = null;   // głębokość stosu, na której zaczęło się pomijanie
        $out     = '';

        foreach ($czesci as $czesc) {
            if ($czesc === '' || $czesc[0] !== '<') {
                $out .= ($pomijaj === null) ? $this->zamien($czesc) : $czesc;
                continue;
            }

            $out .= $czesc;

            // Komentarze, deklaracje i sekcje CDATA nie mają nazwy znacznika.
            if (isset($czesc[1]) && ($czesc[1] === '!' || $czesc[1] === '?')) continue;

            if ($czesc[1] === '/') {
                if (!$stos) continue;
                array_pop($stos);
                /* `<=`, nie `<`. W `$pomijaj` siedzi głębokość SPRZED wepchnięcia
                   pomijanego znacznika, czyli ta, do której mamy wrócić — więc
                   równość znaczy „już wróciliśmy". Przy `<` pomijanie nie
                   kończyło się nigdy dla znacznika na najwyższym poziomie:
                   zmierzone — po `</div class="kod">` cała reszta tekstu
                   zostawała nietknięta. */
                if ($pomijaj !== null && count($stos) <= $pomijaj) $pomijaj = null;
                continue;
            }

            if (!preg_match('/^<([a-zA-Z][a-zA-Z0-9-]*)/', $czesc, $m)) continue;
            $nazwa = strtolower($m[1]);

            // Samozamykający się i pusty nie wchodzą na stos — nie mają czego
            // domknąć, a wepchnięte przesunęłyby wszystkie głębokości.
            if (in_array($nazwa, self::PUSTE, true) || substr($czesc, -2) === '/>') continue;

            $stos[] = $nazwa;

            if ($pomijaj !== null) continue;
            if (in_array($nazwa, self::POMIJANE, true) || $this->wyjety($czesc, $wyjatki)) {
                $pomijaj = count($stos) - 1;
            }
        }

        return $out;
    }

    /** Czy ten znacznik otwierający nosi klasę albo identyfikator z listy. */
    private function wyjety(string $znacznik, array $wyjatki): bool {
        if (!$wyjatki) return false;

        $nazwy = [];
        if (preg_match('/\sclass\s*=\s*"([^"]*)"/i', $znacznik, $m)
            || preg_match("/\sclass\s*=\s*'([^']*)'/i", $znacznik, $m)) {
            $nazwy = preg_split('/\s+/', trim($m[1]));
        }
        if (preg_match('/\sid\s*=\s*"([^"]*)"/i', $znacznik, $m)
            || preg_match("/\sid\s*=\s*'([^']*)'/i", $znacznik, $m)) {
            $nazwy[] = trim($m[1]);
        }

        foreach ($nazwy as $n) {
            if ($n !== '' && in_array($n, $wyjatki, true)) return true;
        }
        return false;
    }

    /**
     * Sama zamiana — na kawałku tekstu, w którym nie ma już żadnych znaczników.
     *
     * DWUKROTNE PRZETWORZENIE NIE SZKODZI i nie wymaga osobnej flagi: `\s`
     * w trybie UTF-8 nie obejmuje U+00A0 ani encji `&nbsp;`, więc tekst już
     * poprawiony — z bazy, z tłumaczeń albo z cudzego filtra — nie ma tu czego
     * dopasować. To jest powód, dla którego wzorce stoją na `\s`, a nie na
     * klasie znaków białych z `\p{Z}`.
     */
    private function zamien(string $tekst): string {
        if (strpos($tekst, ' ') === false && strpos($tekst, "\t") === false
            && strpos($tekst, "\n") === false) return $tekst;

        /* SPÓJNIKI JEDNOLITEROWE. Warunek z lewej pilnuje, żeby to była
           osobna litera, a nie koniec wyrazu: bez niego „mam a" łapałoby się
           tak samo jak „ma a". Warunek z prawej — żeby po spacji naprawdę coś
           stało; sierotka na końcu tekstu nie ma z czym się skleić. */
        $tekst = preg_replace(
            '/(?<![\p{L}\p{N}])([aiouwzAIOUWZ])\s+(?=[\p{L}\p{N}\x{201E}\x{00AB}"\'(\[])/u',
            '$1' . self::NBSP,
            $tekst
        );

        if (empty($this->get_settings()['jednostki'])) return $tekst;

        /* LICZBA I JEDNOSTKA. Krótki wyraz po liczbie to prawie zawsze
           jednostka albo skrót („5 km", „2024 r.", „3 szt."), a rozdzielenie
           ich między wiersze czyta się źle niezależnie od tego, który to
           przypadek. Granica trzech liter jest po to, żeby nie skleić liczby
           z całym następnym zdaniem. */
        $tekst = preg_replace(
            '/(\d)\s+(?=(?:[\p{L}]{1,3}\b|%|\x{2030}|\x{00B0}))/u',
            '$1' . self::NBSP,
            $tekst
        );

        return $tekst;
    }
}

EVK_Sierotki::get_instance();
