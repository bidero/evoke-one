/**
 * Backup — czytnik ZIP do przywracania (includes/backup/zip-reader.php).
 *
 * Archiwa pisze prawdziwy EVK_Zip_Writer — w tym te pisane porcjami,
 * z pełnymi opróżnieniami deflate w środku pliku, bo tak powstają kopie
 * robione w wielu krokach. Najważniejsze: wznawianie w połowie wpisu
 * i odmowa przy uszkodzeniu, zamiast cichego przywrócenia złych danych.
 */

const { phpOutput } = require('./lib/harness');

const sonda = (scen) => JSON.parse(phpOutput('backup-czytnik.php', scen));

module.exports = async function (t) {

  t.section('odczyt archiwum pisanego przez wtyczkę');

  const z = sonda('zwykle');
  t.check('wszystkie wpisy odczytane, w kolejności zapisu',
    z.wpisow === z.oczekiwanych && z.nazwy_zgodne, z.wpisow + ' z ' + z.oczekiwanych);
  t.check('treść każdego pliku co do bajtu (bez kompresji, deflate, pusty, polskie znaki)',
    z.zgodne === z.oczekiwanych && !z.rozne.length, z.rozne.join(', ') || z.zgodne + ' zgodnych');
  t.check('są oba rodzaje wpisów: bez kompresji i deflate',
    z.metody['wp-content/uploads/film.mp4'] === 0 && z.metody['wp-content/losowy.txt'] === 8);
  t.check('pusty katalog rozpoznany jako katalog', z.katalog === true);

  const z64 = sonda('zip64');
  t.check('ZIP64 (wymuszony dla każdego wpisu): odczyt i treść zgodne',
    z64.wpisow === z64.oczekiwanych && z64.zgodne === z64.oczekiwanych, z64.rozne.join(', ') || 'zgodne');

  t.section('wznawianie w połowie wpisu');

  const w = sonda('wznawianie');
  t.check('termin „już": duże wpisy rozpakowane wieloma wywołaniami',
    w.wywolan['wp-content/uploads/film.mp4'] > 3 && w.wywolan['wp-content/losowy.txt'] > 3,
    JSON.stringify(w.wywolan));
  t.check('…i każdy co do bajtu zgodny (także deflate z opróżnieniami w środku)',
    w.zgodne === w.oczekiwanych && !w.rozne.length, w.rozne.join(', ') || 'zgodne');

  const u = sonda('ubity');
  t.check('krok ubity po zapisie, przed zapisem stanu: śmieci za stanem obcięte, plik zgodny',
    ['wp-content/losowy.txt', 'wp-content/uploads/film.mp4'].every((k) => u[k].smieci_byly && u[k].zgodny), JSON.stringify(u));
  t.check('plik docelowy istniał i był dłuższy: po rozpakowaniu bez starego ogona', u.istnial_dluzszy === true);

  const c = sonda('czas');
  t.check('wywołanie z minionym terminem robi jedną porcję i oddaje stan',
    c.done === false && c.pos > 0 && c.pos < c.calosc && c.czas_ms < 1000, JSON.stringify(c));

  t.section('uszkodzone i obce archiwa');

  const s = sonda('uszkodzone');
  t.check('zmieniony bajt w pliku bez kompresji: odmowa z CRC', /CRC/.test(s.mp4), s.mp4);
  t.check('zmieniony bajt w deflate nieściśliwym: odmowa z CRC', /CRC/.test(s.txt), s.txt);
  /* Uszkodzenie, które inflate wziął za blok końcowy — bez sprawdzenia
     rozmiaru każde wznowienie kończyło się bez postępu (pętla bez końca). */
  t.check('deflate urwany przez uszkodzenie: odmowa, a nie pętla bez postępu',
    /przed czasem|deflate|CRC/.test(s.jsonl), s.jsonl);
  t.check('archiwum ucięte w połowie i zwykły plik: czytelna odmowa',
    /ucięte|nie jest archiwum/.test(s.uciete) && /nie jest archiwum/.test(s.nie_zip), s.uciete + ' | ' + s.nie_zip);

  const o = sonda('obce');
  t.check('archiwum z ZipArchive (inny pisarz) też się czyta', o.wpisow === 8 && o.zgodne === 7 && !o.rozne.length,
    JSON.stringify(o));

  t.section('nazwy wpisów: nic poza katalogiem docelowym');

  const n = sonda('nazwy');
  const dozwolone = ['wp-content/a.txt', 'wp-content/uploads/', 'manifest.json'];
  const zle = n.filter(([nazwa, ok]) => ok !== dozwolone.includes(nazwa)).map(([nazwa]) => JSON.stringify(nazwa));
  t.check('odrzucone: ../, ścieżka bezwzględna, dysk C:, odwrotny ukośnik, bajt zerowy, // i /./',
    !zle.length, zle.join(', ') || 'komplet');
};
