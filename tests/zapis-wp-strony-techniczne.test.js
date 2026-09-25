/**
 * Strony techniczne (1.235.0): Kokpit i strona zasłony konserwacji tylko dla
 * zalogowanych, przez PRAWDZIWY serwer HTTP.
 *
 * Do 1.234.1 obie były zwykłymi stronami: pod własnym adresem widział je
 * każdy, Google je indeksował, siedziały w mapie strony, a zasłona pokazywała
 * „Przerwa techniczna" także przy wyłączonej konserwacji.
 *
 * Niezalogowany ma dostać zwykłe 404 na KAŻDEJ drodze do treści strony —
 * tak, żeby nie dało się odróżnić jej od adresu, którego nie ma: ładny adres,
 * adres bez ukośnika i fragment adresu (WordPress zgaduje przy 404 i robi
 * 301), ?page_id=, REST (pojedyncza strona z pełną treścią, lista,
 * wyszukiwanie), wyszukiwarka, lista stron w menu motywu, oEmbed. Mapa strony
 * pomija je zawsze, także dla zalogowanego. Zalogowany widzi obie jak dotąd,
 * a Kokpit ładuje się w iframe na pulpicie.
 *
 * Najpierw warunek wstępny na zwykłej stronie: te same drogi naprawdę
 * pokazują treść albo przekierowują — inaczej test przeszedłby na pusto.
 *
 * Środowisko: tools/testowy-wp.sh. Sonda: tests/php/zapis-wp-strony-techniczne.php.
 */

