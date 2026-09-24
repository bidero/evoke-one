const ROOT = require('path').resolve(__dirname, '../..');
const serwerWp = require(ROOT + '/tests/lib/wp-serwer.js');
(async () => {
  const srv = await serwerWp.start((process.env.EVK_WP_PATH || process.env.HOME + '/.cache/evk-testowy-wp'));
  try {
    const html = await (await fetch(srv.baza + '/')).text();
    const bloki = [...html.matchAll(/<(script|style)([^>]*)>([\s\S]*?)<\/\1>/g)].filter((m) => !/\bsrc=/.test(m[2])).map((m) => ({ t: m[1], a: m[2].trim().slice(0, 60), n: m[3].length, p: m[3].trim().slice(0, 70).replace(/\s+/g, ' ') }));
    bloki.sort((a, b) => b.n - a.n).slice(0, 12).forEach((b) => console.log(b.t.padEnd(7) + String(b.n).padStart(7) + '  ' + b.a.padEnd(40) + ' ' + b.p));
  } finally { await srv.zatrzymaj(); }
})();
