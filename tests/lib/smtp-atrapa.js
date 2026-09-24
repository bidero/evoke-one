/**
 * Atrapa serwera SMTP dla testów wysyłki (newsletter-wysylka).
 *
 * Serwer (smtp-atrapa-serwer.js) startuje we własnej grupie procesów, jak
 * atrapa Google — sondy PHP idą przez `execSync`, więc serwer w procesie testu
 * nie odpowiedziałby w trakcie sondy. Stan i poczta leżą w katalogu
 * tymczasowym, osobnym na każdy start.
 *
 *   const smtp = await start();
 *   smtp.port, smtp.ster({ limit: 2 }), smtp.poczta(), smtp.wyczysc(), await smtp.zatrzymaj()
 */

const { spawn } = require('child_process');
const fs = require('fs');
const os = require('os');
const net = require('net');
const path = require('path');

function wolnyPort() {
  return new Promise((ok) => { const s = net.createServer().listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => ok(p)); }); });
}

async function start() {
  const port = await wolnyPort();
  const katalog = fs.mkdtempSync(path.join(os.tmpdir(), 'evk-smtp-'));
  const serwer = spawn(process.execPath, [path.join(__dirname, 'smtp-atrapa-serwer.js'), String(port), katalog],
    { stdio: 'ignore', detached: true });
  const koniec = Date.now() + 10000;
  while (!fs.existsSync(path.join(katalog, 'gotowy'))) {
    if (Date.now() > koniec) throw new Error('atrapa SMTP nie wstała');
    await new Promise((r) => setTimeout(r, 50));
  }
  const plikPoczty = path.join(katalog, 'poczta.jsonl');
  return {
    port,
    katalog,
    ster: (o) => fs.writeFileSync(path.join(katalog, 'ster.json'), JSON.stringify(o || {})),
    poczta: () => (fs.existsSync(plikPoczty) ? fs.readFileSync(plikPoczty, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l)) : []),
    wyczysc: () => { if (fs.existsSync(plikPoczty)) fs.unlinkSync(plikPoczty); },
    async zatrzymaj() {
      try { process.kill(-serwer.pid, 'SIGTERM'); } catch (e) { /* już nie żyje */ }
      fs.rmSync(katalog, { recursive: true, force: true });
    },
  };
}

module.exports = { start };
