/**
 * Odświeżanie wyzwalaczy ScrollTriggera.
 *
 * Zgłoszone z użycia: na telefonie, zaraz po wczytaniu strony, mocne machnięcie
 * palcem → strona jedzie bezwładnie, zwalnia i NAGLE STAJE, choć powinna
 * przewijać się dalej. Tylko iOS. Po wyłączeniu Animatora objaw znikał.
 *
 * Mechanizm ustalony pomiarem, krok po kroku:
 *
 *  1. `ScrollTrigger.refresh()` ZAPISUJE pozycję przewijania — skacze na samą
 *     górę i wraca. Zmierzone szpiegiem pod `window.scrollTo`:
 *     `scrollTo:0,0` … `scrollTo:0,1200`. Na desktopie niewidoczne (jedna
 *     klatka), na iOS zapis w trakcie bezwładności kasuje ją natychmiast.
 *  2. Marquee wołał ten refresh po KAŻDEJ zmianie rozmiaru dokumentu.
 *  3. Animator czekał z całą inicjalizacją na fonty, więc zmieniał wymiary
 *     strony sekundę po wczytaniu — wprost w pierwsze machnięcie.
 *
 * `ScrollTrigger.config({ ignoreMobileResize: true })` chroniło tylko
 * WBUDOWANĄ ścieżkę odświeżania; sześć naszych własnych wywołań ją omijało.
 */

const { phpOutput, tagContent } = require('./lib/harness');

const HELPER  = phpOutput('gsap-inline.php');

/* Zasłona Z PHP — te same reguły i ten sam mikroskrypt, które Animator drukuje
   w <head>. Przepisane do fixture'a przechodziłyby na zielono nawet wtedy,
   gdyby wtyczka przestała je drukować.

   Wstrzykiwane skryptem startowym, czyli przed czymkolwiek na stronie — tak
   samo jak na żywej stronie, gdzie blok stoi w <head> z priorytetem 1. */
const ZASLONA_PHP = phpOutput('anim-preveil.php');
const ZASLONA_HEAD =
  /* Ponawianie, bo skrypt startowy bywa szybszy niż sam <html>: zmierzone —
     `document.documentElement` jest wtedy jeszcze `null` i wstrzyknięcie
     przepadało po cichu, a testy mierzyły stronę bez zasłony. */
  '(function zal(){'
  + 'if(!document.documentElement){setTimeout(zal,0);return;}'
  + 'var s=document.createElement("style");s.textContent='
  + JSON.stringify(tagContent(ZASLONA_PHP, 'evk-anim-preveil'))
  + ';document.documentElement.appendChild(s);'
  + tagContent(ZASLONA_PHP, 'evk-anim-preveil-js')
  + '})();';
/* Presety z PHP, nie z atrapy: to one niosą `from`/`to` i znacznik podziału
   tekstu, czyli dokładnie to, o co w pomiarze fontów chodzi. */
const PRESETY = 'window.__presets = ' + JSON.stringify(JSON.parse(phpOutput('presets.php'))) + ';';

