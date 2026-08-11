/**
 * Offcanvas Menu — nowy element Bricks.
 *
 * Rodzic wyjeżdża całkiem, podmenu zajmuje jego miejsce (decyzja użytkownika,
 * patrz docs/offcanvas-menu-szkic.md). Wygląda to prościej niż warstwy i jest
 * prostsze — ale JEDNA rzecz zrobiła się przez to groźniejsza, nie łatwiejsza:
 *
 * **Panel wysunięty poza ekran nadal łapie fokus.** `transform: translateX(-100%)`
 * nie usuwa niczego z kolejności tabulacji. Przy warstwach widocznych pod spodem
 * było oczywiste, że tam coś jest; przy wyjeżdżaniu w bok wygląda to na
 * rozwiązane i nie jest. Stąd `inert` i sprawdzenie, którego nie da się zrobić
 * na oko ani na zrzucie ekranu.
 */

const { phpOutput } = require('./lib/harness');

const V = { width: 1200, height: 800 };

module.exports = async function (t) {
  // ── Otwieranie ─────────────────────────────────────────────────────────
  t.section('otwieranie i zamykanie');

  const p = await t.open('offcanvas.html', { viewport: V, settle: 120 });

  t.check('na start zamknięte', !(await p.evaluate(() => window.__isOpen())), 'zamknięte');
  t.check('trigger mówi, że zamknięte',
    (await p.evaluate(() => window.__ariaExpanded())) === 'false',
    String(await p.evaluate(() => window.__ariaExpanded())));
  t.check('powłoka przeniesiona do <body>',
    (await p.evaluate(() => window.__shellParent())) === 'body',
    String(await p.evaluate(() => window.__shellParent())));

  await p.evaluate(() => window.__open());
  await p.waitForTimeout(80);
  t.check('po kliknięciu otwarte', await p.evaluate(() => window.__isOpen()), 'otwarte');
  t.check('trigger mówi, że otwarte',
    (await p.evaluate(() => window.__ariaExpanded())) === 'true',
    String(await p.evaluate(() => window.__ariaExpanded())));
  t.check('fokus wszedł do panelu',
    (await p.evaluate(() => window.__focusId())) === 's-a',
    String(await p.evaluate(() => window.__focusId())));

  // ── inert ──────────────────────────────────────────────────────────────
  t.section('panele niebieżące są odcięte od tabulatora');

  let st = await p.evaluate(() => window.__state());
  t.check('bieżący jest dokładnie jeden', st.filter((x) => x.current).length === 1,
    st.filter((x) => x.current).map((x) => x.id).join(', '));
  t.check('pozostałe mają inert', st.filter((x) => !x.current).every((x) => x.inert),
    st.map((x) => x.id + (x.inert ? ':inert' : ':dostępny')).join(' | '));

  await p.evaluate(() => window.__click('go-uslugi'));
  await p.waitForTimeout(80);
  st = await p.evaluate(() => window.__state());
  t.check('po wejściu w podmenu bieżący się zmienił',
    st.find((x) => x.current).id === 'uslugi', st.find((x) => x.current).id);
  t.check('panel, z którego weszliśmy, dostał inert',
    st.find((x) => x.id === 'start').inert, 'start: '
      + (st.find((x) => x.id === 'start').inert ? 'inert' : 'DOSTĘPNY tabulatorem'));
  t.check('fokus przeszedł do nowego panelu',
    (await p.evaluate(() => window.__focusId())) === 'back-1',
    String(await p.evaluate(() => window.__focusId())));

  // ── Powrót ─────────────────────────────────────────────────────────────
  t.section('powrót wraca na pozycję, z której się weszło');

  await p.evaluate(() => window.__click('back-1'));
  await p.waitForTimeout(80);
  t.check('bieżący znów startowy',
    (await p.evaluate(() => window.__state())).find((x) => x.current).id === 'start',
    (await p.evaluate(() => window.__state())).find((x) => x.current).id);
  t.check('fokus na pozycji „Usługi", nie na początku listy',
    (await p.evaluate(() => window.__focusId())) === 'go-uslugi',
    String(await p.evaluate(() => window.__focusId())));

  // ── Esc ────────────────────────────────────────────────────────────────
  t.section('Esc cofa o jeden, na startowym zamyka');

  await p.evaluate(() => { window.__click('go-uslugi'); });
  await p.waitForTimeout(60);
  await p.evaluate(() => { window.__click('go-detale'); });
  await p.waitForTimeout(60);
  t.check('jesteśmy dwa poziomy w głąb',
    (await p.evaluate(() => window.__state())).find((x) => x.current).id === 'detale',
    (await p.evaluate(() => window.__state())).find((x) => x.current).id);

  await p.evaluate(() => window.__key('Escape'));
  await p.waitForTimeout(60);
  t.check('Esc cofnął o jeden, nie zamknął',
    (await p.evaluate(() => window.__isOpen()))
    && (await p.evaluate(() => window.__state())).find((x) => x.current).id === 'uslugi',
    (await p.evaluate(() => window.__isOpen())) ? 'otwarte na '
      + (await p.evaluate(() => window.__state())).find((x) => x.current).id : 'ZAMKNIĘTE');

  await p.evaluate(() => window.__key('Escape'));
  await p.waitForTimeout(60);
  await p.evaluate(() => window.__key('Escape'));
  await p.waitForTimeout(60);
  t.check('na panelu startowym Esc zamyka', !(await p.evaluate(() => window.__isOpen())),
    'zamknięte');
  t.check('po zamknięciu fokus wrócił na trigger',
    (await p.evaluate(() => window.__focusId())) === 'trigger',
    String(await p.evaluate(() => window.__focusId())));

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  // ── Wysuwanie ──────────────────────────────────────────────────────────
  // Panel ma WJECHAĆ, a nie pojawić się. Przejście CSS nie rusza z elementu,
  // który przed chwilą miał `display: none` — przeglądarka nie ma wtedy stanu
  // wyjściowego do interpolacji i po prostu skacze do końcowego.
  t.section('panel wjeżdża, a nie pojawia się');

  const a = await t.open('offcanvas.html', { viewport: V, query: 'dur=0.6', settle: 120 });

  const closed = await a.evaluate(() => window.__slide());
  t.check('zamknięty kadr stoi poza ekranem', closed.x !== 0,
    'x ' + closed.x + ', widoczność ' + closed.visible);
  t.check('kadr ma zadeklarowane przejście', parseFloat(closed.transition) > 0,
    'transition-duration ' + closed.transition);

  await a.evaluate(() => window.__open());
  await a.waitForTimeout(120);
  const mid = await a.evaluate(() => window.__slide());
  // W połowie 0,6 s kadr ma być W DRODZE: już nie na starcie, jeszcze nie u celu.
  t.check('120 ms po otwarciu kadr jest W RUCHU',
    mid.x !== 0 && Math.abs(mid.x) < Math.abs(closed.x),
    'x ' + mid.x + ' (start ' + closed.x + ', cel 0)');

  await a.waitForTimeout(700);
  const done = await a.evaluate(() => window.__slide());
  t.check('po przejściu kadr dojeżdża na miejsce', done.x === 0, 'x ' + done.x);
  await a.close();

  // ── Panel WYPYCHA, a nie zasłania ──────────────────────────────────────
  // Zgłoszone z użycia: „drugi panel zasłania pierwszy, a powinien go
  // przesuwać". Panele leżą teraz OBOK SIEBIE na taśmie, więc wejście
  // w podmenu odsuwa rodzica w lewo. Różnicę widać po lewej krawędzi panelu
  // startowego: przy wypychaniu maleje, przy zasłanianiu stoi w miejscu.
  t.section('drugi panel wypycha pierwszy');

  const psh = await t.open('offcanvas.html', { viewport: V, settle: 120 });
  await psh.evaluate(() => window.__open());
  await psh.waitForTimeout(80);
  const leftBefore = await psh.evaluate(() => window.__panelLeft('start'));
  t.check('panel startowy stoi w kadrze', leftBefore === 0, leftBefore + ' px');

  await psh.evaluate(() => window.__click('go-uslugi'));
  // Czekamy na KONIEC przejścia: przy domyślnych czasach taśma jedzie 0,35 s,
  // a w połowie drogi „zajęło jego miejsce" jest jeszcze nieprawdą.
  await psh.waitForTimeout(600);
  const leftAfter = await psh.evaluate(() => window.__panelLeft('start'));
  const upLeft    = await psh.evaluate(() => window.__panelLeft('uslugi'));
  t.check('panel startowy ODJECHAŁ w lewo', leftAfter < -100,
    leftBefore + ' → ' + leftAfter + ' px');
  t.check('podmenu zajęło jego miejsce', upLeft === 0, upLeft + ' px');
  await psh.close();

  // ── Taśma ma WŁASNY czas ───────────────────────────────────────────────
  // Zgłoszone z użycia: „inne tempo otwierania panelu, a inne przesuwania".
  // Wspólny czas daje ruch liniowy — menu wjeżdża i panele przesuwają się
  // identycznie, więc całość wygląda płasko.
  t.section('przejście między panelami ma własne tempo');

  const d = await t.open('offcanvas.html', { viewport: V, query: 'dur=0.3&pdur=0.9', settle: 120 });
  const frameT = (await d.evaluate(() => window.__slide())).transition;
  const trackT = (await d.evaluate(() => window.__track())).transition;
  t.check('kadr i taśma mają RÓŻNE czasy', parseFloat(frameT) !== parseFloat(trackT),
    'kadr ' + frameT + ', taśma ' + trackT);
  t.check('każdy ma czas, o który poproszono',
    Math.abs(parseFloat(frameT) - 0.3) < 0.01 && Math.abs(parseFloat(trackT) - 0.9) < 0.01,
    'kadr ' + frameT + ' (0,3), taśma ' + trackT + ' (0,9)');

  // Taśma ma się RUSZAĆ, nie przeskakiwać.
  await d.evaluate(() => window.__open());
  await d.waitForTimeout(400);
  const t0 = await d.evaluate(() => window.__track());
  await d.evaluate(() => window.__click('go-uslugi'));
  await d.waitForTimeout(200);
  const t1 = await d.evaluate(() => window.__track());
  t.check('200 ms po przejściu taśma jest W RUCHU',
    t1.x < t0.x && t1.x > -420, 'z ' + t0.x + ' do ' + t1.x + ' (cel ok. -420)');
  await d.close();

  // ── Wybrana krzywa NIE MOŻE zgasić przejścia ───────────────────────────
  // Zgłoszone z użycia: „animacje są, ale przy wybraniu własnej krzywej
  // przestają działać" i „nadal nie przesuwa się pierwszy panel, drugi go
  // zasłania" — jedna przyczyna, dwa objawy.
  //
  // Lista krzywych jest wspólna z Animatorem, więc jej wartości są w zapisie
  // GSAP: `power2.out`, `back.out(1.7)`. CSS takiej funkcji czasu nie zna,
  // a nieznana wartość unieważnia CAŁĄ deklarację `transition` — nie tylko
  // krzywą, ale i czas. Kadr przestawał wyjeżdżać, a taśma przeskakiwała,
  // więc podmenu POJAWIAŁO SIĘ na wierzchu zamiast wypchnąć rodzica. Domyślne
  // „— domyślny —" działało, bo nie ustawia zmiennej wcale; usterki nie dało
  // się więc zobaczyć bez sięgnięcia po listę.
  t.section('krzywa z listy nie gasi przejścia');

  // Wartości bierzemy z PRAWDZIWEGO przeliczenia w PHP (evk_anim_easing_css),
  // a nie z kopii w teście — kopia rozjechałaby się przy pierwszej poprawionej
  // krzywej i test przestałby cokolwiek znaczyć.
  const easeCss = JSON.parse(phpOutput('presets.php')).easingsCss;
  const e = await t.open('offcanvas.html', {
    viewport: V, settle: 120,
    query: 'dur=0.6&pdur=0.9' +
           '&ease='  + encodeURIComponent(easeCss['power2.out']) +
           '&pease=' + encodeURIComponent(easeCss['back.out(1.7)']),
  });

  const eFrame = await e.evaluate(() => window.__slide());
  const eTrack = await e.evaluate(() => window.__track());

  t.check('kadr zachowuje czas mimo wybranej krzywej',
    Math.abs(parseFloat(eFrame.transition) - 0.6) < 0.01, eFrame.transition);
  t.check('taśma zachowuje czas mimo wybranej krzywej',
    Math.abs(parseFloat(eTrack.transition) - 0.9) < 0.01, eTrack.transition);

  // Sama obecność czasu nie wystarczy: wartość krzywej ma naprawdę wejść,
  // a nie zostać po cichu zamieniona na domyślne `ease`.
  t.check('krzywa kadru trafia do CSS jako funkcja czasu',
    /cubic-bezier|steps|linear/.test(eFrame.ease) && eFrame.ease !== 'ease', eFrame.ease);
  t.check('taśma dostaje SWOJĄ krzywą, inną niż kadr',
    eTrack.ease !== eFrame.ease, 'kadr ' + eFrame.ease + ', taśma ' + eTrack.ease);

  // I dowód na ruch — bo o to w zgłoszeniu chodziło.
  await e.evaluate(() => window.__open());
  await e.waitForTimeout(150);
  const eMid = await e.evaluate(() => window.__slide());
  t.check('150 ms po otwarciu kadr JEDZIE, a nie stoi',
    eMid.x !== 0 && Math.abs(eMid.x) < 420, 'x ' + eMid.x + ' (start ok. 420, cel 0)');

  await e.waitForTimeout(700);
  await e.evaluate(() => window.__click('go-uslugi'));
  await e.waitForTimeout(250);
  const eT1 = await e.evaluate(() => window.__track());
  t.check('250 ms po przejściu taśma JEDZIE, a nie przeskakuje',
    eT1.x < 0 && eT1.x > -420, 'x ' + eT1.x + ' (cel ok. -420)');

  await e.waitForTimeout(900);
  const ePush = await e.evaluate(() => window.__panelLeft('start'));
  t.check('panel startowy i tak zostaje WYPCHNIĘTY', ePush < -100, ePush + ' px');
  t.check('bez błędów JS przy wybranej krzywej', !e.errors.length,
    e.errors.join(' | ') || 'brak');
  await e.close();

  // Druga warstwa obrony. Przeliczenie robi PHP, ale ostatnie słowo ma
  // przeglądarka: `linear()` (odbicie, sprężyna) nie działa na starszych,
  // a strona z pamięci podręcznej może nieść jeszcze surową nazwę GSAP-a.
  // Wartość, której przeglądarka nie przyjmie, ma zostać ODRZUCONA — nie
  // wstawiona i nie zgaszona razem z całym przejściem.
  const raw = await t.open('offcanvas.html', {
    viewport: V, settle: 120, query: 'dur=0.6&ease=power2.out',
  });
  const rawFrame = await raw.evaluate(() => window.__slide());
  t.check('nazwa GSAP-a nie gasi przejścia',
    Math.abs(parseFloat(rawFrame.transition) - 0.6) < 0.01, rawFrame.transition);
  t.check('odrzucona krzywa wraca do domyślnej z arkusza', rawFrame.ease === 'ease',
    rawFrame.ease);
  await raw.close();

  // ── Taśma nie siedzi na stałe na własnej warstwie ──────────────────────
  // Zgłoszone z użycia: „w mobilnym zmienia się kolor pierwszego [panelu]".
  // Stałe `will-change: transform` trzyma taśmę na warstwie kompozytora przez
  // całe życie strony, choć rusza się ona co kilka kliknięć — a warstwa bywa
  // rasteryzowana osobno i ten sam kolor potrafi wyjść odrobinę inaczej,
  // najbardziej na telefonach z szerokim gamutem. Samego przesunięcia barwy
  // NIE DA SIĘ tu zmierzyć (headless rasteryzuje wszystko jednakowo), więc
  // test pilnuje reguły, a nie objawu — i mówi o tym wprost.
  t.section('taśma nie trzyma stałej warstwy kompozytora');

  const wc = await t.open('offcanvas.html', { viewport: V, query: 'dur=0.4', settle: 120 });
  const wcTrack = await wc.evaluate(() => window.__track());
  t.check('taśma nie deklaruje stałego will-change', wcTrack.willChange === 'auto',
    wcTrack.willChange);

  // Rezygnacja z podpowiedzi nie może kosztować ruchu — przeglądarka promuje
  // element na czas trwania przejścia sama.
  await wc.evaluate(() => window.__open());
  await wc.waitForTimeout(500);
  await wc.evaluate(() => window.__click('go-uslugi'));
  await wc.waitForTimeout(150);
  const wcMid = await wc.evaluate(() => window.__track());
  t.check('taśma nadal płynnie jedzie', wcMid.x < 0 && wcMid.x > -420,
    'x ' + wcMid.x + ' (cel ok. -420)');
  await wc.close();

  // ── Animacje grają przy KAŻDYM otwarciu ────────────────────────────────
  // Wyzwalacz „wejście w kadr" jest z definicji jednorazowy: strona przewija
  // się w jedną stronę, więc ScrollTrigger po pierwszym wejściu kończy pracę.
  // W panelu, który się otwiera i zamyka, to założenie nie obowiązuje —
  // animacja grała raz na całe życie strony, a przy drugim otwarciu treść
  // po prostu była. Zgłoszone z użycia.
  t.section('animacje w menu grają za każdym otwarciem');

  const an = await t.open('offcanvas.html', { viewport: V, settle: 250 });

  await an.evaluate(() => window.__open());
  await an.waitForTimeout(30);
  const firstStart = await an.evaluate(() => window.__animOpacity());
  t.check('pierwsze otwarcie zaczyna od stanu początkowego', firstStart < 0.6,
    'opacity ' + firstStart);

  await an.waitForTimeout(500);
  t.check('pierwsze otwarcie dochodzi do końca',
    (await an.evaluate(() => window.__animOpacity())) > 0.95,
    'opacity ' + (await an.evaluate(() => window.__animOpacity())));

  await an.evaluate(() => window.__key('Escape'));
  await an.waitForTimeout(120);

  await an.evaluate(() => window.__open());
  await an.waitForTimeout(30);
  const secondStart = await an.evaluate(() => window.__animOpacity());
  t.check('DRUGIE otwarcie też zaczyna od stanu początkowego', secondStart < 0.6,
    'opacity ' + secondStart + ' (przy pierwszym ' + firstStart + ')');

  await an.waitForTimeout(500);
  t.check('drugie otwarcie też dochodzi do końca',
    (await an.evaluate(() => window.__animOpacity())) > 0.95,
    'opacity ' + (await an.evaluate(() => window.__animOpacity())));

  t.check('bez błędów JS przy powtórce', !an.errors.length,
    an.errors.join(' | ') || 'brak');
  await an.close();

  // ── Panele bez nazw, adresowane kolejnością ────────────────────────────
  // To jest ścieżka, którą dostaje KAŻDY, kto wstawi element i niczego nie
  // skonfiguruje: panele nie mają `data-panel`, więc liczy się ich kolejność
  // i `data-evk-oc-go="1"` otwiera drugi. Opisałem to w kontrolce, ale do
  // 1.57.1 nie było sprawdzone — a to jedyna droga, którą ktoś pójdzie
  // przed przeczytaniem czegokolwiek.
  t.section('drugi panel bez konfiguracji');

  const ix = await t.open('offcanvas.html', { viewport: V, settle: 120 });
  await ix.evaluate(() => window.__open2());
  await ix.waitForTimeout(80);
  const before = await ix.evaluate(() => window.__state2());
  t.check('otwiera się pierwszy panel', before.length === 2 && before[0].current,
    before.map((x) => x.i + (x.current ? '*' : '')).join(' '));

  await ix.evaluate(() => window.__click('go-idx'));
  await ix.waitForTimeout(80);
  const afterGo = await ix.evaluate(() => window.__state2());
  t.check('„data-evk-oc-go=1" otwiera DRUGI panel',
    afterGo.length === 2 && afterGo[1].current,
    afterGo.map((x) => x.i + (x.current ? '*' : '')).join(' '));

  await ix.evaluate(() => window.__click('back-idx'));
  await ix.waitForTimeout(80);
  t.check('„wstecz" wraca do pierwszego',
    (await ix.evaluate(() => window.__state2()))[0].current, 'pierwszy');
  t.check('bez błędów JS przy panelach bez nazw', !ix.errors.length,
    ix.errors.join(' | ') || 'brak');
  await ix.close();

  // ── Blokada przewijania ────────────────────────────────────────────────
  // Zamierzałem tu zmierzyć, że blokada nie przesuwa układu o szerokość paska
  // przewijania. NIE DA SIĘ: headless Chromium rysuje pasek nakładkowy, więc
  // `innerWidth - clientWidth` wychodzi 0 nawet na stronie wysokiej na 3000 px,
  // i sprawdzenie przechodziło TAKŻE po usunięciu kompensaty z kodu. Zamiast
  // zielonego pomiaru bez treści zostaje to, co realnie mierzalne — a przy
  // okazji częstsza usterka: blokada, która się nie zdejmuje i zostawia stronę
  // bez możliwości przewijania.
  t.section('blokada przewijania zakłada się i ZDEJMUJE');

  const l = await t.open('offcanvas.html', { viewport: V, settle: 120 });
  t.check('przed otwarciem brak blokady', !(await l.evaluate(() => window.__lock())).locked,
    'bez blokady');

  await l.evaluate(() => window.__open());
  await l.waitForTimeout(80);
  const opened = await l.evaluate(() => window.__lock());
  t.check('po otwarciu blokada założona', opened.locked,
    opened.locked ? 'zablokowane' : 'BRAK blokady');

  await l.evaluate(() => window.__key('Escape'));
  await l.waitForTimeout(80);
  const after = await l.evaluate(() => window.__lock());
  t.check('po zamknięciu blokada zdjęta', !after.locked,
    after.locked ? 'NADAL zablokowane — strona zostaje bez przewijania' : 'odblokowane');
  t.check('po zamknięciu nie zostaje wcięcie', after.padding === '',
    after.padding || '(puste)');

  // Wyłączona opcja ma naprawdę wyłączać — inaczej „blokuj przewijanie"
  // jest przełącznikiem, który nic nie przełącza.
  await l.close();
  const nl = await t.open('offcanvas.html', { viewport: V, query: 'lock=0', settle: 120 });
  await nl.evaluate(() => window.__open());
  await nl.waitForTimeout(80);
  t.check('przy wyłączonej opcji blokady nie ma',
    !(await nl.evaluate(() => window.__lock())).locked, 'bez blokady');
  await nl.close();

  // ── Tryb swobodny ──────────────────────────────────────────────────────
  t.section('tryb swobodny nie buduje stosu');

  const s1 = await t.open('offcanvas.html', { viewport: V, query: 'mode=single', settle: 120 });
  await s1.evaluate(() => window.__open());
  await s1.waitForTimeout(60);
  await s1.evaluate(() => window.__click('go-uslugi'));
  await s1.waitForTimeout(60);
  await s1.evaluate(() => window.__key('Escape'));
  await s1.waitForTimeout(60);
  t.check('Esc zamyka od razu, nie cofa', !(await s1.evaluate(() => window.__isOpen())),
    'zamknięte');
  await s1.close();

  // ── Redukcja ruchu ─────────────────────────────────────────────────────
  // Menu MUSI się nadal otwierać: znika ruch, nie dostęp do nawigacji.
  t.section('redukcja ruchu — menu nadal działa');

  const r = await t.open('offcanvas.html', { viewport: V, reduce: true, settle: 120 });
  await r.evaluate(() => window.__open());
  await r.waitForTimeout(80);
  t.check('panel jest widoczny mimo redukcji ruchu',
    await r.evaluate(() => window.__panelVisible()), 'widoczny');
  t.check('przejście CSS wyłączone',
    (await r.evaluate(() => getComputedStyle(window.__firstPanel()).transitionDuration))
      .split(',')[0].trim() === '0s',
    await r.evaluate(() => getComputedStyle(window.__firstPanel()).transitionDuration));
  await r.close();

  // KONTROLA NEGATYWNA. „Panel widoczny" jest prawdą także wtedy, gdy skrypt
  // w ogóle nie wystartował — bez tej pary nie da się tego odróżnić.
  const n = await t.open('offcanvas.html', { viewport: V, settle: 120 });
  t.check('bez redukcji ruchu panel NIE jest widoczny przed otwarciem',
    !(await n.evaluate(() => window.__panelVisible())), 'schowany');
  await n.close();
};
