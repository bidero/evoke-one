/**
 * Tłumaczenie AI tekstów w elementach Bricksa (1.260.0).
 *
 * Decyzje zgłaszającego: dostawca do wyboru (Gemini domyślnie, Claude
 * i OpenAI z płatnym kluczem), elementy hurtem z panelu, AI wypełnia tylko
 * puste pola, a wynik jest „Do sprawdzenia”. Tłumaczenie z kontekstem
 * i powtarzalne.
 *
 * Dostawców udaje atrapa (tests/php/_ai-atrapa.php): filtr pre_http_request
 * na PRAWDZIWYCH adresach API, więc test widzi adres, nagłówki i ciało, które
 * wtyczka naprawdę by wysłała. Tłumaczenie atrapy: przedrostek „EN:”/„DE:”
 * w każdym węźle tekstu; tekst „ZEPSUJ” wraca bez znaczników (strażnik).
 *
 * Strona A (sonda tests/php/tl-ai.php): nagłówek, tekst z <strong>, przycisk
 * „Kontakt ai-test” (sprawdzone EN na B → pamięć tłumaczeń), {post_title},
 * „Autor: {author_name}”, nagłówek z EN, „ZEPSUJ”, akordeon z dwiema
 * pozycjami, „2026”. Strona C: dwa świeże teksty do scenariuszy błędów.
 *
 * Czego stąd nie widać: prawdziwych odpowiedzi dostawców (klucze, sieć) —
 * pierwszy przebieg z kluczem Gemini sprawdza się na testowej. Bricksa też
 * tu nie ma: że builder pokaże pola i „Do sprawdzenia”, mówią testy 52/53.
 *
 * Środowisko: tools/testowy-wp.sh.
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const KLUCZ = 'test-klucz-ai-123';
const sonda = (...a) => {
  const s = phpOutput('tl-ai.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const json = (x) => JSON.stringify(x);
/** Teksty z sekcji TO TRANSLATE wiadomości (jak atrapa). */
const doTlumaczenia = (m) => {
  const p = m.indexOf('\nTO TRANSLATE');
  if (p < 0) return [];
  try { return JSON.parse(m.slice(m.indexOf('\n', p + 1) + 1)); } catch (e) { return []; }
};

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
    t.check('sonda przygotowała języki, mapę pól, ustawienia AI i cztery strony', prep.gotowe === true && str.tl === true
      && !!(str.strony && str.strony.A && str.strony.B && str.strony.C && str.strony.D), json([prep, str]));
    if (!prep.gotowe || !str.tl) return;

    t.section('lista braków: tylko teksty z literami, bez już przetłumaczonych');
    const lista = sonda('lista').lista || {};
    t.check('A: EN 9 (bez {post_title}, „2026” i nagłówka z EN), DE 10; B: DE 1; C: EN 2, DE 2; D: 28',
      json(lista) === json({ A: { en: 9, de: 10 }, B: { de: 1 }, C: { en: 2, de: 2 }, D: { en: 28, de: 28 } }), json(lista));

    // ── Claude, strona A, EN ────────────────────────────────────────────────
    t.section('Claude: zapytanie (adres, nagłówki, ciało)');
    const k = sonda('krok', 'claude', 'en', 'A');
    const z = (k.zadania || [])[0] || { headers: {}, body: {} };
    const b = z.body || {};
    t.check('jedno zapytanie na prawdziwy adres Messages API', (k.zadania || []).length === 1 && z.url === 'https://api.anthropic.com/v1/messages',
      json((k.zadania || []).map((x) => x.url)));
    t.check('nagłówki: x-api-key z ustawień, anthropic-version 2023-06-01',
      z.headers['x-api-key'] === KLUCZ && z.headers['anthropic-version'] === '2023-06-01', json(Object.keys(z.headers)));
    t.check('model domyślny claude-opus-5-5 z zapasowym po stronie serwera (fallbacks + nagłówek beta)',
      b.model === 'claude-opus-5-5' && b.fallbacks === 'default' && z.headers['anthropic-beta'] === 'server-side-fallback-2026-07-01',
      json([b.model, b.fallbacks, z.headers['anthropic-beta']]));
    t.check('odpowiedź w schemacie JSON (output_config.format), effort medium, max_tokens 16000',
      b.output_config && b.output_config.format && b.output_config.format.type === 'json_schema'
      && b.output_config.format.schema.required[0] === 'translations' && b.output_config.effort === 'medium' && b.max_tokens === 16000,
      json(b.output_config && { format: b.output_config.format.type, effort: b.output_config.effort, max: b.max_tokens }));
    t.check('bez temperature i budget_tokens (Opus 5.5 odrzuca je z 400)', !('temperature' in b) && !json(b).includes('budget_tokens'),
      Object.keys(b).join(','));

    t.section('Claude: kontekst, opis, wskazówki i słowniczek w zapytaniu');
    const sys = String(b.system || '');
    const msg = String(((b.messages || [])[0] || {}).content || '');
    t.check('język docelowy po angielsku z kodem: „English (en-US)”', sys.includes('into English (en-US).'), sys.slice(0, 80));
    t.check('opis strony i wskazówki dla EN', sys.includes('Studio projektowe AI-TEST.') && sys.includes('Guidance for English (en-US):\nBritish English AI-TEST'),
      sys.slice(sys.indexOf('About'), sys.indexOf('About') + 160));
    t.check('słowniczek: para dla EN, nazwa „nie tłumacz”, bez komentarza i bez kolumny DE',
      sys.includes('- sklepy → shops') && sys.includes('- Evoke AI-TEST') && !sys.includes('komentarz AI-TEST') && !sys.includes('Läden'),
      sys.slice(sys.indexOf('Never')));
    t.check('kontekst: wszystkie teksty części strony po kolei, z tytułem strony', msg.startsWith('Page: Strona AI A\n\nCONTEXT:\n1. [heading · Tekst] "Nasze usługi"'),
      msg.slice(0, 120));
    t.check('kontekst niesie istniejące tłumaczenie (nagłówek z EN) i to z pamięci tłumaczeń (przycisk)',
      msg.includes('"Już przetłumaczone" → "Already translated"') && msg.includes('"Kontakt ai-test" → "Contact us"'),
      (msg.match(/.*→.*/g) || []).join(' | '));
    const doT = doTlumaczenia(msg);
    t.check('do tłumaczenia: 8 tekstów pod kluczami t1…t8 z numerem w kontekście',
      doT.length === 8 && doT.map((w) => w.key).join(',') === 't1,t2,t3,t4,t5,t6,t7,t8' && doT[0].n === 1 && doT[2].n === 4,
      json(doT.map((w) => w.key + ':' + w.n)));
    t.check('bez: przycisku z pamięci, samego {post_title}, „2026” i nagłówka z EN',
      !doT.some((w) => /Kontakt ai-test|\{post_title\}|^2026$|Już przetłumaczone/.test(w.text)), json(doT.map((w) => w.text)));

    t.section('Claude: zapis tylko w puste pola, znacznik „Do sprawdzenia”');
    const w = k.wynik || {};
    t.check('wynik: 8 zapisanych (7 z AI, 1 z pamięci), odrzucony „ZEPSUJ”, nic nie zostało',
      w.zapisane === 8 && w.z_ai === 7 && w.z_pamieci === 1 && json(w.odrzucone) === '["z1|text|en"]' && w.zostalo === 0 && !w.blad, json(w));
    const pola = k.pola || {};
    t.check('pola: tłumaczenie AI w nagłówku, znaczniki HTML i {author_name} zostały',
      pola['h1|evk_tl_en__text'] === 'EN:Nasze usługi' && pola['t1|evk_tl_en__text'] === '<p>EN:Projektujemy <strong>EN:strony</strong>EN: i sklepy.</p>'
      && pola['d2|evk_tl_en__text'] === 'EN:Autor: {author_name}', json([pola['h1|evk_tl_en__text'], pola['t1|evk_tl_en__text'], pola['d2|evk_tl_en__text']]));
    t.check('pozycje akordeonu (tytuł i treść obu)', pola['a1|items.p1.evk_tl_en__title'] === 'EN:Pytanie pierwsze'
      && pola['a1|items.p2.evk_tl_en__content'] === '<p>EN:Odpowiedź druga.</p>', json(pola));
    t.check('przycisk z pamięci tłumaczeń: sprawdzone „Contact us” z innej strony', pola['b1|evk_tl_en__text'] === 'Contact us', pola['b1|evk_tl_en__text']);
    t.check('wypełnione pole nietknięte, odrzucony tekst i dane dynamiczne bez pola',
      pola['h2|evk_tl_en__text'] === 'Already translated' && !('z1|evk_tl_en__text' in pola) && !('d1|evk_tl_en__text' in pola) && !('n1|evk_tl_en__text' in pola),
      json(Object.keys(pola)));
    const ai = Object.entries(k.stan || {}).filter(([, v]) => v.src === 'ai').map(([x]) => x).sort();
    t.check('stan: źródło „ai” przy 7 tłumaczeniach AI, nie przy przycisku z pamięci ani nagłówku z EN',
      ai.length === 7 && !ai.includes('b1|text|en') && !ai.includes('h2|text|en'), json(ai));
    t.check('lista „Do sprawdzenia”: te same 7 miejsc', json([...(k.do_sprawdzenia || [])].sort()) === json(ai), json(k.do_sprawdzenia));
    t.check('wykaz dopisanych (otwarty builder nie zgubi pól): 8 pól', (k.dopisane || []).length === 8 && k.dopisane.includes('a1|items.p1.evk_tl_en__title'),
      json(k.dopisane));

    t.section('powtarzalność: pamięć wyników i pominięte odrzuty');
    const wy = sonda('wyczysc', 'A', 'en', 'h1');
    t.check('wyczyszczone pole nagłówka (builder otwarty, pole puste)', wy.pola && !('h1|evk_tl_en__text' in wy.pola), json(wy.pola && Object.keys(wy.pola)));
    const k2 = sonda('krok', 'claude', 'en', 'A', 'ok', 'z1|text|en');
    t.check('ten sam tekst przy tych samych ustawieniach: wynik z pamięci, bez zapytania',
      (k2.zadania || []).length === 0 && k2.wynik.zapisane === 1 && k2.wynik.z_pamieci === 1 && (k2.pola || {})['h1|evk_tl_en__text'] === 'EN:Nasze usługi',
      json([k2.wynik, (k2.zadania || []).length]));
    t.check('wynik z pamięci AI dalej „Do sprawdzenia”', ((k2.stan || {})['h1|text|en'] || {}).src === 'ai', json((k2.stan || {})['h1|text|en']));
    const k3 = sonda('krok', 'claude', 'en', 'A');
    const doT3 = doTlumaczenia(String((((k3.zadania || [])[0] || {}).body || { messages: [{}] }).messages[0].content || ''));
    t.check('bez pominięcia odrzucony tekst idzie jeszcze raz — sam (pętla panelu go pomija)',
      (k3.zadania || []).length === 1 && doT3.length === 1 && doT3[0].text === '<p>ZEPSUJ <em>to</em></p>', json(doT3));

    t.section('porcje: najwyżej 25 tekstów i 6000 znaków w zapytaniu (strona D)');
    const porcja = () => {
      const x = sonda('krok', 'gemini', 'en', 'D');
      const zz = (x.zadania || [])[0] || { body: {} };
      const tekst = String(((((zz.body.contents || [])[0] || {}).parts || [])[0] || {}).text || '');
      return { w: x.wynik || {}, n: (x.zadania || []).length, ile: doTlumaczenia(tekst).length };
    };
    const d1 = porcja();
    const d2 = porcja();
    const d3 = porcja();
    t.check('krok 1: 25 tekstów (limit liczby), zostały 3', d1.n === 1 && d1.ile === 25 && d1.w.zapisane === 25 && d1.w.zostalo === 3, json(d1));
    t.check('krok 2: 2 nagłówki — długi tekst nie mieści się w limicie znaków, został 1',
      d2.n === 1 && d2.ile === 2 && d2.w.zapisane === 2 && d2.w.zostalo === 1, json(d2));
    t.check('krok 3: sam długi tekst (ponad limit, ale sam jeden idzie), nic nie zostało',
      d3.n === 1 && d3.ile === 1 && d3.w.zapisane === 1 && d3.w.zostalo === 0, json(d3));

    // ── Gemini i OpenAI ─────────────────────────────────────────────────────
    t.section('Gemini: zapytanie i zapis (DE, strona A)');
    const g = sonda('krok', 'gemini', 'de', 'A');
    const gz = (g.zadania || [])[0] || { headers: {}, body: {} };
    const gb = gz.body || {};
    t.check('adres generateContent z modelem domyślnym gemini-3.8-flash',
      (g.zadania || []).length === 1 && gz.url === 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3.8-flash:generateContent', gz.url);
    t.check('klucz w nagłówku x-goog-api-key (nie w adresie)', gz.headers['x-goog-api-key'] === KLUCZ && !gz.url.includes(KLUCZ), json(gz.headers));
    t.check('systemInstruction, contents, odpowiedź JSON według schematu',
      !!(gb.systemInstruction && gb.contents && gb.generationConfig) && gb.generationConfig.responseMimeType === 'application/json'
      && !!gb.generationConfig.responseJsonSchema && !('temperature' in gb.generationConfig), json(gb.generationConfig && Object.keys(gb.generationConfig)));
    const gs = String(((((gb.systemInstruction || {}).parts || [])[0]) || {}).text || '');
    t.check('DE: „German (de-DE)”, wskazówki DE, kolumna DE słowniczka', gs.includes('into German (de-DE).') && gs.includes('Sie-Form AI-TEST')
      && gs.includes('- sklepy → Läden') && !gs.includes('→ shops'), gs.slice(gs.indexOf('Guidance')));
    t.check('wynik DE: 9 z AI (bez pamięci — przycisk na B nie ma DE), odrzucony „ZEPSUJ”',
      g.wynik && g.wynik.zapisane === 9 && g.wynik.z_ai === 9 && json(g.wynik.odrzucone) === '["z1|text|de"]', json(g.wynik));
    t.check('pola DE obok EN, EN bez zmian', (g.pola || {})['h1|evk_tl_de__text'] === 'DE:Nasze usługi' && (g.pola || {})['h1|evk_tl_en__text'] === 'EN:Nasze usługi',
      json([(g.pola || {})['h1|evk_tl_de__text'], (g.pola || {})['h1|evk_tl_en__text']]));

    t.section('OpenAI: zapytanie (DE, strona D)');
    const o = sonda('krok', 'openai', 'de', 'D');
    const oz = (o.zadania || [])[0] || { headers: {}, body: {} };
    const ob = oz.body || {};
    t.check('Responses API, klucz w Authorization: Bearer', oz.url === 'https://api.openai.com/v1/responses' && oz.headers.Authorization === 'Bearer ' + KLUCZ,
      oz.url);
    t.check('model gpt-6-astra, instructions + input, text.format: json_schema ścisły z nazwą',
      ob.model === 'gpt-6-astra' && !!ob.instructions && !!ob.input && ob.text && ob.text.format.type === 'json_schema'
      && ob.text.format.strict === true && ob.text.format.name === 'translations' && !('temperature' in ob), json(ob.text));
    t.check('odpowiedź OpenAI przeczytana: 25 z AI', o.wynik && o.wynik.z_ai === 25 && o.wynik.zapisane === 25, json(o.wynik));

    t.section('pamięć tłumaczeń: tłumaczenie AI się nie liczy, dopóki ktoś go nie sprawdzi');
    const ok1 = sonda('krok', 'openai', 'de', 'B');
    t.check('przycisk na B (DE): tłumaczenie AI z A „Do sprawdzenia” nie jest pamięcią, wynik Gemini nie jest wynikiem OpenAI → pytanie do AI',
      ok1.wynik && ok1.wynik.z_ai === 1 && ok1.wynik.z_pamieci === 0 && (ok1.zadania || []).length === 1
      && (ok1.pola || {})['k1|evk_tl_de__text'] === 'DE:Kontakt ai-test', json([ok1.wynik, ok1.pola]));

    // ── Błędy ───────────────────────────────────────────────────────────────
    t.section('błędy dostawców: czekanie, stop, odrzut porcji');
    const blad = (dostawca, scen, ...r) => {
      const x = sonda('krok', dostawca, 'en', 'C', scen, ...r);
      return { w: x.wynik || {}, n: (x.zadania || []).length, pola: x.pola || {} };
    };
    let e = blad('claude', '429');
    t.check('429 z retry-after: czekaj 7 s, bez stopu, nic nie zapisane, porcja zostaje',
      e.w.czekaj === 7 && e.w.stop === false && e.w.zostalo === 2 && e.w.zapisane === 0 && Object.keys(e.pola).length === 0, json(e));
    e = blad('claude', 'limit-wydatkow');
    t.check('Claude: limit wydatków konta → stop z komunikatem', e.w.stop === true && /limit wydatków/.test(e.w.blad || ''), json(e.w));
    e = blad('claude', '401');
    t.check('401 → stop „odrzucił klucz”', e.w.stop === true && /odrzucił klucz API \(401\)/.test(e.w.blad || ''), json(e.w));
    e = blad('gemini', 'dzienny');
    t.check('Gemini: dzienny limit darmowego poziomu → stop „spróbuj jutro”', e.w.stop === true && /Dzienny limit/.test(e.w.blad || ''), json(e.w));
    e = blad('gemini', 'retry-gemini');
    t.check('Gemini: czas z RetryInfo (12 s) → czekaj 12', e.w.czekaj === 12 && e.w.stop === false, json(e.w));
    e = blad('claude', 'odmowa');
    t.check('odmowa modelu: porcja do odrzuconych, bez stopu, nic nie zostało, nic nie zapisane',
      json(e.w.odrzucone) === '["c1|text|en","c2|text|en"]' && e.w.zostalo === 0 && !e.w.stop && /odmówił/.test(e.w.blad || '')
      && Object.keys(e.pola).length === 0, json(e.w));
    e = blad('claude', 'zly-json');
    t.check('odpowiedź poza schematem: porcja do odrzuconych', json(e.w.odrzucone) === '["c1|text|en","c2|text|en"]' && /schematu/.test(e.w.blad || ''),
      json(e.w));
    e = blad('gemini', 'ok', '', 'bez-klucza');
    t.check('bez klucza: stop bez żadnego zapytania', e.w.stop === true && e.n === 0 && /Brak klucza/.test(e.w.blad || ''), json(e));

    // ── AJAX ────────────────────────────────────────────────────────────────
    t.section('AJAX: ustawienia tylko dla administratora, klucz nie wraca');
    const u1 = sonda('ajax-ustawienia', 'tlumacz');
    t.check('tłumacz (dostęp do Tłumaczeń): zapis ustawień odrzucony, klucze bez zmian',
      u1.odp && u1.odp.success === false && json(((u1.zapisane || {}).klucze) || {}) === '[]', json([u1.odp, (u1.zapisane || {}).klucze]));
    const u2 = sonda('ajax-ustawienia', 'admin');
    t.check('administrator: zapisane, odpowiedź mówi tylko „klucz jest”', u2.odp && u2.odp.success === true && u2.odp.data.klucz === true
      && !String(u2.surowe || '').includes('nowy-klucz-AJAX-456'), String(u2.surowe));
    t.check('opcja: klucz dostawcy, opis, wskazówki per język', (u2.zapisane || {}).dostawca === 'gemini'
      && u2.zapisane.klucze.gemini === 'nowy-klucz-AJAX-456' && u2.zapisane.opis === 'Opis AJAX' && u2.zapisane.wskazowki.en === 'EN AJAX',
      json(u2.zapisane));

    t.section('AJAX: krok tłumaczenia');
    const a1 = sonda('ajax-krok', 'czytelnik');
    t.check('bez prawa edycji strony: odmowa', a1.odp && a1.odp.success === false && a1.odp.data === 'Brak uprawnień do tej strony.', json(a1.odp));
    const a2 = sonda('ajax-krok', 'admin', '_wp_page_template');
    t.check('obcy klucz metadanych: odmowa', a2.odp && a2.odp.success === false && /Nieznana strona/.test(a2.odp.data || ''), json(a2.odp));
    const a3 = sonda('ajax-krok', 'admin');
    t.check('administrator: krok przez AJAX tłumaczy (C, DE: 2 z AI)', a3.odp && a3.odp.success === true && a3.odp.data.z_ai === 2, json(a3.odp));

    t.section('zakładka i „Sprawdzone”');
    const zk = sonda('zakladka');
    t.check('klucz API nie trafia do HTML-a zakładki; dostawca ma znacznik „zapisany”; nonce jest',
      zk.ma_klucz === false && zk.zapisany === true && zk.nonce === true, json(zk));
    const sp = sonda('sprawdzone', 'A', 'h1|text|en');
    t.check('„Sprawdzone” zdejmuje tłumaczenie AI z listy', sp.ok === true && !(sp.do_sprawdzenia || []).includes('h1|text|en')
      && (sp.do_sprawdzenia || []).includes('t1|text|en'), json(sp));

    // ── Panel w przeglądarce ────────────────────────────────────────────────
    t.section('panel: pętla tłumaczenia w przeglądarce (Gemini, jedno 429 po drodze)');
    sonda('sprzataj');
    sonda('przygotuj');
    sonda('strony');
    const mu = sonda('mu');
    sonda('scenariusz', '429-raz');
    t.check('atrapa w serwerze testowym (mu-plugin)', mu.mu === true, json(mu));
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    p.on('pageerror', (x) => bledy.push(x.message));
    await serwerWp.zaloguj(p, serwer.baza);
    await p.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=ai');
    /* Tabela zakresu startuje pusta (1.272.0) — treść Bricksa stron zaznacza test. */
    await p.check('.tl-ai-czesc[data-typ="page"][value="bricks"]');
    const html = await p.content();
    t.check('zakładka „Tłumaczenie AI” otwarta, klucza nie ma w stronie', html.includes('Przetłumacz strony') && !html.includes(KLUCZ),
      html.includes(KLUCZ) ? 'KLUCZ W STRONIE' : (html.includes('Przetłumacz strony') ? '' : 'brak zakładki: ' + p.url()));
    t.check('stan klucza wybranego dostawcy: „(zapisany)”', (await p.locator('.tl-ai-klucz-stan').textContent()) === '(zapisany)');

    await p.click('.tl-ai-lista');
    await p.locator('.tl-ai-wybor').first().waitFor({ timeout: 15000 });
    const wiersze = await p.$$eval('.tl-ai-wybor', (w) => w.map((c) => c.getAttribute('aria-label') || ''));
    t.check('każdy wiersz ma pole z nazwą (strona, część)', wiersze.length >= 3 && wiersze.every((x) => /^Tłumacz: .+ \(.+\)$/.test(x)), json(wiersze));
    // Tylko strony testu — inne strony testowego WordPressa zostają nietknięte.
    await p.$$eval('.tl-ai-wybor', (w) => w.forEach((c) => { c.checked = /Strona AI [ABC] /.test(c.getAttribute('aria-label')); }));
    t.check('trzy strony testu na liście', (await p.$$eval('.tl-ai-wybor:checked', (w) => w.length)) === 3);

    await p.setViewportSize({ width: 360, height: 800 });
    const tel = await p.evaluate(() => {
      const c = document.querySelector('.tl-ai-wybor').getBoundingClientRect();
      return { szer: document.documentElement.scrollWidth, okno: innerWidth, w: Math.round(c.width), h: Math.round(c.height) };
    });
    t.check('telefon (360 px): lista bez przewijania strony w poziomie, pole zaznaczenia ≥ 24×24',
      tel.szer <= tel.okno && tel.w >= 24 && tel.h >= 24, json(tel));
    await p.setViewportSize({ width: 1280, height: 900 });

    await p.click('.tl-ai-start');
    await p.waitForFunction(() => /^(Gotowe\.|Zatrzymane\.)$|limit|klucz/i.test(document.querySelector('.tl-ai-stan').textContent)
      && !document.querySelector('.tl-ai-start').disabled, null, { timeout: 60000 }).catch(() => {});
    const stan = await p.locator('.tl-ai-stan').textContent();
    const dz = await p.$$eval('.tl-ai-dziennik li', (l) => l.map((x) => x.textContent));
    t.check('przebieg skończony: „Gotowe.”', stan === 'Gotowe.', stan);
    t.check('429 po drodze: wpis w dzienniku, krok powtórzony po czekaniu', dz.some((x) => /Limit zapytań dostawcy — czekam\./.test(x))
      && dz.filter((x) => /^Strona AI A \(Treść\) EN:/.test(x)).length === 2, json(dz));
    t.check('podsumowanie: 22 zapisane (AI 20, z pamięci 2), odrzucone 2',
      dz[dz.length - 2] === 'Razem: zapisane 22 (od dostawcy: 20, z pamięci: 2), odrzucone: 2.'
      && dz[dz.length - 1] === 'Odpowiedział dostawca: gemini/gemini-3.8-flash (20).', json(dz.slice(-2)));
    const zad = sonda('zadania').zadania || [];
    t.check('żądania do Gemini: A EN (429 i powtórka), A DE, C EN, C DE — B z pamięci wyników bez zapytania, odrzut nie wraca',
      zad.length === 5 && zad.every((x) => x.includes('generativelanguage.googleapis.com')), json(zad));
    const pa = sonda('pola', 'A');
    t.check('strona A: EN i DE w polach, 16 miejsc „Do sprawdzenia”', (pa.pola || {})['h1|evk_tl_en__text'] === 'EN:Nasze usługi'
      && (pa.pola || {})['h1|evk_tl_de__text'] === 'DE:Nasze usługi' && pa.do_sprawdzenia === 16, json([pa.do_sprawdzenia, pa.pola]));
    const pb = sonda('pola', 'B');
    t.check('strona B: DE przycisku z pamięci wyników (ten sam tekst na A), „Do sprawdzenia”',
      (pb.pola || {})['k1|evk_tl_de__text'] === 'DE:Kontakt ai-test' && pb.do_sprawdzenia === 1, json(pb));

    t.section('panel: dzienny limit zatrzymuje cały przebieg');
    sonda('scenariusz', 'dzienny');
    await p.click('.tl-ai-lista');
    await p.waitForFunction(() => /Części stron z brakami/.test(document.querySelector('.tl-ai-stan').textContent), null, { timeout: 15000 }).catch(() => {});
    await p.$$eval('.tl-ai-wybor', (w) => w.forEach((c) => { c.checked = /Strona AI [ABC] /.test(c.getAttribute('aria-label')); }));
    t.check('na liście została tylko A (odrzucone „ZEPSUJ” w EN i DE)', (await p.$$eval('.tl-ai-wybor:checked', (w) => w.length)) === 1);
    await p.click('.tl-ai-start');
    await p.waitForFunction(() => !document.querySelector('.tl-ai-start').disabled, null, { timeout: 30000 }).catch(() => {});
    const stan2 = await p.locator('.tl-ai-stan').textContent();
    t.check('komunikat o limicie w pasku stanu', stan2 === 'Dzienny limit darmowego poziomu Gemini wyczerpany — spróbuj jutro.', stan2);
    t.check('jedno żądanie: po stopie nie idzie DE', (sonda('zadania').zadania || []).length === 1, json(sonda('zadania').zadania));

    t.section('panel: ustawienia (klucz wpisany i usunięty)');
    await p.selectOption('#tl-ai-dostawca', 'openai');
    t.check('po zmianie dostawcy: model domyślny w podpowiedzi, stan klucza tego dostawcy',
      (await p.getAttribute('#tl-ai-model', 'placeholder')) === 'gpt-6-astra' && (await p.locator('.tl-ai-klucz-stan').textContent()) === '(zapisany)');
    await p.check('#tl-ai-usun-klucz');
    await p.click('.tl-ai-zapisz');
    await p.waitForFunction(() => document.querySelector('.tl-ai-zapis-stan').textContent === 'Zapisano.', null, { timeout: 10000 }).catch(() => {});
    t.check('„Usuń zapisany klucz”: stan „(brak)”', (await p.locator('.tl-ai-klucz-stan').textContent()) === '(brak)');
    await p.fill('#tl-ai-klucz', 'klucz-z-panelu-789');
    await p.evaluate(() => { document.querySelector('.tl-ai-zapis-stan').textContent = ''; });
    await p.click('.tl-ai-zapisz');
    await p.waitForFunction(() => document.querySelector('.tl-ai-zapis-stan').textContent === 'Zapisano.', null, { timeout: 10000 }).catch(() => {});
    t.check('nowy klucz: „(zapisany)”, pole wyczyszczone', (await p.locator('.tl-ai-klucz-stan').textContent()) === '(zapisany)'
      && (await p.inputValue('#tl-ai-klucz')) === '');
    await p.reload();
    const html2 = await p.content();
    t.check('po odświeżeniu: dostawca OpenAI wybrany, klucza w stronie nie ma',
      (await p.inputValue('#tl-ai-dostawca')) === 'openai' && !html2.includes('klucz-z-panelu-789'));

    t.section('panel: wiersz ustawień wyrównany (zgłoszenie 02.10)');
    /* Google: dostawca, plik JSON, zasobnik i opisy obrazów w jednym wierszu. Dwuwierszowa etykieta
       „Opisy obrazów (…)” spychała pole niżej niż sąsiednie, a nazwy dostawców były ucięte w polu. */
    await p.selectOption('#tl-ai-dostawca', 'google');
    const uklad = [];
    for (const szer of [1280, 1600, 1920]) {
      await p.setViewportSize({ width: szer, height: 900 });
      uklad.push(await p.evaluate((szer) => {
        const widac = (x) => x.offsetParent !== null;
        const pola = [...document.querySelector('.tl-ai-wiersz').children].filter(widac);
        const gory = pola.map((f) => {
          const k = [...f.querySelectorAll('select, input:not([type=checkbox]), textarea')].find(widac);
          return Math.round(k.getBoundingClientRect().top);
        });
        const wiersze = {};
        pola.forEach((f, i) => { const g = Math.round(f.getBoundingClientRect().top); (wiersze[g] = wiersze[g] || []).push(gory[i]); });
        return { szer, wiersze: Object.values(wiersze) };
      }, szer));
    }
    console.log('      układ: ' + json(uklad));
    t.check('w każdym wierszu siatki pola zaczynają się na tej samej wysokości (1280, 1600, 1920 px)',
      uklad.every((u) => u.wiersze.every((w) => Math.max(...w) - Math.min(...w) <= 1)) && uklad.some((u) => u.wiersze.some((w) => w.length >= 3)), json(uklad));
    const nazwy = await p.evaluate(() => ({ dostawca: document.querySelector('#tl-ai-dostawca').selectedOptions[0].textContent,
      opis: document.querySelector('.tl-ai-dostawca-opis').textContent, opisy: [...document.querySelectorAll('#tl-ai-opisy option')].map((o) => o.textContent) }));
    t.check('w polach krótkie nazwy dostawców, dopisek pod polem', nazwy.dostawca === 'Google Cloud Translation (v3)' && nazwy.opis === 'plik JSON konta usługi'
      && nazwy.opisy.every((x) => !x.includes(' — ')), json(nazwy));
    await p.setViewportSize({ width: 1280, height: 720 });
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
