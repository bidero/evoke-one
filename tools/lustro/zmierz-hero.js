/**
 * Przeskok w animacji liter hero — pomiar na lustrze żywej strony.
 * Sonda: `sonda-hero.js`. Powód i metoda — w jej nagłówku.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');
const { chromiumPath } = require('../../tests/lib/harness.js');

const ADRES = process.env.ADRES || 'http://127.0.0.1:8765/';
const CPU   = Number(process.env.CPU || 1);
const SONDA = fs.readFileSync(path.join(__dirname, 'sonda-hero.js'), 'utf8');

(async () => {
  const b = await chromium.launch({ executablePath: chromiumPath(), args: ['--no-proxy-server'] });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const page = await ctx.newPage();
  if (CPU > 1) {
    const cdp = await ctx.newCDPSession(page);
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: CPU });
  }
  await page.addInitScript({ content: SONDA });
  await page.goto(ADRES, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(14000);

  const { klatki, odswiez, litery } = await page.evaluate(() => ({
    klatki: window.__klatki, odswiez: window.__odswiez, litery: window.__litery,
  }));

  console.log('\n══ dławienie procesora: ' + CPU + '×');
  console.log('klatek: ' + klatki.length + ', próbek litery: ' + litery.length);

  console.log('\n── ODŚWIEŻENIA ScrollTriggera ──');
  if (!odswiez.length) console.log('  (żadnego)');
  odswiez.forEach((o) => console.log('  ' + String(o.t).padStart(6) + ' ms  ' + o.skad));

  /* DŁUGIE KLATKI. Próg 50 ms to trzy pominięte klatki przy 60 Hz — tyle widać
     gołym okiem jako szarpnięcie. Wypisujemy z czasem, żeby dało się je zestawić
     z odświeżeniami powyżej. */
  let t = 0;
  const dlugie = [];
  klatki.forEach((d) => { t += d; if (d > 50) dlugie.push({ t: Math.round(t), d }); });
  console.log('\n── KLATKI DŁUŻSZE NIŻ 50 ms ──');
  if (!dlugie.length) console.log('  (żadnej)');
  dlugie.slice(0, 25).forEach((k) => console.log('  ' + String(k.t).padStart(6) + ' ms   ' + k.d + ' ms'));

  /* NIECIĄGŁOŚĆ POSTĘPU. Przeskok widoczny na ekranie to skok wartości między
     dwiema kolejnymi klatkami — większy niż zwykły krok animacji. */
  console.log('\n── LITERA: skoki przesunięcia większe niż typowy krok ──');
  const kroki = [];
  for (let i = 1; i < litery.length; i++) kroki.push(Math.abs(litery[i].y - litery[i - 1].y));
  const zwykly = kroki.slice().sort((a, b) => a - b)[Math.floor(kroki.length / 2)] || 0;
  console.log('  typowy krok (mediana): ' + zwykly.toFixed(2) + ' px');
  let ile = 0;
  for (let i = 1; i < litery.length; i++) {
    const d = Math.abs(litery[i].y - litery[i - 1].y);
    if (d > Math.max(6, zwykly * 6)) {
      console.log('  ' + String(litery[i].t).padStart(6) + ' ms   skok ' + d.toFixed(1)
        + ' px   (y ' + litery[i - 1].y + ' → ' + litery[i].y + ')');
      if (++ile >= 15) break;
    }
  }
  if (!ile) console.log('  (żadnego)');

  const splity = await page.evaluate(() => window.__splity || []);
  console.log('\n── KOSZT KAŻDEGO PODZIAŁU TEKSTU ──');
  if (!splity.length) console.log('  (żadnego nie przechwycono)');
  splity.forEach((x) => console.log('  ' + String(x.t).padStart(6) + ' ms   '
    + String(x.ms).padStart(7) + ' ms   ' + String(x.znakow).padStart(5) + ' znaków   ' + x.cel));
  const suma = splity.reduce((a, x) => a + x.ms, 0);
  console.log('  razem: ' + suma.toFixed(1) + ' ms w ' + splity.length + ' wywołaniach');

  const pierwsza = litery.length ? litery[0].t : null;
  console.log('\n  pierwsza próbka litery: ' + pierwsza + ' ms');
  await b.close();
})();
