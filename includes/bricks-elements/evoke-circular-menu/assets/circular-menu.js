/**
 * Evoke Circular Menu
 * v1.6.0
 *
 * evk_circular_menu_init() jest wołane z dwóch stron: przez Bricks (patrz
 * $scripts w element.php) i przez własny DOMContentLoaded poniżej. Flaga
 * data-evk-cm-ready pilnuje, żeby jeden korzeń zainicjalizował się raz —
 * bez niej stackowałyby się listenery, a przy włączonym portalu drugi
 * przebieg nie znalazłby panelu (jest już przeniesiony do <body>).
 */

/**
 * Górna granica czekania na wyjście treści, w sekundach.
 *
 * Animacja ustawiona na osiem sekund zostawiłaby menu otwarte przez osiem
 * sekund po kliknięciu ✕ — a to już nie jest efekt, tylko zawieszenie. Resztę
 * treść dokańcza pod zwijającym się kadrem.
 */
var EVK_CM_EXIT_MAX = 1;

/**
 * Klasa, którą Bricks zakłada po otwarciu — I PRZYCISKOWI, I OTWIERANEMU
 * ELEMENTOWI.
 *
 * Cała animacja burgera zbudowanego w Bricksie — kreski składające się
 * w krzyżyk — wisi w arkuszu na tej klasie. Bez niej przycisk zostaje
 * burgerem przy otwartym menu i wygląda, jakby kliknięcie nie zadziałało.
 *
 * Do 1.73.0 nakładaliśmy ją WYŁĄCZNIE na przycisk, i to była połowa roboty.
 * Bricks trzyma stan na elemencie, który otwiera, a wygląd przełącznika bywa
 * z niego wyprowadzony — regułą typu `.brx-open .brxe-toggle` albo własną
 * logiką przełącznika, która pyta o stan CELU. Nasze menu nie zgłaszało się
 * tam w ogóle: `is-open` to nazwa Evoke, dla Bricksa nic nie znacząca.
 * Przycisk nie miał więc od czego wrócić do burgera po zamknięciu z Esc —
 * bo z jego punktu widzenia menu nigdy się nie otworzyło. Zgłoszone z użycia.
 */
var EVK_BRICKS_OPEN = 'brx-open';

/**
 * Klasy stanu nakładane PRZEŁĄCZNIKOWI. Nie jedna — bo konwencji jest kilka
 * i żadna nie jest „tą właściwą".
 *
 * `brx-open` zakłada Bricks swoim otwartym elementom. `is-active` to konwencja
 * samych burgerów i na niej stoi większość gotowych animacji kresek — również
 * ta, która przyszła ze zgłoszenia („trzeba zdjąć klasę is-active
 * z przełącznika, wtedy się zamyka").
 *
 * Nakładamy OBIE, zamiast zgadywać, która obowiązuje. Klasa stanu na
 * przycisku, którego rolą jest otwieranie menu, nie ma jak zaszkodzić —
 * a każde kolejne zgłoszenie tej rodziny kosztowało wersję.
 *
 * Trzecia konwencja — `<pierwsza-klasa>--opened` — jest wyliczana z klas
 * przycisku i doszyta w updateTriggerState(). Czwartą i dalsze dopisuje się
 * w kontrolce „Klasy otwarcia przełącznika", bez ruszania kodu.
 */
var EVK_CM_TOGGLE_OPEN = ['brx-open', 'is-active'];

/**
 * Co uznajemy za SAM przełącznik, gdy selektor wskazuje na jego opakowanie.
 *
 * `.brxe-toggle` stoi tu obok znaczników HTML-owych i to nie jest ozdoba:
 * burger Bricksa nie zawsze jest przyciskiem — bywa zwykłym divem bez roli,
 * a to właśnie na nim wisi arkusz z animacją kresek. Bez tej pozycji szukanie
 * kończyło się niczym i klasa lądowała na opakowaniu, czyli o jeden poziom
 * za wysoko: w drzewie było `brx-open`, a arkusz Bricksa i tak go nie widział.
 */
var EVK_CM_TOGGLE_SEL = 'button, a, [role="button"], .brxe-toggle';

