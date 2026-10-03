/**
 * Repeatery w CSV Evoke FIELDS (Fields 1.79.0) — przez PRAWDZIWY panel
 * „Migracja CSV” w Chromium, na czwartym testowym WordPressie (pola.test).
 *
 * Decyzje zgłaszającego z 03.10: komórka to JSON z listą wierszy, klucze pól
 * (import przyjmuje też etykiety); tłumaczenia jako obiekt języka w wierszu;
 * obraz po ID albo adresie z biblioteki (brak → puste i ostrzeżenie); relacje
 * po ID albo nazwie; przy aktualizacji „zastąp” albo „dopisz” na kolumnę,
 * pusta komórka zostawia wiersze; eksport w tym samym formacie, więc plik
 * z eksportu wraca 1:1.
 *
 * Sonda: tests/php/fields-csv.php (dane i odczyt bazy).
 */

const fs = require('fs');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const w = phpOutput('fields-csv.php', a.join(' '), { dopuscBlad: true });
  try { return JSON.parse(w); } catch (e) { return { brak: 'sonda „' + a[0] + '": ' + w.slice(0, 300) }; }
};
const J = (v) => JSON.stringify(v);

/** CSV z wierszy (każda komórka w cudzysłowach, przecinek). */
const csv = (wiersze) => wiersze.map((w) => w.map((c) => '"' + String(c).replace(/"/g, '""') + '"').join(',')).join('\r\n') + '\r\n';

/** Prosty czytnik CSV (cudzysłowy, nowe linie w komórkach, BOM). */
function czytajCsv(tekst, sep = ',') {
  tekst = tekst.replace(/^﻿/, '');
  const out = []; let w = [], c = '', q = false;
  for (let i = 0; i < tekst.length; i++) {
    const z = tekst[i];
    if (q) { if (z === '"') { if (tekst[i + 1] === '"') { c += '"'; i++; } else q = false; } else c += z; continue; }
    if (z === '"') q = true;
    else if (z === sep) { w.push(c); c = ''; }
    else if (z === '\n') { w.push(c.replace(/\r$/, '')); out.push(w); w = []; c = ''; }
    else c += z;
  }
  if (c !== '' || w.length) { w.push(c); out.push(w); }
  return out;
}

/** Wiersz repeatera bez pustych pól (zapis trzyma klucz każdego pola, także pustego). */
const bezPustych = (r) => Object.fromEntries(Object.entries(r || {}).filter(([, v]) => v !== '' && !(Array.isArray(v) && !v.length)));

module.exports = async function (t) {
  t.section('środowisko: pola.test z Evoke ONE i Evoke FIELDS');
  const u = sonda('ustaw');
  t.check('czwarty WordPress z Fields 1.79.0 (tools/testowy-wp.sh)', !u.brak && u.img1 > 0 && u.b > 0, u.brak || J(u));
  if (u.brak || !u.img1) return;

  let serwer = null;
  let browser;
  try {
    serwer = await serwerWp.start(u.wp);
    const baza = serwer.baza;
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 1000 }, acceptDownloads: true });
    const p = await ctx.newPage();
    const bledyJs = [];
    p.on('pageerror', (e) => { if (!/\/wp-admin\//.test(String(e.stack || ''))) bledyJs.push(e.message); });
    await serwerWp.zaloguj(p, baza);
    const strona = baza + '/wp-admin/admin.php?page=evk-import';

    /** Import przez panel: wgranie, mapowanie (typ pola_csv), tryby kolumn, start, koniec. */
    const importuj = async (tekst, { dopasuj = 'slug', tryby = {} } = {}) => {
      await p.goto(strona + '&reset=1');
      await p.setInputFiles('input[name="evk_csv_file"]', { name: 'import.csv', mimeType: 'text/csv', buffer: Buffer.from(tekst, 'utf8') });
      await Promise.all([p.waitForNavigation(), p.click('button[name="evk_csv_upload"]')]);
      await p.goto(strona + '&pt=pola_csv');
      const mapa = await p.$$eval('select[name^="evk_csv_map["]', (s) => s.map((e) => {
        const tr = e.closest('tr'), tryb = tr.querySelector('.evk-csv-rep-mode');
        return { kolumna: tr.querySelector('td strong').textContent, cel: e.value, tryb: tryb ? getComputedStyle(tryb).display !== 'none' : null };
      }));
      for (const [kol, tryb] of Object.entries(tryby)) {
        const i = mapa.findIndex((m) => m.kolumna === kol);
        await p.selectOption('select[name="evk_csv_rep_mode[' + i + ']"]', tryb);
      }
      await p.selectOption('select[name="evk_csv_match_key"]', dopasuj);
      await Promise.all([p.waitForNavigation(), p.click('button[name="evk_csv_run"]')]);
      await p.waitForSelector('#evk-csv-done-box', { state: 'visible', timeout: 60000 }).catch(() => {});
      const raport = await p.evaluate(() => ({
        bledy: [...document.querySelectorAll('#evk-csv-errlist li')].map((l) => l.textContent),
        ostrzezenia: [...document.querySelectorAll('#evk-csv-warnlist li')].map((l) => l.textContent),
        utworzono: document.getElementById('evk-csv-created')?.textContent, zaktualizowano: document.getElementById('evk-csv-updated')?.textContent,
      }));
      return { mapa, raport };
    };

    // ── Import ──────────────────────────────────────────────────────────
    t.section('import: JSON w komórce, klucze i etykiety, obrazy, relacje, tłumaczenia');
    const img2Inna = u.img2_maly; // adres z domeną pola.test (nie serwera testu) i rozmiarem 150×150
    const pakietyNowy = [
      { tytul: 'Pakiet S', cena: '1 234,50', ikona: u.img1, galeria: [u.img1, img2Inna], polecane: ['wpis-b'], kategoria: ['Kategoria CSV'],
        autor: 'csv_autor@example.test', przycisk: { url: 'https://sklep.test/s', title: 'Kup', target: '_blank' }, wyrozniony: true, kolor: 'Czerwony',
        en: { tytul: 'Package S', cena: 'x' } },
      { 'Tytuł pakietu': 'Pakiet M', ikona: baza + '/wp-content/uploads/nie-ma.png', nieznane: 'x', polecane: ['nie-ma-takiego'] },
    ];
    const plik1 = csv([
      ['Tytuł', 'Slug', 'Kod', 'Pakiety', 'FAQ'],
      ['CSV nowy', 'csv-nowy', 'K-1', J(pakietyNowy), J([{ pytanie: 'Ile?', odpowiedz: 'Dużo' }])],
      ['Wpis B', 'wpis-b', '', J([{ tytul: 'Dopisany' }]), J([{ pytanie: 'Nowe' }])],
      ['Wpis C', 'wpis-c', '', '', '{zle'],
    ]);
    const im1 = await importuj(plik1, { tryby: { Pakiety: 'append' } });
    console.log('      mapa: ' + J(im1.mapa));
    console.log('      raport: ' + J(im1.raport));
    const m = Object.fromEntries(im1.mapa.map((x) => [x.kolumna, x]));
    t.check('mapowanie samo: „Pakiety” → grupa-repeater, „FAQ” → pole repeater, „Kod” → pole', m.Pakiety?.cel === 'rep:csv_pakiety' && m.FAQ?.cel === 'rep:faq' && m.Kod?.cel === 'evk:kod', J(im1.mapa));
    t.check('wybór „zastąp / dopisz” tylko przy kolumnach repeaterów', m.Pakiety?.tryb === true && m.FAQ?.tryb === true && m.Kod?.tryb === false && m['Tytuł']?.tryb === false, J(im1.mapa));
    t.check('raport: 1 utworzony, 2 zaktualizowane', im1.raport.utworzono === '1' && im1.raport.zaktualizowano === '2', J(im1.raport));
    t.check('błąd wiersza: „Wiersz 3, „FAQ”: to nie jest lista wierszy w JSON”',
      im1.raport.bledy.length === 1 && /^Wiersz 3, „FAQ”: to nie jest lista wierszy w JSON/.test(im1.raport.bledy[0]), J(im1.raport.bledy));
    const ost = im1.raport.ostrzezenia.join(' | ');
    t.check('ostrzeżenia: obraz spoza biblioteki, nieznane pole, brak wpisu relacji, pole bez tłumaczeń w „en”',
      /Wiersz 1, „Pakiety”, wiersz listy 2, „Ikona”: nie ma w bibliotece mediów „.*nie-ma\.png” — pominięte/.test(ost)
      && /Wiersz 1, „Pakiety”: nieznane pole „nieznane” — pominięte/.test(ost)
      && /„Polecane”: nie ma wpisu „nie-ma-takiego”/.test(ost) && /pole „cena” w języku „en” nie ma tłumaczeń/.test(ost), ost);

    const s1 = sonda('stan');
    const nowy = s1.wpisy['csv-nowy'] || {}, b1 = s1.wpisy['wpis-b'] || {}, c1 = s1.wpisy['wpis-c'] || {};
    const p0 = bezPustych((nowy.pakiety || [])[0]);
    console.log('      nowy: ' + J(nowy));
    t.check('nowy wpis: kod z kolumny pola i dwa wiersze pakietów', nowy.kod === 'K-1' && (nowy.pakiety || []).length === 2, J(nowy));
    t.check('liczba po polsku „1 234,50” → 1234.5; wybór po etykiecie „Czerwony” → „czerwony”; tak/nie → 1',
      p0.cena === 1234.5 && p0.kolor === 'czerwony' && p0.wyrozniony === '1', J(p0));
    t.check('obrazy: ID; galeria z ID i z adresu miniatury innej domeny → [img1, img2]',
      p0.ikona === u.img1 && J(p0.galeria) === J([{ img: u.img1 }, { img: u.img2 }]), J({ ikona: p0.ikona, galeria: p0.galeria }));
    t.check('relacje po nazwie: wpis po slugu, term po nazwie, użytkownik po e-mailu',
      J(p0.polecane) === J([u.b]) && J(p0.kategoria) === J([u.term]) && J(p0.autor) === J([u.autor]), J(p0));
    t.check('link z obiektu (url, etykieta, nowa karta)', J(p0.przycisk) === J({ url: 'https://sklep.test/s', title: 'Kup', target: '_blank' }), J(p0.przycisk));
    t.check('tłumaczenie z obiektu języka: evk_tl_en__tytul + źródło', p0.evk_tl_en__tytul === 'Package S' && /^[0-9a-f]{12}$/.test(p0.evk_tl_en__tytul__zrodlo || ''), J(p0));
    const p1 = bezPustych((nowy.pakiety || [])[1]);
    t.check('drugi wiersz: klucz po etykiecie („Tytuł pakietu”), obraz spoza biblioteki pusty', p1.tytul === 'Pakiet M' && !('ikona' in p1) && !('polecane' in p1), J(p1));
    t.check('pole repeater w grupie (FAQ) z JSON', J((nowy.faq || []).map(bezPustych)) === J([{ pytanie: 'Ile?', odpowiedz: 'Dużo' }]), J(nowy.faq));
    t.check('„dopisz”: stare dwa wiersze bez zmian (także znacznik AI), nowy na końcu',
      (b1.pakiety || []).length === 3 && b1.pakiety[0].evk_tl_en__tytul__zrodlo === u_ai(b1) && b1.pakiety[1].tytul === 'Stary 2' && b1.pakiety[2].tytul === 'Dopisany', J(b1.pakiety));
    t.check('„zastąp” (domyślnie): FAQ wpisu B tylko z nowym wierszem', J((b1.faq || []).map(bezPustych)) === J([{ pytanie: 'Nowe' }]), J(b1.faq));
    t.check('pusta komórka i zły JSON: wiersze wpisu C bez zmian', J(c1.pakiety) === J([{ tytul: 'Zostaje', cena: 5 }]) && J(c1.faq) === J([{ pytanie: 'Też zostaje' }]), J(c1));

    // ── Eksport ─────────────────────────────────────────────────────────
    t.section('eksport: ta sama postać (klucze, ID, obiekt języka)');
    await p.goto(strona + '&reset=1');
    await p.goto(strona + '&ept=pola_csv');
    const kolumny = await p.$$eval('input[name="evk_csv_export_cols[]"]', (c) => c.map((e) => e.closest('label').textContent.replace(/\s+/g, ' ').trim()));
    t.check('kolumny eksportu: „Pakiety (repeater, JSON)” i „FAQ (repeater, JSON)”', kolumny.includes('Pakiety (repeater, JSON)') && kolumny.includes('FAQ (repeater, JSON)'), J(kolumny));
    const [pobranie] = await Promise.all([p.waitForEvent('download'), p.click('button[name="evk_csv_export"]')]);
    const tekst = fs.readFileSync(await pobranie.path(), 'utf8');
    const tab = czytajCsv(tekst);
    const nag = tab[0], wNowy = tab.find((w) => w[nag.indexOf('Slug')] === 'csv-nowy') || [];
    const jp = JSON.parse(wNowy[nag.indexOf('Pakiety')] || 'null');
    console.log('      eksport Pakiety: ' + J(jp));
    t.check('nagłówek z „Pakiety” i „FAQ”', nag.includes('Pakiety') && nag.includes('FAQ'), J(nag));
    t.check('eksport wiersza: ID obrazów, galerii i relacji, link jako obiekt, liczba, obiekt „en”, bez pustych pól',
      J(jp && jp[0]) === J({ tytul: 'Pakiet S', cena: 1234.5, ikona: u.img1, galeria: [u.img1, u.img2], polecane: [u.b], kategoria: [u.term], autor: [u.autor],
        przycisk: { url: 'https://sklep.test/s', title: 'Kup', target: '_blank' }, wyrozniony: 1, kolor: 'czerwony', en: { tytul: 'Package S' } })
      && J(jp && jp[1]) === J({ tytul: 'Pakiet M' }), J(jp));

    // ── Ponowny import własnego eksportu ────────────────────────────────
    t.section('plik z eksportu wraca 1:1');
    const im2 = await importuj(tekst, { dopasuj: 'slug' });
    const s2 = sonda('stan');
    t.check('ponowny import: bez błędów i ostrzeżeń, 0 nowych wpisów', im2.raport.bledy.length === 0 && im2.raport.ostrzezenia.length === 0 && im2.raport.utworzono === '0' && s2.liczba === s1.liczba, J(im2.raport));
    /* Zapis trzyma klucz każdego pola wiersza, także pustego (jak formularz) — porównanie bez pustych. */
    const tresc = (st) => J(Object.fromEntries(Object.entries(st.wpisy).map(([k, w]) => [k, w && { kod: w.kod, pakiety: (w.pakiety || []).map(bezPustych), faq: (w.faq || []).map(bezPustych) }])));
    t.check('wiersze wszystkich wpisów te same co przed eksportem (także źródła tłumaczeń, znacznik AI)', tresc(s2) === tresc(s1), J({ przed: s1.wpisy['wpis-b'], po: s2.wpisy['wpis-b'] }));

    // ── Strony ustawień ─────────────────────────────────────────────────
    t.section('strony ustawień: eksport wszystkich pól, import w tym samym formacie');
    sonda('opcje-ustaw', u.img1);
    const przed = sonda('stan');
    const pobierzOpcje = async (grupa) => {
      await p.goto(strona + '&reset=1');
      await p.selectOption('select[name="evk_csv_opt_export_group"]', grupa);
      const [d] = await Promise.all([p.waitForEvent('download'), p.click('button[name="evk_csv_opt_export"]')]);
      return fs.readFileSync(await d.path(), 'utf8');
    };
    const importujOpcje = async (grupa, tekst, tryb = 'replace') => {
      await p.goto(strona + '&reset=1');
      await p.selectOption('select[name="evk_csv_opt_import_group"]', grupa);
      await p.selectOption('select[name="evk_csv_opt_import_mode"]', tryb);
      await p.setInputFiles('input[name="evk_csv_opt_file"]', { name: grupa + '.csv', mimeType: 'text/csv', buffer: Buffer.from(tekst, 'utf8') });
      await Promise.all([p.waitForNavigation(), p.click('button[name="evk_csv_opt_import"]')]);
      return p.evaluate(() => ({ komunikat: document.querySelector('.notice p')?.textContent || '', lista: [...document.querySelectorAll('.evk-csv-notice-lista li')].map((l) => l.textContent) }));
    };
    const plikOpcje = await pobierzOpcje('csv_opcje');
    const plikTabela = await pobierzOpcje('csv_tabela');
    const tOpcje = czytajCsv(plikOpcje), tTabela = czytajCsv(plikTabela);
    console.log('      eksport opcji: ' + J(tOpcje) + ' tabela: ' + J(tTabela));
    t.check('grupa pojedyncza: „Pole | Wartość”, obraz jako ID, link i pole-repeater jako JSON, tłumaczenie „Slogan [en]”',
      J(tOpcje) === J([['Pole', 'Wartość'], ['Slogan', 'Najlepsi w mieście'], ['Logo', String(u.img1)], ['Kontakt', J({ url: 'https://example.test/kontakt', title: 'Napisz' })],
        ['Linki', J([{ nazwa: 'Facebook', adres: { url: 'https://facebook.test/x', title: 'FB', target: '_blank' } }, { nazwa: 'Blog' }])], ['Slogan [en]', 'Best in town']]), J(tOpcje));
    t.check('grupa-repeater: tabela z etykietami, obraz jako ID', J(tTabela) === J([['Miasto', 'Telefon', 'Zdjęcie'], ['Kraków', '+48 12 000 00 00', String(u.img1)], ['Gdańsk', '+48 58 000 00 00', '']]), J(tTabela));

    sonda('opcje-wyczysc');
    const io1 = await importujOpcje('csv_opcje', plikOpcje);
    const io2 = await importujOpcje('csv_tabela', plikTabela);
    const po = sonda('stan');
    const norm = (o) => o && J(Object.fromEntries(Object.entries(o).sort(([a], [b]) => (a < b ? -1 : 1)).filter(([, v]) => v !== '' && !(Array.isArray(v) && !v.length)).map(([k, v]) => [k, Array.isArray(v) ? v.map((r) => (r && typeof r === 'object' ? bezPustych(r) : r)) : v])));
    t.check('pliki z eksportu wracają 1:1 (wartości wyczyszczone przed importem)', norm(po.opcje) === norm(przed.opcje) && J((po.tabela || []).map(bezPustych)) === J((przed.tabela || []).map(bezPustych)),
      J({ przed: przed.opcje, po: po.opcje, io1, io2 }));
    t.check('komunikaty: „zaktualizowano pól: 5” i „zaimportowano wierszy: 2 (zastąpiły poprzednie)”',
      /Grupa „Opcje CSV”: zaktualizowano pól: 5\./.test(io1.komunikat) && /Grupa „Oddziały”: zaimportowano wierszy: 2 \(zastąpiły poprzednie\)\./.test(io2.komunikat), J({ io1, io2 }));

    const io3 = await importujOpcje('csv_opcje', csv([['Pole', 'Wartość'], ['Slogan', 'Nowy slogan'], ['logo', u.img1_url.replace('evk-csv-czerwony', 'evk-csv-niebieski')], ['Nie ma', 'x']]));
    const io4 = await importujOpcje('csv_tabela', csv([['miasto', 'Zdjęcie'], ['Poznań', u.img2_maly]]), 'append');
    const po2 = sonda('stan');
    t.check('część pól (klucz „logo”, obraz po adresie): reszta grupy bez zmian, tłumaczenie zostaje',
      po2.opcje && po2.opcje.slogan === 'Nowy slogan' && po2.opcje.logo === u.img2 && J((po2.opcje.linki || []).map(bezPustych)) === J((przed.opcje.linki || []).map(bezPustych))
      && po2.opcje.evk_tl_en__slogan === 'Best in town', J(po2.opcje));
    t.check('ostrzeżenie o nieznanym polu pod komunikatem', io3.lista.some((l) => /Pole „Nie ma”: nie ma takiego pola w grupie/.test(l)), J(io3));
    t.check('tabela „dopisz”: trzeci wiersz z miniatury → ID obrazu, dwa stare bez zmian',
      (po2.tabela || []).length === 3 && po2.tabela[2].miasto === 'Poznań' && po2.tabela[2].zdjecie === u.img2 && po2.tabela[0].miasto === 'Kraków', J(po2.tabela));
    t.check('import do niewłaściwej grupy: błąd zamiast zapisu', /nie pasuje do pól grupy/.test((await importujOpcje('csv_tabela', plikOpcje)).komunikat) && J(sonda('stan').tabela) === J(po2.tabela));

    t.check('bez błędów JavaScriptu', bledyJs.length === 0, bledyJs.join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }

  /** Znacznik AI pierwszego starego wiersza wpisu B — z sondy „ustaw” (ai- + skrót „Stary 1”). */
  function u_ai(b) { return (b.pakiety && b.pakiety[0] && /^ai-[0-9a-f]{12}$/.test(b.pakiety[0].evk_tl_en__tytul__zrodlo)) ? b.pakiety[0].evk_tl_en__tytul__zrodlo : 'BRAK'; }
};
