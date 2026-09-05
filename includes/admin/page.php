<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Główna strona ustawień
 * Router/dispatcher — ładuje odpowiedni plik zakładki.
 */

// =========================================================================
// ENQUEUE
// =========================================================================

add_action('admin_enqueue_scripts', function (string $hook) {
    if ($hook !== 'settings_page_evoke-one') return;

    wp_enqueue_style('evoke-one-admin',
        EVOKE_ONE_URL . 'assets/admin/admin.css', [], EVOKE_ONE_VERSION);

    // SortableJS — wspólna biblioteka przeciągania całego panelu (Białe etykiety,
    // warstwy OG, biblioteka animacji). Musi stać PRZED admin.js i być jego
    // zależnością: WordPress drukuje skrypty w kolejności zgłoszeń, więc samo
    // enqueue niżej w tej funkcji zostawiłoby admin.js bez biblioteki w chwili
    // uruchomienia — dokładnie tak nie działało przeciąganie wierszy w 1.37.0.
    wp_enqueue_script('sortablejs',
        'https://cdn.jsdelivr.net/npm/sortablejs@1.15.7/Sortable.min.js',
        [], '1.15.7', true);

    wp_enqueue_script('evoke-one-admin',
        EVOKE_ONE_URL . 'assets/admin/admin.js',
        ['jquery', 'sortablejs'], EVOKE_ONE_VERSION, true);

    /* Rewizje — tylko na swoim ekranie. Skrypt kasuje wiersze w bazie, więc
       nie ma powodu, żeby wisiał na pozostałych trzydziestu ekranach panelu. */
    if (($_GET['tab'] ?? '') === 'narzedzia' && ($_GET['sub'] ?? '') === 'rewizje') {
        wp_enqueue_script('evk-rewizje',
            EVOKE_ONE_URL . 'assets/admin/rewizje.js', [], EVOKE_ONE_VERSION, true);
        wp_localize_script('evk-rewizje', 'evkRewizje', [
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);
    }

    // Sitemap
    wp_localize_script('evoke-one-admin', 'evoSitemapAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('tl_ajax_nonce'),
    ]);

    // IO
    wp_localize_script('evoke-one-admin', 'evoIoAjax', [
        'url'     => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('tl_ajax_nonce'),
        'modules' => evoke_one_get_io_modules(),
    ]);

    // Cursor
    $cursor_settings = EVK_Cursor::get_instance()->get_settings();
    wp_localize_script('evoke-one-admin', 'evoOneCursorData', [
        'rowStart' => count($cursor_settings['elements'] ?? []) + 100,
    ]);

    // Animator
    $anim_settings = EVK_Animator::get_instance()->get_settings();
    wp_localize_script('evoke-one-admin', 'evoOneAnimData', [
        'rowStart'   => count($anim_settings['animations'] ?? []) + 100,
        'url'        => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('evk_anim_reorder'),
        // Osobny nonce, bo osobna akcja: zapis całej biblioteki to znacznie
        // więcej niż przestawienie kolejności i nie ma powodu dzielić uprawnienia.
        'saveNonce'  => wp_create_nonce('evk_anim_save'),
    ]);

    // SEO
    wp_localize_script('evoke-one-admin', 'evoSeoAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('evoke_seo_nonce'),
    ]);

    // OpenGraph — warstwy
    //
    // Do 1.59.0 te dane wstawiał PHP wprost w blok skryptu w treści
    // zakładki. Blok ruszał PRZED stopką, a `sortablejs` i `wp.media` jadą
    // właśnie ze stopki — przeciąganie warstw nie podpinało się nigdy, bo
    // `if (typeof Sortable !== 'undefined')` był fałszywy przy każdym wejściu.
    // Kod siedzi teraz w admin.js, który ma bibliotekę w zależnościach, a to,
    // co pochodziło z PHP, jedzie tędy.
    $og_settings = evk_og_get_settings();
    wp_localize_script('evoke-one-admin', 'evoOgData', [
        // Indeks dla NOWEJ warstwy. Musi ruszać za ostatnią istniejącą,
        // inaczej dodana warstwa wchodzi pod cudzy klucz i nadpisuje ją
        // przy zapisie.
        'layerCount' => count($og_settings['layers'] ?? []),
        'types'      => evk_og_layer_types(),
        'nonce'      => wp_create_nonce('evk_og_regen'),
    ]);

    // Toggle AJAX
    wp_localize_script('evoke-one-admin', 'evkToggle', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('evk-toggle-nonce'),
    ]);

    // ── Podgląd animacji w bibliotece ──
    // Silnik jest ten sam, którym animuje się strona: podgląd podaje wartości
    // pól w data-evk-anim i przechodzi przez buildConfig() → tweenVars().
    // Własna kopia tej logiki w panelu rozjechałaby się z silnikiem i podgląd
    // pokazywałby co innego niż odwiedzający.
    //
    // Rejestrację wołamy z ręki, bo evk_register_gsap_libs() wisi na
    // 'wp_enqueue_scripts', czyli wyłącznie na froncie.
    evk_register_gsap_libs();
    // Wtyczki tekstowe BEZWARUNKOWO. Na stronie dociąga się je zależnie od tego,
    // czego potrzebują zapisane wiersze — tu żaden taki warunek nie działa,
    // bo w panelu można wybrać dowolny z 35 presetów i od razu go odegrać.
    wp_enqueue_script('evk-splittext');
    wp_enqueue_script('evk-textplugin');
    wp_enqueue_script('evk-scrambletext');
    wp_enqueue_script('evk-animator', EVOKE_ONE_URL . 'assets/js/animator.js',
        ['evk-gsap', 'evk-splittext', 'evk-textplugin', 'evk-scrambletext'],
        EVOKE_ONE_VERSION, true);
    wp_add_inline_script('evk-animator', 'window.evkAnimator = ' . wp_json_encode([
        // Pusta biblioteka: silnik nie ma czego szukać w panelu, a start()
        // kończy wtedy od razu. Podgląd rejestruje wtyczki GSAP sam.
        'library' => (object) [],
        'presets' => evk_anim_presets(),
    ]) . ';', 'before');

    // Zapis ustawień bez przeładowania. Nonce'a NIE ma tu celowo: formularz
    // niesie już własny, wydrukowany przez settings_fields( $grupa ), i to on
    // decyduje o uprawnieniu. Drugi nonce byłby tylko drugą rzeczą do pilnowania.
    wp_localize_script('evoke-one-admin', 'evkSettingsSave', [
        'url'     => admin_url('admin-ajax.php'),
        'saving'  => __('Zapisuję…', 'evoke-one'),
        'saved'   => __('Zapisano.', 'evoke-one'),
        'failed'  => __('Nie udało się zapisać — wysyłam formularz normalnie.', 'evoke-one'),
    ]);

    wp_enqueue_media();
});

