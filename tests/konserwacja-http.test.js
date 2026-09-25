/**
 * Tryb konserwacji przez PRAWDZIWY serwer HTTP (1.234.0): 503 na każdym
 * adresie, robots.txt bez zmian.
 *
 * Do 1.233.5:
 *   — 503 z Retry-After dostawała wyłącznie strona główna; każdy inny adres
 *     odpowiadał 302 na `/`, czyli dla wyszukiwarki przekierowaniem
 *     wszystkich podstron na stronę główną;
 *   — robots.txt był podmieniany na `Disallow: /` (Google pamięta go do
 *     doby po wyłączeniu konserwacji), a przy wygenerowanym robots.txt
 *     zasłona zjadała nawet samą odpowiedź.
 *
 * W 1.234.0 (zgłoszone z evoke.pl): `/home` — adres strony ustawionej jako
 * główna — odpowiadał 301 na `/`, i dopiero tam było 503. Przekierowania
 * rdzenia (kanoniczne, stary slug) i moduł 301 działają w `template_redirect`,
 * PRZED zasłoną w `template_include`. Testowy WordPress nie miał ładnych
 * adresów ani strony głównej, więc żadne z nich nie miało jak zajść; sonda
 * stawia teraz jedno i drugie, a test najpierw sprawdza, że bez konserwacji
 * te przekierowania naprawdę są.
 *
 * Kody odpowiedzi i nagłówki widać dopiero na żądaniu HTTP — test na
 * atrapach (konserwacja) widzi tylko decyzję hooka. Testowy WordPress przez
 * `php -S` z routerem, jak backup-panel; żądania bez ciasteczek, czyli jak
 * odwiedzający i robot wyszukiwarki.
 *
 * Środowisko: tools/testowy-wp.sh. Sonda: tests/php/konserwacja-http.php.
 */

const http = require('http');
const { phpOutput } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('konserwacja-http.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};

/** GET bez podążania za przekierowaniem. */
function pobierz(url) {
  return new Promise((ok) => {
    const w = { body: '' };
    const req = http.get(url, (r) => {
      w.status = r.statusCode; w.naglowki = r.headers;
      r.on('data', (d) => { if (w.body.length < 200000) w.body += d.toString('utf8'); });
      r.on('end', () => ok(w));
    });
    req.on('error', (e) => { w.status = 'błąd ' + e.code; ok(w); });
    req.setTimeout(20000, () => { w.status = 'timeout'; req.destroy(); });
  });
}

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');
  const prep = sonda('przygotuj');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !prep.brak && !!prep.wp, prep.brak || prep.wp);
  if (prep.brak || !prep.wp) return;

  let serwer = null;
  try {
    serwer = await serwerWp.start(prep.wp);
    const b = serwer.baza;
    const opis = (w) => JSON.stringify({ status: w.status, location: (w.naglowki || {}).location,
      retry: (w.naglowki || {})['retry-after'] });

    const zaslona = (w) => w.status === 503 && !(w.naglowki || {}).location
      && !!(w.naglowki || {})['retry-after'] && /Przerwa techniczna/.test(w.body);
    const glownaBez = prep.glowna.replace(/\/$/, '');

    t.section('warunek wstępny: bez konserwacji rdzeń i moduł 301 przekierowują');
    /* Bez tego reszta pliku mogłaby przejść na pusto: gdyby ładne adresy się
       nie włączyły, żadne przekierowanie nie miałoby jak zajść. */
    const wstepne = [
      ['adres strony głównej → „/"', prep.glowna, /\/$/],
      ['podstrona bez ukośnika → z ukośnikiem', '/zwykla-strona-testowa', /\/zwykla-strona-testowa\/$/],
      ['stary slug wpisu → nowy', '/stary-slug-konserwacja/', /\/nowy-slug-konserwacja\/$/],
      ['reguła modułu 301', '/t-konserwacja-stary-adres', /\/zwykla-strona-testowa\/$/],
    ];
    for (const [nazwa, sciezka, cel] of wstepne) {
      const w = await pobierz(b + sciezka);
      t.check(nazwa + ' (301)', w.status === 301 && cel.test((w.naglowki || {}).location || ''), opis(w));
    }
    t.check('reguła 301 założona', prep.regula === true, String(prep.regula));

    sonda('wlacz');
    t.section('zasłona z 503 na każdym adresie, bez przekierowań');
    const przypadki = [
      ['strona główna', '/'],
      ['podstrona pod ścieżką (do 1.233.5: 302 na stronę główną)', '/zwykla-strona-testowa/'],
      ['podstrona z adresu zapytania (rdzeń: 301 na ładny adres)', '/?page_id=' + prep.zwykla],
      ['nieistniejący adres: zasłona, nie 404', '/?p=99999999'],
      ['adres strony głównej (1.234.0: 301 na „/", evoke.pl)', prep.glowna],
      ['adres strony głównej bez ukośnika', glownaBez],
      ['podstrona bez ukośnika', '/zwykla-strona-testowa'],
      ['adres z regułą modułu 301', '/t-konserwacja-stary-adres'],
    ];
    for (const [nazwa, sciezka] of przypadki) {
      const w = await pobierz(b + sciezka);
      /* „Przerwa techniczna": tytuł strony zasłony przy motywie klasycznym albo
         zasłona zapasowa wtyczki — motyw blokowy (testowy WordPress ma
         Twenty Twenty-Five) nie ma page.php, więc wtyczka rysuje własną. */
      t.check(nazwa + ': 503 z Retry-After i zasłoną, bez Location', zaslona(w), opis(w));
    }

    sonda('bez-strony');
    t.section('zasłona zapasowa (bez strony zasłony, jak na evoke.pl)');
    /* Bez strony zasłony zapytanie nie jest podmieniane: strona główna zostaje
       stroną główną, a 404 — 404. Stąd w 1.234.0 301 z `/home` na `/`. */
    for (const [nazwa, sciezka] of [
      ['adres strony głównej', prep.glowna],
      ['stary slug wpisu (rdzeń: 301 na nowy)', '/stary-slug-konserwacja/'],
      ['adres z regułą modułu 301', '/t-konserwacja-stary-adres'],
    ]) {
      const w = await pobierz(b + sciezka);
      t.check(nazwa + ': 503, bez Location', zaslona(w), opis(w));
    }
    const nieMa = await pobierz(b + '/t-konserwacja-nie-ma/');
    const log = sonda('log404');
    t.check('nieistniejący adres pod zasłoną: 503 i bez wpisu w logu 404',
      zaslona(nieMa) && Array.isArray(log.wiersze) && log.wiersze.length === 0, opis(nieMa) + ' log: ' + JSON.stringify(log.wiersze));

    t.section('robots.txt i logowanie bez zasłony');
    const r = await pobierz(b + '/?robots=1');
    t.check('robots.txt: 200, tekst robots od WordPressa',
      r.status === 200 && /^User-agent:/m.test(r.body) && !/Przerwa techniczna/.test(r.body), opis(r) + ' ' + r.body.slice(0, 120));
    t.check('robots.txt bez „Disallow: /" (wyszukiwarka nie wyrzuca strony)',
      !/^Disallow:\s*\/\s*$/m.test(r.body), JSON.stringify(r.body.slice(0, 200)));
    const l = await pobierz(b + '/wp-login.php');
    t.check('logowanie działa (200)', l.status === 200 && /user_login/.test(l.body), opis(l));
  } finally {
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
