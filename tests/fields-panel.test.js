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
    await p.waitForFunction(() => document.querySelector('.evk-tl-licznik').textContent !== '');
    t.check('liczniki: przetłumaczone / pola z tekstem oryginału (pusty oryginał się nie liczy)',
      J(await liczniki()) === J([['1/1', '0/1'], ['10/10', '2/10']]), J(await liczniki()));
    t.check('licznik dla czytnika ekranu słowami', (await p.textContent('.evk-tl-grupa >> nth=1 >> .evk-tl-jezyk[data-lang="de"] .evk-tl-licznik-sr')) === ', przetłumaczone 2 z 10',
      await p.textContent('.evk-tl-grupa >> nth=1 >> .evk-tl-jezyk[data-lang="de"] .evk-tl-licznik-sr'));

    // Klawiatura: fokus na EN w pierwszej grupie, Enter.
    await p.focus('.evk-tl-grupa >> nth=0 >> .evk-tl-jezyk[data-lang="en"]');
    await p.keyboard.press('Enter');
    await p.waitForTimeout(300);
    const wcisniete = await p.$$eval('.evk-tl-jezyk[aria-pressed="true"]', (b) => b.map((x) => x.dataset.lang));
    t.check('Enter na „EN" przełącza WSZYSTKIE grupy na ekranie (aria-pressed)', J(wcisniete) === J(['en', 'en'])
      && await p.locator('.evk-tl-grupa.evk-tl-obcy[data-evk-jezyk="en"]').count() === 2, J(wcisniete));

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
    t.check('„Nie tłumacz", liczba i zakładka bez pól do tłumaczenia znikają', !widok.liczba && !widok.kod && !widok.ikona && !widok.zakladka_tech, J(widok));
    t.check('repeater zostaje z polami EN; dodawanie, usuwanie i przeciąganie wierszy znika', widok.lista && widok.en_naglowek && !widok.dodaj && !widok.usun && !widok.uchwyt, J(widok));
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
