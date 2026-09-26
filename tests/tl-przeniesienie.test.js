/**
 * Tłumaczenia ze słownika w polach języków elementów (1.244.0).
 *
 * Decyzja zgłaszającego: pole „Tłumaczenie EN" w elemencie pokazuje to, co
 * widać na stronie. Puste pole + polski tekst będący w całości frazą słownika
 * → pole dostaje tłumaczenie, przy każdym zapisie danych Bricksa i z przycisku
 * w zakładce „Teksty w elementach" (dla stron zapisanych wcześniej).
 *
 * Część pierwsza: prawdziwe update_post_meta() w testowym WordPressie,
 * kolejne zapisy i pola po każdym. Najważniejszy krok to zapis buildera,
 * który NIE ZNA pól dopisanych przez serwer, ze zmianą polskiego tekstu —
 * tłumaczenie ma zostać i trafić do „Do sprawdzenia". Część druga: zakładka
 * w Chromium — podgląd, przeniesienie, odmowa przy złym nonce.
 *
 * Czego stąd nie widać: że Bricks zapisuje treść przez update_post_meta
 * i co wysyła po wyczyszczeniu pola (brak klucza czy pusty napis) — próba
 * 1.243.0 i sprawdzenie na stronie.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => JSON.parse(phpOutput('tl-przeniesienie.php', a.join(' ')));

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress');
  const s = sonda('scenariusz');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !s.brak, s.brak || 'jest');
  if (s.brak) return;

  t.section('mapa pól: zapisuje ją filtr kontrolek (tak jak w builderze)');
  t.check('nagłówek: tekst; akordeon: tytuł i treść pozycji (ikona nie); kontener: znany, bez pól',
    JSON.stringify(s.mapa) === '{"heading":{"pola":["text"],"listy":[]},"accordion":{"pola":[],"listy":{"accordions":["title","content"]}},"container":{"pola":[],"listy":[]}}',
    JSON.stringify(s.mapa));

  const p1 = s['1 pierwszy zapis'] || {};
  t.section('zapis: puste pola języków dostają tłumaczenie ze słownika');
  t.check('nagłówek „Kontakt": EN i DE ze słownika', p1['h1abcd|evk_tl_en__text'] === 'Contact' && p1['h1abcd|evk_tl_de__text'] === 'Kontakt (DE)', JSON.stringify(p1));
  t.check('wpisane tłumaczenie nietknięte, puste obok uzupełnione', p1['h2abcd|evk_tl_en__text'] === 'Get in touch' && p1['h2abcd|evk_tl_de__text'] === 'Kontakt (DE)',
    JSON.stringify(p1));
  t.check('pozycja listy: tytuł i jeden akapit edytora (w <p>), język bez tłumaczenia w słowniku pominięty',
    p1['a1abcd|accordions.p1.evk_tl_en__title'] === 'One' && p1['a1abcd|accordions.p1.evk_tl_en__content'] === '<p>One</p>'
      && !('a1abcd|accordions.p1.evk_tl_de__title' in p1), JSON.stringify(p1));
  t.check('tekst ze znacznikiem w środku i dwa akapity: zostają słownikowi', !Object.keys(p1).some((k) => k.includes('.p2.')), JSON.stringify(p1));
  t.check('typ spoza mapy i element bez pól: nietknięte', !Object.keys(p1).some((k) => /^(x1abcd|c1abcd)\|/.test(k)), JSON.stringify(p1));

  t.section('zapis buildera bez pól dopisanych przez serwer');
  const p2 = s['2 zapis bez pól, zmiana PL'] || {};
  t.check('SEDNO: polski zmieniony, a tłumaczenia zostały (przeniesione ze starego zapisu)',
    (p2.pola || {})['h1abcd|evk_tl_en__text'] === 'Contact' && (p2.pola || {})['h1abcd|evk_tl_de__text'] === 'Kontakt (DE)'
      && (p2.pola || {})['h2abcd|evk_tl_en__text'] === 'Get in touch', JSON.stringify(p2.pola));
  t.check('i miejsce jest na liście „Do sprawdzenia" (EN i DE)', JSON.stringify(p2.do_sprawdzenia) === '["text:en","text:de"]', JSON.stringify(p2.do_sprawdzenia));
  const p3 = s['3 jawnie puste EN'] || {};
  t.check('pole jawnie puste (klucz jest) przy polskim spoza słownika: zostaje puste, DE przeniesione',
    p3['h1abcd|evk_tl_en__text'] === '' && p3['h1abcd|evk_tl_de__text'] === 'Kontakt (DE)', JSON.stringify(p3));
  const p4 = s['4 puste EN, PL ze słownika'] || {};
  t.check('pole puste, a polski znów jest frazą słownika: dostaje tłumaczenie', p4['h1abcd|evk_tl_en__text'] === 'Contact', JSON.stringify(p4));

  t.section('zakres: nagłówek szablonu tak, obce metadane nie');
  t.check('obca metadana w tym samym kształcie: bez zmian', JSON.stringify(s['5 obca meta']) === '{"h2abcd|evk_tl_en__text":"Get in touch"}',
    JSON.stringify(s['5 obca meta']));
  t.check('szablon nagłówka (typ spoza wyszukiwania): uzupełniony', (s['6 szablon nagłówka'] || {})['h1abcd|evk_tl_en__text'] === 'Contact',
    JSON.stringify(s['6 szablon nagłówka']));

  t.section('przycisk: strona zapisana przed 1.244.0');
  const pd = s['7b podgląd'] || {};
  t.check('podgląd: strona z liczbą pól, częścią i odnośnikiem do buildera',
    pd.strona && pd.strona.zmiany === 5 && pd.strona.czesc === 'Treść' && /[?&]bricks=run/.test(pd.strona.adres), JSON.stringify(pd.strona));
  t.check('podgląd wymienia typ elementu spoza mapy', pd.nieznane && pd.nieznane['nieznany-element'] >= 1 && pd.znane === 3, JSON.stringify(pd));
  t.check('podgląd niczego nie zapisuje', JSON.stringify(s['7c po podglądzie nic nie zapisane']) === JSON.stringify(s['7a przed przyciskiem']),
    JSON.stringify(s['7c po podglądzie nic nie zapisane']));
  const pe = s['7e po zapisie'] || {};
  t.check('„Przenieś" zapisuje to samo, co zapis strony', JSON.stringify(pe) === JSON.stringify(p1), JSON.stringify(pe));
  t.check('drugi podgląd: tej strony już nie ma (nic do przeniesienia)', (s['7f drugi podgląd'] || {}).strona === null, JSON.stringify(s['7f drugi podgląd']));

  // ── Zakładka w przeglądarce ─────────────────────────────────────────────
  t.section('zakładka „Teksty w elementach" w przeglądarce');
  const u = sonda('ui-ustaw');
  let serwer = null;
  let browser;
  try {
    t.check('strona sprzed 1.244.0 przygotowana (bez pól ze słownika)', u.id && JSON.stringify(u.pola) === '{"h2abcd|evk_tl_en__text":"Get in touch"}',
      JSON.stringify(u));
    serwer = await serwerWp.start(u.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(p, serwer.baza);
    await p.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=elementy');
    const stan = p.locator('.tl-przeniesienie-stan');
    t.check('zakładka ma odnośnik w pasku zakładek i jest aktywna',
      await p.locator('.tl-tabs a.tl-tab.active', { hasText: 'Teksty w elementach' }).count() === 1);
    t.check('„Przenieś" wyłączony przed podglądem', await p.locator('[data-tl-el-tryb="zapisz"]').isDisabled());

    // Zły nonce: odmowa, nic nie zapisane.
    const zly = await p.evaluate(async (url) => {
      const fd = new FormData();
      fd.append('action', 'evk_tl_el_przenies'); fd.append('nonce', 'zly'); fd.append('tryb', 'zapisz');
      const r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
      return r.status;
    }, serwer.baza + '/wp-admin/admin-ajax.php');
    t.check('żądanie ze złym nonce odrzucone', zly === 403, String(zly));
    t.check('po odrzuceniu nic nie zapisane', JSON.stringify(sonda('stan', u.id).pola) === JSON.stringify(u.pola));

    await p.click('[data-tl-el-tryb="podglad"]');
    await p.waitForFunction(() => /Do przeniesienia|Nic do przeniesienia/.test(document.querySelector('.tl-przeniesienie-stan').textContent), null, { timeout: 15000 }).catch(() => {});
    const tekstPodgladu = await stan.textContent();
    const wiersz = p.locator('.tl-przeniesienie-wynik tbody tr', { hasText: 'Test TL — przeniesienie' });
    t.check('podgląd: liczba w komunikacie i wiersz strony w tabeli (w przewijanym opakowaniu)',
      /Do przeniesienia tłumaczeń: \d+/.test(tekstPodgladu || '') && await wiersz.count() >= 1
        && await p.locator('.tl-przeniesienie-wynik .evo-tbl-wrap table.evo-table th[scope="col"]').count() === 3, tekstPodgladu);
    t.check('po podglądzie nic nie zapisane', JSON.stringify(sonda('stan', u.id).pola) === JSON.stringify(u.pola));
    t.check('„Przenieś" włączony po podglądzie z wynikiem', !(await p.locator('[data-tl-el-tryb="zapisz"]').isDisabled()));

    await p.click('[data-tl-el-tryb="zapisz"]');
    await p.waitForFunction(() => /Przeniesiono|Nic do przeniesienia/.test(document.querySelector('.tl-przeniesienie-stan').textContent), null, { timeout: 15000 }).catch(() => {});
    t.check('po przeniesieniu: komunikat z liczbą', /Przeniesiono tłumaczenia: \d+/.test(await stan.textContent() || ''), await stan.textContent());
    const po = sonda('stan', u.id).pola;
    t.check('pola zapisane w danych strony', po['h1abcd|evk_tl_en__text'] === 'Contact' && po['a1abcd|accordions.p1.evk_tl_en__title'] === 'One', JSON.stringify(po));
    t.check('„Przenieś" znowu wyłączony', await p.locator('[data-tl-el-tryb="zapisz"]').isDisabled());
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
