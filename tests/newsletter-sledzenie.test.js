/**
 * Śledzenie newslettera (1.233.2): piksel otwarcia i linki z adresem strony.
 *
 * Zgłoszenie: każde otwarcie maila dawało w logu TRZY wpisy „open" (przy
 * pierwszym otwarciu pierwszy z first:true), a linki z {site_url}
 * i {site_url_full} nie trafiały do statystyk.
 *
 * Zmierzone przed naprawą:
 *   — piksel zapowiadał `Content-Length: 43`, a GIF ma 42 bajty: połączenie
 *     kończyło się ECONNRESET, Chromium zgłaszał obrazek jako błąd
 *     (ERR_CONTENT_LENGTH_MISMATCH). Klient albo jego pośrednik ponawia
 *     nieudane pobranie — każde pobranie było wpisem;
 *   — tag wstawiony przyciskiem to TEKST, a link robi z niego dopiero klient
 *     poczty, wprost na stronę; `href="{site_url}"` z zakładki „Tekst" to link
 *     względny; okno linku daje „http://{site_url_full}" = „http://https://…".
 *
 * Piksel, kliknięcie i podgląd idą przez PRAWDZIWY serwer HTTP (php -S
 * z routerem, jak backup-panel), obrazek ładuje Chromium.
 * Środowisko: tools/testowy-wp.sh. Sonda: tests/php/newsletter-sledzenie.php.
 */

