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
  sonda('modul');
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
    t.check('widok EN: ✦ „Tłumacz puste” (nazwa „Przetłumacz puste pola (AI)”) w każdej grupie, ✦ „Przetłumacz” przy każdym polu z nazwą pola i języka',
      grupy.length === 2 && grupy.every((b) => b.t === 'Tłumacz puste' && b.n === 'Przetłumacz puste pola (AI)' && b.svg) && przyPolu.length === 9
      && przyPolu.every((b) => b.t === 'Przetłumacz' && b.svg && /^Przetłumacz \(AI\) — .+ — (etykieta )?EN$/.test(b.n)), J(pe));
    t.check('cele dotyku co najmniej 24 px wysokości', pe.every((b) => b.h >= 24), J(pe.map((b) => b.h)));

    /* Układ pola (1.77.0): etykieta i przyciski w jednym wierszu, w wąskiej
       kolumnie (jak boczna, 280 px) przyciski pod etykietą; przełącznik
       PL | EN z „Tłumacz puste” w jednym wierszu także w wąskiej kolumnie. */
    const uklad = () => p.evaluate((b) => {
      const r = (e) => e.getBoundingClientRect();
      /* Najszersze pole EN z ✦ — pola w kolumnach grupy (np. pół szerokości) mają swój układ. */
      const pole = Array.from(document.querySelectorAll(b + ' .evk-tl-pole[data-lang="en"]')).filter((x) => x.querySelector('.evk-tl-ai'))
        .sort((x, y) => r(y).width - r(x).width)[0];
      const et = r(pole.querySelector('.evk-tl-etykieta')), kop = r(pole.querySelector('.evk-tl-kopiuj')), ai = r(pole.querySelector('.evk-tl-ai'));
      const we = r(pole.querySelector('.evk-tl-wejscie'));
      const przel = Array.from(document.querySelector(b + ' .evk-tl-przelacznik').querySelectorAll('.evk-tl-jezyk, .evk-tl-ai-grupa'))
        .filter((x) => x.offsetParent !== null).map((x) => Math.round(r(x).top));
      return { obok: Math.abs((kop.top + kop.bottom) / 2 - (et.top + et.bottom) / 2) < 6 && kop.left > et.right, pod: kop.top >= et.bottom - 1,
        przedPolem: ai.bottom <= we.top, aiObokKopiuj: Math.abs(ai.top - kop.top) < 2, wiersze: [...new Set(przel)].length,
        szer: [Math.round(r(pole).width), Math.round(r(pole.querySelector('.evk-tl-naglowek')).width), Math.round(r(pole.querySelector('.evk-tl-narzedzia')).width)] };
    }, box);
    const szer = await p.evaluate((b) => { const x = document.querySelector(b + ' .evk-tl-pole[data-lang="en"]');
      const w = (e) => Math.round(e.getBoundingClientRect().width);
      return { pole: w(x), kop: w(x.querySelector('.evk-tl-kopiuj')), ai: w(x.querySelector('.evk-tl-ai')),
        przel: Array.from(document.querySelectorAll(b + ' .evk-tl-przelacznik > *')).filter((e) => e.offsetParent !== null).map(w),
        pasek: w(document.querySelector(b + ' .evk-tl-przelacznik')) }; }, box);
    console.log('      szerokości: ' + J(szer));
    const u1 = await uklad();
    /* Kolumna boczna edycji wpisu (jak „Dane klienta” u zgłaszającego): metaboks przeniesiony do #side-sortables.
       Strona testu ma dwa języki obce; u zgłaszającego jeden — przycisk DE schowany na czas pomiaru przełącznika. */
    await p.evaluate((b) => { const x = document.querySelector(b); x.__evkMiejsce = [x.parentNode, x.nextSibling];
      document.getElementById('side-sortables').appendChild(x); }, box);
    const u2 = await uklad();
    const kol = await p.evaluate((b) => Math.round(document.querySelector(b).getBoundingClientRect().width), box);
    await p.evaluate((b) => { document.querySelector(b + ' .evk-tl-przelacznik .evk-tl-jezyk[data-lang="de"]').style.display = 'none'; }, box);
    const u3 = await uklad();
    await p.evaluate((b) => { const x = document.querySelector(b); x.querySelector('.evk-tl-przelacznik .evk-tl-jezyk[data-lang="de"]').style.display = '';
      x.__evkMiejsce[0].insertBefore(x, x.__evkMiejsce[1]); }, box);
    console.log('      układ szeroko: ' + J(u1) + ' | kolumna boczna (' + kol + ' px): ' + J(u2) + ' | bez DE: ' + J(u3.wiersze));
    t.check('szeroki metaboks: przyciski w wierszu etykiety, nad oryginałem i polem', u1.obok && !u1.pod && u1.przedPolem && u1.aiObokKopiuj, J(u1));
    t.check('kolumna boczna: przyciski pod etykietą, razem w jednym wierszu', !u2.obok && u2.pod && u2.przedPolem && u2.aiObokKopiuj, J(u2));
    t.check('przełącznik z „Tłumacz puste” w jednym wierszu: szeroko, a w kolumnie bocznej przy jednym języku obcym', u1.wiersze === 1 && u3.wiersze === 1,
      J([u1.wiersze, u2.wiersze, u3.wiersze]));

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

    /* Klawiatura: ✦ stoi w nagłówku pola, przed nim (1.77.0) — Shift+Tab z pola na ✦, Enter tłumaczy. */
    await p.focus('[name="evk_single[lista][0][evk_tl_de__tekst]"]');
    for (let i = 0; i < 6; i++) {
      await p.keyboard.press('Shift+Tab');
      if (await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('evk-tl-ai'))) break;
    }
    const naAi = await p.evaluate(() => document.activeElement && document.activeElement.getAttribute('aria-label'));
    t.check('z klawiatury: Shift+Tab z pola dochodzi do ✦ tego pola (nagłówek pola)', naAi === 'Przetłumacz (AI) — Tekst — DE', naAi);

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

    // ── Strona ustawień (1.268.0, Fields 1.76.0) ─────────────────────────
    t.section('strona ustawień: ✦ przy polu i dla grupy, także w repeaterze');
    const dsA = sonda('dane-strona', 'admin');
    const dsC = sonda('dane-strona', 'czytelnik');
    t.check('dane dla strony ustawień: administrator (jej uprawnienie) — tak; bez uprawnienia strony — null',
      !!dsA.dane && /^[0-9a-f]{10}$/.test(dsA.dane.nonce) && J(dsA.strona) === J({ slug: 'pola-ai-ustawienia', nazwa: 'Ustawienia AI' })
      && dsC.dane === null && dsC.strona === null, J([dsA, dsC]));
    const cialoS = (pola) => cialo(Object.assign({ post_id: '0', strona: 'pola-ai-ustawienia' }, pola));
    const sC = sonda('ajax-pola', 'czytelnik', plik(cialoS({})));
    const sA = sonda('ajax-pola', 'admin', plik(cialoS({ kontekst: J([{ el: 'evk_fields', opis: 'Slogan', pole: '', poz: 0, pl: 'Najlepsza oferta', tl: '' }]) })));
    t.check('AJAX ze stroną: bez jej uprawnienia — 403, bez pytania AI; administrator — tłumaczenie z nazwą strony w zapytaniu',
      sC.odp && sC.odp.success === false && /strony ustawień/.test(sC.odp.data) && sC.zadania === 0 && sA.odp && sA.odp.success === true
      && (sA.odp.data.tlumaczenia || {}).k1 === 'DE:Najlepsza oferta' && /^Page: Ustawienia AI\n/.test(sA.wiadomosc), J([sC.odp, sA.odp, sA.wiadomosc.slice(0, 80)]));
    sonda('ai-klucz', 'on');
    const adresS = serwer.baza + '/wp-admin/admin.php?page=pola-ai-ustawienia';
    await p.goto(adresS, { waitUntil: 'load' });
    const przedS = zadania.length;
    await p.click('.evk-tl-grupa .evk-tl-jezyk[data-lang="en"]');
    await p.waitForTimeout(300);
    const us = await p.evaluate(() => ({ strona: (window.evkRepTlAi || {}).strona, post: (window.evkRepTlAi || {}).post,
      pola: Array.from(document.querySelectorAll('.evk-tl-ai')).filter((b) => b.offsetParent !== null).map((b) => b.getAttribute('aria-label')),
      grupa: Array.from(document.querySelectorAll('.evk-tl-ai-grupa')).filter((b) => b.offsetParent !== null).length }));
    t.check('ekran ustawień: dane ze stroną, ✦ przy slogan i etykiecie w repeaterze, ✦ dla grupy', us.strona === 'pola-ai-ustawienia' && us.post === 0
      && J(us.pola) === J(['Przetłumacz (AI) — Slogan — EN', 'Przetłumacz (AI) — Etykieta — EN']) && us.grupa === 1, J(us));
    await p.click('.evk-tl-ai-grupa');
    await p.waitForFunction(() => /^EN: /.test((document.querySelector('.evk-tl-ai-grupa-stan') || {}).textContent || ''), null, { timeout: 30000 }).catch(() => {});
    const zs = zadania[przedS] || {};
    const stanS = await p.evaluate(() => document.querySelector('.evk-tl-ai-grupa-stan').textContent);
    t.check('grupa EN: żądanie ze stroną (bez wpisu), oba pola wpisane, „zapisz ustawienia”', zs.strona === 'pola-ai-ustawienia' && zs.post_id === '0'
      && stanS === 'EN: wpisane 2. Sprawdź i zapisz ustawienia.', J([zs.strona, zs.post_id, stanS]));
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('.evk-settings-form [type="submit"]')]);
    const op = sonda('opcje').opcje || {};
    const wiersz = (op.przyciski || [])[0] || {};
    t.check('zapis strony ustawień: slogan i etykieta w wierszu repeatera ze znacznikiem „ai-”', op.evk_tl_en__slogan === 'EN:Najlepsza oferta'
      && /^ai-/.test(op.evk_tl_en__slogan__zrodlo || '') && wiersz.evk_tl_en__etykieta === 'EN:Zadzwoń teraz' && /^ai-/.test(wiersz.evk_tl_en__etykieta__zrodlo || '')
      && op.slogan === 'Najlepsza oferta', J(op));

    // ── Strony ustawień w hurcie (1.272.0, Fields 1.78.0) ───────────────
    t.section('strony ustawień w hurcie: API Fields, wiersz tabeli, krok z prawem strony, „Do sprawdzenia”');
    const usOa = sonda('opcje-api');
    t.check('Fields: obiekt „opcje”, strona z dwiema grupami (druga na zakładce 2), teksty grupy pojedynczej i repeatera; grupa spoza stron — pusto, bez zapisu',
      J(usOa.obiekty) === J(['post', 'term', 'opcje']) && J(usOa.strony) === J({ nazwa: 'Ustawienia AI', prawo: 'manage_options',
        grupy: { ai_opcje: { nazwa: 'Opcje AI', zakladka: 0 }, ai_opcje_lista: { nazwa: 'Lista opcji', zakladka: 1 } } })
      && J((usOa.teksty || []).map((x) => [x.klucz, x.pl, x.tl.en])) === J([['slogan|', 'Najlepsza oferta', 'EN:Najlepsza oferta'], ['przyciski|0.etykieta', 'Zadzwoń teraz', 'EN:Zadzwoń teraz']])
      && J((usOa.lista || []).map((x) => [x.klucz, x.pl])) === J([['ai_opcje_lista|0.pozycja', 'Pierwsza pozycja'], ['ai_opcje_lista|1.pozycja', 'Druga pozycja']])
      && J(usOa.spoza) === '[]' && usOa.wpisz_spoza === false, J(usOa));
    const usJoA = sonda('jednostki-opcje', 'admin');
    const usJoC = sonda('jednostki-opcje', 'czytelnik');
    const usJoL = (usJoA.jednostki || []).map((j) => [j.opcje, j.tytul, j.adres.replace(/^.*\/wp-admin\//, ''), j.braki, j.grupa, j.post_id]);
    console.log('      jednostki: ' + J(usJoL));
    t.check('wiersz „Ustawienia AI” (część Pola Fields); pozycja na grupę „Strona › Grupa” z adresem zakładki; bez prawa strony — bez wiersza',
      J(usJoA.wiersz) === J({ nazwa: 'Ustawienia AI', rodzaj: 'opcje', czesci: ['fields'] }) && J(usJoL) === J([
        ['ai_opcje', 'Ustawienia AI › Opcje AI', 'admin.php?page=pola-ai-ustawienia', { de: 2 }, 'Ustawienia AI', 0],
        ['ai_opcje_lista', 'Ustawienia AI › Lista opcji', 'admin.php?page=pola-ai-ustawienia&tab=1', { en: 2, de: 2 }, 'Ustawienia AI', 0]])
      && usJoC.wiersz === null && J(usJoC.jednostki) === '[]', J([usJoA, usJoC]));
    const usKoC = sonda('krok-opcje', 'czytelnik', 'de');
    t.check('krok bez prawa strony ustawień: 403 dla obu grup, bez pytania AI', Object.values(usKoC.odp || {}).every((o) => o && o.success === false
      && o.data === 'Brak uprawnień do tej strony ustawień.') && usKoC.zadania === 0, J(usKoC));
    const usKoA = sonda('krok-opcje', 'admin', 'de');
    const usOl = usKoA.lista || [];
    t.check('krok DE: grupa pojedyncza i repeater — tłumaczenia w opcji ze znacznikiem „ai-”, oryginały bez zmian; nazwa strony w zapytaniu',
      (usKoA.opcje || {}).evk_tl_de__slogan === 'DE:Najlepsza oferta' && /^ai-/.test((usKoA.opcje || {}).evk_tl_de__slogan__zrodlo || '')
      && ((usKoA.opcje || {}).przyciski || [{}])[0].evk_tl_de__etykieta === 'DE:Zadzwoń teraz' && (usKoA.opcje || {}).evk_tl_en__slogan === 'EN:Najlepsza oferta'
      && usOl.length === 2 && usOl[0].pozycja === 'Pierwsza pozycja' && usOl[0].evk_tl_de__pozycja === 'DE:Pierwsza pozycja' && /^ai-/.test(usOl[1].evk_tl_de__pozycja__zrodlo || '')
      && usKoA.zadania === 2 && /^Page: Ustawienia AI › Lista opcji\n/.test(usKoA.wiadomosc || ''), J(usKoA));
    /* Formularz strony ustawień zapisuje opcję w całości — tłumaczenia z hurtu muszą przez niego przejść. */
    await p.goto(adresS, { waitUntil: 'load' });
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('.evk-settings-form [type="submit"]')]);
    await p.goto(adresS + '&tab=1', { waitUntil: 'load' });
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('.evk-settings-form [type="submit"]')]);
    const usPo = sonda('opcje');
    t.check('zapis obu zakładek formularzem: tłumaczenia DE z hurtu zostają, ze znacznikiem', (usPo.opcje || {}).evk_tl_de__slogan === 'DE:Najlepsza oferta'
      && /^ai-/.test((usPo.opcje || {}).evk_tl_de__slogan__zrodlo || '') && ((usPo.lista || [])[1] || {}).evk_tl_de__pozycja === 'DE:Druga pozycja'
      && /^ai-/.test(((usPo.lista || [])[1] || {}).evk_tl_de__pozycja__zrodlo || ''), J(usPo));
    const usLo = sonda('lista-opcje');
    /* Tylko strona testu — pola.test ma też strony ustawień innych zestawów. */
    const nasze = (l) => (l.wiersze || []).filter((w) => /^Ustawienia AI › /.test(w.tytul));
    const usLoW = nasze(usLo).map((w) => [w.klucz, w.tytul, w.ai]);
    console.log('      do sprawdzenia: ' + J(usLoW));
    t.check('„Do sprawdzenia”: każde pole AI strony ustawień, tytuł „Strona › Grupa”, odnośnik do zakładki', usLoW.length === 6
      && usLoW.some((w) => J(w) === J(['ai_opcje_lista#ai_opcje_lista|1.pozycja|de', 'Ustawienia AI › Lista opcji', true]))
      && usLoW.some((w) => J(w) === J(['ai_opcje#slogan||en', 'Ustawienia AI › Opcje AI', true]))
      && /admin\.php\?page=pola-ai-ustawienia&(amp;|#038;)tab=1">Ustawienia AI › Lista opcji<\/a>/.test(usLo.html || ''), J([usLoW, (usLo.html || '').length]));
    const usSo = sonda('ajax-sprawdzone-opcje', 'ai_opcje_lista#ai_opcje_lista|1.pozycja|de', 'czytelnik');
    const usSa = sonda('ajax-sprawdzone-opcje', 'ai_opcje_lista#ai_opcje_lista|1.pozycja|de', 'admin');
    const usLo2 = sonda('lista-opcje');
    t.check('„Sprawdzone”: bez prawa strony — 403; z prawem — znacznik zdjęty, tekst zostaje, wiersz znika', usSo.odp && usSo.odp.success === false
      && usSo.odp.data === 'Brak uprawnień do tej strony ustawień.' && usSa.odp && usSa.odp.success === true
      && ((usSa.lista || [])[1] || {}).evk_tl_de__pozycja === 'DE:Druga pozycja' && !/^ai-/.test(((usSa.lista || [])[1] || {}).evk_tl_de__pozycja__zrodlo || 'ai-')
      && nasze(usLo2).length === 5, J([usSo.odp, usSa.odp, (usSa.lista || [])[1], nasze(usLo2).length]));

    // ── Kategorie (1.270.0, Fields 1.77.0) ───────────────────────────────
    t.section('kategoria: pola Fields termu — API, hurt, „Do sprawdzenia”, ✦ w edycji termu');
    const tt = sonda('termy');
    const pt = miejsce(tt.teksty, 'podpis_kat|');
    t.check('Fields: API zna termy (obiekty, taksonomie), tekst pola kategorii z pustymi tłumaczeniami', J(tt.obiekty) === J(['post', 'term', 'opcje'])
      && J(tt.taksonomie) === J(['category']) && pt.pl === 'Podpis kategorii AI' && J(pt.tl) === J({ en: '', de: '' }), J(tt));
    const jt = (sonda('jednostki-term').jednostki || []).map((j) => [j.tytul, j.czesc, j.braki, /term\.php\?taxonomy=category/.test(j.adres)]);
    t.check('hurt z polem „Pola Evoke FIELDS”: kategoria jako „Pola Evoke FIELDS”, odnośnik do edycji termu',
      J(jt) === J([['Kategoria pól AI (Category)', 'Pola Evoke FIELDS', { en: 1, de: 1 }, true]]), J(jt));
    const ktr = sonda('krok-term', 'en');
    const pk = miejsce(ktr.teksty, 'podpis_kat|');
    t.check('krok: tłumaczenie w meta termu ze znacznikiem AI; kontekst z nazwą kategorii', (ktr.kroki || [])[0].zapisane === 1 && pk.tl.en === 'EN:Podpis kategorii AI'
      && pk.ai.en === true && /^Page: Kategoria pól AI \(Category\)/.test(ktr.wiadomosc || '') && (ktr.wiadomosc || '').includes('[Category · Nazwa] "Kategoria pól AI"'),
      J([ktr.kroki, pk, (ktr.wiadomosc || '').slice(0, 160)]));
    const lt = sonda('lista-term');
    t.check('„Do sprawdzenia”: wiersz pola kategorii z nazwą termu i odnośnikiem do jego edycji', J((lt.wiersze || []).map((w) => [w.klucz, w.ai, w.tytul]))
      === J([['podpis_kat||en', true, 'Kategoria pól AI']]) && (lt.html || '').includes('data-meta="evk_fields_term"')
      && new RegExp('term\\.php\\?taxonomy=category&(amp;|#038;)tag_ID=' + tt.term).test(lt.html || ''), J(lt.wiersze));
    const sk = sonda('ajax-sprawdzone-term', 'podpis_kat||en');
    t.check('„Sprawdzone” (AJAX) dla pola termu zdejmuje znacznik', sk.odp && sk.odp.success === true && miejsce(sk.teksty, 'podpis_kat|').ai.en === false, J(sk.odp));
    const dtA = sonda('dane-term', 'admin');
    const dtC = sonda('dane-term', 'czytelnik');
    t.check('dane ✦ dla termu: administrator — z identyfikatorem termu; bez prawa edycji termów — null', !!dtA.dane && dtA.dane.term === tt.term
      && dtA.dane.post === 0 && dtC.dane === null, J([dtA.dane, dtC.dane]));
    await p.goto(serwer.baza + '/wp-admin/term.php?taxonomy=category&tag_ID=' + tt.term, { waitUntil: 'load' });
    const przedT = zadania.length;
    await p.click('.evk-tl-grupa .evk-tl-jezyk[data-lang="de"]');
    await p.waitForTimeout(300);
    const ut = await p.evaluate(() => ({ term: (window.evkRepTlAi || {}).term,
      pola: Array.from(document.querySelectorAll('.evk-tl-grupa .evk-tl-ai')).filter((b) => b.offsetParent !== null).map((b) => b.getAttribute('aria-label')) }));
    /* Na tej stronie są też grupy kategorii z innych testów Fields (fields-panel) — liczy się pole tej grupy. */
    t.check('edycja kategorii: dane z termem, ✦ przy polu Fields', ut.term === tt.term && ut.pola.includes('Przetłumacz (AI) — Podpis kategorii — DE'), J(ut));
    const poleT = '.evk-tl-pole[data-lang="de"]:has([name$="[evk_tl_de__podpis_kat]"])';
    await p.click(poleT + ' .evk-tl-ai');
    await p.waitForFunction((s) => /Wpisane|Brak|odmówił/.test((document.querySelector(s + ' .evk-tl-ai-stan') || {}).textContent || ''),
      poleT, { timeout: 20000 }).catch(() => {});
    const zt = zadania[przedT] || {};
    const wt = await p.evaluate((s) => { const x = document.querySelector(s);
      return { wartosc: x.querySelector('.evk-tl-wejscie').value, stan: x.querySelector('.evk-tl-ai-stan').textContent }; }, poleT);
    t.check('✦ w kategorii: żądanie z term_id (bez wpisu), wynik w polu, „zapisz term (Aktualizuj)”', zt.term_id === String(tt.term) && zt.post_id === '0'
      && wt.wartosc === 'DE:Podpis kategorii AI' && /zapisz term \(Aktualizuj\)\.$/.test(wt.stan), J([zt.term_id, zt.post_id, wt]));
    await Promise.all([p.waitForNavigation({ waitUntil: 'load' }), p.click('#edittag .edit-tag-actions input[type="submit"]')]);
    const pz = miejsce(sonda('termy').teksty, 'podpis_kat|');
    t.check('zapis kategorii: tłumaczenie DE ze znacznikiem AI', pz.tl.de === 'DE:Podpis kategorii AI' && pz.ai.de === true, J(pz));

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
