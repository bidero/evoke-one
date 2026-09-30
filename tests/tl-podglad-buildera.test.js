/**
 * Podgląd tłumaczeń w builderze Bricksa (1.257.0, #82): przełącznik PL | EN | DE
 * w pasku, obok breakpointów, i kanwa w wybranym języku — na żywo z pól języków.
 *
 * Decyzje zgłaszającego: przełącznik w środku paska; tekst bez tłumaczenia polski
 * z obrysem i „brak EN" (obrazy bez obrysu); kliknięty tekst na czas edycji
 * polski; po przeładowaniu buildera start z PL.
 *
 * Bricksa tu nie ma. Fixtura odtwarza to, co pokazały dwie próby na testowej
 * (Bricks 2.4.2): stan Vue powłoki i osobny stan kanwy, pasek z trzema grupami,
 * korzenie `#brxe-{id}`, oraz edycję w miejscu, która zapisuje tekst z kanwy do
 * ustawień PRZY KAŻDYM ZNAKU. Dane skryptu idą z prawdziwego PHP (sonda).
 */

const { phpOutput } = require('./lib/harness');

const sonda = (...a) => {
  const w = phpOutput('tl-podglad-buildera.php', a.join(' '), { dopuscBlad: true });
  try { return JSON.parse(w); } catch (e) { return { brak: 'sonda „' + a.join(' ') + '": ' + w.slice(0, 300) }; }
};
const J = (v) => JSON.stringify(v);

