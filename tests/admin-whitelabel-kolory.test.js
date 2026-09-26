/**
 * White Label: kolory treści a pasek górny i ekrany Evoke ONE (1.239.0).
 *
 * ZGŁOSZONE Z UŻYCIA, dwie rzeczy o jednej przyczynie:
 *   „dodanie własnej pozycji do górnego paska — kolory inne niż ustawione
 *    w White Label",
 *   „zmiana kolorów tekstu i linków zmienia kolor napisów w panelu lewym
 *    Evoke ONE".
 *
 * Reguły kolorów treści szły po `#wpcontent`. W wp-admin pasek górny leży
 * WEWNĄTRZ #wpcontent, a „Kolor linków" ma !important i stoi w arkuszu po
 * regułach paska. Każda pozycja paska będąca odnośnikiem (także własna)
 * dostawała więc kolor linków treści, a pozycja-lista bez adresu zostawała
 * w kolorze paska. Zmierzone przed poprawką: własny odnośnik #dc2626 (kolor
 * linków), własna lista #eee, na froncie ten sam odnośnik #eee. Ta sama reguła
 * przebarwiała boczne menu panelu, także aktywną pozycję. Sam „Kolor główny"
 * (domyślnie #2563eb, czyli przy KAŻDYM włączonym White Label) niebieszczył
 * menu nawet bez !important, bo `#wpcontent a…` ma wyższą specyficzność niż
 * klasy panelu.
 *
 * Decyzja zgłaszającego: ekrany Evoke ONE mają własne kolory.
 *
 * Droga jak na żywo: testowy WordPress (tools/testowy-wp.sh) przez php -S,
 * prawdziwe arkusze rdzenia (pasek, schemat kolorów), Chromium. Wartości
 * w kolorach testu są celowo jaskrawe i RÓŻNE od siebie, żeby każdy przeciek
 * było widać po samym kolorze.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const q = (s) => "'" + String(s).replace(/'/g, "'\\''") + "'";
const sonda = (...a) => JSON.parse(phpOutput('white-label-kolory.php', a.map(q).join(' ')));

const KOLORY = {
  admin_bar_color: '#14532d', admin_bar_hover_color: '#166534', admin_bar_sub_color: '#052e16',
  color_admin_bar_link: '#fde047', color_link: '#dc2626', color_content_text: '#7c3aed', color_primary: '#0891b2',
};
const RGB = { link: 'rgb(220, 38, 38)', tekst: 'rgb(124, 58, 237)', glowny: 'rgb(8, 145, 178)' };
const POZYCJE = [
  { type: 'parent', id: 'evk-moje', title: 'Moje', icon: 'dashicons-star-filled', href: '', parent: '', target: '' },
  { type: 'item', id: 'evk-a1', title: 'Link A', href: '/', icon: 'dashicons-admin-home', parent: 'evk-moje', target: '' },
  { type: 'item', id: 'evk-solo', title: 'Solo', href: '/', icon: 'dashicons-heart', parent: '', target: '' },
];

/** Kolory panelu Evoke ONE na dwóch ekranach: przegląd (karty) i kopie (pasek zapisu). */
async function panel(p, baza) {
  const wynik = {};
  for (const [ekran, adres] of [['przegląd', ''], ['kopie', '&tab=backup']]) {
    await p.goto(baza + '/wp-admin/options-general.php?page=evoke-one' + adres);
    await p.mouse.move(900, 700);
    Object.assign(wynik, await p.evaluate((ekran) => {
      const kol = (sel, prop) => { const el = document.querySelector(sel); return el ? getComputedStyle(el)[prop] : 'brak'; };
      const o = {};
      o[ekran + ': menu, pozycja'] = kol('.evo-sidebar-link:not(.is-active)', 'color');
      o[ekran + ': menu, aktywna'] = kol('.evo-sidebar-link.is-active', 'color');
      o[ekran + ': nagłówek'] = kol('.evo-main-content h1', 'color');
      if (ekran === 'przegląd') o[ekran + ': karta modułu (odnośnik)'] = kol('.evo-module-card', 'color');
      if (ekran === 'kopie') o[ekran + ': przycisk zapisu'] = kol('.evo-save-bar .button-primary', 'backgroundColor');
      return o;
    }, ekran));
  }
  return wynik;
}

