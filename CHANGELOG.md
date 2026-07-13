# Changelog — Evoke ONE

Format wg [Keep a Changelog](https://keepachangelog.com/), wersjonowanie [SemVer](https://semver.org/).

## [1.16.0] — 2026-07-14

### Naprawione

- **Newsletter — rola z uprawnieniem „Newsletter" (Role Manager) nie mogła nic
  zrobić.** Strona Newsletter była widoczna, ale każda akcja AJAX (lista
  subskrybentów, zapis list, szablonów, kampanie, import, raporty) wymagała
  `manage_options` i kończyła się błędem 403 — subskrybenci nie ładowali się,
  szablonów nie dało się zapisać. Teraz wspólny check AJAX akceptuje też
  `evk_access_newsletter` — jedno uprawnienie daje pełny dostęp do modułu.
  Uwaga: wstawianie obrazków do szablonów wymaga dodatkowo standardowego
  `upload_files` (do nadania w Role Managerze). (`includes/newsletter/ajax.php`)
- **Schema — WebPage nigdy nie pobierał tytułu z Bricksa.** Kod czytał klucz
  `metaTitle`, który w Bricksie nie istnieje (poprawny to `documentTitle`),
  więc tytuł zawsze spadał na tytuł wpisu. Teraz Schema używa wspólnego
  resolvera meta danych (patrz niżej). (`includes/90-schema.php`)

### Dodane

- **SEO — wspólny resolver meta danych** (`evk_seo_get_meta()`,
  `includes/85-seo.php`). Evoke ONE renderuje komplet meta tagów: tytuł, opis,
  słowa kluczowe, robots, og:title/type/url/site_name/description/image.
  Priorytet źródeł per strona:
  1. Bricks — Ustawienia strony → SEO / Media społecznościowe
     (`documentTitle`, `metaDescription`, `metaKeywords`, `metaRobots`,
     `sharingTitle`, `sharingDescription`, `sharingImage`; z fallbackiem na
     ustawienia aktywnego szablonu treści i renderowaniem dynamic data),
  2. zakładka SEO Evoke ONE (`_evoke_seo_*`),
  3. fallback automatyczny (tytuł strony; og:image: generator OG → obrazek
     wyróżniający).
  Z resolvera korzystają meta tagi, og:* oraz Schema (WebPage name/description
  i obrazki w grafie) — `<title>`, og:title i JSON-LD są zawsze spójne.
  Natywne meta tagi SEO/OG Bricksa są wyłączane filtrami
  (`bricks/frontend/disable_seo`, `bricks/frontend/disable_opengraph`),
  żeby nie dublować wpisów w `<head>`. `og:type` odzwierciedla kontekst
  (blog / article / website), doszedł `og:site_name`.
- **Schema — typ działalności i dane firmy lokalnej.** Blok Organization ma
  wybór `@type` z listy ~25 typów (LocalBusiness, LodgingBusiness, Hotel,
  Pensjonat/B&B, Pole namiotowe, Ośrodek wypoczynkowy, Restauracja, Kawiarnia,
  Sklep, Usługi profesjonalne, Placówka medyczna, Obiekt sportowy, Biuro
  podróży, Warsztat, Salon urody i in.). Dla typów innych niż „Organizacja"
  dostępne: współrzędne `geo` (GeoCoordinates), `priceRange` oraz udogodnienia
  `amenityFeature` (LocationFeatureSpecification, jedna linia = jedno
  udogodnienie). (`includes/90-schema.php`, `includes/admin/seo/tab-schema.php`)
- **Schema — blok TouristAttraction** (przełączany w „Aktywne bloki JSON-LD"):
  nazwa atrakcji (fallback: nazwa organizacji), opis per język, adres, geo,
  obrazek; przy typie firmy lokalnej powiązany z organizacją przez
  `containedInPlace`. WebPage dostał `about` wskazujące na organizację.
- **SEO — opis łańcucha priorytetów** w zakładce SEO → Meta (info box).

## [1.15.3] — 2026-07-12

### Zmienione

- Wydanie testowe updatera z tokenem do repo prywatnego — bez zmian
  funkcjonalnych względem 1.15.2 (tylko podbicie wersji).

## [1.15.2] — 2026-07-12

### Dodane

- **Aktualizacje z prywatnego repozytorium GitHub.** Updater obsługuje token
  (fine-grained PAT z uprawnieniem „Contents: Read"): stała
  `EVOKE_ONE_GITHUB_TOKEN` w `wp-config.php` (zalecane) lub opcja
  `evk_one_github_token`. Nagłówek Authorization doklejany też przy pobieraniu
  paczki aktualizacji (`http_request_args`). Bez tokenu prywatne repo zwraca
  404 i aktualizacje nie są widoczne. (`includes/99-github-updater.php`)

### Naprawione

- **Newsletter — import liczył duplikaty jako „dodane".** Po imporcie CSV
  komunikat pokazywał np. „dodano 22076", a lista miała 18477 subskrybentów —
  adresy powtórzone w pliku lub już obecne na liście były zliczane jako dodane
  (funkcja zwracała ID istniejącego wpisu). Teraz: „dodano" = wyłącznie nowe
  wpisy w bazie, duplikaty (w pliku i w bazie) idą do „pominięte"; duplikaty
  w obrębie pliku wykrywane bez odpytywania bazy. Dane w bazie były poprawne —
  błędny był tylko licznik. (`includes/newsletter/lists.php`)

## [1.15.1] — 2026-07-12

### Zmienione

- Wydanie testowe mechanizmu aktualizacji z GitHub — bez zmian funkcjonalnych
  względem 1.15.0 (tylko podbicie wersji).

## [1.15.0] — 2026-07-12

### Naprawione

- **Przełączniki modułów wyłączały się po kliknięciu „Zapisz".** Główna przyczyna:
  WordPress (`options.php`) przy zapisie formularza aktualizuje WSZYSTKIE opcje
  zarejestrowane w danej grupie ustawień — także te, których nie ma w formularzu
  (dostają `null`), a sanitizery zerowały wtedy pole `enabled` zarządzane przez
  AJAX toggle. Naprawione we wszystkich wariantach:
  - Nowy helper `evk_preserve_toggle()` (`includes/30-admin-settings-ajax.php`) —
    sanitizer zachowuje aktualny stan przełącznika, gdy klucza nie ma w POST.
    Zastosowany w: Parallax, OpenGraph, Dark Mode, Lenis, Dostępność, Kursor,
    Schema, SMTP, White Label, Skrzynka wiadomości.
  - Toggle w kartach statusu ujednolicone jako AJAX-only (usunięte `name=` —
    formularz nie nadpisuje już stanu przełącznika nieaktualną wartością).
  - **Konserwacja:** ukryte pole `maintenance_mode` w formularzu nadpisywało stan
    włącznika wartością z momentu załadowania strony; `maintenance_mode` nie jest
    już rejestrowany w grupie `evoke_one_maintenance` (zapis wyłącznie przez AJAX),
    usunięty też martwy mini-formularz w karcie statusu.
  - **Logi 404:** zapis ustawień (Maks. logów / boty) ZAWSZE wyłączał moduł —
    formularz nie przekazywał `evk_404_enabled`, a handler zerował brakujący klucz.
  - **Panel admina:** wspólna grupa `evoke_one_other` rozdzielona na
    `evoke_one_dashboard` i `evoke_one_content` — zapis zakładki „Treść" zerował
    wszystkie ustawienia Kokpitu Bricks (i odwrotnie).
  - **Interfejs:** usunięty niedomknięty, zagnieżdżony `<form>` grupy
    `evoke_one_other` (nieprawidłowy HTML, ryzyko zapisania złej grupy).
  - **Skrzynka wiadomości:** ukryte pole `enabled` z nieaktualną wartością usunięte
    z formularza.
  - **Przekierowania 301:** toggle strzelał podwójnie (AJAX + submit formularza);
    dodany brakujący nonce (CSRF) do przełącznika.
- **Schema:** usunięta zdublowana rejestracja `evk_schema` w `evoke-one.php`
  (nadpisywała argumenty rejestracji z `includes/90-schema.php`); sanitizer
  przyjmuje teraz bezpiecznie wartości nietablicowe.

### Dodane

- **Aktualizacje wtyczki z GitHub** (`includes/99-github-updater.php`) — wtyczka
  sprawdza najnowsze wydanie w `github.com/bidero/evoke-one` (Releases; fallback
  na tagi) i podpina je pod natywny mechanizm aktualizacji WP. Cache 6 h,
  link „Sprawdź aktualizacje" na liście wtyczek, popup „Zobacz szczegóły wersji",
  automatyczna zmiana nazwy folderu z zipballa GitHuba na `evoke-one`.
  Publikacja: podbij wersję → push → utwórz Release z tagiem `vX.Y.Z`.

## [1.14.4] — 2026-06-30

### Naprawione

- **Newsletter — zapis kampanii (i szablonu) wracał do zakładki „Listy".** Regresja z
  v1.14.2: w przekierowaniach JS użyto `esc_js(add_query_arg(...))`, a `esc_js()` zamienia
  `&` na `&amp;`. Po zapisie URL miał `&amp;subtab=…`, więc parametr `subtab` znikał i router
  wracał do domyślnej podzakładki (Listy). Zamieniono na `esc_url_raw()` (zostawia `&`
  dosłownie) w 3 miejscach: zapis kampanii, zapis szablonu, filtr zdarzeń w raportach.
  (`includes/admin/newsletter/tab-{campaigns,templates,reports}.php`)

## [1.14.3] — 2026-06-30

### Zmienione

- **Newsletter — śledzenie domyślnie włączone.** Nowa kampania ma checkbox „Śledzenie
  otwarć i kliknięć" zaznaczony (zgodnie z domyślną wartością DB `tracking_enabled = 1`).
  Edycja istniejącej kampanii nadal pokazuje jej rzeczywisty stan.
  (`includes/admin/newsletter/tab-campaigns.php`)
- **Newsletter — odświeżanie na tej samej zakładce po zapisie.** Zapis edytowanej kampanii
  lub szablonu przeładowuje teraz stronę na tej samej karcie (wcześniej tylko komunikat
  „Zapisano!" bez odświeżenia). (`includes/admin/newsletter/tab-{campaigns,templates}.php`)

## [1.14.2] — 2026-06-30

### Naprawione

- **Newsletter — klik w listę (kampanię/szablon/raport) wyrzucał do ustawień Evoke ONE.**
  Pełny panel renderuje się tylko w osobnej pozycji menu (`admin.php?page=evoke-newsletter`),
  ale pliki podzakładek budowały linki na sztywno do
  `options-general.php?page=evoke-one&tab=newsletter`. Wszystkie linki przepięte na
  dynamiczne `evk_nl_base_url()` — zostają w panelu (działa też przy lokalizacji menu
  „Ustawienia WordPress"). (`includes/admin/newsletter/tab-{lists,campaigns,reports,templates}.php`)

## [1.14.1] — 2026-06-30

### Naprawione

- **Scroll Reading — nie dało się ustawić koloru z poziomu Bricks.** `resolve_color()`
  czytało wyłącznie `hex`, więc kolory wybrane jako **zmienna Bricks** lub **globalny kolor**
  (zapisywane w `raw` / `rgb`, np. `var(--moj-kolor)`) były ignorowane i wracał fallback
  `#000`/`#aaa`. Teraz kolejność `hex → raw → rgb → fallback` (jak w `wave-bg`).
  (`includes/bricks-elements/evoke-scroll-reading/element.php`)
- **Scroll Reading — kolory ze zmiennych nie animowały się.** GSAP nie tweenuje koloru
  podanego jako `var(--x)`. Dodano `resolveColor()`, które rozwija zmienną przez
  `getComputedStyle` w kontekście elementu przed przekazaniem do GSAP.
  (`includes/bricks-elements/evoke-scroll-reading/assets/scroll-reading.js`)

## [1.14.0] — 2026-06-26

### Zmienione

- **White Label — mniej kart.** Trzy sąsiadujące akordeony „Pasek górny — widoczność /
  własne / kolejność węzłów" scalone w jeden **„Pasek górny — węzły"** z pod-nagłówkami
  (11 → 9 kart). Builder własnych pozycji i podmenu zostaje osobno (inne narzędzie).
  (`includes/admin/admin-whitelabel.php`)
- **Newsletter — koniec duplikacji UI.** Zakładka „Newsletter" w panelu to teraz **status +
  link** do osobnego panelu (jak Tłumaczenia). Pełny moduł (listy/szablony/kampanie/raporty)
  nadal renderowany w osobnej pozycji menu bocznego. (`includes/admin/tab-newsletter.php`)
- **Diakrytyki w module Tłumaczeń:** „Przeciagnij" → Przeciągnij, „Jezyki" → Języki,
  „nastepnie/jezykow" → następnie/języków, „Tlumaczenie obrazka" → Tłumaczenie obrazka,
  „Blad/polaczenia" → Błąd/połączenia, „odswiez" → odśwież, „wyswietlania" → wyświetlania,
  „Narzedzia" → Narzędzia, tooltip „Usun" → Usuń.

### Naprawione

- **Link „konfiguracja SMTP" w Newsletterze** wskazywał `subtab=smtp`, a zakładka Narzędzia
  czyta parametr `sub` → trafiał na domyślną podzakładkę. Poprawiono na `sub=smtp`.
  (`includes/admin/tab-newsletter.php`)

## [1.13.0] — 2026-06-26

### Dodane

- **Wspólna warstwa komponentów w `admin.css`** (spójność wszystkich modułów):
  - `.button-icon` (kwadrat 30×30, wariant `button-link-delete`) — wcześniej tylko w module
    Tłumaczeń; teraz globalny. Usunięto lokalną duplikację z `tl/render.php`.
  - Jednolite, zaokrąglone **próbniki koloru** (`input[type=color]`) zamiast natywnych swatchy —
    we wszystkich modułach (White Label, Dark Mode, Dostępność, OpenGraph).
  - Zdefiniowano zmienne `--evo-border` / `--evo-surface` (były **nieustawione** → 14 użyć
    leciało na off-token fallbacki `#e0e0e0`/`#f8f8f8`; teraz tokeny FIELDS).

### Zmienione

- **White Label — dopasowanie do design systemu:** szarości chrome (`#bbb`, `#666`, `#888`,
  `#999`, `#ddd`, `#444`, `#e0e0e0`, `#f8f8f8`, `#e8f0fe`) zmapowane na tokeny FIELDS;
  próbniki koloru w siatce wypełniają komórki eleganckimi pasami. (`includes/admin/admin-whitelabel.php`)
- **Newsletter** — ikon-buttony `border-radius` 4 → 7 (spójne z `.button-icon`).

## [1.12.1] — 2026-06-26

### Naprawione

- **Edytor kodu (CodeMirror) nie ładował się na stronie „Skrypty PHP".** Bramka enqueue
  w `includes/snippets/ajax.php` (linia 26) sprawdzała starą zakładkę `tab=other`, a snippety
  są pod `tab=narzedzia`. Sibling-bramka była już poprawna — ta jedna pozostała po przeniesieniu.
  Teraz pola snippetów dostają kolorowanie składni i inicjalizację edytora.

### Usunięte

- **`includes/admin/tab-other.php`** — osierocony plik (nigdzie nie `require`-owany) z błędem
  składni (`?>` w linii 8 zamykał PHP). Jedyny `php -l` error w repo — usunięty.

## [1.12.0] — 2026-06-26

### Zmienione

- **Reorganizacja i przemianowanie zakładek głównych** (panel `Evoke ONE`):
  - „Wydajność" → **Frontend** (nie zawierała nic o wydajności — to efekty frontu).
  - „Strona" → **SEO** (zawiera wyłącznie SEO).
  - „Admin" → **Panel admina**. „Wiadomości" → **Formularze**. Odświeżone ikony.
  - Klucze URL (`tab=wydajnosc`, `strona`, `admin_panel`, `forminbox`) **bez zmian** —
    istniejące linki/zakładki działają dalej. (`includes/admin/page.php`)
- **Przeniesienia podzakładek:**
  - **Konserwacja**: z „Frontend" → do **Narzędzia** (narzędzie operacyjne ON/OFF witryny).
    `tab-maintenance.php` jest teraz samowystarczalny (własne zmienne).
    (`includes/admin/tab-wydajnosc.php`, `tab-narzedzia.php`, `tab-maintenance.php`)
  - **Tłumaczenia** (włącznik modułu + edytor inline): z „Panel admina" → do **Frontend**
    (to funkcja frontu). (`includes/admin/tab-admin.php`, `tab-wydajnosc.php`)
- **Spolszczenie podzakładek Frontend:** „Dark Mode" → **Tryb ciemny**,
  „Smooth Scroll" → **Płynne przewijanie**. (`includes/admin/tab-wydajnosc.php`)

## [1.11.1] — 2026-06-26

### Zmienione

- **White Label — domyślny stan akordeonów.** Na starcie otwarta jest tylko pierwsza
  karta („Logo, branding i czcionka"), pozostałe 10 jest zwiniętych (wcześniej buildery
  startowały otwarte). (`includes/admin/admin-whitelabel.php`)

## [1.11.0] — 2026-06-26

### Dodane

- **White Label — akordeony.** Długa forma ustawień podzielona na 11 składanych kart
  (`<details>`) w stylu kart Evoke FIELDS: Logo/branding/czcionka, Pasek górny (wygląd,
  widoczność/własne/kolejność węzłów, własne pozycje i podmenu), Menu boczne, Kolory
  (menu+podmenu / sekcja główna), Ukryj elementy, Własny CSS. (`includes/admin/admin-whitelabel.php`)
- **Pole „Własny CSS admina" powiększone** — z `rows=5` na pole edytorskie (min. 320px,
  monospace, rozciągane w pionie). (`includes/admin/admin-whitelabel.php`)

### Zmienione

- **Ikony przy przyciskach w całym module Tłumaczeń** (jak w Evoke FIELDS) — „Dodaj
  frazę/grupę/język/slug/obrazek", „Zapisz…", „Eksportuj", „Rozwiń/Zwiń", „Wykryj slugi"
  dostały dashicony. (`includes/admin/tl/*.php`, `js-admin.php`)
- **Akcje wiersza jako ikon-buttony** — „Duplikuj/Usuń frazę", „Usuń" (język/slug/obrazek)
  zamienione z przycisków tekstowych na kwadratowe ikon-buttony 30×30 (trash na czerwono na
  hover) — spójne z akcjami grupy. (`includes/admin/tl/tab-translations.php`, `tab-languages.php`,
  `tab-slugs.php`, `tab-dd.php`, `tab-images.php`, `js-admin.php`)
- **Jaśniejsze linie pól** — globalny `border-color:#d1d5db` dla inputów/selectów/textarea
  (zamiast ciemnego WP `#8c8f94`), jak w Evoke FIELDS. (`assets/admin/admin.css`)

### Naprawione

- **Krzywe ikony w przyciskach** — usunięto inline-hacki (`margin-top:-20px`,
  `vertical-align`), które psuły centrowanie glifu; ikona jest teraz w jednej linii z tekstem.
  (`includes/admin/tab-io.php`, `includes/admin/tl/tab-io.php`, `includes/admin/other-tlumaczenia.php`)

## [1.10.0] — 2026-06-26

### Zmienione

- **Ujednolicenie wyglądu z Evoke FIELDS (design system).** Cały panel admina
  dopasowany do tego samego języka wizualnego co wtyczka Evoke FIELDS:
  akcent `#2563eb`/`#1d4ed8`, zaokrąglenia przycisków 7px, pól 6px, kart 10–12px,
  spójne ramki (`#d7dde7`/`#d1d5db`), niebieski focus ring `0 0 0 3px rgba(37,99,235,.12)`,
  ikony (dashicony) zawsze w jednej linii z tekstem przycisku.
- **Globalna warstwa stylów** w `assets/admin/admin.css` (scope `.wrap`) — harmonizuje
  przyciski, pola i karty na **wszystkich** ekranach Evoke ONE: Ustawienia, Tłumaczenia,
  Newsletter, Wiadomości. (`assets/admin/admin.css`)
- **Moduł Tłumaczenia — przebudowa stylów.** Usunięto resztki palety WordPress-admin
  (`#2271b1`, `#3858e9`, `#c3c4c7`, `#dcdcde`, `#50575e`…) i zdublowany blok „v3 polish".
  Zakładki to teraz pille EVK, karty/info-boxy/tabele/pola w tokenach FIELDS, zaokrąglenia
  3–4px podniesione do 6–10px, focus ring 1px → 3px. (`includes/admin/tl/render.php`
  oraz `tab-dd/tab-images/tab-io/tab-languages/tab-sitemap/tab-slugs/tab-translations.php`,
  `includes/admin/tl/js-admin.php`)
- **Frontendowy edytor tłumaczeń** (FAB + panel) przeniesiony na akcent Evoke `#2563eb`
  zamiast `#3858e9`. (`includes/41-frontend-inline-editor.php`)
- **Pozostałe moduły** (SEO, Newsletter, Bezpieczeństwo, White Label, OpenGraph,
  Narzędzia) — drobne kolory chrome (ramki/teksty/linki/błędy) zmapowane na tokeny FIELDS.
  W White Label **zachowano** domyślne wartości próbników kolorów (to dane funkcji = realne
  domyślne kolory menu WordPressa, nie chrome panelu).

### Uwagi

- `includes/admin/tab-other.php` jest plikiem osieroconym (nigdzie nie `require`-owany)
  i zawiera istniejący wcześniej błąd składni (`?>` w linii 8 zamyka PHP). Nie wpływa na
  działanie wtyczki; do decyzji: usunąć albo naprawić.
