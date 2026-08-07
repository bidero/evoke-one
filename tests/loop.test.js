/**
 * Zapętlanie animacji.
 *
 * Najważniejsze sprawdzenie jest tu regresyjne i dotyczy kolejki startowej.
 * Zapętlona pozycja NIE MOŻE wejść do wspólnej osi czasu: dziecko z `repeat: -1`
 * daje rodzicowi nieskończony czas trwania, a `runLoadQueue()` wylicza początek
 * następnego kroku właśnie z `master.duration()`. Zapętlenie czegokolwiek
 * w kroku 0 zatrzymałoby więc CAŁĄ resztę sekwencji — po cichu i na zawsze.
 *
 * Rozróżnienie „pętla" od „pętli z odbiciem" bierzemy z konfiguracji osi czasu
 * w GSAP-ie, a nie z przebiegu opacity: oba warianty wracają do stanu
 * początkowego, a różnica jest w tym, JAK wracają — pomiar tego próbkowaniem
 * byłby chwiejny i dawałby testy migające.
 */

/** Próbkuje opacity obu elementów przez zadany czas. */
async function sample(page, ms, step) {
  const out = [];
  const t0 = Date.now();
  while (Date.now() - t0 < ms) {
    out.push(await page.evaluate(() => window.__op()));
    await page.waitForTimeout(step || 40);
  }
  return out;
}

module.exports = async function (t) {
  const V = { width: 900, height: 700 };

  // ── Pętla w kadrze ─────────────────────────────────────────────────────
  t.section('pętla przy wejściu w kadr');

  const page = await t.open('loop.html', { viewport: V, query: 'mode=viewport', settle: 200 });
  const runs = await sample(page, 2000);

  t.check('bez błędów JS', !page.errors.length, page.errors.join(' | ') || 'brak');

  // Zapętlony element wraca do stanu początkowego i rusza od nowa — czyli
  // opacity opada co najmniej raz po tym, jak doszło do pełnej widoczności.
  const aVals = runs.map((r) => r[0]);
  const drops = aVals.filter((v, i) => i > 0 && aVals[i - 1] > 0.8 && v < 0.5).length;
  t.check('zapętlona animacja startuje od nowa', drops >= 1, drops + ' powrotów do początku');

  // KONTROLA NEGATYWNA: sąsiad bez pętli ma dojechać do końca i tam zostać.
  const bVals = runs.map((r) => r[1]);
  const bDrops = bVals.filter((v, i) => i > 0 && bVals[i - 1] > 0.8 && v < 0.5).length;
  t.check('animacja bez pętli zostaje na końcu', bDrops === 0 && bVals[bVals.length - 1] > 0.99,
    bDrops + ' powrotów, koniec na ' + bVals[bVals.length - 1]);

  const loops = await page.evaluate(() => window.__loops());
  t.check('powstaje dokładnie jedna oś z pętlą', loops.length === 1, loops.length + ' osi');
  t.check('bez odbicia, gdy niezaznaczone', loops[0] && loops[0].yoyo === false,
    JSON.stringify(loops));
  await page.close();

  // ── Pętla z odbiciem ───────────────────────────────────────────────────
  t.section('pętla z odbiciem');

  const yo = await t.open('loop.html', { viewport: V, query: 'mode=yoyo', settle: 600 });
  const yoLoops = await yo.evaluate(() => window.__loops());
  t.check('oś czasu ma włączone odbicie', yoLoops[0] && yoLoops[0].yoyo === true,
    JSON.stringify(yoLoops));
  await yo.close();

  // ── Kolejka startowa ───────────────────────────────────────────────────
  // Sedno regresji: zapętlona pozycja w kroku 0 nie może zatrzymać kroku 1.
  t.section('kolejka startowa z zapętloną pozycją');

  const q = await t.open('loop.html', { viewport: V, query: 'mode=queue', settle: 0 });
  await q.waitForFunction(() => window.__op);

  let bStarted = null;
  const t0 = Date.now();
  while (Date.now() - t0 < 3000 && bStarted === null) {
    const op = await q.evaluate(() => window.__op());
    if (op[1] > 0.02) bStarted = (Date.now() - t0) / 1000;
    await q.waitForTimeout(30);
  }

  t.check('krok po zapętlonym mimo wszystko rusza', bStarted !== null,
    bStarted === null ? 'NIGDY — sekwencja stanęła' : bStarted.toFixed(2) + ' s');
  // Rusza NATYCHMIAST, a nie po zakończeniu poprzednika — bo poprzednik się
  // nie kończy. Nieskończona animacja wypada z rachunku sekwencji zamiast go
  // zatrzymywać; to jedyne sensowne zachowanie i zapisujemy je wprost.
  t.check('nie czeka na zapętlonego poprzednika',
    bStarted !== null && bStarted < 0.3, bStarted === null ? '—' : bStarted.toFixed(2) + ' s');
  t.check('bez błędów JS', !q.errors.length, q.errors.join(' | ') || 'brak');
  await q.close();

  // ── Redukcja ruchu ─────────────────────────────────────────────────────
  // Pętla to ruch ciągły — przy włączonej preferencji nie startuje wcale,
  // ale treść musi zostać widoczna.
  t.section('redukcja ruchu');

  const rm = await t.open('loop.html', { viewport: V, query: 'mode=viewport', reduce: true, settle: 700 });
  const rmLoops = await rm.evaluate(() => window.__loops());
  t.check('żadna oś się nie kręci', rmLoops.length === 0, rmLoops.length + ' osi');

  const rmOp = await rm.evaluate(() => window.__op());
  t.check('treść mimo to w pełni widoczna', rmOp.every((v) => v > 0.99), JSON.stringify(rmOp));
  await rm.close();
};
