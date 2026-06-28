<?php
if (!defined('ABSPATH')) exit;


// =========================================================================
// AJAX — pobierz treść rewizji do podglądu
// =========================================================================

add_action('wp_ajax_evk_get_snippet_revision', function () {
    check_ajax_referer('evk_snippets_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error([], 403);
    $rev_id = absint($_POST['revision_id'] ?? 0);
    if (!$rev_id) wp_send_json_error('Brak ID rewizji.');
    $rev = wp_get_post_revision($rev_id);
    if (!$rev) wp_send_json_error('Rewizja nie istnieje.');
    if (!current_user_can('edit_post', $rev->post_parent)) wp_send_json_error('Brak uprawnień.', 403);
    wp_send_json_success(['content' => $rev->post_content]);
});
// ENQUEUE — CodeMirror tylko na stronie snippetów
// =========================================================================

add_action('admin_enqueue_scripts', function () {
    $page = $_GET['page'] ?? '';
    $tab  = $_GET['tab']  ?? '';
    $sub  = $_GET['sub']  ?? '';
    if ($page !== 'evoke-one' || $tab !== 'narzedzia' || $sub !== 'snippets') return;

    $cm = wp_enqueue_code_editor(['type' => 'application/x-httpd-php']);
    if (!$cm) return;
    wp_enqueue_script('wp-theme-plugin-editor');
    wp_enqueue_style('wp-codemirror');

    $field_ids = array_column(evk_snippets_defs(), 'field_id');
    $field_ids[] = 'evk_advanced_code';
    $selectors = '#' . implode(', #', $field_ids);

    wp_add_inline_script('wp-theme-plugin-editor', sprintf(
        'jQuery(function($){ var s=%s; $(%s).each(function(){ if(wp&&wp.codeEditor) wp.codeEditor.initialize(this,s); }); });',
        wp_json_encode($cm),
        wp_json_encode($selectors)
    ));

    // JS rewizji
    $nonce = wp_create_nonce('evk_snippets_nonce');
    wp_add_inline_script('wp-theme-plugin-editor', "
    jQuery(function(\$){
        // Preview rewizji w edytorze
        \$('body').on('click','.evk-preview-revision',function(){
            var rid=\$(this).data('rid'), btn=\$(this), fid=\$(this).data('field');
            btn.prop('disabled',true).text('Ładowanie...');
            \$.post(ajaxurl,{action:'evk_get_snippet_revision',nonce:'{$nonce}',revision_id:rid},function(r){
                if(r.success){
                    var ta=\$('#'+fid);
                    var cm=ta.get(0)&&ta.get(0).CodeMirror||ta.next('.CodeMirror').get(0)&&ta.next('.CodeMirror').get(0).CodeMirror;
                    if(cm) cm.setValue(r.data.content); else ta.val(r.data.content);
                    \$('.evk-restore-btn').hide();
                    btn.closest('li').find('.evk-restore-btn').show();
                }
            }).always(function(){ btn.prop('disabled',false).text('Podgląd w edytorze'); });
        });
        \$('body').on('click','.evk-restore-btn',function(){
            return confirm('Załadować tę rewizję i zapisać? Obecna treść zostanie nadpisana.');
        });
        \$('body').on('click','.evk-clear-revisions',function(){
            return confirm('Usunąć wszystkie rewizje? Tej operacji nie można cofnąć.');
        });
        \$('body').on('click','.evk-clear-logs',function(){
            return confirm('Usunąć wszystkie logi błędów?');
        });
    });
    ");
});

// =========================================================================
// POST HANDLER — musi działać w admin_init (przed jakimkolwiek outputem)
// =========================================================================

add_action('admin_init', function () {
    // Tylko strona Evoke ONE, zakładka narzędzia/snippets, metoda POST
    if (
        !is_admin()
        || ($_GET['page'] ?? '') !== 'evoke-one'
        || ($_GET['tab']  ?? '') !== 'narzedzia'
        || ($_GET['sub']  ?? '') !== 'snippets'
        || $_SERVER['REQUEST_METHOD'] !== 'POST'
        || empty($_POST['evk_snippets_nonce_field'])
    ) return;

    if (!current_user_can('manage_options')) wp_die('Brak uprawnień.');
    if (!wp_verify_nonce($_POST['evk_snippets_nonce_field'], 'evk_snippets_save')) wp_die('Nieprawidłowy nonce.');

    $defs  = evk_snippets_defs();
    $stab  = sanitize_key($_GET['evk_stab'] ?? 'frontend');
    $redir = admin_url('options-general.php?page=evoke-one&tab=narzedzia&sub=snippets');

    // Wyczyść logi
    if (isset($_POST['evk_clear_logs'])) {
        update_option(EVK_SNIPPETS_LOG_OPTION, []);
        wp_redirect(add_query_arg(['evk_stab' => 'logs', 'evk_saved' => 'logs'], $redir));
        exit;
    }

    // Wyczyść rewizje
    if (!empty($_POST['evk_clear_revisions_key'])) {
        $key = sanitize_key($_POST['evk_clear_revisions_key']);
        if (isset($defs[$key])) {
            $pid = evk_snippet_get_id($defs[$key]['slug']);
            if ($pid) {
                foreach (wp_get_post_revisions($pid, ['fields' => 'ids', 'posts_per_page' => -1]) as $rid) {
                    wp_delete_post_revision($rid);
                }
            }
        }
        wp_redirect(add_query_arg(['evk_stab' => $key, 'evk_saved' => 'revisions'], $redir));
        exit;
    }

    // Zapis główny
    if (isset($_POST['evk_save_snippets'])) {
        update_option(EVK_SNIPPETS_ENABLED_OPTION,   !empty($_POST[EVK_SNIPPETS_ENABLED_OPTION]) ? 1 : 0);
        update_option(EVK_SNIPPETS_ADVANCED_ENABLED, !empty($_POST[EVK_SNIPPETS_ADVANCED_ENABLED]) ? 1 : 0);

        if (get_option(EVK_SNIPPETS_ENABLED_OPTION)) {
            delete_transient(EVK_SNIPPETS_FATAL_TRANSIENT);
        }

        foreach ($defs as $key => $def) {
            if (isset($_POST[$def['field_id']])) {
                evk_snippet_save($def['slug'], $def['title'], wp_unslash($_POST[$def['field_id']]));
            }
        }
        if (isset($_POST['evk_advanced_code'])) {
            evk_snippets_advanced_save(wp_unslash($_POST['evk_advanced_code']));
        }

        wp_redirect(add_query_arg(['evk_stab' => $stab, 'evk_saved' => '1'], $redir));
        exit;
    }
});

// =========================================================================
// RENDER — zakładka snippetów (wywoływana z tab-narzedzia.php)
// =========================================================================

function evk_snippets_render_tab(): void {
    if (!current_user_can('manage_options')) return;

    $is_disabled = defined('EVK_CODE_DISABLE') && EVK_CODE_DISABLE;
    $defs        = evk_snippets_defs();
    $adv_enabled = (int) get_option(EVK_SNIPPETS_ADVANCED_ENABLED, 0);
    $enabled     = (int) get_option(EVK_SNIPPETS_ENABLED_OPTION, 0);
    $fatal       = (bool) get_transient(EVK_SNIPPETS_FATAL_TRANSIENT);

    $stab_keys   = array_keys($defs);
    $stab_keys[] = 'logs';
    if ($adv_enabled) $stab_keys[] = 'advanced';
    $stab = isset($_GET['evk_stab']) && in_array($_GET['evk_stab'], $stab_keys, true)
        ? $_GET['evk_stab'] : 'frontend';

    $base_url = admin_url('options-general.php?page=evoke-one&tab=narzedzia&sub=snippets');

    // Komunikat po zapisie (po redirect POST → GET)
    if (!empty($_GET['evk_saved'])) {
        echo '<div class="updated notice is-dismissible"><p>Snippety zapisane.</p></div>';
    }

    // ── Status card — zawsze widoczny na górze ────────────────────────────
    ?>
    <div class="evo-status-card" style="margin-bottom:20px;">
        <div class="evo-status-icon <?php echo $enabled ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-editor-code" style="font-size:24px;width:24px;height:24px;line-height:1;"></span>
        </div>
        <div class="evo-status-text">
            <h3>Snippety: <?php echo $enabled ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
            <p><?php echo $enabled ? 'Kod PHP jest aktywnie wykonywany.' : 'Wykonywanie kodu wyłączone.'; ?></p>
        </div>
        <div class="evo-status-actions">
            <span class="evo-toggle-label"><?php echo $enabled ? 'Włączony' : 'Wyłączony'; ?></span>
            <label class="evo-toggle">
                <input type="checkbox"
                       data-option="evk_snippets_enabled"
                       data-field="_scalar"
                       value="1"
                       <?php checked(1, $enabled); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>
    <?php

    ?>
    <?php if ($is_disabled): ?>
    <div class="notice notice-error inline">
        <p><strong>Wykonywanie wyłączone przez stałą EVK_CODE_DISABLE.</strong> Usuń ją z wp-config.php aby ponownie włączyć.</p>
    </div>
    <?php endif; ?>

    <div class="evo-info-box" style="<?php echo (!$enabled && $fatal) ? 'border-color:#dc2626;background:#fef2f2;' : ''; ?>">
        <span class="dashicons dashicons-<?php echo $enabled ? 'yes-alt' : 'warning'; ?>"
              style="color:<?php echo $enabled ? '#059669' : '#d97706'; ?>;"></span>
        <div>
            Globalne wykonywanie snippetów:
            <strong><?php echo $enabled ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></strong>
            <?php if (!$enabled && $fatal): ?>
            — <span style="color:#dc2626;">automatycznie wyłączone po błędzie krytycznym</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Podzakładki snippetów -->
    <div style="display:flex;gap:0;border-bottom:2px solid #e5e7eb;margin:16px 0 20px;">
        <?php foreach ($defs as $k => $d): ?>
        <a href="<?php echo esc_url(add_query_arg('evk_stab', $k, $base_url)); ?>"
           style="padding:7px 14px;font-size:12px;font-weight:<?php echo $stab===$k?'700':'500';?>;
                  color:<?php echo $stab===$k?'#2563eb':'#374151';?>;text-decoration:none;
                  border-bottom:<?php echo $stab===$k?'2px solid #2563eb':'2px solid transparent';?>;
                  margin-bottom:-2px;">
            <?php echo $d['title']; ?>
        </a>
        <?php endforeach; ?>
        <?php if ($adv_enabled): ?>
        <a href="<?php echo esc_url(add_query_arg('evk_stab', 'advanced', $base_url)); ?>"
           style="padding:7px 14px;font-size:12px;color:<?php echo $stab==='advanced'?'#dc2626':'#374151';?>;
                  font-weight:<?php echo $stab==='advanced'?'700':'500';?>;text-decoration:none;
                  border-bottom:<?php echo $stab==='advanced'?'2px solid #dc2626':'2px solid transparent';?>;
                  margin-bottom:-2px;">⚠ Advanced</a>
        <?php endif; ?>
        <a href="<?php echo esc_url(add_query_arg('evk_stab', 'logs', $base_url)); ?>"
           style="padding:7px 14px;font-size:12px;font-weight:<?php echo $stab==='logs'?'700':'500';?>;
                  color:<?php echo $stab==='logs'?'#2563eb':'#374151';?>;text-decoration:none;
                  border-bottom:<?php echo $stab==='logs'?'2px solid #2563eb':'2px solid transparent';?>;
                  margin-bottom:-2px;">
            Logi błędów
            <?php $log_count = count((array)get_option(EVK_SNIPPETS_LOG_OPTION, []));
            if ($log_count): ?><span style="background:#dc2626;color:#fff;border-radius:10px;padding:1px 6px;font-size:10px;margin-left:4px;"><?php echo $log_count; ?></span><?php endif; ?>
        </a>
    </div>

    <form method="post" action="<?php echo esc_url(add_query_arg('evk_stab', $stab, $base_url)); ?>">
        <?php wp_nonce_field('evk_snippets_save', 'evk_snippets_nonce_field'); ?>

        <?php if ($stab === 'logs'): ?>
            <?php
            $logs = (array) get_option(EVK_SNIPPETS_LOG_OPTION, []);
            if (empty($logs)):
            ?>
            <p style="color:#6b7280;">Brak zarejestrowanych błędów.</p>
            <?php else: ?>
            <div style="overflow-x:auto;margin-bottom:16px;">
                <table class="wp-list-table widefat striped" style="font-size:12px;table-layout:auto;">
                    <thead><tr>
                        <th style="width:130px;">Czas</th>
                        <th style="width:140px;">Typ</th>
                        <th style="width:170px;">Snippet</th>
                        <th style="width:50px;text-align:center;">Linia</th>
                        <th>Komunikat</th>
                    </tr></thead>
                    <tbody>
                    <?php foreach ($logs as $log):
                        $has_ctx = !empty($log['context']);
                    ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo esc_html(date_i18n('d.m H:i', strtotime($log['timestamp']))); ?></td>
                        <td><strong><?php echo esc_html($log['type']); ?></strong></td>
                        <td><code style="font-size:10px;word-break:break-all;"><?php echo esc_html($log['slug']); ?></code></td>
                        <td style="text-align:center;"><?php echo (int)$log['line']; ?></td>
                        <td><pre style="margin:0;white-space:pre-wrap;font-size:11px;"><?php echo esc_html($log['message']); ?></pre></td>
                    </tr>
                    <?php if ($has_ctx): ?>
                    <tr class="evk-log-ctx-row">
                        <td colspan="5" style="padding:0 !important;border-top:none;">
                            <details style="margin:0;">
                                <summary style="color:#2563eb;cursor:pointer;font-size:11px;padding:4px 10px;background:#f8fafc;">
                                    pokaż kontekst kodu
                                </summary>
                                <pre style="font-size:10px;background:#1e1e2e;color:#cdd6f4;padding:10px 14px;margin:0;overflow-x:auto;line-height:1.5;"><?php echo esc_html($log['context']); ?></pre>
                            </details>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" name="evk_clear_logs" class="button" onclick="return confirm('Usunąć wszystkie logi?')">
                Wyczyść logi
            </button>
            <?php endif; ?>

        <?php elseif ($stab === 'advanced'): ?>
            <div class="notice notice-error inline" style="margin-bottom:16px;">
                <p><strong>UWAGA:</strong> Kod wykonywany bezpośrednio bez żadnych zabezpieczeń. Tylko dla ekspertów.</p>
            </div>
            <textarea id="evk_advanced_code" name="evk_advanced_code" rows="30"
                      style="width:100%;font-family:monospace;"
                      placeholder="Wpisz kod PHP..."><?php echo esc_textarea(evk_snippets_advanced_get()); ?></textarea>

        <?php elseif (isset($defs[$stab])): ?>
            <?php
            $def      = $defs[$stab];
            $code     = evk_snippet_get($def['slug']);
            $post_id  = evk_snippet_get_id($def['slug']);
            $revisions = $post_id
                ? wp_get_post_revisions($post_id, ['posts_per_page' => 20, 'orderby' => 'post_date', 'order' => 'DESC'])
                : [];
            ?>
            <div style="display:flex;gap:20px;align-items:flex-start;">
                <!-- Edytor -->
                <div style="flex:3;min-width:0;">
                    <p style="font-size:12px;color:#6b7280;margin-bottom:8px;"><?php echo wp_kses_post($def['desc']); ?></p>
                    <?php if ($stab === 'functions'): ?>
                    <div class="notice notice-warning inline" style="margin-bottom:10px;">
                        <p><strong>Uwaga:</strong> Błędy tutaj mogą zepsuć stronę. Testuj ostrożnie.</p>
                    </div>
                    <?php endif; ?>
                    <textarea id="<?php echo esc_attr($def['field_id']); ?>"
                              name="<?php echo esc_attr($def['field_id']); ?>"
                              rows="28" style="width:100%;font-family:monospace;"
                              placeholder="Wpisz kod PHP lub HTML..."><?php echo esc_textarea($code); ?></textarea>
                </div>
                <!-- Rewizje -->
                <?php if (!empty($revisions)): ?>
                <div style="flex:1;min-width:240px;max-width:320px;border-left:1px solid #e5e7eb;padding-left:16px;">
                    <h4 style="margin-top:0;font-size:13px;">Rewizje</h4>
                    <div style="max-height:600px;overflow-y:auto;">
                        <ul style="list-style:none;margin:0;padding:0;">
                        <?php foreach ($revisions as $rev):
                            $author = get_userdata($rev->post_author);
                            $diff   = human_time_diff(strtotime($rev->post_date_gmt), current_time('timestamp', true));
                        ?>
                        <li style="padding:8px 0;border-bottom:1px solid #f0f0f1;">
                            <span style="display:block;font-size:11px;color:#6b7280;margin-bottom:4px;">
                                <?php echo esc_html(date_i18n('d.m.Y H:i', strtotime($rev->post_date))); ?>
                                — <?php echo esc_html($author ? $author->display_name : '?'); ?>
                                (<?php echo esc_html($diff); ?> temu)
                            </span>
                            <button type="button" class="button button-small evk-preview-revision"
                                    data-rid="<?php echo $rev->ID; ?>"
                                    data-field="<?php echo esc_attr($def['field_id']); ?>">
                                Podgląd w edytorze
                            </button>
                            <button type="submit" name="evk_restore_revision"
                                    value="<?php echo esc_attr($rev->ID . '_' . $stab); ?>"
                                    class="button button-small button-primary evk-restore-btn"
                                    style="display:none;margin-top:4px;">
                                Przywróć i zapisz
                            </button>
                        </li>
                        <?php endforeach; ?>
                        </ul>
                    </div>
                    <input type="hidden" name="evk_clear_revisions_key" id="evk_clear_revisions_key" value="">
                    <button type="submit" class="button button-small evk-clear-revisions"
                            style="margin-top:10px;color:#b91c1c;"
                            onclick="document.getElementById('evk_clear_revisions_key').value='<?php echo esc_js($stab); ?>'">
                        Wyczyść rewizje
                    </button>
                </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($stab !== 'logs'): ?>
        <div class="evo-save-bar" style="margin-top:16px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
            <?php submit_button('Zapisz snippety', 'primary', 'evk_save_snippets', false); ?>
            <label class="evo-toggle" title="Włącz/wyłącz wykonywanie snippetów">
                <input type="checkbox" name="<?php echo EVK_SNIPPETS_ENABLED_OPTION; ?>" value="1" <?php checked(1, $enabled); ?>>
                <span class="evo-slider"></span>
            </label>
            <label style="display:flex;align-items:center;gap:6px;font-size:11px;color:#dc2626;cursor:pointer;">
                <input type="checkbox" name="<?php echo EVK_SNIPPETS_ADVANCED_ENABLED; ?>" value="1" <?php checked(1, $adv_enabled); ?>>
                Tryb Advanced
            </label>
        </div>
        <?php endif; ?>
    </form>
    <?php
}
