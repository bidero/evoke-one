<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Other subtab: interface
 */
?>
<!-- Miniatury — własna opcja evk_interface -->
                <form method="post" action="options.php">
                    <?php settings_fields('evoke_one_interface'); ?>

                    <div class="evo-box">
                        <h3>Miniatury w listach wpisów</h3>
                        <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Dodaje kolumnę z miniaturą (wyróżnionym obrazem) do tabel wpisów w panelu administracyjnym.</div></details>
                        <div class="evo-stack evo-mb">
                            <label class="evo-check">
                                <input type="checkbox" name="evk_interface[post_thumbnails_enabled]" value="1" <?php checked(1, $evk_iface['post_thumbnails_enabled']); ?>>
                                Włącz kolumnę miniatur w tabelach wpisów
                            </label>
                        </div>
                        <div class="evo-grid-2 evo-w" style="--evo-gap:16px;--evo-w:500px">
                            <div class="evo-field evo-mb-0">
                                <label>Położenie kolumny</label>
                                <select name="evk_interface[post_thumbnails_position]">
                                    <option value="after_cb"     <?php selected($evk_iface['post_thumbnails_position'], 'after_cb'); ?>>Po checkboxie</option>
                                    <option value="before_title" <?php selected($evk_iface['post_thumbnails_position'], 'before_title'); ?>>Przed tytułem</option>
                                    <option value="after_title"  <?php selected($evk_iface['post_thumbnails_position'], 'after_title'); ?>>Po tytule</option>
                                    <option value="after_list"   <?php selected($evk_iface['post_thumbnails_position'], 'after_list'); ?>>Na końcu</option>
                                </select>
                            </div>
                            <div class="evo-field evo-mb-0">
                                <label>Rozmiar miniatury (px)</label>
                                <input type="number" name="evk_interface[post_thumbnails_size]"
                                       value="<?php echo esc_attr($evk_iface['post_thumbnails_size'] ?? 48); ?>"
                                       min="20" max="300" class="evo-w" style="--evo-w:100px">
                                <div class="evo-desc">Domyślnie: 48</div>
                            </div>
                        </div>
                    
                    </div>

<div class="evo-save-bar"><?php submit_button('Zapisz ustawienia interfejsu', 'primary', 'submit', false); ?></div>
                </form>
