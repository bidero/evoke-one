<?php if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Admin: Logi 404
 */

/*
 * Obsługa formularza ustawień.
 *
 * `evk_404_enabled` NIE JEST TU ZAPISYWANE, i to jest cała treść zmiany
 * z 1.166.0. Włącznik jedzie AJAX-em, więc formularz nie ma prawa go dotykać:
 * wartość wpisana w ukryte pole przy renderze strony jest KOPIĄ SPRZED
 * przełączenia i nadpisałaby to, co ustawił AJAX. Ta sama klasa błędu co
 * w 1.14.4, tylko odwrócona — wtedy zapis ustawień gasił moduł, bo klucza
 * w POST brakowało; teraz gasiłby go, bo klucz jest, ale nieaktualny.
 *
 * Jedna opcja — jeden sterownik.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['evk_404_save'])
    && check_admin_referer('evk_404_save_action')) {
    update_option('evk_404_max_logs',  max(10, absint($_POST['evk_404_max_logs']  ?? 200)));
    update_option('evk_404_skip_bots', !empty($_POST['evk_404_skip_bots']) ? 1 : 0);
    update_option('evk_404_bot_list',  sanitize_textarea_field($_POST['evk_404_bot_list'] ?? ''));
    /* Potwierdzenie stoi przy przycisku, jak w całym panelu (1.167.0). Czarne
       powiadomienie u góry mówiło to samo, tylko gdzie indziej. */
    $evk_404_zapisano = true;
}

$enabled   = evk_404_is_enabled();
/* Tabela i przeniesienie wpisów sprzed 1.234.0 przy pierwszym otwarciu
   (albo pierwszym trafieniu 404) po aktualizacji — przy włączonym module. */
if ($enabled) evk_404_maybe_upgrade();
$max_logs  = evk_404_max_logs();
$skip_bots = evk_404_skip_bots();
$bot_list  = implode("\n", evk_404_bot_list());
$nonce_ajax = wp_create_nonce('evk_tools_nonce');
?>

<!-- Status card -->
<div class="evo-status-card">
    <div class="evo-status-icon <?php echo $enabled ? 'on' : 'off'; ?>">
        <span class="dashicons dashicons-warning evo-ico-lg"></span>
    </div>
    <div class="evo-status-text">
        <h3>Logi 404: <?php echo $enabled ? 'WŁĄCZONE' : 'WYŁĄCZONE'; ?></h3>
        <p>Zapisuje nieistniejące adresy: ile razy ktoś w nie wszedł, kiedy ostatnio i skąd. Jeden adres to jeden wiersz, a z jednego IP najwyżej <?php echo (int) EVK_404_LIMIT_NA_MINUTE; ?> adresów na minutę — skaner nie zaleje bazy.</p>
    </div>
    <?php /* Bez formularza i bez ukrytych kopii pozostałych ustawień. Włącznik
             przełączał się przeładowaniem, więc musiał wieźć ze sobą wszystkie
             inne pola, żeby ich nie wyzerować — czyli w jednym kliknięciu
             przepisywał cztery opcje, z których trzech nie dotyczył. */ ?>
    <div class="evo-status-actions">
        <span class="evo-toggle-label"><?php echo $enabled ? 'Włączone' : 'Wyłączone'; ?></span>
        <label class="evo-toggle">
            <input type="checkbox"
                   data-option="evk_404_enabled"
                   data-field="_scalar"
                   value="1"
                   <?php checked($enabled); ?>>
            <span class="evo-slider"></span>
        </label>
    </div>
</div>

