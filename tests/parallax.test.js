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

  await p.evaluate(() => window.__przewin(600));
  await p.waitForTimeout(120);
  const poRuchu = await p.evaluate(() => window.__zSerwera());
  t.check('przewinięcie ustawia przesunięcie warstwy',
    poRuchu.przesuniecie !== '' && parseFloat(poRuchu.przesuniecie) !== 0,
    '--evk-par-y: ' + (poRuchu.przesuniecie || 'brak'));
  t.check('i nadal nic nie wstawia', poRuchu.dzieciDiv === 0,
    poRuchu.dzieciDiv + ' wstawionych elementów');

  // ── Stara droga ────────────────────────────────────────────────────────
  t.section('ręcznie wpisany atrybut jedzie jak dotąd');

  /* Filtr Bricksa oznacza tylko elementy z kontrolek Evoke, więc ręcznie
     wpisane `data-parallax` nie dostaje warstwy z serwera. Ta ścieżka MUSI
     zostać sprawna — inaczej poprawka jednego przypadku psuje drugi. */
  const stara = await p.evaluate(() => window.__zeSkryptu());
  t.check('bez znacznika skrypt nadal buduje warstwę', stara.dzieciDiv === 1,
    stara.dzieciDiv + ' wstawionych elementów');
  t.check('i zdejmuje tło z sekcji, jak dotąd', !stara.tloSekcji, String(stara.tloSekcji));

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();
};
