/**
 * Żądania AJAX Bricksa na stronach w języku (1.253.1) — filtr zapytania,
 * stronicowanie, popup — na PRAWDZIWYM WordPressie przez `php -S`.
 *
 * Zgłoszenie ze strony: na `/en/…` nazwy kategorii są angielskie, a po
 * kliknięciu filtra Bricksa (AJAX, `?_kategoria=…`) wracają po polsku. Bricks
 * wysyła filtr POST-em na `bricksData.restApiUrl` (`/wp-json/bricks/v1/…`),
 * a język wtyczka bierze z pierwszego członu adresu — tu go nie było. Do tego
 * słownik zostawiał w odpowiedzi tokeny `##TL_…##`, które rozwija tylko bufor
 * strony.
 *
 * Bricksa tu nie ma: sonda (tests/php/tl-filtry-bricksa.php) stawia mu-plugin
 * z atrapami jego tras i skryptu. Test sprawdza to, co należy do wtyczki:
 * adres REST na stronie EN, wykrycie języka (prefiks, Referer), odpowiedź
 * obrobioną obiema drogami (serwer REST i `wp_send_json`), brak przekierowań.
 */

const http = require('http');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('tl-filtry-bricksa.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (v) => JSON.stringify(v);

/** GET albo POST przez http — bez przeglądarki, z dowolnymi nagłówkami. */
function zadanie(url, { metoda = 'GET', naglowki = {}, cialo = null } = {}) {
  return new Promise((ok) => {
    const w = { body: '' };
    const req = http.request(url, { method: metoda, headers: Object.assign(cialo !== null ? { 'Content-Type': 'application/json; charset=UTF-8' } : {}, naglowki) }, (r) => {
      w.status = r.statusCode;
      w.location = r.headers.location || '';
      r.on('data', (d) => { if (w.body.length < 600000) w.body += d.toString('utf8'); });
      r.on('end', () => ok(w));
    });
    req.on('error', (e) => { w.status = 'błąd ' + e.code; ok(w); });
    req.setTimeout(20000, () => { w.status = 'timeout'; req.destroy(); });
    if (cialo !== null) req.write(cialo);
    req.end();
  });
}
const json = (w) => { try { return JSON.parse(w.body); } catch (e) { return null; } };
/** Najważniejsze z odpowiedzi atrapy — do sprawdzeń i do opisu, gdy nie przejdą. */
const skrot = (d) => {
  if (!d || typeof d !== 'object') return { brak: 'nie JSON' };
  const h = String(d.html || '');
  const f = String(((d.updated_filters || {}).f1) || '');
  const tekst = (klasa, zrodlo) => ((zrodlo.match(new RegExp('class="' + klasa + '"[^>]*>([^<]*)<')) || [])[1] || '');
  return {
    jezyk: d.jezyk,
    term: tekst('t-term', h),
    tytul: tekst('t-tytul', h),
    link: ((h.match(/class="t-link"><a href="([^"]*)"/) || [])[1] || ''),
    fraza: tekst('t-fraza', h),
    filtr: ((f.match(/<label>([^<]*)</) || [])[1] || ''),
    inline: tekst('t-inline', f),
    obraz: ((h.match(/<img src="([^"]*)"/) || [])[1] || ''),
    styl: String(d.styles || ''),
    tokeny: /##TL_/.test(JSON.stringify(d)),
  };
};

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;

  let serwer = null, browser = null;
  const bledy = [];
  try {
    serwer = await serwerWp.start(wp.wp);
    const b = serwer.baza;
    const prep = sonda('przygotuj ' + b);
    t.check('sonda przygotowała kategorię, wpis, stronę, słownik i obrazki', !prep.brak && prep.gotowe === true, prep.brak || J(prep));
    if (prep.brak || !prep.gotowe) return;
    const plu = sonda('przeplucz ' + b);
    t.check('moduł żądań Bricksa wczytany', plu.tl === true, J(plu));
    const O = prep.obrazy;
    const sciezka = (u) => { try { return new URL(u, b).pathname; } catch (e) { return String(u); } };
    /** Odpowiedź w pełni po angielsku: nazwa, tytuł, link, fraza, `{tl:…}`, obrazek w HTML-u i w stylu, bez tokenów. */
    const poAngielsku = (s) => s.jezyk === 'en' && s.term === 'Services FLT' && s.tytul === 'Post FLT' && sciezka(s.link) === '/en/post-flt/'
      && s.fraza === 'See more FLT' && s.filtr === 'Services FLT' && s.inline === 'Yes FLT'
      && sciezka(s.obraz) === sciezka(O.en) && s.styl.includes(sciezka(O.en)) && !s.styl.includes(sciezka(O.pl)) && !s.tokeny;
    const poPolsku = (s) => s.jezyk === 'pl' && s.term === 'Usługi FLT' && s.tytul === 'Wpis FLT' && sciezka(s.link) === '/wpis-flt/'
      && s.fraza === 'Zobacz więcej FLT' && s.inline === 'Tak FLT' && sciezka(s.obraz) === sciezka(O.pl) && !s.tokeny;

    // ── Adres REST na stronie ───────────────────────────────────────────────
    t.section('strona w języku przestawia adres REST Bricksa');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    p.on('pageerror', (e) => bledy.push(p.url().replace(b, '') + ': ' + e.message));
    await p.goto(b + '/en/strona-flt/', { waitUntil: 'load' });
    const adresEn = await p.evaluate(() => (window.bricksData || {}).restApiUrl || '');
    t.check('/en/…: bricksData.restApiUrl → /en/wp-json/bricks/v1/', sciezka(adresEn) === '/en/wp-json/bricks/v1/', adresEn);

    /* Tak jak Bricks: POST z JSON-em na bricksData.restApiUrl + nazwa trasy, ze strony. */
    const zeStrony = await p.evaluate(async () => {
      const r = await fetch(window.bricksData.restApiUrl + 'query_result', { method: 'POST',
        headers: { 'Content-Type': 'application/json; charset=UTF-8' }, body: JSON.stringify({ postId: 1, lang: false }) });
      return { status: r.status, body: await r.text() };
    });
    const sStrona = skrot(json(zeStrony));
    /* Osobno, bo każda część ma inną przyczynę: język żądania, słownik (tokeny
       z render_data), `{tl:…}` poza render_data, mapa obrazów. */
    t.check('filtr ze strony EN: nazwa kategorii, tytuł i link po angielsku (język żądania)',
      zeStrony.status === 200 && sStrona.jezyk === 'en' && sStrona.term === 'Services FLT' && sStrona.filtr === 'Services FLT'
        && sStrona.tytul === 'Post FLT' && sciezka(sStrona.link) === '/en/post-flt/', zeStrony.status + ' ' + J(sStrona));
    t.check('filtr ze strony EN: fraza słownika przetłumaczona, bez tokenów ##TL_', sStrona.fraza === 'See more FLT' && !sStrona.tokeny, J(sStrona));
    t.check('filtr ze strony EN: {tl:…} rozwinięte po angielsku', sStrona.inline === 'Yes FLT', J(sStrona));
    t.check('filtr ze strony EN: obrazek z mapy obrazów — w HTML-u i w stylu',
      sciezka(sStrona.obraz) === sciezka(O.en) && sStrona.styl.includes(sciezka(O.en)) && !sStrona.styl.includes(sciezka(O.pl)), J(sStrona));

    await p.goto(b + '/strona-flt/', { waitUntil: 'load' });
    const adresPl = await p.evaluate(() => (window.bricksData || {}).restApiUrl || '');
    t.check('strona polska: adres REST bez zmian', sciezka(adresPl) === '/wp-json/bricks/v1/', adresPl);
    const plStrona = await zadanie(b + '/strona-flt/');
    t.check('strona polska: bez skryptu przestawiającego adres', plStrona.status === 200 && !plStrona.body.includes('evk-tl-bricks-rest'), String(plStrona.status));

    // ── Język z Referera ────────────────────────────────────────────────────
    t.section('adres REST bez prefiksu: język ze strony, z której przyszło żądanie');
    const post = (sciezkaRest, naglowki = {}) => zadanie(b + sciezkaRest, { metoda: 'POST', naglowki, cialo: '{}' });
    let w = await post('/wp-json/bricks/v1/query_result', { Referer: b + '/en/strona-flt/' });
    t.check('Referer /en/… (strona sprzed 1.253.1 z pamięci podręcznej) → odpowiedź po angielsku', w.status === 200 && poAngielsku(skrot(json(w))), w.status + ' ' + J(skrot(json(w))));
    w = await post('/wp-json/bricks/v1/query_result');
    t.check('bez Referera → po polsku, jak dotąd', w.status === 200 && poPolsku(skrot(json(w))), w.status + ' ' + J(skrot(json(w))));
    w = await post('/wp-json/bricks/v1/query_result', { Referer: 'http://obca.example/en/strona-flt/' });
    t.check('Referer z obcego hosta → po polsku', w.status === 200 && skrot(json(w)).jezyk === 'pl', w.status + ' ' + J(skrot(json(w))));
    w = await post('/wp-json/bricks/v1/query_result', { Referer: b + '/en/strona-flt/?bricks=run' });
    t.check('Referer z buildera → po polsku (builder pracuje na oryginale)', w.status === 200 && skrot(json(w)).jezyk === 'pl', w.status + ' ' + J(skrot(json(w))));
    w = await post('/?rest_route=/bricks/v1/query_result', { Referer: b + '/en/strona-flt/' });
    t.check('adres REST w postaci ?rest_route= + Referer /en/… → po angielsku', w.status === 200 && poAngielsku(skrot(json(w))), w.status + ' ' + J(skrot(json(w))));

    // ── Odpowiedź wysłana z pominięciem serwera REST ────────────────────────
    t.section('popup: odpowiedź wysłana z pominięciem serwera REST (wp_send_json)');
    w = await post('/en/wp-json/bricks/v1/load_popup_content');
    t.check('popup z adresu EN → po angielsku, bez tokenów', w.status === 200 && poAngielsku(skrot(json(w))), w.status + ' ' + J(skrot(json(w))));
    t.check('popup: pusty obiekt „popups” zostaje obiektem {} (Bricks czyta go Object.entries)', /"popups":\{\}/.test(w.body), (w.body.match(/"popups":[^,]*/) || [''])[0]);
    w = await post('/wp-json/bricks/v1/load_popup_content', { Referer: b + '/en/strona-flt/' });
    t.check('popup z adresu bez prefiksu + Referer /en/… → po angielsku', w.status === 200 && poAngielsku(skrot(json(w))), w.status + ' ' + J(skrot(json(w))));

    // ── Bez przekierowań ────────────────────────────────────────────────────
    t.section('żądania REST bez przekierowań');
    w = await post('/wp-json/bricks/v1/query_result?lang=en');
    t.check('?lang=en na adresie REST: bez 301 (przekierowanie POST-a gubi ciało), odpowiedź po angielsku',
      w.status === 200 && !w.location && skrot(json(w)).jezyk === 'en', w.status + ' ' + w.location + ' ' + J(skrot(json(w))));

    t.check('bez błędów JS na stronach', !bledy.length, bledy.join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
