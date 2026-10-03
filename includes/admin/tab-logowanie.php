<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — zakładka „Logowanie” (1.287.0): logowanie dwuetapowe.
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

<?php
/* Ukryty adres logowania (1.288.0). */
$evk_ua    = evk_ua_ustawienia();
$evk_ua_on = evk_ua_wlaczony();
$evk_ua_a  = $evk_ua['adres'] !== '' ? $evk_ua['adres'] : evk_ua_losuj();
$evk_ua_br = evk_ua_strona_bricks('login_page');
?>
<div class="evk-ua-panel evo-mt" id="evk-ua">
    <div class="evo-status-card">
        <div class="evo-status-icon <?php echo $evk_ua_on ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-hidden evo-ico-lg"></span>
        </div>
        <div class="evo-status-text">
            <h3>Ukryty adres logowania: <?php echo $evk_ua_on ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
            <p>wp-login.php i /wp-admin/ odpowiadają niezalogowanym stroną 404. Logujesz się przez własny adres<?php echo $evk_ua_br ? ' albo stronę logowania Bricksa' : ''; ?>.</p>
        </div>
        <div class="evo-status-actions">
            <label class="evo-toggle">
                <input aria-label="Ukryty adres logowania" type="checkbox" id="evk-ua-wlacz" value="1" <?php checked(!empty($evk_ua['enabled'])); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>

    <?php if (evk_ua_awaryjnie()): ?>
    <div class="evo-info-box evo-mt" role="alert"><span class="dashicons dashicons-warning"></span>
        <p class="evo-m0"><strong>Wyłączony awaryjnie stałą EVK_UKRYTY_ADRES_WYLACZ w wp-config.php.</strong> wp-login.php jest otwarty dla wszystkich. Usuń stałą, gdy tylko odzyskasz dostęp.</p></div>
    <?php endif; ?>

    <form id="evk-ua-form" class="evo-box evo-mt">
        <h3>Adres logowania</h3>
        <div class="evo-field">
            <label for="evk-ua-adres">Adres</label>
            <div class="evo-inline evk-ua-wiersz" style="--evo-gap:6px">
                <span class="evo-mono evk-ua-przed"><?php echo esc_html(preg_replace('#^https?://#', '', get_option('permalink_structure') ? home_url('/') : home_url('/?'))); ?></span>
                <input type="text" id="evk-ua-adres" name="adres" class="evo-mono" style="width:auto;flex:0 1 240px;min-width:0" value="<?php echo esc_attr($evk_ua_a); ?>" maxlength="50" autocomplete="off" spellcheck="false" pattern="[a-z0-9][a-z0-9\-]{2,49}">
                <button type="button" class="button" id="evk-ua-losuj">Losuj</button>
                <button type="button" class="button" id="evk-ua-kopiuj">Kopiuj adres</button>
            </div>
            <p class="evo-desc">Małe litery, cyfry i myślniki, 3–50 znaków. Adres otwiera stronę logowania na <?php echo (int) EVK_UA_MINUTY; ?> minut; po zalogowaniu ta przeglądarka trafia na nią do końca sesji. <strong>Zapisz go w menedżerze haseł</strong> — zmiana adresu unieważnia stary.</p>
        </div>
        <?php if ($evk_ua_br): ?>
        <p class="evo-hint">Strona logowania z ustawień Bricksa: <a href="<?php echo esc_url((string) get_permalink($evk_ua_br)); ?>"><?php echo esc_html(get_the_title($evk_ua_br)); ?></a> — działa jak dotąd, a linki „Zaloguj”, „Nie pamiętasz hasła?” i „Zarejestruj się” prowadzą na strony Bricksa.</p>
        <?php endif; ?>
        <p class="evo-hint">Bez wyjątku działają: formularze (admin-ajax.php, admin-post.php), hasło strony chronionej i linki z e-maili (reset hasła, potwierdzenie prośby o dane). Gdy adres zginie — w wp-config.php dopisz <code>define('EVK_UKRYTY_ADRES_WYLACZ', true);</code>, zaloguj się przez wp-login.php i usuń stałą.</p>
        <?php evoke_one_pasek_zapisu('Zapisz', false, 'evk-ua-zapisano'); ?>
        <p class="evo-desc evk-ua-blad" role="alert"></p>
    </form>
    <script>
    jQuery(function ($) {
        var $f = $('#evk-ua-form'), $a = $('#evk-ua-adres'), $w = $('#evk-ua-wlacz'), $b = $f.find('.evk-ua-blad');
        function zapisz(wlacz, potem) {
            $b.text(''); $f.find('.evk-ua-zapisano').removeClass('is-widoczny');
            return $.post(ajaxurl, { action: 'evk_ukryty_adres', nonce: <?php echo wp_json_encode(wp_create_nonce('evk_ukryty_adres')); ?>, adres: $a.val(), enabled: wlacz ? 1 : 0 })
                .done(function (r) { if (r && r.success) potem(r.data); else { $b.text((r && r.data) || 'Błąd zapisu.'); if (wlacz !== <?php echo $evk_ua['enabled'] ? 'true' : 'false'; ?>) $w.prop('checked', !wlacz); } });
        }
        $f.on('submit', function (e) { e.preventDefault(); zapisz($w.is(':checked'), function () { $f.find('.evk-ua-zapisano').addClass('is-widoczny'); }); });
        $w.on('change', function () {
            var wlacz = $w.is(':checked');
            if (wlacz && !window.confirm('Logowanie będzie tylko przez adres:\n' + $f.find('.evk-ua-przed').text() + $a.val() + '\n\nZapisz go. Włączyć?')) { $w.prop('checked', false); return; }
            zapisz(wlacz, function () { window.location.reload(); });
        });
        $('#evk-ua-losuj').on('click', function () {
            var z = 'abcdefghjkmnpqrstuvwxyz23456789', s = '', l = new Uint32Array(6);
            window.crypto.getRandomValues(l);
            for (var i = 0; i < 6; i++) s += z[l[i] % z.length];
            $a.val('panel-' + s).trigger('focus');
        });
        $('#evk-ua-kopiuj').on('click', function () {
            var t = <?php echo wp_json_encode(get_option('permalink_structure') ? home_url('/') : home_url('/?')); ?> + $a.val(), b = this;
            (navigator.clipboard ? navigator.clipboard.writeText(t) : Promise.reject()).then(function () { b.textContent = 'Skopiowano'; setTimeout(function () { b.textContent = 'Kopiuj adres'; }, 1500); }, function () { $a.trigger('select'); });
        });
    });
    </script>
</div>
