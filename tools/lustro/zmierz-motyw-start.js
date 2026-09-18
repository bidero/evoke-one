/**
 * Kto i kiedy ustawia motyw przy ŁADOWANIU strony.
 *
 * ZAOBSERWOWANE przy pomiarze fali (1.218.0): atrybuty motywu zmieniają się na
 * starcie dwa razy — najpierw „light", a koło sekundy później „dark". Skąd to
 * drugie, nie było wiadomo, a czytanie kodu dało trzy wyjaśnienia, z których
 * wszystkie odpadły. Stąd pomiar, a nie czwarta hipoteza.
 *
 * Sonda (`sonda-motyw.js`) loguje KAŻDY zapis mogący zmienić motyw razem ze
 * śladem stosu, więc wynik wskazuje wprost plik i linię sprawcy. Przebieg idzie
 * w dwóch wariantach pamięci lokalnej — pustej (pierwsza wizyta) i „dark"
 * (stały użytkownik) — bo to właśnie ona decyduje, co przeczytają oba skrypty.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');
const { chromiumPath } = require('../../tests/lib/harness.js');

const ADRES = process.env.ADRES || 'http://127.0.0.1:8765/';
const SONDA = fs.readFileSync(path.join(__dirname, 'sonda-motyw.js'), 'utf8');

async function przebieg(b, tryb) {
  const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } });
  const page = await ctx.newPage();
  await page.addInitScript({ content: SONDA });
  if (tryb) {
    await page.addInitScript({
      content: "try { localStorage.setItem('brx_mode', '" + tryb + "'); } catch (e) {}"
    });
  }
  await page.goto(ADRES, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(8000);

  const zapisy = await page.evaluate(() => window.__zapisy);
  const malowanie = await page.evaluate(() => window.__malowanie);
  const koniec = await page.evaluate(() => ({
    theme: document.documentElement.getAttribute('data-theme'),
    brx: document.documentElement.getAttribute('data-brx-theme'),
    klasa: document.documentElement.className,
    pamiec: (function () { try { return localStorage.getItem('brx_mode'); } catch (e) { return '?'; } })()
  }));

  console.log('\n╔══ PAMIĘĆ LOKALNA: ' + (tryb || '(pusta — pierwsza wizyta)'));
  console.log('╚═══════════════════════════════════════════════════════════════');
  if (!zapisy.length) console.log('  (żadnego zapisu nie przechwycono)');
  zapisy.forEach((z) => {
    console.log('  ' + String(z.t).padStart(5) + ' ms  ' + z.co.padEnd(24)
      + ' = ' + z.wartosc.padEnd(18) + '  ← ' + z.skad);
  });
  console.log('\n  malowanie:');
  malowanie.forEach((m) => console.log('  ' + String(m.t).padStart(5) + ' ms  ' + m.nazwa
    + '   data-theme=' + m.theme + '  data-brx-theme=' + m.brx));
  console.log('\n  stan końcowy: ' + JSON.stringify(koniec));

  await ctx.close();
}

(async () => {
  const b = await chromium.launch({ executablePath: chromiumPath(), args: ['--no-proxy-server'] });
  await przebieg(b, null);
  await przebieg(b, 'dark');
  await b.close();
})();
