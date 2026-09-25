/**
 * Logi 404 na PRAWDZIWYM WordPressie (1.234.0): jeden wiersz na adres,
 * limit na IP, przeniesienie starych wpisów i „Przekieruj" w panelu.
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
  t.section('panel: lista adresów i „Przekieruj"');
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
    await pg.goto(ekran);

    const wiersze = await pg.$$eval('tr[data-evk-404-wiersz]', (tr) => tr.map((w) => [w.cells[0].innerText.trim(), w.cells[1].innerText.trim()]));
    t.check('lista: adres i liczba wejść, najczęstszy pierwszy',
      jak(wiersze, [['/t404-stara-oferta/', '3'], ['/t404-kontakt-old/', '1']]), JSON.stringify(wiersze));
    const ostrzezenie = await pg.locator('[data-evk-404-301-wylaczone]').isVisible();
    t.check('ostrzeżenie: przekierowania wyłączone', ostrzezenie, ostrzezenie ? 'widać' : 'ostrzeżenia nie ma');

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

    const st = sonda('stan');
    t.check('reguła 301 zapisana, wiersz zniknął z logu',
      jak(st.reguly, [['/t404-stara-oferta', '/nowa-oferta']]) && jak(st.wiersze, ['/t404-kontakt-old/']), JSON.stringify(st));
    await pg.goto(ekran);
    t.check('po odświeżeniu na liście jeden adres', (await pg.locator('tr[data-evk-404-wiersz]').count()) === 1,
      String(await pg.locator('tr[data-evk-404-wiersz]').count()));
    t.check('bez błędów JavaScriptu', bledy.length === 0, bledy.join(' | ').slice(0, 200));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
