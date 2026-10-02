/**
 * Frazy słownika i etykiety menu w tłumaczeniu AI (1.279.0) — sonda
 * tests/php/tl-frazy.php (prawdziwy WordPress, AI przez atrapę) i zakładka
 * fraz w Chromium.
 *
 * Decyzje zgłaszającego (02.10): frazy w hurcie z wyborem dostawcy i modelu
 * na przebieg oraz w Claude Desktop (MCP); etykiety menu same do grupy
 * „Menu”; fraza z AI ma znacznik „AI”, który znika po poprawce albo
 * „Sprawdzone”, i trafia na listę „Do sprawdzenia”.
 */

const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('tl-frazy.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const tl = (w, lang) => Object.fromEntries(Object.entries(w || {}).map(([k, v]) => [k, v[lang]]));

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  sonda('sprzataj');
  let serwer = null;
  let browser = null;
  try {
    const p = sonda('przygotuj');
    t.check('słownik „Ogólne”, strona i menu „Główne F”', p.gotowe === true, J(p));
    if (p.gotowe !== true) return;

    t.section('etykiety menu do słownika, grupy w zakresie hurtu');
    const j1 = sonda('jednostki');
    t.check('grupa „Menu”: „Blog” i etykieta strony „O nas F”; „Oferta” (już jest) i „123” (bez liter) pominięte',
      j1.nazwa_menu === 'Menu' && J(j1.menu) === J(['Blog', 'O nas F']), J(j1.menu));
    t.check('wiersz zakresu „Słownik fraz”; grupy z brakami: Ogólne 3 (EN, DE), Menu 2',
      j1.wiersze && j1.wiersze.rodzaj === 'frazy' && J(j1.jednostki.map((x) => [x[0], x[2]])) === J([['ogolne', { en: 3, de: 3 }], ['menu', { en: 2, de: 2 }]]), J(j1));
    t.check('drugi raz menu nie dopisuje się podwójnie', J(sonda('jednostki').menu) === J(['Blog', 'O nas F']));

    t.section('hurt: Gemini (ustawienia), potem Claude z modelem na przebieg');
    const k1 = sonda('krok', 'ogolne', 'en');
    t.check('Ogólne EN przez Gemini: trzy puste, „Contact” (ręczne) nietknięte, <strong> zachowany',
      k1.wynik && k1.wynik.zapisane === 3 && /generativelanguage/.test(k1.url)
      && J(tl(k1.wiersze, 'en')) === J({ row_r1: 'Contact', row_r2: 'EN:Oferta', row_r3: 'EN:Nasza firma', row_r4: '<strong>EN:Nowość</strong>EN: w ofercie' }), J([k1.wynik, tl(k1.wiersze, 'en')]));
    t.check('znaczniki AI z modelem gemini (3, bez ręcznej frazy)', Object.values((k1.znaczniki || {}).en || {}).length === 3
      && Object.values(k1.znaczniki.en).every((z) => z.m === 'gemini/gemini-3.8-flash'), J(k1.znaczniki));
    t.check('pamięć tłumaczeń (np. dla elementów Bricksa): fraza AI nie podpowiada, ręczna tak',
      sonda('pamiec', 'en', 'Oferta').tl === null && sonda('pamiec', 'en', 'Kontakt').tl === 'Contact');
    const k2 = sonda('krok', 'menu', 'en', 'claude', 'claude-x');
    t.check('Menu EN przez Claude z modelem claude-x (wybór na przebieg)', k2.wynik && k2.wynik.zapisane === 2 && /anthropic/.test(k2.url)
      && Object.values(tl(k2.wiersze, 'en')).every((x) => /^EN\[claude-x\]:/.test(x))
      && Object.values(k2.znaczniki.en).filter((z) => z.m === 'claude/claude-x').length === 2, J([k2.wynik, tl(k2.wiersze, 'en')]));
    const r = sonda('render', 'en', '<nav><a href="#b">Blog</a> <a href="#o">Oferta</a> <a href="#k">Kontakt</a></nav>');
    t.check('strona EN: silnik fraz podmienia etykiety menu', r.html === '<nav><a href="#b">EN[claude-x]:Blog</a> <a href="#o">EN:Oferta</a> <a href="#k">Contact</a></nav>', r.html);

    t.section('ponownie: tylko tłumaczenia AI, ręczne zostają');
    const k3 = sonda('krok', 'ogolne', 'en', 'claude', 'claude-y', 'ponownie');
    t.check('ponownie z claude-y: trzy frazy AI od nowa, „Contact” nietknięty, znaczniki z nowym modelem',
      k3.wynik && k3.wynik.zapisane === 3 && tl(k3.wiersze, 'en').row_r1 === 'Contact' && /^EN\[claude-y\]:Oferta$/.test(tl(k3.wiersze, 'en').row_r2)
      && Object.values(k3.znaczniki.en).filter((z) => z.m === 'claude/claude-y').length === 3, J([k3.wynik, tl(k3.wiersze, 'en')]));

    const wt = sonda('krok-w-trakcie', 'menu', 'en', Object.keys(k2.wiersze)[0]);
    t.check('hurt: tłumaczenie wpisane w trakcie zapytania do AI zostaje, druga fraza zapisana',
      wt.wynik && wt.wynik.zapisane === 1 && tl(wt.wiersze, 'en')[Object.keys(k2.wiersze)[0]] === 'Wpisane w trakcie', J([wt.wynik, tl(wt.wiersze, 'en')]));

    t.section('lista „Do sprawdzenia” i ręczna poprawka');
    sonda('zmien', 'ogolne', 'row_r3', 'en', 'Our company');
    const l1 = sonda('lista');
    const l1en = (l1.lista || []).filter((x) => x[1] === 'en').map((x) => x[2]);
    t.check('lista: frazy AI z grupą; poprawiona ręcznie („Nasza firma”) zniknęła sama',
      J(l1en) === J(['Oferta', '<strong>Nowość</strong> w ofercie', 'O nas F']) && (l1.lista || []).every((x) => x[5] === 'evk_frazy'), J(l1.lista));
    const k4 = sonda('krok', 'ogolne', 'en', '', '', 'ponownie');
    t.check('kolejny przebieg „ponownie” nie rusza poprawionej ręcznie', tl(k4.wiersze, 'en').row_r3 === 'Our company', J(tl(k4.wiersze, 'en')));

    t.section('prawo: krok przez prawdziwy AJAX');
    const a1 = sonda('ajax-krok', 'ogolne', 'de');
    t.check('administrator: grupa DE przetłumaczona', a1.odp && a1.odp.success === true && a1.odp.data.zapisane === 3, J(a1.odp).slice(0, 300));
    const a2 = sonda('ajax-krok', 'menu', 'de', 'subskrybent');
    t.check('subskrybent: odmowa, nic nie zapisane', a2.odp && a2.odp.success === false && Object.values(tl(a2.wiersze, 'de')).every((x) => x === ''), J(a2.odp));
    const a3 = sonda('ajax-krok', 'nie-ma-grupy', 'de');
    t.check('nieznana grupa: komunikat', a3.odp && a3.odp.success === false && /grupa fraz/.test(J(a3.odp)), J(a3.odp));

    t.section('Claude Desktop (MCP): frazy jak każda część');
    const m1 = sonda('mcp', 'menu', 'de');
    t.check('pobranie i zapis grupy Menu DE: dwie frazy, podpis mcp/claude-test, braki MCP bez tej grupy w DE',
      m1.pobierz && (m1.pobierz.teksty || []).length === 2 && m1.zapisz && m1.zapisz.zapisane === 2 && m1.zapisz.gotowe === true
      && Object.values(tl(m1.wiersze, 'de')).every((x) => /^DE-MCP:/.test(x))
      && Object.values(m1.znaczniki.de || {}).filter((z) => z.m === 'mcp/claude-test').length === 2 && !m1.braki.some((c) => /\|menu\|/.test(c)), J([m1.zapisz, m1.braki]));
    sonda('zmien', 'menu', Object.keys(m1.wiersze)[0], 'de', '');
    sonda('zmien', 'menu', Object.keys(m1.wiersze)[1], 'de', '');
    const m2 = sonda('mcp', 'menu', 'de', 'zmien');
    t.check('ktoś wpisał tłumaczenie między pobraniem a zapisem: to zostaje, druga fraza zapisana',
      m2.zapisz && m2.zapisz.zapisane === 1 && (m2.zapisz.odrzucone || []).length === 1 && Object.values(tl(m2.wiersze, 'de')).includes('Wpisane ręcznie'), J([m2.zapisz, tl(m2.wiersze, 'de')]));

    t.section('zakładka fraz w Chromium: znacznik AI, zapis zakładki, „Sprawdzone”');
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const s = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    s.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(s, serwer.baza);
    const zakladka = serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=translations';
    const policz = () => s.evaluate(() => ({
      znaczniki: document.querySelectorAll('.tl-fraza-ai').length,
      pigulki: document.querySelectorAll('.tl-pill-ai').length,
      lista: [...document.querySelectorAll('.tl-do-sprawdzenia .tl-el-sprawdzone[data-meta="evk_frazy"]')].length,
    }));
    await s.goto(zakladka);
    const z1 = await policz();
    const oczekiwane = sonda('lista').lista.length;
    console.log('      zakładka: ' + J(z1) + ', fraz AI w opcji: ' + oczekiwane);
    t.check('znacznik „AI — do sprawdzenia” przy każdym tłumaczeniu AI, pigułka języka i wiersz na liście', z1.znaczniki === oczekiwane && z1.pigulki === oczekiwane
      && z1.lista === oczekiwane && oczekiwane > 3, J([z1, oczekiwane]));
    await s.click('#btn-save-translations');
    await s.waitForSelector('#save-status-translations.ok', { timeout: 15000 }).catch(() => {});
    await s.goto(zakladka);
    const z2 = await policz();
    t.check('zapis zakładki bez zmian nie gasi znaczników', z2.znaczniki === oczekiwane, J(z2));
    const pole = s.locator('.tl-group[data-gid="ogolne"] .tl-row[data-rid="row_r2"] textarea[data-field="en"]');
    await pole.evaluate((e) => { e.value = 'Offer'; e.dispatchEvent(new Event('input', { bubbles: true })); });
    await s.click('#btn-save-translations');
    await s.waitForSelector('#save-status-translations.ok', { timeout: 15000 }).catch(() => {});
    await s.goto(zakladka);
    const z3 = await policz();
    const r2 = await pole.evaluate((e) => e.closest('.tl-field').querySelectorAll('.tl-fraza-ai').length);
    t.check('poprawka w zakładce: jej znacznik znika, reszta zostaje', z3.znaczniki === oczekiwane - 1 && r2 === 0, J([z3, r2]));
    const przycisk = s.locator('.tl-do-sprawdzenia .tl-el-sprawdzone[data-meta="evk_frazy"]').first();
    await przycisk.click();
    await s.waitForFunction((n) => document.querySelectorAll('.tl-do-sprawdzenia .tl-el-sprawdzone[data-meta="evk_frazy"]').length === n, oczekiwane - 2, { timeout: 10000 }).catch(() => {});
    await s.goto(zakladka);
    const z4 = await policz();
    t.check('„Sprawdzone” na liście: wiersz i znacznik znikają po przeładowaniu', z4.znaczniki === oczekiwane - 2 && z4.lista === oczekiwane - 2, J(z4));
    t.check('bez błędów JS', bledy.length === 0, J(bledy));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
