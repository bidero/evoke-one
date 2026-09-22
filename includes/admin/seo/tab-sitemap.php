<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — SEO → Mapa strony.
 *
 * Ekran steruje natywną mapą `wp-sitemap.xml`: typami treści (bez względu na
 * to, która wtyczka je zarejestrowała), taksonomiami, użytkownikami, kotwicami
 * i pojedynczymi wykluczeniami. Sekcja tłumaczeń pokazuje się wyłącznie przy
 * włączonym module języków — bez niego nie ma czego tłumaczyć.
 */
?>
                <?php
                $sitemap_settings = tl_get_sitemap_settings();
                $sitemap_posts = get_posts(['post_type'=>['page','post'],'post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
                $excluded_ids  = array_map('absint', (array)($sitemap_settings['excluded_ids'] ?? []));

                /* Typy treści: wszystkie publiczne plus te, które ktoś już
                   oznaczył — typ ustawiony jako prywatny nie może zniknąć
                   z ekranu razem ze swoim ustawieniem, bo wtedy nie dałoby się
                   go odznaczyć. Załączniki pomijamy: mapą mediów nikt tu nie
                   steruje. */
                $sm_typy = [];
                foreach (get_post_types(['public' => true], 'objects') as $sm_typ) {
                    if ($sm_typ->name === 'attachment') continue;
                    $sm_typy[$sm_typ->name] = $sm_typ;
                }
                foreach (array_merge(
                    (array) ($sitemap_settings['excluded_types'] ?? []),
                    (array) ($sitemap_settings['noindex_types'] ?? []),
                    array_keys((array) ($sitemap_settings['anchor_types'] ?? []))
                ) as $sm_slug) {
                    $sm_obj = get_post_type_object((string) $sm_slug);
                    if ($sm_obj && !isset($sm_typy[$sm_obj->name])) $sm_typy[$sm_obj->name] = $sm_obj;
                }

                $sm_taks = [];
                foreach (get_taxonomies(['public' => true], 'objects') as $sm_tax) {
                    $sm_taks[$sm_tax->name] = $sm_tax;
                }

                $sm_wykl_typy  = array_map('sanitize_key', (array) ($sitemap_settings['excluded_types'] ?? []));
                $sm_noidx_typy = array_map('sanitize_key', (array) ($sitemap_settings['noindex_types'] ?? []));
                $sm_wykl_taks  = array_map('sanitize_key', (array) ($sitemap_settings['excluded_taxonomies'] ?? []));
                $sm_noidx_taks = array_map('sanitize_key', (array) ($sitemap_settings['noindex_taxonomies'] ?? []));
                $sm_kotwice    = (array) ($sitemap_settings['anchor_types'] ?? []);

                /* Flagi ustawione w Evoke FIELDS. Pokazujemy je jako zaznaczone
                   i zablokowane — odznaczenie tutaj wyglądałoby na skuteczne,
                   a wracałoby przy pierwszym zapisie tamtej wtyczki. */
                $sm_fields_typy = function_exists('evk_noindex_post_types') ? array_map('sanitize_key', (array) evk_noindex_post_types()) : [];
                $sm_fields_taks = function_exists('evk_noindex_taxonomies') ? array_map('sanitize_key', (array) evk_noindex_taxonomies()) : [];

                $sm_strony = get_posts(['post_type'=>'page','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
                $sm_tl_on  = !empty(get_option('evk_tl_module_enabled', 0));
                ?>


            <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">
                Ustawienia sterują natywną mapą <code>wp-sitemap.xml</code> — tą, którą WordPress wystawia sam
                i którą wskazuje <code>robots.txt</code>. Wykluczenie z mapy mówi wyszukiwarce „nie zgłaszam tego",
                ale nie wyprowadza z indeksu tego, co już tam jest; do tego służy <strong>Poza indeksem</strong>,
                które dokłada <code>noindex</code> na froncie i chowa typ przed wyszukiwarką WordPressa.
                Wersje językowe stron opisują tagi <code>hreflang</code> w <code>&lt;head&gt;</code> każdej podstrony —
                do mapy nie wchodzą, bo renderer WordPressa przyjmuje w niej wyłącznie <code>loc</code>,
                <code>lastmod</code>, <code>changefreq</code> i <code>priority</code>.
            </div></details>

            <div class="evo-sm-box">
                <h3>Typy treści</h3>
                <p class="evo-lead">Każdy zarejestrowany typ — także z Evoke FIELDS, ACF czy Metabox.</p>
                <div class="evo-list-box" id="tl-sm-types">
                    <?php foreach ($sm_typy as $sm_slug => $sm_obj):
                        $sm_z_fields = in_array($sm_slug, $sm_fields_typy, true);
                        $sm_noindex  = $sm_z_fields || in_array($sm_slug, $sm_noidx_typy, true);
                        $sm_w_mapie  = !$sm_noindex && !in_array($sm_slug, $sm_wykl_typy, true);
                    ?>
                    <div class="evo-list-row evk-sm-type" data-slug="<?php echo esc_attr($sm_slug); ?>">
                        <strong class="evo-list-key">
                            <?php echo esc_html($sm_obj->labels->name ?? $sm_slug); ?>
                            <code class="evo-muted"><?php echo esc_html($sm_slug); ?></code>
                            <?php if ($sm_z_fields): ?><span class="evo-faint">— z Evoke FIELDS</span><?php endif; ?>
                        </strong>
                        <label>
                            <input type="checkbox" class="tl-sm-type-in" <?php checked($sm_w_mapie); ?> <?php disabled($sm_noindex); ?>>
                            W mapie
                        </label>
                        <label>
                            <input type="checkbox" class="tl-sm-type-noindex" <?php checked($sm_noindex); ?> <?php disabled($sm_z_fields); ?>>
                            Poza indeksem
                        </label>
                        <label class="evk-sm-anchor">
                            Kotwice na stronie:
                            <select class="tl-sm-type-anchor">
                                <option value="0">— bez kotwic —</option>
                                <?php foreach ($sm_strony as $sm_strona): ?>
                                <option value="<?php echo esc_attr($sm_strona->ID); ?>" <?php selected((int) ($sm_kotwice[$sm_slug] ?? 0), (int) $sm_strona->ID); ?>>
                                    <?php echo esc_html(get_the_title($sm_strona) ?: '(bez tytułu)'); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <details class="evo-note"><summary>Po co kotwice</summary><div class="evo-note-body">
                    Typ, którego wpisy renderują się jako sekcje jednej strony (pozycje menu, wiersze cennika),
                    nie ma sensownej podstrony. Po wskazaniu strony docelowej do mapy trafia
                    <code>/oferta/#slug-wpisu</code> zamiast martwego permalinka — kotwicą jest nazwa skrócona wpisu,
                    więc sekcja w szablonie musi mieć <code>id</code> o tej samej nazwie.
                </div></details>
            </div>

            <div class="evo-sm-box">
                <h3>Taksonomie</h3>
                <div class="evo-list-box" id="tl-sm-taxonomies">
                    <?php foreach ($sm_taks as $sm_slug => $sm_obj):
                        $sm_z_fields = in_array($sm_slug, $sm_fields_taks, true);
                        $sm_noindex  = $sm_z_fields || in_array($sm_slug, $sm_noidx_taks, true);
                        $sm_w_mapie  = !$sm_noindex && !in_array($sm_slug, $sm_wykl_taks, true);
                    ?>
                    <div class="evo-list-row evk-sm-tax" data-slug="<?php echo esc_attr($sm_slug); ?>">
                        <strong class="evo-list-key">
                            <?php echo esc_html($sm_obj->labels->name ?? $sm_slug); ?>
                            <code class="evo-muted"><?php echo esc_html($sm_slug); ?></code>
                            <?php if ($sm_z_fields): ?><span class="evo-faint">— z Evoke FIELDS</span><?php endif; ?>
                        </strong>
                        <label>
                            <input type="checkbox" class="tl-sm-tax-in" <?php checked($sm_w_mapie); ?> <?php disabled($sm_noindex); ?>>
                            W mapie
                        </label>
                        <label>
                            <input type="checkbox" class="tl-sm-tax-noindex" <?php checked($sm_noindex); ?> <?php disabled($sm_z_fields); ?>>
                            Poza indeksem
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="evo-sm-box">
                <h3>Pozostałe sekcje</h3>
                <label><input type="checkbox" id="tl-sm-users"        <?php checked(!empty($sitemap_settings['include_users'])); ?>> Użytkownicy (<code>wp-sitemap-users-1.xml</code>)</label>
                <label><input type="checkbox" id="tl-sm-auto-noindex" <?php checked(!empty($sitemap_settings['auto_exclude_noindex'])); ?>> Automatycznie pomijaj strony i wpisy z meta <code>noindex</code></label>
            </div>

            <?php if ($sm_tl_on): ?>
            <div class="evo-sm-box">
                <h3>Sekcja tłumaczeń</h3>
                <p class="evo-lead">Dopisuje do mapy adresy z przetłumaczonymi slugami jako osobną sekcję <code>wp-sitemap-translations-1.xml</code>.</p>
                <label><input type="checkbox" id="tl-sm-enabled"         <?php checked(!empty($sitemap_settings['enabled'])); ?>> Włącz sekcję tłumaczeń</label>
                <label><input type="checkbox" id="tl-sm-home"            <?php checked(!empty($sitemap_settings['include_home'])); ?>> Strona główna w wersjach językowych</label>
                <label><input type="checkbox" id="tl-sm-pages"           <?php checked(!empty($sitemap_settings['include_pages'])); ?>> Strony</label>
                <label><input type="checkbox" id="tl-sm-posts"           <?php checked(!empty($sitemap_settings['include_posts'])); ?>> Wpisy</label>
                <label><input type="checkbox" id="tl-sm-polish"          <?php checked(!empty($sitemap_settings['include_polish'])); ?>> Dodaj też polskie adresy do sekcji tłumaczeń</label>
                <label><input type="checkbox" id="tl-sm-only-translated" <?php checked(!empty($sitemap_settings['only_translated_slugs'])); ?>> Pomijaj podstrony bez przetłumaczonego sluga</label>
            </div>
            <?php endif; ?>

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
                <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Sprawdza które strony mają wykryte meta noindex i przez jakie pole. Skan obejmuje strony i wpisy — typy treści wyklucza się wyżej, jednym checkboksem, bez czytania metadanych każdego wpisu.</div></details>
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
