/**
 * Backup — wgrywanie kopii z przeglądarki w kawałkach
 * (includes/backup/upload.php) na prawdziwym WordPressie z tools/testowy-wp.sh.
 *
 * Rdzeń wołany wprost, kawałki po 64 KB. Najważniejsze: wznowienie po
 * zamknięciu karty (ten sam plik → ten sam identyfikator → offset > 0),
 * kawałek powtórzony nie psuje pliku, a na listę trafia wyłącznie kopia tej
 * wtyczki — sprawdzona, zanim ktokolwiek spróbuje z niej przywracać.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  t.section('wgranie z przerwą i wznowieniem');

  const w = JSON.parse(phpOutput('backup-wgrywanie.php'));
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !w.brak, w.brak || 'jest');
  if (w.brak) return;

  t.check('start: offset 0, identyfikator 40 znaków hex, wielkość kawałka z limitów serwera',
    w.start.offset === 0 && w.start.id_ok && w.start.chunk >= 65536, JSON.stringify(w.start));
  t.check('ten sam plik wybrany znowu: ten sam identyfikator, start od miejsca przerwania',
    w.wznowienie.ten_sam_id && w.wznowienie.offset === w.wznowienie.po_trzech && w.wznowienie.offset > 0,
    JSON.stringify(w.wznowienie));
  t.check('inny użytkownik — inny identyfikator (nie dopisuje do cudzej części)', w.inny_uzytkownik_inny_id === true);
  t.check('kawałek powtórzony: serwer podaje rzeczywisty offset, część nietknięta',
    w.powtorka.resync && w.powtorka.offset === w.wznowienie.offset && w.powtorka.plik_nietkniety, JSON.stringify(w.powtorka));
  t.check('po wznowieniu wysłane tylko brakujące kawałki',
    w.koniec.done && w.koniec.wyslanych_po_wznowieniu === w.koniec.kawalkow_razem - 3, JSON.stringify(w.koniec));
  t.check('wgrana kopia co do bajtu, na liście jako „wgrana", z danymi z manifestu',
    w.koniec.md5_zgodne && w.koniec.zrodlo === 'upload' && w.koniec.db_rows === 3 && w.koniec.siteurl === 'http://zrodlo.test',
    JSON.stringify(w.koniec));
  t.check('nazwa z nawiasami i spacjami oczyszczona; część i jej opis sprzątnięte',
    w.koniec.archive === 'moja-kopia-1.zip' && !w.koniec.czesc_zostala);

  t.section('odmowy');

  t.check('plik, który nie jest ZIP-em: odmowa po ostatnim kawałku, część usunięta',
    /nie jest kopia tej wtyczki/.test(w.nie_zip) && w.nie_zip_czesc === false, w.nie_zip);
  t.check('ZIP bez manifestu: odmowa', /manifestu/.test(w.bez_manifestu), w.bez_manifestu);
  t.check('identyfikator z „../": odmowa', /identyfikator/.test(w.zle_id), w.zle_id);
  t.check('rozszerzenie inne niż .zip: odmowa', /\.zip/.test(w.zle_rozszerzenie), w.zle_rozszerzenie);
  t.check('kawałek poza zapowiedziany rozmiar: odmowa', /poza zapowiedziany/.test(w.poza_rozmiar), w.poza_rozmiar);
  t.check('brak miejsca: odmowa przed pierwszym kawałkiem, z liczbami', /Za mało miejsca.*476,8 MB/.test(w.brak_miejsca), w.brak_miejsca);
  t.check('nazwa „../../złośliwa nazwa.zip": w katalogu kopii, polskie litery na łacińskie',
    w.nazwa === 'zlosliwa-nazwa.zip', String(w.nazwa));

  t.section('anulowanie i sprzątanie');

  t.check('anulowanie usuwa wgraną część', w.anulowane[0] === true && w.anulowane[1] === false, JSON.stringify(w.anulowane));
  t.check('część porzucona na ponad dobę znika, świeża zostaje',
    w.sprzatanie.stara === false && w.sprzatanie.swieza === true, JSON.stringify(w.sprzatanie));
};
