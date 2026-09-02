<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — snippety jako WPISY.
 *
 * Do 1.139.0 snippety były czterema stałymi oknami (`evk_snippets_defs()`):
 * jedno na `wp_head`, jedno na stopkę, jedno na panel i jedno „jak
 * functions.php". Wszystko, co miało się wykonać w danym miejscu, siedziało
 * w jednym bloku. Stąd trzy bolączki, z których ostatnia jest najgorsza:
 *
 *  · nie dało się wyłączyć jednego kawałka bez komentowania kodu;
 *  · rewizje dotyczyły całego bloku, więc historia nie mówiła, co się zmieniło;
 *  · przy błędzie krytycznym silnik gasił WSZYSTKIE snippety, bo nie wiedział,
 *    który pękł (`evk_snippet_log_error(..., 'unknown', ...)`).
 *
 * Wpis niesie trzy rzeczy poza samym kodem:
 *
 *  · RODZAJ — co to jest i jak ma zostać podane stronie. To on zdejmuje
 *    z Was pisanie `<style>`, `<script>` i `<?php` (patrz `evk_snippet_opakuj()`).
 *  · MIEJSCE — kiedy się wykonuje, czyli hak WordPressa.
 *  · GRUPA — wolny tekst do Waszego porządku („SEO", „klient X"). Sortowanie
 *    i szukanie, nic więcej; system się nią nie kieruje.
 */

// =========================================================================
// RODZAJE — co wpisujesz i w co system to owija
// =========================================================================

/**
 * `opakowanie` mówi, co się dzieje z treścią przed podaniem jej stronie:
 *
 *  · `style`/`script` — treść trafia do znacznika i NIE JEST WYKONYWANA jako
 *    PHP. To nie jest wygoda kosmetyczna: arkusz stylów nie ma powodu
 *    przechodzić przez `eval()`, a każde miejsce, którego przez `eval()` nie
 *    przepuszczamy, to jedno miejsce mniej do popsucia.
 *  · `php` — czysty kod, bez otwierającego `<?php`. Wykonywany.
 *  · `surowo` — treść leci na wyjście bez zmian i bez wykonywania.
 *  · `szablon` — dotychczasowy tryb: HTML ze wstawkami `<?php … ?>`.
 *    Zostaje wyłącznie dlatego, że tak działały cztery stare okna i migracja
 *    musi zachować ich zachowanie co do znaku. Nowych wpisów tak nie zakładamy.
 */
function evk_snippet_rodzaje(): array {
    return [
        'php' => [
            'label'      => 'PHP',
            'opakowanie' => 'php',
            'opis'       => 'Sam kod PHP — bez <?php na początku. Wykonywany jak fragment functions.php.',
        ],
        'css' => [
            'label'      => 'CSS',
            'opakowanie' => 'style',
            'opis'       => 'Same reguły. System owija je w znacznik &lt;style&gt;.',
        ],
        'js' => [
            'label'      => 'JavaScript',
            'opakowanie' => 'script',
            'opis'       => 'Sam skrypt. System owija go w znacznik &lt;script&gt;.',
        ],
        'html' => [
            'label'      => 'HTML',
            'opakowanie' => 'surowo',
            'opis'       => 'Znacznik podawany bez zmian. Nic się nie wykonuje.',
        ],
        'szablon' => [
            'label'      => 'HTML + PHP (tryb dawny)',
            'opakowanie' => 'szablon',
            'opis'       => 'HTML ze wstawkami &lt;?php … ?&gt;. Tryb czterech okien sprzed 1.139.0.',
        ],
    ];
}

// =========================================================================
// MIEJSCA — kiedy wpis się wykonuje
// =========================================================================

function evk_snippet_miejsca(): array {
    return [
        'head' => [
            'label'    => 'Frontend — <head>',
            'hak'      => 'wp_head',
            'priorytet'=> 1,
            'admin'    => false,
        ],
        'footer' => [
            'label'    => 'Frontend — stopka',
            'hak'      => 'wp_footer',
            'priorytet'=> 9999,
            'admin'    => false,
        ],
        'admin_head' => [
            'label'    => 'Panel — <head>',
            'hak'      => 'admin_head',
            'priorytet'=> 1,
            'admin'    => true,
        ],
        'init' => [
            /* Bez haka: wykonuje się od razu przy starcie, tak jak
               `functions.php`. Tu mieszka wszystko, co musi zdążyć przed
               resztą WordPressa — rejestracje, filtry, akcje. */
            'label'    => 'Zawsze (jak functions.php)',
            'hak'      => '',
            'priorytet'=> 0,
            'admin'    => null,
        ],
    ];
}

