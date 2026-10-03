/**
 * Ukryty adres logowania z prawdziwym Bricksem (1.288.0) — piąty testowy
 * WordPress. Bricks ma własną stronę logowania i sam przekierowuje na nią
 * wp-login.php (oraz wpuszcza na wp-login.php przez `?brx_use_wp_login`).
 * Test najpierw sprawdza, że to przekierowanie naprawdę jest — dopiero wtedy
 * 404 po włączeniu znaczy, że blokada Evoke idzie PRZED Bricksem.
 */

const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('logowanie-adres-bricks.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const ADRES = 'panel-br1ck5';

module.exports = async function (t) {
  t.section('środowisko');
  const wp = sonda('wp');
  t.check('piąty testowy WordPress z motywem Bricks (tools/testowy-wp.sh, ../bricks-motyw)', !wp.brak && !!wp.wp && !!wp.bricks, wp.brak || J(wp));
  if (wp.brak || !wp.wp || !wp.bricks) return;
  let serwer = null;
  let browser = null;
  try {
    const prz = sonda('przygotuj');
    sonda('ustaw', J({ enabled: 0, adres: ADRES }));
    serwer = await serwerWp.start(wp.wp);
    const baza = serwer.baza;
    const strona = '/?page_id=' + prz.strona;
    const daj = async (sciezka, ciastka) => {
      const r = await fetch(baza + sciezka, { redirect: 'manual', headers: ciastka ? { Cookie: ciastka } : {} });
      const html = await r.text();
      return { kod: r.status, dokad: r.headers.get('location') || '', ciastka: r.headers.getSetCookie(), html,
        e404: /<body[^>]*class="[^"]*\berror404\b/.test(html), wpForm: /id="loginform"/.test(html) };
    };

    t.section('bez ukrytego adresu: Bricks przekierowuje wp-login.php');
    const a1 = await daj('/wp-login.php');
    t.check('kontrola: wp-login.php → 302 na stronę logowania Bricksa', a1.kod === 302 && a1.dokad.includes('page_id=' + prz.strona), J([a1.kod, a1.dokad]));

    t.section('z ukrytym adresem');
    sonda('ustaw', J({ enabled: 1, adres: ADRES }));
    const b1 = await daj('/wp-login.php');
    t.check('wp-login.php: 404 ze strony 404 Bricksa (motyw bricks, body.error404), NIE przekierowanie Bricksa', b1.kod === 404 && b1.e404 && /themes\/bricks\//.test(b1.html) && !b1.wpForm,
      J({ kod: b1.kod, dokad: b1.dokad, e404: b1.e404 }));
    const b2 = await daj('/wp-login.php?brx_use_wp_login');
    t.check('?brx_use_wp_login bez klucza: 404 i Bricks NIE ustawia swojego ciasteczka obejścia', b2.kod === 404 && !b2.wpForm && !b2.ciastka.some((c) => /^brx_use_wp_login=/.test(c)), J([b2.kod, b2.ciastka.map((c) => c.split('=')[0])]));
    const b3 = await daj('/wp-login.php', 'brx_use_wp_login=1');
    t.check('ciasteczko obejścia Bricksa bez klucza też nie wpuszcza: 404', b3.kod === 404 && !b3.wpForm, b3.kod);
    const b4 = await daj(strona);
    t.check('strona logowania Bricksa działa jak dotąd: 200 z formularzem', b4.kod === 200 && /brxe-uaf001/.test(b4.html), b4.kod);

    t.section('adres-klucz z Bricksem');
    const k1 = await daj('/' + ADRES);
    const kc = (k1.ciastka.find((c) => /^evk_ua=/.test(c)) || '').split(';')[0];
    const k2 = await daj('/wp-login.php', kc);
    t.check('z kluczem: wp-login.php to formularz WordPressa (200), Bricks nie odsyła na swoją stronę', k1.kod === 302 && !!kc && k2.kod === 200 && k2.wpForm, J([k1.kod, k2.kod, k2.dokad]));

    t.section('Chromium: logowanie formularzem Bricksa');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const ctx = await browser.newContext();
    const p = await ctx.newPage();
    await p.goto(baza + strona);
    await p.fill('#brxe-uaf001 input[name="form-field-lgn001"]', 'ua_bricks');
    await p.fill('#brxe-uaf001 input[name="form-field-pwd001"]', 'test-haslo');
    await p.click('#brxe-uaf001 button[type=submit]');
    await p.waitForFunction(() => /wordpress_logged_in_/.test(document.cookie) || !!document.querySelector('#brxe-uaf001 .message'), null, { timeout: 15000 }).catch(() => {});
    await p.waitForTimeout(500);
    const c = await ctx.cookies();
    t.check('logowanie przez stronę Bricksa (admin-ajax) przechodzi i daje klucz na czas sesji', c.some((x) => /^wordpress_logged_in_/.test(x.name)) && c.some((x) => x.name === 'evk_ua'),
      J(c.map((x) => x.name)));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    const sp = sonda('sprzataj');
    t.check('sprzątanie: ukryty adres wyłączony, ustawienia Bricksa przywrócone', sp.wlaczony === false, J(sp));
  }
};
