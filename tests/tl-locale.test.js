/**
 * Język WordPressa na wersjach językowych (1.258.0), przez PRAWDZIWY serwer
 * HTTP i w procesie.
 *
 * ZGŁOSZONE: na /en/ daty i teksty WordPressa i Bricksa były po polsku.
 * Wtyczka rozpoznawała język z adresu, ale nie mówiła o nim WordPressowi —
 * filtr `locale` (13-jezyk-wordpressa.php) podaje mu język wersji.
 *
 * Testowy WordPress ma sam angielski, więc sonda stawia minimalne paczki
 * `.l10n.php`: pl_PL rdzenia i dziedziny `evk-t-dom` w languages/plugins
 * (tak leżą paczki motywu i wtyczek, np. Bricksa). Paczkę de_DE test pobiera
 * przyciskiem z zakładki Języki. Znacznik z mu-pluginu mówi, co WordPress
 * myśli o języku: get_locale(), miesiąc z wp_date(), teksty z obu dziedzin.
 *
 * Środowisko: tools/testowy-wp.sh. Sonda: tests/php/tl-locale.php.
 */

const http = require('http');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('tl-locale.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};

/** Jedno żądanie BEZ podążania za przekierowaniem. */
function pobierz(url, naglowki) {
  return new Promise((ok) => {
    const w = { body: '' };
    const req = http.get(url, { headers: naglowki || {} }, (r) => {
      w.status = r.statusCode;
      w.location = r.headers.location || '';
      r.on('data', (d) => { if (w.body.length < 600000) w.body += d.toString('utf8'); });
      r.on('end', () => ok(w));
    });
    req.on('error', (e) => { w.status = 'błąd ' + e.code; ok(w); });
    req.setTimeout(20000, () => { w.status = 'timeout'; req.destroy(); });
  });
}

/** Znacznik z mu-pluginu testu + lang z <html> i og:locale z <head>. */
function strona(w) {
  const z = { status: w.status };
  const m = w.body.match(/<i id="evk-t-lok"([^>]*)><\/i>/);
  if (m) for (const a of m[1].matchAll(/data-([a-z]+)="([^"]*)"/g)) z[a[1]] = a[2];
  const lang = w.body.match(/<html[^>]*\slang="([^"]+)"/);
  z.html = lang ? lang[1] : null;
  const glowa = w.body.split('</head>')[0] || '';
  z.og = (glowa.match(/<meta property="og:locale" content="([^"]*)"/) || [])[1] || null;
  z.alt = [...glowa.matchAll(/<meta property="og:locale:alternate" content="([^"]*)"/g)].map((x) => x[1]).sort();
  return z;
}

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;

  let serwer = null;
  let browser = null;
  try {
    serwer = await serwerWp.start(wp.wp);
    const b = serwer.baza;
    const prep = sonda('przygotuj ' + b);
    t.check('sonda przygotowała stronę (pl_PL, EN i DE, paczki pl_PL, zip de_DE)', !prep.brak && prep.gotowe === true,
      prep.brak || JSON.stringify(prep));
    if (prep.brak || !prep.gotowe) return;
    const plu = sonda('przeplucz ' + b);
    t.check('moduł wczytany, język witryny pl_PL, reguły przepłukane', plu.tl === true && plu.wplang === 'pl_PL', JSON.stringify(plu));

    t.section('get_locale() dla adresów, w procesie');
    const f = sonda('filtr');
    const L = f.locale || {};
    const opis = JSON.stringify(L);
    t.check('polska strona: język witryny (pl_PL)', L.pl === 'pl_PL', opis);
    t.check('/en/: en_US', L.en === 'en_US', opis);
    t.check('/de/: de_DE', L.de === 'de_DE', opis);
    t.check('builder (?bricks=run) na /en/: zostaje pl_PL', L.builder === 'pl_PL', opis);
    t.check('kanwa buildera na /en/: zostaje pl_PL', L.kanwa === 'pl_PL', opis);
    t.check('podgląd szablonu Bricksa na /en/: zostaje pl_PL', L.podglad === 'pl_PL', opis);
    t.check('filtr/stronicowanie Bricksa (REST) z Refererem /en/: en_US', L.rest_referer === 'en_US', opis);
    t.check('ta sama trasa bez Referera: pl_PL', L.rest_bez === 'pl_PL', opis);
    t.check('inna trasa REST z Refererem /en/: pl_PL (tylko trasy Bricksa z językiem)', L.rest_inna === 'pl_PL', opis);
    t.check('prefiks spoza ustawień (/fr/): pl_PL', L.nieznany === 'pl_PL', opis);
    t.check('tl_locale_witryny(): pl_PL także po filtrze', f.witryna === 'pl_PL', JSON.stringify(f.witryna));
    const pan = sonda('panel');
    const wszystkiePl = pan.locale && Object.values(pan.locale).every((v) => v === 'pl_PL');
    t.check('panel (WP_ADMIN): każdy adres, także /en/, zostaje pl_PL', pan.admin === true && wszystkiePl, JSON.stringify(pan));

    t.section('locale z kodu HTML języka (zakładka Języki)');
    const M = sonda('mapa').mapa || {};
    const mo = JSON.stringify(M);
    t.check('kod z regionem: en-US → en_US, en-gb → en_GB, de-DE → de_DE, pt-BR → pt_BR',
      M['en-US|en'] === 'en_US' && M['en-gb|en'] === 'en_GB' && M['de-DE|de'] === 'de_DE' && M['pt-BR|pt'] === 'pt_BR', mo);
    t.check('sam język: zainstalowana paczka (de → de_CH, uk → uk), bez niej region z kodu (fr → fr_FR), en → en_US',
      M['de|de'] === 'de_CH' && M['uk|uk'] === 'uk' && M['fr|fr'] === 'fr_FR' && M['en|en'] === 'en_US', mo);
    t.check('wariant zostaje: de-DE-formal → de_DE_formal', M['de-DE-formal|de'] === 'de_DE_formal', mo);
    t.check('zły kod HTML → kod języka strony (es_ES); oba złe → pusty', M['|es'] === 'es_ES' && M['??|es'] === 'es_ES' && M['x|xx1'] === '', mo);
    const st1 = sonda('stan').stan || [];
    t.check('zakładka Języki: EN wbudowany (en_US), DE bez paczki (de_DE)',
      JSON.stringify(st1.map((s) => [s.kod, s.locale, s.stan])) === JSON.stringify([['en', 'en_US', 'wbudowany'], ['de', 'de_DE', 'brak']]),
      JSON.stringify(st1));

    t.section('polska wersja: WordPress w języku witryny');
    const pl = strona(await pobierz(b + '/wpis-lok/'));
    const po = (z) => JSON.stringify(z);
    t.check('PL: pl_PL, miesiąc „marzec”, tekst rdzenia „Szukaj”', pl.status === 200 && pl.locale === 'pl_PL' && pl.miesiac === 'marzec' && pl.szukaj === 'Szukaj', po(pl));
    t.check('PL: dziedzina wtyczki (jak Bricks) po polsku — „Cześć”', pl.dom === 'Cześć', po(pl));
    t.check('PL: og:locale pl_PL, alternatywy en_US i de_DE', pl.og === 'pl_PL' && JSON.stringify(pl.alt) === JSON.stringify(['de_DE', 'en_US']), po(pl));

    t.section('wersja EN: WordPress po angielsku');
    const en = strona(await pobierz(b + '/en/wpis-lok/'));
    t.check('EN: en_US, miesiąc „March”', en.status === 200 && en.locale === 'en_US' && en.miesiac === 'March', po(en));
    t.check('EN: tekst rdzenia i dziedzina wtyczki po angielsku — „Search”, „Hello”', en.szukaj === 'Search' && en.dom === 'Hello', po(en));
    t.check('EN: get_bloginfo(language) en-US (inLanguage w schema)', en.jezyk === 'en-US', po(en));
    t.check('EN: og:locale en_US, polska wersja zostaje w og:locale:alternate', en.og === 'en_US' && JSON.stringify(en.alt) === JSON.stringify(['de_DE', 'pl_PL']), po(en));
    t.check('EN: <html lang="en-US"> jak dotąd', en.html === 'en-US', po(en));
    t.check('EN: switch_to_locale(pl_PL) wygrywa z filtrem („marzec”), powrót daje znów „March”', en.przelaczony === 'marzec' && en.po === 'March', po(en));

    t.section('wersja DE bez paczki: angielski zamiast polskiego');
    const de = strona(await pobierz(b + '/de/wpis-lok/'));
    t.check('DE bez paczki: de_DE, „March”, „Search” — nie po polsku', de.status === 200 && de.locale === 'de_DE' && de.miesiac === 'March' && de.szukaj === 'Search', po(de));

    t.section('builder i jego kanwa zostają przy języku witryny (HTTP)');
    const bld = strona(await pobierz(b + '/en/wpis-lok/?bricks=run'));
    t.check('/en/…?bricks=run: pl_PL, „marzec”', bld.locale === 'pl_PL' && bld.miesiac === 'marzec', po(bld));
    const kan = strona(await pobierz(b + '/en/wpis-lok/?bricks=run&brickspreview=1'));
    t.check('/en/…?bricks=run&brickspreview: pl_PL', kan.locale === 'pl_PL', po(kan));

    t.section('filtr i stronicowanie Bricksa (REST) w języku strony');
    const json = (w) => { try { return JSON.parse(w.body); } catch (e) { return { status: w.status, body: w.body.slice(0, 200) }; } };
    const r1 = json(await pobierz(b + '/wp-json/bricks/v1/query_result', { Referer: b + '/en/wpis-lok/' }));
    t.check('trasa Bricksa z Refererem /en/: en_US, „March”', r1.locale === 'en_US' && r1.miesiac === 'March', JSON.stringify(r1));
    const r2 = json(await pobierz(b + '/wp-json/bricks/v1/query_result'));
    t.check('ta sama trasa bez Referera: pl_PL, „marzec”', r2.locale === 'pl_PL' && r2.miesiac === 'marzec', JSON.stringify(r2));
    const r3 = json(await pobierz(b + '/en/wp-json/bricks/v1/query_result'));
    t.check('adres REST z prefiksem (/en/wp-json/…): en_US', r3.locale === 'en_US', JSON.stringify(r3));

    /* 1.266.0 (uwagi zgłaszającego, 30.09): nagłówek „Język WordPressa…”
       przyklejony do „Dodaj język / Zapisz ustawienia”, „Pobierz paczkę” wyższy
       od linii tekstu, tekst wiersza PL niewyrównany z tekstem w polach. Style
       z includes/admin/tl/render.php — testy panelu (tests/php/tab.php) ich nie
       ładują, więc pomiar na prawdziwym ekranie. DE jest tu jeszcze bez paczki. */
    t.section('zakładka Języki: układ (Chromium)');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const zmierz = async (szer) => {
      const k = await browser.newContext({ viewport: { width: szer, height: 900 } });
      const p = await k.newPage();
      await serwerWp.zaloguj(p, b);
      await p.goto(b + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=languages');
      const w = await p.evaluate(() => {
        const r = (e) => { if (!e) return null; const x = e.getBoundingClientRect(); return { l: x.left, r: x.right, t: x.top, b: x.bottom, w: x.width, h: x.height }; };
        const tekst = (e) => { if (!e) return null; const z = document.createRange(); z.selectNodeContents(e); return r(z); };
        const stopka = document.querySelector('.tl-footer');
        const przyciski = stopka ? Array.from(stopka.querySelectorAll('.button')).map(r) : [];
        const li = document.querySelector('#tl-jezyk-wp li[data-stan="brak"]');
        const paczka = li && li.querySelector('.tl-pobierz-paczke');
        const pl = document.querySelector('.lang-table .lang-row-pl td:nth-child(2)');
        const polaEn = document.querySelector('#lang-body tr:not(.lang-row-pl) .lang-code');
        const cs = polaEn && getComputedStyle(polaEn);
        return {
          odstep: przyciski.length && document.querySelector('#tl-jezyk-wp h3')
            ? r(document.querySelector('#tl-jezyk-wp h3')).t - Math.max(...przyciski.map((x) => x.b)) : null,
          paczka: r(paczka), linia: tekst(li && li.querySelector('strong')),
          pl: tekst(pl), pole: polaEn ? r(polaEn).l + parseFloat(cs.borderLeftWidth) + parseFloat(cs.paddingLeft) : null,
          szer: document.documentElement.scrollWidth, okno: innerWidth,
        };
      });
      await k.close();
      return w;
    };
    const u = await zmierz(1280);
    console.log('      pomiar 1280 px: ' + JSON.stringify(u));
    t.check('nagłówek „Język WordPressa…” odsunięty od „Dodaj język / Zapisz ustawienia” co najmniej 24 px', u.odstep !== null && u.odstep >= 24,
      JSON.stringify(u.odstep));
    t.check('„Pobierz paczkę de_DE” w linii tekstu (środki ±2 px), nie wyższy niż 32 px', !!u.paczka && !!u.linia
      && Math.abs((u.paczka.t + u.paczka.h / 2) - (u.linia.t + u.linia.h / 2)) <= 2 && u.paczka.h <= 32, JSON.stringify([u.paczka, u.linia]));
    t.check('tekst wiersza PL na wysokości tekstu w polach (±1 px)', !!u.pl && u.pole !== null && Math.abs(u.pl.l - u.pole) <= 1,
      JSON.stringify([u.pl && u.pl.l, u.pole]));
    const m = await zmierz(360);
    console.log('      pomiar 360 px: ' + JSON.stringify(m));
    t.check('360 px: bez przewijania w poziomie, „Pobierz paczkę” co najmniej 24×24', m.szer <= m.okno && !!m.paczka && m.paczka.w >= 24 && m.paczka.h >= 24,
      JSON.stringify([m.szer, m.okno, m.paczka]));

    t.section('pobranie paczki DE przyciskiem w zakładce Języki');
    const obca = sonda('pobierz ' + b + ' fr_FR');
    t.check('język spoza ustawień (fr_FR): odmowa', obca.odp && obca.odp.success === false && /Nieznany/.test(obca.odp.data || ''), JSON.stringify(obca));
    const tlum = sonda('pobierz ' + b + ' de_DE tlumacz');
    t.check('tłumacz bez prawa instalowania języków: odmowa, paczki nie ma',
      tlum.odp && tlum.odp.success === false && /instalowania/.test(tlum.odp.data || '') && tlum.plik === false, JSON.stringify(tlum));
    const adm = sonda('pobierz ' + b + ' de_DE');
    t.check('administrator: paczka de_DE zainstalowana w languages', adm.odp && adm.odp.success === true && adm.plik === true, JSON.stringify(adm));
    const st2 = sonda('stan').stan || [];
    t.check('zakładka Języki: DE — paczka zainstalowana', (st2.find((s) => s.kod === 'de') || {}).stan === 'jest', JSON.stringify(st2));
    const de2 = strona(await pobierz(b + '/de/wpis-lok/'));
    t.check('DE po instalacji: „März”, „Suchen”', de2.locale === 'de_DE' && de2.miesiac === 'März' && de2.szukaj === 'Suchen', po(de2));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
