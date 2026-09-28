<?php
if (!defined('ABSPATH')) exit;
/**
 * Zakładka „Wpisy i kategorie" (1.252.0, termy od 1.253.0) — przeniesienie
 * tytułów, zajawek i nazw termów ze słownika do pól języków
 * (55-translation-posts.php). Nonce i adres liczy sama, bez zmiennych
 * z tl_render_page().
 */
?>
<div class="evo-box tlw-slownik" data-nonce="<?php echo esc_attr(wp_create_nonce('tl_ajax_nonce')); ?>">
    <h3>Tytuły, zajawki i nazwy ze słownika</h3>
    <p class="evo-desc">Tytuł, adres, treść i zajawkę wpisu albo strony tłumaczysz w edytorze wpisu — przełącznikiem
    <strong>PL | EN | DE</strong> nad tytułem. Nazwę, adres i opis kategorii, tagu albo innej taksonomii — tak samo, na
    ekranie edycji i przy dodawaniu. Stan tłumaczeń widać w kolumnie „Języki” na listach.</p>
    <p class="evo-desc">Tytuły, zajawki i nazwy, które słownik już tłumaczy w całości (np. nazwy stron i kategorii w menu),
    mogą trafić do pól języków jednym przyciskiem — tylko tam, gdzie pole jest puste. Od tej chwili tłumaczenie należy do
    wpisu albo kategorii: zmiana polskiego tekstu oznaczy je „Do sprawdzenia”, a poprawka frazy w słowniku już tam nie dojdzie.</p>
    <p class="tlw-slownik-akcje">
        <button type="button" class="button" data-tlw-tryb="podglad">Podgląd</button>
        <button type="button" class="button button-primary" data-tlw-tryb="zapisz" disabled>Przenieś</button>
    </p>
    <p class="tlw-slownik-stan" role="status"></p>
    <div class="tlw-slownik-wynik"></div>
</div>
<script>
(function () {
    var box = document.querySelector('.tlw-slownik');
    if (!box) return;
    var stan = box.querySelector('.tlw-slownik-stan');
    var wynik = box.querySelector('.tlw-slownik-wynik');
    var przenies = box.querySelector('[data-tlw-tryb="zapisz"]');

    function el(tag, tekst, klasa) {
        var e = document.createElement(tag);
        if (tekst !== undefined && tekst !== null) e.textContent = String(tekst);
        if (klasa) e.className = klasa;
        return e;
    }

    /* Liczby po dwukropku — bez odmiany („3 pola", „5 pól"). */
    function pokaz(d) {
        wynik.textContent = '';
        var w = d.wiersze || [];
        stan.textContent = w.length ? 'Do przeniesienia: ' + w.length + '.' : 'Nic do przeniesienia: pola są wypełnione albo słownik nie zna tych tekstów w całości.';
        if (w.length) {
            var wrap = el('div', null, 'evo-tbl-wrap');
            var t = el('table', null, 'evo-table');
            var tr = el('tr');
            ['Wpis / kategoria', 'Pole', 'Język', 'Polski tekst', 'Tłumaczenie ze słownika'].forEach(function (n) {
                var th = el('th', n); th.setAttribute('scope', 'col'); tr.appendChild(th);
            });
            var thead = el('thead'); thead.appendChild(tr); t.appendChild(thead);
            var tbody = el('tbody');
            w.forEach(function (x) {
                var r = el('tr');
                var a = el('a', x.tytul); a.href = x.adres;
                var td = el('td'); td.appendChild(a); td.appendChild(el('span', ' (' + x.typ + ')', 'evo-faint')); r.appendChild(td);
                r.appendChild(el('td', x.nazwa));
                r.appendChild(el('td', String(x.jezyk).toUpperCase()));
                r.appendChild(el('td', x.pl));
                r.appendChild(el('td', x.tekst));
                tbody.appendChild(r);
            });
            t.appendChild(tbody); wrap.appendChild(t); wynik.appendChild(wrap);
        }
        przenies.disabled = !w.length;
    }

    box.addEventListener('click', function (e) {
        var b = e.target.closest('[data-tlw-tryb]');
        if (!b || b.disabled) return;
        var tryb = b.getAttribute('data-tlw-tryb');
        b.disabled = true;
        stan.textContent = tryb === 'zapisz' ? 'Przenoszę…' : 'Liczę…';
        var fd = new FormData();
        fd.append('action', 'evk_tlw_slownik');
        fd.append('nonce', box.getAttribute('data-nonce'));
        fd.append('tryb', tryb);
        fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (!r || !r.success) { b.disabled = false; stan.textContent = (r && r.data) || 'Błąd — spróbuj jeszcze raz.'; return; }
                if (tryb === 'podglad') { b.disabled = false; pokaz(r.data); return; }
                wynik.textContent = '';
                stan.textContent = 'Przeniesiono: ' + r.data.zapisane + (r.data.pominiete ? ', pominięte (brak uprawnień): ' + r.data.pominiete : '') + '.';
            })
            .catch(function () { b.disabled = false; stan.textContent = 'Błąd połączenia — spróbuj jeszcze raz.'; });
    });
})();
</script>
