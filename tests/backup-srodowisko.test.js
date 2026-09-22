/**
 * Backup — sprawdzenie środowiska serwera w zakładce Kopie zapasowe.
 *
 * Moduł kopii, który włącza się na serwerze bez ZipArchive, „działa" aż do
 * dnia, w którym trzeba coś przywrócić. Dlatego braki blokujące mają zapalać
 * się na czerwono i gasić włącznik — ale TYLKO przy włączaniu: moduł już
 * włączony musi dać się wyłączyć.
 *
 * Fakty o serwerze podstawia sonda (tests/php/backup-srodowisko.php), więc
 * serwer bez zip albo z nginx nie jest do tego potrzebny. Ocena i render
 * zakładki są prawdziwe.
 */

const { phpOutput } = require('./lib/harness');

function sonda(fakty, wlaczony) {
  return JSON.parse(phpOutput('backup-srodowisko.php',
    JSON.stringify(JSON.stringify(fakty || {})) + (wlaczony ? ' wlaczony' : '')));
}
const wiersz = (w, label) => w.checks.find((c) => c.label === label) || { status: 'BRAK WIERSZA' };
const wlacznik = (w) => (w.html.replace(/\s+/g, ' ').match(/<input[^>]*data-option="evk_backup"[^>]*>/) || [''])[0];
const statusy = (w) => w.checks.map((c) => c.label + ': ' + c.status).join(' | ');

