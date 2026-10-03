/**
 * Statystyki: hotspoty (1.286.0) — prawdziwy WordPress przez php -S, prawdziwe
 * skrypty w Chromium. Nagranie włącza administrator z paska admina, o tym,
 * czy strona nagrywa, mówi plik listy (nie HTML), kliknięcia trafiają do bazy
 * z miejscem w elemencie, złość i martwe kliknięcia mają znaczniki, limit
 * wizyt kończy nagranie, a podgląd rysuje nakładkę w ramce.
 */

const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('statystyki.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';
const UA_IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
const CZLOWIEK = () => { Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }); };
const czekaj = (ms) => new Promise((r) => setTimeout(r, ms));

module.exports = async function (t) {
  t.section('środowisko');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  let serwer = null;
  let browser = null;
  try {
    const [, B] = sonda('przygotuj').strony || [];
    const H = sonda('strona-hot').id;
    const strona = '/?page_id=' + H;
    serwer = await serwerWp.start(wp.wp);
    const baza = serwer.baza;
    browser = await chromium.launch({ executablePath: chromiumPath() });
    /* Odwiedzający: zapis żądań skryptu hotspotów. */
    const gosc = async (opcje = {}) => {
      const ctx = await browser.newContext(Object.assign({ userAgent: UA, viewport: { width: 1280, height: 800 } }, opcje));
      await ctx.addInitScript(CZLOWIEK);
      const p = await ctx.newPage();
      p.hot = [];
      p.on('request', (r) => { if (/hotspoty(\.min)?\.js(\?|$)/.test(r.url())) p.hot.push(r.url()); });
      return p;
    };
    const daneHot = () => sonda('hot-dane', strona);
    const poczekajNa = async (warunek) => { let d; for (let i = 0; i < 40; i++) { d = daneHot(); if (warunek(d)) return d; await czekaj(150); } return d; };

    t.section('bez nagrania: skrypt hotspotów się nie ładuje');
    const p0 = await gosc();
    await p0.goto(baza + strona);
    await p0.waitForTimeout(800);
    const st0 = sonda('hot-stan', strona);
    t.check('plik listy istnieje od włączenia statystyk (pusta lista)', !!st0.plik && !!st0.plik.strony && !Object.keys(st0.plik.strony).length, J(st0.plik));
    t.check('strona nienagrywana: hotspoty.js nie pobrany, brak danych', p0.hot.length === 0 && daneHot().razem === 0, J(p0.hot));
    await p0.context().close();

    t.section('włączenie z paska admina na stronie');
    const adm = await (await browser.newContext({ viewport: { width: 1280, height: 800 } })).newPage();
    await serwerWp.zaloguj(adm, baza);
    await adm.goto(baza + strona);
    const startTekst = await adm.textContent('#wp-admin-bar-evk-hotspoty-start a').catch(() => null);
    t.check('pasek: „Nagrywaj tę stronę (14 dni albo 1000 wizyt)”', startTekst === 'Nagrywaj tę stronę (14 dni albo 1000 wizyt)', startTekst);
    await Promise.all([adm.waitForNavigation(), adm.evaluate(() => { location.href = document.querySelector('#wp-admin-bar-evk-hotspoty-start a').href; })]);
    const st1 = sonda('hot-stan', strona);
    const tytul = await adm.textContent('#wp-admin-bar-evk-hotspoty > .ab-item').catch(() => null);
    t.check('po kliknięciu: z powrotem na stronie, „Hotspoty: nagrywanie”, nagranie 14 dni / 1000 wizyt',
      adm.url().includes('page_id=' + H) && tytul === 'Hotspoty: nagrywanie' && st1.stan && st1.stan.nagrywa === true && st1.stan.limit === 1000
      && Math.round((st1.stan.do - st1.stan.od) / 86400) === 14, J({ url: adm.url(), tytul, stan: st1.stan }));
    t.check('plik listy: strona z końcem nagrania', st1.plik && st1.plik.strony[strona] === st1.stan.do, J(st1.plik));

    t.section('odwiedzający na komputerze: kliknięcia, złość, martwe, przewinięcie');
    const p1 = await gosc();
    await p1.goto(baza + strona);
    for (let i = 0; i < 30 && !p1.hot.length; i++) await p1.waitForTimeout(100);
    await p1.waitForFunction(() => window.evkHotStart === 1, null, { timeout: 5000 }).catch(() => {});
    t.check('strona nagrywana: hotspoty.js pobrany po pliku listy', p1.hot.length === 1, J(p1.hot));
    const btn = await p1.locator('#h-btn').boundingBox();
    await p1.mouse.click(btn.x + btn.width / 2, btn.y + btn.height / 2);
    await p1.waitForTimeout(1200);
    /* Złość: trzy szybkie kliknięcia w to samo miejsce przycisku (ćwiartka od lewej, góra). */
    for (let i = 0; i < 3; i++) { await p1.mouse.click(btn.x + btn.width / 4, btn.y + btn.height / 4); await p1.waitForTimeout(120); }
    const tekst = await p1.locator('#h-tekst').boundingBox();
    await p1.mouse.click(tekst.x + 30, tekst.y + tekst.height / 2);
    await p1.waitForTimeout(1300);
    const dyn = await p1.locator('#h-dyn').boundingBox();
    await p1.mouse.click(dyn.x + 20, dyn.y + dyn.height / 2);
    await p1.waitForTimeout(1300);
    const docH = await p1.evaluate(() => { window.scrollTo(0, document.documentElement.scrollHeight * 0.6 - window.innerHeight); return document.documentElement.scrollHeight; });
    await p1.waitForTimeout(300);
    await p1.goto('about:blank');
    const d1 = await poczekajNa((d) => (d.kliki || []).length >= 6 && (d.odslony || []).length === 1);
    console.log('      odsłony: ' + J(d1.odslony) + ' (dokument ' + docH + ' px)');
    console.log('      kliki: ' + J((d1.kliki || []).map((k) => [k.selektor, k.etykieta, k.rx, k.ry, k.zlosc, k.martwe])));
    const o1 = (d1.odslony || [])[0] || {};
    t.check('odsłona: komputer, szerokość 1280, przewinięcie ok. 60%, wysokość dokumentu', o1.urzadzenie === 'komputer' && +o1.szer === 1280
      && +o1.przewiniecie >= 57 && +o1.przewiniecie <= 62 && Math.abs(+o1.wys - docH) < 5, J(o1));
    const k1 = d1.kliki || [];
    const naBtn = k1.filter((k) => k.selektor === '#h-btn');
    t.check('przycisk: 4 kliknięcia z selektorem #h-btn i etykietą „Zamów”; pierwsze w środku (500/500), seria w ćwiartce (250/250)',
      naBtn.length === 4 && naBtn.every((k) => k.etykieta === 'Zamów') && Math.abs(naBtn[0].rx - 500) <= 10 && Math.abs(naBtn[0].ry - 500) <= 20
      && naBtn.slice(1).every((k) => Math.abs(k.rx - 250) <= 10 && Math.abs(k.ry - 250) <= 20), J(naBtn));
    t.check('złość: jeden znacznik na serię trzech (trzecie kliknięcie), pierwsze kliknięcie bez', J(naBtn.map((k) => +k.zlosc)) === J([0, 0, 0, 1]), J(naBtn.map((k) => k.zlosc)));
    const kt = k1.find((k) => k.selektor === '#h-tekst') || {}, kd = k1.find((k) => k.selektor === '#h-dyn') || {};
    t.check('martwe: akapit bez reakcji — tak; element z reakcją JS (zmiana w dokumencie) — nie; przycisk — nie',
      +kt.martwe === 1 && +kd.martwe === 0 && naBtn.every((k) => +k.martwe === 0), J({ kt, kd }));

    t.section('telefon, strona bez nagrania, limit wizyt');
    const p2 = await gosc({ userAgent: UA_IPHONE, viewport: { width: 390, height: 800 }, isMobile: true, hasTouch: true });
    await p2.goto(baza + strona);
    const t2 = Date.now();
    const gotowy2 = await p2.waitForFunction(() => window.evkHotStart === 1, null, { timeout: 10000 }).then(() => true, () => false);
    console.log('      telefon: skrypt hotspotów ' + (gotowy2 ? 'gotowy po ' + (Date.now() - t2) + ' ms' : 'NIE wystartował') + ', ' + J(p2.hot));
    await p2.tap('#h-btn');
    /* Kliknięcie po stuknięciu przychodzi po touchend — zapas, zanim strona zniknie. */
    await p2.waitForTimeout(1000);
    await p2.goto('about:blank');
    const d2 = await poczekajNa((d) => (d.odslony || []).length === 2);
    const o2 = (d2.odslony || [])[1] || {};
    t.check('telefon: osobna odsłona „telefon” 390 px i jej kliknięcie', o2.urzadzenie === 'telefon' && +o2.szer === 390
      && (d2.kliki || []).some((k) => k.urzadzenie === 'telefon' && k.selektor === '#h-btn'), J(o2));
    const p3 = await gosc();
    await p3.goto(baza + '/?page_id=' + B);
    await p3.waitForTimeout(800);
    t.check('inna strona (nienagrywana): hotspoty.js nie pobrany', p3.hot.length === 0, J(p3.hot));
    await p3.context().close();
    const beacon = async (tresc, ua) => {
      const r = await fetch(baza + '/?rest_route=/evoke/v1/stat', { method: 'POST', body: J(tresc), headers: { 'Content-Type': 'text/plain;charset=UTF-8', 'User-Agent': ua || UA } });
      await r.text();
      return r.headers.get('x-evk-stat');
    };
    t.check('beacon hotspotów dla strony bez nagrania: odrzucony („nie-nagrywane”)',
      await beacon({ t: 'h', k: 'aaaaaaaaaaaaaaa1', s: '/', q: '?page_id=' + B, w: 1280, h: 2000, d: 50, c: [] }) === 'nie-nagrywane');
    sonda('hot-limit', strona, 2);
    const wLimit = await beacon({ t: 'h', k: 'aaaaaaaaaaaaaaa2', s: '/', q: '?page_id=' + H, w: 1280, h: 2000, d: 50, c: [{ s: '#h-btn', l: 'x', x: 1, y: 1, px: 1, py: 1 }] }, UA + ' Trzeci');
    const st3 = sonda('hot-stan', strona);
    t.check('limit 2 wizyt osiągnięty: trzecia wizyta odrzucona („limit”), nagranie zakończone, strona znika z pliku listy',
      wLimit === 'limit' && st3.stan.nagrywa === false && st3.stan.wizyty === 2 && !(strona in st3.plik.strony) && daneHot().odslony.length === 2, J({ wLimit, st3 }));

    t.section('podgląd w ramce: nakładka, lista, przewinięcie, urządzenia');
    await adm.goto(baza + '/wp-admin/admin.php?page=evoke-statystyki&hotspoty=' + encodeURIComponent(strona) + '&urz=komputer');
    const ramka = adm.frameLocator('.evk-hot-ramka');
    await adm.waitForFunction(() => { const d = document.querySelector('.evk-hot-ramka').contentDocument; return d && d.getElementById('evk-hot-nakladka'); }, null, { timeout: 15000 }).catch(() => {});
    const pod = await adm.evaluate(() => {
      const ram = document.querySelector('.evk-hot-ramka'), d = ram.contentDocument, w = d.getElementById('evk-hot-nakladka');
      if (!w) return null;
      const cv = w.querySelector('canvas'), cx = cv.getContext('2d'), b = d.getElementById('h-btn').getBoundingClientRect(), sy = ram.contentWindow.scrollY;
      const alfa = (x, y) => cx.getImageData(Math.round(x), Math.round(y), 1, 1).data[3];
      return {
        punkty: w.getAttribute('data-punkty'), pasek: !!d.getElementById('wpadminbar'),
        naPrzycisku: alfa(b.left + b.width / 2, b.top + sy + b.height / 2), wCwiartce: alfa(b.left + b.width / 4, b.top + sy + b.height / 4),
        daleko: alfa(10, cv.height - 10),
        znaczniki: [...w.querySelectorAll('.evk-hot-znacznik')].map((z) => z.getAttribute('data-rodzaj')).sort(),
        lista: [...document.querySelectorAll('.evk-hot-elementy tbody tr')].map((tr) => [...tr.children].map((x) => x.textContent)),
        szer: ram.getBoundingClientRect().width,
      };
    });
    console.log('      podgląd: ' + J(pod));
    t.check('ramka bez paska admina; nakładka z 6 kliknięciami komputera', !!pod && pod.pasek === false && pod.punkty === '6', J(pod));
    t.check('mapa ciepła: kolor na przycisku i w miejscu serii, pusto daleko od kliknięć', !!pod && pod.naPrzycisku > 0 && pod.wCwiartce > 0 && pod.daleko === 0, J(pod));
    t.check('znaczniki: jedna „złość”, jedno „martwe”', !!pod && J(pod.znaczniki) === J(['martwe', 'zlosc']), J(pod && pod.znaczniki));
    t.check('lista: „Zamów” 4 (67%), złość 1, martwe 0 na pierwszym miejscu', !!pod && J(pod.lista[0]) === J(['Zamów', '4 (67%)', '1', '0']), J(pod && pod.lista));
    await adm.click('[data-widok="przewiniecie"]');
    const linie = await ramka.locator('.evk-hot-linia').allTextContents();
    t.check('przewinięcie: linie zasięgu z „Połowa odwiedzających dociera tutaj”', linie.some((x) => /^Połowa odwiedzających dociera tutaj/.test(x)), J(linie));
    await Promise.all([adm.waitForNavigation(), adm.click('.evk-hot-grupa a:has-text("Telefon")')]);
    await adm.waitForFunction(() => { const d = document.querySelector('.evk-hot-ramka').contentDocument; return d && d.getElementById('evk-hot-nakladka'); }, null, { timeout: 15000 }).catch(() => {});
    const tel = await adm.evaluate(() => ({ szer: document.querySelector('.evk-hot-ramka').getBoundingClientRect().width,
      punkty: document.querySelector('.evk-hot-ramka').contentDocument.getElementById('evk-hot-nakladka')?.getAttribute('data-punkty'),
      biezace: document.querySelector('.evk-hot-grupa [aria-current]').textContent }));
    t.check('„Telefon (1)”: ramka 390 px, nakładka z kliknięciem z telefonu', Math.round(tel.szer) === 390 && tel.punkty === '1' && tel.biezace === 'Telefon (1)', J(tel));

    t.section('zakładka: lista nagrań, uprawnienia, polityka, kasowanie');
    await adm.goto(baza + '/wp-admin/options-general.php?page=evoke-one&tab=statystyki');
    const wiersz = await adm.evaluate((s) => { const tr = document.querySelector('#evk-stat-hot tr[data-strona="' + s + '"]'); return tr && [...tr.children].map((x) => x.textContent.trim().replace(/\s+/g, ' ')); }, strona);
    t.check('lista: strona, „Zakończone …”, wizyty 2 / 2, „Pokaż” i „Usuń dane” (bez „Zatrzymaj”)', !!wiersz && wiersz[0] === strona && /^Zakończone \d/.test(wiersz[1])
      && wiersz[2] === '2 / 2' && wiersz[3] === 'Pokaż Usuń dane', J(wiersz));
    const pol = () => (sonda('polityka').akapit || '');
    t.check('polityka prywatności: bez zdania o hotspotach, gdy nic nie nagrywa', !/miejsca kliknięć/.test(pol()), pol());
    /* Nowe nagranie formularzem zakładki (od zera) — zdanie w polityce wraca, dane strony znikają. */
    await adm.fill('#evk-hot-strona', baza + strona);
    await adm.check('#evk-stat-hot input[name="od_nowa"]');
    await Promise.all([adm.waitForNavigation(), adm.click('#evk-stat-hot button[type="submit"]')]);
    const st4 = sonda('hot-stan', strona);
    t.check('formularz: pełny adres → ta sama strona, nagrywa od zera (dane usunięte)', st4.stan && st4.stan.nagrywa === true && st4.stan.odslony === 0 && daneHot().razem === 0, J(st4.stan));
    t.check('polityka prywatności: zdanie o hotspotach „wyłącznie na własne potrzeby; … nikomu nie przekazujemy”, gdy nagranie trwa',
      /miejsca kliknięć i głębokość przewinięcia, bez treści pól i bez ruchu myszy — wyłącznie na własne potrzeby; tych danych nikomu nie przekazujemy\./.test(pol()), pol());
    sonda('czytelnicy');
    const cz = await (await browser.newContext()).newPage();
    await serwerWp.zaloguj(cz, baza, 'statyk_csv', 'test-haslo');
    await cz.goto(baza + '/wp-admin/admin.php?page=evoke-statystyki&hotspoty=' + encodeURIComponent(strona));
    const czH1 = await cz.textContent('.evk-hot h1').catch(() => null);
    const czStop = sonda('hot-akcja', 'statyk_csv', 'stop', strona);
    t.check('rola z uprawnieniem „Statystyki” (z własnym ważnym nonce): podgląd tak, zatrzymanie nie (403, nadal nagrywa)',
      /^Hotspoty: /.test(czH1 || '') && /^ODMOWA 403 Brak uprawnień\./.test(czStop.odp || '') && czStop.stan.nagrywa === true, J({ czH1, czStop }));
    await cz.context().close();
    /* „Usuń wszystkie statystyki” kasuje też hotspoty. */
    await beacon({ t: 'h', k: 'aaaaaaaaaaaaaaa3', s: '/', q: '?page_id=' + H, w: 1280, h: 2000, d: 50, c: [{ s: '#h-btn', l: 'x', x: 1, y: 1, px: 1, py: 1 }] });
    const przedUsun = daneHot().razem;
    sonda('usun', J({ wszystko: 1 }));
    t.check('„Usuń wszystkie statystyki” kasuje też odsłony i kliknięcia hotspotów', przedUsun === 2 && daneHot().razem === 0, J({ przedUsun, po: daneHot().razem }));
    adm.on('dialog', (d) => d.accept());
    await Promise.all([adm.waitForNavigation(), adm.click('#evk-stat-hot tr[data-strona="' + strona + '"] .evk-hot-usun')]);
    t.check('„Usuń dane” (po potwierdzeniu): strona znika z listy i z pliku', !(strona in sonda('hot-stan', strona).nagrania)
      && !(await adm.$('#evk-stat-hot tr[data-strona="' + strona + '"]')), J(sonda('hot-stan', strona).nagrania));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('czytelnicy', 'usun');
    sonda('sprzataj');
  }
};
