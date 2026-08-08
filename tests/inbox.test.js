/**
 * Skrzynka wiadomości — wejście na stronę i wąski ekran.
 *
 * Dwie rzeczy, obie zgłoszone z użycia:
 *
 * 1. **Wejście na stronę oznaczało pierwszą wiadomość jako przeczytaną.**
 *    `loadList()` otwierało pierwszy element z listy, a `loadDetail()` od razu
 *    dokładało `.is-read` i gasiło kropkę. Zgłoszenie nikt nie przeczytał,
 *    a ślad po nim znikał. Sprawdzamy to na ŹRÓDLE strony — auto-otwieranie
 *    to brak wywołania, a braku nie da się zmierzyć w przeglądarce bez
 *    postawienia całego backendu skrzynki.
 *
 * 2. **Wersja mobilna.** Lista ma stałe 300 px, więc poniżej ~700 px zjadała
 *    połowę ekranu, a na treść zostawał pas nie do czytania. Poniżej 782 px
 *    widać ALBO listę, ALBO szczegóły — i to jest mierzone: szerokości kolumn,
 *    ich widoczność, obecność wyjścia z powrotem do listy oraz to, że nic nie
 *    wystaje poza szerokość okna.
 */

const { phpOutput } = require('./lib/harness');
const fs = require('fs');
const path = require('path');

const SRC = path.join(__dirname, '..', 'includes', 'admin', 'forminbox-page.php');

module.exports = async function (t) {
  // ── 1. Brak auto-otwierania ───────────────────────────────────────────
  t.section('wejście na stronę nie czyta za użytkownika');

  const src = fs.readFileSync(SRC, 'utf8');

  // `loadDetail` ma być wołane wyłącznie z obsługi kliknięcia. Każde inne
  // wywołanie w kodzie ładowania listy to znów to samo: strona odznacza
  // wiadomość, której nikt nie otworzył.
  // Komentarze zdejmujemy przed sprawdzeniem: wzmianka o `loadDetail()`
  // w wyjaśnieniu, DLACZEGO go tu nie ma, nie jest wywołaniem.
  const inLoadList = src
    .slice(src.indexOf('function loadList'), src.indexOf('function loadDetail'))
    .replace(/\/\/[^\n]*/g, '');
  t.check('loadList nie otwiera żadnej wiadomości',
    !/loadDetail\s*\(/.test(inLoadList),
    (inLoadList.match(/loadDetail\s*\([^)]*\)/g) || ['brak wywołań']).join(', '));

  // Kontrola negatywna: `loadDetail` w ogóle istnieje i nadal oznacza
  // wiadomość jako przeczytaną. Bez tego powyższe przeszłoby także wtedy,
  // gdyby ktoś usunął całą funkcję i strona przestała działać.
  t.check('loadDetail nadal oznacza jako przeczytane',
    /function loadDetail[\s\S]{0,600}addClass\('is-read'\)/.test(src), 'jest');

  // Prawy panel musi mieć co pokazać, skoro nic się nie otwiera samo.
  t.check('jest stan pusty dla prawego panelu',
    /evk-inbox-detail-empty[\s\S]{0,200}Wybierz wiadomość/.test(src), 'jest');

  // ── 2. Wąski ekran ────────────────────────────────────────────────────
  const head = 'window.__page = ' + JSON.stringify(phpOutput('inbox-page.php')) + ';';

  // Szeroki ekran — kontrola. Bez niej „widać jedną kolumnę" byłoby nie do
  // odróżnienia od strony, która się w ogóle nie zbudowała.
  t.section('szeroki ekran — lista i szczegóły obok siebie');

  const wide = await t.open('inbox.html', { viewport: { width: 1400, height: 900 }, head, settle: 120 });
  const w = await wide.evaluate(() => window.__panes());
  t.check('lista widoczna', w.side.shown, w.side.w + 'px');
  t.check('szczegóły widoczne obok listy', w.detail.shown, w.detail.w + 'px');
  t.check('szczegóły szersze niż lista', w.detail.w > w.side.w,
    w.detail.w + ' vs ' + w.side.w);

  await wide.evaluate(() => window.__openDetail());
  const wAfter = await wide.evaluate(() => window.__panes());
  t.check('wybór wiadomości nie chowa listy na szerokim ekranie', wAfter.side.shown,
    wAfter.side.w + 'px');
  const wBack = await wide.evaluate(() => window.__backBtn());
  t.check('powrotu nie ma tam, gdzie jest niepotrzebny', wBack && !wBack.shown,
    wBack ? 'ukryty' : 'brak przycisku');
  t.check('bez błędów JS', !wide.errors.length, wide.errors.join(' | ') || 'brak');
  await wide.close();

  // Wąskie ekrany — dwa typowe telefony i próg WordPressa.
  for (const width of [360, 414, 782]) {
    t.section('wąski ekran ' + width + ' px');

    const p = await t.open('inbox.html', { viewport: { width, height: 800 }, head, settle: 120 });

    const before = await p.evaluate(() => window.__panes());
    t.check('najpierw widać listę', before.side.shown && !before.detail.shown,
      'lista ' + before.side.w + 'px, szczegóły ' +
      (before.detail.shown ? before.detail.w + 'px' : 'ukryte'));
    t.check('lista na pełną szerokość', Math.abs(before.side.w - width) <= 1,
      before.side.w + ' z ' + width);

    await p.evaluate(() => window.__openDetail());
    const after = await p.evaluate(() => window.__panes());
    t.check('po wyborze widać szczegóły', after.detail.shown && !after.side.shown,
      'szczegóły ' + after.detail.w + 'px, lista ' +
      (after.side.shown ? after.side.w + 'px' : 'ukryta'));

    // Bez wyjścia wejście w wiadomość na telefonie jest ślepą uliczką.
    const back = await p.evaluate(() => window.__backBtn());
    t.check('jest powrót do listy', back && back.shown, back ? back.h + 'px wysokości' : 'BRAK');
    t.check('powrót jest dotykalny', back && back.h >= 32, back ? back.h + 'px' : '—');

    await p.evaluate(() => window.__back());
    const backTo = await p.evaluate(() => window.__panes());
    t.check('powrót wraca do listy', backTo.side.shown && !backTo.detail.shown,
      backTo.side.shown ? 'lista widoczna' : 'lista wciąż ukryta');

    // Poziomy pasek przewijania to najbardziej widoczny objaw złego układu.
    await p.evaluate(() => window.__openDetail());
    const of = await p.evaluate(() => window.__overflow());
    t.check('nic nie wystaje poza szerokość okna', of.worst <= of.win + 1,
      'najdalszy element na ' + of.worst + ', okno ' + of.win);
    t.check('strona nie przewija się w poziomie', of.doc <= of.win + 1,
      of.doc + ' vs ' + of.win);

    await p.evaluate(() => window.__back());
    const taps = await p.evaluate(() => window.__taps());
    const small = taps.filter((h) => h < 36);
    t.check('przyciski paska są dotykalne', taps.length > 0 && !small.length,
      small.length ? small.join(', ') + 'px' : taps.length + ' szt. po ' + taps[0] + 'px');

    t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
    await p.close();
  }
};
