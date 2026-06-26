<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — TL: admin footer JavaScript
 * Hook: admin_footer (tylko na stronie TL)
 */

add_action('admin_footer', function () {
    $screen = get_current_screen();
    if (!$screen || strpos($screen->id, TL_MENU_SLUG) === false) return;

    $langs    = tl_get_languages();
    $codes    = array_keys($langs);
    $ajax_url = admin_url('admin-ajax.php');
    $nonce    = wp_create_nonce('tl_ajax_nonce');
    ?>
    <script>
    (function($) {
    'use strict';

    const AJAX       = <?php echo wp_json_encode($ajax_url); ?>;
    const NONCE      = <?php echo wp_json_encode($nonce); ?>;
    const CODES      = <?php echo wp_json_encode($codes); ?>;
    const LANG_NAMES = <?php echo wp_json_encode(array_column($langs,'name'), JSON_UNESCAPED_UNICODE); ?>;
    let _dirty = false;

    window.tlMarkDirty       = function() { _dirty = true; };
    window.tlMarkDirtyImages = function() { _dirty = true; };
    window.addEventListener('beforeunload', function(e) { if (_dirty) { e.preventDefault(); e.returnValue = ''; } });

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.button-link-delete');
        if (!btn) return;
        const inline = btn.getAttribute('onclick') || '';
        if (inline.indexOf('confirm(') !== -1) return;
        if (!window.confirm('Usunąć ten element?')) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    // ----------------------------------------------------------------
    // GROUP TOGGLE — kliknięcie w nagłówek grupy
    // ----------------------------------------------------------------
    function tlToggleGroup($header) {
        const $body = $header.next('.tl-group-body');
        const $icon = $header.find('.tl-group-toggle-icon');
        const gid   = $header.closest('.tl-group').data('gid');

        $body.toggleClass('open');
        $icon.toggleClass('open');
        $header.toggleClass('collapsed', !$body.hasClass('open'));

        try {
            const s = JSON.parse(localStorage.getItem('tl_group_states') || '{}');
            s[gid]  = $body.hasClass('open');
            localStorage.setItem('tl_group_states', JSON.stringify(s));
        } catch(e) {}
    }

    // Delegacja na dokumencie — działa też po dynamicznym dodaniu grup
    $(document).on('click', '.tl-group-header', function(e) {
        // Nie toggluj jeśli kliknięto w input, button lub drag-handle
        if ($(e.target).closest('input, button, .drag-handle').length) return;
        tlToggleGroup($(this));
    });

    // ROW TOGGLE
    $(document).on('click', '.tl-row-toggle-trigger', function(e) {
        e.stopPropagation();
        $(this).closest('.tl-row-header').toggleClass('open').next('.tl-row-body').toggleClass('open');
    });
    $(document).on('click', '.tl-row-header', function(e) {
        if ($(e.target).hasClass('drag-handle')) return;
        if ($(e.target).closest('.tl-row-toggle-trigger').length) return;
        $(this).toggleClass('open').next('.tl-row-body').toggleClass('open');
    });

    // Expand / Collapse all
    $('#btn-expand-all').on('click', function() {
        $('.tl-group-body').addClass('open');
        $('.tl-group-toggle-icon').addClass('open');
        $('.tl-group-header').removeClass('collapsed');
    });
    $('#btn-collapse-all').on('click', function() {
        $('.tl-group-body').removeClass('open');
        $('.tl-group-toggle-icon').removeClass('open');
        $('.tl-group-header').addClass('collapsed');
    });

    // Restore states
    (function() {
        try {
            const s = JSON.parse(localStorage.getItem('tl_group_states') || '{}');
            $('.tl-group').each(function() {
                if (s[$(this).data('gid')] === true) {
                    $(this).find('.tl-group-body').first().addClass('open');
                    $(this).find('.tl-group-toggle-icon').first().addClass('open');
                    $(this).find('.tl-group-header').first().removeClass('collapsed');
                }
            });
        } catch(e) {}
    })();

    // ----------------------------------------------------------------
    // SORTABLE — ONE correct initSortable()
    // ----------------------------------------------------------------
    function initSortable() {
        if (typeof $.fn.sortable === 'undefined') return;

        // Grupy
        if (!$('#groups-wrapper').hasClass('ui-sortable')) {
            $('#groups-wrapper').sortable({
                items:              '> .tl-group',
                handle:             '> .tl-group-header > .drag-handle',
                axis:               'y',
                tolerance:          'pointer',
                placeholder:        'sortable-placeholder tl-group',
                forcePlaceholderSize: true,
                cursor:             'grabbing',
                opacity:            0.9,
                revert:             150,
                start:  function(e, ui) { ui.placeholder.height(ui.item.outerHeight()); },
                update: function()      { tlMarkDirty(); }
            });
        }

        // Wiersze
        $('.row-sortable').each(function() { reinitRowSortable($(this)); });

        // Tabela językow
        if ($('#lang-body').length && !$('#lang-body').hasClass('ui-sortable')) {
            $('#lang-body').sortable({
                items:    'tr:not(:first-child)',
                handle:   '.drag-handle',
                axis:     'y',
                tolerance:'pointer',
                cursor:   'grabbing',
                opacity:  0.9,
                revert:   150,
                helper: function(e, tr) {
                    const $o = tr.children(), $h = tr.clone();
                    $h.children().each(function(i) { $(this).width($o.eq(i).width()); });
                    return $h;
                },
                update: function() { tlMarkDirty(); }
            });
        }
    }

    function reinitRowSortable($c) {
        if (typeof $.fn.sortable === 'undefined') return;
        if ($c.hasClass('ui-sortable')) $c.sortable('destroy');
        $c.sortable({
            items:              '> .tl-row',
            handle:             '> .tl-row-header > .drag-handle',
            axis:               'y',
            tolerance:          'pointer',
            placeholder:        'sortable-placeholder',
            forcePlaceholderSize: true,
            cursor:             'grabbing',
            opacity:            0.9,
            revert:             150,
            connectWith:        '.row-sortable',
            start:  function(e, ui) { ui.placeholder.height(ui.item.outerHeight()); },
            stop:   function(e, ui) {
                ui.item.find('textarea, input.tl-dd-key-input').attr('data-gid', ui.item.closest('.tl-group').data('gid'));
            },
            update: function() {
                tlMarkDirty();
                $('.tl-group').each(function() { $(this).find('.badge-count').text($(this).find('.tl-row').length); });
            }
        });
    }

    $(document).ready(function() { initSortable(); });

    // ----------------------------------------------------------------
    // HELPERS
    // ----------------------------------------------------------------
    window.tlUpdatePreview = function(ta) {
        $(ta).closest('.tl-row').find('.tl-row-pl-preview').text(ta.value.trim() || '- pusta -');
    };
    window.tlUpdatePill = function(ta) {
        const code = $(ta).data('field');
        $(ta).closest('.tl-row').find('.tl-pill').filter(function() { return $(this).text().toLowerCase() === code; }).toggleClass('filled', ta.value.trim().length > 0);
    };

    function ddFieldHtml(gid, rid) {
        return `<div class="tl-dd-field"><label>Klucz Dynamic Data</label><div class="tl-dd-field-row"><span class="tl-dd-prefix">{tl_</span><input type="text" class="tl-dd-key-input" data-field="dd_key" data-gid="${gid}" data-rid="${rid}" placeholder="opcjonalny_klucz" oninput="tlMarkDirty();"><span class="tl-dd-prefix">}</span></div></div>`;
    }

    window.tlAddRow = function(btn, gid) {
        const $rows = $(btn).closest('.tl-group').find('.tl-rows');
        const rid   = 'row_' + Date.now();
        let fields  = ddFieldHtml(gid, rid);
        fields += `<div class="tl-field"><label>Polski</label><textarea data-field="pl" data-gid="${gid}" data-rid="${rid}" oninput="tlUpdatePreview(this);tlMarkDirty();"></textarea></div>`;
        CODES.forEach(function(code, i) {
            fields += `<div class="tl-field"><label>${LANG_NAMES[i]}</label><textarea data-field="${code}" data-gid="${gid}" data-rid="${rid}" oninput="tlUpdatePill(this);tlMarkDirty();"></textarea></div>`;
        });
        const pills = CODES.map(function(c) { return `<span class="tl-pill">${c.toUpperCase()}</span>`; }).join('');
        $rows.append(`<div class="tl-row" data-rid="${rid}"><div class="tl-row-header open"><span class="drag-handle">⠿</span><span class="tl-row-pl-preview tl-row-toggle-trigger">- nowa fraza -</span><div class="tl-lang-pills tl-row-toggle-trigger">${pills}</div><span class="tl-chevron tl-row-toggle-trigger">▶</span></div><div class="tl-row-body open">${fields}<div class="tl-row-footer"><button type="button" class="button button-icon dashicons dashicons-admin-page" title="Duplikuj frazę" onclick="tlDuplicateRow(this)"></button><button type="button" class="button button-icon dashicons dashicons-trash button-link-delete" title="Usuń frazę" style="margin-left:auto;" onclick="jQuery(this).closest('.tl-row').remove();tlMarkDirty();"></button></div></div></div>`);
        reinitRowSortable($rows);
        tlMarkDirty();
    };

    window.tlDuplicateRow = function(btn) {
        const $row = $(btn).closest('.tl-row');
        const $clone = $row.clone(false);
        const rid = 'row_' + Date.now();
        $clone.attr('data-rid', rid);
        $clone.find('textarea').each(function() {
            $(this).attr('data-rid', rid);
            const field = $(this).data('field');
            this.oninput = field === 'pl' ? function(){ tlUpdatePreview(this); tlMarkDirty(); } : function(){ tlUpdatePill(this); tlMarkDirty(); };
        });
        $clone.find('input.tl-dd-key-input').attr('data-rid', rid).val('');
        $clone.find('.tl-row-header').addClass('open');
        $clone.find('.tl-row-body').addClass('open');
        $row.after($clone);
        tlMarkDirty();
    };

    window.tlAddGroup = function() {
        const gid = 'group_' + Date.now();
        $('#groups-wrapper').append(`<div class="tl-group" data-gid="${gid}"><div class="tl-group-header collapsed"><span class="drag-handle dashicons dashicons-move"></span><div class="tl-group-toggle"><span class="tl-group-toggle-icon">▶</span><input type="text" class="tl-group-name-input" data-gid="${gid}" placeholder="Nazwa nowej grupy..."><span class="badge-count">0</span></div><div class="tl-group-actions"><button type="button" class="button button-icon dashicons dashicons-download" onclick="tlExportGroup('${gid}');event.stopPropagation();"></button><button type="button" class="button button-icon dashicons dashicons-admin-page" onclick="tlDuplicateGroup(this);event.stopPropagation();"></button><button type="button" class="button button-icon dashicons dashicons-trash button-link-delete" onclick="if(confirm('Usunąć całą grupę?')){jQuery(this).closest('.tl-group').remove();tlMarkDirty();}event.stopPropagation();"></button></div></div><div class="tl-group-body"><div class="tl-rows row-sortable"></div><div style="padding:10px 14px;border-top:1px solid #f0f0f1;"><button type="button" class="button" onclick="tlAddRow(this,'${gid}')"><span class="dashicons dashicons-plus-alt2"></span> Dodaj frazę</button></div></div></div>`);
        // Niszcz stary sortable grup i reinicjuj
        if ($('#groups-wrapper').hasClass('ui-sortable')) $('#groups-wrapper').sortable('destroy');
        initSortable();
        tlMarkDirty();
    };

    window.tlDuplicateGroup = function(btn) {
        const $orig  = $(btn).closest('.tl-group');
        const $clone = $orig.clone(false);
        const gid    = 'group_' + Date.now();
        $clone.attr('data-gid', gid);
        $clone.find('.tl-group-name-input').attr('data-gid', gid).val($clone.find('.tl-group-name-input').val() + ' (kopia)');
        $clone.find('.tl-row').each(function() {
            const rid = 'row_' + Date.now() + '_' + Math.random().toString(36).slice(2,6);
            $(this).attr('data-rid', rid);
            $(this).find('textarea, input.tl-dd-key-input').attr('data-gid', gid).attr('data-rid', rid);
            $(this).find('input.tl-dd-key-input').val('');
        });
        $orig.after($clone);
        if ($('#groups-wrapper').hasClass('ui-sortable')) $('#groups-wrapper').sortable('destroy');
        initSortable();
        tlMarkDirty();
    };

    // ----------------------------------------------------------------
    // COLLECT + SAVE
    // ----------------------------------------------------------------
    function collectTranslations() {
        const result = { groups: {} };
        $('#groups-wrapper .tl-group').each(function() {
            const gid  = $(this).data('gid');
            const name = $(this).find('.tl-group-name-input').val() || '';
            result.groups[gid] = { name, rows: {} };
            $(this).find('.tl-row').each(function() {
                const rid = $(this).data('rid') || ('row_' + Date.now());
                const rowData = {};
                $(this).find('textarea, input.tl-dd-key-input').each(function() {
                    const field = $(this).data('field');
                    if (field) rowData[field] = $(this).val();
                });
                if (Object.keys(rowData).length) result.groups[gid].rows[rid] = rowData;
            });
        });
        return result;
    }

    window.tlSaveTranslations = function() {
        const $btn = $('#btn-save-translations');
        const $st  = $('#save-status-translations');
        $btn.prop('disabled', true).text('Zapisywanie...');
        $st.removeClass('ok err').hide();
        $.post(AJAX, { action: 'tl_save_translations', nonce: NONCE, tl_translations: JSON.stringify(collectTranslations()) })
            .done(function(r) { if (r.success) { _dirty = false; $st.addClass('ok').text('Zapisano pomyslnie').show(); } else { $st.addClass('err').text(r.data||'Blad zapisu').show(); } })
            .fail(function() { $st.addClass('err').text('Blad polaczenia').show(); })
            .always(function() { $btn.prop('disabled', false).text('Zapisz Tłumaczenia'); });
    };

    window.tlExportGroup = function(gid) {
        const form = $('<form method="post" target="_blank"></form>').css('display','none');
        form.append($('<input>').attr({name:'action',value:'tl_export'}));
        form.append($('<input>').attr({name:'nonce',value:NONCE}));
        form.append($('<input>').attr({name:'group_id',value:gid||''}));
        $('body').append(form);
        form[0].action = AJAX;
        form[0].submit();
        form.remove();
    };

    window.tlExportAll = function() {
        tlExportGroup('');
    };

    // Search
    $('#tl-search').on('input', function() {
        const q = this.value.trim().toLowerCase();
        if (!q) { $('.tl-row').show().removeClass('tl-highlight'); $('#tl-search-count').text(''); return; }
        let found = 0;
        $('.tl-row').each(function() {
            const text  = $(this).find('textarea, input.tl-dd-key-input').map(function() { return this.value.toLowerCase(); }).get().join(' ');
            const match = text.includes(q);
            $(this).toggle(match).toggleClass('tl-highlight', match);
            if (match) {
                found++;
                const $body = $(this).closest('.tl-group-body');
                if (!$body.hasClass('open')) {
                    $body.addClass('open');
                    $body.prev('.tl-group-header').find('.tl-group-toggle-icon').addClass('open');
                    $body.prev('.tl-group-header').removeClass('collapsed');
                }
                $(this).find('.tl-row-header').addClass('open');
                $(this).find('.tl-row-body').addClass('open');
            }
        });
        $('#tl-search-count').text(found ? found + ' wynikow' : 'Brak wynikow');
    });

    // Languages
    window.tlAddLang = function() {
        $('#lang-body').append('<tr><td><span class="drag-handle" title="Przeciagnij">☰</span></td><td><input type="text" class="lang-code" placeholder="np. en"></td><td><input type="text" class="lang-name" placeholder="np. Angielski"></td><td><input type="text" class="lang-html" placeholder="np. en-GB"></td><td><div class="tl-lang-flag-empty" data-att="0" style="width:32px;height:20px;border:1px dashed #cbd5e1;border-radius:2px;display:flex;align-items:center;justify-content:center;cursor:pointer;color:#94a3b8;font-size:12px;" onclick="tlOpenLangFlag(this)">+</div></td><td><button type="button" class="button button-icon dashicons dashicons-trash button-link-delete" title="Usuń" onclick="jQuery(this).closest(\'tr\').remove();tlMarkDirty();"></button></td></tr>');
        if ($('#lang-body').hasClass('ui-sortable')) $('#lang-body').sortable('refresh');
        else initSortable();
        tlMarkDirty();
    };

    window.tlSaveSettings = function() {
        const $st   = $('#save-status-settings');
        $st.removeClass('ok err').hide();
        const langs = [];
        $('#lang-body tr:not(.lang-row-pl)').each(function() {
            const code = $(this).find('.lang-code').val();
            if (!code) return;
            const flagEl = $(this).find('.tl-lang-flag-preview, .tl-lang-flag-empty');
            const flag = parseInt(flagEl.data('att') || 0, 10);
            langs.push({ code, name: $(this).find('.lang-name').val() || code, html: $(this).find('.lang-html').val() || code, flag });
        });
        const plFlagEl = $('.lang-row-pl').find('.tl-lang-flag-preview, .tl-lang-flag-empty');
        const plFlag = parseInt(plFlagEl.data('att') || 0, 10);
        $.post(AJAX, { action: 'tl_save_settings', nonce: NONCE, payload: JSON.stringify({ tl_languages: langs, tl_menu_location: $('#tl-menu-location').val(), tl_pl_flag: plFlag }) })
            .done(function(r) { if (r.success) { _dirty = false; $st.addClass('ok').text('Zapisano - odśwież stronę').show(); } else { $st.addClass('err').text(r.data||'Blad').show(); } })
            .fail(function() { $st.addClass('err').text('Blad polaczenia').show(); });
    };

    // Flag media picker for languages
    let langFlagFrame = null, langFlagTarget = null, langFlagCode = null;
    window.tlOpenLangFlag = function(el, code) {
        langFlagTarget = el;
        langFlagCode = code || null;
        if (langFlagFrame) { langFlagFrame.open(); return; }
        langFlagFrame = wp.media({ title: 'Wybierz flagę', button: { text: 'Wybierz' }, multiple: false });
        langFlagFrame.on('select', function() {
            const att = langFlagFrame.state().get('selection').first().toJSON();
            const url = att.sizes?.thumbnail?.url || att.url;
            const $el = $(langFlagTarget);
            if ($el.is('img')) {
                $el.attr('src', url).data('att', att.id);
            } else {
                const $img = $('<img>').addClass('tl-lang-flag-preview').attr('src', url).data('att', att.id).css({width:'32px',height:'20px',objectFit:'cover',borderRadius:'2px',border:'1px solid #d1d5db',cursor:'pointer'}).on('click', function() { tlOpenLangFlag(this, langFlagCode); });
                $el.replaceWith($img);
            }
            tlMarkDirty();
        });
        langFlagFrame.open();
    };


    // Media
    let mediaFrame = null, mediaTarget = null, mediaLang = null;
    window.tlOpenMedia = function(el, lang) {
        mediaTarget = el; mediaLang = lang;
        if (mediaFrame) { mediaFrame.open(); return; }
        mediaFrame = wp.media({ title: 'Wybierz obrazek', button: { text: 'Wybierz' }, multiple: false });
        mediaFrame.on('select', function() {
            const att = mediaFrame.state().get('selection').first().toJSON();
            const url = att.sizes?.thumbnail?.url || att.url;
            const $el = $(mediaTarget);
            if ($el.is('img')) { $el.attr('src', url).attr('data-att', att.id); }
            else { const $img = $('<img>').addClass('tl-img-preview').attr({ src: url, 'data-lang': mediaLang, 'data-att': att.id }).on('click', function() { tlOpenMedia(this, mediaLang); }); $el.replaceWith($img); }
            tlMarkDirtyImages();
        });
        mediaFrame.open();
    };
    window.tlRemoveImage = function(btn, lang) {
        const $row = $(btn).closest('.tl-img-lang-row');
        $row.find('.tl-img-preview').replaceWith($('<div>').addClass('tl-img-preview-empty').attr({'data-lang':lang,'data-att':0}).text('+').on('click', function() { tlOpenMedia(this, lang); }));
        $(btn).remove(); tlMarkDirtyImages();
    };
    window.tlAddImageCard = function() {
        const key  = 'img_' + Date.now();
        const CODES_ALL = ['pl'].concat(CODES);
        const rows = CODES_ALL.map(function(code) {
            const label = code === 'pl' ? 'PL' : code.toUpperCase();
            return `<div class="tl-img-lang-row"><span class="tl-img-lang-label">${label}</span><div class="tl-img-preview-empty" data-lang="${code}" data-att="0" onclick="tlOpenMedia(this,'${code}')">+</div><button type="button" class="button" onclick="tlOpenMedia(this.previousElementSibling,'${code}')"><span class="dashicons dashicons-format-image"></span> Wybierz</button></div>`;
        }).join('');
        $('#img-grid').append(`<div class="tl-img-card" data-key="${key}"><div class="tl-img-card-header"><strong style="flex:1;">Tlumaczenie obrazka</strong><button type="button" class="button-link-delete" style="font-size:18px;line-height:1;" onclick="jQuery(this).closest('.tl-img-card').remove();tlMarkDirtyImages();">✕</button></div>${rows}</div>`);
        tlMarkDirtyImages();
    };
    window.tlSaveImages = function() {
        const $st = $('#save-status-images'); $st.removeClass('ok err').hide();
        const payload = {};
        $('#img-grid .tl-img-card').each(function() {
            const key = $(this).data('key'); if (!key) return;
            payload[key] = {};
            $(this).find('[data-lang]').each(function() { payload[key][$(this).data('lang')] = parseInt($(this).data('att')||0, 10); });
        });
        $.post(AJAX, { action: 'tl_save_images', nonce: NONCE, tl_images: JSON.stringify(payload) })
            .done(function(r) { if (r.success) { _dirty = false; $st.addClass('ok').text('Zapisano').show(); } else { $st.addClass('err').text(r.data||'Blad').show(); } })
            .fail(function() { $st.addClass('err').text('Blad polaczenia').show(); });
    };

    // Import
    const $zone = $('#tl-drop-zone'), $fileIn = $('#tl-file-input'), $impSt = $('#tl-import-status');
    if ($zone.length) {
        $zone.on('dragover dragenter', function(e) { e.preventDefault(); e.stopPropagation(); $zone.addClass('drag-over'); })
             .on('dragleave dragend drop', function(e) { e.preventDefault(); e.stopPropagation(); $zone.removeClass('drag-over'); })
             .on('drop', function(e) { const f = e.originalEvent.dataTransfer.files[0]; if (f) importFile(f); });
        $fileIn.on('change', function() { if (this.files[0]) importFile(this.files[0]); });
    }

    function importFile(file) {
        if (!file.name.endsWith('.json')) { $impSt.removeClass('ok err').addClass('err').text('Wybierz plik .json').show(); return; }
        const reader = new FileReader();
        reader.onload = function(ev) {
            let json;
            try { json = JSON.parse(ev.target.result); } catch(ex) { $impSt.removeClass('ok err').addClass('err').text('Nieprawidlowy plik JSON').show(); return; }
            $.post(AJAX, { action: 'tl_import', nonce: NONCE, json: JSON.stringify(json) })
                .done(function(r) { $impSt.removeClass('ok err').addClass(r.success?'ok':'err').text(r.success ? r.data+' - odswiez strone.' : (r.data||'Blad')).show(); })
                .fail(function() { $impSt.removeClass('ok err').addClass('err').text('Blad polaczenia').show(); });
        };
        reader.readAsText(file);
    }

    })(jQuery);
    </script>
    <?php
});
