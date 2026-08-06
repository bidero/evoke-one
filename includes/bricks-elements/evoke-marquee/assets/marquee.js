(function () {
  'use strict';

  // ── GSAP horizontalLoop helper (via gsap.com/docs) ──────────────────────
  function horizontalLoop(items, config) {
    items = gsap.utils.toArray(items);
    config = config || {};
    var tl = gsap.timeline({
      repeat          : config.repeat,
      paused          : config.paused,
      defaults        : { ease: 'none' },
      onReverseComplete: function() { tl.totalTime(tl.rawTime() + tl.duration() * 100); },
    });
    var length         = items.length;
    var startX         = items[0].offsetLeft;
    var times          = [];
    var widths         = [];
    var xPercents      = [];
    var curIndex       = 0;
    var pixelsPerSecond = (config.speed || 1) * 100;
    var snap           = config.snap === false ? function(v) { return v; } : gsap.utils.snap(config.snap || 1);
    var totalWidth, curX, distanceToStart, distanceToLoop, item, i;

    gsap.set(items, {
      xPercent: function(i, el) {
        var w = widths[i] = parseFloat(gsap.getProperty(el, 'width', 'px'));
        xPercents[i] = snap(parseFloat(gsap.getProperty(el, 'x', 'px')) / w * 100 + gsap.getProperty(el, 'xPercent'));
        return xPercents[i];
      },
    });
    gsap.set(items, { x: 0 });

    totalWidth = items[length - 1].offsetLeft
      + xPercents[length - 1] / 100 * widths[length - 1]
      - startX
      + items[length - 1].offsetWidth * gsap.getProperty(items[length - 1], 'scaleX')
      + (parseFloat(config.paddingRight) || 0);

    for (i = 0; i < length; i++) {
      item             = items[i];
      curX             = xPercents[i] / 100 * widths[i];
      distanceToStart  = item.offsetLeft + curX - startX;
      distanceToLoop   = distanceToStart + widths[i] * gsap.getProperty(item, 'scaleX');

      tl.to(item, {
        xPercent : snap((curX - distanceToLoop) / widths[i] * 100),
        duration : distanceToLoop / pixelsPerSecond,
      }, 0)
      .fromTo(item, {
        xPercent: snap((curX - distanceToLoop + totalWidth) / widths[i] * 100),
      }, {
        xPercent       : xPercents[i],
        duration       : (curX - distanceToLoop + totalWidth - curX) / pixelsPerSecond,
        immediateRender: false,
      }, distanceToLoop / pixelsPerSecond)
      .add('label' + i, distanceToStart / pixelsPerSecond);

      times[i] = distanceToStart / pixelsPerSecond;
    }

    tl.progress(1, true).progress(0, true);
    if (config.reversed) { tl.vars.onReverseComplete(); tl.reverse(); }
    return tl;
  }
  // ────────────────────────────────────────────────────────────────────────

  function loadScript(src, cb) {
    if (document.querySelector('script[src="' + src + '"]')) { cb(); return; }
    var s = document.createElement('script');
    s.src = src; s.onload = cb;
    document.head.appendChild(s);
  }

  /** Wspólna polityka ruchu — patrz includes/anim/motion.php. */
  function evkMarqueeReduced() {
    if (window.evkMotion && typeof window.evkMotion.reduced === 'function') {
      return window.evkMotion.reduced();
    }
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  function initMarquee(container) {
    if (container.dataset.evkInit) return;
    container.dataset.evkInit = '1';

    var raw = container.dataset.evkMarquee;
    if (!raw) return;
    var cfg;
    try { cfg = JSON.parse(raw); } catch (e) { return; }

    var CFG = {
      baseSpeed        : cfg.baseSpeed        || 80,
      divisor          : cfg.divisor          || 300,
      maxScale         : cfg.maxScale         || 12,
      slowDown         : cfg.slowDown         || 2,
      direction        : cfg.direction        || 'left',
      reverseOnScrollUp: cfg.reverseOnScrollUp || false,
      // Brak klucza = element zapisany przed 1.36.0, gdy pauza była zaszyta
      // na sztywno z zapasem 200 px. Domyślne muszą to odtwarzać.
      pauseOffscreen   : cfg.pauseOffscreen === undefined ? true : !!cfg.pauseOffscreen,
      pauseOffset      : cfg.pauseOffset === undefined ? 200 : Math.max(0, cfg.pauseOffset),
    };

    // Zbierz wszystkie .evk-marquee-item ze wszystkich tracków
    var items = gsap.utils.toArray(container.querySelectorAll('.evk-marquee-item'));
    if (!items.length) return;

    // Redukcja ruchu: treść zostaje na miejscu w zwykłym przepływie, pętla nie
    // rusza i Observer nie powstaje. Nic nie znika — marquee bez ruchu to po
    // prostu rząd elementów. Wspólna polityka: includes/anim/motion.php.
    if (evkMarqueeReduced()) return;

    var gap = parseFloat(getComputedStyle(container.querySelector('.evk-marquee-track')).gap) || 80;

    var tl = horizontalLoop(items, {
      repeat      : -1,
      speed       : CFG.baseSpeed / 100,
      reversed    : CFG.direction === 'right',
      paddingRight: gap,
    });

    // Scroll velocity przez Observer
    gsap.registerPlugin(Observer);
    var baseTimeScale = CFG.direction === 'right' ? -1 : 1;
    tl.timeScale(baseTimeScale);

    // Widoczność steruje NIE TYLKO pętlą, ale i reakcją na przewijanie.
    // Sama wstrzymana oś czasu kosztuje niewiele — prawdziwe marnowanie
    // procesora siedziało niżej: Observer łapie KAŻDE przewinięcie strony
    // i przy każdym zabijał tweeny oraz tworzył nowy na timeScale, także gdy
    // marquee stało dawno poza kadrem. Przy kilku marquee na długiej stronie
    // robiła się z tego stała mielonka przez cały scroll.
    var visible = true;

    Observer.create({
      target   : window,
      type     : 'wheel,touch,scroll',
      onChangeY: function(self) {
        if (!visible) return;

        var velocity   = Math.abs(self.velocityY || self.deltaY * 10);
        var multiplier = Math.min(1 + velocity / (CFG.divisor * 10), CFG.maxScale);

        // Czy odwracamy kierunek przy scrollu w górę?
        var scrollingUp  = self.deltaY < 0;
        var targetScale;
        if (CFG.reverseOnScrollUp && scrollingUp) {
          targetScale = -baseTimeScale * multiplier;
        } else {
          targetScale = baseTimeScale * multiplier;
        }

        gsap.killTweensOf(tl, 'timeScale');
        gsap.to(tl, { timeScale: targetScale, duration: 0.2, ease: 'power2.out', overwrite: true,
          onComplete: function() {
            gsap.to(tl, { timeScale: baseTimeScale, duration: CFG.slowDown, ease: 'power3.out' });
          }
        });
      },
    });

    // Pauza poza kadrem. Wyłączona = pętla i reakcja na scroll działają zawsze,
    // co ma sens tylko wtedy, gdy dwa marquee mają zostać ze sobą zsynchronizowane.
    if (!CFG.pauseOffscreen) return;

    var pad = CFG.pauseOffset;
    var on  = function() { visible = true;  tl.play(); };
    var off = function() {
      visible = false;
      // Tweeny rozpędzające timeScale mogłyby dokończyć się po pauzie i zostawić
      // oś czasu rozpędzoną na powrót do kadru — stąd jawne zabicie i reset.
      gsap.killTweensOf(tl, 'timeScale');
      tl.timeScale(baseTimeScale);
      tl.pause();
    };

    var st = ScrollTrigger.create({
      trigger  : container,
      start    : 'top bottom+=' + pad,
      end      : 'bottom top-=' + pad,
      onToggle : function (self) { if (self.isActive) on(); else off(); },
    });

    // Stan początkowy trzeba nałożyć samemu. ScrollTrigger zgłasza dopiero
    // ZMIANĘ stanu, a marquee stojące daleko pod ekranem nigdy w kadr nie
    // wjechało — więc nie dostawało też sygnału wyjścia i jechało od załadowania
    // strony aż do pierwszego zbliżenia. Przy kilku marquee na długiej stronie
    // wszystkie mieliły w tle, zanim ktokolwiek je zobaczył.
    if (st.isActive) on(); else off();
  }

  function boot() {
    var GSAP_JS = 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.15.0/gsap.min.js';
    var ST_JS   = 'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.15.0/ScrollTrigger.min.js';

    function run() {
      gsap.registerPlugin(ScrollTrigger);
      document.querySelectorAll('.evk-marquee-container').forEach(initMarquee);
    }

    if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
      run();
    } else if (typeof gsap !== 'undefined') {
      loadScript(ST_JS, run);
    } else {
      loadScript(GSAP_JS, function() { loadScript(ST_JS, run); });
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
