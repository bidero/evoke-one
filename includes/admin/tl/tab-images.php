<?php
if (!defined('ABSPATH')) exit;
// Evoke ONE — TL tab content. Zmienne z tl_render_page(): $data $langs $codes $tab $base $nonce $ajax_url $stats
?>
<p class="evo-muted-soft evo-mb">Wybierz bazowy obrazek PL, a następnie przypisz mu alternatywne wersje dla innych języków.</p>
            <div class="tl-img-grid" id="img-grid">
            <?php foreach ($images as $key => $entry): ?>
                <div class="tl-img-card" data-key="<?php echo esc_attr($key); ?>">
                    <div class="tl-img-card-header">
                        <strong class="evo-grow">Tłumaczenie obrazka</strong>
                        <button type="button" class="button-link-delete evo-close-x" onclick="jQuery(this).closest('.tl-img-card').remove();tlMarkDirtyImages();">✕</button>
                    </div>
                    <?php foreach (array_merge(['pl' => ['name' => 'Polski']], $langs) as $code => $lang): ?>
                    <?php $att_id = absint($entry[$code] ?? 0); $img_url = $att_id ? wp_get_attachment_image_url($att_id, 'thumbnail') : ''; ?>
                    <div class="tl-img-lang-row">
                        <span class="tl-img-lang-label"><?php echo esc_html($code==='pl'?'PL':strtoupper($code)); ?></span>
                        <?php if ($img_url): ?>
                        <img src="<?php echo esc_url($img_url); ?>" class="tl-img-preview" data-lang="<?php echo esc_attr($code); ?>" data-att="<?php echo esc_attr($att_id); ?>" onclick="tlOpenMedia(this,'<?php echo esc_js($code); ?>')">
                        <?php else: ?>
                        <div class="tl-img-preview-empty" data-lang="<?php echo esc_attr($code); ?>" data-att="0" onclick="tlOpenMedia(this,'<?php echo esc_js($code); ?>')">+</div>
                        <?php endif; ?>
                        <button type="button" class="button" onclick="tlOpenMedia(this.previousElementSibling,'<?php echo esc_js($code); ?>')"><span class="dashicons dashicons-format-image"></span> <?php echo $att_id?'Zmień':'Wybierz'; ?></button>
                        <?php if ($att_id): ?><button type="button" class="button button-icon dashicons dashicons-no-alt button-link-delete" title="Usuń obrazek" onclick="tlRemoveImage(this,'<?php echo esc_js($code); ?>')"></button><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <div class="evo-mt evo-mb-sm"><button type="button" class="button button-secondary" onclick="tlAddImageCard()"><span class="dashicons dashicons-plus-alt2"></span> Dodaj obrazek</button></div>
            <div class="tl-save-bar">
                <button type="button" class="button button-primary" onclick="tlSaveImages()"><span class="dashicons dashicons-saved"></span> Zapisz obrazki</button>
                <span class="tl-save-status" id="save-status-images"></span>
            </div>
