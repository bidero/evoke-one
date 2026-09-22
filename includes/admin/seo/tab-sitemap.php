<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — SEO → Mapa strony.
 *
 * Ekran steruje natywną mapą `wp-sitemap.xml`: typami treści (bez względu na
 * to, która wtyczka je zarejestrowała), taksonomiami, użytkownikami, sekcjami
 * kotwic i pojedynczymi wykluczeniami. Sekcja tłumaczeń pokazuje się wyłącznie
 * przy włączonym module języków — bez niego nie ma czego tłumaczyć.
 *
 * UKŁAD: każdy blok to akordeon (`<details class="evo-acc">`), bo ekran ma
 * teraz sześć list i rozwinięte naraz nie da się na nim niczego znaleźć.
 * Otwarte startowo są tylko typy treści — reszta czeka na kliknięcie.
 *
 * Wiersze list są SIATKĄ o stałych kolumnach (`.evk-map-row`), a nie rzędem
 * pływających etykiet. Przy układzie pływającym pozycja checkboksa zależała od
 * długości nazwy typu i żadne dwa wiersze nie miały pól w tej samej kolumnie —
 * przy ośmiu taksonomiach nie dawało się wzrokiem sprawdzić, co jest
 * zaznaczone.
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
                    (array) ($sitemap_settings['noindex_types'] ?? [])
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
                $sm_sekcje     = (array) ($sitemap_settings['anchor_sections'] ?? []);

                /* Flagi ustawione w Evoke FIELDS. Pokazujemy je jako zaznaczone
                   i zablokowane — odznaczenie tutaj wyglądałoby na skuteczne,
                   a wracałoby przy pierwszym zapisie tamtej wtyczki. */
                $sm_fields_typy = function_exists('evk_noindex_post_types') ? array_map('sanitize_key', (array) evk_noindex_post_types()) : [];
                $sm_fields_taks = function_exists('evk_noindex_taxonomies') ? array_map('sanitize_key', (array) evk_noindex_taxonomies()) : [];

                $sm_strony = get_posts(['post_type'=>'page','post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
                $sm_tl_on  = !empty(get_option('evk_tl_module_enabled', 0));

                /* Lista stron do wyboru adresu bazowego sekcji — ten sam
                   markup w wierszach istniejących i w szablonie nowego, więc
                   powstaje raz. */
                $sm_opcje_stron = static function ($wybrana) use ($sm_strony) {
                    $out = '<option value="0">— bez strony —</option>';
                    foreach ($sm_strony as $sm_strona) {
                        /* Adres w `data-url`, żeby podgląd pokazywał PRAWDZIWY
                           permalink. Skrypt składający go z tytułu strony
                           kłamałby przy każdej stronie zagnieżdżonej i przy
                           ręcznie zmienionym slugu. */
                        $out .= '<option value="' . esc_attr((string) $sm_strona->ID) . '"'
                              . ' data-url="' . esc_attr((string) get_permalink($sm_strona)) . '"'
                              . selected((int) $wybrana, (int) $sm_strona->ID, false) . '>'
                              . esc_html(get_the_title($sm_strona) ?: '(bez tytułu)') . '</option>';
                    }
                    return $out;
                };
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

            <details class="evo-acc" open>
                <summary>Typy treści <span class="evo-acc-count"><?php echo count($sm_typy); ?></span></summary>
                <div class="evo-acc-body">
                    <p class="evo-lead">Każdy zarejestrowany typ — także z Evoke FIELDS, ACF czy Metabox.</p>
                    <div class="evk-map-grid" id="tl-sm-types">
                        <div class="evk-map-row evk-map-head">
                            <span>Typ treści</span>
                            <span>W mapie</span>
                            <span>Poza indeksem</span>
                        </div>
                        <?php foreach ($sm_typy as $sm_slug => $sm_obj):
                            $sm_z_fields = in_array($sm_slug, $sm_fields_typy, true);
                            $sm_noindex  = $sm_z_fields || in_array($sm_slug, $sm_noidx_typy, true);
                            $sm_w_mapie  = !$sm_noindex && !in_array($sm_slug, $sm_wykl_typy, true);
                        ?>
                        <div class="evk-map-row evk-map-type" data-slug="<?php echo esc_attr($sm_slug); ?>">
                            <span class="evk-map-name">
                                <strong><?php echo esc_html($sm_obj->labels->name ?? $sm_slug); ?></strong>
                                <code class="evo-muted"><?php echo esc_html($sm_slug); ?></code>
                                <?php if ($sm_z_fields): ?><span class="evo-faint">z Evoke FIELDS</span><?php endif; ?>
                            </span>
                            <label class="evk-map-cell">
                                <input type="checkbox" class="tl-sm-type-in" <?php checked($sm_w_mapie); ?> <?php disabled($sm_noindex); ?>>
                                <span class="evk-map-cell-label">W mapie</span>
                            </label>
                            <label class="evk-map-cell">
                                <input type="checkbox" class="tl-sm-type-noindex" <?php checked($sm_noindex); ?> <?php disabled($sm_z_fields); ?>>
                                <span class="evk-map-cell-label">Poza indeksem</span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>

            <details class="evo-acc">
                <summary>Taksonomie <span class="evo-acc-count"><?php echo count($sm_taks); ?></span></summary>
                <div class="evo-acc-body">
                    <p class="evo-lead">Archiwa termów: kategorie, tagi i taksonomie własne.</p>
                    <div class="evk-map-grid" id="tl-sm-taxonomies">
                        <div class="evk-map-row evk-map-head">
                            <span>Taksonomia</span>
                            <span>W mapie</span>
                            <span>Poza indeksem</span>
                        </div>
                        <?php foreach ($sm_taks as $sm_slug => $sm_obj):
                            $sm_z_fields = in_array($sm_slug, $sm_fields_taks, true);
                            $sm_noindex  = $sm_z_fields || in_array($sm_slug, $sm_noidx_taks, true);
                            $sm_w_mapie  = !$sm_noindex && !in_array($sm_slug, $sm_wykl_taks, true);
                        ?>
                        <div class="evk-map-row evk-map-tax" data-slug="<?php echo esc_attr($sm_slug); ?>">
                            <span class="evk-map-name">
                                <strong><?php echo esc_html($sm_obj->labels->name ?? $sm_slug); ?></strong>
                                <code class="evo-muted"><?php echo esc_html($sm_slug); ?></code>
                                <?php if ($sm_z_fields): ?><span class="evo-faint">z Evoke FIELDS</span><?php endif; ?>
                            </span>
                            <label class="evk-map-cell">
                                <input type="checkbox" class="tl-sm-tax-in" <?php checked($sm_w_mapie); ?> <?php disabled($sm_noindex); ?>>
                                <span class="evk-map-cell-label">W mapie</span>
                            </label>
                            <label class="evk-map-cell">
                                <input type="checkbox" class="tl-sm-tax-noindex" <?php checked($sm_noindex); ?> <?php disabled($sm_z_fields); ?>>
                                <span class="evk-map-cell-label">Poza indeksem</span>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>

            <details class="evo-acc"<?php echo $sm_sekcje ? ' open' : ''; ?>>
                <summary>Sekcje z kotwicami <span class="evo-acc-count"><?php echo count($sm_sekcje); ?></span></summary>
                <div class="evo-acc-body">
                    <p class="evo-lead">
                        Własna pozycja w indeksie mapy — strona jednoekranowa zgłoszona kotwica po kotwicy.
                        Sekcja „Menu" ze stroną <code>/menu/</code> i kotwicami <code>desery</code>, <code>napoje</code>
                        daje plik <code>wp-sitemap-menu-1.xml</code> z adresami <code>/menu/#desery</code> i <code>/menu/#napoje</code>.
                    </p>
                    <details class="evo-note"><summary>Zanim dodasz</summary><div class="evo-note-body">
                        Kotwica musi odpowiadać <code>id</code> sekcji w szablonie — adres bez pasującego
                        <code>id</code> otworzy po prostu początek strony. Nazwa sekcji staje się nazwą pliku
                        (<code>Menu</code> → <code>wp-sitemap-menu-1.xml</code>), więc nazwy <code>posts</code>,
                        <code>taxonomies</code>, <code>users</code> i <code>translations</code> są zajęte przez
                        WordPressa i sekcja o takiej nazwie nie powstanie.
                    </div></details>

                    <div id="tl-sm-sections">
                        <?php foreach ($sm_sekcje as $sm_sekcja):
                            $sm_kotwice = (array) ($sm_sekcja['anchors'] ?? []);
                        ?>
                        <div class="evk-map-section">
                            <div class="evk-map-sec-head">
                                <label class="evk-map-field">
                                    <span>Nazwa sekcji</span>
                                    <input type="text" class="tl-sm-sec-name" value="<?php echo esc_attr($sm_sekcja['name'] ?? ''); ?>" placeholder="np. Menu">
                                </label>
                                <label class="evk-map-field">
                                    <span>Strona bazowa</span>
                                    <select class="tl-sm-sec-page"><?php echo $sm_opcje_stron($sm_sekcja['page'] ?? 0); /* phpcs:ignore — zbudowane z esc_* wyżej */ ?></select>
                                </label>
                                <label class="evk-map-field">
                                    <span>albo własny adres</span>
                                    <input type="text" class="tl-sm-sec-url" value="<?php echo esc_attr($sm_sekcja['url'] ?? ''); ?>" placeholder="/menu/">
                                </label>
                                <button type="button" class="button evk-map-sec-remove" title="Usuń sekcję" aria-label="Usuń sekcję"><span class="dashicons dashicons-trash"></span></button>
                            </div>
                            <div class="evk-map-anchors">
                                <?php foreach ($sm_kotwice as $sm_kotwica): ?>
                                <div class="evk-map-anchor">
                                    <span class="evk-map-hash">#</span>
                                    <input type="text" class="tl-sm-anchor" value="<?php echo esc_attr($sm_kotwica); ?>" placeholder="desery">
                                    <button type="button" class="button evk-map-anchor-remove" title="Usuń kotwicę" aria-label="Usuń kotwicę"><span class="dashicons dashicons-no-alt"></span></button>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="button evk-map-anchor-add"><span class="dashicons dashicons-plus-alt2 evo-ico-sm evo-ico-lead"></span> Dodaj kotwicę</button>
                            <p class="evk-map-preview"></p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="button evo-mt-xs" id="tl-sm-section-add"><span class="dashicons dashicons-plus-alt2 evo-ico-sm evo-ico-lead"></span> Dodaj sekcję</button>

                    <?php /* Szablony dla skryptu. `<template>` zamiast składania
                             znaczników w JS: opcje stron są już wyrenderowane
                             i przeszły przez `esc_*`, więc nowy wiersz nie musi
                             ich budować drugi raz, innym kodem. */ ?>
                    <template id="tl-sm-section-tpl">
                        <div class="evk-map-section">
                            <div class="evk-map-sec-head">
                                <label class="evk-map-field">
                                    <span>Nazwa sekcji</span>
                                    <input type="text" class="tl-sm-sec-name" value="" placeholder="np. Menu">
                                </label>
                                <label class="evk-map-field">
                                    <span>Strona bazowa</span>
                                    <select class="tl-sm-sec-page"><?php echo $sm_opcje_stron(0); /* phpcs:ignore — zbudowane z esc_* wyżej */ ?></select>
                                </label>
                                <label class="evk-map-field">
                                    <span>albo własny adres</span>
                                    <input type="text" class="tl-sm-sec-url" value="" placeholder="/menu/">
                                </label>
                                <button type="button" class="button evk-map-sec-remove" title="Usuń sekcję" aria-label="Usuń sekcję"><span class="dashicons dashicons-trash"></span></button>
                            </div>
                            <div class="evk-map-anchors">
                                <div class="evk-map-anchor">
                                    <span class="evk-map-hash">#</span>
                                    <input type="text" class="tl-sm-anchor" value="" placeholder="desery">
                                    <button type="button" class="button evk-map-anchor-remove" title="Usuń kotwicę" aria-label="Usuń kotwicę"><span class="dashicons dashicons-no-alt"></span></button>
                                </div>
                            </div>
                            <button type="button" class="button evk-map-anchor-add"><span class="dashicons dashicons-plus-alt2 evo-ico-sm evo-ico-lead"></span> Dodaj kotwicę</button>
                            <p class="evk-map-preview"></p>
                        </div>
                    </template>
                    <template id="tl-sm-anchor-tpl">
                        <div class="evk-map-anchor">
                            <span class="evk-map-hash">#</span>
                            <input type="text" class="tl-sm-anchor" value="" placeholder="napoje">
                            <button type="button" class="button evk-map-anchor-remove" title="Usuń kotwicę" aria-label="Usuń kotwicę"><span class="dashicons dashicons-no-alt"></span></button>
                        </div>
                    </template>
                </div>
            </details>

            <details class="evo-acc">
                <summary>Pozostałe sekcje mapy</summary>
                <div class="evo-acc-body">
                    <div class="evk-map-checks">
                        <label><input type="checkbox" id="tl-sm-users"        <?php checked(!empty($sitemap_settings['include_users'])); ?>> Użytkownicy (<code>wp-sitemap-users-1.xml</code>)</label>
                        <label><input type="checkbox" id="tl-sm-auto-noindex" <?php checked(!empty($sitemap_settings['auto_exclude_noindex'])); ?>> Automatycznie pomijaj strony i wpisy z meta <code>noindex</code></label>
                    </div>
                </div>
            </details>

            <?php if ($sm_tl_on): ?>
            <details class="evo-acc">
                <summary>Sekcja hreflang (tłumaczenia)</summary>
                <div class="evo-acc-body">
                    <p class="evo-lead">
                        Dokłada do mapy sekcję <code>wp-sitemap-hreflang-1.xml</code>: każdy adres z kompletem powiązań
                        <code>xhtml:link</code> — wskazaniem na siebie, na pozostałe wersje językowe i na <code>x-default</code>.
                        Ten sam zestaw powtarza się w bloku każdej wersji, bo wyszukiwarka odrzuca deklarację,
                        której druga strona nie potwierdza.
                    </p>
                    <details class="evo-note"><summary>Dlaczego osobna sekcja</summary><div class="evo-note-body">
                        Renderer WordPressa przyjmuje dla adresu wyłącznie <code>loc</code>, <code>lastmod</code>,
                        <code>changefreq</code> i <code>priority</code> — powiązań językowych nie umie wypisać wcale.
                        Evoke ONE bierze od rdzenia adres sekcji, regułę przepisywania i wpis w indeksie,
                        a treść pliku wypisuje sam.
                    </div></details>
                    <label class="evk-map-select">
                        <span>Język domyślny (<code>x-default</code>)</span>
                        <select id="tl-sm-hreflang-default">
                            <option value="pl" <?php selected(($sitemap_settings['hreflang_default'] ?? 'pl'), 'pl'); ?>>Polski</option>
                            <?php foreach ((function_exists('tl_get_languages') ? tl_get_languages() : []) as $sm_kod => $sm_lang): ?>
                            <option value="<?php echo esc_attr($sm_kod); ?>" <?php selected(($sitemap_settings['hreflang_default'] ?? 'pl'), $sm_kod); ?>>
                                <?php echo esc_html(($sm_lang['name'] ?? $sm_kod) . ' (' . $sm_kod . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <span class="evo-muted">Używany w mapie i w tagach <code>&lt;head&gt;</code> — oba źródła muszą mówić to samo.</span>
                    </label>
                    <div class="evk-map-checks">
                        <label><input type="checkbox" id="tl-sm-enabled"         <?php checked(!empty($sitemap_settings['enabled'])); ?>> Włącz sekcję hreflang</label>
                        <label><input type="checkbox" id="tl-sm-home"            <?php checked(!empty($sitemap_settings['include_home'])); ?>> Strona główna w wersjach językowych</label>
                        <label><input type="checkbox" id="tl-sm-pages"           <?php checked(!empty($sitemap_settings['include_pages'])); ?>> Strony</label>
                        <label><input type="checkbox" id="tl-sm-posts"           <?php checked(!empty($sitemap_settings['include_posts'])); ?>> Wpisy</label>
                        <label><input type="checkbox" id="tl-sm-polish"          <?php checked(!empty($sitemap_settings['include_polish'])); ?>> Polskie adresy też jako osobne wpisy (w powiązaniach są zawsze)</label>
                        <label><input type="checkbox" id="tl-sm-only-translated" <?php checked(!empty($sitemap_settings['only_translated_slugs'])); ?>> Pomijaj podstrony bez przetłumaczonego sluga</label>
                    </div>
                </div>
            </details>
            <?php endif; ?>

            <details class="evo-acc">
                <summary>Wykluczone strony i wpisy <span class="evo-acc-count"><?php echo count($excluded_ids); ?></span></summary>
                <div class="evo-acc-body">
                    <div class="evo-sm-excluded-list">
                        <?php foreach ($sitemap_posts as $sm_post): ?>
                        <label>
                            <input type="checkbox" class="tl-sm-excluded-id" value="<?php echo esc_attr((string) $sm_post->ID); ?>" <?php checked(in_array((int) $sm_post->ID, $excluded_ids, true)); ?>>
                            <span class="evo-col-label"><?php echo esc_html($sm_post->post_type); ?></span>
                            <strong class="evo-grow"><?php echo esc_html(get_the_title($sm_post) ?: '(bez tytułu)'); ?></strong>
                            <code class="evo-muted"><?php echo esc_html($sm_post->post_name); ?></code>
                            <span class="evo-faint">#<?php echo esc_html((string) $sm_post->ID); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </details>

            <?php
            /* Diagnostyka pyta TĄ SAMĄ funkcją co mapa (`evk_sitemap_noindex_wpisu()`).
               Wcześniej przebiegała po metadanych własną pętlą — czyli opisywała
               regułę podobną, ale nie tę samą, a ekran diagnostyczny pokazujący
               co innego niż mechanizm, który diagnozuje, jest gorszy niż brak
               ekranu. */
            $diag_posts    = get_posts(['post_type' => ['page', 'post'], 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids']);
            $noindex_found = [];
            foreach ($diag_posts as $pid) {
                foreach (evk_sitemap_noindex_wpisu((int) $pid) as $meta_key => $v) {
                    $noindex_found[$pid][] = $meta_key . ' = ' . wp_trim_words((string) $v, 6);
                }
            }
            ?>
            <details class="evo-acc">
                <summary>Diagnostyka noindex <span class="evo-acc-count"><?php echo count($noindex_found); ?></span></summary>
                <div class="evo-acc-body">
                    <p class="evo-lead">Które strony mają wykryte meta <code>noindex</code> i przez jakie pole. Skan obejmuje strony i wpisy — typy treści wyklucza się wyżej, jednym checkboksem, bez czytania metadanych każdego wpisu.</p>
                    <details class="evo-note"><summary>Przeszukiwane pola</summary><div class="evo-note-body">
                        Sprawdzane są wyłącznie <strong>znane pola SEO</strong>:
                        <?php /* Escapujemy KLUCZE, nie sklejenie — `esc_html()` na całości zjadłoby
                                 znaczniki `<code>` i wypisało je jako tekst. */ ?>
                        <code><?php echo implode('</code>, <code>', array_map('esc_html', array_keys(evk_sitemap_klucze_noindex()))); ?></code>.
                        Do 1.224.3 skan chodził po wszystkich metadanych i uznawał za <code>noindex</code> każde pole,
                        którego nazwa zawierała „noindex" albo „robots" — własne pole <code>noindex_uwagi</code>
                        czy <code>robots_txt_snippet</code> wyrzucało stronę z mapy po cichu. Wtyczkę SEO spoza listy
                        dodaje filtr <code>evk_sitemap_klucze_noindex</code>.
                    </div></details>
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
                </div>
            </details>

            <p class="evo-lead">
                Sprawdź: <a href="<?php echo esc_url(home_url('/wp-sitemap.xml')); ?>" target="_blank">wp-sitemap.xml</a>
            </p>

            <div class="evo-save-bar">
                <button type="button" class="button button-primary" onclick="evoSaveSitemap()">Zapisz mapę strony</button>
                <span id="save-status-sitemap" class="evo-save-msg"></span>
            </div>
