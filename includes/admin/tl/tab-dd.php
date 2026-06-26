<?php
if (!defined('ABSPATH')) exit;
// Evoke ONE — TL tab content. Zmienne z tl_render_page(): $data $langs $codes $tab $base $nonce $ajax_url $stats
?>
<p style="color:#475569;margin-bottom:6px;">Zdefiniuj klucze Dynamic Data. Uzywaj ich jako <code>{tl_klucz}</code> albo shortcode <code>[tl key="klucz"]</code>.</p><p style="color:#475569;margin-bottom:6px;">Możesz użyć  <code>{tl:pl=Treść|en=Content|de=Content}</code> by tłumaczyć całe frazy.</p>
            <table class="lang-table" id="dd-keys-table">
                <thead><tr><th style="width:180px;">Klucz</th><th>Fraza PL</th><th style="width:60px;"></th></tr></thead>
                <tbody id="dd-keys-body">
                <?php
                $all_pl_phrases = [];
                foreach (($data['groups'] ?? []) as $group) {
                    foreach (($group['rows'] ?? []) as $row) {
                        $pl = trim($row['pl'] ?? '');
                        if ($pl) $all_pl_phrases[] = $pl;
                    }
                }
                sort($all_pl_phrases);
                foreach ($dd_keys as $key => $phrase): ?>
                <tr class="dd-key-row">
                    <td><input type="text" class="dd-key-input" value="<?php echo esc_attr($key); ?>" placeholder="np. cennik" style="width:100%;font-family:monospace;"></td>
                    <td>
                        <select class="dd-phrase-select" style="width:100%; max-width:100%">
                            <option value="">- wybierz fraze -</option>
                            <?php foreach ($all_pl_phrases as $phrase_option): ?>
                            <option value="<?php echo esc_attr($phrase_option); ?>" <?php selected($phrase,$phrase_option); ?>><?php echo esc_html(mb_strlen($phrase_option)>80?mb_substr($phrase_option,0,77).'...':$phrase_option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><button type="button" class="button button-icon dashicons dashicons-trash button-link-delete" title="Usuń" onclick="jQuery(this).closest('tr').remove();tlMarkDirty();"></button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin:12px 0;"><button type="button" class="button" onclick="tlDDAddRow()"><span class="dashicons dashicons-plus-alt2"></span> Dodaj klucz</button></div>
            <div class="tl-save-bar">
                <button type="button" class="button button-primary" onclick="tlSaveDDKeys()"><span class="dashicons dashicons-saved"></span> Zapisz klucze DD</button>
                <span class="tl-save-status" id="save-status-dd"></span>
            </div>
            <script>
            (function($){
                const ALL_PL_PHRASES = <?php echo wp_json_encode($all_pl_phrases, JSON_UNESCAPED_UNICODE); ?>;
                window.tlDDAddRow = function() {
                    const opts = ALL_PL_PHRASES.map(function(p){ const safe=String(p).replace(/"/g,'&quot;'); const label=p.length>80?p.slice(0,77)+'...':p; return `<option value="${safe}">${label}</option>`; }).join('');
                    $('#dd-keys-body').append(`<tr class="dd-key-row"><td><input type="text" class="dd-key-input" placeholder="np. btn_kontakt" style="width:100%;font-family:monospace;"></td><td><select class="dd-phrase-select" style="width:100%;"><option value="">- wybierz fraze -</option>${opts}</select></td><td><button type="button" class="button button-icon dashicons dashicons-trash button-link-delete" title="Usuń" onclick="jQuery(this).closest('tr').remove();tlMarkDirty();"></button></td></tr>`);
                    tlMarkDirty();
                };
                window.tlSaveDDKeys = function() {
                    const $st=$('#save-status-dd'); $st.removeClass('ok err').hide();
                    const payload={};
                    $('#dd-keys-body .dd-key-row').each(function(){ const key=$(this).find('.dd-key-input').val().trim().replace(/\s+/g,'_').toLowerCase(); const phrase=$(this).find('.dd-phrase-select').val(); if(key&&phrase) payload[key]=phrase; });
                    $.post(<?php echo wp_json_encode($ajax_url); ?>,{action:'tl_save_dd_keys',nonce:<?php echo wp_json_encode($nonce); ?>,tl_dd_keys:JSON.stringify(payload)})
                    .done(function(r){ if(r.success){_dirty=false;$st.addClass('ok').text('Zapisano').show();}else{$st.addClass('err').text(r.data||'Blad').show();} })
                    .fail(function(){ $st.addClass('err').text('Blad polaczenia').show(); });
                };
            })(jQuery);
            </script>