// =========================================================================
// HELPER — moduły IO
// =========================================================================

function evoke_one_get_io_modules(): array {
    return [
        // System tłumaczeń
        'tl_translations'     => 'Tłumaczenia',
        'tl_languages'        => 'Języki i ustawienia TL',
        'tl_images'           => 'Obrazy wielojęzyczne',
        'tl_url_slugs'        => 'Slugi URL',
        'tl_sitemap_settings' => 'Mapa strony TL',
        'tl_dd_keys'          => 'Klucze Dynamic Data',
        // Frontend
        'evk_darkmode'        => 'Dark Mode',
        'evk_cursor'          => 'Kursor',
        'evk_lenis'           => 'Smooth Scroll (Lenis)',
        'evk_animator'        => 'Animator',
        'evk_parallax'        => 'Parallax',
        'evk_a11y'            => 'Dostępność',
        // SEO & OG
        'evk_schema'          => 'Schema.org',
        'evk_og'              => 'OpenGraph',
        // Admin
        'evk_white_label'     => 'White Label',
        'evk_security'        => 'Bezpieczeństwo',
        'evk_smtp'            => 'SMTP',
        'evk_maintenance'     => 'Tryb konserwacji',
        'evk_redirects'       => 'Przekierowania 301',
        'evk_logs404'         => 'Ustawienia logów 404',
        'evk_dashboard'       => 'Kokpit Bricks',
        'evk_snippets'        => 'Fragmenty kodu',
        'evk_other'           => 'Inne ustawienia',
        'evk_newsletter'      => 'Newsletter',
    ];
}

