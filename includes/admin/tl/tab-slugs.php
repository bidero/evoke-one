<?php
if (!defined('ABSPATH')) exit;
// Evoke ONE — TL tab content. Zmienne z tl_render_page(): $data $langs $codes $tab $base $nonce $ajax_url $stats
?>
<div class="tl-info-box">
                <strong>Tłumaczenie slugów URL:</strong> Zdefiniuj tłumaczenia dla slugów (adresów URL) stron i postów.<br>
                Polski slug to oryginalny slug strony/postu w WordPressie. Przykład:<br>
                <code>o-nas</code> → EN: <code>about-us</code>, DE: <code>uber-uns</code><br>
                URL będzie wyglądał: <code>/en/about-us/</code> zamiast <code>/en/o-nas/</code>
            </div>

            <div class="evo-tbl-wrap">
            <table class="slug-table" id="slug-table">
                <thead>
                    <tr>
                        <th class="evo-w" style="--evo-w:25%">Slug PL (oryginalny)</th>
                        <?php foreach ($langs as $code => $lang): ?>
                        <th><?php echo esc_html($lang['name']); ?> (<?php echo esc_html(strtoupper($code)); ?>)</th>
                        <?php endforeach; ?>
                        <th class="evo-w" style="--evo-w:60px"></th>
                    </tr>
                </thead>
                <tbody id="slug-body">
                <?php foreach ($url_slugs as $index => $slug_entry): ?>
                <tr class="slug-row">
                    <td><input type="text" class="slug-input slug-pl" value="<?php echo esc_attr($slug_entry['pl'] ?? ''); ?>" placeholder="np. o-nas"></td>
                    <?php foreach ($codes as $code): ?>
                    <td><input type="text" class="slug-input slug-<?php echo esc_attr($code); ?>" value="<?php echo esc_attr($slug_entry[$code] ?? ''); ?>" placeholder="np. about-us"></td>
                    <?php endforeach; ?>
                    <td><button type="button" class="button button-icon dashicons dashicons-trash button-link-delete" title="Usuń slug" onclick="jQuery(this).closest('tr').remove();tlMarkDirty();"></button></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>

            <div class="evo-mt evo-mb">
                <button type="button" class="button" onclick="tlAddSlugRow()"><span class="dashicons dashicons-plus-alt2"></span> Dodaj slug</button>
                <button type="button" class="button evo-ml" onclick="tlAutoDetectSlugs()"><span class="dashicons dashicons-search"></span> Wykryj slugi stron</button>
            </div>

            <div class="tl-save-bar">
                <button type="button" class="button button-primary" onclick="tlSaveSlugs()"><span class="dashicons dashicons-saved"></span> Zapisz slugi URL</button>
                <span class="tl-save-status" id="save-status-slugs"></span>
            </div>

            <script>
            (function($){
                const CODES = <?php echo wp_json_encode($codes); ?>;

                window.tlAddSlugRow = function() {
                    let cells = '<td><input type="text" class="slug-input slug-pl" placeholder="np. kontakt"></td>';
                    CODES.forEach(function(code) {
                        cells += '<td><input type="text" class="slug-input slug-' + code + '" placeholder=""></td>';
                    });
                    cells += '<td><button type="button" class="button button-icon dashicons dashicons-trash button-link-delete" title="Usuń slug" onclick="jQuery(this).closest(\'tr\').remove();tlMarkDirty();"></button></td>';
                    $('#slug-body').append('<tr class="slug-row">' + cells + '</tr>');
                    tlMarkDirty();
                };

                window.tlAutoDetectSlugs = function() {
                    // Pobierz wszystkie strony i posty
                    $.get(ajaxurl, {action: 'tl_get_all_slugs', nonce: '<?php echo esc_js($nonce); ?>'}, function(response) {
                        if (response.success && response.data) {
                            response.data.forEach(function(slug) {
                                // Sprawdź czy slug już istnieje
                                let exists = false;
                                $('.slug-pl').each(function() {
                                    if ($(this).val() === slug) exists = true;
                                });
                                if (!exists) {
                                    tlAddSlugRow();
                                    $('#slug-body tr:last .slug-pl').val(slug);
                                }
                            });
                        }
                    });
                };

                window.tlSaveSlugs = function() {
                    const $st = $('#save-status-slugs');
                    $st.removeClass('ok err').hide();

                    const payload = [];
                    $('#slug-body .slug-row').each(function() {
                        const row = {pl: $(this).find('.slug-pl').val().trim()};
                        if (!row.pl) return;
                        CODES.forEach(function(code) {
                            row[code] = $(this).find('.slug-' + code).val().trim();
                        }.bind(this));
                        payload.push(row);
                    });

                    $.post(ajaxurl, {
                        action: 'tl_save_slugs',
                        nonce: '<?php echo esc_js($nonce); ?>',
                        tl_url_slugs: JSON.stringify(payload)
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
