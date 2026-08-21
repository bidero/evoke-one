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
  /* Zapasowy wskaźnik z PHP-a MA ZNIKNĄĆ, a nie tylko zostać pusty. PHP drukuje
     go zawsze, bo nie wie, czy selektor trafi; gdy trafił, zostaje absolutnie
     pozycjonowana wstęga z własnym tłem, leżąca NA górnej krawędzi kart.
     Zgłoszone z użycia: „nad boksami mam cały czas linię poprzedniego paska". */
  const zwWew = await zew.evaluate(() => window.__hs(1));
  t.check('a zapasowy wskaźnik w środku znika', zwWew.maWewnetrzny === false, String(zwWew.maWewnetrzny));
  /* Razem z węzłem schodzi modyfikator odsuwający taśmę — inaczej karty
     trzymałyby odstęp od wskaźnika, którego już tam nie ma. */
  t.check('razem z modyfikatorem odstępu', zwWew.modPozycji === false, String(zwWew.modPozycji));
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
  const bcH = await bezCelu.evaluate(() => window.__hs(1));
  t.check('i wskaźnik wewnętrzny stoi na miejscu', bcH.maWewnetrzny === true, String(bcH.maWewnetrzny));
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

  // ── Selektor wskazujący wskaźnik wewnętrzny ──────────────────────────────
  /* Wolno wskazać selektorem TEN SAM węzeł, który drukuje PHP. Usunięcie go
     zostawiłoby wskaźnik jadący w elemencie oderwanym od dokumentu — widoczne
     jako wskaźnik, który po prostu zniknął. */
  t.section('selektor celujący we własny wskaźnik go nie odczepia');

  const sam = await doSekcji('prog=1&style=segments&target=.evk-hscroll__progress', 100);
  const samH = await sam.evaluate(() => window.__hs(1));
  const samW = await sam.evaluate(() => window.__w());
  t.check('wskaźnik zostaje w dokumencie', samH.maWewnetrzny === true, String(samH.maWewnetrzny));
  t.check('i normalnie się rysuje', samW && samW.kresek === 4, samW ? samW.kresek + ' kresek' : 'brak');
  t.check('bez błędów JS', !sam.errors.length, sam.errors.join(' | ') || 'brak');
  await sam.close();

  // ── Wygląd kresek ────────────────────────────────────────────────────────
  /* Grubość miała dotąd zmienną BEZ kontrolki — dawało się ją ustawić tylko
     własnym CSS-em. Mierzona na kresce, nie odczytana z atrybutu. */
  t.section('grubość i zaokrąglenie kreski z kontrolek');

  const ksz = await doSekcji('prog=1&style=segments&segh=12px&segr=6px', 100);
  const kszW = await ksz.evaluate(() => window.__w());
  t.check('kreska ma zadaną grubość',
    kszW.wysoko.length === 4 && kszW.wysoko.every((h) => h === 12), JSON.stringify(kszW.wysoko));
  t.check('i zadane zaokrąglenie', kszW.zaokrSeg === '6px', kszW.zaokrSeg);
  await ksz.close();

  /* KONTROLA NEGATYWNA: bez kontrolek wartości zapasowe z arkusza. */
  const kszB = await doSekcji('prog=1&style=segments', 100);
  const kb = await kszB.evaluate(() => window.__w());
  t.check('bez kontrolek grubość i zaokrąglenie zapasowe',
    kb.wysoko[0] === 3 && kb.zaokrSeg === '0px', kb.wysoko[0] + ' px / ' + kb.zaokrSeg);
  await kszB.close();

  // ── Wygląd pudełka ───────────────────────────────────────────────────────
  t.section('tło, odstęp i zaokrąglenie pudełka wskaźnika');

  const pud = await doSekcji('prog=1&style=segments&target=%23zewn-pusty'
    + '&progbg=%23ff0000&progpad=10px&progr=8px', 100);
  const pw = await pud.evaluate(() => window.__w('#zewn-pusty'));
  t.check('tło jest to z kontrolki', near(rgb(pw.tlo), [255, 0, 0], 0), pw.tlo);
  t.check('odstęp wewnętrzny też', pw.padding === '10px', pw.padding);
  t.check('i zaokrąglenie', pw.zaokrBox === '8px', pw.zaokrBox);
  await pud.close();

  /* KONTROLA NEGATYWNA: przy kreskach bez kontrolki tło zostaje przezroczyste —
     inaczej byłoby kreską pod kreskami. */
  const pudB = await doSekcji('prog=1&style=segments&target=%23zewn-pusty', 100);
  const pb = await pudB.evaluate(() => window.__w('#zewn-pusty'));
  /* Porównanie napisem, nie przez `rgb()`: ten helper ucina kanał alfa do
     trzech liczb, a tutaj cała różnica siedzi właśnie w alfie. */
  t.check('bez kontrolek tło przezroczyste, reszta zerowa',
    pb.tlo === 'rgba(0, 0, 0, 0)' && pb.padding === '0px' && pb.zaokrBox === '0px',
    pb.tlo + ' / ' + pb.padding + ' / ' + pb.zaokrBox);
  await pudB.close();

  // ── Pismo numeru ─────────────────────────────────────────────────────────
  /* Numer dziedziczy pismo po bloku, w którym stoi — a wskaźnik wolno postawić
     gdziekolwiek, więc „gdziekolwiek" bywa akapitem 16 px. */
  t.section('numer ma własne pismo, nie odziedziczone');

  const pis = await doSekcji('prog=1&style=current&target=%23zewn-pusty'
    + '&numsize=32px&numweight=800', 100);
  const pisW = await pis.evaluate(() => window.__w('#zewn-pusty'));
  t.check('rozmiar i grubość pisma z kontrolek', pisW.pismo === '32px / 800', pisW.pismo);
  await pis.close();

  const pisB = await doSekcji('prog=1&style=current&target=%23zewn-pusty', 100);
  const pbW = await pisB.evaluate(() => window.__w('#zewn-pusty'));
  t.check('a bez nich — pismo z bloku', pbW.pismo === '16px / 400', pbW.pismo);
  await pisB.close();

  // ── „Przygaś" zamiast „schowaj" ──────────────────────────────────────────
  /* Do 1.101.0 numer brał kolor bieżącego BEZWARUNKOWO — uchodziło to na sucho,
     bo reszty i tak nie było widać. Przy przygaszaniu wszystkie byłyby
     podświetlone, więc kolor musi się rozdzielić na dwa stany. */
  t.section('tryb „przygaś" pokazuje wszystkie i różnicuje kolorem');

  const dim = await doSekcji('prog=1&style=current&target=%23zewn-pusty'
    + '&rest=dim&segon=%23ff0000&segoff=%2300ff00', 100);
  const dw = await dim.evaluate(() => window.__w('#zewn-pusty'));
  t.check('widać wszystkie pozycje', dw.widocznych === 4, dw.widocznych + ' widocznych');
  t.check('bieżąca w kolorze bieżącego', near(rgb(dw.tekstAkt), [255, 0, 0], 0), dw.tekstAkt);
  t.check('a pozostałe w kolorze nieaktywnych',
    near(rgb(dw.tekstNieakt), [0, 255, 0], 0), dw.tekstNieakt);
  await dim.close();

  /* KONTROLA NEGATYWNA: domyślne „schowaj" — widać jedno, jak dotąd. */
  const hide = await doSekcji('prog=1&style=current&target=%23zewn-pusty&segon=%23ff0000', 100);
  const hw = await hide.evaluate(() => window.__w('#zewn-pusty'));
  t.check('domyślnie widać jedno', hw.widocznych === 1, hw.widocznych + ' widocznych');
  t.check('i jest w kolorze bieżącego', near(rgb(hw.tekstAkt), [255, 0, 0], 0), hw.tekstAkt);
  await hide.close();

  // ── Odstęp paneli od wskaźnika ───────────────────────────────────────────
  /* Wskaźnik wewnętrzny leży NA taśmie, więc bez odstępu przykrywa górę
     pierwszej karty — o to chodziło w zgłoszeniu „nachodzą". Odsuwa się
     TAŚMA, nie wskaźnik. */
  t.section('odstęp odsuwa karty od wskaźnika');

  const bezOd = await doSekcji('prog=1&style=segments', 100);
  const bo = await bezOd.evaluate(() => window.__hs(1));
  t.check('bez kontrolki karta dotyka krawędzi', bo.odKrawedzi === 0, bo.odKrawedzi + ' px');
  await bezOd.close();

  const zOd = await doSekcji('prog=1&style=segments&gap=24px', 100);
  const zo = await zOd.evaluate(() => window.__hs(1));
  t.check('z kontrolką karta odsuwa się o tyle, ile podano', zo.odKrawedzi === 24, zo.odKrawedzi + ' px');
  /* Kontrola pozytywna: odstęp ma NIE ruszyć matematyki przewijania. Zakres
     liczy się z szerokości taśmy, a padding jest pionowy. */
  t.check('a zakres przewijania zostaje bez zmian', zo.zakres === bo.zakres,
    zo.zakres + ' wobec ' + bo.zakres);
  await zOd.close();

  /* Przy wskaźniku przeniesionym na zewnątrz odstępu ma nie być — karty nie
     mają się od czego odsuwać. */
  const odZew = await doSekcji('prog=1&style=segments&target=%23zewn-pusty&gap=24px', 100);
  const oz = await odZew.evaluate(() => window.__hs(1));
  t.check('przy wskaźniku zewnętrznym odstępu nie ma', oz.odKrawedzi === 0, oz.odKrawedzi + ' px');
  await odZew.close();

  // ── Nowe zmienne na zewnątrz ─────────────────────────────────────────────
  /* Każda nowa zmienna musi trafić do listy przepisywanych — inaczej po cichu
     nie dociera do kontenera spoza elementu. */
  t.section('nowe zmienne docierają do zewnętrznego kontenera');

  const zewZm = await doSekcji('prog=1&style=segments&target=%23zewn-pusty'
    + '&segh=12px&segr=6px', 100);
  const zz = await zewZm.evaluate(() => window.__w('#zewn-pusty'));
  t.check('grubość i zaokrąglenie kreski działają POZA elementem',
    zz.wysoko.every((h) => h === 12) && zz.zaokrSeg === '6px',
    JSON.stringify(zz.wysoko) + ' / ' + zz.zaokrSeg);
  await zewZm.close();

  // ── Treść pod przypiętą sekcją ───────────────────────────────────────────
  /* Bez podglądu następna sekcja stoi w dokumencie o całą drogę taśmy niżej
     (`pin-spacer` ma wysokość sekcji + tej drogi) i wjeżdża dopiero na koniec.
     Z podglądem ma stać nieruchomo tuż pod sekcją przez cały czas. */
  t.section('treść pod sekcją stoi tuż pod nią przez całe przewijanie');

  const pk = await doSekcji('solo=1&peek=1', 0);
  const pk0 = await pk.evaluate(() => window.__pod());
  t.check('na starcie przypięcia treść jest w kadrze', pk0.wKadrze, pk0.top + ' px');
  t.check('i styka się z dolną krawędzią sekcji', Math.abs(pk0.top - pk0.sekcjaDol) <= 2,
    pk0.top + ' wobec ' + pk0.sekcjaDol);

  const gory = [pk0.top];
  const iksyPk = [(await pk.evaluate(() => window.__hs(1))).x];
  for (const dy of [300, 600]) {
    await przewin(pk, dy, 250);
    gory.push((await pk.evaluate(() => window.__pod())).top);
    iksyPk.push((await pk.evaluate(() => window.__hs(1))).x);
  }
  t.check('i ani drgnie przez całe przypięcie',
    Math.max.apply(null, gory) - Math.min.apply(null, gory) <= 2, JSON.stringify(gory));
  /* Kontrola pozytywna: „treść stoi" jest prawdą także wtedy, gdy nie dzieje się
     NIC. Taśma musi w tym samym czasie przejechać. */
  t.check('a taśma w tym czasie przejechała', iksyPk[0] === 0 && iksyPk[2] < -400,
    JSON.stringify(iksyPk));
  /* Transformacja MUSI być założona — to ona trzyma treść w miejscu. */
  const pkT = await pk.evaluate(() => window.__pod());
  t.check('trzyma ją transformacja', pkT.transform !== 'none', pkT.transform);
  await pk.close();

  /* KONTROLA NEGATYWNA: bez włącznika treść jedzie w górę i na starcie jest
     poza kadrem — czyli dokładnie to, na co poszło zgłoszenie. */
  const bezPk = await doSekcji('solo=1', 0);
  const b0 = await bezPk.evaluate(() => window.__pod());
  t.check('bez włącznika treść jest poza kadrem', !b0.wKadrze, b0.top + ' px');
  await przewin(bezPk, 600, 250);
  const b1 = await bezPk.evaluate(() => window.__pod());
  t.check('i przy przewijaniu jedzie w górę', b1.top < b0.top - 400,
    b0.top + ' → ' + b1.top);
  t.check('bez transformacji', b1.transform === 'none', b1.transform);
  await bezPk.close();

  // ── Wyjście z zakresu ────────────────────────────────────────────────────
  t.section('koniec przypięcia bez skoku, transformacja znika');

  const wyj = await doSekcji('solo=1&peek=1', 0);
  const zakresPk = (await wyj.evaluate(() => window.__hs(1))).zakres;

  await przewin(wyj, zakresPk - 40, 300);
  const przed = await wyj.evaluate(() => window.__pod());
  await przewin(wyj, zakresPk + 40, 300);
  const za = await wyj.evaluate(() => window.__pod());

  /* Gdyby zakres animacji był odwrócony, na złączeniu treść skoczyłaby o całą
     drogę taśmy. Osiemdziesiąt pikseli przewinięcia ma dać najwyżej tyle
     samo ruchu. */
  t.check('na złączeniu nic nie skacze', Math.abs(przed.top - za.top) <= 45,
    przed.top + ' → ' + za.top + ' (zakres ' + zakresPk + ' px)');
  /* Za zakresem transformacja ma zniknąć CAŁKIEM: nawet zerowa tworzy blok
     zawierający dla `position: fixed` i rozkłada wszystko, co pod spodem
     pozycjonuje się względem okna. */
  t.check('a transformacja znika całkiem', za.transform === 'none', za.transform);
  t.check('bez błędów JS', !wyj.errors.length, wyj.errors.join(' | ') || 'brak');
  await wyj.close();

  /* Podgląd nie ma prawa ruszyć matematyki taśmy. */
  const konPk = await doSekcji('solo=1&peek=1', 0);
  await przewin(konPk, 1000, 400);
  const kp = await konPk.evaluate(() => window.__hs(1));
  t.check('ostatnia karta dalej staje przy krawędzi', Math.abs(kp.ogonek) <= 2, kp.ogonek + ' px');
  await konPk.close();

  // ── Drugi przypinany element pod spodem ──────────────────────────────────
  /* Przesuwanie treści to transformacja, a ta tworzy blok zawierający dla
     `position: fixed` — czyli dla tego, czym ScrollTrigger przypina. Drugi
     przypinany element niżej przestałby działać, więc podgląd ma się NIE
     włączyć i powiedzieć o tym. */
  t.section('drugi przypinany element niżej wyłącza podgląd');

  const kol = await doSekcji('peek=1', 0);
  t.check('ostrzeżenie mówi, dlaczego podgląd nie działa',
    kol.warnings.some((w) => /Podgląd treści pod sekcją wyłączony/.test(w)),
    kol.warnings.find((w) => /Podgląd/.test(w)) || 'brak ostrzeżenia');
  const k0 = await kol.evaluate(() => window.__pod());
  t.check('a treść zachowuje się jak dawniej', k0.transform === 'none' && !k0.wKadrze,
    k0.transform + ', w kadrze: ' + k0.wKadrze);
  /* Kontrola pozytywna: druga sekcja MA dalej działać — o nią w tym całym
     odmawianiu chodzi. */
  const dwa = await doSekcji('peek=1', 0, 2);
  const d2p = await dwa.evaluate(() => window.__hs(2));
  t.check('a druga sekcja dalej się przypina', d2p.przypiety === 'sekcja', d2p.przypiety);
  t.check('bez błędów JS', !kol.errors.length && !dwa.errors.length,
    (kol.errors.concat(dwa.errors)).join(' | ') || 'brak');
  await kol.close();
  await dwa.close();

  // ── Falowanie przy wygładzanej taśmie ────────────────────────────────────
  /*
   * TU MIESZKAŁ BŁĄD z 1.105.0, którego cała reszta sprawdzeń podglądu nie
   * widziała: fixture domyślnie podaje `scrub: 0`, czyli BEZ wygładzania —
   * a w Bricksie domyślną wartością jest 1.
   *
   * Pin nie jest wygładzany, trzyma się przewijania co do piksela. Kompensacja
   * ma go dokładnie zniwelować, więc każde opóźnienie widać jako różnicę:
   * treść podjeżdżała nad sekcję i opadała po zatrzymaniu.
   *
   * Dlatego pomiar leci W TRAKCIE przewijania, po 60 ms od każdego kroku —
   * sekunda wygładzania nie ma wtedy szans dogonić. Po odczekaniu do końca
   * wszystko wygląda dobrze także z błędem.
   */
  t.section('przy wygładzanej taśmie treść pod spodem nadal stoi');

  const fal = await doSekcji('solo=1&peek=1&scrub=1', 0);
  const proby = [];
  for (let i = 1; i <= 6; i++) {
    await fal.evaluate((y) => window.__doPozycji(y), fal.start + i * 100);
    await fal.waitForTimeout(60);
    proby.push(await fal.evaluate(() => window.__pod()));
  }
  const topyFal = proby.map((p) => p.top);
  t.check('treść nie faluje w trakcie przewijania',
    Math.max.apply(null, topyFal) - Math.min.apply(null, topyFal) <= 2, JSON.stringify(topyFal));
  t.check('i ani razu nie wchodzi na sekcję',
    proby.every((p) => p.top >= p.sekcjaDol - 2),
    JSON.stringify(proby.map((p) => p.top - p.sekcjaDol)));
  t.check('bez błędów JS', !fal.errors.length, fal.errors.join(' | ') || 'brak');
  await fal.close();

  // ── Zapas przypięcia nie przechwytuje treści ─────────────────────────────
  /*
   * Regresja, którą zrobiłem i cofnąłem w tej samej wersji — warto ją tu
   * przybić.
   *
   * Kusi, żeby dać przypiętej sekcji `z-index: 1`, bo element z transformacją
   * maluje się jak `z-index: 0` i przy nachodzeniu wygrywa z sekcją. Tyle że
   * ScrollTrigger KOPIUJE styl przypiętego elementu na `pin-spacer`, a ten ma
   * wysokość sekcji plus całą drogę taśmy. Zmierzone: spacer dostawał
   * `position: relative; z-index: 1` i kładł się nad wystającą treścią na całej
   * swojej wysokości — czyli przechwytywał na niej kliknięcia.
   *
   * Mierzone `elementFromPoint`, bo `z-index` w arkuszu niczego by nie dowiódł:
   * ten wynik zależy od TRZECH elementów naraz i od tego, który z nich tworzy
   * kontekst nakładania.
   */
  t.section('zapas przypięcia nie przechwytuje treści pod spodem');

  const kto = await doSekcji('solo=1&peek=1', 300);
  const wSekcji = await kto.evaluate(() => window.__ktoNaWierzchu(-20));
  t.check('w sekcji na wierzchu jest sekcja', wSekcji.kto === 'sekcja', JSON.stringify(wSekcji));
  /* Tuż pod krawędzią leżą DWA elementy: wystająca treść i zapas przypięcia,
     który sięga daleko w dół. Wygrać ma treść — „pin-spacer" w tym miejscu
     znaczy, że zapas przykrył ją i zjada kliknięcia. */
  const podSekcja = await kto.evaluate(() => window.__ktoNaWierzchu(20));
  t.check('a tuż pod nią — treść, nie zapas przypięcia', podSekcja.kto === 'tresc',
    JSON.stringify(podSekcja));
  await kto.close();

  // ── Przejście przez próg wyłączenia ──────────────────────────────────────
  /* Poniżej progu `gsap.matchMedia()` cofa CAŁY blok — razem z pinem
     i podglądem. Po powrocie powyżej progu wszystko powstaje od nowa, tyle że
     tym razem W TRAKCIE odświeżania ScrollTriggera: pin zakłada się dopiero na
     jego końcu, więc przy budowaniu podglądu `pin-spacera` jeszcze nie ma.
     Stąd druga próba po odświeżeniu — i stąd to sprawdzenie, bo bez zmiany
     rozmiaru okna ta gałąź jest nieosiągalna. */
  t.section('po przejściu przez próg wyłączenia podgląd wraca');

  const prog = await t.open('hscroll.html', {
    viewport: V, settle: 600, query: 'solo=1&peek=1&below=900',
  });
  prog.start = await prog.evaluate(() => window.__gdzieSekcja(1));
  await prog.evaluate((y) => window.__doPozycji(y), prog.start);
  await prog.waitForTimeout(300);

  const pr0 = await prog.evaluate(() => window.__pod());
  t.check('przy szerokim oknie podgląd trzyma treść',
    Math.abs(pr0.top - pr0.sekcjaDol) <= 2, pr0.top + ' wobec ' + pr0.sekcjaDol);

  /* Poniżej progu ma zniknąć wszystko — także transformacja, bo inaczej
     zostałby blok zawierający na telefonie, gdzie nic już nie jest przypięte. */
  await prog.setViewportSize({ width: 800, height: 800 });
  await prog.waitForTimeout(500);
  const pr1 = await prog.evaluate(() => window.__pod());
  t.check('poniżej progu transformacja znika', pr1.transform === 'none', pr1.transform);

  await prog.setViewportSize({ width: 1200, height: 800 });
  await prog.waitForTimeout(700);
  await prog.evaluate((y) => window.__doPozycji(y), prog.start);
  await prog.waitForTimeout(400);
  const pr2 = await prog.evaluate(() => window.__pod());
  t.check('a po powrocie powyżej progu znów trzyma',
    Math.abs(pr2.top - pr2.sekcjaDol) <= 2, pr2.top + ' wobec ' + pr2.sekcjaDol);
  t.check('bez błędów JS', !prog.errors.length, prog.errors.join(' | ') || 'brak');
  await prog.close();

  // ── Sekcja na cały ekran ─────────────────────────────────────────────────
  /* Podgląd działa, ale pod sekcją nie zostaje ani piksel. Cisza byłaby tu
     myląca: włącznik włączony, a na ekranie bez zmian. */
  t.section('sekcja na cały ekran mówi, że nie ma czego pokazać');

  const wys = await doSekcji('solo=1&peek=1&tall=1', 0);
  t.check('ostrzeżenie o braku miejsca',
    wys.warnings.some((w) => /zajmuje cały ekran/.test(w)),
    wys.warnings.find((w) => /cały ekran/.test(w)) || 'brak ostrzeżenia');
  t.check('bez błędów JS', !wys.errors.length, wys.errors.join(' | ') || 'brak');
  await wys.close();

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
