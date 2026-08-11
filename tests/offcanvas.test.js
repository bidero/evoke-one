/**
 * Offcanvas Menu — element Bricks.
 *
 * Wejście w podmenu ma DWA tryby i to one dzielą ten plik:
 *
 *   'expand' (domyślny od 1.62.0) — kadr się poszerza, rodzic przesuwa się
 *       w lewo i ZOSTAJE WIDOCZNY obok podmenu. Tak działa wzór.
 *   'slide' — rodzic wyjeżdża całkiem poza kadr, podmenu zajmuje jego miejsce.
 *
 * Sekcje mierzące wyjeżdżanie rodzica podają `?levels=slide` JAWNIE. Bez tego
 * jechałyby domyślnym poszerzaniem i mierzyłyby coś innego, niż mówią —
 * a część z nich (inert na rodzicu, przesunięcie taśmy) jest w poszerzaniu
 * wprost NIEPRAWDZIWA, bo rodzic ma tam zostać dostępny.
 *
 * W obu trybach jedna rzecz jest groźniejsza, niż wygląda:
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

  const p = await t.open('offcanvas.html', { viewport: V, query: 'levels=slide', settle: 120 });

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

  const psh = await t.open('offcanvas.html', { viewport: V, query: 'levels=slide', settle: 120 });
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

  const d = await t.open('offcanvas.html', { viewport: V, query: 'levels=slide&dur=0.3&pdur=0.9', settle: 120 });
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
    query: 'levels=slide&dur=0.6&pdur=0.9' +
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
    viewport: V, settle: 120, query: 'levels=slide&dur=0.6&ease=power2.out',
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

  const wc = await t.open('offcanvas.html', { viewport: V, query: 'levels=slide&dur=0.4', settle: 120 });
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

  // ── Nic nie miga przed startem skryptu ─────────────────────────────────
  // Zgłoszone z użycia: „przy ładowaniu strony miga kawałek offcanvas gdzieś
  // na górze". Korzeń ma `display: contents`, więc do chwili inicjalizacji
  // panele są zwyczajnymi blokami W TREŚCIE strony — widać je w miejscu
  // wstawienia elementu i rozpychają układ. Schować je musi ARKUSZ, bo JS
  // jest właśnie tym, na co strona czeka.
  t.section('nic nie miga przed startem skryptu');

  const fo = await t.open('offcanvas-fouc.html', { viewport: V, settle: 120 });
  const f0 = await fo.evaluate(() => window.__fouc());

  t.check('żaden panel się nie rysuje',
    f0.rects.every((r) => r.w === 0 && r.h === 0),
    f0.rects.map((r) => r.w + '×' + r.h + ' ' + r.display).join(' | '));
  // Drugi pomiar, bo pierwszy da się oszukać samym `visibility: hidden`:
  // panel niewidoczny, ale nadal rozpychający stronę, to wciąż skok układu.
  t.check('panele nie rozpychają układu', f0.przerwa <= 40, f0.przerwa + ' px odstępu');
  // Trigger to burger — ma zostać. Schowanie go razem z panelami zabrałoby
  // jedyny sposób otwarcia menu.
  t.check('trigger zostaje widoczny', f0.trigger > 0, f0.trigger + ' px wysokości');
  t.check('bez błędów JS', !fo.errors.length, fo.errors.join(' | ') || 'brak');
  await fo.close();

  // ── Kadr się POSZERZA, rodzic zostaje widoczny ─────────────────────────
  // Domyślne wejście w podmenu od 1.62.0 i jedyne, które odpowiada wzorowi
  // (nextbricks): menu rośnie o szerokość jednego panelu na poziom, a rodzic
  // przesuwa się w lewo i ZOSTAJE NA EKRANIE. Poprzedni tryb wypychał go
  // całkiem poza kadr — zgłoszone jako „nadal nie przepycha panelu dalej",
  // bo rodzica po prostu nie było widać.
  t.section('kadr się poszerza, rodzic zostaje widoczny');

  const ex = await t.open('offcanvas.html', { viewport: V, query: 'dur=0.3&pdur=0.5', settle: 120 });
  await ex.evaluate(() => window.__open());
  await ex.waitForTimeout(500);

  const x0 = await ex.evaluate(() => window.__expand());
  const one = x0.frameW;
  t.check('na starcie kadr ma szerokość jednego panelu',
    one > 0 && x0.panels.filter((q) => q.shown).length === 1, one + ' px, widocznych '
      + x0.panels.filter((q) => q.shown).length);

  await ex.evaluate(() => window.__click('go-uslugi'));
  await ex.waitForTimeout(800);
  const x1 = await ex.evaluate(() => window.__expand());
  const shown = x1.panels.filter((q) => q.shown);

  t.check('kadr urósł o dokładnie jeden panel', x1.frameW === one * 2,
    one + ' → ' + x1.frameW + ' px');
  t.check('widać DWA panele naraz', shown.length === 2,
    shown.map((q) => q.id).join(' + '));

  const start  = x1.panels.find((q) => q.id === 'start');
  const uslugi = x1.panels.find((q) => q.id === 'uslugi');
  t.check('rodzic PRZESUNĄŁ SIĘ w lewo', start.left === x0.panels[0].left - one,
    x0.panels[0].left + ' → ' + start.left + ' px');
  // Sedno zgłoszenia: rodzic ma zostać NA EKRANIE, nie wyjechać poza kadr.
  t.check('rodzic nadal jest widoczny na ekranie',
    start.left >= 0 && start.left + start.w <= V.width,
    'zajmuje ' + start.left + '–' + (start.left + start.w) + ' px przy oknie ' + V.width);
  t.check('podmenu stanęło tam, gdzie był rodzic', uslugi.left === x0.panels[0].left,
    uslugi.left + ' px (rodzic był na ' + x0.panels[0].left + ')');
  t.check('kadr trzyma się prawej krawędzi okna', x1.frameRight === V.width,
    x1.frameRight + ' px');

  // Rodzic zostaje KLIKALNY — o to w tym trybie chodzi. `inert` na nim
  // odciąłby tabulatorem połowę tego, co widać na ekranie.
  t.check('rodzic zostaje dostępny tabulatorem', !start.inert,
    start.inert ? 'inert' : 'dostępny');
  t.check('bieżący jest wciąż dokładnie jeden',
    x1.panels.filter((q) => q.current).length === 1 && uslugi.current,
    x1.panels.filter((q) => q.current).map((q) => q.id).join(', '));
  // Panel spoza ścieżki nie ma czego zajmować miejsca — wszedłby między
  // rodzica a podmenu i rozerwał układ.
  t.check('panel spoza ścieżki jest schowany i odcięty',
    !x1.panels.find((q) => q.id === 'detale').shown
    && x1.panels.find((q) => q.id === 'detale').inert, 'detale');

  // Poszerzanie to ruch MIĘDZY PANELAMI, więc bierze tempo taśmy, nie kadru.
  const fw = await ex.evaluate(() => window.__frameWidth());
  const fx = await ex.evaluate(() => window.__slide());
  t.check('poszerzanie ma tempo taśmy, nie kadru',
    Math.abs(parseFloat(fw.transition) - 0.5) < 0.01
    && Math.abs(parseFloat(fx.transition) - 0.3) < 0.01,
    'poszerzanie ' + fw.transition + ' (0,5), wysuwanie ' + fx.transition + ' (0,3)');

  await ex.evaluate(() => window.__click('back-1'));
  await ex.waitForTimeout(800);
  const x2 = await ex.evaluate(() => window.__expand());
  t.check('powrót zwęża kadr z powrotem', x2.frameW === one, x1.frameW + ' → ' + x2.frameW + ' px');
  t.check('bez błędów JS', !ex.errors.length, ex.errors.join(' | ') || 'brak');
  await ex.close();

  // ── Drugi podrzędny ZASTĘPUJE pierwszy ─────────────────────────────────
  // Zgłoszone z użycia: „przy otwieraniu drugiego panelu podrzędnego pierwszy
  // musi zacząć wyjeżdżać w stronę, z której wyjechał, a drugi zastąpić jego
  // miejsce". Podrzędny jest więc ZAWSZE JEDEN — wcześniejsza wersja układała
  // je w stos, a to dawało stany, z których „wstecz" wracało po jednym
  // poziomie zamiast tam, skąd widać całe menu.
  t.section('drugi podrzędny zastępuje pierwszy');

  const ov = await t.open('offcanvas.html', { viewport: V, query: 'dur=0.2&pdur=0.3', settle: 120 });
  await ov.evaluate(() => window.__open());
  await ov.waitForTimeout(400);
  await ov.evaluate(() => window.__click('go-uslugi'));
  await ov.waitForTimeout(600);
  const two = await ov.evaluate(() => window.__expand());
  const slotAt = two.panels.find((q) => q.id === 'uslugi').left;

  await ov.evaluate(() => window.__click('go-detale'));
  await ov.waitForTimeout(900);
  const three = await ov.evaluate(() => window.__expand());

  t.check('kadr NIE rośnie przy podmianie', three.frameW === two.frameW,
    two.frameW + ' → ' + three.frameW + ' px');
  t.check('nowy panel zajął miejsce poprzedniego',
    Math.abs(three.panels.find((q) => q.id === 'detale').left - slotAt) < 2,
    'detale @ ' + three.panels.find((q) => q.id === 'detale').left + ', poprzedni był @ ' + slotAt);
  // Sedno: poprzedni ma ZNIKNĄĆ, a nie zostać pod spodem.
  t.check('poprzedni podrzędny wyjechał',
    !three.panels.find((q) => q.id === 'uslugi').shown, 'schowany');
  t.check('otwarty jest DOKŁADNIE JEDEN podrzędny',
    three.panels.filter((q) => q.shown && q.id !== 'start').length === 1,
    three.panels.filter((q) => q.shown).map((q) => q.id).join(' + '));
  t.check('panel główny nadal dostępny',
    !three.panels.find((q) => q.id === 'start').inert, 'dostępny');
  t.check('bez błędów JS', !ov.errors.length, ov.errors.join(' | ') || 'brak');
  await ov.close();

  // ── Poprzedni wyjeżdża TAM, SKĄD PRZYJECHAŁ ────────────────────────────
  // Przy menu z prawej podrzędny wjeżdża zza prawej krawędzi, więc i wyjeżdża
  // w prawo. Przeciwny kierunek czytałoby się jako „poszło gdzie indziej".
  t.section('poprzedni podrzędny wyjeżdża w swoją stronę');

  const sw = await t.open('offcanvas.html', { viewport: V, query: 'dur=0.2&pdur=0.6', settle: 120 });
  await sw.evaluate(() => window.__open());
  await sw.waitForTimeout(400);
  await sw.evaluate(() => window.__click('go-uslugi'));
  await sw.waitForTimeout(1200);
  await sw.evaluate(() => window.__click('go-detale'));
  await sw.waitForTimeout(200);

  const mid3 = await sw.evaluate(() => window.__expand());
  const outU = mid3.panels.find((q) => q.id === 'uslugi');
  t.check('poprzedni jedzie w PRAWO', outU.tx > 20, 'przesunięcie ' + outU.tx + ' px');
  t.check('poprzedni jest jeszcze rysowany', outU.shown, 'rysowany');
  // Kolumna przycina — bez tego wyjeżdżający panel kładłby się na treści obok.
  const col = await sw.evaluate(() => window.__column());
  t.check('kolumna podrzędna przycina', col.slotOverflow === 'hidden', String(col.slotOverflow));
  await sw.close();

  // ── Wstecz i Esc wracają ZAWSZE do panelu głównego ─────────────────────
  // „Nie może być sytuacji, żeby otwarte były dwa podrzędne" — a skoro tak,
  // to nie ma poziomu pośredniego, do którego dałoby się cofnąć.
  t.section('wstecz wraca zawsze do panelu głównego');

  const bk = await t.open('offcanvas.html', { viewport: V, query: 'dur=0.2&pdur=0.3', settle: 120 });
  await bk.evaluate(() => window.__open());
  await bk.waitForTimeout(400);
  await bk.evaluate(() => window.__click('go-uslugi'));
  await bk.waitForTimeout(600);
  await bk.evaluate(() => window.__click('go-detale'));
  await bk.waitForTimeout(700);
  await bk.evaluate(() => window.__click('back-2'));
  await bk.waitForTimeout(800);

  const home = await bk.evaluate(() => window.__expand());
  t.check('wracamy do głównego, nie do poprzedniego podmenu',
    home.panels.find((q) => q.id === 'start').current,
    home.panels.filter((q) => q.current).map((q) => q.id).join(', ') || 'żaden');
  t.check('kadr wrócił do jednej kolumny', home.frameW === two.frameW / 2,
    home.frameW + ' px');

  // Esc z podmenu — ta sama droga.
  await bk.evaluate(() => window.__click('go-uslugi'));
  await bk.waitForTimeout(600);
  await bk.evaluate(() => window.__click('go-detale'));
  await bk.waitForTimeout(700);
  await bk.evaluate(() => window.__key('Escape'));
  await bk.waitForTimeout(800);
  t.check('Esc też wraca do głównego, a nie zamyka',
    (await bk.evaluate(() => window.__isOpen()))
    && (await bk.evaluate(() => window.__expand())).panels.find((q) => q.id === 'start').current,
    (await bk.evaluate(() => window.__isOpen())) ? 'otwarte na głównym' : 'ZAMKNIĘTE');
  t.check('bez błędów JS', !bk.errors.length, bk.errors.join(' | ') || 'brak');
  await bk.close();

  // ── Menu z lewej to LUSTRZANE ODBICIE ──────────────────────────────────
  // Zgłoszone z użycia. Przy menu z prawej kolumna podrzędna leży przy prawej
  // krawędzi — tej, z której menu wyjechało — a panel wjeżdża stamtąd. Z lewej
  // musi być dokładnie odwrotnie; inaczej podmenu przyjeżdża z przeciwnego
  // końca ekranu niż samo menu.
  t.section('menu z lewej to lustrzane odbicie');

  const lf = await t.open('offcanvas.html',
    { viewport: V, query: 'side=left&dur=0.2&pdur=0.6', settle: 120 });
  await lf.evaluate(() => window.__open());
  await lf.waitForTimeout(400);
  const l0 = await lf.evaluate(() => window.__expand());
  t.check('kadr trzyma się LEWEJ krawędzi', l0.frameLeft === 0, l0.frameLeft + ' px');

  await lf.evaluate(() => window.__click('go-uslugi'));
  await lf.waitForTimeout(200);
  const lmid = await lf.evaluate(() => window.__expand());
  t.check('podmenu wjeżdża Z LEWEJ',
    lmid.panels.find((q) => q.id === 'uslugi').tx < -20,
    'przesunięcie ' + lmid.panels.find((q) => q.id === 'uslugi').tx + ' px');

  await lf.waitForTimeout(1000);
  const l1 = await lf.evaluate(() => window.__expand());
  const lStart = l1.panels.find((q) => q.id === 'start');
  const lSub   = l1.panels.find((q) => q.id === 'uslugi');
  t.check('podmenu stanęło przy LEWEJ krawędzi', lSub.left === 0, lSub.left + ' px');
  t.check('panel główny przesunął się w PRAWO', lStart.left === l0.panels[0].left + l0.frameW,
    l0.panels[0].left + ' → ' + lStart.left + ' px');
  t.check('oba panele widoczne, jak z prawej',
    lStart.shown && lSub.shown && l1.frameW === l0.frameW * 2,
    'kadr ' + l0.frameW + ' → ' + l1.frameW + ' px');
  t.check('bez błędów JS', !lf.errors.length, lf.errors.join(' | ') || 'brak');
  await lf.close();

  // ── Panel podrzędny dojeżdża Z OPÓŹNIENIEM ─────────────────────────────
  // Zgłoszone z użycia: „nie jest tak sztywno — najpierw obecny panel się
  // rozszerza, a potem drugorzędny dojeżdża". Bez odstępu oba ruchy zaczynają
  // i kończą się równo, więc nie widać, co po czym następuje.
  t.section('panel podrzędny dojeżdża z opóźnieniem');

  const dl = await t.open('offcanvas.html',
    { viewport: V, query: 'dur=0.2&pdur=0.6&sdelay=0.3', settle: 120 });
  await dl.evaluate(() => window.__open());
  await dl.waitForTimeout(400);
  const dl0 = await dl.evaluate(() => window.__expand());

  await dl.evaluate(() => window.__click('go-uslugi'));
  await dl.waitForTimeout(180);
  const midd = await dl.evaluate(() => window.__expand());
  const midU = midd.panels.find((q) => q.id === 'uslugi');

  t.check('kadr już się poszerza', midd.frameW > dl0.frameW + 20,
    dl0.frameW + ' → ' + midd.frameW + ' px');
  /* 180 ms przy odstępie 300 ms — panel ma jeszcze STAĆ poza slotem.
     Mierzymy jego WŁASNE przesunięcie, nie prostokąt na ekranie: gdy na kadrze
     trwa przejście `width`, prostokąty potomków w tym harnessie pochodzą
     z innej klatki niż prostokąt kadru (zmierzone: kadr 583, taśma −60 przy
     `offsetLeft` taśmy równym 0). Transformacja jest wiarygodna. */
  t.check('panel jeszcze NIE ruszył', midU.tx >= midU.w - 2,
    'przesunięcie ' + midU.tx + ' z ' + midU.w + ' px');

  await dl.waitForTimeout(1000);
  const dl1 = await dl.evaluate(() => window.__expand());
  t.check('po wszystkim panel stoi na miejscu',
    dl1.panels.find((q) => q.id === 'uslugi').tx === 0,
    'przesunięcie ' + dl1.panels.find((q) => q.id === 'uslugi').tx + ' px');

  // Kontrola negatywna: bez odstępu panel rusza od razu i całość jest sztywna.
  const nd = await t.open('offcanvas.html',
    { viewport: V, query: 'dur=0.2&pdur=0.6&sdelay=0', settle: 120 });
  await nd.evaluate(() => window.__open());
  await nd.waitForTimeout(400);
  await nd.evaluate(() => window.__click('go-uslugi'));
  await nd.waitForTimeout(180);
  const nmid = await nd.evaluate(() => window.__expand());
  t.check('bez odstępu panel rusza RAZEM z kadrem',
    nmid.panels.find((q) => q.id === 'uslugi').tx < nmid.panels.find((q) => q.id === 'uslugi').w - 20,
    'przesunięcie ' + nmid.panels.find((q) => q.id === 'uslugi').tx + ' px');
  await nd.close();
  await dl.close();

  // ── Przy zamykaniu nie widać gołego kadru ──────────────────────────────
  // Zgłoszone z użycia: „jak zamykam otwarty drugi panel, jak wyjeżdża,
  // zmienia kolor na biały". Panel znikał natychmiast (`display: none`),
  // a kadr zwężał się jeszcze przez cały czas przejścia — przez ten czas
  // widać było samo tło kadru, domyślnie białe.
  t.section('przy zamykaniu nie widać gołego kadru');

  const bg = await t.open('offcanvas.html', { viewport: V, query: 'dur=0.2&pdur=0.6', settle: 120 });
  await bg.evaluate(() => window.__open());
  await bg.waitForTimeout(400);
  await bg.evaluate(() => window.__click('go-uslugi'));
  await bg.waitForTimeout(1000);
  t.check('przy otwartych dwóch panelach kadr jest cały przykryty',
    (await bg.evaluate(() => window.__bare())) === 0,
    (await bg.evaluate(() => window.__bare())) + ' px gołego kadru');

  await bg.evaluate(() => window.__click('back-1'));
  const probes = [];
  for (let i = 0; i < 5; i++) {
    await bg.waitForTimeout(110);
    probes.push(await bg.evaluate(() => window.__bare()));
  }
  t.check('w trakcie zwężania też nic nie prześwituje',
    probes.every((q) => q === 0), probes.join(' / ') + ' px');

  await bg.waitForTimeout(600);
  t.check('po zamknięciu panel znika na dobre',
    !(await bg.evaluate(() => window.__expand())).panels.find((q) => q.id === 'uslugi').shown,
    'schowany');
  t.check('bez błędów JS', !bg.errors.length, bg.errors.join(' | ') || 'brak');
  await bg.close();

  // ── Tło kadru bierze się z panelu ──────────────────────────────────────
  // Pasek odsłonięty na czas opóźnienia ma być NIEWIDOCZNY bez ustawiania
  // czegokolwiek. Domyślna biel rzucała się w oczy przy ciemnym menu, a nie
  // ma powodu, żeby użytkownik zgadywał — kolor jest wprost w panelu.
  t.section('tło kadru bierze się z panelu');

  const bgc = await t.open('offcanvas-bricks.html', { viewport: V, settle: 200 });
  await bgc.evaluate(() => window.__open());
  await bgc.waitForTimeout(600);
  const bgv = await bgc.evaluate(() => {
    var f = document.querySelector('.evk-oc-frame');
    var p = document.querySelector('#brxe-pjtvtc');
    return { frame: getComputedStyle(f).backgroundColor,
             panel: getComputedStyle(p).backgroundColor };
  });
  t.check('kadr ma kolor bieżącego panelu', bgv.frame === bgv.panel,
    'kadr ' + bgv.frame + ', panel ' + bgv.panel);
  t.check('a nie domyślną biel', bgv.frame !== 'rgb(255, 255, 255)', bgv.frame);
  await bgc.close();

  // ── Wąskie okno: poszerzać nie ma dokąd ────────────────────────────────
  // Dwa panele po 420 px nie zmieszczą się na telefonie. Menu MUSI wtedy
  // samo wrócić do pokazywania jednego — inaczej byłoby szersze niż ekran
  // i rodzic i tak by nie pomógł, bo wyjechałby za lewą krawędź.
  t.section('na wąskim ekranie poszerzanie samo się cofa');

  const nar = await t.open('offcanvas.html', { viewport: { width: 390, height: 760 }, settle: 120 });
  await nar.evaluate(() => window.__open());
  await nar.waitForTimeout(400);
  await nar.evaluate(() => window.__click('go-uslugi'));
  await nar.waitForTimeout(700);

  const xn = await nar.evaluate(() => window.__expand());
  t.check('kadr nie przekracza szerokości okna', xn.frameW <= 390, xn.frameW + ' px');
  t.check('widać dokładnie jeden panel',
    xn.panels.filter((q) => q.shown && q.left >= 0 && q.left < 390).length === 1,
    xn.panels.filter((q) => q.shown).map((q) => q.id + '@' + q.left).join(' '));
  t.check('na ekranie stoi podmenu, nie rodzic',
    xn.panels.find((q) => q.id === 'uslugi').left === 0,
    'uslugi @ ' + xn.panels.find((q) => q.id === 'uslugi').left + ' px');
  t.check('bez błędów JS', !nar.errors.length, nar.errors.join(' | ') || 'brak');
  await nar.close();

  // ── Wypychanie przeżywa style Bricksa ──────────────────────────────────
  // Reszta tego pliku mierzy gołe `<div class="evk-oc-panel">`. Na stronie
  // panele są dziećmi nestable, więc niosą jeszcze `.brxe-block` z własnym
  // `display: flex` i `width: 100%` — a element, który działa tylko bez
  // cudzego CSS-a, nie działa na stronie. Znacznik w fixture jest ZRZUTEM
  // Z ŻYWEJ STRONY (zgłoszenie: „nadal nie przepycha panelu dalej").
  //
  // Sedno: panel musi mieć DOKŁADNIE szerokość kadru. Węższy znaczy, że
  // przesunięcie taśmy o −100% nie przestawia o jeden panel, a to widać
  // właśnie jako „drugi zasłania pierwszy".
  t.section('wypychanie działa w znacznikach Bricksa');

  const br = await t.open('offcanvas-bricks.html', { viewport: V, query: 'levels=slide', settle: 200 });
  t.check('bez błędów JS', !br.errors.length, br.errors.join(' | ') || 'brak');

  await br.evaluate(() => window.__open());
  await br.waitForTimeout(600);
  const g0 = await br.evaluate(() => window.__geo());

  t.check('taśma jest paskiem poziomym', g0.trackDisplay === 'flex', g0.trackDisplay);
  t.check('każdy panel ma szerokość kadru',
    g0.panels.every((p) => p.w === g0.frameW),
    'kadr ' + g0.frameW + ', panele ' + g0.panels.map((p) => p.w).join(' / '));
  t.check('panele leżą OBOK SIEBIE, nie na sobie',
    g0.panels.every((p) => p.pos === 'static') && g0.panels[1].left === g0.frameW,
    'pozycje ' + g0.panels.map((p) => p.left).join(' / ') + ' px');
  // `flex-shrink` z cudzego arkusza ścisnąłby oba panele do połowy kadru
  // i całość wyglądałaby poprawnie aż do pierwszego przejścia.
  t.check('style Bricksa nie ściskają paneli',
    g0.panels.every((p) => p.shrink === '0' && p.basis === '100%'),
    g0.panels.map((p) => p.basis + '/' + p.shrink).join(' '));

  await br.evaluate(() => window.__go());
  await br.waitForTimeout(700);
  const g1 = await br.evaluate(() => window.__geo());
  t.check('rodzic ODJECHAŁ o całą szerokość kadru', g1.panels[0].left === -g0.frameW,
    g0.panels[0].left + ' → ' + g1.panels[0].left + ' px');
  t.check('podmenu zajęło jego miejsce', g1.panels[1].left === 0,
    g0.panels[1].left + ' → ' + g1.panels[1].left + ' px');
  await br.close();

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
