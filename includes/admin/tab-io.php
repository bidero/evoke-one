<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: io
 */
?>
<?php $io_modules = evoke_one_get_io_modules(); ?>
<!-- EKSPORT -->
            <div class="evo-io-box">
                <h3>Eksport danych</h3>
                <p>Wybierz moduły do eksportu i pobierz plik JSON.</p>
                <div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
                    <span style="font-size:12px;font-weight:600;color:#374151;text-transform:uppercase;letter-spacing:.5px;">Moduły</span>
                    <span class="evo-io-select-all" onclick="evoIoSelectAll('export')">zaznacz wszystkie</span>
                    <span class="evo-io-select-all" onclick="evoIoDeselectAll('export')">odznacz wszystkie</span>
                </div>
                <div class="evo-io-grid" id="evo-export-modules">
                    <?php foreach ($io_modules as $key => $label): ?>
                    <label class="evo-io-module" onclick="this.classList.toggle('selected')">
                        <input type="checkbox" class="evo-export-cb" value="<?php echo esc_attr($key); ?>" checked>
                        <?php echo esc_html($label); ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="button button-primary" onclick="evoExportSelected()">
                    <span class="dashicons dashicons-download"></span> Eksportuj zaznaczone
                </button>
            </div>

            <!-- IMPORT -->
            <div class="evo-io-box">
                <h3>Import danych</h3>
                <p>Wgraj plik JSON z eksportu. Dla każdego modułu możesz zdecydować czy nadpisać istniejące dane.</p>
                <div class="evo-drop-zone" id="evo-drop-zone" onclick="document.getElementById('evo-file-input').click();">
                    <span class="dashicons dashicons-upload" style="font-size:40px;width:40px;height:40px;display:block;margin:0 auto 10px;"></span>
                    <span style="display:block;text-align:center;">Przeciągnij plik JSON tutaj lub kliknij, aby wybrać</span>
                    <input type="file" id="evo-file-input" accept=".json">
                </div>
                <div class="evo-import-status" id="evo-import-status"></div>
            </div>

            <!-- MODAL KONFLIKTU -->
            <div class="evo-modal-bg" id="evo-conflict-modal">
                <div class="evo-modal">
                    <h3>Rozwiąż konflikty importu</h3>
                    <p>Poniższe moduły już zawierają dane. Zdecyduj dla każdego z nich czy chcesz <strong>nadpisać</strong> istniejące dane danymi z pliku, czy <strong>pominąć</strong>.</p>
                    <div class="evo-modal-modules" id="evo-conflict-list"></div>
                    <div style="display:flex;align-items:center;justify-content:space-between;">
                        <div style="display:flex;gap:10px;">
                            <button type="button" onclick="evoConflictAll('overwrite')" style="font-size:12px;color:#dc2626;background:none;border:none;cursor:pointer;text-decoration:underline;">Nadpisz wszystkie</button>
                            <button type="button" onclick="evoConflictAll('skip')"      style="font-size:12px;color:#6b7280;background:none;border:none;cursor:pointer;text-decoration:underline;">Pomiń wszystkie</button>
                        </div>
                        <div class="evo-modal-footer">
                            <button type="button" class="evo-modal-btn-done" onclick="evoConflictConfirm()">Importuj</button>
                        </div>
                    </div>
                </div>
            </div>
