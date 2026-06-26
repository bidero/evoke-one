<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Admin tab: other
 */


            <?php
?>
            $other_sub      = sanitize_key($_GET['sub'] ?? 'interface');
            $other_sub_base = add_query_arg(['tab' => 'other'], $base);
            $other_subs     = [
                'interface'  => ['label' => 'Interfejs',       'icon' => 'dashicons-admin-appearance'],
                'security'   => ['label' => 'Bezpieczeństwo',  'icon' => 'dashicons-shield'],
                'content'    => ['label' => 'Treść',           'icon' => 'dashicons-admin-comments'],
                'dashboard'  => ['label' => 'Kokpit',          'icon' => 'dashicons-dashboard'],
                'avatar'     => ['label' => 'Avatar',          'icon' => 'dashicons-admin-users'],
                'snippets'   => ['label' => 'Snippety',        'icon' => 'dashicons-editor-code'],
            ];
            $evk_sec = evk_security_get();
            $evk_iface = evk_interface_get();
            ?>

            <!-- Podzakładki Inne -->
            <div style="display:flex;gap:4px;margin-bottom:24px;border-bottom:2px solid #e5e7eb;padding-bottom:0;">
                <?php foreach ($other_subs as $sk => $sv): ?>
                <a href="<?php echo esc_url(add_query_arg('sub', $sk, $other_sub_base)); ?>"
                   style="display:flex;align-items:center;gap:6px;padding:8px 14px;font-size:13px;font-weight:<?php echo $other_sub === $sk ? '700' : '500'; ?>;color:<?php echo $other_sub === $sk ? '#2563eb' : '#374151'; ?>;text-decoration:none;border-bottom:<?php echo $other_sub === $sk ? '2px solid #2563eb' : '2px solid transparent'; ?>;margin-bottom:-2px;">
                    <span class="dashicons <?php echo $sv['icon']; ?>" style="font-size:15px;width:15px;height:15px;"></span>
                    <?php echo esc_html($sv['label']); ?>
                </a>
                <?php endforeach; ?>
            </div>

            <?php if ($other_sub === 'interface'): ?>

                <!-- Bricks bottom — osobna forma bo to opcja z grupy evoke_one_other -->
                <form method="post" action="options.php">
                    <?php settings_fields('evoke_one_other'); ?>

                <!-- Miniatury — własna opcja evk_interface -->
                <form method="post" action="options.php">
                    <?php settings_fields('evoke_one_interface'); ?>

                    <p class="evo-section-title">Miniatury w listach wpisów</p>
                    <div class="evo-info-box">
                        <span class="dashicons dashicons-info"></span>
                        <div>Dodaje kolumnę z miniaturą (wyróżnionym obrazem) do tabel wpisów w panelu administracyjnym.</div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:16px;">
                        <label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;cursor:pointer;">
                            <input type="checkbox" name="evk_interface[post_thumbnails_enabled]" value="1" <?php checked(1, $evk_iface['post_thumbnails_enabled']); ?>>
                            Włącz kolumnę miniatur w tabelach wpisów
                        </label>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:500px;">
                        <div class="evo-field" style="margin-bottom:0;">
                            <label>Położenie kolumny</label>
                            <select name="evk_interface[post_thumbnails_position]">
                                <option value="after_cb"     <?php selected($evk_iface['post_thumbnails_position'], 'after_cb'); ?>>Po checkboxie</option>
                                <option value="before_title" <?php selected($evk_iface['post_thumbnails_position'], 'before_title'); ?>>Przed tytułem</option>
                                <option value="after_title"  <?php selected($evk_iface['post_thumbnails_position'], 'after_title'); ?>>Po tytule</option>
                                <option value="after_list"   <?php selected($evk_iface['post_thumbnails_position'], 'after_list'); ?>>Na końcu</option>
                            </select>
                        </div>
                        <div class="evo-field" style="margin-bottom:0;">
                            <label>Rozmiar miniatury (px)</label>
                            <input type="number" name="evk_interface[post_thumbnails_size]"
                                   value="<?php echo esc_attr($evk_iface['post_thumbnails_size'] ?? 48); ?>"
                                   min="20" max="300" style="max-width:100px;">
                            <div class="evo-desc">Domyślnie: 48</div>
                        </div>
                    </div>
                    <div class="evo-save-bar"><?php submit_button('Zapisz ustawienia interfejsu', 'primary', 'submit', false); ?></div>
                </form>


            <?php elseif ($other_sub === 'security'): ?>

                <?php
                $active_blocks  = evk_login_active_blocks();
                $sec_nonce      = wp_create_nonce('evk_security_nonce');
                ?>

                <form method="post" action="options.php">
                    <?php settings_fields('evoke_one_security'); ?>

                    <!-- Ukryj wersję WP -->
                    <p class="evo-section-title">WordPress</p>
                    <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px;">
                        <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;font-weight:500;cursor:pointer;">
                            <input type="checkbox" name="evk_security[hide_wp_version]" value="1" <?php checked(1, $evk_sec['hide_wp_version']); ?> style="margin-top:2px;">
                            <span>
                                Ukryj wersję WordPress
                                <span style="display:block;font-weight:400;color:#6b7280;font-size:12px;margin-top:2px;">Usuwa numer wersji z kodu HTML, RSS, nagłówków HTTP oraz query stringów assetów.</span>
                            </span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;font-weight:500;cursor:pointer;">
                            <input type="checkbox" name="evk_security[disable_bundled_themes]" value="1" <?php checked(1, $evk_sec['disable_bundled_themes']); ?> style="margin-top:2px;">
                            <span>
                                Wyłącz aktualizację motywów dołączonych do WP (Twenty*)
                                <span style="display:block;font-weight:400;color:#6b7280;font-size:12px;margin-top:2px;">Zapobiega automatycznej instalacji/aktualizacji domyślnych motywów WordPress podczas aktualizacji rdzenia.</span>
                            </span>
                        </label>
                    </div>

                    <hr class="evo-divider">

                    <!-- Limit logowań -->
                    <div class="evo-status-card">
                        <div class="evo-status-icon <?php echo !empty($evk_sec['limit_login_enabled']) ? 'on' : 'off'; ?>">
                            <span class="dashicons dashicons-lock" style="font-size:24px;width:24px;height:24px;line-height:1;"></span>
                        </div>
                        <div class="evo-status-text">
                            <h3>Limit prób logowania: <?php echo !empty($evk_sec['limit_login_enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
                            <p>Automatycznie blokuje adresy IP po przekroczeniu limitu nieudanych prób logowania.</p>
                        </div>
                        <div class="evo-status-actions">
                            <label class="evo-toggle">
                                <input type="checkbox" name="evk_security[limit_login_enabled]" value="1" <?php checked(1, $evk_sec['limit_login_enabled']); ?>>
                                <span class="evo-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:24px;">
                        <div class="evo-field" style="margin-bottom:0;">
                            <label>Maks. prób logowania</label>
                            <input type="number" name="evk_security[max_attempts]" value="<?php echo esc_attr($evk_sec['max_attempts']); ?>" min="1" max="100">
                            <div class="evo-desc">Liczba nieudanych prób przed blokadą IP. Domyślnie: 5.</div>
                        </div>
                        <div class="evo-field" style="margin-bottom:0;">
                            <label>Resetuj po (godzinach)</label>
                            <input type="number" name="evk_security[reset_hours]" value="<?php echo esc_attr($evk_sec['reset_hours']); ?>" min="1" max="720">
                            <div class="evo-desc">Po ilu godzinach licznik prób i blokady wygasają. Domyślnie: 24.</div>
                        </div>
                    </div>

                    <div class="evo-save-bar" style="margin-bottom:24px;"><?php submit_button('Zapisz ustawienia bezpieczeństwa', 'primary', 'submit', false); ?></div>
                </form>

                <!-- Aktywne blokady -->
                <hr class="evo-divider">
                <p class="evo-section-title">
                    Aktywne blokady
                    <span style="font-weight:400;color:#6b7280;font-size:12px;margin-left:8px;"><?php echo count($active_blocks); ?> aktywnych</span>
                </p>

                <?php if (empty($active_blocks)): ?>
                    <p style="color:#6b7280;font-size:13px;">Brak zablokowanych adresów IP.</p>
                <?php else: ?>
                    <div style="background:#fff;border:1px solid #d1d5db;border-radius:6px;overflow:hidden;margin-bottom:16px;">
                        <table class="wp-list-table widefat fixed striped" style="border:none;">
                            <thead>
                                <tr>
                                    <th>Adres IP</th>
                                    <th>Użytkownik</th>
                                    <th>Prób</th>
                                    <th>Zablokowano</th>
                                    <th>Wygasa</th>
                                    <th style="width:100px;">Akcja</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($active_blocks as $ip => $data):
                                $expires_at  = $data['blocked_at'] + $evk_sec['reset_hours'] * HOUR_IN_SECONDS;
                                $hours_left  = max(0, ceil(($expires_at - current_time('timestamp')) / HOUR_IN_SECONDS));
                            ?>
                            <tr id="evk-block-row-<?php echo esc_attr(md5($ip)); ?>">
                                <td><code><?php echo esc_html($ip); ?></code></td>
                                <td><?php echo esc_html($data['username'] ?: '—'); ?></td>
                                <td><?php echo (int) $data['attempts']; ?></td>
                                <td><?php echo esc_html(date_i18n('d.m.Y H:i', $data['blocked_at'])); ?></td>
                                <td>za <?php echo $hours_left; ?> godz.</td>
                                <td>
                                    <button type="button" class="button button-small evk-unblock-btn"
                                        data-ip="<?php echo esc_attr($ip); ?>"
                                        data-nonce="<?php echo esc_attr($sec_nonce); ?>"
                                        data-row="evk-block-row-<?php echo esc_attr(md5($ip)); ?>">
                                        Odblokuj
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="button" id="evk-clear-all-blocks"
                        data-nonce="<?php echo esc_attr($sec_nonce); ?>">
                        Odblokuj wszystkie
                    </button>
                    <span id="evk-blocks-status" style="margin-left:10px;font-size:13px;color:#047857;display:none;"></span>
                <?php endif; ?>

                <script>
                (function ($) {
                    $(document).on('click', '.evk-unblock-btn', function () {
                        var btn = this, ip = $(btn).data('ip'), row = '#' + $(btn).data('row');
                        $(btn).prop('disabled', true).text('...');
                        $.post(ajaxurl, { action: 'evk_unblock_ip', nonce: $(btn).data('nonce'), ip: ip })
                            .done(function (r) {
                                if (r.success) $(row).fadeOut(300, function () { $(this).remove(); });
                                else alert(r.data || 'Błąd');
                            })
                            .fail(function () { alert('Błąd połączenia.'); $(btn).prop('disabled', false).text('Odblokuj'); });
                    });
                    $('#evk-clear-all-blocks').on('click', function () {
                        if (!confirm('Odblokować wszystkie adresy IP?')) return;
                        var btn = this;
                        $(btn).prop('disabled', true);
                        $.post(ajaxurl, { action: 'evk_clear_all_blocks', nonce: $(btn).data('nonce') })
                            .done(function (r) {
                                if (r.success) {
                                    $('tr[id^="evk-block-row-"]').fadeOut(300, function () { $(this).remove(); });
                                    $('#evk-blocks-status').text('Wyczyszczono.').show();
                                    $(btn).remove();
                                }
                            });
                    });
                })(jQuery);
                </script>

                <!-- REST API -->
                <hr class="evo-divider">
                <form method="post" action="options.php">
                    <?php settings_fields('evoke_one_security'); ?>

                    <p class="evo-section-title">REST API — blokowanie dla gości</p>

                    <div class="evo-status-card" style="margin-bottom:16px;">
                        <div class="evo-status-icon <?php echo !empty($evk_sec['rest_block_all']) ? 'on' : 'off'; ?>">
                            <span class="dashicons dashicons-rest-api" style="font-size:24px;width:24px;height:24px;line-height:1;"></span>
                        </div>
                        <div class="evo-status-text">
                            <h3>Zablokuj cały REST API dla gości</h3>
                            <p>Każdy request do <code>/wp-json/</code> zwróci błąd 401 dla niezalogowanych użytkowników.</p>
                        </div>
                        <div class="evo-status-actions">
                            <label class="evo-toggle">
                                <input type="checkbox" name="evk_security[rest_block_all]" value="1" <?php checked(1, $evk_sec['rest_block_all'] ?? 0); ?>>
                                <span class="evo-slider"></span>
                            </label>
                        </div>
                    </div>

                    <div class="evo-info-box">
                        <span class="dashicons dashicons-info"></span>
                        <div>Lub zaznacz konkretne endpointy. Zalogowani użytkownicy zawsze mają dostęp.</div>
                    </div>

                    <?php
                    $disabled_endpoints = evk_security_get()['disabled_rest_endpoints'] ?? [];
                    $grouped = evk_rest_get_endpoints();
                    $total   = array_sum(array_map('count', $grouped));
                    ?>

                    <div style="margin-bottom:10px;display:flex;align-items:center;gap:12px;">
                        <label style="font-size:13px;font-weight:500;cursor:pointer;display:flex;align-items:center;gap:6px;">
                            <input type="checkbox" id="evk-rest-select-all">
                            Zaznacz / odznacz wszystkie
                        </label>
                        <span style="font-size:12px;color:#6b7280;">Łącznie: <?php echo $total; ?> endpointów</span>
                    </div>

                    <div style="max-height:280px;overflow-y:auto;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px;">
                        <?php foreach ($grouped as $namespace => $endpoints): ?>
                        <div style="margin-bottom:16px;">
                            <div style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.05em;
                                        padding:4px 8px;background:#fff;border-left:3px solid #2563eb;margin-bottom:6px;">
                                <?php echo esc_html($namespace); ?>
                                <span style="font-weight:400;color:#6b7280;">(<?php echo count($endpoints); ?>)</span>
                            </div>
                            <?php foreach ($endpoints as $ep):
                                $checked = in_array($ep['route'], $disabled_endpoints, true);
                            ?>
                            <label style="display:flex;align-items:center;gap:8px;padding:3px 8px;cursor:pointer;border-radius:4px;font-size:12px;"
                                   onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''">
                                <input type="checkbox"
                                       class="evk-rest-ep"
                                       name="evk_security[disabled_rest_endpoints][]"
                                       value="<?php echo esc_attr($ep['route']); ?>"
                                       <?php checked($checked); ?>>
                                <code style="background:#f0f0f1;padding:1px 5px;border-radius:3px;font-size:11px;"><?php echo esc_html($ep['route']); ?></code>
                                <span style="color:#6b7280;font-size:11px;"><?php echo esc_html(implode(', ', $ep['methods'])); ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <script>
                    (function($){
                        $('#evk-rest-select-all').on('change', function(){
                            $('.evk-rest-ep').prop('checked', $(this).prop('checked'));
                        });
                        $('.evk-rest-ep').on('change', function(){
                            var t=$('.evk-rest-ep').length, c=$('.evk-rest-ep:checked').length;
                            $('#evk-rest-select-all').prop('checked', t===c);
                        });
                    })(jQuery);
                    </script>

                    <div class="evo-save-bar"><?php submit_button('Zapisz ustawienia REST API', 'primary', 'submit', false); ?></div>
                </form>

            <?php elseif ($other_sub === 'content'): ?>

                <form method="post" action="options.php">
                    <?php settings_fields('evoke_one_other'); ?>

                    <p class="evo-section-title">Komentarze</p>
                    <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px;">
                        <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;font-weight:500;cursor:pointer;">
                            <input type="checkbox" name="evoke_disable_global_comments" value="1" <?php checked(1, get_option('evoke_disable_global_comments')); ?> style="margin-top:2px;">
                            <span>
                                Wyłącz komentarze na całej stronie
                                <span style="display:block;font-weight:400;color:#6b7280;font-size:12px;margin-top:2px;">Nadpisuje ustawienia poszczególnych wpisów i wyłącza wsparcie dla komentarzy we wszystkich typach treści.</span>
                            </span>
                        </label>
                        <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;font-weight:500;cursor:pointer;">
                            <input type="checkbox" name="evoke_require_reg_to_comment" value="1" <?php checked(1, get_option('evoke_require_reg_to_comment')); ?> style="margin-top:2px;">
                            <span>
                                Wymagaj rejestracji i zalogowania, aby komentować
                                <span style="display:block;font-weight:400;color:#6b7280;font-size:12px;margin-top:2px;">Ustawia opcję WordPress „Użytkownicy muszą być zalogowani, aby mogli komentować".</span>
                            </span>
                        </label>
                    </div>

                    <div class="evo-save-bar"><?php submit_button('Zapisz ustawienia', 'primary', 'submit', false); ?></div>
                </form>

            <?php elseif ($other_sub === 'dashboard'): ?>

                <form method="post" action="options.php">
                    <?php settings_fields('evoke_one_other'); ?>

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
                                <input type="checkbox" name="evoke_dashboard_active" value="1" <?php checked(get_option('evoke_dashboard_active'), '1'); ?>>
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
                        <label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;cursor:pointer;"><input type="checkbox" name="evoke_dashboard_remove_native" value="1" <?php checked(get_option('evoke_dashboard_remove_native'), '1'); ?>> Usuń domyślne widgety kokpitu</label>
                        <label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;cursor:pointer;"><input type="checkbox" name="evoke_dashboard_remove_help" value="1" <?php checked(get_option('evoke_dashboard_remove_help'), '1'); ?>> Ukryj zakładkę „Pomoc"</label>
                        <label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;cursor:pointer;"><input type="checkbox" name="evoke_dashboard_fit_content" value="1" <?php checked(get_option('evoke_dashboard_fit_content'), '1'); ?>> Dynamiczne dopasowanie wysokości iframe</label>
                        <label style="display:flex;align-items:center;gap:10px;font-size:13px;font-weight:500;cursor:pointer;"><input type="checkbox" name="evoke_dashboard_shadow" value="1" <?php checked(get_option('evoke_dashboard_shadow', '1'), '1'); ?>> Cień i zaokrąglone rogi iframe</label>
                    </div>
                    <div class="evo-save-bar"><?php submit_button('Zapisz ustawienia', 'primary', 'submit', false); ?></div>
                </form>

            <?php elseif ($other_sub === 'snippets'): ?>

                <?php evk_snippets_render_tab(); ?>

            <?php elseif ($other_sub === 'avatar'): ?>

                <div class="evo-info-box">
                    <span class="dashicons dashicons-info"></span>
                    <div>Własny avatar ustawiasz w <strong>profilu użytkownika</strong> — przejdź do <a href="<?php echo esc_url(admin_url('profile.php')); ?>">Użytkownicy → Twój profil</a> i przewiń do sekcji „Avatar (własny obraz)". Obraz zastąpi Gravatar w całej witrynie.</div>
                </div>

                <?php
                // Pokaż listę użytkowników z ich avatarami
                $users = get_users(['orderby' => 'display_name', 'number' => 50]);
                ?>
                <p class="evo-section-title" style="margin-top:20px;">Avatary użytkowników</p>
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

            <?php endif; ?>

        <?php endif; ?>
