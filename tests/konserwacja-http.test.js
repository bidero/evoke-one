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

    t.section('zasłona z 503 na każdym adresie');
    const przypadki = [
      ['strona główna', '/'],
      ['podstrona pod ścieżką (do 1.233.5: 302 na stronę główną)', '/zwykla-strona-testowa/'],
      ['podstrona z adresu zapytania', '/?page_id=' + prep.zwykla],
      ['nieistniejący adres: zasłona, nie 404', '/?p=99999999'],
    ];
    for (const [nazwa, sciezka] of przypadki) {
      const w = await pobierz(b + sciezka);
      /* „Przerwa techniczna": tytuł strony zasłony przy motywie klasycznym albo
         zasłona zapasowa wtyczki — motyw blokowy (testowy WordPress ma
         Twenty Twenty-Five) nie ma page.php, więc wtyczka rysuje własną. */
      t.check(nazwa + ': 503 z Retry-After i treścią zasłony',
        w.status === 503 && !!(w.naglowki || {})['retry-after'] && /Przerwa techniczna/.test(w.body),
        opis(w));
    }

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
