<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Other subtab: avatar
 */
?>
<details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Własny avatar ustawiasz w <strong>profilu użytkownika</strong> — przejdź do <a href="<?php echo esc_url(admin_url('profile.php')); ?>">Użytkownicy → Twój profil</a> i przewiń do sekcji „Avatar (własny obraz)". Obraz zastąpi Gravatar w całej witrynie.</div></details>

                <?php
                // Pokaż listę użytkowników z ich avatarami
                $users = get_users(['orderby' => 'display_name', 'number' => 50]);
                ?>
                <div class="evo-box">
                    <h3>Avatary użytkowników</h3>
                    <div class="evo-grid evo-mt-xs" style="--evo-col:200px;--evo-gap:16px">
                        <?php foreach ($users as $u):
                            $att_id = (int) get_user_meta($u->ID, 'evk_avatar_id', true);
                            $thumb  = $att_id ? wp_get_attachment_image_url($att_id, [64, 64]) : null;
                            $grav   = get_avatar_url($u->ID, ['size' => 64, 'default' => 'mp']);
                        ?>
                        <div class="evo-user-card">
                            <?php /* Stan „ma własny avatar" niesie KLASA, nie hex w PHP — inaczej
                                     ramka nie reaguje na zmianę palety (wzorzec z 1.48.0). */ ?>
                            <img src="<?php echo esc_url($thumb ?: $grav); ?>"
                                 class="evo-user-avatar<?php echo $att_id ? ' is-custom' : ''; ?>"
                                 alt="">
                            <div>
                                <div class="evo-user-name"><?php echo esc_html($u->display_name); ?></div>
                                <div class="evo-hint-sm"><?php echo $att_id ? '<span class="evo-accent-tx">✓ własny avatar</span>' : 'Gravatar'; ?></div>
                                <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $u->ID . '#evk-avatar-preview')); ?>" class="evo-hint-sm">edytuj →</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

