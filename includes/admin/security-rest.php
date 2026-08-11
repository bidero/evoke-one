<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Bezpieczeństwo: REST API
 */
?>
<form id="evk-sec-form-rest" data-section="rest">

    <div class="evo-status-card" style="margin-bottom:16px;">
        <div class="evo-status-icon <?php echo !empty($evk_sec['rest_block_all']) ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-rest-api" style="font-size:24px;width:24px;height:24px;line-height:1;"></span>
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

    <div style="margin-bottom:10px;display:flex;align-items:center;gap:12px;">
        <label style="font-size:13px;font-weight:500;cursor:pointer;display:flex;align-items:center;gap:6px;">
            <input type="checkbox" id="evk-rest-select-all">
            Zaznacz / odznacz wszystkie
        </label>
        <span style="font-size:12px;color:#6b7280;">Łącznie: <?php echo $total; ?> endpointów</span>
    </div>

    <div style="max-height:280px;overflow-y:auto;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;padding:10px;">
        <?php foreach ($grouped as $namespace => $endpoints): ?>
        <div style="margin-bottom:16px;">
            <div style="font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.05em;
                        padding:4px 8px;background:#fff;border-left:3px solid #2563eb;margin-bottom:6px;">
                <?php echo esc_html($namespace); ?>
                <span style="font-weight:400;color:#6b7280;">(<?php echo count($endpoints); ?>)</span>
            </div>
            <?php foreach ($endpoints as $ep):
                $checked = in_array($ep['route'], $disabled_endpoints, true);
            ?>
            <label style="display:flex;align-items:center;gap:8px;padding:3px 8px;cursor:pointer;border-radius:4px;font-size:12px;"
                   onmouseover="this.style.background='#f1f5f9'" onmouseout="this.style.background=''">
                <input type="checkbox" class="evk-rest-ep"
                       name="evk_security[disabled_rest_endpoints][]"
                       value="<?php echo esc_attr($ep['route']); ?>"
                       <?php checked($checked); ?>>
                <code style="background:#f0f0f1;padding:1px 5px;border-radius:3px;font-size:11px;"><?php echo esc_html($ep['route']); ?></code>
                <span style="color:#6b7280;font-size:11px;"><?php echo esc_html(implode(', ', $ep['methods'])); ?></span>
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

    <div class="evo-save-bar"><button type="submit" class="button button-primary">Zapisz</button><span class="evk-sec-saved" style="margin-left:10px;font-size:13px;color:#047857;display:none;">✓ Zapisano</span></div>
</form>
