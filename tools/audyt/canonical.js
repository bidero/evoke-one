/* Ile tagów canonical dostaje strona przy włączonych Tłumaczeniach (PL i /en/)? */
const ROOT = require('path').resolve(__dirname, '../..');
const serwerWp = require(ROOT + '/tests/lib/wp-serwer.js');
const { execSync } = require('child_process');
const WP = (process.env.EVK_WP_PATH || process.env.HOME + '/.cache/evk-testowy-wp');
const W = 'php ' + process.env.HOME + '/.cache/wp-cli.phar --path=' + WP + ' --allow-root ';
(async () => {
  const id = execSync(W + "post create --post_type=page --post_status=publish --post_title='O nas' --post_name=o-nas --porcelain").toString().trim();
  execSync(W + 'rewrite flush');
  const srv = await serwerWp.start(WP);
  try {
    for (const sciezka of ['/o-nas/', '/en/about-us/', '/de/uber-uns/']) {
      const r = await fetch(srv.baza + sciezka, { redirect: 'manual' });
      const html = await r.text();
      const kan = [...html.matchAll(/<link[^>]+rel=["']canonical["'][^>]*>/g)].map((m) => (m[0].match(/href=["']([^"']+)/) || [])[1]);
      const hre = [...html.matchAll(/hreflang=["']([^"']+)["']/g)].map((m) => m[1]);
      console.log(sciezka.padEnd(16) + ' HTTP ' + r.status + '  canonical ×' + kan.length + ': ' + kan.join('  |  ') + '   hreflang: ' + hre.join(','));
    }
  } finally { await srv.zatrzymaj(); execSync(W + 'post delete ' + id + ' --force'); }
})().catch((e) => { console.error(e); process.exit(1); });
