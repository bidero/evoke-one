<?php if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Admin: Przekierowania 301
 */
/*
 * WŁĄCZNIK JEDZIE AJAX-em, jak wszystkie pozostałe w panelu (1.166.0).
 *
 * Do 1.14.4 miał JEDNOCZEŚNIE `data-option` i `onchange="this.form.submit()"`,
 * czyli DWA niezależne sterowniki na jedno kliknięcie: uchwyt AJAX zapisywał
 * opcję, a formularz zaraz potem przeładowywał stronę i zapisywał ją drugi raz.
 * Naprawiono to wtedy przez zdjęcie AJAX-a — i tak zostało, jako jedyny moduł
 * przełączany przeładowaniem.
 *
 * Teraz jest odwrotnie i tak samo jednoznacznie: został sam AJAX, a formularz
 * i jego uchwyt POST poszły. Nie ma czego zdublować, bo nie ma drugiej drogi.
 * Pilnuje tego sprawdzenie liczące żądania na jedno kliknięcie.
 */
$enabled   = evk_301_is_enabled();
$redirects = evk_301_get_all();
$nonce     = wp_create_nonce('evk_tools_nonce');
?>
<div class="evo-status-card">
    <div class="evo-status-icon <?php echo $enabled ? 'on' : 'off'; ?>">
        <span class="dashicons dashicons-redo evo-ico-lg"></span>
    </div>
    <div class="evo-status-text">
        <h3>Przekierowania 301: <?php echo $enabled ? 'AKTYWNE' : 'WYŁĄCZONE'; ?></h3>
        <p>Automatyczne przekierowania z licznikiem kliknięć. Obsługuje wildcards (<code>/stara/*</code>).</p>
    </div>
    <div class="evo-status-actions">
        <span class="evo-toggle-label"><?php echo $enabled ? 'Włączone' : 'Wyłączone'; ?></span>
        <label class="evo-toggle">
            <input type="checkbox"
                   data-option="evk_301_enabled"
                   data-field="_scalar"
                   value="1"
                   <?php checked($enabled); ?>>
            <span class="evo-slider"></span>
        </label>
    </div>
</div>

<!-- Dodaj regułę -->
<div class="evo-box">
    <h3>Dodaj regułę</h3>
    <div class="evo-toolbar evo-toolbar-top evo-mb-lg">
        <div class="evo-field evo-grow evo-m0" style="--evo-min:200px">
            <label>Z (From)</label>
            <input type="text" id="evk-301-from" placeholder="/stara-strona" class="evo-w-full">
            <div class="evo-desc">Relatywna ścieżka. Wildcard: <code>/stara/*</code></div>
        </div>
        <div class="evo-field evo-grow evo-m0" style="--evo-min:200px">
            <label>Na (To)</label>
            <input type="text" id="evk-301-to" placeholder="/nowa-strona lub https://..." class="evo-w-full">
        </div>
        <button type="button" class="button button-primary" id="evk-301-add" data-nonce="<?php echo esc_attr($nonce); ?>">Dodaj</button>
        <span id="evk-301-msg" class="evo-note-tx"></span>
    </div>

    <!-- Lista reguł -->
    <?php if (!empty($redirects)): ?>
</div>

<div class="evo-box">
    <h3>Aktywne reguły (<?php echo count($redirects); ?>)</h3>
    <div class="evo-tbl-wrap">
    <table class="evo-tbl evo-tbl-sm">
        <thead><tr>
            <th>Z</th>
            <th>Na</th>
            <th class="evo-w evo-center" style="--evo-w:80px">Kliknięcia</th>
            <th class="evo-w" style="--evo-w:130px">Dodano</th>
            <th class="evo-w" style="--evo-w:80px">Akcja</th>
        </tr></thead>
        <tbody>
        <?php foreach ($redirects as $r): ?>
        <tr id="evk-301-row-<?php echo (int)$r['ID']; ?>">
            <td><code><?php echo esc_html($r['from']); ?></code></td>
            <td class="evo-break"><?php echo esc_html($r['to']); ?></td>
            <td class="evo-center evo-strong"><?php echo (int)$r['clicks']; ?></td>
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
    <div class="evo-mt-xs">
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

