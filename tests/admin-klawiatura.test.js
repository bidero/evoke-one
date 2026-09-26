/**
 * Panel z samej klawiatury (1.238.0): to, co do 1.237.0 dało się zrobić
 * wyłącznie myszą. Każde sprawdzenie idzie Tabem i Enterem/spacją przez
 * PRAWDZIWY markup i PRAWDZIWE skrypty (fixture panel-klawiatura.html):
 *   — Tłumaczenia: frazy i grupy rozwijał tylko klik w nagłówek (div), więc
 *     tłumacz bez myszy nie edytował niczego; flagi języków — div/img z onclick;
 *   — Animator: zwinięty wiersz otwierał tylko klik w nagłówek;
 *   — Import/Eksport: „zaznacz/odznacz wszystkie" były spanami;
 *   — Skrzynka: wiadomość otwierał tylko klik w wiersz (div); na wąskim
 *     ekranie fokus zostawał na schowanej liście;
 *   — Skrzynka formularzy: czipy zmiennych były spanami.
 * Strażnik statyczny (każdy element z kliknięciem osiągalny z klawiatury)
 * stoi w admin-etykiety; tu — czy to naprawdę DZIAŁA.
 */

const { phpOutput } = require('./lib/harness');

/** Tab aż fokus stanie na elemencie pasującym do selektora; ile naciśnięć albo 0. */
async function tabDo(p, sel, max = 150) {
  for (let i = 1; i <= max; i++) {
    await p.keyboard.press('Tab');
    if (await p.evaluate((s) => !!document.activeElement && document.activeElement.matches(s), sel)) return i;
  }
  return 0;
}
const fokus = (p) => p.evaluate(() => {
  const a = document.activeElement;
  return a ? a.tagName.toLowerCase() + (a.className ? '.' + String(a.className).split(' ')[0] : '') : '';
});

