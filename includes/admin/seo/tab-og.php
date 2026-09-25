<?php
if (!defined('ABSPATH')) exit;
?>
            <?php
            $og       = evk_og_get_settings();
            $og_layers = $og['layers'] ?? [];
            $all_post_types = get_post_types(['public' => true], 'objects');

            $blend_modes = ['normal','multiply','screen','overlay','darken','lighten',
                            'color-dodge','color-burn','hard-light','soft-light',
                            'difference','exclusion'];
            $layer_types = evk_og_layer_types();
            ?>


            <div class="evo-status-card">
                <div class="evo-status-icon <?php echo !empty($og['enabled']) ? 'on' : 'off'; ?>">
                    <span class="dashicons dashicons-share evo-ico-lg"></span>
                </div>
                <div class="evo-status-text">
                    <h3>Generator OG: <?php echo !empty($og['enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
                    <p><?php echo !empty($og['enabled']) ? 'Obrazy OG są generowane i dodawane do &lt;head&gt;.' : 'Generator wyłączony — brak meta og:image, brak generowania.'; ?></p>
                </div>
                <div class="evo-status-actions">
                    <span class="evo-toggle-label"><?php echo !empty($og['enabled']) ? 'Włączony' : 'Wyłączony'; ?></span>
                    <label class="evo-toggle">
                        <input aria-label="Generator OG" type="checkbox"
                               data-option="evk_og"
                               data-field="enabled"
                               value="1"
                               <?php checked(!empty($og['enabled'])); ?>>
                        <span class="evo-slider"></span>
                    </label>
                </div>
            </div>

            <form method="post" action="options.php" id="evk-og-form">
                <?php settings_fields('evoke_one_og'); ?>

                <!-- USTAWIENIA GLOBALNE -->
                <div class="evo-box">
                    <h3>Ustawienia globalne</h3>

                    <?php if (!extension_loaded('imagick')): ?>
                    <div class="evo-info-box is-err">
                        <span class="dashicons dashicons-warning"></span>
                        <div>Rozszerzenie <strong>Imagick</strong> nie jest zainstalowane na tym serwerze. Warstwy z plikami <strong>.svg</strong> będą pomijane podczas generowania. Pozostałe formaty (JPG, PNG, WebP, GIF) działają normalnie.</div>
                    </div>
                    <?php else: ?>
                    <div class="evo-info-box is-ok">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <div>Rozszerzenie <strong>Imagick</strong> jest dostępne — obsługa plików <strong>.svg</strong> aktywna.</div>
                    </div>
                    <?php endif; ?>
                    <div class="evo-grid evo-mb" style="--evo-col:180px">

                        <div class="evo-field">
                            <label for="evo-f-evk_og-width">Szerokość (px)</label>
                            <input id="evo-f-evk_og-width" type="number" name="evk_og[width]" value="<?php echo esc_attr($og['width']); ?>" min="400" max="2400">
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_og-height">Wysokość (px)</label>
                            <input id="evo-f-evk_og-height" type="number" name="evk_og[height]" value="<?php echo esc_attr($og['height']); ?>" min="200" max="1400">
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_og-format">Format</label>
                            <select id="evo-f-evk_og-format" name="evk_og[format]">
                                <?php foreach (['jpg' => 'JPG', 'png' => 'PNG', 'webp' => 'WebP'] as $val => $lbl): ?>
                                <option value="<?php echo $val; ?>" <?php selected($og['format'], $val); ?>><?php echo $lbl; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_og-quality">Jakość (JPG/WebP)</label>
                            <input id="evo-f-evk_og-quality" type="number" name="evk_og[quality]" value="<?php echo esc_attr($og['quality']); ?>" min="10" max="100">
                        </div>
                    </div>

                    <div class="evo-field">
                        <label>Font (plik .ttf/.otf)</label>
                        <?php
                        $font_id = 0;
                        if (!empty($og['font_url'])) {
                            $font_id = attachment_url_to_postid($og['font_url']);
                        }
                        ?>
                        <div class="evo-og-media-row" id="evk-og-font-preview">
                            <?php if ($font_id): ?>
                                <span class="evo-hint evo-hint-soft"><?php echo esc_html(basename($og['font_url'])); ?></span>
                            <?php else: ?>
                                <span class="evo-hint evo-faint">Nie wybrano</span>
                            <?php endif; ?>
                        </div>
                        <input type="hidden" name="evk_og[font_url]"  id="evk-og-font-url"  value="<?php echo esc_attr($og['font_url']); ?>">
                        <input type="hidden" name="evk_og[font_path]" id="evk-og-font-path" value="<?php echo esc_attr($og['font_path']); ?>">
                        <button type="button" class="button" style="margin-top:6px;" onclick="evkOgPickMedia('font', null, 'application')">Wybierz plik fontu</button>
                        <div class="evo-desc">Obsługiwane: .ttf, .otf — wgraj przez Bibliotekę mediów.</div>
                    </div>

                    <div class="evo-field">
                        <label for="evo-f-evk_og-fallback_url">URL fallback (gdy brak miniatury)</label>
                        <input id="evo-f-evk_og-fallback_url" type="text" name="evk_og[fallback_url]" value="<?php echo esc_attr($og['fallback_url']); ?>" placeholder="https://twoja-domena.pl/wp-content/uploads/og-fallback.jpg" class="evo-w-full">
                        <div class="evo-desc">Dla stron bez wygenerowanego obrazka i bez miniatury. Puste pole: bez obrazka (albo <code>og-fallback.jpg</code> z katalogu uploads, jeśli taki plik istnieje).</div>
                    </div>
                </div>

                <!-- POST TYPES -->
                <div class="evo-box">
                    <h3>Aktywne typy postów</h3>
                    <div class="evo-inline" style="--evo-gap:14px;flex-wrap:wrap">
                        <?php foreach ($all_post_types as $pt_slug => $pt_obj): ?>
                        <label class="checkbox-label">
                            <input type="checkbox" name="evk_og[post_types][]" value="<?php echo esc_attr($pt_slug); ?>"
                                <?php checked(in_array($pt_slug, (array)$og['post_types'], true)); ?>>
                            <?php echo esc_html($pt_obj->labels->singular_name); ?>
                            <span class="evo-hint-sm evo-faint">(<?php echo esc_html($pt_slug); ?>)</span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- WARSTWY -->
                <div class="evo-box">
                    <h3>Warstwy <span class="evo-box-note">(kolejność = kolejność renderowania — przeciągnij aby zmienić)</span></h3>

                    <div id="evk-og-layers-container">
                        <?php foreach ($og_layers as $li => $layer): ?>
                        <?php $type = $layer['type'] ?? 'rect'; ?>
                        <div class="evo-og-layer" data-index="<?php echo $li; ?>">
                            <div class="evo-og-layer-header">
                                <span class="drag-handle dashicons dashicons-menu"></span>
                                <label class="layer-toggle evo-toggle">
                                    <input type="checkbox" aria-label="<?php echo esc_attr('Włącz warstwę: ' . ($layer['label'] ?? $type)); ?>" name="evk_og[layers][<?php echo $li; ?>][enabled]" value="1" <?php checked(!empty($layer['enabled'])); ?>>
                                    <span class="evo-slider"></span>
                                </label>
                                <span class="evo-og-layer-title"><?php echo esc_html($layer['label'] ?? $type); ?></span>
                                <span class="evo-og-layer-type-badge"><?php echo esc_html($layer_types[$type] ?? $type); ?></span>
                                <button type="button" class="evo-og-btn-remove" title="Usuń warstwę" aria-label="<?php echo esc_attr('Usuń warstwę: ' . ($layer['label'] ?? $type)); ?>" onclick="this.closest('.evo-og-layer').remove()">
                                    <span class="dashicons dashicons-trash evo-ico-sm"></span>
                                </button>
                            </div>

                            <input type="hidden" name="evk_og[layers][<?php echo $li; ?>][id]"    value="<?php echo esc_attr($layer['id']   ?? uniqid('l')); ?>">
                            <input type="hidden" name="evk_og[layers][<?php echo $li; ?>][type]"  value="<?php echo esc_attr($type); ?>">

                            <div class="evo-og-layer-fields">
                                <!-- Wspólne: label -->
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-label">Etykieta</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-label" type="text" name="evk_og[layers][<?php echo $li; ?>][label]" value="<?php echo esc_attr($layer['label'] ?? ''); ?>">
                                </div>

                                <!-- Pozycja X/Y (nie dla text bo ma y_from_bottom, nie dla qr bo ma
                                     własne „Margin prawy" i „Y od góry" pod TYMI SAMYMI kluczami x i y.
                                     Do 1.236.0 warstwa QR miała obie pary, a zapis brał drugą:
                                     pierwsza para przepadała bez słowa) -->
                                <?php if (!in_array($type, ['text', 'qr'], true)): ?>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-x">X (px)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-x" type="number" name="evk_og[layers][<?php echo $li; ?>][x]" value="<?php echo esc_attr($layer['x'] ?? 0); ?>">
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-y">Y (px)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-y" type="number" name="evk_og[layers][<?php echo $li; ?>][y]" value="<?php echo esc_attr($layer['y'] ?? 0); ?>">
                                </div>
                                <?php endif; ?>

                                <!-- Rozmiar W/H (nie dla photo jeśli 0) -->
                                <?php if (!in_array($type, ['text', 'qr'], true)): ?>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-width">Szerokość (px, 0=auto)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-width" type="number" name="evk_og[layers][<?php echo $li; ?>][width]" value="<?php echo esc_attr($layer['width'] ?? 0); ?>">
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-height">Wysokość (px, 0=auto)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-height" type="number" name="evk_og[layers][<?php echo $li; ?>][height]" value="<?php echo esc_attr($layer['height'] ?? 0); ?>">
                                </div>
                                <?php endif; ?>

                                <!-- Opacity -->
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-opacity">Krycie (%)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-opacity" type="number" name="evk_og[layers][<?php echo $li; ?>][opacity]" value="<?php echo esc_attr($layer['opacity'] ?? 100); ?>" min="0" max="100">
                                </div>

                                <!-- Blend mode -->
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-blend">Blend Mode</label>
                                    <select id="evo-f-evk_og-layers-<?php echo $li; ?>-blend" name="evk_og[layers][<?php echo $li; ?>][blend]">
                                        <?php foreach ($blend_modes as $bm): ?>
                                        <option value="<?php echo $bm; ?>" <?php selected($layer['blend'] ?? 'normal', $bm); ?>><?php echo $bm; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <?php if ($type === 'rect'): ?>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-color">Kolor</label>
                                    <div class="evo-og-color-pair">
                                        <input type="color" aria-label="Kolor — próbnik" value="<?php echo esc_attr($layer['color'] ?? '#000000'); ?>"
                                            oninput="this.nextElementSibling.value=this.value">
                                        <input type="text" id="evo-f-evk_og-layers-<?php echo $li; ?>-color" name="evk_og[layers][<?php echo $li; ?>][color]"
                                            value="<?php echo esc_attr($layer['color'] ?? '#000000'); ?>"
                                            oninput="this.previousElementSibling.value=this.value"
                                            class="evo-mono evo-w-hex">
                                    </div>
                                </div>

                                <?php elseif ($type === 'photo'): ?>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-offset_x">Przesunięcie X zdjęcia (px)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-offset_x" type="number" name="evk_og[layers][<?php echo $li; ?>][offset_x]" value="<?php echo esc_attr($layer['offset_x'] ?? 0); ?>">
                                    <div class="evo-hint-sm evo-muted" style="margin-top:3px">Przesuwa kadrowanie w lewo/prawo.</div>
                                </div>

                                <?php elseif ($type === 'gradient'): ?>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-color">Kolor</label>
                                    <div class="evo-og-color-pair">
                                        <input type="color" aria-label="Kolor — próbnik" value="<?php echo esc_attr($layer['color'] ?? '#000000'); ?>"
                                            oninput="this.nextElementSibling.value=this.value">
                                        <input type="text" id="evo-f-evk_og-layers-<?php echo $li; ?>-color" name="evk_og[layers][<?php echo $li; ?>][color]"
                                            value="<?php echo esc_attr($layer['color'] ?? '#000000'); ?>"
                                            oninput="this.previousElementSibling.value=this.value"
                                            class="evo-mono evo-w-hex">
                                    </div>
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-direction">Kierunek</label>
                                    <select id="evo-f-evk_og-layers-<?php echo $li; ?>-direction" name="evk_og[layers][<?php echo $li; ?>][direction]">
                                        <?php foreach (['top' => '↑ Górny', 'bottom' => '↓ Dolny', 'left' => '← Lewy', 'right' => '→ Prawy'] as $dv => $dl): ?>
                                        <option value="<?php echo $dv; ?>" <?php selected($layer['direction'] ?? 'bottom', $dv); ?>><?php echo $dl; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-alpha_start">Alpha start (%)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-alpha_start" type="number" name="evk_og[layers][<?php echo $li; ?>][alpha_start]" value="<?php echo esc_attr($layer['alpha_start'] ?? 0); ?>" min="0" max="100">
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-alpha_end">Alpha end (%)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-alpha_end" type="number" name="evk_og[layers][<?php echo $li; ?>][alpha_end]" value="<?php echo esc_attr($layer['alpha_end'] ?? 100); ?>" min="0" max="100">
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-pos_pct">Pozycja startu (%)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-pos_pct" type="number" name="evk_og[layers][<?php echo $li; ?>][pos_pct]" value="<?php echo esc_attr($layer['pos_pct'] ?? 50); ?>" min="0" max="100">
                                    <div class="evo-hint-sm evo-muted" style="margin-top:3px">Gdzie gradient zaczyna się zanikać.</div>
                                </div>

                                <?php elseif ($type === 'image'): ?>
                                <div class="evo-og-full">
                                    <label>Obraz</label>
                                    <?php
                                    $img_id  = absint($layer['image_id'] ?? 0);
                                    $img_url = $img_id ? wp_get_attachment_image_url($img_id, 'thumbnail') : '';
                                    ?>
                                    <div class="evo-og-media-row" id="evk-og-img-preview-<?php echo $li; ?>">
                                        <?php if ($img_url): ?>
                                            <img src="<?php echo esc_url($img_url); ?>">
                                            <span class="evo-hint evo-hint-soft"><?php echo esc_html(get_the_title($img_id)); ?></span>
                                        <?php else: ?>
                                            <span class="evo-hint evo-faint">Nie wybrano</span>
                                        <?php endif; ?>
                                    </div>
                                    <input type="hidden" name="evk_og[layers][<?php echo $li; ?>][image_id]" id="evk-og-img-id-<?php echo $li; ?>" value="<?php echo esc_attr($img_id); ?>">
                                    <button type="button" class="button" style="margin-top:6px;" onclick="evkOgPickMedia('image', <?php echo $li; ?>)">Wybierz obraz</button>
                                </div>

                                <?php elseif ($type === 'text'): ?>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-x">X (od lewej, px)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-x" type="number" name="evk_og[layers][<?php echo $li; ?>][x]" value="<?php echo esc_attr($layer['x'] ?? 275); ?>">
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-y_from_bottom">Y od dołu (px)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-y_from_bottom" type="number" name="evk_og[layers][<?php echo $li; ?>][y_from_bottom]" value="<?php echo esc_attr($layer['y_from_bottom'] ?? 120); ?>">
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-max_width">Maks. szerokość (px)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-max_width" type="number" name="evk_og[layers][<?php echo $li; ?>][max_width]" value="<?php echo esc_attr($layer['max_width'] ?? 900); ?>">
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-font_size">Rozmiar fontu (px)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-font_size" type="number" name="evk_og[layers][<?php echo $li; ?>][font_size]" value="<?php echo esc_attr($layer['font_size'] ?? 80); ?>">
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-color">Kolor tekstu</label>
                                    <div class="evo-og-color-pair">
                                        <input type="color" aria-label="Kolor tekstu — próbnik" value="<?php echo esc_attr($layer['color'] ?? '#ffffff'); ?>"
                                            oninput="this.nextElementSibling.value=this.value">
                                        <input type="text" id="evo-f-evk_og-layers-<?php echo $li; ?>-color" name="evk_og[layers][<?php echo $li; ?>][color]"
                                            value="<?php echo esc_attr($layer['color'] ?? '#ffffff'); ?>"
                                            oninput="this.previousElementSibling.value=this.value"
                                            class="evo-mono evo-w-hex">
                                    </div>
                                </div>
                                <div class="evo-og-full evo-hr-top">
                                    <label class="checkbox-label evo-mb-sm">
                                        <input type="checkbox" name="evk_og[layers][<?php echo $li; ?>][shadow_enabled]" value="1" <?php checked(!empty($layer['shadow_enabled'])); ?>>
                                        Cień tekstu
                                    </label>
                                    <div class="evo-grid" style="--evo-col:140px;--evo-gap:10px">
                                        <div>
                                            <label for="evo-f-evk_og-layers-<?php echo $li; ?>-shadow_color">Kolor cienia</label>
                                            <div class="evo-og-color-pair">
                                                <input type="color" aria-label="Kolor cienia — próbnik" value="<?php echo esc_attr($layer['shadow_color'] ?? '#000000'); ?>"
                                                    oninput="this.nextElementSibling.value=this.value">
                                                <input type="text" id="evo-f-evk_og-layers-<?php echo $li; ?>-shadow_color" name="evk_og[layers][<?php echo $li; ?>][shadow_color]"
                                                    value="<?php echo esc_attr($layer['shadow_color'] ?? '#000000'); ?>"
                                                    oninput="this.previousElementSibling.value=this.value"
                                                    class="evo-mono evo-w-hex">
                                            </div>
                                        </div>
                                        <div><label for="evo-f-evk_og-layers-<?php echo $li; ?>-shadow_offset_x">Offset X (px)</label><input id="evo-f-evk_og-layers-<?php echo $li; ?>-shadow_offset_x" type="number" name="evk_og[layers][<?php echo $li; ?>][shadow_offset_x]" value="<?php echo esc_attr($layer['shadow_offset_x'] ?? 3); ?>"></div>
                                        <div><label for="evo-f-evk_og-layers-<?php echo $li; ?>-shadow_offset_y">Offset Y (px)</label><input id="evo-f-evk_og-layers-<?php echo $li; ?>-shadow_offset_y" type="number" name="evk_og[layers][<?php echo $li; ?>][shadow_offset_y]" value="<?php echo esc_attr($layer['shadow_offset_y'] ?? 5); ?>"></div>
                                        <div><label for="evo-f-evk_og-layers-<?php echo $li; ?>-shadow_alpha">Alpha (%)</label><input id="evo-f-evk_og-layers-<?php echo $li; ?>-shadow_alpha" type="number" name="evk_og[layers][<?php echo $li; ?>][shadow_alpha]" value="<?php echo esc_attr($layer['shadow_alpha'] ?? 50); ?>" min="0" max="100"></div>
                                        <div><label for="evo-f-evk_og-layers-<?php echo $li; ?>-shadow_blur">Blur (px)</label><input id="evo-f-evk_og-layers-<?php echo $li; ?>-shadow_blur" type="number" name="evk_og[layers][<?php echo $li; ?>][shadow_blur]" value="<?php echo esc_attr($layer['shadow_blur'] ?? 2); ?>" min="0" max="20"></div>
                                    </div>
                                </div>

                                <?php elseif ($type === 'qr'): ?>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-x">Margin prawy (px)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-x" type="number" name="evk_og[layers][<?php echo $li; ?>][x]" value="<?php echo esc_attr($layer['x'] ?? 25); ?>">
                                    <div class="evo-hint-sm evo-muted" style="margin-top:3px">X = odległość od prawej krawędzi.</div>
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-y">Y (od góry, px)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-y" type="number" name="evk_og[layers][<?php echo $li; ?>][y]" value="<?php echo esc_attr($layer['y'] ?? 426); ?>">
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-size">Rozmiar (px)</label>
                                    <input id="evo-f-evk_og-layers-<?php echo $li; ?>-size" type="number" name="evk_og[layers][<?php echo $li; ?>][size]" value="<?php echo esc_attr($layer['size'] ?? 170); ?>" min="50" max="500">
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-fg_color">Kolor kodu (fg)</label>
                                    <div class="evo-og-color-pair">
                                        <input type="color" aria-label="Kolor kodu (fg) — próbnik" value="<?php echo esc_attr($layer['fg_color'] ?? '#ffffff'); ?>"
                                            oninput="this.nextElementSibling.value=this.value">
                                        <input type="text" id="evo-f-evk_og-layers-<?php echo $li; ?>-fg_color" name="evk_og[layers][<?php echo $li; ?>][fg_color]"
                                            value="<?php echo esc_attr($layer['fg_color'] ?? '#ffffff'); ?>"
                                            oninput="this.previousElementSibling.value=this.value"
                                            class="evo-mono evo-w-hex">
                                    </div>
                                </div>
                                <div>
                                    <label for="evo-f-evk_og-layers-<?php echo $li; ?>-bg_color">Kolor tła (bg)</label>
                                    <div class="evo-og-color-pair">
                                        <input type="color" aria-label="Kolor tła (bg) — próbnik" value="<?php echo esc_attr($layer['bg_color'] ?? '#000000'); ?>"
                                            oninput="this.nextElementSibling.value=this.value">
                                        <input type="text" id="evo-f-evk_og-layers-<?php echo $li; ?>-bg_color" name="evk_og[layers][<?php echo $li; ?>][bg_color]"
                                            value="<?php echo esc_attr($layer['bg_color'] ?? '#000000'); ?>"
                                            oninput="this.previousElementSibling.value=this.value"
                                            class="evo-mono evo-w-hex">
                                    </div>
                                </div>
                                <?php endif; ?>

                            </div><!-- .evo-og-layer-fields -->
                        </div><!-- .evo-og-layer -->
                        <?php endforeach; ?>
                    </div><!-- #evk-og-layers-container -->

                    <!-- Dodaj warstwę -->
                    <div class="evo-toolbar" style="margin:14px 0 0">
                        <select id="evk-og-new-layer-type" class="evo-w-md" aria-label="Typ nowej warstwy">
                            <?php foreach ($layer_types as $tv => $tl): ?>
                            <option value="<?php echo esc_attr($tv); ?>"><?php echo esc_html($tl); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" class="button" onclick="evkOgAddLayer()">+ Dodaj warstwę</button>
                    </div>
                </div>

                <!-- REGENERACJA MASOWA -->
                <div class="evo-box">
                    <h3>Narzędzia</h3>
                    <div class="evo-toolbar" style="margin-bottom:0">
                        <button type="button" class="button" id="evk-og-regen-all" onclick="evkOgRegenAll()">
                            <span class="dashicons dashicons-update evo-ico evo-ico-lead"></span>
                            Regeneruj wszystkie obrazy OG
                        </button>
                        <span id="evk-og-regen-result" class="evo-og-regen-result"></span>
                    </div>
                    <div class="evo-desc" style="margin-top:8px;">Przetworzy wszystkie opublikowane posty z przypisanymi miniaturami.</div>
                </div>

                <?php evoke_one_pasek_zapisu('Zapisz ustawienia OpenGraph'); ?>
            </form>

            <?php // Skrypty zakładki siedzą w assets/admin/admin.js.
                 // Blok <script> w TREŚCI strony rusza przed stopką, a
                 // biblioteka przeciągania (sortablejs) i wp.media jadą
                 // właśnie ze stopki — inicjalizacja stąd trafiała w pustkę.
                 // Zależności deklaruje admin_enqueue_scripts. ?>
