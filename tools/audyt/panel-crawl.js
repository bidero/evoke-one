/* Przegląd wizualny panelu na testowym WordPressie: każdy ekran × szerokości.
   Wykrywa: poziome przewijanie, elementy wystające poza okno, ostrzeżenia PHP
   w HTML-u, błędy JS, nieudane żądania. Zrzuty pełnej strony dla wybranych szerokości. */
const fs = require('fs');
const path = require('path');
const ROOT = require('path').resolve(__dirname, '../..');
const { chromium } = require(ROOT + '/node_modules/playwright-core');
const { chromiumPath } = require(ROOT + '/tests/lib/harness.js');
const serwerWp = require(ROOT + '/tests/lib/wp-serwer.js');
const OUT = path.join(__dirname, 'wyniki', 'zrzuty');
const WP = process.env.EVK_WP_PATH || ((process.env.EVK_WP_PATH || process.env.HOME + '/.cache/evk-testowy-wp'));
const SZEROKOSCI = (process.env.SZER || '1440,1024,782,600,390').split(',').map(Number);
const ZRZUTY = (process.env.ZRZUTY || '1440,390').split(',').map(Number);
const TYLKO = process.env.TYLKO || '';

function adresy() {
  const e1 = 'options-general.php?page=evoke-one';
  const ekrany = {
    wydajnosc: ['parallax','darkmode','cursor','lenis','animator','bgshift','fonts','sierotki','themecolor','a11y','elementy','tlumaczenia'],
    strona: ['meta','sitemap','schema','og'],
    bezpieczenstwo: ['login','rest','hardening','cleanup'],
    narzedzia: ['snippets','smtp','redirect','logs404','rewizje','maintenance','io'],
    admin_panel: ['interface','dashboard','avatar','content','whitelabel','roles'],
  };
  const l = [['pulpit', e1]];
  for (const [tab, subs] of Object.entries(ekrany)) {
    l.push([tab + '-przeglad', e1 + '&tab=' + tab]);
    for (const s of subs) l.push([tab + '-' + s, e1 + '&tab=' + tab + '&sub=' + s]);
  }
  for (const t of ['newsletter', 'forminbox', 'backup']) l.push([t, e1 + '&tab=' + t]);
  for (const w of ['logi', 'advanced']) l.push(['snippety-' + w, e1 + '&tab=narzedzia&sub=snippets&evk_widok=' + w]);
  l.push(['snippety-nowy', e1 + '&tab=narzedzia&sub=snippets&evk_widok=edytor&evk_wpis=nowy']);
  for (const s of ['lists','templates','campaigns','reports','settings']) l.push(['nl-' + s, 'admin.php?page=evoke-newsletter&subtab=' + s]);
  for (const s of ['translations','images','slugs','dd','languages','sitemap']) l.push(['tl-' + s, 'options-general.php?page=evoke-tlumaczenia&tab=' + s]);
  l.push(['inbox', 'admin.php?page=evk-form-inbox']);
  return TYLKO ? l.filter(([n]) => n.includes(TYLKO)) : l;
}

