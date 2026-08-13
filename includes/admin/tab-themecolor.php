<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: Paski przeglądarki (theme-color)
 */
$tc = EVK_Theme_Color::get_instance()->get_settings();
?>
            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_themecolor'); ?>

                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($tc['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons dashicons-smartphone"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Kolor pasków przeglądarki: <?php echo !empty($tc['enabled']) ? 'USTALONY' : 'ZOSTAWIONY PRZEGLĄDARCE'; ?></h3>
                        <p>Ustala kolor górnego i dolnego paska Safari na telefonie, żeby nie zmieniał się przy otwarciu menu pełnoekranowego ani na sekcjach z innym tłem.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($tc['enabled']) ? 'Włączone' : 'Wyłączone'; ?></span>
                        <label class="evo-toggle">
                            <input type="checkbox" data-option="evk_theme_color" data-field="enabled" value="1" <?php checked(!empty($tc['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <details class="evo-note"><summary>Dlaczego paski zmieniają kolor po otwarciu menu</summary><div class="evo-note-body">
                    Safari koloruje swoje paski pod stronę. Gdy strona <strong>nie mówi mu</strong>, jakiego
                    koloru mają być, przeglądarka bierze go z tego, co widzi — więc cokolwiek zamaluje kadr,
                    przemalowuje przy okazji paski. Najbardziej widać to przy <strong>Circular Menu</strong>
                    i <strong>Offcanvas Menu</strong>, bo kładą na cały kadr nieprzezroczysty panel.
                    To nie jest usterka menu, tylko domyślne zachowanie przeglądarki wobec strony, która
                    o kolorze nic nie powiedziała.<br><br>
                    Podany tutaj kolor obowiązuje <strong>niezależnie od tego, co jest namalowane</strong>,
                    więc rozwiązuje to samo także poza menu: na pełnoekranowej galerii, sekcji z ciemnym tłem
                    czy filmie na cały ekran. Menu nie wymaga żadnych zmian.<br><br>
                    <strong>Jeśli po włączeniu nic się nie zmienia:</strong> przeglądarka bierze
                    <em>pierwszy</em> taki znacznik w kodzie strony, więc kolor wpisany na sztywno
                    w szablon motywu wygra z tym ustawieniem. Sprawdź w kodzie strony, czy
                    <code>theme-color</code> nie występuje dwa razy.
                </div></details>

                <div class="evo-box">
                    <h3>Kolory</h3>
                    <div class="evo-grid evo-mb" style="--evo-col:200px">
                        <?php
                        $pola = [
                            'light' => 'Kolor pasków',
                            'dark'  => 'Kolor pasków — tryb ciemny',
                        ];
                        foreach ($pola as $key => $label): ?>
                        <div class="evo-field">
                            <label><?php echo esc_html($label); ?></label>
                            <div class="evo-color-row">
                                <input type="color" value="<?php echo esc_attr($tc[$key] ?: '#ffffff'); ?>"
                                    oninput="this.nextElementSibling.value=this.value">
                                <input type="text" name="evk_theme_color[<?php echo $key; ?>]"
                                    value="<?php echo esc_attr($tc[$key]); ?>"
                                    oninput="var v=this.value;if(/^#[0-9a-fA-F]{3,6}$/.test(v))this.previousElementSibling.value=v;"
                                    class="evo-mono evo-w-hex">
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="evo-desc">
                        Zwykle chcesz tu kolor tła strony — wtedy paski zlewają się z treścią i nic nie
                        „mruga” przy przewijaniu. <strong>Tryb ciemny zostawiony pusty</strong> znaczy
                        „ten sam kolor zawsze”; wypełnij go tylko, jeśli strona ma osobny wygląd ciemny.
                        Puste pole główne wyłącza emisję znacznika — zachowanie wraca wtedy do
                        domyślnego, czyli takiego jak przed włączeniem.
                    </div>
                </div>

<div class="evo-save-bar">
                    <?php submit_button('Zapisz kolory pasków', 'primary', 'submit', false); ?>
                </div>
            </form>
