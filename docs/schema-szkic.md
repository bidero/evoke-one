# Schema — stan i szkic pracy

Notatka przekazująca do osobnej rozmowy. Spisana 2026-09-09, przy wersji
**1.161.1**. Zawiera stan zastany, a nie plan — plan wymaga najpierw decyzji,
których nie da się podjąć za zgłaszającego (lista pytań na końcu).

> **Aktualizacja 1.162.0.** Pytania z końca zostały rozstrzygnięte (patrz
> „Decyzje" niżej), a siatka regresyjna z punktu 1 — napisana. Przy okazji
> wyszły dwie usterki w module; obie opisane niżej i zapisane w siatce jako
> stan zastany. Sekcja „Czego NIE ma" jest zachowana jako zapis tego, jak
> było — nie jest już prawdziwa.

## Zadanie

Trzy rzeczy, słowami zgłaszającego:

1. **`knowsAbout`** — dodać.
2. **Inne, najlepiej wszystkie możliwe opcje schema** — dodać i **jakoś je
   uporządkować**. Bez uporządkowania to jest główny problem, nie brak pól:
   zakładka ma dziś 8 sekcji ułożonych pod jeden typ działalności.
3. **Usunąć placeholdery po PTTK Ukta.**

## Co już jest

### Pliki

| Plik | Wierszy | Rola |
|---|---:|---|
| `includes/90-schema.php` | 893 | klasa `EVK_Schema` — ustawienia, sanityzacja, budowanie grafu |
| `includes/admin/seo/tab-schema.php` | 185 | zakładka w panelu |
| `tests/php/tab.php` (scenariusz `schema`, l. 389+) | — | atrapa ustawień do renderu zakładki |

### Budowa grafu

`render_graph()` (l. 234) skleja `@graph` z metod `build_*`:

`build_website` · `build_organization` · `build_breadcrumbs` · `build_article` ·
`build_webpage` · `build_product` · `build_place` · `build_attraction` ·
`build_sub_entities`

plus pomocnicze: `build_address`, `build_geo`, `has_place`,
`parse_opening_hours`, `extract_faq` (czyta akordeon Bricksa),
`auto_detect_socials` (przeszukuje menu, gdy pole puste).

### Bloki włączane w panelu (`$blocks`, `tab-schema.php` l. 161)

`WebSite` · `Organization` · `BreadcrumbList` · `WebPage` ·
`BlogPosting (wpisy)` · `FAQPage (Bricks accordion)` · `Product (WooCommerce)` ·
`TouristAttraction`

### Listy typów

- `EVK_Schema::org_types()` (l. 72) — **26 pozycji**, od `Organization` po
  `HomeAndConstructionBusiness`.
- `EVK_Schema::sub_entity_types()` (l. 106) — **11 pozycji**, wyłącznie podtypy
  `Place`, żeby dały się powiązać przez `containedInPlace`.

### Klucze ustawień (z `sanitize_settings`, l. 130)

- **checkboxy:** `block_website`, `block_org`, `block_breadcrumb`,
  `block_webpage`, `block_article`, `block_faq`, `block_product`,
  `block_attraction`
- **`org_type`** — sprawdzany wobec `org_types()`, spoza listy → `Organization`
- **teksty:** `operator_name`, `site_name`, `telephone`, `email`,
  `street_address`, `locality`, `postal_code`, `country`, `favicon_url`,
  `contact_type`, `geo_lat`, `geo_lng`, `price_range`, `attraction_name`
- **współrzędne:** przecinek → kropka, niebędące liczbą → puste
- **`has_map`:** `esc_url_raw`
- **wieloliniowe:** `amenities`, `opening_hours`, `area_served`
- **JSON-y:** `descriptions`, `social_links`, `lang_currencies` — przy błędzie
  parsowania wracają do domyślnych
- **`evk_schema_desc[]`** — opisy per język, z osobnych pól formularza
- **`evk_schema_sub[]`** — repeater encji podrzędnych
- **`enabled`** — przez AJAX toggle, `evk_preserve_toggle()`

## Placeholdery po PTTK — pełna lista

> **Zrobione w 1.170.0.** Wszystkie usunięte, plus dwa miejsca, których ta
> lista nie obejmowała: opis „Jak to działa" przy encjach podrzędnych (mówił
> o portalach turystycznych) i podpowiedź nazwy encji. Pilnuje tego teraz
> sprawdzenie w `drobiazgi` przeszukujące całe `includes/`.

