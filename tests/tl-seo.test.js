/**
 * SEO w wersjach językowych (1.251.0) na PRAWDZIWYM WordPressie: łańcuch
 * wartości na stronie (przez serwer HTTP), zakładka SEO w Chromium (przełącznik,
 * liczniki, zapis pól języków) i przeniesienie znaczników `{tl_…}`.
 *
 * Do 1.250.0 tytuł i opis SEO w innym języku dało się uzyskać tylko znacznikiem
 * tłumaczenia wpisanym w pole. Bez niego wersja EN dostawała polski tytuł
 * i polski opis — także w og:title i og:description.
 *
 * Łańcuch w wersji językowej (`evk_seo_w_jezyku()`):
 *   tytuł:  pole języka → polska wartość ze znacznikiem → polska wartość;
 *   opis i słowa kluczowe: pole języka → polska wartość ze znacznikiem → NIC
 *     (decyzja zgłaszającego: polski opis na angielskiej stronie myli);
 *   OG: polski tekst z „Mediów społecznościowych" Bricksa przepada (zostaje
 *     tylko ze znacznikiem), OG idzie za tytułem i opisem języka.
 *
 * Środowisko: tools/testowy-wp.sh. Sonda: tests/php/tl-seo.php. Bricksa tu
 * nie ma: `{tl_…}` z pól Bricksa renderuje bufor strony (60-image-replacement),
 * na żywo robi to wcześniej Bricks — wynik w HTML-u ten sam.
 */

