/**
 * Pomiar na lustrze żywej strony.
 *
 * Dla każdego elementu z animacją POD przypiętą sekcją wypisuje dwie liczby:
 * przy jakim przewinięciu naprawdę wjechał w kadr i gdzie ScrollTrigger uważa,
 * że ma zagrać. Różnica to jest dokładnie to, co widać na ekranie jako
 * „animacja odegrała się, zanim element się pokazał".
 *
 * Kółko jest PRAWDZIWE (`page.mouse.wheel`), a nie `window.scrollTo`: przy
 * włączonym płynnym przewijaniu to biblioteka prowadzi pozycję i tylko
 * prawdziwe zdarzenia wpuszczają ją do gry. Zasoby dojeżdżają z opóźnieniem,
 * bo z dysku wszystko jest na miejscu natychmiast i strona nigdy nie rośnie
 * pod palcami — a na żywo rośnie.
 */
const { chromium } = require('playwright-core');
const { chromiumPath } = require('../../tests/lib/harness.js');

const ADRES = process.env.ADRES || 'http://127.0.0.1:8765/';
const OPOZ  = Number(process.env.OPOZ || 900);

const SZPIEG = `
window.__ref = 0;
(function c(){ if(!window.ScrollTrigger) return setTimeout(c,20);
  var o = ScrollTrigger.refresh.bind(ScrollTrigger);
  ScrollTrigger.refresh = function(){ window.__ref++; return o.apply(null,arguments); };
})();
window.__sledz = function () {
  var sp = document.querySelector('.pin-spacer');
  if (!sp) return 0;
  var lista = [];
  for (var n = sp.nextElementSibling; n; n = n.nextElementSibling)
    lista = lista.concat(Array.prototype.slice.call(n.querySelectorAll('[data-evk-anim]')));
  window.__cele = lista.slice(0, 12).map(function (e) {
    return { el: e, id: (e.id || e.className || '?').toString().slice(0, 22), wKadrze: null };
  });
  (function patrz() {
    var y = Math.round(window.scrollY);
    window.__cele.forEach(function (c) {
      var r = c.el.getBoundingClientRect();
      if (c.wKadrze === null && r.top < window.innerHeight && r.bottom > 0) c.wKadrze = y;
    });
    requestAnimationFrame(patrz);
  })();
  return window.__cele.length;
};`;

(async () => {
  const b = await chromium.launch({ executablePath: chromiumPath(), args: ['--no-proxy-server'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await ctx.newPage();
  await ctx.route('**/uploads/**', async (r) => {
    await new Promise((x) => setTimeout(x, OPOZ)); await r.continue();
  });
  await page.addInitScript({ content: SZPIEG });
  await page.goto(ADRES, { waitUntil: 'domcontentloaded', timeout: 60000 });
  await page.waitForTimeout(1500);

  const ilu = await page.evaluate('window.__sledz()');
  const pin = await page.evaluate(`(function(){var p=ScrollTrigger.getAll().filter(function(x){return x.pin;})[0];
    return p ? {s:Math.round(p.start), e:Math.round(p.end)} : null;})()`);
  if (!pin) { console.log('Na stronie nie ma przypiętego elementu — nie ma czego mierzyć.'); await b.close(); return; }
  console.log(`opóźnienie zasobów ${OPOZ} ms, śledzonych celów ${ilu}, przypięcie ${pin.s}→${pin.e}`);

  await page.mouse.move(700, 450);
  for (let i = 0; i < 80; i++) { await page.mouse.wheel(0, 120); await page.waitForTimeout(55); }
  await page.waitForTimeout(2500);

  const w = await page.evaluate(`({ ref: window.__ref,
    cele: window.__cele.map(function (c) {
      var st = ScrollTrigger.getAll().filter(function (x) { return x.trigger === c.el; })[0];
      return { id: c.id, wKadrze: c.wKadrze, start: st ? Math.round(st.start) : null };
    })})`);

  console.log(`\nodświeżeń podczas przejazdu: ${w.ref}`);
  console.log('\n  element                w kadrze   start ST   RÓŻNICA');
  w.cele.forEach((c) => {
    const d = (c.start !== null && c.wKadrze !== null) ? c.start - c.wKadrze : null;
    const flaga = d !== null && d < -200 ? '  ← gra ZANIM się pokaże' : '';
    console.log(`  ${String(c.id).padEnd(22)} ${String(c.wKadrze).padStart(8)} ${String(c.start).padStart(10)} ${String(d).padStart(9)}${flaga}`);
  });
  await b.close();
})();
