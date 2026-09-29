/**
 * Testowy WordPress (tools/testowy-wp.sh) podany przez wbudowany serwer PHP —
 * wspólne dla testów panelu w przeglądarce (backup-panel,
 * backup-panel-przywracanie).
 *
 * ZATRZYMANIE CAŁEJ GRUPY PROCESÓW. `php -S` z PHP_CLI_SERVER_WORKERS to
 * rodzic i procesy robocze. Zmierzone (1.227.0): `kill()` na rodzicu zostawia
 * wszystkie sześć; bezczynne kończą po kilku sekundach, ale ten, który był
 * w trakcie żądania (WP-Cron, krok kopii, 30-sekundowy test pracy w tle),
 * pracuje dalej na bazie, z której korzysta już NASTĘPNY test. Stąd serwer
 * startuje we własnej grupie (`detached`), a zatrzymanie zabija grupę i czeka,
 * aż zniknie.
 *
 * LOGOWANIE Z DIAGNOZĄ. Dwa razy (1.227.0) nawigacja po zalogowaniu nie
 * dotarła do „load" w 30 s, a nie dało się tego odtworzyć. Przy przekroczeniu
 * czasu wyjątek niesie listę żądań, które wiszą, i to, co akurat robi baza
 * — zamiast gołego „Timeout".
 *
 * PRZYCZYNA (1.260.0). Zapis zdarzeń w wyjątku pokazał: GET wp-login.php,
 * 200, klik — i żadnego POST. Strona logowania po 200 ms robi
 * `user_login.focus(); select()` (wp_attempt_focus), a `fill()` to osobno
 * fokus i wpisanie. Gdy zegar trafi pomiędzy, „admin” idzie do zaznaczonego
 * pola loginu, hasło zostaje puste, a że ma `required`, przeglądarka
 * zatrzymuje wysłanie na walidacji. Odtworzone deterministycznie (zegar
 * wywołany ręcznie między fokusem a wpisaniem: puste hasło, klik bez POST).
 * Stąd przed kliknięciem sprawdzamy oba pola i w razie rozjazdu wpisujemy
 * jeszcze raz (tests/zapis-wp-logowanie.test.js wymusza najgorszy moment).
 *
 * Zapis zdarzeń strony (żądania, odpowiedzi, odrzucone, nawigacje) zostaje
 * w wyjątku — gdyby logowanie stanęło z innego powodu.
 */

const { spawn, execSync } = require('child_process');
const net = require('net');
const http = require('http');
const path = require('path');

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

/**
 * Serwer dla katalogu WordPressa $wp. Oddaje { baza, zatrzymaj() }.
 * `opcje.env` — dodatkowe zmienne środowiska dla `php -S`, np.
 * `{ EVK_SCRIPT_DEBUG: '1' }` (router definiuje wtedy SCRIPT_DEBUG).
 */
async function start(wp, opcje) {
  const port = await wolnyPort();
  const serwer = spawn('php', ['-S', '127.0.0.1:' + port, path.join(__dirname, '..', 'php', '_router-wp.php')],
    { cwd: wp, env: Object.assign({}, process.env, { PHP_CLI_SERVER_WORKERS: '6' }, (opcje && opcje.env) || {}), stdio: 'ignore', detached: true });
  await czekajNaSerwer(port);
  return {
    baza: 'http://127.0.0.1:' + port,
    async zatrzymaj() {
      try { process.kill(-serwer.pid, 'SIGTERM'); } catch (e) { return; }
      for (let i = 0; i < 50; i++) {
        try { process.kill(-serwer.pid, 0); } catch (e) { return; }   // grupy już nie ma
        await new Promise((r) => setTimeout(r, 100));
      }
      try { process.kill(-serwer.pid, 'SIGKILL'); } catch (e) { /* już nie ma */ }
    },
  };
}

/** Co robi baza — do diagnozy zawieszenia. */
function stanBazy() {
  try {
    return execSync("mysql -N -e \"SELECT ID, TIME, STATE, LEFT(INFO, 120) FROM information_schema.PROCESSLIST WHERE COMMAND <> 'Sleep' AND INFO NOT LIKE '%PROCESSLIST%'\"",
      { timeout: 5000 }).toString().trim() || '(baza bezczynna)';
  } catch (e) {
    return '(nie da się odczytać: ' + e.message.split('\n')[0] + ')';
  }
}

/** Logowanie admin/admin; przy przekroczeniu czasu wyjątek z diagnozą. */
async function zaloguj(p, baza, login = 'admin', haslo = 'admin') {
  const t0 = Date.now();
  const zdarzenia = [];
  const zapisz = (s) => zdarzenia.push((Date.now() - t0) + ' ms ' + s.replace(baza, ''));
  const wisza = new Map();
  // Skrypty, arkusze, obrazy i pisma tylko przy błędzie — inaczej zasypują zapis.
  const wazne = (r) => !['script', 'stylesheet', 'image', 'font'].includes(r.resourceType());
  const naStart = (r) => { if (wazne(r)) zapisz(r.method() + ' ' + r.url()); wisza.set(r, Date.now()); };
  const naKoniec = (r) => wisza.delete(r);
  const naOdpowiedz = (r) => { if (wazne(r.request())) zapisz(r.status() + ' ' + r.url()); };
  const naBlad = (r) => { zapisz('odrzucone ' + r.url() + ' ' + ((r.failure() || {}).errorText || '')); wisza.delete(r); };
  const naNawigacje = (f) => { if (f === p.mainFrame()) zapisz('nawigacja ' + f.url()); };
  p.on('request', naStart);
  p.on('response', naOdpowiedz);
  p.on('requestfinished', naKoniec);
  p.on('requestfailed', naBlad);
  p.on('framenavigated', naNawigacje);
  try {
    await p.goto(baza + '/wp-login.php');
    /* Wartości sprawdzone tuż przed kliknięciem. Zegar autofokusu, który
       przyjdzie PO sprawdzeniu, przestawia już tylko fokus — pól nie rusza. */
    for (let proba = 0; ; proba++) {
      await p.fill('#user_login', login);
      await p.fill('#user_pass', haslo);
      const w = await p.evaluate(() => [document.getElementById('user_login').value, document.getElementById('user_pass').value]);
      if (w[0] === login && w[1] === haslo) break;
      zapisz('pola po wpisaniu: login ' + w[0].length + ' zn., hasło ' + w[1].length + ' zn.');
      if (proba >= 2) throw new Error('pola logowania nie przyjęły wartości');
    }
    wisza.clear();   // „wiszą" liczy się od kliknięcia, jak dotąd
    zapisz('klik #wp-submit');
    await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.click('#wp-submit')]);
  } catch (e) {
    const lista = [...wisza].map(([r, t]) => r.url().replace(baza, '') + ' (' + (Date.now() - t) + ' ms)').join(', ');
    throw new Error('logowanie nie doszło do końca (' + String(e.message).split('\n')[0] + '); wiszą żądania: ' + (lista || 'żadne')
      + '; baza: ' + stanBazy() + '; adres: ' + p.url() + '; zdarzenia: ' + zdarzenia.slice(-25).join(' | '));
  } finally {
    p.off('request', naStart);
    p.off('response', naOdpowiedz);
    p.off('requestfinished', naKoniec);
    p.off('requestfailed', naBlad);
    p.off('framenavigated', naNawigacje);
  }
}

module.exports = { start, zaloguj };
