/**
 * Marquee — pauza poza kadrem.
 *
 * Pauza istniała od dawna, ale była zaszyta na sztywno (zapas 200 px) i —
 * co ważniejsze — dotyczyła wyłącznie osi czasu. Observer nasłuchujący na
 * `window` łapał KAŻDE przewinięcie strony i przy każdym zabijał tweeny oraz
 * tworzył nowy na `timeScale`, także gdy marquee stało dawno poza kadrem.
 * Przy kilku marquee na długiej stronie robiła się z tego stała mielonka.
 *
 * Test mierzy więc dwie rzeczy naraz: czy treść stoi ORAZ czy silnik przestał
 * wykonywać pracę. Samo „stoi" przechodziłoby także przy nieruszonym Observerze.
 */

const OFFSET = 400;

/** Przewija stronę serią kroków — jeden skok nie wygeneruje wielu zdarzeń. */
async function scrollBurst(page, to) {
  for (let i = 1; i <= 8; i++) {
    await page.evaluate((y) => window.scrollTo(0, y), Math.round((to * i) / 8));
    await page.waitForTimeout(60);
  }
  await page.waitForTimeout(250);
}

module.exports = async function (t) {
  const V = { width: 1000, height: 700 };

  // ── Pauza włączona (domyślna) ──────────────────────────────────────────
  t.section('marquee poza kadrem — pauza włączona');

  const on = await t.open('marquee-pause.html', {
    viewport: V, settle: 700,
    query: 'cfg=' + encodeURIComponent(JSON.stringify({ baseSpeed: 200, pauseOffset: OFFSET })),
  });

  // Start: marquee jest 2400 px niżej, więc od razu poza kadrem i wstrzymane.
  const restA = await on.evaluate(() => window.__pos());
  await on.waitForTimeout(600);
  const restB = await on.evaluate(() => window.__pos());
  t.check('treść stoi, gdy marquee jest poza kadrem', restA === restB, restA + ' → ' + restB);

  await scrollBurst(on, 900);   // nadal poza kadrem, ale strona się przewija
  const work = await on.evaluate(() => window.__work);
  t.check('przewijanie poza kadrem nie generuje pracy', work === 0, work + ' reakcji');

  // Wjazd w kadr — pętla musi ruszyć, inaczej „stoi" nic nie dowodzi.
  await scrollBurst(on, 2400);
  const runA = await on.evaluate(() => window.__pos());
  await on.waitForTimeout(600);
  const runB = await on.evaluate(() => window.__pos());
  t.check('w kadrze treść jedzie', runA !== runB, runA + ' → ' + runB);

  const workIn = await on.evaluate(() => window.__work);
  t.check('w kadrze przewijanie znów działa na prędkość', workIn > 0, workIn + ' reakcji');

  t.check('bez błędów JS', !on.errors.length, on.errors.join(' | ') || 'brak');
  await on.close();

  // ── Pauza wyłączona ────────────────────────────────────────────────────
  // KONTROLA NEGATYWNA dla całej sekcji wyżej: przy wyłączonej pauzie ta sama
  // strona ma się zachować odwrotnie. Bez tego „stoi poza kadrem" świeciłoby
  // na zielono także wtedy, gdyby silnik w ogóle nie wystartował.
  t.section('marquee poza kadrem — pauza wyłączona');

  const off = await t.open('marquee-pause.html', {
    viewport: V, settle: 700,
    query: 'cfg=' + encodeURIComponent(JSON.stringify({ baseSpeed: 200, pauseOffscreen: false })),
  });

  const offA = await off.evaluate(() => window.__pos());
  await off.waitForTimeout(600);
  const offB = await off.evaluate(() => window.__pos());
  t.check('treść jedzie mimo pozycji poza kadrem', offA !== offB, offA + ' → ' + offB);

  await scrollBurst(off, 900);
  const offWork = await off.evaluate(() => window.__work);
  t.check('przewijanie nadal wpływa na prędkość', offWork > 0, offWork + ' reakcji');
  await off.close();

  // ── Zapas jest respektowany ────────────────────────────────────────────
  // Marquee zaczyna się na 2400 px. Przy zapasie 1200 px rusza, gdy górna
  // krawędź jest 1200 px pod dolną krawędzią okna — czyli po przewinięciu
  // do ~500 px. Przy zapasie 0 dopiero po ~1700 px.
  t.section('zapas przed wejściem w kadr');

  async function movesAfterScroll(offset, to) {
    const p = await t.open('marquee-pause.html', {
      viewport: V, settle: 700,
      query: 'cfg=' + encodeURIComponent(JSON.stringify({ baseSpeed: 200, pauseOffset: offset })),
    });
    await scrollBurst(p, to);
    const a = await p.evaluate(() => window.__pos());
    await p.waitForTimeout(500);
    const b = await p.evaluate(() => window.__pos());
    await p.close();
    return a !== b;
  }

  t.check('duży zapas uruchamia wcześniej', await movesAfterScroll(1200, 900), 'zapas 1200 px');
  t.check('zerowy zapas jeszcze nie uruchamia', !(await movesAfterScroll(0, 900)), 'zapas 0 px');
};
