<?php
if (!defined('ABSPATH')) exit;

/**
 * Meta SEO — jeden typ treści naraz, ze stronicowaniem.
 *
 * Do 1.55.0 zakładka robiła `posts_per_page => -1` na KAŻDY publiczny typ
 * treści i rysowała wszystko na jednej stronie: trzy pola i sześć checkboksów
 * na wpis. Przy 500 wpisach to ~4500 kontrolek w DOM — strona otwierała się
 * długo albo nie otwierała wcale.
 *
 * Wyszukiwarka filtrowała wtedy JUŻ ZAŁADOWANE wiersze i to jest powód, dla
 * którego stronicowanie i szukanie musiały wejść razem: samo stronicowanie
 * zamieniłoby wyszukiwarkę w narzędzie przeszukujące bieżącą stronę, czyli
 * zabrałoby funkcję, która wcześniej działała.
 */

$seo_types = [];
foreach (get_post_types(['public' => true], 'objects') as $seo_type) {
    if ($seo_type->name === 'attachment') continue;
    $seo_types[$seo_type->name] = $seo_type;
}

$seo_pt = sanitize_key($_GET['seo_pt'] ?? '');
if (!isset($seo_types[$seo_pt])) $seo_pt = (string) array_key_first($seo_types);

$seo_s     = sanitize_text_field(wp_unslash($_GET['seo_s'] ?? ''));
$seo_paged = max(1, intval($_GET['seo_paged'] ?? 1));
$seo_per   = 20;

$seo_base = add_query_arg(['tab' => 'strona', 'sub' => 'meta'], $base);

/* Wersje językowe (1.251.0) — tylko przy włączonych Tłumaczeniach; bez nich
   zakładka wygląda jak dotąd. Widok języka idzie w adresie (`seo_lang`), więc
   stronicowanie, typy treści i szukanie go nie gubią. */
$seo_jezyki = function_exists('evk_seo_jezyki') ? evk_seo_jezyki() : [];
$seo_lang   = sanitize_key($_GET['seo_lang'] ?? '');
if (!isset($seo_jezyki[$seo_lang])) $seo_lang = 'pl';
$seo_obcy   = $seo_lang !== 'pl';
$seo_stan   = $seo_obcy ? ['seo_lang' => $seo_lang] : [];
$seo_tl_ile = ($seo_jezyki && function_exists('evk_seo_tl_wpisy')) ? count(evk_seo_tl_wpisy()) : 0;

/** Adres zakładki z podmienionymi argumentami; reszta stanu zostaje. */
$seo_url = static function (array $args) use ($seo_base, $seo_pt, $seo_s, $seo_stan) {
    return add_query_arg(array_merge(['seo_pt' => $seo_pt, 'seo_s' => $seo_s], $seo_stan, $args), $seo_base);
};

$seo_query = $seo_types ? new WP_Query([
    'post_type'      => $seo_pt,
    'post_status'    => 'publish',
    'posts_per_page' => $seo_per,
    'paged'          => $seo_paged,
    's'              => $seo_s,
    'orderby'        => 'title',
    'order'          => 'ASC',
]) : null;

$seo_max = $seo_query ? max(1, (int) $seo_query->max_num_pages) : 1;

