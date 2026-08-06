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
  var loadQueueRan = false;

  // ── Helpers ────────────────────────────────────────────────────────────

  /**
   * Polityka ruchu jest wspólna dla całej wtyczki — patrz includes/anim/motion.php.
   * Własny fallback, żeby silnik nie zależał od kolejności ładowania skryptów;
   * przy braku helpera pytamy sam system, bo bezpieczniej uszanować preferencję
   * niż ją zignorować.
   */
  function prefersReduced() {
    if (window.evkMotion && typeof window.evkMotion.reduced === 'function') {
      return window.evkMotion.reduced();
    }
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
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
      mask:     pick(attr.mask, lib.mask, pre.mask, ''),
      targets:  pick(attr.targets, lib.targets, 'self'),
      selector: pick(attr.selector, lib.selector, ''),
      pin:      !!pick(attr.pin, lib.pin, false),
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

  /**
   * Cele animacji: sam element, jego dzieci albo selektor w środku.
   * To dopiero nadaje sens polu „stagger" poza tekstem — pojedynczy element
   * nie ma czego rozsuwać.
   */
  function resolveTargets(el, cfg) {
    if (cfg.targets === 'children') {
      var kids = Array.prototype.slice.call(el.children);
      return kids.length ? kids : [el];
    }
    if (cfg.targets === 'selector' && cfg.selector) {
      var found;
      // Błędna składnia selektora rzuca wyjątkiem i wywaliłaby całą inicjalizację.
      try { found = el.querySelectorAll(cfg.selector); }
      catch (e) {
        console.warn('[EVK Animator] Nieprawidłowy selektor celu:', cfg.selector, el);
        return [el];
      }
      return found.length ? Array.prototype.slice.call(found) : [el];
    }
    return [el];
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
    return tl;
  }

  function attachScrub(el, targets, cfg) {
    var tl = gsap.timeline({
      scrollTrigger: {
        trigger: el,
        start:   cfg.start,
        end:     cfg.end,
        scrub:   cfg.scrub > 0 ? cfg.scrub : true,
        // Pin wyłącznie przy scrubie. Przy pozostałych wyzwalaczach nie ma sensu
        // (nie ma czego przytrzymywać), a tworzy pin-spacer, który rozpycha layout.
        pin:           cfg.pin ? el : false,
        anticipatePin: cfg.pin ? 1 : 0,
      },
    });
    var vars = Object.assign({}, cfg.to, { ease: 'none' });
    if (cfg.stagger > 0) vars.stagger = cfg.stagger;
    if (cfg.from) tl.fromTo(targets, Object.assign({}, cfg.from), vars);
    else          tl.to(targets, vars);
    return tl;
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
    return tl;
  }

  function queueLoad(el, targets, cfg) {
    // Kolejka startowa odpala się raz. Ponowny podział tekstu przy zmianie
    // szerokości okna nie ma odtwarzać animacji wejściowej — ona już była.
    if (loadQueueRan) return;
    loadQueue.push({ el: el, targets: targets, cfg: cfg });
  }

  function runLoadQueue() {
    loadQueueRan = true;
    if (!loadQueue.length) return;
    loadQueue.sort(function (a, b) { return a.cfg.order - b.cfg.order; });

    var master    = gsap.timeline();
    var stepStart = 0;      // moment startu bieżącego kroku
    var stepOrder = null;

    loadQueue.forEach(function (item) {
      var cfg = item.cfg;

      // 'order' to KROK sekwencji: elementy z tym samym numerem ruszają razem,
      // kolejny numer czeka, aż poprzedni krok się skończy. Kolejka jest już
      // posortowana po 'order', więc master.duration() w chwili zmiany numeru
      // to dokładnie koniec kroku poprzedniego.
      if (stepOrder !== null && cfg.order !== stepOrder) stepStart = master.duration();
      stepOrder = cfg.order;

      var vars = Object.assign({}, cfg.to, { duration: cfg.duration, ease: cfg.easing });
      if (cfg.stagger > 0) vars.stagger = cfg.stagger;

      // LICZBA = pozycja bezwzględna względem początku osi. Wcześniej było tu
      // '+=' + delay, które w GSAP liczy się od KOŃCA dotychczasowej osi —
      // opóźnienia sumowały się z czasami trwania poprzednich animacji.
      // Ustawione 0 / 0,3 / 0,6 dawało starty 0 / 1,1 / 2,5.
      var pos = stepStart + cfg.delay;
      if (cfg.from) master.fromTo(item.targets, Object.assign({}, cfg.from), vars, pos);
      else          master.to(item.targets, vars, pos);
    });
    loadQueue = [];
  }

  // ── Init ───────────────────────────────────────────────────────────────

  /** Podpina animację pod wybrany wyzwalacz i ZWRACA oś czasu (albo null dla load). */
  function buildAnimation(el, targets, cfg) {
    switch (cfg.trigger) {
      case 'scrub': return attachScrub(el, targets, cfg);
      case 'hover':
      case 'click': return attachInteractive(el, targets, cfg);
      case 'load':  queueLoad(el, targets, cfg); return null;
      default:      return attachViewport(el, targets, cfg);
    }
  }

  /**
   * Podział tekstu z ponownym podziałem po zmianie szerokości okna.
   *
   * Bez autoSplit tekst dzielony jest raz: po resize łamanie linii się zmienia,
   * a kawałki zostają z poprzedniego rozmiaru i animacja się rozjeżdża.
   * onSplit MUSI zwrócić oś czasu — GSAP sprząta ją wtedy sam przed kolejnym
   * podziałem, zamiast zostawiać osierocone tweeny na nieistniejących węzłach.
   */
  function initSplit(el, cfg) {
    var map  = { lines: 'lines', words: 'words', chars: 'chars' };
    var type = map[cfg.split];
    if (!type) { buildAnimation(el, [el], cfg); return true; }

    // aria warunkowo. Domyślne 'auto' jest POPRAWNE dla pojedynczego nagłówka czy
    // akapitu: element ma własną rolę, więc aria-label działa, a ukrycie kawałków
    // jest tam zalecane. Psuje się na kontenerze z wieloma dziećmi — aria-label
    // sklejałby ich teksty w jeden ciąg, a aria-hidden odbierał nazwy nagłówkom
    // i odnośnikom w środku.
    //
    // Znacznik zostaje domyślny (div). SplitText nakłada `display` i `position`
    // WYŁĄCZNIE gdy tag !== 'span' — przy spanach kawałki zostają inline, a na
    // pudełkach inline transformacje nie działają, więc cała animacja by padła.
    // Scroll Reading może sobie pozwolić na spany, bo ma własny CSS na te klasy.
    var opts = {
      type:       type,
      aria:       el.children.length > 1 ? 'none' : 'auto',
      linesClass: 'evk-anim-line',
      wordsClass: 'evk-anim-word',
      charsClass: 'evk-anim-char',
      autoSplit:  true,
      onSplit:    function (self) {
        var pieces = self[type];
        return buildAnimation(el, (pieces && pieces.length) ? pieces : [el], cfg);
      },
    };
    // mask: 'lines' — GSAP sam robi owijki overflow:hidden pod odsłonę zza maski.
    if (cfg.mask) opts.mask = cfg.mask;

    if (typeof SplitText.create === 'function') {
      SplitText.create(el, opts);
    } else {
      // GSAP < 3.13 nie zna autoSplit/onSplit — jednorazowy podział, jak dotąd.
      var split  = new SplitText(el, opts);
      var pieces = split[type];
      buildAnimation(el, (pieces && pieces.length) ? pieces : [el], cfg);
    }
    return true;
  }

  function initOne(el) {
    var cfg = buildConfig(el);
    if (!cfg) return false;   // brak konfiguracji → spróbuj ponownie później

    // Reduced motion: żadnego ruchu, ale stan końcowy musi być widoczny —
    // inaczej element z opacity:0 we from zostałby niewidzialny na stałe.
    // Przy podziale tekstu nie ma po co dzielić: i tak nic się nie animuje.
    if (prefersReduced()) {
      if (cfg.to) gsap.set(resolveTargets(el, cfg), cfg.to);
      return true;
    }

    if (cfg.split && typeof SplitText !== 'undefined') return initSplit(el, cfg);

    buildAnimation(el, resolveTargets(el, cfg), cfg);
    return true;
  }

  /**
   * Zdejmuje zasłonę z <html> (patrz render_preveil() w includes/anim/animator.php).
   * Nazwa klasy celowo poza przestrzenią „evk-anim-" — inaczej selektor w initAll()
   * łapie sam korzeń dokumentu i silnik szuka animacji o slugu z tej klasy.
   */
  function unveil() {
    document.documentElement.classList.remove('evk-veil');
  }

  function initAll() {
    document.querySelectorAll('[class*="evk-anim-"], [data-evk-anim]').forEach(function (el) {
      if (el.dataset.evkAnimReady === '1') return;
      if (initOne(el)) el.dataset.evkAnimReady = '1';
    });
    runLoadQueue();
    // Bezwarunkowo — także gdy część elementów się nie zainicjalizowała.
    // Stany „from" są już nałożone, więc nie ma czym błysnąć.
    unveil();
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
      unveil();   // GSAP nie dojechał — treść nie może zostać ukryta
    }
  }

  function start() {
    if (!Object.keys(LIBRARY).length && !document.querySelector('[data-evk-anim]')) {
      unveil();
      return;
    }

    // Na webfonty czekamy TYLKO przy podziale tekstu: jedynie tam metryki fontu
    // decydują o łamaniu linii. Przy pozostałych animacjach to czyste opóźnienie
    // startu, przez które element zdąży mrugnąć w stanie docelowym.
    var waitFonts = G.needsFonts
        && document.fonts && document.fonts.ready
        && typeof document.fonts.ready.then === 'function';

    if (waitFonts) {
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
