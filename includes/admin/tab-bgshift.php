<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: przenikanie tła przy scrollu
 */
?>
<?php $bg = EVK_Bg_Shift::get_instance()->get_settings(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_bgshift'); ?>

                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($bg['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons dashicons-art"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Tło przy scrollu: <?php echo !empty($bg['enabled']) ? 'WŁĄCZONE' : 'WYŁĄCZONE'; ?></h3>
                        <p>Kolor tła przewija się płynnie od sekcji do sekcji podczas przewijania strony.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($bg['enabled']) ? 'Włączone' : 'Wyłączone'; ?></span>
                        <label class="evo-toggle">
                            <input type="checkbox" data-option="evk_bgshift" data-field="enabled" value="1" <?php checked(!empty($bg['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Jak to działa</h3>
                    <div class="evo-field">
                        <div class="evo-desc" style="max-width:70ch;">
                            Kolor <strong>nie jest animowany na samych sekcjach</strong>. Gdyby każda sekcja
                            przewijała własne tło, przez całe przejście na granicy sąsiadowałyby dwa różne
                            kolory i widać byłoby szew. Zamiast tego pod całą stroną leży jedna warstwa:
                            sekcja z włączoną opcją oddaje jej swój kolor i sama robi się przezroczysta.<br><br>
                            Włącznik jest przy każdym elemencie w builderze, w grupie <strong>Atrybuty →
                            Evoke ONE → Tło przy scrollu</strong>. Kolor ustawiasz normalnie, tłem sekcji —
                            także kolorem globalnym. Nie ma osobnego pola na kolor, żeby nie powstało drugie
                            źródło prawdy, które rozjeżdża się przy każdej zmianie.<br><br>
                            <strong>Warunki:</strong> sekcja musi mieć własny kolor tła — z tłem graficznym
                            albo gradientowym zostanie pominięta (z ostrzeżeniem w konsoli). Żaden pojemnik
                            między <code>&lt;body&gt;</code> a sekcjami nie może mieć własnego, krytego tła,
                            bo zasłoniłby warstwę.
                        </div>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Przejście</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:24px;">
                        <div class="evo-field" style="margin-bottom:0;">
                            <label>Długość przejścia</label>
                            <input type="number" name="evk_bgshift[length]" value="<?php echo esc_attr($bg['length']); ?>" min="0.1" max="1" step="0.05">
                            <div class="evo-desc">Jaką część wysokości ekranu zajmuje zmiana koloru. 1.0 = pełny widok, 0.3 = szybka zmiana tuż przy granicy sekcji.</div>
                        </div>
                        <div class="evo-field" style="margin-bottom:0;">
                            <label>Wygładzanie (s)</label>
                            <input type="number" name="evk_bgshift[smooth]" value="<?php echo esc_attr($bg['smooth']); ?>" min="0" max="2" step="0.1">
                            <div class="evo-desc">Opóźnienie, z jakim kolor dogania scroll. 0 = przykleja się do przewijania klatka w klatkę.</div>
                        </div>
                    </div>

                    <p class="evo-desc" style="max-width:70ch;">
                        Przy włączonej systemowej redukcji ruchu kolor nie przewija się — przeskakuje
                        na granicy sekcji.
                    </p>

                </div>

                <div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia', 'primary', 'submit', false); ?>
                </div>
</form>
