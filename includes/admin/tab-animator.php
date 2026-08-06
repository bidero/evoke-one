<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: Animator
 */

$anim       = EVK_Animator::get_instance();
$a          = $anim->get_settings();
$rows       = $a['animations'];
$presets    = evk_anim_presets();
$triggers   = evk_anim_triggers();
$easings    = evk_anim_easings();
$row_def    = $anim->row_defaults();
?>
<form method="post" action="options.php">
    <?php settings_fields('evoke_one_animator'); ?>

    <div class="evo-status-card">
        <div class="evo-status-icon <?php echo !empty($a['enabled']) ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-controls-play"></span>
        </div>
        <div class="evo-status-text">
            <h3>Animator: <?php echo !empty($a['enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
            <p>Animacje GSAP dla dowolnego elementu Bricks — przez klasę <code>evk-anim-{slug}</code>.</p>
        </div>
        <div class="evo-status-actions">
            <span class="evo-toggle-label"><?php echo !empty($a['enabled']) ? 'Włączony' : 'Wyłączony'; ?></span>
            <label class="evo-toggle">
                <input type="checkbox" data-option="evk_animator" data-field="enabled" value="1" <?php checked(!empty($a['enabled'])); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>

    <p class="evo-section-title">Zachowanie globalne</p>
    <div style="display:flex;flex-wrap:wrap;gap:20px;margin-bottom:24px;">
        <label style="display:flex;align-items:flex-start;gap:9px;font-size:13px;font-weight:500;color:#111827;cursor:pointer;flex-basis:280px;">
            <input type="checkbox" name="evk_animator[reduced_motion]" value="1" <?php checked(!empty($a['reduced_motion'])); ?> style="margin-top:2px;">
            <span>Szanuj „ogranicz ruch"<br><span style="font-weight:400;color:#6b7280;font-size:12px;">Przy <code>prefers-reduced-motion: reduce</code> element od razu dostaje stan końcowy, bez animacji.</span></span>
        </label>
        <label style="display:flex;align-items:flex-start;gap:9px;font-size:13px;font-weight:500;color:#111827;cursor:pointer;flex-basis:280px;">
            <input type="checkbox" name="evk_animator[builder_preview]" value="1" <?php checked(!empty($a['builder_preview'])); ?> style="margin-top:2px;">
            <span>Animuj w builderze<br><span style="font-weight:400;color:#6b7280;font-size:12px;">Domyślnie wyłączone — animacje w canvasie utrudniają edycję.</span></span>
        </label>
    </div>

    <style>
        .evo-anim-row { background:#f8fafc; border:1px solid #d7dde7; border-radius:8px; padding:20px; margin-bottom:16px; position:relative; }
        .evo-anim-row-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:16px; border-bottom:1px solid #e5e7eb; padding-bottom:12px; cursor:grab; }
        .evo-anim-row-header:active { cursor:grabbing; }
        .evo-anim-grip { color:#9ca3af; margin-right:6px; vertical-align:-3px; }
        /* Miejsce po przeciąganym wierszu — bez tego lista skacze pod kursorem. */
        .evo-anim-row-placeholder { border:2px dashed #c7d2e0; border-radius:8px; background:#eef2f7; margin-bottom:16px; }
        #evo-anim-order-note { display:none; font-size:12px; color:#4b5563; margin:0 0 12px; }
        .evo-anim-row-title { font-size:14px; font-weight:600; color:#111827; }
        .evo-anim-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:16px; }
        .evo-anim-grid label { display:block; font-size:12px; font-weight:600; color:#4b5563; margin-bottom:4px; }
        .evo-anim-grid input[type=text], .evo-anim-grid input[type=number], .evo-anim-grid select { width:100%; border:1px solid #d1d5db; border-radius:6px; font-size:13px; }
        .evo-anim-grid .checkbox-label { display:flex; align-items:center; gap:8px; font-size:13px; font-weight:500; color:#111827; margin-top:20px; cursor:pointer; }
        .evo-anim-grid .checkbox-label input { margin:0; }
        .evo-anim-class { font-family:monospace; font-size:12px; color:#334155; background:#e2e8f0; border-radius:4px; padding:2px 8px; }
        .evo-anim-fromto { grid-column:1 / -1; display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        .evo-anim-fromto textarea { width:100%; min-height:74px; font-family:monospace; font-size:12px; border:1px solid #d1d5db; border-radius:6px; padding:8px; resize:vertical; }
        .evo-anim-hint { grid-column:1 / -1; font-size:12px; color:#6b7280; margin-top:-4px; }
    </style>

    <hr class="evo-divider">
    <p class="evo-section-title">Biblioteka animacji</p>
    <div class="evo-info-box" style="margin-bottom:16px;">
        <span class="dashicons dashicons-info"></span>
        <div>
            Każda animacja dostaje klasę <code>evk-anim-{slug}</code> — wpisz ją elementowi w Bricks
            (Style → CSS → Classes). <strong>Slug jest kluczem</strong>: zmiana sluga zrywa powiązanie
            z elementami, które go już używają. Nazwa służy tylko Tobie i można ją zmieniać dowolnie.
            <br><br>Wiersze możesz <strong>przeciągać za nagłówek</strong> — nowa kolejność zapisuje się
            od razu. Jest ona wyłącznie porządkowa: o sekwencji na stronie decyduje pole
            <strong>Kolejność</strong>, a nie pozycja wiersza na tej liście.
        </div>
    </div>

    <p id="evo-anim-order-note"></p>

    <div id="evo-anim-repeater-container">
        <?php foreach ($rows as $index => $raw):
            $r = $anim->row_with_defaults((array) $raw); ?>
        <div class="evo-anim-row">
            <div class="evo-anim-row-header">
                <div class="evo-anim-row-title">
                    <span class="dashicons dashicons-menu evo-anim-grip" title="Przeciągnij, aby zmienić kolejność"></span>
                    <?php echo $r['label'] !== '' ? esc_html($r['label']) : 'Animacja #' . ($index + 1); ?>
                    <?php if ($r['slug'] !== ''): ?>
                        <span class="evo-anim-class">.evk-anim-<?php echo esc_html($r['slug']); ?></span>
                    <?php endif; ?>
                </div>
                <button type="button" class="evo-btn-remove" onclick="this.closest('.evo-anim-row').remove()">
                    <span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;"></span> Usuń
                </button>
            </div>
            <div class="evo-anim-grid">
                <div><label>Nazwa</label><input type="text" name="evk_animator[animations][<?php echo $index; ?>][label]" value="<?php echo esc_attr($r['label']); ?>" placeholder="np. Nagłówki sekcji"></div>
                <div><label>Slug (klasa)</label><input type="text" name="evk_animator[animations][<?php echo $index; ?>][slug]" value="<?php echo esc_attr($r['slug']); ?>" placeholder="fade-up"></div>
                <div>
                    <label>Preset</label>
                    <select name="evk_animator[animations][<?php echo $index; ?>][preset]">
                        <?php foreach ($presets as $key => $p): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($r['preset'], $key); ?>><?php echo esc_html($p['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Wyzwalacz</label>
                    <select name="evk_animator[animations][<?php echo $index; ?>][trigger]">
                        <?php foreach ($triggers as $key => $label): ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($r['trigger'], $key); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Easing</label>
                    <select name="evk_animator[animations][<?php echo $index; ?>][easing]">
                        <option value="" <?php selected($r['easing'], ''); ?>>— z presetu —</option>
                        <?php foreach ($easings as $e): ?>
                        <option value="<?php echo esc_attr($e); ?>" <?php selected($r['easing'], $e); ?>><?php echo esc_html($e); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div><label>Czas (s)</label><input type="number" step="0.05" min="0.05" max="10" name="evk_animator[animations][<?php echo $index; ?>][duration]" value="<?php echo esc_attr($r['duration']); ?>" placeholder="z presetu"></div>
                <div><label>Opóźnienie (s)</label><input type="number" step="0.05" min="0" max="10" name="evk_animator[animations][<?php echo $index; ?>][delay]" value="<?php echo esc_attr($r['delay']); ?>"></div>
                <div><label>Stagger (s)</label><input type="number" step="0.005" min="0" max="2" name="evk_animator[animations][<?php echo $index; ?>][stagger]" value="<?php echo esc_attr($r['stagger']); ?>" placeholder="z presetu"></div>
                <div><label>Start (ScrollTrigger)</label><input type="text" name="evk_animator[animations][<?php echo $index; ?>][start]" value="<?php echo esc_attr($r['start']); ?>" placeholder="top 85%"></div>
                <div><label>End (tylko scrub)</label><input type="text" name="evk_animator[animations][<?php echo $index; ?>][end]" value="<?php echo esc_attr($r['end']); ?>" placeholder="bottom 40%"></div>
                <div><label>Scrub (tylko scrub)</label><input type="number" step="0.1" min="0" max="5" name="evk_animator[animations][<?php echo $index; ?>][scrub]" value="<?php echo esc_attr($r['scrub']); ?>"></div>
                <div><label title="Krok sekwencji startowej: ten sam numer = razem, wyższy = dopiero po zakończeniu poprzedniego kroku. Opóźnienie liczy się od początku swojego kroku.">Kolejność (krok, tylko load)</label><input type="number" step="1" min="0" max="999" name="evk_animator[animations][<?php echo $index; ?>][order]" value="<?php echo esc_attr($r['order']); ?>"></div>
                <div><label class="checkbox-label"><input type="checkbox" name="evk_animator[animations][<?php echo $index; ?>][repeat]" value="1" <?php checked(!empty($r['repeat'])); ?>> Powtarzaj przy każdym wejściu</label></div>
                <div>
                    <label>Cel animacji</label>
                    <select name="evk_animator[animations][<?php echo $index; ?>][targets]">
                        <option value="self"<?php selected($r['targets'],'self'); ?>>Sam element</option>
                        <option value="children"<?php selected($r['targets'],'children'); ?>>Dzieci elementu</option>
                        <option value="selector"<?php selected($r['targets'],'selector'); ?>>Selektor w środku</option>
                    </select>
                </div>
                <div><label>Selektor (gdy wybrany)</label><input type="text" name="evk_animator[animations][<?php echo $index; ?>][selector]" value="<?php echo esc_attr($r['selector']); ?>" placeholder=".karta"></div>
                <div><label class="checkbox-label"><input type="checkbox" name="evk_animator[animations][<?php echo $index; ?>][pin]" value="1" <?php checked(!empty($r['pin'])); ?>> Pin (tylko scrub)</label></div>
                <div class="evo-anim-hint">Własne <strong>from/to</strong> — po jednej właściwości na linię, np. <code>opacity: 0</code>, <code>y: 40</code>, <code>filter: blur(12px)</code>. Wypełnione pole <strong>zastępuje w całości</strong> odpowiednik z presetu (nie scala się z nim). Puste = wartości z presetu.</div>
                <div class="evo-anim-fromto">
                    <div><label>from (stan początkowy)</label><textarea name="evk_animator[animations][<?php echo $index; ?>][from]" placeholder="opacity: 0&#10;y: 40"><?php echo esc_textarea($r['from']); ?></textarea></div>
                    <div><label>to (stan końcowy)</label><textarea name="evk_animator[animations][<?php echo $index; ?>][to]" placeholder="opacity: 1&#10;y: 0"><?php echo esc_textarea($r['to']); ?></textarea></div>
                </div>
                <div class="evo-anim-hint">Pole <strong>Słowa</strong> działa wyłącznie z presetem <em>Tekst: zmieniające się słowa</em> — po jednym słowie na linię, maksymalnie 20. Pole <strong>Czas</strong> steruje wtedy samym przejściem; każde słowo stoi 1,4 s.</div>
                <div class="evo-anim-fromto">
                    <div><label>Słowa (tylko preset „zmieniające się słowa")</label><textarea name="evk_animator[animations][<?php echo $index; ?>][words]" placeholder="szybciej&#10;prościej&#10;taniej"><?php echo esc_textarea($r['words']); ?></textarea></div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <button type="button" class="button" onclick="evkAddAnimRow()">+ Dodaj animację</button>

    <div class="evo-save-bar">
        <?php submit_button('Zapisz bibliotekę animacji', 'primary', 'submit', false); ?>
    </div>
</form>

<script type="text/template" id="evo-anim-row-template">
    <div class="evo-anim-row">
        <div class="evo-anim-row-header">
            <div class="evo-anim-row-title">
                <span class="dashicons dashicons-menu evo-anim-grip" title="Przeciągnij, aby zmienić kolejność"></span>
                Nowa animacja
            </div>
            <button type="button" class="evo-btn-remove" onclick="this.closest('.evo-anim-row').remove()">
                <span class="dashicons dashicons-trash" style="font-size:16px;width:16px;height:16px;"></span> Usuń
            </button>
        </div>
        <div class="evo-anim-grid">
            <div><label>Nazwa</label><input type="text" name="evk_animator[animations][{INDEX}][label]" value="" placeholder="np. Nagłówki sekcji"></div>
            <div><label>Slug (klasa)</label><input type="text" name="evk_animator[animations][{INDEX}][slug]" value="" placeholder="fade-up"></div>
            <div>
                <label>Preset</label>
                <select name="evk_animator[animations][{INDEX}][preset]">
                    <?php foreach ($presets as $key => $p): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($row_def['preset'], $key); ?>><?php echo esc_html($p['label']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Wyzwalacz</label>
                <select name="evk_animator[animations][{INDEX}][trigger]">
                    <?php foreach ($triggers as $key => $label): ?>
                    <option value="<?php echo esc_attr($key); ?>" <?php selected($row_def['trigger'], $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Easing</label>
                <select name="evk_animator[animations][{INDEX}][easing]">
                    <option value="" <?php selected($row_def['easing'], ''); ?>>— z presetu —</option>
                    <?php foreach ($easings as $e): ?>
                    <option value="<?php echo esc_attr($e); ?>" <?php selected($row_def['easing'], $e); ?>><?php echo esc_html($e); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div><label>Czas (s)</label><input type="number" step="0.05" min="0.05" max="10" name="evk_animator[animations][{INDEX}][duration]" value="" placeholder="z presetu"></div>
            <div><label>Opóźnienie (s)</label><input type="number" step="0.05" min="0" max="10" name="evk_animator[animations][{INDEX}][delay]" value="<?php echo esc_attr($row_def['delay']); ?>"></div>
            <div><label>Stagger (s)</label><input type="number" step="0.005" min="0" max="2" name="evk_animator[animations][{INDEX}][stagger]" value="" placeholder="z presetu"></div>
            <div><label>Start (ScrollTrigger)</label><input type="text" name="evk_animator[animations][{INDEX}][start]" value="<?php echo esc_attr($row_def['start']); ?>" placeholder="top 85%"></div>
            <div><label>End (tylko scrub)</label><input type="text" name="evk_animator[animations][{INDEX}][end]" value="<?php echo esc_attr($row_def['end']); ?>" placeholder="bottom 40%"></div>
            <div><label>Scrub (tylko scrub)</label><input type="number" step="0.1" min="0" max="5" name="evk_animator[animations][{INDEX}][scrub]" value="<?php echo esc_attr($row_def['scrub']); ?>"></div>
            <div><label title="Krok sekwencji startowej: ten sam numer = razem, wyższy = dopiero po zakończeniu poprzedniego kroku. Opóźnienie liczy się od początku swojego kroku.">Kolejność (krok, tylko load)</label><input type="number" step="1" min="0" max="999" name="evk_animator[animations][{INDEX}][order]" value="<?php echo esc_attr($row_def['order']); ?>"></div>
            <div><label class="checkbox-label"><input type="checkbox" name="evk_animator[animations][{INDEX}][repeat]" value="1"> Powtarzaj przy każdym wejściu</label></div>
            <div>
                <label>Cel animacji</label>
                <select name="evk_animator[animations][{INDEX}][targets]">
                    <option value="self">Sam element</option>
                    <option value="children">Dzieci elementu</option>
                    <option value="selector">Selektor w środku</option>
                </select>
            </div>
            <div><label>Selektor (gdy wybrany)</label><input type="text" name="evk_animator[animations][{INDEX}][selector]" value="" placeholder=".karta"></div>
            <div><label class="checkbox-label"><input type="checkbox" name="evk_animator[animations][{INDEX}][pin]" value="1"> Pin (tylko scrub)</label></div>
            <div class="evo-anim-hint">Własne <strong>from/to</strong> — po jednej właściwości na linię, np. <code>opacity: 0</code>, <code>y: 40</code>, <code>filter: blur(12px)</code>. Wypełnione pole <strong>zastępuje w całości</strong> odpowiednik z presetu (nie scala się z nim). Puste = wartości z presetu.</div>
            <div class="evo-anim-fromto">
                <div><label>from (stan początkowy)</label><textarea name="evk_animator[animations][{INDEX}][from]" placeholder="opacity: 0&#10;y: 40"></textarea></div>
                <div><label>to (stan końcowy)</label><textarea name="evk_animator[animations][{INDEX}][to]" placeholder="opacity: 1&#10;y: 0"></textarea></div>
            </div>
            <div class="evo-anim-hint">Pole <strong>Słowa</strong> działa wyłącznie z presetem <em>Tekst: zmieniające się słowa</em> — po jednym słowie na linię, maksymalnie 20. Pole <strong>Czas</strong> steruje wtedy samym przejściem; każde słowo stoi 1,4 s.</div>
            <div class="evo-anim-fromto">
                <div><label>Słowa (tylko preset „zmieniające się słowa")</label><textarea name="evk_animator[animations][{INDEX}][words]" placeholder="szybciej&#10;prościej&#10;taniej"></textarea></div>
            </div>
        </div>
    </div>
</script>
