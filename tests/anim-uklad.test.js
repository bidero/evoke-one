/**
 * Animacje po zmianie układu strony.
 *
 * Dwa zgłoszenia o tej samej naturze — „coś się nie odświeża, gdy układ zmienia
 * się po starcie":
 *
 *   · elementy PO poziomym przewijaniu animują się za wcześnie, „jakby już
 *     zagrały" — bo przypięcie wstawia do dokumentu zapas, a wyzwalacze niżej
 *     policzyły swoje punkty startu przed jego wstawieniem;
 *   · hover nie działa na elemencie wstawionym przez filtr pętli zapytania —
 *     bo taki węzeł nigdy nie przechodzi inicjalizacji.
 *
 * Pierwsze mierzone jest LICZBĄ, nie oglądaniem animacji: punkt startu
 * wyzwalacza wobec prawdziwej pozycji elementu w dokumencie. „Zagrała za
 * wcześnie" trzeba by dopiero łapać w odpowiedniej klatce, a różnica jest
 * widoczna wprost.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {

  // ── Kolejność odświeżania wokół przypięcia ───────────────────────────────
  /* Fixture ładuje animator PRZED hscrollem — w kolejności niekorzystnej,
     w której błąd w ogóle ma szansę wyjść. W odwrotnej sprawdzenie świeciłoby
     na zielono z powodu, który z niego nie wynika. */
  t.section('wyzwalacz pod przypiętą sekcją zna prawdziwą pozycję');

  const p = await t.open('anim-po-hs.html', {
    viewport: { width: 1200, height: 800 }, settle: 900,
  });
  const c = await p.evaluate(() => window.__cel());

  t.check('sytuacja jest realna — przypięcie ma sporą drogę',
    c.droga > 400, c.droga + ' px zapasu');
  t.check('punkt startu równa się prawdziwej pozycji',
    Math.abs(c.start - c.oczekiwanyStart) <= 4,
    c.start + ' wobec ' + c.oczekiwanyStart + ' (różnica ' + (c.oczekiwanyStart - c.start) + ')');
  /* Kontrola pozytywna: element NIE zagrał, dopóki nie wjechał w kadr.
     Sam punkt startu byłby prawdą także wtedy, gdyby animacji w ogóle nie było. */
  t.check('i element czeka w stanie początkowym', c.opacity < 0.05, 'opacity ' + c.opacity);
  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');

  /* A po dojechaniu do niego — gra. Bez tego „czeka" spełniłby też element,
     którego animacja nigdy nie rusza. */
  await p.evaluate(() => window.__doPozycji(document.body.scrollHeight));
  await p.waitForTimeout(700);
  const c2 = await p.evaluate(() => window.__cel());
  t.check('a po dojechaniu — gra', c2.opacity > 0.9, 'opacity ' + c2.opacity);
  await p.close();

  // ── To samo w drugą stronę: przypina Animator ────────────────────────────
  /*
   * Wyzwalacze, które Animator tworzy SAM, powstają w kolejności drzewa —
   * czyli od razu w tej, której GSAP oczekuje — więc na nich błąd nie ma jak
   * wyjść. Wychodzi dopiero, gdy wyzwalacz niżej pochodzi z INNEGO skryptu,
   * wczytanego wcześniej. Fixture ładuje więc hscroll PRZED animatorem.
   */
  t.section('wyzwalacz pod pinem Animatora też zna prawdziwą pozycję');

  const a = await t.open('anim-pin-nad-hs.html', {
    viewport: { width: 1200, height: 800 }, settle: 900,
  });
  const ac = await a.evaluate(() => window.__pod());
  t.check('przypięcie Animatora ma sporą drogę', ac.drogaAnimatora > 400,
    ac.drogaAnimatora + ' px');
  t.check('a punkt startu hscrolla równa się pozycji sekcji',
    Math.abs(ac.start - ac.pozycjaWDokumencie) <= 4,
    ac.start + ' wobec ' + ac.pozycjaWDokumencie);
  t.check('bez błędów JS', !a.errors.length, a.errors.join(' | ') || 'brak');
  await a.close();

  // ── Podmiana treści po starcie ───────────────────────────────────────────
  t.section('hover działa na treści wstawionej po starcie');

  const presety = 'window.__presets = ' + JSON.stringify(JSON.parse(phpOutput('presets.php'))) + ';';
  const h = await t.open('anim-hover.html',
    { viewport: { width: 1000, height: 700 }, head: presety, settle: 600 });

  const przed = await h.evaluate(() => window.__wezly());

  await h.evaluate(() => window.__podmien(3));
  await h.waitForTimeout(500);

  const po = await h.evaluate(() => window.__wezly());
  t.check('nowe węzły przeszły inicjalizację', po.gotowe === przed.gotowe + 3,
    przed.gotowe + ' → ' + po.gotowe);

  /* I naprawdę reagują — znacznik gotowości sam w sobie niczego nie dowodzi. */
  const spoczynek = await h.evaluate(() => window.__stan('nowy0'));
  await h.hover('#nowy0');
  await h.waitForTimeout(500);
  const najechany = await h.evaluate(() => window.__stan('nowy0'));
  t.check('i hover naprawdę je animuje', najechany.transform !== spoczynek.transform,
    spoczynek.transform + '  →  ' + najechany.transform);

  /* KONTROLA NEGATYWNA: element sprzed podmiany nie stracił nasłuchów. */
  const staryS = await h.evaluate(() => window.__stan('stanowy'));
  await h.hover('#stanowy');
  await h.waitForTimeout(500);
  const staryN = await h.evaluate(() => window.__stan('stanowy'));
  t.check('a stare elementy dalej działają', staryN.transform !== staryS.transform,
    staryS.transform + '  →  ' + staryN.transform);

  // ── Obserwator zostaje na służbie ────────────────────────────────────────
  /* Filtr pętli działa się wiele razy pod rząd. Obserwator, który złapałby
     tylko pierwszą podmianę, dałby usterkę trudniejszą do zauważenia niż
     pierwotna: raz działa, raz nie. */
  t.section('obserwator łapie też kolejne podmiany');

  await h.evaluate(() => window.__podmien(2));
  await h.waitForTimeout(500);
  const druga = await h.evaluate(() => window.__wezly());
  t.check('po drugiej podmianie znów wszystko gotowe', druga.gotowe === przed.gotowe + 2,
    przed.gotowe + ' → ' + druga.gotowe);

  const s2 = await h.evaluate(() => window.__stan('nowy1'));
  await h.hover('#nowy1');
  await h.waitForTimeout(500);
  const n2 = await h.evaluate(() => window.__stan('nowy1'));
  t.check('i hover na nich działa', n2.transform !== s2.transform,
    s2.transform + '  →  ' + n2.transform);
  t.check('bez błędów JS', !h.errors.length, h.errors.join(' | ') || 'brak');
  await h.close();
};
