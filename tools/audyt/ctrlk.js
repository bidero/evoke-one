/* Ctrl+K na ekranie Evoke ONE: otwiera się paleta wtyczki, paleta WordPressa, czy obie? */
const ROOT = require('path').resolve(__dirname, '../..');
const fs = require('fs');
const { chromium } = require(ROOT + '/node_modules/playwright-core');
const { chromiumPath } = require(ROOT + '/tests/lib/harness.js');
const serwerWp = require(ROOT + '/tests/lib/wp-serwer.js');
(async () => {
  const srv = await serwerWp.start((process.env.EVK_WP_PATH || process.env.HOME + '/.cache/evk-testowy-wp'));
  const b = await chromium.launch({ executablePath: chromiumPath(), args: ['--no-proxy-server'] });
  try {
    const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
    await ctx.route(/cdn\.jsdelivr\.net\/npm\/sortablejs/, (r) => r.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(ROOT + '/node_modules/sortablejs/Sortable.min.js') }));
    await ctx.route((u) => !/^http:\/\/127\.0\.0\.1/.test(u.href) && !/sortablejs/.test(u.href), (r) => r.abort('blockedbyclient'));
    const p = await ctx.newPage();
    await serwerWp.zaloguj(p, srv.baza);
    await p.goto(srv.baza + '/wp-admin/options-general.php?page=evoke-one&tab=wydajnosc', { waitUntil: 'load' });
    await p.waitForTimeout(800);
    await p.mouse.click(1000, 100); await p.waitForTimeout(300);
    await p.keyboard.press('Control+k');
    await p.waitForTimeout(800);
    const stan = await p.evaluate(() => ({
      evoke: !!document.querySelector('.evo-command-palette.is-open'),
      wp: !!document.querySelector('.commands-command-menu, [class*="command-palette"] [role="dialog"], .components-modal__frame.commands-command-menu'),
      focus: document.activeElement ? (document.activeElement.id || document.activeElement.className || document.activeElement.tagName) : '',
      dialogi: [...document.querySelectorAll('[role="dialog"]')].filter((d) => d.getBoundingClientRect().width > 0).map((d) => d.className.toString().slice(0, 60)),
    }));
    console.log(JSON.stringify(stan));
    fs.mkdirSync(__dirname + '/wyniki', { recursive: true }); await p.screenshot({ path: __dirname + '/wyniki/ctrlk.png' });
  } finally { await b.close(); await srv.zatrzymaj(); }
})().catch((e) => { console.error(e); process.exit(1); });
