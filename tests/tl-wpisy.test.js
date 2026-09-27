/**
 * Tłumaczenia wpisów i stron (1.252.0) na PRAWDZIWYM WordPressie: klasyczny
 * edytor w Chromium (przełącznik nad tytułem, pola języka w miejscu
 * oryginałów, drugi edytor treści, zapis), strona przez serwer HTTP (tytuł,
 * treść na dwie strony, adres z mapy, <title>), „Do sprawdzenia", konflikty
 * adresów, zmiana polskiego adresu, kolumna „Języki", podpowiedź i przeniesienie
 * ze słownika.
 *
 * Do 1.251.0 tytuł, treść i zajawkę wpisu tłumaczył wyłącznie słownik — i to
 * tylko tekst identyczny z frazą. Adres wpisu dało się przetłumaczyć tylko
 * w zakładce „Slugi URL", bez związku z wpisem.
 *
 * Środowisko: tools/testowy-wp.sh. Sonda: tests/php/tl-wpisy.php (klasyczny
 * edytor włącza mu-plugin na czas testu — tak pracuje zgłaszający).
 */

const http = require('http');
const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (a) => {
  const s = phpOutput('tl-wpisy.php', a, { dopuscBlad: true });
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
const tytulStrony = (html) => (((html.split('</head>')[0] || '').match(/<title>([^<]*)<\/title>/) || [])[1] || '')
  .replace(/&#8211;/g, '–').replace(/&#8212;/g, '—').replace(/&amp;/g, '&').trim();
/** Tekst treści strony (body bez skryptów i znaczników). */
const tekstCiala = (html) => ((html.split('<body')[1] || '').replace(/<script[\s\S]*?<\/script>/g, '').replace(/<style[\s\S]*?<\/style>/g, '')
  .replace(/<[^>]+>/g, ' ').replace(/&#8217;/g, '’').replace(/\s+/g, ' '));

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
    t.check('sonda przygotowała wpisy i strony', !prep.brak && prep.gotowe === true, prep.brak || J(prep));
    if (prep.brak || !prep.gotowe) return;
    const plu = sonda('przeplucz ' + b);
    t.check('moduł wpisów wczytany', plu.tl === true, J(plu));
    const W = prep.wpisy;

    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
    p.on('pageerror', (e) => bledy.push(p.url().replace(b, '') + ': ' + e.message));
    await serwerWp.zaloguj(p, b);
    const edytuj = async (id) => { await p.goto(b + '/wp-admin/post.php?post=' + id + '&action=edit', { waitUntil: 'load' }); };
    const zapisz = async () => {
      await Promise.all([p.waitForNavigation({ waitUntil: 'load', timeout: 30000 }), p.click('#publish')]);
    };
    const widok = () => p.evaluate(() => {
      const h = (s) => { const e = document.querySelector(s); return e ? e.offsetHeight : -1; };
      return {
        jezyk: document.querySelector('#post').getAttribute('data-evk-tlw') || 'pl',
        wcisniety: [...document.querySelectorAll('.evk-tlw-przelacznik .evk-tl-jezyk')].map((x) => x.dataset.lang + ':' + x.getAttribute('aria-pressed')),
        liczniki: [...document.querySelectorAll('.evk-tlw-przelacznik .evk-tl-jezyk')].map((x) => x.dataset.lang + '=' + ((x.querySelector('.evk-tl-licznik') || {}).textContent || '')),
        tytulPL: h('#titlewrap'), tytulEN: h('#evk-tlw-en-post_title'), adresPL: h('#edit-slug-box'), adresEN: h('#evk-tlw-en-post_name'),
        trescPL: h('#postdivrich'), trescEN: h('#wp-evk-tlw-en-post_content-wrap'), zajawkaPL: h('#excerpt'), zajawkaEN: h('#evk-tlw-en-post_excerpt'),
        zajawkaWPudelku: !!document.querySelector('#postexcerpt #evk-tlw-en-post_excerpt'),
        edytorEN: !!(window.tinymce && window.tinymce.get('evk-tlw-en-post_content')),
        dodajMedium: !!document.querySelector('#wp-evk-tlw-en-post_content-wrap .insert-media'),
        przelacznikNadTytulem: (() => { const a = document.querySelector('.evk-tlw-przelacznik'), z = document.querySelector('#titlediv');
          return !!(a && z && (a.compareDocumentPosition(z) & Node.DOCUMENT_POSITION_FOLLOWING)); })(),
      };
    });

    // ── Klasyczny edytor ────────────────────────────────────────────────────
    t.section('edytor wpisu: przełącznik nad tytułem');
    await edytuj(W.W1);
    let v = await widok();
    t.check('przełącznik nad tytułem, na starcie polski', v.przelacznikNadTytulem && J(v.wcisniety) === J(['pl:true', 'en:false', 'de:false']), J(v));
    t.check('licznik: tytuł, treść i zajawka z polskim tekstem, nic przetłumaczone', J(v.liczniki) === J(['pl=', 'en=0/3', 'de=0/3']), J(v.liczniki));
    t.check('po polsku widać tylko pola WordPressa', v.tytulPL > 0 && v.tytulEN === 0 && v.adresEN === 0 && v.trescPL > 0 && v.zajawkaEN === 0, J(v));

    await p.click('.evk-tlw-przelacznik [data-lang="en"]');
    await p.waitForFunction(() => window.tinymce && window.tinymce.get('evk-tlw-en-post_content') && window.tinymce.get('evk-tlw-en-post_content').initialized, null, { timeout: 15000 }).catch(() => {});
    v = await widok();
    t.check('EN: tytuł, adres, treść i zajawka EN w miejscu polskich', v.jezyk === 'en' && v.tytulPL === 0 && v.tytulEN > 0 && v.adresPL === 0 && v.adresEN > 0
      && v.trescPL === 0 && v.trescEN > 0 && v.zajawkaPL === 0 && v.zajawkaEN > 0, J(v));
    t.check('EN: zajawka w pudełku „Zajawka"', v.zajawkaWPudelku, J(v));
    t.check('EN: drugi edytor treści uruchomiony przy wejściu w język, z „Dodaj medium"', v.edytorEN && v.dodajMedium, J(v));
    t.check('EN: pod tytułem polski oryginał', (await p.textContent('.evk-tlw-tytul[data-lang="en"] .evk-tlw-oryginal')).replace(/\s+/g, ' ').trim() === 'PL: Wpis W1',
      await p.textContent('.evk-tlw-tytul[data-lang="en"] .evk-tlw-oryginal'));

    await p.fill('#evk-tlw-en-post_title', 'Post W1 EN');
    await p.fill('#evk-tlw-en-post_name', 'Post W1 EN');   // sanitize_title zrobi z tego post-w1-en
    await p.evaluate(() => window.tinymce.get('evk-tlw-en-post_content').setContent('<p>First page W1.</p><!--nextpage--><p>Second page W1.</p>'));
    await p.evaluate(() => window.tinymce.get('evk-tlw-en-post_content').fire('change'));
    await p.fill('#evk-tlw-en-post_excerpt', 'Excerpt W1 EN');
    v = await widok();
    t.check('liczniki na żywo: EN 3/3', J(v.liczniki) === J(['pl=', 'en=3/3', 'de=0/3']), J(v.liczniki));
    await zapisz();
    let st = sonda('stan');
    const m1 = ((st.wpisy || {}).W1 || {}).meta || {};
    t.check('zapis: tytuł, treść (z podziałem stron) i zajawka EN w metadanych wpisu',
      m1._evk_tl_en__post_title === 'Post W1 EN' && /First page W1\.[\s\S]*<!--nextpage-->[\s\S]*Second page W1\./.test(m1._evk_tl_en__post_content || '')
        && m1._evk_tl_en__post_excerpt === 'Excerpt W1 EN', J(m1));
    t.check('zapis: źródło = skrót polskiego tekstu („Do sprawdzenia" po jego zmianie)',
      m1._evk_tl_en__post_title__zrodlo === skrot('Wpis W1') && m1._evk_tl_en__post_excerpt__zrodlo === skrot('Zajawka W1'), J(m1));
    t.check('zapis: DE bez tłumaczeń — żadnych metadanych DE', !Object.keys(m1).some((k) => k.startsWith('_evk_tl_de__')), J(Object.keys(m1)));
    t.check('adres EN w mapie adresów (wspólnej z zakładką „Slugi URL")',
      (st.mapa || []).some((w) => w.pl === 'wpis-w1' && w.en === 'post-w1-en'), J(st.mapa));

    // ── Strona ──────────────────────────────────────────────────────────────
    t.section('strona: wersja EN wpisu');
    const en1 = await pobierz(b + '/en/post-w1-en/');
    const tx1 = tekstCiala(en1.body);
    t.check('/en/post-w1-en/ odpowiada 200, <title> z tytułem EN', en1.status === 200 && tytulStrony(en1.body).startsWith('Post W1 EN'), en1.status + ' ' + tytulStrony(en1.body));
    t.check('treść EN, pierwsza strona (bez polskiej i bez drugiej)', tx1.includes('Post W1 EN') && tx1.includes('First page W1.') && !tx1.includes('Pierwsza strona')
      && !tx1.includes('Second page W1.'), tx1.slice(0, 300));
    const en2 = await pobierz(b + '/en/post-w1-en/2/');
    t.check('druga strona treści EN pod /2/', en2.status === 200 && tekstCiala(en2.body).includes('Second page W1.'), en2.status + ' ' + tekstCiala(en2.body).slice(0, 200));
    const pl1 = await pobierz(b + '/wpis-w1/');
    t.check('polska wersja bez zmian', pl1.status === 200 && tytulStrony(pl1.body).startsWith('Wpis W1') && tekstCiala(pl1.body).includes('Pierwsza strona W1.'),
      tytulStrony(pl1.body));
    const de1 = await pobierz(b + '/de/wpis-w1/');
    t.check('DE bez tłumaczeń: polski tytuł i treść', de1.status === 200 && tytulStrony(de1.body).startsWith('Wpis W1') && tekstCiala(de1.body).includes('Pierwsza strona W1.'),
      tytulStrony(de1.body));
    const fr = sonda('front en');
    t.check('tytuł i zajawka EN przez funkcje WordPressa (pętle, Bricks)', fr.tytul_w1 === 'Post W1 EN' && fr.zajawka_w1 === 'Excerpt W1 EN', J(fr));
    t.check('wpis z treścią EN bez zajawki EN: zajawka z treści EN (nie polska)', /^English content of W2\./.test(fr.zajawka_w2 || ''), J(fr.zajawka_w2));

    // ── „Do sprawdzenia" ────────────────────────────────────────────────────
    t.section('„Do sprawdzenia" po zmianie polskiego tytułu');
    sonda('zmien W1 post_title "Wpis W1 zmieniony"');
    await edytuj(W.W1);
    await p.click('.evk-tlw-przelacznik [data-lang="en"]');
    const sprawdz = () => p.$eval('.evk-tlw-tytul[data-lang="en"] .evk-tlw-do-sprawdzenia', (e) => !e.hidden && e.offsetHeight > 0);
    t.check('tytuł EN oznaczony „Do sprawdzenia"', await sprawdz(), '');
    t.check('treść i zajawka (polskie bez zmian) — bez oznaczenia',
      await p.$$eval('.evk-tlw-pole[data-lang="en"]:not(.evk-tlw-tytul) .evk-tlw-do-sprawdzenia', (e) => e.every((x) => x.hidden)), '');
    await p.goto(b + '/wp-admin/edit.php', { waitUntil: 'load' });
    const kolumna = await p.$$eval('tr', (rs) => rs.filter((r) => /Wpis W1 zmieniony/.test(r.textContent))
      .map((r) => [...r.querySelectorAll('.evk-tlw-stan')].map((s) => s.className.replace('evk-tlw-stan ', '') + ':' + s.textContent.trim())));
    t.check('kolumna „Języki": EN do sprawdzenia, DE brak (z opisem dla czytnika)',
      J(kolumna) === J([['is-sprawdz:ENAngielski: do sprawdzenia, przetłumaczone 3 z 3', 'is-brak:DENiemiecki: brak tłumaczenia']]), J(kolumna));
    await edytuj(W.W1);
    await p.click('.evk-tlw-przelacznik [data-lang="en"]');
    await p.click('.evk-tlw-tytul[data-lang="en"] .evk-tlw-sprawdzone');
    await zapisz();
    await p.click('.evk-tlw-przelacznik [data-lang="en"]');
    st = sonda('stan');
    t.check('„Sprawdzone" + zapis: źródło z nowego polskiego tytułu, oznaczenie znika',
      (((st.wpisy || {}).W1 || {}).meta || {})._evk_tl_en__post_title__zrodlo === skrot('Wpis W1 zmieniony') && !(await sprawdz()),
      J((((st.wpisy || {}).W1 || {}).meta || {})._evk_tl_en__post_title__zrodlo));
    /* Zmiana polskiej zajawki W FORMULARZU, bez ruszania EN: zapis nie może
       „przepisać" źródła — tłumaczenie powstało z innego tekstu. */
    await p.click('.evk-tlw-przelacznik [data-lang="pl"]');
    await p.fill('#excerpt', 'Zajawka W1 zmieniona');
    await zapisz();
    await p.click('.evk-tlw-przelacznik [data-lang="en"]');
    t.check('zmiana polskiej zajawki w formularzu (EN nietknięta): po zapisie zajawka EN „Do sprawdzenia"',
      await p.$eval('.evk-tlw-zajawka[data-lang="en"] .evk-tlw-do-sprawdzenia', (e) => !e.hidden && e.offsetHeight > 0)
        && (((sonda('stan').wpisy || {}).W1 || {}).meta || {})._evk_tl_en__post_excerpt === 'Excerpt W1 EN', '');

    // ── Adresy: konflikty i zmiana polskiego adresu ─────────────────────────
    t.section('adres EN: konflikty i zmiana polskiego adresu');
    const probaAdresu = async (czlon) => {
      await edytuj(W.W1);
      await p.click('.evk-tlw-przelacznik [data-lang="en"]');
      await p.fill('#evk-tlw-en-post_name', czlon);
      await zapisz();
      return { komunikat: ((await p.textContent('.notice-error').catch(() => '')) || '').replace(/\s+/g, ' ').trim(), mapa: sonda('stan').mapa || [] };
    };
    let pr = await probaAdresu('pricing-w');
    t.check('człon EN innego polskiego członu („cennik-w → pricing-w") — odrzucony z wyjaśnieniem',
      /Adres EN nie został zapisany/.test(pr.komunikat) && /cennik-w/.test(pr.komunikat) && pr.mapa.some((w) => w.pl === 'wpis-w1' && w.en === 'post-w1-en'),
      pr.komunikat + ' ' + J(pr.mapa));
    pr = await probaAdresu('kontakt-w');
    t.check('człon EN równy polskiemu adresowi innej strony — odrzucony',
      /polski adres/.test(pr.komunikat) && /Kontakt W/.test(pr.komunikat) && pr.mapa.some((w) => w.pl === 'wpis-w1' && w.en === 'post-w1-en'),
      pr.komunikat + ' ' + J(pr.mapa));
    const zm = sonda('zmien W1 post_name wpis-w1-nowy');
    st = sonda('stan');
    t.check('zmiana polskiego adresu: pozycja mapy idzie za wpisem',
      zm.post_name === 'wpis-w1-nowy' && (st.mapa || []).some((w) => w.pl === 'wpis-w1-nowy' && w.en === 'post-w1-en')
        && !(st.mapa || []).some((w) => w.pl === 'wpis-w1'), J(st.mapa));
    const en3 = await pobierz(b + '/en/post-w1-en/');
    t.check('adres EN działa dalej po zmianie polskiego', en3.status === 200 && tytulStrony(en3.body).startsWith('Post W1 EN'), en3.status + ' ' + tytulStrony(en3.body));

    // ── Strony: rodzic, Bricks, podpowiedź słownika ─────────────────────────
    t.section('strony: adres z rodzicem, treść w Bricksie, podpowiedź ze słownika');
    await edytuj(W.S1);
    await p.click('.evk-tlw-przelacznik [data-lang="en"]');
    t.check('adres EN strony zaczyna się od przetłumaczonego rodzica (/en/parent-w/)',
      (await p.textContent('.evk-tlw-adres[data-lang="en"] .evk-tlw-adres-baza')).endsWith('/en/parent-w/'),
      await p.textContent('.evk-tlw-adres[data-lang="en"] .evk-tlw-adres-baza'));
    const podp = await p.$eval('.evk-tlw-tytul[data-lang="en"] .evk-tlw-slownik', (e) => e.offsetHeight > 0 ? e.textContent.replace(/\s+/g, ' ').trim() : '').catch(() => '');
    t.check('pusty tytuł EN: podpowiedź ze słownika', podp === 'Ze słownika: About us W Wstaw', J(podp));
    if (podp) await p.click('.evk-tlw-tytul[data-lang="en"] .evk-tlw-wstaw');   // bez podpowiedzi nie ma czego klikać — reszta testu idzie dalej
    t.check('„Wstaw" wpisuje tłumaczenie i chowa podpowiedź', (await p.inputValue('#evk-tlw-en-post_title')) === 'About us W'
      && await p.$eval('.evk-tlw-tytul[data-lang="en"] .evk-tlw-slownik', (e) => e.offsetHeight === 0), await p.inputValue('#evk-tlw-en-post_title'));
    await edytuj(W.B1);
    await p.click('.evk-tlw-przelacznik [data-lang="en"]');
    const bricks = await p.evaluate(() => ({ nota: (document.querySelector('.evk-tlw-tresc[data-lang="en"] .evk-tlw-bricks') || {}).offsetHeight || 0,
      edytor: !!document.querySelector('#evk-tlw-en-post_content') }));
    t.check('strona z treścią w Bricksie: zamiast edytora informacja o builderze', bricks.nota > 0 && !bricks.edytor, J(bricks));

    // ── Przeniesienie ze słownika ───────────────────────────────────────────
    t.section('zakładka „Wpisy i strony": przeniesienie ze słownika');
    await p.goto(b + '/wp-admin/options-general.php?page=evoke-tlumaczenia&tab=wpisy', { waitUntil: 'load' });
    await p.click('[data-tlw-tryb="podglad"]');
    await p.waitForFunction(() => /Do przeniesienia|Nic do/.test(document.querySelector('.tlw-slownik-stan').textContent), null, { timeout: 15000 }).catch(() => {});
    const wiersze = await p.$$eval('.tlw-slownik-wynik tbody tr', (rs) => rs.map((r) => [...r.cells].map((c) => c.textContent.trim()).join(' | ')));
    t.check('podgląd: tytuły, które słownik tłumaczy w całości, tylko przy pustych polach',
      J(wiersze) === J(['O nas W (Page) | Tytuł | EN | O nas W | About us W', 'O nas W (Page) | Tytuł | DE | O nas W | Über uns W']), J(wiersze));
    await p.click('[data-tlw-tryb="zapisz"]');
    await p.waitForFunction(() => /Przeniesiono/.test(document.querySelector('.tlw-slownik-stan').textContent), null, { timeout: 15000 }).catch(() => {});
    st = sonda('stan');
    const ms1 = ((st.wpisy || {}).S1 || {}).meta || {};
    t.check('przeniesienie: tytuły EN i DE strony w metadanych, ze źródłem',
      ms1._evk_tl_en__post_title === 'About us W' && ms1._evk_tl_de__post_title === 'Über uns W' && ms1._evk_tl_en__post_title__zrodlo === skrot('O nas W'), J(ms1));
    await p.click('[data-tlw-tryb="podglad"]');
    await p.waitForFunction(() => /Nic do przeniesienia/.test(document.querySelector('.tlw-slownik-stan').textContent), null, { timeout: 15000 }).catch(() => {});
    t.check('drugi podgląd: nic do przeniesienia', /Nic do przeniesienia/.test(await p.textContent('.tlw-slownik-stan')), await p.textContent('.tlw-slownik-stan'));

    t.section('strona: tytuł wpisu w języku zamiast polskiego tytułu SEO');
    const s1en = await pobierz(b + '/en/parent-w/o-nas-w/');
    t.check('strona z polskim tytułem SEO i tytułem EN: <title> z tytułu EN', s1en.status === 200 && tytulStrony(s1en.body).startsWith('About us W'),
      s1en.status + ' ' + tytulStrony(s1en.body));
    const k1en = await pobierz(b + '/en/kontakt-w/');
    t.check('strona z polskim tytułem SEO, bez tytułu EN: <title> z polskiego tytułu SEO (jak w 1.251.0)',
      k1en.status === 200 && tytulStrony(k1en.body) === 'Kontakt — SEO PL', k1en.status + ' ' + tytulStrony(k1en.body));
    const menu = sonda('front en');
    t.check('menu: pozycja strony bez własnej etykiety — tytuł EN', J(menu.menu) === J(['About us W']), J(menu));
    t.check('bez błędów JS w edytorze i panelu', !bledy.length, bledy.join(' | '));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
    sonda('sprzataj');
  }
};
