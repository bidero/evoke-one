/**
 * Obrazy WebP/AVIF (pozycja 5 kolejki) na PRAWDZIWYM WordPressie — pierwszym
 * testowym (stara.test), z prawdziwymi plikami z GD i prawdziwymi edytorami
 * obrazów WordPressa (Imagick, GD). Sonda: tests/php/obrazy-webp.php.
 *
 * Progi wzięte z pomiaru (02.10.2026, Imagick 6.9.12 z AVIF, GD z WebP):
 *  - JPEG 1600×1067: sześć plików, każdy z WebP i AVIF tych samych wymiarów,
 *    wszystkie lżejsze od JPEG-a (np. 841 723 → WebP 213 378, AVIF 281 242);
 *  - jakość: WebP q30/q90 z GD 26 494 / 163 440 B, AVIF 5 946 / 174 093 B.
 *    ImageMagick 6 zapisywał WebP ze STAŁĄ jakością (69 258 B przy q30 i q90) —
 *    stąd WebP przez GD; próg „q30 < połowy q90” łapie powrót tamtego błędu;
 *  - PNG z alfą: krycie przy prawej krawędzi 0,69 w PNG, WebP i AVIF.
 *
 * Przerabianie biblioteki: porcje z karty w Chromium (prawdziwy panel,
 * prawdziwy admin-ajax), karta zamknięta po pierwszej porcji, resztę robi
 * PRAWDZIWY wp-cron.php przez serwer. Zegar „karta zamilkła minutę temu”
 * cofa sonda — test nie czeka minuty.
 *
 * AVIF wymaga Imagicka z AVIF (`apt-get install -y php8.3-imagick
 * libheif-plugin-aomenc`) — bez niego środowisko świeci na czerwono.
 */

const http = require('http');
const { chromium } = require('playwright-core');
const { phpOutput, chromiumPath } = require('./lib/harness');
const serwerWp = require('./lib/wp-serwer');

const J = (x) => JSON.stringify(x);
const pobierz = (u) => new Promise((ok) => http.get(u, (r) => { let d = ''; r.on('data', (c) => { d += c; }); r.on('end', () => ok({ s: r.statusCode, d })); })
  .on('error', (e) => ok({ s: 0, d: String(e) })));

