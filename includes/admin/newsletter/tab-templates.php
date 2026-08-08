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

<div class="evk-nl-split is-narrow">

    <!-- Lewa kolumna -->
    <div>
        <div class="evk-nl-card">
            <div class="evk-nl-card-head">
                <strong style="font-size:13px;">Szablony</strong>
                <a href="<?php echo esc_url(add_query_arg('subtab', 'templates', evk_nl_base_url())); ?>"
                   class="button button-small">+ Nowy</a>
            </div>
            <?php if (empty($templates)): ?>
            <p class="evk-nl-muted">Brak szablonów.</p>
            <?php else: foreach ($templates as $t): $is_cur = (int)$t['id'] === $edit_id; ?>
            <div class="evk-nl-hr">
                <div class="evk-nl-row-between">
                    <a href="<?php echo esc_url(add_query_arg(['subtab'=>'templates','template_id'=>$t['id']], evk_nl_base_url())); ?>"
                       class="evk-nl-item-name<?php echo $is_cur ? ' is-active' : ''; ?>"
                       title="<?php echo esc_attr($t['name']); ?>">
                        <?php echo esc_html($t['name']); ?>
                    </a>
                    <button class="button button-small evk-nl-del-template evk-nl-btn-del" data-id="<?php echo (int)$t['id']; ?>"
                           >✕</button>
                </div>
                <p class="evk-nl-note evk-nl-note-inset"><?php echo esc_html(mb_strimwidth($t['subject'],0,55,'...')); ?></p>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <!-- Merge tagi -->
        <div class="evk-nl-card">
            <div class="evk-nl-card-head"><strong style="font-size:13px;">Merge tagi</strong></div>
            <div class="evk-nl-card-body">
                <p class="evk-nl-note">Kliknij aby wstawić do edytora:</p>
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
    <div class="evk-nl-card evk-nl-card-overflow">
        <div class="evk-nl-card-head">
            <strong style="font-size:14px;">
                <?php echo $edit_tpl ? 'Edytuj: <em style="font-weight:400;">'.esc_html($edit_tpl['name']).'</em>' : 'Nowy szablon'; ?>
            </strong>
        </div>
        <div class="evk-nl-card-body">
            <input type="hidden" id="evk-nl-template-id" value="<?php echo (int)($edit_tpl['id'] ?? 0); ?>">

            <div class="evk-nl-grid2 evo-mb-sm" style="--evo-gap:12px">
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
            <div id="evk-nl-attachments-list" class="evk-nl-chips">
                <?php foreach ($attachments as $att_id):
                    $att_name = basename(get_attached_file($att_id) ?: '');
                ?>
                <div class="evk-nl-att-item evk-nl-chip" data-id="<?php echo (int)$att_id; ?>"
                    >
                    <span class="dashicons dashicons-paperclip evo-ico-xs"></span>
                    <?php echo esc_html($att_name); ?>
                    <button class="evk-nl-remove-att evk-nl-chip-x">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <input type="hidden" id="evk-nl-attachments-data" value="<?php echo esc_attr(wp_json_encode($attachments)); ?>">
            <button class="button button-small" id="evk-nl-add-attachment">
                <span class="dashicons dashicons-paperclip evo-ico-xs" style="font-size:13px;width:13px;height:13px"></span> Dodaj załącznik
            </button>

            <div class="evk-nl-actions">
                <button class="button button-primary" id="evk-nl-save-template-btn">Zapisz szablon</button>
                <button class="button" id="evk-nl-preview-tpl-btn">Podgląd HTML</button>
                <span id="evk-nl-tpl-msg" class="evo-hint"></span>
            </div>

            <div id="evk-nl-preview-wrap" class="evk-nl-preview">
                <div class="evk-nl-preview-bar">
                    <strong style="font-size:12px;">Podgląd HTML</strong>
                    <button class="button button-small" id="evk-nl-close-preview">Zamknij</button>
                </div>
                <iframe id="evk-nl-preview-iframe"></iframe>
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
                    $('#evk-nl-attachments-list').append('<div class="evk-nl-att-item evk-nl-chip" data-id="'+id+'"><span class="dashicons dashicons-paperclip evo-ico-xs"></span>'+$('<div>').text(name).html()+'<button class="evk-nl-remove-att evk-nl-chip-x">✕</button></div>');
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
                    setTimeout(function(){ location.href='<?php echo esc_url_raw(add_query_arg('subtab', 'templates', evk_nl_base_url())); ?>&template_id='+res.data.id; },500);
                } else {
                    setTimeout(function(){ location.reload(); },500);
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
