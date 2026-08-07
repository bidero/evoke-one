<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: Skrzynka wiadomości (ustawienia)
 */

$fi      = evk_inbox_get_settings();
$nonce   = wp_create_nonce('evk_inbox_nonce');
$has_tbl = evk_inbox_table_exists();
$inbox_url = admin_url('admin.php?page=evk-form-inbox');
?>

<!-- STATUS CARD -->
<div class="evo-status-card">
    <div class="evo-status-icon <?php echo !empty($fi['enabled']) ? 'on' : 'off'; ?>">
        <span class="dashicons dashicons-email-alt evo-ico-lg"></span>
    </div>
    <div class="evo-status-text">
        <h3>Skrzynka wiadomości: <?php echo !empty($fi['enabled']) ? 'WŁĄCZONA' : 'WYŁĄCZONA'; ?></h3>
        <p>Odczytuj zgłoszenia formularzy Bricks jak e-maile — bezpośrednio w panelu WordPress.</p>
    </div>
    <div class="evo-status-actions">
        <span class="evo-toggle-label"><?php echo !empty($fi['enabled']) ? 'Włączona' : 'Wyłączona'; ?></span>
        <label class="evo-toggle">
            <input type="checkbox"
                   data-option="evk_forminbox"
                   data-field="enabled"
                   value="1"
                   <?php checked(!empty($fi['enabled'])); ?>>
            <span class="evo-slider"></span>
        </label>
    </div>
</div>

<?php if (!$has_tbl): ?>
<div class="evo-info-box is-warn evo-mt">
    <span class="dashicons dashicons-warning"></span>
    <div>Tabela zgłoszeń Bricks nie istnieje. Przejdź do <strong>Bricks → Ustawienia → Ogólne</strong> i włącz
    <em>„Zapisuj zgłoszenia formularzy"</em>, a następnie w każdym formularzu dodaj akcję <em>„Save Submission"</em>.</div>
</div>
<?php elseif (!empty($fi['enabled'])): ?>
<div class="evo-info-box is-ok evo-mt">
    <span class="dashicons dashicons-yes-alt"></span>
    <div>Moduł aktywny.
        <a href="<?php echo esc_url($inbox_url); ?>" class="button button-secondary evo-ml">
            <span class="dashicons dashicons-email-alt evo-ico"></span>
            Otwórz skrzynkę
        </a>
    </div>
</div>
<?php endif; ?>

