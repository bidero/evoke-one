/**
 * Tłumaczenie AI pól Evoke FIELDS (Fields 1.75.0, Evoke ONE 1.267.0) —
 * czwarty testowy WordPress (pola.test), obie wtyczki.
 *
 * Decyzje zgłaszającego (30.09):
 *   - w metaboksie ✦ przy każdym polu i ✦ dla całej grupy (puste pola
 *     widocznego języka); wynik trafia do pola, zapis ręczny;
 *   - hurt w Tłumaczenia → AI tłumaczy pola Fields tylko po zaznaczeniu pola
 *     wyboru (domyślnie odznaczone);
 *   - znacznik „AI — do sprawdzenia” w Fields i pola Fields w liście
 *     „Do sprawdzenia” w Tłumaczeniach.
 *
 * Fields nie zna AI: API tekstów i zapisu (evk_fields_tl_teksty/_wpisz/
 * _sprawdzone) i filtr `evk_fields_tl_ai`. Dostawca — atrapa
 * (tests/php/_ai-atrapa.php, Gemini: „EN:” przed każdym węzłem tekstu).
 * Żądanie z metaboksu przechwytuje test i puszcza przez PRAWDZIWY AJAX
 * w sondzie (tests/php/fields-ai.php ajax-pola), jak tl-ai-builder.
 *
 * Środowisko: tools/testowy-wp.sh (repozytorium Fields obok, ../evoke-fields).
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('fields-ai.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const MODEL = 'gemini/gemini-3.8-flash';
const miejsce = (teksty, k) => (teksty || []).find((t) => t.klucz === k) || { tl: {}, ai: {}, stale: {} };

module.exports = async function (t) {
  t.section('środowisko: pola.test z Evoke ONE i Evoke FIELDS 1.75.0');
  sonda('sprzataj');
  const u = sonda('ustaw');
  t.check('czwarty WordPress z obiema wtyczkami, grupy i wpis testu (tools/testowy-wp.sh)', !u.brak && u.gotowe === true && u.wpis > 0, u.brak || J(u));
  if (u.brak || !u.gotowe) return;

  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'evk-t-fields-ai-'));
  let serwer = null;
  let browser = null;
  try {
    // ── API Fields ───────────────────────────────────────────────────────
    t.section('Fields: teksty pól wpisu i zapis tłumaczenia');
    const t0 = sonda('teksty').teksty || [];
    t.check('teksty: grupa pojedyncza, repeater w grupie, grupa-repeater; bez „Nie tłumacz” i pustych oryginałów',
      J(t0.map((x) => x.klucz)) === J(['ai_wiersze|0.haslo', 'tytul|', 'opis|', 'tresc|', 'przycisk|', 'lista|0.naglowek', 'lista|0.tekst', 'lista|1.naglowek']),
      J(t0.map((x) => x.klucz)));
    t.check('opis miejsca z etykiet (grupa, lista, pozycja, pole); obecne tłumaczenie EN', miejsce(t0, 'lista|0.tekst').opis === 'Lista · pozycja 1 · Tekst'
      && miejsce(t0, 'lista|0.tekst').grupa === 'Pola oferty' && miejsce(t0, 'tytul|').tl.en === 'Our offer' && miejsce(t0, 'lista|1.naglowek').tl.en === 'Second point',
      J([miejsce(t0, 'lista|0.tekst'), miejsce(t0, 'tytul|').tl]));

    const w1 = sonda('wpisz', 'lista|0.naglowek', 'en', 'First point', '1');
    const w2 = sonda('wpisz', 'opis|', 'en', 'Company services', '1');
    const sur = sonda('surowe');
    t.check('zapis w wierszu repeatera i w osobnej mecie; źródło ze znacznikiem „ai-”',
      w1.ok && w2.ok && miejsce(w2.teksty, 'lista|0.naglowek').ai.en === true && miejsce(w2.teksty, 'opis|').ai.en === true
      && sur.meta.lista[0].evk_tl_en__naglowek === 'First point' && sur.meta.lista[0].evk_tl_en__naglowek__zrodlo === 'ai-' + sur.skroty.pierwszy
      && sur.meta.evk_tl_en__opis__zrodlo === 'ai-' + sur.skroty.opis && sur.meta.lista[1].evk_tl_en__naglowek === 'Second point',
      J([sur.meta.lista, sur.meta.evk_tl_en__opis__zrodlo, sur.skroty]));
    const zm = sonda('meta', 'opis', 'Opis zmieniony');
    t.check('zmiana oryginału po tłumaczeniu AI: „Do sprawdzenia” (skrót bez przedrostka „ai-”), znacznik AI zostaje',
      miejsce(zm.teksty, 'opis|').stale.en === true && miejsce(zm.teksty, 'opis|').ai.en === true && miejsce(zm.teksty, 'lista|0.naglowek').stale.en === false,
      J([miejsce(zm.teksty, 'opis|'), miejsce(zm.teksty, 'lista|0.naglowek')]));
    const sp = sonda('sprawdzone', 'opis|', 'en');
    t.check('„Sprawdzone”: bez znacznika AI i bez „Do sprawdzenia”, tekst ten sam', sp.ok && miejsce(sp.teksty, 'opis|').ai.en === false
      && miejsce(sp.teksty, 'opis|').stale.en === false && miejsce(sp.teksty, 'opis|').tl.en === 'Company services', J(miejsce(sp.teksty, 'opis|')));
    const zle = [sonda('wpisz', 'lista|9.naglowek', 'en', 'X', '1'), sonda('wpisz', 'kod|', 'en', 'X', '1'), sonda('wpisz', 'tytul|', 'fr', 'X', '1'),
      sonda('wpisz', 'lista|1.tekst', 'en', 'X', '1')];
    t.check('odmowa: wiersz spoza listy, pole „Nie tłumacz”, język spoza ustawień, pusty oryginał', zle.every((x) => x.ok === false), J(zle.map((x) => x.ok)));

    // ── Hurt ─────────────────────────────────────────────────────────────
    t.section('ONE: hurt AI z polami Fields (pole wyboru)');
    /* Wpis ma też treść Bricksa (kontekst) — ona jest w hurcie zawsze; liczy się część pól. */
    const czescPol = (x) => (x.jednostki || []).filter((j) => j.meta_key === 'evk_fields');
    const j0 = czescPol(sonda('jednostki', '0'));
    const j1 = czescPol(sonda('jednostki', '1'));
    t.check('pole wyboru odznaczone: bez części „Pola Evoke FIELDS”', j0.length === 0, J(j0));
    t.check('zaznaczone: część „Pola Evoke FIELDS” z brakami w językach i adresem edycji wpisu', j1.length === 1 && j1[0].meta_key === 'evk_fields'
      && j1[0].czesc === 'Pola Evoke FIELDS' && J(j1[0].braki) === J({ en: 4, de: 8 }) && /post\.php\?post=\d+&action=edit$/.test(j1[0].adres), J(j1));
    const k = sonda('krok', 'en');
    console.log('      krok EN: ' + J(k.kroki) + ' | zapytania: ' + k.zadania);
    const kt = k.teksty || [];
    t.check('puste pola EN przetłumaczone ze znacznikiem AI (także wiersze i WYSIWYG), jedno zapytanie',
      miejsce(kt, 'ai_wiersze|0.haslo').tl.en === 'EN:Hasło przewodnie' && miejsce(kt, 'tresc|').tl.en === '<p>EN:Treść <strong>EN:główna</strong></p>'
      && miejsce(kt, 'lista|0.tekst').tl.en === 'EN:Tekst pierwszego punktu' && miejsce(kt, 'przycisk|').tl.en === 'EN:Napisz do nas'
      && ['ai_wiersze|0.haslo', 'tresc|', 'lista|0.tekst', 'przycisk|'].every((x) => miejsce(kt, x).ai.en === true) && k.zadania === 1
      && (k.kroki || []).reduce((a, x) => a + x.zapisane, 0) === 4, J(kt.map((x) => [x.klucz, x.tl.en, x.ai.en])));
    t.check('wypełnione nietknięte (tytuł, sprawdzony opis, wiersz 2, wpis AI w wierszu 1)', miejsce(kt, 'tytul|').tl.en === 'Our offer'
      && miejsce(kt, 'opis|').tl.en === 'Company services' && miejsce(kt, 'opis|').ai.en === false && miejsce(kt, 'lista|1.naglowek').tl.en === 'Second point'
      && miejsce(kt, 'lista|0.naglowek').tl.en === 'First point', J(kt.map((x) => [x.klucz, x.tl.en])));
    t.check('kontekst: pola z opisem z etykiet i obecnymi tłumaczeniami, a za nimi treść Bricksa tej strony',
      k.wiadomosc.includes('[Evoke FIELDS · Pola oferty · Tytuł] "Nasza oferta" → "Our offer"') && k.wiadomosc.includes('"Nagłówek strony Bricks"')
      && k.wiadomosc.indexOf('Nagłówek strony Bricks') > k.wiadomosc.indexOf('Lista · pozycja 2'), k.wiadomosc.slice(0, 700));
    t.check('treść Bricksa nietknięta (tylko kontekst)', J(k.bricks) === J([{ id: 'fa1', name: 'heading', parent: 0, settings: { text: 'Nagłówek strony Bricks' } }]),
      J(k.bricks));
    const j2 = czescPol(sonda('jednostki', '1'));
    t.check('po przebiegu: EN bez braków w liście hurtu (DE dalej)', j2.length === 1 && J(j2[0].braki) === J({ de: 8 }), J(j2));

    t.section('ONE: lista „Do sprawdzenia” z polami Fields');
    const l = sonda('lista');
    console.log('      wiersze: ' + J((l.wiersze || []).map((x) => [x.klucz, x.ai])));
    t.check('wiersze pól z tłumaczeniem AI (EN), bez sprawdzonego opisu', J((l.wiersze || []).map((x) => x.klucz).sort()) === J(['ai_wiersze|0.haslo|en',
      'lista|0.naglowek|en', 'lista|0.tekst|en', 'przycisk||en', 'tresc||en']) && l.wiersze.every((x) => x.ai === true), J(l.wiersze));
    t.check('HTML listy: „Evoke FIELDS · Pola oferty”, link do edycji wpisu, bez „Na stronie” dla pól, „Sprawdzone” z częścią evk_fields',
      /Evoke FIELDS · Pola oferty: Treść/.test(l.html) && new RegExp('post\\.php\\?post=' + u.wpis + '&(amp;|#038;)action=edit').test(l.html)
      && l.html.includes('data-meta="evk_fields" data-klucz="tresc||en"'), l.html.slice(0, 400));
    const as = sonda('ajax-sprawdzone', 'tresc||en');
    t.check('„Sprawdzone” z listy (AJAX): znacznik AI zdjęty', as.odp && as.odp.success === true && miejsce(as.teksty, 'tresc|').ai.en === false,
      J([as.odp, miejsce(as.teksty, 'tresc|')]));

    // ── Dane i AJAX przycisków ───────────────────────────────────────────
    t.section('ONE: dane przycisków w metaboksie i AJAX evk_tl_ai_pola');
    const dA = sonda('dane', 'admin');
    const dC = sonda('dane', 'czytelnik');
    t.check('administrator z kluczem: adres AJAX, nonce, wpis, model; klucza API w danych nie ma', !!dA.dane && /admin-ajax\.php$/.test(dA.dane.ajax)
      && /^[0-9a-f]{10}$/.test(dA.dane.nonce) && dA.dane.post === u.wpis && dA.dane.model === MODEL && dA.z_kluczem === false, J(dA));
    t.check('dostęp do Tłumaczeń bez prawa edycji wpisu: bez przycisków (null)', dC.dane === null, J(dC));
    let nr = 0;
    const plik = (cialo) => { const f = path.join(tmp, 'c' + (++nr) + '.txt'); fs.writeFileSync(f, cialo); return f; };
    const cialo = (pola) => new URLSearchParams(Object.assign({ action: 'evk_tl_ai_pola', nonce: 'auto', post_id: String(u.wpis), lang: 'de',
      kontekst: J([{ el: 'evk_fields', opis: 'Tytuł', pole: '', poz: 0, pl: 'Nasza oferta', tl: '' }]), teksty: J({ k1: { n: 1, bylo: '' } }) }, pola)).toString();
    const aC = sonda('ajax-pola', 'czytelnik', plik(cialo({})));
    const aZ = sonda('ajax-pola', 'admin', plik(cialo({ nonce: 'zly' })));
    const aB = sonda('ajax-pola', 'admin', plik(cialo({ post_id: '0' })));
    const aA = sonda('ajax-pola', 'admin', plik(cialo({})));
    t.check('bez prawa edycji wpisu: odmowa; zły nonce: -1; bez wpisu: odmowa; żadne nie pyta AI', aC.odp && aC.odp.success === false
      && /uprawnień do tej strony/.test(aC.odp.data) && aZ.odp === -1 && aB.odp && aB.odp.success === false && aC.zadania + aZ.zadania + aB.zadania === 0,
      J([aC.odp, aZ.odp, aB.odp]));
    t.check('administrator: tłumaczenie z opisem pola w zapytaniu, bez zapisu w mecie wpisu', aA.odp && aA.odp.success === true
      && (aA.odp.data.tlumaczenia || {}).k1 === 'DE:Nasza oferta' && aA.bez_zapisu === true && aA.wiadomosc.includes('[evk_fields · Tytuł] "Nasza oferta"'),
      J([aA.odp, aA.bez_zapisu, aA.wiadomosc.slice(0, 200)]));

    // ── Metaboks w przeglądarce ──────────────────────────────────────────
    t.section('metaboks: ✦ przy polu i dla grupy (Chromium)');
    serwer = await serwerWp.start(u.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    p.on('pageerror', (e) => { if (!/\/wp-admin\/(js|load-scripts)/.test(String(e.stack || ''))) bledy.push(e.message); });
    const zadania = [];
    await p.route('**/wp-admin/admin-ajax.php', async (route) => {
      const c = route.request().postData() || '';
      if (!/(^|&)action=evk_tl_ai_pola(&|$)/.test(c)) return route.continue();
      zadania.push(Object.fromEntries(new URLSearchParams(c)));
      /* Nonce strony należy do sesji przeglądarki, a sonda to osobny proces bez
         ciasteczek — dostaje świeży dla tego samego konta (nonce sprawdza sekcja PHP wyżej). */
      const r = sonda('ajax-pola', 'admin', plik(c.replace(/(^|&)nonce=[^&]*/, '$1nonce=auto')));
      await route.fulfill({ status: 200, contentType: 'application/json', body: J(r.odp) });
    });
    const dialogi = [];
    let decyzja = 'dismiss';
    p.on('dialog', (d) => { dialogi.push(d.message()); if (decyzja === 'accept') d.accept(); else d.dismiss(); });
    await serwerWp.zaloguj(p, serwer.baza);
    const adres = serwer.baza + '/wp-admin/post.php?post=' + u.wpis + '&action=edit';
    await p.goto(adres, { waitUntil: 'load' });

    const box = '#evk_rep_ai_pola';
    const poleJ = (name, lang) => box + ' .evk-tl-pole[data-lang="' + lang + '"]:has([name="' + name + '"])';
    const ukladPrzyciskow = () => p.evaluate(() => Array.from(document.querySelectorAll('.evk-tl-ai, .evk-tl-ai-grupa')).filter((b) => b.offsetParent !== null)
      .map((b) => ({ k: b.className.replace(/\s+/g, ' '), t: b.textContent.trim(), n: b.getAttribute('aria-label'), w: b.getBoundingClientRect().width,
        h: b.getBoundingClientRect().height, svg: !!b.querySelector('svg') })));
    t.check('widok PL: bez ✦ (pola języków i przycisk grupy ukryte)', (await ukladPrzyciskow()).length === 0, J(await ukladPrzyciskow()));
    await p.click(box + ' .evk-tl-jezyk[data-lang="en"]');
    await p.waitForTimeout(300);
    const pe = await ukladPrzyciskow();
    const grupy = pe.filter((b) => /evk-tl-ai-grupa/.test(b.k));
    const przyPolu = pe.filter((b) => !/evk-tl-ai-grupa/.test(b.k));
    console.log('      przyciski EN: ' + J(pe.slice(0, 3)) + ' … (' + pe.length + ')');
    /* Przy każdym polu z wersjami językowymi (9 — także przy polu z pustym oryginałem: tam serwer odpowie „Brak tekstu”). */
    t.check('widok EN: ✦ „Przetłumacz puste pola (AI)” w każdej grupie, ✦ „Przetłumacz” przy każdym polu z nazwą pola i języka',
      grupy.length === 2 && grupy.every((b) => b.t === 'Przetłumacz puste pola (AI)' && b.svg) && przyPolu.length === 9
      && przyPolu.every((b) => b.t === 'Przetłumacz' && b.svg && /^Przetłumacz \(AI\) — .+ — (etykieta )?EN$/.test(b.n)), J(pe));
    t.check('cele dotyku co najmniej 24 px wysokości', pe.every((b) => b.h >= 24), J(pe.map((b) => b.h)));

    /* Pole wypełnione: pytanie z obecnym tekstem; „Anuluj” — bez żądania. */
    await p.click(poleJ('evk_single[evk_tl_en__tytul]', 'en') + ' .evk-tl-ai');
    await p.waitForTimeout(300);
    t.check('wypełnione pole: pytanie z obecnym tekstem; „Anuluj” — bez żądania', J(dialogi) === J(['Zastąpić obecne tłumaczenie EN?\n\n„Our offer”'])
      && zadania.length === 0, J([dialogi, zadania.length]));

    // Pole sprawdzone ręcznie („Company services”) → nowe tłumaczenie po potwierdzeniu.
    decyzja = 'accept';
    const stanOpisu = poleJ('evk_single[evk_tl_en__opis]', 'en') + ' .evk-tl-ai-stan';
    await p.click(poleJ('evk_single[evk_tl_en__opis]', 'en') + ' .evk-tl-ai');
    await p.waitForFunction((s) => { const e = document.querySelector(s); return e && e.textContent && !/^Tłumaczę/.test(e.textContent); }, stanOpisu, { timeout: 20000 }).catch(() => {});
    const opis = await p.evaluate((s) => {
      const pole = document.querySelector(s);
      return { v: pole.querySelector('.evk-tl-wejscie').value, z: pole.querySelector('.evk-tl-zrodlo').value, ai: pole.hasAttribute('data-ai'),
        znak: getComputedStyle(pole.querySelector('.evk-tl-ai-znak')).display, stan: pole.querySelector('.evk-tl-ai-stan').textContent };
    }, poleJ('evk_single[evk_tl_en__opis]', 'en'));
    const z1 = zadania[0] || {};
    t.check('„OK”: żądanie z obecnym tekstem w `bylo`, kontekst pól EN ekranu; wynik w polu, źródło „ai”, znacznik widoczny',
      zadania.length === 1 && z1.lang === 'en' && z1.post_id === String(u.wpis) && J(JSON.parse(z1.teksty || '{}').k1) === J({ n: 3, bylo: 'Company services' })
      && opis.v === 'EN:Opis zmieniony' && opis.z === 'ai' && opis.ai && opis.znak !== 'none'
      && opis.stan === 'Wpisane (AI · ' + MODEL + ') — sprawdź i zapisz wpis.', J([z1.teksty, JSON.parse(z1.kontekst || '[]').length, opis]));

    /* Grupa DE: wszystkie puste pola DE naraz. */
    await p.click(box + ' .evk-tl-jezyk[data-lang="de"]');
    await p.waitForTimeout(300);
    const przedGrupa = zadania.length;
    await p.click(box + ' .evk-tl-ai-grupa');
    await p.waitForFunction(() => /^DE: /.test((document.querySelector('#evk_rep_ai_pola .evk-tl-ai-grupa-stan') || {}).textContent || ''), null, { timeout: 30000 }).catch(() => {});
    const de = await p.evaluate(() => ({
      stan: document.querySelector('#evk_rep_ai_pola .evk-tl-ai-grupa-stan').textContent,
      pola: Array.from(document.querySelectorAll('#evk_rep_ai_pola .evk-tl-pole[data-lang="de"]')).filter((x) => !x.closest('template'))
        .map((x) => [x.querySelector('.evk-tl-wejscie').name, x.querySelector('.evk-tl-wejscie').value, x.querySelector('.evk-tl-zrodlo').value]),
    }));
    console.log('      grupa DE: ' + J(de));
    t.check('grupa DE: jedno żądanie, puste pola tej grupy wypełnione ze źródłem „ai”, druga grupa nietknięta',
      /* „Nasza oferta” na DE przetłumaczył już AJAX w sekcji PHP wyżej — z pamięci wyników. Ostatnie pole ma pusty oryginał. */
      zadania.length === przedGrupa + 1 && de.stan === 'DE: wpisane 7 (z pamięci 1). Sprawdź i zapisz wpis.' && de.pola.length === 8
      && de.pola.slice(0, 7).every(([, v, z]) => /^(<p>)?DE:/.test(v) && z === 'ai') && J(de.pola[7]) === J(['evk_single[lista][1][evk_tl_de__tekst]', '', ''])
      && (await p.inputValue('[name="ai_wiersze[0][evk_tl_de__haslo]"]')) === '', J(de));

    /* Ręczna poprawka po wpisie AI: bez znacznika. */
    await p.fill('[name="evk_single[evk_tl_de__tytul]"]', 'Unser Angebot');
    const popr = await p.evaluate(() => { const x = document.querySelector('[name="evk_single[evk_tl_de__tytul]"]').closest('.evk-tl-pole');
      return { z: x.querySelector('.evk-tl-zrodlo').value, ai: x.hasAttribute('data-ai') }; });
    t.check('ręczna poprawka wpisu AI przywraca poprzednie źródło i zdejmuje znacznik', popr.z === '' && popr.ai === false, J(popr));

    /* Klawiatura: Tab na ✦ przy polu, Enter tłumaczy. */
    await p.focus('[name="evk_single[lista][0][evk_tl_de__tekst]"]');
    for (let i = 0; i < 6; i++) {
      await p.keyboard.press('Tab');
      if (await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('evk-tl-ai'))) break;
    }
    const naAi = await p.evaluate(() => document.activeElement && document.activeElement.getAttribute('aria-label'));
    t.check('z klawiatury: Tab z pola dochodzi do ✦ tego pola', naAi === 'Przetłumacz (AI) — Tekst — DE', naAi);

    t.section('metaboks: zapis wpisu, znacznik po przeładowaniu, „Sprawdzone”');
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#publish')]);
    const po = sonda('teksty').teksty || [];
    t.check('zapis „Zaktualizuj”: wpisy AI ze znacznikiem, ręczna poprawka bez; EN opisu z AI',
      miejsce(po, 'opis|').tl.en === 'EN:Opis zmieniony' && miejsce(po, 'opis|').ai.en === true && miejsce(po, 'tytul|').tl.de === 'Unser Angebot'
      && miejsce(po, 'tytul|').ai.de === false && miejsce(po, 'lista|0.tekst').ai.de === true && miejsce(po, 'tresc|').ai.de === true,
      J(po.map((x) => [x.klucz, x.tl.de, x.ai.de, x.ai.en])));
    await p.click(box + ' .evk-tl-jezyk[data-lang="en"]');
    const poPrz = await p.evaluate(() => { const x = document.querySelector('[name="evk_single[evk_tl_en__opis]"]').closest('.evk-tl-pole');
      return { ai: x.hasAttribute('data-ai'), znak: getComputedStyle(x.querySelector('.evk-tl-ai-znak')).display, sprawdzone: getComputedStyle(x.querySelector('.evk-tl-sprawdzone')).display }; });
    t.check('po przeładowaniu: „AI — do sprawdzenia” i „Sprawdzone” przy polu', poPrz.ai && poPrz.znak !== 'none' && poPrz.sprawdzone !== 'none', J(poPrz));
    await p.click(poleJ('evk_single[evk_tl_en__opis]', 'en') + ' .evk-tl-sprawdzone');
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#publish')]);
    t.check('„Sprawdzone” + zapis: znacznik zdjęty', miejsce(sonda('teksty').teksty, 'opis|').ai.en === false, J(miejsce(sonda('teksty').teksty, 'opis|')));
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));

    t.section('metaboks: telefon 360 px i bez klucza API');
    const km = await browser.newContext({ viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
    const m = await km.newPage();
    await serwerWp.zaloguj(m, serwer.baza);
    await m.goto(adres, { waitUntil: 'load' });
    await m.click(box + ' .evk-tl-jezyk[data-lang="en"]');
    await m.waitForTimeout(300);
    const tel = await m.evaluate(() => {
      const b = Array.from(document.querySelectorAll('#evk_rep_ai_pola .evk-tl-ai, #evk_rep_ai_pola .evk-tl-ai-grupa')).filter((x) => x.offsetParent !== null)
        .map((x) => x.getBoundingClientRect());
      return { ile: b.length, male: b.filter((r) => r.width < 24 || r.height < 24).length, poza: b.filter((r) => r.left < 0 || r.right > innerWidth).length };
    });
    t.check('360 px: przyciski ✦ co najmniej 24×24, w ekranie', tel.ile > 0 && tel.male === 0 && tel.poza === 0, J(tel));
    await km.close();
    sonda('ai-klucz', 'off');
    await p.goto(adres, { waitUntil: 'load' });
    const bez = await p.evaluate(() => ({ ai: document.querySelectorAll('.evk-tl-ai, .evk-tl-ai-grupa').length, kopiuj: document.querySelectorAll('.evk-tl-kopiuj').length,
      dane: typeof window.evkRepTlAi }));
    t.check('bez klucza API: bez przycisków AI i danych; Fields jak dotąd („Kopiuj z polskiego”)', bez.ai === 0 && bez.kopiuj > 0 && bez.dane === 'undefined', J(bez));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
    fs.rmSync(tmp, { recursive: true, force: true });
  }
};
