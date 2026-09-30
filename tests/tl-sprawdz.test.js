/**
 * Sprawdzanie tłumaczeń wprost na stronie (1.261.0) — tryb ?evk_tl_sprawdz=1
 * na wersji językowej: obrysy, okienko przy elemencie, zapis przez AJAX.
 *
 * Zgłoszone (29.09): „Czy jest możliwość edycji tekstów bezpośrednio na
 * stronie do sprawdzenia? Bardzo by to ułatwiło systemowe tłumaczenie
 * hurtowe.” Decyzje zgłaszającego:
 *   - okienko przy klikniętym elemencie;
 *   - obrys „Do sprawdzenia” i „Brak tłumaczenia”, klikać da się każdy tekst;
 *   - „Zapisz” oznacza cały element jako sprawdzony;
 *   - po zapisie okienko zostaje, fokus na „Następne”.
 *
 * Bricksa tu nie ma: mu-plugin (sonda tests/php/tl-sprawdz.php, krok „mu”)
 * rysuje stronę jak Bricks — każdy element przez PRAWDZIWY filtr
 * `bricks/element/settings`, id `brxe-{id}` albo własne CSS ID, szablony
 * nagłówka i stopki w `\Bricks\Database::$active_templates`. Czego stąd nie
 * widać: identyfikatorów w HTML-u prawdziwego Bricksa, pętli i szablonów na
 * żywej stronie — to punkty „Do sprawdzenia na testowej”.
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

/** Czeka na „Zapisano.” w okienku (albo na błąd) — zwraca napis stanu. */
async function poZapisie(p) {
  await p.waitForFunction(() => {
    const s = document.querySelector('.evk-tls-okno .evk-tls-stan');
    return s && (s.textContent === 'Zapisano.' || s.classList.contains('evk-tls-blad'));
  }, null, { timeout: 15000 }).catch(() => {});
  return p.evaluate(() => { const s = document.querySelector('.evk-tls-okno .evk-tls-stan'); return s ? s.textContent : ''; });
}
/** Błędy JS strony. Bez błędów skryptów z /wp-admin/: logowanie przenosi
    konto bez Kokpitu na profile.php, a tamtejszy user-profile.js (rdzeń) przy
    wyjściu ze strony czyta formularz ustawiany dopiero w swoim „ready” — przy
    goto zaraz po wczytaniu rzuca TypeError („reading 'serialize'”). */
