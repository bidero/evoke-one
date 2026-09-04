/**
 * Sierotki — twarda spacja po spójniku jednoliterowym.
 *
 * Po polsku spójnik jednoliterowy nie ma prawa zostać na końcu wiersza. Sama
 * zamiana jest jednolinijkowa; trudne jest to, CZEGO nie wolno ruszyć — a to
 * widać dopiero na prawdziwym HTML-u, nie na gołym zdaniu.
 *
 * Wzorce nie są tu przepisane: każde sprawdzenie jedzie przez `popraw()`
 * prawdziwego modułu (tests/php/sierotki.php). Kopia wyrażeń w teście
 * rozjechałaby się przy pierwszej poprawce i pilnowałaby wtedy testu,
 * nie wtyczki.
 */

const { phpOutput } = require('./lib/harness');
const fs = require('fs');
const path = require('path');

/** Twarda spacja pokazana wprost — inaczej różnicy nie widać w komunikacie. */
const NBSP = ' ';
const widac = (s) => s.replace(/ /g, '[·]');

/** Puszcza tekst przez moduł. `ust` to nadpisanie ustawień. */
function przez(tekst, ust) {
  return phpOutput('sierotki.php',
    JSON.stringify(tekst) + ' ' + JSON.stringify(JSON.stringify(ust || {})));
}

