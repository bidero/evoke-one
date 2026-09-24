/* Dostępność panelu: kontrolki formularzy bez dostępnej nazwy, przyciski/odnośniki
   bez nazwy, obrazki bez alt, zduplikowane id — na każdym ekranie (1440 px). */
const fs = require('fs');
const path = require('path');
const ROOT = require('path').resolve(__dirname, '../..');
const { chromium } = require(ROOT + '/node_modules/playwright-core');
const { chromiumPath } = require(ROOT + '/tests/lib/harness.js');
const serwerWp = require(ROOT + '/tests/lib/wp-serwer.js');
const WP = (process.env.EVK_WP_PATH || process.env.HOME + '/.cache/evk-testowy-wp');
const lista = JSON.parse(fs.readFileSync(path.join(__dirname, 'wyniki', 'panel-crawl.json'), 'utf8'));
const adresy = [...new Map(lista.map((w) => [w.nazwa, w])).keys()];
function adres(n) {
  const e1 = 'options-general.php?page=evoke-one';
  if (n === 'pulpit') return e1;
  if (n.startsWith('nl-')) return 'admin.php?page=evoke-newsletter&subtab=' + n.slice(3);
  if (n.startsWith('tl-')) return 'options-general.php?page=evoke-tlumaczenia&tab=' + n.slice(3);
  if (n === 'inbox') return 'admin.php?page=evk-form-inbox';
  if (n.startsWith('snippety-')) { const w = n.slice(9); return e1 + '&tab=narzedzia&sub=snippets&evk_widok=' + (w === 'nowy' ? 'edytor&evk_wpis=nowy' : w); }
  if (['newsletter', 'forminbox', 'backup'].includes(n)) return e1 + '&tab=' + n;
  const i = n.indexOf('-'); const tab = n.slice(0, i), sub = n.slice(i + 1);
  return e1 + '&tab=' + tab + (sub === 'przeglad' ? '' : '&sub=' + sub);
}
(async () => {
  const srv = await serwerWp.start(WP);
  const b = await chromium.launch({ executablePath: chromiumPath(), args: ['--no-proxy-server'] });
  const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
  await ctx.route((u) => !/^http:\/\/127\.0\.0\.1/.test(u.href), (r) => r.abort('blockedbyclient'));
  const p = await ctx.newPage();
  await serwerWp.zaloguj(p, srv.baza);
  const suma = {};
  for (const n of adresy) {
    await p.goto(srv.baza + '/wp-admin/' + adres(n), { waitUntil: 'load' });
    const w = await p.evaluate(() => {
      const root = document.querySelector('#wpbody-content');
      const nazwa = (el) => {
        if (el.getAttribute('aria-label')) return el.getAttribute('aria-label').trim();
        const lb = el.getAttribute('aria-labelledby');
        if (lb) return lb.split(/\s+/).map((id) => (document.getElementById(id) || {}).textContent || '').join(' ').trim();
        if (el.labels && el.labels.length) return [...el.labels].map((l) => l.textContent).join(' ').trim();
        if (el.title) return el.title.trim();
        if (el.tagName === 'BUTTON' || el.tagName === 'A') return (el.textContent || '').trim() || (el.querySelector('img[alt]') || {}).alt || '';
        if (el.placeholder) return 'placeholder:' + el.placeholder;
        return '';
      };
      const widoczny = (el) => { const r = el.getBoundingClientRect(); const cs = getComputedStyle(el); return (r.width > 0 || r.height > 0 || el.type === 'checkbox' || el.type === 'radio') && cs.visibility !== 'hidden' && !el.closest('[hidden]') && el.type !== 'hidden'; };
      const opis = (el) => el.tagName.toLowerCase() + (el.type ? '[' + el.type + ']' : '') + (el.name ? ' name=' + el.name : '') + (el.id ? ' #' + el.id : '') + (el.className && typeof el.className === 'string' ? ' .' + el.className.trim().split(/\s+/)[0] : '') + (el.dataset.option ? ' data-option=' + el.dataset.option : '');
      const bezNazwy = [], tylkoPlaceholder = [];
      root.querySelectorAll('input, select, textarea').forEach((el) => {
        if (!widoczny(el) || el.type === 'submit' || el.type === 'button') return;
        const n = nazwa(el);
        if (!n) bezNazwy.push(opis(el)); else if (n.startsWith('placeholder:')) tylkoPlaceholder.push(opis(el));
      });
      const przyciski = [];
      root.querySelectorAll('button, a[href], [role=button]').forEach((el) => { if (widoczny(el) && !nazwa(el)) przyciski.push(opis(el) + ' ' + (el.innerHTML || '').slice(0, 60).replace(/\s+/g, ' ')); });
      const img = [...root.querySelectorAll('img')].filter((i) => !i.hasAttribute('alt')).map((i) => i.src.split('/').pop());
      const ids = {}; document.querySelectorAll('[id]').forEach((el) => { ids[el.id] = (ids[el.id] || 0) + 1; });
      const dup = Object.entries(ids).filter(([k, v]) => v > 1).map(([k, v]) => k + '×' + v);
      const h1 = document.querySelectorAll('#wpbody-content h1').length;
      return { bezNazwy, tylkoPlaceholder, przyciski, img, dup, h1 };
    });
    const ile = w.bezNazwy.length + w.przyciski.length + w.img.length + w.dup.length;
    console.log((ile || w.tylkoPlaceholder.length ? '!! ' : 'ok ') + n.padEnd(26) + ' bez nazwy: ' + w.bezNazwy.length + ', tylko placeholder: ' + w.tylkoPlaceholder.length + ', przyciski bez nazwy: ' + w.przyciski.length + ', img bez alt: ' + w.img.length + ', zdublowane id: ' + w.dup.length + ', h1: ' + w.h1);
    if (w.bezNazwy.length) console.log('     kontrolki: ' + [...new Set(w.bezNazwy)].slice(0, 6).join(' | '));
    if (w.przyciski.length) console.log('     przyciski: ' + [...new Set(w.przyciski)].slice(0, 4).join(' | '));
    if (w.dup.length) console.log('     id: ' + w.dup.slice(0, 6).join(', '));
    suma[n] = w;
  }
  fs.writeFileSync(path.join(__dirname, 'wyniki', 'panel-a11y.json'), JSON.stringify(suma, null, 1));
  await b.close(); await srv.zatrzymaj();
})().catch((e) => { console.error(e); process.exit(1); });
