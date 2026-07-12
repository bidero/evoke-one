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
        <span class="dashicons dashicons-email-alt" style="font-size:24px;width:24px;height:24px;line-height:1;"></span>
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
<div class="evo-info-box" style="border-color:#fde68a;background:#fffbeb;margin-top:16px;">
    <span class="dashicons dashicons-warning" style="color:#d97706;"></span>
    <div>Tabela zgłoszeń Bricks nie istnieje. Przejdź do <strong>Bricks → Ustawienia → Ogólne</strong> i włącz
    <em>„Zapisuj zgłoszenia formularzy"</em>, a następnie w każdym formularzu dodaj akcję <em>„Save Submission"</em>.</div>
</div>
<?php elseif (!empty($fi['enabled'])): ?>
<div class="evo-info-box" style="border-color:#86efac;background:#f0fdf4;margin-top:16px;">
    <span class="dashicons dashicons-yes-alt" style="color:#16a34a;"></span>
    <div>Moduł aktywny.
        <a href="<?php echo esc_url($inbox_url); ?>" class="button button-secondary" style="margin-left:10px;display:inline-flex;align-items:center;gap:5px;vertical-align:middle;">
            <span class="dashicons dashicons-email-alt" style="font-size:16px;width:16px;height:16px;line-height:1;"></span>
            Otwórz skrzynkę
        </a>
    </div>
</div>
<?php endif; ?>

