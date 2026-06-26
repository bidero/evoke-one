<?php
if (!defined('ABSPATH')) exit;
// Evoke ONE — TL tab content. Zmienne z tl_render_page(): $data $langs $codes $tab $base $nonce $ajax_url $stats
?>
<p style="color:#475569;margin-bottom:16px;">Wybierz bazowy obrazek PL, a nastepnie przypisz mu alternatywne wersje dla innych jezykow.</p>
            <div class="tl-img-grid" id="img-grid">
            <?php foreach ($images as $key => $entry): ?>
                <div class="tl-img-card" data-key="<?php echo esc_attr($key); ?>">
                    <div class="tl-img-card-header">
                        <strong style="flex:1;">Tlumaczenie obrazka</strong>
                        <button type="button" class="button-link-delete" style="font-size:18px;line-height:1;" onclick="jQuery(this).closest('.tl-img-card').remove();tlMarkDirtyImages();">✕</button>
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
                        <button type="button" class="button" onclick="tlOpenMedia(this.previousElementSibling,'<?php echo esc_js($code); ?>')"><?php echo $att_id?'Zmien':'Wybierz'; ?></button>
                        <?php if ($att_id): ?><button type="button" class="button-link-delete" style="font-size:12px;" onclick="tlRemoveImage(this,'<?php echo esc_js($code); ?>')">Usun</button><?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
            </div>
            <div style="margin-top:16px;margin-bottom:12px;"><button type="button" class="button button-secondary" onclick="tlAddImageCard()">+ Dodaj obrazek</button></div>
            <div class="tl-save-bar">
                <button type="button" class="button button-primary" onclick="tlSaveImages()">Zapisz obrazki</button>
                <span class="tl-save-status" id="save-status-images"></span>
            </div>
