/**
 * Adresy wersji językowych z członami z mapy adresów (1.251.0), przez
 * PRAWDZIWY serwer HTTP.
 *
 * WordPress odcina prefiks `/en` sam (filtr `home_url` dokleja go do adresu
 * strony głównej, a parser żądania zdejmuje z adresu jej ścieżkę), więc wersja
 * językowa idzie przez zwykłe reguły. Do 1.250.0 z powrotem na polski
 * tłumaczone były wyłącznie `pagename` i `name`:
 *   — `/en/category/services/` (mapa: uslugi → services) odpowiadało 404,
 *     a przekierowanie 302 z `/en/category/uslugi/` prowadziło prosto w ten 404;
 *   — tak samo człon własnego typu treści (`/en/projects/…`);
 *   — linki termów i typów treści miały sam prefiks, bez członów z mapy.
 * Wpisy i strony działały — test pilnuje, że dalej działają.
 *
 * Środowisko: tools/testowy-wp.sh. Sonda: tests/php/tl-adresy.php.
 */

const http = require('http');
const { phpOutput } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('tl-adresy.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};

/** Jedno żądanie BEZ podążania za przekierowaniem. */
function pobierz(url) {
  return new Promise((ok) => {
    const w = { body: '' };
    const req = http.get(url, (r) => {
      w.status = r.statusCode;
      w.location = r.headers.location || '';
      r.on('data', (d) => { if (w.body.length < 400000) w.body += d.toString('utf8'); });
      r.on('end', () => ok(w));
    });
    req.on('error', (e) => { w.status = 'błąd ' + e.code; ok(w); });
    req.setTimeout(20000, () => { w.status = 'timeout'; req.destroy(); });
  });
}

/** Meta z <head>: { 'og:url': ['…'], … }. */
function meta(html) {
  const glowa = (html.split('</head>')[0] || '');
  const wynik = {};
  for (const m of glowa.matchAll(/<meta\s+(?:property|name)="([^"]+)"\s+content="([^"]*)"\s*\/?>/g)) {
    (wynik[m[1]] = wynik[m[1]] || []).push(m[2].replace(/&amp;/g, '&'));
  }
  for (const m of glowa.matchAll(/<link rel="alternate" hreflang="([^"]+)" href="([^"]*)"/g)) wynik['hreflang:' + m[1]] = [m[2]];
  return wynik;
}
const sciezka = (u) => { try { const x = new URL(u); return x.pathname + x.search; } catch (e) { return String(u); } };

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;

  let serwer = null;
  try {
    serwer = await serwerWp.start(wp.wp);
    const b = serwer.baza;
    const prep = sonda('przygotuj ' + b);
    t.check('sonda przygotowała stronę', !prep.brak && prep.gotowe === true, prep.brak || JSON.stringify(prep));
    if (prep.brak || !prep.gotowe) return;
    const plu = sonda('przeplucz ' + b);
    t.check('moduł Tłumaczeń wczytany, reguły przepłukane', plu.tl === true, JSON.stringify(plu));

    const strona = async (u) => {
      const w = await pobierz(b + u);
      return Object.assign(meta(w.body), { status: w.status, location: sciezka(w.location) });
    };
    const opis = (m, klucze) => JSON.stringify(Object.fromEntries([['status', m.status], ['location', m.location]]
      .concat(klucze.map((k) => [k, m[k]]))));
    /* Dwa osobne sprawdzenia na adres: „nie 404" (człon wraca na polski przed
       regułami) i „bez przekierowania" (po dopasowaniu wraca oryginalny adres —
       inaczej przekierowanie 302 widzi człon polski i odsyła adres sam do siebie). */
    const adres = async (nazwa, u, tytul) => {
      const m = await strona(u);
      t.check(nazwa + ': ' + u + ' trafia w regułę (nie 404)', m.status !== 404, opis(m, []));
      t.check(nazwa + ': ' + u + ' bez przekierowania, 200 z właściwą treścią',
        m.status === 200 && !m.location && (m['og:title'] || [])[0] === tytul, opis(m, ['og:title']));
      return m;
    };

    t.section('kategorie i tagi: człon z mapy w wersji językowej');
    const kat = await adres('kategoria', '/en/category/services-adr/', 'Usługi ADR');
    t.check('kategoria: og:url z członem z mapy (link termu)', (kat['og:url'] || []).map(sciezka)[0] === '/en/category/services-adr/',
      opis(kat, ['og:url']));
    t.check('kategoria: hreflang pl i de z członami swoich języków',
      sciezka((kat['hreflang:pl'] || [''])[0]) === '/category/uslugi-adr/'
        && sciezka((kat['hreflang:de-DE'] || [''])[0]) === '/de/category/dienstleistungen-adr/',
      opis(kat, ['hreflang:pl', 'hreflang:de-DE']));
    await adres('podkategoria', '/en/category/services-adr/design-adr/', 'Projektowanie ADR');
    await adres('kategoria, strona 2', '/en/category/services-adr/page/2/', 'Usługi ADR');
    await adres('kategoria po niemiecku', '/de/category/dienstleistungen-adr/', 'Usługi ADR');
    const tag = await adres('tag', '/en/tag/label-adr/', 'Etykieta ADR');
    t.check('tag: og:url z członem z mapy', (tag['og:url'] || []).map(sciezka)[0] === '/en/tag/label-adr/', opis(tag, ['og:url']));

    t.section('własny typ treści: człon typu i wpisu z mapy');
    const dom = await adres('wpis typu', '/en/projects-adr/house-adr/', 'Dom ADR');
    t.check('wpis typu: og:url z członami z mapy (link wpisu)', (dom['og:url'] || []).map(sciezka)[0] === '/en/projects-adr/house-adr/',
      opis(dom, ['og:url']));
    const arch = await strona('/en/projects-adr/');
    t.check('archiwum typu: /en/projects-adr/ odpowiada 200', arch.status === 200 && !arch.location, opis(arch, ['og:url']));
    t.check('archiwum typu: og:url z członem z mapy (link archiwum)', (arch['og:url'] || []).map(sciezka)[0] === '/en/projects-adr/',
      opis(arch, ['og:url']));

    t.section('wpisy i strony działają jak dotąd');
    await adres('strona', '/en/page-adr/', 'Strona ADR');
    await adres('wpis', '/en/post-adr-1/', 'Wpis ADR 1');

    t.section('polska wersja i adresy spoza mapy bez zmian');
    const plKat = await adres('kategoria PL', '/category/uslugi-adr/', 'Usługi ADR');
    t.check('kategoria PL: og:url polski', (plKat['og:url'] || []).map(sciezka)[0] === '/category/uslugi-adr/', opis(plKat, ['og:url']));
    await adres('wpis typu PL', '/realizacje-adr/dom-adr/', 'Dom ADR');
    const stary = await strona('/en/category/uslugi-adr/');
    t.check('polski człon pod /en/: 302 na człon z mapy (który teraz odpowiada)',
      stary.status === 302 && stary.location === '/en/category/services-adr/', opis(stary, []));
    const brak = await strona('/en/category/nie-ma-adr/');
    t.check('nieistniejąca kategoria pod /en/ dalej 404', brak.status === 404, opis(brak, []));
  } finally {
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
