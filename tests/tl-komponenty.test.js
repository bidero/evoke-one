/**
 * Komponenty Bricksa a tłumaczenia (1.272.0).
 *
 * Bricksa tu nie ma — kształt komponentów i instancji z prób na testowej
 * (docs/proby-komponenty.md). Trzy części:
 *  - czysta funkcja właściwości „… EN”: PHP (51) i JS (tl-komponenty.js)
 *    na tych samych przypadkach muszą dać to samo — builder dokłada je na
 *    żywo, serwer przy zapisie, a stałe identyfikatory łączą jedno z drugim;
 *  - serwer na pierwszym testowym WordPressie: zapis opcji komponentów,
 *    zmiana języków, hurt AI instancji i tekstów stałych, lista tekstów,
 *    „Do sprawdzenia”, prawa;
 *  - builder (fixtura builder-ai.html): bliźniaki w stanie bez zapisu,
 *    ✦ na instancji i w trybie edycji komponentu.
 */

const fs = require('fs');
const os = require('os');
const path = require('path');
const { phpOutput } = require('./lib/harness');
const KP = require('../assets/admin/tl-komponenty.js');

const sonda = (...a) => {
  const s = phpOutput('tl-komponenty.php', a.map((x) => JSON.stringify(String(x))).join(' '), { dopuscBlad: true });
  try { return JSON.parse(s); } catch (e) { return { brak: 'sonda „' + a[0] + '” nie oddała JSON-a: ' + s.slice(0, 300) }; }
};
const J = (x) => JSON.stringify(x);
const kopia = (x) => JSON.parse(JSON.stringify(x));
const MAPA = { heading: { pola: ['text'], listy: [] }, 'text-basic': { pola: ['text'], listy: [] }, button: { pola: ['text'], listy: [] } };

/* K1 jak „Testowy” z próby (nagłówek i tekst z właściwościami, tekst stały),
   K2 jak przycisk z próby (ręczna „Tłumaczenie”), K3 — element spoza mapy. */
const K1 = { id: 'kpkomp', elements: [
  { id: 'kpkomp', name: 'block', parent: 0, children: ['kpnagl', 'kptekst', 'kpstal'], settings: { tag: 'div' }, label: 'Karta oferty' },
  { id: 'kpnagl', name: 'heading', parent: 'kpkomp', children: [], settings: { text: 'Nagłówek komponentu', evk_tl_en__text: 'KOMPONENT EN' } },
  { id: 'kptekst', name: 'text-basic', parent: 'kpkomp', children: [], settings: { text: 'Tekst komponentu' } },
  { id: 'kpstal', name: 'text-basic', parent: 'kpkomp', children: [], settings: { text: 'Tekst stały komponentu' } },
], properties: [
  { label: 'Tekst', type: 'text', id: 'kpwtek', connections: { kptekst: ['text'] } },
  { label: 'Nagłówek', type: 'text', id: 'kpwnag', connections: { kpnagl: ['text'] } },
] };
const K2 = { id: 'kpprzy', elements: [
  { id: 'kpprzy', name: 'button', parent: 0, children: [], settings: { text: 'Przycisk komponentu', evk_tl_en__text: 'BUTTON EN' }, label: 'Przycisk' },
], properties: [
  { label: 'Treść', type: 'text', id: 'kpwtre', connections: { kpprzy: ['text'] } },
  { label: 'Link', type: 'link', id: 'kpwlin', connections: { kpprzy: ['link'] } },
  { label: 'Tłumaczenie', type: 'text', id: 'kpwrec', connections: { kpprzy: ['evk_tl_en__text'] } },
] };
const K3 = { id: 'kpikon', elements: [{ id: 'kpikon', name: 'icon-box', parent: 0, children: [], settings: { text: 'Ikona' } }],
  properties: [{ label: 'Opis', type: 'text', id: 'kpwopi', connections: { kpikon: ['text'] } }] };

/** JS: kopia z uzupełnieniem — jak PHP, który oddaje nową listę. */
const js = (komponenty, jezyki, mapa) => { const k = kopia(komponenty); KP.uzupelnij(k, jezyki, mapa); return k; };
const wlasciwosci = (lista) => (lista || []).map((k) => [k.id, (k.properties || []).map((p) => [p.id, p.label, p.type, p.connections])]);

