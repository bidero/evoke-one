<?php
if (!defined('ABSPATH')) exit;


// =========================================================================
// CPT — rejestracja
// =========================================================================

add_action('init', function () {
    /* UPRAWNIENIA WŁASNE, NIE `'post'`.
     *
     * Treść tych wpisów jest WYKONYWANA przez `eval()`. Do 1.131.0 typ miał
     * `capability_type => 'post'`, więc prawo do jej edycji mapowało się na
     * `edit_others_posts` — czyli na Redaktora. Panel jest ukryty
     * (`show_ui => false`), a własne punkty AJAX pilnują `manage_options`, więc
     * znanej drogi nie było. Ale każda OGÓLNA droga edycji wpisów — WP-CLI,
     * importer, wtyczka do masowej edycji, cudzy endpoint rejestrowany
     * generycznie — pyta o uprawnienia typu wpisu, a nie nasze handlery,
     * i tamtędy Redaktor by wszedł.
     *
     * Wszystkie uprawnienia prowadzą do `manage_options`: kto może wykonać kod
     * przez snippety, ten i tak może wykonać go inaczej. */
    register_post_type('evk_code_snippet', [
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => false,
        'show_in_menu'       => false,
        'query_var'          => false,
        'rewrite'            => false,
        'capability_type'    => 'evk_code_snippet',
        'map_meta_cap'       => true,
        /* NIE WOLNO TU WPISAĆ `edit_post`, `read_post` ani `delete_post`.
         *
         * To są META-capy i WordPress traktuje je inaczej niż resztę. Przy
         * `map_meta_cap => true` `_post_type_meta_capabilities()`
         * (`wp-includes/post.php`) robi z nich WPIS GLOBALNY:
         *
         *     $post_type_meta_caps[ $custom ] = $core;
         *
         * czyli mapowanie `'edit_post' => 'manage_options'` rejestruje
         * `$post_type_meta_caps['manage_options'] = 'edit_post'`. Od tej chwili
         * `map_meta_cap()` przekierowuje KAŻDE sprawdzenie `manage_options`
         * w całej witrynie na `edit_post` — bez identyfikatora wpisu, więc
         * z wynikiem `do_not_allow`.
         *
         * Tak wyglądało 1.132.0 i 1.133.0: administrator tracił menu Ustawienia,
         * dostęp do buildera i do wszystkich wtyczek pytających o
         * `manage_options`. Naprawione w 1.133.1.
         *
         * Meta-capy wyprowadza WordPress sam z `capability_type`, a stąd trafiają
         * na primitywy niżej — ochrona jest ta sama, tylko bez zatruwania
         * globalnej tablicy. */
        'capabilities'       => [
            'edit_posts'             => 'manage_options',
            'edit_others_posts'      => 'manage_options',
            'edit_private_posts'     => 'manage_options',
            'edit_published_posts'   => 'manage_options',
            'delete_posts'           => 'manage_options',
            'delete_others_posts'    => 'manage_options',
            'delete_private_posts'   => 'manage_options',
            'delete_published_posts' => 'manage_options',
            'publish_posts'          => 'manage_options',
            'read_private_posts'     => 'manage_options',
            'create_posts'           => 'manage_options',
        ],
        'hierarchical'       => false,
        'supports'           => ['title', 'editor', 'revisions'],
        'has_archive'        => false,
        'show_in_rest'       => false,
    ]);
});

// =========================================================================
// HELPERS — zapis i odczyt snippetów
// =========================================================================

function evk_snippet_get(string $slug): string {
    // Cache per-request
    static $cache = [];
    if (isset($cache[$slug])) return $cache[$slug];

    $posts = get_posts([
        'post_type'        => 'evk_code_snippet',
        'name'             => $slug,
        'posts_per_page'   => 1,
        'post_status'      => 'private',
        'suppress_filters' => true,
    ]);
    $cache[$slug] = !empty($posts) ? $posts[0]->post_content : '';
    return $cache[$slug];
}

function evk_snippet_get_id(string $slug): int {
    $posts = get_posts([
        'post_type'        => 'evk_code_snippet',
        'name'             => $slug,
        'posts_per_page'   => 1,
        'post_status'      => 'private',
        'fields'           => 'ids',
        'suppress_filters' => true,
    ]);
    return !empty($posts) ? (int) $posts[0] : 0;
}

function evk_snippet_save(string $slug, string $title, string $content): void {
    $id = evk_snippet_get_id($slug);
    $data = [
        'post_title'   => $title,
        'post_content' => $content,
        'post_status'  => 'private',
        'post_type'    => 'evk_code_snippet',
        'post_name'    => $slug,
    ];
    if ($id) {
        $data['ID'] = $id;
        wp_update_post($data);
    } else {
        wp_insert_post($data);
    }
}

