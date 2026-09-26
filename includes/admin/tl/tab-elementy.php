<?php
if (!defined('ABSPATH')) exit;
/**
 * Zakładka „Teksty w elementach" (1.244.0) — przeniesienie tłumaczeń ze
 * słownika do pól języków w elementach Bricksa (53-translation-element-
 * transfer.php). Nonce i adres liczy sama, bez zmiennych z tl_render_page().
 */
?>
<div class="evo-box tl-przeniesienie" data-nonce="<?php echo esc_attr(wp_create_nonce('tl_ajax_nonce')); ?>">
    <h3>Przenieś tłumaczenia ze słownika do elementów</h3>
    <p class="evo-desc">Pole „Tłumaczenie EN" w elemencie Bricksa pokazuje to, co widać na stronie. Tekst, który dziś
    tłumaczy słownik, dostanie tłumaczenie do pola w elemencie — tylko tam, gdzie pole jest puste, a cały polski tekst
    jest frazą słownika. Od tej chwili tłumaczenie należy do elementu: zmiana polskiego tekstu go nie usunie, a lista
    „Do sprawdzenia" pokaże to miejsce. Poprawka frazy w słowniku do takiego elementu już nie dojdzie.</p>
    <p class="evo-desc">Nowe teksty uzupełniają się same przy zapisie strony w Bricksie — przycisk jest dla stron
    zapisanych wcześniej. Na czas przenoszenia zamknij builder: jego następny zapis nie zna nowych pól (wtyczka je
    wtedy zachowa, ale spokojniej bez tego).</p>
    <p class="tl-przeniesienie-akcje">
        <button type="button" class="button" data-tl-el-tryb="podglad">Podgląd</button>
        <button type="button" class="button button-primary" data-tl-el-tryb="zapisz" disabled>Przenieś</button>
    </p>
    <p class="tl-przeniesienie-stan" role="status"></p>
    <div class="tl-przeniesienie-wynik"></div>
</div>
<script>
(function () {
    var box = document.querySelector('.tl-przeniesienie');
    if (!box) return;
    var stan = box.querySelector('.tl-przeniesienie-stan');
    var wynik = box.querySelector('.tl-przeniesienie-wynik');
    var przenies = box.querySelector('[data-tl-el-tryb="zapisz"]');

    function el(tag, tekst, klasa) {
        var e = document.createElement(tag);
        if (tekst !== undefined && tekst !== null) e.textContent = String(tekst);
        if (klasa) e.className = klasa;
        return e;
    }

    /* Liczby po dwukropku — bez odmiany („3 strony", „5 stron"). */
    function pokaz(d, zapisane) {
        wynik.textContent = '';
        var strony = d.strony || [];
        if (!d.razem) {
            stan.textContent = zapisane ? 'Nic do przeniesienia.'
                : 'Nic do przeniesienia: pola są już uzupełnione albo słownik nie zna tych tekstów w całości.';
        } else {
            stan.textContent = (zapisane ? 'Przeniesiono tłumaczenia: ' : 'Do przeniesienia tłumaczeń: ') + d.razem + ' (strony: ' + strony.length + ').';
        }
        if (strony.length) {
            var wrap = el('div', null, 'evo-tbl-wrap');
            var t = el('table', null, 'evo-table');
            var tr = el('tr');
            ['Strona', 'Część', zapisane ? 'Uzupełnione pola' : 'Pola do uzupełnienia'].forEach(function (n) {
                var th = el('th', n); th.setAttribute('scope', 'col'); tr.appendChild(th);
            });
            var thead = el('thead'); thead.appendChild(tr); t.appendChild(thead);
            var tbody = el('tbody');
            strony.forEach(function (s) {
                var w = el('tr');
                var a = el('a', s.tytul); a.href = s.adres;
                var td = el('td'); td.appendChild(a); w.appendChild(td);
                w.appendChild(el('td', s.czesc));
                w.appendChild(el('td', s.zmiany));
                tbody.appendChild(w);
            });
            t.appendChild(tbody); wrap.appendChild(t); wynik.appendChild(wrap);
        }
        var nieznane = Object.keys(d.nieznane || {});
        if (!d.znane) {
            wynik.appendChild(el('p', 'Wtyczka nie zna jeszcze pól elementów Bricksa. Otwórz dowolną stronę w builderze i wróć tutaj.', 'evo-desc'));
        } else if (nieznane.length) {
            wynik.appendChild(el('p', 'Pominięte typy elementów — wtyczka nie zna jeszcze ich pól: '
                + nieznane.map(function (n) { return n + ': ' + d.nieznane[n]; }).join(', ')
                + '. Otwórz w builderze stronę z takim elementem i zrób podgląd jeszcze raz.', 'evo-desc'));
        }
        przenies.disabled = zapisane || !d.razem;
    }

    box.addEventListener('click', function (e) {
        var b = e.target.closest('[data-tl-el-tryb]');
        if (!b || b.disabled) return;
        var tryb = b.getAttribute('data-tl-el-tryb');
        b.disabled = true;
        stan.textContent = tryb === 'zapisz' ? 'Przenoszę…' : 'Liczę…';
        var fd = new FormData();
        fd.append('action', 'evk_tl_el_przenies');
        fd.append('nonce', box.getAttribute('data-nonce'));
        fd.append('tryb', tryb);
        fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (r) {
                if (tryb === 'podglad') b.disabled = false;
                if (r && r.success) pokaz(r.data, tryb === 'zapisz');
                else { b.disabled = false; stan.textContent = (r && r.data) || 'Błąd — spróbuj jeszcze raz.'; }
            })
            .catch(function () { b.disabled = false; stan.textContent = 'Błąd połączenia — spróbuj jeszcze raz.'; });
    });
})();
</script>
