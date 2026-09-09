/**
 * Ekran przeglądu sekcji — lista ekranów zakładki z przełącznikami.
 *
 * EKRAN PRZEGLĄDOWY TO PRAWIE SAME LICZNIKI I STANY, a te potrafią być fałszywe
 * bez żadnego objawu: przełącznik sięgający po nieistniejącą opcję rysuje się
 * poprawnie, pokazuje „wyłączone" i nikt się nie dowiaduje, że pyta o zły klucz.
 * Tak przyszedł kiedyś ekran startowy — bez testów — i stąd bierze się kształt
 * sprawdzeń niżej.
 *
 * Dlatego KAŻDY PRZEŁĄCZNIK JEST SPRAWDZANY OSOBNO, z własnym zasiewem. Licznik
 * zbiorczy („ile zaznaczonych") przeszedłby także wtedy, gdyby jedna nazwa opcji
 * była błędna, a inna liczyła się podwójnie.
 *
 * Znacznik bierzemy z PRAWDZIWEJ `evoke_one_render_settings()`, a strukturę
 * panelu z `--mapa`, czyli z tej samej wtyczki — żadna lista nie jest przepisana
 * w teście, bo rozjeżdżanie się dwóch spisów jest właśnie tą klasą usterki,
 * którą tu łapiemy.
 */

const fs   = require('fs');
const path = require('path');
const { phpOutput } = require('./lib/harness');

/** Panel wyrenderowany przez PHP: zasiew opcji, zakładka, opcjonalnie `?sub=`. */
const panel = (zasiew, tab, sub) =>
  phpOutput('panel-start.php',
    JSON.stringify(JSON.stringify(zasiew || {})) + ' ' + tab + (sub ? ' ' + sub : ''));

/** Sama lista przeglądu, bez powłoki panelu — pasek boczny i paleta mają te same adresy. */
const listaPrzegladu = (html) => {
  const od = html.indexOf('<div class="evo-przeglad">');
  return od === -1 ? '' : html.slice(od);
};

/** Wiersze przeglądu w kolejności renderu; klucz ekranu bierzemy z adresu wiersza. */
const wiersze = (html) =>
  listaPrzegladu(html)
    .split('evo-przeglad-wiersz')
    .slice(1)
    .map((kawalek) => {
      const m = kawalek.match(/[?&]sub=([a-z0-9_-]+)/i);
      return { sub: m ? m[1] : null, html: kawalek };
    });

const zaznaczony = (wiersz) => !!wiersz && /<input[^>]*\bchecked/.test(wiersz.html);

/** Wiersz o danym kluczu; nigdy `undefined`, żeby zepsuty render dał wynik, a nie wyjątek. */
const wiersz = (html, sub) => wiersze(html).find((w) => w.sub === sub) || { sub, html: '' };

/** Pary „opcja/pole" wypisane w znaczniku — oba szyki atrybutów, jak w admin-tabs. */
const paryZnacznika = (html) => {
  const out = [];
  for (const m of html.matchAll(/data-option="([^"]+)"[^>]*?data-field="([^"]+)"/g)) out.push(m[1] + '/' + m[2]);
  for (const m of html.matchAll(/data-field="([^"]+)"[^>]*?data-option="([^"]+)"/g)) out.push(m[2] + '/' + m[1]);
  return out;
};

