/**
 * Logowanie w testach panelu (tests/lib/wp-serwer.js → zaloguj) odporne na
 * autofokus strony logowania WordPressa (1.260.0).
 *
 * Zawieszenie „nawigacja nie skończyła się w 30 s” wracało od 1.227.0 raz
 * na kilkanaście przebiegów. Przyczyna: wp-login.php 200 ms po wczytaniu
 * przenosi fokus na pole loginu i zaznacza jego tekst (wp_attempt_focus),
 * a `fill()` hasła to osobno fokus i wpisanie. Zegar pomiędzy → hasło ląduje
 * w polu loginu, pole hasła zostaje puste, a że ma `required`, przeglądarka
 * zatrzymuje wysłanie na walidacji: klik bez POST, strona stoi.
 *
 * Test wymusza najgorszy moment: zegar strony wstrzymany i wywołany raz,
 * zaraz po tym, jak pole hasła dostanie fokus. Kontrola pokazuje, że zwykłe
 * `fill()` wtedy zawodzi; pomocnik ma się mimo to zalogować, bez czekania na
 * przekroczenie czasu.
 */

const path = require('path');
const fs = require('fs');
const { chromium } = require('playwright-core');
const { chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const WP = process.env.EVK_WP_PATH || path.join(process.env.HOME || '', '.cache', 'evk-testowy-wp');

/** Zegar autofokusu wstrzymany; rusza raz, gdy pole hasła dostanie fokus — przed wpisaniem. */
function najgorszyMoment() {
  const st = window.setTimeout;
  let autofokus = null;
  window.setTimeout = function (f, ms, ...a) {
    if (ms === 200 && /user_login/.test(String(f))) { autofokus = f; return 0; }
    return st.call(this, f, ms, ...a);
  };
  document.addEventListener('focusin', (e) => {
    if (autofokus && e.target && e.target.id === 'user_pass') { const f = autofokus; autofokus = null; st(f, 0); }
  });
}

module.exports = async function (t) {
  t.section('logowanie w testach: autofokus WordPressa w najgorszym momencie');
  const jest = fs.existsSync(path.join(WP, 'wp-login.php'));
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', jest, WP);
  if (!jest) return;

  let serwer = null;
  let browser = null;
  try {
    serwer = await serwerWp.start(WP);
    browser = await chromium.launch({ executablePath: chromiumPath() });

    // Kontrola: dawna droga (fill, fill, klik) w tym samym momencie zegara.
    const k1 = await browser.newContext();
    await k1.addInitScript(najgorszyMoment);
    const p1 = await k1.newPage();
    const posty = [];
    p1.on('request', (r) => { if (r.method() === 'POST') posty.push(r.url()); });
    await p1.goto(serwer.baza + '/wp-login.php');
    await p1.fill('#user_login', 'admin');
    await p1.fill('#user_pass', 'admin');
    const w = await p1.evaluate(() => ({ haslo: document.getElementById('user_pass').value,
      brak: document.getElementById('user_pass').validity.valueMissing, fokus: document.activeElement.id }));
    await p1.click('#wp-submit');
    await p1.waitForTimeout(1500);
    t.check('kontrola: zwykłe fill() → hasło w polu loginu, puste hasło zatrzymuje formularz, klik bez POST',
      w.haslo === '' && w.brak === true && w.fokus === 'user_login' && posty.length === 0, JSON.stringify({ ...w, posty }));
    await k1.close();

    // Pomocnik w tym samym momencie zegara.
    const k2 = await browser.newContext();
    await k2.addInitScript(najgorszyMoment);
    const p2 = await k2.newPage();
    let blad = '';
    const t0 = Date.now();
    try { await serwerWp.zaloguj(p2, serwer.baza); } catch (e) { blad = e.message; }
    const ms = Date.now() - t0;
    t.check('zaloguj(): zalogowany mimo to', !blad && /\/wp-admin\//.test(p2.url()), blad.slice(0, 500) || p2.url());
    t.check('bez czekania na przekroczenie czasu (poniżej 10 s)', ms < 10000, ms + ' ms');
    await k2.close();

    // Zwykła strona logowania: pomocnik czeka na autofokus (200 ms) i loguje.
    const k3 = await browser.newContext();
    const p3 = await k3.newPage();
    const t1 = Date.now();
    let blad3 = '';
    try { await serwerWp.zaloguj(p3, serwer.baza); } catch (e) { blad3 = e.message; }
    t.check('zwykła strona logowania: zalogowany w chwilę', !blad3 && /\/wp-admin\//.test(p3.url()) && Date.now() - t1 < 5000,
      (blad3.slice(0, 300) || p3.url()) + ' · ' + (Date.now() - t1) + ' ms');
    await k3.close();
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
  }
};
