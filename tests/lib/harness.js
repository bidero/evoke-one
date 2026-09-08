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
const http = require('http');

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

/**
 * Uruchamia plik PHP z katalogu tests/php i zwraca jego wyjście.
 *
 * `dopuscBlad` przepuszcza niezerowy kod wyjścia i oddaje to, co proces zdążył
 * wypisać. Jest po to dla jednego scenariusza: badania PRAWDZIWEGO błędu
 * krytycznego, po którym PHP kończy się kodem 255 — tam wywrotka jest badaną
 * rzeczą, a nie awarią testu. Wszędzie indziej niezerowy kod ma zapalać.
 */
function phpOutput(file, args, opcje) {
  const cmd = 'php ' + JSON.stringify(path.join(__dirname, '..', 'php', file)) +
              (args ? ' ' + args : '');
  if (!opcje || !opcje.dopuscBlad) return execSync(cmd).toString();
  try {
    return execSync(cmd, { stdio: ['ignore', 'pipe', 'ignore'] }).toString();
  } catch (e) {
    return (e.stdout || '').toString();
  }
}

/** Wycina zawartość znacznika <style|script id="..."> z wyjścia PHP. */
function tagContent(html, id) {
  const m = html.match(new RegExp('<(?:style|script) id="' + id + '">([\\s\\S]*?)</(?:style|script)>'));
  if (!m) throw new Error('Nie znaleziono znacznika o id "' + id + '" w wyjściu PHP.');
  return m[1];
}

/**
 * Wartość tokenu z arkusza panelu — jedno źródło barw także dla testów.
 *
 * Do 1.138.0 kolor akcentu stał wpisany osobno w dwóch plikach testowych.
 * Przy zmianie marki na #6e00a5 zapaliło się przez to 37 sprawdzeń, które
 * niczego złego nie znalazły — pilnowały drugiej kopii tej samej liczby.
 * Teraz pytamy o nią arkusz, więc test dalej łapie DRYF (przycisk w innym
 * kolorze niż token), ale nie wymaga edycji przy zmianie samej marki.
 */
function tokenPanelu(nazwa) {
  const css = require('fs').readFileSync(
    path.join(__dirname, '..', '..', 'assets', 'admin', 'admin.css'), 'utf8');
  const m = css.match(new RegExp('--' + nazwa + ':\\s*([^;]+);'));
  if (!m) throw new Error('Brak tokenu --' + nazwa + ' w admin.css');
  return m[1].trim();
}

/** Token barwy → [r, g, b]. Przyjmuje zapis #rrggbb. */
function tokenRgb(nazwa) {
  const hex = tokenPanelu(nazwa);
  const m = hex.match(/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i);
  if (!m) throw new Error('Token --' + nazwa + ' nie jest kolorem #rrggbb: ' + hex);
  return m.slice(1).map((h) => parseInt(h, 16));
}

/** Kolor „rgb(a, b, c)” → [a, b, c]. Przeglądarka zaokrągla, stąd porównania z tolerancją. */
const rgb = (s) => {
  const m = (s || '').match(/[\d.]+/g);
  return m ? m.slice(0, 3).map(Number) : null;
};
const near = (a, b, tol = 6) =>
  !!a && !!b && a.length === 3 && a.every((v, i) => Math.abs(v - b[i]) <= tol);

/** Typy zawartości dla serwera fixtur. Tyle, ile naprawdę podajemy. */
const TYPY_MIME = {
  '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.mjs': 'text/javascript',
  '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml',
  '.png': 'image/png', '.jpg': 'image/jpeg', '.webp': 'image/webp',
};

class Runner {
  constructor() {
    this.results = [];
    this.browser = null;
    this.serwer  = null;
    this.port    = 0;
  }

  async start() {
    this.browser = await chromium.launch({ executablePath: chromiumPath() });
  }