<form method="post" action="options.php" style="margin-top:24px;">
    <?php settings_fields(EVK_INBOX_OPTION . '_group'); ?>
    <?php /* 'enabled' zapisywany przez AJAX toggle — sanitizer zachowuje stan przy zapisie formularza */ ?>

    <!-- ── MENU ─────────────────────────────────────────────────────── -->
    <p class="evo-section-title">Konfiguracja menu</p>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:14px;margin-bottom:24px;">
        <div class="evo-field" style="margin:0;">
            <label>Nazwa menu</label>
            <input type="text" name="evk_forminbox[menu_label]" value="<?php echo esc_attr($fi['menu_label']); ?>" placeholder="Wiadomości">
        </div>
        <div class="evo-field" style="margin:0;">
            <label>Ikona (Dashicons)</label>
            <input type="text" name="evk_forminbox[menu_icon]" value="<?php echo esc_attr($fi['menu_icon']); ?>" placeholder="dashicons-email-alt">
            <div class="evo-desc"><a href="https://developer.wordpress.org/resource/dashicons/" target="_blank">Lista ↗</a></div>
        </div>
        <div class="evo-field" style="margin:0;">
            <label>Pozycja w menu</label>
            <input type="number" name="evk_forminbox[menu_position]" value="<?php echo esc_attr($fi['menu_position']); ?>" min="1" max="100" style="max-width:80px;">
        </div>
        <div class="evo-field" style="margin:0;">
            <label>Wiadomości na stronę</label>
            <input type="number" name="evk_forminbox[per_page]" value="<?php echo esc_attr($fi['per_page']); ?>" min="5" max="100" style="max-width:80px;">
        </div>
        <div class="evo-field" style="margin:0;">
            <label>Plakietka w menu</label>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
                <input type="checkbox" name="evk_forminbox[menu_badge]" value="1" <?php checked(!empty($fi['menu_badge'])); ?>>
                <span>Pokaż licznik nieprzeczytanych przy pozycji menu</span>
            </label>
        </div>
        <div class="evo-field" style="margin:0;">
            <label>Klucz pola e-mail</label>
            <input type="text" name="evk_forminbox[email_field]" value="<?php echo esc_attr($fi['email_field']); ?>" placeholder="np. 436dec" style="max-width:160px;">
            <div class="evo-desc">Auto-detect jeśli puste.</div>
        </div>
        <div class="evo-field" style="margin:0;">
            <label>Szablon nazwy w sidebarze</label>
            <input type="text" name="evk_forminbox[name_template]" value="<?php echo esc_attr($fi['name_template'] ?? ''); ?>" placeholder="np. {{nazwisko}} {{imie}}" style="max-width:260px;">
            <div class="evo-desc">Używa {{klucz}} — te same co mapowanie pól. Jeśli puste — auto-detect.</div>
        </div>
        <div class="evo-field" style="margin:0;">
            <label>Klucz pola podglądu (sidebar)</label>
            <input type="text" name="evk_forminbox[preview_field]" value="<?php echo esc_attr($fi['preview_field'] ?? ''); ?>" placeholder="np. fonlfr (Temat)" style="max-width:200px;">
            <div class="evo-desc">Treść tego pola pojawia się pod nazwą w liście. Jeśli puste — pierwsze pole.</div>
        </div>
        <div class="evo-field" style="margin:0;">
            <label>Klucz pola tematu (nagłówek)</label>
            <input type="text" name="evk_forminbox[subject_field]" value="<?php echo esc_attr($fi['subject_field'] ?? ''); ?>" placeholder="np. fonlfr" style="max-width:200px;">
            <div class="evo-desc">Temat pokazany pod nazwą w nagłówku wiadomości. Jeśli puste — auto-detekcja (pole „Temat").</div>
        </div>
    </div>

    <hr class="evo-divider">

    <!-- ── MAPOWANIE PÓŁ ────────────────────────────────────────────── -->
    <p class="evo-section-title">Mapowanie pól</p>
    <div class="evo-info-box" style="margin-bottom:14px;">
        <span class="dashicons dashicons-info"></span>
        <div>
            Przypisz czytelne nazwy do kluczy pól Bricks. Klucz to krótki identyfikator z Bricks (np. <code>fonlfr</code>, <code>436dec</code>).
            Użyj <strong>Załaduj z bazy</strong> aby auto-wykryć klucze z istniejących zgłoszeń, lub dodaj ręcznie.
        </div>
    </div>

    <div style="margin-bottom:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <?php if ($has_tbl): ?>
        <button type="button" id="evk-load-fields" class="button" style="display:inline-flex;align-items:center;gap:5px;vertical-align:middle;">
            <span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;line-height:1;"></span>
            Załaduj klucze z bazy
        </button>
        <?php endif; ?>
        <button type="button" id="evk-add-field-row" class="button button-secondary" style="display:inline-flex;align-items:center;gap:5px;vertical-align:middle;">
            <span class="dashicons dashicons-plus" style="font-size:16px;width:16px;height:16px;line-height:1;"></span>
            Dodaj wiersz
        </button>
        <span id="evk-fields-msg" style="font-size:12px;color:#6b7280;"></span>
    </div>

    <div id="evk-fields-table-wrap">
        <table id="evk-fields-table" style="width:100%;border-collapse:collapse;font-size:13px;">
            <thead>
                <tr style="border-bottom:2px solid #e2e8f0;">
                    <th style="text-align:left;padding:8px 10px;color:#6b7280;font-weight:600;width:220px;">Klucz Bricks</th>
                    <th style="text-align:left;padding:8px 10px;color:#6b7280;font-weight:600;">Twoja nazwa</th>
                    <th style="text-align:center;padding:8px 10px;color:#6b7280;font-weight:600;width:60px;">Ukryj</th>
                    <th style="width:36px;"></th>
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
                <tr class="evk-field-row" style="border-bottom:1px solid #f1f5f9;">
                    <td style="padding:6px 10px;">
                        <input type="text" name="evk_forminbox[field_labels_keys][]"
                               value="<?php echo esc_attr($fk); ?>"
                               placeholder="klucz" style="width:100%;border:1px solid #d1d5db;border-radius:5px;padding:5px 8px;font-size:12px;font-family:monospace;">
                    </td>
                    <td style="padding:6px 10px;">
                        <input type="text" name="evk_forminbox[field_labels_vals][]"
                               value="<?php echo esc_attr($fl); ?>"
                               placeholder="Twoja nazwa" style="width:100%;border:1px solid #d1d5db;border-radius:5px;padding:5px 8px;font-size:13px;">
                    </td>
                    <td style="padding:6px 10px;text-align:center;">
                        <input type="checkbox" name="evk_forminbox[hidden_fields][]"
                               value="<?php echo esc_attr($fk); ?>" <?php checked($is_hidden); ?>
                               class="evk-hidden-cb">
                    </td>
                    <td style="padding:6px 4px;text-align:center;">
                        <button type="button" class="evk-remove-row button-link" style="color:#ef4444;padding:2px 4px;" title="Usuń wiersz">
                            <span class="dashicons dashicons-no-alt" style="font-size:16px;width:16px;height:16px;line-height:1;"></span>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php else: ?>
                <tr class="evk-field-row-empty" id="evk-no-rows">
                    <td colspan="4" style="padding:16px 10px;color:#94a3b8;font-style:italic;text-align:center;">
                        Brak mapowań. Załaduj klucze z bazy lub dodaj ręcznie.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <hr class="evo-divider" style="margin-top:24px;">
    <p class="evo-section-title">Układ pól — nagłówek i lewy panel</p>
    <div class="evo-info-box" style="margin-bottom:14px;">
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
            echo '<tr class="evk-layout-empty" data-group="' . esc_attr($group) . '"><td colspan="3" style="padding:12px 8px;color:#94a3b8;font-style:italic;">Brak pól — autodetekcja.</td></tr>';
            return;
        }
        foreach ($rows as $r) {
            $opts = '';
            foreach ($tl as $tk => $tlbl) {
                $opts .= '<option value="' . esc_attr($tk) . '"' . selected($r['type'], $tk, false) . '>' . esc_html($tlbl) . '</option>';
            }
            echo '<tr class="evk-layout-row" data-group="' . esc_attr($group) . '" style="border-bottom:1px solid #f1f5f9;">'
                . '<td style="padding:5px 6px;"><div style="display:flex;gap:4px;align-items:center;">'
                    . '<input type="text" class="evk-layout-tpl" name="evk_forminbox[' . esc_attr($group) . '_layout_keys][]" value="' . esc_attr($r['key']) . '" placeholder="{{nazwisko}} {{imie}}" style="flex:1;min-width:0;border:1px solid #d1d5db;border-radius:5px;padding:4px 6px;font-size:12px;font-family:monospace;">'
                    . '<select class="evk-key-insert" title="Wstaw pole" style="width:38px;flex-shrink:0;border:1px solid #d1d5db;border-radius:5px;padding:4px 2px;font-size:12px;"></select>'
                    . '</div></td>'
                . '<td style="padding:5px 6px;width:150px;"><select name="evk_forminbox[' . esc_attr($group) . '_layout_types][]" style="width:100%;border:1px solid #d1d5db;border-radius:5px;padding:4px 6px;font-size:12px;">' . $opts . '</select></td>'
                . '<td style="padding:5px 4px;width:78px;white-space:nowrap;text-align:right;">'
                    . '<button type="button" class="evk-row-up button-link" title="W górę" style="padding:2px;"><span class="dashicons dashicons-arrow-up-alt2" style="font-size:14px;width:14px;height:14px;"></span></button>'
                    . '<button type="button" class="evk-row-down button-link" title="W dół" style="padding:2px;"><span class="dashicons dashicons-arrow-down-alt2" style="font-size:14px;width:14px;height:14px;"></span></button>'
                    . '<button type="button" class="evk-layout-remove button-link" title="Usuń" style="padding:2px;color:#ef4444;"><span class="dashicons dashicons-no-alt" style="font-size:16px;width:16px;height:16px;"></span></button>'
                . '</td></tr>';
        }
    };
    ?>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:24px;margin-bottom:24px;">
        <div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <strong style="font-size:13px;color:#374151;">Nagłówek wiadomości</strong>
                <button type="button" id="evk-add-header-row" class="button button-secondary" style="display:inline-flex;align-items:center;gap:5px;vertical-align:middle;">
                    <span class="dashicons dashicons-plus" style="font-size:16px;width:16px;height:16px;line-height:1;"></span> Dodaj pole
                </button>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <tbody id="evk-header-tbody"><?php $evk_render_layout_rows($fi['header_layout'] ?? [], 'header'); ?></tbody>
            </table>
        </div>
        <div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                <strong style="font-size:13px;color:#374151;">Lewy panel (lista)</strong>
                <button type="button" id="evk-add-sidebar-row" class="button button-secondary" style="display:inline-flex;align-items:center;gap:5px;vertical-align:middle;">
                    <span class="dashicons dashicons-plus" style="font-size:16px;width:16px;height:16px;line-height:1;"></span> Dodaj pole
                </button>
            </div>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <tbody id="evk-sidebar-tbody"><?php $evk_render_layout_rows($fi['sidebar_layout'] ?? [], 'sidebar'); ?></tbody>
            </table>
        </div>
    </div>

    <!-- ── SZABLON WIADOMOŚCI ────────────────────────────────────────── -->
    <hr class="evo-divider" style="margin-top:24px;">
    <p class="evo-section-title">Nazwy formularzy</p>
    <div class="evo-info-box" style="margin-bottom:14px;">
        <span class="dashicons dashicons-info"></span>
        <div>
            Przypisz czytelne nazwy do identyfikatorów formularzy Bricks (np. <code>yrckyz</code> → <em>Formularz kontaktowy</em>).
            Nazwa pojawia się w sidebarze, nagłówku wiadomości i filtrze formularzy.
        </div>
    </div>
    <div style="margin-bottom:10px;display:flex;align-items:center;gap:10px;">
        <button type="button" id="evk-add-form-row" class="button button-secondary" style="display:inline-flex;align-items:center;gap:5px;vertical-align:middle;">
            <span class="dashicons dashicons-plus" style="font-size:16px;width:16px;height:16px;line-height:1;"></span>
            Dodaj formularz
        </button>
        <?php if ($has_tbl): ?>
        <button type="button" id="evk-load-forms" class="button" style="display:inline-flex;align-items:center;gap:5px;vertical-align:middle;">
            <span class="dashicons dashicons-update" style="font-size:16px;width:16px;height:16px;line-height:1;"></span>
            Załaduj ID z bazy
        </button>
        <?php endif; ?>
        <span id="evk-forms-msg" style="font-size:12px;color:#6b7280;"></span>
    </div>
    <table style="width:100%;border-collapse:collapse;font-size:13px;margin-bottom:24px;">
        <thead>
            <tr style="border-bottom:2px solid #e2e8f0;">
                <th style="text-align:left;padding:8px 10px;color:#6b7280;font-weight:600;width:200px;">ID formularza Bricks</th>
                <th style="text-align:left;padding:8px 10px;color:#6b7280;font-weight:600;">Twoja nazwa</th>
                <th style="width:36px;"></th>
            </tr>
        </thead>
        <tbody id="evk-forms-tbody">
            <?php
            $saved_form_names = $fi['form_names'] ?? [];
            if (!empty($saved_form_names)):
                foreach ($saved_form_names as $fid => $fname): ?>
            <tr class="evk-form-row" style="border-bottom:1px solid #f1f5f9;">
                <td style="padding:6px 10px;">
                    <input type="text" name="evk_forminbox[form_names_keys][]" value="<?php echo esc_attr($fid); ?>" placeholder="ID formularza" style="width:100%;border:1px solid #d1d5db;border-radius:5px;padding:5px 8px;font-size:12px;font-family:monospace;">
                </td>
                <td style="padding:6px 10px;">
                    <input type="text" name="evk_forminbox[form_names_vals][]" value="<?php echo esc_attr($fname); ?>" placeholder="Czytelna nazwa" style="width:100%;border:1px solid #d1d5db;border-radius:5px;padding:5px 8px;font-size:13px;">
                </td>
                <td style="padding:6px 4px;text-align:center;">
                    <button type="button" class="evk-remove-form-row button-link" style="color:#ef4444;padding:2px 4px;">
                        <span class="dashicons dashicons-no-alt" style="font-size:16px;width:16px;height:16px;line-height:1;"></span>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php else: ?>
            <tr id="evk-no-form-rows"><td colspan="3" style="padding:14px 10px;color:#94a3b8;font-style:italic;text-align:center;">Brak mapowań formularzy.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <hr class="evo-divider" style="margin-top:0;">
    <p class="evo-section-title">Szablon wyświetlania wiadomości</p>
    <div class="evo-info-box" style="margin-bottom:14px;">
        <span class="dashicons dashicons-info"></span>
        <div>
            Zdefiniuj jak wyglądać będzie wiadomość w podglądzie. Użyj <code>{{klucz}}</code> aby wstawić wartość pola (krótki klucz Bricks, np. <code>{{fonlfr}}</code>).
            Jeśli szablon jest pusty — pola wyświetlane są automatycznie jako karty.
            <br>Dostępne zmienne: <span id="evk-available-vars" style="font-family:monospace;font-size:11px;color:#2563eb;"></span>
        </div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:24px;">
        <div class="evo-field" style="margin:0;">
            <label>Szablon</label>
            <textarea id="evk-template-editor" name="evk_forminbox[message_template]"
                      rows="14"
                      style="width:100%;font-family:monospace;font-size:12px;line-height:1.6;resize:vertical;border:1px solid #d1d5db;border-radius:6px;padding:10px;"
                      placeholder="Temat: {{fonlfr}}&#10;Od: {{imie}} {{nazwisko}}&#10;E-mail: {{email}}&#10;&#10;Wiadomość:&#10;{{tresc}}&#10;---&#10;Wiadomość z formularza."><?php echo esc_textarea($fi['message_template'] ?? ''); ?></textarea>
            <div class="evo-desc">Kliknij na zmienną po prawej aby wstawić do kursora.</div>
        </div>
        <div class="evo-field" style="margin:0;">
            <label>Podgląd (z fikcyjnymi danymi)</label>
            <div id="evk-template-preview"
                 style="width:100%;min-height:220px;border:1px solid #e2e8f0;border-radius:6px;padding:12px 14px;font-size:12px;line-height:1.7;background:#f8fafc;font-family:monospace;white-space:pre-wrap;word-break:break-word;color:#374151;box-sizing:border-box;"></div>
            <div class="evo-desc">Rzeczywiste dane zobaczysz po otwarciu wiadomości w skrzynce.</div>
        </div>
    </div>

    <div id="evk-vars-palette" style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:20px;"></div>

    <div class="evo-save-bar">
        <?php submit_button('Zapisz ustawienia', 'primary', 'submit', false); ?>
    </div>
