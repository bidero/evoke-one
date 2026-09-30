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
    t.check('wiersze AI: tytuł, treść i zajawka wpisu, tytuł strony Bricks; ręczne tłumaczenia bez wierszy',
      J((l1.wiersze || []).map((w) => [w.post_id, w.klucz, w.ai])) === J([[W.P1, 'post_title|en', true], [W.P1, 'post_content|en', true],
        [W.P1, 'post_excerpt|en', true], [W.P2, 'post_title|en', true]]), J(l1.wiersze));
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

    t.section('Tłumaczenia → AI: pola wyboru tekstów wpisów (Chromium)');
    await p.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=ai', { waitUntil: 'load' });
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
    await p.check('#tl-ai-adres');
    t.check('zmiana pola wyboru czyści listę', (await p.evaluate(() => document.querySelectorAll('.tl-ai-jednostki tbody tr').length)) === 0);
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