/** Pozycje paska w spoczynku; mysz odsunięta od paska. */
async function pasek(p) {
  await p.mouse.move(900, 700);
  await p.waitForTimeout(150);
  return p.evaluate(() => {
    const o = {};
    for (const id of ['evk-solo', 'evk-moje', 'new-content']) {
      const a = document.querySelector('#wp-admin-bar-' + id + ' > .ab-item');
      o[id] = a ? getComputedStyle(a).color : 'brak';
    }
    return o;
  });
}

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress przez php -S');

  const start = sonda('ustaw', JSON.stringify(Object.assign({ enabled: 0 }, KOLORY)), JSON.stringify(POZYCJE));
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !start.brak, start.brak || start.wp);
  if (start.brak) return;

  let serwer = null;
  let browser;
  try {
    serwer = await serwerWp.start(start.wp);
    const baza = serwer.baza;
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1400, height: 900 } });
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(p, baza);

    // ── Panel bez White Label: wzorzec ─────────────────────────────────────
    const wzor = await panel(p, baza);
    sonda('ustaw', JSON.stringify(Object.assign({ enabled: 1 }, KOLORY)), JSON.stringify(POZYCJE));
    const zWl = await panel(p, baza);

    t.section('ekrany Evoke ONE mają własne kolory (decyzja zgłaszającego)');
    t.check('pomiar panelu kompletny (każdy element znaleziony)',
      Object.values(wzor).concat(Object.values(zWl)).every((v) => v !== 'brak'), JSON.stringify({ wzor, zWl }));
    for (const k of Object.keys(wzor)) {
      t.check(k + ': ten sam kolor z White Label i bez', wzor[k] === zWl[k], 'bez: ' + wzor[k] + ', z: ' + zWl[k]);
    }
    t.check('aktywna pozycja menu dalej różni się od zwykłej (do 1.238.0 obie w kolorze linków)',
      zWl['przegląd: menu, aktywna'] !== zWl['przegląd: menu, pozycja'], JSON.stringify(zWl));

    // ── Zwykły ekran WordPressa: White Label działa dalej ───────────────────
    t.section('zwykły ekran WordPressa: kolory treści z White Label działają dalej');
    await p.goto(baza + '/wp-admin/index.php');
    await p.mouse.move(900, 700);
    const kokpit = await p.evaluate(() => {
      const kol = (sel, prop) => { const el = document.querySelector(sel); return el ? getComputedStyle(el)[prop] : 'brak'; };
      return {
        naglowek: kol('#wpbody-content h1', 'color'),
        odnosnik: kol('#wpbody-content .postbox .inside a:not(.button)', 'color'),
      };
    });
    t.check('nagłówek Kokpitu w „Kolorze tekstu" (#7c3aed)', kokpit.naglowek === RGB.tekst, JSON.stringify(kokpit));
    t.check('odnośnik w treści Kokpitu w „Kolorze linków" (#dc2626)', kokpit.odnosnik === RGB.link, JSON.stringify(kokpit));

    // ── Pasek górny w wp-admin ──────────────────────────────────────────────
    t.section('pasek górny: własne pozycje w kolorach paska, nie treści');
    const admin = await pasek(p);
    t.check('pomiar paska kompletny', Object.values(admin).every((v) => v !== 'brak'), JSON.stringify(admin));
    t.check('własny odnośnik NIE w kolorze linków treści (do 1.238.0: #dc2626)',
      admin['evk-solo'] !== RGB.link, JSON.stringify(admin));
    t.check('własny odnośnik w tym samym kolorze co własna lista', admin['evk-solo'] === admin['evk-moje'], JSON.stringify(admin));
    t.check('odnośnik rdzenia („+ Nowy") też w kolorze paska', admin['new-content'] === admin['evk-moje'], JSON.stringify(admin));

    await p.hover('#wp-admin-bar-evk-moje');
    await p.waitForTimeout(250);
    const podmenu = await p.evaluate(() => {
      const li = document.getElementById('wp-admin-bar-evk-a1');
      const a = li && li.querySelector('.ab-item');
      const ic = li && li.querySelector('.ab-icon');
      return a && ic ? { tekst: getComputedStyle(a).color, ikona: getComputedStyle(ic, '::before').color } : null;
    });
    t.check('ikona pozycji podmenu w kolorze jej tekstu, gdy lista jest otwarta (do 1.238.0: kolor linku paska)',
      !!podmenu && podmenu.ikona === podmenu.tekst, JSON.stringify(podmenu));

    // ── Ten sam pasek na froncie ────────────────────────────────────────────
    t.section('pasek na froncie wygląda tak samo jak w wp-admin');
    await p.goto(baza + '/');
    const front = await pasek(p);
    t.check('własny odnośnik: ten sam kolor w wp-admin i na froncie', front['evk-solo'] === admin['evk-solo'],
      'admin: ' + admin['evk-solo'] + ', front: ' + front['evk-solo']);

    // ── Sam „Kolor główny", bez koloru paska i linków ──────────────────────
    /* Tak wygląda White Label zaraz po włączeniu: kolor główny ma domyślną
       (#2563eb), pasek i linki puste. Wtedy przeciekała reguła BEZ !important
       (`#wpcontent a…{color: główny}`) — przy pasku rdzenia wygrywa
       specyficznością, bo nic jej nie przebija. */
    t.section('sam „Kolor główny" (White Label zaraz po włączeniu): pasek bez zmian');
    sonda('ustaw', JSON.stringify({ enabled: 1, color_primary: KOLORY.color_primary }), JSON.stringify(POZYCJE));
    await p.goto(baza + '/wp-admin/index.php');
    const glowny = await pasek(p);
    t.check('własny odnośnik NIE w kolorze głównym (#0891b2)', glowny['evk-solo'] !== RGB.glowny, JSON.stringify(glowny));
    t.check('własny odnośnik w tym samym kolorze co własna lista', glowny['evk-solo'] === glowny['evk-moje'], JSON.stringify(glowny));
    const kokpitGlowny = await p.evaluate(() => {
      const a = document.querySelector('#wpbody-content .postbox .inside a:not(.button)');
      return a ? getComputedStyle(a).color : 'brak';
    });
    t.check('odnośnik w treści Kokpitu dalej w kolorze głównym', kokpitGlowny === RGB.glowny, kokpitGlowny);

    t.check('bez błędów JS na stronach', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('przywroc');
  }

  // ── Które ekrany są „ekranami Evoke ONE" ──────────────────────────────────
  t.section('lista ekranów Evoke ONE obejmuje wszystkie strony wtyczki');
  const ekrany = JSON.parse(phpOutput('white-label-kolory.php', 'ekrany'));
  t.check('każda strona wtyczki rozpoznana, obce nie', !ekrany.brak && ekrany.zle.length === 0,
    ekrany.brak || JSON.stringify(ekrany));
};
