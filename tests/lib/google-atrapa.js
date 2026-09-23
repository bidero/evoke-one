/**
 * Atrapa Google (tests/php/_google-atrapa.php) i pośrednik tokenów
 * (tools/oauth-relay/token.php) na wbudowanych serwerach PHP — wspólne dla
 * testów Dysku (backup-drive, backup-panel-drive).
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

async function czekaj(port, sciezka, co) {
  const koniec = Date.now() + 10000;
  for (;;) {
    try { await zapytaj(port, 'GET', sciezka); return; } catch (e) {
      if (Date.now() > koniec) throw new Error(co + ' nie wstał(a)');
      await new Promise((r) => setTimeout(r, 150));
    }
  }
}

async function zatrzymajGrupe(pid) {
  try { process.kill(-pid, 'SIGTERM'); } catch (e) { return; }
  for (let i = 0; i < 30; i++) {
    try { process.kill(-pid, 0); } catch (e) { return; }
    await new Promise((r) => setTimeout(r, 100));
  }
}

/**
 * Oddaje { adres, posrednik, port, katalog, stan(), ster(obj), zatrzymaj() }.
 *
 * `posrednik` — PRAWDZIWY pośrednik tokenów z tools/oauth-relay/token.php na
 * drugim serwerze, z konfiguracją (sekretem) w katalogu atrapy, kierujący
 * wymianę tokenów do atrapy. Wtyczka w testach mówi do niego, nie do /token
 * atrapy — tak jak na stronach klientów mówi do evoke.pl.
 */
async function start() {
  const port = await wolnyPort();
  const portP = await wolnyPort();
  const katalog = fs.mkdtempSync(path.join(os.tmpdir(), 'evk-google-'));
  const adres = 'http://127.0.0.1:' + port;
  const konfig = path.join(katalog, 'evk-oauth-config.php');
  fs.writeFileSync(konfig, '<?php return ' + JSON.stringify({ client_id: 'test-klient', client_secret: 'test-sekret',
    redirect_uri: adres + '/evk-oauth/', token_url: adres + '/token' }).replace(/^\{/, '[').replace(/\}$/, ']').replace(/":/g, '" =>') + ';\n');
  const serwer = spawn('php', ['-S', '127.0.0.1:' + port, path.join(__dirname, '..', 'php', '_google-atrapa.php')],
    { env: Object.assign({}, process.env, { EVK_GOOGLE_DIR: katalog }), stdio: 'ignore', detached: true });
  const posrednik = spawn('php', ['-S', '127.0.0.1:' + portP, '-t', path.join(__dirname, '..', '..', 'tools', 'oauth-relay')],
    { env: Object.assign({}, process.env, { EVK_OAUTH_CONFIG: konfig }), stdio: 'ignore', detached: true });
  await czekaj(port, '/_atrapa/stan', 'atrapa Google');
  await czekaj(portP, '/token.php', 'pośrednik tokenów');
  return {
    adres,
    posrednik: 'http://127.0.0.1:' + portP + '/token.php',
    port,
    katalog,
    stan: () => zapytaj(port, 'GET', '/_atrapa/stan'),
    ster: (o) => zapytaj(port, 'POST', '/_atrapa/ster', o),
    async zatrzymaj() {
      await zatrzymajGrupe(serwer.pid);
      await zatrzymajGrupe(posrednik.pid);
      fs.rmSync(katalog, { recursive: true, force: true });
    },
  };
}

module.exports = { start };
