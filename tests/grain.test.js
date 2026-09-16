/**
 * Element „Grain" — ziarno filmowe z shadera na całe okno.
 *
 * Powstał ze zgłoszenia przy elemencie fali: „dodatkowy element Bricks tylko
 * z ziarnem. Dodany na stronę wyświetla ziarno z shaderem na całym oknie
 * przeglądarki. Dobrze by było, żeby był przewijany z treścią".
 *
 * MIERZYMY ZŁOŻONY OBRAZ, NIE BUFOR WEBGL-a. `readPixels` czyta bufor już
 * wyczyszczony po złożeniu klatki (`preserveDrawingBuffer` domyślnie fałsz),
 * więc pierwsza wersja tej sondy pokazywała ZERA we wszystkich scenariuszach —
 * także tam, gdzie ziarno było widać gołym okiem. Zrzut pokazuje to, co
 * naprawdę widzi odwiedzający.
 *
 * WSZYSTKIE PROGI POWSTAŁY PO OBEJRZENIU LICZB, nie przed. Zmierzone na wycinku
 * gołego tła (bez tekstu), okno 900×600:
 *
 *     bez elementu      │ szorstkość  0,00 │ jasność 16,0
 *     moc 0,08          │ szorstkość  6,28 │ jasność 19,6
 *     moc 0,50          │ szorstkość 39,9  │ jasność 42,6
 *     przewinięcie ×1   │ 55 105 z 66 000 pikseli zmienionych
 *     przewinięcie ×0   │      0 z 66 000
 */

const { pikseleZPng } = require('./lib/harness');

/* Wycinek GOŁEGO TŁA — poniżej tekstu i poza nim. Pomiar na całej stronie
   mieszałby ziarno z krawędziami liter, a te mają własną szorstkość. */
const KLIP = { x: 0, y: 220, width: 400, height: 220 };
const OKNO = { width: 900, height: 600 };

/** Średnia różnica między sąsiadami. Gładkie tło daje zero, ziarno — więcej. */
function szorstkosc(buf) {
  const { szer, wys, kanaly, dane } = pikseleZPng(buf);
  let suma = 0, n = 0;
  for (let y = 0; y < wys; y++) {
    for (let x = 0; x < szer - 1; x++) {
      const p = (y * szer + x) * kanaly;
      suma += Math.abs(dane[p] - dane[p + kanaly]);
      n++;
    }
  }
  return n ? Math.round((suma / n) * 100) / 100 : null;
}

/** Ile pikseli różni się między dwoma zrzutami tego samego wycinka. */
function roznica(a, b) {
  const A = pikseleZPng(a), B = pikseleZPng(b);
  if (A.dane.length !== B.dane.length) return -1;
  let n = 0;
  for (let i = 0; i < A.dane.length; i += 4) if (A.dane[i] !== B.dane[i]) n++;
  return n;
}

