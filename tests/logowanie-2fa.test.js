/**
 * Logowanie dwuetapowe (1.287.0) — prawdziwy WordPress przez php -S i Chromium.
 *
 * Kod QR z profilu test ODCZYTUJE jak telefon (jsQR z obrazka narysowanego
 * w przeglądarce), a kody liczy własną implementacją TOTP w Node — nie tą
 * z wtyczki. Potem: drugi krok na wp-login.php (hasło nie wraca do strony),
 * powtórka kodu, zapamiętane urządzenie, kody zapasowe, limit prób, API
 * (XML-RPC hasłem — nie, hasłem aplikacji — tak), wymuszenie dla roli,
 * reset przez administratora i stała awaryjna.
 */

const crypto = require('crypto');
const jsQR = require('jsqr');
const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('logowanie-2fa.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const czekaj = (ms) => new Promise((r) => setTimeout(r, ms));

/* TOTP niezależnie od wtyczki (RFC 6238, SHA-1, 30 s, 6 cyfr). */
function base32(s) {
  const a = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
  let bity = '';
  for (const z of s.replace(/[\s=]/g, '').toUpperCase()) bity += a.indexOf(z).toString(2).padStart(5, '0');
  const out = [];
  for (let i = 0; i + 8 <= bity.length; i += 8) out.push(parseInt(bity.slice(i, i + 8), 2));
  return Buffer.from(out);
}
function totp(sekret, licznik) {
  const b = Buffer.alloc(8);
  b.writeBigUInt64BE(BigInt(licznik));
  const h = crypto.createHmac('sha1', base32(sekret)).update(b).digest();
  const o = h[19] & 15;
  const v = ((h[o] & 0x7f) << 24) | (h[o + 1] << 16) | (h[o + 2] << 8) | h[o + 3];
  return String(v % 1e6).padStart(6, '0');
}
/* Kod, którego wtyczka jeszcze nie przyjęła: okno późniejsze niż ostatnie użyte, w zasięgu ±1. */
async function kodPo(sekret, ostatni) {
  for (;;) {
    const teraz = Math.floor(Date.now() / 30000);
    if (ostatni + 1 <= teraz + 1) return totp(sekret, Math.max(ostatni + 1, teraz));
    await czekaj(1000);
  }
}

module.exports = async function (t) {
  t.section('środowisko i funkcje');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  const f = sonda('totp');
  t.check('TOTP wtyczki = wektory RFC 6238 (także licznik ponad 32 bity)', J(f.rfc) === J({ 59: '287082', 1111111109: '081804', 1111111111: '050471', 1234567890: '005924', 2000000000: '279037', 20000000000: '353130' })
    && f.base32 === 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', J(f));
  t.check('okno ±30 s, raz przyjęty kod nie wchodzi drugi raz, szyfrowanie sekretu w obie strony', J(f.okno) === J([1, -1, 1, -1]) && f.szyfr.tam_i_z_powrotem && f.szyfr.zly, J(f));
  t.check('wektor RFC liczony też przez test (Node) — ta sama implementacja co aplikacje', totp('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', 1) === '287082');

  let serwer = null;
  let browser = null;
  try {
    const ids = sonda('przygotuj').id || {};
    serwer = await serwerWp.start(wp.wp, { env: { EVK_WP_SRODOWISKO: 'local' } });
    const baza = serwer.baza;
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const nowa = async () => (await browser.newContext()).newPage();
    const konto = (l) => sonda('konto', l).konto || {};

    t.section('profil: kod QR, włączenie, kody zapasowe');
    const p1 = await nowa();
    await serwerWp.zaloguj(p1, baza, 'dwa_admin', 'test-haslo');
    t.check('bez 2FA logowanie samym hasłem jak dotąd', /\/wp-admin\//.test(p1.url()), p1.url());
    await p1.goto(baza + '/wp-admin/profile.php');
    await p1.waitForSelector('#evk-2fa-box .evk-2fa-qr svg', { timeout: 10000 }).catch(() => {});
    const piksele = await p1.evaluate(async () => {
      const svg = document.querySelector('#evk-2fa-box .evk-2fa-qr svg');
      if (!svg) return null;
      const k = svg.cloneNode(true);
      k.setAttribute('width', '400'); k.setAttribute('height', '400');
      const img = new Image();
      img.src = 'data:image/svg+xml;base64,' + btoa(new XMLSerializer().serializeToString(k));
      await img.decode();
      const c = document.createElement('canvas'); c.width = 400; c.height = 400;
      const x = c.getContext('2d'); x.fillStyle = '#fff'; x.fillRect(0, 0, 400, 400); x.drawImage(img, 0, 0, 400, 400);
      return Array.from(x.getImageData(0, 0, 400, 400).data);
    });
    const odczyt = piksele && jsQR(Uint8ClampedArray.from(piksele), 400, 400);
    const uri = odczyt ? odczyt.data : '';
    console.log('      QR: ' + uri.replace(/secret=[A-Z2-7]+/, 'secret=…'));
    const u = uri ? new URL(uri) : null;
    const sekret = u ? u.searchParams.get('secret') : '';
    const klucz = (await p1.textContent('#evk-2fa-box .evk-2fa-klucz').catch(() => '')).replace(/\s/g, '');
    t.check('kod QR odczytany jak telefonem: otpauth://totp, konto dwa_admin, 32 znaki base32, SHA1 / 6 cyfr / 30 s, ten sam klucz co do przepisania',
      !!u && u.protocol === 'otpauth:' && u.host === 'totp' && /:dwa_admin$/.test(decodeURIComponent(u.pathname)) && /^[A-Z2-7]{32}$/.test(sekret)
      && u.searchParams.get('digits') === '6' && u.searchParams.get('period') === '30' && u.searchParams.get('algorithm') === 'SHA1' && klucz === sekret, J({ uri: uri.replace(/secret=[A-Z2-7]+/, 'secret=…'), klucz: klucz.length }));
    const zly = totp(sekret, Math.floor(Date.now() / 30000)) === '000000' ? '111111' : '000000';
    await p1.fill('#evk-2fa-kod', zly);
    await p1.click('[data-akcja="wlacz"]');
    await p1.waitForFunction(() => /nie zgadza/.test(document.querySelector('.evk-2fa-komunikat').textContent), null, { timeout: 10000 }).catch(() => {});
    t.check('zły kod przy włączaniu: „Kod się nie zgadza…”, 2FA nadal wyłączone', /^Kod się nie zgadza/.test(await p1.textContent('.evk-2fa-komunikat')) && !konto('dwa_admin').ma);
    await p1.fill('#evk-2fa-kod', await kodPo(sekret, 0));
    await p1.click('[data-akcja="wlacz"]');
    await p1.waitForSelector('.evk-2fa-zapasowe:not([hidden])', { timeout: 10000 }).catch(() => {});
    const kody = await p1.$$eval('.evk-2fa-kody span', (x) => x.map((e) => e.textContent));
    const gotoweWyl = await p1.isDisabled('.evk-2fa-gotowe');
    await p1.check('.evk-2fa-zapisalem');
    const k1 = konto('dwa_admin');
    t.check('włączone: 10 kodów zapasowych xxxx-xxxx (bez 0/o/1/l/i), „Gotowe” dopiero po „Zapisałem kody”', kody.length === 10 && kody.every((k) => /^[a-hj-kmnp-z2-9]{4}-[a-hj-kmnp-z2-9]{4}$/.test(k))
      && new Set(kody).size === 10 && gotoweWyl && !(await p1.isDisabled('.evk-2fa-gotowe')), J(kody));
    t.check('baza: włączone, 10 skrótów kodów, bez sekretu oczekującego, sekret NIE jawnie', k1.ma && k1.kody === 10 && !k1.oczekujacy && k1.sekret_jawny === false, J(k1));
    await p1.goto(baza + '/wp-admin/users.php');
    const kol = await p1.evaluate(() => [...document.querySelectorAll('#the-list tr')].map((tr) => [tr.querySelector('.column-username strong a, .column-username a')?.textContent.trim(), (tr.querySelector('.column-evk_2fa') || {}).textContent]).filter((x) => x[0]));
    t.check('lista Użytkownicy: kolumna 2FA — dwa_admin „włączone”, dwa_red „—”', kol.some((x) => x[0] === 'dwa_admin' && /włączone/.test(x[1])) && kol.some((x) => x[0] === 'dwa_red' && x[1] === '—'), J(kol));

    t.section('wp-login.php: drugi krok');
    const p2 = await nowa();
    await serwerWp.zaloguj(p2, baza, 'dwa_admin', 'test-haslo');
    const ekran = await p2.evaluate(() => ({ form: !!document.getElementById('evk-2fa-form'), html: document.documentElement.outerHTML }));
    const ciastka = await p2.context().cookies();
    t.check('po haśle: ekran kodu, w stronie NIE ma hasła, NIE ma jeszcze ciasteczka logowania', ekran.form && !ekran.html.includes('test-haslo')
      && !ciastka.some((c) => /^wordpress_logged_in_/.test(c.name)), J({ form: ekran.form, ciastka: ciastka.map((c) => c.name) }));
    await p2.fill('#evk-2fa-kod', '000000' === await kodPo(sekret, konto('dwa_admin').licznik) ? '111111' : '000000');
    await Promise.all([p2.waitForNavigation(), p2.click('#evk-2fa-form [type=submit]')]);
    const blad = await p2.textContent('#login_error').catch(() => '');
    t.check('zły kod: „Nieprawidłowy kod. Zostało prób: 4.”, nadal ekran kodu', /Nieprawidłowy kod\. Zostało prób: 4\./.test(blad) && !!(await p2.$('#evk-2fa-form')), blad);
    const dobry = await kodPo(sekret, konto('dwa_admin').licznik);
    await p2.fill('#evk-2fa-kod', dobry);
    await p2.check('#evk-2fa-pamietaj');
    await Promise.all([p2.waitForNavigation(), p2.click('#evk-2fa-form [type=submit]')]);
    const c2 = await p2.context().cookies();
    const urz = c2.find((c) => c.name === 'evk_2fa_urz_' + ids.dwa_admin);
    t.check('dobry kod + „Zapamiętaj”: zalogowany w kokpicie, ciasteczko urządzenia httpOnly na 30 dni', /\/wp-admin\//.test(p2.url()) && c2.some((c) => /^wordpress_logged_in_/.test(c.name))
      && !!urz && urz.httpOnly && Math.abs(urz.expires - (Date.now() / 1000 + 30 * 86400)) < 120 && konto('dwa_admin').urzadzenia === 1, J({ url: p2.url(), urz }));
    const p3 = await nowa();
    await serwerWp.zaloguj(p3, baza, 'dwa_admin', 'test-haslo');
    await p3.fill('#evk-2fa-kod', dobry);
    await Promise.all([p3.waitForNavigation(), p3.click('#evk-2fa-form [type=submit]')]);
    t.check('ten sam kod drugi raz (inna przeglądarka): odrzucony', !!(await p3.$('#evk-2fa-form')) && /Nieprawidłowy kod/.test(await p3.textContent('#login_error').catch(() => '')));
    /* Zapamiętane urządzenie: nowe logowanie w tej samej przeglądarce (bez sesji, z ciasteczkiem urządzenia). */
    await p2.context().clearCookies();
    await p2.context().addCookies([urz]);
    await serwerWp.zaloguj(p2, baza, 'dwa_admin', 'test-haslo');
    t.check('zapamiętane urządzenie: samo hasło, bez kodu', /\/wp-admin\//.test(p2.url()) && !(await p2.$('#evk-2fa-form')), p2.url());
    /* Zmiana hasła unieważnia zapamiętane urządzenia (ktoś mógł przejąć i hasło, i komputer). */
    sonda('haslo', 'dwa_admin', 'test-haslo-2');
    await p2.context().clearCookies();
    await p2.context().addCookies([urz]);
    await serwerWp.zaloguj(p2, baza, 'dwa_admin', 'test-haslo-2');
    const poZmianie = !!(await p2.$('#evk-2fa-form'));
    sonda('haslo', 'dwa_admin', 'test-haslo');
    t.check('po zmianie hasła to samo urządzenie znowu prosi o kod', poZmianie);
    const p4 = await nowa();
    await serwerWp.zaloguj(p4, baza, 'dwa_admin', 'test-haslo');
    await p4.click('.evk-2fa-zapasowy button');
    const etyk = await p4.textContent('.evk-2fa-etykieta');
    await p4.fill('#evk-2fa-kod', kody[0].toUpperCase().replace('-', ' '));
    await Promise.all([p4.waitForNavigation(), p4.click('#evk-2fa-form [type=submit]')]);
    t.check('kod zapasowy (wielkimi, ze spacją): „Kod zapasowy”, zalogowany, zostało 9', etyk === 'Kod zapasowy' && /\/wp-admin\//.test(p4.url()) && konto('dwa_admin').kody === 9, J({ etyk, url: p4.url() }));
    const p5 = await nowa();
    await serwerWp.zaloguj(p5, baza, 'dwa_admin', 'test-haslo');
    await p5.fill('#evk-2fa-kod', kody[0]);
    await Promise.all([p5.waitForNavigation(), p5.click('#evk-2fa-form [type=submit]')]);
    t.check('ten sam kod zapasowy drugi raz: odrzucony', !!(await p5.$('#evk-2fa-form')) && konto('dwa_admin').kody === 9);
    for (let i = 0; i < 4; i++) {
      await p5.fill('#evk-2fa-kod', '999999');
      await Promise.all([p5.waitForNavigation(), p5.click('#evk-2fa-form [type=submit]')]);
    }
    t.check('5 złych kodów: koniec tokenu — z powrotem do logowania z komunikatem', /evk_2fa=proby/.test(p5.url()) && /Za dużo nieudanych kodów/.test(await p5.textContent('#login_error').catch(() => '')), p5.url());

    t.section('API: hasło konta nie, hasło aplikacji tak');
    const xml = await fetch(baza + '/xmlrpc.php', { method: 'POST', headers: { 'Content-Type': 'text/xml' },
      body: '<?xml version="1.0"?><methodCall><methodName>wp.getProfile</methodName><params><param><value>1</value></param><param><value>dwa_admin</value></param><param><value>test-haslo</value></param></params></methodCall>' }).then((r) => r.text());
    t.check('XML-RPC zwykłym hasłem konta z 2FA: odmowa', /faultCode/.test(xml) && !/<name>username<\/name>/.test(xml), xml.slice(0, 300));
    const haslo = sonda('haslo-aplikacji', 'dwa_admin').haslo;
    const rest = await fetch(baza + '/?rest_route=/wp/v2/users/me&context=edit', { headers: { Authorization: 'Basic ' + Buffer.from('dwa_admin:' + haslo).toString('base64') } });
    const ja = await rest.json().catch(() => ({}));
    t.check('REST hasłem aplikacji (MCP z Claude Desktop): działa', rest.status === 200 && ja.username === 'dwa_admin', rest.status + ' ' + J(ja).slice(0, 200));

    t.section('wymuszenie dla roli');
    sonda('ustaw', J({ role: ['editor'] }));
    const p6 = await nowa();
    await serwerWp.zaloguj(p6, baza, 'dwa_red', 'test-haslo');
    await p6.goto(baza + '/wp-admin/edit.php');
    const naProfil = p6.url();
    const uwaga = await p6.textContent('.notice-warning').catch(() => '');
    await p6.goto(baza + '/');
    t.check('redaktor bez 2FA: kokpit i strona z paskiem odsyłają na Profil z komunikatem', /profile\.php\?evk_2fa=wymagane/.test(naProfil) && /Twoja rola wymaga logowania dwuetapowego/.test(uwaga)
      && /profile\.php\?evk_2fa=wymagane/.test(p6.url()), J({ naProfil, po: p6.url() }));
    const wylDostepny = !!(await p6.$('[data-akcja="wylacz"]'));
    t.check('profil redaktora: sekcja włączania z kodem QR', !!(await p6.$('#evk-2fa-box .evk-2fa-qr')) && !wylDostepny);

    t.section('administrator: zakładka Logowanie, reset, dziennik');
    const pa = await nowa();
    await serwerWp.zaloguj(pa, baza);
    await pa.goto(baza + '/wp-admin/options-general.php?page=evoke-one&tab=logowanie');
    const wiersze = await pa.$$eval('#evk-2fa-konta tbody tr', (x) => x.map((tr) => [...tr.children].map((td) => td.textContent.trim())));
    t.check('lista kont: dwa_admin „Włączone od …, kodów zapasowych: 9”, dwa_red „Wymagane — jeszcze nie włączone”',
      wiersze.some((w) => /dwa_admin/.test(w[0]) && /^Włączone od .*kodów zapasowych: 9$/.test(w[1])) && wiersze.some((w) => /dwa_red/.test(w[0]) && w[1] === 'Wymagane — jeszcze nie włączone'), J(wiersze));
    pa.on('dialog', (d) => d.accept());
    await Promise.all([pa.waitForNavigation(), pa.click('#evk-2fa-konta tr[data-konto="' + ids.dwa_admin + '"] .evk-2fa-reset')]);
    const dz = sonda('dziennik').dziennik || [];
    t.check('„Wyłącz 2FA”: konto bez 2FA, w dzienniku reset przez admina (i wcześniejsze włączenie przez samo konto)', !konto('dwa_admin').ma
      && J(dz[0]) === J(['reset', 'dwa_admin', 'admin']) && dz.some((w) => J(w) === J(['wlaczenie', 'dwa_admin', 'dwa_admin'])), J(dz));

    t.section('stała awaryjna EVK_2FA_WYLACZ');
    sonda('wlacz', 'dwa_admin', 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP');
    sonda('awaryjnie', 1);
    const p7 = await nowa();
    await serwerWp.zaloguj(p7, baza, 'dwa_admin', 'test-haslo');
    const czerwony = await p7.textContent('.notice-error').catch(() => '');
    sonda('awaryjnie', 0);
    t.check('ze stałą: samo hasło wystarcza, a kokpit pokazuje czerwone ostrzeżenie', /\/wp-admin\//.test(p7.url()) && !(await p7.$('#evk-2fa-form')) && /EVK_2FA_WYLACZ/.test(czerwony), J({ url: p7.url(), czerwony }));
    const p8 = await nowa();
    await serwerWp.zaloguj(p8, baza, 'dwa_admin', 'test-haslo');
    t.check('bez stałej: znowu prosi o kod', !!(await p8.$('#evk-2fa-form')));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
