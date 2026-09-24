<?php
if (!defined('ABSPATH')) exit;

$lists       = evk_nl_get_lists();
$nonce       = wp_create_nonce('evk_nl_nonce');
$active_list = (int) ($_GET['list_id'] ?? ($lists[0]['id'] ?? 0));
$base_url    = add_query_arg('subtab', 'lists', evk_nl_base_url());
?>

<div class="evk-nl-split">

    <!-- PANEL LEWY: listy -->
    <div>
        <div class="evk-nl-card" style="margin-bottom:14px;">
            <div class="evk-nl-card-head">
                <strong>Listy</strong>
                <button class="button button-small" id="evk-nl-add-list-btn">+ Nowa</button>
            </div>
            <?php if (empty($lists)): ?>
            <p class="evk-nl-muted">Brak list.</p>
            <?php else: ?>
            <?php foreach ($lists as $list):
                $count     = evk_nl_list_count((int) $list['id']);
                $is_active = (int) $list['id'] === $active_list;
            ?>
            <a href="<?php echo esc_url(add_query_arg('list_id', $list['id'], $base_url)); ?>"
               class="evk-nl-list-item<?php echo $is_active ? ' is-active' : ''; ?>"
>
                <span class="evk-nl-ellipsis"><?php echo esc_html($list['name']); ?></span>
                <span class="evk-nl-badge-count"><?php echo esc_html($count); ?></span>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php /* Lista wykluczeń (1.233.0). Wpisy dociąga JS (evk_nl_wykluczenia),
                 jak tabelę subskrybentów — ta sama droga przy pierwszym
                 wyświetleniu i po każdej zmianie. */ ?>
        <div class="evk-nl-card" id="evk-nl-wykluczenia" style="margin-bottom:14px;">
            <div class="evk-nl-card-head">
                <strong>Lista wykluczeń</strong>
                <span class="evk-nl-badge-count" id="evk-nl-wyk-licznik">…</span>
            </div>
            <div class="evk-nl-card-body">
                <p class="evo-hint" style="margin:0 0 8px">Import nigdy nie dopisuje tych adresów ani osób wypisanych z dowolnej listy czy usuniętych na żądanie RODO. Adres dopisany tutaj przestaje dostawać maile ze wszystkich list; adres odrzucony przy wysyłce („skrzynka nie istnieje") trafia tu sam.</p>
                <label for="evk-nl-wyk-adresy" class="evk-nl-label">Dodaj adresy</label>
                <textarea id="evk-nl-wyk-adresy" rows="2" class="evo-w-full" placeholder="adres@example.com"></textarea>
                <div class="evo-inline" style="--evo-gap:8px;margin-top:6px;flex-wrap:wrap">
                    <button type="button" class="button button-small" id="evk-nl-wyk-dodaj">Dodaj do wykluczeń</button>
                    <span id="evk-nl-wyk-msg" class="evo-hint" role="status" aria-live="polite"></span>
                </div>
                <ul id="evk-nl-wyk-lista" class="evk-nl-wyk-lista" style="margin:8px 0 0;padding:0;list-style:none;max-height:220px;overflow:auto"></ul>
            </div>
        </div>

        <!-- Formularz nowej/edycji listy -->
        <div id="evk-nl-list-form" class="evk-nl-hidden evk-nl-card">
            <div class="evk-nl-card-body">
                <input type="hidden" id="evk-nl-list-id" value="0">
                <p class="evk-nl-h-sm" id="evk-nl-list-form-title">Nowa lista</p>
                <label class="evk-nl-label">Nazwa listy</label>
                <input type="text" id="evk-nl-list-name" class="evo-w-full" placeholder="np. Klienci 2025">

                <div class="evo-inline" style="--evo-gap:6px">
                    <button class="button button-primary button-small" id="evk-nl-save-list-btn">Zapisz</button>
                    <button class="button button-small" id="evk-nl-cancel-list-btn">Anuluj</button>
                </div>
                <div id="evk-nl-list-msg" class="evo-hint" style="margin-top:6px"></div>
            </div>
        </div>
    </div>

    <!-- PANEL PRAWY: subskrybenci -->
    <div>
        <?php if ($active_list && ($current_list = evk_nl_get_list($active_list))): ?>
        <div class="evk-nl-card" style="margin-bottom:14px;">
            <div class="evk-nl-card-head" style="flex-wrap:wrap;--evo-gap:8px">
                <div class="evo-inline" style="--evo-gap:10px">
                    <strong style="font-size:14px;"><?php echo esc_html($current_list['name']); ?></strong>
                </div>
                <div class="evo-inline" style="--evo-gap:6px">
                    <button class="button button-small evk-nl-edit-list-btn"
                            data-id="<?php echo (int) $current_list['id']; ?>"
                            data-name="<?php echo esc_attr($current_list['name']); ?>"
                            data-fields="<?php echo esc_attr($current_list['fields_config'] ?? '[]'); ?>">Edytuj</button>
                    <button class="button button-small evk-nl-delete-list-btn evo-btn-danger"
                            data-id="<?php echo (int) $current_list['id']; ?>"
                           >Usuń listę</button>
                </div>
            </div>

            <!-- Inline formularz edycji listy -->
            <div id="evk-nl-edit-inline" class="evk-nl-hidden evk-nl-hr">
                <div class="evk-nl-card-body">
                    <p class="evk-nl-h-sm">Edytuj listę</p>
                    <input type="hidden" id="evk-nl-edit-id" value="">
                    <label class="evk-nl-label">Nazwa listy</label>
                    <input type="text" id="evk-nl-edit-name" class="evo-w-full" style="margin-bottom:10px;">

                    <div class="evo-inline" style="--evo-gap:6px">
                        <button class="button button-primary button-small" id="evk-nl-edit-save-btn">Zapisz</button>
                        <button class="button button-small" id="evk-nl-edit-cancel-btn">Anuluj</button>
                    </div>
                    <div id="evk-nl-edit-msg" class="evo-hint" style="margin-top:6px"></div>
                </div>
            </div>

            <!-- Import: podgląd → mapowanie kolumn → import (1.233.0) -->
            <div class="evk-nl-card-body evk-nl-hr" id="evk-nl-import">
                <p class="evk-nl-h-sm" style="margin-bottom:8px">Import subskrybentów</p>
                <div class="evo-inline evo-mb-xs" style="--evo-gap:12px;flex-wrap:wrap">
                    <label class="evk-nl-check">
                        <input type="radio" name="evk-nl-import-type" value="textarea" checked> Wklej adresy
                    </label>
                    <label class="evk-nl-check">
                        <input type="radio" name="evk-nl-import-type" value="csv"> Plik CSV/TXT
                    </label>
                </div>
                <div id="evk-nl-import-textarea-wrap">
                    <label for="evk-nl-import-textarea" class="screen-reader-text">Adresy do importu</label>
                    <textarea id="evk-nl-import-textarea" rows="3" class="evo-w-full"
                              placeholder="jan@example.com&#10;anna@example.com"></textarea>
                </div>
                <div id="evk-nl-import-file-wrap" class="evk-nl-hidden">
                    <label for="evk-nl-import-file" class="screen-reader-text">Plik CSV albo TXT</label>
                    <input type="file" id="evk-nl-import-file" accept=".csv,.txt">
                </div>
                <p class="evk-nl-note" style="margin:4px 0 0">Kolumnę z adresem wtyczka znajdzie sama, a nagłówki pozostałych kolumn staną się znacznikami w treści maila (np. „Imię" → {imie}). Przed zapisem zobaczysz podgląd.</p>
                <div class="evo-inline" style="--evo-gap:8px;margin-top:8px;flex-wrap:wrap">
                    <button type="button" class="button button-small" id="evk-nl-import-podglad-btn"
                            data-list-id="<?php echo (int) $active_list; ?>">Podgląd</button>
                    <span id="evk-nl-import-result" class="evo-hint" role="status" aria-live="polite"></span>
                </div>
                <div id="evk-nl-import-podglad" class="evk-nl-hidden" style="margin-top:10px">
                    <p class="evk-nl-h-sm" style="margin-bottom:6px">Kolumny pliku</p>
                    <div class="evk-nl-tbl-wrap">
                        <table class="evk-nl-tbl" id="evk-nl-import-kolumny">
                            <thead><tr><th scope="col">Kolumna</th><th scope="col">Przykład</th><th scope="col">Zapisz jako</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <p id="evk-nl-import-liczby" class="evo-hint" style="margin:8px 0" role="status" aria-live="polite"></p>
                    <div class="evk-nl-tbl-wrap">
                        <table class="evk-nl-tbl" id="evk-nl-import-probka">
                            <caption class="evo-hint" style="text-align:left">Pierwsze wiersze i co się z nimi stanie</caption>
                            <thead><tr><th scope="col">Adres</th><th scope="col">Stan</th><th scope="col">Znaczniki</th></tr></thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="evo-inline" style="--evo-gap:8px;margin-top:8px;flex-wrap:wrap">
                        <button type="button" class="button button-primary button-small" id="evk-nl-import-btn"
                                data-list-id="<?php echo (int) $active_list; ?>">Importuj</button>
                        <button type="button" class="button button-small" id="evk-nl-import-anuluj">Anuluj</button>
                    </div>
                </div>
            </div>

            <!-- Toolbar subskrybentów -->
            <div class="evk-nl-card-body evk-nl-hr" style="padding-bottom:10px">
                <div class="evo-inline" style="--evo-gap:8px;flex-wrap:wrap">
                    <input type="text" id="evk-nl-sub-search" placeholder="Szukaj email..."
                           class="evo-grow regular-text" style="min-width:140px">
                    <select id="evk-nl-sub-status-filter" class="evo-hint">
                        <option value="">Wszyscy</option>
                        <option value="1">Aktywni</option>
                        <option value="0">Wypisani</option>
                    </select>
                    <span id="evk-nl-sub-count" class="evo-hint evo-faint" style="white-space:nowrap"></span>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-ajax.php')); ?>" target="_blank" style="display:inline;">
                        <input type="hidden" name="action" value="evk_nl_export_subscribers">
                        <input type="hidden" name="nonce" value="<?php echo esc_attr($nonce); ?>">
                        <input type="hidden" name="list_id" value="<?php echo (int) $current_list['id']; ?>">
                        <button class="button button-small" type="submit"
                                title="Plik CSV z adresami tej listy">Eksport CSV</button>
                    </form>
                </div>
                <?php /* Jedno zdanie o zawartości pliku stoi tu celowo. Bez niego
                         pierwsze pytanie po eksporcie brzmi „czemu jest mniej
                         adresów niż w liczniku obok". */ ?>
                <p class="evo-hint evo-faint" style="margin:6px 0 0">
                    Eksport bierze <strong>tylko potwierdzonych aktywnych</strong> —
                    wypisani i oczekujący na potwierdzenie nie wchodzą do pliku.
                </p>
            </div>

            <!-- Bulk bar -->
            <div class="evk-nl-bulk-bar evk-nl-card-body evk-nl-hr" id="evk-nl-bulk-bar">
                <span id="evk-nl-bulk-count" class="evo-hint evo-accent-tx" style="font-weight:600"></span>
                <select id="evk-nl-bulk-action" class="evo-hint">
                    <option value="">— akcja —</option>
                    <option value="unsubscribe">Wypisz</option>
                    <option value="reactivate">Reaktywuj</option>
                    <option value="delete">Usuń</option>
                </select>
                <button class="button button-small" id="evk-nl-bulk-apply">Wykonaj</button>
                <button class="button button-small" id="evk-nl-bulk-cancel">Anuluj</button>
            </div>

            <!-- Tabela -->
            <div class="evk-nl-tbl-wrap">
                <div id="evk-nl-subscribers-table" class="evo-faint" style="padding:12px 16px">Ładowanie...</div>
            </div>
            <div id="evk-nl-sub-pagination" class="evk-nl-toolbar"></div>

        </div><!-- /evk-nl-card -->
        <?php else: ?>
        <div class="evk-nl-card">
            <div class="evk-nl-card-body evk-nl-empty-sm">
                <span class="dashicons dashicons-groups"></span>
                <p class="evo-muted" style="margin:10px 0 0">Wybierz listę lub utwórz nową.</p>
            </div>
        </div>
        <?php endif; ?>
        </div>

    </div>
<?php /* Uwaga: `.evk-nl-split` zamyka się TUTAJ. Wcześniej stał tu jeszcze
         jeden `</div>`, który nie miał pary — a nadmiarowy znacznik zamykający
         domyka `#wpbody-content` przed czasem i stopka WordPressa („Dziękujemy
         za tworzenie…") ląduje w połowie strony, na treści. */ ?>

<script>
/* Wynik importu słowami. Osobno WYKLUCZENI: import ich nie dopisuje (od
   1.231.0 wypisani, od 1.233.0 cała lista wykluczeń), więc bez tej pozycji
   znikaliby z liczb bez wyjaśnienia. `nowe` — w podglądzie to, co DOPIERO
   zostanie dodane. */
var EVK_NL_POWODY = {wypisany: 'wypisani', odbity: 'odbici', reczny: 'z listy wykluczeń', rodo: 'usunięci na żądanie RODO'};
function evkNlWynikImportu(d, nowe) {
    var czesci = [(nowe ? 'Nowe: ' : 'Dodano: ') + (d.added || 0), 'już na liście: ' + (d.skipped || 0)];
    if (d.unsubscribed) {
        var powody = [];
        Object.keys(d.powody || {}).forEach(function (p) { if (d.powody[p]) powody.push((EVK_NL_POWODY[p] || p) + ' ' + d.powody[p]); });
        czesci.push('wykluczeni (pominięci): ' + d.unsubscribed + (powody.length ? ' — ' + powody.join(', ') : ''));
    }
    czesci.push('błędne: ' + (d.invalid || 0));
    return czesci.join(', ') + '.';
}
jQuery(function($) {
    var nonce  = '<?php echo esc_js($nonce); ?>';
    var listId = <?php echo (int) $active_list; ?>;
    var subPage = 1;
    var selectedIds = [];

    // ---- Lista form ----
    $('#evk-nl-add-list-btn').on('click', function() {
        $('#evk-nl-list-id').val(0);
        $('#evk-nl-list-name').val('');
        $('#evk-nl-fields-repeater').empty();
        $('#evk-nl-list-form-title').text('Nowa lista');
        $('#evk-nl-list-form').slideToggle();
    });
    $('#evk-nl-cancel-list-btn').on('click', function() { $('#evk-nl-list-form').slideUp(); });

    $(document).on('click', '.evk-nl-edit-list-btn', function() {
        var btn = $(this);
        $('#evk-nl-edit-id').val(btn.data('id'));
        $('#evk-nl-edit-name').val(btn.data('name'));
        $('#evk-nl-edit-fields-repeater').empty();
        $('#evk-nl-edit-inline').slideDown();
        $('#evk-nl-edit-name').focus();
    });

    $('#evk-nl-edit-cancel-btn').on('click', function() { $('#evk-nl-edit-inline').slideUp(); });



    $('#evk-nl-edit-save-btn').on('click', function() {
        $.post(ajaxurl, {action:'evk_nl_save_list', nonce:nonce,
            id: $('#evk-nl-edit-id').val(),
            name: $('#evk-nl-edit-name').val()
        }, function(res) {
            if (res.success) {
                $('#evk-nl-edit-msg').text('✓ Zapisano').addClass('evo-save-msg is-widoczny');
                setTimeout(function(){ location.reload(); }, 700);
            } else {
                $('#evk-nl-edit-msg').text(res.data?.msg||'Błąd').css('color','#dc2626');
            }
        });
    });



    $('#evk-nl-save-list-btn').on('click', function() {
        $.post(ajaxurl, {action:'evk_nl_save_list', nonce:nonce, id:$('#evk-nl-list-id').val(), name:$('#evk-nl-list-name').val()}, function(res) {
            if (res.success) { $('#evk-nl-list-msg').text('✓ Zapisano').addClass('evo-save-msg is-widoczny'); setTimeout(function(){location.reload();},700); }
            else { $('#evk-nl-list-msg').text(res.data?.msg||'Błąd').css('color','#dc2626'); }
        });
    });

    $(document).on('change', '.evk-nl-list-toggle', function() {
        $.post(ajaxurl, {action:'evk_nl_toggle_list', nonce:nonce, id:$(this).data('id'), status:$(this).is(':checked')?1:0});
    });
    $(document).on('click', '.evk-nl-delete-list-btn', function() {
        if (!confirm('Usunąć listę wraz ze wszystkimi subskrybentami?')) return;
        $.post(ajaxurl, {action:'evk_nl_delete_list', nonce:nonce, id:$(this).data('id')}, function(res) { if (res.success) location.reload(); });
    });

    // ---- Import: podgląd → mapowanie → import ----
    var importMapa = null;
    $('input[name="evk-nl-import-type"]').on('change', function() {
        $(this).val()==='csv' ? ($('#evk-nl-import-textarea-wrap').hide(), $('#evk-nl-import-file-wrap').show())
                              : ($('#evk-nl-import-file-wrap').hide(), $('#evk-nl-import-textarea-wrap').show());
        zamknijPodglad();
    });
    $('#evk-nl-import-textarea, #evk-nl-import-file').on('input change', zamknijPodglad);

    function zamknijPodglad() { importMapa = null; $('#evk-nl-import-podglad').hide(); }

    /* To samo żądanie dla podglądu i importu — różni je akcja. Plik idzie
       za każdym razem od nowa: serwer niczego między krokami nie trzyma. */
    function importDane(akcja) {
        var fd = new FormData();
        fd.append('action', akcja); fd.append('nonce', nonce); fd.append('list_id', listId);
        if ($('input[name="evk-nl-import-type"]:checked').val() === 'csv') {
            var file = $('#evk-nl-import-file')[0].files[0];
            if (!file) return null;
            fd.append('csv_file', file);
        } else {
            var tekst = $('#evk-nl-import-textarea').val();
            if (!$.trim(tekst)) return null;
            fd.append('content', tekst);
        }
        if (importMapa) fd.append('mapa', JSON.stringify(importMapa));
        return fd;
    }
    function wyslij(fd, gotowe) {
        $.ajax({url: ajaxurl, type: 'POST', data: fd, processData: false, contentType: false, success: gotowe,
                error: function() { $('#evk-nl-import-result').text('Błąd połączenia.'); }});
    }
    var esc = function (t) { return $('<div>').text(t == null ? '' : String(t)).html(); };
    var STANY = {nowy: 'nowy', jest: 'już na liście', duplikat: 'powtórzony w pliku', bledny: 'błędny adres'};

    function pokazPodglad(d) {
        importMapa = d.mapa;
        var $k = $('#evk-nl-import-kolumny tbody').empty();
        d.kolumny.forEach(function (kol, i) {
            var jestPole = Object.prototype.hasOwnProperty.call(d.mapa.pola, String(i));
            var klucz = jestPole ? d.mapa.pola[i] : (kol.klucz || ('kolumna_' + (i + 1)));
            var wybor = (d.mapa.email === i) ? 'email' : (jestPole ? 'pole' : 'pomin');
            var nazwa = kol.nazwa || ('Kolumna ' + (i + 1));
            $k.append('<tr data-kolumna="' + i + '"><td>' + esc(nazwa) + '</td><td class="evo-faint">' + esc(kol.przyklad) + '</td><td>'
                + '<select class="evk-nl-map" aria-label="' + esc(nazwa) + ': zapisz jako">'
                + '<option value="pomin"' + (wybor === 'pomin' ? ' selected' : '') + '>Pomiń</option>'
                + '<option value="email"' + (wybor === 'email' ? ' selected' : '') + '>Adres e-mail</option>'
                + '<option value="pole"' + (wybor === 'pole' ? ' selected' : '') + '>Znacznik</option></select> '
                + '<input type="text" class="evk-nl-map-klucz" size="12" value="' + esc(klucz) + '" aria-label="' + esc(nazwa) + ': nazwa znacznika"'
                + (wybor === 'pole' ? '' : ' hidden') + '></td></tr>');
        });
        $('#evk-nl-import-liczby').text(evkNlWynikImportu(d, true) + (d.wierszy > d.probka.length ? ' Wierszy w pliku: ' + d.wierszy + '.' : ''));
        var $p = $('#evk-nl-import-probka tbody').empty();
        d.probka.forEach(function (w) {
            var stan = w.stan === 'wykluczony' ? 'wykluczony (' + (EVK_NL_POWODY[w.powod] || w.powod) + ')' : (STANY[w.stan] || w.stan);
            var pola = Object.keys(w.pola || {}).map(function (k) { return '{' + k + '} ' + w.pola[k]; }).join(', ');
            $p.append('<tr data-stan="' + esc(w.stan) + '"><td>' + esc(w.adres || w.komorki.join(' · ')) + '</td><td>' + esc(stan) + '</td><td class="evo-faint">' + esc(pola) + '</td></tr>');
        });
        $('#evk-nl-import-btn').text('Importuj ' + (d.added || 0) + ' ' + ((d.added === 1) ? 'adres' : ((d.added % 10 >= 2 && d.added % 10 <= 4 && (d.added % 100 < 12 || d.added % 100 > 14)) ? 'adresy' : 'adresów')))
            .prop('disabled', !d.added);
        $('#evk-nl-import-podglad').show();
        $('#evk-nl-import-result').text('');
    }

    function podglad() {
        var fd = importDane('evk_nl_import_podglad');
        if (!fd) { $('#evk-nl-import-result').text($('input[name="evk-nl-import-type"]:checked').val() === 'csv' ? 'Wybierz plik.' : 'Wklej adresy.'); return; }
        $('#evk-nl-import-result').text('Czytam…');
        wyslij(fd, function (res) {
            if (res.success) pokazPodglad(res.data);
            else { zamknijPodglad(); $('#evk-nl-import-result').text((res.data && res.data.msg) || 'Błąd'); }
        });
    }
    $('#evk-nl-import-podglad-btn').on('click', function () { importMapa = null; podglad(); });

    // Zmiana mapowania: nowa mapa i świeże liczby z serwera (te same, które zrobi import).
    $(document).on('change input', '.evk-nl-map, .evk-nl-map-klucz', function () {
        var mapa = {email: null, pola: {}};
        $('#evk-nl-import-kolumny tbody tr').each(function () {
            var i = parseInt($(this).data('kolumna'), 10), wybor = $(this).find('.evk-nl-map').val();
            $(this).find('.evk-nl-map-klucz').prop('hidden', wybor !== 'pole');
            if (wybor === 'email') mapa.email = i;
            if (wybor === 'pole') mapa.pola[i] = $(this).find('.evk-nl-map-klucz').val();
        });
        if (mapa.email === null) { $('#evk-nl-import-liczby').text('Wskaż kolumnę z adresem e-mail.'); $('#evk-nl-import-btn').prop('disabled', true); return; }
        importMapa = mapa;
        clearTimeout(podglad.t);
        podglad.t = setTimeout(podglad, 300);
    });

    $('#evk-nl-import-anuluj').on('click', zamknijPodglad);
    $('#evk-nl-import-btn').on('click', function () {
        var csv = $('input[name="evk-nl-import-type"]:checked').val() === 'csv';
        var fd = importDane(csv ? 'evk_nl_import_csv_file' : 'evk_nl_import_subscribers');
        if (!fd || !importMapa) return;
        $(this).prop('disabled', true);
        $('#evk-nl-import-result').text('Importowanie…');
        wyslij(fd, function (res) {
            if (res.success) {
                $('#evk-nl-import-result').text(evkNlWynikImportu(res.data));
                zamknijPodglad();
                loadSubs();
            } else {
                $('#evk-nl-import-btn').prop('disabled', false);
                $('#evk-nl-import-result').text((res.data && res.data.msg) || 'Błąd');
            }
        });
    });

    // ---- Lista wykluczeń ----
    var WYK_POWODY = {odbity: 'odbity przy wysyłce', reczny: 'dopisany ręcznie'};
    function pokazWykluczenia(lista) {
        $('#evk-nl-wyk-licznik').text(lista.length);
        var $l = $('#evk-nl-wyk-lista').empty();
        if (!lista.length) { $l.append('<li class="evo-faint">Pusto.</li>'); return; }
        lista.forEach(function (w) {
            $l.append('<li style="display:flex;gap:6px;align-items:center;justify-content:space-between;padding:3px 0">'
                + '<span><strong>' + esc(w.email) + '</strong> <span class="evo-hint">' + esc(WYK_POWODY[w.powod] || w.powod) + ' · ' + esc(String(w.kiedy).substring(0, 10)) + '</span></span>'
                + '<button type="button" class="evk-nl-btn-icon evk-nl-wyk-usun" data-email="' + esc(w.email) + '" aria-label="Usuń ' + esc(w.email) + ' z wykluczeń" title="Usuń z wykluczeń">'
                + '<span class="dashicons dashicons-no-alt evo-ico-sm evo-danger-tx"></span></button></li>');
        });
    }
    function wykluczenia(dane, gotowe) {
        $.post(ajaxurl, $.extend({action: 'evk_nl_wykluczenia', nonce: nonce}, dane), function (res) {
            if (res.success) { pokazWykluczenia(res.data.lista); if (gotowe) gotowe(res.data); }
        });
    }
    $('#evk-nl-wyk-dodaj').on('click', function () {
        var adresy = $('#evk-nl-wyk-adresy').val();
        if (!$.trim(adresy)) return;
        wykluczenia({akcja: 'dodaj', adresy: adresy}, function (d) {
            $('#evk-nl-wyk-adresy').val('');
            $('#evk-nl-wyk-msg').text('Dodano: ' + d.dodane + (d.bledne ? ', błędne: ' + d.bledne : '') + '.');
            loadSubs();
        });
    });
    $(document).on('click', '.evk-nl-wyk-usun', function () {
        wykluczenia({akcja: 'usun', email: $(this).data('email')});
    });
    wykluczenia({akcja: 'lista'});

    // ---- Subskrybenci ----
    function updateBulkBar() {
        selectedIds.length ? $('#evk-nl-bulk-bar').css('display','flex').find('#evk-nl-bulk-count').text(selectedIds.length+' zaznaczonych')
                           : $('#evk-nl-bulk-bar').hide();
    }

    function loadSubs(page) {
        if (!listId) return;
        page = page || subPage;
        selectedIds = []; updateBulkBar();
        $.post(ajaxurl, {action:'evk_nl_get_subscribers', nonce:nonce, list_id:listId, page:page, search:$('#evk-nl-sub-search').val(), status:$('#evk-nl-sub-status-filter').val()}, function(res) {
            if (!res.success) return;
            var d = res.data; subPage = d.page;
            $('#evk-nl-sub-count').text(d.total+' subskrybentów');
            if (!d.items.length) { $('#evk-nl-subscribers-table').html('<p class="evo-faint" style="padding:12px 0">Brak subskrybentów.</p>'); $('#evk-nl-sub-pagination').empty(); return; }
            var html = '<table class="evk-nl-tbl"><thead><tr>' +
                '<th style="width:28px;"><input type="checkbox" id="evk-nl-check-all"></th>' +
                '<th>Email</th>' +
                '<th style="width:80px;" class="evk-col-hide">Status</th>' +
                '<th style="width:80px;" class="evk-col-hide">Data</th>' +
                '<th style="width:36px;"></th>' +
                '</tr></thead><tbody>';
            d.items.forEach(function(s) {
                var active = parseInt(s.status)===1;
                var etykieta = active ? '● Aktywny' : (s.wykluczenie === 'odbity' ? '● Odbity' : (s.wykluczenie ? '● Wykluczony' : '● Wypisany'));
                html += '<tr><td><input type="checkbox" class="evk-nl-sub-cb" data-id="'+s.id+'"></td>' +
                    '<td><strong>'+$('<div>').text(s.email).html()+'</strong></td>' +
                    '<td class="evk-col-hide"><span class="evo-hint-sm '+(active?'evk-nl-ok':'evk-nl-err')+'">'+etykieta+'</span></td>' +
                    '<td class="evk-col-hide evo-faint">'+s.subscribed_at.substring(0,10)+'</td>' +
                    '<td><button class="evk-nl-btn-icon evk-nl-del-sub" data-id="'+s.id+'" title="Usuń"><span class="dashicons dashicons-no-alt evo-ico-sm evo-danger-tx"></span></button></td>' +
                    '</tr>';
            });
            html += '</tbody></table>';
            $('#evk-nl-subscribers-table').html(html);
            var pag = '';
            if (d.pages>1) {
                if (subPage>1) pag += '<button class="button button-small evk-nl-sub-page" data-page="'+(subPage-1)+'">‹</button> ';
                for (var i=Math.max(1,subPage-2); i<=Math.min(d.pages,subPage+2); i++) pag += '<button class="button button-small evk-nl-sub-page'+(i===d.page?' button-primary':'')+'" data-page="'+i+'">'+i+'</button> ';
                if (subPage<d.pages) pag += '<button class="button button-small evk-nl-sub-page" data-page="'+(subPage+1)+'">›</button>';
            }
            $('#evk-nl-sub-pagination').html(pag);
        });
    }

    $(document).on('change','#evk-nl-check-all', function() {
        var on=$(this).is(':checked');
        $('.evk-nl-sub-cb').prop('checked',on).each(function() {
            var id=parseInt($(this).data('id'));
            if (on) { if(selectedIds.indexOf(id)===-1) selectedIds.push(id); } else { selectedIds=selectedIds.filter(function(x){return x!==id;}); }
        });
        updateBulkBar();
    });
    $(document).on('change','.evk-nl-sub-cb', function() {
        var id=parseInt($(this).data('id'));
        $(this).is(':checked') ? (selectedIds.indexOf(id)===-1&&selectedIds.push(id)) : (selectedIds=selectedIds.filter(function(x){return x!==id;}), $('#evk-nl-check-all').prop('checked',false));
        updateBulkBar();
    });
    $('#evk-nl-bulk-apply').on('click', function() {
        var a=$('#evk-nl-bulk-action').val();
        if (!a) { alert('Wybierz akcję.'); return; }
        if (!selectedIds.length) return;
        var labels={delete:'usunąć',unsubscribe:'wypisać',reactivate:'reaktywować'};
        if (!confirm('Czy na pewno chcesz '+( labels[a]||a)+' '+selectedIds.length+' subskrybentów?')) return;
        $.post(ajaxurl, {action:'evk_nl_bulk_subscribers', nonce:nonce, bulk_action:a, ids:JSON.stringify(selectedIds)}, function(res) {
            if (res.success) { selectedIds=[]; loadSubs(subPage); } else { alert(res.data?.msg||'Błąd'); }
        });
    });
    $('#evk-nl-bulk-cancel').on('click', function() { selectedIds=[]; $('.evk-nl-sub-cb,#evk-nl-check-all').prop('checked',false); updateBulkBar(); });
    $(document).on('click','.evk-nl-del-sub', function() {
        if (!confirm('Usunąć?')) return;
        $.post(ajaxurl, {action:'evk_nl_delete_subscriber', nonce:nonce, id:$(this).data('id')}, function() { loadSubs(subPage); });
    });
    $(document).on('click','.evk-nl-sub-page', function() { loadSubs($(this).data('page')); });
    var st; $('#evk-nl-sub-search').on('input', function() { clearTimeout(st); st=setTimeout(function(){subPage=1;loadSubs(1);},350); });
    $('#evk-nl-sub-status-filter').on('change', function() { subPage=1; loadSubs(1); });

    if (listId) loadSubs(1);
});
</script>
