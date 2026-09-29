/**
 * Wyszukiwarka na wersjach językowych (1.259.0): na /en/ WordPress szuka też
 * w tłumaczeniach wpisów (`_evk_tl_en__post_title` itd.), w procesie
 * i przez PRAWDZIWY serwer HTTP (główne zapytanie strony wyników).
 *
 * Do 1.258.0 dopasowanie szło wyłącznie po polskim tekście — „contact” na /en/
 * nie znajdowało strony „Kontakt”, choć jej angielski tytuł to „Contact”.
 *
 * Wpisy (sonda tests/php/tl-szukaj.php), daty rosną A → D → E → C → B:
 *   A „Kontakt” / EN „Contact”, „Write to us.” / DE treść „Schreiben Sie uns.”
 *   C „Formularz kontaktowy” / EN „Contact form”, „Fill in the fields.”
 *   B „Oferta” / EN „Offer”, „Contact us for details.”
 *   D z hasłem / EN „Contact secret”;  E „Galeria” bez tłumaczeń
 *
 * Środowisko: tools/testowy-wp.sh.
 */

const http = require('http');
const { phpOutput } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('tl-szukaj.php', a.join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const szukaj = (lang, fraza) => sonda('szukaj', lang, JSON.stringify(fraza)).litery;

function pobierz(url) {
  return new Promise((ok) => {
    const w = { body: '' };
    const req = http.get(url, (r) => {
      w.status = r.statusCode;
      r.on('data', (d) => { if (w.body.length < 600000) w.body += d.toString('utf8'); });
      r.on('end', () => ok(w));
    });
    req.on('error', (e) => { w.status = 'błąd ' + e.code; ok(w); });
    req.setTimeout(20000, () => { w.status = 'timeout'; req.destroy(); });
  });
}

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;

  let serwer = null;
  try {
    serwer = await serwerWp.start(wp.wp);
    const b = serwer.baza;
    const prep = sonda('przygotuj', b);
    t.check('sonda przygotowała pięć wpisów z tłumaczeniami', !prep.brak && prep.gotowe === true, prep.brak || JSON.stringify(prep));
    if (prep.brak || !prep.gotowe) return;
    const plu = sonda('przeplucz', b);
    t.check('moduł wczytany, reguły przepłukane', plu.tl === true, JSON.stringify(plu));

    t.section('wersja EN: dopasowanie także po tłumaczeniu');
    t.check('„contact”: tytuł EN (C, A) i treść EN (B); tytuły przed treścią, bez wpisu z hasłem',
      szukaj('en', 'contact') === 'CAB', szukaj('en', 'contact'));
    t.check('wielkość liter bez znaczenia („Contact”)', szukaj('en', 'Contact') === 'CAB', szukaj('en', 'Contact'));
    t.check('polski tekst dalej się liczy („kontakt” na /en/: C, A)', szukaj('en', 'kontakt') === 'CA', szukaj('en', 'kontakt'));
    t.check('kilka słów: każde musi pasować („contact form” → tylko C)', szukaj('en', 'contact form') === 'C', szukaj('en', 'contact form'));
    t.check('wykluczenie działa na tłumaczeniu („contact -details” → bez B)', szukaj('en', 'contact -details') === 'CA', szukaj('en', 'contact -details'));

    t.section('każdy język szuka w swoim tłumaczeniu');
    t.check('DE: „schreiben” (treść DE) → A', szukaj('de', 'schreiben') === 'A', szukaj('de', 'schreiben'));
    t.check('EN: „schreiben” → nic (tłumaczenie DE nie należy do EN)', szukaj('en', 'schreiben') === '', szukaj('en', 'schreiben'));
    t.check('DE: „contact” → nic (tłumaczenie EN nie należy do DE)', szukaj('de', 'contact') === '', szukaj('de', 'contact'));

    t.section('polska wersja i panel bez zmian');
    t.check('PL: „contact” → nic (tłumaczenia poza polskim wyszukiwaniem)', szukaj('pl', 'contact') === '', szukaj('pl', 'contact'));
    t.check('PL: „kontakt” → C, A jak dotąd', szukaj('pl', 'kontakt') === 'CA', szukaj('pl', 'kontakt'));
    const pan = sonda('panel', 'en', '"contact"');
    t.check('panel (WP_ADMIN) z językiem EN: „contact” → nic', pan.admin === true && pan.litery === '', JSON.stringify(pan));

    t.section('główne zapytanie przez HTTP (strona wyników)');
    const mapa = Object.fromEntries(Object.entries(prep.mapa).map(([k, v]) => [String(v), k]));
    const wyniki = async (u) => {
      const w = await pobierz(b + u);
      const m = w.body.match(/<i id="evk-t-szukaj" data-ids="([^"]*)"><\/i>/);
      return { status: w.status, litery: m ? m[1].split(',').map((id) => mapa[id] || '').join('') : null };
    };
    const en = await wyniki('/en/?s=contact');
    t.check('/en/?s=contact: C, A, B', en.status === 200 && en.litery === 'CAB', JSON.stringify(en));
    const pl = await wyniki('/?s=contact');
    t.check('/?s=contact: nic z tych wpisów', pl.status === 200 && pl.litery === '', JSON.stringify(pl));
  } finally {
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
