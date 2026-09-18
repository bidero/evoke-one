/**
 * Fala przy zmianie motywu — pomiar na LUSTRZE żywej strony.
 *
 * ZGŁOSZONE Z UŻYCIA: „tła elementów przeskakują — zmieniają kolor przed
 * przejściem fali. Tak jak teksty (co ciekawe nie wszystkie) i gradienty".
 *
 * Pięć hipotez obalonych na atrapach z `tests/fixtures/`, bo w atrapie NIE MA
 * skryptu Bricksa — a to on jest podejrzany: `bricksToggleModeFn` wiąże WŁASNĄ
 * obsługę kliknięcia do tego samego `.brxe-toggle-mode` i przestawia
 * `documentElement.dataset.brxTheme` SYNCHRONICZNIE, poza wywołaniem
 * `startViewTransition`. Kolory całej strony wiszą na tym atrybucie
 * (`:root[data-brx-theme="dark"] { --kolor-… }`).
 *
 * Skrypt odpowiada na dwa pytania, oba liczbami:
 *
 *   1. KIEDY zmienia się `data-brx-theme` — przed zrobieniem starej migawki
 *      (czyli przed wejściem w `callback`) czy po niej?
 *   2. Czy powierzchnia w rogu przeciwległym do przycisku TRZYMA kolor,
 *      dopóki fala po niej nie przejdzie?
 *
 * Fala jest zamrażana i przestawiana z ręki, tak jak w `darkmode-ripple`.
 */
const { chromium } = require('playwright-core');
const { chromiumPath } = require('../../tests/lib/harness.js');

const ADRES = process.env.ADRES || 'http://127.0.0.1:8765/';
const KADR  = { width: 1280, height: 800 };
const POPRAWKA = process.env.POPRAWKA === '1';

/* PROTOTYP POPRAWKI, mierzony na żywej stronie ZANIM dotknie się PHP-a.
   Przejmuje kliknięcie w fazie PRZECHWYTYWANIA — ta biegnie przed celem, więc
   obsługa Bricksa podpięta do samego przycisku nie dochodzi do głosu — i robi
   jej robotę (data-brx-theme + brx_mode) WEWNĄTRZ wywołania
   startViewTransition, razem z przełączeniem wtyczki. */
const PROTOTYP = [
"document.addEventListener('click', function (e) {",
"  var btn = e.target.closest && e.target.closest('.brxe-toggle-mode');",
"  if (!btn || !document.startViewTransition) return;",
"  e.preventDefault(); e.stopPropagation();",
"  var html = document.documentElement;",
"  var nowy = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';",
"  var r = btn.getBoundingClientRect();",
"  var x = r.left + r.width / 2, y = r.top + r.height / 2;",
"  var R = Math.hypot(Math.max(x, innerWidth - x), Math.max(y, innerHeight - y));",
"  html.style.setProperty('--ripple-x', x + 'px');",
"  html.style.setProperty('--ripple-y', y + 'px');",
"  html.classList.add('is-theme-toggling');",
"  var t = document.startViewTransition(function () {",
"    window.__znak('callback — STARA MIGAWKA JUŻ ZROBIONA');",
"    html.setAttribute('data-theme', nowy);",
"    html.dataset.brxTheme = nowy;",
"    localStorage.setItem('brx_mode', nowy);",
"    if (nowy === 'dark') html.classList.add('dark'); else html.classList.remove('dark');",
"    window.evkTheme = nowy;",
"    document.dispatchEvent(new CustomEvent('evk:theme-change', { detail: { mode: nowy } }));",
"  });",
"  t.ready.then(function () {",
"    window.__znak('ready');",
"    html.classList.add('is-theme-settled');",
"    html.animate({ '--ripple-radius': ['0px', (R + 150) + 'px'] },",
"      { duration: 1200, easing: 'cubic-bezier(0.4, 0, 0.2, 1)',",
"        pseudoElement: '::view-transition-new(theme-ripple)', fill: 'forwards' });",
"  });",
"  t.finished.then(function () {",
"    html.classList.remove('is-theme-toggling');",
"    html.classList.remove('is-theme-settled');",
"  });",
"}, true);"
].join('\n');

