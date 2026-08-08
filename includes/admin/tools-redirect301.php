<?php if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Admin: Przekierowania 301
 */
$enabled   = evk_301_is_enabled();
$redirects = evk_301_get_all();
$nonce     = wp_create_nonce('evk_tools_nonce');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evk_301_toggle'])
    && check_admin_referer('evk_301_toggle_action')) {
    update_option('evk_301_enabled', !empty($_POST['evk_301_enabled']) ? 1 : 0);
    $enabled = evk_301_is_enabled();
}
?>
<div class="evo-status-card" style="margin-bottom:20px;">
    <div class="evo-status-icon <?php echo $enabled ? 'on' : 'off'; ?>">
        <span class="dashicons dashicons-redo" style="font-size:24px;width:24px;height:24px;line-height:1;"></span>
    </div>
    <div class="evo-status-text">
        <h3>Przekierowania 301: <?php echo $enabled ? 'AKTYWNE' : 'WYŁĄCZONE'; ?></h3>
        <p>Automatyczne przekierowania z licznikiem kliknięć. Obsługuje wildcards (<code>/stara/*</code>).</p>
    </div>
    <form method="post" style="display:contents;">
        <?php wp_nonce_field('evk_301_toggle_action'); ?>
        <input type="hidden" name="evk_301_toggle" value="1">
        <div class="evo-status-actions">
            <label class="evo-toggle">
                <?php /* bez data-option — zapis wyłącznie przez submit formularza,
                         wcześniej strzelał podwójnie (AJAX + POST) */ ?>
                <input type="checkbox" name="evk_301_enabled" value="1" <?php checked($enabled); ?> onchange="this.form.submit()">
                <span class="evo-slider"></span>
            </label>
        </div>
    </form>
</div>

<!-- Dodaj regułę -->
<div class="evo-box">
    <h3>Dodaj regułę</h3>
    <div style="display:flex;gap:10px;align-items:flex-start;margin-bottom:20px;flex-wrap:wrap;">
        <div class="evo-field" style="flex:1;min-width:200px;margin:0;">
            <label>Z (From)</label>
            <input type="text" id="evk-301-from" placeholder="/stara-strona" style="width:100%;">
            <div class="evo-desc">Relatywna ścieżka. Wildcard: <code>/stara/*</code></div>
        </div>
        <div class="evo-field" style="flex:1;min-width:200px;margin:0;">
            <label>Na (To)</label>
            <input type="text" id="evk-301-to" placeholder="/nowa-strona lub https://..." style="width:100%;">
        </div>
        <button type="button" class="button button-primary" id="evk-301-add" data-nonce="<?php echo esc_attr($nonce); ?>">Dodaj</button>
        <span id="evk-301-msg" style="font-size:13px;"></span>
    </div>

    <!-- Lista reguł -->
    <?php if (!empty($redirects)): ?>
</div>

<div class="evo-box">
    <h3>Aktywne reguły (<?php echo count($redirects); ?>)</h3>
    <div style="overflow-x:auto;">
    <table class="wp-list-table widefat striped" style="font-size:12px;">
        <thead><tr>
            <th>Z</th>
            <th>Na</th>
            <th style="width:80px;text-align:center;">Kliknięcia</th>
            <th style="width:130px;">Dodano</th>
            <th style="width:80px;">Akcja</th>
        </tr></thead>
        <tbody>
        <?php foreach ($redirects as $r): ?>
        <tr id="evk-301-row-<?php echo (int)$r['ID']; ?>">
            <td><code><?php echo esc_html($r['from']); ?></code></td>
            <td style="word-break:break-all;"><?php echo esc_html($r['to']); ?></td>
            <td style="text-align:center;font-weight:600;"><?php echo (int)$r['clicks']; ?></td>
            <td><?php echo esc_html($r['date']); ?></td>
            <td>
                <button type="button" class="button button-small evk-301-delete"
                    data-id="<?php echo (int)$r['ID']; ?>"
                    data-nonce="<?php echo esc_attr($nonce); ?>">Usuń</button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <div style="margin-top:10px;">
        <button type="button" class="button" id="evk-301-clear-logs" data-nonce="<?php echo esc_attr($nonce); ?>">Wyczyść logi przekierowań</button>
    </div>
    <?php else: ?>
    <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>Brak zdefiniowanych reguł przekierowań.</div></div>
    <?php endif; ?>

    <script>
    (function($){
        $('#evk-301-add').on('click', function(){
            var from = $('#evk-301-from').val();
            var to   = $('#evk-301-to').val();
            if (!from || !to){ $('#evk-301-msg').text('Uzupełnij oba pola.').css('color','#dc2626'); return; }
            $.post(ajaxurl, {action:'evk_301_save', nonce:$(this).data('nonce'), from:from, to:to}, function(r){
                if (r.success){ location.reload(); }
                else { $('#evk-301-msg').text(r.data||'Błąd').css('color','#dc2626'); }
            });
        });

        $(document).on('click', '.evk-301-delete', function(){
            if (!confirm('Usunąć tę regułę?')) return;
            var id=$(this).data('id'), nonce=$(this).data('nonce'), row=$('#evk-301-row-'+id);
            $.post(ajaxurl, {action:'evk_301_delete', nonce:nonce, id:id}, function(r){
                if (r.success) row.fadeOut(300, function(){ $(this).remove(); });
            });
        });

        $('#evk-301-clear-logs').on('click', function(){
            if (!confirm('Wyczyścić logi przekierowań?')) return;
            $.post(ajaxurl, {action:'evk_301_clear_logs', nonce:$(this).data('nonce')}, function(r){
                if (r.success) alert('Logi wyczyszczone.');
            });
        });
    })(jQuery);
    </script>
</div>

