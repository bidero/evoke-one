<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Bezpieczeństwo: Nagłówki (1.280.0, security/naglowki.php).
 * Zapis przez wspólny AJAX zakładki (sekcja `naglowki`).
 */
/* `$evk_sec` daje zakładka (tab-bezpieczenstwo.php); bez niej — wprost z ustawień. */
$evk_sec = isset($evk_sec) && is_array($evk_sec) ? $evk_sec : evk_security_get();
$evk_upr = (array) $evk_sec['hdr_uprawnienia'];
?>
<form id="evk-sec-form-naglowki" data-section="naglowki">

    <div class="evo-box">
        <h3>Nagłówki bezpieczeństwa</h3>
        <p class="evo-desc">Przeglądarka dostaje je z każdą stroną, panelem i logowaniem. Wysyła je Evoke — gdy serwer (hosting, Cloudflare) ustawia te same, wyłącz je tutaj.</p>
        <div class="evo-stack evo-mb-lg">
            <label class="evo-choice evo-choice-stack">
                <input type="checkbox" name="evk_security[hdr_nosniff]" value="1" <?php checked(1, $evk_sec['hdr_nosniff']); ?>>
                <span>
                    <strong>Bez zgadywania typu plików</strong> <code>X-Content-Type-Options: nosniff</code>
                    <span class="evo-hint">Przeglądarka nie uruchomi pliku jako skryptu, jeśli serwer podał inny typ.</span>
                </span>
            </label>
            <label class="evo-choice evo-choice-stack">
                <input type="checkbox" name="evk_security[hdr_ramki]" value="1" <?php checked(1, $evk_sec['hdr_ramki']); ?>>
                <span>
                    <strong>Ramki tylko z tej strony</strong> <code>frame-ancestors 'self'</code>, <code>X-Frame-Options: SAMEORIGIN</code>
                    <span class="evo-hint">Obca strona nie osadzi tej w &lt;iframe&gt; (clickjacking). Builder Bricksa i podgląd działają dalej.</span>
                </span>
            </label>
            <label class="evo-choice evo-choice-stack">
                <input type="checkbox" name="evk_security[hdr_referrer]" value="1" <?php checked(1, $evk_sec['hdr_referrer']); ?>>
                <span>
                    <strong>Ograniczony adres odsyłający</strong> <code>Referrer-Policy</code>
                    <span class="evo-hint">Inne strony nie dostaną pełnego adresu podstrony, z której przyszedł odwiedzający.</span>
                </span>
            </label>
        </div>
        <div class="evo-field">
            <label for="evk-hdr-referrer">Referrer-Policy</label>
            <select id="evk-hdr-referrer" name="evk_security[hdr_referrer_wartosc]">
                <?php foreach (EVK_NAGLOWKI_REFERRER as $evk_w): ?>
                <option value="<?php echo esc_attr($evk_w); ?>" <?php selected($evk_sec['hdr_referrer_wartosc'], $evk_w); ?>><?php echo esc_html($evk_w . ($evk_w === EVK_NAGLOWKI_REFERRER[0] ? ' (zalecane)' : '')); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="evo-box">
        <h3>Funkcje przeglądarki</h3>
        <label class="evo-choice evo-choice-stack evo-mb-sm">
            <input type="checkbox" name="evk_security[hdr_permissions]" value="1" <?php checked(1, $evk_sec['hdr_permissions']); ?>>
            <span>
                <strong>Blokuj zaznaczone funkcje</strong> <code>Permissions-Policy</code>
                <span class="evo-hint">Ani strona, ani osadzony na niej cudzy kod nie poprosi o nie odwiedzającego.</span>
            </span>
        </label>
        <div class="evo-stack">
            <?php foreach (EVK_NAGLOWKI_UPRAWNIENIA as $evk_k => $evk_n): ?>
            <label class="evo-check-row"><input type="checkbox" name="evk_security[hdr_uprawnienia][]" value="<?php echo esc_attr($evk_k); ?>" <?php checked(in_array($evk_k, $evk_upr, true)); ?>>
                <?php echo esc_html($evk_n); ?> <code><?php echo esc_html($evk_k); ?>=()</code></label>
            <?php endforeach; ?>
        </div>
        <?php if (class_exists('WooCommerce')): ?>
        <p class="evo-desc">WooCommerce: blokada płatności wyłącza przyciski Apple Pay i Google Pay — dlatego domyślnie jest odznaczona.</p>
        <?php endif; ?>
    </div>

    <div class="evo-box">
        <h3>HTTPS na stałe (HSTS)</h3>
        <label class="evo-choice evo-choice-stack evo-mb-sm">
            <input type="checkbox" name="evk_security[hdr_hsts]" value="1" <?php checked(1, $evk_sec['hdr_hsts']); ?>>
            <span>
                <strong>Strict-Transport-Security</strong>
                <span class="evo-hint">Przeglądarka przez wybrany czas łączy się z tą domeną WYŁĄCZNIE przez HTTPS — także gdy certyfikat wygaśnie.
                Zacznij od krótkiego czasu. Idzie tylko przez HTTPS<?php echo is_ssl() ? '' : ' (ta strona jest teraz otwarta przez HTTP, więc nagłówek nie pójdzie)'; ?>.</span>
            </span>
        </label>
        <div class="evo-field">
            <label for="evk-hdr-hsts-wiek">Czas</label>
            <select id="evk-hdr-hsts-wiek" name="evk_security[hdr_hsts_wiek]">
                <?php foreach (EVK_NAGLOWKI_HSTS as $evk_s => $evk_n): ?>
                <option value="<?php echo (int) $evk_s; ?>" <?php selected((int) $evk_sec['hdr_hsts_wiek'], $evk_s); ?>><?php echo esc_html($evk_n); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <label class="evo-check-row"><input type="checkbox" name="evk_security[hdr_hsts_sub]" value="1" <?php checked(1, $evk_sec['hdr_hsts_sub']); ?>>
            Także subdomeny (<code>includeSubDomains</code>) — tylko gdy KAŻDA subdomena ma HTTPS</label>
    </div>

<?php evoke_one_pasek_zapisu('Zapisz', false, 'evk-sec-saved'); ?>
</form>
