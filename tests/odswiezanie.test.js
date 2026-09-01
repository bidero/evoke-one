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

  /* ── Animator: start w dwóch fazach ────────────────────────────────────
   *
   * ZGŁOSZONE Z UŻYCIA: „elementy pojawiają się z opóźnieniem", z logu
   * `?evk-anim-debug=1` na maszynie zgłaszającego: `zaslonaMs: 2823`,
   * `kawalkow: 0`, `redukcjaRuchu: false`.
   *
   * Odtworzone na lustrze przy dławieniu procesora 6×: silnik ruszał o 1757 ms,
   * a zasłona schodziła dopiero o 2993 ms. Przez 1236 ms treść była niewidoczna,
   * choć jedyne, czego zasłona broni, to STAN POCZĄTKOWY — a nałożenie go
   * kosztuje ułamek tego czasu. Osie czasu, ScrollTriggery i podział tekstu
   * mogą powstać, gdy treść jest już na ekranie.
   *
   * Stąd podział pierwszego przebiegu: faza 1 (stany) → zdjęcie zasłony →
   * klatka → faza 2 (budowanie). Klatka jest tu mechanizmem, nie ozdobą:
   * przeglądarka maluje po zakończeniu ZADANIA, więc bez niej wcześniejsze
   * odsłonięcie nie dałoby nic.
   */
  t.section('zasłona schodzi po stanach, przed budowaniem');

  const f = await t.open('anim-fonty.html',
    { settle: 600, head: PRESETY + ZASLONA_HEAD });

  t.check('fonty pod kontrolą testu',
    await f.evaluate(() => typeof window.__fontyGotowe === 'function'), 'obietnica podstawiona');

  const wOdslonie = await f.evaluate(() => window.__wMomencieOdslony);
  t.check('migawka z chwili odsłonięcia w ogóle powstała', !!wOdslonie,
    wOdslonie ? 'jest' : 'zasłona nigdy nie zeszła albo nie było jej wcale');

  /* SEDNO. W chwili, gdy treść staje się widoczna, stan początkowy MA być
     nałożony — inaczej element mrugnie treścią i dopiero skoczy do „from". */
  t.check('stan początkowy nałożony, zanim zeszła zasłona',
    wOdslonie && wOdslonie.zwyklyOpacity <= 0.01,
    'opacity w chwili odsłonięcia: ' + (wOdslonie && wOdslonie.zwyklyOpacity));

  /* DRUGIE SEDNO, i to ono jest całą zmianą: budowania jeszcze NIE MA.
     Gdyby zasłona schodziła po nim, jak do 1.125.0, tween już by istniał. */
  t.check('a osi czasu jeszcze nie zbudowano',
    wOdslonie && wOdslonie.zwyklyTweenow === 0,
    'tweenów w chwili odsłonięcia: ' + (wOdslonie && wOdslonie.zwyklyTweenow));

  /* Podzielony tekst nie ma czego nałożyć w fazie 1 — kawałków jeszcze nie ma —
     więc zostaje pod własnym znacznikiem. To jedyny element, który po zdjęciu
     zasłony dalej jest schowany. */
  t.check('podzielony tekst czeka pod własnym znacznikiem',
    wOdslonie && wOdslonie.dzielonyCzeka && wOdslonie.dzielonyLinii === 0,
    'czeka=' + (wOdslonie && wOdslonie.dzielonyCzeka)
      + ', linii=' + (wOdslonie && wOdslonie.dzielonyLinii));

  /* ── Koniec czekania na fonty ──────────────────────────────────────────
   *
   * Obietnica `document.fonts.ready` NIE JEST tu rozwiązana i nie będzie.
   * Do 1.125.0 podział czekał na nią, a element wisiał ukryty. Dziś podział
   * powstaje w fazie 2 pierwszego przebiegu, a poprawki po wczytaniu fontów
   * robi sam SplitText przez `loadingdone` — i tylko przy podziale na linie,
   * czyli tam, gdzie metryki zmieniają łamanie.
   */
  t.section('silnik nie czeka na fonty');

  const przed = await f.evaluate(() => ({
    zwykly:   window.__stan('zwykly'),
    dzielony: window.__stan('dzielony'),
    zaslona:  window.__zaslona(),
  }));

  t.check('animacja bez podziału zbudowana', przed.zwykly.gotowy && przed.zwykly.tweenow > 0,
    'gotowy=' + przed.zwykly.gotowy + ', tweenów=' + przed.zwykly.tweenow);
  t.check('PODZIAŁ TEŻ, mimo nierozwiązanych fontów',
    przed.dzielony.gotowy && przed.dzielony.linie > 0,
    'gotowy=' + przed.dzielony.gotowy + ', linii=' + przed.dzielony.linie);
  t.check('i przestał czekać', !przed.dzielony.czeka, 'czeka=' + przed.dzielony.czeka);
  t.check('zasłona zeszła', !przed.zaslona, 'zdjęta');
  t.check('bez błędów JS (fonty)', !f.errors.length, f.errors.join(' | ') || 'brak');
  await f.close();

  // ── Redukcja ruchu ─────────────────────────────────────────────────────
  /* Przy redukcji ruchu nic się nie animuje i nic nie dzieli. Treść ma być
     widoczna od razu — u tych, którzy ruch wyłączyli właśnie po to, żeby
     strona zachowywała się spokojnie. */
  t.section('redukcja ruchu nie chowa niczego');

  const rm = await t.open('anim-fonty.html',
    { settle: 600, reduce: true, head: PRESETY + ZASLONA_HEAD });
  const rmSt = await rm.evaluate(() => window.__stan('dzielony'));
  t.check('podzielony tekst jest widoczny od razu',
    rmSt.widoczny && !rmSt.czeka,
    'widoczny=' + rmSt.widoczny + ', czeka=' + rmSt.czeka);
  t.check('bez błędów JS (redukcja)', !rm.errors.length, rm.errors.join(' | ') || 'brak');
  await rm.close();

  // ── Bezpiecznik ────────────────────────────────────────────────────────
  /* Silnik, który nie dojedzie wcale — padł CDN, sieć przerwana w pół drogi.
     Bez bezpiecznika elementy ze znacznikiem czekania zostałyby ukryte NA
     ZAWSZE. Ta sama zasada, co przy zasłonie dokumentu od 1.27.2: lepiej
     widoczna treść bez ruchu niż ruch, którego nikt nie zobaczy.

     Sprawdzamy to przy GSAP-ie, który nie przychodzi nigdy — bo przy fazie 2
     odległej o jedną klatkę zwykły przebieg jest za szybki, żeby bezpiecznik
     zdążył cokolwiek zrobić. */
  t.section('gdy silnik nie dojedzie, treść i tak się pokazuje');

  const bez = await t.open('anim-fonty.html',
    { settle: 400, head: PRESETY + ZASLONA_HEAD, query: 'gsapPozno=99000' });
  t.check('GSAP naprawdę nie dojechał',
    !(await bez.evaluate(() => typeof window.gsap === 'object')), 'brak biblioteki');

  await bez.waitForTimeout(3200);
  const poBezp = await bez.evaluate(() => ({
    zaslona: window.__zaslona(),
    dzielony: window.__stan('dzielony'),
  }));
  t.check('zasłona zeszła mimo braku silnika', !poBezp.zaslona, 'zdjęta');
  t.check('i treść jest widoczna', poBezp.dzielony.widoczny, String(poBezp.dzielony.widoczny));
  await bez.close();

  /* Wyścig: fonty gotowe ZANIM dojedzie GSAP. Na wolnym łączu to zwykła kolej
     rzeczy — pliki fontów bywają w pamięci podręcznej, a biblioteka nie.
     Silnik ma po prostu zbudować wszystko, gdy już nadejdzie. */
  const wyscig = await t.open('anim-fonty.html', {
    settle: 1200, head: PRESETY + ZASLONA_HEAD, query: 'fontyOdRazu=1&gsapPozno=400',
  });
  t.check('GSAP naprawdę dojechał później',
    await wyscig.evaluate(() => typeof window.gsap === 'object'), 'jest po opóźnieniu');
  t.check('bez błędów JS przy późnym GSAP-ie',
    !wyscig.errors.length, wyscig.errors.join(' | ') || 'brak');
  const poWyscigu = await wyscig.evaluate(() => window.__stan('dzielony'));
  /* Kontrola pozytywna: „bez błędów" spełniłby też silnik, który nie zrobił
     nic. Podział ma być zbudowany. */
  t.check('podział i tak powstał', poWyscigu.gotowy && poWyscigu.linie > 0,
    'gotowy=' + poWyscigu.gotowy + ', linii=' + poWyscigu.linie);
  await wyscig.close();
};