module.exports = async function (t) {

  const mapa = JSON.parse(phpOutput('panel-start.php', '--mapa'));
  const sekcja = mapa.przeglad[0];
  const ekrany = mapa.ekrany[sekcja];
  const klucze = Object.keys(ekrany);

  // ── Kompletność listy ──────────────────────────────────────────────────
  t.section('przegląd wypisuje komplet ekranów sekcji');

  const pusty = panel({}, sekcja);
  const bezZasiewu = wiersze(pusty);

  /* Kontrola pozytywna: bez niej wszystkie sprawdzenia niżej przechodziłyby
     na zielono nie mając czego sprawdzać. */
  t.check('lista ma tyle wierszy, ile sekcja ma ekranów',
    bezZasiewu.length === klucze.length,
    bezZasiewu.length + ' wierszy wobec ' + klucze.length + ' ekranów');

  /* Nie „tyle samo sztuk", tylko TE ekrany — i po nazwie widocznej na ekranie,
     nie po samym adresie. */
  const brakujace = klucze.filter((sub) =>
    !bezZasiewu.some((w) => w.sub === sub && w.html.includes(ekrany[sub].label)));
  t.check('i każdy z nich po nazwie', !brakujace.length,
    brakujace.join(', ') || 'komplet');

  /* EKRAN, KTÓREGO NIE DA SIĘ WŁĄCZYĆ, TEŻ MA BYĆ NA LIŚCIE. Lista jest spisem
     sekcji; gdyby pokazywała wyłącznie przełączalne, do reszty trzeba by szukać
     innej drogi. We Frontendzie takiego ekranu dziś nie ma, więc sprawdzamy to,
     co jest sprawdzalne: liczba wierszy nie zależy od liczby przełączników. */
  const zPrzelacznikami = klucze.filter((sub) => (mapa.przelaczniki[sekcja][sub] || []).length);
  t.check('wierszy jest więcej niż samych przełączalnych ekranów, albo tyle samo',
    bezZasiewu.length >= zPrzelacznikami.length,
    zPrzelacznikami.length + ' przełączalnych z ' + bezZasiewu.length);

  const zlyAdres = bezZasiewu.filter((w) => !w.sub || !ekrany[w.sub]);
  t.check('każdy wiersz prowadzi do istniejącego ekranu', !zlyAdres.length,
    zlyAdres.map((w) => w.sub).join(', ') || 'komplet');

  // ── Stany przełączników ────────────────────────────────────────────────
  t.section('stan przełącznika odpowiada opcji w bazie');

  const pojedyncze = klucze.filter((sub) => (mapa.przelaczniki[sekcja][sub] || []).length === 1);

  t.check('bez zasiewu żaden przełącznik nie jest włączony',
    !bezZasiewu.some(zaznaczony),
    bezZasiewu.filter(zaznaczony).map((w) => w.sub).join(', ') || 'wszystkie wyłączone');

  /* Każda opcja z osobna: zasiewamy JĄ JEDNĄ i żądamy, żeby zapalił się dokładnie
     jeden przełącznik i żeby był to ten właściwy. Nazwa użyta w dwóch wierszach
     zapala wtedy dwa, a przełącznik zaznaczony na stałe — wszystkie.

     ZASIEW IDZIE Z TEJ SAMEJ MAPY, którą sprawdzamy, więc samo to nie dowodzi,
     że nazwa opcji jest prawdziwa: zmierzone mutacją — literówka `evk_paralax`
     przechodzi tu na zielono, bo test zasiewa dokładnie tę literówkę. Prawdziwość
     nazwy rozstrzygają dwa sprawdzenia niżej: zgodność z ekranem modułu i biała
     lista uchwytu AJAX. */
  const zle = [];
  for (const sub of pojedyncze) {
    const [option, field] = mapa.przelaczniki[sekcja][sub][0];
    const on = wiersze(panel({ [option]: 1 }, sekcja)).filter(zaznaczony).map((w) => w.sub);

    if (on.length !== 1 || on[0] !== sub) {
      zle.push(sub + ' (' + option + '/' + field + ') → ' + (on.join(', ') || 'nic'));
    }
  }
  t.check('każda opcja zapala swój i tylko swój wiersz', !zle.length,
    zle.join(' | ') || pojedyncze.length + ' z ' + pojedyncze.length);

  /* PRZEGLĄD MA PRZEŁĄCZAĆ TO, CO EKRAN MODUŁU — a nie cokolwiek, co przejdzie
     przez białą listę. Bez tego wiersz „Tryb ciemny" wpięty w `evk_smtp`
     przechodził wszystko powyżej: opcja istnieje, jest na liście uchwytu, zapala
     się jeden wiersz i wygląda to poprawnie, tylko przełącza cudzy moduł.
     Zmierzone mutacją, zanim to sprawdzenie powstało.

     Drugim spisem jest PLIK EKRANU, wybierany tą samą regułą, co w routerze
     zakładki (`tab-{klucz}.php`) — nie lista przepisana w teście. */
  const rozjazd = [];
  let zPliku = 0;
  for (const sub of pojedyncze) {
    const plik = path.join(__dirname, '..', 'includes', 'admin', 'tab-' + sub + '.php');
    if (!fs.existsSync(plik)) continue;

    const wModule = paryZnacznika(fs.readFileSync(plik, 'utf8'));
    if (!wModule.length) continue;
    zPliku++;

    const [option, field] = mapa.przelaczniki[sekcja][sub][0];
    if (!wModule.includes(option + '/' + field)) {
      rozjazd.push(sub + ': przegląd ' + option + '/' + field + ', moduł ' + wModule.join(', '));
    }
  }

  /* Kontrola pozytywna: gdyby żaden plik nie dał się odczytać, sprawdzenie niżej
     byłoby puste. Ekran z włącznikiem tylko na przeglądzie (np. formularzowy
     301) ma tu zapalić i wymusić świadomą decyzję, a nie przejść po cichu. */
  t.check('każdy przełączalny ekran deklaruje przełącznik także u siebie',
    zPliku === pojedyncze.length, zPliku + ' z ' + pojedyncze.length + ' plików ekranów');

  t.check('i przegląd przełącza dokładnie tę opcję, co ekran modułu', !rozjazd.length,
    rozjazd.join(' | ') || zPliku + ' zgodnych');

  /* Odwrotna strona: wyłączona opcja ma gasić przełącznik. Bez tego „zawsze
     zaznaczony" przeszedłby wszystko powyżej. */
  const pierwszy = pojedyncze[0];
  const [opcjaP] = mapa.przelaczniki[sekcja][pierwszy][0];
  const zgaszony = wiersz(panel({ [opcjaP]: 0 }, sekcja), pierwszy);
  t.check('a opcja ustawiona na zero go gasi', !zaznaczony(zgaszony),
    pierwszy + ' = ' + (zaznaczony(zgaszony) ? 'włączony' : 'wyłączony'));

  // ── Granica bezpieczeństwa ─────────────────────────────────────────────
  t.section('każdy przełącznik przeglądu da się naprawdę włączyć');

  /* Przełącznik jedzie wspólnym uchwytem AJAX z białą listą opcji. Opcja spoza
     listy odbija się komunikatem `not_allowed`, ale PANEL RYSUJE SIĘ POPRAWNIE
     i przełącznik po prostu nie działa — nie widać tego inaczej niż z użycia
     (Offcanvas Menu 1.57.0, Sierotki 1.147.0). Przegląd wypisuje te pary drugi
     raz, obok ekranów modułów, więc jest drugim miejscem, gdzie ta dziura może
     się otworzyć.
     Że uchwyt naprawdę ODRZUCA obce pary, dowodzi kontrola negatywna
     w tests/admin-tabs.test.js — bez niej „komplet przechodzi" byłoby prawdą
     także dla uchwytu, który nie sprawdza niczego. */
  const pary = paryZnacznika(listaPrzegladu(pusty)).map((p) => p.split('/'));

  t.check('jest co sprawdzać — przegląd wypisuje przełączniki',
    pary.length === pojedyncze.length && pary.length > 0,
    pary.length + ' przełączników wobec ' + pojedyncze.length + ' ekranów z jedną opcją');

  const odp = JSON.parse(phpOutput('toggle.php', JSON.stringify(JSON.stringify(pary)))).result;
  const odrzucone = Object.keys(odp).filter((k) => odp[k] !== 'ok').map((k) => k + ' → ' + odp[k]);
  t.check('żaden nie odbija się od uchwytu AJAX', !odrzucone.length,
    odrzucone.join(' | ') || pary.length + ' par, komplet przechodzi');

  // ── Ekrany z wieloma przełącznikami ────────────────────────────────────
  t.section('ekran z wieloma włącznikami pokazuje licznik, nie przełącznik');

  const wielokrotne = klucze.filter((sub) => (mapa.przelaczniki[sekcja][sub] || []).length > 1);
  t.check('jest co sprawdzać — sekcja ma taki ekran', wielokrotne.length > 0,
    wielokrotne.join(', ') || 'brak');

  /* Jeden przełącznik na kilka niezależnych opcji musiałby zgadywać, co znaczy
     „włącz wszystko", a przy wyłączeniu gubiłby informację, które z nich były
     włączone. Wiersz mówi więc liczbę i prowadzi na ekran po resztę. */
  const zPrzelacznikiem = wielokrotne.filter((sub) => wiersz(pusty, sub).html.includes('data-option'));
  t.check('taki wiersz nie ma własnego przełącznika', !zPrzelacznikiem.length,
    zPrzelacznikiem.join(', ') || wielokrotne.join(', ') + ' — same liczniki');

  for (const sub of wielokrotne) {
    const wszystkie = mapa.przelaczniki[sekcja][sub];
    const wiersz0 = wiersz(pusty, sub);
    t.check('„' + ekrany[sub].label + '" bez zasiewu to zero z ' + wszystkie.length,
      new RegExp('0 z ' + wszystkie.length + ' włączonych').test(wiersz0.html),
      (wiersz0.html.match(/\d+ z \d+ włączonych/) || ['brak licznika'])[0]);

    /* Mianownik z rejestru, nie wpisany: przy dołożonym elemencie Bricksa ma
       urosnąć sam. Licznik sprawdzamy z zasiewem dwóch pierwszych opcji. */
    const dwie = wszystkie.slice(0, 2);
    const zasiew = {};
    for (const [option, field] of dwie) {
      if (field === '_scalar') zasiew[option] = 1;
      else (zasiew[option] = zasiew[option] || {})[field] = 1;
    }
    const wiersz2 = wiersz(panel(zasiew, sekcja), sub);
    t.check('a z dwiema włączonymi — dwa z ' + wszystkie.length,
      new RegExp('2 z ' + wszystkie.length + ' włączonych').test(wiersz2.html),
      (wiersz2.html.match(/\d+ z \d+ włączonych/) || ['brak licznika'])[0]);
  }

  // ── Plakietka w nagłówku ───────────────────────────────────────────────
  t.section('plakietka sekcji liczy to samo, co lista pod nią');

  const plakietka = (html) => (html.match(/evo-content-status"><span><\/span>\s*([^<]+)</) || [, ''])[1].trim();

  /* MIANOWNIKIEM SĄ EKRANY Z JEDNYM WŁĄCZNIKIEM. Frontend ma 12 ekranów, ale
     dwa z nich mają pod sobą po kilka niezależnych opcji i własne liczniki
     w wierszu — wrzucenie ich do zbiorczej liczby dawałoby „13 z 12". */
  t.check('bez włączonych modułów plakietka pokazuje zero',
    plakietka(pusty) === '0 z ' + pojedyncze.length + ' włączonych',
    plakietka(pusty));

  const [o1] = mapa.przelaczniki[sekcja][pojedyncze[0]][0];
  const [o2] = mapa.przelaczniki[sekcja][pojedyncze[1]][0];
  const dwaModuly = panel({ [o1]: 1, [o2]: 1 }, sekcja);
  t.check('dwa włączone moduły to dwa na plakietce',
    plakietka(dwaModuly) === '2 z ' + pojedyncze.length + ' włączonych',
    plakietka(dwaModuly));

  /* Liczba na plakietce i liczba zaznaczonych przełączników pod nią to dwie
     drogi do tej samej prawdy. Rozjazd oznacza, że któraś kłamie. */
  t.check('i tyle samo zaznaczonych przełączników na liście',
    wiersze(dwaModuly).filter(zaznaczony).length === 2,
    wiersze(dwaModuly).filter(zaznaczony).length + ' zaznaczonych');

  // ── Nawigacja ──────────────────────────────────────────────────────────
  t.section('przegląd jest domyślnym widokiem sekcji, ale nie zabiera starych adresów');

  t.check('pasek boczny ma pozycję „Przegląd"',
    /evo-sidebar-sublink[^"]*"[^>]*>Przegląd</.test(pusty), 'jest');
  t.check('i jest ona zaznaczona, gdy w adresie nie ma ?sub=',
    /evo-sidebar-sublink is-active">Przegląd</.test(pusty),
    (pusty.match(/evo-sidebar-sublink is-active">([^<]*)/) || [, 'brak'])[1]);

  /* Zmiana dotyczy WYŁĄCZNIE samego `?tab=`. Adres z `?sub=` ma dalej prowadzić
     prosto na moduł — inaczej po cichu psujemy każdy zapisany odsyłacz. */
  const naModule = panel({}, sekcja, klucze[0]);
  t.check('adres z ?sub= nadal otwiera moduł, nie przegląd',
    !wiersze(naModule).length,
    wiersze(naModule).length + ' wierszy przeglądu');
  t.check('a w pasku zaznaczony jest ten moduł',
    new RegExp('evo-sidebar-sublink is-active">' + ekrany[klucze[0]].label + '<').test(naModule),
    (naModule.match(/evo-sidebar-sublink is-active">([^<]*)/) || [, 'brak'])[1]);

  /* Paleta zna każdy ekran panelu — przegląd jest osiągalny adresem, więc ma
     w niej być. Wyjątek od tej zasady zaczyna się od jednego wyjątku. */
  t.check('wyszukiwarka zna przegląd',
    pusty.includes(mapa.zakladki[sekcja].label + ' / Przegląd'),
    mapa.zakladki[sekcja].label + ' / Przegląd');

  // ── Sekcje bez przeglądu ───────────────────────────────────────────────
  t.section('pozostałe sekcje zachowują się jak dotąd');

  /* Przegląd jest próbą kształtu na jednej sekcji. Sprawdzenie pilnuje, żeby
     nie rozlał się na resztę niezauważenie — i żeby lista sekcji z przeglądem
     była jedynym miejscem, które o tym decyduje. */
  const inna = Object.keys(mapa.ekrany).find((k) => !mapa.przeglad.includes(k));
  const innaHtml = panel({}, inna);

  t.check('sekcja spoza listy nie dostaje przeglądu', !wiersze(innaHtml).length,
    inna + ': ' + wiersze(innaHtml).length + ' wierszy');
  t.check('i ma dalej plakietkę z liczbą ekranów',
    plakietka(innaHtml) === Object.keys(mapa.ekrany[inna]).length + ' ekranów',
    inna + ': ' + plakietka(innaHtml));
  t.check('ani pozycji „Przegląd" w pasku',
    !/evo-sidebar-sublink[^"]*"[^>]*>Przegląd</.test(innaHtml), 'brak');
};
