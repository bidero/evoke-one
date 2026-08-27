<?php if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Admin: Logi 404
 */

// Obsługa formularza
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evk_404_save'])
    && check_admin_referer('evk_404_save_action')) {
    update_option('evk_404_enabled',   !empty($_POST['evk_404_enabled'])   ? 1 : 0);
    update_option('evk_404_max_logs',  max(10, absint($_POST['evk_404_max_logs']  ?? 200)));
    update_option('evk_404_skip_bots', !empty($_POST['evk_404_skip_bots']) ? 1 : 0);
    update_option('evk_404_bot_list',  sanitize_textarea_field($_POST['evk_404_bot_list'] ?? ''));
    echo '<div class="updated notice is-dismissible"><p>Zapisano.</p></div>';
}

$enabled   = evk_404_is_enabled();
$max_logs  = evk_404_max_logs();
$skip_bots = evk_404_skip_bots();
$bot_list  = implode("\n", evk_404_bot_list());
$nonce_ajax = wp_create_nonce('evk_tools_nonce');
?>

<!-- Status card -->
<div class="evo-status-card">
    <div class="evo-status-icon <?php echo $enabled ? 'on' : 'off'; ?>">
        <span class="dashicons dashicons-warning evo-ico-lg"></span>
    </div>
    <div class="evo-status-text">
        <h3>Logi 404: <?php echo $enabled ? 'WŁĄCZONE' : 'WYŁĄCZONE'; ?></h3>
        <p>Rejestruje nieistniejące adresy URL z datą, IP i referrerem.</p>
    </div>
    <div class="evo-status-actions">
        <form method="post" class="evo-contents">
            <?php wp_nonce_field('evk_404_save_action'); ?>
            <input type="hidden" name="evk_404_save" value="1">
            <input type="hidden" name="evk_404_enabled" value="<?php echo $enabled ? '0' : '1'; ?>">
            <input type="hidden" name="evk_404_max_logs" value="<?php echo esc_attr($max_logs); ?>">
            <input type="hidden" name="evk_404_skip_bots" value="<?php echo $skip_bots ? '1' : '0'; ?>">
            <input type="hidden" name="evk_404_bot_list" value="<?php echo esc_attr($bot_list); ?>">
            <label class="evo-toggle">
                <input type="checkbox" <?php checked($enabled); ?> onchange="this.form.elements['evk_404_enabled'].value=this.checked?'1':'0';this.form.submit()">
                <span class="evo-slider"></span>
            </label>
        </form>
    </div>
</div>

<!-- Ustawienia -->
<form method="post" class="evo-mt-lg">
    <?php wp_nonce_field('evk_404_save_action'); ?>
    <input type="hidden" name="evk_404_save" value="1">
    <?php /* Stan włącznika przekazywany jawnie — bez tego pola zapis ustawień
             wyłączał logi 404 (handler zerował brakujący klucz w POST). */ ?>
    <input type="hidden" name="evk_404_enabled" value="<?php echo $enabled ? '1' : '0'; ?>">

    <div class="evo-toolbar evo-mb" style="--evo-gap:24px">
        <div class="evo-field evo-inline evo-m0" style="--evo-gap:8px">
            <label class="evo-nowrap evo-m0">Maks. logów:</label>
            <input type="number" name="evk_404_max_logs" value="<?php echo esc_attr($max_logs); ?>" min="10" max="5000" class="evo-w" style="--evo-w:90px">
        </div>
        <label class="evo-check">
            <input type="checkbox" name="evk_404_skip_bots" value="1" <?php checked($skip_bots); ?>>
            Ignoruj boty / roboty
        </label>
    </div>

    <?php if ($skip_bots): ?>
    <div class="evo-field">
        <label>Lista botów (jeden per linia)</label>
        <textarea name="evk_404_bot_list" rows="4" class="evo-w-full evo-mono evo-tbl-sm"><?php echo esc_textarea($bot_list); ?></textarea>
    </div>
    <?php endif; ?>

    <div class="evo-save-bar evo-toolbar evo-mt" style="--evo-gap:12px">
        <?php submit_button('Zapisz ustawienia', 'secondary', 'evk_404_save', false); ?>
        <button type="button" class="button" id="evk-clear-404" data-nonce="<?php echo esc_attr($nonce_ajax); ?>">🗑 Wyczyść wszystkie logi</button>
        <span id="evk-clear-404-msg" class="evo-save-msg">Wyczyszczono.</span>
    </div>
</form>

<?php
$logs = get_posts([
    'post_type'      => 'evk_404_log',
    'posts_per_page' => $max_logs,
    'orderby'        => 'date',
    'order'          => 'DESC',
    'post_status'    => 'publish',
]);
if (!empty($logs)):
?>
<div class="evo-box">
    <h3>Zarejestrowane błędy 404 <span class="evo-hint">(<?php echo count($logs); ?>)</span></h3>
    <div class="evo-tbl-wrap">
    <table class="wp-list-table widefat striped evo-tbl-sm">
        <thead><tr>
            <th class="evo-w" style="--evo-w:140px">Czas</th>
            <th>URL</th>
            <th>Referrer</th>
            <th class="evo-w" style="--evo-w:110px">IP</th>
            <th>User Agent</th>
        </tr></thead>
        <tbody>
        <?php foreach ($logs as $log):
            $m = get_post_meta($log->ID);
        ?>
        <tr>
            <td class="evo-nowrap"><?php echo esc_html($m['logged_at'][0] ?? ''); ?></td>
            <td><code class="evo-mono-xs evo-break"><?php echo esc_html($m['url'][0] ?? ''); ?></code></td>
            <td class="evo-hint-sm"><?php echo esc_html($m['referrer'][0] ?? '—'); ?></td>
            <td>
                <a href="https://radar.cloudflare.com/ip/<?php echo esc_attr($m['ip'][0] ?? ''); ?>" target="_blank" class="evo-hint-sm">
                    <?php echo esc_html($m['ip'][0] ?? '—'); ?>
                </a>
            </td>
            <td class="evo-hint-sm evo-ellipsis evo-w" style="--evo-w:280px"
                title="<?php echo esc_attr($m['ua'][0] ?? ''); ?>">
                <?php echo esc_html($m['ua'][0] ?? '—'); ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php elseif ($enabled): ?>
<div class="evo-info-box evo-mt-lg">
    <span class="dashicons dashicons-yes-alt"></span>
    <div>Brak zarejestrowanych błędów 404. Pojawią się tutaj gdy ktoś wejdzie na nieistniejący URL.</div>
</div>
<?php endif; ?>

<script>
(function($){
    $('#evk-clear-404').on('click', function(){
        if (!confirm('Wyczyścić wszystkie logi 404?')) return;
        var btn = $(this).prop('disabled', true).text('...');
        $.post(ajaxurl, {action:'evk_clear_404_logs', nonce:$(this).data('nonce')}, function(r){
            if (r.success){
                $('table.wp-list-table tbody').empty();
                $('#evk-clear-404-msg').show();
                // Cała sekcja jest jednym boksem, więc chowamy boks — wcześniej
                // trzeba było zgadywać jego części z osobna (tabela, kreska,
                // tytuł) i przy zmianie znaczników się rozjeżdżało.
                $('table.wp-list-table').closest('.evo-box').hide();
            }
        }).always(function(){ btn.prop('disabled', false).text('🗑 Wyczyść wszystkie logi'); });
    });
})(jQuery);
</script>
