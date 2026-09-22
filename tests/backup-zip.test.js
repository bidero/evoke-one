/**
 * Backup — strumieniowy zapis ZIP (includes/backup/zip-writer.php).
 *
 * Archiwum ma się dać przeczytać tym, czym czyta je restore — ZipArchive
 * z kontrolą spójności — i ma zawierać DOKŁADNIE to, co było na dysku.
 * Samo „archiwum się otwiera" niczego nie dowodzi: ZipArchive otworzy też
 * archiwum z uciętym wpisem, a błąd wyjdzie dopiero przy jego czytaniu.
 * Dlatego sonda czyta treść KAŻDEGO wpisu i porównuje ją ze źródłem.
 *
 * Najważniejsze są scenariusze wznawiania: kopia idzie w wielu tickach,
 * a tick może zostać ubity w dowolnym miejscu.
 */

const { phpOutput } = require('./lib/harness');
const fs = require('fs');
const path = require('path');

const sonda = (scen, arg) => JSON.parse(phpOutput('backup-zip.php', scen + (arg ? ' ' + arg : '')));

/** Archiwum przeczytane w całości i zgodne ze źródłem. */
function zgodne(zip, ile) {
  return zip.otwarte && zip.wpisow === ile && zip.zgodne === ile && zip.rozne.length === 0 && zip.brakujace === 0;
}
const opis = (zip) => JSON.stringify({ otwarte: zip.otwarte, kod: zip.kod, wpisow: zip.wpisow,
  zgodne: zip.zgodne, rozne: zip.rozne });

module.exports = async function (t) {

  // ── Zwykłe archiwum ──────────────────────────────────────────────────────
  t.section('archiwum czyta się ZipArchive z kontrolą spójności, treść = źródło');

  const p = sonda('podstawowe');
  t.check('wszystkie wpisy obecne i co do bajtu zgodne ze źródłem',
    zgodne(p.zip, p.oczekiwanych), opis(p.zip));
  t.check('polskie znaki w nazwach plików przechodzą',
    'zażółć gęślą.txt' in p.zip.metody && 'sub/głęboko/a.php' in p.zip.metody,
    Object.keys(p.zip.metody).join(', '));
  t.check('pusty plik i pusty katalog są w archiwum',
    'pusty.txt' in p.zip.metody && 'uploads/pusty-katalog/' in p.zip.metody);
  /* Kompresja tam, gdzie się opłaca — i nigdzie indziej. JPG jest już
     skompresowany; deflate kosztowałby czas i nic nie dał. */
  t.check('tekst kompresowany (deflate), obraz zapisany wprost (store)',
    p.zip.metody['style.css'] === 8 && p.zip.metody['uploads/foto.jpg'] === 0,
    JSON.stringify(p.zip.metody));
  t.check('archiwum mniejsze od źródeł (kompresja naprawdę działa)',
    p.rozmiar_zip < p.rozmiar_zrodel * 0.5, p.rozmiar_zip + ' B z ' + p.rozmiar_zrodel + ' B');
  t.check('plik poboczny katalogu centralnego sprzątnięty po finish()', p.plik_poboczny_zostal === false);

  // ── Wznawianie ───────────────────────────────────────────────────────────
  t.section('plik dłuższy niż tick: każde wywołanie to nowe żądanie');

  /* Termin już minął przy każdym wywołaniu, więc każde robi jedną porcję
     i oddaje 'partial'. Obiekt powstaje za każdym razem od nowa ze stanu —
     tak jak w kolejnym ticku crona. */
  const w = sonda('wznawianie');
  t.check('plik rzeczywiście przeszedł przez wiele wywołań',
    w.wywolan['duzy.bin.txt'] > 3 && w.wywolan['duzy.jpg'] > 3, JSON.stringify(w.wywolan));
  t.check('deflate sklejony z wielu ticków daje dokładnie plik źródłowy',
    zgodne(w.zip, 2) && w.zip.metody['duzy.bin.txt'] === 8, opis(w.zip));

  t.section('tick ubity: śmieci za stanem zatwierdzonym znikają');

  /* Praca wykonana po ostatnim zapisanym stanie jest „utracona" (tick ubity
     zanim zapisał stan), a na końcu obu plików leżą śmieci. Wznowienie ze
     starego stanu musi dać poprawne archiwum. */
  const u = sonda('ubity');
  t.check('scenariusz naprawdę zostawił dane za stanem zatwierdzonym',
    u.rozmiar_przed_wznowieniem > u.stan_offset + 70000,
    u.rozmiar_przed_wznowieniem + ' B na dysku, stan mówi ' + u.stan_offset + ' B');
  t.check('wznowienie ze starego stanu daje archiwum zgodne ze źródłem',
    zgodne(u.zip, 2), opis(u.zip));

  t.section('plik znika albo kurczy się w trakcie');

  const k = sonda('kurczy');
  t.check('skurczony w połowie: wpis cofnięty, nic nie wisi',
    k.pierwszy === 'partial' && k.po_skurczeniu === 'skipped' && k.wisi_po === false
    && k.offset_po < k.offset_przed, JSON.stringify(k));
  t.check('plik, którego nie ma: pominięty', k.zniknal === 'skipped');
  t.check('archiwum po pominięciach poprawne i bez resztek', zgodne(k.zip, 1), opis(k.zip));

  // ── ZIP64 ────────────────────────────────────────────────────────────────
  t.section('ZIP64');

  /* Wymuszony dla małych plików: struktury ZIP64 muszą się czytać, zanim
     przyjdzie prawdziwy plik 4 GB, którego w teście nie zrobimy. */
  const z = sonda('zip64');
  t.check('rekord i lokator ZIP64 na końcu archiwum', z.rekord_zip64 && z.lokator_zip64);
  t.check('archiwum ZIP64 czyta się i jest zgodne ze źródłem', zgodne(z.zip, 4), opis(z.zip));

  /* Limit zwykłego ZIP-a to 65 535 wpisów — miniatury mediów przekraczają go
     łatwo. ZIP64 musi się włączyć SAM. */
  const m = sonda('wiele', '70000');
  t.check('70 000 wpisów: ZIP64 włączył się sam', m.rekord_zip64 === true);
  t.check('…i ZipArchive widzi wszystkie wpisy',
    m.otwarte && m.wpisow === 70000 && m.ostatni === 'plik 69999',
    'wpisów ' + m.wpisow + ', ostatni: ' + m.ostatni + ', ' + m.sekund + ' s');

  // ── CRC ──────────────────────────────────────────────────────────────────
  t.section('składanie CRC części pliku z różnych ticków');

  const c = sonda('crc');
  t.check('crc32_combine(a, b) = crc32(a.b) dla 300 losowych cięć', c.zle === 0,
    c.zle + ' złych z ' + c.prob);

  // ── Źródło ───────────────────────────────────────────────────────────────
  t.section('źródło modułu');

  const zrodlo = fs.readFileSync(path.join(__dirname, '..', 'includes', 'backup', 'zip-writer.php'), 'utf8');
  const kod = zrodlo.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');
  /* Zapis przez ZipArchive to dokładnie to, czego ten plik ma unikać — patrz
     pomiar w nagłówku. Czytanie ZipArchive jest w porządku, ale nie tutaj. */
  t.check('zapis nie idzie przez ZipArchive', !/\bZipArchive\b/.test(kod));
  t.check('moduł nie woła funkcji WordPressa (ładuje się w gołym PHP)',
    !/\b(?:wp_\w+|get_option|apply_filters|esc_\w+|sanitize_\w+)\s*\(/.test(kod));
};
