# Schema — rozpiska pól przed przebudową zakładki

Dokument roboczy do zatwierdzenia **przed** pisaniem kodu. Spisany 2026-09-10,
przy wersji **1.170.0**, po naprawie dwóch usterek grafu (1.169.0) i usunięciu
podpowiedzi po jednym wdrożeniu (1.170.0).

Decyzje, z których wynika ten dokument, stoją w `docs/schema-szkic.md`
w tabeli „Decyzje": zakres **(a) + (c)** — pełne właściwości typów, które
wtyczka już generuje, plus generyczny edytor węzłów; układ **wg typu węzła
z presetami branżowymi**; zapis **globalny**; `knowsAbout` jako jedno pole
mieszające tekst i URL.

---

## 1. Po co ten dokument

„Wszystkie możliwe opcje schema" nie ma granicy — schema.org ma ponad 800
typów i kilka tysięcy właściwości. Zakres zawęziliśmy do typów, które moduł
już emituje, ale i to zostawia kilkaset właściwości do przebrania.

Rozpiska odpowiada na trzy pytania, na które kod nie może odpowiedzieć sam:

1. **Które właściwości wchodzą** i dlaczego akurat te.
2. **Do której sekcji panelu** trafiają.
3. **Który preset branżowy** je pokazuje — bo część z nich jest **nieprawidłowa
   poza swoją branżą**, a nie tylko nieprzydatna.

Punkt 3 jest ważniejszy, niż wygląda, i jest głównym argumentem za presetami.
`servesCuisine` istnieje na `FoodEstablishment`, a nie na `Dentist`.
`checkinTime` na `LodgingBusiness`, a nie na `HairSalon`. Panel, który pokazuje
wszystkim wszystko, nie jest tylko zagracony — **produkuje nieprawidłowy graf**,
gdy ktoś wypełni pole spoza swojego typu. Preset nie jest więc udogodnieniem,
tylko mechanizmem poprawności.

---

## 2. Stan zerowy — co moduł emituje dziś

Policzone z prawdziwego wyjścia `render_graph()` (dziewięć scenariuszy
`tests/php/schema-graf.php`), a nie z lektury kodu.

| Węzeł | Właściwości dziś (bez `@id`/`@type`) |
|---|---|
| `#website` | 5 — `url`, `name`, `description`, `publisher`, `potentialAction` |
| `#organization` | 10 — `name`, `url`, `description`, `address`, `telephone`, `email`, `contactPoint`, `image`, `logo`, `sameAs` |
| `#place` | 14 — jw. bez `contactPoint`/`logo`/`sameAs`, za to `geo`, `priceRange`, `amenityFeature`, `hasMap`, `openingHoursSpecification`, `areaServed`, `parentOrganization` |
| `#attraction` | 6 — `name`, `url`, `description`, `address`, `geo`, `containedInPlace` |
| encja podrzędna | 3 — `name`, `description`, `containedInPlace` |
| `WebPage` | 7 — wyprowadzane z wpisu, nie z pól panelu |
| `BlogPosting` | 11 — jw. |
| `FAQPage` | 2 — z akordeonu Bricksa |
| `Product` | 5 — z WooCommerce |

Trzy ostatnie grupy są **wyprowadzane**, nie wpisywane. To rozróżnienie
przenika całą rozpiskę.

---

## 3. Zasady doboru — dlaczego coś jest w środku albo poza

Właściwość wchodzi, gdy spełnia **wszystkie trzy**:

1. **Google ją czyta** — do wyniku rozszerzonego, panelu wiedzy albo listingu
   zakupowego. Właściwość, której nie czyta żaden konsument, dokłada bajtów
   do `<head>` każdej podstrony i nic nie daje.
2. **Da się ją wypełnić bez programisty.** `identifier` jako `PropertyValue`
   z własnym `propertyID` — nie. NIP w polu tekstowym — tak.
3. **Jest globalna dla witryny.** Zdecydowaliśmy, że metaboksu per wpis na
   razie nie ma. Właściwość, która z natury zmienia się co podstronę
   (`datePublished`, `wordCount`, obraz wpisu), nie może być polem globalnym —
   wpisana raz, skłamie wszędzie indziej.

Kryterium 3 przycina najwięcej i jest powodem, dla którego sekcje `WebPage`,
`BlogPosting` i `Product` dostają w tej turze prawie nic. To nie przeoczenie,
tylko konsekwencja decyzji „wszystko globalne".

