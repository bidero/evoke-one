/**
 * Pliki `.min` frontu (1.248.0).
 *
 * Zgłoszone: „w kodzie strony jest mnóstwo poprawek, opisów w skryptach,
 * które wcale nie są tam potrzebne". Decyzja: pliki JS i CSS dostają wersje
 * `.min` budowane przed wydaniem (tools/minifikuj.js, jak Animator), strona
 * dostaje je przez evk_zasob_url() (includes/02-zasoby-frontu.php), a przy
 * SCRIPT_DEBUG — pełne źródła.
 *
 * Testy zachowania chodzą na ŹRÓDŁACH (fixtury wczytują je wprost), więc ten
 * plik pilnuje reszty:
 *   · wytwory są aktualne i naprawdę bez komentarzy;
 *   · żaden plik frontu nie omija listy ani wyboru (pokrycie w PHP);
 *   · wybór w PHP: `.min`, a źródło przy SCRIPT_DEBUG i gdy `.min` nie ma;
 *   · prawdziwa strona w testowym WordPressie dostaje `.min`;
 *   · fixtury wczytane z `.min`: bez błędów, a gdzie wolno porównać — te same
 *     style co na źródłach.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync } = require('child_process');
const { ROOT, FIXTURES, phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const esbuild = require('esbuild');
const serwerWp = require('./lib/wp-serwer');
/* acorn przychodzi z terserem (jego zależność) — stąd rozwiązanie ścieżki od
   tersera, a nie od korzenia: nie zależymy od tego, jak npm spłaszczy drzewo. */
const acorn = require(require.resolve('acorn', { paths: [path.dirname(require.resolve('terser'))] }));

const MINIFIKUJ = path.join(ROOT, 'tools', 'minifikuj.js');
const doMin = (p) => p.replace(/\.(js|css)$/, '.min.$1');

