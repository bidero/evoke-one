<?php
if (!defined('ABSPATH')) exit;


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
// ENQUEUE — CodeMirror tylko na stronie snippetów
// =========================================================================

add_action('admin_enqueue_scripts', function () {
    $page = $_GET['page'] ?? '';
    $tab  = $_GET['tab']  ?? '';
    /* Domyślna wartość TA SAMA co w `tab-narzedzia.php:7`: wejście z paska
       bocznego nie niesie `sub`, a mimo to pokazuje snippety. Bez tego edytor
       dostawał zwykłe pole tekstowe zamiast kolorowania składni. */
    $sub  = $_GET['sub'] ?? 'snippets';
    if ($page !== 'evoke-one' || $tab !== 'narzedzia' || $sub !== 'snippets') return;

    $cm = wp_enqueue_code_editor(['type' => 'application/x-httpd-php']);
    if (!$cm) return;
    wp_enqueue_script('wp-theme-plugin-editor');
    wp_enqueue_style('wp-codemirror');

    /* Pola edytora: jedno w edytorze wpisu i jedno w trybie zaawansowanym.
       Do 1.139.0 była tu lista czterech identyfikatorów z `evk_snippets_defs()`
       — tamte okna już nie istnieją. */
    $selectors = '#evk-kod, #evk_advanced_code';

    wp_add_inline_script('wp-theme-plugin-editor', sprintf(
        'jQuery(function($){ var s=%s; $(%s).each(function(){ if(wp&&wp.codeEditor) wp.codeEditor.initialize(this,s); }); });',
        wp_json_encode($cm),
        wp_json_encode($selectors)
    ));

    /* Podgląd rewizji odchodzi razem z czterema oknami: rewizje są teraz
       osobne dla KAŻDEGO wpisu, więc wracają jako element edytora wpisu,
       a nie panel obok czterech pól. Do zrobienia w kolejnym wydaniu; do tego
       czasu historia zmian nie znika — trzyma ją WordPress przy wpisach. */
});

// =========================================================================
// POST HANDLER — musi działać w admin_init (przed jakimkolwiek outputem)
// =========================================================================

