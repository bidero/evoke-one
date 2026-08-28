<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Other subtab: dashboard
 */
?>
<form method="post" action="options.php">
                    <?php settings_fields('evoke_one_dashboard'); ?>

                    <div class="evo-box">
                        <h3>Kokpit Bricks Builder</h3>
                        <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Zastąp domyślny kokpit WordPress stroną Bricks Builder wyświetlaną w iframe.</div></details>
                        <div class="evo-status-card">
                            <div class="evo-status-icon <?php echo get_option('evoke_dashboard_active') === '1' ? 'on' : 'off'; ?>">
                                <span class="dashicons dashicons-dashboard evo-ico-lg"></span>
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
                        <div class="evo-grid evo-mb-lg" style="--evo-col:200px">
                            <div class="evo-field evo-mb-0"><label>Strona Bricks Builder</label><?php wp_dropdown_pages(['name' => 'evoke_dashboard_page_id', 'selected' => (int) get_option('evoke_dashboard_page_id', 0), 'show_option_none' => '— wybierz —']); ?></div>
                            <div class="evo-field evo-mb-0"><label>Tryb</label><select name="evoke_dashboard_mode"><option value="above" <?php selected(get_option('evoke_dashboard_mode', 'above'), 'above'); ?>>Oddzielony</option><option value="replace" <?php selected(get_option('evoke_dashboard_mode'), 'replace'); ?>>Dolepiony</option></select></div>
                            <div class="evo-field evo-mb-0"><label>Szerokość</label><input type="text" name="evoke_dashboard_width" value="<?php echo esc_attr(get_option('evoke_dashboard_width', '100%')); ?>" class="evo-w" style="--evo-w:100px"></div>
                            <div class="evo-field evo-mb-0"><label>Wysokość</label><input type="text" name="evoke_dashboard_height" value="<?php echo esc_attr(get_option('evoke_dashboard_height', '600px')); ?>" class="evo-w" style="--evo-w:100px"></div>
                            <div class="evo-field evo-mb-0"><label>Paski przewijania</label><select name="evoke_dashboard_scrolling"><option value="auto" <?php selected(get_option('evoke_dashboard_scrolling', 'auto'), 'auto'); ?>>Auto</option><option value="yes" <?php selected(get_option('evoke_dashboard_scrolling'), 'yes'); ?>>Zawsze</option><option value="no" <?php selected(get_option('evoke_dashboard_scrolling'), 'no'); ?>>Ukryte</option></select></div>
                        </div>
                        <div class="evo-stack evo-mb-lg" style="--evo-gap:10px">
                            <label class="evo-check"><input type="checkbox" name="evoke_dashboard_remove_native" data-option="evoke_dashboard_remove_native" data-field="_scalar" value="1" <?php checked(get_option('evoke_dashboard_remove_native'), '1'); ?>> Usuń domyślne widgety kokpitu</label>
                            <label class="evo-check"><input type="checkbox" name="evoke_dashboard_remove_help" data-option="evoke_dashboard_remove_help" data-field="_scalar" value="1" <?php checked(get_option('evoke_dashboard_remove_help'), '1'); ?>> Ukryj zakładkę „Pomoc"</label>
                            <label class="evo-check"><input type="checkbox" name="evoke_dashboard_fit_content" data-option="evoke_dashboard_fit_content" data-field="_scalar" value="1" <?php checked(get_option('evoke_dashboard_fit_content'), '1'); ?>> Dynamiczne dopasowanie wysokości iframe</label>
                            <label class="evo-check"><input type="checkbox" name="evoke_dashboard_shadow" data-option="evoke_dashboard_shadow" data-field="_scalar" value="1" <?php checked(get_option('evoke_dashboard_shadow', '1'), '1'); ?>> Cień i zaokrąglone rogi iframe</label>
                        </div>
                    
                    </div>

<div class="evo-save-bar"><?php submit_button('Zapisz ustawienia', 'primary', 'submit', false); ?></div>
                </form>
