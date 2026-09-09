/**
 * Moduł Schema — wyjście JSON-LD.
 *
 * SIATKA REGRESYJNA NA STANIE ZASTANYM, spisana przed przebudową zakładki.
 * Do 1.161.1 grafu nie sprawdzało NIC: w 51 plikach testowych nie występowało
 * ani `@graph`, ani `application/ld+json`. Pokryte było wyłącznie renderowanie
 * zakładki (`admin-tabs`), czyli formularz — a nie to, co moduł wystawia
 * światu w <head> każdej podstrony.
 *
 * DWIE WARSTWY, bo każda łapie co innego:
 *
 * 1. PLIKI WZORCOWE (`fixtures/schema/*.json`) — cały graf, węzeł po węźle.
 *    Zapalają na KAŻDĄ różnicę, także taką, której nie przewidziałem pisząc
 *    ten plik. To jest sens siatki przed przebudową: mam się dowiedzieć, że
 *    ruszyłem coś, czego ruszać nie chciałem. Świadomą zmianę zatwierdzam
 *    ręcznie:  EVK_ZAPISZ_WZORCE=1 node tests/run.js schema
 *    — i wtedy PATRZĘ na `git diff`, bo to jedyny moment, w którym różnica
 *    przechodzi bez pytania.
 *
 * 2. SPRAWDZENIA PISANE WPROST — niezmienniki, które mają obowiązywać także
 *    po przebudowie: unikalność @id, rozwiązywalność wskazań, numeracja encji
 *    podrzędnych, rozdział #organization / #place. Plik wzorcowy powie „coś
 *    się zmieniło"; te sprawdzenia mówią, CO było zamierzone.
 *
 * Sprawdzenia oznaczone STAN ZASTANY opisują znane usterki, nie życzenia.
 * Są zielone, dopóki usterka trwa — po naprawie zapalą i wtedy się je
 * odwraca. Bez nich naprawa przechodzi niezauważona i nikt nie dopisuje
 * sprawdzenia na to, co naprawił.
 */

const fs   = require('fs');
const path = require('path');
const { phpOutput } = require('./lib/harness');

const KATALOG_WZORCOW = path.join(__dirname, 'fixtures', 'schema');
const ZAPISUJEMY = !!process.env.EVK_ZAPISZ_WZORCE;

/** Surowe wyjście `render_graph()` dla scenariusza. */
const wyjscie = (scenariusz) => phpOutput('schema-graf.php', scenariusz);

/** Graf ze scenariusza — obiekt albo null, gdy moduł nic nie wypisał. */
function graf(scenariusz) {
  const m = wyjscie(scenariusz).match(
    /<script type="application\/ld\+json">([\s\S]*?)<\/script>/);
  return m ? JSON.parse(m[1]) : null;
}

/*
 * Wartości zależne od DNIA uruchomienia. `priceValidUntil` liczy się jako
 * „dziś + rok", więc wzorzec zapisany dosłownie byłby czerwony nazajutrz —
 * i to nie z powodu wtyczki. Podmieniamy je na znacznik, a samą regułę
 * sprawdzamy osobno, niżej.
 */
function bezDat(wartosc) {
  return JSON.parse(JSON.stringify(wartosc), (klucz, v) =>
    klucz === 'priceValidUntil' ? '<DZIS-PLUS-ROK>' : v);
}

/** Wszystkie @id w grafie, w kolejności wystąpienia (z powtórzeniami). */
const identyfikatory = (g) => g['@graph'].map((n) => n['@id']);

/**
 * Wskazania na inne węzły — obiekty, których JEDYNYM kluczem jest `@id`.
 * Właśnie tak graf łączy węzły (`publisher`, `isPartOf`, `containedInPlace`),
 * i właśnie to psuje się po cichu: wskazanie na nieistniejący węzeł jest
 * poprawnym JSON-em, więc nie zauważy go ani parser, ani oko.
 */
