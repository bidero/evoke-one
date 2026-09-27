/**
 * Panel tłumaczeń wartości pól Evoke FIELDS w Chromium (Fields 1.70.0,
 * Evoke ONE 1.250.0) — czwarty testowy WordPress, pola.test, obie wtyczki.
 *
 * Prawdziwe ekrany: klasyczny edytor (typ treści bez REST), term, strona
 * ustawień i edytor blokowy. Sprawdzane to, czego sonda PHP nie widzi:
 * przełącznik jednym kliknięciem dla wszystkich grup, co w widoku języka
 * znika, liczniki, „Kopiuj z polskiego" (z pytaniem przy nadpisaniu),
 * „Do sprawdzenia" na żywo i „Sprawdzone", leniwy edytor WYSIWYG i jego
 * treść po zapisie, klawiatura, telefon 360 px, zapis każdego ekranu.
 *
 * Zmierzone przed ustawieniem progów (rozpoznanie): klasyczny edytor ~1,2 s,
 * blokowy ~1,7 s do przycisku zapisu; w WordPressie 7.1 panel metaboksów
 * edytora blokowego jest zwinięty — test go rozwija. Błędy konsoli
 * „ERR_CERT_AUTHORITY_INVALID" to zewnętrzne zasoby zablokowane przez proxy
 * tej maszyny, nie strona — liczą się tylko błędy JS (pageerror).
 */

const { phpOutput, chromiumPath } = require('./lib/harness');
const { chromium } = require('playwright-core');
const serwerWp = require('./lib/wp-serwer');

const sonda = (tryb) => {
  const w = phpOutput('fields-tlumaczenia.php', tryb);
  try { return JSON.parse(w); } catch (e) { return { brak: 'sonda „' + tryb + '": ' + w.slice(0, 300) }; }
};
const J = (v) => JSON.stringify(v);
const pole = (name) => '.evk-tl-pole:has([name="' + name + '"])';