function evk_snippets_advanced_get(): string {
    global $wpdb;
    $val = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        EVK_SNIPPETS_ADVANCED_CONTENT
    ));
    return is_string($val) ? $val : '';
}

function evk_snippets_advanced_save(string $code): void {
    global $wpdb;
    $wpdb->replace(
        $wpdb->options,
        ['option_name' => EVK_SNIPPETS_ADVANCED_CONTENT, 'option_value' => $code, 'autoload' => 'no'],
        ['%s', '%s', '%s']
    );
}

// =========================================================================
// INICJALIZACJA WYKONYWANIA
// =========================================================================

/**
 * Wykonanie POJEDYNCZEGO wpisu — jedyna droga, którą treść trafia na stronę.
 *
 * Rodzaj rozstrzyga, czy treść jest WYKONYWANA, czy tylko OWIJANA i wypisana.
 * `evk_snippet_opakuj()` zwraca `null` dokładnie dla tych rodzajów, które mają
 * przejść przez `eval()`; wszystko inne — CSS, JavaScript, HTML — jest podawane
 * stronie bez wykonywania, więc nie ma jak wywalić PHP-a.
 */
function evk_snippet_wykonaj_wpis(array $wpis): string {
    if (trim($wpis['kod']) === '') return '';

    $gotowe = evk_snippet_opakuj($wpis['rodzaj'], $wpis['kod'], $wpis['id']);
    if ($gotowe !== null) return $gotowe;

    /* `php` to sam kod bez otwierającego znacznika — dopisujemy go, bo
       `evk_snippet_execute()` wykonuje treść w trybie szablonu (`?>` na
       początku). Dzięki temu obie drogi mają jedną obsługę błędów. */
    $kod = ($wpis['rodzaj'] === 'php') ? "<?php\n" . $wpis['kod'] : $wpis['kod'];

    return evk_snippet_execute(
        $kod,
        $wpis['slug'] !== '' ? $wpis['slug'] : ('wpis-' . $wpis['id']),
        evk_snippet_znacznik_wpisu($wpis)
    );
}

add_action('init', function () {
    if (defined('EVK_CODE_DISABLE') && EVK_CODE_DISABLE) return;

    /* Migracja PRZED sprawdzeniem włącznika: cztery stare okna mają dostać
       metadane niezależnie od tego, czy wykonywanie jest w tej chwili
       włączone — inaczej lista w panelu byłaby pusta u każdego, kto wyłączył
       snippety po fatalnym błędzie. */
    evk_snippety_migruj();

    if (!get_option(EVK_SNIPPETS_ENABLED_OPTION, 0)) return;

    $miejsca = evk_snippet_miejsca();
    $wpisy   = evk_snippety_wszystkie(true);

    // Advanced — osobna opcja sprzed podziału na wpisy, zostaje bez zmian.
    if (get_option(EVK_SNIPPETS_ADVANCED_ENABLED, 0)) {
        $adv = evk_snippets_advanced_get();
        if (!empty(trim($adv))) {
            evk_snippet_execute($adv, 'evk-snippet-advanced', evk_snippet_znacznik_advanced());
        }
    }

    foreach ($wpisy as $wpis) {
        $miejsce = $miejsca[$wpis['miejsce']] ?? $miejsca['head'];

        // Bez haka — wykonanie natychmiastowe, jak functions.php.
        if ($miejsce['hak'] === '') { evk_snippet_wykonaj_wpis($wpis); continue; }

        // Wpisy panelu nie mają czego szukać na froncie i odwrotnie.
        if ($miejsce['admin'] === true  && !is_admin()) continue;
        if ($miejsce['admin'] === false &&  is_admin()) continue;

        add_action($miejsce['hak'], function () use ($wpis) {
            echo evk_snippet_wykonaj_wpis($wpis);
        }, $miejsce['priorytet']);
    }
}, 10);

// =========================================================================
// FUNKCJA ZAMYKAJĄCA — łapie to, czego złapać się nie da
// =========================================================================

/**
 * Czy ten ślad prowadzi do NASZEGO `eval()`.
 *
 * PHP wpisuje w `file` ścieżkę pliku, w którym stoi `eval()`, i dokleja do niej
 * „ : eval()'d code":
 *
 *     /…/includes/snippets/validation.php(99) : eval()'d code
 *
 * Sprawdzamy OBIE części. Samo „eval()'d code" łapałoby też cudzą wtyczkę
 * wykonującą kod tą samą drogą — i gasiłoby wtedy nasze działające snippety za
 * czyjś błąd. Sama ścieżka bez dopisku łapałaby zwykły błąd w naszym pliku.
 */
