/**
 * Tłumaczenia kategorii, tagów i tekstów alternatywnych (1.253.0) na
 * PRAWDZIWYM WordPressie: ekran edycji termu i formularz dodawania w Chromium
 * (przełącznik, pola języka w miejscu oryginałów, zapis, AJAX dodawania bez
 * przecieku pól do następnego termu), kolumna „Języki", „Do sprawdzenia",
 * adres termu w mapie adresów (konflikty, zmiana polskiego adresu),
 * przeniesienie nazw ze słownika, alt na ekranie obrazu i w oknie mediów,
 * strona: nazwy i opisy w listach, menu, archiwum i alt obrazka.
 *
 * Do 1.252.0 nazwy termów tłumaczył wyłącznie słownik (tylko nazwa
 * identyczna z frazą), opisu i alt-u — nic.
 *
 * Środowisko: tools/testowy-wp.sh. Sonda: tests/php/tl-termy.php.
 */

const http = require('http');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('tl-termy.php', a, { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (v) => JSON.stringify(v);
/* Skrót jak evk_tlw_skrot(): bez znaczników i encji, odstępy scalone, md5, 12 znaków. */
const skrot = (t) => require('crypto').createHash('md5').update(String(t).replace(/<[^>]*>/g, '').replace(/\s+/g, ' ').trim()).digest('hex').slice(0, 12);

function pobierz(url) {
  return new Promise((ok) => {
    const w = { body: '' };
    const req = http.get(url, (r) => {
      w.status = r.statusCode;
      w.location = r.headers.location || '';
      r.on('data', (d) => { if (w.body.length < 600000) w.body += d.toString('utf8'); });
      r.on('end', () => ok(w));
    });
    req.on('error', (e) => { w.status = 'błąd ' + e.code; ok(w); });
    req.setTimeout(20000, () => { w.status = 'timeout'; req.destroy(); });
  });
}
const encje = (s) => String(s).replace(/&#8211;/g, '–').replace(/&#8212;/g, '—').replace(/&amp;/g, '&').replace(/&#039;/g, "'").replace(/&quot;/g, '"');
const tytulStrony = (html) => encje((((html.split('</head>')[0] || '').match(/<title>([^<]*)<\/title>/) || [])[1] || '')).trim();
const meta = (html, atr, nazwa) => encje((((html.split('</head>')[0] || '').match(new RegExp('<meta[^>]+' + atr + '="' + nazwa + '"[^>]*content="([^"]*)"')) || [])[1] || ''));

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress podany przez php -S');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;

  let serwer = null, browser = null;
  const bledy = [];
  try {
    serwer = await serwerWp.start(wp.wp);
    const b = serwer.baza;
    const prep = sonda('przygotuj ' + b);
    t.check('sonda przygotowała kategorie, tag, wpis i obrazek', !prep.brak && prep.gotowe === true, prep.brak || J(prep));
    if (prep.brak || !prep.gotowe) return;
    const plu = sonda('przeplucz ' + b);
    t.check('moduły termów i wpisów wczytane', plu.tl === true, J(plu));
    const T = prep.termy, W = prep.wpisy;

    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    p.on('pageerror', (e) => bledy.push(p.url().replace(b, '') + ': ' + e.message));
    await serwerWp.zaloguj(p, b);
    const edytujTerm = async (tax, id) => { await p.goto(b + '/wp-admin/term.php?taxonomy=' + tax + '&tag_ID=' + id, { waitUntil: 'load' }); };
    const zapiszTerm = async () => {
      await Promise.all([p.waitForNavigation({ waitUntil: 'load', timeout: 30000 }), p.click('#edittag .edit-tag-actions [type="submit"]')]);
    };
    const wys = (s) => p.$eval(s, (e) => e.offsetHeight).catch(() => -1);
    const stanTermu = (klucz) => ((((sonda('stan').termy || {})[klucz]) || {}).meta || {});

    // ── Edycja termu ────────────────────────────────────────────────────────
    t.section('edycja kategorii: przełącznik, pola języka w miejscu oryginałów, zapis');
    await edytujTerm('category', T.C1);
    const start = await p.evaluate(() => ({
      wcisniety: [...document.querySelectorAll('.evk-tlw-przelacznik .evk-tl-jezyk')].map((x) => x.dataset.lang + ':' + x.getAttribute('aria-pressed')),
      liczniki: [...document.querySelectorAll('.evk-tlw-przelacznik .evk-tl-jezyk')].map((x) => x.dataset.lang + '=' + ((x.querySelector('.evk-tl-licznik') || {}).textContent || '')),
      nadFormularzem: (() => { const a = document.querySelector('.evk-tlw-przelacznik'), z = document.querySelector('#edittag .form-table');
        return !!(a && z && (a.compareDocumentPosition(z) & Node.DOCUMENT_POSITION_FOLLOWING)); })(),
      /* Wiersz nazwy EN stoi tuż pod wierszem polskiej nazwy (skrypt przenosi go spod końca tabeli). */
      zaNazwa: (() => { const r = document.querySelector('.term-name-wrap'); let n = r && r.nextElementSibling; const out = [];
        while (n && n.classList.contains('evk-tlw-pole')) { out.push(n.dataset.lang + ':' + n.dataset.pole); n = n.nextElementSibling; } return out; })(),
    }));
    t.check('przełącznik nad tabelą formularza, na starcie polski', start.nadFormularzem && J(start.wcisniety) === J(['pl:true', 'en:false', 'de:false']), J(start));
    t.check('licznik: nazwa i opis z polskim tekstem, nic przetłumaczone', J(start.liczniki) === J(['pl=', 'en=0/2', 'de=0/2']), J(start.liczniki));
    t.check('wiersze nazwy EN i DE zaraz pod polską nazwą', J(start.zaNazwa) === J(['en:name', 'de:name']), J(start.zaNazwa));
    t.check('po polsku pola języków schowane', (await wys('#evk-tlw-en-name')) === 0 && (await wys('#evk-tlw-en-description')) === 0 && (await wys('#name')) > 0, '');

    await p.click('.evk-tlw-przelacznik [data-lang="en"]');
    const en = { nazwaPL: await wys('#name'), slugPL: await wys('#slug'), opisPL: await wys('#description'),
      nazwaEN: await wys('#evk-tlw-en-name'), slugEN: await wys('#evk-tlw-en-slug'), opisEN: await wys('#evk-tlw-en-description'), nazwaDE: await wys('#evk-tlw-de-name') };
    t.check('EN: nazwa, adres i opis EN w miejscu polskich, DE schowane', en.nazwaPL === 0 && en.slugPL === 0 && en.opisPL === 0
      && en.nazwaEN > 0 && en.slugEN > 0 && en.opisEN > 0 && en.nazwaDE === 0, J(en));
    t.check('EN: pod nazwą polski oryginał', (await p.textContent('.evk-tlw-term-name[data-lang="en"] .evk-tlw-oryginal')).replace(/\s+/g, ' ').trim() === 'PL: Usługi T',
      await p.textContent('.evk-tlw-term-name[data-lang="en"] .evk-tlw-oryginal'));
    t.check('EN: etykiety pól z kodem języka', (await p.textContent('label[for="evk-tlw-en-name"]')) === 'Nazwa EN'
      && (await p.textContent('label[for="evk-tlw-en-slug"]')) === 'Adres (slug) EN', '');

    await p.fill('#evk-tlw-en-name', 'Services T');
    await p.fill('#evk-tlw-en-slug', 'Services T');   // sanitize_title zrobi z tego services-t
    await p.fill('#evk-tlw-en-description', 'Services description T');
    t.check('licznik EN liczy na bieżąco', (await p.textContent('.evk-tlw-przelacznik [data-lang="en"] .evk-tl-licznik')) === '2/2',
      await p.textContent('.evk-tlw-przelacznik [data-lang="en"] .evk-tl-licznik'));
    await zapiszTerm();
    let st = sonda('stan');
    let m1 = ((st.termy || {}).C1 || {}).meta || {};
    t.check('zapis: nazwa i opis EN w metadanych termu, ze źródłem z polskiego tekstu',
      m1._evk_tl_en__name === 'Services T' && m1._evk_tl_en__description === 'Services description T'
        && m1._evk_tl_en__name__zrodlo === skrot('Usługi T') && m1._evk_tl_en__description__zrodlo === skrot('Opis usług T') && !m1._evk_tl_de__name, J(m1));
    t.check('zapis: adres EN w mapie adresów (uslugi-t → services-t)', (st.mapa || []).some((w) => w.pl === 'uslugi-t' && w.en === 'services-t'), J(st.mapa));
    t.check('po zapisie: polska nazwa bez zmian', ((st.termy || {}).C1 || {}).slug === 'uslugi-t', J((st.termy || {}).C1));

    // ── Dodawanie termu (AJAX) ──────────────────────────────────────────────
    t.section('dodawanie kategorii: pola języków, AJAX, bez przecieku do następnego termu');
    await p.goto(b + '/wp-admin/edit-tags.php?taxonomy=category', { waitUntil: 'load' });
    const dodaj = async () => {
      await Promise.all([p.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('action=add-tag'), { timeout: 20000 }),
        p.click('#addtag #submit')]);
      await p.waitForTimeout(400);
    };
    await p.fill('#tag-name', 'Nowa T');
    await p.click('.evk-tlw-przelacznik [data-lang="en"]');
    t.check('formularz dodawania, EN: pole nazwy EN w miejscu polskiej', (await wys('#tag-name')) === 0 && (await wys('#evk-tlw-en-name')) > 0, '');
    await p.fill('#evk-tlw-en-name', 'New T');
    /* Powrót do polskiego przed „Dodaj": pola języka są wtedy schowane,
       a WordPress po dodaniu czyści tylko widoczne — bez czyszczenia
       w skrypcie nazwa EN przeszłaby do następnego termu. */
    await p.click('.evk-tlw-przelacznik [data-lang="pl"]');
    await dodaj();
    const poDodaniu = await p.evaluate(() => ({
      jezyk: document.querySelector('#addtag').getAttribute('data-evk-tlw') || 'pl',
      pola: [...document.querySelectorAll('#addtag .evk-tlw-wejscie, #addtag .evk-tlw-slug')].map((e) => e.value).join(''),
    }));
    t.check('po dodaniu: pola języków wyczyszczone, widok wraca do polskiego', poDodaniu.jezyk === 'pl' && poDodaniu.pola === '', J(poDodaniu));
    await p.fill('#tag-name', 'Druga T');
    await dodaj();
    st = sonda('stan');
    t.check('dodany term ma nazwę EN, następny (bez EN) — nie dostaje jej po poprzednim', J(st.dodane) === J({ 'Nowa T': 'New T', 'Druga T': '' }), J(st.dodane));

    await p.goto(b + '/wp-admin/edit-tags.php?taxonomy=category', { waitUntil: 'load' });
    const kolumna = await p.$$eval('#the-list tr', (rs) => rs.filter((r) => /Usługi T/.test((r.querySelector('.row-title') || {}).textContent || ''))
      .map((r) => [...r.querySelectorAll('.evk-tlw-stan')].map((s) => s.className.replace('evk-tlw-stan ', '') + ':' + s.textContent.trim())));
    t.check('kolumna „Języki": EN gotowe, DE brak (z opisem dla czytnika)',
      J(kolumna) === J([['is-gotowe:ENAngielski: przetłumaczone 2 z 2', 'is-brak:DENiemiecki: brak tłumaczenia']]), J(kolumna));

    // ── Do sprawdzenia ──────────────────────────────────────────────────────
    t.section('„Do sprawdzenia" po zmianie polskiej nazwy');
    const zmN = sonda('zmien-term C1 name Usługi-T-nowe');
    await edytujTerm('category', T.C1);
    await p.click('.evk-tlw-przelacznik [data-lang="en"]');
    const sprawdzNazwe = await p.$eval('.evk-tlw-term-name[data-lang="en"] .evk-tlw-do-sprawdzenia', (e) => !e.hidden && e.offsetHeight > 0).catch(() => false);
    const sprawdzOpis = await p.$eval('.evk-tlw-term-description[data-lang="en"] .evk-tlw-do-sprawdzenia', (e) => !e.hidden && e.offsetHeight > 0).catch(() => true);
    t.check('nazwa EN „Do sprawdzenia", opis EN (polski bez zmian) — nie', zmN.wynik && sprawdzNazwe && !sprawdzOpis, J({ zmN, sprawdzNazwe, sprawdzOpis }));

    // ── Adresy: konflikt, zmiana polskiego adresu ──────────────────────────
    t.section('adres EN termu: konflikt i zmiana polskiego adresu');
    await p.fill('#evk-tlw-en-slug', 'strona-t');
    await zapiszTerm();
    const komunikat = ((await p.textContent('.notice-error').catch(() => '')) || '').replace(/\s+/g, ' ').trim();
    st = sonda('stan');
    t.check('człon EN równy polskiemu adresowi strony — odrzucony z wyjaśnieniem, mapa bez zmian',
      /Adres EN nie został zapisany/.test(komunikat) && /Strona T/.test(komunikat) && (st.mapa || []).some((w) => w.pl === 'uslugi-t' && w.en === 'services-t'),
      komunikat + ' ' + J(st.mapa));
    const zmS = sonda('zmien-term C1 slug uslugi-t-nowe');
    st = sonda('stan');
    t.check('zmiana polskiego adresu termu: pozycja mapy idzie za termem',
      zmS.slug === 'uslugi-t-nowe' && (st.mapa || []).some((w) => w.pl === 'uslugi-t-nowe' && w.en === 'services-t') && !(st.mapa || []).some((w) => w.pl === 'uslugi-t'),
      J(st.mapa));

    // ── Tag: podpowiedź i przeniesienie ze słownika ─────────────────────────
    t.section('tag: podpowiedź ze słownika, przeniesienie nazw ze słownika');
    await edytujTerm('post_tag', T.T1);
    await p.click('.evk-tlw-przelacznik [data-lang="en"]');
    const podp = await p.$eval('.evk-tlw-term-name[data-lang="en"] .evk-tlw-slownik', (e) => e.offsetHeight > 0 ? e.textContent.replace(/\s+/g, ' ').trim() : '').catch(() => '');
    t.check('pusta nazwa EN tagu: podpowiedź ze słownika', podp === 'Ze słownika: Label T Wstaw', J(podp));
    await p.goto(b + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=wpisy', { waitUntil: 'load' });
    await p.click('[data-tlw-tryb="podglad"]');
    await p.waitForFunction(() => /Do przeniesienia|Nic do/.test(document.querySelector('.tlw-slownik-stan').textContent), null, { timeout: 15000 }).catch(() => {});
    const wiersze = await p.$$eval('.tlw-slownik-wynik tbody tr', (rs) => rs.map((r) => [...r.cells].map((c) => c.textContent.trim()).join(' | ')));
    t.check('podgląd: nazwy termów, które słownik tłumaczy w całości',
      J(wiersze) === J(['Etykieta T (Tag) | Nazwa | EN | Etykieta T | Label T', 'Etykieta T (Tag) | Nazwa | DE | Etykieta T | Etikett T']), J(wiersze));
    const odnosnik = await p.$eval('.tlw-slownik-wynik tbody tr a', (a) => a.getAttribute('href')).catch(() => '');
    t.check('podgląd: odnośnik do edycji termu', odnosnik.includes('term.php') && odnosnik.includes('tag_ID=' + T.T1), odnosnik);
    if (wiersze.length) await p.click('[data-tlw-tryb="zapisz"]');   // pusty podgląd = przycisk wyłączony
    await p.waitForFunction(() => /Przeniesiono/.test(document.querySelector('.tlw-slownik-stan').textContent), null, { timeout: 15000 }).catch(() => {});
    const mt1 = stanTermu('T1');
    t.check('przeniesienie: nazwy EN i DE tagu w metadanych, ze źródłem',
      mt1._evk_tl_en__name === 'Label T' && mt1._evk_tl_de__name === 'Etikett T' && mt1._evk_tl_en__name__zrodlo === skrot('Etykieta T'), J(mt1));

    // ── Alt obrazka ─────────────────────────────────────────────────────────
    t.section('tekst alternatywny: ekran obrazu i okno mediów');
    await p.goto(b + '/wp-admin/post.php?post=' + W.A1 + '&action=edit', { waitUntil: 'load' });
    const nazwaEN = 'attachments[' + W.A1 + '][evk_tl_alt_en]', nazwaDE = 'attachments[' + W.A1 + '][evk_tl_alt_de]';
    const poleAlt = await p.evaluate(([n]) => { const e = document.querySelector('[name="' + n + '"]');
      const l = e && e.id ? document.querySelector('label[for="' + e.id + '"]') : null;
      return e ? { etykieta: l ? l.textContent.trim() : '', pomoc: ((e.closest('tr') || {}).textContent || '').replace(/\s+/g, ' ') } : null; }, [nazwaEN]);
    t.check('ekran obrazu: pole „Tekst alternatywny EN" z polskim alt-em w podpowiedzi',
      !!poleAlt && /Tekst alternatywny EN/.test(poleAlt.etykieta) && /Alt PL obrazka T/.test(poleAlt.pomoc), J(poleAlt));
    if (poleAlt) {   // bez pola nie ma czego wypełniać — reszta testu idzie dalej
      await p.fill('[name="' + nazwaEN + '"]', 'Alt EN image T');
      await Promise.all([p.waitForNavigation({ waitUntil: 'load', timeout: 30000 }), p.click('#publish')]);
    }
    t.check('ekran obrazu: zapis alt-u EN', (sonda('stan').alt || {}).en === 'Alt EN image T', J(sonda('stan').alt));

    await p.goto(b + '/wp-admin/upload.php?mode=grid', { waitUntil: 'load' });
    await p.click('.attachment[data-id="' + W.A1 + '"]', { timeout: 15000 }).catch(() => {});
    await p.waitForSelector('[name="' + nazwaDE + '"]', { timeout: 15000 }).catch(() => {});
    const wOknie = await p.$('[name="' + nazwaDE + '"]');
    t.check('okno mediów: pole „Tekst alternatywny DE"', !!wOknie, '');
    if (wOknie) {
      await p.fill('[name="' + nazwaDE + '"]', 'Alt DE Bild T');
      await Promise.all([p.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('save-attachment-compat'), { timeout: 20000 }).catch(() => {}),
        p.press('[name="' + nazwaDE + '"]', 'Tab')]);
      await p.waitForTimeout(300);
    }
    t.check('okno mediów: zapis alt-u DE (zmiana pola)', (sonda('stan').alt || {}).de === 'Alt DE Bild T', J(sonda('stan').alt));

    // ── Strona ──────────────────────────────────────────────────────────────
    t.section('strona: nazwy, opisy, menu i alt w języku');
    const fen = sonda('front en'), fde = sonda('front de'), fpl = sonda('front pl');
    t.check('EN: nazwa i opis kategorii (get_term)', fen.nazwa_c1 === 'Services T' && fen.opis_c1 === 'Services description T', J(fen));
    t.check('EN: listy kategorii i tagów wpisu, pozycja menu kategorii',
      fen.kategorie_p1 === 'Services T' && fen.tagi_p1 === 'Label T' && J(fen.menu) === J(['Services T']), J(fen));
    t.check('EN: alt obrazka z biblioteki', fen.alt === 'Alt EN image T', J(fen.alt));
    t.check('DE: kategoria bez tłumaczenia — polska, tag ze słownika przeniesiony, alt DE',
      fde.nazwa_c1 === 'Usługi-T-nowe' && fde.opis_c1 === 'Opis usług T' && fde.tagi_p1 === 'Etikett T' && fde.alt === 'Alt DE Bild T', J(fde));
    t.check('PL: wszystko polskie', fpl.nazwa_c1 === 'Usługi-T-nowe' && fpl.tagi_p1 === 'Etykieta T' && fpl.alt === 'Alt PL obrazka T', J(fpl));

    const arch = await pobierz(b + '/en/category/services-t/');
    t.check('archiwum EN pod przetłumaczonym adresem: 200, <title> z nazwy EN', arch.status === 200 && tytulStrony(arch.body).startsWith('Services T'),
      arch.status + ' ' + tytulStrony(arch.body));
    t.check('archiwum EN: opis meta i og:title z tłumaczenia',
      meta(arch.body, 'name', 'description') === 'Services description T' && meta(arch.body, 'property', 'og:title').startsWith('Services T'),
      J({ opis: meta(arch.body, 'name', 'description'), og: meta(arch.body, 'property', 'og:title') }));
    const wpisEN = await pobierz(b + '/en/wpis-t/');
    t.check('wpis EN: alt obrazka wyróżniającego po angielsku', wpisEN.status === 200 && wpisEN.body.includes('alt="Alt EN image T"') && !wpisEN.body.includes('alt="Alt PL obrazka T"'),
      wpisEN.status + ' ' + J((wpisEN.body.match(/alt="[^"]*"/g) || []).slice(0, 6)));
    const archPL = await pobierz(b + '/category/uslugi-t-nowe/');
    t.check('archiwum PL: polska nazwa', archPL.status === 200 && tytulStrony(archPL.body).startsWith('Usługi-T-nowe'), archPL.status + ' ' + tytulStrony(archPL.body));

    t.check('bez błędów JS w panelu', !bledy.length, bledy.join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
