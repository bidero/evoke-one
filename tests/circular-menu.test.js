/**
 * Circular Menu — element Bricks.
 *
 * Element istniał od dawna i NIE MIAŁ ŻADNEGO TESTU. To jest część odpowiedzi
 * na zgłoszenie „animacje w środku nie działają": nic tego nie pilnowało.
 * Dlatego poza nowymi funkcjami ten plik mierzy też rzeczy, które powinny
 * działać od początku — otwieranie, zamykanie, aria-expanded, portal.
 *
 * Jedna rzecz jest tu trudniejsza do zmierzenia, niż wygląda:
 *
 * **Panel jest widoczny dla ScrollTriggera przez cały czas.** Ma
 * `position: fixed; inset: 0`, a chowa go OBCIĘCIE (`clip-path: circle(0px)`).
 * Prostokąt panelu jest więc zawsze prostokątem okna — otwarty czy zamknięty —
 * i to samo dotyczy wszystkiego, co w nim leży. Wyzwalacz „wejście w kadr"
 * wystrzeliwał przez to raz, przy ładowaniu strony, w panelu, którego nikt
 * jeszcze nie widział, i po `once: true` znikał. Treść po otwarciu po prostu
 * BYŁA. Ta sama przyczyna co w offcanvas przed 1.59.0, tylko objaw inny:
 * tam panel stał poza ekranem, tu jest przycięty do zera.
 *
 * Stąd też jedyna miara stanu menu w tym pliku — promień obcięcia. Prostokąty
 * i widoczność nie odpowiadają tu na pytanie „czy menu jest otwarte".
 */

const { phpOutput } = require('./lib/harness');

const V = { width: 1200, height: 800 };

