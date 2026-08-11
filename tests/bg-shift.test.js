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
