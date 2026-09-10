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
| **`knowsAbout`** ✅ | textarea, jedna pozycja na linię | wszystkie | **Pozycja nr 1 z listy zgłaszającego.** Linia od `http` → `{"@type":"Thing","@id":URL}`, reszta → tekst. |
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
| `contactPoint` ✅ | **repeater** | wszystkie | Dziś jeden, wyprowadzany z telefonu. Realnie firmy mają osobne numery do sprzedaży, wsparcia i rezerwacji — a `contactType` to właśnie rozróżnia. |
| `faxNumber` | tekst | wszystkie | Kancelarie i przychodnie nadal go mają. |
| `nonprofitStatus` ✅ | select | organizacja | Fundacje i stowarzyszenia. |
| `hasOfferCatalog` ✅ | repeater nazw usług | usługi, zdrowie | Lista usług bez wchodzenia w `Service` jako osobny typ. |

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

> **KOREKTA po wydaniu 1.173.0.** Ta sekcja była pisana z pamięci schema.org
> i przy pisaniu kodu okazała się częściowo błędna. Zweryfikowane wobec
> hierarchii typów, poprawki niżej:
>
> | Co | Jak było | Jak jest |
> |---|---|---|
> | Preset „zdrowie i uroda" | jeden preset na 4 typy | **rozbity na dwa.** `MedicalBusiness` i `Dentist` mają `medicalSpecialty`; `BeautySalon` i `HairSalon` idą przez `HealthAndBeautyBusiness` i tej właściwości NIE mają. Jeden preset dawałby fryzjerowi pole „specjalizacja medyczna" — dokładnie błąd, przed którym presety mają bronić. Presetów jest więc **9, nie 8**. |
> | `serviceArea` | preset usługi | **usunięte** — schema.org zastąpiło je przez `areaServed`, które już mamy na organizacji i miejscu. |
> | `hasDeliveryMethod` | preset sklep | **usunięte** — nie istnieje na `Store`; dziedzina to `Order`, `ParcelDelivery`, `DeliveryChargeSpecification`. |
> | `isAcceptingNewPatients` | preset zdrowie | **usunięte** — dziedzina to `Physician`, nie `MedicalBusiness`/`Dentist`. |
> | `availableService` | preset zdrowie | **usunięte** — `MedicalClinic`, `Hospital`, `Physician`; nie plain `MedicalBusiness`. |
> | `sport` | preset sport | **usunięte** — nie ma tej właściwości na `SportsActivityLocation`. |
> | `knowsLanguage` | preset usługi | **usunięte** — dubluje `contactPoint.availableLanguage`, które moduł już wyprowadza. |
> | `hasOfferCatalog` | w dwóch sekcjach naraz (pytanie otwarte w sekcji 9) | **rozstrzygnięte: na `#organization`.** Opisuje ofertę FIRMY, nie zawartość budynku. |
> | Przełącznik branży | osobny sterownik nad sekcjami | **preset wynika z `org_type`.** Dwa sterowniki dla jednej rzeczy dają się rozjechać — „Hotel" plus branża „gastronomia" — i wtedy panel pokazuje pola, których typ nie ma. Skoro preset ma być mechanizmem poprawności, nie może dać się ustawić wbrew typowi. |
>
> Zostało **11 pól branżowych na `#place`** i **2 na `#organization`**, zamiast
> zapowiadanych 26. Presety `sklep`, `firma-lokalna`, `uroda` i `sport` nie mają
> własnych pól — i to jest w porządku: ich rolą jest **chować** pola noclegowe
> i gastronomiczne, a nie dokładać własne.

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

> **Zrobione w 1.171.0 — z jedną zmianą wobec propozycji niżej.**
> Prefiksów **nie** wprowadziłem dla kluczy, które już istnieją. Powód wyszedł
> przy pisaniu kodu: te klucze siedzą w bazach żywych stron, a przemianowanie
> ich to migracja cudzych danych — klasa zmian, która psuje się po cichu
> (klient traci NIP i dowiaduje się po pół roku). Wszystko, co miał dawać
> prefiks — pętla sanityzacji i mapowanie na węzeł — daje **kolumna `wezel`
> w rejestrze `EVK_Schema::pola()`**, bez dotykania czegokolwiek zapisanego.
> Pola dokładane od wydania 2 dostają prefiksy od razu, bo tam nie ma czego
> migrować.
>
> Doszła też warstwa, której ta sekcja nie przewidywała: `get_settings($post_id)`
> scala domyślne → globalne → **meta wpisu `_evk_schema`**. Metaboks nie
> istnieje, ale mechanizm tak — i jest sprawdzony, więc dopisanie interfejsu
> nie będzie odkrywaniem, czy to w ogóle działa.

