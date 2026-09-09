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
 * Wszystko chodzi po WSZYSTKICH sekcjach z przeglądu, a listę sekcji podaje
 * wtyczka. Sprawdzenie napisane pod jedną sekcję przestałoby cokolwiek znaczyć
 * dla czterech dołożonych później — a dokładnie to się wydarzyło między
 * 1.163.0 a 1.164.0.
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

const zaznaczony = (w) => !!w && /<input[^>]*\bchecked/.test(w.html);

/** Wiersz o danym kluczu; nigdy `undefined`, żeby zepsuty render dał wynik, a nie wyjątek. */
const wiersz = (html, sub) => wiersze(html).find((w) => w.sub === sub) || { sub, html: '' };

/** Pary „opcja/pole" wypisane w znaczniku — oba szyki atrybutów, jak w admin-tabs. */
const paryZnacznika = (html) => {
  const out = [];
  for (const m of html.matchAll(/data-option="([^"]+)"[^>]*?data-field="([^"]+)"/g)) out.push(m[1] + '/' + m[2]);
  for (const m of html.matchAll(/data-field="([^"]+)"[^>]*?data-option="([^"]+)"/g)) out.push(m[2] + '/' + m[1]);
  return out;
};

/* Plik ekranu modułu — tymi samymi drogami, którymi dobierają go routery
   zakładek: `tab-{klucz}.php`, `security-{klucz}.php`, `tools-{klucz}.php`,
   `seo/tab-{klucz}.php`, `other-{klucz}.php`, `admin-{klucz}.php`. Lista
   prefiksów, nie mapa „ekran → plik": mapa byłaby trzecim spisem do
   utrzymywania obok mapy ekranów i samych routerów. */
const PREFIKSY = ['tab-', 'security-', 'tools-', 'seo/tab-', 'other-', 'admin-'];

const plikEkranu = (sub) => {
  for (const prefiks of PREFIKSY) {
    const p = path.join(__dirname, '..', 'includes', 'admin', prefiks + sub + '.php');
    if (fs.existsSync(p)) return p;
  }
  return null;
};

/** Zasiew jednej pary „opcja/pole" w kształcie, jakiego oczekuje panel-start.php. */
const zasiewPary = (zasiew, option, field) => {
  if (field === '_scalar') zasiew[option] = 1;
  else (zasiew[option] = zasiew[option] || {})[field] = 1;
  return zasiew;
};

