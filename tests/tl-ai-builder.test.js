/**
 * Przyciski „Przetłumacz (AI)” w builderze Bricksa (1.265.0).
 *
 * Decyzje zgłaszającego (30.09):
 *   - przycisk przy przełączniku PL | EN | DE tłumaczy zaznaczony element
 *     z dziećmi na język przełącznika; przy PL jest nieaktywny z podpowiedzią
 *     „Wybierz EN albo DE”;
 *   - mały przycisk pod polem „Tłumaczenie EN” zaznaczonego elementu;
 *   - model z ustawień (bez wyboru w builderze);
 *   - wynik trafia do stanu buildera: puste pola bez pytania, wypełnione po
 *     potwierdzeniu; zapis ręczny w Bricksie.
 *
 * Bricksa tu nie ma. Powłoka i panel to atrapa w kształcie z prób na testowej
 * (tests/fixtures/builder-ai.html, docs/proby-builder-ai.md), kanwa — ta sama
 * co w podglądzie. AJAX idzie przez PRAWDZIWY PHP: przechwycone żądanie
 * przeglądarki trafia ciałem do sondy (tests/php/tl-ai.php ajax-builder),
 * a ta woła prawdziwy punkt AJAX z atrapą dostawcy (Gemini, „EN:…”).
 *
 * Środowisko: tools/testowy-wp.sh.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { phpOutput } = require('./lib/harness');

const sonda = (...a) => {
  const s = phpOutput('tl-ai.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const MODEL = 'gemini/gemini-3.8-flash';

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;

  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'evk-t-ai-builder-'));
  let nr = 0;
  const plik = (cialo) => { const f = path.join(tmp, 'c' + (++nr) + '.txt'); fs.writeFileSync(f, cialo); return f; };
  /* Żądanie jak z kanwy, wprost do sondy. */
  const ajax = (kto, pola, scen) => sonda('ajax-builder', kto, plik(new URLSearchParams(Object.assign({ action: 'evk_tl_ai_builder',
    nonce: 'auto', post_id: 'F', lang: 'en' }, pola)).toString()), scen || 'ok');
  const kt = (lista) => lista.map(([el, pl, tl, pole, poz]) => ({ el, pole: pole || 'text', poz: poz || 0, pl, tl: tl || '' }));

  try {
    sonda('sprzataj');
    const prep = sonda('przygotuj');
    const str = sonda('strony');
    t.check('sonda: ustawienia AI, strony A–D (B: „Kontakt ai-test” → „Contact us”, sprawdzone)',
      prep.gotowe === true && str.tl === true, J([prep, str]));
    if (!prep.gotowe) return;

    // ── Dane kanwy ───────────────────────────────────────────────────────
    t.section('PHP: dane przycisków w kanwie');
    const dA = sonda('builder-dane', 'admin');
    const ai = (dA.dane || {}).ai || null;
    t.check('administrator z kluczem: adres AJAX, nonce, wpis kanwy, model z ustawień, porcja i limit znaków',
      !!ai && /\/wp-admin\/admin-ajax\.php$/.test(ai.ajax) && /^[0-9a-f]{10}$/.test(ai.nonce) && ai.post === dA.F && dA.F > 0
      && ai.model === MODEL && ai.porcja === 25 && ai.znaki === 6000, J(ai));
    t.check('klucza API nie ma w danych kanwy', dA.z_kluczem === false, J(dA.z_kluczem));
    const dK = sonda('builder-dane', 'admin', 'bez-klucza');
    const dT = sonda('builder-dane', 'tlumacz');
    const dR = sonda('builder-dane', 'redaktor');
    t.check('bez klucza API: bez przycisków (ai: null)', !!dK.dane && dK.dane.ai === null, J(dK.dane && dK.dane.ai));
    t.check('redaktor z dostępem do Tłumaczeń: przyciski są', !!(dT.dane && dT.dane.ai && dT.dane.ai.model === MODEL), J(dT.dane && dT.dane.ai));
    t.check('redaktor bez dostępu do Tłumaczeń: podgląd tak, przycisków nie', !!dR.dane && J(dR.dane.jezyki) === J(['pl', 'en', 'de'])
      && dR.dane.ai === null, J(dR.dane && dR.dane.ai));
    const dane = sonda('builder-dane', 'admin').dane;

    // ── AJAX wprost ──────────────────────────────────────────────────────
    t.section('PHP: tłumaczenie z buildera (AJAX), bez zapisu');
    const K1 = kt([['heading', 'Nagłówek z buildera'], ['button', 'Kontakt ai-test'], ['heading', '{post_title}'],
      ['accordion', 'Pytanie drugie', 'Second question', 'title', 2], ['text-basic', '<p>ZEPSUJ <em>to</em></p>']]);
    const r1 = ajax('admin', { kontekst: J(K1), teksty: J({ k1: { n: 1, bylo: '' }, k2: { n: 2, bylo: '' }, k3: { n: 3, bylo: '' }, k5: { n: 5, bylo: '' } }) });
    const d1 = (r1.odp || {}).data || {};
    t.check('AI, pamięć tłumaczeń (sprawdzone z innej strony), sam tag pominięty, strażnik odrzuca',
      J(d1.tlumaczenia) === J({ k2: 'Contact us', k1: 'EN:Nagłówek z buildera' }) && J(d1.zrodla) === J({ k2: 'pamiec', k1: 'ai' })
      && J(d1.pominiete) === J(['k3']) && J(d1.odrzucone) === J(['k5']) && d1.model === MODEL, J(r1.odp));
    t.check('jedno zapytanie do AI; nic nie zapisane w treści ani stanie stron', r1.zadania === 1 && r1.bez_zapisu === true, J([r1.zadania, r1.bez_zapisu]));
    t.check('zapytanie: tytuł strony kanwy, kontekst z opisem pola pozycji listy i obecnym tłumaczeniem',
      /^Page: Strona AI F\n/.test(r1.wiadomosc) && r1.wiadomosc.includes('4. [accordion · pozycja 2 · Tytuł] "Pytanie drugie" → "Second question"'),
      r1.wiadomosc.slice(0, 400));
    t.check('zapytanie: tłumaczenie z pamięci widać w kontekście (słownictwo reszty)',
      r1.wiadomosc.includes('2. [button · Tekst] "Kontakt ai-test" → "Contact us"'), r1.wiadomosc.slice(0, 400));
    t.check('zapytanie: do AI tylko teksty bez pamięci, z numerami z kontekstu',
      /"key": "t1",\s*"n": 1,[\s\S]*"key": "t2",\s*"n": 5,/.test(r1.wiadomosc) && !/"n": 2,/.test(r1.wiadomosc), r1.wiadomosc.slice(-400));
    t.check('instrukcje jak w hurcie (słowniczek i wskazówki z ustawień)', r1.system.includes('British English AI-TEST') && r1.system.includes('sklepy → shops'),
      r1.system.slice(0, 200));

    const r2 = ajax('admin', { kontekst: J(K1), teksty: J({ k1: { n: 1, bylo: '' } }) });
    const d2 = (r2.odp || {}).data || {};
    t.check('drugi raz: z pamięci wyników, bez zapytania', J(d2.tlumaczenia) === J({ k1: 'EN:Nagłówek z buildera' }) && J(d2.zrodla) === J({ k1: 'wynik' })
      && r2.zadania === 0, J([r2.odp, r2.zadania]));
    const r3 = ajax('admin', { kontekst: J(K1), teksty: J({ k1: { n: 1, bylo: 'EN:Nagłówek z buildera' }, k2: { n: 2, bylo: 'Contact us' } }) });
    const d3 = (r3.odp || {}).data || {};
    t.check('pole wypełnione tym, co jest w pamięci: pyta AI; wynik równy obecnemu — „bez zmian”, inny — wpis',
      r3.zadania === 1 && J(d3.bez_zmian) === J(['k1']) && J(d3.tlumaczenia) === J({ k2: 'EN:Kontakt ai-test' }) && J(d3.zrodla) === J({ k2: 'ai' }),
      J([r3.odp, r3.zadania]));

    const dlugi = 'ł'.repeat(3100);
    const K2 = kt([['heading', 'Krótki'], ['text-basic', dlugi]]);
    const wiele = {};
    for (let i = 1; i <= 26; i++) wiele['k' + i] = { n: 1, bylo: '' };
    const odmowy = {
      '26 tekstów naraz': ajax('admin', { kontekst: J(K2), teksty: J(wiele) }),
      'dwa teksty ponad 6000 bajtów': ajax('admin', { kontekst: J(K2), teksty: J({ k1: { n: 1, bylo: '' }, k2: { n: 2, bylo: '' } }) }),
      'numer spoza kontekstu': ajax('admin', { kontekst: J(K2), teksty: J({ k1: { n: 3, bylo: '' } }) }),
      'klucz z niedozwolonym znakiem': ajax('admin', { kontekst: J(K2), teksty: J({ 'k1|x': { n: 1, bylo: '' } }) }),
      'kontekst ponad 2000 tekstów': ajax('admin', { kontekst: J(Array.from({ length: 2001 }, () => K2[0])), teksty: J({ k1: { n: 1, bylo: '' } }) }),
      'nieznany język': ajax('admin', { lang: 'xx', kontekst: J(K2), teksty: J({ k1: { n: 1, bylo: '' } }) }),
      'zepsuty JSON': ajax('admin', { kontekst: '{', teksty: J({ k1: { n: 1, bylo: '' } }) }),
    };
    Object.keys(odmowy).forEach((k) => {
      const o = odmowy[k];
      t.check('odmowa bez zapytania do AI: ' + k, !!o.odp && o.odp.success === false && typeof o.odp.data === 'string' && o.zadania === 0,
        J([o.odp, o.zadania]));
    });
    const sam = ajax('admin', { kontekst: J(K2), teksty: J({ k2: { n: 2, bylo: '' } }) });
    t.check('jeden długi tekst ponad limit znaków przechodzi sam (jak porcja hurtu)', ((sam.odp || {}).data || {}).zrodla
      && sam.odp.data.zrodla.k2 === 'ai' && sam.zadania === 1, J([sam.zadania, sam.odp && sam.odp.success]));

    const bezNonce = ajax('admin', { nonce: 'zly', kontekst: J(K2), teksty: J({ k1: { n: 1, bylo: '' } }) });
    const czytelnik = ajax('czytelnik', { kontekst: J(K2), teksty: J({ k1: { n: 1, bylo: '' } }) });
    const redaktor = ajax('redaktor', { kontekst: J(K2), teksty: J({ k1: { n: 1, bylo: '' } }) });
    const tlumacz = ajax('tlumacz', { kontekst: J(K2), teksty: J({ k1: { n: 1, bylo: '' } }) });
    t.check('zły nonce: -1; dostęp do Tłumaczeń bez prawa edycji strony: odmowa; bez dostępu do Tłumaczeń: odmowa',
      bezNonce.odp === -1 && czytelnik.odp.success === false && /uprawnień do tej strony/.test(czytelnik.odp.data)
      && redaktor.odp.success === false && /Brak uprawnien/.test(redaktor.odp.data)
      && bezNonce.zadania + czytelnik.zadania + redaktor.zadania === 0, J([bezNonce.odp, czytelnik.odp, redaktor.odp]));
    t.check('redaktor z dostępem do Tłumaczeń tłumaczy', tlumacz.odp.success === true && tlumacz.zadania === 1, J([tlumacz.odp, tlumacz.zadania]));

    const K3 = kt([['heading', 'Błąd dostawcy'], ['button', 'Kontakt ai-test']]);
    const T3 = J({ k1: { n: 1, bylo: '' }, k2: { n: 2, bylo: '' } });
    const e401 = ajax('admin', { kontekst: J(K3), teksty: T3 }, '401');
    const e429 = ajax('admin', { kontekst: J(K3), teksty: T3 }, '429');
    const eJson = ajax('admin', { kontekst: J(K3), teksty: T3 }, 'zly-json');
    const g = (r) => (r.odp || {}).data || {};
    t.check('klucz odrzucony (401): stop z komunikatem; wynik z pamięci i tak oddany',
      g(e401).stop === true && /odrzucił klucz API \(401\)/.test(g(e401).blad) && J(g(e401).tlumaczenia) === J({ k2: 'Contact us' })
      && J(g(e401).odrzucone) === '[]', J(g(e401)));
    t.check('limit (429): czekaj 7 s, bez odrzuconych', g(e429).czekaj === 7 && g(e429).stop === false && J(g(e429).odrzucone) === '[]', J(g(e429)));
    t.check('odpowiedź poza schematem: teksty porcji odrzucone', /schematu/.test(g(eJson).blad) && J(g(eJson).odrzucone) === J(['k1'])
      && g(eJson).stop === false, J(g(eJson)));

    // ── Builder ──────────────────────────────────────────────────────────
    t.section('builder: przycisk przy przełączniku');
    /* Kanwa i admin-ajax.php są w builderze na tym samym adresie; fixtura idzie
       z serwera plików testu, więc adres AJAX — ścieżka na tym samym serwerze. */
    const daneKanwy = Object.assign({}, dane, { ai: Object.assign({}, dane.ai, { ajax: '/wp-admin/admin-ajax.php' }) });
    const page = await t.open('builder-ai.html', { przezHttp: true, settle: 900, viewport: { width: 1300, height: 820 },
      head: 'window.__EVK_TL_DANE = ' + J(daneKanwy) + ';' });
    const zadania = [];
    const odpowiedzi = [];
    let scen = 'ok';
    let wstrzymaj = null;
    await page.route('**/wp-admin/admin-ajax.php', async (route) => {
      const cialo = route.request().postData() || '';
      zadania.push(Object.fromEntries(new URLSearchParams(cialo)));
      if (wstrzymaj) await wstrzymaj;
      const r = sonda('ajax-builder', 'admin', plik(cialo), scen);
      odpowiedzi.push(r);
      await route.fulfill({ status: r.odp === -1 ? 403 : 200, contentType: 'application/json', body: J(r.odp) });
    });
    const dialogi = [];
    let decyzja = 'accept';
    page.on('dialog', (d) => { dialogi.push(d.message()); if (decyzja === 'accept') d.accept(); else d.dismiss(); });

    const kanwa = () => page.frames().find((f) => /builder-podglad-kanwa\.html/.test(f.url()));
    const stan = (id) => page.evaluate((i) => {
      let w = null;
      ['content', 'header', 'footer'].forEach((o) => window.__stan[o].forEach((e) => { if (e.id === i) w = e; }));
      return w ? JSON.parse(JSON.stringify(w.settings)) : null;
    }, id);
    const zaznacz = async (id) => { await page.evaluate((i) => { window.__stan.activeId = i; }, id); await page.waitForTimeout(500); };
    const przycisk = () => page.evaluate(() => {
      const b = document.getElementById('evk-tl-ai-element');
      if (!b) return null;
      const r = b.getBoundingClientRect();
      const p = document.getElementById(b.getAttribute('aria-describedby') || '-');
      return { tekst: b.textContent, nieaktywny: b.getAttribute('aria-disabled'), zajety: b.getAttribute('aria-busy'), tytul: b.title,
        podpowiedz: p ? p.textContent : null, wys: r.height, szer: r.width };
    });
    const dymek = () => page.evaluate(() => {
      const d = document.getElementById('evk-tl-ai-dymek');
      const z = document.getElementById('evk-tl-ai-zamknij');
      if (!d) return null;
      const r = d.getBoundingClientRect(), rz = z.getBoundingClientRect();
      return { widoczny: d.classList.contains('widoczny') && r.width > 1, tekst: document.getElementById('evk-tl-ai-stan').textContent,
        rola: document.getElementById('evk-tl-ai-stan').getAttribute('role'), zamknij: !z.hidden && rz.width >= 24 && rz.height >= 24 };
    });
    const koniec = (sel) => page.waitForFunction((s) => {
      const e = document.querySelector(s);
      return e && e.textContent !== '' && !/^Tłumaczę/.test(e.textContent);
    }, sel, { timeout: 30000 }).catch(() => null);
    const klik = async () => { await page.click('#evk-tl-ai-element'); await koniec('#evk-tl-ai-stan'); await page.waitForTimeout(200); };

    const grupa = await page.evaluate(() => {
      const u = document.getElementById('evk-tl-ai');
      return u ? { przed: u.previousElementSibling && u.previousElementSibling.id, rola: u.getAttribute('role'), etykieta: u.getAttribute('aria-label'),
        ile: document.querySelectorAll('#evk-tl-ai').length } : null;
    });
    t.check('grupa „Tłumaczenie AI” zaraz za przełącznikiem PL | EN | DE', !!grupa && grupa.przed === 'evk-tl-podglad' && grupa.rola === 'group'
      && grupa.etykieta === 'Tłumaczenie AI' && grupa.ile === 1, J(grupa));
    const p0 = await przycisk();
    t.check('PL: „Przetłumacz (AI)” nieaktywny, podpowiedź „Wybierz EN albo DE” (dymek i opis)', !!p0 && p0.tekst === 'Przetłumacz (AI)'
      && p0.nieaktywny === 'true' && p0.tytul === 'Wybierz EN albo DE' && p0.podpowiedz === 'Wybierz EN albo DE', J(p0));
    t.check('cel dotyku co najmniej 24 px wysokości', !!p0 && p0.wys >= 24, J(p0 && [p0.szer, p0.wys]));
    /* `force`: Playwright bierze `aria-disabled` za wyłączenie i czekałby na włączenie. */
    await page.click('#evk-tl-ai-element', { force: true });
    await page.waitForTimeout(150);
    const dPL = await dymek();
    t.check('kliknięcie przy PL: komunikat z podpowiedzią (role=status), bez żądania', !!dPL && dPL.widoczny && /^Wybierz EN albo DE/.test(dPL.tekst)
      && dPL.rola === 'status' && dPL.zamknij && zadania.length === 0, J([dPL, zadania.length]));
    await page.click('#evk-tl-ai-zamknij');
    await page.waitForTimeout(100);
    const poZamk = await dymek();
    const fokus = await page.evaluate(() => document.activeElement && document.activeElement.id);
    t.check('„Zamknij komunikat”: dymek znika, fokus wraca na przycisk', !!poZamk && !poZamk.widoczny && poZamk.tekst === '' && fokus === 'evk-tl-ai-element',
      J([poZamk, fokus]));

    await page.click('#evk-tl-podglad button[data-jezyk="en"]');
    await page.waitForTimeout(400);
    const p1 = await przycisk();
    t.check('EN bez zaznaczenia: nieaktywny, „Zaznacz element”', p1.nieaktywny === 'true' && p1.tytul === 'Zaznacz element', J(p1));
    await page.click('#evk-tl-ai-element', { force: true });
    await page.waitForTimeout(150);
    t.check('kliknięcie bez zaznaczenia: komunikat, bez żądania', /^Zaznacz element/.test((await dymek()).tekst) && zadania.length === 0,
      J([await dymek(), zadania.length]));

    t.section('builder: przycisk pod polem „Tłumaczenie EN”');
    await zaznacz('h1');
    const p2 = await przycisk();
    t.check('zaznaczony element: przycisk aktywny, opis z językiem i modelem; stary komunikat znika',
      p2.nieaktywny === 'false' && p2.tytul === 'Zaznaczony element z dziećmi → EN (' + MODEL + ')' && !(await dymek()).widoczny, J([p2, await dymek()]));
    const pola = () => page.evaluate(() => Array.from(document.querySelectorAll('.evk-tl-ai-pole')).map((b) => ({
      klucz: b.parentElement.getAttribute('data-controlkey'), w_liscie: !!b.parentElement.parentElement.closest('[data-controlkey]'),
      nazwa: b.querySelector('button').getAttribute('aria-label'), wys: b.querySelector('button').getBoundingClientRect().height })));
    const ph1 = await pola();
    t.check('nagłówek: przyciski pod polami EN i DE, z nazwą pola', J(ph1.map((x) => [x.klucz, x.nazwa])) === J([
      ['evk_tl_en__text', 'Przetłumacz (AI) — Tłumaczenie EN'], ['evk_tl_de__text', 'Przetłumacz (AI) — Tłumaczenie DE']])
      && ph1.every((x) => x.wys >= 24), J(ph1));
    await page.click('[data-controlkey="evk_tl_en__text"] .evk-tl-ai-pole button');
    await koniec('[data-controlkey="evk_tl_en__text"] .evk-tl-ai-pole-stan');
    await page.waitForTimeout(300);
    const z1 = zadania[zadania.length - 1] || {};
    const kz1 = JSON.parse(z1.kontekst || '[]');
    const tz1 = JSON.parse(z1.teksty || '{}');
    t.check('puste pole: bez pytania, jedno żądanie z językiem, wpisem kanwy i nonce', dialogi.length === 0 && zadania.length === 1
      && z1.lang === 'en' && z1.post_id === String(dane.ai.post) && z1.nonce === dane.ai.nonce && z1.action === 'evk_tl_ai_builder', J(z1).slice(0, 300));
    t.check('kontekst: wszystkie teksty obszaru w kolejności stanu, z tłumaczeniami; bez samego tagu, instancji komponentu i elementów spoza mapy',
      kz1.length === 41 && kz1[0].pl === 'Oferta' && kz1[3].tl === 'Two' && kz1[3].poz === 2 && kz1[3].pole === 'title' && kz1[6].pl === 'Grafika<br>użytkowa'
      && kz1[7].tl === 'Ask for a quote' && !kz1.some((k) => k.pl === '{post_title}' || k.pl === 'Tekst komponentu') && kz1[10].el === 'slider',
      J(kz1.slice(0, 12).map((k) => [k.el, k.pole, k.poz, k.pl.slice(0, 20), k.tl])) + ' (' + kz1.length + ')');
    t.check('tekst do tłumaczenia: numer nagłówka w kontekście, pole puste', J(tz1) === J({ k1: { n: 7, bylo: '' } }), J(tz1));
    const sh1 = await stan('h1');
    const pole1 = await page.evaluate(() => ({ wartosc: document.getElementById('evk_tl_en__text').value,
      stan: document.querySelector('[data-controlkey="evk_tl_en__text"] .evk-tl-ai-pole-stan').textContent }));
    t.check('wynik w stanie powłoki i w polu panelu; komunikat z modelem i prośbą o zapis', sh1.evk_tl_en__text === 'EN:Grafika<br>EN:użytkowa'
      && pole1.wartosc === 'EN:Grafika<br>EN:użytkowa' && pole1.stan === 'Wpisane (AI · ' + MODEL + '). Zapisz stronę w Bricksie.', J([sh1, pole1]));
    const kh1 = await kanwa().evaluate(() => document.getElementById('brxe-h1').innerHTML);
    t.check('kanwa w podglądzie EN pokazuje wpisane tłumaczenie', kh1 === 'EN:Grafika<br>EN:użytkowa', kh1);

    await zaznacz('t1');
    decyzja = 'dismiss';
    await page.click('[data-controlkey="evk_tl_en__text"] .evk-tl-ai-pole button');
    await page.waitForTimeout(400);
    t.check('wypełnione pole: pytanie z obecnym tekstem; „Anuluj” — bez żądania i bez zmiany',
      dialogi.length === 1 && dialogi[0] === 'Zastąpić obecne tłumaczenie EN?\n\n„Ask for a quote”' && zadania.length === 1
      && (await stan('t1')).evk_tl_en__text === 'Ask for a quote', J([dialogi, zadania.length]));
    decyzja = 'accept';
    await page.click('[data-controlkey="evk_tl_en__text"] .evk-tl-ai-pole button');
    await koniec('[data-controlkey="evk_tl_en__text"] .evk-tl-ai-pole-stan');
    await page.waitForTimeout(300);
    const z2 = zadania[zadania.length - 1] || {};
    const kz2 = JSON.parse(z2.kontekst || '[]');
    t.check('„OK”: żądanie z obecnym tekstem, w kontekście bez niego (model tłumaczy po swojemu); nowy tekst w polu',
      zadania.length === 2 && J(JSON.parse(z2.teksty || '{}')) === J({ k1: { n: 8, bylo: 'Ask for a quote' } }) && kz2[7].tl === ''
      && kz2[6].tl === 'EN:Grafika<br>EN:użytkowa' && (await stan('t1')).evk_tl_en__text === 'EN:Zapytaj o wycenę',
      J([JSON.parse(z2.teksty || '{}'), kz2[7], (await stan('t1')).evk_tl_en__text]));

    await zaznacz('sl1');
    const psl = await pola();
    t.check('pole elementu i pole pozycji listy o tym samym kluczu: przycisk tylko przy polu elementu',
      J(psl.map((x) => [x.klucz, x.w_liscie])) === J([['evk_tl_en__title', false], ['evk_tl_de__title', false]]), J(psl));
    await zaznacz('a1');
    t.check('akordeon (same pola pozycji): bez przycisków pod polami', (await pola()).length === 0, J(await pola()));
    await zaznacz('h1');
    await page.click('[data-controlkey="evk_tl_de__text"] .evk-tl-ai-pole button');
    await koniec('[data-controlkey="evk_tl_de__text"] .evk-tl-ai-pole-stan');
    await page.waitForTimeout(300);
    const sh1de = await stan('h1');
    t.check('pole DE przy podglądzie EN: tłumaczy na DE, EN bez zmian', zadania.length === 3 && zadania[2].lang === 'de'
      && sh1de.evk_tl_de__text === 'DE:Grafika<br>DE:użytkowa' && sh1de.evk_tl_en__text === 'EN:Grafika<br>EN:użytkowa', J(sh1de));

    t.section('builder: element z dziećmi');
    await zaznacz('s1');
    decyzja = 'dismiss';
    dialogi.length = 0;
    await klik();
    const ss = { h2: await stan('h2'), a1: await stan('a1'), z1: await stan('z1') };
    const zs = zadania[zadania.length - 1] || {};
    t.check('pytanie o wypełnione (1) z liczbą pustych (5); „Anuluj” — tylko puste pola',
      J(dialogi) === J(['Zaznaczony element ma już tłumaczenia EN: 1.\n\nOK — przetłumacz je od nowa razem z pustymi polami (5).\nAnuluj — tylko puste pola.'])
      && J(JSON.parse(zs.teksty || '{}')) === J({ k1: { n: 1, bylo: '' }, k2: { n: 2, bylo: '' }, k3: { n: 3, bylo: '' }, k4: { n: 5, bylo: '' }, k5: { n: 6, bylo: '' } }),
      J([dialogi, JSON.parse(zs.teksty || '{}')]));
    t.check('wpisane: nagłówek i pozycje listy; wypełnione zostaje; odrzucone przez strażnika — puste',
      ss.h2.evk_tl_en__text === 'EN:Oferta' && ss.a1.items[0].evk_tl_en__title === 'EN:Jeden' && ss.a1.items[0].evk_tl_en__content === '<p>EN:Pierwsza odpowiedź.</p>'
      && ss.a1.items[1].evk_tl_en__title === 'Two' && ss.a1.items[1].evk_tl_en__content === '<p>EN:Druga odpowiedź.</p>' && ss.z1.evk_tl_en__text === undefined,
      J(ss));
    const d1b = await dymek();
    t.check('komunikat: wpisane 4, odrzucone 1, prośba o zapis', d1b.widoczny
      && d1b.tekst === 'EN: wpisane 4, odrzucone 1 (znaczniki, tagi {…} albo shortcody). Zapisz stronę w Bricksie.', d1b.tekst);
    const ks = await kanwa().evaluate(() => [document.getElementById('brxe-h2').textContent,
      Array.from(document.querySelectorAll('#brxe-a1 .title')).map((e) => e.textContent)]);
    t.check('kanwa: dzieci przetłumaczone w podglądzie od razu (nie tylko zaznaczony element)', J(ks) === J(['EN:Oferta', ['EN:Jeden', 'Two']]), J(ks));

    decyzja = 'accept';
    dialogi.length = 0;
    await klik();
    const ss2 = await stan('a1');
    t.check('„OK”: wszystkie od nowa — ten sam wynik to „bez zmian”, wypełnione ręcznie dostaje AI',
      /tłumaczenia EN: 5\.[\s\S]*\(1\)/.test(dialogi[0] || '') && ss2.items[1].evk_tl_en__title === 'EN:Dwa'
      && (await dymek()).tekst === 'EN: wpisane 1, bez zmian 4, odrzucone 1 (znaczniki, tagi {…} albo shortcody). Zapisz stronę w Bricksie.',
      J([dialogi, ss2.items[1], (await dymek()).tekst]));

    await zaznacz('b1');
    const przedB = zadania.length;
    await klik();
    const ob = odpowiedzi[odpowiedzi.length - 1] || {};
    t.check('pamięć tłumaczeń: „Contact us” bez zapytania do AI; komunikat liczy pamięć',
      (await stan('b1')).evk_tl_en__text === 'Contact us' && zadania.length === przedB + 1 && ob.zadania === 0
      && (await dymek()).tekst === 'EN: wpisane 1 (z pamięci 1). Zapisz stronę w Bricksie.', J([(await stan('b1')), ob.zadania, (await dymek()).tekst]));

    await zaznacz('hd1');
    await klik();
    const zh = zadania[zadania.length - 1] || {};
    t.check('element nagłówka strony: kontekst z nagłówka, nie z treści', J(JSON.parse(zh.kontekst || '[]').map((k) => k.pl)) === J(['Menu główne'])
      && (await stan('hd1')).evk_tl_en__text === 'EN:Menu główne', J([JSON.parse(zh.kontekst || '[]'), await stan('hd1')]));

    await zaznacz('s9');
    const przed9 = zadania.length;
    await klik();
    const z9 = zadania.slice(przed9).map((z) => Object.keys(JSON.parse(z.teksty || '{}')).length);
    const s9 = await page.evaluate(() => window.__stan.content.filter((e) => e.parent === 's9').map((e) => e.settings.evk_tl_en__text || ''));
    t.check('porcje jak w hurcie: 25 tekstów, potem 2, a tekst 6200 bajtów (3100 znaków) sam', J(z9) === J([25, 2, 1]), J(z9));
    const k9 = JSON.parse((zadania[przed9 + 1] || {}).kontekst || '[]');
    t.check('następna porcja widzi w kontekście tłumaczenia z poprzedniej (jak w hurcie)', !!k9[12] && k9[12].pl === 'Pozycja 1'
      && k9[12].tl === 'EN:Pozycja 1' && k9[38].pl === 'Pozycja 27' && k9[38].tl === '', J([k9[12], k9[38]]));
    /* Długi tekst przetłumaczyła już część PHP (ten sam tekst i ustawienia) — z pamięci wyników. */
    t.check('wszystko wpisane poza instancją komponentu; komunikat mówi o pominiętym komponencie i pamięci',
      s9.filter((x) => /^EN:/.test(x)).length === 28 && (await stan('kc1')).evk_tl_en__text === undefined
      && (await dymek()).tekst === 'EN: wpisane 28 (z pamięci 1), komponenty pominięte 1. Zapisz stronę w Bricksie.',
      J([s9.filter((x) => /^EN:/.test(x)).length, (await dymek()).tekst]));

    await zaznacz('kc1');
    const przedK = zadania.length;
    await klik();
    t.check('zaznaczona instancja komponentu: bez żądania, komunikat o pominięciu', zadania.length === przedK
      && (await dymek()).tekst === 'W zaznaczonym elemencie nie ma tekstów do tłumaczenia (komponenty pomijam).', J([zadania.length - przedK, (await dymek()).tekst]));

    t.section('builder: w trakcie, błędy, DE, klawiatura');
    await zaznacz('h3');
    let pusc;
    wstrzymaj = new Promise((ok) => { pusc = ok; });
    const przedW = zadania.length;
    await page.click('#evk-tl-ai-element');
    await page.waitForTimeout(300);
    const wTrakcie = await przycisk();
    await page.click('#evk-tl-ai-element', { force: true });
    await page.waitForTimeout(150);
    const polaW = await pola();
    await page.click('[data-controlkey="evk_tl_de__text"] .evk-tl-ai-pole button');
    await page.waitForTimeout(150);
    const stanPolaW = await page.evaluate(() => document.querySelector('[data-controlkey="evk_tl_de__text"] .evk-tl-ai-pole-stan').textContent);
    await page.evaluate(() => { window.__stan.content.find((e) => e.id === 'h3').settings.text = 'Oferta zmieniona'; });
    pusc();
    wstrzymaj = null;
    await koniec('#evk-tl-ai-stan');
    await page.waitForTimeout(200);
    t.check('w trakcie: przycisk zajęty i nieaktywny; przycisk pola czeka', wTrakcie.zajety === 'true' && wTrakcie.nieaktywny === 'true'
      && wTrakcie.tytul === 'Tłumaczę…' && polaW.length === 2 && stanPolaW === 'Trwa tłumaczenie — poczekaj na koniec.', J([wTrakcie, stanPolaW]));
    t.check('drugie kliknięcie w trakcie nie wysyła drugiego żądania', zadania.length === przedW + 1, zadania.length - przedW);
    t.check('polski tekst zmieniony w trakcie: nic nie wpisane, komunikat to mówi', (await stan('h3')).evk_tl_en__text === undefined
      && (await dymek()).tekst === 'EN: zmienione w trakcie 1.', (await dymek()).tekst);
    t.check('po końcu przycisk znów aktywny', (await przycisk()).zajety === 'false' && (await przycisk()).nieaktywny === 'false', J(await przycisk()));

    scen = '401';
    await klik();
    t.check('błąd dostawcy (401): komunikat z serwera, nic nie wpisane', (await stan('h3')).evk_tl_en__text === undefined
      && /^EN: nic nie wpisane\. Dostawca odrzucił klucz API \(401\)/.test((await dymek()).tekst), (await dymek()).tekst);
    scen = 'ok';

    await page.click('#evk-tl-podglad button[data-jezyk="de"]');
    await page.waitForTimeout(300);
    await zaznacz('h2');
    const p3 = await przycisk();
    await page.focus('#evk-tl-podglad button[data-jezyk="de"]');
    await page.keyboard.press('Tab');
    const fokusAi = await page.evaluate(() => document.activeElement && document.activeElement.id);
    await page.keyboard.press('Enter');
    await koniec('#evk-tl-ai-stan');
    await page.waitForTimeout(200);
    t.check('DE z klawiatury: Tab z przełącznika na przycisk, Enter — pole DE', fokusAi === 'evk-tl-ai-element'
      && p3.tytul === 'Zaznaczony element z dziećmi → DE (' + MODEL + ')' && (await stan('h2')).evk_tl_de__text === 'DE:Oferta'
      && (await stan('h2')).evk_tl_en__text === 'EN:Oferta', J([fokusAi, p3.tytul, await stan('h2')]));
    await page.focus('#evk-tl-ai-zamknij');
    await page.keyboard.press('Escape');
    await page.waitForTimeout(100);
    t.check('Esc w komunikacie zamyka go', !(await dymek()).widoczny, J(await dymek()));

    t.section('builder: edytor w polu, pagehide, przeładowanie kanwy');
    await page.click('#evk-tl-podglad button[data-jezyk="en"]');
    await page.waitForTimeout(300);
    await zaznacz('tx1');
    await page.click('[data-controlkey="evk_tl_en__text"] .evk-tl-ai-pole button');
    await koniec('[data-controlkey="evk_tl_en__text"] .evk-tl-ai-pole-stan');
    await page.waitForTimeout(200);
    const ed = await page.evaluate(() => ({ stan: window.__stan.content.find((e) => e.id === 'tx1').settings.evk_tl_en__text,
      edytor: window.tinymce.get('evk_tl_en__text').getContent(), de: window.tinymce.get('evk_tl_de__text').getContent() }));
    t.check('pole z edytorem TinyMCE: tekst w stanie i w edytorze (edytor nie zapisze starego z powrotem); DE nietknięte',
      ed.stan === '<p>EN:Treść w edytorze</p>' && ed.edytor === '<p>EN:Treść w edytorze</p>' && ed.de === '', J(ed));

    /* Zamknięcie kanwy: jej przyciski znikają od razu (inaczej zostałyby
       w powłoce z obsługą z martwego okna), a straż ich nie odtwarza. */
    await kanwa().evaluate(() => window.dispatchEvent(new PageTransitionEvent('pagehide')));
    await page.waitForTimeout(1300);
    const poZamknieciu = await page.evaluate(() => ['#evk-tl-podglad', '#evk-tl-ai', '#evk-tl-ai-dymek', '.evk-tl-ai-pole']
      .map((s) => document.querySelectorAll(s).length));
    t.check('pagehide kanwy: przełącznik, przycisk, komunikat i przyciski pól znikają i nie wracają', J(poZamknieciu) === J([0, 0, 0, 0]), J(poZamknieciu));
    await zaznacz('h2');
    await kanwa().evaluate(() => location.reload());
    await page.waitForTimeout(1600);
    const po = await page.evaluate(() => ({ grupy: document.querySelectorAll('#evk-tl-ai').length, dymki: document.querySelectorAll('#evk-tl-ai-dymek').length,
      pola: Array.from(document.querySelectorAll('.evk-tl-ai-pole')).map((b) => b.parentElement.getAttribute('data-controlkey')) }));
    t.check('po przeładowaniu kanwy przyciski raz (stare — z martwego okna — usunięte)', po.grupy === 1 && po.dymki === 1
      && J(po.pola) === J(['evk_tl_en__text', 'evk_tl_de__text']), J(po));
    /* Kanwa podmieniona bez pagehide (np. zerwana): przyciski pól z obcego
       okna mają obsługę z martwego okna — straż stawia własne. */
    await page.evaluate(() => document.querySelectorAll('.evk-tl-ai-pole').forEach((b) => { b.__evkWlasciciel = null; b.setAttribute('data-obcy', '1'); }));
    await page.waitForTimeout(500);
    const obcePola = await page.evaluate(() => [document.querySelectorAll('.evk-tl-ai-pole[data-obcy]').length, document.querySelectorAll('.evk-tl-ai-pole').length]);
    t.check('przyciski pól z obcego okna: podmienione na własne', J(obcePola) === J([0, 2]), J(obcePola));
    const przedR = zadania.length;
    dialogi.length = 0;
    await page.click('[data-controlkey="evk_tl_en__text"] .evk-tl-ai-pole button');
    await koniec('[data-controlkey="evk_tl_en__text"] .evk-tl-ai-pole-stan');
    t.check('przycisk pola po przeładowaniu działa (nowe okno kanwy)', zadania.length === przedR + 1
      && J(dialogi) === J(['Zastąpić obecne tłumaczenie EN?\n\n„EN:Oferta”']), J([zadania.length - przedR, dialogi]));

    t.check('bez błędów w konsoli', page.errors.length === 0, J(page.errors.slice(0, 3)));
  } finally {
    sonda('sprzataj');
    fs.rmSync(tmp, { recursive: true, force: true });
  }
};