| Plik | Wiersz | Treść |
|---|---:|---|
| `includes/admin/seo/tab-schema.php` | 63 | `np. Stanica Wodna PTTK Ukta` |
| `includes/admin/seo/tab-schema.php` | 64 | `np. PTTK Oddział Mazurski` |
| `includes/admin/seo/tab-schema.php` | 88 | `Mazury / Puszcza Piska / Krutynia` |
| `includes/admin/seo/tab-schema.php` | 95 | `np. Stanica Wodna PTTK Ukta nad rzeką Krutynią` |
| `includes/admin/seo/tab-schema.php` | 25 | `Nazwa, np. Wypożyczalnia kajaków` |
| `includes/admin/seo/tab-schema.php` | 86 | `Spływy kajakowe / Pole namiotowe / Sauna` |
| `tests/php/tab.php` | 398 | `{"type":"Service","name":"Spływy","description":"Krutynia"}` |

Dwa ostatnie wiersze zakładki nie mówią wprost „PTTK", ale są z tej samej
realizacji. Wiersz w `tests/php/tab.php` to atrapa testowa — do zmiany razem
z resztą, żeby nie została jedyną wzmianką.

## Czego NIE ma — i to jest najważniejsza rzecz w tej notatce

> **Nieaktualne od 1.162.0** — siatka istnieje: `tests/php/schema-graf.php`
> (10 scenariuszy) + `tests/schema-graf.test.js` (84 sprawdzenia, 1,3 s),
> 25 mutacji, wszystkie zapaliły. Poniższy opis zostaje jako zapis stanu,
> od którego zaczynaliśmy.

**Wyjście JSON-LD nie ma ani jednego sprawdzenia.** W 51 plikach testowych
(3007 sprawdzeń) nie występuje `@graph`, `application/ld+json` ani nic, co
czytałoby wygenerowany graf. Pokryte jest wyłącznie **renderowanie zakładki**
(`tests/admin-tabs.test.js`) i obecność modułu na ekranie startowym
(`tests/panel-start.test.js`).

Znaczy to, że moduł wstawiający dane strukturalne do `<head>` każdej podstrony
jest dziś zmieniany bez siatki bezpieczeństwa. Przy zadaniu, które ma **dodać
wszystkie możliwe opcje**, to jest pierwsza rzecz do zrobienia, a nie ostatnia:
inaczej nie da się odróżnić „dodałem pole" od „dodałem pole i zepsułem graf".

Proponowana kolejność:

1. Sprawdzenia na **dzisiejszym** wyjściu — graf dla kilku konfiguracji,
   porównywany jako struktura, nie jako tekst. To jest siatka regresyjna:
   ma zapalić, gdy przebudowa zmieni cokolwiek, czego zmienić nie chciałem.
2. Dopiero potem porządkowanie zakładki i nowe pola.

Wzorzec dla punktu 1 jest w repozytorium: `tests/php/*.php` uruchamiają
prawdziwy PHP wtyczki i oddają wynik do Node'a (`phpOutput()` w
`tests/lib/harness.js`). `tests/php/tab.php` robi już dokładnie to dla zakładki
Schema — brakuje bliźniaka dla `render_graph()`.

**Uwaga bezpieczeństwa:** każdy nowy plik w `tests/php/` musi zaczynać się od
`if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }` — pliki testowe
jadą na żywe strony razem z wtyczką przez updater i bez tego byłyby osiągalne
przez HTTP.

## Decyzje (2026-09-09)

