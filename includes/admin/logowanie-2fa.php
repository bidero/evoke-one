<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Logowanie › Logowanie dwuetapowe (1.287.0).
 * Włącznik, role z wymuszeniem, zapamiętywanie urządzeń, konta z 2FA
 * (reset) i dziennik. Konto włącza 2FA samo, w swoim Profilu.
 */
$evk_u  = evk_2fa_ustawienia();
$evk_on = !empty($evk_u['enabled']);
?>
<div class="evk-2fa-panel">
    <div class="evo-status-card">
        <div class="evo-status-icon <?php echo $evk_on ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-lock evo-ico-lg"></span>
        </div>
        <div class="evo-status-text">
            <h3>Logowanie dwuetapowe: <?php echo $evk_on ? 'WŁĄCZONE' : 'WYŁĄCZONE'; ?></h3>
            <p>Po haśle 6 cyfr z aplikacji w telefonie. Konto włącza je samo w swoim Profilu; dla wybranych ról jest obowiązkowe.</p>
        </div>
        <div class="evo-status-actions">
            <label class="evo-toggle">
                <input aria-label="Logowanie dwuetapowe" type="checkbox" data-option="evk_2fa" data-field="enabled" value="1" <?php checked($evk_on); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>

    <?php if (evk_2fa_awaryjnie()): ?>
    <div class="evo-info-box evo-mt" role="alert"><span class="dashicons dashicons-warning"></span>
        <p class="evo-m0"><strong>Wyłączone awaryjnie stałą EVK_2FA_WYLACZ w wp-config.php.</strong> Każde konto loguje się samym hasłem. Usuń stałą, gdy tylko odzyskasz dostęp.</p></div>
    <?php endif; ?>

    <?php if (!$evk_on): ?>
    <div class="evo-empty-panel evo-mt">
        <span class="dashicons dashicons-lock evo-ico-xl"></span>
        <p class="evo-muted evo-m0 evo-mt-xs">Po włączeniu każde konto znajdzie w swoim Profilu sekcję „Logowanie dwuetapowe” (kod QR, kody zapasowe). Konta bez 2FA logują się jak dotąd, dopóki ich roli nie obejmie wymuszenie.</p>
    </div>
    <?php else: ?>
    <form id="evk-2fa-form" class="evo-mt">
        <div class="evo-box">
            <h3>Wymuszenie i urządzenia</h3>
            <fieldset>
                <legend class="evo-label">Wymagaj logowania dwuetapowego dla ról</legend>
                <p class="evo-desc">Konto takiej roli bez 2FA po zalogowaniu widzi tylko swój Profil, dopóki go nie włączy. Samo nie może go wyłączyć.</p>
                <?php foreach (wp_roles()->get_names() as $evk_r => $evk_n): ?>
                <label class="evo-check-row"><input type="checkbox" name="role[]" value="<?php echo esc_attr((string) $evk_r); ?>" <?php checked(in_array((string) $evk_r, $evk_u['role'], true)); ?>> <?php echo esc_html(translate_user_role($evk_n)); ?></label>
                <?php endforeach; ?>
            </fieldset>
            <label class="evo-check-row evo-mt"><input type="checkbox" name="pamietaj" value="1" <?php checked(!empty($evk_u['pamietaj'])); ?>> Pozwól zapamiętać urządzenie na <?php echo (int) EVK_2FA_URZADZENIE_DNI; ?> dni (pole przy kodzie, domyślnie odznaczone; zmiana hasła unieważnia)</label>
        </div>
        <?php evoke_one_pasek_zapisu('Zapisz', false, 'evk-2fa-zapisano'); ?>
    </form>
    <script>
    jQuery(function ($) {
        $('#evk-2fa-form').on('submit', function (e) {
            e.preventDefault();
            var f = this, role = [];
            $(f).find('input[name="role[]"]:checked').each(function () { role.push(this.value); });
            $.post(ajaxurl, { action: 'evk_2fa_ustawienia', nonce: <?php echo wp_json_encode(wp_create_nonce('evk_2fa_ustawienia')); ?>,
                role: role, pamietaj: $(f).find('input[name=pamietaj]').is(':checked') ? 1 : 0 })
                .done(function (r) { if (r && r.success) $(f).find('.evk-2fa-zapisano').addClass('is-widoczny'); else alert((r && r.data) || 'Błąd zapisu.'); });
        });
    });
    </script>

    <?php
    $evk_konta = get_users(['meta_key' => EVK_2FA_META, 'fields' => 'all']);
    $evk_brak = $evk_u['role'] ? get_users(['role__in' => $evk_u['role'], 'fields' => 'all']) : [];
    $evk_lista = [];
    foreach (array_merge($evk_konta, $evk_brak) as $evk_k) $evk_lista[$evk_k->ID] = $evk_k;
    ?>
    <div class="evo-box evo-mt" id="evk-2fa-konta">
        <h3>Konta</h3>
        <?php if (!$evk_lista): ?>
        <p class="evo-muted">Żadne konto nie włączyło jeszcze logowania dwuetapowego. Zacznij od swojego: <a href="<?php echo esc_url(admin_url('profile.php#evk-2fa')); ?>">Profil → Logowanie dwuetapowe</a>.</p>
        <?php else: ?>
        <div class="evo-tbl-wrap"><table class="evo-table">
            <thead><tr><th scope="col">Konto</th><th scope="col">Stan</th><th scope="col">Akcje</th></tr></thead>
            <tbody>
            <?php foreach ($evk_lista as $evk_k): $evk_ma = evk_2fa_ma($evk_k->ID); $evk_kk = evk_2fa_konto($evk_k->ID); ?>
            <tr data-konto="<?php echo (int) $evk_k->ID; ?>">
                <td><?php echo esc_html($evk_k->display_name . ' (' . $evk_k->user_login . ')'); ?></td>
                <td><?php echo esc_html($evk_ma ? 'Włączone od ' . wp_date('j.m.Y', $evk_kk['od']) . ', kodów zapasowych: ' . count($evk_kk['kody']) : (evk_2fa_wymagane($evk_k) ? 'Wymagane — jeszcze nie włączone' : 'Wyłączone')); ?></td>
                <td><?php if ($evk_ma && $evk_k->ID !== get_current_user_id()): ?>
                    <button type="button" class="button evk-2fa-reset" data-user="<?php echo (int) $evk_k->ID; ?>" data-nonce="<?php echo esc_attr(wp_create_nonce('evk_2fa_' . $evk_k->ID)); ?>">Wyłącz 2FA</button>
                <?php elseif ($evk_k->ID === get_current_user_id()): ?><a href="<?php echo esc_url(admin_url('profile.php#evk-2fa')); ?>">Twój profil</a><?php endif; ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <p class="evo-hint">Zgubiony telefon i kody: „Wyłącz 2FA” tutaj (inny administrator). Gdy dostęp stracił jedyny administrator — w wp-config.php dopisz
            <code>define('EVK_2FA_WYLACZ', true);</code>, zaloguj się hasłem, zresetuj 2FA i usuń stałą.</p>
    </div>
    <script>
    jQuery(function ($) {
        $('#evk-2fa-konta .evk-2fa-reset').on('click', function () {
            var b = $(this);
            if (!window.confirm('Wyłączyć logowanie dwuetapowe tego konta?')) return;
            $.post(ajaxurl, { action: 'evk_2fa', akcja: 'reset', user: b.data('user'), nonce: b.data('nonce') }).done(function (r) {
                if (r && r.success) window.location.reload(); else alert((r && r.data) || 'Błąd.');
            });
        });
    });
    </script>

    <?php $evk_dz = array_slice((array) get_option(EVK_2FA_DZIENNIK, []), 0, 10); if ($evk_dz): ?>
    <div class="evo-box evo-mt" id="evk-2fa-dziennik">
        <h3>Dziennik</h3>
        <div class="evo-tbl-wrap"><table class="evo-table">
            <thead><tr><th scope="col">Kiedy</th><th scope="col">Co</th><th scope="col">Konto</th><th scope="col">Kto</th></tr></thead>
            <tbody>
            <?php $evk_co = ['wlaczenie' => 'Włączenie', 'wylaczenie' => 'Wyłączenie', 'reset' => 'Reset przez administratora', 'nowe-kody' => 'Nowe kody zapasowe'];
            foreach ($evk_dz as $evk_w): $evk_a = get_userdata((int) $evk_w['konto']); $evk_b = get_userdata((int) $evk_w['kto']); ?>
            <tr><td><?php echo esc_html(wp_date('j.m.Y H:i', (int) $evk_w['czas'])); ?></td><td><?php echo esc_html($evk_co[$evk_w['co']] ?? (string) $evk_w['co']); ?></td>
                <td><?php echo esc_html($evk_a ? $evk_a->user_login : '#' . (int) $evk_w['konto']); ?></td><td><?php echo esc_html($evk_b ? $evk_b->user_login : '—'); ?></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

