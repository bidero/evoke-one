<?php
if (!defined('ABSPATH')) exit;

$campaigns = evk_nl_get_campaigns();
$templates = evk_nl_get_templates();
$lists     = evk_nl_get_lists();
$nonce     = wp_create_nonce('evk_nl_nonce');
$edit_id   = (int) ($_GET['campaign_id'] ?? 0);
$edit_camp = $edit_id ? evk_nl_get_campaign($edit_id) : null;

$camp_lists     = json_decode($edit_camp['lists_json']    ?? '[]', true) ?: [];
$camp_tpl_id    = (int) ($edit_camp['template_id']        ?? 0);
$camp_scheduled = $edit_camp['scheduled_at']              ?? '';
$camp_batch     = (int) ($edit_camp['batch_size']         ?? 50);
$camp_interval  = (int) ($edit_camp['batch_interval']     ?? 5);
$camp_tracking  = $edit_camp ? !empty($edit_camp['tracking_enabled']) : true; // nowa kampania = śledzenie domyślnie ON
$st             = $edit_camp['status']                    ?? '';

$status_labels = [
    'draft'     => ['label' => 'Szkic',       'color' => '#94a3b8'],
    'scheduled' => ['label' => 'Zaplanowana', 'color' => '#f59e0b'],
    'sending'   => ['label' => 'Wysyłanie',   'color' => '#2563eb'],
    'paused'    => ['label' => 'Pauza',       'color' => '#f97316'],
    'done'      => ['label' => 'Zakończona',  'color' => '#16a34a'],
    'cancelled' => ['label' => 'Anulowana',   'color' => '#dc2626'],
];
?>
<style>
.evk-nl-grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
#evk-nl-modal{display:none;position:fixed;inset:0;z-index:100000;align-items:center;justify-content:center;}
#evk-nl-modal-backdrop{position:absolute;inset:0;background:rgba(15,23,42,.6);}
.evk-nl-modal-box{position:relative;background:#fff;border-radius:12px;width:min(920px,94vw);max-height:88vh;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.3);}
.evk-nl-modal-head{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid #e2e8f0;}
.evk-nl-modal-head h2{margin:0;font-size:16px;}
#evk-nl-modal-close{background:none;border:0;font-size:24px;line-height:1;cursor:pointer;color:#64748b;}
.evk-nl-modal-body{padding:18px;overflow:auto;}
.evk-nl-rcp-tools{margin-bottom:12px;}
.evk-nl-rcp-pager{margin-top:12px;font-size:12px;color:#64748b;display:flex;align-items:center;gap:10px;}
.evk-nl-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:20px;}
.evk-nl-label{display:block;margin-bottom:5px;font-size:12px;font-weight:600;color:#374151;}
.evk-nl-label-mt{margin-top:14px;}
.evk-nl-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:16px;}
.evk-nl-btn-icon{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;padding:0;min-height:28px;border-radius:7px;cursor:pointer;border:1px solid #cbd5e1;background:#f8fafc;text-decoration:none;}
.evk-nl-btn-icon:hover{background:#f0f0f1;border-color:#8c8f94;}
.evk-nl-btn-icon .dashicons{font-size:14px;width:14px;height:14px;line-height:1;}
.evk-nl-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.evk-nl-tbl th,.evk-nl-tbl td{padding:8px 10px;text-align:left;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.evk-nl-tbl th{background:#f8fafc;font-weight:600;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;}
.evk-nl-tbl tr:last-child td{border-bottom:none;}
.evk-nl-tbl tr:hover td{background:#f8fafc;}
.evk-nl-badge{display:inline-flex;align-items:center;font-size:11px;padding:2px 8px;border-radius:99px;font-weight:600;white-space:nowrap;}
.evk-nl-bulk-bar{display:none;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;padding:8px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;}
.evk-nl-tbl-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
@media(max-width:782px){
    .evk-nl-grid2{grid-template-columns:1fr;}
    .evk-nl-card{padding:14px;}
    .evk-nl-tbl th.evk-col-hide,.evk-nl-tbl td.evk-col-hide{display:none;}
    .evk-nl-actions{gap:4px;}
}
</style>

<!-- Formularz kampanii -->
<div class="evk-nl-card">
    <h3 style="margin:0 0 14px;font-size:15px;">
        <?php echo $edit_camp ? 'Edytuj: <strong>' . esc_html($edit_camp['name']) . '</strong>' : 'Nowa kampania'; ?>
    </h3>
    <input type="hidden" id="evk-nl-camp-id" value="<?php echo (int) ($edit_camp['id'] ?? 0); ?>">

    <div class="evk-nl-grid2">
        <div>
            <label class="evk-nl-label">Nazwa kampanii</label>
            <input type="text" id="evk-nl-camp-name" class="widefat"
                   value="<?php echo esc_attr($edit_camp['name'] ?? ''); ?>"
                   placeholder="np. Newsletter Czerwiec 2025">

            <label class="evk-nl-label evk-nl-label-mt">Szablon</label>
            <select id="evk-nl-camp-template" class="widefat">
                <option value="">— wybierz szablon —</option>
                <?php foreach ($templates as $t): ?>
                <option value="<?php echo (int) $t['id']; ?>" <?php selected($camp_tpl_id, $t['id']); ?>>
                    <?php echo esc_html($t['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <label class="evk-nl-label evk-nl-label-mt">Data wysyłki (opcjonalnie)</label>
            <input type="datetime-local" id="evk-nl-camp-scheduled" class="widefat"
                   value="<?php echo esc_attr($camp_scheduled ? date('Y-m-d\TH:i', strtotime($camp_scheduled)) : ''); ?>">
        </div>
        <div>
            <label class="evk-nl-label">Listy subskrybentów</label>
            <div style="border:1px solid #e2e8f0;border-radius:8px;padding:10px;max-height:130px;overflow-y:auto;">
                <?php if (empty($lists)): ?>
                <p style="color:#94a3b8;font-size:12px;margin:0;">Brak list.</p>
                <?php else: ?>
                <?php foreach ($lists as $list): ?>
                <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:13px;cursor:pointer;">
                    <input type="checkbox" class="evk-nl-camp-list" value="<?php echo (int) $list['id']; ?>"
                           <?php checked(in_array((int) $list['id'], $camp_lists, true)); ?>>
                    <?php echo esc_html($list['name']); ?>
                    <span style="font-size:11px;color:#94a3b8;">(<?php echo esc_html(evk_nl_list_count((int) $list['id'])); ?>)</span>
                </label>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px;">
                <div>
                    <label class="evk-nl-label">Batch: <span id="evk-nl-batch-val"><?php echo $camp_batch; ?></span></label>
                    <input type="range" id="evk-nl-batch-size" min="10" max="500" step="10"
                           value="<?php echo $camp_batch; ?>" style="width:100%;">
                </div>
                <div>
                    <label class="evk-nl-label">Przerwa (min): <span id="evk-nl-interval-val"><?php echo $camp_interval; ?></span></label>
                    <input type="range" id="evk-nl-batch-interval" min="1" max="60"
                           value="<?php echo $camp_interval; ?>" style="width:100%;">
                </div>
            </div>

            <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:13px;cursor:pointer;">
                <input type="checkbox" id="evk-nl-tracking" <?php checked($camp_tracking); ?>>
                Śledzenie otwarć i kliknięć
            </label>
        </div>
    </div>

    <div class="evk-nl-actions">
        <button class="button button-primary" id="evk-nl-save-camp">Zapisz draft</button>
        <?php if ($edit_camp): ?>
            <button class="button evk-nl-preview" data-url="<?php echo esc_url(add_query_arg(['evk_nl' => 'view', 'evk_nl_campaign' => (int) $edit_camp['id']], home_url('/'))); ?>" style="display:inline-flex;align-items:center;gap:5px;"><span class="dashicons dashicons-visibility" style="font-size:16px;width:16px;height:16px;line-height:1;"></span> Podgląd</button>
            <?php if (in_array($st, ['draft', 'scheduled'])): ?>
            <button class="button button-primary" id="evk-nl-launch-now"
                    style="background:#16a34a;border-color:#16a34a;">▶ Uruchom teraz</button>
            <?php endif; ?>
            <?php if ($st === 'sending'): ?>
            <button class="button" id="evk-nl-pause" style="border-color:#f97316;color:#f97316;">⏸ Pauza</button>
            <?php endif; ?>
            <?php if ($st === 'paused'): ?>
            <button class="button button-primary" id="evk-nl-resume">▶ Wznów</button>
            <?php endif; ?>
            <?php if (in_array($st, ['sending', 'scheduled', 'paused'])): ?>
            <button class="button" id="evk-nl-cancel" style="border-color:#dc2626;color:#dc2626;">■ Stop</button>
            <?php endif; ?>
            <?php if (in_array($st, ['done', 'failed', 'cancelled'])): ?>
            <button class="button" id="evk-nl-restart">↺ Uruchom ponownie</button>
            <?php endif; ?>
        <?php endif; ?>
        <span id="evk-nl-camp-msg" style="font-size:12px;color:#64748b;"></span>
    </div>
</div>

<!-- Lista kampanii -->
<div class="evk-nl-card">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
        <h3 style="margin:0;font-size:15px;">Wszystkie kampanie</h3>
        <select id="evk-nl-camp-status-filter" style="font-size:12px;">
            <option value="">Wszystkie statusy</option>
            <option value="draft">Szkice</option>
            <option value="scheduled">Zaplanowane</option>
            <option value="sending">Wysyłanie</option>
            <option value="paused">Pauza</option>
            <option value="done">Zakończone</option>
        </select>
    </div>

    <div class="evk-nl-bulk-bar" id="evk-nl-camp-bulk-bar">
        <span id="evk-nl-camp-bulk-count" style="font-size:12px;font-weight:600;color:#2563eb;"></span>
        <select id="evk-nl-camp-bulk-action" style="font-size:12px;">
            <option value="">— akcja —</option>
            <option value="delete">Usuń zaznaczone</option>
            <option value="clear_logs">Wyczyść logi</option>
        </select>
        <button class="button button-small" id="evk-nl-camp-bulk-apply">Wykonaj</button>
        <button class="button button-small" id="evk-nl-camp-bulk-cancel">Anuluj</button>
    </div>

    <?php if (empty($campaigns)): ?>
    <p style="color:#94a3b8;">Brak kampanii. Utwórz pierwszą powyżej.</p>
    <?php else: ?>
    <div class="evk-nl-tbl-wrap">
        <table class="evk-nl-tbl" id="evk-nl-camp-table">
            <thead>
                <tr>
                    <th style="width:28px;"><input type="checkbox" id="evk-nl-camp-check-all"></th>
                    <th>Nazwa</th>
                    <th style="width:95px;">Status</th>
                    <th style="width:80px;" class="evk-col-hide">Postęp</th>
                    <th style="width:110px;" class="evk-col-hide">Zaplanowana</th>
                    <th style="width:120px;">Akcje</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($campaigns as $c):
                    $stats = evk_nl_campaign_stats((int) $c['id']);
                    $stl   = $status_labels[$c['status']] ?? ['label' => $c['status'], 'color' => '#94a3b8'];
                    $pct   = $stats['total'] > 0 ? round($stats['sent'] / $stats['total'] * 100) : 0;
                    $rep_url  = add_query_arg(['subtab' => 'reports',   'campaign_id' => $c['id']], evk_nl_base_url());
                    $edit_url = add_query_arg(['subtab' => 'campaigns', 'campaign_id' => $c['id']], evk_nl_base_url());
                    $view_url = add_query_arg(['evk_nl' => 'view', 'evk_nl_campaign' => (int) $c['id']], home_url('/'));
                ?>
                <tr data-id="<?php echo (int) $c['id']; ?>" data-status="<?php echo esc_attr($c['status']); ?>">
                    <td><input type="checkbox" class="evk-nl-camp-cb" data-id="<?php echo (int) $c['id']; ?>"></td>
                    <td>
                        <a href="<?php echo esc_url($edit_url); ?>"
                           style="font-weight:600;text-decoration:none;color:#1e293b;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:160px;"
                           title="<?php echo esc_attr($c['name']); ?>">
                            <?php echo esc_html($c['name']); ?>
                        </a>
                    </td>
                    <td>
                        <span class="evk-nl-badge"
                              style="background:<?php echo esc_attr($stl['color']); ?>20;color:<?php echo esc_attr($stl['color']); ?>;">
                            <?php echo esc_html($stl['label']); ?>
                        </span>
                    </td>
                    <td class="evk-col-hide">
                        <?php if ($stats['total'] > 0): ?>
                        <div style="background:#e2e8f0;border-radius:99px;height:5px;overflow:hidden;margin-bottom:2px;">
                            <div style="width:<?php echo $pct; ?>%;background:#2563eb;height:100%;border-radius:99px;"></div>
                        </div>
                        <span style="font-size:10px;color:#94a3b8;"><?php echo $stats['sent']; ?>/<?php echo $stats['total']; ?></span>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                    <td class="evk-col-hide" style="color:#64748b;font-size:11px;">
                        <?php echo $c['scheduled_at'] ? esc_html(date('d.m H:i', strtotime($c['scheduled_at']))) : '—'; ?>
                    </td>
                    <td>
                        <div style="display:flex;gap:3px;align-items:center;">
                            <a href="<?php echo esc_url($rep_url); ?>" class="evk-nl-btn-icon" title="Raport">
                                <span class="dashicons dashicons-chart-bar"></span>
                            </a>
                            <button class="evk-nl-btn-icon evk-nl-preview" data-url="<?php echo esc_url($view_url); ?>" title="Podgląd">
                                <span class="dashicons dashicons-visibility"></span>
                            </button>
                            <button class="evk-nl-btn-icon evk-nl-recipients" data-id="<?php echo (int) $c['id']; ?>" data-name="<?php echo esc_attr($c['name']); ?>" title="Odbiorcy">
                                <span class="dashicons dashicons-groups"></span>
                            </button>
                            <button class="evk-nl-btn-icon evk-nl-clear-logs" data-id="<?php echo (int) $c['id']; ?>" title="Wyczyść logi">
                                <span class="dashicons dashicons-trash" style="color:#f59e0b;"></span>
                            </button>
                            <button class="evk-nl-btn-icon evk-nl-del-camp" data-id="<?php echo (int) $c['id']; ?>" title="Usuń">
                                <span class="dashicons dashicons-no-alt" style="color:#dc2626;"></span>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div id="evk-nl-modal">
    <div id="evk-nl-modal-backdrop"></div>
    <div class="evk-nl-modal-box">
        <div class="evk-nl-modal-head"><h2 id="evk-nl-modal-title"></h2><button id="evk-nl-modal-close" type="button">&times;</button></div>
        <div class="evk-nl-modal-body" id="evk-nl-modal-body"></div>
    </div>
</div>

<script>
jQuery(function($) {
    var nonce = '<?php echo esc_js($nonce); ?>';

    $('#evk-nl-batch-size').on('input', function() { $('#evk-nl-batch-val').text($(this).val()); });
    $('#evk-nl-batch-interval').on('input', function() { $('#evk-nl-interval-val').text($(this).val()); });

    function getCampData() {
        var lists = [];
        $('.evk-nl-camp-list:checked').each(function() { lists.push(parseInt($(this).val())); });
        return {
            id: $('#evk-nl-camp-id').val(),
            name: $('#evk-nl-camp-name').val(),
            template_id: $('#evk-nl-camp-template').val(),
            lists: JSON.stringify(lists),
            scheduled_at: $('#evk-nl-camp-scheduled').val().replace('T', ' '),
            batch_size: $('#evk-nl-batch-size').val(),
            batch_interval: $('#evk-nl-batch-interval').val(),
            tracking_enabled: $('#evk-nl-tracking').is(':checked') ? 1 : 0
        };
    }

    $('#evk-nl-save-camp').on('click', function() {
        $('#evk-nl-camp-msg').text('Zapisywanie...').css('color','#64748b');
        $.post(ajaxurl, $.extend({action:'evk_nl_save_campaign', nonce:nonce}, getCampData()), function(res) {
            if (res.success) {
                $('#evk-nl-camp-msg').text('Zapisano!').css('color','#16a34a');
                if (!$('#evk-nl-camp-id').val() || $('#evk-nl-camp-id').val() === '0') {
                    setTimeout(function() { location.href = '<?php echo esc_js(add_query_arg('subtab', 'campaigns', evk_nl_base_url())); ?>&campaign_id=' + res.data.id; }, 500);
                } else {
                    setTimeout(function() { location.reload(); }, 500);
                }
            } else { $('#evk-nl-camp-msg').text(res.data?.msg || 'Błąd').css('color','#dc2626'); }
        });
    });

    function campaignAction(action) {
        var id = $('#evk-nl-camp-id').val();
        if (!id) { alert('Najpierw zapisz kampanię.'); return; }
        $('#evk-nl-camp-msg').text('...').css('color','#64748b');
        $.post(ajaxurl, {action:'evk_nl_launch_campaign', nonce:nonce, id:id, campaign_action:action}, function(res) {
            if (res.success) { $('#evk-nl-camp-msg').text(res.msg || 'OK').css('color','#16a34a'); setTimeout(function() { location.reload(); }, 800); }
            else { $('#evk-nl-camp-msg').text(res.msg || 'Błąd').css('color','#dc2626'); }
        });
    }

    $('#evk-nl-launch-now').on('click', function() { if (confirm('Uruchomić kampanię teraz?')) campaignAction('launch'); });
    $('#evk-nl-pause').on('click', function() { campaignAction('pause'); });
    $('#evk-nl-resume').on('click', function() { campaignAction('resume'); });
    $('#evk-nl-restart').on('click', function() { if (confirm('Uruchomić ponownie?')) campaignAction('restart'); });

    $(document).on('click', '.evk-nl-del-camp', function() {
        if (!confirm('Usunąć kampanię i wszystkie dane?')) return;
        $.post(ajaxurl, {action:'evk_nl_delete_campaign', nonce:nonce, id:$(this).data('id')}, function(res) { if (res.success) location.reload(); });
    });

    $(document).on('click', '.evk-nl-clear-logs', function() {
        if (!confirm('Wyczyścić logi tej kampanii?')) return;
        $.post(ajaxurl, {action:'evk_nl_bulk_campaigns', nonce:nonce, bulk_action:'clear_logs', ids:JSON.stringify([$(this).data('id')])}, function(res) { if (res.success) location.reload(); });
    });

    // Bulk
    var sel = [];
    function updateBulk() {
        sel.length ? $('#evk-nl-camp-bulk-bar').css('display','flex').find('#evk-nl-camp-bulk-count').text(sel.length + ' zaznaczonych')
                   : $('#evk-nl-camp-bulk-bar').hide();
    }
    $(document).on('change', '#evk-nl-camp-check-all', function() {
        var on = $(this).is(':checked');
        $('.evk-nl-camp-cb:visible').prop('checked', on).each(function() {
            var id = parseInt($(this).data('id'));
            if (on) { if (sel.indexOf(id)===-1) sel.push(id); } else { sel = sel.filter(function(x){return x!==id;}); }
        });
        updateBulk();
    });
    $(document).on('change', '.evk-nl-camp-cb', function() {
        var id = parseInt($(this).data('id'));
        $(this).is(':checked') ? (sel.indexOf(id)===-1 && sel.push(id)) : (sel = sel.filter(function(x){return x!==id;}), $('#evk-nl-camp-check-all').prop('checked',false));
        updateBulk();
    });
    $('#evk-nl-camp-status-filter').on('change', function() {
        var v = $(this).val(); sel = []; $('#evk-nl-camp-check-all').prop('checked',false); updateBulk();
        $('#evk-nl-camp-table tbody tr').each(function() {
            $(this)[(!v || $(this).data('status')===v) ? 'show' : 'hide']();
            if (v && $(this).data('status')!==v) $(this).find('.evk-nl-camp-cb').prop('checked',false);
        });
    });
    $('#evk-nl-camp-bulk-apply').on('click', function() {
        var a = $('#evk-nl-camp-bulk-action').val();
        if (!a || !sel.length) { alert('Zaznacz kampanie i wybierz akcję.'); return; }
        if (!confirm((a==='delete'?'Usunąć ':'Wyczyścić logi dla ') + sel.length + ' kampanii?')) return;
        $.post(ajaxurl, {action:'evk_nl_bulk_campaigns', nonce:nonce, bulk_action:a, ids:JSON.stringify(sel)}, function(res) { if (res.success) location.reload(); else alert(res.data?.msg||'Błąd'); });
    });
    $('#evk-nl-camp-bulk-cancel').on('click', function() { sel=[]; $('.evk-nl-camp-cb,#evk-nl-camp-check-all').prop('checked',false); updateBulk(); });

    // ── Stop (anulowanie) ──
    $('#evk-nl-cancel').on('click', function() {
        if (confirm('Zatrzymać i anulować wysyłkę? Niewysłane maile nie zostaną wysłane.')) campaignAction('cancel');
    });

    // ── Modal (podgląd + odbiorcy) ──
    function esc(t){ return $('<div>').text(t==null?'':String(t)).html(); }
    function evkModal(title, body){
        $('#evk-nl-modal-title').text(title);
        $('#evk-nl-modal-body').html(body);
        $('#evk-nl-modal').css('display','flex');
    }
    function evkModalClose(){ $('#evk-nl-modal').hide(); $('#evk-nl-modal-body').empty(); }
    $('#evk-nl-modal-close, #evk-nl-modal-backdrop').on('click', evkModalClose);
    $(document).on('keydown', function(e){ if(e.key==='Escape') evkModalClose(); });

    $(document).on('click', '.evk-nl-preview', function(){
        var url = $(this).data('url');
        evkModal('Podgląd kampanii', '<iframe src="'+url+'" style="width:100%;height:70vh;border:1px solid #e2e8f0;border-radius:8px;background:#fff;"></iframe>');
    });

    function statusBadge(s){
        var m={pending:['#f59e0b','oczekuje'],sent:['#16a34a','wysłana'],opened:['#2563eb','otwarta'],clicked:['#7c3aed','kliknięta'],failed:['#dc2626','błąd'],cancelled:['#94a3b8','anulowana']};
        var x=m[s]||['#94a3b8',s];
        return '<span style="background:'+x[0]+'22;color:'+x[0]+';padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;white-space:nowrap;">'+x[1]+'</span>';
    }
    function rcpLoad(){
        var b=$('#evk-nl-modal'), id=b.data('rcpId'), status=b.data('rcpStatus')||'', page=b.data('rcpPage')||1;
        $('#evk-nl-rcp-list').html('<p style="color:#64748b;">Ładowanie…</p>');
        $.post(ajaxurl, {action:'evk_nl_campaign_queue', nonce:nonce, id:id, status:status, page:page}, function(res){
            if(!res.success){ $('#evk-nl-rcp-list').text('Błąd ładowania.'); return; }
            var d=res.data, h='';
            if(!d.rows.length){ h='<p style="color:#64748b;">Brak odbiorców dla tego filtra.</p>'; }
            else {
                h='<table class="widefat striped" style="font-size:13px;"><thead><tr><th>E-mail</th><th>Status</th><th>Próby</th><th>Wysłano</th><th>Otwarto</th></tr></thead><tbody>';
                d.rows.forEach(function(r){
                    h+='<tr><td>'+esc(r.email||'—')+'</td><td>'+statusBadge(r.status)+'</td><td>'+esc(r.attempts)+'</td><td>'+esc(r.sent_at||'—')+'</td><td>'+esc(r.opened_at||'—')+'</td></tr>';
                });
                h+='</tbody></table>';
            }
            h+='<div class="evk-nl-rcp-pager"><span>'+esc(d.total)+' odbiorców • strona '+esc(d.page)+'/'+esc(d.pages||1)+'</span>'+
               (d.page>1?'<button class="button button-small evk-rcp-prev">‹ poprzednia</button>':'')+
               (d.page<d.pages?'<button class="button button-small evk-rcp-next">następna ›</button>':'')+'</div>';
            $('#evk-nl-rcp-list').html(h);
        });
    }
    $(document).on('click', '.evk-nl-recipients', function(){
        var b=$('#evk-nl-modal');
        b.data('rcpId', $(this).data('id')).data('rcpStatus','').data('rcpPage',1);
        evkModal('Odbiorcy — ' + $(this).data('name'),
            '<div class="evk-nl-rcp-tools"><select id="evk-rcp-status" style="font-size:12px;">'+
            '<option value="">Wszyscy</option><option value="sent">Wysłane</option><option value="opened">Otwarte</option><option value="clicked">Kliknięte</option><option value="pending">Oczekujące</option><option value="failed">Błędy</option><option value="cancelled">Anulowane</option>'+
            '</select></div><div id="evk-nl-rcp-list"></div>');
        rcpLoad();
    });
    $(document).on('change', '#evk-rcp-status', function(){ $('#evk-nl-modal').data('rcpStatus',$(this).val()).data('rcpPage',1); rcpLoad(); });
    $(document).on('click', '.evk-rcp-prev', function(){ var b=$('#evk-nl-modal'); b.data('rcpPage',Math.max(1,(b.data('rcpPage')||1)-1)); rcpLoad(); });
    $(document).on('click', '.evk-rcp-next', function(){ var b=$('#evk-nl-modal'); b.data('rcpPage',(b.data('rcpPage')||1)+1); rcpLoad(); });
});
</script>