function wskazania(g) {
  const out = [];
  const idzie = (o, sciezka) => {
    if (Array.isArray(o)) return o.forEach((v) => idzie(v, sciezka));
    if (!o || typeof o !== 'object') return;
    const klucze = Object.keys(o);
    if (klucze.length === 1 && klucze[0] === '@id') {
      return out.push({ sciezka, cel: o['@id'] });
    }
    for (const k of klucze) {
      if (k !== '@id') idzie(o[k], sciezka + '/' + k);
    }
  };
  g['@graph'].forEach((n) => idzie(
    Object.fromEntries(Object.entries(n).filter(([k]) => k !== '@id')), n['@type']));
  return out;
}

/** Węzeł danego typu, albo undefined. */
const wezel = (g, typ) => g['@graph'].find((n) => n['@type'] === typ);

/** Wszystkie łańcuchy w strukturze — do polowania na puste wartości. */
function lancuchy(o, out = []) {
  if (typeof o === 'string') out.push(o);
  else if (o && typeof o === 'object') Object.values(o).forEach((v) => lancuchy(v, out));
  return out;
}

/* Scenariusze, które NAPRAWDĘ coś drukują. „wylaczony" i „bloki-off" mają
   z założenia milczeć, więc chodzą osobno — wzorzec z pustego wyjścia byłby
   sprawdzeniem, że plik jest pusty. */
const SCENARIUSZE = ['minimalny', 'firma', 'firma-en', 'atrakcja',
                     'podstrona', 'wpis', 'produkt', 'bez-org'];

