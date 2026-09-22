/**
 * Backup — podmiana adresów bezpieczna dla serializacji.
 *
 * Najbardziej ryzykowna część modułu kopii: pomyłka tutaj nie wywala restore,
 * tylko po cichu psuje dane — unserialize() odrzuca wartość ze złą długością
 * i np. cały układ strony Bricksa znika po przeniesieniu. Dlatego sprawdzamy
 * WYNIK (czy da się go odczytać i czy mówi to, co ma mówić), a nie sam fakt,
 * że podmiana zaszła.
 *
 * Wszystko jedzie przez PRAWDZIWY moduł (tests/php/backup-serialize.php),
 * bez atrap WordPressa — moduł ma działać w gołym PHP-ie.
 */

const { phpOutput } = require('./lib/harness');
const fs = require('fs');
const path = require('path');

const STARY = 'https://stara.pl';
const NOWY  = 'https://nowa-dluzsza-domena.com.pl';

const b64  = (s) => Buffer.from(s, 'utf8').toString('base64');
const zb64 = (s) => Buffer.from(s, 'base64').toString('utf8');

/** Puszcza wartości przez moduł. Oddaje [{out, stats, unser}]. */
function przez(values, opcje) {
  const o = Object.assign({ urls: [[STARY, NOWY]] }, opcje || {});
  o.values = values.map(b64);
  const wynik = JSON.parse(phpOutput('backup-serialize.php', 'wartosci ' + b64(JSON.stringify(o))));
  return wynik.map((w) => ({ out: zb64(w.out), stats: w.stats, unser: w.unser }));
}

/** Zapis PHP-owy łańcucha — długość w BAJTACH, jak w PHP. */
const s = (str) => 's:' + Buffer.byteLength(str, 'utf8') + ':"' + str + '";';

