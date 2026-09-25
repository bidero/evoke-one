/**
 * Meta w <head> (1.236.0), przez PRAWDZIWY serwer HTTP: twitter:card,
 * og:locale z wersjami językowymi, wymiary i alt obrazka OG, opis i OG na
 * archiwach.
 *
 * Do 1.235.0:
 *   — bez twitter:card X pokazywał link bez dużego obrazka;
 *   — bez og:locale Facebook brał stronę za en_US;
 *   — bez og:image:width/height Facebook przy pierwszym udostępnieniu
 *     często dawał link bez obrazka (przetwarza go w tle);
 *   — kategorie, tagi, archiwa typów i strona główna z ostatnimi wpisami nie
 *     miały ani opisu, ani OG.
 * Archiwa dat i autorów zostają bez zmian — tego też pilnuje test.
 *
 * Środowisko: tools/testowy-wp.sh. Sonda: tests/php/seo-meta-glowa.php.
 */

const http = require('http');
const { phpOutput } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('seo-meta-glowa.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};

function pobierz(url) {
  return new Promise((ok) => {
    const w = { body: '' };
    const req = http.get(url, (r) => {
      w.status = r.statusCode;
      r.on('data', (d) => { if (w.body.length < 400000) w.body += d.toString('utf8'); });
      r.on('end', () => ok(w));
    });
    req.on('error', (e) => { w.status = 'błąd ' + e.code; ok(w); });
    req.setTimeout(20000, () => { w.status = 'timeout'; req.destroy(); });
  });
}

/** Meta z <head>: { 'og:title': ['…'], 'description': ['…'], … } — wartości w kolejności wystąpień. */
function meta(html) {
  const glowa = (html.split('</head>')[0] || '');
  const wynik = {};
  for (const m of glowa.matchAll(/<meta\s+(?:property|name)="([^"]+)"\s+content="([^"]*)"\s*\/?>/g)) {
    const tekst = m[2].replace(/&amp;/g, '&').replace(/&#039;/g, "'").replace(/&quot;/g, '"').replace(/&#8230;/g, '…');
    (wynik[m[1]] = wynik[m[1]] || []).push(tekst);
  }
  return wynik;
}
const sciezka = (u) => { try { const x = new URL(u); return x.pathname + x.search; } catch (e) { return String(u); } };
const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);

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
    t.check('sonda przygotowała stronę', !prep.brak && !!prep.wpis1, prep.brak || 'jest');
    if (prep.brak || !prep.wpis1) return;
    Object.assign(prep, sonda('przeplucz ' + b));
    const strona = async (u) => {
      const w = await pobierz(b + sciezka(u));
      return Object.assign(meta(w.body), { status: w.status });
    };
    const opis = (m, klucze) => JSON.stringify(Object.fromEntries([['status', m.status]].concat(klucze.map((k) => [k, m[k]]))));

    t.section('wpis: język, karta X, obrazek z wymiarami i alt');
    const w1 = await strona(prep.wpis1);
    t.check('og:locale pl_PL (język witryny), bez wersji językowych', jak(w1['og:locale'], ['pl_PL']) && !w1['og:locale:alternate'],
      opis(w1, ['og:locale', 'og:locale:alternate']));
    t.check('twitter:card: duży obrazek', jak(w1['twitter:card'], ['summary_large_image']), opis(w1, ['twitter:card']));
    t.check('miniatura z biblioteki mediów: wymiary i alt z załącznika',
      jak((w1['og:image'] || []).map(sciezka), [sciezka(prep.miniatura)]) && jak(w1['og:image:width'], ['800'])
        && jak(w1['og:image:height'], ['500']) && jak(w1['og:image:alt'], ['Alt miniatury SEO']),
      opis(w1, ['og:image', 'og:image:width', 'og:image:height', 'og:image:alt']));
    t.check('każdy tag OG raz (bez dublowania)', ['og:title', 'og:type', 'og:url', 'og:image', 'twitter:card'].every((k) => (w1[k] || []).length === 1),
      opis(w1, ['og:title', 'og:image', 'twitter:card']));
    const w2 = await strona(prep.wpis2);
    t.check('obrazek generatora OG (plik z ?v=, nie załącznik): wymiary z pliku',
      /\?v=\d+$/.test((w2['og:image'] || [''])[0]) && jak(w2['og:image:width'], ['1200']) && jak(w2['og:image:height'], ['630']),
      opis(w2, ['og:image', 'og:image:width', 'og:image:height']));
    t.check('obrazek bez tekstu alternatywnego: alt = tytuł strony', jak(w2['og:image:alt'], w2['og:title']) && !!w2['og:title'],
      opis(w2, ['og:image:alt', 'og:title']));

    t.section('archiwa: opis i OG');
    const kat = await strona(prep.kategoria);
    t.check('kategoria: opis bez HTML-a z opisu kategorii', jak(kat['description'], ['Opis kategorii SEO-7742 z HTML-em i drugim akapitem.'])
      && jak(kat['og:description'], kat['description']), opis(kat, ['description', 'og:description']));
    t.check('kategoria: og:title, og:type website, og:url adres kategorii',
      jak(kat['og:title'], ['Kategoria SEO']) && jak(kat['og:type'], ['website']) && jak((kat['og:url'] || []).map(sciezka), [sciezka(prep.kategoria)]),
      opis(kat, ['og:title', 'og:type', 'og:url']));
    t.check('kategoria: obrazek domyślny OG z wymiarami i alt, duża karta, pl_PL',
      jak((kat['og:image'] || []).map(sciezka), [sciezka(prep.domyslny)]) && jak(kat['og:image:width'], ['1200'])
        && jak(kat['og:image:alt'], ['Alt obrazka domyślnego SEO']) && jak(kat['twitter:card'], ['summary_large_image'])
        && jak(kat['og:locale'], ['pl_PL']),
      opis(kat, ['og:image', 'og:image:width', 'og:image:alt', 'twitter:card', 'og:locale']));
    const kat2 = await strona(sciezka(prep.kategoria) + 'page/2/');
    t.check('kategoria, strona 2: og:url tej strony', ((kat2['og:url'] || [''])[0]).endsWith('/kategoria-seo/page/2/'), opis(kat2, ['og:url']));
    const tag = await strona(prep.tag);
    t.check('tag bez opisu: OG jest, pustego opisu nie ma', jak(tag['og:title'], ['Tag SEO']) && !tag['description'] && !tag['og:description'],
      opis(tag, ['og:title', 'description', 'og:description']));
    const typ = await strona(prep.katalog);
    t.check('archiwum typu treści: opis typu, tytuł i adres archiwum',
      jak(typ['description'], ['Opis archiwum typu SEO-7743']) && jak(typ['og:title'], ['Katalog SEO'])
        && jak((typ['og:url'] || []).map(sciezka), [sciezka(prep.katalog)]),
      opis(typ, ['description', 'og:title', 'og:url']));
    const dom = await strona('/');
    t.check('strona główna z ostatnimi wpisami (do 1.235.0: nic): slogan, nazwa, adres główny',
      jak(dom['description'], ['Slogan strony SEO-7740']) && jak(dom['og:title'], [prep.nazwa]) && jak((dom['og:url'] || []).map(sciezka), ['/'])
        && jak(dom['og:type'], ['website']),
      opis(dom, ['description', 'og:title', 'og:url', 'og:type']));
    const autor = await strona('/author/admin/');
    const data = await strona('/' + new Date().getFullYear() + '/');
    t.check('archiwa autora i dat bez zmian: bez OG', !autor['og:title'] && !data['og:title'], opis(autor, ['og:title']) + opis(data, ['og:title']));

    sonda('bez-obrazka');
    const bez = await strona(prep.kategoria);
    t.check('bez domyślnego obrazka: bez og:image i mała karta X', !bez['og:image'] && jak(bez['twitter:card'], ['summary']),
      opis(bez, ['og:image', 'twitter:card']));

    t.section('Tłumaczenia: język wersji i og:locale:alternate');
    sonda('tl-wl');
    const tl = sonda('tl-przeplucz');
    t.check('moduł Tłumaczeń wczytany', tl.tl === true, JSON.stringify(tl));
    const pl = await strona(prep.strona);
    t.check('wersja polska: pl_PL, alternatywy en_US i de_DE (fr bez regionu pominięty)',
      jak(pl['og:locale'], ['pl_PL']) && jak(pl['og:locale:alternate'], ['en_US', 'de_DE']),
      opis(pl, ['og:locale', 'og:locale:alternate']));
    const en = await strona('/en' + sciezka(prep.strona));
    t.check('wersja angielska: en_US, alternatywy pl_PL i de_DE',
      jak(en['og:locale'], ['en_US']) && jak(en['og:locale:alternate'], ['pl_PL', 'de_DE']),
      opis(en, ['og:locale', 'og:locale:alternate']));
    const enDom = await strona('/en/');
    t.check('strona główna /en/: en_US', jak(enDom['og:locale'], ['en_US']), opis(enDom, ['og:locale', 'og:title']));
  } finally {
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
