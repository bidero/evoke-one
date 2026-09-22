/**
 * Backup — silnik zadań (includes/backup/engine.php) na prawdziwym
 * WordPressie z tools/testowy-wp.sh.
 *
 * Najważniejsze tu są scenariusze śmierci kroku, bo tak wygląda codzienność
 * hostingu: proces ZABITY z zewnątrz (limit nginx/LiteSpeed/FPM — PHP nic
 * nie widzi i nic nie zapisuje), FATAL z braku pamięci, limit czasu PHP przy
 * zablokowanym set_time_limit(). W każdym zadanie ma przeżyć, skrócić kroki
 * i skończyć się archiwum zgodnym ze źródłem.
 */

const { phpOutput } = require('./lib/harness');

const sonda = (scen) => JSON.parse(phpOutput('backup-silnik.php', scen));
/** Archiwum kompletne: manifest pierwszy, zrzut drugi, treść na miejscu, bez tego, czego być nie ma. */
const archiwumOk = (f) => f && f.otwarte && f.pierwszy === 'manifest.json' && f.drugi === 'database.jsonl'
  && f.wierszy_db === f.manifest_rows && f.wierszy_db > 50 && f.tabel_db > 10
  && f.ma_index && f.ma_motyw && !f.ma_wtyczke && !f.ma_kopie;

