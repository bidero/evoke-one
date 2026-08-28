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

                <div class="evo-box">
                    <h3>Ruch i płynność</h3>
                    <div class="evo-grid evo-mb-lg" style="--evo-col:220px;--evo-gap:16px">
                        <div class="evo-field evo-mb-0">
                            <label>Duration (s)</label>
                            <input type="number" name="evk_lenis[duration]" value="<?php echo esc_attr($lenis['duration']); ?>" min="0.1" max="10" step="0.1">
                            <div class="evo-desc">Czas trwania animacji przewijania.</div>
                        </div>
                        <div class="evo-field evo-mb-0">
                            <label>Lerp (bezwładność)</label>
                            <div class="evo-slider-wrap">
                                <div class="evo-slider-track">
                                    <div class="evo-slider-fill" id="fill-lerp"></div>
                                    <!-- Suwak steruje tylko wizualnie — zapisywana jest wartość z pola obok,
                                         dzięki temu da się ustawić dokładną liczbę (np. 0.08). -->
                                    <input type="range" class="evo-range" id="lenis_lerp" min="0.01" max="1" step="0.005" value="<?php echo esc_attr($lenis['lerp']); ?>">
                                    <div class="evo-slider-thumb" id="thumb-lerp"></div>
                                </div>
                                <input type="number" class="evo-slider-value" id="value-lerp" name="evk_lenis[lerp]" min="0.01" max="1" step="0.005" value="<?php echo esc_attr($lenis['lerp']); ?>">
                            </div>
                            <div class="evo-desc">Im mniej, tym płynniej (0.01 – 1.0). Domyślnie 0.08 — wartość można wpisać ręcznie.</div>
                        </div>
                        <div class="evo-field evo-mb-0">
                            <label>Wheel Multiplier</label>
                            <input type="number" name="evk_lenis[wheel_multiplier]" value="<?php echo esc_attr($lenis['wheel_multiplier']); ?>" min="0.1" max="10" step="0.1">
                            <div class="evo-desc">Mnożnik prędkości kółka myszy.</div>
                        </div>
                        <div class="evo-field evo-mb-0">
                            <label>Touch Multiplier</label>
                            <input type="number" name="evk_lenis[touch_multiplier]" value="<?php echo esc_attr($lenis['touch_multiplier']); ?>" min="0.1" max="10" step="0.1">
                            <div class="evo-desc">Mnożnik prędkości przewijania dotykiem.</div>
                        </div>
                        <div class="evo-field evo-mb-0">
                            <label>Touch Inertia Exponent</label>
                            <input type="number" name="evk_lenis[touch_inertia]" value="<?php echo esc_attr($lenis['touch_inertia']); ?>" min="1" max="5" step="0.1">
                            <div class="evo-desc">Bezwładność po zwolnieniu dotyku.</div>
                        </div>
                        <div class="evo-field evo-mb-0">
                            <label>Sync Touch Lerp</label>
                            <input type="number" name="evk_lenis[sync_touch_lerp]" value="<?php echo esc_attr($lenis['sync_touch_lerp']); ?>" min="0.01" max="1" step="0.01">
                            <div class="evo-desc">Lerp przy synchronizacji dotyku.</div>
                        </div>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Orientacja</h3>
                    <div class="evo-grid evo-mb-lg" style="--evo-col:220px;--evo-gap:16px">
                        <div class="evo-field evo-mb-0">
                            <label>Orientacja przewijania</label>
                            <select name="evk_lenis[orientation]" class="evo-w-full">
                                <option value="vertical"   <?php selected($lenis['orientation'], 'vertical'); ?>>Pionowa (vertical)</option>
                                <option value="horizontal" <?php selected($lenis['orientation'], 'horizontal'); ?>>Pozioma (horizontal)</option>
                            </select>
                        </div>
                        <div class="evo-field evo-mb-0">
                            <label>Orientacja gestów</label>
                            <select name="evk_lenis[gesture_orientation]" class="evo-w-full">
                                <option value="vertical"   <?php selected($lenis['gesture_orientation'], 'vertical'); ?>>Pionowa (vertical)</option>
                                <option value="horizontal" <?php selected($lenis['gesture_orientation'], 'horizontal'); ?>>Pozioma (horizontal)</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Opcje</h3>
                    <div class="evo-toolbar evo-toolbar-top evo-mb-lg" style="--evo-gap:20px">
                        <?php
                        $checkboxes = [
                            'auto_raf'     => ['Automatyczny RAF', 'Automatyczna pętla requestAnimationFrame.'],
                            'smooth_wheel' => ['Smooth Wheel', 'Wygładzone zdarzenia kółka myszy.'],
                            'sync_touch'   => ['Sync Touch', 'Synchronizacja dotyku (może być niestabilne na starszych iOS).'],
                            'infinite'     => ['Infinite Scroll', 'Nieskończone przewijanie.'],
                            'overscroll'   => ['Overscroll', 'Efekt odbicia przy końcu strony.'],
                        ];
                        foreach ($checkboxes as $key => [$label, $desc]): ?>
                        <label class="evo-choice evo-choice-stack evo-grow" style="--evo-min:200px">
                            <input type="checkbox" name="evk_lenis[<?php echo $key; ?>]" value="1" <?php checked(!empty($lenis[$key])); ?>>
                            <span><strong><?php echo $label; ?></strong><span class="evo-hint"><?php echo $desc; ?></span></span>
                        </label>
                        <?php endforeach; ?>
                    </div>

                
                </div>

<div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia Smooth Scroll', 'primary', 'submit', false); ?>
                </div>
            </form>
