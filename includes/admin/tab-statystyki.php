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
            <label class="evo-check-row"><input type="checkbox" name="licznik" value="1" <?php checked(1, $evk_st['licznik']); ?>>
                Licznik w pasku admina na stronie: odsłony tej strony dziś / 30 dni</label>
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

    <?php /* Kasowanie za okres (1.285.0): widać je, gdy są tabele — także przy wyłączonym module. */ ?>
    <?php if ((int) get_option('evk_stat_db_version', 0) > 0): ?>
    <div class="evo-box evo-mt" id="evk-stat-usun">
        <h3>Usuń statystyki</h3>
        <p class="evo-desc">Kasuje odsłony i podsumowania dzienne z wybranych dni (włącznie). Tego nie da się cofnąć.</p>
        <div class="evo-grid evo-pola-rowne" style="--evo-col:180px;--evo-gap:16px">
            <div class="evo-field">
                <label for="evk-stat-usun-od">Od</label>
                <input type="date" id="evk-stat-usun-od" value="<?php echo esc_attr(wp_date('Y-m-d', time() - 6 * DAY_IN_SECONDS)); ?>">
            </div>
            <div class="evo-field">
                <label for="evk-stat-usun-do">Do</label>
                <input type="date" id="evk-stat-usun-do" value="<?php echo esc_attr(wp_date('Y-m-d')); ?>">
            </div>
        </div>
        <p class="evo-toolbar">
            <button type="button" class="button" data-evk-usun="okres">Usuń z tego okresu</button>
            <button type="button" class="button button-link-delete" data-evk-usun="wszystko">Usuń wszystkie statystyki</button>
        </p>
        <p class="evk-stat-usun-stan" role="status"></p>
    </div>
    <script>
    jQuery(function ($) {
        var nonce = <?php echo wp_json_encode(wp_create_nonce('evk_stat')); ?>, stan = $('#evk-stat-usun .evk-stat-usun-stan');
        $('#evk-stat-usun [data-evk-usun]').on('click', function () {
            var wszystko = $(this).data('evk-usun') === 'wszystko', b = $(this);
            var dane = { action: 'evk_stat_usun', nonce: nonce, wszystko: wszystko ? 1 : '', od: $('#evk-stat-usun-od').val(), 'do': $('#evk-stat-usun-do').val() };
            b.prop('disabled', true);
            $.post(ajaxurl, $.extend({ licz: 1 }, dane)).done(function (r) {
                if (!r || !r.success) { b.prop('disabled', false); stan.text((r && r.data) || 'Błąd.'); return; }
                var co = wszystko ? 'WSZYSTKIE statystyki' : 'statystyki z dni ' + dane.od + ' – ' + dane['do'];
                if (!window.confirm('Usunąć ' + co + ' (' + r.data.ile + ' odsłon)? Tego nie da się cofnąć.')) { b.prop('disabled', false); stan.text('Anulowane.'); return; }
                $.post(ajaxurl, dane).done(function (r2) {
                    b.prop('disabled', false);
                    stan.text(r2 && r2.success ? 'Usunięto ' + r2.data.ile + ' odsłon.' : ((r2 && r2.data) || 'Błąd.'));
                });
            });
        });
    });
    </script>
    <?php endif; ?>
</div>
