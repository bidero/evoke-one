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

    // Efekty tekstowe nie mają i nie mogą mieć from/to: docelowym tekstem jest
    // treść, którą element ma już w sobie, a tablica presetów w PHP nie
    // przeniesie funkcji, która by ją odczytała. Varsy składa textFxVars().
    var textFx = pick(attr.textFx, lib.textFx, pre.textFx, '');

    // Efekty wskaźnika też nie są tweenem od–do. 'to' niosą jako stan
    // spoczynku dla ścieżki redukcji ruchu — i to samo 'to' przepuszcza je
    // przez bramkę niżej, więc nie ma tu dla nich osobnego warunku.
    // Że każdy taki preset stan spoczynku ma, pilnuje tests/presets.test.js.
    var pointer = pick(attr.pointer, lib.pointer, pre.pointer, '');

    if (!from && !to && !textFx) return null;

    return {
      from:     from,
      to:       to,
      textFx:   textFx,
      words:    attr.words || lib.words || null,
      pointer:  pointer,
      strength: num(pick(attr.strength, lib.strength, pre.strength), 0.35),
      split:    pick(attr.split, lib.split, pre.split, ''),
      mask:     pick(attr.mask, lib.mask, pre.mask, ''),
      targets:  pick(attr.targets, lib.targets, 'self'),
      selector: pick(attr.selector, lib.selector, ''),
      pin:      !!pick(attr.pin, lib.pin, false),
      trigger:  pick(attr.trigger, lib.trigger, 'viewport'),
      easing:   pick(attr.easing, lib.easing, pre.easing, 'power2.out'),
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

  // ── Efekty tekstowe ────────────────────────────────────────────────────

  /**
   * Docelowy tekst jest treścią elementu, więc trzeba go zapamiętać, ZANIM
   * wtyczka zacznie go nadpisywać. Zapis na węźle, nie w zmiennej: przy
   * autoSplit i przy ponownej inicjalizacji ta sama funkcja dostaje te same
   * węzły i nie może wtedy zapamiętać połowy animacji.
   */
  function rememberText(targets) {
    targets.forEach(function (el) {
      if (el._evkText === undefined) el._evkText = el.textContent;
    });
  }

  /**
   * Varsy dla wtyczek tekstowych. Wartość jest FUNKCJĄ — GSAP rozwiązuje ją
   * osobno dla każdego celu, dzięki czemu jedna oś czasu obsługuje wiele
   * elementów z różną treścią. Przy scramble funkcją jest cały obiekt
   * konfiguracji, bo rozwiązywanie sięga tylko poziomu właściwości.
   */
  function textFxVars(targets, cfg) {
    rememberText(targets);

    if (cfg.textFx === 'scramble') {
      return {
        scrambleText: function (i, el) {
          return { text: el._evkText, chars: 'upperCase', speed: 0.6 };
        },
      };
    }
    return { text: function (i, el) { return el._evkText; } };
  }

  /** Maszyna do pisania zaczyna od pustego pola; scramble miesza w miejscu. */
  function textFxFrom(cfg) {
    return cfg.textFx === 'type' ? { text: '' } : null;
  }

  /**
   * Zmieniające się słowa — jedyny efekt, który nie jest pojedynczym tweenem,
   * tylko pętlą. Przerwa między słowami jest stała: pole „Czas" steruje samym
   * przejściem, a doszywanie drugiej liczby do panelu byłoby nieproporcjonalne
   * do zysku.
   */
  var WORD_HOLD = 1.4;

  function attachWords(el, targets, cfg) {
    var words = cfg.words || [];
    if (words.length < 2) {
      console.warn('[EVK Animator] Preset „zmieniające się słowa" potrzebuje co najmniej dwóch słów '
        + '— uzupełnij pole „Słowa" w wierszu biblioteki.', el);
      return null;
    }

    rememberText(targets);
    var tl = gsap.timeline({ repeat: -1, delay: cfg.delay });
    words.forEach(function (word) {
      tl.to(targets, { duration: cfg.duration, ease: cfg.easing, text: word });
      tl.to(targets, { duration: WORD_HOLD });   // tween bez właściwości = postój
    });
    return tl;
  }

  // ── Budowa osi czasu ───────────────────────────────────────────────────

  /**
   * Varsy tweenu w jednym miejscu. Wyzwalacze różnią się tylko tym, co
   * dokładają na wierzchu (scrub kasuje czas i krzywą, kolejka startowa podaje
   * pozycję), a efekty tekstowe mają działać przy każdym z nich — rozsypane po
   * czterech funkcjach obsłużyłyby tylko ten wyzwalacz, o którym ktoś pamiętał.
   */
  function tweenVars(targets, cfg, override) {
    var vars = Object.assign({}, cfg.to, {
      duration: cfg.duration,
      ease:     cfg.easing,
    }, override || {});
    if (cfg.stagger > 0) vars.stagger = cfg.stagger;
    if (cfg.textFx) Object.assign(vars, textFxVars(targets, cfg));
    return vars;
  }

  /** Stan wyjściowy: własny z konfiguracji albo dorobiony przez efekt tekstowy. */
  function startVars(cfg) {
    if (cfg.from) return Object.assign({}, cfg.from);
    return cfg.textFx ? textFxFrom(cfg) : null;
  }

  function buildTimeline(targets, cfg, paused) {
    var tl    = gsap.timeline({ paused: !!paused });
    var vars  = tweenVars(targets, cfg);
    var start = startVars(cfg);

    if (start) tl.fromTo(targets, start, vars);
    else       tl.to(targets, vars);
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
    var vars  = tweenVars(targets, cfg);
    var start = startVars(cfg);

    var tl = gsap.timeline({
      delay: cfg.delay,
      scrollTrigger: {
        trigger:       el,
        start:         cfg.start,
        once:          !cfg.repeat,
        toggleActions: cfg.repeat ? 'play reverse play reverse' : 'play none none none',
      },
    });

    if (start) tl.fromTo(targets, start, vars);
    else       tl.to(targets, vars);
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
    // Przy scrubie o czasie decyduje scroll, nie pole „Czas" — stąd krzywa
    // liniowa i usunięty duration (klucz z wartością undefined nie jest tym
    // samym co brak klucza dla części wtyczek).
    var vars = tweenVars(targets, cfg, { ease: 'none' });
    delete vars.duration;
    var start = startVars(cfg);
    if (start) tl.fromTo(targets, start, vars);
    else       tl.to(targets, vars);
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

  // ── Efekty wskaźnika ───────────────────────────────────────────────────

  /** Maksymalny przechył przy sile 1. Wyżej robi się z tego karuzela. */
  var TILT_MAX_DEG = 12;

  /**
   * Śledzenie kursora zamiast tweenu od–do. quickTo zamiast gsap.to na każdym
   * ruchu myszy: tworzy JEDEN tween i tylko przestawia mu cel, więc pod
   * pointermove nie powstają setki obiektów na sekundę.
   *
   * Wychylenie liczymy w połówkach pudełka (−1…1 od środka do krawędzi), więc
   * ta sama siła znaczy to samo dla przycisku i dla karty na pół ekranu.
   */
  function attachPointer(el, targets, cfg) {
    // Na dotyku nie ma czego śledzić: pointermove przychodzi dopiero przy
    // przeciąganiu palcem, więc element odskakiwałby przy próbie przewinięcia.
    if (window.matchMedia && !window.matchMedia('(hover: hover)').matches) return null;

    var tilt = cfg.pointer === 'tilt';
    var ease = { duration: 0.5, ease: 'power3' };

    if (tilt) gsap.set(targets, { transformPerspective: 800, transformOrigin: 'center center' });

    var setA = gsap.quickTo(targets, tilt ? 'rotationY' : 'x', ease);
    var setB = gsap.quickTo(targets, tilt ? 'rotationX' : 'y', ease);

    var ac   = new AbortController();
    var opts = { signal: ac.signal };

    el.addEventListener('pointermove', function (e) {
      var r  = el.getBoundingClientRect();
      if (!r.width || !r.height) return;
      var dx = (e.clientX - (r.left + r.width  / 2)) / (r.width  / 2);
      var dy = (e.clientY - (r.top  + r.height / 2)) / (r.height / 2);

      if (tilt) {
        // Znak przy osi X odwrócony: kursor u góry ma odchylić górną krawędź OD
        // patrzącego, a nie do niego.
        setA(dx * TILT_MAX_DEG * cfg.strength);
        setB(-dy * TILT_MAX_DEG * cfg.strength);
      } else {
        setA(dx * (r.width  / 2) * cfg.strength);
        setB(dy * (r.height / 2) * cfg.strength);
      }
    }, opts);

    var rest = function () { setA(0); setB(0); };
    el.addEventListener('pointerleave', rest, opts);
    // Wskaźnik potrafi zniknąć bez pointerleave (przewinięcie, zmiana karty).
    el.addEventListener('pointercancel', rest, opts);

    el._evkAnimAbort = ac;
    return null;   // brak osi czasu — nie ma czym sterować z zewnątrz
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

      var vars  = tweenVars(item.targets, cfg);
      var start = startVars(cfg);

      // LICZBA = pozycja bezwzględna względem początku osi. Wcześniej było tu
      // '+=' + delay, które w GSAP liczy się od KOŃCA dotychczasowej osi —
      // opóźnienia sumowały się z czasami trwania poprzednich animacji.
      // Ustawione 0 / 0,3 / 0,6 dawało starty 0 / 1,1 / 2,5.
      var pos = stepStart + cfg.delay;
      if (start) master.fromTo(item.targets, start, vars, pos);
      else       master.to(item.targets, vars, pos);
    });
    loadQueue = [];
  }

  // ── Init ───────────────────────────────────────────────────────────────

  /** Podpina animację pod wybrany wyzwalacz i ZWRACA oś czasu (albo null dla load). */
  function buildAnimation(el, targets, cfg) {
    // Dwa efekty nie przechodzą przez żaden wyzwalacz, bo nie są tweenem
    // od–do: pętla po słowach kręci się od razu i w kółko, a śledzenie
    // kursora czeka na ruch wskaźnika. Pole „Wyzwalacz" nic dla nich nie znaczy.
    if (cfg.pointer)              return attachPointer(el, targets, cfg);
    if (cfg.textFx === 'words')   return attachWords(el, targets, cfg);

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
      // Wyjątek: wyzwalacze interaktywne. Tam stanem spoczynku jest 'from',
      // a 'to' to stan NAJECHANIA — nałożony na stałe zostawiłby przycisk
      // trwale uniesiony i podświetlony. Nie ma też wejścia do dokończenia,
      // więc najbezpieczniej nie ruszać elementu wcale: zostaje taki, jak
      // wyrenderował go CSS, czyli na pewno widoczny.
      if (cfg.trigger !== 'hover' && cfg.trigger !== 'click' && cfg.to) {
        gsap.set(resolveTargets(el, cfg), cfg.to);
      }
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
      // Wtyczki opcjonalne — dociągane tylko gdy któryś wiersz biblioteki
      // ich potrzebuje (patrz enqueue_assets() w includes/anim/animator.php).
      if (window.SplitText)          gsap.registerPlugin(SplitText);
      if (window.TextPlugin)         gsap.registerPlugin(TextPlugin);
      if (window.ScrambleTextPlugin) gsap.registerPlugin(ScrambleTextPlugin);
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