### Czego świadomie nie robimy

| Rzecz | Dlaczego nie |
|---|---|
| `aggregateRating` / `review` wpisywane ręcznie | Wytyczne Google o fragmentach z opinią zabraniają oceny, której nie widać na stronie i która nie pochodzi od realnych recenzentów. Pole „wpisz swoją ocenę" jest zaproszeniem do kary, nie funkcją. Wchodzi wyłącznie jako **odczyt z WooCommerce**, gdzie opinie są prawdziwe. |
| `datePublished`, `dateModified`, `wordCount`, `articleSection` | Zmienne per wpis. Dziś wyprowadzane automatycznie i tak jest poprawnie. |
| Nowe typy: `Event`, `Recipe`, `Course`, `JobPosting`, `HowTo`, `VideoObject` | Poza zakresem — decyzja 1. Bez metaboksu nie mają sensu jako jedna wartość na witrynę. |
| `duns`, `leiCode`, `isicV4`, `naics` | Kryterium 1: nie znam konsumenta, który je czyta. Zostają dla edytora węzłów. |
| `speakable` | Google wycofał program. |

---

## 4. Sekcje panelu

Osiem dzisiejszych sekcji ułożonych pod jeden typ działalności zastępuje
**siedem sekcji wg węzła grafu**, plus pasek presetu nad nimi.

```
┌─ Preset branżowy: [ Obiekt noclegowy ▾ ]  ← rozwija i zwija sekcje niżej
├─ 1. Witryna            (#website)
├─ 2. Organizacja        (#organization)      ← wydawca strony
├─ 3. Miejsce            (#place)             ← fizyczny obiekt, gdy typ ≠ Organizacja
│     └ 3a. Pola branżowe (zależne od typu)
├─ 4. Atrakcja           (#attraction)
├─ 5. Encje podrzędne    (repeater)
├─ 6. Treść              (WebPage / BlogPosting / FAQ / Product — sterowanie, nie pola)
└─ 7. Edytor węzłów      (dowolna właściwość → dowolny węzeł)
     + podgląd JSON-LD i link do Rich Results
```

Układ odpowiada **jeden do jednego** temu, co wychodzi w `@graph`. To jest
całe uzasadnienie: gdy podgląd pokaże coś nie tak, wiadomo, w której sekcji
szukać, bez znajomości kodu.

---

## 5. Rozpiska właściwości

Legenda kolumny **Preset**: `wszystkie` — widoczne zawsze; nazwa presetu —
tylko tam. Kolumna **Pole** mówi, jak to wygląda w panelu.

### 5.1 Sekcja 1 — Witryna (`#website`)

| Właściwość | Pole | Preset | Po co |
|---|---|---|---|
| `alternateName` | tekst | wszystkie | Google używa `name` + `alternateName` do ustalenia **nazwy witryny** w wynikach. Dziś nie mamy skąd podać skrótu („Piekarnia Przykładowa" vs „Przykładowa"). |
| `inLanguage` | auto z modułu Tłumaczeń | wszystkie | Mamy tę informację, nie wystawiamy jej. |
| `copyrightYear` | liczba | wszystkie | Tanie. |
| `copyrightHolder` | wskazanie na `#organization` | wszystkie | Automatyczne, bez pola. |
| `license` | URL | wszystkie | Dla witryn z treścią na licencji. |

**5 nowych, wszystkie globalne z natury.** Sekcja jest mała i taka zostanie.

### 5.2 Sekcja 2 — Organizacja (`#organization`)

Tu trafia najwięcej, bo to jedyny węzeł, który jest globalny **z definicji**.

