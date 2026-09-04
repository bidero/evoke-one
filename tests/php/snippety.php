<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Snippety jako wpisy — wykonanie, opakowanie i migracja.
 *
 * Treść snippetu JEST WYKONYWANA, więc to jest ten moduł, w którym pomyłka
 * kosztuje najwięcej. Sprawdzamy trzy rzeczy, każdą na prawdziwym kodzie
 * wtyczki, nie na opisie:
 *
 *  · czy rodzaj rozstrzyga, co się z treścią dzieje (CSS ma NIE przechodzić
 *    przez `eval()`, PHP ma działać bez pisania `<?php`);
 *  · czy wpis wyłączony naprawdę nie wchodzi;
 *  · czy migracja czterech starych okien zachowuje ich zachowanie co do znaku.
 *
 *   php tests/php/snippety.php <scenariusz>
 */
require __DIR__ . '/_wp-stubs.php';

define('DAY_IN_SECONDS', 86400);
define('WP_DEBUG', false);

function wp_specialchars_decode($s, $q = null) { return html_entity_decode((string) $s, ENT_QUOTES); }
function set_transient($k, $v, $t = 0) { $GLOBALS['transients'][$k] = $v; return true; }
function get_transient($k) { return $GLOBALS['transients'][$k] ?? false; }
function delete_transient($k) { unset($GLOBALS['transients'][$k]); return true; }
function wp_normalize_path($s) { return str_replace('\\', '/', (string) $s); }
function wp_strip_all_tags($s, $br = false) { return strip_tags((string) $s); }
function wp_create_nonce($akcja = -1) { return 'nonce'; }

/* Tryb zaawansowany trzyma treść w opcji, ale czyta ją WPROST z bazy
   (`$wpdb->get_var`), z pominięciem pamięci podręcznej opcji. Atrapa robi to
   samo po naszej tablicy opcji — inaczej scenariusz „awaria-advanced" padłby
   na braku `$wpdb`, zanim doszedłby do badanej rzeczy. */
class EVK_Test_Wpdb {
    public $options = 'wp_options';
    public function prepare($zapytanie, ...$args) { return [$zapytanie, $args]; }
    public function get_var($przygotowane) {
        $nazwa = $przygotowane[1][0] ?? '';
        return $GLOBALS['options'][$nazwa] ?? null;
    }
    public function replace($tabela, $dane, $format = null) {
        $GLOBALS['options'][$dane['option_name']] = $dane['option_value'];
        return 1;
    }
}
$GLOBALS['wpdb'] = new EVK_Test_Wpdb();
/* Rejestracja typu wpisu nas tu nie interesuje — sprawdza ją
   tests/php/odpornosc.php. Przyjmujemy zgłoszenie i idziemy dalej. */
function register_post_type($typ, $args = []) { $GLOBALS['typy'][$typ] = $args; return (object) $args; }
function current_time($t = 'mysql') { return '2027-03-01 10:00:00'; }
$GLOBALS['transients'] = [];

/* Stuby dla handlera POST — snippety zmieniają stan formularzem, nie AJAX-em.
   `wp_safe_redirect` RZUCA zamiast przekierowywać: handler kończy się `exit`,
   więc bez tego przebieg umierałby przed odczytaniem wyniku. */
class EVK_Redirect extends Exception { public $url; }
function wp_safe_redirect($url, $status = 302) { $e = new EVK_Redirect(); $e->url = $url; throw $e; }
function wp_verify_nonce($nonce, $akcja) { return empty($GLOBALS['zly_nonce']) ? 1 : false; }
function wp_nonce_field(...$a) {}
function admin_url($sciezka = '') { return 'https://przyklad.test/wp-admin/' . $sciezka; }
function get_post_type($id) { $p = get_post($id); return $p ? ($p->post_type ?? false) : false; }
function wp_delete_post($id, $force = false) { unset($GLOBALS['posts_store'][$id]); return true; }
function esc_textarea($s) { return $s; }
function selected($a, $b, $echo = true) {}
function submit_button(...$a) {}

require_once EVK_TEST_ROOT . '/includes/snippets/definitions.php';
require_once EVK_TEST_ROOT . '/includes/snippets/wpisy.php';
require_once EVK_TEST_ROOT . '/includes/snippets/validation.php';
require_once EVK_TEST_ROOT . '/includes/snippets/wersje.php';
require_once EVK_TEST_ROOT . '/includes/snippets/engine.php';
require_once EVK_TEST_ROOT . '/includes/snippets/panel.php';   // evk_snippety_url()
require_once EVK_TEST_ROOT . '/includes/snippets/ajax.php';    // handler POST

