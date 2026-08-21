/**
 * Marquee — wiersz „galeria" z danych dynamicznych.
 *
 * Do 1.103.1 element sam sięgał do Evoke Fields przez `evk_get_field()`.
 * Od 1.104.0 czyta JEDEN tag danych dynamicznych i nie zna już żadnej wtyczki
 * pól — zna tylko listę numerów załączników, która z tagu wychodzi.
 *
 * Wszystko idzie przez `phpOutput()`, czyli PRAWDZIWY `element.php` z atrapą
 * `bricks_render_dynamic_data()` — i to nie z wygody. Trzech rzeczy nie widać
 * na stronie: z czym wywołano dane dynamiczne, że obie kopie taśmy dostały tę
 * samą wylosowaną kolejność, i że brak Bricksa nie wywraca rendera.
 */

const { phpOutput } = require('./lib/harness');

module.exports = async function (t) {

  const d = JSON.parse(phpOutput('marquee-gallery.php'));

  // ── Rozwinięcie galerii ──────────────────────────────────────────────────
  t.section('jeden wiersz galerii rozwija się we wszystkie obrazy');

  t.check('tag dał cztery obrazy na kopię', d.zTagu.kopiaA.length === 4,
    d.zTagu.kopiaA.length + ' obrazów');
  /* Taśma jedzie w dwóch kopiach — bez tego „cztery na kopię" nie mówiłoby nic
     o tym, ile ich naprawdę wyszło. */
  t.check('i obie kopie taśmy są', d.zTagu.kopii === 2 && d.zTagu.obrazow === 8,
    d.zTagu.kopii + ' kopie, ' + d.zTagu.obrazow + ' obrazów');
  t.check('a kolejność jest ta z galerii',
    d.zTagu.kopiaA.join(',') === '/media/11.jpg,/media/12.jpg,/media/13.jpg,/media/14.jpg',
    d.zTagu.kopiaA.join(','));

  /* KONTROLA NEGATYWNA: zwykły wiersz „obraz" ma dalej dawać JEDEN obraz.
     Bez niej „galeria daje cztery" byłoby spełnione także wtedy, gdyby element
     powielał każdy wiersz. */
  t.check('a zwykły wiersz „obraz" dalej daje jeden',
    d.mieszane.kopiaA.length === 5 && d.mieszane.kopiaA[0] === '/media/21.jpg',
    d.mieszane.kopiaA.join(','));
  t.check('i tekst obok galerii zostaje', d.mieszane.teksty.join(',') === 'EVOKE,EVOKE',
    d.mieszane.teksty.join(','));

  // ── Tag i kontekst wpisu ─────────────────────────────────────────────────
  /* Z ekranu nie widać, co poszło do danych dynamicznych — a tag wpisany na
     sztywno wyglądałby identycznie jak wzięty z wiersza. */
  t.section('do danych dynamicznych idzie tag z wiersza i właściwy wpis');

  t.check('poszedł tag z wiersza',
    d.wywolanieTag && d.wywolanieTag.tag === '{evk_field_logotypy__ids}',
    JSON.stringify(d.wywolanieTag));

  t.check('a wpis z kontekstu elementu idzie dalej', d.wywolanieKontekst.post === 7,
    JSON.stringify(d.wywolanieKontekst));
  /* Kontrola pozytywna: kontekst ma NAPRAWDĘ zmieniać wynik, a nie tylko
     jechać w wywołaniu. */
  t.check('i daje obrazy tamtej galerii',
    d.zKontekstu.kopiaA.join(',') === '/media/21.jpg,/media/22.jpg',
    d.zKontekstu.kopiaA.join(','));

  /* Sedno przejścia na dane dynamiczne: element przestał znać Evoke Fields.
     Cudza wtyczka pól oddająca surową tablicę wierszy ma działać tak samo. */
  t.check('cudzy kształt wartości też działa', d.cudzyKsztalt.kopiaA.length === 3,
    d.cudzyKsztalt.kopiaA.join(','));

  /* Goły tag galerii oddaje ADRES pierwszego obrazu, nie listę ID. Ma z tego
     wyjść PUSTKA — jedno zdjęcie wyglądałoby na działającą galerię i pomyłka
     w wyborze wariantu przeszłaby niezauważona. */
  t.check('goły tag daje pustkę, a nie jedno zdjęcie', d.golyTag.obrazow === 0,
    d.golyTag.obrazow + ' obrazów');

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

  // ── Brak Bricksa i złe dane ──────────────────────────────────────────────
  t.section('brak danych dynamicznych i złe dane nie wywracają strony');

  t.check('atrapa naprawdę nie istniała przy tym renderze',
    d.funkcjeIstnialy === false, String(d.funkcjeIstnialy));
  t.check('bez danych dynamicznych galeria nie daje nic', d.brakWtyczki.obrazow === 0,
    d.brakWtyczki.obrazow + ' obrazów');
  /* Kontrola pozytywna: reszta taśmy ma jechać dalej. Samo „zero obrazów"
     byłoby prawdą także wtedy, gdyby render padł w połowie. */
  t.check('a tekst obok jedzie dalej', d.brakWtyczki.teksty.join(',') === 'EVOKE,EVOKE',
    d.brakWtyczki.teksty.join(','));

  t.check('nietrafiony tag gasi sam wiersz', d.zlyKlucz.obrazow === 0,
    d.zlyKlucz.obrazow + ' obrazów');
  t.check('i nie rusza reszty', d.zlyKlucz.teksty.join(',') === 'EVOKE,EVOKE',
    d.zlyKlucz.teksty.join(','));

  /* Zero i numer spoza biblioteki. Puste pudełko rozpychałoby odstępy taśmy
     w miejscu, w którym nic nie widać — najgorszy rodzaj usterki. */
  t.check('śmieci w liście ID wypadają bez śladu',
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
    d.zTagu.szerokosci.length === 1 && d.zTagu.szerokosci[0] === '90px',
    JSON.stringify(d.zTagu.szerokosci));
  /* KONTROLA NEGATYWNA: obok, w tym samym renderze, wiersz „obraz" ma własną
     szerokość — inaczej „bierze z wiersza" byłoby prawdą także dla wartości
     wpisanej na sztywno. */
  t.check('a zwykły obraz obok ma swoją',
    d.mieszane.szerokosci.join(',') === '200px,90px', JSON.stringify(d.mieszane.szerokosci));

  t.check('alt idzie z biblioteki mediów', d.zTagu.alty[1] === 'logo dwunastki',
    JSON.stringify(d.zTagu.alty.slice(0, 4)));

  // ── Kontrolki ────────────────────────────────────────────────────────────
  /* Okablowania kontrolek nie widać w znaczniku — pole po prostu nie pokaże
     się w builderze i nie ma tego jak zgadnąć. */
  t.section('kontrolki wiersza są okablowane');

  const k = d.kontrolki;
  t.check('typ ma trzecią pozycję', k.typy.join(',') === 'text,image,gallery', k.typy.join(','));
  t.check('galeria to JEDNO pole z piorunkiem',
    k.tagDynamic === true && JSON.stringify(k.tagReq) === '["type","=","gallery"]',
    'hasDynamicData: ' + k.tagDynamic + ', required: ' + JSON.stringify(k.tagReq));
  /* Cztery pola z 1.103.0 mają ZNIKNĄĆ, nie tylko przestać być wymagane —
     inaczej panel dalej pytałby o klucze, o które nie ma już potrzeby pytać. */
  t.check('a cztery stare pola zniknęły', k.stareUsuniete.length === 0,
    k.stareUsuniete.length ? 'zostały: ' + k.stareUsuniete.join(', ') : 'wszystkie usunięte');
  t.check('wiersz ma siedem pól', k.polaWiersza.length === 7, k.polaWiersza.join(', '));
  t.check('szerokość obrazu pokazuje się też przy galerii',
    JSON.stringify(k.szerokoscReq) === '["type","!=","text"]', JSON.stringify(k.szerokoscReq));
  t.check('kolejność ma trzy warianty', k.kolejnosc.join(',') === 'as-is,reverse,random',
    k.kolejnosc.join(','));

  /*
   * ŻADNE pole wiersza nie może mieć łańcucha warunków.
   *
   * Repeater Bricksa przyjmuje w polach wiersza pojedynczy warunek — tak działa
   * repeater Animatora (includes/anim/bricks-controls.php), jedyny sprawdzony
   * w boju w tej wtyczce. Łańcuch dwóch warunków rozłożył w 1.103.0 dokładanie
   * wierszy: repeater przestał je dokładać, a z ekranu wyglądało to jak martwy
   * przycisk „+".
   *
   * Tego NIE DA SIĘ zobaczyć w znaczniku — stąd sprawdzenie na kształcie tablicy.
   */
  const dlugie = Object.keys(k.wszystkieReq).filter((nazwa) => {
    const r = k.wszystkieReq[nazwa];
    return Array.isArray(r) && r.length !== 3;
  });
  t.check('żadne pole wiersza nie ma łańcucha warunków', dlugie.length === 0,
    dlugie.length ? dlugie.join(', ') : 'wszystkie po jednym warunku');
  /* Kontrola pozytywna: warunki w ogóle SĄ. Samo „żaden nie jest za długi"
     byłoby prawdą także wtedy, gdyby zniknęły wszystkie. */
  const zWarunkiem = Object.keys(k.wszystkieReq).filter((n) => k.wszystkieReq[n]);
  t.check('a sześć pól je ma', zWarunkiem.length === 6,
    zWarunkiem.length + ': ' + zWarunkiem.join(', '));
};
