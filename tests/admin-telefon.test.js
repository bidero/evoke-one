/**
 * Panel na telefonie (1.240.0): każdy ekran wtyczki przy 360 px.
 *
 * Zakres analizy wybrany przez zgłaszającego: „panel Evoke ONE na telefonie —
 * każda zakładka przy 360 px". Zmierzone na 1.239.0, 56 ekranów:
 *   - nic nie wystaje w bok i strona nigdzie nie przewija się w poziomie,
 *     menu otwiera się przyciskiem — to zostaje pod strażą;
 *   - pola 13 px (miejscami 12 px) na 30 ekranach. Safari na iPhonie
 *     powiększa stronę przy polu z pismem poniżej 16 px i nie cofa tego po
 *     wyjściu z pola. WordPress sam daje swoim polom 16 px przy tej
 *     szerokości; panel nadpisywał to swoim 13 px;
 *   - cele dotyku poniżej 24×24 px (WCAG 2.5.8): wiersze pól zaznaczenia
 *     21 px wysokości (w White Label 15 px), ikony „usuń warstwę" OG 14 px
 *     szerokości, oko i „usuń" w menu White Label 20 i 9 px, podsumowanie
 *     „Jak to działa" 18 px, samodzielne odnośniki 12–15 px.
 *
 * Wyjątki są te z WCAG, nie wygodne: odnośnik W ZDANIU (rodzic ma tekst
 * poza nim), pole pliku ukryte w strefie upuszczania (celem jest strefa)
 * i ukryte pole przełącznika (celem jest suwak).
 *
 * Droga jak na żywo: testowy WordPress (tools/testowy-wp.sh) przez php -S,
 * ekrany zebrane z prawdziwego menu panelu (strona główna, sekcje i ich
 * ekrany) plus strony modułów: Tłumaczenia, Newsletter, Skrzynka. Emulacja
 * telefonu w Chromium; Safari na iPhonie tu nie ma, więc powiększanie przy
 * polu sprawdzamy rozmiarem pisma, a nie zachowaniem.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => JSON.parse(phpOutput('admin-telefon.php', a));

/** Pomiar jednego ekranu — w przeglądarce. */
function pomiar() {
  const W = innerWidth;
  const widoczny = (el) => {
    const s = getComputedStyle(el);
    if (s.visibility === 'hidden' || s.display === 'none') return false;
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0;
  };
  const opis = (el, r) => el.tagName.toLowerCase() + (el.id ? '#' + el.id : '')
    + (el.classList.length ? '.' + [...el.classList].slice(0, 2).join('.') : '')
    + ' „' + (el.textContent || el.value || el.getAttribute('aria-label') || '').trim().replace(/\s+/g, ' ').slice(0, 24) + '"'
    + (r ? ' ' + Math.round(r.width) + '×' + Math.round(r.height) : '');
  /* Wolno wystawać tylko wewnątrz kontenera PRZEWIJANEGO w poziomie (auto,
     scroll), który sam mieści się na ekranie — tam treść da się przesunąć
     palcem. Przycięcie (hidden, clip) to nie ratunek, tylko ucięta treść:
     pierwsza wersja strażnika brała je za „w porządku" i mutacja z suwakiem
     szerszym niż telefon przeszła na zielono — karta Newslettera ucinała go
     w połowie, a strona się nie przewijała. */
  const przycina = (el) => {
    for (let a = el.parentElement; a && a !== document.body; a = a.parentElement) {
      if (/(auto|scroll)/.test(getComputedStyle(a).overflowX)) {
        const r = a.getBoundingClientRect();
        if (r.right <= W + 1 && r.left >= -1) return true;
      }
    }
    return false;
  };
  const tresc = document.getElementById('wpbody-content');
  const poza = (el) => !!el.closest('#screen-meta, #screen-meta-links');

  const wystaja = [];
  for (const el of tresc.querySelectorAll('*')) {
    if (poza(el) || !widoczny(el)) continue;
    const r = el.getBoundingClientRect();
    if ((r.right > W + 1 || r.left < -1) && !przycina(el) && !wystaja.some((w) => w.el.contains(el))) wystaja.push({ el, o: opis(el) + ' [' + Math.round(r.left) + '…' + Math.round(r.right) + ']' });
  }

  const pola = [];
  for (const el of tresc.querySelectorAll('input, select, textarea')) {
    if (poza(el) || !widoczny(el)) continue;
    if (el.tagName === 'INPUT' && /^(hidden|checkbox|radio|button|submit|reset|image|color|file|range)$/.test(el.type)) continue;
    const f = parseFloat(getComputedStyle(el).fontSize);
    if (f < 16) pola.push(opis(el) + ' ' + f + 'px');
  }

  const cele = [];
  for (const el of tresc.querySelectorAll('a[href], button, input, select, textarea, summary, [role=button]')) {
    if (poza(el) || !widoczny(el) || (el.tagName === 'INPUT' && el.type === 'hidden')) continue;
    // Odnośnik w zdaniu — wyjątek WCAG 2.5.8 („inline").
    if (el.tagName === 'A' && el.parentElement && el.parentElement.textContent.trim().length > el.textContent.trim().length + 2) continue;
    // Pole pliku ukryte w strefie upuszczania i pole przełącznika: celem jest strefa / suwak.
    if (el.tagName === 'INPUT' && (el.type === 'file' || el.closest('.evo-toggle')) && parseFloat(getComputedStyle(el).opacity) === 0) continue;
    let r = el.getBoundingClientRect();
    if ((el.type === 'checkbox' || el.type === 'radio') && el.closest('label')) r = el.closest('label').getBoundingClientRect();
    if (r.width < 23.5 || r.height < 23.5) cele.push(opis(el, r));
  }

  return {
    przewija: document.documentElement.scrollWidth > W + 1 ? document.documentElement.scrollWidth : 0,
    wystaja: wystaja.map((w) => w.o), pola, cele,
  };
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
    const A = serwer.baza + '/wp-admin/';
    browser = await chromium.launch({ executablePath: chromiumPath() });

    // ── Ekrany z prawdziwego menu panelu ────────────────────────────────────
    const kd = await browser.newContext({ viewport: { width: 1400, height: 900 } });
    const d = await kd.newPage();
    await serwerWp.zaloguj(d, serwer.baza);
    const panel = A + 'options-general.php?page=evoke-one';
    await d.goto(panel);
    const adresy = new Set([panel]);
    for (const u of await d.$$eval('.evo-sidebar-link', (a) => a.map((x) => x.href))) {
      adresy.add(u);
      await d.goto(u);
      for (const s of await d.$$eval('.evo-sidebar-sublink', (a) => a.map((x) => x.href))) adresy.add(s);
    }
    for (const tab of ['translations', 'images', 'slugs', 'dd', 'languages', 'sitemap', 'io']) adresy.add(A + 'options-general.php?page=evoke-tlumaczenia&tab=' + tab);
    for (const tab of ['lists', 'templates', 'campaigns', 'reports', 'settings']) adresy.add(A + 'admin.php?page=evoke-newsletter&subtab=' + tab);
    adresy.add(A + 'admin.php?page=evk-form-inbox');

    t.section('telefon 360 px: każdy ekran wtyczki');
    const km = await browser.newContext({
      viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2,
      storageState: await kd.storageState(),
    });
    const m = await km.newPage();
    const bledy = [];
    m.on('pageerror', (e) => bledy.push(e.message));
    const zle = { status: [], przewija: [], wystaja: [], pola: [], cele: [] };
    for (const u of adresy) {
      const r = await m.goto(u, { waitUntil: 'load' });
      await m.waitForTimeout(100);
      const n = u.replace(A, '').replace('options-general.php?page=', '').replace('admin.php?page=', '');
      if (r.status() !== 200) { zle.status.push(n + ' → ' + r.status()); continue; }
      const w = await m.evaluate(pomiar);
      if (w.przewija) zle.przewija.push(n + ' → ' + w.przewija + ' px');
      for (const x of w.wystaja) zle.wystaja.push(n + ': ' + x);
      for (const x of w.pola) zle.pola.push(n + ': ' + x);
      for (const x of w.cele) zle.cele.push(n + ': ' + x);
    }
    const lista = (a) => a.length + ' — ' + a.slice(0, 12).join(' | ') + (a.length > 12 ? ' | …' : '');
    t.check('zebrano ekrany: menu panelu i strony modułów (co najmniej 50)', adresy.size >= 50, String(adresy.size));
    t.check('każdy ekran się otworzył (200)', zle.status.length === 0, lista(zle.status));
    t.check('strona nigdzie nie przewija się w poziomie', zle.przewija.length === 0, lista(zle.przewija));
    t.check('nic nie wystaje poza ekran ani nie jest ucięte przy jego krawędzi (poza kontenerami przewijanymi)', zle.wystaja.length === 0, lista(zle.wystaja));
    t.check('pola co najmniej 16 px (Safari nie powiększa strony przy polu)', zle.pola.length === 0, lista(zle.pola));
    t.check('cele dotyku co najmniej 24×24 px (WCAG 2.5.8)', zle.cele.length === 0, lista(zle.cele));
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));

    // ── Menu panelu na telefonie ────────────────────────────────────────────
    t.section('menu panelu na telefonie');
    await m.goto(panel);
    await m.locator('.evo-mobile-menu').tap();
    await m.waitForTimeout(400);
    const menu = await m.evaluate(() => {
      const s = document.getElementById('evo-sidebar').getBoundingClientRect();
      return { l: Math.round(s.left), r: Math.round(s.right), rozwiniete: document.querySelector('.evo-mobile-menu').getAttribute('aria-expanded') };
    });
    t.check('przycisk otwiera menu, a menu mieści się na ekranie', menu.rozwiniete === 'true' && menu.l >= 0 && menu.r <= 360, JSON.stringify(menu));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('przywroc');
  }
};
