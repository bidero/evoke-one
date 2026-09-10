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

/**
 * Węzeł po sufiksie `@id` — po scaleniu w 1.175.0 typ węzła firmy zależy od
 * konfiguracji (`Organization` albo typ działalności), więc szukanie po typie
 * przestało być niezawodne. `@id` jest stały.
 */
const wezelId = (g, sufiks) => g['@graph'].find((n) => n['@id'].endsWith(sufiks));

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
                     'podstrona', 'wpis', 'produkt', 'bez-org', 'faq-off',
                     'organizacja-pelna', 'miejsce-pelne', 'trojstan-intem', 'agencja', 'scalenie-kolizje', 'hotel', 'restauracja', 'gabinet', 'wyciek-presetu',
                     'nadpisanie-wpisu', 'nadpisanie-puste', 'bez-okruszkow',
                     'filtr-ustawien', 'edytor', 'edytor-atak'];

/** Scalone ustawienia scenariusza (warstwy: domyślne → globalne → meta wpisu). */
const ustawienia = (scenariusz) =>
  JSON.parse(phpOutput('schema-graf.php', scenariusz + ' --ustawienia'));

/** Rejestr pól — pytamy o niego kod, zamiast trzymać drugą kopię listy. */
const rejestr = () => JSON.parse(phpOutput('schema-graf.php', 'minimalny --pola'));

