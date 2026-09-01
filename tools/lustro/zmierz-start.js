/**
 * Ile trwa czekanie na treść pod zasłoną Animatora.
 *
 * ZGŁOSZONE Z UŻYCIA: „włączony Animator powoduje, że elementy pojawiają się
 * z opóźnieniem", „w mobilnym Safari bez opóźnień, w Chrome na Androidzie
 * bardzo duże", „na wolniejszym komputerze pojawiają się później — nawet jeśli
 * animacja to hover".
 *
 * Zasłona (`render_preveil()` w includes/anim/animator.php) chowa treść
 * z animacją już w <head>, a zdejmuje ją dopiero silnik na końcu pierwszego
 * przebiegu — czyli po pobraniu, sparsowaniu i wykonaniu kompletu GSAP
 * + animator.js. Ten pomiar podaje, ile to trwa.
 *
 * DWA DŁAWIENIA, BO SĄ DWIE RÓŻNE PRZYCZYNY i mylenie ich prowadzi donikąd:
 *
 * — PROCESOR (4×, 6×) odwzorowuje średniego Androida i starszego desktopa.
 *   Tu kosztuje parsowanie i wykonanie ~200 KiB JS-a. Preload tego nie skróci.
 * — SIEĆ (wolna) odwzorowuje to, czego lustro na localhoście nie ma wcale:
 *   czas pobrania plików. Dopiero tu widać, co daje `<link rel="preload">`,
 *   bo bez niego pobieranie rusza wtedy, gdy parser dojdzie do stopki.
 *
 * Mierzenie tylko na localhoście pokazałoby, że preload nic nie daje — i byłby
 * to wniosek z warunków, w których nikt tej strony nie ogląda.
 *
 *   node tools/lustro/zmierz-start.js [adres]
 *
 * Domyślnie http://127.0.0.1:8765 (tools/lustro/serwuj.sh).
 */

const { chromium } = require('playwright-core');
const path = require('path');
const { chromiumPath } = require(path.join(__dirname, '..', '..', 'tests', 'lib', 'harness.js'));

const ADRES = process.argv[2] || 'http://127.0.0.1:8765/';

/** Dławienie sieci zbliżone do „Fast 3G" z DevToolsów. */
const WOLNA_SIEC = {
  offline: false,
  downloadThroughput: (1.6 * 1024 * 1024) / 8,
  uploadThroughput: (750 * 1024) / 8,
  latency: 150,
};

const SCENARIUSZE = [
  { cpu: 1, siec: null,       opis: '1× / sieć lokalna' },
  { cpu: 4, siec: null,       opis: '4× / sieć lokalna' },
  { cpu: 6, siec: null,       opis: '6× / sieć lokalna' },
  { cpu: 1, siec: WOLNA_SIEC, opis: '1× / sieć wolna' },
  { cpu: 4, siec: WOLNA_SIEC, opis: '4× / sieć wolna' },
  { cpu: 6, siec: WOLNA_SIEC, opis: '6× / sieć wolna' },
  /* Redukcja ruchu — najpewniejszy kandydat na zgłoszenie „na starszym
     komputerze cała animacja wejścia się nie odtwarza". Windows ma systemowe
     „Pokaż animacje", na starszych maszynach często wyłączone. Nic się wtedy
     nie animuje, więc zasłona nie ma czego chować i nie powinno jej być
     w ogóle — a do 1.125.0 taki użytkownik czekał na GSAP-a jak każdy inny. */
  { cpu: 4, siec: null, redukcja: true, opis: '4× / redukcja ruchu' },
];

/* Notatnik zakładany PRZED jakimkolwiek skryptem strony: zapisuje moment
   zdjęcia zasłony i to, ile elementów naprawdę chowała. */
