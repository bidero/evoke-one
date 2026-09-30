/**
 * Ponowne tłumaczenie AI (1.262.0) — hurt „od nowa” i jeden tekst.
 *
 * Decyzje zgłaszającego (29.09): od nowa idą wyłącznie niesprawdzone
 * tłumaczenia AI (sprawdzone i wpisane ręcznie nigdy), dostawcę i model
 * wybiera się przy przebiegu (ustawienia zostają), poprzednia wersja zostaje
 * do przywrócenia — także w okienku sprawdzania na stronie (62).
 *
 * Sonda i atrapa jak w tl-ai (tests/php/tl-ai.php, tests/php/_ai-atrapa.php).
 * Atrapa dopisuje do przedrostka model inny niż domyślny („EN[gpt-test-c]:”),
 * więc tekst mówi, który model go dał.
 *
 * Strona A po pierwszym przebiegu (Claude, EN): 7 tłumaczeń AI, przycisk
 * z pamięci tłumaczeń („Contact us”, sprawdzony), nagłówek z EN wpisanym
 * ręcznie, „ZEPSUJ” odrzucony. Potem t1 przyjęty „Sprawdzone”.
 *
 * Środowisko: tools/testowy-wp.sh.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (...a) => {
  const s = phpOutput('tl-ai.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const json = (x) => JSON.stringify(x);
/** Wiadomość zapytania (Claude, Gemini albo OpenAI) i jej sekcja TO TRANSLATE. */
const wiadomosc = (z) => {
  const b = (z || {}).body || {};
  return String(((b.messages || [])[0] || {}).content || ((((b.contents || [])[0] || {}).parts || [])[0] || {}).text || b.input || '');
};
const doTlumaczenia = (m) => {
  const p = m.indexOf('\nTO TRANSLATE');
  if (p < 0) return [];
  try { return JSON.parse(m.slice(m.indexOf('\n', p + 1) + 1)); } catch (e) { return []; }
};
const AI6 = ['a1|items.p1.content|en', 'a1|items.p1.title|en', 'a1|items.p2.content|en', 'a1|items.p2.title|en', 'd2|text|en', 'h1|text|en'];

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;

  let serwer = null;
  let browser = null;
  try {
    sonda('sprzataj');
    const prep = sonda('przygotuj');
    const str = sonda('strony');
    t.check('sonda: języki, mapa pól, ustawienia AI (Claude), strony', prep.gotowe === true && str.tl === true && !!(str.strony || {}).A,
      json([prep, str]));
    if (!prep.gotowe || !str.tl) return;

    t.section('pierwszy przebieg (Claude): model w stanie');
    const k = sonda('krok', 'claude', 'en', 'A');
    const modele = Object.entries(k.stan || {}).filter(([, v]) => v.src === 'ai').map(([x, v]) => x + '=' + v.model).sort();
    t.check('każde z 7 tłumaczeń AI ma w stanie model „claude/claude-opus-5-5”',
      modele.length === 7 && modele.every((x) => x.endsWith('=claude/claude-opus-5-5')), json(modele));
    t.check('przycisk z pamięci tłumaczeń i nagłówek z EN — bez modelu', !('model' in ((k.stan || {})['b1|text|en'] || {}))
      && !('model' in ((k.stan || {})['h2|text|en'] || {})), json([(k.stan || {})['b1|text|en'], (k.stan || {})['h2|text|en']]));
    const sp = sonda('sprawdzone', 'A', 't1|text|en');
    t.check('t1 przyjęty „Sprawdzone”', sp.ok === true, json(sp));

    t.section('lista w trybie ponownym');
    const lp = sonda('lista-ponownie').lista || {};
    t.check('A: EN 7 (6 AI od nowa + odrzucony „ZEPSUJ”), DE 10 (same braki); sprawdzony t1, ręczny nagłówek i przycisk z pamięci poza listą',
      json(lp.A) === json({ braki: { en: 7, de: 10 }, ai: { en: 6 } }), json(lp.A));
    t.check('B: tylko brak DE (sprawdzone EN przycisku nie idzie od nowa)', json(lp.B) === json({ braki: { de: 1 }, ai: {} }), json(lp.B));

    // ── Od nowa: Gemini z własnym modelem, ustawienia bez zmian ────────────
    t.section('od nowa: inny dostawca i model tylko na ten przebieg');
    const g = sonda('ponownie', 'en', 'A', 'gemini', 'gemini-test-b');
    const gz = (g.zadania || [])[0] || {};
    t.check('jedno zapytanie do Gemini, model przebiegu w adresie', (g.zadania || []).length === 1
      && gz.url === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-test-b:generateContent', json((g.zadania || []).map((z) => z.url)));
    t.check('ustawienia bez zmian: dostawca Claude, bez własnych modeli', json(g.ustawienia) === json({ dostawca: 'claude', modele: [] }),
      json(g.ustawienia));
    const gm = wiadomosc(gz);
    const gd = doTlumaczenia(gm);
    t.check('do tłumaczenia: 6 tłumaczeń AI i odrzucony „ZEPSUJ” — bez sprawdzonego t1, ręcznego nagłówka i przycisku',
      gd.length === 7 && !gd.some((w) => /Projektujemy|Już przetłumaczone|Kontakt ai-test/.test(w.text)), json(gd.map((w) => w.text)));
    t.check('kontekst: stare tłumaczenia AI ukryte (inny model tłumaczy po swojemu), sprawdzone i ręczne widoczne',
      !/→ "EN:Nasze usługi"|→ "EN:Pytanie/.test(gm) && gm.includes('"Już przetłumaczone" → "Already translated"')
      && gm.includes('"Kontakt ai-test" → "Contact us"') && gm.includes('→ "<p>EN:Projektujemy'),
      (gm.match(/.*→.*/g) || []).join(' | '));
    const gw = g.wynik || {};
    t.check('wynik: 6 zapisanych z AI, „ZEPSUJ” odrzucony, bez zmian 0', gw.zapisane === 6 && gw.z_ai === 6 && gw.bez_zmian === 0
      && json(gw.odrzucone) === '["z1|text|en"]', json(gw));
    const gp = g.pola || {};
    t.check('pola: nowe tłumaczenia modelu przebiegu; sprawdzony t1, ręczny nagłówek i przycisk z pamięci nietknięte',
      gp['h1|evk_tl_en__text'] === 'EN[gemini-test-b]:Nasze usługi' && gp['a1|items.p2.evk_tl_en__content'] === '<p>EN[gemini-test-b]:Odpowiedź druga.</p>'
      && /^<p>EN:Projektujemy/.test(gp['t1|evk_tl_en__text'] || '') && gp['h2|evk_tl_en__text'] === 'Already translated'
      && gp['b1|evk_tl_en__text'] === 'Contact us', json(gp));
    const h1 = (g.stan || {})['h1|text|en'] || {};
    t.check('stan: dalej AI „Do sprawdzenia”, model przebiegu, poprzednia wersja z jej modelem',
      h1.src === 'ai' && h1.model === 'gemini/gemini-test-b' && json(h1.poprz) === json({ t: 'EN:Nasze usługi', m: 'claude/claude-opus-5-5' }), json(h1));
    t.check('lista „Do sprawdzenia”: te same 6 miejsc', json([...(g.do_sprawdzenia || [])].sort()) === json(AI6), json(g.do_sprawdzenia));

    t.section('ten sam model i ustawienia: ten sam wynik, bez zapytania');
    const g2 = sonda('ponownie', 'en', 'A', 'gemini', 'gemini-test-b', '', 'z1|text|en');
    t.check('bez zapytania, 6 bez zmian w „pominiete” (pętla panelu ich nie powtarza), nic nie zapisane',
      (g2.zadania || []).length === 0 && (g2.wynik || {}).bez_zmian === 6 && (g2.wynik || {}).zapisane === 0
      && json([...((g2.wynik || {}).pominiete || [])].sort()) === json(AI6), json(g2.wynik));
    t.check('poprzednia wersja dalej ta sprzed Gemini', json(((g2.stan || {})['h1|text|en'] || {}).poprz) === json(h1.poprz),
      json((g2.stan || {})['h1|text|en']));

    t.section('powrót do modelu z ustawień: wynik z pamięci, poprzednia to wersja Gemini');
    const c = sonda('ponownie', 'en', 'A', '', '', '', 'z1|text|en');
    t.check('bez zapytania: 6 z pamięci wyników Claude', (c.zadania || []).length === 0 && (c.wynik || {}).zapisane === 6
      && (c.wynik || {}).z_pamieci === 6, json(c.wynik));
    const ch1 = (c.stan || {})['h1|text|en'] || {};
    t.check('pole i stan: tekst Claude, model Claude, poprzednio Gemini', (c.pola || {})['h1|evk_tl_en__text'] === 'EN:Nasze usługi'
      && ch1.src === 'ai' && ch1.model === 'claude/claude-opus-5-5'
      && json(ch1.poprz) === json({ t: 'EN[gemini-test-b]:Nasze usługi', m: 'gemini/gemini-test-b' }), json(ch1));

    sonda('bez-pamieci');
    const c2 = sonda('ponownie', 'en', 'A', '', '', '', 'z1|text|en');
    t.check('bez pamięci wyników: model odpowiada tym samym tekstem — dalej „bez zmian”, nic nie zapisane',
      (c2.zadania || []).length === 1 && (c2.wynik || {}).bez_zmian === 6 && (c2.wynik || {}).z_ai === 0 && (c2.wynik || {}).zapisane === 0,
      json([c2.wynik, (c2.zadania || []).length]));

    t.section('„Sprawdzone” w trakcie zapytania wygrywa');
    const o = sonda('ponownie', 'en', 'A', 'openai', 'gpt-test-c', 'h1|text|en', 'z1|text|en');
    const op = o.pola || {};
    t.check('nagłówek przyjęty w trakcie: tekst Claude zostaje, stan sprawdzony (nie AI)',
      op['h1|evk_tl_en__text'] === 'EN:Nasze usługi' && ((o.stan || {})['h1|text|en'] || {}).src !== 'ai', json([op['h1|evk_tl_en__text'], (o.stan || {})['h1|text|en']]));
    t.check('reszta nadpisana wynikiem OpenAI (5)', (o.wynik || {}).zapisane === 5 && op['d2|evk_tl_en__text'] === 'EN[gpt-test-c]:Autor: {author_name}',
      json([o.wynik, op['d2|evk_tl_en__text']]));

    t.section('jeden tekst („Przetłumacz ponownie” w okienku): bez zapisu');
    const j = sonda('jeden', 'A', 't1|text|en', 'openai', 'gpt-test-d');
    const jm = wiadomosc((j.zadania || [])[0]);
    const jd = doTlumaczenia(jm);
    t.check('sprawdzony tekst też (to decyzja człowieka w okienku): wynik modelu, bez zapisu',
      (j.wynik || {}).ok === true && j.wynik.tekst === '<p>EN[gpt-test-d]:Projektujemy <strong>EN[gpt-test-d]:strony</strong>EN[gpt-test-d]: i sklepy.</p>'
      && j.wynik.model === 'openai/gpt-test-d' && j.wynik.z_pamieci === false && j.bez_zapisu === true, json(j));
    t.check('zapytanie: jeden tekst, kontekst bez jego obecnego tłumaczenia', jd.length === 1 && jd[0].key === 't1'
      && !jm.includes('→ "<p>EN:Projektujemy'), json(jd));
    const j2 = sonda('jeden', 'A', 't1|text|en', 'openai', 'gpt-test-d');
    t.check('drugi raz ten sam model: z pamięci wyników, bez zapytania', (j2.zadania || []).length === 0 && (j2.wynik || {}).z_pamieci === true
      && j2.wynik.tekst === j.wynik.tekst, json(j2.wynik));
    const j3 = sonda('jeden', 'A', 'd1|text|en', 'openai', 'gpt-test-d');
    t.check('sam {post_title}: komunikat, bez zapytania', (j3.wynik || {}).ok === false && /nie tłumaczy/.test(j3.wynik.blad || '')
      && (j3.zadania || []).length === 0, json(j3));

    t.section('AJAX: tryb, dostawca i model przebiegu');
    sonda('ajax-krok', 'admin');
    const a = sonda('ajax-krok', 'admin', '_bricks_page_content_2', 'ponownie', 'openai', 'gpt-test-e/../x');
    t.check('krok od nowa przez AJAX: OpenAI, model oczyszczony z niedozwolonych znaków', ((a.odp || {}).data || {}).zapisane === 2
      && json(a.modele) === json(['https://api.openai.com/v1/responses gpt-test-e..x']), json([a.odp, a.modele]));
    t.check('ustawienia po AJAX-ie bez zmian (dostawca z ustawień, bez modelu przebiegu)', (a.zapisane || {}).dostawca === 'claude'
      && !json((a.zapisane || {}).modele || {}).includes('gpt-test-e'), json(a.zapisane && { d: a.zapisane.dostawca, m: a.zapisane.modele }));

    // ── Panel w przeglądarce ────────────────────────────────────────────────
    t.section('panel: „od nowa” innym modelem, potem ten sam — „bez zmian”');
    sonda('sprzataj');
    sonda('przygotuj');
    sonda('strony');
    sonda('krok', 'gemini', 'en', 'A');   // 7 tłumaczeń AI z Gemini
    const mu = sonda('mu');                // atrapa w serwerze; ustawienia: Gemini, klucze trzech dostawców
    sonda('scenariusz', 'ok');
    t.check('atrapa w serwerze testowym (mu-plugin)', mu.mu === true, json(mu));
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    p.on('pageerror', (x) => bledy.push(x.message));
    await serwerWp.zaloguj(p, serwer.baza);
    await p.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=ai');
    const dostawcy = await p.$$eval('#tl-ai-przebieg-dostawca option', (o) => o.map((x) => x.value + (x.selected ? '*' : '')));
    t.check('dostawca przebiegu: trzej z kluczem, wybrany ten z ustawień', json(dostawcy) === json(['gemini*', 'claude', 'openai']), json(dostawcy));
    await p.check('input[name="tl-ai-tryb"][value="ponownie"]');
    await p.selectOption('#tl-ai-przebieg-dostawca', 'openai');
    t.check('podpowiedź modelu przebiegu: model OpenAI z ustawień', (await p.getAttribute('#tl-ai-przebieg-model', 'placeholder')) === 'gpt-6-astra');
    await p.fill('#tl-ai-przebieg-model', 'gpt-test-f');
    await p.uncheck('.tl-ai-jezyk[value="de"]');
    const pokaz = async (strony) => {
      await p.click('.tl-ai-lista');
      /* Przycisk jest wyłączony na czas liczenia — dopiero po nim lista jest nowa. */
      await p.waitForFunction(() => !document.querySelector('.tl-ai-lista').disabled && document.querySelector('.tl-ai-wybor'),
        null, { timeout: 15000 }).catch(() => {});
      await p.$$eval('.tl-ai-wybor', (w, wz) => w.forEach((c) => { c.checked = new RegExp('Strona AI [' + wz + '] ').test(c.getAttribute('aria-label')); }), strony);
      return p.$$eval('.tl-ai-wybor', (w) => w.filter((c) => c.checked).map((c) => c.closest('tr').lastElementChild.textContent));
    };
    const przebieg = async () => {
      await p.click('.tl-ai-start');
      await p.waitForFunction(() => !document.querySelector('.tl-ai-start').disabled && /^(Gotowe\.|Zatrzymane\.)$|limit|klucz/i.test(
        document.querySelector('.tl-ai-stan').textContent), null, { timeout: 30000 }).catch(() => {});
      return p.$$eval('.tl-ai-dziennik li', (l) => l.map((x) => x.textContent));
    };
    const wiersz = await pokaz('AD');
    t.check('lista w trybie „od nowa”: ile z tekstów to tłumaczenia AI (A), same braki (D)',
      json(wiersz) === json(['EN: 8 (w tym AI od nowa: 7), DE: 10', 'EN: 28, DE: 28']), json(wiersz));
    const dz1 = await przebieg();
    t.check('przebieg: na A 7 zapisanych z AI, „ZEPSUJ” odrzucony', dz1[0] === 'Strona AI A (Treść) EN: zapisane 7 (AI: 7, z pamięci: 0), odrzucone: 1, zostało: 0',
      json(dz1));
    /* D w trzech krokach (porcje). Świeże tłumaczenia AI z kroku 1 są w trybie
       „od nowa” znów niesprawdzone — bez pominięcia wracałyby w kroku 2 jako
       „bez zmian: 25”. */
    t.check('na D trzy kroki porcjami, zapisane z kroku 1 nie wracają jako „bez zmian”', json(dz1.slice(1, 4)) === json([
      'Strona AI D (Treść) EN: zapisane 25 (AI: 25, z pamięci: 0), odrzucone: 0, zostało: 3',
      'Strona AI D (Treść) EN: zapisane 2 (AI: 2, z pamięci: 0), odrzucone: 0, zostało: 1',
      'Strona AI D (Treść) EN: zapisane 1 (AI: 1, z pamięci: 0), odrzucone: 0, zostało: 0']), json(dz1));
    const pa = sonda('pola', 'A');
    t.check('pola strony A: tekst modelu przebiegu (OpenAI, gpt-test-f), ręczny nagłówek nietknięty',
      (pa.pola || {})['h1|evk_tl_en__text'] === 'EN[gpt-test-f]:Nasze usługi' && (pa.pola || {})['h2|evk_tl_en__text'] === 'Already translated', json(pa.pola));
    const dz2 = (await pokaz('A'), await przebieg());
    t.check('drugi przebieg tym samym modelem: „bez zmian: 7” i wyjaśnienie w dzienniku',
      dz2[0] === 'Strona AI A (Treść) EN: zapisane 0 (AI: 0, z pamięci: 0), odrzucone: 1, bez zmian: 7, zostało: 0'
      && dz2[dz2.length - 2] === 'Razem: zapisane 0 (AI: 0, z pamięci: 0), odrzucone: 1, bez zmian: 7.'
      && /^Bez zmian: ten sam model/.test(dz2[dz2.length - 1]), json(dz2));
    await p.check('input[name="tl-ai-tryb"][value="puste"]');
    t.check('zmiana trybu czyści listę (liczby zależą od trybu)', (await p.locator('.tl-ai-wybor').count()) === 0
      && await p.locator('.tl-ai-start').isDisabled() && (await p.locator('.tl-ai-stan').textContent()) === 'Zmieniony tryb — pokaż strony jeszcze raz.');
    await p.reload();
    t.check('ustawienia po przebiegach bez zmian: dostawca Gemini, model z ustawień pusty',
      (await p.inputValue('#tl-ai-dostawca')) === 'gemini' && (await p.inputValue('#tl-ai-model')) === '');
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
