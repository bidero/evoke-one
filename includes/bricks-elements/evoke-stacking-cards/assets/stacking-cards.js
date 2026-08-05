/* Evoke ONE — Stacking Cards (frontend)
 *
 * Nakładanie kart realizuje CSS przez position:sticky — bez pinu, więc bez
 * pin-spacerów i bez przeliczania wysokości. GSAP dokłada tylko skalowanie
 * i przygaszanie kart, które zostały pod spodem.
 *
 * Dwie rzeczy w tym pliku wyglądają na okrężne i takie nie są — obie wynikają
 * z zachowania sticky zmierzonego w przeglądarce (Chromium, karty 100vh):
 *
 * 1. ZAPAS POD STOSEM TO ELEMENT, NIE PADDING. Sticky trzyma kartę w granicach
 *    CONTENT BOXA rodzica. padding-bottom leży poza content boxem, więc nie
 *    przedłuża fazy przyklejenia ani o piksel — 0px, 120px i 400px paddingu dają
 *    identyczny przebieg. Miejsce pod ostatnią kartą musi dać realny element.
 *
 * 2. SCHODEK ROBI TRANSFORM, NIE `top`. Karty zwalniają w kolejności odwrotnej
 *    do ustawienia, a odstęp między zwolnieniami to dokładnie różnica ich `top`.
 *    Przy schodkowaniu przez `top` ostatnia karta ruszała jako pierwsza i wchodziła
 *    na poprzednie — dokładnie to było widać na stronie. Wspólny `top` dla
 *    wszystkich kart + przesunięcie transformem daje ten sam obraz, ale zwolnienie
 *    równoczesne: schodki zostają nienaruszone aż stos zjedzie z ekranu.
 *    (Układ przepływu na to nie wpływa — różnica bierze się wyłącznie z `top`.)
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
        // O tyle transform przesuwa ostatnią kartę w dół względem jej miejsca
        // w układzie — o tyle też stos wystaje poniżej kontenera.
        var lastStep = stagger * (cards.length - 1);

        root.style.setProperty('--evk-sc-offset', offset);
        root.style.setProperty('--evk-sc-gap', cfg.gap || '40px');

        // Poniżej breakpointu karty mają się układać normalnie. matchMedia zamiast
        // pomiaru przy starcie — obrót telefonu ma przełączać tryb bez przeładowania.
        var below = parseInt(cfg.disableBelow, 10) || 0;
        var mq    = below > 0 && window.matchMedia
            ? window.matchMedia('(min-width: ' + below + 'px)')
            : null;

        var triggers    = [];
        var active      = false;
        var spacer      = null;
        var resizeTimer = null;

        function sizeSpacer() {
            if (!spacer) return;
            var manual = (cfg.bottomSpace || '').trim();
            if (manual !== '') { spacer.style.height = manual; return; }
            // Automat: pół wysokości ostatniej karty. Mierzone, bo karty bywają
            // na 100vh — sztywna wartość w px byłaby albo za krótka, albo absurdalna.
            var h = cards[cards.length - 1].getBoundingClientRect().height;
            spacer.style.height = Math.round(h / 2) + 'px';
        }

        function teardown() {
            active = false;
            triggers.forEach(function (t) { t.kill(); });
            triggers = [];
            gsap.set(cards, { clearProps: 'transform,filter' });
            if (spacer && spacer.parentNode) spacer.parentNode.removeChild(spacer);
            root.style.paddingBottom = '';
            root.classList.remove('is-active', 'has-shadow');
        }

        function setup() {
            active = true;
            root.classList.add('is-active');
            if (cfg.shadow) {
                root.classList.add('has-shadow');
                root.style.setProperty('--evk-sc-shadow', cfg.shadowValue || '0 -8px 30px rgba(0,0,0,.18)');
            }

            // Zapas pod stosem — decyduje, jak długo gotowy stos stoi, zanim odjedzie.
            if (!spacer) {
                spacer = document.createElement('div');
                spacer.className = 'evk-sc-space';
                spacer.setAttribute('aria-hidden', 'true');
            }
            root.appendChild(spacer);
            sizeSpacer();

            // Ostatnia karta jest przesunięta transformem o lastStep w dół, więc
            // wystaje tyle samo poniżej kontenera. Padding oddziela stos od
            // następnej sekcji — i nie rusza sticky, bo leży poza content boxem.
            if (lastStep) root.style.paddingBottom = lastStep + 'px';

            cards.forEach(function (card, i) {
                var step = i * stagger;
                if (step) card.style.transform = 'translateY(' + step + 'px)';
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

                // GSAP przejmuje cały transform — bez tego skalowanie skasowałoby
                // schodek ustawiony wyżej inline.
                if (step) { fromVars.y = step; toVars.y = step; }

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
            // Flaga zamiast triggers.length — przy wyłączonym zmniejszaniu
            // i przyciemnianiu żaden trigger nie powstaje, a stos i tak działa.
            var on = mq ? mq.matches : true;
            if (on && !active) setup();
            else if (!on && active) teardown();
        }

        apply();
        if (mq && mq.addEventListener) mq.addEventListener('change', apply);

        // Automatyczny zapas zależy od wysokości karty, a ta przy 100vh zmienia
        // się z oknem. Bez tego po obrocie ekranu stos trzymałby starą miarę.
        window.addEventListener('resize', function () {
            if (!active) return;
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                sizeSpacer();
                ScrollTrigger.refresh();
            }, 150);
        });
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
