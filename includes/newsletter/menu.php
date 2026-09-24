<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — Menu administratora
 * Osobna pozycja głównego menu (jak Tłumaczenia) z konfiguracją lokalizacji.
 */

define('EVK_NL_MENU_SLUG',  'evoke-newsletter');
define('EVK_NL_MENU_TITLE', 'Newsletter');

add_action('admin_menu', function () {
    $opts     = get_option('evk_newsletter', []);
    if (empty($opts['enabled'])) return;  // wyłączony = brak pozycji menu

    $location = $opts['menu_location'] ?? 'toplevel';

    if ($location === 'settings') {
        add_options_page(
            EVK_NL_MENU_TITLE,
            EVK_NL_MENU_TITLE,
            'evk_access_newsletter',
            EVK_NL_MENU_SLUG,
            'evk_nl_render_page'
        );
    } else {
        add_menu_page(
            EVK_NL_MENU_TITLE,
            EVK_NL_MENU_TITLE,
            'evk_access_newsletter',
            EVK_NL_MENU_SLUG,
            'evk_nl_render_page',
            'dashicons-email-alt',
            82
        );
    }
}, 99);

function evk_nl_base_url(): string {
    $opts     = get_option('evk_newsletter', []);
    $location = $opts['menu_location'] ?? 'toplevel';
    if ($location === 'settings') {
        return admin_url('options-general.php?page=' . EVK_NL_MENU_SLUG);
    }
    return admin_url('admin.php?page=' . EVK_NL_MENU_SLUG);
}

// =========================================================================
// RENDER — strona Newsletter (router subtabów)
// =========================================================================

function evk_nl_render_page(): void {
    if (!current_user_can('manage_options') && !current_user_can('evk_access_newsletter')) wp_die('Brak uprawnień.');

    $nl_opts   = get_option('evk_newsletter', []);
    $nl_active = !empty($nl_opts['enabled']);
    $subtab    = sanitize_key($_GET['subtab'] ?? 'lists');
    $base      = evk_nl_base_url();

    $subtabs = [
        'lists'     => ['label' => 'Listy',    'icon' => 'dashicons-groups'],
        'templates' => ['label' => 'Szablony', 'icon' => 'dashicons-email-alt'],
        'campaigns' => ['label' => 'Kampanie', 'icon' => 'dashicons-megaphone'],
        'reports'   => ['label' => 'Raporty',  'icon' => 'dashicons-chart-bar'],
        'settings'  => ['label' => 'Ustawienia','icon' => 'dashicons-admin-settings'],
    ];

    if (!array_key_exists($subtab, $subtabs)) $subtab = 'lists';

    wp_enqueue_style('evoke-one-admin', EVOKE_ONE_URL . 'assets/admin/admin.css', [], EVOKE_ONE_VERSION);
    wp_enqueue_script('evoke-one-admin', EVOKE_ONE_URL . 'assets/admin/admin.js', ['jquery'], EVOKE_ONE_VERSION, true);
    wp_localize_script('evoke-one-admin', 'evkToggle', [
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('evk-toggle-nonce'),
    ]);
    wp_enqueue_media();
    ?>
    <div class="wrap">
        <h1 style="display:flex;align-items:center;gap:10px;">
            <span class="dashicons dashicons-email-alt" style="color:#2563eb;font-size:26px;width:26px;height:26px;line-height:1;"></span>
            Newsletter
            <span style="font-size:11px;color:#94a3b8;font-weight:400;">Evoke ONE v<?php echo esc_html(EVOKE_ONE_VERSION); ?></span>
        </h1>

        <div class="evo-tabs">
            <?php foreach ($subtabs as $key => $st): ?>
            <a href="<?php echo esc_url(add_query_arg('subtab', $key, $base)); ?>"
               class="evo-tab <?php echo $subtab === $key ? 'active' : ''; ?>">
                <span class="dashicons <?php echo esc_attr($st['icon']); ?>"></span>
                <?php echo esc_html($st['label']); ?>
            </a>
            <?php endforeach; ?>
        </div>

        <div class="evo-panel">
            <?php
            // Czym pójdzie poczta (wp_mail od 1.233.0) — tylko gdy jest o czym mówić
            echo evk_nl_ostrzezenie_transportu();

            if ($subtab === 'settings') {
                evk_nl_render_settings_subtab();
            } elseif (!$nl_active && $subtab !== 'settings') {
                echo '<div style="padding:40px;text-align:center;background:#f8fafc;border-radius:10px;border:1px dashed #cbd5e1;">';
                echo '<span class="dashicons dashicons-email-alt" style="font-size:48px;width:48px;height:48px;color:#94a3b8;"></span>';
                echo '<p style="color:#64748b;margin:12px 0 0;">Włącz moduł Newsletter w zakładce <a href="' . esc_url(add_query_arg('subtab', 'settings', $base)) . '">Ustawienia</a>.</p>';
                echo '</div>';
            } else {
                $subtab_file = EVOKE_ONE_DIR . 'includes/admin/newsletter/tab-' . $subtab . '.php';
                if (file_exists($subtab_file)) {
                    require $subtab_file;
                } else {
                    echo '<p style="color:#dc2626;">Błąd: plik zakładki nie istnieje.</p>';
                }
            }
            ?>
        </div>
    </div>
    <?php
}

