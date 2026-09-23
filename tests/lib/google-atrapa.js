/**
 * Atrapa Google (tests/php/_google-atrapa.php) na wbudowanym serwerze PHP —
 * wspólne dla testów Dysku (backup-drive, backup-panel-drive).
 *
 * Stan atrapy leży w katalogu tymczasowym, osobnym na każdy start. Serwer
 * startuje we własnej grupie procesów i jest zatrzymywany razem z nią (jak
 * testowy WordPress w wp-serwer.js).
 */

const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const net = require('net');
const http = require('http');
const path = require('path');

function wolnyPort() {
  return new Promise((ok) => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => ok(p)); }); });
}

function zapytaj(port, metoda, sciezka, dane) {
  return new Promise((ok, zle) => {
    const cialo = dane === undefined ? '' : JSON.stringify(dane);
    const r = http.request({ host: '127.0.0.1', port, path: sciezka, method: metoda,
      headers: { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(cialo) } }, (res) => {
      let b = '';
      res.on('data', (c) => { b += c; });
      res.on('end', () => { try { ok(JSON.parse(b)); } catch (e) { ok(b); } });
    });
    r.on('error', zle);
    r.end(cialo);
  });
}

/** Oddaje { adres, port, katalog, stan(), ster(obj), zatrzymaj() }. */
async function start() {
  const port = await wolnyPort();
  const katalog = fs.mkdtempSync(path.join(os.tmpdir(), 'evk-google-'));
  const serwer = spawn('php', ['-S', '127.0.0.1:' + port, path.join(__dirname, '..', 'php', '_google-atrapa.php')],
    { env: Object.assign({}, process.env, { EVK_GOOGLE_DIR: katalog }), stdio: 'ignore', detached: true });
  const koniec = Date.now() + 10000;
  for (;;) {
    try { await zapytaj(port, 'GET', '/_atrapa/stan'); break; } catch (e) {
      if (Date.now() > koniec) throw new Error('atrapa Google nie wstała');
      await new Promise((r) => setTimeout(r, 150));
    }
  }
  return {
    adres: 'http://127.0.0.1:' + port,
    port,
    katalog,
    stan: () => zapytaj(port, 'GET', '/_atrapa/stan'),
    ster: (o) => zapytaj(port, 'POST', '/_atrapa/ster', o),
    async zatrzymaj() {
      try { process.kill(-serwer.pid, 'SIGTERM'); } catch (e) { /* już nie ma */ }
      for (let i = 0; i < 30; i++) {
        try { process.kill(-serwer.pid, 0); } catch (e) { break; }
        await new Promise((r) => setTimeout(r, 100));
      }
      fs.rmSync(katalog, { recursive: true, force: true });
    },
  };
}

module.exports = { start };
