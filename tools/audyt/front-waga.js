/* Waga frontu: strona główna testowego WP z modułami wyłączonymi i włączonymi.
   Zlicza skrypty/style (zewnętrzne i wbudowane), bajty, blokujące w <head>, błędy JS. */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');
const ROOT = require('path').resolve(__dirname, '../..');
const { chromium } = require(ROOT + '/node_modules/playwright-core');
const { chromiumPath } = require(ROOT + '/tests/lib/harness.js');
const serwerWp = require(ROOT + '/tests/lib/wp-serwer.js');
const WP = (process.env.EVK_WP_PATH || process.env.HOME + '/.cache/evk-testowy-wp');
const W = 'php ' + process.env.HOME + '/.cache/wp-cli.phar --path=' + WP + ' --allow-root ';
const MODULY = ['evk_darkmode', 'evk_cursor', 'evk_lenis', 'evk_parallax', 'evk_animator', 'evk_bgshift', 'evk_fonts', 'evk_sierotki', 'evk_theme_color', 'evk_a11y', 'evk_schema', 'evk_og'];
function ustaw(wl) {
  for (const m of MODULY) {
    let v = {};
    try { v = JSON.parse(execSync(W + 'option get ' + m + ' --format=json 2>/dev/null').toString() || '{}'); } catch (e) { v = {}; }
    if (!v || typeof v !== 'object' || Array.isArray(v)) v = {};
    v.enabled = wl ? 1 : 0;
    fs.writeFileSync(require('os').tmpdir() + '/evk-opt.json', JSON.stringify(v));
    execSync(W + 'option update ' + m + ' --format=json < /tmp/evk-opt.json');
  }
}
async function zmierz(b, baza, etykieta) {
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  await ctx.route((u) => !/^http:\/\/127\.0\.0\.1/.test(u.href), (r) => r.abort('blockedbyclient'));
  const p = await ctx.newPage();
  const bledy = [], zasoby = [];
  p.on('pageerror', (e) => bledy.push(e.message.slice(0, 160)));
  p.on('console', (m) => { if (m.type() === 'error' && !/BLOCKED_BY_CLIENT|ERR_FAILED/.test(m.text())) bledy.push('konsola: ' + m.text().slice(0, 160)); });
  p.on('response', async (r) => { const t = r.request().resourceType(); if (t === 'script' || t === 'stylesheet') { let n = 0; try { n = (await r.body()).length; } catch (e) {} zasoby.push({ t, u: r.url().replace(baza, ''), n }); } });
  await p.goto(baza + '/', { waitUntil: 'load' });
  await p.waitForTimeout(1500);
  const html = await p.evaluate(() => document.documentElement.outerHTML);
  const surowy = await (await fetch(baza + '/')).text();
  const head = surowy.split('</head>')[0];
  const inlS = [...surowy.matchAll(/<script(?![^>]*\bsrc=)[^>]*>([\s\S]*?)<\/script>/g)].map((m) => m[1].length);
  const inlC = [...surowy.matchAll(/<style[^>]*>([\s\S]*?)<\/style>/g)].map((m) => m[1].length);
  const blok = [...head.matchAll(/<script[^>]*\bsrc="([^"]+)"[^>]*>/g)].filter((m) => !/\b(defer|async)\b/.test(m[0]) && !/type="module"/.test(m[0])).map((m) => m[1].replace(baza, '').split('?')[0]);
  const evk = zasoby.filter((z) => /evoke-one/.test(z.u));
  console.log('── ' + etykieta);
  console.log('  HTML: ' + surowy.length + ' B; wbudowane <script>: ' + inlS.length + ' szt. / ' + inlS.reduce((a, b) => a + b, 0) + ' B; wbudowane <style>: ' + inlC.length + ' szt. / ' + inlC.reduce((a, b) => a + b, 0) + ' B');
  console.log('  pliki JS/CSS wtyczki: ' + evk.length + ' szt. / ' + evk.reduce((a, z) => a + z.n, 0) + ' B');
  evk.forEach((z) => console.log('     ' + z.t.padEnd(10) + String(z.n).padStart(8) + '  ' + z.u.split('?')[0]));
  console.log('  blokujące skrypty w <head>: ' + (blok.length ? blok.join(', ') : 'brak'));
  console.log('  błędy JS: ' + (bledy.length ? bledy.join(' | ') : 'brak'));
  await ctx.close();
}
(async () => {
  const srv = await serwerWp.start(WP);
  const b = await chromium.launch({ executablePath: chromiumPath(), args: ['--no-proxy-server'] });
  try {
    ustaw(false); await zmierz(b, srv.baza, 'moduły frontu WYŁĄCZONE');
    ustaw(true);  await zmierz(b, srv.baza, 'moduły frontu WŁĄCZONE (12)');
  } finally { await b.close(); await srv.zatrzymaj(); }
})().catch((e) => { console.error(e); process.exit(1); });
