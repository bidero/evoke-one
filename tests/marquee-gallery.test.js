/**
 * Marquee — wiersz „galeria" z Evoke Fields.
 *
 * PIERWSZE sprawdzenia `render()` tego elementu. Do 1.103.0 marquee miało
 * wyłącznie testy przeglądarkowe o pauzie poza kadrem.
 *
 * Wszystko idzie przez `phpOutput()`, czyli PRAWDZIWY `element.php` z atrapami
 * Evoke Fields — i to nie z wygody. Trzech rzeczy nie da się zobaczyć na
 * stronie: z czym wywołano `evk_get_field()` („bieżący wpis" to zero podane
 * wtyczce, a z ekranu wygląda tak samo jak wpisany numer), że obie kopie taśmy
 * dostały tę samą wylosowaną kolejność, i że brak wtyczki nie wywraca rendera.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {

  const d = JSON.parse(phpOutput('marquee-gallery.php'));

  // ── Rozwinięcie galerii ──────────────────────────────────────────────────
  /* Sedno całej wersji: JEDEN wiersz repeatera, tyle obrazów, ile ich
     w galerii. */
  t.section('jeden wiersz galerii rozwija się we wszystkie obrazy');

  t.check('galeria dała cztery obrazy na kopię', d.zWpisu.kopiaA.length === 4,
    d.zWpisu.kopiaA.length + ' obrazów');
  /* Taśma jedzie w dwóch kopiach — bez tego „cztery na kopię" nie mówiłoby nic
     o tym, ile ich naprawdę wyszło. */
  t.check('i obie kopie taśmy są', d.zWpisu.kopii === 2 && d.zWpisu.obrazow === 8,
    d.zWpisu.kopii + ' kopie, ' + d.zWpisu.obrazow + ' obrazów');
  t.check('a kolejność jest ta z galerii',
    d.zWpisu.kopiaA.join(',') === '/media/11.jpg,/media/12.jpg,/media/13.jpg,/media/14.jpg',
    d.zWpisu.kopiaA.join(','));

  /* KONTROLA NEGATYWNA: zwykły wiersz „obraz" ma dalej dawać JEDEN obraz.
     Bez niej „galeria daje cztery" byłoby spełnione także wtedy, gdyby element
     powielał każdy wiersz. */
  t.check('a zwykły wiersz „obraz" dalej daje jeden',
    d.mieszane.kopiaA.length === 5 && d.mieszane.kopiaA[0] === '/media/21.jpg',
    d.mieszane.kopiaA.join(','));
  t.check('i tekst obok galerii zostaje', d.mieszane.teksty.join(',') === 'EVOKE,EVOKE',
    d.mieszane.teksty.join(','));

  // ── Trzy źródła ──────────────────────────────────────────────────────────
  /* Galeria bywa w trzech miejscach, a z ekranu nie widać, do którego sięgnął
     element — atrapy zapisują więc, z czym je wywołano. */
  t.section('każde źródło sięga tam, gdzie trzeba');

  t.check('„bieżący wpis" idzie do Evoke Fields z zerem',
    d.wywolanieWpis && d.wywolanieWpis.fn === 'field' && d.wywolanieWpis.post === 0,
    JSON.stringify(d.wywolanieWpis));
  /* Zero nie jest tu zaniedbaniem: `evk_get_field()` sam podstawia za nie
     bieżący wpis (`$post_id ?: get_the_ID()`). */
  t.check('i prosi o wariant „ids"', d.wywolanieWpis.prop === 'ids', d.wywolanieWpis.prop);

  t.check('„wskazany wpis" idzie ze swoim numerem', d.wywolanieWskazany.post === 7,
    JSON.stringify(d.wywolanieWskazany));
  /* Kontrola pozytywna: numer ma NAPRAWDĘ zmieniać wynik, a nie tylko jechać
     w wywołaniu. */
  t.check('i daje obrazy tamtego wpisu',
    d.zeWskazanego.kopiaA.join(',') === '/media/21.jpg,/media/22.jpg',
    d.zeWskazanego.kopiaA.join(','));

  t.check('„strona ustawień" woła zupełnie inną funkcję',
    d.wywolanieOpcje.fn === 'option' && d.wywolanieOpcje.grupa === 'globalne',
    JSON.stringify(d.wywolanieOpcje));
  /* I to jest miejsce, w którym łatwo o cichą pustkę: ta droga oddaje SUROWĄ
     tablicę wierszy `['img'=>…]`, a nie tekst po przecinkach jak pole wpisu. */
  t.check('a surowa tablica wierszy też daje obrazy', d.zOpcji.kopiaA.length === 3,
    d.zOpcji.kopiaA.join(','));

  // ── Normalizator ─────────────────────────────────────────────────────────
  t.section('jedno wejście na wszystkie kształty wartości');

  const n = d.normalizator;
  t.check('tekst po przecinkach i tablica wierszy dają to samo',
    n.tekst.join(',') === '11,12,13' && n.wiersze.join(',') === '11,12,13',
    JSON.stringify(n.tekst) + ' / ' + JSON.stringify(n.wiersze));
  t.check('tablica liczb też', n.liczby.join(',') === '11,12,13', JSON.stringify(n.liczby));
  /* Kontrola negatywna: pustka ma zostać pustką, a nie zamienić się w jeden
     śmieciowy numer. */
  t.check('a pustka i null dają pustkę',
    n.puste.length === 0 && n.nic.length === 0,
    JSON.stringify(n.puste) + ' / ' + JSON.stringify(n.nic));

  // ── Kolejność i limit ────────────────────────────────────────────────────
  t.section('kolejność liczy się przed limitem');

  t.check('„odwrotna + 3" daje trzy OSTATNIE obrazy',
    d.odwrotnaZLimitem.kopiaA.join(',') === '/media/14.jpg,/media/13.jpg,/media/12.jpg',
    d.odwrotnaZLimitem.kopiaA.join(','));

  /* Losowanie MUSI paść raz, przed pętlą kopii. Marquee zapętla się tym, że
     druga kopia jest co do znaku identyczna z pierwszą — dwa różne losowania
     dałyby widoczny przeskok na złączeniu. */
  t.check('losowanie zapada raz — obie kopie identyczne',
    d.losowa.kopiaA.join(',') === d.losowa.kopiaB.join(','),
    d.losowa.kopiaA.join(',') + '  vs  ' + d.losowa.kopiaB.join(','));
  t.check('i nic po drodze nie zginęło', d.losowa.kopiaA.length === 6,
    d.losowa.kopiaA.length + ' obrazów');

  // ── Brak wtyczki i złe dane ──────────────────────────────────────────────
  /* Evoke Fields to osobna wtyczka. Nieznana funkcja zabiłaby CAŁY render
     strony, nie samo marquee. */
  t.section('brak Evoke Fields i złe dane nie wywracają strony');

  t.check('atrapy naprawdę nie istniały przy tym renderze',
    d.funkcjeIstnialy === false, String(d.funkcjeIstnialy));
  t.check('bez wtyczki galeria nie daje nic', d.brakWtyczki.obrazow === 0,
    d.brakWtyczki.obrazow + ' obrazów');
  /* Kontrola pozytywna: reszta taśmy ma jechać dalej. Samo „zero obrazów"
     byłoby prawdą także wtedy, gdyby render padł w połowie. */
  t.check('a tekst obok jedzie dalej', d.brakWtyczki.teksty.join(',') === 'EVOKE,EVOKE',
    d.brakWtyczki.teksty.join(','));

  t.check('nietrafiony klucz gasi sam wiersz', d.zlyKlucz.obrazow === 0,
    d.zlyKlucz.obrazow + ' obrazów');
  t.check('i nie rusza reszty', d.zlyKlucz.teksty.join(',') === 'EVOKE,EVOKE',
    d.zlyKlucz.teksty.join(','));

  /* Zero i numer spoza biblioteki. Puste pudełko rozpychałoby odstępy taśmy
     w miejscu, w którym nic nie widać — najgorszy rodzaj usterki. */
  t.check('śmieci w galerii wypadają bez śladu',
    d.dziury.kopiaA.join(',') === '/media/11.jpg,/media/12.jpg',
    d.dziury.kopiaA.join(','));
  t.check('i nie zostawiają pustych pudełek', d.dziury.pustePudelka === 0,
    d.dziury.pustePudelka + ' pustych');

  // ── Pusto: front milczy, builder mówi ────────────────────────────────────
  /* Pudełko zastępcze to sprzęt kanwy. Na froncie byłoby widocznym śmieciem
     w środku strony. */
  t.section('pusta galeria: front milczy, builder mówi');

  t.check('na froncie nie ma pudełka zastępczego', d.pustoFront.placeholder === false,
    String(d.pustoFront.placeholder));
  t.check('a w builderze jest', d.pustoBuilder.placeholder === true,
    String(d.pustoBuilder.placeholder));

  // ── Rozmiar i alt ────────────────────────────────────────────────────────
  t.section('szerokość i alt trafiają na każdy obraz galerii');

  /* „Na każdy", nie „na pierwszy": wspólny znacznik obrazu ma brać szerokość
     z wiersza, a nie z pierwszej pozycji listy. */
  t.check('cała galeria ma szerokość z wiersza',
    d.zWpisu.szerokosci.length === 1 && d.zWpisu.szerokosci[0] === '90px',
    JSON.stringify(d.zWpisu.szerokosci));
  /* KONTROLA NEGATYWNA: obok, w tym samym renderze, wiersz „obraz" ma własną
     szerokość — inaczej „bierze z wiersza" byłoby prawdą także dla wartości
     wpisanej na sztywno. */
  t.check('a zwykły obraz obok ma swoją',
    d.mieszane.szerokosci.join(',') === '200px,90px', JSON.stringify(d.mieszane.szerokosci));

  t.check('alt idzie z biblioteki mediów', d.zWpisu.alty[1] === 'logo dwunastki',
    JSON.stringify(d.zWpisu.alty.slice(0, 4)));

  // ── Kontrolki ────────────────────────────────────────────────────────────
  /* Okablowania `required` nie widać w znaczniku — pole po prostu nie pokaże
     się w builderze i nie ma tego jak zgadnąć. */
  t.section('kontrolki wiersza są okablowane');

  const k = d.kontrolki;
  t.check('typ ma trzecią pozycję', k.typy.join(',') === 'text,image,gallery', k.typy.join(','));
  t.check('szerokość obrazu pokazuje się też przy galerii',
    JSON.stringify(k.szerokoscReq) === '["type","!=","text"]', JSON.stringify(k.szerokoscReq));
  t.check('klucz grupy tylko przy stronie ustawień',
    JSON.stringify(k.grupaReq) === '["type","=","gallery","gallery_source","=","option"]',
    JSON.stringify(k.grupaReq));
  t.check('numer wpisu tylko przy wskazanym wpisie',
    JSON.stringify(k.idReq) === '["type","=","gallery","gallery_source","=","post_id"]',
    JSON.stringify(k.idReq));
  t.check('kolejność ma trzy warianty', k.kolejnosc.join(',') === 'as-is,reverse,random',
    k.kolejnosc.join(','));
};
