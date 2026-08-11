<?php
if (!defined('ABSPATH')) exit;
?>
            <?php
            $sc      = EVK_Schema::get_instance()->get_settings();
            $langs   = function_exists('tl_get_languages') ? tl_get_languages() : [];
            $descs   = json_decode($sc['descriptions'],    true) ?: [];
            $socials = json_decode($sc['social_links'],    true) ?: [];
            $currs   = json_decode($sc['lang_currencies'], true) ?: [];
            $subs    = json_decode($sc['sub_entities'] ?? '[]', true) ?: [];
            $sub_types = EVK_Schema::sub_entity_types();

            // Renderuje jeden wiersz repeatera podrzędnych encji
            $render_sub_row = static function (array $row) use ($sub_types) {
                $r_type = $row['type'] ?? '';
                $r_name = $row['name'] ?? '';
                $r_desc = $row['description'] ?? '';
                ob_start(); ?>
                <div class="evk-sub-row">
                    <select name="evk_schema_sub[type][]">
                        <?php foreach ($sub_types as $tk => $tl): ?>
                        <option value="<?php echo esc_attr($tk); ?>" <?php selected($r_type, $tk); ?>><?php echo esc_html($tl); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="evk_schema_sub[name][]" value="<?php echo esc_attr($r_name); ?>" placeholder="Nazwa, np. Wypożyczalnia kajaków">
                    <input type="text" name="evk_schema_sub[description][]" value="<?php echo esc_attr($r_desc); ?>" placeholder="Opis (opcjonalnie)">
                    <button type="button" class="button evk-sub-remove" title="Usuń"><span class="dashicons dashicons-trash"></span></button>
                </div>
                <?php return ob_get_clean();
            };
            ?>
            <form method="post" action="options.php">
                <?php settings_fields('evoke_one_schema'); ?>
                <div class="evo-status-card">
                    <div class="evo-status-icon <?php echo !empty($sc['enabled']) ? 'on' : 'off'; ?>">
                        <span class="dashicons dashicons-database"></span>
                    </div>
                    <div class="evo-status-text">
                        <h3>Moduł Schema: <?php echo !empty($sc['enabled']) ? 'WŁĄCZONY' : 'WYŁĄCZONY'; ?></h3>
                        <p>Generuje JSON-LD @graph w &lt;head&gt; każdej podstrony.</p>
                    </div>
                    <div class="evo-status-actions">
                        <span class="evo-toggle-label"><?php echo !empty($sc['enabled']) ? 'Włączony' : 'Wyłączony'; ?></span>
                        <label class="evo-toggle">
                            <input type="checkbox" data-option="evk_schema" data-field="enabled" value="1" <?php checked(!empty($sc['enabled'])); ?>>
                            <span class="evo-slider"></span>
                        </label>
                    </div>
                </div>

                <div class="evo-box">
                    <h3>Dane organizacji</h3>
                    <div class="evo-grid evo-mb" style="--evo-col:280px;--evo-gap:16px">
                        <div class="evo-field evo-mb-0">
                            <label>Typ działalności (@type)<span class="evo-tip" tabindex="0" role="note" data-tip="Typ inny niż „Organizacja&quot; tworzy w grafie osobny węzeł miejsca (#place) z polami firmy lokalnej poniżej; #organization pozostaje czystym wydawcą strony." aria-label="Typ inny niż „Organizacja&quot; tworzy w grafie osobny węzeł miejsca (#place) z polami firmy lokalnej poniżej; #organization pozostaje czystym wydawcą strony.">?</span></label>
                            <select name="evk_schema[org_type]">
                                <?php foreach (EVK_Schema::org_types() as $type_key => $type_label): ?>
                                <option value="<?php echo esc_attr($type_key); ?>" <?php selected($sc['org_type'], $type_key); ?>><?php echo esc_html($type_label); ?> — <?php echo esc_html($type_key); ?></option>
                                <?php endforeach; ?>
                            </select>
                            
                        </div>
                        <div class="evo-field evo-mb-0"><label>Nazwa obiektu / firmy (site_name)</label><input type="text" name="evk_schema[site_name]" value="<?php echo esc_attr($sc['site_name']); ?>" placeholder="np. Stanica Wodna PTTK Ukta"></div>
                        <div class="evo-field evo-mb-0"><label>Nazwa operatora (Organization)</label><input type="text" name="evk_schema[operator_name]" value="<?php echo esc_attr($sc['operator_name']); ?>" placeholder="np. PTTK Oddział Mazurski"><div class="evo-desc">Wydawca strony / właściciel obiektu. Puste = nazwa obiektu.</div></div>
                        <div class="evo-field evo-mb-0"><label>Telefon</label><input type="text" name="evk_schema[telephone]" value="<?php echo esc_attr($sc['telephone']); ?>" placeholder="+48 000 000 000"></div>
                        <div class="evo-field evo-mb-0"><label>E-mail</label><input type="text" name="evk_schema[email]" value="<?php echo esc_attr($sc['email']); ?>" placeholder="biuro@domena.pl"></div>
                        <div class="evo-field evo-mb-0"><label>Ulica i numer</label><input type="text" name="evk_schema[street_address]" value="<?php echo esc_attr($sc['street_address']); ?>" placeholder="ul. Przykładowa 1"></div>
                        <div class="evo-field evo-mb-0"><label>Miejscowość</label><input type="text" name="evk_schema[locality]" value="<?php echo esc_attr($sc['locality']); ?>" placeholder="Warszawa"></div>
                        <div class="evo-field evo-mb-0"><label>Kod pocztowy</label><input type="text" name="evk_schema[postal_code]" value="<?php echo esc_attr($sc['postal_code']); ?>" placeholder="00-000"></div>
                        <div class="evo-field evo-mb-0"><label>Kod kraju (ISO)</label><input type="text" name="evk_schema[country]" value="<?php echo esc_attr($sc['country']); ?>" placeholder="PL" class="evo-w-full"></div>
                        <div class="evo-field evo-mb-0"><label>Typ kontaktu (contactType)</label><input type="text" name="evk_schema[contact_type]" value="<?php echo esc_attr($sc['contact_type']); ?>" placeholder="booking"></div>
                        <div class="evo-field evo-mb-0"><label>URL logo / faviconu</label><input type="text" name="evk_schema[favicon_url]" value="<?php echo esc_attr($sc['favicon_url']); ?>" placeholder="/wp-content/uploads/logo.png"><div class="evo-desc">Ścieżka relatywna lub pełny URL.</div></div>
                    </div>

                </div>

                <div class="evo-box">
                    <h3>Miejsce / firma lokalna (węzeł #place)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Pola używane tylko, gdy typ działalności jest inny niż „Organizacja" (LocalBusiness i pochodne — np. obiekt noclegowy, restauracja). Trafiają do osobnego węzła #place powiązanego z #organization przez parentOrganization. Współrzędne znajdziesz np. w Mapach Google (PPM na pinezce).</div></details>
                    <div class="evo-grid evo-mb" style="--evo-col:280px;--evo-gap:16px">
                        <div class="evo-field evo-mb-0"><label>Szerokość geograficzna (latitude)</label><input type="text" name="evk_schema[geo_lat]" value="<?php echo esc_attr($sc['geo_lat']); ?>" placeholder="53.12345"></div>
                        <div class="evo-field evo-mb-0"><label>Długość geograficzna (longitude)</label><input type="text" name="evk_schema[geo_lng]" value="<?php echo esc_attr($sc['geo_lng']); ?>" placeholder="21.12345"></div>
                        <div class="evo-field evo-mb-0"><label>Przedział cenowy (priceRange)</label><input type="text" name="evk_schema[price_range]" value="<?php echo esc_attr($sc['price_range']); ?>" placeholder="$$"><div class="evo-desc">Umownie: $ tanio … $$$$ drogo (albo np. „50–200 zł").</div></div>
                        <div class="evo-field evo-mb-0"><label>Link do mapy (hasMap)</label><input type="text" name="evk_schema[has_map]" value="<?php echo esc_attr($sc['has_map']); ?>" placeholder="https://maps.google.com/…"><div class="evo-desc">Np. link „Udostępnij" z Map Google.</div></div>
                    </div>
                    <div class="evo-field"><label>Udogodnienia (amenityFeature) — jedno na linię</label><textarea name="evk_schema[amenities]" rows="4" class="evo-w-480" placeholder="Spływy kajakowe&#10;Pole namiotowe&#10;Sauna"><?php echo esc_textarea($sc['amenities']); ?></textarea></div>
                    <div class="evo-field"><label>Godziny otwarcia (openingHoursSpecification) — jedna reguła na linię<span class="evo-tip" tabindex="0" role="note" data-tip="Format: dni + godziny, np. „Pn-Pt 08:00-20:00&quot;, „Sob 09:00-14:00&quot;, „Codziennie 08:00-20:00&quot;. Dni: Pn, Wt, Śr, Cz, Pt, Sob, Nd (można łączyć przecinkiem i zakresem)." aria-label="Format: dni + godziny, np. „Pn-Pt 08:00-20:00&quot;, „Sob 09:00-14:00&quot;, „Codziennie 08:00-20:00&quot;. Dni: Pn, Wt, Śr, Cz, Pt, Sob, Nd (można łączyć przecinkiem i zakresem).">?</span></label><textarea name="evk_schema[opening_hours]" rows="3" class="evo-mono evo-w-480" placeholder="Pn-Pt 08:00-20:00&#10;Sob-Nd 09:00-18:00"><?php echo esc_textarea($sc['opening_hours']); ?></textarea></div>
                    <div class="evo-field"><label>Obsługiwany obszar (areaServed) — jeden na linię</label><textarea name="evk_schema[area_served]" rows="3" class="evo-w-480" placeholder="Mazury&#10;Puszcza Piska&#10;Krutynia"><?php echo esc_textarea($sc['area_served']); ?></textarea></div>

                </div>

                <div class="evo-box">
                    <h3>Atrakcja turystyczna (TouristAttraction)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Osobny obiekt w grafie — włącz go w „Aktywne bloki JSON-LD" poniżej. Używa adresu i współrzędnych z pól powyżej.</div></details>
                    <div class="evo-field"><label>Nazwa atrakcji</label><input type="text" name="evk_schema[attraction_name]" value="<?php echo esc_attr($sc['attraction_name']); ?>" placeholder="np. Stanica Wodna PTTK Ukta nad rzeką Krutynią" class="evo-w-480"><div class="evo-desc">Puste pole = nazwa organizacji.</div></div>

                </div>

                <div class="evo-box">
                    <h3>Dodatkowe obiekty i usługi (encje podrzędne)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Każda pozycja to osobny węzeł w grafie (np. pole namiotowe, wypożyczalnia kajaków, restauracja, plaża). Gdy ustawiony jest typ działalności inny niż „Organizacja", encje są powiązane z obiektem (#place) przez <code>containedInPlace</code>. To poziom danych spotykany w portalach turystycznych — dokładniej opisuje ofertę niż jeden typ.</div></details>
                    <div id="evk-sub-list">
                        <?php foreach ($subs as $row) { echo $render_sub_row((array) $row); } ?>
                    </div>
                    <template id="evk-sub-tpl"><?php echo $render_sub_row([]); ?></template>
                    <button type="button" class="button evo-mt-xs" id="evk-sub-add"><span class="dashicons dashicons-plus-alt2 evo-ico-sm evo-ico-lead"></span> Dodaj obiekt</button>
                    <script>
                    (function(){
                        var list = document.getElementById('evk-sub-list');
                        var tpl  = document.getElementById('evk-sub-tpl');
                        var add  = document.getElementById('evk-sub-add');
                        if (!list || !tpl || !add) return;
                        add.addEventListener('click', function(){
                            list.appendChild(tpl.content.cloneNode(true));
                        });
                        list.addEventListener('click', function(e){
                            var btn = e.target.closest('.evk-sub-remove');
                            if (btn) btn.closest('.evk-sub-row').remove();
                        });
                    })();
                    </script>

                </div>

                <div class="evo-box">
                    <h3>Opis organizacji per język</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Języki pobierane z modułu Tłumaczenia. Opis PL jest domyślnym fallbackiem.</div></details>
                    <div class="evo-field"><label>Polski (pl) — domyślny</label><textarea name="evk_schema_desc[pl]" rows="3" class="evo-w-full"><?php echo esc_textarea($descs['pl'] ?? ''); ?></textarea></div>
                    <?php foreach ($langs as $code => $lang_data): ?>
                    <div class="evo-field">
                        <label><?php echo esc_html($lang_data['name']); ?> (<?php echo esc_html($code); ?>)</label>
                        <textarea name="evk_schema_desc[<?php echo esc_attr($code); ?>]" rows="3" class="evo-w-full"><?php echo esc_textarea($descs[$code] ?? ''); ?></textarea>
                    </div>
                    <?php endforeach; ?>

                </div>

                <div class="evo-box">
                    <h3>Linki społecznościowe (sameAs)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Jeden URL na linię. Jeśli pole jest puste, moduł automatycznie przeszuka menu nawigacyjne.</div></details>
                    <div class="evo-field"><label>URLs (jeden na linię)</label><textarea name="evk_schema_socials" rows="4" class="evo-mono" style="max-width:480px"><?php echo esc_textarea(implode("\n", $socials)); ?></textarea></div>

                </div>

                <div class="evo-box">
                    <h3>Waluty per język (WooCommerce)</h3>
                    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Dla produktów WooCommerce — przypisz walutę do wersji językowej.</div></details>
                    <?php foreach ($langs as $code => $lang_data): ?>
                    <div class="evo-field evk-schema-curr-row">
                        <label class="evk-schema-curr-lang"><?php echo esc_html($lang_data['name']); ?> (<?php echo esc_html($code); ?>)</label>
                        <input type="text" name="evk_schema_curr[<?php echo esc_attr($code); ?>]" value="<?php echo esc_attr($currs[$code] ?? ''); ?>" placeholder="EUR" class="evo-w-80">
                    </div>
                    <?php endforeach; ?>

                </div>

                <div class="evo-box">
                    <h3>Aktywne bloki JSON-LD</h3>
                    <div class="evo-grid evo-mb-lg" style="--evo-col:220px;--evo-gap:10px">
                        <?php
                        $blocks = [
                            'block_website'    => 'WebSite',
                            'block_org'        => 'Organization',
                            'block_breadcrumb' => 'BreadcrumbList',
                            'block_webpage'    => 'WebPage',
                            'block_article'    => 'BlogPosting (wpisy)',
                            'block_faq'        => 'FAQPage (Bricks accordion)',
                            'block_product'    => 'Product (WooCommerce)',
                            'block_attraction' => 'TouristAttraction',
                        ];
                        foreach ($blocks as $key => $label): ?>
                        <label class="evo-choice">
                            <input type="checkbox" name="evk_schema[<?php echo $key; ?>]" value="1" <?php checked(!empty($sc[$key])); ?>>
                            <?php echo esc_html($label); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>

                
                </div>

<div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia Schema', 'primary', 'submit', false); ?>
                </div>
            </form>