const SZPIEG = `
window.__zaslona = { start: null, koniec: null, ukrytych: null, zdjal: null };
(function () {
  /* Obserwujemy DOKUMENT, nie <html>: skrypt wstrzykiwany na starcie biegnie,
     zanim element <html> w ogóle powstanie — document.documentElement jest
     wtedy null. Sprawdzone: obserwator zakładany na nim wywalał się wyjątkiem
     i cały pomiar wychodził pusty. */
  var zapisz = function () {
    if (window.__zaslona.koniec !== null) return;
    window.__zaslona.koniec = performance.now();
  };
  new MutationObserver(function () {
    var h = document.documentElement;
    if (!h) return;
    if (window.__zaslona.start === null && h.classList.contains('evk-veil')) {
      window.__zaslona.start = performance.now();
    }
    if (window.__zaslona.start !== null && !h.classList.contains('evk-veil')) zapisz();
  }).observe(document, { attributes: true, subtree: true, attributeFilter: ['class'] });

  /* Ile elementów zasłona chowa WŁASNĄ REGUŁĄ.
   *
   * Nie przez getComputedStyle: visibility się DZIEDZICZY, więc dziecko ukrytej
   * sekcji też wychodzi ukryte i licznik pokazywał 102 ze 103 elementów
   * niezależnie od tego, co w regule stoi. Bierzemy selektor prosto z arkusza
   * i pytamy, co się z nim zgadza. */
  document.addEventListener('DOMContentLoaded', function () {
    var styl = document.getElementById('evk-anim-preveil');
    if (!styl || !styl.sheet) { window.__zaslona.ukrytych = null; return; }
    var sel = [];
    for (var i = 0; i < styl.sheet.cssRules.length; i++) {
      var r = styl.sheet.cssRules[i];
      if (r.selectorText && r.selectorText.indexOf('evk-veil') !== -1) sel.push(r.selectorText);
    }
    if (!sel.length) { window.__zaslona.ukrytych = 0; return; }
    var bylo = document.documentElement.classList.contains('evk-veil');
    document.documentElement.classList.add('evk-veil');
    window.__zaslona.ukrytych = document.querySelectorAll(sel.join(',')).length;
    if (!bylo) document.documentElement.classList.remove('evk-veil');
  });
})();
`;

(async () => {
  const browser = await chromium.launch({ executablePath: chromiumPath() });
  console.log('adres:', ADRES);
  console.log('');
  console.log('scenariusz          zasłona zdjęta   pod zasłoną   FCP       kto zdjął');
  console.log('─────────────────────────────────────────────────────────────────────');

  for (const sc of SCENARIUSZE) {
    const ctx = await browser.newContext({
      viewport: { width: 1400, height: 900 },
      reducedMotion: sc.redukcja ? 'reduce' : 'no-preference',
    });
    const page = await ctx.newPage();
    await page.addInitScript({ content: SZPIEG });

    const cdp = await ctx.newCDPSession(page);
    await cdp.send('Emulation.setCPUThrottlingRate', { rate: sc.cpu });
    if (sc.siec) {
      await cdp.send('Network.enable');
      await cdp.send('Network.emulateNetworkConditions', sc.siec);
    }

    await page.goto(ADRES, { waitUntil: 'load' });
    /* Dłużej niż bezpiecznik, żeby zmierzyć także przypadek, w którym silnik
       nie zdążył i zasłonę zdjął timeout. */
    await page.waitForTimeout(3600);

    /* KTO zdjął zasłonę — pytamy silnika, nie zgadujemy z zegara. Próg czasowy
       mylił się dokładnie tam, gdzie to najważniejsze: przy 1693 ms na wolnej
       sieci nie da się na oko orzec, czy zdążył silnik, czy wypadł bezpiecznik.
       `zaslonaMs === null` znaczy, że silnik zastał zasłonę już zdjętą. */
    const w = await page.evaluate(() => ({
      z: window.__zaslona,
      stan: typeof window.evkAnimatorStan === 'function' ? window.evkAnimatorStan() : null,
      fcp: (performance.getEntriesByType('paint')
        .find((e) => e.name === 'first-contentful-paint') || {}).startTime || null,
    }));
    const kto = !w.stan ? '(silnik nie ruszył)'
      : (w.stan.zaslonaMs === null ? 'bezpiecznik' : 'silnik');

    const ms = (x) => (x === null || x === undefined ? '—' : Math.round(x) + ' ms');
    console.log(
      sc.opis.padEnd(19),
      ms(w.z.koniec).padEnd(16),
      String(w.z.ukrytych === null ? '—' : w.z.ukrytych).padEnd(13),
      ms(w.fcp).padEnd(9),
      w.z.koniec === null ? '(zasłony nie było)' : kto
    );
    await ctx.close();
  }

  await browser.close();
})();