// =========================================================================
// MENU
// =========================================================================

add_action('admin_menu', function () {
    add_options_page(
        'Evoke ONE',
        'Evoke ONE',
        'manage_options',
        'evoke-one',
        'evoke_one_render_settings'
    );
});

// =========================================================================
// RENDER — główna funkcja (router)
// =========================================================================

function evoke_one_render_settings(): void {
    if (!current_user_can('manage_options')) return;

    $tab  = sanitize_key($_GET['tab'] ?? 'dashboard');
    $sub  = sanitize_key($_GET['sub'] ?? '');
    $base = admin_url('options-general.php?page=evoke-one');

    $tabs = evoke_one_zakladki();

    if (!array_key_exists($tab, $tabs)) $tab = 'wydajnosc';

    // Mapowanie zakładki → plik
    $tab_files = [
        'dashboard'       => '',
        'wydajnosc'      => 'tab-wydajnosc.php',
        'strona'         => 'tab-strona.php',
        'bezpieczenstwo' => 'tab-bezpieczenstwo.php',
        'narzedzia'      => 'tab-narzedzia.php',
        'admin_panel'    => 'tab-admin.php',
        'newsletter'     => 'tab-newsletter.php',
        'forminbox'      => 'tab-forminbox.php',
    ];

    ?>
    <div class="wrap evo-control-center">
        <div class="evo-mobile-bar">
            <button type="button" class="evo-mobile-menu" aria-expanded="false" aria-controls="evo-sidebar">
                <span class="dashicons dashicons-menu"></span><span class="screen-reader-text">Otwórz menu</span>
            </button>
            <span class="evo-mobile-brand">Evoke ONE</span>
            <button type="button" class="evo-search-trigger" data-evo-search-open aria-label="Szukaj ustawień">
                <span class="dashicons dashicons-search"></span>
            </button>
        </div>

        <div class="evo-app-shell">
            <aside class="evo-sidebar" id="evo-sidebar">
                <div class="evo-brand">
                    <span class="dashicons dashicons-star-filled"></span>
                    <span>Evoke ONE</span>
                </div>
                <button type="button" class="evo-search-trigger evo-search-trigger-wide" data-evo-search-open>
                    <span class="dashicons dashicons-search"></span><span>Szukaj ustawień</span><kbd>⌘ K</kbd>
                </button>
                <nav class="evo-navigation" aria-label="Evoke ONE">
                    <p class="evo-nav-label">Przegląd</p>
                    <?php evoke_one_render_sidebar_link('dashboard', $tabs['dashboard'], $tab, $base, $sub); ?>
                    <p class="evo-nav-label">Moduły</p>
                    <?php foreach (['wydajnosc', 'strona', 'bezpieczenstwo', 'narzedzia', 'newsletter', 'forminbox'] as $key): ?>
                        <?php evoke_one_render_sidebar_link($key, $tabs[$key], $tab, $base, $sub); ?>
                    <?php endforeach; ?>
                    <p class="evo-nav-label">System</p>
                    <?php evoke_one_render_sidebar_link('admin_panel', $tabs['admin_panel'], $tab, $base, $sub); ?>
                    <?php /* „Pomoc" prowadziła na https://evoke.one — adres, pod którym
                             nie ma czego czytać. Pozycja w pasku, która nigdzie nie
                             prowadzi, jest gorsza niż jej brak: kosztuje kliknięcie
                             i zaufanie. Wróci, gdy będzie dokąd. */ ?>
                </nav>
                <div class="evo-sidebar-footer">v<?php echo esc_html(EVOKE_ONE_VERSION); ?></div>
            </aside>

            <main class="evo-main-content">
                <?php if ($tab !== 'dashboard'): ?>
                <header class="evo-content-header">
                    <div>
                        <p class="evo-eyebrow">Moduł</p>
                        <h1><?php echo esc_html($tabs[$tab]['label']); ?></h1>
                    </div>
                    <?php /* Stała plakietka „GOTOWE" stała tu do 1.138.0 i mówiła to samo
                             na każdej zakładce, niezależnie od stanu czegokolwiek. Zamiast niej
                             liczba ekranów sekcji — to akurat jest prawdą i mówi, ile jest do
                             obejrzenia po prawej. */ ?>
                    <?php $ile_ekranow = count(evoke_one_ekrany()[$tab] ?? []); ?>
                    <?php if ($ile_ekranow): ?>
                    <span class="evo-content-status"><span></span> <?php echo (int) $ile_ekranow; ?> ekranów</span>
                    <?php endif; ?>
                </header>
                <?php endif; ?>
                <div class="evo-panel <?php echo $tab === 'dashboard' ? 'evo-panel-dashboard' : ''; ?>">
            <?php
            if ($tab === 'dashboard') {
                evoke_one_render_control_center($base);
            } else {
                $tab_file = EVOKE_ONE_DIR . 'includes/admin/' . ($tab_files[$tab] ?? '');
                if ($tab_file && file_exists($tab_file)) {
                require $tab_file;
                } else {
                echo '<p class="evo-danger-tx">Błąd: plik zakładki nie istnieje.</p>';
                }
                }
                ?>
                </div>
                <?php if ($tab !== 'dashboard') evoke_one_render_command_palette($base); ?>
            </main>
        </div>
    </div>
    <?php
}

