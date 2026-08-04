/* Evoke ONE — Animator (silnik)
 *
 * Konfiguracja jest scalana z trzech warstw, w tej kolejności:
 *   preset (kod) ⊕ wiersz biblioteki (panel) ⊕ data-evk-anim (element)
 * Dzięki temu zmiana definicji w panelu przestawia całą stronę, a pojedynczy
 * element można odchylić bez zakładania nowej definicji.
 *
 * Wymaga GSAP + ScrollTrigger (+ SplitText, gdy preset dzieli tekst) —
 * handle'e rejestruje includes/89-gsap.php.
 */
(function () {
  'use strict';

  var G = window.evkAnimator || {};
  var LIBRARY = G.library || {};
  var PRESETS = G.presets || {};

  var loadQueue = [];

  // ── Helpers ────────────────────────────────────────────────────────────

  function prefersReduced() {
    return !!G.reducedMotion
        && window.matchMedia
        && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  }

  function pick() {
    for (var i = 0; i < arguments.length; i++) {
      var v = arguments[i];
      if (v !== undefined && v !== null && v !== '') return v;
    }
    return undefined;
  }

  function num(v, fallback) {
    var n = parseFloat(v);
    return isNaN(n) ? fallback : n;
  }

  /** Slug z klasy evk-anim-{slug}; pomija sam prefiks bez sluga. */
  function slugFromClass(el) {
    for (var i = 0; i < el.classList.length; i++) {
      var c = el.classList[i];
      if (c.indexOf('evk-anim-') === 0 && c.length > 9) return c.slice(9);
    }
    return '';
  }

  function attrConfig(el) {
    var raw = el.getAttribute('data-evk-anim');
    if (!raw) return {};
    raw = raw.trim();
    if (!raw) return {};
    // Skrót: sam slug zamiast JSON-a.
    if (raw.charAt(0) !== '{') return { animation: raw };
    try { return JSON.parse(raw); }
    catch (e) {
      console.warn('[EVK Animator] Nieprawidłowy JSON w data-evk-anim:', raw);
      return {};
    }
  }

  function buildConfig(el) {
    var attr = attrConfig(el);
    var slug = attr.animation || slugFromClass(el);
    var lib  = LIBRARY[slug] || {};

    // Slug wskazany, ale nieobecny w bibliotece — najczęściej skutek zmiany
    // sluga w panelu przy elemencie, który trzyma starą nazwę. Bez ostrzeżenia
    // element po prostu nie animuje się i trudno zgadnąć dlaczego.
    if (slug && !LIBRARY[slug]) {
      console.warn('[EVK Animator] Brak animacji "' + slug + '" w bibliotece.', el);
    }

    var pre = PRESETS[pick(attr.preset, lib.preset)] || {};

    // from/to: nadpisywane w całości, nie scalane po kluczach — inaczej
    // resztki poprzedniej warstwy wchodziłyby w drogę własnym wartościom.
    var from = attr.from || lib.from || pre.from || null;
    var to   = attr.to   || lib.to   || pre.to   || null;
    if (!from && !to) return null;

    return {
      from:     from,
      to:       to,
      split:    pick(attr.split, lib.split, pre.split, ''),
      trigger:  pick(attr.trigger, lib.trigger, 'viewport'),
      easing:   pick(attr.easing, lib.easing, 'power2.out'),
      duration: num(pick(attr.duration, lib.duration, pre.duration), 0.8),
      delay:    num(pick(attr.delay, lib.delay), 0),
      stagger:  num(pick(attr.stagger, lib.stagger, pre.stagger), 0),
      scrub:    num(pick(attr.scrub, lib.scrub), 1),
      start:    pick(attr.start, lib.start, 'top 85%'),
      end:      pick(attr.end, lib.end, 'bottom 40%'),
      repeat:   !!pick(attr.repeat, lib.repeat, false),
      order:    num(pick(attr.order, lib.order), 0),
    };
  }

  /** Cele animacji: kawałki po SplitText albo sam element. */
  function resolveTargets(el, cfg) {
    if (!cfg.split || typeof SplitText === 'undefined') return [el];
    var map = { lines: 'lines', words: 'words', chars: 'chars' };
    var type = map[cfg.split];
    if (!type) return [el];
    var split = new SplitText(el, {
      type: type,
      linesClass: 'evk-anim-line',
      wordsClass: 'evk-anim-word',
      charsClass: 'evk-anim-char',
    });
    return split[type] && split[type].length ? split[type] : [el];
  }

  // ── Budowa osi czasu ───────────────────────────────────────────────────

  function buildTimeline(targets, cfg, paused) {
    var tl = gsap.timeline({ paused: !!paused });
    var vars = Object.assign({}, cfg.to, {
      duration: cfg.duration,
      ease:     cfg.easing,
    });
    if (cfg.stagger > 0) vars.stagger = cfg.stagger;

    if (cfg.from) {
      tl.fromTo(targets, Object.assign({}, cfg.from), vars);
    } else {
      tl.to(targets, vars);
    }
    return tl;
  }

  // ── Wyzwalacze ─────────────────────────────────────────────────────────

  // ScrollTrigger w varsach osi czasu, nie ScrollTrigger.create + ręczne onEnter.
  // Powód: (1) delay podany varsami faktycznie działa — wołanie tl.delay() na już
  // utworzonej osi czasu jest no-opem, bo liczy się względem startu rodzica;
  // (2) fromTo renderuje stan początkowy natychmiast, więc element od razu dostaje
  // np. opacity:0 — gdyby onEnter nie wystrzelił (element widoczny już przy
  // załadowaniu strony), treść zostałaby niewidoczna na stałe. toggleActions
  // pozwala ScrollTriggerowi rozstrzygnąć stan przy pierwszym refreshu.
  function attachViewport(el, targets, cfg) {
    var vars = Object.assign({}, cfg.to, {
      duration: cfg.duration,
      ease:     cfg.easing,
    });
    if (cfg.stagger > 0) vars.stagger = cfg.stagger;

    var tl = gsap.timeline({
      delay: cfg.delay,
      scrollTrigger: {
        trigger:       el,
        start:         cfg.start,
        once:          !cfg.repeat,
        toggleActions: cfg.repeat ? 'play reverse play reverse' : 'play none none none',
      },
    });

    if (cfg.from) tl.fromTo(targets, Object.assign({}, cfg.from), vars);
    else          tl.to(targets, vars);
  }

  function attachScrub(el, targets, cfg) {
    var tl = gsap.timeline({
      scrollTrigger: {
        trigger: el,
        start:   cfg.start,
        end:     cfg.end,
        scrub:   cfg.scrub > 0 ? cfg.scrub : true,
      },
    });
    var vars = Object.assign({}, cfg.to, { ease: 'none' });
    if (cfg.stagger > 0) vars.stagger = cfg.stagger;
    if (cfg.from) tl.fromTo(targets, Object.assign({}, cfg.from), vars);
    else          tl.to(targets, vars);
  }

  function attachInteractive(el, targets, cfg) {
    var tl = buildTimeline(targets, cfg, true);
    var ac = new AbortController();
    var opts = { signal: ac.signal };

    if (cfg.trigger === 'hover') {
      el.addEventListener('mouseenter', function () { tl.play(); }, opts);
      el.addEventListener('mouseleave', function () { tl.reverse(); }, opts);
      // Klawiatura — hover bez odpowiednika fokusowego wyklucza część użytkowników.
      el.addEventListener('focusin',  function () { tl.play(); }, opts);
      el.addEventListener('focusout', function () { tl.reverse(); }, opts);
    } else {
      el.addEventListener('click', function () {
        if (tl.progress() > 0 && tl.reversed() === false) tl.reverse();
        else tl.play();
      }, opts);
    }

    el._evkAnimAbort = ac;
  }

  function queueLoad(el, targets, cfg) {
    loadQueue.push({ el: el, targets: targets, cfg: cfg });
  }

  function runLoadQueue() {
    if (!loadQueue.length) return;
    loadQueue.sort(function (a, b) { return a.cfg.order - b.cfg.order; });

    var master = gsap.timeline();
    loadQueue.forEach(function (item) {
      var cfg  = item.cfg;
      var vars = Object.assign({}, cfg.to, { duration: cfg.duration, ease: cfg.easing });
      if (cfg.stagger > 0) vars.stagger = cfg.stagger;
      // Pozycja bezwzględna — 'order' ustala kolejność, 'delay' odstęp od startu.
      var pos = cfg.delay > 0 ? '+=' + cfg.delay : '<';
      if (cfg.from) master.fromTo(item.targets, Object.assign({}, cfg.from), vars, pos);
      else          master.to(item.targets, vars, pos);
    });
    loadQueue = [];
  }

  // ── Init ───────────────────────────────────────────────────────────────

  function initOne(el) {
    var cfg = buildConfig(el);
    if (!cfg) return false;   // brak konfiguracji → spróbuj ponownie później

    var targets = resolveTargets(el, cfg);

    // Reduced motion: żadnego ruchu, ale stan końcowy musi być widoczny —
    // inaczej element z opacity:0 we from zostałby niewidzialny na stałe.
    if (prefersReduced()) {
      if (cfg.to) gsap.set(targets, cfg.to);
      return true;
    }

    switch (cfg.trigger) {
      case 'scrub': attachScrub(el, targets, cfg); break;
      case 'hover':
      case 'click': attachInteractive(el, targets, cfg); break;
      case 'load':  queueLoad(el, targets, cfg); break;
      default:      attachViewport(el, targets, cfg);
    }
    return true;
  }

  function initAll() {
    document.querySelectorAll('[class*="evk-anim-"], [data-evk-anim]').forEach(function (el) {
      if (el.dataset.evkAnimReady === '1') return;
      if (initOne(el)) el.dataset.evkAnimReady = '1';
    });
    runLoadQueue();
  }

  function waitForGSAP(cb, tries) {
    tries = tries || 0;
    if (window.gsap && window.ScrollTrigger) {
      gsap.registerPlugin(ScrollTrigger);
      if (window.SplitText) gsap.registerPlugin(SplitText);
      cb();
    } else if (tries < 50) {
      setTimeout(function () { waitForGSAP(cb, tries + 1); }, 100);
    } else {
      console.warn('[EVK Animator] Brak GSAP/ScrollTrigger.');
    }
  }

  function start() {
    if (!Object.keys(LIBRARY).length && !document.querySelector('[data-evk-anim]')) return;

    // SplitText musi dzielić tekst PO załadowaniu webfontów. Uruchomiony wcześniej
    // liczy linie na metrykach fontu zastępczego, a po podmianie fontu podział się
    // rozjeżdża — GSAP ostrzega o tym w konsoli („SplitText called before fonts
    // loaded"). Czekamy więc na document.fonts.ready; przy braku Font Loading API
    // startujemy od razu, jak dotąd.
    if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
      document.fonts.ready.then(function () { waitForGSAP(initAll); });
    } else {
      waitForGSAP(initAll);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  // Punkt wejścia dla treści doładowywanej dynamicznie (AJAX, loop, popupy).
  window.evkAnimatorRefresh = initAll;
})();