/** Wartość z Bricksa pod polem — ma pierwszeństwo na stronie, a do 1.250.0 nie było jej tu widać. */
$seo_bricks = static function (array $wartosci, string $klucz): void {
    $b = (string) ($wartosci[$klucz]['bricks'] ?? '');
    if ($b === '') return;
    echo '<p class="evk-seo-bricks"><span class="evk-seo-oryginal-jezyk">Bricks (ma pierwszeństwo):</span> ' . esc_html($b) . '</p>';
};
?>
                <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">
                    Evoke ONE renderuje wszystkie meta tagi (tytuł, opis, słowa kluczowe, robots, og:*).
                    Priorytet źródeł per strona: <strong>Bricks → Ustawienia strony → SEO / Media społecznościowe</strong>,
                    a gdy pole tam jest puste — wartości z tej zakładki. Natywne meta tagi Bricksa są
                    automatycznie wyłączane, żeby nie dublować wpisów. Obrazek og:image: Media społecznościowe
                    Bricksa → generator OG → obrazek wyróżniający.
                </div></details>

                <?php if (count($seo_types) > 1): ?>
                <div class="evk-seo-types">
                    <?php foreach ($seo_types as $seo_key => $seo_obj): ?>
                    <a href="<?php echo esc_url(add_query_arg(array_merge(['seo_pt' => $seo_key, 'seo_s' => $seo_s], $seo_stan), $seo_base)); ?>"
                       class="evk-seo-type<?php echo $seo_key === $seo_pt ? ' is-active' : ''; ?>">
                        <?php echo esc_html($seo_obj->labels->name); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <?php if ($seo_tl_ile): ?>
                <div class="evo-box evk-seo-tl" id="evk-seo-tl">
                    <h3>Znaczniki {tl_…} w polach SEO</h3>
                    <p class="evo-hint">
                        <?php echo $seo_tl_ile === 1 ? 'Na 1 stronie' : 'Na ' . (int) $seo_tl_ile . ' stronach'; ?>
                        tytuł, opis albo słowa kluczowe mają znacznik tłumaczenia. Przeniesienie wpisze polski tekst
                        w miejsce znacznika, a tłumaczenia do pustych pól języków w tabeli niżej. Pola „Media
                        społecznościowe” w Bricksie zostają bez zmian — znacznik działa tam jak dotąd.
                    </p>
                    <div class="evo-inline evk-seo-tl-akcje">
                        <button type="button" class="button" id="evk-seo-tl-podglad">Pokaż, co się przeniesie</button>
                        <span id="evk-seo-tl-przenies-w" hidden><button type="button" class="button button-primary" id="evk-seo-tl-przenies">Przenieś</button></span>
                        <span class="evo-hint" id="evk-seo-tl-stan" role="status"></span>
                    </div>
                    <div id="evk-seo-tl-wynik"></div>
                </div>
                <?php endif; ?>

                <div class="evk-seo-toolbar">
                    <?php /* Zwykły formularz GET: szukanie ma trafić do ZAPYTANIA, a nie
                             przebierać w wierszach, które już są na stronie. */ ?>
                    <form method="get" class="evk-seo-search-form">
                        <?php foreach (array_merge(['page' => $_GET['page'] ?? '', 'tab' => 'strona', 'sub' => 'meta', 'seo_pt' => $seo_pt], $seo_stan) as $seo_hk => $seo_hv): ?>
                        <input type="hidden" name="<?php echo esc_attr($seo_hk); ?>" value="<?php echo esc_attr($seo_hv); ?>">
                        <?php endforeach; ?>
                        <input type="search" name="seo_s" id="evoke-seo-search" aria-label="Szukaj po tytule" value="<?php echo esc_attr($seo_s); ?>"
                               placeholder="Szukaj po tytule..." class="evk-seo-search-input">
                        <button type="submit" class="button">Szukaj</button>
                        <?php if ($seo_s !== ''): ?>
                        <a href="<?php echo esc_url(add_query_arg(array_merge(['seo_pt' => $seo_pt], $seo_stan), $seo_base)); ?>" class="button">Wyczyść</a>
                        <?php endif; ?>
                    </form>
                    <div class="evk-seo-toolbar-actions">
                        <button type="button" id="evoke-seo-save-all" class="button button-primary">Zapisz zmienione</button>
                        <span id="evoke-seo-bulk-status" class="evo-hint"></span>
                    </div>
                </div>

                <?php if (!$seo_query || !$seo_query->have_posts()): ?>
                <p class="evo-faint evk-nl-13">
                    <?php echo $seo_s !== ''
                        ? 'Nic nie pasuje do „' . esc_html($seo_s) . '".'
                        : 'Brak opublikowanych wpisów tego typu.'; ?>
                </p>
                <?php else: ?>

                <h3 class="evo-group-title">
                    <?php echo esc_html($seo_types[$seo_pt]->labels->name); ?>
                    <span class="evk-seo-count">(<?php echo (int) $seo_query->found_posts; ?>)</span>
                </h3>

                <?php /* BEZ KLAS RDZENIA. `widefat` rysuje ramkę kwadratową, a cały panel
             jest zaokrąglony — stąd zgłoszenie „w SEO kanty w ramce są ostre".
             Wygląd bierze `.evo-tbl` z `admin.css`, proporcje kolumn zostają
             (`.evo-tbl-fixed` robi to samo, co `fixed` z rdzenia). */ ?>
                <?php if ($seo_jezyki): ?>
                <?php /* Przełącznik jak w Evoke FIELDS (te same klasy): widok języka
                         podmienia pola w każdym wierszu, robots są wspólne. */ ?>
                <div class="evk-tl-przelacznik evk-seo-przelacznik" role="group" aria-label="Wersja językowa pól SEO">
                    <?php foreach (array_merge(['pl' => 'Polski'], $seo_jezyki) as $seo_kod => $seo_nazwa): $seo_on = $seo_kod === $seo_lang; ?>
                    <button type="button" class="button<?php echo $seo_on ? ' button-primary' : ''; ?> evk-tl-jezyk" data-lang="<?php echo esc_attr($seo_kod); ?>"
                            aria-pressed="<?php echo $seo_on ? 'true' : 'false'; ?>" title="<?php echo esc_attr($seo_nazwa); ?>"><?php echo esc_html(strtoupper($seo_kod)); ?><?php if ($seo_kod !== 'pl'): ?> <span class="evk-tl-licznik" aria-hidden="true"></span><span class="screen-reader-text evk-tl-licznik-sr"></span><?php endif; ?></button>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="evk-table-wrap"><table class="evo-tbl evo-tbl-fixed evk-seo-tabela<?php echo $seo_obcy ? ' evk-seo-obcy' : ''; ?>">
                    <thead>
                        <tr>
                            <th class="evk-seo-col-post">Strona / Wpis</th>
                            <th>Dane SEO (Tytuł, Opis, Słowa kluczowe)</th>
                            <th class="evk-seo-robots-col">Robots</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($seo_query->have_posts()): $seo_query->the_post(); $pid = get_the_ID();
                        $saved_robots = (array)(get_post_meta($pid, '_evoke_seo_robots', true) ?: []);
                        $seo_pl = function_exists('evk_seo_wartosci_panelu') ? evk_seo_wartosci_panelu($pid) : [];
                        /* Nazwa dostępna pól wiersza niesie tytuł wpisu: pól „Tytuł SEO"
                           jest na ekranie tyle, ile wierszy, a czytnik bez tego mówił przy
                           każdym to samo. Tytuł bez znaczników i encji z wptexturize —
                           esc_attr() niżej zakodowałby je drugi raz. */
                        $seo_wpis = trim(wp_strip_all_tags(html_entity_decode(get_the_title($pid), ENT_QUOTES, 'UTF-8'))) ?: 'ID ' . $pid;
                    ?>
                    <tr class="evoke-seo-row" data-id="<?php echo esc_attr($pid); ?>">
                        <td>
                            <strong class="evoke-seo-post-title"><?php the_title(); ?></strong><br>
                            <span class="evk-seo-id">ID: <?php echo $pid; ?></span><br>
                            <a href="<?php the_permalink(); ?>" target="_blank" class="evk-seo-peek">Podgląd →</a>
                        </td>
                        <td>
                            <?php /* `hidden` na opakowaniu bez klasy układu, klasa na dziecku —
                                     inaczej `display: flex` z `.evoke-seo-fields` wygrywa z `hidden`. */ ?>
                            <div class="evk-seo-wersja" data-lang="pl"<?php echo $seo_obcy ? ' hidden' : ''; ?>>
                            <div class="evoke-seo-fields">
                                <input type="text" class="evoke-seo-title"    aria-label="<?php echo esc_attr('Tytuł SEO: ' . $seo_wpis); ?>" value="<?php echo esc_attr(get_post_meta($pid,'_evoke_seo_title',true)); ?>" placeholder="Tytuł SEO...">
                                <?php $seo_bricks($seo_pl, 'title'); ?>
                                <textarea class="evoke-seo-desc" rows="2" aria-label="<?php echo esc_attr('Opis SEO: ' . $seo_wpis); ?>" placeholder="Opis SEO..."><?php echo esc_textarea(get_post_meta($pid,'_evoke_seo_desc',true)); ?></textarea>
                                <?php $seo_bricks($seo_pl, 'desc'); ?>
                                <input type="text" class="evoke-seo-keywords" aria-label="<?php echo esc_attr('Słowa kluczowe: ' . $seo_wpis); ?>" value="<?php echo esc_attr(get_post_meta($pid,'_evoke_seo_keywords',true)); ?>" placeholder="Słowa kluczowe...">
                                <?php $seo_bricks($seo_pl, 'keywords'); ?>
                            </div>
                            </div>
                            <?php foreach ($seo_jezyki as $seo_kod => $seo_nazwa): $seo_kod_d = strtoupper($seo_kod); ?>
                            <div class="evk-seo-wersja" data-lang="<?php echo esc_attr($seo_kod); ?>"<?php echo $seo_kod !== $seo_lang ? ' hidden' : ''; ?>>
                            <div class="evoke-seo-fields">
                                <?php foreach (['title' => ['Tytuł SEO', 'input'], 'desc' => ['Opis SEO', 'textarea'], 'keywords' => ['Słowa kluczowe', 'input']] as $seo_k => [$seo_et, $seo_typ]):
                                    $seo_orig = $seo_pl[$seo_k]['bricks'] ?? '';
                                    $seo_z_bricks = $seo_orig !== '';
                                    if (!$seo_z_bricks) $seo_orig = $seo_pl[$seo_k]['zakladka'] ?? '';
                                    $seo_wart = function_exists('evk_seo_meta_jezyka') ? evk_seo_meta_jezyka($pid, $seo_kod, 'seo_' . $seo_k) : '';
                                    $seo_attr = 'class="evk-seo-pole" data-pole="' . $seo_k . '" data-pl="' . ($seo_orig !== '' ? '1' : '0') . '"'
                                        . ' aria-label="' . esc_attr($seo_et . ' ' . $seo_kod_d . ': ' . $seo_wpis) . '"'
                                        . ' placeholder="' . esc_attr($seo_et . ' ' . $seo_kod_d . '...') . '"';
                                ?>
                                <?php if ($seo_typ === 'textarea'): ?>
                                <textarea rows="2" <?php echo $seo_attr; ?>><?php echo esc_textarea($seo_wart); ?></textarea>
                                <?php else: ?>
                                <input type="text" <?php echo $seo_attr; ?> value="<?php echo esc_attr($seo_wart); ?>">
                                <?php endif; ?>
                                <p class="evk-seo-oryginal"><span class="evk-seo-oryginal-jezyk">PL<?php echo $seo_z_bricks ? ' (Bricks)' : ''; ?>:</span>
                                    <?php echo $seo_orig !== '' ? esc_html($seo_orig) : '—'; ?></p>
                                <?php endforeach; ?>
                            </div>
                            </div>
                            <?php endforeach; ?>
                            <button type="button" class="button button-primary evoke-save-seo evk-seo-save-inline evo-mt-xs">Zapisz</button>
                        </td>
                        <td class="evk-seo-robots-col evo-top">
                            <div class="evk-seo-wspolne"<?php echo $seo_obcy ? ' inert' : ''; ?>>
                            <?php foreach (['index','noindex','follow','nofollow','noarchive','nosnippet'] as $rv): ?>
                            <label class="evk-seo-robot">
                                <input type="checkbox" class="evoke-seo-robots-cb" value="<?php echo $rv; ?>" <?php checked(in_array($rv, $saved_robots)); ?>>
                                <?php echo $rv; ?>
                            </label>
                            <?php endforeach; ?>
                            </div>
                            <?php if ($seo_jezyki): ?><p class="evk-seo-wspolne-uwaga">wspólne dla języków</p><?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; wp_reset_postdata(); ?>
                    </tbody>
                </table></div>

                <?php if ($seo_max > 1): ?>
                <div class="evk-seo-pager">
                    <?php if ($seo_paged > 1): ?>
                    <a class="button" href="<?php echo esc_url($seo_url(['seo_paged' => $seo_paged - 1])); ?>">← Poprzednia</a>
                    <?php endif; ?>
                    <span class="evo-hint">Strona <?php echo (int) $seo_paged; ?> z <?php echo (int) $seo_max; ?></span>
                    <?php if ($seo_paged < $seo_max): ?>
                    <a class="button" href="<?php echo esc_url($seo_url(['seo_paged' => $seo_paged + 1])); ?>">Następna →</a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php endif; ?>
