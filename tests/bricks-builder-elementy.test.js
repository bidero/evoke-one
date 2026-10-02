/**
 * Elementy Evoke dodawane w PRAWDZIWYM builderze (1.276.0) — piąty testowy
 * WordPress z licencją, sonda tests/php/bricks-builder-elementy.php.
 *
 * Przegląd buildera (1.275.0) pokazał dwa elementy niewidoczne po dodaniu
 * z panelu: Scroll Reading (pusty kontener) i Offcanvas Menu (zamknięty
 * w builderze). Decyzja zgłaszającego: Scroll Reading dostaje treść na start,
 * nowo dodany Offcanvas jest otwarty. Stary Offcanvas (bez znacznika) zostaje
 * zamknięty, a „Zamknij w builderze” w nowym działa w obie strony.
 *
 * Do tego wszystkie dziesięć elementów Evoke: dodanie z panelu bez błędów JS.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('bricks-builder-elementy.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const ELEMENTY = ['Marquee', 'Horizontal Scroll', 'Grain', 'Scroll Reading', 'Circular Title', 'Circular Menu', 'Offcanvas Menu', 'Burger', 'Stacking Cards', 'Wave Background'];

module.exports = async function (t) {
  t.section('środowisko: piąty WordPress, Bricks z licencją');
  const wp = sonda('wp');
  t.check('piąty WordPress z Bricksem i aktywną licencją (tools/testowy-wp.sh, EVK_BRICKS_KLUCZ)',
    !wp.brak && wp.motyw === 'Bricks' && wp.licencja === true, wp.brak || J(wp));
  if (wp.brak || wp.licencja !== true) return;
  sonda('sprzataj');
  let serwer = null;
  let browser = null;
  try {
    const u = sonda('ustaw');
    t.check('sonda: elementy Evoke włączone, strona ze starym Offcanvasem', u.gotowe === true, J(u));
    if (u.gotowe !== true) return;
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1500, height: 950 } });
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(p, serwer.baza);
    await p.goto(serwer.baza + '/?page_id=' + u.strona + '&bricks=run', { timeout: 60000 });
    await p.waitForSelector('.brx-body.main', { timeout: 60000 });
    let kanwa = null;
    for (let i = 0; i < 60 && !kanwa; i++) {
      kanwa = p.frames().find((x) => /brickspreview/.test(x.url())) || null;
      if (!kanwa) await p.waitForTimeout(250);
    }
    await kanwa.waitForSelector('[data-id="beoc01"]', { state: 'attached', timeout: 30000 }).catch(() => {});
    await p.waitForTimeout(1000);
    const st = (f, a) => p.evaluate(([fs, x]) => new Function('st', 'a', 'return (' + fs + ')(st, a)')(
      document.querySelector('.brx-body.main').__vue_app__.config.globalProperties.$_state, x), [String(f), a === undefined ? null : a]);
    const otwarte = (id) => kanwa.evaluate((i) => { const e = document.querySelector('[data-id="' + i + '"]'); return e ? e.getAttribute('data-open-builder') : null; }, id);

    t.check('stary Offcanvas (bez znacznika) zostaje zamknięty w builderze', (await otwarte('beoc01')) === '0', String(await otwarte('beoc01')));

    t.section('dodanie każdego elementu Evoke z panelu');
    const dodane = {};
    for (const n of ELEMENTY) {
      await st((s) => { s.activeId = 'bes001'; s.activePanel = 'elements'; });
      await p.waitForTimeout(500);
      const przed = await st((s) => s.content.map((e) => e.id));
      const ok = await p.evaluate((nazwa) => {
        const el = Array.from(document.querySelectorAll('#bricks-panel-elements li')).find((x) => x.textContent.trim() === nazwa);
        if (!el) return false;
        el.click();
        return true;
      }, n);
      await p.waitForTimeout(1500);
      const nowe = await st((s, a) => s.content.filter((e) => a.indexOf(e.id) === -1).map((e) => ({ id: e.id, name: e.name, settings: e.settings, dzieci: (e.children || []).length })), przed);
      dodane[n] = { ok, korzen: nowe[0] || null, wszystkie: nowe };
    }
    const nieDodane = ELEMENTY.filter((n) => !dodane[n].ok || !dodane[n].korzen);
    t.check('wszystkie dziesięć elementów dodaje się z panelu', nieDodane.length === 0, nieDodane.join(', ') || 'wszystkie');
    t.check('bez błędów JS przy dodawaniu', bledy.length === 0, bledy.slice(0, 3).join(' | '));

    t.section('Scroll Reading: treść na start');
    const sr = dodane['Scroll Reading'];
    const srRender = sr.korzen ? await kanwa.evaluate((id) => { const e = document.querySelector('[data-id="' + id + '"]'); const r = e.getBoundingClientRect();
      return { w: Math.round(r.width), h: Math.round(r.height), tekst: (e.innerText || '').trim() }; }, sr.korzen.id) : null;
    console.log('      Scroll Reading: ' + J(srRender) + ' dzieci: ' + J(sr.wszystkie.map((e) => e.name)));
    t.check('po dodaniu: nagłówek z tekstem w środku, element widoczny w kanwie',
      J(sr.wszystkie.map((e) => e.name)) === J(['evk-scroll-reading', 'heading']) && !!srRender && srRender.h > 20 && /przewijasz/.test(srRender.tekst), J(srRender));

    t.section('Offcanvas Menu: nowo dodany otwarty w builderze');
    const oc = dodane['Offcanvas Menu'].korzen || { settings: {} };
    console.log('      Offcanvas: ' + J(oc.settings && { openInBuilder_nowy: oc.settings.openInBuilder_nowy, closedInBuilder: oc.settings.closedInBuilder, openInBuilder: oc.settings.openInBuilder }));
    t.check('znacznik zapisany przy wstawieniu, „Zamknij w builderze” odznaczone, menu otwarte',
      oc.settings.openInBuilder_nowy === 2 && !oc.settings.closedInBuilder && (await otwarte(oc.id)) === '1', J([oc.settings.openInBuilder_nowy, oc.settings.closedInBuilder, await otwarte(oc.id)]));
    /* Pole schowane warunkiem zostawia pustą obudowę `[data-controlkey]` bez `.control`. */
    const widoczneW = () => p.evaluate(() => Array.from(document.querySelectorAll('#bricks-panel [data-controlkey]'))
      .filter((x) => x.querySelector('.control')).map((x) => x.getAttribute('data-controlkey')).filter((k) => /InBuilder/.test(k)));
    const panel = async () => {
      await st((s, a) => { s.activeId = a; s.activePanel = 'element'; s.activePanelTab = 'content'; }, oc.id);
      await p.waitForTimeout(900);
      return widoczneW();
    };
    const widoczne = await panel();
    t.check('panel nowego: tylko „Zamknij w builderze” (bez dawnego „Trzymaj otwarte” i bez znacznika)', J(widoczne) === J(['closedInBuilder']), J(widoczne));
    /* Klik w panelu jak użytkownik — zmiana wpisana prosto w stan nie odpala renderu elementu. */
    const klik = async () => {
      await p.click('[data-controlkey="closedInBuilder"] .control label, [data-controlkey="closedInBuilder"] .control input');
      await p.waitForTimeout(2000);
      return { atr: await otwarte(oc.id), wart: await st((s, a) => s.content.find((e) => e.id === a).settings.closedInBuilder ?? null, oc.id) };
    };
    const zamk = await klik();
    const znowu = await klik();
    console.log('      klik: ' + J([zamk, znowu]));
    t.check('„Zamknij w builderze” zaznaczone → zamknięte; odznaczone (Bricks usuwa klucz) → znów otwarte', zamk.atr === '0' && zamk.wart === true && znowu.atr === '1' && !znowu.wart, J([zamk, znowu]));
    await st((s) => { s.activeId = 'beoc01'; s.activePanel = 'element'; s.activePanelTab = 'content'; });
    await p.waitForTimeout(900);
    const stary = await widoczneW();
    t.check('panel starego: dawne „Trzymaj otwarte w builderze”', J(stary) === J(['openInBuilder']), J(stary));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
