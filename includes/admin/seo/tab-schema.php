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
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:20px;">
                        <div class="evo-field" style="margin-bottom:0">
                            <label>Typ działalności (@type)</label>
                            <select name="evk_schema[org_type]">
                                <?php foreach (EVK_Schema::org_types() as $type_key => $type_label): ?>
                                <option value="<?php echo esc_attr($type_key); ?>" <?php selected($sc['org_type'], $type_key); ?>><?php echo esc_html($type_label); ?> — <?php echo esc_html($type_key); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="evo-desc">Typ inny niż „Organizacja" tworzy w grafie osobny węzeł miejsca (#place) z polami firmy lokalnej poniżej; #organization pozostaje czystym wydawcą strony.</div>
                        </div>
                        <div class="evo-field" style="margin-bottom:0"><label>Nazwa obiektu / firmy (site_name)</label><input type="text" name="evk_schema[site_name]" value="<?php echo esc_attr($sc['site_name']); ?>" placeholder="np. Stanica Wodna PTTK Ukta"></div>
                        <div class="evo-field" style="margin-bottom:0"><label>Nazwa operatora (Organization)</label><input type="text" name="evk_schema[operator_name]" value="<?php echo esc_attr($sc['operator_name']); ?>" placeholder="np. PTTK Oddział Mazurski"><div class="evo-desc">Wydawca strony / właściciel obiektu. Puste = nazwa obiektu.</div></div>
                        <div class="evo-field" style="margin-bottom:0"><label>Telefon</label><input type="text" name="evk_schema[telephone]" value="<?php echo esc_attr($sc['telephone']); ?>" placeholder="+48 000 000 000"></div>
                        <div class="evo-field" style="margin-bottom:0"><label>E-mail</label><input type="text" name="evk_schema[email]" value="<?php echo esc_attr($sc['email']); ?>" placeholder="biuro@domena.pl"></div>
                        <div class="evo-field" style="margin-bottom:0"><label>Ulica i numer</label><input type="text" name="evk_schema[street_address]" value="<?php echo esc_attr($sc['street_address']); ?>" placeholder="ul. Przykładowa 1"></div>
                        <div class="evo-field" style="margin-bottom:0"><label>Miejscowość</label><input type="text" name="evk_schema[locality]" value="<?php echo esc_attr($sc['locality']); ?>" placeholder="Warszawa"></div>
                        <div class="evo-field" style="margin-bottom:0"><label>Kod pocztowy</label><input type="text" name="evk_schema[postal_code]" value="<?php echo esc_attr($sc['postal_code']); ?>" placeholder="00-000"></div>
                        <div class="evo-field" style="margin-bottom:0"><label>Kod kraju (ISO)</label><input type="text" name="evk_schema[country]" value="<?php echo esc_attr($sc['country']); ?>" placeholder="PL" style="max-width:100%;"></div>
                        <div class="evo-field" style="margin-bottom:0"><label>Typ kontaktu (contactType)</label><input type="text" name="evk_schema[contact_type]" value="<?php echo esc_attr($sc['contact_type']); ?>" placeholder="booking"></div>
                        <div class="evo-field" style="margin-bottom:0"><label>URL logo / faviconu</label><input type="text" name="evk_schema[favicon_url]" value="<?php echo esc_attr($sc['favicon_url']); ?>" placeholder="/wp-content/uploads/logo.png"><div class="evo-desc">Ścieżka relatywna lub pełny URL.</div></div>
                    </div>

                </div>

                <div class="evo-box">
                    <h3>Miejsce / firma lokalna (węzeł #place)</h3>
                    <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>Pola używane tylko, gdy typ działalności jest inny niż „Organizacja" (LocalBusiness i pochodne — np. obiekt noclegowy, restauracja). Trafiają do osobnego węzła #place powiązanego z #organization przez parentOrganization. Współrzędne znajdziesz np. w Mapach Google (PPM na pinezce).</div></div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:20px;">
                        <div class="evo-field" style="margin-bottom:0"><label>Szerokość geograficzna (latitude)</label><input type="text" name="evk_schema[geo_lat]" value="<?php echo esc_attr($sc['geo_lat']); ?>" placeholder="53.12345"></div>
                        <div class="evo-field" style="margin-bottom:0"><label>Długość geograficzna (longitude)</label><input type="text" name="evk_schema[geo_lng]" value="<?php echo esc_attr($sc['geo_lng']); ?>" placeholder="21.12345"></div>
                        <div class="evo-field" style="margin-bottom:0"><label>Przedział cenowy (priceRange)</label><input type="text" name="evk_schema[price_range]" value="<?php echo esc_attr($sc['price_range']); ?>" placeholder="$$"><div class="evo-desc">Umownie: $ tanio … $$$$ drogo (albo np. „50–200 zł").</div></div>
                        <div class="evo-field" style="margin-bottom:0"><label>Link do mapy (hasMap)</label><input type="text" name="evk_schema[has_map]" value="<?php echo esc_attr($sc['has_map']); ?>" placeholder="https://maps.google.com/…"><div class="evo-desc">Np. link „Udostępnij" z Map Google.</div></div>
                    </div>
                    <div class="evo-field"><label>Udogodnienia (amenityFeature) — jedno na linię</label><textarea name="evk_schema[amenities]" rows="4" style="max-width:480px;" placeholder="Spływy kajakowe&#10;Pole namiotowe&#10;Sauna"><?php echo esc_textarea($sc['amenities']); ?></textarea></div>
                    <div class="evo-field"><label>Godziny otwarcia (openingHoursSpecification) — jedna reguła na linię</label><textarea name="evk_schema[opening_hours]" rows="3" style="max-width:480px;font-family:monospace;" placeholder="Pn-Pt 08:00-20:00&#10;Sob-Nd 09:00-18:00"><?php echo esc_textarea($sc['opening_hours']); ?></textarea><div class="evo-desc">Format: dni + godziny, np. „Pn-Pt 08:00-20:00", „Sob 09:00-14:00", „Codziennie 08:00-20:00". Dni: Pn, Wt, Śr, Cz, Pt, Sob, Nd (można łączyć przecinkiem i zakresem).</div></div>
                    <div class="evo-field"><label>Obsługiwany obszar (areaServed) — jeden na linię</label><textarea name="evk_schema[area_served]" rows="3" style="max-width:480px;" placeholder="Mazury&#10;Puszcza Piska&#10;Krutynia"><?php echo esc_textarea($sc['area_served']); ?></textarea></div>

                </div>

                <div class="evo-box">
                    <h3>Atrakcja turystyczna (TouristAttraction)</h3>
                    <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>Osobny obiekt w grafie — włącz go w „Aktywne bloki JSON-LD" poniżej. Używa adresu i współrzędnych z pól powyżej.</div></div>
                    <div class="evo-field"><label>Nazwa atrakcji</label><input type="text" name="evk_schema[attraction_name]" value="<?php echo esc_attr($sc['attraction_name']); ?>" placeholder="np. Stanica Wodna PTTK Ukta nad rzeką Krutynią" style="max-width:480px;"><div class="evo-desc">Puste pole = nazwa organizacji.</div></div>

                </div>

                <div class="evo-box">
                    <h3>Dodatkowe obiekty i usługi (encje podrzędne)</h3>
                    <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>Każda pozycja to osobny węzeł w grafie (np. pole namiotowe, wypożyczalnia kajaków, restauracja, plaża). Gdy ustawiony jest typ działalności inny niż „Organizacja", encje są powiązane z obiektem (#place) przez <code>containedInPlace</code>. To poziom danych spotykany w portalach turystycznych — dokładniej opisuje ofertę niż jeden typ.</div></div>
                    <style>
                        .evk-sub-row{display:grid;grid-template-columns:230px 1fr 1fr 34px;gap:8px;align-items:center;margin-bottom:8px;}
                        .evk-sub-row select,.evk-sub-row input{margin:0;}
                        .evk-sub-remove{display:inline-flex!important;align-items:center;justify-content:center;padding:0!important;width:34px;height:32px;}
                        .evk-sub-remove .dashicons{font-size:16px;width:16px;height:16px;line-height:1;color:#dc2626;}
                        @media(max-width:782px){.evk-sub-row{grid-template-columns:1fr;}}
                    </style>
                    <div id="evk-sub-list">
                        <?php foreach ($subs as $row) { echo $render_sub_row((array) $row); } ?>
                    </div>
                    <template id="evk-sub-tpl"><?php echo $render_sub_row([]); ?></template>
                    <button type="button" class="button" id="evk-sub-add" style="margin-top:6px;"><span class="dashicons dashicons-plus-alt2" style="font-size:15px;width:15px;height:15px;line-height:1;vertical-align:text-bottom;"></span> Dodaj obiekt</button>
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
                    <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>Języki pobierane z modułu Tłumaczenia. Opis PL jest domyślnym fallbackiem.</div></div>
                    <div class="evo-field"><label>Polski (pl) — domyślny</label><textarea name="evk_schema_desc[pl]" rows="3" style="max-width:100%;"><?php echo esc_textarea($descs['pl'] ?? ''); ?></textarea></div>
                    <?php foreach ($langs as $code => $lang_data): ?>
                    <div class="evo-field">
                        <label><?php echo esc_html($lang_data['name']); ?> (<?php echo esc_html($code); ?>)</label>
                        <textarea name="evk_schema_desc[<?php echo esc_attr($code); ?>]" rows="3" style="max-width:100%;"><?php echo esc_textarea($descs[$code] ?? ''); ?></textarea>
                    </div>
                    <?php endforeach; ?>

                </div>

                <div class="evo-box">
                    <h3>Linki społecznościowe (sameAs)</h3>
                    <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>Jeden URL na linię. Jeśli pole jest puste, moduł automatycznie przeszuka menu nawigacyjne.</div></div>
                    <div class="evo-field"><label>URLs (jeden na linię)</label><textarea name="evk_schema_socials" rows="4" style="max-width:480px;font-family:monospace;"><?php echo esc_textarea(implode("\n", $socials)); ?></textarea></div>

                </div>

                <div class="evo-box">
                    <h3>Waluty per język (WooCommerce)</h3>
                    <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>Dla produktów WooCommerce — przypisz walutę do wersji językowej.</div></div>
                    <?php foreach ($langs as $code => $lang_data): ?>
                    <div class="evo-field" style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                        <label style="min-width:140px;margin:0;"><?php echo esc_html($lang_data['name']); ?> (<?php echo esc_html($code); ?>)</label>
                        <input type="text" name="evk_schema_curr[<?php echo esc_attr($code); ?>]" value="<?php echo esc_attr($currs[$code] ?? ''); ?>" placeholder="EUR" style="max-width:80px;">
                    </div>
                    <?php endforeach; ?>

                </div>

                <div class="evo-box">
                    <h3>Aktywne bloki JSON-LD</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px;margin-bottom:20px;">
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
                        <label style="display:flex;align-items:center;gap:9px;background:#f8fafc;border:1px solid #d7dde7;border-radius:8px;padding:10px 14px;font-size:13px;font-weight:500;cursor:pointer;">
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
