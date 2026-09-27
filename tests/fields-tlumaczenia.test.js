/**
 * Tłumaczenia wartości pól Evoke FIELDS (Fields 1.70.0, Evoke ONE 1.250.0).
 *
 * Decyzje zgłaszającego: pola języków przy tekście, tekście wielowierszowym,
 * WYSIWYG i etykiecie linku (także w repeaterach), „Nie tłumacz" w kreatorze;
 * wpisy, termy i strony ustawień (bez profilu i mediów); link: etykieta
 * ręcznie, adres wewnętrzny sam na wersję językową; brak tłumaczenia →
 * polski tekst.
 *
 * Czwarty testowy WordPress (pola.test) ma OBIE wtyczki. Formularz składa
 * sonda jak przeglądarka: z wyrenderowanego metaboksu, wiersze z szablonu —
 * więc sprawdzane są prawdziwe nazwy pól i pola ukryte, a zapis idzie przez
 * save_post, edited_category i handler strony ustawień Fields.
 *
 * Czego stąd nie widać: skryptu panelu (przełącznik, kopiowanie, edytor) —
 * to fields-panel w Chromium. I Bricksa: tagi są wołane przez funkcje
 * resolvera, które Bricks woła na stronie.
 */

const { phpOutput } = require('./lib/harness');

const sonda = (tryb) => {
  const w = phpOutput('fields-tlumaczenia.php', tryb);
  try { return JSON.parse(w); } catch (e) { return { brak: 'sonda „' + tryb + '": ' + w.slice(0, 300) }; }
};
const J = (v) => JSON.stringify(v);