add_action('admin_init', function () {
    /* BRAMA NIE PATRZY NA `tab` ANI `sub` — I TO JEST ISTOTA POPRAWKI 1.139.1.
     *
     * Do 1.139.0 stały tu jeszcze dwa warunki: `$_GET['tab'] === 'narzedzia'`
     * i `$_GET['sub'] === 'snippets'`. Wyglądały niewinnie, a wyłączały cały
     * ekran, bo pasek boczny prowadzi do Narzędzi adresem BEZ `sub`
     * (`evoke_one_render_sidebar_link()` skleja tylko `tab`), a
     * `tab-narzedzia.php` domyśla sobie `$sub = 'snippets'` samodzielnie.
     * Snippety renderowały się więc pod `?page=evoke-one&tab=narzedzia`,
     * formularze wracały na ten sam adres — i brama je odrzucała. Strona
     * przeładowywała się bez zmian: włącznik, usuwanie, zapis i czyszczenie
     * logów nie robiły NIC, zależnie od tego, którędy się tu weszło.
     *
     * O dostępie decyduje nonce razem z `manage_options` niżej. Adres nie jest
     * zabezpieczeniem i nie ma czego pilnować; `page` zostaje tylko po to, żeby
     * handler nie budził się na cudzych ekranach.
     */
    if (
        !is_admin()
        || ($_GET['page'] ?? '') !== 'evoke-one'
        || $_SERVER['REQUEST_METHOD'] !== 'POST'
        || empty($_POST['evk_snippets_nonce_field'])
    ) return;

    if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
    if (!wp_verify_nonce($_POST['evk_snippets_nonce_field'], 'evk_snippets_save')) wp_die('Nieprawidłowy nonce.');

    // ── Logi ──────────────────────────────────────────────────────────────
    if (isset($_POST['evk_clear_logs'])) {
        update_option(EVK_SNIPPETS_LOG_OPTION, []);
        wp_safe_redirect(evk_snippety_url(['evk_widok' => 'logi', 'evk_zapisano' => 'logi']));
        exit;
    }

    // ── Włącznik pojedynczego wpisu ───────────────────────────────────────
    if (!empty($_POST['evk_przelacz_wpis'])) {
        $id = (int) $_POST['evk_przelacz_wpis'];
        if (get_post_type($id) === 'evk_code_snippet') {
            $teraz = get_post_meta($id, EVK_SNIPPET_META_WLACZ, true);
            $teraz = ($teraz === '' || $teraz === null) ? 1 : (int) $teraz;
            update_post_meta($id, EVK_SNIPPET_META_WLACZ, $teraz ? 0 : 1);

            /* WŁĄCZENIE Z POWROTEM zdejmuje ślad po wywrotce — to świadoma
               decyzja administratora, że wpis ma znów pracować. Wyłączenie go
               nie zdejmuje: powód, dla którego wpis zgasł, ma zostać widoczny. */
            if (!$teraz) delete_post_meta($id, EVK_SNIPPET_META_AWARIA);
        }
        wp_safe_redirect(evk_snippety_url(['evk_zapisano' => 'stan']));
        exit;
    }

    // ── Usunięcie wpisu ───────────────────────────────────────────────────
    if (!empty($_POST['evk_usun_wpis'])) {
        $id = (int) $_POST['evk_usun_wpis'];
        /* Sprawdzenie typu, a nie tylko liczby: identyfikator przychodzi
           z formularza, więc bez tego dałoby się z tego miejsca skasować
           dowolny wpis w witrynie. */
        if (get_post_type($id) === 'evk_code_snippet') wp_delete_post($id, true);
        wp_safe_redirect(evk_snippety_url(['evk_zapisano' => 'usuniety']));
        exit;
    }

    // ── Zapis wpisu ───────────────────────────────────────────────────────
    if (isset($_POST['evk_zapisz_wpis'])) {
        $id = (int) ($_POST['evk_wpis_id'] ?? 0);
        if ($id && get_post_type($id) !== 'evk_code_snippet') $id = 0;

        $id = evk_snippet_zapisz_wpis([
            'id'        => $id,
            'tytul'     => sanitize_text_field(wp_unslash($_POST['evk_tytul'] ?? '')),
            /* KOD BEZ SANITYZACJI I TAK MA BYĆ. Treść jest kodem — każde
               „czyszczenie" zmieniłoby to, co administrator napisał, i zepsuło
               działający snippet. Chroni to uprawnienie `manage_options`, a nie
               filtr: kto tu wchodzi, ten i tak może wykonać kod inaczej. */
            'kod'       => (string) wp_unslash($_POST['evk_kod'] ?? ''),
            'rodzaj'    => sanitize_key($_POST['evk_rodzaj'] ?? 'php'),
            'miejsce'   => sanitize_key($_POST['evk_miejsce'] ?? 'head'),
            'grupa'     => sanitize_text_field(wp_unslash($_POST['evk_grupa'] ?? '')),
            'wlaczony'  => !empty($_POST['evk_wlaczony']) ? 1 : 0,
            'kolejnosc' => (int) ($_POST['evk_kolejnosc'] ?? 0),
        ]);

        /* Zapis to moment, w którym administrator naprawia to, co pękło —
           więc powiadomienie o fatalu z poprzedniego przebiegu przestaje być
           aktualne. */
        delete_transient(EVK_SNIPPETS_FATAL_TRANSIENT);

        wp_safe_redirect(evk_snippety_url(['evk_widok' => 'edytor', 'evk_wpis' => $id, 'evk_zapisano' => 'wpis']));
        exit;
    }

    // ── Zaawansowane ──────────────────────────────────────────────────────
    if (isset($_POST['evk_zapisz_advanced'])) {
        evk_snippets_advanced_save((string) wp_unslash($_POST['evk_advanced_code'] ?? ''));
        wp_safe_redirect(evk_snippety_url(['evk_widok' => 'advanced', 'evk_zapisano' => 'wpis']));
        exit;
    }
});

// =========================================================================
// RENDER — zakładka snippetów (wywoływana z tab-narzedzia.php)
// =========================================================================
