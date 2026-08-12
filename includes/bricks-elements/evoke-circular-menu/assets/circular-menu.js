/**
 * Evoke Circular Menu
 * v1.2.0
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
    var customToggleSel = root.getAttribute( 'data-customtoggle' ) || '';
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
    function updateTriggerState( triggerEl ) {
        var btn = triggerEl.querySelector( 'button' );
        if ( ! btn ) return;
        var firstClass = btn.classList[0];
        var openedClass = firstClass ? firstClass + '--opened' : '';
        if ( isOpen ) {
            if ( openedClass ) btn.classList.add( openedClass );
            btn.setAttribute( 'aria-expanded', 'true' );
        } else {
            if ( openedClass ) btn.classList.remove( openedClass );
            btn.setAttribute( 'aria-expanded', 'false' );
        }
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

    /** Ile sekund czekać z zamknięciem, żeby treść zdążyła wyjść. */
    function exitContent() {
        if ( ! animateExit || typeof window.evkAnimatorExit !== 'function' ) return 0;
        return Math.min( window.evkAnimatorExit( panel ), EVK_CM_EXIT_MAX );
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
        panel.style.pointerEvents = 'all';
        tl.play();
        syncTriggers();
        replayContent();
    }

    function closeMenu() {
        if ( ! isOpen || closeTimer ) return;
        isOpen = false;
        setTabIndex( panel );
        panel.style.pointerEvents = 'none';
        syncTriggers();

        var wait = exitContent();
        if ( wait <= 0 ) { tl.reverse(); return; }

        closeTimer = setTimeout( function () {
            closeTimer = null;
            tl.reverse();
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
    if ( isBuilder && openBuilder ) {
        tl.play();
    }

    return true;
}

document.addEventListener( 'DOMContentLoaded', function() {
    if ( bricksIsFrontend ) {
        evk_circular_menu_init();
    }
} );
