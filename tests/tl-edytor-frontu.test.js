/**
 * Edytor tłumaczeń na froncie (1.239.0): oryginał tylko do odczytu, telefon.
 *
 * ZGŁOSZONE Z UŻYCIA: zmiana polskiej frazy w edytorze na froncie zmieniała
 * KLUCZ w słowniku, a nie tekst strony. Strona dalej miała stary tekst, więc
 * od tej chwili nie pasowała do żadnej frazy i tłumaczenie przestawało
 * działać — a edytor podmieniał tekst w przeglądarce, więc do odświeżenia
 * wyglądało, że się udało. Decyzja zgłaszającego: pole polskie w edytorze
 * tylko do odczytu (oryginał zmienia się w Bricksie). Serwer odrzuca zmianę
 * frazy także wtedy, gdy ktoś obejdzie pole.
 *
 * Telefon (zakres analizy wybrany przez zgłaszającego): panel edytora miał
 * na sztywno 400 px szerokości, więc na ekranie 360 px lewy brzeg wychodził
 * poza ekran. Do tego przycisk zamknięcia 16×16 px i pola 13 px (Safari na
 * iPhonie powiększa stronę przy polu poniżej 16 px — tego tu nie zobaczymy,
 * sprawdzamy więc sam rozmiar).
 *
 * Droga jak na żywo: testowy WordPress (tools/testowy-wp.sh) przez php -S,
 * Chromium, prawdziwe żądania AJAX edytora.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => JSON.parse(phpOutput('tl-edytor-frontu.php', a));
const FRAZA = 'Hello world!';

/** Wiersz słownika o danej frazie polskiej albo null. */
const wiersz = (pl) => sonda('stan').wiersze.find((w) => w.pl === pl) || null;

