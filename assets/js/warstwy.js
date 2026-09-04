/**
 * Evoke ONE — kto z kim rywalizuje o warstwę
 *
 * Wspólne dla menu, które przenoszą swój panel do `<body>` i przez to zasłaniają
 * własny przełącznik: Offcanvas Menu i Circular Menu. Obie miały ten sam problem
 * i obie zgłoszone osobno, więc reguła stoi w jednym miejscu, a nie w dwóch
 * kopiach, które rozjadą się przy pierwszej poprawce.
 *
 * SEDNO. `z-index` porównuje się wyłącznie WEWNĄTRZ jednego kontekstu
 * nakładania. Gdy nagłówek z burgerem zakłada własny — a robi to `transform`,
 * `filter`, krycie poniżej jedynki, `position: sticky` albo po prostu `z-index`
 * na czymś pozycjonowanym, czyli rzeczy, którymi Bricks i animacje sypią gęsto —
 * to ten nagłówek rywalizuje z panelem jako JEDNA warstwa. Liczba wpisana
 * samemu burgerowi nie ma wtedy jak wyjść na zewnątrz i żadna jej wysokość
 * niczego nie zmieni.
 */

(function () {
    'use strict';

    /** Czy ten styl zakłada elementowi WŁASNY kontekst nakładania. */
    function tworzyKontekst(cs) {
        if (cs.position === 'fixed' || cs.position === 'sticky') return true;
        if (cs.position !== 'static' && cs.zIndex !== 'auto') return true;
        if (parseFloat(cs.opacity) < 1) return true;
        if (cs.transform !== 'none') return true;
        if (cs.filter && cs.filter !== 'none') return true;
        if (cs.perspective && cs.perspective !== 'none') return true;
        if (cs.backdropFilter && cs.backdropFilter !== 'none') return true;
        if (cs.clipPath && cs.clipPath !== 'none') return true;
        if (cs.mixBlendMode && cs.mixBlendMode !== 'normal') return true;
        if (cs.isolation === 'isolate') return true;
        if (cs.contain && /paint|layout|strict|content/.test(cs.contain)) return true;
        if (cs.willChange
            && /transform|opacity|filter|perspective|contain|isolation/.test(cs.willChange)) return true;
        return false;
    }

    /**
     * Element, który STANIE DO PORÓWNANIA z panelem — albo `null`.
     *
     * @param {Element} od      Przełącznik, od którego idziemy w górę.
     * @param {Element} granica Rodzic panelu — dokąd iść.
     *
     * Szukamy NAJDALSZEGO przodka przełącznika, który zakłada własny kontekst:
     * to on stoi z panelem w tym samym porównaniu. Gdy takiego nie ma, do
     * porównania staje najbliższy przodek pozycjonowany (albo sam przełącznik,
     * jeśli to on nim jest) — bo elementy niepozycjonowane w tym porównaniu
     * nie biorą udziału.
     *
     * `granica` rozstrzyga, jak daleko iść, i nie jest ostrożnością. Przy
     * przeniesieniu panelu do `<body>` idziemy przez cały nagłówek. Bez
     * przeniesienia panel siedzi obok przełącznika, więc porównanie odbywa się
     * tam — a pójście wyżej wskazałoby nagłówek, którego podniesienie wyniosłoby
     * panel razem z nim. Nic by się nie zmieniło, a nagłówek zostałby ruszony.
     */
    function konkurent(od, granica) {
        var najdalszyKontekst = null;
        var najblizszyPozycjonowany = null;

        for (var a = od;
             a && a !== granica && a !== document.body && a !== document.documentElement;
             a = a.parentElement) {
            var cs = getComputedStyle(a);
            if (!najblizszyPozycjonowany && cs.position !== 'static') najblizszyPozycjonowany = a;
            if (tworzyKontekst(cs)) najdalszyKontekst = a;
        }
        return najdalszyKontekst || najblizszyPozycjonowany;
    }

    window.evkWarstwy = {
        tworzyKontekst: tworzyKontekst,
        konkurent: konkurent,
    };
})();