module.exports = async function (t) {

  t.section('pełna kopia krok po kroku');

  const p = sonda('pelny');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !p.brak, p.brak || 'jest');
  if (p.brak) return;

  t.check('zadanie skończone bez błędu', p.status === 'done', p.status + (p.blad ? ': ' + p.blad : ''));
  t.check('szło wieloma krokami (budżet 1 ms — porcja na krok)', p.krokow > 100, p.krokow + ' kroków');
  t.check('archiwum kompletne: manifest, zrzut, pliki; bez wtyczki wykluczonej i bez katalogów kopii',
    archiwumOk(p.fakty), JSON.stringify(p.fakty));
  t.check('nazwa: host-data-reczna-losowe.zip', p.nazwa_ok === true);
  t.check('opis obok archiwum: źródło, nieprzypięta, rozmiar zgodny', p.meta.source === 'manual'
    && p.meta.pinned === false && p.meta.size_ok, JSON.stringify(p.meta));
  t.check('po sobie sprzątnięte: bez .part, bez katalogu roboczego, bez zapasowego eventu, lock zdjęty',
    !p.part_zostal && !p.praca_zostala && !p.zaplanowany && p.lock === 0);
  t.check('postęp domknięty do 100%', p.postep[0] === p.postep[1] && p.postep[1] > 0, p.postep.join(' / '));
  t.check('kopia na liście', p.na_liscie === true);

  t.section('lock: jeden krok naraz');

  const l = sonda('lock');
  t.check('zajęty lock: krok nic nie robi', l.zajety_krok === true);
  t.check('wolny lock: krok pracuje i zdejmuje lock na końcu', l.wolny_krok === true && l.lock_zdjety_po_kroku === true);

  const z = sonda('zajety');
  t.check('druga kopia w trakcie pierwszej odrzucona z komunikatem', z.drugi_odrzucony && /już trwa/.test(z.komunikat), z.komunikat);
  t.check('po anulowaniu można zacząć nową', z.po_anulowaniu_mozna === true);

  // ── Śmierć kroku ─────────────────────────────────────────────────────────
  t.section('krok ubity: wykrycie, krótsze kroki, kopia i tak cała');

  const u = sonda('ubity');
  t.check('ubity poprzednik wykryty po locku, budżet 20 s → 10 s',
    u.log_przerwany === true && u.budzet_po === 10000, 'budżet ' + u.budzet_po + ' ms');
  t.check('udany krok zeruje licznik ubitych', u.kills_po_udanym === 0);
  t.check('kopia po tym kompletna', u.status === 'done' && archiwumOk(u.fakty), JSON.stringify(u.fakty));
  t.check('po 8 ubitych z rzędu zadanie się poddaje z czytelnym błędem',
    u.poddanie.status === 'failed' && /8 razy/.test(u.poddanie.blad || ''), JSON.stringify(u.poddanie));

  /* SIGKILL po 1,5 s w środku deflate pliku 60 MB: PHP nie wie nic, nic nie
     zapisuje, na dysku zostają dane za stanem zatwierdzonym. */
  const k = sonda('zabity');
  t.check('zabity z zewnątrz (SIGKILL): lock zostaje, następny krok to rozpoznaje',
    k.lock_zostal === true && k.log_wykryty === true && k.budzet_po_wykryciu === 10000, JSON.stringify(k));
  /* Postęp na bieżąco (1.226.1, zgłoszone: „pasek musi się odświeżać
     znacznie częściej"): dwie osobne przyczyny, dwa osobne sprawdzenia. */
  t.check('krok w połowie dużego pliku wlicza do postępu jego przeczytane bajty',
    k.wisi_duzy === true && k.wisi_bajtow > 0 && k.postep_z_polowy === k.wisi_bajtow,
    '+' + k.postep_z_polowy + ' B postępu, ' + k.wisi_bajtow + ' B pliku w połowie');
  t.check('krok zabity po 1,5 s zdążył zapisać postęp (zapis po każdej porcji ≤ 1 s)',
    k.postep_w_zabitym === true);
  t.check('plik przerwany w połowie ma w archiwum dobry rozmiar i CRC',
    k.status === 'done' && k.duzy_w_archiwum === true && archiwumOk(k.fakty));

  const m = sonda('pamiec');
  t.check('fatal z braku pamięci trafia do logu zadania', m.fatal_w_wyjsciu === true && m.log_fatal === true, JSON.stringify(m));
  t.check('…i zadanie po nim kończy się kompletną kopią', m.status === 'done' && m.duzy_w_archiwum === true && archiwumOk(m.fakty));

  /* set_time_limit() wyłączone (w PHP 8 funkcja wtedy NIE ISTNIEJE),
     max_execution_time = 2 s. Bez przycięcia budżetu ten krok ginie fatalem —
     sprawdzone mutacją. */
  const lp = sonda('limit_php');
  t.check('limit PHP przy zablokowanym set_time_limit: krok kończy się sam, bez fatala',
    lp.fatal === false && lp.krok_zakonczony === true && lp.postep === 'pack', JSON.stringify(lp));

  // ── Anulowanie, konserwacja, retencja ────────────────────────────────────
  t.section('anulowanie');

  const a = sonda('anuluj');
  t.check('anulowanie w trakcie pakowania sprząta .part i katalog roboczy',
    a.part_przed && a.anulowane && a.status === 'cancelled' && !a.part_po && !a.praca_po, JSON.stringify(a));
  t.check('po anulowaniu krok nic nie robi, drugie anulowanie odmawia',
    a.krok_po_anulowaniu === true && a.drugie_anulowanie === false);

  t.section('tryb konserwacji na czas zrzutu bazy');

  const ko = sonda('konserwacja');
  t.check('wyłączona przed: włączona w trakcie, wyłączona po bazie i po anulowaniu',
    JSON.stringify(ko['wyłączona']) === JSON.stringify({ w_trakcie: '1', po_bazie: '', po_anulowaniu: '' }),
    JSON.stringify(ko['wyłączona']));
  t.check('włączona przed: zostaje włączona (kopia nie wyłącza cudzej konserwacji)',
    JSON.stringify(ko['włączona']) === JSON.stringify({ w_trakcie: '1', po_bazie: '1', po_anulowaniu: '1' }),
    JSON.stringify(ko['włączona']));

  t.section('retencja: limit w sztukach, przypięte i nieliczone nietykalne');

  const r = sonda('retencja');
  t.check('limit 2: usunięte trzy najstarsze ręczne/nocne',
    JSON.stringify(r.usuniete) === JSON.stringify(['test-r1.zip', 'test-r2.zip', 'test-r3.zip']), JSON.stringify(r.usuniete));
  t.check('zostały: dwie najnowsze, przypięta, sprzed przywrócenia i wgrana z zewnątrz',
    JSON.stringify(r.zostaly) === JSON.stringify(['test-r4.zip', 'test-r5.zip', 'test-snapshot.zip', 'test-stara-przypieta.zip', 'test-wgrana.zip']),
    JSON.stringify(r.zostaly));
  t.check('opisy usuniętych kopii usunięte razem z nimi', r.opisy_usunietych === 0);
  t.check('przypięcie zdejmuje kopię z rotacji', Array.isArray(r.po_przypieciu) && r.po_przypieciu.length === 0);

  t.section('nazwa kopii z żądania: wyłącznie pliki z katalogu kopii');

  const n = sonda('nazwy');
  const zle = Object.entries(n).filter(([k, v]) => v !== (k === 'prawdziwa-kopia.zip')).map(([k]) => k);
  t.check('przyjęta tylko istniejąca kopia; ../, ukośnik, .htaccess, .json, bajt zerowy odrzucone',
    !zle.length, zle.join(', ') || 'komplet');
};
