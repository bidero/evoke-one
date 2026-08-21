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

const { rgb, near } = require('./lib/harness');

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
  const w1 = await seg.evaluate(() => window.__w());
  t.check('kresek tyle, ile paneli', w1.kresek === 4, w1.kresek + ' kresek');
  t.check('i jedna jest aktywna', w1.aktywny === 0, 'indeks ' + w1.aktywny);

  /* Aktywna ma WĘDROWAĆ. Sam fakt, że któraś jest podświetlona, spełniłby też
     wskaźnik zamrożony na pierwszej. */
  await przewin(seg, 800, 400);
  const w2 = await seg.evaluate(() => window.__w());
  t.check('a przy końcu aktywna jest ostatnia', w2.aktywny === 3, 'indeks ' + w2.aktywny);
  await seg.close();

  /* KONTROLA NEGATYWNA: przy stylu „jedna kreska" segmentów ma nie być wcale,
     a kreska ma się skalować. */
  const pas = await doSekcji('prog=1&style=bar', 400);
  const w3 = await pas.evaluate(() => window.__w());
  t.check('przy jednej kresce segmentów nie ma', w3.kresek === 0, w3.kresek + ' kresek');
  t.check('a kreska jest rozciągnięta', /matrix\(0\.[3-9]/.test(w3.pasek), w3.pasek);
  await pas.close();

  // ── Wskaźnik POZA elementem ──────────────────────────────────────────────
  /* Sedno 1.101.0. Kontener wskazuje się selektorem i leży poza elementem —
     szuka go `document.querySelector`, a nie `closest` jak przodka do pinu. */
  t.section('wskaźnik ląduje w zewnętrznym kontenerze');

  const zew = await doSekcji('prog=1&style=segments&target=%23zewn-pusty', 100);
  const zw  = await zew.evaluate(() => window.__w('#zewn-pusty'));
  /* Obie rzeczy w JEDNYM sprawdzeniu, i to nie z lenistwa: samo „div nie jest
     w elemencie" jest prawdą także wtedy, gdy skrypt w ogóle go nie tknął. */
  t.check('kreski są w zewnętrznym divie', zw.kresek === 4 && zw.wSrodku === false,
    zw.kresek + ' kresek, w elemencie: ' + zw.wSrodku);
  /* Kontrola pozytywna do powyższego: kresek nie ma jednocześnie w dwóch
     miejscach. Bez tego „są na zewnątrz" spełniałby też skrypt rysujący
     wszędzie. */
  const zwWew = await zew.evaluate(() => window.__w());
  t.check('i nie ma ich już w środku elementu', zwWew.kresek === 0, zwWew.kresek + ' kresek');
  t.check('i bieżąca jest podświetlona', zw.aktywny === 0, 'indeks ' + zw.aktywny);

  /* Kontener zostaje W PRZEPŁYWIE. `position: absolute` na klasie bazowej
     wyrwałoby cudzy blok Bricksa z układu i przykleiło do krawędzi najbliższego
     przodka z pozycjonowaniem — a `.ramka` w fixture takim przodkiem jest. */
  t.check('kontener nie jest pozycjonowany', zw.pozycja === 'static', zw.pozycja);
  const ukladZ = await zew.evaluate(() => window.__uklad());
  await zew.close();

  /* KONTROLA NEGATYWNA dla obu: bez selektora kreski wracają do środka,
     a zewnętrzny div zostaje pusty i zajmuje dokładnie tyle samo miejsca. */
  const bezCelu = await doSekcji('prog=1&style=segments', 100);
  const bc = await bezCelu.evaluate(() => window.__w());
  const bcZ = await bezCelu.evaluate(() => window.__w('#zewn-pusty'));
  t.check('bez selektora kreski są w środku', bc.kresek === 4 && bcZ.kresek === 0,
    bc.kresek + ' w środku, ' + bcZ.kresek + ' na zewnątrz');
  const ukladB = await bezCelu.evaluate(() => window.__uklad());
  t.check('i wysokość ramki jest ta sama co ze wskaźnikiem',
    ukladZ.odstep === ukladB.odstep, ukladZ.odstep + ' wobec ' + ukladB.odstep);
  await bezCelu.close();

  // ── Kontener z własną treścią ────────────────────────────────────────────
  /* Druga droga: w kontenerze stoi gotowa treść („01 · ROZMOWA"), a skrypt ma
     tylko wpinać `is-active`. */
  t.section('kontener z własną treścią zostaje nietknięty');

  const wlasny = await doSekcji('prog=1&style=segments&target=%23zewn-wlasny', 100);
  const v1 = await wlasny.evaluate(() => window.__w('#zewn-wlasny'));
  t.check('dzieci zostały te z HTML-a', v1.dzieci === 4 && v1.teksty[0] === '01 · ROZMOWA',
    v1.dzieci + ' × „' + v1.teksty[0] + '"');
  t.check('skrypt nie dorysował własnych kresek', v1.kresek === 0 && v1.numerow === 0,
    v1.kresek + ' kresek, ' + v1.numerow + ' numerów');
  t.check('a bieżące dziecko jest podświetlone', v1.aktywny === 0, 'indeks ' + v1.aktywny);

  await przewin(wlasny, 800, 400);
  const v2 = await wlasny.evaluate(() => window.__w('#zewn-wlasny'));
  t.check('podświetlenie wędruje po cudzej treści', v2.aktywny === 3, 'indeks ' + v2.aktywny);
  t.check('bez błędów JS', !wlasny.errors.length, wlasny.errors.join(' | ') || 'brak');
  await wlasny.close();

  /* Za mało dzieci = ostatnie stany nie mają czego podświetlić. Z ekranu wygląda
     to jak zacinający się wskaźnik, więc skrypt MA o tym powiedzieć. */
  const krotki = await doSekcji('prog=1&style=segments&target=%23zewn-krotki', 100);
  t.check('niezgodna liczba dzieci mówi o sobie',
    krotki.warnings.some((w) => /2 dzieci przy 4 panelach/.test(w)),
    krotki.warnings.find((w) => /dzieci/.test(w)) || 'brak ostrzeżenia');
  const kr = await krotki.evaluate(() => window.__w('#zewn-krotki'));
  t.check('a wskaźnik mimo to działa', kr.dzieci === 2 && kr.aktywny === 0,
    kr.dzieci + ' dzieci, indeks ' + kr.aktywny);
  t.check('bez błędów JS', !krotki.errors.length, krotki.errors.join(' | ') || 'brak');
  await krotki.close();

  // ── Tryb „tylko bieżący" ─────────────────────────────────────────────────
  /* Trzeci styl: z całego rzędu widać jedno. W pustym kontenerze skrypt pisze
     NUMERY kart — goła kreska nie niosłaby tam żadnej informacji. */
  t.section('tryb „tylko bieżący" pokazuje dokładnie jedno');

  const cur = await doSekcji('prog=1&style=current&target=%23zewn-pusty', 100);
  const c1 = await cur.evaluate(() => window.__w('#zewn-pusty'));
  t.check('dzieci jest tyle, ile kart', c1.numerow === 4, c1.numerow + ' numerów');
  t.check('ale widać dokładnie jedno', c1.widocznych === 1, c1.widocznych + ' widocznych');
  t.check('i jest to numer pierwszej karty', c1.tekstWidoczny === '1', '„' + c1.tekstWidoczny + '"');

  await przewin(cur, 800, 400);
  const c2 = await cur.evaluate(() => window.__w('#zewn-pusty'));
  t.check('widoczne przeskakuje wraz z przewijaniem', c2.tekstWidoczny === '4',
    '„' + c2.tekstWidoczny + '"');
  t.check('i nadal jest jedno', c2.widocznych === 1, c2.widocznych + ' widocznych');
  await cur.close();

  /* KONTROLA NEGATYWNA: przy „segmentach" widać WSZYSTKIE. Bez niej „widać
     jedno" spełniałby też arkusz chowający cały wskaźnik. */
  const wszystkie = await doSekcji('prog=1&style=segments&target=%23zewn-pusty', 100);
  const ws = await wszystkie.evaluate(() => window.__w('#zewn-pusty'));
  t.check('przy segmentach widać wszystkie', ws.widocznych === 4, ws.widocznych + ' widocznych');
  await wszystkie.close();

  // ── Długość kresek ───────────────────────────────────────────────────────
  /* Puste pole znaczy „rozciągnij" — kreski dzielą szerokość po równo, tak jak
     do 1.100.0. Podana wartość zamraża długość. */
  t.section('długość kresek: pusta rozciąga, podana zamraża');

  const roz = await doSekcji('prog=1&style=segments&target=%23zewn-pusty', 100);
  const r1 = await roz.evaluate(() => window.__w('#zewn-pusty'));
  const suma = r1.szeroko.reduce((a, b) => a + b, 0);
  // Trzy odstępy po 8 px między czterema kreskami — reszta to same kreski.
  t.check('bez podanej długości kreski wypełniają kontener',
    Math.abs(suma + 24 - r1.szerBox) <= 2, suma + ' + 24 wobec ' + r1.szerBox);
  await roz.close();

  const stala = await doSekcji('prog=1&style=segments&target=%23zewn-pusty&len=60px', 100);
  const s1 = await stala.evaluate(() => window.__w('#zewn-pusty'));
  /* Warunek na liczbę kresek nie jest ozdobą: `[].every()` jest PRAWDĄ, więc
     pusty kontener spełniałby „wszystkie mają 60 px" bez jednej kreski. */
  t.check('podana długość jest respektowana',
    s1.szeroko.length === 4 && s1.szeroko.every((w) => w === 60), JSON.stringify(s1.szeroko));
  await stala.close();

  const dluzsza = await doSekcji('prog=1&style=segments&target=%23zewn-pusty&len=60px&lena=120px', 100);
  const d1 = await dluzsza.evaluate(() => window.__w('#zewn-pusty'));
  t.check('aktywna może być dłuższa', d1.szeroko[0] === 120 && d1.szeroko[1] === 60,
    JSON.stringify(d1.szeroko));
  await dluzsza.close();

  /* KONTROLA NEGATYWNA: sama „aktywna dłuższa", bez ustalonej długości, ma nic
     nie robić — przy rozciąganiu to byłby współczynnik rozrostu, czyli inny
     model. Kontrolka mówi o tym `required`, a arkusz tego pilnuje. */
  const samaAkt = await doSekcji('prog=1&style=segments&target=%23zewn-pusty&lena=120px', 100);
  const sa = await samaAkt.evaluate(() => window.__w('#zewn-pusty'));
  t.check('sama „aktywna dłuższa" nic nie zmienia',
    sa.szeroko.length === 4 && sa.szeroko[0] === sa.szeroko[1], JSON.stringify(sa.szeroko));
  await samaAkt.close();

  // ── Zmienne CSS na zewnątrz ──────────────────────────────────────────────
  /* Kontrolki `css` z pustym selektorem Bricks zapisuje NA KORZENIU elementu.
     Kontener spoza elementu nie jest jego potomkiem i nie dziedziczy po nim
     niczego — skrypt musi te zmienne przepisać. */
  t.section('zmienne z kontrolek docierają do zewnętrznego kontenera');

  const kolor = await doSekcji('prog=1&style=segments&target=%23zewn-pusty&segon=%23ff0000', 100);
  const k1 = await kolor.evaluate(() => window.__w('#zewn-pusty'));
  /* Porównanie przez `rgb()`, nie napisami: wartość podstawiona ze zmiennej CSS
     wraca z przeglądarki jako „rgba(255, 0, 0, 1)", a ta sama barwa wpisana
     wprost — jako „rgb(255, 0, 0)". */
  t.check('kolor aktywnej kreski jest ten z kontrolki', near(rgb(k1.kolorAkt), [255, 0, 0], 0), k1.kolorAkt);
  await kolor.close();

  /* KONTROLA NEGATYWNA: bez ustawionej zmiennej kreska bierze wartość zapasową
     z arkusza. Bez tej pary sprawdzenie wyżej byłoby prawdziwe także wtedy,
     gdyby czerwień brała się skądinąd. */
  const bezKoloru = await doSekcji('prog=1&style=segments&target=%23zewn-pusty', 100);
  const bk = await bezKoloru.evaluate(() => window.__w('#zewn-pusty'));
  t.check('a bez niej — wartość zapasowa', !near(rgb(bk.kolorAkt), [255, 0, 0], 0), bk.kolorAkt);
  await bezKoloru.close();

  /* Styl „jedna kreska" też ma działać poza elementem, choć kreskę drukuje
     zwykle PHP — w cudzym kontenerze nie ma jej skąd wziąć, więc powstaje w JS. */
  const zewPas = await doSekcji('prog=1&style=bar&target=%23zewn-pusty', 400);
  const zp = await zewPas.evaluate(() => window.__w('#zewn-pusty'));
  t.check('przy jednej kresce skrypt tworzy ją na zewnątrz',
    zp.pasek !== null && /matrix\(0\.[3-9]/.test(zp.pasek), String(zp.pasek));
  await zewPas.close();

  // ── Nietrafiony selektor wskaźnika ───────────────────────────────────────
  /* Cisza byłaby tu najgorsza: wskaźnik zostaje w środku elementu, a z ekranu
     nie ma jak zgadnąć, że selektor w ogóle nie trafił. */
  t.section('nietrafiony selektor wskaźnika mówi o tym i wraca do środka');

  const zlyCel = await doSekcji('prog=1&style=segments&target=.nie-ma-takiego', 100);
  t.check('ostrzeżenie wskazuje selektor',
    zlyCel.warnings.some((w) => /selektora wskaźnika/.test(w) && /nie-ma-takiego/.test(w)),
    zlyCel.warnings.find((w) => /wskaźnika/.test(w)) || 'brak ostrzeżenia');
  const zc = await zlyCel.evaluate(() => window.__w());
  t.check('a kreski wracają do środka elementu', zc.kresek === 4, zc.kresek + ' kresek');
  t.check('bez błędów JS', !zlyCel.errors.length, zlyCel.errors.join(' | ') || 'brak');
  await zlyCel.close();

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
