/**
 * Wspólny zamek na przewijanie.
 *
 * Zgłoszone z użycia: „strona się blokuje czasami przy przewijaniu, nie wiem
 * czego to przyczyna". Objawu nie dało się powiązać z żadną czynnością, więc
 * rozpoznanie poszło przez kod i dało trzy pewne usterki w tej rodzinie:
 *
 *  1. Circular Menu ustawiało atrybut `evk-cm-scroll-locked`, którego NIE
 *     CZYTAŁA żadna reguła CSS w całej wtyczce — blokada była atrapą.
 *  2. Offcanvas blokował naprawdę, ale nie zatrzymywał Lenisa: dokument stał,
 *     a płynne przewijanie jechało dalej swoją wirtualną pozycję. Po zamknięciu
 *     obie się nie zgadzały i przewijanie wyglądało na martwe.
 *  3. Lenis miał trzy nazwy globalne (`evkLenis`, `lenisInstance`, `lenis`),
 *     z których ustawiana była jedna.
 *
 * Stąd JEDEN zamek ze ZBIOREM IMION trzymających. Zbiór, a nie licznik, bo
 * licznik nie odróżnia „drugi zamknął" od „ten sam zamknął dwa razy" — a to
 * druga sytuacja zostawia stronę zablokowaną na stałe.
 */

const { phpOutput, tagContent } = require('./lib/harness');

const GLOWA = phpOutput('scroll-lock-head.php');
/* Skrypt modułu płynnego przewijania w obu trybach tempa — patrz sekcja niżej. */
const LENIS_LERP = phpOutput('lenis-inline.php');
const LENIS_DUR  = phpOutput('lenis-inline.php', 'duration');
const CSS   = tagContent(GLOWA, 'evk-scroll-lock-css');
const JS    = tagContent(GLOWA, 'evk-scroll-lock');

