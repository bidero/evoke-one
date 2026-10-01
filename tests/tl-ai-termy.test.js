/**
 * Kategorie i tagi z AI (1.270.0): nazwa, opis i adres z nazwy — osobne pola
 * wyboru w Tłumaczenia → AI (domyślnie odznaczone, jak przy wpisach), ✦ przy
 * nazwie i opisie w edycji termu, „Z nazwy” przy adresie, lista „Do sprawdzenia”.
 *
 * Zapis jak formularz modułu termów (56): meta termu `_evk_tl_{język}__{pole}`,
 * źródło `ai-{skrót}`. Adres z nazwy trafia do Slugów URL tylko bez konfliktu.
 *
 * Dostawca AI — atrapa (tests/php/_ai-atrapa.php, Gemini: „EN:” przed każdym
 * węzłem tekstu). Sonda: tests/php/tl-ai-termy.php. Środowisko: tools/testowy-wp.sh.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('tl-ai-termy.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;

  let serwer = null;
  let browser = null;
  try {
    sonda('sprzataj');
    const m = sonda('modul');
    const u = sonda('ustaw');
    const T = u.termy || {};
    t.check('sonda: moduł, termy K1–K3, strona z adresem, klucz AI', m.gotowe === true && u.gotowe === true, J([m, u.brak || T]));
    if (u.gotowe !== true) return;

    // ── Lista hurtu ──────────────────────────────────────────────────────
    t.section('lista hurtu: pola wyboru kategorii i tagów');
    const lista = (...a) => (sonda('jednostki', ...a).jednostki || []).map((j) => [j.tytul, j.czesc, j.braki, /term\.php\?taxonomy=\w+&tag_ID=\d+/.test(j.adres)]);
    const bez = lista('-');
    const oba = lista('name,description');
    const adr = lista('-', 'adres');
    console.log('      nazwa i opis: ' + J(oba) + '\n      adres: ' + J(adr));
    t.check('bez pól wyboru: termy poza hurtem', bez.length === 0, J(bez));
    t.check('„Nazwa” i „Opis”: kategoria z opisem — 2 na język; ręczna nazwa EN (K2, K3) — tylko DE; odnośnik do edycji termu',
      J(oba) === J([['Usługi AI (Category)', 'Nazwa i opis', { en: 2, de: 2 }, true], ['Promocja AI (Tag)', 'Nazwa i opis', { de: 1 }, true],
        ['Cennik kat AI (Category)', 'Nazwa i opis', { de: 1 }, true]]), J(oba));
    t.check('sam „Adres z nazwy”: termy z nazwą EN, bez członu w mapie', J(adr) === J([['Promocja AI (Tag)', 'Adres z nazwy', { en: 1 }, true],
      ['Cennik kat AI (Category)', 'Adres z nazwy', { en: 1 }, true]]), J(adr));

    // ── Kroki ────────────────────────────────────────────────────────────
    t.section('kroki: nazwa, opis, adres z nazwy');
    const k1 = sonda('krok', 'K1', 'en', 'name,description', 'adres');
    const s1 = sonda('stan');
    const m1 = ((s1.termy || {}).K1 || {}).meta || {};
    console.log('      K1: ' + J(k1.kroki) + ' | ' + J(m1));
    t.check('kategoria: nazwa i opis EN w jednym zapytaniu, źródło ze znacznikiem „ai-”', k1.zadania === 1 && m1._evk_tl_en__name === 'EN:Usługi AI'
      && m1._evk_tl_en__name__zrodlo === 'ai-' + s1.skroty.K1_nazwa && m1._evk_tl_en__description === 'EN:Krótki opis usług AI'
      && m1._evk_tl_en__description__zrodlo === 'ai-' + s1.skroty.K1_opis, J([k1.zadania, m1]));
    t.check('kontekst: rodzaj termu i pole, tytuł „Nazwa (rodzaj)”', (k1.wiadomosc || '').includes('1. [Category · Nazwa] "Usługi AI"')
      && (k1.wiadomosc || '').includes('Usługi AI (Category)'), (k1.wiadomosc || '').slice(0, 200));
    const a1 = ((k1.kroki || []).slice(-1)[0] || {}).adres || {};
    t.check('adres z nazwy po ostatnim kroku: człon z tłumaczenia nazwy w mapie', a1.stan === 'zapisany' && a1.czlon === 'enuslugi-ai'
      && (s1.mapa || []).some((w) => w.pl === 'uslugi-ai' && w.en === 'enuslugi-ai'), J([a1, s1.mapa]));
    const k2 = sonda('krok', 'K2', 'en', '-', 'adres');
    const k3 = sonda('krok', 'K3', 'en', '-', 'adres');
    const s2 = sonda('stan');
    const a2 = ((k2.kroki || [])[0] || {}).adres || {};
    const a3 = ((k3.kroki || [])[0] || {}).adres || {};
    t.check('sam adres (bez AI): z ręcznej nazwy EN tagu, bez zapytania', a2.stan === 'zapisany' && a2.czlon === 'promotion-ai' && k2.zadania === 0, J([a2, k2.zadania]));
    t.check('konflikt: człon to polski adres strony — bez zapisu, z powodem', a3.stan === 'konflikt' && a3.czlon === 'price-list-kat-ai'
      && /polski adres: .*„Lista cen kat”/.test(a3.powod) && !(s2.mapa || []).some((w) => w.pl === 'cennik-kat-ai'), J([a3, s2.mapa]));
    const ak = sonda('ajax-krok', 'K3', 'de', 'name');
    const akb = sonda('ajax-krok', 'K3', 'de', 'name', 'bez-prawa');
    t.check('krok przez AJAX: z prawem edycji termu — zapisuje; konto bez niego — 403, bez zapisu', ak.odp && ak.odp.success === true && ak.odp.data.zapisane === 1
      && akb.odp && akb.odp.success === false && /Brak uprawnień/.test(String(akb.odp.data)), J([ak.odp, akb.odp]));

    // ── Do sprawdzenia ───────────────────────────────────────────────────
    t.section('lista „Do sprawdzenia”: nazwy i opisy termów');
    const l1 = sonda('lista');
    console.log('      wiersze: ' + J((l1.wiersze || []).map((w) => [w.post_id, w.klucz, w.ai])));
    t.check('wiersze AI: nazwa DE kategorii K3, nazwa i opis EN kategorii K1 (najnowsze najpierw); ręczne nazwy bez wierszy',
      J((l1.wiersze || []).map((w) => [w.post_id, w.klucz, w.ai])) === J([[T.K3, 'name|de', true], [T.K1, 'name|en', true], [T.K1, 'description|en', true]]),
      J(l1.wiersze));
    t.check('HTML: nazwa termu z odnośnikiem do edycji termu, „Category: Nazwa”, „Sprawdzone” z częścią evk_term',
      new RegExp('term\\.php\\?taxonomy=category&(amp;|#038;)tag_ID=' + T.K1).test(l1.html || '') && (l1.html || '').includes('>Usługi AI</a>')
      && (l1.html || '').includes('<td>Category: Nazwa</td>') && (l1.html || '').includes('data-meta="evk_term" data-klucz="name|en"'), (l1.html || '').slice(0, 300));
    const sp = sonda('ajax-sprawdzone', 'K3', 'name|de');
    sonda('zmien', 'K2', 'name', 'Promocja AI nowa');
    const l2 = sonda('lista');
    t.check('„Sprawdzone” (AJAX) zdejmuje wiersz; zmiana polskiej nazwy — wiersz „zmienił się oryginał”', sp.odp && sp.odp.success === true
      && !l2.wiersze.some((w) => w.post_id === T.K3) && l2.wiersze.some((w) => w.post_id === T.K2 && w.klucz === 'name|en' && w.ai === false),
      J([sp.odp, (l2.wiersze || []).map((w) => [w.post_id, w.klucz, w.ai])]));

    // ── Przeglądarka ─────────────────────────────────────────────────────
    t.section('edycja kategorii: ✦ przy nazwie i opisie, adres z nazwy (Chromium)');
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    p.on('pageerror', (e) => { if (!/\/wp-admin\/(js|load-scripts)/.test(String(e.stack || ''))) bledy.push(e.message); });
    await serwerWp.zaloguj(p, serwer.baza);
    /* Atrapa AI działa w procesie sondy — żądanie ✦ idzie przez nią; „Z nazwy” — prawdziwy serwer. */
    const plikAjax = path.join(os.tmpdir(), 'evk-t-tl-ai-termy-ajax.txt');
    const zadaniaAi = [];
    await p.route('**/wp-admin/admin-ajax.php', async (route) => {
      const q = new URLSearchParams(route.request().postData() || '');
      if (q.get('action') !== 'evk_tl_ai_pola') return route.continue();
      q.set('nonce', 'auto');
      fs.writeFileSync(plikAjax, q.toString());
      const r = sonda('ajax-pola', plikAjax);
      zadaniaAi.push([q.get('term_id'), q.get('post_id'), q.get('lang'), r.zadania, (r.wiadomosc || '').slice(0, 120)]);
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(r.odp === undefined ? r : r.odp) });
    });
    await p.goto(serwer.baza + '/wp-admin/term.php?taxonomy=category&tag_ID=' + T.K1, { waitUntil: 'load' });
    await p.click('.evk-tlw-przelacznik .evk-tl-jezyk[data-lang="de"]');
    const POLE = (l, pole) => '.evk-tlw-pole[data-lang="' + l + '"][data-pole="' + pole + '"]';
    const nazwyAi = await p.evaluate(() => Array.from(document.querySelectorAll('.evk-tlw-pole[data-lang="de"] .evk-tlw-ai')).map((b) => b.getAttribute('aria-label')));
    t.check('DE: ✦ przy nazwie i opisie, z nazwą pola i języka', J(nazwyAi) === J(['Przetłumacz (AI) — Nazwa DE', 'Przetłumacz (AI) — Opis DE']), J(nazwyAi));
    const stanPola = (l, pole) => p.evaluate((sel) => {
      const x = document.querySelector(sel);
      const w = (s) => { const e = x && x.querySelector(s); return !!e && !e.hidden && getComputedStyle(e).display !== 'none'; };
      return { wartosc: x.querySelector('.evk-tlw-wejscie').value, zrodlo: x.querySelector('.evk-tlw-zrodlo').value, znak: w('.evk-tlw-ai-znak'),
        stan: (x.querySelector('.evk-tlw-ai-stan') || {}).textContent || '' };
    }, POLE(l, pole));
    const adres = (l) => p.evaluate((sel) => { const a = document.querySelector(sel);
      return { czlon: a.querySelector('.evk-tlw-slug').value, stan: (a.querySelector('.evk-tlw-adres-stan') || {}).textContent }; }, POLE(l, 'slug'));
    const poAi = (sel) => p.waitForFunction((s) => /Wpisane|odmówił|Brak|nic nie wpisuję/.test((document.querySelector(s + ' .evk-tlw-ai-stan') || {}).textContent || ''),
      sel, { timeout: 20000 }).catch(() => {});
    await p.click(POLE('de', 'name') + ' .evk-tlw-ai');
    await poAi(POLE('de', 'name'));
    await p.waitForFunction((s) => (document.querySelector(s + ' .evk-tlw-adres-stan') || {}).textContent, POLE('de', 'slug'), { timeout: 10000 }).catch(() => {});
    const n1 = await stanPola('de', 'name');
    const a1de = await adres('de');
    console.log('      nazwa DE: ' + J(n1) + ' | adres: ' + J(a1de) + ' | AI: ' + J(zadaniaAi));
    t.check('✦ nazwy: tłumaczenie w polu DE, źródło „ai”, znak, komunikat z „Aktualizuj”', n1.wartosc === 'DE:Usługi AI' && n1.zrodlo === 'ai' && n1.znak
      && /^Wpisane \(AI · .+\) — sprawdź i zapisz \(Aktualizuj\)\.$/.test(n1.stan), J(n1));
    t.check('✦ nazwy: żądanie z identyfikatorem termu (nie wpisu), kontekst „Category · Nazwa” i opis', zadaniaAi.length === 1 && zadaniaAi[0][0] === String(T.K1)
      && !zadaniaAi[0][1] && zadaniaAi[0][3] === 1 && /Category · Nazwa/.test(zadaniaAi[0][4]), J(zadaniaAi));
    t.check('✦ nazwy wypełnia pusty adres DE członem z nowej nazwy', a1de.czlon === 'deuslugi-ai' && a1de.stan === 'Adres z nazwy — zapisz (Aktualizuj).', J(a1de));
    await p.click(POLE('de', 'description') + ' .evk-tlw-ai');
    await poAi(POLE('de', 'description'));
    const o1 = await stanPola('de', 'description');
    t.check('✦ opisu: tłumaczenie, źródło „ai”, znak', o1.wartosc === 'DE:Krótki opis usług AI' && o1.zrodlo === 'ai' && o1.znak, J(o1));
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#edittag .edit-tag-actions input[type="submit"]')]);
    const s3 = sonda('stan');
    const m3 = ((s3.termy || {}).K1 || {}).meta || {};
    console.log('      zapis DE: ' + J(Object.fromEntries(Object.entries(m3).filter(([k]) => k.includes('_de_')))) + ' | mapa: ' + J(s3.mapa));
    t.check('„Aktualizuj”: nazwa i opis DE ze znacznikiem „ai-” (skrót polskiego tekstu)', m3._evk_tl_de__name === 'DE:Usługi AI'
      && m3._evk_tl_de__name__zrodlo === 'ai-' + s3.skroty.K1_nazwa && m3._evk_tl_de__description__zrodlo === 'ai-' + s3.skroty.K1_opis, J(m3));
    t.check('„Aktualizuj”: człon DE z nazwy w mapie adresów', (s3.mapa || []).some((w) => w.pl === 'uslugi-ai' && w.de === 'deuslugi-ai'), J(s3.mapa));

    t.section('edycja kategorii: „Z nazwy”, formularz dodawania (Chromium)');
    await p.goto(serwer.baza + '/wp-admin/term.php?taxonomy=category&tag_ID=' + T.K3, { waitUntil: 'load' });
    await p.click('.evk-tlw-przelacznik .evk-tl-jezyk[data-lang="en"]');
    const nazwaZ = await p.getAttribute(POLE('en', 'slug') + ' .evk-tlw-z-tytulu', 'aria-label');
    await p.click(POLE('en', 'slug') + ' .evk-tlw-z-tytulu');
    await p.waitForFunction((s) => (document.querySelector(s + ' .evk-tlw-adres-stan') || {}).textContent, POLE('en', 'slug'), { timeout: 10000 }).catch(() => {});
    const a3en = await adres('en');
    console.log('      K3 EN: ' + J(a3en));
    t.check('„Z nazwy” bez AI: człon z nazwy EN; konflikt z polskim adresem strony — powód', nazwaZ === 'Adres EN z nazwy EN'
      && a3en.czlon === 'price-list-kat-ai' && /„price-list-kat-ai” to polski adres: .*Lista cen kat/.test(a3en.stan || ''), J([nazwaZ, a3en]));
    t.check('✦ wołane tylko przy „✦”', zadaniaAi.length === 2, J(zadaniaAi.length));
    await p.goto(serwer.baza + '/wp-admin/edit-tags.php?taxonomy=category', { waitUntil: 'load' });
    const dodaj = await p.evaluate(() => [document.querySelectorAll('#addtag .evk-tlw-pole').length > 0, document.querySelectorAll('.evk-tlw-ai, .evk-tlw-z-tytulu').length,
      typeof window.evkTlwAi]);
    t.check('formularz dodawania kategorii: pola języków są, bez ✦ i „Z nazwy” (termu jeszcze nie ma)', J(dodaj) === J([true, 0, 'undefined']), J(dodaj));
    await p.unroute('**/wp-admin/admin-ajax.php');

    t.section('Tłumaczenia → AI: pola wyboru kategorii i tagów (Chromium)');
    await p.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=ai', { waitUntil: 'load' });
    const pw = await p.evaluate(() => Array.from(document.querySelectorAll('.tl-ai-termy input')).map((c) => [c.value === 'on' ? c.id : c.value, c.checked,
      (c.closest('label') || {}).textContent.trim()]));
    t.check('trzy pola wyboru, odznaczone: Nazwa, Opis, Adres z nazwy', J(pw) === J([['name', false, 'Nazwa'], ['description', false, 'Opis'],
      ['tl-ai-term-adres', false, 'Adres z nazwy']]), J(pw));
    await p.check('.tl-ai-term-pole[value="name"]');
    await p.click('.tl-ai-lista');
    await p.waitForFunction(() => /Części stron z brakami|Nie ma/.test((document.querySelector('.tl-ai-stan') || {}).textContent || ''), null, { timeout: 20000 }).catch(() => {});
    const wiersze = await p.evaluate(() => Array.from(document.querySelectorAll('.tl-ai-jednostki tbody tr')).map((r) => [r.children[1].textContent, r.children[2].textContent,
      r.children[1].querySelector('a').getAttribute('href')]));
    /* K1 przetłumaczona w obu językach, K3 ma nazwę DE z kroku AJAX — w liście zostaje tag K2 (brak nazwy DE). */
    t.check('„Nazwa” zaznaczona: term z brakiem w liście jako „Nazwa i opis”, z odnośnikiem do edycji termu; przetłumaczone — bez wierszy',
      wiersze.some((w) => w[0] === 'Promocja AI nowa (Tag)' && w[1] === 'Nazwa i opis' && /term\.php\?taxonomy=post_tag/.test(w[2]))
      && !wiersze.some((w) => /^(Usługi AI|Cennik kat AI) /.test(w[0])), J(wiersze));
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
