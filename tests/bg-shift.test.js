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
