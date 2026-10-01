/**
 * Alt obrazów z AI (1.271.0): tłumaczenie polskiego altu i brakujący polski
 * alt z obrazu (AI ogląda obraz) — wiersz „Obrazy” tabeli zakresu (części
 * `evk_alt` i `evk_alt_pl`), ✦ na ekranie obrazu i w oknie mediów, lista
 * „Do sprawdzenia”.
 *
 * Obraz do AI: najmniejszy rozmiar biblioteki z dłuższym bokiem ≥ 512 px
 * (zgłaszający: „mniej niż 768”). Opis PL ze skrótem w `_evk_alt_ai` —
 * „AI — do sprawdzenia”, dopóki alt jest tym tekstem.
 *
 * Dostawca AI — atrapa (tests/php/_ai-atrapa.php: na zapytanie z obrazem
 * „Opis obrazu {plik}”). Sonda: tests/php/tl-ai-alt.php. Środowisko: tools/testowy-wp.sh.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('tl-ai-alt.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
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
    const O = u.obrazy || {};
    t.check('sonda: moduł, trzy obrazy z miniaturami (GD), klucz AI', m.gotowe === true && u.gotowe === true && (u.rozmiary || []).includes('medium_large'), J([m, u.brak || u]));
    if (u.gotowe !== true) return;

    // ── Obraz do AI ──────────────────────────────────────────────────────
    t.section('obraz do AI: rozmiar');
    const ob = sonda('obraz', 'O1').obraz || {};
    console.log('      obraz do AI: ' + J(ob));
    t.check('do AI idzie miniatura z dłuższym bokiem 512–768 px (nie oryginał 1600 px), JPEG poniżej 100 kB',
      Math.max(ob.szer, ob.wys) >= 512 && Math.max(ob.szer, ob.wys) <= 768 && ob.mime === 'image/jpeg' && ob.bajty < 100000 && /-\d+x\d+\.jpg$/.test(ob.plik || ''), J(ob));

    // ── Lista hurtu ──────────────────────────────────────────────────────
    t.section('lista hurtu: wiersz „Obrazy”');
    const j1 = sonda('jednostki', 'alt');
    const j2 = sonda('jednostki', 'alt,alt_pl');
    const lj = (x) => (x.jednostki || []).map((j) => [j.meta_key, j.czesc, j.braki, j.do >= j.post_id]);
    console.log('      alt: ' + J(lj(j1)) + '\n      alt + alt PL: ' + J(lj(j2)));
    t.check('wiersz „Obrazy” w tabeli zakresu: tłumaczenie altu i brakujący alt PL', J((j1.wiersz || {}).czesci) === J(['alt', 'alt_pl']), J(j1.wiersz));
    t.check('sam „Alt”: porcja z obrazami z polskim altem — EN 1 (O2 ma ręczny), DE 2', J(lj(j1)) === J([['evk_alt', 'Alt', { en: 1, de: 2 }, true]]), J(lj(j1)));
    t.check('z „Brakujący alt PL”: najpierw porcja opisów (braki „pl”), w tłumaczeniu liczy się też obraz bez altu',
      J(lj(j2)) === J([['evk_alt_pl', 'Alt PL (AI ogląda obraz)', { pl: 1 }, true], ['evk_alt', 'Alt', { en: 2, de: 3 }, true]]), J(lj(j2)));

    // ── Przeglądarka: ekran obrazu ───────────────────────────────────────
    t.section('ekran obrazu: ✦ przy alcie EN/DE i „Opisz obraz (AI)” (Chromium)');
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    p.on('pageerror', (e) => { if (!/\/wp-admin\/(js|load-scripts)/.test(String(e.stack || ''))) bledy.push(e.message); });
    await serwerWp.zaloguj(p, serwer.baza);
    /* Atrapa AI działa w procesie sondy — oba ✦ (tłumaczenie i opis) idą przez nią. */
    const plikAjax = path.join(os.tmpdir(), 'evk-t-tl-ai-alt-ajax.txt');
    const zadaniaAi = [];
    await p.route('**/wp-admin/admin-ajax.php', async (route) => {
      const q = new URLSearchParams(route.request().postData() || '');
      if (!['evk_tl_ai_pola', 'evk_tl_ai_opisz'].includes(q.get('action'))) return route.continue();
      q.set('nonce', 'auto');
      fs.writeFileSync(plikAjax, q.toString());
      const r = sonda('ajax', plikAjax);
      zadaniaAi.push([q.get('action'), q.get('post_id'), q.get('lang'), r.zadania, r.obrazy]);
      return route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(r.odp === undefined ? r : r.odp) });
    });
    const POLE = (id, l) => '.evk-alt-ai[data-id="' + id + '"][data-lang="' + l + '"]';
    const stanPola = (id, l) => p.evaluate((sel) => { const w = document.querySelector(sel); if (!w) return null;
      const pole = w.querySelector('.evk-alt-pole');
      return { wartosc: pole ? pole.value : null, zrodlo: (w.querySelector('.evk-alt-zrodlo') || {}).value,
        znak: getComputedStyle(w.querySelector('.evk-alt-ai-znak')).display !== 'none', stan: w.querySelector('.evk-alt-ai-stan').textContent }; }, POLE(id, l));
    await p.goto(serwer.baza + '/wp-admin/post.php?post=' + O.O1 + '&action=edit', { waitUntil: 'load' });
    const przyciski = await p.evaluate(() => Array.from(document.querySelectorAll('.evk-alt-tlumacz, .evk-alt-opisz')).map((b) => b.getAttribute('aria-label')));
    const etykieta = await p.evaluate((id) => { const e = document.getElementById('attachments-' + id + '-evk_tl_alt_de');
      const l = e ? document.querySelector('label[for="' + e.id + '"]') : null; return [e && e.name, l && l.textContent.trim()]; }, O.O1);
    t.check('ekran obrazu: ✦ „Opisz obraz (AI)” przy PL, ✦ „Przetłumacz” przy EN i DE; pole DE z dotychczasową nazwą i etykietą',
      J(przyciski) === J(['Opisz obraz (AI) — tekst alternatywny PL', 'Przetłumacz (AI) — tekst alternatywny EN', 'Przetłumacz (AI) — tekst alternatywny DE'])
      && etykieta[0] === 'attachments[' + O.O1 + '][evk_tl_alt_de]' && /Tekst alternatywny DE/.test(etykieta[1] || ''), J([przyciski, etykieta]));
    await p.click(POLE(O.O1, 'de') + ' .evk-alt-tlumacz');
    await p.waitForFunction((s) => /Wpisane|Brak|odmówił/.test(document.querySelector(s + ' .evk-alt-ai-stan').textContent), POLE(O.O1, 'de'), { timeout: 20000 }).catch(() => {});
    const d1 = await stanPola(O.O1, 'de');
    console.log('      alt DE O1: ' + J(d1));
    t.check('✦ przy DE: tłumaczenie polskiego altu, źródło „ai”, znak', d1.wartosc === 'DE:Zespół przy pracy' && d1.zrodlo === 'ai' && d1.znak, J(d1));
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#publish')]);
    const s1 = sonda('stan');
    t.check('„Aktualizuj”: alt DE ze znacznikiem „ai-” (skrót polskiego altu)', s1.obrazy.O1._evk_tl_de__alt === 'DE:Zespół przy pracy'
      && s1.obrazy.O1._evk_tl_de__alt__zrodlo === 'ai-' + s1.skroty.O1, J(s1.obrazy.O1));
    await p.goto(serwer.baza + '/wp-admin/post.php?post=' + O.O3 + '&action=edit', { waitUntil: 'load' });
    const przyciskiO3 = await p.evaluate(() => Array.from(document.querySelectorAll('.evk-alt-tlumacz, .evk-alt-opisz')).map((b) => b.className.split(' ').pop()));
    await p.click(POLE(O.O3, 'en') + ' .evk-alt-tlumacz').catch(() => {});
    const bezPl = (await stanPola(O.O3, 'en') || {}).stan;
    t.check('obraz bez polskiego altu: „Opisz obraz” i „Przetłumacz” (1.272.0 — alt może przyjść z opisu); klik bez altu: „Brak polskiego altu.”',
      J(przyciskiO3) === J(['evk-alt-opisz', 'evk-alt-tlumacz', 'evk-alt-tlumacz']) && bezPl === 'Brak polskiego altu.', J([przyciskiO3, bezPl]));
    await p.click(POLE(O.O3, 'pl') + ' .evk-alt-opisz');
    await p.waitForFunction((s) => /Wpisane|Brak|odmówił|obejrzy/.test(document.querySelector(s + ' .evk-alt-ai-stan').textContent), POLE(O.O3, 'pl'), { timeout: 20000 }).catch(() => {});
    const o3 = await p.evaluate(() => (document.getElementById('attachment_alt') || {}).value);
    const z3 = zadaniaAi[zadaniaAi.length - 1] || [];
    console.log('      opis O3: ' + J(o3) + ' | zapytanie: ' + J(z3));
    t.check('„Opisz obraz”: opis w polu altu PL; zapytanie z jednym obrazem (miniatura ≤ 768 px)', o3 === 'Opis obrazu evk-t-alt-o3-768x480.jpg'
      && z3[0] === 'evk_tl_ai_opisz' && (z3[4] || []).length === 1 && Math.max(z3[4][0][0], z3[4][0][1]) <= 768, J([o3, z3]));
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#publish')]);
    const l1 = sonda('lista');
    const w1 = (l1.wiersze || []).map((w) => [w.post_id, w.meta_key, w.klucz, w.ai]);
    console.log('      do sprawdzenia: ' + J(w1));
    t.check('zapisany opis AI: w „Do sprawdzenia” jako „Alt PL (AI)”, obok alt DE z ✦', w1.some((w) => J(w) === J([O.O3, 'evk_alt_pl', 'alt|pl', true]))
      && w1.some((w) => J(w) === J([O.O1, 'evk_alt', 'alt|de', true])) && (l1.html || '').includes('<td>Obraz: Alt PL (AI)</td>'), J(w1));

    // ── Okno mediów ──────────────────────────────────────────────────────
    t.section('okno mediów: ✦ przy alcie DE zapisuje się samo (Chromium)');
    await p.goto(serwer.baza + '/wp-admin/upload.php?item=' + O.O2, { waitUntil: 'load' });
    /* Okno dociąga dane załącznika i przerysowuje pola — klik dopiero po tym. */
    await p.waitForLoadState('networkidle').catch(() => {});
    await p.waitForSelector(POLE(O.O2, 'de') + ' .evk-alt-tlumacz', { timeout: 15000 }).catch(() => {});
    const zapisOkna = p.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('save-attachment-compat'), { timeout: 20000 }).catch(() => null);
    await p.click(POLE(O.O2, 'de') + ' .evk-alt-tlumacz').catch(() => {});
    await zapisOkna;
    await p.waitForTimeout(300);
    const s2 = sonda('stan');
    console.log('      O2 po oknie: ' + J(s2.obrazy.O2));
    t.check('okno mediów: ✦ wpisuje i WordPress zapisuje pole (źródło „ai-”); ręczny EN nietknięty', s2.obrazy.O2._evk_tl_de__alt === 'DE:Biuro firmy'
      && s2.obrazy.O2._evk_tl_de__alt__zrodlo === 'ai-' + s2.skroty.O2 && s2.obrazy.O2._evk_tl_en__alt === 'Company office', J(s2.obrazy.O2));

    /* „Opisz obraz (AI)” w oknie mediów (1.272.0): nasze pola są w `form.compat-item`,
       pole altu WordPressa obok — opis ma trafić w nie i zapisać się samo. */
    t.section('okno mediów: „Opisz obraz (AI)” wpisuje alt PL i WordPress go zapisuje (Chromium)');
    p.on('dialog', (d) => d.accept());
    const opiszWOknie = async (otworz, opis) => {
      sonda('alt-pl', 'O3', '');
      sonda('usun-meta', 'O3', '_evk_tl_en__alt');
      await otworz();
      await p.waitForLoadState('networkidle').catch(() => {});
      await p.waitForSelector(POLE(O.O3, 'pl') + ' .evk-alt-opisz', { timeout: 15000 }).catch(() => {});
      const zapis = p.waitForResponse((r) => r.url().includes('admin-ajax.php') && /action=save-attachment(&|$)/.test(r.request().postData() || ''), { timeout: 20000 }).catch(() => null);
      await p.click(POLE(O.O3, 'pl') + ' .evk-alt-opisz').catch(() => {});
      await zapis;
      await p.waitForTimeout(300);
      const pole = await p.evaluate(() => { const f = document.querySelector('.media-modal [data-setting="alt"] textarea, .media-modal [data-setting="alt"] input');
        return f ? f.value : null; });
      /* „Przetłumacz” EN w tym samym oknie: polski alt z pola, nie z atrybutu narysowanego przed opisem. */
      await p.waitForSelector(POLE(O.O3, 'en') + ' .evk-alt-tlumacz', { timeout: 10000 }).catch(() => {});
      const zapisEn = p.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('save-attachment-compat'), { timeout: 20000 }).catch(() => null);
      await p.click(POLE(O.O3, 'en') + ' .evk-alt-tlumacz').catch(() => {});
      await zapisEn;
      await p.waitForTimeout(300);
      const s = sonda('stan');
      console.log('      ' + opis + ': pole ' + J(pole) + ' | O3 ' + J(s.obrazy.O3));
      return { pole, o3: s.obrazy.O3 };
    };
    const OPIS3 = 'Opis obrazu evk-t-alt-o3-768x480.jpg';
    const siatka = await opiszWOknie(() => p.goto(serwer.baza + '/wp-admin/upload.php?item=' + O.O3, { waitUntil: 'load' }), 'siatka');
    t.check('siatka (upload.php?item=): opis w polu altu okna, zapisany w bazie ze znacznikiem AI; EN przetłumaczony z tego opisu',
      siatka.pole === OPIS3 && siatka.o3.pl === OPIS3 && siatka.o3.ai !== '' && siatka.o3._evk_tl_en__alt === 'EN:' + OPIS3, J(siatka));
    const boczne = await opiszWOknie(async () => {
      await p.goto(serwer.baza + '/wp-admin/upload.php?mode=grid', { waitUntil: 'load' });
      await p.evaluate(() => { window.evkRamka = wp.media({ frame: 'select', multiple: false }); window.evkRamka.open(); });
      /* Puste konto testowe otwiera okno na „Wgraj pliki” — przejście do biblioteki. */
      await p.click('.media-modal .media-router button:has-text("Media Library"), .media-modal #menu-item-browse').catch(() => {});
      await p.waitForSelector('.media-modal li.attachment[data-id="' + O.O3 + '"]', { timeout: 15000 }).catch(() => {});
      await p.click('.media-modal li.attachment[data-id="' + O.O3 + '"]').catch(() => {});
    }, 'okno wyboru');
    t.check('okno wyboru z paskiem bocznym (wp.media): to samo — opis w polu, zapis, EN z opisu',
      boczne.pole === OPIS3 && boczne.o3.pl === OPIS3 && boczne.o3._evk_tl_en__alt === 'EN:' + OPIS3, J(boczne));
    sonda('usun-meta', 'O3', '_evk_tl_en__alt');
    await p.unroute('**/wp-admin/admin-ajax.php');

    // ── Hurt ─────────────────────────────────────────────────────────────
    t.section('hurt: brakujący alt PL, potem tłumaczenie');
    sonda('alt-pl', 'O3', '');
    const kp = sonda('krok', 'evk_alt_pl', 'pl');
    const ke = sonda('krok', 'evk_alt', 'en');
    const s3 = sonda('stan');
    console.log('      opis: ' + J(kp.kroki) + ' | obrazy: ' + J(kp.obrazy) + '\n      EN: ' + J(ke.kroki) + '\n      stan: ' + J(s3.obrazy));
    t.check('„Brakujący alt PL”: jeden opis z obrazem, alt PL ze znacznikiem `_evk_alt_ai` (skrót opisu)', kp.zadania === 1 && (kp.obrazy || []).length === 1
      && s3.obrazy.O3.pl === 'Opis obrazu evk-t-alt-o3-768x480.jpg' && s3.obrazy.O3.ai !== '' && /Polish/.test(kp.system || ''), J([kp.kroki, s3.obrazy.O3]));
    t.check('„Alt” EN: O1 i nowy alt O3 w jednym zapytaniu, bez obrazów; ręczny EN O2 nietknięty', ke.zadania === 1 && (ke.obrazy || []).length === 0
      && s3.obrazy.O1._evk_tl_en__alt === 'EN:Zespół przy pracy' && s3.obrazy.O3._evk_tl_en__alt === 'EN:Opis obrazu evk-t-alt-o3-768x480.jpg'
      && s3.obrazy.O2._evk_tl_en__alt === 'Company office', J(s3.obrazy));
    const sp = sonda('ajax-sprawdzone', 'O3', 'evk_alt_pl', 'alt|pl');
    sonda('alt-pl', 'O1', 'Zespół przy pracy w biurze');
    const l2 = sonda('lista');
    const w2 = (l2.wiersze || []).map((w) => [w.post_id, w.klucz, w.ai]);
    t.check('„Sprawdzone” zdejmuje znacznik opisu AI; zmiana polskiego altu — alt EN „zmienił się oryginał”', sp.odp && sp.odp.success === true
      && !w2.some((w) => w[0] === O.O3 && w[1] === 'alt|pl') && w2.some((w) => w[0] === O.O1 && w[1] === 'alt|en' && w[2] === false), J([sp.odp, w2]));
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