/**
 * Klasa stanu na panelu — zaczep dla CSS-a, którego menu wcześniej nie miało.
 *
 * Offcanvas ma ją na powłoce od początku; Circular Menu nie miało czego
 * zaczepić, bo chowa panel OBCIĘCIEM (`clip-path`), a nie klasą. Wszystko,
 * co ma reagować na otwarcie — a nie da się tego wyrazić animacją Animatora —
 * wisiało dotąd w próżni.
 *
 * Siedzi na PANELU, nie na korzeniu, i to jest istotne: przy włączonym portalu
 * panel jedzie do `<body>` i przestaje być potomkiem korzenia, więc selektor
 * `.evk-cm.is-open .evk-cm-content` nie miałby czego dopasować. Ta sama zasada
 * co przy `is-open` na powłoce offcanvas.
 */
var EVK_CM_OPEN = 'is-open';

/**
 * Znacznik „odsłoń panel, bo jesteśmy w builderze".
 *
 * Nakłada go WYŁĄCZNIE gałąź `isBuilder && openBuilder` na dole tego pliku
 * i to jest cała jego rola: arkusz ma malować stan, a nie go wymyślać.
 * Do 1.86.0 arkusz decydował sam, po samym ustawieniu elementu — i na froncie
 * przy wyłączonym portalu zostawiał menu otwarte na stałe.
 */
var EVK_CM_BUILDER = 'evk-cm-builder';

function evk_circular_menu_init() {
    document.querySelectorAll( '.evk-cm' ).forEach( function( root ) {
        if ( root.dataset.evkCmReady === '1' ) return;
        // Flaga dopiero po udanej inicjalizacji — element bez panelu (np. świeżo
        // wstawiony w builderze, zanim powstaną dzieci) ma szansę przy kolejnym wywołaniu.
        if ( evk_circular_menu_init_one( root ) ) root.dataset.evkCmReady = '1';
    } );
}

