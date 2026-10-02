<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — zakładka „Statystyki” (1.283.0): ogólny włącznik i ustawienia.
 * Raporty mają osobne menu (includes/statystyki/raport.php) — tu ich nie ma.
 */
$evk_st  = evk_stat_ustawienia();
$evk_on  = !empty($evk_st['enabled']);
?>
<div class="evk-stat-panel">
    <div class="evo-status-card">
        <div class="evo-status-icon <?php echo $evk_on ? 'on' : 'off'; ?>">
            <span class="dashicons dashicons-chart-area evo-ico-lg"></span>
        </div>
        <div class="evo-status-text">
            <h3>Statystyki: <?php echo $evk_on ? 'WŁĄCZONE' : 'WYŁĄCZONE'; ?></h3>
            <p>Odsłony, unikalni, źródła i urządzenia — bez cookies i bez baneru zgody.</p>
        </div>
        <div class="evo-status-actions">
            <label class="evo-toggle">
                <input aria-label="Statystyki" type="checkbox" data-option="evk_statystyki" data-field="enabled" value="1" <?php checked($evk_on); ?>>
                <span class="evo-slider"></span>
            </label>
        </div>
    </div>

    <?php if (!$evk_on): ?>
    <div class="evo-empty-panel evo-mt">
        <span class="dashicons dashicons-chart-area evo-ico-xl"></span>
        <p class="evo-muted evo-m0 evo-mt-xs">Wyłączone statystyki nie dodają do strony żadnego skryptu ani tabel w bazie. Po włączeniu przeładuj stronę — pojawią się ustawienia i menu <strong>Statystyki</strong>.</p>
    </div>
    <?php else: ?>

    <p class="evo-mt"><a class="button button-secondary" href="<?php echo esc_url(evk_stat_adres_raportu()); ?>"><span class="dashicons dashicons-chart-bar"></span> Otwórz raporty</a></p>

    <form id="evk-stat-form" class="evo-mt">
        <div class="evo-box">
            <h3>Kogo nie liczyć</h3>
            <div class="evo-stack evo-mb">
                <label class="evo-check-row"><input type="checkbox" name="wyklucz_role" value="1" <?php checked(1, $evk_st['wyklucz_role']); ?>>
                    Zalogowanych redaktorów i administratorów</label>
                <label class="evo-check-row"><input type="checkbox" name="dnt" value="1" <?php checked(1, $evk_st['dnt']); ?>>
                    Odwiedzających z sygnałem „nie śledź” (Do Not Track, Global Privacy Control)</label>
            </div>
            <div class="evo-field">
                <label for="evk-stat-ip">Adresy IP do pominięcia (adres albo sieć CIDR w linii)</label>
                <textarea id="evk-stat-ip" name="wyklucz_ip" rows="3" spellcheck="false"><?php echo esc_textarea((string) $evk_st['wyklucz_ip']); ?></textarea>
                <?php if (function_exists('evk_ip_klienta') && evk_ip_klienta() !== ''): ?>
                <p class="evo-desc">Twój adres teraz: <code><?php echo esc_html(evk_ip_klienta()); ?></code></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="evo-box">
            <h3>Dane i menu</h3>
            <div class="evo-field">
                <label for="evk-stat-retencja">Szczegółowe wpisy trzymaj przez</label>
                <select id="evk-stat-retencja" name="retencja">
                    <?php foreach (EVK_STAT_RETENCJE as $evk_d => $evk_n): ?>
                    <option value="<?php echo (int) $evk_d; ?>" <?php selected((int) $evk_st['retencja'], $evk_d); ?>><?php echo esc_html($evk_n); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="evo-desc">Podsumowania dzienne (odsłony, unikalni, strony, źródła…) zostają na zawsze.</p>
            </div>
            <div class="evo-field">
                <label for="evk-stat-menu">Menu „Statystyki”</label>
                <select id="evk-stat-menu" name="menu">
                    <?php foreach (EVK_STAT_MIEJSCA_MENU as $evk_m => $evk_n): ?>
                    <option value="<?php echo esc_attr($evk_m); ?>" <?php selected($evk_st['menu'], $evk_m); ?>><?php echo esc_html($evk_n); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="evo-desc">Raporty widzi administrator i role z uprawnieniem „Statystyki” (Panel admina → Role Manager).</p>
            </div>
        </div>

        <details class="evo-note"><summary>Jak to działa</summary><div class="evo-note-body">
            Skrypt (ok. 1 KB) wysyła odsłonę przy wczytaniu strony, a przy jej opuszczeniu czas i przewinięcie.
            Unikalnych odwiedzających rozpoznaje skrót adresu IP i przeglądarki z losową solą zmienianą o północy —
            bez cookies, bez zapisu pełnego adresu IP. Boty odpadają po nagłówku przeglądarki.
        </div></details>

        <?php evoke_one_pasek_zapisu('Zapisz', false, 'evk-stat-zapisano'); ?>
    </form>
    <script>
    jQuery(function ($) {
        $('#evk-stat-form').on('submit', function (e) {
            e.preventDefault();
            var f = this, dane = {};
            $(f).find('input[type=checkbox][name]').each(function () { dane[this.name] = this.checked ? 1 : 0; });
            $(f).find('select[name], textarea[name]').each(function () { dane[this.name] = $(this).val(); });
            $.post(ajaxurl, { action: 'evk_stat_zapisz', nonce: <?php echo wp_json_encode(wp_create_nonce('evk_stat')); ?>, dane: JSON.stringify(dane) })
                .done(function (r) {
                    if (r && r.success) { $(f).find('textarea[name=wyklucz_ip]').val(r.data.wyklucz_ip); $(f).find('.evk-stat-zapisano').addClass('is-widoczny'); }
                    else alert((r && r.data) || 'Błąd zapisu.');
                });
        });
    });
    </script>
    <?php endif; ?>
</div>