| Właściwość | Pole | Preset | Po co |
|---|---|---|---|
| **`knowsAbout`** | textarea, jedna pozycja na linię | wszystkie | **Pozycja nr 1 z listy zgłaszającego.** Linia od `http` → `{"@type":"Thing","@id":URL}`, reszta → tekst. |
| `legalName` | tekst | wszystkie | Nazwa rejestrowa, inna niż handlowa. |
| `alternateName` | tekst | wszystkie | |
| `slogan` | tekst | wszystkie | |
| `foundingDate` | data | wszystkie | Panel wiedzy. |
| `founder` | tekst (→ `Person`) | wszystkie | |
| `numberOfEmployees` | liczba | wszystkie | |
| `vatID` | tekst | wszystkie | NIP. Każda polska firma zna go na pamięć. |
| `taxID` | tekst | wszystkie | REGON/KRS. |
| `award` | textarea | wszystkie | |
| `memberOf` | textarea (nazwa + URL) | wszystkie | Izby, zrzeszenia. |
| `brand` | tekst | wszystkie | |
| `areaServed` | textarea | wszystkie | **Dziś tylko na `#place`.** Organizacja bez fizycznego obiektu nie ma jak podać obszaru. |
| `contactPoint` | **repeater** | wszystkie | Dziś jeden, wyprowadzany z telefonu. Realnie firmy mają osobne numery do sprzedaży, wsparcia i rezerwacji — a `contactType` to właśnie rozróżnia. |
| `faxNumber` | tekst | wszystkie | Kancelarie i przychodnie nadal go mają. |
| `nonprofitStatus` | select | organizacja | Fundacje i stowarzyszenia. |
| `hasOfferCatalog` | repeater nazw usług | usługi, zdrowie | Lista usług bez wchodzenia w `Service` jako osobny typ. |

**17 nowych.** Z 10 właściwości robi się 27.

### 5.3 Sekcja 3 — Miejsce (`#place`), pola wspólne

| Właściwość | Pole | Preset | Po co |
|---|---|---|---|
| `specialOpeningHoursSpecification` | textarea, `data godziny` | wszystkie | **Święta i przerwy urlopowe.** Google to czyta i pokazuje. Dziś nie da się powiedzieć „24.12 zamknięte". |
| `currenciesAccepted` | tekst | wszystkie | |
| `paymentAccepted` | textarea | wszystkie | „Gotówka, karta, BLIK". |
| `publicAccess` | przełącznik | wszystkie | |
| `isAccessibleForFree` | przełącznik | wszystkie | |
| `smokingAllowed` | przełącznik | noclegi, gastronomia | |
| `branchCode` | tekst | wszystkie | Numer oddziału przy wielu lokalizacjach. |
| `maximumAttendeeCapacity` | liczba | noclegi, gastronomia, sport | |
| `photo` | repeater URL | wszystkie | Dziś jeden `image` z faviconu. |
| `faxNumber` | tekst | wszystkie | |

**10 nowych, wspólnych.**

### 5.4 Sekcja 3a — Pola branżowe (`#place`, zależne od typu)

Tu preset przestaje być wygodą, a staje się poprawnością. Każde z tych pól
**nie istnieje** na typach spoza swojego presetu.

| Preset | Typy z `org_types()` | Właściwości |
|---|---|---|
| **Obiekt noclegowy** | `LodgingBusiness`, `Hotel`, `BedAndBreakfast`, `Campground`, `Resort`, `Hostel` | `checkinTime`, `checkoutTime`, `numberOfRooms`, `petsAllowed`, `starRating`, `availableLanguage` |
| **Gastronomia** | `Restaurant`, `CafeOrCoffeeShop`, `BarOrPub`, `FoodEstablishment` | `servesCuisine`, `hasMenu`, `acceptsReservations`, `starRating`, `hasDriveThroughService` |
| **Zdrowie i uroda** | `MedicalBusiness`, `Dentist`, `BeautySalon`, `HairSalon` | `medicalSpecialty`, `availableService`, `isAcceptingNewPatients` |
| **Usługi profesjonalne** | `ProfessionalService`, `LegalService`, `FinancialService`, `RealEstateAgent`, `TravelAgency`, `AutoRepair`, `HomeAndConstructionBusiness` | `hasOfferCatalog`, `knowsLanguage`, `serviceArea` |
| **Sklep** | `Store` | `hasDeliveryMethod`, `currenciesAccepted` |
| **Sport i rekreacja** | `SportsActivityLocation`, `TouristInformationCenter` | `sport`, `availableLanguage`, `isAccessibleForFree` |
| **Firma lokalna (ogólna)** | `LocalBusiness` | tylko wspólne z 5.3 |
| **Organizacja** | `Organization` | brak — nie ma węzła `#place` |

**8 presetów pokrywa wszystkie 26 typów** z `org_types()`. Sprawdzone wobec
kodu, nie na oko: 26 typów w `org_types()`, 26 przypisanych, zero bez presetu,
zero w dwóch presetach naraz, zero presetów dla typu, którego nie ma.
Podział: noclegi 6, usługi 7, gastronomia 4, zdrowie 4, sport 2, sklep 1,
firma lokalna 1, organizacja 1.

