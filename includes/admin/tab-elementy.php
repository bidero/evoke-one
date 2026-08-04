<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Wydajność → Elementy Bricks
 */

$reg = function_exists('evk_elements_registry') ? evk_elements_registry() : [];
$en  = function_exists('evk_elements_enabled')  ? evk_elements_enabled()  : [];
$loaded = $GLOBALS['evk_loaded_elements'] ?? [];
?>
<div class="evo-tab-content">

    <p class="evo-section-title">Elementy Bricks</p>
    <div class="evo-info-box" style="margin-bottom:16px;">
        <span class="dashicons dashicons-info"></span>
        <div>
            Elementy Bricks są częścią Evoke ONE — włącz tylko te, których używasz (domyślnie wyłączone).
            Włączone elementy znajdziesz w builderze w grupie <strong>Evoke ONE</strong>, pod tymi samymi nazwami co poniżej.
            Wspólne biblioteki (<strong>GSAP, ScrollTrigger, Observer</strong>) ładowane są raz, bez duplikowania między elementami.
            Jeśli wykryję aktywną samodzielną wtyczkę danego elementu, Evoke ONE ustępuje jej miejsca — bez podwójnej rejestracji.
        </div>
    </div>

    <?php if (empty($reg)): ?>
        <p style="color:#dc2626;">Loader elementów nie został załadowany.</p>
    <?php else: foreach ($reg as $key => $el):
        $on         = !empty($en[$key]);
        $standalone = evk_element_class_loaded($el) && empty($loaded[$key]);
    ?>
    <div class="evo-status-card" style="margin-bottom:12px;">
        <div class="evo-status-icon <?php echo $on ? 'on' : 'off'; ?>">
            <span class="dashicons <?php echo esc_attr($el['icon']); ?>"></span>
        </div>
        <div class="evo-status-text">
            <h3>
                <?php echo esc_html($el['label']); ?>: <?php echo $on ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?>
                <?php if ($standalone): ?>
                    <span style="font-size:11px;color:#b45309;background:#fef3c7;padding:2px 8px;border-radius:10px;margin-left:6px;font-weight:500;">samodzielna wtyczka aktywna</span>
                <?php endif; ?>
            </h3>
            <p><?php echo esc_html($el['desc']); ?></p>
        </div>
        <div class="evo-status-actions">
            <span class="evo-toggle-label"><?php echo $on ? 'Włączony' : 'Wyłączony'; ?></span>
            <label class="evo-toggle">
                <input type="checkbox" data-option="evk_elements" data-field="<?php echo esc_attr($key); ?>" value="1" <?php checked($on); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>
    <?php endforeach; endif; ?>

</div>