**Propozycja (nieaktualna w części o prefiksach):** zostajemy przy jednej
opcji i płaskich kluczach — bo `sanitize_settings()` jest na tym zbudowane
i działa — ale **z prefiksem węzła**:

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
| 1 ✅ | **Rejestr i pętla sanityzacji** (1.171.0) | Przebudowa zapisu **bez ani jednego nowego pola**. Wyjście grafu musi zostać **bit w bit takie samo** — pliki wzorcowe nie drgną. | Największe. Dlatego idzie pierwsze i osobno: jeśli coś się rozjedzie, wiadomo, że to zapis, a nie nowe pole. |
| 2 ✅ | **`knowsAbout` + Organizacja** (1.172.0) | Pozycja nr 1 z listy zgłaszającego plus pozostałe 16 pól sekcji 2. | Małe — same dopiski do jednego węzła. |
| 3 ✅ | **Układ wg węzła + presety** (1.173.0) | Przemeblowanie zakładki na siedem sekcji, pasek presetu, pola branżowe. | Średnie, ale wyłącznie w panelu — graf bez zmian poza polami branżowymi. |
| 4 ✅ | **Miejsce, atrakcja, encje** (1.174.0) | Sekcje 3, 4, 5. | Małe. |
| 5 ✅ | **Edytor węzłów + podgląd** (1.176.0) | Sekcja 7 i walidacja. | Średnie — nowy mechanizm wstrzykiwania do grafu. |

Wszystkie pięć wydań są zrobione. Co z rozpiski **weszło inaczej, niż tu
napisano**, i dlaczego:

| Punkt rozpiski | Jak weszło |
|---|---|
| prefiksy w nazwach kluczy (§ 7) | **odrzucone** — klucze siedzą w bazach żywych stron; kolumna `wezel` w rejestrze daje to samo bez migracji |
| osobny przełącznik branży nad sekcjami (§ 4) | **odrzucone** — preset wynika z typu działalności; dwa sterowniki dla jednej rzeczy dają się rozjechać |
| „zdrowie i uroda" jako jeden preset (§ 5.4) | **rozdzielone** — `BeautySalon` nie ma `medicalSpecialty`, więc jeden preset dawałby fryzjerowi pole „specjalizacja medyczna" |
| `medicalSpecialty`/`nonprofitStatus` jako `select` (§ 8a) | **`datalist`** — wyliczenia schema.org są rozszerzane, zamknięta lista blokowałaby prawidłową wartość |
| dwa węzły zawsze (§ 5.2, § 5.3) | **zależnie od operatora** (1.175.0) — patrz § 8b |
| edytor obejmuje `#webpage` i dalsze | **cztery węzły globalne** — reszta jest per podstrona i dopisana globalnie siadałaby na każdej stronie serwisu |

Poza planem weszło **1.175.0 — scalenie organizacji z obiektem**, z użycia:
pierwsza strona wypełniła zakładkę prawdziwymi danymi i pokazała, że przy
jednej firmie graf robi dwa węzły o tej samej nazwie. Nie dało się tego
zobaczyć na danych testowych, bo w scenariuszach nazwa obiektu i nazwa
operatora były różne. Opis w § 8b.

Każde wydanie kończy się zielonym `node tests/run.js schema` i mutacjami na
dopisanych sprawdzeniach.

---

## 8a. Audyt zgodności ze schema.org (1.173.0)

Po zbudowaniu presetów przeszedłem każdą właściwość na każdym typie, który
moduł emituje — 34 typy, ~110 par typ↔właściwość, wyliczonych z prawdziwego
wyjścia `render_graph()`.

