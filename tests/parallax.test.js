/**
 * Parallax — warstwa tła powstaje w CSS, nie w skrypcie.
 *
 * ZGŁOSZONE Z UŻYCIA: „przy włączonym parallaksie ekran miga podczas ładowania,
 * dokładnie zdjęcie w tle; wyłączenie parallaksu rozwiązuje problem".
 *
 * Przyczyna nie leżała w samej animacji, tylko w kolejności zdarzeń:
 * przeglądarka malowała sekcję z jej tłem, potem skrypt wstawiał własną warstwę
 * z `opacity: 0` i ZDEJMOWAŁ tło z sekcji, a dwie klatki później wjeżdżał nią
 * z powrotem. „Widać → pusto → wraca", przy każdym wejściu na stronę.
 *
 * Sprawdzenia niżej stoją na PRAWDZIWEJ regule z modułu (wstrzykiwanej do
 * fixture'a wyjściem `tests/php/parallax.php`) i na prawdziwym `parallax.js`.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {

  const regula = phpOutput('parallax.php');

  // ── Reguła z serwera ───────────────────────────────────────────────────
  t.section('warstwa jest w arkuszu, zanim ruszy skrypt');

  t.check('moduł drukuje regułę w nagłówku', regula.includes('<style id="evk-parallax-layer">'),
    regula.slice(0, 40));
  t.check('warstwą jest pseudoelement', regula.includes('[data-parallax-css]::before'),
    'data-parallax-css::before');
  t.check('i dziedziczy tło z sekcji', regula.includes('background-image:inherit'),
    'background-image:inherit');

  /* Gdyby reguła niosła `opacity`, wróciłoby dokładnie to, co naprawiamy:
     warstwa miałaby się z czego wyłaniać. */
  t.check('nie ma w niej krycia ani przejścia',
    !/opacity|transition/.test(regula), 'bez opacity i transition');

  /* Skala domyślna jedzie z ustawień — reguła jest drukowana, nie stała. */
  const zInna = phpOutput('parallax.php', '1.6');
  t.check('skala domyślna pochodzi z ustawień', zInna.includes('scale(var(--evk-par-scale,1.6))'),
    (zInna.match(/scale\(var\([^)]*\)\)/) || ['brak'])[0]);

  // ── Zachowanie w przeglądarce ──────────────────────────────────────────
  t.section('pierwsze malowanie pokazuje już tło');

  const p = await t.open('parallax-serwer.html', {
    viewport: { width: 1200, height: 800 },
    head: 'window.__regula = ' + JSON.stringify(regula) + ';',
  });

  const serw = await p.evaluate(() => window.__zSerwera());

  /* SEDNO POPRAWKI. Stara droga zdejmowała tło z sekcji (`element.style
     .backgroundImage = 'none'`) i wstawiała własną warstwę — między jednym
     a drugim była pusta klatka. */
  t.check('tło zostaje na sekcji', serw.tloSekcji, String(serw.tloSekcji));
  t.check('a warstwa dziedziczy je z niej', serw.tloWarstwy, String(serw.tloWarstwy));
  t.check('warstwa jest od razu widoczna, nie wyłania się',
    serw.krycieWarstwy === '1', 'opacity ' + serw.krycieWarstwy);
  t.check('skrypt nie wstawia już żadnej warstwy', serw.dzieciDiv === 0,
    serw.dzieciDiv + ' wstawionych elementów');

  // ── Ruch ───────────────────────────────────────────────────────────────
  t.section('skryptowi zostaje samo przesuwanie');

  /* KOSZT TEJ PĘTLI, ZMIERZONY. `--evk-par-y` to własność NIESTANDARDOWA,
     a te dziedziczą się na całe poddrzewo — zapis na sekcji unieważnia styl
     każdego jej potomka przy każdej klatce przewijania. Sześć sekcji,
     dławienie CPU 4×, 120 klatek:

         potomków w sekcji │   0 │  20 │ 100 │  400
         czas przeliczania │  97 │ 228 │ 557 │ 2059 ms

     Z parallaksem wyłączonym: 0 ms w każdym przypadku. UKŁAD nie kosztuje nic
     ani przedtem, ani dziś — `transform` go nie brudzi. */

  await p.evaluate(() => window.__przewin(400));
  await p.waitForTimeout(150);
  const wPolowie = await p.evaluate(() => window.__zSerwera());
  await p.evaluate(() => window.__przewin(1100));
  await p.waitForTimeout(150);
  const dalej = await p.evaluate(() => window.__zSerwera());

  t.check('przewinięcie ustawia przesunięcie warstwy',
    dalej.przesuniecie !== '' && parseFloat(dalej.przesuniecie) !== 0,
    '--evk-par-y: ' + (dalej.przesuniecie || 'brak'));

  /* NAJWAŻNIEJSZE, I TO NA TRANSFORMACJI, NIE NA ZMIENNEJ. Sprawdzenie samego
     `--evk-par-y` mówi tylko tyle, że skrypt coś zapisał — a w 1.155.0 zapisywał
     poprawnie i warstwa i tak stała, bo sterowała nią zamrożona animacja.
     Dopiero odczyt transformacji `::before` mówi, czy warstwa NAPRAWDĘ jedzie.

     Fixture ma dziś kontener z `overflow: hidden` wokół sekcji — tak wygląda
     prawdziwa strona i to on przepuścił tamtą regresję. */
  t.check('a warstwa naprawdę się przesuwa, także w kontenerze z overflow:hidden',
    wPolowie.transformWarstwy !== dalej.transformWarstwy
      && wPolowie.transformWarstwy !== 'none',
    wPolowie.transformWarstwy + ' → ' + dalej.transformWarstwy);
  t.check('przodek naprawdę jest kontenerem przewijania',
    await p.evaluate(() => {
      const el = document.querySelector('.z-serwera');
      return getComputedStyle(el.parentElement).overflowY !== 'visible';
    }), 'rodzic sekcji ma overflow inny niż visible');

  t.check('i nadal nic nie wstawia', dalej.dzieciDiv === 0,
    dalej.dzieciDiv + ' wstawionych elementów');

  /* Reguła zatrzymuje dziedziczenie na dzieciach sekcji — to jest ta jedna
     trzecia kosztu (1898 → 1235 ms przy 400 potomkach). `::before` regułą
     objęty nie jest, więc warstwa dalej widzi wartość i dalej jedzie. */
  t.check('własność nie przecieka do zawartości sekcji',
    dalej.uDziecka === '' || dalej.uDziecka === null,
    'u dziecka: "' + dalej.uDziecka + '"');

  // ── Stara droga ────────────────────────────────────────────────────────
  t.section('ręcznie wpisany atrybut jedzie jak dotąd');

  /* Filtr Bricksa oznacza tylko elementy z kontrolek Evoke, więc ręcznie
     wpisane `data-parallax` nie dostaje warstwy z serwera. Ta ścieżka MUSI
     zostać sprawna — inaczej poprawka jednego przypadku psuje drugi. */
  const stara = await p.evaluate(() => window.__zeSkryptu());
  t.check('bez znacznika skrypt nadal buduje warstwę', stara.dzieciDiv === 1,
    stara.dzieciDiv + ' wstawionych elementów');

  /* ZGŁOSZONE Z UŻYCIA PO 1.140.0: „nadal jest flash z parallax". Warstwa
     z serwera trafia tylko do elementów z kontrolek Evoke; ręcznie wpisany
     `data-parallax` jechał dalej starą drogą — a ta zdejmowała tło z sekcji
     i wjeżdżała kryciem od zera. Ta sama dziura, tylko w drugiej ścieżce.
     Teraz obie zachowują się tak samo. */
  t.check('ale nie zdejmuje już tła z sekcji', stara.tloSekcji, String(stara.tloSekcji));
  t.check('a warstwa jest widoczna od razu', stara.krycieWarstwy === '1',
    'opacity ' + stara.krycieWarstwy);
  t.check('bez przejścia krycia, które trzeba przeczekać',
    stara.czasPrzejscia === '0s', 'transition-duration: ' + stara.czasPrzejscia);

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  // ── Przesunięcie gotowe od pierwszej klatki ─────────────────────────────
  /*
   * ZGŁOSZONE Z UŻYCIA: „obraz pojawia się i momentalnie przesuwa się w górę
   * minimalnie". Zgłaszający wyłączył Animatora i objaw został — to zawęziło
   * rzecz do parallaxu.
   *
   * Reguła jest w nagłówku, więc warstwa maluje się od razu, ale bierze
   * `var(--evk-par-y, 0px)` — SPOCZYNEK. Prawdziwa wartość zależy od pozycji
   * przewinięcia i wysokości okna, których PHP nie zna, więc wpisywał ją
   * dopiero `parallax.js` ze stopki. Zmierzone przed poprawką: przez pierwsze
   * ~30–70 ms warstwa stała na zerze, po czym jedną klatką szła na 19 px.
   *
   * Sekcje sprawdzenia stoją NAD ZGIĘCIEM. Pod zgięciem przeskoku nie widać
   * i całe to sprawdzenie świeciłoby na zielono z powodu, który z niego
   * nie wynika.
   */
  t.section('przesunięcie jest gotowe, zanim sekcja zostanie namalowana');

  t.check('moduł drukuje wczesny ustawiacz',
    regula.includes('<script id="evk-parallax-wczesnie">'),
    regula.includes('evk-parallax-wczesnie') ? 'jest' : 'brak');

  const w = await t.open('parallax-start.html', {
    viewport: { width: 1200, height: 800 }, settle: 0,
    head: 'window.__regula = ' + JSON.stringify(regula) + ';',
  });
  const bieg = await w.evaluate(() => window.__przebieg());

  t.check('przebieg ma z czego wnioskować', bieg.klatek > 20, bieg.klatek + ' klatek');

  /* SEDNO. Przed poprawką pierwsza klatka pokazywała „(brak)”. */
  t.check('PIERWSZA klatka ma już przesunięcie', bieg.pierwsza !== '(brak)',
    'pierwsza klatka: ' + bieg.pierwsza);

  /* I nie ma drugiej wartości — czyli nie ma przeskoku, tylko jedno ustawienie.
     Samo „pierwsza klatka niepusta” przeszłoby też wtedy, gdyby wartość
     zaraz potem skoczyła na inną. */
  t.check('i nie zmienia się już ani razu', bieg.zmiany.length === 1,
    bieg.zmiany.map((z) => z.ms + 'ms:' + z.y).join(' → '));

  /* KOPIA WZORU. Wczesny ustawiacz powtarza rachunek z `parallax.js`, bo PHP
     nie ma jak go z niego wziąć. Bez tego sprawdzenia kopia kiedyś odjedzie
     i zamienimy jeden przeskok na drugi — mniejszy, ale przy przewinięciu. */
  const zNaglowka = parseFloat(await w.evaluate(() => window.__zmienna('sekcja', '--evk-par-y')));
  const zeSkryptu = await w.evaluate(() => window.__wzorZeSkryptu('sekcja'));
  t.check('wzór w nagłówku daje to samo, co wzór w skrypcie',
    Math.abs(zNaglowka - zeSkryptu) < 0.5,
    'nagłówek ' + zNaglowka + ' px, skrypt ' + zeSkryptu + ' px');

  /* Kontrola pozytywna: liczba nie jest zerem, więc porównanie wyżej naprawdę
     coś porównuje. Dwa zera zgadzałyby się doskonale i nie znaczyły nic. */
  t.check('a nie jest to zgodność dwóch zer', Math.abs(zeSkryptu) > 1,
    zeSkryptu + ' px');

  /* Skala własna elementu miała tę samą wadę — reguła niosła domyślną,
     a skrypt nadpisywał ją klatkę później.

     MIERZONA NA PIERWSZEJ KLATCE, nie na stanie końcowym. Pierwsza wersja tego
     sprawdzenia czytała stan po wszystkim i przechodziła na zielono także po
     wycięciu obsługi skali z ustawiacza — bo `parallax.js` i tak ją wpisze,
     tyle że klatkę za późno, czyli dokładnie z tą usterką, którą naprawiamy.
     Wyszło to na mutacji. */
  t.check('skala własna też jest gotowa od pierwszej klatki',
    parseFloat(bieg.pierwszaSkala) === 1.35, 'pierwsza klatka: ' + bieg.pierwszaSkala);

  /* KONTROLA NEGATYWNA: element BEZ własnej skali nie dostaje zapisu wcale —
     inaczej ustawiacz nadpisywałby domyślną z reguły bez powodu. */
  t.check('a bez własnej skali nic nie jest wpisywane',
    await w.evaluate(() => document.getElementById('sekcja').style.getPropertyValue('--evk-par-scale')) === '',
    'zapis inline: „' + await w.evaluate(() => document.getElementById('sekcja').style.getPropertyValue('--evk-par-scale')) + '”');

  // ── Sam gradient nie jest ruszany ──────────────────────────────────────
  /*
   * ZGŁOSZONE Z UŻYCIA po 1.181.0: „przesuwa mi też gradient. Możliwe, że tak
   * było?". Było — sprawdzone porównawczo na wersji sprzed tamtej poprawki:
   * transformacje przy przewijaniu wychodziły co do wartości takie same.
   * Widać to było jednak dopiero od 1.181.0, bo wcześniej pierwsza klatka
   * pokazywała spoczynek i przesunięcie pojawiało się skokiem.
   *
   * Gradient CSS jest `background-image` tak samo jak `url(...)`, więc reguła
   * dziedziczyła go i ruszała. Zdjęcie na ruchu zyskuje głębię, gradient tylko
   * rozjeżdża się z projektem.
   *
   * WYŁĄCZAMY CAŁĄ WARSTWĘ, NIE SAM RUCH. Pudełko `::before` sięga od -10% do
   * 110%, więc nawet nieruchome rozciągałoby gradient o piątą część wysokości
   * i przycinało mu oba końce. Bez warstwy widać własne tło sekcji.
   */
  /* ZNALEZIONE PRZY PISANIU TEGO BLOKU, nie zgłoszone: skrypt startował samym
     `DOMContentLoaded`, bez pytania, czy zdarzenie już nie minęło. Wczytany
     później — a tak robią wtyczki optymalizujące dokładające `async` — nie
     robił NIC, bez śladu w konsoli. Fixture wczytuje go z opóźnieniem, czyli
     dokładnie w tej sytuacji, więc to sprawdzenie stoi na realnym przypadku:
     gdyby skrypt milczał, poniższa kontrola pozytywna przy przewijaniu
     nie miałaby czego pokazać. */
  t.check('skrypt rusza także wczytany po DOMContentLoaded',
    await w.evaluate(() => document.querySelector('[data-parallax-css]').dataset.parallaxActive === 'true'),
    'znacznik gotowości na sekcji');

  t.section('tło bez obrazu nie dostaje warstwy');

  t.check('reguła zna wyłączenie warstwy',
    regula.includes('[data-parallax-css][data-evk-par-bez-obrazu]::before{content:none}'),
    'selektor wyłączający');

  const grad = await w.evaluate(() => window.__warstwa('gradient'));
  const obraz = await w.evaluate(() => window.__warstwa('sekcja'));

  /* OD PIERWSZEJ KLATKI, nie „kiedyś". Znacznik stawia też `parallax.js`,
     więc odczyt po wszystkim przechodził także po wycięciu go z nagłówka —
     a wtedy gradient mruga warstwą rozciągającą go o piątą część wysokości
     i dopiero potem ją traci. Wyszło to na mutacji. */
  t.check('sekcja z samym gradientem jest oznaczona od pierwszej klatki',
    bieg.pierwszyGradient === true, 'pierwsza klatka: ' + bieg.pierwszyGradient);
  t.check('i znacznik zostaje', grad.znacznik === true, 'znacznik: ' + grad.znacznik);
  t.check('i nie ma warstwy wcale', grad.trescWarstwy === 'none',
    'content: ' + grad.trescWarstwy);
  t.check('więc nic jej nie przesuwa', grad.y === '(brak)',
    '--evk-par-y: ' + grad.y);

  /* KONTROLA NEGATYWNA, bez której powyższe przechodziłoby też wtedy, gdyby
     warstwa zniknęła WSZYSTKIM — czyli gdyby parallax przestał działać. */
  t.check('a sekcja ze zdjęciem warstwę ma',
    obraz.znacznik === false && obraz.trescWarstwy !== 'none',
    'znacznik: ' + obraz.znacznik + ', content: ' + obraz.trescWarstwy);
  t.check('i jest przesuwana', obraz.y !== '(brak)' && parseFloat(obraz.y) !== 0,
    '--evk-par-y: ' + obraz.y);

  /* A TERAZ PRZY PRZEWIJANIU — bo zgłoszenie brzmiało „przesuwa mi gradient",
     a nie „gradient stoi krzywo". Sam odczyt w spoczynku przechodził także po
     wycięciu warunku ze skryptu: wtedy gradient stoi do pierwszego przewinięcia
     i dopiero potem rusza. Wyszło to na mutacji. */
  await w.evaluate(() => window.scrollTo(0, 300));
  await w.waitForTimeout(250);
  const gradPo = await w.evaluate(() => window.__warstwa('gradient'));
  const obrazPo = await w.evaluate(() => window.__warstwa('sekcja'));

  t.check('gradient stoi także PO przewinięciu', gradPo.y === '(brak)',
    '--evk-par-y: ' + gradPo.y);
  /* Kontrola pozytywna: przewinięcie naprawdę czymś ruszyło, więc powyższe
     nie przechodzi dlatego, że nic się nie wydarzyło. */
  t.check('a zdjęcie owszem — przewinięcie zmieniło jego przesunięcie',
    obrazPo.y !== obraz.y, obraz.y + ' → ' + obrazPo.y);

  t.check('bez błędów JS', !w.errors.length, w.errors.join(' | ') || 'brak');
  await w.close();
};