/** Rejestr presetów branżowych — jw. */
const presety = () => JSON.parse(phpOutput('schema-graf.php', 'minimalny --presety'));

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

  /* Wskazania muszą trafiać w istniejący węzeł — we WSZYSTKICH scenariuszach.
     Do 1.168.0 `bez-org` był stąd wyjęty, bo miał dwa wskazania donikąd;
     po naprawie wyjątek zniknął i to on jest dowodem, że naprawa objęła
     oba miejsca, a nie jedno. */
  for (const s of SCENARIUSZE) {
    const ids = new Set(identyfikatory(grafy[s]));
    const wiszace = wskazania(grafy[s]).filter((w) => !ids.has(w.cel));
    t.check('wskazania trafiają w węzeł: ' + s, !wiszace.length,
      wiszace.length ? wiszace.map((w) => w.sciezka + ' → ' + w.cel).join('; ')
                     : wskazania(grafy[s]).length + ' wskazań');
  }

  t.section('jeden węzeł czy dwa — decyduje nazwa operatora');

  /* ZGŁOSZONE Z UŻYCIA po wypełnieniu prawdziwych danych: „redundancja
     i pętla w parentOrganization — ProfessionalService wskazuje jako
     rodzica tę samą firmę".

     Do 1.175.0 typ działalności ZAWSZE tworzył osobny węzeł #place, a
     #organization zostawał czystą Organization. Przy jednej firmie dawało
     to dwa węzły o tej samej nazwie, z których jeden wskazywał drugi jako
     rodzica. To nie była struktura, tylko powtórzenie.

     Teraz decyduje pole „Nazwa operatora": wypełnione inną nazwą → dwa
     węzły (sieć hoteli i jeden hotel to realnie dwie encje); puste albo
     takie samo → jeden węzeł typu działalności. Poprawne, bo LocalBusiness
     i pochodne dziedziczą i z `Organization`, i z `Place`. */
  const ag = grafy.agencja['@graph'];
  t.check('bez osobnego operatora graf ma JEDEN węzeł firmy, nie dwa',
    ag.filter((n) => /#(organization|place)$/.test(n['@id'])).length === 1,
    ag.map((n) => n['@type']).join(', '));
  t.check('i jest nim #organization typu działalności',
    wezel(grafy.agencja, 'ProfessionalService')?.['@id'] === 'https://example.test/#organization');
  t.check('bez parentOrganization wskazującego samego siebie',
    !('parentOrganization' in (wezel(grafy.agencja, 'ProfessionalService') || {})));
  t.check('scalony węzeł niesie dane obiektu, nie tylko firmy',
    !!wezel(grafy.agencja, 'ProfessionalService')?.openingHoursSpecification);

  /* Kontrola dodatnia: przy ODRĘBNYM operatorze węzły dalej są dwa
     i `parentOrganization` ma sens. Bez niej „jeden węzeł" przechodzi też
     wtedy, gdyby rozdział zniknął na dobre. */
  t.check('z osobnym operatorem węzły zostają dwa',
    !!wezel(grafy.firma, 'Organization') && !!wezel(grafy.firma, 'LodgingBusiness'));
  t.check('i wtedy parentOrganization wskazuje INNĄ firmę',
    wezel(grafy.firma, 'LodgingBusiness')?.parentOrganization?.['@id']
      === wezel(grafy.firma, 'Organization')?.['@id'] &&
    wezel(grafy.firma, 'Organization')?.name !== wezel(grafy.firma, 'LodgingBusiness')?.name,
    wezel(grafy.firma, 'Organization')?.name + ' ≠ ' + wezel(grafy.firma, 'LodgingBusiness')?.name);

  /* Po scaleniu wskazania encji podrzędnych i atrakcji idą na #organization —
     i dalej trafiają w węzeł, który JEST miejscem. Ogólne sprawdzenie
     rozwiązywalności (wyżej) obejmuje to dla wszystkich scenariuszy; tutaj
     pytamy wprost, żeby powód był widoczny w nazwie. */
  t.check('encje podrzędne wiszą na scalonym węźle',
    grafy.atrakcja['@graph'].filter((n) => /#entity-/.test(n['@id']))
      .every((n) => n.containedInPlace['@id'] === 'https://example.test/#organization'));

  /* Cztery kolizje przy scalaniu, wszystkie z mutacji, które przechodziły
     na zielono, bo żaden scenariusz nie wchodził w te ścieżki. */
  const kol = wezelId(grafy['scalenie-kolizje'], '#organization');

  t.check('operator wpisany TĄ SAMĄ nazwą znaczy „jedna firma"',
    kol?.['@type'] === 'LegalService' &&
    grafy['scalenie-kolizje']['@graph'].filter((n) => /#place$/.test(n['@id'])).length === 0,
    kol?.['@type']);

  /* Obszar firmy i obszar obiektu mapują się na TĘ SAMĄ właściwość.
     Przy scaleniu jedna lista mogłaby cicho zniknąć — sumujemy je,
     bez powtórzeń. */
  t.check('areaServed sumuje obie listy',
    kol?.areaServed?.length === 3, JSON.stringify(kol?.areaServed));
  t.check('i nie powtarza pozycji wspólnej dla obu',
    kol?.areaServed?.filter((x) => x === 'mazowieckie').length === 1);

  /* `faxNumber` jest pojedyncze — wygrywa numer FIRMY, bo to jej węzeł;
     numer obiektu wchodzi tylko wtedy, gdy firmowego nie podano. */
  t.check('faks firmy wygrywa z faksem obiektu',
    kol?.faxNumber === '+48 11 111 11 11', kol?.faxNumber);

  /* `about` powstaje tylko na WebPage, więc tylko podstrona pokazuje,
     dokąd wskazuje po scaleniu. */
  t.check('about podstrony wskazuje scalony węzeł, nie nieistniejące #place',
    wezel(grafy['scalenie-kolizje'], 'WebPage')?.about?.['@id']
      === 'https://example.test/#organization',
    wezel(grafy['scalenie-kolizje'], 'WebPage')?.about?.['@id']);

  t.section('kształty zakwestionowane w analizie z użycia — stan faktyczny');

  /* Analiza wyjścia żywej strony zgłosiła cztery rzeczy, których moduł NIE
     robi w źródle.

     SPROSTOWANIE (1.176.0): pisało tu wcześniej, że to najpewniej cudza
     wtyczka drukująca własny blok. Nieprawda — to NASZE wyjście, czytane
     w postaci ROZWINIĘTEJ. Walidatory rozwiązują wskazania `@id`, wklejając
     wskazywany węzeł w każde miejsce, w którym się pojawia, i sprowadzają
     wartości wyliczeń do pełnych adresów:

       "dayOfWeek": ["Monday"]        → http://schema.org/Monday
       "addressCountry": "PL"         → {"@type":"Country","name":"PL"}
       "query-input": "required …"    → węzeł PropertyValueSpecification
       "@context": "https://schema.org" → rozwiązany, niewidoczny
       "publisher": {"@id": "…"}      → cały węzeł organizacji, wklejony

     Stąd też wrażenie, że dane firmy lecą dwa razy: `publisher` w WebSite
     i `about` w WebPage to w źródle po jednej linijce ze wskazaniem.

     Sprawdzenia niżej mówią o ŹRÓDLE i dlatego zostają: gdyby ktoś kiedyś
     „poprawił" moduł tak, żeby emitował te kształty dosłownie, byłaby to
     zmiana na gorsze — dosłowny `Country` i pełne adresy dni są dopuszczalne,
     ale dłuższe i niepotrzebne, a wklejony węzeł zamiast `@id` to już
     prawdziwa duplikacja. */

  t.check('SearchAction siedzi na WebSite, zgodnie z dokumentacją Google',
    !!wezel(grafy.agencja, 'WebSite')?.potentialAction);
  t.check('i NIE ma go na WebPage',
    grafy['scalenie-kolizje']['@graph']
      .every((n) => n['@type'] !== 'WebPage' || !('potentialAction' in n)));

  t.check('addressCountry to łańcuch ISO, nie węzeł Country',
    typeof wezelId(grafy.agencja, '#organization')?.address?.addressCountry === 'string',
    JSON.stringify(wezelId(grafy.agencja, '#organization')?.address?.addressCountry));

  const dni = wezelId(grafy.agencja, '#organization')?.openingHoursSpecification?.[0]?.dayOfWeek;
  t.check('dayOfWeek to skróty tekstowe, nie adresy schema.org',
    Array.isArray(dni) && dni.every((d) => /^[A-Z][a-z]+$/.test(d)),
    JSON.stringify(dni));

  t.check('@context jest i wskazuje schema.org',
    grafy.agencja['@context'] === 'https://schema.org', grafy.agencja['@context']);

  t.section('edytor węzłów — furtka na resztę schema.org');

  const ed  = grafy.edytor;
  const edO = wezelId(ed, '#organization');
  const edW = wezelId(ed, '#website');

  t.check('właściwość spoza pól panelu wchodzi do węzła',
    edO?.slogan === 'Odpoczywaj u nas', edO?.slogan);

  /* Bez parsowania JSON-a edytor umiałby dopisać wyłącznie płaskie łańcuchy,
     a połowa schema.org to węzły zagnieżdżone. */
  t.check('wartość w JSON wchodzi jako STRUKTURA, nie jako tekst',
    edO?.numberOfEmployees?.['@type'] === 'QuantitativeValue' &&
    edO?.numberOfEmployees?.value === 12,
    JSON.stringify(edO?.numberOfEmployees));
  t.check('tablica w JSON też',
    Array.isArray(edW?.keywords) && edW.keywords.length === 2,
    JSON.stringify(edW?.keywords));

  /* Tekst, który tylko WYGLĄDA jak JSON, ma zostać tekstem. Gdyby zamiast
     tego wchodził jako `null` albo znikał, pomyłka byłaby niewidoczna. */
  t.check('niedomknięty JSON zostaje tekstem, nie znika',
    edO?.award === '{niedomknięty', JSON.stringify(edO?.award));

  /* Edytor wygrywa z tym, co moduł wyliczył sam — inaczej nie dałoby się
     poprawić niczego, co moduł podaje źle, a to jest cały sens furtki. */
  t.check('edytor nadpisuje wartość wyliczoną przez moduł',
    edO?.telephone === '+48 999 888 777', edO?.telephone);
  t.check('a punkt kontaktowy zachowuje swój numer — nadpisanie sięga JEDNEGO klucza',
    edO?.contactPoint?.telephone === '+48 111 222 333', edO?.contactPoint?.telephone);

  t.check('pusta wartość USUWA właściwość z węzła',
    !('description' in (edW || {})), JSON.stringify(edW?.description));
  t.check('a w scenariuszu bez usuwania opis jest — kontrola',
    typeof wezelId(grafy.firma, '#website')?.description === 'string');

  /* Wybór „obiekt" przy węźle scalonym musi trafić w #organization — ten sam
     `miejsce_id()`, z którego korzystają `about` i `containedInPlace`. */
  t.check('wybór „obiekt" trafia w węzeł scalony',
    edO?.priceRange === '$$', edO?.priceRange);

  t.check('wiersz wskazujący węzeł spoza grafu nie tworzy węzła-widma',
    ed['@graph'].length === 2 &&
    !identyfikatory(ed).some((id) => /#attraction$/.test(id)),
    identyfikatory(ed).join(' '));

  /* KLUCZE, KTÓRE NIE MOGĄ PRZEJŚĆ — wstrzyknięte filtrem, czyli z pominięciem
     `sanitize_settings()`. Warstwa nadpisań per podstrona i cudzy kod wchodzą
     do `get_settings()` tą samą drogą, więc bramka przy zapisie ich nie
     obejmuje i sprawdzenie tamtej nic by o tym nie powiedziało. */
  const atk  = grafy['edytor-atak'];
  const atkO = wezelId(atk, '#organization');

  t.check('kontrola dodatnia: prawidłowy wiersz z tej samej paczki PRZESZEDŁ',
    atkO?.slogan === 'Kontrola', atkO?.slogan);
  t.check('@id węzła nie do podmienienia',
    atkO?.['@id'] === 'https://example.test/#organization', atkO?.['@id']);
  t.check('@type węzła nie do podmienienia',
    atkO?.['@type'] === 'LodgingBusiness', atkO?.['@type']);
  t.check('@context nie do podmienienia',
    atk['@context'] === 'https://schema.org', atk['@context']);
  t.check('klucz ze spacją odrzucony',
    !Object.keys(atkO || {}).some((k) => /\s/.test(k)),
    Object.keys(atkO || {}).join(' '));
  t.check('klucz zaczynający się od cyfry odrzucony',
    !('2mokry' in (atkO || {})));
  t.check('wiersz z nieznanym węzłem nie rusza żadnego węzła',
    !identyfikatory(atk).some((id) => /cudz/.test(id)) &&
    !JSON.stringify(atk).includes('cudze.test'),
    identyfikatory(atk).join(' '));

  t.section('podpowiedzi właściwości nadążają za tym, co moduł emituje');

  /* Lista podpowiedzi jest pisana ręcznie, więc może się zestarzeć — nowe
     pole w panelu emituje właściwość, której w podpowiedziach nie ma, i nikt
     tego nie zauważy, bo brak podpowiedzi niczego nie psuje w grafie.
     Dlatego kontrolą jest PRAWDZIWE WYJŚCIE ze wszystkich scenariuszy. */
  const znane = JSON.parse(phpOutput('schema-graf.php', 'minimalny --wlasciwosci'));
  const wszystkieZnane = new Set([...znane.organization, ...znane.place]);

  for (const [wezelKlucz, sufiks] of [['website', '#website'], ['attraction', '#attraction']]) {
    const emitowane = new Set();
    for (const s of SCENARIUSZE) {
      for (const n of grafy[s]['@graph']) {
        if (!n['@id'].endsWith(sufiks)) continue;
        Object.keys(n).forEach((k) => { if (!k.startsWith('@')) emitowane.add(k); });
      }
    }
    const braki = [...emitowane].filter((k) => !znane[wezelKlucz].includes(k));
    t.check(`podpowiedzi ${wezelKlucz} pokrywają to, co moduł emituje`,
      emitowane.size > 0 && !braki.length, braki.length ? braki.join(', ') : emitowane.size + ' właściwości');
  }

  /* #organization i #place idą razem: po scaleniu (1.175.0) właściwości
     obiektu siedzą na węźle organizacji, więc rozdzielne sprawdzenie
     wymagałoby wiedzy, który scenariusz jest scalony — a to zapisywałoby
     scalanie w sprawdzeniu podpowiedzi, gdzie nie ma czego szukać. */
  const emitFirma = new Set();
  for (const s of SCENARIUSZE) {
    for (const n of grafy[s]['@graph']) {
      if (!/#(organization|place)$/.test(n['@id'])) continue;
      Object.keys(n).forEach((k) => { if (!k.startsWith('@')) emitFirma.add(k); });
    }
  }
  const brakiFirma = [...emitFirma].filter((k) => !wszystkieZnane.has(k));
  t.check('podpowiedzi organizacji i obiektu pokrywają to, co moduł emituje',
    emitFirma.size > 20 && !brakiFirma.length,
    brakiFirma.length ? brakiFirma.join(', ') : emitFirma.size + ' właściwości');

  /* Podpowiedź z `@` byłaby zaproszeniem do wpisania klucza, który i tak
     zostanie odrzucony — a to gorsze niż brak podpowiedzi. */
  for (const [w, lista] of Object.entries(znane)) {
    t.check(`podpowiedzi ${w}: żadnej z @ i wszystkie w kształcie nazwy`,
      lista.length > 0 && lista.every((k) => /^[A-Za-z][A-Za-z0-9_]*$/.test(k)),
      lista.filter((k) => !/^[A-Za-z][A-Za-z0-9_]*$/.test(k)).join(', ') || lista.length + ' nazw');
  }

  t.section('rozdział #organization / #place — przy odrębnym operatorze');

  /* Tryb DWUWĘZŁOWY, czyli scenariusz `firma`: operator (Fundacja) jest inną
     firmą niż obiekt (Ośrodek), więc wydawca zostaje czystą Organization,
     a typ działalności ma własny węzeł. Od 1.175.0 to już nie jest zasada
     całej zakładki — jest jednym z dwóch układów, wybieranym nazwą operatora
     (drugi wyżej). Zlanie tych dwóch węzłów TUTAJ byłoby błędem: dwie firmy
     to dwie encje. */
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
  t.check('i #organization zostaje czystą Organization',
    wezel(grafy.minimalny, 'Organization')?.['@id'] === 'https://example.test/#organization');

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

  /* TO SAMO WE WSZYSTKICH SCENARIUSZACH, nie tylko w pustym.
     `minimalny` nie ma adresu w ogóle, więc przez trzy wydania nie widział,
     że `build_address()` emituje wszystkie cztery klucze niezależnie od
     tego, czy są wypełnione — obiekt bez kodu pocztowego dostawał
     `"postalCode": ""`. Znalezione dopiero przy odczycie z walidatora,
     który puste klucze przy WYŚWIETLANIU ukrywa, więc i tam było
     niewidoczne. Ta pętla zamyka całą klasę: każde pole dopisane
     w przyszłości bez bramki na pustkę wywali sprawdzenie od razu. */
  for (const s of SCENARIUSZE) {
    const p = lancuchy(grafy[s]).filter((x) => x === '');
    t.check(`bez pustych wartości: ${s}`, !p.length, p.length + ' pustych');
  }
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
  /* Scenariusz `atrakcja` nie ma odrębnego operatora, więc od 1.175.0 węzeł
     obiektu JEST węzłem `#organization`. Sprawdzamy wskazanie na ten węzeł,
     nie na dosłowne `#place` — dosłowność zapisywałaby tu tryb dwuwęzłowy
     jako jedyny poprawny. */
  const idMiejsca = wezelId(grafy.atrakcja, '#organization')?.['@id'];
  t.check('każda encja wisi na węźle obiektu',
    podrzedne.length > 0 && !!idMiejsca &&
    podrzedne.every((n) => n.containedInPlace?.['@id'] === idMiejsca),
    podrzedne.map((n) => n.containedInPlace?.['@id'] ?? 'BRAK').join(' '));
  t.check('atrakcja też wisi na węźle obiektu',
    wezel(grafy.atrakcja, 'TouristAttraction')?.containedInPlace?.['@id'] === idMiejsca);
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
    org?.contactPoint?.availableLanguage?.join(',') === 'Polish,English,German',
    org?.contactPoint?.availableLanguage?.join(','));
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
    wezelId(grafy.atrakcja, '#organization')?.description === 'Opis po polsku');

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

  t.section('FAQPage z akordeonu Bricksa');

  /* Do 1.168.0 ten blok nie wyemitował ANI JEDNEGO węzła, odkąd istniał:
     `extract_faq()` pytało `$key === 'items' && is_array($value)` wewnątrz
     `array_walk_recursive`, a ta funkcja nie podaje tablic do callbacka.
     Blok był włączony domyślnie i opisany w panelu jako działający.
     Sprawdzenia niżej pilnują, żeby nie wrócił do tego stanu po cichu. */
  const faq = wezel(grafy.wpis, 'FAQPage');
  t.check('akordeon w meta Bricksa daje węzeł FAQPage', !!faq,
    faq ? faq['@id'] : 'brak węzła');
  t.check('oba pytania z akordeonu, nie jedno', faq?.mainEntity?.length === 2,
    faq?.mainEntity?.map((q) => q.name).join(' | '));
  t.check('pytanie ma kształt Question + acceptedAnswer',
    faq?.mainEntity?.[0]?.['@type'] === 'Question' &&
    faq?.mainEntity?.[0]?.acceptedAnswer?.['@type'] === 'Answer',
    faq?.mainEntity?.[0] && Object.keys(faq.mainEntity[0]).join(','));
  /* Odpowiedź w Bricksie jest HTML-em. Znaczniki w JSON-LD to nie kosmetyka —
     Google czyta wartość dosłownie i pokazuje ją w wynikach wyszukiwania. */
  t.check('HTML odpowiedzi zdjęty do czystego tekstu',
    faq?.mainEntity?.[0]?.acceptedAnswer?.text === 'Tak, bezpłatny.',
    faq?.mainEntity?.[0]?.acceptedAnswer?.text);
  t.check('FAQPage wskazuje swoją stronę przez isPartOf',
    faq?.isPartOf?.['@id'] === wezel(grafy.wpis, 'WebPage')?.['@id'],
    faq?.isPartOf?.['@id']);

  /* Dwie kontrole negatywne. Bez nich „węzeł powstaje" przechodzi także
     wtedy, gdy powstaje zawsze — niezależnie od ustawienia i od treści. */
  t.check('odhaczony blok FAQPage — węzła nie ma mimo akordeonu',
    !wezel(grafy['faq-off'], 'FAQPage'));
  t.check('strona bez akordeonu nie dostaje FAQPage',
    !wezel(grafy.podstrona, 'FAQPage'));

  // ── Rejestr pól ────────────────────────────────────────────────────────
  t.section('organizacja — dane rozszerzone');

  /* Czternaście pól dołożonych w 1.172.0. Zasada nadrzędna wydania: pole
     puste NIE ZOSTAWIA śladu w grafie, więc witryna, która ich nie tknęła,
     dostaje JSON-LD bajt w bajt taki jak przedtem. Pilnuje tego brak zmian
     w plikach wzorcowych wszystkich wcześniejszych scenariuszy — i osobne
     sprawdzenie niżej, bo „wzorce się zgadzają" nie mówi DLACZEGO. */
  const orgP = wezel(grafy['organizacja-pelna'], 'Organization');

  t.check('legalName, alternateName, slogan wychodzą gołym tekstem',
    orgP?.legalName === 'Przykładowa spółka z ograniczoną odpowiedzialnością' &&
    orgP?.alternateName === 'Przykładowa' &&
    orgP?.slogan === 'Chleb od 1998 roku');
  t.check('NIP i REGON na swoich właściwościach',
    orgP?.vatID === 'PL0000000000' && orgP?.taxID === '000000000');
  t.check('data założenia bez przerabiania', orgP?.foundingDate === '1998-04-20',
    orgP?.foundingDate);

  /* Założyciel i marka to WĘZŁY, nie łańcuchy — schema.org oczekuje tam
     Person i Brand, a goły tekst konsumenci potraktują jako nazwę bez typu. */
  t.check('założyciel jako węzeł Person',
    orgP?.founder?.['@type'] === 'Person' && orgP?.founder?.name === 'Anna Przykładowa');
  t.check('marka jako węzeł Brand',
    orgP?.brand?.['@type'] === 'Brand' && orgP?.brand?.name === 'Zakwas Przykładowy');
  t.check('liczba pracowników jako QuantitativeValue z liczbą, nie łańcuchem',
    orgP?.numberOfEmployees?.['@type'] === 'QuantitativeValue' &&
    orgP?.numberOfEmployees?.value === 12,
    JSON.stringify(orgP?.numberOfEmployees));

  /* knowsAbout — pozycja nr 1 z listy zgłaszającego. Jedno pole, dwie
     postacie: adres staje się wskazaniem na encję, reszta tekstem. */
  t.check('knowsAbout ma trzy pozycje', orgP?.knowsAbout?.length === 3,
    JSON.stringify(orgP?.knowsAbout));
  t.check('linia bez adresu zostaje tekstem',
    orgP?.knowsAbout?.[0] === 'wypiek chleba na zakwasie');
  t.check('linia z adresem staje się encją Thing z @id',
    orgP?.knowsAbout?.[1]?.['@type'] === 'Thing' &&
    orgP?.knowsAbout?.[1]?.['@id'] === 'https://pl.wikipedia.org/wiki/Chleb');
  /* Kontrola: tekst PO adresie też ma zostać tekstem. Bez niej przechodzi
     też implementacja, która po pierwszym adresie przełącza się na stałe. */
  t.check('a tekst po adresie dalej jest tekstem',
    orgP?.knowsAbout?.[2] === 'cukiernictwo', JSON.stringify(orgP?.knowsAbout?.[2]));

  t.check('pusta linia w nagrodach nie robi pustej pozycji',
    orgP?.award?.length === 2, JSON.stringify(orgP?.award));
  t.check('obszar firmy osobno od obszaru miejsca',
    orgP?.areaServed?.join(',') === 'Warszawa,mazowieckie', orgP?.areaServed?.join(','));

  /* memberOf: „Nazwa | adres", adres opcjonalny — obie gałęzie w jednym
     scenariuszu, bo tylko razem pokazują, że kreska jest opcjonalna,
     a nie wymagana. */
  t.check('członkostwo z adresem ma nazwę i url',
    orgP?.memberOf?.[0]?.name === 'Izba Rzemieślnicza' &&
    orgP?.memberOf?.[0]?.url === 'https://izba.example.test');
  t.check('członkostwo bez adresu ma samą nazwę, bez pustego url',
    orgP?.memberOf?.[1]?.name === 'Cech Piekarzy' &&
    orgP?.memberOf?.[1]?.url === undefined,
    JSON.stringify(orgP?.memberOf?.[1]));

  /* SEDNO WYDANIA: puste pole nie zostawia śladu. Scenariusz `firma` nie tknął
     ani jednego z czternastu nowych pól i jego węzeł Organization ma dokładnie
     tyle właściwości, co przed 1.172.0. */
  const NOWE = ['legalName', 'alternateName', 'slogan', 'foundingDate', 'founder',
                'numberOfEmployees', 'vatID', 'taxID', 'brand', 'faxNumber',
                'knowsAbout', 'award', 'memberOf'];
  const przecieki = NOWE.filter((k) => k in (wezel(grafy.firma, 'Organization') || {}));
  t.check('niewypełnione pola nie zostawiają śladu w grafie', !przecieki.length,
    przecieki.join(', ') || NOWE.length + ' pól cicho');

  // ── Miejsce, atrakcja, encje (wydanie 4) ───────────────────────────────
  t.section('miejsce: pola wspólne dla wszystkich branż');

  const mp = wezel(grafy['miejsce-pelne'], 'Resort');

  /* TRÓJSTAN. `smokingAllowed: false` znaczy „u nas się nie pali" i jest
     deklaracją; brak właściwości znaczy „nie mówimy". Checkbox tych dwóch
     rzeczy nie odróżniał, więc te pola mają select o trzech stanach —
     i scenariusz używa wszystkich trzech naraz. */
  t.check('trójstan: TAK wychodzi jako true', mp?.publicAccess === true);
  t.check('trójstan: NIE wychodzi jako false, a nie jako brak',
    mp?.smokingAllowed === false, JSON.stringify(mp?.smokingAllowed));
  t.check('trójstan: „nie podano" nie wychodzi wcale',
    !('isAccessibleForFree' in (mp || {})),
    JSON.stringify(mp?.isAccessibleForFree));

  t.check('waluty i formy płatności', mp?.currenciesAccepted === 'PLN' &&
    mp?.paymentAccepted === 'Gotówka, Karta, BLIK', mp?.paymentAccepted);
  t.check('numer oddziału i pojemność',
    mp?.branchCode === 'MIK-01' && mp?.maximumAttendeeCapacity === 120,
    JSON.stringify(mp?.maximumAttendeeCapacity));
  t.check('zdjęcia jako węzły ImageObject, nie gołe adresy',
    mp?.photo?.length === 2 && mp?.photo?.[0]['@type'] === 'ImageObject');
  t.check('faks miejsca osobno od faksu organizacji',
    mp?.faxNumber === '+48 00 000 00 00');

  /* Wartość zapisana INT-em, nie łańcuchem — tak wygląda opcja po checkboxie
     sprzed 1.174.0 albo po imporcie ustawień. Ścisłe porównanie do łańcucha
     gubiłoby ją CICHO: klient widzi w panelu „nie podano" i nie wie, czemu
     jego ustawienie zniknęło. Mutacja „trójstan gubi wartość zapisaną int-em"
     przechodziła na zielono, zanim ten scenariusz powstał. */
  const ti = wezel(grafy['trojstan-intem'], 'Hotel');
  t.check('int 1 czytany jako TAK', ti?.petsAllowed === true && ti?.publicAccess === true,
    JSON.stringify([ti?.petsAllowed, ti?.publicAccess]));
  t.check('int 0 czytany jako NIE, a nie jako „nie podano"',
    ti?.smokingAllowed === false, JSON.stringify(ti?.smokingAllowed));

  t.section('godziny świąteczne (specialOpeningHoursSpecification)');

  /* Trzy postacie reguły naraz: dzień zamknięty, ZAKRES dni zamkniętych
     i dzień o skróconych godzinach. Jedna postać sprawdzałaby wyłącznie
     tę jedną gałąź parsera. */
  const sw = mp?.specialOpeningHoursSpecification || [];
  t.check('trzy reguły z trzech linii', sw.length === 3, sw.length + '');
  t.check('dzień zamknięty to opens=closes=00:00 — konwencja Google',
    sw[0]?.opens === '00:00' && sw[0]?.closes === '00:00' &&
    sw[0]?.validFrom === '2026-12-24' && sw[0]?.validThrough === '2026-12-24',
    JSON.stringify(sw[0]));
  t.check('zakres dni daje validFrom różne od validThrough',
    sw[1]?.validFrom === '2026-12-25' && sw[1]?.validThrough === '2026-12-26',
    sw[1]?.validFrom + '..' + sw[1]?.validThrough);
  t.check('skrócone godziny zachowane, nie zamienione na zamknięte',
    sw[2]?.opens === '09:00' && sw[2]?.closes === '14:00',
    sw[2]?.opens + '-' + sw[2]?.closes);
  t.check('a witryna bez świąt nie dostaje tej właściwości — kontrola',
    !('specialOpeningHoursSpecification' in (wezel(grafy.firma, 'LodgingBusiness') || {})));

  t.section('atrakcja: własne pola i własne godziny');

  const at = wezel(grafy['miejsce-pelne'], 'TouristAttraction');
  t.check('grupy odbiorców jako lista', at?.touristType?.join(',') === 'Rodziny z dziećmi,Wędkarze');
  t.check('języki atrakcji', at?.availableLanguage?.join(',') === 'Polish,English');
  /* Atrakcja bywa czynna inaczej niż obiekt — plaża od maja, gdy recepcja
     cały rok. Dlatego WŁASNE godziny, a nie dziedziczone z #place. */
  t.check('atrakcja ma własne godziny otwarcia',
    at?.openingHoursSpecification?.[0]?.dayOfWeek?.length === 7,
    at?.openingHoursSpecification?.[0]?.dayOfWeek?.length);
  t.check('i własne trójstany', at?.publicAccess === true && at?.isAccessibleForFree === true);
  t.check('a atrakcja bez tych pól ich nie dostaje — kontrola',
    !('touristType' in (wezel(grafy.atrakcja, 'TouristAttraction') || {})));

  t.section('encje podrzędne: adres, telefon, obraz');

  const encje = grafy['miejsce-pelne']['@graph'].filter((n) => /#entity-\d+$/.test(n['@id']));
  t.check('encja z kompletem pól niesie wszystkie trzy',
    encje[0]?.url === 'https://przyklad.test/plaza' &&
    encje[0]?.telephone === '+48 999 888 777' &&
    encje[0]?.image === 'https://przyklad.test/plaza.jpg');
  t.check('encja bez nich nie dostaje pustych kluczy',
    !('url' in (encje[1] || {})) && !('telephone' in (encje[1] || {})),
    Object.keys(encje[1] || {}).join(','));

  t.section('punkty kontaktowe (repeater z fallbackiem)');

  /* Firmy mają osobne numery do rezerwacji, sprzedaży i wsparcia, a
     `contactType` jest właśnie od ich rozróżniania. Fallback jest tu
     najważniejszy: gdy repeater pusty, wychodzi DOKŁADNIE jeden punkt
     złożony z telefonu i typu kontaktu — czyli to, co moduł robił dotąd,
     więc witryna nietknięta ma graf bez zmian. */
  const kont = wezelId(grafy['miejsce-pelne'], '#organization')?.contactPoint;
  t.check('dwa punkty z trzech wierszy — pusty odrzucony',
    Array.isArray(kont) && kont.length === 2, JSON.stringify(kont?.length));
  t.check('punkt z telefonem nie dostaje pustego e-maila',
    kont?.[0]?.telephone === '+48 111 222 333' && !('email' in (kont?.[0] || {})));
  t.check('punkt z samym e-mailem nie dostaje pustego telefonu',
    kont?.[1]?.email === 'pomoc@przyklad.test' && !('telephone' in (kont?.[1] || {})));
  t.check('każdy punkt ma własny contactType', kont?.[0]?.contactType === 'reservations' &&
    kont?.[1]?.contactType === 'customer support');

  /* FALLBACK — sedno zgodności wstecznej tego wydania. */
  const kontStary = wezel(grafy.firma, 'Organization')?.contactPoint;
  t.check('pusty repeater → jeden punkt jak dotąd, OBIEKT a nie tablica',
    !Array.isArray(kontStary) && kontStary?.['@type'] === 'ContactPoint' &&
    kontStary?.telephone === '+48 111 222 333',
    Array.isArray(kontStary) ? 'tablica!' : kontStary?.contactType);

  // ── Presety branżowe ───────────────────────────────────────────────────
  t.section('presety: każdy typ działalności ma dokładnie jeden');

  /* Preset NIE JEST osobnym ustawieniem — wynika z `org_type`. Rozpiska
     przewidywała nad sekcjami osobny przełącznik branży, ale dwa sterowniki
     dla jednej rzeczy pozwalają je rozjechać (Hotel + branża „gastronomia"),
     a wtedy panel pokazuje pola, których wybrany typ nie ma. Skoro preset ma
     być mechanizmem POPRAWNOŚCI, nie może dać się ustawić wbrew typowi. */
  const pres = presety();
  const typyZPresetow = Object.values(pres).flatMap((p) => p.typy);
  const wszystkieTypy = Object.keys(
    JSON.parse(phpOutput('schema-graf.php', 'minimalny --typy')));

  const bezPresetu = wszystkieTypy.filter((t) => !typyZPresetow.includes(t));
  t.check('każdy typ działalności należy do jakiegoś presetu', !bezPresetu.length,
    bezPresetu.join(', ') || wszystkieTypy.length + ' typów');

  const wDwoch = typyZPresetow.filter((t, i) => typyZPresetow.indexOf(t) !== i);
  t.check('i tylko do jednego', !wDwoch.length, wDwoch.join(', ') || 'bez powtórzeń');

  const widmo = typyZPresetow.filter((t) => !wszystkieTypy.includes(t));
  t.check('żaden preset nie wskazuje typu, którego nie ma', !widmo.length,
    widmo.join(', ') || Object.keys(pres).length + ' presetów');

  /* Rozdział zdrowia od urody. Rozpiska trzymała je w jednym presecie;
     sprawdzone przy pisaniu kodu: `MedicalBusiness` i `Dentist` mają
     `medicalSpecialty`, a `BeautySalon` i `HairSalon` idą przez
     `HealthAndBeautyBusiness` i tej właściwości NIE mają. Jeden preset
     dawałby salonowi fryzjerskiemu pole „specjalizacja medyczna" —
     dokładnie ten błąd, przed którym presety mają bronić. */
  t.check('salon fryzjerski nie jest w presecie medycznym',
    !pres.zdrowie.typy.includes('HairSalon') && pres.uroda.typy.includes('HairSalon'));
  t.check('a gabinet stomatologiczny jest', pres.zdrowie.typy.includes('Dentist'));

  t.section('pola branżowe: każda branża dostaje swoje i tylko swoje');

  const bran = (scen, typ) => wezel(grafy[scen], typ) || {};
  const h = bran('hotel', 'Hotel');
  t.check('hotel: godziny zameldowania i wymeldowania',
    h.checkinTime === '15:00' && h.checkoutTime === '11:00',
    h.checkinTime + '/' + h.checkoutTime);
  t.check('hotel: liczba pokoi jako QuantitativeValue',
    h.numberOfRooms?.['@type'] === 'QuantitativeValue' && h.numberOfRooms?.value === 24);
  t.check('hotel: kategoria jako Rating z liczbą',
    h.starRating?.['@type'] === 'Rating' && h.starRating?.ratingValue === 4,
    JSON.stringify(h.starRating));
  t.check('hotel: języki obsługi jako lista',
    h.availableLanguage?.join(',') === 'Polish,English');
  t.check('hotel: zwierzęta dozwolone jako true', h.petsAllowed === true);
  /* Każdy scenariusz branżowy NIESIE pola cudzych branż — dlatego te trzy
     sprawdzenia badają bramkę w trzy strony, a nie tylko w jedną. Zanim
     scenariusze je dostały, mutacja „bramka zdjęta ze specjalizacji medycznej"
     przechodziła na zielono: żaden scenariusz nie miał `place_specialty`
     przy typie spoza presetu medycznego. */
  t.check('hotel NIE dostaje pól gastronomicznych ani medycznych',
    h.servesCuisine === undefined && h.hasMenu === undefined &&
    h.medicalSpecialty === undefined,
    ['servesCuisine', 'hasMenu', 'medicalSpecialty'].filter((k) => k in h).join(', '));

  const r = bran('restauracja', 'Restaurant');
  t.check('restauracja: rodzaje kuchni jako lista',
    r.servesCuisine?.join(',') === 'polska,wegetariańska');
  t.check('restauracja: menu i rezerwacje',
    r.hasMenu === 'https://przyklad.test/menu' && r.acceptsReservations === true);
  /* Scenariusz ma drive-through ustawiony na NIE, nie na „nie podano" —
     i to jest sedno trójstanu. `false` znaczy „nie mamy okienka" i JEST
     deklaracją; brak właściwości znaczy „nie mówimy". Checkbox tych dwóch
     rzeczy nie odróżniał. */
  t.check('restauracja: drive-through jako świadome NIE, nie brak',
    r.hasDriveThroughService === false,
    JSON.stringify(r.hasDriveThroughService));
  t.check('restauracja też ma starRating — jedyne pole wspólne dla dwóch presetów',
    r.starRating?.ratingValue === 3);
  t.check('restauracja NIE dostaje pól hotelowych ani medycznych',
    r.checkinTime === undefined && r.numberOfRooms === undefined &&
    r.medicalSpecialty === undefined,
    ['checkinTime', 'numberOfRooms', 'medicalSpecialty'].filter((k) => k in r).join(', '));

  const gab = bran('gabinet', 'Dentist');
  t.check('gabinet: specjalizacja medyczna', gab.medicalSpecialty?.join(',') === 'Dentistry');
  t.check('gabinet NIE dostaje ani hotelowych, ani gastronomicznych',
    gab.checkinTime === undefined && gab.servesCuisine === undefined,
    ['checkinTime', 'servesCuisine'].filter((k) => k in gab).join(', '));

  /* hasOfferCatalog opisuje ofertę FIRMY, nie zawartość budynku — dlatego
     siedzi na #organization. Rozpiska zostawiała to jako pytanie otwarte. */
  const orgGab = wezel(grafy.gabinet, 'Organization');
  t.check('oferta usług trafia na #organization, nie na #place',
    !!orgGab?.hasOfferCatalog && gab.hasOfferCatalog === undefined);
  t.check('oferta ma kształt OfferCatalog z pozycjami',
    orgGab?.hasOfferCatalog?.['@type'] === 'OfferCatalog' &&
    orgGab?.hasOfferCatalog?.itemListElement?.length === 2,
    JSON.stringify(orgGab?.hasOfferCatalog?.itemListElement?.length));
  t.check('pozycja oferty to Offer z Service w środku',
    orgGab?.hasOfferCatalog?.itemListElement?.[0]?.itemOffered?.['@type'] === 'Service' &&
    orgGab?.hasOfferCatalog?.itemListElement?.[0]?.itemOffered?.name === 'Przegląd');

  t.section('bramka presetu: wartości zostają, ale nie wyciekają');

  /* SEDNO WYDANIA. Ustawienia niosą komplet pól hotelowych i gastronomicznych,
     ale typ działalności to gabinet — stan po przestawieniu typu na stronie,
     która wcześniej była hotelem. Wartości MUSZĄ zostać w bazie (powrót do
     poprzedniego typu ma je przywrócić), więc jedyne, co dzieli je od cudzego
     grafu, to bramka w `build_place()`. */
  const uW = ustawienia('wyciek-presetu');
  t.check('wartości hotelowe DALEJ SĄ w ustawieniach',
    uW.place_checkin === '15:00' && uW.place_rooms === '24',
    uW.place_checkin + ' / ' + uW.place_rooms);

  const wyciek = bran('wyciek-presetu', 'Dentist');
  const OBCE = ['checkinTime', 'checkoutTime', 'numberOfRooms', 'petsAllowed',
                'availableLanguage', 'starRating', 'servesCuisine', 'hasMenu'];
  const przeciekly = OBCE.filter((k) => k in wyciek);
  t.check('ale ANI JEDNA nie wychodzi do grafu gabinetu', !przeciekly.length,
    przeciekly.join(', ') || 'graf czysty');
  t.check('status pożytku publicznego też nie — to preset „organizacja"',
    wezel(grafy['wyciek-presetu'], 'Organization')?.nonprofitStatus === undefined);

  /* Kontrola dodatnia: te same pola przy WŁAŚCIWYM typie wychodzą. Bez niej
     „nic nie wycieka" przechodzi też wtedy, gdy nic nie wychodzi nigdy. */
  t.check('a przy typie Hotel te same pola wychodzą — kontrola',
    OBCE.filter((k) => k in h).length === 6, Object.keys(h).length + ' pól');

  t.section('rejestr pól jest jedyną prawdą o polach');

  /* Do 1.171.0 prawda o polu była w trzech miejscach: wartość domyślna
     w `$defaults`, sposób sanityzacji w ręcznie wypisanej liście, a węzeł
     grafu nigdzie. Pole dopisane do formularza, a zapomniane w liście
     `$texts`, przestawało się zapisywać BEZ ŻADNEGO OBJAWU — formularz
     przyjmował wartość, sanityzacja jej nie przepisywała, po przeładowaniu
     pole było puste. Przy ~86 polach z rozpiski to kwestia czasu, nie ryzyka. */
  const pola = rejestr();
  const klucze = Object.keys(pola);

  t.check('rejestr niepusty', klucze.length > 20, klucze.length + ' pól');
  const bezOpisu = klucze.filter((k) =>
    !pola[k].wezel || !pola[k].typ || pola[k].domyslnie === undefined);
  t.check('każde pole ma węzeł, typ i wartość domyślną', !bezOpisu.length,
    bezOpisu.join(', ') || 'komplet');

  /* Lista rośnie razem z rejestrem — i to sprawdzenie ma o tym POWIEDZIEĆ.
     Przy dokładaniu typów `data` i `liczba` w 1.172.0 zapaliło jako pierwsze,
     zanim padła jakakolwiek inna różnica. O to chodzi: nowy typ sanityzacji
     ma być decyzją, a nie czymś, co wchodzi bokiem razem z polem. */
  const TYPY = ['tekst', 'wieloliniowe', 'url', 'wspolrzedna', 'data', 'liczba',
                'czas', 'trojstan', 'checkbox', 'json', 'wlasne'];
  const zleTypy = klucze.filter((k) => !TYPY.includes(pola[k].typ));
  t.check('żaden typ spoza znanej listy', !zleTypy.length,
    zleTypy.map((k) => k + '=' + pola[k].typ).join(', ') || TYPY.length + ' typów');

  /* SEDNO: każde pole formularza zakładki MUSI być w rejestrze. To jest
     dokładnie ta pomyłka, przed którą rejestr broni — i jedyny sposób, żeby
     zapaliła, zanim klient zgłosi „zapisuję i nie zapisuje". */
  const zakladka = phpOutput('tab.php', 'schema');
  const zForm = [...zakladka.matchAll(/name="evk_schema\[([a-z0-9_]+)\]"/g)]
    .map((m) => m[1]);
  const nieznane = [...new Set(zForm)].filter((k) => !klucze.includes(k));
  t.check('każde pole formularza jest w rejestrze', !nieznane.length,
    nieznane.join(', ') || new Set(zForm).size + ' pól formularza');

  /* Kontrola do kontroli: gdyby regexp przestał cokolwiek łapać — bo zakładka
     zmieni sposób nazywania pól — sprawdzenie wyżej byłoby zielone na pustej
     liście. */
  t.check('a formularz w ogóle ma pola do sprawdzenia', new Set(zForm).size > 10,
    new Set(zForm).size + '');

  /* Bramka presetu jest w DWÓCH miejscach: w panelu (co widać) i w budowaniu
     grafu (co wychodzi). Muszą mówić to samo — panel pokazujący pole, którego
     graf i tak nie wyemituje, to formularz kłamiący użytkownikowi w twarz.
     Sprawdzamy, że każde pole rejestru z kluczem `preset` niesie w znaczniku
     DOKŁADNIE tę samą listę presetów. */
  const bramkowane = klucze.filter((k) => pola[k].preset);

  /* Szukamy WSTECZ od nazwy pola do najbliższego `data-preset`, zamiast do
     przodu od atrybutu. Pierwsza wersja szła do przodu i przy trzech
     sąsiadujących checkboxach przypisywała drugiemu atrybut pierwszego —
     sprawdzenie zapalało na poprawnym kodzie. */
  const presetWZakladce = (klucz) => {
    const i = zakladka.indexOf('evk_schema[' + klucz + ']');
    if (i < 0) return null;
    const przed = zakladka.slice(0, i);
    const j = przed.lastIndexOf('data-preset="');
    if (j < 0) return null;
    return przed.slice(j + 'data-preset="'.length, przed.indexOf('"', j + 13));
  };

  const rozjazd = bramkowane.filter(
    (k) => presetWZakladce(k) !== pola[k].preset.join(' '));
  t.check('bramka w panelu zgadza się z rejestrem', !rozjazd.length,
    rozjazd.join(', ') || bramkowane.length + ' pól bramkowanych');

  /* Przy typie „Organizacja" widoczne ma być TYLKO pole tego presetu.
     Kontrola, że atrybut `hidden` w ogóle jest wystawiany — bez niej
     sprawdzenie wyżej przechodzi także wtedy, gdy panel nie chowa nic. */
  const ukryte = (zakladka.match(/data-preset="[^"]*" hidden/g) || []).length;
  t.check('pola spoza bieżącego presetu wychodzą jako hidden',
    ukryte === bramkowane.length - 1,
    ukryte + ' z ' + bramkowane.length + ' (jedno widoczne: preset „organizacja")');

  // ── Sanityzacja zapisu ─────────────────────────────────────────────────
  t.section('sanityzacja ustawień — pętla po rejestrze');

  /* Do 1.171.0 sanityzacji ustawień Schema nie sprawdzało NIC w całym
     zestawie: ani współrzędnych, ani JSON-ów, ani typu działalności spoza
     listy. Wyszło przy mutacji — „sanityzacja współrzędnych przepuszcza
     tekst" przechodziła na zielono, bo harness zasiewał opcję wprost,
     z pominięciem zapisu. Sprawdzenia niżej idą przez PRAWDZIWĄ
     `sanitize_settings()`. */
  const san = (wejscie) =>
    JSON.parse(phpOutput('schema-graf.php',
      'minimalny --sanityzuj ' + JSON.stringify(JSON.stringify(wejscie))));

  const w = san({
    geo_lat: '53,8021',            // przecinek dziesiętny
    geo_lng: 'na pewno nie liczba',
    site_name: '<b>Firma</b>',
    org_type: 'NieMaTakiegoTypu',
    amenities: 'a\nb',
    block_faq: '1',
    block_org: '',
  });

  t.check('przecinek dziesiętny zamieniony na kropkę', w.geo_lat === '53.8021', w.geo_lat);
  t.check('współrzędna niebędąca liczbą wyczyszczona', w.geo_lng === '', JSON.stringify(w.geo_lng));
  t.check('znaczniki HTML zdjęte z pola tekstowego', w.site_name === 'Firma', w.site_name);
  t.check('typ działalności spoza listy wraca do Organization',
    w.org_type === 'Organization', w.org_type);
  t.check('checkbox zaznaczony to 1', w.block_faq === 1, JSON.stringify(w.block_faq));
  t.check('checkbox pusty to 0, nie pusty łańcuch', w.block_org === 0, JSON.stringify(w.block_org));
  t.check('pole nieobecne w wejściu dostaje wartość domyślną',
    w.country === 'PL' && w.contact_type === 'customer service',
    w.country + ' / ' + w.contact_type);

  /* Typy `data` i `liczba` dołożone w 1.172.0. Sprawdzane TU, a nie przez
     scenariusz grafu — scenariusze zasiewają opcję wprost, z pominięciem
     zapisu, więc walidacja przechodziłaby na zielono, cokolwiek by robiła.
     Ta sama luka co przy współrzędnych: mutacja „walidacja daty przepuszcza
     cokolwiek" przeszła, zanim to sprawdzenie powstało. */
  const daty = san({ org_founding: '1998' });
  t.check('sam rok to poprawna data', daty.org_founding === '1998', daty.org_founding);
  t.check('rok z miesiącem też', san({ org_founding: '1998-04' }).org_founding === '1998-04');
  t.check('pełna data też', san({ org_founding: '1998-04-20' }).org_founding === '1998-04-20');
  for (const zle of ['kiedyś', '20.04.1998', '98', '1998-4-20', '1998-04-20T10:00']) {
    t.check('odrzucona data: ' + JSON.stringify(zle),
      san({ org_founding: zle }).org_founding === '',
      JSON.stringify(san({ org_founding: zle }).org_founding));
  }

  t.check('liczba całkowita przechodzi', san({ org_employees: '12' }).org_employees === '12');
  for (const zle of ['-5', '12,5', 'dwunastu', '12 osób']) {
    t.check('odrzucona liczba: ' + JSON.stringify(zle),
      san({ org_employees: zle }).org_employees === '',
      JSON.stringify(san({ org_employees: zle }).org_employees));
  }

  /* Zepsuty JSON ma wrócić do wartości domyślnej. Do 1.171.0 przechodził na
     wylot: pętla walidująca ustawiała wartość poprawnie, a stojąca niżej
     gałąź `else` nadpisywała ją SUROWYM wejściem — obejście walidacji
     w czterech polach naraz. */
  const zepsuty = san({
    descriptions: '{zepsuty', social_links: '[zepsute',
    lang_currencies: 'nie json', sub_entities: '{{{',
  });
  t.check('zepsuty JSON opisów wraca do domyślnego', zepsuty.descriptions === '{}',
    zepsuty.descriptions);
  t.check('zepsuty JSON socialów wraca do domyślnego', zepsuty.social_links === '',
    JSON.stringify(zepsuty.social_links));
  t.check('zepsuty JSON walut wraca do domyślnego',
    zepsuty.lang_currencies === '{"en":"EUR","de":"EUR"}', zepsuty.lang_currencies);
  t.check('zepsuty JSON encji podrzędnych wraca do domyślnego',
    zepsuty.sub_entities === '[]', zepsuty.sub_entities);

  /* Kontrola: POPRAWNY JSON ma przejść nietknięty. Bez niej „zepsuty wraca
     do domyślnego" przechodzi także wtedy, gdy do domyślnego wraca wszystko. */
  const dobry = san({ descriptions: '{"pl":"Opis"}', sub_entities: '[{"type":"Beach","name":"Plaża"}]' });
  t.check('poprawny JSON przechodzi nietknięty',
    dobry.descriptions === '{"pl":"Opis"}', dobry.descriptions);
  t.check('i poprawny repeater też',
    dobry.sub_entities === '[{"type":"Beach","name":"Plaża"}]', dobry.sub_entities);

  /* ── Edytor węzłów: BRAMKA PRZY ZAPISIE ────────────────────────────────
     Ta gałąź czyta z `$_POST`, nie z `$input` — tak działa formularz. Bramka
     w `dolacz_wlasne()` (sprawdzona wyżej) łapie to samo, ale dopiero przy
     budowaniu grafu; ta odsiewa przed wejściem do bazy, więc zły wiersz nie
     wraca do formularza po zapisie. Obie są potrzebne i każda ma inny zasięg. */
  const zapisz = (wiersze) => JSON.parse(san({
    _post: { evk_schema_custom: {
      wezel:   wiersze.map((r) => r[0]),
      klucz:   wiersze.map((r) => r[1]),
      wartosc: wiersze.map((r) => r[2]),
    } },
  }).custom_props);

  const przeszly = zapisz([
    ['organization', 'slogan',        'Dobry'],
    ['organization', '@id',           'https://cudze.test/#x'],
    ['website',      '@type',         'Thing'],
    ['organization', 'zły klucz',     'x'],
    ['organization', '9zle',          'x'],
    ['cudzy',        'name',          'x'],
    ['place',        'priceRange',    '$$'],
  ]);
  t.check('zapis przepuszcza tylko prawidłowe wiersze',
    przeszly.length === 2, JSON.stringify(przeszly.map((r) => r.klucz)));
  t.check('klucz z @ nie wchodzi do bazy',
    !przeszly.some((r) => r.klucz.startsWith('@')));
  t.check('klucz ze spacją nie wchodzi do bazy',
    !przeszly.some((r) => /\s/.test(r.klucz)));
  t.check('klucz od cyfry nie wchodzi do bazy',
    !przeszly.some((r) => /^\d/.test(r.klucz)));
  t.check('węzeł spoza listy nie wchodzi do bazy',
    przeszly.every((r) => ['website', 'organization', 'place', 'attraction'].includes(r.wezel)),
    przeszly.map((r) => r.wezel).join(' '));

  /* Wartość ma wrócić do formularza DOKŁADNIE taka, jaka weszła. Gdyby JSON
     był parsowany przy zapisie i zapisywany jako struktura, pole po zapisie
     pokazałoby przeformatowaną treść — co wygląda jak zepsute pole. */
  const surowe = zapisz([['organization', 'numberOfEmployees',
    '{"@type":"QuantitativeValue",  "value":12}']]);
  t.check('wartość wraca surowa, bez przeformatowania',
    surowe[0].wartosc === '{"@type":"QuantitativeValue",  "value":12}', surowe[0].wartosc);

  /* Wiersz z pustą wartością MA zostać — pusta wartość znaczy „usuń tę
     właściwość z węzła", więc odsianie go zabrałoby jedyny sposób na zdjęcie
     czegoś, co moduł wstawia zawsze. */
  const doUsuniecia = zapisz([['website', 'description', '']]);
  t.check('wiersz z pustą wartością zostaje — to jest polecenie usunięcia',
    doUsuniecia.length === 1 && doUsuniecia[0].wartosc === '', JSON.stringify(doUsuniecia));

  /* Każdy klucz rejestru MUSI wyjść z sanityzacji. Pole dopisane do rejestru,
     a pominięte przy zapisie, przestaje się zapisywać bez żadnego objawu —
     to jest ta usterka, przed którą rejestr broni. */
  const brakujace = klucze.filter((k) => !(k in w));
  t.check('sanityzacja oddaje każdy klucz rejestru', !brakujace.length,
    brakujace.join(', ') || klucze.length + ' kluczy');

  // ── Nadpisania per podstrona ───────────────────────────────────────────
  t.section('nadpisania per podstrona (warstwa pod przyszły metaboks)');

  /* `get_settings($post_id)` scala trzy warstwy: domyślne → globalne →
     meta wpisu `_evk_schema`. Interfejsu zapisującego tę meta jeszcze nie ma,
     ale mechanizm jest kompletny i musi być sprawdzony — inaczej jest martwym
     kodem, który przy dopisywaniu metaboksu okaże się nie działać. */
  const u = ustawienia('nadpisanie-wpisu');
  t.check('meta wpisu bije ustawienie globalne',
    u.site_name === 'Nazwa tylko dla tej podstrony', u.site_name);
  t.check('pole nienadpisane zostaje z warstwy globalnej',
    u.country === 'PL', u.country);

  /* Klucz spoza rejestru ma odpaść. Meta wpisu bywa zapisywana z zewnątrz
     i nie chcemy, żeby dowolny klucz wjeżdżał do ustawień tylnymi drzwiami.
     Widać to WYŁĄCZNIE w ustawieniach — odrzucony klucz z definicji nie
     zostawia śladu w grafie. */
  t.check('klucz spoza rejestru odrzucony',
    !('nie_ma_takiego_pola' in u), Object.keys(u).length + ' kluczy');

  /* Nadpisanie zmienia nie tylko wartości, ale i KSZTAŁT grafu. */
  const gNad = grafy['nadpisanie-wpisu'];
  t.check('nadpisana nazwa wychodzi do grafu',
    wezel(gNad, 'WebSite')?.name === 'Nazwa tylko dla tej podstrony');
  t.check('odhaczony blok w meta usuwa węzeł z grafu',
    !wezel(gNad, 'BreadcrumbList'),
    gNad['@graph'].map((n) => n['@type']).join(', '));
  t.check('a bez nadpisania ten sam scenariusz węzeł MA — kontrola',
    !!wezel(grafy.podstrona, 'BreadcrumbList'));

  /* PUSTY ŁAŃCUCH NADPISUJE, BRAK KLUCZA NIE. Scalanie po `!empty()`
     przechodziłoby wszystkie sprawdzenia wyżej i wywracało się dopiero tutaj. */
  const uPuste = ustawienia('nadpisanie-puste');
  t.check('pusty łańcuch w meta nadpisuje niepustą wartość globalną',
    uPuste.telephone === '', JSON.stringify(uPuste.telephone));
  t.check('i telefon znika z #organization',
    wezel(grafy['nadpisanie-puste'], 'Organization')?.telephone === undefined);
  t.check('razem z contactPoint, który na nim stał',
    wezel(grafy['nadpisanie-puste'], 'Organization')?.contactPoint === undefined);
  t.check('i z #place',
    wezel(grafy['nadpisanie-puste'], 'LodgingBusiness')?.telephone === undefined);
  t.check('a w scenariuszu bez nadpisania telefon jest — kontrola',
    wezel(grafy.firma, 'Organization')?.telephone === '+48 111 222 333');

  /* Filtr `evk_schema_settings` — drugie wejście dla kodu spoza modułu.
     Sprawdzane PRAWDZIWYM `apply_filters` (harness ma własny, bo wspólna
     atrapa jest przelotowa); pod atrapą filtr byłby dodatkiem, którego nie
     sprawdza nic i który okazuje się nie działać w dniu, gdy ktoś na niego
     liczy. Numer wpisu w drugim argumencie jest tym, co pozwala filtrowi
     rozróżnić podstrony — bez niego byłby wart tyle, co stała. */
  const uFiltr = ustawienia('filtr-ustawien');
  t.check('filtr może nadpisać ustawienia',
    uFiltr.site_name === 'Z filtru, wpis 11', uFiltr.site_name);
  t.check('i dostaje numer wpisu, nie tylko wartości',
    /wpis 11$/.test(uFiltr.site_name));
  t.check('wynik filtru wychodzi do grafu',
    wezel(grafy['filtr-ustawien'], 'WebSite')?.name === 'Z filtru, wpis 11');

  t.section('okruszki tylko wtedy, gdy węzeł BreadcrumbList powstaje');

  /* Trzeci przypadek tej samej klasy co `publisher`, znaleziony przez ogólne
     sprawdzenie rozwiązywalności wskazań przy okazji warstwy nadpisań.
     Odhaczenie okruszków — globalnie albo na jednej podstronie — zostawiało
     `WebPage.breadcrumb` i `BlogPosting.breadcrumb` wskazujące donikąd. */
  t.check('WebPage bez breadcrumb, gdy blok odhaczony',
    wezel(grafy['bez-okruszkow'], 'WebPage')?.breadcrumb === undefined,
    wezel(grafy['bez-okruszkow'], 'WebPage')?.breadcrumb?.['@id']);
  /* BlogPosting NIE MA `breadcrumb` NIGDY — nie z powodu przełącznika, tylko
     dlatego, że ta właściwość ma w schema.org dziedzinę WYŁĄCZNIE `WebPage`,
     a BlogPosting to Article → CreativeWork. Do 1.173.0 moduł wysyłał ją tam
     zawsze; znalazł to audyt właściwość-po-właściwości, nie żadne sprawdzenie.
     Okruszki i tak są w grafie dwa razy — własnym węzłem i na WebPage —
     więc nie zginęło nic poza nieprawidłowością. */
  t.check('BlogPosting nie ma breadcrumb — dziedzina tej właściwości to WebPage',
    wezel(grafy.wpis, 'BlogPosting')?.breadcrumb === undefined &&
    wezel(grafy['bez-okruszkow'], 'BlogPosting')?.breadcrumb === undefined,
    wezel(grafy.wpis, 'BlogPosting')?.breadcrumb?.['@id']);
  t.check('a przy włączonym bloku WebPage MA breadcrumb — kontrola',
    wezel(grafy.podstrona, 'WebPage')?.breadcrumb?.['@id']
      === 'https://example.test/oferta/domki/#breadcrumb');
  /* Kontrola: okruszki JAKO WĘZEŁ dalej powstają przy włączonym bloku —
     bez niej „BlogPosting nie ma breadcrumb" przechodzi także wtedy, gdyby
     okruszki zniknęły z grafu w ogóle. */
  t.check('a węzeł BreadcrumbList przy włączonym bloku jest — kontrola',
    !!wezel(grafy.wpis, 'BreadcrumbList'));

  t.section('publisher tylko wtedy, gdy wydawca jest w grafie');

  /* Do 1.168.0 `publisher` ustawiały bezwarunkowo DWA węzły — WebSite
     i BlogPosting — więc odhaczenie bloku Organization zostawiało w grafie
     dwa wskazania na węzeł, którego nie ma. Poprawny JSON, wskazanie
     donikąd: nie zauważy tego ani parser, ani oko. Ogólne sprawdzenie
     rozwiązywalności wskazań (wyżej) obejmuje już `bez-org`; tutaj pytamy
     wprost o oba miejsca, żeby powód był widoczny w nazwie. */
  t.check('WebSite bez publisher, gdy blok Organization odhaczony',
    wezel(grafy['bez-org'], 'WebSite')?.publisher === undefined,
    wezel(grafy['bez-org'], 'WebSite')?.publisher?.['@id']);
  t.check('BlogPosting bez publisher, gdy blok Organization odhaczony',
    wezel(grafy['bez-org'], 'BlogPosting')?.publisher === undefined,
    wezel(grafy['bez-org'], 'BlogPosting')?.publisher?.['@id']);

  /* Kontrola dodatnia: przy włączonym bloku publisher MA być w obu miejscach.
     Bez niej naprawa przechodzi też wtedy, gdy skasowała pole na dobre. */
  t.check('a przy włączonym bloku WebSite ma publisher',
    wezel(grafy.wpis, 'WebSite')?.publisher?.['@id'] === 'https://example.test/#organization');
  t.check('i BlogPosting też ma publisher',
    wezel(grafy.wpis, 'BlogPosting')?.publisher?.['@id'] === 'https://example.test/#organization');
};
