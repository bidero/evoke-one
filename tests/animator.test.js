/**
 * Animator — kolejka startowa.
 *
 * Broni usterki z 1.28.1: pozycje na osi czasu wstawiane były jako `'+=' + delay`,
 * a `'+='` liczy się w GSAP od KOŃCA osi, nie od jej początku. Opóźnienia sumowały
 * się z czasami trwania poprzednich animacji — ustawione 0 / 0,3 / 0,6 s dawało
 * starty 0 / 1,1 / 2,5 s. Pojedynczy element działał poprawnie, więc usterka
 * umykała; dlatego test ma zawsze KILKA elementów naraz.
 */

module.exports = async function (t) {
  t.section('wyzwalacz „wczytanie strony”');

  const page = await t.open('animator.html', { viewport: { width: 1000, height: 700 }, settle: 0 });
  await page.waitForFunction(() => window.__op);

  // Moment ruszenia każdego elementu — próbkujemy opacity aż wszystkie wystartują.
  const t0 = Date.now();
  const started = {};
  while (Date.now() - t0 < 3000 && Object.keys(started).length < 4) {
    const op = await page.evaluate(() => window.__op());
    op.forEach((v, i) => { if (started[i] === undefined && v > 0.02) started[i] = (Date.now() - t0) / 1000; });
    await page.waitForTimeout(25);
  }

  // D siedzi w kroku 1, więc rusza po zakończeniu kroku 0: 0,6 s + 0,8 s = 1,4 s.
  const expected = [
    ['A — opóźnienie 0',    0],
    ['B — opóźnienie 0,3',  0.3],
    ['C — opóźnienie 0,6',  0.6],
    ['D — kolejność 1',     1.4],
  ];
  expected.forEach(([name, want], i) => {
    const got = started[i];
    t.check(name, got !== undefined && Math.abs(got - want) < 0.25,
      got === undefined ? 'NIGDY' : got.toFixed(2) + ' s (oczekiwane ~' + want + ' s)');
  });
  await page.close();

  // ── Zasłona: opóźnione mają stać niewidoczne, zanim ruszą ──────────────
  t.section('zasłona przed animacją');
  const p2 = await t.open('animator.html', { viewport: { width: 1000, height: 700 }, settle: 0 });
  await p2.waitForFunction(() => window.__op);
  const early = await p2.evaluate(() => window.__op());
  // A rusza natychmiast, więc sprawdzamy tylko te, które mają czekać.
  t.check('opóźnione stoją na opacity 0', early.slice(1).every((v) => v === 0), JSON.stringify(early));
  t.check('bez błędów JS', !p2.errors.length, p2.errors.join(' | ') || 'brak');
  await p2.close();

  // ── Cel zewnętrzny: A wyzwala, B się animuje ──────────────────────────
  // Silnik zawsze rozdzielał wyzwalacz od celu — `scrollTrigger.trigger` to
  // element, a `resolveTargets()` wybiera, co się rusza. Brakowało tylko
  // ZASIĘGU: `el.querySelectorAll()` trafia wyłącznie w potomków, więc
  // „przewinięcie do sekcji zmienia coś w nagłówku" było nie do zrobienia.
  t.section('cel poza elementem wyzwalającym');

  const ex = await t.open('anim-external.html', {
    viewport: { width: 1000, height: 700 }, settle: 200,
  });

  // Kontrola sensowności. Bez niej całość przeszłaby także dla dzisiejszego
  // „selektora w środku" — a to jest dokładnie ta różnica, o którą chodzi.
  t.check('cel LEŻY POZA wyzwalaczem',
    await ex.evaluate(() => window.__celPozaWyzwalaczem()), 'osobne poddrzewa');

  // Cel jest na górze strony i widoczny od początku — gdyby animacja podpięła
  // się pod niego jako wyzwalacz, zagrałaby od razu i test nic by nie znaczył.
  const zanim = await ex.evaluate(() => window.__opacity('cel'));
  t.check('przed przewinięciem cel czeka ukryty', zanim === 0, 'opacity ' + zanim);

  await ex.evaluate(() => window.__doWyzwalacza());
  await ex.waitForFunction(() => window.__opacity('cel') > 0.9, { timeout: 3000 })
    .catch(() => {});

  const po = await ex.evaluate(() => window.__opacity('cel'));
  t.check('przewinięcie do WYZWALACZA odsłania CEL', po > 0.9, 'opacity ' + po);

  // „Animuj tamtego" znaczy tamtego, nie obu.
  t.check('wyzwalacz zostaje nietknięty',
    !(await ex.evaluate(() => window.__wyzwalaczTknięty())), 'bez własnych varsów');

  t.check('bez błędów JS przy celu zewnętrznym', !ex.errors.length,
    ex.errors.join(' | ') || 'brak');
  await ex.close();
};
