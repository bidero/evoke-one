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
            <span class="dashicons dashicons-lock" style="font-size:24px;width:24px;height:24px;line-height:1;"></span>
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

    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:0;">
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
        <div class="evo-field" style="grid-column:1/-1;">
            <label>Własny komunikat blokady IP</label>
            <textarea name="evk_security[limit_login_message]" rows="4" style="width:100%;max-width:100%;" placeholder="Pozostaw puste aby użyć domyślnego komunikatu z czasem odblokowania..."><?php echo esc_textarea($evk_sec['limit_login_message'] ?? ''); ?></textarea>
            <div class="evo-desc">HTML dozwolony: &lt;strong&gt; &lt;em&gt; &lt;a&gt; &lt;p&gt;. Zmienne: <code>{hours}</code> — liczba godzin, <code>{hours_str}</code> — odmiana słowa. Gdy puste — używany domyślny komunikat.</div>
        </div>
    </div>

    <div class="evo-save-bar"><button type="submit" class="button button-primary">Zapisz</button><span class="evk-sec-saved" style="margin-left:10px;font-size:13px;color:#047857;display:none;">✓ Zapisano</span></div>
</form>

<?php if (!empty($active_blocks)): ?>
<hr class="evo-divider">
<p class="evo-section-title">
    Aktywne blokady
    <span style="font-weight:400;color:#6b7280;font-size:12px;margin-left:8px;"><?php echo count($active_blocks); ?> aktywnych</span>
</p>
<div style="background:#fff;border:1px solid #d1d5db;border-radius:6px;overflow:hidden;margin-bottom:16px;">
    <table class="wp-list-table widefat fixed striped" style="border:none;">
        <thead><tr>
            <th>Adres IP</th><th>Użytkownik</th><th>Prób</th>
            <th>Zablokowano</th><th>Wygasa</th><th style="width:100px;">Akcja</th>
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
<span id="evk-blocks-status" style="margin-left:10px;font-size:13px;color:#047857;display:none;"></span>
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
<?php endif; ?>
