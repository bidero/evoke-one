/**
 * Hurt AI tekstów wpisów (1.268.0): tytuł, treść (edytor WordPressa), zajawka
 * i adres z tytułu — osobne pola wyboru w Tłumaczenia → AI, domyślnie
 * odznaczone (decyzja zgłaszającego z 30.09).
 *
 * Zapis jak formularz modułu wpisów (55): meta `_evk_tl_{język}__{pole}`, źródło
 * `ai-{skrót}` — „AI — do sprawdzenia” w edycji wpisu i w liście „Do
 * sprawdzenia”. Adres z tytułu trafia do Slugów URL tylko bez konfliktu.
 *
 * Dostawca AI — atrapa (tests/php/_ai-atrapa.php, Gemini: „EN:” przed każdym
 * węzłem tekstu). Sonda: tests/php/tl-ai-wpisy.php. Środowisko: tools/testowy-wp.sh.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('tl-ai-wpisy.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const WSZYSTKIE = 'post_title,post_content,post_excerpt';

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
    const W = u.wpisy || {};
    t.check('sonda: moduł, wpisy P1–P5, klucz AI, klasyczny edytor', m.gotowe === true && u.gotowe === true && !!(W.P1 && W.P5), J([m, u.brak || W]));
    if (u.gotowe !== true) return;

    // ── Lista hurtu ──────────────────────────────────────────────────────
    t.section('lista hurtu: pola wyboru');
    const lista = (...a) => (sonda('jednostki', ...a).jednostki || []).map((j) => [j.tytul, j.czesc, j.braki]);
    const bez = lista('-');
    const tyt = lista('post_title');
    const wsz = lista(WSZYSTKIE);
    const adr = lista('-', 'adres');
    console.log('      tytuł: ' + J(tyt) + '\n      wszystkie: ' + J(wsz) + '\n      adres: ' + J(adr));
    t.check('bez pól wyboru: teksty wpisów poza hurtem', bez.length === 0, J(bez));
    t.check('„Tytuł”: każdy wpis z brakującym tytułem; ręcznie przetłumaczony EN (P3, P5) — tylko DE', J(tyt) === J([
      ['Nasza nowa oferta AI', 'Teksty wpisu', { en: 1, de: 1 }], ['Strona Bricks AI', 'Teksty wpisu', { en: 1, de: 1 }],
      ['Kontakt AI', 'Teksty wpisu', { de: 1 }], ['Lista cen', 'Teksty wpisu', { en: 1, de: 1 }], ['Cennik AI', 'Teksty wpisu', { de: 1 }]]), J(tyt));
    t.check('tytuł, treść i zajawka: wpis — 3; strona w Bricksie — sam tytuł; strony bez zajawki i pustej treści — sam tytuł',
      J(wsz[0]) === J(['Nasza nowa oferta AI', 'Teksty wpisu', { en: 3, de: 3 }]) && J(wsz[1]) === J(['Strona Bricks AI', 'Teksty wpisu', { en: 1, de: 1 }]), J(wsz));
    t.check('sam „Adres z tytułu”: wpisy z tytułem EN, bez członu w mapie', J(adr) === J([['Kontakt AI', 'Adres wpisu', { en: 1 }], ['Cennik AI', 'Adres wpisu', { en: 1 }]]),
      J(adr));

    // ── Kroki ────────────────────────────────────────────────────────────
    t.section('kroki: tytuł, treść, zajawka, adres');
    const k1 = sonda('krok', 'P1', 'en', WSZYSTKIE, 'adres');
    const s1 = sonda('stan');
    const p1 = (s1.wpisy || {}).P1 || { meta: {} };
    console.log('      P1: ' + J(k1.kroki) + ' | ' + J(p1.meta));
    t.check('wpis: tytuł, treść i zajawka EN w jednym zapytaniu, źródło ze znacznikiem „ai-”',
      k1.zadania === 1 && p1.meta._evk_tl_en__post_title === 'EN:Nasza nowa oferta AI' && p1.meta._evk_tl_en__post_title__zrodlo === 'ai-' + s1.skroty.P1_tytul
      && p1.meta._evk_tl_en__post_content === '<p>EN:Treść wpisu o <strong>EN:ofercie</strong>EN:.</p>' && p1.meta._evk_tl_en__post_content__zrodlo === 'ai-' + s1.skroty.P1_tresc
      && p1.meta._evk_tl_en__post_excerpt === 'EN:Krótka zajawka oferty' && p1.meta._evk_tl_en__post_excerpt__zrodlo === 'ai-' + s1.skroty.P1_zajawka, J([k1.zadania, p1.meta]));
    t.check('kontekst: tytuł, treść i zajawka wpisu z opisem pola', k1.wiadomosc.includes('1. [Wpis · Tytuł] "Nasza nowa oferta AI"')
      && k1.wiadomosc.includes('3. [Wpis · Zajawka] "Krótka zajawka oferty"'), k1.wiadomosc.slice(0, 300));
    const a1 = ((k1.kroki || []).slice(-1)[0] || {}).adres || {};
    t.check('adres z tytułu po ostatnim kroku: człon z tłumaczenia tytułu w mapie', a1.stan === 'zapisany' && a1.czlon === 'ennasza-nowa-oferta-ai'
      && (s1.mapa || []).some((w) => w.pl === 'nasza-nowa-oferta-ai' && w.en === 'ennasza-nowa-oferta-ai'), J([a1, s1.mapa]));
    const k2 = sonda('krok', 'P2', 'en', 'post_title,post_content');
    const s2 = sonda('stan');
    t.check('strona w Bricksie: tylko tytuł, stara treść WordPressa nietknięta; kontekst z elementów Bricksa',
      (k2.kroki || [])[0].zapisane === 1 && s2.wpisy.P2.meta._evk_tl_en__post_content === undefined && k2.wiadomosc.includes('"Tekst z Bricksa"')
      && !k2.wiadomosc.includes('Stara treść') && s2.wpisy.P2.post_content === 'Stara treść WordPressa', J([k2.kroki, s2.wpisy.P2]));
    const k3 = sonda('krok', 'P3', 'en', '-', 'adres');
    const k5 = sonda('krok', 'P5', 'en', '-', 'adres');
    const s3 = sonda('stan');
    const a3 = ((k3.kroki || [])[0] || {}).adres || {};
    const a5 = ((k5.kroki || [])[0] || {}).adres || {};
    t.check('sam adres (bez AI): z ręcznego tytułu EN, bez zapytania; tytuł EN nietknięty', a3.stan === 'zapisany' && a3.czlon === 'contact-ai'
      && k3.zadania === 0 && s3.wpisy.P3.meta._evk_tl_en__post_title === 'Contact AI', J([a3, k3.zadania]));
    t.check('konflikt: człon to polski adres innej strony — bez zapisu, z powodem', a5.stan === 'konflikt' && a5.czlon === 'price-list-ai'
      && /polski adres: .*„Lista cen”/.test(a5.powod) && !(s3.mapa || []).some((w) => w.pl === 'cennik-ai'), J([a5, s3.mapa]));
    const k3b = sonda('krok', 'P3', 'en', '-', 'adres');
    t.check('drugi raz: człon już w mapie — „jest”, bez zmian', (((k3b.kroki || [])[0] || {}).adres || {}).stan === 'jest', J(k3b.kroki));

    // ── Do sprawdzenia ───────────────────────────────────────────────────
    t.section('lista „Do sprawdzenia”: teksty wpisów');
    const l1 = sonda('lista');
    /* Najnowsze wpisy najpierw (1.269.0) — limit listy nie ucina świeżo przetłumaczonych. */
    t.check('wiersze AI: tytuł strony Bricks, tytuł, treść i zajawka wpisu (najnowsze najpierw); ręczne tłumaczenia bez wierszy',
      J((l1.wiersze || []).map((w) => [w.post_id, w.klucz, w.ai])) === J([[W.P2, 'post_title|en', true], [W.P1, 'post_title|en', true],
        [W.P1, 'post_content|en', true], [W.P1, 'post_excerpt|en', true]]), J(l1.wiersze));
    t.check('HTML: „Wpis: Tytuł”, link do edycji wpisu, „Sprawdzone” z częścią evk_wpis',
      l1.html.includes('<td>Wpis: Tytuł</td>') && new RegExp('post\\.php\\?post=' + W.P1 + '&(amp;|#038;)action=edit').test(l1.html)
      && l1.html.includes('data-meta="evk_wpis" data-klucz="post_title|en"'), l1.html.slice(0, 300));
    const sp = sonda('ajax-sprawdzone', 'P2', 'post_title|en');
    sonda('zmien', 'P3', 'post_title', 'Kontakt zmieniony AI');
    const l2 = sonda('lista');
    t.check('„Sprawdzone” (AJAX) zdejmuje wiersz; zmiana polskiego tytułu — wiersz „zmienił się oryginał”', sp.odp && sp.odp.success === true
      && !l2.wiersze.some((w) => w.post_id === W.P2) && l2.wiersze.some((w) => w.post_id === W.P3 && w.klucz === 'post_title|en' && w.ai === false),
      J([sp.odp, l2.wiersze.map((w) => [w.post_id, w.klucz, w.ai])]));

    // ── Przeglądarka ─────────────────────────────────────────────────────
    t.section('edycja wpisu: „AI — do sprawdzenia” i „Sprawdzone” (Chromium)');
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    p.on('pageerror', (e) => { if (!/\/wp-admin\/(js|load-scripts)/.test(String(e.stack || ''))) bledy.push(e.message); });
    await serwerWp.zaloguj(p, serwer.baza);
    await p.goto(serwer.baza + '/wp-admin/post.php?post=' + W.P1 + '&action=edit', { waitUntil: 'load' });
    await p.click('.evk-tlw-przelacznik .evk-tl-jezyk[data-lang="en"]');
    const znaki = () => p.evaluate(() => ['post_title', 'post_content', 'post_excerpt'].map((pole) => {
      const x = document.querySelector('.evk-tlw-pole[data-lang="en"][data-pole="' + pole + '"]');
      const w = (sel) => { const e = x && x.querySelector(sel); return !!e && !e.hidden && getComputedStyle(e).display !== 'none'; };
      return [pole, w('.evk-tlw-ai-znak'), w('.evk-tlw-sprawdzone'), w('.evk-tlw-do-sprawdzenia')];
    }));
    const z1 = await znaki();
    t.check('EN: przy tytule, treści i zajawce „AI — do sprawdzenia” i „Sprawdzone” (bez „polski tekst się zmienił”)',
      J(z1) === J([['post_title', true, true, false], ['post_content', true, true, false], ['post_excerpt', true, true, false]]), J(z1));
    const kolumna = async () => { await p.goto(serwer.baza + '/wp-admin/edit.php', { waitUntil: 'load' });
      return p.evaluate((id) => { const r = document.querySelector('#post-' + id + ' .evk-tlw-stan'); return r ? r.className + ' ' + r.textContent.trim() : null; }, W.P1); };
    await p.click('.evk-tlw-pole[data-lang="en"][data-pole="post_title"] .evk-tlw-sprawdzone');
    /* Pudełko „Zajawka” WordPress domyślnie chowa (opcje ekranu) — wpis jak pisanie: wartość i zdarzenie input. */
    await p.evaluate(() => { const e = document.getElementById('evk-tlw-en-post_excerpt'); e.value = 'Short offer teaser';
      e.dispatchEvent(new Event('input', { bubbles: true })); });
    const z2 = await znaki();
    t.check('„Sprawdzone” przy tytule i ręczna poprawka zajawki zdejmują znacznik (treść — dalej AI)',
      J(z2) === J([['post_title', false, false, false], ['post_content', true, true, false], ['post_excerpt', false, false, false]]), J(z2));
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#publish')]);
    const s4 = sonda('stan').wpisy.P1.meta;
    t.check('zapis: tytuł i zajawka bez znacznika, treść ze znacznikiem', !/^ai-/.test(s4._evk_tl_en__post_title__zrodlo)
      && s4._evk_tl_en__post_excerpt === 'Short offer teaser' && !/^ai-/.test(s4._evk_tl_en__post_excerpt__zrodlo) && /^ai-/.test(s4._evk_tl_en__post_content__zrodlo),
      J(s4));
    const kol = await kolumna();
    t.check('kolumna „Języki” na liście wpisów: EN „do sprawdzenia” (treść z AI)', /is-sprawdz/.test(kol || ''), kol);

    // ── ✦ w edycji wpisu (1.269.0) ───────────────────────────────────────
    t.section('edycja wpisu: ✦ przy tytule, treści i zajawce, adres z tytułu (Chromium)');
    /* Atrapa AI działa w procesie sondy — żądanie ✦ idzie przez nią (nonce
       świeży dla sesji sondy). „Z tytułu” (evk_tlw_czlon) — prawdziwy serwer. */
    const plikAjax = path.join(os.tmpdir(), 'evk-t-tl-ai-wpisy-ajax.txt');
    const zadaniaAi = [];
    await p.route('**/wp-admin/admin-ajax.php', async (route) => {
      const cialo = route.request().postData() || '';
      const q = new URLSearchParams(cialo);
      if (q.get('action') !== 'evk_tl_ai_pola') return route.continue();
      q.set('nonce', 'auto');
      fs.writeFileSync(plikAjax, q.toString());
      const r = sonda('ajax-pola', plikAjax);
      zadaniaAi.push([q.get('lang'), JSON.parse(q.get('teksty') || '{}'), r.zadania]);
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(r.odp === undefined ? r : r.odp) });
    });
    await p.goto(serwer.baza + '/wp-admin/post.php?post=' + W.P1 + '&action=edit', { waitUntil: 'load' });
    await p.click('.evk-tlw-przelacznik .evk-tl-jezyk[data-lang="de"]');
    const POLE = (l, pole) => '.evk-tlw-pole[data-lang="' + l + '"][data-pole="' + pole + '"]';
    const przyciski = await p.evaluate(() => Array.from(document.querySelectorAll('.evk-tlw-pole[data-lang="de"] .evk-tlw-ai'))
      .map((b) => b.getAttribute('aria-label')));
    console.log('      ✦ DE: ' + J(przyciski));
    t.check('DE: ✦ przy tytule, treści i zajawce, każdy z nazwą (pole i język)', J(przyciski.slice().sort()) === J(['Przetłumacz (AI) — Tytuł DE',
      'Przetłumacz (AI) — Treść DE', 'Przetłumacz (AI) — Zajawka DE'].sort()), J(przyciski));
    const stanPola = (l, pole) => p.evaluate((sel) => {
      const x = document.querySelector(sel);
      const w = (s) => { const e = x && x.querySelector(s); return !!e && !e.hidden && getComputedStyle(e).display !== 'none'; };
      const we = x.querySelector('.evk-tlw-wejscie');
      const ed = window.tinymce && we && window.tinymce.get(we.id);
      return { wartosc: ed && !ed.isHidden() ? ed.getContent() : we.value, zrodlo: x.querySelector('.evk-tlw-zrodlo').value, znak: w('.evk-tlw-ai-znak'),
        stan: (x.querySelector('.evk-tlw-ai-stan') || {}).textContent || '' };
    }, POLE(l, pole));
    const adres = (l) => p.evaluate((l) => {
      const a = document.querySelector('.evk-tlw-adres[data-lang="' + l + '"]');
      return { czlon: a.querySelector('.evk-tlw-slug').value, stan: a.querySelector('.evk-tlw-adres-stan').textContent };
    }, l);
    const poAi = (sel) => p.waitForFunction((s) => /Wpisane|odmówił|Brak|nic nie wpisuję/.test((document.querySelector(s + ' .evk-tlw-ai-stan') || {}).textContent || ''),
      sel, { timeout: 20000 }).catch(() => {});

    await p.click(POLE('de', 'post_title') + ' .evk-tlw-ai');
    await poAi(POLE('de', 'post_title'));
    await p.waitForFunction(() => (document.querySelector('.evk-tlw-adres[data-lang="de"] .evk-tlw-adres-stan') || {}).textContent, null, { timeout: 10000 }).catch(() => {});
    const t1 = await stanPola('de', 'post_title');
    const a1de = await adres('de');
    console.log('      tytuł DE: ' + J(t1) + ' | adres: ' + J(a1de) + ' | AI: ' + J(zadaniaAi));
    t.check('✦ tytułu: tłumaczenie w polu DE, źródło „ai”, znak „AI — do sprawdzenia”, komunikat z modelem',
      t1.wartosc === 'DE:Nasza nowa oferta AI' && t1.zrodlo === 'ai' && t1.znak && /^Wpisane \(AI · .+\) — sprawdź i zapisz wpis\.$/.test(t1.stan), J(t1));
    t.check('✦ tytułu: jedno zapytanie, tylko ten tekst (kontekst — pozostałe pola wpisu)', zadaniaAi.length === 1 && zadaniaAi[0][0] === 'de'
      && J(zadaniaAi[0][1]) === J({ k1: { n: 1, bylo: '' } }) && zadaniaAi[0][2] === 1, J(zadaniaAi));
    t.check('✦ tytułu wypełnia pusty adres DE członem z nowego tytułu', a1de.czlon === 'denasza-nowa-oferta-ai' && a1de.stan === 'Adres z tytułu — zapisz wpis.', J(a1de));

    /* Wypełnione pole: pytanie przed nadpisaniem — „Anuluj” zostawia tekst i nie pyta AI. */
    let pytanie = '';
    p.once('dialog', (d) => { pytanie = d.message(); d.dismiss(); });
    await p.click(POLE('de', 'post_title') + ' .evk-tlw-ai');
    await p.waitForTimeout(300);
    const t2 = await stanPola('de', 'post_title');
    t.check('wypełnione pole: pytanie z obecnym tekstem; „Anuluj” — bez zapytania, tekst bez zmian', /Zastąpić obecne tłumaczenie DE\?/.test(pytanie)
      && pytanie.includes('DE:Nasza nowa oferta AI') && zadaniaAi.length === 1 && t2.wartosc === 'DE:Nasza nowa oferta AI', J([pytanie, zadaniaAi.length, t2.wartosc]));

    await p.click(POLE('de', 'post_content') + ' .evk-tlw-ai');
    await poAi(POLE('de', 'post_content'));
    /* Pudełko „Zajawka” jest schowane (opcje ekranu) — klik z poziomu strony. */
    await p.evaluate((s) => document.querySelector(s).click(), POLE('de', 'post_excerpt') + ' .evk-tlw-ai');
    await poAi(POLE('de', 'post_excerpt'));
    const tr = await stanPola('de', 'post_content');
    const za = await stanPola('de', 'post_excerpt');
    console.log('      treść DE: ' + J(tr) + ' | zajawka DE: ' + J(za));
    t.check('✦ treści (edytor wizualny) i zajawki: tłumaczenie w polu, źródło „ai”, znak', /DE:Treść wpisu o/.test(tr.wartosc) && tr.wartosc.includes('<strong>DE:ofercie</strong>')
      && tr.zrodlo === 'ai' && tr.znak && za.wartosc === 'DE:Krótka zajawka oferty' && za.zrodlo === 'ai' && za.znak, J([tr, za]));
    /* Ręczna poprawka po ✦ — tłumacz przejrzał: bez znaku, źródło jak przed ✦. */
    await p.evaluate(() => { const e = document.getElementById('evk-tlw-de-post_excerpt'); e.value = 'Kurzer Teaser';
      e.dispatchEvent(new Event('input', { bubbles: true })); });
    const za2 = await stanPola('de', 'post_excerpt');
    t.check('ręczna poprawka po ✦ zdejmuje znak i źródło „ai”', !za2.znak && za2.zrodlo !== 'ai', J(za2));
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#publish')]);
    const s5 = sonda('stan');
    const m5 = s5.wpisy.P1.meta;
    console.log('      zapis DE: ' + J(Object.fromEntries(Object.entries(m5).filter(([k]) => k.includes('_de_')))) + ' | mapa: ' + J(s5.mapa));
    t.check('zapis „Zaktualizuj”: tytuł i treść DE ze znacznikiem „ai-” (skrót polskiego tekstu), poprawiona zajawka bez',
      m5._evk_tl_de__post_title === 'DE:Nasza nowa oferta AI' && m5._evk_tl_de__post_title__zrodlo === 'ai-' + s5.skroty.P1_tytul
      && m5._evk_tl_de__post_content__zrodlo === 'ai-' + s5.skroty.P1_tresc && m5._evk_tl_de__post_excerpt === 'Kurzer Teaser'
      && !/^ai-/.test(m5._evk_tl_de__post_excerpt__zrodlo || 'ai-'), J(m5));
    t.check('zapis: człon DE z tytułu w mapie adresów', (s5.mapa || []).some((w) => w.pl === 'nasza-nowa-oferta-ai' && w.de === 'denasza-nowa-oferta-ai'), J(s5.mapa));
    await p.click('.evk-tlw-przelacznik .evk-tl-jezyk[data-lang="de"]');
    const t3 = await stanPola('de', 'post_title');
    await p.fill(POLE('de', 'post_title') + ' .evk-tlw-wejscie', 'Unser neues KI-Angebot');
    const t4 = await stanPola('de', 'post_title');
    t.check('po przeładowaniu: znak przy tytule DE; ręczna zmiana go zdejmuje', t3.znak && !t4.znak, J([t3, t4]));

    t.section('edycja wpisu: „Z tytułu” przy adresie (Chromium)');
    await p.goto(serwer.baza + '/wp-admin/post.php?post=' + W.P5 + '&action=edit', { waitUntil: 'load' });
    await p.click('.evk-tlw-przelacznik .evk-tl-jezyk[data-lang="en"]');
    await p.click('.evk-tlw-adres[data-lang="en"] .evk-tlw-z-tytulu');
    await p.waitForFunction(() => (document.querySelector('.evk-tlw-adres[data-lang="en"] .evk-tlw-adres-stan') || {}).textContent, null, { timeout: 10000 }).catch(() => {});
    const a5en = await adres('en');
    console.log('      P5 EN: ' + J(a5en));
    t.check('„Z tytułu” bez AI: człon z tytułu EN; konflikt z polskim adresem innej strony — powód zamiast „zapisz wpis”',
      a5en.czlon === 'price-list-ai' && /„price-list-ai” to polski adres: .*Lista cen/.test(a5en.stan), J(a5en));
    const nazwaZ = await p.getAttribute('.evk-tlw-adres[data-lang="en"] .evk-tlw-z-tytulu', 'aria-label');
    t.check('„Z tytułu”: nazwa z językiem', nazwaZ === 'Adres EN z tytułu EN', nazwaZ);
    t.check('✦ wołane tylko przy „✦”, nie przy „Z tytułu”', zadaniaAi.length === 3, J(zadaniaAi.length));

    sonda('ai-klucz', 'off');
    await p.goto(serwer.baza + '/wp-admin/post.php?post=' + W.P1 + '&action=edit', { waitUntil: 'load' });
    const bezKlucza = await p.evaluate(() => [document.querySelectorAll('.evk-tlw-ai, .evk-tlw-ai-stan').length,
      document.querySelectorAll('.evk-tlw-z-tytulu').length, typeof window.evkTlwAi]);
    sonda('ai-klucz', 'on');
    t.check('bez klucza API: bez ✦ i danych AI, „Z tytułu” zostaje', J(bezKlucza) === J([0, 2, 'undefined']), J(bezKlucza));
    await p.goto(serwer.baza + '/wp-admin/term.php?taxonomy=category&tag_ID=1', { waitUntil: 'load' });
    const term = await p.evaluate(() => [document.querySelectorAll('.evk-tlw-pole').length > 0, document.querySelectorAll('.evk-tlw-ai').length]);
    /* Od 1.270.0 ✦ jest też w edycji termu (nazwa i opis, EN i DE) — szczegóły w tl-ai-termy. */
    t.check('edycja kategorii: pola języków i ✦ przy nazwie i opisie (EN, DE)', J(term) === J([true, 4]), J(term));
    await p.unroute('**/wp-admin/admin-ajax.php');

    t.section('Tłumaczenia → AI: pola wyboru tekstów wpisów (Chromium)');
    await p.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=ai', { waitUntil: 'load' });
    /* Układ (1.270.0): krótkie pola w jednym wierszu, na telefonie jedno pod drugim. */
    const kolumny = () => p.evaluate(() => {
      const top = (sel) => { const e = document.querySelector(sel); return e ? Math.round(e.getBoundingClientRect().top) : null; };
      const g = (lista) => lista.map(top);
      return { ustawienia: g(['#tl-ai-dostawca', '#tl-ai-klucz', '#tl-ai-model']), wskazowki: g(['#tl-ai-wsk-en', '#tl-ai-wsk-de']),
        przebieg: g(['#tl-ai-tryb-tytul', '#tl-ai-jezyki-tytul', 'label[for="tl-ai-przebieg-dostawca"]']),
        dodatki: g(['.tl-ai-wpisy legend', '.tl-ai-pola-fields legend']), szer: Math.round(document.getElementById('tl-ai-klucz').getBoundingClientRect().width) };
    });
    const jeden = (a) => a.every((x) => x !== null && x === a[0]);
    const rosnie = (a) => a.every((x, i) => x !== null && (i === 0 || x > a[i - 1]));
    const k1280 = await kolumny();
    await p.setViewportSize({ width: 360, height: 800 });
    const k360 = await kolumny();
    await p.setViewportSize({ width: 1280, height: 900 });
    console.log('      kolumny 1280: ' + J(k1280) + '\n      telefon 360: ' + J(k360));
    t.check('1280 px: Dostawca, Klucz API i Model w jednym wierszu, wskazówki EN i DE obok siebie, klucz szerszy niż 200 px',
      jeden(k1280.ustawienia) && jeden(k1280.wskazowki) && k1280.szer > 200, J(k1280));
    t.check('1280 px: „Co tłumaczyć”, „Języki” i dostawca przebiegu w jednym wierszu; pola wyboru wpisów obok', jeden(k1280.przebieg)
      && k1280.dodatki[0] !== null && (k1280.dodatki[1] === null || k1280.dodatki[1] === k1280.dodatki[0]), J(k1280));
    t.check('360 px: wszystko jedno pod drugim', rosnie(k360.ustawienia) && rosnie(k360.wskazowki) && rosnie(k360.przebieg), J(k360));
    const pw = await p.evaluate(() => Array.from(document.querySelectorAll('.tl-ai-wpisy input')).map((c) => [c.value === 'on' ? c.id : c.value, c.checked,
      (c.closest('label') || {}).textContent.trim()]));
    t.check('cztery pola wyboru, wszystkie odznaczone: Tytuł, Treść, Zajawka, Adres z tytułu', J(pw) === J([['post_title', false, 'Tytuł'],
      ['post_content', false, 'Treść (edytor WordPressa)'], ['post_excerpt', false, 'Zajawka'], ['tl-ai-adres', false, 'Adres z tytułu']]), J(pw));
    await p.check('.tl-ai-wpis-pole[value="post_title"]');
    await p.click('.tl-ai-lista');
    await p.waitForFunction(() => /Części stron z brakami|Nie ma/.test((document.querySelector('.tl-ai-stan') || {}).textContent || ''), null, { timeout: 20000 }).catch(() => {});
    const wiersze = await p.evaluate(() => Array.from(document.querySelectorAll('.tl-ai-jednostki tbody tr')).map((r) => [r.children[1].textContent, r.children[2].textContent]));
    t.check('„Tytuł” zaznaczony: w liście „Teksty wpisu” wpisów testu', ['Strona Bricks AI', 'Kontakt zmieniony AI', 'Lista cen', 'Cennik AI']
      .every((x) => wiersze.some((w) => w[0] === x && w[1] === 'Teksty wpisu')), J(wiersze));
    /* Zaznacz / odznacz wszystkie (1.269.0): pole w nagłówku tabeli, stan częściowy. */
    const wszystkie = () => p.evaluate(() => { const g = document.querySelector('.tl-ai-jednostki thead .tl-ai-wszystkie');
      const r = Array.from(document.querySelectorAll('.tl-ai-jednostki tbody .tl-ai-wybor'));
      return g ? [g.checked, g.indeterminate, r.filter((c) => c.checked).length, r.length, g.getAttribute('aria-label')] : null; });
    const g0 = await wszystkie();
    await p.click('.tl-ai-wszystkie');
    const g1 = await wszystkie();
    await p.click('.tl-ai-wszystkie');
    const g2 = await wszystkie();
    await p.click('.tl-ai-jednostki tbody tr:first-child .tl-ai-wybor');
    const g3 = await wszystkie();
    await p.click('.tl-ai-jednostki tbody tr:first-child .tl-ai-wybor');
    const g4 = await wszystkie();
    console.log('      zaznacz wszystkie: ' + J([g0, g1, g2, g3, g4]));
    const n = g0 ? g0[3] : 0;
    t.check('„Zaznacz wszystkie” w nagłówku tabeli, z nazwą; na starcie zaznaczone jak wiersze', !!g0 && n > 1 && J(g0) === J([true, false, n, n, 'Zaznacz wszystkie']), J(g0));
    t.check('klik odznacza wszystkie wiersze, drugi klik zaznacza', J(g1) === J([false, false, 0, n, 'Zaznacz wszystkie']) && J(g2) === J(g0), J([g1, g2]));
    t.check('odznaczony jeden wiersz: stan częściowy; zaznaczony z powrotem — pełny', J(g3) === J([false, true, n - 1, n, 'Zaznacz wszystkie']) && J(g4) === J(g0), J([g3, g4]));
    await p.check('#tl-ai-adres');
    t.check('zmiana pola wyboru czyści listę', (await p.evaluate(() => document.querySelectorAll('.tl-ai-jednostki tbody tr').length)) === 0);
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
