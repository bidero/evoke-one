/**
 * Kursor — która reguła zajmuje element.
 *
 * ZGŁOSZONE Z UŻYCIA: „mam ustawione powiększenie na `a`, ale mam też drugie
 * pole, które ma `a` oraz `.home-projekt` — nie działają ustawienia tego
 * drugiego".
 *
 * Do 1.140.2 wygrywała PIERWSZA reguła, która trafiła w element, i zajmowała go
 * na zawsze (`if (!el.hasAttribute('data-cursor'))`). Reguła `a` oznaczała
 * każdy odnośnik, więc druga — `a, .home-projekt` — nie miała już czego
 * oznaczyć. Specyficzność CSS by tu nie pomogła: obie trafiają w `a` z tą samą
 * siłą, więc rozstrzygać musi kolejność.
 *
 * Silnik jest generowany w PHP; fixture wstrzykuje dosłowne wyjście modułu.
 */

const { phpOutput } = require('./lib/harness');

const REGULY = [
  { selector: 'a',                size: 80,  text: 'pierwsza' },
  { selector: 'a, .home-projekt', size: 140, text: 'druga' },
];

module.exports = async function (t) {

  const silnik = phpOutput('kursor.php', JSON.stringify(JSON.stringify(REGULY)));

  t.section('silnik wychodzi z modułu, nie z kopii w teście');
  t.check('moduł podaje skrypt kursora', silnik.includes('customCursorElements'),
    silnik.length + ' znaków');
  t.check('i niesie obie reguły',
    silnik.includes('"a, .home-projekt"') && silnik.includes('"a"'), 'dwie reguły');

  const p = await t.open('kursor.html', {
    viewport: { width: 1200, height: 800 },
    head: 'window.__silnik = ' + JSON.stringify(silnik) + ';',
  });

  // ── Pierwszeństwo ──────────────────────────────────────────────────────
  t.section('wygrywa ostatnia pasująca reguła');

  /* SEDNO ZGŁOSZENIA. Odnośnik trafiają OBIE reguły — ma zostać przy tej
     drugiej, bo stoi niżej na liście. */
  const link = await p.evaluate(() => window.__cel('link'));
  t.check('odnośnik trafiony dwa razy bierze regułę niższą',
    link.rozmiar === 140, 'rozmiar ' + link.rozmiar + ', tekst „' + link.tekst + '"');

  const projekt = await p.evaluate(() => window.__cel('projekt'));
  t.check('odnośnik z klasą projektu tak samo', projekt.rozmiar === 140,
    'rozmiar ' + projekt.rozmiar);

  /* Element trafiony TYLKO przez drugą regułę też musi ją dostać — inaczej
     „wygrywa ostatnia" znaczyłoby „wygrywa ostatnia, ale tylko czasem". */
  const klasa = await p.evaluate(() => window.__cel('tylko-klasa'));
  t.check('element trafiony tylko drugą regułą również ją dostaje',
    klasa.rozmiar === 140, 'rozmiar ' + klasa.rozmiar);

  // ── Atrybut wpisany z ręki ─────────────────────────────────────────────
  t.section('własny atrybut w builderze zostaje nietknięty');

  /* Stara wersja respektowała go PRZY OKAZJI — przez to samo sprawdzenie,
     które psuło kolejność reguł. Teraz jest to osobna decyzja i musi mieć
     osobne pokrycie, bo inaczej zniknęłaby razem z tamtym sprawdzeniem. */
  const wlasny = await p.evaluate(() => window.__cel('wlasny'));
  t.check('ręcznie wpisany data-cursor nie jest nadpisywany',
    wlasny.rozmiar === 999 && wlasny.tekst === 'moje',
    'rozmiar ' + wlasny.rozmiar + ', tekst „' + wlasny.tekst + '"');
  t.check('i jest oznaczony jako cudzy', wlasny.znacznik === 'wlasny',
    String(wlasny.znacznik));

  t.check('bez błędów JS', !p.errors.length, p.errors.join(' | ') || 'brak');
  await p.close();

  // ── Panel: kolejność da się ustawić ────────────────────────────────────
  t.section('kolejność reguł da się zmienić w panelu');

  /* Skoro kolejność rozstrzyga, musi być sterowalna — inaczej jedynym
     sposobem na przestawienie reguł byłoby skasowanie ich i dodanie od nowa. */
  const ekran = phpOutput('tab.php', 'fe-cursor');
  const uchwytow = (ekran.match(/evo-anim-grip/g) || []).length;
  const wierszy  = (ekran.match(/class="evo-cursor-row"/g) || []).length;
  t.check('każdy wiersz ma uchwyt przeciągania', wierszy > 0 && uchwytow >= wierszy,
    uchwytow + ' uchwytów na ' + wierszy + ' wierszy');

  const skrypt = require('fs').readFileSync(
    require('path').join(__dirname, '..', 'assets', 'admin', 'admin.js'), 'utf8');
  t.check('a panel podpina przeciąganie do listy reguł',
    /Sortable\.create\(\$kursory\[0\]/.test(skrypt), 'Sortable na #evo-cursor-repeater-container');
};