module.exports = async function (t) {
  t.section('PHP: co trafia do kanwy, a co nie');
  const przyg = sonda('przygotuj');
  t.check('testowy WordPress z Tłumaczeniami (tools/testowy-wp.sh)', !przyg.brak && przyg.gotowe === true, przyg.brak || J(przyg));
  if (przyg.brak) return;

  let dane = null;
  try {
    const st = {};
    for (const k of ['kanwa', 'kanwa-gosc', 'powloka', 'front']) st[k] = sonda('stopka', k);
    t.check('kanwa: dane i skrypt podglądu, bez starego skryptu {tl_…}', st.kanwa.dane && st.kanwa.skrypt && !st.kanwa.stary, J(st.kanwa));
    t.check('dane: „</script>" z frazy słownika zostaje tekstem', st.kanwa.json_poprawny === true && st.kanwa.uwaga_en === 'Mind </script><b>', J(st.kanwa));
    t.check('powłoka buildera: bez podglądu, stary skrypt {tl_…} jak dotąd', !st.powloka.dane && !st.powloka.skrypt && st.powloka.stary, J(st.powloka));
    t.check('strona: bez podglądu i bez skryptu {tl_…}', !st.front.dane && !st.front.skrypt && !st.front.stary, J(st.front));
    t.check('kanwa bez prawa edycji: bez podglądu', !st['kanwa-gosc'].dane && !st['kanwa-gosc'].skrypt, J(st['kanwa-gosc']));

    const d = sonda('dane');
    dane = d.dane || null;
    t.check('dane: języki, mapa pól, słownik z tl_dd_keys i z wiersza (dd_key)',
      !!dane && J(dane.jezyki) === J(['pl', 'en', 'de']) && J(((dane.mapa || {}).heading || {}).pola) === J(['text'])
      && J((dane.slownik || {}).zobacz) === J({ pl: 'Zobacz więcej', en: 'See more', de: 'Mehr sehen' })
      && J((dane.slownik || {}).napisz) === J({ pl: 'Napisz do nas', en: 'Write to us' }), J(d.brak || dane));
  } finally {
    sonda('sprzataj');
  }
  if (!dane) return;

  const page = await t.open('builder-podglad.html', { przezHttp: true, settle: 900, viewport: { width: 1100, height: 800 },
    head: 'window.__EVK_TL_DANE = ' + JSON.stringify(dane) + ';' });
  /* Nieudane żądania z adresem — sam komunikat konsoli „404" go nie podaje. */
  const zleZadania = [];
  page.on('response', (r) => { if (r.status() >= 400) zleZadania.push(r.status() + ' ' + r.url()); });
  let kanwa = page.frames().find((f) => /builder-podglad-kanwa\.html/.test(f.url()));
  const K = (fn, arg) => kanwa.evaluate(fn, arg);
  const html = (sel) => K((s) => { const e = document.querySelector(s); return e ? e.innerHTML : null; }, sel);
  const tekst = (sel) => K((s) => Array.from(document.querySelectorAll(s)).map((e) => e.textContent), sel);
  const obrys = (id) => K((i) => document.getElementById('brxe-' + i).classList.contains('evk-tl-brak'), id);
  /* Napis „brak EN" (jeden, po najechaniu): tekst, dla którego elementu i o ile
     odstaje od prawego górnego rogu ramki (obrys 2 px + odsunięcie 2 px). Nad
     ramką, bo na dole po lewej Bricks pisze nazwę elementu (zgłoszenie po
     1.257.1); bez miejsca nad ramką — w ramce, u góry (`wewnatrz`). */
  const napisPod = () => K(() => {
    const z = document.querySelector('#evk-tl-nakladka .evk-tl-znacznik');
    if (!z || z.hidden) return { widoczny: false };
    const dla = z.getAttribute('data-dla');
    const el = document.querySelector('[data-id="' + dla + '"]');
    const a = z.getBoundingClientRect(), b = el ? el.getBoundingClientRect() : null;
    const z1 = (v) => Math.round(v * 10) / 10;
    return { widoczny: true, tekst: z.textContent, dla, wewnatrz: z.classList.contains('wewnatrz'),
      dx: b ? z1(a.right - (b.right + 4)) : null, dy: b ? z1(a.bottom - (b.top - 4)) : null,
      dxW: b ? z1(a.right - b.right) : null, dyW: b ? z1(a.top - b.top) : null };
  });
  const przyRamce = (n) => n.widoczny && !n.wewnatrz && Math.abs(n.dx) <= 1 && Math.abs(n.dy) <= 1;
  const edycjaRamka = (id) => K((i) => document.getElementById('brxe-' + i).classList.contains('evk-tl-edycja'), id);
  const wcisniety = () => page.evaluate(() => Array.from(document.querySelectorAll('#evk-tl-podglad button[aria-pressed="true"]')).map((b) => b.textContent));

  t.section('przełącznik w pasku buildera');
  const grupa = await page.evaluate(() => {
    const u = document.getElementById('evk-tl-podglad');
    if (!u) return null;
    return { przed: u.previousElementSibling && u.previousElementSibling.className, rola: u.getAttribute('role'), etykieta: u.getAttribute('aria-label'),
      przyciski: Array.from(u.querySelectorAll('button')).map((b) => b.textContent + ':' + b.getAttribute('aria-pressed')) };
  });
  t.check('grupa PL | EN | DE zaraz za breakpointami, PL wciśnięty',
    !!grupa && /center/.test(grupa.przed) && grupa.rola === 'group' && !!grupa.etykieta && J(grupa.przyciski) === J(['PL:true', 'EN:false', 'DE:false']), J(grupa));
  /* Sonda „dane” działa bez zalogowanego użytkownika — bez dostępu do
     Tłumaczeń, więc bez przycisków AI (1.265.0, tl-ai-builder). */
  t.check('bez danych AI: bez przycisku „Przetłumacz (AI)” w pasku', dane.ai === null
    && await page.evaluate(() => !document.getElementById('evk-tl-ai') && !document.getElementById('evk-tl-ai-dymek')), J(dane.ai));
  t.check('start z PL: kanwa bez zmian, {tl_…} po polsku jak dotąd',
    await html('#brxe-h1') === 'Grafika<br>użytkowa' && J(await tekst('#brxe-d1')) === J(['Zobacz więcej'])
    && !(await K(() => document.querySelector('.evk-tl-brak'))), J([await html('#brxe-h1'), await tekst('#brxe-d1')]));

  t.section('EN z klawiatury');
  await page.focus('.group-wrapper.center li:last-child button');
  await page.keyboard.press('Tab');
  await page.keyboard.press('Tab');
  await page.keyboard.press('Enter');
  await page.waitForTimeout(400);
  t.check('Tab do EN i Enter: EN wciśnięty', J(await wcisniety()) === J(['EN']), J(await wcisniety()));
  t.check('nagłówek i przycisk po angielsku (ikona przycisku zostaje), bez obrysu',
    await html('#brxe-h1') === 'Graphic<br>design' && J(await tekst('#brxe-b1 .tekst')) === J(['Contact']) && J(await tekst('#brxe-b1 .ikona')) === J(['★'])
    && !(await obrys('h1')) && !(await obrys('b1')), J([await html('#brxe-h1'), await html('#brxe-b1')]));
  t.check('element w elemencie: każdy swoje (sekcja, icon-box z nagłówkiem o tym samym tekście)',
    J(await tekst('#brxe-h2')) === J(['Offer']) && J(await tekst('#brxe-h3')) === J(['Offer H']) && J(await tekst('#brxe-k1 .opis')) === J(['Offer K']),
    J([await tekst('#brxe-h2'), await tekst('#brxe-h3'), await tekst('#brxe-k1 .opis')]));
  t.check('pozycje listy: przetłumaczona po angielsku; bez tłumaczenia polska, a element z obrysem',
    J(await tekst('#brxe-a1 .title')) === J(['One', 'Dwa']) && await obrys('a1') && J(await tekst('#brxe-sl1 .tytul')) === J(['Slide']),
    J([await tekst('#brxe-a1 .title'), await tekst('#brxe-sl1 .tytul')]));
  await kanwa.hover('#brxe-t1');
  await page.waitForTimeout(100);
  const nT1 = await napisPod();
  t.check('tekst bez tłumaczenia: polski z obrysem, po najechaniu napis „brak EN" nad ramką, przy prawym rogu',
    J(await tekst('#brxe-t1')) === J(['Zapytaj o wycenę']) && await obrys('t1') && nT1.tekst === 'brak EN' && nT1.dla === 't1' && przyRamce(nT1),
    J([await tekst('#brxe-t1'), nT1]));
  await kanwa.hover('#brxe-b1');
  await page.waitForTimeout(100);
  t.check('najechanie na przetłumaczony element: bez napisu', !(await napisPod()).widoczny, J(await napisPod()));
  await kanwa.hover('#brxe-a1');
  await page.waitForTimeout(100);
  const nA1 = await napisPod();
  await kanwa.click('#brxe-a1');
  await page.waitForTimeout(400);
  const poKlik = await napisPod();
  await kanwa.hover('#brxe-t1');
  await page.waitForTimeout(100);
  const naInnym = await napisPod();
  t.check('kliknięcie w element chowa napis (jak w Bricksie); przy następnym elemencie wraca',
    nA1.dla === 'a1' && !poKlik.widoczny && naInnym.dla === 't1', J([nA1, poKlik, naInnym]));
  await K(() => window.scrollBy(0, 5));
  await page.waitForTimeout(50);
  const wTrakcie = await napisPod();
  await page.waitForTimeout(400);
  const poPrzewinieciu = await napisPod();
  t.check('przewijanie: napis znika od razu', !wTrakcie.widoczny, J(wTrakcie));
  t.check('po zatrzymaniu przewijania napis wraca przy ramce (nie zostaje w tyle)',
    poPrzewinieciu.dla === 't1' && przyRamce(poPrzewinieciu), J(poPrzewinieciu));
  await K(() => window.scrollTo(0, 0));
  await page.waitForTimeout(300);
  /* Kanwa pomniejszona przekształceniem przodka: `fixed` liczy się wtedy od
     niego, nie od okna. Napis ma dalej przylegać do ramki. */
  await K(() => { document.body.style.transformOrigin = '0 0'; document.body.style.transform = 'scale(0.8)'; });
  await kanwa.hover('#brxe-t1');
  await page.waitForTimeout(100);
  await kanwa.hover('#brxe-h1');
  await page.waitForTimeout(100);
  await kanwa.hover('#brxe-t1');
  await page.waitForTimeout(100);
  const nSkala = await napisPod();
  await K(() => { document.body.style.transform = ''; document.body.style.transformOrigin = ''; });
  await page.waitForTimeout(100);
  /* Obrys też się skaluje: 4 px ramki to na ekranie 3,2 px, więc względem
     „element − 4 px" napis leży o 0,8 px bliżej. */
  const e = 4 - 4 * 0.8;
  const przyRamceSkala = (n) => n.widoczny && !n.wewnatrz && Math.abs(n.dx + e) <= 1 && Math.abs(n.dy - e) <= 1;
  t.check('kanwa pomniejszona (przekształcenie): napis dalej przy ramce', nSkala.dla === 't1' && przyRamceSkala(nSkala), J(nSkala));
  /* Element przy górnej krawędzi kanwy: nad ramką nie ma miejsca, napis idzie
     do ramki, u góry po prawej. */
  await K(() => window.scrollTo(0, document.getElementById('brxe-t1').getBoundingClientRect().top + window.scrollY - 6));
  await page.waitForTimeout(300);
  await kanwa.hover('#brxe-t1');
  await page.waitForTimeout(400);
  const nGora = await napisPod();
  await K(() => window.scrollTo(0, 0));
  await page.waitForTimeout(300);
  t.check('element przy górnej krawędzi kanwy: napis w ramce, u góry po prawej',
    nGora.dla === 't1' && nGora.wewnatrz && Math.abs(nGora.dxW) <= 1 && Math.abs(nGora.dyW) <= 1, J(nGora));
  t.check('{tl_…}: EN ze słownika, bez obrysu', J(await tekst('#brxe-d1')) === J(['See more']) && !(await obrys('d1')), J(await tekst('#brxe-d1')));
  t.check('dane dynamiczne: bez zmian i bez obrysu', J(await tekst('#brxe-p1')) === J(['Mój wpis']) && !(await obrys('p1')), J(await tekst('#brxe-p1')));
  const img = await K(() => ['#brxe-i1 img', '#brxe-sl1 img'].map((s) => { const i = document.querySelector(s); return [i.getAttribute('src'), i.getAttribute('srcset')]; }));
  t.check('obraz: plik EN bez srcset, także w pozycji listy; bez obrysu',
    J(img) === J([['podglad-en.svg', null], ['slajd-en.svg', null]]) && !(await obrys('i1')) && !(await obrys('sl1')), J(img));
  await page.waitForTimeout(300);
  const svg = await K(() => {
    const s = document.getElementById('brxe-sv1');
    return { rect: !!s.querySelector('rect#znak-en'), skrypt: !!s.querySelector('script'), obcy: !!s.querySelector('foreignObject'),
      zdarzenie: !!s.querySelector('[onload]'), viewBox: s.getAttribute('viewBox'), zlo: [parent.__zloSkrypt, window.__zloAtrybut], obrys: s.classList.contains('evk-tl-brak') };
  });
  t.check('SVG: plik EN wstawiony i oczyszczony (bez skryptu, obcych obiektów, zdarzeń)',
    svg.rect && !svg.skrypt && !svg.obcy && !svg.zdarzenie && svg.viewBox === '0 0 20 20' && J(svg.zlo) === '[null,null]' && !svg.obrys, J(svg));

  t.section('edycja w kanwie w trybie EN: na czas edycji polski');
  await kanwa.click('#brxe-h1', { position: { x: 30, y: 10 } });
  await page.waitForTimeout(150);
  t.check('kliknięty nagłówek pokazuje polski i niebieską ramkę edycji',
    await html('#brxe-h1') === 'Grafika<br>użytkowa' && await edycjaRamka('h1'), J([await html('#brxe-h1'), await edycjaRamka('h1')]));
  await page.keyboard.type('X');
  await page.waitForTimeout(150);
  const stanH1 = await page.evaluate(() => window.__stan.content.find((e) => e.id === 'h1').settings);
  t.check('pisanie zmienia polski stan, a tłumaczenie nie trafia do polskiego',
    /X/.test(stanH1.text) && stanH1.text.replace('X', '') === 'Grafika<br>użytkowa' && stanH1.evk_tl_en__text === 'Graphic<br>design', J(stanH1));
  await page.click('#zapisz');
  await page.waitForTimeout(400);
  t.check('po wyjściu z tekstu znów EN, bez ramki edycji',
    await html('#brxe-h1') === 'Graphic<br>design' && !(await edycjaRamka('h1')), J([await html('#brxe-h1'), await edycjaRamka('h1')]));

  /* Zabezpieczenie drugiej warstwy: znak w elemencie, który wciąż pokazuje
     tłumaczenie (inna droga Bricksa do edycji niż kliknięcie i fokus). */
  const zabezp = await K(() => {
    const h = document.getElementById('brxe-h2');
    const ev = new InputEvent('beforeinput', { bubbles: true, cancelable: true, inputType: 'insertText', data: 'Y' });
    h.dispatchEvent(ev);
    return { zablokowany: ev.defaultPrevented, html: h.innerHTML };
  });
  await page.waitForTimeout(100);
  t.check('znak bez kliknięcia i fokusu: zablokowany, a element najpierw wraca do polskiego',
    zabezp.zablokowany && zabezp.html === 'Oferta' && await edycjaRamka('h2'), J([zabezp, await edycjaRamka('h2')]));
  await kanwa.click('body', { position: { x: 5, y: 5 } });
  await page.waitForTimeout(300);

  t.section('na żywo');
  await page.evaluate(() => { window.__stan.activeId = 'b1'; window.__stan.content.find((e) => e.id === 'b1').settings.evk_tl_en__text = 'Contact us'; });
  await page.waitForTimeout(800);
  t.check('pisanie w polu „Tłumaczenie EN" panelu: kanwa nadąża', J(await tekst('#brxe-b1 .tekst')) === J(['Contact us']), J(await tekst('#brxe-b1 .tekst')));
  await K(() => window.__przerysuj('h2'));
  await page.waitForTimeout(300);
  t.check('przerysowanie elementu przez Vue: tłumaczenie wraca', J(await tekst('#brxe-h2')) === J(['Offer']), J(await tekst('#brxe-h2')));
  const p0 = await K(() => window.__evkTlPodglad.przebiegi);
  await page.waitForTimeout(1500);
  const p1 = await K(() => window.__evkTlPodglad.przebiegi);
  t.check('w spoczynku podgląd sam siebie nie budzi', p1 - p0 <= 1, (p1 - p0) + ' przebiegów w 1,5 s');

  t.section('DE');
  await page.click('#evk-tl-podglad button[data-jezyk="de"]');
  await page.waitForTimeout(400);
  const plTeraz = await page.evaluate(() => window.__stan.content.find((e) => e.id === 'h1').settings.text);
  await kanwa.hover('#brxe-h1');
  await page.waitForTimeout(100);
  const nDe = await napisPod();
  t.check('DE: przycisk z pola DE; nagłówek bez DE — polski z obrysem i napisem „brak DE"',
    J(await tekst('#brxe-b1 .tekst')) === J(['Kontakt DE']) && await html('#brxe-h1') === plTeraz && await obrys('h1') && nDe.tekst === 'brak DE' && nDe.dla === 'h1',
    J([await tekst('#brxe-b1 .tekst'), await html('#brxe-h1'), nDe]));

  t.section('powrót do PL');
  await page.click('#evk-tl-podglad button[data-jezyk="pl"]');
  await page.waitForTimeout(400);
  const po = await K(() => ({
    h1: document.getElementById('brxe-h1').innerHTML, b1: document.querySelector('#brxe-b1 .tekst').textContent,
    img: [document.querySelector('#brxe-i1 img').getAttribute('src'), document.querySelector('#brxe-i1 img').getAttribute('srcset')],
    svg: !!document.querySelector('#brxe-sv1 circle'), d1: document.getElementById('brxe-d1').textContent,
    obrysy: document.querySelectorAll('.evk-tl-brak, .evk-tl-edycja').length,
    napis: !!document.querySelector('#evk-tl-nakladka .evk-tl-znacznik:not([hidden])') }));
  const plH1 = await page.evaluate(() => window.__stan.content.find((e) => e.id === 'h1').settings.text);
  t.check('PL: kanwa jak przed podglądem (tekst, obraz z srcset, SVG, {tl_…}), bez obrysów',
    po.h1 === plH1 && po.b1 === 'Kontakt' && J(po.img) === J(['podglad-pl-300x200.svg', 'podglad-pl-300x200.svg 300w']) && po.svg
      && po.d1 === 'Zobacz więcej' && po.obrysy === 0 && !po.napis, J(po));

  t.section('przeładowania');
  await page.click('#evk-tl-podglad button[data-jezyk="en"]');
  await page.waitForTimeout(300);
  await K(() => location.reload());
  await page.waitForTimeout(1200);
  kanwa = page.frames().find((f) => /builder-podglad-kanwa\.html/.test(f.url()));
  t.check('przeładowanie samej kanwy: wybór zostaje (ta sama sesja buildera)',
    J(await wcisniety()) === J(['EN']) && J(await tekst('#brxe-b1 .tekst')) === J(['Contact us'])
    && await page.evaluate(() => document.querySelectorAll('#evk-tl-podglad').length) === 1, J([await wcisniety(), await tekst('#brxe-b1 .tekst')]));
  await page.reload();
  await page.waitForTimeout(1200);
  kanwa = page.frames().find((f) => /builder-podglad-kanwa\.html/.test(f.url()));
  t.check('przeładowanie buildera: start z PL', J(await wcisniety()) === J(['PL']) && await html('#brxe-h1') === 'Grafika<br>użytkowa',
    J([await wcisniety(), await html('#brxe-h1')]));

  t.check('bez błędów w konsoli', page.errors.length === 0, J(page.errors.slice(0, 3).concat(zleZadania.slice(0, 3))));
};