module.exports = async function (t) {

  const mapa = JSON.parse(phpOutput('panel-start.php', '--mapa'));
  const sekcje = mapa.przeglad;

  const pary1 = (s, sub) => (mapa.przelaczniki[s][sub] || []);
  const pojedyncze = (s) => Object.keys(mapa.ekrany[s]).filter((sub) => pary1(s, sub).length === 1);
  const wielokrotne = (s) => Object.keys(mapa.ekrany[s]).filter((sub) => pary1(s, sub).length > 1);
  const bezOpcji = (s) => Object.keys(mapa.ekrany[s]).filter((sub) => pary1(s, sub).length === 0);

  // ── Kompletność listy ──────────────────────────────────────────────────
  t.section('przegląd wypisuje komplet ekranów każdej sekcji');

  /* Kontrola pozytywna: bez niej wszystkie sprawdzenia niżej przechodziłyby
     na zielono nie mając czego sprawdzać. Liczba z mapy, nie wpisana. */
  t.check('jest co sprawdzać — sekcji z przeglądem jest tyle, ile ekranowych zakładek',
    sekcje.length === Object.keys(mapa.ekrany).length && sekcje.length > 1,
    sekcje.length + ' sekcji: ' + sekcje.join(', '));

  const puste = {};
  for (const s of sekcje) puste[s] = panel({}, s);

  const zleLiczby = sekcje.filter((s) => wiersze(puste[s]).length !== Object.keys(mapa.ekrany[s]).length);
  t.check('każda sekcja ma tyle wierszy, ile ekranów', !zleLiczby.length,
    zleLiczby.map((s) => s + ': ' + wiersze(puste[s]).length + ' z ' + Object.keys(mapa.ekrany[s]).length).join(', ')
      || sekcje.map((s) => wiersze(puste[s]).length).join(' + ') + ' wierszy');

  const brakujace = [];
  const zlyAdres = [];
  for (const s of sekcje) {
    for (const sub of Object.keys(mapa.ekrany[s])) {
      const w = wiersz(puste[s], sub);
      if (!w.html.includes(mapa.ekrany[s][sub].label)) brakujace.push(s + '/' + sub);
    }
    for (const w of wiersze(puste[s])) if (!w.sub || !mapa.ekrany[s][w.sub]) zlyAdres.push(s + '/' + w.sub);
  }
  t.check('i każdy ekran po nazwie', !brakujace.length, brakujace.join(', ') || 'komplet');
  t.check('każdy wiersz prowadzi do istniejącego ekranu', !zlyAdres.length,
    zlyAdres.join(', ') || 'komplet');

  /* KAŻDY OPIS JEST NA MIEJSCU. Wiersz bez opisu to sama nazwa modułu, a nazwy
     w rodzaju „Sierotki" czy „Kokpit" nic nie mówią komuś, kto wchodzi tu
     pierwszy raz — po to opis wszedł do mapy. */
  const bezOpisu = [];
  for (const s of sekcje) {
    for (const [sub, ekran] of Object.entries(mapa.ekrany[s])) {
      if (!ekran.opis) bezOpisu.push(s + '/' + sub);
      else if (!wiersz(puste[s], sub).html.includes(ekran.opis)) bezOpisu.push(s + '/' + sub + ' (nie na ekranie)');
    }
  }
  t.check('każdy ekran ma opis i widać go w wierszu', !bezOpisu.length,
    bezOpisu.join(', ') || 'komplet');

  // ── Trzy kształty wiersza ──────────────────────────────────────────────
  t.section('kształt wiersza wynika z liczby opcji, nie z gałęzi per ekran');

  /* Ekran, którego nie da się włączyć, TEŻ jest na liście — lista ma być spisem
     sekcji. Ma być odsyłaczem: ani przełącznika, ani licznika. */
  const zNadmiarem = [];
  for (const s of sekcje) {
    for (const sub of bezOpcji(s)) {
      const h = wiersz(puste[s], sub).html;
      if (h.includes('data-option') || h.includes('evo-przeglad-licznik')) zNadmiarem.push(s + '/' + sub);
    }
  }
  const ileBezOpcji = sekcje.reduce((n, s) => n + bezOpcji(s).length, 0);
  t.check('jest co sprawdzać — są ekrany bez czego włączać', ileBezOpcji > 0,
    ileBezOpcji + ' ekranów');
  t.check('ekran bez opcji jest samym odsyłaczem', !zNadmiarem.length,
    zNadmiarem.join(', ') || ileBezOpcji + ' odsyłaczy');

  /* Jeden przełącznik na kilka niezależnych opcji musiałby zgadywać, co znaczy
     „włącz wszystko", a przy wyłączeniu gubiłby informację, które były włączone.
     Wiersz mówi więc liczbę i prowadzi na ekran po resztę. */
  const zPrzelacznikiem = [];
  for (const s of sekcje) {
    for (const sub of wielokrotne(s)) {
      if (wiersz(puste[s], sub).html.includes('data-option')) zPrzelacznikiem.push(s + '/' + sub);
    }
  }
  const ileWielu = sekcje.reduce((n, s) => n + wielokrotne(s).length, 0);
  t.check('jest co sprawdzać — są ekrany z wieloma opcjami', ileWielu > 0, ileWielu + ' ekranów');
  t.check('ekran z wieloma opcjami ma licznik, nie przełącznik', !zPrzelacznikiem.length,
    zPrzelacznikiem.join(', ') || ileWielu + ' liczników');

  // ── Liczniki ───────────────────────────────────────────────────────────
  t.section('licznik mówi prawdę o liczbie włączonych');

  const zleLiczniki = [];
  for (const s of sekcje) {
    for (const sub of wielokrotne(s)) {
      const wszystkie = pary1(s, sub);

      const zero = wiersz(puste[s], sub).html;
      if (!new RegExp('0 z ' + wszystkie.length + ' włączonych').test(zero)) {
        zleLiczniki.push(s + '/' + sub + ' pusty: ' + (zero.match(/\d+ z \d+ włączonych/) || ['brak'])[0]);
        continue;
      }

      /* Mianownik z mapy, licznik z zasiewu dwóch pierwszych opcji — przy
         dołożonym elemencie Bricksa ma urosnąć sam. */
      const zasiew = {};
      for (const [o, f] of wszystkie.slice(0, 2)) zasiewPary(zasiew, o, f);
      const dwie = wiersz(panel(zasiew, s), sub).html;
      if (!new RegExp('2 z ' + wszystkie.length + ' włączonych').test(dwie)) {
        zleLiczniki.push(s + '/' + sub + ' z dwiema: ' + (dwie.match(/\d+ z \d+ włączonych/) || ['brak'])[0]);
      }
    }
  }
  t.check('każdy licznik liczy od zera i rośnie z zasiewem', !zleLiczniki.length,
    zleLiczniki.join(' | ') || ileWielu + ' liczników');

  // ── Stany przełączników ────────────────────────────────────────────────
  t.section('stan przełącznika odpowiada opcji w bazie');

  const zapalone = [];
  for (const s of sekcje) {
    const on = wiersze(puste[s]).filter(zaznaczony).map((w) => s + '/' + w.sub);
    zapalone.push(...on);
  }
  t.check('bez zasiewu żaden przełącznik nie jest włączony', !zapalone.length,
    zapalone.join(', ') || 'wszystkie wyłączone');

  /* Każda opcja z osobna: zasiewamy JĄ JEDNĄ i żądamy, żeby w tej sekcji zapalił
     się dokładnie jeden przełącznik i żeby był to ten właściwy. Nazwa użyta
     w dwóch wierszach zapala wtedy dwa, a przełącznik zaznaczony na stałe —
     wszystkie.

     ZASIEW IDZIE Z TEJ SAMEJ MAPY, którą sprawdzamy, więc samo to nie dowodzi,
     że nazwa opcji jest prawdziwa: zmierzone mutacją — literówka `evk_paralax`
     przechodzi tu na zielono, bo test zasiewa dokładnie tę literówkę. Prawdziwość
     nazwy rozstrzygają dwa sprawdzenia niżej: zgodność z ekranem modułu i biała
     lista uchwytu AJAX. */
  const zle = [];
  let ilePojedynczych = 0;
  for (const s of sekcje) {
    for (const sub of pojedyncze(s)) {
      ilePojedynczych++;
      const [option, field] = pary1(s, sub)[0];
      const on = wiersze(panel(zasiewPary({}, option, field), s)).filter(zaznaczony).map((w) => w.sub);
      if (on.length !== 1 || on[0] !== sub) {
        zle.push(s + '/' + sub + ' (' + option + '/' + field + ') → ' + (on.join(', ') || 'nic'));
      }
    }
  }
  t.check('każda opcja zapala swój i tylko swój wiersz', !zle.length,
    zle.join(' | ') || ilePojedynczych + ' z ' + ilePojedynczych);

  /* Odwrotna strona: wyłączona opcja ma gasić przełącznik. Bez tego „zawsze
     zaznaczony" przeszedłby wszystko powyżej. */
  const [sPierwsza] = sekcje;
  const subPierwszy = pojedyncze(sPierwsza)[0];
  const [opcjaP, poleP] = pary1(sPierwsza, subPierwszy)[0];
  const zasiewZero = poleP === '_scalar' ? { [opcjaP]: 0 } : { [opcjaP]: { [poleP]: 0 } };
  const zgaszony = wiersz(panel(zasiewZero, sPierwsza), subPierwszy);
  t.check('a opcja ustawiona na zero go gasi', !zaznaczony(zgaszony),
    subPierwszy + ' = ' + (zaznaczony(zgaszony) ? 'włączony' : 'wyłączony'));

  // ── Zgodność z ekranem modułu ──────────────────────────────────────────
  t.section('przegląd przełącza to samo, co ekran modułu');

  /* PRZEGLĄD MA PRZEŁĄCZAĆ TO, CO EKRAN MODUŁU — a nie cokolwiek, co przejdzie
     przez białą listę. Bez tego wiersz „Tryb ciemny" wpięty w `evk_smtp`
     przechodził wszystko powyżej: opcja istnieje, jest na liście uchwytu, zapala
     się jeden wiersz i wygląda to poprawnie, tylko przełącza cudzy moduł.
     Zmierzone mutacją, zanim to sprawdzenie powstało.

     Drugim spisem jest PLIK EKRANU, dobierany tymi samymi prefiksami, co
     w routerach zakładek — nie lista przepisana w teście. */
  const rozjazd = [];
  const bezWlasnego = [];
  let zgodnych = 0;

  for (const s of sekcje) {
    for (const sub of pojedyncze(s)) {
      const plik = plikEkranu(sub);
      const wModule = plik ? paryZnacznika(fs.readFileSync(plik, 'utf8')) : [];
      const [option, field] = pary1(s, sub)[0];

      if (!wModule.length) { bezWlasnego.push(s + '/' + sub); continue; }

      if (wModule.includes(option + '/' + field)) zgodnych++;
      else rozjazd.push(s + '/' + sub + ': przegląd ' + option + '/' + field + ', moduł ' + wModule.join(', '));
    }
  }

  t.check('i robi to zgodnie z ekranem modułu', !rozjazd.length,
    rozjazd.join(' | ') || zgodnych + ' zgodnych');

  /* WYJĄTEK MUSI BYĆ POLICZONY. Przekierowania 301 i Logi 404 mają na własnych
     ekranach włącznik jadący submitem — AJAX i POST razem strzelały podwójnie —
     więc ich pary są tylko na przeglądzie. Sprawdzenie porównuje listę
     znalezioną z listą ZADEKLAROWANĄ we wtyczce w obie strony: trzeci taki ekran
     nie pojawi się po cichu, a wpis, który przestał być wyjątkiem, nie zostanie
     na liście na zawsze. */
  const zadeklarowane = [...mapa.wyjatki].sort().join(', ');
  t.check('a ekran z włącznikiem tylko na przeglądzie jest zadeklarowany',
    bezWlasnego.sort().join(', ') === zadeklarowane,
    'znalezione: ' + (bezWlasnego.join(', ') || 'brak') + ' | zadeklarowane: ' + (zadeklarowane || 'brak'));

  t.check('jest co porównywać — większość ekranów ma własny przełącznik',
    zgodnych >= ilePojedynczych - mapa.wyjatki.length,
    zgodnych + ' z ' + ilePojedynczych + ' przełączalnych');

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
  const pary = [];
  for (const s of sekcje) for (const p of paryZnacznika(listaPrzegladu(puste[s]))) pary.push(p.split('/'));

  t.check('jest co sprawdzać — przegląd wypisuje przełączniki',
    pary.length === ilePojedynczych && pary.length > 0,
    pary.length + ' przełączników wobec ' + ilePojedynczych + ' ekranów z jedną opcją');

  const odp = JSON.parse(phpOutput('toggle.php', JSON.stringify(JSON.stringify(pary)))).result;
  const odrzucone = Object.keys(odp).filter((k) => odp[k] !== 'ok').map((k) => k + ' → ' + odp[k]);
  t.check('żaden nie odbija się od uchwytu AJAX', !odrzucone.length,
    odrzucone.join(' | ') || pary.length + ' par, komplet przechodzi');

  // ── Plakietka w nagłówku ───────────────────────────────────────────────
  t.section('plakietka sekcji liczy to samo, co lista pod nią');

  const plakietka = (html) => (html.match(/evo-content-status"><span><\/span>\s*([^<]+)</) || [, ''])[1].trim();

  /* MIANOWNIKIEM SĄ EKRANY Z JEDNYM WŁĄCZNIKIEM, nie wszystkie ekrany sekcji:
     ekrany z kilkoma opcjami mają własne liczniki w wierszu, a ekranów bez opcji
     nie da się włączyć wcale. Inaczej Frontend pokazywałby „13 z 12". */
  const zlePlakietki = [];
  for (const s of sekcje) {
    const m = pojedyncze(s).length;
    if (plakietka(puste[s]) !== '0 z ' + m + ' włączonych') {
      zlePlakietki.push(s + ': ' + plakietka(puste[s]) + ', oczekiwane 0 z ' + m);
    }
  }
  t.check('bez włączonych modułów każda plakietka pokazuje zero', !zlePlakietki.length,
    zlePlakietki.join(' | ') || sekcje.map((s) => pojedyncze(s).length).join(' + ') + ' modułów);'.replace(');', ')'));

  /* Liczba na plakietce i liczba zaznaczonych przełączników pod nią to dwie
     drogi do tej samej prawdy. Rozjazd oznacza, że któraś kłamie. */
  const zRozjazdem = [];
  for (const s of sekcje) {
    if (pojedyncze(s).length < 2) continue;
    const zasiew = {};
    for (const sub of pojedyncze(s).slice(0, 2)) zasiewPary(zasiew, ...pary1(s, sub)[0]);
    const html = panel(zasiew, s);
    const naLiscie = wiersze(html).filter(zaznaczony).length;
    if (plakietka(html) !== '2 z ' + pojedyncze(s).length + ' włączonych' || naLiscie !== 2) {
      zRozjazdem.push(s + ': plakietka „' + plakietka(html) + '", na liście ' + naLiscie);
    }
  }
  t.check('dwa włączone moduły to dwa na plakietce i dwa na liście', !zRozjazdem.length,
    zRozjazdem.join(' | ') || 'zgodne w każdej sekcji');

  // ── Nawigacja ──────────────────────────────────────────────────────────
  t.section('przegląd jest domyślnym widokiem sekcji, ale nie zabiera starych adresów');

  const bezPozycji = sekcje.filter((s) => !/evo-sidebar-sublink is-active">Przegląd</.test(puste[s]));
  t.check('pasek boczny ma zaznaczoną pozycję „Przegląd" w każdej sekcji', !bezPozycji.length,
    bezPozycji.join(', ') || sekcje.length + ' sekcji');

  /* Zmiana dotyczy WYŁĄCZNIE samego `?tab=`. Adres z `?sub=` ma dalej prowadzić
     prosto na moduł — inaczej po cichu psujemy każdy zapisany odsyłacz. */
  const zjedzone = [];
  for (const s of sekcje) {
    const pierwszy = Object.keys(mapa.ekrany[s])[0];
    const html = panel({}, s, pierwszy);
    if (wiersze(html).length) zjedzone.push(s + '/' + pierwszy);
    else if (!new RegExp('evo-sidebar-sublink is-active">' + mapa.ekrany[s][pierwszy].label + '<').test(html)) {
      zjedzone.push(s + '/' + pierwszy + ' (pasek nie zaznacza modułu)');
    }
  }
  t.check('adres z ?sub= nadal otwiera moduł i zaznacza go w pasku', !zjedzone.length,
    zjedzone.join(', ') || sekcje.length + ' sekcji');

  /* Paleta zna każdy ekran panelu — przegląd jest osiągalny adresem, więc ma
     w niej być. Wyjątek od tej zasady zaczyna się od jednego wyjątku. */
  const bezWPalecie = sekcje.filter((s) => !puste[s].includes(mapa.zakladki[s].label + ' / Przegląd'));
  t.check('wyszukiwarka zna przegląd każdej sekcji', !bezWPalecie.length,
    bezWPalecie.join(', ') || sekcje.length + ' wpisów');

  // ── Potwierdzenie przed włączeniem ─────────────────────────────────────
  t.section('przełącznik, który kosztuje widoczność strony, pyta przed włączeniem');

  /* Konserwacja wyłącza stronę dla gości, a na liście stoi w rzędzie
     identycznych przełączników — pomyłka jest o jedno kliknięcie. Sprawdzamy to
     w PRZEGLĄDARCE, prawdziwym `admin.js`: sam atrybut w znaczniku dowodziłby
     tylko tego, że go wypisaliśmy. */
  const zPotwierdzeniem = [];
  for (const s of sekcje) {
    for (const [sub, ekran] of Object.entries(mapa.ekrany[s])) {
      if (ekran.potwierdzenie) zPotwierdzeniem.push([s, sub, ekran.potwierdzenie]);
    }
  }
  t.check('jest co sprawdzać — jakiś ekran deklaruje potwierdzenie',
    zPotwierdzeniem.length > 0, zPotwierdzeniem.map((x) => x[0] + '/' + x[1]).join(', ') || 'brak');

  for (const [s, sub, tekst] of zPotwierdzeniem) {
    const [option, field] = pary1(s, sub)[0];
    const inne = pojedyncze(s).find((x) => x !== sub && !mapa.ekrany[s][x].potwierdzenie);

    const strona = await t.open('panel-start.html', {
      viewport: { width: 1400, height: 1000 },
      head: 'window.evkToggle = { url: "about:blank", nonce: "x" };'
          + 'window.__pytania = []; window.__zadania = []; window.__odpowiedz = false;'
          + 'window.confirm = function (tekst) { window.__pytania.push(tekst); return window.__odpowiedz; };'
          + '(function () { var o = XMLHttpRequest.prototype.open;'
          + '  XMLHttpRequest.prototype.open = function (m, u) { window.__zadania.push(m + " " + u); return o.apply(this, arguments); };'
          + '}());'
          + 'window.__panel = ' + JSON.stringify(puste[s]) + ';',
    });

    const suwak = (opcja) => 'label.evo-toggle:has(input[data-option="' + opcja + '"]) .evo-slider';

    // ODMOWA: pyta, nie wysyła, nie zostaje włączony.
    await strona.click(suwak(option));
    const poOdmowie = await strona.evaluate((sel) => ({
      pytania: window.__pytania.slice(),
      zadania: window.__zadania.length,
      zaznaczony: document.querySelector(sel).checked,
    }), 'input[data-option="' + option + '"]');

    t.check('„' + mapa.ekrany[s][sub].label + '" pyta przed włączeniem',
      poOdmowie.pytania.length === 1 && poOdmowie.pytania[0] === tekst,
      poOdmowie.pytania[0] ? poOdmowie.pytania[0].slice(0, 60) : 'nie zapytał');
    t.check('odmowa nie wysyła żądania', poOdmowie.zadania === 0,
      poOdmowie.zadania + ' żądań');
    t.check('i nie zostawia go włączonego', !poOdmowie.zaznaczony,
      poOdmowie.zaznaczony ? 'włączony' : 'wyłączony');

    // ZGODA: pyta drugi raz i tym razem wysyła.
    await strona.evaluate(() => { window.__odpowiedz = true; });
    await strona.click(suwak(option));
    const poZgodzie = await strona.evaluate(() => ({
      pytania: window.__pytania.length, zadania: window.__zadania.length,
    }));
    t.check('a zgoda dopuszcza zapis',
      poZgodzie.pytania === 2 && poZgodzie.zadania === 1,
      poZgodzie.pytania + ' pytań, ' + poZgodzie.zadania + ' żądań');

    /* KONTROLA NEGATYWNA. Bez niej „pyta" byłoby prawdą także dla kodu, który
       pyta przy KAŻDYM przełączniku — a wtedy potwierdzenie przestaje cokolwiek
       znaczyć i zaczyna przeszkadzać. */
    if (inne) {
      const [innaOpcja] = pary1(s, inne)[0];
      await strona.click(suwak(innaOpcja));
      const poInnym = await strona.evaluate(() => window.__pytania.length);
      t.check('a zwykły przełącznik („' + mapa.ekrany[s][inne].label + '") nie pyta',
        poInnym === 2, poInnym - 2 + ' dodatkowych pytań');
    }

    await strona.close();
  }
};