const http = require('http');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('zapis-wp-strony-techniczne.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};

/** GET bez ciasteczek (jak odwiedzający i robot) i bez podążania za przekierowaniem. */
function pobierz(url) {
  return new Promise((ok) => {
    const w = { body: '' };
    const req = http.get(url, (r) => {
      w.status = r.statusCode; w.naglowki = r.headers;
      r.on('data', (d) => { if (w.body.length < 400000) w.body += d.toString('utf8'); });
      r.on('end', () => ok(w));
    });
    req.on('error', (e) => { w.status = 'błąd ' + e.code; ok(w); });
    req.setTimeout(20000, () => { w.status = 'timeout'; req.destroy(); });
  });
}
const json = (w) => { try { return JSON.parse(w.body); } catch (e) { return null; } };
const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const opis = (w) => JSON.stringify({ status: w.status, location: (w.naglowki || {}).location });

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');
  const prep = sonda('przygotuj');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !prep.brak && !!prep.wp, prep.brak || prep.wp);
  if (prep.brak || !prep.wp) return;

  let serwer = null;
  let browser;
  try {
    serwer = await serwerWp.start(prep.wp);
    const b = serwer.baza;
    const rest = (sciezka) => b + '/?rest_route=' + sciezka;
    const oembed = (sciezka) => rest('/oembed/1.0/embed') + '&url=' + encodeURIComponent(b + sciezka);
    const mapa = b + '/?sitemap=posts&sitemap-subtype=page&paged=1';

    t.section('warunek wstępny: zwykła strona na tych samych drogach');
    const z = await pobierz(b + '/zwykla-strona-techtest/');
    t.check('ładny adres: 200 z treścią', z.status === 200 && z.body.includes('TRESC-ZWYKLA-7733'), opis(z));
    const zBez = await pobierz(b + '/zwykla-strona-techtest');
    t.check('bez ukośnika: 301 na adres z ukośnikiem', zBez.status === 301 && /\/zwykla-strona-techtest\/$/.test(zBez.naglowki.location || ''), opis(zBez));
    const zFrag = await pobierz(b + '/zwykla-strona-tech');
    t.check('fragment adresu: WordPress zgaduje i robi 301', zFrag.status === 301 && /\/zwykla-strona-techtest\/$/.test(zFrag.naglowki.location || ''), opis(zFrag));
    const zRest = await pobierz(rest('/wp/v2/pages/' + prep.zwykla));
    t.check('REST: strona z treścią', zRest.status === 200 && zRest.body.includes('TRESC-ZWYKLA-7733'), opis(zRest));
    const zSzuk = await pobierz(b + '/?s=Techxq');
    t.check('wyszukiwarka znajduje zwykłą stronę', zSzuk.status === 200 && zSzuk.body.includes('Zwykła strona Techxq'), opis(zSzuk));
    const zEmbed = await pobierz(oembed('/zwykla-strona-techtest/'));
    t.check('oEmbed: 200', zEmbed.status === 200, opis(zEmbed));

    t.section('niezalogowany: strony techniczne to zwykłe 404');
    const nieMa = await pobierz(rest('/wp/v2/pages/99999999'));
    const bledy404 = [];
    for (const [nazwa, sciezka, znacznik] of [
      ['Kokpit pod ładnym adresem', '/kokpit-testowy/', 'TRESC-KOKPITU-7731'],
      ['Kokpit bez ukośnika (bez 301, które zdradza stronę)', '/kokpit-testowy', 'TRESC-KOKPITU-7731'],
      ['Kokpit z fragmentu adresu (bez zgadywania)', '/kokpit-test', 'TRESC-KOKPITU-7731'],
      ['Kokpit przez ?page_id=', '/?page_id=' + prep.kokpit, 'TRESC-KOKPITU-7731'],
      ['Kokpit przez ?p=', '/?p=' + prep.kokpit, 'TRESC-KOKPITU-7731'],
      ['zasłona przy wyłączonej konserwacji', '/zaslona-testowa/', 'TRESC-ZASLONY-7732'],
    ]) {
      const w = await pobierz(b + sciezka);
      const ok = w.status === 404 && !(w.naglowki || {}).location && !w.body.includes(znacznik);
      if (!ok) bledy404.push(nazwa + ' ' + opis(w));
      t.check(nazwa + ': 404, bez Location i bez treści', ok, opis(w));
    }
    const kRest = await pobierz(rest('/wp/v2/pages/' + prep.kokpit));
    t.check('REST: Kokpit jak nieistniejąca strona (404, ten sam kod błędu)',
      kRest.status === 404 && !kRest.body.includes('TRESC-KOKPITU') && (json(kRest) || {}).code === (json(nieMa) || {}).code,
      opis(kRest) + ' ' + kRest.body.slice(0, 120) + ' | nieistniejąca: ' + nieMa.body.slice(0, 80));
    const listaW = await pobierz(rest('/wp/v2/pages') + '&per_page=100');
    const lista = json(listaW) || [];
    const idListy = Array.isArray(lista) ? lista.map((p) => p.id) : [];
    t.check('REST: lista stron bez Kokpitu i zasłony', idListy.includes(prep.zwykla) && !idListy.includes(prep.kokpit) && !idListy.includes(prep.zaslona),
      JSON.stringify(idListy));
    /* Licznik liczy SQL, a nie wynik: bez wykluczenia w samym zapytaniu
       (pre_get_posts) nagłówek mówiłby o dwóch stronach więcej, niż widać. */
    const naglowekLiczby = Number((listaW.naglowki || {})['x-wp-total']);
    t.check('REST: licznik stron (X-WP-Total) nie liczy ukrytych', naglowekLiczby === idListy.length,
      JSON.stringify({ 'x-wp-total': naglowekLiczby, widac: idListy.length }));
    const szukRest = json(await pobierz(rest('/wp/v2/search') + '&search=Techxq')) || [];
    const idSzuk = Array.isArray(szukRest) ? szukRest.map((p) => p.id) : [];
    t.check('REST: wyszukiwanie bez Kokpitu i zasłony', idSzuk.includes(prep.zwykla) && !idSzuk.includes(prep.kokpit) && !idSzuk.includes(prep.zaslona),
      JSON.stringify(idSzuk));
    const wSzuk = ['Kokpit testowy Techxq', 'Zasłona testowa Techxq'].filter((x) => zSzuk.body.includes(x));
    t.check('wyszukiwarka: bez Kokpitu i zasłony', wSzuk.length === 0, JSON.stringify(wSzuk));
    const kEmbed = await pobierz(oembed('/kokpit-testowy/'));
    t.check('oEmbed: Kokpit 404', kEmbed.status === 404 && !kEmbed.body.includes('Kokpit testowy'), opis(kEmbed) + ' ' + kEmbed.body.slice(0, 100));
    /* url_to_postid() oddaje numer z `?page_id=N` od ręki, bez WP_Query —
       ta droga omija wykluczenie w zapytaniu i potrzebuje własnego filtra. */
    const kEmbedId = await pobierz(oembed('/?page_id=' + prep.kokpit));
    t.check('oEmbed z adresu ?page_id=: Kokpit 404', kEmbedId.status === 404 && !kEmbedId.body.includes('Kokpit testowy'),
      opis(kEmbedId) + ' ' + kEmbedId.body.slice(0, 100));
    /* Motyw blokowy bez menu pokazuje w nagłówku listę stron (get_pages()),
       jak zapasowe menu motywu klasycznego. */
    t.check('lista stron w nagłówku motywu: zwykła jest, Kokpitu i zasłony nie ma',
      z.body.includes('Zwykła strona Techxq') && !z.body.includes('Kokpit testowy Techxq') && !z.body.includes('Zasłona testowa Techxq'),
      JSON.stringify(['Kokpit testowy Techxq', 'Zasłona testowa Techxq'].filter((x) => z.body.includes(x))));

    /* Log 404: adres, którego nie ma, tak (warunek wstępny); strony techniczne
       nie — „Przekieruj" przy nich założyłoby 301 także dla zalogowanych
       i wyłączyło Kokpit na pulpicie. Fragment adresu to zwykłe 404. */
    await pobierz(b + '/nie-ma-takiej-techtest/');
    const log = sonda('log404');
    t.check('log 404: adres, którego nie ma, zapisany; Kokpitu i zasłony w nim nie ma',
      jak(log.wiersze, ['/kokpit-test', '/nie-ma-takiej-techtest/']), JSON.stringify(log.wiersze));

    t.section('zalogowany: obie strony jak dotąd, Kokpit w iframe na pulpicie');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const pg = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledyJs = [];
    pg.on('pageerror', (e) => bledyJs.push(e.message));
    await serwerWp.zaloguj(pg, b);
    const kz = await pg.goto(b + '/kokpit-testowy/');
    const kzHtml = await pg.content();
    t.check('Kokpit pod własnym adresem: 200 z treścią', kz.status() === 200 && kzHtml.includes('TRESC-KOKPITU-7731'), String(kz.status()));
    const robots = await pg.$$eval('meta[name="robots"]', (m) => m.map((x) => x.getAttribute('content')));
    t.check('meta robots: noindex, nofollow', robots.some((r) => /noindex/.test(r) && /nofollow/.test(r)), JSON.stringify(robots));
    const zz = await pg.goto(b + '/zaslona-testowa/');
    t.check('zasłona pod własnym adresem: 200 z treścią', zz.status() === 200 && (await pg.content()).includes('TRESC-ZASLONY-7732'), String(zz.status()));
    await pg.goto(b + '/wp-admin/index.php');
    const ramka = pg.frameLocator('#evoke-bricks-dashboard-iframe');
    let wRamce = '';
    try { wRamce = await ramka.locator('body').innerText({ timeout: 15000 }); } catch (e) { wRamce = 'błąd: ' + e.message.slice(0, 80); }
    t.check('pulpit: Kokpit ładuje się w iframe', wRamce.includes('TRESC-KOKPITU-7731'), wRamce.slice(0, 120));
    const mapaZal = await pg.goto(mapa);
    const mapaHtml = await pg.content();
    t.check('mapa strony (także dla zalogowanego): zwykła jest, Kokpitu i zasłony nie ma',
      mapaZal.status() === 200 && mapaHtml.includes('zwykla-strona-techtest') && !mapaHtml.includes('kokpit-testowy') && !mapaHtml.includes('zaslona-testowa'),
      String(mapaZal.status()) + ' ' + ['zwykla-strona-techtest', 'kokpit-testowy', 'zaslona-testowa'].filter((x) => mapaHtml.includes(x)).join(','));
    t.check('bez błędów JavaScriptu', bledyJs.length === 0, bledyJs.join(' | ').slice(0, 200));

    t.section('konserwacja: zasłona działa jak dotąd');
    sonda('konserwacja-wl');
    /* „Przerwa techniczna", jak w konserwacja-http: motyw blokowy testowego
       WordPressa nie ma page.php, więc wtyczka rysuje zasłonę zapasową. */
    for (const [nazwa, sciezka] of [['pod własnym adresem', '/zaslona-testowa/'], ['pod adresem zwykłej strony', '/zwykla-strona-techtest/']]) {
      const w = await pobierz(b + sciezka);
      t.check('zasłona ' + nazwa + ': 503 z zasłoną, bez Location', w.status === 503 && /Przerwa techniczna/.test(w.body) && !(w.naglowki || {}).location, opis(w));
    }
    sonda('konserwacja-wyl');

    t.section('bezpiecznik: strona główna nigdy nie jest techniczna');
    sonda('glowna-kokpit');
    const g = await pobierz(b + '/');
    t.check('Kokpit ustawiony też jako strona główna: „/" dalej 200 dla wszystkich', g.status === 200 && g.body.includes('TRESC-KOKPITU-7731'), opis(g));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
