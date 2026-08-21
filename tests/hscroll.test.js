/**
 * Horizontal Scroll — element Bricks.
 *
 * PIERWSZE testy tego elementu. Do 1.100.0 nie miał żadnych, a od tej wersji ma
 * dwa układy, które łatwo ze sobą pomylić:
 *
 *   „slajder" (od zawsze) — element przypina SAM SIEBIE, a panele dostają
 *       narzuconą szerokość (100% elementu albo 100vw) i wysokość.
 *   „sekcja"  (1.100.0)   — przypina się PRZODEK z nagłówkiem, a pod nagłówkiem
 *       jedzie taśma kart WĘŻSZYCH od ekranu, o rozmiarach z buildera.
 *
 * Różnica, którą widać na ekranie, jest jedna: w pierwszym układzie nagłówek
 * wyjeżdża razem z całą sekcją, w drugim stoi. Dlatego prawie każde sprawdzenie
 * niżej patrzy na DWIE rzeczy naraz — przesunięcie taśmy i położenie nagłówka —
 * odczytane w tej samej klatce układu.
 */

const V = { width: 1200, height: 800 };

module.exports = async function (t) {

  /**
   * Otwiera stronę i przewija tak, żeby sekcja stanęła przy górze ekranu.
   *
   * POZYCJĘ SEKCJI ODCZYTUJEMY RAZ, przed jakimkolwiek przewinięciem, i wozimy
   * ją dalej na `p.start`. `__gdzieSekcja()` liczy ją z prostokąta sekcji —
   * a PRZYPIĘTA sekcja ma ten prostokąt zawsze przy zerze, więc drugi odczyt
   * zwraca już bieżącą pozycję przewijania, nie miejsce sekcji w dokumencie.
   * Zmierzone: kolejne „przewiń o 600" lądowało wtedy 900 px dalej, taśma
   * dojeżdżała do końca i sprawdzenie „nagłówek stoi" świeciło na czerwono
   * mimo poprawnego kodu.
   */
  async function doSekcji(query, dy, nr) {
    const p = await t.open('hscroll.html', { viewport: V, settle: 600, query: query || '' });
    p.start = await p.evaluate((n) => window.__gdzieSekcja(n), nr || 1);
    await przewin(p, dy || 0);
    return p;
  }

  /** Przewinięcie o `dy` od początku sekcji — zawsze od zapamiętanego punktu. */
  async function przewin(p, dy, ms) {
    await p.evaluate((y) => window.__doPozycji(y), p.start + dy);
    await p.waitForTimeout(ms || 300);
  }

  // ── Przypinanie przodka ──────────────────────────────────────────────────
  /* Sedno całej wersji. Przypięty ma być PRZODEK, nie taśma — inaczej nagłówek
     wyjeżdża w górę razem z sekcją, zanim karty ruszą. */
  t.section('przypina się wskazany przodek, nie sama taśma');

  const sek = await doSekcji('', 0);

  /* ScrollTrigger owija przypięty element w rodzica z klasą `pin-spacer` —
     po tym poznaje się, co NAPRAWDĘ zostało przypięte. Sama konfiguracja nic
     by nie dowiodła: `pin` przyjmuje element, ale przypiąć może co innego,
     gdy selektor nie trafi. */
  const s0 = await sek.evaluate(() => window.__hs(1));
  t.check('przypięta jest SEKCJA', s0.przypiety === 'sekcja', s0.przypiety);
  t.check('a taśma ma co przesuwać', s0.zakres > 400, s0.zakres + ' px zakresu');
  t.check('bez błędów JS', !sek.errors.length, sek.errors.join(' | ') || 'brak');

  // ── Nagłówek stoi, taśma jedzie ──────────────────────────────────────────
  t.section('nagłówek stoi, jedzie tylko taśma');

  const klatki = [s0];
  for (const dy of [300, 600]) {
    await przewin(sek, dy, 250);
    klatki.push(await sek.evaluate(() => window.__hs(1)));
  }

  const tytuly = klatki.map((k) => k.tytulTop);
  const iksy   = klatki.map((k) => k.x);

  t.check('nagłówek ani drgnął', Math.max.apply(null, tytuly) - Math.min.apply(null, tytuly) <= 2,
    JSON.stringify(tytuly));
  /* Kontrola pozytywna: „nagłówek stoi" jest prawdą także wtedy, gdy nie dzieje
     się NIC. Taśma musi w tym samym czasie przejechać. */
  t.check('a taśma w tym czasie przejechała', iksy[0] === 0 && iksy[2] < -400,
    JSON.stringify(iksy));
  await sek.close();

  /* KONTROLA NEGATYWNA dla całej pary: przy przypinaniu samego elementu
     nagłówek MA wyjechać. Bez niej „nagłówek stoi" nie odróżnia nowego układu
     od strony, na której nic nie zostało przypięte. */
  const samSiebie = await doSekcji('pin=self', 400);
  const ss = await samSiebie.evaluate(() => window.__hs(1));
  t.check('przy „ten element" przypięty jest element', ss.przypiety === 'element', ss.przypiety);
  t.check('i nagłówek wyjeżdża poza kadr', ss.tytulTop < -100, ss.tytulTop + ' px');
  await samSiebie.close();

  // ── Koniec przewijania ───────────────────────────────────────────────────
  /* Ostatnia karta ma stanąć PRZY PRAWEJ KRAWĘDZI, a nie wyjechać poza nią.
     Mierzone odległością jej prawego brzegu od prawego brzegu kadru. */
  t.section('ostatnia karta staje przy prawej krawędzi');

  const kon = await doSekcji('', 0);
  const naStarcie = await kon.evaluate(() => window.__hs(1));
  t.check('na starcie ostatnia karta jest daleko poza kadrem', naStarcie.ogonek > 400,
    naStarcie.ogonek + ' px za krawędzią');

  await przewin(kon, 1000, 400);
  const naKoncu = await kon.evaluate(() => window.__hs(1));
  t.check('na końcu dokładnie przy krawędzi', Math.abs(naKoncu.ogonek) <= 2,
    naKoncu.ogonek + ' px');
  t.check('a przesunięcie równa się zakresowi', naKoncu.x === -naKoncu.zakres,
    naKoncu.x + ' wobec ' + (-naKoncu.zakres));
  await kon.close();

  // ── Tryb „z buildera" ────────────────────────────────────────────────────
  /* Skrypt nie ma ruszać ani szerokości, ani wysokości kart. To jest warunek
     całego układu: karty mają być węższe od ekranu i takie, jakie zrobiono
     w builderze. */
  t.section('tryb „z buildera" nie rusza rozmiarów kart');

  const au = await doSekcji('', 200);
  const a0 = await au.evaluate(() => window.__hs(1));
  t.check('skrypt nie wpisał szerokości ani wysokości',
    a0.stylW === '' && a0.stylH === '', 'width „' + a0.stylW + '", height „' + a0.stylH + '"');
  /* Zmierzone, nie deklarowane: regułę rozmiaru mógłby narzucić także arkusz,
     a wtedy pusty styl w atrybucie niczego by nie dowodził. */
  t.check('i karta ma rozmiar ze znacznika', a0.szer === 500 && a0.wys === 260,
    a0.szer + '×' + a0.wys + ' px');
  t.check('klasa trybu jest na korzeniu', a0.auto, 'evk-hscroll--auto');
  await au.close();

  /* KONTROLA NEGATYWNA: w trybie „wypełnij element" ta sama karta MA dostać
     szerokość korzenia. Bez tej pary „nie rusza rozmiarów" byłoby spełnione
     także wtedy, gdyby skrypt przestał działać w ogóle. */
  const fill = await doSekcji('width=fill', 200);
  const f0 = await fill.evaluate(() => window.__hs(1));
  t.check('a w trybie „wypełnij" karta dostaje szerokość korzenia',
    f0.stylW === '1200px' && f0.szer === 1200, f0.stylW + ' / ' + f0.szer + ' px');
  t.check('i klasy trybu „z buildera" nie ma', !f0.auto, String(f0.auto));
  await fill.close();

  // ── Selektor szuka PRZODKA ───────────────────────────────────────────────
  /* Różnica między `closest()` a `document.querySelector()` jest niewidoczna,
     dopóki pasująca sekcja jest jedna. Przy dwóch querySelector przypina obu
     taśmom tę SAMĄ, pierwszą sekcję — i druga skacze przy przewijaniu
     pierwszej. Strona ma dwie sekcje właśnie po to. */
  t.section('selektor trafia we własną sekcję, nie w pierwszą z brzegu');

  const dwie = await doSekcji('', 0, 2);
  const d2 = await dwie.evaluate(() => window.__hs(2));
  t.check('druga taśma przypięła DRUGĄ sekcję', d2.przypiety === 'sekcja', d2.przypiety);
  t.check('i to ona stoi przy górze ekranu', Math.abs(d2.sekcjaTop) <= 2, d2.sekcjaTop + ' px');
  t.check('a jej nagłówek jest widoczny', d2.tytulTop > 0 && d2.tytulTop < 200, d2.tytulTop + ' px');
  t.check('bez błędów JS', !dwie.errors.length, dwie.errors.join(' | ') || 'brak');
  await dwie.close();

  // ── Selektor, który do niczego nie pasuje ────────────────────────────────
  /* Cisza byłaby tu najgorsza: element działa dalej, tylko przypina nie to, co
     trzeba, a z ekranu nie ma tego jak zgadnąć. */
  t.section('nietrafiony selektor mówi o tym i nie psuje strony');

  const zly = await doSekcji('sel=.nie-ma-takiej', 400);
  t.check('ostrzeżenie wskazuje selektor',
    zly.warnings.some((w) => /Żaden przodek nie pasuje/.test(w) && /nie-ma-takiej/.test(w)),
    zly.warnings.find((w) => /przodek/.test(w)) || 'brak ostrzeżenia');
  const z0 = await zly.evaluate(() => window.__hs(1));
  t.check('a element przypina sam siebie', z0.przypiety === 'element', z0.przypiety);
  t.check('i taśma nadal jedzie', z0.x < -100, z0.x + ' px');
  t.check('bez błędów JS', !zly.errors.length, zly.errors.join(' | ') || 'brak');
  await zly.close();

  // ── Wskaźnik segmentowy ──────────────────────────────────────────────────
  t.section('wskaźnik segmentowy: tyle kresek, ile kart');

  const seg = await doSekcji('prog=1&style=segments', 100);
  const w1 = await seg.evaluate(() => window.__wskaznik(1));
  t.check('segmentów tyle, ile paneli', w1.segmentow === 4, w1.segmentow + ' segmentów');
  t.check('i jeden jest aktywny', w1.aktywny === 0, 'indeks ' + w1.aktywny);

  /* Aktywny ma WĘDROWAĆ. Sam fakt, że któryś jest podświetlony, spełniłby też
     wskaźnik zamrożony na pierwszym. */
  await przewin(seg, 800, 400);
  const w2 = await seg.evaluate(() => window.__wskaznik(1));
  t.check('a przy końcu aktywny jest ostatni', w2.aktywny === 3, 'indeks ' + w2.aktywny);
  await seg.close();

  /* KONTROLA NEGATYWNA: przy stylu „jedna kreska" segmentów ma nie być wcale,
     a kreska ma się skalować. */
  const pas = await doSekcji('prog=1&style=bar', 400);
  const w3 = await pas.evaluate(() => window.__wskaznik(1));
  t.check('przy jednej kresce segmentów nie ma', w3.segmentow === 0, w3.segmentow + ' segmentów');
  t.check('a kreska jest rozciągnięta', /matrix\(0\.[3-9]/.test(w3.pasek), w3.pasek);
  await pas.close();

  // ── Próg wyłączenia ──────────────────────────────────────────────────────
  /* Poniżej progu element ma się w ogóle nie uruchamiać: panele wracają do
     pionu i cała treść zostaje dostępna zwykłym przewijaniem strony. */
  t.section('poniżej progu nic nie jest przypięte');

  const maly = await doSekcji('below=1600', 400);
  const m0 = await maly.evaluate(() => window.__hs(1));
  t.check('nic nie zostało przypięte', m0.przypiety === 'nic', m0.przypiety);
  t.check('taśma stoi na zerze', m0.x === 0, m0.x + ' px');
  t.check('i klasy trybu poziomego nie ma', !m0.aktywny, String(m0.aktywny));
  await maly.close();
};
