/**
 * Ukryty adres logowania (1.288.0) — prawdziwy WordPress przez php -S i Chromium.
 *
 * Żądania bez przeglądarki idą zwykłym fetch-em bez podążania za
 * przekierowaniami, więc test widzi kod odpowiedzi i ciasteczka tak, jak
 * zobaczy je skaner botów. Logowanie, wylogowanie i zakładkę przechodzi
 * Chromium. Na końcu sprzątanie przywraca opcję — inne zestawy logują się
 * przez wp-login.php.
 */

const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('logowanie-adres.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const ADRES = 'panel-t3st9q';

module.exports = async function (t) {
  t.section('środowisko i sprawdzanie adresu');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  const s = sonda('sprawdz', J([ADRES, 'wp-admin', 'login', 'Panel', 'ab', '-panel', 'sample-page', 'a'.repeat(51)]));
  const w = s.wynik || {};
  t.check('adres: dobry przechodzi; zajęty przez WordPressa, wielkie litery, za krótki, myślnik na początku, istniejąca strona i 51 znaków — odmowa',
    w[ADRES] === '' && /znaczy dla WordPressa/.test(w['wp-admin']) && /znaczy dla WordPressa/.test(w.login) && /małe litery/.test(w.Panel) && /małe litery/.test(w.ab)
    && /małe litery/.test(w['-panel']) && /jest już strona/.test(w['sample-page']) && /małe litery/.test(w['a'.repeat(51)]), J(s));
  t.check('losowy adres z panelu jest dobry: „panel-” i 6 znaków', /^panel-[a-z2-9]{6}$/.test(s.losowy) && s.losowy_dobry, J(s));

  let serwer = null;
  let browser = null;
  try {
    sonda('ustaw', J({ enabled: 0, adres: '' }));
    serwer = await serwerWp.start(wp.wp);
    const baza = serwer.baza;
    /* Żądanie jak od skanera: bez podążania za przekierowaniem; `ciastka` — nagłówek Cookie. */
    const daj = async (sciezka, o = {}) => {
      const r = await fetch(baza + sciezka, { redirect: 'manual', method: o.metoda || 'GET', body: o.cialo,
        headers: Object.assign({}, o.ciastka ? { Cookie: o.ciastka } : {}, o.cialo ? { 'Content-Type': 'application/x-www-form-urlencoded' } : {}) });
      const html = await r.text();
      return { kod: r.status, dokad: r.headers.get('location') || '', ciastka: r.headers.getSetCookie ? r.headers.getSetCookie() : [], html,
        e404: /class="[^"]*\berror404\b/.test(html), formularz: /id="loginform"/.test(html) };
    };

    t.section('wyłączony: wp-login.php jak dotąd');
    const a0 = await daj('/wp-login.php');
    t.check('wyłączony: wp-login.php 200 z formularzem logowania', a0.kod === 200 && a0.formularz, a0.kod);
    const a1 = await daj('/' + ADRES);
    t.check('wyłączony: adres-klucz NIE wpuszcza (nie ustawia ciasteczka evk_ua)', !a1.ciastka.some((c) => /^evk_ua=/.test(c)), J(a1.ciastka));

    t.section('włączony: wp-login.php i /wp-admin/ bez klucza');
    sonda('ustaw', J({ enabled: 1, adres: ADRES }));
    const b1 = await daj('/wp-login.php');
    t.check('wp-login.php: 404 ze stroną 404 motywu (body.error404), bez formularza logowania', b1.kod === 404 && b1.e404 && !b1.formularz && !/user_pass/.test(b1.html), J({ kod: b1.kod, e404: b1.e404 }));
    const b2 = await daj('/wp-login.php', { metoda: 'POST', cialo: 'log=admin&pwd=admin&wp-submit=Log+In&testcookie=1', ciastka: 'wordpress_test_cookie=WP%20Cookie%20check' });
    t.check('POST z prawdziwym loginem i hasłem: 404, BEZ ciasteczka logowania (zgadywanie haseł nie dochodzi do WordPressa)',
      b2.kod === 404 && !b2.ciastka.some((c) => /^wordpress_(logged_in|sec)_/.test(c)), J({ kod: b2.kod, c: b2.ciastka.map((c) => c.split('=')[0]) }));
    const b3 = [];
    for (const q of ['?brx_use_wp_login', '?action=lostpassword', '?action=register', '?action=logout', '?interim-login=1', '?action=postpass', '?action=rp', '?action=confirmaction&request_id=1&confirm_key=x']) {
      const r = await daj('/wp-login.php' + q);
      b3.push([q, r.kod, r.e404]);
    }
    t.check('?brx_use_wp_login, przypomnienie hasła, rejestracja, wylogowanie, interim-login, postpass GET-em, rp i confirmaction bez klucza — 404',
      b3.every((x) => x[1] === 404 && x[2]), J(b3));
    const b4 = await daj('/wp-admin/');
    const b5 = await daj('/wp-admin/options-general.php');
    const b6 = await daj('/?error=404');
    t.check('/wp-admin/ i strona kokpitu: przekierowanie na 404 strony (/?error=404), BEZ wp-login.php w adresie', b4.kod === 302 && /\/\?error=404$/.test(b4.dokad)
      && b5.kod === 302 && /\/\?error=404$/.test(b5.dokad) && !/wp-login/.test(b4.dokad + b5.dokad) && b6.kod === 404 && b6.e404, J([b4.kod, b4.dokad, b5.dokad, b6.kod]));
    const b7 = await daj('/wp-admin/admin-ajax.php');
    const b8 = await daj('/wp-admin/admin-post.php');
    t.check('admin-ajax.php i admin-post.php działają jak dotąd (400 „0” i 200, nie 404 ani przekierowanie)', b7.kod === 400 && b7.html.trim() === '0' && b8.kod === 200 && !b8.e404, J([b7.kod, b8.kod]));
    const zly = sonda('ciastko', Math.floor(Date.now() / 1000) + 600, ADRES).wartosc.replace(/.$/, (z) => (z === '0' ? '1' : '0'));
    const stary = sonda('ciastko', Math.floor(Date.now() / 1000) - 5, ADRES).wartosc;
    const inny = sonda('ciastko', Math.floor(Date.now() / 1000) + 600, 'panel-inny').wartosc;
    const c1 = [await daj('/wp-login.php', { ciastka: 'evk_ua=' + encodeURIComponent(zly) }), await daj('/wp-login.php', { ciastka: 'evk_ua=' + encodeURIComponent(stary) }),
      await daj('/wp-login.php', { ciastka: 'evk_ua=' + encodeURIComponent(inny) }), await daj('/wp-login.php', { ciastka: 'evk_ua=1' })];
    t.check('podrobione ciasteczko: zły podpis, termin minął (podpis dobry), podpisane dla innego adresu, śmieci — 404', c1.every((r) => r.kod === 404), J(c1.map((r) => r.kod)));

    t.section('adres-klucz');
    const k1 = await daj('/' + ADRES);
    const ck = k1.ciastka.find((c) => /^evk_ua=/.test(c)) || '';
    const termin = Number((decodeURIComponent(ck.split(';')[0].split('=')[1] || '').split('|')[0]));
    t.check('/' + ADRES + ': przekierowanie na wp-login.php, ciasteczko evk_ua HttpOnly, SameSite=Lax, ważne 10 minut', k1.kod === 302 && /\/wp-login\.php$/.test(k1.dokad)
      && /HttpOnly/i.test(ck) && /SameSite=Lax/i.test(ck) && Math.abs(termin - (Date.now() / 1000 + 600)) < 30, J({ kod: k1.kod, dokad: k1.dokad, ck: ck.replace(/=[^;]+/, '=…') }));
    const kc = ck.split(';')[0];
    const k2 = await daj('/wp-login.php', { ciastka: kc });
    const k3 = await daj('/wp-login.php?action=lostpassword', { ciastka: kc });
    t.check('z kluczem: wp-login.php 200 z formularzem, przypomnienie hasła też', k2.kod === 200 && k2.formularz && k3.kod === 200 && /lostpasswordform/.test(k3.html), J([k2.kod, k3.kod]));
    const k4 = await daj('/?' + ADRES + '&redirect_to=' + encodeURIComponent(baza + '/wp-admin/profile.php'));
    t.check('postać /?adres (zwykłe adresy WordPressa) też wpuszcza i niesie redirect_to', k4.kod === 302 && /\/wp-login\.php\?redirect_to=/.test(k4.dokad)
      && decodeURIComponent(k4.dokad).includes('/wp-admin/profile.php') && k4.ciastka.some((c) => /^evk_ua=/.test(c)), k4.dokad);
    const k5 = await daj('/' + ADRES + '-x');
    const k6 = await daj('/sub/' + ADRES);
    t.check('tylko dokładnie ten adres: /' + ADRES + '-x i /sub/' + ADRES + ' nie dają klucza', !k5.ciastka.some((c) => /^evk_ua=/.test(c)) && !k6.ciastka.some((c) => /^evk_ua=/.test(c)), J([k5.kod, k6.kod]));

    t.section('wyjątki: e-maile i hasło wpisu');
    const rs = sonda('reset', 'ua_reset');
    const r1 = await daj('/wp-login.php?action=rp&key=' + rs.klucz + '&login=' + rs.login);
    const rc = (r1.ciastka.find((c) => /^wp-resetpass-/.test(c)) || '').split(';')[0];
    const r2 = await daj('/wp-login.php?action=rp', { ciastka: rc });
    const r3 = await daj('/wp-login.php?action=rp&key=zly' + rs.klucz.slice(3) + '&login=' + rs.login);
    t.check('link resetu hasła z e-maila: z ważnym kluczem formularz nowego hasła (przez ciasteczko WordPressa), ze złym — 404',
      r1.kod === 302 && !!rc && r2.kod === 200 && /resetpassform/.test(r2.html) && r3.kod === 404, J([r1.kod, !!rc, r2.kod, r3.kod]));
    const r4 = await daj('/wp-login.php?action=resetpass', { metoda: 'POST', ciastka: rc, cialo: 'pass1=test-haslo-nowe&pass2=test-haslo-nowe&rp_key=' + rs.klucz + '&wp-submit=Save' });
    t.check('ustawienie nowego hasła (POST resetpass z ciasteczkiem) przechodzi — 200, „hasło zostało zmienione”', r4.kod === 200 && /reset|zresetowane|zmienione/i.test(r4.html) && !r4.e404, r4.kod);
    const pr = sonda('prosba');
    const p1 = await daj('/wp-login.php?action=confirmaction&request_id=' + pr.id + '&confirm_key=' + pr.klucz);
    const p2 = await daj('/wp-login.php?action=confirmaction&request_id=' + pr.id + '&confirm_key=zly');
    t.check('potwierdzenie prośby o dane z e-maila: z ważnym kluczem 200 (nie 404), ze złym — 404', p1.kod === 200 && !p1.e404 && p2.kod === 404, J([p1.kod, p2.kod]));
    sonda('chroniona');
    const h1 = await daj('/wp-login.php?action=postpass', { metoda: 'POST', cialo: 'post_password=test-haslo&Submit=Enter' });
    t.check('hasło wpisu chronionego (formularz POST na wp-login.php?action=postpass): przyjęte, ciasteczko wp-postpass', h1.kod !== 404 && !h1.e404 && h1.ciastka.some((c) => /^wp-postpass_/.test(c)), J([h1.kod, h1.ciastka.map((c) => c.split('=')[0])]));

    t.section('Chromium: logowanie, sesja, wylogowanie, zmiana adresu');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const ctx = await browser.newContext();
    const p = await ctx.newPage();
    const odp = await p.goto(baza + '/wp-login.php');
    t.check('przeglądarka bez klucza: wp-login.php — strona 404', odp.status() === 404 && !(await p.$('#loginform')), odp.status());
    await p.goto(baza + '/' + ADRES);
    t.check('przeglądarka: adres-klucz otwiera formularz logowania', /\/wp-login\.php$/.test(p.url()) && !!(await p.$('#loginform')), p.url());
    await serwerWp.zaloguj(p, baza, 'admin', 'admin');
    const ciastkaPo = await ctx.cookies();
    const kPo = ciastkaPo.find((c) => c.name === 'evk_ua');
    const tPo = kPo ? Number(decodeURIComponent(kPo.value).split('|')[0]) : 0;
    t.check('po zalogowaniu: kokpit; klucz na czas sesji logowania (termin ~2 dni, ciasteczko sesyjne jak logowanie bez „Zapamiętaj mnie”)',
      /\/wp-admin\//.test(p.url()) && !!kPo && tPo > Date.now() / 1000 + 86400 && kPo.expires === -1, J({ url: p.url(), termin: tPo, wygasa: kPo && kPo.expires }));
    await p.goto(baza + '/wp-admin/profile.php');
    const wyloguj = await p.getAttribute('#wp-admin-bar-logout a', 'href');
    await p.goto(wyloguj);
    t.check('wylogowanie: strona logowania z komunikatem „wylogowano” (nie 404)', /loggedout=true/.test(p.url()) && !!(await p.$('#loginform')), p.url());
    const p2b = await ctx.newPage();
    await p2b.goto(baza + '/wp-admin/');
    t.check('po wylogowaniu, z kluczem z sesji: /wp-admin/ prowadzi do logowania jak w WordPressie', /\/wp-login\.php\?redirect_to=/.test(p2b.url()) && !!(await p2b.$('#loginform')), p2b.url());
    sonda('ustaw', J({ enabled: 1, adres: 'panel-n0wy22' }));
    const o2 = await p2b.goto(baza + '/wp-login.php');
    t.check('zmiana adresu unieważnia stary klucz: ta sama przeglądarka dostaje 404', o2.status() === 404, o2.status());
    const o3 = await p2b.goto(baza + '/' + ADRES);
    t.check('stary adres to teraz zwykła nieistniejąca strona: nie wpuszcza', !/wp-login\.php/.test(p2b.url()) && !(await p2b.$('#loginform')), p2b.url() + ' ' + o3.status());
    await p2b.close();
    sonda('ustaw', J({ enabled: 1, adres: ADRES }));

    t.section('linki na strony logowania Bricksa');
    const l0 = sonda('linki', 0);
    t.check('bez stron Bricksa: linki WordPressa bez zmian (wp-login.php)', /wp-login\.php/.test(l0.login) && /wp-login\.php\?action=lostpassword/.test(l0.haslo) && /wp-login\.php\?action=register/.test(l0.rejestracja), J(l0));
    const l1 = sonda('linki', 1);
    const st = l1.strony || {};
    t.check('ze stronami Bricksa: „Zaloguj” (z redirect_to), „Nie pamiętasz hasła?”, „Zarejestruj się” prowadzą na nie, nie na wp-login.php',
      !!st.login_page && l1.login.startsWith(st.login_page) && /redirect_to=http%3A%2F%2Fcel\.test%2Fx/.test(l1.login) && l1.haslo === st.lost_password_page
      && l1.rejestracja === st.registration_page, J(l1));
    sonda('ustaw', J({ enabled: 0, adres: ADRES }));
    const l2 = sonda('linki', 0);
    t.check('wyłączony: strony Bricksa w ustawieniach, ale linki nietknięte', /wp-login\.php/.test(l2.login) && /wp-login\.php/.test(l2.haslo), J(l2));
    sonda('ustaw', J({ enabled: 1, adres: ADRES }));

    t.section('błąd krytyczny: link trybu odzyskiwania z e-maila');
    const rod = sonda('odzyskiwanie');
    const ro1 = await daj(rod.sciezka);
    const roc = (ro1.ciastka.find((c) => /^wordpress_rec_/.test(c)) || '').split(';')[0];
    t.check('link z e-maila (generate_url rdzenia): wejście w tryb odzyskiwania, przekierowanie na wp-login.php?action=entered_recovery_mode', /^\/wp-login\.php\?action=enter_recovery_mode&/.test(rod.sciezka)
      && ro1.kod === 302 && /wp-login\.php\?action=entered_recovery_mode$/.test(ro1.dokad) && !!roc, J({ kod: ro1.kod, dokad: ro1.dokad, ciastko: !!roc }));
    const ro2 = await daj('/wp-login.php?action=entered_recovery_mode', { ciastka: roc });
    const ro3 = await daj('/wp-login.php?action=entered_recovery_mode');
    t.check('w trybie odzyskiwania (bez klucza adresu): formularz logowania 200; bez sesji odzyskiwania ten sam adres — 404', ro2.kod === 200 && ro2.formularz && ro3.kod === 404, J([ro2.kod, ro3.kod]));
    const zKluczem = await daj('/wp-login.php', { ciastka: kc });
    const linkHasla = (h) => ((h.match(/href="([^"]*)"[^>]*>\s*(Lost your password|Nie pamiętasz hasła)/) || [])[1] || '').replace(/&amp;/g, '&');
    t.check('w trybie odzyskiwania „Nie pamiętasz hasła?” zostaje przy wp-login.php (strona Bricksa może być zepsuta); z samym kluczem — strona Bricksa',
      /wp-login\.php\?action=lostpassword/.test(linkHasla(ro2.html)) && linkHasla(zKluczem.html) === (l1.strony || {}).lost_password_page.replace('http://stara.test', baza), J([linkHasla(ro2.html), linkHasla(zKluczem.html)]));
    const ro4 = await daj(rod.sciezka);
    t.check('link z e-maila jest jednorazowy: drugie użycie nie daje sesji odzyskiwania', !ro4.ciastka.some((c) => /^wordpress_rec_/.test(c)), J([ro4.kod]));
    const rpo = await (await browser.newContext()).newPage();
    if (roc) await rpo.context().addCookies([{ name: roc.split('=')[0], value: roc.slice(roc.indexOf('=') + 1), url: baza }]);
    await rpo.goto(baza + '/wp-login.php?action=entered_recovery_mode');
    await serwerWp.zaloguj(rpo, baza, 'admin', 'admin');
    t.check('logowanie w trybie odzyskiwania bez klucza adresu: kokpit', /\/wp-admin\//.test(rpo.url()), rpo.url());
    await rpo.context().close();

    t.section('stała awaryjna');
    sonda('awaryjnie', 1);
    const e1 = await daj('/wp-login.php');
    sonda('awaryjnie', 0);
    const e2 = await daj('/wp-login.php');
    t.check('EVK_UKRYTY_ADRES_WYLACZ w wp-config.php: wp-login.php otwarty (200), po usunięciu stałej znów 404', e1.kod === 200 && e1.formularz && e2.kod === 404, J([e1.kod, e2.kod]));

    t.section('zakładka Logowanie');
    sonda('ustaw', J({ enabled: 0, adres: '' }));
    const pz = await (await browser.newContext()).newPage();
    await serwerWp.zaloguj(pz, baza, 'admin', 'admin');
    await pz.goto(baza + '/wp-admin/options-general.php?page=evoke-one&tab=logowanie');
    const pole = await pz.inputValue('#evk-ua-adres').catch(() => '');
    t.check('sekcja „Ukryty adres logowania”: WYŁĄCZONY, pole z losowym adresem na start', /WYŁĄCZONY/.test(await pz.textContent('#evk-ua h3').catch(() => '')) && /^panel-[a-z2-9]{6}$/.test(pole), pole);
    await pz.fill('#evk-ua-adres', 'wp-admin');
    await pz.click('#evk-ua-form [type=submit]');
    await pz.waitForFunction(() => document.querySelector('.evk-ua-blad').textContent !== '', null, { timeout: 10000 }).catch(() => {});
    t.check('zapis zajętego adresu: komunikat w panelu, opcja bez zmian', /znaczy dla WordPressa/.test(await pz.textContent('.evk-ua-blad')) && sonda('stan').ust.adres === '', await pz.textContent('.evk-ua-blad'));
    await pz.fill('#evk-ua-adres', ADRES);
    pz.once('dialog', (d) => d.accept());
    await Promise.all([pz.waitForNavigation({ timeout: 15000 }).catch(() => {}), pz.click('#evk-ua .evo-toggle')]);
    await pz.waitForSelector('#evk-ua h3');
    const naglowek = await pz.textContent('#evk-ua h3');
    const ck2 = (await pz.context().cookies()).find((c) => c.name === 'evk_ua');
    t.check('włącznik (z potwierdzeniem): WŁĄCZONY po przeładowaniu, a ta przeglądarka od razu dostaje klucz na sesję', /WŁĄCZONY/.test(naglowek) && !!ck2
      && Number(decodeURIComponent(ck2.value).split('|')[0]) > Date.now() / 1000 + 3600, naglowek);
    const z1 = await daj('/wp-login.php');
    t.check('włączony z panelu: wp-login.php bez klucza — 404', z1.kod === 404, z1.kod);
    const kop = await pz.evaluate(() => document.querySelector('.evk-ua-przed').textContent + document.getElementById('evk-ua-adres').value);
    t.check('podany adres do zapisania: adres strony + /?' + ADRES + ' przy zwykłych adresach WordPressa', kop.endsWith('/?' + ADRES) && kop.includes('127.0.0.1'), kop);
    await Promise.all([pz.waitForNavigation({ timeout: 15000 }).catch(() => {}), pz.click('#evk-ua .evo-toggle')]);
    await pz.waitForSelector('#evk-ua h3');
    t.check('wyłączenie włącznikiem (bez pytania): WYŁĄCZONY, wp-login.php znów 200', /WYŁĄCZONY/.test(await pz.textContent('#evk-ua h3')) && (await daj('/wp-login.php')).kod === 200);
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    const sp = sonda('sprzataj');
    t.check('sprzątanie: ukryty adres wyłączony (inne zestawy logują się przez wp-login.php)', sp.wlaczony === false, J(sp));
  }
};
