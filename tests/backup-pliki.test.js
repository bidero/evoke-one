/**
 * Backup — zbieranie plików: lista wp-content i pakowanie do archiwum
 * (includes/backup/file-collector.php).
 *
 * Kryterium: archiwum zawiera DOKŁADNIE oczekiwany zbiór — ani jednego
 * wpisu brakującego, ani nadmiarowego — i każdy plik ma treść ze źródła.
 * Brak pliku wychodzi dopiero przy przywracaniu, nadmiar (np. stare kopie
 * innej wtyczki) potrafi rozdąć kopię wielokrotnie. Oba są tak samo złe.
 *
 * Drzewo buduje sonda (tests/php/backup-pliki.php) w katalogu tymczasowym.
 */

const { phpOutput } = require('./lib/harness');

const sonda = (scen) => JSON.parse(phpOutput('backup-pliki.php', scen));
const opis = (w) => JSON.stringify({ brak: w.brak, rozne: w.rozne, nadmiar: w.nadmiar });
const komplet = (w) => w.brak.length === 0 && w.rozne.length === 0 && w.nadmiar.length === 0
  && w.wpisow === w.oczekiwanych;

module.exports = async function (t) {

  // ── Wykluczenia ──────────────────────────────────────────────────────────
  t.section('wykluczenia: zakotwiczone w korzeniu albo po nazwie wszędzie');

  /* `cache/` to wp-content/cache — NIE każdy katalog cache. Wtyczki trzymają
     w tak nazwanych katalogach własny kod; kopia bez niego odtworzyłaby
     zepsutą wtyczkę. */
  const wyk = sonda('wykluczenia');
  const zle = wyk.filter((w) => !w.ok).map((w) => w.rel + ' → ' + (w.jest ? 'wykluczony' : 'w kopii'));
  t.check('13 przypadków rozstrzygniętych poprawnie', !zle.length && wyk.length === 13, zle.join(' | ') || 'komplet');

  // ── Pełny przebieg ───────────────────────────────────────────────────────
  t.section('archiwum = dokładnie oczekiwany zbiór plików');

  const p = sonda('pelny');
  t.check('ani jednego brakującego, różnego ani nadmiarowego wpisu', komplet(p), opis(p));
  t.check('lista i pakowanie szły wieloma krokami (każdy jedno wywołanie)',
    p.krokow_listy > 5 && p.krokow_pakowania > p.lista_plikow,
    'lista ' + p.krokow_listy + ', pakowanie ' + p.krokow_pakowania + ' kroków');

  /* Poszczególne przypadki są w komplecie wyżej — tu nazwane osobno, żeby
     porażka od razu mówiła, KTÓRY. Nazwy zbioru oczekiwanego zna sonda. */
  t.check('plik z nazwą spoza UTF-8 (cp1250) w archiwum pod tą samą nazwą',
    !p.brak.some((n) => n.startsWith('base64:')) && !p.rozne.some((n) => n.startsWith('base64:')));
  t.check('katalog z samymi wykluczonymi plikami i katalog pusty mają wpisy',
    !p.brak.includes('wp-content/uploads/tylko-logi/') && !p.brak.includes('wp-content/uploads/2026/10/'));
  t.check('podgląd z katalogu głównego pod _root/, nieistniejący plik pominięty',
    !p.brak.includes('_root/.htaccess') && !p.nadmiar.some((n) => n.includes('brak.txt')));

  // ── Dowiązania ───────────────────────────────────────────────────────────
  t.section('dowiązania symboliczne');

  t.check('dowiązanie na zewnątrz (media z innego dysku) śledzone',
    !p.brak.includes('wp-content/link-media/m1.jpg'));
  t.check('dowiązania do „/" i do katalogu nad wp-content pominięte i zapisane',
    (p.log.dowiazania_nadrzedne || []).sort().join(',') === 'link-wyzej,plugins/foo/do-korzenia',
    JSON.stringify(p.log.dowiazania_nadrzedne));
  /* Druga pętla idzie przez dwa katalogi zewnętrzne (A → B → A) — bez
     pamięci skoków lista kręciłaby się bez końca (sonda ma bezpiecznik). */
  t.check('pętle wykryte: do przodka i przez dwa katalogi zewnętrzne',
    (p.log.petle || []).sort().join(',') === 'uploads/2026/petla,zewA/doB/doA',
    JSON.stringify(p.log.petle));
  t.check('zerwane dowiązanie pominięte i zapisane', (p.log.zerwane_dowiazania || []).includes('zerwany'));
  if (p.fifo) {
    t.check('plik specjalny (FIFO) pominięty i zapisany', (p.log.specjalne || []).includes('uploads/kolejka'));
  }

  // ── Wznawianie ───────────────────────────────────────────────────────────
  t.section('krok ubity: lista i pakowanie wznowione ze starego stanu');

  /* Lista: śmieci na końcu pliku i praca bez zapisanego stanu. Pakowanie:
     stan zapamiętany, gdy duży plik był w połowie, potem praca, której stan
     „przepadł". Wynik ma być identyczny z przebiegiem bez przerw. */
  const u = sonda('ubity');
  t.check('archiwum po wznowieniach = oczekiwany zbiór, co do bajtu', komplet(u), opis(u));
};
