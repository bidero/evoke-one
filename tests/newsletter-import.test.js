/**
 * Import subskrybentów z podglądem, mapowaniem kolumn i listą wykluczeń
 * (1.233.0) na PRAWDZIWYM WordPressie. Sonda: tests/php/newsletter-import.php.
 *
 * Do 1.232.x import był jednym kliknięciem: plik szedł od razu do bazy, brany
 * był sam adres, a o wyniku mówiły cztery liczby po fakcie. Wykluczani byli
 * tylko wypisani z TEJ listy i usunięci na żądanie RODO.
 *
 * Środowisko: `tools/testowy-wp.sh`. Bez niego test zapala się na czerwono.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const sonda = (scenariusz) => {
    const surowe = phpOutput('newsletter-import.php', scenariusz, { dopuscBlad: true });
    try { return JSON.parse(surowe); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + surowe.slice(0, 300) }; }
  };
  const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);
  // Mapa z PHP: pola jako obiekt albo lista (indeksy od zera) — porównujemy treść.
  const pola = (m) => Object.fromEntries(Object.entries((m && m.pola) || {}));

  t.section('środowisko: testowy WordPress z prawdziwą bazą');
  const tb = sonda('tabela');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !tb.brak, tb.brak || 'jest');
  if (tb.brak) return;

  // ── Czytanie pliku ──────────────────────────────────────────────────────
  t.section('plik: nagłówek, kolumna adresu, znaczniki z nagłówków');
  const ex = tb.excel || {};
  t.check('polski Excel (BOM, średnik): nagłówek, dwa wiersze',
    jak(ex.naglowek, ['E-mail', 'Imię', 'Nazwisko', 'Nazwa firmy']) && ex.wierszy === 2, JSON.stringify(ex.naglowek));
  t.check('znaczniki z nagłówków: {imie}, {nazwisko}, {nazwa_firmy}; adres w kolumnie 0',
    ex.mapa && ex.mapa.email === 0 && jak(pola(ex.mapa), { 1: 'imie', 2: 'nazwisko', 3: 'nazwa_firmy' }), JSON.stringify(ex.mapa));
  t.check('adres w drugiej kolumnie wykryty sam',
    tb.adres_drugi && tb.adres_drugi.mapa.email === 1 && jak(pola(tb.adres_drugi.mapa), { 0: 'imie' }), JSON.stringify(tb.adres_drugi && tb.adres_drugi.mapa));
  t.check('bez nagłówka: nie wiadomo, co jest czym — reszta kolumn pominięta',
    tb.bez_naglowka && tb.bez_naglowka.naglowek === null && tb.bez_naglowka.wierszy === 2 && jak(pola(tb.bez_naglowka.mapa), {}),
    JSON.stringify(tb.bez_naglowka));
  t.check('powtórzony klucz dostaje przyrostek, „_consent_ip" nie nadpisze zapisu zgody',
    tb.te_same && jak(pola(tb.te_same.mapa), { 0: 'imie', 1: 'imie_2', 3: 'consent_ip' }), JSON.stringify(tb.te_same && tb.te_same.mapa));
  /* „Imię <adres>" to jedyny wyjątek od ścisłości; „jan@x.pl;Jan" dalej nie
     jest adresem (audyt 1.229.6: sklejone pola liczone jako dodane). */
  t.check('adres z komórki: „Imię <adres>" tak, sklejone pola nie',
    jak(tb.adresy, ['jan@x.pl', '', 'anna@y.pl', '', '']), JSON.stringify(tb.adresy));
  t.check('mapa z panelu: bez kolumny adresu, spoza pliku i z kluczem oczyszczonym',
    tb.mapa_z_zadania && tb.mapa_z_zadania.email === 0 && jak(pola(tb.mapa_z_zadania), { 1: 'imie_glowne', 2: 'ip' }), JSON.stringify(tb.mapa_z_zadania));
  t.check('zepsuta mapa z żądania — mapa proponowana', tb.mapa_zepsuta && jak(pola(tb.mapa_zepsuta), { 1: 'imie', 2: 'firma' }),
    JSON.stringify(tb.mapa_zepsuta));

  // ── Podgląd i import ────────────────────────────────────────────────────
  const pr = sonda('przejdz');
  const liczby = (w) => w && { added: w.added, skipped: w.skipped, unsubscribed: w.unsubscribed, invalid: w.invalid };
  t.section('podgląd: liczby i stany wierszy, bez zapisu');
  t.check('nowe 2, już na liście 2 (jest + powtórzony), wykluczeni 5, błędne 1',
    jak(liczby(pr.podglad), { added: 2, skipped: 2, unsubscribed: 5, invalid: 1 }), JSON.stringify(liczby(pr.podglad)));
  t.check('wykluczeni z każdego powodu: wypisani (z tej i z innej listy), odbity (wielkie litery), ręcznie, RODO',
    pr.podglad && jak(pr.podglad.powody, { wypisany: 2, odbity: 1, reczny: 1, rodo: 1 }), JSON.stringify(pr.podglad && pr.podglad.powody));
  const stany = ((pr.podglad || {}).probka || []).map((w) => w.stan + (w.powod ? ':' + w.powod : ''));
  t.check('próbka mówi, co stanie się z każdym wierszem',
    jak(stany, ['jest', 'wykluczony:wypisany', 'wykluczony:wypisany', 'wykluczony:reczny', 'wykluczony:odbity', 'wykluczony:rodo',
      'nowy', 'duplikat', 'bledny', 'nowy']), JSON.stringify(stany));
  t.check('podgląd niczego nie zapisuje', pr.po_podgladzie_nowy1 === null, JSON.stringify(pr.po_podgladzie_nowy1));

  t.section('import: dokładnie to, co pokazał podgląd');
  t.check('te same liczby co w podglądzie', jak(liczby(pr.import), liczby(pr.podglad)), JSON.stringify(liczby(pr.import)));
  t.check('nowy subskrybent ze znacznikiem z pliku i śladem importu',
    pr.nowy1 && pr.nowy1.status === 1 && pr.nowy1.pola.imie === 'Jan' && pr.nowy1.pola._consent_source === 'import z pliku (admin)',
    JSON.stringify(pr.nowy1));
  t.check('pusta komórka — bez znacznika', pr.nowy2 && pr.nowy2.status === 1 && !('imie' in pr.nowy2.pola), JSON.stringify(pr.nowy2));
  t.check('wypisany zostaje wypisany', pr.wypisany_dalej && pr.wypisany_dalej.status === 0, JSON.stringify(pr.wypisany_dalej));
  t.check('stara droga (tablica adresów) też pomija wykluczonych',
    jak(pr.stara_droga, { added: 1, skipped: 0, unsubscribed: 2, invalid: 0 }), JSON.stringify(pr.stara_droga));

  // ── Panel (AJAX) ────────────────────────────────────────────────────────
  const aj = sonda('ajax');
  t.section('panel: podgląd i import przez AJAX');
  const p = aj.podglad || {};
  t.check('podgląd: kolumny z nagłówkami i przykładami, mapa, liczby',
    p.sukces === true && p.naglowek === true && jak((p.kolumny || []).map((k) => k.nazwa + '=' + k.przyklad), ['E-mail=jest@example.com', 'Imię=Ala'])
      && p.added === 2 && p.unsubscribed === 5, JSON.stringify(p).slice(0, 300));
  t.check('import z mapą z panelu: pominięta kolumna nie trafia do subskrybenta',
    aj.import && aj.import.sukces === true && aj.import.added === 2 && aj.import.probka === false
      && aj.nowy1_bez_imienia && !('imie' in aj.nowy1_bez_imienia.pola), JSON.stringify([aj.import, aj.nowy1_bez_imienia]));
  t.check('nieistniejąca lista i pusta wklejka — komunikat, bez zapisu',
    (aj.zla_lista || {}).success === false && (aj.pusto || {}).success === false && /adresów/.test(((aj.pusto || {}).data || {}).msg || ''),
    JSON.stringify([aj.zla_lista, aj.pusto]));

  t.section('lista wykluczeń w panelu');
  t.check('dodanie: poprawne adresy trafiają na listę, błędne są liczone',
    aj.wyk_dodaj && aj.wyk_dodaj.dodane === 2 && aj.wyk_dodaj.bledne === 1 && aj.wyk_dodaj.adresy.includes('jest@example.com')
      && aj.wyk_dodaj.adresy.includes('kto@example.com'), JSON.stringify(aj.wyk_dodaj));
  t.check('adres dopisany do wykluczeń przestaje dostawać maile (wypisany z listy)',
    aj.jest_po_wykluczeniu && aj.jest_po_wykluczeniu.status === 0, JSON.stringify(aj.jest_po_wykluczeniu));
  t.check('tabela subskrybentów mówi, dlaczego wypisany', jak(aj.lista_subskrybentow, ['jest@example.com:0:reczny']),
    JSON.stringify(aj.lista_subskrybentow));
  t.check('usunięcie z wykluczeń nie przywraca na listę (to osobna decyzja)',
    !(aj.wyk_po_usunieciu || []).includes('jest@example.com') && aj.jest_po_usunieciu && aj.jest_po_usunieciu.status === 0,
    JSON.stringify([aj.wyk_po_usunieciu, aj.jest_po_usunieciu]));
  t.check('bez prawa do newslettera — odmowa', (aj.bez_prawa || {}).success === false && aj.obcy_dodany === false,
    JSON.stringify([aj.bez_prawa, aj.obcy_dodany]));

  // ── RODO ────────────────────────────────────────────────────────────────
  t.section('RODO: wpis na liście wykluczeń');
  const r = sonda('rodo');
  const wiersz = ((r.eksport || [])[0] || {}).data || [];
  t.check('eksport danych osoby pokazuje wpis na liście wykluczeń z powodem',
    wiersz.some((d) => d.name === 'Powód' && /odrzucony przez serwer/.test(d.value)), JSON.stringify(wiersz));
  t.check('usunięcie: adres znika z listy wykluczeń, zostaje tylko skrót (import go dalej nie dopisze)',
    r.usuniecie && r.usuniecie.items_removed === true && r.po && r.po.na_liscie === false && r.po.zablokowany === true && r.po.adres_w_opcjach === false,
    JSON.stringify([r.usuniecie && r.usuniecie.items_removed, r.po]));
};
