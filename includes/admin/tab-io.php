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
                <div class="evo-inline evo-mb-xs" style="--evo-gap:6px">
                    <span class="evo-eyebrow">Moduły</span>
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
                <label class="evo-check evo-mb">
                    <input type="checkbox" id="evo-export-hasla" value="1">
                    <div>
                        <span class="evo-strong-500">Dołącz hasła</span>
                        <div class="evo-desc evo-m0">Hasło SMTP i hasło obejścia konserwacji. Bez zaznaczenia plik ich nie ma, a import takiego pliku zostawia hasła, które są na stronie.</div>
                    </div>
                </label>
                <label class="evo-check evo-mb">
                    <input type="checkbox" id="evo-export-subskrybenci" value="1">
                    <div>
                        <span class="evo-strong-500">Dołącz subskrybentów newslettera</span>
                        <div class="evo-desc evo-m0">Adresy, zapisy zgód (z adresami IP), kampanie i ich statystyki. Bez zaznaczenia plik niesie ustawienia, listy i szablony newslettera, a import takiego pliku dopisuje listy i szablony, nie ruszając subskrybentów na stronie.</div>
                    </div>
                </label>
                <button type="button" class="button button-primary" onclick="evoExportSelected()">
                    <span class="dashicons dashicons-download"></span> Eksportuj zaznaczone
                </button>
            </div>

            <!-- IMPORT -->
            <div class="evo-io-box">
                <h3>Import danych</h3>
                <p>Wgraj plik JSON z eksportu. Dla każdego modułu możesz zdecydować czy nadpisać istniejące dane.</p>
                <div class="evo-drop-zone" id="evo-drop-zone" onclick="document.getElementById('evo-file-input').click();">
                    <span class="dashicons dashicons-upload evo-ico-xl evo-drop-ico"></span>
                    <span class="evo-block evo-center">Przeciągnij plik JSON tutaj lub kliknij, aby wybrać</span>
                    <input type="file" id="evo-file-input" accept=".json" aria-label="Plik JSON z eksportu do importu">
                </div>
                <div class="evo-import-status" id="evo-import-status"></div>
            </div>

            <!-- DANE PO ODINSTALOWANIU (1.232.0) — czyta je uninstall.php -->
            <div class="evo-io-box">
                <h3>Dane po odinstalowaniu</h3>
                <form method="post" action="options.php">
                    <?php settings_fields('evk_usun_dane_grupa'); ?>
                    <label class="evo-choice evo-choice-stack">
                        <input type="checkbox" name="evk_usun_dane" value="1" <?php checked(1, (int) get_option('evk_usun_dane', 0)); ?>>
                        <span>
                            <strong>Usuń wszystkie dane przy odinstalowaniu</strong>
                            <span class="evo-hint">Dotyczy usunięcia wtyczki z listy wtyczek — samo wyłączenie niczego nie kasuje. Znikną: ustawienia i logi, newsletter (listy, subskrybenci, kampanie), snippety i przekierowania, kopie zapasowe z serwera (te na Dysku Google zostają) oraz role utworzone w Role Managerze — ich użytkownicy dostaną rolę domyślną strony. Dane innych wtyczek, np. Evoke Fields, zostają.</span>
                        </span>
                    </label>
                    <?php evoke_one_pasek_zapisu('Zapisz'); ?>
                </form>
            </div>

            <!-- MODAL KONFLIKTU -->
            <div class="evo-modal-bg" id="evo-conflict-modal">
                <div class="evo-modal">
                    <h3>Rozwiąż konflikty importu</h3>
                    <p>Poniższe moduły już zawierają dane. Zdecyduj dla każdego z nich czy chcesz <strong>nadpisać</strong> istniejące dane danymi z pliku, czy <strong>pominąć</strong>.</p>
                    <div class="evo-modal-modules" id="evo-conflict-list"></div>
                    <div class="evo-row-between">
                        <div class="evo-inline" style="--evo-gap:10px">
                            <button type="button" class="evo-link-btn is-danger" onclick="evoConflictAll('overwrite')">Nadpisz wszystkie</button>
                            <button type="button" class="evo-link-btn" onclick="evoConflictAll('skip')">Pomiń wszystkie</button>
                        </div>
                        <div class="evo-modal-footer">
                            <button type="button" class="evo-modal-btn-done" onclick="evoConflictConfirm()">Importuj</button>
                        </div>
                    </div>
                </div>
            </div>