module.exports = async function (t) {
  t.section('środowisko: testowy WordPress');
  const wp = sonda('wp');
  t.check('testowy WordPress jest (tools/testowy-wp.sh)', !wp.brak && !!wp.wp, wp.brak || wp.wp);
  if (wp.brak || !wp.wp) return;
  sonda('sprzataj');
  const m = sonda('modul');
  t.check('moduł Tłumaczeń włączony, języki EN i DE', m.gotowe === true, J(m));

  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'evk-t-komponenty-'));
  try {
    // ── Czysta funkcja: PHP i JS ─────────────────────────────────────────
    t.section('właściwości „… EN”: PHP i JS na tych samych przypadkach');
    const baza = js([K1, K2, K3], ['en', 'de'], MAPA);
    const zmienionaEtykieta = kopia(baza); zmienionaEtykieta[0].properties.find((p) => p.id === 'kpwnag').label = 'Tytuł';
    const bezTekstu = kopia(baza); bezTekstu[0].properties = bezTekstu[0].properties.filter((p) => p.id !== 'kpwtek');
    const przypadki = [
      { opis: 'K1–K3, EN i DE', komponenty: [K1, K2, K3], jezyki: ['en', 'de'], mapa: MAPA },
      { opis: 'gotowe — bez zmian', komponenty: baza, jezyki: ['en', 'de'], mapa: MAPA },
      { opis: 'nowy język FR', komponenty: baza, jezyki: ['en', 'de', 'fr'], mapa: MAPA },
      { opis: 'wyłączony DE', komponenty: baza, jezyki: ['en'], mapa: MAPA },
      { opis: 'zmieniona etykieta „Nagłówek” → „Tytuł”', komponenty: zmienionaEtykieta, jezyki: ['en', 'de'], mapa: MAPA },
      { opis: 'usunięta właściwość „Tekst”', komponenty: bezTekstu, jezyki: ['en', 'de'], mapa: MAPA },
      /* 1.274.0, prawdziwy Bricks: przejście z pustą mapą kasowało bliźniaki razem z tłumaczeniami w instancjach. */
      { opis: 'pusta mapa pól', komponenty: baza, jezyki: ['en', 'de'], mapa: {} },
      { opis: 'mapa bez nagłówka', komponenty: baza, jezyki: ['en', 'de'], mapa: { 'text-basic': MAPA['text-basic'], button: MAPA.button } },
    ];
    const idy = [['kpkomp', 'kpwnag', 'en'], ['oxtyev', 'xjpsxa', 'en'], ['a', 'b', 'pt_br'], ['ąę', 'ż', 'de']];
    const plik = path.join(tmp, 'wektory.json');
    fs.writeFileSync(plik, J({ przypadki, idy }));
    const w = sonda('wektory', plik);
    const zJs = przypadki.map((p) => js(p.komponenty, p.jezyki, p.mapa));
    przypadki.forEach((p, i) => {
      const a = wlasciwosci((w.przypadki || [])[i]), b = wlasciwosci(zJs[i]);
      t.check('PHP = JS: ' + p.opis, J(a) === J(b), J({ php: a, js: b }));
    });
    const idyJs = idy.map((x) => KP.id(x[0], x[1], x[2]));
    t.check('identyfikatory: PHP = JS, 6 małych liter', J(w.idy) === J(idyJs) && idyJs.every((x) => /^[a-z]{6}$/.test(x)), J([w.idy, idyJs]));

    const etykiety = (k) => (k.properties || []).map((p) => p.label);
    const k1 = zJs[0][0], k2 = zJs[0][1], k3 = zJs[0][2];
    console.log('      K1: ' + J(etykiety(k1)) + '\n      K2: ' + J(etykiety(k2)) + '\n      K3: ' + J(etykiety(k3)));
    const en = KP.id('kpkomp', 'kpwnag', 'en');
    t.check('tekst z właściwością: „Nagłówek EN/DE” tuż pod „Nagłówek”, połączone z `evk_tl_{język}__text` tego samego elementu, stały id',
      J(etykiety(k1)) === J(['Tekst', 'Tekst EN', 'Tekst DE', 'Nagłówek', 'Nagłówek EN', 'Nagłówek DE'])
      && J(k1.properties[4]) === J({ label: 'Nagłówek EN', type: 'text', id: en, connections: { kpnagl: ['evk_tl_en__text'] } }), J(k1.properties));
    t.check('ręczna „Tłumaczenie” uznana (bez drugiego EN) i przeniesiona pod „Treść”; „Link” bez bliźniaka; element spoza mapy bez zmian',
      J(etykiety(k2)) === J(['Treść', 'Tłumaczenie', 'Treść DE', 'Link']) && J(k3.properties) === J(K3.properties), J([etykiety(k2), etykiety(k3)]));
    const k1b = kopia(baza);
    t.check('uzupełnione drugi raz — bez zmian (JS mówi „nic”, tablica nietknięta)', KP.uzupelnij(k1b, ['en', 'de'], MAPA) === false && J(k1b) === J(baza), '');
    t.check('wyłączony DE: bliźniaki DE zostają (tłumaczenia w instancjach nie giną); nowy FR — pod DE',
      J(etykiety(zJs[3][0])) === J(etykiety(baza[0])) && J(etykiety(zJs[2][0])) === J(['Tekst', 'Tekst EN', 'Tekst DE', 'Tekst FR', 'Nagłówek', 'Nagłówek EN', 'Nagłówek DE', 'Nagłówek FR']),
      J([etykiety(zJs[3][0]), etykiety(zJs[2][0])]));
    t.check('zmieniona etykieta — bliźniaki za nią (ten sam id); usunięta właściwość — jej bliźniaki znikają',
      J(etykiety(zJs[4][0])) === J(['Tekst', 'Tekst EN', 'Tekst DE', 'Tytuł', 'Tytuł EN', 'Tytuł DE']) && zJs[4][0].properties[4].id === en
      && J(etykiety(zJs[5][0])) === J(['Nagłówek', 'Nagłówek EN', 'Nagłówek DE']), J([etykiety(zJs[4][0]), etykiety(zJs[5][0])]));

    t.check('pusta albo niepełna mapa pól: żaden bliźniak nie znika — para nie do rozpoznania to nie sierota',
      J(wlasciwosci(zJs[6])) === J(wlasciwosci(baza)) && J(wlasciwosci(zJs[7])) === J(wlasciwosci(baza)), J([etykiety(zJs[6][0]), etykiety(zJs[7][0])]));

    // ── Serwer ───────────────────────────────────────────────────────────
    t.section('serwer: zapis komponentów, języki, hurt AI, listy, prawa');
    const u = sonda('ustaw');
    t.check('sonda: komponenty zapisane, strona z instancjami', !u.brak && u.gotowe === true, u.brak || J(u).slice(0, 300));
    if (!u.gotowe) return;
    t.check('zapis opcji komponentów: bliźniaki dołożone filtrem (jak JS)', J(wlasciwosci(u.opcja)) === J(wlasciwosci(js([K1, K2], ['en', 'de'], MAPA))),
      J(wlasciwosci(u.opcja)));
    const j3 = sonda('jezyki', 'en,de,fr');
    const j1 = sonda('jezyki', 'en');
    sonda('jezyki', 'en,de');
    t.check('nowy język FR w Tłumaczeniach: „… FR” we wszystkich komponentach bez otwierania buildera; usunięcie języka niczego nie kasuje',
      J(etykiety(j3.opcja[0])) === J(['Tekst', 'Tekst EN', 'Tekst DE', 'Tekst FR', 'Nagłówek', 'Nagłówek EN', 'Nagłówek DE', 'Nagłówek FR'])
      && J(etykiety(j3.opcja[1])) === J(['Treść', 'Tłumaczenie', 'Treść DE', 'Treść FR', 'Link']) && J(etykiety(j1.opcja[0])) === J(etykiety(j3.opcja[0])),
      J([etykiety(j3.opcja[0]), etykiety(j3.opcja[1]), etykiety(j1.opcja[0])]));

    const jd = sonda('jednostki');
    const jl = (jd.jednostki || []).map((j) => [j.tytul, j.czesc, j.braki, j.opcje || '', j.grupa]);
    console.log('      jednostki: ' + J(jl));
    t.check('lista hurtu: strona z instancjami (teksty właściwości liczone jak pola) i wiersz „Komponenty Bricksa” z tekstami stałymi',
      J(jd.wiersz) === J({ nazwa: 'Komponenty Bricksa', rodzaj: 'wpis', czesci: ['bricks'] })
      && J(jl) === J([['Strona komponentów KP', 'Treść', { en: 4, de: 5 }, '', 'Pages'], ['Komponent: Karta oferty', 'Teksty stałe', { en: 1, de: 1 }, 'kpkomp', 'Komponenty Bricksa']]),
      J([jd.wiersz, jl]));

    const ke = sonda('krok', 'en');
    const el = (id) => (ke.strona || []).find((e) => e.id === id) || {};
    const bl = (pid, l) => KP.id('kpkomp', pid, l);
    t.check('hurt EN strony: teksty właściwości do bliźniaków w instancji, gotowe „Heading A” nietknięte, instancja bez wartości pominięta, zwykły element jak dotąd',
      J(el('kpia').properties) === J({ kpwnag: 'Nagłówek A', kpwtek: 'Tekst A', [bl('kpwnag', 'en')]: 'Heading A', [bl('kpwtek', 'en')]: 'EN:Tekst A' })
      && J(el('kpib').properties) === J({ kpwnag: 'Nagłówek B', [bl('kpwnag', 'en')]: 'EN:Nagłówek B' }) && el('kpic').properties === undefined
      && J(el('kpid').properties) === J({ kpwtre: 'Zadzwoń', kpwrec: 'EN:Zadzwoń' }) && el('kph1').settings.evk_tl_en__text === 'EN:Zwykły nagłówek'
      && ke.zadania === 1, J([ke.strona, ke.zadania]));
    t.check('zapytanie: teksty instancji z nazwą komponentu i właściwości, obecne tłumaczenie w kontekście',
      /\[Komponent Karta oferty · Nagłówek\] "Nagłówek A" → "Heading A"/.test(ke.wiadomosc || '') && /"element": "Komponent Przycisk · Treść"/.test(ke.wiadomosc || ''),
      (ke.wiadomosc || '').slice(0, 500));
    const st = (ke.stan || {})._bricks_page_content_2 || {};
    t.check('stan „Do sprawdzenia”: klucze `{instancja}|prop:{właściwość}|{język}`, AI — źródło „ai”; ręczne „Heading A” — skrót oryginału',
      (st['kpia|prop:kpwtek|en'] || {}).src === 'ai' && (st['kpib|prop:kpwnag|en'] || {}).src === 'ai' && /^[0-9a-f]{32}$/.test((st['kpia|prop:kpwnag|en'] || {}).src || ''),
      J(st));
    const kk = sonda('krok-kp', 'kpkomp', 'en');
    const ust = (id) => (((kk.opcja || [])[0] || {}).elements || []).find((e) => e.id === id).settings;
    t.check('hurt tekstów stałych: tylko pole bez właściwości (do opcji komponentów), nagłówek z właściwością nietknięty, stan w `evk_tl_kp_stan`',
      ust('kpstal').evk_tl_en__text === 'EN:Tekst stały komponentu' && ust('kpnagl').evk_tl_en__text === 'KOMPONENT EN' && ust('kptekst').evk_tl_en__text === undefined
      && ((kk.stan_kp || {}).kpkomp || {})['kpstal|text|en'] && kk.stan_kp.kpkomp['kpstal|text|en'].src === 'ai' && kk.zadania === 1, J([kk.opcja && kk.opcja[0].elements, kk.stan_kp]));

    const li = sonda('lista');
    const lw = (li.wiersze || []).map((x) => [x.klucz, x.element, x.pole, x.ai]);
    const lk = (li.kp || []).map((x) => [x.klucz, x.tytul, x.pole, x.ai]);
    console.log('      do sprawdzenia: ' + J(lw) + '\n      komponenty: ' + J(lk));
    t.check('„Do sprawdzenia”: teksty instancji z nazwą komponentu i właściwości; tekst stały z tytułem „Komponent: …”',
      J(lw) === J([['kpia|prop:kpwtek|en', 'Komponent Karta oferty', 'Tekst', true], ['kpib|prop:kpwnag|en', 'Komponent Karta oferty', 'Nagłówek', true],
        ['kpid|prop:kpwtre|en', 'Komponent Przycisk', 'Treść', true], ['kph1|text|en', 'heading', 'text', true]])
      && J(lk) === J([['kpkomp#kpstal|text|en', 'Komponent: Karta oferty', 'text', true]]), J([lw, lk]));
    const h = li.html || '';
    t.check('sekcja: tekst stały bez odnośnika (edycja w builderze), właściwość instancji bez „Na stronie”, zwykły element z nim',
      h.includes('<td>Komponent: Karta oferty</td>') && !/evk-tls=kpia/.test(h) && /Na stronie: Strona komponentów KP, text, EN/.test(h)
      && !/Na stronie: Strona komponentów KP, Tekst, EN/.test(h), h.length);
    const tx = sonda('teksty');
    t.check('„Teksty w elementach”: wiersze właściwości instancji obok zwykłych, ze stanem „ai” albo „ok”',
      J((tx.wiersze || []).map((x) => [x.id, x.sciezka, x.opis, x.en[0], x.en[2]])) === J([['kpia', 'prop:kpwtek', 'Tekst', 'EN:Tekst A', 'ai'],
        ['kpia', 'prop:kpwnag', 'Nagłówek', 'Heading A', 'ok'], ['kpib', 'prop:kpwnag', 'Nagłówek', 'EN:Nagłówek B', 'ai'], ['kpid', 'prop:kpwtre', 'Treść', 'EN:Zadzwoń', 'ai'],
        ['kph1', 'text', 'Tekst', 'EN:Zwykły nagłówek', 'ai']]), J(tx.wiersze));
    const sp1 = sonda('ajax-sprawdzone', 'evk_komponent', 'kpkomp#kpstal|text|en');
    const sp2 = sonda('ajax-sprawdzone', '_bricks_page_content_2', 'kpib|prop:kpwnag|en', String(u.strona));
    const li2 = sonda('lista');
    t.check('„Sprawdzone”: tekst stały i właściwość instancji znikają z listy', sp1.odp && sp1.odp.success === true && sp2.odp && sp2.odp.success === true
      && (li2.kp || []).length === 0 && !(li2.wiersze || []).some((x) => x.klucz === 'kpib|prop:kpwnag|en') && (li2.wiersze || []).length === 3,
      J([sp1.odp, sp2.odp, (li2.wiersze || []).map((x) => x.klucz), li2.kp]));
    const ar = sonda('ajax-krok-kp', 'redaktor', 'kpkomp', 'de');
    const aa = sonda('ajax-krok-kp', 'admin', 'kpkomp', 'de');
    t.check('krok tekstów stałych przez AJAX: redaktor (bez praw administratora) — 403 bez pytania AI; administrator — zapisane',
      ar.odp && ar.odp.success === false && ar.odp.data === 'Brak uprawnień do komponentów.' && ar.zadania === 0
      && aa.odp && aa.odp.success === true && aa.odp.data.zapisane === 1, J([ar, aa]));
  } finally {
    sonda('sprzataj');
    fs.rmSync(tmp, { recursive: true, force: true });
  }

  // ── Builder ────────────────────────────────────────────────────────────
  t.section('builder: bliźniaki na żywo, ✦ na instancji i w edycji komponentu (Chromium)');
  const DANE = { jezyki: ['pl', 'en', 'de'], mapa: MAPA, slownik: {}, napisy: {},
    ai: { ajax: '/wp-admin/admin-ajax.php', nonce: 'test', post: 1, model: 'atrapa/model', porcja: 25, znaki: 6000 } };
  const page = await t.open('builder-ai.html', { przezHttp: true, settle: 900, viewport: { width: 1300, height: 820 }, head: 'window.__EVK_TL_DANE = ' + J(DANE) + ';' });
  const zadania = [];
  await page.route('**/wp-admin/admin-ajax.php', async (route) => {
    const q = Object.fromEntries(new URLSearchParams(route.request().postData() || ''));
    const kontekst = JSON.parse(q.kontekst || '[]'), teksty = JSON.parse(q.teksty || '{}');
    zadania.push({ kontekst, teksty });
    const tl = {};
    Object.keys(teksty).forEach((k) => { tl[k] = 'EN:' + ((kontekst[teksty[k].n - 1] || {}).pl || ''); });
    await route.fulfill({ status: 200, contentType: 'application/json', body: J({ success: true, data: { tlumaczenia: tl, zrodla: {} } }) });
  });
  page.on('dialog', (d) => d.accept());
  await page.evaluate(([k1]) => {
    window.__stan.components = [k1];
    window.__stan.content.push(
      { id: 'kpia', name: 'block', parent: 0, children: [], settings: {}, cid: 'kpkomp', properties: { kpwnag: 'Nagłówek A', kpwtek: 'Tekst A' } },
      { id: 'kpic', name: 'block', parent: 0, children: [], settings: {}, cid: 'kpkomp' });
  }, [kopia(K1)]);
  await page.waitForTimeout(1500);
  const wStanie = await page.evaluate(() => window.__stan.components[0].properties.map((p) => p.label + ':' + p.id));
  t.check('bliźniaki w stanie buildera po chwili, bez zapisu — te same id co serwer',
    J(wStanie) === J(wlasciwosci(js([K1], ['en', 'de'], MAPA))[0][1].map((p) => p[1] + ':' + p[0])), J(wStanie));
  await page.click('#evk-tl-podglad button[data-jezyk="en"]');
  await page.evaluate(() => { window.__stan.activeId = 'kpia'; });
  await page.waitForTimeout(500);
  await page.click('#evk-tl-ai-element');
  await page.waitForFunction(() => /^EN: /.test(document.getElementById('evk-tl-ai-stan').textContent), null, { timeout: 15000 }).catch(() => {});
  const inst = await page.evaluate(() => ({ p: window.__stan.content.find((e) => e.id === 'kpia').properties, stan: document.getElementById('evk-tl-ai-stan').textContent }));
  t.check('✦ na instancji: teksty właściwości do bliźniaków EN w `properties`, komunikat bez „komponenty pominięte”',
    J(inst.p) === J({ kpwnag: 'Nagłówek A', kpwtek: 'Tekst A', [KP.id('kpkomp', 'kpwtek', 'en')]: 'EN:Tekst A', [KP.id('kpkomp', 'kpwnag', 'en')]: 'EN:Nagłówek A' })
    && inst.stan === 'EN: wpisane 2. Zapisz stronę w Bricksie.', J(inst));
  /* Tryb edycji komponentu: zaznaczony korzeń — tylko tekst stały, pola z właściwością zostają. */
  await page.evaluate(() => { window.__stan.activeComponent = window.__stan.components[0]; window.__stan.activeId = 'kpkomp'; });
  await page.waitForTimeout(500);
  const przed = zadania.length;
  await page.click('#evk-tl-ai-element');
  await page.waitForFunction(() => /^EN: /.test(document.getElementById('evk-tl-ai-stan').textContent) && !/wpisane 2/.test(document.getElementById('evk-tl-ai-stan').textContent),
    null, { timeout: 15000 }).catch(() => {});
  const komp = await page.evaluate(() => ({ el: window.__stan.components[0].elements.map((e) => [e.id, e.settings.evk_tl_en__text || null]),
    stan: document.getElementById('evk-tl-ai-stan').textContent }));
  const zap = zadania.slice(przed).map((z) => z.kontekst.map((k) => k.pl));
  t.check('✦ w edycji komponentu: tłumaczy tekst stały, pomija pola z właściwościami (także w kontekście)',
    J(komp.el) === J([['kpkomp', null], ['kpnagl', 'KOMPONENT EN'], ['kptekst', null], ['kpstal', 'EN:Tekst stały komponentu']])
    && J(zap) === J([['Tekst stały komponentu']]) && komp.stan === 'EN: wpisane 1. Zapisz stronę w Bricksie.', J([komp, zap]));

  /* ✦ we „Właściwościach” instancji (1.273.0). Panel jak w Bricksie 2.4.2 (wynik z konsoli 01.10):
     #bricks-panel-component-instance › ul.properties › li: `.label span` z etykietą, kontrolka
     `[data-control][propertyid]` z textarea i ⚡ w rogu. Vue przerysowuje panel — tu `__panel()`. */
  t.section('builder: ✦ przy polach tłumaczeń we „Właściwościach” instancji');
  const dialogi = [];
  page.on('dialog', (d) => dialogi.push(d.message()));
  await page.evaluate(() => {
    window.__stan.activeComponent = null;
    window.__stan.content.push({ id: 'kpix', name: 'block', parent: 0, children: [], settings: {}, cid: 'kpkomp', properties: { kpwnag: 'Nagłówek X' } });
    window.__panel = function () {
      var inst = window.__stan.content.find(function (e) { return e.id === 'kpix'; });
      var k = window.__stan.components[0];
      var stary = document.getElementById('bricks-panel-element');
      if (stary) stary.remove();
      var p = document.createElement('div');
      p.id = 'bricks-panel-element';
      p.className = 'instance';
      p.innerHTML = '<div id="bricks-panel-component-instance"><div class="panel-content"><div class="bricks-panel-controls">'
        + '<div class="group-wrapper" data-group-id="groupless"><ul class="properties"></ul></div></div></div></div>';
      var ul = p.querySelector('ul');
      k.properties.forEach(function (w) {
        var li = document.createElement('li');
        li.innerHTML = '<div class="label"><div class="has-setting"><span class="indicator"></span></div><i class="ti-text"></i><span></span>'
          + '<span class="bricks-svg-wrapper edit" data-name="edit">✎</span></div><div class="control control-textarea no-label"><div class="control-inner">'
          + '<div data-control="textarea" class="auto-height" style="position:relative"><textarea rows="1" style="height:32px;padding:6px 8px;font-size:16px;line-height:20px"></textarea>'
          + '<div class="dynamic-tag-picker-button" style="position:absolute;top:6px;right:4px;width:20px;height:20px">⚡</div></div></div></div>';
        li.querySelector('.label span:not(.indicator)').textContent = w.label;
        var ctl = li.querySelector('[data-control]');
        ctl.setAttribute('propertyid', w.id);
        var ta = li.querySelector('textarea');
        ta.value = (inst.properties || {})[w.id] || '';
        ta.addEventListener('input', function () { inst.properties = inst.properties || {}; inst.properties[w.id] = ta.value; });
        ul.appendChild(li);
      });
      document.getElementById('bricks-panel').appendChild(p);
    };
    /* Bliźniak języka spoza ustawień (FR; fixtura ma EN i DE) — zostaje w komponencie (51), ✦ przy nim nie ma. */
    window.__stan.components[0].properties.push({ label: 'Nagłówek FR', type: 'text', id: 'kpwnfr', connections: { kpnagl: ['evk_tl_fr__text'] } });
    window.__panel();
    window.__stan.activeId = 'kpix';
  });
  await page.waitForTimeout(700);
  const ikony = () => page.evaluate(() => Array.from(document.querySelectorAll('#bricks-panel-component-instance .evk-tl-ai-ikona')).map((b) => {
    const r = b.getBoundingClientRect();
    return [b.getAttribute('aria-label'), b.getAttribute('data-balloon'), b.getAttribute('data-balloon-pos'), b.getAttribute('data-balloon-length'), Math.round(r.width) > 0];
  }));
  const ik = await ikony();
  t.check('✦ tylko przy polach języków z ustawień (nie przy „Tekst”, „Nagłówek” ani „Nagłówek FR”), z nazwą pola i dymkiem jak przy polach elementów', J(ik) === J([
    ['Przetłumacz (AI) — Tekst EN', 'Przetłumacz z polskiego używając atrapa/model', 'top-right', 'medium', true],
    ['Przetłumacz (AI) — Tekst DE', 'Przetłumacz z polskiego używając atrapa/model', 'top-right', 'medium', true],
    ['Przetłumacz (AI) — Nagłówek EN', 'Przetłumacz z polskiego używając atrapa/model', 'top-right', 'medium', true],
    ['Przetłumacz (AI) — Nagłówek DE', 'Przetłumacz z polskiego używając atrapa/model', 'top-right', 'medium', true]]), J(ik));
  /* Pole jak w Bricksie 2.4.2 (prawdziwy builder, bricks-builder): jeden wiersz, ⚡ 20×20 w rogu.
     ✦ pod ⚡ wisiało pod polem — ma stać na lewo od ⚡, w polu. */
  const geo = await page.evaluate(() => {
    const r = (e) => { const x = e.getBoundingClientRect(); return { l: Math.round(x.left), r: Math.round(x.right), t: Math.round(x.top), b: Math.round(x.bottom) }; };
    return Array.from(document.querySelectorAll('#bricks-panel-component-instance .evk-tl-ai-ikona')).map((b) => {
      const li = b.closest('li');
      const ta = li.querySelector('textarea');
      return { ik: r(b), bolt: r(li.querySelector('.dynamic-tag-picker-button')), pole: r(ta), pad: parseFloat(getComputedStyle(ta).paddingRight) };
    });
  });
  t.check('jednowierszowe pole: ✦ w polu, na lewo od ⚡, w tym samym wierszu; tekst nie wchodzi pod ikonki',
    geo.length === 4 && geo.every((g) => g.ik.t >= g.pole.t && g.ik.b <= g.pole.b && g.ik.r <= g.bolt.l && Math.abs(g.ik.t - g.bolt.t) <= 1
      && g.pad >= g.pole.r - g.ik.l), J(geo[0]));
  const tN = KP.id('kpkomp', 'kpwnag', 'en'), tT = KP.id('kpkomp', 'kpwtek', 'en');
  const klik = async (tid) => {
    await page.click('[propertyid="' + tid + '"] .evk-tl-ai-ikona');
    await page.waitForFunction((t) => { const s = document.querySelector('[propertyid="' + t + '"]').closest('li').querySelector('.evk-tl-ai-pole-stan');
      return s && s.textContent && !/^Tłumaczę/.test(s.textContent); }, tid, { timeout: 15000 }).catch(() => {});
    return page.evaluate((t) => { const li = document.querySelector('[propertyid="' + t + '"]').closest('li');
      return { stan: li.querySelector('.evk-tl-ai-pole-stan').textContent, pole: li.querySelector('textarea').value,
        wl: window.__stan.content.find((e) => e.id === 'kpix').properties[t] || null }; }, tid);
  };
  const przedW = zadania.length;
  const w1 = await klik(tN);
  t.check('klik ✦ przy „Nagłówek EN”: tłumaczenie polskiego „Nagłówek” tej instancji w jej `properties` i w polu panelu, jedno zapytanie',
    w1.wl === 'EN:Nagłówek X' && w1.pole === 'EN:Nagłówek X' && w1.stan === 'Wpisane (AI · atrapa/model). Zapisz stronę w Bricksie.'
    && zadania.length === przedW + 1, J([w1, zadania.length - przedW]));
  const w2 = await klik(tT);
  t.check('„Tekst EN” bez polskiego tekstu w instancji: komunikat, bez zapytania', w2.stan === 'Brak polskiego tekstu w tej właściwości.' && w2.wl === null
    && zadania.length === przedW + 1, J([w2, zadania.length - przedW]));
  const przedD = dialogi.length;
  await klik(tN);
  t.check('pole już wypełnione: pytanie „Zastąpić obecne tłumaczenie EN?”', dialogi.length === przedD + 1 && /^Zastąpić obecne tłumaczenie EN\?/.test(dialogi[przedD] || ''),
    J(dialogi.slice(przedD)));
  await page.evaluate(() => window.__panel());
  await page.waitForTimeout(700);
  const poPrzer = await page.evaluate(() => Array.from(document.querySelectorAll('#bricks-panel-component-instance li')).map((li) => li.querySelectorAll('.evk-tl-ai-ikona').length));
  t.check('przerysowany panel: znów po jednym ✦ przy każdym polu języka', J(poPrzer) === J([0, 1, 1, 0, 1, 1, 0]), J(poPrzer));
  await page.evaluate(() => { window.__stan.activeId = 'h2'; });
  await page.waitForTimeout(700);
  t.check('zaznaczony zwykły element: ✦ znikają z panelu właściwości', (await ikony()).length === 0, J(await ikony()));
};