<!-- Ustawienia -->
<form method="post" class="evo-mt-lg">
    <?php wp_nonce_field('evk_404_save_action'); ?>
    <input type="hidden" name="evk_404_save" value="1">
    <?php /* Ukryte pole `evk_404_enabled` stało tu do 1.166.0 i wiozło stan
             włącznika, bo handler zerował brakujący klucz w POST. Handler już
             tej opcji nie dotyka, więc pole jest zbędne — a przy włączniku
             AJAX-owym byłoby wręcz szkodliwe: niosłoby wartość sprzed
             przełączenia. */ ?>

    <?php /* Ustawienia w pudełku, jak na każdym innym ekranie. Do 1.139.7
             wisiały luzem w karcie — stąd zgłoszenie „brakuje delikatnej,
             zaokrąglonej ramki": nie brakowało jej w stylach, tylko nie było
             czego nią otoczyć. */ ?>
    <div class="evo-box">
        <h3>Ustawienia rejestrowania</h3>
    <div class="evo-toolbar evo-mb" style="--evo-gap:24px">
        <div class="evo-field evo-inline evo-m0" style="--evo-gap:8px">
            <label class="evo-nowrap evo-m0" for="evk-404-max">Maks. adresów:</label>
            <input type="number" id="evk-404-max" name="evk_404_max_logs" value="<?php echo esc_attr($max_logs); ?>" min="10" max="5000" class="evo-w" style="--evo-w:90px">
        </div>
        <label class="evo-check">
            <input type="checkbox" name="evk_404_skip_bots" value="1" <?php checked($skip_bots); ?>>
            Ignoruj boty / roboty
        </label>
    </div>

    <?php if ($skip_bots): ?>
    <div class="evo-field">
        <label>Lista botów (jeden per linia)</label>
        <textarea name="evk_404_bot_list" rows="4" class="evo-w-full evo-mono evo-tbl-sm"><?php echo esc_textarea($bot_list); ?></textarea>
    </div>
    <?php endif; ?>

    <?php /* `primary`, nie `secondary`: to jest główna akcja tego ekranu,
             a wyglądała na drugorzędną — jedyny taki zapis w panelu.
             Kosz z ikony `dashicons-trash`, tej samej co w Animatorze,
             Kursorze i Fragmentach kodu, zamiast emoji. */ ?>
    <div class="evo-save-bar evo-toolbar" style="--evo-gap:12px">
        <?php submit_button('Zapisz ustawienia', 'primary', 'evk_404_save', false); ?>
        <?php evoke_one_komunikat_zapisu(!empty($evk_404_zapisano)); ?>
        <button type="button" class="button" id="evk-clear-404" data-nonce="<?php echo esc_attr($nonce_ajax); ?>"><span class="dashicons dashicons-trash evo-ico"></span> Wyczyść wszystkie logi</button>
        <span id="evk-clear-404-msg" class="evo-save-msg">Wyczyszczono.</span>
    </div>
    </div>
</form>

<?php
/* Najnowsze na górze, jak w każdym logu. W 1.234.0 lista szła od
   najczęstszych, więc świeże 404 z jednym wejściem lądowało na samym dole
   i wyglądało, jakby nic się nie dopisywało. „Najczęstsze" zostają pod
   przełącznikiem — do wybierania, co przekierować. */
$sort    = (isset($_GET['sort']) && $_GET['sort'] === 'wejscia') ? 'wejscia' : 'najnowsze';
$wiersze = evk_404_tabela_gotowa()
    ? ($GLOBALS['wpdb']->get_results('SELECT id, url, hits, first_seen, last_seen, referrer, ip, ua FROM ' . evk_404_table()
        . ($sort === 'wejscia' ? ' ORDER BY hits DESC, last_seen DESC' : ' ORDER BY last_seen DESC, id DESC')
        . ' LIMIT ' . (int) $max_logs, ARRAY_A) ?: [])
    : [];
