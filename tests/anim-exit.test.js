/**
 * Animacje wychodzące — wyzwalacz „wyjście z kadru".
 *
 * Do 1.51.0 element mógł wejść, a wyjść tylko przez COFNIĘCIE wejścia
 * („Powtarzaj przy każdym wejściu"). Wyjście jest teraz osobną konfiguracją
 * z własnym presetem, czasem i krzywą — a to znaczy, że jeden element niesie
 * dwie animacje i obie muszą się dogadać.
 *
 * Trzy rzeczy są tu ważniejsze niż samo „gaśnie":
 *
 * 1. **Wyjście działa w OBIE strony.** Element ucieka górą, gdy przewijasz
 *    w dół, i dołem, gdy wracasz do góry. Sprawdzenie tylko jednego kierunku
 *    przechodzi dla `toggleActions: 'none play none none'`, które połowy
 *    przypadków nie obsługuje.
 * 2. **Powrót w kadr cofa wyjście.** Bez tego element gasnący raz zostaje
 *    zgaszony na zawsze — a to jest stan gorszy niż brak animacji.
 * 3. **Redukcja ruchu NIE MOŻE nałożyć stanu końcowego wyjścia.** `cfg.to`
 *    to tam `opacity: 0`, więc dotychczasowa ścieżka „bez ruchu, ale stan
 *    końcowy widoczny" zgasiłaby element na stałe u każdego z włączoną
 *    preferencją. To ta sama klasa błędu, dla której powstał motion.test.js.
 */

const V = { width: 1000, height: 700 };

module.exports = async function (t) {
  t.section('wyjście z kadru w dół');

  const p = await t.open('anim-exit.html', { viewport: V, settle: 200 });

  await p.evaluate(() => window.__doCelu());
  await p.waitForTimeout(250);
  t.check('w kadrze element jest widoczny', (await p.evaluate(() => window.__op())) === 1,
    'opacity ' + (await p.evaluate(() => window.__op())));

  await p.evaluate(() => window.__hen());
  await p.waitForFunction(() => window.__op() < 0.05, { timeout: 3000 }).catch(() => {});
  t.check('po ucieczce GÓRĄ element gaśnie', (await p.evaluate(() => window.__op())) < 0.05,
    'opacity ' + (await p.evaluate(() => window.__op())));

  // ── Powrót ────────────────────────────────────────────────────────────
  t.section('powrót w kadr cofa wyjście');

  await p.evaluate(() => window.__doCelu());
  await p.waitForFunction(() => window.__op() > 0.95, { timeout: 3000 }).catch(() => {});
  t.check('wraca do pełnej widoczności', (await p.evaluate(() => window.__op())) > 0.95,
    'opacity ' + (await p.evaluate(() => window.__op())));

  // ── Drugi kierunek ────────────────────────────────────────────────────
  // Ucieczka DOŁEM przy przewijaniu w górę. To ten przypadek wypada przy
  // `toggleActions` bez czwartego slotu — a wygląda identycznie jak działający,
  // dopóki testuje się tylko przewijanie w dół.
  t.section('wyjście z kadru w górę');

  await p.evaluate(() => window.__naGore());
  await p.waitForFunction(() => window.__op() < 0.05, { timeout: 3000 }).catch(() => {});
  t.check('po ucieczce DOŁEM element też gaśnie', (await p.evaluate(() => window.__op())) < 0.05,
    'opacity ' + (await p.evaluate(() => window.__op())));

  // ── Wielokrotność ─────────────────────────────────────────────────────
  // `once: true` (jak przy wejściu w kadr) zabiłby wyzwalacz po pierwszym
  // wyjściu i element z opacity 0 zostałby niewidzialny na zawsze.
  t.section('wyjście działa więcej niż raz');

  await p.evaluate(() => window.__doCelu());
  await p.waitForFunction(() => window.__op() > 0.95, { timeout: 3000 }).catch(() => {});
  await p.evaluate(() => window.__hen());
  await p.waitForFunction(() => window.__op() < 0.05, { timeout: 3000 }).catch(() => {});
  t.check('drugie wyjście też gasi', (await p.evaluate(() => window.__op())) < 0.05,
    'opacity ' + (await p.evaluate(() => window.__op())));

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  // ── Redukcja ruchu ────────────────────────────────────────────────────
  t.section('redukcja ruchu — element ZOSTAJE widoczny');

  const r = await t.open('anim-exit.html', { viewport: V, reduce: true, settle: 200 });
  await r.evaluate(() => window.__hen());
  await r.waitForTimeout(400);
  t.check('po przewinięciu poza kadr nadal widoczny',
    (await r.evaluate(() => window.__op())) === 1,
    'opacity ' + (await r.evaluate(() => window.__op())));
  await r.close();

  // KONTROLA NEGATYWNA. „Element widoczny" jest prawdą także wtedy, gdy silnik
  // w ogóle nie wystartował — bez tej pary nie da się tego odróżnić.
  const n = await t.open('anim-exit.html', { viewport: V, settle: 200 });
  await n.evaluate(() => window.__hen());
  await n.waitForFunction(() => window.__op() < 0.05, { timeout: 3000 }).catch(() => {});
  t.check('bez redukcji ruchu ten sam scroll GASI', (await n.evaluate(() => window.__op())) < 0.05,
    'opacity ' + (await n.evaluate(() => window.__op())));
  await n.close();
};
