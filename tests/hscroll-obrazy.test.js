/**
 * Taśma mierzona na obrazach, których jeszcze nie ma.
 *
 * Zgłoszenie z użycia brzmiało tak: „animacje po horizontal scroll odpalają się
 * za wcześnie… Ciekawe, że zdarzyło się kilka razy, że nagle elementy się
 * odświeżyły i zaczęły działać w czasie przewijania. Potem po refresh strony
 * znowu to samo".
 *
 * Oba zdania opisują JEDNĄ rzecz. `getAmount()` liczy długość przewijania
 * z `track.scrollWidth`, a w trybie „z buildera" bierze się ona z treści paneli
 * — czyli z obrazów, które lazy-loader podstawia jako zastępcze `data:`.
 * Pierwszy pomiar leci na atrapach: taśma za krótka, pin za krótki, `pin-spacer`
 * za niski, więc CAŁA treść pod sekcją stoi w zmierzonym dokumencie wyżej, niż
 * stanie naprawdę — i jej punkty startu wypadają za wcześnie. „Nagle zaczęły
 * działać" to moment, w którym odłożone przeliczenie wreszcie wchodzi.
 *
 * Mierzone są LICZBY z `ScrollTrigger.getAll()`, nie to, czy animacja zagrała:
 * różnica jest tu punktem startu, a „zagrała za wcześnie" trzeba by dopiero
 * łapać w odpowiedniej klatce.
 */

const fs   = require('fs');
const path = require('path');
/** `near` z harnessu porównuje trójki RGB — tu potrzebne są zwykłe liczby. */
const blisko = (a, b, tol) => a !== null && b !== null && Math.abs(a - b) <= (tol || 4);

const V = { width: 1200, height: 800 };

