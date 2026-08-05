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
            gsap.set(cards, { clearProps: 'transform,opacity' });
            root.classList.remove('is-active');
        }

        function setup() {
            root.classList.add('is-active');

            cards.forEach(function (card, i) {
                // Schodkowanie: każda kolejna karta zatrzymuje się nieco niżej,
                // dzięki czemu widać krawędzie tych pod spodem.
                if (stagger > 0) {
                    card.style.top = 'calc(' + offset + ' + ' + (i * stagger) + 'px)';
                }
                // Karty wyżej w stosie muszą leżeć nad wcześniejszymi.
                card.style.zIndex = String(i + 1);

                // Ostatnia karta nie ma nic nad sobą — nie ma czego przygaszać.
                if (i === cards.length - 1) return;

                var vars = {};
                if (cfg.shrink) vars.scale = cfg.minScale || 0.9;
                if (cfg.dim > 0) vars.opacity = 1 - cfg.dim;
                if (!Object.keys(vars).length) return;

                vars.ease = 'none';

                var tl = gsap.to(card, Object.assign(vars, {
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
