/**
 * Tło przy scrollu.
 *
 * Broni usterki z 1.29.2: moduł trybu ciemnego dokłada `transition:
 * background-color` na `section`, a `getComputedStyle` w trakcie trwającego
 * przejścia zwraca wartość ANIMOWANĄ, nie docelową. Silnik odczytywał kolor
 * dokładnie w tym momencie i warstwa zostawała o jeden motyw w tyle — po
 * powrocie do jasnego trzymała ciemny kolor. Fixture ma to samo przejście CSS,
 * co domyślne `global_selectors`, więc regresja odtworzyłaby się natychmiast.
 */

const { phpOutput, tagContent, rgb, near } = require('./lib/harness');

const VH = 800;
/* Domyślny zasięg koloru liter to od 1.89.0 „wszystkie teksty", więc CSS bez
   argumentu JEST tym szerokim. Wariant wąski podajemy jawnie — inaczej
   sprawdzenia dziedziczenia mierzyłyby coś, czego domyślnie nie ma. */
const CSS = tagContent(phpOutput('bgshift-head.php'), 'evk-bgshift-css');
const CSS_WSZYSTKO = tagContent(phpOutput('bgshift-head.php', 'wszystko'), 'evk-bgshift-css');
const CSS_DZIEDZ   = tagContent(phpOutput('bgshift-head.php', 'dziedziczenie'), 'evk-bgshift-css');

/** Największy rozjazd na składową między dwoma kolorami; null = brak odczytu. */
const rozjazd = (a, b) => (!a || !b ? 999 : Math.max(...a.map((v, i) => Math.abs(v - b[i]))));

