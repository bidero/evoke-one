/**
 * Newsletter → Listy w PRAWDZIWYM panelu, w przeglądarce: import z podglądem
 * i mapowaniem kolumn, lista wykluczeń (1.233.0).
 *
 * Logika importu ma własny test na prawdziwej bazie (newsletter-import);
 * tu sprawdzamy to, czego tamten nie widzi — skrypt panelu: czy podgląd
 * pokazuje kolumny, liczby i próbkę, czy zmiana mapowania trafia do serwera,
 * czy import idzie z tą mapą i czy lista wykluczeń działa bez przeładowania.
 *
 * Środowisko: `tools/testowy-wp.sh` (jak backup-panel: php -S z routerem).
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('newsletter-panel.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);

const CSV = 'E-mail;Imię;Firma\njest.panel@example.com;Ala;A\nnowy.panel@example.com;Jan;ACME\n'
          + 'wykluczony.panel@example.com;X;Y\nzly;Z;Z\nnowy2.panel@example.com;Ewa;\n';

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');
  const prep = sonda('przygotuj');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !prep.brak && !!prep.lista, prep.brak || ('lista ' + prep.lista));
  if (prep.brak || !prep.lista) return;

  let serwer = null;
  let browser;
  try {
    serwer = await serwerWp.start(prep.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    p.on('dialog', (d) => d.accept());
    await serwerWp.zaloguj(p, serwer.baza);
    await p.goto(serwer.baza + '/wp-admin/admin.php?page=evoke-newsletter&subtab=lists&list_id=' + prep.lista);

    // ── Lista wykluczeń po wczytaniu ──────────────────────────────────────
    t.section('lista wykluczeń: wczytana z serwera');
    await p.locator('#evk-nl-wyk-lista li').first().waitFor({ timeout: 15000 });
    t.check('licznik i wpis z serwera',
      (await p.locator('#evk-nl-wyk-licznik').innerText()) === '1' && /wykluczony\.panel@example\.com/.test(await p.locator('#evk-nl-wyk-lista').innerText()),
      await p.locator('#evk-nl-wyk-lista').innerText());

    // ── Podgląd ───────────────────────────────────────────────────────────
    t.section('import: podgląd kolumn, liczb i wierszy');
    await p.fill('#evk-nl-import-textarea', CSV);
    await p.click('#evk-nl-import-podglad-btn');
    await p.locator('#evk-nl-import-podglad').waitFor({ state: 'visible', timeout: 15000 });
    const kolumny = await p.$$eval('#evk-nl-import-kolumny tbody tr', (tr) => tr.map((w) => ({
      nazwa: w.cells[0].innerText, wybor: w.querySelector('select').value,
      klucz: w.querySelector('.evk-nl-map-klucz').hidden ? '' : w.querySelector('.evk-nl-map-klucz').value,
      etykieta: w.querySelector('select').getAttribute('aria-label') })));
    t.check('trzy kolumny: adres, {imie}, {firma} — z opisem dla czytnika ekranu',
      jak(kolumny.map((k) => k.nazwa + ':' + k.wybor + ':' + k.klucz), ['E-mail:email:', 'Imię:pole:imie', 'Firma:pole:firma'])
        && kolumny.every((k) => /: zapisz jako$/.test(k.etykieta || '')),
      JSON.stringify(kolumny));
    const liczby = await p.locator('#evk-nl-import-liczby').innerText();
    t.check('liczby: nowe 2, na liście 1, wykluczony 1 (z listy wykluczeń), błędny 1',
      /Nowe: 2, już na liście: 1, wykluczeni \(pominięci\): 1 — z listy wykluczeń 1, błędne: 1\./.test(liczby), liczby);
    const stany = await p.$$eval('#evk-nl-import-probka tbody tr', (tr) => tr.map((w) => w.getAttribute('data-stan')));
    t.check('próbka: stan każdego wiersza', jak(stany, ['jest', 'nowy', 'wykluczony', 'bledny', 'nowy']), JSON.stringify(stany));
    t.check('przycisk mówi, ile adresów doda', (await p.locator('#evk-nl-import-btn').innerText()).trim() === 'Importuj 2 adresy',
      await p.locator('#evk-nl-import-btn').innerText());

    // ── Mapowanie ─────────────────────────────────────────────────────────
    t.section('mapowanie: zmiana idzie do serwera i do importu');
    const odpowiedz = p.waitForResponse((r) => r.url().includes('admin-ajax.php') && r.request().postData() && /evk_nl_import_podglad/.test(r.request().postData()));
    await p.selectOption('#evk-nl-import-kolumny tbody tr[data-kolumna="2"] select', 'pomin');
    const zPodgladu = await (await odpowiedz).json();
    t.check('„Firma" → Pomiń: nowa mapa w żądaniu podglądu',
      zPodgladu.success === true && jak(Object.values(zPodgladu.data.mapa.pola), ['imie']), JSON.stringify(zPodgladu.data && zPodgladu.data.mapa));
    await p.waitForFunction(() => !/\{firma\}/.test(document.querySelector('#evk-nl-import-probka').innerText));
    t.check('próbka bez {firma}, z {imie}', /\{imie\} Jan/.test(await p.locator('#evk-nl-import-probka').innerText()),
      (await p.locator('#evk-nl-import-probka').innerText()).slice(0, 200));

    await p.click('#evk-nl-import-btn');
    await p.locator('#evk-nl-import-result').filter({ hasText: /Dodano/ }).waitFor({ timeout: 15000 });
    const wynik = await p.locator('#evk-nl-import-result').innerText();
    t.check('import: wynik słowami, podgląd zamknięty',
      /Dodano: 2, już na liście: 1, wykluczeni \(pominięci\): 1/.test(wynik) && !(await p.locator('#evk-nl-import-podglad').isVisible()), wynik);
    const stan = sonda('stan ' + prep.lista);
    const sub = stan.subskrybenci || {};
    t.check('w bazie: nowi ze znacznikiem {imie}, bez {firma} (pominiętej w podglądzie)',
      jak(sub['nowy.panel@example.com'], { status: 1, pola: { imie: 'Jan' } }) && jak(sub['nowy2.panel@example.com'], { status: 1, pola: { imie: 'Ewa' } })
        && !('wykluczony.panel@example.com' in sub), JSON.stringify(sub));
    await p.locator('#evk-nl-sub-count').filter({ hasText: '3 subskrybentów' }).waitFor({ timeout: 10000 });
    t.check('tabela subskrybentów odświeżona bez przeładowania', true, await p.locator('#evk-nl-sub-count').innerText());

    // ── Lista wykluczeń: dodaj / usuń ─────────────────────────────────────
    t.section('lista wykluczeń: dodawanie i usuwanie w panelu');
    await p.fill('#evk-nl-wyk-adresy', 'dodany.panel@example.com, nie-adres');
    await p.click('#evk-nl-wyk-dodaj');
    await p.locator('#evk-nl-wyk-msg').filter({ hasText: /Dodano/ }).waitFor({ timeout: 10000 });
    t.check('komunikat i wpis na liście', /Dodano: 1, błędne: 1\./.test(await p.locator('#evk-nl-wyk-msg').innerText())
      && (await p.locator('#evk-nl-wyk-licznik').innerText()) === '2', await p.locator('#evk-nl-wyk-msg').innerText());
    await p.click('.evk-nl-wyk-usun[data-email="dodany.panel@example.com"]');
    await p.locator('#evk-nl-wyk-licznik').filter({ hasText: /^1$/ }).waitFor({ timeout: 10000 });
    const poUsunieciu = sonda('stan ' + prep.lista).wykluczenia;
    t.check('usunięty z listy (serwer i ekran)', jak(poUsunieciu, ['wykluczony.panel@example.com']), JSON.stringify(poUsunieciu));

    // ── Plik ──────────────────────────────────────────────────────────────
    t.section('import z pliku: ten sam podgląd');
    await p.check('input[name="evk-nl-import-type"][value="csv"]');
    await p.setInputFiles('#evk-nl-import-file', { name: 'lista.csv', mimeType: 'text/csv', buffer: Buffer.from('﻿email,Imię\nplik.panel@example.com,Olek\n') });
    await p.click('#evk-nl-import-podglad-btn');
    await p.locator('#evk-nl-import-podglad').waitFor({ state: 'visible', timeout: 15000 });
    t.check('plik: podgląd z liczbami', /Nowe: 1,/.test(await p.locator('#evk-nl-import-liczby').innerText()),
      await p.locator('#evk-nl-import-liczby').innerText());

    t.section('bez błędów JS');
    t.check('skrypt panelu bez błędów', bledy.length === 0, bledy.join(' | ') || 'brak');
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj ' + prep.lista);
  }
};
