<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: a11y
 */
?>
<?php $a11y = evk_a11y_get_settings(); ?>

            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_a11y'); ?>

                <!-- STATUS -->
                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($a11y['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons dashicons-universal-access" style="font-size:24px;width:24px;height:24px;line-height:1;"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Moduł Dostępności: <?php echo !empty($a11y['enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
                        <p>Widget dostępności WCAG — czytnik ekranu, kontrast, czcionki, sterowanie głosem.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($a11y['enabled']) ? 'Włączony' : 'Wyłączony'; ?></span>
                        <label class="evo-toggle">
                            <input type="checkbox" data-option="evk_a11y" data-field="enabled" value="1" <?php checked(!empty($a11y['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <!-- WŁĄCZONE FUNKCJE -->
                <p class="evo-section-title">Włączone funkcje</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:10px;margin-bottom:24px;">
                    <?php
                    $features = [
                        'enable_high_contrast'    => 'Wysoki Kontrast',
                        'enable_bigger_text'       => 'Rozmiar Tekstu',
                        'enable_text_spacing'      => 'Odstępy w Tekście',
                        'enable_pause_animations'  => 'Zatrzymaj Animacje',
                        'enable_hide_images'       => 'Ukryj Obrazki',
                        'enable_dyslexia_font'     => 'Czcionka dla Dyslektyków',
                        'enable_bigger_cursor'     => 'Większy Kursor',
                        'enable_line_height'       => 'Wysokość Linii',
                        'enable_text_align'        => 'Wyrównanie Tekstu',
                        'enable_screen_reader'     => 'Czytnik Ekranu',
                        'enable_voice_control'     => 'Sterowanie Głosem',
                        'enable_font_selection'    => 'Wybór Czcionki',
                        'enable_color_filter'      => 'Filtr Kolorów',
                        'enable_saturation'        => 'Nasycenie Kolorów',
                    ];
                    foreach ($features as $key => $label): ?>
                    <label style="display:flex;align-items:center;gap:9px;background:#f8fafc;border:1px solid #d7dde7;border-radius:8px;padding:10px 14px;font-size:13px;font-weight:500;cursor:pointer;">
                        <input type="checkbox" name="evk_a11y[<?php echo $key; ?>]" value="1" <?php checked(!empty($a11y[$key])); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                    <?php endforeach; ?>
                </div>

                <!-- POZYCJA -->
                <hr class="evo-divider">
                <p class="evo-section-title">Pozycja przycisku</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:20px;">
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Strona</label>
                        <select name="evk_a11y[position_side]">
                            <option value="right" <?php selected($a11y['position_side'], 'right'); ?>>Prawa (right)</option>
                            <option value="left"  <?php selected($a11y['position_side'], 'left'); ?>>Lewa (left)</option>
                        </select>
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Odległość od prawej</label>
                        <input type="text" name="evk_a11y[position_right]" value="<?php echo esc_attr($a11y['position_right']); ?>" placeholder="20px" style="max-width:100px;">
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Odległość od lewej</label>
                        <input type="text" name="evk_a11y[position_left]" value="<?php echo esc_attr($a11y['position_left']); ?>" placeholder="20px" style="max-width:100px;">
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Odległość od dołu</label>
                        <input type="text" name="evk_a11y[position_bottom]" value="<?php echo esc_attr($a11y['position_bottom']); ?>" placeholder="20px" style="max-width:100px;">
                    </div>
                </div>

                <!-- KOLORY -->
                <hr class="evo-divider">
                <p class="evo-section-title">Kolory</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:20px;">
                    <?php
                    $color_fields = [
                        'color_primary'     => 'Kolor główny (przycisk, nagłówek, aktywne)',
                        'color_secondary'   => 'Kolor wtórny (ikona przycisku, tekst nagłówka)',
                        'color_option_bg'   => 'Tło opcji',
                        'color_option_text' => 'Tekst opcji',
                        'color_option_icon' => 'Ikony opcji',
                    ];
                    foreach ($color_fields as $key => $label): ?>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label><?php echo esc_html($label); ?></label>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <input type="color" value="<?php echo esc_attr($a11y[$key]); ?>"
                                oninput="this.nextElementSibling.value=this.value"
                                style="width:40px;height:32px;border:1px solid #d1d5db;border-radius:5px;cursor:pointer;padding:2px;">
                            <input type="text" name="evk_a11y[<?php echo $key; ?>]"
                                value="<?php echo esc_attr($a11y[$key]); ?>"
                                oninput="var v=this.value;if(/^#[0-9a-fA-F]{3,6}$/.test(v))this.previousElementSibling.value=v;"
                                style="width:90px;font-family:monospace;font-size:12px;">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- WYMIARY -->
                <hr class="evo-divider">
                <p class="evo-section-title">Wymiary</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:14px;margin-bottom:20px;">
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Szerokość menu</label>
                        <input type="text" name="evk_a11y[widget_width]" value="<?php echo esc_attr($a11y['widget_width']); ?>" placeholder="450px" style="max-width:100px;">
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Rozmiar przycisku</label>
                        <input type="text" name="evk_a11y[button_size]" value="<?php echo esc_attr($a11y['button_size']); ?>" placeholder="50px" style="max-width:100px;">
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Zaokrąglenie przycisku</label>
                        <input type="text" name="evk_a11y[button_border_radius]" value="<?php echo esc_attr($a11y['button_border_radius']); ?>" placeholder="100px" style="max-width:100px;">
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Rozmiar ikony przycisku</label>
                        <input type="text" name="evk_a11y[button_icon_size]" value="<?php echo esc_attr($a11y['button_icon_size']); ?>" placeholder="40px" style="max-width:100px;">
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Kolumny siatki (CSS)</label>
                        <input type="text" name="evk_a11y[grid_columns]" value="<?php echo esc_attr($a11y['grid_columns']); ?>" placeholder="1fr 1fr" style="max-width:120px;">
                        <div class="evo-desc">np. <code>1fr 1fr</code> lub <code>1fr 1fr 1fr</code></div>
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Odstęp siatki</label>
                        <input type="text" name="evk_a11y[grid_gap]" value="<?php echo esc_attr($a11y['grid_gap']); ?>" placeholder="5px" style="max-width:80px;">
                    </div>
                </div>

                <!-- WYKLUCZONE STRONY -->
                <hr class="evo-divider">
                <p class="evo-section-title">Wykluczenia stron</p>
                <div class="evo-info-box">
                    <span class="dashicons dashicons-info"></span>
                    <div>Strony, na których widget dostępności <strong>nie zostanie załadowany</strong>. Wpisz jedną ścieżkę URL na linię (np. <code>/kontakt</code>, <code>/koszyk</code>, <code>/en/</code>). Dopasowanie częściowe — <code>/konto</code> wyklucza <code>/konto</code>, <code>/konto/zamowienia</code> itd.</div>
                </div>
                <div class="evo-field" style="margin-bottom:24px;">
                    <label>Wykluczone ścieżki URL</label>
                    <textarea name="evk_a11y[exclude_urls]" rows="5" style="max-width:480px;font-family:monospace;font-size:12px;" placeholder="/kontakt&#10;/koszyk&#10;/konto"><?php echo esc_textarea($a11y['exclude_urls'] ?? ''); ?></textarea>
                    <div class="evo-desc">Jedna ścieżka na linię. Dopasowanie do <code>$_SERVER['REQUEST_URI']</code>.</div>
                </div>

                <!-- WYKLUCZENIA CSS -->
                <hr class="evo-divider">
                <p class="evo-section-title">Wykluczenia z filtrów CSS</p>
                <div class="evo-info-box">
                    <span class="dashicons dashicons-info"></span>
                    <div>Selektory CSS wykluczone z działania filtrów kolorów i kontrastu. Jeden selektor na linię. Widget jest zawsze wykluczony automatycznie.</div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;margin-bottom:20px;">
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Filtry kolorów i saturacji</label>
                        <textarea name="evk_a11y[filter_exclusions]" rows="5" style="max-width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea($a11y['filter_exclusions']); ?></textarea>
                        <div class="evo-desc">Wykluczenia dla: filtrów protanopia, deuteranopia, tritanopia, grayscale, saturation.</div>
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Wysoki kontrast</label>
                        <textarea name="evk_a11y[contrast_exclusions]" rows="5" style="max-width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea($a11y['contrast_exclusions']); ?></textarea>
                        <div class="evo-desc">Wykluczenia dla: high contrast medium/high/ultra.</div>
                    </div>
                    <div class="evo-field" style="margin-bottom:0;">
                        <label>Saturacja (osobna lista)</label>
                        <textarea name="evk_a11y[saturation_exclusions]" rows="5" style="max-width:100%;font-family:monospace;font-size:12px;"><?php echo esc_textarea($a11y['saturation_exclusions']); ?></textarea>
                        <div class="evo-desc">Wykluczenia dla: saturate low/high/none.</div>
                    </div>
                </div>

                <div class="evo-info-box" style="border-color:#86efac;background:#f0fdf4;">
                    <span class="dashicons dashicons-info" style="color:#16a34a;"></span>
                    <div>Podgląd generowanego CSS możesz sprawdzić w DevTools (zakładka Sources → evk-accessibility-css-inline). Zmiany w wykluczeniach wchodzą w życie po zapisaniu.</div>
                </div>

                <div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia dostępności', 'primary', 'submit', false); ?>
                </div>
            </form>
