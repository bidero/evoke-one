/**
 * Krok kodu 2FA w PRAWDZIWYM builderze Bricksa (1.289.0) — piąty testowy
 * WordPress z licencją. Krok pojawia się na stronie dopiero po haśle, więc
 * do stylowania jest podgląd na kanwie (kontrolka „Podgląd kroku kodu
 * w builderze”). Test sprawdza, że podgląd stoi na kanwie z wyglądem
 * z kontrolek, że grupa jest w panelu formularza i że zmiana tekstu oraz
 * typografii w stanie buildera od razu zmienia podgląd (Bricks przerysowuje
 * element — podgląd musi wrócić sam).
 */

const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('logowanie-2fa-bricks.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const SEKRET = 'KRUGS4ZANFZSAYJAORSXG5BAONSWG4TF';

/** Stan powłoki buildera (Vue) — jak w bricks-builder. */
const stan = (p, f, arg) => p.evaluate(([fs, a]) => {
  const st = document.querySelector('.brx-body.main').__vue_app__.config.globalProperties.$_state;
  return new Function('st', 'a', 'return (' + fs + ')(st, a);')(st, a);
}, [String(f), arg === undefined ? null : arg]);

module.exports = async function (t) {
  t.section('środowisko: piąty WordPress, Bricks z licencją');
  const wp = sonda('wp');
  t.check('piąty testowy WordPress z motywem Bricks (tools/testowy-wp.sh, ../bricks-motyw)', !wp.brak && !!wp.wp && !!wp.bricks, wp.brak || J(wp));
  if (wp.brak || !wp.wp || !wp.bricks) return;
  let serwer = null;
  let browser = null;
  try {
    sonda('przygotuj', SEKRET);
    const b = sonda('builder');
    t.check('licencja Bricksa aktywna (EVK_BRICKS_KLUCZ, tools/testowy-wp.sh); strona z podglądem kroku kodu', b.licencja === true && b.podglad === true && !!b.strona, J(b));
    if (b.licencja !== true) return;
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1500, height: 900 } });
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(p, serwer.baza);
    await p.goto(serwer.baza + '/?page_id=' + b.strona + '&bricks=run', { timeout: 60000 });
    await p.waitForSelector('.brx-body.main', { timeout: 60000 });
    const kanwa = async () => {
      for (const f of p.frames()) { if (f !== p.mainFrame() && await f.$('form.brxe-form').catch(() => null)) return f; }
      return null;
    };
    let k = null;
    for (let i = 0; i < 60 && !k; i++) { k = await kanwa(); if (!k) await p.waitForTimeout(500); }
    t.check('kanwa buildera z formularzem', !!k);
    if (!k) return;
    await k.waitForSelector('form.brxe-form .evk-2fa-nawig', { timeout: 15000 }).catch(() => {});
    const odczyt = () => k.evaluate(() => {
      const f = document.querySelector('form.brxe-form'), l = [...f.querySelectorAll('.evk-2fa-link')], bl = f.querySelector('.evk-2fa-blad');
      const pole = f.querySelector('input[name="evk_2fa_kod"]');
      return { linki: l.map((x) => x.textContent), kolor: l[0] && getComputedStyle(l[0]).color, blad: bl && bl.textContent, bladKolor: bl && getComputedStyle(bl).color,
        pole: !!pole && pole.offsetParent !== null, login: (f.querySelector('input[name="form-field-lgn001"]') || {}).offsetParent !== null, fokus: document.activeElement === pole };
    });
    const o1 = await odczyt();
    t.check('podgląd na kanwie: krok kodu z tekstami i wyglądem z kontrolek (linki #0a7d3b, komunikat #123456 z {proby} = 4), pola logowania schowane, bez kradzieży fokusu',
      o1.pole && !o1.login && J(o1.linki) === J(['Zapasowy', 'Cofnij']) && o1.kolor === 'rgb(10, 125, 59)' && o1.blad === 'Zły kod, prób: 4' && o1.bladKolor === 'rgb(18, 52, 86)' && !o1.fokus, J(o1));

    await stan(p, (st) => { st.activeId = 'b2f001'; st.activePanel = 'element'; });
    await p.waitForTimeout(800);
    const grupy = await p.evaluate(() => [...document.querySelectorAll('#bricks-panel .control-group-title, #bricks-panel-element .control-group-title, #bricks-panel [class*="group-title"]')].map((e) => e.textContent.trim()));
    t.check('panel formularza: grupa „Logowanie dwuetapowe (Evoke)”', grupy.some((g) => /Logowanie dwuetapowe \(Evoke\)/.test(g)), J(grupy));

    /* Jak użytkownik: pole kontrolki w panelu (zmiana stanu z boku nie przerysowuje elementu — sprawdzone). */
    if (!(await p.isVisible('[data-controlkey="tfaWroc"] input'))) await p.click('[data-control-group="evk2fa"] .control-group-title');
    await p.fill('[data-controlkey="tfaWroc"] input', 'Wstecz');
    await k.waitForFunction(() => [...document.querySelectorAll('form.brxe-form .evk-2fa-link')].some((x) => x.textContent === 'Wstecz'), null, { timeout: 15000 }).catch(() => {});
    const o2 = await odczyt();
    const fokusPanel = await p.evaluate(() => !!document.activeElement && !!document.activeElement.closest('[data-controlkey="tfaWroc"]'));
    t.check('zmiana tekstu w builderze: element przerysowany, podgląd wraca sam z nowym tekstem, fokus zostaje w polu panelu (podgląd go nie zabiera)',
      J(o2.linki) === J(['Zapasowy', 'Wstecz']) && o2.pole && !o2.login && fokusPanel, J({ o2, fokusPanel }));

    await stan(p, (st) => { const el = st.content.find((e) => e.id === 'b2f001'); el.settings.evk2faTypografia = { color: { hex: '#aa0000' }, 'font-size': '13px' }; });
    await k.waitForFunction(() => { const l = document.querySelector('form.brxe-form .evk-2fa-link'); return l && getComputedStyle(l).color === 'rgb(170, 0, 0)'; }, null, { timeout: 15000 }).catch(() => {});
    const o3 = await odczyt();
    t.check('zmiana typografii w builderze: kolor linków na podglądzie od razu', o3.kolor === 'rgb(170, 0, 0)', J(o3));
    const gosc = await (await browser.newContext()).newPage();
    await gosc.goto(serwer.baza + '/?page_id=' + b.strona);
    await gosc.waitForTimeout(500);
    const naStronie = await gosc.evaluate(() => ({ krok: !!document.querySelector('.evk-2fa-nawig'), login: (document.querySelector('input[name="form-field-lgn001"]') || {}).offsetParent !== null,
      atrybut: (document.querySelector('form.brxe-form') || { getAttribute: () => '' }).getAttribute('data-evk2fa') || '' }));
    t.check('na stronie (gość) podglądu nie ma: zwykły formularz logowania, bez „podglad” w ustawieniach', !naStronie.krok && naStronie.login && !/podglad/.test(naStronie.atrybut), J(naStronie));
    t.check('bez błędów JS w builderze', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