/**
 * Pozycja paska bocznego, razem z jej ekranami.
 *
 * ZGŁOSZONE Z UŻYCIA: „dodaj animatora do panelu z lewej". Do 1.138.0 pasek
 * pokazywał wyłącznie osiem zakładek, więc do Animatora trzeba było wejść
 * w Frontend i dopiero tam wybrać go z paska podzakładek — dwa kliknięcia do
 * ekranu używanego najczęściej.
 *
 * Rozwinięta jest TYLKO sekcja bieżąca. Pełna lista 31 ekranów naraz jest
 * równie nieczytelna co dwupoziomowy pasek u góry, od którego uciekaliśmy.
 *
 * Stare adresy `?tab=` działają bez zmian; `?sub=` dokładamy tylko w linkach
 * drugiego poziomu.
 */
function evoke_one_render_sidebar_link(string $key, array $tab, string $active, string $base, string $sub_active = ''): void {
    printf(
        '<a href="%s" class="evo-sidebar-link%s"><span class="dashicons %s"></span><span>%s</span></a>',
        esc_url(add_query_arg('tab', $key, $base)),
        $key === $active ? ' is-active' : '',
        esc_attr($tab['icon']),
        esc_html($tab['label'])
    );

    $ekrany = evoke_one_ekrany()[$key] ?? [];
    if (!$ekrany || $key !== $active) return;

    /* Bez `?sub=` w adresie każda zakładka otwiera swój pierwszy ekran —
       tak samo, jak rozstrzygają to same pliki zakładek (`if (!array_key_exists(
       $sub, $subs)) $sub = 'parallax';` i odpowiedniki). Zaznaczamy więc
       pierwszy, żeby pasek mówił to, co widać po prawej. */
    $biezacy = isset($ekrany[$sub_active]) ? $sub_active : (string) array_key_first($ekrany);

    echo '<div class="evo-sidebar-sub">';
    foreach ($ekrany as $klucz => $ekran) {
        printf(
            '<a href="%s" class="evo-sidebar-sublink%s">%s</a>',
            esc_url(add_query_arg(['tab' => $key, 'sub' => $klucz], $base)),
            $klucz === $biezacy ? ' is-active' : '',
            esc_html($ekran['label'])
        );
    }
    echo '</div>';
}

