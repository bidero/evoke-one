<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: lenis
 */
?>
<?php $lenis = EVK_Lenis::get_instance()->get_settings(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_lenis'); ?>

                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($lenis['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons dashicons-sort"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Lenis Smooth Scroll: <?php echo !empty($lenis['enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
                        <p>Płynne przewijanie strony oparte o bibliotekę Lenis.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($lenis['enabled']) ? 'Włączony' : 'Wyłączony'; ?></span>
                        <label class="evo-toggle">
                            <input type="checkbox" data-option="evk_lenis" data-field="enabled" value="1" <?php checked(!empty($lenis['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <p class="evo-section-title">Ruch i płynność</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:24px;">
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Duration (s)</label>
                        <input type="number" name="evk_lenis[duration]" value="<?php echo esc_attr($lenis['duration']); ?>" min="0.1" max="10" step="0.1">
                        <div class="evo-desc">Czas trwania animacji przewijania.</div>
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Lerp (bezwładność)</label>
                        <div class="evo-slider-wrap">
                            <div class="evo-slider-track">
                                <div class="evo-slider-fill" id="fill-lerp"></div>
                                <input type="range" class="evo-range" id="lenis_lerp" name="evk_lenis[lerp]" min="0.01" max="1" step="0.01" value="<?php echo esc_attr($lenis['lerp']); ?>">
                                <div class="evo-slider-thumb" id="thumb-lerp"></div>
                            </div>
                            <span class="evo-slider-value" id="value-lerp"><?php echo esc_html($lenis['lerp']); ?></span>
                        </div>
                        <div class="evo-desc">Im mniej, tym płynniej (0.01 – 1.0).</div>
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Wheel Multiplier</label>
                        <input type="number" name="evk_lenis[wheel_multiplier]" value="<?php echo esc_attr($lenis['wheel_multiplier']); ?>" min="0.1" max="10" step="0.1">
                        <div class="evo-desc">Mnożnik prędkości kółka myszy.</div>
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Touch Multiplier</label>
                        <input type="number" name="evk_lenis[touch_multiplier]" value="<?php echo esc_attr($lenis['touch_multiplier']); ?>" min="0.1" max="10" step="0.1">
                        <div class="evo-desc">Mnożnik prędkości przewijania dotykiem.</div>
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Touch Inertia Exponent</label>
                        <input type="number" name="evk_lenis[touch_inertia]" value="<?php echo esc_attr($lenis['touch_inertia']); ?>" min="1" max="5" step="0.1">
                        <div class="evo-desc">Bezwładność po zwolnieniu dotyku.</div>
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Sync Touch Lerp</label>
                        <input type="number" name="evk_lenis[sync_touch_lerp]" value="<?php echo esc_attr($lenis['sync_touch_lerp']); ?>" min="0.01" max="1" step="0.01">
                        <div class="evo-desc">Lerp przy synchronizacji dotyku.</div>
                    </div>
                </div>

                <p class="evo-section-title">Orientacja</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:24px;">
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Orientacja przewijania</label>
                        <select name="evk_lenis[orientation]" style="width:100%;">
                            <option value="vertical"   <?php selected($lenis['orientation'], 'vertical'); ?>>Pionowa (vertical)</option>
                            <option value="horizontal" <?php selected($lenis['orientation'], 'horizontal'); ?>>Pozioma (horizontal)</option>
                        </select>
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Orientacja gestów</label>
                        <select name="evk_lenis[gesture_orientation]" style="width:100%;">
                            <option value="vertical"   <?php selected($lenis['gesture_orientation'], 'vertical'); ?>>Pionowa (vertical)</option>
                            <option value="horizontal" <?php selected($lenis['gesture_orientation'], 'horizontal'); ?>>Pozioma (horizontal)</option>
                        </select>
                    </div>
                </div>

                <p class="evo-section-title">Opcje</p>
                <div style="display:flex;flex-wrap:wrap;gap:20px;margin-bottom:24px;">
                    <?php
                    $checkboxes = [
                        'auto_raf'     => ['Automatyczny RAF', 'Automatyczna pętla requestAnimationFrame.'],
                        'smooth_wheel' => ['Smooth Wheel', 'Wygładzone zdarzenia kółka myszy.'],
                        'sync_touch'   => ['Sync Touch', 'Synchronizacja dotyku (może być niestabilne na starszych iOS).'],
                        'infinite'     => ['Infinite Scroll', 'Nieskończone przewijanie.'],
                        'overscroll'   => ['Overscroll', 'Efekt odbicia przy końcu strony.'],
                    ];
                    foreach ($checkboxes as $key => [$label, $desc]): ?>
                    <label style="display:flex;align-items:flex-start;gap:9px;font-size:13px;font-weight:500;color:#111827;cursor:pointer;flex-basis:200px;">
                        <input type="checkbox" name="evk_lenis[<?php echo $key; ?>]" value="1" <?php checked(!empty($lenis[$key])); ?> style="margin-top:2px;">
                        <span><?php echo $label; ?><br><span style="font-weight:400;color:#6b7280;font-size:12px;"><?php echo $desc; ?></span></span>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia Smooth Scroll', 'primary', 'submit', false); ?>
                </div>
            </form>
