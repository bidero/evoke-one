/**
 * Ile kosztuje łańcuch krytyczny wtyczki — i ile dałoby się odzyskać.
 *
 * ZGŁOSZONE Z UŻYCIA: spadek wyniku PageSpeed. Drzewo zależności z PageSpeed dla
 * `evoke.pl/home` pokazuje 9 blokujących arkuszy (6 z wtyczki, po jednym na
 * element) i 17 skryptów, z których ŻADEN nie ma `defer` ani `async`.
 *
 * Pomiar idzie na LUSTRZE i NIE DOTYKA KODU WTYCZKI: warianty powstają przez
 * przepisanie HTML-a kopii strony. Dopiero liczba stąd decyduje, czy warto
 * zmieniać rejestrację skryptów — zgodnie z zasadą „żadnej optymalizacji bez
 * pomiaru".
 *
 * Sieć jest DŁAWIONA. Bez tego lustro stoi na localhoście, każdy plik dociera
 * natychmiast i wychodzi, że liczba żądań nic nie kosztuje — czyli warunki,
 * w których nikt tej strony nie ogląda.
 */
const fs = require('fs');
const path = require('path');
const { chromium } = require('playwright-core');
const { chromiumPath } = require('../../tests/lib/harness.js');

const KAT   = path.join(__dirname, 'strona');
const PORT  = Number(process.env.PORT || 8765);
const PROB  = Number(process.env.PROB || 3);   // powtórzenia, bo pojedynczy pomiar szumi

/** Warianty HTML-a. Każdy dostaje własny plik obok oryginału. */
function warianty() {
  const src = fs.readFileSync(path.join(KAT, 'index.html'), 'utf8');

  // 1. Skrypty wtyczki z `defer`.
  const zDefer = src.replace(
    /<script([^>]*?)src="([^"]*evoke-one-claude[^"]*)"/g,
    (m, a, u) => (/\bdefer\b|\basync\b|type="module"/.test(a) ? m : `<script${a}defer src="${u}"`)
  );

  // 2. To samo + arkusze wtyczki wczytywane bez blokowania renderu.
  const zDeferIcss = zDefer.replace(
    /<link([^>]*?)rel=['"]stylesheet['"]([^>]*?)href=['"]([^'"]*evoke-one-claude[^'"]*\.css[^'"]*)['"]([^>]*)>/g,
    (m, a, b, u, c) =>
      `<link${a}rel="stylesheet"${b}href="${u}"${c} media="print" onload="this.media='all'">`
  );

  return { 'jak-jest': src, 'defer': zDefer, 'defer+css': zDeferIcss };
}

async function pomiar(page, url) {
  await page.goto(url, { waitUntil: 'load', timeout: 60000 });
  await page.waitForTimeout(2500);
  return page.evaluate(() => {
    const p = performance.getEntriesByType('paint');
    const fcp = p.find((x) => x.name === 'first-contentful-paint');
    const lcp = window.__lcp || null;
    const dlugie = (window.__long || []).reduce((s, d) => s + Math.max(0, d - 50), 0);
    return {
      fcp: fcp ? Math.round(fcp.startTime) : null,
      lcp: lcp ? Math.round(lcp) : null,
      tbt: Math.round(dlugie),
      zadan: performance.getEntriesByType('resource').length,
    };
  });
}

const SZPIEG = `
window.__long = [];
try { new PerformanceObserver(function (l) {
  l.getEntries().forEach(function (e) { window.__long.push(e.duration); });
}).observe({ type: 'longtask', buffered: true }); } catch (e) {}
try { new PerformanceObserver(function (l) {
  var e = l.getEntries(); window.__lcp = e[e.length - 1].startTime;
}).observe({ type: 'largest-contentful-paint', buffered: true }); } catch (e) {}
`;

(async () => {
  const w = warianty();
  Object.entries(w).forEach(([n, tresc]) => {
    if (n !== 'jak-jest') fs.writeFileSync(path.join(KAT, 'wariant-' + n + '.html'), tresc);
  });

  const b = await chromium.launch({ executablePath: chromiumPath(), args: ['--no-proxy-server'] });
  console.log('\nwariant                       FCP      LCP      TBT      żądań');
  console.log('─────────────────────────────────────────────────────────────────');

  /* CZWARTY WARIANT: ta sama strona, ale z redukcją ruchu. Wtedy Animator
     NIE ZAKŁADA zasłony `evk-veil` — zmierzone wcześniej przez
     `zmierz-start.js`. Jeśli pierwsze malowanie przeskoczy do przodu właśnie
     tutaj, to znaczy, że łańcucha plików nie ma co ruszać: FCP trzyma zasłona,
     a nie liczba żądań. */
  const lista = [...Object.keys(w), 'bez-zasłony (redukcja ruchu)'];
  for (const nazwa of lista) {
    const bezRuchu = nazwa.indexOf('redukcja') !== -1;
    const plik = (nazwa === 'jak-jest' || bezRuchu) ? '' : 'wariant-' + nazwa + '.html';
    const wyniki = [];
    for (let i = 0; i < PROB; i++) {
      const ctx = await b.newContext({
        viewport: { width: 1280, height: 800 },
        reducedMotion: bezRuchu ? 'reduce' : 'no-preference',
      });
      const page = await ctx.newPage();
      const cdp = await ctx.newCDPSession(page);
      /* Dławienie jak „Slow 4G" w narzędziach przeglądarki — opóźnienie na
         żądanie jest tu istotniejsze od przepustowości, bo badamy LICZBĘ
         plików w łańcuchu, a nie ich wagę. */
      await cdp.send('Network.emulateNetworkConditions', {
        offline: false, latency: 150,
        downloadThroughput: 1.6 * 1024 * 1024 / 8,
        uploadThroughput: 750 * 1024 / 8,
      });
      await page.addInitScript({ content: SZPIEG });
      wyniki.push(await pomiar(page, 'http://127.0.0.1:' + PORT + '/' + plik));
      await ctx.close();
    }
    const med = (k) => {
      const v = wyniki.map((x) => x[k]).filter((x) => x !== null).sort((a, b) => a - b);
      return v.length ? v[Math.floor(v.length / 2)] : '—';
    };
    console.log(nazwa.padEnd(30)
      + String(med('fcp')).padEnd(9) + String(med('lcp')).padEnd(9)
      + String(med('tbt')).padEnd(9) + String(med('zadan')));
  }
  console.log('\nmediana z ' + PROB + ' przebiegów; sieć dławiona (150 ms opóźnienia, 1,6 Mb/s)');
  await b.close();
})();