module.exports = async function (t) {
  const V = { width: 1400, height: 900 };

  // ── Tłumaczenia: frazy ──────────────────────────────────────────────────
  t.section('Tłumaczenia: grupa i fraza z klawiatury');
  const tl = await t.open('panel-klawiatura.html', { viewport: V, settle: 150,
    head: 'window.__tab = ' + JSON.stringify(phpOutput('tab.php', 'tl-translations "" "" "" stopka')) + ';' });
  const doGrupy = await tabDo(tl, 'button.tl-group-toggle-icon');
  await tl.keyboard.press('Enter');
  const grupa = await tl.evaluate(() => ({
    otwarta: document.querySelector('.tl-group-body').classList.contains('open'),
    stan: document.querySelector('.tl-group-toggle-icon').getAttribute('aria-expanded'),
  }));
  t.check('strzałka grupy osiągalna Tabem, Enter otwiera grupę (stan dla czytnika)',
    doGrupy > 0 && grupa.otwarta && grupa.stan === 'true', JSON.stringify({ doGrupy, ...grupa }));

  const doFrazy = await tabDo(tl, 'button.tl-chevron');
  const przed = await tl.evaluate(() => document.querySelector('.tl-chevron').getAttribute('aria-expanded'));
  await tl.keyboard.press('Enter');
  const fraza = await tl.evaluate(() => ({
    otwarta: document.querySelector('.tl-row-body').classList.contains('open'),
    stan: document.querySelector('.tl-chevron').getAttribute('aria-expanded'),
    nazwa: document.querySelector('.tl-chevron').getAttribute('aria-label'),
  }));
  await tl.keyboard.press('Tab');
  const wFrazie = await tl.evaluate(() => !!document.activeElement.closest('.tl-row-body'));
  t.check('strzałka frazy: Enter otwiera frazę, następny Tab jest już w jej polach',
    doFrazy > 0 && przed === 'false' && fraza.otwarta && fraza.stan === 'true' && wFrazie,
    JSON.stringify({ doFrazy, przed, ...fraza, wFrazie }));
  t.check('strzałka frazy nazywa się tekstem frazy', fraza.nazwa === 'Fraza: Kontakt', fraza.nazwa);

  // Pole polskie tej frazy — pisanie z klawiatury zmienia nazwę strzałki.
  await tl.focus('.tl-row-body textarea[data-field="pl"]');
  await tl.keyboard.press('Control+A');
  await tl.keyboard.type('Napisz do nas');
  const poEdycji = await tl.evaluate(() => document.querySelector('.tl-chevron').getAttribute('aria-label'));
  t.check('po edycji frazy strzałka mówi nowy tekst', poEdycji === 'Fraza: Napisz do nas', poEdycji);
  t.check('Tłumaczenia bez błędów JS', !tl.errors.length, tl.errors.join(' | ') || 'brak');
  await tl.close();

  // ── Tłumaczenia: flagi ──────────────────────────────────────────────────
  t.section('Tłumaczenia: flaga języka z klawiatury');
  const fl = await t.open('panel-klawiatura.html', { viewport: V, settle: 150,
    head: 'window.__tab = ' + JSON.stringify(phpOutput('tab.php', 'tl-languages "" "" "" stopka')) + ';' });
  const doFlagi = await tabDo(fl, '.tl-lang-flag-empty, .tl-lang-flag-preview');
  await fl.keyboard.press('Enter');
  await fl.keyboard.press('Tab');
  await fl.keyboard.press('Shift+Tab');
  await fl.keyboard.press(' ');
  const media = await fl.evaluate(() => window.__media.slice());
  t.check('flaga osiągalna Tabem; Enter i spacja otwierają wybór flagi',
    doFlagi > 0 && media.length === 2 && media.every((m) => m === 'Wybierz flagę'), JSON.stringify({ doFlagi, media }));
  t.check('flagi bez błędów JS', !fl.errors.length, fl.errors.join(' | ') || 'brak');
  await fl.close();

  // ── Animator ────────────────────────────────────────────────────────────
  t.section('Animator: zwijanie wiersza z klawiatury');
  const an = await t.open('panel-klawiatura.html', { viewport: V, settle: 200,
    head: 'window.__tab = ' + JSON.stringify(phpOutput('anim-tab.php', JSON.stringify(JSON.stringify(['alfa', 'beta'])))) + ';'
      + 'window.__animData = { rowStart: 100, url: "/fake-ajax", nonce: "n", saveNonce: "s" };' });
  const doZwin = await tabDo(an, 'button.evo-anim-toggle');
  await an.keyboard.press('Enter');
  const zwiniety = await an.evaluate(() => {
    const row = document.querySelector('.evo-anim-row');
    return { zwiniety: row.classList.contains('is-collapsed'), stan: row.querySelector('.evo-anim-toggle').getAttribute('aria-expanded'),
      pola: getComputedStyle(row.querySelector('.evo-anim-grid')).display };
  });
  await an.keyboard.press('Enter');
  const rozwiniety = await an.evaluate(() => {
    const row = document.querySelector('.evo-anim-row');
    return { zwiniety: row.classList.contains('is-collapsed'), stan: row.querySelector('.evo-anim-toggle').getAttribute('aria-expanded') };
  });
  t.check('przycisk zwijania osiągalny Tabem; Enter zwija (pola znikają) i rozwija, stan dla czytnika',
    doZwin > 0 && zwiniety.zwiniety && zwiniety.stan === 'false' && zwiniety.pola === 'none'
      && !rozwiniety.zwiniety && rozwiniety.stan === 'true', JSON.stringify({ doZwin, zwiniety, rozwiniety }));
  t.check('Animator bez błędów JS', !an.errors.length, an.errors.join(' | ') || 'brak');
  await an.close();

  // ── Import/Eksport ──────────────────────────────────────────────────────
  t.section('Import/Eksport: zaznaczanie modułów z klawiatury');
  const io = await t.open('panel-klawiatura.html', { viewport: V, settle: 150,
    head: 'window.__tab = ' + JSON.stringify(phpOutput('tab.php', 'tools-io')) + ';'
      + 'window.__ioAjax = { url: "/fake-ajax", nonce: "n" };' });
  const doOdznacz = await tabDo(io, 'button.evo-io-select-all:nth-of-type(2), .evo-io-select-all + .evo-io-select-all');
  await io.keyboard.press('Enter');
  const poOdznacz = await io.evaluate(() => [...document.querySelectorAll('.evo-export-cb')].filter((c) => c.checked).length);
  await io.keyboard.press('Shift+Tab');
  await io.keyboard.press('Enter');
  const poZaznacz = await io.evaluate(() => {
    const cb = [...document.querySelectorAll('.evo-export-cb')];
    return { zaznaczone: cb.filter((c) => c.checked).length, razem: cb.length, wyroznione: document.querySelectorAll('.evo-io-module.selected').length };
  });
  t.check('„odznacz wszystkie" i „zaznacz wszystkie" osiągalne Tabem, działają Enterem',
    doOdznacz > 0 && poOdznacz === 0 && poZaznacz.zaznaczone === poZaznacz.razem && poZaznacz.razem > 5,
    JSON.stringify({ doOdznacz, poOdznacz, ...poZaznacz }));
  await io.focus('.evo-export-cb');
  await io.keyboard.press(' ');
  const modul = await io.evaluate(() => {
    const cb = document.querySelector('.evo-export-cb');
    return { zaznaczony: cb.checked, wyrozniony: cb.closest('.evo-io-module').classList.contains('selected') };
  });
  t.check('spacja na module: pole i wyróżnienie idą razem', !modul.zaznaczony && !modul.wyrozniony, JSON.stringify(modul));
  t.check('Import/Eksport bez błędów JS', !io.errors.length, io.errors.join(' | ') || 'brak');
  await io.close();

  // ── Skrzynka ────────────────────────────────────────────────────────────
  const lista = { success: true, data: { total: 2, pages: 1, page: 1, items: [
    { id: 11, name: 'Jan Kowalski', date: '24.09', form_id: 'kontakt', form_label: 'Kontakt', is_read: false,
      lines: [{ type: 'preview', text: 'Pytanie o ofertę' }] },
    { id: 12, name: 'Anna Nowak', date: '23.09', form_id: 'kontakt', form_label: 'Kontakt', is_read: true, lines: [] },
  ] } };
  const szczegoly = { success: true, data: { id: 11, form_id: 'kontakt', form_label: 'Kontakt',
    fields: [{ key: 'temat', label: 'Temat', value: 'Pytanie o ofertę' }], has_template: false, rendered: '',
    email: 'jan@example.test', name: 'Jan Kowalski', subtitle: '', header_lines: [],
    meta: { date: '24.09', ip: '', browser: '', os: '', referrer: '', user: '' } } };
  const headSkrzynki = 'window.__tab = ' + JSON.stringify(phpOutput('inbox-page.php')) + ';'
    + 'window.__odpowiedzi = ' + JSON.stringify({ evk_inbox_list: lista, evk_inbox_detail: szczegoly,
      evk_inbox_forms: { success: true, data: { forms: [] } } }) + ';';

  for (const szer of [1400, 360]) {
    t.section('Skrzynka ' + szer + ' px: wiadomość z klawiatury');
    const sk = await t.open('panel-klawiatura.html', { viewport: { width: szer, height: 800 }, settle: 200, head: headSkrzynki });
    const doWiadomosci = await tabDo(sk, 'button.evk-inbox-item-inner');
    await sk.keyboard.press('Enter');
    await sk.waitForTimeout(50);
    const otwarta = await sk.evaluate(() => ({
      zadanie: (window.__zadania.filter((z) => z.akcja === 'evk_inbox_detail').pop() || {}).dane,
      fokus: document.activeElement && document.activeElement.matches('#evk-inbox-detail h2'),
      naglowek: (document.querySelector('#evk-inbox-detail h2') || {}).textContent,
    }));
    t.check('wiadomość osiągalna Tabem, Enter ją otwiera',
      doWiadomosci > 0 && !!otwarta.zadanie && Number(otwarta.zadanie.id) === 11 && otwarta.naglowek === 'Jan Kowalski',
      JSON.stringify({ doWiadomosci, id: otwarta.zadanie && otwarta.zadanie.id, naglowek: otwarta.naglowek }));
    t.check('po otwarciu fokus na nagłówku wiadomości', !!otwarta.fokus, 'fokus na: ' + await fokus(sk));
    if (szer < 782) {
      // Powrót leży PRZED nagłówkiem — Shift+Tab, potem Enter.
      await sk.keyboard.press('Shift+Tab');
      const naPowrocie = await sk.evaluate(() => document.activeElement && document.activeElement.matches('.evk-inbox-back'));
      // Enter tylko na powrocie: gdzie indziej mógłby trafić w odnośnik i przeładować stronę.
      if (naPowrocie) await sk.keyboard.press('Enter');
      const wrocil = await sk.evaluate(() => ({
        lista: getComputedStyle(document.querySelector('.evk-inbox-sidebar')).display !== 'none',
        fokus: document.activeElement && document.activeElement.matches('.evk-inbox-item.active .evk-inbox-item-inner'),
      }));
      t.check('wąski ekran: powrót z klawiatury pokazuje listę i oddaje fokus wiadomości',
        naPowrocie && wrocil.lista && wrocil.fokus, JSON.stringify({ naPowrocie, ...wrocil, fokusNa: await fokus(sk) }));
    }
    t.check('Skrzynka bez błędów JS', !sk.errors.length, sk.errors.join(' | ') || 'brak');
    await sk.close();
  }

  // ── Skrzynka formularzy: czipy zmiennych ────────────────────────────────
  t.section('Skrzynka formularzy: zmienna do szablonu z klawiatury');
  const fi = await t.open('panel-klawiatura.html', { viewport: V, settle: 200,
    head: 'window.__tab = ' + JSON.stringify(phpOutput('tab.php', 'forminbox')) + ';' });
  const maCzipy = await fi.evaluate(() => document.querySelectorAll('button.evk-var-chip').length);
  const doCzipa = await tabDo(fi, 'button.evk-var-chip', 250);
  const zmienna = await fi.evaluate(() => document.activeElement && document.activeElement.getAttribute('data-var'));
  await fi.keyboard.press('Enter');
  const szablon = await fi.evaluate(() => (document.getElementById('evk-template-editor') || {}).value || '');
  t.check('czip zmiennej osiągalny Tabem, Enter wstawia zmienną do szablonu',
    maCzipy > 0 && doCzipa > 0 && !!zmienna && szablon.includes(zmienna),
    JSON.stringify({ maCzipy, doCzipa, zmienna, szablon: szablon.slice(0, 80) }));
  t.check('Skrzynka formularzy bez błędów JS', !fi.errors.length, fi.errors.join(' | ') || 'brak');
  await fi.close();
};
