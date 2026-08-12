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
