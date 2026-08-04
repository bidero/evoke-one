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

    wp_enqueue_script('evoke-one-admin',
        EVOKE_ONE_URL . 'assets/admin/admin.js',
        ['jquery'], EVOKE_ONE_VERSION, true);

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
        'rowStart' => count($anim_settings['animations'] ?? []) + 100,
    ]);

    // SEO
    wp_localize_script('evoke-one-admin', 'evoSeoAjax', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('evoke_seo_nonce'),
    ]);

    // Toggle AJAX
    wp_localize_script('evoke-one-admin', 'evkToggle', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('evk-toggle-nonce'),
    ]);

    wp_enqueue_media();

    wp_enqueue_script('sortablejs',
        'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js',
        [], '1.15.2', true);
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
        'evk_snippets'        => 'Snippety PHP',
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

    $tab  = sanitize_key($_GET['tab'] ?? 'wydajnosc');
    $base = admin_url('options-general.php?page=evoke-one');

    $tabs = [
        'wydajnosc'      => ['label' => 'Frontend',        'icon' => 'dashicons-desktop'],
        'strona'         => ['label' => 'SEO',             'icon' => 'dashicons-search'],
        'bezpieczenstwo' => ['label' => 'Bezpieczeństwo',  'icon' => 'dashicons-shield'],
        'narzedzia'      => ['label' => 'Narzędzia',       'icon' => 'dashicons-admin-tools'],
        'admin_panel'    => ['label' => 'Panel admina',    'icon' => 'dashicons-admin-settings'],
        'newsletter'     => ['label' => 'Newsletter',      'icon' => 'dashicons-email-alt'],
        'forminbox'      => ['label' => 'Formularze',      'icon' => 'dashicons-feedback'],
    ];

    if (!array_key_exists($tab, $tabs)) $tab = 'wydajnosc';

    // Mapowanie zakładki → plik
    $tab_files = [
        'wydajnosc'      => 'tab-wydajnosc.php',
        'strona'         => 'tab-strona.php',
        'bezpieczenstwo' => 'tab-bezpieczenstwo.php',
        'narzedzia'      => 'tab-narzedzia.php',
        'admin_panel'    => 'tab-admin.php',
        'newsletter'     => 'tab-newsletter.php',
        'forminbox'      => 'tab-forminbox.php',
    ];

    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            <span class="dashicons dashicons-star-filled" style="color:#2563eb;font-size:26px;width:26px;height:26px;line-height:1;"></span>
            Evoke ONE
            <span style="font-size:11px;color:#94a3b8;font-weight:400;">v<?php echo esc_html(EVOKE_ONE_VERSION); ?></span>
        </h1>

        <div class="evo-tabs">
            <?php foreach ($tabs as $key => $t): ?>
            <a href="<?php echo esc_url(add_query_arg('tab', $key, $base)); ?>"
               class="evo-tab <?php echo $tab === $key ? 'active' : ''; ?>">
                <span class="dashicons <?php echo esc_attr($t['icon']); ?>"></span>
                <?php echo esc_html($t['label']); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="evo-panel">
            <?php
            $tab_file = EVOKE_ONE_DIR . 'includes/admin/' . ($tab_files[$tab] ?? '');
            if ($tab_file && file_exists($tab_file)) {
                require $tab_file;
            } else {
                echo '<p style="color:#dc2626;">Błąd: plik zakładki nie istnieje.</p>';
            }
            ?>
        </div>
    </div>
    <?php
}
