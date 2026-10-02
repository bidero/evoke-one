/**
 * Kopiowanie animacji Animatora z elementu na element (1.275.0) — PRAWDZIWY
 * builder Bricksa (piąty testowy WordPress z licencją, jak bricks-builder),
 * sonda tests/php/bricks-builder-animator.php, logika w
 * assets/admin/anim-lustro.js.
 *
 * Pomiar przed kodem (Bricks 2.4.2): „Copy → All styles” nie bierze listy
 * Animatora; ikonka „Attributes” przy „Copy” jest tylko przy niepustych
 * Atrybutach; „Paste → Attributes” zastępuje Atrybuty celu i idzie przez
 * schowek systemowy (stąd uprawnienia schowka w kontekście przeglądarki).
 *
 * Droga z testu: prawy klik na A (lista) → „Copy → Attributes” → prawy klik na
 * B → „Paste → Attributes” → B ma listę A; C (ręczny wpis sprzed lustra) bez
 * zmian; zapis przyciskiem „Save”; front: B animuje się jak A, z odpowiedzią dla zasłony.
 */

const http = require('http');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('bricks-builder-animator.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const pobierz = (u) => new Promise((ok) => http.get(u, (r) => { let d = ''; r.on('data', (c) => { d += c; }); r.on('end', () => ok({ s: r.statusCode, d })); })
  .on('error', (e) => ok({ s: 0, d: String(e) })));
/** Atrybuty data-evk-anim* nagłówka o tekście `tekst` z HTML-a frontu. */
function atrybutyFrontu(html, tekst) {
  const m = html.match(new RegExp('<h2[^>]*>\\s*' + tekst + '\\s*</h2>'));
  if (!m) return null;
  const o = {};
  m[0].replace(/(data-evk-anim[a-z-]*|data-x)="([^"]*)"/g, (_, k, v) => { o[k] = v.replace(/&quot;/g, '"'); return ''; });
  return o;
}

