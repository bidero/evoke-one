<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — wspólny zamek na przewijanie
 *
 * Blokowanie strony robiły dotąd elementy same, każdy po swojemu, i każdy
 * z inną usterką:
 *
 *  — Circular Menu ustawiało atrybut `evk-cm-scroll-locked`, którego NIE CZYTAŁA
 *    żadna reguła CSS w całej wtyczce. Blokada była atrapą.
 *  — Offcanvas blokował naprawdę (`overflow: hidden` plus kompensata paska),
 *    ale nie zatrzymywał płynnego przewijania. Dokument stał, a Lenis przewijał
 *    dalej swoją wirtualną pozycję — po zamknięciu obie się nie zgadzały
 *    i przewijanie wyglądało na martwe, dopóki się nie zeszły. Zgłoszone
 *    z użycia jako „strona się blokuje czasami".
 *  — Sam Lenis miał trzy nazwy globalne (`evkLenis` z modułu, `lenisInstance`
 *    w Circular Menu, `lenis`/`__lenis` w tytule na okręgu). Działała jedna.
 *
 * Stąd JEDEN zamek, do którego wołają wszyscy, i który robi trzy rzeczy naraz:
 * zatrzymuje Lenisa, zakłada klasę na <html> i kompensuje szerokość paska.
 *
 * TRZYMAJĄCY SĄ ZBIOREM IMION, NIE LICZNIKIEM. Dwa panele otwarte naraz to
 * realny przypadek, a licznik nie odróżnia „drugi zamknął" od „ten sam zamknął
 * dwa razy" — i to ta druga sytuacja zostawia stronę zablokowaną na stałe.
 * Zbiór odpowiada przy okazji na pytanie „kto trzyma", które przy nieregularnym
 * zacięciu jest ważniejsze niż „ilu".
 *
 * Drukowane w <head> — tak samo jak wspólna polityka ruchu (anim/motion.php),
 * i z tego samego powodu: ma być gotowe zanim ruszy cokolwiek ze stopki.
 */

const EVK_SCROLL_LOCK_CLASS = 'evk-scroll-locked';

add_action('wp_head', function (): void {
    if (evk_w_builderze()) return;

    $klasa = EVK_SCROLL_LOCK_CLASS;
    ?>
<style id="evk-scroll-lock-css">
/* `!important`, bo motyw i Bricks potrafią ustawić `overflow` na tych samych
   węzłach — a blokada, którą da się przebić, jest gorsza niż jej brak: panel
   jest otwarty, a strona pod nim jedzie. Oba węzły, bo przeglądarki nie są
   zgodne co do tego, który z nich przewija dokument. */
html.<?php echo $klasa; ?>, html.<?php echo $klasa; ?> body {
    overflow: hidden !important;
}
</style>
<script id="evk-scroll-lock">
(function () {
    var KLASA = '<?php echo esc_js($klasa); ?>';
    var trzymajacy = [];
    var gadaj = false;

    try {
        gadaj = new URLSearchParams(location.search).get('evk-scroll-debug') === '1';
    } catch (e) { /* starsze przeglądarki — diagnostyka po prostu nie wchodzi */ }

    /** Lenis pod JEDYNĄ nazwą. Czytany przy każdym użyciu, bo powstaje w stopce. */
    function lenis() { return window.evkLenis || null; }

    function raport(co, kto) {
        if (!gadaj) return;
        console.log('[EVK Scroll] ' + co + (kto ? ' — ' + kto : ''),
            '| trzymają:', trzymajacy.slice(), '| Lenis:', lenis() ? 'jest' : 'brak');
    }

    function zaloz() {
        /* Kompensata szerokości paska przewijania. Bez niej `overflow: hidden`
           przesuwa całą stronę o kilkanaście pikseli i widać to jako skok
           w chwili otwarcia — na desktopie, gdzie pasek zajmuje miejsce. */
        var luka = window.innerWidth - document.documentElement.clientWidth;
        if (luka > 0) document.body.style.paddingRight = luka + 'px';
        document.documentElement.classList.add(KLASA);
        var l = lenis();
        if (l && typeof l.stop === 'function') l.stop();
    }

    function zdejmij() {
        document.documentElement.classList.remove(KLASA);
        document.body.style.paddingRight = '';
        var l = lenis();
        if (l && typeof l.start === 'function') l.start();
    }

    window.evkScroll = {
        /**
         * Blokuje przewijanie w imieniu `kto`. Powtórne wołanie tym samym
         * imieniem nic nie zmienia — zbiór, nie licznik.
         */
        lock: function (kto) {
            kto = kto || 'nieznany';
            if (trzymajacy.indexOf(kto) !== -1) { raport('lock (już trzyma)', kto); return; }
            trzymajacy.push(kto);
            if (trzymajacy.length === 1) zaloz();
            raport('lock', kto);
        },

        /**
         * Zwalnia blokadę w imieniu `kto`. Przewijanie wraca dopiero, gdy zbiór
         * jest pusty — czyli gdy odpuścił OSTATNI trzymający, a nie pierwszy.
         */
        unlock: function (kto) {
            kto = kto || 'nieznany';
            var i = trzymajacy.indexOf(kto);
            if (i === -1) {
                /* Odblokowanie przez kogoś, kto nie blokował. Przy liczniku
                   zdejmowałoby to cudzą blokadę i strona jechałaby pod otwartym
                   panelem; przy zbiorze jest nieszkodliwe, ale prawie zawsze
                   znaczy błąd w parowaniu wywołań — więc mówimy o tym głośno. */
                if (gadaj) {
                    console.warn('[EVK Scroll] unlock bez wcześniejszego lock — ' + kto,
                        '| trzymają:', trzymajacy.slice());
                }
                return;
            }
            trzymajacy.splice(i, 1);
            if (!trzymajacy.length) zdejmij();
            raport('unlock', kto);
        },

        /** Kto w tej chwili trzyma przewijanie. Pusta lista = strona jedzie. */
        trzymajacy: function () { return trzymajacy.slice(); },

        /** Pełny stan — do diagnostyki przy zacięciu. */
        stan: function () {
            return {
                zablokowane: !!trzymajacy.length,
                trzymajacy:  trzymajacy.slice(),
                klasa:       document.documentElement.classList.contains(KLASA),
                lenis:       !!lenis(),
                kompensata:  document.body.style.paddingRight || '',
            };
        },
    };

    if (gadaj) {
        console.log('[EVK Scroll] diagnostyka włączona — evkScroll.stan() pokaże, kto trzyma przewijanie.');
    }
})();
</script>
    <?php
}, 1);
