<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: darkmode
 */
?>
<?php $dm = EVK_DarkMode::get_instance()->get_settings(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_darkmode'); ?>

                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($dm['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons <?php echo !empty($dm['enabled']) ? 'dashicons-visibility' : 'dashicons-hidden'; ?>"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Moduł Dark Mode: <?php echo !empty($dm['enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
                        <p>Przejścia CSS i efekty View Transition API dla przełączania motywu.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($dm['enabled']) ? 'Włączony' : 'Wyłączony'; ?></span>
                        <label class="evo-toggle">
                            <input type="checkbox" data-option="evk_darkmode" data-field="enabled" value="1" <?php checked(!empty($dm['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <p class="evo-section-title">Przełącznik motywu</p>
                <div class="evo-field">
                    <label>Selektor CSS przycisku przełączającego</label>
                    <input type="text" name="evk_darkmode[toggle_selector]" value="<?php echo esc_attr($dm['toggle_selector']); ?>" placeholder=".brxe-toggle-mode" style="max-width:340px;">
                    <div class="evo-desc">Dowolny selektor CSS — klasa, ID lub atrybut. Domyślnie: <code>.brxe-toggle-mode</code></div>
                </div>

                <hr class="evo-divider">
                <p class="evo-section-title">Przejścia przy nawigacji między stronami</p>
                <div class="evo-info-box">
                    <span class="dashicons dashicons-info"></span>
                    <div>Animacja podczas przechodzenia między podstronami. Wymaga Chrome/Edge 111+ z View Transition API.</div>
                </div>

                <div class="evo-field">
                    <label style="display:flex;align-items:center;gap:10px;">
                        <input type="checkbox" name="evk_darkmode[wipe_enabled]" value="1" <?php checked(!empty($dm['wipe_enabled'])); ?>>
                        Włącz przejście przy nawigacji
                    </label>
                </div>

                <?php
                $nav_types = [
                    'wipe'       => ['Zasłona', 'Kolorowa zasłona przesuwa się przez ekran'],
                    'fade'       => ['Fade', 'Stara strona zanika, nowa pojawia się'],
                    'zoom-out'   => ['Zoom Out', 'Stara strona oddala się, nowa przylatuje'],
                    'zoom-in'    => ['Zoom In', 'Stara strona przybliża się, nowa wyjeżdża'],
                    'slide-push' => ['Slide Push', 'Nowa strona wypycha starą w lewo'],
                    'iris'       => ['Iris', 'Nowa strona otwiera się kołem ze środka'],
                    'nav-ripple' => ['Ripple od kliknięcia', 'Fala rozchodzi się od miejsca kliknięcia'],
                ];
                $cur_type = $dm['nav_trans_type'] ?? 'wipe';
                ?>
                <div class="evo-field">
                    <label>Typ przejścia</label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:8px;margin-top:6px;">
                        <?php foreach ($nav_types as $val => [$name, $desc]): ?>
                        <label style="display:flex;align-items:flex-start;gap:8px;padding:10px 12px;border:2px solid <?php echo $cur_type === $val ? '#2563eb' : '#e2e8f0'; ?>;border-radius:8px;cursor:pointer;background:<?php echo $cur_type === $val ? '#eff6ff' : '#fff'; ?>;">
                            <input type="radio" name="evk_darkmode[nav_trans_type]" value="<?php echo $val; ?>" <?php checked($cur_type, $val); ?> style="margin-top:2px;flex-shrink:0;">
                            <span>
                                <strong style="display:block;font-size:13px;"><?php echo $name; ?></strong>
                                <span style="font-size:11px;color:#64748b;line-height:1.3;"><?php echo $desc; ?></span>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;margin-top:8px;">
                    <div class="evo-field">
                        <label>Czas trwania (s)</label>
                        <input type="number" name="evk_darkmode[wipe_duration]" value="<?php echo esc_attr($dm['wipe_duration']); ?>" min="0.3" max="5" step="0.1" style="width:80px;">
                    </div>
                    <div class="evo-field">
                        <label>Easing</label>
                        <select name="evk_darkmode[wipe_easing]">
                            <?php foreach (['ease', 'ease-in', 'ease-out', 'ease-in-out', 'linear', 'cubic-bezier(0.4, 0, 0.2, 1)'] as $e): ?>
                            <option value="<?php echo $e; ?>" <?php selected($dm['wipe_easing'], $e); ?>><?php echo $e; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <details style="margin-top:12px;border:1px solid #e2e8f0;border-radius:8px;">
                    <summary style="padding:10px 14px;cursor:pointer;font-weight:600;font-size:13px;background:#f8fafc;border-radius:8px;user-select:none;">Opcje szczegółowe (Zasłona / Ripple)</summary>
                    <div style="padding:16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;">
                        <div class="evo-field">
                            <label>Kolor zasłony</label>
                            <div class="evo-color-row">
                                <button type="button" class="evo-color-swatch" id="wipe-color-swatch"
                                    style="background:<?php echo esc_attr($dm['wipe_color']); ?>;"
                                    onclick="document.getElementById('wipe_color_input').click();">
                                </button>
                                <input type="color" id="wipe_color_input" name="evk_darkmode[wipe_color]"
                                    value="<?php echo esc_attr($dm['wipe_color']); ?>"
                                    style="display:none;"
                                    oninput="document.getElementById('wipe-color-swatch').style.background=this.value;document.getElementById('wipe-color-text').value=this.value;">
                                <input type="text" id="wipe-color-text" value="<?php echo esc_attr($dm['wipe_color']); ?>"
                                    style="width:90px;font-family:monospace;"
                                    oninput="var v=this.value;if(/^#[0-9a-fA-F]{6}$/.test(v)){document.getElementById('wipe_color_input').value=v;document.getElementById('wipe-color-swatch').style.background=v;}">
                            </div>
                        </div>
                        <div class="evo-field">
                            <label>Kierunek zasłony</label>
                            <select name="evk_darkmode[wipe_direction]">
                                <?php foreach (['to bottom' => 'Z góry na dół ↓', 'to top' => 'Z dołu do góry ↑', 'to right' => 'Od lewej do prawej →', 'to left' => 'Od prawej do lewej ←'] as $val => $label): ?>
                                <option value="<?php echo esc_attr($val); ?>" <?php selected($dm['wipe_direction'], $val); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="evo-field">
                            <label>Rozmycie krawędzi zasłony</label>
                            <input type="number" name="evk_darkmode[wipe_blur]" value="<?php echo esc_attr($dm['wipe_blur']); ?>" min="0" max="50" step="5" style="width:72px;">
                            <div class="evo-desc">0 = ostra, 50 = miękka</div>
                        </div>
                        <div class="evo-field">
                            <label>Kolor Ripple</label>
                            <div class="evo-color-row">
                                <button type="button" class="evo-color-swatch" id="nav-ripple-color-swatch"
                                    style="background:<?php echo esc_attr($dm['nav_ripple_color'] ?? '#ffffff'); ?>;"
                                    onclick="document.getElementById('nav_ripple_color_input').click();">
                                </button>
                                <input type="color" id="nav_ripple_color_input" name="evk_darkmode[nav_ripple_color]"
                                    value="<?php echo esc_attr($dm['nav_ripple_color'] ?? '#ffffff'); ?>"
                                    style="display:none;"
                                    oninput="document.getElementById('nav-ripple-color-swatch').style.background=this.value;document.getElementById('nav-ripple-color-text').value=this.value;">
                                <input type="text" id="nav-ripple-color-text" value="<?php echo esc_attr($dm['nav_ripple_color'] ?? '#ffffff'); ?>"
                                    style="width:90px;font-family:monospace;"
                                    oninput="var v=this.value;if(/^#[0-9a-fA-F]{6}$/.test(v)){document.getElementById('nav_ripple_color_input').value=v;document.getElementById('nav-ripple-color-swatch').style.background=v;}">
                            </div>
                        </div>
                        <div class="evo-field">
                            <label>Rozmycie Ripple (px)</label>
                            <input type="number" name="evk_darkmode[nav_ripple_blur]" value="<?php echo esc_attr($dm['nav_ripple_blur'] ?? 20); ?>" min="0" max="100" step="5" style="width:72px;">
                        </div>
                    </div>
                </details>

                <hr class="evo-divider">
                <p class="evo-section-title">Przejścia CSS przy zmianie motywu (globalne)</p>
                <div class="evo-info-box">
                    <span class="dashicons dashicons-info"></span>
                    <div>Przejścia dla głównych kontenerów strony (<code>body</code>, <code>section</code>, etc.).</div>
                </div>
                <div class="evo-field">
                    <label>Selektory (jeden na linię)</label>
                    <textarea name="evk_darkmode[global_selectors]" rows="4"><?php echo esc_textarea($dm['global_selectors']); ?></textarea>
                </div>
                <div class="evo-field">
                    <label>Właściwości CSS (jeden na linię)</label>
                    <textarea name="evk_darkmode[global_properties]" rows="4"><?php echo esc_textarea($dm['global_properties']); ?></textarea>
                </div>
                <div style="display:flex;gap:24px;flex-wrap:wrap;">
                    <div class="evo-field">
                        <label>Czas trwania (s)</label>
                        <input type="number" name="evk_darkmode[global_duration]" value="<?php echo esc_attr($dm['global_duration']); ?>" min="0.1" max="5" step="0.1" style="width:80px;">
                    </div>
                    <div class="evo-field">
                        <label>Easing</label>
                        <select name="evk_darkmode[global_easing]">
                            <?php foreach (['ease', 'ease-in', 'ease-out', 'ease-in-out', 'linear'] as $e): ?>
                            <option value="<?php echo $e; ?>" <?php selected($dm['global_easing'], $e); ?>><?php echo $e; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <hr class="evo-divider">
                <p class="evo-section-title">Elementy Bricks Builder</p>
                <div class="evo-field">
                    <label style="display:flex;align-items:center;gap:10px;">
                        <input type="checkbox" name="evk_darkmode[bricks_enabled]" value="1" <?php checked(!empty($dm['bricks_enabled'])); ?>>
                        Włącz przejścia dla elementów Bricks
                    </label>
                </div>
                <div class="evo-field">
                    <label>Selektory Bricks (jeden na linię, bez prefiksu <code>[data-brx-theme]</code>)</label>
                    <textarea name="evk_darkmode[bricks_selectors]" rows="6"><?php echo esc_textarea($dm['bricks_selectors']); ?></textarea>
                </div>
                <div class="evo-field">
                    <label>Właściwości CSS</label>
                    <textarea name="evk_darkmode[bricks_properties]" rows="3"><?php echo esc_textarea($dm['bricks_properties']); ?></textarea>
                </div>
                <div style="display:flex;gap:24px;flex-wrap:wrap;">
                    <div class="evo-field">
                        <label>Czas trwania (s)</label>
                        <input type="number" name="evk_darkmode[bricks_duration]" value="<?php echo esc_attr($dm['bricks_duration']); ?>" min="0.1" max="5" step="0.1" style="width:80px;">
                    </div>
                    <div class="evo-field">
                        <label>Easing</label>
                        <input type="text" name="evk_darkmode[bricks_easing]" value="<?php echo esc_attr($dm['bricks_easing']); ?>" style="width:280px;" placeholder="np. cubic-bezier(0.33, 1, 0.68, 1)">
                    </div>
                </div>

                <hr class="evo-divider">
                <p class="evo-section-title">Przejście logo (View Transition)</p>
                <div class="evo-field">
                    <label style="display:flex;align-items:center;gap:10px;">
                        <input type="checkbox" name="evk_darkmode[logo_enabled]" value="1" <?php checked(!empty($dm['logo_enabled'])); ?>>
                        Włącz animację przejścia logo
                    </label>
                </div>
                <div style="display:flex;gap:24px;flex-wrap:wrap;">
                    <div class="evo-field">
                        <label>Klasa logo jasnego</label>
                        <input type="text" name="evk_darkmode[logo_light_class]" value="<?php echo esc_attr($dm['logo_light_class']); ?>" style="width:160px;">
                    </div>
                    <div class="evo-field">
                        <label>Klasa logo ciemnego</label>
                        <input type="text" name="evk_darkmode[logo_dark_class]" value="<?php echo esc_attr($dm['logo_dark_class']); ?>" style="width:160px;">
                    </div>
                    <div class="evo-field">
                        <label>Czas animacji (s)</label>
                        <input type="number" name="evk_darkmode[logo_duration]" value="<?php echo esc_attr($dm['logo_duration']); ?>" min="0.1" max="5" step="0.1" style="width:80px;">
                    </div>
                </div>

                <hr class="evo-divider">
                <p class="evo-section-title">Efekt Ripple (przełączanie motywu)</p>
                <div class="evo-info-box">
                    <span class="dashicons dashicons-info"></span>
                    <div>Fala rozchodząca się od przycisku przy zmianie motywu. Wymaga Chrome/Edge 111+.</div>
                </div>
                <div class="evo-field">
                    <label style="display:flex;align-items:center;gap:10px;">
                        <input type="checkbox" name="evk_darkmode[ripple_enabled]" value="1" <?php checked(!empty($dm['ripple_enabled'])); ?>>
                        Włącz efekt ripple
                    </label>
                </div>
                <div style="display:flex;gap:24px;flex-wrap:wrap;">
                    <div class="evo-field">
                        <label>Czas trwania (ms)</label>
                        <input type="number" name="evk_darkmode[ripple_duration]" value="<?php echo esc_attr($dm['ripple_duration']); ?>" min="200" max="5000" step="100" style="width:100px;">
                    </div>
                    <div class="evo-field">
                        <label>Rozmycie krawędzi (px)</label>
                        <input type="number" name="evk_darkmode[ripple_blur]" value="<?php echo esc_attr($dm['ripple_blur']); ?>" min="0" max="100" step="5" style="width:80px;">
                    </div>
                    <div class="evo-field">
                        <label>Easing</label>
                        <input type="text" name="evk_darkmode[ripple_easing]" value="<?php echo esc_attr($dm['ripple_easing']); ?>" style="width:280px;">
                    </div>
                </div>

                <div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia Dark Mode', 'primary', 'submit', false); ?>
                </div>

                <hr class="evo-divider">
                <p class="evo-section-title">Przejścia elementów lista → wpis (View Transition)</p>
                <div class="evo-info-box">
                    <span class="dashicons dashicons-info"></span>
                    <div>Tytuł i obrazek "przefruwają" z listy wpisów do strony wpisu. Wymaga Chrome/Edge 111+. Potrzebne włączone przejście przy nawigacji powyżej.</div>
                </div>

                <div class="evo-field">
                    <label style="display:flex;align-items:center;gap:10px;">
                        <input type="checkbox" name="evk_darkmode[post_trans_enabled]" value="1" <?php checked(!empty($dm['post_trans_enabled'])); ?>>
                        Włącz przejścia lista → wpis
                    </label>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:8px;">
                    <div class="evo-field">
                        <label>Klasa tytułu <span style="color:#94a3b8;font-weight:400;font-size:11px;">na liście (bez kropki)</span></label>
                        <input type="text" name="evk_darkmode[post_trans_title_class]" value="<?php echo esc_attr($dm['post_trans_title_class']); ?>" placeholder="post-card-title" style="width:100%;">
                        <div class="evo-desc">Klasa elementu Bricks z tytułem w query loop. Kilka — oddziel przecinkami.</div>
                    </div>
                    <div class="evo-field">
                        <label>Klasa obrazka <span style="color:#94a3b8;font-weight:400;font-size:11px;">na liście (bez kropki)</span></label>
                        <input type="text" name="evk_darkmode[post_trans_image_class]" value="<?php echo esc_attr($dm['post_trans_image_class']); ?>" placeholder="post-card-image" style="width:100%;">
                        <div class="evo-desc">Klasa elementu Bricks z obrazkiem w query loop. Kilka — oddziel przecinkami.</div>
                    </div>
                    <div class="evo-field">
                        <label>Selektor tytułu <span style="color:#94a3b8;font-weight:400;font-size:11px;">na singlu (CSS)</span></label>
                        <input type="text" name="evk_darkmode[post_trans_title_single]" value="<?php echo esc_attr($dm['post_trans_title_single']); ?>" placeholder=".single-post .brxe-post-title h1" style="width:100%;">
                    </div>
                    <div class="evo-field">
                        <label>Selektor obrazka <span style="color:#94a3b8;font-weight:400;font-size:11px;">na singlu (CSS)</span></label>
                        <input type="text" name="evk_darkmode[post_trans_image_single]" value="<?php echo esc_attr($dm['post_trans_image_single']); ?>" placeholder=".single-post .brxe-post-image img" style="width:100%;">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px;margin-top:8px;">
                    <div class="evo-field">
                        <label>Czas animacji (s)</label>
                        <input type="number" name="evk_darkmode[post_trans_duration]" value="<?php echo esc_attr($dm['post_trans_duration']); ?>" min="0.1" max="3.0" step="0.1" style="width:80px;">
                    </div>
                    <div class="evo-field">
                        <label>Easing</label>
                        <select name="evk_darkmode[post_trans_easing]">
                            <?php foreach (['ease', 'ease-in', 'ease-out', 'ease-in-out', 'linear', 'cubic-bezier(0.4, 0, 0.2, 1)'] as $e): ?>
                            <option value="<?php echo $e; ?>" <?php selected($dm['post_trans_easing'] ?? 'ease-in-out', $e); ?>><?php echo $e; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <?php submit_button('Zapisz ustawienia Dark Mode', 'primary', 'submit', false); ?>
            </form>
