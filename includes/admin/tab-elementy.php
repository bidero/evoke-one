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

    <div class="evo-box">
        <h3>Elementy Bricks</h3>
        <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">
                Elementy Bricks są częścią Evoke ONE — włącz tylko te, których używasz (domyślnie wyłączone).
                Włączone elementy znajdziesz w builderze w grupie <strong>Evoke ONE</strong>, pod tymi samymi nazwami co poniżej.
                Wspólne biblioteki (<strong>GSAP, ScrollTrigger, Observer</strong>) ładowane są raz, bez duplikowania między elementami.
                Jeśli wykryję aktywną samodzielną wtyczkę danego elementu, Evoke ONE ustępuje jej miejsca — bez podwójnej rejestracji.
            </div></details>

        <?php if (empty($reg)): ?>
            <p class="evo-danger-tx">Loader elementów nie został załadowany.</p>
        <?php else: foreach ($reg as $key => $el):
            $on         = !empty($en[$key]);
            $standalone = evk_element_class_loaded($el) && empty($loaded[$key]);
        ?>
        <div class="evo-status-card">
            <div class="evo-status-icon <?php echo $on ? 'on' : 'off'; ?>">
                <span class="dashicons <?php echo esc_attr($el['icon']); ?>"></span>
            </div>
            <div class="evo-status-text">
                <h3>
                    <?php echo esc_html($el['label']); ?>: <?php echo $on ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?>
                    <?php if ($standalone): ?>
                        <span class="evo-pill is-warn">samodzielna wtyczka aktywna</span>
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
    </div>

