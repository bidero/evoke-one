/**
 * Blokowanie REST API (1.281.0) — prawdziwe odpowiedzi testowego WordPressa
 * przez php -S, zapis prawdziwym AJAX-em i z zakładki Bezpieczeństwo → REST API
 * w Chromium.
 *
 * Usterka, od której to wyszło: zapis przepuszczał trasy przez
 * sanitize_text_field(), więc „/wp/v2/users/(?P<id>[\d]+)” zapisywało się bez
 * „<id>” (jak znacznik HTML), wyrażenie przestawało pasować i gość dalej
 * dostawał konto z /wp/v2/users/1, choć pole było zaznaczone.
 *
 * Decyzje zgłaszającego (02.10): blokada całości z wyjątkami (domyślnie
 * bricks/v1, wc/store, contact-form-7/v1, oembed/1.0, evoke/v1, do edycji);
 * „Blokuj wyliczanie użytkowników” domyślnie włączone — REST, ?author=N, mapa strony.
 */

const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('rest-api.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const TRASA_ID = '/wp/v2/users/(?P<id>[\\d]+)';

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress przez php -S');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  let serwer = null;
  let browser = null;
  try {
    const d0 = sonda('domyslne');
    t.check('stan wyjściowy: bez zapisanych pól REST, hasło aplikacji admina', ['{}', '[]'].includes(J(d0.zapisane)) && !!d0.haslo, J(d0.zapisane));
    /* Hasła aplikacji WordPress przyjmuje przez HTTP tylko w środowisku `local`. */
    serwer = await serwerWp.start(wp.wp, { env: { EVK_WP_SRODOWISKO: 'local' } });
    const auth = 'Basic ' + Buffer.from('admin:' + d0.haslo).toString('base64');
    const kod = async (trasa, zalogowany) => {
      const r = await fetch(serwer.baza + '/?rest_route=' + encodeURIComponent(trasa), { redirect: 'manual', headers: zalogowany ? { Authorization: auth } : {} });
      await r.text();
      return r.status;
    };
    const kody = async (trasy) => { const o = {}; for (const tr of trasy) o[tr] = await kod(tr); return o; };

    t.section('domyślnie (bez zapisu): wyliczanie użytkowników zablokowane');
    const k0 = await kody(['/wp/v2/users', '/wp/v2/users/' + d0.id, '/wp/v2/posts', '/']);
    console.log('      gość: ' + J(k0));
    t.check('gość: /wp/v2/users i /wp/v2/users/ID → 401; wpisy i indeks dalej 200',
      J(k0) === J({ '/wp/v2/users': 401, ['/wp/v2/users/' + d0.id]: 401, '/wp/v2/posts': 200, '/': 200 }), J(k0));
    const zal = await kod('/wp/v2/users', true);
    t.check('zalogowany (hasło aplikacji): /wp/v2/users → 200', zal === 200, zal);
    const autor = await fetch(serwer.baza + '/?author=' + d0.id, { redirect: 'manual' });
    const autorTresc = await autor.text();
    t.check('gość: ?author=ID → 404 (bez przekierowania na /author/login)', autor.status === 404 && !autor.headers.get('location'),
      autor.status + ' ' + (autor.headers.get('location') || ''));
    t.check('strona 404 nie zdradza loginu', !/\badmin\b/.test(autorTresc.replace(/wp-admin|admin-ajax|admin-bar|adminbar/gi, '')), '');
    /* Zwykłe adresy testowego WordPressa: mapa strony pod ?sitemap=index. */
    const mapa = await fetch(serwer.baza + '/?sitemap=index');
    const mapaTresc = await mapa.text();
    t.check('mapa strony WordPressa bez autorów (jest, ale bez sitemap=users)', mapa.status === 200 && /sitemap=posts/.test(mapaTresc)
      && !/sitemap=users/.test(mapaTresc), mapa.status + ' ' + mapaTresc.slice(0, 200));
    t.check('dostawcy mapy strony bez „users”', J(sonda('stan').mapa || []).indexOf('"users"') === -1);

    t.section('zapis wybranych tras (prawdziwy AJAX zakładki)');
    const z1 = sonda('ajax', J({ rest_block_all: 0, rest_uzytkownicy: 0, disabled_rest_endpoints: [TRASA_ID, '/wp/v2/pages', '/nieistnieje', 'bez-ukosnika'],
      rest_wyjatki: 'bricks/v1' }));
    t.check('zapis przyjęty', z1.odp && z1.odp.success === true, J(z1.odp));
    t.check('trasa z parametrem zapisana DOKŁADNIE (z „<id>” i „\\d”), nieistniejące odrzucone',
      J((z1.zapisane || {}).disabled_rest_endpoints) === J([TRASA_ID, '/wp/v2/pages']), J(z1.zapisane));
    const k1 = await kody(['/wp/v2/users/' + d0.id, '/wp/v2/users', '/wp/v2/pages', '/wp/v2/posts']);
    t.check('gość: users/ID i pages → 401; users (wyliczanie wyłączone, trasa niezaznaczona) i posts → 200',
      J(k1) === J({ ['/wp/v2/users/' + d0.id]: 401, '/wp/v2/users': 200, '/wp/v2/pages': 401, '/wp/v2/posts': 200 }), J(k1));
    const autor1 = await fetch(serwer.baza + '/?author=' + d0.id, { redirect: 'manual' });
    await autor1.text();
    t.check('wyliczanie wyłączone: ?author=ID znów nie daje 404', autor1.status !== 404, autor1.status);
    /* Indeks mapy wymienia autorów tylko z opublikowanymi wpisami — tu pytanie do rejestru dostawców. */
    const mapa1 = sonda('stan').mapa || [];
    t.check('wyliczanie wyłączone: dostawca „users” wraca do mapy strony', mapa1.includes('users'), J(mapa1));

    t.section('blokada całości z wyjątkami');
    const z2 = sonda('ajax', J({ rest_block_all: 1, rest_uzytkownicy: 1, disabled_rest_endpoints: [],
      rest_wyjatki: 'bricks/v1\nwc/store\ncontact-form-7/v1\noembed/1.0\nevoke/v1' }));
    t.check('zapis przyjęty', z2.odp && z2.odp.success === true, J(z2.odp));
    const k2 = await kody(['/', '/wp/v2/posts', '/wp/v2/users', '/oembed/1.0']);
    t.check('gość: indeks, wpisy, użytkownicy → 401; oEmbed (wyjątek) → 200',
      J(k2) === J({ '/': 401, '/wp/v2/posts': 401, '/wp/v2/users': 401, '/oembed/1.0': 200 }), J(k2));
    t.check('zalogowany: wpisy → 200 mimo blokady', await kod('/wp/v2/posts', true) === 200);
    const z3 = sonda('ajax', J({ rest_block_all: 1, rest_uzytkownicy: 1, disabled_rest_endpoints: [], rest_wyjatki: ' /WP/v2/posts/ \nzle!!\n/oembed/1.0/\r\noembed/1.0' }));
    t.check('wyjątki: ukośniki i wielkość liter zdjęte, złe linie i powtórki odrzucone',
      J((z3.zapisane || {}).rest_wyjatki) === J(['wp/v2/posts', 'oembed/1.0']), J(z3.zapisane));
    const k3 = await kody(['/wp/v2/posts', '/wp/v2/pages']);
    t.check('gość: wpisy (wyjątek) → 200, strony → 401', J(k3) === J({ '/wp/v2/posts': 200, '/wp/v2/pages': 401 }), J(k3));
    const tr = sonda('trasy', J([
      ['/bricks/v1x/load', { rest_block_all: 1, rest_wyjatki: ['bricks/v1'] }],
      ['/bricks/v1/load_query_page', { rest_block_all: 1, rest_wyjatki: ['bricks/v1'] }],
      ['/bricks/v1', { rest_block_all: 1, rest_wyjatki: ['bricks/v1'] }],
      ['/WP/V2/Users/1', { rest_uzytkownicy: 1, rest_block_all: 0 }],
      ['/wp/v2/usersx', { rest_uzytkownicy: 1, rest_block_all: 0 }],
      ['/wp/v2/users/1/x', { rest_block_all: 0, disabled_rest_endpoints: [TRASA_ID] }],
    ])).wyniki;
    t.check('dopasowanie: wyjątek tylko jako cała przestrzeń, users bez względu na wielkość liter, trasa z listy w całości',
      J(tr) === J([ 'evk_rest_disabled', null, null, 'evk_rest_users', null, null ]), J(tr));

    t.section('zakładka Bezpieczeństwo → REST API w Chromium');
    sonda('domyslne');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const s = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    s.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(s, serwer.baza);
    const adres = serwer.baza + '/wp-admin/options-general.php?page=evoke-one&tab=bezpieczenstwo&sub=rest';
    const stan = () => s.evaluate((tid) => ({
      calosc: document.querySelector('[name="evk_security[rest_block_all]"]').checked,
      uzytkownicy: document.querySelector('[name="evk_security[rest_uzytkownicy]"]').checked,
      wyjatki: document.querySelector('#evk-rest-wyjatki').value,
      id: !!([...document.querySelectorAll('.evk-rest-ep')].find((x) => x.value === tid) || {}).checked,
      trasaJest: [...document.querySelectorAll('.evk-rest-ep')].some((x) => x.value === tid),
    }), TRASA_ID);
    await s.goto(adres);
    const s0 = await stan();
    t.check('zakładka: domyślnie wyliczanie zablokowane, całość nie, pięć wyjątków w polu',
      s0.uzytkownicy === true && s0.calosc === false && s0.wyjatki === 'bricks/v1\nwc/store\ncontact-form-7/v1\noembed/1.0\nevoke/v1' && s0.trasaJest, J(s0));
    await s.check('.evk-rest-ep[value="' + TRASA_ID.replace(/\\/g, '\\\\') + '"]');
    await s.click('label.evo-toggle:has([name="evk_security[rest_uzytkownicy]"]) .evo-slider');
    await s.fill('#evk-rest-wyjatki', 'bricks/v1\nwp/v2/posts');
    await s.click('#evk-sec-form-rest [type=submit]');
    await s.waitForSelector('#evk-sec-form-rest .evk-sec-saved', { state: 'visible', timeout: 10000 }).catch(() => {});
    const st = sonda('stan').zapisane || {};
    t.check('zapis z zakładki: trasa z parametrem dokładnie, wyliczanie wyłączone, dwa wyjątki',
      J(st.disabled_rest_endpoints) === J([TRASA_ID]) && st.rest_uzytkownicy === 0 && st.rest_block_all === 0
      && J(st.rest_wyjatki) === J(['bricks/v1', 'wp/v2/posts']), J(st));
    await s.goto(adres);
    const s1 = await stan();
    t.check('po przeładowaniu trasa z parametrem dalej ZAZNACZONA (przed 1.281.0 — odznaczona)', s1.id === true && s1.uzytkownicy === false
      && s1.wyjatki === 'bricks/v1\nwp/v2/posts', J(s1));
    t.check('gość: users/ID → 401 po zapisie z zakładki', await kod('/wp/v2/users/' + d0.id) === 401);
    t.check('bez błędów JS', bledy.length === 0, J(bledy));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