/**
 * Wyszukiwarka ustawień — lista budowana z mapy ekranów, nie wpisana z ręki.
 *
 * Do 1.138.0 stało tu czternaście pozycji wypisanych obok `$tabs`, przy panelu
 * mającym 34 ekrany. Taka lista nie ma jak nadążyć: dołożenie modułu nie
 * przypomina o dopisaniu go tutaj i nic tego nie zauważa.
 *
 * Teraz źródłem jest `evoke_one_ekrany()`, czyli to samo, z czego renderują się
 * paski podzakładek. Do etykiety dokładamy słowa pomocnicze z mapy — filtr
 * w admin.js czyta `textContent` całego wpisu, więc „dark" znajduje „Tryb
 * ciemny", a „gsap" — Animator. Słowa siedzą w osobnym elemencie ukrytym
 * wizualnie, żeby lista pozostała czytelna.
 */
function evoke_one_render_command_palette(string $base): void {
    $zakladki = evoke_one_zakladki();
    $ekrany   = evoke_one_ekrany();

    $items = [];
    foreach ($zakladki as $klucz => $zakladka) {
        if ($klucz === 'dashboard') continue;   // pulpit jest tam, gdzie stoisz

        if (empty($ekrany[$klucz])) {
            // Zakładka bez ekranów w środku — Newsletter i Formularze.
            $items[] = [$zakladka['label'], $klucz, '', ''];
            continue;
        }
        foreach ($ekrany[$klucz] as $sub => $ekran) {
            $items[] = [
                $zakladka['label'] . ' / ' . $ekran['label'],
                $klucz,
                $sub,
                (string) ($ekran['szukaj'] ?? ''),
            ];
        }
    }
    ?>
    <div class="evo-command-palette" id="evo-command-palette" aria-hidden="true">
        <div class="evo-command-dialog" role="dialog" aria-modal="true" aria-label="Szukaj ustawień">
            <label><span class="dashicons dashicons-search"></span><input type="search" id="evo-command-input" placeholder="Szukaj ustawień…" autocomplete="off"></label>
            <div class="evo-command-results">
                <?php foreach ($items as $item): ?>
                <a href="<?php echo esc_url(add_query_arg(array_filter(['tab' => $item[1], 'sub' => $item[2]]), $base)); ?>" data-evo-search-item><?php echo esc_html($item[0]); ?><?php if ($item[3] !== ''): ?><i class="evo-command-terms"><?php echo esc_html($item[3]); ?></i><?php endif; ?><span>→</span></a>
                <?php endforeach; ?>
            </div>
            <p class="evo-command-empty">Brak pasujących ustawień.</p>
        </div>
    </div>
    <?php
}

/**
 * Lekki dashboard oparty wyłącznie o dane, które wtyczka już zapisuje.
 * Nie udajemy audytu zewnętrznego: wynik Health oznacza gotowość konfiguracji
 * Evoke ONE, a nie ocenę bezpieczeństwa całej infrastruktury serwera.
 */
