/**
 * Statystyki: wejścia na nieistniejące strony (1.286.0). Prawdziwe 404
 * WordPressa w Chromium — liczone osobno (`blad = 404`), poza odsłonami,
 * źródłami i „teraz na stronie”; w raporcie lista adresów z rozwijanym „skąd”
 * (obca strona, zepsuty link we własnej treści, bezpośrednio) i w CSV.
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
const CZLOWIEK = () => { Object.defineProperty(Navigator.prototype, 'webdriver', { get: () => false }); };

module.exports = async function (t) {
  t.section('środowisko');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  let serwer = null;
  let browser = null;
  try {
    const [A] = sonda('przygotuj').strony || [];
    serwer = await serwerWp.start(wp.wp);
    const baza = serwer.baza;
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const gosc = async (ua) => { const c = await browser.newContext({ userAgent: ua }); await c.addInitScript(CZLOWIEK); return c.newPage(); };
    const odslony = () => sonda('odslony').wiersze || [];
    const czekaj = async (n) => { for (let i = 0; i < 40 && odslony().length < n; i++) await new Promise((r) => setTimeout(r, 150)); };

    t.section('wejścia z Chromium: strona, zepsuty link we własnej treści, obca strona, bezpośrednio');
    /* Odwiedzający 1: strona A. Odwiedzający 2: z A na nieistniejącą, potem dwa wejścia na drugą nieistniejącą. */
    const p1 = await gosc(UA);
    await p1.goto(baza + '/?page_id=' + A);
    const p2 = await gosc(UA + ' Drugi');
    await p2.goto(baza + '/?page_id=' + A);
    await czekaj(2);
    const status = [];
    /* Zepsuty link w treści strony A — kliknięcie, więc odsyłaczem jest adres A. */
    await p2.evaluate(() => { const a = document.createElement('a'); a.id = 'zly-link'; a.href = '/?page_id=999998'; a.textContent = 'stary link'; document.body.prepend(a); });
    const [odp] = await Promise.all([p2.waitForNavigation(), p2.click('#zly-link')]);
    status.push(odp.status());
    status.push((await p2.goto(baza + '/?page_id=999999', { referer: 'https://www.example.org/artykul?token=tajny#x' })).status());
    status.push((await p2.goto(baza + '/?page_id=999999')).status());
    await czekaj(5);
    const w = odslony();
    console.log('      odsłony: ' + J(w.map((x) => [x.sciezka, x.blad, x.zrodlo, x.odsylacz])));
    t.check('warunek: prawdziwe 404 WordPressa (kod 404)', status[1] === 404 && status[2] === 404, J(status));
    t.check('zapis: 3 wejścia z błędem 404, 2 zwykłe odsłony', w.filter((x) => +x.blad === 404).length === 3 && w.filter((x) => +x.blad === 0).length === 2, J(w.map((x) => x.blad)));
    const dzis = sonda('stan').dzis;
    const dane = (wym) => sonda('dane', wym, dzis, dzis).dane || {};
    const przed = { razem: dane('razem'), zrodlo: dane('zrodlo'), d404: dane('404'), skad: dane('404_skad') };
    console.log('      dane: ' + J(przed));
    t.check('odsłony: tylko 2 zwykłe (404 poza odsłonami), źródła bez example.org', (przed.razem[''] || {}).odslony === 2 && !przed.zrodlo['example.org'], J(przed));
    t.check('lista 404: ?page_id=999999 ×2 (1 wizyta), ?page_id=999998 ×1',
      J(Object.keys(przed.d404)) === J(['/?page_id=999999', '/?page_id=999998']) && przed.d404['/?page_id=999999'].odslony === 2 && przed.d404['/?page_id=999999'].unikalni === 1, J(przed.d404));
    t.check('skąd: obca strona bez „?…” i „#…”, bezpośrednio, zepsuty link z tej strony (adres strony A)',
      J(Object.keys(przed.skad).sort()) === J(['/?page_id=999998\tta strona: /?page_id=' + A, '/?page_id=999999\t(bezpośrednio)', '/?page_id=999999\texample.org/artykul'].sort()), J(Object.keys(przed.skad)));

    t.section('zbiórka dzienna, „teraz na stronie”, raport, CSV');
    sonda('postarz', 1);
    sonda('zbiorka', 'od-nowa');
    const wcz = sonda('stan').dzis;
    const d1 = (wym) => { const d = new Date(wcz + 'T12:00:00Z'); d.setUTCDate(d.getUTCDate() - 1); const x = d.toISOString().slice(0, 10); return sonda('dane', wym, x, x).dane || {}; };
    const po = { razem: d1('razem'), zrodlo: d1('zrodlo'), d404: d1('404'), skad: d1('404_skad') };
    t.check('po zbiórce do tabeli dziennej — te same liczby (404 osobno)', J(po) === J(przed), J({ przed, po }));
    /* Świeże wejścia dla „teraz”: odwiedzający 2 tylko na 404 — nie liczy się jako obecny. */
    await p1.goto(baza + '/?page_id=' + A);
    await p2.goto(baza + '/?page_id=999997');
    await czekaj(7);
    const adm = await (await browser.newContext()).newPage();
    await serwerWp.zaloguj(adm, baza);
    await adm.goto(baza + '/wp-admin/admin.php?page=evoke-statystyki&okres=7');
    const teraz = await adm.textContent('.evk-stat-teraz strong');
    t.check('„teraz na stronie”: 1 (wejście na 404 nie jest obecnością na stronie)', teraz === '1', teraz);
    const box = () => adm.evaluate(() => {
      const b = [...document.querySelectorAll('.evk-stat-lista')].find((x) => x.querySelector('h3').textContent === 'Nieistniejące strony (404)');
      return b && { wiersze: [...b.querySelectorAll('tbody:not(.evk-stat-pod) > tr')].map((tr) => [...tr.children].map((x) => x.textContent.trim())),
        pod: [...b.querySelectorAll('tbody.evk-stat-pod')].filter((x) => !x.hidden).map((x) => [...x.querySelectorAll('td:first-child')].map((td) => td.textContent)) };
    });
    const b0 = await box();
    await adm.click('.evk-stat-lista:has(h3:text-is("Nieistniejące strony (404)")) .evk-stat-pod-przelacz >> nth=0');
    const b1 = await box();
    console.log('      raport 404: ' + J({ b0, b1 }));
    t.check('raport: lista „Nieistniejące strony (404)” — ?page_id=999999 (2) na górze, potem pozostałe',
      !!b0 && J(b0.wiersze[0]) === J(['/?page_id=999999', '2', '1']) && b0.wiersze.length === 3 && b0.pod.length === 0, J(b0));
    t.check('rozwinięcie pierwszego adresu: skąd — example.org/artykul i (bezpośrednio)', !!b1 && b1.pod.length === 1
      && J(b1.pod[0].map((x) => x.replace('↳ ', '')).sort()) === J(['(bezpośrednio)', 'example.org/artykul']), J(b1));
    const csv = sonda('csv', wcz, wcz).csv || '';
    t.check('CSV: sekcje „Nieistniejące strony (404)” i „… skąd” (adres ← skąd)', /\n"Nieistniejące strony \(404\)";\/\?page_id=999997;1;1;;\n/.test(csv)
      && /\n"Nieistniejące strony: skąd";"\/\?page_id=999997 ← \(bezpośrednio\)";1;1;;\n/.test(csv), J(csv.split('\n').filter((x) => /Nieistniejące/.test(x))));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