module.exports = async function (t) {

  const grafy = {};
  for (const s of SCENARIUSZE) grafy[s] = graf(s);

  // ── Warstwa 1: pliki wzorcowe ──────────────────────────────────────────
  t.section('graf zgadza się z plikiem wzorcowym');

  if (ZAPISUJEMY) fs.mkdirSync(KATALOG_WZORCOW, { recursive: true });

  for (const s of SCENARIUSZE) {
    const plik = path.join(KATALOG_WZORCOW, s + '.json');
    const teraz = JSON.stringify(bezDat(grafy[s]), null, 2) + '\n';

    if (ZAPISUJEMY) {
      fs.writeFileSync(plik, teraz);
      t.check('wzorzec zapisany: ' + s, true, 'ZAPIS — obejrzyj git diff');
      continue;
    }

    if (!fs.existsSync(plik)) {
      t.check('wzorzec istnieje: ' + s, false, 'brak ' + path.relative(__dirname, plik));
      continue;
    }

    const wzorzec = fs.readFileSync(plik, 'utf8');
    /* Różnicę pokazujemy PIERWSZĄ NIEZGODNĄ LINIĄ, nie samym „nie zgadza się".
       Graf ma kilkadziesiąt linii i komunikat bez wskazania miejsca zmusza
       do ręcznego porównywania dwóch ekranów JSON-a. */
    const a = wzorzec.split('\n');
    const b = teraz.split('\n');
    const i = a.findIndex((linia, n) => linia !== b[n]);
    t.check('wzorzec zgodny: ' + s, wzorzec === teraz,
      wzorzec === teraz ? b.length - 1 + ' linii'
        : 'linia ' + (i + 1) + ': wzorzec ' + JSON.stringify(a[i] || '(koniec)') +
          ', teraz ' + JSON.stringify(b[i] || '(koniec)'));
  }

  // ── Kontrole negatywne ─────────────────────────────────────────────────
  t.section('kiedy graf NIE ma się pojawić');

  /* Bez tych dwóch sprawdzeń cała reszta pliku przechodzi także wtedy, gdy
     moduł drukuje graf ZAWSZE — a to znaczy cudze dane w cudzym <head>
     mimo wyłączonego przełącznika. */
  const pusty = wyjscie('wylaczony');
  t.check('moduł wyłączony nie drukuje niczego', pusty.trim() === '',
    pusty.trim() === '' ? 'zero bajtów' : JSON.stringify(pusty.slice(0, 60)));

  const bezBlokow = wyjscie('bloki-off');
  t.check('wszystkie bloki odhaczone — brak znacznika, nie pusty @graph',
    !/ld\+json/.test(bezBlokow),
    /ld\+json/.test(bezBlokow) ? 'wyszedł znacznik' : 'zero bajtów');

  /* Kontrola do kontroli: gdyby harness mylił scenariusze albo `render_graph`
     milczał zawsze, oba sprawdzenia wyżej byłyby zielone bez powodu. */
  t.check('a scenariusz włączony drukuje — kontrola do kontroli',
    /ld\+json/.test(wyjscie('minimalny')));

  // ── Warstwa 2: niezmienniki grafu ──────────────────────────────────────
  t.section('@id: unikalne i rozwiązywalne');

  for (const s of SCENARIUSZE) {
    const ids = identyfikatory(grafy[s]);
    const powtorzone = ids.filter((id, i) => ids.indexOf(id) !== i);
    t.check('@id bez powtórzeń: ' + s, !powtorzone.length,
      powtorzone.length ? powtorzone.join(', ') : ids.length + ' węzłów');
  }

  /* Wskazania muszą trafiać w istniejący węzeł. `bez-org` jest wyjęty
     ŚWIADOMIE — ma znaną usterkę, opisaną niżej własnym sprawdzeniem. */
  for (const s of SCENARIUSZE.filter((x) => x !== 'bez-org')) {
    const ids = new Set(identyfikatory(grafy[s]));
    const wiszace = wskazania(grafy[s]).filter((w) => !ids.has(w.cel));
    t.check('wskazania trafiają w węzeł: ' + s, !wiszace.length,
      wiszace.length ? wiszace.map((w) => w.sciezka + ' → ' + w.cel).join('; ')
                     : wskazania(grafy[s]).length + ' wskazań');
  }

  t.section('rozdział #organization / #place');

  /* Cała zakładka stoi na tej zasadzie: wydawca strony to zawsze czysta
     Organization, a typ działalności tworzy OSOBNY węzeł miejsca. Zlanie
     ich w jeden jest najbardziej prawdopodobnym skutkiem przebudowy. */
  const org = wezel(grafy.firma, 'Organization');
  const place = wezel(grafy.firma, 'LodgingBusiness');
  t.check('typ działalności robi osobny węzeł #place', !!place,
    place ? place['@id'] : 'brak węzła');
  t.check('a #organization zostaje czystą Organization', !!org && org['@type'] === 'Organization',
    org && org['@type']);
  t.check('nazwa operatora idzie na #organization', org?.name === 'Fundacja Wypoczynek', org?.name);
  t.check('nazwa obiektu idzie na #place', place?.name === 'Ośrodek Nad Jeziorem', place?.name);
  t.check('#place wskazuje wydawcę przez parentOrganization',
    !!org && place?.parentOrganization?.['@id'] === org['@id'],
    place?.parentOrganization?.['@id']);

  /* Kontrola negatywna: przy typie „Organizacja" węzła miejsca NIE MA.
     Bez niej „#place powstaje" przechodzi też wtedy, gdy powstaje zawsze. */
  t.check('przy typie Organizacja nie ma węzła #place',
    !identyfikatory(grafy.minimalny).some((id) => /#place$/.test(id)),
    identyfikatory(grafy.minimalny).join(' '));

  /* Pola firmy lokalnej mają siedzieć na #place, a nie na wydawcy. */
  for (const pole of ['geo', 'priceRange', 'amenityFeature', 'openingHoursSpecification', 'hasMap', 'areaServed']) {
    t.check('„' + pole + '" na #place, nie na #organization',
      place?.[pole] !== undefined && org?.[pole] === undefined,
      place?.[pole] === undefined ? 'brak na #place' : '');
  }

  t.section('pusta konfiguracja nie drukuje śmieci');

  /* Świeża instalacja z włączonym modułem. Pusty łańcuch albo `null`
     w JSON-LD to nie kosmetyka — Google czyta je jako zadeklarowaną
     wartość pustą, a nie jako brak deklaracji. */
  const puste = lancuchy(grafy.minimalny).filter((s) => s.trim() === '');
  t.check('żadnej pustej wartości w grafie', !puste.length, puste.length + ' pustych');
  t.check('żadnego null', !/null/.test(JSON.stringify(grafy.minimalny)));
  t.check('tylko WebSite i Organization', grafy.minimalny['@graph'].length === 2,
    grafy.minimalny['@graph'].map((n) => n['@type']).join(', '));
  t.check('@context na miejscu', grafy.minimalny['@context'] === 'https://schema.org');

  t.section('encje podrzędne');

  /* Repeater dostaje pięć wierszy, z czego dwa są do odrzucenia: typ spoza
     `sub_entity_types()` i wiersz z samymi spacjami w nazwie. Numeracja @id
     ma je POMINĄĆ — dziura w numeracji (#entity-1, #entity-3) znaczyłaby,
     że licznik idzie po wejściu, a nie po wyjściu. */
  const podrzedne = grafy.atrakcja['@graph'].filter((n) => /#entity-\d+$/.test(n['@id']));
  t.check('trzy wiersze z pięciu przechodzą', podrzedne.length === 3,
    podrzedne.map((n) => n['@type']).join(', '));
  t.check('numeracja ciągła, bez dziur po odrzuconych',
    podrzedne.map((n) => n['@id'].replace(/.*#entity-/, '')).join(',') === '1,2,3',
    podrzedne.map((n) => n['@id'].replace(/.*#/, '')).join(' '));
  t.check('odrzucony typ spoza listy (Service)',
    !podrzedne.some((n) => n['@type'] === 'Service'));
  t.check('odrzucony wiersz bez nazwy (Playground)',
    !podrzedne.some((n) => n['@type'] === 'Playground'));
  t.check('każda encja wisi na #place',
    podrzedne.length > 0 &&
    podrzedne.every((n) => n.containedInPlace?.['@id'] === 'https://example.test/#place'),
    podrzedne.map((n) => n.containedInPlace?.['@id'] ?? 'BRAK').join(' '));
  t.check('atrakcja też wisi na #place',
    wezel(grafy.atrakcja, 'TouristAttraction')?.containedInPlace?.['@id'] === 'https://example.test/#place');
  t.check('własna nazwa atrakcji wygrywa z nazwą obiektu',
    wezel(grafy.atrakcja, 'TouristAttraction')?.name === 'Półwysep nad jeziorem');

  t.section('godziny otwarcia');

  /* Trzy zapisy naraz: zakres („Pn-Pt"), lista („Sob, Nd") i „Codziennie".
     Parser ma 60 linii i mapę skrótów na dwa języki — jeden przykład
     sprawdzałby wyłącznie ten jeden przypadek. */
  const godziny = place?.openingHoursSpecification ?? [];
  t.check('trzy reguły z trzech linii', godziny.length === 3, godziny.length + '');
  t.check('zakres Pn-Pt to pięć dni roboczych',
    godziny[0]?.dayOfWeek.join(',') === 'Monday,Tuesday,Wednesday,Thursday,Friday',
    godziny[0]?.dayOfWeek.join(','));
  t.check('lista „Sob, Nd" to weekend',
    godziny[1]?.dayOfWeek.join(',') === 'Saturday,Sunday', godziny[1]?.dayOfWeek.join(','));
  t.check('„Codziennie" to siedem dni', godziny[2]?.dayOfWeek.length === 7, godziny[2]?.dayOfWeek.length);
  t.check('godziny dopełnione do dwóch cyfr',
    godziny[0]?.opens === '08:00' && godziny[0]?.closes === '20:00',
    godziny[0]?.opens + '–' + godziny[0]?.closes);

  t.section('udogodnienia, obszar, adres');

  t.check('pusta linia w udogodnieniach nie robi pustej pozycji',
    place?.amenityFeature?.length === 2,
    place?.amenityFeature?.map((a) => a.name).join(', '));
  t.check('udogodnienie ma kształt LocationFeatureSpecification',
    place?.amenityFeature?.[0]['@type'] === 'LocationFeatureSpecification' &&
    place?.amenityFeature?.[0].value === true);
  t.check('obszar obsługiwany to lista łańcuchów',
    Array.isArray(place?.areaServed) && place.areaServed.join(',') === 'Mazury,Warmia');
  t.check('adres relatywny logo dostaje adres witryny z przodu',
    org?.logo?.url === 'https://example.test/wp-content/uploads/logo.png', org?.logo?.url);
  t.check('kontakt niesie języki z modułu Tłumaczeń',
    org?.contactPoint?.availableLanguage.join(',') === 'Polish,English,German',
    org?.contactPoint?.availableLanguage.join(','));
  t.check('własny contactType wygrywa z domyślnym',
    org?.contactPoint?.contactType === 'reservations', org?.contactPoint?.contactType);

  /* Adres pojawia się tylko wtedy, gdy jest z czego go zbudować. */
  t.check('bez ulicy i miejscowości nie ma węzła adresu',
    wezel(grafy.minimalny, 'Organization')?.address === undefined);

  t.section('język zmienia adres bazowy i opis');

  /* Wersja językowa przesuwa CAŁY graf pod /en/ — łącznie z @id, po których
     Google skleja encje między podstronami. Pomyłka tutaj rozspaja witrynę
     na dwie niepowiązane organizacje. */
  t.check('@id organizacji siedzi pod adresem języka',
    wezel(grafy['firma-en'], 'Organization')?.['@id'] === 'https://example.test/en/#organization',
    wezel(grafy['firma-en'], 'Organization')?.['@id']);
  t.check('opis wzięty z wersji angielskiej',
    wezel(grafy['firma-en'], 'Organization')?.description === 'English description');
  t.check('a polski scenariusz ma polski opis — kontrola',
    org?.description === 'Opis po polsku');
  t.check('brak tłumaczenia opisu schodzi na polski',
    wezel(grafy.atrakcja, 'Organization')?.description === 'Opis po polsku');

  t.section('podstrona i wpis');

  const okruszki = wezel(grafy.podstrona, 'BreadcrumbList');
  t.check('okruszki niosą rodzica strony',
    okruszki?.itemListElement.map((i) => i.name).join(' › ') === 'Witryna testowa › Oferta › Domki',
    okruszki?.itemListElement.map((i) => i.name).join(' › '));
  t.check('pozycje numerowane od jedynki, po kolei',
    okruszki?.itemListElement.map((i) => i.position).join(',') === '1,2,3');

  const strona = wezel(grafy.podstrona, 'WebPage');
  t.check('tytuł strony z zakładki SEO, nie z tytułu wpisu',
    strona?.name === 'Domki nad jeziorem — oferta', strona?.name);
  t.check('opis strony z zakładki SEO', strona?.description === 'Opis z zakładki SEO');
  t.check('obraz strony trafia do primaryImageOfPage',
    strona?.primaryImageOfPage?.url === 'https://example.test/img/domki.jpg');

  /* Kontrola: wpis NIE MA meta SEO, więc te same pola muszą zejść niżej
     w łańcuchu źródeł. Bez tego sprawdzenia „czyta z SEO" przechodzi też
     wtedy, gdy czyta WYŁĄCZNIE z SEO i nie ma czym się ratować. */
  t.check('bez meta SEO tytuł schodzi na tytuł wpisu',
    wezel(grafy.wpis, 'WebPage')?.name === 'Sezon otwarty',
    wezel(grafy.wpis, 'WebPage')?.name);
  t.check('a opis na opis witryny',
    wezel(grafy.wpis, 'WebPage')?.description === 'Opis z ustawień WordPressa');

  const wpis = wezel(grafy.wpis, 'BlogPosting');
  t.check('wpis wskazuje swoją stronę przez isPartOf',
    !!wpis && wpis.isPartOf['@id'] === wezel(grafy.wpis, 'WebPage')?.['@id']);
  t.check('autor jako osobny węzeł Person z @id',
    wpis?.author['@type'] === 'Person' && /#author$/.test(wpis.author['@id']));
  t.check('kategorie sklejone w keywords',
    wpis?.keywords === 'Aktualności, Wydarzenia', wpis?.keywords);
  t.check('daty publikacji i modyfikacji różne',
    !!wpis && wpis.datePublished !== wpis.dateModified);
  t.check('strona bez wpisu nie dostaje BlogPosting — kontrola',
    !wezel(grafy.podstrona, 'BlogPosting'));

  t.section('produkt WooCommerce');

  const produkt = wezel(grafy.produkt, 'Product');
  t.check('waluta z mapy per język, nie z Woo',
    produkt?.offers?.priceCurrency === 'EUR', produkt?.offers?.priceCurrency);
  t.check('dostępność jako URL schema.org',
    produkt?.offers?.availability === 'https://schema.org/InStock',
    produkt?.offers?.availability);
  t.check('opis krótki wygrywa z długim',
    produkt?.description === 'Wypożyczenie na dobę');

  /* Data ważności ceny liczy się od DNIA renderu, więc w pliku wzorcowym
     stoi znacznik. Regułę sprawdzamy tutaj — inaczej nie sprawdza jej nic. */
  const zaRok = new Date();
  zaRok.setUTCFullYear(zaRok.getUTCFullYear() + 1);
  t.check('priceValidUntil to dokładnie rok od dziś',
    produkt?.offers?.priceValidUntil === zaRok.toISOString().slice(0, 10),
    produkt?.offers?.priceValidUntil);

  t.check('podstrona bez produktu nie dostaje węzła Product — kontrola',
    !wezel(grafy.podstrona, 'Product'));

  // ── Stan zastany: znane usterki ────────────────────────────────────────
  t.section('STAN ZASTANY — usterki do naprawy, zapisane żeby naprawa zapaliła');

  /* USTERKA 1. `extract_faq()` szuka akordeonu przez array_walk_recursive
     z warunkiem `is_array($value)`. array_walk_recursive NIE PODAJE tablic
     do callbacka — wchodzi w nie i podaje wyłącznie liście. Warunek nie może
     być prawdziwy nigdy, więc blok FAQPage — włączony domyślnie i opisany
     w panelu jako „FAQPage (Bricks accordion)" — nie wyemitował ani jednego
     węzła, odkąd istnieje.

     Scenariusz `wpis` NIESIE poprawny akordeon w meta Bricksa i ma
     `block_faq` włączony, więc gdy usterka zniknie, to sprawdzenie zapali. */
  t.check('FAQPage nie powstaje mimo akordeonu w meta (usterka)',
    !wezel(grafy.wpis, 'FAQPage'),
    'array_walk_recursive nie podaje tablic — 90-schema.php:546');

  /* USTERKA 2. Przy odhaczonym bloku Organization `WebSite.publisher` dalej
     wskazuje na #organization, którego w grafie nie ma. Poprawny JSON,
     wskazanie donikąd — nie zauważy tego ani parser, ani oko. */
  const idsBezOrg = new Set(identyfikatory(grafy['bez-org']));
  const wiszaceBezOrg = wskazania(grafy['bez-org']).filter((w) => !idsBezOrg.has(w.cel));
  t.check('publisher wisi, gdy blok Organization odhaczony (usterka)',
    wiszaceBezOrg.length === 1 &&
    wiszaceBezOrg[0].sciezka === 'WebSite/publisher',
    wiszaceBezOrg.map((w) => w.sciezka + ' → ' + w.cel).join('; ') || 'brak — naprawione?');
};