module.exports = async function (t) {

  // ── Zwykły tekst ─────────────────────────────────────────────────────────
  t.section('zwykły tekst: podmiana z granicą domeny');

  const [zwykly, granice, kropka, dwa] = przez([
    'Zobacz ' + STARY + '/o-nas i wróć.',
    STARY + '.eu ' + STARY + 'us ' + STARY + '-sklep',
    'Więcej na ' + STARY + '.',
    STARY + '/a ' + STARY + '/b',
  ]);
  t.check('adres w tekście podmieniony',
    zwykly.out === 'Zobacz ' + NOWY + '/o-nas i wróć.', zwykly.out);
  /* KONTROLA NEGATYWNA: inna domena zaczynająca się tak samo. */
  t.check('stara.pl.eu, stara.plus i stara.pl-sklep nietknięte',
    granice.out === STARY + '.eu ' + STARY + 'us ' + STARY + '-sklep', granice.out);
  t.check('kropka kończąca zdanie nie blokuje podmiany',
    kropka.out === 'Więcej na ' + NOWY + '.', kropka.out);
  t.check('liczy każdą podmianę', dwa.stats.replaced === 2, JSON.stringify(dwa.stats));

  // ── Warianty zapisu adresu ───────────────────────────────────────────────
  t.section('warianty adresu: schemat, www, JSON, kodowanie URL, bez schematu');

  /* JSON ze STARYM schematem http — przy tym samym schemacie wynik dałby
     także wariant bez schematu (`\/\/stara.pl`) i sprawdzenie nie mówiłoby,
     czy wariant JSON w ogóle istnieje. */
  const warianty = przez([
    'http://stara.pl/x',
    'https://www.stara.pl/x',
    '{"u":"http:\\/\\/stara.pl\\/x"}',
    '?next=https%3A%2F%2Fstara.pl%2Fx',
    '<img src="//stara.pl/a.jpg">',
  ]);
  const oczekiwane = [
    NOWY + '/x',
    NOWY + '/x',
    '{"u":"https:\\/\\/nowa-dluzsza-domena.com.pl\\/x"}',
    '?next=https%3A%2F%2Fnowa-dluzsza-domena.com.pl%2Fx',
    '<img src="//nowa-dluzsza-domena.com.pl/a.jpg">',
  ];
  ['http → nowy schemat', 'www → nowy adres', 'JSON z \\/ (http → https)', 'zakodowany jak w URL', 'bez schematu (//)']
    .forEach((nazwa, i) => t.check(nazwa, warianty[i].out === oczekiwane[i], warianty[i].out));

  /* Nowy adres zawiera stary: bez jednego przejścia wariant `//stara.pl`
     dopadłby tekst już podmieniony i dałby /nowa/nowa. */
  const [podkatalog] = przez(['https://stara.pl/kontakt'], { urls: [[STARY, 'https://stara.pl/nowa']] });
  t.check('nowy adres zawierający stary nie jest podmieniany drugi raz',
    podkatalog.out === 'https://stara.pl/nowa/kontakt', podkatalog.out);

  /* Stara strona w podkatalogu: dłuższe dopasowanie wygrywa. Pary podane
     KRÓTSZĄ najpierw (home przed siteurl) — w odwrotnej kolejności wynik
     byłby dobry także bez sortowania i sprawdzenie niczego by nie mówiło. */
  const [dluzszy, krotszy] = przez(['https://stara.pl/blog/wpis', 'https://stara.pl/blogger'],
    { urls: [[STARY, 'https://nowa.pl'], ['https://stara.pl/blog', 'https://nowa.pl']] });
  t.check('dłuższy adres (siteurl z podkatalogiem) wygrywa z krótszym',
    dluzszy.out === 'https://nowa.pl/wpis', dluzszy.out);
  t.check('…a /blogger to nie /blog — idzie krótszą parą',
    krotszy.out === 'https://nowa.pl/blogger', krotszy.out);

  // ── Serializacja ─────────────────────────────────────────────────────────
  t.section('serializacja: długości przeliczone, wynik się odczytuje');

  const url = STARY + '/wp-content/uploads/a.jpg';
  const tablica = 'a:2:{s:3:"url";' + s(url) + 's:3:"alt";s:4:"opis";}';
  const [arr] = przez([tablica]);
  t.check('tablica: wynik odczytywalny przez unserialize()', arr.unser === true, arr.out);
  t.check('tablica: długość przeliczona w bajtach',
    arr.out.includes(s(NOWY + '/wp-content/uploads/a.jpg')), arr.out);

  /* Dłuższa i krótsza wartość — obie strony przeliczenia. */
  const [dluzsza, krotsza] = [
    przez([s(STARY)])[0],
    przez([s(STARY)], { urls: [[STARY, 'https://a.pl']] })[0],
  ];
  t.check('wartość dłuższa po podmianie', dluzsza.out === s(NOWY) && dluzsza.unser, dluzsza.out);
  t.check('wartość krótsza po podmianie', krotsza.out === s('https://a.pl') && krotsza.unser, krotsza.out);

  /* Polskie znaki: bajty ≠ znaki. Parser liczący znaki zaniżyłby długość
     i unserialize() odrzuciłby całość. */
  const pl = 'Zażółć gęślą ' + STARY;
  const [polskie] = przez([s(pl)]);
  t.check('polskie znaki: długość w bajtach, nie w znakach',
    polskie.out === s('Zażółć gęślą ' + NOWY) && polskie.unser, polskie.out);

  /* Serializacja w łańcuchu w serializacji — częste w opcjach wtyczek.
     Obie długości muszą się zgadzać: wewnętrzna i zewnętrzna. */
  const wewn = 'a:1:{s:1:"u";' + s(STARY + '/x') + '}';
  const [zagn] = przez(['a:1:{s:4:"opts";' + s(wewn) + '}']);
  const wewnPo = 'a:1:{s:1:"u";' + s(NOWY + '/x') + '}';
  t.check('serializacja zagnieżdżona: obie długości przeliczone',
    zagn.out === 'a:1:{s:4:"opts";' + s(wewnPo) + '}' && zagn.unser, zagn.out);

  /* Znaki udające koniec łańcucha w środku wartości. Parser szukający `";`
     zamiast liczyć długość rozciąłby tę wartość w pół. */
  const podstep = 'tekst";s:1:"x";} i ' + STARY;
  const [pod] = przez(['a:1:{i:0;' + s(podstep) + '}']);
  t.check('„";" i „}" wewnątrz wartości nie mylą parsera',
    pod.unser === true && pod.out.includes(s('tekst";s:1:"x";} i ' + NOWY)), pod.out);

  /* Obiekt klasy, której NIE MA. Tu leży cały sens parsera tekstowego:
     unserialize() nie jest wołane, więc nic się nie wywraca i nic nie
     powstaje. Nazwa klasy zostaje bajt w bajt. */
  const klasa = 'Wtyczka_Ktorej_Nie';
  const naglowek = 'O:' + klasa.length + ':"' + klasa + '":2:{';
  const obiekt = naglowek + 's:3:"url";' + s(STARY) + s('\0*\0chroni') + s(STARY + '/p') + '}';
  const [obj] = przez([obiekt]);
  t.check('obiekt klasy nieistniejącej: bez błędu, nazwa klasy nietknięta',
    obj.out.startsWith(naglowek) && obj.stats.broken === 0, obj.out);
  t.check('obiekt: właściwości (też chronione) podmienione, wynik odczytywalny',
    obj.unser === true && obj.out.includes(s(NOWY)) && obj.out.includes(s(NOWY + '/p')), obj.out);

  /* Klucze zostają: zmiana klucza potrafi zderzyć dwa wpisy w jeden. */
  const [klucze] = przez(['a:1:{' + s(STARY) + s(STARY) + '}']);
  t.check('klucz tablicy bez zmian, wartość podmieniona',
    klucze.out === 'a:1:{' + s(STARY) + s(NOWY) + '}', klucze.out);

  /* JSON wewnątrz serializacji — tak Bricks i inni trzymają część ustawień. */
  const json = '{"img":"https:\\/\\/stara.pl\\/a.png"}';
  const [js] = przez(['a:1:{i:0;' + s(json) + '}']);
  t.check('JSON z \\/ wewnątrz serializacji: podmiana i przeliczenie',
    js.unser === true && js.out.includes(s('{"img":"https:\\/\\/nowa-dluzsza-domena.com.pl\\/a.png"}')), js.out);

  /* Referencje, liczby, null, bool — przechodzą bajt w bajt. (R:, nie r: —
     r: w PHP wskazuje wyłącznie obiekt, na skalar unserialize() go odrzuca.) */
  const mieszane = 'a:7:{i:0;' + s(STARY) + 'i:1;R:2;i:2;i:-5;i:3;d:0.5;i:4;b:1;i:5;N;i:6;d:-INF;}';
  const [mix] = przez([mieszane]);
  t.check('referencje, liczby, null, bool przechodzą bez zmian',
    mix.unser === true && mix.out === mieszane.replace(s(STARY), s(NOWY)), mix.out);

  // ── Czego parser świadomie nie rusza ─────────────────────────────────────
  t.section('zepsute i nieprzezroczyste: bez zmian, policzone');

  /* Zła długość już w źródle. Podmiana na takim zapisie może go tylko
     popsuć bardziej — zostaje jak był, a statystyka to mówi. */
  const zepsuty = 'a:1:{i:0;s:99:"' + STARY + '";}';
  const [zep] = przez([zepsuty]);
  t.check('zepsuta serializacja zostaje bajt w bajt', zep.out === zepsuty, zep.out);
  t.check('…i jest policzona jako broken', zep.stats.broken === 1 && zep.stats.replaced === 0,
    JSON.stringify(zep.stats));

  /* C: — ładunek w formacie klasy. Przechodzi bajt w bajt, reszta obok
     podmieniona normalnie. */
  const ladunek = 'x:i:0;a:1:{i:0;' + s(STARY) + '};m:a:0:{}';
  const cTok = 'C:11:"ArrayObject":' + Buffer.byteLength(ladunek) + ':{' + ladunek + '}';
  const [cc] = przez(['a:2:{i:0;' + cTok + 'i:1;' + s(STARY) + '}']);
  t.check('C: przechodzi bez zmian, sąsiednia wartość podmieniona',
    cc.out === 'a:2:{i:0;' + cTok + 'i:1;' + s(NOWY) + '}', cc.out);
  t.check('…i jest policzony jako opaque', cc.stats.opaque === 1, JSON.stringify(cc.stats));

  /* Głębokość: parser rekurencyjny bez limitu wywróciłby proces. Z limitem
     ma oddać wartość bez zmian — i nie paść. */
  let gleboki = s(STARY);
  for (let i = 0; i < 600; i++) gleboki = 'a:1:{i:0;' + gleboki + '}';
  const [gl] = przez([gleboki]);
  t.check('zagnieżdżenie ponad limit: bez zmian, bez wywrotki',
    gl.out === gleboki && gl.stats.broken === 1, JSON.stringify(gl.stats));

  /* Tekst zaczynający się jak serializacja, ale nią niebędący. */
  const [udaje] = przez(['i: tak, na ' + STARY]);
  t.check('tekst udający początek serializacji dostaje zwykłą podmianę',
    udaje.out === 'i: tak, na ' + NOWY, udaje.out);

  // ── Ścieżki i adresy e-mail ──────────────────────────────────────────────
  t.section('ścieżki i adresy e-mail');

  const sciezki = { paths: [['/home/stara/public_html', '/var/www/nowa']] };
  const [sc, sc2, scJ] = przez(['/home/stara/public_html/wp-content/uploads',
    '/home/stara/public_html2/x', '"\\/home\\/stara\\/public_html\\/a"'], sciezki);
  t.check('ścieżka podmieniona', sc.out === '/var/www/nowa/wp-content/uploads', sc.out);
  t.check('ścieżka o tym samym początku nietknięta', sc2.out === '/home/stara/public_html2/x', sc2.out);
  t.check('ścieżka w zapisie JSON', scJ.out === '"\\/var\\/www\\/nowa\\/a"', scJ.out);

  const pary = (o) => JSON.parse(phpOutput('backup-serialize.php', 'pary ' + b64(JSON.stringify(o))));
  const korzen = pary({ urls: [], paths: [['/', '/x'], ['', '/x'], ['/ab', '/x']] });
  t.check('ścieżka „/", pusta ani zbyt krótka nie wchodzi do podmian',
    Object.keys(korzen).length === 0, JSON.stringify(korzen));

  const [mail] = przez(['Pisz: biuro@stara.pl'], { emails: true, urls: [[STARY, 'https://nowa.pl']] });
  t.check('e-mail w domenie podmieniony, gdy włączone', mail.out === 'Pisz: biuro@nowa.pl', mail.out);
  const [mailOff] = przez(['Pisz: biuro@stara.pl'], { urls: [[STARY, 'https://nowa.pl']] });
  t.check('…a bez włączenia zostaje', mailOff.out === 'Pisz: biuro@stara.pl', mailOff.out);
  /* Kopia robocza na poddomenie — adresy e-mail mają zostać prawdziwe. */
  const pod1 = pary({ emails: true, urls: [[STARY, 'https://test.stara.pl']] });
  const pod2 = pary({ emails: true, urls: [['https://test.stara.pl', STARY]] });
  t.check('przenosiny na poddomenę i z poddomeny nie ruszają e-maili',
    !Object.keys(pod1).some((k) => k.startsWith('@')) && !Object.keys(pod2).some((k) => k.startsWith('@')),
    JSON.stringify(Object.keys(pod1).filter((k) => k.startsWith('@'))));

  // ── Porównanie różnicowe ─────────────────────────────────────────────────
  t.section('porównanie różnicowe z niezależnym wzorcem (unserialize → serialize)');

  /* Wzorzec liczy to samo zupełnie inną drogą. Trzy liczby pokrycia są po
     to, żeby „zero rozjazdów" nie oznaczało „nic nie było do podmiany". */
  const los = JSON.parse(phpOutput('backup-serialize.php', 'losowe 20260922 800'));
  t.check('800 losowych struktur: parser = wzorzec co do bajtu',
    los.rozjazdy.length === 0, JSON.stringify(los.rozjazdy[0] || {}).slice(0, 600));
  t.check('każdy wynik odczytywalny', los.niepoprawne === 0, 'niepoprawnych: ' + los.niepoprawne);
  t.check('losowanie naprawdę pokryło podmiany, obiekty i zagnieżdżenia',
    los.podmian > 100 && los.obiektow > 50 && los.zagniezdzen > 50,
    'podmian ' + los.podmian + ', obiektów ' + los.obiektow + ', zagnieżdżeń ' + los.zagniezdzen);

  // ── Źródło ───────────────────────────────────────────────────────────────
  t.section('źródło modułu');

  const zrodlo = fs.readFileSync(path.join(__dirname, '..', 'includes', 'backup', 'serialize-replace.php'), 'utf8');
  /* Bez komentarzy — w komentarzach unserialize() jest opisane jako to,
     czego NIE robimy. */
  const kod = zrodlo.replace(/\/\*[\s\S]*?\*\//g, '').replace(/\/\/[^\n]*/g, '');
  t.check('moduł nie woła unserialize()', !/\bunserialize\s*\(/.test(kod));
  t.check('moduł nie woła funkcji WordPressa (ładuje się w gołym PHP)',
    !/\b(?:wp_\w+|get_option|apply_filters|esc_\w+|sanitize_\w+)\s*\(/.test(kod));
};
