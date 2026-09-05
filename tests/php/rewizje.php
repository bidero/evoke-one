<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Rewizje — przegląd i sprzątanie, NA PRAWDZIWEJ BAZIE.
 *
 * Ten moduł to prawie same zapytania SQL: złączenia, grupowanie, `HAVING`,
 * podzapytanie. Atrapa `$wpdb` udająca ich wynik sprawdzałaby atrapę —
 * a najdroższa pomyłka siedzi tu właśnie w zapytaniu (skasować za dużo).
 * Dlatego harness zakłada bazę SQLite z tabelą o kształcie `wp_posts`
 * i puszcza przez nią TEN SAM łańcuch SQL, który moduł wysyła na produkcji.
 *
 * SQLite, a nie MySQL, bo MySQL-a w środowisku testowym nie ma. Cena jest
 * jedna i jest znana: dwie funkcje MySQL-a nie istnieją w SQLite. Zamiast
 * tłumaczyć zapytania w harnessie — co sprawdzałoby tłumaczenie, nie moduł —
 * zapytania w module są napisane przenośnie (`CASE` zamiast `GREATEST`,
 * odcięcie w PHP zamiast `LIMIT … OFFSET`).
 *
 *   php tests/php/rewizje.php <scenariusz>
 */

require __DIR__ . '/_wp-stubs.php';

function size_format($b, $d = 0) { return round($b / 1024, $d) . ' KB'; }
function number_format_i18n($n, $d = 0) { return number_format($n, $d, ',', ' '); }
function get_post_type_object($typ) {
    $nazwy = ['post' => 'Wpisy', 'page' => 'Strony', 'bricks_template' => 'Szablony Bricks'];
    if (!isset($nazwy[$typ])) return null;
    return (object) ['labels' => (object) ['name' => $nazwy[$typ]]];
}

// =========================================================================
// $wpdb NA SQLITE
// =========================================================================

class EVK_Test_Wpdb_Sqlite {
    public $posts = 'wp_posts';
    public $pdo;
    /** Wysłane zapytania — do sprawdzeń, że coś w ogóle poszło do bazy. */
    public $zapytania = [];

    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec(
            'CREATE TABLE wp_posts (
                ID INTEGER PRIMARY KEY AUTOINCREMENT,
                post_author INTEGER DEFAULT 1,
                post_date TEXT DEFAULT "",
                post_content TEXT DEFAULT "",
                post_title TEXT DEFAULT "",
                post_excerpt TEXT DEFAULT "",
                post_status TEXT DEFAULT "publish",
                post_name TEXT DEFAULT "",
                post_parent INTEGER DEFAULT 0,
                post_type TEXT DEFAULT "post"
            )'
        );
    }

    /**
     * Odtworzenie `$wpdb->prepare()` — te same `%d` i `%s`, to samo cytowanie.
     *
     * WordPress przyjmuje argumenty i jako listę, i jako jedną tablicę; moduł
     * korzysta z obu form, więc atrapa musi znać obie.
     */
    public function prepare($zapytanie, ...$args) {
        if (count($args) === 1 && is_array($args[0])) $args = $args[0];
        $i = 0;
        return preg_replace_callback('/%[ds]/', function ($m) use (&$i, $args) {
            $v = $args[$i++] ?? '';
            return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v);
        }, $zapytanie);
    }

    public function get_results($sql, $tryb = null) {
        $this->zapytania[] = $sql;
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function get_col($sql) {
        $this->zapytania[] = $sql;
        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    }

    public function get_var($sql) {
        $this->zapytania[] = $sql;
        $w = $this->pdo->query($sql)->fetch(PDO::FETCH_NUM);
        return $w === false ? null : $w[0];
    }
}

$GLOBALS['wpdb'] = new EVK_Test_Wpdb_Sqlite();

/* Kasowanie idzie przez `wp_delete_post_revision()` — na produkcji sprząta też
   metadane i pamięci podręczne, tutaj wystarczy skasowanie wiersza. Atrapa
   PILNUJE TYPU: gdyby moduł podał identyfikator zwykłego wpisu, ma nie zniknąć
   ani jeden wiersz, który nie jest rewizją. */
