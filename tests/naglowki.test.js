/**
 * Nagłówki bezpieczeństwa (1.280.0) — prawdziwe odpowiedzi testowego
 * WordPressa przez php -S, zapis z zakładki Bezpieczeństwo → Nagłówki
 * w Chromium i prawdziwym AJAX-em.
 *
 * Decyzje zgłaszającego (02.10): domyślnie nosniff, Referrer-Policy,
 * Permissions-Policy (kamera, mikrofon, geolokalizacja, płatności) i ramki
 * tylko z tej strony; HSTS wyłączony, z wyborem czasu, bez preload.
 */

const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('naglowki.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const NAZWY = ['x-content-type-options', 'referrer-policy', 'permissions-policy', 'content-security-policy', 'x-frame-options', 'strict-transport-security'];

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress przez php -S');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  let serwer = null;
  let browser = null;
  try {
    sonda('domyslne');
    serwer = await serwerWp.start(wp.wp);
    const naglowki = async (sciezka) => {
      const r = await fetch(serwer.baza + sciezka, { redirect: 'manual' });
      await r.text();
      const o = {};
      for (const n of NAZWY) if (r.headers.get(n) !== null) o[n] = r.headers.get(n);
      return o;
    };

    t.section('domyślnie (bez zapisu): strona i logowanie');
    const d = await naglowki('/');
    console.log('      /: ' + J(d));
    const DOMYSLNE = { 'x-content-type-options': 'nosniff', 'referrer-policy': 'strict-origin-when-cross-origin',
      'permissions-policy': 'camera=(), microphone=(), geolocation=(), payment=()', 'content-security-policy': "frame-ancestors 'self'", 'x-frame-options': 'SAMEORIGIN' };
    t.check('strona: nosniff, Referrer-Policy, Permissions-Policy (4 funkcje), ramki tylko z tej strony; bez HSTS', J(d) === J(DOMYSLNE), J(d));
    t.check('logowanie (wp-login.php): te same nagłówki', J(await naglowki('/wp-login.php')) === J(DOMYSLNE));
    const woo = sonda('woo');
    t.check('przy WooCommerce domyślnie bez płatności (Apple Pay / Google Pay)', J(woo.bez) === J(['camera', 'microphone', 'geolocation', 'payment'])
      && J(woo.z) === J(['camera', 'microphone', 'geolocation']), J(woo));

    t.section('zapis sekcji (prawdziwy AJAX zakładki)');
    const z1 = sonda('ajax', J({ hdr_nosniff: 1, hdr_ramki: 0, hdr_referrer: 1, hdr_referrer_wartosc: 'no-referrer', hdr_permissions: 1,
      hdr_uprawnienia: ['camera'], hdr_hsts: 1, hdr_hsts_wiek: '86400', hdr_hsts_sub: 1 }));
    t.check('zapis przyjęty', z1.odp && z1.odp.success === true, J(z1));
    const h1 = await naglowki('/');
    t.check('strona: bez ramek, no-referrer, tylko kamera; HSTS nie idzie przez HTTP', J(h1) === J({ 'x-content-type-options': 'nosniff',
      'referrer-policy': 'no-referrer', 'permissions-policy': 'camera=()' }), J(h1));
    const l1 = sonda('lista', '1');
    t.check('przez HTTPS: HSTS 1 dzień z subdomenami, bez preload', (l1.lista || {})['Strict-Transport-Security'] === 'max-age=86400; includeSubDomains', J(l1.lista));
    const z2 = sonda('ajax', J({ hdr_nosniff: 0, hdr_referrer_wartosc: 'unsafe-url', hdr_permissions: 1, hdr_hsts_wiek: '999', hdr_hsts: 1, hdr_hsts_sub: 0 }));
    t.check('złe wartości poprawione: referrer zalecany, HSTS 5 minut; brak listy funkcji = żadna (bez nagłówka)',
      z2.zapisane.hdr_referrer_wartosc === 'strict-origin-when-cross-origin' && z2.zapisane.hdr_hsts_wiek === 300 && J(z2.zapisane.hdr_uprawnienia) === J([])
      && !('Permissions-Policy' in (sonda('lista', '1').lista || {})) && sonda('lista', '1').lista['Strict-Transport-Security'] === 'max-age=300', J(z2.zapisane));
    const h2 = await naglowki('/');
    t.check('strona: nosniff wyłączony', !('x-content-type-options' in h2), J(h2));

    t.section('zakładka Bezpieczeństwo → Nagłówki w Chromium');
    sonda('domyslne');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const s = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    s.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(s, serwer.baza);
    await s.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-one&tab=bezpieczenstwo&sub=naglowki');
    const stan0 = await s.evaluate(() => ({
      ramki: document.querySelector('[name="evk_security[hdr_ramki]"]').checked,
      hsts: document.querySelector('[name="evk_security[hdr_hsts]"]').checked,
      funkcje: [...document.querySelectorAll('[name="evk_security[hdr_uprawnienia][]"]:checked')].map((x) => x.value),
    }));
    t.check('zakładka: wartości domyślne (ramki i 4 funkcje zaznaczone, HSTS nie)', stan0.ramki === true && stan0.hsts === false
      && J(stan0.funkcje) === J(['camera', 'microphone', 'geolocation', 'payment']), J(stan0));
    await s.uncheck('[name="evk_security[hdr_ramki]"]');
    await s.uncheck('[name="evk_security[hdr_uprawnienia][]"][value="geolocation"]');
    await s.selectOption('#evk-hdr-referrer', 'same-origin');
    await s.click('#evk-sec-form-naglowki [type=submit]');
    await s.waitForSelector('#evk-sec-form-naglowki .evk-sec-saved', { state: 'visible', timeout: 10000 }).catch(() => {});
    const st = sonda('stan').zapisane || {};
    t.check('zapis z zakładki: ramki wyłączone, bez geolokalizacji, same-origin, reszta bez zmian', st.hdr_ramki === 0 && st.hdr_nosniff === 1
      && J(st.hdr_uprawnienia) === J(['camera', 'microphone', 'payment']) && st.hdr_referrer_wartosc === 'same-origin' && st.hdr_hsts === 0, J(st));
    const h3 = await naglowki('/');
    t.check('strona po zapisie z zakładki', !('x-frame-options' in h3) && h3['permissions-policy'] === 'camera=(), microphone=(), payment=()'
      && h3['referrer-policy'] === 'same-origin', J(h3));
    t.check('bez błędów JS', bledy.length === 0, J(bledy));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
