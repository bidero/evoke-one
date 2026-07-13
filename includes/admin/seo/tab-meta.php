<?php
if (!defined('ABSPATH')) exit;
?>
                <?php $post_types = get_post_types(['public' => true], 'objects'); ?>

                <div class="evo-info-box"><span class="dashicons dashicons-info"></span><div>
                    Evoke ONE renderuje wszystkie meta tagi (tytuł, opis, słowa kluczowe, robots, og:*).
                    Priorytet źródeł per strona: <strong>Bricks → Ustawienia strony → SEO / Media społecznościowe</strong>,
                    a gdy pole tam jest puste — wartości z tej zakładki. Natywne meta tagi Bricksa są
                    automatycznie wyłączane, żeby nie dublować wpisów. Obrazek og:image: Media społecznościowe
                    Bricksa → generator OG → obrazek wyróżniający.
                </div></div>

                <div class="evk-seo-toolbar">
                    <input type="text" id="evoke-seo-search" placeholder="Szukaj po tytule..." class="evk-seo-search-input">
                    <div class="evk-seo-toolbar-actions">
                        <button type="button" id="evoke-seo-save-all" class="button button-primary">Zapisz wszystkie</button>
                        <span id="evoke-seo-bulk-status" style="font-size:12px;color:#16a34a;"></span>
                    </div>
                </div>

                <style>
                    .evoke-seo-fields { display:flex; flex-direction:column; gap:8px; max-width:800px; }
                    .evoke-seo-fields input[type=text], .evoke-seo-fields textarea { width:100%; border:1px solid #d1d5db; border-radius:6px; padding:4px 8px; }
                    .evoke-seo-fields input:focus, .evoke-seo-fields textarea:focus { border-color:#2563eb; box-shadow:0 0 0 3px rgba(37,99,235,.12); outline:none; }
                    .evoke-save-seo { min-width:90px !important; white-space:nowrap; }
                </style>

                <?php foreach ($post_types as $pt):
                    if ($pt->name === 'attachment') continue;
                    $query = new WP_Query(['post_type'=>$pt->name,'post_status'=>'publish','posts_per_page'=>-1,'orderby'=>'title','order'=>'ASC']);
                    if (!$query->have_posts()) { wp_reset_postdata(); continue; }
                ?>
                <h3 style="margin-top:32px;margin-bottom:12px;padding-bottom:8px;border-bottom:2px solid #e5e7eb;">
                    <?php echo esc_html($pt->labels->name); ?>
                    <span style="color:#888;font-size:13px;font-weight:400;">(<?php echo $query->found_posts; ?>)</span>
                </h3>
                <div class="evk-table-wrap"><table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width:22%;">Strona / Wpis</th>
                            <th>Dane SEO (Tytuł, Opis, Słowa kluczowe)</th>
                            <th style="width:15%;" class="evk-seo-robots-col">Robots</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php while ($query->have_posts()): $query->the_post(); $pid = get_the_ID();
                        $saved_robots = (array)(get_post_meta($pid, '_evoke_seo_robots', true) ?: []);
                    ?>
                    <tr class="evoke-seo-row" data-id="<?php echo esc_attr($pid); ?>">
                        <td>
                            <strong class="evoke-seo-post-title"><?php the_title(); ?></strong><br>
                            <span style="color:#888;font-size:11px;">ID: <?php echo $pid; ?></span><br>
                            <a href="<?php the_permalink(); ?>" target="_blank" style="font-size:11px;">Podgląd →</a>
                        </td>
                        <td>
                            <div class="evoke-seo-fields">
                                <input type="text" class="evoke-seo-title"    value="<?php echo esc_attr(get_post_meta($pid,'_evoke_seo_title',true)); ?>" placeholder="Tytuł SEO...">
                                <textarea class="evoke-seo-desc" rows="2" placeholder="Opis SEO..."><?php echo esc_textarea(get_post_meta($pid,'_evoke_seo_desc',true)); ?></textarea>
                                <input type="text" class="evoke-seo-keywords" value="<?php echo esc_attr(get_post_meta($pid,'_evoke_seo_keywords',true)); ?>" placeholder="Słowa kluczowe...">
                                <button type="button" class="button button-primary evoke-save-seo evk-seo-save-inline" style="margin-top:6px;">Zapisz</button>
                            </div>
                        </td>
                        <td class="evk-seo-robots-col" style="vertical-align:top;padding-top:8px;">
                            <?php foreach (['index','noindex','follow','nofollow','noarchive','nosnippet'] as $rv): ?>
                            <label style="display:flex;align-items:center;gap:4px;font-size:11px;white-space:nowrap;cursor:pointer;margin-bottom:2px;">
                                <input type="checkbox" class="evoke-seo-robots-cb" value="<?php echo $rv; ?>" <?php checked(in_array($rv, $saved_robots)); ?>>
                                <?php echo $rv; ?>
                            </label>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <?php endwhile; wp_reset_postdata(); ?>
                    </tbody>
                </table></div>
                <?php endforeach; ?>

                <script>
                (function($){
                    $('#evoke-seo-search').on('input', function(){
                        var q = this.value.toLowerCase();
                        $('.evoke-seo-row').each(function(){
                            $(this).toggle($(this).find('.evoke-seo-post-title').text().toLowerCase().includes(q));
                        });
                    });

                    $(document).on('click', '.evoke-save-seo', function(){
                        var btn = this, $row = $(btn).closest('tr');
                        var origW = $(btn).outerWidth();
                        $(btn).css('min-width', origW + 'px');
                        btn.textContent = 'Zapisuję...'; btn.disabled = true;
                        var robots = [];
                        $row.find('.evoke-seo-robots-cb:checked').each(function(){ robots.push($(this).val()); });
                        $.post(evoSeoAjax.url, {
                            action:'evoke_save_seo_ajax', nonce:evoSeoAjax.nonce,
                            post_id: $row.data('id'),
                            seo_title: $row.find('.evoke-seo-title').val(),
                            seo_desc: $row.find('.evoke-seo-desc').val(),
                            seo_keywords: $row.find('.evoke-seo-keywords').val(),
                            seo_robots: JSON.stringify(robots)
                        }).done(function(r){
                            $(btn).css({background: r.success ? '#16a34a' : '#dc2626', color:'#fff', borderColor: r.success ? '#008a20' : '#b91c1c'});
                            btn.textContent = r.success ? 'Zapisano!' : 'Błąd!';
                        }).always(function(){
                            setTimeout(function(){ btn.textContent='Zapisz'; btn.disabled=false; $(btn).removeAttr('style').css('min-width', origW+'px'); }, 1800);
                        });
                    });

                    $('#evoke-seo-save-all').on('click', function(){
                        var $btn=$(this), rows=[];
                        $('.evoke-seo-row').each(function(){
                            var robots=[];
                            $(this).find('.evoke-seo-robots-cb:checked').each(function(){ robots.push($(this).val()); });
                            rows.push({post_id:$(this).data('id'),seo_title:$(this).find('.evoke-seo-title').val(),seo_desc:$(this).find('.evoke-seo-desc').val(),seo_keywords:$(this).find('.evoke-seo-keywords').val(),seo_robots:robots});
                        });
                        $btn.text('Zapisuję...').prop('disabled',true);
                        $.post(evoSeoAjax.url,{action:'evoke_save_seo_bulk',nonce:evoSeoAjax.nonce,rows:JSON.stringify(rows)}).done(function(r){
                            $('#evoke-seo-bulk-status').text(r.success ? 'Zapisano '+r.data.saved+' wpisów!' : 'Błąd');
                            setTimeout(function(){ $('#evoke-seo-bulk-status').text(''); },3000);
                        }).always(function(){ $btn.text('Zapisz wszystkie').prop('disabled',false); });
                    });
                })(jQuery);
                </script>
