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
                        <span class="dashicons dashicons-universal-access evo-ico-lg"></span>
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

                <!-- POLITYKA RUCHU -->
                <p class="evo-section-title">Ruch na stronie</p>
                <div class="evo-field">
                    <label class="checkbox-label">
                        <input type="checkbox" name="evk_motion[respect_reduced]" value="1"
                               <?php checked(evk_motion_respect_reduced()); ?>>
                        Szanuj systemowe ograniczenie animacji
                    </label>
                    <div class="evo-desc evo-prose">
                        Gdy odwiedzający ma w systemie włączone <code>prefers-reduced-motion</code>,
                        wszystkie efekty Evoke ONE zatrzymują ruch, ale <strong>zachowują stan
                        końcowy</strong> — nic nie znika i nic nie zostaje niewidoczne.
                        Karty układają się jedna pod drugą, marquee stoi, tekst Scroll Reading
                        dostaje od razu kolor docelowy, tło Wave renderuje jedną klatkę,
                        parallax nie przesuwa, płynne przewijanie i własny kursor się nie włączają,
                        a menu otwiera się natychmiast zamiast rozwijać.<br><br>
                        To ustawienie działa niezależnie od tego, czy widżet dostępności powyżej
                        jest włączony — dlatego ma osobną opcję, a nie pole w jego konfiguracji.
                    </div>
                </div>

                <!-- WŁĄCZONE FUNKCJE -->
                <p class="evo-section-title">Włączone funkcje</p>
                <div class="evo-grid evo-mb-lg" style="--evo-col:200px;--evo-gap:10px">
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
                    <label class="evo-choice">
                        <input type="checkbox" name="evk_a11y[<?php echo $key; ?>]" value="1" <?php checked(!empty($a11y[$key])); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                    <?php endforeach; ?>
                </div>

                <!-- POZYCJA -->
                <hr class="evo-divider">
                <p class="evo-section-title">Pozycja przycisku</p>
                <div class="evo-grid evo-mb" style="--evo-col:180px">
                    <div class="evo-field">
                        <label>Strona</label>
                        <select name="evk_a11y[position_side]">
                            <option value="right" <?php selected($a11y['position_side'], 'right'); ?>>Prawa (right)</option>
                            <option value="left"  <?php selected($a11y['position_side'], 'left'); ?>>Lewa (left)</option>
                        </select>
                    </div>
                    <div class="evo-field">
                        <label>Odległość od prawej</label>
                        <input type="text" name="evk_a11y[position_right]" value="<?php echo esc_attr($a11y['position_right']); ?>" placeholder="20px" class="evo-w-xs">
                    </div>
                    <div class="evo-field">
                        <label>Odległość od lewej</label>
                        <input type="text" name="evk_a11y[position_left]" value="<?php echo esc_attr($a11y['position_left']); ?>" placeholder="20px" class="evo-w-xs">
                    </div>
                    <div class="evo-field">
                        <label>Odległość od dołu</label>
                        <input type="text" name="evk_a11y[position_bottom]" value="<?php echo esc_attr($a11y['position_bottom']); ?>" placeholder="20px" class="evo-w-xs">
                    </div>
                </div>

                <!-- KOLORY -->
                <hr class="evo-divider">
                <p class="evo-section-title">Kolory</p>
                <div class="evo-grid evo-mb" style="--evo-col:200px">
                    <?php
                    $color_fields = [
                        'color_primary'     => 'Kolor główny (przycisk, nagłówek, aktywne)',
                        'color_secondary'   => 'Kolor wtórny (ikona przycisku, tekst nagłówka)',
                        'color_option_bg'   => 'Tło opcji',
                        'color_option_text' => 'Tekst opcji',
                        'color_option_icon' => 'Ikony opcji',
                    ];
                    foreach ($color_fields as $key => $label): ?>
                    <div class="evo-field">
                        <label><?php echo esc_html($label); ?></label>
                        <div class="evo-color-row">
                            <input type="color" value="<?php echo esc_attr($a11y[$key]); ?>"
                                oninput="this.nextElementSibling.value=this.value">
                            <input type="text" name="evk_a11y[<?php echo $key; ?>]"
                                value="<?php echo esc_attr($a11y[$key]); ?>"
                                oninput="var v=this.value;if(/^#[0-9a-fA-F]{3,6}$/.test(v))this.previousElementSibling.value=v;"
                                class="evo-mono evo-w-hex">
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- WYMIARY -->
                <hr class="evo-divider">
                <p class="evo-section-title">Wymiary</p>
                <div class="evo-grid evo-mb" style="--evo-col:180px">
                    <div class="evo-field">
                        <label>Szerokość menu</label>
                        <input type="text" name="evk_a11y[widget_width]" value="<?php echo esc_attr($a11y['widget_width']); ?>" placeholder="450px" class="evo-w-xs">
                    </div>
                    <div class="evo-field">
                        <label>Rozmiar przycisku</label>
                        <input type="text" name="evk_a11y[button_size]" value="<?php echo esc_attr($a11y['button_size']); ?>" placeholder="50px" class="evo-w-xs">
                    </div>
                    <div class="evo-field">
                        <label>Zaokrąglenie przycisku</label>
                        <input type="text" name="evk_a11y[button_border_radius]" value="<?php echo esc_attr($a11y['button_border_radius']); ?>" placeholder="100px" class="evo-w-xs">
                    </div>
                    <div class="evo-field">
                        <label>Rozmiar ikony przycisku</label>
                        <input type="text" name="evk_a11y[button_icon_size]" value="<?php echo esc_attr($a11y['button_icon_size']); ?>" placeholder="40px" class="evo-w-xs">
                    </div>
                    <div class="evo-field">
                        <label>Kolumny siatki (CSS)</label>
                        <input type="text" name="evk_a11y[grid_columns]" value="<?php echo esc_attr($a11y['grid_columns']); ?>" placeholder="1fr 1fr" class="evo-w-sm">
                        <div class="evo-desc">np. <code>1fr 1fr</code> lub <code>1fr 1fr 1fr</code></div>
                    </div>
                    <div class="evo-field">
                        <label>Odstęp siatki</label>
                        <input type="text" name="evk_a11y[grid_gap]" value="<?php echo esc_attr($a11y['grid_gap']); ?>" placeholder="5px" class="evo-w-xs">
                    </div>
                </div>

                <!-- WYKLUCZONE STRONY -->
                <hr class="evo-divider">
                <p class="evo-section-title">Wykluczenia stron</p>
                <div class="evo-info-box">
                    <span class="dashicons dashicons-info"></span>
                    <div>Strony, na których widget dostępności <strong>nie zostanie załadowany</strong>. Wpisz jedną ścieżkę URL na linię (np. <code>/kontakt</code>, <code>/koszyk</code>, <code>/en/</code>). Dopasowanie częściowe — <code>/konto</code> wyklucza <code>/konto</code>, <code>/konto/zamowienia</code> itd.</div>
                </div>
                <div class="evo-field evo-mb-lg">
                    <label>Wykluczone ścieżki URL</label>
                    <textarea name="evk_a11y[exclude_urls]" rows="5" class="evo-mono evo-code-area" style="max-width:480px" placeholder="/kontakt&#10;/koszyk&#10;/konto"><?php echo esc_textarea($a11y['exclude_urls'] ?? ''); ?></textarea>
                    <div class="evo-desc">Jedna ścieżka na linię. Dopasowanie do <code>$_SERVER['REQUEST_URI']</code>.</div>
                </div>

                <!-- WYKLUCZENIA CSS -->
                <hr class="evo-divider">
                <p class="evo-section-title">Wykluczenia z filtrów CSS</p>
                <div class="evo-info-box">
                    <span class="dashicons dashicons-info"></span>
                    <div>Selektory CSS wykluczone z działania filtrów kolorów i kontrastu. Jeden selektor na linię. Widget jest zawsze wykluczony automatycznie.</div>
                </div>

                <div class="evo-grid evo-mb" style="--evo-col:200px;--evo-gap:16px">
                    <div class="evo-field">
                        <label>Filtry kolorów i saturacji</label>
                        <textarea name="evk_a11y[filter_exclusions]" rows="5" class="evo-mono evo-code-area evo-w-full"><?php echo esc_textarea($a11y['filter_exclusions']); ?></textarea>
                        <div class="evo-desc">Wykluczenia dla: filtrów protanopia, deuteranopia, tritanopia, grayscale, saturation.</div>
                    </div>
                    <div class="evo-field">
                        <label>Wysoki kontrast</label>
                        <textarea name="evk_a11y[contrast_exclusions]" rows="5" class="evo-mono evo-code-area evo-w-full"><?php echo esc_textarea($a11y['contrast_exclusions']); ?></textarea>
                        <div class="evo-desc">Wykluczenia dla: high contrast medium/high/ultra.</div>
                    </div>
                    <div class="evo-field">
                        <label>Saturacja (osobna lista)</label>
                        <textarea name="evk_a11y[saturation_exclusions]" rows="5" class="evo-mono evo-code-area evo-w-full"><?php echo esc_textarea($a11y['saturation_exclusions']); ?></textarea>
                        <div class="evo-desc">Wykluczenia dla: saturate low/high/none.</div>
                    </div>
                </div>

                <div class="evo-info-box is-ok">
                    <span class="dashicons dashicons-info"></span>
                    <div>Podgląd generowanego CSS możesz sprawdzić w DevTools (zakładka Sources → evk-accessibility-css-inline). Zmiany w wykluczeniach wchodzą w życie po zapisaniu.</div>
                </div>

                <div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia dostępności', 'primary', 'submit', false); ?>
                </div>
            </form>
