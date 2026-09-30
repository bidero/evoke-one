/**
 * „Przetłumacz brakujące (AI)” na pasku trybu sprawdzania i zamykanie okienka
 * ikonką (1.266.0).
 *
 * Decyzje zgłaszającego (30.09):
 *   - przycisk na dolnym pasku tłumaczy wszystkie brakujące teksty tej strony
 *     (treść, nagłówek, stopka) na język podglądu;
 *   - zapis jak w hurcie, ze znacznikiem „Do sprawdzenia”, potem strona się
 *     odświeża;
 *   - zamykanie okienka to zwykła ikonka, bez fioletowej ramki.
 *
 * Środowisko jak w tl-sprawdz: testowy WordPress przez `php -S`, atrapa
 * renderu Bricksa i atrapa dostawców AI jako mu-plugin (sonda
 * tests/php/tl-sprawdz.php). Kroki idą przez PRAWDZIWY punkt AJAX hurtu
 * (`evk_tl_ai_krok`, 61).
 *
 * Środowisko: tools/testowy-wp.sh.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('tl-sprawdz.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const json = (x) => JSON.stringify(x);

/** Błędy JS strony, bez skryptów z /wp-admin/ (patrz tl-sprawdz: profile.php po zalogowaniu). */
function lapBledy(p) {
  const bledy = [];
  p.on('pageerror', (e) => { if (!/\/wp-admin\//.test(String(e.stack || ''))) bledy.push(e.message); });
  return bledy;
}
const gotowa = (p) => p.waitForFunction(() => window.__evkTlSprawdz && window.__evkTlSprawdz.liczby && 'sprawdz' in window.__evkTlSprawdz.liczby,
  null, { timeout: 10000 }).catch(() => {});
const daneStrony = (p) => p.evaluate(() => {
  const e = document.getElementById('evk-tl-sprawdz-dane');
  try { return e ? JSON.parse(e.textContent) : null; } catch (x) { return null; }
});

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress i atrapa renderu Bricksa');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;

  let serwer = null;
  let browser = null;
  try {
    sonda('sprzataj');
    const mod = sonda('modul');
    const prep = sonda('przygotuj');
    const mu = sonda('mu');
    const scen = sonda('ai-scen', 'ok');
    const W = prep.wpisy || {};
    t.check('sonda: moduł, strony, szablony, atrapa renderu i atrapa AI', mod.gotowe === true && prep.gotowe === true && mu.mu === true
      && scen.scen === 'ok' && !!(W.A && W.H && W.F && W.S), json([mod, prep.brak || W, mu, scen]));
    if (prep.gotowe !== true || mu.mu !== true) return;

    serwer = await serwerWp.start(wp.wp);
    const b = serwer.baza;
    const adresA = b + '/en/?page_id=' + W.A + '&evk_tl_sprawdz=1';
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const k = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const p = await k.newPage();
    const bledy = lapBledy(p);
    await serwerWp.zaloguj(p, b);

    // ── Kiedy przycisk jest ──────────────────────────────────────────────
    t.section('przycisk tylko z kluczem API i brakami');
    sonda('ai-klucz', 'off');
    await p.goto(adresA);
    await gotowa(p);
    const bez = { dane: await daneStrony(p), przycisk: await p.locator('.evk-tls-ai-braki').count() };
    t.check('bez klucza API: bez przycisku i bez danych AI', !!bez.dane && bez.dane.ai === null && bez.przycisk === 0, json([bez.przycisk, bez.dane && bez.dane.ai]));
    sonda('ai-klucz', 'on');
    await p.goto(adresA);
    await gotowa(p);
    const d = await daneStrony(p);
    const ai = (d && d.ai) || {};
    const czesci = Array.isArray(ai.czesci) ? ai.czesci : [];
    const brA = sonda('braki', 'A');
    const brH = sonda('braki', 'H');
    const brF = sonda('braki', 'F');
    const brS = sonda('braki', 'S');
    console.log('      części: ' + json(czesci) + ' | braki A/H/F/S: ' + json([brA.ile, brH.ile, brF.ile, brS.ile]));
    const cz = (post, meta) => czesci.find((c) => c.post === post && c.meta === meta) || null;
    const cA = cz(W.A, '_bricks_page_content_2');
    const cH = cz(W.H, '_bricks_page_header_2');
    const cS = cz(W.S, '_bricks_page_content_2');
    t.check('dane: części z brakami liczonymi jak w hurcie — treść, nagłówek (szablon), wstawiony szablon sekcji',
      !!cA && cA.braki === brA.ile && brA.ile > 0 && cA.czesc === 'Treść' && cA.szablon === false
      && !!cH && cH.braki === brH.ile && brH.ile > 0 && cH.czesc === 'Nagłówek' && cH.szablon === true
      && !!cS && cS.braki === brS.ile && cS.szablon === true, json(czesci));
    t.check('dane: stopka bez braków poza listą; kopia w dwóch miejscach (K1) poza listą; nonce kroku hurtu',
      brF.ile === 0 && !cz(W.F, '_bricks_page_footer_2') && !czesci.some((c) => c.post === W.K1) && czesci.length === 3
      && /^[0-9a-f]{10}$/.test(ai.nonce_krok || ''), json([brF.ile, czesci.length, ai.nonce_krok]));
    const suma = czesci.reduce((a, c) => a + c.braki, 0);
    t.check('z kluczem i brakami: „Przetłumacz brakujące (AI)” na pasku, przed „Zakończ”', await p.evaluate(() => {
      const x = document.querySelector('.evk-tls-pasek .evk-tls-ai-braki');
      return !!x && x.textContent === 'Przetłumacz brakujące (AI)' && x.nextElementSibling && x.nextElementSibling.classList.contains('evk-tls-koniec');
    }));

    // ── Zamykanie okienka ────────────────────────────────────────────────
    t.section('zamykanie okienka: ikonka bez ramki');
    await p.locator('#brxe-b1').first().click();
    await p.waitForFunction(() => window.__evkTlSprawdz && window.__evkTlSprawdz.otwarty === 'b1', null, { timeout: 10000 }).catch(() => {});
    const zam = await p.evaluate(() => {
      const z = document.querySelector('.evk-tls-okno .evk-tls-zamknij');
      if (!z) return null;
      const r = z.getBoundingClientRect(), cs = getComputedStyle(z);
      const h = document.querySelector('.evk-tls-okno .evk-tls-tytul').getBoundingClientRect();
      const svg = z.querySelector('svg');
      return { w: r.width, h: r.height, ramka: cs.borderTopWidth, tlo: cs.backgroundColor, kolor: cs.color, tekst: z.textContent.trim(),
        svg: svg ? svg.getBoundingClientRect().width : 0, nazwa: z.getAttribute('aria-label'), klasa: z.className,
        srodek: Math.abs((r.top + r.height / 2) - (h.top + h.height / 2)) };
    });
    console.log('      zamykanie: ' + json(zam));
    t.check('32×32, bez ramki i tła, × jako SVG 14 px w kolorze #50575e, nazwa „Zamknij”', !!zam && zam.w === 32 && zam.h === 32
      && zam.ramka === '0px' && zam.tlo === 'rgba(0, 0, 0, 0)' && zam.kolor === 'rgb(80, 87, 94)' && zam.tekst === '' && zam.svg === 14
      && zam.nazwa === 'Zamknij' && !/evk-tls-przycisk/.test(zam.klasa), json(zam));
    t.check('ikonka na wysokości tytułu (środki ±2 px)', !!zam && zam.srodek <= 2, json(zam && zam.srodek));
    await p.focus('.evk-tls-okno .evk-tls-zamknij');
    await p.keyboard.press('Enter');
    await p.waitForTimeout(150);
    const poZam = await p.evaluate(() => ({ ukryte: document.querySelector('.evk-tls-okno').hidden,
      fokus: document.activeElement && document.activeElement.getAttribute('data-evk-tls-id') }));
    t.check('z klawiatury: Enter na ikonce zamyka okienko, fokus wraca na element', poZam.ukryte && poZam.fokus === 'b1', json(poZam));

    // ── Telefon ──────────────────────────────────────────────────────────
    t.section('telefon 360 px: pasek się zawija');
    const km = await browser.newContext({ viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
    const m = await km.newPage();
    await serwerWp.zaloguj(m, b);
    await m.goto(adresA);
    await gotowa(m);
    const tel = await m.evaluate(() => {
      const pas = document.querySelector('.evk-tls-pasek');
      const cele = Array.from(pas.querySelectorAll('button, a, label.evk-tls-wybor')).filter((x) => x.offsetParent !== null)
        .map((x) => { const r = x.getBoundingClientRect(); return { k: x.className, l: r.left, r: r.right, w: r.width, h: r.height, t: Math.round(r.top) }; });
      const ai = cele.find((c) => /evk-tls-ai-braki/.test(c.k));
      return { szer: document.documentElement.scrollWidth, okno: innerWidth, wiersze: new Set(cele.map((c) => c.t)).size,
        male: cele.filter((c) => c.w < 24 || c.h < 24).map((c) => c.k), poza: cele.filter((c) => c.l < 0 || c.r > innerWidth).map((c) => c.k), ai };
    });
    t.check('bez przewijania w poziomie; pasek w kilku wierszach; przycisk AI w ekranie', tel.szer <= tel.okno && tel.wiersze > 1
      && !!tel.ai && tel.poza.length === 0, json(tel));
    t.check('cele dotyku na pasku co najmniej 24×24', tel.male.length === 0, json(tel.male));
    await km.close();

    // ── Potwierdzenie ────────────────────────────────────────────────────
    t.section('potwierdzenie: liczba tekstów, znacznik, szablony');
    const kroki = [];
    p.on('request', (r) => { if (/admin-ajax\.php/.test(r.url()) && /evk_tl_ai_krok/.test(r.postData() || '')) kroki.push(r.postData() || ''); });
    let pytanie = '';
    p.once('dialog', (dl) => { pytanie = dl.message(); dl.dismiss(); });
    await p.click('.evk-tls-ai-braki');
    await p.waitForTimeout(400);
    console.log('      pytanie: ' + json(pytanie));
    t.check('liczba brakujących tekstów i język, uwaga o znaczniku „Do sprawdzenia”',
      pytanie.startsWith('Przetłumaczyć brakujące teksty tej strony na EN: ' + suma + '?\n\nTłumaczenia dostaną znacznik „Do sprawdzenia”.'), pytanie);
    t.check('nagłówek i szablon sekcji: dopisek, że tłumaczenie trafi na wszystkie strony z nimi',
      /\n\nNagłówek i szablon „TLS szablon sekcji” to szablony — tłumaczenie trafi na wszystkie strony, na których są\.$/.test(pytanie), pytanie);
    t.check('„Anuluj”: bez żądań', kroki.length === 0, kroki.length);

    // ── Przebieg ─────────────────────────────────────────────────────────
    t.section('przebieg: puste pola wypełnione, wypełnione nietknięte, podsumowanie po przeładowaniu');
    const przed = { h1: sonda('stan', 'A', 'h1'), t1: sonda('stan', 'A', 't1'), ok1: sonda('stan', 'A', 'ok1'), fn1: sonda('stan', 'F', 'fn1'),
      h1de: sonda('stan', 'A', 'h1', 'de') };
    sonda('ai-scen', '429-raz');
    p.once('dialog', (dl) => dl.accept());
    await p.click('.evk-tls-ai-braki');
    const odliczanie = await p.waitForFunction(() => {
      const s = document.querySelector('.evk-tls-pasek-stan');
      return s && /^Dostawca prosi o przerwę — ponawiam za \d+ s\.$/.test(s.textContent) ? s.textContent : false;
    }, null, { timeout: 20000, polling: 50 }).then((h) => h.jsonValue()).catch(() => '');
    const wZajety = await p.evaluate(() => { const x = document.querySelector('.evk-tls-ai-braki'); return x ? [x.disabled, x.getAttribute('aria-busy')] : null; })
      .catch(() => null);
    await p.waitForFunction(() => /^Przetłumaczone:/.test((document.querySelector('.evk-tls-pasek-stan') || {}).textContent || ''), null, { timeout: 60000 })
      .catch(() => {});
    await gotowa(p);
    const podsum = await p.evaluate(() => (document.querySelector('.evk-tls-pasek-stan') || {}).textContent || '');
    console.log('      odliczanie: ' + json(odliczanie) + ' | podsumowanie: ' + json(podsum) + ' | kroki: ' + kroki.length);
    t.check('limit dostawcy (429): odliczanie na pasku, potem ponowienie', odliczanie !== '' && !!wZajety, json([odliczanie, wZajety]));
    t.check('w trakcie: przycisk zajęty (disabled, aria-busy)', !!wZajety && wZajety[0] === true && wZajety[1] === 'true', json(wZajety));

    const po = { A: sonda('braki', 'A'), H: sonda('braki', 'H'), S: sonda('braki', 'S') };
    const b1 = sonda('stan', 'A', 'b1');
    const cs1 = sonda('stan', 'A', 'cs1');
    const hn1 = sonda('stan', 'H', 'hn1');
    console.log('      po: ' + json({ braki: [po.A.klucze, po.H.ile, po.S.ile], b1, cs1, hn1 }));
    t.check('puste pola wypełnione ze znacznikiem „Do sprawdzenia” (AI): przycisk, nagłówek z własnym ID, nagłówek strony',
      (b1.pola || {}).text === 'EN:Napisz do nas' && (b1.sprawdzone || {}).text === 'ai'
      && (cs1.pola || {}).text === 'EN:Własne ID' && (cs1.sprawdzone || {}).text === 'ai'
      && (hn1.pola || {}).text === 'EN:Menu główne' && (hn1.sprawdzone || {}).text === 'ai', json([b1, cs1, hn1]));
    const zostaly = (x) => json([x.pola, x.sprawdzone]);
    const poH = { h1: sonda('stan', 'A', 'h1'), t1: sonda('stan', 'A', 't1'), ok1: sonda('stan', 'A', 'ok1'), fn1: sonda('stan', 'F', 'fn1'),
      h1de: sonda('stan', 'A', 'h1', 'de') };
    t.check('wypełnione nietknięte (h1, t1, ok1, stopka, DE)', Object.keys(przed).every((x) => zostaly(przed[x]) === zostaly(poH[x])),
      json([przed, poH]));
    const liczby = /^Przetłumaczone: (\d+) \(Do sprawdzenia\), odrzucone: (\d+)\.$/.exec(podsum);
    const zostalo = po.A.ile + po.H.ile + po.S.ile;
    t.check('po przeładowaniu: „Przetłumaczone: N (Do sprawdzenia), odrzucone: M”; N + M + to, co zostało = braki przed',
      !!liczby && +liczby[1] > 0 && +liczby[1] + +liczby[2] === suma && zostalo === +liczby[2], json([podsum, suma, zostalo]));
    t.check('kroki szły przez hurt (evk_tl_ai_krok) z nonce kroku i językiem podglądu', kroki.length >= 4
      && kroki.every((x) => x.includes('name="nonce"\r\n\r\n' + ai.nonce_krok) && x.includes('name="lang"\r\n\r\nen')), json(kroki.slice(0, 1)));
    t.check('po przeładowaniu, bez braków: przycisku już nie ma', zostalo > 0 || await p.locator('.evk-tls-ai-braki').count() === 0);
    await p.reload();
    await gotowa(p);
    t.check('podsumowanie tylko raz (drugie przeładowanie — bez niego)', (await p.evaluate(() => document.querySelector('.evk-tls-pasek-stan').textContent)) === '');
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
