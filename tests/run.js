#!/usr/bin/env node
/**
 * Evoke ONE — uruchamianie testów
 *
 *   node tests/run.js              wszystko
 *   node tests/run.js motion bg    tylko pasujące nazwą
 *
 * Kod wyjścia 1, gdy cokolwiek padnie — nadaje się pod CI.
 */

const fs = require('fs');
const path = require('path');
const { Runner } = require('./lib/harness');

const filters = process.argv.slice(2);

const files = fs.readdirSync(__dirname)
  .filter((f) => f.endsWith('.test.js'))
  .filter((f) => !filters.length || filters.some((q) => f.includes(q)))
  .sort();

if (!files.length) {
  console.error('Brak testów pasujących do: ' + filters.join(', '));
  process.exit(1);
}

(async () => {
  const r = new Runner();
  await r.start();

  for (const f of files) {
    console.log('\n══ ' + f.replace('.test.js', ''));
    try {
      await require(path.join(__dirname, f))(r);
    } catch (e) {
      r.check(f + ' — wyjątek', false, e.message);
    }
  }

  await r.stop();

  const bad = r.results.filter((x) => !x.pass);
  console.log('\n' + '─'.repeat(66));
  console.log(bad.length
    ? 'BŁĄD  ' + bad.length + ' z ' + r.results.length + ' sprawdzeń nie przeszło:\n' +
      bad.map((x) => '        · ' + x.name).join('\n')
    : ' OK   wszystkie ' + r.results.length + ' sprawdzeń przeszło');

  process.exit(bad.length ? 1 : 0);
})().catch((e) => {
  console.error('\nBŁĄD krytyczny: ' + e.message);
  process.exit(1);
});
