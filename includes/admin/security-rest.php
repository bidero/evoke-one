<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Bezpieczeństwo: REST API
 */
?>
<form id="evk-sec-form-rest" data-section="rest">

    <div class="evo-status-card evo-mb">
        <div class="evo-status-icon <?php echo !empty($evk_sec['rest_block_all']) ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-rest-api evo-ico-lg"></span>
        </div>
        <div class="evo-status-text">
            <h3>Zablokuj cały REST API dla gości</h3>
            <p>Każdy request do <code>/wp-json/</code> zwróci Access Denied dla niezalogowanych.</p>
        </div>
        <div class="evo-status-actions">
            <label class="evo-toggle">
                <input type="checkbox" name="evk_security[rest_block_all]" data-option="evk_security" data-field="rest_block_all" value="1" <?php checked(1, $evk_sec['rest_block_all'] ?? 0); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>

    <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">Lub zaznacz konkretne endpointy. Zalogowani użytkownicy zawsze mają dostęp.</div></details>

    <?php
    $disabled_endpoints = $evk_sec['disabled_rest_endpoints'] ?? [];
    $grouped = evk_rest_get_endpoints();
    $total   = array_sum(array_map('count', $grouped));
    ?>

    <div class="evo-toolbar evo-mb-sm">
        <label class="evo-check">
            <input type="checkbox" id="evk-rest-select-all">
            Zaznacz / odznacz wszystkie
        </label>
        <span class="evo-hint">Łącznie: <?php echo $total; ?> endpointów</span>
    </div>

    <div class="evo-scroll-box">
        <?php foreach ($grouped as $namespace => $endpoints): ?>
        <div class="evo-mb">
            <div class="evo-group-head">
                <?php echo esc_html($namespace); ?>
                <span class="evo-faint"><?php echo count($endpoints); ?></span>
            </div>
            <?php foreach ($endpoints as $ep):
                $checked = in_array($ep['route'], $disabled_endpoints, true);
            ?>
            <label class="evo-ep-row">
                <input type="checkbox" class="evk-rest-ep"
                       name="evk_security[disabled_rest_endpoints][]"
                       value="<?php echo esc_attr($ep['route']); ?>"
                       <?php checked($checked); ?>>
                <code class="evo-code-chip"><?php echo esc_html($ep['route']); ?></code>
                <span class="evo-hint-sm"><?php echo esc_html(implode(', ', $ep['methods'])); ?></span>
            </label>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <script>
    (function($){
        $('#evk-rest-select-all').on('change', function(){
            $('.evk-rest-ep').prop('checked', $(this).prop('checked'));
        });
    })(jQuery);
    </script>

    <div class="evo-save-bar"><button type="submit" class="button button-primary">Zapisz</button><span class="evk-sec-saved evo-save-msg evo-ml">✓ Zapisano</span></div>
</form>