function wp_delete_post_revision($id) {
    global $wpdb;
    $st = $wpdb->pdo->prepare('SELECT post_type FROM wp_posts WHERE ID = ?');
    $st->execute([(int) $id]);
    if ($st->fetchColumn() !== 'revision') { $GLOBALS['skasowano_nie_rewizje'][] = (int) $id; return false; }
    $wpdb->pdo->prepare('DELETE FROM wp_posts WHERE ID = ?')->execute([(int) $id]);
    return true;
}
$GLOBALS['skasowano_nie_rewizje'] = [];

require_once EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';
require_once EVK_TEST_ROOT . '/includes/tools/rewizje.php';

// =========================================================================
// ZASIEW
// =========================================================================

/** Wpis rodzic. Zwraca identyfikator. */
function wpis(string $typ, string $tytul = 'Wpis'): int {
    global $wpdb;
    $wpdb->pdo->prepare(
        'INSERT INTO wp_posts (post_type, post_title, post_status) VALUES (?, ?, "publish")'
    )->execute([$typ, $tytul]);
    return (int) $wpdb->pdo->lastInsertId();
}

/** `$ile` rewizji przy rodzicu, od najstarszej do najnowszej. */
function rewizje(int $rodzic, int $ile, string $prefiks = 'tresc'): array {
    global $wpdb;
    $ids = [];
    for ($i = 1; $i <= $ile; $i++) {
        $wpdb->pdo->prepare(
            'INSERT INTO wp_posts (post_type, post_parent, post_date, post_content, post_status)
             VALUES ("revision", ?, ?, ?, "inherit")'
        )->execute([$rodzic, sprintf('2026-08-%02d 10:00:00', $i), "$prefiks $i"]);
        $ids[] = (int) $wpdb->pdo->lastInsertId();
    }
    return $ids;
}

function ile_rewizji(int $rodzic = 0): int {
    global $wpdb;
    $sql = 'SELECT COUNT(*) FROM wp_posts WHERE post_type = "revision"'
         . ($rodzic ? ' AND post_parent = ' . $rodzic : '');
    return (int) $wpdb->pdo->query($sql)->fetchColumn();
}

