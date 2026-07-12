<?php
if (!defined('ABSPATH')) exit;
?>
            <?php
            $sc      = EVK_Schema::get_instance()->get_settings();
            $langs   = function_exists('tl_get_languages') ? tl_get_languages() : [];
            $descs   = json_decode($sc['descriptions'],    true) ?: [];
            $socials = json_decode($sc['social_links'],    true) ?: [];
            $currs   = json_decode($sc['lang_currencies'], true) ?: [];
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

                <p class="evo-section-title">Dane organizacji</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-bottom:20px;">
                    <div class="evo-field" style="margin-bottom:0"><label>Nazwa (site_name)</label><input type="text" name="evk_schema[site_name]" value="<?php echo esc_attr($sc['site_name']); ?>" placeholder="np. Stanica Wodna PTTK Ukta"></div>
                    <div class="evo-field" style="margin-bottom:0"><label>Telefon</label><input type="text" name="evk_schema[telephone]" value="<?php echo esc_attr($sc['telephone']); ?>" placeholder="+48 000 000 000"></div>
                    <div class="evo-field" style="margin-bottom:0"><label>E-mail</label><input type="text" name="evk_schema[email]" value="<?php echo esc_attr($sc['email']); ?>" placeholder="biuro@domena.pl"></div>
                    <div class="evo-field" style="margin-bottom:0"><label>Ulica i numer</label><input type="text" name="evk_schema[street_address]" value="<?php echo esc_attr($sc['street_address']); ?>" placeholder="ul. Przykładowa 1"></div>
                    <div class="evo-field" style="margin-bottom:0"><label>Miejscowość</label><input type="text" name="evk_schema[locality]" value="<?php echo esc_attr($sc['locality']); ?>" placeholder="Warszawa"></div>
                    <div class="evo-field" style="margin-bottom:0"><label>Kod pocztowy</label><input type="text" name="evk_schema[postal_code]" value="<?php echo esc_attr($sc['postal_code']); ?>" placeholder="00-000"></div>
                    <div class="evo-field" style="margin-bottom:0"><label>Kod kraju (ISO)</label><input type="text" name="evk_schema[country]" value="<?php echo esc_attr($sc['country']); ?>" placeholder="PL" style="max-width:100%;"></div>
                    <div class="evo-field" style="margin-bottom:0"><label>Typ kontaktu (contactType)</label><input type="text" name="evk_schema[contact_type]" value="<?php echo esc_attr($sc['contact_type']); ?>" placeholder="booking"></div>
                    <div class="evo-field" style="margin-bottom:0"><label>URL logo / faviconu</label><input type="text" name="evk_schema[favicon_url]" value="<?php echo esc_attr($sc['favicon_url']); ?>" placeholder="/wp-content/uploads/logo.png"><div class="evo-desc">Ścieżka relatywna lub pełny URL.</div></div>
                </div>

                <hr class="evo-divider">
                <p class="evo-section-title">Opis organizacji per język</p>
                <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>Języki pobierane z modułu Tłumaczenia. Opis PL jest domyślnym fallbackiem.</div></div>
                <div class="evo-field"><label>Polski (pl) — domyślny</label><textarea name="evk_schema_desc[pl]" rows="3" style="max-width:100%;"><?php echo esc_textarea($descs['pl'] ?? ''); ?></textarea></div>
                <?php foreach ($langs as $code => $lang_data): ?>
                <div class="evo-field">
                    <label><?php echo esc_html($lang_data['name']); ?> (<?php echo esc_html($code); ?>)</label>
                    <textarea name="evk_schema_desc[<?php echo esc_attr($code); ?>]" rows="3" style="max-width:100%;"><?php echo esc_textarea($descs[$code] ?? ''); ?></textarea>
                </div>
                <?php endforeach; ?>

                <hr class="evo-divider">
                <p class="evo-section-title">Linki społecznościowe (sameAs)</p>
                <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>Jeden URL na linię. Jeśli pole jest puste, moduł automatycznie przeszuka menu nawigacyjne.</div></div>
                <div class="evo-field"><label>URLs (jeden na linię)</label><textarea name="evk_schema_socials" rows="4" style="max-width:480px;font-family:monospace;"><?php echo esc_textarea(implode("\n", $socials)); ?></textarea></div>

                <hr class="evo-divider">
                <p class="evo-section-title">Waluty per język (WooCommerce)</p>
                <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>Dla produktów WooCommerce — przypisz walutę do wersji językowej.</div></div>
                <?php foreach ($langs as $code => $lang_data): ?>
                <div class="evo-field" style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                    <label style="min-width:140px;margin:0;"><?php echo esc_html($lang_data['name']); ?> (<?php echo esc_html($code); ?>)</label>
                    <input type="text" name="evk_schema_curr[<?php echo esc_attr($code); ?>]" value="<?php echo esc_attr($currs[$code] ?? ''); ?>" placeholder="EUR" style="max-width:80px;">
                </div>
                <?php endforeach; ?>

                <hr class="evo-divider">
                <p class="evo-section-title">Aktywne bloki JSON-LD</p>
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
                    ];
                    foreach ($blocks as $key => $label): ?>
                    <label style="display:flex;align-items:center;gap:9px;background:#f8fafc;border:1px solid #d7dde7;border-radius:8px;padding:10px 14px;font-size:13px;font-weight:500;cursor:pointer;">
                        <input type="checkbox" name="evk_schema[<?php echo $key; ?>]" value="1" <?php checked(!empty($sc[$key])); ?>>
                        <?php echo esc_html($label); ?>
                    </label>
                    <?php endforeach; ?>
                </div>

                <div class="evo-save-bar">
                    <?php submit_button('Zapisz ustawienia Schema', 'primary', 'submit', false); ?>
                </div>
            </form>
