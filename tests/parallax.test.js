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
  t.section('ruch prowadzi przeglądarka, nie skrypt');

  /* ZMIERZONE, DLACZEGO TO SIĘ ZMIENIŁO. `--evk-par-y` to własność
     niestandardowa, a te dziedziczą się na całe poddrzewo — zapis na sekcji
     unieważniał styl każdego jej potomka przy każdej klatce przewijania.
     Sześć sekcji, dławienie CPU 4×, 120 klatek przewijania, czas przeliczania
     stylu wobec liczby potomków w sekcji:

         potomków │   0 │  20 │ 100 │  400
         dawniej  │  97 │ 228 │ 557 │ 2059 ms
         dziś     │  78 │  99 │  86 │  127 ms

     Z parallaksem wyłączonym: 0 ms w każdym przypadku, więc to nie był koszt
     „większej strony". Układ nie kosztował nic ani przedtem, ani dziś —
     `transform` go nie brudzi. */

  await p.evaluate(() => window.__przewin(400));
  await p.waitForTimeout(150);
  const wPolowie = await p.evaluate(() => window.__zSerwera());
  await p.evaluate(() => window.__przewin(1100));
  await p.waitForTimeout(150);
  const dalej = await p.evaluate(() => window.__zSerwera());

  /* NAJWAŻNIEJSZE: warstwa MA SIĘ RUSZAĆ. Bez tego sprawdzenia „skrypt nic nie
     zapisuje" przechodziłoby także wtedy, gdyby parallax przestał działać —
     a to jest najtańszy możliwy sposób, żeby nic nie kosztować. */
  t.check('warstwa naprawdę przesuwa się przy przewijaniu',
    wPolowie.transformWarstwy !== dalej.transformWarstwy
      && wPolowie.transformWarstwy !== 'none',
    wPolowie.transformWarstwy + ' → ' + dalej.transformWarstwy);
  t.check('a skrypt nie zapisuje przy tym nic co klatkę',
    dalej.przesuniecie === '', '--evk-par-y: ' + (dalej.przesuniecie || 'nie ustawiane'));
  t.check('zakres ruchu podany raz, przy starcie',
    /^[\d.]+px$/.test(dalej.zakres), '--evk-par-amp: ' + (dalej.zakres || 'brak'));
  t.check('i nadal nic nie wstawia', dalej.dzieciDiv === 0,
    dalej.dzieciDiv + ' wstawionych elementów');

  /* Zakres ma odtwarzać ruch sprzed zmiany, inaczej gotowe strony zmieniłyby
     wygląd. Stara pętla dawała `wartość × 100` na przejeździe o wysokość okna;
     oś `cover` obejmuje przejazd o okno PLUS wysokość elementu, więc zakres
     rośnie w tej samej proporcji: 0,4 × 100 × (1 + 400/800) = 60 px. */
  t.check('zakres odtwarza ruch sprzed zmiany',
    Math.abs(parseFloat(dalej.zakres) - 60) < 0.6,
    dalej.zakres + ' wobec oczekiwanych 60px');

  /* Reguła zatrzymuje dziedziczenie na dzieciach sekcji, ale `::before` musi
     obie wartości dalej widzieć — inaczej warstwa staje. */
  t.check('własność nie przecieka do zawartości sekcji',
    dalej.uDziecka === '' || dalej.uDziecka === null,
    'u dziecka: "' + dalej.uDziecka + '"');

  // ── Odwrót dla przeglądarek bez osi widoku ─────────────────────────────
  t.section('bez osi widoku zostaje stara droga');

  /* Firefox nie zna dziś `animation-timeline`. Ta ścieżka MUSI zostać sprawna
     — inaczej poprawka wydajności wyłącza parallax na całej przeglądarce.
     Podmieniamy `CSS.supports` przed startem skryptu, bo to jego pyta. */
  const bezOsi = await t.open('parallax-serwer.html', {
    viewport: { width: 1200, height: 800 },
    head: 'window.__regula = ' + JSON.stringify(regula) + ';\n'
        + 'const _sup = CSS.supports.bind(CSS);\n'
        + 'CSS.supports = function (a, b) {\n'
        + '  if (String(a).indexOf("animation-timeline") !== -1) return false;\n'
        + '  return _sup(a, b);\n'
        + '};',
  });
  /* PRZEGLĄDARKA BEZ OBSŁUGI IGNORUJE CAŁY BLOK `@supports`, nie tylko pyta
     inaczej. Pierwsza wersja tego testu podmieniała samo `CSS.supports` dla
     skryptu — a reguła w arkuszu dalej działała i warstwą sterowała animacja
     natywna. Sprawdzenie „warstwa rusza się tak samo" przechodziło wtedy
     z błędnego powodu: patrzyło na ruch, którego stara droga wcale nie robiła.
     Widać to było po liczbach — przesunięcie w macierzy było połową tego, co
     skrypt wpisywał w `--evk-par-y`, bo animacja jechała domyślnym zakresem. */
  await bezOsi.addStyleTag({ content: '[data-parallax-css]::before{animation-name:none}' });

  await bezOsi.evaluate(() => window.__przewin(400));
  await bezOsi.waitForTimeout(150);
  const staraA = await bezOsi.evaluate(() => window.__zSerwera());
  await bezOsi.evaluate(() => window.__przewin(1100));
  await bezOsi.waitForTimeout(150);
  const staraB = await bezOsi.evaluate(() => window.__zSerwera());

  t.check('skrypt wraca do zapisywania przesunięcia',
    staraB.przesuniecie !== '' && parseFloat(staraB.przesuniecie) !== 0,
    '--evk-par-y: ' + (staraB.przesuniecie || 'brak'));
  t.check('i warstwa rusza się tak samo',
    staraA.transformWarstwy !== staraB.transformWarstwy,
    staraA.transformWarstwy + ' → ' + staraB.transformWarstwy);
  /* OBIE DROGI MAJĄ DAWAĆ TEN SAM OBRAZ. To jest sprawdzenie, które pilnuje,
     żeby zmiana wydajnościowa nie przemalowała gotowych stron: przy tym samym
     przewinięciu warstwa ma stać w tym samym miejscu niezależnie od tego,
     którą drogą jedzie. */
  const yZMacierzy = (m) => parseFloat((m.match(/matrix\(([^)]*)\)/) || [0, ''])[1].split(',')[5]);
  t.check('a obie drogi dają to samo przesunięcie',
    Math.abs(yZMacierzy(staraA.transformWarstwy) - yZMacierzy(wPolowie.transformWarstwy)) < 1.5,
    'stara ' + yZMacierzy(staraA.transformWarstwy) + ' wobec natywnej '
      + yZMacierzy(wPolowie.transformWarstwy));
  t.check('zakresu osi natywnej wtedy nie ustawia',
    staraB.zakres === '', '--evk-par-amp: ' + (staraB.zakres || 'nie ustawiane'));
  /* Reset dziedziczenia działa też tutaj — i to on zdejmuje jedną trzecią
     kosztu tej ścieżki (zmierzone: 1898 → 1235 ms przy 400 potomkach). */
  t.check('a własność i tak nie przecieka do zawartości',
    staraB.uDziecka === '' || staraB.uDziecka === null,
    'u dziecka: "' + staraB.uDziecka + '"');
  t.check('bez błędów JS w odwrocie', !bezOsi.errors.length,
    bezOsi.errors.join(' | ') || 'brak');
  await bezOsi.close();

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
};
