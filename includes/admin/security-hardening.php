<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Bezpieczeństwo: Ochrona WP
 * Ukryj wersję WP, wyłącz motywy bundled
 */
?>
<form id="evk-sec-form-hardening" data-section="hardening">

    <div class="evo-box">
        <h3>WordPress</h3>
        <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:24px;">
            <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;font-weight:500;cursor:pointer;">
                <input type="checkbox" name="evk_security[hide_wp_version]" value="1"
                       <?php checked(1, $evk_sec['hide_wp_version']); ?> style="margin-top:2px;">
                <span>
                    Ukryj wersję WordPress
                    <span style="display:block;font-weight:400;color:#6b7280;font-size:12px;margin-top:2px;">
                        Usuwa numer wersji z kodu HTML, RSS, nagłówków HTTP oraz query stringów assetów.
                    </span>
                </span>
            </label>
            <label style="display:flex;align-items:flex-start;gap:10px;font-size:13px;font-weight:500;cursor:pointer;">
                <input type="checkbox" name="evk_security[disable_bundled_themes]" value="1"
                       <?php checked(1, $evk_sec['disable_bundled_themes']); ?> style="margin-top:2px;">
                <span>
                    Wyłącz aktualizację motywów dołączonych do WP (Twenty*)
                    <span style="display:block;font-weight:400;color:#6b7280;font-size:12px;margin-top:2px;">
                        Zapobiega automatycznej instalacji/aktualizacji domyślnych motywów podczas aktualizacji rdzenia.
                    </span>
                </span>
            </label>
        </div>

    
    </div>

<div class="evo-save-bar"><button type="submit" class="button button-primary">Zapisz</button><span class="evk-sec-saved" style="margin-left:10px;font-size:13px;color:#047857;display:none;">✓ Zapisano</span></div>
</form>