Odpowiedzi na pytania, które stały niżej. Zostawiam je w oryginalnym
brzmieniu pod spodem — bez pytania decyzja nie mówi, czego dotyczy.

| Pytanie | Rozstrzygnięcie |
|---|---|
| 1. Zakres | **(a) + (c)**: pełne właściwości typów, które wtyczka już generuje, **oraz** generyczny edytor węzłów. **Bez (b)** — żadnych Event / Recipe / Course / JobPosting. |
| 2. Uporządkowanie | Sekcje wg **typu węzła**, a nad nimi wybór **branży**, który rozwija i zwija właściwe sekcje oraz podpowiada wartości. Jedna prawda o strukturze, prowadzenie za rękę na wierzchu. |
| 3. Edytor pól | Datalist ze znanymi właściwościami dla wybranego węzła, ale **własny klucz dozwolony** — z ostrzeżeniem, nie z błędem. |
| 4. Per-wpis czy globalnie | **Wszystko globalne.** Metaboks per wpis to osobne zadanie na później. |
| 5. `knowsAbout` | **Jedno pole**, jedna pozycja na linię. Linia zaczynająca się od `http` → `{"@type":"Thing","@id":URL}`, reszta → zwykły tekst. |
| 6. Walidacja | **Podgląd JSON-LD w zakładce** + **link do Google Rich Results**. Bez listy ostrzeżeń „LodgingBusiness bez adresu" — na razie. |
| Kolejność | Najpierw siatka regresyjna, potem zmiany. |

**Napięcie do zapamiętania:** skoro metaboksu nie ma, generyczny edytor
dopisuje właściwości **globalnie** — ta sama wartość poleci w `<head>` każdej
podstrony. Dla `knowsAbout` czy `foundingDate` to w porządku; dopisanie
`datePublished` do WebPage zrobi tę samą datę wszędzie. Zapis ma być zrobiony
tak, żeby metaboks dało się dołożyć bez przepisywania go od nowa.

## Usterki znalezione przy pisaniu siatki

> **Obie naprawione w 1.169.0.** Sprawdzenia stanu zastanego zapaliły przy
> naprawie — po to tam były — i zostały odwrócone na normalne, z kontrolami
> w obie strony. Przy okazji wyszło, że `publisher` wisiał w **dwóch**
> miejscach, nie w jednym: `build_article()` ustawiał go tak samo
> bezwarunkowo jak `build_website()`, a scenariusz `bez-org` był stroną, więc
> `BlogPosting` w nim nie powstawał i drugie miejsce było niewidoczne.
> Scenariusz jest teraz wpisem.

Opis stanu sprzed naprawy, zostawiony dla kontekstu:

1. **FAQPage nie powstaje nigdy.** `extract_faq()` (l. 542) szuka akordeonu
   przez `array_walk_recursive` z warunkiem `$key === 'items' && is_array($value)`.
   `array_walk_recursive` nie podaje tablic do callbacka — wchodzi w nie
   i podaje wyłącznie liście, więc warunek nie może być prawdziwy nigdy.
   Blok jest włączony domyślnie i opisany w panelu jako działający.
2. **`WebSite.publisher` wskazuje donikąd** przy odhaczonym bloku
   Organization — układ osiągalny jednym kliknięciem. Wskazanie na
   nieistniejący węzeł jest poprawnym JSON-em, więc nie zauważy go ani
   parser, ani oko.

## Pytania do rozstrzygnięcia przed pisaniem kodu

*Rozstrzygnięte — patrz tabela wyżej. Zostawione dla kontekstu.*

Bez odpowiedzi na nie „wszystkie możliwe opcje" nie ma granicy — schema.org ma
ponad 800 typów i kilka tysięcy właściwości.

1. **Zakres.** Czy chodzi o (a) wszystkie właściwości typów, które wtyczka już
   generuje, (b) dodanie kolejnych typów obiektów (Event, Recipe, Course,
   JobPosting, VideoObject, HowTo…), czy (c) edytor pozwalający wpisać dowolną
   właściwość dowolnemu węzłowi?
