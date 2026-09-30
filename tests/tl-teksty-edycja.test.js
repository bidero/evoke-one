/**
 * Edycja tłumaczeń w liście „Teksty w elementach” (1.263.0).
 *
 * Zgłoszone (30.09) przy /wp-admin/…page=evoke-tlumaczenia&pokaz=sprawdz&tab=
 * elementy: „tu by się przydała od razu edycja fraz przetłumaczonych”.
 * Decyzje zgłaszającego: „Edytuj” przy języku otwiera edytor pod wierszem
 * (cały polski tekst, pole tłumaczenia, Zapisz / Sprawdzone / Przetłumacz
 * ponownie / Przywróć); „Zapisz” oznacza jako sprawdzony tylko ten tekst.
 *
 * Sonda i dane jak w tl-sprawdz (tests/php/tl-sprawdz.php): strona A — h1
 * z EN i DE od AI, t1 ze zmienionym polskim tekstem, akordeon z EN od AI,
 * przycisk b1 bez EN; strona B — hb z modelem i poprzednią wersją. Dostawców
 * AI udaje atrapa w serwerze (mu-plugin sondy).
 *
 * Edytor składa JS, więc strażnik nazw kontrolek (admin-etykiety) go nie
 * widzi — nazwy pola, wyboru modelu i przycisków sprawdza ten test.
 *
 * Środowisko: tools/testowy-wp.sh.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('tl-sprawdz.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const json = (x) => JSON.stringify(x);

/** Błędy JS strony (bez skryptów /wp-admin/ z rdzenia — patrz tl-sprawdz). */
function lapBledy(p) {
  const bledy = [];
  p.on('pageerror', (e) => { if (!/\/wp-admin\/js\//.test(String(e.stack || ''))) bledy.push(e.message); });
  return bledy;
}

/** Indeks wiersza listy (data-w) po polskim tekście w kolumnie „Polski”. */
const wiersz = (p, pl) => p.evaluate((x) => {
  const tr = Array.from(document.querySelectorAll('.tl-teksty tbody tr[data-w]'))
    .find((r) => r.children[2] && r.children[2].textContent.trim() === x);
  return tr ? tr.getAttribute('data-w') : null;
}, pl);

/** Komórka języka: tekst bez przycisku „Edytuj”. */
const komorka = (p, w, j) => p.evaluate(([a, b]) => {
  const td = document.querySelector('.tl-teksty td[data-w="' + a + '"][data-j="' + b + '"]');
  if (!td) return null;
  const k = td.cloneNode(true);
  k.querySelectorAll('button').forEach((x) => x.remove());
  k.querySelectorAll('br').forEach((x) => x.replaceWith(' '));   // łamanie wiersza = odstęp
  return k.textContent.replace(/\s+/g, ' ').trim();
}, [w, j]);

async function edytuj(p, w, j) {
  await p.click('.tl-teksty-edytuj[data-w="' + w + '"][data-j="' + j + '"]');
  await p.locator('.tl-teksty-edytor').waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
}

/** Czeka na „Zapisano.” (albo błąd) w edytorze — zwraca napis stanu. */
async function poZapisie(p, napis = 'Zapisano.') {
  await p.waitForFunction((n) => {
    const s = document.querySelector('.tl-teksty-edytor .tl-teksty-stan');
    return s && (s.textContent.indexOf(n) === 0 || s.classList.contains('evo-danger-tx'));
  }, napis, { timeout: 15000 }).catch(() => {});
  return p.evaluate(() => { const s = document.querySelector('.tl-teksty-edytor .tl-teksty-stan'); return s ? s.textContent : ''; });
}

const edytor = (p) => p.evaluate(() => {
  const e = document.querySelector('.tl-teksty-edytor');
  if (!e) return null;
  const t = (s) => { const x = e.querySelector(s); return x ? x.textContent.replace(/\s+/g, ' ').trim() : null; };
  const lab = (id) => { const l = e.querySelector('label[for="' + id + '"]'); return l ? l.textContent.trim() : null; };
  const poprz = e.querySelector('.tl-teksty-poprz');
  return {
    tytul: t('.tl-teksty-tytul'), znak: t('.tl-teksty-znak'), pl: t('.tl-teksty-pl'),
    pole: (e.querySelector('#tl-teksty-pole') || {}).value,
    przyciski: Array.from(e.querySelectorAll('.tl-teksty-akcje button, .tl-teksty-akcje a')).map((x) => x.textContent.trim()),
    naStronie: (e.querySelector('.tl-teksty-akcje a') || {}).href || '',
    etykiety: { pole: lab('tl-teksty-pole'), ai: lab('tl-teksty-ai-dostawca'),
      model: (e.querySelector('#tl-teksty-ai-model') || { getAttribute: () => null }).getAttribute('aria-label') },
    poprz: poprz && poprz.offsetParent !== null ? poprz.querySelector('p').textContent.replace(/\s+/g, ' ').trim() : null,
    uwagi: Array.from(e.querySelectorAll('.tl-teksty-uwaga')).map((x) => x.textContent),
    przywrocNazwa: (e.querySelector('.tl-teksty-przywroc') || { getAttribute: () => null }).getAttribute('aria-label'),
    fokus: document.activeElement ? document.activeElement.className : '',
  };
});

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress, dane jak w tl-sprawdz');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;

  let serwer = null;
  let browser = null;
  try {
    sonda('sprzataj');
    const mod = sonda('modul');
    const prep = sonda('przygotuj');
    const mu = sonda('mu');
    t.check('sonda: moduł, strony, szablony, użytkownicy, atrapy Bricksa i dostawców AI',
      mod.gotowe === true && prep.gotowe === true && mu.mu === true, json([mod, prep.brak || prep.wpisy, mu]));
    if (prep.gotowe !== true || mu.mu !== true) return;

    serwer = await serwerWp.start(wp.wp);
    const b = serwer.baza;
    const LISTA = (pokaz) => b + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=elementy&pokaz=' + pokaz;
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const k = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const p = await k.newPage();
    const bledy = lapBledy(p);
    await serwerWp.zaloguj(p, b);
    await p.goto(LISTA('sprawdz'));

    // ── Lista ──────────────────────────────────────────────────────────────
    t.section('lista „Do sprawdzenia”: przyciski „Edytuj” z nazwami');
    const nazwy = await p.$$eval('.tl-teksty-edytuj', (w) => w.map((x) => x.getAttribute('aria-label')));
    t.check('„Edytuj” przy każdym języku, nazwa z językiem, stroną i polem', nazwy.length >= 6
      && nazwy.every((x) => /^Edytuj (EN|DE): .+, .+ \(.+\)$/.test(x || '')), json(nazwy.slice(0, 4)));
    const wH1 = await wiersz(p, 'Nasze usługi');
    t.check('nagłówek h1: EN i DE „do sprawdzenia”', /do sprawdzenia$/.test(await komorka(p, wH1, 'en') || '')
      && /do sprawdzenia$/.test(await komorka(p, wH1, 'de') || ''), json([wH1, await komorka(p, wH1, 'en'), await komorka(p, wH1, 'de')]));

    // ── Edytor ─────────────────────────────────────────────────────────────
    t.section('edytor pod wierszem: pełny tekst, stan, nazwy kontrolek');
    await edytuj(p, wH1, 'en');
    const e1 = await edytor(p);
    t.check('pod wierszem: tytuł (język, strona, element), znaczek AI, cały polski tekst, pole z tłumaczeniem, bez „Poprzednio”',
      !!e1 && e1.tytul === 'EN · TLS strona A · heading (Tekst) AI — do sprawdzenia' && e1.pl === 'PL Nasze usługi' && e1.pole === 'EN: Our services'
      && e1.poprz === null
      && await p.evaluate((w) => { const n = document.querySelector('tr[data-w="' + w + '"]').nextElementSibling; return !!n && n.classList.contains('tl-teksty-edycja'); }, wH1),
      json(e1));
    t.check('przyciski: Zapisz, Sprawdzone, Przetłumacz ponownie, Następny, Na stronie, Zamknij; fokus w polu',
      !!e1 && json(e1.przyciski) === json(['Zapisz', 'Sprawdzone', 'Przetłumacz ponownie', 'Następny ›', 'Na stronie', 'Zamknij'])
      && e1.fokus === '', json(e1 && [e1.przyciski, e1.fokus]));
    t.check('„Na stronie”: strona w języku, tryb sprawdzania, kotwica elementu', !!e1 && /\/en\//.test(e1.naStronie)
      && e1.naStronie.includes('evk_tl_sprawdz=1') && e1.naStronie.endsWith('#evk-tls=h1'), e1 && e1.naStronie);
    t.check('nazwy: pole „Tłumaczenie EN”, wybór „Model AI”, pole modelu z aria-label', !!e1
      && json(e1.etykiety) === json({ pole: 'Tłumaczenie EN', ai: 'Model AI', model: 'Model (puste — z ustawień)' }), json(e1 && e1.etykiety));

    t.section('„Zapisz”: tylko ten tekst (EN), DE zostaje do sprawdzenia');
    await p.fill('#tl-teksty-pole', 'Our services (list)');
    await p.click('.tl-teksty-zapisz');
    const s1 = await poZapisie(p);
    const en = sonda('stan', 'A', 'h1', 'en');
    const de = sonda('stan', 'A', 'h1', 'de');
    t.check('EN zapisane i sprawdzone', s1 === 'Zapisano.' && (en.pola || {}).text === 'Our services (list)' && (en.sprawdzone || {}).text === 'tak',
      json([s1, en]));
    t.check('DE bez zmian i dalej AI „Do sprawdzenia” (wiersz listy to jeden tekst w jednym języku)',
      (de.pola || {}).text === 'DE: Unsere Leistungen' && (de.sprawdzone || {}).text === 'ai', json(de));
    t.check('komórki: EN — nowy tekst „w elemencie” bez znacznika, DE — dalej „do sprawdzenia”',
      (await komorka(p, wH1, 'en')) === 'Our services (list) w elemencie' && /do sprawdzenia$/.test(await komorka(p, wH1, 'de') || ''),
      json([await komorka(p, wH1, 'en'), await komorka(p, wH1, 'de')]));
    const e2 = await edytor(p);
    t.check('edytor zostaje z „Zapisano.”, znaczek „Przetłumaczone”, fokus na „Następny”', !!e2 && /^EN · .* Przetłumaczone$/.test(e2.tytul)
      && /tl-teksty-nastepny/.test(e2.fokus), json(e2 && [e2.tytul, e2.fokus]));

    t.section('„Następny”, „Sprawdzone”, Esc');
    await p.keyboard.press('Enter');
    const nast = await p.evaluate(() => (window.__evkTlTeksty.otwarte() || {}).i);
    t.check('Enter na „Następny” → następny wiersz listy w tym samym języku', String(nast) === String(+wH1 + 1), json([wH1, nast]));
    const wT1 = await wiersz(p, 'Projektujemy strony i sklepy.');
    await edytuj(p, wT1, 'en');
    t.check('zmieniony oryginał: znaczek „Zmienił się polski tekst”', /Zmienił się polski tekst$/.test(((await edytor(p)) || {}).tytul || ''));
    await p.click('.tl-teksty-sprawdzone');
    await poZapisie(p);
    const t1 = sonda('stan', 'A', 't1');
    t.check('„Sprawdzone”: tłumaczenie bez zmian, tekst sprawdzony', (t1.pola || {}).text === '<p>We design websites.</p>'
      && (t1.sprawdzone || {}).text === 'tak', json(t1));
    await p.keyboard.press('Escape');
    t.check('Esc zamyka edytor i oddaje fokus przyciskowi „Edytuj” tego tekstu', (await p.locator('.tl-teksty-edytor').count()) === 0
      && await p.evaluate((w) => { const a = document.activeElement; return !!a && a.classList.contains('tl-teksty-edytuj') && a.getAttribute('data-w') === w
        && a.getAttribute('data-j') === 'en'; }, wT1));

    t.section('niezapisana zmiana: pytanie przed zmianą wiersza, Ctrl+Enter zapisuje');
    const wA1 = await wiersz(p, 'Pytanie');
    await edytuj(p, wA1, 'en');
    await p.fill('#tl-teksty-pole', 'EN Question (list)');
    const pytania = [];
    p.once('dialog', (d) => { pytania.push(d.message()); d.dismiss().catch(() => {}); });
    await p.click('.tl-teksty-nastepny');
    t.check('„Następny” z niezapisaną zmianą pyta; „Anuluj” zostawia edytor', json(pytania) === json(['Porzucić niezapisaną zmianę tłumaczenia?'])
      && String(await p.evaluate(() => (window.__evkTlTeksty.otwarte() || {}).i)) === String(wA1), json(pytania));
    await p.focus('#tl-teksty-pole');
    await p.keyboard.press('Control+Enter');
    await poZapisie(p);
    const a1 = sonda('stan', 'A', 'a1');
    t.check('Ctrl+Enter zapisuje', (a1.pola || {})['accordions.p1.title'] === 'EN Question (list)', json(a1));
    t.check('„tylko ten tekst” także w obrębie elementu: treść tej samej pozycji dalej AI „Do sprawdzenia”',
      (a1.sprawdzone || {})['accordions.p1.title'] === 'tak' && (a1.sprawdzone || {})['accordions.p1.content'] === 'ai', json(a1.sprawdzone));

    t.section('„Przywróć” (poprzednia wersja z przebiegu AI)');
    const wHb = await wiersz(p, 'Strona B');
    await edytuj(p, wHb, 'en');
    const e3 = await edytor(p);
    t.check('„Poprzednio” z modelem, znaczek AI z modelem, nazwa „Przywróć”', !!e3 && e3.poprz === 'Poprzednio (gpt-6-astra): Old page B'
      && /AI — do sprawdzenia · gemini-3\.8-flash$/.test(e3.tytul) && e3.przywrocNazwa === 'Przywróć poprzednie tłumaczenie EN', json(e3));
    await p.click('.tl-teksty-przywroc');
    const e4 = await edytor(p);
    t.check('„Przywróć” zamienia pole z poprzednim (z modelami)', !!e4 && e4.pole === 'Old page B' && e4.poprz === 'Poprzednio (gemini-3.8-flash): Page B', json(e4));
    await p.click('.tl-teksty-zapisz');
    await poZapisie(p);
    const hb = sonda('stan', 'B', 'hb');
    t.check('zapis: przywrócony tekst, poprzednia wersja w stanie', (hb.pola || {}).text === 'Old page B'
      && json((hb.poprz || {}).text) === json({ t: 'Page B', m: 'gemini/gemini-3.8-flash' }), json(hb));

    t.section('pusty tekst: „Przetłumacz (AI)” wybranym modelem');
    await p.goto(LISTA('wszystko'));
    const wS1 = await wiersz(p, 'Słownikowy tekst');
    await edytuj(p, wS1, 'en');
    const e5 = await edytor(p);
    t.check('tekst ze słownika: pole puste (tłumaczenie należy do słownika), uwaga z tekstem ze słownika', !!e5 && e5.pole === ''
      && /Ze słownika$/.test(e5.tytul) && e5.uwagi.includes('Na stronie ze słownika: „Dictionary text”. Tłumaczenie wpisane tutaj ma pierwszeństwo.'),
      json(e5 && [e5.pole, e5.tytul, e5.uwagi]));
    const wB1 = await wiersz(p, 'Napisz do nas');
    await edytuj(p, wB1, 'en');
    t.check('przy pustym polu przycisk „Przetłumacz (AI)”', ((await edytor(p)) || { przyciski: [] }).przyciski.includes('Przetłumacz (AI)'),
      json(((await edytor(p)) || {}).przyciski));
    await p.selectOption('#tl-teksty-ai-dostawca', 'openai');
    await p.fill('#tl-teksty-ai-model', 'gpt-test-c');
    await p.click('.tl-teksty-ai');
    const s5 = await poZapisie(p, 'Nowe tłumaczenie');
    t.check('wynik wybranego modelu w polu, bez zapisu', s5 === 'Nowe tłumaczenie (gpt-test-c) — sprawdź i zapisz.'
      && ((await edytor(p)) || {}).pole === 'EN[gpt-test-c]:Napisz do nas' && !('text' in (sonda('stan', 'A', 'b1').pola || {})), s5);
    await p.click('.tl-teksty-zapisz');
    await poZapisie(p);
    const b1 = sonda('stan', 'A', 'b1');
    t.check('zapis: nowe pole i w wykazie dopisanych (otwarty builder go nie zgubi)', (b1.pola || {}).text === 'EN[gpt-test-c]:Napisz do nas'
      && (b1.dopisane || []).includes('b1|evk_tl_en__text'), json(b1));
    t.check('bez błędów JS (administrator)', bledy.length === 0, bledy.slice(0, 3).join(' | '));
    await k.close();

    // ── Telefon ────────────────────────────────────────────────────────────
    t.section('telefon 360 px');
    const km = await browser.newContext({ viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
    const m = await km.newPage();
    await serwerWp.zaloguj(m, b);
    await m.goto(LISTA('sprawdz'));
    await m.locator('.tl-teksty-edytuj').first().tap();
    await m.locator('.tl-teksty-edytor').waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
    const tel = await m.evaluate(() => {
      const e = document.querySelector('.tl-teksty-edytor');
      if (!e) return null;
      const r = e.getBoundingClientRect();
      const cele = Array.from(e.querySelectorAll('button, a, select, input, textarea')).filter((x) => x.offsetParent !== null)
        .map((x) => x.getBoundingClientRect());
      return { szer: document.documentElement.scrollWidth, okno: innerWidth, lewo: Math.round(r.left), prawo: Math.round(r.right),
        font: Math.min(...Array.from(e.querySelectorAll('textarea, select, input')).map((x) => parseFloat(getComputedStyle(x).fontSize))),
        male: cele.filter((x) => x.width < 24 || x.height < 24).length };
    });
    t.check('edytor mieści się na ekranie (przyklejony do lewej krawędzi przewijanej tabeli), strona bez przewijania w poziomie',
      !!tel && tel.szer <= tel.okno && tel.lewo >= 0 && tel.prawo <= tel.okno, json(tel));
    t.check('pola co najmniej 16 px, cele dotyku co najmniej 24×24', !!tel && tel.font >= 16 && tel.male === 0, json(tel));
    await km.close();

    // ── Uprawnienia ────────────────────────────────────────────────────────
    t.section('uprawnienia: bez prawa edycji strony — lista tylko do odczytu');
    const kb = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pb = await kb.newPage();
    await serwerWp.zaloguj(pb, b, 'evk-t-tls-bez', 'evk-t-haslo-bez');
    await pb.goto(LISTA('wszystko'));
    t.check('dostęp do Tłumaczeń bez edycji stron: lista jest, bez „Edytuj” i bez danych edytora',
      (await pb.locator('.tl-teksty tbody tr').count()) > 0 && (await pb.locator('.tl-teksty-edytuj').count()) === 0
      && (await pb.locator('#tl-teksty-dane').count()) === 0);
    await kb.close();
    const kt = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pt = await kt.newPage();
    await serwerWp.zaloguj(pt, b, 'evk-t-tls-tlumacz', 'evk-t-haslo-tlumacz');
    await pt.goto(LISTA('wszystko'));
    t.check('tłumacz z prawem edycji stron: „Edytuj” jest', (await pt.locator('.tl-teksty-edytuj').count()) > 0);
    await kt.close();
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
