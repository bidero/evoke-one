/* Evoke One — Admin Panel Scripts */
(function ($) {
    'use strict';

    /* =========================================================
       AJAX TOGGLES — obsługa włączników przez AJAX
       ========================================================= */
    if (typeof evkToggle !== 'undefined') {
        $(document).on('change', '.evo-toggle input[type=checkbox]', function (e) {
            var $cb     = $(this);
            var option  = $cb.data('option');
            var field   = $cb.data('field');
            if (!option || !field) return; // brak data-* → form submit

            e.stopImmediatePropagation(); // zapobiega double-fire

            var $card   = $cb.closest('.evo-status-card');
            var checked = $cb.is(':checked') ? 1 : 0;

            $cb.prop('disabled', true);

            $.post(evkToggle.url, {
                action: 'evk_ajax_toggle',
                nonce:  evkToggle.nonce,
                option: option,
                field:  field,
                value:  checked,
            })
            .done(function (r) {
                if (!r.success) {
                    console.error('evk toggle error:', r.data);
                    $cb.prop('checked', !checked);
                    return;
                }
                var $icon   = $card.find('.evo-status-icon');
                var $title  = $card.find('.evo-status-text h3');
                var $tlabel = $card.find('.evo-toggle-label');
                if (checked) {
                    $icon.removeClass('off').addClass('on');
                } else {
                    $icon.removeClass('on').addClass('off');
                }
                if ($title.length) {
                    $title.text($title.text()
                        .replace(checked ? 'WYŁĄCZONY' : 'WŁĄCZONY', checked ? 'WŁĄCZONY' : 'WYŁĄCZONY'));
                }
                if ($tlabel.length) {
                    $tlabel.text(checked ? 'Włączony' : 'Wyłączony');
                }
            })
            .fail(function (xhr) {
                console.error('evk toggle fail:', xhr.status);
                $cb.prop('checked', !checked);
            })
            .always(function () {
                $cb.prop('disabled', false);
            });
        });
    }

    /* =========================================================
       RANGE SLIDER HELPER
       ========================================================= */
    /* Gdy element wartości jest polem <input> (a nie <span>), staje się on
       źródłem zapisywanej wartości — suwak tylko nim steruje. Dzięki temu
       można wpisać dokładną liczbę, której nie da się trafić suwakiem. */
    function initSlider(inputId, fillId, thumbId, valueId, min, max) {
        var input = document.getElementById(inputId),
            fill  = document.getElementById(fillId),
            thumb = document.getElementById(thumbId),
            val   = document.getElementById(valueId);
        if (!input || !val) return;

        var isField = val.tagName === 'INPUT';

        function paint(v) {
            if (isNaN(v)) return;
            var pct = ((v - min) / (max - min)) * 100;
            fill.style.width = pct + '%';
            thumb.style.left = pct + '%';
        }

        function fromRange() {
            var v = parseFloat(input.value);
            paint(v);
            if (isField) { val.value = v; } else { val.textContent = v.toFixed(2); }
        }

        function fromField() {
            var v = parseFloat(val.value);
            if (isNaN(v)) return;
            input.value = Math.max(min, Math.min(max, v));
            paint(parseFloat(input.value));
        }

        input.addEventListener('input', fromRange);

        if (isField) {
            val.addEventListener('input', fromField);
            val.addEventListener('change', function () {
                var v = parseFloat(val.value);
                val.value = isNaN(v) ? input.value : Math.max(min, Math.min(max, v));
                fromField();
            });
            paint(parseFloat(val.value));
        } else {
            fromRange();
        }
    }

    /* Parallax tab sliders */
    initSlider('evk_parallax_value', 'fill-parallax', 'thumb-parallax', 'value-parallax', -1, 1);
    initSlider('evk_parallax_scale', 'fill-scale',    'thumb-scale',    'value-scale',     1, 2);

    /* Lenis tab slider */
    initSlider('lenis_lerp', 'fill-lerp', 'thumb-lerp', 'value-lerp', 0.01, 1);

    /* Cursor tab slider */
    initSlider('cursor_inertia_range', 'fill-inertia', 'thumb-inertia', 'value-inertia', 0.1, 1);

    /* =========================================================
       SITEMAP TAB
       ========================================================= */
    if (typeof evoSitemapAjax !== 'undefined') {
        window.evoSaveSitemap = function () {
            var $st = $('#save-status-sitemap');
            $st.hide();
            var payload = {
                enabled:               $('#tl-sm-enabled').is(':checked')        ? 1 : 0,
                include_home:          $('#tl-sm-home').is(':checked')            ? 1 : 0,
                include_pages:         $('#tl-sm-pages').is(':checked')           ? 1 : 0,
                include_posts:         $('#tl-sm-posts').is(':checked')           ? 1 : 0,
                include_polish:        $('#tl-sm-polish').is(':checked')          ? 1 : 0,
                only_translated_slugs: $('#tl-sm-only-translated').is(':checked') ? 1 : 0,
                auto_exclude_noindex:  $('#tl-sm-auto-noindex').is(':checked')    ? 1 : 0,
                include_users:         $('#tl-sm-users').is(':checked')           ? 1 : 0,
                excluded_ids:          $('.tl-sm-excluded-id:checked').map(function () {
                    return parseInt(this.value, 10);
                }).get()
            };
            $.post(evoSitemapAjax.url, {
                action:  'tl_save_sitemap_settings',
                nonce:   evoSitemapAjax.nonce,
                payload: JSON.stringify(payload)
            }).done(function (r) {
                $st.text(r.success ? 'Zapisano' : 'Błąd: ' + (r.data || '')).show();
            });
        };
    }

    /* =========================================================
       IO TAB — EKSPORT / IMPORT
       ========================================================= */
    if (typeof evoIoAjax !== 'undefined') {
        var importData = null;
        var decisions  = {};
        var moduleLabels = evoIoAjax.modules;

        /* Zaznacz / odznacz wszystkie */
        window.evoIoSelectAll = function (type) {
            $('#evo-' + type + '-modules .evo-export-cb, #evo-' + type + '-modules .evo-import-cb')
                .prop('checked', true).closest('.evo-io-module').addClass('selected');
        };
        window.evoIoDeselectAll = function (type) {
            $('#evo-' + type + '-modules .evo-export-cb, #evo-' + type + '-modules .evo-import-cb')
                .prop('checked', false).closest('.evo-io-module').removeClass('selected');
        };

        /* Sync checkbox ↔ klasa .selected */
        $(document).on('change', '.evo-export-cb, .evo-import-cb', function () {
            $(this).closest('.evo-io-module').toggleClass('selected', this.checked);
        });

        /* EKSPORT */
        window.evoExportSelected = function () {
            var keys = [];
            $('.evo-export-cb:checked').each(function () { keys.push(this.value); });
            if (!keys.length) { alert('Zaznacz co najmniej jeden moduł.'); return; }
            var f = $('<form method="post" target="_blank" style="display:none">');
            f.append($('<input>').attr({ name: 'action',  value: 'tl_export' }));
            f.append($('<input>').attr({ name: 'nonce',   value: evoIoAjax.nonce }));
            f.append($('<input>').attr({ name: 'modules', value: JSON.stringify(keys) }));
            $('body').append(f);
            f[0].action = evoIoAjax.url;
            f[0].submit();
            f.remove();
        };

        /* IMPORT — wczytanie pliku */
        var $zone = $('#evo-drop-zone'),
            $fi   = $('#evo-file-input'),
            $st   = $('#evo-import-status');

        $zone.on('dragover dragenter', function (e) {
            e.preventDefault(); e.stopPropagation();
            $zone.addClass('drag-over');
        }).on('dragleave dragend drop', function (e) {
            e.preventDefault(); e.stopPropagation();
            $zone.removeClass('drag-over');
        }).on('drop', function (e) {
            var f = e.originalEvent.dataTransfer.files[0];
            if (f) readFile(f);
        });
        $fi.on('change', function () { if (this.files[0]) readFile(this.files[0]); });

        function readFile(file) {
            if (!file.name.endsWith('.json')) {
                $st.removeClass('ok err').addClass('err').text('Wybierz plik .json').show();
                return;
            }
            var r = new FileReader();
            r.onload = function (ev) {
                try { importData = JSON.parse(ev.target.result); }
                catch (ex) {
                    $st.removeClass('ok err').addClass('err').text('Nieprawidłowy JSON').show();
                    return;
                }
                $st.hide();
                openConflictModal();
            };
            r.readAsText(file);
        }

        /* MODAL konfliktu */
        function openConflictModal() {
            if (!importData) return;
            decisions = {};
            var $list = $('#evo-conflict-list').empty();
            var hasConflicts = false;
            var keyMap = {
                // TL
                'tl_translations':              'tl_translations',
                'tl_languages':                 'tl_languages',
                'tl_menu_location':             'tl_languages',
                'tl_pl_flag':                   'tl_languages',
                'tl_images':                    'tl_images',
                'tl_url_slugs':                 'tl_url_slugs',
                'tl_sitemap_settings':          'tl_sitemap_settings',
                'tl_dd_keys':                   'tl_dd_keys',
                // Frontend
                'evk_darkmode':                 'evk_darkmode',
                'evk_cursor':                   'evk_cursor',
                'evk_lenis':                    'evk_lenis',
                'evk_animator':                 'evk_animator',
                'evk_bgshift':                  'evk_bgshift',
                'evk_parallax_value':           'evk_parallax',
                'evk_parallax_scale':           'evk_parallax',
                'evk_a11y':                     'evk_a11y',
                // SEO
                'evk_schema':                   'evk_schema',
                'evk_og':                       'evk_og',
                // Admin
                'evk_white_label':              'evk_white_label',
                'evk_wl_bar_items':             'evk_white_label',
                'evk_security':                 'evk_security',
                'evk_smtp':                     'evk_smtp',
                'maintenance_mode':             'evk_maintenance',
                'maintenance_page_id':          'evk_maintenance',
                'maintenance_excluded_paths':   'evk_maintenance',
                'maintenance_bypass_hours':     'evk_maintenance',
                'maintenance_bypass_password':  'evk_maintenance',
                'evk_301_enabled':              'evk_redirects',
                'evk_404_enabled':              'evk_logs404',
                'evk_404_max_logs':             'evk_logs404',
                'evk_404_skip_bots':            'evk_logs404',
                'evk_404_bot_list':             'evk_logs404',
                'evoke_dashboard_active':       'evk_dashboard',
                'evoke_dashboard_page_id':      'evk_dashboard',
                'evoke_dashboard_mode':         'evk_dashboard',
                'evoke_dashboard_width':        'evk_dashboard',
                'evoke_dashboard_height':       'evk_dashboard',
                'evoke_dashboard_scrolling':    'evk_dashboard',
                'evoke_dashboard_fit_content':  'evk_dashboard',
                'evoke_dashboard_shadow':       'evk_dashboard',
                'evoke_dashboard_remove_native':'evk_dashboard',
                'evoke_dashboard_remove_help':  'evk_dashboard',
                'evk_snippets_settings':        'evk_snippets',
                'evoke_disable_global_comments':'evk_other',
                'evoke_require_reg_to_comment': 'evk_other',
                'evoke_move_bricks_bottom':     'evk_other',
                'evk_draft_revision_enabled':   'evk_other',
                'favicon_url':                  'evk_other',
            };
            var modulesInFile = {};
            Object.keys(importData).forEach(function (k) {
                var mod = keyMap[k] || k;
                if (moduleLabels[mod]) modulesInFile[mod] = true;
            });
            Object.keys(modulesInFile).forEach(function (mod) {
                hasConflicts = true;
                decisions[mod] = 'overwrite';
                var label = moduleLabels[mod] || mod;
                var $row  = $('<div class="evo-modal-row overwritten" data-mod="' + mod + '">');
                $row.append(
                    '<div class="evo-modal-row-name"><span class="dashicons dashicons-database"></span>' + label + '</div>' +
                    '<div class="evo-modal-row-actions">' +
                    '<button type="button" class="evo-modal-btn-overwrite" onclick="evoDecide(\'' + mod + '\',\'overwrite\')">Nadpisz</button>' +
                    '<button type="button" class="evo-modal-btn-skip" onclick="evoDecide(\'' + mod + '\',\'skip\')">Pomiń</button>' +
                    '</div>'
                );
                $list.append($row);
            });
            if (!hasConflicts) { doImport({}); return; }
            $('#evo-conflict-modal').addClass('open');
        }

        window.evoDecide = function (mod, action) {
            decisions[mod] = action;
            var $row = $('#evo-conflict-list [data-mod="' + mod + '"]');
            $row.removeClass('overwritten skipped');
            $row.addClass(action === 'overwrite' ? 'overwritten' : 'skipped');
        };

        window.evoConflictAll = function (action) {
            Object.keys(decisions).forEach(function (mod) { evoDecide(mod, action); });
        };

        window.evoConflictConfirm = function () {
            $('#evo-conflict-modal').removeClass('open');
            doImport(decisions);
        };

        function doImport(dec) {
            $st.removeClass('ok err').text('Importowanie…').show();
            $.post(evoIoAjax.url, {
                action:    'tl_import',
                nonce:     evoIoAjax.nonce,
                json:      JSON.stringify(importData),
                decisions: JSON.stringify(dec),
            }).done(function (r) {
                $st.removeClass('ok err').addClass(r.success ? 'ok' : 'err')
                   .text(r.success ? (r.data + ' — odśwież stronę.') : (r.data || 'Błąd')).show();
                importData = null;
                decisions  = {};
            }).fail(function () {
                $st.removeClass('ok err').addClass('err').text('Błąd połączenia.').show();
            });
        }

        /* Zamknij modal klikając tło */
        $('#evo-conflict-modal').on('click', function (e) {
            if (e.target === this) $(this).removeClass('open');
        });
    }

    /* =========================================================
       CURSOR TAB — dodawanie wierszy
       ========================================================= */
    if (typeof evoOneCursorData !== 'undefined') {
        window.evkCursorRowIndex = parseInt(evoOneCursorData.rowStart, 10);
        window.evkAddCursorRow = function () {
            var tpl  = document.getElementById('evo-cursor-row-template').innerHTML;
            var html = tpl.replace(/{INDEX}/g, evkCursorRowIndex++);
            document.getElementById('evo-cursor-repeater-container').insertAdjacentHTML('beforeend', html);
        };
    }

    /* =========================================================
       ANIMATOR TAB — dodawanie wierszy biblioteki
       ========================================================= */
    if (typeof evoOneAnimData !== 'undefined') {
        window.evkAnimRowIndex = parseInt(evoOneAnimData.rowStart, 10);
        window.evkAddAnimRow = function () {
            var tpl  = document.getElementById('evo-anim-row-template').innerHTML;
            var html = tpl.replace(/{INDEX}/g, evkAnimRowIndex++);
            document.getElementById('evo-anim-repeater-container').insertAdjacentHTML('beforeend', html);
        };

        /* Przenoszenie wierszy.
         *
         * Sam formularz działa poprawnie po przestawieniu bez żadnej pomocy:
         * PHP zachowuje kolejność kluczy z ciała POST, czyli kolejność DOM,
         * a sanitize_settings() iteruje po niej foreachem. Przeindeksowywanie
         * pól nie jest więc potrzebne — AJAX służy tylko temu, żeby nowy układ
         * przetrwał bez klikania „Zapisz".
         */
        var $box = $('#evo-anim-repeater-container');

        if ($box.length) {
            // SortableJS, nie jQuery UI — tą samą biblioteką jedzie już
            // przeciąganie w Białych etykietach i w warstwach OG, więc te same
            // klasy dają ten sam wygląd i nie dokładamy drugiej zależności.
            if (typeof Sortable === 'undefined') {
                // GŁOŚNO. Cichy warunek na brak biblioteki sprawił, że
                // przeciąganie nie działało w 1.37.0 i nikt tego nie zauważył.
                console.error('[Evoke ONE] Brak biblioteki Sortable — przeciąganie wierszy '
                    + 'animacji nie zadziała. Sprawdź, czy handle „sortablejs" jest ładowany.');
            } else {
                Sortable.create($box[0], {
                    handle     : '.evo-anim-row-header',
                    draggable  : '.evo-anim-row',
                    animation  : 150,
                    ghostClass : 'evk-drag-ghost',
                    chosenClass: 'evk-drag-chosen',
                    // Bez tego uchwyt łapie też przycisk „Usuń" w nagłówku.
                    filter             : 'button, input, select, textarea, a',
                    preventOnFilter    : false,
                    onEnd              : function () { saveOrder(); },
                });
            }
        }

        function saveOrder() {
            // Slugi, nie indeksy: wiersz świeżo dodany przyciskiem nie ma jeszcze
            // sluga i serwer ma go pominąć, a nie przesunąć cokolwiek innego.
            var order = $box.children('.evo-anim-row').find('input[name*="[slug]"]')
                .map(function () { return $(this).val(); }).get()
                .filter(function (s) { return s !== ''; });

            if (!order.length) return;

            var $note = $('#evo-anim-order-note').text('Zapisuję kolejność…').show();

            $.post(evoOneAnimData.url, {
                action: 'evk_anim_reorder',
                nonce : evoOneAnimData.nonce,
                order : order,
            }).done(function (res) {
                $note.text(res && res.success ? 'Kolejność zapisana.' : 'Nie udało się zapisać kolejności.');
                setTimeout(function () { $note.fadeOut(); }, 2000);
            }).fail(function () {
                $note.text('Nie udało się zapisać kolejności — zmiana zostanie zapisana wraz z formularzem.');
            });
        }

        /* Zapis całej biblioteki bez przeładowania strony.
         *
         * Formularz zostaje zwykłym formularzem celującym w options.php —
         * przechwytujemy tylko wysłanie. Gdy AJAX padnie, puszczamy je dalej
         * normalną drogą: awaria skryptu nie może być jedyną drogą zapisu.
         */
        var $form = $box.closest('form');

        $form.on('submit', function (e) {
            if ($form.data('evkFallback')) return;   // druga próba — puść normalnie
            e.preventDefault();

            var $btn  = $form.find('[type=submit]').prop('disabled', true);
            var $note = $('#evo-anim-order-note').text('Zapisuję…').show();

            // Ciąg pól, nie obiekt — i tylko pola należące do biblioteki.
            //
            // Kolejność: $.param() na liście par zachowuje kolejność DOM,
            // a PHP zachowuje kolejność, w jakiej klucze pojawiły się w ciele
            // żądania — dzięki temu przestawione wiersze zapisują się same.
            // Przepuszczenie tego przez obiekt JS wszystko psuje: klucze
            // wyglądające na liczby są w obiekcie porządkowane NUMERYCZNIE,
            // więc animations {1,2,0} wracało do 0,1,2 i przeciągnięcie
            // znikało w zapisie.
            //
            // Filtr: settings_fields() dokłada własne option_page, _wpnonce
            // i action=update. To ostatnie zderzyłoby się z naszą akcją —
            // w żądaniu byłyby DWA pola „action" i o routingu decydowałoby to,
            // które z nich wygra przy parsowaniu. Endpointowi nic z tych pól
            // nie jest potrzebne, więc po prostu ich nie wysyłamy.
            var fields = $form.serializeArray().filter(function (pair) {
                return pair.name.indexOf('evk_animator[') === 0;
            });

            var body = $.param(fields)
                + '&action=evk_anim_save'
                + '&nonce=' + encodeURIComponent(evoOneAnimData.saveNonce);

            $.post(evoOneAnimData.url, body).done(function (res) {
                $btn.prop('disabled', false);
                if (res && res.success) {
                    $note.text('Zapisano (' + res.data.count + ' animacji).');
                    refreshBadges();
                    setTimeout(function () { $note.fadeOut(); }, 2500);
                } else {
                    $note.text('Nie udało się zapisać — wysyłam formularz normalnie.');
                    $form.data('evkFallback', true).trigger('submit');
                }
            }).fail(function () {
                $btn.prop('disabled', false);
                $note.text('Nie udało się zapisać — wysyłam formularz normalnie.');
                $form.data('evkFallback', true).trigger('submit');
            });
        });

        /** Plakietki .evk-anim-{slug} w nagłówkach po zapisie zgadzają się ze slugami. */
        function refreshBadges() {
            $box.children('.evo-anim-row').each(function () {
                var slug  = $(this).find('input[name*="[slug]"]').val();
                var $badge = $(this).find('.evo-anim-class');
                if (!slug) { $badge.remove(); return; }
                if (!$badge.length) {
                    $badge = $('<span class="evo-anim-class"></span>')
                        .appendTo($(this).find('.evo-anim-row-title'));
                }
                $badge.text('.evk-anim-' + slug);
            });
        }
    }

})(jQuery);
