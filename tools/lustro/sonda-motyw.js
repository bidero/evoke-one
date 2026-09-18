/**
 * Sonda wstrzykiwana przed skryptami strony (document-start).
 *
 * OSOBNY PLIK, A NIE ŁAŃCUCH W `zmierz-motyw-start.js`. Kod, który ma logować
 * ślady stosu, sam musi być czytelny w narzędziach przeglądarki — a wklejony
 * w literał szablonowy przestaje nim być i wpada w pułapkę odwrotnego
 * apostrofu opisaną w CLAUDE.md. Tu wolno pisać normalnie.
 *
 * Łapie KAŻDY zapis, który potrafi zmienić motyw, razem ze śladem stosu:
 *
 *   · `setAttribute` na korzeniu dokumentu,
 *   · przypisanie do `documentElement.dataset.*` (Bricks pisze właśnie tak,
 *     a to NIE przechodzi przez `setAttribute`),
 *   · `classList.add/remove/toggle`,
 *   · `localStorage.setItem('brx_mode', …)` — bo to ten zapis decyduje,
 *     co przeczytają oba skrypty przy następnym ładowaniu.
 *
 * Do tego pierwsze malowanie, żeby było wiadomo, co widzi człowiek.
 */
(function () {
    var CIEKAWE = ['data-theme', 'data-brx-theme', 'class'];
    window.__zapisy = [];
    window.__malowanie = [];

    function skad(pomin) {
        /* ŚLAD STOSU SPRAWCY. Sonda jest wstrzykiwana, więc w stosie figuruje
           jako „<anonymous>" — filtrowanie po nazwie pliku nic nie da (pierwsza
           wersja tak robiła i wypisywała samą siebie). Odliczamy więc ramki
           własne: `skad`, `zapisz` i owijkę, którą sonda podstawiła. */
        var s = (new Error()).stack || '';
        var linie = s.split('\n');
        // Chrome zaczyna stos od wiersza „Error"; ramki idą od drugiego.
        var od = (linie[0] || '').indexOf('Error') === 0 ? 1 : 0;
        var reszta = linie.slice(od + (pomin || 3));
        var opis = reszta.slice(0, 3).map(function (l) {
            return l.trim().replace(/^at\s+/, '');
        }).filter(Boolean).join('  ←  ');
        return opis.slice(0, 220) || '(bez stosu)';
    }

    function zapisz(co, wartosc) {
        window.__zapisy.push({
            t: Math.round(performance.now()),
            co: co,
            wartosc: String(wartosc).slice(0, 60),
            skad: skad()
        });
    }

    // ── setAttribute na korzeniu ────────────────────────────────────────────
    var origSet = Element.prototype.setAttribute;
    Element.prototype.setAttribute = function (nazwa, wartosc) {
        if (this === document.documentElement && CIEKAWE.indexOf(nazwa) !== -1) {
            zapisz('setAttribute ' + nazwa, wartosc);
        }
        return origSet.apply(this, arguments);
    };

    // ── przypisanie do `dataset` ────────────────────────────────────────────
    /* Bricks robi `document.documentElement.dataset.brxTheme = t`, co omija
       `setAttribute` całkowicie. Podmieniamy getter `dataset` na prototypie
       i owijamy zwracaną mapę pułapką na zapis. */
    var opis = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'dataset');
    if (opis && opis.get && typeof Proxy === 'function') {
        Object.defineProperty(HTMLElement.prototype, 'dataset', {
            configurable: true,
            get: function () {
                var mapa = opis.get.call(this);
                if (this !== document.documentElement) return mapa;
                return new Proxy(mapa, {
                    set: function (cel, klucz, wartosc) {
                        zapisz('dataset.' + String(klucz), wartosc);
                        cel[klucz] = wartosc;
                        return true;
                    }
                });
            }
        });
    }

    // ── classList ───────────────────────────────────────────────────────────
    ['add', 'remove', 'toggle'].forEach(function (m) {
        var orig = DOMTokenList.prototype[m];
        DOMTokenList.prototype[m] = function () {
            var wynik = orig.apply(this, arguments);
            if (document.documentElement && this === document.documentElement.classList) {
                zapisz('classList.' + m, Array.prototype.join.call(arguments, ' '));
            }
            return wynik;
        };
    });

    // ── localStorage ────────────────────────────────────────────────────────
    try {
        var origStore = Storage.prototype.setItem;
        Storage.prototype.setItem = function (k, v) {
            if (k === 'brx_mode') zapisz('localStorage brx_mode', v);
            return origStore.apply(this, arguments);
        };
    } catch (e) { /* prywatne okno — trudno */ }

    // ── malowanie ───────────────────────────────────────────────────────────
    /* Co widzi człowiek i KIEDY. Zapisujemy stan motywu w chwili malowania,
       bo dopiero to mówi, czy strona pokazała się w złym kolorze. */
    try {
        new PerformanceObserver(function (lista) {
            lista.getEntries().forEach(function (w) {
                window.__malowanie.push({
                    nazwa: w.name,
                    t: Math.round(w.startTime),
                    theme: document.documentElement.getAttribute('data-theme'),
                    brx: document.documentElement.getAttribute('data-brx-theme')
                });
            });
        }).observe({ type: 'paint', buffered: true });
    } catch (e) { /* brak wsparcia */ }
})();
