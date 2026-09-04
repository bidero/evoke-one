<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: sierotki
 */
?>
<?php $sierotki = EVK_Sierotki::get_instance()->get_settings(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_sierotki'); ?>

                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($sierotki['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons dashicons-editor-paragraph"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Sierotki: <?php echo !empty($sierotki['enabled']) ? 'WŁĄCZONE' : 'WYŁĄCZONE'; ?></h3>
                        <p>Spójniki jednoliterowe („a", „i", „o", „u", „w", „z") nie zostają na końcu wiersza —
                           spacja po nich staje się nierozdzielająca.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($sierotki['enabled']) ? 'Włączone' : 'Wyłączone'; ?></span>
                        <label class="evo-toggle">
                            <input type="checkbox" data-option="evk_sierotki" data-field="enabled" value="1" <?php checked(!empty($sierotki['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Co jest poprawiane</h3>

                    <label class="evo-check">
                        <input type="checkbox" name="evk_sierotki[jednostki]" value="1" <?php checked(!empty($sierotki['jednostki'])); ?>>
                        <span>Wiązać także liczbę z jednostką</span>
                    </label>
                    <div class="evo-desc evo-mb-lg">
                        „5 km", „2024 r.", „10 %" — liczba i to, co po niej, nie rozjeżdżają się między wierszami.
                        Wiązany jest krótki wyraz (do trzech liter) oraz znaki %, ‰ i °.
                    </div>

                    <div class="evo-field">
                        <label>Pomijane klasy i identyfikatory</label>
                        <input type="text" name="evk_sierotki[wyjatki]"
                               value="<?php echo esc_attr($sierotki['wyjatki']); ?>"
                               placeholder=".kod, #stopka, cytat">
                        <div class="evo-desc">
                            Oddzielone przecinkami albo spacjami. Kropkę i krzyżyk można pominąć —
                            <code>.kod</code> i <code>kod</code> znaczą to samo.
                            <strong>To nie są pełne selektory CSS</strong>, tylko nazwy klas i identyfikatorów:
                            zamiana idzie po stronie serwera, na tekście, gdzie nie ma drzewa dokumentu,
                            więc dopasowanie potomka czy sąsiada nie miałoby się o co oprzeć.
                        </div>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Gdzie to działa</h3>
                    <p class="evo-desc evo-mb-0">
                        Głównym wejściem jest treść renderowana przez <strong>Bricksa</strong> — nagłówki, teksty,
                        przyciski i listy nie przechodzą przez zwykłe filtry WordPressa, więc sama treść wpisu
                        to za mało. Poza tym poprawiane są treść wpisu, wyciąg i tytuł na stronie.
                        <br><br>
                        <strong>Nie ruszamy</strong> wnętrza znaczników (adresów, opisów obrazków, nazw klas),
                        treści <code>&lt;pre&gt;</code>, <code>&lt;code&gt;</code>, <code>&lt;script&gt;</code>,
                        <code>&lt;style&gt;</code> i <code>&lt;textarea&gt;</code>, panelu administracyjnego,
                        kanałów RSS ani znacznika <code>&lt;title&gt;</code>.
                        <br><br>
                        Zapis w bazie zostaje nietknięty — zamiana dzieje się przy wyświetlaniu, więc
                        wyszukiwarka i edytor widzą tekst taki, jaki wpisałeś.
                    </p>
                </div>

                <div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia', 'primary', 'submit', false); ?>
                </div>
            </form>
