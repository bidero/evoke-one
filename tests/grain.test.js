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
