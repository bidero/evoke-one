<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: Optymalizacja czcionek (anti-FOUT)
 */
$f        = EVK_Fonts::get_instance()->get_settings();
$detected = EVK_Fonts::detect_local_fonts();
?>
            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_fonts'); ?>

                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($f['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons dashicons-editor-textcolor"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Optymalizacja czcionek: <?php echo !empty($f['enabled']) ? 'WŁĄCZONA' : 'WYŁĄCZONA'; ?></h3>
                        <p>Preload lokalnych plików czcionek — ogranicza miganie tekstu (FOUT) bez wpływu na layout i przejścia.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($f['enabled']) ? 'Włączona' : 'Wyłączona'; ?></span>
                        <label class="evo-toggle">
                            <input type="checkbox" data-option="evk_fonts" data-field="enabled" value="1" <?php checked(!empty($f['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">
                    FOUT (miganie) bierze się z tego, że plik czcionki zaczyna się pobierać dopiero po
                    sparsowaniu CSS. <strong>Preload</strong> każe przeglądarce pobrać go od razu przy
                    wczytywaniu strony — czcionka jest zwykle gotowa przed pierwszym malowaniem i podmiana
                    nie jest widoczna. To rozwiązanie <strong>czysto addytywne</strong>: nie ukrywa tekstu,
                    nie zmienia layoutu ani animacji. Wskazuj tylko czcionki widoczne „nad zgięciem"
                    (nagłówki, tekst główne) — preload wszystkich plików spowalnia start.
                </div></details>

                <div class="evo-box">
                    <h3>Pliki czcionek do preload</h3>
                    <div class="evo-field">
                        <label>URL-e plików .woff2 / .woff (jeden na linię)<span class="evo-tip" tabindex="0" role="note" data-tip="Ścieżka względna (od „/&quot;) lub pełny URL. Obsługiwane: woff2, woff, ttf, otf — najlepiej woff2. Dokleimy crossorigin i właściwy typ MIME automatycznie." aria-label="Ścieżka względna (od „/&quot;) lub pełny URL. Obsługiwane: woff2, woff, ttf, otf — najlepiej woff2. Dokleimy crossorigin i właściwy typ MIME automatycznie.">?</span></label>
                        <textarea name="evk_fonts[preload]" rows="5" class="evo-mono evo-tbl-sm evo-w" style="--evo-w:640px" placeholder="/wp-content/uploads/fonts/inter-regular.woff2&#10;/wp-content/uploads/fonts/inter-600.woff2"><?php echo esc_textarea($f['preload']); ?></textarea>
                        
                    </div>

                    <?php if (!empty($detected)): ?>
                    <div class="evo-info-box">
                        <span class="dashicons dashicons-search"></span>
                        <div class="evo-grow">
                            <strong>Wykryte lokalne czcionki (.woff2)</strong> — kliknij, aby dopisać do pola powyżej:
                            <div class="evo-stack evo-mt-xs" style="--evo-gap:4px">
                            <?php foreach ($detected as $url):
                                $rel = str_replace(home_url(), '', $url); ?>
                                <a href="#" class="evk-font-suggest evo-mono evo-tbl-sm evo-link-plain" data-url="<?php echo esc_attr($rel); ?>"><span class="dashicons dashicons-plus-alt2 evo-ico-xs"></span> <?php echo esc_html($rel); ?></a>
                            <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <script>
                    (function(){
                        var ta = document.querySelector('textarea[name="evk_fonts[preload]"]');
                        document.querySelectorAll('.evk-font-suggest').forEach(function(a){
                            a.addEventListener('click', function(e){
                                e.preventDefault();
                                var url = a.getAttribute('data-url');
                                var cur = ta.value.split(/\r?\n/).map(function(s){return s.trim();}).filter(Boolean);
                                if (cur.indexOf(url) === -1) { cur.push(url); ta.value = cur.join('\n'); }
                                a.style.opacity = '0.45';
                            });
                        });
                    })();
                    </script>
                    <?php else: ?>
                    <div class="evo-desc evo-mb">Nie znaleziono plików czcionek w typowych folderach (uploads/fonts, omgf, …). URL czcionki znajdziesz w DevTools przeglądarki: zakładka <em>Sieć/Network</em> → filtr <em>Font</em> → skopiuj adres pliku .woff2.</div>
                    <?php endif; ?>

                </div>

                <div class="evo-box">
                    <h3>Preconnect (opcjonalnie)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Tylko jeśli czcionki są serwowane z <strong>zewnętrznego</strong> hosta/CDN (nie z Twojej domeny). Dla w pełni lokalnych czcionek zostaw puste.</div></details>
                    <div class="evo-field">
                        <label>Hosty do preconnect (jeden na linię)</label>
                        <textarea name="evk_fonts[preconnect]" rows="2" class="evo-mono evo-tbl-sm evo-w" style="--evo-w:480px" placeholder="https://fonts.gstatic.com"><?php echo esc_textarea($f['preconnect']); ?></textarea>
                    </div>

                
                </div>

<div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia czcionek', 'primary', 'submit', false); ?>
                </div>
            </form>
