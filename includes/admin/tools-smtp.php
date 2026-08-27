<?php if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Admin: SMTP
 */
$s   = evk_smtp_get();
$log = (array) get_option('evk_smtp_log', []);
$nonce = wp_create_nonce('evk_tools_nonce');
?>
<form method="post" action="options.php">
<?php settings_fields('evk_smtp_settings'); ?>

<div class="evo-status-card">
    <div class="evo-status-icon <?php echo !empty($s['enabled']) ? 'on' : 'off'; ?>">
        <span class="dashicons dashicons-email-alt evo-ico-lg"></span>
    </div>
    <div class="evo-status-text">
        <h3>SMTP: <?php echo !empty($s['enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
        <p>Własna konfiguracja wysyłki maili zamiast domyślnej funkcji PHP mail().</p>
    </div>
    <div class="evo-status-actions">
        <label class="evo-toggle">
            <input type="checkbox" data-option="evk_smtp" data-field="enabled" value="1" <?php checked(1, $s['enabled']); ?>>
            <span class="evo-slider"></span>
        </label>
    </div>
</div>

<div class="evo-grid-2 evo-mt-lg evo-mb-lg" style="--evo-gap:16px">
    <div class="evo-field">
        <label>Host SMTP</label>
        <input type="text" name="evk_smtp[host]" value="<?php echo esc_attr($s['host']); ?>" placeholder="smtp.gmail.com">
    </div>
    <div class="evo-field">
        <label>Port</label>
        <input type="number" name="evk_smtp[port]" value="<?php echo esc_attr($s['port']); ?>" min="1" max="65535">
    </div>
    <div class="evo-field">
        <label>Szyfrowanie</label>
        <select name="evk_smtp[encryption]">
            <?php foreach (['tls' => 'TLS (zalecane)', 'ssl' => 'SSL', 'none' => 'Brak'] as $val => $label): ?>
            <option value="<?php echo $val; ?>" <?php selected($s['encryption'], $val); ?>><?php echo $label; ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="evo-field">
        <label>Nazwa nadawcy</label>
        <input type="text" name="evk_smtp[from_name]" value="<?php echo esc_attr($s['from_name']); ?>" placeholder="<?php echo esc_attr(get_bloginfo('name')); ?>">
    </div>
    <div class="evo-field">
        <label>Użytkownik SMTP</label>
        <input type="text" name="evk_smtp[username]" value="<?php echo esc_attr($s['username']); ?>" autocomplete="off">
    </div>
    <div class="evo-field">
        <label>Hasło / API Key</label>
        <input type="password" name="evk_smtp[password]" value="" placeholder="<?php echo !empty($s['password']) ? '••••••••' : 'Wpisz hasło'; ?>" autocomplete="new-password">
        <div class="evo-desc">Zostaw puste aby zachować aktualne hasło.</div>
    </div>
    <div class="evo-field">
        <label>Email nadawcy (From)</label>
        <input type="email" name="evk_smtp[from_email]" value="<?php echo esc_attr($s['from_email']); ?>" placeholder="noreply@twojadomena.pl">
    </div>
</div>

<div class="evo-box">
    <h3>Logi maili</h3>
    <div class="evo-toolbar evo-mb-lg" style="--evo-gap:20px">
        <label class="evo-check">
            <input type="checkbox" name="evk_smtp[log_enabled]" value="1" <?php checked(1, $s['log_enabled']); ?>>
            Włącz logowanie wysłanych maili
        </label>
        <div class="evo-field evo-inline evo-m0" style="--evo-gap:8px">
            <label class="evo-nowrap evo-m0">Maks. logów:</label>
            <input type="number" name="evk_smtp[log_max]" value="<?php echo esc_attr($s['log_max']); ?>" min="10" max="1000" class="evo-w" style="--evo-w:80px">
        </div>
    </div>

    <div class="evo-save-bar evo-toolbar" style="--evo-gap:16px">
        <?php submit_button('Zapisz SMTP', 'primary', 'submit', false); ?>
        <div class="evo-inline" style="--evo-gap:8px">
            <input type="email" id="evk-smtp-test-email" placeholder="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text evo-w" style="--evo-w:240px">
            <button type="button" class="button" id="evk-smtp-test" data-nonce="<?php echo esc_attr($nonce); ?>">Wyślij testowy mail</button>
            <span id="evk-smtp-test-result" class="evo-note-tx"></span>
        </div>
    </div>
</div>

</form>

<?php if (!empty($log)): ?>
<hr class="evo-divider">
<div class="evo-box">
    <h3>Log maili (<?php echo count($log); ?>)</h3>
    <div class="evo-toolbar evo-mb-sm">
        <button type="button" class="button button-small" id="evk-smtp-clear-log" data-nonce="<?php echo esc_attr($nonce); ?>">Wyczyść logi</button>
    </div>
    <div class="evo-tbl-wrap">
        <table class="wp-list-table widefat striped evo-tbl-sm">
            <thead><tr>
                <th class="evo-w" style="--evo-w:140px">Czas</th>
                <th>Do</th>
                <th>Temat</th>
                <th class="evo-w" style="--evo-w:80px">Status</th>
                <th>Błąd</th>
            </tr></thead>
            <tbody>
            <?php foreach ($log as $entry): ?>
            <tr>
                <td><?php echo esc_html($entry['time'] ?? ''); ?></td>
                <td><?php echo esc_html($entry['to']   ?? ''); ?></td>
                <td><?php echo esc_html($entry['subject'] ?? ''); ?></td>
                <td>
                    <?php if (!empty($entry['success'])): ?>
                    <span class="evo-ok-tx evo-strong">✓ OK</span>
                    <?php else: ?>
                    <span class="evo-danger-tx evo-strong">✗ Błąd</span>
                    <?php endif; ?>
                </td>
                <td class="evo-hint-sm"><?php echo esc_html($entry['error'] ?? ''); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
(function($){
    $('#evk-smtp-test').on('click', function(){
        var btn = $(this), email = $('#evk-smtp-test-email').val(), res = $('#evk-smtp-test-result');
        btn.prop('disabled', true).text('Wysyłanie...');
        $.post(ajaxurl, {action:'evk_smtp_test', nonce:$(this).data('nonce'), email:email}, function(r){
            // Kolor niesie KLASA, nie hex w skrypcie — inaczej omija paletę
            // dokładnie tak jak `style=`, tylko trudniej go znaleźć.
            res.text(r.success ? '✓ Mail wysłany do ' + r.to : '✗ Błąd wysyłki')
               .removeClass('evo-ok-tx evo-danger-tx')
               .addClass(r.success ? 'evo-ok-tx' : 'evo-danger-tx');
        }).always(function(){ btn.prop('disabled', false).text('Wyślij testowy mail'); });
    });
    $('#evk-smtp-clear-log').on('click', function(){
        if (!confirm('Wyczyścić wszystkie logi?')) return;
        $.post(ajaxurl, {action:'evk_smtp_clear_log', nonce:$(this).data('nonce')}, function(){location.reload();});
    });
})(jQuery);
</script>
