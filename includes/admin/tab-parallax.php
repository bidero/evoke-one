<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: parallax
 */

$parallax_opt = get_option('evk_parallax', []);
$par_enabled  = !empty($parallax_opt['enabled']);
?>
<div class="evo-status-card">
    <div class="evo-status-icon <?php echo $par_enabled ? 'on' : 'off'; ?>">
        <span class="dashicons dashicons-image-flip-vertical evo-ico-lg"></span>
    </div>
    <div class="evo-status-text">
        <h3>Parallax: <?php echo $par_enabled ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
        <p><?php echo $par_enabled ? 'Skrypt parallax jest ładowany na frontendzie.' : 'Skrypt parallax nie jest ładowany — zero narzutu.'; ?></p>
    </div>
    <div class="evo-status-actions">
        <span class="evo-toggle-label"><?php echo $par_enabled ? 'Włączony' : 'Wyłączony'; ?></span>
        <label class="evo-toggle">
            <input type="checkbox"
                   data-option="evk_parallax"
                   data-field="enabled"
                   value="1"
                   <?php checked($par_enabled); ?>>
            <span class="evo-slider"></span>
        </label>
    </div>
</div>

<form method="post" action="options.php">
                <?php settings_fields('evoke_one_parallax'); ?>

                <div class="evo-box">
                    <h3>Ustawienia efektu Parallax</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Użyj <code>{evk_parallax}</code> i <code>{evk_parallax_scale}</code> w atrybutach elementów Bricks Builder lub jako <code>data-parallax</code> / <code>data-skala</code> na dowolnym elemencie HTML.</div></details>

                    <div class="evo-field">
                        <label>Intensywność parallax</label>
                        <div class="evo-slider-wrap">
                            <div class="evo-slider-track">
                                <div class="evo-slider-fill" id="fill-parallax"></div>
                                <input type="range" class="evo-range" id="evk_parallax_value" name="evk_parallax_value" min="-1" max="1" step="0.05" value="<?php echo esc_attr($parallax_value); ?>">
                                <div class="evo-slider-thumb" id="thumb-parallax"></div>
                            </div>
                            <span class="evo-slider-value" id="value-parallax"><?php echo esc_html($parallax_value); ?></span>
                        </div>
                        <div class="evo-desc">Zakres: -1 (odwrócony) do 1 (maksymalny). Domyślnie: 0.3</div>
                    </div>

                    <div class="evo-field">
                        <label>Skalowanie obrazu</label>
                        <div class="evo-slider-wrap">
                            <div class="evo-slider-track">
                                <div class="evo-slider-fill" id="fill-scale"></div>
                                <input type="range" class="evo-range" id="evk_parallax_scale" name="evk_parallax_scale" min="1" max="2" step="0.05" value="<?php echo esc_attr($scale_value); ?>">
                                <div class="evo-slider-thumb" id="thumb-scale"></div>
                            </div>
                            <span class="evo-slider-value" id="value-scale"><?php echo esc_html($scale_value); ?></span>
                        </div>
                        <div class="evo-desc">Zakres: 1 (bez skalowania) do 2 (dwukrotne). Domyślnie: 1.2</div>
                    </div>

                
                </div>

<div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia parallax', 'primary', 'submit', false); ?>
                </div>
            </form>
