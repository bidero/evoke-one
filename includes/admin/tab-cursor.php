<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: cursor
 */
?>
<?php
            $cursor_settings = EVK_Cursor::get_instance()->get_settings();
            $elements        = $cursor_settings['elements'] ?? [];
            $cd              = $cursor_settings['cursor_default'] ?? [];
            $blend_modes     = ['normal', 'multiply', 'screen', 'overlay', 'darken', 'lighten',
                                'color-dodge', 'color-burn', 'hard-light', 'soft-light',
                                'difference', 'exclusion', 'hue', 'saturation', 'color', 'luminosity'];
            ?>
            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_cursor'); ?>

                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($cursor_settings['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons <?php echo !empty($cursor_settings['enabled']) ? 'dashicons-visibility' : 'dashicons-hidden'; ?>"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Moduł Kursora: <?php echo !empty($cursor_settings['enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
                        <p>Niestandardowy kursor zintegrowany z GSAP.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($cursor_settings['enabled']) ? 'Włączony' : 'Wyłączony'; ?></span>
                        <label class="evo-toggle">
                            <input type="checkbox" data-option="evk_cursor" data-field="enabled" value="1" <?php checked(!empty($cursor_settings['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Domyślny wygląd kursora</h3>
                    <div class="evo-info-box">
                        <span class="dashicons dashicons-info"></span>
                        <div>Ustawienia stosowane gdy kursor nie najedzie na żaden zdefiniowany selektor.</div>
                    </div>

                    <div style="background:#f8fafc;border:1px solid #d7dde7;border-radius:8px;padding:20px;margin-bottom:24px;">
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;">

                            <div class="evo-field" style="margin-bottom:0;">
                                <label>Rozmiar (px)</label>
                                <input type="number" name="evk_cursor[cursor_default][size]" value="<?php echo esc_attr($cd['size'] ?? 16); ?>" min="4" max="200">
                            </div>
                            <div class="evo-field" style="margin-bottom:0;">
                                <label>Kolor tła (CSS)</label>
                                <input type="text" name="evk_cursor[cursor_default][background_color]" value="<?php echo esc_attr($cd['background_color'] ?? 'white'); ?>" placeholder="white, rgba(255,255,255,1), #fff">
                            </div>
                            <div class="evo-field" style="margin-bottom:0;">
                                <label>Blend Mode</label>
                                <select name="evk_cursor[cursor_default][blend_mode]" style="width:100%;">
                                    <?php foreach ($blend_modes as $mode): ?>
                                    <option value="<?php echo $mode; ?>" <?php selected($cd['blend_mode'] ?? 'exclusion', $mode); ?>><?php echo $mode; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="evo-field" style="margin-bottom:0;">
                                <label>Backdrop Filter</label>
                                <input type="text" name="evk_cursor[cursor_default][backdrop_filter]" value="<?php echo esc_attr($cd['backdrop_filter'] ?? 'blur(0px)'); ?>" placeholder="blur(0px)">
                            </div>
                            <div class="evo-field" style="margin-bottom:0;">
                                <label>Szybkość animacji wejścia (s)</label>
                                <input type="number" name="evk_cursor[cursor_default][enter_duration]" value="<?php echo esc_attr($cd['enter_duration'] ?? 0.6); ?>" min="0.1" max="5" step="0.1">
                                <div class="evo-desc">Czas powiększania się kursora po najechaniu na element.</div>
                            </div>
                            <div class="evo-field" style="margin-bottom:0;">
                                <label>Szybkość powrotu do domyślnego (s)</label>
                                <input type="number" name="evk_cursor[cursor_default][leave_duration]" value="<?php echo esc_attr($cd['leave_duration'] ?? 0.3); ?>" min="0.1" max="5" step="0.1">
                                <div class="evo-desc">Czas animacji po opuszczeniu selektora.</div>
                            </div>
                            <div class="evo-field" style="margin-bottom:0;">
                                <label>Inercja (lerp)</label>
                                <div class="evo-slider-wrap">
                                    <div class="evo-slider-track">
                                        <div class="evo-slider-fill" id="fill-inertia"></div>
                                        <input type="range" class="evo-range" id="cursor_inertia_range"
                                            name="evk_cursor[cursor_default][inertia]"
                                            min="0.1" max="1" step="0.05"
                                            value="<?php echo esc_attr($cd['inertia'] ?? 0.5); ?>">
                                        <div class="evo-slider-thumb" id="thumb-inertia"></div>
                                    </div>
                                    <span class="evo-slider-value" id="value-inertia"><?php echo esc_html($cd['inertia'] ?? 0.5); ?></span>
                                </div>
                                <div class="evo-desc">0.1 = bardzo leniwy, 1.0 = natychmiastowy</div>
                            </div>
                            <div class="evo-field" style="margin-bottom:0;">
                                <label>Wyłącz na ekranach ≤ (px)</label>
                                <input type="number" name="evk_cursor[cursor_default][mobile_breakpoint]" value="<?php echo esc_attr($cd['mobile_breakpoint'] ?? 1024); ?>" min="0" max="2560">
                                <div class="evo-desc">Kursor nie ładuje się na wąskich ekranach.</div>
                            </div>

                        </div>

                        <hr class="evo-divider" style="margin:20px 0 16px;">
                        <div style="display:flex;flex-wrap:wrap;gap:20px;">
                            <label style="display:flex;align-items:center;gap:9px;font-size:13px;font-weight:500;color:#111827;cursor:pointer;">
                                <input type="checkbox" name="evk_cursor[cursor_default][hide_native]" value="1" <?php checked(!empty($cd['hide_native']), true); ?>>
                                Ukryj systemowy kursor (<code>cursor: none</code>) na całej stronie
                            </label>
                            <label style="display:flex;align-items:center;gap:9px;font-size:13px;font-weight:500;color:#111827;cursor:pointer;">
                                <input type="checkbox" name="evk_cursor[cursor_default][restore_on_inputs]" value="1" <?php checked(!empty($cd['restore_on_inputs']), true); ?>>
                                Przywróć systemowy kursor na <code>input</code>, <code>textarea</code>, <code>select</code>
                            </label>
                        </div>
                    </div>

                    <style>
                        .evo-cursor-row { background:#f8fafc; border:1px solid #d7dde7; border-radius:8px; padding:20px; margin-bottom:16px; position:relative; }
                        .evo-cursor-row-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e5e7eb; padding-bottom:12px; }
                        .evo-cursor-row-title { font-size:14px; font-weight:600; color:#111827; }
                        .evo-cursor-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:16px; }
                        .evo-cursor-grid label { display:block; font-size:12px; font-weight:600; color:#4b5563; margin-bottom:4px; }
                        .evo-cursor-grid input[type=text], .evo-cursor-grid input[type=number], .evo-cursor-grid select { width:100%; border:1px solid #d1d5db; border-radius:6px; font-size:13px; }
                        .evo-cursor-grid .checkbox-label { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:500; color:#111827; margin-top:20px; cursor:pointer; }
                        .evo-cursor-grid .checkbox-label input { margin:0; }
                        .evo-btn-remove { color:#ef4444; background:none; border:none; cursor:pointer; font-size:13px; font-weight:500; display:flex; align-items:center; gap:4px; }
                        .evo-btn-remove:hover { color:#b91c1c; text-decoration:underline; }
                    </style>

                </div>

                <div class="evo-box">
                    <h3>Konfiguracja selektorów (Repeater)</h3>
                    <div class="evo-info-box">
                        <span class="dashicons dashicons-info"></span>
                        <div>Zdefiniuj zachowanie kursora dla konkretnych elementów na stronie.</div>
                    </div>

                    <div id="evo-cursor-repeater-container">
                        <?php foreach ($elements as $index => $el): ?>
                        <div class="evo-cursor-row">
                            <div class="evo-cursor-row-header">
                                <div class="evo-cursor-row-title">Selektor #<?php echo $index + 1; ?></div>
                                <button type="button" class="evo-btn-remove" onclick="this.closest('.evo-cursor-row').remove()">
                                    <span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;"></span> Usuń
                                </button>
                            </div>
                            <div class="evo-cursor-grid">
                                <div><label>Selektor CSS</label><input type="text" name="evk_cursor[elements][<?php echo $index; ?>][selector]" value="<?php echo esc_attr($el['selector']); ?>" placeholder="np. .btn, a, select"></div>
                                <div><label>Rozmiar (px)</label><input type="number" name="evk_cursor[elements][<?php echo $index; ?>][size]" value="<?php echo esc_attr($el['size']); ?>"></div>
                                <div><label>Tekst (HTML dozwolony)</label><input type="text" name="evk_cursor[elements][<?php echo $index; ?>][text]" value="<?php echo esc_attr($el['text']); ?>"></div>
                                <div><label>Kolor tła (CSS)</label><input type="text" name="evk_cursor[elements][<?php echo $index; ?>][backgroundColor]" value="<?php echo esc_attr($el['backgroundColor']); ?>" placeholder="rgba(255,255,255,1)"></div>
                                <div><label>Kolor tekstu (CSS)</label><input type="text" name="evk_cursor[elements][<?php echo $index; ?>][textColor]" value="<?php echo esc_attr($el['textColor']); ?>"></div>
                                <div><label>Backdrop Filter</label><input type="text" name="evk_cursor[elements][<?php echo $index; ?>][cursorBackdropFilter]" value="<?php echo esc_attr($el['cursorBackdropFilter']); ?>" placeholder="blur(10px)"></div>
                                <div>
                                    <label>Blend Mode Kursora</label>
                                    <select name="evk_cursor[elements][<?php echo $index; ?>][cursorBlendMode]">
                                        <?php foreach ($blend_modes as $mode): ?>
                                        <option value="<?php echo $mode; ?>" <?php selected($el['cursorBlendMode'], $mode); ?>><?php echo $mode; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label>Blend Mode Tekstu</label>
                                    <select name="evk_cursor[elements][<?php echo $index; ?>][textBlendMode]">
                                        <?php foreach ($blend_modes as $mode): ?>
                                        <option value="<?php echo $mode; ?>" <?php selected($el['textBlendMode'], $mode); ?>><?php echo $mode; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div><label class="checkbox-label"><input type="checkbox" name="evk_cursor[elements][<?php echo $index; ?>][arrows]" value="1" <?php checked(!empty($el['arrows'])); ?>> Pokaż strzałki (↔)</label></div>
                                <div><label class="checkbox-label"><input type="checkbox" name="evk_cursor[elements][<?php echo $index; ?>][invert]" value="1" <?php checked(!empty($el['invert'])); ?>> Odwróć w Dark Mode</label></div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="button" onclick="evkAddCursorRow()">+ Dodaj nowy selektor</button>

                
                </div>

<div class="evo-save-bar">
                    <?php submit_button('Zapisz konfigurację kursora', 'primary', 'submit', false); ?>
                </div>
            </form>

            <script type="text/template" id="evo-cursor-row-template">
                <div class="evo-cursor-row">
                    <div class="evo-cursor-row-header">
                        <div class="evo-cursor-row-title">Nowy Selektor</div>
                        <button type="button" class="evo-btn-remove" onclick="this.closest('.evo-cursor-row').remove()">
                            <span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;"></span> Usuń
                        </button>
                    </div>
                    <div class="evo-cursor-grid">
                        <div><label>Selektor CSS</label><input type="text" name="evk_cursor[elements][{INDEX}][selector]" value="" placeholder="np. .btn, a, select"></div>
                        <div><label>Rozmiar (px)</label><input type="number" name="evk_cursor[elements][{INDEX}][size]" value="64"></div>
                        <div><label>Tekst (HTML dozwolony)</label><input type="text" name="evk_cursor[elements][{INDEX}][text]" value=""></div>
                        <div><label>Kolor tła (CSS)</label><input type="text" name="evk_cursor[elements][{INDEX}][backgroundColor]" value="rgba(255,255,255,1)"></div>
                        <div><label>Kolor tekstu (CSS)</label><input type="text" name="evk_cursor[elements][{INDEX}][textColor]" value="white"></div>
                        <div><label>Backdrop Filter</label><input type="text" name="evk_cursor[elements][{INDEX}][cursorBackdropFilter]" value="blur(0px)"></div>
                        <div>
                            <label>Blend Mode Kursora</label>
                            <select name="evk_cursor[elements][{INDEX}][cursorBlendMode]">
                                <?php foreach ($blend_modes as $mode): ?>
                                <option value="<?php echo $mode; ?>" <?php selected('difference', $mode); ?>><?php echo $mode; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Blend Mode Tekstu</label>
                            <select name="evk_cursor[elements][{INDEX}][textBlendMode]">
                                <?php foreach ($blend_modes as $mode): ?>
                                <option value="<?php echo $mode; ?>" <?php selected('exclusion', $mode); ?>><?php echo $mode; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div><label class="checkbox-label"><input type="checkbox" name="evk_cursor[elements][{INDEX}][arrows]" value="1"> Pokaż strzałki (↔)</label></div>
                        <div><label class="checkbox-label"><input type="checkbox" name="evk_cursor[elements][{INDEX}][invert]" value="1"> Odwróć w Dark Mode</label></div>
                    </div>
                </div>
            </script>
