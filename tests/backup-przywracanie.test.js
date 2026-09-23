/**
 * Backup — przywracanie (includes/backup/restore.php) na dwóch prawdziwych
 * WordPressach z tools/testowy-wp.sh: kopia ze stara.test (prefiks wp_),
 * przywrócenie na nowa.test (prefiks nowy_) w TEJ SAMEJ bazie.
 *
 * Przenosiny to najtrudniejszy przypadek: inny adres, inna ścieżka, inny
 * prefiks tabel, obce tabele i pliki na miejscu docelowym. Przywracanie na
 * tę samą stronę jest jego szczególnym przypadkiem (puste pary podmian).
 *
 * Każde przywrócenie idzie krokami z budżetem 1 ms — setki kroków, jak na
 * wolnym hostingu. Fakty o stronie docelowej zbiera ŚWIEŻY proces: pamięć
 * podręczna opcji w procesie, który przywracał, niczego nie dowodzi.
 */

const { phpOutput } = require('./lib/harness');

const sonda = (...a) => JSON.parse(phpOutput('backup-przywracanie.php', a.join(' ')));

module.exports = async function (t) {

  t.section('kopia na stara.test');

  const a = sonda('kopia_a');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !a.brak, a.brak || 'jest');
  if (a.brak) return;
  t.check('kopia źródłowa gotowa', a.status === 'done', a.status + (a.blad ? ': ' + a.blad : ''));
  const sumyPrzed = sonda('sumy_a');

  try {
    // ── Pełne przywrócenie, pliki nadpisywane ──────────────────────────────
    t.section('przywrócenie na nowa.test: baza i pliki, bez lustra');

    const p = sonda('przywroc', a.zip, 'all', '0');
    t.check('przywracanie skończone bez błędu, wieloma krokami',
      p.status === 'done' && p.krokow > 100, p.status + (p.blad ? ': ' + p.blad : '') + ', kroków ' + p.krokow);
    t.check('przeszło przez wszystkie fazy (bez lustra — bez usuwania)',
      ['r_check', 'r_extract', 'r_db', 'r_files', 'r_swap'].every((f) => p.fazy[f] > 0) && !p.fazy.r_sweep,
      JSON.stringify(p.fazy));

    const f = sonda('fakty_b');
    t.check('adres strony docelowej zostaje (home i siteurl nowa.test)',
      f.home === 'http://nowa.test' && f.siteurl === 'http://nowa.test', f.home + ' / ' + f.siteurl);
    t.check('adresy w treści podmienione: href, src, zakodowany w adresie',
      /href="http:\/\/nowa\.test\/o-nas\/"/.test(f.tresc) && /src="http:\/\/nowa\.test\/wp-content/.test(f.tresc)
        && /%2F%2Fnowa\.test%2Fy/.test(f.tresc) && !/stara\.test[^.]/.test(f.tresc), f.tresc);
    t.check('obca domena z tym samym początkiem nietknięta (stara.test.eu)',
      /stara\.test\.eu/.test(f.tresc) && f.ser.obce === 'https://stara.test.eu/', f.ser.obce);
    t.check('serializacja: opcja wciąż się odczytuje, adres i ścieżka podmienione',
      f.ser.url === 'http://nowa.test/sklep' && f.ser.plik === f.content_dir + '/uploads/evk-test/obraz.jpg'
        && f.ser.polski === 'zażółć http://nowa.test', JSON.stringify(f.ser));
    t.check('prefiks: role pod nowy_user_roles, uprawnienia pod nowy_capabilities, bez kluczy wp_',
      f.role.includes('administrator') && f.admin_z_a === true && f.stare_klucze === 0,
      'role ' + f.role.length + ', admin ' + f.admin_z_a + ', kluczy wp_: ' + f.stare_klucze);
    t.check('użytkownicy z kopii (admin ze stara.test), bez użytkowników sprzed', f.nowy_z_b === false);
    t.check('tabela spoza kopii usunięta, bez tabel roboczych', f.obca === false && !f.robocze.length,
      'obca: ' + f.obca + ', robocze: ' + f.robocze.join(', '));
    t.check('cudza instalacja z prefiksem nowy_sklep_ nietknięta i nie w zrzucie',
      f.sklep === 3 && f.sklep_w_zrzucie === false, JSON.stringify({ sklep: f.sklep, w_zrzucie: f.sklep_w_zrzucie }));
    t.check('BLOB spoza UTF-8 co do bajtu; NULL i pusty łańcuch rozróżnione',
      f.blob_md5 === a.blob_md5 && f.nul.nul === null && f.nul.pusty === '', JSON.stringify(f.nul));
    t.check('tabele z kluczem i bez: wszystkie wiersze, duplikaty z danych zachowane',
      f.klucz === 1200 && f.bezpk === 1200 && f.bezpk_rozne === 120 && f.klucz_url === 'wiersz http://nowa.test/7',
      JSON.stringify({ klucz: f.klucz, bezpk: f.bezpk, rozne: f.bezpk_rozne }));
    t.check('zostaje z instalacji docelowej: katalog kopii, klucz, wtyczka aktywna, moduł włączony',
      f.dir_zostal && f.key_zostal && f.wtyczka && f.modul && f.pliki.katalog_kopii,
      JSON.stringify({ dir: f.dir_zostal, key: f.key_zostal, wtyczka: f.wtyczka, modul: f.modul }));
    /* Kopia robiona z trybem konserwacji na czas zrzutu ma go WŁĄCZONEGO
       w zrzucie — po przywróceniu strona ma wrócić do stanu sprzed kopii. */
    t.check('tryb konserwacji: stan sprzed kopii, nie ten złapany w zrzucie', f.maint === '', JSON.stringify(f.maint));
    t.check('zadanie przywracania zapisane jako skończone (tabela zadań przeżyła podmianę)',
      JSON.stringify(f.zadanie) === '["restore","done"]', JSON.stringify(f.zadanie));
    t.check('pliki z kopii na miejscu, co do bajtu',
      f.pliki.plik === 'treść zażółć\n' && f.pliki.obraz_md5 === a.obraz_md5);
    t.check('bez lustra pliki spoza kopii zostają; dowiązanie wtyczki nietknięte',
      f.pliki.obcy && f.pliki.obcy_kat && f.pliki.wtyczka_link, JSON.stringify(f.pliki));

    const sumyPo = sonda('sumy_a');
    const zmienione = Object.keys(sumyPrzed).filter((k) => sumyPrzed[k] !== sumyPo[k]);
    t.check('tabele stara.test (ta sama baza) nietknięte — sumy kontrolne',
      !zmienione.length && Object.keys(sumyPrzed).length > 10, zmienione.join(', ') || Object.keys(sumyPrzed).length + ' tabel');

    // ── Lustro + krok ginący między porcją a zapisem stanu ─────────────────
    t.section('lustro i krok ubity w połowie importu');

    const l = sonda('przywroc', a.zip, 'all', '1', 'powtorka');
    t.check('przywracanie z lustrem skończone', l.status === 'done' && l.fazy.r_sweep > 0,
      l.status + (l.blad ? ': ' + l.blad : '') + ' ' + JSON.stringify(l.fazy));
    t.check('porcja wysłana, stan cofnięty — w tabeli z kluczem i bez',
      l.cofniete.wp_evk_test_bezpk === 500 && l.cofniete.wp_evk_test_klucz === 500, JSON.stringify(l.cofniete));
    const fl = sonda('fakty_b');
    t.check('…i żadnego zdublowanego wiersza (REPLACE z kluczem, od nagłówka bez klucza)',
      fl.klucz === 1200 && fl.bezpk === 1200 && fl.bezpk_rozne === 120, JSON.stringify({ klucz: fl.klucz, bezpk: fl.bezpk }));
    t.check('lustro usunęło pliki i katalog spoza kopii',
      !fl.pliki.obcy && !fl.pliki.obcy_kat, JSON.stringify(fl.pliki));
    t.check('…ale zostawiło wykluczenia kopii (debug.log, cache/) i dowiązanie wtyczki',
      fl.pliki.debug_log && fl.pliki.cache && fl.pliki.wtyczka_link && fl.pliki.plik === 'treść zażółć\n');
    t.check('…i drop-in tego serwera (db-error.php), którego w kopii nie ma', fl.pliki.db_error === '<?php // serwer B',
      JSON.stringify(fl.pliki.db_error));

    // ── Zakresy ────────────────────────────────────────────────────────────
    t.section('zakres: tylko baza, tylko pliki');

    const d = sonda('przywroc', a.zip, 'db', '0');
    const fd = sonda('fakty_b');
    t.check('tylko baza: baza podmieniona, pliki nietknięte',
      d.status === 'done' && !d.fazy.r_files && fd.znacznik === false && fd.pliki.plik === false && fd.pliki.obcy,
      JSON.stringify({ status: d.status, blad: d.blad, znacznik: fd.znacznik, plik: fd.pliki.plik, obcy: fd.pliki.obcy }));

    const pl = sonda('przywroc', a.zip, 'files', '0');
    const fp = sonda('fakty_b');
    t.check('tylko pliki: pliki z kopii, baza nietknięta (znacznik i obca tabela zostają)',
      pl.status === 'done' && !pl.fazy.r_db && fp.znacznik === 'b' && fp.obca === true && fp.pliki.plik === 'treść zażółć\n'
        && fp.maint === '', JSON.stringify({ status: pl.status, blad: pl.blad, znacznik: fp.znacznik, obca: fp.obca, maint: fp.maint }));

    // ── Wpisy, których nie wolno zapisać ───────────────────────────────────
    t.section('archiwum z wpisami spoza strony i z katalogiem tej wtyczki');

    const z = sonda('zlosliwe', a.zip);
    t.check('przywracanie przechodzi, zwykły plik na miejscu', z.status === 'done' && z.ok === 'ok',
      z.status + (z.blad ? ': ' + z.blad : ''));
    t.check('katalog tej wtyczki nietknięty (kod, który przywraca, nie podmienia sam siebie)', z.wtyczka === false);
    t.check('„../" nie wychodzi poza wp-content', z.wyzej === false);
    /* Zgłoszone z użycia (1.227.1): object-cache.php starego serwera
       zatrzymywał każde żądanie, także kroki przywracania. */
    t.check('drop-iny z kopii pominięte: bez object-cache.php, db-error.php tego serwera zostaje',
      z.object_cache === false && z.db_error === '<?php // serwer B' && z.log.some((l) => /drop-in.*object-cache\.php/.test(l)),
      JSON.stringify({ oc: z.object_cache, db: z.db_error }));
    t.check('pliki z katalogu głównego tylko do podglądu (.htaccess bez zmian), katalogi kopii pominięte',
      z.htaccess === true && z.kopia === false && z.log.length >= 2, JSON.stringify(z.log));

    // ── Anulowanie ─────────────────────────────────────────────────────────
    t.section('anulowanie w trakcie importu');

    const n = sonda('anuluj', a.zip);
    t.check('anulowane w połowie bazy: tabele tymczasowe i katalog roboczy sprzątnięte, strona bez zmian',
      n.status === 'cancelled' && !n.tabele_tymczasowe.length && !n.praca && n.home === 'http://nowa.test' && n.obca
        && n.maint === '', JSON.stringify(n));
  } finally {
    sonda('sprzataj_a');
  }
};
