/**
 * Stacking Cards — geometria stosu.
 *
 * Broni trzech usterek, które kosztowały po kilka podejść:
 *  · sticky zwalnia karty w kolejności ODWROTNEJ do `top`, więc schodek przez
 *    `top` sprawiał, że ostatnia karta ruszała pierwsza i wchodziła na poprzednie
 *    (1.27.3). Schodek robi teraz transform, a zwolnienie ma być równoczesne.
 *  · padding-bottom nie przedłuża fazy sticky ani o piksel — zapas musi być
 *    realnym elementem (1.27.3).
 *  · własny nasłuch resize wołający ScrollTrigger.refresh() zacinał scroll na
 *    telefonie (1.28.0). Przy zmianie samej wysokości nie wolno wymuszać refreshu.
 */

const VH = 800;
const STAGGER = 40;

module.exports = async function (t) {
  // ── Zwolnienie i schodki ──────────────────────────────────────────────
  t.section('schodek 40px, zapas automatyczny');
  let page = await t.open('stacking-cards.html', { viewport: { width: 1000, height: VH }, query: 'stagger=40' });

  const docH = await page.evaluate(() => document.documentElement.scrollHeight);
  const rows = [];
  for (let y = 0; y <= docH - VH; y += 4) {
    await page.evaluate((v) => window.scrollTo(0, v), y);
    rows.push([y, await page.evaluate(() => window.__m())]);
  }

  const release = [];
  for (let i = 0; i < 3; i++) {
    const stuck = 80 + i * STAGGER;
    const held = rows.filter(([, m]) => m.tops[i] === stuck);
    release.push(held.length ? held[held.length - 1][0] : null);
    t.check('karta ' + (i + 1) + ' stoi na ' + stuck + 'px', held.length > 0,
      held.length ? held[0][0] + '–' + held[held.length - 1][0] : 'NIGDY');
  }
  t.check('zwolnienie równoczesne', new Set(release).size === 1, release.join(' / '));

  const exiting = rows.filter(([y, m]) => y > release[2] && m.tops[2] > -300);
  const broken = exiting.filter(([, m]) => m.tops[1] - m.tops[0] !== STAGGER || m.tops[2] - m.tops[1] !== STAGGER);
  t.check('schodki przetrwały wyjście stosu', !broken.length,
    (exiting.length - broken.length) + '/' + exiting.length + ' klatek');

  const overlap = rows.filter(([, m]) => m.afterTop < VH && m.afterTop > -VH)
                      .map(([, m]) => m.lastBottom - m.afterTop).filter((o) => o > 0);
  t.check('stos nie nachodzi na następną sekcję', !overlap.length,
    overlap.length ? 'max ' + Math.max(...overlap) + 'px' : 'brak');

  const first = rows[0][1];
  t.check('zapas automatyczny = pół karty', first.spacer === 400, first.spacer + 'px przy karcie 800px');
  t.check('padding-bottom = (n-1) × schodek', first.pad === '80px', first.pad);
  await page.close();

  // ── Zapas ręczny ──────────────────────────────────────────────────────
  t.section('zapas ręczny');
  page = await t.open('stacking-cards.html', { viewport: { width: 1000, height: VH }, query: 'stagger=40&space=50vh' });
  t.check('rozpórka z jednostki CSS', (await page.evaluate(() => window.__m())).spacer === 400, '50vh z 800px = 400px');
  await page.close();

  // ── Brak efektów: stos ma działać bez żadnego tweena ───────────────────
  t.section('bez zmniejszania i przyciemniania');
  page = await t.open('stacking-cards.html', { viewport: { width: 1000, height: VH }, query: 'stagger=40&shrink=0&dim=0' });
  const bare = await page.evaluate(() => window.__m());
  t.check('stos działa mimo zera tweenów', bare.active && bare.spacer === 400, 'rozpórka ' + bare.spacer + 'px');
  await page.close();

  // ── Poniżej breakpointu ───────────────────────────────────────────────
  t.section('poniżej breakpointu');
  page = await t.open('stacking-cards.html', { viewport: { width: 600, height: VH }, query: 'stagger=40' });
  const small = await page.evaluate(() => window.__m());
  t.check('zwykły przepływ', !small.active && small.spacer === null && small.pad === '0px',
    'is-active=' + small.active + ', rozpórka=' + small.spacer);
  await page.close();

  // ── Regresja 1.28.0: zmiana samej wysokości nie wymusza refreshu ───────
  t.section('zmiana wysokości okna (pasek adresu telefonu)');
  page = await t.open('stacking-cards.html', { viewport: { width: 1000, height: VH }, query: 'stagger=40' });
  const before = (await page.evaluate(() => window.__m())).refresh;
  await page.setViewportSize({ width: 1000, height: 640 });
  await page.waitForTimeout(500);
  await page.setViewportSize({ width: 1000, height: VH });
  await page.waitForTimeout(500);
  const after = await page.evaluate(() => window.__m());
  t.check('brak wymuszonego refreshu', after.refresh === before, before + ' → ' + after.refresh);
  t.check('rozpórka bez zmian', after.spacer === 400, after.spacer + 'px');

  // ── Zmiana szerokości MA przeliczyć rozpórkę ───────────────────────────
  await page.setViewportSize({ width: 900, height: 500 });
  await page.waitForTimeout(700);
  t.check('zmiana szerokości przelicza rozpórkę',
    (await page.evaluate(() => window.__m())).spacer === 250, 'pół karty 500px = 250px');
  t.check('bez błędów JS', !page.errors.length, page.errors.join(' | ') || 'brak');
  await page.close();

  // ── Sticky pod przodkiem z overflow ─────────────────────────────────────
  /* ZGŁOSZONE Z UŻYCIA: „Stacking cards nie działa wewnątrz kontenera.
     W sekcji tak, ale jeśli jest dalej w kolejnym bloku przestaje." Karty
     w ogóle się nie przyklejały, a na kontenerze stał `overflow: hidden`
     albo `overflow-x: hidden`.

     `position: sticky` PRZESTAJE ISTNIEĆ pod przodkiem będącym kontenerem
     przewijania — nie ma stanu pośredniego. I nie widać tego w devtoolsach:
     karta ma dalej wyliczone `position: sticky` i mimo to odjeżdża z ekranu.
     Dlatego mierzymy POŁOŻENIE pierwszej karty po przewinięciu, a nie
     `position`.

     Zmierzone (okno 1200×800, przewinięcie o 1200 px, `top` pierwszej karty;
     przyklejona stoi na 80 px, czyli na zadanym offsecie):

         owijka            │ overflow x/y  │ przed │ po naprawie
         brak              │ —             │    80 │  80
         visible           │ visible/…     │    80 │  80
         hidden            │ hidden/hidden │  -520 │  80   (→ clip/clip)
         overflow-x:hidden │ hidden/AUTO   │  -520 │  80   (→ clip/visible)
         auto              │ auto/auto     │  -520 │ -520  (celowo nietknięte)

     WIERSZ TRZECI OD DOŁU JEST SEDNEM ZGŁOSZENIA. Ustawienie samego
     `overflow-x: hidden` WYLICZA `overflow-y: auto` — taka jest specyfikacja.
     Zabieg „żeby nie było poziomego scrolla" tworzy więc pionowy kontener
     przewijania, choć nikt nie tknął osi pionowej.

     OSTATNI WIERSZ NIE JEST USTERKĄ. Przodka, który przewija własną treść,
     nie ruszamy — autor chce tam przewijania, a zamiana odebrałaby mu je.
     Element mówi o tym w konsoli i na tym poprzestaje. */
  t.section('karty przyklejają się także w kontenerze z overflow');

  const PRZEWIN = 1200;
  const PRZYKLEJONA = 80;     // = offsetTop z konfiguracji

  const wOwijce = async (query, viewport) => {
    const p = await t.open('stacking-cards.html',
      { viewport: viewport || { width: 1200, height: 800 }, settle: 700, query });
    await p.evaluate((y) => window.scrollTo(0, y), PRZEWIN);
    await p.waitForTimeout(300);
    const m = await p.evaluate(() => window.__m());
    return { p, ...m };
  };

  /* ODNIESIENIE: bez owijki i pod owijką `visible` karta stoi na offsecie.
     Bez tego „przykleja się w kontenerze" przechodziłoby także dla pomiaru,
     który mierzy coś innego niż przyklejanie. */
  const bezOwijki = await wOwijce('');
  t.check('bez owijki karta stoi na offsecie', bezOwijki.tops[0] === PRZYKLEJONA,
    'top = ' + bezOwijki.tops[0]);
  await bezOwijki.p.close();

  const przezroczysta = await wOwijce('owijka=visible');
  t.check('owijka bez overflow niczego nie psuje', przezroczysta.tops[0] === PRZYKLEJONA,
    'top = ' + przezroczysta.tops[0]);
  t.check('i nie ma o czym mówić', przezroczysta.ostrzezenia.length === 0,
    przezroczysta.ostrzezenia.join(' | ') || 'cisza');
  await przezroczysta.p.close();

  const schowana = await wOwijce('owijka=hidden');
  t.check('owijka overflow:hidden — karta dalej się przykleja',
    schowana.tops[0] === PRZYKLEJONA, 'top = ' + schowana.tops[0]);
  /* `clip`, NIE `hidden` — to jest cała naprawa. `clip` przycina identycznie,
     ale nie tworzy kontenera przewijania. */
  t.check('bo overflow zamieniony na clip na obu osiach',
    schowana.owijka.x === 'clip' && schowana.owijka.y === 'clip',
    schowana.owijka.x + '/' + schowana.owijka.y);
  t.check('i element mówi, co zrobił i komu',
    schowana.ostrzezenia.length === 1
      && /div#owijka/.test(schowana.ostrzezenia[0])
      && /clip/.test(schowana.ostrzezenia[0]),
    schowana.ostrzezenia.join(' | ') || 'cisza');
  await schowana.p.close();

  const poziomo = await wOwijce('owijka=x-hidden');
  t.check('owijka overflow-x:hidden — karta dalej się przykleja',
    poziomo.tops[0] === PRZYKLEJONA, 'top = ' + poziomo.tops[0]);
  /* Oś pionowa wraca do `visible`, bo `clip` w parze z `visible` jest
     dozwolony — a to `auto` wyliczone z `hidden` było zabójcą sticky. */
  t.check('a oś pionowa przestaje być kontenerem przewijania',
    poziomo.owijka.y === 'visible', 'overflow-y = ' + poziomo.owijka.y);
  await poziomo.p.close();

  const przewijana = await wOwijce('owijka=auto');
  t.check('owijki z overflow:auto NIE ruszamy',
    przewijana.owijka.x === 'auto' && przewijana.owijka.y === 'auto',
    przewijana.owijka.x + '/' + przewijana.owijka.y);
  t.check('ale mówimy, dlaczego karty się nie przyklejają',
    przewijana.ostrzezenia.length === 1
      && /przewija własną treść/.test(przewijana.ostrzezenia[0]),
    przewijana.ostrzezenia.join(' | ') || 'cisza');
  await przewijana.p.close();

  // ── Przywracanie przy wyłączeniu stosu ──────────────────────────────────
  /* OBOWIĄZKOWE, NIE KOSMETYCZNE: element zmienia CUDZE węzły, a teardown leci
     przy zejściu poniżej breakpointu i przy redukcji ruchu. Zostawiony `clip`
     na obcym kontenerze byłby zmianą, której nikt nie zamawiał. */
  t.section('poniżej breakpointu owijka dostaje swój overflow z powrotem');

  const wracamy = await t.open('stacking-cards.html',
    { viewport: { width: 1200, height: 800 }, settle: 700, query: 'owijka=hidden' });
  const zanim = await wracamy.evaluate(() => window.__m());
  t.check('nad breakpointem naprawione na clip', zanim.owijka.x === 'clip',
    zanim.owijka.x + '/' + zanim.owijka.y);

  await wracamy.setViewportSize({ width: 500, height: 800 });
  await wracamy.waitForTimeout(700);
  const potem = await wracamy.evaluate(() => window.__m());
  t.check('stos się wyłączył', potem.active === false, 'is-active: ' + potem.active);
  t.check('a owijka odzyskała overflow: hidden',
    potem.owijka.x === 'hidden' && potem.owijka.y === 'hidden',
    potem.owijka.x + '/' + potem.owijka.y);
  t.check('bez błędów JS', !wracamy.errors.length, wracamy.errors.join(' | ') || 'brak');
  await wracamy.close();
};
