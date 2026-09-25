<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Tab: darkmode
 */
?>
<?php $dm = EVK_DarkMode::get_instance()->get_settings(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_darkmode'); ?>

                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($dm['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons <?php echo !empty($dm['enabled']) ? 'dashicons-visibility' : 'dashicons-hidden'; ?>"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Moduł Dark Mode: <?php echo !empty($dm['enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
                        <p>Przejścia CSS i efekty View Transition API dla przełączania motywu.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($dm['enabled']) ? 'Włączony' : 'Wyłączony'; ?></span>
                        <label class="evo-toggle">
                            <input aria-label="Moduł Dark Mode" type="checkbox" data-option="evk_darkmode" data-field="enabled" value="1" <?php checked(!empty($dm['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Przełącznik motywu</h3>
                    <div class="evo-field">
                        <label for="evo-f-evk_darkmode-toggle_selector">Selektor CSS przycisku przełączającego</label>
                        <input id="evo-f-evk_darkmode-toggle_selector" type="text" name="evk_darkmode[toggle_selector]" value="<?php echo esc_attr($dm['toggle_selector']); ?>" placeholder=".brxe-toggle-mode" class="evo-w-xl">
                        <div class="evo-desc">Dowolny selektor CSS — klasa, ID lub atrybut. Domyślnie: <code>.brxe-toggle-mode</code></div>
                    </div>

                </div>

                <div class="evo-box">
                    <h3>Przejścia przy nawigacji między stronami</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Animacja podczas przechodzenia między podstronami. Wymaga Chrome/Edge 111+ z View Transition API.</div></details>

                    <div class="evo-field">
                        <label class="checkbox-label">
                            <input type="checkbox" name="evk_darkmode[wipe_enabled]" value="1" <?php checked(!empty($dm['wipe_enabled'])); ?>>
                            Włącz przejście przy nawigacji
                        </label>
                    </div>

                    <?php
                    $nav_types = [
                        'wipe'       => ['Zasłona', 'Kolorowa zasłona przesuwa się przez ekran'],
                        'fade'       => ['Fade', 'Stara strona zanika, nowa pojawia się'],
                        'zoom-out'   => ['Zoom Out', 'Stara strona oddala się, nowa przylatuje'],
                        'zoom-in'    => ['Zoom In', 'Stara strona przybliża się, nowa wyjeżdża'],
                        'slide-push' => ['Slide Push', 'Nowa strona wypycha starą w lewo'],
                        'iris'       => ['Iris', 'Nowa strona otwiera się kołem ze środka'],
                        'nav-ripple' => ['Ripple od kliknięcia', 'Fala rozchodzi się od miejsca kliknięcia'],
                    ];
                    $cur_type = $dm['nav_trans_type'] ?? 'wipe';
                    ?>
                    <div class="evo-field">
                        <label>Typ przejścia</label>
                        <div class="evo-grid" style="--evo-col:180px;--evo-gap:8px;margin-top:6px">
                            <?php foreach ($nav_types as $val => [$name, $desc]): ?>
                            <label class="evo-choice evo-choice-stack">
                                <input type="radio" name="evk_darkmode[nav_trans_type]" value="<?php echo $val; ?>" <?php checked($cur_type, $val); ?>>
                                <span>
                                    <strong><?php echo $name; ?></strong>
                                    <span class="evo-hint-sm"><?php echo $desc; ?></span>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="evo-grid" style="--evo-col:160px;--evo-gap:16px;margin-top:8px">
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-wipe_duration">Czas trwania (s)</label>
                            <input id="evo-f-evk_darkmode-wipe_duration" type="number" name="evk_darkmode[wipe_duration]" value="<?php echo esc_attr($dm['wipe_duration']); ?>" min="0.3" max="5" step="0.1" class="evo-w-xs">
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-wipe_easing">Easing</label>
                            <input id="evo-f-evk_darkmode-wipe_easing" type="text" name="evk_darkmode[wipe_easing]" value="<?php echo esc_attr($dm['wipe_easing']); ?>" class="evo-w-xl" placeholder="np. cubic-bezier(0.33, 1, 0.68, 1)">
                        </div>
                    </div>

                    <details class="evo-fold">
                        <summary>Opcje szczegółowe (Zasłona / Ripple)</summary>
                        <div class="evo-fold-body evo-grid" style="--evo-col:200px;--evo-gap:16px">
                            <div class="evo-field">
                                <label for="wipe-color-text">Kolor zasłony</label>
                                <div class="evo-color-row">
                                    <button type="button" class="evo-color-swatch" id="wipe-color-swatch" aria-label="Kolor zasłony — próbnik"
                                        style="--evo-swatch:<?php echo esc_attr($dm['wipe_color']); ?>"
                                        onclick="document.getElementById('wipe_color_input').click();">
                                    </button>
                                    <input type="color" id="wipe_color_input" name="evk_darkmode[wipe_color]" aria-label="Kolor zasłony"
                                        value="<?php echo esc_attr($dm['wipe_color']); ?>"
                                        class="evo-hidden"
                                        oninput="document.getElementById('wipe-color-swatch').style.setProperty('--evo-swatch',this.value);document.getElementById('wipe-color-text').value=this.value;">
                                    <input type="text" id="wipe-color-text" value="<?php echo esc_attr($dm['wipe_color']); ?>"
                                        class="evo-mono evo-w-hex"
                                        oninput="var v=this.value;if(/^#[0-9a-fA-F]{6}$/.test(v)){document.getElementById('wipe_color_input').value=v;document.getElementById('wipe-color-swatch').style.setProperty('--evo-swatch',v);}">
                                </div>
                            </div>
                            <div class="evo-field">
                                <label for="evo-f-evk_darkmode-wipe_direction">Kierunek zasłony</label>
                                <select id="evo-f-evk_darkmode-wipe_direction" name="evk_darkmode[wipe_direction]">
                                    <?php foreach (['to bottom' => 'Z góry na dół ↓', 'to top' => 'Z dołu do góry ↑', 'to right' => 'Od lewej do prawej →', 'to left' => 'Od prawej do lewej ←'] as $val => $label): ?>
                                    <option value="<?php echo esc_attr($val); ?>" <?php selected($dm['wipe_direction'], $val); ?>><?php echo esc_html($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="evo-field">
                                <label for="evo-f-evk_darkmode-wipe_blur">Rozmycie krawędzi zasłony</label>
                                <input id="evo-f-evk_darkmode-wipe_blur" type="number" name="evk_darkmode[wipe_blur]" value="<?php echo esc_attr($dm['wipe_blur']); ?>" min="0" max="50" step="5" class="evo-w-xs">
                                <div class="evo-desc">0 = ostra, 50 = miękka</div>
                            </div>
                            <div class="evo-field">
                                <label for="nav-ripple-color-text">Kolor Ripple</label>
                                <div class="evo-color-row">
                                    <button type="button" class="evo-color-swatch" id="nav-ripple-color-swatch" aria-label="Kolor Ripple — próbnik"
                                        style="--evo-swatch:<?php echo esc_attr($dm['nav_ripple_color'] ?? '#ffffff'); ?>"
                                        onclick="document.getElementById('nav_ripple_color_input').click();">
                                    </button>
                                    <input type="color" id="nav_ripple_color_input" name="evk_darkmode[nav_ripple_color]" aria-label="Kolor Ripple"
                                        value="<?php echo esc_attr($dm['nav_ripple_color'] ?? '#ffffff'); ?>"
                                        class="evo-hidden"
                                        oninput="document.getElementById('nav-ripple-color-swatch').style.setProperty('--evo-swatch',this.value);document.getElementById('nav-ripple-color-text').value=this.value;">
                                    <input type="text" id="nav-ripple-color-text" value="<?php echo esc_attr($dm['nav_ripple_color'] ?? '#ffffff'); ?>"
                                        class="evo-mono evo-w-hex"
                                        oninput="var v=this.value;if(/^#[0-9a-fA-F]{6}$/.test(v)){document.getElementById('nav_ripple_color_input').value=v;document.getElementById('nav-ripple-color-swatch').style.setProperty('--evo-swatch',v);}">
                                </div>
                            </div>
                            <div class="evo-field">
                                <label for="evo-f-evk_darkmode-nav_ripple_blur">Rozmycie Ripple (px)</label>
                                <input id="evo-f-evk_darkmode-nav_ripple_blur" type="number" name="evk_darkmode[nav_ripple_blur]" value="<?php echo esc_attr($dm['nav_ripple_blur'] ?? 20); ?>" min="0" max="100" step="5" class="evo-w-xs">
                            </div>
                        </div>
                    </details>

                </div>

                <div class="evo-box">
                    <h3>Zmienne kolorów w gradientach</h3>
                    <div class="evo-field">
                        <label for="evo-f-evk_darkmode-color_vars">Zmienne kolorów do animowania (jedna na linię)</label>
                        <textarea id="evo-f-evk_darkmode-color_vars" name="evk_darkmode[color_vars]" rows="3" placeholder="--kolor-glowny-d-2"><?php echo esc_textarea($dm['color_vars']); ?></textarea>
                        <details class="evo-note"><summary>Po co to jest</summary><div class="evo-note-body">
                            <p><strong>Przy włączonej fali to pole nie jest potrzebne.</strong> Zmierzone:
                            gradient z zarejestrowaną zmienną i bez niej zachowują się pod falą tak samo —
                            oba czekają nieruchomo, aż fala po nich przejdzie.</p>
                            <p>Przydaje się dopiero <strong>bez fali</strong>. Gradient to
                            <code>background-image</code>, a przeglądarka nie potrafi go płynnie przefarbować,
                            gdy kolor siedzi w <code>var()</code> — wtedy przeskakuje zamiast płynąć.</p>
                            <p>Wpisz tu nazwy zmiennych, które trzymają te kolory (po jednej w wierszu,
                            np. <code>--kolor-glowny-d-2</code>). Wtedy zmieniają się płynnie razem z resztą
                            motywu, a fala je odsłania tak samo jak zwykłe tła.</p>
                            <p><strong>Tylko zmienne, które trzymają kolor.</strong> Wpisanie zmiennej
                            z rozmiarem albo cieniem sprawi, że w tym miejscu zrobi się przezroczyście.</p>
                        </div></details>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Przejście logo (View Transition)</h3>
                    <div class="evo-field">
                        <label class="checkbox-label">
                            <input type="checkbox" name="evk_darkmode[logo_enabled]" value="1" <?php checked(!empty($dm['logo_enabled'])); ?>>
                            Włącz animację przejścia logo
                        </label>
                    </div>
                    <div class="evo-inline-fields">
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-logo_light_class">Klasa logo jasnego</label>
                            <input id="evo-f-evk_darkmode-logo_light_class" type="text" name="evk_darkmode[logo_light_class]" value="<?php echo esc_attr($dm['logo_light_class']); ?>" class="evo-w-sm">
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-logo_dark_class">Klasa logo ciemnego</label>
                            <input id="evo-f-evk_darkmode-logo_dark_class" type="text" name="evk_darkmode[logo_dark_class]" value="<?php echo esc_attr($dm['logo_dark_class']); ?>" class="evo-w-sm">
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-logo_duration">Czas animacji (s)</label>
                            <input id="evo-f-evk_darkmode-logo_duration" type="number" name="evk_darkmode[logo_duration]" value="<?php echo esc_attr($dm['logo_duration']); ?>" min="0.1" max="5" step="0.1" class="evo-w-xs">
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-logo_easing">Easing</label>
                            <input id="evo-f-evk_darkmode-logo_easing" type="text" name="evk_darkmode[logo_easing]" value="<?php echo esc_attr($dm['logo_easing']); ?>" class="evo-w-xl" placeholder="np. cubic-bezier(0.33, 1, 0.68, 1)">
                        </div>
                    </div>

                </div>

                <div class="evo-box">
                    <h3>Przejście przy przełączaniu motywu</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body"><p>Cała strona zostaje zapamiętana jako jedna migawka, a nowy motyw jest spod niej ODSŁANIANY. Dlatego nic nie zmienia koloru, dopóki przejście po nim nie przejdzie — i dlatego niczego nie trzeba dopisywać do żadnej listy.</p><p>Wymaga Chrome/Edge 111+. <strong>Tam, gdzie to nie zadziała — bo przejście jest wyłączone albo przeglądarka go nie zna — kolory i tak zmieniają się płynnie.</strong> To jest wbudowane i nie ma czego ustawiać; do 1.220.0 stały tu dwie sekcje z listami selektorów, w których trzeba było zgadywać, co dopisać.</p></div></details>
                    <div class="evo-field">
                        <label class="checkbox-label">
                            <input type="checkbox" name="evk_darkmode[ripple_enabled]" value="1" <?php checked(!empty($dm['ripple_enabled'])); ?>>
                            Włącz przejście przy zmianie motywu
                        </label>
                    </div>

                    <?php
                    /* Typ przejścia MOTYWU. Osobna lista niż przy nawigacji —
                       tam przechodzi się między dwiema stronami, tu przemalowuje
                       tę samą. Wspólna jest migawka, na której stoją wszystkie
                       trzy, więc poprawki z 1.218.0 i 1.219.0 dotyczą każdego. */
                    $theme_types = [
                        'ripple' => ['Fala od przycisku', 'Koło rozchodzi się od miejsca kliknięcia'],
                        'wipe'   => ['Zasłona z góry na dół', 'Nowy motyw zjeżdża poziomą krawędzią'],
                        'fade'   => ['Fade całej strony', 'Nowy motyw przenika przez stary, bez krawędzi'],
                    ];
                    $cur_theme = $dm['theme_trans_type'] ?? 'ripple';
                    ?>
                    <div class="evo-field">
                        <label>Typ przejścia</label>
                        <div class="evo-grid" style="--evo-col:180px;--evo-gap:8px;margin-top:6px">
                            <?php foreach ($theme_types as $val => [$name, $desc]): ?>
                            <label class="evo-choice evo-choice-stack">
                                <input type="radio" name="evk_darkmode[theme_trans_type]" value="<?php echo $val; ?>" <?php checked($cur_theme, $val); ?>>
                                <span>
                                    <strong><?php echo $name; ?></strong>
                                    <span class="evo-hint-sm"><?php echo $desc; ?></span>
                                </span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="evo-inline-fields">
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-ripple_duration">Czas trwania (ms)</label>
                            <input id="evo-f-evk_darkmode-ripple_duration" type="number" name="evk_darkmode[ripple_duration]" value="<?php echo esc_attr($dm['ripple_duration']); ?>" min="200" max="5000" step="100" class="evo-w-xs">
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-ripple_blur">Rozmycie krawędzi (px)</label>
                            <input id="evo-f-evk_darkmode-ripple_blur" type="number" name="evk_darkmode[ripple_blur]" value="<?php echo esc_attr($dm['ripple_blur']); ?>" min="0" max="100" step="5" class="evo-w-xs">
                            <span class="evo-hint-sm">Dotyczy krawędzi fali i zasłony. Przy przenikaniu nie ma znaczenia — fade nie ma krawędzi.</span>
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-ripple_easing">Easing</label>
                            <input id="evo-f-evk_darkmode-ripple_easing" type="text" name="evk_darkmode[ripple_easing]" value="<?php echo esc_attr($dm['ripple_easing']); ?>" class="evo-w-xl">
                        </div>
                    </div>

                </div>

                <div class="evo-box">
                    <h3>Przejścia elementów lista → wpis (View Transition)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Tytuł i obrazek "przefruwają" z listy wpisów do strony wpisu. Wymaga Chrome/Edge 111+. Potrzebne włączone przejście przy nawigacji powyżej.</div></details>

                    <div class="evo-field">
                        <label class="checkbox-label">
                            <input type="checkbox" name="evk_darkmode[post_trans_enabled]" value="1" <?php checked(!empty($dm['post_trans_enabled'])); ?>>
                            Włącz przejścia lista → wpis
                        </label>
                    </div>

                    <div class="evo-grid-2" style="--evo-gap:16px;margin-top:8px">
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-post_trans_title_class">Klasa tytułu <span class="evo-label-note">na liście (bez kropki)</span></label>
                            <input id="evo-f-evk_darkmode-post_trans_title_class" type="text" name="evk_darkmode[post_trans_title_class]" value="<?php echo esc_attr($dm['post_trans_title_class']); ?>" placeholder="post-card-title" class="evo-w-full">
                            <div class="evo-desc">Klasa elementu Bricks z tytułem w query loop. Kilka — oddziel przecinkami.</div>
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-post_trans_image_class">Klasa obrazka <span class="evo-label-note">na liście (bez kropki)</span></label>
                            <input id="evo-f-evk_darkmode-post_trans_image_class" type="text" name="evk_darkmode[post_trans_image_class]" value="<?php echo esc_attr($dm['post_trans_image_class']); ?>" placeholder="post-card-image" class="evo-w-full">
                            <div class="evo-desc">Klasa elementu Bricks z obrazkiem w query loop. Kilka — oddziel przecinkami.</div>
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-post_trans_title_single">Selektor tytułu <span class="evo-label-note">na singlu (CSS)</span></label>
                            <input id="evo-f-evk_darkmode-post_trans_title_single" type="text" name="evk_darkmode[post_trans_title_single]" value="<?php echo esc_attr($dm['post_trans_title_single']); ?>" placeholder=".single-post .brxe-post-title h1" class="evo-w-full">
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-post_trans_image_single">Selektor obrazka <span class="evo-label-note">na singlu (CSS)</span></label>
                            <input id="evo-f-evk_darkmode-post_trans_image_single" type="text" name="evk_darkmode[post_trans_image_single]" value="<?php echo esc_attr($dm['post_trans_image_single']); ?>" placeholder=".single-post .brxe-post-image img" class="evo-w-full">
                        </div>
                    </div>

                    <div class="evo-grid" style="--evo-col:160px;--evo-gap:16px;margin-top:8px">
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-post_trans_duration">Czas animacji (s)</label>
                            <input id="evo-f-evk_darkmode-post_trans_duration" type="number" name="evk_darkmode[post_trans_duration]" value="<?php echo esc_attr($dm['post_trans_duration']); ?>" min="0.1" max="3.0" step="0.1" class="evo-w-xs">
                        </div>
                        <div class="evo-field">
                            <label for="evo-f-evk_darkmode-post_trans_easing">Easing</label>
                            <input id="evo-f-evk_darkmode-post_trans_easing" type="text" name="evk_darkmode[post_trans_easing]" value="<?php echo esc_attr($dm['post_trans_easing'] ?? 'ease-in-out'); ?>" class="evo-w-xl" placeholder="np. cubic-bezier(0.33, 1, 0.68, 1)">
                        </div>
                    </div>

                </div>

<?php evoke_one_pasek_zapisu('Zapisz ustawienia Dark Mode'); ?>
</form>
