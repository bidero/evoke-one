/**
 * Role Manager w PRAWDZIWYM panelu, w przeglądarce: jeden komunikat po każdej
 * akcji (1.233.3).
 *
 * Zgłoszenie: po aktualizacji roli panel pokazywał „Rola zaktualizowana."
 * DWA razy. Strona Evoke ONE wisi pod „Ustawieniami" (add_options_page),
 * a dla takich stron WordPress sam dołącza wp-admin/options-head.php, który
 * woła settings_errors() dla wszystkich komunikatów. admin-roles.php wołał je
 * drugi raz — zmierzone przed naprawą: dwa div#setting-error-saved. To samo
 * dotyczyło „Rola dodana.", „Rola usunięta." i błędu „już istnieje".
 *
 * Sprawdzenie liczy DOKŁADNIE jeden: „naprawa" przez zgubienie komunikatu
 * (zero) zapala test tak samo jak duplikat. Każda akcja sprawdza też, że
 * zmiana naprawdę się zapisała — liczba komunikatów nic nie mówi o tym, czy
 * formularz działa.
 *
 * Środowisko: tools/testowy-wp.sh (php -S z routerem, jak backup-panel).
 * Sonda: tests/php/uprawnienia-panel.php.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('uprawnienia-panel.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};

/** Komunikaty panelu z danym tekstem: ile ich jest i ile elementów o identyfikatorze komunikatu. */
async function komunikaty(p, tekst) {
  return p.evaluate((t) => {
    const wszystkie = [...document.querySelectorAll('div.notice, div.updated, div.error')];
    const pasujace = wszystkie.filter((e) => e.innerText.includes(t));
    return { ile: pasujace.length, id: pasujace.map((e) => e.id) };
  }, tekst);
}

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');
  const prep = sonda('przygotuj');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !prep.brak && !!prep.wp, prep.brak || prep.wp);
  if (prep.brak || !prep.wp) return;

  let serwer = null;
  let browser;
  try {
    serwer = await serwerWp.start(prep.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    p.on('dialog', (d) => d.accept());   // „Usunąć rolę …?"
    await serwerWp.zaloguj(p, serwer.baza);
    const role = serwer.baza + '/wp-admin/options-general.php?page=evoke-one&tab=admin_panel&sub=roles';
    const wyslij = (sel) => Promise.all([p.waitForNavigation({ timeout: 30000 }), p.click(sel)]);

    // ── Edycja ──────────────────────────────────────────────────────────
    t.section('edycja roli: jeden komunikat, zmiana zapisana');
    await p.goto(role + '&role_action=edit&edit_role=evk_t_panel');
    await p.check('input[name="capabilities[edit_posts]"]');
    await wyslij('input[type="submit"][value="Zapisz rolę"]');
    const edycja = await komunikaty(p, 'Rola zaktualizowana.');
    t.check('jeden komunikat „Rola zaktualizowana."', edycja.ile === 1, JSON.stringify(edycja));
    t.check('uprawnienie zapisane (edit_posts)', sonda('stan').panel_edit_posts === true, JSON.stringify(sonda('stan')));

    // ── Dodanie ─────────────────────────────────────────────────────────
    t.section('dodanie roli: jeden komunikat, rola jest');
    await p.goto(role + '&role_action=add');
    await p.fill('input[name="role_name"]', 'Nowa rola testowa');
    await p.fill('input[name="role_slug"]', 'evk_t_nowa');
    await wyslij('input[type="submit"][value="Dodaj rolę"]');
    const dodanie = await komunikaty(p, 'Rola dodana.');
    t.check('jeden komunikat „Rola dodana."', dodanie.ile === 1, JSON.stringify(dodanie));
    t.check('rola dodana', sonda('stan').nowa_jest === true, JSON.stringify(sonda('stan')));

    t.section('dodanie istniejącej roli: jeden komunikat błędu');
    await p.goto(role + '&role_action=add');
    await p.fill('input[name="role_name"]', 'Nowa rola testowa');
    await p.fill('input[name="role_slug"]', 'evk_t_nowa');
    await wyslij('input[type="submit"][value="Dodaj rolę"]');
    const istnieje = await komunikaty(p, 'już istnieje');
    t.check('jeden komunikat „już istnieje"', istnieje.ile === 1, JSON.stringify(istnieje));

    // ── Usunięcie ───────────────────────────────────────────────────────
    t.section('usunięcie roli: jeden komunikat, roli nie ma');
    await p.goto(role);
    await wyslij('form:has(input[name="role_id"][value="evk_t_nowa"]) button[type="submit"]');
    const usuniecie = await komunikaty(p, 'Rola usunięta.');
    t.check('jeden komunikat „Rola usunięta."', usuniecie.ile === 1, JSON.stringify(usuniecie));
    t.check('rola usunięta', sonda('stan').nowa_jest === false, JSON.stringify(sonda('stan')));

    t.check('bez błędów JavaScriptu na stronie', bledy.length === 0, bledy.join(' | ').slice(0, 200));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
