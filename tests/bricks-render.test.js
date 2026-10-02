/**
 * Render z PRAWDZIWYM Bricksem (1.274.0) — piąty testowy WordPress
 * (bricks.test, motyw z prywatnego bidero/bricks-motyw, tools/testowy-wp.sh).
 *
 * Do 1.273.0 o zachowaniu Bricksa wiedzieliśmy tylko z prób zgłaszającego
 * w konsoli (docs/proby-komponenty.md). Ten test sprawdza te same przypadki
 * na prawdziwym renderze — wartości zgodne z wynikami z testowej (01.10):
 *  - zwykły element: pole „Tłumaczenie EN” na `/en/` (filtr ustawień, 51);
 *  - instancja z właściwością i bliźniakiem EN: A (tekst i EN) → EN,
 *    B (sam tekst) → polski, C (bez wartości) → pusto; tekst stały → EN
 *    z komponentu;
 *  - komponent BEZ bliźniaka (sprzed 1.272.0), instancja z własnym tekstem →
 *    tłumaczenie tekstu komponentu, czyli innego zdania (błąd z próby 1);
 *    po przejściu po komponentach (51, jak po aktualizacji) — jej polski tekst.
 *
 * Builder Bricksa bez licencji przekierowuje na stronę licencji — panel
 * i przyciski dalej sprawdza zgłaszający; tu tylko render.
 */

const http = require('http');
const { phpOutput } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('bricks-render.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const pobierz = (u) => new Promise((ok) => http.get(u, (r) => { let d = ''; r.on('data', (c) => { d += c; }); r.on('end', () => ok({ s: r.statusCode, d })); })
  .on('error', (e) => ok({ s: 0, d: String(e) })));
/** Nagłówki h3 i teksty strony testu, w kolejności. */
const teksty = (html) => (html.match(/<h3[^>]*>[\s\S]*?<\/h3>|<div[^>]*class="[^"]*brxe-text-basic[^"]*"[^>]*>[\s\S]*?<\/div>/g) || [])
  .map((x) => x.replace(/<[^>]+>/g, '').trim());

module.exports = async function (t) {
  t.section('środowisko: piąty testowy WordPress z motywem Bricks');
  const wp = sonda('wp');
  t.check('piąty WordPress z motywem Bricks (tools/testowy-wp.sh; zip w ../bricks-motyw albo EVK_BRICKS_ZIP)',
    !wp.brak && wp.motyw === 'Bricks' && !!wp.bricks && wp.opcja === 'bricks_components', wp.brak || J(wp));
  if (wp.brak || wp.motyw !== 'Bricks') return;
  sonda('sprzataj');
  let serwer = null;
  try {
    t.check('moduł Tłumaczeń, język EN, elementy Evoke włączone', sonda('modul').gotowe === true);

    t.section('elementy Evoke: pola „… EN” tylko przy treści (prawdziwe kontrolki)');
    const tl = sonda('tlumaczalne').pola || {};
    console.log('      ' + J(tl));
    /* Przegląd w prawdziwym builderze (1.275.0): start/koniec ScrollTriggera, pozycja Wave BG,
       ID panelu, zapas pod stosem dostawały pola „… EN” — oznaczone `evkTlPomin`. */
    t.check('tłumaczone: napisy Burgera i tekst Circular Title; wartości techniczne (ScrollTrigger, pozycja, ID, odstęp) — bez pól',
      J(tl) === J({ 'evk-burger': ['textClosed', 'textOpen', 'ariaLabel'], 'evk-circular-menu': [], 'evk-circular-title': ['inner_title'], 'evk-grain': [],
        'evk-horizontal-scroll': [], 'evk-marquee': [], 'evk-offcanvas-menu': [], 'evk-scroll-reading': [], 'evk-stacking-cards': [], 'evk-wave-bg': [] }), J(tl));
    const u = sonda('ustaw');
    t.check('komponenty i strona testu', u.gotowe === true, J(u));
    if (!u.gotowe) return;
    serwer = await serwerWp.start(wp.wp);
    const strona = async (pre) => {
      const r = await pobierz(serwer.baza + pre + '/?page_id=' + u.strona);
      return { s: r.s, t: teksty(r.d) };
    };

    t.section('render: elementy i instancje komponentów, / i /en/');
    const pl = await strona('');
    const en = await strona('/en');
    console.log('      PL: ' + J(pl) + '\n      EN: ' + J(en));
    t.check('polski: zwykły nagłówek; „stary” z tekstem instancji; A i B z tekstem instancji, C pusty; tekst stały w każdej instancji', pl.s === 200 && J(pl.t) === J([
      'Zwykły nagłówek', 'Instancja starego', 'Nagłówek A', 'Tekst stały', 'Nagłówek B', 'Tekst stały', '', 'Tekst stały']), J(pl));
    t.check('/en/: zwykły element z pola „Tłumaczenie EN”; A — EN z właściwości instancji; B — polski; C — pusty; tekst stały — EN z komponentu',
      en.s === 200 && en.t[0] === 'PLAIN HEADING EN' && J(en.t.slice(2)) === J(['HEADING A EN', 'FIXED TEXT EN', 'Nagłówek B', 'FIXED TEXT EN', '', 'FIXED TEXT EN']), J(en));
    t.check('/en/, komponent bez bliźniaka (sprzed 1.272.0): instancja z własnym tekstem dostaje tłumaczenie tekstu komponentu — błąd z próby 1, odtworzony',
      en.t[1] === 'OLD COMPONENT EN', J(en.t[1]));
    const st = sonda('stan');
    t.check('mapa pól z prawdziwych kontrolek Bricksa po renderze: nagłówek — „text”, tekst podstawowy — „text”',
      J(((st.mapa || {}).heading || {}).pola) === J(['text']) && (((st.mapa || {})['text-basic'] || {}).pola || []).indexOf('text') === 0, J(st.mapa));

    t.section('przejście po komponentach (51) i render jeszcze raz');
    const p = sonda('przejscie');
    console.log('      właściwości: ' + J(p.wlasciwosci));
    const bl = ((p.wlasciwosci || [])[0] || [])[1] || [];
    t.check('„stary” dostaje „Nagłówek EN”; „nowy” z ręcznym „Nagłówek EN” bez drugiego', bl.length === 2 && /^Nagłówek EN:[a-z]{6}$/.test(bl[1])
      && J(((p.wlasciwosci || [])[1] || [])[1]) === J(['Nagłówek:brknwn', 'Nagłówek EN:brknwe']), J(p.wlasciwosci));
    const en2 = await strona('/en');
    console.log('      EN po przejściu: ' + J(en2.t));
    t.check('/en/ po przejściu: instancja „starego” pokazuje swój polski tekst zamiast cudzego tłumaczenia; reszta bez zmian',
      en2.t[1] === 'Instancja starego' && J(en2.t.slice(0, 1).concat(en2.t.slice(2))) === J(en.t.slice(0, 1).concat(en.t.slice(2))), J(en2.t));
  } finally {
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
