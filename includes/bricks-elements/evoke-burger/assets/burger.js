/**
 * Evoke ONE — Burger
 * v1.1.0
 *
 * Trzy tryby, a w każdym obowiązuje ta sama zasada: **stan ma jednego
 * właściciela, a burger go POKAZUJE**. Różni je tylko to, kto nim jest.
 *
 *  1. `menu` (domyślny) — właścicielem jest Circular Menu albo Offcanvas Menu.
 *     Burger nie wiąże niczego; menu nakłada mu `brx-open`, arkusz reaguje.
 *  2. `target` — właścicielem jest WSKAZANY ELEMENT. Kliknięcie przestawia
 *     jego klasę, a wygląd burgera idzie ZA NIM przez obserwatora. Dzięki temu
 *     zamknięcie celu czymkolwiek innym też wraca do kresek.
 *  3. `self` — właścicielem jest sam przycisk. Dla użycia, w którym klasa na
 *     nim wystarcza, a resztą steruje czyjś kod.
 *
 * Nigdzie nie ma trybu, w którym stan trzymają dwie rzeczy naraz — to była
 * przyczyna czterech rund poprawek w menu (1.70.0 → 1.74.0).
 *
 * evk_burger_init() wołane jest z dwóch stron: przez Bricks (patrz $scripts
 * w element.php) i przez własny DOMContentLoaded niżej. Flaga
 * data-evk-burger-ready pilnuje, żeby jeden przycisk zainicjalizował się raz.
 */

/**
 * Klasa, po której poznajemy stan otwarcia — ta sama, którą nakłada Bricks
 * swoim otwartym elementom i którą nakładają oba nasze menu przełącznikom.
 */
var EVK_BURGER_OPEN = 'brx-open';

/** Stan na samym przycisku. Arkusz burgera czyta wyłącznie tę klasę. */
function evk_burger_show( el, open ) {
    el.classList.toggle( EVK_BURGER_OPEN, open );
    el.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
}

/**
 * Tryb „wskazany element" — kliknięcie przestawia CEL, wygląd idzie za celem.
 *
 * Dwukierunkowość nie jest ozdobą. Gdyby burger tylko NAKŁADAŁ klasę celowi,
 * a swój wygląd trzymał osobno, to zamknięcie panelu czymkolwiek innym
 * (własny skrypt, klawisz, przycisk „zamknij" w środku) zostawiłoby krzyżyk
 * na przycisku — czyli dokładnie usterka, którą przez cztery wersje
 * naprawialiśmy w menu. Obserwator sprawia, że właściciel jest jeden: klasa
 * na celu.
 */
function evk_burger_bind_target( el, sel ) {
    var cele;
    try { cele = document.querySelectorAll( sel ); }
    catch ( e ) {
        console.warn( '[EVK Burger] Nieprawidłowy selektor celu:', sel, el );
        return;
    }
    if ( ! cele.length ) {
        console.warn( '[EVK Burger] Cel „' + sel + '" nie istnieje na stronie.', el );
        return;
    }

    /* Menu Evoke pilnuje `brx-open` na sobie samo, więc wskazanie go tutaj
       daje stan z dwoma właścicielami. Mówimy o tym wprost, bo objaw byłby
       trudny: menu działa, a burger gubi kreski co drugie kliknięcie. */
    var nasze = Array.prototype.filter.call( cele, function ( t ) {
        return t.classList.contains( 'evk-cm' ) || t.classList.contains( 'evk-oc' );
    } );
    if ( nasze.length ) {
        console.warn( '[EVK Burger] Celem jest menu Evoke, a ono pilnuje „' + EVK_BURGER_OPEN
            + '" samo — stan miałby dwóch właścicieli. Wskaż ten przycisk w polu '
            + '„Własny przełącznik → Selektor CSS" tego menu i zostaw burgerowi tryb '
            + '„Nic — stan bierze z menu Evoke".', el );
    }

    // Wbudowana plus to, co dopisano w kontrolce. Ta sama zasada co przy
    // klasach przełącznika w obu menu: dokładamy, nie zastępujemy.
    var klasy = [ EVK_BURGER_OPEN ].concat(
        ( el.getAttribute( 'data-evk-burger-target-class' ) || '' )
            .split( /[\s,]+/ )
            .filter( function ( c ) { return c && c !== EVK_BURGER_OPEN; } )
    );

    /* Stan czytamy z PIERWSZEGO celu. Przy kilku rozjechanych burger migałby
       między stanami, a jeden wskazany element jest odpowiedzią na pytanie
       „czy to jest otwarte". Klasę dostają i tak wszystkie. */
    var zrodlo = cele[0];

    // Czym ten przycisk steruje — dla czytnika ekranu. Tylko gdy cel ma
    // identyfikator, bo `aria-controls` odwołuje się właśnie do niego.
    if ( zrodlo.id && ! el.hasAttribute( 'aria-controls' ) ) {
        el.setAttribute( 'aria-controls', zrodlo.id );
    }

    function zsynchronizuj() {
        evk_burger_show( el, zrodlo.classList.contains( EVK_BURGER_OPEN ) );
    }

    new MutationObserver( zsynchronizuj )
        .observe( zrodlo, { attributes: true, attributeFilter: [ 'class' ] } );
    zsynchronizuj();

    el.addEventListener( 'click', function () {
        var open = ! zrodlo.classList.contains( EVK_BURGER_OPEN );
        Array.prototype.forEach.call( cele, function ( t ) {
            klasy.forEach( function ( c ) { t.classList.toggle( c, open ); } );
        } );
        /* Obserwator i tak to złapie, ale dopiero w mikrozadaniu. Przycisk ma
           zmienić stan w TEJ SAMEJ klatce co cel — inaczej przy zerowym czasie
           przejścia widać przeskok o jedną klatkę. */
        zsynchronizuj();
    } );
}

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

        var sel = el.getAttribute( 'data-evk-burger-target' ) || '';
        if ( sel ) { evk_burger_bind_target( el, sel ); return; }

        // Domyślnie stan wystawia menu — tu nie ma czego wiązać.
        if ( el.getAttribute( 'data-evk-burger-self' ) !== '1' ) return;

        el.addEventListener( 'click', function () {
            evk_burger_show( el, ! el.classList.contains( EVK_BURGER_OPEN ) );
        } );
    } );
}

document.addEventListener( 'DOMContentLoaded', function () {
    if ( typeof bricksIsFrontend === 'undefined' || bricksIsFrontend ) evk_burger_init();
} );
