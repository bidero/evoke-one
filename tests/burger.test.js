/**
 * Burger — element Bricks.
 *
 * Cała racja bytu tego elementu mieści się w jednym zdaniu: **przycisk nie ma
 * własnego stanu**. Gotowe burgery wiążą sobie własny `click` i same
 * przełączają swoją klasę; postawione obok czegoś, co też chce wiedzieć, czy
 * menu jest otwarte, dają jeden stan z dwoma właścicielami — a wtedy wygrywa
 * ten, którego nasłuch zarejestrował się później. Kosztowało to cztery wersje
 * poprawek w menu (1.70.0 → 1.74.0) i za każdym razem objaw był inny.
 *
 * Dlatego najważniejsza sekcja w tym pliku to NIE wygląd kresek, tylko
 * „burger idzie za menu": otwarcie i zamknięcie każdą drogą — kliknięciem,
 * Esc, kliknięciem poza panelem — ma przestawiać przycisk, mimo że przycisk
 * o żadnej z tych dróg nie wie.
 *
 * Druga rzecz, której nie widać na stronie i dlatego jedzie z PHP: znacznik
 * ma być WYLICZANY z liczby kresek w tablicy stylów, a nie wklejany osobno dla
 * każdego stylu. Przy jednym stylu obie drogi wyglądają identycznie — różnica
 * wychodzi dopiero przy dokładaniu następnych, i wtedy jest już za późno.
 */

const { phpOutput } = require('./lib/harness');

const V = { width: 1000, height: 700 };