module.exports = async function (t) {
  /* Zamek jedzie do <head> tak samo jak polityka ruchu — ma być gotowy, zanim
     ruszy cokolwiek ze stopki. Fixture dostaje OBIE części: skrypt od razu
     (definiuje `window.evkScroll`, nie potrzebuje drzewa), arkusz po zbudowaniu
     dokumentu. Bez arkusza „blokada blokuje" byłaby nie do zmierzenia — a to
     właśnie ARKUSZU brakowało w usterce, którą ta wersja naprawia. */
  const GLOWA_JS = JS + '\ndocument.addEventListener("DOMContentLoaded",function(){'
    + 'var s=document.createElement("style");s.textContent=' + JSON.stringify(CSS)
    + ';document.head.appendChild(s);});';

  const open = (opts) => t.open('scroll-lock.html', Object.assign({
    viewport: { width: 1000, height: 600 },
    head: GLOWA_JS,
  }, opts || {}));

  // ── Arkusz ─────────────────────────────────────────────────────────────
  t.section('reguła blokady istnieje i coś robi');

  /* To jest sedno usterki numer 1: poprzednia blokada ustawiała znacznik,
     którego nie czytało NIC. Sprawdzamy więc nie samą klasę, tylko regułę. */
  t.check('arkusz gasi przewijanie na klasie', /\.evk-scroll-locked[^{]*\{[^}]*overflow:\s*hidden/.test(CSS),
    'reguła jest');
  t.check('i robi to z !important', /overflow:\s*hidden\s*!important/.test(CSS), '!important');

  // ── Zamek zatrzymuje płynne przewijanie ────────────────────────────────
  t.section('zamek a płynne przewijanie');

  const p = await open();

  const przed = await p.evaluate(() => window.__lenisLog.slice());
  t.check('na starcie nikt Lenisa nie ruszał', przed.length === 0, JSON.stringify(przed));

  await p.evaluate(() => window.evkScroll.lock('menu'));
  const poLock = await p.evaluate(() => window.__lenisLog.slice());
  /* USTERKA NUMER 2. Bez tego dokument stoi, a Lenis przewija dalej — i po
     odblokowaniu pozycje się nie zgadzają. */
  t.check('lock ZATRZYMUJE Lenisa', JSON.stringify(poLock) === '["stop"]', JSON.stringify(poLock));

  const wTrakcie = await p.evaluate(() => window.evkScroll.stan());
  t.check('klasa blokady jest na <html>', wTrakcie.klasa, String(wTrakcie.klasa));
  t.check('a trzymający jest wymieniony z imienia',
    JSON.stringify(wTrakcie.trzymajacy) === '["menu"]', JSON.stringify(wTrakcie.trzymajacy));

  /* Blokada ma być WIDOCZNA w stylu wyliczonym, nie tylko w klasie — to jest
     różnica między „ustawiliśmy znacznik" a „strona naprawdę stoi". */
  const overflow = await p.evaluate(() => getComputedStyle(document.documentElement).overflow);
  t.check('przewijanie dokumentu naprawdę zgaszone', /hidden/.test(overflow), overflow);

  await p.evaluate(() => window.evkScroll.unlock('menu'));
  const poUnlock = await p.evaluate(() => window.__lenisLog.slice());
  t.check('unlock WZNAWIA Lenisa', JSON.stringify(poUnlock) === '["stop","start"]', JSON.stringify(poUnlock));
  t.check('i zdejmuje klasę', !(await p.evaluate(() => window.evkScroll.stan().klasa)), 'zdjęta');

  // ── Dwóch trzymających ─────────────────────────────────────────────────
  t.section('dwóch trzymających, dwa odblokowania');

  await p.evaluate(() => { window.__lenisLog.length = 0; });
  await p.evaluate(() => { window.evkScroll.lock('menu'); window.evkScroll.lock('offcanvas'); });
  t.check('drugi lock nie zatrzymuje Lenisa po raz drugi',
    JSON.stringify(await p.evaluate(() => window.__lenisLog.slice())) === '["stop"]',
    JSON.stringify(await p.evaluate(() => window.__lenisLog.slice())));

  await p.evaluate(() => window.evkScroll.unlock('menu'));
  const poPierwszym = await p.evaluate(() => window.evkScroll.stan());
  /* Sedno zbioru: jedno menu się zamknęło, drugie nadal stoi otwarte — strona
     ma ZOSTAĆ zablokowana. Przy blokadach prywatnych każdy element odkręcał
     swoją i wygrywał ten, który zamknął się jako pierwszy. */
  t.check('po pierwszym odblokowaniu strona DALEJ stoi',
    poPierwszym.zablokowane && poPierwszym.klasa,
    JSON.stringify(poPierwszym.trzymajacy));

  await p.evaluate(() => window.evkScroll.unlock('offcanvas'));
  t.check('a po drugim wraca do przewijania',
    !(await p.evaluate(() => window.evkScroll.stan().zablokowane)), 'odblokowana');

  // ── Błędy w parowaniu wywołań ──────────────────────────────────────────
  t.section('parowanie lock i unlock');

  await p.evaluate(() => { window.evkScroll.lock('menu'); window.evkScroll.lock('menu'); });
  await p.evaluate(() => window.evkScroll.unlock('menu'));
  /* Podwójne `lock` tym samym imieniem to jedno wejście do zbioru, więc jedno
     `unlock` wystarcza. Przy liczniku strona zostałaby zablokowana na stałe —
     i to jest dokładnie ten błąd, który daje objaw „strona się blokuje". */
  t.check('podwójny lock tym samym imieniem odkręca się jednym unlockiem',
    !(await p.evaluate(() => window.evkScroll.stan().zablokowane)), 'odblokowana');

  await p.evaluate(() => { window.evkScroll.lock('menu'); });
  await p.evaluate(() => window.evkScroll.unlock('ktos-inny'));
  const poObcym = await p.evaluate(() => window.evkScroll.stan());
  /* Odblokowanie przez kogoś, kto nie blokował, NIE MOŻE zdjąć cudzej blokady —
     inaczej strona jedzie pod otwartym panelem. */
  t.check('obcy unlock nie zdejmuje cudzej blokady',
    poObcym.zablokowane && JSON.stringify(poObcym.trzymajacy) === '["menu"]',
    JSON.stringify(poObcym.trzymajacy));
  await p.evaluate(() => window.evkScroll.unlock('menu'));

  // ── Kompensata paska przewijania ───────────────────────────────────────
  t.section('kompensata paska przewijania');

  const szer = await p.evaluate(() => document.documentElement.clientWidth);
  await p.evaluate(() => window.evkScroll.lock('menu'));
  const poBlokadzie = await p.evaluate(() => ({
    szer: document.documentElement.clientWidth,
    pad:  document.body.style.paddingRight,
  }));
  /* Bez kompensaty `overflow: hidden` zabiera pasek i cała strona przeskakuje
     o kilkanaście pikseli w chwili otwarcia panelu.

     Najpierw dowód, że jest CO kompensować: fixture wymusza klasyczny pasek,
     bo przy nakładkowym (domyślnym w przeglądarce testowej) luka wynosi zero
     i sprawdzenie niżej byłoby zielone bez żadnej kompensaty. */
  t.check('fixture naprawdę ma pasek zajmujący miejsce',
    parseInt(poBlokadzie.pad, 10) > 0, poBlokadzie.pad || 'BRAK — nie ma czego mierzyć');
  t.check('szerokość treści nie skacze', Math.abs(poBlokadzie.szer - szer) <= 1,
    szer + ' → ' + poBlokadzie.szer + ' (padding ' + (poBlokadzie.pad || 'brak') + ')');
  await p.evaluate(() => window.evkScroll.unlock('menu'));
  t.check('a kompensata schodzi razem z blokadą',
    !(await p.evaluate(() => document.body.style.paddingRight)), 'zdjęta');

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  /* ── Płynne przewijanie: jedno pokrętło tempa ───────────────────────────
   *
   * Lenis przyjmuje `duration` ALBO `lerp` — to dwa wykluczające się sposoby
   * sterowania tempem. Do 1.94.0 wysyłaliśmy oba naraz (potwierdzone w kodzie
   * żywej strony: `duration: 1.00` i `lerp: 0.100`), więc biblioteka brała
   * jeden, a drugie pokrętło w panelu po cichu nic nie robiło — i nie było jak
   * zgadnąć które.
   */
  t.section('płynne przewijanie — jedno pokrętło tempa');

  const maDuration = (js) => /^\s*duration:/m.test(js);
  const maLerp     = (js) => /^\s*lerp:/m.test(js);

  t.check('tryb „wygładzanie" emituje SAM lerp',
    maLerp(LENIS_LERP) && !maDuration(LENIS_LERP),
    'lerp ' + maLerp(LENIS_LERP) + ', duration ' + maDuration(LENIS_LERP));
  t.check('tryb „czas trwania" emituje SAM duration',
    maDuration(LENIS_DUR) && !maLerp(LENIS_DUR),
    'lerp ' + maLerp(LENIS_DUR) + ', duration ' + maDuration(LENIS_DUR));

  /* Kotwice. Selektor łapał `a[href^="#"]`, czyli także gołe `#` używane przez
     akordeony i zakładki — dostawały `preventDefault()` i przestawały działać.
     Sprawdzamy oba warunki, bo sama obecność `#` w kodzie nic nie mówi. */
  t.check('gołe # jest wyłączone z przejmowania', /href === '#'/.test(LENIS_LERP),
    'warunek jest');
  t.check('a cel musi istnieć w dokumencie',
    /querySelector\(href\)/.test(LENIS_LERP) && /if \(!cel\) return/.test(LENIS_LERP),
    'sprawdzenie celu jest');

  /* Kanoniczna nazwa. Trzy nazwy dla jednej rzeczy były tu sednem usterki. */
  t.check('moduł publikuje Lenisa pod jedną nazwą',
    /window\.evkLenis = lenis/.test(LENIS_LERP), 'window.evkLenis');

  // ── Diagnostyka ────────────────────────────────────────────────────────
  t.section('diagnostyka na żądanie');

  const cichy = await open();
  await cichy.evaluate(() => { window.evkScroll.lock('a'); window.evkScroll.unlock('b'); });
  /* Bez parametru konsola ma MILCZEĆ — raport na produkcji każdego użytkownika
     jest hałasem, a ostrzeżenie o obcym unlocku ma się pojawiać tam, gdzie ktoś
     go szuka. */
  t.check('bez parametru konsola milczy',
    !cichy.warnings.length && !cichy.errors.length,
    (cichy.warnings.concat(cichy.errors)).join(' | ') || 'cisza');
  await cichy.close();

  const gadatliwy = await open({ query: 'evk-scroll-debug=1' });
  await gadatliwy.evaluate(() => window.evkScroll.unlock('nikt-taki'));
  t.check('z parametrem obcy unlock daje ostrzeżenie',
    gadatliwy.warnings.some((w) => /unlock bez wcześniejszego lock/.test(w)),
    gadatliwy.warnings[0] || 'BRAK');
  await gadatliwy.close();
};
