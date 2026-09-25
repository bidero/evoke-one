/**
 * Dwie kopie Evoke ONE aktywne naraz (1.233.1) na PRAWDZIWYM WordPressie.
 *
 * Zgłoszenie z testowa.evoke.pl: po przywróceniu kopii zapasowej aktywne były
 * „evoke-one-main" (z kopii) i kopia z gałęzi roboczej. Następne żądanie
 * kończyło się błędem krytycznym „Cannot redeclare function
 * evoke_one_check_conflicts()". Przyczyny dwie:
 *   — przywracanie zostawiało aktywną każdą kopię, której plik istniał,
 *   — plik główny deklarował funkcję na najwyższym poziomie, a PHP wiąże ją
 *     przy KOMPILACJI — druga kopia padała, zanim cokolwiek się wykonało.
 *
 * Sonda: tests/php/zapis-wp-dwie-kopie.php (wczytanie strony w osobnym
 * procesie, żeby błąd krytyczny nie zabrał sondy).
 */

const fs = require('fs');
const path = require('path');
const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const surowe = phpOutput('zapis-wp-dwie-kopie.php', '', { dopuscBlad: true });
  let s;
  try { s = JSON.parse(surowe); } catch (e) { s = { brak: 'sonda nie oddała JSON-a: ' + surowe.slice(0, 300) }; }
  const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);

  t.section('środowisko: testowy WordPress z prawdziwą bazą');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !s.brak, s.brak || 'jest');
  if (s.brak) return;
  t.check('warunek testu: stara kopia to 1.233.0 z historii gita', s.stara_wersja === true, JSON.stringify(s.stara_wersja));

  // ── Plik główny bez deklaracji ──────────────────────────────────────────
  /* Strażnik drugiej kopii działa tylko wtedy, gdy plik główny NIE deklaruje
     funkcji ani klas na najwyższym poziomie — PHP wiąże je przy kompilacji,
     przed pierwszą instrukcją pliku. */
  t.section('plik główny: bez deklaracji wiązanych przy kompilacji');
  const glowny = fs.readFileSync(path.join(__dirname, '..', 'evoke-one.php'), 'utf8');
  const deklaracje = glowny.match(/^(?:abstract\s+|final\s+)?(?:function|class|interface|trait|enum)\s+\w+/gm) || [];
  t.check('evoke-one.php nie deklaruje funkcji ani klas', deklaracje.length === 0, deklaracje.join(', ') || 'brak');
  const straznik = glowny.indexOf("if (defined('EVOKE_ONE_FILE'))");
  const pierwszyDefine = glowny.indexOf("define('EVOKE_ONE_FILE'");
  t.check('strażnik drugiej kopii stoi przed pierwszą stałą', straznik > 0 && straznik < pierwszyDefine,
    JSON.stringify({ straznik, pierwszyDefine }));

  // ── Wczytanie strony z dwiema kopiami ───────────────────────────────────
  const wczytanie = (klucz, opis, dziala, dopuscOstrzezenia) => {
    const w = s[klucz] || {};
    const r = w.wynik || {};
    t.check(opis + ': strona się wczytuje, bez błędu krytycznego', w.kod === 0 && w.fatal === false && !!w.wynik,
      w.blad || ('kod ' + w.kod));
    t.check(opis + ': działa jedna kopia, z modułami', r.dziala === dziala && r.moduly === true, JSON.stringify([r.dziala, r.moduly]));
    t.check(opis + ': jeden komunikat w panelu o drugiej kopii', r.komunikatow === 1 && /więcej niż raz/.test(r.komunikat || ''),
      (r.komunikat || '').slice(0, 160));
    if (!dopuscOstrzezenia) {
      t.check(opis + ': druga kopia kończy przed pierwszą stałą (bez ostrzeżeń PHP)', w.ostrzezenia === 0, String(w.ostrzezenia));
    }
  };
  t.section('dwie bieżące kopie, w obu kolejnościach');
  wczytanie('nowa_pierwsza', 'kopia z innego katalogu pierwsza', 'evk-t-nowa-kopia/evoke-one.php', false);
  wczytanie('nowa_druga', 'kopia z innego katalogu druga', 'evoke-one/evoke-one.php', false);

  /* Tak było na testowa.evoke.pl: „evoke-one-claude-…" przed „evoke-one-main"
     w kolejności wczytywania, czyli bieżąca pierwsza, stara druga. Stara nie
     ma strażnika — ratuje ją nowa nazwa funkcji kolizji w bieżącej
     (evoke_one_kolizje), a jej ostrzeżenia o stałych to cena, nie błąd. */
  t.section('bieżąca i stara 1.233.0 (tak jak na testowa.evoke.pl)');
  wczytanie('stara_druga', 'stara druga', 'evoke-one/evoke-one.php', true);
  wczytanie('stara_pierwsza', 'stara pierwsza', 'evk-t-stara-kopia/evoke-one.php', false);
  t.check('stara pierwsza: komunikat mówi, że bieżąca się nie uruchomiła',
    /evoke-one\/evoke-one\.php się nie uruchomiła/.test(((s.stara_pierwsza || {}).wynik || {}).komunikat || ''),
    (((s.stara_pierwsza || {}).wynik || {}).komunikat || '').slice(0, 200));

  // ── Przywracanie ────────────────────────────────────────────────────────
  t.section('przywracanie kopii: aktywna zostaje jedna kopia Evoke ONE');
  t.check('druga kopia, która LEŻY na dysku, też wyłączona (tak było na testowa.evoke.pl)',
    s.istnieje_przy_liczeniu === true
      && jak(s.po_przywroceniu_istniejaca, [['akismet/akismet.php', 'evoke-one/evoke-one.php'], ['evk-t-stara-kopia/evoke-one.php']]),
    JSON.stringify([s.istnieje_przy_liczeniu, s.po_przywroceniu_istniejaca]));
  t.check('inne kopie z kopii zapasowej wyłączone, reszta wtyczek zostaje, przywracająca aktywna',
    jak(s.po_przywroceniu, [['akismet/akismet.php', 'evoke-one-claude-x/evoke-one.php'], ['evoke-one-main/evoke-one.php', 'evoke-one.php']]),
    JSON.stringify(s.po_przywroceniu));
  t.check('przywracającej nie ma na liście z kopii — zostaje dopisana',
    jak(s.po_przywroceniu_bez_siebie, [['akismet/akismet.php', 'evoke-one/evoke-one.php'], ['evoke-one-main/evoke-one.php']]),
    JSON.stringify(s.po_przywroceniu_bez_siebie));
};