module.exports = async function (t) {
  const lista = JSON.parse(execFileSync('node', [MINIFIKUJ, '--lista'], { encoding: 'utf8' }));

  // ── Wytwory ──────────────────────────────────────────────────────────────
  t.section('pliki .min zbudowane z bieżących źródeł');
  let swieze = true, powod = '';
  try {
    powod = execFileSync('node', [MINIFIKUJ, '--sprawdz'], { encoding: 'utf8', stdio: 'pipe' }).trim();
  } catch (e) {
    swieze = false;
    powod = String(e.stderr || e.stdout || e.message).trim().split('\n').slice(0, 3).join(' | ');
  }
  t.check('każdy plik .min aktualny (node tools/minifikuj.js)', swieze, powod);
  t.check('lista: 23 pliki frontu', lista.length === 23, String(lista.length));

  t.section('pliki .min: bez komentarzy, parsują się');
  const zleJs = [], zleCss = [];
  let zrodla = 0, skrocone = 0;
  for (const p of lista) {
    const min = path.join(ROOT, doMin(p));
    if (!fs.existsSync(min)) { (p.endsWith('.js') ? zleJs : zleCss).push(doMin(p) + ': brak'); continue; }
    const kod = fs.readFileSync(min, 'utf8');
    zrodla += fs.statSync(path.join(ROOT, p)).size;
    skrocone += Buffer.byteLength(kod);
    if (p.endsWith('.js')) {
      /* Jeden komentarz: nagłówek „wytwór tools/minifikuj.js". Parser, nie
         wyrażenie regularne — `//` bywa w adresach w łańcuchach. */
      const komentarze = [];
      try { acorn.parse(kod, { ecmaVersion: 'latest', onComment: komentarze }); } catch (e) { zleJs.push(doMin(p) + ': ' + e.message); continue; }
      if (komentarze.length !== 1 || !/wytwór tools\/minifikuj\.js/.test(komentarze[0].value)) {
        zleJs.push(doMin(p) + ': ' + komentarze.length + ' komentarzy');
      }
    } else {
      try { esbuild.transformSync(kod, { loader: 'css' }); } catch (e) { zleCss.push(doMin(p) + ': ' + e.message.split('\n')[0]); continue; }
      const ile = (kod.match(/\/\*/g) || []).length;
      if (ile !== 1) zleCss.push(doMin(p) + ': ' + ile + ' komentarzy');
    }
  }
  t.check('JS: każdy parsuje się, jedyny komentarz to nagłówek', zleJs.length === 0, zleJs.join(', ') || lista.filter((p) => p.endsWith('.js')).length + ' plików');
  t.check('CSS: każdy parsuje się, jedyny komentarz to nagłówek', zleCss.length === 0, zleCss.join(', ') || lista.filter((p) => p.endsWith('.css')).length + ' plików');
  t.check('razem co najmniej o 60% mniej', skrocone < zrodla * 0.4,
    Math.round(zrodla / 1024) + ' → ' + Math.round(skrocone / 1024) + ' KiB');

  // ── Pokrycie ─────────────────────────────────────────────────────────────
  /* Każdy literał „…assets/….js|css" w PHP frontu (bez panelu, bibliotek
     zewnętrznych i plików już skróconych) to plik, który jedzie na stronę.
     Ma być na liście i iść przez evk_zasob_url() — w rejestrze elementów
     loader.php przepuszcza go przez nią przy rejestracji. */
  t.section('pokrycie: PHP kolejkuje tylko pliki z listy, przez evk_zasob_url()');
  const php = [];
  (function chodz(d) {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) { if (path.relative(ROOT, p) !== path.join('includes', 'admin')) chodz(p); } else if (p.endsWith('.php')) php.push(p);
    }
  })(path.join(ROOT, 'includes'));
  const znalezione = [], pozaLista = [], bezWyboru = [];
  const nazwyListy = lista.map((p) => path.basename(p));
  const loader = path.join(ROOT, 'includes', 'bricks-elements', 'loader.php');
  for (const p of php) {
    fs.readFileSync(p, 'utf8').split('\n').forEach((linia, i) => {
      for (const m of linia.matchAll(/'([A-Za-z0-9_./-]*assets\/[A-Za-z0-9_./-]+\.(?:js|css))'/g)) {
        if (/\/vendor\/|assets\/admin\/|\.min\./.test(m[1])) continue;
        const gdzie = path.relative(ROOT, p) + ':' + (i + 1);
        znalezione.push(path.basename(m[1]));
        if (!nazwyListy.includes(path.basename(m[1]))) pozaLista.push(gdzie + ' ' + m[1]);
        const wRejestrze = p === loader && /'(?:script|style)'\s*=>/.test(linia);
        if (!wRejestrze && !linia.includes('evk_zasob_url(')) bezWyboru.push(gdzie + ' ' + m[1]);
      }
    });
  }
  const tekstLoadera = fs.readFileSync(loader, 'utf8');
  t.check('pliki frontu znalezione w PHP (kontrola skanu)', znalezione.length >= 23, znalezione.length + ' miejsc');
  t.check('każdy jest na liście tools/minifikuj.js', pozaLista.length === 0, pozaLista.join(', ') || 'wszystkie');
  t.check('i każdy z listy jest kolejkowany (lista bez martwych wpisów)', nazwyListy.every((n) => znalezione.includes(n)),
    nazwyListy.filter((n) => !znalezione.includes(n)).join(', ') || 'wszystkie');
  t.check('każde miejsce kolejkowania idzie przez evk_zasob_url()', bezWyboru.length === 0, bezWyboru.join(', ') || 'wszystkie');
  t.check('rejestr elementów: rejestracja skryptu i stylu przez evk_zasob_url()',
    /wp_register_script\(\$el\['script'\]\[0\], evk_zasob_url\(\$el\['script'\]\[1\]\)/.test(tekstLoadera)
      && /wp_register_style\(\$el\['style'\]\[0\], evk_zasob_url\(\$el\['style'\]\[1\]\)/.test(tekstLoadera));

  // ── Wybór pliku w PHP ───────────────────────────────────────────────────
  t.section('wybór pliku w PHP: .min, a źródło przy SCRIPT_DEBUG i bez .min');
  const zwykle = JSON.parse(phpOutput('minifikacja.php', 'pomocnik'));
  const debug = JSON.parse(phpOutput('minifikacja.php', 'debug'));
  t.check('plik z wersją .min (JS modułu, CSS elementu) → .min',
    zwykle['js z .min'] === 'assets/js/parallax.min.js'
      && zwykle['css elementu z .min'] === 'includes/bricks-elements/evoke-offcanvas-menu/assets/offcanvas-menu.min.css', JSON.stringify(zwykle));
  t.check('bez pliku .min, już skrócony, obcy adres, nie JS/CSS → bez zmian',
    zwykle['bez pliku .min'] === 'assets/js/nie-ma-takiego.js' && zwykle['już skrócony'] === 'assets/vendor/gsap/gsap.min.js'
      && zwykle['obcy adres'] === 'https://cdn.example.test/assets/js/parallax.js' && zwykle['nie js ani css'] === 'assets/img/logo.png', JSON.stringify(zwykle));
  t.check('SCRIPT_DEBUG → pełne źródła', debug['js z .min'] === 'assets/js/parallax.js'
    && debug['css elementu z .min'] === 'includes/bricks-elements/evoke-offcanvas-menu/assets/offcanvas-menu.css', JSON.stringify(debug));

  // ── Prawdziwa strona ────────────────────────────────────────────────────
  t.section('strona w testowym WordPressie dostaje pliki .min');
  const u = JSON.parse(phpOutput('minifikacja.php', 'strona-ustaw'));
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !u.brak && !!u.wp, u.brak || u.wp);
  if (u.wp) {
    let serwer = null;
    try {
      serwer = await serwerWp.start(u.wp);
      const html = await (await fetch(serwer.baza + '/')).text();
      const nasze = [...html.matchAll(/<(?:script|link)\b[^>]*(?:src|href)=["']([^"']*\/plugins\/[^"'/]+\/[^"']+\.(?:js|css))(?:\?[^"']*)?["']/g)]
        .map((m) => m[1].replace(/^.*?\/plugins\/[^/]+\//, '')).filter((p) => !/\/vendor\//.test(p));
      const zrodlowe = nasze.filter((p) => !/\.min\.(js|css)$/.test(p));
      t.check('moduły frontu wysłały własne pliki (kontrola)', ['assets/js/parallax.min.js', 'assets/js/accessibility.min.js', 'assets/js/bg-shift.min.js']
        .every((p) => nasze.includes(p)), nasze.join(', '));
      t.check('żaden plik wtyczki nie jedzie jako źródło', zrodlowe.length === 0, zrodlowe.join(', ') || nasze.length + ' plików, wszystkie .min');
    } finally {
      if (serwer) await serwer.zatrzymaj();
      phpOutput('minifikacja.php', 'strona-przywroc');
    }
  }

  // ── Fixtury z plikami .min ──────────────────────────────────────────────
  /* Każda fixtura, która wczytuje plik z listy, raz ze źródłami, raz z .min
     (kopia w katalogu tymczasowym z <base> na katalog fixtur). Błędy JS: zero
     w obu. Style: podpis stylów wyliczonych po 800 ms — porównywany tylko
     tam, gdzie dwa przebiegi na ŹRÓDŁACH dają to samo i fixtura wczytuje CSS
     z listy. Zmierzone przed ustawieniem reguły: pętle animacji (loop,
     marquee) mają przy .min inną fazę, bo plik wczytuje się szybciej —
     to nie jest różnica w działaniu. */
  t.section('fixtury wczytane z plików .min');
  const fixtury = fs.readdirSync(FIXTURES).filter((f) => f.endsWith('.html')
    && nazwyListy.some((n) => fs.readFileSync(path.join(FIXTURES, f), 'utf8').includes('/' + n + '"')));
  const wersja = (html, min) => {
    let h = html;
    if (min) for (const n of nazwyListy) h = h.split('/' + n + '"').join('/' + doMin(n) + '"');
    const baza = '<base href="file://' + FIXTURES + '/">';
    return /^\s*<!doctype[^>]*>/i.test(h) ? h.replace(/^(\s*<!doctype[^>]*>)/i, '$1' + baza) : baza + h;
  };
  const podpis = () => [...document.querySelectorAll('body *')].slice(0, 400).map((e) => {
    const c = getComputedStyle(e);
    return e.tagName + '.' + (typeof e.className === 'string' ? e.className : '') + '|'
      + ['display', 'position', 'width', 'height', 'opacity', 'transform', 'color', 'background-color', 'visibility',
        'z-index', 'overflow', 'box-shadow', 'border-radius', 'font-size', 'margin-top', 'padding-top'].map((k) => c.getPropertyValue(k)).join(',');
  }).join('\n');
  const browser = await chromium.launch({ executablePath: chromiumPath() });
  const bledyMin = [], rozne = [], porownane = [], niestabilne = [];
  let wczytanychMin = 0;
  try {
    const przebieg = async (f, html, min, klucz) => {
      const plik = path.join(os.tmpdir(), 'evk-min-' + process.pid + '-' + klucz + '-' + f);
      fs.writeFileSync(plik, wersja(html, min));
      const ctx = await browser.newContext({ viewport: { width: 1200, height: 800 } });
      const p = await ctx.newPage();
      const bledy = [];
      p.on('pageerror', (e) => bledy.push(e.message));
      p.on('console', (m) => { if (m.type() === 'error') bledy.push(m.text()); });
      try {
        await p.goto('file://' + plik);
        await p.waitForTimeout(800);
        return { bledy, podpis: await p.evaluate(podpis),
          min: await p.evaluate(() => [...document.querySelectorAll('script[src],link[rel=stylesheet]')]
            .map((e) => e.src || e.href).filter((s) => /\.min\.(js|css)$/.test(s) && !/\/vendor\//.test(s)).length) };
      } finally {
        await ctx.close();
        fs.unlinkSync(plik);
      }
    };
    for (const f of fixtury) {
      const html = fs.readFileSync(path.join(FIXTURES, f), 'utf8');
      const z1 = await przebieg(f, html, false, 'z1');
      const m = await przebieg(f, html, true, 'm');
      wczytanychMin += m.min;
      if (m.bledy.length > z1.bledy.length) bledyMin.push(f + ': ' + m.bledy.slice(0, 2).join(' | '));
      if (!nazwyListy.some((n) => n.endsWith('.css') && html.includes('/' + n + '"'))) continue;
      const z2 = await przebieg(f, html, false, 'z2');
      if (z1.podpis !== z2.podpis) { niestabilne.push(f); continue; }
      porownane.push(f);
      if (z1.podpis !== m.podpis) rozne.push(f);
    }
  } finally {
    await browser.close();
  }
  t.check('fixtury z plikami z listy (kontrola)', fixtury.length >= 25 && wczytanychMin >= fixtury.length,
    fixtury.length + ' fixtur, ' + wczytanychMin + ' wczytań .min');
  t.check('z .min żadna nie ma więcej błędów JS niż ze źródłami', bledyMin.length === 0, bledyMin.join(' || ') || 'brak');
  t.check('style z CSS .min takie same jak ze źródeł', rozne.length === 0 && porownane.length >= 10,
    rozne.join(', ') || porownane.length + ' porównanych' + (niestabilne.length ? ', niestabilne: ' + niestabilne.join(', ') : ''));
};