const http = require('http');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('tl-seo.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
/* `phpOutput` dokleja argumenty do polecenia powłoki: JSON idzie w apostrofach. */
const ajax = (akcja, post) => sonda('ajax ' + akcja + " '" + JSON.stringify(post).replace(/'/g, "'\\''") + "'").odp;
const J = (v) => JSON.stringify(v);

function pobierz(url) {
  return new Promise((ok) => {
    const w = { body: '' };
    const req = http.get(url, (r) => {
      w.status = r.statusCode;
      r.on('data', (d) => { if (w.body.length < 400000) w.body += d.toString('utf8'); });
      r.on('end', () => ok(w));
    });
    req.on('error', (e) => { w.status = 'błąd ' + e.code; ok(w); });
    req.setTimeout(20000, () => { w.status = 'timeout'; req.destroy(); });
  });
}

const encje = (s) => s.replace(/&amp;/g, '&').replace(/&#039;/g, "'").replace(/&quot;/g, '"').replace(/&#8211;/g, '–')
  .replace(/&#8212;/g, '—').replace(/&#124;/g, '|');
/** <title>, meta name/property i ich liczba — z <head>. */
function glowa(html) {
  const g = (html.split('</head>')[0] || '');
  const w = { title: encje(((g.match(/<title>([^<]*)<\/title>/) || [])[1] || '').trim()) };
  for (const m of g.matchAll(/<meta\s+(?:property|name)="([^"]+)"\s+content="([^"]*)"\s*\/?>/g)) {
    (w[m[1]] = w[m[1]] || []).push(encje(m[2]));
  }
  return w;
}

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;

  let serwer = null, browser = null;
  const bledy = [];
  try {
    serwer = await serwerWp.start(wp.wp);
    const b = serwer.baza;
    const prep = sonda('przygotuj ' + b);
    t.check('sonda przygotowała strony J1–J6', !prep.brak && prep.gotowe === true, prep.brak || J(prep));
    if (prep.brak || !prep.gotowe) return;
    const plu = sonda('przeplucz ' + b);
    t.check('moduł Tłumaczeń i SEO w językach wczytane', plu.tl === true, J(plu));

    const strona = async (u) => { const w = await pobierz(b + u); return Object.assign(glowa(w.body), { status: w.status }); };
    const opis = (m, k) => J(Object.fromEntries([['status', m.status]].concat(k.map((x) => [x, m[x]]))));

    // ── Łańcuch na stronie ──────────────────────────────────────────────────
    t.section('strona: pola języka z zakładki SEO');
    const j1pl = await strona('/strona-seo-j1/');
    t.check('J1 po polsku: tytuł, opis i słowa z zakładki (jak dotąd)',
      j1pl.title === 'Tytuł SEO PL J1' && J(j1pl.description) === J(['Opis SEO PL J1']) && J(j1pl.keywords) === J(['slowa, pl']),
      opis(j1pl, ['title', 'description', 'keywords']));
    const j1en = await strona('/en/strona-seo-j1/');
    t.check('J1 po angielsku: tytuł i opis z pól EN', j1en.title === 'SEO title EN J1' && J(j1en.description) === J(['SEO description EN J1']),
      opis(j1en, ['title', 'description']));
    t.check('J1 po angielsku: og:title i og:description z pól EN',
      J(j1en['og:title']) === J(['SEO title EN J1']) && J(j1en['og:description']) === J(['SEO description EN J1']),
      opis(j1en, ['og:title', 'og:description']));
    t.check('J1 po angielsku: słowa kluczowe bez pola EN — żadnych (nie polskie)', !j1en.keywords, opis(j1en, ['keywords']));
    const j1de = await strona('/de/strona-seo-j1/');
    t.check('J1 po niemiecku (bez pól DE): tytuł polski — lepszy niż żaden', j1de.title === 'Tytuł SEO PL J1', opis(j1de, ['title']));
    t.check('J1 po niemiecku: bez opisu i bez og:description (polski opis przepada)',
      !j1de.description && !j1de['og:description'] && !j1de.keywords, opis(j1de, ['description', 'og:description', 'keywords']));

    t.section('strona: znaczniki {tl_…} działają jak dotąd');
    const j2en = await strona('/en/strona-seo-j2/');
    t.check('J2 po angielsku: tytuł z {tl_klucz} w Bricksie, opis z {tl:pl=…|en=…}',
      j2en.title === 'Title from dictionary J2' && J(j2en.description) === J(['Inline description EN']),
      opis(j2en, ['title', 'description']));
    const j2pl = await strona('/strona-seo-j2/');
    t.check('J2 po polsku: frazy polskie', j2pl.title === 'Tytuł ze słownika J2' && J(j2pl.description) === J(['Opis inline PL']),
      opis(j2pl, ['title', 'description']));

    t.section('strona: OG w wersji językowej');
    const j3pl = await strona('/strona-seo-j3/');
    t.check('J3 po polsku: OG z „Mediów społecznościowych" Bricksa',
      J(j3pl['og:title']) === J(['Tytuł do udostępnień J3']) && J(j3pl['og:description']) === J(['Opis do udostępnień J3']),
      opis(j3pl, ['og:title', 'og:description']));
    const j3en = await strona('/en/strona-seo-j3/');
    t.check('J3 po angielsku: polski tekst z Bricksa przepada — og:title z tytułu EN, bez og:description',
      J(j3en['og:title']) === J(['SEO title EN J3']) && !j3en['og:description'], opis(j3en, ['og:title', 'og:description']));
    const j4en = await strona('/en/strona-seo-j4/');
    t.check('J4 po angielsku (nic nie ustawione): tytuł strony z nazwą witryny, bez opisu',
      j4en.title.startsWith('Strona SEO J4') && j4en.title.length > 'Strona SEO J4'.length && !j4en.description, opis(j4en, ['title', 'description']));

    // ── Zapis przez punkt AJAX ──────────────────────────────────────────────
    t.section('zapis wiersza (AJAX): pola języków');
    const id = prep.strony;
    const zap = ajax('evoke_save_seo_ajax', { post_id: id.j4, seo_title: 'Tytuł \\ z ukośnikiem', seo_desc: '', seo_keywords: '', seo_robots: '[]',
      seo_jezyki: J({ en: { title: 'EN \\ title J4', desc: 'EN desc J4', keywords: '' }, fr: { title: 'Titre' } }) });
    let meta = sonda('meta').meta || {};
    t.check('zapis odpowiada sukcesem', (zap || {}).success === true, J(zap));
    t.check('pola EN zapisane, ukośnik wsteczny zostaje (także w polu PL)',
      meta['strona-seo-j4']._evk_tl_en__seo_title === 'EN \\ title J4' && meta['strona-seo-j4']._evk_tl_en__seo_desc === 'EN desc J4'
        && meta['strona-seo-j4']._evoke_seo_title === 'Tytuł \\ z ukośnikiem',
      J(meta['strona-seo-j4']));
    t.check('język spoza Tłumaczeń (fr) pominięty', !('_evk_tl_fr__seo_title' in meta['strona-seo-j4']), J(meta['strona-seo-j4']));
    ajax('evoke_save_seo_ajax', { post_id: id.j4, seo_title: 'Tytuł \\ z ukośnikiem', seo_desc: '', seo_keywords: '', seo_robots: '[]',
      seo_jezyki: J({ en: { title: 'EN \\ title J4', desc: '', keywords: '' } }) });
    meta = sonda('meta').meta || {};
    t.check('wyczyszczone pole EN usuwa metadaną', !('_evk_tl_en__seo_desc' in meta['strona-seo-j4']), J(meta['strona-seo-j4']));
    const zly = ajax('evoke_save_seo_ajax', { nonce: 'zly', post_id: id.j4, seo_title: 'x', seo_jezyki: J({ en: { title: 'wlamanie' } }) });
    meta = sonda('meta').meta || {};
    t.check('zły nonce: odmowa, nic nie zapisane', zly === -1 && meta['strona-seo-j4']._evk_tl_en__seo_title === 'EN \\ title J4', J(zly));
    const zb = ajax('evoke_save_seo_bulk', { rows: J([{ post_id: id.j4, seo_title: 'Tytuł \\ z ukośnikiem', seo_desc: '', seo_keywords: '', seo_robots: [],
      seo_jezyki: { de: { title: 'DE Titel J4', desc: 'DE Beschreibung J4', keywords: 'de, j4' } } }]) });
    meta = sonda('meta').meta || {};
    t.check('zapis zbiorczy: pola DE, pola EN nieprzysłane zostają',
      (zb || {}).success === true && meta['strona-seo-j4']._evk_tl_de__seo_title === 'DE Titel J4' && meta['strona-seo-j4']._evk_tl_de__seo_keywords === 'de, j4'
        && meta['strona-seo-j4']._evk_tl_en__seo_title === 'EN \\ title J4', J(meta['strona-seo-j4']));

    // ── Zakładka SEO w przeglądarce ────────────────────────────────────────
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    p.on('pageerror', (e) => bledy.push(e.message));
    await serwerWp.zaloguj(p, b);
    const zakladka = b + '/wp-admin/options-general.php?page=evoke-one&tab=strona&sub=meta&seo_pt=page&seo_s=' + encodeURIComponent('Strona SEO J');
    await p.goto(zakladka, { waitUntil: 'load' });

    t.section('zakładka SEO: przełącznik języka');
    const stan = () => p.evaluate(() => ({
      wcisniety: [...document.querySelectorAll('.evk-seo-przelacznik .evk-tl-jezyk')].map((x) => x.dataset.lang + ':' + x.getAttribute('aria-pressed') + ':' + x.classList.contains('button-primary')),
      widoczne: [...new Set([...document.querySelectorAll('.evk-seo-wersja')].filter((w) => !w.hidden && w.offsetHeight > 0).map((w) => w.dataset.lang))],
      robots: [...document.querySelectorAll('.evk-seo-wspolne')].map((w) => w.inert + ':' + getComputedStyle(w).opacity)[0],
      liczniki: [...document.querySelectorAll('.evk-seo-przelacznik .evk-tl-jezyk')].map((x) => x.dataset.lang + '=' + ((x.querySelector('.evk-tl-licznik') || {}).textContent || '')),
      sr: (document.querySelector('.evk-seo-przelacznik [data-lang="en"] .evk-tl-licznik-sr') || {}).textContent,
      adres: location.search,
      nastepna: [...document.querySelectorAll('.evk-seo-types a')].map((a) => a.href).find((h) => h.includes('seo_pt=post')) || '',
    }));
    let s = await stan();
    t.check('na starcie polski: PL wciśnięty, widać tylko pola polskie, robots aktywne',
      J(s.wcisniety) === J(['pl:true:true', 'en:false:false', 'de:false:false']) && J(s.widoczne) === J(['pl']) && s.robots === 'false:1', J(s));
    /* Pola z polską wartością (także z Bricksa): J1 3, J2 2, J4 1 (tytuł
       z zapisu AJAX wyżej), J5 2, J6 2 = 10. Wypełnione EN: J1 tytuł i opis,
       J4 tytuł, J5 opis = 4; DE: J4 tytuł = 1. J3 ma pole EN, ale bez polskiej
       wartości — nie liczy się. */
    t.check('liczniki: wypełnione pola języka z tych, które mają wartość polską', J(s.liczniki) === J(['pl=', 'en=4/10', 'de=1/10']), J(s.liczniki));
    t.check('licznik dla czytnika ekranu słowami', s.sr === ', przetłumaczone 4 z 10', J(s.sr));
    const bricks = await p.$$eval('.evk-seo-wersja[data-lang="pl"] .evk-seo-bricks', (e) => e.map((x) => x.textContent.trim()));
    t.check('wartość z Bricksa widać pod polem polskim (ma pierwszeństwo)',
      bricks.includes('Bricks (ma pierwszeństwo): {tl_seo_j2_tytul}') && bricks.includes('Bricks (ma pierwszeństwo): {tl_seo_j6}'), J(bricks));

    await p.click('.evk-seo-przelacznik [data-lang="en"]');
    s = await stan();
    t.check('EN: wciśnięty EN, widać tylko pola EN', J(s.wcisniety) === J(['pl:false:false', 'en:true:true', 'de:false:false']) && J(s.widoczne) === J(['en']), J(s));
    t.check('EN: robots wspólne — przygaszone i nieaktywne (inert)', s.robots === 'true:0.45', J(s.robots));
    t.check('EN: język w adresie i w odnośnikach do innych typów treści', s.adres.includes('seo_lang=en') && s.nastepna.includes('seo_lang=en'), J(s));
    const orygJ2 = await p.$$eval('tr.evoke-seo-row', (rows) => {
      const r = rows.find((x) => x.querySelector('.evoke-seo-post-title').textContent.includes('J2'));
      return r ? r.querySelector('.evk-seo-wersja[data-lang="en"] .evk-seo-oryginal').textContent.replace(/\s+/g, ' ').trim() : '';
    });
    t.check('EN: pod polem polska wartość, z zaznaczeniem, że z Bricksa', orygJ2 === 'PL (Bricks): {tl_seo_j2_tytul}', J(orygJ2));

    const wiersz = (nr) => 'tr.evoke-seo-row:has(.evoke-seo-post-title:text("Strona SEO J' + nr + '"))';
    await p.fill(wiersz(1) + ' .evk-seo-wersja[data-lang="en"] .evk-seo-pole[data-pole="keywords"]', 'keywords en j1');
    s = await stan();
    t.check('wpisanie pola EN od razu podbija licznik', J(s.liczniki) === J(['pl=', 'en=5/10', 'de=1/10']), J(s.liczniki));
    await p.click(wiersz(1) + ' .evoke-save-seo');
    await p.waitForFunction(() => [...document.querySelectorAll('.evoke-save-seo')].some((x) => x.textContent.includes('Zapisano')), null, { timeout: 15000 }).catch(() => {});
    meta = sonda('meta').meta || {};
    t.check('Zapisz w wierszu zapisuje pole EN (przez prawdziwy admin-ajax)', meta['strona-seo-j1']._evk_tl_en__seo_keywords === 'keywords en j1'
      && meta['strona-seo-j1']._evk_tl_en__seo_title === 'SEO title EN J1', J(meta['strona-seo-j1']));

    await p.click('.evk-seo-przelacznik [data-lang="de"]');
    await p.fill(wiersz(3) + ' .evk-seo-wersja[data-lang="de"] .evk-seo-pole[data-pole="title"]', 'DE Titel J3');
    await p.click('#evoke-seo-save-all');
    await p.waitForFunction(() => /Zapisano/.test(document.querySelector('#evoke-seo-bulk-status').textContent), null, { timeout: 15000 }).catch(() => {});
    meta = sonda('meta').meta || {};
    t.check('„Zapisz zmienione" zapisuje pole DE zmienionego wiersza', meta['strona-seo-j3']._evk_tl_de__seo_title === 'DE Titel J3', J(meta['strona-seo-j3']));

    await p.goto(zakladka + '&seo_lang=en', { waitUntil: 'load' });
    s = await stan();
    t.check('adres z seo_lang=en: widok EN od razu z serwera', J(s.widoczne) === J(['en']) && s.robots === 'true:0.45'
      && s.wcisniety[1] === 'en:true:true', J(s));
    await p.click('.evk-seo-przelacznik [data-lang="pl"]');
    s = await stan();
    t.check('powrót do PL zdejmuje język z adresu', !s.adres.includes('seo_lang') && J(s.widoczne) === J(['pl']) && s.robots === 'false:1', J(s));

    // ── Przeniesienie {tl_…} ────────────────────────────────────────────────
    t.section('przeniesienie {tl_…}: podgląd');
    const box = await p.$eval('#evk-seo-tl', (e) => e.textContent.replace(/\s+/g, ' ')).catch(() => '');
    t.check('pudełko przeniesienia: 3 strony ze znacznikiem (J2, J5, J6)', box.includes('Na 3 stronach'), box.slice(0, 160));
    t.check('przycisk „Przenieś" schowany do podglądu', await p.$eval('#evk-seo-tl-przenies-w', (e) => e.hidden && e.offsetHeight === 0), '');
    await p.click('#evk-seo-tl-podglad');
    await p.waitForSelector('.evk-seo-tl-tabela tbody tr', { timeout: 15000 }).catch(() => {});
    const podglad = await p.$$eval('.evk-seo-tl-tabela tbody tr', (rs) => rs.map((r) => [...r.cells].map((c) => c.textContent.trim()).join(' | ')));
    t.check('podgląd: pole, źródło, polski tekst i tłumaczenia; istniejące pole EN zostaje; brak frazy DE widać',
      J(podglad) === J([
        'Strona SEO J2 | Tytuł (Bricks) | Tytuł ze słownika J2 | Title from dictionary J2 | — brak tłumaczenia',
        'Strona SEO J2 | Opis (Bricks) | Opis inline PL | Inline description EN | — brak tłumaczenia',
        'Strona SEO J5 | Tytuł | Tytuł J5 | Firma | Title J5 | Firma | Titel J5 | Firma',
        'Strona SEO J5 | Opis | Opis J5 | zostaje wpisane: Istniejący opis EN J5 | — brak tłumaczenia',
        'Strona SEO J6 | Tytuł (Bricks) | Tytuł J6 | Title J6 | — brak tłumaczenia',
        'Strona SEO J6 | Słowa kluczowe | Znacznik bez frazy w słowniku — zostaje: {tl_brak_klucza_j6} | — | —',
      ]), J(podglad));
    t.check('stan: 5 pól do przeniesienia, przycisk „Przenieś" widoczny',
      (await p.textContent('#evk-seo-tl-stan')) === 'Do przeniesienia: 5 pól.' && await p.$eval('#evk-seo-tl-przenies', (e) => e.offsetHeight > 0),
      await p.textContent('#evk-seo-tl-stan'));

    t.section('przeniesienie {tl_…}: zapis');
    p.once('dialog', (d) => d.accept());
    await Promise.all([p.waitForNavigation({ waitUntil: 'load', timeout: 30000 }).catch(() => {}), p.click('#evk-seo-tl-przenies')]);
    meta = sonda('meta').meta || {};
    const m2 = meta['strona-seo-j2'] || {}, m5 = meta['strona-seo-j5'] || {}, m6 = meta['strona-seo-j6'] || {};
    t.check('Bricks: polski tekst w miejscu znacznika, tłumaczenia w polach EN',
      (m2._bricks_page_settings || {}).documentTitle === 'Tytuł ze słownika J2' && (m2._bricks_page_settings || {}).metaDescription === 'Opis inline PL'
        && m2._evk_tl_en__seo_title === 'Title from dictionary J2' && m2._evk_tl_en__seo_desc === 'Inline description EN' && !m2._evk_tl_de__seo_title,
      J(m2));
    t.check('zakładka: tytuł ze znacznikiem i tekstem wokół — PL, EN i DE',
      m5._evoke_seo_title === 'Tytuł J5 | Firma' && m5._evk_tl_en__seo_title === 'Title J5 | Firma' && m5._evk_tl_de__seo_title === 'Titel J5 | Firma', J(m5));
    t.check('wpisane wcześniej pole EN nienadpisane', m5._evoke_seo_desc === 'Opis J5' && m5._evk_tl_en__seo_desc === 'Istniejący opis EN J5', J(m5));
    t.check('znacznik bez frazy i „Media społecznościowe" Bricksa nietknięte',
      m6._evoke_seo_keywords === '{tl_brak_klucza_j6}' && (m6._bricks_page_settings || {}).sharingTitle === '{tl_seo_j6}'
        && (m6._bricks_page_settings || {}).documentTitle === 'Tytuł J6' && m6._evk_tl_en__seo_title === 'Title J6', J(m6));
    const box2 = await p.$eval('#evk-seo-tl', (e) => e.textContent.replace(/\s+/g, ' ')).catch(() => '');
    t.check('po przeładowaniu pudełko liczy już tylko J6', box2.includes('Na 1 stronie'), box2.slice(0, 120));
    const drugi = ajax('evoke_seo_tl_przenies', {});
    t.check('drugie przeniesienie niczego nie zmienia (J6: znacznik bez frazy pominięty)',
      J((drugi || {}).data) === J({ pola: 0, jezyki: 0, pominiete: 1 }), J(drugi));

    t.section('przeniesienie {tl_…}: strona po przeniesieniu');
    const j5 = [await strona('/strona-seo-j5/'), await strona('/en/strona-seo-j5/'), await strona('/de/strona-seo-j5/')].map((x) => x.title);
    t.check('J5: tytuł PL, EN i DE jak przed przeniesieniem', J(j5) === J(['Tytuł J5 | Firma', 'Title J5 | Firma', 'Titel J5 | Firma']), J(j5));
    const j2 = await strona('/en/strona-seo-j2/');
    t.check('J2 po angielsku: tytuł i opis jak przed przeniesieniem (teraz z pól EN)',
      j2.title === 'Title from dictionary J2' && J(j2.description) === J(['Inline description EN']), opis(j2, ['title', 'description']));
    t.check('bez błędów JS na zakładce', !bledy.length, bledy.join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
