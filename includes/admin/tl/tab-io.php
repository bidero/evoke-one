<?php
if (!defined('ABSPATH')) exit;
// Evoke ONE — TL tab content. Zmienne z tl_render_page(): $data $langs $codes $tab $base $nonce $ajax_url $stats
?>
<!-- EKSPORT -->
            <div class="tl-io-section">
                <h3>Eksport danych</h3>
                <p style="color:#475569;margin-bottom:12px;">Pobierz wszystkie tłumaczenia, ustawienia językow, obrazki, slugi URL i klucze DD jako plik JSON.</p>
                <button type="button" class="button button-primary" onclick="tlExportAll()">
                    <span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:4px;"></span>
                    Eksportuj wszystko
                </button>

                <div style="margin-top:16px;">
                    <p style="color:#475569;margin-bottom:8px;font-size:13px;">Lub eksportuj pojedynczą grupę tłumaczeń:</p>
                    <select id="tl-export-group" style="min-width:200px;margin-right:8px;">
                        <option value="">-- Wszystkie grupy --</option>
                        <?php foreach (($data['groups'] ?? []) as $group_id => $group): ?>
                        <option value="<?php echo esc_attr($group_id); ?>"><?php echo esc_html($group['name'] ?: $group_id); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="button" onclick="tlExportGroup(document.getElementById('tl-export-group').value)">Eksportuj grupę</button>
                </div>
            </div>

            <!-- IMPORT -->
            <div class="tl-io-section">
                <h3>Import danych</h3>
                <p style="color:#475569;margin-bottom:12px;">Wgraj plik JSON z eksportu. <strong>Uwaga:</strong> Istniejące dane zostaną nadpisane.</p>

                <div class="tl-drop-zone" id="tl-drop-zone" onclick="document.getElementById('tl-file-input').click();">
                    <span class="dashicons dashicons-upload" style="font-size:32px;display:block;margin-bottom:8px;"></span>
                    Przeciągnij plik JSON tutaj lub kliknij aby wybrać
                    <input type="file" id="tl-file-input" accept=".json">
                </div>

                <div class="tl-import-status" id="tl-import-status"></div>
            </div>

            <!-- INFORMACJE -->
            <div class="tl-io-section" style="background:#f8fafc;padding:16px;border-radius:6px;border:1px solid #d1d5db;">
                <h4 style="margin-top:0;">Zawartość eksportu:</h4>
                <ul style="margin:0;padding-left:20px;color:#475569;font-size:13px;">
                    <li><strong>tl_translations</strong> - wszystkie grupy i frazy tłumaczeń</li>
                    <li><strong>tl_languages</strong> - konfiguracja języków (kody, nazwy, flagi)</li>
                    <li><strong>tl_images</strong> - mapowanie obrazków między językami</li>
                    <li><strong>tl_url_slugs</strong> - tłumaczenia slugów URL</li>
                    <li><strong>tl_sitemap_settings</strong> - ustawienia mapy strony WordPress</li>
                    <li><strong>tl_dd_keys</strong> - klucze Dynamic Data</li>
                    <li><strong>tl_menu_location</strong> - lokalizacja menu w adminie</li>
                    <li><strong>tl_pl_flag</strong> - flaga dla języka polskiego</li>
                </ul>
            </div>
