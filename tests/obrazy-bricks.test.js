/**
 * Obrazy WebP/AVIF w renderze PRAWDZIWEGO Bricksa (piąty testowy WordPress,
 * bricks.test, jak bricks-render) i w Chromium. Sonda: tests/php/obrazy-bricks.php.
 *
 * Strona: Image (z podpisem z biblioteki — <figure>, z własnym podpisem,
 * z odnośnikiem — <a>), Image Gallery (trzy miniatury), Image E bez podpisu
 * i odnośnika (`<img>` jest KORZENIEM elementu: id, brxe-image, szerokość 50 %
 * w flexie) i obraz D za odstępem 4000 px.
 *
 * Co zmierzone przed progami (02.10.2026, Bricks 2.4.2, Chromium):
 *  - Bricks ładuje obrazy leniwie po swojemu: zastępnik SVG w `src`, adresy
 *    w `data-src`/`data-srcset`/`data-sizes`, skrypt obserwuje sam `<img>`
 *    i nie zna `<source>`. Moduł oddaje takim obrazom prawdziwe adresy
 *    i `loading="lazy"` przeglądarki (obejmuje cały <picture>);
 *  - z modułem przeglądarka pobrała WYŁĄCZNIE pliki .avif (osiem), żadnego
 *    JPEG-a; obraz D — dopiero po przewinięciu;
 *  - położenie i wymiary każdego obrazu co do piksela takie same jak bez
 *    modułu. Obraz E pokazał, czego to wymaga: bez stylu <picture> obraz ma
 *    133 px zamiast 550 (50 % z <picture>, nie z kontenera); z samym
 *    `display: contents` stoi 20 px dalej (dwa <source> we flexie, każdy
 *    z `gap`) — stąd także `source { display: none }`;
 *  - pierwszy obraz ma od WordPressa `fetchpriority="high"` — zostaje bez
 *    `loading="lazy"`.
 */

const http = require('http');
const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const J = (x) => JSON.stringify(x);
const pobierz = (u) => new Promise((ok) => http.get(u, (r) => { let d = ''; r.on('data', (c) => { d += c; }); r.on('end', () => ok({ s: r.statusCode, d })); })
  .on('error', (e) => ok({ s: 0, d: String(e) })));

