/**
 * Statystyki: eksport CSV (1.285.0) — okres raportu w jednym pliku, wiersz
 * na wartość. Dane z obu ścieżek (wczoraj z tabeli dziennej, dziś z surowej),
 * całe listy zamiast dziesięciu z raportu, pola od odwiedzających bez formuł
 * arkusza. Pobranie przyciskiem w Chromium: ten sam plik co z sondy.
 */

const fs = require('fs');
const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('statystyki.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);

/** CSV ze średnikiem i cudzysłowami (RFC 4180) → wiersze. */
function czytajCsv(tekst) {
  const wiersze = [];
  let w = [], pole = '', cudz = false;
  for (let i = 0; i < tekst.length; i++) {
    const z = tekst[i];
    if (cudz) {
      if (z === '"' && tekst[i + 1] === '"') { pole += '"'; i++; } else if (z === '"') cudz = false; else pole += z;
    } else if (z === '"') cudz = true;
    else if (z === ';') { w.push(pole); pole = ''; }
    else if (z === '\n') { w.push(pole); wiersze.push(w); w = []; pole = ''; }
    else if (z !== '\r') pole += z;
  }
  if (pole !== '' || w.length) { w.push(pole); wiersze.push(w); }
  return wiersze;
}

module.exports = async function (t) {
  t.section('środowisko');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  let serwer = null;
  let browser = null;
  try {
    sonda('przygotuj');
    const dzis = sonda('stan').dzis;
    const dzien = (n) => { const d = new Date(dzis + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() - n); return d.toISOString().slice(0, 10); };
    /* Wczoraj: 3 odsłony jednej wizyty, zebrane do tabeli dziennej. */
    const W = 'a'.repeat(16), B = 'b'.repeat(16), C = 'c'.repeat(16);
    sonda('wstaw', J({ odslony: [1, 2, 3].map(() => ({ sciezka: '/wczoraj', wizyta: W, czas_s: 20, przewiniecie: 50 })) }));
    sonda('postarz', 1);
    sonda('zbiorka', 'od-nowa');
    /* Dziś: pola zaczynające się od „=”, „+”, „@”, „-”, adres ze średnikiem i cudzysłowem, 12 kolejnych stron. */
    const wst = sonda('wstaw', J({
      odslony: [
        { sciezka: '=HYPERLINK("http://x")', zrodlo: '+48 bramka', utm_source: '@kampania', utm_campaign: '-promo', czas_s: 30, przewiniecie: 80, urzadzenie: 'telefon', kraj: 'PL', wizyta: B },
        { sciezka: '/a;b"c', zrodlo: 'google.com', czas_s: 90, przewiniecie: 40, urzadzenie: 'komputer', wizyta: C },
      ].concat(Array.from({ length: 12 }, (_, i) => ({ sciezka: '/p' + (i + 1), wizyta: C }))),
      zdarzenia: [{ rodzaj: 'tel', etykieta: '+48 123', wizyta: B }],
    }));
    t.check('warunek: wiersze wstawione bez błędu bazy', (wst.bledy || []).length === 15 && wst.bledy.every((b) => b === ''), J(wst));

    t.section('zawartość pliku (sonda)');
    const csv = sonda('csv', dzien(6), dzien(0)).csv || '';
    console.log('      csv: ' + J(csv.slice(0, 400)));
    t.check('BOM UTF-8 na początku (polski Excel), średnik, nagłówek po polsku', csv.startsWith('﻿')
      && csv.slice(1).split('\n')[0] === 'Sekcja;Wartość;Odsłony;Unikalni;"Średni czas (s)";"Średnie przewinięcie (%)"', J(csv.slice(0, 120)));
    const w = czytajCsv(csv.replace(/^﻿/, ''));
    const sekcja = (s) => w.filter((r) => r[0] === s).map((r) => r.slice(1));
    t.check('każdy wiersz ma 6 pól', w.every((r) => r.length === 6), J(w.filter((r) => r.length !== 6)));
    /* Razem: 17 odsłon, 3 wizyty (1 wczoraj + 2 dziś); czas (60 + 120) / 5, przewinięcie (150 + 120) / 5. */
    t.check('„Razem”: okres, 17 odsłon, 3 unikalnych, 36 s, 54%', J(sekcja('Razem')) === J([[dzien(6) + ' – ' + dzien(0), '17', '3', '36', '54']]), J(sekcja('Razem')));
    const dni = sekcja('Dzień');
    t.check('„Dzień”: 7 dni okresu; wczoraj (tabela dzienna) 3/1/20/50, dziś (surowe) 14/2/60/60, puste dni z zerami i bez średnich',
      dni.length === 7 && dni[0][0] === dzien(6) && J(dni[5]) === J([dzien(1), '3', '1', '20', '50']) && J(dni[6]) === J([dzien(0), '14', '2', '60', '60'])
      && J(dni[0]) === J([dzien(6), '0', '0', '', '']), J(dni));
    const strony = sekcja('Strony').map((r) => r[0]);
    t.check('„Strony”: wszystkie 15 adresów (raport pokazuje 10)', strony.length === 15 && strony.includes('/p12') && strony.includes('/wczoraj'), J(strony));
    t.check('adres ze średnikiem i cudzysłowem przechodzi w cudzysłowach i wraca bez zmian', strony.includes('/a;b"c') && csv.includes('"/a;b""c"'), J(strony));
    const formuly = { strona: strony.find((s) => s.includes('HYPERLINK')), zrodlo: sekcja('Źródła').map((r) => r[0]).find((s) => s.includes('bramka')),
      utm: sekcja('Kampanie: źródło').map((r) => r[0]), kampania: sekcja('Kampanie: nazwa').map((r) => r[0]) };
    t.check('pola od odwiedzających zaczynające się od „=”, „+”, „@”, „-” dostają apostrof (bez formuły w arkuszu)',
      J(formuly) === J({ strona: '\'=HYPERLINK("http://x")', zrodlo: '\'+48 bramka', utm: ['\'@kampania'], kampania: ['\'-promo'] }), J(formuly));
    const urz = sekcja('Urządzenia').map((r) => r[0]).sort();
    const kraje = sekcja('Kraje').map((r) => r[0]);
    const zd = sekcja('Zdarzenia');
    t.check('nazwy jak w raporcie: urządzenia, kraj (po polsku albo kod bez intl), zdarzenie „Telefon: …”',
      J(urz) === J(['Komputer', 'Telefon']) && ['Polska', 'PL'].includes(kraje[0]) && J(zd) === J([['Telefon: +48 123', '1', '1', '', '']]), J({ urz, kraje, zd }));

    t.section('pobranie przyciskiem w raporcie (Chromium)');
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const s = await (await browser.newContext({ acceptDownloads: true })).newPage();
    await serwerWp.zaloguj(s, serwer.baza);
    await s.goto(serwer.baza + '/wp-admin/admin.php?page=evoke-statystyki&okres=7');
    const [pob] = await Promise.all([s.waitForEvent('download', { timeout: 15000 }), s.click('a.evk-stat-csv')]);
    const plik = fs.readFileSync(await pob.path(), 'utf8');
    t.check('nazwa pliku: statystyki-<host>-<od>-<do>.csv', new RegExp('^statystyki-[a-z0-9.-]+-' + dzien(6) + '-' + dzien(0) + '\\.csv$').test(pob.suggestedFilename()), pob.suggestedFilename());
    t.check('plik z przycisku = plik z sondy (ten sam okres)', plik === csv, J({ przycisk: plik.slice(0, 200), sonda: csv.slice(0, 200) }));
    const href = await s.getAttribute('a.evk-stat-csv', 'href');
    const r = await s.request.get(href.replace(/_wpnonce=[^&]+/, '_wpnonce=zly'), { maxRedirects: 0 });
    t.check('zły nonce: 403, bez pliku', r.status() === 403 && !(await r.text()).includes('Sekcja;'), r.status());
    const rOk = await s.request.get(href);
    t.check('nagłówki: text/csv, załącznik, bez pamięci podręcznej', /^text\/csv; charset=utf-8$/i.test(rOk.headers()['content-type'] || '')
      && /^attachment; filename="statystyki-/.test(rOk.headers()['content-disposition'] || '') && /no-cache|no-store/.test(rOk.headers()['cache-control'] || ''), J(rOk.headers()));

    t.section('raport: listy po 5 z rozwinięciem, zdarzenia w dwóch liniach, adresy odsyłające, oś dat (1.286.0)');
    sonda('wstaw', J({ odslony: [
      { sciezka: '/z-bloga', zrodlo: 'example.org', odsylacz: 'example.org/blog/wpis', wizyta: 'd'.repeat(16) },
      { sciezka: '/z-bloga', zrodlo: 'example.org', odsylacz: 'example.org/blog/wpis', wizyta: 'e'.repeat(16) },
      { sciezka: '/z-bloga', zrodlo: 'example.org', odsylacz: 'example.org/inny', wizyta: 'f'.repeat(16) }] }));
    const odsylacze = sonda('odsylacz').wyniki;
    t.check('adres odsyłający: obca strona — domena i ścieżka bez „?…” i „#…”; wyszukiwarka (sama domena), bezpośrednio i własna strona — pusto',
      J(odsylacze) === J(['', '', 'example.org/blog/wpis', '', 'l.facebook.com/l.php']), J(odsylacze));
    await s.goto(serwer.baza + '/wp-admin/admin.php?page=evoke-statystyki&okres=7');
    const lista = (tytul) => s.evaluate((t) => {
      const b = [...document.querySelectorAll('.evk-stat-lista')].find((x) => x.querySelector('h3').textContent === t);
      if (!b) return null;
      const widac = (el) => !!el.offsetParent;
      return { wiersze: [...b.querySelectorAll('tbody:not(.evk-stat-pod) > tr')].filter(widac).map((tr) => tr.children[0].innerText.replace(/\s+/g, ' ').trim()),
        przycisk: (b.querySelector('.evk-stat-rozwin') || {}).textContent || null, rozwiniety: (b.querySelector('.evk-stat-rozwin') || { getAttribute: () => null }).getAttribute('aria-expanded'),
        pod: [...b.querySelectorAll('tbody.evk-stat-pod tr')].filter(widac).map((tr) => tr.children[0].textContent),
        rodzaje: [...b.querySelectorAll('.evk-stat-rodzaj')].map((x) => x.textContent) };
    }, tytul);
    const st0 = await lista('Strony');
    await s.click('.evk-stat-lista:has(h3:text-is("Strony")) .evk-stat-rozwin');
    const st1 = await lista('Strony');
    await s.click('.evk-stat-lista:has(h3:text-is("Strony")) .evk-stat-rozwin');
    const st2 = await lista('Strony');
    t.check('„Strony”: 5 widać, „Pokaż wszystkie (16)” rozwija 16 i zmienia się na „Pokaż mniej”, drugi klik zwija',
      st0.wiersze.length === 5 && st0.przycisk === 'Pokaż wszystkie (16)' && st0.rozwiniety === 'false'
      && st1.wiersze.length === 16 && st1.przycisk === 'Pokaż mniej' && st1.rozwiniety === 'true' && st2.wiersze.length === 5, J({ st0, st1, st2 }));
    const zd2 = await lista('Zdarzenia');
    t.check('„Zdarzenia”: rodzaj małym napisem nad etykietą, bez przycisku przy jednej pozycji', J(zd2.rodzaje) === J(['Telefon']) && zd2.wiersze[0] === 'Telefon +48 123' && zd2.przycisk === null, J(zd2));
    const zr0 = await lista('Źródła');
    await s.click('.evk-stat-lista:has(h3:text-is("Źródła")) .evk-stat-pod-przelacz');
    const zr1 = await lista('Źródła');
    const przel = await s.getAttribute('.evk-stat-pod-przelacz', 'aria-expanded');
    t.check('„Źródła”: example.org rozwija swoje adresy (blog/wpis ×2 przed inny), inne źródła bez rozwijania',
      zr0.pod.length === 0 && J(zr1.pod) === J(['↳ example.org/blog/wpis', '↳ example.org/inny']) && przel === 'true'
      && (await s.$$('.evk-stat-pod-przelacz')).length === 1, J({ zr0, zr1 }));
    const os = await s.$$eval('.evk-stat-os span', (x) => x.map((e) => e.textContent));
    const jm = (d) => +d.slice(8, 10) + '.' + d.slice(5, 7);
    t.check('pod wykresem daty: pierwszy, środkowy i ostatni dzień (7 kolumn)', os.length === 7 && J(os.filter(Boolean)) === J([jm(dzien(6)), jm(dzien(3)), jm(dzien(0))]), J(os));
    const csv2 = sonda('csv', dzien(6), dzien(0)).csv || '';
    t.check('CSV: sekcja „Adresy odsyłające” z pełnymi adresami', /\n"Adresy odsyłające";example\.org\/blog\/wpis;2;2;;\n/.test(csv2), J(csv2.split('\n').filter((x) => /odsyłające/.test(x))));

    t.section('kto pobiera');
    sonda('czytelnicy');
    const c = await (await browser.newContext({ acceptDownloads: true })).newPage();
    await serwerWp.zaloguj(c, serwer.baza, 'statyk_csv', 'test-haslo');
    await c.goto(serwer.baza + '/wp-admin/admin.php?page=evoke-statystyki&okres=7');
    const [pobC] = await Promise.all([c.waitForEvent('download', { timeout: 15000 }), c.click('a.evk-stat-csv')]);
    t.check('rola z uprawnieniem „Statystyki” pobiera ten sam plik', fs.readFileSync(await pobC.path(), 'utf8') === sonda('csv', dzien(6), dzien(0)).csv);
    const bez = sonda('csv-pobierz', 'statyk_csv_bez');
    t.check('autor bez uprawnienia: odmowa 403, bez pliku', /^ODMOWA 403 Brak uprawnień\./.test(bez.odp || ''), J(bez));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('czytelnicy', 'usun');
    sonda('sprzataj');
  }
};