module.exports = async function (t) {
  t.section('środowisko: pola.test z Evoke ONE i Evoke FIELDS');
  const u = sonda('ustaw');
  t.check('czwarty WordPress z obiema wtyczkami (tools/testowy-wp.sh)', !u.brak && u.klasyk > 0 && u.term > 0, u.brak || J(u));
  if (u.brak) return;

  // ── Zapis ────────────────────────────────────────────────────────────────
  const z = sonda('zapis');
  const m = z.meta || {};
  t.section('zapis wpisu z formularza (save_post, nonce z formularza)');
  t.check('szablon wiersza: pola języków EN i DE przy tekście, opisie i etykiecie linku, bez nich przy „Nie tłumacz"',
    (z.szablon_w1 || []).includes('evk_single[lista][w1][evk_tl_de__odnosnik]') && (z.szablon_w1 || []).includes('evk_single[lista][w1][evk_tl_en__tekst__przed]')
      && !(z.szablon_w1 || []).some((n) => n.includes('__ikona')) && (z.szablon_w1 || []).length === 23, J(z.szablon_w1));
  t.check('pole pojedyncze: oryginał i tłumaczenia jako osobne meta', m.tytul === 'Tytuł PL' && m.evk_tl_en__tytul === 'Title EN' && m.evk_tl_de__tytul === 'Titel DE', J(m));
  t.check('źródło tłumaczenia = skrót oryginału przy zapisie', m.evk_tl_en__tytul__zrodlo === z.skroty.tytul && m.evk_tl_de__tytul__zrodlo === z.skroty.tytul,
    m.evk_tl_en__tytul__zrodlo + ' / ' + z.skroty.tytul);
  t.check('wielowierszowy z nową linią, WYSIWYG ze znacznikami', m.evk_tl_en__opis === 'Description EN\nin two lines' && m.evk_tl_en__tresc === '<p>Content <strong>EN</strong></p>', J([m.evk_tl_en__opis, m.evk_tl_en__tresc]));
  t.check('link: tłumaczy się sama etykieta, adres zostaje w oryginale', m.evk_tl_en__przycisk === 'Write to us' && m.evk_tl_en__przycisk__zrodlo === z.skroty.przycisk
    && J(m.przycisk) === J({ url: 'http://pola.test/kontakt/', title: 'Napisz do nas' }), J([m.przycisk, m.evk_tl_en__przycisk]));
  t.check('puste tłumaczenie nie zapisuje się wcale (ani źródło)', !z.meta_istnieje.evk_tl_de__opis && !z.meta_istnieje.evk_tl_de__opis__zrodlo, J(z.meta_istnieje));
  t.check('„Nie tłumacz" i liczba: bez tłumaczeń', m.kod === 'ABC-1' && m.evk_tl_en__kod === '' && m.liczba === '5' && m.evk_tl_en__liczba === '', J([m.kod, m.evk_tl_en__kod, m.liczba]));
  const w1 = (z.lista || [])[0] || {}, w2 = (z.lista || [])[1] || {};
  t.check('wiersze z szablonu: tłumaczenia w wierszu, obok oryginału', w1.evk_tl_en__naglowek === 'Heading 1' && w1.evk_tl_de__naglowek === 'Überschrift 1'
    && w1.evk_tl_en__naglowek__zrodlo === z.skroty.naglowek1 && w1.evk_tl_en__odnosnik === 'More w1' && w2.evk_tl_en__naglowek === 'Heading 2', J(z.lista));
  t.check('w wierszu: puste tłumaczenie pominięte, „Nie tłumacz" bez tłumaczeń', !('evk_tl_de__naglowek' in w2) && !Object.keys(w1).some((k) => k.includes('__ikona'))
    && w1.ikona === 'ikona-w1', J(Object.keys(w2)));
  const g1 = (z.wiersze || [])[0] || {};
  t.check('grupa-repeater (wiersze pod kluczem grupy): tłumaczenie w wierszu, liczba bez', g1.haslo === 'Hasło 1' && g1.evk_tl_en__haslo === 'Slogan 1' && !('evk_tl_en__numer' in g1), J(g1));

  // ── Panel ────────────────────────────────────────────────────────────────
  const p = sonda('panel');
  t.section('panel: przełącznik i pola języków');
  t.check('przełącznik: PL wciśnięty, EN i DE z nazwą języka (grupa z etykietą)',
    J(p.przelacznik) === J([['pl', 'true', 'PL — oryginał'], ['en', 'false', 'EN — English'], ['de', 'false', 'DE — Deutsch']])
      && J(p.przelacznik_rola) === J(['group', 'Język wartości pól']) && J(p.grupa) === J(['pl', 'pl']), J([p.przelacznik, p.przelacznik_rola, p.grupa]));
  const pola = p.pola || {};
  const tak = (k) => (pola[k] || []).length > 0 && pola[k].every((f) => f.tak && f.pl === 1 && J(f.jezyki) === J(['en', 'de']));
  t.check('tekst, wielowierszowy, WYSIWYG, link (także w obu wierszach): oryginał + EN + DE',
    ['tytul', 'opis', 'tresc', 'przycisk', 'naglowek', 'tekst', 'odnosnik'].every(tak) && pola.naglowek.length === 2, J(pola));
  const bez = (k) => (pola[k] || []).length > 0 && pola[k].every((f) => !f.tak && f.jezyki.length === 0);
  t.check('„Nie tłumacz" (także w wierszu) i liczba: bez pól języków', ['kod', 'ikona', 'liczba'].every(bez), J([pola.kod, pola.ikona, pola.liczba]));
  t.check('repeater z polami do tłumaczenia oznaczony (zostaje w widoku języka)', (pola.lista || [])[0] && pola.lista[0].zawiera === true, J(pola.lista));
  t.check('zakładka bez pól do tłumaczenia oznaczona do ukrycia, zakładka z polami nie', J(p.zakladki) === J({ 'Treść': false, 'Techniczne': true }), J(p.zakladki));
  t.check('etykiety: „pole — EN", przy linku „— etykieta EN"', (p.etykiety || []).includes('Tytuł sekcji — EN') && p.etykiety.includes('Przycisk — etykieta DE')
    && p.etykiety.length === 22, J(p.etykiety));
  t.check('każda etykieta wskazuje swoje pole, identyfikatory bez powtórzeń', J(p.etykiety_bez_pola) === '[]' && J(p.id_powtorzone) === '[]',
    J([p.etykiety_bez_pola, p.id_powtorzone]));
  t.check('szablon wiersza: identyfikator pola języka ze znacznikiem wiersza (admin.js daje każdemu wierszowi własny)',
    (p.szablon_id || []).length === 6 && p.szablon_id.every((i) => i.includes('__INDEX__')), J(p.szablon_id));
  t.check('pola językowe z atrybutem lang (sprawdzanie pisowni w danym języku)', J(p.lang_atr) === J(['en', 'de']), J(p.lang_atr));
  t.check('przy linku uwaga o adresie (pole + dwa wiersze × dwa języki), WYSIWYG języków leniwy (textarea)', p.uwaga_link === 6 && p.wysiwyg === 2,
    p.uwaga_link + ' / ' + p.wysiwyg);
  const st = p.stan || {};
  t.check('zapisane wartości wracają do pól, bez „Do sprawdzenia" tuż po zapisie',
    (st['evk_single[evk_tl_en__tytul]'] || {}).wartosc === 'Title EN' && (st['evk_single[lista][0][evk_tl_de__naglowek]'] || {}).wartosc === 'Überschrift 1'
      && Object.values(st).every((s) => !s.sprawdz), J(st));
  t.check('skrypt i style panelu kolejkowane przy ekranie wpisu, reguła widoczności na każdy język', p.skrypt === true
    && p.style_jezykow === '.evk-tl-grupa[data-evk-jezyk="en"] .evk-tl-pole[data-lang="en"]{display:block}.evk-tl-grupa[data-evk-jezyk="de"] .evk-tl-pole[data-lang="de"]{display:block}',
    J([p.skrypt, p.style_jezykow]));

  // ── Do sprawdzenia ──────────────────────────────────────────────────────
  const s = sonda('sprawdz');
  t.section('„Do sprawdzenia"');
  t.check('zmiana samego oryginału: EN i DE do sprawdzenia, pole obok nie',
    s.po_zmianie_pl.en.sprawdz && s.po_zmianie_pl.de.sprawdz && !s.po_zmianie_pl.opis_en.sprawdz, J(s.po_zmianie_pl));
  t.check('„Sprawdzone" (źródło „teraz"): EN aktualne, DE dalej do sprawdzenia',
    !s.po_sprawdzone.en.sprawdz && s.po_sprawdzone.de.sprawdz && s.po_sprawdzone.zrodlo_en !== s.po_zmianie_pl.en.zrodlo, J(s.po_sprawdzone));
  t.check('tłumacz poprawił DE: aktualne bez klikania „Sprawdzone"', !s.po_poprawce_de.de.sprawdz && s.po_poprawce_de.de.wartosc === 'Titel DE neu', J(s.po_poprawce_de));
  t.check('w wierszu: do sprawdzenia tylko wiersz ze zmienionym oryginałem', s.wiersz.w0 && s.wiersz.w0.sprawdz && s.wiersz.w1 && !s.wiersz.w1.sprawdz, J(s.wiersz));
  t.check('wyczyszczone tłumaczenie (same spacje): meta znika razem ze źródłem', !s.wyczyszczone.wartosc && !s.wyczyszczone.zrodlo, J(s.wyczyszczone));
  t.check('tłumaczenie do pustego oryginału: źródło „pusty", po wpisaniu oryginału do sprawdzenia',
    s.pusty_oryginal.zrodlo === 'pusty' && !s.pusty_oryginal.stan.sprawdz && s.pusty_wpisany.sprawdz, J([s.pusty_oryginal, s.pusty_wpisany]));
  t.check('WYSIWYG: samo formatowanie oryginału (znaczniki, nowe linie) to nie zmiana tekstu', !s.wysiwyg_format.sprawdz, J(s.wysiwyg_format));

  // ── Przenoszenie ────────────────────────────────────────────────────────
  const pr = sonda('przenies');
  t.section('tłumaczenia bez widocznego pola przeżywają zapis');
  t.check('DE wyłączony: bez pól DE, a w wierszu DE jako pola ukryte (wartość, źródło, skrót)',
    pr.a_widoczne_de === 0 && J(pr.a_ukryte_de) === J(['evk_single[lista][0][evk_tl_de__naglowek]', 'evk_single[lista][0][evk_tl_de__naglowek__zrodlo]', 'evk_single[lista][0][evk_tl_de__naglowek__przed]']),
    J([pr.a_widoczne_de, pr.a_ukryte_de]));
  t.check('po zwykłym zapisie: DE w wierszu z tym samym źródłem (nadal może być „Do sprawdzenia")',
    pr.a_de_w_wierszu[0] === 'Überschrift 1' && pr.a_de_w_wierszu[1] === 'Überschrift 1' && pr.a_de_w_wierszu[2] === pr.a_de_w_wierszu[3], J(pr.a_de_w_wierszu));
  t.check('DE w polu pojedynczym (osobna meta) nietknięte', pr.a_de_pojedyncze[0] === pr.a_de_pojedyncze[1] && pr.a_de_pojedyncze[1] !== '', J(pr.a_de_pojedyncze));
  t.check('„Nie tłumacz" zaznaczone po fakcie: pól nie ma, tłumaczenie zostaje w danych', pr.b_pola_naglowka === 0 && pr.b_en_w_wierszu[0] === 'Heading 1'
    && pr.b_en_w_wierszu[1] === 'Heading 1', J([pr.b_pola_naglowka, pr.b_en_w_wierszu]));
  t.check('…a strona w EN pokazuje oryginał', pr.b_odczyt_en === 'Nagłówek 1 zmieniony', pr.b_odczyt_en);

  // ── Odczyt ───────────────────────────────────────────────────────────────
  const o = sonda('odczyt');
  t.section('strona: tagi w języku strony');
  t.check('PL: oryginały', o.pl.tytul === 'Tytuł' && o.pl.tresc === '<p>Treść</p>' && o.pl.przycisk_url === 'http://pola.test/kontakt/', J(o.pl));
  t.check('EN: tekst, wielowierszowy, WYSIWYG, opcja, term', o.en.tytul === 'Title' && o.en.opis === 'Description' && o.en.tresc === '<p>Content</p>'
    && o.en.slogan === 'Motto' && o.en.term === 'Caption', J(o.en));
  t.check('DE: tłumaczenie, a bez niego oryginał (także pusty akapit z edytora)', o.de.tytul === 'Titel' && o.de.opis === 'Opis' && o.de.tresc === '<p>Treść</p>'
    && o.de.slogan === 'Hasło' && o.de.term === 'Podpis', J(o.de));
  t.check('pusty oryginał zostaje pusty we wszystkich językach', ['pl', 'en', 'de'].every((l) => o[l].pusty === ''), J(['pl', 'en', 'de'].map((l) => o[l].pusty)));
  t.check('„Nie tłumacz": oryginał, choć w bazie leży tłumaczenie', ['en', 'de'].every((l) => o[l].kod === 'ABC-1') && o.en.wiersz[1] === 'ikona', J([o.en.kod, o.en.wiersz]));
  t.check('link EN: etykieta przetłumaczona, adres wewnętrzny na wersję EN (przetłumaczony slug)',
    o.en.przycisk_title === 'Write' && o.en.przycisk_url === 'http://pola.test/en/contact/' && o.en.przycisk_html === '<a href="http://pola.test/en/contact/">Write</a>',
    J([o.en.przycisk_title, o.en.przycisk_url, o.en.przycisk_html]));
  t.check('link DE bez etykiety DE: etykieta polska, adres na wersję DE', o.de.przycisk_title === 'Napisz' && o.de.przycisk_url === 'http://pola.test/de/kontakt-de/',
    J([o.de.przycisk_title, o.de.przycisk_url]));
  t.check('pętla (wiersz repeatera): tekst i etykieta EN, adres zewnętrzny bez zmian', J(o.en.wiersz) === J(['Heading', 'ikona', 'https://example.com/a/', 'More']), J(o.en.wiersz));
  t.check('pętla opcji: etykieta EN, adres względny na wersję EN', J(o.en.wiersz_opcji) === J(['Shop', '/en/contact/', 'Here']), J(o.en.wiersz_opcji));
  t.check('język spoza listy (fr): wszystko po polsku', J(o.fr) === J(o.pl), J(o.fr));
  t.check('builder Bricksa w widoku EN: po polsku (tam edytuje się oryginał)', J(o.builder) === J(o.pl), J(o.builder));

  const a = sonda('adresy');
  const oczek = [
    'http://pola.test/en/contact/', 'http://pola.test/de/kontakt-de/', '/en/contact/', '/en/contact', 'http://pola.test/en/', 'http://pola.test/en/',
    'http://pola.test/en/contact/', 'http://pola.test/en/contact/', 'http://pola.test/en/contact/?a=1#b', 'http://www.pola.test/en/contact/',
    'http://pola.test/en/o-nas/zespol/', 'https://example.com/kontakt/', 'mailto:a@b.pl', 'tel:+48123', '#sekcja',
    'http://pola.test/wp-content/uploads/plik.pdf', '/cennik.pdf', '//cdn.example.com/x/', 'http://pola.test/kontakt/', 'http://pola.test/kontakt/',
  ];
  t.section('adres z pola Link → wersja językowa (evk_tl_fields_url)');
  const zle = (a.przypadki || []).map((c, i) => c[2] === oczek[i] ? null : c[1] + ' ' + c[0] + ' → ' + c[2] + ' (oczekiwane ' + oczek[i] + ')').filter(Boolean);
  t.check('20 przypadków: slug, prefiks innego języka, zapytanie i kotwica, www, podstrona bez tłumaczenia; zewnętrzne, mailto, tel, kotwica, pliki, pl i fr bez zmian',
    (a.przypadki || []).length === 20 && zle.length === 0, zle.join(' | ') || '20 zgodnych');

  // ── Term, strona ustawień, bez języków ──────────────────────────────────
  const tr = sonda('term');
  t.section('term, strona ustawień, profil');
  t.check('term: przełącznik i pola języków, zapis przez edited_category', tr.przelacznik === 3 && tr.pola_en === 2 && tr.meta.en === 'Category caption'
    && tr.meta.en_zrodlo === tr.meta.skrot && tr.meta.de_baner === '<p>Banner DE</p>', J(tr));
  t.check('profil użytkownika: bez przełącznika i pól języków', tr.uzytkownik_przelacznik === 0, String(tr.uzytkownik_przelacznik));
  const op = sonda('opcje');
  const opc = op.opcja || {};
  t.check('strona ustawień: prawdziwy zapis, tłumaczenie obok oryginału w tablicy opcji', opc.slogan === 'Nowe hasło' && opc.evk_tl_en__slogan === 'New motto'
    && typeof opc.evk_tl_en__slogan__zrodlo === 'string' && opc.evk_tl_en__slogan__zrodlo.length === 12, J(op));
  t.check('strona ustawień, język wyłączony: tłumaczenie wraca jako pole ukryte i zostaje z tym samym źródłem',
    op.ukryte_de === 'Alt DE' && opc.evk_tl_de__slogan === 'Alt DE' && opc.evk_tl_de__slogan__zrodlo === 'abc123abc123', J(op));
  t.check('strona ustawień: wiersz dodany z szablonu, tłumaczenia w wierszu', J(((opc.przyciski || [])[0] || {}).evk_tl_en__etykieta) === '"Shop"'
    && ((opc.przyciski || [])[0] || {}).evk_tl_en__adres === 'Here', J(opc.przyciski));

  const wy = sonda('wylaczone');
  t.check('Tłumaczenia wyłączone: panel bez przełącznika, pól i klas języków', wy.przelacznik === 0 && wy.pola_jezykow === 0 && wy.klasy === 0, J(wy));
  t.check('…a tłumaczenia w wierszach wracają jako pola ukryte i przeżywają zapis', wy.ukryte === 21 && J(wy.wiersz_przed_po[0]) === J(wy.wiersz_przed_po[1]),
    wy.ukryte + ' ukrytych');
  t.check('…meta pól pojedynczych nietknięta, strona po polsku', wy.meta_en_tytul === 'Title EN' && wy.odczyt_en === wy.odczyt_pl, J([wy.meta_en_tytul, wy.odczyt_en]));
};