module.exports = async function (t) {

  // ── Otwieranie i zamykanie ─────────────────────────────────────────────
  t.section('otwieranie i zamykanie');

  const p = await t.open('circular-menu.html', { viewport: V, query: 'dur=0.2', settle: 300 });

  t.check('panel przeniesiony do <body>',
    (await p.evaluate(() => window.__panelParent())) === 'body',
    String(await p.evaluate(() => window.__panelParent())));
  t.check('na start kadr jest zwinięty', !(await p.evaluate(() => window.__rozwiniety())),
    (await p.evaluate(() => window.__clip())).raw);
  // Bez tego `aria-expanded` pojawiało się dopiero po pierwszym kliknięciu —
  // do tej chwili czytnik ekranu nie miał skąd wiedzieć, że burger cokolwiek
  // rozwija.
  t.check('trigger od razu mówi, że zamknięte',
    (await p.evaluate(() => window.__aria())) === 'false',
    String(await p.evaluate(() => window.__aria())));

  await p.evaluate(() => window.__open());
  await p.waitForTimeout(400);
  t.check('po kliknięciu kadr się rozwinął', await p.evaluate(() => window.__rozwiniety()),
    (await p.evaluate(() => window.__clip())).raw);
  t.check('trigger mówi, że otwarte', (await p.evaluate(() => window.__aria())) === 'true',
    String(await p.evaluate(() => window.__aria())));
  // Klasa `--opened` na przycisku to jedyny hak dla animowanego burgera.
  t.check('przycisk dostał klasę otwarcia', await p.evaluate(() => window.__opened()),
    'burger--opened');
  t.check('panel przyjmuje kliknięcia', (await p.evaluate(() => window.__pe())) === 'all',
    String(await p.evaluate(() => window.__pe())));

  await p.evaluate(() => window.__key('Escape'));
  await p.waitForTimeout(400);
  t.check('Esc zwija kadr', !(await p.evaluate(() => window.__rozwiniety())),
    (await p.evaluate(() => window.__clip())).raw);
  t.check('trigger wraca do „zamknięte"', (await p.evaluate(() => window.__aria())) === 'false',
    String(await p.evaluate(() => window.__aria())));
  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  // Portal wyłączony — panel ma zostać tam, gdzie go wstawiono.
  const np = await t.open('circular-menu.html', { viewport: V, query: 'portal=0', settle: 250 });
  t.check('przy wyłączonym portalu panel zostaje w korzeniu',
    (await np.evaluate(() => window.__panelParent())) === 'div',
    String(await np.evaluate(() => window.__panelParent())));
  await np.close();

  // ── Animacje grają przy KAŻDYM otwarciu ────────────────────────────────
  // Sedno zgłoszenia. Wyzwalacz wystrzelił raz, przy ładowaniu strony,
  // w przyciętym do zera panelu — i po `once: true` przestał istnieć.
  t.section('animacje w panelu grają za każdym otwarciem');

  const an = await t.open('circular-menu.html', { viewport: V, query: 'dur=0.2', settle: 300 });

  // To NIE jest sprawdzenie poprawności, tylko udokumentowanie przyczyny:
  // animacja odegrała się w niewidocznym panelu, zanim ktokolwiek go otworzył.
  // Dlatego powtórka przy otwarciu jest konieczna, a nie kosmetyczna.
  t.check('przed otwarciem treść stoi w stanie KOŃCOWYM (stąd powtórka)',
    (await an.evaluate(() => window.__op('anim'))) > 0.95,
    'opacity ' + (await an.evaluate(() => window.__op('anim'))));

  await an.evaluate(() => window.__open());
  await an.waitForTimeout(30);
  const pierwsze = await an.evaluate(() => window.__op('anim'));
  t.check('pierwsze otwarcie zaczyna od stanu początkowego', pierwsze < 0.6,
    'opacity ' + pierwsze);

  await an.waitForTimeout(500);
  t.check('pierwsze otwarcie dochodzi do końca',
    (await an.evaluate(() => window.__op('anim'))) > 0.95,
    'opacity ' + (await an.evaluate(() => window.__op('anim'))));

  await an.evaluate(() => window.__key('Escape'));
  await an.waitForTimeout(400);
  await an.evaluate(() => window.__open());
  await an.waitForTimeout(30);
  const drugie = await an.evaluate(() => window.__op('anim'));
  t.check('DRUGIE otwarcie też zaczyna od stanu początkowego', drugie < 0.6,
    'opacity ' + drugie + ' (przy pierwszym ' + pierwsze + ')');

  await an.waitForTimeout(500);
  t.check('drugie otwarcie też dochodzi do końca',
    (await an.evaluate(() => window.__op('anim'))) > 0.95,
    'opacity ' + (await an.evaluate(() => window.__op('anim'))));
  t.check('bez błędów JS przy powtórce', !an.errors.length, an.errors.join(' | ') || 'brak');
  await an.close();

  // ── Opóźnienie treści ──────────────────────────────────────────────────
  // Kadr ma swoje tempo, treść swoje. Bez odstępu oba ruchy startują w tej
  // samej klatce i całość wygląda sztywno — ten sam problem, który w offcanvas
  // rozwiązało opóźnienie panelu podrzędnego.
  //
  // Przez czas odstępu treść MUSI stać w stanie POCZĄTKOWYM. Samo odłożenie
  // startu zostawiłoby ją w stanie końcowym z poprzedniego otwarcia — widoczną,
  // a potem migającą do początku w chwili ruszenia.
  t.section('treść rusza z opóźnieniem, a nie razem z kadrem');

  const dl = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.6&cdelay=0.4', settle: 300 });
  await dl.evaluate(() => window.__open());
  await dl.waitForTimeout(120);

  t.check('kadr już się rozwija', (await dl.evaluate(() => window.__clip())).r > 5,
    (await dl.evaluate(() => window.__clip())).raw);
  t.check('treść jeszcze STOI w stanie początkowym',
    (await dl.evaluate(() => window.__op('anim'))) < 0.05,
    'opacity ' + (await dl.evaluate(() => window.__op('anim'))));

  await dl.waitForTimeout(900);
  t.check('po odstępie treść dojeżdża do końca',
    (await dl.evaluate(() => window.__op('anim'))) > 0.95,
    'opacity ' + (await dl.evaluate(() => window.__op('anim'))));
  t.check('bez błędów JS przy odstępie', !dl.errors.length, dl.errors.join(' | ') || 'brak');
  await dl.close();

  // KONTROLA NEGATYWNA: bez odstępu treść rusza razem z kadrem. Bez tej pary
  // „opacity 0 po 120 ms" przechodziłoby też dla animacji, która w ogóle nie gra.
  const nd = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.6&cdelay=0', settle: 300 });
  await nd.evaluate(() => window.__open());
  await nd.waitForTimeout(120);
  t.check('bez odstępu treść rusza RAZEM z kadrem',
    (await nd.evaluate(() => window.__op('anim'))) > 0.05,
    'opacity ' + (await nd.evaluate(() => window.__op('anim'))));
  await nd.close();

  // ── Krzywe ze wspólnej listy ───────────────────────────────────────────
  // Do 1.68.0 element miał WŁASNĄ listę: same rodziny GSAP-a („power2"),
  // bez kierunku, plus pole na wartość wpisywaną ręcznie. Kopii nie da się
  // złapać pomiarem na stronie — obie listy dają krzywe, które GSAP przyjmie —
  // więc lista jedzie tu wprost z PHP.
  t.section('krzywe z tej samej listy, co reszta wtyczki');

  const php = JSON.parse(phpOutput('circular-controls.php'));

  // Pierwsza pozycja to „— domyślna —" (pusta wartość), reszta ma być
  // dokładnie wspólną listą, w tej samej kolejności.
  t.check('lista kontrolki to wspólna lista Animatora',
    JSON.stringify(php.easingOptions) === JSON.stringify([''].concat(php.sharedEasings)),
    php.easingOptions.length + ' pozycji, wspólna ma ' + php.sharedEasings.length);
  t.check('pole na ręcznie wpisaną krzywą zniknęło', !php.hasCustomEasing,
    php.hasCustomEasing ? 'nadal jest' : 'usunięte');
  t.check('doszły kontrolki opóźnienia i wyjścia',
    php.hasContentDelay && php.hasAnimateExit,
    'opóźnienie ' + php.hasContentDelay + ', wyjście ' + php.hasAnimateExit);
  t.check('„zamknięcie menu" jest na liście wyzwalaczy',
    php.triggers.indexOf('menu-close') >= 0, php.triggers.join(', '));

  // Ustawienia mają DOJECHAĆ na front — kontrolka bez atrybutu nic nie znaczy.
  t.check('wypełnione pola trafiają w atrybuty',
    /data-easing="back\.out\(1\.7\)"/.test(php.renderFilled)
    && /data-content-delay="0\.25"/.test(php.renderFilled)
    && /data-anim-exit="1"/.test(php.renderFilled),
    php.renderFilled.replace(/^.*?(data-easing[^>]*)>.*$/s, '$1').slice(0, 120));
  t.check('domyślnie wyjście treści jest wyłączone',
    /data-anim-exit="0"/.test(php.renderPlain) && /data-content-delay="0"/.test(php.renderPlain),
    'bez zmiany dotychczasowego zachowania');

  // I druga połowa: GSAP musi rozumieć KAŻDĄ wartość z tej listy wprost.
  // To menu animuje GSAP-em, więc nie ma tu tłumaczenia na zapis CSS-a,
  // które ratuje offcanvas — nieznana nazwa poszłaby w krzywą domyślną.
  const eas = await t.open('circular-menu.html', { viewport: V, settle: 250 });
  const zle = [];
  for (const name of php.sharedEasings) {
    const v = await eas.evaluate((n) => window.__ease(n), name);
    if (v === null) zle.push(name);
  }
  t.check('GSAP rozumie każdą krzywą z listy', zle.length === 0,
    zle.length ? 'nie rozumie: ' + zle.join(', ') : php.sharedEasings.length + ' krzywych');

  // Krzywa naprawdę ZMIENIA ruch — sama akceptacja nazwy to za mało.
  const lin  = await eas.evaluate(() => window.__ease('none'));
  const back = await eas.evaluate(() => window.__ease('back.out(1.7)'));
  t.check('krzywa z listy zmienia przebieg, a nie tylko nazwę', lin !== back,
    'none ' + lin + ' vs back.out(1.7) ' + back);
  await eas.close();

  // ── Wyjście treści przy zamykaniu ──────────────────────────────────────
  // Zgłoszone z użycia: „klikam ✕, linki znikają używając mojej animacji,
  // a dopiero po chwili panel się zamyka". Bez ustawiania czegokolwiek treść
  // wychodzi TĄ SAMĄ animacją, którą weszła — tylko od końca.
  t.section('treść wychodzi, zanim zwinie się kadr');

  const ex = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.2&exit=1', settle: 300 });
  await ex.evaluate(() => window.__open());
  await ex.waitForTimeout(600);

  await ex.evaluate(() => window.__key('Escape'));
  await ex.waitForTimeout(250);
  t.check('250 ms po ✕ kadr jest JESZCZE otwarty',
    await ex.evaluate(() => window.__rozwiniety()),
    (await ex.evaluate(() => window.__clip())).raw);
  t.check('a treść już wychodzi', (await ex.evaluate(() => window.__op('anim'))) < 0.7,
    'opacity ' + (await ex.evaluate(() => window.__op('anim'))));

  await ex.waitForTimeout(700);
  t.check('po wyjściu treści kadr się zwija', !(await ex.evaluate(() => window.__rozwiniety())),
    (await ex.evaluate(() => window.__clip())).raw);
  t.check('bez błędów JS przy wyjściu', !ex.errors.length, ex.errors.join(' | ') || 'brak');
  await ex.close();

  // KONTROLA NEGATYWNA: wyłączona opcja ma naprawdę wyłączać. Inaczej
  // „animuj wyjście" jest przełącznikiem, który nic nie przełącza.
  const ne = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.2&exit=0', settle: 300 });
  await ne.evaluate(() => window.__open());
  await ne.waitForTimeout(600);
  await ne.evaluate(() => window.__key('Escape'));
  await ne.waitForTimeout(250);
  t.check('przy wyłączonej opcji kadr zwija się OD RAZU',
    !(await ne.evaluate(() => window.__rozwiniety())),
    (await ne.evaluate(() => window.__clip())).raw);
  await ne.close();

  // ── Cofnięcie działa po `clearProps` ───────────────────────────────────
  // To był otwarty punkt planu, nie oczywistość. Animacja wejściowa bez
  // powtarzania kończy z `clearProps: transform,filter,clipPath`, więc
  // inline'owy zapis przesunięcia ZNIKA po jej zakończeniu. Pytanie brzmiało,
  // czy `reverse()` ma jeszcze co odtwarzać — GSAP trzyma wartości brzegowe
  // w tweenach z chwili inicjalizacji, ale to było przypuszczenie.
  // Zmierzone: ma.
  t.section('cofnięcie odtwarza stan sprzed animacji mimo clearProps');

  const cp = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.2&exit=1', settle: 300 });
  await cp.evaluate(() => window.__open());
  await cp.waitForTimeout(600);

  const inline = await cp.evaluate(() => window.__inline('anim'));
  t.check('po wejściu inline\'owej transformacji NIE MA', !/transform/.test(inline),
    'style="' + inline + '"');
  t.check('a element stoi na swoim miejscu', (await cp.evaluate(() => window.__ty('anim'))) === 0,
    'przesunięcie ' + (await cp.evaluate(() => window.__ty('anim'))) + ' px');

  await cp.evaluate(() => window.__key('Escape'));
  await cp.waitForTimeout(900);
  t.check('cofnięcie wraca do przesunięcia z „from"',
    (await cp.evaluate(() => window.__ty('anim'))) === 40,
    'przesunięcie ' + (await cp.evaluate(() => window.__ty('anim'))) + ' px (from: 40)');
  t.check('i do przezroczystości z „from"',
    (await cp.evaluate(() => window.__op('anim'))) < 0.05,
    'opacity ' + (await cp.evaluate(() => window.__op('anim'))));
  await cp.close();

  // ── Własny preset wyjścia wygrywa z cofaniem ───────────────────────────
  // Element `#oba` niesie DWIE animacje: wejściową i własną z wyzwalaczem
  // „Zamknięcie menu". Różnicę widać po osi X — cofnięcie wejścia jej nie
  // dotyka, bo wejście animuje wyłącznie przezroczystość i oś Y.
  t.section('preset „zamknięcie menu" wygrywa z cofaniem');

  const pr = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.2&exit=1', settle: 300 });
  t.check('element ma zbudowane obie osie',
    (await pr.evaluate(() => window.__osie('oba'))).wejscia === 1
    && (await pr.evaluate(() => window.__osie('oba'))).wyjscia === 1,
    JSON.stringify(await pr.evaluate(() => window.__osie('oba'))));

  await pr.evaluate(() => window.__open());
  await pr.waitForTimeout(600);
  t.check('przy otwartym menu preset zamknięcia NIE zagrał',
    (await pr.evaluate(() => window.__tx('oba'))) === 0,
    'przesunięcie ' + (await pr.evaluate(() => window.__tx('oba'))) + ' px');

  await pr.evaluate(() => window.__key('Escape'));
  await pr.waitForTimeout(900);
  t.check('po zamknięciu zagrał WŁASNY preset, nie cofnięcie',
    (await pr.evaluate(() => window.__tx('oba'))) > 150,
    'przesunięcie ' + (await pr.evaluate(() => window.__tx('oba'))) + ' px (cel 200)');

  // Element bez własnego wyjścia jedzie cofnięciem — obie drogi naraz,
  // w jednym panelu.
  t.check('sąsiad bez własnego wyjścia cofnął wejście',
    (await pr.evaluate(() => window.__ty('anim'))) === 40,
    'przesunięcie ' + (await pr.evaluate(() => window.__ty('anim'))) + ' px');

  // Ponowne otwarcie musi ODKRĘCIĆ wyjście. Bez tego treść, która raz wyszła,
  // zostaje poza ekranem i przy drugim otwarciu menu jest puste.
  await pr.evaluate(() => window.__open());
  await pr.waitForTimeout(600);
  t.check('drugie otwarcie wraca ze stanu po wyjściu',
    (await pr.evaluate(() => window.__tx('oba'))) === 0,
    'przesunięcie ' + (await pr.evaluate(() => window.__tx('oba'))) + ' px');
  t.check('bez błędów JS przy własnym wyjściu', !pr.errors.length,
    pr.errors.join(' | ') || 'brak');
  await pr.close();

  // ── Zbieg zamknięć ─────────────────────────────────────────────────────
  // Zamknięcie jest teraz ODŁOŻONE, więc po raz pierwszy istnieje okno,
  // w którym menu jest jeszcze otwarte, a zamknięcie już w drodze. Źródeł
  // zamykania jest tu cztery: ✕, klik poza panelem, Esc i focusout — zbieg
  // dwóch naraz nie jest teoretyczny.
  t.section('zamknięcie w drodze nie mnoży się i daje się cofnąć');

  const rc = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.2&exit=1', settle: 300 });
  await rc.evaluate(() => window.__open());
  await rc.waitForTimeout(600);

  await rc.evaluate(() => window.__key('Escape'));
  await rc.waitForTimeout(60);
  await rc.evaluate(() => window.__key('Escape'));
  await rc.waitForTimeout(900);
  t.check('dwa Esc pod rząd zamykają raz i do końca',
    !(await rc.evaluate(() => window.__rozwiniety()))
    && (await rc.evaluate(() => window.__aria())) === 'false',
    (await rc.evaluate(() => window.__clip())).raw + ', aria ' +
      (await rc.evaluate(() => window.__aria())));

  // Otwarcie w trakcie wychodzenia ma ODWOŁAĆ zaległe zamknięcie. Bez tego
  // menu zamyka się samo ułamek sekundy po ponownym otwarciu.
  await rc.evaluate(() => window.__open());
  await rc.waitForTimeout(600);
  await rc.evaluate(() => window.__key('Escape'));
  await rc.waitForTimeout(100);
  await rc.evaluate(() => window.__open());
  await rc.waitForTimeout(600);
  t.check('otwarcie w trakcie wychodzenia odwołuje zamknięcie',
    await rc.evaluate(() => window.__rozwiniety()),
    (await rc.evaluate(() => window.__clip())).raw);
  t.check('i treść wraca do pełnej widoczności',
    (await rc.evaluate(() => window.__op('anim'))) > 0.95,
    'opacity ' + (await rc.evaluate(() => window.__op('anim'))));
  t.check('bez błędów JS przy zbiegu zamknięć', !rc.errors.length,
    rc.errors.join(' | ') || 'brak');
  await rc.close();

  // ── Redukcja ruchu ─────────────────────────────────────────────────────
  // Menu MUSI się nadal otwierać i zamykać: znika ruch, nie dostęp do
  // nawigacji. Odłożone zamknięcie jest tu szczególnie groźne — czekanie na
  // animację, której nie ma, trzymałoby menu otwarte bez powodu.
  t.section('redukcja ruchu — menu zamyka się natychmiast');

  const rm = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.4&exit=1', settle: 300, reduce: true });
  await rm.evaluate(() => window.__open());
  await rm.waitForTimeout(60);
  t.check('kadr i tak się otwiera', await rm.evaluate(() => window.__rozwiniety()),
    (await rm.evaluate(() => window.__clip())).raw);
  // Przy redukcji silnik animacji wraca przed zbudowaniem czegokolwiek, więc
  // nie ma osi do cofania — i to jest powód, dla którego czekanie wychodzi zero.
  t.check('żadna oś czasu nie powstała',
    (await rm.evaluate(() => window.__osie('anim'))).wejscia === 0,
    JSON.stringify(await rm.evaluate(() => window.__osie('anim'))));

  await rm.evaluate(() => window.__key('Escape'));
  await rm.waitForTimeout(40);
  t.check('zamknięcie jest natychmiastowe', !(await rm.evaluate(() => window.__rozwiniety())),
    (await rm.evaluate(() => window.__clip())).raw);
  t.check('bez błędów JS przy redukcji', !rm.errors.length, rm.errors.join(' | ') || 'brak');
  await rm.close();

  // KONTROLA NEGATYWNA. „Zamknięte po 40 ms" jest prawdą także wtedy, gdy
  // skrypt w ogóle nie wystartował albo gdy opcja nic nie robi.
  const nr = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.4&exit=1', settle: 300 });
  await nr.evaluate(() => window.__open());
  await nr.waitForTimeout(600);
  await nr.evaluate(() => window.__key('Escape'));
  await nr.waitForTimeout(40);
  t.check('bez redukcji 40 ms po ✕ menu jest JESZCZE otwarte',
    await nr.evaluate(() => window.__rozwiniety()),
    (await nr.evaluate(() => window.__clip())).raw);
  await nr.close();
};