const SZPIEG = `
window.__log = [];
window.__t0 = 0;
window.__fala = [];
function znak(co, extra) {
  window.__log.push({ co: co, t: Math.round(performance.now() - window.__t0), v: extra });
}
window.__znak = znak;

/* OBSERWATOR DOPIERO, GDY JEST CO OBSERWOWAĆ.
   Skrypt wstrzykiwany jest przy „document-start", więc „documentElement"
   jeszcze nie istnieje i „observe()" rzuca — a wyjątek zabierał ze sobą
   opakowanie „startViewTransition" poniżej.
   ODWROTNY APOSTROF JEST TU ZAKAZANY: cały ten blok siedzi w literale
   szablonowym JS-a. */
(function czekaj() {
  if (!document.documentElement) return setTimeout(czekaj, 0);
  new MutationObserver(function (muts) {
    muts.forEach(function (m) {
      znak('atrybut ' + m.attributeName, document.documentElement.getAttribute(m.attributeName));
    });
  }).observe(document.documentElement, {
    attributes: true, attributeFilter: ['data-brx-theme', 'data-theme', 'class']
  });
})();

(function () {
  var orig = document.startViewTransition;
  if (!orig) return;
  document.startViewTransition = function (cb) {
    znak('startViewTransition — wywołane');
    var t = orig.call(document, function () {
      znak('callback — STARA MIGAWKA JUŻ ZROBIONA');
      return cb();
    });
    t.ready.then(function () { znak('ready'); });
    return t;
  };
  /* ZAMRAŻANIE OSOBNO, WOŁANE Z ZEWNĄTRZ PO KLIKNIĘCIU.
     Robienie tego w „ready.then" szpiega nie działa: ten „.then" rejestruje się
     przed tym, w którym wtyczka dokłada animację maski, więc lista jest wtedy
     jeszcze pusta. */
  window.__zamroz = function () {
    window.__fala = document.getAnimations().filter(function (a) {
      return a.effect && a.effect.pseudoElement
          && a.effect.pseudoElement.indexOf('theme-ripple') !== -1;
    });
    window.__fala.forEach(function (a) { a.pause(); });
    return window.__fala.length;
  };
})();
`;

/** Jasność punktu na zrzucie, przy zadanym czasie fali. */
async function wPunkcie(page, ms, xy) {
  await page.evaluate((v) => window.__fala.forEach((a) => { a.currentTime = v; }), ms);
  await page.waitForTimeout(40);
  const buf = await page.screenshot();
  return page.evaluate(async ({ b64, punkt }) => {
    const img = new Image();
    img.src = 'data:image/png;base64,' + b64;
    await img.decode();
    const c = document.createElement('canvas');
    c.width = img.width; c.height = img.height;
    const ctx = c.getContext('2d');
    ctx.drawImage(img, 0, 0);
    const d = ctx.getImageData(0, 0, img.width, img.height).data;
    const i = (punkt[1] * img.width + punkt[0]) * 4;
    return Math.round((d[i] + d[i + 1] + d[i + 2]) / 3);
  }, { b64: buf.toString('base64'), punkt: xy });
}

