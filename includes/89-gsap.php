<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — wspólne biblioteki GSAP
 *
 * Jeden zestaw handle'ów dla całej wtyczki: elementy Bricks
 * (includes/bricks-elements/loader.php) i Animator (includes/anim/animator.php)
 * enqueue'ują te same handle, więc GSAP ładuje się raz niezależnie od tego,
 * ile funkcji jest włączonych.
 *
 * Rejestracja ≠ pobranie — samo zarejestrowanie handle'a nic nie emituje.
 * Skrypt trafia na stronę dopiero gdy ktoś zrobi wp_enqueue_script().
 */

const EVK_GSAP_VERSION = '3.15.0';

/**
 * Adres katalogu z bibliotekami GSAP. Wołany też z 96-lenis.php i z testów.
 *
 * PLIKI JADĄ Z WŁASNEGO SERWERA, nie z cdnjs — i to nie jest kwestia gustu.
 * Cudzy host to osobne DNS + TCP + TLS przed pierwszym bajtem, a przy komplecie
 * ważącym ~55 KiB po kompresji sam transfer jest wobec tego kosztu pomijalny:
 * zmierzone na żywej stronie 900–1650 ms na plik przy 53 KiB razem. Z własnego
 * serwera te same pliki jadą po JUŻ OTWARTYM połączeniu.
 *
 * Argument „użytkownik ma to już w pamięci podręcznej z innej strony" nie
 * obowiązuje od 2020: przeglądarki dzielą cache per witryna, więc każda strona
 * i tak pobiera swoje. Do tego znika zależność od cudzej dostępności i wyciek
 * adresów IP odwiedzających do zewnętrznego CDN-u.
 *
 * Skąd się biorą pliki i jak je podbić — patrz assets/vendor/README.md.
 */
function evk_gsap_url(): string {
    return EVOKE_ONE_URL . 'assets/vendor/gsap/';
}

