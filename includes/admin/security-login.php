<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Bezpieczeństwo: Limit logowań
 */

$active_blocks = evk_login_active_blocks();
?>
<form id="evk-sec-form-login" data-section="login">

    <div class="evo-status-card">
        <div class="evo-status-icon <?php echo !empty($evk_sec['limit_login_enabled']) ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-lock evo-ico-lg"></span>
        </div>
        <div class="evo-status-text">
            <h3>Limit prób logowania: <?php echo !empty($evk_sec['limit_login_enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
            <p>Automatycznie blokuje adresy IP po przekroczeniu limitu nieudanych prób logowania.</p>
        </div>
        <div class="evo-status-actions">
            <label class="evo-toggle">
                <input type="checkbox" name="evk_security[limit_login_enabled]" data-option="evk_security" data-field="limit_login_enabled" value="1" <?php checked(1, $evk_sec['limit_login_enabled']); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>

    <div class="evo-grid" style="--evo-col:200px;--evo-gap:16px">
        <div class="evo-field">
            <label>Maks. prób logowania</label>
            <input type="number" name="evk_security[max_attempts]" value="<?php echo esc_attr($evk_sec['max_attempts']); ?>" min="1" max="100">
            <div class="evo-desc">Domyślnie: 5</div>
        </div>
        <div class="evo-field">
            <label>Resetuj po (godzinach)</label>
            <input type="number" name="evk_security[reset_hours]" value="<?php echo esc_attr($evk_sec['reset_hours']); ?>" min="1" max="720">
            <div class="evo-desc">Domyślnie: 24</div>
        </div>
        <div class="evo-field evo-full">
            <label>Własny komunikat blokady IP<span class="evo-tip" tabindex="0" role="note" data-tip="HTML dozwolony: &lt;strong&gt; &lt;em&gt; &lt;a&gt; &lt;p&gt;. Zmienne: {hours} — liczba godzin, {hours_str} — odmiana słowa. Gdy puste — używany domyślny komunikat." aria-label="HTML dozwolony: &lt;strong&gt; &lt;em&gt; &lt;a&gt; &lt;p&gt;. Zmienne: {hours} — liczba godzin, {hours_str} — odmiana słowa. Gdy puste — używany domyślny komunikat.">?</span></label>
            <textarea name="evk_security[limit_login_message]" rows="4" placeholder="Pozostaw puste aby użyć domyślnego komunikatu z czasem odblokowania..."><?php echo esc_textarea($evk_sec['limit_login_message'] ?? ''); ?></textarea>

        </div>
    </div>

    <?php /* Adres odwiedzających (1.233.0, includes/security/ip-klienta.php).
             W TYM formularzu, nie w osobnym: jeden pasek zapisu na ekranie,
             a limit logowań to miejsce, gdzie zły adres boli najbardziej —
             blokada węzła Cloudflare odcina wszystkich naraz. */
    $evk_ip = evk_ip_diagnoza();
    $evk_ip_tryby = ['brak' => 'Brak — połączenie wprost', 'cloudflare' => 'Cloudflare', 'inne' => 'Inny pośrednik (np. load balancer)']; ?>
    <div class="evo-box" data-evk-proxy>
        <h3>Adres IP odwiedzających</h3>
        <p class="evo-hint">Z tego adresu korzystają limit logowań, newsletter (zapis zgody i limit zapisów) i logi 404. Za Cloudflare albo innym pośrednikiem serwer widzi adres pośrednika, a nie odwiedzającego.</p>
        <?php if ($evk_ip['przez_cloudflare'] && $evk_ip['tryb'] !== 'cloudflare'): ?>
        <div class="evo-info-box is-warn evo-mb" data-evk-proxy-ostrzezenie>
            <span class="dashicons dashicons-warning evo-warn-tx"></span>
            <div><strong>Ta strona stoi za Cloudflare.</strong> Twoje żądanie przyszło z węzła Cloudflare (<code><?php echo esc_html($evk_ip['zdalny']); ?></code>), więc bez ustawienia „Cloudflare" wtyczka widzi kilka tych samych adresów dla wszystkich odwiedzających — limit logowań zablokowałby ich naraz.</div>
        </div>
        <?php endif; ?>
        <div class="evo-grid" style="--evo-col:200px;--evo-gap:16px">
            <div class="evo-field">
                <label for="evk-proxy-tryb">Pośrednik przed stroną</label>
                <select id="evk-proxy-tryb" name="evk_security[proxy_tryb]">
                    <?php foreach ($evk_ip_tryby as $wartosc => $etykieta): ?>
                    <option value="<?php echo esc_attr($wartosc); ?>" <?php selected($evk_sec['proxy_tryb'] ?? 'brak', $wartosc); ?>><?php echo esc_html($etykieta); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="evo-field evo-full">
                <label for="evk-proxy-zaufane">Dodatkowe zaufane adresy pośrednika</label>
                <textarea id="evk-proxy-zaufane" name="evk_security[proxy_zaufane]" rows="3" placeholder="10.0.0.5&#10;192.168.0.0/16"><?php echo esc_textarea($evk_sec['proxy_zaufane'] ?? ''); ?></textarea>
                <div class="evo-desc">Adres albo sieć (CIDR), po jednym na linię. Przy „Cloudflare" tylko wtedy, gdy między Cloudflare a stroną stoi jeszcze coś (sieci Cloudflare wtyczka zna sama). Przy „Inny pośrednik" — adresy tego pośrednika: nagłówek X-Forwarded-For liczy się tylko od nich, bo od każdego innego mógłby go podrobić sam odwiedzający.</div>
            </div>
        </div>
        <div class="evo-tbl-wrap evo-mt">
            <table class="evo-tbl" data-evk-proxy-diagnoza>
                <caption class="evo-hint">Twoje obecne połączenie</caption>
                <tbody>
                    <tr><th scope="row">Adres połączenia</th><td><code><?php echo esc_html($evk_ip['zdalny'] ?: '—'); ?></code><?php echo $evk_ip['przez_cloudflare'] ? ' (sieć Cloudflare)' : ''; ?></td></tr>
                    <tr><th scope="row">Nagłówek CF-Connecting-IP</th><td><code><?php echo esc_html($evk_ip['cf_naglowek'] ?: '—'); ?></code></td></tr>
                    <tr><th scope="row">Nagłówek X-Forwarded-For</th><td><code><?php echo esc_html($evk_ip['xff'] ?: '—'); ?></code></td></tr>
                    <?php foreach ($evk_ip_tryby as $wartosc => $etykieta): ?>
                    <tr<?php echo $wartosc === $evk_ip['tryb'] ? ' class="is-current"' : ''; ?>><th scope="row">Twój adres przy „<?php echo esc_html($etykieta); ?>"</th><td><code><?php echo esc_html($evk_ip['wg_trybu'][$wartosc] ?: '—'); ?></code><?php echo $wartosc === $evk_ip['tryb'] ? ' ← teraz' : ''; ?></td></tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php evoke_one_pasek_zapisu('Zapisz', false, 'evk-sec-saved'); ?>
</form>

<?php if (!empty($active_blocks)): ?>
<div class="evo-box">
    <h3>
    Aktywne blokady
    <span class="evo-hint evo-ml"><?php echo count($active_blocks); ?> aktywnych</span>
</h3>
    <?php /* `.evo-tbl-wrap`, nie `.evo-tbl-frame`. Ta druga rysowała WŁASNĄ ramkę
             wokół tabeli i zdejmowała ramkę z niej samej — trzeci sposób na to
             samo, w dodatku z innym promieniem (6 px zamiast 8) i innym
             kolorem. Używał go jeden ekran w całym panelu. Ramkę rysuje
             `.evo-tbl`, a owijce zostaje to, po co naprawdę jest: przewijanie
             w poziomie. */ ?>
    <div class="evo-tbl-wrap evo-mb">
        <table class="evo-tbl evo-tbl-fixed">
            <thead><tr>
                <th>Adres IP</th><th>Użytkownik</th><th>Prób</th>
                <th>Zablokowano</th><th>Wygasa</th><th class="evo-w" style="--evo-w:100px">Akcja</th>
            </tr></thead>
            <tbody>
            <?php foreach ($active_blocks as $ip => $data):
                $expires_at = $data['blocked_at'] + $evk_sec['reset_hours'] * HOUR_IN_SECONDS;
                $hours_left = max(0, ceil(($expires_at - current_time('timestamp')) / HOUR_IN_SECONDS));
            ?>
            <tr id="evk-block-row-<?php echo esc_attr(md5($ip)); ?>">
                <td><code><?php echo esc_html($ip); ?></code></td>
                <td><?php echo esc_html($data['username'] ?: '—'); ?></td>
                <td><?php echo (int)$data['attempts']; ?></td>
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
    <span id="evk-blocks-status" class="evo-save-msg evo-ml"></span>
    <script>
    (function ($) {
        $(document).on('click', '.evk-unblock-btn', function () {
            var btn = this, ip = $(btn).data('ip'), row = '#' + $(btn).data('row');
            $(btn).prop('disabled', true).text('...');
            $.post(ajaxurl, { action: 'evk_unblock_ip', nonce: $(btn).data('nonce'), ip: ip })
                .done(function (r) {
                    if (r.success) $(row).fadeOut(300, function () { $(this).remove(); });
                    else alert(r.data || 'Błąd');
                });
        });
        $('#evk-clear-all-blocks').on('click', function () {
            if (!confirm('Odblokować wszystkie?')) return;
            $(this).prop('disabled', true);
            $.post(ajaxurl, { action: 'evk_clear_all_blocks', nonce: $(this).data('nonce') })
                .done(function (r) {
                    if (r.success) {
                        $('tr[id^="evk-block-row-"]').fadeOut(300, function () { $(this).remove(); });
                        $('#evk-blocks-status').text('Wyczyszczono.').show();
                    }
                });
        });
    })(jQuery);
    </script>
</div>
<?php endif; ?>