module.exports = async function (t) {
  const open = (opts) => {
    opts = opts || {};
    return t.open('bg-shift.html', Object.assign({
      viewport: { width: 1000, height: VH },
    }, opts, {
      head: 'document.addEventListener("DOMContentLoaded",function(){' +
            'var s=document.createElement("style");s.textContent=' + JSON.stringify(opts.css || CSS) +
            ';document.head.appendChild(s);});',
    }));
  };

  // ── Warstwa i oddanie koloru ──────────────────────────────────────────
  t.section('warstwa pod stroną');
  let page = await open({});
  let m = await page.evaluate(() => window.__m());
  t.check('position fixed, z-index -1', m.pos === 'fixed' && m.z === '-1', m.pos + ', ' + m.z);
  t.check('pierwsze dziecko <body>', m.firstChild, String(m.firstChild));
  t.check('przezroczyste tylko sekcje z kolorem',
    JSON.stringify(m.handoff) === JSON.stringify([true, true, true, false, true]), JSON.stringify(m.handoff));
  t.check('ostrzeżenie o sekcji bez koloru',
    page.warnings.some((w) => /nie ma własnego koloru/.test(w)), page.warnings[0] || 'BRAK');

  // ── Kolor nad każdą sekcją ────────────────────────────────────────────
  t.section('kolor warstwy nad środkiem sekcji');
  const want = { s1: [11, 42, 26], s2: [31, 111, 235], s3: [180, 40, 60], s5: [240, 230, 210] };
  for (const id of Object.keys(want)) {
    await page.evaluate((x) => {
      const el = document.getElementById(x);
      window.scrollTo(0, el.offsetTop + el.offsetHeight / 2 - window.innerHeight / 2);
    }, id);
    await page.waitForTimeout(250);
    const got = rgb((await page.evaluate(() => window.__m())).color);
    t.check(id + (id === 's2' ? ' (kolor globalny)' : id === 's5' ? ' (po pominiętej s4)' : ''),
      near(got, want[id]), JSON.stringify(got));
  }

  // ── Płynność przejścia ────────────────────────────────────────────────
  t.section('przenikanie s1 → s2');
  const s2top = await page.evaluate(() => document.getElementById('s2').offsetTop);
  const blues = [];
  for (let f = 0; f <= 1.001; f += 0.25) {
    await page.evaluate((y) => window.scrollTo(0, y), s2top - VH + f * VH * 0.5);
    await page.waitForTimeout(160);
    blues.push(rgb((await page.evaluate(() => window.__m())).color)[2]);
  }
  t.check('niebieski rośnie monotonicznie',
    blues.every((v, i) => i === 0 || v >= blues[i - 1] - 2), blues.join(' → '));
  t.check('w połowie wartość pośrednia', blues[2] > 30 && blues[2] < 230, String(blues[2]));
  t.check('bez błędów JS', !page.errors.length, page.errors.join(' | ') || 'brak');
  await page.close();

  // ── Moment przełączenia ───────────────────────────────────────────────
  // Do 1.53.0 `start: 'top bottom'` było zahardkodowane: przejście zaczynało
  // się ZAWSZE, gdy górna krawędź nadchodzącej sekcji dotknęła dołu okna.
  // Ustawienie „długość" ruszało wyłącznie koniec, więc początku nie dało się
  // przesunąć wcale. Żaden test tego nie pilnował — fixture zawsze przyjmował
  // domyślne 0,5 i nigdy nie podawał `length`.
  //
  // Mierzymy PUNKT ODJAZDU, nie kolor w losowym miejscu. To rozróżnienie ma
  // znaczenie: koniec przejścia liczy się od początku (`endPct = start − dł.`),
  // więc zepsucie samego startu przesuwa CAŁE okno i pomiar „gdzieś w środku"
  // pokazuje różnicę nawet wtedy, gdy start w ogóle nie działa. Sprawdzone
  // celowym zepsuciem: pierwsza wersja tego bloku świeciła na zielono
  // z zahardkodowanym `'top bottom'` z powrotem na miejscu.
  t.section('początek przejścia da się przesunąć');

  const S1_BLUE = 26;   // #0b2a1a

  /** Kolor warstwy, gdy górna krawędź s2 stoi na `pct`% wysokości okna. */
  const atPct = async (p, pct) => {
    await p.evaluate((y) => window.scrollTo(0, y), s2top - VH * (pct / 100));
    await p.waitForTimeout(200);
    return rgb((await p.evaluate(() => window.__m())).color)[2];
  };

  const dom = await open({});
  const domEarly = await atPct(dom, 90);
  t.check('przy starcie 100% kolor rusza od razu po wejściu sekcji',
    domEarly > S1_BLUE + 8, 'niebieski ' + domEarly + ' na 90% okna');
  await dom.close();

  // Start 50%: na 90% okna sekcja jeszcze NIE zaczęła przejmować tła, więc
  // kolor musi być kolorem s1 co do piksela.
  const late = await open({ query: 'start=50' });
  const lateEarly = await atPct(late, 90);
  t.check('przy starcie 50% na 90% okna kolor jeszcze STOI',
    Math.abs(lateEarly - S1_BLUE) <= 3, 'niebieski ' + lateEarly + ' (s1 = ' + S1_BLUE + ')');

  // …ale gdy sekcja dojedzie wyżej, przejście rusza normalnie. Bez tej pary
  // „kolor stoi" byłoby prawdą także dla łańcucha, który się w ogóle nie zbudował.
  const lateMid = await atPct(late, 25);
  t.check('przy starcie 50% wyżej kolor jednak rusza', lateMid > S1_BLUE + 8,
    'niebieski ' + lateMid + ' na 25% okna');
  await late.close();

  // ── Nadpisanie per sekcja ─────────────────────────────────────────────
  // `data-evk-bg` był pustym znacznikiem. Teraz może nieść procent — „przełącz
  // tło, gdy TA sekcja dojdzie do X%" — a pusty nadal znaczy „wartość globalna",
  // więc istniejące strony nie zmieniają zachowania.
  t.section('sekcja może nadpisać moment');

  const perSec = await open({ query: 'sekcja=s2:50' });
  const perEarly = await atPct(perSec, 90);
  t.check('atrybut sekcji opóźnia przejęcie tak samo jak ustawienie globalne',
    Math.abs(perEarly - S1_BLUE) <= 3, 'niebieski ' + perEarly + ' (s1 = ' + S1_BLUE + ')');
  await perSec.close();

  // Nadpisanie dotyczy TYLKO swojej sekcji. Bez tego sprawdzenia „atrybut
  // działa" przechodziłoby także dla kodu, który wpisaną wartość bierze
  // za nową globalną.
  const inna = await open({ query: 'sekcja=s3:50' });
  const innaEarly = await atPct(inna, 90);
  t.check('atrybut na INNEJ sekcji nie rusza tego przejścia',
    innaEarly > S1_BLUE + 8, 'niebieski ' + innaEarly + ' na 90% okna');
  await inna.close();

  // ── Kolor liter razem z tłem ──────────────────────────────────────────
  // Moduł przewijał tło i NIE DOTYKAŁ treści, więc na stronie z ciemnymi
  // i jasnymi sekcjami naprzemiennie litery zostawały w jednym kolorze
  // i na części tła przestawały być czytelne.
  //
  // Kolor jedzie tym SAMYM tweenem co tło — nie osobnym. Dlatego sprawdzenia
  // niżej mierzą nie tylko wartość, ale i to, że oba ruchy dzielą jedno okno:
  // rozjechanie się ich to jedyna usterka, której nie widać po samym kolorze
  // nad środkiem sekcji.
  t.section('kolor liter — automat i wskazanie');

  /* Wariant WĄSKI, podany jawnie. Dwa sprawdzenia niżej opisują zachowanie
     dziedziczenia (element z własnym kolorem zostaje nietknięty), a to od
     1.89.0 nie jest już domyślne — na domyślnym arkuszu mierzyłyby coś
     przeciwnego niż nazywają. */
  const txt = await open({ css: CSS_DZIEDZ });
  const atMid = async (p, id) => {
    await p.evaluate((x) => {
      const el = document.getElementById(x);
      window.scrollTo(0, el.offsetTop + el.offsetHeight / 2 - window.innerHeight / 2);
    }, id);
    await p.waitForTimeout(250);
    return p.evaluate(() => window.__text());
  };

  // s1 to #0b2a1a — ciemna zieleń. Automat ma sięgnąć po jasny z pary.
  let tv = await atMid(txt, 's1');
  t.check('na ciemnym tle automat daje jasne litery',
    near(rgb(tv.s1), [255, 255, 255]), tv.s1);

  // s5 to #f0e6d2 — jasny piasek. Ten sam automat, druga strona pary.
  tv = await atMid(txt, 's5');
  t.check('na jasnym tle automat daje ciemne litery',
    near(rgb(tv.s5), [17, 17, 17]), tv.s5);

  // s3 ma kolor wskazany atrybutem — wskazanie musi wygrać z automatem.
  tv = await atMid(txt, 's3');
  t.check('wskazany kolor wygrywa z automatem',
    near(rgb(tv.s3), [0, 200, 0]), tv.s3);

  // Zasięg: sekcja pominięta (gradient, bez własnego tła) traci evk-bg-handoff,
  // więc reguła w nią nie trafia. Bez tego kolor liter rozlewałby się na
  // sekcje, które w tym mechanizmie w ogóle nie biorą udziału.
  t.check('sekcja pominięta NIE dostaje koloru liter',
    rgb(tv.s4)[0] === 255 && rgb(tv.s4)[1] === 255 && rgb(tv.s4)[2] === 255, tv.s4);

  // Dziedziczenie: element z własnym kolorem zostaje nietknięty, a ten
  // z klasą-furtką podąża. Tak wygląda WĄSKI zasięg — od 1.89.0 wybierany
  // ręcznie, bo domyślnie okazał się znaczyć „litery nie zmieniają się
  // prawie nigdzie".
  tv = await atMid(txt, 's1');
  t.check('element z własnym kolorem zostaje nietknięty',
    near(rgb(tv.wlasny), [255, 0, 255]), tv.wlasny);
  t.check('klasa evk-bg-text każe mu jednak podążać',
    near(rgb(tv.furtka), [255, 255, 255]), tv.furtka);
  await txt.close();

  // ── Litery i tło w JEDNYM oknie ───────────────────────────────────────
  // Sedno decyzji „płynnie razem z tłem". Osobny tween dla liter dałby drugie
  // okno i drugą krzywą, które da się przestawić niezależnie — i to jest
  // jedyna usterka, której nie złapie pomiar nad środkiem sekcji.
  //
  // Dlatego nie porównujemy tu wartości, tylko POSTĘP obu ruchów: każdy
  // przeliczony na ułamek drogi od swojego startu do swojego celu. Jeden tween
  // znaczy, że te dwa ułamki są w każdej chwili równe — i tylko taki pomiar
  // odróżnia „jadą razem" od „jadą podobnie".
  t.section('litery i tło jadą w jednym oknie');

  const sync = await open({});
  const s3top = await sync.evaluate(() => document.getElementById('s3').offsetTop);

  // s2 → s3: tło z rgb(31,111,235) na rgb(180,40,60), litery z bieli na
  // rgb(0,200,0). Bierzemy kanały o największym zakresie: niebieski i czerwony.
  const post = [];
  for (let f = 0; f <= 1.001; f += 0.25) {
    await sync.evaluate((y) => window.scrollTo(0, y), s3top - VH + f * VH * 0.5);
    await sync.waitForTimeout(160);
    const tlo   = rgb((await sync.evaluate(() => window.__m())).color)[2];
    const tekst = rgb((await sync.evaluate(() => window.__text())).zmienna)[0];
    post.push({
      tlo:   (235 - tlo)   / (235 - 60),
      tekst: (255 - tekst) / (255 - 0),
      raw:   tlo + '/' + tekst,
    });
  }

  t.check('postęp liter rośnie monotonicznie',
    post.every((x, i) => i === 0 || x.tekst >= post[i - 1].tekst - 0.02),
    post.map((x) => x.tekst.toFixed(2)).join(' → '));
  t.check('w połowie litery są w POŁOWIE drogi',
    post[2].tekst > 0.3 && post[2].tekst < 0.7, post[2].tekst.toFixed(2));
  t.check('na starcie okna oba stoją na zerze',
    post[0].tlo < 0.05 && post[0].tekst < 0.05, post[0].raw);
  t.check('na końcu okna oba są u celu',
    post[4].tlo > 0.95 && post[4].tekst > 0.95, post[4].raw);
  t.check('postęp liter i tła jest ten SAM w każdej chwili',
    post.every((x) => Math.abs(x.tlo - x.tekst) < 0.06),
    post.map((x) => x.tlo.toFixed(2) + ' vs ' + x.tekst.toFixed(2)).join(' | '));
  t.check('bez błędów JS', !sync.errors.length, sync.errors.join(' | ') || 'brak');
  await sync.close();

  // ── Redukcja ruchu ────────────────────────────────────────────────────
  // Litery przeskakują razem z tłem, w tym samym punkcie. Rozdzielenie dałoby
  // moment, w którym stary kolor liter leży już na nowym tle.
  t.section('redukcja ruchu — litery przeskakują razem z tłem');

  const red = await open({ reduce: true });
  const próbkiR = [];
  // Zakres szerszy niż samo okno przejścia: przeskok wypada na jego KOŃCU
  // (50% okna), więc pomiar kończący się dokładnie tam mógłby go nie złapać.
  for (let f = 0; f <= 1.501; f += 0.25) {
    await red.evaluate((y) => window.scrollTo(0, y), s3top - VH + f * VH * 0.5);
    await red.waitForTimeout(160);
    próbkiR.push(rgb((await red.evaluate(() => window.__text())).zmienna)[0]);
  }
  const posrednie = próbkiR.filter((v) => v > 20 && v < 235);
  t.check('brak wartości pośrednich', !posrednie.length, próbkiR.join(' → '));
  t.check('kolor liter mimo wszystko dochodzi do celu',
    próbkiR[próbkiR.length - 1] <= 20, String(próbkiR[próbkiR.length - 1]));
  await red.close();

  // Kontrola negatywna: bez redukcji stan pośredni MUSI wystąpić. Bez niej
  // „brak wartości pośrednich" jest prawdą także dla modułu, który się
  // w ogóle nie uruchomił.
  t.check('bez redukcji stan pośredni JEST',
    post[2].tekst > 0.3 && post[2].tekst < 0.7,
    'przy pełnym ruchu postęp ' + post[2].tekst.toFixed(2));

  // ── Zmiana motywu ─────────────────────────────────────────────────────
  // Automat liczy z tła, a tło zmienia się z motywem — więc kolor liter musi
  // się przeliczyć razem z nim, nie zostać z poprzedniego motywu.
  t.section('zmiana motywu przelicza kolor liter');

  const th = await open({});
  await th.evaluate(() => {
    const el = document.getElementById('s5');
    window.scrollTo(0, el.offsetTop + el.offsetHeight / 2 - window.innerHeight / 2);
  });
  await th.waitForTimeout(250);
  const jasny = (await th.evaluate(() => window.__text())).s5;
  t.check('na jasnym tle litery ciemne', near(rgb(jasny), [17, 17, 17]), jasny);

  // W ciemnym motywie s5 to #1a1512 — automat musi obrócić wybór.
  await th.evaluate(() => window.__setTheme('dark'));
  await th.waitForTimeout(400);
  const ciemny = (await th.evaluate(() => window.__text())).s5;
  t.check('po zmianie motywu litery jasne', near(rgb(ciemny), [255, 255, 255]), ciemny);
  t.check('bez błędów JS przy zmianie motywu', !th.errors.length,
    th.errors.join(' | ') || 'brak');
  await th.close();

  // ── Redukcja ruchu ────────────────────────────────────────────────────
  t.section('redukcja ruchu');
  page = await open({ reduce: true });
  const rm = [];
  for (let f = 0; f <= 2.001; f += 0.25) {
    await page.evaluate((y) => window.scrollTo(0, y), s2top - VH + f * VH * 0.5);
    await page.waitForTimeout(160);
    rm.push(rgb((await page.evaluate(() => window.__m())).color));
  }
  t.check('brak wartości pośrednich',
    rm.every((c) => near(c, [11, 42, 26]) || near(c, [31, 111, 235])), rm.map((c) => c[2]).join(' → '));
  t.check('kolor mimo wszystko przeskakuje', rm.some((c) => near(c, [31, 111, 235])), 'docelowy osiągnięty');
  await page.close();

  // ── Zmiana motywu ─────────────────────────────────────────────────────
  t.section('przełączanie motywu (regresja 1.29.2)');
  page = await open({});
  const LIGHT = [11, 42, 26], DARK = [18, 22, 29];
  let drift = false;
  for (let i = 0; i < 5; i++) {
    for (const [mode, want2] of [['dark', DARK], ['light', LIGHT]]) {
      await page.evaluate((x) => window.__setTheme(x), mode);
      await page.waitForTimeout(700);
      const s = await page.evaluate(() => window.__m());
      if (!near(rgb(s.color), want2) || !s.handoff.slice(0, 3).every(Boolean)) drift = true;
    }
  }
  t.check('dziesięć przełączeń bez dryfu', !drift, drift ? 'warstwa rozjechała się z motywem' : 'kolor zgodny za każdym razem');
  await page.close();

  /* ── Zasięg koloru liter ──────────────────────────────────────────────
   *
   * Zgłoszone z użycia: „zmiana koloru tekstu nie działa". Nie była zepsuta —
   * działała dokładnie tak, jak opisano: kolor schodził na sekcję i dalej
   * DZIEDZICZENIEM. Tyle że dziedziczenie omija każdy element z własnym
   * kolorem, a Bricks nadaje własny kolor niemal każdemu tekstowi, regułą po
   * identyfikatorze. Zasięg opisany jako ostrożny znaczył w praktyce
   * „prawie nigdzie" — i dlatego od 1.89.0 domyślny jest ten szeroki.
   */
  t.section('zasięg koloru liter');

  /* Reguła MALUJĄCA potomków, w odróżnieniu od tej, która tylko gasi im
     przejścia. Sam selektor `.evk-bg-handoff *` stoi teraz w obu, więc
     szukanie go bez zaglądania do środka bloku odpowiadałoby na inne
     pytanie niż zadane. */
  const maluje_potomkow = (css) => /\.evk-bg-handoff \*[^{]*\{[^}]*color: var/.test(css);

  t.check('domyślnie kolor dosięga też potomków',
    maluje_potomkow(CSS), 'reguła koloru na potomkach jest');
  t.check('a wariant wąski zostawia je dziedziczeniu',
    /\.evk-bg-handoff,\s*\n\.evk-bg-handoff \.evk-bg-text/.test(CSS_DZIEDZ)
    && !maluje_potomkow(CSS_DZIEDZ), 'bez reguły koloru na potomkach');
  /* `!important` to jedyna droga: reguła Bricksa siedzi na IDENTYFIKATORZE,
     więc żaden selektor pisany klasami jej nie przebije szczegółowością. */
  t.check('i robi to z !important, bo inaczej nie przebije reguły po id',
    /\.evk-bg-handoff \*[^{]*\{[^}]*color: var[^}]*!important/.test(CSS_WSZYSTKO), '!important');
  /* Pominięcia nie są ozdobą: `color` na obrazie czy polu formularza znaczy co
     innego niż „kolor liter", a ikona rysowana `currentColor` potrafi zniknąć
     na tle własnego przycisku. */
  t.check('ale omija obrazy, pola i wyłączone klasą',
    /:not\(img\)/.test(CSS_WSZYSTKO) && /:not\(input\)/.test(CSS_WSZYSTKO)
    && /evk-bg-keep/.test(CSS_WSZYSTKO), 'wyjątki na miejscu');

  /* Nowa domyślna zmierzona na stronie, nie w arkuszu: `#own` ma w CSS własny
     kolor (magenta) wpisany jak w builderze. Przy dziedziczeniu zostawał
     magentą — sprawdzenie wyżej w sekcji o automacie pilnuje, że tam DALEJ
     zostaje. Tu ma podążać za tłem. */
  const domysl = await open({});
  await domysl.evaluate(() => {
    const el = document.getElementById('s1');
    window.scrollTo(0, el.offsetTop + el.offsetHeight / 2 - window.innerHeight / 2);
  });
  await domysl.waitForTimeout(250);
  const domTxt = await domysl.evaluate(() => window.__text());
  t.check('domyślnie element z własnym kolorem PODĄŻA',
    near(rgb(domTxt.wlasny), [255, 255, 255]), domTxt.wlasny);
  await domysl.close();

  /* ── Litery nadążają MIMO cudzego przejścia na `color` ────────────────
   *
   * Zgłoszone z użycia: „zmiana koloru tekstu nie działa, powinno płynnie się
   * zmieniać". Silnik był sprawny — zmienna interpoluje wzorowo. Zjadało to
   * cudze `transition: color`: kolor piszemy CO KLATKĘ, a każdy zapis
   * RESTARTUJE tamto przejście od bieżącej wartości, więc tekst nie dogania
   * celu przez cały czas przewijania i dochodzi do niego dopiero po nim.
   *
   * Zbieg nie jest teoretyczny — sekundowe przejście na `color` dokłada
   * domyślnie moduł trybu ciemnego TEJ WTYCZKI, do `section` (global_selectors)
   * ORAZ do `.brxe-text` i `.brxe-heading` (bricks_selectors). Fixture
   * odtwarzał dotąd tylko przejście TŁA, i to tylko na sekcji, więc cała ta
   * klasa usterek była poza zasięgiem pomiaru.
   *
   * MIERZYMY SEKCJĘ I POTOMKA OSOBNO, bo kolor dochodzi do nich dwiema różnymi
   * drogami: sekcja dostaje go regułą, potomek — przy wąskim zasięgu —
   * dziedziczeniem. Zmiana wartości odziedziczonej uruchamia własne przejście
   * potomka tak samo jak ustawiona wprost, więc pomiar na samej sekcji
   * przepuszczał rozjazd sięgający 117 składowych.
   */
  t.section('litery nadążają mimo cudzego przejścia na kolor');

  for (const w of [{ nazwa: 'szeroki', css: CSS }, { nazwa: 'wąski', css: CSS_DZIEDZ }]) {
    const tc = await open({ query: 'tcol=1', css: w.css });

    /* Najpierw dowód, że wariant NAPRAWDĘ dołożył przejście — i to w obu
       miejscach. Bez tego reszta przechodziłaby także wtedy, gdy nie ma
       z czym walczyć. */
    const przS = await tc.evaluate(() => window.__przejscie('s3'));
    const przK = await tc.evaluate(() => window.__przejscie('kid'));
    t.check(w.nazwa + ': fixture naprawdę dokłada przejście',
      /color/.test(przS.wlasciwosci) && /1s/.test(przS.czasy)
      && /color/.test(przK.wlasciwosci) && /1s/.test(przK.czasy),
      'sekcja ' + przS.czasy + ', potomek ' + przK.czasy);

    const top = await tc.evaluate(() => document.getElementById('s3').offsetTop);
    const sekcja = [], potomek = [];
    for (let f = 0; f <= 1.001; f += 0.25) {
      await tc.evaluate((y) => window.scrollTo(0, y), top - VH + f * VH * 0.5);
      await tc.waitForTimeout(200);
      const st = await tc.evaluate(() => window.__text());
      const z = rgb(st.zmienna);
      sekcja.push(rozjazd(z, rgb(st.s3)));
      potomek.push(rozjazd(z, rgb(st.potomek)));
    }
    // Litery mają być TYM SAMYM kolorem co zmienna w każdej chwili.
    t.check(w.nazwa + ': sekcja trzyma się zmiennej',
      sekcja.every((d) => d <= 6), sekcja.join(' / ') + ' (max składowa)');
    t.check(w.nazwa + ': POTOMEK trzyma się zmiennej',
      potomek.every((d) => d <= 6), potomek.join(' / ') + ' (max składowa)');
    t.check(w.nazwa + ': bez błędów JS', !tc.errors.length, tc.errors.join(' | ') || 'brak');
    await tc.close();
  }

  // Znacznik przewijania: jest w trakcie, schodzi po chwili od ostatniego
  // zapisu — inaczej przejścia byłyby wyłączone na stałe i zabrałyby hover.
  const zn = await open({ query: 'tcol=1' });
  const znTop = await zn.evaluate(() => document.getElementById('s3').offsetTop);
  await zn.evaluate((y) => window.scrollTo(0, y), znTop - VH + 0.5 * VH * 0.5);
  await zn.waitForTimeout(30);
  t.check('w trakcie przewijania znacznik JEST',
    await zn.evaluate(() => window.__scrub()), 'evk-bg-scrub');
  await zn.waitForTimeout(400);
  t.check('a po chwili schodzi, więc przejścia wracają',
    !(await zn.evaluate(() => window.__scrub())), 'zdjęty');

  /* ...ale NIE MIGA po drodze. Znacznik ma schodzić po ostatnim zapisie,
     nie po każdym — inaczej w środku gestu wpada okno z włączonymi
     przejściami, a przy okazji leci pełne unieważnienie stylu poddrzewa
     kilka razy na sekundę. Gest odtwarzamy gęsto (co ~16 ms), bo pomiary
     wyżej robią przerwy dłuższe niż samo opóźnienie i znacznik schodzi
     tam zgodnie z projektem. */
  await zn.evaluate(() => window.__scrubZejscia(true));
  await zn.evaluate(async (args) => {
    for (let i = 0; i <= 40; i++) {
      window.scrollTo(0, args.od + (args.do_ - args.od) * (i / 40));
      await new Promise((r) => setTimeout(r, 16));
    }
  }, { od: znTop - VH, do_: znTop - VH + VH * 0.5 });
  t.check('i nie miga w trakcie gestu',
    (await zn.evaluate(() => window.__scrubZejscia())) <= 1,
    (await zn.evaluate(() => window.__scrubZejscia())) + ' zejść na ~700 ms przewijania');
  /* Gaszenie przejść ma sięgać potomków ZAWSZE, nie tylko przy szerokim
     zasięgu — przy wąskim to właśnie potomek dostaje kolor i to on ma
     nadążać. */
  t.check('gaszenie przejść sięga potomków w obu zasięgach',
    /html\.evk-bg-scrub \.evk-bg-handoff \*\s*\{/.test(CSS)
    && /html\.evk-bg-scrub \.evk-bg-handoff \*\s*\{/.test(CSS_DZIEDZ), 'w obu wariantach');
  await zn.close();
};
