/**
 * Evoke ONE — co ma stać NAD otwartym menu
 *
 * Wspólne dla menu, które przenoszą swój panel do `<body>` i przez to zasłaniają
 * własny przełącznik: Offcanvas Menu i Circular Menu. Obie usterki zgłoszono
 * osobno i obie mają tę samą przyczynę, więc reguła stoi w jednym miejscu,
 * a nie w dwóch kopiach, które rozjadą się przy pierwszej poprawce.
 *
 * SEDNO. `z-index` porównuje się wyłącznie WEWNĄTRZ jednego kontekstu
 * nakładania. Gdy nagłówek z burgerem zakłada własny — a robi to `transform`,
 * `filter`, krycie poniżej jedynki, `position: sticky` albo po prostu `z-index`
 * na czymś pozycjonowanym, czyli rzeczy, którymi Bricks i animacje sypią
 * gęsto — to ten nagłówek rywalizuje z panelem jako JEDNA warstwa. Liczba
 * wpisana samemu burgerowi nie ma wtedy jak wyjść na zewnątrz i żadna jej
 * wysokość niczego nie zmieni.
 *
 * Wyjścia są z tego dwa i naprawdę się różnią:
 *
 *   PODNIEŚĆ NAGŁÓWEK — jedna liczba na jednym przodku. Nic nie rusza się
 *   w drzewie, a nad panel wjeżdża cały pasek RAZEM Z TŁEM.
 *
 *   WYJĄĆ ELEMENT — przenieść węzeł do `<body>`, zostawiając w jego miejscu
 *   niewidoczną przekładkę trzymającą układ. Nad panelem staje wtedy sam
 *   burger (albo burger i logo), bez ramki nagłówka.
 *
 * Która z nich jest lepsza, zależy od projektu, więc wybiera go kontrolka
 * „Co nad panelem" — a nie wtyczka za użytkownika.
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

    /**
     * PRZEKŁADKA: kopia elementu z wymuszonym pudełkiem.
     *
     * Kopia, a nie pusty prostokąt — bo pusty element blokowo-liniowy wyznacza
     * linię bazową dolną krawędzią, a taki z tekstem linią bazową swojego
     * tekstu; sąsiad przeskakiwał przez to o 17 px.
     *
     * Wymuszone pudełko, a nie sama kopia — bo identyfikator z kopii trzeba
     * zdjąć (dwa te same w dokumencie psują `getElementById` i cudze skrypty),
     * a Bricks stylizuje elementy WŁAŚNIE po identyfikatorze. Kopia bez niego
     * gubi szerokość, wysokość i wypełnienie: zmierzone przesunięcie sąsiada
     * o 43 px, tak samo w nagłówku elastycznym jak liniowym.
     *
     * Wypełnienie i obramowanie KOPIOWANE, a nie zerowane: przy wyzerowanych
     * tekst siadał wyżej niż w oryginale, więc linia bazowa całego wiersza
     * wypadała o piksel inaczej. Kolor obramowania nieistotny — przekładka
     * jest niewidoczna.
     *
     * `visibility`, a nie `opacity` — żeby wypadła też z drzewa dostępności;
     * inaczej czytnik ekranu ogłaszałby przycisk dwa razy.
     */
    function przekladka(el, r, cs) {
        var ph = el.cloneNode(true);
        ph.removeAttribute('id');
        ph.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
        ph.setAttribute('aria-hidden', 'true');
        ph.setAttribute('data-evk-przekladka', '');
        ph.style.cssText = 'box-sizing:border-box'
            + ';display:'        + cs.display
            + ';width:'          + r.width  + 'px'
            + ';height:'         + r.height + 'px'
            + ';padding:'        + cs.padding
            + ';border-width:'   + cs.borderWidth
            + ';border-style:'   + cs.borderStyle
            + ';border-color:transparent'
            + ';margin:'         + cs.margin
            + ';vertical-align:' + cs.verticalAlign
            + ';font-size:'      + cs.fontSize
            + ';line-height:'    + cs.lineHeight
            + ';visibility:hidden;pointer-events:none';
        return ph;
    }

    /** Warstwa panelu jako liczba — albo `zapas`, gdy panel jej nie ma. */
    function warstwaPanelu(panel, zapas) {
        var z = parseInt(getComputedStyle(panel).zIndex, 10);
        return isNaN(z) ? zapas : z;
    }

    /**
     * Wyjmuje element z jego kontekstu: do `<body>`, na wymierzone miejsce.
     *
     * Prostokąt wpisujemy WPROST, bo w `<body>` element nie ma już swojego
     * miejsca w układzie — a przekładka zajmuje je za niego, więc reszta
     * nagłówka stoi tam, gdzie stała.
     */
    function wyjmij(el, z, zapis) {
        var r  = el.getBoundingClientRect();
        var cs = getComputedStyle(el);
        var ph = przekladka(el, r, cs);

        el.parentNode.insertBefore(ph, el);

        // Cały atrybut `style`, a nie pojedyncze właściwości: element może mieć
        // własne wpisane wprost (burger ma tam krzywą czasu), a przywrócenie
        // kompletu jest jedynym sposobem, żeby niczego nie zgubić ani nie
        // dorobić.
        zapis.push({ el: el, ph: ph, styl: el.getAttribute('style') });

        document.body.appendChild(el);
        el.style.position = 'fixed';
        el.style.margin   = '0';
        el.style.top      = r.top    + 'px';
        el.style.left     = r.left   + 'px';
        el.style.width    = r.width  + 'px';
        el.style.height   = r.height + 'px';
        el.style.zIndex   = String(z);
    }

    /**
     * Stawia nad panelem to, co wskazuje `tryb`. Zwraca zapis do `opusc()`.
     *
     * @param {Object}    o
     * @param {Element[]} o.przelaczniki Przełączniki menu.
     * @param {Element}   o.panel        Panel albo powłoka, ponad którą podnosimy.
     * @param {string}    o.tryb         'przelacznik' | 'wskazane' | 'naglowek'
     * @param {string}    [o.selektor]   Co jeszcze wyjąć przy trybie 'wskazane'.
     * @param {number}    [o.zapas]      Warstwa panelu, gdy nie ma własnej.
     * @param {Function}  [o.ostrzez]    Jak zgłosić, że się nie da.
     * @returns {Array|null} Zapis podniesionych — `null`, gdy nic nie zrobiono.
     */
    function podnies(o) {
        var panel = o.panel;
        var z = warstwaPanelu(panel, o.zapas || 9999) + 1;
        var zapis = [];

        if (o.tryb === 'naglowek') {
            /* NAJPIERW ZEBRAĆ, POTEM PODNIEŚĆ. Przełączników bywa kilka —
               wbudowany i wskazane własnym selektorem — a wycofanie w połowie
               zostawiłoby część podniesioną, część nietkniętą. */
            var kandydaci = [];
            var dasie = true;

            o.przelaczniki.forEach(function (el) {
                if (!dasie || panel.contains(el)) return;
                var kand = konkurent(el, panel.parentElement);
                if (!kand || getComputedStyle(kand).position === 'static') { dasie = false; return; }
                if (kandydaci.indexOf(kand) < 0) kandydaci.push(kand);
            });

            if (!dasie || !kandydaci.length) {
                if (o.ostrzez) {
                    o.ostrzez('o kolejności decyduje element niepozycjonowany, a na takim '
                        + '`z-index` nie działa. Nadaj `position: relative` nagłówkowi '
                        + 'z przełącznikiem — albo wybierz w „Co nad panelem" wyjmowanie '
                        + 'samego przełącznika.');
                }
                return null;
            }

            kandydaci.forEach(function (kand) {
                zapis.push({ el: kand, ph: null, styl: kand.getAttribute('style') });
                kand.style.zIndex = String(z);
            });
            return zapis;
        }

        /* Przełącznik W ŚRODKU panelu jedzie z nim portalem do `<body>`, więc
           jest już nad wszystkim — wyrywanie go stamtąd zabrałoby go z panelu. */
        o.przelaczniki.forEach(function (el) {
            if (panel.contains(el)) return;
            wyjmij(el, z, zapis);
        });

        if (o.tryb === 'wskazane' && o.selektor) {
            var dodatkowe;
            try {
                dodatkowe = document.querySelectorAll(o.selektor);
            } catch (e) {
                if (o.ostrzez) o.ostrzez('nieprawidłowy selektor „' + o.selektor + '".');
                dodatkowe = [];
            }
            Array.prototype.forEach.call(dodatkowe, function (el) {
                // Bez powtórek: selektor bywa napisany tak, że łapie też
                // przełącznik, a wyjęcie go dwa razy zgubiłoby przekładkę.
                if (panel.contains(el)) return;
                for (var i = 0; i < zapis.length; i++) if (zapis[i].el === el) return;
                wyjmij(el, z, zapis);
            });
        }

        /* WYMUSZONY PRZELICZNIK, i to nie jest zabobon.
         *
         * Przeniesienie węzła w drzewie kasuje jego stan przejść: element,
         * którego nie było w dokumencie przy poprzednim przeliczeniu stylu, NIE
         * MA od czego animować. Klasa stanu dochodzi zaraz po tym, więc bez tej
         * linijki burger PRZESKAKIWAŁ do wyglądu otwartego — bez przejścia
         * kolorów i bez ruchu kresek. Przy zamykaniu animował się normalnie, bo
         * tam klasa schodzi, gdy węzeł od dawna siedzi w `<body>`. Dokładnie tak
         * to zgłoszono: „nie animuje się do stanu otwartego, tylko przy
         * zamknięciu". Jeden odczyt na cały dokument wystarcza dla wszystkich.
         */
        if (zapis.length) void document.body.offsetHeight;

        return zapis.length ? zapis : null;
    }

    /** Cofa wszystko, co zrobił `podnies()` — w obu trybach. */
    function opusc(zapis) {
        if (!zapis) return;
        zapis.forEach(function (w) {
            /* Wraca DOKŁADNIE to, co było — łącznie z brakiem atrybutu.
               Podstawienie pustego ciągu zostawiłoby po nas `style=""`,
               a zera w `z-index` — warstwę, której wcześniej nie było. */
            if (w.styl === null) w.el.removeAttribute('style');
            else                 w.el.setAttribute('style', w.styl);

            // Podniesienie warstwy nie ruszało drzewa, więc nie ma przekładki
            // do wyjęcia — sam styl wrócił linijkę wyżej.
            if (!w.ph) return;
            if (w.ph.parentNode) {
                w.ph.parentNode.insertBefore(w.el, w.ph);
                w.ph.parentNode.removeChild(w.ph);
            }
        });
    }

    window.evkWarstwy = {
        tworzyKontekst: tworzyKontekst,
        konkurent: konkurent,
        podnies: podnies,
        opusc: opusc,
    };
})();
