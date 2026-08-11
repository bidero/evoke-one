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

/** Adres zakładki z podmienionymi argumentami; reszta stanu zostaje. */
$seo_url = static function (array $args) use ($seo_base, $seo_pt, $seo_s) {
    return add_query_arg(array_merge(['seo_pt' => $seo_pt, 'seo_s' => $seo_s], $args), $seo_base);
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
?>
                <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>
                    Evoke ONE renderuje wszystkie meta tagi (tytuł, opis, słowa kluczowe, robots, og:*).
                    Priorytet źródeł per strona: <strong>Bricks → Ustawienia strony → SEO / Media społecznościowe</strong>,
                    a gdy pole tam jest puste — wartości z tej zakładki. Natywne meta tagi Bricksa są
                    automatycznie wyłączane, żeby nie dublować wpisów. Obrazek og:image: Media społecznościowe
                    Bricksa → generator OG → obrazek wyróżniający.
                </div></div>

                <?php if (count($seo_types) > 1): ?>
                <div class="evk-seo-types">
                    <?php foreach ($seo_types as $seo_key => $seo_obj): ?>
                    <a href="<?php echo esc_url(add_query_arg(['seo_pt' => $seo_key, 'seo_s' => $seo_s], $seo_base)); ?>"
                       class="evk-seo-type<?php echo $seo_key === $seo_pt ? ' is-active' : ''; ?>">
                        <?php echo esc_html($seo_obj->labels->name); ?>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="evk-seo-toolbar">
                    <?php /* Zwykły formularz GET: szukanie ma trafić do ZAPYTANIA, a nie
                             przebierać w wierszach, które już są na stronie. */ ?>
                    <form method="get" class="evk-seo-search-form">
                        <?php foreach (['page' => $_GET['page'] ?? '', 'tab' => 'strona', 'sub' => 'meta', 'seo_pt' => $seo_pt] as $seo_hk => $seo_hv): ?>
                        <input type="hidden" name="<?php echo esc_attr($seo_hk); ?>" value="<?php echo esc_attr($seo_hv); ?>">
                        <?php endforeach; ?>
                        <input type="search" name="seo_s" id="evoke-seo-search" value="<?php echo esc_attr($seo_s); ?>"
                               placeholder="Szukaj po tytule..." class="evk-seo-search-input">
                        <button type="submit" class="button">Szukaj</button>
                        <?php if ($seo_s !== ''): ?>
                        <a href="<?php echo esc_url(add_query_arg(['seo_pt' => $seo_pt], $seo_base)); ?>" class="button">Wyczyść</a>
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

                <div class="evk-table-wrap"><table class="wp-list-table widefat fixed striped">
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
                    ?>
                    <tr class="evoke-seo-row" data-id="<?php echo esc_attr($pid); ?>">
                        <td>
                            <strong class="evoke-seo-post-title"><?php the_title(); ?></strong><br>
                            <span class="evk-seo-id">ID: <?php echo $pid; ?></span><br>
                            <a href="<?php the_permalink(); ?>" target="_blank" class="evk-seo-peek">Podgląd →</a>
                        </td>
                        <td>
                            <div class="evoke-seo-fields">
                                <input type="text" class="evoke-seo-title"    value="<?php echo esc_attr(get_post_meta($pid,'_evoke_seo_title',true)); ?>" placeholder="Tytuł SEO...">
                                <textarea class="evoke-seo-desc" rows="2" placeholder="Opis SEO..."><?php echo esc_textarea(get_post_meta($pid,'_evoke_seo_desc',true)); ?></textarea>
                                <input type="text" class="evoke-seo-keywords" value="<?php echo esc_attr(get_post_meta($pid,'_evoke_seo_keywords',true)); ?>" placeholder="Słowa kluczowe...">
                                <button type="button" class="button button-primary evoke-save-seo evk-seo-save-inline evo-mt-xs">Zapisz</button>
                            </div>
                        </td>
                        <td class="evk-seo-robots-col evo-top">
                            <?php foreach (['index','noindex','follow','nofollow','noarchive','nosnippet'] as $rv): ?>
                            <label class="evk-seo-robot">
                                <input type="checkbox" class="evoke-seo-robots-cb" value="<?php echo $rv; ?>" <?php checked(in_array($rv, $saved_robots)); ?>>
                                <?php echo $rv; ?>
                            </label>
                            <?php endforeach; ?>
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
