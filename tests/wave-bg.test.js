/**
 * Wave Background — palety kolorów.
 *
 * Najważniejsze sprawdzenie jest tu regresyjne: element bez ustawień musi
 * dawać DOKŁADNIE te kolory co przed dodaniem palet. Gotowa paleta jako
 * domyślna przemalowałaby wszystkie tła już wstawione na strony, a zauważyłby
 * to dopiero ktoś, kto wejdzie na gotową podstronę.
 *
 * Kolory czytamy z wyjścia prawdziwego render() (tests/php/wave-bg-colors.php),
 * nie z regexa po źródle — regex sprawdzałby naszą interpretację pliku.
 */

const { phpOutput, barwyZrzutu, pikseleZPng, ROOT } = require('./lib/harness');

const HEX = /^#[0-9a-fA-F]{6}$/;
const run = (settings) => JSON.parse(phpOutput('wave-bg-colors.php', JSON.stringify(JSON.stringify(settings))));

module.exports = async function (t) {
  t.section('palety Wave Background');

  // Zestaw sprzed dodania palet — wpisany wprost, bo właśnie o niezmienność
  // wobec niego chodzi. Odczyt z tego samego źródła co kod nie dowiódłby niczego.
  const BEFORE = ['#F2E6DB', '#71D9E9', '#8c3dd0', '#D03F83', '#F43FF9', '#8c3dd0'];

  const bare = run({});
  t.check('element bez ustawień ma kolory jak dotąd',
    JSON.stringify(bare.colors) === JSON.stringify(BEFORE), bare.colors && bare.colors.join(' '));

  t.check('lista palet niepusta', bare.palettes.length >= 5, bare.palettes.join(', '));
  t.check('„własne" jest pierwsze na liście', bare.palettes[0] === 'custom', bare.palettes[0]);

  // Każda paleta musi dać sześć poprawnych kolorów — shader czyta uColor[6]
  // i brakująca pozycja zostawiłaby w gradiencie czerń.
  const broken = bare.palettes.filter((key) => {
    const c = run({ palette: key }).colors;
    return !c || c.length !== 6 || !c.every((v) => HEX.test(v));
  });
  t.check('każda paleta daje sześć poprawnych kolorów', !broken.length,
    broken.join(', ') || bare.palettes.length + ' palet');

  // Gotowa paleta wygrywa z pickerami — te są wtedy w panelu ukryte.
  const named = run({ palette: 'ocean', color_1: { hex: '#ff0000' } });
  t.check('gotowa paleta wygrywa z pickerem', named.colors[0] !== '#ff0000', named.colors[0]);

  const custom = run({ palette: 'custom', color_1: { hex: '#ff0000' } });
  t.check('„własne" czyta pickery', custom.colors[0] === '#ff0000', custom.colors[0]);

  // Paleta zapisana kiedyś, a dziś nieistniejąca (zmiana nazwy klucza) nie może
  // zostawić gradientu bez kolorów — wracamy do pickerów.
  const ghost = run({ palette: 'nie-ma-takiej', color_1: { hex: '#00ff00' } });
  t.check('nieznana paleta wraca do pickerów', ghost.colors[0] === '#00ff00', ghost.colors[0]);

  /* ── Maska: rampa po krzywej, nie po prostej ──────────────────────────
   *
   * Zgłoszone z użycia: maska górna ma mieć łagodniejsze przejście. Dwa
   * przystanki — `transparent 0%` i `#000 X%` — dają alfę rosnącą LINIOWO,
   * a wtedy przyrost jest najszybszy dokładnie tam, gdzie zanikanie się
   * zaczyna i kończy: w obu tych miejscach widać szew.
   *
   * Krzywa `t²(3−2t)` startuje i kończy ze zboczem zerowym, więc wchodzi
   * w sąsiedztwo bez załamania. W POŁOWIE drogi jest identyczna z prostą,
   * co jest tu istotne: zanikanie nie robi się ani krótsze, ani dłuższe —
   * zmienia się wyłącznie jego kształt. Dlatego mierzymy ĆWIARTKĘ, bo tylko
   * tam prosta i krzywa się rozjeżdżają.
   */
  // ── Wydajność (1.151.0) ────────────────────────────────────────────────
  t.section('fala nie liczy telefonu na śmierć');

  /* ZGŁOSZONE Z UŻYCIA wraz z raportem PageSpeed: 22 330 ms blocking time,
     42 s do interaktywności. Zmierzone w fixturze przy dławieniu procesora 4×
     i gęstości pikseli 2: mediana klatki 133,4 ms, czyli osiem klatek na sekundę
     przez cały czas oglądania strony. */

  const cfg = (ust) => JSON.parse(phpOutput('wave-bg-colors.php',
    JSON.stringify(JSON.stringify(ust)) + ' cfg'));

  const domyslne = cfg({});
  t.check('sufit gęstości pikseli domyślnie wynosi 1',
    domyslne.pixelRatioCap === 1, String(domyslne.pixelRatioCap));
  /* Wartość idzie wprost do `setPixelRatio()`. Zero dałoby płótno zerowego
     rozmiaru, a bardzo duża — takie, którego przeglądarka nie zaalokuje. */
  t.check('i jest ograniczony z obu stron',
    cfg({ pixel_ratio_cap: 0 }).pixelRatioCap === 0.5
      && cfg({ pixel_ratio_cap: 99 }).pixelRatioCap === 3,
    cfg({ pixel_ratio_cap: 0 }).pixelRatioCap + ' … ' + cfg({ pixel_ratio_cap: 99 }).pixelRatioCap);
  t.check('ale wartość z panelu przechodzi bez zmian',
    cfg({ pixel_ratio_cap: 1.5 }).pixelRatioCap === 1.5,
    String(cfg({ pixel_ratio_cap: 1.5 }).pixelRatioCap));

  /* Lista wyboru, nie pole zaznaczenia — pole z domyślnym `true` jest
     w Bricksie nieodróżnialne od nietkniętego, więc albo nie da się go
     wyłączyć, albo domyślna nie działa. */
  t.check('zatrzymywanie poza ekranem jest domyślnie włączone',
    domyslne.pauseOffscreen === true, String(domyslne.pauseOffscreen));
  t.check('i daje się wyłączyć wprost',
    cfg({ pause_offscreen: 'nie' }).pauseOffscreen === false,
    String(cfg({ pause_offscreen: 'nie' }).pauseOffscreen));

  t.check('bufor rysowania domyślnie wyłączony',
    domyslne.preserveBuffer === false, String(domyslne.preserveBuffer));
  t.check('a kontrolka go włącza',
    cfg({ preserve_buffer: true }).preserveBuffer === true,
    String(cfg({ preserve_buffer: true }).preserveBuffer));

  /* Ustawienie w CONFIG-u nic nie znaczy, jeśli moduł go nie czyta. Do 1.151.0
     stały tam trzy wartości wpisane na sztywno. */
  const zrodlo = phpOutput('wave-bg-colors.php', JSON.stringify(JSON.stringify({})) + ' html');
  t.check('i moduł naprawdę czyta te trzy ustawienia',
    zrodlo.includes('CONFIG.pixelRatioCap') && zrodlo.includes('CONFIG.preserveBuffer')
      && zrodlo.includes('CONFIG.pauseOffscreen'),
    'trzy odwołania do CONFIG');
  t.check('a twardej dwójki i twardego bufora już nie ma',
    !/setPixelRatio\(Math\.min\(window\.devicePixelRatio, 2\)\)/.test(zrodlo)
      && !/preserveDrawingBuffer:\s*true/.test(zrodlo),
    'brak wartości wpisanych na sztywno');

  // ── Jakość dopasowana do urządzenia (1.152.0) ──────────────────────────
  t.section('fala schodzi z jakości, gdy sprzęt nie nadąża');

  t.check('dopasowanie jakości domyślnie włączone',
    domyslne.autoJakosc === true, String(domyslne.autoJakosc));
  t.check('i daje się wyłączyć wprost',
    cfg({ auto_jakosc: 'nie' }).autoJakosc === false,
    String(cfg({ auto_jakosc: 'nie' }).autoJakosc));
  t.check('budżet klatki domyślnie 40 ms — pod progiem długiego zadania',
    domyslne.budzetKlatki === 40 && domyslne.budzetKlatki < 50,
    domyslne.budzetKlatki + ' ms');
  t.check('i jest ograniczony z obu stron',
    cfg({ budzet_klatki: 1 }).budzetKlatki === 20 && cfg({ budzet_klatki: 9999 }).budzetKlatki === 200,
    cfg({ budzet_klatki: 1 }).budzetKlatki + ' … ' + cfg({ budzet_klatki: 9999 }).budzetKlatki);

  // ── Zatrzymanie poza ekranem, w prawdziwej przeglądarce ────────────────
  t.section('poza ekranem fala przestaje liczyć');

  /**
   * Mediana odstępu klatek WŁASNEJ pętli obserwacyjnej fixtura — czyli miara
   * tego, ile wolnego zostaje wątkowi głównemu.
   *
   * Mierzymy tak, a nie licząc klatki fali, bo to właśnie zajęty wątek główny
   * jest usterką: Lighthouse liczy Total Blocking Time z zadań dłuższych niż
   * 50 ms, a nie z tego, ile razy przerysowało się płótno.
   */
  const zajetosc = async (ust) => {
    /* DRABINA JAKOŚCI WYŁĄCZONA — ten blok bada pauzę, nie jakość. Zmierzone:
       przy włączonej drabinie element schodził o szczebel w trakcie pomiaru,
       więc okno „po przewinięciu" wychodziło szybsze niezależnie od tego, czy
       pauza w ogóle działa. Dwie zmienne naraz nie dają się rozdzielić. */
    const html = phpOutput('wave-bg-colors.php',
      JSON.stringify(JSON.stringify(Object.assign({ auto_jakosc: 'nie' }, ust))) + ' html');
    const str = await t.open('wave-bg-pomiar.html', {
      przezHttp: true,
      viewport: { width: 412, height: 915 },
      dpr: 2,
      dlawienieCPU: 4,
      head: 'window.__tresc = ' + JSON.stringify(html) + ';',
      settle: 200,
    });
    await str.evaluate(() => window.__start());
    await str.waitForTimeout(2500);          // start modułu i kompilacja shaderów

    await str.evaluate(() => window.__zerujKlatki());
    await str.waitForTimeout(2500);
    const widoczna = await str.evaluate(() => window.__medianaKlatki());

    await str.evaluate(() => window.scrollTo(0, 3000));
    await str.waitForTimeout(600);
    await str.evaluate(() => window.__zerujKlatki());
    await str.waitForTimeout(2500);
    const poza = await str.evaluate(() => window.__medianaKlatki());

    const plotno = await str.evaluate(() => !!document.querySelector('#scena canvas'));
    const bledy  = str.errors.filter((e) => !/favicon/.test(e));
    await str.close();
    return { widoczna, poza, plotno, bledy };
  };

  const zPauza = await zajetosc({});
  /* Bez tego sprawdzenia cała reszta przechodziłaby także wtedy, gdyby moduł
     w ogóle nie wystartował — a wtedy „wątek główny wolny" jest prawdą
     z najgorszego możliwego powodu. */
  t.check('element naprawdę wystartował', zPauza.plotno && zPauza.bledy.length === 0,
    zPauza.bledy.join(' | ') || 'płótno jest, konsola czysta');
  t.check('gdy fala jest widoczna, wątek główny jest zajęty',
    zPauza.widoczna > 25, zPauza.widoczna + ' ms na klatkę');
  t.check('a po wyjściu poza ekran zwalnia się',
    zPauza.poza < zPauza.widoczna * 0.7,
    zPauza.widoczna + ' → ' + zPauza.poza + ' ms');

  /* KONTROLA NEGATYWNA. Bez niej sprawdzenie wyżej przechodziłoby także dla
     kodu, który zwalnia z innego powodu — na przykład dlatego, że po
     przewinięciu przeglądarka i tak mniej maluje. */
  const bezPauzy = await zajetosc({ pause_offscreen: 'nie' });
  t.check('z wyłączonym zatrzymywaniem nie zwalnia',
    bezPauzy.poza > bezPauzy.widoczna * 0.7,
    bezPauzy.widoczna + ' → ' + bezPauzy.poza + ' ms');

  // ── Biblioteki z własnego serwera (1.156.0) ────────────────────────────
  t.section('nic nie leci z cudzego CDN-u');

  /* Do 1.155.1 element importował three.js i GSAP-a z esm.sh: 11 żądań, dwa
     poziomy przekierowań i adres IP każdego odwiedzającego wysyłany na obcy
     serwer. Sprawdzenie jest na ŹRÓDLE, nie na wyjściu — adres wpisany
     w komentarzu albo w martwej gałęzi też ma zapalić. */
  const zrodloElementu = require('fs').readFileSync(
    require('path').join(ROOT, 'includes/bricks-elements/evoke-wave-bg/element.php'), 'utf8');
  const kod = zrodloElementu.replace(/\/\*[\s\S]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');
  t.check('w kodzie elementu nie ma adresu do esm.sh',
    !/esm\.sh/.test(kod), (kod.match(/https?:\/\/[^'"\s]*/g) || ['brak adresów zewnętrznych'])[0]);
  t.check('paczka three.js leży w repozytorium',
    require('fs').existsSync(require('path').join(ROOT, 'assets/vendor/three/0.185.1/evoke-three.min.js')),
    'assets/vendor/three/0.185.1/evoke-three.min.js');
  /* Paczka ma być SAMOWYSTARCZALNA — inaczej przeglądarka poprosi o plik,
     którego nie wozimy, i wróci zależność od cudzego serwera tylnymi drzwiami. */
  const paczka = require('fs').readFileSync(
    require('path').join(ROOT, 'assets/vendor/three/0.185.1/evoke-three.min.js'), 'utf8');
  /* JEDNA KOPIA GSAP-a NA STRONIE. Element deklaruje to jedynym miejscem —
     `enqueue_scripts()`. Fixture przeglądarkowy ładuje GSAP-a sam (tak jak robi
     to strona), więc zdjęcie tej metody nie zapaliłoby tam NICZEGO: sprawdzamy
     więc kolejkę WordPressa, a nie stronę. */
  const kolejka = JSON.parse(phpOutput('wave-bg-colors.php',
    JSON.stringify(JSON.stringify({})) + ' skrypty'));
  t.check('element prosi o wspólnego GSAP-a, zamiast wozić własnego',
    kolejka.enqueued.includes('evk-gsap'),
    'w kolejce: ' + (kolejka.enqueued.join(', ') || 'nic'));

  t.check('i nie dociąga niczego dalej',
    !/\bfrom\s*["'][^"']+["']/.test(paczka),
    (paczka.match(/from\s*["'][^"']+["']/g) || ['zero importów']).join(', '));

  // ── Brak akceleracji sprzętowej (1.153.0, przebudowane w 1.154.0) ──────
  t.section('bez GPU fala nie pobiera three.js');

  /* ZGŁOSZONE PORÓWNANIEM A/B NA ŻYWEJ STRONIE: przy włączonej fali praca wątku
     głównego wynosiła 9,1 s, przy wyłączonej 1,3 s — a kategoria „Other" 7119
     wobec 200 ms. Drabina jakości z 1.152.0 zbiła TBT ośmiokrotnie, ale
     zejście pod próg długiego zadania to NIE jest to samo, co przestać
     obciążać procesor.

     1.153.0 zatrzymywało falę na jednym kadrze — po ZAŁADOWANIU 287 KB three.js,
     kompilacji shaderów i zbudowaniu sceny. 1.154.0 pyta sterownik o akcelerację
     PRZED importem, więc na takiej maszynie nie pobiera bibliotek wcale i rysuje
     zastępnik: własny obraz albo gradient CSS z palety.

     Chromium w testach rasteryzuje programowo (SwiftShader) — czyli jest
     dokładnie tą maszyną, dla której ta ścieżka powstała. */
  const bezGpu = async (ust, opcje) => {
    opcje = opcje || {};
    const html = phpOutput('wave-bg-colors.php', JSON.stringify(JSON.stringify(ust)) + ' html');
    const str = await t.open('wave-bg-pomiar.html', {
      przezHttp: true,
      viewport: { width: 1350, height: 940 },
      dlawienieCPU: 4,
      head: 'window.__tresc = ' + JSON.stringify(html) + ';',
      query: 'evk-wave-debug=1',
      settle: 200,
      reduce: !!opcje.ograniczRuch,
    });
    const log = [];
    str.on('console', (m) => { if (m.text().includes('[EVK Wave]')) log.push(m.text()); });

    /* Licznik żądań, bo o to w tym wydaniu chodzi: biblioteki mają się NIE
       pobrać. Podpięty po `open()`, ale przed `__start()` — moduł wstawia się
       dopiero tam, więc nic nam nie ucieknie. */
    const zadania = { biblioteki: [], gsap: [], obraz: [] };
    str.on('request', (r) => {
      const u = r.url();
      if (/evoke-three/.test(u))                  zadania.biblioteki.push(u);
      /* GSAP osobno: od 1.156.0 element go NIE POBIERA — bierze `window.gsap`
         postawiony przez stronę. Fixture ładuje go przed `__start()`, więc to,
         co tu wpadnie, jest żądaniem samego modułu. */
      if (/gsap/.test(u))                         zadania.gsap.push(u);
      if (/obraz-zastepczy\.svg/.test(u))         zadania.obraz.push(u);
    });

    if (opcje.ukryjSterownik)    await str.evaluate(() => window.__ukryjSterownik());
    if (opcje.ukryjSterownikRaz) await str.evaluate(() => window.__ukryjSterownikRaz(1));
    if (opcje.bezWebGL)          await str.evaluate(() => window.__wylaczWebGL());
        /* 1500 ms, a nie 700: paczka three.js waży pół megabajta i samo jej
       pobranie trwa dłużej niż krótkie opóźnienie — GSAP wracał, zanim
       czekanie miało co robić, i mutacja zdejmująca je przechodziła. */
    if (opcje.gsapPozniej)       await str.evaluate(() => window.__gsapPozniej(1500));
    /* Odcięcie bibliotek — udaje serwer, który nie oddaje plików three.js
       (uszkodzone wdrożenie, blokada, literówka w adresie). */
    if (opcje.blokujBiblioteki) {
      await str.route('**/assets/vendor/three/**', (r) => r.abort());
    }
    await str.evaluate(() => window.__start());
    await str.waitForTimeout(4000);
    await str.evaluate(() => window.__zerujKlatki());
    await str.waitForTimeout(2500);
    const wolnyWatek = await str.evaluate(() => window.__medianaKlatki());

    const stan = await str.evaluate(() => {
      const el = document.querySelector('#scena [id^="evk-wb"]');
      const c = document.querySelector('#scena canvas');
      return {
        plotno:    !!c,
        znacznik:  el ? el.getAttribute('data-evk-wb-zastepnik') : null,
        tlo:       el ? getComputedStyle(el).backgroundImage : '',
        /* Uchwyt debugowy — jedyne, co pozwala porównywać zrzuty przy USTALONEJ
           chwili animacji. Bez niego kadr zależy od tego, ile klatek zdążyło
           wypaść, i porównanie zrzutów jest bezwartościowe (zmierzone: dwa
           przebiegi tego samego kodu różniły się średnio o 10 poziomów). */
        uchwyt:    !!window.__evkWave,
        /* Czy płótno ZACHOWUJE narysowaną zawartość. Przy jednym kadrze i
           `preserveDrawingBuffer:false` przeglądarce wolno je wyczyścić zaraz
           po wyświetleniu — i nic go już nie odrysuje. */
        bufor: (() => {
          try { const gl = c && (c.getContext('webgl2') || c.getContext('webgl'));
                return gl ? gl.getContextAttributes().preserveDrawingBuffer : null; }
          catch (e) { return null; }
        })(),
      };
    });

    /* Piksele, nie łańcuch w arkuszu. Sprawdzanie samego `backgroundImage`
       byłoby czytaniem własnego zapisu — a to jest dokładnie ten rodzaj
       sprawdzenia, który w 1.152.0 przepuścił nieruchomy kadr o zerowym
       kryciu, czyli obraz, którego NIE BYŁO WIDAĆ. */
    /* `ustalKadr` zdejmuje zależność od czasu tam, gdzie ona przeszkadza.
       Wejście fali trwa 2,5 s i przy opóźnionym GSAP-ie potrafi się nie zmieścić
       przed zrzutem — pod obciążeniem pełnego zestawu sprawdzenie „kadr nie jest
       pusty" padało, choć w izolacji przechodziło trzy razy z rzędu na tej samej
       liczbie. To był za ciasny margines w teście, nie usterka: drabina jakości
       dochodzi do zamrożenia i kadr ZACHOWUJE treść (sprawdzone osobno, 6333
       barwy także dziesięć sekund później). */
    if (opcje.ustalKadr) {
      await str.evaluate(() => window.__kadr && window.__kadr(3.7));
      await str.waitForTimeout(250);
    }
    let barwy = null, zrzut = null;
    try {
      /* Surowy PNG zostaje, gdy wołający o niego prosi — sprawdzenia ziarna
         liczą piksele W WYBRANYM OBSZARZE, a `barwyZrzutu` zbiera cały kadr
         i różnicy w narożnikach by nie pokazało. */
      const png = await str.locator('#scena').screenshot();
      barwy = barwyZrzutu(png);
      if (opcje.zrzut) zrzut = png;
    } catch (e) { barwy = { blad: e.message }; }

    await str.close();
    return { log, wolnyWatek, zadania, ...stan, barwy, zrzut };
  };

  const naSofcie = await bezGpu({});
  t.check('rozpoznaje renderowanie programowe',
    naSofcie.log.some((l) => l.includes('brak akceleracji')),
    naSofcie.log[0] || 'brak komunikatu');
  /* SEDNO WYDANIA. Do 1.153.0 te 287 KB szły na łącze zawsze — także na maszynę,
     która i tak zobaczy jeden nieruchomy kadr. */
  t.check('nie pobiera three.js',
    naSofcie.zadania.biblioteki.length === 0,
    naSofcie.zadania.biblioteki.length + ' żądań');
  t.check('nie stawia płótna WebGL',
    naSofcie.plotno === false, 'płótno: ' + naSofcie.plotno);
  t.check('i oddaje wątek główny',
    naSofcie.wolnyWatek !== null && naSofcie.wolnyWatek < 25,
    naSofcie.wolnyWatek + ' ms na klatkę');
  t.check('oznacza się znacznikiem zastępnika',
    naSofcie.znacznik === '1', 'data-evk-wb-zastepnik: ' + naSofcie.znacznik);
  /* NAJWAŻNIEJSZE SPRAWDZENIE — i to na pikselach, nie na łańcuchu w arkuszu.
     `uAlpha` startuje od zera, więc w 1.152.0 „narysowany" nieruchomy kadr był
     w rzeczywistości niewidoczny, a sprawdzenie na samych ustawieniach tego nie
     złapało. Płaskie tło daje jedną barwę i rozrzut zero; gradient — setki. */
  t.check('a zastępnik NAPRAWDĘ coś maluje',
    naSofcie.barwy.barw > 200 && naSofcie.barwy.rozrzut > 60,
    naSofcie.barwy.barw + ' barw, rozrzut ' + naSofcie.barwy.rozrzut);
  /* Same trójki liczb, bez `rgb(`: warstwy z kryciem poniżej pełnego
     przeglądarka wypisuje jako `rgba(...)`, więc dopasowanie do `rgb(` łapałoby
     tylko te nieprzezroczyste i milczałoby o zgubieniu całej reszty palety. */
  t.check('gradient bierze kolory z palety elementu',
    ['244, 63, 249', '113, 217, 233', '242, 230, 219']
      .every((barwa) => naSofcie.tlo.includes(barwa)),
    naSofcie.tlo.slice(0, 60) + '…');

  /* KONTROLA NEGATYWNA. Bez niej wszystko powyżej przechodziłoby także dla
     elementu, który w ogóle nie wystartował — „nic nie pobrano" i „wątek wolny"
     są wtedy prawdziwe z najgorszego możliwego powodu. Ze sterownikiem ukrytym
     przed odczytem element nie ma prawa zgadywać: ładuje biblioteki i liczy. */
  const zeSterownikiem = await bezGpu({ auto_jakosc: 'nie' });
  t.check('z wyłączonym dopasowaniem POBIERA biblioteki',
    zeSterownikiem.zadania.biblioteki.length > 0,
    zeSterownikiem.zadania.biblioteki.length + ' żądań');
  /* JEDNA KOPIA GSAP-a NA STRONIE. Do 1.156.0 element importował własną
     z esm.sh — 34 KB obok tych samych 34 KB, które wtyczka już wozi dla
     Animatora i reszty. Teraz bierze `window.gsap`, więc sam nie prosi
     o nic. */
  t.check('ale GSAP-a nie pobiera — bierze ten ze strony',
    zeSterownikiem.zadania.gsap.length === 0,
    zeSterownikiem.zadania.gsap.length + ' żądań o GSAP-a');
  t.check('z wyłączonym dopasowaniem stawia płótno',
    zeSterownikiem.plotno === true, 'płótno: ' + zeSterownikiem.plotno);
  t.check('i wystawia uchwyt do porównań zrzutów',
    zeSterownikiem.uchwyt === true, 'window.__evkWave: ' + zeSterownikiem.uchwyt);
  /* Pytamy o BRAK KOMUNIKATU O REZYGNACJI, nie o milczącą konsolę. Stało tu
     `log.length === 0`, czyli „element nie powiedział nic" — a przy
     `?evk-wave-debug=1` element mówi też o rzeczach zwyczajnych (od 1.183.0
     o chwili startu sceny). Każda nowa linia diagnostyki zapalałaby to
     sprawdzenie, choć badana rzecz — że fala NIE rezygnuje z płótna — miałaby
     się dobrze. */
  t.check('z wyłączonym dopasowaniem nadal obciąża',
    !zeSterownikiem.log.some((l) => l.includes('brak akceleracji'))
      && zeSterownikiem.wolnyWatek > 50,
    zeSterownikiem.wolnyWatek + ' ms na klatkę');

  /* DRUGA LINIA OBRONY. Probka przed importem zwraca „nie wiem" jako `false`
     — rozszerzenie z nazwą sterownika bywa wyłączone, a jednorazowe płótno może
     w ogóle nie dostać kontekstu. Wtedy biblioteki JADĄ, a rozpoznaniem zajmuje
     się kontekst, którego renderer naprawdę używa: jedna klatka i koniec.

     Bez tego sprawdzenia mutacja wyłączająca tę probkę przechodziła na zielono
     — zasłaniała ją probka wstępna, dokładnie tak jak w 1.151.0 zasłaniały się
     nawzajem dwa mechanizmy pauzy. */
  const drugaLinia = await bezGpu({}, { ukryjSterownikRaz: true });
  /* Biblioteki JADĄ — tego nie da się uniknąć, skoro probka wstępna niczego
     się nie dowiedziała. O tym, co dzieje się z płótnem, mówi sprawdzenie
     „zamrożone płótno ustępuje zastępnikowi" niżej. */
  t.check('gdy probka wstępna nic nie wie, biblioteki jadą',
    drugaLinia.zadania.biblioteki.length > 0,
    drugaLinia.zadania.biblioteki.length + ' żądań');
  t.check('ale renderer sam rozpoznaje brak akceleracji',
    drugaLinia.log.some((l) => l.includes('brak akceleracji')),
    drugaLinia.log[0] || 'brak komunikatu');
  t.check('i wątek główny zostaje wolny',
    drugaLinia.wolnyWatek !== null && drugaLinia.wolnyWatek < 25,
    drugaLinia.wolnyWatek + ' ms na klatkę');
  /* ZGŁOSZONE Z UŻYCIA: „jak wyłączam akcelerację, nie mam ani obrazka
     domyślnego, ani tego co ustawię". Zostawał tu ZAMROŻONY KADR na płótnie
     bez `preserveDrawingBuffer` — a przeglądarce wolno je wyczyścić zaraz po
     wyświetleniu i nic go już nie odrysowywało. Płótno schodzi, wchodzi ten
     sam zastępnik co przy probce wstępnej. */
  t.check('a zamrożone płótno ustępuje zastępnikowi',
    drugaLinia.plotno === false && drugaLinia.znacznik === '1',
    'płótno: ' + drugaLinia.plotno + ', znacznik: ' + drugaLinia.znacznik);
  t.check('który NAPRAWDĘ coś maluje',
    drugaLinia.barwy.barw > 200 && drugaLinia.barwy.rozrzut > 60,
    drugaLinia.barwy.barw + ' barw, rozrzut ' + drugaLinia.barwy.rozrzut);

  // ── Ograniczony ruch też rysuje jeden kadr ─────────────────────────────
  t.section('przy ograniczonym ruchu kadr nie znika');

  /* TA SAMA USTERKA, DRUGIE WEJŚCIE. Przy „ogranicz ruch" fala rysuje jeden
     kadr prawdziwym rendererem — i tak samo mogła go stracić. Tu płótno ZOSTAJE
     (obraz ma być prawdziwą falą, nie przybliżeniem), więc zamiast zdejmować je
     wymuszamy zachowanie bufora. */
  const spokojnie = await bezGpu({ auto_jakosc: 'nie' }, { ograniczRuch: true, ukryjSterownik: true });
  t.check('płótno zostaje, bo to prawdziwa fala',
    spokojnie.plotno === true, 'płótno: ' + spokojnie.plotno);
  t.check('a jego zawartość jest zachowana',
    spokojnie.bufor === true, 'preserveDrawingBuffer: ' + spokojnie.bufor);
  t.check('i w kadrze NAPRAWDĘ coś widać',
    spokojnie.barwy.barw > 200 && spokojnie.barwy.rozrzut > 60,
    spokojnie.barwy.barw + ' barw, rozrzut ' + spokojnie.barwy.rozrzut);
  t.check('bez animacji — wątek główny wolny',
    spokojnie.wolnyWatek !== null && spokojnie.wolnyWatek < 25,
    spokojnie.wolnyWatek + ' ms na klatkę');

  /* WEBGL WYŁĄCZONY CAŁKOWICIE — Firefox z `webgl.disabled`, Safari
     z odznaczonym WebGL-em. Nie ma wtedy kontekstu, więc nie ma kogo pytać
     o nazwę sterownika, a `evkWbBezAkceleracji()` z założenia odpowiada wtedy
     „nie wiem". Bez osobnego warunku element pobierał 287 KB tylko po to, żeby
     wywrócić się na `new THREE.WebGLRenderer()` i zostawić puste miejsce. */
  const bezWebGL = await bezGpu({}, { bezWebGL: true });
  t.check('przy wyłączonym WebGL nie pobiera bibliotek',
    bezWebGL.zadania.biblioteki.length === 0,
    bezWebGL.zadania.biblioteki.length + ' żądań');
  t.check('i rysuje zastępnik zamiast pustego miejsca',
    bezWebGL.znacznik === '1' && bezWebGL.barwy.barw > 200,
    'znacznik: ' + bezWebGL.znacznik + ', ' + bezWebGL.barwy.barw + ' barw');

  /* GSAP PRZYCHODZI PÓŹNIEJ NIŻ MODUŁ. Element czeka na `window.gsap` zamiast
     zakładać, że skrypt ze stopki zdążył. Bez tego sprawdzenia mutacja
     zdejmująca to czekanie przechodziła na zielono — w fixturze GSAP jest
     zawsze na miejscu, więc nie było czego łapać. */
  const gsapZOpoznieniem = await bezGpu({}, { ukryjSterownik: true, gsapPozniej: true, ustalKadr: true });
  /* PŁÓTNO NIE WYSTARCZA. Konstruktor wstawia je zanim dojdzie do `gsap.quickTo`,
     więc przy nieudanym starcie też tam jest — pytamy dodatkowo o BRAK znacznika
     zastępnika, bo to on odróżnia „fala jedzie" od „fala padła i zastąpiliśmy ją". */
  t.check('poczeka na GSAP-a i mimo to wystartuje',
    gsapZOpoznieniem.plotno === true && gsapZOpoznieniem.znacznik !== '1',
    'płótno: ' + gsapZOpoznieniem.plotno + ', zastępnik: ' + gsapZOpoznieniem.znacznik);
  t.check('a kadr nie jest pusty',
    gsapZOpoznieniem.barwy.barw > 200,
    gsapZOpoznieniem.barwy.barw + ' barw');

  // ── Awaria cudzego CDN-a (1.154.0) ─────────────────────────────────────
  t.section('gdy bibliotek nie da się pobrać, zostaje zastępnik');

  /* Element ładuje three.js i GSAP-a z esm.sh. Serwis bywa niedostępny —
     awaria, blokada w sieci firmowej, filtr. Do 1.154.0 kończyło się to pustym
     miejscem w układzie strony: statyczny import przewracał cały moduł.
     Sprawdzamy sprzęt Z AKCELERACJĄ (sterownik ukryty), żeby element naprawdę
     próbował pobrać biblioteki, a nie poszedł ścieżką „bez GPU". */
  const bezCdn = await bezGpu({}, { ukryjSterownik: true, blokujBiblioteki: true });
  t.check('element nie znika, tylko rysuje zastępnik',
    bezCdn.znacznik === '1', 'data-evk-wb-zastepnik: ' + bezCdn.znacznik);
  t.check('a zastępnik NAPRAWDĘ coś maluje',
    bezCdn.barwy.barw > 200 && bezCdn.barwy.rozrzut > 60,
    bezCdn.barwy.barw + ' barw, rozrzut ' + bezCdn.barwy.rozrzut);
  t.check('płótna WebGL nie ma, bo nie było z czego',
    bezCdn.plotno === false, 'płótno: ' + bezCdn.plotno);

  // ── Własny obraz zamiast gradientu (1.154.0) ───────────────────────────
  t.section('zastępnik może być własnym obrazem');

  const OBRAZ = { _zalaczniki: { 7: '/tests/fixtures/obraz-zastepczy.svg' },
                  zastepnik_obraz: { id: 7 } };

  const zObrazem = await bezGpu(OBRAZ);
  t.check('obraz wygrywa z gradientem',
    /obraz-zastepczy\.svg/.test(zObrazem.tlo) && !/gradient/.test(zObrazem.tlo),
    zObrazem.tlo.slice(0, 70));
  t.check('i naprawdę zostaje pobrany',
    zObrazem.zadania.obraz.length > 0,
    zObrazem.zadania.obraz.length + ' żądań');
  t.check('a bibliotek dalej nie ma',
    zObrazem.zadania.biblioteki.length === 0,
    zObrazem.zadania.biblioteki.length + ' żądań');

  /* NA MASZYNIE Z GPU OBRAZ MA SIĘ NIE POBRAĆ. Adres jedzie w konfiguracji
     zawsze — gdyby `background-image` ustawiać bezwarunkowo, każdy odwiedzający
     ściągałby plik, którego nigdy nie zobaczy. Sterownik ukrywamy, żeby element
     poszedł ścieżką „jest akceleracja". */
  const obrazZeSprzetem = await bezGpu(OBRAZ, { ukryjSterownik: true });
  t.check('z akceleracją obraz NIE jest pobierany',
    obrazZeSprzetem.zadania.obraz.length === 0,
    obrazZeSprzetem.zadania.obraz.length + ' żądań');
  t.check('a fala rusza normalnie',
    obrazZeSprzetem.plotno === true && obrazZeSprzetem.zadania.biblioteki.length > 0,
    'płótno: ' + obrazZeSprzetem.plotno + ', bibliotek: ' + obrazZeSprzetem.zadania.biblioteki.length);

  // ── Wyjście render() musi być poprawnym JavaScriptem ───────────────────
  /* KLASA BŁĘDU, KTÓRA UGRYZŁA JUŻ DWA RAZY.
     Moduł elementu powstaje w PHP-ie jako jeden wielki literał, a shadery
     siedzą w nim w literałach szablonowych JS-a. Znak użyty w KOMENTARZU
     potrafi więc zamknąć literał w połowie zdania i wywalić cały moduł —
     raz zrobił to prosty cudzysłów w `includes/96-lenis.php`, raz odwrotny
     apostrof w komentarzu do ziarna.

     Z przeglądarki widać wtedy wyłącznie objaw („element nie wystartował"),
     a nie przyczynę. Parser odpowiada wprost i na każdej zmianie tego pliku,
     bez stawiania przeglądarki. */
  t.section('moduł elementu parsuje się jako JavaScript');

  const { execFileSync } = require('child_process');
  const os = require('os');
  const fs = require('fs');
  const path = require('path');

  const modul = (ust) => {
    const html = phpOutput('wave-bg-colors.php', JSON.stringify(JSON.stringify(ust)) + ' html');
    const m = html.match(/<script type="module">([\s\S]*?)<\/script>/);
    if (!m) return { blad: 'nie znalazłem modułu w wyjściu render()' };
    const plik = path.join(os.tmpdir(), 'evk-wave-' + process.pid + '-' + Math.random().toString(36).slice(2) + '.mjs');
    try {
      fs.writeFileSync(plik, m[1]);
      execFileSync(process.execPath, ['--check', plik], { stdio: 'pipe' });
      return { ok: true, znakow: m[1].length };
    } catch (e) {
      return { ok: false, blad: String(e.stderr || e.message).split('\n').slice(0, 3).join(' ') };
    } finally { try { fs.unlinkSync(plik); } catch (e) {} }
  };

  /* Oba ustawienia ziarna, bo gałąź rozlania jest w shaderze pod warunkiem —
     a literał szablonowy psuje się niezależnie od tego, czy gałąź się wykona. */
  const mBez = modul({ noise_enabled: true, noise_spread: 0 });
  const mZ   = modul({ noise_enabled: true, noise_spread: 1 });

  t.check('przy ziarnie bez rozlania', mBez.ok === true, mBez.blad || mBez.znakow + ' znaków');
  t.check('i z rozlaniem', mZ.ok === true, mZ.blad || mZ.znakow + ' znaków');

  // ── Ziarno poza falą ───────────────────────────────────────────────────
  /* ZGŁOSZONE Z UŻYCIA: „może dodać opcję, żeby rozszerzyć ziarno na całą
     szerokość okna, a nie tylko nad falą".

     Ziarno NIE BYŁO przycięte do fali — było przemnożone przez jej
     przezroczystość: shader siatki wygasza falę ku krawędziom, a przebieg
     post-process dosypywał ziarno wyłącznie do BARWY i przepuszczał tę alphę
     bez zmian. Ziarno liczyło się więc na całym kadrze i nie miało czym się
     pokazać tam, gdzie fala jest przezroczysta.

     MIERZYMY NAROŻNIKI, bo tam fala nie sięga — `pow(sin(vUv.x*PI), uPow)`
     jest przy krawędziach zerem. Pomiar w środku kadru nie odróżniłby niczego:
     tam ziarno było widać zawsze. */
  t.section('ziarno wychodzi poza falę, gdy się je o to poprosi');

  /**
   * SZORSTKOŚĆ kadru: średnia różnica między sąsiadującymi pikselami.
   *
   * PIERWSZA WERSJA MIERZYŁA ROZRZUT W NAROŻNIKACH i była oparta na złym
   * założeniu — że fala tam nie sięga. Sięga: przy `heightMultiplier: 2`
   * wypełnia kadr w pionie, a rozrzut w narożniku wychodził 40 przy ZEROWYM
   * rozlaniu, czyli miara mówiła o gradiencie fali, nie o ziarnie.
   *
   * Sąsiedztwo rozdziela jedno od drugiego bez zgadywania, gdzie fala jest:
   * gradient zmienia się GŁADKO, więc różnica między sąsiadami jest bliska
   * zeru niezależnie od tego, jak bardzo barwy różnią się przez cały kadr.
   * Ziarno jest z definicji wysokoczęstotliwościowe i tę różnicę podnosi.
   *
   * Dlatego mierzymy CAŁY kadr, a nie wybrany kawałek: przy zerowym rozlaniu
   * ziarno jest tylko tam, gdzie fala jest nieprzezroczysta, przy pełnym —
   * wszędzie. Średnia po całości musi więc urosnąć.
   */
  const szorstkosc = (buf) => {
    const { szer, wys, kanaly, dane } = pikseleZPng(buf);
    let suma = 0, prob = 0;
    for (let y = 0; y < wys; y += 2) {
      const w = y * szer * kanaly;
      for (let x = 0; x < szer - 1; x++) {
        suma += Math.abs(dane[w + x * kanaly] - dane[w + (x + 1) * kanaly]);
        prob++;
      }
    }
    return prob ? Math.round((suma / prob) * 100) / 100 : null;
  };

  /* DRABINA JAKOŚCI MUSI BYĆ WYŁĄCZONA i to nie jest ułatwianie sobie pomiaru,
     tylko warunek, żeby w ogóle było co mierzyć. `rysujRaz()` woła composer
     WYŁĄCZNIE na poziomie zerowym:

         if (this.poziom === 0) this.composer.render();
         else                   this.renderer.render(this.scene, this.camera);

     a ziarno siedzi w przebiegu post-process, czyli właśnie w composerze.
     Chromium w testach rasteryzuje programowo, więc drabina schodzi tu o trzy
     szczeble w kilka sekund — i pierwsza wersja tego pomiaru mierzyła scenę
     BEZ ZIARNA w każdym z trzech wariantów, pokazując zgodnie 0,2 szorstkości.
     Wyglądało to jak „rozlanie nie działa", a znaczyło „nie ma czego rozlewać".

     To zresztą ta sama drabina, która na słabej maszynie zdejmuje dziś ziarno
     razem ze zniekształceniem — patrz `obnizJakosc()`. */
  const zrzutZiarna = (spread) => bezGpu(
    Object.assign({ noise_enabled: true, auto_jakosc: 'nie' },
      spread === null ? {} : { noise_spread: spread }),
    { ukryjSterownik: true, ustalKadr: true, zrzut: true });

  /* Trzy przebiegi. `null` to STARY KSZTAŁT USTAWIEŃ — bez klucza `noise_spread`
     w ogóle, czyli dokładnie to, co siedzi dziś w bazach żywych stron. */
  const zBrak  = await zrzutZiarna(null);
  const zZero  = await zrzutZiarna(0);
  const zJeden = await zrzutZiarna(1);

  const sBrak  = szorstkosc(zBrak.zrzut);
  const sZero  = szorstkosc(zZero.zrzut);
  const sJeden = szorstkosc(zJeden.zrzut);

  /* ZGODNOŚĆ WSTECZNA I NAJWAŻNIEJSZE SPRAWDZENIE TEJ ZMIANY. Brak ustawienia
     ma znaczyć dokładnie to samo co zero — inaczej aktualizacja zmieniłaby
     wygląd wszystkim, którzy o nic nie prosili. Porównujemy statystykę, a nie
     piksele: `uNoiseSeed` losuje się co klatkę, więc dwa zrzuty tego samego
     ustawienia NIGDY nie są identyczne bajt w bajt. */
  t.check('brak ustawienia znaczy to samo co zero', Math.abs(sBrak - sZero) < 1.0,
    'szorstkość ' + sBrak + ' vs ' + sZero);
  /* KONTROLA NEGATYWNA powyższego: gdyby rozlanie nie działało wcale, wszystkie
     trzy liczby byłyby takie same i sprawdzenie wyżej też by przeszło. */
  t.check('a rozlanie dosypuje ziarna poza falę', sJeden > sZero + 1.0,
    'szorstkość ' + sZero + ' → ' + sJeden);
  /* Ziarno, nie placki. Barwa NIEprzemnożona przez alphę rozjaśniłaby drobiny
     kilkunastokrotnie — i to jest ten błąd, przed którym broni mnożenie
     w shaderze, bo renderer stoi na domyślnym premultiplied alpha. */
  t.check('i nie zamienia kadru w śnieg', sJeden < sZero + 40,
    'szorstkość ' + sJeden);
  t.check('fala rusza w każdym z trzech przypadków',
    zBrak.plotno === true && zZero.plotno === true && zJeden.plotno === true,
    'płótna: ' + zBrak.plotno + ' / ' + zZero.plotno + ' / ' + zJeden.plotno);

  // ── Drabina jakości w prawdziwej przeglądarce ──────────────────────────
  t.section('drabina jakości schodzi sama i zatrzymuje się pod progiem');

  /**
   * Puszcza falę na kilkanaście sekund i oddaje: na który szczebel zeszła
   * i jaki jest końcowy odstęp między klatkami.
   *
   * Chromium w testach renderuje PROGRAMOWO (SwiftShader) — tak samo jak
   * maszyny mierzące PageSpeed. To nie jest ograniczenie środowiska, tylko
   * dokładnie ten przypadek, dla którego drabina powstała.
   */
  const drabina = async (ust, dlawienie, widok) => {
    const html = phpOutput('wave-bg-colors.php', JSON.stringify(JSON.stringify(ust)) + ' html');
    const str = await t.open('wave-bg-pomiar.html', {
      przezHttp: true,
      viewport: widok || { width: 1350, height: 940 },
      dlawienieCPU: dlawienie,
      head: 'window.__tresc = ' + JSON.stringify(html) + ';',
      query: 'evk-wave-debug=1',
      settle: 200,
    });
    /* Zbieramy WYŁĄCZNIE komunikaty o zejściu o szczebel, nie wszystko, co
       element wypisuje przy `?evk-wave-debug=1`. Filtr po samym `[EVK Wave]`
       wystarczał, dopóki drabina była jedyną rzeczą gadającą w tym miejscu —
       od 1.183.0 element loguje też moment startu sceny (czeka na wejście
       Animatora) i ta linia wpadała tu jako czwarte „zejście": mediany
       parsowały się na NaN, a obie kontrole negatywne zapalały na „1 zejść".
       Dopasowanie po treści komunikatu, bo to z niej test i tak czyta numer
       szczebla i medianę kilka linii niżej. */
    const zejscia = [];
    str.on('console', (m) => {
      if (m.text().includes('[EVK Wave]') && m.text().includes('schodzę na poziom')) {
        zejscia.push(m.text());
      }
    });

    /* UKRYWAMY NAZWĘ STEROWNIKA — i to jest badany przypadek, nie obejście.
       Gdy przeglądarka nie mówi, czym renderuje (a coraz częściej nie mówi),
       element ma nie zgadywać, tylko zdać się na zmierzony koszt klatki.
       Bez tej atrapy fala rozpoznałaby tu SwiftShadera i zamroziła kadr, więc
       drabina nie miałaby jak ruszyć. */
    await str.evaluate(() => window.__ukryjSterownik());
    await str.evaluate(() => window.__start());
    await str.waitForTimeout(11000);
    await str.evaluate(() => window.__zerujKlatki());
    await str.waitForTimeout(2500);
    const koncowa = await str.evaluate(() => window.__medianaKlatki());
    const plotno  = await str.evaluate(() => !!document.querySelector('#scena canvas'));
    await str.close();
    return { zejscia, koncowa, plotno };
  };

  const zDrabina = await drabina({}, 4);
  t.check('element wystartował', zDrabina.plotno, String(zDrabina.plotno));
  t.check('przy renderowaniu programowym schodzi o szczebel',
    zDrabina.zejscia.length >= 1, zDrabina.zejscia.length + ' zejść');
  /* SEDNO: po zejściu klatka mieści się pod progiem 50 ms, od którego
     przeglądarka liczy długie zadanie — a Lighthouse z długich zadań liczy
     blocking time i czeka na okno bez nich, żeby uznać stronę za wczytaną. */
  t.check('i kończy pod progiem długiego zadania',
    zDrabina.koncowa !== null && zDrabina.koncowa < 50,
    zDrabina.koncowa + ' ms na klatkę');

  /* KAŻDY SZCZEBEL MUSI COŚ KUPIĆ. Zmierzone mutacją: gdy pierwszy szczebel nie
     zmieniał drogi rysowania, drabina i tak dochodziła pod próg — niżej. Samo
     „skończyło się dobrze" nie odróżnia więc szczebla, który działa, od takiego,
     który tylko przesuwa robotę na następny. */
  const mediany = zDrabina.zejscia.map((l) => Number((l.match(/\] (\d+) ms/) || [])[1]));
  t.check('a każdy szczebel naprawdę obniża koszt klatki',
    mediany.length >= 2 && mediany.every((m, i) => i === 0 || m < mediany[i - 1]),
    mediany.join(' → ') + ' ms');

  /* SCHODZI PO KOLEI, NIE SKACZE OD RAZU NA DÓŁ. Ostatni szczebel zatrzymuje
     animację — to deska ratunku, a nie skrót. Zmierzone mutacją: bez tego
     sprawdzenia zamrożona fala przechodziła jako sukces, bo nieruchoma strona
     też mieści się pod progiem.

     Sprawdzamy KOLEJNOŚĆ, nie to, jak nisko zeszła. Poprzednia wersja wymagała
     zatrzymania się na drugim szczeblu i zapaliła się przy pierwszej zmianie
     rozmiaru okna w teście: na wolniejszej maszynie drugi szczebel trafia
     w 42 ms przy budżecie 40 i schodzenie dalej jest POPRAWNE. Warunek
     zależał od szybkości maszyny testowej zamiast od zachowania kodu. */
  const poziomy = zDrabina.zejscia.map((l) => Number((l.match(/poziom (\d+)/) || [])[1]));
  t.check('schodząc po jednym szczeblu, bez przeskoków',
    poziomy.length > 0 && poziomy.every((p, i) => p === i + 1),
    'kolejność szczebli: ' + poziomy.join(' → '));

  /* KONTROLA NEGATYWNA PIERWSZA: bez drabiny zostaje wolno. Bez niej
     sprawdzenie wyżej przechodziłoby także dla kodu, który nie robi nic,
     a maszyna testowa akurat wyrobiła się sama. */
  const bezDrabiny = await drabina({ auto_jakosc: 'nie' }, 4);
  t.check('z wyłączoną drabiną zostaje wolno i nic nie schodzi',
    bezDrabiny.zejscia.length === 0 && bezDrabiny.koncowa > 50,
    bezDrabiny.koncowa + ' ms na klatkę');

  /* KONTROLA NEGATYWNA DRUGA — i to jest obietnica dla sprzętu z GPU:
     kiedy klatka mieści się w budżecie, NIC się nie degraduje. Zamiast szukać
     maszyny z kartą graficzną podnosimy budżet ponad zmierzony koszt; gate jest
     ten sam, więc dowodzi tego samego.

     MARGINES ROBIMY LICZBĄ PIKSELI, nie zegarem. Przy dławieniu 4× i pełnym
     widoku klatka kosztuje ~195 ms, a górna granica kontrolki to 200 ms —
     „zapas" wynosił więc kilka milisekund i sprawdzenie było rzutem monetą:
     w pełnym zestawie, na obciążonej maszynie, zapaliło się na „1 zejść",
     choć ta sama sekcja puszczona osobno świeciła na zielono.

     WIDOK JEST DOBRANY Z DWÓCH STRON, nie „jak najmniejszy". Pierwsza próba
     (480×320) dawała klatkę 33 ms i sześciokrotny zapas — ale przeszła na
     zielono mutacja zaszywająca próg 40 ms na sztywno w miejsce
     `CONFIG.budzetKlatki`, bo 33 ms mieści się i tu, i tam. Sprawdzenie
     przestawało dowodzić, że budżet w ogóle jest CZYTANY. Przy 900×600 klatka
     kosztuje ~67 ms: trzykrotnie poniżej ustawionych 200, ale powyżej 40,
     więc bramka z zaszytą liczbą schodzi i mutacja zapala. Zmierzony koszt
     jest wypisany w wyniku, żeby przy następnym zapaleniu było widać, czy
     margines się skurczył, czy naprawdę coś schodzi. */
  const zLuznymBudzetem = await drabina(
    { budzet_klatki: 200 }, 1, { width: 900, height: 600 });
  t.check('a przy budżecie z zapasem nie schodzi wcale',
    zLuznymBudzetem.zejscia.length === 0,
    zLuznymBudzetem.zejscia.length + ' zejść przy budżecie 200 ms, klatka '
      + zLuznymBudzetem.koncowa + ' ms');

  /* ── Fala czeka z budową sceny na wejście Animatora ──────────────────────
   *
   * ZGŁOSZONE Z UŻYCIA: „potrzebne jest dodanie opóźnienia uruchamiania wave bg,
   * bo jeśli są na stronie animacje animatora, to jest przeskok".
   *
   * Budowa sceny to wykonanie modułu three.js, kompilacja shaderów (zmierzone
   * wyżej w tym pliku: sto kilkadziesiąt milisekund) i pierwsze klatki pętli
   * rAF. Wypadając w środku wejścia strony, blokuje wątek na tyle, że animacje
   * Animatora przeskakują. Fala czeka więc na sygnał `evk-animator-wejscie`,
   * a POBIERANIE biblioteki rusza od razu — to sieć, nie wątek główny.
   *
   * Mierzymy CHWILĘ POJAWIENIA SIĘ PŁÓTNA, a nie same ustawienia: reguła może
   * wyglądać poprawnie i nie trafiać w nic.
   */
  t.section('fala czeka z budową sceny na wejście Animatora');

  /**
   * Puszcza falę i oddaje: czy płótno było o `probka` ms i po ilu ms powstało.
   *
   * `animator` udaje stronę z włączonym Animatorem (globalną stawia on sam,
   * skryptem inline przed `animator.js`). `ogloszPo` wysyła zdarzenie końca
   * wejścia po tylu ms od startu.
   */
  const koordynacja = async (opcje) => {
    const html = phpOutput('wave-bg-colors.php', JSON.stringify(JSON.stringify({})) + ' html');
    const str = await t.open('wave-bg-pomiar.html', {
      przezHttp: true,
      viewport: { width: 900, height: 600 },
      head: 'window.__tresc = ' + JSON.stringify(html) + ';'
        + (opcje.animator ? 'window.evkAnimator = { library: {}, presets: {} };' : '')
        + (opcje.poWejsciu ? 'window.evkAnimatorWejscieKoniec = true;' : ''),
      settle: 200,
    });
    /* Bez tego Chromium rozpoznaje SwiftShadera i fala rysuje gradient zastępczy
       zamiast płótna — badany przypadek w ogóle by nie wystąpił. */
    await str.evaluate(() => window.__ukryjSterownik());

    await str.evaluate(() => { window.__t0 = performance.now(); window.__start(); });
    if (opcje.ogloszPo !== undefined) {
      await str.evaluate((ms) => setTimeout(
        () => document.dispatchEvent(new CustomEvent('evk-animator-wejscie')), ms),
        opcje.ogloszPo);
    }

    /* Czekamy na płótno przez poll, nie na sztywny odstęp: budowa sceny trwa
       tyle, ile trwa na maszynie testowej, a mierzymy przecież RÓŻNICĘ. */
    const czas = await str.evaluate(async () => {
      for (let i = 0; i < 120; i++) {
        if (document.querySelector('#scena canvas')) return Math.round(performance.now() - window.__t0);
        await new Promise((ok) => setTimeout(ok, 50));
      }
      return null;
    });
    const bledy = str.errors.slice();
    await str.close();
    return { czas, bledy };
  };

  /* Odniesienie: bez Animatora na stronie nie ma na co czekać. Ta liczba jest
     kosztem samej budowy na tej maszynie i punktem odniesienia dla reszty. */
  const bezAnimatora = await koordynacja({});
  t.check('bez Animatora scena powstaje od razu',
    bezAnimatora.czas !== null && bezAnimatora.czas < 1200,
    bezAnimatora.czas + ' ms od startu');

  /* SEDNO: z Animatorem i BEZ sygnału fala czeka do własnego limitu 1200 ms.
     Gdyby czekania nie było, płótno powstałoby tak szybko jak wyżej. */
  const bezSygnalu = await koordynacja({ animator: true });
  t.check('z Animatorem bez sygnału czeka do limitu',
    bezSygnalu.czas !== null && bezSygnalu.czas >= 1200,
    bezSygnalu.czas + ' ms (limit 1200)');

  /* …ale limit jest RATUNKIEM, nie normą: gdy sygnał przyjdzie, fala rusza
     wcześniej. Bez tego sprawdzenia „czeka" przechodziłoby także dla kodu,
     który zawsze odlicza 1200 ms i nikogo nie słucha. */
  const zeSygnalem = await koordynacja({ animator: true, ogloszPo: 150 });
  t.check('a po sygnale rusza przed limitem',
    zeSygnalem.czas !== null && zeSygnalem.czas < bezSygnalu.czas,
    zeSygnalem.czas + ' ms vs ' + bezSygnalu.czas + ' ms bez sygnału');

  /* Wejście odegrane, ZANIM moduł fali zaczął nasłuchiwać — zdarzenie dawno
     przepadło. To nie jest przypadek teoretyczny: `animator.js` jedzie ze stopki
     jako zwykły skrypt, a fala jest modułem, więc wykonuje się po nim. Bez flagi
     obok zdarzenia fala czekałaby tu do limitu na każdej stronie. */
  const poWejsciu = await koordynacja({ animator: true, poWejsciu: true });
  t.check('flaga „wejście już było" zdejmuje czekanie',
    poWejsciu.czas !== null && poWejsciu.czas < 1200,
    poWejsciu.czas + ' ms od startu');

  t.check('bez błędów JS przy koordynacji',
    !bezSygnalu.bledy.length && !zeSygnalem.bledy.length && !poWejsciu.bledy.length,
    [...bezSygnalu.bledy, ...zeSygnalem.bledy, ...poWejsciu.bledy].join(' | ') || 'brak');

  t.section('maska zanika po krzywej, a nie po prostej');

  const alfy = (maska) => (maska.match(/rgba\(0,0,0,([\d.]+)\)/g) || [])
    .map((x) => parseFloat(x.replace(/rgba\(0,0,0,|\)/g, '')));

  const gora = run({ mask_top_enabled: true, mask_top_end: 10 }).maska;
  const a = alfy(gora);

  t.check('rampa ma więcej niż dwa przystanki', a.length >= 5, a.length + ' przystanków');
  t.check('zaczyna od zera i dochodzi do pełnej',
    a[0] === 0 && a[a.length - 1] === 1, a[0] + ' → ' + a[a.length - 1]);
  t.check('rośnie monotonicznie',
    a.every((v, i) => i === 0 || v >= a[i - 1]), a.join(' '));
  /* Właściwość, która krzywą S ODRÓŻNIA od prostej: w pierwszej połowie leży
     PONIŻEJ prostej, w drugiej POWYŻEJ, a w środku się z nią spotyka. Sama
     „mniejsza wartość w jakimś punkcie" niczego by nie dowiodła — porównujemy
     każdy przystanek z prostą w tym samym miejscu. */
  const prosta = a.map((_, i) => i / (a.length - 1));
  const pierwsza = a.slice(1, (a.length - 1) / 2);
  const druga    = a.slice((a.length - 1) / 2 + 1, -1);
  t.check('w pierwszej połowie rampa leży PONIŻEJ prostej',
    pierwsza.every((v, i) => v < prosta[i + 1] - 0.01),
    pierwsza.join(' ') + ' vs ' + prosta.slice(1, (a.length - 1) / 2).join(' '));
  t.check('a w drugiej POWYŻEJ — to jest krzywa S, nie odcinek',
    druga.every((v, i) => v > prosta[i + 1 + (a.length - 1) / 2] + 0.01),
    druga.join(' '));
  // W połowie pokrywa się z prostą — dowód, że długość zanikania została ta sama.
  t.check('a w połowie pokrywa się z prostą — długość bez zmian',
    Math.abs(a[(a.length - 1) / 2] - 0.5) < 0.001, String(a[(a.length - 1) / 2]));

  // KONTROLA NEGATYWNA: bez maski górnej nie ma czego wygładzać.
  t.check('bez maski górnej rampy nie ma wcale',
    alfy(run({ mask_enabled: false, mask_top_enabled: false }).maska || '').length === 0,
    String(run({ mask_enabled: false, mask_top_enabled: false }).maska));

  // Dolna maska jedzie tą samą rampą — inaczej góra byłaby miękka, a dół
  // twardy i wyglądałoby to jak usterka.
  const dol = alfy(run({ mask_enabled: true, mask_start: 90 }).maska);
  t.check('dolna maska zanika tą samą krzywą',
    dol.length >= 5 && dol[0] === 1 && dol[dol.length - 1] === 0,
    dol.join(' '));
};