Kompletność tego przypisania trzeba będzie **utrzymać** — dopisanie typu do
`org_types()` bez presetu zostawi go bez pól branżowych po cichu. To kandydat
na sprawdzenie w siatce przy wydaniu 3.

### 5.5 Sekcja 4 — Atrakcja (`#attraction`)

| Właściwość | Pole | Preset | Po co |
|---|---|---|---|
| `touristType` | textarea | wszystkie | „Rodziny z dziećmi", „Wędkarze". |
| `availableLanguage` | z modułu Tłumaczeń | wszystkie | |
| `isAccessibleForFree` | przełącznik | wszystkie | |
| `publicAccess` | przełącznik | wszystkie | |
| `openingHoursSpecification` | dziedziczone z `#place` albo własne | wszystkie | Dziś atrakcja nie ma godzin wcale. |

**5 nowych.**

### 5.6 Sekcja 5 — Encje podrzędne (repeater)

Dziś wiersz ma trzy pola: typ, nazwa, opis. Dochodzą trzy:

| Właściwość | Pole | Po co |
|---|---|---|
| `url` | tekst | Podstrona opisująca ten obiekt. |
| `telephone` | tekst | Osobny numer do restauracji w hotelu. |
| `image` | URL | |

Świadomie **nie** rozbudowuję tego dalej. Repeater z dziesięcioma polami
w wierszu przestaje być czytelny, a od szczegółów jest edytor węzłów.

### 5.7 Sekcja 6 — Treść (sterowanie, nie pola)

`WebPage`, `BlogPosting`, `FAQPage`, `Product` są **wyprowadzane**. Sekcja
zostaje tym, czym jest dziś — listą przełączników „Aktywne bloki JSON-LD" —
z dwoma wyjątkami, które są globalne mimo dotyczenia produktów:

| Właściwość | Pole | Po co |
|---|---|---|
| `offers.shippingDetails` | koszt + czas wysyłki | Google wymaga tego do listingu zakupowego, a w WooCommerce siedzi to w ustawieniach sklepu, nie na produkcie. |
| `offers.hasMerchantReturnPolicy` | okno zwrotu w dniach + kto płaci | Jw. Wartość jednakowa dla całego sklepu, więc **jest** globalna. |

Reszta produktu (`brand`, `gtin`, `mpn`) należy do produktu i wchodzi razem
z metaboksem, nie teraz.

### 5.8 Sekcja 7 — Edytor węzłów

Ujście dla wszystkiego, czego nie ma na liście — i dla tego, co schema.org
doda w przyszłości.

- Wiersz: **węzeł** (select: `#website`, `#organization`, `#place`,
  `#attraction`) + **klucz** + **wartość**.
- Klucz z `datalist` znanych właściwości **dla wybranego węzła**.
- Klucz spoza listy **wolno wpisać** — z ostrzeżeniem obok pola, nie z błędem
  blokującym zapis. Tak brzmi decyzja 3.
- Wartość zaczynająca się od `{` albo `[` i parsująca się jako JSON idzie
  do grafu jako struktura; reszta jako tekst. Bez tego nie da się dopisać
  niczego zagnieżdżonego.
- Klucz nie może zaczynać się od `@` — `@id` i `@type` są własnością modułu
  i nadpisanie ich rozspaja graf.

### 5.9 Podgląd i walidacja

- **Podgląd JSON-LD** — zwijane pole pod sekcją 7, pokazujące graf strony
  głównej dla obecnych ustawień. Odpowiada na „czy to, co wpisałem, w ogóle
  dochodzi do wyjścia".
- **Link do Rich Results** — przycisk otwierający test Google dla adresu
  witryny. Działa tylko dla stron publicznie dostępnych i tak trzeba to
  podpisać, żeby nie zgłaszano „przycisk nie działa" z `localhost`.

---

## 6. Ile tego jest

| Sekcja | Dziś | Dochodzi | Razem |
|---|---:|---:|---:|
| Witryna | 5 | 5 | 10 |
| Organizacja | 10 | 17 | 27 |
| Miejsce — wspólne | 14 | 10 | 24 |
| Miejsce — branżowe | 0 | 6–26* | — |
| Atrakcja | 6 | 5 | 11 |
| Encje podrzędne | 3 | 3 | 6 |
| Treść | — | 2 | 2 |
| **Suma pól panelu** | **38** | **≈48** | **≈86** |