<form method="post" action="options.php" class="evo-mt-lg">
    <?php settings_fields(EVK_INBOX_OPTION . '_group'); ?>
    <?php /* 'enabled' zapisywany przez AJAX toggle — sanitizer zachowuje stan przy zapisie formularza */ ?>

    <!-- ── MENU ─────────────────────────────────────────────────────── -->
    <p class="evo-section-title">Konfiguracja menu</p>
    <div class="evo-grid evo-mb-lg" style="--evo-col:190px">
        <div class="evo-field">
            <label>Nazwa menu</label>
            <input type="text" name="evk_forminbox[menu_label]" value="<?php echo esc_attr($fi['menu_label']); ?>" placeholder="Wiadomości">
        </div>
        <div class="evo-field">
            <label>Ikona (Dashicons)</label>
            <input type="text" name="evk_forminbox[menu_icon]" value="<?php echo esc_attr($fi['menu_icon']); ?>" placeholder="dashicons-email-alt">
            <div class="evo-desc"><a href="https://developer.wordpress.org/resource/dashicons/" target="_blank">Lista ↗</a></div>
        </div>
        <div class="evo-field">
            <label>Pozycja w menu</label>
            <input type="number" name="evk_forminbox[menu_position]" value="<?php echo esc_attr($fi['menu_position']); ?>" min="1" max="100" class="evo-w-xs">
        </div>
        <div class="evo-field">
            <label>Wiadomości na stronę</label>
            <input type="number" name="evk_forminbox[per_page]" value="<?php echo esc_attr($fi['per_page']); ?>" min="5" max="100" class="evo-w-xs">
        </div>
        <div class="evo-field">
            <label>Plakietka w menu</label>
            <label class="checkbox-label">
                <input type="checkbox" name="evk_forminbox[menu_badge]" value="1" <?php checked(!empty($fi['menu_badge'])); ?>>
                <span>Pokaż licznik nieprzeczytanych przy pozycji menu</span>
            </label>
        </div>
        <div class="evo-field">
            <label>Klucz pola e-mail</label>
            <input type="text" name="evk_forminbox[email_field]" value="<?php echo esc_attr($fi['email_field']); ?>" placeholder="np. 436dec" class="evo-w-sm">
            <div class="evo-desc">Auto-detect jeśli puste.</div>
        </div>
        <div class="evo-field">
            <label>Szablon nazwy w sidebarze</label>
            <input type="text" name="evk_forminbox[name_template]" value="<?php echo esc_attr($fi['name_template'] ?? ''); ?>" placeholder="np. {{nazwisko}} {{imie}}" class="evo-w-lg">
            <div class="evo-desc">Używa {{klucz}} — te same co mapowanie pól. Jeśli puste — auto-detect.</div>
        </div>
        <div class="evo-field">
            <label>Klucz pola podglądu (sidebar)</label>
            <input type="text" name="evk_forminbox[preview_field]" value="<?php echo esc_attr($fi['preview_field'] ?? ''); ?>" placeholder="np. fonlfr (Temat)" class="evo-w-md">
            <div class="evo-desc">Treść tego pola pojawia się pod nazwą w liście. Jeśli puste — pierwsze pole.</div>
        </div>
        <div class="evo-field">
            <label>Klucz pola tematu (nagłówek)</label>
            <input type="text" name="evk_forminbox[subject_field]" value="<?php echo esc_attr($fi['subject_field'] ?? ''); ?>" placeholder="np. fonlfr" class="evo-w-md">
            <div class="evo-desc">Temat pokazany pod nazwą w nagłówku wiadomości. Jeśli puste — auto-detekcja (pole „Temat").</div>
        </div>
    </div>

    <hr class="evo-divider">

    <!-- ── MAPOWANIE PÓŁ ────────────────────────────────────────────── -->
    <p class="evo-section-title">Mapowanie pól</p>
    <div class="evo-info-box">
        <span class="dashicons dashicons-info"></span>
        <div>
            Przypisz czytelne nazwy do kluczy pól Bricks. Klucz to krótki identyfikator z Bricks (np. <code>fonlfr</code>, <code>436dec</code>).
            Użyj <strong>Załaduj z bazy</strong> aby auto-wykryć klucze z istniejących zgłoszeń, lub dodaj ręcznie.
        </div>
    </div>

    <div class="evo-toolbar">
        <?php if ($has_tbl): ?>
        <button type="button" id="evk-load-fields" class="button">
            <span class="dashicons dashicons-update evo-ico"></span>
            Załaduj klucze z bazy
        </button>
        <?php endif; ?>
        <button type="button" id="evk-add-field-row" class="button button-secondary">
            <span class="dashicons dashicons-plus evo-ico"></span>
            Dodaj wiersz
        </button>
        <span id="evk-fields-msg" class="evo-hint"></span>
    </div>

    <div id="evk-fields-table-wrap">
        <table id="evk-fields-table" class="evo-table">
            <thead>
                <tr>
                    <th style="width:220px">Klucz Bricks</th>
                    <th>Twoja nazwa</th>
                    <th class="is-center" style="width:60px">Ukryj</th>
                    <th style="width:36px"></th>
                </tr>
            </thead>
            <tbody id="evk-fields-tbody">
                <?php
                // Renderuj zapisane mapowania
                $saved_labels = $fi['field_labels'] ?? [];
                $saved_hidden = $fi['hidden_fields'] ?? [];
                if (!empty($saved_labels)):
                    foreach ($saved_labels as $fk => $fl):
                        $is_hidden = in_array($fk, $saved_hidden, true);
                ?>
                <tr class="evk-field-row">
                    <td>
                        <input type="text" name="evk_forminbox[field_labels_keys][]"
                               value="<?php echo esc_attr($fk); ?>"
                               placeholder="klucz" class="evo-w-full evo-mono">
                    </td>
                    <td>
                        <input type="text" name="evk_forminbox[field_labels_vals][]"
                               value="<?php echo esc_attr($fl); ?>"
                               placeholder="Twoja nazwa" class="evo-w-full">
                    </td>
                    <td class="is-center">
                        <input type="checkbox" name="evk_forminbox[hidden_fields][]"
                               value="<?php echo esc_attr($fk); ?>" <?php checked($is_hidden); ?>
                               class="evk-hidden-cb">
                    </td>
                    <td class="is-center is-tight">
                        <button type="button" class="evk-remove-row evo-btn-plain is-danger" title="Usuń wiersz">
                            <span class="dashicons dashicons-no-alt evo-ico"></span>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <tr class="evk-field-row-empty" id="evk-no-rows">
                    <td colspan="4" class="evo-empty">
                        Brak mapowań. Załaduj klucze z bazy lub dodaj ręcznie.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <hr class="evo-divider">
    <p class="evo-section-title">Układ pól — nagłówek i lewy panel</p>
    <div class="evo-info-box">
        <span class="dashicons dashicons-info"></span>
        <div>
            Ustaw które pola i w jakiej kolejności pojawiają się w <strong>nagłówku wiadomości</strong> oraz na <strong>liście (lewy panel)</strong>.
            Strzałkami zmieniasz kolejność. Puste = autodetekcja.
            <br>Możesz łączyć kilka pól w jednej linii — wpisz szablon, np. <code>{{nazwisko}} {{imie}}</code>, albo użyj selektora <strong>▾</strong> aby wstawić pole.
        </div>
    </div>
    <?php
    $evk_type_labels = [
        'header'  => ['title' => 'Tytuł (duży)', 'subtitle' => 'Podtytuł / temat', 'meta' => 'Meta (mała linia)'],
        'sidebar' => ['name'  => 'Nazwa (pogrubiona)', 'preview' => 'Podgląd', 'meta' => 'Meta (mała linia)'],
    ];
    $evk_render_layout_rows = function ($rows, $group) use ($evk_type_labels) {
        $tl = $evk_type_labels[$group];
        if (empty($rows)) {
            echo '<tr class="evk-layout-empty" data-group="' . esc_attr($group) . '"><td colspan="3" class="evo-empty">Brak pól — autodetekcja.</td></tr>';
            return;
        }
        foreach ($rows as $r) {
            $opts = '';
            foreach ($tl as $tk => $tlbl) {
                $opts .= '<option value="' . esc_attr($tk) . '"' . selected($r['type'], $tk, false) . '>' . esc_html($tlbl) . '</option>';
            }
            echo '<tr class="evk-layout-row" data-group="' . esc_attr($group) . '">'
                . '<td><div class="evo-inline">'
                    . '<input type="text" class="evk-layout-tpl evo-mono" name="evk_forminbox[' . esc_attr($group) . '_layout_keys][]" value="' . esc_attr($r['key']) . '" placeholder="{{nazwisko}} {{imie}}">'
                    . '<select class="evk-key-insert evo-select-thin" title="Wstaw pole"></select>'
                    . '</div></td>'
                . '<td style="width:150px"><select name="evk_forminbox[' . esc_attr($group) . '_layout_types][]" class="evo-w-full">' . $opts . '</select></td>'
                . '<td class="is-right is-tight" style="width:78px">'
                    . '<button type="button" class="evk-row-up evo-btn-plain" title="W górę"><span class="dashicons dashicons-arrow-up-alt2 evo-ico-sm"></span></button>'
                    . '<button type="button" class="evk-row-down evo-btn-plain" title="W dół"><span class="dashicons dashicons-arrow-down-alt2 evo-ico-sm"></span></button>'
                    . '<button type="button" class="evk-layout-remove evo-btn-plain is-danger" title="Usuń"><span class="dashicons dashicons-no-alt evo-ico"></span></button>'
                . '</td></tr>';
        }
    };
    ?>
    <div class="evo-grid-2 evo-mb-lg" style="--evo-gap:24px">
        <div>
            <div class="evo-toolbar" style="margin-bottom:8px">
                <strong class="evo-col-title">Nagłówek wiadomości</strong>
                <button type="button" id="evk-add-header-row" class="button button-secondary">
                    <span class="dashicons dashicons-plus evo-ico"></span> Dodaj pole
                </button>
            </div>
            <table class="evo-table">
                <tbody id="evk-header-tbody"><?php $evk_render_layout_rows($fi['header_layout'] ?? [], 'header'); ?></tbody>
            </table>
        </div>
        <div>
            <div class="evo-toolbar" style="margin-bottom:8px">
                <strong class="evo-col-title">Lewy panel (lista)</strong>
                <button type="button" id="evk-add-sidebar-row" class="button button-secondary">
                    <span class="dashicons dashicons-plus evo-ico"></span> Dodaj pole
                </button>
            </div>
            <table class="evo-table">
                <tbody id="evk-sidebar-tbody"><?php $evk_render_layout_rows($fi['sidebar_layout'] ?? [], 'sidebar'); ?></tbody>
            </table>
        </div>
    </div>

    <!-- ── SZABLON WIADOMOŚCI ────────────────────────────────────────── -->
    <hr class="evo-divider">
    <p class="evo-section-title">Nazwy formularzy</p>
    <div class="evo-info-box">
        <span class="dashicons dashicons-info"></span>
        <div>
            Przypisz czytelne nazwy do identyfikatorów formularzy Bricks (np. <code>yrckyz</code> → <em>Formularz kontaktowy</em>).
            Nazwa pojawia się w sidebarze, nagłówku wiadomości i filtrze formularzy.
        </div>
    </div>
    <div class="evo-toolbar">
        <button type="button" id="evk-add-form-row" class="button button-secondary">
            <span class="dashicons dashicons-plus evo-ico"></span>
            Dodaj formularz
        </button>
        <?php if ($has_tbl): ?>
        <button type="button" id="evk-load-forms" class="button">
            <span class="dashicons dashicons-update evo-ico"></span>
            Załaduj ID z bazy
        </button>
        <?php endif; ?>
        <span id="evk-forms-msg" class="evo-hint"></span>
    </div>
    <table class="evo-table evo-mb-lg">
        <thead>
            <tr>
                <th style="width:200px">ID formularza Bricks</th>
                <th>Twoja nazwa</th>
                <th style="width:36px"></th>
            </tr>
        </thead>
        <tbody id="evk-forms-tbody">
            <?php
            $saved_form_names = $fi['form_names'] ?? [];
            if (!empty($saved_form_names)):
                foreach ($saved_form_names as $fid => $fname): ?>
            <tr class="evk-form-row">
                <td>
                    <input type="text" name="evk_forminbox[form_names_keys][]" value="<?php echo esc_attr($fid); ?>" placeholder="ID formularza" class="evo-w-full evo-mono">
                </td>
                <td>
                    <input type="text" name="evk_forminbox[form_names_vals][]" value="<?php echo esc_attr($fname); ?>" placeholder="Czytelna nazwa" class="evo-w-full">
                </td>
                <td class="is-center is-tight">
                    <button type="button" class="evk-remove-form-row evo-btn-plain is-danger" title="Usuń">
                        <span class="dashicons dashicons-no-alt evo-ico"></span>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr id="evk-no-form-rows"><td colspan="3" class="evo-empty">Brak mapowań formularzy.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <hr class="evo-divider" style="margin-top:0">
    <p class="evo-section-title">Szablon wyświetlania wiadomości</p>
    <div class="evo-info-box">
        <span class="dashicons dashicons-info"></span>
        <div>
            Zdefiniuj jak wyglądać będzie wiadomość w podglądzie. Użyj <code>{{klucz}}</code> aby wstawić wartość pola (krótki klucz Bricks, np. <code>{{fonlfr}}</code>).
            Jeśli szablon jest pusty — pola wyświetlane są automatycznie jako karty.
            <br>Dostępne zmienne: <span id="evk-available-vars" class="evo-mono evo-hint-sm evo-accent-tx"></span>
        </div>
    </div>

    <div class="evo-grid-2 evo-mb-lg">
        <div class="evo-field">
            <label>Szablon</label>
            <textarea id="evk-template-editor" name="evk_forminbox[message_template]"
                      rows="14"
                      class="evo-w-full evo-mono evo-code-area"
                      placeholder="Temat: {{fonlfr}}&#10;Od: {{imie}} {{nazwisko}}&#10;E-mail: {{email}}&#10;&#10;Wiadomość:&#10;{{tresc}}&#10;---&#10;Wiadomość z formularza."><?php echo esc_textarea($fi['message_template'] ?? ''); ?></textarea>
            <div class="evo-desc">Kliknij na zmienną po prawej aby wstawić do kursora.</div>
        </div>
        <div class="evo-field">
            <label>Podgląd (z fikcyjnymi danymi)</label>
            <div id="evk-template-preview" class="evo-preview"></div>
            <div class="evo-desc">Rzeczywiste dane zobaczysz po otwarciu wiadomości w skrzynce.</div>
        </div>
    </div>

    <div id="evk-vars-palette" class="evo-chips"></div>

    <div class="evo-save-bar">
        <?php submit_button('Zapisz ustawienia', 'primary', 'submit', false); ?>
    </div>
</form>

<script>
(function($) {
    var NONCE = <?php echo json_encode($nonce); ?>;

    // ── Mapa klucz → etykieta (live z tabeli) ────────────────
    function getFieldMap() {
        var map = {};
        $('#evk-fields-tbody .evk-field-row').each(function() {
            var key = $(this).find('input[name*="field_labels_keys"]').val().trim();
            var val = $(this).find('input[name*="field_labels_vals"]').val().trim();
            if (key) map[key] = val || ('{{' + key + '}}');
        });
        return map;
    }

    // ── Aktualizuj dostępne zmienne i paletę ─────────────────
    function updateVarPalette() {
        var map = getFieldMap();
        var keys = Object.keys(map);

        // Info bar
        if (keys.length) {
            $('#evk-available-vars').text(keys.map(function(k){ return '{{' + k + '}}'; }).join('  '));
        } else {
            $('#evk-available-vars').text('(brak mapowań — dodaj pola powyżej)');
        }

        // Paleta chipów
        var chipsHtml = keys.length
            ? keys.map(function(k) {
                var lbl = map[k] !== ('{{' + k + '}}') ? map[k] : k;
                return '<span class="evo-chip evk-var-chip" data-var="{{' + k + '}}" title="' + lbl + '">{{' + k + '}}</span>';
              }).join('')
            : '<span class="evo-hint evo-faint">Dodaj mapowania pól aby zobaczyć dostępne zmienne.</span>';
        $('#evk-vars-palette').html(chipsHtml);

        updatePreview(map);
        refreshKeySelects();
    }

    // ── Podgląd szablonu z fikcyjnymi danymi ──────────────────
    function updatePreview(map) {
        var tpl = $('#evk-template-editor').val();
        if (!tpl) { $('#evk-template-preview').text('(brak szablonu)'); return; }
        var map2 = map || getFieldMap();
        var out = tpl;
        $.each(map2, function(k, label) {
            var fakeVal = label !== ('{{' + k + '}}') ? '[' + label + ']' : '[wartość ' + k + ']';
            out = out.split('{{' + k + '}}').join(fakeVal);
        });
        // Pozostałe {{...}} oznacz jako nieznane
        out = out.replace(/\{\{([^}]+)\}\}/g, '[???]');
        $('#evk-template-preview').text(out);
    }

    // ── Wstaw zmienną do kursora w textarea ──────────────────
    $(document).on('click', '.evk-var-chip', function() {
        var varStr = $(this).data('var');
        var ta     = document.getElementById('evk-template-editor');
        var start  = ta.selectionStart;
        var end    = ta.selectionEnd;
        var val    = ta.value;
        ta.value   = val.substring(0, start) + varStr + val.substring(end);
        ta.selectionStart = ta.selectionEnd = start + varStr.length;
        ta.focus();
        updatePreview();
    });

    // ── Dodaj pusty wiersz ────────────────────────────────────
    function addRow(key, label, hidden) {
        key   = key   || '';
        label = label || '';
        $('#evk-no-rows').remove();
        var hidCk = hidden ? 'checked' : '';
        // Aktualizuj hidden checkbox name przy ukrywaniu
        var row = $('<tr class="evk-field-row">' +
            '<td><input type="text" name="evk_forminbox[field_labels_keys][]" value="' + esc(key) + '" placeholder="klucz" class="evo-w-full evo-mono"></td>' +
            '<td><input type="text" name="evk_forminbox[field_labels_vals][]" value="' + esc(label) + '" placeholder="Twoja nazwa" class="evo-w-full"></td>' +
            '<td class="is-center"><input type="checkbox" name="evk_forminbox[hidden_fields][]" value="" class="evk-hidden-cb" ' + hidCk + '></td>' +
            '<td class="is-center is-tight">' +
                '<button type="button" class="evk-remove-row evo-btn-plain is-danger" title="Usuń wiersz">' +
                    '<span class="dashicons dashicons-no-alt evo-ico"></span>' +
                '</button>' +
            '</td>' +
        '</tr>');
        $('#evk-fields-tbody').append(row);
        syncHiddenCbValues();
        updateVarPalette();
    }

    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    // Hidden checkbox value musi być równy kluczowi z pola obok
    function syncHiddenCbValues() {
        $('#evk-fields-tbody .evk-field-row').each(function() {
            var key = $(this).find('input[name*="field_labels_keys"]').val().trim();
            $(this).find('.evk-hidden-cb').val(key);
        });
    }

    // ── Zdarzenia tabeli ──────────────────────────────────────
    $('#evk-add-field-row').on('click', function() { addRow(); });

    $(document).on('click', '.evk-remove-row', function() {
        $(this).closest('tr').remove();
        if ($('#evk-fields-tbody .evk-field-row').length === 0) {
            $('#evk-fields-tbody').append('<tr class="evk-field-row-empty" id="evk-no-rows"><td colspan="4" class="evo-empty">Brak mapowań.</td></tr>');
        }
        updateVarPalette();
    });

    $(document).on('input', '#evk-fields-tbody input', function() {
        syncHiddenCbValues();
        updateVarPalette();
    });

    $(document).on('input', '#evk-template-editor', function() { updatePreview(); });

    // ── Załaduj klucze z bazy ────────────────────────────────
    <?php if ($has_tbl): ?>
    $('#evk-load-fields').on('click', function() {
        $('#evk-fields-msg').text('Ładowanie…');
        $.get(window.ajaxurl, { action: 'evk_inbox_field_keys', nonce: NONCE }, function(r) {
            if (!r.success) { $('#evk-fields-msg').text('Błąd.'); return; }
            var keys     = r.data.keys;
            var existing = {};
            // Zachowaj istniejące mapowania
            $('#evk-fields-tbody .evk-field-row').each(function() {
                var k = $(this).find('input[name*="field_labels_keys"]').val().trim();
                var v = $(this).find('input[name*="field_labels_vals"]').val().trim();
                if (k) existing[k] = v;
            });
            // Dodaj brakujące klucze
            var added = 0;
            keys.forEach(function(k) {
                if (!existing[k.key]) {
                    addRow(k.key, k.label !== k.key ? k.label : '', k.hidden);
                    added++;
                }
            });
            $('#evk-fields-msg').text(added ? added + ' nowych kluczy dodano.' : 'Brak nowych kluczy — wszystkie już skonfigurowane.');
        });
    });
    <?php endif; ?>

    // ── Init ─────────────────────────────────────────────────
    // Zsynchronizuj wartości hidden checkbox (klucze z inputów)
    syncHiddenCbValues();
    updateVarPalette();

    // ── Tabela nazw formularzy ────────────────────────────────
    function addFormRow(id, name) {
        $('#evk-no-form-rows').remove();
        var row = $('<tr class="evk-form-row">' +
            '<td><input type="text" name="evk_forminbox[form_names_keys][]" value="' + esc(id||'') + '" placeholder="ID formularza" class="evo-w-full evo-mono"></td>' +
            '<td><input type="text" name="evk_forminbox[form_names_vals][]" value="' + esc(name||'') + '" placeholder="Czytelna nazwa" class="evo-w-full"></td>' +
            '<td class="is-center is-tight"><button type="button" class="evk-remove-form-row evo-btn-plain is-danger" title="Usuń"><span class="dashicons dashicons-no-alt evo-ico"></span></button></td>' +
        '</tr>');
        $('#evk-forms-tbody').append(row);
    }

    $('#evk-add-form-row').on('click', function() { addFormRow('', ''); });

    $(document).on('click', '.evk-remove-form-row', function() {
        $(this).closest('tr').remove();
        if (!$('#evk-forms-tbody .evk-form-row').length) {
            $('#evk-forms-tbody').append('<tr id="evk-no-form-rows"><td colspan="3" class="evo-empty">Brak mapowań formularzy.</td></tr>');
        }
    });

    <?php if ($has_tbl): ?>
    $('#evk-load-forms').on('click', function() {
        $('#evk-forms-msg').text('Ładowanie…');
        $.get(window.ajaxurl, { action: 'evk_inbox_forms', nonce: NONCE }, function(r) {
            if (!r.success) { $('#evk-forms-msg').text('Błąd: ' + (r.data || 'nieznany')); return; }
            var existing = {};
            $('#evk-forms-tbody .evk-form-row').each(function() {
                existing[$(this).find('input:first').val().trim()] = true;
            });
            var added = 0;
            (r.data.forms || []).forEach(function(f) {
                if (!existing[f.form_id]) { addFormRow(f.form_id, ''); added++; }
            });
            $('#evk-forms-msg').text(added ? added + ' ID dodano.' : 'Wszystkie już skonfigurowane.');
        });
    });
    <?php endif; ?>

    // ── Układ pól (nagłówek / sidebar) ───────────────────────
    var EVK_TYPE_OPTS = {
        header:  [['title', 'Tytuł (duży)'], ['subtitle', 'Podtytuł / temat'], ['meta', 'Meta (mała linia)']],
        sidebar: [['name', 'Nazwa (pogrubiona)'], ['preview', 'Podgląd'], ['meta', 'Meta (mała linia)']]
    };

    function fieldInsertOptions() {
        var map  = getFieldMap();
        var html = '<option value="">\u25be</option>'; // ▾
        Object.keys(map).forEach(function(k) {
            var lbl = map[k] !== ('{{' + k + '}}') ? map[k] : k;
            html += '<option value="' + esc(k) + '">' + esc(lbl) + ' (' + esc(k) + ')</option>';
        });
        return html;
    }

    function refreshKeySelects() {
        var opts = fieldInsertOptions();
        $('.evk-key-insert').html(opts);
    }

    function layoutRow(group, tpl, type) {
        var topts = EVK_TYPE_OPTS[group].map(function(o) {
            return '<option value="' + o[0] + '"' + (o[0] === type ? ' selected' : '') + '>' + o[1] + '</option>';
        }).join('');
        return $('<tr class="evk-layout-row" data-group="' + group + '">' +
            '<td><div class="evo-inline">' +
                '<input type="text" class="evk-layout-tpl evo-mono" name="evk_forminbox[' + group + '_layout_keys][]" value="' + esc(tpl || '') + '" placeholder="{{nazwisko}} {{imie}}">' +
                '<select class="evk-key-insert evo-select-thin" title="Wstaw pole"></select>' +
            '</div></td>' +
            '<td style="width:150px"><select name="evk_forminbox[' + group + '_layout_types][]" class="evo-w-full">' + topts + '</select></td>' +
            '<td class="is-right is-tight" style="width:78px">' +
                '<button type="button" class="evk-row-up evo-btn-plain" title="W górę"><span class="dashicons dashicons-arrow-up-alt2 evo-ico-sm"></span></button>' +
                '<button type="button" class="evk-row-down evo-btn-plain" title="W dół"><span class="dashicons dashicons-arrow-down-alt2 evo-ico-sm"></span></button>' +
                '<button type="button" class="evk-layout-remove evo-btn-plain is-danger" title="Usuń"><span class="dashicons dashicons-no-alt evo-ico"></span></button>' +
            '</td></tr>');
    }

    function addLayoutRow(group) {
        var tbody = $('#evk-' + group + '-tbody');
        tbody.find('.evk-layout-empty').remove();
        var row = layoutRow(group, '', EVK_TYPE_OPTS[group][0][0]);
        tbody.append(row);
        row.find('.evk-key-insert').html(fieldInsertOptions());
    }

    // Wstaw {{klucz}} do szablonu linii po wyborze z selektora
    $(document).on('change', '.evk-key-insert', function() {
        var key = $(this).val();
        if (!key) return;
        var input = $(this).closest('div').find('.evk-layout-tpl');
        var cur   = input.val();
        input.val((cur && cur.trim() ? cur.replace(/\s+$/, '') + ' ' : '') + '{{' + key + '}}');
        $(this).val('');
    });

    $('#evk-add-header-row').on('click',  function() { addLayoutRow('header'); });
    $('#evk-add-sidebar-row').on('click', function() { addLayoutRow('sidebar'); });

    $(document).on('click', '.evk-row-up', function() {
        var tr = $(this).closest('tr'), prev = tr.prev('.evk-layout-row');
        if (prev.length) prev.before(tr);
    });
    $(document).on('click', '.evk-row-down', function() {
        var tr = $(this).closest('tr'), next = tr.next('.evk-layout-row');
        if (next.length) next.after(tr);
    });
    $(document).on('click', '.evk-layout-remove', function() {
        var tbody = $(this).closest('tbody');
        var group = tbody.attr('id').replace('evk-', '').replace('-tbody', '');
        $(this).closest('tr').remove();
        if (!tbody.find('.evk-layout-row').length) {
            tbody.append('<tr class="evk-layout-empty" data-group="' + group + '"><td colspan="3" class="evo-empty">Brak pól — autodetekcja.</td></tr>');
        }
    });

    // Wypełnij selecty kluczy na starcie (mapowanie już w DOM)
    refreshKeySelects();

})(jQuery);
</script>
