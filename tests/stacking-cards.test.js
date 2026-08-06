/**
 * Stacking Cards — geometria stosu.
 *
 * Broni trzech usterek, które kosztowały po kilka podejść:
 *  · sticky zwalnia karty w kolejności ODWROTNEJ do `top`, więc schodek przez
 *    `top` sprawiał, że ostatnia karta ruszała pierwsza i wchodziła na poprzednie
 *    (1.27.3). Schodek robi teraz transform, a zwolnienie ma być równoczesne.
 *  · padding-bottom nie przedłuża fazy sticky ani o piksel — zapas musi być
 *    realnym elementem (1.27.3).
 *  · własny nasłuch resize wołający ScrollTrigger.refresh() zacinał scroll na
 *    telefonie (1.28.0). Przy zmianie samej wysokości nie wolno wymuszać refreshu.
 */

const VH = 800;
const STAGGER = 40;

module.exports = async function (t) {
  // ── Zwolnienie i schodki ──────────────────────────────────────────────
  t.section('schodek 40px, zapas automatyczny');
  let page = await t.open('stacking-cards.html', { viewport: { width: 1000, height: VH }, query: 'stagger=40' });

  const docH = await page.evaluate(() => document.documentElement.scrollHeight);
  const rows = [];
  for (let y = 0; y <= docH - VH; y += 4) {
    await page.evaluate((v) => window.scrollTo(0, v), y);
    rows.push([y, await page.evaluate(() => window.__m())]);
  }

  const release = [];
  for (let i = 0; i < 3; i++) {
    const stuck = 80 + i * STAGGER;
    const held = rows.filter(([, m]) => m.tops[i] === stuck);
    release.push(held.length ? held[held.length - 1][0] : null);
    t.check('karta ' + (i + 1) + ' stoi na ' + stuck + 'px', held.length > 0,
      held.length ? held[0][0] + '–' + held[held.length - 1][0] : 'NIGDY');
  }
  t.check('zwolnienie równoczesne', new Set(release).size === 1, release.join(' / '));

  const exiting = rows.filter(([y, m]) => y > release[2] && m.tops[2] > -300);
  const broken = exiting.filter(([, m]) => m.tops[1] - m.tops[0] !== STAGGER || m.tops[2] - m.tops[1] !== STAGGER);
  t.check('schodki przetrwały wyjście stosu', !broken.length,
    (exiting.length - broken.length) + '/' + exiting.length + ' klatek');

  const overlap = rows.filter(([, m]) => m.afterTop < VH && m.afterTop > -VH)
                      .map(([, m]) => m.lastBottom - m.afterTop).filter((o) => o > 0);
  t.check('stos nie nachodzi na następną sekcję', !overlap.length,
    overlap.length ? 'max ' + Math.max(...overlap) + 'px' : 'brak');

  const first = rows[0][1];
  t.check('zapas automatyczny = pół karty', first.spacer === 400, first.spacer + 'px przy karcie 800px');
  t.check('padding-bottom = (n-1) × schodek', first.pad === '80px', first.pad);
  await page.close();

  // ── Zapas ręczny ──────────────────────────────────────────────────────
  t.section('zapas ręczny');
  page = await t.open('stacking-cards.html', { viewport: { width: 1000, height: VH }, query: 'stagger=40&space=50vh' });
  t.check('rozpórka z jednostki CSS', (await page.evaluate(() => window.__m())).spacer === 400, '50vh z 800px = 400px');
  await page.close();

  // ── Brak efektów: stos ma działać bez żadnego tweena ───────────────────
  t.section('bez zmniejszania i przyciemniania');
  page = await t.open('stacking-cards.html', { viewport: { width: 1000, height: VH }, query: 'stagger=40&shrink=0&dim=0' });
  const bare = await page.evaluate(() => window.__m());
  t.check('stos działa mimo zera tweenów', bare.active && bare.spacer === 400, 'rozpórka ' + bare.spacer + 'px');
  await page.close();

  // ── Poniżej breakpointu ───────────────────────────────────────────────
  t.section('poniżej breakpointu');
  page = await t.open('stacking-cards.html', { viewport: { width: 600, height: VH }, query: 'stagger=40' });
  const small = await page.evaluate(() => window.__m());
  t.check('zwykły przepływ', !small.active && small.spacer === null && small.pad === '0px',
    'is-active=' + small.active + ', rozpórka=' + small.spacer);
  await page.close();

  // ── Regresja 1.28.0: zmiana samej wysokości nie wymusza refreshu ───────
  t.section('zmiana wysokości okna (pasek adresu telefonu)');
  page = await t.open('stacking-cards.html', { viewport: { width: 1000, height: VH }, query: 'stagger=40' });
  const before = (await page.evaluate(() => window.__m())).refresh;
  await page.setViewportSize({ width: 1000, height: 640 });
  await page.waitForTimeout(500);
  await page.setViewportSize({ width: 1000, height: VH });
  await page.waitForTimeout(500);
  const after = await page.evaluate(() => window.__m());
  t.check('brak wymuszonego refreshu', after.refresh === before, before + ' → ' + after.refresh);
  t.check('rozpórka bez zmian', after.spacer === 400, after.spacer + 'px');

  // ── Zmiana szerokości MA przeliczyć rozpórkę ───────────────────────────
  await page.setViewportSize({ width: 900, height: 500 });
  await page.waitForTimeout(700);
  t.check('zmiana szerokości przelicza rozpórkę',
    (await page.evaluate(() => window.__m())).spacer === 250, 'pół karty 500px = 250px');
  t.check('bez błędów JS', !page.errors.length, page.errors.join(' | ') || 'brak');
  await page.close();
};
