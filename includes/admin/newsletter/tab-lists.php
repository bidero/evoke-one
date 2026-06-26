<?php
if (!defined('ABSPATH')) exit;

$lists       = evk_nl_get_lists();
$nonce       = wp_create_nonce('evk_nl_nonce');
$active_list = (int) ($_GET['list_id'] ?? ($lists[0]['id'] ?? 0));
$base_url    = admin_url('options-general.php?page=evoke-one&tab=newsletter&subtab=lists');
?>
<style>
.evk-nl-lists-layout{display:grid;grid-template-columns:240px 1fr;gap:16px;align-items:start;}
.evk-nl-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;}
.evk-nl-card-body{padding:16px;}
.evk-nl-card-head{padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;}
.evk-nl-card-head strong{font-size:13px;}
.evk-nl-list-item{display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid #f1f5f9;text-decoration:none;}
.evk-nl-list-item:last-child{border-bottom:none;}
.evk-nl-badge-count{font-size:11px;background:#e2e8f0;padding:1px 7px;border-radius:99px;color:#64748b;}
.evk-nl-label{display:block;margin-bottom:5px;font-size:12px;font-weight:600;color:#374151;}
.evk-nl-label-mt{margin-top:12px;}
.evk-nl-btn-icon{display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;padding:0;min-height:28px;border-radius:4px;cursor:pointer;border:1px solid #cbd5e1;background:#f8fafc;}
.evk-nl-btn-icon .dashicons{font-size:14px;width:14px;height:14px;line-height:1;}
.evk-nl-bulk-bar{display:none;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:10px;padding:8px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;}
.evk-nl-tbl{width:100%;border-collapse:collapse;font-size:12px;}
.evk-nl-tbl th,.evk-nl-tbl td{padding:7px 10px;text-align:left;border-bottom:1px solid #f1f5f9;vertical-align:middle;}
.evk-nl-tbl th{background:#f8fafc;font-weight:600;color:#64748b;font-size:11px;text-transform:uppercase;letter-spacing:.04em;}
.evk-nl-tbl tr:last-child td{border-bottom:none;}
.evk-nl-tbl-wrap{overflow-x:auto;}
@media(max-width:900px){
    .evk-nl-lists-layout{grid-template-columns:1fr;}
}
@media(max-width:782px){
    .evk-nl-card-body{padding:12px;}
    .evk-nl-tbl th.evk-col-hide,.evk-nl-tbl td.evk-col-hide{display:none;}
}
</style>

<div class="evk-nl-lists-layout">

    <!-- PANEL LEWY: listy -->
    <div>
        <div class="evk-nl-card" style="margin-bottom:14px;">
            <div class="evk-nl-card-head">
                <strong>Listy</strong>
                <button class="button button-small" id="evk-nl-add-list-btn">+ Nowa</button>
            </div>
            <?php if (empty($lists)): ?>
            <p style="padding:16px;color:#94a3b8;font-size:13px;margin:0;">Brak list.</p>
            <?php else: ?>
            <?php foreach ($lists as $list):
                $count     = evk_nl_list_count((int) $list['id']);
                $is_active = (int) $list['id'] === $active_list;
            ?>
            <a href="<?php echo esc_url(add_query_arg('list_id', $list['id'], $base_url)); ?>"
               class="evk-nl-list-item"
               style="background:<?php echo $is_active ? '#eff6ff' : 'transparent'; ?>;color:<?php echo $is_active ? '#2563eb' : '#374151'; ?>;font-weight:<?php echo $is_active ? '600' : '400'; ?>;">
                <span style="font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?php echo esc_html($list['name']); ?></span>
                <span class="evk-nl-badge-count"><?php echo esc_html($count); ?></span>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Formularz nowej/edycji listy -->
        <div id="evk-nl-list-form" style="display:none;" class="evk-nl-card">
            <div class="evk-nl-card-body">
                <input type="hidden" id="evk-nl-list-id" value="0">
                <p style="margin:0 0 10px;font-weight:600;font-size:13px;" id="evk-nl-list-form-title">Nowa lista</p>
                <label class="evk-nl-label">Nazwa listy</label>
                <input type="text" id="evk-nl-list-name" class="widefat" placeholder="np. Klienci 2025">

                <div style="display:flex;gap:6px;">
                    <button class="button button-primary button-small" id="evk-nl-save-list-btn">Zapisz</button>
                    <button class="button button-small" id="evk-nl-cancel-list-btn">Anuluj</button>
                </div>
                <div id="evk-nl-list-msg" style="margin-top:6px;font-size:12px;"></div>
            </div>
        </div>
    </div>

    <!-- PANEL PRAWY: subskrybenci -->
    <div>
        <?php if ($active_list && ($current_list = evk_nl_get_list($active_list))): ?>
        <div class="evk-nl-card" style="margin-bottom:14px;">
            <div class="evk-nl-card-head" style="flex-wrap:wrap;gap:8px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <strong style="font-size:14px;"><?php echo esc_html($current_list['name']); ?></strong>
                </div>
                <div style="display:flex;gap:6px;">
                    <button class="button button-small evk-nl-edit-list-btn"
                            data-id="<?php echo (int) $current_list['id']; ?>"
                            data-name="<?php echo esc_attr($current_list['name']); ?>"
                            data-fields="<?php echo esc_attr($current_list['fields_config'] ?? '[]'); ?>">Edytuj</button>
                    <button class="button button-small evk-nl-delete-list-btn"
                            data-id="<?php echo (int) $current_list['id']; ?>"
                            style="color:#dc2626;border-color:#dc2626;">Usuń listę</button>
                </div>
            </div>

            <!-- Inline formularz edycji listy -->
            <div id="evk-nl-edit-inline" style="display:none;border-bottom:1px solid #e2e8f0;">
                <div class="evk-nl-card-body">
                    <p style="margin:0 0 10px;font-weight:600;font-size:13px;">Edytuj listę</p>
                    <input type="hidden" id="evk-nl-edit-id" value="">
                    <label class="evk-nl-label">Nazwa listy</label>
                    <input type="text" id="evk-nl-edit-name" class="widefat" style="margin-bottom:10px;">

                    <div style="display:flex;gap:6px;">
                        <button class="button button-primary button-small" id="evk-nl-edit-save-btn">Zapisz</button>
                        <button class="button button-small" id="evk-nl-edit-cancel-btn">Anuluj</button>
                    </div>
                    <div id="evk-nl-edit-msg" style="margin-top:6px;font-size:12px;"></div>
                </div>
            </div>

            <!-- Import -->
            <div class="evk-nl-card-body" style="border-bottom:1px solid #e2e8f0;">
                <p style="margin:0 0 8px;font-weight:600;font-size:13px;">Import subskrybentów</p>
                <div style="display:flex;gap:12px;margin-bottom:8px;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;">
                        <input type="radio" name="evk-nl-import-type" value="textarea" checked> Wklej emaile
                    </label>
                    <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;">
                        <input type="radio" name="evk-nl-import-type" value="csv"> Plik CSV/TXT
                    </label>
                </div>
                <div id="evk-nl-import-textarea-wrap">
                    <textarea id="evk-nl-import-textarea" rows="3" class="widefat"
                              placeholder="jan@example.com&#10;anna@example.com"></textarea>
                </div>
                <div id="evk-nl-import-file-wrap" style="display:none;">
                    <input type="file" id="evk-nl-import-file" accept=".csv,.txt">
                    <p style="margin:4px 0 0;font-size:11px;color:#94a3b8;">Email w pierwszej kolumnie.</p>
                </div>
                <div style="margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                    <button class="button button-primary button-small" id="evk-nl-import-btn"
                            data-list-id="<?php echo (int) $active_list; ?>">Importuj</button>
                    <span id="evk-nl-import-result" style="font-size:12px;color:#64748b;"></span>
                </div>
            </div>

            <!-- Toolbar subskrybentów -->
            <div class="evk-nl-card-body" style="border-bottom:1px solid #e2e8f0;padding-bottom:10px;">
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                    <input type="text" id="evk-nl-sub-search" placeholder="Szukaj email..."
                           style="flex:1;min-width:140px;" class="regular-text">
                    <select id="evk-nl-sub-status-filter" style="font-size:12px;">
                        <option value="">Wszyscy</option>
                        <option value="1">Aktywni</option>
                        <option value="0">Wypisani</option>
                    </select>
                    <span id="evk-nl-sub-count" style="font-size:12px;color:#94a3b8;white-space:nowrap;"></span>
                </div>
            </div>

            <!-- Bulk bar -->
            <div class="evk-nl-bulk-bar evk-nl-card-body" id="evk-nl-bulk-bar" style="border-bottom:1px solid #e2e8f0;">
                <span id="evk-nl-bulk-count" style="font-size:12px;font-weight:600;color:#2563eb;"></span>
                <select id="evk-nl-bulk-action" style="font-size:12px;">
                    <option value="">— akcja —</option>
                    <option value="unsubscribe">Wypisz</option>
                    <option value="reactivate">Reaktywuj</option>
                    <option value="delete">Usuń</option>
                </select>
                <button class="button button-small" id="evk-nl-bulk-apply">Wykonaj</button>
                <button class="button button-small" id="evk-nl-bulk-cancel">Anuluj</button>
            </div>

            <!-- Tabela -->
            <div class="evk-nl-tbl-wrap">
                <div id="evk-nl-subscribers-table" style="padding:12px 16px;color:#94a3b8;">Ładowanie...</div>
            </div>
            <div id="evk-nl-sub-pagination" style="padding:10px 16px;display:flex;gap:4px;flex-wrap:wrap;align-items:center;"></div>

        </div><!-- /evk-nl-card -->
        <?php else: ?>
        <div class="evk-nl-card">
            <div class="evk-nl-card-body" style="text-align:center;padding:48px 20px;">
                <span class="dashicons dashicons-groups" style="font-size:36px;width:36px;height:36px;color:#94a3b8;"></span>
                <p style="color:#64748b;margin:10px 0 0;">Wybierz listę lub utwórz nową.</p>
            </div>
        </div>
        <?php endif; ?>
        </div>

    </div>
</div>

<script>
jQuery(function($) {
    var nonce  = '<?php echo esc_js($nonce); ?>';
    var listId = <?php echo (int) $active_list; ?>;
    var subPage = 1;
    var selectedIds = [];

    // ---- Lista form ----
    $('#evk-nl-add-list-btn').on('click', function() {
        $('#evk-nl-list-id').val(0);
        $('#evk-nl-list-name').val('');
        $('#evk-nl-fields-repeater').empty();
        $('#evk-nl-list-form-title').text('Nowa lista');
        $('#evk-nl-list-form').slideToggle();
    });
    $('#evk-nl-cancel-list-btn').on('click', function() { $('#evk-nl-list-form').slideUp(); });

    $(document).on('click', '.evk-nl-edit-list-btn', function() {
        var btn = $(this);
        $('#evk-nl-edit-id').val(btn.data('id'));
        $('#evk-nl-edit-name').val(btn.data('name'));
        $('#evk-nl-edit-fields-repeater').empty();
        $('#evk-nl-edit-inline').slideDown();
        $('#evk-nl-edit-name').focus();
    });

    $('#evk-nl-edit-cancel-btn').on('click', function() { $('#evk-nl-edit-inline').slideUp(); });



    $('#evk-nl-edit-save-btn').on('click', function() {
        $.post(ajaxurl, {action:'evk_nl_save_list', nonce:nonce,
            id: $('#evk-nl-edit-id').val(),
            name: $('#evk-nl-edit-name').val()
        }, function(res) {
            if (res.success) {
                $('#evk-nl-edit-msg').text('Zapisano!').css('color','#16a34a');
                setTimeout(function(){ location.reload(); }, 700);
            } else {
                $('#evk-nl-edit-msg').text(res.data?.msg||'Błąd').css('color','#dc2626');
            }
        });
    });



    $('#evk-nl-save-list-btn').on('click', function() {
        $.post(ajaxurl, {action:'evk_nl_save_list', nonce:nonce, id:$('#evk-nl-list-id').val(), name:$('#evk-nl-list-name').val()}, function(res) {
            if (res.success) { $('#evk-nl-list-msg').text('Zapisano!').css('color','#16a34a'); setTimeout(function(){location.reload();},700); }
            else { $('#evk-nl-list-msg').text(res.data?.msg||'Błąd').css('color','#dc2626'); }
        });
    });

    $(document).on('change', '.evk-nl-list-toggle', function() {
        $.post(ajaxurl, {action:'evk_nl_toggle_list', nonce:nonce, id:$(this).data('id'), status:$(this).is(':checked')?1:0});
    });
    $(document).on('click', '.evk-nl-delete-list-btn', function() {
        if (!confirm('Usunąć listę wraz ze wszystkimi subskrybentami?')) return;
        $.post(ajaxurl, {action:'evk_nl_delete_list', nonce:nonce, id:$(this).data('id')}, function(res) { if (res.success) location.reload(); });
    });

    // ---- Import ----
    $('input[name="evk-nl-import-type"]').on('change', function() {
        $(this).val()==='csv' ? ($('#evk-nl-import-textarea-wrap').hide(), $('#evk-nl-import-file-wrap').show())
                              : ($('#evk-nl-import-file-wrap').hide(), $('#evk-nl-import-textarea-wrap').show());
    });
    $('#evk-nl-import-btn').on('click', function() {
        var type = $('input[name="evk-nl-import-type"]:checked').val();
        $('#evk-nl-import-result').text('Importowanie...');
        if (type === 'csv') {
            var file = $('#evk-nl-import-file')[0].files[0];
            if (!file) { $('#evk-nl-import-result').text('Wybierz plik.'); return; }
            var fd = new FormData();
            fd.append('action','evk_nl_import_csv_file'); fd.append('nonce',nonce); fd.append('list_id',listId); fd.append('csv_file',file);
            $.ajax({url:ajaxurl, type:'POST', data:fd, processData:false, contentType:false, success:function(res) {
                if (res.success) { $('#evk-nl-import-result').text('Dodano:'+res.data.added+' pom.:'+res.data.skipped+' błędnych:'+res.data.invalid); loadSubs(); }
                else { $('#evk-nl-import-result').text(res.data?.msg||'Błąd'); }
            }});
        } else {
            $.post(ajaxurl, {action:'evk_nl_import_subscribers', nonce:nonce, list_id:listId, import_type:'textarea', content:$('#evk-nl-import-textarea').val()}, function(res) {
                if (res.success) { $('#evk-nl-import-result').text('Dodano:'+res.data.added+' pom.:'+res.data.skipped+' błędnych:'+res.data.invalid); loadSubs(); }
                else { $('#evk-nl-import-result').text(res.data?.msg||'Błąd'); }
            });
        }
    });

    // ---- Subskrybenci ----
    function updateBulkBar() {
        selectedIds.length ? $('#evk-nl-bulk-bar').css('display','flex').find('#evk-nl-bulk-count').text(selectedIds.length+' zaznaczonych')
                           : $('#evk-nl-bulk-bar').hide();
    }

    function loadSubs(page) {
        if (!listId) return;
        page = page || subPage;
        selectedIds = []; updateBulkBar();
        $.post(ajaxurl, {action:'evk_nl_get_subscribers', nonce:nonce, list_id:listId, page:page, search:$('#evk-nl-sub-search').val(), status:$('#evk-nl-sub-status-filter').val()}, function(res) {
            if (!res.success) return;
            var d = res.data; subPage = d.page;
            $('#evk-nl-sub-count').text(d.total+' subskrybentów');
            if (!d.items.length) { $('#evk-nl-subscribers-table').html('<p style="color:#94a3b8;padding:12px 0;">Brak subskrybentów.</p>'); $('#evk-nl-sub-pagination').empty(); return; }
            var html = '<table class="evk-nl-tbl"><thead><tr>' +
                '<th style="width:28px;"><input type="checkbox" id="evk-nl-check-all"></th>' +
                '<th>Email</th>' +
                '<th style="width:80px;" class="evk-col-hide">Status</th>' +
                '<th style="width:80px;" class="evk-col-hide">Data</th>' +
                '<th style="width:36px;"></th>' +
                '</tr></thead><tbody>';
            d.items.forEach(function(s) {
                var active = parseInt(s.status)===1;
                html += '<tr><td><input type="checkbox" class="evk-nl-sub-cb" data-id="'+s.id+'"></td>' +
                    '<td><strong>'+$('<div>').text(s.email).html()+'</strong></td>' +
                    '<td class="evk-col-hide"><span style="color:'+(active?'#16a34a':'#dc2626')+';font-size:11px;">'+(active?'● Aktywny':'● Wypisany')+'</span></td>' +
                    '<td class="evk-col-hide" style="color:#94a3b8;">'+s.subscribed_at.substring(0,10)+'</td>' +
                    '<td><button class="evk-nl-btn-icon evk-nl-del-sub" data-id="'+s.id+'" title="Usuń"><span class="dashicons dashicons-no-alt" style="color:#dc2626;font-size:14px;width:14px;height:14px;line-height:1;"></span></button></td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
            $('#evk-nl-subscribers-table').html(html);
            var pag = '';
            if (d.pages>1) {
                if (subPage>1) pag += '<button class="button button-small evk-nl-sub-page" data-page="'+(subPage-1)+'">‹</button> ';
                for (var i=Math.max(1,subPage-2); i<=Math.min(d.pages,subPage+2); i++) pag += '<button class="button button-small evk-nl-sub-page'+(i===d.page?' button-primary':'')+'" data-page="'+i+'">'+i+'</button> ';
                if (subPage<d.pages) pag += '<button class="button button-small evk-nl-sub-page" data-page="'+(subPage+1)+'">›</button>';
            }
            $('#evk-nl-sub-pagination').html(pag);
        });
    }

    $(document).on('change','#evk-nl-check-all', function() {
        var on=$(this).is(':checked');
        $('.evk-nl-sub-cb').prop('checked',on).each(function() {
            var id=parseInt($(this).data('id'));
            if (on) { if(selectedIds.indexOf(id)===-1) selectedIds.push(id); } else { selectedIds=selectedIds.filter(function(x){return x!==id;}); }
        });
        updateBulkBar();
    });
    $(document).on('change','.evk-nl-sub-cb', function() {
        var id=parseInt($(this).data('id'));
        $(this).is(':checked') ? (selectedIds.indexOf(id)===-1&&selectedIds.push(id)) : (selectedIds=selectedIds.filter(function(x){return x!==id;}), $('#evk-nl-check-all').prop('checked',false));
        updateBulkBar();
    });
    $('#evk-nl-bulk-apply').on('click', function() {
        var a=$('#evk-nl-bulk-action').val();
        if (!a) { alert('Wybierz akcję.'); return; }
        if (!selectedIds.length) return;
        var labels={delete:'usunąć',unsubscribe:'wypisać',reactivate:'reaktywować'};
        if (!confirm('Czy na pewno chcesz '+( labels[a]||a)+' '+selectedIds.length+' subskrybentów?')) return;
        $.post(ajaxurl, {action:'evk_nl_bulk_subscribers', nonce:nonce, bulk_action:a, ids:JSON.stringify(selectedIds)}, function(res) {
            if (res.success) { selectedIds=[]; loadSubs(subPage); } else { alert(res.data?.msg||'Błąd'); }
        });
    });
    $('#evk-nl-bulk-cancel').on('click', function() { selectedIds=[]; $('.evk-nl-sub-cb,#evk-nl-check-all').prop('checked',false); updateBulkBar(); });
    $(document).on('click','.evk-nl-del-sub', function() {
        if (!confirm('Usunąć?')) return;
        $.post(ajaxurl, {action:'evk_nl_delete_subscriber', nonce:nonce, id:$(this).data('id')}, function() { loadSubs(subPage); });
    });
    $(document).on('click','.evk-nl-sub-page', function() { loadSubs($(this).data('page')); });
    var st; $('#evk-nl-sub-search').on('input', function() { clearTimeout(st); st=setTimeout(function(){subPage=1;loadSubs(1);},350); });
    $('#evk-nl-sub-status-filter').on('change', function() { subPage=1; loadSubs(1); });

    if (listId) loadSubs(1);
});
</script>
