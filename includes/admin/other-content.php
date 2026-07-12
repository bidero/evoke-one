<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Other subtab: content
 */
?>
<form method="post" action="options.php">
                    <?php settings_fields('evoke_one_content'); ?>

                    <p class="evo-section-title">Komentarze</p>
                    <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px;">
                        <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;font-weight:500;cursor:pointer;">
                            <input type="checkbox" name="evoke_disable_global_comments" data-option="evoke_disable_global_comments" data-field="_scalar" value="1" <?php checked(1, get_option('evoke_disable_global_comments')); ?> style="margin-top:2px;">
                            <span>
                                Wyłącz komentarze na całej stronie
                                <span style="display:block;font-weight:400;color:#6b7280;font-size:12px;margin-top:2px;">Nadpisuje ustawienia poszczególnych wpisów i wyłącza wsparcie dla komentarzy we wszystkich typach treści.</span>
                            </span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;font-weight:500;cursor:pointer;">
                            <input type="checkbox" name="evoke_require_reg_to_comment" data-option="evoke_require_reg_to_comment" data-field="_scalar" value="1" <?php checked(1, get_option('evoke_require_reg_to_comment')); ?> style="margin-top:2px;">
                            <span>
                                Wymagaj rejestracji i zalogowania, aby komentować
                                <span style="display:block;font-weight:400;color:#6b7280;font-size:12px;margin-top:2px;">Ustawia opcję WordPress „Użytkownicy muszą być zalogowani, aby mogli komentować".</span>
                            </span>
                        </label>
                    </div>

                    <div class="evo-save-bar"><?php submit_button('Zapisz ustawienia', 'primary', 'submit', false); ?></div>
                </form>