module.exports = async function (t) {
  const open = (opts) => t.open('odswiezanie.html', Object.assign({
    viewport: { width: 800, height: 600 },
    /* Helper jedzie z PHP — tak samo jak na stronie, gdzie dopina się do
       uchwytu ScrollTriggera. */
    head: 'document.addEventListener("DOMContentLoaded",function(){'
        + 'var s=document.createElement("script");s.textContent='
        + JSON.stringify(HELPER) + ';document.head.appendChild(s);});',
  }, opts || {}));

  // ── Skrypt z PHP ───────────────────────────────────────────────────────
  t.section('helper jedzie razem z konfiguracją ScrollTriggera');

  t.check('ignoreMobileResize nadal ustawiane',
    /ignoreMobileResize:\s*true/.test(HELPER), 'jest');
  t.check('a obok niego helper odświeżania',
    /window\.evkOdswiez\s*=/.test(HELPER), 'window.evkOdswiez');

  const p = await open();
  t.check('helper istnieje na stronie',
    await p.evaluate(() => typeof window.evkOdswiez === 'function'), 'funkcja');

  // ── Sedno: odświeżenie NIE ucina przewijania ───────────────────────────
  t.section('odświeżenie czeka, aż przewijanie ustanie');

  /* Najpierw dowód, że jest CO mierzyć: samo `ScrollTrigger.refresh()`
     naprawdę zapisuje pozycję. Bez tego reszta sekcji przechodziłaby także
     wtedy, gdyby refresh był niewinny — a wtedy cała ta wersja nie miałaby
     powodu istnieć. */
  await p.evaluate(() => { window.scrollTo(0, 1200); window.__zapisyOdZera(true); });
  await p.evaluate(() => ScrollTrigger.refresh());
  const goly = await p.evaluate(() => window.__zapisyOdZera(true));
  t.check('samo refresh() NAPRAWDĘ rusza pozycję przewijania',
    goly.length >= 2 && goly.some((z) => /scrollTo:0,0/.test(z)),
    JSON.stringify(goly));

  /* A teraz przez helper, w trakcie ruchu. Udajemy przewijanie samym
     zdarzeniem, żeby nie zaburzać pomiaru własnymi zapisami pozycji. */
  await p.evaluate(() => {
    window.__zapisyOdZera(true);
    window.__zacznijRuch();
    window.evkOdswiez();
  });
  /* Znacznie dłużej niż odstęp scalania (200 ms) i próg ciszy (150 ms) — przez
     cały ten czas „palec" jest w ruchu, więc odświeżenie ma NIE nadejść. */
  await p.waitForTimeout(900);
  const wTrakcie = await p.evaluate(() => window.__zapisyOdZera(false));
  t.check('w trakcie przewijania odświeżenie NIE ruszyło pozycji',
    wTrakcie.length === 0, JSON.stringify(wTrakcie));

  /* Kontrola pozytywna. Bez niej „nie rusza" spełniłoby też odświeżanie
     wyłączone na stałe — a wtedy wyzwalacze zostają z nieaktualnymi miarami. */
  await p.evaluate(() => window.__zakonczRuch());
  await p.waitForTimeout(600);
  const poCiszy = await p.evaluate(() => window.__zapisyOdZera(true));
  t.check('ale przychodzi, gdy ruch ustanie',
    poCiszy.length >= 2, JSON.stringify(poCiszy));

  // ── Scalanie ───────────────────────────────────────────────────────────
  t.section('seria wywołań daje jedno odświeżenie');

  await p.evaluate(() => {
    window.__zapisyOdZera(true);
    for (var i = 0; i < 10; i++) window.evkOdswiez();
  });
  await p.waitForTimeout(600);
  const poSerii = await p.evaluate(() => window.__zapisyOdZera(true));
  /* Jedno odświeżenie to trzy zapisy pozycji (dwa zera i powrót). Dziesięć
     odświeżeń dałoby ich trzydzieści — i tyle samo szarpnięć na telefonie. */
  t.check('dziesięć wywołań to jedno odświeżenie',
    poSerii.length > 0 && poSerii.length <= 4,
    poSerii.length + ' zapisów pozycji');

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  // ── Obserwator wysokości strony (marquee) ──────────────────────────────
  t.section('na dotyku sama wysokość nie prosi o odświeżenie');

  /* Prawdziwy marquee.js z prawdziwym ResizeObserverem na <html>. Helper jedzie
     w skrypcie startowym, czyli zanim ruszy cokolwiek ze strony — tak samo jak
     na żywej stronie, gdzie dopina się do uchwytu ScrollTriggera, a marquee od
     niego zależy. */
  const marquee = (opts) => t.open('marquee-pause.html', Object.assign({
    viewport: { width: 500, height: 700 },
    settle: 700,
    query: 'cfg=' + encodeURIComponent(JSON.stringify({ baseSpeed: 200 })),
    head: HELPER,
  }, opts || {}));

  /* Zmiana samej WYSOKOŚCI dokumentu. Na telefonie robi to chowanie paska
     adresu — dziesiątki razy w trakcie jednego przewijania, a układ ani
     drgnie. */
  const urosnij = async (page) => {
    await page.evaluate(() => { window.__prosby = 0; window.__urosnij(3000); });
    await page.waitForTimeout(500);
    return page.evaluate(() => window.__prosby);
  };

  const dotyk = await marquee({ touch: true });
  t.check('dotyk rozpoznany', !await dotyk.evaluate(
    () => matchMedia('(hover: hover)').matches), '(hover: hover) = false');
  t.check('sama wysokość NIE prosi o odświeżenie', await urosnij(dotyk) === 0, '0 próśb');

  /* Kontrola negatywna pierwsza: zmiana SZEROKOŚCI to prawdziwa zmiana układu
     i musi przejść przez pominięcie. Bez niej „nie prosi" spełniłby też
     obserwator wyłączony na dotyku na głucho — a wtedy po obrocie telefonu
     wyzwalacze zostają z miarami sprzed obrotu. */
  await dotyk.evaluate(() => { window.__prosby = 0; });
  await dotyk.setViewportSize({ width: 380, height: 700 });
  await dotyk.waitForTimeout(500);
  t.check('ale zmiana szerokości prosi',
    await dotyk.evaluate(() => window.__prosby) > 0,
    await dotyk.evaluate(() => window.__prosby) + ' próśb');
  t.check('bez błędów JS (dotyk)', !dotyk.errors.length, dotyk.errors.join(' | ') || 'brak');
  await dotyk.close();

  /* Kontrola negatywna druga: na desktopie zmiana samej wysokości MA prosić.
     Bez niej pominięcie mogłoby dotyczyć wszystkich — a na desktopie wysokość
     rośnie od doładowanej treści, nie od paska adresu, i wyzwalacze naprawdę
     wymagają przeliczenia. */
  const mysz = await marquee();
  t.check('na desktopie sama wysokość prosi', await urosnij(mysz) > 0, 'prosi');
  t.check('bez błędów JS (desktop)', !mysz.errors.length, mysz.errors.join(' | ') || 'brak');
  await mysz.close();

  // ── Ścieżka zapasowa ───────────────────────────────────────────────────
  t.section('bez helpera wołający schodzi do zwykłego refreshu');

  /* Strona BEZ naszego skryptu z <head>: inna kolejność wtyczek, strona bez
     ScrollTriggera z naszej kolejki, wpięcie z ręki. Odświeżanie ma wtedy
     działać po staremu — traci tylko ochronę przed ucinaniem bezwładności. */
  const bezHelpera = await t.open('marquee-pause.html', {
    viewport: { width: 500, height: 700 },
    settle: 700,
    query: 'cfg=' + encodeURIComponent(JSON.stringify({ baseSpeed: 200 })),
  });
  t.check('helpera naprawdę nie ma',
    await bezHelpera.evaluate(() => typeof window.evkOdswiez), 'undefined');
  await bezHelpera.evaluate(() => { window.__odswiezenia = 0; window.__urosnij(3000); });
  await bezHelpera.waitForTimeout(500);
  t.check('wyzwalacze i tak się przeliczyły',
    await bezHelpera.evaluate(() => window.__odswiezenia) > 0,
    await bezHelpera.evaluate(() => window.__odswiezenia) + ' odświeżeń');
  t.check('bez błędów JS (bez helpera)',
    !bezHelpera.errors.length, bezHelpera.errors.join(' | ') || 'brak');
  await bezHelpera.close();

  // ── Animator: na fonty czeka tylko podział tekstu ──────────────────────
  t.section('animator nie czeka z wszystkim na fonty');

  const f = await t.open('anim-fonty.html',
    { settle: 600, head: PRESETY + ZASLONA_HEAD });

  t.check('fonty pod kontrolą testu',
    await f.evaluate(() => typeof window.__fontyGotowe === 'function'), 'obietnica podstawiona');

  const przed = await f.evaluate(() => ({
    zwykly:   window.__stan('zwykly'),
    dzielony: window.__stan('dzielony'),
    zaslona:  window.__zaslona(),
  }));

  /* Sedno. Zwykła animacja gra od razu — silnik nie ma powodu czekać na fonty,
     żeby przesunąć element o dwadzieścia pikseli. */
  t.check('animacja bez podziału zbudowana przed fontami',
    przed.zwykly.gotowy && przed.zwykly.tweenow > 0,
    'gotowy=' + przed.zwykly.gotowy + ', tweenów=' + przed.zwykly.tweenow);

  /* Kontrola negatywna: podział ma poczekać. Bez tego „nie czeka na fonty"
     spełniłby też silnik, który dzieli tekst na metrykach fontu zastępczego —
     a wtedy linie łamią się w złych miejscach i po podmianie fontu zostają
     krzywe. */
  t.check('podział tekstu czeka na fonty',
    !przed.dzielony.gotowy && przed.dzielony.linie === 0,
    'gotowy=' + przed.dzielony.gotowy + ', linii=' + przed.dzielony.linie);

  /* Zasłona nie ma czekać na nic. To ona chowa treść, więc każda sekunda
     zwłoki to sekunda pustej strony. */
  t.check('zasłona zeszła przed fontami', !przed.zaslona, 'zdjęta');

  await f.evaluate(() => window.__fontyGotowe());
  await f.waitForTimeout(600);
  const po = await f.evaluate(() => window.__stan('dzielony'));
  t.check('po wczytaniu fontów podział dochodzi',
    po.gotowy && po.linie > 0,
    'gotowy=' + po.gotowy + ', linii=' + po.linie);

  t.check('bez błędów JS (fonty)', !f.errors.length, f.errors.join(' | ') || 'brak');
  await f.close();

  /* ── Element czekający na fonty NIE MOŻE być widoczny ───────────────────
   *
   * Zgłoszone z użycia po 1.96.0: „elementy, które powinny pojawić się po raz
   * pierwszy, są widoczne przed rozpoczęciem animacji i po opóźnieniu się
   * animują".
   *
   * To jest dokładnie ten błysk, dla którego w 1.27.2 powstała zasłona: element
   * stoi wyrenderowany normalnie, potem skacze do stanu początkowego i dopiero
   * animuje. 1.96.0 przywróciło go połowicznie — zasłona schodzi z dokumentu po
   * pierwszym przebiegu, a podzielone teksty czekają wtedy jeszcze na fonty.
   *
   * Zasłona dokumentu MA schodzić: reszta strony nie ma powodu czekać. Chowany
   * ma być pojedynczy element, i tylko dopóki czeka.
   */
  t.section('element czekający na fonty jest niewidoczny');

  const bl = await t.open('anim-fonty.html',
    { settle: 600, head: PRESETY + ZASLONA_HEAD });

  /* Najpierw dowód, że reguła w ogóle dojechała — bez niej cała sekcja mierzy
     stronę bez zasłony i „niewidoczny" nie miałoby prawa być prawdą. */
  t.check('reguła zasłony przyjechała z PHP',
    /\[data-evk-anim-czeka\]/.test(ZASLONA_PHP), 'jest w <head>');

  const przedB = await bl.evaluate(() => ({
    dzielony: window.__stan('dzielony'),
    zwykly:   window.__stan('zwykly'),
    zaslona:  window.__zaslona(),
  }));

  // SEDNO.
  t.check('podzielony tekst NIE jest widoczny, dopóki czeka',
    !przedB.dzielony.widoczny && przedB.dzielony.czeka,
    'widoczny=' + przedB.dzielony.widoczny + ', czeka=' + przedB.dzielony.czeka);

  /* Kontrola negatywna: element BEZ podziału ma być widoczny w tej samej
     chwili. Bez niej „niewidoczny" byłoby spełnione także wtedy, gdyby zasłona
     dokumentu po prostu nie zeszła — czyli gdyby wróciło zachowanie sprzed
     1.96.0 i cała strona czekała na fonty. */
  t.check('a animacja bez podziału jest widoczna i gra',
    przedB.zwykly.widoczny && przedB.zwykly.tweenow > 0 && !przedB.zwykly.czeka,
    'widoczny=' + przedB.zwykly.widoczny + ', tweenów=' + przedB.zwykly.tweenow);
  t.check('zasłona dokumentu i tak zeszła', !przedB.zaslona, 'zdjęta');

  await bl.evaluate(() => window.__fontyGotowe());
  await bl.waitForTimeout(400);
  const poB = await bl.evaluate(() => window.__stan('dzielony'));
  t.check('po fontach znacznik znika i tekst wchodzi',
    !poB.czeka && poB.widoczny && poB.linie > 0,
    'czeka=' + poB.czeka + ', widoczny=' + poB.widoczny + ', linii=' + poB.linie);
  t.check('bez błędów JS (zasłona)', !bl.errors.length, bl.errors.join(' | ') || 'brak');
  await bl.close();

  // ── Zwłoka ─────────────────────────────────────────────────────────────
  /* Druga połowa zgłoszenia: „jest ogólnie duże opóźnienie". Odłożony element
     czeka teraz pod zasłoną, więc każda milisekunda zwłoki to milisekunda
     pustego miejsca w treści — a 1.96.0 przepuszczało dobudowanie przez kolejkę
     bezczynności z limitem 200 ms. */
  t.section('po wczytaniu fontów tekst wchodzi OD RAZU');

  const zw = await t.open('anim-fonty.html',
    { settle: 600, head: PRESETY + ZASLONA_HEAD });

  /* Pomiar idzie MIKROZADANIEM, nie zegarem — i to jest tu istotne, nie
     kosztowne. Zmierzone: `requestIdleCallback` na bezczynnym wątku wypala
     natychmiast, więc czekanie „krócej niż limit 200 ms" NIE ODRÓŻNIA kolejki
     od jej braku i mutacja przywracająca kolejkę przechodziła na zielono.
     Limit jest górną granicą, nie zwłoką — na żywej, zajętej stronie sięga
     całych 200 ms, ale w harnessie nigdy.

     Mikrozadanie odróżnia je bez zegara: `document.fonts.ready.then` biegnie
     jako mikrozadanie, więc budowanie WPROST kończy się, zanim przeglądarka
     weźmie jakiekolwiek zadanie. Kolejka bezczynności to zadanie — po dwóch
     obrotach mikrokolejki nie zdąży wypalić ani razu. */
  const odRazu = await zw.evaluate(async () => {
    window.__fontyGotowe();
    await Promise.resolve();
    await Promise.resolve();
    return window.__stan('dzielony');
  });
  t.check('podział jest gotowy jeszcze w mikrozadaniu po fontach',
    odRazu.gotowy && odRazu.linie > 0,
    'gotowy=' + odRazu.gotowy + ', linii=' + odRazu.linie);
  t.check('i element przestał czekać', !odRazu.czeka, 'czeka=' + odRazu.czeka);
  await zw.close();

  // ── Redukcja ruchu ─────────────────────────────────────────────────────
  /* Przy redukcji ruchu nic się nie animuje i nic nie dzieli, więc metryki
     fontu są bez znaczenia. Czekanie na nie trzymałoby treść pod zasłoną przez
     sekundę bez żadnego powodu — i to u tych, którzy ruch wyłączyli właśnie
     po to, żeby strona zachowywała się spokojnie. */
  t.section('redukcja ruchu nie czeka na fonty');

  const rm = await t.open('anim-fonty.html',
    { settle: 600, reduce: true, head: PRESETY + ZASLONA_HEAD });
  const rmSt = await rm.evaluate(() => window.__stan('dzielony'));
  t.check('podzielony tekst jest widoczny od razu',
    rmSt.widoczny && !rmSt.czeka,
    'widoczny=' + rmSt.widoczny + ', czeka=' + rmSt.czeka);
  t.check('bez błędów JS (redukcja)', !rm.errors.length, rm.errors.join(' | ') || 'brak');
  await rm.close();

  // ── Bezpiecznik ────────────────────────────────────────────────────────
  /* Fonty, które nie dojadą nigdy — padł serwer, adres się zmienił, sieć
     przerwana w pół drogi. Bez bezpiecznika podzielone teksty zostałyby ukryte
     NA ZAWSZE, czyli awaria fontu zabrałaby stronie nagłówki. Ta sama zasada
     i ten sam zegar, co przy zasłonie dokumentu od 1.27.2: po trzech sekundach
     treść pokazuje się bez animacji, bo widoczna treść bez ruchu jest lepsza
     niż ruch, którego nikt nie zobaczy. */
  t.section('fonty, które nie dojechały, nie chowają treści na zawsze');

  const bez = await t.open('anim-fonty.html',
    { settle: 400, head: PRESETY + ZASLONA_HEAD });
  t.check('na starcie tekst czeka',
    (await bez.evaluate(() => window.__stan('dzielony'))).czeka, 'czeka');

  // Fontów NIE rozwiązujemy — czekamy dłużej niż bezpiecznik.
  await bez.waitForTimeout(3200);
  const poBezp = await bez.evaluate(() => window.__stan('dzielony'));
  t.check('po bezpieczniku znacznik zdjęty', !poBezp.czeka, 'czeka=' + poBezp.czeka);
  t.check('i treść jest widoczna', poBezp.widoczny, String(poBezp.widoczny));
  t.check('bez błędów JS (bezpiecznik)', !bez.errors.length, bez.errors.join(' | ') || 'brak');
  await bez.close();

  /* Wyścig: fonty gotowe ZANIM dojedzie GSAP. Na wolnym łączu to zwykła kolej
     rzeczy — pliki fontów bywają w pamięci podręcznej, a biblioteka nie.
     Przebudowa ruszona w tym momencie woła initAll() bez GSAP-a i wywraca
     Animatora na całej stronie. Ma po prostu nie ruszać: pierwszy przebieg,
     gdy już nadejdzie, zobaczy gotowe fonty i zbuduje podział od razu. */
  const wyscig = await t.open('anim-fonty.html', {
    settle: 1200, head: PRESETY + ZASLONA_HEAD, query: 'fontyOdRazu=1&gsapPozno=400',
  });
  t.check('GSAP naprawdę dojechał później',
    await wyscig.evaluate(() => typeof window.gsap === 'object'), 'jest po opóźnieniu');
  t.check('bez błędów JS przy wyścigu fontów z GSAP-em',
    !wyscig.errors.length, wyscig.errors.join(' | ') || 'brak');
  const poWyscigu = await wyscig.evaluate(() => window.__stan('dzielony'));
  /* Kontrola pozytywna: „bez błędów" spełniłby też silnik, który nie zrobił
     nic. Podział ma być zbudowany — w pierwszym przebiegu, bez przebudowy. */
  t.check('podział i tak powstał', poWyscigu.gotowy && poWyscigu.linie > 0,
    'gotowy=' + poWyscigu.gotowy + ', linii=' + poWyscigu.linie);
  await wyscig.close();
};
