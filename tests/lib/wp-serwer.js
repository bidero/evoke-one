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

/** Serwer dla katalogu WordPressa $wp. Oddaje { baza, zatrzymaj() }. */
async function start(wp) {
  const port = await wolnyPort();
  const serwer = spawn('php', ['-S', '127.0.0.1:' + port, path.join(__dirname, '..', 'php', '_router-wp.php')],
    { cwd: wp, env: Object.assign({}, process.env, { PHP_CLI_SERVER_WORKERS: '6' }), stdio: 'ignore', detached: true });
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
  await p.goto(baza + '/wp-login.php');
  await p.fill('#user_login', login);
  await p.fill('#user_pass', haslo);
  const wisza = new Map();
  const naStart = (r) => wisza.set(r, Date.now());
  const naKoniec = (r) => wisza.delete(r);
  p.on('request', naStart);
  p.on('requestfinished', naKoniec);
  p.on('requestfailed', naKoniec);
  try {
    await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.click('#wp-submit')]);
  } catch (e) {
    const lista = [...wisza].map(([r, t]) => r.url().replace(baza, '') + ' (' + (Date.now() - t) + ' ms)').join(', ');
    throw new Error('logowanie: nawigacja nie skończyła się w 30 s; wiszą żądania: ' + (lista || 'żadne')
      + '; baza: ' + stanBazy() + '; adres: ' + p.url());
  } finally {
    p.off('request', naStart);
    p.off('requestfinished', naKoniec);
    p.off('requestfailed', naKoniec);
  }
}

module.exports = { start, zaloguj };
