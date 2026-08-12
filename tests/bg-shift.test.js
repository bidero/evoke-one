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
const CSS = tagContent(phpOutput('bgshift-head.php'), 'evk-bgshift-css');

module.exports = async function (t) {
  const open = (opts) => t.open('bg-shift.html', Object.assign({
    viewport: { width: 1000, height: VH },
    head: 'document.addEventListener("DOMContentLoaded",function(){' +
          'var s=document.createElement("style");s.textContent=' + JSON.stringify(CSS) +
          ';document.head.appendChild(s);});',
  }, opts));

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

  const txt = await open({});
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
  // z klasą-furtką podąża. To jest świadomy kompromis, nie niedoróbka.
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
};
