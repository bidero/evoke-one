/**
 * Redukcja ruchu w silnikach.
 *
 * Każdy blok ma PARĘ: przypadek z redukcją i kontrolę negatywną bez niej.
 * Bez kontroli test byłby bezwartościowy — nieuruchomiony silnik wygląda
 * dokładnie tak samo jak wyłączony, więc „nic się nie rusza” samo w sobie
 * niczego nie dowodzi. Ta pułapka zadziałała przy pisaniu tych testów.
 *
 * Druga zasada: ruch ma zniknąć, ale STAN KOŃCOWY MA ZOSTAĆ WIDOCZNY.
 * Dlatego obok każdego pomiaru ruchu stoi sprawdzenie widoczności.
 */

const { phpOutput, tagContent } = require('./lib/harness');

const HEAD = tagContent(phpOutput('motion-head.php'), 'evk-motion');

module.exports = async function (t) {
  const open = (fixture, reduce, opts) =>
    t.open(fixture, Object.assign({ head: HEAD, reduce }, opts || {}));

  // ── Helper ────────────────────────────────────────────────────────────
  t.section('helper polityki ruchu');
  let page = await open('parallax.html', true);
  const api = await page.evaluate(() => ({ has: !!window.evkMotion, r: window.evkMotion.reduced() }));
  t.check('window.evkMotion.reduced() w kontekście', api.has && api.r === true, String(api.r));
  await page.close();
  page = await open('parallax.html', false);
  t.check('reduced() bez preferencji', (await page.evaluate(() => window.evkMotion.reduced())) === false, 'false');
  await page.close();

  // ── Stacking Cards ────────────────────────────────────────────────────
  t.section('Stacking Cards');
  for (const reduce of [true, false]) {
    page = await open('stacking-cards.html', reduce, { viewport: { width: 1000, height: 800 }, query: 'stagger=40' });
    const m = await page.evaluate(() => window.__m());
    t.check(reduce ? 'redukcja → zwykły przepływ' : 'kontrola → sticky',
      reduce ? (!m.active && m.cardPos === 'relative') : (m.active && m.cardPos === 'sticky'),
      'is-active=' + m.active + ', position=' + m.cardPos);
    t.check('  treść widoczna', m.vis === 'visible', m.vis);
    await page.close();
  }

  // ── Scroll Reading ────────────────────────────────────────────────────
  t.section('Scroll Reading');
  for (const reduce of [true, false]) {
    page = await open('scroll-reading.html', reduce);
    const col = await page.evaluate(() => getComputedStyle(document.querySelector('.evk-sr-word')).color);
    // Tekst stoi nad progiem triggera, więc bez redukcji ma być PRZYGASZONY.
    // Z redukcją musi dostać kolor DOCELOWY — przygaszony byłby nieczytelny.
    t.check(reduce ? 'redukcja → od razu kolor docelowy' : 'kontrola → kolor przygaszony',
      col === (reduce ? 'rgb(0, 0, 0)' : 'rgb(200, 200, 200)'), col);
    await page.close();
  }

  // ── Marquee ───────────────────────────────────────────────────────────
  t.section('Marquee');
  for (const reduce of [true, false]) {
    page = await open('marquee.html', reduce);
    const x0 = await page.evaluate(() => document.querySelector('.evk-marquee-item').getBoundingClientRect().left);
    await page.waitForTimeout(600);
    const s = await page.evaluate(() => ({
      x: document.querySelector('.evk-marquee-item').getBoundingClientRect().left,
      vis: getComputedStyle(document.querySelector('.evk-marquee-item')).visibility,
    }));
    const moved = Math.abs(s.x - x0) > 2;
    t.check(reduce ? 'redukcja → treść stoi' : 'kontrola → pętla idzie', reduce ? !moved : moved,
      Math.abs(s.x - x0).toFixed(1) + 'px');
    t.check('  treść widoczna', s.vis === 'visible', s.vis);
    await page.close();
  }

  // ── Parallax (tło kontenera) ──────────────────────────────────────────
  t.section('Parallax — tło kontenera');
  for (const reduce of [true, false]) {
    page = await open('parallax.html', reduce);
    const at = async (y) => {
      await page.evaluate((v) => window.scrollTo(0, v), y);
      await page.waitForTimeout(230);
      return page.evaluate(() => window.__bg());
    };
    const a = await at(200), b = await at(700), c = await at(1200);
    const ty = (s) => parseFloat(s.transform.match(/matrix\(([^)]+)\)/)[1].split(',')[5]);
    const scale = parseFloat(a.transform.match(/matrix\(([^)]+)\)/)[1].split(',')[0]);
    const moves = new Set([a, b, c].map(ty)).size > 1;
    t.check(reduce ? 'redukcja → tło stoi' : 'kontrola → tło się przesuwa', reduce ? !moves : moves,
      [a, b, c].map(ty).map((v) => v.toFixed(1)).join(' → '));
    t.check('  tło widoczne i kadrowane', a.opacity === '1' && a.hasImage && a.size === 'cover',
      'opacity ' + a.opacity + ', ' + a.size);
    t.check('  skala zachowana', scale === 1.2, String(scale));
    await page.close();
  }
};
