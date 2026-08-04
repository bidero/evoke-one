# Changelog — Evoke ONE

Format wg [Keep a Changelog](https://keepachangelog.com/), wersjonowanie [SemVer](https://semver.org/).

## [1.23.0] — 2026-08-04

### Dodane

- **Przełącznik „Sekcja w zakładce Style" (eksperymentalny, domyślnie wyłączony).**
  Przenosi sekcję „Evoke ONE" z zakładki Content do pionowego paska ikon po lewej,
  za grupy CSS i Attributes.

  Bricks **nie udostępnia klucza na ikonę grupy kontrolek** — publiczne API filtra
  `bricks/elements/{name}/control_groups` zna wyłącznie `tab` i `title`, a `$icon`
  dotyczy elementów, nie grup. Ikonę podkłada więc CSS wstrzykiwany wyłącznie
  w oknie buildera, celujący w `li[data-balloon="Evoke ONE"]`. Warunek: Bricks
  musi w ogóle wyrenderować grupę z filtra w pasku — czego nie da się sprawdzić
  bez żywej instalacji. Stąd przełącznik zamiast zmiany na sztywno: gdyby sekcja
  zniknęła z panelu, odznaczenie natychmiast przywraca działający stan.
  (`includes/anim/animator.php`, `includes/anim/bricks-controls.php`,
  `includes/admin/tab-animator.php`)

## [1.22.2] — 2026-08-04

### Naprawione

- **Kontrolki zniknęły z buildera po 1.22.1.** Przeniesienie grup do zakładki Style
  (`'tab' => 'style'`) sprawiło, że przestały się renderować w ogóle. Przyczyna:
  w Bricks 2.x zakładka Style pokazuje grupy kontrolek jako **pionowy pasek ikon** —
  każda grupa to pozycja z SVG i tooltipem. Grupa dodana filtrem nie ma ikony
  (publiczne API filtra `bricks/elements/{name}/control_groups` zna wyłącznie
  `tab` i `title`), więc w pasku powstaje pusty, niewidoczny element. Powrót do
  zakładki Content — jedynego wariantu potwierdzonego na żywej instalacji.
  (`includes/anim/bricks-controls.php`)

### Zmienione

- **Jedna sekcja „Evoke ONE" zamiast dwóch osobnych grup.** Kontrolki Animatora
  i Parallaxu siedzą teraz we wspólnej grupie, rozdzielone nagłówkami sekcji
  („Animator", „Parallax"). Nagłówek pojawia się tylko dla włączonego modułu —
  przy jednym aktywnym nie zostaje osierocony. Gdy oba moduły są wyłączone, grupa
  nie powstaje w ogóle. (`includes/anim/bricks-controls.php`)

### Znane ograniczenia

- Umieszczenie sekcji w **pionowym pasku zakładki Style**, za CSS i Data attributes,
  pozostaje niezrealizowane. Wymaga nadania grupie ikony, a klucz na ikonę nie jest
  częścią publicznego API Bricks. W definicji grupy przekazywany jest spekulacyjnie
  `'icon'` — jeśli Bricks go nie zna, jest ignorowany i nic się nie dzieje.
- Trzecia zakładka panelu elementu (obok Content i Style) nie jest możliwa — `tab`
  przyjmuje tylko `'content'` i `'style'`; zakładka WooCommerce to wewnętrzny
  przypadek Bricks bez publicznego filtra.

## [1.22.1] — 2026-08-04

### Naprawione

- **Kontrolki w panelu elementu nie miały żadnego skutku na froncie.** Zarówno
  wybrana animacja, jak i włączony Parallax nie robiły nic — atrybuty `data-evk-anim`,
  `data-parallax` i `data-skala` nigdy nie trafiały do HTML. Przyczyna: filtr
  `bricks/element/render_attributes` dostaje tablicę **grupowaną po kluczu**
  fragmentu HTML (`$attributes[$key]['data-x']`), a kod zapisywał ją płasko
  (`$attributes['data-x']`), więc wartość lądowała obok struktury, z której Bricks
  buduje tag, i była po cichu ignorowana. Zapis idzie teraz przez wspólny helper
  wykrywający kształt tablicy w locie.
  (`includes/anim/bricks-controls.php`)
- **Ten sam defekt w dwóch starszych miejscach.** Przejścia wpis→wpis w Trybie
  ciemnym (`inject_post_trans_attrs` — dodatkowo porównywał `$key` z `'root'`
  zamiast `'_root'`) i podmiana flagi języka na obrazku w przełączniku języków
  zapisywały atrybuty tak samo płasko. Obie funkcje wyglądały na działające,
  a nie działały. (`includes/93-darkmode.php`,
  `includes/70-bricks-language-switcher.php`)
- **Animator: pole „Opóźnienie" nie działało przy wyzwalaczu „wejście w viewport".**
  Opóźnienie ustawiano przez `tl.delay()` na już utworzonej osi czasu, co jest
  no-opem — liczy się względem startu rodzica, który dawno minął. Trafia teraz
  do varsów osi czasu.
- **Animator: ryzyko trwale niewidocznej treści.** `fromTo` renderuje stan
  początkowy natychmiast, więc element od razu dostawał np. `opacity: 0`. Gdyby
  `onEnter` nie wystrzelił — realne przy elemencie widocznym już w chwili
  załadowania strony — treść zostawała niewidoczna na stałe. ScrollTrigger jest
  teraz podpięty w varsach osi czasu z `toggleActions`, więc stan rozstrzyga się
  przy pierwszym refreshu. (`assets/js/animator.js`)

### Zmienione

- **Grupy „Evoke Animator" i „Evoke Parallax" przeniesione do zakładki Style**,
  na sam koniec — za CSS i Data attributes. Dotyczy to zarówno obu grup, jak
  i wszystkich dziewięciu kontrolek. (`includes/anim/bricks-controls.php`)

## [1.22.0] — 2026-08-04

### Dodane

- **Kontrolki Animatora i Parallaxu w panelu elementu Bricks.** Każdy element
  dostaje dwie grupy w zakładce Content — koniec z wklejaniem klasy
  `evk-anim-{slug}` z pamięci i ręcznym wpisywaniem `data-parallax`.
  - **Evoke Animator:** lista rozwijana zasilana biblioteką (nie da się wybrać
    animacji, której nie ma) plus nadpisania czasu, opóźnienia, staggera,
    wyzwalacza i startu ScrollTriggera. Puste pole zostawia wartość z biblioteki.
    Pola nadpisań pokazują się dopiero po wybraniu animacji.
  - **Evoke Parallax:** włącznik plus siła i skala. Puste pole = wartość globalna
    z panelu Parallax (widoczna jako placeholder), wypełnione = override dla tego
    elementu. Ręcznie wpisany `data-parallax` działa bez zmian.
  - Kontrolki dokładane tylko gdy dany moduł jest włączony — strona z wyłączonym
    Animatorem i Parallaxem nie płaci nic w payloadzie buildera.
  - Oba silniki JS zostały **nietknięte** — to wyłącznie nowa warstwa wejścia
    konfiguracji, czytająca te same atrybuty co dotąd.
  (`includes/anim/bricks-controls.php`)

- **Ostrzeżenie o nieznanym slugu animacji.** Element wskazujący animację, której
  nie ma w bibliotece (np. po zmianie sluga w panelu), po cichu się nie animował.
  Teraz zgłasza to w konsoli wraz z referencją do elementu.
  (`assets/js/animator.js`)

### Usunięte

- **Spike Fazy 0** (`includes/anim/spike-bricks-controls.php`). Miał sprawdzić na
  żywej instalacji, czy wolno doklejać kontrolki do cudzych elementów Bricks.
  Weryfikacja w dokumentacji Bricks zamknęła temat — `bricks/elements/{name}/controls`
  i `.../control_groups` to oficjalne filtry od wersji 1.3.2 — więc sonda straciła
  rację bytu. Przy okazji wyszło, że miała zaniżony priorytet hooka `init` (20
  zamiast `PHP_INT_MAX`), co mogło dać niepełną listę elementów; docelowy kod
  używa `PHP_INT_MAX`.

## [1.21.0] — 2026-08-04

### Dodane

- **Animator — moduł animacji GSAP dla dowolnego elementu Bricks.** W panelu
  (*Frontend → Animator*) definiujesz bibliotekę nazwanych animacji; każda dostaje
  klasę `evk-anim-{slug}`, którą przypinasz dowolnemu elementowi w Bricks. Nie
  trzeba owijać elementu w kontener ani pisać kodu.
  - **13 presetów:** fade (+ 4 kierunki), skala, zoom out, obrót, rozmycie,
    odsłona maską, podział tekstu na linie / słowa / znaki (SplitText).
  - **4 wyzwalacze:** wejście w viewport, scrub przy scrollu, hover (z obsługą
    fokusa klawiatury), klik, load z sekwencjonowaniem przez pole „kolejność".
  - **Trzy warstwy konfiguracji** scalane w kolejności *preset ⊕ wiersz biblioteki
    ⊕ atrybut `data-evk-anim`*. Zmiana definicji w panelu przestawia całą stronę,
    a pojedynczy element można odchylić bez zakładania nowej definicji. Puste pole
    czasu lub staggera dziedziczy wartość presetu.
  - **`prefers-reduced-motion`** — zamiast animacji element dostaje od razu stan
    końcowy, więc nic nie zostaje niewidoczne. Do wyłączenia w ustawieniach modułu.
  - Domyślnie **nie animuje w canvasie buildera** (osobny włącznik).
  - Moduł startuje wyłączony, zgodnie z regułą z 1.20.0.
  (`includes/anim/animator.php`, `includes/anim/presets.php`,
  `assets/js/animator.js`, `includes/admin/tab-animator.php`)

- **Spike Fazy 0 — kontrolki w panelu elementu Bricks.** Diagnostyka pod przyszłe
  wstrzykiwanie kontrolek Animatora i Parallaxu do cudzych elementów. Nieaktywna,
  dopóki w `wp-config.php` nie pojawi się `define('EVK_ANIM_CONTROLS_SPIKE', true);`.
  Instrukcja i kryteria oceny w nagłówku pliku.
  (`includes/anim/spike-bricks-controls.php`)

### Zmienione

- **Rejestracja bibliotek GSAP wydzielona z loadera elementów.** Siedziała w
  `includes/bricks-elements/loader.php`, przez co Animator — nie będący elementem
  Bricks — musiałby zależeć od loadera elementów. Handle `evk-gsap`,
  `evk-scrolltrigger`, `evk-observer`, `evk-splittext` rejestruje teraz
  `includes/89-gsap.php`, wspólnie dla całej wtyczki. GSAP nadal ładuje się raz,
  niezależnie od tego, ile funkcji jest włączonych.

## [1.20.1] — 2026-08-04

### Zmienione

- **Circular Menu ujednolicony z resztą elementów.** Element odstawał od
  pozostałych na każdym poziomie poza etykietą. Slug `evoke-circular-menu` →
  `evk-circular-menu`, klasa `Evoke_Circular_Menu` → `Evk_Circular_Menu`, plik
  `element-circular-menu.php` → `element.php`, asety z katalogu głównego i `js/`
  → `assets/circular-menu.{js,css}`, text domain → `evk-circular-menu`, handle
  skryptu → `evk-circular-menu-js`, ścieżki przez stałą `EVK_CIRCULAR_MENU_URL`
  zamiast `plugin_dir_url()`. W 1.20.0 slug został świadomie nietknięty (jest
  zapisywany w danych stron Bricks) — element nie był użyty na produkcji, więc
  blokada odpadła. Bez zmian: katalog `evoke-circular-menu/`, `namespace Bricks`,
  klasy CSS `.evk-cm*` i atrybuty `data-*`.
  (`includes/bricks-elements/evoke-circular-menu/*`,
  `includes/bricks-elements/loader.php`)

  **Uwaga przy aktualizacji:** strony deweloperskie zawierające stary element
  stracą go po aktualizacji — trzeba wstawić go na nowo.

### Naprawione

- **Circular Menu nie inicjalizował się w builderze.** `public $scripts` wskazywał
  na `evk_circular_menu`, a plik JS definiował `evkCircularMenuInit()` — Bricks
  wołał nieistniejącą funkcję. Na froncie ratował to własny `DOMContentLoaded`,
  ale w builderze element był martwy (m.in. opcja „Otwórz w builderze" nic nie
  robiła). Funkcja nazywa się teraz `evk_circular_menu_init()` — zgodnie z tym,
  jak ma to zrobione Circular Title — i `$scripts` na nią wskazuje. Ponieważ
  wywołanie leci teraz z dwóch stron (Bricks + `DOMContentLoaded`), doszła flaga
  `data-evk-cm-ready`: bez niej stackowałyby się listenery, a przy włączonym
  portalu drugi przebieg nie znalazłby panelu (jest już w `<body>`) i menu cicho
  przestałoby działać.
  (`includes/bricks-elements/evoke-circular-menu/assets/circular-menu.js`,
  `includes/bricks-elements/evoke-circular-menu/element.php`)

## [1.20.0] — 2026-08-04

### Zmienione

- **Świeża instalacja startuje z wyłączonymi modułami.** Tryb ciemny,
  Dostępność, Schema, OpenGraph, Tłumaczenia (wraz z edytorem inline) i Mapa
  strony miały domyślne `enabled = 1`, więc po instalacji działały od razu.
  Teraz domyślną wartością jest `0` — po instalacji nic nie rusza frontu,
  dopóki nie włączy się modułu ręcznie. Istniejące strony są chronione:
  jednorazowa migracja (`includes/01-install.php`) wykrywa wcześniejszą
  konfigurację i zapisuje te moduły jawnie jako włączone, więc zmiana
  domyślnych niczego nie wyłącza na działającej witrynie.
  (`includes/01-install.php`, `includes/93-darkmode.php`,
  `includes/98-accessibility.php`, `includes/90-schema.php`,
  `includes/opengraph/settings.php`, `includes/30-admin-settings-ajax.php`,
  `evoke-one.php`)
- **Smooth Scroll: domyślny lerp 0.08.** Zamiast 0.1 — wartość domyślna
  odpowiada teraz ustawieniu używanemu w projektach.
  (`includes/96-lenis.php`)
- **Elementy Bricks w grupie „Evoke ONE".** Wszystkie sześć elementów siedziało
  w kategorii `general`, wymieszane z elementami Bricks. Mają teraz własną
  grupę w panelu buildera. (`includes/bricks-elements/loader.php`)
- **Spójne nazwy elementów Bricks.** Etykiety były wymieszane językowo i
  niekonsekwentnie prefiksowane („Evoke Circular Menu", „Kołowy tytuł",
  „Poziomy Scroll", „Evoke Wave Background"). Teraz odpowiadają jeden do
  jednego nazwom z zakładki *Frontend → Elementy Bricks*: Marquee, Horizontal
  Scroll, Scroll Reading, Circular Title, Circular Menu, Wave Background.
  Każdy element ma też własną, niepowtarzalną ikonę i słowa kluczowe
  (m.in. `evoke`, żeby wyszukiwarka buildera pokazywała cały zestaw).
  Slugi elementów (`$name`) pozostały bez zmian — są zapisane w danych stron
  Bricks i ich zmiana rozwaliłaby istniejące instancje.
  (`includes/bricks-elements/*/element*.php`)

### Naprawione

- **Suwak lerp nie pozwalał zapisać konkretnej wartości.** Wartość szła z
  suwaka `<input type="range">` o kroku 0.01 na zakresie 0.01–1, więc trafienie
  np. w 0.08 wymagało pikselowej precyzji. Pole obok suwaka jest teraz
  edytowalne i to ono niesie zapisywaną wartość — suwak nim tylko steruje.
  (`includes/admin/tab-lenis.php`, `assets/admin/admin.js`,
  `assets/admin/admin.css`)
- **Scroll Reading ignorował zmianę trybu ciemnego.** Kolory podane jako
  zmienne CSS (globalne kolory Bricks z odpowiednikiem w dark mode) są
  rozwijane w JS, bo GSAP nie potrafi tweenować `var(--x)`. Wyliczenie
  następowało raz, przy starcie strony, więc przełączenie motywu nie zmieniało
  koloru tekstu — dopiero odświeżenie. Element nasłuchuje teraz zmiany motywu
  (zdarzenie `evk:theme-change` z modułu Dark Mode, `MutationObserver` na
  `data-theme`/`.dark` dla dowolnego innego przełącznika oraz
  `prefers-color-scheme`), przelicza kolory i przebudowuje oś czasu.
  (`includes/bricks-elements/evoke-scroll-reading/assets/scroll-reading.js`,
  `includes/93-darkmode.php`)
- **Circular Menu: nieskuteczny guard koegzystencji.** Klasa żyje w namespace
  `Bricks`, a sprawdzenie szukało jej w globalnym — samodzielna wtyczka nigdy
  nie zostałaby wykryta. Guard sprawdza teraz obie formy nazwy klasy.
  (`includes/bricks-elements/loader.php`, `includes/admin/tab-elementy.php`)

## [1.19.4] — 2026-07-17

### Zmienione

- **Newsletter — mniejsza minimalna paczka.** Suwak rozmiaru paczki w kampanii
  pozwala teraz zejść do 5 maili (dotąd minimum 10), ze skokiem co 5 —
  przydatne przy restrykcyjnych limitach antyspamowych hostingu.
  (`includes/admin/newsletter/tab-campaigns.php`)

## [1.19.3] — 2026-07-17

### Dodane

- **Newsletter — równomierne rozłożenie maili w paczce.** Zamiast wysyłać całą
  paczkę serią (co ~250 ms), maile są rozkładane równomiernie w oknie czasowym
  — filtry antyspamowe hostingów („too quickly") reagują na chwilowe tempo,
  nie na średnią. Okno rozkładu to 80% odstępu paczki, przycięte do 240 s
  (filtr `evk_nl_batch_spread_max_seconds`), żeby przebieg crona zmieścił się
  w `max_execution_time` hostingu. Przykład: 10 maili przy odstępie 15 min →
  jeden mail co ~24 s. Na wypadek ubicia procesu w trakcie rozłożonej paczki
  kolejna paczka jest planowana z góry (zapasowy event) — kampania nigdy nie
  zawiśnie w połowie. (`includes/newsletter/queue.php`)

## [1.19.2] — 2026-07-17

### Dodane

- **Newsletter — automatyczny backoff po limicie SMTP.** Gdy bezpiecznik
  przerwie paczkę (seria błędów wysyłki, np. filtr antyspamowy hostingu typu
  DCC „slow down"), kolejna paczka rusza z podwojonym odstępem — każde kolejne
  zadziałanie bezpiecznika podwaja odstęp dalej (×2, ×4, do ×8; filtr
  `evk_nl_backoff_max_multiplier`), a paczka zakończona bez bezpiecznika zeruje
  mnożnik. Kampania sama dostosowuje tempo do limitu dostawcy, bez ręcznego
  zmieniania ustawień. Wydłużenie odstępu jest odnotowywane w logu kampanii.
  Mnożnik jest zerowany też przy starcie, anulowaniu i usunięciu kampanii.
  (`includes/newsletter/queue.php`, `includes/newsletter/campaigns.php`)

## [1.19.1] — 2026-07-17

### Naprawione

- **Newsletter — „SMTP Error: data not accepted." po kilkunastu mailach.**
  Serwery SMTP (zwłaszcza na hostingach współdzielonych) odrzucały wiadomości
  po przekroczeniu limitu tempa wysyłki, a wtyczka dodatkowo prowokowała limity,
  otwierając osobne połączenie SMTP dla każdego maila i wysyłając paczkę bez
  żadnej przerwy. Zmiany (`includes/newsletter/mailer.php`,
  `includes/newsletter/queue.php`):
  - Jedno współdzielone połączenie SMTP na całą paczkę (`SMTPKeepAlive`)
    zamiast łączenia się od nowa przy każdym mailu; połączenie zamykane po
    zakończeniu paczki.
  - Krótka przerwa między kolejnymi mailami w paczce (domyślnie 250 ms,
    filtr `evk_nl_send_delay_ms`).
  - Bezpiecznik: po 3 błędach wysyłki pod rząd (filtr
    `evk_nl_max_consecutive_failures`) paczka jest przerywana zamiast dobijać
    się do serwera — pozostałe maile zostają w statusie „pending" i idą w
    kolejnej paczce, bez spalania limitu 3 prób na subskrybenta.
  - Komunikat błędu w logach zawiera teraz faktyczną odpowiedź serwera SMTP
    (kod + powód, np. informację o przekroczonym limicie), a nie tylko ogólne
    „data not accepted".

## [1.19.0] — 2026-07-14

### Dodane

- **Optymalizacja czcionek (anti-FOUT)** — nowy moduł w zakładce Frontend →
  „Czcionki (FOUT)". Preloaduje wskazane lokalne pliki czcionek
  (`<link rel="preload" as="font" crossorigin>`) najwcześniej w `<head>` (przed
  arkuszami stylów), dzięki czemu czcionka jest zwykle gotowa przed pierwszym
  malowaniem i miganie (swap/FOUT) nie występuje. Rozwiązanie czysto addytywne:
  nie ukrywa tekstu, nie zmienia layoutu ani przejść/animacji.
  - Pole listy plików do preload (ścieżki względne lub pełne URL-e; woff2/woff/
    ttf/otf — typ MIME i `crossorigin` doklejane automatycznie).
  - Auto-wykrywanie lokalnych czcionek `.woff2` w typowych folderach
    (`uploads/fonts`, `omgf`, `local-google-fonts`, `bricks`…) z podpowiedziami
    do jednego kliknięcia.
  - Opcjonalny `preconnect` dla czcionek z zewnętrznego CDN.
  - Nieaktywny domyślnie; nie ładuje niczego na froncie gdy wyłączony.
  (`includes/91-fonts.php`, `includes/admin/tab-fonts.php`)

## [1.18.1] — 2026-07-14

### Naprawione

- **Updater — pasek „dostępna aktualizacja" nie znikał po aktualizacji.** Po
  kliknięciu „Aktualizuj" instalacja przebiegała, ale informacja o nowej wersji
  wisiała pod wtyczką aż do kolejnej aktualizacji/przeładowania (trzeba było
  klikać drugi raz). Przyczyna: porównanie wersji GitHub ze stałą
  `EVOKE_ONE_VERSION`, która jest zamrożona na początek żądania (z plików sprzed
  nadpisania) — więc tuż po aktualizacji w tym samym żądaniu trzymała jeszcze
  starą wartość i aktualizacja była ponownie wstrzykiwana do transientu. Teraz
  porównujemy z wersją, którą WordPress świeżo odczytał z dysku
  (`$transient->checked`), z fallbackiem na stałą; przy braku aktualizacji
  wtyczka trafia też do `no_update` (poprawna obsługa listy i auto-aktualizacji).
  (`includes/99-github-updater.php`)

  > Uwaga: tę poprawkę instalujesz jeszcze starym mechanizmem — pasek może
  > zachować się po staremu przy aktualizacji **do** 1.18.1. Od 1.18.1 wzwyż
  > kolejne aktualizacje znikają już od razu.

## [1.18.0] — 2026-07-14

### Dodane

- **Schema — repeater dodatkowych obiektów i usług (encje podrzędne).** W
  ustawieniach Schema można dodać dowolną liczbę pozycji (typ + nazwa +
  opcjonalny opis), np. pole namiotowe (`Campground`), wypożyczalnię kajaków
  (`SportsActivityLocation`), restaurację, plażę, parking, rzekę/akwen. Każda
  trafia do grafu jako osobny węzeł `#entity-N`; przy typie działalności innym
  niż „Organizacja" powiązana z obiektem (`#place`) przez `containedInPlace`.
  Lista typów ograniczona do podtypów Place (poprawne `containedInPlace`).
  To poziom danych strukturalnych spotykany w portalach turystycznych.
  (`includes/90-schema.php`, `includes/admin/seo/tab-schema.php`)

### Zmienione

- **Schema — adres także na węźle `#organization`.** Google zaleca `address`
  na Organization; po rozdzieleniu z 1.17.0 węzeł wydawcy go nie miał (walidator
  zgłaszał brak opcjonalnego pola). Teraz adres pojawia się na `#organization`,
  `#place` i `TouristAttraction`, o ile podano ulicę lub miejscowość — wspólny
  helper `build_address()`. Gdy adresu brak, pusty `PostalAddress` nie jest już
  emitowany (dotąd węzeł Organization dostawał pusty blok adresu).
  (`includes/90-schema.php`)

## [1.17.0] — 2026-07-14

### Zmienione

- **Schema — rozdzielenie wydawcy strony od fizycznego obiektu.** Dotąd wybór
  typu działalności (np. Resort) zmieniał `@type` węzła `#organization`, przez
  co `WebSite.publisher` wskazywał na obiekt noclegowy. Teraz `#organization`
  to zawsze czysta `Organization` (wydawca: nazwa operatora — nowe pole,
  fallback: nazwa obiektu, logo, sameAs, contactPoint), a przy typie innym niż
  „Organizacja" powstaje osobny węzeł **`#place`** z wybranym `@type` i danymi
  miejsca: adres, geo, priceRange, amenityFeature, telefon, e-mail, obrazek;
  powiązany z wydawcą przez `parentOrganization`. `WebPage.about` i
  `TouristAttraction.containedInPlace` wskazują teraz `#place`.
  **Uwaga:** na instalacjach z ustawionym typem ≠ „Organizacja" graf zmienia
  strukturę (jeden węzeł → dwa powiązane). (`includes/90-schema.php`)

### Dodane

- **Schema — nowe pola węzła #place:** link do mapy (`hasMap`), godziny
  otwarcia (`openingHoursSpecification` — parser reguł „Pn-Pt 08:00-20:00",
  „Sob 09:00-14:00", „Codziennie 08:00-20:00"; dni po polsku i angielsku,
  zakresy i listy po przecinku) oraz obsługiwany obszar (`areaServed`, jedna
  linia = jeden obszar). (`includes/90-schema.php`,
  `includes/admin/seo/tab-schema.php`)

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