function evk_snippet_slad_eval(string $tekst): bool {
    if ($tekst === '' || strpos($tekst, "eval()'d code") === false) return false;
    return strpos(wp_normalize_path($tekst), wp_normalize_path(evk_snippet_plik_eval())) !== false;
}

/**
 * Czy ten błąd krytyczny jest nasz.
 *
 * Trzy drogi, od najpewniejszej. Znacznik zapalony to dowód wprost: w chwili
 * błędu leciał nasz `eval()` i wiadomo nawet, czyj. Ślad w `file` zostaje
 * wtedy, gdy pękł kod zdefiniowany w snippecie, ale wywołany później — hak,
 * funkcja, domknięcie; znacznik jest już wtedy zgaszony. Ślad w `message` łapie
 * przypadek odwrotny: winowajcą jest cudzy plik, ale komunikat wskazuje na
 * nasze `eval()` („Cannot redeclare foo(), previously declared in … eval()'d
 * code") — nasz snippet zajął nazwę i to on ma wypaść.
 */
function evk_snippet_blad_nasz(array $error): bool {
    if (EVK_Snippet_Znacznik::biezacy() !== null) return true;
    if (evk_snippet_slad_eval((string) ($error['file'] ?? ''))) return true;
    if (evk_snippet_slad_eval((string) ($error['message'] ?? ''))) return true;

    // Wywrotka w kodzie samego modułu — gasimy z ostrożności, tak jak dotąd.
    $plik = wp_normalize_path((string) ($error['file'] ?? ''));
    return $plik !== '' && strpos($plik, wp_normalize_path(__DIR__)) === 0;
}

/**
 * Reakcja na błąd, którego `try/catch` nie widzi.
 *
 * Redeklaracja funkcji, brakujący `require`, przekroczony czas wykonania —
 * takiego błędu `evk_snippet_execute()` nie złapie, bo PHP nie rzuca tu
 * wyjątku, tylko kończy żądanie. Zostaje funkcja zamykająca: leci w TYM SAMYM
 * procesie, więc znacznik wciąż mówi, czyj kod pracował.
 *
 * Osobna, NAZWANA funkcja zamiast domknięcia w `register_shutdown_function` —
 * żeby dało się ją zawołać z testem, podając błąd wprost. Poza `error_get_last()`
 * jest tu wszystko, co decyduje.
 */
function evk_snippet_obsluz_fatal(?array $error): void {
    if (!$error) return;
    if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    if (!get_option(EVK_SNIPPETS_ENABLED_OPTION, 0)) return;
    if (!evk_snippet_blad_nasz($error)) return;

    $wynik = evk_snippet_odetnij('Fatal Error', (string) $error['message'], (int) $error['line']);

    /* Log dostaje nazwę wpisu, nie „unknown" — po to był cały znacznik. */
    evk_snippet_log_error('PHP Fatal Error', (string) $error['message'],
        $wynik['slug'], (int) $error['line']);
}

add_action('init', function () {
    register_shutdown_function(function () { evk_snippet_obsluz_fatal(error_get_last()); });
}, 1);

// =========================================================================
// POWIADOMIENIE — na każdym ekranie panelu
// =========================================================================

/**
 * Treść powiadomienia zależy od tego, CO wypadło.
 *
 * Zdanie „wykonywanie wyłączone" byłoby przy zakresie `wpis` nieprawdą: reszta
 * snippetów pracuje dalej i główny włącznik został włączony. Powiadomienie ma
 * nazwać jeden wpis i zaprowadzić prosto do niego — bez tego administrator
 * dostaje komunikat o błędzie i listę kilkunastu wpisów do przeszukania.
 */
