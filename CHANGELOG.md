# Changelog — Evoke ONE

Format wg [Keep a Changelog](https://keepachangelog.com/), wersjonowanie [SemVer](https://semver.org/).

## [1.29.2] — 2026-08-05

### Naprawione

- **Tryb ciemny nie wracał do pełnej jasności po włączeniu tła przy scrollu.**
  Warstwa tła zostawała o jeden motyw w tyle: po przełączeniu na ciemny trzymała
  kolor jasny, a po powrocie do jasnego — ciemny.

  Moduł trybu ciemnego dokłada `transition: background-color` między innymi na
  `section` (domyślne `global_selectors`). W trakcie trwającego przejścia
  `getComputedStyle` zwraca wartość **animowaną**, nie docelową — a silnik tła
  odczytuje kolor sekcji dokładnie w tym momencie, tuż po zdjęciu klasy
  przezroczystości. Trafiał więc albo w kolor poprzedniego motywu, albo w samą
  przezroczystość, przez co sekcje wypadały z łańcucha jako „bez własnego koloru".

  Pomiar odbywa się teraz przy wyłączonych przejściach (klasa `evk-bg-measure`),
  a obie zmiany klas są commitowane zanim przejścia wrócą — inaczej powrót do
  przezroczystości animowałby się przez 0,4 s i widać byłoby mignięcie kolorem
  sekcji. Sprawdzone dziesięcioma przełączeniami tam i z powrotem, bez dryfu.

## [1.29.1] — 2026-08-05

### Naprawione

- **Nie dało się włączyć przełącznika „Tło przy scrollu".** Przełączniki modułów
  zapisują się przez AJAX, a ten sprawdza opcję i pole względem białej listy
  w `includes/30-admin-settings-ajax.php`. Nowa opcja `evk_bgshift` na tę listę
  nie trafiła, więc każde kliknięcie kończyło się odpowiedzią `not_allowed`.

  Przy okazji ta sama opcja została dopisana do mapy eksportu, pętli importu
  i mapy grupowania w panelu — bez tego ustawienia modułu ginęłyby po cichu przy
  przenoszeniu konfiguracji między instalacjami. Do mapy grupowania dopisany też
  `evk_animator`, którego nigdy tam nie było — ta sama luka, ten sam plik.

## [1.29.0] — 2026-08-05

### Dodane

- **Przenikanie tła przy scrollu.** Nowy moduł: kolor tła przewija się płynnie od
  sekcji do sekcji podczas przewijania strony. Włącznik przy każdym elemencie
  w builderze (Atrybuty → Evoke ONE → Tło przy scrollu), ustawienia długości
  przejścia i wygładzania w panelu (Frontend → Tło przy scrollu).

  Kolor **nie jest animowany na samych sekcjach**. Gdyby każda przewijała własne
  tło, przez całe przejście na granicy sąsiadowałyby dwa różne kolory i widać
  byłoby szew — efekt czytałby się jak przenikanie prostokątów, nie jak jedno tło.
  Zamiast tego pod całą stroną leży jedna warstwa: sekcja oddaje jej swój kolor
  i sama robi się przezroczysta.

  **Bez osobnej kontrolki na kolor.** Silnik odczytuje `background-color`
  z `getComputedStyle`, gdzie kolory globalne Bricks są już rozwinięte do `rgb()`.
  Dzięki temu nie powstaje drugie źródło prawdy, które rozjeżdżałoby się przy
  każdej zmianie koloru sekcji. Zmiana motywu przelicza kolory i przebudowuje oś
  czasu, tak samo jak w Scroll Reading.

  Sekcja z tłem graficznym albo gradientowym nie ma czego oddać — wypada
  z łańcucha z ostrzeżeniem w konsoli, zamiast wstawiać w niego przezroczystą
  dziurę. Przy systemowej redukcji ruchu kolor przeskakuje na granicy sekcji
  zamiast się przewijać.

## [1.28.3] — 2026-08-05

### Naprawione

- **Wave Background renderował scenę dwa razy na każdą klatkę.** Pętla wołała
  `renderer.render()`, a zaraz po nim `composer.render()` (komentarz mówił „jak
  w referencji"). Ostatni `ShaderPass` renderuje na ekran przez
  `setRenderTarget(null)` nieprzezroczystym materiałem, więc i tak nadpisuje
  wszystko — potwierdzone porównaniem zrzutów płótna: obraz jest identyczny co do
  piksela z pierwszym przebiegiem i bez niego. Był to pełny, wyrzucany przebieg
  ciężkiego shadera z szumem na każdą klatkę, przy dwukrotnym DPR na telefonie.

- **`removeEventListener` w `destroy()` nigdy niczego nie usuwał.** Nasłuch
  `resize` zakładany był przez `this.resize.bind(this)`, a zdejmowany przez
  kolejne `.bind()`, czyli inną funkcję. Referencja trzymana jest teraz w polu.

### Zmienione

- **Wave Background włącza `preserveDrawingBuffer`.** Element bywa tłem dla tekstu
  z `mix-blend-mode`, a tryb mieszania zmusza przeglądarkę do odczytania pikseli
  płótna. Przy domyślnym `false` WebGL wolno porzucić bufor zaraz po wyświetleniu
  — odczyt trafia wtedy na pustkę i tekst miesza się z niczym zamiast z falą.
  Koszt to jedna kopia bufora na klatkę, czyli mniej niż usunięty wyżej przebieg
  sceny.

## [1.28.2] — 2026-08-05

### Naprawione

- **Ostrzeżenie `[EVK Animator] Brak animacji "pending" w bibliotece` w konsoli.**
  Klasa zasłony z 1.27.2 nazywała się `evk-anim-pending`, czyli wpadła w przestrzeń
  nazw slugów animacji. Silnik zbiera elementy selektorem `[class*="evk-anim-"]`,
  a `querySelectorAll` przeszukuje dokument razem z `<html>` — korzeń strony
  trafiał więc do wyników jak zwykły element z animacją i silnik szukał w bibliotece
  animacji o slugu „pending".

  Skutek był wyłącznie kosmetyczny: konfiguracja nie miała `from` ani `to`, więc na
  `<html>` nic się nie działo. Hałas był jednak mylący, bo to ostrzeżenie istnieje
  po to, żeby łapać literówki w slugach. Klasa nazywa się teraz `evk-veil` i leży
  poza tą przestrzenią; warunek jest zapisany w komentarzu przy `render_preveil()`,
  bo dopasowanie idzie po podciągu, nie po prefiksie — sam brak prefiksu nie
  wystarczy.

## [1.28.1] — 2026-08-05

### Naprawione

- **Opóźnienie nie działało przy wyzwalaczu „wczytanie strony".** Kolejka startowa
  wstawiała animacje na oś czasu pod pozycją `'+=' + opóźnienie`, a w GSAP `'+='`
  liczy się od **końca** dotychczasowej osi, nie od jej początku — opóźnienia
  sumowały się z czasami trwania wszystkich wcześniejszych animacji. Zmierzone:
  ustawione 0 / 0,3 / 0,6 s dawało starty 0 / 1,1 / 2,5 s, a cała sekwencja
  ciągnęła się 3,3 s zamiast 1,4 s. Pojedynczy element na stronie działał
  poprawnie, dlatego usterka umykała przy pobieżnym teście.

  Pozycja jest teraz liczbowa, czyli bezwzględna względem początku sekwencji —
  wpisana wartość znaczy dokładnie tyle, ile mówi etykieta.

- **Błędny `aria-label` w Scroll Reading.** Etykietę dokładał SplitText: domyślne
  `aria: "auto"` ustawia ją z surowego `textContent` kontenera i oznacza każdy
  kawałek `aria-hidden`. Element jest nestable, więc dzieli **kontener z dowolnymi
  dziećmi Bricks** — na granicy bloków wyrazy się sklejały („ProjektujemyRobimy"),
  a co gorsza cała treść znikała z drzewa dostępności: zmierzone w Chromium
  nagłówek i odnośnik zostawały bez nazw, przez co link stawał się dla czytnika
  ekranu bezimienny.

  Podział woła teraz SplitText z `aria: 'none'` i `tag: 'span'`. Po zmianie drzewo
  dostępności pokazuje `heading: "Projektujemy"` i `link: "piszemy o tym"`, a żaden
  kawałek nie jest ukryty. Wygląd bez zmian — CSS elementu celuje w klasy, nie
  w znaczniki, co potwierdzono pomiarem `display` dla wszystkich trzech trybów
  podziału.

- **Ten sam błąd w Animatorze, ale tylko na kontenerach.** Przy pojedynczym
  nagłówku czy akapicie zachowanie GSAP-a jest poprawne — element ma własną rolę,
  więc `aria-label` działa, a ukrycie kawałków jest zalecane. Psuje się dopiero
  na elemencie z wieloma dziećmi, więc `aria: 'none'` włącza się wyłącznie tam.
  Znacznik zostaje domyślny: SplitText nakłada `display` i `position` tylko gdy
  `tag !== 'span'`, a na pudełkach `inline` transformacje nie działają.

### Dodane

- **„Kolejność" w kontrolkach elementu Bricks.** Dotąd była wyłącznie w bibliotece,
  więc każdy element ustawiany w panelu siedział w kroku zerowym.
- **„Kolejność" wreszcie coś znaczy.** Po przejściu na pozycje bezwzględne samo
  sortowanie nie wpływałoby na nic. Teraz to **krok sekwencji**: elementy z tym
  samym numerem ruszają razem, kolejny numer czeka, aż poprzedni krok się skończy,
  a opóźnienie liczy się od początku swojego kroku.

## [1.28.0] — 2026-08-05

### Naprawione

- **Scroll zacinał się na telefonie na całej stronie.** Regresja z 1.27.3: Stacking
  Cards nasłuchiwały `resize` i na każde zdarzenie wołały `ScrollTrigger.refresh()`.
  Na telefonie `resize` wypala przy każdym chowaniu i pokazywaniu paska adresu,
  czyli w trakcie przewijania, a refresh przemierza **wszystkie** triggery na
  stronie — także Animatora. Stąd zacięcie wszędzie, nie tylko przy stosie.

  Rozpórka przelicza się teraz w zdarzeniu `refreshInit`, tuż przed pomiarem
  ScrollTriggera: pomiar zostaje spójny, ale o czas refreshu decyduje ScrollTrigger,
  a nie my. Do tego cała wtyczka ustawia `ScrollTrigger.config({ignoreMobileResize:
  true})`, więc sam pasek adresu nie wywołuje już przemiaru w żadnym module.

- **Lenis nie był spięty ze ScrollTriggerem.** Brakowało `lenis.on('scroll',
  ScrollTrigger.update)`, przez co ScrollTrigger polegał na natywnych zdarzeniach
  scrolla, które Lenis modyfikuje — scrub potrafił zostawać w tyle za obrazem.
  Lenis jechał też własną pętlą `requestAnimationFrame` obok tickera GSAP-a;
  teraz, gdy GSAP jest na stronie, obie animacje idą z jednego zegara.

- **Moduł Kursor ładował drugą kopię GSAP-a** (własny handle `gsap`, wersja 3.12.2)
  obok wspólnej z `includes/89-gsap.php`. Na stronie z Animatorem albo elementem
  Bricks pobierały się dwie biblioteki w dwóch różnych wersjach. Teraz korzysta ze
  wspólnego handle'a.

### Zmienione

- **Wszystkie biblioteki zewnętrzne podbite do najnowszych wydań:**

  | Biblioteka | Było | Jest |
  |---|---|---|
  | GSAP | 3.13.0 (a w Marquee i Horizontal Scroll 3.12.5, w Kursorze 3.12.2) | **3.15.0** wszędzie |
  | Lenis | `@studio-freight/lenis` 1.0.42 | **`lenis` 1.3.26** |
  | three.js | 0.128.0 | **0.185.1** |
  | Chart.js | 4.4.0 | **4.5.1** |
  | SortableJS | 1.15.2 | **1.15.7** |

- **Dwie kontrolki Smooth Scroll wreszcie coś robią.** Paczka `@studio-freight/lenis`
  została porzucona na 1.0.42, a wtyczka wysyłała jej opcje z gałęzi 1.3:
  `touchInertiaExponent` i `overscroll` w tamtej wersji nie istniały i były po cichu
  ignorowane. Widełki suwaka bezwładności (1.0–5.0, domyślnie 1.7) od początku
  opisywały wersję 1.3 — po podbiciu zgadzają się z biblioteką. Arkusz Lenisa
  zaktualizowany do wydania 1.3.26.

- **Wave Background wyłącza zarządzanie kolorem three.js.** Od 0.152 `new
  THREE.Color('#hex')` przelicza sRGB na przestrzeń liniową. Element podaje kolory
  wprost do własnego ShaderMaterial i renderuje przez EffectComposer bez OutputPass,
  więc nic tej konwersji nie odwraca — sprawdzone: `#3366ff` dałoby
  `[0.033, 0.133, 1]` zamiast `[0.2, 0.4, 1]`, czyli wyraźnie ciemniejszy gradient.
  `ColorManagement.enabled = false` zachowuje obraz sprzed podbicia.

## [1.27.3] — 2026-08-05

### Naprawione

- **Stacking Cards: ostatnia karta nie zatrzymywała się na swoim schodku, tylko
  wjeżdżała na poprzednie.** Dwie osobne przyczyny, obie zmierzone w Chromium na
  kartach 100vh — poprzednia próba naprawy (1.27.1/1.27.2) nie mogła zadziałać.

  Po pierwsze, „Zapas pod stosem" szedł w `padding-bottom` kontenera, a sticky
  trzyma element wyłącznie w granicach **content boxa** rodzica. Padding leży poza
  nim, więc nie przedłużał fazy przyklejenia ani o piksel: 0 px, 120 px i 400 px
  paddingu dawały identyczny przebieg, a karta stała 0 px. Zapas realizuje teraz
  pusty element pod ostatnią kartą — mierzalnie działa.

  Po drugie, sticky zwalnia karty w kolejności **odwrotnej** do ich `top`, a odstęp
  między zwolnieniami to dokładnie różnica tych wartości. Przy schodkowaniu przez
  `top` ostatnia karta ruszała pierwsza i wchodziła na poprzednie. Wszystkie karty
  mają teraz wspólny `top`, a schodek robi `transform` — obraz ten sam, ale
  zwolnienie równoczesne i schodki zostają nienaruszone, aż stos zjedzie z ekranu.
  Układ przepływu na to nie wpływał, więc zmiany odstępów nic by nie dały.

### Zmienione

- **Stacking Cards: automatyczny zapas to połowa wysokości ostatniej karty**
  (mierzonej, bo karty bywają na 100vh) zamiast „liczba kart × schodek", czyli
  kilkudziesięciu pikseli. Wartość można nadpisać w dowolnej jednostce CSS.
  Wbrew temu, co mówił poprzedni opis kontrolki, zapas **nie** zostawia pustego
  miejsca pod stosem — wychodzące karty przykrywają go sobą.
- **Stacking Cards: zapas przelicza się przy zmianie rozmiaru okna.** Przy
  kartach na 100vh obrót telefonu zmieniał wysokość karty, a zapas zostawał stary.

## [1.27.2] — 2026-08-04

### Naprawione

- **Błysk elementu przed animacją.** Po odświeżeniu element był przez moment
  widoczny w stanie docelowym, potem skakał do stanu początkowego i dopiero
  animował. Skrypt animatora ładuje się ze stopki razem z GSAP z CDN, więc przez
  ten czas element stał wyrenderowany normalnie. Doszła zasłona: `<head>` dostaje
  regułę ukrywającą elementy z animacją, a silnik zdejmuje ją zaraz po nałożeniu
  stanów początkowych.

  Klasę na `<html>` ustawia mikroskrypt, nie PHP — przy wyłączonym JavaScripcie
  nie wejdzie w ogóle i treść pozostaje widoczna. Do tego bezpiecznik czasowy
  (3 s) oraz zdjęcie zasłony, gdy GSAP w ogóle nie dojedzie: awaria CDN-u nie może
  ukryć strony na stałe. Użyto `visibility`, nie `opacity` — zachowuje layout,
  więc pomiary ScrollTriggera pozostają poprawne.

- **Czekanie na webfonty tylko tam, gdzie ma sens.** Od 1.23.3 animator czekał na
  `document.fonts.ready` przed **każdą** animacją, choć metryki fontu mają
  znaczenie wyłącznie przy podziale tekstu. Przy wolnych fontach dokładało to
  setki milisekund i było główną przyczyną błysku. Teraz czeka tylko przy
  presetach `split-*`. (`includes/anim/animator.php`, `assets/js/animator.js`)

- **Stacking Cards: środkowa karta czerniała w połowie przewijania.** `gsap.to()`
  na karcie bez ustawionego `filter` startuje od `none`, a GSAP podstawia za
  brakującą funkcję wartość zerową — czyli `brightness(0)`, czyli czerń — i scrub
  przewijał od czarnego. Stan początkowy jest teraz podany jawnie przez `fromTo`
  z `brightness(1)` plus `immediateRender: false`. (Ease był ustawiony poprawnie
  i nie miał z tym związku.)

### Dodane

- **Stacking Cards: kontrolka „Zapas pod stosem".** Sticky trzyma kartę tylko
  dopóki kontener ma pod nią miejsce — bez zapasu ostatnia karta odkleja się
  niemal natychmiast i sunie w górę po poprzednich. Puste pole = *liczba kart ×
  schodek*. Przy wysokich kartach to za mało; wtedy wpisuje się np. `50vh`.
  Kompromis jest nieusuwalny: im dłużej ostatnia karta stoi, tym więcej pustego
  miejsca pod stosem. (`includes/bricks-elements/evoke-stacking-cards/*`)

## [1.27.1] — 2026-08-04

### Zmienione

- **Stacking Cards: karta pod spodem ciemnieje zamiast prześwitywać.** Wcześniej
  efekt szedł przez `opacity`, więc przez kartę widać było tło strony i stos się
  rozłaził. Teraz `filter: brightness()` — karta zostaje nieprzezroczysta, po
  prostu ciemniejsza. Kontrolka nazywa się „Przyciemnienie", domyślnie 0.25.
- **Stacking Cards: cień kart.** Nowy włącznik (domyślnie włączony) plus pole na
  własną wartość CSS. Domyślnie cień rzucany do góry — to ta krawędź, którą widać
  przy nakładaniu. Bez cienia stos czyta się płasko.

### Naprawione

- **Stacking Cards: ostatnia karta nie zatrzymywała się na swoim schodku.**
  Kontener kończył się tuż za nią, więc jej faza `sticky` trwała ułamek chwili
  i karta sunęła dalej po poprzednich zamiast stanąć. Przy włączonym schodkowaniu
  kontener dostaje teraz na dole tyle miejsca, ile wynosi całe schodkowanie
  — każda karta, łącznie z ostatnią, dojeżdża do swojej pozycji i na niej zostaje.
  (`includes/bricks-elements/evoke-stacking-cards/assets/stacking-cards.js`)

## [1.27.0] — 2026-08-04

### Dodane

- **Nowy element Bricks: Stacking Cards.** Karty nakładające się przy scrollu.
  Element nestable — kontener plus karty jako dzieci, trzy na start.
  - **Mechanika na `position: sticky`, nie na `ScrollTrigger.pin`.** Sticky nie
    tworzy pin-spacerów, nie przelicza wysokości i nie rozjeżdża layoutu przy
    zmianie rozmiaru okna. GSAP dokłada wyłącznie skalowanie i przygaszanie kart,
    które zostają pod spodem.
  - Kontrolki: offset od góry, odstęp między kartami, schodkowanie (każda kolejna
    karta zatrzymuje się niżej, więc widać krawędzie tych pod spodem), skala
    docelowa, przygaszenie, wyłączenie poniżej zadanej szerokości.
  - Breakpoint przez `matchMedia`, nie przez pomiar przy starcie — obrót telefonu
    przełącza tryb bez przeładowania strony.
  - Bez JS-a albo poniżej breakpointu karty układają się normalnie, jedna pod
    drugą. Degradacja jest bezpieczna: `sticky` włącza dopiero klasa dodawana
    przez skrypt.
  - Element startuje wyłączony, jak reszta — *Frontend → Elementy Bricks*.
  (`includes/bricks-elements/evoke-stacking-cards/*`,
  `includes/bricks-elements/loader.php`, `includes/30-admin-settings-ajax.php`)

## [1.26.0] — 2026-08-04

### Dodane

- **Cel animacji: sam element, jego dzieci albo selektor w środku.** Nowe pole
  w wierszu biblioteki. Do tej pory animator celował zawsze w jeden element, przez
  co **pole „stagger" nie robiło nic poza tekstem** — nie miało czego rozsuwać,
  choć w panelu wyglądało na sprawne. Teraz siatka kart z celem „dzieci elementu"
  i staggerem pojawia się jedna po drugiej. Błędna składnia selektora jest łapana
  i zgłaszana w konsoli zamiast wywalać inicjalizację całej strony.
- **Pin przy wyzwalaczu scrub.** Element trzyma się ekranu, dopóki animacja się nie
  dokończy. Świadomie tylko przy scrubie — przy pozostałych wyzwalaczach nie ma
  czego przytrzymywać, a pin tworzy pin-spacer rozpychający layout.
- **6 nowych presetów:** odsłona maską z lewej i z prawej, flip 3D w poziomie
  i w pionie, skew oraz **tekst po liniach zza maski** (`mask: "lines"` z GSAP 3.13
  — SplitText sam robi owijki `overflow:hidden`, bez ręcznego CSS-u). Razem 20.

### Naprawione

- **Podział tekstu nie przeżywał zmiany szerokości okna.** Tekst dzielony był raz;
  po zmianie rozmiaru łamanie linii się zmieniało, a kawałki zostawały
  z poprzedniego rozmiaru i animacja się rozjeżdżała. Presety `split-*` używają
  teraz `SplitText.create()` z `autoSplit`, a `onSplit()` zwraca oś czasu, dzięki
  czemu GSAP sam sprząta ją przed kolejnym podziałem zamiast zostawiać tweeny na
  nieistniejących węzłach. Kolejka animacji „load" nie odtwarza się przy ponownym
  podziale — wejście na stronę było już raz. Przy GSAP starszym niż 3.13 zachowanie
  wraca do jednorazowego podziału. (`assets/js/animator.js`)

## [1.25.0] — 2026-08-04

Wydanie scala iteracje 1.21–1.24, które nigdy nie zostały otagowane. Opisuje stan
docelowy, a nie drogę do niego — ślepe uliczki (m.in. próba wejścia do paska skrótów
buildera) zostały udokumentowane w komentarzach w kodzie, żeby nikt nie powtarzał
ich po raz drugi.

### Dodane

- **Animator — moduł animacji GSAP dla dowolnego elementu Bricks.**
  W *Ustawienia → Evoke ONE → Frontend → Animator* budujesz bibliotekę nazwanych
  animacji. Każda dostaje slug, a slug daje klasę `evk-anim-{slug}` do przypięcia
  dowolnemu elementowi. Bez owijania w kontener i bez pisania kodu.
  - **14 presetów:** fade i 4 kierunki, skala, zoom out, obrót, rozmycie, odsłona
    maską, podział tekstu na linie / słowa / znaki (SplitText) oraz preset własny.
  - **4 wyzwalacze:** wejście w viewport, scrub przy scrollu, hover (z obsługą
    fokusa klawiatury) i klik, oraz load z sekwencjonowaniem przez pole „kolejność".
  - **Własne from/to** — dowolne właściwości GSAP w zapisie „właściwość: wartość"
    po jednej na linię (`opacity: 0`, `y: 40`, `filter: blur(12px)`). Wypełnione
    pole zastępuje odpowiednik z presetu w całości; puste dziedziczy z presetu.
  - **Trzy warstwy konfiguracji** scalane w kolejności *preset ⊕ wiersz biblioteki
    ⊕ atrybut `data-evk-anim`*. Zmiana definicji w panelu przestawia całą stronę,
    a pojedynczy element można odchylić bez zakładania nowej definicji.
  - **`prefers-reduced-motion`** — element dostaje od razu stan końcowy zamiast
    animacji, więc nic nie zostaje niewidoczne. Do wyłączenia w ustawieniach.
  - Slug jest osobnym polem, nie pochodną nazwy — zmiana nazwy nie zrywa klas
    wpisanych już na stronach. Element wskazujący nieznany slug zgłasza to w konsoli.
  - Domyślnie nie animuje w canvasie buildera (osobny włącznik). Moduł startuje
    wyłączony, zgodnie z regułą z 1.20.0.
  (`includes/anim/*`, `assets/js/animator.js`, `includes/admin/tab-animator.php`)

- **Kontrolki Animatora i Parallaxu w panelu elementu Bricks.** Doklejane do
  **każdego** elementu, wewnątrz natywnej grupy **Atrybuty** — dzięki temu są
  dostępne jednym kliknięciem z paska skrótów buildera, którego własne grupy
  wtyczek nie przyjmują. Blok oznaczony separatorami „Evoke ONE — Animator"
  i „Evoke ONE — Parallax".
  - **Animator:** lista animacji zasilana biblioteką plus nadpisania czasu,
    opóźnienia, staggera, wyzwalacza i startu ScrollTriggera.
  - **Parallax:** włącznik, siła i skala — koniec ręcznego wpisywania
    `data-parallax`. Puste pole = wartość globalna z panelu (widoczna jako
    placeholder), wypełnione = override. Ręcznie wpisany atrybut działa bez zmian.
  - Klucz grupy docelowej wykrywany w locie, z awaryjnym powrotem do własnej sekcji.
  - Kontrolki dokładane tylko gdy dany moduł jest włączony — strona z wyłączonymi
    nie płaci nic w payloadzie buildera.
  (`includes/anim/bricks-controls.php`)

### Zmienione

- **Rejestracja bibliotek GSAP wydzielona z loadera elementów** do
  `includes/89-gsap.php`. Handle `evk-gsap`, `evk-scrolltrigger`, `evk-observer`,
  `evk-splittext` są teraz wspólne dla całej wtyczki, więc Animator nie zależy od
  loadera elementów Bricks. GSAP nadal ładuje się raz, niezależnie od tego, ile
  funkcji jest włączonych.

### Naprawione

- **Atrybuty ustawiane filtrem nie trafiały do HTML.** Filtr
  `bricks/element/render_attributes` dostaje tablicę grupowaną po kluczu fragmentu
  (`$attributes[$key]['data-x']`), a kod w trzech miejscach zapisywał ją płasko —
  wartość lądowała obok struktury, z której Bricks buduje tag, i była po cichu
  ignorowana. Dotyczyło to kontrolek Evoke ONE, przejść wpis→wpis w Trybie ciemnym
  (dodatkowo porównywał `$key` z `'root'` zamiast `'_root'`) oraz podmiany flagi
  języka w przełączniku języków. Dwie ostatnie funkcje wyglądały na działające,
  a nie działały. (`includes/anim/bricks-controls.php`, `includes/93-darkmode.php`,
  `includes/70-bricks-language-switcher.php`)
- **Animator dzielił tekst przed załadowaniem webfontów.** Presety `split-*`
  uruchamiały SplitText zaraz po `DOMContentLoaded`, więc podział liczył się na
  metrykach fontu zastępczego i po podmianie fontu linie się rozjeżdżały (GSAP
  zgłaszał „SplitText called before fonts loaded"). Inicjalizacja czeka teraz na
  `document.fonts.ready`.
- **Animator: pole „Opóźnienie" nie działało przy wyzwalaczu viewport** —
  `tl.delay()` na już utworzonej osi czasu jest no-opem. Trafia teraz do varsów.
- **Animator: ryzyko trwale niewidocznej treści.** `fromTo` renderuje stan
  początkowy natychmiast, więc nieodpalony `onEnter` zostawiał element na
  `opacity: 0`. ScrollTrigger idzie teraz w varsach osi czasu z `toggleActions`,
  dzięki czemu stan rozstrzyga się przy pierwszym refreshu.

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