function evoke_one_render_control_center(string $base): void {
    $frontend = [
        'evk_animator', 'evk_parallax', 'evk_darkmode', 'evk_cursor',
        'evk_lenis', 'evk_bgshift', 'evk_fonts', 'evk_theme_color', 'evk_a11y',
    ];
    $frontend_active = 0;
    foreach ($frontend as $option) {
        $settings = get_option($option, []);
        if (is_array($settings) && !empty($settings['enabled'])) $frontend_active++;
    }

    $security = evk_security_get();
    $cleanup  = get_option('evk_cleanup', []);
    $security_active = count(array_filter([
        !empty($security['limit_login_enabled']), !empty($security['hide_wp_version']),
        !empty($security['rest_block_all']), !empty($cleanup['disable_xmlrpc']), !empty($cleanup['remove_rss']),
    ]));
    $seo_active = count(array_filter([
        !empty(get_option('evk_schema', [])['enabled']), !empty(get_option('evk_og', [])['enabled']),
        !empty(tl_get_sitemap_settings()['enabled']),
    ]));
    $tool_active = count(array_filter([
        (bool) get_option('evk_301_enabled', 0), (bool) get_option('evk_404_enabled', 0),
        !empty(get_option('evk_smtp', [])['enabled']), (bool) get_option('maintenance_mode', 0),
    ]));
    $newsletter_active = !empty(get_option('evk_newsletter', [])['enabled']);
    $inbox_settings    = get_option('evk_forminbox', []);
    $inbox_active      = !empty($inbox_settings['enabled']);

    /* OCENIAMY WYŁĄCZNIE TO, CO DA SIĘ NIE ZDAĆ.
     *
     * Wcześniej w tej liście stały `defined('ABSPATH')` i `PHP >= 7.4`. Obie są
     * prawdziwe zawsze, gdy ten kod się w ogóle wykonuje: bez `ABSPATH` plik
     * kończy pracę w pierwszej linii, a wersji PHP niższej niż wymagana wtyczka
     * nie obsłuży. Wynik miał przez to podłogę 25/100 i przy pustej instalacji
     * pokazywał 38, choć nie było skonfigurowane nic.
     *
     * Liczba ma odpowiadać na pytanie „ile z tego, co mogę włączyć, mam
     * włączone" — więc mianownikiem jest to, co realnie zależy od decyzji
     * osoby przy panelu. */
    $checks = [
        ['label' => 'HTTPS',   'ok' => is_ssl()],
        ['label' => 'XML-RPC', 'ok' => !empty($cleanup['disable_xmlrpc']), 'url' => add_query_arg(['tab' => 'bezpieczenstwo', 'sub' => 'cleanup'], $base)],
        ['label' => 'Limit logowań', 'ok' => !empty($security['limit_login_enabled']), 'url' => add_query_arg(['tab' => 'bezpieczenstwo', 'sub' => 'login'], $base)],
        ['label' => 'SMTP',    'ok' => !empty(get_option('evk_smtp', [])['enabled']), 'url' => add_query_arg(['tab' => 'narzedzia', 'sub' => 'smtp'], $base)],
        ['label' => 'Schema',  'ok' => !empty(get_option('evk_schema', [])['enabled']), 'url' => add_query_arg(['tab' => 'strona', 'sub' => 'schema'], $base)],
        ['label' => 'Sitemap', 'ok' => !empty(tl_get_sitemap_settings()['enabled']), 'url' => add_query_arg(['tab' => 'strona', 'sub' => 'sitemap'], $base)],
    ];

    /* Środowisko — do zobaczenia, nie do oceniania. Wersja PHP i obecność
     * Bricksa są przydatne przy zgłoszeniu („na czym to stoi"), ale nie są
     * niczyją decyzją, więc nie wchodzą do mianownika. */
    $srodowisko = [
        ['label' => 'PHP ' . PHP_VERSION, 'ok' => true],
        ['label' => 'Bricks', 'ok' => defined('BRICKS_VERSION') || class_exists('Bricks\\Helpers')],
    ];

    $passed = count(array_filter($checks, static function ($check) { return $check['ok']; }));
    $score  = (int) round(($passed / count($checks)) * 100);
    $cards = [
        ['tab' => 'wydajnosc', 'icon' => 'dashicons-desktop',       'name' => 'Frontend',      'meta' => $frontend_active . ' aktywnych z ' . count($frontend)],
        ['tab' => 'strona', 'icon' => 'dashicons-search',            'name' => 'SEO',           'meta' => $seo_active . ' aktywne obszary'],
        ['tab' => 'bezpieczenstwo', 'icon' => 'dashicons-shield',    'name' => 'Bezpieczeństwo','meta' => $security_active . ' aktywnych zabezpieczeń'],
        ['tab' => 'narzedzia', 'icon' => 'dashicons-admin-tools',    'name' => 'Narzędzia',     'meta' => $tool_active . ' aktywne narzędzia'],
        ['tab' => 'newsletter', 'icon' => 'dashicons-email-alt',     'name' => 'Newsletter',    'meta' => $newsletter_active ? 'moduł aktywny' : 'moduł wyłączony'],
        ['tab' => 'forminbox', 'icon' => 'dashicons-feedback',       'name' => 'Formularze',    'meta' => $inbox_active ? 'skrzynka aktywna' : 'skrzynka wyłączona'],
    ];
    ?>
    <header class="evo-dashboard-header">
        <div><p class="evo-eyebrow">Evoke ONE</p><h1>Control Center</h1><p>Jedno miejsce do zarządzania stroną WordPress i Bricks.</p></div>
        <span class="evo-page-version">v<?php echo esc_html(EVOKE_ONE_VERSION); ?></span>
    </header>

    <section class="evo-health-card">
        <div class="evo-health-summary">
            <span class="evo-health-dot"></span><div><strong><?php echo $passed === count($checks) ? 'Wszystko działa dobrze' : 'Wymaga uwagi'; ?></strong><p>Stan konfiguracji Evoke ONE</p></div>
        </div>
        <div class="evo-health-score"><strong><?php echo esc_html((string) $score); ?></strong><span>/ 100</span></div>
        <div class="evo-health-checks">
            <?php foreach ($checks as $check): ?><span class="<?php echo $check['ok'] ? 'is-ok' : 'is-warn'; ?>"><i></i><?php echo esc_html($check['label']); ?></span><?php endforeach; ?>
            <?php foreach ($srodowisko as $check): ?><span class="is-info" title="Informacja o środowisku — nie wchodzi do wyniku"><i></i><?php echo esc_html($check['label']); ?></span><?php endforeach; ?>
        </div>
    </section>

    <section class="evo-dashboard-section"><div class="evo-section-heading"><h2>Moduły</h2><p>Przejdź bezpośrednio do konfiguracji.</p></div>
        <div class="evo-module-grid">
        <?php foreach ($cards as $card): ?>
            <a class="evo-module-card" href="<?php echo esc_url(add_query_arg('tab', $card['tab'], $base)); ?>">
                <span class="dashicons <?php echo esc_attr($card['icon']); ?>"></span><h3><?php echo esc_html($card['name']); ?></h3><p><?php echo esc_html($card['meta']); ?></p><span class="evo-module-open">Otwórz <b>→</b></span>
            </a>
        <?php endforeach; ?>
        </div>
    </section>

    <section class="evo-dashboard-section evo-quick-actions"><div class="evo-section-heading"><h2>Szybkie akcje</h2><p>Najczęściej używane narzędzia.</p></div>
        <div>
            <a href="<?php echo esc_url(add_query_arg(['tab' => 'narzedzia', 'sub' => 'redirect'], $base)); ?>" class="button">+ Nowy redirect</a>
            <a href="<?php echo esc_url(add_query_arg(['tab' => 'narzedzia', 'sub' => 'logs404'], $base)); ?>" class="button">Sprawdź 404</a>
            <a href="<?php echo esc_url(add_query_arg(['tab' => 'strona', 'sub' => 'og'], $base)); ?>" class="button">Generator OG</a>
            <a href="<?php echo esc_url(add_query_arg(['tab' => 'narzedzia', 'sub' => 'smtp'], $base)); ?>" class="button">SMTP test</a>
        </div>
    </section>

    <section class="evo-dashboard-section evo-health-details"><div class="evo-section-heading"><h2>Evoke Health</h2><p>Konfiguracja wymagająca działania.</p></div>
        <?php foreach ($checks as $check): if ($check['ok']) continue; ?>
            <div class="evo-health-issue"><span class="dashicons dashicons-warning"></span><span><strong><?php echo esc_html($check['label']); ?></strong> nie jest obecnie gotowe lub skonfigurowane.<?php if (!empty($check['url'])): ?> <a href="<?php echo esc_url($check['url']); ?>">Skonfiguruj →</a><?php endif; ?></span></div>
        <?php endforeach; ?>
        <?php if ($passed === count($checks)): ?><div class="evo-health-issue is-good"><span class="dashicons dashicons-yes-alt"></span><span>Wszystkie podstawowe kontrolki Evoke ONE są gotowe.</span></div><?php endif; ?>
    </section>

    <?php evoke_one_render_command_palette($base); ?>
    <?php
}