  /**
   * Serwer plików repozytorium — wstaje dopiero, gdy któryś fixture go zażąda.
   *
   * PO CO, SKORO RESZTA IDZIE PRZEZ `file://`: moduły ES (`<script type="module">`)
   * przeglądarka odmawia załadować z `file://` — blokuje je reguła pochodzenia.
   * Element Wave Background jest w całości modułem, więc bez serwera nie startuje
   * wcale, a pomiar pokazywałby zero pracy i świecił na zielono z najgorszego
   * możliwego powodu. Serwer podaje katalog repozytorium, więc fixture sięga
   * i po `node_modules`, i po pliki wtyczki tak samo jak przeglądarka na stronie.
   */
  async serwerPlikow() {
    if (this.serwer) return this.port;
    this.serwer = http.createServer((req, res) => {
      const cel = path.join(ROOT, decodeURIComponent(req.url.split('?')[0]));
      if (!cel.startsWith(ROOT) || !fs.existsSync(cel) || fs.statSync(cel).isDirectory()) {
        res.writeHead(404); return res.end('nie ma');
      }
      res.writeHead(200, { 'Content-Type': TYPY_MIME[path.extname(cel)] || 'application/octet-stream' });
      fs.createReadStream(cel).pipe(res);
    });
    await new Promise((ok) => this.serwer.listen(0, '127.0.0.1', ok));
    this.port = this.serwer.address().port;
    return this.port;
  }

  async stop() {
    if (this.browser) await this.browser.close();
    if (this.serwer)  await new Promise((ok) => this.serwer.close(ok));
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
      /* Emulacja dotyku. Zmienia `(hover: hover)` na `false` — zmierzone, bo
         po tym pyta kod pomijający zmiany samej wysokości na telefonie. Bez
         tego ta gałąź jest w testach nieosiągalna i mutacja w niej przechodzi
         na zielono. */
      hasTouch: !!opts.touch,
      /* Gęstość pikseli. Domyślnie 1, bo tyle mają wszystkie dotychczasowe
         fixtury; pomiar fali potrzebuje 2, żeby odtworzyć telefon. */
      deviceScaleFactor: opts.dpr || 1,
    });
    const page = await ctx.newPage();
    /* Dławienie procesora jak w Lighthouse dla telefonu — inaczej pomiar kosztu
       klatki mówi o mocy maszyny testowej, a nie o kodzie. */
    if (opts.dlawienieCPU) {
      const cdp = await ctx.newCDPSession(page);
      await cdp.send('Emulation.setCPUThrottlingRate', { rate: opts.dlawienieCPU });
    }
    page.errors = [];
    page.warnings = [];
    /* Zwykłe logi też, bo niektóre funkcje MAJĄ mówić — i wtedy „konsola
       milczy" trzeba umieć odróżnić od „nie patrzymy". Raport kosztu Animatora
       i diagnostyka przewijania piszą przez console.log, nie warn. */
    page.logs = [];
    page.on('pageerror', (e) => page.errors.push(e.message));
    page.on('console', (m) => {
      if (m.type() === 'error') page.errors.push(m.text());
      if (m.type() === 'warning') page.warnings.push(m.text());
      if (m.type() === 'log') page.logs.push(m.text());
    });
    if (opts.head) await page.addInitScript({ content: opts.head });

    /* `przezHttp` dla fixtur z modułami ES — patrz `serwerPlikow()`. */
    const adres = opts.przezHttp
      ? 'http://127.0.0.1:' + (await this.serwerPlikow()) + '/tests/fixtures/' + fixture
      : 'file://' + path.join(FIXTURES, fixture);
    await page.goto(adres + (opts.query ? '?' + opts.query : ''));
    await page.waitForTimeout(opts.settle === undefined ? 450 : opts.settle);
    return page;
  }
}

module.exports = { Runner, ROOT, FIXTURES, chromiumPath, phpOutput, tagContent, rgb, near,
                   tokenPanelu, tokenRgb };