function evk_snippet_tresc_powiadomienia(array $fatal): array {
    $zakres = $fatal['zakres'] ?? 'nieznany';
    $tytul  = trim((string) ($fatal['tytul'] ?? ''));

    if ($zakres === 'wpis' && !empty($fatal['id'])) {
        return [
            'naglowek' => sprintf('Evoke ONE: snippet „%s" wyłączył się po błędzie krytycznym.',
                                  $tytul !== '' ? $tytul : ('#' . (int) $fatal['id'])),
            'reszta'   => 'Pozostałe wpisy pracują dalej.',
            'url'      => evk_snippety_url(['evk_widok' => 'edytor', 'evk_wpis' => (int) $fatal['id']]),
            'przycisk' => 'Otwórz ten snippet',
        ];
    }

    if ($zakres === 'advanced') {
        return [
            'naglowek' => 'Evoke ONE: tryb zaawansowany snippetów wyłączył się po błędzie krytycznym.',
            'reszta'   => 'Zwykłe wpisy pracują dalej.',
            'url'      => evk_snippety_url(['evk_widok' => 'advanced']),
            'przycisk' => 'Otwórz tryb zaawansowany',
        ];
    }

    return [
        'naglowek' => 'Evoke ONE: wykonywanie snippetów wyłączone po błędzie krytycznym.',
        /* Mówimy WPROST, że sprawcy nie znamy — inaczej wygląda to na decyzję,
           a jest to ostatnia deska ratunku. */
        'reszta'   => 'Nie dało się wskazać wpisu: błąd wybuchł poza wykonaniem snippetu, '
                    . 'w kodzie, który snippet zarejestrował na później.',
        'url'      => evk_snippety_url(['evk_widok' => 'logi']),
        'przycisk' => 'Zobacz logi błędów',
    ];
}

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $fatal = get_transient(EVK_SNIPPETS_FATAL_TRANSIENT);
    if (!$fatal || !is_array($fatal)) return;

    $t = evk_snippet_tresc_powiadomienia($fatal);

    echo '<div class="notice notice-error evk-snippets-fatal-notice">';
    printf('<p><strong>%s</strong> %s</p>', esc_html($t['naglowek']), esc_html($t['reszta']));
    printf('<p class="evo-mono-xs">%s — linia %d</p>',
        esc_html((string) ($fatal['message'] ?? '')), (int) ($fatal['line'] ?? 0));
    printf('<p><a href="%s" class="button">%s</a> &nbsp;', esc_url($t['url']), esc_html($t['przycisk']));
    printf('<button type="button" class="button evk-dismiss-fatal-snippet" data-nonce="%s" data-url="%s">Odrzuć</button></p>',
        esc_attr(wp_create_nonce('evk_dismiss_fatal')),
        esc_attr(admin_url('admin-ajax.php')));
    echo '</div>';

    /* SKRYPT BEZ jQuery I BEZ ŻADNEJ ZMIENNEJ GLOBALNEJ.
     *
     * Do 1.149.1 stało tu `(function($){…})($j || jQuery)`. `$j` to konwencja
     * z cudzych motywów (`var $j = jQuery.noConflict()`) i we wtyczce nie było
     * jej nigdy — a goła, niezadeklarowana nazwa w JavaScripcie NIE ODDAJE
     * `undefined`, tylko RZUCA `ReferenceError`. Alternatywa po prawej nie
     * miała więc jak zadziałać: skrypt umierał przed wywołaniem funkcji,
     * obsługa kliknięcia nie wpinała się wcale i przycisk nie robił nic.
     * Zmierzone w przeglądarce: „$j is not defined", zero wysłanych żądań.
     *
     * Powiadomienie wypisuje się na KAŻDYM ekranie panelu, a `admin.js` jedzie
     * tylko na ekran Evoke ONE — więc skrypt musi zostać tutaj. Skoro tak,
     * niech nie zależy od niczego: ani od jQuery, ani od globalnego `ajaxurl`.
     * Adres idzie w atrybucie, obok nonce'a.
     */
    ?>
    <script>
    (function () {
        var b = document.querySelector('.evk-dismiss-fatal-snippet');
        if (!b) return;
        b.addEventListener('click', function () {
            var dane = new FormData();
            dane.append('action', 'evk_dismiss_snippet_fatal');
            dane.append('nonce', b.dataset.nonce);
            b.disabled = true;
            fetch(b.dataset.url, { method: 'POST', body: dane, credentials: 'same-origin' })
                .then(function (o) { return o.json(); })
                .then(function (odp) {
                    /* Znikamy TYLKO po potwierdzeniu z serwera. Schowanie
                       powiadomienia przy odmowie (przeterminowany nonce)
                       wyglądałoby na załatwione, a wróciłoby przy następnym
                       przeładowaniu — i wtedy nie wiadomo, co jest zepsute. */
                    if (!odp || !odp.success) { b.disabled = false; return; }
                    var n = document.querySelector('.evk-snippets-fatal-notice');
                    if (n) n.remove();
                })
                .catch(function () { b.disabled = false; });
        });
    })();
    </script>
    <?php
});

add_action('wp_ajax_evk_dismiss_snippet_fatal', function () {
    check_ajax_referer('evk_dismiss_fatal', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    delete_transient(EVK_SNIPPETS_FATAL_TRANSIENT);
    wp_send_json_success();
});
