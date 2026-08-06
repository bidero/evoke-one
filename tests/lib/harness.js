/**
 * Evoke ONE — wspólna obsługa testów
 *
 * Bez frameworka: czysty Node i playwright-core. Testy sprawdzają zachowanie
 * silników w prawdziwej przeglądarce, bo większość usterek, które łapią,
 * to geometria, kolory i drzewo dostępności — rzeczy niewidoczne w testach
 * jednostkowych na atrapach.
 *
 * DWIE ZASADY, obie okupione znalezionymi błędami:
 *
 * 1. CSS I JS CZYTAMY Z PLIKÓW WTYCZKI, nigdy z kopii w teście. Kopia zaczyna
 *    żyć własnym życiem i test przestaje sprawdzać cokolwiek. Fragmenty
 *    generowane przez PHP bierzemy z PHP (patrz phpOutput), a nie regexem
 *    ze źródła.
 *
 * 2. STRONY OTWIERAMY PRZEZ goto NA PLIKU, nigdy przez setContent. Silniki
 *    startują na DOMContentLoaded, a przy setContent zdarzenie wypala zanim
 *    dołożymy skrypty — silnik nie rusza wcale. Test wygląda wtedy na zielony,
 *    bo „nic się nie animuje” jest właśnie tym, czego często oczekujemy.
 *    Dlatego każdy blok ma parę: przypadek badany I kontrolę negatywną.
 */

const { chromium } = require('playwright-core');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const ROOT     = path.resolve(__dirname, '..', '..');
const FIXTURES = path.join(__dirname, '..', 'fixtures');

/**
 * Ścieżka do Chromium. Kolejno: jawna zmienna, katalog przeglądarek Playwrighta,
 * typowe lokalizacje systemowe. Bez tego test pada z komunikatem o niczym.
 */
function chromiumPath() {
  if (process.env.EVK_CHROMIUM) return process.env.EVK_CHROMIUM;

  const bases = [
    process.env.PLAYWRIGHT_BROWSERS_PATH,
    path.join(process.env.HOME || '', '.cache', 'ms-playwright'),
  ].filter(Boolean);

  for (const base of bases) {
    if (!fs.existsSync(base)) continue;
    for (const dir of fs.readdirSync(base).filter((d) => d.startsWith('chromium'))) {
      for (const rel of ['chrome-linux/chrome', 'chrome-mac/Chromium.app/Contents/MacOS/Chromium']) {
        const p = path.join(base, dir, rel);
        if (fs.existsSync(p)) return p;
      }
    }
  }

  for (const p of ['/usr/bin/chromium', '/usr/bin/chromium-browser', '/usr/bin/google-chrome',
                   '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome']) {
    if (fs.existsSync(p)) return p;
  }

  throw new Error(
    'Nie znaleziono Chromium. Wskaż go zmienną EVK_CHROMIUM=/ścieżka/do/chrome ' +
    'albo ustaw PLAYWRIGHT_BROWSERS_PATH.'
  );
}

/** Uruchamia plik PHP z katalogu tests/php i zwraca jego wyjście. */
function phpOutput(file, args) {
  return execSync('php ' + JSON.stringify(path.join(__dirname, '..', 'php', file)) +
                  (args ? ' ' + args : '')).toString();
}

/** Wycina zawartość znacznika <style|script id="..."> z wyjścia PHP. */
function tagContent(html, id) {
  const m = html.match(new RegExp('<(?:style|script) id="' + id + '">([\\s\\S]*?)</(?:style|script)>'));
  if (!m) throw new Error('Nie znaleziono znacznika o id "' + id + '" w wyjściu PHP.');
  return m[1];
}

/** Kolor „rgb(a, b, c)” → [a, b, c]. Przeglądarka zaokrągla, stąd porównania z tolerancją. */
const rgb = (s) => {
  const m = (s || '').match(/[\d.]+/g);
  return m ? m.slice(0, 3).map(Number) : null;
};
const near = (a, b, tol = 6) =>
  !!a && !!b && a.length === 3 && a.every((v, i) => Math.abs(v - b[i]) <= tol);

class Runner {
  constructor() {
    this.results = [];
    this.browser = null;
  }

  async start() {
    this.browser = await chromium.launch({ executablePath: chromiumPath() });
  }

  async stop() {
    if (this.browser) await this.browser.close();
  }

  section(title) {
    console.log('\n  ── ' + title + ' ' + '─'.repeat(Math.max(0, 58 - title.length)));
  }

  check(name, pass, detail) {
    this.results.push({ name, pass });
    console.log('  ' + (pass ? ' OK  ' : 'BŁĄD ') + name.padEnd(44) + (detail === undefined ? '' : detail));
  }

  /**
   * Otwiera fixture. `head` to kod wstrzykiwany przed skryptami strony —
   * odpowiednik tego, co wtyczka drukuje w <head>.
   */
  async open(fixture, opts) {
    opts = opts || {};
    const ctx = await this.browser.newContext({
      viewport: opts.viewport || { width: 1200, height: 800 },
      reducedMotion: opts.reduce ? 'reduce' : 'no-preference',
    });
    const page = await ctx.newPage();
    page.errors = [];
    page.warnings = [];
    page.on('pageerror', (e) => page.errors.push(e.message));
    page.on('console', (m) => {
      if (m.type() === 'error') page.errors.push(m.text());
      if (m.type() === 'warning') page.warnings.push(m.text());
    });
    if (opts.head) await page.addInitScript({ content: opts.head });
    await page.goto('file://' + path.join(FIXTURES, fixture) + (opts.query ? '?' + opts.query : ''));
    await page.waitForTimeout(opts.settle === undefined ? 450 : opts.settle);
    return page;
  }
}

module.exports = { Runner, ROOT, FIXTURES, chromiumPath, phpOutput, tagContent, rgb, near };
