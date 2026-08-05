/* EVK Scroll Reading — frontend
 * Wymaga GSAP + ScrollTrigger + SplitText (ładowane przez Bricks Animator).
 *
 * Kolory są wyliczane w JS (GSAP nie tweenuje var(--x)), więc po zmianie
 * motywu trzeba je przeliczyć ponownie — inaczej element trzyma wartości
 * z momentu startu strony aż do odświeżenia.
 */
(function () {
  'use strict';

  var instances = [];
  var themeSig  = null;
  var pending   = false;

  // GSAP nie potrafi tweenować koloru podanego jako var(--x) / globalny kolor Bricks.
  // Rozwijamy go do konkretnej wartości RGB w kontekście danego elementu.
  function resolveColor(value, ctxEl) {
    if (!value) return value;
    var str = String(value).trim();
    if (str.indexOf('var(') === -1) return str; // już konkretny kolor
    var probe = document.createElement('span');
    probe.style.cssText = 'position:absolute;left:-9999px;top:-9999px;color:' + str + ';';
    (ctxEl || document.body).appendChild(probe);
    var resolved = getComputedStyle(probe).color;
    probe.remove();
    return resolved || str;
  }

  // Sygnatura motywu — reagujemy tylko na jej zmianę, nie na każdą mutację
  // klas <html> (dark mode dokłada np. is-theme-toggling przy animacji).
  function themeSignature() {
    var h = document.documentElement;
    return (h.getAttribute('data-theme') || '') + '|' + (h.classList.contains('dark') ? 'dark' : '');
  }

  function build(inst) {
    inst.dim    = resolveColor(inst.cfg.colorDim, inst.el);
    inst.active = resolveColor(inst.cfg.colorActive, inst.el);

    if (inst.tl) {
      if (inst.tl.scrollTrigger) inst.tl.scrollTrigger.kill();
      inst.tl.kill();
    }

    gsap.set(inst.targets, { color: inst.dim });

    inst.tl = gsap.timeline({
      scrollTrigger: {
        trigger: inst.el,
        start:   inst.cfg.start || 'top 90%',
        end:     inst.cfg.end   || 'bottom 20%',
        scrub:   inst.cfg.scrub > 0 ? inst.cfg.scrub : false,
      },
    }).to(inst.targets, {
      color:   inst.active,
      stagger: inst.cfg.stagger || 0.05,
      ease:    'none',
    });
  }

  function initAll() {
    document.querySelectorAll('[data-evk-sr]').forEach(function (el) {
      var cfg;
      try { cfg = JSON.parse(el.getAttribute('data-evk-sr')); }
      catch (e) { return; }

      // Cel: cały kontener (nestable — children to dowolne elementy Bricks)
      var splitTypeMap = { words: 'words', chars: 'chars', lines: 'lines' };
      var splitBy = splitTypeMap[cfg.splitType] || 'words';

      // tag + aria, bo element jest nestable — dzielimy KONTENER z dowolnymi
      // dziećmi Bricks, a nie pojedynczy nagłówek.
      //
      // aria:'none' — domyślne 'auto' ustawia na kontenerze aria-label z surowego
      // textContent (na granicy bloków wyrazy się sklejają: „ProjektujemyRobimy")
      // i oznacza każdy kawałek aria-hidden. Zmierzone w drzewie dostępności:
      // nagłówek i odnośnik traciły przez to swoje nazwy, a etykieta lądowała na
      // elemencie o roli generic, gdzie i tak nie jest wiarygodnie ogłaszana.
      //
      // tag:'span' — domyślne div-y wchodzą do drzewa dostępności jako bloki;
      // spany są dla niego przezroczyste. Wygląd bez zmian, bo CSS elementu
      // celuje w klasy, nie w znaczniki.
      var split = new SplitText(el, {
        type: splitBy,
        tag: 'span',
        aria: 'none',
        wordsClass: 'evk-sr-word',
        charsClass: 'evk-sr-char',
        linesClass: 'evk-sr-line',
      });

      var inst = { el: el, cfg: cfg, targets: split[splitBy], tl: null };
      instances.push(inst);
      build(inst);
    });

    themeSig = themeSignature();
    watchTheme();
  }

  // Przebuduj oś czasu na nowych kolorach. ScrollTrigger.refresh() przywraca
  // pozycję scrolla, więc tekst nie „przeskakuje" po przełączeniu motywu.
  function rebuildAll() {
    pending = false;
    if (!instances.length) return;
    instances.forEach(build);
    ScrollTrigger.refresh();
  }

  // Odroczenie: zmiana motywu może lecieć w callbacku View Transition,
  // gdzie ciężkie odczyty layoutu blokowałyby animację.
  function scheduleRebuild() {
    if (pending) return;
    pending = true;
    requestAnimationFrame(rebuildAll);
  }

  function onThemeMaybeChanged() {
    var sig = themeSignature();
    if (sig === themeSig) return;
    themeSig = sig;
    scheduleRebuild();
  }

  function watchTheme() {
    // Moduł Dark Mode Evoke ONE — jawny sygnał.
    document.addEventListener('evk:theme-change', onThemeMaybeChanged);

    // Uniwersalnie: dowolny przełącznik motywu, który zmienia data-theme
    // albo klasę .dark na <html> (m.in. natywny toggle Bricks).
    if (window.MutationObserver) {
      new MutationObserver(onThemeMaybeChanged).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme', 'class'],
      });
    }

    // Motyw sterowany preferencją systemu.
    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) mq.addEventListener('change', scheduleRebuild);
    }
  }

  function waitForGSAP(cb, tries) {
    tries = tries || 0;
    if (window.gsap && window.ScrollTrigger && window.SplitText) {
      gsap.registerPlugin(ScrollTrigger, SplitText);
      cb();
    } else if (tries < 50) {
      setTimeout(function () { waitForGSAP(cb, tries + 1); }, 100);
    } else {
      console.warn('[EVK Scroll Reading] Brak GSAP/ScrollTrigger/SplitText.');
    }
  }

  // W builderze Bricks (bricksIsFrontend === false) element się nie animuje.
  // typeof zamiast gołej zmiennej — brak Bricks nie może wywalić skryptu.
  function start() {
    var frontend = typeof bricksIsFrontend === 'undefined' ? true : !!bricksIsFrontend;
    if (frontend) waitForGSAP(initAll);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
