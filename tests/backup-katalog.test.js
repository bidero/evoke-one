/**
 * Backup — katalog kopii, jego ochrona, sprawdzenie „kanarkiem" i manifest
 * (includes/backup/storage.php, manifest.php). Na prawdziwym WordPressie
 * z tools/testowy-wp.sh — bez niego test zapala się na czerwono.
 *
 * Kopia niesie zrzut bazy (hashe haseł, adresy subskrybentów), więc pytanie
 * „czy da się ją pobrać z sieci" nie może być założeniem. Kanarek odpowiada
 * na nie na TYM serwerze — tu sprawdzamy, że odpowiada poprawnie na trzech
 * prawdziwych serwerach HTTP o różnym zachowaniu.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {

  t.section('katalog kopii: losowa nazwa i pliki ochronne');

  const k = JSON.parse(phpOutput('backup-katalog.php', 'katalog'));
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !k.brak, k.brak || 'jest');
  if (k.brak) return;

  t.check('sufiks 20 znaków szesnastkowych, stały między wywołaniami',
    k.sufiks_format && k.sufiks_staly, k.sufiks);
  t.check('inna instalacja (opcja usunięta) dostaje inny sufiks', k.sufiks_inny_po_usunieciu === true);
  t.check('katalog w wp-content, pliki ochronne: .htaccess, index.php, web.config',
    k.utworzony && k.w_wp_content && Object.values(k.pliki).every(Boolean), JSON.stringify(k.pliki));
  t.check('.htaccess w obu składniach (Apache 2.4 i 2.2 / LiteSpeed)', k.htaccess_obie_skladnie === true);
  t.check('ręczna zmiana w .htaccess nie jest nadpisywana', k.htaccess_nie_nadpisany === true);
  t.check('katalog kopii i katalog importu wykluczone z kopii (kopia kopii)',
    k.wykluczony_katalog === true && k.wykluczony_import === true);

  t.section('kanarek: czy plik z katalogu kopii da się pobrać z sieci');

  /* Trzy prawdziwe serwery (php -S) i zamknięty port. Wynik „nie wiadomo"
     ma być null, a nie false — false mówiłby „bezpiecznie", choć nic nie
     zostało sprawdzone. */
  const c = JSON.parse(phpOutput('backup-katalog.php', 'kanarek'));
  t.check('serwer ignorujący .htaccess (jak nginx): wykryty jako ODSŁONIĘTY', c.bez_htaccess === true, String(c.bez_htaccess));
  t.check('serwer odmawiający (403, jak Apache z .htaccess): zablokowany', c.z_htaccess === false, String(c.z_htaccess));
  t.check('serwer nie odpowiada: „nie wiadomo", a nie „bezpiecznie"', c.bez_serwera === null, String(c.bez_serwera));
  t.check('200 z inną treścią (strona błędu) to nie odsłonięcie', c.inna_tresc === false, String(c.inna_tresc));
  t.check('kanarki sprzątnięte po każdym sprawdzeniu', c.kanarki_zostaly === 0, c.kanarki_zostaly + ' zostało');

  t.section('manifest');

  const m = JSON.parse(phpOutput('backup-katalog.php', 'manifest'));
  const zle = Object.entries(m.zgodne).filter(([, v]) => !v).map(([k]) => k);
  t.check('siteurl, home, ABSPATH, prefiks, wp-content zgodne z instalacją', !zle.length, zle.join(', ') || 'komplet');
  t.check('stałe z prawdziwego wp-config.php: nazwy', m.stale_prawdziwe.includes('DB_NAME') && m.stale_prawdziwe.includes('AUTH_KEY'),
    m.stale_prawdziwe.length + ' nazw');
  /* Tokenizer, nie regex: define() w komentarzu i w łańcuchu to nie stała. */
  t.check('stałe z pliku: tylko prawdziwe definicje (bez komentarzy i łańcuchów)',
    JSON.stringify(m.stale_z_pliku) === JSON.stringify(['DB_PASSWORD', 'EVK_GITHUB_TOKEN', 'WP_MEMORY_LIMIT']),
    JSON.stringify(m.stale_z_pliku));
  t.check('wartości stałych (hasło, token) nie trafiają do manifestu', m.wartosci_wyciekly === false);
  t.check('podgląd z katalogu głównego: .htaccess, robots, weryfikacje — bez wp-config, index, readme i za dużych',
    JSON.stringify(m.podglad) === JSON.stringify(['_root/.htaccess', '_root/BingSiteAuth.xml', '_root/google1a2b3c.html', '_root/robots.txt']),
    JSON.stringify(m.podglad));
  /* WordPress w katalogu głównym serwera (ABSPATH „/"): do 1.231.0 każda
     kopia padała zaraz po zrzucie bazy na scandir('') — zgłoszone z Apache. */
  const bezWyjatku = (w) => Array.isArray(w) && w.every((p) => /^\/[^/]/.test(p));
  t.check('podgląd przy WordPressie w „/" i „//": bez wyjątku, ścieżki od jednego „/"',
    bezWyjatku(m.podglad_korzen) && bezWyjatku(m.podglad_korzen_podwojny),
    JSON.stringify([m.podglad_korzen, m.podglad_korzen_podwojny]));
};
