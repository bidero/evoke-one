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
        <div class="evo-stack evo-mb-lg">
            <label class="evo-choice evo-choice-stack">
                <input type="checkbox" name="evk_security[hide_wp_version]" value="1"
                       <?php checked(1, $evk_sec['hide_wp_version']); ?>>
                <span>
                    <strong>Ukryj wersję WordPress</strong>
                    <span class="evo-hint">
                        Usuwa numer wersji z kodu HTML, RSS, nagłówków HTTP oraz query stringów assetów.
                    </span>
                </span>
            </label>
            <label class="evo-choice evo-choice-stack">
                <input type="checkbox" name="evk_security[disable_bundled_themes]" value="1"
                       <?php checked(1, $evk_sec['disable_bundled_themes']); ?>>
                <span>
                    <strong>Wyłącz aktualizację motywów dołączonych do WP (Twenty*)</strong>
                    <span class="evo-hint">
                        Zapobiega automatycznej instalacji/aktualizacji domyślnych motywów podczas aktualizacji rdzenia.
                    </span>
                </span>
            </label>
        </div>
    </div>

<?php evoke_one_pasek_zapisu('Zapisz', false, 'evk-sec-saved'); ?>
</form>
