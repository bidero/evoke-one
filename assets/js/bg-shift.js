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
  /* Dwa kolory, spośród których automat wybiera kolor liter dla sekcji bez
     własnego. Patrz pickText(). */
  var T_LIGHT  = CFG.textLight || '#ffffff';
  var T_DARK   = CFG.textDark  || '#111111';

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
   * Rozwinięcie dowolnego zapisu koloru do `rgb()`.
   *
   * Kontrolka koloru Bricks potrafi przysłać heks, `rgba(...)` albo `var(--marka)`
   * (kolor globalny). GSAP umie interpolować tylko konkretne wartości, a `var()`
   * konkretną wartością nie jest — więc przepuszczamy je przez warstwę
   * i odczytujemy z powrotem. Warstwa nadaje się do tego lepiej niż nowy element:
   * już istnieje, siedzi w `<body>`, więc dziedziczy te same zmienne CSS co
   * sekcje, i nic na niej nie widać (jest pusta i `aria-hidden`).
   */
  function resolveColor(raw) {
    if (!raw) return '';
    var keep = layer.style.color;
    layer.style.color = '';
    layer.style.color = raw;
    var out = getComputedStyle(layer).color;
    layer.style.color = keep;
    return out;
  }

  /** Składowa luminancji względnej wg sRGB (WCAG). */
  function channel(v) {
    v /= 255;
    return v <= 0.04045 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  }

  function luminance(color) {
    var m = /rgba?\(([^)]+)\)/.exec(color || '');
    if (!m) return 0;
    var p = m[1].split(',');
    return 0.2126 * channel(parseFloat(p[0]))
         + 0.7152 * channel(parseFloat(p[1]))
         + 0.0722 * channel(parseFloat(p[2]));
  }

  function contrast(a, b) {
    var la = luminance(a), lb = luminance(b);
    var hi = Math.max(la, lb), lo = Math.min(la, lb);
    return (hi + 0.05) / (lo + 0.05);
  }

  /**
   * Automat: który z dwóch kolorów globalnych czyta się lepiej na tym tle.
   *
   * Wybór po KONTRAŚCIE, nie po progu jasności. Próg (np. „luminancja > 0,5 →
   * ciemne litery") zakłada, że oba kolory są skrajne; przy parze w rodzaju
   * kremowy/grafitowy albo przy tle o pośredniej jasności potrafi wskazać ten
   * gorszy. Porównanie kontrastu odpowiada wprost na pytanie, które zadajemy.
   */
  function pickText(bg) {
    var light = resolveColor(T_LIGHT);
    var dark  = resolveColor(T_DARK);
    return contrast(bg, light) >= contrast(bg, dark) ? light : dark;
  }

  /* Znacznik „kolor liter jest właśnie przewijany". Patrz setText(). */
  var SCRUB_CLASS = 'evk-bg-scrub';
  var scrubTimer  = null;

  /**
   * Zapis koloru liter — jedna wartość na cały dokument, zasięg robi CSS.
   *
   * Razem z wartością nakładamy na <html> znacznik, na czas którego arkusz
   * WYŁĄCZA przejścia CSS w sekcjach biorących udział w efekcie. Bez tego cudze
   * `transition: color` zjada całą płynność: piszemy nową wartość CO KLATKĘ,
   * a każdy zapis RESTARTUJE tamto przejście od bieżącego koloru. Tekst nie
   * dogania celu przez cały czas przewijania i dochodzi do niego dopiero po
   * nim — czyli wygląda, jakby zmiana koloru nie działała.
   *
   * Zbieg nie jest teoretyczny, tylko domyślny: moduł trybu ciemnego tej
   * wtyczki daje `section` przejście na `color`, a `.brxe-text`
   * i `.brxe-heading` — sekundowe. Zmierzone na potomku z takim przejściem:
   * zmienna `rgb(0, 200, 0)`, sekcja `rgb(0, 200, 0)`, POTOMEK
   * `rgb(117, 225, 117)`. Potomek jest tu ważniejszy od sekcji, bo przy
   * zasięgu przez dziedziczenie to jemu kolor się zmienia.
   *
   * Znacznik schodzi po chwili od OSTATNIEGO zapisu, a nie po każdym — stąd
   * `clearTimeout`. Bez niego znacznik gaśnie i wraca kilkadziesiąt razy na
   * sekundę przewijania (zmierzone: 34 zejścia na 700 ms), a każde takie
   * zejście to i okno, w którym cudze przejście zdąży wystartować, i pełne
   * unieważnienie stylu poddrzewa sekcji. Gdy schodzi na dobre, kolor jest już
   * docelowy — więc nie ma czego animować i nic nie przeskakuje.
   */
  function setText(color) {
    var html = document.documentElement;
    html.style.setProperty('--evk-bg-text', color);
    html.classList.add(SCRUB_CLASS);
    clearTimeout(scrubTimer);
    scrubTimer = setTimeout(function () { html.classList.remove(SCRUB_CLASS); }, 120);
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
      /* Kolor liter: wskazany kontrolką przy sekcji, a gdy jej nie wypełniono —
         dobrany z jasności tła. Pusty atrybut znaczy „automat" tak samo, jak
         pusty `data-evk-bg` znaczy „wartość globalna" — jedna konwencja dla
         obu pól. */
      var raw = el.getAttribute('data-evk-bg-text');
      usable.push({
        el:    el,
        color: colors[i],
        text:  raw ? resolveColor(raw) : pickText(colors[i]),
      });
    });

    if (usable.length < 2) {
      if (usable.length === 1) {
        gsap.set(layer, { backgroundColor: usable[0].color, color: usable[0].text });
        setText(usable[0].text);
      }
      return;
    }

    gsap.set(layer, { backgroundColor: usable[0].color, color: usable[0].text });
    setText(usable[0].text);

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
      var textFrom = usable[i].text;
      var textTo   = usable[i + 1].text;

      if (reduced()) {
        // Bez przewijania koloru — przeskok w punkcie, w którym przejście
        // i tak by się kończyło. Przy domyślnych (początek 100, długość 0,5)
        // wypada to na 50%, czyli dokładnie tam, gdzie stało twarde
        // 'top center' — uogólnienie, nie przestawienie.
        // Litery przeskakują razem z tłem — w tym samym punkcie i tym samym
        // wywołaniem. Rozdzielenie ich dałoby moment, w którym stary kolor
        // liter leży już na nowym tle, czyli dokładnie to, przed czym ta
        // funkcja ma chronić.
        var jump = function (bg, txt) {
          return function () {
            gsap.set(layer, { backgroundColor: bg, color: txt });
            setText(txt);
          };
        };
        triggers.push(ScrollTrigger.create({
          trigger: next,
          start: 'top ' + endPct + '%',
          onEnter:     jump(to, textTo),
          onLeaveBack: jump(from, textFrom),
        }));
        continue;
      }

      /* JEDEN tween, dwie właściwości — i to jest cała gwarancja, że kolor
         liter nie rozjedzie się z tłem. Osobny tween znaczyłby drugie okno
         i drugą krzywą, które da się przestawić niezależnie; tu nie ma czego
         przestawiać. `color` warstwy nic nie maluje (jest pusta), służy
         wyłącznie za interpolator: GSAP zapisuje go inline, a onUpdate
         przepisuje do zmiennej. Czytamy `style.color`, nie getComputedStyle —
         to odczyt bez przeliczania układu, a leci co klatkę. */
      var tl = gsap.fromTo(layer,
        { backgroundColor: from, color: textFrom },
        {
          backgroundColor: to,
          color: textTo,
          onUpdate: function () { setText(layer.style.color); },
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
