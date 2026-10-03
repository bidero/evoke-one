/**
 * Menu „Evoke” w pasku admina na stronie (1.290.0) — prawdziwy WordPress
 * przez php -S, Chromium na komputerze i na telefonie (360 px, dotyk).
 * Jedno menu z grupami Statystyki (licznik w tytule), Hotspoty, Tłumaczenia
 * i panel; każda rola widzi tylko swoje grupy; konserwacja zostaje osobno.
 */

const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('pasek-evoke.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const TELEFON = { viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true,
  userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36' };

/** Stan menu: pozycje (id, tekst, nagłówek?), licznik, czy rozwinięte; pozycje modułów poza menu. */
const menu = (p) => p.evaluate(() => {
  const m = document.getElementById('wp-admin-bar-evk-menu');
  const poza = ['evk-statystyki', 'evk-hotspoty', 'evk-tl-sprawdz'].filter((id) => { const e = document.getElementById('wp-admin-bar-' + id); return e && !(m && m.contains(e)); });
  if (!m) return { jest: false, poza };
  const sw = m.querySelector(':scope > .ab-sub-wrapper');
  return {
    jest: true, poza, etykieta: (m.querySelector(':scope > .ab-item .ab-label') || {}).textContent,
    licznik: (m.querySelector(':scope > .ab-item .evk-pasek-licznik') || {}).textContent || null,
    opis: (m.querySelector(':scope > .ab-item') || { getAttribute: () => '' }).getAttribute('title'),
    pozycje: [...m.querySelectorAll('.ab-submenu > li')].map((li) => (li.classList.contains('evk-pasek-naglowek') ? '# ' : '') + li.querySelector('.ab-item').textContent.trim()),
    widac: !!sw && getComputedStyle(sw).display !== 'none' && sw.getBoundingClientRect().height > 0,
  };
});

module.exports = async function (t) {
  t.section('środowisko');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  let serwer = null;
  let browser = null;
  try {
    const prz = sonda('przygotuj');
    serwer = await serwerWp.start(wp.wp);
    const baza = serwer.baza;
    const strona = baza + '/?page_id=' + prz.strona;
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const bledy = [];
    const nowa = async (opcje, login, haslo) => {
      const p = await (await browser.newContext(opcje || { viewport: { width: 1280, height: 800 } })).newPage();
      p.on('pageerror', (e) => bledy.push(e.message));
      await serwerWp.zaloguj(p, baza, login || 'admin', haslo || 'admin');
      await p.goto(strona);
      return p;
    };

    t.section('administrator na komputerze');
    const a = await nowa();
    await a.hover('#wp-admin-bar-evk-menu');
    await a.waitForTimeout(500);
    const m1 = await menu(a);
    console.log('      menu: ' + J(m1));
    t.check('jedno menu „Evoke” z licznikiem tej strony w tytule (dziś / 30 dni) i opisem po najechaniu',
      m1.jest && m1.etykieta === 'Evoke' && /^\d+ \/ \d+$/.test(m1.licznik || '') && /^Odsłony tej strony: dziś \d+, 30 dni \d+$/.test(m1.opis || ''), J(m1));
    t.check('grupy w kolejności: Statystyki, Hotspoty, Tłumaczenia (EN, DE), panel — każda z nagłówkiem',
      J(m1.pozycje.map((x) => x.replace(/\d+/g, 'N'))) === J(['# Statystyki', 'Odsłony tej strony: dziś N, N dni N', 'Raport statystyk', '# Hotspoty', 'Nagrywaj tę stronę (N dni albo N wizyt)',
        '# Tłumaczenia', 'Sprawdź tłumaczenia (EN)', 'Sprawdź tłumaczenia (DE)', 'Panel Evoke ONE']), J(m1.pozycje));
    t.check('pozycje modułów tylko w menu — nie osobno w pasku; po najechaniu menu rozwinięte', m1.poza.length === 0 && m1.widac, J(m1.poza));
    const wierzch = await a.evaluate(() => ({ konserwacja: !!document.querySelector('#wp-admin-bar-root-default > #wp-admin-bar-maintenance_toggle_node'),
      evoke: !!document.querySelector('#wp-admin-bar-root-default > #wp-admin-bar-evk-menu'),
      panel: (document.querySelector('#wp-admin-bar-evk-panel a') || {}).href || '' }));
    t.check('konserwacja zostaje osobno na wierzchu paska; „Panel Evoke ONE” prowadzi do panelu', wierzch.konserwacja && wierzch.evoke && /options-general\.php\?page=evoke-one$/.test(wierzch.panel), J(wierzch));
    await a.goto(baza + '/wp-admin/');
    t.check('w kokpicie menu „Evoke” nie ma (pozycje dotyczą strony)', !(await menu(a)).jest);

    t.section('role: każda widzi tylko swoje');
    const c = await nowa(null, 'pasek_czyt', 'test-haslo');
    const mc = await menu(c);
    t.check('czytelnik statystyk: tylko Statystyki (bez Hotspotów — strona nienagrywana, bez Tłumaczeń i panelu)',
      mc.jest && J(mc.pozycje.map((x) => x.replace(/\d+/g, 'N'))) === J(['# Statystyki', 'Odsłony tej strony: dziś N, N dni N', 'Raport statystyk']), J(mc));
    const tl = await nowa(null, 'pasek_tlum', 'test-haslo');
    const mt = await menu(tl);
    t.check('tłumacz: tylko Tłumaczenia, bez licznika w tytule', mt.jest && J(mt.pozycje) === J(['# Tłumaczenia', 'Sprawdź tłumaczenia (EN)', 'Sprawdź tłumaczenia (DE)']) && mt.licznik === null, J(mt));
    const n = await nowa(null, 'pasek_nikt', 'test-haslo');
    t.check('konto bez żadnego z tych uprawnień: menu „Evoke” nie ma', !(await menu(n)).jest);

    t.section('licznik wyłączony');
    sonda('licznik', 0);
    await a.goto(strona);
    const m2 = await menu(a);
    sonda('licznik', 1);
    t.check('bez licznika: tytuł bez liczb, w grupie Statystyki zostaje „Raport statystyk”', m2.jest && m2.licznik === null && m2.pozycje[0] === '# Statystyki' && m2.pozycje[1] === 'Raport statystyk', J(m2));

    t.section('telefon 360 px');
    const f = await nowa(TELEFON);
    const przed = await f.evaluate(() => { const e = document.querySelector('#wp-admin-bar-evk-menu > .ab-item'); const r = e && e.getBoundingClientRect();
      return r && { l: r.left, p: r.right, w: r.width, h: r.height, widac: getComputedStyle(e.parentNode).display !== 'none' }; });
    t.check('na telefonie „Evoke” jest w pasku: widoczne, w ekranie, cel dotyku ≥ 24 px', !!przed && przed.widac && przed.l >= 0 && przed.p <= 360 && przed.w >= 24 && przed.h >= 24, J(przed));
    await f.tap('#wp-admin-bar-evk-menu > .ab-item');
    await f.waitForTimeout(500);
    const mf = await menu(f);
    const geo = await f.evaluate(() => {
      const sw = document.querySelector('#wp-admin-bar-evk-menu > .ab-sub-wrapper'), r = sw.getBoundingClientRect();
      const li = [...sw.querySelectorAll('li:not(.evk-pasek-naglowek) > a.ab-item')].map((x) => { const q = x.getBoundingClientRect(); return { h: q.height, l: q.left, p: q.right, f: parseFloat(getComputedStyle(x).fontSize) }; });
      return { l: r.left, p: r.right, d: r.bottom, poziomo: document.documentElement.scrollWidth, li };
    });
    t.check('dotknięcie rozwija menu na całą szerokość ekranu (0–360 px), bez przewijania w poziomie', mf.widac && geo.l === 0 && geo.p === 360 && geo.poziomo <= 360, J({ widac: mf.widac, geo: { l: geo.l, p: geo.p, poziomo: geo.poziomo } }));
    t.check('na telefonie pozycje: pismo 16 px, cel dotyku ≥ 24 px, w ekranie; te same grupy', geo.li.length === 6 && geo.li.every((x) => x.f >= 16 && x.h >= 24 && x.l >= 0 && x.p <= 360)
      && mf.pozycje.length === m1.pozycje.length, J(geo.li));
    await f.tap('#wp-admin-bar-evk-menu > .ab-item');
    await f.waitForTimeout(400);
    t.check('drugie dotknięcie zwija menu', !(await menu(f)).widac);
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
