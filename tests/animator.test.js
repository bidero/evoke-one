/**
 * Animator — kolejka startowa.
 *
 * Broni usterki z 1.28.1: pozycje na osi czasu wstawiane były jako `'+=' + delay`,
 * a `'+='` liczy się w GSAP od KOŃCA osi, nie od jej początku. Opóźnienia sumowały
 * się z czasami trwania poprzednich animacji — ustawione 0 / 0,3 / 0,6 s dawało
 * starty 0 / 1,1 / 2,5 s. Pojedynczy element działał poprawnie, więc usterka
 * umykała; dlatego test ma zawsze KILKA elementów naraz.
 */

const { phpOutput } = require('./lib/harness');

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
  /* ── Wyzwalacz najechania nie gasi elementu ────────────────────────────
   *
   * Zgłoszone z użycia: „chciałbym użyć niektórych predefiniowanych animacji,
   * ale większość powoduje, że element jest niewidoczny przed hover".
   *
   * Powód siedział w `buildTimeline`: ścieżka interaktywna szła przez
   * `fromTo`, a to renderuje stan początkowy NATYCHMIAST. Przy presecie
   * wejściowym `from` jest stanem ukrycia (`opacity: 0`), więc element parkował
   * niewidoczny aż do pierwszego najechania. Wyzwalacz wybiera się per użycie,
   * więc taką parę da się złożyć na elemencie niezależnie od tego, co stoi
   * w bibliotece — i dlatego pomiar idzie właśnie na niej.
   */
  t.section('hover nie gasi elementu');

  const presety = 'window.__presets = ' + JSON.stringify(JSON.parse(phpOutput('presets.php'))) + ';';
  const hv = await t.open('anim-hover.html',
    { viewport: { width: 1000, height: 700 }, head: presety, settle: 600 });

  const spoczynek = await hv.evaluate(() => window.__stan('wejsciowy'));
  t.check('preset wejściowy na hoverze JEST widoczny w spoczynku',
    spoczynek.opacity >= 0.99, 'opacity ' + spoczynek.opacity);
  t.check('i nie stoi przesunięty',
    spoczynek.transform === 'none' || /matrix\(1, 0, 0, 1, 0, 0\)/.test(spoczynek.transform),
    spoczynek.transform);

  /* KONTROLA NEGATYWNA. Gdyby parkowanie zniknęło WSZĘDZIE, powyższe też
     byłoby zielone — a na parkowaniu stoją wszystkie animacje wejściowe:
     element ma czekać ukryty, aż wjedzie w kadr. */
  const czeka = await hv.evaluate(() => window.__stan('kontrola'));
  t.check('a pod wejściem w kadr DALEJ czeka ukryty',
    czeka.opacity <= 0.01, 'opacity ' + czeka.opacity);

  /* Preset stanowy ma coś robić. Bez tego „widoczny w spoczynku" spełniłby
     także preset, który nie animuje niczego. Mierzone W LOCIE, bo stan
     końcowy powrotu jest identyczny ze stanem spoczynku. */
  const przed = await hv.evaluate(() => window.__stan('stanowy'));
  await hv.evaluate(() => window.__najedz('stanowy'));
  await hv.waitForTimeout(200);
  const wTrakcie = await hv.evaluate(() => window.__stan('stanowy'));
  /* Odchylenie od macierzy JEDNOSTKOWEJ, nie różnica napisów. Porównanie
     tekstowe przepuszczało preset stanowy ze `scale: 1`, bo `matrix(1, 0, 0,
     1, 0, 0)` to inny napis niż `none` przy dokładnie zerowym ruchu —
     sprawdzenie było zielone dla presetu nierobiącego nic (zmierzone mutacją). */
  const odchylenie = (v) => {
    const m = /\(([^)]*)\)/.exec(v || '');
    if (!m) return 0;
    const l = m[1].split(',').map((x) => Number(x.trim()));
    const jedn = l.length === 16
      ? [1,0,0,0, 0,1,0,0, 0,0,1,0, 0,0,0,1]
      : [1, 0, 0, 1, 0, 0];
    return Math.max(...l.map((x, i) => Math.abs(x - (jedn[i] === undefined ? 0 : jedn[i]))));
  };
  t.check('preset stanowy po najechaniu przekształca',
    odchylenie(wTrakcie.transform) > 0.01,
    przed.transform + ' → ' + wTrakcie.transform
      + ' (odchylenie ' + odchylenie(wTrakcie.transform).toFixed(3) + ')');

  await hv.evaluate(() => window.__zjedz('stanowy'));
  await hv.waitForFunction(() => window.__bezRuchu(), null, { timeout: 3000 }).catch(() => {});
  const poPowrocie = await hv.evaluate(() => window.__stan('stanowy'));
  /* `none` i macierz jednostkowa to ten sam stan widoczny — GSAP po powrocie
     zostawia wpisaną transformację, tylko że tożsamościową. Porównanie samych
     napisów zapalałoby się na czerwono przy elemencie stojącym dokładnie tam,
     gdzie ma stać. */
  const spoczynkowa = (v) => v === 'none' || /^matrix\(1, 0, 0, 1, 0, 0\)$/.test(v);
  t.check('a po zjechaniu wraca do spoczynku',
    spoczynkowa(poPowrocie.transform) && spoczynkowa(przed.transform),
    przed.transform + ' → ' + poPowrocie.transform);

  /* Efekt tekstowy zachowuje stan początkowy MIMO pominięcia `from` presetu —
     maszyna do pisania zaczyna od pustego pola i bez tego nie miałaby czego
     wypisywać. Kontrola pokazująca, że skasowaliśmy `from` PRESETU, a nie stan
     początkowy w ogóle. */
  await hv.evaluate(() => window.__najedz('tekstowy'));
  await hv.waitForTimeout(120);
  const wPolowie = await hv.evaluate(() => window.__stan('tekstowy'));
  t.check('efekt tekstowy na hoverze dalej startuje od pustego',
    wPolowie.text.length < 'tekstowy'.length, JSON.stringify(wPolowie.text));
  await hv.waitForFunction(() => window.__bezRuchu(), null, { timeout: 4000 }).catch(() => {});
  const naKoniec = await hv.evaluate(() => window.__stan('tekstowy'));
  t.check('i dopisuje treść do końca', naKoniec.text === 'tekstowy',
    JSON.stringify(naKoniec.text));

  t.check('bez błędów JS przy hoverze', !hv.errors.length, hv.errors.join(' | ') || 'brak');
  await hv.close();

  /* ── Redukcja ruchu a rodzina stanowa ──────────────────────────────────
   *
   * Bramka redukcji ruchu wykluczała stany po WYZWALACZU. Preset stanowy
   * podpięty pod wejście w kadr przez nią przechodził i jego `to` było
   * nakładane NA STAŁE — element zostawał trwale przygaszony u każdego, kto
   * ruch ogranicza. Warunek po znaczniku rodziny łapie to niezależnie od tego,
   * co wybrano w panelu.
   */
  t.section('redukcja ruchu — stan nie zostaje na stałe');

  const red = await t.open('anim-hover.html',
    { viewport: { width: 1000, height: 700 }, head: presety, reduce: true, settle: 600 });
  const stanRed = await red.evaluate(() => window.__stan('stanwkadrze'));
  t.check('przygaszenie NIE zostaje nałożone', stanRed.opacity >= 0.99,
    'opacity ' + stanRed.opacity);

  /* Lustro: redukcja ruchu ma nadal doprowadzać WEJŚCIA do stanu końcowego,
     inaczej element z `opacity: 0` we `from` zostałby niewidzialny na zawsze. */
  const wejRed = await red.evaluate(() => window.__stan('kontrola'));
  t.check('a wejście mimo redukcji dochodzi do widoczności', wejRed.opacity >= 0.99,
    'opacity ' + wejRed.opacity);
  await red.close();
};