$GLOBALS['options'][EVK_SNIPPETS_ENABLED_OPTION] = 1;

/** Zakłada wpis i zwraca jego identyfikator. */
function zaloz(array $dane): int {
    return evk_snippet_zapisz_wpis(array_merge(
        ['tytul' => 'Testowy', 'kod' => '', 'rodzaj' => 'php',
         'miejsce' => 'head', 'grupa' => '', 'wlaczony' => 1, 'kolejnosc' => 0],
        $dane));
}

/** Uruchamia zarejestrowane akcje danego haka i zwraca to, co wypisały. */
function odpal_hak(string $hak): string {
    ob_start();
    foreach ($GLOBALS['hooks'][$hak] ?? [] as $cb) $cb();
    return (string) ob_get_clean();
}

/** Przebieg silnika: to samo `init`, które odpala WordPress. */
function przebieg(): void {
    $GLOBALS['hooks']['wp_head'] = [];
    $GLOBALS['hooks']['wp_footer'] = [];
    $GLOBALS['hooks']['admin_head'] = [];
    foreach ($GLOBALS['hooks']['init'] ?? [] as $cb) $cb();
}

$scenariusz = $argv[1] ?? '';
$out = [];

if ($scenariusz === 'opakowanie') {
    $css = zaloz(['rodzaj' => 'css',  'kod' => 'body { color: red }', 'miejsce' => 'head']);
    $js  = zaloz(['rodzaj' => 'js',   'kod' => 'console.log(1)',      'miejsce' => 'footer']);
    $htm = zaloz(['rodzaj' => 'html', 'kod' => '<p>cześć</p>',        'miejsce' => 'head']);
    $php = zaloz(['rodzaj' => 'php',  'kod' => 'echo "z php";',       'miejsce' => 'head']);

    przebieg();
    $out['head']   = odpal_hak('wp_head');
    $out['footer'] = odpal_hak('wp_footer');
    $out['id_css'] = $css;

} elseif ($scenariusz === 'wylaczony') {
    zaloz(['rodzaj' => 'html', 'kod' => '<b>widoczny</b>',  'wlaczony' => 1]);
    zaloz(['rodzaj' => 'html', 'kod' => '<b>ukryty</b>',    'wlaczony' => 0]);
    przebieg();
    $out['head'] = odpal_hak('wp_head');

} elseif ($scenariusz === 'kolejnosc') {
    zaloz(['rodzaj' => 'html', 'kod' => 'C', 'kolejnosc' => 30]);
    zaloz(['rodzaj' => 'html', 'kod' => 'A', 'kolejnosc' => 10]);
    zaloz(['rodzaj' => 'html', 'kod' => 'B', 'kolejnosc' => 20]);
    przebieg();
    $out['head'] = preg_replace('/\s+/', '', odpal_hak('wp_head'));

} elseif ($scenariusz === 'miejsca') {
    zaloz(['rodzaj' => 'html', 'kod' => 'FRONT',  'miejsce' => 'head']);
    zaloz(['rodzaj' => 'html', 'kod' => 'STOPKA', 'miejsce' => 'footer']);
    zaloz(['rodzaj' => 'html', 'kod' => 'PANEL',  'miejsce' => 'admin_head']);
    zaloz(['rodzaj' => 'php',  'kod' => '$GLOBALS["od_razu"] = "TAK";', 'miejsce' => 'init']);
    przebieg();
    $out['head']    = odpal_hak('wp_head');
    $out['footer']  = odpal_hak('wp_footer');
    $out['panel']   = odpal_hak('admin_head');
    $out['od_razu'] = $GLOBALS['od_razu'] ?? '';

} elseif ($scenariusz === 'domkniecie') {
    // Domknięcie znacznika w treści nie ma prawa wyjść z bloku.
    zaloz(['rodzaj' => 'css', 'kod' => 'a{}</style><script>alert(1)</script>']);
    przebieg();
    $out['head'] = odpal_hak('wp_head');

} elseif ($scenariusz === 'migracja') {
    // Cztery stare okna: wpisy o stałych slugach, bez żadnych metadanych.
    foreach (evk_snippets_defs() as $def) {
        $tresc = $def['slug'] === 'evk-snippet-admin-head' ? '' : ('<b>' . $def['slug'] . '</b>');
        wp_insert_post([
            'post_title' => '', 'post_content' => $tresc, 'post_status' => 'private',
            'post_type' => 'evk_code_snippet', 'post_name' => $def['slug'],
        ]);
    }
    evk_snippety_migruj();

    $out['wpisy'] = array_map(function ($w) {
        return ['slug' => $w['slug'], 'rodzaj' => $w['rodzaj'], 'miejsce' => $w['miejsce'],
                'wlaczony' => $w['wlaczony'], 'tytul' => $w['tytul'], 'kod' => $w['kod']];
    }, evk_snippety_wszystkie());
    $out['kopia']  = get_option(EVK_SNIPPETY_KOPIA);
    /* Powtórzona migracja nie ma prawa niczego ruszyć — a „niczego" znaczy
       przede wszystkim: nie ma prawa cofnąć zmian, które wprowadziłeś PO
       niej. Liczenie wpisów tego nie łapie (migracja i tak żadnego nie
       zakłada); łapie to dopiero przestawiony rodzaj. */
    $wpisy = evk_snippety_wszystkie();
    $pierwszy = $wpisy[0]['id'];
    evk_snippet_zapisz_wpis(['id' => $pierwszy, 'tytul' => 'Moja nazwa', 'kod' => $wpisy[0]['kod'],
                             'rodzaj' => 'php', 'miejsce' => 'footer', 'grupa' => 'Moja grupa',
                             'wlaczony' => 1, 'kolejnosc' => 5]);
    $ile_przed = count($GLOBALS['posts_store']);
    evk_snippety_migruj();

    $po = evk_snippety_wszystkie();
    $ten = null;
    foreach ($po as $w) if ($w['id'] === $pierwszy) $ten = $w;

    $out['drugi_raz'] = [
        'bez_nowych'   => count($GLOBALS['posts_store']) === $ile_przed,
        'rodzaj'       => $ten['rodzaj']  ?? '',
        'miejsce'      => $ten['miejsce'] ?? '',
        'grupa'        => $ten['grupa']   ?? '',
    ];

} elseif ($scenariusz === 'panel') {
    /* To samo, co scenariusz „miejsca", ale w żądaniu PANELU. Bez tego warunki
       po `is_admin()` w silniku nie mają jak się pomylić — obie strony
       sprawdzenia wyglądają tak samo z frontu. */
    $GLOBALS['is_admin'] = true;
    zaloz(['rodzaj' => 'html', 'kod' => 'FRONT', 'miejsce' => 'head']);
    zaloz(['rodzaj' => 'html', 'kod' => 'PANEL', 'miejsce' => 'admin_head']);
    przebieg();
    $out['panel']       = odpal_hak('admin_head');
    $out['head']        = odpal_hak('wp_head');
    $out['hakow_front'] = count($GLOBALS['hooks']['wp_head'] ?? []);

} elseif ($scenariusz === 'szablon') {
    // Tryb dawny: HTML ze wstawkami PHP — tak działały cztery okna.
    zaloz(['rodzaj' => 'szablon', 'kod' => 'przed <?php echo 2 + 2; ?> po']);
    przebieg();
    $out['head'] = trim(odpal_hak('wp_head'));

} elseif ($scenariusz === 'akcje') {
    /* AKCJE Z LISTY POD ADRESEM BEZ `sub` — sedno poprawki 1.139.1.
     *
     * Pasek boczny prowadzi do Narzędzi adresem `?page=evoke-one&tab=narzedzia`,
     * a `tab-narzedzia.php` sam domyśla sobie `sub=snippets`. Ekran się rysuje,
     * formularze wracają na ten sam adres — i do 1.139.0 brama je odrzucała,
     * bo wymagała `sub=snippets` w `$_GET`. Włącznik, usuwanie i zapis nie
     * robiły NIC, zależnie od tego, którędy się na ekran weszło.
     */
    $GLOBALS['is_admin'] = true;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['page' => 'evoke-one', 'tab' => 'narzedzia'];   // BEZ `sub` — o to chodzi

    /** Odpala `admin_init` z zadanym POST-em; zwraca adres przekierowania albo ''. */
    $wyslij = function (array $post): string {
        $_POST = $post;
        try {
            foreach ($GLOBALS['hooks']['admin_init'] ?? [] as $cb) $cb();
        } catch (EVK_Redirect $e) {
            return (string) $e->url;
        }
        return '';
    };
    $stan = function (int $id) {
        foreach (evk_snippety_wszystkie() as $w) if ($w['id'] === $id) return $w;
        return null;
    };
    $nonce = ['evk_snippets_nonce_field' => 'x'];

    $id = zaloz(['tytul' => 'Sticky header', 'rodzaj' => 'css', 'kod' => 'a{}', 'wlaczony' => 1]);

    $out['przed']       = $stan($id)['wlaczony'];
    $out['przekierowanie'] = $wyslij($nonce + ['evk_przelacz_wpis' => (string) $id]) !== '';
    $out['po_wylaczeniu']  = $stan($id)['wlaczony'];
    $wyslij($nonce + ['evk_przelacz_wpis' => (string) $id]);
    $out['po_wlaczeniu']   = $stan($id)['wlaczony'];

    // Zapis nowego wpisu — ta sama brama, ta sama droga.
    $wyslij($nonce + ['evk_zapisz_wpis' => 'Zapisz', 'evk_wpis_id' => '0',
                      'evk_tytul' => 'Z formularza', 'evk_kod' => 'b{}',
                      'evk_rodzaj' => 'css', 'evk_miejsce' => 'footer',
                      'evk_grupa' => 'SEO', 'evk_wlaczony' => '1', 'evk_kolejnosc' => '20']);
    $tytuly = array_column(evk_snippety_wszystkie(), 'tytul');
    $out['zapisany'] = in_array('Z formularza', $tytuly, true);

    // Bez nonce'a brama musi milczeć — po zdjęciu warunków na adres to jedyne wejście.
    $przed_bez = $stan($id)['wlaczony'];
    $wyslij(['evk_przelacz_wpis' => (string) $id]);
    $out['bez_nonce_bez_zmian'] = $stan($id)['wlaczony'] === $przed_bez;

    // Podrobiony nonce → `wp_die`, nie cicha zmiana.
    $GLOBALS['zly_nonce'] = true;
    $zablokowany = false;
    try { $wyslij($nonce + ['evk_przelacz_wpis' => (string) $id]); }
    catch (Throwable $e) { $zablokowany = true; }
    $GLOBALS['zly_nonce'] = false;
    $out['zly_nonce_zablokowany'] = $zablokowany;
    $out['zly_nonce_bez_zmian']   = $stan($id)['wlaczony'] === $przed_bez;

    /* Usunięcie patrzy na TYP wpisu, nie na samą liczbę: identyfikator
       przychodzi z formularza, więc bez tego dałoby się stąd skasować dowolny
       wpis w witrynie. */
    $obcy = wp_insert_post(['post_title' => 'Strona firmy', 'post_type' => 'page', 'post_status' => 'publish']);
    $wyslij($nonce + ['evk_usun_wpis' => (string) $obcy]);
    $out['obcy_wpis_zyje'] = get_post($obcy) !== null;

    $wyslij($nonce + ['evk_usun_wpis' => (string) $id]);
    $out['snippet_usuniety'] = $stan($id) === null;
}

