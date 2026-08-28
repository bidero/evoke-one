<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Other subtab: content
 */
?>
<form method="post" action="options.php">
                    <?php settings_fields('evoke_one_content'); ?>

                    <div class="evo-box">
                        <h3>Komentarze</h3>
                        <div class="evo-stack evo-mb-lg">
                            <label class="evo-choice evo-choice-stack">
                                <input type="checkbox" name="evoke_disable_global_comments" data-option="evoke_disable_global_comments" data-field="_scalar" value="1" <?php checked(1, get_option('evoke_disable_global_comments')); ?>>
                                <span>
                                    <strong>Wyłącz komentarze na całej stronie</strong>
                                    <span class="evo-hint">Nadpisuje ustawienia poszczególnych wpisów i wyłącza wsparcie dla komentarzy we wszystkich typach treści.</span>
                                </span>
                            </label>
                            <label class="evo-choice evo-choice-stack">
                                <input type="checkbox" name="evoke_require_reg_to_comment" data-option="evoke_require_reg_to_comment" data-field="_scalar" value="1" <?php checked(1, get_option('evoke_require_reg_to_comment')); ?>>
                                <span>
                                    <strong>Wymagaj rejestracji i zalogowania, aby komentować</strong>
                                    <span class="evo-hint">Ustawia opcję WordPress „Użytkownicy muszą być zalogowani, aby mogli komentować".</span>
                                </span>
                            </label>
                        </div>

                    
                    </div>

<div class="evo-save-bar"><?php submit_button('Zapisz ustawienia', 'primary', 'submit', false); ?></div>
                </form>
