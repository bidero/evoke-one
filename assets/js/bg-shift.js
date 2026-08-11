/* Evoke ONE — Przenikanie tła przy scrollu (silnik)
 *
 * Jedna warstwa pod całą stroną przewija kolor od sekcji do sekcji. Sekcje
 * z atrybutem data-evk-bg oddają jej swój kolor i same robią się przezroczyste
 * — dzięki temu na ich granicy nie widać szwu z dwóch kolorów naraz.
 *
 * Kolor bierzemy z getComputedStyle, gdzie kolory globalne Bricks są już
 * rozwinięte do rgb(). Zmiana motywu zmienia te wartości, więc po zdarzeniu
 * dark mode'a trzeba je odczytać i zbudować oś czasu na nowo.
 *
 * Wymaga GSAP + ScrollTrigger; handle'e rejestruje includes/89-gsap.php.
 */
(function () {
  'use strict';

  var CFG      = window.evkBgShift || {};
  var LENGTH   = typeof CFG.length === 'number' ? CFG.length : 0.5;
  var SMOOTH   = typeof CFG.smooth === 'number' ? CFG.smooth : 0.3;
  /* Procent wysokości okna, na którym ZACZYNA się przejście. 100 = górna
     krawędź nadchodzącej sekcji dotyka dołu okna — wartość zahardkodowana
     do 1.53.0. Mniej znaczy „przełącz później, gdy sekcja będzie już wyżej". */
  var START    = typeof CFG.start === 'number' ? CFG.start : 100;

  var sections = [];
  var layer    = null;
  var triggers = [];
  var themeSig = null;
  var pending  = false;

  /** Wspólna polityka ruchu — patrz includes/anim/motion.php. */
  function reduced() {
    if (window.evkMotion && typeof window.evkMotion.reduced === 'function') {
      return window.evkMotion.reduced();
    }
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  /** Kolor „pusty" — sekcja bez własnego tła nie ma czego oddać warstwie. */
  function isBlank(c) {
    if (!c) return true;
    c = c.trim();
    if (c === 'transparent') return true;
    // rgba(...) z zerową alfą
    var m = /^rgba?\(([^)]+)\)$/.exec(c);
    if (!m) return false;
    var parts = m[1].split(',');
    return parts.length > 3 && parseFloat(parts[3]) === 0;
  }

  /**
   * Odczyt kolorów. Sekcje są w tym momencie przezroczyste (klasa handoff),
   * więc trzeba ją zdjąć na czas pomiaru — inaczej odczytalibyśmy własną
   * przezroczystość zamiast koloru ustawionego w Bricks.
   *
   * PRZEJŚCIA MUSZĄ BYĆ WYŁĄCZONE NA CZAS POMIARU. Moduł trybu ciemnego dokłada
   * `transition: background-color` między innymi na `section`, a getComputedStyle
   * zwraca wtedy wartość W TRAKCIE animacji, nie docelową — czyli kolor
   * poprzedniego motywu albo samą przezroczystość tuż po zdjęciu klasy.
   * Bez tego warstwa zostawała o jeden motyw w tyle i po powrocie do jasnego
   * trzymała ciemny kolor.
   *
   * Obie zmiany klas commitujemy jeszcze przy wyłączonych przejściach (stąd
   * wymuszony przeliczenie układu w środku), inaczej powrót do przezroczystości
   * animowałby się przez 0,4 s i widać byłoby mignięcie kolorem sekcji.
   */
  function readColors(list) {
    list.forEach(function (el) {
      el.classList.add('evk-bg-measure');
      el.classList.remove('evk-bg-handoff');
    });
    var out = list.map(function (el) { return getComputedStyle(el).backgroundColor; });

    list.forEach(function (el) { el.classList.add('evk-bg-handoff'); });
    void document.documentElement.offsetHeight;   // commit przy wyłączonych przejściach
    list.forEach(function (el) { el.classList.remove('evk-bg-measure'); });

    return out;
  }

  function themeSignature() {
    var h = document.documentElement;
    return (h.getAttribute('data-theme') || '') + '|' + (h.classList.contains('dark') ? 'dark' : '');
  }

  /**
   * Od którego procentu wysokości okna zaczyna się przejście DO tej sekcji.
   *
   * Wyzwalaczem pary jest sekcja NADCHODZĄCA, więc wartość czyta się wprost:
   * „przełącz tło, gdy TA sekcja dojdzie do X%". Pusty atrybut (tak wyglądał
   * do 1.53.0 i tak wygląda na wszystkich istniejących stronach) znaczy
   * „wartość globalna" — dlatego brak i pustka schodzą tą samą ścieżką.
   */
  function startPct(el) {
    var raw = el.getAttribute('data-evk-bg');
    if (raw === null || raw === '') return START;
    var n = parseFloat(raw);
    if (isNaN(n)) {
      console.warn('[EVK Tło] Nieprawidłowy początek przejścia w data-evk-bg:', raw, el);
      return START;
    }
    return clampPct(n);
  }

  /** Poza 0–200% ScrollTrigger dostaje punkt, którego nigdy nie minie. */
  function clampPct(n) {
    return Math.max(0, Math.min(200, n));
  }

  function killTriggers() {
    triggers.forEach(function (t) { t.kill(); });
    triggers = [];
  }

  function build() {
    killTriggers();

    var colors = readColors(sections);

    // Sekcja bez własnego tła (obraz, gradient, nic) nie ma czego oddać —
    // wypada z łańcucha zamiast wstawiać w niego przezroczystą dziurę.
    var usable = [];
    sections.forEach(function (el, i) {
      if (isBlank(colors[i])) {
        el.classList.remove('evk-bg-handoff');
        console.warn('[EVK Tło] Sekcja nie ma własnego koloru tła — pomijam.', el);
        return;
      }
      usable.push({ el: el, color: colors[i] });
    });

    if (usable.length < 2) {
      if (usable.length === 1) gsap.set(layer, { backgroundColor: usable[0].color });
      return;
    }

    gsap.set(layer, { backgroundColor: usable[0].color });

    for (var i = 0; i < usable.length - 1; i++) {
      var from = usable[i].color;
      var to   = usable[i + 1].color;
      var next = usable[i + 1].el;

      // Przejście trwa tyle widoku, ile mówi ustawienie „długość", i zaczyna
      // się tam, gdzie każe „początek" — globalny albo nadpisany na sekcji.
      // Przy początku 100 wzór daje dokładnie dawne (1 − length)·100, więc
      // strony bez nadpisania zachowują się co do piksela tak jak wcześniej.
      var begPct = startPct(next);
      var endPct = Math.round(clampPct(begPct - LENGTH * 100));

      if (reduced()) {
        // Bez przewijania koloru — przeskok w punkcie, w którym przejście
        // i tak by się kończyło. Przy domyślnych (początek 100, długość 0,5)
        // wypada to na 50%, czyli dokładnie tam, gdzie stało twarde
        // 'top center' — uogólnienie, nie przestawienie.
        triggers.push(ScrollTrigger.create({
          trigger: next,
          start: 'top ' + endPct + '%',
          onEnter:     (function (c) { return function () { gsap.set(layer, { backgroundColor: c }); }; })(to),
          onLeaveBack: (function (c) { return function () { gsap.set(layer, { backgroundColor: c }); }; })(from),
        }));
        continue;
      }

      var tl = gsap.fromTo(layer,
        { backgroundColor: from },
        {
          backgroundColor: to,
          ease: 'none',
          // Bez tego GSAP nałożyłby stan „from" od razu przy budowie i ostatnia
          // zbudowana para wygrałaby kolor startowy dla całej strony.
          immediateRender: false,
          scrollTrigger: {
            trigger: next,
            start:   'top ' + begPct + '%',
            end:     'top ' + endPct + '%',
            scrub:   SMOOTH > 0 ? SMOOTH : true,
          },
        });
      if (tl.scrollTrigger) triggers.push(tl.scrollTrigger);
    }

    ScrollTrigger.refresh();
  }

  function rebuild() {
    pending = false;
    if (!sections.length) return;
    build();
  }

  function scheduleRebuild() {
    if (pending) return;
    pending = true;
    requestAnimationFrame(rebuild);
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

    // Uniwersalnie: dowolny przełącznik zmieniający data-theme albo klasę .dark.
    if (window.MutationObserver) {
      new MutationObserver(onThemeMaybeChanged).observe(document.documentElement, {
        attributes: true,
        attributeFilter: ['data-theme', 'class'],
      });
    }

    if (window.matchMedia) {
      var mq = window.matchMedia('(prefers-color-scheme: dark)');
      if (mq.addEventListener) mq.addEventListener('change', scheduleRebuild);
    }
  }

  function init() {
    sections = Array.prototype.slice.call(document.querySelectorAll('[data-evk-bg]'));
    if (!sections.length) return;

    layer = document.createElement('div');
    layer.className = 'evk-bg-layer';
    layer.setAttribute('aria-hidden', 'true');
    document.body.insertBefore(layer, document.body.firstChild);

    sections.forEach(function (el) { el.classList.add('evk-bg-handoff'); });

    build();
    themeSig = themeSignature();
    watchTheme();
  }

  function waitForGSAP(tries) {
    tries = tries || 0;
    if (window.gsap && window.ScrollTrigger) {
      gsap.registerPlugin(ScrollTrigger);
      init();
    } else if (tries < 50) {
      setTimeout(function () { waitForGSAP(tries + 1); }, 100);
    } else {
      console.warn('[EVK Tło] Brak GSAP/ScrollTrigger.');
    }
  }

  function start() {
    // W builderze Bricks (bricksIsFrontend === false) efekt tylko przeszkadza.
    var frontend = typeof bricksIsFrontend === 'undefined' ? true : !!bricksIsFrontend;
    if (frontend) waitForGSAP();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
