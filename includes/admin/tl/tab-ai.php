<?php
if (!defined('ABSPATH')) exit;
/**
 * Zakładka „Tłumaczenie AI” (1.260.0) — ustawienia i tłumaczenie brakujących
 * tekstów w elementach Bricksa (61-translation-ai.php). Nonce i adres liczy
 * sama, bez zmiennych z tl_render_page().
 *
 * Klucz API nigdy nie trafia do strony: pole jest puste, a obok stoi tylko
 * informacja, że klucz jest zapisany. Ustawienia zmienia administrator;
 * tłumacz (Role Manager) widzi je i może tłumaczyć.
 */
if (!function_exists('evk_tl_ai_ustawienia')) return;
$evk_u = evk_tl_ai_ustawienia();
$evk_d = evk_tl_ai_dostawcy();
$evk_admin = current_user_can('manage_options');
$evk_jezyki = tl_get_languages();
?>
<div class="evo-box tl-ai" data-nonce="<?php echo esc_attr(wp_create_nonce('evk_tl_ai')); ?>">
    <h3>Tłumaczenie AI</h3>
    <p class="evo-desc">AI uzupełnia puste pola „Tłumaczenie EN/DE” w elementach Bricksa. Wypełnione pola zostają nietknięte.
    Każde tłumaczenie AI trafia na listę „Do sprawdzenia” (zakładka EVOKE Tłumaczenia) — zdejmuje je „Sprawdzone” albo poprawka.</p>
    <p class="evo-desc">Model widzi wszystkie teksty tej części strony naraz, opis strony, wskazówki dla języka i słowniczek.
    Ten sam polski tekst ze sprawdzonym tłumaczeniem dostaje je bez pytania AI, a ponowne tłumaczenie tego samego tekstu daje ten sam wynik.</p>
    <p class="evo-desc"><strong>Prywatność:</strong> teksty stron idą do wybranego dostawcy. Na darmowym poziomie Gemini Google
    wykorzystuje je do ulepszania swoich usług; na płatnym (Gemini, Claude, OpenAI) — nie.</p>
</div>