/** Atrybuty znacznika jako obiekt (wartości surowe). */
function atrybuty(tag) {
  const out = {};
  for (const m of tag.matchAll(/\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'>]+)))?/g)) {
    out[m[1].toLowerCase()] = m[2] !== undefined ? m[2] : m[3] !== undefined ? m[3] : (m[4] || '');
  }
  return out;
}

module.exports = async function (t) {
  const sonda = (krok, arg) => {
    const s = phpOutput('obrazy-bricks.php', krok + (arg !== undefined ? ' ' + JSON.stringify(J(arg)) : ''), { dopuscBlad: true });
    try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + krok + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
  };

  t.section('środowisko: piąty testowy WordPress z motywem Bricks');
  const wp = sonda('wp');
  t.check('piąty WordPress z motywem Bricks (tools/testowy-wp.sh; zip w ../bricks-motyw albo EVK_BRICKS_ZIP)', !wp.brak && wp.motyw === 'Bricks', wp.brak || J(wp));
  if (wp.brak || wp.motyw !== 'Bricks') return;
  t.check('serwer zapisze AVIF (Imagick z AVIF: apt-get install -y php8.3-imagick libheif-plugin-aomenc)', wp.avif === true, J(wp));

  let serwer = null;
  let browser = null;
  try {
    t.check('moduł obrazów i Tłumaczenia (EN) włączone', sonda('modul', {}).gotowe === true);
    const u = sonda('ustaw');
    t.check('cztery obrazy wgrane z WebP i AVIF, strona z elementami Bricksa', u.gotowe === true && J(u.meta) === J({ avif: 60, webp: 80 }), J(u));
    if (!u.gotowe) return;
    serwer = await serwerWp.start(wp.wp);
    const adres = (pre) => serwer.baza + pre + '/?page_id=' + u.strona;
    const zUploads = (s) => s.replace(/http:\/\/127\.0\.0\.1:\d+\/wp-content\/uploads\/\d+\/\d+\//g, '…/');

    t.section('HTML renderu: każdy obraz w <picture>, atrybuty Bricksa na miejscu');
    const z = await pobierz(adres(''));
    const en = await pobierz(adres('/en'));
    sonda('modul', { enabled: 0 });
    const bez = await pobierz(adres(''));
    sonda('modul', {});
    const obrazy = (html) => (html.match(/<img\b[^>]*>/g) || []).filter((x) => /uploads/.test(x));
    const picts = z.d.match(/<picture class="evk-obraz">[\s\S]*?<\/picture>/g) || [];
    const imgZ = obrazy(z.d);
    const imgBez = obrazy(bez.d);
    console.log('      ' + picts.map((x) => zUploads(x).slice(0, 220)).join('\n      '));
    t.check('render 200; osiem obrazów (trzy Image, trzy w galerii, E, D) — wszystkie w <picture class="evk-obraz">',
      z.s === 200 && imgZ.length === 8 && picts.length === 8, J({ s: z.s, img: imgZ.length, picture: picts.length }));
    t.check('warunek testu: obraz E bez podpisu i odnośnika — <img> to korzeń elementu (id, brxe-image)',
      /^<img [^>]*id="brxe-obi005"/.test(imgBez[6] || '') && /class="[^"]*brxe-image/.test(imgBez[6] || '') && /id="brxe-obi005"/.test(imgZ[6] || ''),
      zUploads((imgBez[6] || '').slice(0, 200)));
    t.check('każdy <picture>: źródło AVIF, potem WebP', picts.every((x) => /^<picture class="evk-obraz"><source type="image\/avif" srcset="[^"]+\.avif[^"]*"[^>]*><source type="image\/webp" srcset="[^"]+\.webp[^"]*"[^>]*><img /.test(x)),
      J(picts.map((x) => (x.match(/<source type="[^"]+"/g) || []).join(' '))));
    t.check('warunek testu: bez modułu Bricks ładuje leniwie (zastępnik SVG, data-src) i nie ma <picture>',
      imgBez.length === 8 && imgBez.every((x) => /bricks-lazy-hidden/.test(x) && /data-src=/.test(x) && /src="data:image\/svg/.test(x)) && !/<picture class="evk-obraz"/.test(bez.d),
      J(imgBez.map((x) => zUploads(x).slice(0, 120))));
    /* Atrybuty <img> z modułem = atrybuty Bricksa z prawdziwymi adresami zamiast
       zastępnika, bez klasy leniwości, plus loading="lazy" (poza fetchpriority=high). */
    const roznice = imgZ.map((x, i) => {
      const a = atrybuty(x);
      const b = atrybuty(imgBez[i] || '');
      const ocz = {};
      for (const [k, v] of Object.entries(b)) {
        if (k === 'src' || k === 'data-type') continue;
        if (k.startsWith('data-') && ['data-src', 'data-srcset', 'data-sizes'].includes(k)) { ocz[k.slice(5)] = v; continue; }
        ocz[k] = k === 'class' ? v.split(/\s+/).filter((c) => c && c !== 'bricks-lazy-hidden').join(' ') : v;
      }
      if (b.fetchpriority !== 'high') ocz.loading = 'lazy';
      const klucze = [...new Set([...Object.keys(a), ...Object.keys(ocz)])].sort();
      return klucze.filter((k) => a[k] !== ocz[k]).map((k) => k + ': ' + J(a[k]) + ' ≠ ' + J(ocz[k]));
    });
    t.check('<img>: atrybuty Bricksa (klasy, alt, wymiary, srcset, sizes, decoding, fetchpriority) zachowane, adresy prawdziwe, loading="lazy"',
      roznice.every((r) => r.length === 0), J(roznice));
    t.check('pierwszy obraz (fetchpriority="high" od WordPressa) bez loading="lazy"', /fetchpriority="high"/.test(imgZ[0]) && !/loading=/.test(imgZ[0]), zUploads(imgZ[0]));
    t.check('styl <picture> bez własnego pudełka (i bez pudełek źródeł) w <head>',
      z.d.includes('<style id="evk-obrazy">picture.evk-obraz{display:contents}picture.evk-obraz>source{display:none}</style>'), '');
    const altEn = obrazy(en.d).map((x) => atrybuty(x).alt);
    t.check('/en/: tłumaczony alt obrazu A (Image i galeria), reszta po polsku; <picture> na miejscu',
      en.s === 200 && J(altEn) === J(['Description A', 'Opis B', 'Opis C', 'Description A', 'Opis B', 'Opis C', 'Opis C', 'Opis D (daleko)'])
        && (en.d.match(/<picture class="evk-obraz">/g) || []).length === 8, J(altEn));

    t.section('Chromium: AVIF, leniwe ładowanie, układ bez zmian');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const zmierz = async (url) => {
      const p = await browser.newPage({ viewport: { width: 1280, height: 800 } });
      const bledy = [];
      p.on('pageerror', (e) => bledy.push(String(e)));
      const pobrane = [];
      p.on('response', (r) => { if (/\/uploads\//.test(r.url())) pobrane.push(r.url().replace(/^.*\/uploads\/\d+\/\d+\//, '')); });
      await p.goto(url, { waitUntil: 'load' });
      await p.waitForTimeout(1500);
      const stan = () => p.evaluate(() => Array.from(document.querySelectorAll('img')).filter((i) => /uploads/.test(i.currentSrc || i.getAttribute('data-src') || i.getAttribute('src') || '')).map((i) => {
        const r = i.getBoundingClientRect();
        return { alt: i.alt, plik: (i.currentSrc || '').replace(/^.*\//, ''), x: Math.round(r.left), y: Math.round(r.top + scrollY), w: Math.round(r.width), h: Math.round(r.height),
          zaladowany: i.complete && i.naturalWidth > 0 };
      }));
      const przed = await stan();
      const pobranePrzed = pobrane.slice();
      const podpisy = () => p.evaluate(() => Array.from(document.querySelectorAll('figcaption')).map((f) => [f.textContent.trim(), f.offsetHeight > 0]));
      const podpis = await podpisy();
      await p.evaluate(() => document.getElementById('obraz-daleko').scrollIntoView());
      await p.waitForTimeout(1500);
      const po = await stan();
      const podpisPo = await podpisy();
      await p.close();
      return { przed, po, pobranePrzed, pobrane, bledy, podpis, podpisPo };
    };
    const m = await zmierz(adres(''));
    sonda('modul', { enabled: 0 });
    const mb = await zmierz(adres(''));
    sonda('modul', {});
    const D = (lista) => lista.find((x) => /daleko/.test(x.alt)) || {};
    console.log('      z modułem: ' + J(m.przed.map((x) => [x.alt, x.plik, x.x, x.y, x.w, x.h])));
    console.log('      bez:       ' + J(mb.przed.map((x) => [x.alt, x.plik, x.x, x.y, x.w, x.h])));
    console.log('      pobrane przed przewinięciem: ' + J(m.pobranePrzed));
    const blisko = m.przed.filter((x) => !/daleko/.test(x.alt));
    t.check('siedem obrazów na ekranie: przeglądarka wybrała AVIF', blisko.length === 7 && blisko.every((x) => /\.avif$/.test(x.plik) && x.zaladowany), J(blisko.map((x) => x.plik)));
    t.check('pobrane tylko pliki .avif — żadnego JPEG-a obok (bez podwójnego pobierania)', m.pobrane.length === 8 && m.pobrane.every((f) => /\.avif$/.test(f)), J(m.pobrane));
    t.check('obraz D za odstępem 4000 px: nie pobrany przed przewinięciem', !D(m.przed).zaladowany && !m.pobranePrzed.some((f) => /bricks-d/.test(f)), J(D(m.przed)));
    t.check('obraz D po przewinięciu: AVIF', D(m.po).zaladowany === true && /bricks-d-1024x683\.jpg\.avif$/.test(D(m.po).plik), J(D(m.po)));
    const uklad = (l) => J(l.map((x) => [x.alt, x.x, x.y, x.w, x.h]));
    t.check('układ co do piksela jak bez modułu (położenie i wymiary każdego obrazu, także E w flexie i D)', uklad(m.po) === uklad(mb.po), uklad(m.po) + '\n      bez: ' + uklad(mb.po));
    /* Bricks pokazuje podpis z biblioteki także bez ustawienia (motyw: „attachment”).
       Zmierzone: bez modułu podpis obrazu D jest schowany do czasu załadowania
       (`.bricks-lazy-hidden+figcaption{display:none}`), z modułem widać go od
       razu — miejsce na obraz i tak trzymają width/height, układ się nie zmienia. */
    t.check('podpisy po przewinięciu jak bez modułu (własny „Podpis B” i podpisy z biblioteki)',
      J(m.podpisPo) === J(mb.podpisPo) && m.podpisPo.some((f) => f[0] === 'Podpis B' && f[1]) && m.podpisPo.every((f) => f[1]), J([m.podpisPo, mb.podpisPo]));
    t.check('przed przewinięciem z modułem widać wszystkie podpisy (Bricks chował podpis leniwego obrazu)', m.podpis.length === 4 && m.podpis.every((f) => f[1]), J(m.podpis));
    t.check('bez błędów JS (z modułem i bez)', m.bledy.length === 0 && mb.bledy.length === 0, J([m.bledy, mb.bledy]));

    sonda('modul', { avif: 0 });
    const mw = await zmierz(adres(''));
    sonda('modul', {});
    t.check('AVIF wyłączony: przeglądarka bierze WebP', mw.przed.filter((x) => !/daleko/.test(x.alt)).every((x) => /\.webp$/.test(x.plik) && x.zaladowany),
      J(mw.przed.map((x) => x.plik)));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