// =========================================================================
// USTAWIENIA NEWSLETTERA
// =========================================================================

function evk_nl_render_settings_subtab(): void {
    $opts     = get_option('evk_newsletter', []);
    $enabled  = !empty($opts['enabled']);
    $location = $opts['menu_location'] ?? 'toplevel';

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evk_nl_settings_nonce'])) {
        check_admin_referer('evk_nl_settings', 'evk_nl_settings_nonce');
        $current_nl = get_option('evk_newsletter', []);
        update_option('evk_newsletter', array_merge($current_nl, [
            'menu_location' => sanitize_key($_POST['nl_menu_location'] ?? 'toplevel'),
        ]));
        echo '<div class="notice notice-success inline" style="margin-bottom:12px;"><p>Ustawienia zapisane.</p></div>';
        $opts     = get_option('evk_newsletter', []);
        $enabled  = !empty($opts['enabled']);
        $location = $opts['menu_location'] ?? 'toplevel';
    }
    ?>
    <form method="post">
        <?php wp_nonce_field('evk_nl_settings', 'evk_nl_settings_nonce'); ?>
        <?php
        // Sprawdź status WP-Cron
        $cron_disabled = defined('DISABLE_WP_CRON') && DISABLE_WP_CRON;
        $next_cron     = wp_next_scheduled('evk_nl_process_batch');
        ?>

        <?php if ($cron_disabled): ?>
        <div class="notice notice-info inline" style="margin-bottom:16px;">
            <p>
                <strong>WP-Cron wyłączony</strong> — używasz systemowego crona (zalecane).
                Upewnij się że <code>wget -q -O- <?php echo esc_url(home_url('/wp-cron.php?doing_wp_cron')); ?></code>
                jest w crontab.
            </p>
        </div>
        <?php else: ?>
        <div class="notice notice-warning inline" style="margin-bottom:16px;">
            <p>
                <strong>WP-Cron aktywny</strong> — wysyłka opóźniona działa tylko gdy ktoś odwiedza stronę.
                Dla niezawodnej wysyłki dodaj do <code>wp-config.php</code>:<br>
                <code>define('DISABLE_WP_CRON', true);</code><br>
                i ustaw systemowy cron: <code>* * * * * wget -q -O- <?php echo esc_url(home_url('/wp-cron.php?doing_wp_cron')); ?> &>/dev/null</code>
            </p>
        </div>
        <?php endif; ?>

        <?php if ($next_cron): ?>
        <div class="notice notice-info inline" style="margin-bottom:16px;">
            <p>⏳ Następny zaplanowany batch: <strong><?php echo esc_html(date('d.m.Y H:i:s', $next_cron)); ?></strong></p>
        </div>
        <?php endif; ?>

        <table class="form-table">
            <tr>
                <th>Lokalizacja menu</th>
                <td>
                    <select name="nl_menu_location">
                        <option value="toplevel" <?php selected($location, 'toplevel'); ?>>Główne menu (jak Tłumaczenia)</option>
                        <option value="settings" <?php selected($location, 'settings'); ?>>Ustawienia WordPress</option>
                    </select>
                    <p class="description">Zmiana wymaga odświeżenia strony.</p>
                </td>
            </tr>
        </table>
        <?php submit_button('Zapisz ustawienia'); ?>
    </form>
    <?php
}