<div class="evo-box tl-ai-ustawienia">
    <h3>Ustawienia</h3>
    <?php if (!$evk_admin): ?>
    <p class="evo-desc">Ustawienia zmienia administrator. Dostawca: <?php echo esc_html($evk_d[$evk_u['dostawca']]['nazwa']); ?>,
    model: <code><?php echo esc_html(evk_tl_ai_model($evk_u)); ?></code>, klucz API: <?php echo evk_tl_ai_klucz($evk_u) !== '' ? 'zapisany' : 'brak'; ?>.</p>
    <?php else: ?>
    <div class="evo-field">
        <label for="tl-ai-dostawca">Dostawca</label>
        <select id="tl-ai-dostawca">
            <?php foreach ($evk_d as $evk_k => $evk_w): ?>
            <option value="<?php echo esc_attr($evk_k); ?>" <?php selected($evk_u['dostawca'], $evk_k); ?>
                data-model="<?php echo esc_attr($evk_w['model']); ?>"
                data-wlasny="<?php echo esc_attr((string) ($evk_u['modele'][$evk_k] ?? '')); ?>"
                data-klucz="<?php echo (string) ($evk_u['klucze'][$evk_k] ?? '') !== '' ? '1' : ''; ?>"><?php echo esc_html($evk_w['nazwa']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="evo-field">
        <label for="tl-ai-klucz">Klucz API <span class="tl-ai-klucz-stan evo-label-note"></span></label>
        <input type="password" id="tl-ai-klucz" autocomplete="off" spellcheck="false" placeholder="wklej klucz — zapisany zostaje ukryty">
        <label class="evo-check-row"><input type="checkbox" id="tl-ai-usun-klucz"> Usuń zapisany klucz tego dostawcy</label>
    </div>
    <div class="evo-field">
        <label for="tl-ai-model">Model</label>
        <input type="text" id="tl-ai-model" spellcheck="false">
        <p class="evo-desc">Puste — model domyślny dostawcy (w podpowiedzi pola).</p>
    </div>
    <div class="evo-field">
        <label for="tl-ai-opis">Opis strony (branża, odbiorcy, ton)</label>
        <textarea id="tl-ai-opis" rows="3"><?php echo esc_textarea($evk_u['opis']); ?></textarea>
    </div>
    <?php foreach ($evk_jezyki as $evk_kod => $evk_j): ?>
    <div class="evo-field">
        <label for="tl-ai-wsk-<?php echo esc_attr((string) $evk_kod); ?>">Wskazówki: <?php echo esc_html(strtoupper((string) $evk_kod) . ' (' . ($evk_j['name'] ?? $evk_kod) . ')'); ?></label>
        <textarea id="tl-ai-wsk-<?php echo esc_attr((string) $evk_kod); ?>" data-jezyk="<?php echo esc_attr((string) $evk_kod); ?>" class="tl-ai-wsk" rows="2"
            placeholder="np. zwracaj się per Sie; angielski brytyjski"><?php echo esc_textarea((string) ($evk_u['wskazowki'][$evk_kod] ?? '')); ?></textarea>
    </div>
    <?php endforeach; ?>
    <div class="evo-field">
        <label for="tl-ai-slowniczek">Słowniczek</label>
        <textarea id="tl-ai-slowniczek" rows="5" spellcheck="false" placeholder="realizacje | projects | Projekte&#10;!Evoke Design Studio"><?php echo esc_textarea($evk_u['slowniczek']); ?></textarea>
        <p class="evo-desc">Linia <code>polski | <?php echo esc_html(implode(' | ', array_map('strtoupper', array_keys($evk_jezyki)))); ?></code> — te tłumaczenia zawsze.
        Linia <code>!Nazwa</code> — nie tłumaczyć (marka, produkt). Linia od <code>#</code> — komentarz.</p>
    </div>
    <p class="tl-footer">
        <button type="button" class="button button-primary tl-ai-zapisz">Zapisz ustawienia AI</button>
        <span class="tl-ai-zapis-stan" role="status"></span>
    </p>
    <?php endif; ?>
</div>

<div class="evo-box tl-ai-tlumacz">
    <h3>Przetłumacz braki</h3>
    <p class="tl-ai-jezyki">
        <?php foreach ($evk_jezyki as $evk_kod => $evk_j): ?>
        <label class="evo-check-row"><input type="checkbox" class="tl-ai-jezyk" value="<?php echo esc_attr((string) $evk_kod); ?>" checked>
            <?php echo esc_html(strtoupper((string) $evk_kod) . ' — ' . ($evk_j['name'] ?? $evk_kod)); ?></label>
        <?php endforeach; ?>
    </p>
    <p>
        <button type="button" class="button tl-ai-lista">Pokaż strony z brakami</button>
        <button type="button" class="button button-primary tl-ai-start" disabled>Przetłumacz zaznaczone</button>
        <button type="button" class="button tl-ai-stop" disabled>Zatrzymaj</button>
    </p>
    <p class="tl-ai-stan" role="status"></p>
    <div class="tl-ai-jednostki"></div>
    <ol class="tl-ai-dziennik"></ol>
</div>
<script>
(function () {
    var AJAX = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
    var box = document.querySelector('.tl-ai');
    if (!box) return;
    var NONCE = box.getAttribute('data-nonce');

    function wyslij(dane) {
        var fd = new FormData();
        fd.append('nonce', NONCE);
        Object.keys(dane).forEach(function (k) {
            var v = dane[k];
            if (Array.isArray(v)) v.forEach(function (x) { fd.append(k + '[]', x); });
            else if (v && typeof v === 'object') Object.keys(v).forEach(function (x) { fd.append(k + '[' + x + ']', v[x]); });
            else fd.append(k, v);
        });
        return fetch(AJAX, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function el(tag, tekst, klasa) {
        var e = document.createElement(tag);
        if (tekst !== undefined && tekst !== null) e.textContent = String(tekst);
        if (klasa) e.className = klasa;
        return e;
    }

    /* Ustawienia: model i stan klucza zależą od dostawcy. */
    var dostawca = document.getElementById('tl-ai-dostawca');
    if (dostawca) {
        var model = document.getElementById('tl-ai-model');
        var kluczStan = document.querySelector('.tl-ai-klucz-stan');
        var pokazDostawce = function () {
            var o = dostawca.options[dostawca.selectedIndex];
            model.placeholder = o.getAttribute('data-model');
            model.value = o.getAttribute('data-wlasny') || '';
            kluczStan.textContent = o.getAttribute('data-klucz') ? '(zapisany)' : '(brak)';
        };
        dostawca.addEventListener('change', pokazDostawce);
        pokazDostawce();
        document.querySelector('.tl-ai-zapisz').addEventListener('click', function (e) {
            var b = e.currentTarget, stan = document.querySelector('.tl-ai-zapis-stan');
            var wsk = {};
            document.querySelectorAll('.tl-ai-wsk').forEach(function (t) { wsk[t.getAttribute('data-jezyk')] = t.value; });
            b.disabled = true;
            stan.textContent = 'Zapisuję…';
            wyslij({ action: 'evk_tl_ai_ustawienia', dostawca: dostawca.value, klucz: document.getElementById('tl-ai-klucz').value,
                usun_klucz: document.getElementById('tl-ai-usun-klucz').checked ? '1' : '', model: model.value,
                opis: document.getElementById('tl-ai-opis').value, slowniczek: document.getElementById('tl-ai-slowniczek').value, wskazowki: wsk })
                .then(function (r) {
                    b.disabled = false;
                    if (!r || !r.success) { stan.textContent = (r && r.data) || 'Błąd zapisu.'; return; }
                    var o = dostawca.options[dostawca.selectedIndex];
                    o.setAttribute('data-klucz', r.data.klucz ? '1' : '');
                    o.setAttribute('data-wlasny', model.value);
                    document.getElementById('tl-ai-klucz').value = '';
                    document.getElementById('tl-ai-usun-klucz').checked = false;
                    pokazDostawce();
                    stan.textContent = r.data.komunikat;
                })
                .catch(function () { b.disabled = false; stan.textContent = 'Błąd połączenia.'; });
        });
    }

    /* Tłumaczenie: lista części stron z brakami, potem krok po kroku. */
    var t = document.querySelector('.tl-ai-tlumacz');
    var stan = t.querySelector('.tl-ai-stan');
    var lista = t.querySelector('.tl-ai-jednostki');
    var dziennik = t.querySelector('.tl-ai-dziennik');
    var start = t.querySelector('.tl-ai-start');
    var stop = t.querySelector('.tl-ai-stop');
    var jednostki = [];
    var zatrzymaj = false;

    function jezyki() {
        return Array.prototype.map.call(t.querySelectorAll('.tl-ai-jezyk:checked'), function (c) { return c.value; });
    }
    function wpisz(tekst) { dziennik.appendChild(el('li', tekst)); }
    function czekaj(s) { return new Promise(function (ok) { setTimeout(ok, s * 1000); }); }

    t.querySelector('.tl-ai-lista').addEventListener('click', function (e) {
        var b = e.currentTarget;
        b.disabled = true;
        stan.textContent = 'Liczę braki…';
        wyslij({ action: 'evk_tl_ai_lista' }).then(function (r) {
            b.disabled = false;
            lista.textContent = '';
            if (!r || !r.success) { stan.textContent = (r && r.data) || 'Błąd.'; return; }
            jednostki = r.data;
            if (!jednostki.length) { stan.textContent = 'Nie ma pustych pól języków w elementach.'; start.disabled = true; return; }
            stan.textContent = 'Części stron z brakami: ' + jednostki.length + '.';
            var wrap = el('div', null, 'evo-tbl-wrap'), tab = el('table', null, 'evo-table'), tr = el('tr');
            ['', 'Strona', 'Część', 'Braki'].forEach(function (n) { var th = el('th', n); th.setAttribute('scope', 'col'); tr.appendChild(th); });
            var thead = el('thead'); thead.appendChild(tr); tab.appendChild(thead);
            var tbody = el('tbody');
            jednostki.forEach(function (j, i) {
                var w = el('tr'), td0 = el('td'), c = el('input');
                c.type = 'checkbox'; c.checked = true; c.className = 'tl-ai-wybor'; c.setAttribute('data-i', i);
                c.setAttribute('aria-label', 'Tłumacz: ' + j.tytul + ' (' + j.czesc + ')');
                td0.appendChild(c); w.appendChild(td0);
                var td = el('td'), a = el('a', j.tytul); a.href = j.adres; td.appendChild(a); w.appendChild(td);
                w.appendChild(el('td', j.czesc));
                w.appendChild(el('td', Object.keys(j.braki).map(function (k) { return k.toUpperCase() + ': ' + j.braki[k]; }).join(', ')));
                tbody.appendChild(w);
            });
            tab.appendChild(tbody); wrap.appendChild(tab); lista.appendChild(wrap);
            start.disabled = false;
        }).catch(function () { b.disabled = false; stan.textContent = 'Błąd połączenia.'; });
    });

    stop.addEventListener('click', function () { zatrzymaj = true; stop.disabled = true; stan.textContent = 'Zatrzymuję po bieżącym kroku…'; });

    start.addEventListener('click', async function () {
        var wybrane = Array.prototype.map.call(t.querySelectorAll('.tl-ai-wybor:checked'), function (c) { return jednostki[+c.getAttribute('data-i')]; });
        var jez = jezyki();
        if (!wybrane.length || !jez.length) { stan.textContent = 'Zaznacz strony i języki.'; return; }
        zatrzymaj = false; start.disabled = true; stop.disabled = false; dziennik.textContent = '';
        var suma = { zapisane: 0, z_ai: 0, z_pamieci: 0, odrzucone: 0 }, przerwane = '';
        petla:
        for (var i = 0; i < wybrane.length; i++) {
            var j = wybrane[i];
            for (var k = 0; k < jez.length; k++) {
                var lang = jez[k];
                if (!j.braki[lang]) continue;
                var pomin = [], proby = 0;
                while (!zatrzymaj) {
                    stan.textContent = 'Tłumaczę: ' + j.tytul + ' (' + j.czesc + ') — ' + lang.toUpperCase() + '…';
                    var r;
                    try {
                        r = await wyslij({ action: 'evk_tl_ai_krok', post_id: j.post_id, meta_key: j.meta_key, lang: lang, pomin: pomin });
                    } catch (e) { r = { success: false, data: 'Błąd połączenia.' }; }
                    if (!r || !r.success) { wpisz(j.tytul + ' ' + lang.toUpperCase() + ': ' + ((r && r.data) || 'błąd')); break; }
                    var d = r.data;
                    pomin = pomin.concat(d.odrzucone || []);
                    suma.zapisane += d.zapisane; suma.z_ai += d.z_ai; suma.z_pamieci += d.z_pamieci; suma.odrzucone += (d.odrzucone || []).length;
                    wpisz(j.tytul + ' (' + j.czesc + ') ' + lang.toUpperCase() + ': zapisane ' + d.zapisane + ' (AI: ' + d.z_ai
                        + ', z pamięci: ' + d.z_pamieci + '), odrzucone: ' + (d.odrzucone || []).length + ', zostało: ' + d.zostalo
                        + (d.blad ? ' — ' + d.blad : ''));
                    if (d.blad) {
                        if (d.stop) { przerwane = d.blad; break petla; }
                        if (++proby > 5) break;
                        stan.textContent = d.blad + ' (' + d.czekaj + ' s)';
                        await czekaj(d.czekaj);
                        continue;
                    }
                    if (!d.zostalo) break;
                }
                if (zatrzymaj) break petla;
            }
        }
        start.disabled = false; stop.disabled = true;
        stan.textContent = przerwane || (zatrzymaj ? 'Zatrzymane.' : 'Gotowe.');
        wpisz('Razem: zapisane ' + suma.zapisane + ' (AI: ' + suma.z_ai + ', z pamięci: ' + suma.z_pamieci + '), odrzucone: ' + suma.odrzucone + '.');
    });
})();
</script>