function lapBledy(p) {
  const bledy = [];
  p.on('pageerror', (e) => { if (!/\/wp-admin\//.test(String(e.stack || ''))) bledy.push(e.message); });
  return bledy;
}
const otwarty = (p) => p.evaluate(() => (window.__evkTlSprawdz || {}).otwarty || null);
async function czekajNaOtwarty(p, id) {
  await p.waitForFunction((x) => window.__evkTlSprawdz && window.__evkTlSprawdz.otwarty === x, id, { timeout: 10000 }).catch(() => {});
  return otwarty(p);
}
/** Jak użytkownik: otwarte okienko zasłania kawałek strony, więc przed klikiem
    w następny element najpierw Esc. Klik w element widoczny obok otwartego
    okienka sprawdza sekcja akordeonu. */
async function otworzKlikiem(p, sel, id) {
  if (await p.evaluate(() => { const o = document.querySelector('.evk-tls-okno'); return !!o && !o.hidden; })) {
    await p.keyboard.press('Escape');
  }
  await p.locator(sel).first().click();
  return czekajNaOtwarty(p, id);
}

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress i atrapa renderu Bricksa');
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
    const W = prep.wpisy || {};
    t.check('sonda: moduł, strony, szablony, słownik, użytkownicy i atrapa renderu',
      mod.gotowe === true && prep.gotowe === true && mu.mu === true && !!(W.A && W.B && W.H && W.S), json([mod, prep.brak || W, mu]));
    if (prep.gotowe !== true || mu.mu !== true) return;

    serwer = await serwerWp.start(wp.wp);
    const b = serwer.baza;
    const adresA = b + '/en/?page_id=' + W.A + '&evk_tl_sprawdz=1';
    browser = await chromium.launch({ executablePath: chromiumPath() });

    // ── Kto i gdzie ──────────────────────────────────────────────────────
    t.section('kto i gdzie widzi tryb');
    const gosc = await (await fetch(adresA)).text();
    t.check('gość: bez danych trybu i bez skryptu', !gosc.includes('evk-tl-sprawdz-dane') && !gosc.includes('tl-sprawdz.js'),
      gosc.includes('evk-tl-sprawdz-dane') ? 'DANE U GOŚCIA' : 'czysto');

    const k = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const p = await k.newPage();
    const bledy = lapBledy(p);
    await serwerWp.zaloguj(p, b);
    await p.goto(b + '/?page_id=' + W.A + '&evk_tl_sprawdz=1');
    t.check('polska wersja: bez trybu (sprawdza się wersję językową)', await p.locator('#evk-tl-sprawdz-dane').count() === 0);
    const naPasku = await p.locator('#wp-admin-bar-evk-tl-sprawdz > a').first();
    const hrefPaska = (await naPasku.count()) ? await naPasku.getAttribute('href') : '';
    t.check('pasek admina na polskiej stronie: „Sprawdź tłumaczenia (EN)” → /en/ z trybem',
      (await naPasku.textContent().catch(() => '')) === 'Sprawdź tłumaczenia (EN)' && /\/en\//.test(hrefPaska || '') && /evk_tl_sprawdz=1/.test(hrefPaska || ''),
      hrefPaska);

    await p.goto(adresA);
    await p.waitForFunction(() => window.__evkTlSprawdz && window.__evkTlSprawdz.liczby && 'sprawdz' in window.__evkTlSprawdz.liczby, null, { timeout: 10000 }).catch(() => {});
    const st = await p.evaluate(() => ({ liczby: window.__evkTlSprawdz.liczby, id: Object.keys(window.__evkTlSprawdz.elementy).sort() }));
    t.check('liczniki: do sprawdzenia 3 (AI, zmieniony oryginał, akordeon), brak 4 (przycisk, własne ID, sekcja, nagłówek), tylko w builderze 1 (kopia)',
      json(st.liczby) === json({ sprawdz: 3, brak: 4, poza: 1, niema: 0 }), json(st.liczby));
    t.check('w danych: teksty treści, nagłówka, stopki i sekcji; bez {post_title}, kontenerów (także w kopii) i wstawek szablonów',
      json(st.id) === json(['a1', 'b1', 'cs1', 'dup1', 'fn1', 'h1', 'hn1', 'lp1', 'ok1', 's1', 'sx1', 't1']), json(st.id));
    const ozdoby = await p.$$eval('.evk-tls-el', (w) => w.map((x) => x.id + ':' + x.getAttribute('data-evk-tls')));
    t.check('obrysy według stanu: AI i zmiana → do sprawdzenia, brak → brak, ze słownika i przetłumaczone → bez obrysu',
      ozdoby.includes('brxe-h1:sprawdz') && ozdoby.includes('brxe-t1:sprawdz') && ozdoby.includes('brxe-a1:sprawdz')
      && ozdoby.includes('brxe-b1:brak') && ozdoby.includes('brxe-s1:ok') && ozdoby.includes('brxe-ok1:ok'), json(ozdoby));
    t.check('własne CSS ID znalezione, element w pętli dwa razy', ozdoby.includes('moj-naglowek:brak')
      && ozdoby.filter((x) => x === 'brxe-lp1:ok').length === 2, json(ozdoby));
    const obrys = await p.evaluate(() => ({
      sprawdz: getComputedStyle(document.getElementById('brxe-h1')).outlineStyle,
      brak: getComputedStyle(document.getElementById('brxe-b1')).outlineStyle,
      ok: getComputedStyle(document.getElementById('brxe-ok1')).outlineStyle,
    }));
    t.check('obrys widoczny: przerywany (do sprawdzenia), kropkowany (brak), bez obrysu (przetłumaczone)',
      obrys.sprawdz === 'dashed' && obrys.brak === 'dotted' && obrys.ok === 'none', json(obrys));
    const nast = await p.getAttribute('.evk-tls-strona', 'href');
    const koniec = await p.getAttribute('.evk-tls-koniec', 'href');
    t.check('„Następna strona do sprawdzenia” → strona B (AI) w trybie; „Zakończ” → ta sama strona bez parametru',
      (nast || '').includes('page_id=' + W.B) && (nast || '').includes('evk_tl_sprawdz=1') && /\/en\//.test(nast || '')
      && !(koniec || '').includes('evk_tl_sprawdz') && (koniec || '').includes('page_id=' + W.A), json([nast, koniec]));

    // ── Okienko i zapis ──────────────────────────────────────────────────
    t.section('okienko: tłumaczenie AI → poprawka → zapis');
    await p.click('#brxe-h1');
    const okno = p.locator('.evk-tls-okno');
    await okno.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
    const o = await p.evaluate(() => {
      const w = document.querySelector('.evk-tls-okno');
      const tx = (s) => (w.querySelector(s) || {}).textContent || '';
      return { tytul: tx('.evk-tls-tytul'), pl: tx('.evk-tls-pl'), skad: tx('.evk-tls-skad'),
        pole: (w.querySelector('textarea') || {}).value, znak: tx('.evk-tls-znak'),
        etykieta: w.querySelector('label[for="evk-tls-pole-0"]') ? w.querySelector('label[for="evk-tls-pole-0"]').textContent : '',
        rola: w.getAttribute('role'), przyciski: Array.from(w.querySelectorAll('.evk-tls-stopka button')).map((x) => x.textContent) };
    });
    t.check('okienko: tytuł, polski oryginał, pole EN z tłumaczeniem AI, znaczek stanu',
      o.tytul === 'Nagłówek · EN' && o.pl === 'PL Nasze usługi' && o.pole === 'EN: Our services' && o.znak === 'AI — do sprawdzenia', json(o));
    /* Strona C to kopia z tym samym identyfikatorem h1. Na stronie A element
       należy do A: najpierw kandydaci (strona, szablony), dopiero potem
       wyszukiwanie, które znalazłoby dwa wpisy („w kilku miejscach”). */
    t.check('element skopiowany na inną stronę — na oglądanej należy do niej (kandydaci przed wyszukiwaniem)',
      o.skad === 'Treść: TLS strona A', o.skad);
    t.check('dialog z nazwami: pole ma etykietę, przyciski Zapisz, Sprawdzone, Następne',
      o.rola === 'dialog' && o.etykieta === 'Tekst — EN' && json(o.przyciski) === json(['Zapisz', 'Sprawdzone', 'Następne ›']), json(o));
    await okno.locator('textarea').fill('Our services');
    await p.evaluate(() => { window.__evkTestBezPrzeladowania = true; });
    await okno.locator('.evk-tls-zapisz').click();
    const s1 = await poZapisie(p);
    const h1 = sonda('stan', 'A', 'h1');
    t.check('zapis: pole EN poprawione, element sprawdzony', s1 === 'Zapisano.' && (h1.pola || {}).text === 'Our services'
      && (h1.sprawdzone || {}).text === 'tak', json([s1, h1]));
    t.check('na stronie od razu nowy tekst (węzeł podmieniony, bez przeładowania), już bez obrysu',
      (await p.textContent('#brxe-h1')) === 'Our services' && (await p.getAttribute('#brxe-h1', 'data-evk-tls')) === 'ok'
      && await p.evaluate(() => window.__evkTestBezPrzeladowania === true));
    t.check('okienko zostaje z „Zapisano.”, fokus na „Następne”, licznik do sprawdzenia: 2',
      await okno.isVisible() && await p.evaluate(() => document.activeElement && document.activeElement.classList.contains('evk-tls-nastepne'))
      && (await p.evaluate(() => window.__evkTlSprawdz.liczby.sprawdz)) === 2);

    t.section('„Następne” i „Sprawdzone”');
    await p.keyboard.press('Enter');
    const t1Otw = await czekajNaOtwarty(p, 't1');
    t.check('Enter na „Następne” → następny do sprawdzenia w kolejności strony (zmieniony oryginał)',
      t1Otw === 't1' && (await okno.locator('.evk-tls-znak').textContent()) === 'Zmienił się polski tekst', t1Otw);
    t.check('przy HTML-u w oryginale — uwaga o znacznikach', (await okno.textContent()).includes('Znaczniki HTML zachowaj'));
    await okno.locator('.evk-tls-sprawdzone').click();
    const s2 = await poZapisie(p);
    const t1 = sonda('stan', 'A', 't1');
    t.check('„Sprawdzone”: tłumaczenie bez zmian, element sprawdzony', s2 === 'Zapisano.' && (t1.pola || {}).text === '<p>We design websites.</p>'
      && (t1.sprawdzone || {}).text === 'tak', json([s2, t1]));

    t.section('akordeon: cały element sprawdzony, przeładowanie i powrót do okienka');
    await p.click('#brxe-a1 h3');
    await czekajNaOtwarty(p, 'a1');
    const sciezki = await okno.locator('textarea').evaluateAll((w) => w.map((x) => x.getAttribute('data-sciezka')));
    t.check('okienko akordeonu: tytuł i treść obu pozycji', json(sciezki) === json(['accordions.p1.title', 'accordions.p1.content',
      'accordions.p2.title', 'accordions.p2.content']), json(sciezki));
    await okno.locator('textarea[data-sciezka="accordions.p2.title"]').fill('Second question');
    await p.evaluate(() => { window.__evkTestBezPrzeladowania = true; });
    await Promise.all([p.waitForNavigation({ timeout: 15000 }).catch(() => {}), okno.locator('.evk-tls-zapisz').click()]);
    const a1Otw = await czekajNaOtwarty(p, 'a1');
    const przeladowana = await p.evaluate(() => window.__evkTestBezPrzeladowania !== true);
    t.check('element z własnym skryptem → przeładowanie; potem okienko akordeonu otwarte z „Zapisano.”',
      przeladowana && a1Otw === 'a1' && (await p.textContent('.evk-tls-okno .evk-tls-stan')) === 'Zapisano.', json([przeladowana, a1Otw]));
    const a1 = sonda('stan', 'A', 'a1');
    t.check('druga pozycja zapisana; pierwsza (AI) sprawdzona razem z elementem; nowe pole w wykazie dopisanych',
      (a1.pola || {})['accordions.p2.title'] === 'Second question' && (a1.sprawdzone || {})['accordions.p1.title'] === 'tak'
      && (a1.dopisane || []).includes('a1|accordions.p2.evk_tl_en__title'), json(a1));
    /* Do sprawdzenia nic już nie ma, więc „Następne” idzie po brakach — od
       bieżącego miejsca w dół strony (własne CSS ID pod akordeonem), a nie od
       góry (nagłówek). */
    await p.keyboard.press('Enter');
    t.check('„Następne” po ostatnim do sprawdzenia: braki od bieżącego miejsca w dół strony',
      (await czekajNaOtwarty(p, 'cs1')) === 'cs1', await otwarty(p));

    t.section('brak tłumaczenia: odnośnik przycisku i nowe pole');
    const przed = p.url();
    t.check('klik w odnośnik otwiera okienko zamiast przejść', (await otworzKlikiem(p, '#brxe-b1', 'b1')) === 'b1' && p.url() === przed, p.url());
    await okno.locator('textarea').fill('Write to us');
    await okno.locator('.evk-tls-zapisz').click();
    await poZapisie(p);
    const b1 = sonda('stan', 'A', 'b1');
    t.check('nowe pole zapisane i w wykazie dopisanych (otwarty builder go nie zgubi)', (b1.pola || {}).text === 'Write to us'
      && (b1.dopisane || []).includes('b1|evk_tl_en__text'), json(b1));
    t.check('na stronie „Write to us”', (await p.textContent('#brxe-b1')) === 'Write to us');

    t.section('szablony: nagłówek i sekcja wstawiona na stronę');
    await otworzKlikiem(p, '#brxe-hn1', 'hn1');
    t.check('okienko mówi, skąd element: szablon nagłówka', (await okno.locator('.evk-tls-skad').textContent()) === 'Nagłówek: TLS nagłówek');
    await okno.locator('textarea').fill('Main menu');
    await okno.locator('.evk-tls-zapisz').click();
    await poZapisie(p);
    t.check('element nagłówka zapisany w szablonie nagłówka', (sonda('stan', 'H', 'hn1').pola || {}).text === 'Main menu');
    await otworzKlikiem(p, '#brxe-sx1', 'sx1');
    t.check('sekcja spoza kandydatów (jedno trafienie) — do edycji, ze swoim szablonem',
      (await okno.locator('.evk-tls-skad').textContent()) === 'Treść: TLS szablon sekcji' && await okno.locator('.evk-tls-zapisz').count() === 1);
    await okno.locator('textarea').fill('Shared section');
    await okno.locator('.evk-tls-zapisz').click();
    await poZapisie(p);
    t.check('zapisana w szablonie sekcji', (sonda('stan', 'S', 'sx1').pola || {}).text === 'Shared section');

    t.section('kopia, pętla, pusty tekst');
    await otworzKlikiem(p, '#brxe-dup1', 'dup1');
    t.check('element w dwóch kopiach: komunikat, bez pola i zapisu', (await okno.textContent()).includes('w kilku miejscach')
      && await okno.locator('textarea').count() === 0 && await okno.locator('.evk-tls-zapisz').count() === 0);
    await otworzKlikiem(p, '#brxe-lp1', 'lp1');
    t.check('element w pętli: uwaga, że zmiana dotyczy każdego wystąpienia', (await okno.textContent()).includes('występuje na stronie 2 razy'));
    await otworzKlikiem(p, '#brxe-ok1', 'ok1');
    await okno.locator('textarea').fill('');
    await okno.locator('.evk-tls-zapisz').click();
    await poZapisie(p);
    const ok1 = sonda('stan', 'A', 'ok1');
    t.check('pusty tekst usuwa tłumaczenie — strona wraca do polskiego', !('text' in (ok1.pola || {})) && (await p.textContent('#brxe-ok1')) === 'Gotowe',
      json(ok1));

    t.section('klawiatura');
    await p.keyboard.press('Escape');
    t.check('Esc zamyka okienko i oddaje fokus elementowi', await okno.isHidden()
      && await p.evaluate(() => document.activeElement && document.activeElement.id === 'brxe-ok1'));
    await p.focus('#brxe-t1');
    await p.keyboard.press('Enter');
    t.check('Enter na elemencie (tabindex dołożony w trybie) otwiera okienko', (await czekajNaOtwarty(p, 't1')) === 't1');
    await p.keyboard.press('Escape');
    await p.focus('#brxe-b1');
    await p.keyboard.press('Enter');
    await czekajNaOtwarty(p, 'b1');
    await okno.locator('textarea').press('End');
    await okno.locator('textarea').type(' now');
    await okno.locator('textarea').press('Control+Enter');
    await poZapisie(p);
    t.check('Ctrl+Enter zapisuje', (sonda('stan', 'A', 'b1').pola || {}).text === 'Write to us now');

    t.section('zły nonce');
    const zly = await p.evaluate(async () => {
      const d = JSON.parse(document.getElementById('evk-tl-sprawdz-dane').textContent);
      const e = d.elementy.h1;
      const fd = new FormData();
      [['action', 'evk_tl_sprawdz_zapisz'], ['nonce', 'zly'], ['post_id', e.post], ['meta_key', e.meta], ['lang', 'en'], ['element', 'h1'], ['pola[text]', 'NONCE']]
        .forEach(([x, y]) => fd.append(x, y));
      return (await fetch(d.ajax, { method: 'POST', body: fd, credentials: 'same-origin' })).status;
    });
    t.check('żądanie ze złym nonce odrzucone, pole bez zmian', zly === 403 && (sonda('stan', 'A', 'h1').pola || {}).text === 'Our services', String(zly));

    // ── Lista w panelu ───────────────────────────────────────────────────
    t.section('lista „Do sprawdzenia” w panelu: powód i „Na stronie”');
    const l = sonda('lista', b);
    const html = l.html || '';
    t.check('siedem kolumn, w tym „Powód”; strona B z powodem „AI”', (html.match(/<th scope="col">/g) || []).length === 7
      && html.includes('<th scope="col">Powód</th>') && /TLS strona B[\s\S]*?<td>AI<\/td>/.test(html), html.slice(0, 300));
    const naStronie = (html.match(/<a class="button" href="([^"]+)" aria-label="Na stronie: TLS strona B[^"]*">Na stronie<\/a>/) || [])[1] || '';
    const hrefNaStronie = naStronie.replace(/&amp;/g, '&').replace(/&#038;/g, '&');
    t.check('„Na stronie” → strona B w języku, w trybie, z kotwicą elementu', hrefNaStronie.includes('page_id=' + W.B)
      && hrefNaStronie.includes('evk_tl_sprawdz=1') && hrefNaStronie.endsWith('#evk-tls=hb') && /\/en\//.test(hrefNaStronie), hrefNaStronie);
    const wierszK1 = (html.match(/<tr>(?:(?!<\/tr>)[\s\S])*TLS kopia 1(?:(?!<\/tr>)[\s\S])*<\/tr>/) || [''])[0];
    t.check('szablon sekcji w liście bez „Na stronie” (nie ma jednej strony), z „Sprawdzone”',
      wierszK1 !== '' && !wierszK1.includes('>Na stronie<') && wierszK1.includes('tl-el-sprawdzone'), wierszK1.slice(0, 300));
    if (hrefNaStronie) {
      await p.goto(hrefNaStronie);
      t.check('kotwica z listy otwiera okienko elementu', (await czekajNaOtwarty(p, 'hb')) === 'hb');
      /* A już sprawdzona, a szablon sekcji z AI to nie strona — dalej nie ma dokąd. */
      t.check('„Następna strona” ukryta: innych stron do sprawdzenia brak (szablon się nie liczy)',
        await p.locator('.evk-tls-strona').isHidden(), await p.getAttribute('.evk-tls-strona', 'href'));
    }
    t.check('bez błędów JS (administrator)', bledy.length === 0, bledy.slice(0, 3).join(' | '));

    // ── Telefon ──────────────────────────────────────────────────────────
    t.section('telefon 360 px');
    const km = await browser.newContext({ viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true, deviceScaleFactor: 2 });
    const m = await km.newPage();
    await serwerWp.zaloguj(m, b);
    await m.goto(adresA);
    await m.waitForFunction(() => window.__evkTlSprawdz && window.__evkTlSprawdz.liczby && 'sprawdz' in window.__evkTlSprawdz.liczby, null, { timeout: 10000 }).catch(() => {});
    await m.tap('#brxe-t1');
    await czekajNaOtwarty(m, 't1');
    const tel = await m.evaluate(() => {
      const o = document.querySelector('.evk-tls-okno');
      const cele = Array.from(document.querySelectorAll('.evk-tls-ui button, .evk-tls-ui a, .evk-tls-ui label.evk-tls-wybor'))
        .filter((x) => x.offsetParent !== null).map((x) => x.getBoundingClientRect());
      return { arkusz: o.classList.contains('evk-tls-arkusz'), szer: document.documentElement.scrollWidth, okno: innerWidth,
        font: parseFloat(getComputedStyle(o.querySelector('textarea')).fontSize), male: cele.filter((r) => r.width < 24 || r.height < 24).length,
        dol: Math.round(o.getBoundingClientRect().bottom), wys: innerHeight };
    });
    t.check('okienko jako arkusz od dołu, bez przewijania strony w poziomie', tel.arkusz && tel.szer <= tel.okno && tel.dol === tel.wys, json(tel));
    t.check('pole co najmniej 16 px, cele dotyku co najmniej 24×24', tel.font >= 16 && tel.male === 0, json(tel));
    await km.close();

    // ── Uprawnienia i bezpieczeństwo ─────────────────────────────────────
    t.section('uprawnienia: dostęp do Tłumaczeń bez prawa edycji strony');
    const kb = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pb = await kb.newPage();
    const bledyB = lapBledy(pb);
    await serwerWp.zaloguj(pb, b, 'evk-t-tls-bez', 'evk-t-haslo-bez');
    await pb.goto(adresA);
    await pb.click('#brxe-h1');
    await czekajNaOtwarty(pb, 'h1');
    t.check('okienko tylko do podglądu: komunikat, pole tylko do odczytu, bez zapisu',
      (await pb.locator('.evk-tls-okno').textContent()).includes('Nie możesz edytować tej strony')
      && await pb.locator('.evk-tls-okno textarea').evaluate((x) => x.readOnly) && await pb.locator('.evk-tls-okno .evk-tls-zapisz').count() === 0);
    const odmowa = await pb.evaluate(async () => {
      const d = JSON.parse(document.getElementById('evk-tl-sprawdz-dane').textContent);
      const e = d.elementy.h1;
      const fd = new FormData();
      [['action', 'evk_tl_sprawdz_zapisz'], ['nonce', d.nonce], ['post_id', e.post], ['meta_key', e.meta], ['lang', 'en'], ['element', 'h1'], ['pola[text]', 'HACK']]
        .forEach(([x, y]) => fd.append(x, y));
      const r = await fetch(d.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
      return { status: r.status, tekst: await r.text() };
    });
    t.check('zapis wprost przez AJAX: 403 „Brak uprawnień do tej strony”, pole bez zmian', odmowa.status === 403
      && odmowa.tekst.includes('Brak uprawnie') && (sonda('stan', 'A', 'h1').pola || {}).text === 'Our services', json(odmowa));
    t.check('bez błędów JS (podgląd)', bledyB.length === 0, bledyB.slice(0, 3).join(' | '));
    await kb.close();

    t.section('bezpieczeństwo: tłumacz bez unfiltered_html');
    const kt = await browser.newContext({ viewport: { width: 1280, height: 900 } });
    const pt = await kt.newPage();
    await serwerWp.zaloguj(pt, b, 'evk-t-tls-tlumacz', 'evk-t-haslo-tlumacz');
    await pt.goto(adresA);
    await pt.click('#brxe-b1');
    await czekajNaOtwarty(pt, 'b1');
    await pt.locator('.evk-tls-okno textarea').fill('<strong>Write</strong><script>alert(1)</script> us');
    await pt.locator('.evk-tls-okno .evk-tls-zapisz').click();
    await poZapisie(pt);
    const bt = (sonda('stan', 'A', 'b1').pola || {}).text || '';
    t.check('tłumacz z prawem edycji zapisuje; skrypt wycięty (wp_kses_post), znaczniki treści zostają',
      bt.includes('<strong>Write</strong>') && !bt.includes('<script') && bt.includes(' us'), bt);

    /* Dostęp odebrany w trakcie pracy: nonce z otwartej strony jest ważny
       jeszcze kilka godzin, a prawo edycji strony zostaje — zapis blokuje
       dopiero sprawdzenie dostępu do Tłumaczeń. */
    t.section('odebrany dostęp do Tłumaczeń (prawo edycji strony zostaje)');
    const daneT = await pt.evaluate(() => {
      const d = JSON.parse(document.getElementById('evk-tl-sprawdz-dane').textContent);
      return { nonce: d.nonce, ajax: d.ajax, post: d.elementy.b1.post, meta: d.elementy.b1.meta };
    });
    const odebr = sonda('odbierz', 'evk-t-tls-tlumacz');
    await pt.goto(adresA);
    t.check('strona bez trybu: bez danych i bez przycisku na pasku admina', odebr.odebrane === true
      && await pt.locator('#evk-tl-sprawdz-dane').count() === 0 && await pt.locator('#wp-admin-bar-evk-tl-sprawdz').count() === 0, json(odebr));
    const poOdebraniu = await pt.evaluate(async (d) => {
      const fd = new FormData();
      [['action', 'evk_tl_sprawdz_zapisz'], ['nonce', d.nonce], ['post_id', d.post], ['meta_key', d.meta], ['lang', 'en'], ['element', 'b1'], ['pola[text]', 'PO ODEBRANIU']]
        .forEach(([x, y]) => fd.append(x, y));
      const r = await fetch(d.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
      return { status: r.status, tekst: await r.text() };
    }, daneT);
    t.check('zapis z nonce sprzed odebrania: 403 „Brak uprawnień.”, pole bez zmian', poOdebraniu.status === 403
      && poOdebraniu.tekst.includes('Brak uprawnie') && (sonda('stan', 'A', 'b1').pola || {}).text === bt, json(poOdebraniu));
    await kt.close();
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
