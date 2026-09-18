/**
 * Sonda wstrzykiwana przed skryptami strony — przeskok w animacji liter hero.
 *
 * ZGŁOSZONE Z UŻYCIA: „lecą litery z HARMONIĄ i po 3 czy 4 jest mini przeskok.
 * Kolejne słowa już ładują się płynnie."
 *
 * Objaw jest JEDNORAZOWY i wczesny, więc nie szukamy średniej, tylko
 * POJEDYNCZEJ nieciągłości. Zbierane są trzy rzeczy z tej samej osi czasu:
 *
 *   · długość każdej klatki — przeskok to albo jedna długa klatka,
 *   · postęp animacji liter — albo skok wartości między klatkami,
 *   · wywołania ScrollTrigger.refresh() ze śladem stosu — bo preset hero ma
 *     „scrub: 1", a odświeżenie przelicza postęp scrubowanych animacji i może
 *     nim szarpnąć. Horizontal Scroll woła „evkOdswiez(pilne)" po dojechaniu
 *     obrazów taśmy — dokładnie we wczesnej fazie.
 *
 * ODWROTNY APOSTROF JEST TU ZAKAZANY: plik jest wstrzykiwany jako łańcuch.
 */
(function () {
    window.__klatki  = [];
    window.__odswiez = [];
    window.__litery  = [];

    function skad() {
        var s = (new Error()).stack || '';
        return s.split('\n').slice(3, 6).map(function (l) {
            return l.trim().replace(/^at\s+/, '');
        }).join('  <-  ').slice(0, 200);
    }

    /* Opakowanie refresh — czekamy, aż ScrollTrigger w ogóle powstanie. */
    (function czekaj() {
        if (!window.ScrollTrigger) return setTimeout(czekaj, 20);
        var orig = ScrollTrigger.refresh.bind(ScrollTrigger);
        ScrollTrigger.refresh = function () {
            window.__odswiez.push({ t: Math.round(performance.now()), skad: skad() });
            return orig.apply(null, arguments);
        };
    })();

    /* KOSZT POJEDYNCZEGO PODZIAŁU. Rozbicie pętli na partie pomaga tylko wtedy,
       gdy drogie jest WIELE tanich elementów. Jeśli jeden podział sam w sobie
       trwa ponad budżet klatki, żadne partiowanie go nie skróci — i to trzeba
       wiedzieć, zanim się coś naprawi. */
    window.__splity = [];
    (function czekajSplit() {
        if (typeof window.SplitText === 'undefined') return setTimeout(czekajSplit, 20);
        if (window.__splityGotowe) return;
        window.__splityGotowe = true;
        ['create'].forEach(function (m) {
            if (typeof SplitText[m] !== 'function') return;
            var orig = SplitText[m].bind(SplitText);
            SplitText[m] = function (cel) {
                var t0 = performance.now();
                var wynik = orig.apply(null, arguments);
                /* CEL BYWA TABLICĄ ALBO LISTĄ WĘZŁÓW, nie pojedynczym elementem —
                   pierwsza wersja rzutowała go na łańcuch i wypisywała listę
                   adresów zamiast nazwy elementu. Normalizujemy. */
                var cele = [];
                if (cel && cel.nodeType) cele = [cel];
                else if (typeof cel === 'string') cele = Array.prototype.slice.call(document.querySelectorAll(cel));
                else if (cel && typeof cel.length === 'number') cele = Array.prototype.slice.call(cel);
                var opis = cele.slice(0, 2).map(function (e) {
                    var r = e.getBoundingClientRect();
                    return e.tagName.toLowerCase()
                        + (e.id ? '#' + e.id : '')
                        + (e.className ? '.' + String(e.className).trim().split(/\s+/).slice(0, 2).join('.') : '')
                        + '  y=' + Math.round(r.top)
                        + (r.top < innerHeight && r.bottom > 0 ? ' [W KADRZE]' : ' [poza kadrem]');
                }).join('  +  ');
                window.__splity.push({
                    t: Math.round(t0),
                    ms: Math.round((performance.now() - t0) * 10) / 10,
                    cel: (cele.length > 2 ? '(' + cele.length + ' elementów) ' : '') + opis,
                    znakow: cele.reduce(function (a, e) { return a + (e.textContent || '').trim().length; }, 0)
                });
                return wynik;
            };
        });
    })();

    /* Klatki i postęp liter. Postęp bierzemy z PIERWSZEJ litery hero: jej
       przesunięcie w pionie jest tym, co widać jako wlot. */
    var poprzednia = null;
    (function klatka(teraz) {
        if (poprzednia !== null) {
            /* CZAS BEZWZGLĘDNY, nie sama długość. Pierwsza wersja zapisywała
               tylko delty i czas klatek trzeba było sumować narastająco — a to
               inna oś niż `performance.now()` w próbkach litery. Zestawienie
               jednego z drugim było wtedy zgadywaniem. */
            window.__klatki.push({
                t: Math.round(teraz),
                d: Math.round((teraz - poprzednia) * 10) / 10
            });
        }
        poprzednia = teraz;

        var ch = document.querySelector('.hero-header__slide .evk-anim-char');
        if (ch) {
            var m = new DOMMatrixReadOnly(getComputedStyle(ch).transform);
            window.__litery.push({
                t: Math.round(teraz),
                y: Math.round(m.m42 * 10) / 10,
                o: Math.round(parseFloat(getComputedStyle(ch).opacity) * 100) / 100
            });
        }
        if (teraz < 14000) requestAnimationFrame(klatka);
    })(performance.now());
})();
