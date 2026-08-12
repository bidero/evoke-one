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
        if ( window.ScrollTrigger && typeof ScrollTrigger.refresh === 'function' ) {
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

    function syncTriggers() {
        root.querySelectorAll( '.evk-cm-trigger' ).forEach( updateTriggerState );
        if ( customToggleSel ) {
            document.querySelectorAll( customToggleSel ).forEach( updateTriggerState );
        }
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
        if ( isOpen ) return;
        isOpen = true;
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
    }

    function closeMenu() {
        if ( ! isOpen || closeTimer ) return;
        isOpen = false;
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

    // ── Scroll lock ───────────────────────────────────────────────
    function toggleBodyScroll() {
        if ( ! lockScroll ) return;
        var html = document.querySelector( 'html' );
        var offcanvasOpen = document.querySelector( '.bc-offcanvas-menu[data-open="bc-offcanvas-menu--opened"]' );
        if ( html.hasAttribute( 'evk-cm-scroll-locked' ) && ! offcanvasOpen ) {
            if ( window.lenisInstance ) window.lenisInstance.start();
            html.removeAttribute( 'evk-cm-scroll-locked' );
        } else if ( ! html.hasAttribute( 'evk-cm-scroll-locked' ) ) {
            if ( window.lenisInstance ) window.lenisInstance.stop();
            html.setAttribute( 'evk-cm-scroll-locked', '' );
        }
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
                toggleBodyScroll();
                toggle();
            } );
            el.addEventListener( 'keydown', function( e ) {
                if ( e.key === 'Enter' ) {
                    toggleBodyScroll();
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
            var customToggles = customToggleSel ? Array.from( document.querySelectorAll( customToggleSel ) ) : [];
            var internalTriggers = Array.from( root.querySelectorAll( '.evk-cm-trigger' ) );
            var clickedOutside = ! panel.contains( e.target )
                && ! customToggles.some( function( t ) { return t.contains( e.target ); } )
                && ! internalTriggers.some( function( t ) { return t.contains( e.target ); } );
            if ( clickedOutside && isOpen ) {
                toggle();
                var html = document.querySelector( 'html' );
                var offcanvasOpen = document.querySelector( '.bc-offcanvas-menu[data-open="bc-offcanvas-menu--opened"]' );
                if ( ! offcanvasOpen ) {
                    html.removeAttribute( 'evk-cm-scroll-locked' );
                    if ( window.lenisInstance ) window.lenisInstance.start();
                }
            }
        } );
    }

    // ESC
    document.addEventListener( 'keydown', function( e ) {
        if ( isOpen && closeOnEsc && e.key === 'Escape' ) {
            toggle();
            var html = document.querySelector( 'html' );
            var offcanvasOpen = document.querySelector( '.bc-offcanvas-menu[data-open="bc-offcanvas-menu--opened"]' );
            if ( ! offcanvasOpen ) {
                html.removeAttribute( 'evk-cm-scroll-locked' );
                if ( window.lenisInstance ) window.lenisInstance.start();
            }
        }
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
