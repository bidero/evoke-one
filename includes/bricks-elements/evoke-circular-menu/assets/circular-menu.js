/**
 * Evoke Circular Menu
 * v1.1.0
 *
 * evk_circular_menu_init() jest wołane z dwóch stron: przez Bricks (patrz
 * $scripts w element.php) i przez własny DOMContentLoaded poniżej. Flaga
 * data-evk-cm-ready pilnuje, żeby jeden korzeń zainicjalizował się raz —
 * bez niej stackowałyby się listenery, a przy włączonym portalu drugi
 * przebieg nie znalazłby panelu (jest już przeniesiony do <body>).
 */

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
    var duration    = parseFloat( root.getAttribute( 'data-duration' ) ) || 0.4;
    var easing      = root.getAttribute( 'data-easing' ) || 'none';
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

    function toggle() {
        if ( ! panel ) return;
        setTabIndex( panel );
        if ( isOpen ) {
            panel.style.pointerEvents = 'none';
            tl.reverse();
        } else {
            panel.style.pointerEvents = 'all';
            tl.play();
        }

        // Aktualizuj triggery wewnętrzne
        root.querySelectorAll( '.evk-cm-trigger' ).forEach( updateTriggerState );

        // Aktualizuj custom toggle
        if ( customToggleSel ) {
            document.querySelectorAll( customToggleSel ).forEach( updateTriggerState );
        }

        isOpen = ! isOpen;
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
