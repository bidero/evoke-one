/**
 * Evoke ONE — Burger
 * v1.0.0
 *
 * Ten skrypt robi DWIE rzeczy i ani jednej więcej. To jest cecha, nie
 * oszczędność: cały sens tego elementu polega na tym, że stan otwarcia
 * należy do MENU, a burger go tylko pokazuje. Każda linijka, która by tu
 * o stanie decydowała, przywracałaby problem dwóch właścicieli.
 *
 *  1. Znaczy przycisk jako „bez ruchu", gdy obowiązuje redukcja ruchu.
 *  2. Obsługuje tryb SAMODZIELNY — dla użycia bez naszego menu.
 *
 * evk_burger_init() wołane jest z dwóch stron: przez Bricks (patrz $scripts
 * w element.php) i przez własny DOMContentLoaded niżej. Flaga
 * data-evk-burger-ready pilnuje, żeby jeden przycisk zainicjalizował się raz.
 */

function evk_burger_init() {
    document.querySelectorAll( '.evk-burger' ).forEach( function ( el ) {
        if ( el.dataset.evkBurgerReady === '1' ) return;
        el.dataset.evkBurgerReady = '1';

        /* Redukcja ruchu przez wspólną politykę wtyczki, nie przez sam media
           query: polityka ma jeszcze przełącznik w panelu i zapytanie samego
           systemu by go pominęło. Patrz includes/anim/motion.php. */
        var reduced = ( window.evkMotion && typeof window.evkMotion.reduced === 'function' )
            ? window.evkMotion.reduced()
            : !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
        if ( reduced ) el.setAttribute( 'data-evk-still', '' );

        // Domyślnie stan wystawia menu — tu nie ma czego wiązać.
        if ( el.getAttribute( 'data-evk-burger-self' ) !== '1' ) return;

        el.addEventListener( 'click', function () {
            /* Wyłącznie `brx-open` i `aria-expanded`. Menu nakłada swoim
               przełącznikom jeszcze `is-active` i `<klasa>--opened`, ale te są
               po to, żeby zadowolić CUDZE arkusze (Bricks, gotowe burgery).
               Nasz własny arkusz czyta `brx-open` i nic poza tym, więc
               dokładanie reszty byłoby zaśmiecaniem znacznika. */
            var open = ! el.classList.contains( 'brx-open' );
            el.classList.toggle( 'brx-open', open );
            el.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
        } );
    } );
}

document.addEventListener( 'DOMContentLoaded', function () {
    if ( typeof bricksIsFrontend === 'undefined' || bricksIsFrontend ) evk_burger_init();
} );
