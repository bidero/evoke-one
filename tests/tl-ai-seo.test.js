/**
 * SEO z AI (1.271.0): tytuł, opis i słowa kluczowe SEO w wersjach językowych.
 * Hurt (kolumna „SEO” tabeli zakresu, część `evk_seo`), ✦ w metaboksie SEO
 * wpisu i w zakładce SEO, lista „Do sprawdzenia”.
 *
 * Polski tekst — ten, który działa na stronie: Bricks przed zakładką SEO.
 * Pole ze znacznikiem `{tl_…}` tłumaczy się samo — AI go pomija. Zapis ze
 * źródłem `ai-{skrót}`: „AI — do sprawdzenia” przy polu i w liście.
 *
 * Dostawca AI — atrapa (tests/php/_ai-atrapa.php). Sonda: tests/php/tl-ai-seo.php.
 * Środowisko: tools/testowy-wp.sh.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('tl-ai-seo.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
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
    const S = u.strony || {};
    t.check('sonda: moduł, strony S1–S3 z SEO, klucz AI, klasyczny edytor', m.gotowe === true && u.gotowe === true, J([m, u.brak || S]));
    if (u.gotowe !== true) return;

    // ── Lista hurtu ──────────────────────────────────────────────────────
    t.section('lista hurtu: kolumna „SEO”');
    const j = sonda('jednostki');
    const lj = (j.jednostki || []).map((x) => [x.tytul, x.czesc, x.braki, x.meta_key]);
    console.log('      wiersz Stron: ' + J(j.wiersze) + '\n      jednostki: ' + J(lj));
    t.check('wiersz „Pages” w tabeli zakresu ma część „seo”', ((j.wiersze || {}).czesci || []).includes('seo'), J(j.wiersze));
    t.check('SEO: S1 — trzy pola na język; S2 — tytuł z Bricksa; S3 — sam opis (tytuł z {tl_…} pominięty)', J(lj) === J([
      ['Usługi SEO AI', 'SEO', { en: 3, de: 3 }, 'evk_seo'], ['Bricks SEO AI', 'SEO', { en: 1, de: 1 }, 'evk_seo'], ['Znacznik SEO AI', 'SEO', { en: 1, de: 1 }, 'evk_seo']]), J(lj));

    // ── Kroki ────────────────────────────────────────────────────────────
    t.section('kroki: tytuł, opis, słowa kluczowe');
    const k1 = sonda('krok', 'S1', 'en');
    const k2 = sonda('krok', 'S2', 'en');
    const k3 = sonda('krok', 'S3', 'en');
    const s1 = sonda('stan');
    const p1 = (s1.strony || {}).S1 || {};
    const p2 = (s1.strony || {}).S2 || {};
    const p3 = (s1.strony || {}).S3 || {};
    console.log('      S1: ' + J(p1) + '\n      S2: ' + J(p2) + '\n      S3: ' + J(p3));
    t.check('S1: trzy pola EN w jednym zapytaniu, źródło ze znacznikiem „ai-” (skrót polskiego tekstu)', k1.zadania === 1
      && p1._evk_tl_en__seo_title === 'EN:Usługi firmy SEO' && p1._evk_tl_en__seo_title__zrodlo === 'ai-' + s1.skroty.S1_title
      && p1._evk_tl_en__seo_desc__zrodlo === 'ai-' + s1.skroty.S1_desc && p1._evk_tl_en__seo_keywords === 'EN:usługi, firma', J([k1.zadania, p1]));
    t.check('kontekst: tytuł wpisu i pola SEO z opisem', (k1.wiadomosc || '').includes('1. [Wpis · Tytuł] "Usługi SEO AI"')
      && (k1.wiadomosc || '').includes('[SEO · Opis SEO] "Opis usług firmy"'), (k1.wiadomosc || '').slice(0, 300));
    t.check('S2: tłumaczy się tytuł z Bricksa (ma pierwszeństwo), nie ten z zakładki', p2._evk_tl_en__seo_title === 'EN:Tytuł z Bricksa'
      && p2._evk_tl_en__seo_title__zrodlo === 'ai-' + s1.skroty.S2_title && !(k2.wiadomosc || '').includes('Tytuł z zakładki'), J([p2, (k2.wiadomosc || '').slice(0, 200)]));
    t.check('S3: tytuł ze znacznikiem {tl_…} pominięty, opis przetłumaczony', p3._evk_tl_en__seo_title === undefined && p3._evk_tl_en__seo_desc === 'EN:Opis strony ze znacznikiem'
      && !(k3.wiadomosc || '').includes('tl_seo_ai_test'), J(p3));

    // ── Do sprawdzenia ───────────────────────────────────────────────────
    t.section('lista „Do sprawdzenia”: pola SEO');
    const l1 = sonda('lista');
    const w1 = (l1.wiersze || []).map((w) => [w.post_id, w.klucz, w.ai]);
    console.log('      wiersze: ' + J(w1));
    t.check('wiersze AI: najnowsze strony najpierw, każde pole osobno', J(w1) === J([[S.S3, 'seo_desc|en', true], [S.S2, 'seo_title|en', true],
      [S.S1, 'seo_title|en', true], [S.S1, 'seo_desc|en', true], [S.S1, 'seo_keywords|en', true]]), J(w1));
    t.check('HTML: „SEO: Tytuł SEO”, odnośnik do edycji wpisu (metaboks SEO), „Sprawdzone” z częścią evk_seo', (l1.html || '').includes('<td>SEO: Tytuł SEO</td>')
      && new RegExp('post\\.php\\?post=' + S.S1 + '&(amp;|#038;)action=edit').test(l1.html || '') && (l1.html || '').includes('data-meta="evk_seo" data-klucz="seo_title|en"'),
      (l1.html || '').slice(0, 300));
    const sp = sonda('ajax-sprawdzone', 'S1', 'seo_title|en');
    sonda('pl', 'S1', 'desc', 'Nowy opis usług');
    const l2 = sonda('lista');
    t.check('„Sprawdzone” (AJAX) zdejmuje wiersz; zmiana polskiego opisu — wiersz „zmienił się oryginał”', sp.odp && sp.odp.success === true
      && !(l2.wiersze || []).some((w) => w.post_id === S.S1 && w.klucz === 'seo_title|en')
      && (l2.wiersze || []).some((w) => w.post_id === S.S1 && w.klucz === 'seo_desc|en' && w.ai === false), J([sp.odp, (l2.wiersze || []).map((w) => [w.post_id, w.klucz, w.ai])]));
    sonda('pl', 'S1', 'desc', 'Opis usług firmy');

    const bn = sonda('mb-bez-nonce', 'S1');
    t.check('zapis metaboksu bez nonce nic nie zmienia (tytuł PL i EN jak były)', bn.tytul === 'Usługi firmy SEO' && bn.en === 'EN:Usługi firmy SEO', J(bn));

    // ── Przeglądarka: metaboks ───────────────────────────────────────────
    t.section('metaboks SEO we wpisie: ✦ przy wersji językowej (Chromium)');
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    p.on('pageerror', (e) => { if (!/\/wp-admin\/(js|load-scripts)/.test(String(e.stack || ''))) bledy.push(e.message); });
    await serwerWp.zaloguj(p, serwer.baza);
    /* Atrapa AI działa w procesie sondy — żądanie ✦ idzie przez nią. */
    const plikAjax = path.join(os.tmpdir(), 'evk-t-tl-ai-seo-ajax.txt');
    const zadaniaAi = [];
    await p.route('**/wp-admin/admin-ajax.php', async (route) => {
      const q = new URLSearchParams(route.request().postData() || '');
      if (q.get('action') !== 'evk_tl_ai_pola') return route.continue();
      q.set('nonce', 'auto');
      fs.writeFileSync(plikAjax, q.toString());
      const r = sonda('ajax-pola', plikAjax);
      zadaniaAi.push([q.get('post_id'), q.get('lang'), r.zadania, (r.wiadomosc || '').slice(0, 200)]);
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(r.odp === undefined ? r : r.odp) });
    });
    await p.goto(serwer.baza + '/wp-admin/post.php?post=' + S.S1 + '&action=edit', { waitUntil: 'load' });
    const mb = await p.evaluate(() => { const x = document.getElementById('evk_seo_mb');
      return x ? { pl: ['title', 'desc', 'keywords'].map((k) => (document.getElementById('evk-seo-mb-pl-' + k) || {}).value),
        jezyki: Array.from(x.querySelectorAll('.evk-seo-mb-przelacznik .evk-tl-jezyk')).map((b) => b.getAttribute('data-lang')) } : null; });
    t.check('metaboks „SEO”: polskie pola z zakładki, przełącznik PL | EN | DE', !!mb && J(mb.pl) === J(['Usługi firmy SEO', 'Opis usług firmy', 'usługi, firma'])
      && J(mb.jezyki) === J(['pl', 'en', 'de']), J(mb));
    await p.click('.evk-seo-mb-przelacznik .evk-tl-jezyk[data-lang="de"]');
    const NARZ = (l, k) => '.evk-seo-mb-wersja[data-lang="' + l + '"] .evk-seo-ai-narzedzia[data-pole="' + k + '"]';
    const przyciski = await p.evaluate(() => Array.from(document.querySelectorAll('.evk-seo-mb-wersja[data-lang="de"] .evk-seo-ai')).filter((b) => b.offsetParent !== null)
      .map((b) => b.getAttribute('aria-label')));
    t.check('DE: ✦ przy tytule, opisie i słowach (widoczne tylko w widoku DE)', J(przyciski) === J(['Przetłumacz (AI) — Tytuł SEO DE',
      'Przetłumacz (AI) — Opis SEO DE', 'Przetłumacz (AI) — Słowa kluczowe DE']), J(przyciski));
    const stanPola = (l, k) => p.evaluate((sel) => { const n = document.querySelector(sel);
      const pole = n.closest('.evk-seo-mb-wersja').querySelector('.evk-seo-pole[data-pole="' + n.getAttribute('data-pole') + '"]');
      return { wartosc: pole.value, zrodlo: n.querySelector('.evk-seo-zrodlo').value, znak: getComputedStyle(n.querySelector('.evk-seo-ai-znak')).display !== 'none',
        stan: n.querySelector('.evk-seo-ai-stan').textContent }; }, NARZ(l, k));
    await p.click(NARZ('de', 'title') + ' .evk-seo-ai');
    await p.waitForFunction((s) => /Wpisane|Brak|odmówił/.test(document.querySelector(s + ' .evk-seo-ai-stan').textContent), NARZ('de', 'title'), { timeout: 20000 }).catch(() => {});
    const t1 = await stanPola('de', 'title');
    console.log('      tytuł DE: ' + J(t1) + ' | AI: ' + J(zadaniaAi));
    t.check('✦ tytułu: tłumaczenie w polu DE, źródło „ai”, znak, komunikat z modelem', t1.wartosc === 'DE:Usługi firmy SEO' && t1.zrodlo === 'ai' && t1.znak
      && /^Wpisane \(AI · .+\) — sprawdź i zapisz\.$/.test(t1.stan), J(t1));
    t.check('✦: żądanie z identyfikatorem wpisu, kontekst z tytułem wpisu i polskimi polami SEO', zadaniaAi.length === 1 && zadaniaAi[0][0] === String(S.S1)
      && zadaniaAi[0][1] === 'de' && /\[Wpis · Tytuł\] "Usługi SEO AI"/.test(zadaniaAi[0][3]), J(zadaniaAi));
    await p.fill('#evk-seo-mb-de-keywords', 'Dienstleistungen');
    const kw = await stanPola('de', 'keywords');
    t.check('ręcznie wpisane słowa kluczowe: bez źródła „ai” i bez znaku', kw.zrodlo === '' && !kw.znak, J(kw));
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#publish')]);
    const s2 = sonda('stan');
    const q1 = (s2.strony || {}).S1 || {};
    console.log('      po zapisie S1: ' + J(q1));
    t.check('„Zaktualizuj”: tytuł DE ze znacznikiem „ai-”, słowa kluczowe DE bez, polskie pola bez zmian', q1._evk_tl_de__seo_title === 'DE:Usługi firmy SEO'
      && q1._evk_tl_de__seo_title__zrodlo === 'ai-' + s2.skroty.S1_title && q1._evk_tl_de__seo_keywords === 'Dienstleistungen'
      && q1._evk_tl_de__seo_keywords__zrodlo === s2.skroty.S1_keywords && q1._evoke_seo_title === 'Usługi firmy SEO' && q1._evk_tl_en__seo_desc === 'EN:Opis usług firmy', J(q1));
    await p.click('.evk-seo-mb-przelacznik .evk-tl-jezyk[data-lang="de"]');
    const t2 = await stanPola('de', 'title');
    t.check('po przeładowaniu: znak przy tytule DE', t2.znak && t2.wartosc === 'DE:Usługi firmy SEO', J(t2));

    // ── Przeglądarka: zakładka SEO ───────────────────────────────────────
    t.section('zakładka SEO: ✦ i „Sprawdzone” w wierszu (Chromium)');
    await p.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-one&tab=strona&sub=meta&seo_pt=page&seo_s=' + encodeURIComponent('SEO AI') + '&seo_lang=en',
      { waitUntil: 'load' });
    const W = (id) => '.evoke-seo-row[data-id="' + id + '"]';
    const NZ = (id, l, k) => W(id) + ' .evk-seo-wersja[data-lang="' + l + '"] .evk-seo-ai-narzedzia[data-pole="' + k + '"]';
    const z0 = await p.evaluate((a) => { const n = document.querySelector(a.s2); const tl = document.querySelector(a.s3);
      return { znak: n && getComputedStyle(n.querySelector('.evk-seo-ai-znak')).display !== 'none', sprawdzone: n && getComputedStyle(n.querySelector('.evk-seo-sprawdzone')).display !== 'none',
        ai_s3_tytul: tl ? !!tl.querySelector('.evk-seo-ai') : null, dane: typeof window.evkSeoAi }; },
      { s2: NZ(S.S2, 'en', 'title'), s3: NZ(S.S3, 'en', 'title') });
    t.check('EN: przy tytule S2 z hurtu znak „AI — do sprawdzenia” i „Sprawdzone”; przy tytule ze znacznikiem {tl_…} bez ✦', z0.znak && z0.sprawdzone
      && z0.ai_s3_tytul === false && z0.dane === 'object', J(z0));
    await p.click(NZ(S.S2, 'en', 'title') + ' .evk-seo-sprawdzone');
    await p.click('.evk-seo-przelacznik .evk-tl-jezyk[data-lang="de"]');
    await p.click(NZ(S.S2, 'de', 'title') + ' .evk-seo-ai');
    await p.waitForFunction((s) => /Wpisane|Brak|odmówił/.test(document.querySelector(s + ' .evk-seo-ai-stan').textContent), NZ(S.S2, 'de', 'title'), { timeout: 20000 }).catch(() => {});
    const zt = await p.evaluate((s) => document.querySelector(s).value, W(S.S2) + ' .evk-seo-wersja[data-lang="de"] .evk-seo-pole[data-pole="title"]');
    t.check('✦ w wierszu S2 (DE): tłumaczenie tytułu z Bricksa', zt === 'DE:Tytuł z Bricksa', zt);
    await p.click(W(S.S2) + ' .evoke-save-seo');
    await p.waitForFunction((s) => /Zapisano|Błąd/.test(document.querySelector(s + ' .evoke-save-seo').textContent), W(S.S2), { timeout: 15000 }).catch(() => {});
    const s3 = sonda('stan');
    const q2 = (s3.strony || {}).S2 || {};
    console.log('      po zapisie S2: ' + J(q2));
    t.check('zapis wiersza: tytuł DE ze znacznikiem „ai-”, EN po „Sprawdzone” bez znacznika (ten sam tekst)', q2._evk_tl_de__seo_title === 'DE:Tytuł z Bricksa'
      && q2._evk_tl_de__seo_title__zrodlo === 'ai-' + s3.skroty.S2_title && q2._evk_tl_en__seo_title === 'EN:Tytuł z Bricksa'
      && q2._evk_tl_en__seo_title__zrodlo === s3.skroty.S2_title, J(q2));
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