(async () => {
  const b = await chromium.launch({ executablePath: chromiumPath(), args: ['--no-proxy-server'] });
  const ctx = await b.newContext({ viewport: KADR });
  const page = await ctx.newPage();
  page.on('pageerror', (e) => console.log('  [błąd JS]', e.message.slice(0, 120)));
  await page.addInitScript({ content: SZPIEG });
  if (POPRAWKA) console.log('>>> z PROTOTYPEM POPRAWKI <<<');
  await page.goto(ADRES, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(2500);

  // Czy Bricks w ogóle podpiął swoją obsługę do tego samego przycisku.
  const bricks = await page.evaluate(() => ({
    maFn:   typeof window.bricksToggleMode === 'function',
    brxNa:  document.documentElement.getAttribute('data-brx-theme'),
    evkNa:  document.documentElement.getAttribute('data-theme'),
    ilePrzyciskow: document.querySelectorAll('.brxe-toggle-mode').length
  }));
  console.log('Bricks na stronie:', JSON.stringify(bricks));

  // Widoczny przełącznik — na stronie bywa ich kilka (nagłówek + menu mobilne).
  const przycisk = await page.evaluate(() => {
    const lista = Array.from(document.querySelectorAll('.brxe-toggle-mode'));
    for (let i = 0; i < lista.length; i++) {
      const r = lista[i].getBoundingClientRect();
      if (r.width > 0 && r.height > 0 && r.top >= 0 && r.top < innerHeight) {
        lista[i].setAttribute('data-evk-cel', '1');
        return { i, x: Math.round(r.left + r.width / 2), y: Math.round(r.top + r.height / 2) };
      }
    }
    return null;
  });
  if (!przycisk) { console.log('BRAK widocznego przełącznika w kadrze'); await b.close(); return; }
  console.log('przełącznik w kadrze:', JSON.stringify(przycisk));

  // Róg przeciwległy — tam fala dochodzi na końcu.
  const rog = [
    przycisk.x < KADR.width / 2 ? KADR.width - 12 : 12,
    przycisk.y < KADR.height / 2 ? KADR.height - 12 : 12
  ];

  if (POPRAWKA) await page.evaluate(PROTOTYP);
  const przedPelny = (await page.screenshot()).toString('base64');
  const przed = await page.evaluate(async ({ b64, punkt }) => {
    const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
    const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
    const x = c.getContext('2d'); x.drawImage(img, 0, 0);
    const d = x.getImageData(0, 0, img.width, img.height).data;
    const i = (punkt[1] * img.width + punkt[0]) * 4;
    return Math.round((d[i] + d[i+1] + d[i+2]) / 3);
  }, { b64: przedPelny, punkt: rog });
  await page.evaluate(() => { window.__t0 = performance.now(); });
  await page.click('[data-evk-cel]');
  await page.waitForTimeout(200);
  const ile = await page.evaluate(() => window.__zamroz());
  console.log('zamrożonych animacji pseudoelementów:', ile);

  console.log('\n── KOLEJNOŚĆ ZDARZEŃ (ms od kliknięcia) ──');
  const log = await page.evaluate(() => window.__log);
  log.forEach((w) => console.log(String(w.t).padStart(5) + ' ms  ' + w.co + (w.v ? '  = ' + w.v : '')));

  const czas = await page.evaluate(() => (window.__fala.length
    ? window.__fala[0].effect.getComputedTiming().duration : 0));
  console.log('\nczas fali:', czas, 'ms');
  if (!czas) { console.log('fala nie wystartowała — dalszy pomiar bez sensu'); await b.close(); return; }

  console.log('\n── JASNOŚĆ W ROGU ' + JSON.stringify(rog) + ' (przed kliknięciem: ' + przed + ') ──');
  for (const u of [0, 0.15, 0.3, 0.5, 0.9]) {
    const j = await wPunkcie(page, Math.round(czas * u), rog);
    console.log(String(Math.round(u * 100)).padStart(4) + '%  ' + String(Math.round(czas * u)).padStart(5)
      + ' ms   jasność ' + j + (Math.abs(j - przed) <= 2 ? '   (trzyma kolor)' : '   ← ZMIENIONY'));
  }

  /* ── CAŁY KADR PRZY PROMIENIU ZERO ────────────────────────────────────────
     Jeden punkt to za mało: zgłoszenie mówi „nie wszystkie". Przy promieniu
     fali RÓWNYM ZERU ekran ma wyglądać dokładnie tak jak przed kliknięciem —
     fala nie odsłoniła jeszcze niczego. Każdy piksel, który się różni,
     przefarbował się POZA falą. */
  await page.evaluate(() => window.__fala.forEach((a) => { a.currentTime = 0; }));
  await page.waitForTimeout(60);
  const przyZerze = (await page.screenshot()).toString('base64');

  const mapa = await page.evaluate(async ({ a64, b64, kolX, kolY }) => {
    async function piks(b64) {
      const img = new Image(); img.src = 'data:image/png;base64,' + b64; await img.decode();
      const c = document.createElement('canvas'); c.width = img.width; c.height = img.height;
      const x = c.getContext('2d'); x.drawImage(img, 0, 0);
      return { d: x.getImageData(0, 0, img.width, img.height).data, w: img.width, h: img.height };
    }
    const A = await piks(a64), B = await piks(b64);
    const kom = [], krok = 4;
    let zmienione = 0, wszystkie = 0;
    for (let ky = 0; ky < kolY; ky++) {
      const wiersz = [];
      for (let kx = 0; kx < kolX; kx++) {
        const x0 = Math.floor(kx * A.w / kolX), x1 = Math.floor((kx + 1) * A.w / kolX);
        const y0 = Math.floor(ky * A.h / kolY), y1 = Math.floor((ky + 1) * A.h / kolY);
        let suma = 0, n = 0;
        for (let y = y0; y < y1; y += krok) for (let x = x0; x < x1; x += krok) {
          const i = (y * A.w + x) * 4;
          suma += Math.abs(A.d[i] - B.d[i]) + Math.abs(A.d[i+1] - B.d[i+1]) + Math.abs(A.d[i+2] - B.d[i+2]);
          n++;
        }
        const sr = Math.round(suma / (n * 3));
        wiersz.push(sr);
        wszystkie++; if (sr > 12) zmienione++;
      }
      kom.push(wiersz);
    }
    return { kom, zmienione, wszystkie };
  }, { a64: przedPelny, b64: przyZerze, kolX: 24, kolY: 14 });

  /* KTO MA WŁASNĄ NAZWĘ. Element z własnym `view-transition-name` jest wyjmowany
     z migawki `theme-ripple` i animuje się osobną grupą — domyślnie przez
     przenikanie. To jedyny znany mechanizm, przez który treść może nie czekać
     na falę mimo poprawki z 1.218.0. */
  const nazwane = await page.evaluate(() => {
    const out = [];
    document.querySelectorAll('*').forEach((el) => {
      const n = getComputedStyle(el).viewTransitionName;
      if (n && n !== 'none') {
        out.push(n + '  ←  ' + el.tagName.toLowerCase()
          + (el.id ? '#' + el.id : '')
          + (el.className && typeof el.className === 'string'
             ? '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.') : ''));
      }
    });
    return out;
  });
  console.log('\n── ELEMENTY Z WŁASNYM view-transition-name ──');
  nazwane.forEach((n) => console.log('  ' + n));
  if (!nazwane.length) console.log('  (żaden — cała strona jest jedną migawką)');

  console.log('\n── CAŁY KADR PRZY PROMIENIU FALI = 0 ──');
  console.log('Mapa średniej różnicy koloru wobec stanu SPRZED kliknięcia.');
  console.log('Kropka = bez zmian (fala jeszcze tam nie doszła i dobrze).');
  console.log('Cyfra/znak = PRZEFAROWANE POZA FALĄ.\n');
  const znak = (v) => (v <= 12 ? '.' : v < 30 ? '-' : v < 80 ? '+' : v < 150 ? '#' : '@');
  mapa.kom.forEach((w) => console.log('  ' + w.map(znak).join(' ')));
  console.log('\nkomórek przefarbowanych poza falą: ' + mapa.zmienione + ' / ' + mapa.wszystkie);

  await page.screenshot({ path: '/tmp/fala-zero.png' });
  await b.close();
})();
