<?php
if (!defined('ABSPATH')) exit;

$templates = evk_nl_get_templates();
$nonce     = wp_create_nonce('evk_nl_nonce');
$edit_id   = (int) ($_GET['template_id'] ?? 0);
$edit_tpl  = $edit_id ? evk_nl_get_template($edit_id) : null;

wp_enqueue_editor();

$merge_tags = [
    '{email}'                => 'Email odbiorcy',
    '{unsubscribe_url}'      => 'URL wypisania — z https:// (wstaw jako href)',
    '{unsubscribe_url_plain}' => 'URL wypisania — bez protokołu (gdy WP dokłada https://)',
    '{view_in_browser}'      => 'Link „Zobacz w przeglądarce"',
    '{view_url}'             => 'URL podglądu — z https://',
    '{view_url_plain}'       => 'URL podglądu — bez protokołu',
    '{site_name}'            => 'Nazwa strony',
    '{site_url}'             => 'URL strony — bez protokołu (bezpieczny w href)',
    '{site_url_full}'        => 'URL strony — z https://',
];
$attachments     = json_decode($edit_tpl['attachments_json'] ?? '[]', true) ?: [];
?>
<style>
.evk-nl-tpl-layout{display:grid;grid-template-columns:220px 1fr;gap:16px;align-items:start;}
.evk-nl-card{background:#fff;border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;margin-bottom:14px;}
.evk-nl-card-body{padding:16px;}
.evk-nl-card-head{padding:12px 16px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;}
.evk-nl-tpl-item{display:block;padding:10px 14px;border-bottom:1px solid #f1f5f9;text-decoration:none;}
.evk-nl-tpl-item:last-child{border-bottom:none;}
.evk-nl-label{display:block;margin-bottom:5px;font-size:12px;font-weight:600;color:#374151;}
.evk-nl-label-mt{margin-top:12px;}
.evk-nl-merge-btn{display:block;width:100%;text-align:left;margin-bottom:3px;font-family:monospace;font-size:11px;padding:3px 6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.evk-nl-actions{display:flex;gap:6px;align-items:center;flex-wrap:wrap;margin-top:14px;}
@media(max-width:900px){
    .evk-nl-tpl-layout{grid-template-columns:1fr;}
}
</style>

<div class="evk-nl-tpl-layout">

    <!-- Lewa kolumna -->
    <div>
        <div class="evk-nl-card">
            <div class="evk-nl-card-head">
                <strong style="font-size:13px;">Szablony</strong>
                <a href="<?php echo esc_url(add_query_arg('subtab', 'templates', evk_nl_base_url())); ?>"
                   class="button button-small">+ Nowy</a>
            </div>
            <?php if (empty($templates)): ?>
            <p style="padding:14px;color:#94a3b8;font-size:13px;margin:0;">Brak szablonów.</p>
            <?php else: foreach ($templates as $t): $is_cur = (int)$t['id'] === $edit_id; ?>
            <div style="border-bottom:1px solid #f1f5f9;">
                <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 14px;">
                    <a href="<?php echo esc_url(add_query_arg(['subtab'=>'templates','template_id'=>$t['id']], evk_nl_base_url())); ?>"
                       style="text-decoration:none;color:<?php echo $is_cur?'#2563eb':'#374151';?>;font-size:13px;font-weight:<?php echo $is_cur?'600':'400';?>;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:140px;"
                       title="<?php echo esc_attr($t['name']); ?>">
                        <?php echo esc_html($t['name']); ?>
                    </a>
                    <button class="button button-small evk-nl-del-template" data-id="<?php echo (int)$t['id']; ?>"
                            style="color:#dc2626;padding:1px 6px;min-height:auto;">✕</button>
                </div>
                <p style="margin:0 14px 8px;font-size:11px;color:#94a3b8;"><?php echo esc_html(mb_strimwidth($t['subject'],0,55,'...')); ?></p>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Merge tagi -->
        <div class="evk-nl-card">
            <div class="evk-nl-card-head"><strong style="font-size:13px;">Merge tagi</strong></div>
            <div class="evk-nl-card-body">
                <p style="margin:0 0 8px;font-size:11px;color:#94a3b8;">Kliknij aby wstawić do edytora:</p>
                <?php foreach ($merge_tags as $tag => $desc): ?>
                <button class="button button-small evk-nl-insert-tag evk-nl-merge-btn"
                        data-tag="<?php echo esc_attr($tag); ?>" title="<?php echo esc_attr($desc); ?>">
                    <?php echo esc_html($tag); ?>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Prawa kolumna: formularz -->
    <div class="evk-nl-card" style="overflow:visible;">
        <div class="evk-nl-card-head">
            <strong style="font-size:14px;">
                <?php echo $edit_tpl ? 'Edytuj: <em style="font-weight:400;">'.esc_html($edit_tpl['name']).'</em>' : 'Nowy szablon'; ?>
            </strong>
        </div>
        <div class="evk-nl-card-body">
            <input type="hidden" id="evk-nl-template-id" value="<?php echo (int)($edit_tpl['id'] ?? 0); ?>">

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                <div>
                    <label class="evk-nl-label">Nazwa szablonu</label>
                    <input type="text" id="evk-nl-tpl-name" class="widefat"
                           value="<?php echo esc_attr($edit_tpl['name'] ?? ''); ?>" placeholder="Wewnętrzna nazwa">
                </div>
                <div>
                    <label class="evk-nl-label">Temat maila</label>
                    <input type="text" id="evk-nl-tpl-subject" class="widefat"
                           value="<?php echo esc_attr($edit_tpl['subject'] ?? ''); ?>" placeholder="Temat wiadomości">
                </div>
            </div>

            <label class="evk-nl-label">Treść</label>
            <?php
            wp_editor($edit_tpl['body_html'] ?? '', 'evk_nl_tpl_body', [
                'textarea_name' => 'evk_nl_tpl_body',
                'textarea_rows' => 18,
                'media_buttons' => true,
                'teeny'         => false,
                'tinymce'       => ['toolbar1' => 'formatselect | bold italic underline | bullist numlist | link unlink | image | forecolor backcolor | alignleft aligncenter alignright | code'],
            ]);
            ?>

            <label class="evk-nl-label evk-nl-label-mt">Załączniki</label>
            <div id="evk-nl-attachments-list" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:8px;">
                <?php foreach ($attachments as $att_id):
                    $att_name = basename(get_attached_file($att_id) ?: '');
                ?>
                <div class="evk-nl-att-item" data-id="<?php echo (int)$att_id; ?>"
                     style="display:flex;align-items:center;gap:4px;background:#f1f5f9;padding:3px 8px;border-radius:6px;font-size:12px;">
                    <span class="dashicons dashicons-paperclip" style="font-size:12px;width:12px;height:12px;"></span>
                    <?php echo esc_html($att_name); ?>
                    <button class="evk-nl-remove-att" style="background:none;border:none;color:#dc2626;cursor:pointer;padding:0 0 0 2px;line-height:1;">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" id="evk-nl-attachments-data" value="<?php echo esc_attr(wp_json_encode($attachments)); ?>">
            <button class="button button-small" id="evk-nl-add-attachment">
                <span class="dashicons dashicons-paperclip" style="font-size:13px;width:13px;height:13px;line-height:1.6;"></span> Dodaj załącznik
            </button>

            <div class="evk-nl-actions">
                <button class="button button-primary" id="evk-nl-save-template-btn">Zapisz szablon</button>
                <button class="button" id="evk-nl-preview-tpl-btn">Podgląd HTML</button>
                <span id="evk-nl-tpl-msg" style="font-size:12px;color:#64748b;"></span>
            </div>

            <div id="evk-nl-preview-wrap" style="display:none;margin-top:14px;border:1px solid #e2e8f0;border-radius:8px;overflow:hidden;">
                <div style="padding:8px 12px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                    <strong style="font-size:12px;">Podgląd HTML</strong>
                    <button class="button button-small" id="evk-nl-close-preview">Zamknij</button>
                </div>
                <iframe id="evk-nl-preview-iframe" style="width:100%;height:480px;border:none;"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
jQuery(function($) {
    var nonce = '<?php echo esc_js($nonce); ?>';
    var attachments = JSON.parse($('#evk-nl-attachments-data').val() || '[]');

    $('.evk-nl-insert-tag').on('click', function(e) {
        e.preventDefault();
        var tag = $(this).data('tag');
        if (typeof tinymce !== 'undefined' && tinymce.get('evk_nl_tpl_body')) {
            tinymce.get('evk_nl_tpl_body').insertContent(tag);
        } else {
            var ta = document.getElementById('evk_nl_tpl_body');
            if (ta) { var s=ta.selectionStart,e2=ta.selectionEnd; ta.value=ta.value.substring(0,s)+tag+ta.value.substring(e2); ta.selectionStart=ta.selectionEnd=s+tag.length; ta.focus(); }
        }
    });

    $('#evk-nl-add-attachment').on('click', function(e) {
        e.preventDefault();
        var frame = wp.media({title:'Wybierz załącznik', button:{text:'Dodaj'}, multiple:true});
        frame.on('select', function() {
            frame.state().get('selection').each(function(att) {
                var id=att.id, name=att.get('filename')||att.get('url').split('/').pop();
                if (attachments.indexOf(id)===-1) {
                    attachments.push(id);
                    $('#evk-nl-attachments-list').append('<div class="evk-nl-att-item" data-id="'+id+'" style="display:flex;align-items:center;gap:4px;background:#f1f5f9;padding:3px 8px;border-radius:6px;font-size:12px;"><span class="dashicons dashicons-paperclip" style="font-size:12px;width:12px;height:12px;"></span>'+$('<div>').text(name).html()+'<button class="evk-nl-remove-att" style="background:none;border:none;color:#dc2626;cursor:pointer;padding:0 0 0 2px;line-height:1;">✕</button></div>');
                    syncAtt();
                }
            });
        });
        frame.open();
    });
    $(document).on('click','.evk-nl-remove-att', function() {
        var id=$(this).closest('.evk-nl-att-item').data('id');
        attachments=attachments.filter(function(a){return a!==id;});
        $(this).closest('.evk-nl-att-item').remove(); syncAtt();
    });
    function syncAtt() { $('#evk-nl-attachments-data').val(JSON.stringify(attachments)); }

    function getBody() {
        return (typeof tinymce!=='undefined' && tinymce.get('evk_nl_tpl_body'))
            ? tinymce.get('evk_nl_tpl_body').getContent()
            : $('#evk_nl_tpl_body').val();
    }

    $('#evk-nl-save-template-btn').on('click', function() {
        $('#evk-nl-tpl-msg').text('Zapisywanie...').css('color','#64748b');
        $.post(ajaxurl, {action:'evk_nl_save_template', nonce:nonce, id:$('#evk-nl-template-id').val(), name:$('#evk-nl-tpl-name').val(), subject:$('#evk-nl-tpl-subject').val(), body_html:getBody(), attachments:JSON.stringify(attachments)}, function(res) {
            if (res.success) {
                $('#evk-nl-tpl-msg').text('Zapisano!').css('color','#16a34a');
                if (!$('#evk-nl-template-id').val()||$('#evk-nl-template-id').val()==='0') {
                    setTimeout(function(){ location.href='<?php echo esc_js(add_query_arg('subtab', 'templates', evk_nl_base_url())); ?>&template_id='+res.data.id; },500);
                }
            } else { $('#evk-nl-tpl-msg').text(res.data?.msg||'Błąd').css('color','#dc2626'); }
        });
    });

    $('#evk-nl-preview-tpl-btn').on('click', function() {
        document.getElementById('evk-nl-preview-iframe').srcdoc = getBody();
        $('#evk-nl-preview-wrap').show();
    });
    $('#evk-nl-close-preview').on('click', function() { $('#evk-nl-preview-wrap').hide(); });

    $(document).on('click','.evk-nl-del-template', function() {
        if (!confirm('Usunąć ten szablon?')) return;
        $.post(ajaxurl, {action:'evk_nl_delete_template', nonce:nonce, id:$(this).data('id')}, function(res) { if (res.success) location.reload(); });
    });
});
</script>