if (!empty($wiersze)):
?>
<div class="evo-box">
    <?php /* Nagłówek zostaje `h3` wprost w boksie (wzorzec panelu, pilnuje
             admin-tabs); przełącznik kolejności to dopisek w nim. */ ?>
    <h3 class="evo-row-between">
        <span>Nieistniejące adresy <span class="evo-hint">(<?php echo count($wiersze); ?>)</span></span>
        <span class="evo-box-note" data-evk-404-sortowanie>Kolejność:
            <?php if ($sort === 'najnowsze'): ?>
            <strong>najnowsze</strong> · <a href="<?php echo esc_url(add_query_arg('sort', 'wejscia')); ?>">najczęstsze</a>
            <?php else: ?>
            <a href="<?php echo esc_url(remove_query_arg('sort')); ?>">najnowsze</a> · <strong>najczęstsze</strong>
            <?php endif; ?>
        </span>
    </h3>
    <?php if (!evk_301_is_enabled()): ?>
    <div class="evo-info-box is-warn evo-mb" data-evk-404-301-wylaczone>
        <span class="dashicons dashicons-warning evo-warn-tx"></span>
        <div>Przekierowania 301 są wyłączone. Utworzone tu przekierowania zaczną działać po włączeniu modułu
            <a href="<?php echo esc_url(add_query_arg(['tab' => 'narzedzia', 'sub' => 'redirect'], admin_url('options-general.php?page=evoke-one'))); ?>">Przekierowania 301</a>.</div>
    </div>
    <?php endif; ?>
    <?php /* Kolumny o stałej szerokości, adres zabiera resztę (`evo-tbl-fixed`
             + `<colgroup>`). W 1.234.0 układ był automatyczny, a przeglądarka
             w `nowrap` rozpychała tabelę do szerokości całego tekstu: przy
             oknie 1280 tabela miała 1282 px w pudełku na 748, adres łamał się
             co cztery znaki (wiersze do 513 px), a kolumna z „Przekieruj"
             wypadała poza pudełko. „Skąd" siedzi pod adresem, przeglądarka pod
             IP — obie ucięte, pełna treść w podpowiedzi. `min-width`: na
             telefonie tabela przewija się w `.evo-tbl-wrap`, zamiast ściskać
             adres do zera. */ ?>
    <div class="evo-tbl-wrap">
    <table class="evo-tbl evo-tbl-sm evo-tbl-fixed" style="min-width:640px" data-evk-404-tabela>
        <colgroup>
            <col>
            <col style="width:76px">
            <col style="width:140px">
            <col style="width:170px">
            <col style="width:146px">
        </colgroup>
        <thead><tr>
            <th>Adres</th>
            <th class="evo-center">Wejścia</th>
            <th>Ostatnio</th>
            <th>IP i przeglądarka</th>
            <th>Akcja</th>
        </tr></thead>
        <tbody>
        <?php foreach ($wiersze as $w): ?>
        <tr data-evk-404-wiersz="<?php echo (int) $w['id']; ?>">
            <td>
                <code class="evo-mono-xs evo-break" data-evk-404-adres><?php echo esc_html($w['url']); ?></code>
                <?php if ($w['referrer'] !== ''): ?>
                <div class="evo-hint-sm evo-ellipsis" title="<?php echo esc_attr($w['referrer']); ?>">z: <?php echo esc_html($w['referrer']); ?></div>
                <?php endif; ?>
            </td>
            <td class="evo-center evo-strong"><?php echo (int) $w['hits']; ?></td>
            <td class="evo-nowrap" title="<?php echo esc_attr('Pierwszy raz: ' . wp_date('Y-m-d H:i', (int) $w['first_seen'])); ?>"><?php echo esc_html(wp_date('Y-m-d H:i', (int) $w['last_seen'])); ?></td>
            <td>
                <div class="evo-ellipsis">
                <?php if ($w['ip'] !== ''): ?>
                <a href="https://radar.cloudflare.com/ip/<?php echo esc_attr($w['ip']); ?>" target="_blank" rel="noopener" class="evo-hint-sm"><?php echo esc_html($w['ip']); ?></a>
                <?php else: ?>—<?php endif; ?>
                </div>
                <div class="evo-hint-sm evo-ellipsis" title="<?php echo esc_attr($w['ua']); ?>"><?php echo esc_html($w['ua'] !== '' ? $w['ua'] : '—'); ?></div>
            </td>
            <td class="evk-404-akcja">
                <?php if (evk_404_da_sie_przekierowac($w['url'])): ?>
                <button type="button" class="button button-small" data-evk-404-przekieruj>Przekieruj</button>
                <?php /* Samo `hidden`, bez klasy układu: `.evo-inline` ma
                         `display: flex`, a to wygrywa z atrybutem `hidden` —
                         w 1.234.0 pole celu stało otwarte w każdym wierszu. */ ?>
                <div data-evk-404-cel hidden>
                    <input type="text" class="evo-w-full" placeholder="/nowy-adres"
                           aria-label="<?php echo esc_attr('Przekieruj ' . $w['url'] . ' na adres'); ?>">
                    <div class="evo-inline" style="--evo-gap:6px;margin-top:6px">
                        <button type="button" class="button button-small button-primary" data-evk-404-zapisz>Zapisz</button>
                        <button type="button" class="button button-small" data-evk-404-anuluj>Anuluj</button>
                    </div>
                </div>
                <span class="evo-hint-sm evo-danger-tx" data-evk-404-blad role="alert"></span>
                <?php else: ?>
                <span class="evo-hint-sm evo-faint" data-evk-404-bez-przekierowania
                      title="Przekierowania działają po ścieżce, a tutaj 404 robi zapytanie (?…) — reguła przekierowałaby stronę główną.">bez przekierowania</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php elseif ($enabled): ?>
