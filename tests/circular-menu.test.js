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
  t.check('i klasę otwarcia Bricksa',
    (await p.evaluate(() => window.__stanPrzycisku('trigger'))).brx,
    (await p.evaluate(() => window.__stanPrzycisku('trigger'))).klasy);
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
    php.hasContentDelay && php.hasAnimateExit && php.hasExitWait,
    'opóźnienie ' + php.hasContentDelay + ', wyjście ' + php.hasAnimateExit
      + ', czekanie ' + php.hasExitWait);
  // Pole widoczne przy wyłączonym wyjściu byłoby polem, które nic nie robi.
  t.check('czekanie pokazuje się dopiero przy włączonym wyjściu',
    JSON.stringify(php.exitWaitGate) === JSON.stringify(['animateExit', '=', true]),
    JSON.stringify(php.exitWaitGate));
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

  /* Czekanie ma TRZY stany, nie dwa, i to jest tu sedno: nieustawione („cały
     czas animacji"), jawna liczba i jawne ZERO („oba ruchy naraz"). Zero
     wygląda w PHP jak brak wartości — `! empty()` nie odróżnia jednego od
     drugiego — więc wybranie „naraz" wracałoby po cichu do grania jeden ruch
     po drugim. Sprawdzamy wszystkie trzy, bo tylko razem coś znaczą. */
  t.check('nieustawione czekanie jedzie pustym atrybutem',
    /data-exit-wait=""/.test(php.renderPlain), 'puste');
  t.check('jawna liczba dojeżdża',
    /data-exit-wait="0\.15"/.test(php.renderFilled), '0,15 s');
  t.check('jawne ZERO NIE wypada jako brak wartości',
    /data-exit-wait="0"/.test(php.renderZero), '0 s — oba ruchy naraz');

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

  // ── Klasa stanu na panelu ──────────────────────────────────────────────
  // Offcanvas ma `is-open` na powłoce od początku; Circular Menu nie miało
  // czego zaczepić, bo chowa panel OBCIĘCIEM, a nie klasą. Wszystko, co ma
  // reagować na otwarcie, a nie da się tego wyrazić animacją Animatora,
  // wisiało w próżni.
  //
  // Klasa siedzi na PANELU, nie na korzeniu, i to nie jest dowolne: przy
  // portalu panel jedzie do <body> i przestaje być potomkiem korzenia, więc
  // `.evk-cm.is-open .evk-cm-content` nie miałoby czego dopasować.
  t.section('panel niesie klasę stanu dla własnego CSS-a');

  const io = await t.open('circular-menu.html', { viewport: V, query: 'dur=0.2', settle: 300 });
  t.check('zamknięty panel nie ma klasy stanu', !(await io.evaluate(() => window.__panelOpen())),
    await io.evaluate(() => window.__panelKlasy()));

  await io.evaluate(() => window.__open());
  await io.waitForTimeout(60);
  t.check('otwarcie zakłada klasę OD RAZU', await io.evaluate(() => window.__panelOpen()),
    await io.evaluate(() => window.__panelKlasy()));
  // Zaczep ma być na tym samym elemencie, który jedzie do <body> — inaczej
  // selektor przestaje działać dokładnie przy domyślnym ustawieniu portalu.
  t.check('i to na elemencie, który poszedł do <body>',
    (await io.evaluate(() => window.__panelParent())) === 'body',
    String(await io.evaluate(() => window.__panelParent())));

  await io.evaluate(() => window.__key('Escape'));
  await io.waitForTimeout(400);
  t.check('zamknięcie zdejmuje klasę', !(await io.evaluate(() => window.__panelOpen())),
    await io.evaluate(() => window.__panelKlasy()));
  t.check('bez błędów JS', !io.errors.length, io.errors.join(' | ') || 'brak');
  await io.close();

  // Sedno decyzji projektowej: klasa schodzi dopiero, gdy KADR RUSZA, a nie
  // w chwili kliknięcia. Przez czas wychodzenia treści panel stoi na ekranie
  // jak stał — styl otwartego menu ma go dalej dotyczyć, inaczej wygląd
  // przeskakiwałby pod nieruchomym panelem.
  const ic = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.2&exit=1', settle: 300 });
  await ic.evaluate(() => window.__open());
  await ic.waitForTimeout(600);
  await ic.evaluate(() => window.__key('Escape'));
  await ic.waitForTimeout(200);

  t.check('w trakcie wychodzenia treści klasa ZOSTAJE',
    await ic.evaluate(() => window.__panelOpen())
    && await ic.evaluate(() => window.__rozwiniety()),
    await ic.evaluate(() => window.__panelKlasy()));

  await ic.waitForTimeout(700);
  t.check('a po zwinięciu kadru schodzi', !(await ic.evaluate(() => window.__panelOpen())),
    await ic.evaluate(() => window.__panelKlasy()));

  // Otwarcie w trakcie wychodzenia nie może zostawić panelu bez klasy.
  await ic.evaluate(() => window.__open());
  await ic.waitForTimeout(600);
  await ic.evaluate(() => window.__key('Escape'));
  await ic.waitForTimeout(100);
  await ic.evaluate(() => window.__open());
  await ic.waitForTimeout(600);
  t.check('odwołane zamknięcie zostawia klasę na miejscu',
    await ic.evaluate(() => window.__panelOpen()),
    await ic.evaluate(() => window.__panelKlasy()));
  await ic.close();

  // ── Menu zgłasza otwarcie językiem Bricksa ─────────────────────────────
  // Zgłoszone z użycia: „Esc nie zmienia stanu przełącznika, który był użyty
  // do otwarcia". Przyczyna nie siedziała w obsłudze Esc — ta działa i jest
  // zmierzona niżej — tylko w tym, CZEGO Bricks nie widział.
  //
  // Do 1.73.0 `brx-open` nakładaliśmy WYŁĄCZNIE na przycisk. Bricks trzyma
  // jednak stan na elemencie, który OTWIERA, a wygląd przełącznika bywa z
  // niego wyprowadzony — regułą typu `.brx-open .brxe-toggle` albo własną
  // logiką przełącznika, która pyta o stan celu. Nasze menu nie zgłaszało się
  // tam w ogóle: `is-open` to nazwa Evoke, dla Bricksa nic nie znacząca.
  // Przycisk nie miał więc od czego wrócić do burgera — z jego punktu widzenia
  // menu nigdy się nie otworzyło.
  t.section('otwarte menu zgłasza się klasą, którą rozumie Bricks');

  const bx = await t.open('circular-menu.html', { viewport: V, query: 'dur=0.2', settle: 300 });
  t.check('zamknięte menu nie ma klasy Bricksa na korzeniu',
    !(await bx.evaluate(() => window.__rootBrx())),
    await bx.evaluate(() => window.__rootKlasy()));

  await bx.evaluate(() => window.__open());
  await bx.waitForTimeout(60);
  t.check('otwarcie zgłasza stan NA KORZENIU, nie tylko na przycisku',
    await bx.evaluate(() => window.__rootBrx()),
    await bx.evaluate(() => window.__rootKlasy()));
  // Korzeń, a nie panel: przy portalu panel jedzie do <body> i przestaje być
  // czymkolwiek w okolicy przełącznika, więc reguła oparta na pokrewieństwie
  // w drzewie nie miałaby czego dopasować.
  t.check('a panel poszedł do <body> — dlatego korzeń, nie panel',
    (await bx.evaluate(() => window.__panelParent())) === 'body',
    String(await bx.evaluate(() => window.__panelParent())));

  await bx.evaluate(() => window.__key('Escape'));
  await bx.waitForTimeout(400);
  t.check('Esc zdejmuje klasę Bricksa z korzenia',
    !(await bx.evaluate(() => window.__rootBrx())),
    await bx.evaluate(() => window.__rootKlasy()));
  // I to jest sedno zgłoszenia: przycisk MA z czego wrócić do burgera.
  t.check('a przycisk wraca do stanu zamkniętego',
    !(await bx.evaluate(() => window.__stanPrzycisku('trigger'))).brx,
    (await bx.evaluate(() => window.__stanPrzycisku('trigger'))).klasy);
  t.check('bez błędów JS', !bx.errors.length, bx.errors.join(' | ') || 'brak');
  await bx.close();

  // Zamknięcie klikiem POZA panelem — druga droga, ta sama reguła. Esc i klik
  // poza to osobne ścieżki w kodzie i osobno dało się je zepsuć.
  const bo = await t.open('circular-menu.html', { viewport: V, query: 'dur=0.2', settle: 300 });
  await bo.evaluate(() => window.__open());
  await bo.waitForTimeout(400);
  await bo.evaluate(() => document.querySelector('.long').click());
  await bo.waitForTimeout(400);
  t.check('klik poza panelem też zdejmuje klasę z korzenia',
    !(await bo.evaluate(() => window.__rootBrx())),
    await bo.evaluate(() => window.__rootKlasy()));
  await bo.close();

  // ── Czekanie na wyjście da się ustawić ─────────────────────────────────
  // Zgłoszone z użycia: „chciałbym, żeby animowało się zamykanie i linki
  // w tym samym czasie, a nie jedna po drugiej". Domyślnie kadr czeka na CAŁĄ
  // animację treści — dobre, gdy treść ma zniknąć przed zamknięciem, złe, gdy
  // oba ruchy mają być jednym gestem.
  //
  // Rozdzielone są tu DWIE rzeczy, które wcześniej były jedną: moment, w którym
  // treść rusza (zawsze od razu), i to, ile kadr na nią czeka.
  t.section('czekanie na wyjście — treść i kadr mogą iść RAZEM');

  const rw = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.4&exit=1&exitwait=0', settle: 300 });
  await rw.evaluate(() => window.__open());
  await rw.waitForTimeout(600);
  await rw.evaluate(() => window.__key('Escape'));
  await rw.waitForTimeout(200);

  const klip0  = (await rw.evaluate(() => window.__clip())).r;
  const tresc0 = await rw.evaluate(() => window.__op('anim'));
  t.check('przy zerze kadr JUŻ się zwija', klip0 < 140, 'promień ' + klip0 + ' ze 150');
  // Obie granice są potrzebne: powyżej zera znaczy „jeszcze nie skończyła",
  // poniżej jedynki — „już ruszyła". Sam kadr w ruchu przeszedłby też dla
  // wyjścia, którego w ogóle nie ma.
  t.check('a treść jest W TRAKCIE wychodzenia', tresc0 > 0.05 && tresc0 < 0.95,
    'opacity ' + tresc0);

  await rw.waitForTimeout(800);
  t.check('oba ruchy dochodzą do końca',
    !(await rw.evaluate(() => window.__rozwiniety()))
    && (await rw.evaluate(() => window.__op('anim'))) < 0.05,
    (await rw.evaluate(() => window.__clip())).raw + ', opacity '
      + (await rw.evaluate(() => window.__op('anim'))));
  t.check('bez błędów JS przy ruchach naraz', !rw.errors.length,
    rw.errors.join(' | ') || 'brak');
  await rw.close();

  // KONTROLA NEGATYWNA: bez ustawienia kadr NADAL czeka na całą animację.
  // Bez tej pary „kadr się zwija" nie odróżnia ustawienia od jego braku.
  const kw = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.4&exit=1', settle: 300 });
  await kw.evaluate(() => window.__open());
  await kw.waitForTimeout(600);
  await kw.evaluate(() => window.__key('Escape'));
  await kw.waitForTimeout(200);
  t.check('bez ustawienia kadr w tej samej chwili STOI',
    (await kw.evaluate(() => window.__clip())).r === 150,
    'promień ' + (await kw.evaluate(() => window.__clip())).r);
  await kw.close();

  // Jawna wartość DŁUŻSZA niż animacja — chwila ciszy przed zamknięciem.
  // Sprawdza drugi kierunek: że ustawienie wygrywa z czasem animacji, a nie
  // tylko go skraca.
  const dw = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.4&exit=1&exitwait=0.6', settle: 300 });
  await dw.evaluate(() => window.__open());
  await dw.waitForTimeout(600);
  await dw.evaluate(() => window.__key('Escape'));
  await dw.waitForTimeout(400);
  t.check('treść zdążyła wyjść (animacja trwa 0,3 s)',
    (await dw.evaluate(() => window.__op('anim'))) < 0.05,
    'opacity ' + (await dw.evaluate(() => window.__op('anim'))));
  t.check('a kadr wciąż czeka, bo poproszono o 0,6 s',
    await dw.evaluate(() => window.__rozwiniety()),
    (await dw.evaluate(() => window.__clip())).raw);

  await dw.waitForTimeout(800);
  t.check('po odczekaniu kadr się zwija', !(await dw.evaluate(() => window.__rozwiniety())),
    (await dw.evaluate(() => window.__clip())).raw);
  await dw.close();

  // Redukcja ruchu MUSI wygrać z jawnym czekaniem. Inaczej menu wisiałoby
  // otwarte przez 0,6 s, czekając na animację, której nie ma.
  const rwm = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.4&exit=1&exitwait=0.6', settle: 300, reduce: true });
  await rwm.evaluate(() => window.__open());
  await rwm.waitForTimeout(60);
  await rwm.evaluate(() => window.__key('Escape'));
  await rwm.waitForTimeout(40);
  t.check('przy redukcji ruchu jawne czekanie NIE obowiązuje',
    !(await rwm.evaluate(() => window.__rozwiniety())),
    (await rwm.evaluate(() => window.__clip())).raw);
  await rwm.close();

  // ── Zewnętrzny przełącznik ─────────────────────────────────────────────
  // Zgłoszone z użycia: „zewnętrzny przełącznik nie dodaje klasy brx-open".
  // Dwie przyczyny naraz, obie po cichu:
  //
  //  1. Klasy `brx-open` nie było w kodzie WCALE — a to na niej wisi cała
  //     animacja burgera zbudowanego w Bricksie.
  //  2. Selektor zewnętrznego przełącznika celuje zwykle WPROST w przycisk,
  //     a element szukał `button` w jego ŚRODKU i wychodził, gdy nic nie
  //     znalazł. Tą drogą nie działo się nic — ani klasy, ani aria-expanded.
  t.section('zewnętrzny przełącznik dostaje stan otwarcia');

  const zt = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.2&toggle=' + encodeURIComponent('.moj-burger'), settle: 300 });

  let s = await zt.evaluate(() => window.__stanPrzycisku('zew'));
  t.check('na start bez klasy otwarcia', !s.brx && s.aria === 'false',
    s.klasy + ' | aria ' + s.aria);

  await zt.evaluate(() => window.__zew());
  await zt.waitForTimeout(400);
  t.check('kliknięcie w zewnętrzny przycisk otwiera menu',
    await zt.evaluate(() => window.__rozwiniety()),
    (await zt.evaluate(() => window.__clip())).raw);

  s = await zt.evaluate(() => window.__stanPrzycisku('zew'));
  t.check('przełącznik dostał klasę Bricksa', s.brx, s.klasy);
  // Druga konwencja, ta od samych burgerów. Zgłoszone z użycia: „trzeba zdjąć
  // klasę is-active z przełącznika, wtedy się zamyka" — animacja kresek stała
  // właśnie na niej, a myśmy jej nie ruszali. Nakładamy OBIE, zamiast zgadywać,
  // która obowiązuje na danej stronie.
  t.check('i konwencję samych burgerów', s.act, s.klasy);
  t.check('i mówi, że rozwija', s.aria === 'true', String(s.aria));
  // Dotychczasowa konwencja Evoke zostaje — czyjeś arkusze mogą na niej stać.
  t.check('konwencja „--opened" też zostaje', /brxe-toggle--opened/.test(s.klasy), s.klasy);

  await zt.evaluate(() => window.__zew());
  await zt.waitForTimeout(400);
  s = await zt.evaluate(() => window.__stanPrzycisku('zew'));
  t.check('zamknięcie zdejmuje WSZYSTKIE klasy stanu',
    !s.brx && !s.act && !/--opened/.test(s.klasy) && s.aria === 'false',
    s.klasy + ' | aria ' + s.aria);
  t.check('bez błędów JS przy zewnętrznym przełączniku', !zt.errors.length,
    zt.errors.join(' | ') || 'brak');
  await zt.close();

  // Czwarta konwencja i dalsze — z kontrolki, bez ruszania kodu. Trzy rundy
  // zgłoszeń na tej samej rodzinie usterek wystarczą, żeby przestać zgadywać
  // i dać pole.
  const tc = await t.open('circular-menu.html', {
    viewport: V, settle: 300,
    query: 'dur=0.2&toggle=' + encodeURIComponent('.moj-burger') + '&tclass=' +
           encodeURIComponent('moja-klasa druga-klasa'),
  });
  await tc.evaluate(() => window.__zew());
  await tc.waitForTimeout(400);
  let k = await tc.evaluate(() => window.__stanPrzycisku('zew'));
  t.check('własne klasy z kontrolki też siadają',
    /moja-klasa/.test(k.klasy) && /druga-klasa/.test(k.klasy), k.klasy);
  t.check('a wbudowane nadal działają obok', k.brx && k.act, k.klasy);

  await tc.evaluate(() => window.__key('Escape'));
  await tc.waitForTimeout(400);
  k = await tc.evaluate(() => window.__stanPrzycisku('zew'));
  t.check('i wszystkie schodzą przy Esc',
    !/moja-klasa|druga-klasa/.test(k.klasy) && !k.brx && !k.act, k.klasy);
  await tc.close();

  // Druga postać: selektor na OPAKOWANIU przycisku. Stan należy do
  // sterującego, a nie do pudełka wokół niego — div z `aria-expanded` nie
  // jest dla czytnika ekranu żadnym przyciskiem.
  const zw = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.2&toggle=' + encodeURIComponent('.moje-opakowanie'), settle: 300 });
  await zw.evaluate(() => window.__zew('zew-btn'));
  await zw.waitForTimeout(400);

  const btn  = await zw.evaluate(() => window.__stanPrzycisku('zew-btn'));
  const wrap = await zw.evaluate(() => window.__stanPrzycisku('zew-wrap'));
  t.check('przy selektorze na opakowaniu stan dostaje PRZYCISK', btn.brx && btn.aria === 'true',
    btn.klasy + ' | aria ' + btn.aria);
  t.check('a pudełko zostaje nietknięte', !wrap.brx && wrap.aria === null,
    wrap.klasy + ' | aria ' + wrap.aria);
  await zw.close();

  // Trzecia postać, i to ta z żywej strony: burger BRICKSA nie jest
  // przyciskiem, tylko divem bez roli. Szukanie samego `button` kończyło się
  // niczym, więc klasa lądowała na opakowaniu — o jeden poziom za wysoko.
  // W drzewie `brx-open` było, a arkusz Bricksa i tak go nie widział, bo wisi
  // na `.brxe-toggle`. To dokładnie ten objaw: „w circular nadal nie ma
  // brx-open".
  const zb = await t.open('circular-menu.html',
    { viewport: V, query: 'dur=0.2&toggle=' + encodeURIComponent('.pudlo-bricks'), settle: 300 });
  await zb.evaluate(() => window.__zew('zew-bricks'));
  await zb.waitForTimeout(400);
  t.check('burger Bricksa (div) otwiera menu', await zb.evaluate(() => window.__rozwiniety()),
    (await zb.evaluate(() => window.__clip())).raw);

  const brxBtn  = await zb.evaluate(() => window.__stanPrzycisku('zew-bricks'));
  const brxWrap = await zb.evaluate(() => window.__stanPrzycisku('zew-bricks-wrap'));
  t.check('klasa siada na .brxe-toggle, a nie na opakowaniu',
    brxBtn.brx && !brxWrap.brx,
    '.brxe-toggle: ' + brxBtn.klasy + ' | opakowanie: ' + brxWrap.klasy);
  await zb.close();

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

  /* ── Przełącznik NAD panelem ────────────────────────────────────────────
   *
   * Zgłoszone z użycia: burger siedzi w nagłówku, nagłówek jest w <body> i nie
   * jest ani `fixed`, ani `absolute`, a panel go przykrywa. Podniesienie samego
   * burgera z-indeksem nie pomaga — pomaga dopiero wyciągnięcie na wierzch
   * CAŁEGO nagłówka, czyli razem z jego tłem.
   *
   * Nagłówek tworzy KONTEKST UKŁADANIA, a wtedy `z-index` dziecka rywalizuje
   * wyłącznie z rodzeństwem: z panelem rywalizuje cały nagłówek jako jedna
   * warstwa. Dlatego przełącznik w fixture ma z-index 99999 — żeby pomiar
   * pokazywał, że to NIE liczba decyduje.
   *
   * Mierzymy `elementFromPoint`, bo to jest dokładnie pytanie użytkownika:
   * co jest na wierzchu i w co trafi kliknięcie. Porównanie `z-index` niczego
   * by nie dowiodło.
   */
  t.section('przełącznik nad panelem — wyjęcie z kontekstu układania');

  const Q = 'dur=0.2&toggle=' + encodeURIComponent('.w-naglowku');

  // KONTROLA NEGATYWNA i zarazem dzisiejsze zachowanie: bez opcji panel
  // przykrywa przełącznik MIMO z-indeksu 99999.
  const bez = await t.open('circular-menu.html', { viewport: V, query: Q, settle: 300 });
  await bez.evaluate(() => window.__zew('zew-naglowek'));
  await bez.waitForTimeout(400);
  t.check('bez opcji panel przykrywa przełącznik mimo z-index 99999',
    (await bez.evaluate(() => window.__naWierzchu('zew-naglowek'))) !== 'zew-naglowek',
    String(await bez.evaluate(() => window.__naWierzchu('zew-naglowek'))));
  await bez.close();

  const zr = await t.open('circular-menu.html', {
    viewport: V, query: Q + '&raise=1', settle: 300,
  });
  const przedR = await zr.evaluate(() => window.__rect('zew-naglowek'));
  const sasiadR = await zr.evaluate(() => window.__rect('sasiad'));
  const stylPrzed = await zr.evaluate(() => window.__inline('zew-naglowek'));

  await zr.evaluate(() => window.__zew('zew-naglowek'));
  await zr.waitForTimeout(400);

  t.check('z opcją na wierzchu jest PRZEŁĄCZNIK',
    (await zr.evaluate(() => window.__naWierzchu('zew-naglowek'))) === 'zew-naglowek',
    String(await zr.evaluate(() => window.__naWierzchu('zew-naglowek'))));
  t.check('i nie drgnął na ekranie',
    JSON.stringify(await zr.evaluate(() => window.__rect('zew-naglowek'))) === JSON.stringify(przedR),
    JSON.stringify(await zr.evaluate(() => window.__rect('zew-naglowek'))) + ' vs ' + JSON.stringify(przedR));
  // Przekładka: bez niej nagłówek zapada się o szerokość przełącznika
  // i wszystko obok przeskakuje w chwili otwarcia.
  t.check('nagłówek się NIE przebudował',
    JSON.stringify(await zr.evaluate(() => window.__rect('sasiad'))) === JSON.stringify(sasiadR),
    JSON.stringify(await zr.evaluate(() => window.__rect('sasiad'))) + ' vs ' + JSON.stringify(sasiadR));
  t.check('na czas otwarcia siedzi w <body>',
    (await zr.evaluate(() => window.__rodzic('zew-naglowek'))) === 'body',
    String(await zr.evaluate(() => window.__rodzic('zew-naglowek'))));

  // Zamknięcie DROGĄ, o której podnoszenie nic nie wie — Esc.
  await zr.evaluate(() => window.__key('Escape'));
  await zr.waitForTimeout(500);
  t.check('po zamknięciu wraca do nagłówka',
    (await zr.evaluate(() => window.__rodzic('zew-naglowek'))) === 'naglowek',
    String(await zr.evaluate(() => window.__rodzic('zew-naglowek'))));
  t.check('i oddaje swój styl w stanie sprzed otwarcia',
    (await zr.evaluate(() => window.__inline('zew-naglowek'))) === stylPrzed,
    JSON.stringify(await zr.evaluate(() => window.__inline('zew-naglowek'))));
  t.check('a pozycja jest ta sama co na starcie',
    JSON.stringify(await zr.evaluate(() => window.__rect('zew-naglowek'))) === JSON.stringify(przedR),
    JSON.stringify(await zr.evaluate(() => window.__rect('zew-naglowek'))));
  t.check('bez błędów JS przy podnoszeniu', !zr.errors.length,
    zr.errors.join(' | ') || 'brak');
  await zr.close();

  /* PRZEJŚCIE MUSI ZAGRAĆ PRZY OTWIERANIU — zgłoszone z użycia po 1.85.0:
     „burger nie animuje się do stanu otwartego, tylko przy zamknięciu".
     Przeniesienie węzła w drzewie KASUJE stan przejść: element, którego nie
     było w dokumencie przy poprzednim przeliczeniu stylu, nie ma od czego
     animować, więc klasa nałożona zaraz potem dawała przeskok. Przy zamykaniu
     wszystko grało, bo tam klasa schodzi, gdy węzeł od dawna siedzi w <body>.
     Mierzymy W POŁOWIE drogi, bo tylko tam widać różnicę między przejściem
     a przeskokiem — stan końcowy jest w obu przypadkach ten sam. */
  const anim = await t.open('circular-menu.html', {
    viewport: V, settle: 300,
    query: 'dur=0.2&raise=1&toggle=' + encodeURIComponent('.w-naglowku'),
  });
  await anim.evaluate(() => window.__zew('zew-naglowek'));
  await anim.waitForTimeout(150);
  const wPol = await anim.evaluate(() => window.__tlo('zew-naglowek'));
  t.check('przy OTWIERANIU kolor jest w pół drogi, a nie przeskoczony',
    wPol !== 'rgb(0, 0, 255)' && wPol !== 'rgb(255, 0, 0)', wPol);
  await anim.waitForTimeout(500);
  t.check('a na końcu dochodzi do docelowego',
    (await anim.evaluate(() => window.__tlo('zew-naglowek'))) === 'rgb(0, 0, 255)',
    String(await anim.evaluate(() => window.__tlo('zew-naglowek'))));
  await anim.close();

  /* Przełącznik BEZ własnego z-indeksu — i to on dowodzi, że liczbę nadajemy
     my. Ten z nagłówka ma wpisane 99999, żeby pokazać, że wewnątrz kontekstu
     układania liczba nic nie daje; ale po przeniesieniu do <body> ta sama
     liczba zaczyna działać i MASKUJE nasze przypisanie. Zwykły burger żadnego
     z-indeksu nie ma, więc bez nadanego przez nas przegrałby z panelem
     (9999) mimo przeniesienia. */
  const bezZ = await t.open('circular-menu.html', {
    viewport: V, settle: 300,
    query: 'dur=0.2&raise=1&toggle=' + encodeURIComponent('.moj-burger'),
  });
  await bezZ.evaluate(() => window.__zew('zew'));
  await bezZ.waitForTimeout(400);
  t.check('przełącznik bez własnego z-indeksu też jest na wierzchu',
    (await bezZ.evaluate(() => window.__naWierzchu('zew'))) === 'zew',
    String(await bezZ.evaluate(() => window.__naWierzchu('zew'))));
  await bezZ.close();

  /* REDUKCJA RUCHU to osobne sprawdzenie, a nie powtórka — bo to jedyna
     ścieżka, na której czas trwania naprawdę wynosi ZERO (silnik zeruje go
     wtedy sam, niezależnie od ustawienia).
     Naturalnym miejscem na przywracanie byłby `onReverseComplete` osi czasu,
     ale przy zerowym czasie to zwrotne wywołanie się NIE ODPALA — zmierzone
     na GSAP-ie z tej paczki: 0,2 s odpala, 0 s nie. Przełącznik zostałby więc
     wyrwany z nagłówka NA STAŁE u każdego, kto ma ograniczony ruch w systemie.
     Dlatego przywracanie jedzie zegarem o znanym czasie. */
  const zero = await t.open('circular-menu.html', {
    viewport: V, reduce: true, settle: 300,
    query: 'dur=0.4&toggle=' + encodeURIComponent('.w-naglowku') + '&raise=1',
  });
  await zero.evaluate(() => window.__zew('zew-naglowek'));
  await zero.waitForTimeout(120);
  t.check('przy redukcji ruchu też się podnosi',
    (await zero.evaluate(() => window.__rodzic('zew-naglowek'))) === 'body',
    String(await zero.evaluate(() => window.__rodzic('zew-naglowek'))));
  await zero.evaluate(() => window.__key('Escape'));
  await zero.waitForTimeout(200);
  t.check('i przy redukcji ruchu NAPRAWDĘ wraca',
    (await zero.evaluate(() => window.__rodzic('zew-naglowek'))) === 'naglowek',
    String(await zero.evaluate(() => window.__rodzic('zew-naglowek'))));
  await zero.close();

  // Przełącznik WBUDOWANY siedzi w korzeniu menu, a nie w panelu — ma się
  // podnosić normalnie. Gdyby pomijanie „tego, co w panelu" było napisane zbyt
  // szeroko, wypadłby razem z nim.
  const wb = await t.open('circular-menu.html', {
    viewport: V, query: 'dur=0.2&raise=1', settle: 300,
  });
  await wb.evaluate(() => window.__open());
  await wb.waitForTimeout(400);
  // Podnoszony jest CAŁY `.evk-cm-trigger` (opakowanie), a nie przycisk
  // w środku — bo to opakowanie zajmuje miejsce w układzie strony.
  t.check('wbudowany przełącznik też jedzie do <body>',
    (await wb.evaluate(() => window.__rodzic('tw'))) === 'body',
    String(await wb.evaluate(() => window.__rodzic('tw'))));
  t.check('a panel został tam, gdzie był',
    (await wb.evaluate(() => window.__panelParent())) === 'body',
    String(await wb.evaluate(() => window.__panelParent())));
  await wb.close();
};
