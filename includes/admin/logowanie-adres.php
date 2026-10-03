<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Logowanie › Ukryty adres (1.288.0).
 */
$evk_ua    = evk_ua_ustawienia();
$evk_ua_on = evk_ua_wlaczony();
$evk_ua_a  = $evk_ua['adres'] !== '' ? $evk_ua['adres'] : evk_ua_losuj();
$evk_ua_br = evk_ua_strona_bricks('login_page');
?>
<div class="evk-ua-panel" id="evk-ua">
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
        <p class="evo-hint">Bez wyjątku działają: formularze (admin-ajax.php, admin-post.php), hasło strony chronionej i linki z e-maili (reset hasła, potwierdzenie prośby o dane, tryb odzyskiwania po błędzie krytycznym). Gdy adres zginie — w wp-config.php dopisz <code>define('EVK_UKRYTY_ADRES_WYLACZ', true);</code>, zaloguj się przez wp-login.php i usuń stałą.</p>
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
