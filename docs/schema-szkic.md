# Schema — stan i szkic pracy

Notatka przekazująca do osobnej rozmowy. Spisana 2026-09-09, przy wersji
**1.161.1**. Zawiera stan zastany, a nie plan — plan wymaga najpierw decyzji,
których nie da się podjąć za zgłaszającego (lista pytań na końcu).

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

## Pytania do rozstrzygnięcia przed pisaniem kodu

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
node tests/run.js                 # wszystko: 51 plików, 3007 sprawdzeń, ~12 min
node tests/run.js schema          # tylko pasujące nazwą pliku
node tests/run.js admin-tabs panel-start
```

Filtr dopasowuje **fragment nazwy pliku**, można podać kilka. W trakcie pracy
wystarczy sekcja (~1–2 min); pełny zestaw przed commitem wydania — łapie
sprzężenia między modułami, których sekcja z definicji nie widzi.
