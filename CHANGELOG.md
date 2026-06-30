# Changelog — Evoke ONE

Format wg [Keep a Changelog](https://keepachangelog.com/), wersjonowanie [SemVer](https://semver.org/).

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
