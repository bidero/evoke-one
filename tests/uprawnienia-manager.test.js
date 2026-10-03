/**
 * Panel Evoke ONE dla roli bez praw administratora (1.292.0) — prawdziwy
 * WordPress, prawdziwe zapisy AJAX i panel w Chromium.
 *
 * Decyzje zgłaszającego z 03.10: rola typu Manager wchodzi przez Ustawienia →
 * Evoke ONE, a panel pokazuje WYŁĄCZNIE ekrany z jej dostępów (SEO,
 * Przekierowania i 404, Kopia teraz, 2FA kont) i zamyka resztę także po
 * adresie. Kopie: tylko „Utwórz kopię teraz”. Logowanie: lista kont
 * i „Wyłącz 2FA” innemu kontu, nie administratorowi.
 *
 * Dwie warstwy, bo granica bezpieczeństwa leży po stronie serwera:
 * - macierz (sonda): każdy zapis AJAX jako administrator, Manager, rola z samym
 *   SEO i subskrybent. Panel, który chowa przycisk, a serwer przyjmuje zapis,
 *   przeszedłby same sprawdzenia z przeglądarki;
 * - Chromium: co widzi każde konto, 403 po adresie, zapis Schema przez AJAX
 *   (bez zapasowego wysłania formularza), dodanie przekierowania i „Wyłącz 2FA”.
 *
 * Środowisko: tools/testowy-wp.sh (php -S z routerem). Sonda:
 * tests/php/uprawnienia-manager.php.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('uprawnienia-manager.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};

const BRAK_DOSTEPU = 'Brak dostępu do tej części panelu.';

/** Zakładki paska bocznego panelu (parametr tab z odnośników). */
const zakladki = (p) => p.$$eval('.evo-sidebar-link', (a) => a.map((e) => new URL(e.href).searchParams.get('tab') || 'dashboard').sort());