/** Włącza tryb edycji i otwiera panel frazy. `dotyk` — stuknięcia zamiast kliknięć. */
async function otworzFraze(p, dotyk) {
  const fab = p.locator('#tl-fab');
  await (dotyk ? fab.tap() : fab.click());
  const fraza = p.locator('[data-tl-phrase="' + FRAZA + '"]').first();
  await fraza.waitFor({ timeout: 5000 });
  await (dotyk ? fraza.tap() : fraza.click());
  await p.locator('#tl-side-panel.open').waitFor({ timeout: 5000 });
  await p.locator('#tl-panel-fields textarea[data-lang="en"]').waitFor({ timeout: 5000 });
  await p.waitForTimeout(350); // przejście panelu (right .3s)
}

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress przez php -S');
  const start = sonda('ustaw');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !start.brak, start.brak || start.wp);
  if (start.brak) return;

  let serwer = null;
  let browser;
  try {
    serwer = await serwerWp.start(start.wp);
    const baza = serwer.baza;
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const kontekst = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const p = await kontekst.newPage();
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(p, baza);
    await p.goto(baza + '/');
    t.check('edytor na froncie jest (przycisk 🌐)', await p.locator('#tl-fab').count() === 1);

    // ── Oryginał tylko do odczytu ───────────────────────────────────────────
    t.section('pole „Polski (bazowy)" tylko do odczytu');
    await otworzFraze(p, false);
    const pl = p.locator('#tl-panel-fields textarea[data-lang="pl"]');
    t.check('pole polskie ma readonly', await pl.evaluate((el) => el.readOnly));
    await pl.click();
    await p.keyboard.type(' ZMIANA');
    t.check('wpisywanie nie zmienia oryginału', (await pl.inputValue()) === FRAZA, await pl.inputValue());
    const uwaga = await p.locator('#tl-panel-fields').innerText();
    t.check('panel mówi, gdzie zmienia się oryginał (Bricks)', /Bricks/.test(uwaga), uwaga.slice(0, 160));

    await p.locator('#tl-panel-fields textarea[data-lang="en"]').fill('Hello EN 2');
    await p.click('#tl-panel-save');
    await p.locator('#tl-panel-status.ok').waitFor({ timeout: 5000 }).catch(() => {});
    const poZapisie = wiersz(FRAZA);
    t.check('zapis tłumaczenia działa, fraza polska bez zmian',
      !!poZapisie && poZapisie.en === 'Hello EN 2', JSON.stringify(sonda('stan')));

    // ── Serwer: zmiana frazy odrzucona także z pominięciem pola ─────────────
    t.section('serwer odrzuca zmianę frazy polskiej, nowe frazy przyjmuje');
    const nonce = await p.evaluate(() => {
      const m = document.documentElement.innerHTML.match(/const NONCE = "([^"]+)"/);
      return m ? m[1] : '';
    });
    const zadanie = (dane) => p.evaluate(async ({ url, dane }) => {
      const r = await fetch(url, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams(dane) });
      return r.json();
    }, { url: baza + '/wp-admin/admin-ajax.php', dane });
    const zmiana = await zadanie({ action: 'tl_inline_save_full', nonce, old_pl: FRAZA, pl: 'Hello changed', translations: '{"en":"x"}', dd_key: '', group_id: '' });
    t.check('zmiana frazy odrzucona z komunikatem', zmiana && zmiana.success === false && /Bricks/.test(String(zmiana.data)), JSON.stringify(zmiana));
    t.check('słownik bez zmian po odrzuceniu', !!wiersz(FRAZA) && !wiersz('Hello changed') && wiersz(FRAZA).en === 'Hello EN 2',
      JSON.stringify(sonda('stan')));
    const nowa = await zadanie({ action: 'tl_inline_save_full', nonce, old_pl: '', pl: 'Nowa fraza testowa', translations: '{"en":"New phrase"}', dd_key: '', group_id: '' });
    t.check('nowa fraza z edytora dalej trafia do słownika', nowa && nowa.success === true && !!wiersz('Nowa fraza testowa'),
      JSON.stringify(nowa));

    // ── Telefon ─────────────────────────────────────────────────────────────
    t.section('telefon 360 px: panel mieści się na ekranie');
    const tel = await browser.newContext({
      viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2,
      storageState: await kontekst.storageState(),
    });
    const m = await tel.newPage();
    m.on('pageerror', (e) => bledy.push(e.message));
    await m.goto(baza + '/');
    await otworzFraze(m, true);
    const uklad = await m.evaluate(() => {
      const r = (sel) => { const el = document.querySelector(sel); if (!el) return null; const b = el.getBoundingClientRect(); return { l: b.left, r: b.right, w: b.width, h: b.height }; };
      const pole = document.querySelector('#tl-panel-fields textarea[data-lang="en"]');
      return {
        ekran: innerWidth, panel: r('#tl-side-panel'), zamknij: r('#tl-panel-close'), zapisz: r('#tl-panel-save'),
        pole: pole ? parseFloat(getComputedStyle(pole).fontSize) : 0,
      };
    });
    t.check('panel w całości na ekranie (do 1.238.0: 400 px na 360)',
      !!uklad.panel && uklad.panel.l >= 0 && uklad.panel.r <= uklad.ekran + 0.5, JSON.stringify(uklad));
    t.check('przycisk zamknięcia co najmniej 40×40 px', !!uklad.zamknij && uklad.zamknij.w >= 40 && uklad.zamknij.h >= 40, JSON.stringify(uklad.zamknij));
    t.check('pola co najmniej 16 px (Safari nie powiększa strony)', uklad.pole >= 16, String(uklad.pole));
    await m.locator('#tl-panel-close').tap();
    await m.waitForTimeout(400);
    t.check('stuknięcie w zamknięcie zamyka panel', await m.locator('#tl-side-panel.open').count() === 0);
    await tel.close();

    t.section('komputer: bez zmian w układzie');
    const duzy = await p.evaluate(() => {
      const b = document.querySelector('#tl-side-panel').getBoundingClientRect();
      const pole = document.querySelector('#tl-panel-fields textarea[data-lang="en"]');
      return { w: b.width, pole: pole ? parseFloat(getComputedStyle(pole).fontSize) : 0 };
    });
    t.check('na komputerze panel dalej 400 px, pola 13 px', duzy.w === 400 && duzy.pole === 13, JSON.stringify(duzy));

    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('przywroc');
  }
};
