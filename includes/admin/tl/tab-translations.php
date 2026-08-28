<?php
if (!defined('ABSPATH')) exit;
// Evoke ONE — TL tab content. Zmienne z tl_render_page(): $data $langs $codes $tab $base $nonce $ajax_url $stats
?>
<?php if (!empty($codes)): ?>
            <div class="tl-coverage">
                <?php foreach ($stats['by_lang'] as $code => $pct): $cls = $pct>=80?'good':($pct>=40?'mid':''); ?>
                <div class="tl-cov-item">
                    <strong><?php echo esc_html(strtoupper($code)); ?></strong>
                    <div class="tl-cov-bar"><div class="tl-cov-fill <?php echo esc_attr($cls); ?>" style="width:<?php echo esc_attr($pct); ?>%"></div></div>
                    <span><?php echo esc_html($pct); ?>% (<?php echo esc_html($stats['total']); ?> fraz)</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="tl-toolbar">
                <div class="tl-search-wrap">
                    <span class="dashicons dashicons-search evo-faint"></span>
                    <input type="text" id="tl-search" placeholder="Szukaj frazy..." autocomplete="off">
                    <span id="tl-search-count"></span>
                </div>
                <button type="button" class="button" id="btn-expand-all"><span class="dashicons dashicons-arrow-down-alt2"></span> Rozwiń wszystko</button>
                <button type="button" class="button" id="btn-collapse-all"><span class="dashicons dashicons-arrow-up-alt2"></span> Zwiń wszystko</button>
            </div>

            <div id="groups-wrapper">
            <?php foreach (($data['groups'] ?? []) as $group_id => $group): $row_count = count($group['rows'] ?? []); ?>
                <div class="tl-group" data-gid="<?php echo esc_attr($group_id); ?>">
                    <div class="tl-group-header collapsed">
                        <span class="drag-handle dashicons dashicons-move" title="Przeciągnij"></span>
                        <div class="tl-group-toggle">
                            <span class="tl-group-toggle-icon">▶</span>
                            <input type="text" class="tl-group-name-input" data-gid="<?php echo esc_attr($group_id); ?>" value="<?php echo esc_attr($group['name'] ?? ''); ?>" placeholder="Nazwa grupy...">
                            <span class="badge-count"><?php echo esc_html($row_count); ?></span>
                        </div>
                        <div class="tl-group-actions">
                            <button type="button" class="button button-icon dashicons dashicons-download" title="Eksportuj" onclick="tlExportGroup('<?php echo esc_js($group_id); ?>');event.stopPropagation();"></button>
                            <button type="button" class="button button-icon dashicons dashicons-admin-page" title="Duplikuj" onclick="tlDuplicateGroup(this);event.stopPropagation();"></button>
                            <button type="button" class="button button-icon dashicons dashicons-trash button-link-delete" title="Usuń" onclick="if(confirm('Usunąć całą grupę?')){jQuery(this).closest('.tl-group').remove();tlMarkDirty();}event.stopPropagation();"></button>
                        </div>
                    </div>
                    <div class="tl-group-body">
                        <div class="tl-rows row-sortable">
                        <?php foreach (($group['rows'] ?? []) as $row_id => $row):
                            $row_pl     = trim($row['pl'] ?? '');
                            $row_dd_key = sanitize_key($row['dd_key'] ?? '');
                            if (!$row_dd_key && $row_pl) $row_dd_key = $dd_by_phrase[$row_pl] ?? '';
                        ?>
                            <div class="tl-row" data-rid="<?php echo esc_attr($row_id); ?>">
                                <div class="tl-row-header">
                                    <span class="drag-handle" title="Przeciągnij">⠿</span>
                                    <span class="tl-row-pl-preview tl-row-toggle-trigger"><?php echo esc_html($row_pl ?: '- pusta -'); ?></span>
                                    <div class="tl-lang-pills tl-row-toggle-trigger">
                                        <?php foreach ($codes as $code): ?>
                                        <span class="tl-pill <?php echo !empty($row[$code])?'filled':''; ?>"><?php echo esc_html(strtoupper($code)); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <span class="tl-chevron tl-row-toggle-trigger">▶</span>
                                </div>
                                <div class="tl-row-body">
                                    <div class="tl-dd-field">
                                        <label>Klucz Dynamic Data</label>
                                        <div class="tl-dd-field-row">
                                            <span class="tl-dd-prefix">{tl_</span>
                                            <input type="text" class="tl-dd-key-input" data-field="dd_key" data-gid="<?php echo esc_attr($group_id); ?>" data-rid="<?php echo esc_attr($row_id); ?>" value="<?php echo esc_attr($row_dd_key); ?>" placeholder="opcjonalny_klucz" oninput="tlMarkDirty();">
                                            <span class="tl-dd-prefix">}</span>
                                        </div>
                                    </div>
                                    <div class="tl-field">
                                        <label>Polski</label>
                                        <textarea data-field="pl" data-gid="<?php echo esc_attr($group_id); ?>" data-rid="<?php echo esc_attr($row_id); ?>" oninput="tlUpdatePreview(this);tlMarkDirty();"><?php echo esc_textarea($row['pl'] ?? ''); ?></textarea>
                                    </div>
                                    <?php foreach ($langs as $code => $lang): ?>
                                    <div class="tl-field">
                                        <label><?php echo esc_html($lang['name']); ?></label>
                                        <textarea data-field="<?php echo esc_attr($code); ?>" data-gid="<?php echo esc_attr($group_id); ?>" data-rid="<?php echo esc_attr($row_id); ?>" oninput="tlUpdatePill(this);tlMarkDirty();"><?php echo esc_textarea($row[$code] ?? ''); ?></textarea>
                                    </div>
                                    <?php endforeach; ?>
                                    <div class="tl-row-footer">
                                        <button type="button" class="button button-icon dashicons dashicons-admin-page" title="Duplikuj frazę" onclick="tlDuplicateRow(this)"></button>
                                        <button type="button" class="button button-icon dashicons dashicons-trash button-link-delete evo-ml-auto" title="Usuń frazę" onclick="jQuery(this).closest('.tl-row').remove();tlMarkDirty();"></button>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <div class="evo-row-footer">
                            <button type="button" class="button" onclick="tlAddRow(this,'<?php echo esc_js($group_id); ?>')"><span class="dashicons dashicons-plus-alt2"></span> Dodaj frazę</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>

            <div class="evo-mb-sm">
                <button type="button" class="button button-secondary" onclick="tlAddGroup()"><span class="dashicons dashicons-plus-alt2"></span> Dodaj nową grupę</button>
            </div>
            <div class="tl-save-bar">
                <button type="button" class="button button-primary" id="btn-save-translations" onclick="tlSaveTranslations()"><span class="dashicons dashicons-saved"></span> Zapisz tłumaczenia</button>
                <span class="tl-save-status" id="save-status-translations"></span>
            </div>
