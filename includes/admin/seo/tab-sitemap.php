<?php
if (!defined('ABSPATH')) exit;
?>
                <?php
                $sitemap_settings = tl_get_sitemap_settings();
                $sitemap_posts = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
                $excluded_ids  = array_map('absint', (array)($sitemap_settings['excluded_ids'] ?? []));
                ?>


            <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Te ustawienia dodają tłumaczone adresy do <code>wp-sitemap.xml</code> jako osobną sekcję oraz generują <code>/sitemap.xml</code> z tagami <code>hreflang</code>.</div></details>

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
                        <span class="evo-col-label"><?php echo esc_html($sm_post->post_type); ?></span>
                        <strong class="evo-grow"><?php echo esc_html(get_the_title($sm_post) ?: '(bez tytułu)'); ?></strong>
                        <code class="evo-muted"><?php echo esc_html($sm_post->post_name); ?></code>
                        <span class="evo-faint">#<?php echo esc_html($sm_post->ID); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <p class="evo-lead">
                Sprawdź: <a href="<?php echo esc_url(home_url('/wp-sitemap.xml')); ?>" target="_blank">wp-sitemap.xml</a>
            </p>

            <div class="evo-box">
                <h3>Diagnostyka noindex</h3>
                <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Sprawdza które strony mają wykryte meta noindex i przez jakie pole.</div></details>
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
                    <p class="evk-nl-13 evo-muted">Żadna strona nie została wykryta jako noindex.</p>
                <?php else: ?>
                    <div class="evo-list-box">
                        <?php foreach ($noindex_found as $pid => $keys): ?>
                        <div class="evo-list-row">
                            <strong class="evo-list-key"><a href="<?php echo esc_url(get_edit_post_link($pid)); ?>" target="_blank"><?php echo esc_html(get_the_title($pid)); ?></a> <span class="evo-faint">#<?php echo $pid; ?></span></strong>
                            <div class="evo-mono-xs"><?php echo esc_html(implode(', ', $keys)); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="evo-save-bar">
                    <button type="button" class="button button-primary" onclick="evoSaveSitemap()">Zapisz mapę strony</button>
                    <span id="save-status-sitemap" class="evo-save-msg"></span>
                </div>
            </div>

