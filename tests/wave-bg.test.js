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

const { phpOutput } = require('./lib/harness');

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
    const html = phpOutput('wave-bg-colors.php', JSON.stringify(JSON.stringify(ust)) + ' html');
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