const http = require('http');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('newsletter-sledzenie.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const jak = (a, b) => JSON.stringify(a) === JSON.stringify(b);

/** GET bez podążania za przekierowaniem: status, nagłówki, bajty i to, jak się skończyło. */
function pobierz(url) {
  return new Promise((ok) => {
    const w = { bajty: 0, body: '' };
    let req;
    try {
      req = http.get(url, (r) => {
        w.status = r.statusCode; w.naglowki = r.headers;
        r.on('data', (d) => { w.bajty += d.length; if (w.body.length < 200000) w.body += d.toString('utf8'); });
        r.on('end', () => { w.koniec = w.koniec || 'end'; ok(w); });
        r.on('aborted', () => { w.koniec = 'aborted'; });
        r.on('error', (e) => { w.koniec = 'błąd ' + e.code; ok(w); });
      });
    } catch (e) {
      // Brak adresu (np. link, którego w mailu nie ma) — sprawdzenie ma zgasnąć, nie przerwać testu.
      w.koniec = 'zły adres: ' + JSON.stringify(url);
      ok(w);
      return;
    }
    req.on('error', (e) => { w.koniec = w.koniec || ('błąd ' + e.code); ok(w); });
    req.setTimeout(15000, () => { w.koniec = 'timeout'; req.destroy(); });
  });
}

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');
  const env = sonda('sciezka');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !env.brak && !!env.wp, env.brak || env.wp);
  if (env.brak || !env.wp) return;

  let serwer = null;
  let browser;
  let prep = {};
  try {
    serwer = await serwerWp.start(env.wp);
    prep = sonda('przygotuj ' + serwer.baza);
    t.check('sonda przygotowała kampanię', !prep.brak && !!prep.kampania, prep.brak || ('kampania ' + prep.kampania));
    if (prep.brak || !prep.kampania) return;

    const home = prep.home;                       // adres serwera testowego
    const host = home.replace(/^https?:\/\//, '');

    // ── Mail: linki z adresem strony ──────────────────────────────────────
    /* Każdy wariant to osobny akapit szablonu (sonda: id akapitu → linki),
       więc brak jednego linku nie zapala sprawdzeń pozostałych. */
    t.section('mail: linki z {site_url} i {site_url_full} idą przez śledzenie');
    const linki = prep.linki || {};
    const w = (id, pola) => (linki[id] || []).map((l) => {
      const o = {};
      pola.forEach((k) => { o[k] = l[k]; });
      return o;
    });
    const przypadki = [
      // [akapit, opis, oczekiwane linki, porównywane pola]
      ['t-tekst', 'tag w tekście, kropka za linkiem', [{ tekst: host, cel: home, po: '.' }], ['tekst', 'cel', 'po']],
      ['t-sciezka', 'tag w tekście ze ścieżką i parametrami, przecinek za linkiem',
        [{ tekst: home + '/sklep/?a=1&b=2', cel: home + '/sklep/?a=1&b=2', po: ',' }], ['tekst', 'cel', 'po']],
      ['t-nbsp', 'tag w tekście przed twardą spacją', [{ tekst: host, cel: home, po: '&' }], ['tekst', 'cel', 'po']],
      ['t-strong', 'tag w pogrubieniu', [{ tekst: home, cel: home }], ['tekst', 'cel']],
      ['o-krotki', 'okno linku: http://{site_url}', [{ tekst: 'okno-krotki', cel: home }], ['tekst', 'cel']],
      ['o-pelny', 'okno linku: http://{site_url_full} → adres strony, nie „https://"',
        [{ tekst: 'okno-pelny', cel: home }], ['tekst', 'cel']],
      ['h-krotki', 'zakładka Tekst: href="{site_url}" bez protokołu', [{ tekst: 'tekst-krotki', cel: home }], ['tekst', 'cel']],
      ['h-pelny', 'zakładka Tekst: href="{site_url_full}/kontakt/"', [{ tekst: 'tekst-pelny', cel: home + '/kontakt/' }], ['tekst', 'cel']],
      ['w-linku', 'tag w tekście linku do innej strony: napis bez nowego linku',
        [{ tekst: host, cel: 'https://example.com/' }], ['tekst', 'cel']],
    ];
    for (const [id, opis, oczekiwane, pola] of przypadki) {
      const jest = w(id, pola);
      t.check(opis, jak(jest, oczekiwane), JSON.stringify(jest).split(home).join('{adres strony}'));
    }
    t.check('żadnych innych linków w mailu', prep.wszystkich_w_mailu === 9, String(prep.wszystkich_w_mailu));
    t.check('link w linku nie powstaje', prep.zagniezdzony === false, String(prep.zagniezdzony));
    t.check('tag w atrybucie (title) zostaje adresem bez linku', prep.title === host, String(prep.title));
    t.check('jeden piksel otwarcia w mailu', prep.pikseli === 1 && !!prep.piksel, String(prep.pikseli));

    // ── Piksel: odpowiedź, którą klient przyjmie ────────────────────────
    t.section('piksel otwarcia: odpowiedź bez błędu');
    const piksel = prep.piksel;
    const r1 = await pobierz(piksel);
    t.check('200, image/gif', r1.status === 200 && /^image\/gif/.test((r1.naglowki || {})['content-type'] || ''),
      JSON.stringify([r1.status, (r1.naglowki || {})['content-type']]));
    t.check('Content-Length równe temu, co przyszło, połączenie kończy się normalnie',
      String(r1.bajty) === String((r1.naglowki || {})['content-length']) && r1.koniec === 'end',
      JSON.stringify({ zapowiedziane: (r1.naglowki || {})['content-length'], przyszlo: r1.bajty, koniec: r1.koniec }));

    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage();
    const bledy = [];
    p.on('requestfailed', (r) => { if (/evk_nl=open|\/nl\/open\//.test(r.url())) bledy.push(r.failure() && r.failure().errorText); });
    await p.goto(serwer.baza + '/wp-login.php');
    const obrazek = await p.evaluate((u) => new Promise((ok) => {
      const i = new Image();
      i.onload = () => ok('wczytany ' + i.naturalWidth + '×' + i.naturalHeight);
      i.onerror = () => ok('błąd');
      i.src = u;
    }), piksel);
    t.check('Chromium wczytuje piksel jako obrazek 1×1', obrazek === 'wczytany 1×1' && bledy.length === 0,
      obrazek + ' ' + JSON.stringify(bledy));

    // ── Jedno otwarcie = jeden wpis ───────────────────────────────────────
    t.section('otwarcie: kolejne pobrania w oknie to jeden wpis');
    await pobierz(piksel);   // trzecie pobranie tego samego otwarcia
    const s1 = sonda('stan ' + prep.kampania);
    t.check('trzy pobrania w oknie → jeden wpis, first:true', jak(s1.otwarcia, [{ first: true }]), JSON.stringify(s1.otwarcia));
    t.check('kolejka: status opened, opened_at ustawione',
      !!s1.kolejka && s1.kolejka.status === 'opened' && !!s1.kolejka.opened_at, JSON.stringify(s1.kolejka));

    const cof = sonda('cofnij ' + prep.kampania);
    await pobierz(piksel);
    await pobierz(piksel);
    const s2 = sonda('stan ' + prep.kampania);
    t.check('otwarcie po oknie → drugi wpis, first:false; powtórka w nowym oknie → bez wpisu',
      cof.przesuniete === 1 && jak(s2.otwarcia, [{ first: true }, { first: false }]),
      JSON.stringify([cof.przesuniete, s2.otwarcia]));

    // ── Kliknięcie: link z tagu działa i się liczy ───────────────────────
    t.section('kliknięcie w adres strony z tagu');
    const klik = await pobierz(((linki['t-tekst'] || [])[0] || {}).href || '');
    t.check('przekierowanie na adres strony', klik.status === 302 && (klik.naglowki || {}).location === home,
      JSON.stringify([klik.status, (klik.naglowki || {}).location]));
    const s3 = sonda('stan ' + prep.kampania);
    t.check('kliknięcie w statystykach', jak(s3.klikniecia, [home]), JSON.stringify(s3.klikniecia));

    // ── Podgląd w przeglądarce: te same linki, bez śledzenia ─────────────
    t.section('podgląd w przeglądarce: tag w tekście i w href to działający link');
    const podglad = await pobierz(prep.podglad);
    const baza = serwer.baza;
    const hostSerwera = baza.replace(/^https?:\/\//, '');
    const wAkapicie = (id) => {
      const ak = (podglad.body || '').match(new RegExp('<p id="' + id + '">([\\s\\S]*?)</p>'));
      return ak ? [...ak[1].matchAll(/<a\s[^>]*href="([^"]*)"[^>]*>([\s\S]*?)<\/a>/g)].map((m) => [m[1], m[2].replace(/<[^>]+>/g, '')]) : null;
    };
    t.check('podgląd się otwiera', podglad.status === 200, String(podglad.status));
    t.check('tag w tekście → link do strony z tym samym napisem', jak(wAkapicie('t-tekst'), [[baza, hostSerwera]]),
      JSON.stringify(wAkapicie('t-tekst')));
    t.check('href bez protokołu z zakładki Tekst → pełny adres', jak(wAkapicie('h-krotki'), [[baza, 'tekst-krotki']]),
      JSON.stringify(wAkapicie('h-krotki')));
    t.check('podgląd nie liczy kliknięć (bez śledzenia)', !/evk_nl=click|\/nl\/click\//.test(podglad.body || ''),
      ((podglad.body || '').match(/[^"]*(evk_nl=click|\/nl\/click\/)[^"]*/) || [''])[0].slice(0, 120));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    if (prep.kampania) sonda('sprzataj ' + prep.kampania + ' ' + prep.lista + ' ' + prep.szablon);
  }
};