/** Ekrany zakładki, do których prowadzi przegląd i pasek boczny (parametr sub). */
const ekrany = (p, tab) => p.$$eval('a[href*="tab=' + tab + '&sub="], a[href*="tab=' + tab + '&amp;sub="]',
  (a) => [...new Set(a.map((e) => new URL(e.href).searchParams.get('sub')))].sort());

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress, role i konta');
  const prep = sonda('przygotuj');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !prep.brak && !!(prep.id && prep.id.t_manager), prep.brak || JSON.stringify(prep));
  if (prep.brak || !prep.id) return;

  let serwer = null;
  let browser;
  try {
    // ── Macierz zapisów po stronie serwera ───────────────────────────────
    t.section('zapisy AJAX: każde konto tylko w swoich dostępach');
    const mac = sonda('macierz');
    const m = mac.macierz || {};
    const oczek = {
      przelacznik_schema:   { admin: 'ok', t_manager: 'ok', t_seo: 'ok', t_nikt: 'blad' },
      przelacznik_darkmode: { admin: 'ok', t_manager: 'blad', t_seo: 'blad', t_nikt: 'blad' },
      seo_wpis:             { admin: 'ok', t_manager: 'ok', t_seo: 'ok', t_nikt: 'blad' },
      przekierowanie_301:   { admin: 'ok', t_manager: 'ok', t_seo: 'blad', t_nikt: 'blad' },
      kopia_stan:           { admin: 'ok', t_manager: 'ok', t_seo: 'blad', t_nikt: 'blad' },
      kopia_lista:          { admin: 'ok', t_manager: 'blad', t_seo: 'blad', t_nikt: 'blad' },
      kopia_usun:           { t_manager: 'blad', t_seo: 'blad', t_nikt: 'blad' },
      '2fa_reset_admina':   { t_manager: 'blad', t_seo: 'blad', t_nikt: 'blad' },
      '2fa_reset_konta':    { t_manager: 'ok', t_seo: 'blad' },
      options_php_schema:   { admin: 'manage_options', t_manager: 'evk_access_seo', t_seo: 'evk_access_seo', t_nikt: 'manage_options' },
      options_php_og:       { admin: 'manage_options', t_manager: 'evk_access_seo', t_seo: 'evk_access_seo', t_nikt: 'manage_options' },
    };
    for (const [akcja, konta] of Object.entries(oczek)) {
      const jest = Object.fromEntries(Object.keys(konta).map((k) => [k, (m[k] || {})[akcja]]));
      t.check(akcja + ': ' + JSON.stringify(konta), JSON.stringify(jest) === JSON.stringify(konta), JSON.stringify(jest));
    }
    t.check('kontrola: administrator wyłącza 2FA innemu administratorowi (ta sama akcja działa)',
      (m.admin || {})['2fa_reset_admina_przez_admina'] === 'ok', JSON.stringify(m.admin));
    t.check('po macierzy 2FA administratora nietknięte przez Managera, konto redaktora wyłączone',
      mac['2fa_po'] && mac['2fa_po'].red === false, JSON.stringify(mac['2fa_po']));

    serwer = await serwerWp.start(prep.wp || sonda('wp').wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const panel = serwer.baza + '/wp-admin/options-general.php?page=evoke-one';

    /** Nowa karta zalogowana jako konto; błędy JS strony zbierane osobno. */
    const jako = async (login, haslo) => {
      const kontekst = await browser.newContext({ viewport: { width: 1280, height: 1000 } });
      const p = await kontekst.newPage();
      const bledy = [];
      /* Bez skryptów rdzenia z /wp-admin/: konto bez Kokpitu ląduje po zalogowaniu na profile.php,
         a tamten user-profile.js przy szybkim wyjściu rzuca „reading 'serialize'” (CLAUDE.md). */
      p.on('pageerror', (e) => { if (!/\/wp-admin\//.test(String(e.stack || ''))) bledy.push(e.message); });
      p.on('dialog', (d) => d.accept());
      await serwerWp.zaloguj(p, serwer.baza, login, haslo);
      return { p, bledy, kontekst };
    };

    // ── Manager ─────────────────────────────────────────────────────────
    t.section('Manager: panel przycięty do dostępów');
    const man = await jako('t_manager', 'test-haslo');
    const p = man.p;
    let r = await p.goto(panel);
    t.check('Ustawienia → Evoke ONE otwiera się (200)', r.status() === 200, String(r.status()));
    t.check('pozycja „Evoke ONE” w menu Ustawień', await p.$('#menu-settings a[href*="page=evoke-one"]') !== null);
    const zm = await zakladki(p);
    t.check('pasek boczny: Strona, Logowanie, Narzędzia, Kopie — bez Pulpitu, Frontendu, Bezpieczeństwa i Systemu',
      JSON.stringify(zm) === JSON.stringify(['backup', 'logowanie', 'narzedzia', 'strona']), JSON.stringify(zm));
    const paleta = await p.$$eval('[data-evo-search-item]', (a) => a.map((e) => { const u = new URL(e.href).searchParams; return u.get('tab') + (u.get('sub') ? '/' + u.get('sub') : ''); }).sort());
    t.check('wyszukiwarka ustawień zna tylko ekrany z dostępów', JSON.stringify(paleta) === JSON.stringify(['backup', 'logowanie', 'logowanie/2fa',
      'narzedzia', 'narzedzia/logs404', 'narzedzia/redirect', 'strona', 'strona/meta', 'strona/og', 'strona/schema', 'strona/sitemap']), JSON.stringify(paleta));
    t.check('wejście bez zakładki nie pokazuje komunikatu o braku dostępu', !(await p.content()).includes(BRAK_DOSTEPU));

    t.section('Manager: reszta panelu zamknięta także po adresie (403)');
    for (const adres of ['&tab=bezpieczenstwo', '&tab=admin_panel', '&tab=wydajnosc&sub=darkmode', '&tab=narzedzia&sub=smtp',
      '&tab=logowanie&sub=adres', '&tab=logowanie&sub=limit', '&tab=statystyki', '&tab=dashboard&sub=x']) {
      r = await p.goto(panel + adres);
      const tresc = await p.content();
      t.check(adres + ' → 403 „' + BRAK_DOSTEPU + '”', r.status() === 403 && tresc.includes(BRAK_DOSTEPU), r.status() + ' ' + tresc.slice(0, 120).replace(/\s+/g, ' '));
    }

    t.section('Manager: w dozwolonych zakładkach tylko ekrany z dostępów');
    await p.goto(panel + '&tab=strona');
    t.check('Strona: Meta SEO, mapa strony, Schema, OpenGraph', JSON.stringify(await ekrany(p, 'strona')) === JSON.stringify(['meta', 'og', 'schema', 'sitemap']),
      JSON.stringify(await ekrany(p, 'strona')));
    await p.goto(panel + '&tab=narzedzia');
    t.check('Narzędzia: przekierowania i logi 404', JSON.stringify(await ekrany(p, 'narzedzia')) === JSON.stringify(['logs404', 'redirect']),
      JSON.stringify(await ekrany(p, 'narzedzia')));
    for (const adres of ['&tab=strona&sub=meta', '&tab=strona&sub=sitemap', '&tab=strona&sub=og', '&tab=narzedzia&sub=logs404']) {
      r = await p.goto(panel + adres);
      t.check(adres + ' otwiera się', r.status() === 200 && !(await p.content()).includes(BRAK_DOSTEPU), String(r.status()));
    }

    t.section('Manager: Schema zapisuje się przez AJAX panelu, nie zapasowym wysłaniem');
    await p.goto(panel + '&tab=strona&sub=schema');
    await p.fill('#evo-f-evk_schema-telephone', '+48 111 222 333');
    const [odp] = await Promise.all([
      p.waitForResponse((x) => x.url().includes('admin-ajax.php') && (x.request().postData() || '').includes('evk_settings_save'), { timeout: 15000 }),
      p.click('form[action$="options.php"] [type=submit]'),
    ]);
    const jOdp = await odp.json().catch(() => ({}));
    t.check('evk_settings_save odpowiada success (bez „forbidden” i powrotu do options.php)', jOdp.success === true, JSON.stringify(jOdp).slice(0, 150));
    await p.waitForTimeout(500);
    t.check('telefon zapisany w evk_schema', (sonda('stan').schema || {}).telephone === '+48 111 222 333', JSON.stringify((sonda('stan').schema || {}).telephone));

    t.section('Manager: przekierowanie dodane z ekranu');
    await p.goto(panel + '&tab=narzedzia&sub=redirect');
    await p.fill('#evk-301-from', '/evk-t-manager-ui');
    await p.fill('#evk-301-to', '/');
    await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.click('#evk-301-add')]);
    t.check('reguła /evk-t-manager-ui zapisana', (sonda('stan').przekierowania || []).includes('/evk-t-manager-ui'), JSON.stringify(sonda('stan').przekierowania));

    t.section('Manager: kopie — tylko „Utwórz kopię teraz”');
    await p.goto(panel + '&tab=backup');
    t.check('przycisk kopii teraz jest', await p.$('[data-evk-backup-start]') !== null);
    const kopie = await p.evaluate(() => ({
      lista: document.body.innerText.includes('Kopie na serwerze'),
      dysk: !!document.querySelector('#evk-gdrive'),
      ustawienia: !!document.querySelector('form[action$="options.php"]'),
    }));
    t.check('bez listy kopii, Dysku Google i ustawień', !kopie.lista && !kopie.dysk && !kopie.ustawienia, JSON.stringify(kopie));

    t.section('Manager: Logowanie — lista kont i „Wyłącz 2FA”, bez ustawień');
    await p.goto(panel + '&tab=logowanie');
    const prz = await p.evaluate(() => ({
      ekrany: [...document.querySelectorAll('.evo-przeglad-link')].map((a) => new URL(a.href).searchParams.get('sub')),
      przelaczniki: document.querySelectorAll('.evo-przeglad input[data-option]').length,
      stan: [...document.querySelectorAll('.evo-przeglad-akcja')].map((e) => e.innerText.trim()),
    }));
    t.check('przegląd Logowania: sam ekran 2FA, stan modułu bez przełącznika (włącza administrator)',
      JSON.stringify(prz.ekrany) === '["2fa"]' && prz.przelaczniki === 0 && JSON.stringify(prz.stan) === '["Włączone"]', JSON.stringify(prz));
    r = await p.goto(panel + '&tab=logowanie&sub=2fa');
    t.check('ekran 2FA z listą kont', r.status() === 200 && await p.$('#evk-2fa-konta') !== null,
      r.status() + ' ' + (await p.$$eval('.evo-main-content h1, .evo-main-content h3', (h) => h.map((e) => e.innerText).join(' | '))).slice(0, 200));
    const l2 = await p.evaluate((id) => ({
      formularz: !!document.querySelector('#evk-2fa-form'),
      przelacznik: !!document.querySelector('.evo-toggle input[data-option="evk_2fa"], input[data-option="evk_2fa"]'),
      reset: [...document.querySelectorAll('.evk-2fa-reset')].map((b) => Number(b.dataset.user)),
      admin2: id,
    }), prep.id.t_admin2);
    t.check('bez formularza ustawień i przełącznika modułu', !l2.formularz && !l2.przelacznik, JSON.stringify(l2));
    t.check('„Wyłącz 2FA” tylko przy redaktorze, nie przy administratorze', JSON.stringify(l2.reset) === JSON.stringify([prep.id.t_red2fa]), JSON.stringify(l2));
    await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.click('.evk-2fa-reset[data-user="' + prep.id.t_red2fa + '"]')]);
    const st2 = sonda('stan')['2fa'] || {};
    t.check('„Wyłącz 2FA” wyłączył redaktorowi, administrator nietknięty', st2.red === false && st2.admin2 === true, JSON.stringify(st2));

    t.section('Manager: „Panel Evoke ONE” w menu Evoke na stronie');
    await p.goto(serwer.baza + '/');
    t.check('odnośnik do panelu w pasku', await p.$('#wp-admin-bar-evk-panel a[href*="page=evoke-one"]') !== null);
    t.check('Manager: bez błędów JavaScriptu', man.bledy.length === 0, man.bledy.join(' | ').slice(0, 200));
    await man.kontekst.close();

    // ── Rola z samym SEO ────────────────────────────────────────────────
    t.section('rola z samym SEO: tylko Strona');
    const seo = await jako('t_seo', 'test-haslo');
    await seo.p.goto(panel);
    t.check('pasek boczny: sama Strona', JSON.stringify(await zakladki(seo.p)) === JSON.stringify(['strona']), JSON.stringify(await zakladki(seo.p)));
    r = await seo.p.goto(panel + '&tab=backup');
    t.check('Kopie → 403', r.status() === 403, String(r.status()));
    r = await seo.p.goto(panel + '&tab=narzedzia&sub=redirect');
    t.check('Przekierowania → 403', r.status() === 403, String(r.status()));
    await seo.kontekst.close();

    // ── Bez dostępów ────────────────────────────────────────────────────
    t.section('subskrybent: panelu nie ma');
    const nikt = await jako('t_nikt', 'test-haslo');
    r = await nikt.p.goto(panel);
    t.check('WordPress odmawia (403), panelu nie ma', r.status() === 403 && await nikt.p.$('.evo-control-center') === null, String(r.status()));
    await nikt.p.goto(serwer.baza + '/');
    t.check('bez „Panel Evoke ONE” w pasku', await nikt.p.$('#wp-admin-bar-evk-panel') === null);
    await nikt.kontekst.close();

    // ── Administrator bez zmian ─────────────────────────────────────────
    t.section('administrator: pełny panel');
    const adm = await jako('admin', 'admin');
    await adm.p.goto(panel);
    const za = await zakladki(adm.p);
    t.check('pasek boczny z Pulpitem, Bezpieczeństwem i Systemem', ['dashboard', 'bezpieczenstwo', 'admin_panel', 'wydajnosc'].every((k) => za.includes(k)), JSON.stringify(za));
    await adm.p.goto(panel + '&tab=backup');
    t.check('kopie: lista i ustawienia', await adm.p.$('form[action$="options.php"]') !== null && (await adm.p.content()).includes('Kopie na serwerze'));
    await adm.p.goto(panel + '&tab=logowanie&sub=2fa');
    t.check('2FA: formularz ustawień', await adm.p.$('#evk-2fa-form') !== null);
    t.check('administrator: bez błędów JavaScriptu', adm.bledy.length === 0, adm.bledy.join(' | ').slice(0, 200));
    await adm.kontekst.close();
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