| Znalezisko | Stan |
|---|---|
| `BlogPosting.breadcrumb` — dziedzina tej właściwości to **wyłącznie `WebPage`** | **usunięte**; okruszki i tak są w grafie dwa razy |
| `medicalSpecialty`, `nonprofitStatus` — oczekują wyliczeń, przyjmują wolny tekst | opisy w panelu wskazują słownik; zamknięcie w select → wydanie 5 |
| `latitude`/`longitude` jako tekst | schema.org dopuszcza `Number` albo `Text` — zgodne, zostawione |
| 26 typów działalności, 11 typów encji podrzędnych | wszystkie istnieją; `containedInPlace` prawidłowe na każdym z jedenastu |
| 25 właściwości `#organization` | wszystkie prawidłowe |
| pola noclegowe i gastronomiczne | wszystkie na właściwych typach bazowych |

## 8b. Jeden węzeł czy dwa (1.175.0)

Rozpiska przez cały czas zakładała dwa węzły: `#organization` jako wydawcę
strony i `#place` jako obiekt. To założenie było **niesprawdzone** — brało
się z tego, że w każdym scenariuszu testowym nazwa obiektu różniła się od
nazwy operatora, więc dwa węzły wyglądały sensownie.

Na prawdziwych danych jednej firmy oba węzły dostają tę samą nazwę, a jeden
wskazuje drugi jako `parentOrganization`. Rozstrzyga teraz pole **„Nazwa
operatora"**: puste albo równe nazwie obiektu → **jeden** węzeł
`#organization` typu działalności; inna firma → **dwa** węzły, jak dotąd.

Poprawne, bo `LocalBusiness` dziedziczy i z `Organization`, i z `Place`.
Konsekwencje, wszystkie w kodzie:

- adres węzła obiektu liczy jedna metoda `miejsce_id()` — `about`,
  `containedInPlace` na atrakcji i na encjach podrzędnych biorą stamtąd;
- kolizje właściwości: `areaServed` sumuje się, `faxNumber` bierze wartość
  organizacji;
- odhaczony blok Organization wraca do dwóch węzłów (scalony nie miałby
  gdzie zamieszkać).

### Cztery zgłoszenia i czytanie wyjścia przez walidator

> **SPROSTOWANIE (1.176.0).** Stało tu, że te cztery znaleziska opisują
> cudzy JSON-LD. Nieprawda i warto wiedzieć dlaczego, bo to samo nieporozumienie
> wróci przy każdym następnym odczycie.

`SearchAction` na `WebPage`, `addressCountry` jako obiekt `Country`,
`dayOfWeek` w pełnych adresach schema.org, brak `@context` — **nic z tego moduł
nie emituje w źródle**. Ale to jest nasze wyjście: walidatory pokazują graf
**po rozwinięciu**, a rozwinięcie robi trzy rzeczy naraz.

| U nas w źródle | W odczycie z walidatora |
|---|---|
| `"publisher": {"@id": "…#organization"}` | cały węzeł organizacji, wklejony |
| `"about": {"@id": "…#organization"}` | ten sam węzeł, wklejony drugi raz |
| `"dayOfWeek": ["Monday", …]` | `http://schema.org/Monday` |
| `"addressCountry": "PL"` | `{"@type": "Country", "name": "PL"}` |
| `"query-input": "required name=search_term_string"` | węzeł `PropertyValueSpecification` |
| `"@context": "https://schema.org"` | rozwiązany i niewidoczny |
| `"postalCode": ""` | ukryte przy wyświetlaniu |

Stąd bierze się wrażenie, że blok danych firmy leci dwa razy. **Nie leci** —
`publisher` i `about` to po jednej linijce ze wskazaniem, i o to właśnie
chodzi w `@graph` spiętym przez `@id`. Sprawdzić można jednym ruchem: podgląd
źródła strony i szukanie `"publisher"`.

Ostatni wiersz tabeli był prawdziwym znaleziskiem: `build_address()` emitował
wszystkie cztery klucze niezależnie od wypełnienia, a walidator to ukrywał.
Naprawione w 1.176.0.

Wszystkie cztery kształty mają nazwane sprawdzenia w
`tests/schema-graf.test.js` — mówią o źródle i tam zostają.

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