(async () => {
  fs.mkdirSync(OUT, { recursive: true });
  const srv = await serwerWp.start(WP);
  const b = await chromium.launch({ executablePath: chromiumPath(), args: ['--no-proxy-server'] });
  const wyniki = [];
  try {
    const ctx = await b.newContext({ viewport: { width: 1440, height: 900 } });
    // CDN SortableJS podajemy z node_modules (w kontenerze proxy psuje certyfikat),
    // resztę zewnętrznych żądań (gravatar, wordpress.org) ucinamy po cichu.
    await ctx.route(/cdn\.jsdelivr\.net\/npm\/sortablejs/, (r) => r.fulfill({ contentType: 'application/javascript', body: fs.readFileSync(ROOT + '/node_modules/sortablejs/Sortable.min.js') }));
    await ctx.route((u) => !/^http:\/\/127\.0\.0\.1/.test(u.href) && !/cdn\.jsdelivr\.net\/npm\/sortablejs/.test(u.href), (r) => r.abort('blockedbyclient'));
    const p = await ctx.newPage();
    await serwerWp.zaloguj(p, srv.baza);
    for (const [nazwa, adres] of adresy()) {
      for (const szer of SZEROKOSCI) {
        await p.setViewportSize({ width: szer, height: 900 });
        const konsola = [], bledy = [], nieudane = [];
        const nk = (m) => { if ((m.type() === 'error' || m.type() === 'warning') && !/ERR_BLOCKED_BY_CLIENT|Failed to load resource: net::ERR_FAILED/.test(m.text())) konsola.push(m.type() + ': ' + m.text().slice(0, 200)); };
        const nb = (e) => bledy.push(String(e.message).slice(0, 200));
        const nr = (r) => { if (r.status() >= 400) nieudane.push(r.status() + ' ' + r.url().replace(srv.baza, '')); };
        const nf = (r) => { const t = (r.failure() || {}).errorText || ''; if (/ERR_ABORTED|BLOCKED_BY_CLIENT|ERR_FAILED/.test(t)) return; nieudane.push('FAIL ' + r.url().replace(srv.baza, '') + ' ' + t); };
        p.on('console', nk); p.on('pageerror', nb); p.on('response', nr); p.on('requestfailed', nf);
        let status = 0;
        try {
          const r = await p.goto(srv.baza + '/wp-admin/' + adres, { waitUntil: 'load', timeout: 45000 });
          status = r ? r.status() : 0;
          await p.waitForTimeout(400);
        } catch (e) { bledy.push('NAWIGACJA: ' + e.message.split('\n')[0]); }
        const html = await p.content().catch(() => '');
        const php = [...html.matchAll(/(<b>)?(Warning|Notice|Deprecated|Fatal error|Parse error)(<\/b>)?:\s*(.{0,220})/g)].map((m) => m[2] + ': ' + m[4].replace(/<[^>]+>/g, '')).slice(0, 5);
        const uklad = await p.evaluate(() => {
          const vw = document.documentElement.clientWidth;
          const sw = document.documentElement.scrollWidth;
          const wystaja = [];
          const root = document.querySelector('#wpbody-content') || document.body;
          root.querySelectorAll('*').forEach((el) => {
            const r = el.getBoundingClientRect();
            if (!r.width || !r.height) return;
            const cs = getComputedStyle(el);
            if (cs.visibility === 'hidden' || cs.display === 'none' || cs.position === 'fixed') return;
            // pomiń elementy w kontenerach z przewijaniem
            let a = el.parentElement, wScrollu = false;
            while (a && a !== root) { const o = getComputedStyle(a).overflowX; if (o === 'auto' || o === 'scroll' || o === 'hidden' || o === 'clip') { wScrollu = true; break; } a = a.parentElement; }
            if (wScrollu) return;
            if (r.right > vw + 1 || r.left < -1) {
              const id = el.tagName.toLowerCase() + (el.id ? '#' + el.id : '') + (el.className && typeof el.className === 'string' ? '.' + el.className.trim().split(/\s+/).slice(0, 2).join('.') : '');
              wystaja.push(id + ' [' + Math.round(r.left) + '..' + Math.round(r.right) + ']');
            }
          });
          // tylko „najwyższe” wystające (bez ich dzieci) — uproszczenie: pierwsze 6
          return { vw, sw, wystaja: wystaja.slice(0, 6), ile: wystaja.length, tytul: document.title };
        }).catch((e) => ({ blad: e.message }));
        p.off('console', nk); p.off('pageerror', nb); p.off('response', nr); p.off('requestfailed', nf);
        const w = { nazwa, szer, status, php, konsola: konsola.slice(0, 6), bledy, nieudane: nieudane.slice(0, 6), ...uklad };
        wyniki.push(w);
        const problem = status !== 200 || php.length || bledy.length || konsola.length || nieudane.length || (uklad.sw > uklad.vw + 1) || uklad.ile;
        console.log((problem ? '!! ' : 'ok ') + nazwa.padEnd(28) + String(szer).padStart(5) + '  st ' + status
          + (uklad.sw > uklad.vw + 1 ? '  POZIOMY SCROLL ' + uklad.sw + '>' + uklad.vw : '')
          + (uklad.ile ? '  wystaje ' + uklad.ile + ': ' + uklad.wystaja.slice(0, 3).join(' | ') : '')
          + (php.length ? '  PHP: ' + php.join(' | ') : '') + (bledy.length ? '  JS: ' + bledy.join(' | ') : '')
          + (konsola.length ? '  konsola: ' + konsola.join(' | ') : '') + (nieudane.length ? '  żądania: ' + nieudane.join(' | ') : ''));
        if (ZRZUTY.includes(szer)) await p.screenshot({ path: path.join(OUT, nazwa + '-' + szer + '.png'), fullPage: true }).catch(() => {});
      }
    }
  } finally {
    fs.writeFileSync(path.join(__dirname, 'wyniki', 'panel-crawl.json'), JSON.stringify(wyniki, null, 1));
    await b.close();
    await srv.zatrzymaj();
  }
})().catch((e) => { console.error('BŁĄD', e); process.exit(1); });
