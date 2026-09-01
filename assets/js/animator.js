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

  /* ZNAK, ŻE SILNIK DOJECHAŁ — czyta go bezpiecznik zasłony w <head>
     (render_preveil() w includes/anim/animator.php).
 
     Bezpiecznik odsłania treść, gdy silnik nie przyszedł wcale. Gdyby zrobił to
     także wtedy, gdy silnik JUŻ się wczytuje, pokazałby na chwilę treść, którą
     ten za moment schowa do stanu początkowego — czyli dokładnie ten błysk,
     przeciw któremu zasłona istnieje. Zmierzone na dławionej sieci: bezpiecznik
     wypadał 200 ms przed końcem pierwszego przebiegu.

     Stawiane PRZED czymkolwiek innym, żeby liczyło się też wtedy, gdy dalsza
     część pliku wyłoży się na wyjątku. */
  window.evkAnimatorObecny = true;

  var G = window.evkAnimator || {};
  var LIBRARY = G.library || {};
  var PRESETS = G.presets || {};

  var loadQueue = [];
  var loadQueueRan = false;
  /** Kiedy silnik zdjął zasłonę, w ms od startu nawigacji. Null = nie zdjął jej. */
  var zaslonaZdjeta = null;

  /* Rozbicie czasu startu do `?evk-anim-debug=1`. Bez niego przy zgłoszeniu
     „elementy pojawiają się z opóźnieniem" nie da się rozstrzygnąć, czy czas
     schodzi na dojazd skryptów, czy na pracę silnika — a to zupełnie inne
     naprawy. `silnikStart` bierzemy w chwili wykonania pliku. */
  var silnikStart = Math.round(performance.now());
  var fazaJeden = null;
  var fazaDwa = null;
  /* Rozbicie samej fazy 1 na dwie składowe. Bez nich „faza 1 kosztuje 800 ms"
     nie mówi, co skracać: czytanie konfiguracji czy nakładanie stanów. */
  var czasKonfiguracji = 0;
  var czasStanow = 0;

  /* CZEKANIA NA FONTY JUŻ NIE MA — i to jest zmiana z 1.126.0, nie przeoczenie.
   *
   * Do 1.125.0 konfiguracje z podziałem tekstu odkładały się do
   * `document.fonts.ready`, bo metryki fontu decydują o łamaniu LINII.
   * Zmierzone na lustrze przy dławieniu 6×: fonty były gotowe o 1932 ms, czyli
   * w środku pierwszego przebiegu — więc odłożenie nie tyle czekało, ile
   * rozbijało pracę na DWA pełne przebiegi po wszystkich elementach. Pierwsze
   * kawałki podziału pojawiały się o 6137 ms.
   *
   * Robi to za nas SplitText: przy `autoSplit` dopina się do zdarzenia
   * `loadingdone` i przedziela tekst sam — i tylko przy podziale na linie,
   * czyli dokładnie tam, gdzie metryki mają znaczenie (widać to w źródle
   * wtyczki: `D && y && o && D.addEventListener("loadingdone", this._split)`).
   * Nasze czekanie było więc drugą kopią jego mechanizmu.
   *
   * Cena: wejście po liniach, które zdąży zagrać PRZED wczytaniem fontów,
   * zagra ponownie po przedzieleniu. Na wolnym łączu to się zdarzy — ale
   * sekunda niewidocznej treści kosztuje więcej niż powtórzone wejście. */

  /**
   * Znacznik „ten element jeszcze czeka — nie pokazuj go”.
   *
   * Zasłona `evk-veil` jest jedna na cały dokument i schodzi po TANIEJ fazie
   * pierwszego przebiegu (patrz `przygotuj`). Element z podziałem tekstu albo
   * z efektem tekstowym nie ma wtedy czego nałożyć — jego stan początkowy leży
   * na kawałkach, których jeszcze nie ma. Pokazany w międzyczasie mrugnąłby
   * treścią i skoczył do stanu początkowego, czyli dokładnie ten błysk,
   * przeciw któremu zasłona powstała. Znacznik schodzi po zbudowaniu, w fazie
   * drugiej.
   *
   * Atrybut, nie klasa: nazwa klasy z ciągiem „evk-anim-” wpadłaby w selektor,
   * którym silnik zbiera elementy (patrz komentarz przy render_preveil()).
   */
  var ATRYBUT_CZEKA = 'data-evk-anim-czeka';

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

  /**
   * Czy animacja mieści się w zadanym zakresie szerokości okna.
   *
   * Granice są WŁĄCZNE i podane w pikselach: `minW` znaczy „nie graj węziej",
   * `maxW` — „nie graj szerzej". Zero i brak są tym samym, czyli brakiem
   * granicy z tej strony.
   *
   * Pytamy `matchMedia`, nie `innerWidth`, bo o tym samym decyduje potem
   * nasłuch zmiany progu — jedno źródło prawdy zamiast dwóch, które umieją
   * się rozjechać o szerokość paska przewijania.
   */
  function wZakresie(cfg) {
    if (!window.matchMedia) return true;
    if (cfg.minW && !window.matchMedia('(min-width: ' + cfg.minW + 'px)').matches) return false;
    if (cfg.maxW && !window.matchMedia('(max-width: ' + cfg.maxW + 'px)').matches) return false;
    return true;
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

  /* Węzły, które silnik tworzy SAM. To nie są slugi biblioteki i nie wolno ich
     za takie brać — inaczej każdy zgłasza „brak animacji w bibliotece".
     
     Wzorzec, a nie lista trzech nazw, bo lista już raz się rozjechała: kawałki
     po podziale (`line`, `word`, `char`) były na niej, ale nie były MASKI, które
     dokłada opcja `mask` SplitTextu (`line-mask` i spółka), ani KLONY podmiany
     treści (`swap-klon`, od 1.92.0). Póki initAll() biegł raz na życie strony,
     nie było tego widać — dopiero zakres szerokości z 1.93.0 zaczął go wołać
     ponownie i wysypał lawinę ostrzeżeń przy każdej zmianie progu.
     
     Nowy efekt, który coś generuje, ma dopisać się TUTAJ. */
  var WEZEL_SILNIKA = /^(?:line|word|char)(?:-mask)?$|^swap-klon$|^host$/;

  /** Co Animator uważa za swoją robotę — jeden zapis dla `initAll()`
      i dla obserwatora podmian, żeby nie rozjechały się przy poprawce. */
  var SELEKTOR_ANIM = '[class*="evk-anim-"], [data-evk-anim]';


  /**
   * Czy element jest wyłącznie WYTWOREM silnika — maską, kawałkiem albo klonem.
   *
   * Taki węzeł nie dostanie konfiguracji nigdy: stworzyliśmy go sami i wiemy,
   * co w nim jest. To odróżnia go od elementu bez konfiguracji, na który silnik
   * czeka (treść z AJAX-a może dostać atrybut później) — i dlatego wolno go
   * pominąć od razu, zamiast wracać do niego przy każdej zmianie progu.
   */
  function tylkoWezelSilnika(el) {
    if (el.hasAttribute('data-evk-anim')) return false;
    var maNasze = false;
    for (var i = 0; i < el.classList.length; i++) {
      var c = el.classList[i];
      if (c.indexOf('evk-anim-') !== 0 || c.length <= 9) continue;
      maNasze = true;
      if (!WEZEL_SILNIKA.test(c.slice(9))) return false;
    }
    return maNasze;
  }

  /** WSZYSTKIE slugi z klas, nie pierwszy: element może nieść kilka animacji. */
  function slugsFromClass(el) {
    var out = [];
    for (var i = 0; i < el.classList.length; i++) {
      var c = el.classList[i];
      if (c.indexOf('evk-anim-') !== 0 || c.length <= 9) continue;
      var slug = c.slice(9);
      if (WEZEL_SILNIKA.test(slug)) continue;
      out.push(slug);
    }
    return out;
  }

  /**
   * Konfiguracje z atrybutu — ZAWSZE tablica, także dla jednej.
   *
   * Trzy formaty, rozróżniane pierwszym znakiem: tablica JSON (wiele animacji),
   * obiekt JSON (jedna) i goły slug (skrót). Dwa ostatnie są zapisane na
   * istniejących stronach i muszą działać bez zmian — stąd tablica dokłada się
   * jako trzeci przypadek, a nie zastępuje tamtych.
   */
  function attrConfigs(el) {
    var raw = el.getAttribute('data-evk-anim');
    if (!raw) return [];
    raw = raw.trim();
    if (!raw) return [];

    var first = raw.charAt(0);
    if (first !== '{' && first !== '[') return [{ animation: raw }];

    var parsed;
    try { parsed = JSON.parse(raw); }
    catch (e) {
      console.warn('[EVK Animator] Nieprawidłowy JSON w data-evk-anim:', raw);
      return [];
    }
    var list = Array.isArray(parsed) ? parsed : [parsed];
    return list.filter(function (o) { return o && typeof o === 'object'; });
  }

  /**
   * Wszystkie konfiguracje elementu. Atrybut wygrywa z klasami — tak było
   * i przy jednej animacji (`attr.animation || slugFromClass(el)`).
   */
  function buildConfigs(el) {
    var slugs = slugsFromClass(el);
    var attrs = attrConfigs(el);

    if (!attrs.length) {
      attrs = slugs.map(function (s) { return { animation: s }; });
      // Element bez slugu i bez atrybutu — jedno puste wejście, żeby
      // buildConfig zwrócił null i zachował dotychczasowy kontrakt.
      if (!attrs.length) attrs = [{}];
    }

    var out = [];
    for (var i = 0; i < attrs.length; i++) {
      var cfg = buildConfig(el, attrs[i], slugs[0] || '');
      if (cfg) out.push(cfg);
    }
    // Ile animacji dzieli ten element. Niesione w konfiguracji, a nie jako
    // dodatkowy argument: `cleansUp()` siedzi pięć wywołań głębiej i przewlekanie
    // liczby przez `initSplit`, `buildAnimation`, wszystkie `attach*` i `tweenVars`
    // dołożyłoby parametr do ośmiu sygnatur, żeby przeczytać go w jednej.
    // `idx` — tożsamość konfiguracji w obrębie elementu. Kolejność jest stała,
    // bo bierze się z atrybutu i klas, a te się nie zmieniają; dzięki temu
    // przygotuj() pamięta między wywołaniami, co już zbudował.
    out.forEach(function (cfg, i) { cfg.siblings = out.length; cfg.idx = i; });
    return out;
  }

  function buildConfig(el, attr, classSlug) {
    var slug = attr.animation || classSlug || '';
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

    // Podmiana treści też nie jest tweenem od–do: `from` i `to` nie opisałyby
    // ruchu DWÓCH rzeczy naraz (tekst wyjeżdża, klon wjeżdża). Kierunek niesie
    // znacznik, a oś czasu składa attachSwap().
    var swap = pick(attr.swap, lib.swap, pre.swap, '');

    if (!from && !to && !textFx && !swap) return null;

    return {
      from:     from,
      to:       to,
      textFx:   textFx,
      words:    attr.words || lib.words || null,
      pointer:  pointer,
      swap:     swap,
      strength: num(pick(attr.strength, lib.strength, pre.strength), 0.35),
      split:    pick(attr.split, lib.split, pre.split, ''),
      mask:     pick(attr.mask, lib.mask, pre.mask, ''),
      targets:  pick(attr.targets, lib.targets, 'self'),
      selector: pick(attr.selector, lib.selector, ''),
      pin:      !!pick(attr.pin, lib.pin, false),
      // Czy preset KOŃCZY niewidocznie. Bierze się z tablicy presetów, nie
      // z wyzwalacza: preset wyjściowy wolno podpiąć pod wejście w kadr
      // (element gasnący, gdy się pojawia, bywa świadomym efektem).
      exit:     !!pick(attr.exit, lib.exit, pre.exit, false),
      // Czy preset opisuje STAN (najechanie, wciśnięcie), a nie wejście ani
      // wyjście. Tak samo jak `exit`: ze znacznika w tablicy presetów, nie
      // z nazwy i nie z wyzwalacza. Czyta to bramka redukcji ruchu — stan
      // najechania nałożony na stałe zostawiłby przycisk trwale uniesiony.
      stan:     !!pick(attr.stan, lib.stan, pre.stan, false),
      trigger:  pick(attr.trigger, lib.trigger, 'viewport'),
      easing:   pick(attr.easing, lib.easing, pre.easing, 'power2.out'),
      duration: num(pick(attr.duration, lib.duration, pre.duration), 0.8),
      delay:    num(pick(attr.delay, lib.delay), 0),
      stagger:  num(pick(attr.stagger, lib.stagger, pre.stagger), 0),
      scrub:    num(pick(attr.scrub, lib.scrub), 1),
      start:    pick(attr.start, lib.start, 'top 85%'),
      end:      pick(attr.end, lib.end, 'bottom 40%'),
      repeat:   !!pick(attr.repeat, lib.repeat, false),
      // Pętla to co innego niż 'repeat': tamto znaczy „odtwórz ponownie przy
      // każdym wejściu w kadr", to — „kręć się bez końca". Nazwy zostają
      // rozłączne, bo mieszanie ich w panelu byłoby nie do rozplątania.
      loop:     !!pick(attr.loop, lib.loop, false),
      loopYoyo: !!pick(attr.loopYoyo, lib.loop_yoyo, lib.loopYoyo, false),
      order:    num(pick(attr.order, lib.order), 0),
      /* Zakres szerokości okna, w którym animacja ma w ogóle istnieć. PHP
         wysyła tu PIKSELE — klucze breakpointów Bricks rozwija po swojej
         stronie (patrz enqueue_assets w includes/anim/animator.php), więc
         silnik nie musi wiedzieć nic o nazwach progów. Brak wartości znaczy
         „bez granicy" i dlatego czytamy je przez `num(..., 0)`: zero i brak
         są tu tym samym. */
      minW:     num(pick(attr.minW, lib.minW), 0),
      maxW:     num(pick(attr.maxW, lib.maxW), 0),
    };
  }

  /**
   * Cele animacji: sam element, jego dzieci, selektor w środku albo element
   * ZEWNĘTRZNY. To dopiero nadaje sens polu „stagger" poza tekstem —
   * pojedynczy element nie ma czego rozsuwać.
   *
   * Wyzwalacz i cel były rozdzielone od początku: `scrollTrigger.trigger` to
   * zawsze element, a stąd bierze się to, co się rusza. Brakowało wyłącznie
   * ZASIĘGU — `el.querySelectorAll()` widzi tylko potomków, więc „przewinięcie
   * do sekcji zmienia coś w nagłówku" nie było wykonalne.
   */
  function resolveTargets(el, cfg) {
    if (cfg.targets === 'children') {
      var kids = Array.prototype.slice.call(el.children);
      return kids.length ? kids : [el];
    }

    if (cfg.targets === 'external' || cfg.targets === 'selector') {
      if (!cfg.selector) return [el];
      var external = cfg.targets === 'external';
      var scope    = external ? document : el;
      var found;
      // Błędna składnia selektora rzuca wyjątkiem i wywaliłaby całą inicjalizację.
      try { found = scope.querySelectorAll(cfg.selector); }
      catch (e) {
        console.warn('[EVK Animator] Nieprawidłowy selektor celu:', cfg.selector, el);
        return external ? [] : [el];
      }
      if (found.length) return Array.prototype.slice.call(found);

      // Powrót do siebie ma sens TYLKO przy celu wewnętrznym — tam „nie znalazłem
      // nic w środku" i „animuj całość" są bliskimi kuzynami. Przy celu zewnętrznym
      // oznaczałby animowanie zupełnie innego elementu niż ten, o który proszono,
      // i to bez żadnego znaku, że coś poszło nie tak.
      if (external) {
        console.warn('[EVK Animator] Cel zewnętrzny „' + cfg.selector + '" nie istnieje.', el);
        return [];
      }
      return [el];
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
   * Efekty tekstowe wolno puszczać WYŁĄCZNIE na elementy bez dzieci-elementów.
   *
   * TextPlugin i ScrambleTextPlugin wpisują tekst przez innerHTML, a docelowy
   * tekst bierzemy z textContent. Na kontenerze jedno z drugim daje katastrofę:
   * textContent skleja treść wszystkich potomków w jeden ciąg bez odstępów,
   * a wpisanie go z powrotem kasuje całe znaczniki i style w środku. Zgłoszone
   * na korzeniu slidera — wychodziły z tego wszystkie slajdy sklejone w jeden
   * akapit. To nie jest przypadek do obsłużenia, tylko do zablokowania.
   */
  function textSafeTargets(el, targets) {
    var safe = targets.filter(function (t) { return t.children.length === 0; });
    if (safe.length !== targets.length) {
      console.warn('[EVK Animator] Efekt tekstowy pomija element, który ma w środku inne '
        + 'elementy — wtyczka tekstowa zastąpiłaby całą jego zawartość zlepkiem tekstu. '
        + 'Ustaw „Cel animacji" na sam tekst (np. selektorem) albo przypnij animację '
        + 'bezpośrednio do nagłówka lub akapitu.', el);
    }
    return safe;
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

  /**
   * Uchwyt do odpięcia nasłuchów. Dziś NIKT go nie czyta — sprawdzone: dwa
   * zapisy, zero odczytów w całym repozytorium. Zostaje mimo to, bo bez niego
   * nie da się w przyszłości posprzątać po elemencie; ale musi być LISTĄ.
   * Pojedynczy slot przy dwóch animacjach interaktywnych na jednym elemencie
   * gubił uchwyt pierwszej i jej nasłuchy zostawałyby na stałe.
   */
  function rememberAbort(el, ac) {
    (el._evkAnimAbort = el._evkAnimAbort || []).push(ac);
  }

  /**
   * Osie czasu wejściowe zapamiętane na elemencie — do ponownego odegrania.
   *
   * Wejście w kadr jest z definicji „raz i koniec" (`once: true`), bo strona
   * przewija się w jedną stronę. W panelu, który się OTWIERA I ZAMYKA, to
   * założenie przestaje obowiązywać: ScrollTrigger wystrzelił przy pierwszym
   * pokazaniu i nigdy więcej, więc animacja grała raz na całe życie strony.
   * Zgłoszone z użycia przy menu offcanvas.
   */
  function rememberTimeline(el, tl) {
    (el._evkTls = el._evkTls || []).push(tl);
  }

  /**
   * Osie czasu WYJŚCIOWE — wyzwalacz „zamknięcie menu".
   *
   * Osobny koszyk od `_evkTls`, bo to nie jest wariant wejścia: te osie nie
   * mają żadnego wyzwalacza i nigdy nie zagrają same z siebie. Trzymane razem
   * z wejściowymi odegrałby je pierwszy `evkAnimatorReplay()` i treść znikałaby
   * przy OTWIERANIU menu.
   */
  function rememberCloseTimeline(el, tl) {
    (el._evkCloseTls = el._evkCloseTls || []).push(tl);
  }

  /** Odłożone wejście (opóźnienie treści) — patrz replayIn(). */
  function cancelWait(tl) {
    if (tl._evkWait) { tl._evkWait.kill(); tl._evkWait = null; }
  }

  // ── Budowa osi czasu ───────────────────────────────────────────────────

  /**
   * Varsy tweenu w jednym miejscu. Wyzwalacze różnią się tylko tym, co
   * dokładają na wierzchu (scrub kasuje czas i krzywą, kolejka startowa podaje
   * pozycję), a efekty tekstowe mają działać przy każdym z nich — rozsypane po
   * czterech funkcjach obsłużyłyby tylko ten wyzwalacz, o którym ktoś pamiętał.
   */
  /**
   * Czy po animacji sprzątamy inline'owy zapis transformacji.
   *
   * GSAP zostawia po sobie `transform: translate(0px, 0px)` i `filter: blur(0px)`.
   * To NIE jest `none`: element pozostaje wtedy na stałe blokiem zawierającym
   * dla potomków pozycjonowanych absolutnie i osobnym kontekstem układania —
   * długo po tym, jak animacja się skończyła. Na zwykłym divie nie widać tego
   * wcale, ale wystarczy przypiąć animację do czegoś, czego układem steruje
   * inny skrypt (slider, popup, sticky), żeby zaczęło się psuć bez powodu.
   *
   * Sprzątamy tylko przy wejściach BEZ powtarzania, bo tylko tam stan końcowy
   * jest z definicji stanem naturalnym elementu: przesunięcie 0, skala 1,
   * rozmycie 0. Przy hoverze, kliku i scrubie stan końcowy jest znaczący,
   * a przy powtarzaniu oś czasu jeszcze się cofa — tam czyszczenie psułoby efekt.
   */
  function cleansUp(cfg) {
    // Pętla nigdy nie dobiega do końca, więc clearProps i tak by nie wystrzelił —
    // ale zostawianie tu tej ścieżki byłoby miną: wystarczyłoby, żeby ktoś
    // kiedyś dołożył pętli skończoną liczbę powtórzeń.
    if (cfg.loop) return false;

    // Wszystko, co kończy NIEWIDOCZNIE, jest tu wyłączone: `clearProps`
    // przywróciłby element do widoczności, czyli cofnął dokładnie to, co
    // animacja miała zrobić.
    //
    // Wyzwalacz nie wystarcza jako kryterium i zmierzył to test presetów:
    // „mask-out-up" pod wejściem w kadr kończył z `clip-path: none`, bo
    // clipPath jest na liście czyszczonych właściwości. Element wracał więc
    // widoczny mimo poprawnie odegranej animacji. Stąd znacznik z presetu
    // obok wyzwalacza — opacity ocalało tylko dlatego, że nie jest czyszczone.
    //
    // Presety STANOWE z tego samego powodu, tylko z drugiej strony: ich stan
    // końcowy (uniesienie, powiększenie, przygaszenie) NIE jest stanem
    // naturalnym elementu. `clearProps` zdejmowałby go w chwili, gdy animacja
    // dobiega końca — element powiększyłby się i natychmiast wrócił, czyli
    // preset wyglądałby na zepsuty. Wyłapane pomiarem przy dokładaniu tej
    // rodziny: `hover-scale` pod wejściem w kadr kończył z `transform: none`.
    if (cfg.exit || cfg.trigger === 'exit' || cfg.stan) return false;

    // Element z kilkoma animacjami NIE jest po jednej z nich „gotowy":
    // clearProps skasowałby inline'owy transform, na którym stoi sąsiadka.
    // Uzasadnienie z komentarza wyżej — „stan końcowy jest stanem naturalnym
    // elementu" — obowiązuje tylko wtedy, gdy animacja jest jedyna.
    if (cfg.siblings > 1) return false;

    return !cfg.repeat && (cfg.trigger === 'viewport' || cfg.trigger === 'load');
  }

  function tweenVars(targets, cfg, override) {
    var vars = Object.assign({}, cfg.to, {
      duration: cfg.duration,
      ease:     cfg.easing,
    }, override || {});
    if (cfg.stagger > 0) vars.stagger = cfg.stagger;
    if (cfg.textFx) Object.assign(vars, textFxVars(targets, cfg));

    // Wyliczone właściwości, nie clearProps:true — własne „to" wpisane w panelu
    // może celowo zostawiać kolor czy tło i nie wolno go kasować. Opacity też
    // zostaje: kończy na 1, więc nie szkodzi, a ktoś może celowo animować do 0,8.
    if (cleansUp(cfg)) vars.clearProps = 'transform,filter,clipPath';

    return vars;
  }

  /**
   * Stan wyjściowy: własny z konfiguracji albo dorobiony przez efekt tekstowy.
   *
   * `bezFrom` każe pominąć `from` PRESETU — podaje je wyłącznie ścieżka
   * interaktywna, patrz attachInteractive(). Stan początkowy efektu tekstowego
   * zostaje mimo tej flagi i to nie jest niedopatrzenie: maszyna do pisania
   * zaczyna od pustego pola, więc bez niego nie miałaby czego wypisywać.
   * Gasi element `from` presetu, nie textFxFrom().
   */
  function startVars(cfg, bezFrom) {
    if (cfg.from && !bezFrom) return Object.assign({}, cfg.from);
    return cfg.textFx ? textFxFrom(cfg) : null;
  }

  function buildTimeline(targets, cfg, paused, bezFrom) {
    var tl    = gsap.timeline({ paused: !!paused });
    var vars  = tweenVars(targets, cfg);
    var start = startVars(cfg, bezFrom);

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
      delay:  cfg.delay,
      repeat: cfg.loop ? -1 : 0,
      yoyo:   !!(cfg.loop && cfg.loopYoyo),
      scrollTrigger: {
        trigger:       el,
        start:         cfg.start,
        // Pętla nie może być „raz i koniec" — bez tego ScrollTrigger zabiłby
        // wyzwalacz po pierwszym wejściu i przy powrocie do kadru nic by nie było.
        once:          !cfg.loop && !cfg.repeat,
        toggleActions: cfg.repeat ? 'play reverse play reverse' : 'play none none none',
      },
    });

    if (start) tl.fromTo(targets, start, vars);
    else       tl.to(targets, vars);
    rememberTimeline(el, tl);
    return tl;
  }

  /**
   * Wyjście z kadru — OSOBNA animacja, nie cofnięcie wejściowej.
   *
   * `toggleActions` ma cztery sloty: [onEnter, onLeave, onEnterBack, onLeaveBack].
   * Stąd „reverse play reverse play": gramy przy każdym WYJŚCIU (górą przy
   * przewijaniu w dół, dołem przy powrocie do góry) i cofamy przy każdym
   * POWROCIE w kadr. Sprawdzenie tylko jednego kierunku przechodziłoby też dla
   * 'none play none none', które połowy przypadków nie obsługuje — dlatego
   * tests/anim-exit.test.js mierzy oba.
   *
   * `once: false` jest tu warunkiem działania, nie ustawieniem. Gdyby zadziałało
   * jak przy wejściu (`once: !cfg.loop && !cfg.repeat`), pierwsze wyjście
   * zabiłoby wyzwalacz i element z `opacity: 0` zostałby niewidzialny na zawsze.
   *
   * `immediateRender: false` też nie jest ozdobą. Wejście MUSI nakładać swój
   * stan początkowy od razu — na tym stoi zasłona `evk-veil` i to trzyma treść
   * ukrytą do czasu wejścia w kadr. Wyjście ma `from` równe stanowi spoczynku,
   * więc nie ma czego renderować z wyprzedzeniem; gdyby renderowało, element
   * z obiema animacjami dostawałby stan początkowy tej zbudowanej PÓŹNIEJ
   * i bywałby widoczny albo niewidoczny wbrew konfiguracji. Ten sam wzorzec
   * i z tego samego powodu jest w assets/js/bg-shift.js.
   */
  function attachExit(el, targets, cfg) {
    var vars  = tweenVars(targets, cfg, { immediateRender: false });
    var start = startVars(cfg);

    var tl = gsap.timeline({
      scrollTrigger: {
        trigger:       el,
        start:         cfg.start,
        end:           cfg.end,
        once:          false,
        toggleActions: 'reverse play reverse play',
      },
    });

    // Opóźnienie POZYCYJNIE, nie varsami osi. Przy `toggleActions` oś jest
    // budowana raz i odtwarzana wielokrotnie — `delay` w varsach liczyłby się
    // od zegara rodzica z chwili utworzenia, czyli tylko za pierwszym razem.
    if (start) tl.fromTo(targets, start, vars, cfg.delay);
    else       tl.to(targets, vars, cfg.delay);
    return tl;
  }

  /**
   * Zamknięcie menu — oś czasu BEZ WYZWALACZA, wstrzymana do odwołania.
   *
   * Wyzwalacz „wyjście z kadru" się tu nie nadaje i dlatego to osobna pozycja
   * na liście: tamten wisi na ScrollTriggerze i mierzy opuszczanie kadru,
   * a przy zamykaniu menu żadnego kadru się nie opuszcza — panel po prostu
   * znika spod treści. Oś powstaje więc `paused` i czeka na `evkAnimatorExit()`,
   * które woła menu tuż przed zamknięciem.
   *
   * `immediateRender: false` z tego samego powodu co przy wyjściu z kadru:
   * `from` animacji wyjściowej to stan SPOCZYNKU, więc nie ma czego renderować
   * z wyprzedzeniem, a nałożone od razu przykryłoby stan początkowy animacji
   * WEJŚCIOWEJ na tym samym elemencie — i treść bywałaby widoczna albo nie
   * zależnie od tego, którą animację zbudowano później.
   */
  function attachMenuClose(el, targets, cfg) {
    var tl    = gsap.timeline({ paused: true });
    var vars  = tweenVars(targets, cfg, { immediateRender: false });
    var start = startVars(cfg);

    // Opóźnienie POZYCYJNIE, nie varsami osi — ta oś jest odtwarzana przy
    // każdym zamknięciu, a `delay` w varsach liczyłby się od zegara rodzica
    // z chwili utworzenia, czyli zadziałałby najwyżej raz.
    if (start) tl.fromTo(targets, start, vars, cfg.delay);
    else       tl.to(targets, vars, cfg.delay);

    rememberCloseTimeline(el, tl);
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
        /* Przypięty wyzwalacz musi odświeżyć się PRZED tymi pod nim — inaczej
           liczą punkty startu z układu sprzed wstawienia zapasu i odpalają za
           wcześnie. To samo ustawienie ma pin Horizontal Scrolla. */
        refreshPriority: cfg.pin ? 1 : 0,
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

  /**
   * Hover i klik — oś budowana BEZ `from` presetu.
   *
   * `fromTo` renderuje stan początkowy natychmiast (na tym celowo stoi
   * attachViewport). Przy wyzwalaczu interaktywnym daje to skutek odwrotny do
   * zamierzonego: preset wejściowy ma we `from` stan UKRYTY, więc element
   * parkuje niewidoczny i pojawia się dopiero po najechaniu. Zgłoszone jako
   * „większość animacji powoduje, że element jest niewidoczny przed hover".
   *
   * Samo `to` znaczy: stanem spoczynku jest to, co wyrenderował CSS. GSAP
   * zapisuje wartość wyjściową przy pierwszym odtworzeniu, więc `reverse()`
   * wraca dokładnie tam.
   *
   * ALE TYLKO DLA PRESETÓW, KTÓRE NIE SĄ STANOWE. Preset stanowy ma we `from`
   * stan SPOCZYNKU, nie ukrycia — `underline-sweep` trzyma tam podkład
   * z gradientem i zerową szerokością podkreślenia, a `border-draw` ramkę
   * o zerowej grubości. Bez nich nie ma z czego animować: GSAP dostaje
   * `background-size: auto` i nie ma jak dojść do `100% 2px`. Zmierzone —
   * kasowanie `from` wszystkim zapaliło dwa istniejące sprawdzenia na czerwono.
   *
   * Kryterium jest więc RODZINA presetu, a nie wyzwalacz: `from` gasi wtedy,
   * gdy preset jest wejściem albo wyjściem, i tylko wtedy go pomijamy.
   */
  function attachInteractive(el, targets, cfg) {
    return podepnijInteraktywnie(el, buildTimeline(targets, cfg, true, !cfg.stan), cfg);
  }

  /**
   * Nasłuchy wyzwalaczy interaktywnych dla GOTOWEJ osi czasu.
   *
   * Wydzielone z attachInteractive(), bo podmiana treści (attachSwap) buduje oś
   * inaczej — z klonów kawałków — a podpina się dokładnie tak samo. Druga kopia
   * tych czterech nasłuchów rozjechałaby się przy pierwszej poprawce; najpewniej
   * na obsłudze klawiatury, bo o niej najłatwiej zapomnieć.
   *
   * `przerwij` przekazuje ten, kto może zostać zbudowany PONOWNIE na tym samym
   * elemencie — patrz attachSwap i `autoSplit`.
   */
  function podepnijInteraktywnie(el, tl, cfg, przerwij) {
    if (przerwij && el[przerwij]) el[przerwij].abort();
    var ac = new AbortController();
    if (przerwij) el[przerwij] = ac;
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

    rememberAbort(el, ac);
    return tl;
  }

  // ── Podmiana treści na najechaniu ──────────────────────────────────────

  /** Maksymalny skos kawałków przy sile 1. Wyżej robi się z tego chorągiewka. */
  var SWAP_MAX_SKEW = 8;

  /**
   * Podmiana treści: tekst wyjeżdża, jego kopia wjeżdża na to samo miejsce.
   *
   * Kopia jest KONIECZNA — bez niej nie ma czego wsunąć w miejsce
   * wyjeżdżającego tekstu i efekt sprowadza się do zniknięcia. Klasyczna
   * „rolka" robi to dwiema warstwami nad sobą, ale tutaj wystarczy jedna:
   * SplitText z opcją `mask` owija KAŻDY kawałek własnym `overflow: hidden`,
   * więc klon kawałka trafia do tej samej maski i ma z definicji identyczne
   * pudełko. Dopasowywanie geometrii dwóch warstw odpada.
   *
   * Klon dostaje `aria-hidden`, bo dla czytnika ekranu jest czystym
   * powtórzeniem — bez tego każdy taki napis byłby czytany dwa razy.
   *
   * Klonów nie sprzątamy: `autoSplit` przy zmianie szerokości okna odtwarza
   * element z treści zapamiętanej przez SplitText, a ta pochodzi sprzed ich
   * powstania. Sprzątać trzeba za to NASŁUCHY — patrz `_evkSwapAbort` niżej.
   */
  function attachSwap(el, kawalki, cfg) {
    if (!kawalki.length) return null;

    var wGore  = cfg.swap !== 'down';        // domyślnie treść wjeżdża z dołu
    var wyjscie = wGore ? -100 : 100;
    var skos    = Math.max(-SWAP_MAX_SKEW, Math.min(SWAP_MAX_SKEW,
                    (cfg.strength || 0) * SWAP_MAX_SKEW));

    /* NAJPIERW SAME ODCZYTY, POTEM SAME ZAPISY.
     *
     * Odczyt stylu wymusza jego przeliczenie, jeśli cokolwiek zdążyło go
     * unieważnić. Pętla, która czyta maskę, dokłada do niej klon i czyta
     * następną, unieważnia go za każdym obrotem — więc przeglądarka przelicza
     * styl tyle razy, ile jest kawałków. Przy podziale na znaki to setki razy
     * na jedną stronę i widać to w PageSpeed jako wymuszone przeformatowanie.
     *
     * Zmierzone przez Performance.getMetrics na stronie z 494 klonami:
     * 779 przeliczeń stylu i 70 ms układu przed rozdzieleniem faz, 539 i 28 ms
     * po. Liczba przeliczeń układu się nie zmienia — zmienia się ich koszt,
     * bo styl nie jest już wtedy brudny. */
    var statyczne = kawalki.map(function (kawalek) {
      var maska = kawalek.parentNode;
      return !!maska && getComputedStyle(maska).position === 'static';
    });

    var klony = kawalki.map(function (kawalek, i) {
      var maska = kawalek.parentNode;
      var klon  = kawalek.cloneNode(true);
      klon.setAttribute('aria-hidden', 'true');
      klon.classList.add('evk-anim-swap-klon');
      // Klon leży NA oryginale, nie za nim: obie kopie są w tej samej masce,
      // a bez wyjęcia z układu klon dopisałby się obok i rozepchnął wiersz.
      gsap.set(klon, { position: 'absolute', top: 0, left: 0 });
      if (maska) {
        // Maska od SplitText ma `overflow: hidden`, ale nie musi być układem
        // odniesienia — bez tego `position: absolute` klonu uciekłoby wyżej.
        if (statyczne[i]) gsap.set(maska, { position: 'relative' });
        maska.appendChild(klon);
      }
      return klon;
    });

    // Klon czeka poza maską, po przeciwnej stronie niż ta, w którą wyjeżdża
    // oryginał — inaczej obie kopie mijałyby się w tym samym kierunku.
    gsap.set(klony, { yPercent: -wyjscie, skewY: skos });

    var tl = gsap.timeline({ paused: true });
    var wspolne = { duration: cfg.duration, ease: cfg.easing };
    if (cfg.stagger > 0) wspolne.stagger = cfg.stagger;

    // Oba ruchy w JEDNYM oknie i na tej samej pozycji osi (0). Osobne wywołania
    // dałyby dwa okna, które da się rozjechać niezależnie — a wtedy przez chwilę
    // widać dziurę albo dwie kopie naraz.
    tl.to(kawalki, Object.assign({ yPercent: wyjscie, skewY: skos }, wspolne), 0);
    tl.to(klony,   Object.assign({ yPercent: 0,       skewY: 0    }, wspolne), 0);

    /* Nasłuchy przerywane przy każdej przebudowie. `autoSplit` woła `onSplit`
       po każdej zmianie szerokości okna, więc bez tego po kilku zmianach na
       elemencie wisiałoby kilka kompletów nasłuchów i jedno najechanie
       uruchamiałoby kilka osi czasu naraz — z których tylko ostatnia dotyczy
       istniejących kawałków. */
    return podepnijInteraktywnie(el, tl, cfg, '_evkSwapAbort');
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

    rememberAbort(el, ac);
    return null;   // brak osi czasu — nie ma czym sterować z zewnątrz
  }

  function queueLoad(el, targets, cfg) {
    if (loadQueueRan) {
      /* Kolejka startowa przebiegła. Rozstrzyga teraz ZNACZNIK GOTOWOŚCI, bo
         trafiają tu dwa różne przypadki i mylenie ich psuje albo jeden, albo
         drugi:

         — element JUŻ zbudowany (ponowny podział tekstu po zmianie szerokości
           okna) nie ma odtwarzać wejścia drugi raz; ono już było;
         — element JESZCZE nie zbudowany wchodzi tu dlatego, że dopiero teraz
           mieści się w zakresie szerokości. Jego wejście nie zagrało nigdy,
           a sekwencja startowa dawno się skończyła i nie ma z czym go
           synchronizować — więc gra od razu, sam. */
      if (el.dataset.evkAnimReady === '1') return;

      var tl = buildTimeline(targets, cfg);
      tl.delay(cfg.delay);
      rememberTimeline(el, tl);
      return;
    }
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

      // Zapętlone pozycje NIE mogą wejść do wspólnej osi. Dziecko z repeat:-1
      // daje rodzicowi nieskończony czas trwania — zmierzone: master.duration()
      // zwraca wtedy 1e10, wartownik nieskończoności GSAP-a. Kolejny krok
      // sekwencji startowałby więc po dziesięciu miliardach sekund, czyli nigdy.
      // Zapętlone dostają własną oś, odsuniętą o tę samą pozycję — sekwencja
      // liczy się dalej tak, jakby ich w niej nie było.
      if (cfg.loop) {
        var solo = gsap.timeline({ repeat: -1, yoyo: !!cfg.loopYoyo, delay: pos });
        if (start) solo.fromTo(item.targets, start, vars);
        else       solo.to(item.targets, vars);
        return;
      }

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
    if (cfg.pointer) return attachPointer(el, targets, cfg);

    // Efekty tekstowe przepisują zawartość, więc nie mogą dostać kontenera.
    if (cfg.textFx) {
      targets = textSafeTargets(el, targets);
      if (!targets.length) return null;
    }

    if (cfg.textFx === 'words') return attachWords(el, targets, cfg);

    // Podmiana treści dostaje KAWAŁKI po podziale tekstu — dokładnie to, co
    // `onSplit` tu przysyła. Dlatego siedzi w tym samym miejscu co pozostałe
    // wyjątki, a nie osobnym wejściem do potoku.
    if (cfg.swap) return attachSwap(el, targets, cfg);

    switch (cfg.trigger) {
      case 'exit':       return attachExit(el, targets, cfg);
      case 'menu-close': return attachMenuClose(el, targets, cfg);
      case 'scrub':      return attachScrub(el, targets, cfg);
      case 'hover':
      case 'click':      return attachInteractive(el, targets, cfg);
      case 'load':       queueLoad(el, targets, cfg); return null;
      default:           return attachViewport(el, targets, cfg);
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
  /**
   * CO dzielimy: sam element czy jego dzieci.
   *
   * SplitText przebudowuje ZAWARTOŚĆ elementu na własne kawałki. Kiedy element
   * jest kontenerem flex albo grid, te kawałki stają się jego elementami
   * układu — i wtedy dzieje się to, co zgłoszono z użycia:
   *
   * — „słowa tworzą jedno słowo o stałych odstępach". Białe znaki nie tworzą
   *   elementów flex (anonimowy element z samej spacji po prostu nie powstaje),
   *   więc SPACJE ZNIKAJĄ, a `gap` kontenera wchodzi między KAŻDĄ literę.
   *   Zmierzone na `display:flex; gap:8px`: napis rozdął się z 253 px do 347 px,
   *   a odstęp 8 px stanął w każdej z trzynastu szczelin między literami.
   *
   * — „słowa i ikona nie są w linii". Cała treść trafia do jednego
   *   `.evk-anim-line` (`display:block`), więc ikona przestaje być elementem
   *   flex. Zmierzone na przycisku `inline-flex`: ikona spadła 23 px pod tekst,
   *   przycisk urósł z 44 px do 62 px.
   *
   * — i po cichu: podział na LINIE przestaje dzielić. Słowa leżą w jednym
   *   wierszu flex (`flex-wrap: nowrap`), więc SplitText widzi jedną linię —
   *   zmierzone: 1 kawałek zamiast 3.
   *
   * Dlatego w takim elemencie schodzimy poziom niżej: dzielimy jego dzieci,
   * a układ zostaje przy nim. Gołego tekstu nie da się podzielić osobno, więc
   * dostaje owijkę — dla flexa jest to zmiana neutralna, bo taki tekst i tak
   * był anonimowym elementem układu. Dziecko bez tekstu (ikona) zostaje
   * nietknięte i dalej jest elementem flex.
   *
   * Podwójnej owijki nie ma jak zrobić: przy kolejnym wejściu tekst siedzi już
   * w `.evk-anim-host`, więc łapie go gałąź dla dzieci-elementów.
   */
  function celePodzialu(el) {
    var display = getComputedStyle(el).display;
    if (display.indexOf('flex') === -1 && display.indexOf('grid') === -1) return [el];

    var cele = [];
    Array.prototype.slice.call(el.childNodes).forEach(function (wezel) {
      if (wezel.nodeType === 3) {
        // Sam biały znak — kontener flex i tak go nie rysuje, nie ma czego dzielić.
        if (!wezel.textContent.trim()) return;
        var host = document.createElement('span');
        host.className = 'evk-anim-host';
        el.insertBefore(host, wezel);
        host.appendChild(wezel);
        cele.push(host);
      } else if (wezel.nodeType === 1 && wezel.textContent.trim()) {
        cele.push(wezel);
      }
    });

    return cele.length ? cele : [el];
  }

  function initSplit(el, cfg) {
    var map  = { lines: 'lines', words: 'words', chars: 'chars' };
    var type = map[cfg.split];
    if (!type) { buildAnimation(el, [el], cfg); return true; }

    var cele = celePodzialu(el);

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
      // Warunek liczony NA CELU podziału, nie na elemencie: przy podziale
      // dzieci celem jest sam napis, więc `aria-label` trafia dokładnie tam,
      // gdzie ma — a ikona obok niego zachowuje swoją nazwę.
      aria:       cele.some(function (c) { return c.children.length > 1; }) ? 'none' : 'auto',
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
      // Jedno wywołanie na WSZYSTKIE cele: SplitText scala z nich kawałki
      // w jedną instancję i woła `onSplit` raz, więc oś czasu dalej jest jedna
      // i `autoSplit` ma co sprzątać.
      SplitText.create(cele, opts);
    } else {
      // GSAP < 3.13 nie zna autoSplit/onSplit — jednorazowy podział, jak dotąd.
      var split  = new SplitText(cele, opts);
      var pieces = split[type];
      buildAnimation(el, (pieces && pieces.length) ? pieces : [el], cfg);
    }
    return true;
  }

  /**
   * FAZA 1 — wszystko, co tanie: konfiguracje, zakres szerokości, redukcja
   * ruchu i STAN POCZĄTKOWY. Zwraca zadanie dla fazy 2 albo `null`, gdy nie ma
   * czego robić.
   *
   * Podział na fazy jest odpowiedzią na zgłoszenie „elementy pojawiają się
   * z opóźnieniem". Zmierzone na lustrze przy dławieniu procesora 6×: silnik
   * ruszał o 1757 ms, a zasłona schodziła dopiero o 2993 ms — 1236 ms treść
   * była niewidoczna, choć wszystko, co zasłona chroni, to stan początkowy.
   * Nałożenie go kosztuje ułamek tego czasu; osie czasu, ScrollTriggery
   * i podział tekstu mogą poczekać, aż treść będzie na ekranie.
   */
  function przygotuj(el) {
    var t0 = performance.now();
    var cfgs = buildConfigs(el);
    if (!cfgs.length) return null;   // brak konfiguracji → spróbuj ponownie później

    /* Zakres szerokości okna. PRZED gałęzią redukcji ruchu i to jest znaczące:
       poza zakresem animacja ma nie ISTNIEĆ, więc nie wolno nakładać jej stanu
       końcowego na stałe tak, jak robi to redukcja. Element zostaje dokładnie
       taki, jak wyrenderował go CSS.

       Nie ma tu też ryzyka, że treść zniknie: zasłonę `evk-veil` zdejmuje
       `unveil()` bezwarunkowo po fazie 1, niezależnie od tego, ile elementów
       się zainicjalizowało. */
    var wszystkich = cfgs.length;
    cfgs = cfgs.filter(wZakresie);
    var pominieto = cfgs.length !== wszystkich;

    /* Co już zbudowane, nie buduje się drugi raz.
       
       Element z dwiema animacjami, z których jedna czeka (na fonty albo na próg
       szerokości), NIE dostaje znacznika gotowości — a bez znacznika initAll()
       wraca do niego przy każdej przebudowie i budował wtedy od nowa także tę
       gotową. Druga oś czasu na tym samym elemencie to podwojony ruch przy
       pętlach i podwojony koszt przy wszystkim innym. */
    var zbudowane = el._evkZbudowane || (el._evkZbudowane = {});
    cfgs = cfgs.filter(function (cfg) { return !zbudowane[cfg.idx]; });

    /* Brak znacznika gotowości = „spróbuj ponownie później". Tak samo, jak przy
       braku konfiguracji — initAll() woła się ponownie po zmianie progu
       i dobuduje to, co odpadło. Znacznika nie stawiamy TAKŻE wtedy, gdy
       zbudowaliśmy część: inaczej element z dwiema animacjami zablokowałby
       tę pominiętą na zawsze. */
    if (!cfgs.length) return { el: el, cfgs: [], pominieto: pominieto };

    cfgs.forEach(function (cfg) { zbudowane[cfg.idx] = true; });

    // Reduced motion: żadnego ruchu, ale stan końcowy musi być widoczny —
    // inaczej element z opacity:0 we from zostałby niewidzialny na stałe.
    // Przy podziale tekstu nie ma po co dzielić: i tak nic się nie animuje.
    if (prefersReduced()) {
      cfgs.forEach(function (cfg) {
        // Wyjątek: wyzwalacze interaktywne. Tam stanem spoczynku jest 'from',
        // a 'to' to stan NAJECHANIA — nałożony na stałe zostawiłby przycisk
        // trwale uniesiony i podświetlony. Nie ma też wejścia do dokończenia,
        // więc najbezpieczniej nie ruszać elementu wcale: zostaje taki, jak
        // wyrenderował go CSS, czyli na pewno widoczny.
        // 'exit' jest tu równie groźny jak hover i klik, ale z odwrotnego
        // powodu: jego `to` to stan PO ZNIKNIĘCIU. Nałożony na stałe zgasiłby
        // element u każdego z włączoną redukcją ruchu — czyli dokładnie ta
        // klasa błędu, dla której powstał tests/motion.test.js.
        // 'menu-close' niesie dokładnie to samo ryzyko: jego `to` to stan
        // treści, która WŁAŚNIE ZNIKNĘŁA razem z zamykanym panelem.
        //
        // Osobno RODZINA presetu, nie tylko wyzwalacz. Preset stanowy
        // (`hover-lift`, `hover-dim`…) opisuje stan NAJECHANIA niezależnie od
        // tego, pod co go podpięto — użyty z wyzwalaczem 'viewport' przeszedłby
        // przez warunek po wyzwalaczu i zostałby nałożony na stałe, zostawiając
        // element trwale uniesiony albo przygaszony. Warunek po znaczniku
        // łapie to niezależnie od wyboru w panelu.
        if (cfg.trigger !== 'hover' && cfg.trigger !== 'click'
            && cfg.trigger !== 'exit' && cfg.trigger !== 'menu-close'
            && !cfg.stan && cfg.to) {
          gsap.set(resolveTargets(el, cfg), cfg.to);
        }
      });
      return { el: el, cfgs: [], pominieto: pominieto };
    }

    /* Stan początkowy — jedyne, co zasłona naprawdę chroni.
     *
     * Podział tekstu i efekty tekstowe nakładają go na KAWAŁKI, których jeszcze
     * nie ma; taki element zostaje pod własnym znacznikiem do końca fazy 2. */
    var czeka = false;
    czasKonfiguracji += performance.now() - t0;
    var t1 = performance.now();
    cfgs.forEach(function (cfg) {
      if (cfg.split || cfg.textFx) { czeka = true; return; }
      var start = stanPoczatkowy(cfg);
      if (start) gsap.set(resolveTargets(el, cfg), start);
    });
    czasStanow += performance.now() - t1;
    if (czeka) el.setAttribute(ATRYBUT_CZEKA, '1');

    return { el: el, cfgs: cfgs, pominieto: pominieto };
  }

  /**
   * FAZA 2 — droga część: podział tekstu, osie czasu, ScrollTriggery.
   *
   * Wszystko, co dotyka układu strony i tworzy wyzwalacze, czyli to, na co
   * przy 36 elementach i 22 ScrollTriggerach schodzi ponad sekunda na wolnym
   * procesorze. Treść jest w tym czasie już widoczna.
   */
  function zbuduj(zadanie) {
    var el = zadanie.el;

    // Podział tekstu wolno zrobić RAZ na element. Dwa `SplitText.create()` na
    // tym samym węźle dzielą już podzielony DOM, a `autoSplit` przy zmianie
    // szerokości okna odbudowuje kawałki, na których wisi pierwsza oś czasu.
    var splitUsed = false;

    zadanie.cfgs.forEach(function (cfg) {
      if (cfg.split && typeof SplitText !== 'undefined') {
        if (splitUsed) {
          console.warn('[EVK Animator] Drugi podział tekstu na tym samym elemencie '
            + 'jest pomijany — dzieli już podzielony DOM. Animacja „' + cfg.split
            + '" zagra bez podziału.', el);
          cfg = Object.assign({}, cfg, { split: '' });
        } else {
          splitUsed = true;
          initSplit(el, cfg);
          return;
        }
      }
      buildAnimation(el, resolveTargets(el, cfg), cfg);
    });

    el.removeAttribute(ATRYBUT_CZEKA);

    /* Znacznik gotowości dopiero TUTAJ, nie w fazie 1. `queueLoad()` czyta go,
       żeby nie odtwarzać wejścia drugi raz — postawiony za wcześnie wyciszyłby
       wszystkie animacje z wyzwalaczem „wczytanie strony". */
    if (!zadanie.pominieto) el.dataset.evkAnimReady = '1';
  }

  /**
   * Zdejmuje zasłonę z <html> (patrz render_preveil() w includes/anim/animator.php).
   * Nazwa klasy celowo poza przestrzenią „evk-anim-" — inaczej selektor w initAll()
   * łapie sam korzeń dokumentu i silnik szuka animacji o slugu z tej klasy.
   */
  /**
   * Stan początkowy do nałożenia w fazie 1 — albo `null`, gdy silnik żadnego
   * nie nakłada.
   *
   * REGUŁA JEST BLIŹNIACZA Z `wiersz_zaslania()` w includes/anim/animator.php:
   * zasłona chowa dokładnie to, czemu tu nakładamy stan. Gdyby się rozjechały,
   * element albo mrugnąłby treścią (zasłona chowa mniej), albo czekał bez
   * powodu (chowa więcej).
   *
   * — `hover`/`click` → nic. `attachInteractive()` woła `buildTimeline` z
   *   `bezFrom = !cfg.stan`, a presety stanowe trzymają w `from` stan SPOCZYNKU.
   * — `exit`/`menu-close` → nic. Obie ścieżki budują tween z
   *   `immediateRender: false`, właśnie po to, żeby nie przykryć tego, co widać.
   * — `viewport`/`load`/`scrub` → `from`, bo tam leci `fromTo`, które renderuje
   *   stan początkowy natychmiast.
   */
  var WYZWALACZE_ZE_STANEM = { viewport: 1, load: 1, scrub: 1 };

  function stanPoczatkowy(cfg) {
    if (cfg.pointer) return null;              // śledzenie kursora nie ma „od"
    if (!WYZWALACZE_ZE_STANEM[cfg.trigger]) return null;
    return cfg.from ? Object.assign({}, cfg.from) : null;
  }

  function unveil() {
    /* Moment zdjęcia zasłony — do raportu `?evk-anim-debug=1`. To jest liczba,
       której szuka się przy zgłoszeniu „elementy pojawiają się z opóźnieniem":
       do tej chwili treść z animacją jest niewidoczna. */
    if (zaslonaZdjeta === null && document.documentElement.classList.contains('evk-veil')) {
      zaslonaZdjeta = Math.round(performance.now());
    }
    document.documentElement.classList.remove('evk-veil');
  }

  /** Czy PIERWSZY przebieg jeszcze przed nami — tylko on odkłada fazę 2. */
  var pierwszyRaz = true;

  /** Następna klatka, czyli najwcześniejszy moment PO namalowaniu strony. */
  function poKlatce(co) {
    if (window.requestAnimationFrame) requestAnimationFrame(co);
    else                              setTimeout(co, 16);
  }

  /**
   * Pierwszy przebieg idzie w dwóch fazach, rozdzielonych jedną klatką.
   *
   * KLATKA JEST MECHANIZMEM, NIE OZDOBĄ. `unveil()` zdejmuje klasę, ale
   * przeglądarka maluje dopiero po zakończeniu ZADANIA — gdyby faza 2 szła
   * w tym samym, wcześniejsze odsłonięcie nie dałoby nic. Zmierzone przed
   * zmianą: przy dławieniu 6× treść czekała 1236 ms na budowanie, które
   * w niczym jej nie dotyczy.
   *
   * Odkładamy TYLKO pierwszy przebieg. Kolejne (zmiana progu szerokości,
   * podmiana treści, `evkAnimatorRefresh`) idą synchronicznie, bo zasłony już
   * nie ma i nie ma czego przyspieszać — a wołający mają prawo oczekiwać, że
   * po powrocie z funkcji animacje istnieją.
   */
  function initAll(poBudowaniu) {
    var zadania = [];

    document.querySelectorAll(SELEKTOR_ANIM).forEach(function (el) {
      if (el.dataset.evkAnimReady === '1') return;
      /* Wytwory silnika odpadają BEZ budowania konfiguracji. Bez tego każdy
         z nich przechodził przez buildConfigs(), zgłaszał „brak animacji
         w bibliotece" i — nie dostając znacznika gotowości — wracał przy
         każdej kolejnej zmianie progu szerokości. Przy podziale na znaki to
         kilkadziesiąt węzłów na jeden nagłówek. */
      if (tylkoWezelSilnika(el)) return;
      var zadanie = przygotuj(el);
      if (zadanie) zadania.push(zadanie);
    });

    fazaJeden = Math.round(performance.now());

    // Bezwarunkowo — także gdy część elementów się nie zainicjalizowała.
    // Stany „from" są już nałożone, więc nie ma czym błysnąć.
    unveil();

    var faza2 = function () {
      zadania.forEach(zbuduj);
      runLoadQueue();
      fazaDwa = Math.round(performance.now());
      if (poBudowaniu) poBudowaniu();
    };

    if (pierwszyRaz) { pierwszyRaz = false; poKlatce(faza2); }
    else             faza2();
  }

  /**
   * Progi szerokości, o których w ogóle warto wiedzieć.
   *
   * Zbierane z biblioteki I z atrybutów, bo zakres da się podać w obu miejscach.
   * Atrybuty czytamy wyrażeniem, a nie przez buildConfig() — na starcie chodzi
   * o same liczby, a pełne scalanie konfiguracji dla każdego elementu byłoby
   * tu robotą wykonaną drugi raz.
   */
  function progiWUzyciu() {
    var out = {};
    var dodaj = function (min, max) {
      if (min) out['(min-width: ' + min + 'px)'] = 1;
      if (max) out['(max-width: ' + max + 'px)'] = 1;
    };

    Object.keys(LIBRARY).forEach(function (k) { dodaj(LIBRARY[k].minW, LIBRARY[k].maxW); });

    document.querySelectorAll('[data-evk-anim]').forEach(function (el) {
      var raw = el.getAttribute('data-evk-anim') || '';
      if (raw.indexOf('minW') === -1 && raw.indexOf('maxW') === -1) return;
      var re = /"(minW|maxW)"\s*:\s*(\d+)/g, m;
      while ((m = re.exec(raw))) dodaj(m[1] === 'minW' ? m[2] : 0, m[1] === 'maxW' ? m[2] : 0);
    });

    return Object.keys(out);
  }

  /**
   * Ponowne wejście w zakres dobudowuje to, co wcześniej odpadło.
   *
   * Cała robota siedzi w tym, że initAll() pomija elementy ze znacznikiem
   * gotowości — więc powtórka jest tania, a pominięte dobudowują się same.
   *
   * W DRUGĄ STRONĘ NIC NIE ROZBIERAMY. Zwężenie okna to działanie osoby przy
   * komputerze, nie odwiedzającego, a rozebranie żywej osi czasu potrafi
   * zostawić element w stanie pośrednim — czyli dokładnie tam, gdzie nie
   * chcemy go zostawić.
   *
   * Nasłuch zakładamy tylko wtedy, gdy ktokolwiek używa zakresów: bez tego
   * każda strona z Animatorem przeliczałaby się przy każdej zmianie rozmiaru
   * okna, żeby nie znaleźć niczego do zrobienia.
   */
  var przebudowaCzeka = false;

  /**
   * Przebudowa po zmianie progu — ZAWSZE poza bieżącą klatką.
   *
   * Zdarzenie `matchMedia` przychodzi w środku zmiany rozmiaru okna, a na
   * telefonie zmiana rozmiaru bywa skutkiem chowania paska adresu W TRAKCIE
   * przewijania. Budowanie animacji synchronicznie w tym miejscu wkłada pracę
   * dokładnie tam, gdzie przeglądarka liczy klatkę scrolla.
   *
   * Jedna kolejka na wszystkie progi: przekroczenie kilku naraz (zwykłe przy
   * przeciąganiu krawędzi okna) daje jedno przeliczenie, nie kilka.
   */
  function zaplanujPrzebudowe() {
    if (przebudowaCzeka) return;
    przebudowaCzeka = true;
    var start = function () { przebudowaCzeka = false; initAll(); };
    if (window.requestIdleCallback) window.requestIdleCallback(start, { timeout: 200 });
    else                            requestAnimationFrame(start);
  }

  /**
   * Podmiany treści po starcie strony.
   *
   * Elementy wstawione przez filtr pętli zapytania, stronicowanie albo
   * doładowywanie nigdy nie przechodziły inicjalizacji: Animator przebudowywał
   * się wyłącznie przy zmianie progu szerokości i po wczytaniu fontów. Hover
   * podpina się per element (`podepnijInteraktywnie`), więc nowy węzeł nie miał
   * żadnych nasłuchów. Zgłoszone z użycia: „animacja hover nie działa na
   * elemencie po przefiltrowaniu".
   *
   * OBSERWATOR, a nie zdarzenia Bricksa, i to jest wybór świadomy: nazw zdarzeń
   * nie da się tu sprawdzić, więc byłby to kod pisany na wiarę. Obserwator jest
   * niezależny od dostawcy — łapie filtry Bricksa, cudze wtyczki i każdą inną
   * podmianę tak samo.
   *
   * Przeliczenie idzie przez `zaplanujPrzebudowe()`, więc kilkadziesiąt węzłów
   * wstawionych naraz daje JEDNO przeliczenie, a nie jedno na węzeł.
   */
  function sledzPodmiany() {
    if (!window.MutationObserver || !document.body) return;

    var obs = new MutationObserver(function (zmiany) {
      for (var i = 0; i < zmiany.length; i++) {
        var dodane = zmiany[i].addedNodes;
        for (var j = 0; j < dodane.length; j++) {
          if (dodane[j].nodeType === 1 && maDoZrobienia(dodane[j])) {
            zaplanujPrzebudowe();
            return;
          }
        }
      }
    });
    obs.observe(document.body, { childList: true, subtree: true });
  }

  /**
   * Czy w tym poddrzewie jest cokolwiek, czego `initAll()` jeszcze nie tknął.
   *
   * Dwa odsiewy: węzeł ze znacznikiem gotowości jest już zrobiony, a wytwór
   * silnika (maska, kawałek, klon) nigdy nie był robotą do zrobienia — to
   * nasza własna produkcja.
   *
   * NIE jest to zabezpieczenie przed zapętleniem, choć tak wygląda. Sprawdzone
   * mutacją: nawet gdy ta funkcja zgłasza robotę ZAWSZE, nic się nie zapętla —
   * `initAll()` jest idempotentne, znakuje węzły i przy kolejnym przebiegu nie
   * dokłada już niczego, więc obserwator nie ma na co zareagować. Odsiewy są
   * tu po to, żeby nie wołać przebudowy bez powodu.
   */
  function maDoZrobienia(el) {
    var kandydaci = [];
    if (el.matches && el.matches(SELEKTOR_ANIM)) kandydaci.push(el);
    if (el.querySelectorAll) {
      var w = el.querySelectorAll(SELEKTOR_ANIM);
      for (var i = 0; i < w.length; i++) kandydaci.push(w[i]);
    }

    for (var k = 0; k < kandydaci.length; k++) {
      var c = kandydaci[k];
      if (c.dataset.evkAnimReady === '1') continue;
      if (tylkoWezelSilnika(c)) continue;
      return true;
    }
    return false;
  }

  function sledzProgi() {
    progiWUzyciu().forEach(function (zapytanie) {
      var mq = window.matchMedia(zapytanie);
      if (mq.addEventListener)  mq.addEventListener('change', zaplanujPrzebudowe);
      else if (mq.addListener)  mq.addListener(zaplanujPrzebudowe);
    });
  }

  /**
   * Rejestracja wtyczek GSAP. Osobno od waitForGSAP, bo potrzebuje jej też
   * podgląd w panelu — tam `start()` kończy od razu (brak biblioteki i brak
   * elementów z data-evk-anim), więc rejestracja tą drogą nigdy by nie zaszła
   * i SplitText leżałby załadowany, ale nieaktywny.
   */
  var pluginsReady = false;

  function registerPlugins() {
    if (pluginsReady || !window.gsap) return;
    if (window.ScrollTrigger)      gsap.registerPlugin(ScrollTrigger);
    // Wtyczki opcjonalne — na stronie dociągane tylko gdy któryś wiersz
    // biblioteki ich potrzebuje (patrz enqueue_assets() w animator.php).
    if (window.SplitText)          gsap.registerPlugin(SplitText);
    if (window.TextPlugin)         gsap.registerPlugin(TextPlugin);
    if (window.ScrambleTextPlugin) gsap.registerPlugin(ScrambleTextPlugin);
    pluginsReady = true;
  }

  function waitForGSAP(cb, tries) {
    tries = tries || 0;
    if (window.gsap && window.ScrollTrigger) {
      registerPlugins();
      cb();
    } else if (tries < 50) {
      setTimeout(function () { waitForGSAP(cb, tries + 1); }, 100);
    } else {
      console.warn('[EVK Animator] Brak GSAP/ScrollTrigger.');
      unveil();   // GSAP nie dojechał — treść nie może zostać ukryta
    }
  }

  /**
   * Raport kosztu — na żądanie, parametrem w adresie.
   *
   * Zgłoszenie „bezwładny scroll zatrzymuje się na iOS, po wyłączeniu Animatora
   * problem znika" dało się rozstrzygnąć tylko wyłączaniem modułów po kolei.
   * Te liczby mówią wprost, ile strona kosztuje: ile elementów silnik obsłużył,
   * ile z nich dzieli tekst na kawałki (setki węzłów przy podziale na znaki),
   * ile ScrollTriggerów wisi i ile z nich pracuje na KAŻDEJ klatce przewijania.
   *
   * Bez parametru konsola milczy — raport na produkcji każdego użytkownika
   * byłby hałasem.
   */
  function raportKosztu() {
    var scrollTriggery = window.ScrollTrigger ? ScrollTrigger.getAll() : [];
    var scrubow = scrollTriggery.filter(function (t) {
      return t.vars && t.vars.scrub !== undefined && t.vars.scrub !== false;
    }).length;

    var wiersze = Object.keys(LIBRARY).map(function (slug) {
      var r = LIBRARY[slug];
      var pre = PRESETS[r.preset] || {};
      return {
        slug:     slug,
        preset:   r.preset,
        wyzwalacz: r.trigger,
        dzieli:   pre.split || '',
        zakres:   (r.minW || '') + (r.maxW ? '–' + r.maxW : (r.minW ? '–' : '')),
      };
    });

    console.log('[EVK Animator] koszt strony:', {
      elementow:     document.querySelectorAll('[data-evk-anim-ready="1"]').length
                     || document.querySelectorAll('[class*="evk-anim-"], [data-evk-anim]').length,
      kawalkow:      document.querySelectorAll('.evk-anim-line, .evk-anim-word, .evk-anim-char').length,
      masek:         document.querySelectorAll('[class*="evk-anim-"][class*="-mask"]').length,
      klonow:        document.querySelectorAll('.evk-anim-swap-klon').length,
      scrollTriggerow: scrollTriggery.length,
      wTymScrub:     scrubow,
      /* Trzy liczby do zgłoszeń „elementy pojawiają się z opóźnieniem".
         `zaslonaMs` to czas od startu nawigacji do chwili, w której treść
         z animacją w ogóle mogła się pokazać; `podZaslona` — ile elementów
         czekało; `redukcjaRuchu` — czy cokolwiek się w ogóle animuje. Bez tej
         ostatniej „animacja się nie odtwarza" jest nie do odróżnienia od
         usterki, a bywa systemowym ustawieniem (Windows: „Pokaż animacje"). */
      zaslonaMs:     zaslonaZdjeta,
      podZaslona:    document.querySelectorAll('[data-evk-anim-czeka]').length,
      redukcjaRuchu: prefersReduced(),
      /* Rozbicie startu: kiedy plik ruszył, kiedy skończyła się tania faza
         (po niej treść jest widoczna) i kiedy droga. Różnica `silnikStartMs`
         minus zero to dojazd i parsowanie skryptów — tego silnik nie skróci. */
      silnikStartMs: silnikStart,
      fazaJedenMs:   fazaJeden,
      fazaDwaMs:     fazaDwa,
      konfiguracjeMs: Math.round(czasKonfiguracji),
      stanyMs:        Math.round(czasStanow),
    });
    if (console.table) console.table(wiersze);
    if (scrubow) {
      console.log('[EVK Animator] Wiersze ze `scrub` pracują na KAŻDEJ klatce '
        + 'przewijania. Jeśli animują właściwości przeliczające układ '
        + '(padding, width, margin) albo filtry (backdrop-filter), to one '
        + 'kosztują najwięcej — najtaniej jest transform i opacity.');
    }
  }

  /** Pierwsze uruchomienie: budowa plus nasłuch progów szerokości. */
  function pierwszyPrzebieg() {
    /* Nasłuchy i raport DOPIERO po fazie 2 — inaczej diagnostyka opisywałaby
       stronę sprzed budowania i pokazywała zero kawałków podziału przy każdym
       uruchomieniu. Dokładnie taki mylący odczyt przyszedł ze zgłoszenia. */
    initAll(function () {
      if (window.matchMedia) sledzProgi();
      sledzPodmiany();

      var gadaj = false;
      try {
        gadaj = new URLSearchParams(location.search).get('evk-anim-debug') === '1';
      } catch (e) { /* starsze przeglądarki — diagnostyka po prostu nie wchodzi */ }
      if (gadaj) raportKosztu();
    });
  }

  function start() {
    if (!Object.keys(LIBRARY).length && !document.querySelector('[data-evk-anim]')) {
      unveil();
      return;
    }

    /* Start jest natychmiastowy — na nic nie czekamy.
       Wczytanie fontów obsługuje SplitText sam, przez `loadingdone`
       (patrz komentarz na górze pliku). */
    waitForGSAP(pierwszyPrzebieg);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }

  // Punkt wejścia dla treści doładowywanej dynamicznie (AJAX, loop, popupy).
  window.evkAnimatorRefresh = initAll;

  /**
   * Stan startu dla diagnostyki (`?evk-anim-debug=1` i tools/lustro).
   *
   * `zaslonaMs === null` znaczy, że zasłony NIE zdjął silnik — zrobił to
   * bezpiecznik czasowy z <head>. To rozróżnienie jest sednem przy zgłoszeniu
   * „elementy pojawiają się z opóźnieniem": inaczej nie wiadomo, czy silnik był
   * wolny, czy w ogóle nie dojechał.
   */
  window.evkAnimatorStan = function () {
    return {
      zaslonaMs:     zaslonaZdjeta,
      redukcjaRuchu: prefersReduced(),
      elementow:     document.querySelectorAll('[data-evk-anim-ready="1"]').length,
      silnikStartMs: silnikStart,
      fazaJedenMs:   fazaJeden,
      fazaDwaMs:     fazaDwa,
      konfiguracjeMs: Math.round(czasKonfiguracji),
      stanyMs:        Math.round(czasStanow),
    };
  };

  // ── Podgląd w panelu ───────────────────────────────────────────────────

  /**
   * Odgrywa animację w pudełku podglądu w panelu administratora.
   *
   * DLACZEGO TUTAJ, A NIE W admin.js: konfiguracja powstaje przez `buildConfig()`,
   * czyli tę samą funkcję, która obsługuje stronę. Panel podaje wartości pól
   * w atrybucie `data-evk-anim` i dalej dzieje się dokładnie to, co na żywo:
   * scalenie atrybut ⊕ biblioteka ⊕ preset, `tweenVars()`, `startVars()`.
   * Druga kopia tej logiki w panelu rozjechałaby się z silnikiem i podgląd
   * pokazywałby coś innego niż strona — to jest ten sam błąd, który usuwało
   * scentralizowanie budowy varsów w 1.32.0.
   *
   * Czym podgląd RÓŻNI SIĘ od strony i dlaczego:
   *
   * * **Gra raz, na żądanie.** Wyzwalacze `viewport`, `scrub`, `hover`, `click`
   *   i `pin` nie mają sensu w pudełku 120×80 — nie ma czego przewijać ani
   *   przypinać. Presety scrollowe grają więc jako zwykły tween i panel mówi
   *   to wprost, zamiast udawać, że pokazuje całość.
   * * **Sprząta po sobie przed każdym odegraniem.** SplitText przebudowuje DOM,
   *   a wtyczki tekstowe nadpisują `innerHTML` — bez przywrócenia treści druga
   *   próba startowałaby z resztek pierwszej.
   *
   * Zwraca oś czasu albo null (konfiguracja nie do zbudowania, redukcja ruchu).
   */
  function previewPlay(el) {
    if (typeof gsap === 'undefined') return null;
    registerPlugins();   // w panelu start() kończy od razu i nie robi tego za nas

    // Sprzątanie po poprzednim odegraniu.
    if (el._evkPrevTl)    { el._evkPrevTl.kill();    el._evkPrevTl = null; }
    if (el._evkPrevSplit) { el._evkPrevSplit.revert(); el._evkPrevSplit = null; }
    if (el._evkPrevHTML === undefined) el._evkPrevHTML = el.innerHTML;
    else el.innerHTML = el._evkPrevHTML;
    // `_evkText` pamięta docelowy tekst dla wtyczek tekstowych i jest zapisany
    // na węźle — po przywróceniu innerHTML węzeł jest nowy, ale sam element
    // podglądu ten sam, więc trzeba go wyczyścić ręcznie.
    delete el._evkText;
    gsap.set(el, { clearProps: 'all' });

    // Podgląd zostaje JEDNOKONFIGURACYJNY, choć element na stronie może nieść
    // kilka animacji. Pudełko 120×80 grające dwie rzeczy naraz przestałoby
    // pokazywać to, co się właśnie edytuje — a podgląd jest od jednego wiersza
    // biblioteki, nie od złożenia całej strony.
    var cfg = buildConfig(el, attrConfigs(el)[0] || {}, slugsFromClass(el)[0] || '');
    if (!cfg) return null;

    // Redukcja ruchu — ta sama polityka co w initOne(): bez ruchu, ale stan
    // końcowy widoczny. Podgląd nie jest od tego wyjątkiem; gdyby był, panel
    // pokazywałby ruch, którego odwiedzający nigdy nie zobaczy.
    if (prefersReduced()) {
      if (cfg.to) gsap.set(resolveTargets(el, cfg), cfg.to);
      return null;
    }

    cfg = Object.assign({}, cfg, {
      trigger: 'load',   // gra od razu, bez ScrollTriggera
      repeat:  false,
      pin:     false,
    });

    var tl;

    if (cfg.textFx === 'words') {
      // Zmieniające się słowa to pętla, nie tween — attachWords() zna tę różnicę.
      tl = attachWords(el, [el], cfg);
    } else if (cfg.split && typeof SplitText !== 'undefined'
               && { lines: 1, words: 1, chars: 1 }[cfg.split]) {
      var opts = { type: cfg.split, aria: 'none',
                   linesClass: 'evk-anim-line', wordsClass: 'evk-anim-word',
                   charsClass: 'evk-anim-char' };
      if (cfg.mask) opts.mask = cfg.mask;
      // Bez autoSplit: pudełko podglądu nie zmienia szerokości w trakcie
      // odegrania, a onSplit odbierałby nam uchwyt do osi czasu.
      //
      // `celePodzialu` tak samo jak na stronie — scena podglądu JEST flexem
      // (`.evo-anim-preview-stage` w admin.css), więc bez tego panel pokazywał
      // próbkę „Evoke ONE — próbka tekstu" jako „EvokeONE—próbkatekstu”:
      // zmierzone 20 odstępów po 0 px i 200,3 px zamiast 209,8 px.
      var split  = new SplitText(celePodzialu(el), opts);
      var pieces = split[cfg.split];
      el._evkPrevSplit = split;
      tl = buildTimeline((pieces && pieces.length) ? pieces : [el], cfg);
    } else {
      var targets = resolveTargets(el, cfg);
      if (cfg.textFx) targets = textSafeTargets(el, targets);
      if (!targets.length) return null;
      tl = buildTimeline(targets, cfg);
    }

    if (tl) { tl.delay(cfg.delay); el._evkPrevTl = tl; }
    return tl;
  }

  /**
   * „opacity: 0\ny: 40" → { opacity: 0, y: 40 }.
   *
   * Ten sam format, co w `evk_anim_parse_props()` (includes/anim/presets.php).
   * Odpowiednik w JS jest potrzebny, bo panel ma tekst z pola, a silnik obiekt —
   * i musi być TU, przy silniku, a nie w admin.js: format należy do animacji,
   * nie do panelu. Zgodność obu parserów pilnuje tests/anim-preview.test.js,
   * porównując je na tych samych danych.
   */
  function parseProps(text) {
    var out = {};
    var n   = 0;
    String(text || '').split(/\r\n|\r|\n/).forEach(function (line) {
      if (n >= 20) return;                       // limit jak po stronie PHP
      line = line.trim();
      var i = line.indexOf(':');
      if (!line || i === -1) return;
      var prop = line.slice(0, i).trim();
      var val  = line.slice(i + 1).trim();
      if (!/^[A-Za-z][A-Za-z0-9_-]*$/.test(prop) || val === '') return;
      // is_numeric() po stronie PHP: liczba zostaje liczbą, reszta łańcuchem.
      out[prop] = (val !== '' && !isNaN(val) && !isNaN(parseFloat(val))) ? parseFloat(val) : val;
      n++;
    });
    return out;
  }

  /**
   * Odgrywa ponownie animacje wejściowe w poddrzewie.
   *
   * Woła to każdy, kto POKAZUJE treść, która wcześniej już raz weszła w kadr —
   * menu offcanvas i Circular Menu przy otwarciu. Nie buduje niczego od nowa:
   * restartuje osie zbudowane przy inicjalizacji, więc nie mnoży wyzwalaczy
   * ani nasłuchów.
   *
   * @param {number} [delay] Odstęp w sekundach między pokazaniem panelu
   *   a ruszeniem treści. Stan początkowy nakładany jest OD RAZU, a dopiero
   *   ruch czeka — samo odłożenie `restart()` zostawiłoby treść w stanie
   *   KOŃCOWYM z poprzedniego otwarcia, czyli widoczną i migającą do początku
   *   w chwili startu.
   */
  function replayIn(root, delay) {
    if (!root || typeof root.querySelectorAll !== 'function') return 0;
    delay = num(delay, 0);
    var n = 0;
    var all = [root].concat(Array.prototype.slice.call(root.querySelectorAll('*')));
    all.forEach(function (el) {
      /* Animacje „zamknięcie menu" wracają do stanu spoczynku. Bez tego treść,
         która wyszła przy poprzednim zamknięciu, zostawałaby w stanie po
         zniknięciu — przy drugim otwarciu menu byłoby puste. */
      (el._evkCloseTls || []).forEach(function (tl) { tl.pause(0); });

      (el._evkTls || []).forEach(function (tl) {
        cancelWait(tl);
        if (delay > 0) {
          tl.pause(0);
          tl._evkWait = gsap.delayedCall(delay, function () {
            tl._evkWait = null;
            tl.restart(true);
          });
          n++;
          return;
        }
        // `restart(true)` uwzględnia opóźnienie z konfiguracji — bez tego
        // sekwencja z opóźnieniami zagrałaby za drugim razem inaczej niż za
        // pierwszym, a to gorsze niż brak powtórki.
        tl.restart(true);
        n++;
      });
    });
    return n;
  }

  /**
   * Wyprowadza treść z poddrzewa i ZWRACA czas w sekundach, jakiego na to
   * potrzebuje — wołający ma o tyle odłożyć zamknięcie panelu.
   *
   * Dla każdego elementu, po kolei:
   *
   *  1. **Ma animację z wyzwalaczem „zamknięcie menu"** → gra ona.
   *  2. **Nie ma** → COFAMY wejściową. Co wjechało, wyjeżdża tą samą drogą,
   *     bez żadnej konfiguracji — o to prosi zgłoszenie („linki znikają
   *     używając mojej animacji").
   *
   * Cofnięcie działa TAKŻE po `clearProps` (patrz tweenVars): GSAP trzyma
   * wartości brzegowe w tweenach z chwili inicjalizacji i odtwarza je przy
   * renderze wstecz, mimo że inline'owy zapis transformacji zdążył zniknąć.
   * To był otwarty punkt planu — zmierzony, patrz tests/circular-menu.test.js.
   *
   * Przy redukcji ruchu `initOne()` wraca przed zbudowaniem czegokolwiek, więc
   * żaden koszyk nie istnieje i wychodzi zero: panel zamyka się natychmiast.
   */
  function exitOut(root) {
    if (!root || typeof root.querySelectorAll !== 'function') return 0;
    var longest = 0;
    var all = [root].concat(Array.prototype.slice.call(root.querySelectorAll('*')));
    all.forEach(function (el) {
      // Odłożone wejście nie może dojść do głosu w trakcie wychodzenia —
      // zagrałoby animację wejściową na zamykanym już panelu.
      (el._evkTls || []).forEach(cancelWait);

      var closers = el._evkCloseTls || [];
      if (closers.length) {
        // Wejście, które jeszcze trwa, ustępuje miejsca — dwie osie na tych
        // samych właściwościach szarpałyby się o element.
        (el._evkTls || []).forEach(function (tl) { tl.pause(); });
        closers.forEach(function (tl) {
          tl.restart(true);
          longest = Math.max(longest, tl.totalDuration());
        });
        return;
      }

      (el._evkTls || []).forEach(function (tl) {
        // Oś stojąca na zerze nic nie nałożyła — nie ma czego cofać.
        if (tl.time() <= 0) return;
        // Cofnięcie trwa dokładnie tyle, ile oś zdążyła przejechać.
        longest = Math.max(longest, tl.time());
        tl.reverse();
      });
    });
    return longest;
  }

  window.evkAnimatorReplay     = replayIn;
  window.evkAnimatorExit       = exitOut;
  window.evkAnimatorPreview    = previewPlay;
  window.evkAnimatorParseProps = parseProps;
})();