module.exports = async function (t) {
  const sonda = (krok, arg) => {
    const s = phpOutput('obrazy-webp.php', krok + (arg !== undefined ? ' ' + JSON.stringify(typeof arg === 'string' ? arg : J(arg)) : ''), { dopuscBlad: true });
    try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + krok + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
  };
  /* Zmienna środowiska tylko dla jednego wywołania sondy (EVK_TEST_EDYTOR=GD). */
  const sondaGD = (krok, arg) => {
    process.env.EVK_TEST_EDYTOR = 'GD';
    try { return sonda(krok, arg); } finally { delete process.env.EVK_TEST_EDYTOR; }
  };

  t.section('środowisko: pierwszy testowy WordPress, edytory obrazów');
  const wp = sonda('wp');
  console.log('      ' + J(wp));
  t.check('testowy WordPress z modułem (tools/testowy-wp.sh)', !wp.brak && wp.modul === true, wp.brak || J(wp));
  if (wp.brak || !wp.modul) return;
  t.check('serwer zapisze AVIF i WebP (Imagick z AVIF: apt-get install -y php8.3-imagick libheif-plugin-aomenc)',
    wp.avif === true && wp.webp === true && wp.edytor_avif === 'Imagick', J(wp));
  t.check('WebP zapisuje GD (ImageMagick 6 gubi jakość WebP), AVIF — Imagick', wp.edytor_webp === 'GD' && wp.edytor_avif === 'Imagick',
    J({ webp: wp.edytor_webp, avif: wp.edytor_avif }));
  const gd = sondaGD('wp');
  t.check('serwer z samym GD (bez imageavif): WebP tak, AVIF nie', gd.webp === true && gd.avif === false && gd.edytor_avif === '', J(gd));

  sonda('sprzataj');
  let serwer = null;
  let browser = null;
  try {
    // ── Wgrywanie ───────────────────────────────────────────────────────────
    t.section('wgrywanie JPEG 1600×1067: wersje obok każdego pliku, oryginał zostaje');
    sonda('ustaw', {});
    const w1 = sonda('wgraj', { w: 1600, h: 1067, typ: 'jpg', nazwa: 'evk-jpg', alt: 'Opis obrazu' });
    const pliki = w1.pliki || {};
    const jpg = Object.keys(pliki).filter((k) => /\.jpg$/.test(k));
    console.log('      ' + jpg.map((k) => k + ' ' + pliki[k].bajty + ' → webp ' + (pliki[k + '.webp'] || {}).bajty + ', avif ' + (pliki[k + '.avif'] || {}).bajty).join('\n      '));
    t.check('sześć plików JPEG (główny i pięć rozmiarów) — tak jak bez modułu', jpg.length === 6, J(jpg));
    const wersja = (k, fmt) => pliki[k + '.' + fmt] || {};
    t.check('każdy JPEG ma obok .webp i .avif — typ z bajtów pliku, nie z nazwy',
      jpg.every((k) => wersja(k, 'webp').mime === 'image/webp' && wersja(k, 'avif').mime === 'image/avif'),
      J(jpg.map((k) => [k, wersja(k, 'webp').mime, wersja(k, 'avif').mime])));
    t.check('wersje mają wymiary swojego JPEG-a', jpg.every((k) => ['webp', 'avif'].every((f) => wersja(k, f).w === pliki[k].w && wersja(k, f).h === pliki[k].h)),
      J(jpg.map((k) => [k, pliki[k].w, wersja(k, 'webp').w, wersja(k, 'avif').w])));
    t.check('każda wersja lżejsza od JPEG-a', jpg.every((k) => wersja(k, 'webp').bajty < pliki[k].bajty && wersja(k, 'avif').bajty < pliki[k].bajty),
      J(jpg.map((k) => [pliki[k].bajty, wersja(k, 'webp').bajty, wersja(k, 'avif').bajty])));
    t.check('meta załącznika: format → jakość z panelu (domyślnie AVIF 60, WebP 80)', J(w1.meta) === J({ avif: 60, webp: 80 }), J(w1.meta));
    t.check('nic poza JPEG-ami i ich wersjami (oryginał nie podmieniony, bez dodatkowych plików)', Object.keys(pliki).length === 18, Object.keys(pliki).length + '');

    t.section('jakość z panelu działa w obu formatach');
    const q = {};
    for (const jak of [30, 90]) {
      sonda('ustaw', { jakosc_webp: jak, jakosc_avif: jak });
      const r = sonda('wgraj', { w: 1200, h: 800, typ: 'jpg', nazwa: 'evk-q' + jak });
      const k = Object.keys(r.pliki || {}).find((x) => /-1024x683\.jpg$/.test(x));
      q[jak] = { meta: r.meta, webp: ((r.pliki || {})[k + '.webp'] || {}).bajty, avif: ((r.pliki || {})[k + '.avif'] || {}).bajty };
    }
    console.log('      ' + J(q));
    t.check('meta zapisuje jakość, z którą powstały', J(q[30].meta) === J({ avif: 30, webp: 30 }) && J(q[90].meta) === J({ avif: 90, webp: 90 }), J(q));
    t.check('WebP q30 mniejszy niż połowa WebP q90 (ImageMagick 6 dawał dwa razy ten sam plik)', q[30].webp > 0 && q[30].webp < q[90].webp / 2, J(q));
    t.check('AVIF q30 mniejszy niż połowa AVIF q90', q[30].avif > 0 && q[30].avif < q[90].avif / 2, J(q));

    t.section('zmniejszanie zbyt dużych: próg WordPressa z panelu');
    sonda('ustaw', { max_bok: 2000 });
    const duzy = sonda('wgraj', { w: 3000, h: 2000, typ: 'jpg', nazwa: 'evk-duzy' });
    const dp = duzy.pliki || {};
    console.log('      ' + J({ plik: duzy.plik, w: duzy.w, h: duzy.h, oryginal: duzy.oryginal, czas_s: duzy.czas_s }));
    t.check('próg 2000 px: plik „-scaled” 2000×1333 podawany jako pełny', /evk-duzy-scaled\.jpg$/.test(duzy.plik) && duzy.w === 2000 && duzy.h === 1333, J(duzy));
    t.check('oryginał 3000×2000 zostaje na serwerze (original_image)', duzy.oryginal === 'evk-duzy.jpg' && (dp['evk-duzy.jpg'] || {}).w === 3000, J(dp['evk-duzy.jpg']));
    t.check('„-scaled” ma WebP i AVIF; oryginał bez nich (nie trafia na stronę)',
      !!dp['evk-duzy-scaled.jpg.webp'] && !!dp['evk-duzy-scaled.jpg.avif'] && !dp['evk-duzy.jpg.webp'] && !dp['evk-duzy.jpg.avif'], J(Object.keys(dp)));
    sonda('ustaw', { max_bok: 0 });
    const bez = sonda('wgraj', { w: 3000, h: 2000, typ: 'jpg', nazwa: 'evk-bez-progu' });
    t.check('próg 0: bez zmniejszania, pełny plik 3000×2000 z wersjami', bez.plik.endsWith('evk-bez-progu.jpg') && bez.w === 3000 && !bez.oryginal
      && !!(bez.pliki || {})['evk-bez-progu.jpg.avif'], J({ plik: bez.plik, w: bez.w, oryginal: bez.oryginal }));

    t.section('PNG z przezroczystością');
    sonda('ustaw', {});
    const png = sonda('wgraj', { w: 400, h: 300, typ: 'png', nazwa: 'evk-alfa', alfa: 1 });
    const pp = png.pliki || {};
    const pngi = Object.keys(pp).filter((k) => /\.png$/.test(k));
    console.log('      ' + pngi.map((k) => k + ' krycie ' + pp[k].alfa + ' / ' + (pp[k + '.webp'] || {}).alfa + ' / ' + (pp[k + '.avif'] || {}).alfa).join('\n      '));
    t.check('warunek testu: PNG ma przezroczystość przy krawędzi', pngi.length > 0 && pngi.every((k) => pp[k].alfa !== null && pp[k].alfa < 0.95), J(pngi.map((k) => pp[k].alfa)));
    t.check('WebP i AVIF zachowują przezroczystość (krycie ±0,05 od PNG)',
      pngi.every((k) => ['webp', 'avif'].every((f) => (pp[k + '.' + f] || {}).alfa !== undefined && Math.abs(pp[k + '.' + f].alfa - pp[k].alfa) <= 0.05)),
      J(pngi.map((k) => [pp[k].alfa, (pp[k + '.webp'] || {}).alfa, (pp[k + '.avif'] || {}).alfa])));

    t.section('serwer bez AVIF (sam GD): same WebP, panel to mówi');
    const g = sondaGD('wgraj', { w: 900, h: 600, typ: 'jpg', nazwa: 'evk-gd' });
    const gp = Object.keys(g.pliki || {});
    t.check('każdy JPEG z WebP, żadnego AVIF; meta bez AVIF', gp.filter((k) => /\.jpg$/.test(k)).every((k) => gp.includes(k + '.webp'))
      && !gp.some((k) => /\.avif$/.test(k)) && J(g.meta) === J({ webp: 80 }), J({ pliki: gp, meta: g.meta }));
    const pgd = (sondaGD('panel').html || '').replace(/\s+/g, ' ');
    const pim = (sonda('panel').html || '').replace(/\s+/g, ' ');
    t.check('panel z samym GD: „AVIF: nie” i wyjaśnienie, czego brakuje', /AVIF: <strong class="evo-danger-tx">nie<\/strong>/.test(pgd) && pgd.includes('Serwer nie zapisze AVIF'),
      (pgd.match(/WebP: .{0,160}/) || [''])[0]);
    t.check('panel z Imagickiem: „AVIF: tak (Imagick)”, WebP przez GD, bez ostrzeżenia', /AVIF: <strong>tak<\/strong> <span class="evo-muted">\(Imagick\)/.test(pim)
      && /WebP: <strong>tak<\/strong> <span class="evo-muted">\(GD\)/.test(pim) && !pim.includes('Serwer nie zapisze AVIF'), (pim.match(/WebP: .{0,200}/) || [''])[0]);

    // ── Podawanie ───────────────────────────────────────────────────────────
    t.section('treść wpisu: <picture> AVIF → WebP → oryginalny <img>');
    const id = w1.id;
    const tr = sonda('tresc', { id });
    sonda('ustaw', { enabled: 0 });
    const trBez = sonda('tresc', { id });
    sonda('ustaw', {});
    const html = tr.html || '';
    const imgBez = ((trBez.html || '').match(/<img\b[^>]*>/) || [''])[0];
    const pic = (html.match(/<picture class="evk-obraz">([\s\S]*?)<\/picture>/) || ['', ''])[1];
    const zrodla = [...pic.matchAll(/<source type="([^"]+)" srcset="([^"]+)"(?: sizes="([^"]+)")?>/g)].map((m) => ({ typ: m[1], srcset: m[2], sizes: m[3] }));
    const imgZ = (pic.match(/<img\b[^>]*>/) || [''])[0];
    console.log('      ' + html.replace(/http:\/\/stara\.test\/wp-content\/uploads\/\d+\/\d+\//g, '…/').slice(0, 900));
    t.check('jeden <picture>, w nim AVIF, potem WebP, potem <img>', (html.match(/<picture/g) || []).length === 1
      && J(zrodla.map((z) => z.typ)) === J(['image/avif', 'image/webp']) && /<\/source>|<img/.test(pic) && pic.trim().endsWith(imgZ), J(zrodla.map((z) => z.typ)));
    t.check('<img> w środku BAJT W BAJT taki, jak bez modułu (klasy, alt, srcset, sizes, loading, decoding, wymiary)',
      imgZ !== '' && imgZ === imgBez, imgZ + '\n      bez: ' + imgBez);
    const srcsetImg = (imgBez.match(/srcset="([^"]+)"/) || ['', ''])[1];
    t.check('źródła: srcset obrazu z doklejonym .avif/.webp, te same deskryptory; sizes jak w <img>',
      zrodla.length === 2 && zrodla.every((z) => z.srcset === srcsetImg.split(', ').map((c) => c.replace(/^(\S+)/, '$1.' + z.typ.slice(6))).join(', ')
        && z.sizes === (imgBez.match(/sizes="([^"]+)"/) || ['', ''])[1]), J(zrodla));
    t.check('bez modułu: zwykły <img>, żadnego .webp ani .avif w treści', !/<picture|\.webp|\.avif/.test(trBez.html || '') && imgBez !== '', (trBez.html || '').slice(0, 200));
    t.check('miniatura wpisu (post_thumbnail_html) też w <picture>', /^<picture class="evk-obraz"><source type="image\/avif"/.test(tr.miniatura || ''), (tr.miniatura || '').slice(0, 120));

    t.section('źródło tylko, gdy KAŻDY kandydat ma wersję');
    sonda('skasuj', { id, plik: 'evk-jpg-300x200.jpg.webp' });
    const czesc = sonda('tresc', { id }).html || '';
    t.check('brak WebP jednego rozmiaru: zostaje samo źródło AVIF', /<source type="image\/avif"/.test(czesc) && !/<source type="image\/webp"/.test(czesc),
      (czesc.match(/<source type="[^"]+"/g) || []).join(' '));
    sonda('ustaw', { avif: 0 });
    const bezAvif = sonda('tresc', { id }).html || '';
    t.check('AVIF wyłączony w panelu i brak jednego WebP: bez <picture>, sam <img>', !/<picture/.test(bezAvif) && /<img/.test(bezAvif), bezAvif.slice(0, 160));
    sonda('ustaw', { webp: 0 });
    const tylkoAvif = sonda('tresc', { id }).html || '';
    t.check('WebP wyłączony w panelu: samo źródło AVIF', J((tylkoAvif.match(/<source type="[^"]+"/g) || [])) === J(['<source type="image/avif"']),
      (tylkoAvif.match(/<source type="[^"]+"/g) || []).join(' '));
    sonda('ustaw', {});

    t.section('HTML, którego nie ruszamy');
    const u = sonda('wgraj', { w: 600, h: 400, typ: 'jpg', nazwa: 'evk-brzegi' });
    const ru = (u.rozmiary || {}).medium;
    const baza = 'http://stara.test/wp-content/uploads/' + u.plik.replace(/[^/]+$/, '');
    const brzegi = [
      /* srcset bez spacji po przecinku — parser według reguł HTML. */
      '<img src="' + baza + ru + '" srcset="' + baza + ru + ' 300w,' + baza + u.plik.replace(/^.*\//, '') + ' 600w" sizes="300px" alt="a">',
      '<img src="https://example.com/obcy.jpg" alt="obcy">',
      '<picture><source srcset="' + baza + ru + '"><img src="' + baza + ru + '" alt="juz"></picture>',
      '<noscript><img src="' + baza + ru + '" alt="ns"></noscript>',
      '<img src="' + baza + ru + '?ver=2" alt="zapytanie">',
    ];
    const rb = sonda('tresc', { html: brzegi.join('\n') }).html || '';
    sonda('ustaw', { enabled: 0 });
    const rbBez = sonda('tresc', { html: brzegi.join('\n') }).html || '';
    sonda('ustaw', {});
    console.log('      ' + rb.replace(/http:\/\/stara\.test\/wp-content\/uploads\/\d+\/\d+\//g, '…/').slice(0, 1200).replace(/\n/g, '\n      '));
    t.check('srcset bez spacji po przecinku: oba kandydaci z wersją, deskryptory na miejscu',
      /srcset="[^"]*-300x200\.jpg\.avif 300w, [^"]*evk-brzegi\.jpg\.avif 600w"/.test(rb), (rb.match(/<source type="image\/avif" srcset="[^"]*"/) || [''])[0]);
    /* Jedyna różnica względem renderu bez modułu: pierwszy obraz w <picture>.
       Obcy adres, istniejące <picture>, <noscript> i adres z ?ver= — bez zmian. */
    t.check('obraz spoza katalogu wgrywania, <img> w istniejącym <picture> i w <noscript>, adres z ?ver= — bez zmian',
      (rb.match(/<picture class="evk-obraz">/g) || []).length === 1
        && rb.replace(/<picture class="evk-obraz">(?:<source [^>]*>)+(<img[^>]*>)<\/picture>/, '$1') === rbBez, rbBez.slice(0, 300));

    // ── Usuwanie ────────────────────────────────────────────────────────────
    t.section('usunięcie załącznika zabiera jego WebP i AVIF');
    const us = sonda('usun', w1.id);
    t.check('przed: 17 plików (jeden WebP skasowany wyżej); po: żadnego, także .webp i .avif', (us.przed || []).length === 17 && J(us.po) === J([]), J(us));

    // ── Przerabianie biblioteki ────────────────────────────────────────────
    t.section('przerabianie biblioteki: porcje z karty, potem cron');
    sonda('zapamietaj');
    sonda('ustaw', { enabled: 0 });
    const bib = [];
    for (let i = 1; i <= 12; i++) bib.push(sonda('wgraj', { w: 600, h: 400, typ: i % 4 ? 'jpg' : 'png', nazwa: 'evk-bib' + i }));
    t.check('warunek testu: 12 obrazów wgranych przy wyłączonym module — bez wersji', bib.every((b) => b.id && (b.meta === '' || J(b.meta) === '[]')
      && !Object.keys(b.pliki || {}).some((k) => /\.(webp|avif)$/.test(k))), J(bib.map((b) => [b.id, b.meta])));
    sonda('ustaw', {});
    const st0 = sonda('przebieg', { co: 'stan' });
    console.log('      przed: ' + J(st0.liczby));

    serwer = await serwerWp.start(wp.wp || '');
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const ctx = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const p = await ctx.newPage();
    const bledyJs = [];
    p.on('pageerror', (e) => bledyJs.push(String(e)));
    await serwerWp.zaloguj(p, serwer.baza);
    const PANEL = serwer.baza + '/wp-admin/options-general.php?page=evoke-one&tab=wydajnosc&sub=obrazy';
    await p.goto(PANEL);
    t.check('ekran „Obrazy WebP/AVIF” w panelu: przycisk aktywny, liczby z biblioteki',
      await p.isEnabled('.evk-obrazy-start') && Number(await p.textContent('.evk-obrazy-wszystkie')) === st0.liczby.wszystkie,
      J({ wszystkie: await p.textContent('.evk-obrazy-wszystkie'), z_wersjami: await p.textContent('.evk-obrazy-z-wersjami') }));
    await p.click('.evk-obrazy-start');
    /* Pierwsza porcja z karty — i karta zamknięta, zanim przebieg się skończy. */
    await p.waitForFunction(() => /Przerabiam: [1-9]/.test((document.querySelector('.evk-obrazy-info') || {}).textContent || ''), null, { timeout: 60000 });
    const infoKarta = await p.textContent('.evk-obrazy-info');
    const pasekKarta = await p.getAttribute('#evk-obrazy-pasek', 'value');
    await p.close();
    console.log('      karta: ' + infoKarta + ' (pasek ' + pasekKarta + ')');
    /* Karta zamknięta zaraz po pierwszej porcji mogła już wysłać drugą — serwer
       ją dokończy. Pomiar dopiero, gdy porcja w locie zwolni blokadę. */
    let st1 = null;
    for (let i = 0; i < 60; i++) {
      await new Promise((ok) => setTimeout(ok, 500));
      st1 = sonda('przebieg', { co: 'stan' });
      if (!st1.blokada) break;
    }
    console.log('      po zamknięciu karty: ' + J(st1.przebieg) + ' cron za ' + st1.cron + ' s');
    t.check('karta przerobiła porcję (5 obrazów) i pokazała postęp', st1.przebieg.przejrzane >= 5 && Number(pasekKarta) > 0, J(st1.przebieg));
    t.check('po zamknięciu karty przebieg trwa, a cron czeka w odwodzie (krok za ≤ 90 s)',
      st1.przebieg.stan === 'trwa' && st1.przebieg.przejrzane < st1.przebieg.wszystkie && st1.cron !== null && st1.cron <= 90, J({ stan: st1.przebieg.stan, cron: st1.cron }));

    /* Cron przy aktywnej karcie nie przerabia — przesuwa się za nią. */
    const zaWczesnie = await (async () => {
      const przed = sonda('przebieg', { co: 'stan' }).przebieg.przejrzane;
      sonda('przebieg', { co: 'postarz', s: 0 });
      await pobierz(serwer.baza + '/wp-cron.php');
      const po = sonda('przebieg', { co: 'stan' });
      return { przed, po: po.przebieg.przejrzane, cron: po.cron, kroki_cron: po.przebieg.kroki_cron };
    })();
    t.check('cron, gdy karta odzywała się przed chwilą: nic nie robi, przesuwa się o ≈ minutę', zaWczesnie.przed === zaWczesnie.po && zaWczesnie.kroki_cron === 0
      && zaWczesnie.cron >= 30 && zaWczesnie.cron <= 70, J(zaWczesnie));

    let st = null;
    for (let i = 0; i < 10; i++) {
      sonda('przebieg', { co: 'postarz', s: 120 });
      const r = await pobierz(serwer.baza + '/wp-cron.php');
      st = sonda('przebieg', { co: 'stan' });
      console.log('      wp-cron.php ' + r.s + ': ' + J({ stan: st.przebieg.stan, przejrzane: st.przebieg.przejrzane, kroki_cron: st.przebieg.kroki_cron, cron: st.cron }));
      if (st.przebieg.stan !== 'trwa') break;
    }
    t.check('cron dokończył przebieg: wszystkie obrazy przejrzane, bez błędów', st.przebieg.stan === 'gotowe' && st.przebieg.przejrzane === st.przebieg.wszystkie
      && st.przebieg.bledy === 0 && st.przebieg.kroki_cron >= 1, J(st.przebieg));
    t.check('po końcu w cronie nie zostaje nic zaplanowanego', st.cron === null, J(st.cron));
    const metas = sonda('metas').metas || {};
    t.check('każdy z 12 obrazów biblioteki ma WebP i AVIF', bib.every((b) => J(metas[b.id]) === J({ avif: 60, webp: 80 })), J(bib.map((b) => metas[b.id])));
    const pb = sonda('pliki', bib[3].id).pliki || {};
    t.check('PNG z biblioteki: każdy plik z .webp i .avif', Object.keys(pb).filter((k) => /\.png$/.test(k)).every((k) => pb[k + '.webp'] && pb[k + '.avif']), J(Object.keys(pb)));
    t.check('liczba „z wersjami” = wszystkie', st.liczby.z_wersjami === st.liczby.wszystkie, J(st.liczby));

    const p2 = await ctx.newPage();
    p2.on('pageerror', (e) => bledyJs.push(String(e)));
    await p2.goto(PANEL);
    const info2 = await p2.textContent('.evk-obrazy-info');
    t.check('powrót do panelu: pasek 100 %, „Gotowe”, porcje crona w opisie', /^Gotowe: (\d+) z \1 \(100%\)/.test(info2) && /W tle \(cron\)/.test(info2)
      && await p2.getAttribute('#evk-obrazy-pasek', 'value') === '100', info2);

    t.section('ponowny przebieg i zatrzymanie');
    await p2.click('.evk-obrazy-start');
    /* Napis „Gotowe” wisi z poprzedniego przebiegu — czekamy na NOWY. */
    await p2.waitForFunction((stary) => {
      const tx = (document.querySelector('.evk-obrazy-info') || {}).textContent || '';
      return tx !== stary && /^Gotowe/.test(tx);
    }, info2, { timeout: 60000 });
    const info3 = await p2.textContent('.evk-obrazy-info');
    t.check('„tylko bez wersji” po pełnym przebiegu: nic do roboty, wszystkie pominięte', /przerobione 0, pominięte \(już miały wersje\) (\d+)/.test(info3), info3);
    await p2.check('input[name="evk-obrazy-tryb"][value="wszystkie"]');
    await p2.click('.evk-obrazy-start');
    await p2.click('.evk-obrazy-stop');
    await p2.waitForFunction(() => /Zatrzymane/.test((document.querySelector('.evk-obrazy-info') || {}).textContent || ''), null, { timeout: 60000 });
    const stStop = sonda('przebieg', { co: 'stan' });
    t.check('„Zatrzymaj”: przebieg zatrzymany po bieżącej porcji, w cronie nic', stStop.przebieg.stan === 'zatrzymany' && stStop.cron === null
      && stStop.przebieg.przejrzane < stStop.przebieg.wszystkie, J({ stan: stStop.przebieg.stan, przejrzane: stStop.przebieg.przejrzane, cron: stStop.cron }));

    t.section('zapis ustawień formularzem (options.php)');
    await p2.goto(PANEL);
    await p2.fill('#evo-f-evk_obrazy-jakosc_webp', '70');
    await p2.fill('#evo-f-evk_obrazy-jakosc_avif', '150');
    await p2.fill('#evo-f-evk_obrazy-max_bok', '100');
    await p2.uncheck('input[name="evk_obrazy[avif]"]');
    t.check('przeglądarka sama nie przepuszcza jakości 150 (pole 1–100)', await p2.evaluate(() => !document.getElementById('evo-f-evk_obrazy-jakosc_avif').checkValidity()), '');
    /* Serwer sprawdzamy jak spreparowany POST — bez walidacji przeglądarki. */
    await p2.evaluate(() => { document.querySelector('form[action="options.php"]').noValidate = true; });
    /* Panel zapisuje formularz options.php AJAX-em (admin.js) — bez przeładowania. */
    await p2.click('.evo-save-bar [type="submit"]');
    await p2.waitForFunction(() => {
      const m = document.querySelector('.evo-save-bar .evo-save-msg');
      return m && m.offsetParent !== null && /Zapisano/.test(m.textContent) && !m.classList.contains('is-err');
    }, null, { timeout: 30000 });
    const zap = sonda('przebieg', { co: 'stan' }).ustawienia || {};
    t.check('zapisane: jakość WebP 70, AVIF wyłączony; przełącznik modułu nietknięty', zap.jakosc_webp === 70 && zap.avif === 0 && zap.webp === 1 && zap.enabled === 1, J(zap));
    t.check('wartości spoza zakresu: jakość 150 → domyślna 60, bok 100 px → 320 (mniej to literówka)', zap.jakosc_avif === 60 && zap.max_bok === 320, J(zap));
    await p2.reload();
    t.check('po przeładowaniu ekran pokazuje zapisane wartości', await p2.inputValue('#evo-f-evk_obrazy-jakosc_webp') === '70'
      && await p2.inputValue('#evo-f-evk_obrazy-max_bok') === '320' && !(await p2.isChecked('input[name="evk_obrazy[avif]"]'))
      && await p2.isChecked('input[data-option="evk_obrazy"][data-field="enabled"]'), p2.url());
    await p2.close();
    t.check('panel bez błędów JS', bledyJs.length === 0, J(bledyJs));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    const s = sonda('sprzataj');
    console.log('      sprzątanie: ' + J(s));
  }
};
