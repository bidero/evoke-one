<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Other subtab: avatar
 */
?>
<div class="evo-info-box">
                    <span class="dashicons dashicons-info"></span>
                    <div>Własny avatar ustawiasz w <strong>profilu użytkownika</strong> — przejdź do <a href="<?php echo esc_url(admin_url('profile.php')); ?>">Użytkownicy → Twój profil</a> i przewiń do sekcji „Avatar (własny obraz)". Obraz zastąpi Gravatar w całej witrynie.</div>
                </div>

                <?php
                // Pokaż listę użytkowników z ich avatarami
                $users = get_users(['orderby' => 'display_name', 'number' => 50]);
                ?>
                <div class="evo-box">
                    <h3 style="margin-top:20px;">Avatary użytkowników</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-top:12px;">
                        <?php foreach ($users as $u):
                            $att_id = (int) get_user_meta($u->ID, 'evk_avatar_id', true);
                            $thumb  = $att_id ? wp_get_attachment_image_url($att_id, [64, 64]) : null;
                            $grav   = get_avatar_url($u->ID, ['size' => 64, 'default' => 'mp']);
                        ?>
                        <div style="display:flex;align-items:center;gap:12px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;">
                            <img src="<?php echo esc_url($thumb ?: $grav); ?>"
                                 style="width:48px;height:48px;border-radius:50%;object-fit:cover;border:2px solid <?php echo $att_id ? '#2563eb' : '#d1d5db'; ?>;"
                                 alt="">
                            <div>
                                <div style="font-size:13px;font-weight:600;color:#111827;"><?php echo esc_html($u->display_name); ?></div>
                                <div style="font-size:11px;color:#6b7280;"><?php echo $att_id ? '<span style="color:#2563eb;">✓ własny avatar</span>' : 'Gravatar'; ?></div>
                                <a href="<?php echo esc_url(admin_url('user-edit.php?user_id=' . $u->ID . '#evk-avatar-preview')); ?>" style="font-size:11px;">edytuj →</a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