function evk_register_gsap_libs(): void {
    if (wp_script_is('evk-gsap', 'registered')) return; // idempotentne

    $dir = evk_gsap_url();

    wp_register_script('evk-gsap',          $dir . 'gsap.min.js',          [],           EVK_GSAP_VERSION, true);
    wp_register_script('evk-scrolltrigger', $dir . 'ScrollTrigger.min.js', ['evk-gsap'], EVK_GSAP_VERSION, true);
    wp_register_script('evk-observer',      $dir . 'Observer.min.js',      ['evk-gsap'], EVK_GSAP_VERSION, true);
    wp_register_script('evk-splittext',     $dir . 'SplitText.min.js',     ['evk-gsap'], EVK_GSAP_VERSION, true);

    // Efekty tekstowe Animatora. Od GSAP 3.13 wszystkie wtyczki są darmowe,
    // więc leżą w paczce npm obok reszty i idą tą samą drogą.
    wp_register_script('evk-textplugin',    $dir . 'TextPlugin.min.js',    ['evk-gsap'], EVK_GSAP_VERSION, true);
    wp_register_script('evk-scrambletext',  $dir . 'ScrambleTextPlugin.min.js', ['evk-gsap'], EVK_GSAP_VERSION, true);

    /*
     * Adres katalogu dla skryptów, które dociągają GSAP-a SAME.
     *
     * Marquee i Horizontal Scroll mają własny loader awaryjny na wypadek, gdyby
     * biblioteki nie było — i do 1.72.0 miały w nim wpisany na sztywno adres
     * cdnjs. Zależności WordPressa i tak ładują GSAP wcześniej, więc ta ścieżka
     * jest w praktyce nieużywana; wpisany adres CDN-u nie przestawał jednak być
     * adresem CDN-u i przy pierwszej pomyłce w zależnościach wracał do gry —
     * tak właśnie ScrollTrigger jeździł z cdnjs na każdej stronie z marquee.
     *
     * Pozycja `before`: globalna musi istnieć, ZANIM wykona się którykolwiek
     * z tych skryptów.
     */
    wp_add_inline_script(
        'evk-gsap',
        'window.evkGsapBase=' . wp_json_encode($dir) . ';',
        'before'
    );

    // Na telefonie chowanie i pokazywanie paska adresu wypala `resize` w trakcie
    // przewijania. Bez tego ScrollTrigger przemierza wtedy wszystkie triggery na
    // stronie i scroll widocznie się zacina — mimo że zmieniła się sama wysokość
    // widoku, a układ ani drgnął. Dotyczy całej wtyczki: Animatora, Horizontal
    // Scroll, Scroll Reading i Stacking Cards.
    //
    // Skrypt inline drukuje się wyłącznie tam, gdzie handle jest enqueue'owany.
    //
    // Do tego WSPÓLNE odświeżanie wyzwalaczy. `ignoreMobileResize` chroni tylko
    // wbudowaną ścieżkę GSAP-a; nasze własne, jawne `ScrollTrigger.refresh()`
    // (marquee, tło przy scrollu, scroll reading, circular menu, stacking cards,
    // horizontal scroll) tę ochronę omijały — a refresh ZAPISUJE pozycję
    // przewijania. Zmierzone szpiegiem pod `window.scrollTo`:
    //
    //     scrollTo:0,0   ← skok na samą górę
    //     scrollTo:0,0
    //     scrollTo:0,1200 ← i powrót
    //
    // Na desktopie niewidoczne, bo dzieje się w jednej klatce. Na iOS zapis
    // pozycji w trakcie bezwładnego przewijania KASUJE je natychmiast: strona
    // zwalnia i nagle staje. Zgłoszone z użycia dokładnie w tych słowach.
    wp_add_inline_script('evk-scrolltrigger', <<<'JS'
        if (window.ScrollTrigger) ScrollTrigger.config({ ignoreMobileResize: true });

        (function () {
            var czeka = null, ostatniRuch = 0, termin = 0;
            var ODSTEP = 200;   // scalanie serii wywołań
            var CISZA  = 150;   // ile bez ruchu, żeby uznać przewijanie za skończone
            var MAKS   = 1000;  // po tylu ms pilne przelicza mimo trwającego ruchu

            window.addEventListener('scroll', function () {
                ostatniRuch = Date.now();
            }, { passive: true });

            function sprobuj() {
                /* Jeszcze się przewija — odkładamy. Refresh w trakcie ruchu ucina
                   bezwładność, bo zapisuje pozycję przewijania.

                   Ale nie w nieskończoność. Kto wchodzi na stronę i od razu
                   przewija, ten KAŻDYM zdarzeniem scrolla zeruje ten licznik —
                   odłożone przeliczenie nie wchodziło wtedy ani razu. Zmierzone
                   na evoke.pl: taśma Horizontal Scrolla mierzy się na zastępczych
                   obrazach lazy-loadera, więc do pierwszego przeliczenia pin jest
                   za krótki, a wszystkie animacje pod nim startują za wcześnie.
                   Zgłoszone z użycia dokładnie tak: „nagle elementy się odświeżyły
                   i zaczęły działać w czasie przewijania. Potem po refresh strony
                   znowu to samo".

                   Dlatego wywołanie PILNE ma termin ostateczny. Asymetria jest
                   celowa: zła geometria trwa, dopóki jej nie przeliczysz,
                   a szarpnięcie bezwładności trwa chwilę. */
                if (Date.now() - ostatniRuch < CISZA && !(termin && Date.now() >= termin)) {
                    czeka = setTimeout(sprobuj, CISZA);
                    return;
                }
                czeka = null;
                termin = 0;
                if (window.ScrollTrigger) ScrollTrigger.refresh();
            }

            /**
             * Odśwież wyzwalacze — ale nie teraz, tylko gdy przewijanie ustanie.
             * Seria wywołań (doładowywanie treści potrafi zmienić wysokość
             * kilkanaście razy pod rząd) daje jedno przeliczenie.
             *
             * `evkOdswiez(true)` = PILNE: czeka na ciszę tak samo, ale nie dłużej
             * niż MAKS. Dla przeliczeń, bez których układ zostaje zwyczajnie zły —
             * dziś woła tak tylko Horizontal Scroll po dojechaniu obrazów taśmy.
             * Pozostali wołający (marquee, tło przy scrollu, scroll reading,
             * circular menu, stacking cards) zostają przy czekaniu bez terminu.
             */
            window.evkOdswiez = function (pilne) {
                if (pilne && !termin) { termin = Date.now() + MAKS; }
                clearTimeout(czeka);
                czeka = setTimeout(sprobuj, ODSTEP);
            };
        })();
        JS);
}

// Priorytet 1 — przed loaderem elementów (5) i przed enqueue Animatora (20).
add_action('wp_enqueue_scripts', 'evk_register_gsap_libs', 1);
