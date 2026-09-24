/**
 * Zapis danych na PRAWDZIWYM WordPressie — to, czego atrapy nie widzą.
 *
 * Audyt 1.229.6 znalazł pięć błędów, z których każdy przechodził zielono
 * przez testy na atrapach, bo atrapa `wp_insert_post()` nie zdejmuje
 * ukośników, a atrapa uprawnień nie mapuje meta-uprawnień:
 *
 *   — snippety i wersje robocze gubiły `\` przy każdym zapisie,
 *   — import ustawień przy wyłączonych Tłumaczeniach kończył się błędem
 *     krytycznym,
 *   — eksport snippetów gubił rodzaj, a snippet PHP po imporcie wypisywał
 *     swój kod na stronie,
 *   — ograniczenia stron w Role Managerze nie blokowały niczego.
 *
 * Środowisko: `tools/testowy-wp.sh`. Bez niego test zapala się na czerwono,
 * tak jak testy kopii. Sonda: tests/php/zapis-wp.php.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {
  const sonda = (scenariusz) => {
    const surowe = phpOutput('zapis-wp.php', scenariusz, { dopuscBlad: true });
    try { return JSON.parse(surowe); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + surowe.slice(0, 300) }; }
  };

  t.section('środowisko: testowy WordPress z prawdziwą bazą');
  const s = sonda('snippety');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !s.brak, s.brak || 'jest');
  if (s.brak) return;

  // ── Snippety ────────────────────────────────────────────────────────────
  t.section('snippety: zapis z edytora zachowuje ukośniki');
  t.check('pierwszy zapis: kod bajt w bajt', s.po_zapisie === s.wzor, s.po_zapisie);
  t.check('drugi zapis tego samego kodu: dalej bajt w bajt', s.po_drugim === s.wzor, s.po_drugim);
  t.check('zapis stawia znacznik „ukośniki sprawdzone"', s.znacznik === '1', JSON.stringify(s.znacznik));

  t.section('snippety: ostrzeżenie przy wpisach sprzed naprawy');
  t.check('wpis zapisany po naprawie — bez ostrzeżenia', s.podejrzenie_po_zapisie === '', s.podejrzenie_po_zapisie || 'brak');
  t.check('regex PHP bez \\ („d{2}") — ostrzeżenie', /d\{/.test(s.podejrzenie_regex), s.podejrzenie_regex || 'BRAK');
  t.check('regex JS bez \\ („s+") — ostrzeżenie', /s\+/.test(s.podejrzenie_js), s.podejrzenie_js || 'BRAK');
  t.check('CSS content: "f101" — ostrzeżenie', /f101/.test(s.podejrzenie_css), s.podejrzenie_css || 'BRAK');
  t.check('poprawny kod — bez ostrzeżenia (fałszywy alarm)', s.podejrzenie_czysty === '', s.podejrzenie_czysty || 'brak');
  t.check('starsza wersja miała więcej \\ — ostrzeżenie z historii', /Wersja z/.test(s.podejrzenie_historia), s.podejrzenie_historia || 'BRAK');

  // ── Wersje robocze ──────────────────────────────────────────────────────
  t.section('wersje robocze: kopia i synchronizacja zachowują ukośniki');
  const w = sonda('wersje');
  t.check('kopia: dane Bricksa bajt w bajt', JSON.stringify(w.kopia_meta) === JSON.stringify(w.wzor_meta), JSON.stringify(w.kopia_meta));
  t.check('kopia: treść bajt w bajt', w.kopia_tresc === w.wzor_tresc, w.kopia_tresc);
  t.check('oryginał po synchronizacji: dane Bricksa bajt w bajt', JSON.stringify(w.oryginal_meta) === JSON.stringify(w.wzor_meta), JSON.stringify(w.oryginal_meta));
  t.check('oryginał po synchronizacji: treść bajt w bajt', w.oryginal_tresc === w.wzor_tresc, w.oryginal_tresc);

  // ── Import ──────────────────────────────────────────────────────────────
  t.section('import ustawień przy wyłączonych Tłumaczeniach');
  const i = sonda('import');
  t.check('warunek testu: moduł Tłumaczeń wyłączony i niezaładowany', !i.tl_wlaczone && !i.tl_zaladowane,
    'włączony: ' + i.tl_wlaczone + ', załadowany: ' + i.tl_zaladowane);
  t.check('import kończy się bez błędu krytycznego', !i.blad && i.odpowiedz && i.odpowiedz.success === true,
    i.blad || JSON.stringify(i.odpowiedz));
  t.check('moduł spoza Tłumaczeń zaimportowany', i.darkmode && Number(i.darkmode.logo_duration) === 2, JSON.stringify(i.darkmode && i.darkmode.logo_duration));
  const rzad = (((i.frazy || {}).groups || {}).g1 || {}).rows ? i.frazy.groups.g1.rows.r1 : null;
  t.check('frazy zaimportowane z kolumnami en/de', rzad && rzad.en === 'Contact' && rzad.de === 'Kontakt DE', JSON.stringify(rzad));
  t.check('„zaawansowany" snippet bez podwojonych ukośników', i.advanced === '<?php\n' + i.wzor, i.advanced.slice(0, 120));

  t.section('import snippetów: rodzaj wraca, nowe wchodzą wyłączone');
  const php = (i.snippety || {})['evk-t-php'] || {};
  t.check('snippet PHP wraca jako PHP, w swoim miejscu i grupie', php.rodzaj === 'php' && php.miejsce === 'footer' && php.grupa === 'Import',
    JSON.stringify({ rodzaj: php.rodzaj, miejsce: php.miejsce, grupa: php.grupa }));
  t.check('kod snippetu bajt w bajt (z ukośnikami)', php.kod === i.wzor, php.kod);
  t.check('nowy snippet z importu jest WYŁĄCZONY', php.wlaczony === 0, JSON.stringify(php.wlaczony));
  t.check('kolejność z pliku', php.kolejnosc === 7, JSON.stringify(php.kolejnosc));
  const stary = (i.snippety || {})['evk-t-stary'] || {};
  t.check('stary eksport (bez rodzaju): wyłączony, nic nie wypisuje', stary.wlaczony === 0 && i.wyjscie_starego === '',
    JSON.stringify({ wlaczony: stary.wlaczony, wyjscie: i.wyjscie_starego }));
  t.check('komunikat mówi o wyłączonych snippetach, bez dubla „odśwież"',
    /WYŁĄCZONE/.test((i.odpowiedz || {}).data || '') && !/odśwież/i.test((i.odpowiedz || {}).data || ''), (i.odpowiedz || {}).data);

  t.section('eksport snippetów niesie metadane');
  let e = {};
  try { e = JSON.parse(phpOutput('zapis-wp.php', 'eksport', { dopuscBlad: true })); } catch (err) { e = {}; }
  const wpis = (e.evk_snippets_posts || []).find((x) => x.title === 'Eksportowany') || {};
  t.check('rodzaj, miejsce, grupa, włącznik i kolejność w pliku',
    wpis.rodzaj === 'css' && wpis.miejsce === 'footer' && wpis.grupa === 'G' && String(wpis.wlaczony) === '1' && wpis.kolejnosc === 3,
    JSON.stringify(wpis));

  // ── Role ────────────────────────────────────────────────────────────────
  t.section('Role Manager: ograniczenie edycji stron');
  const r = sonda('role');
  const k = r.klient || {};
  t.check('dozwolona strona: edycja i usuwanie', k.edit_A === true && k.delete_A === true, JSON.stringify({ edit: k.edit_A, delete: k.delete_A }));
  t.check('inna strona: bez edycji, usuwania i publikacji',
    k.edit_B === false && k.delete_B === false && k.edit_page_B === false && k.publish_B === false,
    JSON.stringify({ edit: k.edit_B, delete: k.delete_B, edit_page: k.edit_page_B, publish: k.publish_B }));
  t.check('wpis blogowy: bez edycji', k.edit_wpis === false, JSON.stringify(k.edit_wpis));
  t.check('media zostają dostępne', k.edit_zalacznik === true, JSON.stringify(k.edit_zalacznik));
  t.check('rola bez ograniczeń: bez zmian', r.wolny && r.wolny.edit_B === true && r.wolny.edit_wpis === true, JSON.stringify(r.wolny));
  t.check('administrator: bez zmian', r.admin && r.admin.edit_B === true, JSON.stringify(r.admin));
  t.check('dwie role z listami: suma list, reszta zablokowana',
    r.dwie_role && r.dwie_role.edit_A === true && r.dwie_role.edit_B === true && r.dwie_role.edit_wpis === false, JSON.stringify(r.dwie_role));

  /* Szablony Bricksa (1.231.2): od 1.230.0 blokada obejmuje każdy typ wpisu,
     a lista miała same strony — nagłówka ani stopki nie dało się udostępnić. */
  t.section('Role Manager: szablony Bricksa na liście');
  t.check('zaznaczony szablon: edycja; inny szablon: bez edycji',
    k.edit_szablon_zaznaczony === true && k.edit_szablon_inny === false,
    JSON.stringify({ zaznaczony: k.edit_szablon_zaznaczony, inny: k.edit_szablon_inny }));
  const l = r.lista || {};
  t.check('lista w panelu: sekcja szablonów pod stronami, z każdym szablonem',
    l.naglowek === true && l.inny_jest === true, JSON.stringify({ naglowek: l.naglowek, inny: l.inny_jest }));
  t.check('zaznaczenie z zapisu: szablon i strona z listy tak, inny szablon nie',
    l.zaznaczony === true && l.strona_zaznaczona === true && l.inny_zaznaczony === false,
    JSON.stringify({ szablon: l.zaznaczony, strona: l.strona_zaznaczona, inny: l.inny_zaznaczony }));
  t.check('przy nazwie szablonu jego typ („nagłówek")', l.typ === true, JSON.stringify(l.typ));
  t.check('bez Bricksa lista nie ma sekcji szablonów', r.lista_bez_bricksa === true, JSON.stringify(r.lista_bez_bricksa));

  t.section('Role Manager: jednorazowe powiadomienie');
  t.check('przy zapisanych ograniczeniach administrator dostaje powiadomienie', r.powiadomienie === true, JSON.stringify(r.powiadomienie));
  t.check('bez ograniczeń: brak powiadomienia i zamyka się samo', r.powiadomienie_bez_ograniczen === false && r.zamkniete_samo === true,
    JSON.stringify({ pokazane: r.powiadomienie_bez_ograniczen, zamkniete: r.zamkniete_samo }));
};