module.exports = async function (t) {

  // ── Sedno ────────────────────────────────────────────────────────────────
  t.section('spójnik jednoliterowy nie zostaje na końcu wiersza');

  const proste = przez('<p>Idziemy w las i po drodze o tym pomyślimy.</p>');
  t.check('spacja po spójniku staje się twarda',
    proste.includes('w' + NBSP + 'las') && proste.includes('i' + NBSP + 'po')
    && proste.includes('o' + NBSP + 'tym'),
    widac(proste));

  /* Wszystkie sześć liter, i wielkie też — zdanie zaczynające się od „A" ma
     tę samą sierotkę co w środku. */
  const litery = przez('<p>a i o u w z A I O U W Z koniec</p>');
  const ilePar = (litery.match(/ /g) || []).length;
  t.check('obejmuje wszystkie spójniki, małe i wielkie', ilePar === 12,
    ilePar + ' twardych spacji: ' + widac(litery));

  /* KONTROLA NEGATYWNA. Bez niej „zamienia spacje" przechodziłoby też dla
     kodu, który zamienia KAŻDĄ spację — a to psułoby cały tekst. */
  const zwykle = przez('<p>Ala ma kota i pies</p>');
  t.check('a zwykłych spacji nie rusza',
    (zwykle.match(/ /g) || []).length === 1, widac(zwykle));

  /* Ostatnia litera w wyrazie to nie sierotka — bez warunku z lewej „ma a"
     i „mam a" łapałyby się tak samo. */
  const konceWyrazu = przez('<p>mam a potem</p>');
  t.check('litera na końcu wyrazu nie jest sierotką',
    konceWyrazu.includes('mam a' + NBSP + 'potem')
    && !konceWyrazu.includes('mam' + NBSP), widac(konceWyrazu));

  /* Spójnik na samym końcu nie ma z czym się skleić. Dwa przypadki, i różnią
     się tym, co po nim stoi: raz nie ma nic, raz jest spacja przed znacznikiem.
     Ten drugi jest tu ważniejszy — bez warunku z prawej twarda spacja wchodzi
     przed `</p>` i zostaje wiszącym znakiem, którego nikt nie widzi. */
  t.check('spójnik na końcu tekstu zostaje bez zmian',
    !przez('<p>Sam a</p>').includes(NBSP), widac(przez('<p>Sam a</p>')));
  t.check('spacja przed zamknięciem znacznika też nie twardnieje',
    !przez('<p>Idziemy w </p>').includes(NBSP), widac(przez('<p>Idziemy w </p>')));

  // ── Czego nie wolno ruszyć ──────────────────────────────────────────────
  /* To jest właściwa trudność tego modułu. Wyrażenie puszczone na cały HTML
     wchodzi w atrybuty — twarda spacja w adresie albo w nazwie klasy psuje
     stronę cicho i trudno to potem powiązać z typografią. */
  t.section('wnętrza znaczników i miejsca wyłączone zostają nietknięte');

  const atryb = przez('<img alt="a to jest opis" src="/a b.jpg" class="i tak"> idź w las');
  t.check('atrybuty nietknięte',
    atryb.includes('alt="a to jest opis"') && atryb.includes('src="/a b.jpg"')
    && atryb.includes('class="i tak"'), widac(atryb).slice(0, 70));
  /* Druga połowa pary: tekst OBOK atrybutów ma być poprawiony. Bez niej
     „atrybuty nietknięte" przechodziłoby dla kodu, który nie robi nic. */
  t.check('a tekst obok nich poprawiony', atryb.includes('w' + NBSP + 'las'),
    widac(atryb).slice(-30));

  const kod = przez('<pre>echo w las;</pre><code>a b</code><p>idź w las</p>');
  t.check('kod i blok preformatowany pomijane',
    kod.includes('<pre>echo w las;</pre>') && kod.includes('<code>a b</code>'),
    widac(kod));
  t.check('a akapit za nimi już nie', kod.includes('idź w' + NBSP + 'las'), widac(kod));

  /* `<textarea>` wygląda w źródle jak zwykły tekst i najłatwiej o nim
     zapomnieć — a twarda spacja trafiłaby stamtąd do tego, co ktoś wyśle. */
  const pole = przez('<textarea>wpisz a potem</textarea><p>a potem</p>');
  t.check('pole tekstowe formularza też pomijane',
    pole.includes('<textarea>wpisz a potem</textarea>'), widac(pole));

  // ── Wyjątki z panelu ────────────────────────────────────────────────────
  t.section('pomijane klasy i identyfikatory');

  const wyj = przez('<div class="kod">idź w las</div><p>idź w las</p>', { wyjatki: '.kod' });
  t.check('element z klasą z listy jest pomijany',
    wyj.includes('<div class="kod">idź w las</div>'), widac(wyj));
  /* SEDNO: pomijanie ma się SKOŃCZYĆ na zamknięciu. Zmierzone — przy złym
     warunku głębokości cała reszta strony zostawała nietknięta. */
  t.check('a po jego zamknięciu poprawianie wraca',
    wyj.includes('<p>idź w' + NBSP + 'las</p>'), widac(wyj));

  const zagn = przez('<div class="kod"><span>idź w las</span></div><p>idź w las</p>',
                     { wyjatki: 'kod' });
  t.check('zagnieżdżenie w pomijanym też jest pomijane',
    zagn.includes('<span>idź w las</span>') && zagn.includes('<p>idź w' + NBSP + 'las</p>'),
    widac(zagn));

  /* ZNACZNIK PUSTY W POMIJANYM BLOKU. `<br>` i `<img>` nie mają zamknięcia,
     więc wepchnięte na stos przesuwałyby wszystkie głębokości — pierwsze
     `</div>` zdjęłoby wtedy `br` zamiast `div` i pomijanie nie skończyłoby się
     nigdy. Sprawdzenie stoi na tym, co widać: czy tekst PO bloku wraca do
     poprawiania. */
  const puste = przez('<div class="kod">idź w las<br><img src="a.jpg"></div><p>idź w las</p>',
                      { wyjatki: 'kod' });
  t.check('znacznik pusty w pomijanym bloku nie psuje głębokości',
    puste.includes('<div class="kod">idź w las<br>') && puste.includes('<p>idź w' + NBSP + 'las</p>'),
    widac(puste));

  const ident = przez('<div id="stopka">idź w las</div>', { wyjatki: '#stopka' });
  t.check('identyfikator działa tak samo jak klasa',
    !ident.includes(NBSP), widac(ident));

  /* Bez wyjątku ten sam kod MA być poprawiony — inaczej „wyjątek działa"
     nie odróżnia się od „nic nie działa". */
  const bezWyj = przez('<div class="kod">idź w las</div>');
  t.check('bez wpisanego wyjątku ten sam element jest poprawiany',
    bezWyj.includes('w' + NBSP + 'las'), widac(bezWyj));

  // ── Liczby z jednostkami ────────────────────────────────────────────────
  t.section('liczba i jednostka trzymają się razem');

  const jedn = przez('<p>Trasa ma 5 km, zajmie 30 min, w 2024 r.</p>');
  t.check('liczba wiąże się z krótkim wyrazem po niej',
    jedn.includes('5' + NBSP + 'km') && jedn.includes('30' + NBSP + 'min')
    && jedn.includes('2024' + NBSP + 'r.'), widac(jedn));

  const proc = przez('<p>wzrost 10 % i 3 °C</p>');
  t.check('procent i stopień też', proc.includes('10' + NBSP + '%')
    && proc.includes('3' + NBSP + '°'), widac(proc));

  /* Wyłącznik z panelu ma naprawdę wyłączać — spójniki zostają, jednostki nie. */
  const bezJedn = przez('<p>Trasa ma 5 km i 3 %</p>', { jednostki: 0 });
  t.check('wyłączona opcja zostawia liczby w spokoju',
    !bezJedn.includes('5' + NBSP) && !bezJedn.includes('3' + NBSP), widac(bezJedn));
  t.check('a spójniki poprawia dalej', bezJedn.includes('i' + NBSP + '3'), widac(bezJedn));

  // ── Dwukrotne przetworzenie ─────────────────────────────────────────────
  /* Tekst wraca przez ten filtr wielokrotnie: raz z Bricksa, raz z `the_content`,
     a przy tłumaczeniach jeszcze raz. Druga porcja nie ma prawa niczego
     zepsuć ani dołożyć. */
  t.section('drugie przejście niczego nie zmienia');

  const raz  = przez('<p>Idziemy w las i o tym pomyślimy.</p>');
  const dwa  = przez(raz);
  t.check('wynik jest identyczny za drugim razem', raz === dwa,
    raz === dwa ? 'bez zmian' : widac(raz) + '  ≠  ' + widac(dwa));

  /* Encja `&nbsp;` wpisana z ręki w edytorze to ta sama sytuacja — nie ma
     tam spacji do zamiany i nie może się dorobić drugiej. */
  const encja = przez('<p>Idziemy w&nbsp;las</p>');
  t.check('encja &nbsp; wpisana z ręki zostaje jedna',
    encja === '<p>Idziemy w&nbsp;las</p>', widac(encja));

  // ── Gdzie moduł się wpina ───────────────────────────────────────────────
  /* Czytane ze ŹRÓDŁA, bo tego nie widać w wyjściu `popraw()`. Na stronie
     budowanej Bricksem większość tekstu nie przechodzi przez `the_content` —
     bez wpięcia w `bricks/frontend/render_data` moduł łapałby wpisy bloga
     i niewiele więcej, a to jest cały powód, dla którego go piszemy. */
  t.section('wpięcia: Bricks obowiązkowo, klasyczne filtry obok');

  const zrodlo = fs.readFileSync(
    path.join(__dirname, '..', 'includes', '91-sierotki.php'), 'utf8');

  t.check('wpina się w treść renderowaną przez Bricksa',
    /add_filter\('bricks\/frontend\/render_data'/.test(zrodlo), 'bricks/frontend/render_data');
  t.check('oraz w treść, wyciąg i tytuł',
    /add_filter\('the_content'/.test(zrodlo) && /add_filter\('the_excerpt'/.test(zrodlo)
    && /add_filter\('the_title'/.test(zrodlo), 'the_content, the_excerpt, the_title');

  /* KOLEJNOŚĆ WOBEC TŁUMACZEŃ. Silnik tłumaczeń siedzi na tym samym filtrze
     z priorytetem 1; sierotki muszą pójść PO nim, bo inaczej poprawiałyby
     tekst, który za chwilę zostanie podmieniony na inny język. */
  const tlum = fs.readFileSync(
    path.join(__dirname, '..', 'includes', '50-translation-engine.php'), 'utf8');
  const prTlum = (tlum.match(/add_filter\('bricks\/frontend\/render_data'[\s\S]*?\}, (\d+)\)/) || [])[1];
  const prNas  = (zrodlo.match(/add_filter\('bricks\/frontend\/render_data',[^;]*?, (\d+)\)/) || [])[1];
  t.check('i idzie PO tłumaczeniach, nie przed',
    prNas !== undefined && prTlum !== undefined && Number(prNas) > Number(prTlum),
    'sierotki ' + prNas + ', tłumaczenia ' + prTlum);

  /* Wyłączony moduł nie ma prawa wpinać się w nic — inaczej przełącznik
     w panelu byłby ozdobą. */
  t.check('a przy wyłączonym module nie wpina się wcale',
    /if \(empty\(\$this->get_settings\(\)\['enabled'\]\)\) return;/.test(zrodlo),
    'wyjście przed add_filter');
};
