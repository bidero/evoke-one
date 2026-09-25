/**
 * Logi 404 na PRAWDZIWYM WordPressie (1.234.0): jeden wiersz na adres,
 * limit na IP, przeniesienie starych wpisów i „Przekieruj" w panelu.
 *
 * 1.234.1, zgłoszone z użycia („nic się nie dopisuje i wizualnie się
 * rozjeżdża"): lista szła od najczęstszych, więc świeże 404 lądowało na dole;
 * tabela w układzie automatycznym rozpychała się do 1282 px w pudełku na 748
 * (wiersze do 513 px, „Przekieruj" poza pudełkiem); pole celu stało otwarte
 * w każdym wierszu (`display: flex` wygrywał z `hidden`). Przy okazji: 404
 * ze strony głównej z zapytaniem zlewały się w wiersz „/", którego
 * „Przekieruj" przekierowałoby stronę główną, a polskie znaki w adresie
 * wypadały przy zapisie (`/usługi/` → `/usugi/`).
 *
 * Do 1.233.5 każde trafienie 404 było nowym wpisem typu evk_404_log.
 * Zmierzone przed zmianą: 26–32 zapytania na trafienie i `save_post` przy
 * każdym (na ten hak reagują inne wtyczki — czyszczenie pamięci stron,
 * indeksy). Skaner podatności sprawdzający setki adresów robił z tego lawinę
 * zapisów. Progi kosztu niżej stoją przy zmierzonych wartościach po zmianie:
 * nowy adres 4 zapytania, kolejne wejście 2.
 *
 * Panel przez `php -S` i Chromium, jak newsletter-panel.
 * Środowisko: tools/testowy-wp.sh. Sonda: tests/php/zapis-wp-logi404.php.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('zapis-wp-logi404.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress z prawdziwą bazą');
  const s = sonda('logika');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !s.brak && !!s.koszt, s.brak || 'jest');
  if (s.brak || !s.koszt) return;

  // ── Koszt ─────────────────────────────────────────────────────────────
  t.section('koszt trafienia: kilka zapytań, bez save_post');
  const k = s.koszt;
  t.check('nowy adres: najwyżej 4 zapytania (do 1.233.5: 26–32)', k.nowy.zapytan <= 4, JSON.stringify(k.nowy));
  t.check('kolejne wejście w ten sam adres: najwyżej 2 zapytania', k.kolejny.zapytan <= 2, JSON.stringify(k.kolejny));
  t.check('żadnego save_post (inne wtyczki nie reagują na 404)', k.nowy.save_post === 0 && k.kolejny.save_post === 0,
    JSON.stringify([k.nowy.save_post, k.kolejny.save_post]));

  // ── Jeden wiersz na adres ─────────────────────────────────────────────
  t.section('jeden wiersz na adres');
  t.check('/Oferta/, /oferta i /oferta/?utm… to jeden wiersz', s.oferta_wierszy === 1, String(s.oferta_wierszy));
  t.check('trzy wejścia, dane ostatniego (IP, skąd, przeglądarka), adres z pierwszego',
    !!s.oferta && s.oferta.hits === 3 && s.oferta.ip === '198.51.100.3' && s.oferta.referrer === 'https://b.example/'
      && s.oferta.ua === 'Mozilla/5.0 B' && s.oferta.url === '/t404-Oferta/',
    JSON.stringify(s.oferta));
  t.check('roboty z listy nie trafiają do logu', s.robot === null, JSON.stringify(s.robot));

  t.section('strona główna z zapytaniem i polskie znaki (1.234.1)');
  t.check('/?p=… z utm i bez to jeden wiersz, /?author=… osobno (1.234.0: wszystko jako „/")',
    jak(s.zapytania, [['/?author=77', 1], ['/?p=987654', 2]]), JSON.stringify(s.zapytania));
  t.check('zakodowany adres zapisany zdekodowany, wielkość liter bez znaczenia (1.234.0: „/t404-usugi/")',
    !!s.utf && s.utf.url === '/t404-usługi/' && s.utf.hits === 2, JSON.stringify(s.utf));

  // ── Limit ─────────────────────────────────────────────────────────────
  t.section('limit: najwyżej 20 adresów na minutę z jednego IP');
  const l = s.limit || {};
  t.check('skaner: 20 zapisanych, 5 odrzuconych', jak(l.wyniki, { nowy: 20, limit: 5 }) && l.wierszy_ip === 20,
    JSON.stringify([l.wyniki, l.wierszy_ip]));
  t.check('inny adres IP w tej samej minucie dalej zapisywany', l.inny_ip === 'nowy', String(l.inny_ip));
  t.check('ten sam IP po minucie znowu zapisywany', l.po_minucie === 'nowy', String(l.po_minucie));

  // ── Maks. adresów ─────────────────────────────────────────────────────
  t.section('„Maks. adresów": zostają ostatnio odwiedzane');
  const oczekiwane = [5, 6, 7, 8, 9, 10, 11, 12, 13, 14].map((i) => '/t404-max-' + i + '/');
  t.check('15 adresów przy limicie 10: zostaje 10 najświeższych', jak(s.max, oczekiwane), JSON.stringify(s.max));

  // ── Przekierowania ────────────────────────────────────────────────────
  t.section('przekierowania: adres z regułą nie trafia do logu');
  const p = s.przekierowany || {};
  t.check('adres z przekierowaniem 301 pomijany', p.w_logu === null, JSON.stringify(p.w_logu));
  t.check('cel bez ukośnika to ścieżka w serwisie (nie „http://cel…")', p.cel === '/cel-bez-ukosnika' && p.z === '/t404-przekierowany',
    JSON.stringify([p.z, p.cel]));
  t.check('ten sam adres „Z" zmienia cel istniejącej reguły, bez duplikatu', p.ten_sam_z === true, String(p.ten_sam_z));
  t.check('przekierowanie adresu na samego siebie odrzucone', p.petla === 'evk_301_petla', String(p.petla));
  t.check('reguła z polskimi znakami łapie zakodowane żądanie (nie trafia do logu)', p.utf_w_logu === null, JSON.stringify(p.utf_w_logu));

  // ── Przeniesienie ─────────────────────────────────────────────────────
  t.section('aktualizacja: wpisy sprzed 1.234.0 przeniesione');
  const m = s.przeniesione || {};
  t.check('trzy stare wpisy jednego adresu → jeden wiersz, trzy wejścia',
    !!m.stary && m.stary.hits === 3 && m.stary.first_seen === m.czas_od && m.stary.last_seen === m.czas_do,
    JSON.stringify([m.stary, m.czas_od, m.czas_do]));
  t.check('dane z najpóźniejszego wpisu (nie z ostatniego w kolejności)', !!m.stary && m.stary.ip === '203.0.113.2' && m.stary.referrer === 'https://y.example/',
    JSON.stringify(m.stary));
  t.check('drugi adres osobno', !!m.inny && m.inny.hits === 1, JSON.stringify(m.inny));
  t.check('stare wpisy usunięte, wersja tabeli zapisana', m.zostalo_starych === 0 && m.wersja === '2',
    JSON.stringify([m.zostalo_starych, m.wersja]));

  // ── Panel ─────────────────────────────────────────────────────────────
  t.section('panel: kolejność, układ i „Przekieruj"');
  const prep = sonda('przygotuj-panel');
  if (prep.brak || !prep.wp) { t.check('sonda panelu', false, prep.brak || JSON.stringify(prep)); return; }
  let serwer = null;
  let browser;
  try {
    serwer = await serwerWp.start(prep.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const pg = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    const bledy = [];
    pg.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(pg, serwer.baza);
    const ekran = serwer.baza + '/wp-admin/options-general.php?page=evoke-one&tab=narzedzia&sub=logs404';
    const adresy = () => pg.$$eval('tr[data-evk-404-wiersz] [data-evk-404-adres]', (el) => el.map((e) => e.innerText.trim()));
    const dlugi = '/t404-wp-content/uploads/2021/03/Zdjecie-z-realizacji-bardzo-dluga-nazwa-pliku-do-sprawdzenia-lamania-wierszy-1024x768.jpg';
    await pg.goto(ekran);

    const lista = await adresy();
    t.check('najnowsze na górze (1.234.0: od najczęstszych, świeże 404 na dole)',
      jak(lista, ['/', dlugi, '/?p=987654', '/t404-usługi-panel/', '/t404-kontakt-old/', '/t404-stara-oferta/']), JSON.stringify(lista));
    const ostrzezenie = await pg.locator('[data-evk-404-301-wylaczone]').isVisible();
    t.check('ostrzeżenie: przekierowania wyłączone', ostrzezenie, ostrzezenie ? 'widać' : 'ostrzeżenia nie ma');

    /* Zmierzone przy oknie 1280, zanim stanęły progi: karta ma na tabelę
       748 px; po zmianie tabela 748 px, najwyższy wiersz (długi adres,
       „skąd", przeglądarka) 119 px, przycisk 68 px od prawej krawędzi.
       W 1.234.0 tabela 1282 px, wiersze do 513 px, „Przekieruj" poza
       pudełkiem. */
    const u = await pg.evaluate(() => {
      const tab = document.querySelector('table[data-evk-404-tabela]');
      const wr = tab.parentElement.getBoundingClientRect();   // pudełko z przewijaniem (.evo-tbl-wrap)
      return {
        tabela: Math.round(tab.getBoundingClientRect().width), pudelko: Math.round(wr.width),
        wiersz: Math.max(...[...tab.querySelectorAll('tbody tr')].map((tr) => Math.round(tr.getBoundingClientRect().height))),
        przyciski: [...tab.querySelectorAll('[data-evk-404-przekieruj]')].map((b) => Math.round(wr.right - b.getBoundingClientRect().right)),
        pola: [...tab.querySelectorAll('[data-evk-404-cel]')].filter((e) => e.offsetParent !== null).length,
      };
    });
    t.check('tabela mieści się w pudełku (1.234.0: 1282 px na 748)', u.tabela <= u.pudelko, JSON.stringify(u));
    t.check('wiersze niskie: najwyższy do 200 px (1.234.0: do 513)', u.wiersz <= 200, String(u.wiersz));
    t.check('„Przekieruj" w obrębie pudełka, w czterech wierszach', u.przyciski.length === 4 && u.przyciski.every((x) => x >= 0), JSON.stringify(u.przyciski));
    t.check('pole celu schowane do kliknięcia (1.234.0: otwarte w każdym wierszu)', u.pola === 0, String(u.pola));

    const bez = await pg.$$eval('tr[data-evk-404-wiersz]', (tr) => tr
      .filter((w) => w.querySelector('[data-evk-404-bez-przekierowania]') && !w.querySelector('[data-evk-404-przekieruj]'))
      .map((w) => w.querySelector('[data-evk-404-adres]').innerText.trim()));
    t.check('„/" i „/?p=…" bez „Przekieruj" (reguła przekierowałaby stronę główną)', jak(bez, ['/', '/?p=987654']), JSON.stringify(bez));
    const odmowa = await pg.evaluate(async () => {
      const tr = [...document.querySelectorAll('tr[data-evk-404-wiersz]')]
        .find((w) => w.querySelector('[data-evk-404-adres]').innerText.trim() === '/?p=987654');
      const f = new FormData();
      f.append('action', 'evk_404_przekieruj');
      f.append('nonce', document.getElementById('evk-clear-404').getAttribute('data-nonce'));
      f.append('id', tr.getAttribute('data-evk-404-wiersz'));
      f.append('to', '/gdziekolwiek');
      return (await fetch(window.ajaxurl, { method: 'POST', body: f, credentials: 'same-origin' })).json();
    });
    t.check('serwer też odmawia przekierowania wiersza z zapytaniem', !!odmowa && odmowa.success === false && /nie da się przekierować/.test(String(odmowa.data)),
      JSON.stringify(odmowa));

    await pg.goto(ekran + '&sort=wejscia');
    const wg = await adresy();
    t.check('„najczęstsze": od największej liczby wejść', wg[0] === '/t404-stara-oferta/', JSON.stringify(wg.slice(0, 2)));
    await pg.goto(ekran);

    const wiersz = pg.locator('tr[data-evk-404-wiersz]').filter({ hasText: '/t404-stara-oferta/' });
    await wiersz.locator('[data-evk-404-przekieruj]').click();
    const pole = wiersz.locator('[data-evk-404-cel] input');
    const poleWidac = await pole.isVisible();
    t.check('„Przekieruj" otwiera pole celu', poleWidac, poleWidac ? 'widać' : 'pola nie widać');
    await wiersz.locator('[data-evk-404-zapisz]').click();
    t.check('pusty cel: komunikat, bez zapisu', (await wiersz.locator('[data-evk-404-blad]').innerText()).includes('Podaj adres docelowy'),
      await wiersz.locator('[data-evk-404-blad]').innerText());
    await pole.fill('nowa-oferta');
    await pole.press('Enter');
    await wiersz.locator('[data-evk-404-przekierowano]').waitFor({ timeout: 15000 });
    t.check('wiersz mówi, dokąd przekierowano', (await wiersz.locator('[data-evk-404-przekierowano]').innerText()) === 'Przekierowano → /nowa-oferta',
      await wiersz.locator('[data-evk-404-przekierowano]').innerText());

    const wUtf = pg.locator('tr[data-evk-404-wiersz]').filter({ hasText: '/t404-usługi-panel/' });
    await wUtf.locator('[data-evk-404-przekieruj]').click();
    await wUtf.locator('[data-evk-404-cel] input').fill('/cel-utf');
    await wUtf.locator('[data-evk-404-cel] input').press('Enter');
    await wUtf.locator('[data-evk-404-przekierowano]').waitFor({ timeout: 15000 });

    const st = sonda('stan');
    t.check('reguły 301 zapisane (polskie znaki zdekodowane, jak w żądaniu), wiersze zniknęły z logu',
      jak(st.reguly, [['/t404-stara-oferta', '/nowa-oferta'], ['/t404-usługi-panel', '/cel-utf']])
        && jak(st.wiersze, ['/', '/?p=987654', '/t404-kontakt-old/', dlugi]), JSON.stringify(st));
    await pg.goto(ekran);
    t.check('po odświeżeniu na liście cztery adresy', (await pg.locator('tr[data-evk-404-wiersz]').count()) === 4,
      String(await pg.locator('tr[data-evk-404-wiersz]').count()));

    await pg.setViewportSize({ width: 360, height: 800 });
    await pg.goto(ekran);
    const tel = await pg.evaluate(() => ({ strona: document.documentElement.scrollWidth, okno: document.documentElement.clientWidth }));
    t.check('telefon (360 px): strona nie wystaje w poziomie, tabela przewija się w swoim pudełku', tel.strona <= tel.okno, JSON.stringify(tel));
    t.check('bez błędów JavaScriptu', bledy.length === 0, bledy.join(' | ').slice(0, 200));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
