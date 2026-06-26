<?php
if (!defined('ABSPATH')) exit;
?>
                <?php
                $sitemap_settings = tl_get_sitemap_settings();
                $sitemap_posts = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
                $excluded_ids  = array_map('absint', (array)($sitemap_settings['excluded_ids'] ?? []));
                ?>


            <div class="evo-info-box">
                <span class="dashicons dashicons-info"></span>
                <div>Te ustawienia dodają tłumaczone adresy do <code>wp-sitemap.xml</code> jako osobną sekcję oraz generują <code>/sitemap.xml</code> z tagami <code>hreflang</code>.</div>
            </div>

            <div class="evo-sm-box">
                <h3>Zawartość mapy strony</h3>
                <label><input type="checkbox" id="tl-sm-enabled"         <?php checked(!empty($sitemap_settings['enabled'])); ?>> Włącz sekcję tłumaczeń w <code>wp-sitemap.xml</code></label>
                <label><input type="checkbox" id="tl-sm-home"            <?php checked(!empty($sitemap_settings['include_home'])); ?>> Strona główna w wersjach językowych</label>
                <label><input type="checkbox" id="tl-sm-pages"           <?php checked(!empty($sitemap_settings['include_pages'])); ?>> Strony</label>
                <label><input type="checkbox" id="tl-sm-posts"           <?php checked(!empty($sitemap_settings['include_posts'])); ?>> Wpisy</label>
                <label><input type="checkbox" id="tl-sm-polish"          <?php checked(!empty($sitemap_settings['include_polish'])); ?>> Dodaj też polskie adresy do sekcji tłumaczeń</label>
                <label><input type="checkbox" id="tl-sm-only-translated" <?php checked(!empty($sitemap_settings['only_translated_slugs'])); ?>> Pomijaj podstrony bez przetłumaczonego sluga</label>
                <label><input type="checkbox" id="tl-sm-auto-noindex"    <?php checked(!empty($sitemap_settings['auto_exclude_noindex'])); ?>> Automatycznie pomijaj strony z meta <code>noindex</code></label>
                <label><input type="checkbox" id="tl-sm-users"           <?php checked(!empty($sitemap_settings['include_users'])); ?>> Użytkownicy (wp-sitemap-users-1.xml)</label>
            </div>

            <div class="evo-sm-box">
                <h3>Wykluczone strony i wpisy</h3>
                <div class="evo-sm-excluded-list">
                    <?php foreach ($sitemap_posts as $sm_post): ?>
                    <label>
                        <input type="checkbox" class="tl-sm-excluded-id" value="<?php echo esc_attr($sm_post->ID); ?>" <?php checked(in_array((int) $sm_post->ID, $excluded_ids, true)); ?>>
                        <span style="min-width:42px;color:#64748b;font-size:11px;text-transform:uppercase;"><?php echo esc_html($sm_post->post_type); ?></span>
                        <strong style="flex:1;"><?php echo esc_html(get_the_title($sm_post) ?: '(bez tytułu)'); ?></strong>
                        <code style="color:#475569;"><?php echo esc_html($sm_post->post_name); ?></code>
                        <span style="color:#94a3b8;">#<?php echo esc_html($sm_post->ID); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <p style="color:#475569;margin-bottom:16px;font-size:13px;">
                Sprawdź: <a href="<?php echo esc_url(home_url('/wp-sitemap.xml')); ?>" target="_blank">wp-sitemap.xml</a>
            </p>

            <hr class="evo-divider">
            <p class="evo-section-title">Diagnostyka noindex</p>
            <div class="evo-info-box">
                <span class="dashicons dashicons-info"></span>
                <div>Sprawdza które strony mają wykryte meta noindex i przez jakie pole.</div>
            </div>
            <?php
            $diag_posts    = get_posts(['post_type' => ['page', 'post'], 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
            $noindex_found = [];
            foreach ($diag_posts as $pid) {
                foreach (get_post_meta($pid) as $meta_key => $values) {
                    foreach ((array) $values as $v) {
                        if (tl_meta_value_means_noindex($v, $meta_key)) {
                            $noindex_found[$pid][] = $meta_key . ' = ' . wp_trim_words((string) $v, 6);
                            break;
                        }
                    }
                }
            }
            ?>
            <?php if (empty($noindex_found)): ?>
                <p style="font-size:13px;color:#6b7280;">Żadna strona nie została wykryta jako noindex.</p>
            <?php else: ?>
                <div style="background:#fff;border:1px solid #d1d5db;border-radius:6px;overflow:hidden;">
                    <?php foreach ($noindex_found as $pid => $keys): ?>
                    <div style="display:flex;align-items:flex-start;gap:12px;padding:10px 14px;border-bottom:1px solid #f0f0f1;font-size:13px;">
                        <strong style="min-width:180px;"><a href="<?php echo esc_url(get_edit_post_link($pid)); ?>" target="_blank"><?php echo esc_html(get_the_title($pid)); ?></a> <span style="color:#94a3b8;">#<?php echo $pid; ?></span></strong>
                        <div style="color:#6b7280;font-family:monospace;font-size:11px;line-height:1.7;"><?php echo esc_html(implode(', ', $keys)); ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="evo-save-bar">
                <button type="button" class="button button-primary" onclick="evoSaveSitemap()">Zapisz mapę strony</button>
                <span id="save-status-sitemap" style="font-size:13px;color:#047857;display:none;"></span>
            </div>
