<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — zakładka w panelu = włącznik + link.
 * Pełny moduł (listy/szablony/kampanie/raporty) ma własną pozycję w menu bocznym
 * (includes/newsletter/menu.php) — tu nie duplikujemy całego UI.
 */

$nl_opts   = get_option('evk_newsletter', []);
$nl_active = !empty($nl_opts['enabled']);
$nl_url    = function_exists('evk_nl_base_url')
    ? evk_nl_base_url()
    : admin_url('admin.php?page=evoke-newsletter');
$smtp_ok   = function_exists('evk_nl_smtp_is_configured') ? evk_nl_smtp_is_configured() : true;
?>

<div class="evk-nl-wrap">

    <!-- Status card — spójny z innymi modułami Evoke ONE -->
    <div class="evo-status-card">
        <div class="evo-status-icon <?php echo $nl_active ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-email-alt evo-ico-lg"></span>
        </div>
        <div class="evo-status-text">
            <h3>Newsletter: <?php echo $nl_active ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
            <p>Listy subskrybentów, szablony i kampanie email.</p>
        </div>
        <div class="evo-status-actions">
            <label class="evo-toggle">
                <input type="checkbox" id="evk-nl-toggle" data-option="evk_newsletter" data-field="enabled" value="1" <?php checked($nl_active); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>

    <?php if (!$nl_active): ?>
    <div class="evo-empty-panel evo-mt">
        <span class="dashicons dashicons-email-alt evo-ico-xl"></span>
        <p class="evo-muted evo-m0 evo-mt-xs">Włącz moduł Newsletter powyżej — w pasku bocznym pojawi się osobna pozycja <strong>Newsletter</strong> z pełnym panelem.</p>
    </div>
    <?php else: ?>

    <?php if (!$smtp_ok): ?>
    <div class="evo-info-box is-warn evo-mt">
        <span class="dashicons dashicons-warning evo-warn-tx"></span>
        <div>
            <strong>SMTP nie jest skonfigurowany</strong> — wysyłka maili nie będzie działać.
            <a href="<?php echo esc_url(admin_url('options-general.php?page=evoke-one&tab=narzedzia&sub=smtp')); ?>">Przejdź do konfiguracji SMTP →</a>
        </div>
    </div>
    <?php endif; ?>

    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">
            Pełny panel Newslettera — <strong>Listy, Szablony, Kampanie, Raporty, Ustawienia</strong> —
            znajdziesz w osobnej pozycji menu w pasku bocznym.
            <a href="<?php echo esc_url($nl_url); ?>" class="button button-secondary evo-ml">
                <span class="dashicons dashicons-external"></span>
                Otwórz panel Newslettera
            </a>
        </div></details>

    <?php endif; // module active ?>

</div>