2. **Uporządkowanie.** Dzisiejsze 8 sekcji jest ułożone pod jeden typ
   działalności. Czy zakładka ma się układać wg **typu węzła** (Organization /
   Place / WebPage / …), wg **branży** (wybieram „hotel" i dostaję komplet pól),
   czy zostać płaska z wyszukiwarką pól?
3. **Elastyczny edytor pól** — był na liście od dawna. Czy to znaczy „dowolna
   para klucz→wartość dopisywana do wybranego węzła", czy „lista znanych
   właściwości z podpowiedziami i walidacją"? Pierwsze jest tanie i pozwala
   wpisać bzdury; drugie wymaga wbudowanego słownika schema.org.
4. **Per-wpis czy globalnie?** Dziś wszystko jest globalne (jedne ustawienia na
   stronę). Czy `knowsAbout`, autorzy, `Event` itp. mają być ustawiane osobno
   dla podstrony/wpisu — bo to zmienia całą architekturę zapisu.
5. **`knowsAbout` konkretnie** — lista tekstów, czy lista URL-i do encji
   (Wikipedia/Wikidata)? Google czyta oba, ale drugie jest mocniejsze.
6. **Walidacja.** Czy chcesz podgląd wygenerowanego JSON-LD w panelu i/lub
   przycisk „sprawdź w Google Rich Results"?

## Zasady pracy, które obowiązują dalej

- Rozmowa **po polsku**, do zgłaszającego w liczbie pojedynczej.
- **Pytać jak najwięcej** o szczegóły.
- **Żadnej optymalizacji bez pomiaru.**
- **Każde sprawdzenie dowiedzione mutacją**: po dopisaniu sprawdzenia zepsuć
  kod naumyślnie i potwierdzić, że zapala. Mutacja, która przechodzi na
  zielono, kończy się przepisaniem sprawdzenia **albo uproszczeniem kodu**.
- Wydania na gałęzi `claude/evoke-one-functionality-vssnl3`, potem scalenie
  fast-forward do `main` i push obu. Numer wersji w trzech miejscach
  (`evoke-one.php` × 2 + `CHANGELOG.md`) — pilnuje tego `tests/drobiazgi.test.js`.
- Cel projektu: **mniej wtyczek zewnętrznych**.

## Uruchamianie testów

```
node tests/run.js                 # wszystko: 52 pliki, 3091 sprawdzeń, ~12 min
node tests/run.js schema          # siatka grafu: 84 sprawdzenia, 1,3 s
node tests/run.js schema admin-tabs   # graf + zakładka: ~32 s
```

Filtr dopasowuje **fragment nazwy pliku**, można podać kilka.

**Zmierzone**, bo pytanie „czy da się odpalać w obrębie zmiany" wraca:

| Co | Czas |
|---|---:|
| start i zamknięcie Chromium (stały narzut runnera) | 0,17 s |
| `php tests/php/schema-graf.php <scenariusz>` | 0,04 s |
| `node tests/run.js schema` — 84 sprawdzenia | **1,3 s** |
| `node tests/run.js admin-tabs` — 762 sprawdzenia | 32 s |
| pełny zestaw | ~12 min |

Dwanaście minut to nie PHP, tylko przeglądarka: fixtury animacji czekają po
450 ms każda i tych czekań są setki. Graf jest czystym PHP — `phpOutput()`
odpala CLI i oddaje tekst Node'owi — więc siatka grafu nie potrzebuje
przeglądarki wcale. **W trakcie pracy nad grafem `node tests/run.js schema`
jest praktycznie darmowe.** Pełny zestaw przed commitem wydania — łapie
sprzężenia między modułami, których sekcja z definicji nie widzi.

Pułapka do 1.161.1: `node tests/run.js schema` kończyło się komunikatem
„Brak testów pasujących", bo filtr patrzy na nazwę pliku `.test.js`, a żaden
nie miał w nazwie „schema". Polecenie z tej notatki nie robiło nic.
