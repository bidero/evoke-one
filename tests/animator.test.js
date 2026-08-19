/**
 * Animator — kolejka startowa.
 *
 * Broni usterki z 1.28.1: pozycje na osi czasu wstawiane były jako `'+=' + delay`,
 * a `'+='` liczy się w GSAP od KOŃCA osi, nie od jej początku. Opóźnienia sumowały
 * się z czasami trwania poprzednich animacji — ustawione 0 / 0,3 / 0,6 s dawało
 * starty 0 / 1,1 / 2,5 s. Pojedynczy element działał poprawnie, więc usterka
 * umykała; dlatego test ma zawsze KILKA elementów naraz.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  t.section('wyzwalacz „wczytanie strony”');

  const page = await t.open('animator.html', { viewport: { width: 1000, height: 700 }, settle: 0 });
  await page.waitForFunction(() => window.__op);

  // Moment ruszenia każdego elementu — próbkujemy opacity aż wszystkie wystartują.
  const t0 = Date.now();
  const started = {};
  while (Date.now() - t0 < 3000 && Object.keys(started).length < 4) {
    const op = await page.evaluate(() => window.__op());
    op.forEach((v, i) => { if (started[i] === undefined && v > 0.02) started[i] = (Date.now() - t0) / 1000; });
    await page.waitForTimeout(25);
  }

  // D siedzi w kroku 1, więc rusza po zakończeniu kroku 0: 0,6 s + 0,8 s = 1,4 s.
  const expected = [
    ['A — opóźnienie 0',    0],
    ['B — opóźnienie 0,3',  0.3],
    ['C — opóźnienie 0,6',  0.6],
    ['D — kolejność 1',     1.4],
  ];
  expected.forEach(([name, want], i) => {
    const got = started[i];
    t.check(name, got !== undefined && Math.abs(got - want) < 0.25,
      got === undefined ? 'NIGDY' : got.toFixed(2) + ' s (oczekiwane ~' + want + ' s)');
  });
  await page.close();

  // ── Zasłona: opóźnione mają stać niewidoczne, zanim ruszą ──────────────
  t.section('zasłona przed animacją');
  const p2 = await t.open('animator.html', { viewport: { width: 1000, height: 700 }, settle: 0 });
  await p2.waitForFunction(() => window.__op);
  const early = await p2.evaluate(() => window.__op());
  // A rusza natychmiast, więc sprawdzamy tylko te, które mają czekać.
  t.check('opóźnione stoją na opacity 0', early.slice(1).every((v) => v === 0), JSON.stringify(early));
  t.check('bez błędów JS', !p2.errors.length, p2.errors.join(' | ') || 'brak');
  await p2.close();

  // ── Cel zewnętrzny: A wyzwala, B się animuje ──────────────────────────
  // Silnik zawsze rozdzielał wyzwalacz od celu — `scrollTrigger.trigger` to
  // element, a `resolveTargets()` wybiera, co się rusza. Brakowało tylko
  // ZASIĘGU: `el.querySelectorAll()` trafia wyłącznie w potomków, więc
  // „przewinięcie do sekcji zmienia coś w nagłówku" było nie do zrobienia.
  t.section('cel poza elementem wyzwalającym');

  const ex = await t.open('anim-external.html', {
    viewport: { width: 1000, height: 700 }, settle: 200,
  });

  // Kontrola sensowności. Bez niej całość przeszłaby także dla dzisiejszego
  // „selektora w środku" — a to jest dokładnie ta różnica, o którą chodzi.
  t.check('cel LEŻY POZA wyzwalaczem',
    await ex.evaluate(() => window.__celPozaWyzwalaczem()), 'osobne poddrzewa');

  // Cel jest na górze strony i widoczny od początku — gdyby animacja podpięła
  // się pod niego jako wyzwalacz, zagrałaby od razu i test nic by nie znaczył.
  const zanim = await ex.evaluate(() => window.__opacity('cel'));
  t.check('przed przewinięciem cel czeka ukryty', zanim === 0, 'opacity ' + zanim);

  await ex.evaluate(() => window.__doWyzwalacza());
  await ex.waitForFunction(() => window.__opacity('cel') > 0.9, { timeout: 3000 })
    .catch(() => {});

  const po = await ex.evaluate(() => window.__opacity('cel'));
  t.check('przewinięcie do WYZWALACZA odsłania CEL', po > 0.9, 'opacity ' + po);

  // „Animuj tamtego" znaczy tamtego, nie obu.
  t.check('wyzwalacz zostaje nietknięty',
    !(await ex.evaluate(() => window.__wyzwalaczTknięty())), 'bez własnych varsów');

  t.check('bez błędów JS przy celu zewnętrznym', !ex.errors.length,
    ex.errors.join(' | ') || 'brak');
  await ex.close();
  /* ── Wyzwalacz najechania nie gasi elementu ────────────────────────────
   *
   * Zgłoszone z użycia: „chciałbym użyć niektórych predefiniowanych animacji,
   * ale większość powoduje, że element jest niewidoczny przed hover".
   *
   * Powód siedział w `buildTimeline`: ścieżka interaktywna szła przez
   * `fromTo`, a to renderuje stan początkowy NATYCHMIAST. Przy presecie
   * wejściowym `from` jest stanem ukrycia (`opacity: 0`), więc element parkował
   * niewidoczny aż do pierwszego najechania. Wyzwalacz wybiera się per użycie,
   * więc taką parę da się złożyć na elemencie niezależnie od tego, co stoi
   * w bibliotece — i dlatego pomiar idzie właśnie na niej.
   */
  t.section('hover nie gasi elementu');

  const presety = 'window.__presets = ' + JSON.stringify(JSON.parse(phpOutput('presets.php'))) + ';';
  const hv = await t.open('anim-hover.html',
    { viewport: { width: 1000, height: 700 }, head: presety, settle: 600 });

  const spoczynek = await hv.evaluate(() => window.__stan('wejsciowy'));
  t.check('preset wejściowy na hoverze JEST widoczny w spoczynku',
    spoczynek.opacity >= 0.99, 'opacity ' + spoczynek.opacity);
  t.check('i nie stoi przesunięty',
    spoczynek.transform === 'none' || /matrix\(1, 0, 0, 1, 0, 0\)/.test(spoczynek.transform),
    spoczynek.transform);

  /* KONTROLA NEGATYWNA. Gdyby parkowanie zniknęło WSZĘDZIE, powyższe też
     byłoby zielone — a na parkowaniu stoją wszystkie animacje wejściowe:
     element ma czekać ukryty, aż wjedzie w kadr. */
  const czeka = await hv.evaluate(() => window.__stan('kontrola'));
  t.check('a pod wejściem w kadr DALEJ czeka ukryty',
    czeka.opacity <= 0.01, 'opacity ' + czeka.opacity);

  /* Preset stanowy ma coś robić. Bez tego „widoczny w spoczynku" spełniłby
     także preset, który nie animuje niczego. Mierzone W LOCIE, bo stan
     końcowy powrotu jest identyczny ze stanem spoczynku. */
  const przed = await hv.evaluate(() => window.__stan('stanowy'));
  await hv.evaluate(() => window.__najedz('stanowy'));
  await hv.waitForTimeout(200);
  const wTrakcie = await hv.evaluate(() => window.__stan('stanowy'));
  /* Odchylenie od macierzy JEDNOSTKOWEJ, nie różnica napisów. Porównanie
     tekstowe przepuszczało preset stanowy ze `scale: 1`, bo `matrix(1, 0, 0,
     1, 0, 0)` to inny napis niż `none` przy dokładnie zerowym ruchu —
     sprawdzenie było zielone dla presetu nierobiącego nic (zmierzone mutacją). */
  const odchylenie = (v) => {
    const m = /\(([^)]*)\)/.exec(v || '');
    if (!m) return 0;
    const l = m[1].split(',').map((x) => Number(x.trim()));
    const jedn = l.length === 16
      ? [1,0,0,0, 0,1,0,0, 0,0,1,0, 0,0,0,1]
      : [1, 0, 0, 1, 0, 0];
    return Math.max(...l.map((x, i) => Math.abs(x - (jedn[i] === undefined ? 0 : jedn[i]))));
  };
  t.check('preset stanowy po najechaniu przekształca',
    odchylenie(wTrakcie.transform) > 0.01,
    przed.transform + ' → ' + wTrakcie.transform
      + ' (odchylenie ' + odchylenie(wTrakcie.transform).toFixed(3) + ')');

  await hv.evaluate(() => window.__zjedz('stanowy'));
  await hv.waitForFunction(() => window.__bezRuchu(), null, { timeout: 3000 }).catch(() => {});
  const poPowrocie = await hv.evaluate(() => window.__stan('stanowy'));
  /* `none` i macierz jednostkowa to ten sam stan widoczny — GSAP po powrocie
     zostawia wpisaną transformację, tylko że tożsamościową. Porównanie samych
     napisów zapalałoby się na czerwono przy elemencie stojącym dokładnie tam,
     gdzie ma stać. */
  const spoczynkowa = (v) => v === 'none' || /^matrix\(1, 0, 0, 1, 0, 0\)$/.test(v);
  t.check('a po zjechaniu wraca do spoczynku',
    spoczynkowa(poPowrocie.transform) && spoczynkowa(przed.transform),
    przed.transform + ' → ' + poPowrocie.transform);

  /* Efekt tekstowy zachowuje stan początkowy MIMO pominięcia `from` presetu —
     maszyna do pisania zaczyna od pustego pola i bez tego nie miałaby czego
     wypisywać. Kontrola pokazująca, że skasowaliśmy `from` PRESETU, a nie stan
     początkowy w ogóle. */
  await hv.evaluate(() => window.__najedz('tekstowy'));
  await hv.waitForTimeout(120);
  const wPolowie = await hv.evaluate(() => window.__stan('tekstowy'));
  t.check('efekt tekstowy na hoverze dalej startuje od pustego',
    wPolowie.text.length < 'tekstowy'.length, JSON.stringify(wPolowie.text));
  await hv.waitForFunction(() => window.__bezRuchu(), null, { timeout: 4000 }).catch(() => {});
  const naKoniec = await hv.evaluate(() => window.__stan('tekstowy'));
  t.check('i dopisuje treść do końca', naKoniec.text === 'tekstowy',
    JSON.stringify(naKoniec.text));

  t.check('bez błędów JS przy hoverze', !hv.errors.length, hv.errors.join(' | ') || 'brak');
  await hv.close();

  /* ── Redukcja ruchu a rodzina stanowa ──────────────────────────────────
   *
   * Bramka redukcji ruchu wykluczała stany po WYZWALACZU. Preset stanowy
   * podpięty pod wejście w kadr przez nią przechodził i jego `to` było
   * nakładane NA STAŁE — element zostawał trwale przygaszony u każdego, kto
   * ruch ogranicza. Warunek po znaczniku rodziny łapie to niezależnie od tego,
   * co wybrano w panelu.
   */
  t.section('redukcja ruchu — stan nie zostaje na stałe');

  const red = await t.open('anim-hover.html',
    { viewport: { width: 1000, height: 700 }, head: presety, reduce: true, settle: 600 });
  const stanRed = await red.evaluate(() => window.__stan('stanwkadrze'));
  t.check('przygaszenie NIE zostaje nałożone', stanRed.opacity >= 0.99,
    'opacity ' + stanRed.opacity);

  /* Lustro: redukcja ruchu ma nadal doprowadzać WEJŚCIA do stanu końcowego,
     inaczej element z `opacity: 0` we `from` zostałby niewidzialny na zawsze. */
  const wejRed = await red.evaluate(() => window.__stan('kontrola'));
  t.check('a wejście mimo redukcji dochodzi do widoczności', wejRed.opacity >= 0.99,
    'opacity ' + wejRed.opacity);
  await red.close();
  /* ── Podmiana treści na najechaniu ─────────────────────────────────────
   *
   * Zgłoszone jako „chodziło mi też o efekty w stylu nextbricks.io/swap-hover":
   * tekst wyjeżdża, a jego kopia wjeżdża na to samo miejsce.
   *
   * Kopia jest sednem — bez niej efekt sprowadza się do zniknięcia napisu.
   * Dlatego sprawdzenia pilnują OBU ruchów naraz i mierzą je W LOCIE: po
   * zakończeniu klon stoi dokładnie tam, gdzie na początku stał oryginał,
   * więc sam stan końcowy nie odróżnia „podmieniło się" od „nic się nie stało".
   */
  t.section('podmiana treści na najechaniu');

  const sw = await t.open('anim-swap.html',
    { viewport: { width: 900, height: 600 }, head: presety, settle: 700 });

  const spokoj = await sw.evaluate(() => window.__swap('wgore'));
  t.check('tekst został podzielony na kawałki', spokoj.kawalkow >= 2,
    spokoj.kawalkow + ' kawałków');
  t.check('każdy kawałek dostał klon', spokoj.klonow === spokoj.kawalkow,
    spokoj.klonow + ' klonów do ' + spokoj.kawalkow + ' kawałków');

  /* W spoczynku widać JEDEN tekst: oryginał na swoim miejscu, klon poza
     maską. Gdyby klon stał w kadrze, napis byłby podwójny. */
  t.check('w spoczynku oryginał jest na miejscu', Math.abs(spokoj.oryginal) <= 5,
    spokoj.oryginal + '% wysokości');
  t.check('a klon czeka poza maską', Math.abs(spokoj.klon) >= 80,
    spokoj.klon + '% wysokości');

  /* Treść dla czytnika ekranu ma zostać POJEDYNCZA — klon jest czystym
     powtórzeniem i bez `aria-hidden` napis byłby czytany dwa razy. */
  t.check('a tekst dostępny pozostaje pojedynczy',
    spokoj.tekstDostepny === 'Zobacz projekty', JSON.stringify(spokoj.tekstDostepny));

  /* Dostępność mierzona na elemencie Z DZIEĆMI. Przy jednym węźle tekstowym
     SplitText sam ustawia `aria-hidden` na wszystkich kawałkach, więc klon
     dziedziczy je z klonowania i sprawdzenie nie mówi nic o wtyczce — puste
     przechodziło nawet po skasowaniu tej linijki z silnika (zmierzone mutacją).
     Przy kilkorgu dzieci SplitText kawałków NIE chowa, bo aria-label sklejałby
     nazwy odnośników — i wtedy ukrycie klonu należy do silnika. */
  const zDziecmi = await sw.evaluate(() => window.__swap('zdziecmi'));
  t.check('przy elemencie z dziećmi podział NIE chowa kawałków',
    !zDziecmi.podzialUkrylKawalki, 'kontrola: ' + zDziecmi.podzialUkrylKawalki);
  t.check('a klony i tak są ukryte przed czytnikiem', zDziecmi.ukryteDlaCzytnika,
    zDziecmi.klonow + ' klonów, aria-hidden na wszystkich: ' + zDziecmi.ukryteDlaCzytnika);

  // ── Ruch: oryginał wyjeżdża, klon wchodzi ────────────────────────────
  await sw.evaluate(() => window.__najedz('wgore'));
  await sw.waitForTimeout(220);
  const wLocie = await sw.evaluate(() => window.__swap('wgore'));
  t.check('po najechaniu oryginał wyjeżdża', wLocie.oryginal < spokoj.oryginal - 10,
    spokoj.oryginal + '% → ' + wLocie.oryginal + '%');
  t.check('a klon w tej samej chwili wchodzi',
    Math.abs(wLocie.klon) < Math.abs(spokoj.klon) - 10,
    spokoj.klon + '% → ' + wLocie.klon + '%');

  /* Opóźnienie między kawałkami: pierwszy i ostatni klon mają w tej samej
     chwili RÓŻNY postęp. Bez tego stagger mógłby być wyzerowany i nikt by
     nie zauważył. */
  t.check('kawałki jadą z opóźnieniem względem siebie',
    Math.abs(wLocie.klonPierwszy - wLocie.klonOstatni) >= 5,
    wLocie.klonPierwszy + '% vs ' + wLocie.klonOstatni + '%');

  await sw.evaluate(() => window.__zjedz('wgore'));
  await sw.waitForFunction(() => window.__bezRuchu(), null, { timeout: 3000 }).catch(() => {});
  const powrot = await sw.evaluate(() => window.__swap('wgore'));
  t.check('po zjechaniu wraca do stanu wyjściowego',
    Math.abs(powrot.oryginal) <= 5 && Math.abs(powrot.klon) >= 80,
    'oryginał ' + powrot.oryginal + '%, klon ' + powrot.klon + '%');

  /* KIERUNEK. Przy jednym wariancie „w którą stronę" nie da się odróżnić od
     „w jakąkolwiek" — dlatego drugi element z presetem `-down` i porównanie
     ZNAKÓW przesunięcia. */
  const dolSpokoj = await sw.evaluate(() => window.__swap('wdol'));
  t.check('drugi kierunek czeka po PRZECIWNEJ stronie',
    Math.sign(dolSpokoj.klon) === -Math.sign(spokoj.klon),
    'z dołu: ' + spokoj.klon + '% | z góry: ' + dolSpokoj.klon + '%');

  await sw.evaluate(() => window.__najedz('wdol'));
  await sw.waitForTimeout(220);
  const dolWLocie = await sw.evaluate(() => window.__swap('wdol'));
  t.check('i jedzie w przeciwną stronę',
    Math.sign(dolWLocie.oryginal - dolSpokoj.oryginal)
      === -Math.sign(wLocie.oryginal - spokoj.oryginal),
    'z dołu: ' + (wLocie.oryginal - spokoj.oryginal)
      + ' | z góry: ' + (dolWLocie.oryginal - dolSpokoj.oryginal));

  t.check('bez błędów JS przy podmianie', !sw.errors.length, sw.errors.join(' | ') || 'brak');
  await sw.close();

  /* ── Ponowny podział nie zwielokrotnia nasłuchów ───────────────────────
   *
   * `autoSplit` odtwarza kawałki po każdej zmianie szerokości okna i woła
   * `onSplit` ponownie. Bez przerwania poprzednich nasłuchów na elemencie
   * wisiałoby kilka kompletów i jedno najechanie uruchamiałoby kilka osi
   * czasu naraz — z których tylko ostatnia dotyczy istniejących kawałków.
   */
  t.section('podmiana po zmianie szerokości okna');

  const rs = await t.open('anim-swap.html',
    { viewport: { width: 900, height: 600 }, head: presety, settle: 700 });

  /* Pomiar idzie na podziale na LINIE. Podział na słowa nie zależy od
     szerokości okna, więc `autoSplit` go nie przebudowuje — to samo sprawdzenie
     zrobione na słowach było PUSTE i przechodziło także po skasowaniu strażnika
     (zmierzone mutacją). */
  const przedZmiana = await rs.evaluate(() => window.__swap('linie'));
  await rs.setViewportSize({ width: 420, height: 600 });
  await rs.waitForTimeout(800);

  const poZmianie = await rs.evaluate(() => window.__swap('linie'));
  /* Bez tego cała sekcja byłaby zielona także wtedy, gdyby ponowny podział
     w ogóle nie nastąpił — a wtedy nie ma czego pilnować. */
  t.check('zmiana szerokości NAPRAWDĘ przebudowała podział',
    poZmianie.kawalkow !== przedZmiana.kawalkow,
    przedZmiana.kawalkow + ' → ' + poZmianie.kawalkow + ' linii');
  t.check('po przebudowie klonów tyle co kawałków', poZmianie.klonow === poZmianie.kawalkow,
    poZmianie.klonow + ' klonów do ' + poZmianie.kawalkow + ' kawałków');

  await rs.evaluate(() => window.__najedz('linie'));
  await rs.waitForTimeout(80);
  const osie = await rs.evaluate(() => window.__aktywneOsie());
  t.check('jedno najechanie rusza JEDNĄ oś czasu', osie === 1, osie + ' osi');
  t.check('bez błędów JS po przebudowie', !rs.errors.length, rs.errors.join(' | ') || 'brak');
  await rs.close();

  /* ── Redukcja ruchu: klonów nie ma w ogóle ─────────────────────────────
   *
   * Gałąź redukcji wychodzi przed podziałem tekstu, więc nie powstają ani
   * maski, ani klony — element zostaje taki, jak wyrenderował go CSS.
   */
  t.section('podmiana a redukcja ruchu');

  const swRed = await t.open('anim-swap.html',
    { viewport: { width: 900, height: 600 }, head: presety, reduce: true, settle: 600 });
  const red2 = await swRed.evaluate(() => window.__swap('wgore'));
  t.check('przy redukcji ruchu nie ma żadnych klonów', red2.klonow === 0,
    red2.klonow + ' klonów');
  t.check('a treść jest widoczna i pojedyncza',
    swRed.evaluate(() => document.getElementById('wgore').textContent.trim())
      .then((x) => x === 'Zobacz projekty'), 'sprawdzane niżej');
  await swRed.close();
  /* ── Zakres szerokości okna ────────────────────────────────────────────
   *
   * Zgłoszone jako „media query dla animacji". Wiersz biblioteki dostaje
   * zakres, w którym animacja ma w ogóle istnieć — poza nim silnik nie buduje
   * osi czasu, nie dzieli tekstu i nie zakłada ScrollTriggerów.
   *
   * Najgroźniejsza możliwa regresja to nie „gra tam, gdzie nie powinna", tylko
   * „treść ukryta na stałe": zasłona `evk-veil` chowa wszystko z atrybutem,
   * dopóki silnik jej nie zdejmie. Dlatego widoczność ma tu osobne sprawdzenie
   * i to ono jest w tej sekcji najważniejsze.
   */
  t.section('zakres szerokości okna');

  const wask = await t.open('anim-breakpoint.html',
    { viewport: { width: 500, height: 600 }, head: presety, settle: 700 });

  const w500 = await wask.evaluate(() => window.__bp('waski'));
  const s500 = await wask.evaluate(() => window.__bp('szeroki'));
  const z500 = await wask.evaluate(() => window.__bp('zawsze'));

  t.check('w wąskim oknie wiersz „do 700" GRA', w500.tweenow > 0,
    w500.tweenow + ' tweenów');
  t.check('a wiersz „od 900" NIE powstaje', s500.tweenow === 0,
    s500.tweenow + ' tweenów');
  /* Kontrola negatywna: bez niej „nie powstaje" byłoby spełnione także wtedy,
     gdyby silnik przestał działać na tej szerokości w ogóle. */
  t.check('kontrola: wiersz bez granic gra tak czy owak', z500.tweenow > 0,
    z500.tweenow + ' tweenów');

  /* SEDNO. Element pominięty musi być WIDOCZNY — inaczej zasłona zostawia
     tekst niewidzialny na stałe, czyli pusty ekran na telefonie. */
  t.check('zasłona zeszła mimo pominięcia', !(await wask.evaluate(() => window.__zaslona())),
    'evk-veil zdjęta');
  t.check('a pominięty element JEST widoczny',
    s500.visibility === 'visible' && s500.opacity >= 0.99,
    s500.visibility + ', opacity ' + s500.opacity);

  /* Znacznik gotowości: pominięty element ma go NIE mieć, bo to on decyduje,
     czy silnik do niego wróci. */
  t.check('pominięty nie dostaje znacznika gotowości', !s500.gotowy, String(s500.gotowy));
  t.check('a zbudowany dostaje', z500.gotowy, String(z500.gotowy));

  // ── Wejście w zakres dobudowuje ──────────────────────────────────────
  await wask.setViewportSize({ width: 1100, height: 600 });
  await wask.waitForTimeout(500);
  const s1100 = await wask.evaluate(() => window.__bp('szeroki'));
  t.check('po poszerzeniu okna pominięta animacja POWSTAJE', s1100.tweenow > 0,
    s1100.tweenow + ' tweenów');

  /* Element z dwiema animacjami: jedna grała od razu, druga była poza zakresem.
     Gdyby znacznik gotowości stanął po tej pierwszej, druga nie dobudowałaby
     się nigdy.

     DOKŁADNIE dwa, nie „co najmniej". Element bez znacznika gotowości wraca do
     initOne() przy każdej przebudowie, a bez pamięci zbudowanych konfiguracji
     ta pierwsza, gotowa animacja dostawała za każdym razem kolejną oś czasu.
     Przy „co najmniej dwóch" podwojenie przechodziło niezauważone. */
  const dwie = await wask.evaluate(() => window.__bp('dwie'));
  t.check('element z dwiema animacjami dobudował tę drugą', dwie.tweenow === 2,
    dwie.tweenow + ' tweenów');

  t.check('bez błędów JS przy zakresach', !wask.errors.length,
    wask.errors.join(' | ') || 'brak');
  await wask.close();

  // ── Ta sama strona w szerokim oknie: granica działa w drugą stronę ────
  const szer = await t.open('anim-breakpoint.html',
    { viewport: { width: 1200, height: 600 }, head: presety, settle: 700 });
  const w1200 = await szer.evaluate(() => window.__bp('waski'));
  const s1200 = await szer.evaluate(() => window.__bp('szeroki'));
  t.check('w szerokim oknie wiersz „od 900" gra', s1200.tweenow > 0,
    s1200.tweenow + ' tweenów');
  t.check('a wiersz „do 700" NIE powstaje', w1200.tweenow === 0,
    w1200.tweenow + ' tweenów');
  t.check('i on też jest widoczny',
    w1200.visibility === 'visible' && w1200.opacity >= 0.99,
    w1200.visibility + ', opacity ' + w1200.opacity);
  await szer.close();
  /* ── Silnik nie skanuje własnych wytworów ───────────────────────────────
   *
   * Zgłoszone z użycia: przy każdej zmianie szerokości okna konsola zalewała
   * się ostrzeżeniami „Brak animacji »line-mask« / »char-mask« / »swap-klon«
   * w bibliotece", ze śladem prowadzącym przez `initAll` do nasłuchu progów
   * z 1.93.0.
   *
   * Powód: `initAll()` przechodzi po `[class*="evk-anim-"]`, a filtr pomijający
   * kawałki znał tylko trzy nazwy (`line`, `word`, `char`). Nie znał MASEK,
   * które dokłada opcja `mask` SplitTextu, ani KLONÓW podmiany treści z 1.92.0.
   * Póki `initAll()` biegł raz na życie strony, nie było tego widać — zakres
   * szerokości zaczął go wołać ponownie i wysypał lawinę.
   *
   * Drugi, cichszy skutek: taki węzeł nigdy nie dostawał znacznika gotowości,
   * więc wracał przy KAŻDEJ kolejnej zmianie progu. Przy podziale na znaki to
   * kilkadziesiąt węzłów na jeden nagłówek.
   */
  t.section('własne wytwory silnika nie są animacjami');

  const wy = await t.open('anim-breakpoint.html',
    { viewport: { width: 1000, height: 600 }, head: presety, settle: 800 });

  /* Najpierw dowód, że jest CO pomijać — bez podziału z maską reszta sekcji
     przechodziłaby, bo nie byłoby żadnych wytworów. */
  const wytwory = await wy.evaluate(() => window.__wytwory());
  t.check('fixture naprawdę tworzy kawałki i maski',
    wytwory.kawalki > 0 && wytwory.maski > 0,
    wytwory.kawalki + ' kawałków, ' + wytwory.maski + ' masek');

  const ostrzezenPrzed = wy.warnings.filter((w) => /Brak animacji/.test(w)).length;
  t.check('po wczytaniu żadnych ostrzeżeń o brakującej animacji',
    ostrzezenPrzed === 0, ostrzezenPrzed + ' ostrzeżeń');

  /* SEDNO ZGŁOSZENIA: zmiana progu nie może wysypać ostrzeżeń. Dwie zmiany,
     bo przy jednej „nie wraca po raz drugi" byłoby nie do odróżnienia. */
  await wy.setViewportSize({ width: 600, height: 600 });
  await wy.waitForTimeout(400);
  await wy.setViewportSize({ width: 1100, height: 600 });
  await wy.waitForTimeout(400);

  const poZmianach = wy.warnings.filter((w) => /Brak animacji/.test(w)).length;
  t.check('i po dwóch zmianach progu nadal żadnych',
    poZmianach === 0, poZmianach + ' ostrzeżeń: ' + (wy.warnings[0] || ''));

  /* Wytwór nie może dostać znacznika gotowości — ma być pomijany, zanim
     w ogóle dojdzie do budowania konfiguracji. */
  t.check('żaden wytwór nie jest oznaczony jako gotowy',
    (await wy.evaluate(() => window.__wytworyGotowe())) === 0,
    (await wy.evaluate(() => window.__wytworyGotowe())) + ' oznaczonych');

  /* KONTROLA NEGATYWNA. Gdyby bramka wycinała wszystko bez slugu, cisza
     w konsoli byłaby osiągnięta kosztem samej funkcji z 1.93.0: animacje
     czekające na wejście w zakres przestałyby się dobudowywać. */
  const dobudowane = await wy.evaluate(() => window.__bp('szeroki'));
  t.check('a prawdziwa animacja DALEJ dobudowuje się po zmianie progu',
    dobudowane.tweenow > 0, dobudowane.tweenow + ' tweenów');

  t.check('bez błędów JS przy wytworach', !wy.errors.length,
    wy.errors.join(' | ') || 'brak');
  await wy.close();

  /* Przebudowa nie może dziać się WPROST ze zdarzenia progu: na telefonie
     zmiana rozmiaru bywa skutkiem chowania paska adresu w trakcie przewijania,
     a budowanie animacji w tej samej klatce wkłada pracę dokładnie tam, gdzie
     przeglądarka liczy scroll.

     Strona startuje WĄSKA, więc animacja „od 900" jest pominięta i naprawdę
     jest co budować przy przekroczeniu progu. Fixture patrzy na stan w chwili
     zdarzenia — silnik ma swój nasłuch zarejestrowany wcześniej, więc gdyby
     budował synchronicznie, byłoby już po. */
  const odr = await t.open('anim-breakpoint.html',
    { viewport: { width: 500, height: 600 }, head: presety, settle: 600 });
  await odr.setViewportSize({ width: 1100, height: 600 });
  await odr.waitForTimeout(500);

  t.check('przebudowa nie dzieje się w klatce zdarzenia progu',
    (await odr.evaluate(() => window.__synchronicznie)) === false,
    'w chwili zdarzenia zbudowane: ' + (await odr.evaluate(() => window.__synchronicznie)));
  /* Kontrola: przebudowa MIMO WSZYSTKO nastąpiła — inaczej „nie w tej klatce"
     spełniłoby też całkowite jej wyłączenie. */
  t.check('ale nastąpiła zaraz potem',
    (await odr.evaluate(() => window.__bp('szeroki'))).tweenow > 0,
    (await odr.evaluate(() => window.__bp('szeroki'))).tweenow + ' tweenów');
  await odr.close();

  /* ── Raport kosztu ──────────────────────────────────────────────────────
   *
   * Zgłoszenie „scroll zatrzymuje się na iOS, po wyłączeniu Animatora znika"
   * dało się rozstrzygnąć tylko wyłączaniem modułów po kolei. Raport pokazuje
   * liczby, na których da się oprzeć następny krok.
   */
  t.section('raport kosztu na żądanie');

  const cichy = await t.open('anim-breakpoint.html',
    { viewport: { width: 1000, height: 600 }, head: presety, settle: 600 });
  t.check('bez parametru raportu nie ma',
    !cichy.logs.some((l) => /koszt strony/.test(l)),
    cichy.logs.length + ' logów, żaden to raport');
  await cichy.close();

  /* Kontrola pozytywna: bez niej „nie ma raportu" byłoby spełnione także
     wtedy, gdyby raportu nie było wcale — a wtedy cała ta funkcja to martwy
     kod, o który nikt się nie potknie. */
  const gadatliwy = await t.open('anim-breakpoint.html',
    { viewport: { width: 1000, height: 600 }, head: presety,
      query: 'evk-anim-debug=1', settle: 600 });
  t.check('z parametrem raport JEST',
    gadatliwy.logs.some((l) => /koszt strony/.test(l)),
    gadatliwy.logs.find((l) => /koszt/.test(l)) || 'BRAK');
  await gadatliwy.close();

  /* ── Koszt budowy klonów przy podmianie treści ──────────────────────────
   *
   * Zgłoszone z PageSpeed: „wymuszone przeformatowanie", a w nim `animator.js`
   * jako najdroższa pozycja spośród naszych.
   *
   * Powód: pętla budująca klony czytała styl maski, dokładała do niej klon
   * i czytała następną. Każdy dołożony klon unieważnia styl, więc następny
   * odczyt wymusza jego przeliczenie — tyle razy, ile jest kawałków. Przy
   * podziale na ZNAKI to setki razy na jedną stronę.
   *
   * Zmierzone przez Performance.getMetrics na stronie z 494 klonami:
   * 779 przeliczeń stylu i 70 ms układu przed rozdzieleniem faz, 539 i 22 ms
   * po. Liczba przeliczeń UKŁADU się nie zmienia — zmienia się ich koszt, bo
   * styl nie jest już wtedy brudny.
   *
   * Test mierzy KOLEJNOŚĆ, nie zegar: liczby z metryk zależą od maszyny i od
   * tego, co akurat robi przeglądarka, a przeplot odczytów z zapisami jest
   * faktem binarnym.
   */
  t.section('klony przy podmianie nie przeplatają odczytów z zapisami');

  const kzt = await t.open('anim-swap-koszt.html', { head: presety, settle: 600 });
  const klonow = await kzt.evaluate(() => window.__klony());

  /* Najpierw dowód, że jest CO mierzyć: bez klonów przeplot nie ma prawa
     wystąpić i sprawdzenie niżej przechodziłoby zawsze. */
  t.check('fixture naprawdę buduje dużo klonów', klonow > 30, klonow + ' klonów');

  const przeplot = await kzt.evaluate(() => window.__przeplot());
  /* Garść to praca silnika POZA samą pętlą (podział tekstu, stany początkowe).
     Przeplot rośnie z liczbą kawałków, więc próg oddziela je z zapasem. */
  t.check('odczyt po zapisie zdarza się garść razy, nie raz na kawałek',
    przeplot < klonow / 4,
    przeplot + ' przeplotów przy ' + klonow + ' klonach');

  /* Kontrola pozytywna: klony mają nadal POWSTAĆ i leżeć w maskach. Pomiar
     kolejności byłby spełniony także wtedy, gdyby pętla przestała cokolwiek
     robić. */
  t.check('a każdy klon siedzi w masce',
    await kzt.evaluate(() => Array.prototype.every.call(
      document.querySelectorAll('.evk-anim-swap-klon'),
      function (k) { return /-mask/.test(k.parentNode.className); })),
    'w maskach');
  t.check('bez błędów JS', !kzt.errors.length, kzt.errors.join(' | ') || 'brak');
  await kzt.close();
};
