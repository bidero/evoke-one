<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Admin subtab: Tłumaczenia (włącznik modułu)
 */
$tl_enabled     = !empty(get_option('evk_tl_module_enabled', 0));
$tl_fab_enabled = !empty(get_option('evk_tl_fab_enabled', 0));
$tl_url         = function_exists('tl_base_url') ? tl_base_url() : admin_url('options-general.php?page=evoke-tlumaczenia');
?>

<div class="evo-status-card">
    <div class="evo-status-icon <?php echo $tl_enabled ? 'on' : 'off'; ?>">
        <span class="dashicons dashicons-translation" style="font-size:24px;width:24px;height:24px;line-height:1;"></span>
    </div>
    <div class="evo-status-text">
        <h3>Moduł tłumaczeń: <?php echo $tl_enabled ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
        <p>Gdy wyłączony — cały silnik tłumaczeń (URL, engine, switcher, sitemap) nie jest ładowany. Ustawienia są zachowane.</p>
    </div>
    <div class="evo-status-actions">
        <label class="evo-toggle">
            <input type="checkbox"
                   data-option="evk_tl_module_enabled"
                   data-field="_scalar"
                   value="1"
                   <?php checked($tl_enabled); ?>>
            <span class="evo-slider"></span>
        </label>
    </div>
</div>

<div class="evo-status-card" style="margin-top:16px;">
    <div class="evo-status-icon <?php echo $tl_fab_enabled ? 'on' : 'off'; ?>">
        <span class="dashicons dashicons-edit" style="font-size:24px;width:24px;height:24px;line-height:1;"></span>
    </div>
    <div class="evo-status-text">
        <h3>Edytor inline (FAB): <?php echo $tl_fab_enabled ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
        <p>Pływający przycisk edycji tłumaczeń na frontendzie (widoczny tylko dla administratora).</p>
    </div>
    <div class="evo-status-actions">
        <label class="evo-toggle">
            <input type="checkbox"
                   data-option="evk_tl_fab_enabled"
                   data-field="_scalar"
                   value="1"
                   <?php checked($tl_fab_enabled); ?>>
            <span class="evo-slider"></span>
        </label>
    </div>
</div>

<details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">
        Pełne ustawienia tłumaczeń (języki, frazy, slugi URL, sitemap) dostępne są w osobnym panelu.
        <a href="<?php echo esc_url($tl_url); ?>" class="button button-secondary" style="margin-left:12px;">
            <span class="dashicons dashicons-external"></span>
            Otwórz panel tłumaczeń
        </a>
    </div></details>