<div class="evo-info-box evo-mt-lg">
    <span class="dashicons dashicons-yes-alt"></span>
    <div>Brak zarejestrowanych błędów 404. Pojawią się tutaj gdy ktoś wejdzie na nieistniejący URL.</div>
</div>
<?php endif; ?>

<script>
(function($){
    /* „Przekieruj" w wierszu: pole celu, zapis przez evk_404_przekieruj
       (przekierowanie 301 + usunięcie wiersza z logu). */
    var nonce404 = <?php echo wp_json_encode($nonce_ajax); ?>;
    function wiersz(el){ return $(el).closest('tr'); }
    $(document).on('click', '[data-evk-404-przekieruj]', function(){
        var tr = wiersz(this);
        $(this).hide();
        tr.find('[data-evk-404-cel]').prop('hidden', false).find('input').trigger('focus');
    });
    $(document).on('click', '[data-evk-404-anuluj]', function(){
        var tr = wiersz(this);
        tr.find('[data-evk-404-cel]').prop('hidden', true);
        tr.find('[data-evk-404-blad]').text('');
        tr.find('[data-evk-404-przekieruj]').show();
    });
    $(document).on('keydown', '[data-evk-404-cel] input', function(e){
        if (e.key === 'Enter') { e.preventDefault(); wiersz(this).find('[data-evk-404-zapisz]').trigger('click'); }
    });
    $(document).on('click', '[data-evk-404-zapisz]', function(){
        var tr = wiersz(this), btn = $(this), cel = $.trim(tr.find('[data-evk-404-cel] input').val());
        if (!cel) { tr.find('[data-evk-404-blad]').text('Podaj adres docelowy.'); return; }
        btn.prop('disabled', true);
        $.post(ajaxurl, {action: 'evk_404_przekieruj', nonce: nonce404, id: tr.data('evk-404-wiersz'), to: cel}, function(r){
            if (r && r.success) {
                tr.addClass('evo-faint').find('.evk-404-akcja').empty()
                  .append($('<span class="evo-hint-sm" data-evk-404-przekierowano></span>').text('Przekierowano → ' + r.data.na));
            } else {
                tr.find('[data-evk-404-blad]').text((r && r.data) ? r.data : 'Nie udało się zapisać.');
            }
        }).fail(function(){
            tr.find('[data-evk-404-blad]').text('Błąd połączenia — spróbuj jeszcze raz.');
        }).always(function(){ btn.prop('disabled', false); });
    });

    $('#evk-clear-404').on('click', function(){
        if (!confirm('Wyczyścić wszystkie logi 404?')) return;
        var btn = $(this).prop('disabled', true).text('...');
        $.post(ajaxurl, {action:'evk_clear_404_logs', nonce:$(this).data('nonce')}, function(r){
            if (r.success){
                /* Selektor idzie za klasą tabeli. Gdy w 1.139.9 odeszła klasa
                   rdzenia, ten wiersz przestałby cokolwiek czyścić — po
                   „Wyczyść logi" tabela zostawałaby na ekranie z nieistniejącymi
                   już wpisami. Usterka bez śladu w wyglądzie. */
                $('table.evo-tbl tbody').empty();
                $('#evk-clear-404-msg').show();
                // Cała sekcja jest jednym boksem, więc chowamy boks — wcześniej
                // trzeba było zgadywać jego części z osobna (tabela, kreska,
                // tytuł) i przy zmianie znaczników się rozjeżdżało.
                $('table.evo-tbl').closest('.evo-box').hide();
            }
        }).always(function(){ btn.prop('disabled', false).html('<span class="dashicons dashicons-trash evo-ico"></span> Wyczyść wszystkie logi'); });
    });
})(jQuery);
</script>
