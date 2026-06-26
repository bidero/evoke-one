<?php
if (!defined('ABSPATH')) exit;
// Evoke ONE — TL tab content. Zmienne z tl_render_page(): $data $langs $codes $tab $base $nonce $ajax_url $stats
?>
<?php
            $sitemap_posts = get_posts([
                'post_type'      => ['page', 'post'],
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ]);
            $excluded_ids = array_map('absint', (array) ($sitemap_settings['excluded_ids'] ?? []));
            ?>
            <div class="tl-info-box">
                <strong>Mapa strony WordPress:</strong> Te ustawienia dodają tłumaczone adresy ze slugami do natywnej mapy <code>wp-sitemap.xml</code> jako osobną sekcję tłumaczeń.
            </div>

            <div class="tl-menu-settings">
                <h3>Zawartość mapy strony</h3>
                <p style="margin-bottom:14px;color:#475569;">Wybierz adresy, które wtyczka ma dopisać do <code>wp-sitemap.xml</code>.</p>
                <label style="display:block;margin:10px 0;">
                    <input type="checkbox" id="tl-sm-enabled" <?php checked(!empty($sitemap_settings['enabled'])); ?>>
                    Włącz sekcję tłumaczeń w <code>wp-sitemap.xml</code>
                </label>
                <label style="display:block;margin:10px 0;">
                    <input type="checkbox" id="tl-sm-home" <?php checked(!empty($sitemap_settings['include_home'])); ?>>
                    Strona główna w wersjach językowych
                </label>
                <label style="display:block;margin:10px 0;">
                    <input type="checkbox" id="tl-sm-pages" <?php checked(!empty($sitemap_settings['include_pages'])); ?>>
                    Strony
                </label>
                <label style="display:block;margin:10px 0;">
                    <input type="checkbox" id="tl-sm-posts" <?php checked(!empty($sitemap_settings['include_posts'])); ?>>
                    Wpisy
                </label>
                <label style="display:block;margin:10px 0;">
                    <input type="checkbox" id="tl-sm-polish" <?php checked(!empty($sitemap_settings['include_polish'])); ?>>
                    Dodaj też polskie adresy do sekcji tłumaczeń
                </label>
                <label style="display:block;margin:10px 0;">
                    <input type="checkbox" id="tl-sm-only-translated" <?php checked(!empty($sitemap_settings['only_translated_slugs'])); ?>>
                    Pomijaj podstrony bez przetłumaczonego sluga
                </label>
                <label style="display:block;margin:10px 0;">
                    <input type="checkbox" id="tl-sm-auto-noindex" <?php checked(!empty($sitemap_settings['auto_exclude_noindex'])); ?>>
                    Automatycznie pomijaj strony i wpisy z meta <code>noindex</code>
                </label>
            </div>

            <div class="tl-menu-settings" style="max-width:900px;">
                <h3>Wykluczone strony i wpisy</h3>
                <p style="margin-bottom:14px;color:#475569;">Zaznaczone pozycje nie trafią do mapy tłumaczeń ani do standardowych sekcji postów WordPressa.</p>
                <div style="max-height:360px;overflow:auto;background:#fff;border:1px solid #d1d5db;border-radius:4px;padding:8px 12px;">
                    <?php foreach ($sitemap_posts as $sm_post): ?>
                    <label style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f0f0f1;">
                        <input type="checkbox" class="tl-sm-excluded-id" value="<?php echo esc_attr($sm_post->ID); ?>" <?php checked(in_array((int) $sm_post->ID, $excluded_ids, true)); ?>>
                        <span style="min-width:48px;color:#64748b;font-size:11px;text-transform:uppercase;"><?php echo esc_html($sm_post->post_type); ?></span>
                        <strong style="flex:1;"><?php echo esc_html(get_the_title($sm_post) ?: '(bez tytułu)'); ?></strong>
                        <code style="color:#475569;"><?php echo esc_html($sm_post->post_name); ?></code>
                        <span style="color:#94a3b8;">#<?php echo esc_html($sm_post->ID); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <p style="color:#475569;margin:0 0 16px;">
                Po zapisie sprawdź: <a href="<?php echo esc_url(home_url('/wp-sitemap.xml')); ?>" target="_blank" rel="noopener">wp-sitemap.xml</a>
            </p>

            <div class="tl-save-bar">
                <button type="button" class="button button-primary" onclick="tlSaveSitemapSettings()"><span class="dashicons dashicons-saved"></span> Zapisz mapę strony</button>
                <span class="tl-save-status" id="save-status-sitemap"></span>
            </div>

            <script>
            (function($){
                window.tlSaveSitemapSettings = function() {
                    const $st = $('#save-status-sitemap');
                    $st.removeClass('ok err').hide();
                    const payload = {
                        enabled: $('#tl-sm-enabled').is(':checked') ? 1 : 0,
                        include_home: $('#tl-sm-home').is(':checked') ? 1 : 0,
                        include_pages: $('#tl-sm-pages').is(':checked') ? 1 : 0,
                        include_posts: $('#tl-sm-posts').is(':checked') ? 1 : 0,
                        include_polish: $('#tl-sm-polish').is(':checked') ? 1 : 0,
                        only_translated_slugs: $('#tl-sm-only-translated').is(':checked') ? 1 : 0,
                        auto_exclude_noindex: $('#tl-sm-auto-noindex').is(':checked') ? 1 : 0,
                        excluded_ids: $('.tl-sm-excluded-id:checked').map(function(){ return parseInt(this.value, 10); }).get()
                    };
                    $.post(ajaxurl, {
                        action: 'tl_save_sitemap_settings',
                        nonce: '<?php echo esc_js($nonce); ?>',
                        payload: JSON.stringify(payload)
                    }).done(function(r) {
                        if (r.success) {
                            _dirty = false;
                            $st.addClass('ok').text('Zapisano').show();
                        } else {
                            $st.addClass('err').text(r.data || 'Błąd').show();
                        }
                    }).fail(function() {
                        $st.addClass('err').text('Błąd połączenia').show();
                    });
                };
            })(jQuery);
            </script>
