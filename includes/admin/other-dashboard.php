<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Other subtab: dashboard
 */
?>
<form method="post" action="options.php">
                    <?php settings_fields('evoke_one_dashboard'); ?>

                    <p class="evo-section-title">Kokpit Bricks Builder</p>
                    <div class="evo-info-box">
                        <span class="dashicons dashicons-info"></span>
                        <div>Zastąp domyślny kokpit WordPress stroną Bricks Builder wyświetlaną w iframe.</div>
                    </div>
                    <div class="evo-status-card" style="margin-bottom:20px;">
                        <div class="evo-status-icon <?php echo get_option('evoke_dashboard_active') === '1' ? 'on' : 'off'; ?>">
                            <span class="dashicons dashicons-dashboard" style="font-size:24px;width:24px;height:24px;line-height:1;"></span>
                        </div>
                        <div class="evo-status-text">
                            <h3>Kokpit Bricks: <?php echo get_option('evoke_dashboard_active') === '1' ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
                            <p>Strona Bricks wyświetlana jako iframe na stronie kokpitu WordPress.</p>
                        </div>
                        <div class="evo-status-actions">
                            <label class="evo-toggle">
                                <input type="checkbox" name="evoke_dashboard_active" data-option="evoke_dashboard_active" data-field="_scalar" value="1" <?php checked(get_option('evoke_dashboard_active'), '1'); ?>>
                                <span class="evo-slider"></span>
                            </label>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:20px;">
                        <div class="evo-field" style="margin-bottom:0;"><label>Strona Bricks Builder</label><?php wp_dropdown_pages(['name' => 'evoke_dashboard_page_id', 'selected' => (int) get_option('evoke_dashboard_page_id', 0), 'show_option_none' => '— wybierz —']); ?></div>
                        <div class="evo-field" style="margin-bottom:0;"><label>Tryb</label><select name="evoke_dashboard_mode"><option value="above" <?php selected(get_option('evoke_dashboard_mode', 'above'), 'above'); ?>>Oddzielony</option><option value="replace" <?php selected(get_option('evoke_dashboard_mode'), 'replace'); ?>>Dolepiony</option></select></div>
                        <div class="evo-field" style="margin-bottom:0;"><label>Szerokość</label><input type="text" name="evoke_dashboard_width" value="<?php echo esc_attr(get_option('evoke_dashboard_width', '100%')); ?>" style="max-width:100px;"></div>
                        <div class="evo-field" style="margin-bottom:0;"><label>Wysokość</label><input type="text" name="evoke_dashboard_height" value="<?php echo esc_attr(get_option('evoke_dashboard_height', '600px')); ?>" style="max-width:100px;"></div>
                        <div class="evo-field" style="margin-bottom:0;"><label>Paski przewijania</label><select name="evoke_dashboard_scrolling"><option value="auto" <?php selected(get_option('evoke_dashboard_scrolling', 'auto'), 'auto'); ?>>Auto</option><option value="yes" <?php selected(get_option('evoke_dashboard_scrolling'), 'yes'); ?>>Zawsze</option><option value="no" <?php selected(get_option('evoke_dashboard_scrolling'), 'no'); ?>>Ukryte</option></select></div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px;">
                        <label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;cursor:pointer;"><input type="checkbox" name="evoke_dashboard_remove_native" data-option="evoke_dashboard_remove_native" data-field="_scalar" value="1" <?php checked(get_option('evoke_dashboard_remove_native'), '1'); ?>> Usuń domyślne widgety kokpitu</label>
                        <label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;cursor:pointer;"><input type="checkbox" name="evoke_dashboard_remove_help" data-option="evoke_dashboard_remove_help" data-field="_scalar" value="1" <?php checked(get_option('evoke_dashboard_remove_help'), '1'); ?>> Ukryj zakładkę „Pomoc"</label>
                        <label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;cursor:pointer;"><input type="checkbox" name="evoke_dashboard_fit_content" data-option="evoke_dashboard_fit_content" data-field="_scalar" value="1" <?php checked(get_option('evoke_dashboard_fit_content'), '1'); ?>> Dynamiczne dopasowanie wysokości iframe</label>
                        <label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;cursor:pointer;"><input type="checkbox" name="evoke_dashboard_shadow" data-option="evoke_dashboard_shadow" data-field="_scalar" value="1" <?php checked(get_option('evoke_dashboard_shadow', '1'), '1'); ?>> Cień i zaokrąglone rogi iframe</label>
                    </div>
                    <div class="evo-save-bar"><?php submit_button('Zapisz ustawienia', 'primary', 'submit', false); ?></div>
                </form>
