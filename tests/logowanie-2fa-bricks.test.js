/**
 * 2FA w formularzu logowania Bricksa (1.287.0) — piąty testowy WordPress
 * z prawdziwym motywem Bricks 2.4, prawdziwy skrypt formularza Bricksa
 * w Chromium. Drugi krok ma być w TYM SAMYM formularzu i wyglądać jak on:
 * test porównuje obliczone style pola kodu z polem loginu (formularz ma
 * własne tło, ramkę i odstępy pól), sprawdza, że to ten sam przycisk,
 * a potem przechodzi zły kod, „Wróć”, dobry kod i formularz z własnym
 * komunikatem błędu (Bricks podmienia nim każdy błąd logowania).
 */

const crypto = require('crypto');
const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('logowanie-2fa-bricks.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const SEKRET = 'KRUGS4ZANFZSAYJAORSXG5BAONSWG4TF';
function totp(licznik) {
  const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bity = '';
  for (const z of SEKRET) bity += a.indexOf(z).toString(2).padStart(5, '0');
  const k = Buffer.from(bity.match(/.{8}/g).map((b) => parseInt(b, 2)));
  const b = Buffer.alloc(8);
  b.writeBigUInt64BE(BigInt(licznik));
  const h = crypto.createHmac('sha1', k).update(b).digest();
  const o = h[19] & 15;
  return String((((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3]) % 1e6).padStart(6, '0');
}
const STYLE = ['font-size', 'font-family', 'line-height', 'color', 'background-color', 'padding-top', 'padding-left', 'border-top-width', 'border-top-style', 'border-top-color',
  'border-top-left-radius', 'height', 'width', 'box-sizing'];

module.exports = async function (t) {
  t.section('środowisko');
  const wp = sonda('wp');
  t.check('piąty testowy WordPress z motywem Bricks (tools/testowy-wp.sh, ../bricks-motyw)', !wp.brak && !!wp.wp && !!wp.bricks, wp.brak || J(wp));
  if (wp.brak || !wp.wp || !wp.bricks) return;
  let serwer = null;
  let browser = null;
  try {
    const prz = sonda('przygotuj', SEKRET);
    const [S1, S2] = prz.strony || [];
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await (await browser.newContext()).newPage();
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    await p.goto(serwer.baza + '/?page_id=' + S1);
    const F = 'form.brxe-form';
    const styl = (sel) => p.evaluate(([s, lista]) => { const e = document.querySelector(s); if (!e) return null; const c = getComputedStyle(e); const o = {}; lista.forEach((k) => { o[k] = c.getPropertyValue(k); }); return o; }, [sel, STYLE]);
    const wzor = await styl(F + ' input[name="form-field-lgn001"]');
    const klasy = await p.evaluate((f) => document.querySelector(f + ' input[name="form-field-lgn001"]').className, F);
    const etykietaKlasy = await p.evaluate((f) => { const l = document.querySelector(f + ' input[name="form-field-lgn001"]').closest('.form-group').querySelector('label'); return l && l.className; }, F);
    console.log('      pole loginu: ' + J(wzor));
    t.check('warunek: formularz Bricksa ma własne style pól (tło #fff3d6, ramka 2 px)', !!wzor && wzor['background-color'] === 'rgb(255, 243, 214)' && wzor['border-top-width'] === '2px', J(wzor));

    t.section('pierwszy krok: hasło → krok kodu w tym samym formularzu');
    await p.fill(F + ' input[name="form-field-lgn001"]', 'dwa_bricks');
    await p.fill(F + ' input[name="form-field-pwd001"]', 'test-haslo');
    const odp1 = p.waitForResponse((r) => /admin-ajax\.php/.test(r.url()));
    await p.click(F + ' button[type=submit]');
    const tresc1 = await (await odp1).text();
    await p.waitForSelector(F + ' input[name="evk_2fa_kod"]', { timeout: 10000 }).catch(() => {});
    const k1 = await p.evaluate((f) => {
      const form = document.querySelector(f), pole = form.querySelector('input[name="evk_2fa_kod"]');
      const widac = (e) => !!e && e.offsetParent !== null;
      return {
        pole: !!pole, widac: widac(pole), etykieta: pole && ((form.querySelector('label[for="' + pole.id + '"]') || {}).textContent || pole.getAttribute('placeholder')),
        nazwa: pole && pole.getAttribute('aria-label'), etykietaKlasy: pole && ((form.querySelector('label[for="' + pole.id + '"]') || {}).className || null),
        klasy: pole && pole.className, tryb: pole && pole.getAttribute('inputmode'), autouzupelnianie: pole && pole.getAttribute('autocomplete'),
        loginWidac: widac(form.querySelector('input[name="form-field-lgn001"]')), hasloWidac: widac(form.querySelector('input[name="form-field-pwd001"]')),
        przyciski: [...form.querySelectorAll('button[type=submit]')].filter(widac).map((b) => b.textContent.trim()),
        pamietaj: [...form.querySelectorAll('input[name="evk_2fa_pamietaj"]')].map((c) => ({ zaznaczone: c.checked, tekst: c.closest('.form-group').textContent.trim(), grupa: c.closest('.form-group').className })),
        komunikat: (form.querySelector('.message') || {}).textContent || null,
        nawig: [...form.querySelectorAll('[data-evk2fa] button[type=button]')].map((b) => b.textContent),
      };
    }, F);
    const kodStyl = await styl(F + ' input[name="evk_2fa_kod"]');
    const rozne = STYLE.filter((k) => !kodStyl || kodStyl[k] !== wzor[k]);
    console.log('      krok kodu: ' + J(k1) + ' różne style: ' + J(rozne.map((k) => k + ': ' + (kodStyl || {})[k] + ' vs ' + wzor[k])));
    const ciastka1 = await p.context().cookies();
    t.check('odpowiedź Bricksa z tokenem drugiego kroku, bez hasła; jeszcze NIE zalogowany', /"evk2fa":\{"token":"[0-9a-f]{48}"/.test(tresc1) && !tresc1.includes('test-haslo')
      && !ciastka1.some((c) => /^wordpress_logged_in_/.test(c.name)), tresc1.slice(0, 300));
    t.check('w tym samym formularzu: login i hasło schowane, WIDOCZNE pole „Kod z aplikacji” (podpowiedź jak w polach formularza, nazwa dostępna; numeryczne, one-time-code), ten sam przycisk „Zaloguj się”, bez komunikatu błędu',
      k1.pole && k1.widac && k1.etykieta === 'Kod z aplikacji' && k1.nazwa === 'Kod z aplikacji' && !k1.loginWidac && !k1.hasloWidac && J(k1.przyciski) === J(['Zaloguj się']) && k1.tryb === 'numeric'
      && k1.autouzupelnianie === 'one-time-code' && k1.komunikat === null, J(k1));
    t.check('wygląd pola kodu = pole formularza: te same klasy pola i etykiety, te same obliczone style (poza odstępem liter)',
      k1.klasy === klasy && k1.etykietaKlasy === etykietaKlasy && rozne.length === 0, J({ klasy: [k1.klasy, klasy], etykieta: [k1.etykietaKlasy, etykietaKlasy], rozne }));
    t.check('„Zapamiętaj to urządzenie na 30 dni”: klon pola zaznaczenia formularza, odznaczone; odnośniki do kodu zapasowego i powrotu',
      k1.pamietaj.length === 1 && !k1.pamietaj[0].zaznaczone && k1.pamietaj[0].tekst === 'Zapamiętaj to urządzenie na 30 dni' && /form-group/.test(k1.pamietaj[0].grupa)
      && J(k1.nawig) === J(['Nie masz telefonu? Użyj kodu zapasowego', '← Wróć']), J(k1.pamietaj));

    t.section('zły kod, „Wróć”, dobry kod');
    const licznik = Math.floor(Date.now() / 30000);
    await p.fill(F + ' input[name="evk_2fa_kod"]', totp(licznik) === '000000' ? '111111' : '000000');
    const odp2 = p.waitForResponse((r) => /admin-ajax\.php/.test(r.url()));
    await p.click(F + ' button[type=submit]');
    await odp2;
    await p.waitForFunction((f) => /Nieprawidłowy kod/.test((document.querySelector(f + ' .message') || {}).textContent || ''), F, { timeout: 5000 }).catch(() => {});
    const k2 = await p.evaluate((f) => ({ komunikat: (document.querySelector(f + ' .message') || {}).textContent, pole: !!document.querySelector(f + ' input[name="evk_2fa_kod"]'),
      wartosc: (document.querySelector(f + ' input[name="evk_2fa_kod"]') || {}).value }), F);
    t.check('zły kod: komunikat Bricksa „Nieprawidłowy kod. Zostało prób: 4.”, nadal krok kodu, pole wyczyszczone', /Nieprawidłowy kod\. Zostało prób: 4\./.test(k2.komunikat || '') && k2.pole && k2.wartosc === '', J(k2));
    await p.click(F + ' [data-evk2fa] button:has-text("Wróć")');
    const k3 = await p.evaluate((f) => ({ pole: !!document.querySelector(f + ' input[name="evk_2fa_kod"]'), login: document.querySelector(f + ' input[name="form-field-lgn001"]').offsetParent !== null,
      haslo: document.querySelector(f + ' input[name="form-field-pwd001"]').value }), F);
    t.check('„Wróć”: formularz jak przed chwilą (login widać, hasło zostało w polu, krok kodu zniknął)', !k3.pole && k3.login && k3.haslo === 'test-haslo', J(k3));
    const odp3 = p.waitForResponse((r) => /admin-ajax\.php/.test(r.url()));
    await p.click(F + ' button[type=submit]');
    await odp3;
    await p.waitForSelector(F + ' input[name="evk_2fa_kod"]', { timeout: 10000 }).catch(() => {});
    await p.fill(F + ' input[name="evk_2fa_kod"]', totp(Math.max(licznik, Math.floor(Date.now() / 30000))));
    const odp4 = p.waitForResponse((r) => /admin-ajax\.php/.test(r.url()));
    await p.click(F + ' button[type=submit]');
    const tresc4 = await (await odp4).text();
    await p.waitForTimeout(500);
    const ciastka4 = await p.context().cookies();
    t.check('dobry kod: odpowiedź logowania Bricksa „success”, ciasteczko logowania ustawione', /"type":"success"/.test(tresc4) && ciastka4.some((c) => /^wordpress_logged_in_/.test(c.name)), tresc4.slice(0, 200));
    await p.goto(serwer.baza + '/wp-admin/profile.php');
    t.check('zalogowany: Profil otwiera się bez strony logowania', /profile\.php/.test(p.url()) && !(await p.$('#loginform')), p.url());

    t.section('formularz z własnym komunikatem błędu');
    const q = await (await browser.newContext()).newPage();
    await q.goto(serwer.baza + '/?page_id=' + S2);
    await q.fill(F + ' input[name="form-field-lgn001"]', 'dwa_bricks');
    await q.fill(F + ' input[name="form-field-pwd001"]', 'zle-haslo');
    await q.click(F + ' button[type=submit]');
    await q.waitForSelector(F + ' .message', { timeout: 10000 }).catch(() => {});
    const zleHaslo = await q.evaluate((f) => ({ komunikat: (document.querySelector(f + ' .message') || {}).textContent, kod: !!document.querySelector(f + ' input[name="evk_2fa_kod"]') }), F);
    await q.fill(F + ' input[name="form-field-pwd001"]', 'test-haslo');
    await q.click(F + ' button[type=submit]');
    await q.waitForSelector(F + ' input[name="evk_2fa_kod"]', { timeout: 10000 }).catch(() => {});
    t.check('złe hasło: własny komunikat Bricksa, bez kroku kodu; dobre hasło: krok kodu mimo własnego komunikatu', /Błędne dane logowania/.test(zleHaslo.komunikat || '') && !zleHaslo.kod
      && !!(await q.$(F + ' input[name="evk_2fa_kod"]')), J(zleHaslo));
    t.check('bez błędów JS na stronie', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
