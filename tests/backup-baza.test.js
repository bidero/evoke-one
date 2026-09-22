/**
 * Backup — zrzut bazy do JSONL (includes/backup/db-dump.php).
 *
 * WYMAGA PRAWDZIWEJ BAZY. Atrapa $wpdb nie odpowie na pytania, o które tu
 * chodzi: SHOW CREATE TABLE, porządek kolacji przy stronicowaniu po kluczu
 * tekstowym, to, co MySQL oddaje z kolumny BLOB. Środowisko stawia jedno
 * polecenie: `tools/testowy-wp.sh`. Bez niego test ZAPALA SIĘ NA CZERWONO,
 * a nie jest po cichu pomijany — tak samo jak brak PHPStana w drobiazgach.
 *
 * Kryterium jest jedno i twarde: każdy wiersz odczytany z pliku (po
 * zdekodowaniu base64) ma być IDENTYCZNY z tym, co oddaje SELECT — ta sama
 * kolejność, NULL odróżniony od pustego łańcucha, bajt w bajt.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {

  t.section('środowisko: testowy WordPress z prawdziwą bazą');

  const pelny = JSON.parse(phpOutput('backup-baza.php', 'pelny'));
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !pelny.brak, pelny.brak || 'jest');
  if (pelny.brak) return;

  // ── Zgodność z bazą ──────────────────────────────────────────────────────
  t.section('każdy wiersz z pliku = SELECT z bazy');

  const tabele = Object.entries(pelny.tabele);
  const nierowne = tabele.filter(([, v]) => !v.rowne)
    .map(([k, v]) => k + (v.brak_naglowka ? ' (bez nagłówka)' : ' (' + v.w_zrzucie + '/' + v.w_bazie + ')'));
  t.check('wszystkie tabele zgodne co do wiersza', !nierowne.length,
    nierowne.join(', ') || tabele.length + ' tabel, ' + pelny.wierszy_stan + ' wierszy');

  /* Kontrola, że porównanie w ogóle miało na czym działać — fikstury są
     w zrzucie i mają wiersze w liczbie większej niż jedna paczka. */
  const fik = (nazwa) => pelny.tabele[pelny.fikstury.find((f) => f.endsWith(nazwa))] || {};
  t.check('fikstury obecne i dłuższe niż paczka (50)',
    fik('_mix').w_bazie === 438 && fik('_comp').w_bazie === 131 && fik('_nopk').w_bazie === 123
    && fik('_strpk').w_bazie === 72,
    JSON.stringify({ mix: fik('_mix').w_bazie, comp: fik('_comp').w_bazie,
      nopk: fik('_nopk').w_bazie, strpk: fik('_strpk').w_bazie }));
  t.check('zrzut szedł wieloma krokami (każdy jedna paczka)', pelny.krokow > 20, pelny.krokow + ' kroków');
  t.check('rdzeń WordPressa w zrzucie (options, posts, users)',
    ['options', 'posts', 'users'].every((n) => pelny.w_stanie.some((x) => x.endsWith('_' + n))));

  // ── Kodowanie ────────────────────────────────────────────────────────────
  t.section('kodowanie wartości');

  /* BLOB z losowymi bajtami nie jest UTF-8 — bez base64 json_encode oddałby
     false, a wiersz zginąłby bez śladu. Zgodność z bazą wyżej mówi, że
     wróciły bajt w bajt; tu, że ścieżka base64 naprawdę zadziałała. */
  t.check('wartości spoza UTF-8 idą przez base64', pelny.b64 > 300, pelny.b64 + ' wartości');
  t.check('plik to czysty JSONL: każda linia się dekoduje, nagłówek przed wierszami',
    pelny.zle_linie === 0 && pelny.kolejnosc_zla.length === 0,
    pelny.zle_linie + ' złych linii; ' + pelny.kolejnosc_zla.join(', '));
  t.check('kolumna generowana opisana w nagłówku i pominięta w wierszach',
    pelny.generowane.includes('podwojone') && pelny.generowana_w_wierszach === false,
    JSON.stringify(pelny.generowane));
  t.check('nagłówek niesie klucz główny: prosty, złożony, brak',
    JSON.stringify(pelny.pk) === JSON.stringify({ mix: ['id'], comp: ['a', 'b'], nopk: [] }),
    JSON.stringify(pelny.pk));
  t.check('nagłówek niesie CREATE TABLE', pelny.create_ma_nazwe === true);

  // ── Co NIE trafia do zrzutu ──────────────────────────────────────────────
  t.section('czego w zrzucie nie ma');

  t.check('tabela zadań kopii pominięta (restore nie może jej nadpisać)', pelny.zadania_w_zrzucie === false);
  /* `_` w `wp_` jest w LIKE znakiem dowolnym — bez esc_like złapałaby się
     tabela innej instalacji w tej samej bazie. */
  t.check('tabela innej instalacji (wpX…) pominięta mimo LIKE', pelny.obcy_w_zrzucie === false);
  t.check('widok nie jest zrzucany, tylko wymieniony jako pominięty',
    pelny.pominiete.includes(pelny.widok) && !pelny.w_stanie.includes(pelny.widok),
    JSON.stringify(pelny.pominiete));

  // ── Wznawianie ───────────────────────────────────────────────────────────
  t.section('krok ubity przed zapisem stanu: wznowienie bez duplikatów');

  const u = JSON.parse(phpOutput('backup-baza.php', 'ubity'));
  t.check('scenariusz zostawił dane i śmieci za stanem zatwierdzonym',
    u.przed_wznowieniem > u.stan_bytes + 1000, u.przed_wznowieniem + ' B na dysku, stan: ' + u.stan_bytes + ' B');
  const nierowneU = Object.entries(u.tabele).filter(([, v]) => !v.rowne).map(([k]) => k);
  t.check('po wznowieniu każdy wiersz zgodny z bazą (bez powtórzeń)',
    !nierowneU.length && u.zle_linie === 0 && u.kolejnosc_zla.length === 0,
    nierowneU.join(', ') || u.linii + ' linii');
  t.check('liczba linii taka jak w zrzucie bez przerwy', u.linii === pelny.linii, u.linii + ' vs ' + pelny.linii);

  // ── Pamięć ───────────────────────────────────────────────────────────────
  t.section('duże wiersze: paczka mniejsza PRZED pobraniem');

  /* 1000 drobnych wierszy, potem 60 po 1 MB. Zmierzone przed ustawieniem
     progu: z dopasowaniem paczki po LENGTH() szczyt +10,2 MB, bez niego
     (stała paczka 1000) +179,7 MB — czyli fatal na hostingu z limitem
     128 MB. Próg 32 MB (osiem paczek po 4 MB) leży daleko od obu. */
  const m = JSON.parse(phpOutput('backup-baza.php', 'pamiec'));
  t.check('wszystkie wiersze zrzucone', m.wierszy === 1060, m.wierszy + ' wierszy');
  t.check('szczyt pamięci zrzutu poniżej 32 MB', m.szczyt_mb < 32,
    '+' + m.szczyt_mb + ' MB (paczka ' + m.paczka_mb + ' MB' + (m.reset_szczytu ? '' : ', PHP < 8.2: szczyt z całej sondy') + ')');
};