\* Zależnie od presetu użytkownik widzi 0–6 pól branżowych; w kodzie definicji
jest ich 26 na wszystkie presety łącznie.

Do tego edytor węzłów, którym da się dopisać cokolwiek — więc „wszystkie
możliwe opcje" z zadania są spełnione **dwutorowo**: kilkadziesiąt pól
prowadzonych za rękę plus furtka na resztę schema.org.

---

## 7. Wpływ na zapis

Dziś `evk_schema` to jedna płaska opcja; kilka pól trzyma JSON w stringu.
Czterdzieści osiem nowych kluczy w tej samej płaskiej przestrzeni zrobi
bałagan i rozjedzie się z sanityzacją.

**Propozycja:** zostajemy przy jednej opcji i płaskich kluczach — bo
`sanitize_settings()` jest na tym zbudowane i działa — ale **z prefiksem
węzła**:

```
site_alternate_name      → #website
org_knows_about          → #organization
org_vat_id
place_checkin_time       → #place
place_special_hours
attr_tourist_type        → #attraction
```

Trzy powody:

1. Sanityzacja może iść **pętlą po prefiksie**, zamiast wymieniać każdy klucz
   z osobna. Dzisiejsza lista `$texts` ma czternaście pozycji wpisanych ręcznie;
   przy sześćdziesięciu to gwarantowane przeoczenie.
2. Prefiks mówi, do którego węzła pole trafia — to samo, co mówi sekcja
   w panelu. Jedna prawda, dwa miejsca.
3. **Metaboks per wpis, gdy przyjdzie, nadpisze te same klucze** bez zmiany
   kształtu zapisu. Wystarczy scalić `post_meta` na `get_settings()`.

Pola powtarzalne (`contactPoint`, `photo`, `hasOfferCatalog`, edytor węzłów)
zostają JSON-em w stringu — tak samo jak dzisiejsze `sub_entities`.

---

## 8. Kolejność wydań

Jedno wydanie na to nie wystarczy — byłby diff nie do przejrzenia, a siatka
regresyjna straciłaby sens (nie odróżniłbym zamierzonej zmiany od zepsucia).

| # | Wydanie | Zawartość | Ryzyko |
|---|---|---|---|
| 1 | **Prefiksy i pętla sanityzacji** | Przebudowa zapisu **bez ani jednego nowego pola**. Wyjście grafu musi zostać **bit w bit takie samo** — pliki wzorcowe nie drgną. | Największe. Dlatego idzie pierwsze i osobno: jeśli coś się rozjedzie, wiadomo, że to zapis, a nie nowe pole. |
| 2 | **`knowsAbout` + Organizacja** | Pozycja nr 1 z listy zgłaszającego plus pozostałe 16 pól sekcji 2. | Małe — same dopiski do jednego węzła. |
| 3 | **Układ wg węzła + presety** | Przemeblowanie zakładki na siedem sekcji, pasek presetu, pola branżowe. | Średnie, ale wyłącznie w panelu — graf bez zmian poza polami branżowymi. |
| 4 | **Miejsce, atrakcja, encje** | Sekcje 3, 4, 5. | Małe. |
| 5 | **Edytor węzłów + podgląd** | Sekcja 7 i walidacja. | Średnie — nowy mechanizm wstrzykiwania do grafu. |

Każde wydanie kończy się zielonym `node tests/run.js schema` i mutacjami na
dopisanych sprawdzeniach.

---

## 9. Czego ta rozpiska nie rozstrzyga

Uczciwie, żeby nie wyszło przy kodzie:

- **Nazwy presetów po polsku** są moje. Jeśli masz własne nazewnictwo dla
  branż klientów, powiedz teraz — potem będzie w trzech miejscach.
- **`hasOfferCatalog` w dwóch sekcjach** (organizacja i usługi profesjonalne).
  Może powinno być tylko w jednej; nie umiem tego rozstrzygnąć bez przykładu
  realnego klienta.
- **`specialOpeningHoursSpecification`** wymaga własnego parsera dat, podobnego
  do `parse_opening_hours()`. To jedyne pole z tej listy, które jest robotą
  na pół dnia, a nie na dziesięć minut. Jeśli ma odpaść, to teraz.
- **Kolejność 1 przed 2.** Przebudowa zapisu bez widocznego efektu to wydanie,
  po którym nic nie widać w panelu. Jeśli wolisz najpierw zobaczyć
  `knowsAbout`, można odwrócić — kosztem trudniejszego diffu w wydaniu 2.