// =========================================================================
// OPAKOWANIE — jedyne miejsce, które wie, co zrobić z treścią
// =========================================================================

/**
 * Zwraca to, co ma trafić na wyjście, albo `null`, gdy treść ma zostać
 * WYKONANA zamiast wypisana (wtedy robi to `evk_snippet_execute()`).
 *
 * Identyfikator w znaczniku bierze się z wpisu, żeby dało się dojść, skąd na
 * stronie wziął się dany styl — bez tego pierwszym pytaniem przy zgłoszeniu
 * „coś nadpisuje mi nagłówek" jest „ale co".
 */
function evk_snippet_opakuj(string $rodzaj, string $kod, int $id = 0): ?string {
    $rodzaje = evk_snippet_rodzaje();
    $opak    = $rodzaje[$rodzaj]['opakowanie'] ?? 'szablon';

    if ($opak === 'php' || $opak === 'szablon') return null;   // do wykonania

    if ($opak === 'surowo') return $kod;

    $znacznik = $opak === 'style' ? 'style' : 'script';
    $atrybut  = $id ? sprintf(' id="evk-snippet-%d"', $id) : '';

    /* Domknięcie znacznika w treści wyszłoby z bloku i stało się znacznikiem
       strony — ta sama klasa błędu, którą naprawialiśmy w 1.133.0 we własnym
       CSS-ie panelu. Rozbijamy je tak, żeby przeglądarka nie zobaczyła
       zamknięcia, a treść pozostała czytelna. */
    $kod = str_ireplace('</' . $znacznik, '<\/' . $znacznik, $kod);

    return sprintf("<%s%s>\n%s\n</%s>\n", $znacznik, $atrybut, $kod, $znacznik);
}

// =========================================================================
// ODCZYT WPISÓW
// =========================================================================

const EVK_SNIPPET_META_RODZAJ  = '_evk_snippet_rodzaj';
const EVK_SNIPPET_META_MIEJSCE = '_evk_snippet_miejsce';
const EVK_SNIPPET_META_GRUPA   = '_evk_snippet_grupa';
const EVK_SNIPPET_META_WLACZ   = '_evk_snippet_wlaczony';

/**
 * Wszystkie wpisy, w kolejności wykonania.
 *
 * `menu_order` niesie kolejność — WordPress ma to pole od zawsze i nie ma
 * powodu zakładać własnego. Przy równych numerach rozstrzyga identyfikator,
 * żeby kolejność była POWTARZALNA: bez tego dwa wpisy z zerem mogłyby wykonać
 * się raz tak, raz tak, zależnie od bazy.
 */
function evk_snippety_wszystkie(bool $tylko_wlaczone = false): array {
    $posty = get_posts([
        'post_type'        => 'evk_code_snippet',
        'post_status'      => 'private',
        'posts_per_page'   => -1,
        'orderby'          => ['menu_order' => 'ASC', 'ID' => 'ASC'],
        'suppress_filters' => true,
    ]);

    $out = [];
    foreach ($posty as $post) {
        $wlaczony = get_post_meta($post->ID, EVK_SNIPPET_META_WLACZ, true);
        /* Brak wartości = włączony. Wpisy z migracji nie mają tej metadanej,
           a cztery stare okna działały; wyłączenie ich przy aktualizacji byłoby
           cichą zmianą zachowania strony. */
        $wlaczony = ($wlaczony === '' || $wlaczony === null) ? 1 : (int) $wlaczony;
        if ($tylko_wlaczone && !$wlaczony) continue;

        $rodzaj  = (string) get_post_meta($post->ID, EVK_SNIPPET_META_RODZAJ, true);
        $miejsce = (string) get_post_meta($post->ID, EVK_SNIPPET_META_MIEJSCE, true);

        $out[] = [
            'id'       => (int) $post->ID,
            'tytul'    => (string) $post->post_title,
            'kod'      => (string) $post->post_content,
            'rodzaj'   => isset(evk_snippet_rodzaje()[$rodzaj]) ? $rodzaj : 'szablon',
            'miejsce'  => isset(evk_snippet_miejsca()[$miejsce]) ? $miejsce : 'head',
            'grupa'    => (string) get_post_meta($post->ID, EVK_SNIPPET_META_GRUPA, true),
            'wlaczony' => $wlaczony,
            'kolejnosc'=> (int) $post->menu_order,
            'slug'     => (string) $post->post_name,
        ];
    }
    return $out;
}

