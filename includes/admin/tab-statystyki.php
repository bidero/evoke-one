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

        <div class="evo-box">
            <h3>Zdarzenia</h3>
            <p class="evo-desc">Liczone automatycznie kliknięcia i wysłane formularze. Własne zdarzenie: atrybut <code>data-evk-zdarzenie="nazwa"</code> na elemencie albo formularzu — liczy się zawsze.</p>
            <div class="evo-stack">
                <?php foreach (['zd_tel' => 'Kliknięcia w telefon i e-mail (tel:, mailto:)', 'zd_pobrania' => 'Pobrania plików (PDF, ZIP, DOCX, XLSX…)',
                                'zd_wychodzace' => 'Linki wychodzące (do innych domen)', 'zd_formularze' => 'Wysłane formularze (Bricks, Contact Form 7 i zwykłe)'] as $evk_k => $evk_n): ?>
                <label class="evo-check-row"><input type="checkbox" name="<?php echo esc_attr($evk_k); ?>" value="1" <?php checked(1, $evk_st[$evk_k]); ?>> <?php echo esc_html($evk_n); ?></label>
                <?php endforeach; ?>
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

    <?php if ($evk_on): $evk_db = evk_stat_dbip_stan(); /* Kraje z DB-IP Lite (1.285.0). */ ?>
    <div class="evo-box evo-mt" id="evk-stat-dbip">
        <h3>Kraje odwiedzających</h3>
        <p class="evo-desc">Kraj z adresu IP według bazy <a href="https://db-ip.com" rel="noopener" target="_blank">DB-IP Lite</a> (CC BY 4.0). Strona pobiera ją sama raz w miesiącu (ok. 4,5 MB) i trzyma we własnej tabeli — adres IP nie wychodzi nigdzie.</p>
        <p class="evk-stat-dbip-stan" role="status"><?php
            if (is_array($evk_db['import'])) echo esc_html('Import w toku (' . $evk_db['import']['miesiac'] . ').');
            elseif ($evk_db['wersja'] !== '') echo esc_html(sprintf('Baza z %s: %s zakresów adresów.', $evk_db['wersja'], number_format_i18n((int) $evk_db['zakresy'])));
            else echo esc_html('Bazy jeszcze nie ma — pobierze się w nocy albo przyciskiem poniżej.');
            if ($evk_db['blad'] !== '') echo ' ' . esc_html('Ostatnia próba: ' . $evk_db['blad']);
        ?></p>
        <p><button type="button" class="button" id="evk-stat-dbip-pobierz">Pobierz bazę krajów teraz</button></p>
    </div>
    <script>
    jQuery(function ($) {
        var b = $('#evk-stat-dbip-pobierz'), st = $('#evk-stat-dbip .evk-stat-dbip-stan');
        function krok() {
            $.post(ajaxurl, { action: 'evk_stat_dbip', nonce: <?php echo wp_json_encode(wp_create_nonce('evk_stat')); ?> }).done(function (r) {
                if (!r || !r.success) { b.prop('disabled', false); st.text((r && r.data) || 'Błąd.'); return; }
                if (r.data.stan === 'import') { st.text('Importuję bazę krajów… ' + r.data.procent + '%'); krok(); return; }
                b.prop('disabled', false);
                st.text(r.data.stan === 'gotowe' ? 'Baza z ' + r.data.wersja + ': ' + r.data.zakresy + ' zakresów adresów.' : 'Błąd: ' + r.data.blad);
            }).fail(function () { b.prop('disabled', false); st.text('Błąd połączenia z serwerem.'); });
        }
        b.on('click', function () { b.prop('disabled', true); st.text('Pobieram bazę krajów…'); krok(); });
    });
    </script>
    <?php endif; ?>

    <?php if ($evk_on): /* Cele (1.285.0): wiersze z PHP — po zapisie strona się przeładowuje z nową pustą linią. */
        $evk_cele = evk_stat_cele();
        $evk_cele[] = ['id' => '', 'nazwa' => '', 'typ' => 'zdarzenie', 'wartosc' => ''];
    ?>
    <div class="evo-box evo-mt" id="evk-stat-cele">
        <h3>Cele</h3>
        <p class="evo-desc">Cel to zdarzenie (np. <code>formularz</code>, <code>tel</code>, <code>pobranie:cennik.pdf</code>, <code>wlasne:zapis</code>) albo wejście na adres (np. <code>/dziekujemy/</code>).
            Raport pokaże konwersję: ile wizyt osiągnęło cel, osobno dla źródła i kampanii. Pusta nazwa — wiersz pominięty.</p>
        <?php foreach ($evk_cele as $evk_i => $evk_c): ?>
        <div class="evo-grid evo-pola-rowne evk-stat-cel" style="--evo-col:180px;--evo-gap:12px" data-id="<?php echo esc_attr($evk_c['id']); ?>">
            <div class="evo-field">
                <label for="evk-cel-nazwa-<?php echo (int) $evk_i; ?>"><?php echo $evk_c['id'] === '' ? 'Nowy cel: nazwa' : 'Nazwa'; ?></label>
                <input type="text" id="evk-cel-nazwa-<?php echo (int) $evk_i; ?>" class="evk-cel-nazwa" value="<?php echo esc_attr($evk_c['nazwa']); ?>">
            </div>
            <div class="evo-field">
                <label for="evk-cel-typ-<?php echo (int) $evk_i; ?>">Rodzaj</label>
                <select id="evk-cel-typ-<?php echo (int) $evk_i; ?>" class="evk-cel-typ">
                    <option value="zdarzenie" <?php selected($evk_c['typ'], 'zdarzenie'); ?>>Zdarzenie</option>
                    <option value="adres" <?php selected($evk_c['typ'], 'adres'); ?>>Wejście na adres</option>
                </select>
            </div>
            <div class="evo-field">
                <label for="evk-cel-wartosc-<?php echo (int) $evk_i; ?>">Zdarzenie albo adres</label>
                <input type="text" id="evk-cel-wartosc-<?php echo (int) $evk_i; ?>" class="evk-cel-wartosc" value="<?php echo esc_attr($evk_c['wartosc']); ?>" spellcheck="false">
            </div>
            <?php if ($evk_c['id'] !== ''): ?>
            <label class="evo-check-row"><input type="checkbox" class="evk-cel-usun"> Usuń ten cel</label>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <p><button type="button" class="button button-primary" id="evk-stat-cele-zapisz">Zapisz cele</button> <span class="evk-stat-cele-stan" role="status"></span></p>
    </div>
    <script>
    jQuery(function ($) {
        $('#evk-stat-cele-zapisz').on('click', function () {
            var cele = [];
            $('#evk-stat-cele .evk-stat-cel').each(function () {
                var w = $(this);
                if (w.find('.evk-cel-usun').is(':checked')) return;
                cele.push({ id: w.data('id') || '', nazwa: w.find('.evk-cel-nazwa').val(), typ: w.find('.evk-cel-typ').val(), wartosc: w.find('.evk-cel-wartosc').val() });
            });
            $.post(ajaxurl, { action: 'evk_stat_cele', nonce: <?php echo wp_json_encode(wp_create_nonce('evk_stat')); ?>, cele: JSON.stringify(cele) }).done(function (r) {
                if (r && r.success) { $('.evk-stat-cele-stan').text('✓ Zapisano celów: ' + r.data.length + '.'); window.location.reload(); }
                else $('.evk-stat-cele-stan').text((r && r.data) || 'Błąd zapisu.');
            });
        });
    });
    </script>
    <?php endif; ?>

    <?php if ($evk_on): /* Hotspoty (1.286.0): lista nagrań i nowe nagranie — włącza i kasuje administrator. */
        $evk_hot = evk_stat_hot_nagrania();
    ?>
    <div class="evo-box evo-mt" id="evk-stat-hot">
        <h3>Hotspoty</h3>
        <p class="evo-desc">Mapa kliknięć i przewinięcia wybranych stron: gdzie odwiedzający klikają (osobno telefon, tablet i komputer), jak daleko przewijają,
            gdzie klikają ze złości i w co klikają bez skutku. Bez ruchu myszy i bez treści pól. Nagranie włączysz też z paska admina na stronie
            („Hotspoty → Nagrywaj tę stronę”). Dane zostają po zakończeniu — do usunięcia tutaj.</p>
        <?php if ($evk_hot): ?>
        <div class="evo-tbl-wrap"><table class="evo-table evk-hot-lista">
            <thead><tr><th scope="col">Strona</th><th scope="col">Stan</th><th scope="col" class="num">Wizyty</th><th scope="col">Akcje</th></tr></thead>
            <tbody>
            <?php foreach (array_keys($evk_hot) as $evk_s): $evk_s = (string) $evk_s; $evk_st = evk_stat_hot_stan($evk_s); if (!$evk_st) continue; ?>
            <tr data-strona="<?php echo esc_attr($evk_s); ?>">
                <td><code><?php echo esc_html($evk_s); ?></code></td>
                <td><?php echo esc_html($evk_st['nagrywa'] ? 'Nagrywa do ' . wp_date('j.m.Y', $evk_st['do']) : 'Zakończone ' . wp_date('j.m.Y', $evk_st['koniec'])); ?></td>
                <td class="num"><?php echo esc_html($evk_st['wizyty'] . ' / ' . $evk_st['limit']); ?></td>
                <td class="evk-hot-akcje">
                    <a class="button" href="<?php echo esc_url(evk_stat_hot_adres_podgladu($evk_s)); ?>">Pokaż</a>
                    <?php if ($evk_st['nagrywa']): ?>
                    <a class="button" href="<?php echo esc_url(evk_stat_hot_adres_akcji('stop', $evk_s)); ?>">Zatrzymaj</a>
                    <?php endif; ?>
                    <a class="button button-link-delete evk-hot-usun" href="<?php echo esc_url(evk_stat_hot_adres_akcji('usun', $evk_s)); ?>">Usuń dane</a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="evk-hot-nowe">
            <input type="hidden" name="action" value="evk_stat_hot">
            <input type="hidden" name="akcja" value="start">
            <?php wp_nonce_field('evk_stat_hot'); ?>
            <div class="evo-grid evo-pola-rowne" style="--evo-col:180px;--evo-gap:12px">
                <div class="evo-field">
                    <label for="evk-hot-strona">Adres strony</label>
                    <input type="text" id="evk-hot-strona" name="strona" placeholder="/oferta/" spellcheck="false" required>
                </div>
                <div class="evo-field">
                    <label for="evk-hot-dni">Dni</label>
                    <input type="number" id="evk-hot-dni" name="dni" min="1" max="365" value="<?php echo (int) EVK_STAT_HOT_DNI; ?>">
                </div>
                <div class="evo-field">
                    <label for="evk-hot-wizyty">Albo wizyt</label>
                    <input type="number" id="evk-hot-wizyty" name="wizyty" min="1" value="<?php echo (int) EVK_STAT_HOT_WIZYTY; ?>">
                </div>
            </div>
            <label class="evo-check-row"><input type="checkbox" name="od_nowa" value="1"> Zacznij od zera (usuń dotychczasowe dane tej strony)</label>
            <p><button type="submit" class="button button-primary">Nagrywaj</button></p>
        </form>
    </div>
    <script>
    jQuery(function ($) {
        $('#evk-stat-hot .evk-hot-usun').on('click', function (e) {
            if (!window.confirm('Usunąć hotspoty strony ' + $(this).closest('tr').data('strona') + '? Tego nie da się cofnąć.')) e.preventDefault();
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
