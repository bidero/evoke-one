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

    return evk_snippet_execute($kod, $wpis['slug'] !== '' ? $wpis['slug'] : ('wpis-' . $wpis['id']));
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
        if (!empty(trim($adv))) evk_snippet_execute($adv, 'evk-snippet-advanced');
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
// SHUTDOWN HANDLER — przechwytuje fatalne błędy
// =========================================================================

add_action('init', function () {
    register_shutdown_function(function () {
        $error = error_get_last();
        if (!$error) return;
        if (!in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
        if (!get_option(EVK_SNIPPETS_ENABLED_OPTION, 0)) return;

        $is_snippet = strpos($error['message'], "eval()'d code") !== false
            || strpos($error['message'], 'evk_snippet') !== false
            || strpos(wp_normalize_path($error['file']), wp_normalize_path(__FILE__)) !== false;

        if (!$is_snippet) return;

        evk_snippet_log_error('PHP Fatal Error', $error['message'], 'unknown', $error['line']);
        update_option(EVK_SNIPPETS_ENABLED_OPTION, 0);
        set_transient(EVK_SNIPPETS_FATAL_TRANSIENT, [
            'message' => $error['message'],
            'slug'    => 'fatal',
            'line'    => $error['line'],
            'type'    => 'Fatal Error',
        ], DAY_IN_SECONDS);
    });
}, 1);

// =========================================================================
// ADMIN NOTICE — fatal error
// =========================================================================

add_action('admin_notices', function () {
    if (!current_user_can('manage_options')) return;
    $fatal = get_transient(EVK_SNIPPETS_FATAL_TRANSIENT);
    if (!$fatal || !is_array($fatal)) return;
    $url = admin_url('options-general.php?page=evoke-one&tab=narzedzia&sub=snippets&evk_stab=logs');
    echo '<div class="notice notice-error evk-snippets-fatal-notice">';
    echo '<p><strong>Evoke One Snippety: wykonywanie wyłączone z powodu błędu krytycznego.</strong></p>';
    printf('<p>%s — linia %d</p>', esc_html($fatal['message']), (int)$fatal['line']);
    printf('<p><a href="%s" class="button">Zobacz logi błędów</a> &nbsp;', esc_url($url));
    echo '<button type="button" class="button evk-dismiss-fatal-snippet" data-nonce="' . esc_attr(wp_create_nonce('evk_dismiss_fatal')) . '">Odrzuć</button></p>';
    echo '</div>';
    echo '<script>(function($){$(".evk-dismiss-fatal-snippet").on("click",function(){$.post(ajaxurl,{action:"evk_dismiss_snippet_fatal",nonce:$(this).data("nonce")},function(){$(".evk-snippets-fatal-notice").remove();});});})($j||jQuery);</script>';
});

add_action('wp_ajax_evk_dismiss_snippet_fatal', function () {
    check_ajax_referer('evk_dismiss_fatal', 'nonce');
    if (!current_user_can('manage_options')) wp_die();
    delete_transient(EVK_SNIPPETS_FATAL_TRANSIENT);
    wp_send_json_success();
});

// =========================================================================
// AJAX — pobierz treść rewizji do podglądu
// =========================================================================

add_action('wp_ajax_evk_get_snippet_revision', function () {
    check_ajax_referer('evk_snippets_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error([], 403);
    $rev_id = absint($_POST['revision_id'] ?? 0);
    if (!$rev_id) wp_send_json_error('Brak ID rewizji.');
    $rev = wp_get_post_revision($rev_id);
    if (!$rev) wp_send_json_error('Rewizja nie istnieje.');
    if (!current_user_can('edit_post', $rev->post_parent)) wp_send_json_error('Brak uprawnień.', 403);
    wp_send_json_success(['content' => $rev->post_content]);
});
