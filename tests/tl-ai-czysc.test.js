/**
 * „Wyczyść tłumaczenia strony” i „Pytaj AI od nowa” (1.264.0).
 *
 * Zgłoszone (30.09): „Potrzebna jest jeszcze opcja czyszczenia tłumaczeń dla
 * całej podstrony — bez tego tłumaczenie hurtowe nie pozwala na ponowne
 * tłumaczenie tym samym modelem”. Pamięć wyników (61) oddaje temu samemu
 * modelowi przy tych samych ustawieniach zapamiętany tekst bez zapytania,
 * więc samo wyczyszczenie pól nic by nie dało — hurt wpisałby to samo.
 * Decyzje zgłaszającego:
 *   - zakres do wyboru przy czyszczeniu: tylko tłumaczenia AI „Do
 *     sprawdzenia” (domyślnie) albo wszystkie; do tego języki;
 *   - czyszczenie zapomina wyniki AI dla tekstów strony (wszystkich modeli),
 *     a w hurcie jest „Pytaj AI od nowa” (bez pamięci wyników);
 *   - osobne pole w zakładce Tłumaczenie AI: strona z listy wszystkich stron
 *     z tekstami, po czyszczeniu zaznaczona na liście hurtu;
 *   - „Przywróć wyczyszczone” — do pustych pól, do następnego czyszczenia.
 *
 * Sonda i atrapa jak w tl-ai (tests/php/tl-ai.php, tests/php/_ai-atrapa.php);
 * strona E tylko dla tego testu (krok „strona-e”). Atrapa odpowiada zawsze
 * tak samo („EN:…”, inny model: „EN[model]:…”), więc to, czy zapytanie
 * poszło, mówi liczba żądań, a nie tekst.
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
const E2_PL = 'Ścieżka C:\\Dane\\oferta';
const EN_AI = ['e1|text|en', 'e2|text|en', 'e7|items.q1.content|en', 'e7|items.q1.title|en'];
const DE_AI = ['e1|text|de', 'e2|text|de', 'e3|text|de', 'e4|text|de', 'e7|items.q1.content|de', 'e7|items.q1.title|de'];
const TEKSTY = json([['en', 'Czyszczony nagłówek'], ['en', E2_PL], ['de', 'Czyszczony nagłówek'], ['en', 'Oferta specjalna']]);

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
    const e = sonda('strona-e');
    t.check('sonda: ustawienia AI (Claude), strony A–D i E; tekst e2 z ukośnikami wstecznymi', prep.gotowe === true && str.tl === true
      && !!e.E && e.tekst_e2 === E2_PL, json([prep, e]));
    if (!prep.gotowe || !e.E) return;
    sonda('krok', 'claude', 'en', 'C');
    const k1 = sonda('krok', 'claude', 'en', 'E');
    const k2 = sonda('krok', 'claude', 'de', 'E');
    const sp = sonda('sprawdzone', 'E', 'e3|text|en');
    t.check('E przetłumaczona przez Claude: EN 5 (e3 potem przyjęty), DE 6; ręczny EN, sam {post_title} i element spoza mapy bez AI',
      (k1.wynik || {}).z_ai === 5 && (k2.wynik || {}).z_ai === 6 && sp.ok === true, json([k1.wynik, k2.wynik, sp]));

    // ── Podgląd ─────────────────────────────────────────────────────────────
    t.section('podgląd: ile zniknie w zakresie i językach');
    const pa = sonda('czysc', 'E', 'en', 'ai', 'podglad');
    t.check('tylko AI, EN: 4 (bez przyjętego e3); nic nie wyczyszczone', json(pa.podglad && pa.podglad.ile) === json({ en: 4 })
      && pa.podglad.razem === 4 && pa.wynik === undefined && (pa.pola || {})['e1|evk_tl_en__text'] === 'EN:Czyszczony nagłówek', json(pa.podglad));
    const pw = sonda('czysc', 'E', 'en,de', 'wszystkie', 'podglad');
    t.check('wszystkie, EN i DE: 6 + 6 — z przyjętym i ręcznym; bez {post_title} i elementu spoza mapy (AI ich nie przetłumaczy)',
      json(pw.podglad && pw.podglad.ile) === json({ en: 6, de: 6 }) && pw.podglad.razem === 12, json(pw.podglad));

    // ── Czyszczenie tylko AI ────────────────────────────────────────────────
    t.section('czyszczenie: tylko tłumaczenia AI w EN');
    const c1 = sonda('czysc', 'E', 'en', 'ai');
    const p1 = c1.pola || {};
    t.check('wynik: 4 wyczyszczone w EN, 4 wyniki zapomniane', json(c1.wynik) === json({ wyczyszczone: { en: 4 }, razem: 4, zapomniane: 4 }),
      json(c1.wynik));
    t.check('pola: EN od AI puste; przyjęty, ręczny, {post_title}, spoza mapy i całe DE zostają',
      !('e1|evk_tl_en__text' in p1) && !('e2|evk_tl_en__text' in p1) && !('e7|items.q1.evk_tl_en__title' in p1)
      && p1['e3|evk_tl_en__text'] === 'EN:Sprawdzony nagłówek' && p1['e4|evk_tl_en__text'] === 'Manual heading'
      && p1['e5|evk_tl_en__text'] === '{post_title}' && p1['e6|evk_tl_en__text'] === 'Outside the map'
      && p1['e1|evk_tl_de__text'] === 'DE:Czyszczony nagłówek', json(p1));
    t.check('lista „Do sprawdzenia”: zostały tylko tłumaczenia AI w DE', json(c1.do_sprawdzenia) === json(DE_AI), json(c1.do_sprawdzenia));
    const kop = (c1.kopia || {})['e2|text|en'] || {};
    t.check('kopia: 4 miejsca z tekstem i stanem (AI, model); ukośniki wsteczne w tekście całe',
      Object.keys(c1.kopia || {}).length === 4 && kop.t === 'EN:' + E2_PL && (kop.s || {}).src === 'ai' && (kop.s || {}).model === 'claude/claude-opus-5-5',
      json(kop));
    const m1 = sonda('pamiec', TEKSTY);
    t.check('pamięć wyników: teksty EN strony zapomniane; DE tej strony i strona C zostają',
      json(m1.teksty) === json({ 'en|Czyszczony nagłówek': 0, ['en|' + E2_PL]: 0, 'de|Czyszczony nagłówek': 1, 'en|Oferta specjalna': 1 }),
      json(m1.teksty));

    t.section('ten sam model po czyszczeniu: zapytanie do AI');
    const k3 = sonda('krok', 'claude', 'en', 'E');
    t.check('jedno zapytanie, 4 z AI (wcześniej ten sam model dawał wynik z pamięci bez pytania)',
      (k3.zadania || []).length === 1 && (k3.wynik || {}).z_ai === 4 && (k3.wynik || {}).z_pamieci === 0, json([k3.wynik, (k3.zadania || []).length]));
    const s3 = (k3.stan || {})['e1|text|en'] || {};
    t.check('ten sam tekst co wyczyszczony: AI „Do sprawdzenia”, bez poprzedniej wersji (nie ma czego przywracać)',
      s3.src === 'ai' && !('poprz' in s3) && (k3.pola || {})['e1|evk_tl_en__text'] === 'EN:Czyszczony nagłówek', json(s3));

    t.section('inny model po czyszczeniu: wyczyszczony tekst jako poprzednia wersja');
    sonda('czysc', 'E', 'en', 'ai');
    const k4 = sonda('krok-opcje', 'E', 'en', json({ dostawca: 'gemini', model: 'gemini-test-b' }));
    const s4 = (k4.stan || {})['e2|text|en'] || {};
    t.check('pole: tekst Gemini; poprzednio: wyczyszczony tekst Claude z modelem, ukośniki całe',
      (k4.pola || {})['e2|evk_tl_en__text'] === 'EN[gemini-test-b]:' + E2_PL
      && json(s4.poprz) === json({ t: 'EN:' + E2_PL, m: 'claude/claude-opus-5-5' }), json([(k4.pola || {})['e2|evk_tl_en__text'], s4]));
    /* Stan zapisuje się przy każdym zapisie treści (52) i przy „Sprawdzone” —
       update_post_meta zdejmuje ukośniki, jeśli nikt ich nie podwoił. */
    const poprzE2 = () => (((sonda('czysc', 'E', 'en', 'ai', 'podglad').stan || {})['e2|text|en'] || {}).poprz || {});
    sonda('sprawdzone', 'E', 'e1|text|en');
    const s4s = poprzE2();
    t.check('poprzednia wersja po „Sprawdzone” innego miejsca: ukośniki całe', s4s.t === 'EN:' + E2_PL, json(s4s));
    const w4 = sonda('wpisz', 'E', 'e4|text|de', 'DE ręcznie');
    const s4b = poprzE2();
    t.check('… i po zapisie treści (stan liczony od nowa): ukośniki dalej całe', (w4.zapisane || []).length === 1 && s4b.t === 'EN:' + E2_PL,
      json(s4b));

    // ── Przywracanie ────────────────────────────────────────────────────────
    t.section('„Przywróć wyczyszczone”: do pustych pól, ze stanem');
    const c5 = sonda('czysc', 'E', 'en', 'ai');
    t.check('wyczyszczone 3 tłumaczenia Gemini (e1 przyjęty zostaje)', (c5.wynik || {}).razem === 3
      && (c5.pola || {})['e1|evk_tl_en__text'] === 'EN[gemini-test-b]:Czyszczony nagłówek',
      json(c5.wynik));
    sonda('wpisz', 'E', 'e7|items.q1.title|en', 'Wpisane po czyszczeniu');
    const r1 = sonda('przywroc', 'E');
    const rp = r1.pola || {};
    const rs = (r1.stan || {})['e2|text|en'] || {};
    t.check('przywrócone 2, pominięte to, które wypełniono od czasu czyszczenia (i e1 z wcześniejszej kopii — pole pełne)',
      json(r1.wynik) === json({ przywrocone: 2, pominiete: 2 }) && rp['e7|items.q1.evk_tl_en__title'] === 'Wpisane po czyszczeniu'
      && rp['e2|evk_tl_en__text'] === 'EN[gemini-test-b]:' + E2_PL && rp['e7|items.q1.evk_tl_en__content'] === '<p>EN[gemini-test-b]:Czysta odpowiedź.</p>',
      json([r1.wynik, rp]));
    t.check('stan przywrócony w całości: AI, model Gemini, poprzednia wersja Claude — znów „Do sprawdzenia”',
      rs.src === 'ai' && rs.model === 'gemini/gemini-test-b' && (rs.poprz || {}).t === 'EN:' + E2_PL
      && (r1.do_sprawdzenia || []).includes('e2|text|en') && (r1.do_sprawdzenia || []).includes('e7|items.q1.content|en')
      && !(r1.do_sprawdzenia || []).includes('e7|items.q1.title|en'), json([rs, r1.do_sprawdzenia]));
    const r2 = sonda('przywroc', 'E');
    t.check('drugi raz: nic do przywrócenia (pola pełne), kopia zostaje', json(r2.wynik) === json({ przywrocone: 0, pominiete: 4 })
      && Object.keys(r2.kopia || {}).length === 4, json([r2.wynik, Object.keys(r2.kopia || {})]));

    t.section('wszystkie tłumaczenia w DE: kopia łączy się z poprzednią');
    const c6 = sonda('czysc', 'E', 'de', 'wszystkie');
    t.check('wyczyszczone 6 w DE (także wpisane ręcznie), EN nietknięte', json((c6.wynik || {}).wyczyszczone) === json({ de: 6 })
      && !Object.keys(c6.pola || {}).some((k) => k.includes('evk_tl_de__')) && (c6.pola || {})['e4|evk_tl_en__text'] === 'Manual heading',
      json(c6.pola));
    t.check('kopia: 4 miejsca EN z poprzedniego czyszczenia + 6 DE; DE ręcznie wpisane w kopii', Object.keys(c6.kopia || {}).length === 10
      && ((c6.kopia || {})['e4|text|de'] || {}).t === 'DE ręcznie', json(Object.keys(c6.kopia || {})));
    t.check('„Przywróć” widzi 6 do przywrócenia (EN pełne)', (c6.po || {}).kopia === 6, json(c6.po));
    const m6 = sonda('pamiec', TEKSTY);
    t.check('pamięć: DE strony zapomniane, strona C dalej ma wynik', m6.teksty['de|Czyszczony nagłówek'] === 0 && m6.teksty['en|Oferta specjalna'] === 1,
      json(m6.teksty));

    // ── „Pytaj AI od nowa” ──────────────────────────────────────────────────
    t.section('„Pytaj AI od nowa”: zapytanie mimo wyniku w pamięci');
    const b0 = sonda('krok-opcje', 'C', 'en', json({ ponownie: true }));
    t.check('bez opcji: od nowa tym samym modelem — z pamięci, bez zapytania, 2 bez zmian', b0.zadania === 0 && (b0.wynik || {}).bez_zmian === 2,
      json([b0.wynik, b0.zadania]));
    sonda('pamiec-stara');
    t.check('w pamięci wpis w starym kluczu (sprzed 1.264.0)', sonda('pamiec').stare === 1);
    const b1 = sonda('krok-opcje', 'C', 'en', json({ ponownie: true, bez_pamieci: true }));
    t.check('z opcją: jedno zapytanie (atrapa odpowiada tym samym tekstem, więc dalej bez zmian)', b1.zadania === 1 && (b1.wynik || {}).bez_zmian === 2,
      json([b1.wynik, b1.zadania]));
    const m7 = sonda('pamiec', TEKSTY);
    t.check('zapis wyniku sprząta stary klucz, nowy wynik w pamięci', m7.stare === 0 && m7.teksty['en|Oferta specjalna'] === 1, json(m7));

    // ── AJAX ────────────────────────────────────────────────────────────────
    t.section('AJAX: uprawnienia, obcy język, czyszczenie i przywracanie');
    const a0 = sonda('ajax-czysc', 'czytelnik', 'E', 'wykonaj');
    t.check('dostęp do Tłumaczeń bez prawa edycji strony: odmowa, pola bez zmian', (a0.odp || {}).success === false
      && (a0.odp || {}).data === 'Brak uprawnień do tej strony.' && (a0.pola || {})['e2|evk_tl_en__text'] === 'EN[gemini-test-b]:' + E2_PL, json(a0.odp));
    const a1 = sonda('ajax-czysc', 'admin', 'E', 'podglad');
    t.check('podgląd przez AJAX (język spoza listy pominięty): EN 2', json(((a1.odp || {}).data || {}).ile) === json({ en: 2 }), json(a1.odp));
    const a2 = sonda('ajax-czysc', 'admin', 'E', 'wykonaj');
    t.check('czyszczenie przez AJAX: 2 wyczyszczone, pola puste', ((a2.odp || {}).data || {}).razem === 2 && !('e2|evk_tl_en__text' in (a2.pola || {})),
      json(a2.odp));
    const a3 = sonda('ajax-czysc', 'tlumacz', 'E', 'przywroc');
    t.check('przywracanie przez AJAX (redaktor z dostępem do Tłumaczeń)', ((a3.odp || {}).data || {}).przywrocone === 8
      && (a3.pola || {})['e2|evk_tl_en__text'] === 'EN[gemini-test-b]:' + E2_PL, json(a3.odp));

    // ── Panel w przeglądarce ────────────────────────────────────────────────
    t.section('panel: wybór strony, potwierdzenie, lista hurtu, przywracanie');
    const mu = sonda('mu');
    sonda('scenariusz', 'ok');
    t.check('atrapa w serwerze testowym (mu-plugin)', mu.mu === true, json(mu));
    serwer = await serwerWp.start(wp.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    const bledy = [];
    p.on('pageerror', (x) => bledy.push(x.message));
    const dialogi = [];
    p.on('dialog', (d) => { dialogi.push(d.message()); d.accept(); });
    await serwerWp.zaloguj(p, serwer.baza);
    await p.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=ai');
    /* Tabela zakresu startuje pusta (1.272.0) — treść Bricksa stron zaznacza test. */
    await p.check('.tl-ai-czesc[data-typ="page"][value="bricks"]');
    const opcje = await p.$$eval('#tl-ai-czysc-strona option', (o) => o.map((x) => x.textContent));
    t.check('lista stron: każda z danymi Bricksa, także w pełni przetłumaczona B', ['Strona AI A (Treść)', 'Strona AI B (Treść)', 'Strona AI E (Treść)']
      .every((x) => opcje.includes(x)), json(opcje));
    /* Nazwy dostępne z drzewa dostępności Chromium. Strażnik etykiet rysuje
       zakładki na atrapach bez modułu 53, więc pola czyszczenia nie widzi. */
    const box = p.locator('.tl-ai-czysc');
    const rola = (r, n) => box.getByRole(r, { name: n, exact: true }).count();
    const nazwy = [await rola('combobox', 'Strona'), await rola('radiogroup', 'Co usunąć'),
      await rola('radio', 'Tylko tłumaczenia AI „Do sprawdzenia”'), await rola('radio', 'Wszystkie tłumaczenia — także sprawdzone i wpisane ręcznie'),
      await rola('group', 'Języki do wyczyszczenia'), await rola('checkbox', 'EN — Angielski'), await rola('checkbox', 'DE — Niemiecki'),
      await rola('button', 'Wyczyść…'), await p.getByRole('checkbox', { name: 'Pytaj AI od nowa (bez pamięci wyników)', exact: true }).count()];
    t.check('nazwy dostępne: wybór strony, zakres, języki, przyciski i „Pytaj AI od nowa”', nazwy.every((x) => x === 1), json(nazwy));
    const czekajNaStan = (wzor) => p.waitForFunction((w) => new RegExp(w).test(document.querySelector('.tl-ai-czysc-stan').textContent),
      wzor, { timeout: 10000 }).catch(() => {});
    await p.selectOption('#tl-ai-czysc-strona', e.E + '|_bricks_page_content_2');
    await czekajNaStan('^Do wyczyszczenia');
    /* EN: e2 i treść pozycji (przywrócone jako AI), DE: pięć przywróconych
       jako AI — DE wpisane ręcznie przy e4 wróciło jako sprawdzone. */
    t.check('po wyborze strony: liczby w zakresie i językach, w kolejności języków', (await p.textContent('.tl-ai-czysc-stan'))
      === 'Do wyczyszczenia: 7 (EN: 2, DE: 5).', await p.textContent('.tl-ai-czysc-stan'));
    await p.click('.tl-ai-czysc-start');
    await czekajNaStan('^Wyczyszczone');
    t.check('potwierdzenie z nazwą strony i liczbami', dialogi.length === 1 && dialogi[0].includes('„Strona AI E (Treść)”')
      && dialogi[0].includes('Do usunięcia: 7 (EN: 2, DE: 5).'), json(dialogi));
    t.check('stan: wyczyszczone i gdzie dalej', /^Wyczyszczone: 7 \(EN: 2, DE: 5\)\. Strona jest zaznaczona/.test(await p.textContent('.tl-ai-czysc-stan')),
      await p.textContent('.tl-ai-czysc-stan'));
    await p.waitForFunction(() => document.querySelector('.tl-ai-wybor'), null, { timeout: 10000 }).catch(() => {});
    const zazn = await p.$$eval('.tl-ai-wybor', (w) => w.map((c) => (c.checked ? '*' : '') + c.getAttribute('aria-label')));
    t.check('lista hurtu pokazana, zaznaczona tylko wyczyszczona strona', zazn.length > 1
      && json(zazn.filter((x) => x[0] === '*')) === json(['*Tłumacz: Strona AI E (Treść)']), json(zazn));
    const podP = sonda('czysc', 'E', 'en,de', 'ai', 'podglad');
    const etykieta = await p.textContent('.tl-ai-czysc-przywroc');
    t.check('„Przywróć wyczyszczone” z liczbą pustych pól z kopii', etykieta === 'Przywróć wyczyszczone (' + (podP.podglad || {}).kopia + ')'
      && (podP.podglad || {}).kopia > 0, json([etykieta, podP.podglad]));
    await p.click('.tl-ai-czysc-przywroc');
    await czekajNaStan('^Przywrócone');
    const poP = sonda('pola', 'E');
    t.check('przywrócone w przeglądarce: stan i pola', (await p.textContent('.tl-ai-czysc-stan')).startsWith('Przywrócone: ' + (podP.podglad || {}).kopia + '.')
      && (poP.pola || {})['e2|evk_tl_en__text'] === 'EN[gemini-test-b]:' + E2_PL, json([await p.textContent('.tl-ai-czysc-stan'), poP.pola]));

    t.section('panel: „Pytaj AI od nowa” w przebiegu');
    await p.check('input[name="tl-ai-tryb"][value="ponownie"]');
    await p.uncheck('.tl-ai-jezyk[value="de"]');
    /* Pamięć strony C to wyniki Claude (krok na początku), a ustawienia po
       kroku „mu” mają Gemini — przebieg Claude trafia w pamięć. */
    await p.selectOption('#tl-ai-przebieg-dostawca', 'claude');
    const przebiegC = async (nowe) => {
      sonda('scenariusz', 'ok');
      if (nowe) await p.check('#tl-ai-bez-pamieci'); else await p.uncheck('#tl-ai-bez-pamieci');
      await p.click('.tl-ai-lista');
      await p.waitForFunction(() => !document.querySelector('.tl-ai-lista').disabled && document.querySelector('.tl-ai-wybor'), null, { timeout: 15000 }).catch(() => {});
      await p.$$eval('.tl-ai-wybor', (w) => w.forEach((c) => { c.checked = /Strona AI C /.test(c.getAttribute('aria-label')); }));
      await p.click('.tl-ai-start');
      await p.waitForFunction(() => !document.querySelector('.tl-ai-start').disabled && /^(Gotowe\.|Zatrzymane\.)$/.test(
        document.querySelector('.tl-ai-stan').textContent), null, { timeout: 30000 }).catch(() => {});
      return { zadania: (sonda('zadania').zadania || []).length, dziennik: await p.$$eval('.tl-ai-dziennik li', (l) => l.map((x) => x.textContent)) };
    };
    const bez = await przebiegC(false);
    t.check('bez opcji: bez zapytania, „bez zmian” i podpowiedź o „Pytaj AI od nowa”', bez.zadania === 0
      && /Zaznacz „Pytaj AI od nowa”/.test(bez.dziennik[bez.dziennik.length - 1] || ''), json(bez));
    const z = await przebiegC(true);
    t.check('z opcją: zapytanie poszło; model odpowiedział tym samym — dziennik mówi to wprost', z.zadania === 1
      && z.dziennik[z.dziennik.length - 1] === 'Bez zmian: model odpowiedział tym samym tekstem co obecny.', json(z));
    t.check('bez błędów JS', bledy.length === 0, bledy.slice(0, 3).join(' | '));

    t.section('telefon 360 px: pole czyszczenia');
    const km = await browser.newContext({ viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
    const m = await km.newPage();
    await serwerWp.zaloguj(m, serwer.baza);
    await m.goto(serwer.baza + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=ai');
    const tel = await m.evaluate(() => {
      /* Szerokość ekranu z clientWidth — innerWidth w emulacji telefonu rośnie
         razem z treścią szerszą niż ekran (1.263.0). */
      const W = document.documentElement.clientWidth;
      const box = document.querySelector('.tl-ai-czysc');
      const wystaja = [...box.querySelectorAll('*')].filter((x) => x.getBoundingClientRect().right > W + 1).map((x) => x.className || x.tagName);
      const cele = [...box.querySelectorAll('button, select, label.evo-check-row')].map((x) => x.getBoundingClientRect())
        .filter((r) => r.width < 24 || r.height < 24).length;
      return { W, wystaja, cele, font: parseFloat(getComputedStyle(document.getElementById('tl-ai-czysc-strona')).fontSize) };
    });
    t.check('nic nie wystaje, wybór strony co najmniej 16 px, cele dotyku co najmniej 24×24', tel.wystaja.length === 0 && tel.font >= 16 && tel.cele === 0,
      json(tel));
    await km.close();

    /* Poprawka w okienku sprawdzania (62) odkłada poprzednią wersję — ten sam
       zapis stanu, ta sama pułapka z ukośnikami. */
    t.section('okienko sprawdzania (62): poprzednia wersja z ukośnikami');
    const o62 = sonda('ajax-sprawdz', 'E', 'e2', 'Poprawione w okienku', 'en');
    const s62 = ((o62.stan || {})['e2|text|en'] || {}).poprz || {};
    t.check('zapis z okienka: nowy tekst, poprzednia wersja z ukośnikami całymi', (o62.odp || {}).success === true
      && (o62.pola || {})['e2|evk_tl_en__text'] === 'Poprawione w okienku' && s62.t === 'EN[gemini-test-b]:' + E2_PL, json([o62.odp, s62]));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
