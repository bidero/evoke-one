<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia - frontend inline translation editor
 */

// ====================================================================
// 6. FRONTEND INLINE EDITOR
// ====================================================================
add_action('wp_footer', function () {
    if (!current_user_can('manage_options')) return;
    if (tl_is_bricks_editor() || is_admin()) return;
    if (empty(get_option('evk_tl_fab_enabled', 1))) return;

    $lang = get_current_lang();
    $langs = tl_get_languages();
    $codes = array_keys($langs);
    $nonce = wp_create_nonce('tl_inline_nonce');
    $ajax_url = admin_url('admin-ajax.php');
    $admin_url = tl_base_url();
    $lang_labels = array_map(fn($item) => $item['name'], $langs);

    $config = get_translation_config();
    $inline = tl_get_inline_phrases();
    $all_phrases = [];

    foreach (($config['strings'] ?? []) as $pl => $translations) {
        $meta = $config['meta'][$pl] ?? [];
        $all_phrases[$pl] = [
            'type'        => 'database',
            'translations'=> $translations,
            'parent_pl'   => $meta['parent_pl'] ?? $pl,
            'part_index'  => $meta['part_index'] ?? null,
        ];
    }

    foreach (get_option('tl_dd_keys', []) as $key => $pl_phrase) {
        $pl_phrase = trim((string) $pl_phrase);
        if (!$pl_phrase) continue;

        if (!isset($all_phrases[$pl_phrase])) {
            $all_phrases[$pl_phrase] = [
                'type'         => 'database',
                'translations' => [],
                'parent_pl'    => $pl_phrase,
                'part_index'   => null,
            ];
        }

        $all_phrases[$pl_phrase]['dd_key'] = $key;
    }

    foreach ($inline as $pl => $data) {
        if (!isset($all_phrases[$pl])) {
            $all_phrases[$pl] = [
                'type'         => 'inline',
                'translations' => $data['translations'],
                'raw'          => $data['raw'] ?? '',
                'parent_pl'    => $pl,
                'part_index'   => null,
            ];
        }
    }
    ?>
    <style>
        #tl-fab { position:fixed; bottom:24px; right:24px; z-index:99998; width:48px; height:48px; border-radius:50%; background:#2563eb; color:#fff; border:none; cursor:pointer; font-size:20px; box-shadow:0 4px 16px rgba(37,99,235,.45); display:flex; align-items:center; justify-content:center; transition:transform .2s, background .2s; }
        #tl-fab:hover { transform:scale(1.08); background:#2145d6; }
        #tl-fab.active { background:#dc2626; }
        #tl-fab-tooltip { position:fixed; bottom:80px; right:24px; z-index:99997; background:#1d2327; color:#fff; font-size:12px; padding:5px 10px; border-radius:4px; pointer-events:none; opacity:0; transition:opacity .15s; white-space:nowrap; }
        #tl-fab:hover + #tl-fab-tooltip { opacity:1; }
        body.tl-edit-mode [data-tl-phrase] { outline:2px dashed #2563eb !important; outline-offset:2px; cursor:pointer !important; }
        body.tl-edit-mode [data-tl-phrase]:hover { background:rgba(37,99,235,.08) !important; outline-style:solid !important; }
        body.tl-edit-mode [data-tl-phrase].tl-inline { outline-color:#dba617; }
        #tl-side-panel { position:fixed; top:0; right:-420px; width:400px; height:100vh; background:#fff; z-index:99999; box-shadow:-4px 0 24px rgba(0,0,0,.15); display:flex; flex-direction:column; transition:right .3s cubic-bezier(.4,0,.2,1); font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        #tl-side-panel.open { right:0; }
        #tl-panel-header { background:#2563eb; color:#fff; padding:14px 16px; display:flex; align-items:center; gap:10px; flex-shrink:0; }
        #tl-panel-header h3 { margin:0; font-size:14px; font-weight:600; flex:1; font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        #tl-panel-close { background:none; border:none; color:#fff; font-size:20px; cursor:pointer; padding:0; line-height:1; opacity:.8; }
        #tl-panel-close:hover { opacity:1; }
        #tl-panel-phrase { padding:10px 16px 12px; background:#eff6ff; border-bottom:1px solid #cbd5e1; flex-shrink:0; }
        #tl-panel-phrase-meta { font-size:10px; text-transform:uppercase; letter-spacing:.4px; color:#8cc4f0; margin-bottom:3px; }
        #tl-panel-pl-text { display:block; font-size:13px; color:#1d2327; font-weight:600; margin-bottom:10px; word-break:break-word; min-height:18px; }
        #tl-panel-type-badge { display:inline-block; font-size:10px; font-weight:700; padding:2px 6px; border-radius:4px; margin-left:6px; vertical-align:middle; }
        #tl-panel-type-badge.database { background:#d4edda; color:#155724; }
        #tl-panel-type-badge.inline { background:#fff3cd; color:#856404; }
        #tl-panel-type-badge.new { background:#cce5ff; color:#004085; }
        #tl-panel-dd-wrap { display:flex; align-items:center; gap:6px; }
        #tl-panel-dd-wrap label { font-size:10px; font-weight:700; text-transform:uppercase; color:#475569; letter-spacing:.4px; white-space:nowrap; flex-shrink:0; }
        #tl-panel-dd-key { flex:1; border:1px solid #d1d5db; border-radius:3px; padding:5px 8px; font-size:12px; font-family:monospace; background:#fff; min-width:0; }
        #tl-panel-dd-key:focus { border-color:#2563eb; outline:none; box-shadow:0 0 0 1px #2563eb; }
        #tl-panel-group-wrap { display:flex; align-items:center; gap:6px; margin-top:6px; }
        #tl-panel-group-wrap label { font-size:10px; font-weight:700; text-transform:uppercase; color:#475569; letter-spacing:.4px; white-space:nowrap; flex-shrink:0; }
        #tl-panel-group-select { flex:1; border:1px solid #d1d5db; border-radius:3px; padding:5px 8px; font-size:12px; background:#fff; }
        #tl-panel-group-select:focus { border-color:#2563eb; outline:none; box-shadow:0 0 0 1px #2563eb; }
        #tl-panel-close svg { display:block; }
        #tl-panel-fields { flex:1; overflow-y:auto; padding:16px; }
        .tl-panel-field { margin-bottom:14px; }
        .tl-panel-field label { display:block; font-size:10px; font-weight:700; text-transform:uppercase; color:#475569; margin-bottom:4px; letter-spacing:.4px; }
        .tl-panel-field label .tl-lang-current { background:#2563eb; color:#fff; font-size:9px; padding:1px 5px; border-radius:4px; margin-left:4px; vertical-align:middle; }
        .tl-panel-field textarea { width:100%; border:1px solid #d1d5db; border-radius:4px; padding:8px 10px; font-size:13px; resize:vertical; min-height:60px; box-sizing:border-box; font-family:inherit; }
        .tl-panel-field textarea:focus { border-color:#2563eb; outline:none; box-shadow:0 0 0 1px #2563eb; }
        .tl-panel-field textarea[data-lang="pl"] { background:#f9f9f9; }
        .tl-panel-field textarea.readonly { background:#f0f0f0; cursor:not-allowed; }
        .tl-panel-info, .tl-panel-info-new { border-radius:4px; padding:10px 12px; font-size:12px; margin-bottom:14px; }
        .tl-panel-info { background:#fff3cd; border:1px solid #ffc107; color:#856404; }
        .tl-panel-info-new { background:#cce5ff; border:1px solid #b8daff; color:#004085; }
        #tl-panel-footer { padding:12px 16px; border-top:1px solid #d1d5db; flex-shrink:0; display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
        #tl-panel-save, #tl-panel-add-to-db, #tl-panel-create-new { border:none; padding:8px 14px; border-radius:4px; font-size:13px; font-weight:600; cursor:pointer; }
        #tl-panel-save { background:#2563eb; color:#fff; }
        #tl-panel-add-to-db { background:#16a34a; color:#fff; }
        #tl-panel-create-new { background:#0073aa; color:#fff; }
        #tl-panel-save:disabled, #tl-panel-add-to-db:disabled, #tl-panel-create-new:disabled { opacity:.6; cursor:default; }
        #tl-panel-open-admin { font-size:12px; color:#2563eb; text-decoration:none; }
        #tl-panel-status { font-size:12px; flex:1; text-align:right; }
        #tl-panel-status.ok { color:#16a34a; }
        #tl-panel-status.err { color:#dc2626; }
        #tl-overlay { display:none; position:fixed; inset:0; z-index:99990; }
        #tl-overlay.active { display:block; }
        #tl-add-new-btn { position:fixed; bottom:24px; right:80px; z-index:99998; height:36px; padding:0 14px; border-radius:18px; background:#16a34a; color:#fff; border:none; cursor:pointer; font-size:12px; font-weight:600; box-shadow:0 2px 8px rgba(0,163,42,.35); display:none; align-items:center; gap:6px; transition:transform .2s; }
        body.tl-edit-mode #tl-add-new-btn { display:flex; }
    </style>

    <button id="tl-fab" title="Inline editor tlumaczen (Alt+T)">🌐</button>
    <div id="tl-fab-tooltip">Tryb edycji tlumaczen (Alt+T)</div>
    <button id="tl-add-new-btn" title="Dodaj nowa fraze do bazy">+ Nowa fraza</button>

    <div id="tl-side-panel" role="dialog" aria-label="Edytor tlumaczen">
        <div id="tl-panel-header">
            <h3>Edycja Tłumaczenia</h3>
            <button id="tl-panel-close" title="Zamknij"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        </div>
        <div id="tl-panel-phrase">
            <div id="tl-panel-phrase-meta">Fraza PL <span id="tl-panel-type-badge"></span></div>
            <strong id="tl-panel-pl-text">-</strong>
            <div id="tl-panel-dd-wrap">
                <label for="tl-panel-dd-key">{tl_klucz}</label>
                <input type="text" id="tl-panel-dd-key" placeholder="opcjonalny klucz DD" autocomplete="off" spellcheck="false">
            </div>
            <div id="tl-panel-group-wrap" style="display:none;">
                <label for="tl-panel-group-select">Grupa</label>
                <select id="tl-panel-group-select">
                    <?php
                    $tl_data   = get_option('tl_translations', ['groups' => []]);
                    $tl_groups = [];
                    foreach (($tl_data['groups'] ?? []) as $gid => $gdata) {
                        $tl_groups[$gid] = $gdata['name'] ?? $gid;
                    }
                    if (empty($tl_groups)) $tl_groups['group_inline'] = 'Inline Editor';
                    foreach ($tl_groups as $gid => $gname):
                    ?>
                    <option value="<?php echo esc_attr($gid); ?>"><?php echo esc_html($gname); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div id="tl-panel-fields"></div>
        <div id="tl-panel-footer">
            <button id="tl-panel-save">Zapisz</button>
            <button id="tl-panel-add-to-db" style="display:none;">Dodaj do bazy</button>
            <button id="tl-panel-create-new" style="display:none;">Utworz fraze</button>
            <a href="<?php echo esc_url($admin_url); ?>" id="tl-panel-open-admin" target="_blank">Panel</a>
            <span id="tl-panel-status"></span>
        </div>
    </div>
    <div id="tl-overlay"></div>

    <script>
    (function() {
        'use strict';

        const AJAX = <?php echo wp_json_encode($ajax_url); ?>;
        const NONCE = <?php echo wp_json_encode($nonce); ?>;
        const CODES = <?php echo wp_json_encode($codes); ?>;
        const LANG_LABELS = <?php echo wp_json_encode($lang_labels, JSON_UNESCAPED_UNICODE); ?>;
        const CURRENT_LANG = <?php echo wp_json_encode($lang); ?>;
        const ALL_PHRASES = <?php echo wp_json_encode($all_phrases, JSON_UNESCAPED_UNICODE); ?>;

        let editMode = false;
        let currentPhrase = null;
        let currentType = null;
        let isNewPhrase = false;

        const SVG_X = '<svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        const SVG_GLOBE = '🌐';
        const $fab = document.getElementById('tl-fab');
        const $addNewBtn = document.getElementById('tl-add-new-btn');
        const $panel = document.getElementById('tl-side-panel');
        const $overlay = document.getElementById('tl-overlay');
        const $close = document.getElementById('tl-panel-close');
        const $fields = document.getElementById('tl-panel-fields');
        const $plText = document.getElementById('tl-panel-pl-text');
        const $ddKeyInput = document.getElementById('tl-panel-dd-key');
        const $groupWrap   = document.getElementById('tl-panel-group-wrap');
        const $groupSelect = document.getElementById('tl-panel-group-select');
        const $save = document.getElementById('tl-panel-save');
        const $addToDb = document.getElementById('tl-panel-add-to-db');
        const $createNew = document.getElementById('tl-panel-create-new');
        const $status = document.getElementById('tl-panel-status');
        const $badge = document.getElementById('tl-panel-type-badge');

        function tlNormText(value) {
            return String(value || '').replace(/\u00A0/g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
        }

        function toggleEditMode() {
            editMode = !editMode;
            document.body.classList.toggle('tl-edit-mode', editMode);
            $fab.classList.toggle('active', editMode);
            $fab.title = editMode ? 'Wylacz tryb edycji (Alt+T)' : 'Inline editor tlumaczen (Alt+T)';
            $fab.innerHTML = editMode ? SVG_X : SVG_GLOBE;

            if (editMode) {
                markPhrasesInDOM();
            } else {
                clearPhraseMarks();
                closePanel();
            }
        }

        function markPhrasesInDOM() {
            const searchMap = new Map();

            for (const [pl, data] of Object.entries(ALL_PHRASES)) {
                const info = {
                    pl,
                    type: data.type,
                    dd_key: data.dd_key || '',
                    parent_pl: data.parent_pl || pl,
                    part_index: data.part_index ?? ''
                };

                searchMap.set(pl, info);

                if (data.translations) {
                    for (const [, text] of Object.entries(data.translations)) {
                        if (text && text !== pl) searchMap.set(text, info);
                    }
                }
            }

            const sorted = [...searchMap.entries()].sort((a, b) => b[0].length - a[0].length);
            const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT, {
                acceptNode(node) {
                    const tag = node.parentElement?.tagName?.toLowerCase();
                    if (['script', 'style', 'noscript', 'textarea'].includes(tag)) return NodeFilter.FILTER_REJECT;
                    if (node.parentElement?.closest('#tl-side-panel,#tl-fab,#tl-fab-tooltip,#tl-add-new-btn')) return NodeFilter.FILTER_REJECT;
                    return NodeFilter.FILTER_ACCEPT;
                }
            });

            const nodes = [];
            let node;
            while ((node = walker.nextNode())) nodes.push(node);

            nodes.forEach(function(textNode) {
                const text = textNode.textContent.trim();
                if (!text) return;

                for (const [searchText, info] of sorted) {
                    if (tlNormText(text) === tlNormText(searchText)) {
                        const parent = textNode.parentElement;

                        if (parent && !parent.dataset.tlPhrase) {
                            parent.dataset.tlPhrase = info.pl;
                            parent.dataset.tlType = info.type;
                            parent.dataset.tlDdKey = info.dd_key || '';
                            parent.dataset.tlParentPhrase = info.parent_pl || info.pl;
                            parent.dataset.tlPartIndex = info.part_index ?? '';
                            if (info.type === 'inline') parent.classList.add('tl-inline');
                        }
                        break;
                    }
                }
            });

            document.querySelectorAll('[data-tl-phrase]').forEach(function(element) {
                element.addEventListener('click', onPhraseClick);
            });
        }

        function clearPhraseMarks() {
            document.querySelectorAll('[data-tl-phrase]').forEach(function(element) {
                delete element.dataset.tlPhrase;
                delete element.dataset.tlType;
                delete element.dataset.tlDdKey;
                delete element.dataset.tlParentPhrase;
                delete element.dataset.tlPartIndex;
                element.classList.remove('tl-inline');
                element.removeEventListener('click', onPhraseClick);
            });
        }

        function onPhraseClick(event) {
            if (!editMode) return;

            event.preventDefault();
            event.stopPropagation();

            const pl = event.currentTarget.dataset.tlParentPhrase || event.currentTarget.dataset.tlPhrase;
            const type = event.currentTarget.dataset.tlType || 'database';

            if (pl) openPanel(pl, type, false);
        }

        function openPanel(pl, type, isNew) {
            currentPhrase = pl;
            currentType = type;
            isNewPhrase = isNew;

            $plText.textContent = pl || '- nowa fraza -';
            $badge.textContent = isNew ? 'NOWA' : (type === 'inline' ? 'INLINE' : 'BAZA');
            $badge.className = isNew ? 'new' : type;

            $ddKeyInput.value = '';
            const match = document.querySelector(`[data-tl-phrase="${CSS.escape(pl)}"]`);
            if (match?.dataset.tlDdKey) $ddKeyInput.value = match.dataset.tlDdKey;

            $status.textContent = '';
            $status.className = '';
            $fields.innerHTML = '<p style="color:#94a3b8;font-size:13px;">Ladowanie...</p>';

            $addToDb.style.display = (type === 'inline' && !isNew) ? 'inline-block' : 'none';
            $save.style.display = (type !== 'inline' && !isNew) ? 'inline-block' : 'none';
            $createNew.style.display = isNew ? 'inline-block' : 'none';
            if ($groupWrap) $groupWrap.style.display = isNew ? 'flex' : 'none';

            $panel.classList.add('open');
            $overlay.classList.add('active');

            if (isNew) {
                renderFields('', {}, 'new', null, true);
                return;
            }

            fetch(AJAX, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ action: 'tl_inline_get', nonce: NONCE, pl })
            })
                .then(response => response.json())
                .then(function(response) {
                    if (!response.success) {
                        $fields.innerHTML = '<p style="color:#dc2626;">Blad pobierania.</p>';
                        return;
                    }

                    currentType = response.data.type || type;
                    if (response.data.dd_key) $ddKeyInput.value = response.data.dd_key;
                    renderFields(response.data.pl || pl, response.data.translations || {}, currentType, response.data.raw, false);
                })
                .catch(function() { $fields.innerHTML = '<p style="color:#dc2626;">Blad polaczenia.</p>'; });
        }

        function renderFields(pl, translations, type, rawCode, isNew) {
            let html = '';

            if (isNew) html += '<div class="tl-panel-info-new"><strong>Nowa fraza</strong><br>Wpisz fraze PL i Tłumaczenia. Po zapisaniu trafi do bazy.</div>';
            if (type === 'inline' && rawCode && !isNew) html += `<div class="tl-panel-info"><strong>Fraza inline</strong><br>Zdefiniowana w szablonie: <code>${esc(rawCode)}</code><br>Kliknij "Dodaj do bazy" aby zarzadzac centralnie.</div>`;

            const readonly = (type === 'inline' && !isNew) ? 'readonly class="readonly"' : '';
            html += `<div class="tl-panel-field"><label>Polski <span style="font-size:9px;background:#475569;color:#fff;padding:1px 5px;border-radius:3px;">bazowy</span></label><textarea data-lang="pl" id="tl-pl-input" ${readonly}>${esc(isNew ? '' : pl)}</textarea></div>`;

            CODES.forEach(function(code) {
                const current = code === CURRENT_LANG ? '<span class="tl-lang-current">aktualny</span>' : '';
                html += `<div class="tl-panel-field"><label>${esc(LANG_LABELS[code] || code.toUpperCase())}${current}</label><textarea data-lang="${esc(code)}" placeholder="Wpisz tlumaczenie..." ${readonly}>${esc(translations[code] || '')}</textarea></div>`;
            });

            $fields.innerHTML = html;

            if (isNew) {
                const plInput = document.getElementById('tl-pl-input');
                plInput.addEventListener('input', function() {
                    $plText.textContent = this.value.trim() || '- nowa fraza -';
                    currentPhrase = this.value.trim();
                });
                plInput.focus();
            } else if (type !== 'inline') {
                const textarea = $fields.querySelector(`textarea[data-lang="${CURRENT_LANG}"]`);
                if (textarea) {
                    textarea.focus();
                    textarea.setSelectionRange(textarea.value.length, textarea.value.length);
                }
            }
        }

        function closePanel() {
            $panel.classList.remove('open');
            $overlay.classList.remove('active');
            currentPhrase = null;
            currentType = null;
            isNewPhrase = false;
            $ddKeyInput.value = '';
            if ($groupWrap) $groupWrap.style.display = 'none';
        }

        function collectTranslations() {
            const translations = {};
            $fields.querySelectorAll('textarea[data-lang]').forEach(function(textarea) {
                if (textarea.dataset.lang !== 'pl') translations[textarea.dataset.lang] = textarea.value;
            });
            return translations;
        }

        function saveAll(button, buttonText, oldPl, pl, translations) {
            const ddKey = $ddKeyInput.value.trim().replace(/\s+/g, '_').toLowerCase();

            button.disabled = true;
            button.textContent = 'Zapisywanie...';
            $status.textContent = '';
            $status.className = '';

            fetch(AJAX, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    action: 'tl_inline_save_full',
                    nonce: NONCE,
                    old_pl: oldPl || pl,
                    pl,
                    translations: JSON.stringify(translations),
                    dd_key: ddKey,
                    group_id: $groupSelect ? $groupSelect.value : ''
                })
            })
                .then(response => response.json())
                .then(function(result) {
                    if (result.success) {
                        $status.textContent = 'Zapisano';
                        $status.className = 'ok';
                        ALL_PHRASES[pl] = { type: 'database', translations, parent_pl: pl, part_index: null, dd_key: ddKey };
                        if (oldPl && oldPl !== pl) delete ALL_PHRASES[oldPl];

                        const saved = translations[CURRENT_LANG] || pl;
                        document.querySelectorAll(`[data-tl-phrase="${CSS.escape(oldPl || pl)}"]`).forEach(function(element) {
                            element.textContent = saved;
                            element.dataset.tlPhrase = pl;
                            element.dataset.tlParentPhrase = pl;
                            element.dataset.tlType = 'database';
                            element.dataset.tlDdKey = ddKey;
                            element.classList.remove('tl-inline');
                        });

                        currentPhrase = pl;
                        setTimeout(closePanel, 1200);
                    } else {
                        $status.textContent = result.data || 'Blad';
                        $status.className = 'err';
                    }
                })
                .catch(function() {
                    $status.textContent = 'Blad polaczenia';
                    $status.className = 'err';
                })
                .finally(function() {
                    button.disabled = false;
                    button.textContent = buttonText;
                });
        }

        $save.addEventListener('click', function() {
            if (!currentPhrase || currentType === 'inline') return;
            const plInput = document.getElementById('tl-pl-input');
            const newPl = plInput ? plInput.value.trim() : currentPhrase;
            if (!newPl) {
                $status.textContent = 'Wpisz fraze PL';
                $status.className = 'err';
                return;
            }
            saveAll($save, 'Zapisz', currentPhrase, newPl, collectTranslations());
        });

        $addToDb.addEventListener('click', function() {
            if (!currentPhrase) return;
            saveAll($addToDb, 'Dodaj do bazy', currentPhrase, currentPhrase, collectTranslations());
        });

        $createNew.addEventListener('click', function() {
            const plInput = document.getElementById('tl-pl-input');
            const pl = plInput ? plInput.value.trim() : '';
            if (!pl) {
                $status.textContent = 'Wpisz fraze PL';
                $status.className = 'err';
                return;
            }
            saveAll($createNew, 'Utworz fraze', '', pl, collectTranslations());
        });

        $addNewBtn.addEventListener('click', function() { openPanel('', 'new', true); });
        $fab.addEventListener('click', toggleEditMode);
        $close.addEventListener('click', closePanel);
        $overlay.addEventListener('click', closePanel);

        document.addEventListener('keydown', function(event) {
            if (event.altKey && event.key === 't') {
                event.preventDefault();
                toggleEditMode();
            }
            if (event.key === 'Escape' && $panel.classList.contains('open')) closePanel();
            if ((event.ctrlKey || event.metaKey) && event.key === 'Enter' && $panel.classList.contains('open')) {
                if (isNewPhrase) $createNew.click();
                else if (currentType === 'inline') $addToDb.click();
                else $save.click();
            }
        });

        function esc(value) {
            return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
    })();
    </script>
    <?php
}, 100);