// =========================================================================
// IZOLACJA BŁĘDU KRYTYCZNEGO
// =========================================================================

/** Stan po wywrotce: co zgasło, co pracuje, co silnik zapisał przy wpisie. */
function stan_po_awarii(array $ids): array {
    $wpisy = [];
    foreach (evk_snippety_wszystkie() as $w) {
        if (!in_array($w['id'], $ids, true)) continue;
        $wpisy[$w['tytul']] = ['wlaczony' => $w['wlaczony'], 'awaria' => $w['awaria']];
    }
    return [
        'wpisy'    => $wpisy,
        'glowny'   => (int) get_option(EVK_SNIPPETS_ENABLED_OPTION, 0),
        'advanced' => (int) get_option(EVK_SNIPPETS_ADVANCED_ENABLED, 0),
        'transjent'=> get_transient(EVK_SNIPPETS_FATAL_TRANSIENT),
    ];
}

if ($scenariusz === 'awaria-wpis') {
    /* Trzy wpisy, środkowy się wywraca. Sedno: dwa pozostałe mają wyjść na
       stronę, a główny włącznik zostać włączony. */
    $a = zaloz(['tytul' => 'Pierwszy', 'rodzaj' => 'html', 'kod' => 'A']);
    /* Wpis liczy własne wejścia, ZANIM się wywróci. Bez tego licznika „drugi
       przebieg" nie miałby czego pokazać: wpis, który rzuca, i tak nic nie
       wypisuje, więc wyjście wyglądałoby identycznie niezależnie od tego, czy
       został pominięty, czy wykonany po raz drugi. */
    $b = zaloz(['tytul' => 'Wywrotka', 'rodzaj' => 'php',
                'kod'   => '$GLOBALS["wejsc"] = ($GLOBALS["wejsc"] ?? 0) + 1; throw new Error("bum");']);
    $c = zaloz(['tytul' => 'Trzeci',   'rodzaj' => 'html', 'kod' => 'C']);

    przebieg();
    $out['head'] = preg_replace('/\s+/', '', odpal_hak('wp_head'));
    $out['stan'] = stan_po_awarii([$a, $b, $c]);

    /* DRUGI PRZEBIEG. Wyłączenie ma znaczyć „już nie wchodzi", a nie tylko
       „ma metadaną". */
    przebieg();
    $out['head_drugi'] = preg_replace('/\s+/', '', odpal_hak('wp_head'));
    $out['wejsc']      = $GLOBALS['wejsc'] ?? 0;

} elseif ($scenariusz === 'awaria-advanced') {
    /* Tryb zaawansowany to osobne pole i osobna opcja — ma gasnąć sam,
       nie ciągnąc za sobą wpisów. */
    $a = zaloz(['tytul' => 'Pierwszy', 'rodzaj' => 'html', 'kod' => 'A']);
    $GLOBALS['options'][EVK_SNIPPETS_ADVANCED_ENABLED] = 1;
    $GLOBALS['options'][EVK_SNIPPETS_ADVANCED_CONTENT] = '<?php throw new Error("bum w advanced");';

    przebieg();
    $out['head'] = trim(odpal_hak('wp_head'));
    $out['stan'] = stan_po_awarii([$a]);

} elseif ($scenariusz === 'awaria-nieznana') {
    /* BŁĄD POZA WYKONANIEM SNIPPETU. Kod zarejestrowany przez snippet
       (hak, domknięcie) leci długo po tym, jak znacznik zgasł — nie wiadomo
       wtedy, czyj to kod. Wtedy i tylko wtedy gaśnie główny włącznik. */
    $a = zaloz(['tytul' => 'Pierwszy', 'rodzaj' => 'html', 'kod' => 'A']);
    przebieg();

    evk_snippet_obsluz_fatal([
        'type'    => E_ERROR,
        'message' => 'Call to undefined function nie_ma()',
        /* Ścieżkę bierzemy z kodu, a nie przepisujemy: PHP skleja `file` jako
           „plik-z-eval-em(linia) : eval()'d code" i to jest jedyna część, którą
           test ma prawo udawać. Że kształt się zgadza, sprawdza scenariusz
           „fatal-twardy" na PRAWDZIWYM błędzie. */
        'file'    => evk_snippet_plik_eval() . '(120) : eval()\'d code',
        'line'    => 4,
    ]);
    $out['stan'] = stan_po_awarii([$a]);

} elseif ($scenariusz === 'awaria-cudza') {
    /* KONTROLA NEGATYWNA. Cudza wtyczka też potrafi wykonywać kod przez
       `eval()`. Jej wywrotka nie ma prawa zgasić naszych snippetów — samo
       „eval()'d code" w ścieżce to za mało, musi tam stać NASZ plik. */
    $a = zaloz(['tytul' => 'Pierwszy', 'rodzaj' => 'html', 'kod' => 'A']);
    przebieg();

    evk_snippet_obsluz_fatal([
        'type'    => E_ERROR,
        'message' => 'Call to undefined function cudza()',
        'file'    => '/var/www/wp-content/plugins/obca-wtyczka/silnik.php(88) : eval()\'d code',
        'line'    => 12,
    ]);
    $out['stan'] = stan_po_awarii([$a]);

    /* Drugi przypadek: błąd w zwykłym pliku motywu, bez śladu po `eval()`. */
    evk_snippet_obsluz_fatal([
        'type'    => E_ERROR,
        'message' => 'Cannot redeclare motyw_funkcja()',
        'file'    => '/var/www/wp-content/themes/bricks/functions.php',
        'line'    => 30,
    ]);
    $out['stan_po_drugim'] = stan_po_awarii([$a]);

} elseif ($scenariusz === 'awaria-czyszczenie') {
    /* Ślad po wywrotce ma zniknąć, kiedy administrator naprawi wpis — i ma
       ZOSTAĆ, dopóki tego nie zrobi. */
    $b = zaloz(['tytul' => 'Wywrotka', 'rodzaj' => 'php', 'kod' => 'throw new Error("bum");']);
    przebieg();
    odpal_hak('wp_head');
    $out['po_awarii'] = stan_po_awarii([$b])['wpisy']['Wywrotka'];

    // Zapis wpisu = naprawa.
    evk_snippet_zapisz_wpis(['id' => $b, 'tytul' => 'Wywrotka', 'kod' => 'echo "juz dobrze";',
                             'rodzaj' => 'php', 'miejsce' => 'head', 'grupa' => '',
                             'wlaczony' => 1, 'kolejnosc' => 0]);
    $out['po_zapisie'] = stan_po_awarii([$b])['wpisy']['Wywrotka'];

} elseif ($scenariusz === 'fatal-twardy') {
    /* PRAWDZIWY BŁĄD NIEPRZECHWYTYWALNY, bez żadnej atrapy.
     *
     * Redeklaracji funkcji `try/catch` nie widzi: PHP nie rzuca wyjątku, tylko
     * kończy żądanie. Zostaje funkcja zamykająca — i właśnie ona jest tu badana.
     * Proces UMIERA z kodem 255; wynik wypisuje druga funkcja zamykająca,
     * zarejestrowana PO tej z silnika, więc leci po niej.
     */
    $a = zaloz(['tytul' => 'Pierwszy', 'rodzaj' => 'html', 'kod' => 'A']);
    $b = zaloz(['tytul' => 'Redeklaracja', 'rodzaj' => 'php',
                'kod'   => 'function evk_test_kolizja() { return 1; }']);

    przebieg();   // tu silnik rejestruje swoją funkcję zamykającą

    register_shutdown_function(function () use ($a, $b) {
        /* Bufor `odpal_hak()` został otwarty i nigdy się nie domknął — fatal
           przerwał go w środku. PHP wypycha go po funkcjach zamykających, więc
           bez tego wynik miałby przed sobą treść snippetu. */
        while (ob_get_level()) ob_end_clean();
        echo json_encode(['stan' => stan_po_awarii([$a, $b])],
                         JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    });

    // Nazwa zajęta ZANIM wykona się wpis — jego `function` będzie kolizją.
    function evk_test_kolizja() { return 0; }

    odpal_hak('wp_head');   // stąd się nie wraca
    echo json_encode(['blad' => 'fatal nie wystapil']);
    exit;
}

// =========================================================================
// HISTORIA ZMIAN
// =========================================================================

if ($scenariusz === 'roznica') {
    /* Poprawka JEDNEJ linii w środku długiej treści. Sedno: wynik ma
       pokazywać zmianę razem z otoczeniem, a nie stu linii bez zmian. */
    $dlugie_stare = [];
    for ($i = 1; $i <= 60; $i++) $dlugie_stare[] = "linia $i";
    $dlugie_nowe = $dlugie_stare;
    $dlugie_nowe[29] = 'linia 30 POPRAWIONA';

    $out['jedna_linia'] = evk_snippet_roznica(
        implode("\n", $dlugie_stare), implode("\n", $dlugie_nowe));
    $out['jedna_linia_html'] = evk_snippet_roznica_html($out['jedna_linia']);

    $out['dodane']   = evk_snippet_roznica("a\nb", "a\nNOWA\nb");
    $out['usuniete'] = evk_snippet_roznica("a\nSTARA\nb", "a\nb");
    $out['bez_zmian'] = evk_snippet_roznica("a\nb\nc", "a\nb\nc");
    $out['bez_zmian_html'] = evk_snippet_roznica_html($out['bez_zmian']);

    /* Treść przerastająca próg dokładnego porównania — ma wyjść blok
       wymieniony w całości, a nie zawieszony panel. */
    /* Co dziesiąta linia WSPÓLNA — i to jest tu sedno. Bez wspólnych linii
       obie drogi (blok i dokładne porównanie) dałyby ten sam wynik, więc
       sprawdzenie nie odróżniłoby jednej od drugiej. */
    $wielkie_a = []; $wielkie_b = [];
    for ($i = 1; $i <= EVK_SNIPPET_DIFF_LIMIT + 50; $i++) {
        $wielkie_a[] = "A $i";
        $wielkie_b[] = ($i % 10 === 0) ? "A $i" : "B $i";
    }
    $t0 = microtime(true);
    $wielka = evk_snippet_roznica(implode("\n", $wielkie_a), implode("\n", $wielkie_b));
    $out['wielka'] = [
        'ms'        => (int) round((microtime(true) - $t0) * 1000),
        'usuniete'  => count(array_filter($wielka, fn($w) => $w['typ'] === 'usuniete')),
        'dodane'    => count(array_filter($wielka, fn($w) => $w['typ'] === 'dodane')),
        'rownych'   => count(array_filter($wielka, fn($w) => $w['typ'] === 'rowny')),
    ];

    /* Przeplot: wspólne linie w środku mają zostać rozpoznane jako wspólne,
       a nie zamienione na „wszystko usunięte, wszystko dodane". */
    $out['przeplot'] = evk_snippet_roznica("a\nb\nc\nd", "a\nX\nc\nY");

    /* POPRAWKA JEDNEJ LINII W DŁUGIM PLIKU — i to jest przypadek, w którym
       odcięcie wspólnych końców rozstrzyga o WYNIKU, nie o czasie. Plik jest
       dłuższy niż próg dokładnego porównania; bez odcięcia cała treść poszłaby
       jako blok wymieniony w całości i podgląd pokazywałby osiemset zmienionych
       linii zamiast jednej. Zmierzone mutacją: bez tego przypadku zdjęcie
       odcięcia przechodziło na zielono. */
    $duzy_stary = [];
    for ($i = 1; $i <= 800; $i++) $duzy_stary[] = "linia $i";
    $duzy_nowy = $duzy_stary;
    $duzy_nowy[399] = 'linia 400 POPRAWIONA';

    $t1 = microtime(true);
    $duzy = evk_snippet_roznica(implode("\n", $duzy_stary), implode("\n", $duzy_nowy));
    $out['duzy_plik'] = [
        'ms'       => (int) round((microtime(true) - $t1) * 1000),
        'zmian'    => count(array_filter($duzy, fn($w) => $w['typ'] !== 'rowny')),
        'rownych'  => count(array_filter($duzy, fn($w) => $w['typ'] === 'rowny')),
        'tresci'   => array_values(array_map(fn($w) => $w['tekst'],
                          array_filter($duzy, fn($w) => $w['typ'] !== 'rowny'))),
    ];

} elseif ($scenariusz === 'wersje-lista') {
    $id = zaloz(['tytul' => 'Z historią', 'rodzaj' => 'php', 'kod' => 'echo 1;']);
    for ($i = 1; $i <= 25; $i++) {
        evk_test_rewizja($id, "wersja $i", sprintf('2026-08-%02d 10:00:00', $i));
    }

    $lista = evk_snippet_wersje($id);
    $out['ile']        = count($lista);
    $out['najnowsza']  = $lista[0]['data'];
    $out['najstarsza'] = $lista[count($lista) - 1]['data'];
    $out['w_bazie']    = count(wp_get_post_revisions($id));

    /* Historia CUDZEGO wpisu nie ma prawa wejść na tę listę. */
    $obcy = wp_insert_post(['post_title' => 'Strona', 'post_type' => 'page', 'post_status' => 'publish']);
    evk_test_rewizja($obcy, 'cudza treść', '2026-08-30 10:00:00');
    $out['po_dolozeniu_cudzej'] = count(evk_snippet_wersje($id));

} elseif ($scenariusz === 'wersje-czyszczenie') {
    $id = zaloz(['tytul' => 'Z historią', 'rodzaj' => 'php', 'kod' => 'echo 1;']);
    for ($i = 1; $i <= 25; $i++) {
        evk_test_rewizja($id, "wersja $i", sprintf('2026-08-%02d 10:00:00', $i));
    }

    $out['skasowane'] = evk_snippet_wyczysc_wersje($id, 10);
    $zostalo = wp_get_post_revisions($id);
    $out['zostalo']   = count($zostalo);
    /* ZOSTAJĄ NAJNOWSZE, nie pierwsze z brzegu — przy kasowaniu po złej
       stronie listy liczba wyszłaby ta sama, a historia zniknęłaby ta
       potrzebna. */
    $tresci = array_map(fn($r) => $r->post_content, array_values($zostalo));
    $out['najnowsza_zostala'] = in_array('wersja 25', $tresci, true);
    $out['najstarsza_znikla'] = !in_array('wersja 1', $tresci, true);

    // Powtórzone czyszczenie nie ma już czego skasować.
    $out['drugi_raz'] = evk_snippet_wyczysc_wersje($id, 10);

    // Zero znaczy zero, a liczba ujemna też — nie „skasuj od końca".
    $out['ujemne'] = evk_snippet_wyczysc_wersje($id, -5);
    $out['po_ujemnym'] = count(wp_get_post_revisions($id));

} elseif ($scenariusz === 'wersje-ajax') {
    $GLOBALS['is_admin'] = true;
    $id  = zaloz(['tytul' => 'Z historią', 'rodzaj' => 'php', 'kod' => "a\nb\nc"]);
    $rew = evk_test_rewizja($id, "a\nSTARE\nc", '2026-08-01 10:00:00');

    /** Woła prawdziwy uchwyt AJAX i oddaje jego odpowiedź. */
    $pytaj = function (int $rewizja) {
        $_POST = ['revision_id' => (string) $rewizja, 'nonce' => 'x'];
        try {
            foreach ($GLOBALS['hooks']['wp_ajax_evk_get_snippet_revision'] ?? [] as $cb) $cb();
        } catch (EVK_Test_Json $e) {
            return $e->payload;
        }
        return ['success' => null];
    };

    $out['wersja'] = $pytaj($rew);

    /* KONTROLA NEGATYWNA. Identyfikator przychodzi z żądania, więc uchwyt musi
       sprawdzić TYP rodzica — inaczej oddawałby treść dowolnej rewizji
       w witrynie. */
    $obcy     = wp_insert_post(['post_title' => 'Strona', 'post_type' => 'page', 'post_status' => 'publish']);
    $obca_rew = evk_test_rewizja($obcy, 'sekret z cudzej strony', '2026-08-02 10:00:00');
    $out['cudza'] = $pytaj($obca_rew);
    $out['nieistniejaca'] = $pytaj(999999);

} elseif ($scenariusz === 'wersje-formularz') {
    /* Czyszczenie idzie tą samą bramą co reszta akcji listy. */
    $GLOBALS['is_admin'] = true;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_GET = ['page' => 'evoke-one', 'tab' => 'narzedzia'];

    $id = zaloz(['tytul' => 'Z historią', 'rodzaj' => 'php', 'kod' => 'echo 1;']);
    for ($i = 1; $i <= 15; $i++) {
        evk_test_rewizja($id, "wersja $i", sprintf('2026-08-%02d 10:00:00', $i));
    }

    $wyslij = function (array $post): string {
        $_POST = $post;
        try {
            foreach ($GLOBALS['hooks']['admin_init'] ?? [] as $cb) $cb();
        } catch (EVK_Redirect $e) {
            return (string) $e->url;
        }
        return '';
    };

    $out['adres']   = $wyslij(['evk_snippets_nonce_field' => 'x',
                               'evk_wyczysc_wersje' => (string) $id, 'evk_zostaw_wersji' => '5']);
    $out['zostalo'] = count(wp_get_post_revisions($id));

    // Bez nonce'a brama musi milczeć.
    $przed = $out['zostalo'];
    $wyslij(['evk_wyczysc_wersje' => (string) $id, 'evk_zostaw_wersji' => '1']);
    $out['bez_nonce_bez_zmian'] = count(wp_get_post_revisions($id)) === $przed;

    /* Obcy wpis: identyfikator z formularza nie ma prawa skasować historii
       strony ani wpisu bloga. */
    $obcy = wp_insert_post(['post_title' => 'Strona', 'post_type' => 'page', 'post_status' => 'publish']);
    for ($i = 1; $i <= 4; $i++) evk_test_rewizja($obcy, "obca $i", sprintf('2026-07-%02d 10:00:00', $i));
    $wyslij(['evk_snippets_nonce_field' => 'x',
             'evk_wyczysc_wersje' => (string) $obcy, 'evk_zostaw_wersji' => '0']);
    $out['obca_historia_zyje'] = count(wp_get_post_revisions($obcy));
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