module.exports = async function (t) {

  // ── Znacznik z tablicy stylów, nie z wklejki ───────────────────────────
  t.section('znacznik powstaje z liczby kresek');

  const php = JSON.parse(phpOutput('burger-controls.php'));

  t.check('styl „krzyżyk" ma trzy kreski w tablicy', php.lines.cross === 3,
    JSON.stringify(php.lines));
  t.check('i tyle samo wychodzi z rendera', php.plainLines === php.lines.cross,
    php.plainLines + ' kresek w znaczniku, ' + php.lines.cross + ' w tablicy');

  /* To samo dla KAŻDEGO stylu, nie tylko domyślnego. Przy jednym sprawdzanym
     pomyłka w nowym wpisie przechodziłaby bez śladu — a przy dokładaniu partii
     stylów to najprawdopodobniejsza pomyłka, jaka może się zdarzyć. */
  const zleKreski = php.styles.filter((k) => php.render[k].kresek !== php.lines[k]);
  t.check('każdy styl renderuje tyle kresek, ile ma w rejestrze', zleKreski.length === 0,
    zleKreski.length ? zleKreski.map((k) => k + ': ' + php.render[k].kresek
      + ' zamiast ' + php.lines[k]).join(', ') : php.styles.length + ' stylów');
  const zlaKlasa = php.styles.filter((k) => !php.render[k].klasa);
  t.check('i niesie klasę swojego stylu', zlaKlasa.length === 0,
    zlaKlasa.join(', ') || 'wszystkie');

  // Obie rodziny są obsadzone — ścieżka dwukreskowa nie była dotąd przejechana
  // ani razu, a to o nią prosiłeś obok trzykreskowych.
  const trzy = php.styles.filter((k) => php.lines[k] === 3);
  const dwie = php.styles.filter((k) => php.lines[k] === 2);
  t.check('są style trzykreskowe i dwukreskowe',
    trzy.length >= 5 && dwie.length >= 4,
    trzy.length + ' trzykreskowych, ' + dwie.length + ' dwukreskowych');
  t.check('i żaden nie ma innej liczby kresek',
    php.styles.every((k) => php.lines[k] === 2 || php.lines[k] === 3),
    JSON.stringify(php.lines));

  // Przycisk, nie div — i to nie jest kosmetyka: `<button>` jest fokusowalny
  // z klawiatury, reaguje na spację i Enter, i to jego znajduje toggleTarget()
  // w obu menu.
  t.check('korzeniem jest <button type="button">',
    /^<button /.test(php.renderPlain) && /type="button"/.test(php.renderPlain),
    php.renderPlain.slice(0, 60));
  // Stan startowy JAWNIE — bez tego czytnik ekranu do pierwszego kliknięcia
  // nie wie, że przycisk cokolwiek rozwija. Ta sama usterka co w 1.69.0.
  t.check('od razu mówi, że jest zamknięty',
    /aria-expanded="false"/.test(php.renderPlain), 'aria-expanded="false"');
  t.check('i ma opis dla czytnika ekranu',
    /aria-label="Menu"/.test(php.renderPlain), 'aria-label="Menu"');

  // Domyślnie NIE przełącza niczego — przy naszym menu każdy inny tryb
  // wróciłby do dwóch właścicieli jednego stanu.
  t.check('domyślnie nie przełącza niczego',
    !/data-evk-burger-self|data-evk-burger-target/.test(php.renderPlain), 'brak atrybutów');
  t.check('tryb „tylko siebie" dojeżdża w atrybut',
    /data-evk-burger-self="1"/.test(php.renderFilled), 'data-evk-burger-self="1"');
  t.check('tryb „wskazany element" niesie selektor i klasy',
    /data-evk-burger-target="#moj-panel"/.test(php.renderTarget)
    && /data-evk-burger-target-class="is-otwarte"/.test(php.renderTarget),
    (php.renderTarget.match(/data-evk-burger-target[^>]*?(?= <|>)/) || ['—'])[0]);

  /* Strona zapisana PRZED zamianą checkboxa na listę trybów. Bez tej ścieżki
     burger z włączonym starym „Sam się przełącza" po cichu przestałby cokolwiek
     robić — i nikt by tego nie zauważył, dopóki nie otworzyłby elementu
     w builderze. */
  t.check('stary checkbox nadal znaczy „tylko siebie"',
    /data-evk-burger-self="1"/.test(php.renderLegacy), 'zapisane strony jadą dalej');

  // Krzywa idzie OSOBNĄ drogą niż reszta ustawień: kontrolka `css` wpisałaby
  // surową nazwę GSAP-a, a nieznana funkcja czasu unieważnia CAŁĄ deklarację
  // `transition` razem z czasem trwania — to gasiło przejścia w offcanvas
  // do 1.61.0.
  t.check('krzywa dojeżdża PRZELICZONA na zapis CSS-a',
    /--evk-burger-ease:cubic-bezier\(/.test(php.renderFilled),
    (php.renderFilled.match(/--evk-burger-ease:[^"]*/) || ['—'])[0]);
  t.check('lista krzywych to wspólna lista wtyczki',
    JSON.stringify(php.easingOptions) === JSON.stringify([''].concat(php.sharedEasings)),
    php.easingOptions.length + ' pozycji');

  // Zapisane strony przeżywają przemianowanie stylu — nieznany wraca
  // do domyślnego zamiast wywalić render.
  t.check('nieznany styl wraca do domyślnego, a nie psuje strony',
    /evk-burger--cross/.test(php.renderBogus) && /evk-burger__line/.test(php.renderBogus),
    'wrócił do „cross"');

  // ── Kreski: zamknięty i otwarty ────────────────────────────────────────
  // Zamknięty: trzy równoległe kreski rozsunięte o odstęp PLUS grubość, żeby
  // „odstęp" znaczył przerwę między nimi, a nie rozstaw środków.
  t.section('kreski składają się w krzyżyk');

  const p = await t.open('burger.html', { viewport: V, query: 'dur=0ms&stroke=2px&gap=7px', settle: 200 });

  t.check('są trzy kreski', (await p.evaluate(() => window.__ile('burger'))) === 3,
    String(await p.evaluate(() => window.__ile('burger'))));

  const g0 = await p.evaluate(() => window.__linia('burger', 0));
  const s0 = await p.evaluate(() => window.__linia('burger', 1));
  const d0 = await p.evaluate(() => window.__linia('burger', 2));

  t.check('zamknięty: wszystkie leżą poziomo',
    g0.kat === 0 && s0.kat === 0 && d0.kat === 0,
    [g0.kat, s0.kat, d0.kat].join('° / ') + '°');
  // 7 px odstępu + 2 px grubości = 9 px w każdą stronę od środka.
  t.check('zamknięty: skrajne rozsunięte o odstęp PLUS grubość',
    g0.ty === -9 && d0.ty === 9, g0.ty + ' / ' + s0.ty + ' / ' + d0.ty + ' px (cel -9 / 0 / 9)');
  t.check('zamknięty: środkowa widoczna', s0.opacity === 1, 'opacity ' + s0.opacity);

  await p.evaluate(() => window.__klik('burger'));
  await p.waitForTimeout(120);

  const g1 = await p.evaluate(() => window.__linia('burger', 0));
  const s1 = await p.evaluate(() => window.__linia('burger', 1));
  const d1 = await p.evaluate(() => window.__linia('burger', 2));

  t.check('otwarty: skrajne krzyżują się pod 45°',
    g1.kat === 45 && d1.kat === -45, g1.kat + '° / ' + d1.kat + '°');
  // Obrót MUSI wychodzić ze środka pudełka, inaczej krzyżyk się rozjeżdża
  // przy każdym innym odstępie — stąd wszystkie kreski leżą na środku,
  // a rozsuwa je dopiero transform.
  t.check('otwarty: i wracają na środek, więc krzyżyk się schodzi',
    g1.ty === 0 && d1.ty === 0, g1.ty + ' / ' + d1.ty + ' px');
  t.check('otwarty: środkowa gaśnie', s1.opacity === 0, 'opacity ' + s1.opacity);
  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  // ── Każdy styl z rejestru NAPRAWDĘ się rusza ───────────────────────────
  // To jest najważniejsze sprawdzenie tej partii, bo pilnuje obietnicy całej
  // architektury: „nowy styl to wiersz w tablicy plus kilka reguł w arkuszu".
  // Wiersz bez reguł renderuje się jako nieruchome kreski — wygląda jak działający
  // burger, tylko nic nie robi. Bez tej pętli nikt by tego nie złapał.
  //
  // Lista stylów idzie Z PHP do fixture'a, więc galeria nie może rozjechać się
  // z rejestrem: dopisanie stylu automatycznie dokłada mu pomiar.
  t.section('każdy zarejestrowany styl zmienia się po otwarciu');

  const gal = await t.open('burger.html', {
    viewport: V, settle: 250,
    query: 'dur=0ms&stroke=2px&gap=7px&styles=' + encodeURIComponent(php.galeria),
  });

  const nieruchome = [];
  const zleLiczby  = [];
  for (const styl of php.styles) {
    const id = 's-' + styl;
    const ile = await gal.evaluate((i) => window.__ile(i), id);
    if (ile !== php.lines[styl]) zleLiczby.push(styl + ': ' + ile);

    const zamk = await gal.evaluate((i) => window.__geo(i), id);
    await gal.evaluate((i) => window.__ustawOtwarty(i, true), id);
    await gal.waitForTimeout(30);
    const otw = await gal.evaluate((i) => window.__geo(i), id);
    await gal.evaluate((i) => window.__ustawOtwarty(i, false), id);

    if (JSON.stringify(zamk) === JSON.stringify(otw)) nieruchome.push(styl);
  }

  t.check('żaden styl nie stoi w miejscu', nieruchome.length === 0,
    nieruchome.length ? 'BEZ RUCHU: ' + nieruchome.join(', ')
                      : php.styles.length + ' stylów, każdy się zmienia');
  t.check('i każdy ma w kadrze tyle kresek, ile deklaruje', zleLiczby.length === 0,
    zleLiczby.join(', ') || 'zgodne z rejestrem');
  t.check('bez błędów JS w galerii', !gal.errors.length, gal.errors.join(' | ') || 'brak');

  // ── Mechanika przedstawicieli ──────────────────────────────────────────
  // Po jednym pomiarze na RODZAJ ruchu. Jedenaście kompletów geometrii to test,
  // którego nikt nie przeczyta; te cztery pokrywają wszystko, co robi coś więcej
  // niż obrót w miejscu.
  t.section('mechanika: skracanie, plus, zsunięcie, daszek');

  // Strzałka — to dla niej kreska musiała przestać być przypięta do OBU
  // krawędzi. Bez poprawki bazy `width` nie robi nic i to sprawdzenie pada.
  await gal.evaluate(() => window.__ustawOtwarty('s-arrow', true));
  await gal.waitForTimeout(30);
  const arr = await gal.evaluate(() => window.__geo('s-arrow'));
  const bok = (await gal.evaluate(() => window.__pole('s-arrow'))).w;
  t.check('strzałka: skrajne kreski są SKRÓCONE do połowy',
    Math.abs(arr[0].szer - bok / 2) <= 1 && Math.abs(arr[2].szer - bok / 2) <= 1,
    arr[0].szer + ' i ' + arr[2].szer + ' px z ' + bok);
  t.check('strzałka: rozchylają się na przeciwne strony',
    arr[0].kat === -45 && arr[2].kat === 45, arr[0].kat + '° / ' + arr[2].kat + '°');
  t.check('strzałka: środkowa zostaje pełnym trzonem',
    arr[1].szer === bok && arr[1].kat === 0, arr[1].szer + ' px, ' + arr[1].kat + '°');

  // KONTROLA NEGATYWNA: styl, który NIE skraca, musi mieć kreski pełnej
  // długości. Bez tej pary „kreska skrócona" przechodziłoby dla wszystkiego.
  await gal.evaluate(() => window.__ustawOtwarty('s-cross', true));
  await gal.waitForTimeout(30);
  const cr = await gal.evaluate(() => window.__geo('s-cross'));
  t.check('krzyżyk NIE skraca kresek — skracanie jest cechą stylu, nie bazy',
    cr[0].szer === bok && cr[2].szer === bok, cr[0].szer + ' px z ' + bok);

  // Plus: jedna kreska znika, dwie zostają prostopadle.
  await gal.evaluate(() => window.__ustawOtwarty('s-plus', true));
  await gal.waitForTimeout(30);
  const pl = await gal.evaluate(() => window.__geo('s-plus'));
  const widoczne = pl.filter((l) => l.opacity > 0);
  t.check('plus: zostają dokładnie dwie widoczne kreski', widoczne.length === 2,
    pl.map((l) => l.opacity).join(' / '));
  t.check('plus: i są do siebie prostopadłe',
    Math.abs(Math.abs(widoczne[0].kat - widoczne[1].kat) - 90) <= 1,
    widoczne.map((l) => l.kat + '°').join(' / '));

  // Zsunięcie i minus: kreski schodzą się w JEDNO miejsce w pionie.
  for (const styl of [ 'stack', 'minus-2' ]) {
    await gal.evaluate((i) => window.__ustawOtwarty(i, true), 's-' + styl);
    await gal.waitForTimeout(30);
    const g = await gal.evaluate((i) => window.__geo(i), 's-' + styl);
    const srodki = g.map((l) => l.srodekY);
    t.check('„' + styl + '": wszystkie kreski w jednej linii',
      Math.max(...srodki) - Math.min(...srodki) <= 1, srodki.join(' / ') + ' px');
  }

  // Daszek: obie skrócone, kąty przeciwne, ostrza w jednym punkcie po prawej.
  await gal.evaluate(() => window.__ustawOtwarty('s-chevron-2', true));
  await gal.waitForTimeout(30);
  const ch = await gal.evaluate(() => window.__geo('s-chevron-2'));
  t.check('daszek: obie kreski skrócone', ch[0].szer < bok && ch[1].szer < bok,
    ch[0].szer + ' / ' + ch[1].szer + ' px z ' + bok);
  t.check('daszek: kąty przeciwne', ch[0].kat === -ch[1].kat && ch[0].kat !== 0,
    ch[0].kat + '° / ' + ch[1].kat + '°');
  t.check('daszek: ostrza schodzą się po prawej',
    Math.abs(ch[0].prawy - ch[1].prawy) <= 2,
    'prawe końce ' + ch[0].prawy + ' i ' + ch[1].prawy + ' px');
  await gal.close();

  // ── Asymetria: kreski różnią się DŁUGOŚCIĄ już w spoczynku ─────────────
  // Pierwsza cecha, której nie dawało się uzyskać żadnym ustawieniem: do 1.78.0
  // każdy styl miał kreski równe przed kliknięciem. Asymetria jest tu cechą
  // stanu ZAMKNIĘTEGO, więc widać ją, zanim ktokolwiek dotknie przycisku.
  t.section('style nierówne mają krótszą kreskę przed otwarciem');

  const as = await t.open('burger.html', {
    viewport: V, settle: 250,
    query: 'dur=0ms&styles=uneven-2:2,uneven:3,cross-2:2',
  });

  const u2 = await as.evaluate(() => window.__geo('s-uneven-2'));
  const u3 = await as.evaluate(() => window.__geo('s-uneven'));
  t.check('dwukreskowy: dolna krótsza od górnej', u2[1].szer < u2[0].szer,
    u2[0].szer + ' i ' + u2[1].szer + ' px');
  t.check('trzykreskowy: środkowa krótsza od skrajnych',
    u3[1].szer < u3[0].szer && u3[1].szer < u3[2].szer,
    u3.map((l) => l.szer).join(' / ') + ' px');

  // KONTROLA NEGATYWNA: styl, który nie jest nierówny, ma kreski RÓWNE.
  // Bez tej pary „krótsza kreska" przechodziłoby dla każdego stylu.
  const rowne = await as.evaluate(() => window.__geo('s-cross-2'));
  t.check('zwykły krzyżyk ma kreski RÓWNE', rowne[0].szer === rowne[1].szer,
    rowne[0].szer + ' i ' + rowne[1].szer + ' px');

  // Po otwarciu asymetria znika — krzyżyk wychodzi z dwóch pełnych kresek.
  await as.evaluate(() => window.__ustawOtwarty('s-uneven-2', true));
  await as.waitForTimeout(60);
  const u2o = await as.evaluate(() => window.__geo('s-uneven-2'));
  t.check('po otwarciu obie wracają do pełnej długości',
    u2o[0].szer === u2o[1].szer && u2o[0].kat === 45 && u2o[1].kat === -45,
    u2o.map((l) => l.szer + 'px/' + l.kat + '°').join(' '));
  await as.close();

  // Kontrolka długości naprawdę steruje proporcją — i rusza WYŁĄCZNIE krótką.
  const kr = await t.open('burger.html', {
    viewport: V, settle: 250, query: 'dur=0ms&short=30%&styles=uneven-2:2',
  });
  const k30 = await kr.evaluate(() => window.__geo('s-uneven-2'));
  t.check('kontrolka skraca krótszą kreskę', k30[1].szer < u2[1].szer,
    'przy 30%: ' + k30[1].szer + ' px, przy domyślnych 60%: ' + u2[1].szer + ' px');
  t.check('a pełnej nie rusza', k30[0].szer === u2[0].szer,
    k30[0].szer + ' px w obu przypadkach');
  await kr.close();

  // Lista stylów czytających tę kontrolkę idzie Z REJESTRU, a nie z ręki.
  // Wpisana ręcznie rozjeżdżałaby się przy każdym nowym stylu asymetrycznym,
  // a objawem byłoby pole schowane akurat tam, gdzie jest potrzebne.
  t.check('warunek widoczności tej kontrolki idzie z rejestru',
    JSON.stringify(php.shortRequired) === JSON.stringify(php.shortStyles),
    php.shortStyles.join(', '));

  // ── Asymetria z KRAWĘDZIĄ ──────────────────────────────────────────────
  // Drugi wymiar asymetrii: nie tylko JAK DŁUGA jest kreska, ale DO KTÓREJ
  // KRAWĘDZI przylega. Bez pomiaru krawędzi te cztery style przechodziłyby
  // jako „nierówne" — krótka kreska wyśrodkowana i krótka kreska dosunięta
  // do prawej mają dokładnie tę samą szerokość.
  t.section('style krawędziowe: krótka kreska trzyma swoją stronę');

  const kw = await t.open('burger.html', {
    viewport: V, settle: 250,
    query: 'dur=0ms&styles=zigzag:3,steps:3,uneven-right-2:2,split-2:2,cross:3',
  });

  const lewa  = (id, i) => kw.evaluate(([a, b]) => window.__lewaKreski(a, b), [id, i]);
  const prawa = (id, i) => kw.evaluate(([a, b]) => window.__prawaKreski(a, b), [id, i]);

  const zz = await kw.evaluate(() => window.__geo('s-zigzag'));
  t.check('zygzak: skrajne krótsze od środkowej',
    zz[0].szer < zz[1].szer && zz[2].szer < zz[1].szer,
    zz.map((l) => l.szer).join(' / ') + ' px');
  t.check('zygzak: górna przylega do LEWEJ i NIE dochodzi do prawej',
    (await lewa('s-zigzag', 0)) === 0 && (await prawa('s-zigzag', 0)) > 0,
    'lewa ' + (await lewa('s-zigzag', 0)) + ' px, prawa ' + (await prawa('s-zigzag', 0)) + ' px');
  t.check('zygzak: dolna przylega do PRAWEJ i NIE dochodzi do lewej',
    (await prawa('s-zigzag', 2)) === 0 && (await lewa('s-zigzag', 2)) > 0,
    'lewa ' + (await lewa('s-zigzag', 2)) + ' px, prawa ' + (await prawa('s-zigzag', 2)) + ' px');

  const sc = await kw.evaluate(() => window.__geo('s-steps'));
  t.check('schodki: trzy MALEJĄCE długości',
    sc[0].szer > sc[1].szer && sc[1].szer > sc[2].szer,
    sc.map((l) => l.szer).join(' / ') + ' px');
  // Średnia liczy się z krótkiej, żeby jedno pole sterowało całą proporcją —
  // stąd musi wypaść dokładnie w połowie drogi między skrajnymi.
  t.check('schodki: środkowa dokładnie w pół drogi',
    Math.abs(sc[1].szer - (sc[0].szer + sc[2].szer) / 2) <= 1,
    sc[1].szer + ' px przy celu ' + ((sc[0].szer + sc[2].szer) / 2) + ' px');
  t.check('schodki: wszystkie trzy zaczynają się przy LEWEJ',
    (await lewa('s-steps', 0)) === 0 && (await lewa('s-steps', 1)) === 0
    && (await lewa('s-steps', 2)) === 0, 'wszystkie 0 px od lewej');

  const ur = await kw.evaluate(() => window.__geo('s-uneven-right-2'));
  t.check('nierówne z prawej: dolna krótsza i dosunięta do PRAWEJ',
    ur[1].szer < ur[0].szer && (await prawa('s-uneven-right-2', 1)) === 0
    && (await lewa('s-uneven-right-2', 1)) > 0,
    ur[0].szer + ' / ' + ur[1].szer + ' px, ' + (await lewa('s-uneven-right-2', 1)) + ' px od lewej');

  const sp = await kw.evaluate(() => window.__geo('s-split-2'));
  t.check('rozstrzelone: OBIE krótkie', sp[0].szer < 44 && sp[1].szer < 44,
    sp[0].szer + ' / ' + sp[1].szer + ' px z 44');
  t.check('rozstrzelone: górna przy lewej, dolna przy prawej',
    (await lewa('s-split-2', 0)) === 0 && (await prawa('s-split-2', 1)) === 0
    && (await prawa('s-split-2', 0)) > 0 && (await lewa('s-split-2', 1)) > 0,
    'górna ' + (await lewa('s-split-2', 0)) + ' px od lewej, dolna '
      + (await prawa('s-split-2', 1)) + ' px od prawej');

  // KONTROLA NEGATYWNA: styl bez wyrównania dotyka OBU krawędzi. Bez tej pary
  // „przylega do prawej" przechodziłoby dla każdej pełnej kreski.
  t.check('zwykły krzyżyk dotyka obu krawędzi naraz',
    (await lewa('s-cross', 0)) === 0 && (await prawa('s-cross', 0)) === 0,
    'lewa i prawa po 0 px');

  // Po otwarciu wszystkie cztery składają krzyżyk z PEŁNYCH kresek — asymetria
  // jest cechą stanu zamkniętego, a nie trwałym kalectwem rysunku.
  for (const styl of [ 'zigzag', 'steps', 'uneven-right-2', 'split-2' ]) {
    await kw.evaluate((i) => window.__ustawOtwarty(i, true), 's-' + styl);
    await kw.waitForTimeout(40);
    const o = await kw.evaluate((i) => window.__geo(i), 's-' + styl);
    const ukos = o.filter((l) => Math.abs(l.kat) === 45);
    t.check('„' + styl + '": po otwarciu krzyżyk z dwóch PEŁNYCH kresek',
      ukos.length === 2 && ukos.every((l) => l.szer === 44)
      && ukos[0].kat === -ukos[1].kat,
      ukos.map((l) => l.szer + 'px/' + l.kat + '°').join(' ') || 'brak ukośnych');
  }
  t.check('bez błędów JS w stylach krawędziowych', !kw.errors.length,
    kw.errors.join(' | ') || 'brak');
  await kw.close();

  /* Dosunięcie do prawej idzie przez `right` + `width` ze zdjętym `left`.
     To NIE jest powrót do tego, co wycięliśmy w 1.77.0 — tamten problem brał
     się z podania `left`, `right` I `width` naraz: układ był nadokreślony,
     a przeglądarka ignorowała wartość od końca linijki pisma, więc przy
     `dir="rtl"` wypadał `left`. Dwie wartości są jednoznaczne w obu
     kierunkach — i właśnie to sprawdzamy, bo to jedyna różnica. */
  t.section('wyrównanie do prawej nie zależy od kierunku pisma');

  for (const kierunek of [ 'ltr', 'rtl' ]) {
    const d = await t.open('burger.html', {
      viewport: V, settle: 250, query: 'dur=0ms&dir=' + kierunek + '&styles=zigzag:3',
    });
    const pr = await d.evaluate(() => window.__prawaKreski('s-zigzag', 2));
    const lw = await d.evaluate(() => window.__lewaKreski('s-zigzag', 2));
    t.check('„' + kierunek + '": dolna kreska zygzaka trzyma PRAWĄ krawędź',
      pr === 0 && lw > 0, 'prawa ' + pr + ' px, lewa ' + lw + ' px');
    await d.close();
  }

  // ── Dwutakty: „po kolei" i „ściągnięcie" ───────────────────────────────
  // Oba widać WYŁĄCZNIE w trakcie ruchu — po jego końcu wyglądają jak krzyżyk.
  // Oba jadą tym samym chwytem co „złożenie": opóźnienie trafia w JEDNĄ pozycję
  // listy, bo pojedyncza wartość w `transition-delay` dotyczy wszystkich
  // właściwości ze skrótu.
  t.section('po kolei i ściągnięcie mają dwa takty');

  const dt = await t.open('burger.html', {
    viewport: V, settle: 250, query: 'dur=400ms&styles=stagger:3,pinch-2:2',
  });

  await dt.evaluate(() => window.__ustawOtwarty('s-stagger', true));
  await dt.waitForTimeout(150);
  const stg = await dt.evaluate(() => window.__geo('s-stagger'));
  t.check('„po kolei": górna już się obraca', stg[0].kat > 5, stg[0].kat + '°');
  t.check('a dolna jeszcze stoi', stg[2].kat === 0, stg[2].kat + '°');

  await dt.evaluate(() => window.__ustawOtwarty('s-pinch-2', true));
  await dt.waitForTimeout(150);
  const pin = await dt.evaluate(() => window.__geo('s-pinch-2'));
  t.check('„ściągnięcie": kreski już się zwężają',
    pin[0].szer < 44 && pin[1].szer < 44, pin[0].szer + ' / ' + pin[1].szer + ' px z 44');
  t.check('a obrót jeszcze nie ruszył', pin[0].kat === 0 && pin[1].kat === 0,
    pin[0].kat + '° / ' + pin[1].kat + '°');

  await dt.waitForTimeout(700);
  const kon = await dt.evaluate(() => window.__geo('s-pinch-2'));
  t.check('na końcu wychodzi krzyżyk', kon[0].kat === 45 && kon[1].kat === -45,
    kon[0].kat + '° / ' + kon[1].kat + '°');
  /* I to jest właśnie ten MNIEJSZY krzyżyk, który odróżnia ten styl od zwykłego
     dwukreskowego. Kreski zostają krótkie, bo przejście CSS prowadzi od jednej
     wartości do drugiej i nie umie „do zera i z powrotem" — gdyby stan końcowy
     miał pełną szerokość, pierwszy takt nie miałby czego animować. */
  t.check('i jest MNIEJSZY — kreski zostają skrócone', kon[0].szer < 44,
    kon[0].szer + ' px z 44');
  await dt.close();

  /* Skrócona kreska trzyma się LEWEJ krawędzi w obu kierunkach pisma.
     To jest prawdziwy powód, dla którego kreska jest przypięta przez
     `left` + `width`, a nie `left` + `right` — i nie ten, który wpisałem
     sobie w plan. Przy trzech podanych wartościach układ jest nadokreślony
     i przeglądarka ignoruje JEDNĄ z nich, więc skracanie działa tak czy tak.
     Ignorowana jest jednak ta od końca linijki pisma: przy `dir="rtl"`
     wypada `left`, a strzałka przykleja się do prawej krawędzi. */
  t.section('skrócone kreski nie zależą od kierunku pisma');

  for (const kierunek of [ 'ltr', 'rtl' ]) {
    const d = await t.open('burger.html', {
      viewport: V, settle: 250, query: 'dur=0ms&dir=' + kierunek + '&styles=arrow:3',
    });
    await d.evaluate(() => window.__ustawOtwarty('s-arrow', true));
    await d.waitForTimeout(60);
    /* Tolerancja, bo prostokąt OBRÓCONEJ kreski wystaje o pół jej grubości
       razy sinus kąta — przy 2 px to 0,7 px w lewo. Dwa piksele zostawiają to
       w spokoju, a wciąż odróżniają przyklejenie do lewej (0) od prawej (100). */
    t.check('„' + kierunek + '": ostrze strzałki przy LEWEJ krawędzi',
      Math.abs(await d.evaluate(() => window.__lewaKreski('s-arrow', 0))) <= 2,
      (await d.evaluate(() => window.__lewaKreski('s-arrow', 0))) + ' px od lewej');
    await d.close();
  }

  // ── Złożenie jest DWUTAKTOWE ───────────────────────────────────────────
  // Najpierw zjazd do środka, dopiero potem obrót. Jednej właściwości
  // `transform` nie da się rozdzielić na dwa takty, więc zjazd idzie przez
  // `top`, a obrót przez `transform` z opóźnieniem. Widać to WYŁĄCZNIE
  // w trakcie przejścia — po jego końcu ten styl wygląda jak krzyżyk.
  t.section('złożenie: najpierw zjazd, dopiero potem obrót');

  const dw = await t.open('burger.html', {
    viewport: V, settle: 250,
    query: 'dur=400ms&stroke=2px&gap=7px&styles=collapse:3',
  });

  const przed = await dw.evaluate(() => window.__geo('s-collapse'));
  await dw.evaluate(() => window.__ustawOtwarty('s-collapse', true));
  await dw.waitForTimeout(150);
  const wpol = await dw.evaluate(() => window.__geo('s-collapse'));

  t.check('w połowie drogi kreski JUŻ zjeżdżają do środka',
    wpol[0].top > przed[0].top && wpol[2].top < przed[2].top,
    przed[0].top + '→' + wpol[0].top + ' px (górna), '
      + przed[2].top + '→' + wpol[2].top + ' px (dolna)');
  t.check('ale obrót jeszcze NIE ruszył', wpol[0].kat === 0 && wpol[2].kat === 0,
    wpol[0].kat + '° / ' + wpol[2].kat + '°');

  await dw.waitForTimeout(700);
  const po = await dw.evaluate(() => window.__geo('s-collapse'));
  t.check('a na końcu jest pełny krzyżyk', po[0].kat === 45 && po[2].kat === -45,
    po[0].kat + '° / ' + po[2].kat + '°');
  await dw.close();

  // ── Obrót po otwarciu ──────────────────────────────────────────────────
  // Mnożnik listy stylów: krzyżyk z obrotem 90° to krzyżyk stojący. Dzięki
  // niemu lista nie puchnie o pozycje różniące się wyłącznie kierunkiem.
  t.section('obrót po otwarciu mnoży style, zamiast dokładać pozycje');

  const ob = await t.open('burger.html', {
    viewport: V, settle: 250, query: 'dur=0ms&rotate=90deg&styles=cross:3',
  });
  t.check('zamknięty stoi prosto',
    (await ob.evaluate(() => window.__obrotPudelka('s-cross'))) === 0,
    (await ob.evaluate(() => window.__obrotPudelka('s-cross'))) + '°');

  await ob.evaluate(() => window.__ustawOtwarty('s-cross', true));
  await ob.waitForTimeout(60);
  t.check('otwarty obraca CAŁY rysunek',
    (await ob.evaluate(() => window.__obrotPudelka('s-cross'))) === 90,
    (await ob.evaluate(() => window.__obrotPudelka('s-cross'))) + '°');
  // Kreski robią swoje niezależnie — obrót pudełka się z nimi składa,
  // a nie zastępuje ich ruchu.
  t.check('a kreski i tak składają krzyżyk w środku',
    (await ob.evaluate(() => window.__geo('s-cross')))[0].kat === 45,
    (await ob.evaluate(() => window.__geo('s-cross')))[0].kat + '°');
  await ob.close();

  // KONTROLA NEGATYWNA: bez ustawienia rysunek stoi prosto także po otwarciu.
  const nob = await t.open('burger.html', {
    viewport: V, settle: 250, query: 'dur=0ms&styles=cross:3',
  });
  await nob.evaluate(() => window.__ustawOtwarty('s-cross', true));
  await nob.waitForTimeout(60);
  t.check('bez ustawienia rysunek się NIE obraca',
    (await nob.evaluate(() => window.__obrotPudelka('s-cross'))) === 0,
    (await nob.evaluate(() => window.__obrotPudelka('s-cross'))) + '°');
  await nob.close();

  // ── Tekst i własne ikony ───────────────────────────────────────────────
  // Kreski przestały być wbudowanym założeniem: dochodzi OŚ ŹRÓDŁA (kreski /
  // własna ikona / nic), a tekst jest osobną osią i dokłada się do każdego
  // z nich. „Nic" plus tekst daje wariant czysto tekstowy — bez ani jednej
  // nowej gałęzi kodu.
  //
  // Znacznik bierzemy Z PRAWDZIWEGO render() i wstawiamy do fixture'a. Ręcznie
  // przepisana kopia zaczęłaby żyć własnym życiem przy pierwszej zmianie nazwy
  // klasy, a test wyglądałby dalej na zielony.
  t.section('tekst i własne ikony przełączają się tą samą klasą');

  const zrzut = encodeURIComponent(
    '<div id="w-tekst">'  + php.wariant.tekst      + '</div>' +
    '<div id="w-jeden">'  + php.wariant.tekstJeden + '</div>' +
    '<div id="w-nad">'    + php.wariant.tekstNad   + '</div>' +
    '<div id="w-ikona">'  + php.wariant.ikona      + '</div>' +
    '<div id="w-brak">'   + php.wariant.brak       + '</div>');

  const tx = await t.open('burger.html', {
    viewport: V, settle: 250, query: 'dur=0ms&size=44px&html=' + zrzut,
  });

  const nap0 = await tx.evaluate(() => window.__napisy('w-tekst'));
  t.check('oba napisy są w znaczniku od początku', nap0.length === 2
    && nap0[0].tekst === 'MENU' && nap0[1].tekst === 'ZAMKNIJ',
    nap0.map((n) => n.tekst).join(' + '));
  t.check('zamknięty pokazuje MENU, a ZAMKNIJ nie',
    nap0[0].widoczny && !nap0[1].widoczny,
    nap0.map((n) => n.tekst + ':' + (n.widoczny ? 'widoczny' : 'ukryty')).join(' '));

  /* Ukryty napis musi wypaść z DRZEWA DOSTĘPNOŚCI, inaczej czytnik ekranu
     przeczyta oba naraz: „MENU ZAMKNIJ". Samo `opacity: 0` tego nie robi —
     element zostaje w drzewie i jest dalej ogłaszany. Dlatego mierzymy
     `checkVisibility()` (które przezroczystości domyślnie nie liczy) i osobno
     sam mechanizm, żeby przy błędzie było widać, czym to zrobiono. */
  t.check('ukryty napis wypada z drzewa przez visibility, nie przez opacity',
    nap0[1].visibility === 'hidden', 'visibility: ' + nap0[1].visibility
      + ', opacity: ' + nap0[1].opacity);
  t.check('a widoczny w drzewie zostaje', nap0[0].visibility === 'visible',
    'visibility: ' + nap0[0].visibility);

  await tx.evaluate(() => window.__ustawOtwarty('w-tekst', true));
  await tx.waitForTimeout(60);
  const nap1 = await tx.evaluate(() => window.__napisy('w-tekst'));
  t.check('po otwarciu zamieniają się rolami',
    !nap1[0].widoczny && nap1[1].widoczny,
    nap1.map((n) => n.tekst + ':' + (n.widoczny ? 'widoczny' : 'ukryty')).join(' '));
  t.check('i nigdy nie widać obu naraz',
    nap0.filter((n) => n.widoczny).length === 1
    && nap1.filter((n) => n.widoczny).length === 1,
    'zamknięty 1, otwarty 1');

  // Jedno wypełnione pole ma dać ten sam napis w obu stanach — inaczej burger
  // z niezmiennym tekstem gubiłby go po otwarciu.
  const jed = await tx.evaluate(() => window.__napisy('w-jeden'));
  t.check('pusty drugi slot znaczy „ten sam napis"',
    jed.length === 2 && jed[0].tekst === 'MENU' && jed[1].tekst === 'MENU',
    jed.map((n) => n.tekst).join(' + '));

  // Pozycja tekstu — klucz z rejestru idzie wprost w klasę-modyfikator, więc
  // lista i arkusz nie mogą się rozjechać.
  t.check('pozycja „nad" układa napis w kolumnie',
    (await tx.evaluate(() => getComputedStyle(document.getElementById('w-nad')).flexDirection))
      === 'column-reverse',
    await tx.evaluate(() => getComputedStyle(document.getElementById('w-nad')).flexDirection));
  t.check('a domyślna „za" zostawia rząd',
    (await tx.evaluate(() => getComputedStyle(document.getElementById('w-tekst')).flexDirection))
      === 'row',
    await tx.evaluate(() => getComputedStyle(document.getElementById('w-tekst')).flexDirection));

  /* Nazwa dla czytnika ekranu. Przy widocznym tekście `aria-label` PRZYKRYŁBY
     to, co widać — a nazwa inna od widocznej etykiety psuje sterowanie głosem
     (WCAG 2.5.3): użytkownik mówi „kliknij MENU", przeglądarka szuka „Menu".
     Obie strony, bo jedna bez drugiej nic nie znaczy: „nie ma aria-label" jest
     prawdą także wtedy, gdy nie ma go nigdzie. */
  t.check('przy tekście aria-label NIE wychodzi',
    (await tx.evaluate(() => window.__ariaLabel('w-tekst'))) === null
    && !/aria-label/.test(php.wariant.tekst),
    String(await tx.evaluate(() => window.__ariaLabel('w-tekst'))));
  t.check('a bez tekstu wychodzi', /aria-label="Menu"/.test(php.renderPlain),
    (php.renderPlain.match(/aria-label="[^"]*"/) || [ '—' ])[0]);
  t.check('warunek widoczności tej kontrolki pilnuje tego samego',
    JSON.stringify(php.ariaRequired) === JSON.stringify([ 'textClosed', '=', '' ]),
    JSON.stringify(php.ariaRequired));

  // Źródło „ikona": kresek nie ma wcale, są dwie ikony i przełącza je ta sama
  // klasa co napisy — czyli znów sam arkusz, bez linijki w burger.js.
  t.check('źródło „ikona" nie rysuje ani jednej kreski',
    (await tx.evaluate(() => window.__ile('w-ikona'))) === 0,
    (await tx.evaluate(() => window.__ile('w-ikona'))) + ' kresek');
  const ik0 = await tx.evaluate(() => window.__ikony('w-ikona'));
  t.check('obie ikony są w znaczniku i niosą swoje klasy',
    ik0.length === 2 && /ti-menu/.test(ik0[0].klasy) && /ti-close/.test(ik0[1].klasy),
    ik0.map((i) => i.klasy).join(' + ') || 'brak');
  t.check('zamknięty pokazuje pierwszą', ik0[0].widoczny && !ik0[1].widoczny,
    ik0.map((i) => i.widoczny).join(' / '));
  await tx.evaluate(() => window.__ustawOtwarty('w-ikona', true));
  await tx.waitForTimeout(60);
  const ik1 = await tx.evaluate(() => window.__ikony('w-ikona'));
  t.check('otwarty pokazuje drugą', !ik1[0].widoczny && ik1[1].widoczny,
    ik1.map((i) => i.widoczny).join(' / '));

  // Źródło „nic" plus tekst — wariant czysto tekstowy.
  t.check('źródło „nic" zostawia sam tekst',
    (await tx.evaluate(() => window.__ile('w-brak'))) === 0
    && (await tx.evaluate(() => window.__ikony('w-brak'))).length === 0
    && (await tx.evaluate(() => window.__napisy('w-brak'))).length === 2,
    'kresek 0, ikon 0, napisów 2');

  /* Rozmiar. Bok pola klikalnego zszedł z przycisku na PUDEŁKO Z KRESKAMI —
     przy tekście obok przycisk musi mierzyć się treścią, a procentowa
     szerokość kresek nie miałaby się wtedy do czego odnieść i pudełko
     zapadłoby się do zera. Przycisk BEZ tekstu ma zostać dokładnie taki jak
     dotąd; pilnuje tego osobne sprawdzenie „pole klikalne ma zadany bok". */
  const polePlain = await tx.evaluate(() => window.__pole('burger'));
  const poleTekst = await tx.evaluate(() => window.__pole('w-tekst'));
  t.check('przycisk bez tekstu zostaje kwadratem o zadanym boku',
    polePlain.w === 44 && polePlain.h === 44, polePlain.w + '×' + polePlain.h + ' px');
  t.check('a z tekstem rośnie wszerz i nie kurczy się w pionie',
    poleTekst.w > polePlain.w && poleTekst.h >= polePlain.h,
    poleTekst.w + '×' + poleTekst.h + ' px');

  /* I to jest właściwe sprawdzenie tego refaktoru, bo szerszy przycisk wyszedłby
     także wtedy, gdyby pudełko z kreskami zapadło się do zera — sam napis jest
     szerszy niż 44 px. Pudełko musi ZOSTAĆ kwadratem o boku pola klikalnego,
     inaczej burger z tekstem gubi rysunek. */
  const pudPlain = await tx.evaluate(() => window.__pudelko('burger'));
  const pudTekst = await tx.evaluate(() => window.__pudelko('w-tekst'));
  const pudIkona = await tx.evaluate(() => window.__pudelko('w-ikona'));
  t.check('pudełko z rysunkiem zostaje kwadratem obok tekstu',
    pudTekst.w === pudPlain.w && pudTekst.h === pudPlain.h && pudTekst.w === 44,
    'bez tekstu ' + pudPlain.w + '×' + pudPlain.h + ', z tekstem '
      + pudTekst.w + '×' + pudTekst.h + ' px');
  // Ikona dostaje to samo pudełko co kreski, więc zamiana źródła nie przesuwa
  // niczego, co stoi obok przycisku.
  t.check('a pudełko własnej ikony ma ten sam bok',
    pudIkona.w === pudPlain.w && pudIkona.h === pudPlain.h,
    pudIkona.w + '×' + pudIkona.h + ' px');
  t.check('bez błędów JS przy tekście i ikonach', !tx.errors.length,
    tx.errors.join(' | ') || 'brak');
  await tx.close();

  // ── Wypełnienie własnej ikony ──────────────────────────────────────────
  // Usterka z 1.79.0: `fill: currentColor` bez warunku KASOWAŁO `fill="none"`
  // z korzenia `<svg>`, bo atrybut prezentacyjny przegrywa z każdą regułą
  // arkusza — a wypełnienie dziedziczyło się w dół na wszystkie kształty.
  // Ikona rysowana obrysem zalewała się na płasko i wyglądało to jak
  // nieusuwalne tło pod ikoną.
  //
  // Widać było głównie na ikonie OTWARTEJ i to nie przypadek: hamburger
  // z trzech `<line>` nie ma pola, więc wypełnienie nic z nim nie robiło,
  // a X w kółku owszem.
  t.section('własna ikona: obrys zostaje obrysem, ale kolor działa dalej');

  const ik = await t.open('burger.html', {
    viewport: V, settle: 200,
    query: 'dur=0ms&color=' + encodeURIComponent('rgb(255, 0, 0)') +
           '&coloropen=' + encodeURIComponent('rgb(0, 128, 255)'),
  });

  const obrys = await ik.evaluate(() => window.__ksztalt('i-obrys'));
  t.check('ikona obrysowa NIE dostaje wypełnienia', obrys.fill === 'none',
    'fill: ' + obrys.fill);
  /* Druga połowa tej samej poprawki i ta ważniejsza: dałoby się uciszyć objaw
     kasując regułę `fill` w całości, tylko wtedy po cichu przestałyby działać
     kontrolki koloru. Obrys idzie za `color`, bo ikony piszą
     `stroke="currentColor"`. */
  t.check('ale obrys idzie za kolorem z kontrolki',
    obrys.stroke === 'rgb(255, 0, 0)', 'stroke: ' + obrys.stroke);

  const pelna = await ik.evaluate(() => window.__ksztalt('i-pelna'));
  t.check('ikona pełna dalej dostaje kolor kontrolki',
    pelna.fill === 'rgb(255, 0, 0)', 'fill: ' + pelna.fill);
  /* KONTROLA NEGATYWNA dla zbyt szerokiej poprawki: przy `:not([fill])` zamiast
     `:not([fill="none"])` ikona z kolorem wpisanym na sztywno przestałaby
     słuchać kontrolek — czyli regresja wobec 1.79.0 wprowadzona przy okazji
     naprawiania czegoś innego. */
  const sztywna = await ik.evaluate(() => window.__ksztalt('i-sztywna'));
  t.check('a ikona z kolorem wpisanym w plik też go słucha',
    sztywna.fill === 'rgb(255, 0, 0)', 'fill: ' + sztywna.fill);

  // Kolor po otwarciu — na ikonie widocznej w tym stanie, czyli tej „otwartej".
  await ik.evaluate(() => window.__ustawOtwarty('ikony', true));
  await ik.waitForTimeout(60);
  t.check('po otwarciu ikona bierze kolor po otwarciu',
    (await ik.evaluate(() => window.__ksztalt('i-pelna'))).fill === 'rgb(0, 128, 255)',
    (await ik.evaluate(() => window.__ksztalt('i-pelna'))).fill);
  t.check('bez błędów JS przy ikonach SVG', !ik.errors.length,
    ik.errors.join(' | ') || 'brak');
  await ik.close();

  // ── Wewnętrzny odstęp napisu ───────────────────────────────────────────
  // Padding korzenia daje Bricks natywnie, ale odsuwa napis RAZEM z rysunkiem —
  // więc nie da się nim ustawić jednego względem drugiego. Ta kontrolka celuje
  // w sam slot napisu i tylko dlatego istnieje.
  t.section('padding napisu odsuwa sam napis, nie cały przycisk');

  t.check('kontrolka celuje w SLOT NAPISU, a nie w korzeń',
    php.textPaddingType === 'dimensions'
    && php.textPaddingCss.property === 'padding'
    && php.textPaddingCss.selector === '.evk-burger__text',
    JSON.stringify(php.textPaddingCss));

  const bezPad = await t.open('burger.html', {
    viewport: V, settle: 200, query: 'dur=0ms&size=44px&html=' + zrzut,
  });
  const pad0 = await bezPad.evaluate(() => window.__slotTekstu('w-tekst'));
  const polePrzed = await bezPad.evaluate(() => window.__pole('w-tekst'));
  await bezPad.close();

  // Nierówna góra i dół — to jest ten przypadek, dla którego kontrolka powstała.
  const gora = await t.open('burger.html', {
    viewport: V, settle: 200,
    query: 'dur=0ms&size=44px&textpad=' + encodeURIComponent('8px 0 0 0') + '&html=' + zrzut,
  });
  const pad1 = await gora.evaluate(() => window.__slotTekstu('w-tekst'));
  t.check('padding rozpycha slot napisu', pad1.h === pad0.h + 8,
    pad0.h + ' → ' + pad1.h + ' px');
  /* Przesunięcie o POŁOWĘ różnicy, bo przycisk środkuje pudełko napisu:
     środek treści wypada na `środek + (górny − dolny) / 2`. To nie jest
     usterka, tylko rzecz, którą trzeba wiedzieć przy strojeniu — i dlatego
     stoi w opisie kontrolki. */
  t.check('i przesuwa napis o POŁOWĘ różnicy góra-dół',
    Math.abs(pad1.srodek - (pad0.srodek + 4)) <= 1,
    pad0.srodek + ' → ' + pad1.srodek + ' px od środka (cel ' + (pad0.srodek + 4) + ')');
  await gora.close();

  // KONTROLA NEGATYWNA: równy padding rozpycha, ale NIE przesuwa. Bez tej pary
  // „padding przesuwa napis" przechodziłoby dla dowolnej zmiany rozmiaru.
  const rowny = await t.open('burger.html', {
    viewport: V, settle: 200,
    query: 'dur=0ms&size=44px&textpad=8px&html=' + zrzut,
  });
  const pad2 = await rowny.evaluate(() => window.__slotTekstu('w-tekst'));
  const polePo = await rowny.evaluate(() => window.__pole('w-tekst'));
  t.check('równy padding rozpycha, ale NIE przesuwa', pad2.srodek === pad0.srodek,
    pad0.srodek + ' → ' + pad2.srodek + ' px od środka');
  t.check('a przycisk rośnie razem z napisem', polePo.w === polePrzed.w + 16,
    polePrzed.w + ' → ' + polePo.w + ' px');
  await rowny.close();

  // ── Wszystko konfigurowalne ────────────────────────────────────────────
  // Zmienne CSS, a nie wartości wpisane w reguły — tylko dzięki temu Bricks
  // może je ustawić osobno na breakpoincie.
  t.section('rozmiary i kolory naprawdę sterują rysunkiem');

  const c = await t.open('burger.html', {
    viewport: V, settle: 200,
    query: 'dur=0ms&size=64px&stroke=4px&gap=10px' +
           '&color=' + encodeURIComponent('rgb(255, 0, 0)') +
           '&coloropen=' + encodeURIComponent('rgb(0, 128, 255)'),
  });

  const pole = await p.errors.length ? null : await c.evaluate(() => window.__pole('burger'));
  t.check('pole klikalne ma zadany bok', pole.w === 64 && pole.h === 64,
    pole.w + '×' + pole.h + ' px');

  const cg0 = await c.evaluate(() => window.__linia('burger', 0));
  t.check('grubość kreski idzie za ustawieniem', cg0.h === 4, cg0.h + ' px');
  // 10 px odstępu + 4 px grubości = 14 px. Gdyby odstęp liczył się od środków,
  // wyszłoby 10 — i przy grubszych kreskach przerwa by znikała.
  t.check('odstęp liczy się od KRAWĘDZI, nie od środków', cg0.ty === -14,
    cg0.ty + ' px (cel -14)');
  t.check('kolor przed otwarciem', cg0.kolor === 'rgb(255, 0, 0)', cg0.kolor);

  await c.evaluate(() => window.__klik('burger'));
  await c.waitForTimeout(120);
  t.check('kolor po otwarciu jest INNY',
    (await c.evaluate(() => window.__linia('burger', 0))).kolor === 'rgb(0, 128, 255)',
    (await c.evaluate(() => window.__linia('burger', 0))).kolor);
  await c.close();

  // KONTROLA NEGATYWNA: nieustawiony kolor po otwarciu znaczy „ten sam co
  // przed", a nie „czarny". Bez tej pary „kolor się zmienia" przechodziłoby
  // też dla wartości domyślnej wziętej z sufitu.
  const nc = await t.open('burger.html', {
    viewport: V, settle: 200,
    query: 'dur=0ms&color=' + encodeURIComponent('rgb(255, 0, 0)'),
  });
  await nc.evaluate(() => window.__klik('burger'));
  await nc.waitForTimeout(120);
  t.check('bez ustawienia kolor po otwarciu zostaje TEN SAM',
    (await nc.evaluate(() => window.__linia('burger', 0))).kolor === 'rgb(255, 0, 0)',
    (await nc.evaluate(() => window.__linia('burger', 0))).kolor);
  await nc.close();

  // ── Burger idzie za MENU ───────────────────────────────────────────────
  // Sedno całego elementu. Przycisk nie wie nic o Esc ani o kliknięciu poza
  // panelem — a mimo to wraca do kresek, bo stan wystawia menu.
  t.section('burger idzie za menu, także gdy zamyka je co innego');

  const m = await t.open('burger.html', { viewport: V, query: 'dur=0ms', settle: 300 });

  t.check('na start zamknięty i tak mówi',
    !(await m.evaluate(() => window.__brx('burger')))
    && (await m.evaluate(() => window.__aria('burger'))) === 'false',
    'aria ' + (await m.evaluate(() => window.__aria('burger'))));

  await m.evaluate(() => window.__klik('burger'));
  await m.waitForTimeout(300);
  t.check('kliknięcie otwiera menu', await m.evaluate(() => window.__menuOtwarte()), 'otwarte');
  t.check('i przestawia burgera', await m.evaluate(() => window.__brx('burger')),
    'brx-open');
  t.check('kreski są w krzyżyku',
    (await m.evaluate(() => window.__linia('burger', 0))).kat === 45,
    (await m.evaluate(() => window.__linia('burger', 0))).kat + '°');

  // Esc — droga, o której burger nie wie NIC.
  await m.evaluate(() => window.__key('Escape'));
  await m.waitForTimeout(300);
  t.check('Esc zamyka menu', !(await m.evaluate(() => window.__menuOtwarte())), 'zamknięte');
  t.check('i burger sam wraca do kresek',
    !(await m.evaluate(() => window.__brx('burger')))
    && (await m.evaluate(() => window.__linia('burger', 0))).kat === 0,
    'kąt ' + (await m.evaluate(() => window.__linia('burger', 0))).kat + '°');
  t.check('aria też wróciło', (await m.evaluate(() => window.__aria('burger'))) === 'false',
    String(await m.evaluate(() => window.__aria('burger'))));

  // Druga taka droga: kliknięcie poza panelem.
  await m.evaluate(() => window.__klik('burger'));
  await m.waitForTimeout(300);
  await m.evaluate(() => document.querySelector('.long').click());
  await m.waitForTimeout(300);
  t.check('klik poza panelem też przestawia burgera',
    !(await m.evaluate(() => window.__brx('burger'))),
    (await m.evaluate(() => window.__linia('burger', 0))).kat + '°');
  t.check('bez błędów JS przy spięciu z menu', !m.errors.length,
    m.errors.join(' | ') || 'brak');
  await m.close();

  // ── Tryb samodzielny ───────────────────────────────────────────────────
  // Dla użycia BEZ naszego menu. Domyślnie wyłączony, bo przy menu
  // przywracałby dwóch właścicieli jednego stanu.
  t.section('tryb samodzielny przełącza się sam');

  const s = await t.open('burger.html', { viewport: V, query: 'dur=0ms', settle: 200 });

  t.check('na start zamknięty', !(await s.evaluate(() => window.__brx('sam'))), 'zamknięty');
  await s.evaluate(() => window.__klik('sam'));
  await s.waitForTimeout(120);
  t.check('klik otwiera', await s.evaluate(() => window.__brx('sam'))
    && (await s.evaluate(() => window.__aria('sam'))) === 'true', 'otwarty');
  await s.evaluate(() => window.__klik('sam'));
  await s.waitForTimeout(120);
  t.check('drugi klik zamyka', !(await s.evaluate(() => window.__brx('sam')))
    && (await s.evaluate(() => window.__aria('sam'))) === 'false', 'zamknięty');

  /* KONTROLA NEGATYWNA i zarazem sedno domyślnego trybu: burger BEZ tej opcji
     nie przełącza się sam z siebie. Gdyby to robił, przy naszym menu stan
     miałby dwóch właścicieli — dokładnie problem, dla którego to powstało.

     Mierzymy na burgerze NICZYIM — bez trybu samodzielnego i bez menu, które
     by go pilnowało. Pierwsze podejście brało do tego przełącznik menu i było
     bez sensu: ten przestawia się przez menu, więc pomiar mówił o czymś innym,
     niż deklarował. */
  t.check('burger niczyj na start jest zamknięty',
    !(await s.evaluate(() => window.__brx('niczyj'))), 'zamknięty');
  await s.evaluate(() => window.__klik('niczyj'));
  await s.waitForTimeout(120);
  t.check('bez tej opcji klik NIE przestawia burgera',
    !(await s.evaluate(() => window.__brx('niczyj')))
    && (await s.evaluate(() => window.__aria('niczyj'))) === 'false',
    'bez zmiany — stan należy do menu');
  await s.close();

  // ── Tryb „wskazany element" ────────────────────────────────────────────
  // Dla cudzych rzeczy: kliknięcie nakłada CELOWI klasę `brx-open`, tak jak
  // robi to przełącznik Bricksa. Ale jest tu druga połowa, bez której to by
  // było tylko przepisywanie cudzego pomysłu razem z jego usterką: burger
  // IDZIE ZA CELEM. Gdyby tylko nakładał klasę, a swój wygląd trzymał osobno,
  // zamknięcie panelu czymkolwiek innym zostawiłoby krzyżyk na przycisku —
  // czyli dokładnie to, co przez cztery wersje naprawialiśmy w menu.
  t.section('burger steruje cudzym elementem — i wraca za nim');

  const g = await t.open('burger.html', { viewport: V, query: 'dur=0ms', settle: 250 });

  t.check('na start cel jest zamknięty',
    !(await g.evaluate(() => window.__cel())).brx,
    (await g.evaluate(() => window.__cel())).klasy || '(bez klas)');
  // Czym ten przycisk steruje — dla czytnika ekranu.
  t.check('burger mówi, czym steruje',
    (await g.evaluate(() => window.__ariaControls('celowy'))) === 'cel',
    String(await g.evaluate(() => window.__ariaControls('celowy'))));

  await g.evaluate(() => window.__klik('celowy'));
  await g.waitForTimeout(120);
  const cel1 = await g.evaluate(() => window.__cel());
  t.check('kliknięcie nakłada celowi klasę Bricksa', cel1.brx, cel1.klasy);
  // Dodatkowa klasa z kontrolki — gdy cudzy panel otwiera się na innej.
  t.check('i dodatkową klasę z kontrolki', /moja-klasa/.test(cel1.klasy), cel1.klasy);
  t.check('a burger pokazuje krzyżyk',
    (await g.evaluate(() => window.__linia('celowy', 0))).kat === 45
    && (await g.evaluate(() => window.__aria('celowy'))) === 'true',
    (await g.evaluate(() => window.__linia('celowy', 0))).kat + '°, aria '
      + (await g.evaluate(() => window.__aria('celowy'))));

  await g.evaluate(() => window.__klik('celowy'));
  await g.waitForTimeout(120);
  t.check('drugie kliknięcie zdejmuje klasy z celu',
    !(await g.evaluate(() => window.__cel())).brx
    && !/moja-klasa/.test((await g.evaluate(() => window.__cel())).klasy),
    (await g.evaluate(() => window.__cel())).klasy || '(bez klas)');

  /* SEDNO tego trybu: cel zamyka CO INNEGO — tu wprost z konsoli, na stronie
     byłby to własny skrypt, klawisz albo przycisk „zamknij" w środku panelu.
     Burger nie dostaje o tym żadnego sygnału, a mimo to ma wrócić do kresek. */
  await g.evaluate(() => window.__klik('celowy'));
  await g.waitForTimeout(120);
  t.check('cel znów otwarty', (await g.evaluate(() => window.__cel())).brx, 'otwarty');

  await g.evaluate(() => window.__zamknijCel());
  await g.waitForTimeout(120);
  t.check('zamknięcie celu CZYMŚ INNYM też wraca do kresek',
    (await g.evaluate(() => window.__linia('celowy', 0))).kat === 0
    && !(await g.evaluate(() => window.__brx('celowy'))),
    'kąt ' + (await g.evaluate(() => window.__linia('celowy', 0))).kat + '°');
  t.check('i aria za tym idzie',
    (await g.evaluate(() => window.__aria('celowy'))) === 'false',
    String(await g.evaluate(() => window.__aria('celowy'))));

  // Selektor wskazujący w próżnię nie może wywalić strony — najczęstsza
  // pomyłka przy wpisywaniu selektora z ręki.
  t.check('cel, którego nie ma, nie wywala strony', !g.errors.length,
    g.errors.join(' | ') || 'brak błędów');
  await g.evaluate(() => window.__klik('pusty'));
  await g.waitForTimeout(80);
  t.check('a kliknięcie w taki burger nic nie psuje',
    !g.errors.length && !(await g.evaluate(() => window.__brx('pusty'))),
    g.errors.join(' | ') || 'bez zmiany');
  await g.close();

  // ── Redukcja ruchu ─────────────────────────────────────────────────────
  // Kreski PRZESKAKUJĄ, ale nadal pokazują stan. Burger, który przy otwartym
  // menu wygląda jak przy zamkniętym, nie mówi nic i jest gorszy niż burger
  // bez animacji.
  t.section('redukcja ruchu — bez ruchu, ale ze stanem');

  const r = await t.open('burger.html', { viewport: V, query: 'dur=400ms', reduce: true, settle: 200 });
  t.check('przejście wyłączone',
    (await r.evaluate(() => window.__linia('burger', 0))).czas === '0s',
    (await r.evaluate(() => window.__linia('burger', 0))).czas);

  await r.evaluate(() => window.__klik('burger'));
  await r.waitForTimeout(120);
  t.check('a krzyżyk i tak się składa',
    (await r.evaluate(() => window.__linia('burger', 0))).kat === 45,
    (await r.evaluate(() => window.__linia('burger', 0))).kat + '°');
  await r.close();

  // KONTROLA NEGATYWNA. „Czas 0s" jest prawdą także wtedy, gdy arkusz w ogóle
  // się nie wczytał — bez tej pary nie da się tego odróżnić.
  const nr = await t.open('burger.html', { viewport: V, query: 'dur=400ms', settle: 200 });
  t.check('bez redukcji przejście ma zadany czas',
    (await nr.evaluate(() => window.__linia('burger', 0))).czas === '0.4s',
    (await nr.evaluate(() => window.__linia('burger', 0))).czas);
  await nr.close();
};
