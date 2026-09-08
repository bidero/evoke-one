/**
 * Historia zmian snippetu — układ tabeli wersji.
 *
 * ZGŁOSZONE Z UŻYCIA: „ułożenie przycisków podgląd i przywróć w rewizjach kodu".
 *
 * Rozpoznanie było dwuczęściowe i obie części są tu sprawdzane:
 *
 *  1. `admin.css` dawał `display: flex` na KOMÓRCE TABELI (`td.evo-akcje`).
 *     Komórka przestaje wtedy być komórką — wypada z układu tabeli i nie
 *     trzyma się swojej kolumny.
 *  2. `data-etykieta` w tabeli wersji NIC NIE ROBIŁO: reguły kart na wąskim
 *     ekranie były zawężone do `.evo-snippety-tbl`, więc tabela wersji ich nie
 *     dostawała i zostawała ciasną tabelą na telefonie.
 *
 * Znacznik pochodzi z PRAWDZIWEGO `evk_snippety_wersje_ekran()`, nie z kopii.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const html = phpOutput('snippety.php', 'wersje-html');

  const otworz = async (szerokosc) => {
    const p = await t.open('snippety-wersje.html', {
      viewport: { width: szerokosc, height: 900 },
      head: 'window.__wersje = ' + JSON.stringify(html) + ';',
    });
    const u = await p.evaluate(() => window.__uklad());
    await p.close();
    return u;
  };

  // ── Szeroki ekran ──────────────────────────────────────────────────────
  t.section('na szerokim ekranie komórka akcji zostaje komórką');

  const szeroki = await otworz(1200);
  t.check('tabela ma wiersze', szeroki.wiersze === 3, szeroki.wiersze + ' wierszy');

  /* SEDNO PIERWSZEJ CZĘŚCI. `display: flex` na `<td>` wyjmuje komórkę z układu
     tabeli — przestaje trzymać się swojej kolumny. Porównujemy z nagłówkiem
     „Akcje", bo to on wyznacza, gdzie ta kolumna jest. */
  t.check('komórka akcji trzyma się swojej kolumny',
    szeroki.komorka && szeroki.naglowek
      && Math.abs(szeroki.komorka.x - szeroki.naglowek.x) <= 2,
    'komórka x=' + (szeroki.komorka || {}).x + ', nagłówek x=' + (szeroki.naglowek || {}).x);
  t.check('i nie jest kontenerem flex',
    szeroki.wyswietl === 'table-cell', 'display: ' + szeroki.wyswietl);

  /* Przyciski mają stać OBOK SIEBIE — to jest to, co widać jako „ułożenie". */
  t.check('przyciski stoją obok siebie, nie jeden pod drugim',
    szeroki.przyciski.length === 2
      && Math.abs(szeroki.przyciski[0].y - szeroki.przyciski[1].y) <= 2
      && szeroki.przyciski[1].x > szeroki.przyciski[0].x,
    JSON.stringify(szeroki.przyciski));

  // ── Wąski ekran ────────────────────────────────────────────────────────
  t.section('na wąskim ekranie wiersz jest kartą');

  const waski = await otworz(700);
  /* DRUGA CZĘŚĆ. `data-etykieta` stoi w znaczniku od dawna, ale bez reguł kart
     nie robi nic — a bez etykiet karta jest kolumną liczb bez podpisów. */
  t.check('komórki układają się w blok, nie w wiersz tabeli',
    waski.ktoDisplay === 'block', 'display: ' + waski.ktoDisplay);
  t.check('a etykieta kolumny jest widoczna',
    waski.etykieta && waski.etykieta.includes('Kto'),
    'content: ' + waski.etykieta);
  t.check('przyciski nadal obok siebie',
    waski.przyciski.length === 2
      && Math.abs(waski.przyciski[0].y - waski.przyciski[1].y) <= 2,
    JSON.stringify(waski.przyciski));
};
