/**
 * „Do sprawdzenia" dla pól języków w elementach (1.242.0).
 *
 * Decyzja zgłaszającego: po zmianie polskiego tekstu stare tłumaczenie
 * w polu elementu zostaje (1.241.0), ale wtyczka ma je oznaczyć, a strona
 * dalej je pokazuje. Stan idzie we własnych metadanych wpisu, liczony przy
 * każdym zapisie danych Bricksa (update_post_meta).
 *
 * Część pierwsza: prawdziwe update_post_meta() w testowym WordPressie,
 * kolejne zapisy i lista po każdym. Część druga: panel Tłumaczeń
 * w Chromium — sekcja, odnośnik do buildera, „Sprawdzone" przez AJAX.
 *
 * Czego stąd nie widać: że Bricks zapisuje treść przez update_post_meta
 * (standard WordPressa) — do potwierdzenia na stronie.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => JSON.parse(phpOutput('tl-do-sprawdzenia.php', a.join(' ')));

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress');
  const s = sonda('scenariusz');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !s.brak, s.brak || 'jest');
  if (s.brak) return;

  const pola = (krok) => (s[krok] && s[krok].lista ? s[krok].lista : []).map((m) => m.pole + ':' + m.jezyk).join(',');

  t.section('kolejne zapisy: kiedy miejsce jest do sprawdzenia');
  t.check('świeżo przetłumaczone: nic do sprawdzenia, stan dla dwóch pól (puste DE i pusta pozycja pominięte)',
    pola('1 przetłumaczone') === '' && JSON.stringify(s['1 przetłumaczone'].stan) === '["h1abcd|text|en","a1abcd|accordions.p1.title|en"]',
    JSON.stringify(s['1 przetłumaczone']));
  t.check('zmiana polskiego nagłówka → nagłówek EN do sprawdzenia', pola('2 zmiana nagłówka PL') === 'text:en', pola('2 zmiana nagłówka PL'));
  const m2 = (s['2 zmiana nagłówka PL'].lista || [])[0] || {};
  t.check('wpis listy niesie bieżący oryginał i stare tłumaczenie', m2.oryginal === 'Witaj ponownie' && m2.tlumaczenie === 'Welcome', JSON.stringify(m2));
  t.check('zmiana pozycji akordeonu → pozycja też na liście', pola('3 zmiana pozycji PL') === 'text:en,accordions.p1.title:en', pola('3 zmiana pozycji PL'));
  t.check('nowe tłumaczenie nagłówka zdejmuje go z listy', pola('4 nowe tłumaczenie nagłówka') === 'accordions.p1.title:en', pola('4 nowe tłumaczenie nagłówka'));
  t.check('oryginał wrócił do dawnej postaci → pozycja znika sama', pola('5 pozycja PL wraca do dawnej') === '', pola('5 pozycja PL wraca do dawnej'));
  t.check('kolejna zmiana po nowym tłumaczeniu znowu oznacza', pola('6 znów zmiana nagłówka PL') === 'text:en', pola('6 znów zmiana nagłówka PL'));
  t.check('„Sprawdzone" zdejmuje miejsce bez zmiany tłumaczenia', s['7 sprawdzone'].ok === true && s['7 sprawdzone'].lista.length === 0,
    JSON.stringify(s['7 sprawdzone']));
  t.check('„Sprawdzone" z obcym kluczem metadanych odrzucone', s['7b obcy klucz meta'] === false);
  t.check('wyczyszczone tłumaczenie wypada ze stanu', JSON.stringify(s['8 tłumaczenie nagłówka wyczyszczone'].stan) === '["a1abcd|accordions.p1.title|en"]',
    JSON.stringify(s['8 tłumaczenie nagłówka wyczyszczone']));

  t.section('zakres: nagłówek i stopka tak, obce metadane nie');
  const sz = s['9 szablon nagłówka'] || [];
  t.check('szablon nagłówka (typ spoza wyszukiwania) na liście', sz.length === 1 && sz[0].meta === '_bricks_page_header_2' && sz[0].oryginal === 'Nawigacja',
    JSON.stringify(sz));
  t.check('obca metadana w tym samym kształcie: bez stanu i bez listy', s['10 obca meta'].stan === '' && s['10 obca meta'].lista.length === 0,
    JSON.stringify(s['10 obca meta']));
  t.check('treść bez tłumaczeń: stanu nie ma w ogóle', s['11 bez tłumaczeń'] === '', JSON.stringify(s['11 bez tłumaczeń']));

  t.section('sekcja w panelu Tłumaczeń');
  const html = s['12 sekcja'] || '';
  t.check('sekcja z liczbą miejsc (strona + szablon nagłówka)', /do sprawdzenia \(2\)/.test(html), html.slice(0, 200));
  t.check('oryginał pokazany bez znaczników', html.includes('Witaj znów') && !html.includes('<b>znów</b>'), (html.match(/Witaj[^<]*/) || [''])[0]);
  t.check('tabela z nagłówkami kolumn i przyciskiem „Sprawdzone" na wiersz',
    (html.match(/<th scope="col">/g) || []).length === 6 && (html.match(/class="button tl-el-sprawdzone"/g) || []).length === 2);
  t.check('tabela w przewijanym opakowaniu (telefon)', html.includes('class="evo-tbl-wrap"'));
  t.check('bez miejsc sekcji nie ma', s['13 bez miejsc sekcji nie ma'] === '', JSON.stringify(s['13 bez miejsc sekcji nie ma']));

  // ── Panel w przeglądarce ────────────────────────────────────────────────
  t.section('panel: „Sprawdzone" w przeglądarce');
  const u = sonda('ui-ustaw');
  let serwer = null;
  let browser;
  try {
    t.check('miejsce do sprawdzenia przygotowane', u.lista && u.lista.length === 1, JSON.stringify(u.lista));
    serwer = await serwerWp.start(u.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(p, serwer.baza);
    await p.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=translations');
    const wiersze = p.locator('.tl-do-sprawdzenia tbody tr');
    t.check('sekcja widoczna nad frazami, jeden wiersz', await wiersze.count() === 1);
    if (await wiersze.count() !== 1) return;
    const nazwa = await p.locator('.tl-el-sprawdzone').first().getAttribute('aria-label');
    t.check('przycisk ma nazwę z kontekstem (strona, pole, język)', /^Sprawdzone: .+, text, EN$/.test(nazwa || ''), nazwa);
    const link = await p.locator('.tl-do-sprawdzenia tbody tr a').first().getAttribute('href');
    t.check('odnośnik prowadzi do edycji strony w Bricksie', /[?&]bricks=run/.test(link || ''), link);

    // Zły nonce: odmowa, stan bez zmian.
    const zly = await p.evaluate(async (url) => {
      const fd = new FormData();
      fd.append('action', 'evk_tl_el_sprawdzone'); fd.append('nonce', 'zly');
      fd.append('post_id', document.querySelector('.tl-el-sprawdzone').dataset.post);
      fd.append('meta_key', '_bricks_page_content_2'); fd.append('klucz', 'h1abcd|text|en');
      const r = await fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' });
      return r.status;
    }, serwer.baza + '/wp-admin/admin-ajax.php');
    t.check('żądanie ze złym nonce odrzucone', zly === 403, String(zly));
    t.check('po odrzuceniu miejsce dalej do sprawdzenia', sonda('stan', u.id).lista.length === 1);

    await p.click('.tl-el-sprawdzone');
    await p.locator('.tl-do-sprawdzenia').waitFor({ state: 'detached', timeout: 5000 }).catch(() => {});
    t.check('po kliknięciu wiersz i pusta sekcja znikają', await p.locator('.tl-do-sprawdzenia').count() === 0);
    t.check('stan zapisany: nic do sprawdzenia', sonda('stan', u.id).lista.length === 0);
    await p.reload();
    t.check('po odświeżeniu sekcji nie ma', await p.locator('.tl-do-sprawdzenia').count() === 0);
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