module.exports = async function (t) {

  // ── Długość taśmy przelicza się po dojeździe obrazów ────────────────────
  t.section('taśma przelicza się, gdy dojadą jej obrazy');

  const p = await t.open('hscroll-obrazy.html', { viewport: V, settle: 600 });
  const przed = await p.evaluate(() => window.__stan());
  const ile   = await p.evaluate(() => window.__dojedz());
  await p.waitForTimeout(900);
  const po = await p.evaluate(() => window.__stan());

  t.check('obrazy naprawdę dojechały — taśma szersza', ile === 8
    && po.szerokoscTasmy > przed.szerokoscTasmy + 1500,
    przed.szerokoscTasmy + ' → ' + po.szerokoscTasmy + ' px');

  t.check('droga przewijania policzona na nowo',
    przed.droga !== null && po.droga > przed.droga + 1500,
    przed.droga + ' → ' + po.droga);

  // Sedno zgłoszenia. Punkt startu wyzwalacza POD przypięciem ma się zgadzać
  // z prawdziwą pozycją celu — bez jednego ręcznego `ScrollTrigger.refresh()`.
  t.check('punkt startu celu zgadza się z jego pozycją — bez ręcznego odświeżania',
    blisko(po.start, po.oczekiwanyStart),
    'start ' + po.start + ', oczekiwany ' + po.oczekiwanyStart);

  /* Bez tego powyższe byłoby prawdą także wtedy, gdyby punkt startu w ogóle się
     nie ruszył. Przed dojazdem obrazów układ jest KRÓTKI i start 808 jest w nim
     poprawny — błędem jest dopiero to, że po urośnięciu taśmy tam ZOSTAJE. */
  t.check('punkt startu przesunął się razem z taśmą',
    po.start > przed.start + 1500,
    przed.start + ' → ' + po.start);

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  // ── Kontrola negatywna: obrazy o znanym rozmiarze od początku ───────────
  t.section('a przy obrazach gotowych od razu nie ma czego przeliczać');

  const q = await t.open('hscroll-obrazy.html', { viewport: V, settle: 600, query: 'odrazu=1' });
  const q1 = await q.evaluate(() => window.__stan());
  await q.evaluate(() => window.__dojedz());
  await q.waitForTimeout(900);
  const q2 = await q.evaluate(() => window.__stan());

  t.check('droga przewijania ta sama przed i po', q1.droga === q2.droga, String(q1.droga));
  t.check('punkt startu celu dobry od pierwszego pomiaru',
    blisko(q1.start, q1.oczekiwanyStart),
    'start ' + q1.start + ', oczekiwany ' + q1.oczekiwanyStart);

  /* Szerokość potrafi urosnąć BEZ obrazu — webfont w panelu wystarczy. Wtedy
     nie leci żadne `load` i zostaje sam `ResizeObserver`. */
  await q.evaluate(() => window.__rozepchnij());
  await q.waitForTimeout(900);
  const q3 = await q.evaluate(() => window.__stan());
  t.check('zmiana szerokości bez obrazu też przelicza drogę',
    q3.droga > q2.droga + 1500, q2.droga + ' → ' + q3.droga);
  t.check('i punkt startu celu nadal się zgadza',
    blisko(q3.start, q3.oczekiwanyStart),
    'start ' + q3.start + ', oczekiwany ' + q3.oczekiwanyStart);
  await q.close();

  // ── `load` już poleciał ─────────────────────────────────────────────────
  t.section('gdy „load" poleciał przed skryptem, przeliczenie i tak jest');

  /*
   * Tu nie chodzi o to, jaki wyjdzie układ, tylko CZY przeliczenie się odbywa.
   * Sam `addEventListener('load', …)` wiesza się wtedy na zdarzeniu, którego
   * nikt już nie wywoła — i po starcie nie ma przeliczenia nigdy.
   */
  const r = await t.open('hscroll-obrazy.html', { viewport: V, settle: 1200, query: 'pozno=1' });
  const st = await r.evaluate(() => window.__stan());
  t.check('hscroll wystartował mimo wpięcia po „load"', st.droga !== null, 'droga ' + st.droga);
  t.check('przeliczenie po starcie się odbyło', st.przeliczen > 0, st.przeliczen + '×');
  t.check('bez błędów JS przy wpięciu po „load"', !r.errors.length,
    r.errors.join(' | ') || 'brak');
  await r.close();

  // ── Termin ostateczny odłożonego przeliczenia ───────────────────────────
  t.section('pilne przeliczenie nie czeka w nieskończoność');

  /*
   * Helper jedzie na stronę jako skrypt inline z PHP, więc czytamy go WPROST
   * z `includes/89-gsap.php` — kopia w teście sprawdzałaby kopię.
   */
  const php = fs.readFileSync(path.join(__dirname, '..', 'includes', '89-gsap.php'), 'utf8');
  const js  = php.match(/wp_add_inline_script\('evk-scrolltrigger', <<<'JS'\n([\s\S]*?)\n\s*JS\);/);
  t.check('helper wyjęty z 89-gsap.php', !!js && js[1].includes('evkOdswiez'),
    js ? js[1].length + ' znaków' : 'nie znaleziono');

  const s = await t.open('hscroll-obrazy.html', { viewport: V, settle: 300, query: 'odrazu=1' });

  /* Podstawiony `ScrollTrigger` liczy wywołania i nic nie robi — mierzymy sam
     harmonogram helpera, nie skutki odświeżania. */
  const wynik = await s.evaluate(async (kod) => {
    window.ScrollTrigger = { refresh: function () { window.__licz++; }, config: function () {} };
    window.__licz = 0;
    // eslint-disable-next-line no-eval
    (0, eval)(kod);

    /* Nieustające przewijanie: KAŻDE zdarzenie zeruje licznik ciszy, więc bez
       terminu ostatecznego odłożone przeliczenie nie wchodzi ani razu. */
    const spij = (ms) => new Promise((r) => setTimeout(r, ms));
    let bicie = setInterval(() => window.dispatchEvent(new Event('scroll')), 50);

    window.evkOdswiez(false);
    await spij(1600);
    const zwykle = window.__licz;

    // Cisza — i dopiero teraz zwykłe ma wejść.
    clearInterval(bicie);
    await spij(600);
    const zwyklePoCiszy = window.__licz;

    // Znów przewijanie, tym razem z wywołaniem pilnym.
    bicie = setInterval(() => window.dispatchEvent(new Event('scroll')), 50);
    window.evkOdswiez(true);
    await spij(1600);
    const pilne = window.__licz - zwyklePoCiszy;
    clearInterval(bicie);

    return { zwykle, zwyklePoCiszy, pilne };
  }, js[1]);

  t.check('pilne wchodzi mimo trwającego przewijania', wynik.pilne > 0, wynik.pilne + '×');
  t.check('a zwykłe w trakcie przewijania czeka', wynik.zwykle === 0, wynik.zwykle + '×');
  t.check('zwykłe wchodzi dopiero po ucichnięciu', wynik.zwyklePoCiszy > 0,
    wynik.zwyklePoCiszy + '×');
  await s.close();
};