module.exports = async function (t) {

  t.section('serwer w porządku: nic nie blokuje, włącznik aktywny');

  /* KONTROLA: bez niej „brak zip blokuje" przechodziłoby też dla oceny,
     która blokuje zawsze. */
  const dobry = sonda({});
  t.check('żaden wiersz nie jest błędem', !dobry.blocked, statusy(dobry));
  t.check('włącznik da się kliknąć', !/\bdisabled\b/.test(wlacznik(dobry)), wlacznik(dobry));
  t.check('bez czerwonej ramki nad tabelą', !dobry.html.includes('evo-info-box is-err'));
  t.check('tabela pokazuje wszystkie wiersze oceny',
    (dobry.html.match(/<tr class="is-/g) || []).length === dobry.checks.length,
    dobry.checks.length + ' wierszy oceny');

  /* Środowisko zwinięte w akordeon (1.226.1) — otwarte wyłącznie wtedy, gdy
     coś blokuje moduł: ramka błędu odsyła „do tabeli niżej", więc tabela
     musi być widoczna bez szukania. */
  const akordeon = (w) => (w.html.match(/<details class="evo-acc[^"]*evk-backup-srodowisko"[^>]*>/) || [''])[0];
  const podsum = (w) => ((w.html.match(/<span class="evo-acc-count">([^<]*)</) || [])[1] || '');
  t.check('akordeon zwinięty, gdy nic nie blokuje; podsumowanie „wszystko w porządku"',
    akordeon(dobry) !== '' && !/\bopen\b/.test(akordeon(dobry)) && podsum(dobry) === 'wszystko w porządku',
    akordeon(dobry) + ' / ' + podsum(dobry));

  t.section('braki blokujące');

  for (const [nazwa, fakty, label] of [
    ['brak ZipArchive', { zip: false }, 'ZipArchive'],
    ['brak zlib', { deflate: false }, 'Kompresja (zlib)'],
    ['Multisite', { multisite: true }, 'WordPress Multisite'],
    ['wp-content bez zapisu', { content_write: false }, 'Zapis do wp-content'],
  ]) {
    const w = sonda(fakty);
    t.check(nazwa + ': wiersz na czerwono i moduł zablokowany',
      wiersz(w, label).status === 'err' && w.blocked, statusy(w));
    t.check(nazwa + ': włącznik nieaktywny, ramka błędu widoczna',
      /\bdisabled\b/.test(wlacznik(w)) && w.html.includes('evo-info-box is-err'), wlacznik(w));
  }

  /* Moduł włączony wcześniej, a serwer zmienił się pod nim (np. hosting
     wyłączył zip) — musi dać się go wyłączyć. */
  const juzWlaczony = sonda({ zip: false }, true);
  t.check('moduł już włączony: włącznik aktywny mimo blokady (da się wyłączyć)',
    !/\bdisabled\b/.test(wlacznik(juzWlaczony)) && /\bchecked\b/.test(wlacznik(juzWlaczony)),
    wlacznik(juzWlaczony));

  const bezTabeli = sonda({ jobs_table: false }, true);
  t.check('włączony, a tabeli zadań nie ma: błąd z podpowiedzią o uprawnieniach',
    wiersz(bezTabeli, 'Tabela zadań').status === 'err', statusy(bezTabeli));
  t.check('bez faktu o tabeli (moduł wyłączony jej nie tworzy) wiersza nie ma',
    wiersz(dobry, 'Tabela zadań').status === 'BRAK WIERSZA');

  t.section('ostrzeżenia nie blokują');

  const zablokowany = sonda({ zip: false });
  t.check('blokada: akordeon otwarty, podsumowanie „blokuje moduł"',
    /\bopen\b/.test(akordeon(zablokowany)) && podsum(zablokowany) === 'blokuje moduł',
    akordeon(zablokowany) + ' / ' + podsum(zablokowany));

  const nginx = sonda({ server: 'nginx/1.24.0' });
  t.check('ostrzeżenie: akordeon zwinięty, podsumowanie „1 uwaga"',
    !/\bopen\b/.test(akordeon(nginx)) && podsum(nginx) === '1 uwaga', podsum(nginx));
  t.check('nginx: ostrzeżenie o .htaccess, moduł nie zablokowany',
    wiersz(nginx, 'Serwer WWW').status === 'warn' && !nginx.blocked, statusy(nginx));
  t.check('Apache: bez ostrzeżenia', wiersz(dobry, 'Serwer WWW').status === 'info');

  const pamiec = sonda({ memory_limit: '32M' });
  t.check('32 MB pamięci: ostrzeżenie', wiersz(pamiec, 'Limit pamięci').status === 'warn' && !pamiec.blocked);
  const bezLimitu = sonda({ memory_limit: '-1' });
  t.check('pamięć bez limitu (-1): bez ostrzeżenia, opisana słowami',
    wiersz(bezLimitu, 'Limit pamięci').status === 'info' && wiersz(bezLimitu, 'Limit pamięci').value === 'bez limitu (-1)',
    wiersz(bezLimitu, 'Limit pamięci').value);

  const dysk = sonda({ disk_free: 500 * 1048576 });
  t.check('500 MB wolnego: ostrzeżenie', wiersz(dysk, 'Wolne miejsce').status === 'warn' && !dysk.blocked);
  const dyskNieznany = sonda({ disk_free: null });
  t.check('hosting nie podaje miejsca: informacja, nie błąd',
    wiersz(dyskNieznany, 'Wolne miejsce').status === 'info' && !dyskNieznany.blocked);

  t.section('katalog kopii z sieci (wynik kanarka)');

  /* Samo sprawdzenie kanarkiem na prawdziwych serwerach HTTP jest
     w backup-katalog; tu — co z jego wyniku robi tabela. */
  const odsl = sonda({ dir_exposed: 'tak' });
  t.check('odsłonięty: ostrzeżenie, ale bez blokady (chroni losowa nazwa)',
    wiersz(odsl, 'Katalog kopii z sieci').status === 'warn' && !odsl.blocked, statusy(odsl));
  t.check('zablokowany: w porządku', wiersz(sonda({ dir_exposed: 'nie' }), 'Katalog kopii z sieci').status === 'ok');
  const niezn = sonda({ dir_exposed: 'nieznane' });
  t.check('nie sprawdzono: informacja z podpowiedzią o żądaniach do siebie',
    wiersz(niezn, 'Katalog kopii z sieci').status === 'info' && /WP-Cron/.test(wiersz(niezn, 'Katalog kopii z sieci').note));
  t.check('moduł wyłączony: wiersza nie ma (katalogu jeszcze nie ma)',
    wiersz(dobry, 'Katalog kopii z sieci').status === 'BRAK WIERSZA');

  t.section('odczyt wartości z php.ini');

  const b = dobry.bajty;
  t.check('128M, 1G, 512K, -1, pusty, 1000 → bajty',
    b['128M'] === 134217728 && b['1G'] === 1073741824 && b['512K'] === 524288
    && b['-1'] === -1 && b[''] === 0 && b['1000'] === 1000, JSON.stringify(b));
};