module.exports = async function (t) {
  t.section('środowisko i filtr PHP: lustro czytane jak lista');
  const wp = sonda('wp');
  t.check('piąty WordPress z Bricksem i aktywną licencją (tools/testowy-wp.sh, EVK_BRICKS_KLUCZ)',
    !wp.brak && wp.motyw === 'Bricks' && wp.licencja === true, wp.brak || J(wp));
  if (wp.brak || wp.licencja !== true) return;
  sonda('sprzataj');
  let serwer = null;
  let browser = null;
  try {
    const u = sonda('ustaw');
    t.check('sonda: Animator z „wjazd”, strona z nagłówkami A, B, C', u.gotowe === true, J(u));
    if (u.gotowe !== true) return;

    const f = sonda('filtr').przypadki || {};
    const zLisy = { 'data-evk-anim': '{"animation":"wjazd","delay":0.2}', 'data-evk-anim-zaslona': '1' };
    console.log('      filtr: ' + J(f));
    t.check('lustro w Atrybutach (z listą i bez) daje to samo co lista — konfiguracja i odpowiedź dla zasłony',
      J(f.lista) === J(zLisy) && J(f['lista+lustro']) === J(zLisy) && J(f['samo-lustro']) === J(zLisy), J(f));
    t.check('wpisy ręczne bez zmian: goła nazwa, pojedynczy obiekt, tablica bez „animation” — serwer nic nie dokłada',
      J(f['reczna-nazwa']) === '[]' && J(f['reczny-obiekt']) === '[]' && J(f['tablica-bez-animation']) === '[]', J(f));

    t.section('builder: prawy klik → Copy → Attributes, Paste → Attributes');
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const ctx = await browser.newContext({ viewport: { width: 1500, height: 900 }, permissions: ['clipboard-read', 'clipboard-write'] });
    const p = await ctx.newPage();
    const bledy = [];
    p.on('pageerror', (e) => { if (/evoke-one/.test(String(e.stack || ''))) bledy.push(e.message); });
    await serwerWp.zaloguj(p, serwer.baza);
    await p.goto(serwer.baza + '/?page_id=' + u.strona + '&bricks=run', { timeout: 60000 });
    await p.waitForSelector('.brx-body.main', { timeout: 60000 });
    let kanwa = null;
    for (let i = 0; i < 60 && !kanwa; i++) {
      kanwa = p.frames().find((x) => /brickspreview/.test(x.url())) || null;
      if (!kanwa) await p.waitForTimeout(250);
    }
    await kanwa.waitForFunction(() => !!window.__evkAnimLustro, null, { timeout: 30000 }).catch(() => {});
    await kanwa.waitForSelector('#brxe-bahb01', { timeout: 30000 }).catch(() => {});
    const ust = (id) => p.evaluate((i) => {
      const st = document.querySelector('.brx-body.main').__vue_app__.config.globalProperties.$_state;
      return JSON.parse(JSON.stringify(st.content.find((x) => x.id === i).settings));
    }, id);
    const akcja = (wiersz, nazwa) => p.evaluate(([w, n]) => {
      const a = Array.from(document.querySelectorAll('#bricks-builder-context-menu ' + w + ' .action')).find((x) => x.getAttribute('data-balloon') === n);
      if (!a) return false;
      a.click();
      return true;
    }, [wiersz, nazwa]);
    const zamknij = async () => { await p.keyboard.press('Escape'); await p.waitForTimeout(300); };

    const przedA = await ust('baha01');
    t.check('przy otwarciu strony builder nie rusza elementów — A bez Atrybutów (bez „niezapisanych zmian”)', !przedA._attributes, J(przedA));
    await (await kanwa.$('#brxe-baha01')).click({ button: 'right' });
    await p.waitForTimeout(400);
    const skopiowane = await akcja('li:first-child', 'Attributes');
    await zamknij();
    const a = await ust('baha01');
    t.check('prawy klik na A: lustro w Atrybutach i ikonka „Attributes” przy „Copy”',
      skopiowane && J(a._attributes.map((r) => [r.name, r.value])) === J([['data-evk-anim', '[{"animation":"wjazd","delay":"0.2"}]']]), J([skopiowane, a._attributes]));

    await (await kanwa.$('#brxe-bahb01')).click({ button: 'right' });
    await p.waitForTimeout(400);
    const wklejone = await akcja('li.sep', 'Attributes');
    await zamknij();
    await p.waitForTimeout(700);
    const b = await ust('bahb01');
    t.check('Paste → Attributes na B: lista Animatora B = lista A (nowe id wierszy), Atrybuty B zastąpione jak w Bricksie',
      wklejone && J((b.evkAnimList || []).map((r) => [r.animation, r.delay, /^[a-z]{6}$/.test(r.id)])) === J([['wjazd', '0.2', true]])
      && J(b._attributes.map((r) => r.name)) === J(['data-evk-anim']), J([wklejone, b]));

    await (await kanwa.$('#brxe-bahc01')).click({ button: 'right' });
    await p.waitForTimeout(700);
    await zamknij();
    const c = await ust('bahc01');
    t.check('C z ręcznym wpisem „wjazd” sprzed lustra: wpis i lista bez zmian', J(c._attributes) === J([{ id: 'bat002', name: 'data-evk-anim', value: 'wjazd' }])
      && J(c.evkAnimList) === J([{ id: 'bar002', animation: 'wjazd', delay: '0.5' }]), J(c));

    t.section('zapis w Bricksie i front');
    /* Przycisk „Save” paska (`.save[data-balloon="Save"]`); Ctrl+S z Playwrighta nie dochodził do Bricksa. */
    await p.click('.save[data-balloon="Save"]');
    let zapis = null;
    for (let i = 0; i < 40; i++) {
      await p.waitForTimeout(250);
      zapis = sonda('tresc').el || {};
      if (zapis.bahb01 && zapis.bahb01.evkAnimList) break;
    }
    t.check('„Save”: lista i lustro B w bazie', !!zapis.bahb01 && J((zapis.bahb01.evkAnimList || []).map((r) => r.animation)) === J(['wjazd'])
      && (zapis.baha01._attributes || []).length === 1, J(zapis));
    const front = await pobierz(serwer.baza + '/?page_id=' + u.strona);
    const fa = atrybutyFrontu(front.d, 'Nagłówek A'), fb = atrybutyFrontu(front.d, 'Nagłówek B'), fc = atrybutyFrontu(front.d, 'Nagłówek C');
    console.log('      front: ' + J({ A: fa, B: fb, C: fc }));
    t.check('front: B animuje się dokładnie jak A — konfiguracja z listy (obiekt, liczby) i odpowiedź dla zasłony',
      front.s === 200 && J(fa) === J({ 'data-evk-anim': '{"animation":"wjazd","delay":0.2}', 'data-evk-anim-zaslona': '1' }) && J(fb) === J(fa), J({ s: front.s, fa, fb }));
    t.check('front: C — ręczny wpis jak dotąd (sama nazwa, bez odpowiedzi serwera)', J(fc) === J({ 'data-evk-anim': 'wjazd' }), J(fc));
    t.check('bez błędów JS Evoke w builderze', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
