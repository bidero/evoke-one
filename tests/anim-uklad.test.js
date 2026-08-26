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
  /* Odczyt przed zamknięciem tej strony — służy jako kontrola negatywna
     w sekcji o podglądzie niżej. */
  const scBez = await p.evaluate(() => window.__scena());

  t.check('sytuacja jest realna — przypięcie ma sporą drogę',
    c.droga > 400, c.droga + ' px zapasu');
  t.check('punkt startu równa się prawdziwej pozycji',
    c.start === c.oczekiwanyStart,
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

  // ── To samo z WŁĄCZONYM podglądem treści ─────────────────────────────────
  /*
   * Droga, którą 1.108.0 przegapiło: fixture miał wtedy podgląd wyłączony,
   * czyli biegł w jedynej konfiguracji, w której tego błędu nie ma.
   *
   * Podgląd przesuwa rodzeństwo pod przypięciem TRANSFORMACJĄ, a ScrollTrigger
   * mierzy pozycje przez `getBoundingClientRect()`, który transformację wlicza.
   * Zmierzone przed poprawką: punkt startu 700 zamiast 1572 — mniej dokładnie
   * o drogę taśmy.
   */
  t.section('podgląd treści nie zniekształca pomiarów');

  const pk = await t.open('anim-po-hs.html', {
    viewport: { width: 1200, height: 800 }, settle: 900, query: 'peek=1',
  });
  const pc = await pk.evaluate(() => window.__cel());

  /*
   * PODGLĄD PRZEZ PRZYPIĘCIE, nie przez transformację (1.111.0).
   *
   * Do 1.110.0 treść pod sekcją była podciągana transformacją liczoną w JS
   * w każdej klatce przewijania. Przy natywnym przewijaniu to musi drgać:
   * przeglądarka maluje przewinięcie z wątku kompozytora, a skrypt dokłada
   * przesunięcie klatkę później. Zgłoszone z użycia: „przyklejona sekcja
   * podsuwa się do góry i dołu przy każdym swipie", a wyłączenie podglądu
   * drganie usuwało — przypięta sekcja obok stała jak wmurowana, bo
   * `position: fixed` prowadzi przeglądarka.
   *
   * Teraz sekcja i jej następne rodzeństwo idą do wspólnego opakowania
   * i przypinane jest opakowanie. Nie ma czego liczyć, więc nie ma czego
   * opóźnić.
   */
  const sc = await pk.evaluate(() => window.__scena());
  t.check('sekcja i treść pod nią trafiają do jednej sceny',
    sc.jest && sc.dzieci.length === 2 && sc.dzieci[0] === 'sekcja-1',
    sc.jest ? sc.dzieci.join(' + ') : 'sceny nie ma');

  /* Sprawdzenie WPROST na przyczynę drgania. */
  t.check('nic za zapasem przypięcia nie jest transformowane',
    sc.zTransformacja === 0, sc.zTransformacja + ' elementów z transformacją');

  /* KONTROLA NEGATYWNA: bez podglądu sceny nie ma i DOM zostaje nietknięty. */
  t.check('a bez podglądu sceny nie ma wcale', !scBez.jest, String(scBez.jest));

  /* Treść pod sekcją stoi nieruchomo przez CAŁE przypięcie — to jest cała
     funkcja podglądu, wyrażona jako jedna liczba. */
  const luki = [];
  for (const y of [900, 1300, 1700]) {
    await pk.evaluate((v) => window.__doPozycji(v), y);
    await pk.waitForTimeout(250);
    luki.push(await pk.evaluate(() => window.__luka()));
  }
  t.check('treść pod sekcją stoi nieruchomo przez całe przypięcie',
    Math.max(...luki) - Math.min(...luki) <= 1, 'odstępy: ' + luki.join(', '));

  /* Punkt startu treści PONIŻEJ sceny jest teraz naturalny — nie ma czego
     dociągać, bo nic jej nie zniekształca. Ta sama liczba co bez podglądu. */
  const pc2 = await pk.evaluate(() => window.__cel());
  t.check('wyzwalacz pod sceną ma naturalny punkt startu',
    pc2.start === pc2.oczekiwanyStart, pc2.start + ' wobec układu ' + pc2.oczekiwanyStart);
  t.check('i taki sam jak bez podglądu w ogóle', pc2.start === c.start,
    'z podglądem ' + pc2.start + ', bez ' + c.start);

  t.check('bez błędów JS', !pk.errors.length, pk.errors.join(' | ') || 'brak');
  await pk.close();


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
