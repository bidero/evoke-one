/**
 * Backup — zakładka „Kopie zapasowe" w PRAWDZIWYM panelu, w przeglądarce.
 *
 * Testowy WordPress (tools/testowy-wp.sh) podany przez wbudowany serwer PHP
 * z kilkoma procesami (PHP_CLI_SERVER_WORKERS) — bez nich żądanie serwera do
 * samego siebie czekałoby na samo siebie. Router (tests/php/_router-wp.php)
 * ustawia adres strony z portu, bez zmian w bazie.
 *
 * Przechodzimy ścieżkę osoby przy panelu: kopia → postęp → lista → pobranie →
 * przypięcie → usunięcie; anulowanie w trakcie; kopia napędzana WYŁĄCZNIE
 * odpytywaniem z otwartej karty (żądania zwrotne i WP-Cron wyłączone — tak
 * jest na serwerze, który ubija żądania w tle); test napędu w tle.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const { spawn } = require('child_process');
const net = require('net');
const http = require('http');
const path = require('path');

const sonda = (a) => JSON.parse(phpOutput('backup-panel.php', a));

function wolnyPort() {
  return new Promise((ok) => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => ok(p)); }); });
}
function czekajNaSerwer(port) {
  return new Promise((ok, zle) => {
    const koniec = Date.now() + 15000;
    (function proba() {
      http.get({ host: '127.0.0.1', port, path: '/wp-login.php' }, (r) => { r.resume(); ok(); })
        .on('error', () => (Date.now() > koniec ? zle(new Error('serwer nie wstał')) : setTimeout(proba, 200)));
    })();
  });
}
/** Czy element naprawdę widać — nie „czy ma atrybut hidden". */
const widac = (page, sel) => page.locator(sel).isVisible();

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');

  const prep = sonda('przygotuj');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !prep.brak, prep.brak || prep.wp);
  if (prep.brak) return;

  const port = await wolnyPort();
  const baza = 'http://127.0.0.1:' + port;
  const serwer = spawn('php', ['-S', '127.0.0.1:' + port, path.join(__dirname, 'php', '_router-wp.php')],
    { cwd: prep.wp, env: Object.assign({}, process.env, { PHP_CLI_SERVER_WORKERS: '6' }), stdio: 'ignore' });
  let browser;
  try {
    await czekajNaSerwer(port);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    const bledy = [];
    p.on('pageerror', (e) => bledy.push(e.message));
    p.on('dialog', (d) => d.accept());
    await p.goto(baza + '/wp-login.php');
    await p.fill('#user_login', 'admin');
    await p.fill('#user_pass', 'admin');
    await Promise.all([p.waitForNavigation(), p.click('#wp-submit')]);
    const zakladka = baza + '/wp-admin/options-general.php?page=evoke-one&tab=backup';
    await p.goto(zakladka);

    // ── Stan wyjściowy ─────────────────────────────────────────────────────
    t.section('zakładka przed pierwszą kopią');
    t.check('przycisk „Utwórz kopię teraz" widoczny', await widac(p, '[data-evk-backup-start]'));
    /* Zmierzone przy pierwszym podejściu: `hidden` przegrywał z CSS
       WordPressa i „Anuluj" oraz pusta ramka wisiały na ekranie. */
    t.check('„Anuluj", pasek i ramka komunikatu NIEWIDOCZNE',
      !(await widac(p, '[data-evk-backup-cancel]')) && !(await widac(p, '[data-evk-backup-progress]'))
      && !(await widac(p, '[data-evk-backup-msg]')));
    t.check('lista pusta z komunikatem', await widac(p, '[data-evk-backup-empty]'));

    // ── Kopia ──────────────────────────────────────────────────────────────
    t.section('kopia z panelu: postęp, lista, pobranie');
    await p.click('[data-evk-backup-start]');
    t.check('w trakcie: „Anuluj" widoczny, start zablokowany',
      (await widac(p, '[data-evk-backup-cancel]')) && (await p.locator('[data-evk-backup-start]').isDisabled()));
    await p.locator('[data-evk-backup-msg]').filter({ hasText: /Kopia gotowa|nie powiodła/ }).waitFor({ timeout: 180000 });
    const msg1 = await p.locator('[data-evk-backup-msg]').innerText();
    t.check('komunikat „Kopia gotowa"', /Kopia gotowa: .+\.zip/.test(msg1), msg1.trim());
    t.check('pasek na 100%, „Anuluj" znów niewidoczny',
      /^100%/.test(await p.locator('[data-evk-backup-percent]').innerText()) && !(await widac(p, '[data-evk-backup-cancel]')));
    t.check('kopia na liście bez przeładowania strony', (await p.locator('tr[data-archive]').count()) === 1);

    const fakty1 = sonda('fakty');
    const href = await p.locator('tr[data-archive] [data-evk-backup-download]').getAttribute('href');
    const odp = await p.request.get(href);
    const cialo = await odp.body();
    t.check('pobranie: ZIP o rozmiarze kopii z dysku',
      odp.status() === 200 && cialo.slice(0, 2).toString() === 'PK' && cialo.length === fakty1.kopie[0].size,
      odp.status() + ', ' + cialo.length + ' B vs ' + fakty1.kopie[0].size + ' B');
    const zlyNonce = await p.request.get(href.replace(/_wpnonce=[^&]+/, '_wpnonce=zly'));
    t.check('pobranie ze złym nonce: odmowa', zlyNonce.status() === 403, String(zlyNonce.status()));

    /* Żądanie zwrotne kroku przychodzi BEZ zalogowania — jedynym
       poświadczeniem jest podpis HMAC. Zły podpis: odmowa, zanim cokolwiek
       ruszy zadanie. */
    const ajax = baza + '/wp-admin/admin-ajax.php';
    const zlyPodpis = await p.request.post(ajax, { form: { action: 'evk_backup_loopback', id: '1', sig: 'zly' } });
    t.check('żądanie zwrotne kroku ze złym podpisem: 403', zlyPodpis.status() === 403, String(zlyPodpis.status()));
    const bezNonce = await p.request.post(ajax, { form: { action: 'evk_backup_delete', nonce: 'zly', archive: fakty1.kopie[0].archive } });
    t.check('usuwanie kopii bez ważnego nonce: 403, kopia zostaje',
      bezNonce.status() === 403 && sonda('fakty').kopie.length === 1, String(bezNonce.status()));

    // ── Przypinanie i usuwanie ─────────────────────────────────────────────
    t.section('przypinanie i usuwanie');
    await p.click('tr[data-archive] [data-evk-backup-pin]');
    await p.locator('tr[data-archive] .evo-badge').waitFor();
    t.check('przypięta: plakietka w wierszu i zapis na dysku', sonda('fakty').kopie[0].pinned === true);
    await p.click('tr[data-archive] [data-evk-backup-delete]');
    await p.locator('[data-evk-backup-empty]').waitFor();
    const fakty2 = sonda('fakty');
    t.check('usunięta: wiersz znika, plik z dysku też', fakty2.kopie.length === 0, JSON.stringify(fakty2.kopie));

    // ── Anulowanie ─────────────────────────────────────────────────────────
    t.section('anulowanie w trakcie');
    sonda('duzy');   // 150 MB nieściśliwych danych — kopia trwa, jest co przerwać
    await p.goto(zakladka);
    await p.click('[data-evk-backup-start]');
    await p.locator('[data-evk-backup-step]').filter({ hasText: /etap 3/ }).waitFor({ timeout: 60000 });
    await p.click('[data-evk-backup-cancel]');
    await p.locator('[data-evk-backup-msg]').filter({ hasText: /anulowana/ }).waitFor({ timeout: 60000 });
    /* Krok, który akurat pakował, sprząta po sobie między porcjami — chwila. */
    let fakty3;
    for (let i = 0; i < 30; i++) { fakty3 = sonda('fakty'); if (!fakty3.part.length && !fakty3.praca.length) break; await p.waitForTimeout(500); }
    t.check('po anulowaniu: bez kopii, bez .part, bez katalogu roboczego',
      !fakty3.kopie.length && !fakty3.part.length && !fakty3.praca.length, JSON.stringify(fakty3));

    // ── Napęd wyłącznie z panelu ───────────────────────────────────────────
    t.section('kopia napędzana wyłącznie otwartą kartą');
    sonda('bez-loopbacku 1');
    await p.goto(zakladka);
    await p.click('[data-evk-backup-start]');
    await p.locator('[data-evk-backup-msg]').filter({ hasText: /Kopia gotowa|nie powiodła/ }).waitFor({ timeout: 240000 });
    const msg4 = await p.locator('[data-evk-backup-msg]').innerText();
    const fakty4 = sonda('fakty');
    const ost = fakty4.zadania[fakty4.zadania.length - 1];
    /* Bez żądań zwrotnych i bez WP-Cron jedynym, kto może zrobić krok, jest
       odpytywanie z tej karty (ajax.php, evk_backup_status). Mutacja
       usuwająca popychanie: zadanie stoi w „queued" do końca czasu testu. */
    t.check('bez żądań zwrotnych i WP-Cron kopia i tak się kończy', /Kopia gotowa/.test(msg4) && ost.status === 'done',
      msg4.trim() + ' / kroków: ' + ost.ticks);
    sonda('bez-loopbacku 0');

    // ── Test napędu w tle ──────────────────────────────────────────────────
    t.section('test napędu w tle');
    await p.click('[data-evk-backup-probe-start]');
    await p.waitForFunction(() => !document.querySelector('[data-evk-backup-probe-start]').disabled, null, { timeout: 60000 });
    const probe = await p.locator('[data-evk-backup-probe-result]').innerText();
    t.check('żądanie w tle przeżywa pełne 30 s (serwer testowy nie ubija)', /przeżyło pełne 30 s/.test(probe), probe);

    t.check('bez błędów JS przez cały przebieg', !bledy.length, bledy.join(' | ') || 'czysto');
  } finally {
    if (browser) await browser.close();
    serwer.kill();
    sonda('sprzataj');
  }
};