module.exports = async function (t) {
  t.section('środowisko: pola.test z Evoke ONE i Evoke FIELDS');
  const u = sonda('ustaw');
  t.check('czwarty WordPress z obiema wtyczkami (tools/testowy-wp.sh)', !u.brak && u.klasyk > 0, u.brak || 'jest');
  if (u.brak) return;
  const z = sonda('zapis');
  t.check('dane startowe zapisane formularzem (sonda „zapis")', (z.meta || {}).evk_tl_en__tytul === 'Title EN', J(z.meta || z));

  let serwer, browser;
  const bledy = [];
  try {
    serwer = await serwerWp.start(u.wp);
    browser = await chromium.launch({ executablePath: chromiumPath() });
    const p = await browser.newPage({ viewport: { width: 1280, height: 900 } });
    p.on('pageerror', (e) => bledy.push(p.url().replace(serwer.baza, '') + ': ' + e.message));
    await serwerWp.zaloguj(p, serwer.baza);
    const adres = serwer.baza + '/wp-admin/post.php?post=' + u.klasyk + '&action=edit';

    // ── Klasyczny edytor ──────────────────────────────────────────────────
    t.section('klasyczny edytor: przełącznik i liczniki');
    await p.goto(adres, { waitUntil: 'load' });
    const liczniki = () => p.$$eval('.evk-tl-grupa', (gs) => gs.map((g) => [...g.querySelectorAll(':scope > .evk-tl-przelacznik .evk-tl-licznik')].map((s) => s.textContent)));
    t.check('dwie grupy (pojedyncza i repeater), każda z przełącznikiem', await p.locator('.evk-tl-grupa').count() === 2, String(await p.locator('.evk-tl-grupa').count()));
    // Licznik grupy FIELDS — nad tytułem stoi też przełącznik wpisu z Evoke ONE (1.252.0), liczony od razu.
    await p.waitForFunction(() => document.querySelector('.evk-tl-grupa .evk-tl-licznik').textContent !== '');
    t.check('liczniki: przetłumaczone / pola z tekstem oryginału (pusty oryginał się nie liczy)',
      J(await liczniki()) === J([['1/1', '0/1'], ['10/10', '2/10']]), J(await liczniki()));
    t.check('licznik dla czytnika ekranu słowami', (await p.textContent('.evk-tl-grupa >> nth=1 >> .evk-tl-jezyk[data-lang="de"] .evk-tl-licznik-sr')) === ', przetłumaczone 2 z 10',
      await p.textContent('.evk-tl-grupa >> nth=1 >> .evk-tl-jezyk[data-lang="de"] .evk-tl-licznik-sr'));

    /* Zgłoszone ze strony (1.70.0): przełącznik bez marginesów, za duży. Metabox
       Fields zdejmuje boczny padding z .inside, a pola mają własny — przełącznik
       ma stać w tej samej linii co pola. Zmierzone przed progiem: 14 = 14 px,
       przyciski 37×26 px, kolor = --wp-admin-theme-color (WP 7.1: #3858e9). */
    const przel = await p.evaluate(() => {
      const b = document.querySelector('#evk_rep_grupa_wpis');
      const inside = b.querySelector('.inside').getBoundingClientRect().left;
      const przycisk = b.querySelector('.evk-tl-jezyk').getBoundingClientRect();
      const pole = b.querySelector('.evk-s-field[data-key="tytul"]');
      const akt = b.querySelector('.evk-tl-jezyk[aria-pressed="true"]');
      return {
        przelacznik: Math.round(przycisk.left - inside), pole: Math.round(pole.getBoundingClientRect().left + parseFloat(getComputedStyle(pole).paddingLeft) - inside),
        wysokosc: Math.round(przycisk.height), kolor: getComputedStyle(akt).backgroundColor,
        motyw: getComputedStyle(akt).getPropertyValue('--wp-admin-theme-color').trim(),
        klasy: [...b.querySelectorAll('.evk-tl-jezyk')].map((x) => x.dataset.lang + ':' + x.classList.contains('button-primary')),
      };
    });
    const rgb = (hex) => { const n = parseInt(hex.replace('#', ''), 16); return 'rgb(' + (n >> 16) + ', ' + ((n >> 8) & 255) + ', ' + (n & 255) + ')'; };
    t.check('przełącznik w linii z polami (ten sam odstęp od krawędzi metaboksu)', przel.przelacznik === przel.pole && przel.przelacznik >= 12, J(przel));
    t.check('przyciski języków małe: 24–28 px wysokości', przel.wysokosc >= 24 && przel.wysokosc <= 28, przel.wysokosc + ' px');
    t.check('wciśnięty język w kolorze schematu panelu (button-primary), tylko on',
      /^#[0-9a-f]{6}$/i.test(przel.motyw) && przel.kolor === rgb(przel.motyw) && J(przel.klasy) === J(['pl:true', 'en:false', 'de:false']), J(przel));

    // Układ w PL — do porównania z widokiem EN (zgłoszenie: wiersze repeatera zmieniały wysokość).
    const uklad = () => p.evaluate(() => {
      const w = document.querySelector('#evk_rep_grupa_wpis .evk-rep-row');
      return {
        pola: [...w.querySelectorAll(':scope > .evk-rep-row-body .evk-s-field')].map((f) => { const r = f.getBoundingClientRect(); return f.dataset.key + ':' + Math.round(r.left) + '/' + Math.round(r.width); }),
        naglowki: [...document.querySelectorAll('#evk_rep_grupa_wpis .evk-rep-row-head, #evk_rep_grupa_wiersze .evk-rep-row-head')].map((h) => Math.round(h.getBoundingClientRect().height)),
      };
    });
    const ukladPl = await uklad();

    // Klawiatura: fokus na EN w pierwszej grupie, Enter.
    await p.focus('.evk-tl-grupa >> nth=0 >> .evk-tl-jezyk[data-lang="en"]');
    await p.keyboard.press('Enter');
    await p.waitForTimeout(300);
    const wcisniete = await p.$$eval('.evk-tl-grupa .evk-tl-jezyk[aria-pressed="true"]', (b) => b.map((x) => x.dataset.lang));
    t.check('Enter na „EN" przełącza WSZYSTKIE grupy na ekranie (aria-pressed)', J(wcisniete) === J(['en', 'en'])
      && await p.locator('.evk-tl-grupa.evk-tl-obcy[data-evk-jezyk="en"]').count() === 2, J(wcisniete));
    /* Evoke ONE 1.252.0: przełącznik wpisu nad tytułem ma tę samą klasę przycisku,
       więc idzie za FIELDS — jedno kliknięcie przełącza tytuł, treść i grupy. */
    const wpisEn = await p.evaluate(() => ({ forma: document.querySelector('#post').getAttribute('data-evk-tlw'),
      wcisniety: (document.querySelector('.evk-tlw-przelacznik .evk-tl-jezyk[aria-pressed="true"]') || {}).dataset }));
    t.check('przełącznik wpisu Evoke ONE idzie za FIELDS: EN wciśnięty, tytuł i treść EN', wpisEn.forma === 'en' && (wpisEn.wcisniety || {}).lang === 'en', J(wpisEn));
    const kolorEn = await p.$$eval('.evk-tl-grupa', (gs) => gs.map((g) => [...g.querySelectorAll(':scope > .evk-tl-przelacznik .evk-tl-jezyk')]
      .map((b) => b.dataset.lang + ':' + b.classList.contains('button-primary')).join(',')));
    t.check('kolor wciśnięcia (button-primary) przechodzi na EN w obu grupach', J(kolorEn) === J(['pl:false,en:true,de:false', 'pl:false,en:true,de:false']), J(kolorEn));

    t.section('widok EN: zostaje tylko to, co tłumacz ma przejść');
    const widac = async (s) => p.isVisible(s);
    const widok = {
      pl_tytul: await widac('[name="evk_single[tytul]"]'), en_tytul: await widac('[name="evk_single[evk_tl_en__tytul]"]'),
      de_tytul: await widac('[name="evk_single[evk_tl_de__tytul]"]'), etykieta_pl: await widac('.evk-s-field[data-key="tytul"] > .evk-s-label'),
      liczba: await widac('.evk-s-field[data-key="liczba"]'), kod: await widac('.evk-s-field[data-key="kod"]'),
      ikona: await widac('.evk-rep-row .evk-s-field[data-key="ikona"]'), lista: await widac('.evk-s-field[data-key="lista"]'),
      en_naglowek: await widac('[name="evk_single[lista][0][evk_tl_en__naglowek]"]'),
      zakladka_tech: await widac('.evk-s-tab:has-text("Techniczne")'), dodaj: await widac('.evk-rep-add'),
      usun: await widac('.evk-rep-remove'), uchwyt: await widac('.evk-rep-handle'),
    };
    t.check('pole EN widać, oryginału (pola i etykiety) i DE nie', widok.en_tytul && !widok.pl_tytul && !widok.de_tytul && !widok.etykieta_pl, J(widok));
    /* Decyzja zgłaszającego (po 1.70.0): pola bez tłumaczenia wyszarzone, nie
       ukryte — układ ma się nie zmieniać. Nieaktywne przez `inert`. */
    const szare = (s) => p.evaluate((s) => [...document.querySelectorAll(s)].filter((e) => e.getClientRects().length)
      .map((e) => ({ inert: e.inert, przezrocz: parseFloat(getComputedStyle(e).opacity) })), s);
    const ikony = await szare('#evk_rep_grupa_wpis .evk-rep-row .evk-s-field[data-key="ikona"]');
    t.check('„Nie tłumacz" w wierszu: na swoim miejscu, wyszarzone, nieaktywne (inert)', ikony.length === 2 && ikony.every((x) => x.inert && x.przezrocz < 0.6), J(ikony));
    const struktura = await szare('#evk_rep_grupa_wpis .evk-rep-handle, #evk_rep_grupa_wpis .evk-rep-remove, #evk_rep_grupa_wpis .evk-rep-add-wrap');
    t.check('uchwyty przeciągania, kosze i „Dodaj wiersz": zostają, przygaszone i nieaktywne', struktura.length === 5 && struktura.every((x) => x.inert && x.przezrocz < 0.6),
      J(struktura));
    const ukladEn = await uklad();
    t.check('SEDNO zgłoszenia: nagłówki wierszy repeatera tej samej wysokości w PL i EN', J(ukladEn.naglowki) === J(ukladPl.naglowki) && ukladPl.naglowki.length === 3,
      J([ukladPl.naglowki, ukladEn.naglowki]));
    t.check('i pola wiersza w tych samych kolumnach (pozycja i szerokość)', J(ukladEn.pola) === J(ukladPl.pola), J([ukladPl.pola, ukladEn.pola]));
    const fokus = await p.evaluate(() => {
      const w = document.querySelector('#evk_rep_grupa_wpis .evk-rep-row .evk-s-field[data-key="ikona"] input');
      w.focus();
      return document.activeElement === w;
    });
    t.check('wyszarzone pole nie łapie fokusu', !fokus, fokus ? 'łapie' : 'nie łapie');
    t.check('zakładka bez pól do tłumaczenia zostaje, przygaszona', widok.zakladka_tech
      && parseFloat(await p.evaluate(() => getComputedStyle([...document.querySelectorAll('.evk-s-tab')].find((b) => b.textContent === 'Techniczne')).opacity)) < 1, J(widok));
    t.check('repeater z polami EN widoczny', widok.lista && widok.en_naglowek, J(widok));
    await p.click('.evk-s-tab:has-text("Techniczne")');
    const tech = await szare('.evk-s-field[data-key="kod"], .evk-s-field[data-key="liczba"]');
    const dopisek = await p.evaluate(() => getComputedStyle(document.querySelector('.evk-s-field[data-key="kod"] > .evk-s-label'), '::after').content);
    t.check('w niej „Nie tłumacz" i liczba: widoczne, wyszarzone, z dopiskiem „wspólne dla języków"', tech.length === 2 && tech.every((x) => x.inert && x.przezrocz < 0.6)
      && dopisek.includes('wspólne dla języków'), J([tech, dopisek]));
    await p.click('.evk-s-tab:has-text("Treść")');
    t.check('nad polem oryginał (podgląd)', (await p.textContent(pole('evk_single[evk_tl_en__tytul]') + ' .evk-tl-oryginal-tekst')) === 'Tytuł PL',
      await p.textContent(pole('evk_single[evk_tl_en__tytul]') + ' .evk-tl-oryginal-tekst'));
    const idTresc = await p.getAttribute('.evk-tl-wysiwyg[name="evk_single[evk_tl_en__tresc]"]', 'id');
    await p.waitForFunction((id) => window.tinymce && tinymce.get(id) && tinymce.get(id).initialized, idTresc, { timeout: 15000 }).catch(() => {});
    const ramka = await p.evaluate((id) => { const f = document.getElementById(id + '_ifr'); return f ? Math.round(f.getBoundingClientRect().height) : 0; }, idTresc);
    t.check('WYSIWYG EN: edytor startuje dopiero w widoku EN, z niezerową wysokością', ramka > 50, ramka + ' px');
    t.check('WYSIWYG DE (niewidoczny): bez edytora', await p.evaluate(() => !tinymce.get(document.querySelector('.evk-tl-wysiwyg[name="evk_single[evk_tl_de__tresc]"]').id)), 'bez');

    t.section('„Kopiuj z polskiego" i liczniki na żywo');
    const pytania = [];
    p.once('dialog', (d) => { pytania.push(d.message()); d.dismiss(); });
    await p.click(pole('evk_single[evk_tl_en__tytul]') + ' .evk-tl-kopiuj');
    await p.waitForTimeout(200);
    t.check('przy nadpisaniu innego tłumaczenia pyta; „Anuluj" zostawia tłumaczenie', pytania.length === 1
      && (await p.inputValue('[name="evk_single[evk_tl_en__tytul]"]')) === 'Title EN', J(pytania));
    await p.fill('[name="evk_single[evk_tl_en__opis]"]', '');
    await p.waitForTimeout(400);
    t.check('wyczyszczone tłumaczenie: licznik EN 9/10', J(await liczniki()) === J([['1/1', '0/1'], ['9/10', '2/10']]), J(await liczniki()));
    await p.click(pole('evk_single[evk_tl_en__opis]') + ' .evk-tl-kopiuj');
    await p.waitForTimeout(400);
    t.check('kopia oryginału (z nową linią) w pustym polu, bez pytania; licznik 10/10',
      (await p.inputValue('[name="evk_single[evk_tl_en__opis]"]')) === 'Opis PL\nw dwóch liniach' && J(await liczniki()) === J([['1/1', '0/1'], ['10/10', '2/10']]),
      J([await p.inputValue('[name="evk_single[evk_tl_en__opis]"]'), await liczniki()]));

    t.section('„Do sprawdzenia" na żywo i „Sprawdzone"');
    await p.click('.evk-tl-grupa >> nth=0 >> .evk-tl-jezyk[data-lang="pl"]');
    await p.waitForTimeout(300);
    t.check('powrót do PL: edytor EN zamknięty, treść została w polu', await p.evaluate((id) => !tinymce.get(id) && document.getElementById(id).value.includes('Content'), idTresc),
      'zamknięty');
    const poPowrocie = await p.evaluate(() => ({
      inert: document.querySelectorAll('.evk-tl-grupa [inert]').length,
      primary: [...document.querySelectorAll('.evk-tl-grupa .evk-tl-jezyk.button-primary')].map((b) => b.dataset.lang),
    }));
    t.check('…nic nie zostaje nieaktywne, kolor wciśnięcia wraca na PL w obu grupach', poPowrocie.inert === 0 && J(poPowrocie.primary) === J(['pl', 'pl']), J(poPowrocie));
    // W drugą stronę: przełącznik wpisu Evoke ONE przełącza grupy FIELDS.
    await p.click('.evk-tlw-przelacznik .evk-tl-jezyk[data-lang="de"]');
    await p.waitForTimeout(300);
    const grupyDe = await p.locator('.evk-tl-grupa.evk-tl-obcy[data-evk-jezyk="de"]').count();
    await p.click('.evk-tlw-przelacznik .evk-tl-jezyk[data-lang="pl"]');
    await p.waitForTimeout(300);
    t.check('przełącznik wpisu Evoke ONE przełącza grupy FIELDS (DE i z powrotem PL)',
      grupyDe === 2 && await p.locator('.evk-tl-grupa.evk-tl-obcy').count() === 0 && !(await p.getAttribute('#post', 'data-evk-tlw')), String(grupyDe));
    await p.fill('[name="evk_single[tytul]"]', 'Tytuł PL nowy');
    await p.click('.evk-tl-grupa >> nth=0 >> .evk-tl-jezyk[data-lang="en"]');
    await p.waitForTimeout(300);
    const tyt = pole('evk_single[evk_tl_en__tytul]');
    t.check('zmiana oryginału: tłumaczenia tego pola „Do sprawdzenia", podgląd z nowym oryginałem',
      (await p.getAttribute(tyt, 'data-sprawdz')) === '1' && await p.isVisible(tyt + ' .evk-tl-do-sprawdzenia')
        && (await p.textContent(tyt + ' .evk-tl-oryginal-tekst')) === 'Tytuł PL nowy', await p.textContent(tyt + ' .evk-tl-oryginal-tekst'));
    t.check('…a pole z niezmienionym oryginałem nie', (await p.getAttribute(pole('evk_single[evk_tl_en__opis]'), 'data-sprawdz')) === null, 'bez');
    await p.click(tyt + ' .evk-tl-sprawdzone');
    const poKlik = await p.evaluate((s) => ({
      sprawdz: document.querySelector(s).hasAttribute('data-sprawdz'), zrodlo: document.querySelector(s + ' .evk-tl-zrodlo').value,
      fokus: document.activeElement === document.querySelector(s + ' .evk-tl-kopiuj'),
    }), tyt);
    t.check('„Sprawdzone": znacznik znika, źródło „teraz" do zapisu, fokus na przycisku obok (nie ginie)', !poKlik.sprawdz && poKlik.zrodlo === 'teraz' && poKlik.fokus, J(poKlik));

    t.section('WYSIWYG EN: pisanie i zapis');
    await p.waitForFunction((id) => window.tinymce && tinymce.get(id) && tinymce.get(id).initialized, idTresc, { timeout: 15000 });
    const f = p.frameLocator('#' + idTresc + '_ifr');
    await f.locator('body').click();
    await p.keyboard.press('Control+A');
    await p.keyboard.type('Nowa treść EN');
    await p.waitForTimeout(300);
    t.check('pole pod edytorem aktualne od razu (zapis bez zdarzenia submit też je weźmie)',
      await p.evaluate((id) => document.getElementById(id).value.includes('Nowa treść EN'), idTresc), await p.evaluate((id) => document.getElementById(id).value, idTresc));
    await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.click('#publish')]);
    const s1 = sonda('stan');
    const m1 = s1.meta || {};
    // Przeglądarka wysyła nowe linie pola tekstowego jako CRLF (norma formularzy) — ten sam tekst.
    t.check('zapis: tłumaczenie EN z edytora, skopiowany opis', String(m1.evk_tl_en__tresc || '').includes('Nowa treść EN')
      && String(m1.evk_tl_en__opis || '').replace(/\r\n/g, '\n') === 'Opis PL\nw dwóch liniach',
      J([m1.evk_tl_en__tresc, m1.evk_tl_en__opis]));
    t.check('zapis: EN („Sprawdzone") ze źródłem nowego oryginału, DE ze starym', m1.evk_tl_en__tytul__zrodlo === s1.skrot_tytul && m1.evk_tl_de__tytul__zrodlo !== s1.skrot_tytul,
      J([m1.evk_tl_en__tytul__zrodlo, m1.evk_tl_de__tytul__zrodlo, s1.skrot_tytul]));
    await p.click('.evk-tl-grupa >> nth=0 >> .evk-tl-jezyk[data-lang="de"]');
    await p.waitForTimeout(300);
    t.check('po przeładowaniu (DE): tytuł „Do sprawdzenia" z serwera, EN nie',
      (await p.getAttribute(pole('evk_single[evk_tl_de__tytul]'), 'data-sprawdz')) === '1' && (await p.getAttribute(tyt, 'data-sprawdz')) === null, 'DE do sprawdzenia');

    t.section('nowe wiersze: własne identyfikatory pól języków');
    await p.click('.evk-tl-grupa >> nth=0 >> .evk-tl-jezyk[data-lang="pl"]');
    await p.click('.evk-s-field[data-key="lista"] .evk-rep-add');
    await p.click('.evk-s-field[data-key="lista"] .evk-rep-add');
    const idy = await p.evaluate(() => {
      const w = [...document.querySelectorAll('[id]')].map((e) => e.id);
      const pow = w.filter((v, i) => w.indexOf(v) !== i);
      const nowe = [...document.querySelectorAll('.evk-s-field[data-key="lista"] .evk-rep-row')].slice(-2)
        .map((r) => [...r.querySelectorAll('.evk-tl-wejscie')].map((e) => e.id));
      const etykiety = [...document.querySelectorAll('.evk-tl-etykieta')].filter((l) => !document.getElementById(l.htmlFor)).length;
      return { pow, nowe, etykiety };
    });
    t.check('dwa dodane wiersze: po 6 pól języków, identyfikatory różne, żadnych powtórzeń na stronie, etykiety trafiają',
      idy.nowe.length === 2 && idy.nowe[0].length === 6 && idy.nowe[0][0] !== idy.nowe[1][0] && idy.pow.length === 0 && idy.etykiety === 0, J(idy));
    await p.click('.evk-tl-grupa >> nth=0 >> .evk-tl-jezyk[data-lang="en"]');
    await p.waitForTimeout(300);
    t.check('licznik liczy tylko wiersze z oryginałem (puste nowe nie)', J(await liczniki()) === J([['1/1', '0/1'], ['10/10', '2/10']]), J(await liczniki()));

    // ── White Label ────────────────────────────────────────────────────────
    t.section('kolor przełącznika z White Label (Evoke ONE)');
    const wl = sonda('wl');   // #d63638 — domyślny w sondzie (znak # w powłoce zaczyna komentarz)
    try {
      await p.goto(adres, { waitUntil: 'load' });
      const kolorWl = await p.evaluate(() => getComputedStyle(document.querySelector('#evk_rep_grupa_wpis .evk-tl-jezyk[aria-pressed="true"]')).backgroundColor);
      t.check('White Label z kolorem głównym: wciśnięty język w tym kolorze', wl.ok && kolorWl === 'rgb(214, 54, 56)', kolorWl);
    } finally {
      sonda('wl-przywroc');
    }

    // ── Telefon ────────────────────────────────────────────────────────────
    t.section('telefon 360 px: widok EN');
    await p.setViewportSize({ width: 360, height: 800 });
    await p.goto(adres, { waitUntil: 'load' });
    await p.click('.evk-tl-grupa >> nth=1 >> .evk-tl-jezyk[data-lang="en"]');
    await p.waitForTimeout(500);
    const tel = await p.evaluate(() => {
      const W = document.documentElement.clientWidth;
      const vis = (e) => e.getClientRects().length > 0 && getComputedStyle(e).visibility !== 'hidden';
      const wystaje = [...document.querySelectorAll('.evk-tl-grupa *')].filter(vis)
        .filter((e) => { const r = e.getBoundingClientRect(); return r.width > 0 && (r.right > W + 0.5 || r.left < -0.5); })
        .filter((e) => !e.closest('.wp-editor-wrap iframe'))
        .map((e) => e.tagName + '.' + String(e.className).slice(0, 30) + ' ' + Math.round(e.getBoundingClientRect().right));
      const male = [...document.querySelectorAll('.evk-tl-grupa .evk-tl-wejscie')].filter(vis)
        .filter((e) => parseFloat(getComputedStyle(e).fontSize) < 16).map((e) => e.name + ' ' + getComputedStyle(e).fontSize);
      const cele = [...document.querySelectorAll('.evk-tl-grupa .evk-tl-jezyk, .evk-tl-grupa .evk-tl-narzedzia .button')].filter(vis)
        .filter((e) => { const r = e.getBoundingClientRect(); return r.width < 24 || r.height < 24; }).map((e) => e.textContent.trim().slice(0, 20));
      return { wystaje: wystaje.slice(0, 8), male: male.slice(0, 8), cele: cele.slice(0, 8), W };
    });
    t.check('nic nie wystaje poza ekran', tel.wystaje.length === 0, J(tel.wystaje) + ' / ' + tel.W);
    t.check('pola języków: co najmniej 16 px pisma (Safari nie powiększa strony)', tel.male.length === 0, J(tel.male));
    t.check('przyciski języków i narzędzi: co najmniej 24×24 px', tel.cele.length === 0, J(tel.cele));
    await p.setViewportSize({ width: 1280, height: 900 });

    // ── Nowy term (AJAX) ───────────────────────────────────────────────────
    t.section('nowy term: zapis AJAX-em i czysty formularz po dodaniu');
    await p.goto(serwer.baza + '/wp-admin/edit-tags.php?taxonomy=category', { waitUntil: 'load' });
    const dodaj = async (nazwa, pl, en) => {
      await p.fill('#tag-name', nazwa);
      if (pl !== null) {
        await p.click('#addtag .evk-tl-jezyk[data-lang="pl"]');
        await p.fill('#addtag [name="evk_single[podpis]"]', pl);
      }
      await p.click('#addtag .evk-tl-jezyk[data-lang="en"]');
      await p.fill('#addtag [name="evk_single[evk_tl_en__podpis]"]', en);
      const odp = p.waitForResponse((r) => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('action=add-tag'), { timeout: 30000 });
      await p.click('#addtag #submit');
      await odp;
      await p.waitForTimeout(500);
    };
    await dodaj('Nowa kategoria pola', 'Podpis nowej', 'New caption');
    const poDodaniu = await p.evaluate(() => ({
      en: document.querySelector('#addtag [name="evk_single[evk_tl_en__podpis]"]').value,
      pl: document.querySelector('#addtag [name="evk_single[podpis]"]').value,
      widok: document.querySelector('#addtag .evk-tl-grupa').dataset.evkJezyk,
      zrodlo: document.querySelector('#addtag .evk-tl-pole[data-lang="en"] .evk-tl-zrodlo').value,
    }));
    t.check('po dodaniu: pola języków i ukryty w widoku EN oryginał puste, widok wraca do PL',
      J(poDodaniu) === J({ en: '', pl: '', widok: 'pl', zrodlo: '' }), J(poDodaniu));
    // Drugi term dodany od razu, z samym tłumaczeniem — nie może dostać oryginału poprzedniego.
    await dodaj('Druga kategoria pola', null, 'Second caption');
    const nowe = sonda('stan').nowe_termy || {};
    t.check('oba termy zapisane z własnymi wartościami (drugi bez oryginału pierwszego)',
      J(nowe) === J({ 'nowa-kategoria-pola': { podpis: 'Podpis nowej', evk_tl_en__podpis: 'New caption' },
        'druga-kategoria-pola': { podpis: '', evk_tl_en__podpis: 'Second caption' } }), J(nowe));

    // ── Term ───────────────────────────────────────────────────────────────
    t.section('term: przełącznik i zapis');
    await p.goto(serwer.baza + '/wp-admin/term.php?taxonomy=category&tag_ID=' + u.term, { waitUntil: 'load' });
    t.check('przełącznik na ekranie termu', await p.locator('.evk-tl-grupa .evk-tl-jezyk').count() === 3, String(await p.locator('.evk-tl-grupa .evk-tl-jezyk').count()));
    await p.fill('[name="evk_single[podpis]"]', 'Podpis z panelu');
    await p.click('.evk-tl-jezyk[data-lang="en"]');
    await p.fill('[name="evk_single[evk_tl_en__podpis]"]', 'Caption from panel');
    await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.click('#edittag [type="submit"].button-primary')]);
    const s2 = sonda('stan');
    t.check('zapis termu: oryginał i tłumaczenie', J(s2.term_meta) === J({ podpis: 'Podpis z panelu', evk_tl_en__podpis: 'Caption from panel' }), J(s2.term_meta));

    // ── Strona ustawień ────────────────────────────────────────────────────
    t.section('strona ustawień: przełącznik i zapis');
    await p.goto(serwer.baza + '/wp-admin/admin.php?page=pola-ustawienia', { waitUntil: 'load' });
    t.check('przełącznik na stronie ustawień', await p.locator('.evk-tl-grupa .evk-tl-jezyk').count() === 3, String(await p.locator('.evk-tl-grupa .evk-tl-jezyk').count()));
    await p.fill('[name="evk_opt[grupa_opcje][slogan]"]', 'Hasło z panelu');
    await p.click('.evk-tl-jezyk[data-lang="en"]');
    await p.fill('[name="evk_opt[grupa_opcje][evk_tl_en__slogan]"]', 'Motto from panel');
    await Promise.all([p.waitForNavigation({ timeout: 30000 }), p.click('.evk-settings-form [type="submit"]')]);
    const s3 = sonda('stan');
    t.check('zapis opcji: oryginał i tłumaczenie w tablicy opcji', (s3.opcje || {}).slogan === 'Hasło z panelu' && (s3.opcje || {}).evk_tl_en__slogan === 'Motto from panel',
      J(s3.opcje));

    // ── Edytor blokowy ─────────────────────────────────────────────────────
    t.section('edytor blokowy: metabox, WYSIWYG i zapis bez zdarzenia submit');
    await p.goto(serwer.baza + '/wp-admin/post.php?post=' + u.wpis + '&action=edit', { waitUntil: 'load' });
    await p.waitForSelector('.editor-post-publish-button', { timeout: 60000 });
    if (await p.locator('.components-modal__frame').count()) await p.keyboard.press('Escape');
    // Panel metaboksów zwinięty; jego przycisk przykrywa uchwyt zmiany rozmiaru,
    // więc klik myszą trafia w uchwyt — klik z poziomu strony.
    await p.evaluate(() => {
      const b = [...document.querySelectorAll('button[aria-expanded="false"]')].find((x) => /^Meta Boxes$/i.test(x.textContent.trim()));
      if (b) b.click();
    });
    await p.waitForSelector('.evk-tl-grupa', { state: 'visible', timeout: 15000 });
    await p.fill('[name="evk_single[tytul]"]', 'Tytuł z edytora bloków');
    await p.click('.evk-tl-grupa >> nth=0 >> .evk-tl-jezyk[data-lang="en"]');
    await p.fill('[name="evk_single[evk_tl_en__tytul]"]', 'Title from block editor');
    const idGb = await p.getAttribute('.evk-tl-wysiwyg[name="evk_single[evk_tl_en__tresc]"]', 'id');
    await p.waitForFunction((id) => window.tinymce && tinymce.get(id) && tinymce.get(id).initialized, idGb, { timeout: 15000 });
    await p.frameLocator('#' + idGb + '_ifr').locator('body').click();
    await p.keyboard.type('Treść EN z edytora bloków');
    const zapisMetabox = p.waitForResponse((r) => r.url().includes('meta-box-loader=1') && r.request().method() === 'POST', { timeout: 30000 });
    await p.click('.editor-post-publish-button');
    await zapisMetabox;
    await p.waitForTimeout(500);
    const s4 = sonda('stan');
    t.check('zapis z edytora bloków: oryginał, tłumaczenie tekstowe i z WYSIWYG',
      s4.wpis_meta.tytul === 'Tytuł z edytora bloków' && s4.wpis_meta.evk_tl_en__tytul === 'Title from block editor'
        && String(s4.wpis_meta.evk_tl_en__tresc || '').includes('Treść EN z edytora bloków'), J(s4.wpis_meta));
  } finally {
    if (browser) await browser.close();
    if (serwer) await serwer.zatrzymaj();
  }
  t.check('zero błędów JS na wszystkich ekranach', bledy.length === 0, bledy.join(' | ') || 'zero');
};