</form>

<style>
.evk-field-row:hover { background: #f8fafc; }
.evk-var-chip {
    display: inline-block; padding: 3px 8px; background: #eff6ff; border: 1px solid #bfdbfe;
    border-radius: 4px; font-size: 11px; font-family: monospace; color: #1d4ed8;
    cursor: pointer; transition: background .12s;
}
.evk-var-chip:hover { background: #dbeafe; }
</style>

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
                return '<span class="evk-var-chip" data-var="{{' + k + '}}" title="' + lbl + '">{{' + k + '}}</span>';
              }).join('')
            : '<span style="font-size:12px;color:#94a3b8;">Dodaj mapowania pól aby zobaczyć dostępne zmienne.</span>';
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
        var row = $('<tr class="evk-field-row" style="border-bottom:1px solid #f1f5f9;">' +
            '<td style="padding:6px 10px;">' +
                '<input type="text" name="evk_forminbox[field_labels_keys][]" value="' + esc(key) + '" placeholder="klucz" style="width:100%;border:1px solid #d1d5db;border-radius:5px;padding:5px 8px;font-size:12px;font-family:monospace;">' +
            '</td>' +
            '<td style="padding:6px 10px;">' +
                '<input type="text" name="evk_forminbox[field_labels_vals][]" value="' + esc(label) + '" placeholder="Twoja nazwa" style="width:100%;border:1px solid #d1d5db;border-radius:5px;padding:5px 8px;font-size:13px;">' +
            '</td>' +
            '<td style="padding:6px 10px;text-align:center;">' +
                '<input type="checkbox" name="evk_forminbox[hidden_fields][]" value="" class="evk-hidden-cb" ' + hidCk + '>' +
            '</td>' +
            '<td style="padding:6px 4px;text-align:center;">' +
                '<button type="button" class="evk-remove-row button-link" style="color:#ef4444;padding:2px 4px;" title="Usuń wiersz">' +
                    '<span class="dashicons dashicons-no-alt" style="font-size:16px;width:16px;height:16px;line-height:1;"></span>' +
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
            $('#evk-fields-tbody').append('<tr class="evk-field-row-empty" id="evk-no-rows"><td colspan="4" style="padding:16px 10px;color:#94a3b8;font-style:italic;text-align:center;">Brak mapowań.</td></tr>');
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
        var row = $('<tr class="evk-form-row" style="border-bottom:1px solid #f1f5f9;">' +
            '<td style="padding:6px 10px;"><input type="text" name="evk_forminbox[form_names_keys][]" value="' + esc(id||'') + '" placeholder="ID formularza" style="width:100%;border:1px solid #d1d5db;border-radius:5px;padding:5px 8px;font-size:12px;font-family:monospace;"></td>' +
            '<td style="padding:6px 10px;"><input type="text" name="evk_forminbox[form_names_vals][]" value="' + esc(name||'') + '" placeholder="Czytelna nazwa" style="width:100%;border:1px solid #d1d5db;border-radius:5px;padding:5px 8px;font-size:13px;"></td>' +
            '<td style="padding:6px 4px;text-align:center;"><button type="button" class="evk-remove-form-row button-link" style="color:#ef4444;padding:2px 4px;" title="Usuń"><span class="dashicons dashicons-no-alt" style="font-size:16px;width:16px;height:16px;line-height:1;"></span></button></td>' +
        '</tr>');
        $('#evk-forms-tbody').append(row);
    }

    $('#evk-add-form-row').on('click', function() { addFormRow('', ''); });

    $(document).on('click', '.evk-remove-form-row', function() {
        $(this).closest('tr').remove();
        if (!$('#evk-forms-tbody .evk-form-row').length) {
            $('#evk-forms-tbody').append('<tr id="evk-no-form-rows"><td colspan="3" style="padding:14px 10px;color:#94a3b8;font-style:italic;text-align:center;">Brak mapowań formularzy.</td></tr>');
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
        return $('<tr class="evk-layout-row" data-group="' + group + '" style="border-bottom:1px solid #f1f5f9;">' +
            '<td style="padding:5px 6px;"><div style="display:flex;gap:4px;align-items:center;">' +
                '<input type="text" class="evk-layout-tpl" name="evk_forminbox[' + group + '_layout_keys][]" value="' + esc(tpl || '') + '" placeholder="{{nazwisko}} {{imie}}" style="flex:1;min-width:0;border:1px solid #d1d5db;border-radius:5px;padding:4px 6px;font-size:12px;font-family:monospace;">' +
                '<select class="evk-key-insert" title="Wstaw pole" style="width:38px;flex-shrink:0;border:1px solid #d1d5db;border-radius:5px;padding:4px 2px;font-size:12px;"></select>' +
            '</div></td>' +
            '<td style="padding:5px 6px;width:150px;"><select name="evk_forminbox[' + group + '_layout_types][]" style="width:100%;border:1px solid #d1d5db;border-radius:5px;padding:4px 6px;font-size:12px;">' + topts + '</select></td>' +
            '<td style="padding:5px 4px;width:78px;white-space:nowrap;text-align:right;">' +
                '<button type="button" class="evk-row-up button-link" title="W górę" style="padding:2px;"><span class="dashicons dashicons-arrow-up-alt2" style="font-size:14px;width:14px;height:14px;"></span></button>' +
                '<button type="button" class="evk-row-down button-link" title="W dół" style="padding:2px;"><span class="dashicons dashicons-arrow-down-alt2" style="font-size:14px;width:14px;height:14px;"></span></button>' +
                '<button type="button" class="evk-layout-remove button-link" title="Usuń" style="padding:2px;color:#ef4444;"><span class="dashicons dashicons-no-alt" style="font-size:16px;width:16px;height:16px;"></span></button>' +
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
            tbody.append('<tr class="evk-layout-empty" data-group="' + group + '"><td colspan="3" style="padding:12px 8px;color:#94a3b8;font-style:italic;">Brak pól — autodetekcja.</td></tr>');
        }
    });

    // Wypełnij selecty kluczy na starcie (mapowanie już w DOM)
    refreshKeySelects();

})(jQuery);
</script>
