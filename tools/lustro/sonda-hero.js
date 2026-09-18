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

    /* Klatki i postęp liter. Postęp bierzemy z PIERWSZEJ litery hero: jej
       przesunięcie w pionie jest tym, co widać jako wlot. */
    var poprzednia = null;
    (function klatka(teraz) {
        if (poprzednia !== null) {
            window.__klatki.push(Math.round((teraz - poprzednia) * 10) / 10);
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
