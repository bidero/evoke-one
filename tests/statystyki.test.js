/**
 * Statystyki bez cookies (1.283.0, etap 1) — prawdziwy WordPress przez php -S,
 * beacony z prawdziwego skryptu w Chromium, raport i zakładka panelu.
 *
 * Decyzje zgłaszającego (02.10): ogólny włącznik, domyślnie wyłączony;
 * unikalni z soli zmienianej o północy, bez cookies; boty odfiltrowane;
 * DNT/GPC domyślnie szanowane; redaktorzy i administratorzy nie liczeni;
 * surowe 90 dni (do zmiany), dzienne na zawsze; menu raportów osobną pozycją.
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
/* Playwright ustawia navigator.webdriver — skrypt (słusznie) uznaje to za automat. Odwiedzający „z ludzi” to podmieniają. */
const CZLOWIEK = () => { Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }); };

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress przez php -S');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  let serwer = null;
  let browser = null;
  try {
    serwer = await serwerWp.start(wp.wp);
    const baza = serwer.baza;
    const beacon = async (tresc, naglowki = {}) => {
      const r = await fetch(baza + '/?rest_route=/evoke/v1/stat', { method: 'POST', body: typeof tresc === 'string' ? tresc : J(tresc),
        headers: Object.assign({ 'Content-Type': 'text/plain;charset=UTF-8', 'User-Agent': UA }, naglowki) });
      await r.text();
      return { s: r.status, w: r.headers.get('x-evk-stat') };
    };
    const odslony = () => sonda('odslony').wiersze || [];

    t.section('moduł wyłączony: nic na stronie, brak trasy');
    sonda('wylacz');
    const html0 = await (await fetch(baza + '/')).text();
    t.check('strona bez skryptu statystyk', !/statystyki(\.min)?\.js|evkStat/.test(html0));
    const b0 = await beacon({ t: 'v', k: '0123456789abcdef', s: '/' });
    t.check('trasa evoke/v1/stat nie istnieje (404)', b0.s === 404, J(b0));

    t.section('włączenie: tabele, cron, skrypt na stronie');
    const p = sonda('przygotuj');
    const [A, B] = p.strony || [];
    t.check('włączenie tworzy tabele od razu (zanim przyjdzie pierwszy beacon)', p.tabele_po_wlaczeniu === true, J(p));
    /* Zapis listy crona bywa przegrany z równoległym żądaniem serwera testowego („could_not_set”);
       każde żądanie planuje zadanie od nowa, więc odczyt do trzech razy. */
    let st0 = sonda('stan');
    for (let i = 0; i < 2 && !st0.cron; i++) st0 = sonda('stan');
    t.check('domyślne: cron dobowy zaplanowany', st0.cron === true, J(st0));
    const html1 = await (await fetch(baza + '/?page_id=' + A)).text();
    const konf = (html1.match(/window\.evkStat=(\{[^<]*?\});/) || [])[1];
    console.log('      konfiguracja: ' + konf);
    t.check('strona ze skryptem (defer) i konfiguracją: adres trasy, ID strony, DNT szanowane',
      /\bdefer\b/.test((html1.match(/<script[^>]*src="[^"]*statystyki(\.min)?\.js[^"]*"[^>]*>/) || [''])[0]) && !!konf && JSON.parse(konf).p === A && JSON.parse(konf).d === 1
      && /rest_route=(%2F|\/)evoke(%2F|\/)v1(%2F|\/)stat/.test(JSON.parse(konf).u), (konf || '') + ' ' + ((html1.match(/<script[^>]*src="[^"]*statystyki[^>]*>/) || [])[0] || 'brak <script>'));

    t.section('odsłony z Chromium (prawdziwy skrypt, sendBeacon)');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const komp = await browser.newContext({ userAgent: UA, viewport: { width: 1280, height: 800 } });
    await komp.addInitScript(CZLOWIEK);
    const s1 = await komp.newPage();
    const bledy = [];
    s1.on('pageerror', (e) => bledy.push(e.message));
    const czekajNa = async (n) => { for (let i = 0; i < 40; i++) { if (odslony().length >= n) return true; await s1.waitForTimeout(150); } return false; };
    await s1.goto(baza + '/?page_id=' + A + '&utm_source=Newsletter&utm_medium=email&utm_campaign=jesien', { referer: 'https://www.google.com/search?q=evoke' });
    await czekajNa(1);
    let w = odslony();
    const o1 = w[0] || {};
    console.log('      odsłona 1: ' + J(o1));
    /* Ścieżka (1.285.0): przy zwykłych adresach z `page_id`, bez UTM — do 1.284.0 każda strona była „/”. */
    t.check('jedna odsłona: strona (/?page_id=…, bez UTM), ID, źródło google.com, kampania, komputer, Chrome, Linux',
      w.length === 1 && o1.sciezka === '/?page_id=' + A && +o1.post_id === A && o1.zrodlo === 'google.com' && o1.utm_source === 'newsletter'
      && o1.utm_medium === 'email' && o1.utm_campaign === 'jesien' && o1.urzadzenie === 'komputer' && o1.przegladarka === 'Chrome' && o1.system_op === 'Linux', J(w));
    t.check('wizyta to skrót (16 znaków), nie adres IP', /^[0-9a-f]{16}$/.test(o1.wizyta || '') && !/127\.0\.0\.1/.test(J(o1)));
    /* Koniec odsłony: przewinięcie do połowy, 1,5 s widoczności, schowanie karty. */
    await s1.evaluate(() => window.scrollTo(0, (document.documentElement.scrollHeight - innerHeight) / 2));
    await s1.waitForTimeout(1500);
    await s1.evaluate(() => {
      Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'hidden' });
      document.dispatchEvent(new Event('visibilitychange'));
    });
    let o1k = {};
    for (let i = 0; i < 30; i++) { o1k = odslony()[0] || {}; if (+o1k.czas_s > 0) break; await s1.waitForTimeout(150); }
    console.log('      po schowaniu karty: czas ' + o1k.czas_s + ' s, przewinięcie ' + o1k.przewiniecie + '%');
    t.check('schowanie karty: czas widoczności (1–3 s) i przewinięcie (40–80%)', +o1k.czas_s >= 1 && +o1k.czas_s <= 3 && +o1k.przewiniecie >= 40 && +o1k.przewiniecie <= 80, J(o1k));

    const s2 = await komp.newPage();
    await s2.goto(baza + '/?page_id=' + B, { referer: baza + '/?page_id=' + A });
    await czekajNa(2);
    w = odslony();
    t.check('przejście wewnątrz strony: bez źródła; ta sama wizyta (ten sam skrót)', w.length === 2 && w[1].zrodlo === '' && w[1].wizyta === w[0].wizyta && +w[1].post_id === B, J(w.map((x) => [x.zrodlo, x.wizyta])));

    const tel = await browser.newContext({ userAgent: UA_IPHONE, viewport: { width: 390, height: 800 } });
    await tel.addInitScript(CZLOWIEK);
    await (await tel.newPage()).goto(baza + '/?page_id=' + A);
    await czekajNa(3);
    w = odslony();
    t.check('telefon: urządzenie, Safari, iOS, bez odsyłacza — „(bezpośrednio)”, inna wizyta',
      w.length === 3 && w[2].urzadzenie === 'telefon' && w[2].przegladarka === 'Safari' && w[2].system_op === 'iOS' && w[2].zrodlo === '(bezpośrednio)' && w[2].wizyta !== w[0].wizyta, J(w[2]));

    t.section('kogo nie liczymy');
    const gpc = await browser.newContext({ userAgent: UA, extraHTTPHeaders: { 'Sec-GPC': '1' } });
    await gpc.addInitScript(CZLOWIEK);
    await gpc.addInitScript(() => { Object.defineProperty(Navigator.prototype, 'globalPrivacyControl', { get: () => true }); });
    await (await gpc.newPage()).goto(baza + '/?page_id=' + A);
    const automat = await browser.newContext({ userAgent: UA });
    await (await automat.newPage()).goto(baza + '/?page_id=' + A);
    await s1.waitForTimeout(1200);
    t.check('przeglądarka z GPC i automat (navigator.webdriver) nie wysyłają nic', odslony().length === 3, odslony().length);
    const bGpc = await beacon({ t: 'v', k: 'aaaaaaaaaaaaaaa1', s: '/x' }, { 'Sec-GPC': '1' });
    const bDnt = await beacon({ t: 'v', k: 'aaaaaaaaaaaaaaa2', s: '/x' }, { DNT: '1' });
    const bBot = await beacon({ t: 'v', k: 'aaaaaaaaaaaaaaa3', s: '/x' }, { 'User-Agent': 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' });
    const bKlucz = await beacon({ t: 'v', k: 'nie-szesnastkowy', s: '/x' });
    const bSmiec = await beacon('{to nie json');
    t.check('serwer: GPC, DNT, bot, zły klucz, nie-JSON — 204 i odrzucone', J([bGpc, bDnt, bBot, bKlucz, bSmiec].map((x) => x.s + ' ' + x.w))
      === J(['204 dnt', '204 dnt', '204 bot', '204 klucz', '204 tresc']), J([bGpc, bDnt, bBot, bKlucz, bSmiec]));
    sonda('ustaw', J({ dnt: 0, wyklucz_ip: '127.0.0.0/8' }));
    const bIp = await beacon({ t: 'v', k: 'aaaaaaaaaaaaaaa4', s: '/x' }, { 'Sec-GPC': '1' });
    t.check('DNT wyłączone w panelu, adres z listy wykluczonych — odrzucony po IP', bIp.w === 'ip', J(bIp));
    sonda('ustaw', J({ dnt: 1, wyklucz_ip: '' }));
    sonda('zasiej', UA, 300);
    const bLimit = await beacon({ t: 'v', k: 'aaaaaaaaaaaaaaa5', s: '/x' });
    t.check('limit 300 odsłon jednej wizyty dziennie', bLimit.w === 'limit', J(bLimit));

    t.section('zalogowani');
    const adm = await browser.newContext({ userAgent: UA });
    const sa = await adm.newPage();
    await serwerWp.zaloguj(sa, baza);
    const htmlAdm = await (await sa.goto(baza + '/?page_id=' + A)).text();
    t.check('administrator: strona bez skryptu statystyk', !/evkStat/.test(htmlAdm));
    sonda('ustaw', J({ wyklucz_role: 0 }));
    const htmlAdm2 = await (await sa.goto(baza + '/?page_id=' + A)).text();
    t.check('„Zalogowanych redaktorów i administratorów” odznaczone — skrypt jest', /evkStat/.test(htmlAdm2));
    sonda('ustaw', J({ wyklucz_role: 1 }));
    const upr = sonda('uprawnienia');
    t.check('raporty czyta administrator i rola z uprawnieniem „Statystyki”, autor bez niego — nie',
      upr.admin === true && upr.statyk_z === true && upr.statyk_bez === false, J(upr));

    t.section('zbiórka dzienna i czas trzymania');
    /* Daty w strefie WordPressa (z sondy), nie przeglądarki ani Node'a. */
    const dzis = sonda('stan').dzis;
    const dzien = (n) => { const d = new Date(dzis + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() - n); return d.toISOString().slice(0, 10); };
    const przed = sonda('dane', 'strona', dzien(10), dzien(0)).dane;
    sonda('postarz', 3);
    const przedZb = { razem: sonda('dane', 'razem', dzien(10), dzien(0)).dane, strona: sonda('dane', 'strona', dzien(10), dzien(0)).dane,
      zrodlo: sonda('dane', 'zrodlo', dzien(10), dzien(0)).dane };
    const zb = sonda('zbiorka');
    console.log('      zbiórka: ' + J({ dni: zb.zebrane_dni, do: zb.zebrane_do, surowe: zb.surowe }));
    const poZb = { razem: sonda('dane', 'razem', dzien(10), dzien(0)).dane, strona: sonda('dane', 'strona', dzien(10), dzien(0)).dane,
      zrodlo: sonda('dane', 'zrodlo', dzien(10), dzien(0)).dane };
    console.log('      razem: ' + J(poZb.razem));
    t.check('zbiórka do wczoraj; surowe zostają (90 dni)', zb.zebrane_do === zb.wczoraj && zb.surowe >= 303 && zb.zebrane_dni >= 1, J(zb));
    t.check('raport z tabeli dziennej = raport z surowych (razem, strony, źródła)', J(przedZb) === J(poZb), J({ przedZb, poZb }));
    /* 300 zasianych ma skrót komputera z Chromium (ten sam adres i przeglądarka), więc wizyty są dwie. */
    t.check('razem: 303 odsłony (3 z Chromium + 300 zasianych), 2 wizyty', (poZb.razem[''] || {}).odslony === 303 && (poZb.razem[''] || {}).unikalni === 2, J(poZb.razem));
    t.check('źródła: google.com i (bezpośrednio), bez przejścia wewnętrznego', J(Object.keys(poZb.zrodlo).sort()) === J(['(bezpośrednio)', 'google.com']), J(poZb.zrodlo));
    t.check('dane z dnia przesunięcia, nie z dziś', Object.keys(sonda('dane', 'razem', dzien(10), dzien(0), 'dni').dane).join() === dzien(3), J(sonda('dane', 'razem', dzien(10), dzien(0), 'dni').dane));
    t.check('przed przesunięciem te same strony', J(Object.keys(przed).sort()) === J(Object.keys(poZb.strona).sort()), J({ przed, po: poZb.strona }));
    sonda('postarz', 100);
    const zb2 = sonda('zbiorka');
    t.check('90 dni: surowe starsze niż 90 dni skasowane, dzienne zostają', zb2.surowe === 0 && (zb2.dni || []).length > 0, J({ surowe: zb2.surowe, dni: (zb2.dni || []).length }));

    t.section('kasowanie za okres (1.285.0)');
    sonda('przygotuj');
    /* Dni: −3 (5 odsłon), −1 (3), dziś (2); −3 i −1 zebrane do tabeli dziennej. */
    phpOutput('statystyki.php', 'zasiej x 5'); sonda('postarz', 2); phpOutput('statystyki.php', 'zasiej x 3'); sonda('postarz', 1);
    phpOutput('statystyki.php', 'zasiej x 2'); sonda('zbiorka');
    const razem = () => (sonda('dane', 'razem', dzien(30), dzien(0)).dane[''] || {}).odslony || 0;
    t.check('warunek: 10 odsłon w trzech dniach, dwa zebrane', razem() === 10, razem());
    const u1 = sonda('usun', J({ od: dzien(1), do: dzien(1), licz: 1 }));
    t.check('„licz”: 3 odsłony z wczoraj, nic nie skasowane', ((u1.odp || {}).data || {}).ile === 3 && razem() === 10, J(u1.odp));
    const u2 = sonda('usun', J({ od: dzien(1), do: dzien(1) }));
    t.check('usunięte wczoraj: z tabeli dziennej i surowej; reszta (−3 i dziś) zostaje', ((u2.odp || {}).data || {}).ile === 3 && razem() === 7
      && !u2.dni.includes(dzien(1)) && u2.dni.includes(dzien(3)) && !u2.surowe_dni.includes(dzien(1)) && u2.surowe_dni.includes(dzien(0)), J(u2));
    const zle = [sonda('usun', J({ od: dzien(0), do: dzien(1) })), sonda('usun', J({ od: '2026-02-30', do: dzien(0) })), sonda('usun', J({ wszystko: 1 }), 'autor')]
      .map((x) => (x.odp || {}).data);
    t.check('odmowy: „od” po „do”, zła data, rola z uprawnieniem „Statystyki” (tylko czyta) — nic nie skasowane',
      J(zle) === J(['Data „od” jest późniejsza niż „do”.', 'Podaj obie daty.', 'Brak uprawnień.']) && razem() === 7, J(zle));

    t.section('raport w panelu (Chromium)');
    await komp.close(); await tel.close(); await gpc.close(); await automat.close();
    const [A2, B2] = sonda('przygotuj').strony || [];
    /* Poprzedni okres (1.285.0): 2 odsłony sprzed 7 dni — dla „7 dni” to okres poprzedni. */
    phpOutput('statystyki.php', 'zasiej x 2'); sonda('postarz', 7);
    const k2 = await browser.newContext({ userAgent: UA });
    await k2.addInitScript(CZLOWIEK);
    for (const [id, ref] of [[A2, 'https://www.bing.com/'], [B2, ''], [A2, '']]) {
      const pp = await k2.newPage();
      await pp.goto(baza + '/?page_id=' + id, ref ? { referer: ref } : {});
      await pp.close();
    }
    for (let i = 0; i < 30 && odslony().length < 3; i++) await sa.waitForTimeout(150);
    await sa.goto(baza + '/wp-admin/admin.php?page=evoke-statystyki&okres=7');
    const rap = await sa.evaluate(() => ({
      h1: (document.querySelector('.evk-stat h1') || {}).textContent,
      liczby: [...document.querySelectorAll('.evk-stat-liczba')].map((x) => x.querySelector('span').textContent + '=' + x.querySelector('strong').textContent),
      pasy: document.querySelectorAll('.evk-stat-wykres .evk-w-pas').length,
      slupki: document.querySelectorAll('.evk-stat-wykres .evk-w-slupek').length,
      dni: [...document.querySelectorAll('.evk-stat-dni tbody tr')].map((tr) => [...tr.children].map((x) => x.textContent)),
      zmiany: [...document.querySelectorAll('.evk-stat-liczba small')].map((x) => x.textContent),
      zrodla: [...document.querySelectorAll('.evk-stat-lista')].filter((b) => b.querySelector('h3').textContent === 'Źródła').map((b) => [...b.querySelectorAll('tbody td:first-child')].map((x) => x.textContent))[0],
      okres: document.querySelector('.evk-stat-okresy [aria-current]')?.textContent,
      menu: !!document.querySelector('#adminmenu a[href*="page=evoke-statystyki"]'),
    }));
    console.log('      raport: ' + J(rap));
    const ostatni = (rap.dni || [])[6] || [];
    t.check('raport: 7 dni, jeden słupek (dziś), tabela dzienna: dziś 3 odsłony, 1 unikalny; liczby 3 i 1', rap.pasy === 7 && rap.slupki === 1
      && (rap.dni || []).length === 7 && ostatni[1] === '3' && ostatni[2] === '1' && rap.liczby[0] === 'Odsłony=3' && rap.liczby[1] === 'Unikalni=1', J(rap));
    /* Dzień do dnia: dziś porównuje się z tym samym dniem tydzień wcześniej (ostatni wiersz). */
    t.check('porównanie z poprzednimi 7 dniami: odsłony +50% (3 wobec 2), unikalni 0%; poprzedni okres w tabeli dziennej',
      rap.zmiany[0] === '+50% wobec: poprzednie 7 dni' && rap.zmiany[1] === '0% wobec: poprzednie 7 dni'
      && (rap.dni || []).reduce((s, r) => s + +r[3], 0) === 2 && ostatni[3] === '2', J({ zmiany: rap.zmiany, dni: rap.dni }));
    /* Przełącznik Słupki / Linie: domyślnie słupki, linie z legendą; wybór pamięta przeglądarka. */
    const widok = () => sa.evaluate(() => ({
      tryb: document.querySelector('.evk-stat-wykres-box').getAttribute('data-tryb'),
      slupkiWidac: getComputedStyle(document.querySelector('.evk-w-slupki')).display !== 'none',
      linieWidac: getComputedStyle(document.querySelector('.evk-w-linie')).display !== 'none',
      legenda: getComputedStyle(document.querySelector('.evk-stat-legenda')).display !== 'none',
      nacisniety: document.querySelector('.evk-stat-tryby [aria-pressed="true"]').textContent,
      linie: [...document.querySelectorAll('.evk-w-linie polyline')].map((p) => p.getAttribute('class') + ':' + p.getAttribute('points').split(' ').length) }));
    const w0 = await widok();
    await sa.click('.evk-stat-tryby [data-tryb="linie"]');
    const w1 = await widok();
    await sa.reload();
    const w2 = await widok();
    t.check('domyślnie słupki; „Linie” → trzy linie po 7 punktów (odsłony, unikalni, poprzedni okres) z legendą; po przeładowaniu dalej linie',
      w0.tryb === 'slupki' && w0.slupkiWidac && !w0.linieWidac && !w0.legenda && w1.tryb === 'linie' && !w1.slupkiWidac && w1.linieWidac && w1.legenda
      && w1.nacisniety === 'Linie' && J(w1.linie) === J(['evk-w-poprz:7', 'evk-w-unikalni:7', 'evk-w-odslony:7']) && w2.tryb === 'linie' && w2.nacisniety === 'Linie', J({ w0, w1, w2 }));
    await sa.hover('.evk-w-pas[data-i="6"]');
    const dymek = await sa.evaluate(() => { const d = document.querySelector('.evk-stat-dymek'); return d.hidden ? null : [...d.children].map((x) => x.textContent); });
    t.check('podpowiedź nad dniem: data, odsłony, unikalni, poprzednio', !!dymek && dymek.length === 4 && dymek[1] === 'Odsłony: 3' && dymek[2] === 'Unikalni: 1'
      && /^Poprzednio \(\d{4}-\d{2}-\d{2}\): 2$/.test(dymek[3]), J(dymek));
    await sa.click('.evk-stat-tryby [data-tryb="slupki"]');
    t.check('źródła: bing.com i (bezpośrednio); okres „7 dni” zaznaczony; pozycja w menu', J((rap.zrodla || []).sort()) === J(['(bezpośrednio)', 'bing.com'])
      && rap.okres === '7 dni' && rap.menu, J(rap));

    t.section('„teraz na stronie”, widżet Kokpitu, licznik w pasku admina (1.285.0)');
    const teraz = await sa.textContent('.evk-stat-teraz strong');
    const terazStrony = await sa.textContent('.evk-stat-teraz .evo-muted');
    t.check('raport: teraz na stronie 1 wizyta (ostatnie 5 min), z jej stronami', teraz === '1' && terazStrony.includes('/?page_id=' + A2 + ' 1'), teraz + ' ' + terazStrony);
    await sa.goto(baza + '/wp-admin/index.php');
    const widzet = await sa.evaluate(() => {
      const w = document.querySelector('#evk_stat_widzet');
      return w && { tytul: w.querySelector('h2')?.textContent.trim(), liczby: [...w.querySelectorAll('.evk-sw-liczby strong')].map((x) => x.textContent),
        zmiana: w.querySelector('.evk-sw-liczby small')?.textContent, mini: w.querySelectorAll('.evk-stat-mini rect').length,
        strony: [...w.querySelectorAll('.evk-sw-strony li')].map((li) => li.querySelector('span').textContent + ' ' + li.querySelector('strong').textContent), raport: !!w.querySelector('a[href*="page=evoke-statystyki"]') };
    });
    console.log('      widżet: ' + J(widzet));
    t.check('widżet Kokpitu: 3 odsłony (+50%), 1 unikalny, teraz 1; mini wykres; strony A ×2 i B ×1; odnośnik do raportu',
      !!widzet && widzet.tytul === 'Statystyki — 7 dni' && J(widzet.liczby) === J(['3', '1', '1']) && widzet.zmiana === '+50% wobec poprzednich 7 dni'
      && widzet.mini === 1 && J(widzet.strony) === J(['/?page_id=' + A2 + ' 2', '/?page_id=' + B2 + ' 1']) && widzet.raport, J(widzet));
    const licznik = async () => { await sa.goto(baza + '/?page_id=' + A2); return sa.evaluate(() => {
      const n = document.querySelector('#wp-admin-bar-evk-statystyki'); return n && { tekst: n.querySelector('.ab-label').textContent, tytul: n.querySelector('a').getAttribute('title') }; }); };
    const l0 = await licznik();
    sonda('ustaw', J({ licznik: 1 }));
    const l1 = await licznik();
    sonda('ustaw', J({ licznik: 0 }));
    t.check('licznik w pasku: domyślnie brak; włączony — strona A: dziś 2 / 30 dni 2 (administrator nie liczony)',
      l0 === null && !!l1 && l1.tekst === '2 / 2' && l1.tytul === 'Odsłony tej strony: dziś 2, 30 dni 2', J({ l0, l1 }));
    await sa.goto(baza + '/wp-admin/admin.php?page=evoke-statystyki&okres=7');

    /* Raport to osobna strona (nie przez tests/php/tab.php), więc admin-telefon jej nie widzi. */
    await sa.setViewportSize({ width: 360, height: 740 });
    await sa.reload();
    const tel360 = await sa.evaluate(() => ({ szer: document.documentElement.scrollWidth, okno: innerWidth,
      przyciski: [...document.querySelectorAll('.evk-stat-okresy a')].map((a) => Math.round(a.getBoundingClientRect().height)),
      wystaje: [...document.querySelectorAll('.evk-stat *')].filter((e) => e.getBoundingClientRect().right > innerWidth + 1 && !e.closest('.evo-tbl-wrap')).length }));
    t.check('raport na telefonie (360 px): bez przewijania w poziomie, nic nie wystaje, okresy ≥ 24 px',
      tel360.szer <= tel360.okno && tel360.wystaje === 0 && tel360.przyciski.every((h) => h >= 24), J(tel360));
    await sa.setViewportSize({ width: 1280, height: 900 });

    t.section('zakładka Statystyki w panelu Evoke ONE');
    await sa.goto(baza + '/wp-admin/options-general.php?page=evoke-one&tab=statystyki');
    await sa.selectOption('#evk-stat-retencja', '180');
    await sa.selectOption('#evk-stat-menu', 'index.php');
    await sa.fill('#evk-stat-ip', '10.0.0.1\nzle\n2001:db8::/32');
    await sa.uncheck('#evk-stat-form [name=dnt]');
    await sa.click('#evk-stat-form [type=submit]');
    await sa.waitForSelector('#evk-stat-form .evk-stat-zapisano.is-widoczny', { timeout: 10000 }).catch(() => {});
    const ust = sonda('stan').ust || {};
    t.check('zapis z zakładki: 180 dni, menu w Kokpicie, złe IP odrzucone, DNT wyłączone, moduł dalej włączony',
      ust.retencja === 180 && ust.menu === 'index.php' && ust.wyklucz_ip === '10.0.0.1\n2001:db8::/32' && ust.dnt === 0 && ust.enabled === 1, J(ust));
    t.check('pole IP pokazuje, co zostało zapisane', await sa.inputValue('#evk-stat-ip') === '10.0.0.1\n2001:db8::/32');
    await sa.goto(baza + '/wp-admin/index.php');
    t.check('menu raportów pod Kokpitem', await sa.locator('#menu-dashboard a[href*="index.php?page=evoke-statystyki"]').count() === 1);
    await sa.goto(baza + '/wp-admin/options-general.php?page=evoke-one&tab=statystyki');
    /* Kasowanie z zakładki (1.285.0): potwierdzenie z liczbą; „Anuluj” nic nie rusza. */
    const okna = [];
    let odpowiedz = false;
    const naOkno = async (d) => { okna.push(d.message()); if (odpowiedz) await d.accept(); else await d.dismiss(); };
    sa.on('dialog', naOkno);
    const przedUs = razem();
    await sa.click('#evk-stat-usun [data-evk-usun="okres"]');
    await sa.waitForFunction(() => /Anulowane/.test(document.querySelector('.evk-stat-usun-stan').textContent), null, { timeout: 10000 }).catch(() => {});
    t.check('„Usuń z tego okresu” → okno z okresem i liczbą; „Anuluj” — nic nie skasowane',
      /^Usunąć statystyki z dni \d{4}-\d{2}-\d{2} – \d{4}-\d{2}-\d{2} \(3 odsłon\)\?/.test(okna[0] || '') && razem() === przedUs && przedUs === 5, J({ okna, przedUs }));
    odpowiedz = true;
    await sa.click('#evk-stat-usun [data-evk-usun="wszystko"]');
    await sa.waitForFunction(() => /Usunięto/.test(document.querySelector('.evk-stat-usun-stan').textContent), null, { timeout: 10000 }).catch(() => {});
    t.check('„Usuń wszystkie statystyki” → potwierdzone, „Usunięto 3 odsłon.”, raport pusty',
      /^Usunąć WSZYSTKIE statystyki \(5 odsłon\)/.test(okna[1] || '') && (await sa.textContent('.evk-stat-usun-stan')) === 'Usunięto 5 odsłon.' && razem() === 0, J(okna));
    sa.off('dialog', naOkno);

    t.section('zdarzenia i cele (1.285.0)');
    const Z = sonda('strona-zdarzen').id;
    const UA2 = UA.replace('Chrome/130.0', 'Chrome/131.0');
    const ludz = await browser.newContext({ userAgent: UA, viewport: { width: 1280, height: 800 } });
    await ludz.addInitScript(CZLOWIEK);
    /* Linki nie mają nigdzie prowadzić — nasza obsługa idzie w fazie przechwytywania, więc beacon wychodzi przed tym. */
    await ludz.addInitScript(() => document.addEventListener('click', (e) => { if (e.target.closest && e.target.closest('a')) e.preventDefault(); }));
    await ludz.addInitScript(() => document.addEventListener('submit', (e) => e.preventDefault()));
    const sz = await ludz.newPage();
    await sz.goto(baza + '/?page_id=' + Z, { referer: 'https://www.bing.com/' });
    for (const id of ['z-tel', 'z-mail', 'z-pdf', 'z-out', 'z-wew', 'z-wl', 'z-form']) await sz.click('#' + id);
    const zdarzenia = async (n) => { for (let i = 0; i < 40; i++) { const w = sonda('zdarzenia').wiersze || []; if (w.length >= n) return w; await sz.waitForTimeout(150); } return sonda('zdarzenia').wiersze || []; };
    const z1 = await zdarzenia(6);
    t.check('sześć zdarzeń: telefon (cyfry), e-mail (bez parametrów, małe litery), plik, domena wychodząca, własne, formularz (id); link wewnętrzny nie',
      J(z1.map((r) => r.rodzaj + ':' + r.etykieta)) === J(['tel:+48123456789', 'mail:biuro@example.com', 'pobranie:cennik.pdf', 'wychodzacy:example.org', 'wlasne:zapis', 'formularz:kontakt']), J(z1));
    sonda('ustaw', J({ zd_wychodzace: 0 }));
    await sz.reload();
    await sz.click('#z-out');
    await sz.click('#z-tel');
    await zdarzenia(7);
    await sz.waitForTimeout(500);
    const z2 = sonda('zdarzenia').wiersze || [];
    const bOut = await beacon({ t: 'z', k: 'bbbbbbbbbbbbbbb1', r: 'wychodzacy', e: 'example.org' });
    t.check('„Linki wychodzące” wyłączone: skrypt ich nie wysyła, serwer odrzuca stary skrypt; telefon dalej liczony',
      z2.length === 7 && z2[6].rodzaj === 'tel' && bOut.w === 'wylaczone', J({ z2: z2.slice(6), bOut }));
    sonda('ustaw', J({ zd_wychodzace: 1 }));
    /* Druga osoba: wejście wprost na stronę B — cel „adres”. */
    const inny = await browser.newContext({ userAgent: UA2 });
    await inny.addInitScript(CZLOWIEK);
    const pB = sonda('stan') && (await (async () => { const pg = await inny.newPage(); await pg.goto(baza + '/?page_id=' + A2); return pg; })());
    await pB.close();
    for (let i = 0; i < 30 && odslony().length < 3; i++) await sa.waitForTimeout(150);
    const cele = sonda('cele', J([{ nazwa: 'Kontakt', typ: 'zdarzenie', wartosc: 'formularz' }, { nazwa: 'Cennik', typ: 'zdarzenie', wartosc: 'pobranie:cennik.pdf' },
      { nazwa: 'Strona A', typ: 'adres', wartosc: '/?page_id=' + A2 + '&utm_source=x' }, { nazwa: 'Zły', typ: 'cokolwiek', wartosc: 'x' }, { nazwa: '', typ: 'adres', wartosc: '/' }]));
    t.check('zapis celów: trzy poprawne (adres bez UTM), zły rodzaj i pusta nazwa odrzucone', (cele.cele || []).length === 3
      && cele.cele[2].wartosc === '/?page_id=' + A2 && cele.cele.every((c) => /^[a-z0-9]{8}$/.test(c.id)), J(cele));
    await sa.goto(baza + '/wp-admin/index.php?page=evoke-statystyki&okres=7');
    const rc = await sa.evaluate(() => ({
      zd: [...document.querySelectorAll('.evk-stat-lista')].filter((b) => b.querySelector('h3').textContent === 'Zdarzenia').map((b) => [...b.querySelectorAll('tbody td:first-child')].map((x) => x.textContent))[0],
      cele: [...document.querySelectorAll('.evk-stat-cel-raport')].map((b) => ({ nazwa: b.querySelector('h3').textContent, konw: b.querySelector('.evk-stat-konwersja').textContent,
        zrodla: [...b.querySelectorAll('tbody tr')].map((tr) => [...tr.children].map((x) => x.textContent).join('|')) })) }));
    console.log('      cele: ' + J(rc.cele));
    t.check('raport: lista zdarzeń z nazwami („Telefon: +48123456789”, „Pobranie: cennik.pdf”…)', (rc.zd || []).includes('Telefon: +48123456789')
      && rc.zd.includes('Pobranie: cennik.pdf') && rc.zd.includes('Własne: zapis') && rc.zd.includes('Formularz: kontakt'), J(rc.zd));
    const pr = (x) => (x || '').replace(',', '.');
    const [ck, cc, ca] = rc.cele;
    t.check('cele: 2 wizyty; Kontakt i Cennik 50% (wejście z bing.com 1/1, wprost 0/1); Strona A 50% (wprost 1/1)',
      rc.cele.length === 3 && pr(ck.konw) === '50.0%' && pr(cc.konw) === '50.0%' && pr(ca.konw) === '50.0%'
      && J(ck.zrodla.map(pr)) === J(['bing.com|1|1|100.0%', '(bezpośrednio)|1|0|0.0%']) && J(ca.zrodla.map(pr)) === J(['(bezpośrednio)|1|1|100.0%', 'bing.com|1|0|0.0%']), J(rc.cele));
    const zdPrzed = sonda('dane', 'zdarzenie', dzien(10), dzien(0)).dane;
    sonda('postarz', 1); sonda('zbiorka', 'od-nowa');
    const zdPo = sonda('dane', 'zdarzenie', dzien(10), dzien(0)).dane;
    t.check('zdarzenia po zbiórce dziennej: te same liczby z tabeli dziennej', Object.keys(zdPrzed).length === 6 && zdPrzed['tel:+48123456789'].odslony === 2 && J(zdPrzed) === J(zdPo), J({ zdPrzed, zdPo }));
    await ludz.close(); await inny.close();
    await sa.goto(baza + '/wp-admin/options-general.php?page=evoke-one&tab=statystyki');
    await sa.click('label.evo-toggle:has([data-option="evk_statystyki"]) .evo-slider');
    for (let i = 0; i < 30 && (sonda('stan').ust || {}).enabled !== 0; i++) await sa.waitForTimeout(150);
    const st2 = sonda('stan');
    t.check('przełącznik wyłącza moduł; ustawienia zostają', st2.ust.enabled === 0 && st2.ust.retencja === 180, J(st2.ust));
    await sa.goto(baza + '/wp-admin/index.php');
    t.check('wyłączony: bez menu raportów i bez crona', await sa.locator('a[href*="page=evoke-statystyki"]').count() === 0 && sonda('stan').cron === false);
    t.check('bez błędów JS na stronie', bledy.length === 0, J(bledy));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