// =========================================================================
// ZAPIS
// =========================================================================

function evk_snippet_zapisz_wpis(array $dane): int {
    $id = (int) ($dane['id'] ?? 0);

    $post = [
        'post_title'   => (string) ($dane['tytul'] ?? 'Snippet'),
        'post_content' => (string) ($dane['kod'] ?? ''),
        'post_status'  => 'private',
        'post_type'    => 'evk_code_snippet',
        'menu_order'   => (int) ($dane['kolejnosc'] ?? 0),
    ];
    if ($id) {
        $post['ID'] = $id;
        wp_update_post($post);
    } else {
        $id = (int) wp_insert_post($post);
        if (!$id) return 0;
    }

    $rodzaj  = (string) ($dane['rodzaj'] ?? 'php');
    $miejsce = (string) ($dane['miejsce'] ?? 'head');

    update_post_meta($id, EVK_SNIPPET_META_RODZAJ,
        isset(evk_snippet_rodzaje()[$rodzaj]) ? $rodzaj : 'php');
    update_post_meta($id, EVK_SNIPPET_META_MIEJSCE,
        isset(evk_snippet_miejsca()[$miejsce]) ? $miejsce : 'head');
    update_post_meta($id, EVK_SNIPPET_META_GRUPA,
        sanitize_text_field((string) ($dane['grupa'] ?? '')));
    update_post_meta($id, EVK_SNIPPET_META_WLACZ, empty($dane['wlaczony']) ? 0 : 1);

    return $id;
}

// =========================================================================
// MIGRACJA — cztery stałe okna stają się wpisami
// =========================================================================

const EVK_SNIPPETY_MIGRACJA = 'evk_snippets_migracja_1139';
const EVK_SNIPPETY_KOPIA    = 'evk_snippets_kopia_przed_migracja';

/**
 * Przenosi cztery stare bloki na wpisy — RAZ, przy pierwszym starcie po
 * aktualizacji.
 *
 * Bloki są już wpisami tego samego typu (`evk_code_snippet` o stałych slugach),
 * więc migracja nie przenosi treści ani nie dotyka rewizji: dokłada im
 * wyłącznie metadane, których do tej pory nie miały. To jest cała sztuczka —
 * i dlatego historia zmian zostaje nietknięta.
 *
 * RODZAJ `szablon`, nie `php`: stare okna szły przez `eval('?>' . $kod)`, czyli
 * treść była HTML-em ze wstawkami `<?php`. Ustawienie im nowego, wygodnego
 * rodzaju zmieniłoby sposób wykonania i popsuło działające strony.
 *
 * Kopia treści do opcji przed zmianą — jedyny nieodwracalny krok w tej
 * przebudowie zasługuje na siatkę.
 */
function evk_snippety_migruj(): void {
    if (get_option(EVK_SNIPPETY_MIGRACJA)) return;

    $miejsca_starych = [
        'evk-snippet-frontend-head' => 'head',
        'evk-snippet-footer'        => 'footer',
        'evk-snippet-admin-head'    => 'admin_head',
        'evk-snippet-functions-php' => 'init',
    ];

    $kopia = [];
    foreach (evk_snippets_defs() as $klucz => $def) {
        $id = evk_snippet_get_id($def['slug']);
        if (!$id) continue;

        $post = get_post($id);
        if (!$post) continue;

        $kopia[$def['slug']] = $post->post_content;

        update_post_meta($id, EVK_SNIPPET_META_RODZAJ,  'szablon');
        update_post_meta($id, EVK_SNIPPET_META_MIEJSCE, $miejsca_starych[$def['slug']] ?? 'head');
        update_post_meta($id, EVK_SNIPPET_META_GRUPA,   'Przeniesione');
        /* Pusty blok wchodzi WYŁĄCZONY: cztery okna istniały zawsze, także
           puste, a lista czterech pustych wpisów „włączonych" to hałas. */
        update_post_meta($id, EVK_SNIPPET_META_WLACZ,   trim($post->post_content) === '' ? 0 : 1);

        if ($post->post_title === '' || $post->post_title === $def['slug']) {
            wp_update_post(['ID' => $id, 'post_title' => wp_specialchars_decode($def['title'])]);
        }
    }

    if ($kopia) update_option(EVK_SNIPPETY_KOPIA, $kopia, false);
    update_option(EVK_SNIPPETY_MIGRACJA, 1, false);
}
