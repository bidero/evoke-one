/**
 * CO zjada tę jedną długą klatkę w animacji liter hero.
 *
 * `zmierz-hero.js` pokazał, ŻE jest: klatka 183 ms w 867 ms i skok litery
 * o 10 px w 888 ms. Ten skrypt odpowiada KTO — profilem procesora, a nie
 * opakowywaniem funkcji, które akurat przyszły do głowy. Profil jest ważony
 * czasem własnym i zawężony do okna wokół przeskoku, bo suma z całego
 * ładowania mówi o czym innym.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');
const { chromiumPath } = require('../../tests/lib/harness.js');

const ADRES = process.env.ADRES || 'http://127.0.0.1:8765/';
const OD    = Number(process.env.OD || 600);
const DO    = Number(process.env.DO || 1100);
const SONDA = fs.readFileSync(path.join(__dirname, 'sonda-hero.js'), 'utf8');

(async () => {
  const b = await chromium.launch({ executablePath: chromiumPath(), args: ['--no-proxy-server'] });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const page = await ctx.newPage();
  await page.addInitScript({ content: SONDA });
  const cdp = await ctx.newCDPSession(page);
  await cdp.send('Profiler.enable');
  await cdp.send('Profiler.setSamplingInterval', { interval: 200 });   // 0,2 ms
  await cdp.send('Profiler.start');
  await page.goto(ADRES, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(3000);
  const { profile } = await cdp.send('Profiler.stop');

  /* Profil ma czas w mikrosekundach od WŁASNEGO startu, a sonda mierzy
     `performance.now()`. Zamiast zgadywać przesunięcie, kotwiczymy się na
     pierwszej próbce litery: ona jest w obu skalach. */
  const litery = await page.evaluate(() => window.__litery);
  const klatki = await page.evaluate(() => window.__klatki);
  let t = 0; const dlugie = [];
  klatki.forEach((d) => { t += d; if (d > 50) dlugie.push({ t: Math.round(t), d }); });
  console.log('długie klatki:', JSON.stringify(dlugie));
  console.log('pierwsza litera:', litery.length ? litery[0].t : null, 'ms');

  const wezly = new Map();
  profile.nodes.forEach((n) => wezly.set(n.id, n.callFrame));

  // Czas własny na próbkę, w oknie [OD, DO] licząc od startu profilu.
  const start = profile.startTime;
  let czas = start;
  const suma = new Map();
  for (let i = 0; i < profile.samples.length; i++) {
    czas += profile.timeDeltas[i] || 0;
    const ms = (czas - start) / 1000;
    if (ms < OD || ms > DO) continue;
    const f = wezly.get(profile.samples[i]);
    if (!f) continue;
    const klucz = (f.functionName || '(anonimowa)') + '  '
      + (f.url || '').replace(/^https?:\/\/[^/]+/, '').split('?')[0]
      + (f.lineNumber >= 0 ? ':' + (f.lineNumber + 1) : '');
    suma.set(klucz, (suma.get(klucz) || 0) + (profile.timeDeltas[i] || 0) / 1000);
  }

  console.log('\n── CZAS WŁASNY W OKNIE ' + OD + '–' + DO + ' ms (top 20) ──');
  [...suma.entries()].sort((a, b) => b[1] - a[1]).slice(0, 20).forEach(([k, v]) => {
    console.log('  ' + v.toFixed(1).padStart(7) + ' ms   ' + k);
  });
  await b.close();
})();
