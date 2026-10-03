<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Statystyki: podgląd hotspotów (1.286.0).
 *
 * Raport z parametrem `hotspoty=<strona>`: strona w ramce (ten sam adres
 * z `evk_hot_podglad=1` — bez paska admina i bez liczenia) o szerokości
 * urządzenia, nakładka rysowana WEWNĄTRZ ramki (ta sama domena), więc
 * przewija się razem ze stroną. Kliknięcie trafia w element z selektora
 * i miejsce w nim (0–1000); gdy elementu już nie ma — w miejsce na stronie
 * przeskalowane do szerokości ramki.
 *
 * 1.292.0 (zgłoszenie: na mniejszym ekranie komputera podgląd pokazywał wersję
 * mobilną): ramka ma STAŁĄ szerokość urządzenia i pomniejsza się (transform)
 * do miejsca, które jest — strona w środku dalej widzi 1440 px. Analiza
 * (podsumowanie, lista) nie zabiera już ramce miejsca: wysuwa się z prawej
 * na przycisk „Analiza” i zamyka ✕ albo Esc.
 */

function evk_stat_hot_render_podglad(string $strona): void {
    $urz = sanitize_key($_GET['urz'] ?? 'komputer');
    if (!isset(EVK_STAT_HOT_SZEROKOSCI[$urz])) $urz = 'komputer';
    $st = evk_stat_hot_stan($strona);
    $dane = evk_stat_hot_dane($strona, $urz);
    $adres = add_query_arg('evk_hot_podglad', '1', home_url($strona));
    $nazwy = ['telefon' => 'Telefon', 'tablet' => 'Tablet', 'komputer' => 'Komputer'];
    wp_enqueue_style('evoke-one-admin', EVOKE_ONE_URL . 'assets/admin/admin.css', [], EVOKE_ONE_VERSION);
    ?>
    <div class="wrap evk-stat evk-hot">
        <style>
            .evk-hot-gora { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; margin: 12px 0; }
            .evk-hot-grupa { display: flex; flex-wrap: wrap; gap: 4px; }
            .evk-hot-grupa .button[aria-pressed="true"], .evk-hot-grupa .button[aria-current="page"] { background: #2271b1; border-color: #2271b1; color: #fff; }
            .evk-hot-uklad { position: relative; }
            .evk-hot-ramka-box { min-width: 0; overflow-x: auto; background: #f0f0f1; border: 1px solid #dcdcde; border-radius: 8px; padding: 12px; }
            .evk-hot-ramka { display: block; margin: 0 auto; height: 80vh; min-height: 480px; border: 0; background: #fff; box-shadow: 0 1px 4px rgba(0,0,0,.15); transform-origin: 0 0; }
            /* Wysuwana analiza: klasa, nie atrybut hidden — ten przegrywa z każdą regułą display (CLAUDE.md). */
            .evk-hot-panel { display: none; position: absolute; top: 12px; right: 12px; z-index: 5; width: 360px; max-width: calc(100% - 24px); max-height: calc(100% - 24px);
                overflow: auto; box-sizing: border-box; padding: 12px; background: #fff; border: 1px solid #dcdcde; border-radius: 8px; box-shadow: 0 8px 28px rgba(0,0,0,.2); }
            .evk-hot-panel.is-otwarty { display: block; }
            .evk-hot-panel-gora { display: flex; justify-content: space-between; align-items: center; gap: 8px; margin: 0 0 8px; }
            .evk-hot-panel-gora h2 { margin: 0; font-size: 15px; }
            .evk-hot-panel .evo-box { margin: 0 0 12px; }
            .evk-hot-panel .evo-box:last-child { margin-bottom: 0; }
            .evk-hot-panel table tr.is-aktywny td { background: #f0f6fc; }
            .evk-hot-panel .num { text-align: right; white-space: nowrap; }
            .evk-hot-legenda { display: flex; align-items: center; gap: 8px; font-size: 12px; color: #50575e; }
            .evk-hot-legenda i { display: inline-block; width: 120px; height: 10px; border-radius: 5px; background: linear-gradient(90deg, #2a78d6, #1baf7a, #f2c12e, #eb6834, #d1242f); }
        </style>
        <h1>Hotspoty: <code><?php echo esc_html($strona); ?></code></h1>
        <p><a href="<?php echo esc_url(evk_stat_adres_raportu()); ?>">← Raport</a>
            <?php if ($st): ?> · <?php echo esc_html($st['nagrywa']
                ? sprintf('Nagrywanie do %s albo %d wizyt (jest %d)', wp_date('j.m.Y', $st['do']), $st['limit'], $st['wizyty'])
                : sprintf('Nagrywanie zakończone %s (%d wizyt)', wp_date('j.m.Y', $st['koniec']), $st['wizyty'])); ?><?php endif; ?></p>
        <div class="evk-hot-gora">
            <nav class="evk-hot-grupa" aria-label="Urządzenie">
                <?php foreach ($nazwy as $k => $n): ?>
                <a class="button" href="<?php echo esc_url(evk_stat_hot_adres_podgladu($strona, $k)); ?>"<?php echo $k === $urz ? ' aria-current="page"' : ''; ?>><?php echo esc_html($n . ' (' . (int) $dane['urzadzenia'][$k] . ')'); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="evk-hot-grupa" role="group" aria-label="Widok">
                <button type="button" class="button" data-widok="kliki" aria-pressed="true">Kliknięcia</button>
                <button type="button" class="button" data-widok="przewiniecie" aria-pressed="false">Przewinięcie</button>
            </div>
            <label class="evo-check-row"><input type="checkbox" class="evk-hot-znaczniki" checked> Złość i martwe kliknięcia</label>
            <span class="evk-hot-legenda" aria-hidden="true">mało <i></i> dużo</span>
            <button type="button" class="button evk-hot-analiza" aria-expanded="false" aria-controls="evk-hot-panel">Analiza</button>
        </div>
        <div class="evk-hot-uklad">
            <div class="evk-hot-ramka-box">
                <iframe class="evk-hot-ramka" title="Strona z nakładką hotspotów" src="<?php echo esc_url($adres); ?>"
                    data-szer="<?php echo (int) EVK_STAT_HOT_SZEROKOSCI[$urz]; ?>" style="width:<?php echo (int) EVK_STAT_HOT_SZEROKOSCI[$urz]; ?>px"></iframe>
            </div>
            <div class="evk-hot-panel" id="evk-hot-panel" role="region" aria-labelledby="evk-hot-panel-tytul">
                <div class="evk-hot-panel-gora">
                    <h2 id="evk-hot-panel-tytul">Analiza</h2>
                    <button type="button" class="button evk-hot-zamknij" aria-label="Zamknij analizę">✕</button>
                </div>
                <div class="evo-box">
                    <h3>Podsumowanie</h3>
                    <p class="evk-hot-podsumowanie"><?php echo esc_html(sprintf('%d odsłon · %d kliknięć', count($dane['przewiniecia']), count($dane['kliki']))); ?></p>
                    <p class="evo-hint">Martwe kliknięcie: poza linkiem i przyciskiem, a przez 1 s nic się na stronie nie zmieniło. Na stronach z ciągłą animacją (suwaki) martwych prawie nie będzie.</p>
                </div>
                <div class="evo-box">
                    <h3>Najczęściej klikane</h3>
                    <div class="evo-tbl-wrap"><table class="evo-table evk-hot-elementy">
                        <thead><tr><th scope="col">Element</th><th scope="col" class="num">Kliknięcia</th><th scope="col" class="num">Złość</th><th scope="col" class="num">Martwe</th></tr></thead>
                        <tbody></tbody>
                    </table></div>
                </div>
            </div>
        </div>
        <script type="application/json" id="evk-hot-dane"><?php echo wp_json_encode($dane, JSON_HEX_TAG | JSON_HEX_AMP); ?></script>
        <script>
        (function () {
            var dane = JSON.parse(document.getElementById('evk-hot-dane').textContent), ramka = document.querySelector('.evk-hot-ramka');
            var widok = 'kliki', znaczniki = true, grupy = {}, lista = [];
            /* Elementy listy: po etykiecie i selektorze. */
            dane.kliki.forEach(function (k) {
                var klucz = k[0];
                var g = grupy[klucz] || (grupy[klucz] = { s: k[0], l: k[1], n: 0, z: 0, m: 0 });
                g.n++; g.z += k[7]; g.m += k[8];
            });
            lista = Object.keys(grupy).map(function (x) { return grupy[x]; }).sort(function (a, b) { return b.n - a.n; }).slice(0, 15);
            var tbody = document.querySelector('.evk-hot-elementy tbody');
            lista.forEach(function (g) {
                var tr = document.createElement('tr');
                [g.l || g.s, String(g.n) + ' (' + Math.round(g.n / dane.kliki.length * 100) + '%)', String(g.z), String(g.m)].forEach(function (t, i) {
                    var td = document.createElement('td'); td.textContent = t; if (i) td.className = 'num'; tr.appendChild(td);
                });
                tr.tabIndex = 0;
                tr.title = g.s;
                function pokaz(wl) {
                    var dok = ramka.contentDocument, el = dok && dok.querySelector(g.s);
                    tr.classList.toggle('is-aktywny', wl);
                    if (el) { el.style.outline = wl ? '3px solid #d1242f' : ''; el.style.outlineOffset = wl ? '2px' : ''; if (wl) el.scrollIntoView({ block: 'center', behavior: 'smooth' }); }
                }
                tr.addEventListener('mouseenter', function () { pokaz(true); });
                tr.addEventListener('mouseleave', function () { pokaz(false); });
                tr.addEventListener('focus', function () { pokaz(true); });
                tr.addEventListener('blur', function () { pokaz(false); });
                tbody.appendChild(tr);
            });
            if (!lista.length) { var tr = document.createElement('tr'), td = document.createElement('td'); td.colSpan = 4; td.textContent = 'Brak kliknięć na tym urządzeniu.'; tr.appendChild(td); tbody.appendChild(tr); }

            /* Kolory mapy: niebieski → zielony → żółty → pomarańczowy → czerwony. */
            var PALETA = [[42, 120, 214], [27, 175, 122], [242, 193, 46], [235, 104, 52], [209, 36, 47]];
            function kolor(t) {
                var p = Math.min(PALETA.length - 1.001, Math.max(0, t) * (PALETA.length - 1)), i = Math.floor(p), f = p - i, a = PALETA[i], b = PALETA[i + 1];
                return [a[0] + (b[0] - a[0]) * f, a[1] + (b[1] - a[1]) * f, a[2] + (b[2] - a[2]) * f];
            }
            function rysuj() {
                var dok = ramka.contentDocument;
                if (!dok || !dok.body) return;
                var stare = dok.getElementById('evk-hot-nakladka');
                if (stare) stare.remove();
                var w = dok.documentElement.scrollWidth, h = Math.min(dok.documentElement.scrollHeight, 16000);
                var warstwa = dok.createElement('div');
                warstwa.id = 'evk-hot-nakladka';
                warstwa.style.cssText = 'position:absolute;left:0;top:0;width:' + w + 'px;height:' + h + 'px;pointer-events:none;z-index:2147483647';
                var pl = dok.createElement('canvas');
                pl.width = w; pl.height = h; pl.style.cssText = 'position:absolute;left:0;top:0;width:' + w + 'px;height:' + h + 'px';
                warstwa.appendChild(pl);
                dok.documentElement.appendChild(warstwa);
                var cx = pl.getContext('2d'), okno = ramka.contentWindow, punkty = [], cache = {};
                dane.kliki.forEach(function (k) {
                    var el = cache[k[0]];
                    if (el === undefined) { try { el = dok.querySelector(k[0]); } catch (e) { el = null; } cache[k[0]] = el; }
                    var x, y;
                    if (el && el.getClientRects().length) {
                        var r = el.getBoundingClientRect();
                        x = r.left + okno.scrollX + k[2] / 1000 * r.width; y = r.top + okno.scrollY + k[3] / 1000 * r.height;
                    } else { x = k[6] ? k[4] * w / k[6] : k[4]; y = k[5]; }
                    punkty.push([x, y, k[7], k[8]]);
                });
                if (widok === 'kliki') {
                    /* Mapa ciepła: plamy z przezroczystością na osobnej warstwie, potem kolor z gęstości. */
                    var R = 24;
                    cx.globalCompositeOperation = 'source-over';
                    punkty.forEach(function (p) {
                        var g = cx.createRadialGradient(p[0], p[1], 0, p[0], p[1], R);
                        g.addColorStop(0, 'rgba(0,0,0,0.25)'); g.addColorStop(1, 'rgba(0,0,0,0)');
                        cx.fillStyle = g; cx.fillRect(p[0] - R, p[1] - R, 2 * R, 2 * R);
                    });
                    var img = cx.getImageData(0, 0, w, h), px = img.data, max = 0, i;
                    for (i = 3; i < px.length; i += 4) if (px[i] > max) max = px[i];
                    for (i = 0; i < px.length; i += 4) {
                        var a = px[i + 3];
                        if (!a) continue;
                        var c = kolor(a / (max || 1));
                        px[i] = c[0]; px[i + 1] = c[1]; px[i + 2] = c[2]; px[i + 3] = Math.min(220, 60 + a / (max || 1) * 180);
                    }
                    cx.putImageData(img, 0, 0);
                    if (znaczniki) {
                        punkty.forEach(function (p) {
                            if (!p[2] && !p[3]) return;
                            var z = dok.createElement('div');
                            z.className = 'evk-hot-znacznik';
                            z.setAttribute('data-rodzaj', p[2] ? 'zlosc' : 'martwe');
                            z.textContent = p[2] ? 'złość' : 'martwe';
                            z.style.cssText = 'position:absolute;left:' + Math.round(p[0] + 8) + 'px;top:' + Math.round(p[1] - 9) + 'px;font:600 11px/18px sans-serif;padding:0 6px;border-radius:9px;color:#fff;background:' + (p[2] ? '#d1242f' : '#50575e');
                            warstwa.appendChild(z);
                        });
                    }
                } else {
                    /* Mapa przewinięcia: jaki odsetek odsłon dotarł do danej wysokości (przewinięcie = dół okna / wysokość dokumentu). */
                    var n = dane.przewiniecia.length, dokH = dok.documentElement.scrollHeight;
                    for (var p = 0; p < 100; p++) {
                        var ile = 0;
                        dane.przewiniecia.forEach(function (v) { if (v > p) ile++; });
                        var t = n ? ile / n : 0, c = kolor(t);
                        cx.fillStyle = 'rgba(' + Math.round(c[0]) + ',' + Math.round(c[1]) + ',' + Math.round(c[2]) + ',0.35)';
                        cx.fillRect(0, dokH * p / 100, w, dokH / 100 + 1);
                    }
                    [100, 75, 50, 25].forEach(function (proc) {
                        /* Najgłębsze miejsce, do którego dotarło co najmniej proc% odsłon. */
                        var s = dane.przewiniecia.slice().sort(function (a, b) { return b - a; }), idx = Math.ceil(n * proc / 100) - 1;
                        if (!n || idx < 0) return;
                        var y = Math.round(dokH * s[idx] / 100) - 1, l = dok.createElement('div');
                        l.className = 'evk-hot-linia';
                        l.textContent = proc === 50 ? 'Połowa odwiedzających dociera tutaj (' + proc + '%)' : proc + '% odwiedzających dociera tutaj';
                        l.style.cssText = 'position:absolute;left:0;right:0;top:' + y + 'px;border-top:2px dashed #1d2327;font:600 12px/1.4 sans-serif;color:#fff;background:transparent';
                        var e2 = dok.createElement('span');
                        e2.style.cssText = 'position:absolute;left:8px;top:2px;background:#1d2327;padding:2px 8px;border-radius:4px';
                        e2.textContent = l.textContent; l.textContent = ''; l.appendChild(e2);
                        warstwa.appendChild(l);
                    });
                }
                warstwa.setAttribute('data-punkty', String(punkty.length));
            }
            var czas;
            function pozniej() { clearTimeout(czas); czas = setTimeout(rysuj, 200); }

            /* Stała szerokość urządzenia, pomniejszona do miejsca w ramce. Ujemne marginesy
               oddają miejsce, które transform zostawia w układzie, więc nic nie przewija się w bok. */
            var box = document.querySelector('.evk-hot-ramka-box'), SZER = Number(ramka.getAttribute('data-szer')) || 0;
            function dopasuj() {
                var st = getComputedStyle(box), miejsce = box.clientWidth - parseFloat(st.paddingLeft) - parseFloat(st.paddingRight);
                var s = SZER && miejsce > 0 ? Math.min(1, miejsce / SZER) : 1, h = Math.max(480, Math.round(window.innerHeight * 0.8));
                ramka.style.height = Math.round(h / s) + 'px';
                ramka.style.transform = s < 1 ? 'scale(' + s + ')' : '';
                ramka.style.margin = s < 1 ? '0 ' + (SZER * s - SZER) + 'px ' + (h - h / s) + 'px 0' : '0 auto';
                ramka.setAttribute('data-skala', s.toFixed(3));
            }
            dopasuj();
            if (window.ResizeObserver) new ResizeObserver(dopasuj).observe(box); else window.addEventListener('resize', dopasuj);

            /* Wysuwana analiza: przycisk ze stanem aria-expanded, ✕ i Esc zamykają i oddają fokus przyciskowi. */
            var panel = document.getElementById('evk-hot-panel'), przycisk = document.querySelector('.evk-hot-analiza');
            function analiza(otworz, fokus) {
                panel.classList.toggle('is-otwarty', otworz);
                przycisk.setAttribute('aria-expanded', String(otworz));
                if (fokus) (otworz ? panel.querySelector('.evk-hot-zamknij') : przycisk).focus();
            }
            przycisk.addEventListener('click', function () { analiza(!panel.classList.contains('is-otwarty'), true); });
            panel.querySelector('.evk-hot-zamknij').addEventListener('click', function () { analiza(false, true); });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && panel.classList.contains('is-otwarty')) analiza(false, true); });
            ramka.addEventListener('load', function () {
                rysuj();
                setTimeout(rysuj, 1500); /* późny układ (pisma, obrazy, animacje wejścia) */
                ramka.contentWindow.addEventListener('resize', pozniej);
            });
            document.querySelectorAll('[data-widok]').forEach(function (b) {
                b.addEventListener('click', function () {
                    widok = b.getAttribute('data-widok');
                    document.querySelectorAll('[data-widok]').forEach(function (x) { x.setAttribute('aria-pressed', String(x === b)); });
                    rysuj();
                });
            });
            document.querySelector('.evk-hot-znaczniki').addEventListener('change', function (e) { znaczniki = e.target.checked; rysuj(); });
        })();
        </script>
    </div>
    <?php
}
