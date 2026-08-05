/* Evoke ONE — Stacking Cards (frontend)
 *
 * Nakładanie kart realizuje CSS przez position:sticky — bez pinu, więc bez
 * pin-spacerów i bez przeliczania wysokości. GSAP dokłada tylko skalowanie
 * i przygaszanie kart, które zostały pod spodem.
 *
 * Wymaga GSAP + ScrollTrigger; handle'e rejestruje includes/89-gsap.php.
 */
function evk_stacking_cards_init() {
    document.querySelectorAll('.evk-sc').forEach(function (root) {
        if (root.dataset.evkScReady === '1') return;

        var cfg;
        try { cfg = JSON.parse(root.getAttribute('data-evk-sc') || '{}'); }
        catch (e) { cfg = {}; }

        var cards = Array.prototype.slice.call(root.children);
        if (cards.length < 2) return;   // jedna karta nie ma się na co nakładać

        root.dataset.evkScReady = '1';

        var offset  = cfg.offsetTop || '80px';
        var stagger = parseInt(cfg.stagger, 10) || 0;

        root.style.setProperty('--evk-sc-offset', offset);
        root.style.setProperty('--evk-sc-gap', cfg.gap || '40px');

        // Poniżej breakpointu karty mają się układać normalnie. matchMedia zamiast
        // pomiaru przy starcie — obrót telefonu ma przełączać tryb bez przeładowania.
        var below = parseInt(cfg.disableBelow, 10) || 0;
        var mq    = below > 0 && window.matchMedia
            ? window.matchMedia('(min-width: ' + below + 'px)')
            : null;

        var triggers = [];

        function teardown() {
            triggers.forEach(function (t) { t.kill(); });
            triggers = [];
            gsap.set(cards, { clearProps: 'transform,filter' });
            root.style.paddingBottom = '';
            root.classList.remove('is-active', 'has-shadow');
        }

        function setup() {
            root.classList.add('is-active');
            if (cfg.shadow) {
                root.classList.add('has-shadow');
                root.style.setProperty('--evk-sc-shadow', cfg.shadowValue || '0 -8px 30px rgba(0,0,0,.18)');
            }

            // Sticky trzyma kartę tylko dopóki kontener ma pod nią miejsce.
            // Kontener kończy się tuż za ostatnią kartą, więc odkleja się ona
            // niemal natychmiast i sunie w górę po poprzednich zamiast stanąć
            // na swoim schodku. Zapas pod stosem przedłuża fazę sticky.
            //
            // Domyślnie: liczba kart × schodek. Przy wysokich kartach to bywa za
            // mało — wtedy służy kontrolka „Zapas pod stosem" (np. 50vh).
            var reserve = (cfg.bottomSpace || '').trim();
            if (!reserve && stagger > 0) reserve = (cards.length * stagger) + 'px';
            if (reserve) root.style.paddingBottom = reserve;

            cards.forEach(function (card, i) {
                // Schodkowanie: każda kolejna karta zatrzymuje się nieco niżej,
                // dzięki czemu widać krawędzie tych pod spodem.
                if (stagger > 0) {
                    card.style.top = 'calc(' + offset + ' + ' + (i * stagger) + 'px)';
                }
                // Karty wyżej w stosie muszą leżeć nad wcześniejszymi.
                card.style.zIndex = String(i + 1);

                // Ostatnia karta nie ma nic nad sobą — nie ma czego przyciemniać.
                if (i === cards.length - 1) return;

                // fromTo, nie to: karta nie ma ustawionego filter, więc stan
                // wyjściowy to `none`. GSAP podstawia wtedy za brakującą funkcję
                // wartość zerową — brightness(0), czyli CZERŃ — i scrub przewija
                // od czarnego. Stan początkowy musi być podany jawnie.
                var fromVars = {};
                var toVars   = {};

                if (cfg.shrink) {
                    fromVars.scale = 1;
                    toVars.scale   = cfg.minScale || 0.9;
                }
                // brightness, nie opacity: karta ma ciemnieć, a nie prześwitywać.
                // Przy opacity widać przez nią tło strony i stos się rozłazi.
                if (cfg.dim > 0) {
                    fromVars.filter = 'brightness(1)';
                    toVars.filter   = 'brightness(' + (1 - cfg.dim) + ')';
                }
                if (!Object.keys(toVars).length) return;

                toVars.ease = 'none';
                // Bez tego GSAP renderuje stan „from" od razu, zanim wyzwalacz
                // w ogóle wejdzie w zakres.
                toVars.immediateRender = false;

                var tl = gsap.fromTo(card, fromVars, Object.assign(toVars, {
                    scrollTrigger: {
                        trigger: cards[i + 1],
                        start:   'top bottom',
                        end:     'top ' + offset,
                        scrub:   true,
                    },
                }));
                if (tl.scrollTrigger) triggers.push(tl.scrollTrigger);
            });

            ScrollTrigger.refresh();
        }

        function apply() {
            var on = mq ? mq.matches : true;
            if (on && !triggers.length) setup();
            else if (!on && triggers.length) teardown();
            else if (!on) root.classList.remove('is-active');
        }

        apply();
        if (mq && mq.addEventListener) mq.addEventListener('change', apply);
    });
}

function evkStackingCardsWait(tries) {
    tries = tries || 0;
    if (window.gsap && window.ScrollTrigger) {
        gsap.registerPlugin(ScrollTrigger);
        evk_stacking_cards_init();
    } else if (tries < 50) {
        setTimeout(function () { evkStackingCardsWait(tries + 1); }, 100);
    } else {
        console.warn('[EVK Stacking Cards] Brak GSAP/ScrollTrigger.');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    if (typeof bricksIsFrontend === 'undefined' || bricksIsFrontend) {
        evkStackingCardsWait();
    }
});