function evk_circular_menu_init_one( root ) {

    var isBuilder   = ! bricksIsFrontend;
    var openBuilder = root.getAttribute( 'data-open-builder' ) === '1';
    var usePortal   = root.getAttribute( 'data-portal' ) !== '0';
    // Redukcja ruchu: menu MUSI się nadal otwierać i zamykać — zerujemy sam czas
    // trwania, więc clip-path przeskakuje zamiast się rozwijać. Wyłączenie
    // animacji nie może odebrać dostępu do nawigacji.
    // Wspólna polityka: includes/anim/motion.php.
    var reduced = ( window.evkMotion && typeof window.evkMotion.reduced === 'function' )
        ? window.evkMotion.reduced()
        : !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );

    var duration    = reduced ? 0 : ( parseFloat( root.getAttribute( 'data-duration' ) ) || 0.4 );
    var easing      = root.getAttribute( 'data-easing' ) || 'none';
    // Odstęp między rozwinięciem kadru a ruszeniem treści. Bez niego oba ruchy
    // startują w tej samej klatce i nie widać, co po czym następuje — ten sam
    // problem, który w offcanvas rozwiązało opóźnienie panelu podrzędnego.
    var contentDelay = reduced ? 0 : ( parseFloat( root.getAttribute( 'data-content-delay' ) ) || 0 );
    var animateExit  = root.getAttribute( 'data-anim-exit' ) === '1';
    /* Ile czekać z zamykaniem kadru na wyjście treści.
       `null` znaczy „cały czas animacji" (ruchy jeden PO drugim), liczba —
       tyle sekund, ile podano. Zero to nie brak ustawienia, tylko wybór:
       kadr zwija się RAZEM z wychodzącą treścią. Stąd rozróżnienie na null
       zamiast zwykłej domyślki — `|| 0` zjadałoby jawne zero. */
    var exitWaitRaw = root.getAttribute( 'data-exit-wait' );
    var exitWait    = ( exitWaitRaw === null || exitWaitRaw === '' )
        ? null : parseFloat( exitWaitRaw );
    if ( isNaN( exitWait ) ) exitWait = null;
    var customToggleSel = root.getAttribute( 'data-customtoggle' ) || '';
    /* Wbudowane konwencje plus to, co dopisano w kontrolce. Rozdzielamy po
       białych znakach i odsiewamy puste — pole tekstowe zbiera wszystko, co
       ktoś wklei, razem z podwójnymi spacjami i przecinkami. */
    var toggleOpenClasses = EVK_CM_TOGGLE_OPEN.concat(
        ( root.getAttribute( 'data-toggle-class' ) || '' )
            .split( /[\s,]+/ )
            .filter( function ( c ) { return c && EVK_CM_TOGGLE_OPEN.indexOf( c ) < 0; } )
    );
    var raiseToggle = root.getAttribute( 'data-raise-toggle' ) === '1';
    var lockScroll  = root.getAttribute( 'data-lock-scroll' ) === '1';
    var closeOnEsc  = root.getAttribute( 'data-close-on-esc' ) === '1';

    var panel = root.querySelector( '.evk-cm-content' );
    if ( ! panel ) return false;

    // ── Portal: przenieś panel do <body> ──────────────────────────
    if ( usePortal && ! isBuilder ) {
        // Bricks generuje CSS jako `.brxe-XXXX .evk-cm-content { --evk-cm-from-top: ... }`
        // Po przeniesieniu do body selektor przestaje matchować — czytamy
        // computed values PRZED appendChild i ustawiamy jako inline style.
        var panelComputedStyle = getComputedStyle( panel );
        var fromTop  = panelComputedStyle.getPropertyValue( '--evk-cm-from-top' ).trim();
        var fromLeft = panelComputedStyle.getPropertyValue( '--evk-cm-from-left' ).trim();

        document.body.appendChild( panel );

        if ( fromTop )  panel.style.setProperty( '--evk-cm-from-top',  fromTop );
        if ( fromLeft ) panel.style.setProperty( '--evk-cm-from-left', fromLeft );

        // Przeprowadzka unieważnia współrzędne wyzwalaczy zbudowanych PRZED nią:
        // Animator startuje na DOMContentLoaded i zdążył już zmierzyć elementy
        // w środku panelu tam, gdzie stały w treści strony. Bez przeliczenia
        // trzymają pozycje sprzed przenosin.
        /* Przez wspólny helper: `ScrollTrigger.refresh()` zapisuje pozycję
           przewijania, a na iOS zapis w trakcie bezwładności ją kasuje.
           Helper odkłada odświeżenie, aż ruch ustanie — includes/89-gsap.php. */
        if ( window.evkOdswiez ) {
            window.evkOdswiez();
        } else if ( window.ScrollTrigger && typeof ScrollTrigger.refresh === 'function' ) {
            ScrollTrigger.refresh();
        }
    }

    // ── GSAP timeline ─────────────────────────────────────────────
    var clipOpen = getComputedStyle( panel ).getPropertyValue( '--evk-cm-clip-open' ).trim()
                || 'circle(150% at var(--evk-cm-from-left) var(--evk-cm-from-top))';

    var tl = gsap.timeline( { paused: true } );
    tl.to( panel, {
        duration: duration,
        ease: easing,
        clipPath: clipOpen,
    } );

    var isOpen = false;

    // ── Tab index helpers ─────────────────────────────────────────
    function setTabIndex( el ) {
        if ( el.hasAttribute( 'tabindex' ) && el.getAttribute( 'tabindex' ) === '-1' ) {
            el.removeAttribute( 'tabindex' );
        } else {
            el.setAttribute( 'tabindex', '-1' );
        }
        Array.from( el.children ).forEach( setTabIndex );
    }

    function applyTabIndexRecursively( el ) {
        if ( el.nodeType !== Node.ELEMENT_NODE ) return;
        if ( ! el.hasAttribute( 'tabindex' ) || el.getAttribute( 'tabindex' ) !== '-1' ) {
            el.setAttribute( 'tabindex', '-1' );
        }
        Array.from( el.children ).forEach( applyTabIndexRecursively );
    }

    // MutationObserver dla dynamicznie dodawanych dzieci (tylko na froncie)
    if ( ! isBuilder ) {
        var mo = new MutationObserver( function( mutations ) {
            mutations.forEach( function( m ) {
                if ( m.type === 'childList' ) {
                    m.addedNodes.forEach( function( node ) {
                        if ( node.nodeType === Node.ELEMENT_NODE ) {
                            applyTabIndexRecursively( node );
                        }
                    } );
                }
            } );
        } );
        mo.observe( panel, { childList: true, subtree: true } );
    }

    // Inicjalne tabindex na zamkniętym panelu
    setTabIndex( panel );

    // ── Toggle ────────────────────────────────────────────────────

    /**
     * Element, któremu nakładamy stan otwarcia.
     *
     * Selektor zewnętrznego przełącznika bywa wskazany na SAM przycisk, a nie
     * na jego opakowanie — i wtedy `querySelector('button')` nie znajdował nic,
     * więc funkcja wychodziła, zanim cokolwiek zrobiła: ani klasy, ani
     * `aria-expanded`. Wewnętrzny `.evk-cm-trigger` jest OPAKOWANIEM (tak go
     * tworzy get_nestable_children), więc obie drogi muszą działać.
     *
     * Ostatnia deska ratunku to sam element: przełącznik bywa zwykłym divem
     * i lepiej oznaczyć jego, niż nie oznaczyć niczego.
     */
    function toggleTarget( el ) {
        if ( el.matches( EVK_CM_TOGGLE_SEL ) ) return el;
        return el.querySelector( EVK_CM_TOGGLE_SEL ) || el;
    }

    function updateTriggerState( triggerEl ) {
        var btn = toggleTarget( triggerEl );
        // Pierwsza WŁASNA klasa elementu — nasze znaczniki stanu wypadają,
        // inaczej przełącznik bez żadnej klasy dorobiłby się „brx-open--opened".
        var firstClass = Array.prototype.filter.call( btn.classList, function ( c ) {
            return toggleOpenClasses.indexOf( c ) < 0 && c.slice( -8 ) !== '--opened';
        } )[0];
        // Dotychczasowa konwencja Evoke — czyjeś arkusze mogą już na niej stać.
        if ( firstClass ) btn.classList.toggle( firstClass + '--opened', isOpen );

        toggleOpenClasses.forEach( function ( c ) {
            btn.classList.toggle( c, isOpen );
        } );
        btn.setAttribute( 'aria-expanded', isOpen ? 'true' : 'false' );
    }

    /**
     * Wszystkie przełączniki tego menu — wbudowany plus wskazane selektorem.
     *
     * PODNIESIONE dopisujemy osobno i to nie jest ostrożność na wyrost:
     * wbudowany przełącznik szuka się przez `root.querySelectorAll`, a podniesiony
     * siedzi w `<body>`, więc wypada z tej listy DOKŁADNIE wtedy, gdy menu jest
     * otwarte. Bez tego nasłuch „klik poza panelem" przestawał go rozpoznawać
     * i zamykał menu w tej samej klatce, w której je otwarto.
     */
    function triggerList() {
        var out = [];
        /* Bez powtórek: ten sam element potrafi wpaść i z `.evk-cm-trigger`,
           i z własnego selektora, i z listy podniesionych. Drugie wejście
           znaczyłoby drugą przekładkę i powrót w złe miejsce. */
        var dodaj = function ( el ) { if ( out.indexOf( el ) < 0 ) out.push( el ); };
        root.querySelectorAll( '.evk-cm-trigger' ).forEach( dodaj );
        if ( customToggleSel ) document.querySelectorAll( customToggleSel ).forEach( dodaj );
        podniesione.forEach( function ( w ) { dodaj( w.el ); } );
        return out;
    }

    /* LISTA ZBIERANA NAJPIERW, dopiero potem obchodzona. Pierwsze podejście
       wołało `fn` wprost w trakcie zbierania — a `fn` przy podnoszeniu dopisuje
       do `podniesione`, więc pętla po tej właśnie tablicy odwiedzała elementy
       drugi raz. Każdy dostawał wtedy DRUGĄ przekładkę, a przy powrocie trafiał
       przed tę wstawioną już w `<body>`: nagłówek podskakiwał o 35 px, bo
       korzeń menu zostawał pusty. */
    function forEachTrigger( fn ) {
        triggerList().forEach( fn );
    }

    function syncTriggers() {
        forEachTrigger( updateTriggerState );
    }

    /* ── Przełącznik NAD panelem ──────────────────────────────────
     *
     * Zgłoszone z użycia: burger siedzi w nagłówku, nagłówek jest w <body>
     * i nie jest ani `fixed`, ani `absolute`, a panel go przykrywa. Podniesienie
     * samego burgera nie pomaga — pomaga dopiero wyciągnięcie na wierzch CAŁEGO
     * nagłówka, czyli razem z jego tłem.
     *
     * To nie jest kwestia za małej liczby. Nagłówek tworzy KONTEKST UKŁADANIA
     * (wystarczy `position: relative` z własnym `z-index`, `transform`, `filter`,
     * `will-change` albo `opacity` < 1 — Bricks i animacje sypią tym gęsto),
     * a wtedy `z-index` dziecka rywalizuje wyłącznie z rodzeństwem: z panelem
     * rywalizuje cały nagłówek jako JEDNA warstwa. Zmierzone `elementFromPoint`
     * w środku burgera przy otwartym menu: przy `z-index: 99999` na burgerze
     * na wierzchu jest PANEL, a po przeniesieniu węzła do <body> — burger.
     * Żadna liczba tego nie naprawi; trzeba wyjąć przełącznik z kontekstu.
     */
    var podniesione   = [];
    var restoreTimer  = null;

    function panelZIndex() {
        var z = parseInt( getComputedStyle( panel ).zIndex, 10 );
        return isNaN( z ) ? 9999 : z;
    }

    function podniesPrzelaczniki() {
        /* W BUILDERZE nie ruszamy niczego. Kanwa jest cudzym drzewem: Bricks
           pilnuje jej własnym obserwatorem i przerysowuje element, gdy DOM się
           zmieni — a nasze przeniesienie węzła jest taką zmianą. Wychodzi
           z tego para, w której każda strona reaguje na ruch drugiej.
           Ta sama zasada, którą stosuje już portal panelu (`usePortal &&
           ! isBuilder`): w builderze pokazujemy element, a nie przemeblowujemy
           mu otoczenia. Poza tym problem, który ta opcja rozwiązuje — kontekst
           układania nagłówka — na kanwie i tak nie występuje. */
        if ( ! raiseToggle || isBuilder || podniesione.length ) return;
        var z = panelZIndex() + 1;

        forEachTrigger( function ( el ) {
            // Przełącznik W ŚRODKU panelu jedzie z nim portalem do <body>,
            // więc jest już nad wszystkim — wyrywanie go stamtąd zabrałoby go
            // z panelu.
            if ( panel.contains( el ) ) return;

            var r  = el.getBoundingClientRect();
            var cs = getComputedStyle( el );

            /* Przekładka: KOPIA przełącznika z WYMUSZONYM pudełkiem.
             *
             * Kopia, a nie pusty prostokąt — bo pusty element blokowo-liniowy
             * wyznacza linię bazową dolną krawędzią, a taki z tekstem linią
             * bazową swojego tekstu; sąsiad przeskakiwał przez to o 17 px.
             *
             * Wymuszone pudełko, a nie sama kopia — bo identyfikator z kopii
             * trzeba zdjąć (dwa te same w dokumencie psują `getElementById`
             * i cudze skrypty), a Bricks stylizuje elementy WŁAŚNIE po
             * identyfikatorze. Kopia bez niego gubi szerokość, wysokość
             * i wypełnienie: zmierzone przesunięcie sąsiada o 43 px, tak samo
             * w nagłówku elastycznym jak liniowym. Wpisanie zmierzonego pudełka
             * wprost uniezależnia przekładkę od tego, które reguły ją ominą.
             *
             * `visibility`, a nie `opacity` — żeby wypadła też z drzewa
             * dostępności; inaczej czytnik ekranu ogłaszałby przycisk dwa razy. */
            var ph = el.cloneNode( true );
            ph.removeAttribute( 'id' );
            ph.querySelectorAll( '[id]' ).forEach( function ( n ) { n.removeAttribute( 'id' ); } );
            ph.setAttribute( 'aria-hidden', 'true' );
            ph.setAttribute( 'data-evk-cm-przekladka', '' );
            /* Wypełnienie i obramowanie KOPIOWANE, a nie zerowane. Przy
               wyzerowanych tekst w przekładce siadał wyżej niż w oryginale,
               więc linia bazowa całego wiersza wypadała o piksel inaczej —
               zmierzone. Przy `border-box` i wpisanym rozmiarze zewnętrznym
               te same wartości dają to samo pudełko treści, a więc i tę samą
               linię bazową. Kolor obramowania nieistotny: przekładka jest
               niewidoczna. */
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
            el.parentNode.insertBefore( ph, el );

            // Cały atrybut `style`, a nie pojedyncze właściwości: przełącznik
            // może mieć własne wpisane wprost (burger ma tam krzywą czasu),
            // a przywrócenie kompletu jest jedynym sposobem, żeby niczego nie
            // zgubić ani nie dorobić.
            podniesione.push( { el: el, ph: ph, styl: el.getAttribute( 'style' ) } );

            document.body.appendChild( el );
            el.style.position = 'fixed';
            el.style.margin   = '0';
            el.style.top      = r.top    + 'px';
            el.style.left     = r.left   + 'px';
            el.style.width    = r.width  + 'px';
            el.style.height   = r.height + 'px';
            // Z PANELU, nie z własnej liczby — dzięki temu idzie za jego
            // ustawieniem, zamiast powtarzać wartość w drugim miejscu.
            el.style.zIndex   = String( z );
        } );

        /* WYMUSZONY PRZELICZNIK, i to nie jest zabobon.
         *
         * Przeniesienie węzła w drzewie kasuje jego stan przejść: element,
         * którego nie było w dokumencie przy poprzednim przeliczeniu stylu,
         * NIE MA od czego animować. Klasa stanu dochodzi zaraz po tym
         * (syncTriggers), więc bez tej linijki burger PRZESKAKIWAŁ do wyglądu
         * otwartego — bez przejścia kolorów i bez ruchu kresek. Przy zamykaniu
         * animował się normalnie, bo tam klasa schodzi, gdy węzeł od dawna
         * siedzi w <body>. Dokładnie tak to zgłoszono: „nie animuje się do
         * stanu otwartego, tylko przy zamknięciu".
         *
         * Odczyt wymiaru zmusza przeglądarkę do przeliczenia stylu TERAZ, więc
         * przełącznik dostaje stan wyjściowy, od którego jest się czym odbić.
         * Jeden odczyt na cały dokument wystarcza dla wszystkich przeniesionych.
         * Zmierzone: bez niego kolor skacze wprost do docelowego, z nim
         * w połowie czasu jest w połowie drogi. */
        if ( podniesione.length ) void document.body.offsetHeight;
    }

    function opuscPrzelaczniki() {
        podniesione.forEach( function ( w ) {
            if ( w.styl === null ) w.el.removeAttribute( 'style' );
            else                   w.el.setAttribute( 'style', w.styl );
            if ( w.ph.parentNode ) {
                w.ph.parentNode.insertBefore( w.el, w.ph );
                w.ph.parentNode.removeChild( w.ph );
            }
        } );
        podniesione = [];
    }

    /**
     * Animacje wejściowe w treści odgrywamy PRZY KAŻDYM otwarciu.
     *
     * Panel jest `position: fixed; inset: 0` i chowa się obcięciem
     * (`clip-path`), więc LEŻY W KADRZE od załadowania strony. Wyzwalacz
     * „wejście w kadr" jest jednorazowy (`once: true`), więc wystrzeliwał raz,
     * na starcie, w niewidocznym jeszcze panelu — i ginął. Treść po otwarciu
     * po prostu była. Ta sama przyczyna co w offcanvas przed 1.59.0.
     */
    function replayContent() {
        if ( typeof window.evkAnimatorReplay === 'function' ) {
            window.evkAnimatorReplay( panel, contentDelay );
        }
    }

    /**
     * Wyprowadza treść i zwraca, ile sekund czekać z zamykaniem KADRU.
     *
     * Animacja rusza tutaj, niezależnie od czekania — to są dwie różne rzeczy
     * i dopiero ich rozdzielenie pozwala puścić oba ruchy RAZEM. Zwrócone zero
     * nie znaczy „nic nie wychodzi", tylko „nie czekaj": kadr zwija się wtedy
     * na wychodzącej treści.
     */
    function exitContent() {
        if ( ! animateExit || typeof window.evkAnimatorExit !== 'function' ) return 0;

        var trwa = window.evkAnimatorExit( panel );

        /* Nic nie wyszło — nie ma na co czekać, choćby ustawiono jawną wartość.
           Tędy idzie redukcja ruchu: silnik nie buduje wtedy żadnej osi, więc
           czekanie MUSI wyjść zero, inaczej menu wisiałoby otwarte na animację,
           której nie ma. */
        if ( trwa <= 0 ) return 0;

        // Jawna wartość wygrywa z czasem animacji — także wtedy, gdy jest
        // dłuższa (ruch ma się zdążyć wybrzmieć) albo równa zeru.
        if ( exitWait !== null ) return exitWait;

        // Domyślnie czekamy CAŁĄ animację, ale nie dłużej niż granica: ustawione
        // osiem sekund trzymałoby menu otwarte osiem sekund po kliknięciu ✕.
        return Math.min( trwa, EVK_CM_EXIT_MAX );
    }

    /* Uchwyt odłożonego zamknięcia. Bez niego drugi klik w ✕ startuje drugie
       wyjście, a otwarcie w trakcie wychodzenia zostaje po chwili zamknięte
       przez zaległy zegar — menu zamyka się samo tuż po otwarciu.
       Źródeł zamykania jest tu więcej niż jedno: ✕, klik poza panelem, Esc
       i focusout, więc zbieg dwóch naraz nie jest teoretyczny. */
    var closeTimer = null;

    function openMenu() {
        if ( closeTimer ) { clearTimeout( closeTimer ); closeTimer = null; }
        // Zaległy powrót przełącznika z przerwanego zamykania. Bez tego
        // otwarcie w trakcie zwijania kadru zostawałoby po chwili opuszczone
        // przez zegar sprzed chwili — burger wpadałby pod panel przy otwartym
        // menu. Ta sama pułapka co przy `closeTimer` i to samo lekarstwo.
        if ( restoreTimer ) { clearTimeout( restoreTimer ); restoreTimer = null; }
        if ( isOpen ) return;
        isOpen = true;
        syncScrollLock();
        podniesPrzelaczniki();
        setTabIndex( panel );
        panel.classList.add( EVK_CM_OPEN );
        // Stan po naszemu na panelu, po Bricksowemu na korzeniu. Korzeń, nie
        // panel: przy włączonym portalu panel jedzie do <body> i przestaje być
        // czymkolwiek w okolicy przełącznika, a reguły Bricksa czytają stan
        // przez pokrewieństwo w drzewie.
        root.classList.add( EVK_BRICKS_OPEN );
        panel.style.pointerEvents = 'all';
        tl.play();
        syncTriggers();
        replayContent();
    }

    /**
     * Zwijanie kadru — osobno od decyzji o zamknięciu, bo dzieli je czekanie
     * na wyjście treści.
     *
     * Tu, a nie w closeMenu(), schodzi klasa stanu: przez czas wychodzenia
     * treści panel jest JESZCZE widoczny i CSS zaczepiony o `is-open` ma go
     * dalej dotyczyć. Zdjęta w chwili kliknięcia przestawiałaby wygląd panelu,
     * który stoi na ekranie jak stał. Tak samo działa `is-open` na powłoce
     * offcanvas — schodzi dopiero w finishClose().
     */
    function startCollapse() {
        panel.classList.remove( EVK_CM_OPEN );
        root.classList.remove( EVK_BRICKS_OPEN );
        tl.reverse();

        /* Przełącznik wraca DOPIERO po zwinięciu kadru — opuszczony od razu
           wpadałby pod jeszcze widoczny panel w połowie animacji.
           Zegar o znanym czasie, a NIE `onReverseComplete` osi czasu: przy
           zerowym czasie trwania to zwrotne wywołanie się NIE ODPALA (zmierzone
           na GSAP-ie z tej paczki), a zero to dokładnie ścieżka REDUKCJI RUCHU
           — `duration` jest wtedy wyzerowane wyżej. Burger zostałby wyrwany
           z nagłówka na stałe u każdego, kto ma ograniczony ruch w systemie. */
        if ( podniesione.length ) {
            if ( restoreTimer ) clearTimeout( restoreTimer );
            restoreTimer = setTimeout( function () {
                restoreTimer = null;
                if ( ! isOpen ) opuscPrzelaczniki();
            }, duration * 1000 );
        }
    }

    function closeMenu() {
        if ( ! isOpen || closeTimer ) return;
        isOpen = false;
        syncScrollLock();
        setTabIndex( panel );
        panel.style.pointerEvents = 'none';
        syncTriggers();

        var wait = exitContent();
        if ( wait <= 0 ) { startCollapse(); return; }

        closeTimer = setTimeout( function () {
            closeTimer = null;
            startCollapse();
        }, wait * 1000 );
    }

    function toggle() {
        if ( ! panel ) return;
        if ( isOpen ) closeMenu();
        else          openMenu();
    }

    /* ── Blokada przewijania ───────────────────────────────────────
     *
     * Robi to WSPÓLNY zamek (includes/96-scroll-lock.php), a nie ten element.
     * Do 1.94.0 stała tu własna wersja i miała dwie usterki naraz: ustawiała
     * atrybut `evk-cm-scroll-locked`, którego nie czytała żadna reguła CSS
     * w całej wtyczce (czyli nie blokowała NICZEGO), i pytała o Lenisa pod
     * nazwą `window.lenisInstance`, której nikt nigdy nie ustawiał.
     *
     * Zniknęło przy okazji wypatrywanie otwartego offcanvasu po selektorze:
     * wspólny zamek trzyma ZBIÓR IMION, więc „ktoś inny nadal trzyma" wie sam
     * i nie trzeba go o to pytać drzewem DOM.
     *
     * Wywoływane ze stanu menu, nie z kliknięcia. Klik bywa obsłużony w dwóch
     * miejscach (przełącznik wewnętrzny i zewnętrzny selektor), a stan jest
     * jeden — parowanie lock/unlock po kliknięciach było tym, co potrafiło się
     * rozjechać.
     */
    function syncScrollLock() {
        if ( ! lockScroll || ! window.evkScroll ) return;
        if ( isOpen ) window.evkScroll.lock( 'circular-menu' );
        else          window.evkScroll.unlock( 'circular-menu' );
    }

    // ── Bindowanie triggerów ──────────────────────────────────────

    // Wewnętrzny trigger .evk-cm-trigger
    root.querySelectorAll( '.evk-cm-trigger' ).forEach( function( trigger ) {
        trigger.addEventListener( 'click', function() {
            toggle();
        } );
    } );

    // Custom toggle (zewnętrzny selektor)
    if ( customToggleSel ) {
        document.querySelectorAll( customToggleSel ).forEach( function( el ) {
            if ( ! el.hasAttribute( 'tabindex' ) ) el.setAttribute( 'tabindex', '0' );
            el.addEventListener( 'click', function() {
                toggle();
            } );
            el.addEventListener( 'keydown', function( e ) {
                if ( e.key === 'Enter' ) {
                    toggle();
                    e.stopImmediatePropagation();
                }
            } );
        } );
    }

    // Klik poza panelem — zamknij
    if ( root.isConnected ) {
        document.addEventListener( 'click', function( e ) {
            if ( ! panel ) return;
            /* Lista przełączników idzie przez forEachTrigger(), a nie przez
               własne zapytania: przy podniesionym przełączniku `root` już go
               nie zawiera i własna kopia tego zapytania uznawała kliknięcie
               w niego za „poza panelem". */
            var wPrzelaczniku = false;
            forEachTrigger( function ( t ) {
                if ( t.contains( e.target ) ) wPrzelaczniku = true;
            } );
            var clickedOutside = ! panel.contains( e.target ) && ! wPrzelaczniku;
            // Blokadę zdejmuje closeMenu() przez syncScrollLock() — tu nie ma
            // czego odkręcać ręcznie i nie ma po co pytać o cudze panele.
            if ( clickedOutside && isOpen ) toggle();
        } );
    }

    // ESC
    document.addEventListener( 'keydown', function( e ) {
        if ( isOpen && closeOnEsc && e.key === 'Escape' ) toggle();
    } );

    // Focus out z panelu
    panel.addEventListener( 'focusout', function( e ) {
        if ( e.relatedTarget && ! panel.contains( e.relatedTarget ) && isOpen ) {
            toggle();
        }
    } );

    // Stan początkowy triggerów. Bez tego `aria-expanded` pojawia się dopiero
    // przy pierwszym kliknięciu, więc czytnik ekranu do tej chwili nie ma skąd
    // wiedzieć, że przycisk cokolwiek rozwija.
    syncTriggers();

    // ── Otwórz w builderze ────────────────────────────────────────
    // Klasa stanu też — inaczej styl zaczepiony o `is-open` nie byłby widoczny
    // dokładnie tam, gdzie się go ustawia.
    if ( isBuilder && openBuilder ) {
        // Znacznik dla arkusza. To JEDYNE miejsce, które go nakłada — dzięki
        // temu odsłonięcie panelu nie może wydarzyć się na froncie, gdzie ten
        // warunek nigdy nie jest prawdziwy. Do 1.86.0 arkusz decydował o tym
        // sam, po samym ustawieniu, i menu potrafiło zostać otwarte na stałe.
        panel.classList.add( EVK_CM_BUILDER );
        panel.classList.add( EVK_CM_OPEN );
        root.classList.add( EVK_BRICKS_OPEN );
        tl.play();
    }

    return true;
}

document.addEventListener( 'DOMContentLoaded', function() {
    if ( bricksIsFrontend ) {
        evk_circular_menu_init();
    }
} );