module.exports = async function (t) {

  // ── Skrypt elementu jest poprawnym JavaScriptem ─────────────────────────
  /* Shader siedzi w tablicy łańcuchów w zwykłym pliku .js — i to jest ŚWIADOMY
     wybór wobec fali, która drukuje swój moduł z PHP-a. Tam odwrotny apostrof
     w komentarzu dwa razy wywrócił całość (patrz CLAUDE.md). Tu pułapki nie ma,
     ale parser i tak odpowiada w milisekundach, więc niech pilnuje. */
  t.section('skrypt elementu parsuje się jako JavaScript');

  const { execFileSync } = require('child_process');
  const path = require('path');
  const { ROOT } = require('./lib/harness');
  const plikJs = path.join(ROOT, 'includes/bricks-elements/evoke-grain/assets/grain.js');

  let parsuje = true, powod = 'bez zastrzeżeń';
  try {
    execFileSync(process.execPath, ['--check', plikJs], { stdio: 'pipe' });
  } catch (e) {
    parsuje = false;
    powod = String(e.stderr || e.message).split('\n').slice(0, 2).join(' ');
  }
  t.check('grain.js parsuje się', parsuje, powod);

  // ── Kontrolki piszą właściwe atrybuty ───────────────────────────────────
  /* Ustawienia jadą do skryptu ATRYBUTAMI DANYCH. Pomyłka w nazwie daje pole,
     które zapisuje się w builderze poprawnie i nie zmienia na stronie niczego —
     a z przeglądarki nieustawiona kontrolka i kontrolka pisząca nie tam, gdzie
     trzeba, wyglądają identycznie. */
  t.section('kontrolki dojeżdżają na korzeń atrybutami');

  const { phpOutput } = require('./lib/harness');
  const domyslne = JSON.parse(phpOutput('grain-cfg.php', '{}'));
  const wlasne = JSON.parse(phpOutput('grain-cfg.php',
    JSON.stringify(JSON.stringify({
      intensywnosc: 0.3, przesiew: 'stop', mnoznik_scrolla: 2.5,
      warstwa: 5, auto_jakosc: 'nie',
    }))));

  t.check('domyślna moc ziarna trafia na korzeń',
    domyslne.atrybuty['data-intensywnosc'] === '0.08', domyslne.atrybuty['data-intensywnosc']);
  t.check('ustawiona moc też', wlasne.atrybuty['data-intensywnosc'] === '0.3',
    wlasne.atrybuty['data-intensywnosc']);
  t.check('przesiew', wlasne.atrybuty['data-przesiew'] === 'stop', wlasne.atrybuty['data-przesiew']);
  t.check('mnożnik przewijania', wlasne.atrybuty['data-mnoznik'] === '2.5',
    wlasne.atrybuty['data-mnoznik']);
  t.check('warstwa', wlasne.atrybuty['data-warstwa'] === '5', wlasne.atrybuty['data-warstwa']);
  /* `auto_jakosc: 'nie'` wyżej to ZAPIS PO STAREJ LIŚCIE WYBORU, celowo
     zostawiony po zamianie kontrolki na przełącznik w 1.200.0. Gałąź jedzie
     aktualizatorem na żywe strony, więc to jest jedyne miejsce, które pilnuje,
     że komuś wyłączony automat nie wrócił włączony. */
  t.check('stary zapis „nie" dalej wyłącza automat',
    wlasne.atrybuty['data-auto-jakosc'] === '0', wlasne.atrybuty['data-auto-jakosc']);
  /* Żadna kontrolka nie jest suwakiem — w całej wtyczce nie ma ani jednej
     kontrolki typu `slider`, a wartości liczbowe wpisuje się w pole. */
  t.check('wartości liczbowe są polami, nie suwakami',
    domyslne.typy.intensywnosc === 'number' && domyslne.typy.mnoznik_scrolla === 'number',
    domyslne.typy.intensywnosc + ' / ' + domyslne.typy.mnoznik_scrolla);

  // ── Przełącznik przewijania ─────────────────────────────────────────────
  /* ZGŁOSZONE Z UŻYCIA: „Dlaczego używamy pól tekstowych tam gdzie może być
     bricksowy toggle? Przewijanie z treścią, czy np Automat jakości?".
     Pierwszą decyzją jest „czy w ogóle", a dopiero drugą „jak mocno" — więc
     przełącznik, a pod nim pole siły. */
  t.check('przewijanie i automat jakości to przełączniki',
    domyslne.typy.przewijaj === 'checkbox' && domyslne.typy.auto_jakosc === 'checkbox',
    domyslne.typy.przewijaj + ' / ' + domyslne.typy.auto_jakosc);

  /* Wyłączony przełącznik oddaje ZERO, a nie osobny atrybut — zero już wcześniej
     znaczyło w skrypcie „przyklejone do ekranu", więc grain.js nie wymagał
     zmiany. Gdyby oddawał cokolwiek innego, ziarno przewijałoby się mimo
     wyłączenia i z panelu nie dałoby się tego zobaczyć. */
  const bezPrzewijania = JSON.parse(phpOutput('grain-cfg.php',
    JSON.stringify(JSON.stringify({ przewijaj: false, mnoznik_scrolla: 2.5 }))));
  t.check('wyłączony przełącznik zeruje mnożnik mimo wpisanej siły',
    bezPrzewijania.atrybuty['data-mnoznik'] === '0', bezPrzewijania.atrybuty['data-mnoznik']);

  /* Kontrola negatywna: bez niej „zero" byłoby nie do odróżnienia od pola,
     które nigdy nie przepuszcza wpisanej wartości. */
  const zPrzewijaniem = JSON.parse(phpOutput('grain-cfg.php',
    JSON.stringify(JSON.stringify({ przewijaj: true, mnoznik_scrolla: 2.5 }))));
  t.check('włączony przepuszcza wpisaną siłę',
    zPrzewijaniem.atrybuty['data-mnoznik'] === '2.5', zPrzewijaniem.atrybuty['data-mnoznik']);

  /* Element zapisany PRZED 1.200.0 nie ma klucza `przewijaj` — i ma dalej
     przewijać, bo przewijał. To jest ta połowa evk_flaga(), której nie widać
     w żadnym innym sprawdzeniu ziarna. */
  t.check('element sprzed przełącznika przewija dalej',
    domyslne.atrybuty['data-mnoznik'] === '1', domyslne.atrybuty['data-mnoznik']);

  /* Bricks nie obsługuje ŁAŃCUCHÓW w `required` — pilnuje tego
     bricks-required.test.js dla całej wtyczki. Tu sprawdzamy drugą połowę:
     że bramka w ogóle wskazuje na przełącznik, a nie na nieistniejące pole. */
  t.check('siła przewijania jest pod bramką przełącznika',
    JSON.stringify(domyslne.bramki.mnoznik_scrolla) === JSON.stringify(['przewijaj', '=', true]),
    JSON.stringify(domyslne.bramki.mnoznik_scrolla));

  // ── Zasięg: co render() wypisuje na korzeniu ────────────────────────────
  /* Zachowanie w przeglądarce sprawdza sekcja o masce niżej. Tu chodzi
     wyłącznie o parę „kontrolka ↔ nazwa atrybutu": pomyłka w niej daje pole,
     które zapisuje się w builderze poprawnie i nie zmienia na stronie nic. */
  t.check('domyślnie ziarno jest na całym oknie',
    domyslne.atrybuty['data-zakres'] === 'strona', domyslne.atrybuty['data-zakres']);
  t.check('zasięg i wtopienie są bramkowane wyborem zasięgu',
    JSON.stringify(domyslne.bramki.sekcja_selektor) === JSON.stringify(['zakres', '=', 'sekcja'])
      && JSON.stringify(domyslne.bramki.wtopienie) === JSON.stringify(['zakres', '=', 'sekcja']),
    JSON.stringify(domyslne.bramki.sekcja_selektor) + ' / ' + JSON.stringify(domyslne.bramki.wtopienie));

  const wSekcji = JSON.parse(phpOutput('grain-cfg.php',
    JSON.stringify(JSON.stringify({ zakres: 'sekcja', sekcja_selektor: '.hero', wtopienie: 40 }))));
  t.check('tryb sekcji dojeżdża z selektorem i wtopieniem',
    wSekcji.atrybuty['data-zakres'] === 'sekcja'
      && wSekcji.atrybuty['data-sekcja'] === '.hero'
      && wSekcji.atrybuty['data-wtopienie'] === '40',
    [wSekcji.atrybuty['data-zakres'], wSekcji.atrybuty['data-sekcja'],
     wSekcji.atrybuty['data-wtopienie']].join(' / '));

  /* PUSTY SELEKTOR NIE WYPISUJE ATRYBUTU — brak i pusty znaczą w skrypcie to
     samo („weź rodzica"), więc pusty byłby wyłącznie szumem w kodzie strony. */
  const rodzic = JSON.parse(phpOutput('grain-cfg.php',
    JSON.stringify(JSON.stringify({ zakres: 'sekcja' }))));
  t.check('pusty selektor nie zostawia pustego atrybutu',
    !('data-sekcja' in rodzic.atrybuty), JSON.stringify(rodzic.atrybuty['data-sekcja']));

  /* SELEKTOR JEST PIERWSZYM POLEM TEKSTOWYM TEGO ELEMENTU W ATRYBUCIE HTML.
     Cudzysłów zamieniamy na apostrof, bo to jedyny znak, który mógłby wyjść
     z wartości atrybutu — a `[data-rola='hero']` znaczy w CSS-ie to samo co
     `[data-rola="hero"]`. Podwójnego ucieczkowania tu nie ma i być nie może:
     zamieniłoby taki selektor w `&amp;quot;` i przestałby działać. */
  const zCudzyslowem = JSON.parse(phpOutput('grain-cfg.php',
    JSON.stringify(JSON.stringify({ zakres: 'sekcja', sekcja_selektor: '[data-rola="hero"] > .karta' }))));
  /* SONDA ODDAJE WARTOŚĆ JESZCZE ZAKODOWANĄ HTML-owo — rozkodowuje ją dopiero
     przeglądarka, czytając atrybut. Pierwsza wersja tego sprawdzenia
     porównywała wprost i zapalała się na `&#039;`, czyli na własnym błędzie
     odczytu, a nie na kodzie. Liczy się to, co zobaczy `getAttribute()`. */
  const odkoduj = (w) => String(w || '')
    .replace(/&#0?39;/g, "'").replace(/&quot;/g, '"')
    .replace(/&gt;/g, '>').replace(/&lt;/g, '<').replace(/&amp;/g, '&');
  t.check('cudzysłów w selektorze nie wychodzi z atrybutu',
    odkoduj(zCudzyslowem.atrybuty['data-sekcja']) === "[data-rola='hero'] > .karta",
    odkoduj(zCudzyslowem.atrybuty['data-sekcja']));

  // ── Ziarno naprawdę się rysuje ──────────────────────────────────────────
  /* NA PIKSELACH, nie na obecności węzła. Sprawdzenie „jest <canvas>"
     przepuściłoby pustą kanwę — czyli element, który nie rysuje nic, a wygląda
     na uruchomiony. Dokładnie ten błąd fala miała w 1.152.0. */
  t.section('ziarno naprawdę maluje, i tym mocniej, im wyższa moc');

  const zrzut = async (query, przed) => {
    const p = await t.open('grain.html', { viewport: OKNO, settle: 400, query });
    if (przed) await przed(p);
    const png = await p.screenshot({ clip: KLIP });
    const stan = await p.evaluate(() => ({
      plotno: !!window.__plotno(),
      naWierzchu: window.__ktoNaWierzchu(),
      ostrzezenia: window.__ostrzezenia,
    }));
    return { p, png, ...stan };
  };

  const bez = await zrzut('webgl=nie');
  const slabe = await zrzut('moc=0.08&przesiew=stop');
  const mocne = await zrzut('moc=0.5&przesiew=stop');

  const sBez = szorstkosc(bez.png), sSlabe = szorstkosc(slabe.png), sMocne = szorstkosc(mocne.png);

  /* ODNIESIENIE: bez elementu tło jest gładkie. Bez tego „ziarno jest"
     przechodziłoby także dla strony, która ma własną fakturę. */
  t.check('bez elementu tło jest gładkie', sBez < 0.5, 'szorstkość ' + sBez);
  t.check('z elementem pojawia się faktura', sSlabe > 3, 'szorstkość ' + sSlabe);
  t.check('a mocniejsze ziarno daje jej więcej', sMocne > sSlabe * 3,
    'szorstkość ' + sSlabe + ' → ' + sMocne);
  await bez.p.close(); await mocne.p.close();

  // ── Nakładka nie łapie kliknięć ─────────────────────────────────────────
  /* WARUNEK DZIAŁANIA STRONY, nie kosmetyka. Kanwa leży nad całą treścią
     z wysokim z-index — bez `pointer-events: none` przechwytywałaby każde
     kliknięcie i strona przestałaby reagować. */
  t.check('kanwa nie przechwytuje kliknięć', slabe.naWierzchu === 'tresc',
    'na wierzchu: ' + slabe.naWierzchu);
  await slabe.p.close();

  // ── Przewijanie z treścią ───────────────────────────────────────────────
  /* SEDNO ZGŁOSZENIA („dobrze by było, żeby był przewijany z treścią").
     Mierzymy w trybie NIERUCHOMYM, bo tylko tam da się odróżnić skutek
     przewinięcia od przesiewu: przy przesiewie obraz zmienia się co klatkę
     z samego losowania i nie wiadomo, co go zmieniło.

     Tu też wyszła usterka — sondą, przed napisaniem tego sprawdzenia. Ziarno
     nieruchome NIE MIAŁO nasłuchu przewijania, więc przewinięcie o 500 px
     zmieniało ZERO pikseli i cała obietnica znikała dokładnie w tym trybie,
     który jest wyjściem dla słabszych maszyn. */
  t.section('ziarno przewija się z treścią, a mnożnik zero to wyłącza');

  const poPrzewinieciu = async (query) => {
    const p = await t.open('grain.html', { viewport: OKNO, settle: 400, query });
    const przed = await p.screenshot({ clip: KLIP });
    await p.evaluate(() => window.__przewin(500));
    await p.waitForTimeout(250);
    const po = await p.screenshot({ clip: KLIP });
    const bledy = p.errors.slice();
    await p.close();
    return { zmienione: roznica(przed, po), wszystkich: KLIP.width * KLIP.height, bledy };
  };

  const zMnoznikiem = await poPrzewinieciu('przesiew=stop&mnoznik=1');
  const bezMnoznika = await poPrzewinieciu('przesiew=stop&mnoznik=0');

  t.check('przy mnożniku 1 przewinięcie przesuwa ziarno',
    zMnoznikiem.zmienione > zMnoznikiem.wszystkich * 0.5,
    zMnoznikiem.zmienione + ' z ' + zMnoznikiem.wszystkich + ' pikseli');
  /* KONTROLA NEGATYWNA: bez niej „ziarno reaguje na przewinięcie" przechodziłoby
     także dla kodu, który przerysowuje je z nowym losowaniem przy każdym ruchu —
     czyli ignoruje mnożnik. */
  t.check('a przy zerze stoi w miejscu', bezMnoznika.zmienione === 0,
    bezMnoznika.zmienione + ' pikseli');
  t.check('bez błędów JS', !zMnoznikiem.bledy.length, zMnoznikiem.bledy.join(' | ') || 'brak');

  // ── Przesiew ────────────────────────────────────────────────────────────
  t.section('przesiew co klatkę miga, nieruchomy stoi');

  const dwaZrzuty = async (query) => {
    const p = await t.open('grain.html', { viewport: OKNO, settle: 400, query });
    const a = await p.screenshot({ clip: KLIP });
    await p.waitForTimeout(350);
    const b = await p.screenshot({ clip: KLIP });
    await p.close();
    return roznica(a, b);
  };

  const stoi = await dwaZrzuty('przesiew=stop');
  const miga = await dwaZrzuty('przesiew=klatka');

  t.check('nieruchome daje dwa identyczne kadry', stoi === 0, stoi + ' pikseli różnicy');
  /* KONTROLA NEGATYWNA powyższego: bez niej „stoi" przechodziłoby także dla
     elementu, który w ogóle nie rysuje. */
  t.check('a przesiewane dwa różne', miga > 1000, miga + ' pikseli różnicy');

  // ── Zasięg: całe okno albo jedna sekcja ─────────────────────────────────
  /* ZGŁOSZONE Z UŻYCIA: „dodaj do samej kontrolki grain możliwość wyświetlania
     tylko w jednej sekcji z łagodnym przejściem na górze/dole, a nie na całej
     stronie".

     MIERZYMY PASMAMI KADRU, bo maska jest właśnie rozkładem ziarna po
     wysokości — jedna liczba na cały kadr nie odróżniłaby „ziarno w sekcji" od
     „ziarno wszędzie, tylko słabsze". Sekcja w fixturze stoi absolutnie na
     wierszach 200–400, więc pasma są policzalne bez zgadywania.

     ZMIERZONE (okno 900×600, moc 0,5, przesiew nieruchomy; szorstkość wycinka):

         wariant              │ 80–160 │ 170–230 │ 260–340 │ 470–550
         całe okno            │  42,64 │   42,68 │   42,85 │   42,49
         sekcja, wtopienie 0  │      0 │   20,99 │   42,85 │       0
         sekcja, wtopienie120 │      0 │    1,17 │   30,80 │       0
         sekcja z selektorem  │      0 │   20,99 │   42,85 │       0
         selektor nietrafiony │  42,64 │   42,68 │   42,85 │   42,49

     PASMO 170–230 LEŻY NA KRAWĘDZI sekcji i to ono pokazuje wtopienie: przy
     twardej krawędzi ma połowę ziarna (20,99 — bo połowa pasma jest w środku),
     a przy wtopieniu 120 px dopiero się zaczyna (1,17).

     ŚRODEK PRZY WTOPIENIU 120 NIE DOCHODZI DO PEŁNI (30,80 wobec 42,85) i to
     nie jest usterka: sekcja ma 200 px, więc wtopienia z góry i z dołu na siebie
     zachodzą. Wtopienie dłuższe niż połowa sekcji znaczy ziarno, które nigdzie
     nie osiąga pełnej mocy. */
  t.section('ziarno w jednej sekcji, z wtopieniem na krawędziach');

  /* Środek sekcji — ten sam wycinek, którego używa sekcja o awariach niżej. */
  const SRODEK_SEKCJI = { x: 0, y: 260, width: 400, height: 80 };

  const PASMA = {
    gora:   { x: 0, y: 80,  width: 400, height: 80 },
    brzeg:  { x: 0, y: 170, width: 400, height: 60 },
    srodek: { x: 0, y: 260, width: 400, height: 80 },
    dol:    { x: 0, y: 470, width: 400, height: 80 },
  };

  /** Szorstkość w każdym z czterech pasm kadru. */
  const pasma = async (p) => {
    const w = {};
    for (const k of Object.keys(PASMA)) w[k] = szorstkosc(await p.screenshot({ clip: PASMA[k] }));
    return w;
  };

  const zasieg = async (query) => {
    const p = await t.open('grain.html', { viewport: OKNO, settle: 500, query });
    const w = await pasma(p);
    w.ostrzezenia = await p.evaluate(() => window.__ostrzezenia);
    w.p = p;
    return w;
  };

  const BAZA = 'moc=0.5&przesiew=stop';

  /* ODNIESIENIE: przy zasięgu „całe okno" wszystkie pasma mają tyle samo.
     Bez tego „w sekcji jest ziarno" przechodziłoby także dla maski, która nie
     robi nic. */
  const cale = await zasieg(BAZA);
  t.check('całe okno: ziarno w każdym paśmie',
    Math.min(cale.gora, cale.brzeg, cale.srodek, cale.dol) > 30,
    [cale.gora, cale.brzeg, cale.srodek, cale.dol].join(' / '));
  await cale.p.close();

  const twarda = await zasieg(BAZA + '&zakres=sekcja&gdzie=sekcja&wtop=0');
  t.check('sekcja: nad nią goło', twarda.gora < 0.5, 'szorstkość ' + twarda.gora);
  t.check('sekcja: pod nią goło', twarda.dol < 0.5, 'szorstkość ' + twarda.dol);
  t.check('sekcja: w środku pełne ziarno', twarda.srodek > 30, 'szorstkość ' + twarda.srodek);
  t.check('bez błędów JS', !twarda.p.errors.length, twarda.p.errors.join(' | ') || 'brak');
  await twarda.p.close();

  /* SEDNO PROŚBY O „ŁAGODNE PRZEJŚCIE": przy tej samej krawędzi i tej samej
     mocy wtopienie musi dać na niej WYRAŹNIE MNIEJ ziarna niż twarda granica.
     Porównanie jest względne, bo obie liczby wychodzą z tego samego pomiaru —
     próg bezwzględny opisywałby maszynę testową. */
  const miekka = await zasieg(BAZA + '&zakres=sekcja&gdzie=sekcja&wtop=120');
  t.check('wtopienie wygasza krawędź', miekka.brzeg < twarda.brzeg / 5,
    'krawędź ' + twarda.brzeg + ' → ' + miekka.brzeg);
  t.check('a poza sekcją dalej goło', miekka.gora < 0.5 && miekka.dol < 0.5,
    miekka.gora + ' / ' + miekka.dol);
  await miekka.p.close();

  /* Selektor ma trafiać w to samo, co domyślny rodzic — inaczej dwie drogi
     do tej samej sekcji dawałyby dwa różne wyniki. */
  const przezSelektor = await zasieg(BAZA + '&zakres=sekcja&sekcja=%23sekcja&wtop=0');
  t.check('selektor daje to samo co rodzic',
    przezSelektor.gora < 0.5 && przezSelektor.srodek > 30,
    przezSelektor.gora + ' / ' + przezSelektor.srodek);
  await przezSelektor.p.close();

  /* NIETRAFIONY SELEKTOR NIE MOŻE ZNIKNĄĆ PO CICHU. Ziarno rozlane na całe
     okno zamiast jednej sekcji wygląda jak usterka układu, nie jak literówka
     w selektorze — a wtedy szuka się jej w CSS-ie. */
  const pudlo = await zasieg(BAZA + '&zakres=sekcja&sekcja=.nie-ma&wtop=0');
  t.check('nietrafiony selektor wraca na całe okno', pudlo.gora > 30, 'szorstkość ' + pudlo.gora);
  t.check('i mówi o tym w konsoli',
    pudlo.ostrzezenia.some((o) => /nie znalazłem sekcji/.test(o)),
    pudlo.ostrzezenia.join(' | ') || 'cisza');
  await pudlo.p.close();

  // ── Zła klatka nie zabija animacji ──────────────────────────────────────
  /* ZGŁOSZONE Z UŻYCIA: „coś jest nie tak z ziarnem. Podczas przewijania
     zatrzymuje się i przestaje animować. Nie zawsze". Doprecyzowane: zamrożone
     NA STAŁE, na telefonie i na desktopie, przy zasięgu „tylko jedna sekcja".

     PRZYCZYNA BYŁA W KOLEJNOŚCI LINIJEK w pętli rysowania — zamówienie
     następnej klatki stało na KOŃCU ciała, więc jeden wyjątek zabijał animację
     na zawsze. Do 1.210.0 nie miało to jak wystrzelić: ciało klatki było samymi
     wywołaniami WebGL-a, a te nie rzucają. 1.211.0 wstawił tam pierwszy odczyt
     DOM-u — `sekcja.getBoundingClientRect()` — i tylko w gałęzi trybu sekcji.

     TRZY SPOSOBY, NA JAKIE SEKCJA POTRAFI ZNIKNĄĆ SPOD NÓG, i każdy wymaga
     innej odpowiedzi. Na żywej stronie robi to builder przy przerysowaniu albo
     ScrollTrigger, który przy przypinaniu przenosi element do `pin-spacera`;
     tu odtwarza je fixture, bo Bricksa na tej maszynie nie ma.

     Zmierzone (wycinek 400×80, dwa zrzuty oddalone o 400 ms):

         wariant           │ przed naprawą │ po naprawie
         sekcja zdrowa     │   23 247      │  23 240
         rect rzuca        │        0      │  23 213   ← zamrożone na stałe
         sekcja znika      │        0      │  23 259   ← i to po CICHU
         korzeń znika      │   23 215      │       0   ← zombi na całym oknie

     DWA OSTATNIE WIERSZE IDĄ W PRZECIWNE STRONY i o to chodzi. Sekcja
     wypadająca z drzewa nie rzuca niczym — `getBoundingClientRect()` na
     odpiętym elemencie oddaje same zera, więc maska gasiła ziarno w ciszy.
     Odwrotnie przy zniknięciu KORZENIA: kanwa leży w <body>, nie w korzeniu,
     więc malowała dalej po stronie, z której element usunięto. */
  t.section('zła klatka nie zabija animacji, a znikająca sekcja nie gasi ziarna');

  const poAwarii = async (query) => {
    const p = await t.open('grain.html', { viewport: OKNO, settle: 1200, query });
    const a = await p.screenshot({ clip: SRODEK_SEKCJI });
    await p.waitForTimeout(400);
    const b = await p.screenshot({ clip: SRODEK_SEKCJI });
    const ostrzezenia = await p.evaluate(() => window.__ostrzezenia);
    const rzutow = await p.evaluate(() => window.__rzutow);
    await p.close();
    return { zmiana: roznica(a, b), ostrzezenia, rzutow, bledy: p.errors };
  };

  const BAZA_AWARII = 'moc=0.5&zakres=sekcja&sekcja=%23sekcja&wtop=0';

  /* ODNIESIENIE: bez awarii ziarno w sekcji migocze. Bez tego „migocze po
     awarii" przechodziłoby także dla sprawdzenia, które mierzy co innego. */
  const zdrowe = await poAwarii(BAZA_AWARII);
  t.check('sekcja zdrowa: ziarno migocze', zdrowe.zmiana > 5000,
    zdrowe.zmiana + ' pikseli różnicy');
  t.check('i nic nie ma do powiedzenia', zdrowe.ostrzezenia.length === 0,
    zdrowe.ostrzezenia.join(' | ') || 'cisza');

  const rzuca = await poAwarii(BAZA_AWARII + '&rzucPo=400');
  t.check('rzucający odczyt sekcji NIE zabija animacji', rzuca.zmiana > 5000,
    rzuca.zmiana + ' pikseli różnicy');
  /* Rzucający odczyt to stan trwały, nie potknięcie jednej klatki — więc
     zasięg schodzi na całe okno, zamiast czyścić kanwę w każdej klatce. */
  t.check('i mówi o tym RAZ, nie co klatkę', rzuca.ostrzezenia.length === 1,
    rzuca.ostrzezenia.length + ': ' + rzuca.ostrzezenia.join(' | '));
  t.check('a treść nazywa przyczynę',
    /odczyt położenia sekcji rzucił wyjątkiem/.test(rzuca.ostrzezenia[0] || ''),
    rzuca.ostrzezenia[0] || 'cisza');

  /* NIEUDANY ODCZYT TO STAN TRWAŁY, nie potknięcie jednej klatki — więc zasięg
     schodzi na całe okno i element przestaje się dobijać do sekcji. Po obrazie
     tego nie widać (wygląda tak samo), więc liczymy PRÓBY: ma być dokładnie
     jedna. Bez zejścia byłoby tyle, ile klatek — przy 400 ms grubo ponad
     dwadzieścia, każda z rzutem i złapaniem. */
  t.check('po nieudanym odczycie nie dobija się co klatkę', rzuca.rzutow === 1,
    rzuca.rzutow + ' prób odczytu');

  /* WYJĄTEK POZA ODCZYTEM SEKCJI — tu ratuje wyłącznie kolejność linijek
     w `ruszaj()`: zamówienie następnej klatki idzie PRZED rysowaniem, a ciało
     jest w `try`. `zmierz()` leci po narysowaniu, więc kadr zdążył powstać
     i widać różnicę między „pętla żyje" a „pętla umarła". */
  const wZmierz = await poAwarii('moc=0.5&rzucWZmierz=400');
  t.check('wyjątek poza odczytem sekcji też nie zabija pętli', wZmierz.zmiana > 5000,
    wZmierz.zmiana + ' pikseli różnicy');
  t.check('i zgłasza się raz, z treścią wyjątku',
    wZmierz.ostrzezenia.length === 1
      && /klatka rzuciła wyjątkiem/.test(wZmierz.ostrzezenia[0])
      && /zmierz rzuca/.test(wZmierz.ostrzezenia[0]),
    wZmierz.ostrzezenia.join(' | ') || 'cisza');

  const znika = await poAwarii(BAZA_AWARII + '&usunPo=400');
  t.check('sekcja wypadła z drzewa: ziarno maluje dalej', znika.zmiana > 5000,
    znika.zmiana + ' pikseli różnicy');
  t.check('i nie robi tego po cichu',
    znika.ostrzezenia.length === 1 && /wypadła z drzewa/.test(znika.ostrzezenia[0]),
    znika.ostrzezenia.join(' | ') || 'cisza');

  /* ODWROTNY KIERUNEK: tu ziarno ma PRZESTAĆ malować. Kanwa leży w <body>,
     więc po usunięciu korzenia malowałaby dalej po cudzej stronie. */
  const zombi = await poAwarii(BAZA_AWARII + '&usunKorzenPo=400');
  t.check('zniknięty korzeń gasi ziarno', zombi.zmiana === 0,
    zombi.zmiana + ' pikseli różnicy');
  t.check('i też mówi dlaczego',
    zombi.ostrzezenia.length === 1 && /korzeń elementu zniknął/.test(zombi.ostrzezenia[0]),
    zombi.ostrzezenia.join(' | ') || 'cisza');

  // ── Maska nadąża za sekcją przy przewijaniu ─────────────────────────────
  /* WARUNEK DZIAŁANIA, NIE OZDOBA: maska liczy się z prostokąta sekcji
     w kadrze, więc bez odświeżenia przy przewinięciu ziarno zostawałoby tam,
     gdzie sekcja była przy wczytaniu strony.

     TRZY TRYBY, BO SĄ TRZY DROGI DO JEDNEJ KLATKI: przesiew „nieruchome",
     ograniczony ruch i automat jakości schodzący w trakcie. Nasłuch pyta
     o BRAK PĘTLI (`uchwyt`), a nie o przesiew — pytanie o przesiew obejmowało
     jeden z tych trzech i przy ograniczonym ruchu maska stała w miejscu.

     Zmierzone (przewinięcie o 200 px przesuwa sekcję z wierszy 200–400 na
     0–200, więc pasma zamieniają się rolami):

         tryb              │ przed: góra / środek │ po: góra / środek
         nieruchome        │      0 / 42,85       │ 43,06 / 0
         co klatkę         │      0 / 42,65       │ 42,72 / 0
         ograniczony ruch  │      0 / 42,89       │ 42,39 / 0
  */
  t.section('maska jedzie za sekcją przy przewijaniu');

  const pasmaPoPrzewinieciu = async (query) => {
    const p = await t.open('grain.html', { viewport: OKNO, settle: 500, query });
    const przed = await pasma(p);
    await p.evaluate(() => window.__przewin(200));
    await p.waitForTimeout(400);
    const po = await pasma(p);
    await p.close();
    return { przed, po };
  };

  for (const [nazwa, ogon] of [
    ['nieruchome',       '&przesiew=stop'],
    ['co klatkę',        ''],
    ['ograniczony ruch', '&ruch=ogranicz'],
  ]) {
    const r = await pasmaPoPrzewinieciu('moc=0.5&zakres=sekcja&gdzie=sekcja&wtop=0' + ogon);
    t.check(nazwa + ': maska zjeżdża z sekcją',
      r.przed.gora < 0.5 && r.przed.srodek > 30 && r.po.gora > 30 && r.po.srodek < 0.5,
      'góra ' + r.przed.gora + ' → ' + r.po.gora + ', środek ' + r.przed.srodek + ' → ' + r.po.srodek);
  }

  // ── Redukcja ruchu ──────────────────────────────────────────────────────
  /* Ziarno ZOSTAJE na ekranie — jest dekoracją, więc jego zniknięcie zmieniłoby
     wygląd strony. Ta sama polityka co w fali. Kontrola negatywna jest tu
     konieczna: „nic się nie rusza" jest nie do odróżnienia od elementu, który
     się nie uruchomił. */
  t.section('przy ograniczonym ruchu jeden kadr, ale kadr JEST');

  const spokojnie = await zrzut('ruch=ogranicz');
  t.check('kanwa powstaje', spokojnie.plotno === true, 'płótno: ' + spokojnie.plotno);
  t.check('i naprawdę coś maluje', szorstkosc(spokojnie.png) > 3,
    'szorstkość ' + szorstkosc(spokojnie.png));
  await spokojnie.p.close();

  const spokojneDwa = await dwaZrzuty('ruch=ogranicz');
  t.check('a obraz się nie rusza', spokojneDwa === 0, spokojneDwa + ' pikseli różnicy');

  // ── Kiedy element ma się NIE uruchamiać ─────────────────────────────────
  /* Nakładka na całe okno bez czego rysować to pusta kanwa nad treścią —
     w najlepszym razie nic, w najgorszym coś, co przykrywa stronę. */
  t.section('bez czego rysować element po prostu nie wchodzi');

  t.check('bez WebGL nie stawia kanwy', bez.plotno === false, 'płótno: ' + bez.plotno);
  t.check('i mówi dlaczego',
    bez.ostrzezenia.some((o) => /brak kontekstu WebGL/.test(o)),
    bez.ostrzezenia.join(' | ') || 'brak ostrzeżenia');

  const soft = await zrzut('gpu=nie');
  t.check('przy rasteryzacji programowej też nie', soft.plotno === false, 'płótno: ' + soft.plotno);
  t.check('i mówi dlaczego', soft.ostrzezenia.some((o) => /rasteryzacja programowa/.test(o)),
    soft.ostrzezenia.join(' | ') || 'brak ostrzeżenia');
  t.check('treść strony zostaje nietknięta', soft.naWierzchu === 'tresc',
    'na wierzchu: ' + soft.naWierzchu);
  await soft.p.close();
};