function tresci(int $rodzic): array {
    global $wpdb;
    $st = $wpdb->pdo->prepare(
        'SELECT post_content FROM wp_posts WHERE post_type = "revision" AND post_parent = ?
          ORDER BY post_date ASC');
    $st->execute([$rodzic]);
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/* Atrapy formularza ustawień — potrzebne tylko scenariuszowi rysującemu ekran. */
function settings_fields($grupa) { echo '<input type="hidden" name="option_page" value="' . $grupa . '">'; }
function submit_button($tekst = '', $typ = 'primary', $nazwa = 'submit', $wrap = true) {
    echo '<button type="submit" class="button button-' . $typ . '" name="' . $nazwa . '">' . $tekst . '</button>';
}
function wp_create_nonce($akcja = -1) { return 'testnonce'; }

$scenariusz = $argv[1] ?? '';
$out = [];

if ($scenariusz === 'ekran') {
    /* EKRAN Z PRAWDZIWYCH DANYCH: znacznik powstaje z tych samych zapytań,
       które sprawdzają scenariusze wyżej — nie z tablicy wpisanej w harness. */
    $w1 = wpis('post'); rewizje($w1, 12);
    $w2 = wpis('post'); rewizje($w2, 3);
    $s1 = wpis('page'); rewizje($s1, 25);
    rewizje(999999, 4);

    ob_start();
    require EVK_TEST_ROOT . '/includes/admin/tools-rewizje.php';
    echo ob_get_clean();
    exit;
}

if ($scenariusz === 'ekran-pusty') {
    // Baza bez ani jednej rewizji — ekran ma to powiedzieć, a nie rysować pustą tabelę.
    ob_start();
    require EVK_TEST_ROOT . '/includes/admin/tools-rewizje.php';
    echo ob_get_clean();
    exit;
}

if ($scenariusz === 'przeglad') {
    $w1 = wpis('post');   rewizje($w1, 12);
    $w2 = wpis('post');   rewizje($w2, 3);
    $s1 = wpis('page');   rewizje($s1, 5);
    /* Sierota: rewizja wskazująca na wpis, którego nie ma. Zdarza się po
       kasowaniu wprost w bazie i po nieudanych migracjach. */
    rewizje(999999, 4);

    $out['przeglad'] = evk_rewizje_przeglad();
    $out['razem']    = ile_rewizji();

} elseif ($scenariusz === 'podsumowanie') {
    $w1 = wpis('post'); rewizje($w1, 12);
    $w2 = wpis('post'); rewizje($w2, 3);
    $s1 = wpis('page'); rewizje($s1, 25);
    rewizje(999999, 4);

    // Zostaw 10: z wpisów nadmiar to 2 (12−10) + 0 (3 < 10), ze strony 15.
    $out['zostaw10'] = evk_rewizje_do_skasowania(['post', 'page'], 10);
    $out['zostaw0']  = evk_rewizje_do_skasowania(['post', 'page'], 0);
    $out['same_strony'] = evk_rewizje_do_skasowania(['page'], 10);
    /* Sieroty idą w całości NIEZALEŻNIE od „zostaw" — rodzica już nie ma,
       więc nie ma czego zostawiać. */
    $out['sieroty']  = evk_rewizje_do_skasowania([''], 10);
    // Typ, którego w bazie nie ma, nie ma prawa nic zmienić.
    $out['nieznany'] = evk_rewizje_do_skasowania(['produkt', 'shop_order'], 10);
    $out['pusty']    = evk_rewizje_do_skasowania([], 10);

} elseif ($scenariusz === 'kasowanie') {
    $w1 = wpis('post'); rewizje($w1, 12, 'wpis');
    $s1 = wpis('page'); rewizje($s1, 25, 'strona');

    $out['przed'] = ['wpis' => ile_rewizji($w1), 'strona' => ile_rewizji($s1)];
    $out['skasowane'] = evk_rewizje_kasuj(['post', 'page'], 10, 1000);
    $out['po']    = ['wpis' => ile_rewizji($w1), 'strona' => ile_rewizji($s1)];

    /* ZOSTAJĄ NAJNOWSZE. Przy kasowaniu z drugiej strony listy liczby wyżej
       byłyby identyczne, a zniknęłaby historia, po którą się tu przychodzi. */
    $out['zostaly_wpis']   = tresci($w1);
    $out['zostaly_strona'] = tresci($s1);

    // Powtórzone kasowanie nie ma już czego zabrać.
    $out['drugi_raz'] = evk_rewizje_kasuj(['post', 'page'], 10, 1000);

} elseif ($scenariusz === 'kasowanie-wybor') {
    $w1 = wpis('post'); rewizje($w1, 12);
    $s1 = wpis('page'); rewizje($s1, 12);
    rewizje(999999, 4);

    // Zaznaczone tylko wpisy — strona i sieroty mają zostać nietknięte.
    $out['skasowane'] = evk_rewizje_kasuj(['post'], 5, 1000);
    $out['wpis']      = ile_rewizji($w1);
    $out['strona']    = ile_rewizji($s1);
    $out['sieroty']   = ile_rewizji(999999);

    // Teraz same sieroty: wszystkie, mimo „zostaw 5".
    $out['skasowane_sieroty'] = evk_rewizje_kasuj([''], 5, 1000);
    $out['sieroty_po']        = ile_rewizji(999999);
    $out['strona_po']         = ile_rewizji($s1);

    /* Zwykłe wpisy nie mają prawa zniknąć — kasujemy rewizje, nie treść. */
    $out['wpisy_zyja'] = (int) $GLOBALS['wpdb']->pdo
        ->query('SELECT COUNT(*) FROM wp_posts WHERE post_type IN ("post","page")')->fetchColumn();
    $out['proby_na_nierewizjach'] = $GLOBALS['skasowano_nie_rewizje'];

} elseif ($scenariusz === 'partie') {
    /* Kasowanie partiami: trzy wpisy po dwadzieścia rewizji, partia po 7.
       Sprawdzane jest to, że kolejne przebiegi POSUWAJĄ SIĘ NAPRZÓD i że
       kończą się dokładnie na tym, co miało zniknąć. */
    $ids = [];
    for ($i = 0; $i < 3; $i++) { $ids[] = wpis('post'); rewizje($ids[$i], 20); }

    $out['do_skasowania'] = evk_rewizje_do_skasowania(['post'], 10)['razem'];

    $przebiegi = [];
    $razem = 0;
    for ($i = 0; $i < 20; $i++) {
        $n = evk_rewizje_kasuj(['post'], 10, 7);
        $przebiegi[] = $n;
        $razem += $n;
        if ($n === 0) break;
    }
    $out['przebiegi'] = $przebiegi;
    $out['razem']     = $razem;
    $out['zostalo']   = ile_rewizji();
    $out['po_wpisie'] = array_map('ile_rewizji', $ids);

} elseif ($scenariusz === 'limit') {
    /* Filtr `wp_revisions_to_keep`: domyślnie nie rusza niczego, po włączeniu
       oddaje własną liczbę. */
    $filtr = $GLOBALS['hooks']['wp_revisions_to_keep'][0] ?? null;
    $out['filtr_wpiety'] = $filtr !== null;

    $out['domyslnie'] = $filtr ? $filtr(-1, (object) ['post_type' => 'post']) : null;

    $GLOBALS['options'][EVK_REWIZJE_OPCJA] = ['limit_on' => 1, 'limit' => 7];
    $out['po_wlaczeniu'] = $filtr ? $filtr(-1, (object) ['post_type' => 'post']) : null;

    $GLOBALS['options'][EVK_REWIZJE_OPCJA] = ['limit_on' => 0, 'limit' => 7];
    $out['po_wylaczeniu'] = $filtr ? $filtr(-1, (object) ['post_type' => 'post']) : null;

    // Sanityzacja: liczba ujemna znaczy dla WordPressa „bez ograniczeń".
    $out['sanit_ujemna'] = evk_rewizje_sanitize(['limit_on' => 1, 'limit' => '-5']);
    $out['sanit_tekst']  = evk_rewizje_sanitize(['limit_on' => 1, 'limit' => 'dużo']);
    $out['sanit_zero']   = evk_rewizje_sanitize(['limit_on' => 1, 'limit' => '0']);
    // Kontrola dodatnia: zwykła liczba ma przejść bez zmiany.
    $out['sanit_zwykla'] = evk_rewizje_sanitize(['limit_on' => 1, 'limit' => '7']);

} elseif ($scenariusz === 'ajax') {
    $w1 = wpis('post'); rewizje($w1, 12);
    $s1 = wpis('page'); rewizje($s1, 12);

    $pytaj = function (string $akcja, array $post) {
        $_POST = $post;
        try {
            foreach ($GLOBALS['hooks']['wp_ajax_' . $akcja] ?? [] as $cb) $cb();
        } catch (EVK_Test_Json $e) {
            return $e->payload;
        }
        return ['success' => null];
    };

    $out['podsumowanie'] = $pytaj('evk_rewizje_podsumowanie',
        ['nonce' => 'x', 'zostaw' => '10', 'typy' => ['post']]);

    /* KONTROLA NEGATYWNA: typ spoza bazy nie ma prawa nic zmienić, a brak
       uprawnienia ma zatrzymać żądanie przed dotknięciem bazy. */
    $out['nieznany_typ'] = $pytaj('evk_rewizje_podsumowanie',
        ['nonce' => 'x', 'zostaw' => '10', 'typy' => ['produkt']]);

    $out['kasowanie'] = $pytaj('evk_rewizje_kasuj',
        ['nonce' => 'x', 'zostaw' => '10', 'typy' => ['post']]);
    $out['po_kasowaniu'] = ['wpis' => ile_rewizji($w1), 'strona' => ile_rewizji($s1)];

    /* `typy[]` przychodzi z żądania, więc może być tablicą tablic. Bez odsiania
       nieskalarnych PHP rzuca ostrzeżeniem „Array to string conversion" —
       a ostrzeżenie w odpowiedzi AJAX psuje JSON i uchwyt przestaje działać. */
    $GLOBALS['ostrzezenia'] = [];
    set_error_handler(function ($no, $str) { $GLOBALS['ostrzezenia'][] = $str; return true; });
    $out['zagniezdzone'] = $pytaj('evk_rewizje_podsumowanie',
        ['nonce' => 'x', 'zostaw' => '10', 'typy' => [['post'], 'page']]);
    restore_error_handler();
    $out['ostrzezenia'] = $GLOBALS['ostrzezenia'];

    $GLOBALS['caps'] = ['manage_options' => false];
    $out['bez_uprawnien'] = $pytaj('evk_rewizje_kasuj',
        ['nonce' => 'x', 'zostaw' => '0', 'typy' => ['post', 'page']]);
    $GLOBALS['caps'] = null;
    $out['po_odmowie'] = ['wpis' => ile_rewizji($w1), 'strona' => ile_rewizji($s1)];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
