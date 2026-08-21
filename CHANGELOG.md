# Changelog — Evoke ONE

Format wg [Keep a Changelog](https://keepachangelog.com/), wersjonowanie [SemVer](https://semver.org/).

## [1.105.0] — 2026-08-21

### Dodane

- **Horizontal Scroll: „Pokaż treść pod sekcją".** Do tej wersji pod przypiętą
  sekcją ziała pustka, a następna sekcja wjeżdżała dopiero na sam koniec
  przewijania kart. Z włącznikiem **stoi nieruchomo tuż pod nią przez cały
  czas**, a widać jej tyle, ile zostaje ekranu pod sekcją. Domyślnie wyłączone
  — nikomu nic się nie zmienia.

  Powód, dla którego trzeba było to policzyć, a nie ustawić: ScrollTrigger
  wstawia zapas o wysokości `H + D` (sekcja + droga taśmy), więc następna
  sekcja stoi w dokumencie o `D` niżej, a po przewinięciu o `d` jej górna
  krawędź jest na `H + D − d`. To maleje **zawsze** — żaden margines ani
  `position: sticky` tego nie odwróci, bo treść jest naprawdę oddalona o `D`.

  Dlatego rodzeństwo za zapasem jedzie `y` od `−D` do `0` tym samym scrubem.
  Wychodzi z tego `(docTop − S − d) + (d − D)` = `docTop − S − D`, czyli
  wartość stała: przed przypięciem treść jest przyklejona pod sekcją (luka `D`
  nie zdąży się pokazać), w trakcie stoi, a na końcu `y` wraca do zera i nic
  nie skacze.

### Rzeczy, o które łatwo się potknąć — i jak są rozwiązane

- **Transformacja tworzy blok zawierający dla `position: fixed`** — ta sama
  pułapka, którą offcanvas omija portalem do `<body>`. ScrollTrigger przypina
  właśnie przez `position: fixed`, więc **drugi przypinany element niżej na
  stronie przestałby działać**. Skrypt to wykrywa i wtedy **nie włącza
  podglądu**, mówiąc o tym w konsoli — zamiast po cichu zepsuć tamtą sekcję.

- **Za zakresem transformacja jest kasowana**, żeby nie zostawiać bloku
  zawierającego na resztę strony. Przed zakresem musi zostać: bez niej treść
  skacze o `D` przy dojeżdżaniu do sekcji.

- **Sekcja na cały ekran nie ma czego pokazać** — podgląd działa, ale nie
  zostaje ani piksel. Skrypt mówi o tym w konsoli, zamiast milczeć.

### Uprzątnięte

- **Trzy „ostrożności" wyleciały jako martwy kod.** Mutacje pokazały, że dawały
  się wyciąć bez jednego czerwonego sprawdzenia: druga próba zbudowania
  podglądu po odświeżeniu ScrollTriggera (zapas istnieje już przy tworzeniu
  wyzwalacza, także w trakcie odświeżania) oraz ręczne `kill()` z `clearProps`
  przy cofaniu bloku — `gsap.matchMedia()` cofa wszystko, co w jego bloku
  powstało, razem z transformacją. Komentarz w kodzie mówi, dlaczego tego tam
  nie ma, żeby nie wróciło.

## [1.104.0] — 2026-08-21

### Zmienione

- **Marquee: galeria przez dane dynamiczne Bricksa — jedno pole zamiast
  czterech.** 1.103.0 kazało wskazywać źródło („z bieżącego wpisu" / „ze strony
  ustawień" / „ze wskazanego wpisu"), klucz grupy, klucz pola i numer wpisu.
  Czyli ręczne odtwarzanie tego, co Bricks ma pod piorunkiem. Zgłoszone
  z użycia: *„mam te dane w danych dynamicznych, wystarczyłoby podać klucz"*.

  Zostało **jedno pole z piorunkiem**. Klikasz, wybierasz z listy wariant
  oddający listę ID — w Evoke Fields to „(lista ID)", czyli tag z końcówką
  `__ids` — i tyle.

  Skutek uboczny jest tu ważniejszy niż sama wygoda: **element przestał wiedzieć
  o Evoke Fields**. Nie ma już `evk_get_field()`, `evk_get_option_field()` ani
  warunku `function_exists()` na tamtą wtyczkę. Marquee zna wyłącznie listę
  numerów załączników, więc zadziała z ACF, Metaboksem i każdym innym tagiem,
  który taką listę odda. Kod elementu skurczył się przy tym o 38 linii.

  Kolejność (jak w galerii / odwrotna / losowa) i limit zostają bez zmian.

### Rzecz, o którą łatwo się potknąć

- **Goły tag galerii oddaje adres PIERWSZEGO obrazu, nie listę ID.** Marquee
  nie pokaże wtedy nic i powie o tym na kanwie buildera — zamiast pokazać jedno
  zdjęcie, które wyglądałoby na działającą galerię i przepuściło pomyłkę
  w wyborze wariantu. Sprawdzenie tego pilnuje.

### Uwaga przy aktualizacji

Wiersze galerii wstawione w 1.103.0 trzeba ustawić od nowa — cztery stare pola
zniknęły. Wierszy „tekst" i „obraz" to nie dotyczy.

## [1.103.1] — 2026-08-21

### Naprawione

- **Marquee: wróciło dokładanie elementów w builderze.** 1.103.0 dało dwóm polom
  galerii `required` złożone z **dwóch warunków**
  (`['type','=','gallery','gallery_source','=','option']`). Łańcuchy warunków
  działają w kontrolkach GÓRNEGO POZIOMU — używa ich Horizontal Scroll — ale
  w polach wiersza repeatera nie miały w tej wtyczce ani jednego precedensu.
  Repeater przestał dokładać wiersze, a z ekranu wyglądało to jak martwy
  przycisk „+".

  Wzorcem jest repeater Animatora (`includes/anim/bricks-controls.php`), jedyny
  sprawdzony w boju: pojedynczy warunek, także z `!=`, i tablica jako trzeci
  człon. Oba pola mają teraz po jednym warunku — na samo źródło galerii. Nic to
  nie zmienia w praktyce, bo „ze strony ustawień" i „ze wskazanego wpisu" da się
  wybrać wyłącznie w wierszu typu „galeria".

  Sprawdzenie pilnuje teraz KAŻDEGO pola wiersza: żadne nie może mieć łańcucha
  warunków. Tego nie widać w znaczniku — pole po prostu nie pokazuje się
  w builderze — więc mierzone jest na kształcie tablicy.

## [1.103.0] — 2026-08-21

### Dodane

- **Marquee: pozycja jako galeria z Evoke Fields.** Repeater przyjmował dotąd
  jeden tekst albo jeden obraz na wiersz — przy pasku logotypów znaczyło to
  tyle wierszy, ile logotypów, i drugie tyle roboty przy każdej zmianie.
  Teraz **jeden wiersz wskazuje pole galerii, a marquee rozwija je we wszystkie
  obrazy**: dokładasz zdjęcie w Evoke Fields, taśma nadąża sama.

  **Trzy źródła**, bo galeria bywa w trzech miejscach: przy bieżącym wpisie,
  na stronie ustawień (jedna dla całej witryny) albo przy zupełnie innym wpisie
  wskazanym numerem. „Bieżący wpis" to zero podane wtyczce — `evk_get_field()`
  sam podstawia za nie `get_the_ID()`.

  **Kolejność i limit**: jak w galerii, odwrotna albo losowa, plus „ile obrazów".
  Kolejność liczy się PRZED limitem, więc „odwrotna + 3" daje trzy OSTATNIE
  obrazy galerii, a nie trzy pierwsze ustawione tyłem. Losowanie zapada **raz**,
  przed zbudowaniem taśmy: marquee zapętla się tym, że druga kopia jest co do
  znaku identyczna z pierwszą, a dwa osobne losowania dałyby przeskok na
  złączeniu.

  Szerokość bierze istniejące pole „Szerokość obrazu", wspólne z typem „Obraz".

### Rzeczy, o które łatwo się potknąć — i jak są rozwiązane

- **Dwa źródła oddają dwa różne kształty.** Pole wpisu z wariantem `ids` wraca
  z Evoke Fields **tekstem po przecinkach**, a pole ze strony ustawień —
  **surową tablicą wierszy** `['img' => ID, 'cat' => kategoria]`, bo tamtej
  drogi wtyczka nie formatuje wcale. Marquee ma jedno wejście przyjmujące oba
  kształty; bez niego wariant „ze strony ustawień" po cichu nie pokazywałby nic.

- **Evoke Fields może nie być zainstalowane.** Cała droga stoi za
  `function_exists()`: bez wtyczki wiersz galerii znika, a reszta taśmy jedzie
  dalej. Nieznana funkcja zabiłaby render CAŁEJ strony, nie samo marquee.

- **Pusty wynik na froncie milczy**, a mówi tylko na kanwie buildera. Pudełko
  zastępcze w środku strony byłoby widocznym śmieciem.

- **Obraz, którego nie ma w bibliotece, wypada w całości** — razem z pudełkiem.
  Puste pudełko rozpychałoby odstępy taśmy w miejscu, w którym nic nie widać.

### Testy

Marquee miało 12 sprawdzeń i **żadnego** na `render()` — doszły 34, mierzone na
znaczniku z prawdziwego `element.php`. Nowy harness `tests/php/marquee-gallery.php`
i atrapy Evoke Fields, które zapisują, z czym je wywołano: „bieżący wpis"
wygląda na ekranie tak samo jak wpisany numer, więc inaczej nie dałoby się tego
odróżnić.

## [1.102.0] — 2026-08-21

### Naprawione

- **Horizontal Scroll: zgubiona kreska nad kartami.** Po przeniesieniu wskaźnika
  do zewnętrznego bloku (1.101.0) nad kartami zostawała ciemna linia. To był
  **zapasowy wskaźnik z PHP-a**: `element.php` drukuje go zawsze, bo nie wie,
  czy selektor kontenera w ogóle trafi — a `--top` to `position: absolute`
  z własnym tłem i `z-index: 10`, czyli wstęga leżąca NA górnej krawędzi kart.
  Po udanym trafieniu selektora skrypt ten węzeł usuwa. Nietrafiony selektor
  dalej ma do czego wrócić, bo usunięcie następuje **po** rozstrzygnięciu.

  Wolno też wskazać selektorem ten sam, wewnętrzny węzeł — wtedy zostaje na
  miejscu, bo inaczej wskaźnik jechałby w elemencie oderwanym od dokumentu.

### Dodane

- **Odstęp paneli od wskaźnika.** Wskaźnik wewnętrzny jest pozycjonowany
  absolutnie, więc bez odstępu przykrywa górę pierwszej karty. Nowa kontrolka
  odsuwa **taśmę**, a nie przestawia wskaźnik — przestawienie go do przepływu
  zmieniłoby układ każdemu, kto już go używa. Pionowy odstęp nie rusza
  matematyki przewijania: zakres liczy się z szerokości taśmy. Puste = 0,
  jak dotąd. Przy wskaźniku w zewnętrznym bloku pole się nie pokazuje, a klasa
  sterująca schodzi z korzenia razem z usuniętym węzłem.

- **Grubość i zaokrąglenie kreski.** `--evk-seg-h` istniała od 1.100.0 **bez
  żadnej kontrolki** — dawało się ją ustawić tylko własnym CSS-em. Teraz ma
  swoje pole, razem z zaokrągleniem.

- **Wielkość i grubość pisma numeru** w trybie „Tylko bieżący". Numer
  dziedziczył je po bloku, w którym stoi — a wskaźnik wolno postawić
  gdziekolwiek, więc „gdziekolwiek" bywa akapitem 16 px.

- **Tło, wewnętrzny odstęp i zaokrąglenie całego wskaźnika.**

- **„Pozostałe pozycje: Schowaj / Przygaś"** w trybie „Tylko bieżący".
  „Przygaś" daje klasyczne „1 2 3 4" z podświetloną bieżącą pozycją; pozostałe
  biorą kolor nieaktywnych. Domyślne zostaje „Schowaj", czyli zachowanie
  z 1.101.0.

### Zmienione

- **Kolor numeru rozdzielony na dwa stany.** Do 1.101.0 numer brał kolor
  bieżącego **bezwarunkowo** — uchodziło to na sucho, bo reszty i tak nie było
  widać. Przy przygaszaniu wszystkie byłyby podświetlone.

- **„Kolor tła" działa teraz przy każdym stylu wskaźnika**, nie tylko przy
  jednej kresce. Przy kreskach i numerach domyślnie tła dalej nie ma — byłoby
  kreską pod kreskami.

- Etykiety kolorów przestały mówić „segment": przy numerach malują tekst, nie
  kreskę. Klucze kontrolek zostały, więc zapisane wartości też.

## [1.101.0] — 2026-08-21

### Dodane

- **Horizontal Scroll: wskaźnik postępu gdziekolwiek na stronie.** Do tej wersji
  wskaźnik mógł stać wyłącznie WEWNĄTRZ elementu, przyklejony do jego górnej albo
  dolnej krawędzi. W praktyce jego miejsce jest zupełnie gdzie indziej — w rogu
  nagłówka sekcji, obok akapitu z opisem, czyli w cudzym bloku Bricksa.

  **Kontrolka „Kontener wskaźnika (selektor)"** — pusta znaczy „jak dotąd,
  w środku". Podany selektor przenosi wskaźnik do dowolnego elementu na stronie.
  Szukany jest **`document.querySelector`, nie `closest`**, i to jest odwrotnie
  niż przy kontrolce „Selektor przodka" z 1.100.0: tam chodziło o PRZODKA taśmy,
  tutaj cel leży POZA elementem, zwykle w innej gałęzi drzewa. Gdy nic nie
  pasuje — ostrzeżenie z nazwą selektora i powrót do wskaźnika wewnętrznego.

  **Dwie drogi, zależnie od tego, co jest w kontenerze.** Pusty skrypt wypełnia
  sam. Kontener z własną treścią zostawia nietknięty i tylko wpina `is-active`
  w bieżące dziecko — tędy robi się „01 · ROZMOWA" zamiast kresek. Gdy dzieci
  jest mniej niż paneli, część stanów nie ma czego podświetlić; skrypt mówi
  o tym w konsoli, zamiast milczeć.

- **Trzeci styl wskaźnika: „Tylko bieżący — resztę chowaj".** Z całego rzędu
  widać jedną pozycję. W pustym kontenerze skrypt pisze **numery kart** (1, 2,
  3…) — goła kreska nie niosłaby tam żadnej informacji.

- **Długość kresek z kontrolki.** „Długość segmentu" pusta znaczy „rozciągnij"
  (kreski dzielą szerokość kontenera po równo, jak dotąd), podana — zamraża.
  „Długość segmentu aktywnego" pokazuje się dopiero przy ustalonej długości:
  przy rozciąganiu „dłuższa aktywna" znaczyłaby współczynnik rozrostu, czyli
  zupełnie inny model.

### Zmienione

- **Wskaźnik nie jest już pozycjonowany sam z siebie.** `position: absolute`
  siedziało na klasie `.evk-hscroll__progress`, a tę klasę dostaje teraz także
  kontener spoza elementu — czyli wyrwałoby cudzy blok z układu strony
  i przykleiło do krawędzi najbliższego przodka z pozycjonowaniem.
  Pozycjonowanie zeszło do `--top` i `--bottom`, które właśnie o to proszą,
  a PHP zawsze emituje jeden z nich dla wskaźnika wewnętrznego. Dla wskaźnika
  w środku elementu nic się nie zmienia.

- **Grubość, kolor tła i kolor paska jadą teraz zmiennymi CSS**
  (`--evk-prog-h`, `--evk-prog-bg`, `--evk-prog-fill`) zamiast regułami na
  potomka. Reguła `#brxe-xxx .evk-hscroll__progress { … }` nie sięga kontenera,
  który nie jest potomkiem korzenia; zmienną skrypt potrafi przepisać — tym
  samym sposobem, którym offcanvas ratuje swoje zmienne przy portalu do
  `<body>`. Zapisane wartości kontrolek zostają bez zmian.

- Kolory i odstęp segmentów pokazują się też przy stylu „Tylko bieżący", a nie
  wyłącznie przy segmentach.

## [1.100.0] — 2026-08-19

### Dodane

- **Horizontal Scroll: przypinanie PRZODKA i karty własnej szerokości.** Element
  znał dotąd jeden układ — „slajder na pełny ekran": przypinał sam siebie,
  a panelom narzucał szerokość (100% elementu albo 100vw) i wysokość. Zamówiony
  jest drugi: **sekcja z nagłówkiem staje w miejscu**, a pod nagłówkiem jedzie
  taśma kart **węższych niż ekran**. Po dojechaniu ostatniej strona przewija się
  dalej.

  Trzy nowe rzeczy, każda osobno przełączalna:

  **Kontrolka „Co przypiąć"** — ten element (jak dotąd, domyślnie), bezpośredni
  rodzic albo przodek wskazany selektorem. Selektor rozwiązywany przez
  **`closest()`, nie `document.querySelector()`**, i to nie jest drobiazg:
  przy dwóch takich sekcjach na stronie `querySelector` przypiąłby obu taśmom tę
  samą, pierwszą sekcję, a druga skakałaby przy przewijaniu pierwszej. Gdy nic
  nie pasuje — ostrzeżenie z nazwą selektora i powrót do przypinania siebie.

  Razem z pinem przenosi się **wyzwalacz**. Zostawiony na taśmie ruszałby
  dopiero, gdy jej górna krawędź dojedzie do góry ekranu — czyli po przewinięciu
  nagłówka poza kadr, a więc dokładnie za późno.

  **Tryb szerokości „z buildera"** — skrypt nie dotyka ani `width`, ani
  wysokości paneli, a arkusz przestaje narzucać `height: 100vh` korzeniowi,
  taśmie i kartom. Karty stylujesz zwyczajnie w Bricksie; skrypt liczy tylko,
  o ile przesunąć taśmę. Zostaje `overflow: hidden` — bez niego karty wystające
  poza element rozpychają stronę w poziomie.

  **Wskaźnik segmentowy** — zamiast jednej rosnącej kreski tyle kresek, ile kart,
  z podświetloną bieżącą. Segmenty buduje **JS**, bo zna prawdziwą liczbę paneli;
  PHP musiałoby ją zgadywać z drzewa dzieci Bricksa. Trzy własne kontrolki:
  odstęp oraz kolor aktywnego i nieaktywnego — to inne role niż „tło" i „pasek"
  przy jednej kresce, więc dostają własne pola zamiast dziedziczyć mylące.

  **Nikomu nic się nie zmienia.** Domyślne zostają: „ten element", „wypełnij
  element", „jedna kreska".

- **Kontrolka startu przyjmowała przesunięcie od zawsze** — `top top+=100`
  przypnie sto pikseli niżej, tyle, ile zajmuje przyklejony nagłówek. Wartość
  szła wprost do ScrollTriggera i nikt tego nie napisał; jest teraz w opisie
  kontrolki, zamiast drugiej drogi do tej samej rzeczy.

### Uwaga dla testów

- **Element dostał PIERWSZE testy.** Do 1.100.0 nie miał ani jednego — a od tej
  wersji ma dwa układy, które łatwo pomylić. Strona testowa ma **dwie takie same
  sekcje**, bo różnicy między `closest()` a `querySelector()` nie da się
  zobaczyć, dopóki pasująca sekcja jest jedna.

- **Punkt odniesienia dla przewijania odczytujemy RAZ.** Pozycja sekcji liczona
  z jej prostokąta jest prawdziwa tylko do chwili przypięcia: PRZYPIĘTA sekcja
  ma ten prostokąt zawsze przy zerze, więc drugi odczyt zwraca bieżącą pozycję
  przewijania, nie miejsce sekcji w dokumencie. Zmierzone: kolejne „przewiń
  o 600" lądowało 900 px dalej i „nagłówek stoi" świeciło na czerwono mimo
  poprawnego kodu.

- **Jedna mutacja okazała się równoważna i to też jest wynik.** `pin: true`
  zamiast `pin: pinEl` nie zmienia dziś niczego, bo wyzwalacz jest tym samym
  elementem. Forma jawna zostaje mimo to: trzyma pin przy właściwym elemencie
  niezależnie od wyzwalacza, więc gdyby ten kiedyś wrócił na taśmę, `true`
  przypięłoby po cichu taśmę.

## [1.99.1] — 2026-08-19

### Naprawione

- **Pozycja menu zbudowana ze zwykłego `<span>` nie była przyciskiem dla nikogo
  poza myszą.** Zgłoszone z PageSpeed jako *„Elements must only use permitted
  ARIA attributes"*, a nieprawidłowym elementem był
  `<span aria-label="Home" data-evk-oc-go="1">`.

  Zarzut jest słuszny i nie chodzi o sam atrybut. Element **bez roli** ma rolę
  `generic`, a ta nie ma prawa mieć nazwy — `aria-label` jest tam zabroniony.
  Bricks daje ten atrybut swoim odnośnikom tekstowym, więc sam z siebie nie
  zawinił; zawiniło to, że **my** robiliśmy z takiego spana sterowanie, nie
  mówiąc o tym drzewu dostępności.

  **Cichszy, ale poważniejszy skutek** audyt przemilcza: taki span nie wchodził
  do pułapki fokusu (`focusables()` szuka odnośników, przycisków i elementów
  z `tabindex`), więc **klawiaturą nie dało się wejść w podmenu**. To gorsze niż
  zły atrybut.

  Element, który staje się sterowaniem (`data-evk-oc-go`, `-back`, `-close`),
  dostaje teraz `role="button"`, `tabindex="0"` i obsługę Enter oraz spacji —
  `<span role="button">` nie zamienia tych klawiszy w kliknięcie sam z siebie,
  więc rola bez nich byłaby obietnicą bez pokrycia. Przy okazji znika zarzut
  z audytu: na `role="button"` `aria-label` jest jak najbardziej dozwolony.

  **Nie ruszamy tego, co interaktywne z natury** — `<a href>` i `<button>` mają
  rolę, fokus i klawiaturę od siebie, a nadpisanie roli odebrałoby odnośnikowi
  jego własną. Nie ruszamy też **cudzego `tabindex`**: Circular Menu steruje nim
  u siebie, żeby chować zamknięty panel przed tabulatorem, i nadpisanie zerem
  odsłoniłoby go na stałe.

  Uwaga na marginesie zgłoszenia: `tabindex="-1"` widoczne w raporcie **nie
  pochodzi z Offcanvasu** — w całej wtyczce zapisuje je wyłącznie Circular Menu,
  i tylko wewnątrz własnego panelu, gdzie jest zamierzone.

## [1.99.0] — 2026-08-19

### Zmienione

- **Wygięta ściana jedzie teraz ścieżką SVG, tak jak wzór.** Zgłoszone z użycia
  po 1.98.0: *„nie jestem zadowolony z tej wygiętej linii CSS. Tam jest płynniej
  i po prostu ładniej"*. Uczciwa ocena — 1.98.0 dawało **łuk eliptyczny**
  (`border-radius`), a wzór rysuje **kwadratową Bézierę**, i widać to gołym okiem.

  Kształt daje teraz `<clipPath clipPathUnits="objectBoundingBox">` z animowanym
  atrybutem `d`. Trzy stany o **tej samej budowie poleceń** (żeby interpolowały
  się liczba po liczbie), a oś czasu prowadzi je **dwustopniowo**: 0,66 czasu
  na wybrzuszenie krzywą `power2.in`, 0,34 na wyprostowanie krzywą `power2.out`.
  Dokładnie tak liczy to wzór.

  Zmierzone na panelu 420 px: przy pełnej sile środek brzegu wyprzedza rogi
  o **100 px**, czyli o ćwierć panelu — tyle samo, ile u nich.

  **Dlaczego nie dało się bez GSAP-a:** ścieżki nie zapisze ani promień
  narożnika, ani `clip-path: path()` w procentach (ta funkcja zna wyłącznie
  jednostki użytkownika), a `d` jako właściwość CSS nie działa w Firefoksie.
  Zostaje wpisywanie atrybutu co klatkę.

  **GSAP doładowuje się TYLKO przy tym efekcie** — zależność dopisuje się
  w `enqueue_scripts()` po odczytaniu ustawienia, więc strony bez wygiętej
  ściany nie pobierają ani bajta więcej. Gdyby biblioteki mimo wszystko
  zabrakło, menu otwiera się bez wygięcia i mówi o tym w konsoli.

- **Wygięta ściana jest trzecim EFEKTEM OTWIERANIA, nie dodatkiem do niego.**
  W 1.98.0 był to osobny włącznik obok „Efektu otwierania" — i to było mylące,
  bo we wzorze wygięcie **zastępuje** wysuwanie: kadr stoi w miejscu, a całą
  robotę robi rosnące obcięcie. Osobny włącznik obiecywał składanie, którego
  nie ma. Kontrolka „Efekt otwierania" ma teraz trzy pozycje: wysuwanie,
  odsłanianie, wygięta ściana. „Siła wygięcia" pokazuje się przy trzeciej.

### Uwaga dla testów

- **Pomiar musiał zmienić punkt zaczepienia.** W 1.98.0 brzeg zawsze dotykał
  krawędzi kadru na środku, więc wystarczył jeden punkt tuż przy niej. Obcięcie
  ROŚNIE, więc w połowie drogi brzeg stoi w środku panelu i przy krawędzi nie ma
  treści na żadnej wysokości — taki pomiar pokazywałby ciemno zawsze. Mierzona
  jest teraz GŁĘBOKOŚĆ brzegu na dwóch wysokościach, a wygięcie to różnica
  między nimi.

- **Kontrola negatywna złapała błąd w samym pomiarze.** Sprawdzenie „otwarte
  pokazuje wszystko" świeciło na czerwono przy menu z lewej i z góry: 4 px
  obcięcia, którego nie było. `getBoundingClientRect().right` i `.bottom` są
  WYŁĄCZNE, więc próbka dokładnie na nich pada już poza kadrem. Skan jest
  odsunięty o piksel w głąb.

- **Dwie mutacje okazały się bez znaczenia i to też jest wynik.** Przestawienie
  osi w kształcie ZAMKNIĘTYM i OTWARTYM dla menu z góry nie zmienia niczego:
  zamknięty jest zdegenerowany, a otwarty to pełne pudełko — oba opisują to samo
  niezależnie od osi. Oś niesie wyłącznie kształt POŁOWY i tam mutacja zapala
  sprawdzenie na czerwono.

## [1.98.1] — 2026-08-19

### Naprawione

- **Budowa klonów podmiany przeplatała odczyty stylu z zapisami do drzewa.**
  Zgłoszone z PageSpeed: w „wymuszonym przeformatowaniu" `animator.js` był
  najdroższą pozycją spośród naszych.

  Pętla budująca klony czytała styl maski, dokładała do niej klon i czytała
  następną. Każdy dołożony klon unieważnia styl, więc **następny odczyt wymusza
  jego przeliczenie** — tyle razy, ile jest kawałków. Przy podziale na ZNAKI to
  setki razy na jedną stronę.

  Odczyty idą teraz jednym przebiegiem przed wszystkimi zapisami.

  Zmierzone przez `Performance.getMetrics` na stronie z 494 klonami:

  | | przeliczeń stylu | czas układu |
  |---|---|---|
  | przed | 779 | 70–75 ms |
  | po | **539** | **22–28 ms** |

  Liczba przeliczeń UKŁADU się nie zmienia (31) — zmienia się ich koszt, bo
  styl nie jest już wtedy brudny.

### Uwaga dla testów

- **Test mierzy KOLEJNOŚĆ, nie zegar.** Liczby z metryk zależą od maszyny i od
  tego, co akurat robi przeglądarka; przeplot odczytów z zapisami jest faktem
  binarnym. Szpieg liczy odczyty stylu następujące po dołożeniu klonu:
  1 przy rozdzielonych fazach, 40 przy przeplocie (na 40 klonach).

- **Szpieg musiał zawęzić się do węzłów W DOKUMENCIE.** Pierwsza wersja liczyła
  wszystkie odczyty i pokazywała przeplot przy każdym klonie także PO poprawce —
  bo GSAP czyta styl przy każdym `set()` na świeżo sklonowanym kawałku, a ten
  jest jeszcze odłączony od drzewa i nie ma czego przeliczać. Pomiar mierzył
  wtedy GSAP-a, nie nas.

## [1.98.0] — 2026-08-19

### Dodane

- **Offcanvas: wygięta ściana.** Zamówione ze wzoru `nextbricks.io`. Krawędź
  panelu wygina się w trakcie ruchu: **wyprzedza na środku, zostaje w tyle przy
  rogach**, a na końcu wraca do prostej. Dwie nowe kontrolki — włącznik
  „Wygięta ściana" i „Siła wygięcia" (0–1).

  Efekt jest **niezależny od tego z 1.97.0**: dotyczy kształtu kadru, a nie
  tego, co robi treść, więc składa się i z wysuwaniem, i z odsłanianiem.

  **Wzór robi to inaczej i świadomie tego nie powtarzam.** Ich element podmienia
  wielokąt `clip-path` na SVG-owy `<clipPath clipPathUnits="objectBoundingBox">`
  i animuje GSAP-em atrybut `d` ścieżki, dwustopniowo (0,66 czasu na
  wybrzuszenie, 0,34 na wyprostowanie). Nasze menu jedzie na **przejściach CSS
  i nie ma zależności od GSAP-a** — przeniesienie go na oś czasu w JS
  przepisałoby to, na czym stoi trzydzieści kilka sekcji testów: krzywe przez
  `CSS.supports()`, zmienne `--evk-oc-*`, redukcja ruchu przez zapytanie
  medialne.

  Kształt daje więc `border-radius`, a nie ścieżka. Rzecz, która to umożliwia:
  **`border-radius` obcina potomków**, gdy element ma `overflow: clip` — a kadr
  ma je od 1.62.0. Promień na obu rogach jednej krawędzi robi z niej soczewkę:
  przy rogach cofniętą, na środku sięgającą pełnej krawędzi. Zmierzone punkt po
  punkcie na obrazie: przy pełnej sile treść zaczyna się dopiero sto pikseli od
  krawędzi w rogu, a na środku wysokości od samego skraju.

  Promienie **w procentach**, więc kształt skaluje się z panelem — ta sama
  krzywizna na wąskim telefonie i na szerokim ekranie. Dwustopniowy profil
  wymagał **klatek, nie przejścia**: przejście interpoluje z punktu do punktu
  i nie ma jak mieć szczytu w środku drogi. Szczyt na 66%, tak jak we wzorze.

  Różnica wobec oryginału jest jedna i warto ją znać: łuk jest **eliptyczny**,
  a nie kwadratową Bézierą. Kształt bardzo bliski, nie identyczny co do piksela.

  Zamykanie gra tę samą krzywą **wspak** — wzór odwraca całą swoją oś czasu.
  Wymagało to własnego zaczepu na czas wyjazdu kadru: `is-open` już go wtedy
  nie ma, a bez niego ściana prostowałaby się w jednej klatce.

  **Koszt do odnotowania:** `border-radius` to właściwość malowania, nie
  kompozycji, więc każda klatka wygięcia przerysowuje obciętą zawartość kadru.
  Wzór ma ten sam koszt (animowany `clipPath` też przerysowuje). Dlatego efekt
  jest domyślnie **wyłączony**.

### Uwaga dla testów

- **Kształt mierzony PIKSELAMI ZRZUTU EKRANU**, nie hit-testem i nie wartością
  obliczoną. Oba obejścia sprawdzone i oba kłamią: `elementFromPoint` zwraca
  panel także tam, gdzie obrazu nie ma, a `border-radius` w procentach nie jest
  rozwiązywany do pikseli w wartości obliczonej — przeglądarka zwraca „30%",
  więc z niej samej nie widać ani głębokości, ani tego, czy cokolwiek zostało
  obcięte.

- **Kadr trzeba ZAMROZIĆ, żeby cokolwiek zmierzyć.** W ruchu zrzut ekranu
  i `getBoundingClientRect()` pochodzą z różnych chwil — zmierzone, rozjazd
  sięgał 25 px i próbka trafiała obok kształtu, pokazując tło zamiast panelu.
  Pomiar przewija przejścia na koniec, a samą animację wygięcia na zadany
  ułamek jej czasu.

## [1.97.2] — 2026-08-19

### Naprawione

- **Podzielone teksty znów mrugały przed animacją.** Zgłoszone z użycia:
  *„elementy, które powinny pojawić się po raz pierwszy, są widoczne przed
  rozpoczęciem animacji i po opóźnieniu się animują. Jest ogólnie duże
  opóźnienie"*.

  To ten sam błysk, dla którego w **1.27.2** powstała zasłona: element stoi
  wyrenderowany normalnie, potem skacze do stanu początkowego i dopiero animuje.
  Przywróciło go **1.96.0** — i to połowicznie, co jest tu całą trudnością.
  Zasłona jest **jedna na cały dokument** i schodzi, gdy silnik skończy pierwszy
  przebieg. Odkąd na fonty czeka już tylko podział tekstu, element z podziałem
  kończy w tej chwili dopiero połowę drogi: jego animacja powstanie sekundę
  później, po `document.fonts.ready`. Zasłona schodziła więc **z niego też** —
  i przez tę sekundę stał widoczny, po czym skakał do „from" i grał.

  Zasłona jest teraz dwuwarstwowa. Ta na dokumencie schodzi jak dotąd, bo reszta
  strony nie ma powodu czekać. Element, który jeszcze czeka, dostaje **własny
  znacznik** i zostaje niewidoczny dokładnie tak długo, jak czeka — nie dłużej.

- **Zwłoka po wczytaniu fontów.** 1.96.0 przepuszczało dobudowanie podziału
  przez kolejkę bezczynności z limitem 200 ms — słusznie przy zmianie progu
  szerokości, ale nie tutaj: odłożony element czeka pod zasłoną, więc każda
  milisekunda zwłoki to milisekunda pustego miejsca w treści. Powód, dla którego
  ta kolejka tam trafiła, obsługuje dziś kto inny: przeliczenie wyzwalaczy idzie
  przez `evkOdswiez`, który sam czeka, aż przewijanie ustanie. Podział buduje się
  więc od razu.

- **Redukcja ruchu nie czeka już na fonty.** Nic się wtedy nie animuje i nic nie
  dzieli, więc metryki fontu są bez znaczenia — a czekanie na nie trzymało treść
  pod zasłoną przez sekundę u tych, którzy ruch wyłączyli właśnie po to, żeby
  strona zachowywała się spokojnie.

- **Bezpiecznik obejmuje nową zasłonę.** Webfont, który nie dojedzie nigdy,
  trzymałby podzielone teksty ukryte bez końca — czyli awaria fontu zabierałaby
  stronie nagłówki. Ten sam trzysekundowy zegar, który zdejmuje zasłonę
  dokumentu, zdejmuje teraz także znaczniki pojedynczych elementów.

### Uwaga dla testów

- **Reguła zasłony przestała być kopiowana do fixture'a.** Fixtures miały ją
  przepisaną u siebie, więc „element czekający jest niewidoczny" przechodziłoby
  nawet wtedy, gdyby wtyczka przestała ją drukować. Idzie teraz z PHP
  (`tests/php/anim-preveil.php`), razem z mikroskryptem i bezpiecznikiem.

- **Pomiar zwłoki idzie mikrozadaniem, nie zegarem.** Zmierzone:
  `requestIdleCallback` na bezczynnym wątku wypala natychmiast, więc czekanie
  „krócej niż limit 200 ms" NIE odróżniało kolejki od jej braku — mutacja
  przywracająca kolejkę przechodziła na zielono. Limit jest górną granicą, nie
  zwłoką. `document.fonts.ready.then` biegnie jako mikrozadanie, więc budowanie
  wprost kończy się, zanim przeglądarka weźmie jakiekolwiek zadanie; kolejka
  bezczynności to zadanie i po dwóch obrotach mikrokolejki nie zdąży wypalić.

## [1.97.1] — 2026-08-19

### Naprawione

- **Offcanvas w nagłówku otwierał się w builderze „tylko w świetle tego bloku".**
  Zgłoszone z użycia, razem z drugą połową objawu: *„przeniesienie do body nic
  nie zmienia"*. I to ta druga połowa była wskazówką — kontrolka **nic nie
  zmieniała, bo nic jej nie czytało**. Warunek portalu brzmiał
  `usePortal && !isBuilder`, więc na kanwie powłoka zawsze zostawała w korzeniu.

  Sama przyczyna leży w bloku zawierającym: `position: fixed` liczy się od okna
  **tylko wtedy**, gdy żaden przodek nie tworzy dla niego bloku zawierającego.
  Tworzy go `transform`, `perspective`, `filter`, `backdrop-filter`, `contain`
  i `will-change` zapowiadające którąkolwiek z tych rzeczy — a w nagłówku to nie
  jest egzotyka: przyklejony nagłówek, animacja wejścia z Animatora, cudzy efekt
  na sekcji. Na froncie problem nie występował, bo tam powłoka i tak jechała do
  `<body>` i zostawiała takiego przodka za sobą.

  Portal działa teraz **także w builderze**. Cena jest jedna i warto ją znać:
  panel przeniesiony do `<body>` przestaje być potomkiem węzła elementu, więc
  Bricks szuka go po `id`, a nie po pokrewieństwie. Za to menu wygląda na kanwie
  tak, jak będzie wyglądać na stronie — a to jest sens edycji na żywo.

- **Powłoka nie zostaje po przerysowaniu elementu.** Bricks podmienia węzeł
  elementu przy **każdej** zmianie ustawienia. Póki powłoka siedziała w korzeniu,
  znikała razem z nim; powłoka w `<body>` korzenia nad sobą nie ma i zostawałaby
  po każdej edycji. Po kilkunastu kliknięciach w kanwie leżałby stos martwych
  menu z nieaktualną treścią, każde na `z-index: 99990`.

  Korzeń dostaje własny identyfikator, powłoka jego odcisk — i przy każdym
  uruchomieniu znikają te powłoki, których korzenia nie ma już w dokumencie.
  Wiązanie własnym identyfikatorem, a nie `id` Bricksa: nasz ginie razem
  z przerysowanym korzeniem i właśnie po tym poznajemy sierotę.

### Dodane

- **Element mówi, kto go zamyka.** Gdy powłoka nie ma jak rozciągnąć się na całe
  okno, w konsoli ląduje ostrzeżenie z **nazwiskiem przodka**, który tworzy blok
  zawierający, i z podpowiedzią, co z tym zrobić. Bez tego objaw jest nie do
  rozszyfrowania z zewnątrz: menu otwiera się w prostokącie nagłówka, a
  w ustawieniach elementu nie ma niczego, co by to tłumaczyło.

## [1.97.0] — 2026-08-19

### Dodane

- **Offcanvas: drugi efekt otwierania — „odsłanianie".** Menu miało dotąd jeden
  sposób wjazdu: kadr wysuwał się zza krawędzi i **wwoził treść ze sobą**. Nowa
  kontrolka „Efekt otwierania" daje drugi: treść stoi w oknie od pierwszej
  klatki, a przesuwa się sama płaszczyzna panelu — jego krawędź przejeżdża po
  gotowej treści i ją odsłania.

  ```
  start:   [okno ..................|▓treść▓]   kadr poza ekranem, treść pod obcięciem
  połowa:  [okno .........|▓▓treść▓▓|      ]   krawędź kadru sunie w lewo
  koniec:  [okno .........|▓▓treść▓▓]          treść ANI DRGNĘŁA
  ```

  Mechanika jest w całości w arkuszu i sprowadza się do jednej rzeczy: kadr
  jedzie o **własną szerokość**, a opakowanie treści dostaje transformację
  dokładnie przeciwną — też o własną szerokość. Jest ono blokiem w kadrze, więc
  obie wartości są tą samą liczbą i znoszą się co do piksela; `overflow: clip`
  na kadrze obcina tę część treści, nad którą kadr jeszcze nie dojechał.

  Wtyczka mówiła już zresztą tym językiem: przy menu z lewej podmenu „wysuwa
  się spod panelu głównego”, bo slot je tam obcina (1.65.1). Odsłanianie to ta
  sama myśl zastosowana do otwierania całego menu.

  **Przeciw-transformacja siedzi na WŁASNYM węźle** (`.evk-oc-hold`), a nie na
  taśmie paneli. Taśma ma już właściciela: `applyState()` wpisuje jej
  `style.transform` przy przechodzeniu między panelami, a styl w atrybucie
  wygrywa z arkuszem. Dwie rzeczy na jednym transformie to dokładnie ta klasa
  usterek, którą ten element zbierał przez cztery rundy w 1.62.0–1.65.1.

  Domyślne zostaje **wysuwanie**, więc po aktualizacji nikomu nic nie zmienia
  się pod ręką. Czas i krzywa rządzą obydwoma efektami tak samo — nowy węzeł
  bierze `--evk-oc-time` i `--evk-oc-ease` wprost od kadru. Osobne tempo
  znaczyłoby, że treść w trakcie ruchu odpływa i wraca: na oko wygląda to jak
  drganie i nie da się zgadnąć, skąd się bierze.

  Działa z każdej z czterech krawędzi i tak samo przy zamykaniu.

### Uwaga dla testów

- **Sprawdzenie redukcji ruchu było puste i mutacja to pokazała.** „Czas
  przejścia wynosi zero" przechodziło także z regułą WYCIĘTĄ z bloku
  `@media (prefers-reduced-motion: reduce)` — bo przy starcie z redukcją skrypt
  sam zeruje `--evk-oc-time` i czas jest zerowy przez zmienną, a nie przez blok.

  Pomiar idzie teraz drogą, na której blok jest jedyną rzeczą zatrzymującą ruch:
  redukcja **włączana już po starcie**. To nie jest sztuczka na potrzeby testu —
  tak wygląda przestawienie ustawienia systemu przy otwartej stronie. Zmienna
  zostaje wtedy nieaktualna, a zapytanie medialne jest żywe.

## [1.96.0] — 2026-08-19

### Naprawione

- **Bezwładne przewijanie nagle stawało na telefonie.** Zgłoszone dokładnie:
  zaraz po wczytaniu strony mocne machnięcie palcem w górę → strona jedzie
  bezwładnie, zwalnia i **nagle staje**, choć powinna przewijać się dalej.
  Tylko iOS (Safari i Chrome mają tam ten sam silnik). Po wyłączeniu Animatora
  objaw znikał.

  Mechanizm, ustalony pomiarem, ma trzy ogniwa i żadne z nich nie jest oczywiste.

  **Pierwsze:** `ScrollTrigger.refresh()` **zapisuje pozycję przewijania** —
  skacze na samą górę dokumentu i wraca. Zmierzone szpiegiem podstawionym pod
  `window.scrollTo`: `scrollTo:0,0`, `scrollTo:0,0`, `scrollTo:0,1200`. Na
  desktopie tego nie widać, bo dzieje się w jednej klatce; na iOS zapis pozycji
  w trakcie bezwładności **kasuje ją natychmiast**.

  **Drugie:** marquee pilnuje wysokości dokumentu obserwatorem na `<html>`
  i po każdej zmianie wołało ten refresh. Obserwator jest potrzebny (bez niego
  marquee zepchnięte w dół mieli pętlę w tle), ale nie odróżniał „doładowała się
  treść" od „użytkownik właśnie przewija".

  **Trzecie:** Animator czekał z **całą** inicjalizacją na `document.fonts.ready`
  — dzielił tekst, tworzył maski i klony, zdejmował zasłonę. Na żywej stronie to
  sekunda po wczytaniu, czyli wprost w pierwsze machnięcie palcem. Wymiary
  strony drgały, obserwator wypalał, refresh ucinał bezwładność. Stąd zależność
  od Animatora: bez niego nic nie ruszało wymiarów strony po wczytaniu fontów.

  Odświeżanie wyzwalaczy przechodzi teraz przez **jeden wspólny helper**
  (`window.evkOdswiez`), drukowany obok `ScrollTrigger.config()` w
  `includes/89-gsap.php`. Helper scala serię wywołań w jedno przeliczenie
  i **odkłada je, dopóki przewijanie nie ustanie** — refresh nigdy nie ląduje
  w trakcie bezwładności. Na dotyku marquee pomija do tego zmiany **samej
  wysokości**: chowanie paska adresu zmienia ją dziesiątki razy w trakcie
  jednego przewijania, a układ ani drgnie.

  `ScrollTrigger.config({ ignoreMobileResize: true })` stało w tym pliku od
  dawna i chroniło przed tym samym — ale tylko **wbudowaną** ścieżkę GSAP-a.
  Sześć naszych własnych, jawnych wywołań `refresh()` tę ochronę omijało.
  Wszystkie idą teraz przez helper: marquee, tło przy scrollu, scroll reading,
  circular menu, stacking cards i horizontal scroll. Gdy helpera na stronie nie
  ma (inna kolejność wtyczek, wpięcie z ręki), wołający schodzi do zwykłego
  `ScrollTrigger.refresh()` — nic nie przestaje działać, traci tylko ochronę.

### Zmienione

- **Na webfonty czeka już tylko podział tekstu.** `needsFonts` włącza czekanie,
  gdy którykolwiek preset dzieli tekst na linie, słowa albo znaki — i dla samego
  **podziału** to jest słuszne, bo łamanie linii zależy od metryk fontu. Ale
  czekała na nie cała inicjalizacja: także animacje, które o fonty nie zahaczają,
  i zdjęcie zasłony chowającej treść.

  Start jest teraz natychmiastowy, a konfiguracje z podziałem odkłada `initOne()`
  — tą samą drogą, którą odkłada animacje poza zakresem szerokości: konfiguracja
  odpada, element nie dostaje znacznika gotowości i wraca, gdy fonty dojadą.
  Jedna mechanika na dwa powody odłożenia.

  Poza mniejszym ryzykiem ucięcia bezwładności daje to szybsze pojawienie się
  animacji wejściowych i krótszy czas, przez który treść stoi pod zasłoną.

  Przy okazji: element z **dwiema** animacjami, z których jedna czeka (na fonty
  albo na próg szerokości), nie dostaje znacznika gotowości — a bez znacznika
  silnik wracał do niego przy każdej przebudowie i budował od nowa także tę
  gotową. Druga oś czasu na tym samym elemencie to podwojony ruch przy pętlach
  i podwojony koszt przy wszystkim innym. Silnik pamięta teraz, które
  konfiguracje już zbudował. Usterka jest starsza — weszła razem z zakresem
  szerokości w 1.93.0 — ale dopiero czekanie na fonty czyniło ją częstą.

## [1.95.0] — 2026-08-18

### Naprawione

- **Silnik animacji skanował własne wytwory i zalewał konsolę ostrzeżeniami.**
  Zgłoszone z użycia: przy każdej zmianie szerokości okna sypało się
  „Brak animacji »line-mask« / »char-mask« / »swap-klon« w bibliotece", ze
  śladem prowadzącym przez `initAll` do nasłuchu progów szerokości.

  `initAll()` przechodzi po `[class*="evk-anim-"]`, a filtr pomijający kawałki
  po podziale tekstu znał tylko trzy nazwy: `line`, `word`, `char`. Nie znał
  **masek**, które dokłada opcja `mask` SplitTextu (`line-mask` i pozostałe),
  ani **klonów** podmiany treści z 1.92.0. Póki `initAll()` biegł raz na życie
  strony, nie było tego widać — zakres szerokości z 1.93.0 zaczął go wołać
  ponownie i wysypał lawinę.

  Drugi, cichszy skutek: taki węzeł nigdy nie dostawał znacznika gotowości,
  więc wracał przy KAŻDEJ kolejnej zmianie progu. Przy podziale na znaki to
  kilkadziesiąt węzłów na jeden nagłówek.

  Rozpoznanie idzie teraz po wzorcu, nie po liście nazw, a element będący
  wyłącznie wytworem silnika jest pomijany od razu — bez budowania
  konfiguracji i bez powrotów.

  Sama funkcja zakresu szerokości działała poprawnie; to ona tylko odsłoniła
  usterkę.

### Zmienione

- **Przebudowa po zmianie progu nie dzieje się już w klatce zdarzenia.**
  Zdarzenie `matchMedia` przychodzi w środku zmiany rozmiaru okna, a na
  telefonie zmiana rozmiaru bywa skutkiem chowania paska adresu **w trakcie
  przewijania** — budowanie animacji synchronicznie w tym miejscu wkłada pracę
  dokładnie tam, gdzie przeglądarka liczy klatkę scrolla. Przebudowa idzie
  przez jedną kolejkę, więc kilka progów przekroczonych naraz daje jedno
  przeliczenie zamiast kilku.

### Dodane

- **Raport kosztu Animatora.** `?evk-anim-debug=1` wypisuje w konsoli, ile
  elementów silnik obsłużył, ile kawałków, masek i klonów powstało, ile
  ScrollTriggerów wisi i ile z nich pracuje na każdej klatce przewijania —
  plus tabelę wierszy biblioteki. Bez parametru konsola milczy.

  Zgłoszenie „bezwładny scroll zatrzymuje się na iOS, po wyłączeniu Animatora
  problem znika" dało się dotąd rozstrzygnąć tylko wyłączaniem modułów po
  kolei. Te liczby pozwalają oprzeć następny krok na pomiarze.

## [1.94.0] — 2026-08-18

### Naprawione

- **Blokada przewijania nie blokowała, a płynne przewijanie nie było
  zatrzymywane.** Zgłoszone z użycia jako „strona się blokuje czasami przy
  przewijaniu, nie wiem czego to przyczyna". Rozpoznanie w kodzie i na żywej
  stronie dało trzy pewne usterki, wszystkie z tej samej rodziny:

  - **Circular Menu ustawiało atrybut `evk-cm-scroll-locked`, którego nie
    czytała żadna reguła CSS w całej wtyczce.** Blokada była atrapą — strona
    jechała pod otwartym panelem.
  - **Offcanvas blokował naprawdę, ale nie zatrzymywał Lenisa.** Dokument stał,
    a płynne przewijanie jechało dalej swoją wirtualną pozycję; po zamknięciu
    obie się nie zgadzały i przewijanie wyglądało na martwe, dopóki się nie
    zeszły. To jest mechanizm opisanego objawu.
  - **Lenis miał trzy nazwy globalne** — `evkLenis` z modułu, `lenisInstance`
    w Circular Menu, `lenis`/`__lenis` w tytule na okręgu. Ustawiana była
    jedna, więc dwa z trzech miejsc nigdy go nie znalazły.

  Wchodzi **jeden wspólny zamek** (`includes/96-scroll-lock.php`), do którego
  wołają wszyscy. Zatrzymuje Lenisa, gasi przewijanie dokumentu i kompensuje
  szerokość paska — a trzymających pamięta jako **zbiór imion**, nie licznik.
  Licznik nie odróżnia „drugi zamknął" od „ten sam zamknął dwa razy", a to
  właśnie ta druga sytuacja zostawia stronę zablokowaną na stałe.

  Zniknęło przy okazji wypatrywanie cudzych paneli po selektorach: zamek sam
  wie, kto jeszcze trzyma.

- **Płynne przewijanie dostawało dwa wykluczające się pokrętła tempa naraz.**
  Lenis przyjmuje `duration` ALBO `lerp`; wysyłaliśmy oba, więc jedno
  z ustawień w panelu po cichu nie działało i nie było jak zgadnąć które.
  Panel wybiera teraz tryb, a emitowany jest wyłącznie parametr tego trybu.

- **Kotwice przejmowały też gołe `#`.** Selektor `a[href^="#"]` łapał
  przełączniki akordeonów i zakładek, robił im `preventDefault()` i próbował
  przewinąć do selektora `#`. Skrypt przejmuje teraz kliknięcie tylko wtedy,
  gdy cel naprawdę istnieje w dokumencie — i sprawdza to przy kliknięciu, więc
  działa też dla treści doładowanej później.

### Dodane

- **Diagnostyka przewijania.** `?evk-scroll-debug=1` wypisuje w konsoli, kto
  trzyma blokadę i co się z nią dzieje, a `evkScroll.stan()` odpowiada na to
  samo pytanie w dowolnej chwili. Ostrzega też, gdy odblokowuje ktoś, kto nie
  blokował — to jest ten błąd, który zostawia stronę martwą, a dotąd przechodził
  bez śladu.

## [1.93.0] — 2026-08-18

### Dodane

- **Zakres szerokości okna dla animacji.** Każdy wiersz biblioteki dostaje pola
  „Graj od szerokości" i „Graj do szerokości" — ciężkie wejścia i podział tekstu
  da się wreszcie wyłączyć na telefonie, a hover tam, gdzie jest dotyk.

  Poza zakresem silnik **nie buduje** osi czasu: nie dzieli tekstu, nie zakłada
  ScrollTriggerów, nie klonuje niczego. To nie jest ukrycie animacji, tylko jej
  nieobecność — więc znika też jej koszt.

  Progi czytane są **z Bricks** (`\Bricks\Breakpoints`), a w wierszu zapisujemy
  KLUCZ, nie piksele. Przestawienie breakpointu w builderze przestawia więc od
  razu wszystkie wiersze, zamiast zostawiać liczbę, która kiedyś się zgadzała.
  Na front idą już piksele, więc silnik nie musi wiedzieć nic o Bricks. Bez
  Bricks wchodzą jego domyślne progi (478 / 767 / 991).

  Zakres działa też przez `data-evk-anim` na pojedynczym elemencie, tak samo jak
  reszta ustawień.

  Wejście w zakres **dobudowuje** animację bez przeładowania strony: obrót
  telefonu albo poszerzenie okna buduje to, co wcześniej odpadło. W drugą
  stronę nic nie jest rozbierane — rozebranie żywej osi czasu potrafiłoby
  zostawić element w stanie pośrednim.

### Naprawione

- **Kolejka startowa blokowała animacje dobudowywane później.** Wyzwalacz „load"
  miał zasadę „kolejka odpala się raz", żeby ponowny podział tekstu po zmianie
  szerokości okna nie odtwarzał wejścia drugi raz. Ta sama zasada blokowałaby
  jednak animację, która nigdy nie zagrała, bo dopiero teraz weszła w zakres.
  Rozstrzyga teraz znacznik gotowości elementu: już zbudowany nie gra ponownie,
  nigdy niezbudowany gra od razu.

## [1.92.0] — 2026-08-18

### Dodane

- **Podmiana treści na najechaniu (swap).** Na hoverze tekst wyjeżdża, a jego
  kopia wjeżdża na to samo miejsce — sześć presetów: podział na linie, słowa
  albo znaki, w dwóch kierunkach (z dołu, z góry).

  Kopia jest sednem efektu: bez niej zostaje samo zniknięcie napisu. Klasyczne
  rozwiązanie to dwie warstwy nad sobą, ale tu wystarczyła jedna. SplitText
  z opcją `mask` owija **każdy kawałek** własnym `overflow: hidden`, więc klon
  kawałka trafia do tej samej maski i ma z definicji identyczne pudełko —
  dopasowywanie geometrii dwóch warstw odpada.

  Klony dostają `aria-hidden`, żeby czytnik ekranu nie przeczytał napisu dwa
  razy. Ma to znaczenie tam, gdzie SplitText sam kawałków nie chowa — czyli
  przy elementach z kilkorgiem dzieci (nagłówek z odnośnikiem w środku).

  Stagger, czas i krzywa idą z wiersza biblioteki jak wszędzie; `strength`
  steruje skosem kawałków w ruchu.

  Presety należą do rodziny stanowej, więc dziedziczą po 1.91.0 trzy rzeczy:
  grupę „Stany (hover, klik)" w panelu, wyłączenie ze sprzątania transformacji
  i to, że przy redukcji ruchu nic nie zostaje nałożone na stałe. Przy
  ograniczonym ruchu klony nie powstają w ogóle.

### Naprawione

- **Ponowny podział tekstu zwielokrotniał nasłuchy.** `autoSplit` przebudowuje
  kawałki po każdej zmianie szerokości okna i woła `onSplit` ponownie —
  a każde wywołanie dokładało kolejny komplet nasłuchów na tym samym elemencie.
  Po dwóch zmianach szerokości jedno najechanie uruchamiało dwie osie czasu,
  z których tylko ostatnia dotyczyła istniejących kawałków. Podmiana treści
  trzyma teraz własny `AbortController` i przerywa poprzedni przed podpięciem
  nowego.

## [1.91.0] — 2026-08-18

### Naprawione

- **Animacja na hover gasiła element.** Zgłoszone z użycia: „chciałbym użyć
  niektórych predefiniowanych animacji, ale większość powoduje, że element jest
  niewidoczny przed hover".

  Ścieżka interaktywna budowała oś przez `fromTo`, a to renderuje stan
  początkowy **natychmiast**. Przy presecie wejściowym `from` jest stanem
  ukrycia (`opacity: 0`), więc element parkował niewidoczny aż do pierwszego
  najechania. Wyzwalacz wybiera się per użycie, więc parę „preset wejściowy
  + hover" dało się złożyć na dowolnym elemencie — sama poprawka w panelu by
  nie wystarczyła.

  Hover i klik budują teraz oś **samym `to`**: stanem spoczynku jest to, co
  wyrenderował CSS. Kryterium jest przy tym RODZINA presetu, nie wyzwalacz —
  preset stanowy ma we `from` stan spoczynku (`underline-sweep` trzyma tam
  podkład z zerową szerokością podkreślenia) i ten `from` zostaje. Efekty
  tekstowe też zachowują swój stan początkowy: maszyna do pisania zaczyna od
  pustego pola.

- **Preset stanowy pod wejściem w kadr cofał sam siebie.** `clearProps` po
  animacji obowiązywał wszystko, co nie było wyjściem — więc `hover-scale`
  powiększał element i natychmiast zdejmował powiększenie. Rodzina stanowa jest
  teraz z tego sprzątania wyłączona, tak samo jak wyjścia.

- **Przy redukcji ruchu stan najechania mógł zostać na stałe.** Bramka
  wykluczała stany po WYZWALACZU, więc preset stanowy podpięty pod wejście
  w kadr przez nią przechodził i element zostawał trwale przygaszony albo
  powiększony. Warunek idzie teraz po znaczniku rodziny.

### Dodane

- **Rodzina presetów STANOWYCH** — sześć nowych (`hover-scale`, `hover-sink`,
  `hover-shift`, `hover-rotate`, `hover-dim`, `hover-blur-soft`) plus cztery,
  które już były hoverowe w zamyśle i teraz są tak oznaczone (`lift`, `glow`,
  `underline-sweep`, `border-draw`). Czas 0,3 s zamiast 0,8: stan reaguje na
  kursor, a nie wjeżdża w kadr.

  Rozpoznawane po znaczniku `stan` i helperze `evk_anim_preset_is_state()` —
  tym samym wzorcem, którym biblioteka odróżnia wyjścia, a nie po nazwie.

- **Trzecia grupa w liście presetów** — „Stany (hover, klik)" obok wejść
  i wyjść, żeby przestać sięgać po „Fade z dołu" do hoveru.

## [1.90.0] — 2026-08-18

### Naprawione

- **Kolor liter przy scrollu stał w miejscu — kontrolka nie miała żadnego
  wpływu.** Zgłoszone jako „nieważne, jaki kolor ustawię w builderze, tekst
  zawsze wygląda tak samo", a po włączeniu szerokiego zasięgu — „litery są
  czarne". Tło przenikało przy tym poprawnie.

  Silnik rozwija kolor globalny Bricks (`var(--bricks-color-…)`) do
  konkretnego `rgb()`, wpisując go na warstwę tła i **odczytując w tej samej
  chwili**. Gdy na warstwie wisi `transition: color`, `getComputedStyle`
  zwraca wartość **sprzed** zmiany — czyli kolor odziedziczony po dokumencie.
  Każde wywołanie oddawało wtedy tę samą wartość: wszystkie sekcje dostawały
  jeden kolor liter i przenikanie nie miało czego animować.

  Zbieg nie jest wydumany. Wystarczy dopisać `div` do „selektorów globalnych"
  w module trybu ciemnego — a warstwa jest divem. Zmierzone na żywej stronie:
  trzy sekcje z kolorami `#81D4FA`, `#f5f5f5`, `#81D4FA`, a zmienna przez całe
  przewijanie stała na `rgb(26, 26, 26)`.

  Warstwa ma teraz `transition: none` na stałe. Steruje nią wyłącznie GSAP,
  więc żadne przejście CSS nie ma na niej nic do roboty — ani przy mierzeniu
  koloru, ani przy malowaniu tła, gdzie zjadałoby płynność przewijania.

  To ta sama pułapka, przed którą od 1.29.1 broni klasa `evk-bg-measure` przy
  odczycie kolorów sekcji. Warstwa nie miała odpowiednika.

## [1.89.0] — 2026-08-18

### Naprawione

- **Kolor liter przy scrollu nie zmieniał się płynnie.** Zgłoszone trzeci raz —
  i tym razem winowajca był po drugiej stronie niż szukaliśmy.

  Silnik był sprawny: zmienna `--evk-bg-text` interpoluje wzorowo, a warstwa
  tła jedzie z nią w jednym tweenie. Płynność zjadało **cudze przejście CSS na
  `color`**. Kolor piszemy do zmiennej co klatkę, a każdy zapis restartuje
  takie przejście od bieżącej wartości — więc litery nie doganiają celu przez
  całe przewijanie i dochodzą do niego dopiero sekundę po jego zatrzymaniu.

  Zbieg nie był teoretyczny, tylko domyślny: to **moduł trybu ciemnego tej
  wtyczki** daje `.brxe-text` i `.brxe-heading` sekundowe przejście na `color`,
  a `section` — czterdziestosetne. Zmierzone: zmienna na `rgb(0, 200, 0)`,
  sekcja na `rgb(0, 200, 0)`, a tekst wewnątrz niej na `rgb(117, 225, 117)`.

  Na czas przewijania koloru silnik znakuje `<html>` klasą `evk-bg-scrub`,
  a arkusz gasi wtedy przejścia w sekcjach biorących udział w efekcie —
  **razem z ich potomkami**, bo przy wąskim zasięgu kolor zmienia się właśnie
  potomkowi, a wartość odziedziczona uruchamia jego przejście tak samo jak
  ustawiona wprost. Znacznik schodzi 120 ms po ostatnim zapisie, więc poza
  samym przewijaniem przejścia (hover i reszta) działają bez zmian.

### Zmienione

- **Domyślny „Zasięg koloru liter" to teraz „wszystkie teksty w sekcji".**
  Przez jedną wersję domyślne było dziedziczenie — z rozumowania, że szerszy
  zasięg zabiera kontrolę nad typografią i musi być świadomą decyzją. Praktyka
  pokazała odwrotnie: moduł włącza się osobno i osobno zaznacza się każdą
  sekcję, więc decyzja „litery mają iść za tłem" zapada dwa razy, zanim ta
  opcja w ogóle zaczyna działać — a domyślna kazała podjąć ją trzeci raz
  w miejscu, którego nikt nie szukał.

  Strony, które po 1.88.0 świadomie wybrały dziedziczenie, mają je zapisane
  w ustawieniach i zostają przy swoim. Zmiana dotyczy tych, które tej opcji
  nigdy nie dotknęły.

## [1.88.0] — 2026-08-13

### Naprawione

- **„Zmiana koloru tekstu nie działa" w Tle przy scrollu.** Nie była zepsuta —
  działała dokładnie tak, jak opisano, i to był problem.

  Kolor schodził na sekcję i dalej **dziedziczeniem**, a dziedziczenie omija
  każdy element, który ma własny kolor. Bricks nadaje własny kolor niemal
  każdemu tekstowi, regułą po identyfikatorze — więc zasięg opisany jako
  ostrożny znaczył w praktyce „prawie nigdzie". Klasa `evk-bg-text` na
  pojedynczy element była jedynym wyjściem i nie skalowała się na sekcję.

  Dochodzi kontrolka **Zasięg koloru liter**: „tylko dziedziczone"
  (dotychczasowe) albo „wszystkie teksty w sekcji". Druga opcja przemalowuje
  też elementy z własnym kolorem — `!important` jest tu jedyną drogą, bo reguła
  po identyfikatorze ma wyższą wagę niż cokolwiek, co da się napisać klasami.
  Domyślna zostaje przy dziedziczeniu, bo szerszy zasięg zabiera kontrolę nad
  typografią i musi być decyzją.

  Szeroki zasięg omija obrazy, pola formularzy i wszystko z klasą
  `evk-bg-keep` — `color` znaczy tam co innego niż „kolor liter", a ikona
  rysowana `currentColor` potrafiłaby zniknąć na tle własnego przycisku.

### Zmienione

- **Maska Wave Background zanika łagodniej.** Dwa przystanki gradientu dawały
  alfę rosnącą **liniowo**, a wtedy przyrost jest najszybszy dokładnie tam,
  gdzie zanikanie się zaczyna i kończy — w obu tych miejscach widać szew.
  Zgłoszone przy masce górnej.

  Rampa idzie teraz po krzywej `t²(3−2t)`, która startuje i kończy ze zboczem
  zerowym, więc wchodzi w sąsiedztwo bez załamania. W połowie drogi jest
  identyczna z prostą, więc **długość zanikania się nie zmienia** — inna jest
  wyłącznie jego charakterystyka.

  Ta sama rampa objęła maskę dolną. Wygładzenie samej górnej zostawiłoby górę
  miękką, a dół twardy — a to wyglądałoby jak usterka.

## [1.87.0] — 2026-08-13

### Naprawione

- **„Otwórz w builderze" zostawiało menu otwarte NA STAŁE na froncie.**

  Reguła arkusza, która odsłaniała panel, nie miała o builderze **ani słowa** —
  wystarczało samo ustawienie elementu. Na froncie ratował ją tylko przypadek:
  portal wynosi panel do `<body>`, więc przestaje być potomkiem korzenia
  i selektor przestaje pasować. Przy **wyłączonym portalu** panel zostaje na
  miejscu, reguła trafia, a `!important` przebija to, co GSAP wpisuje w styl —
  więc skrypt nie miał nawet czym tego zamknąć. Przy włączonym portalu
  zostawało okno od sparsowania strony do startu skryptu, czyli mignięcie
  otwartym menu przy każdym wejściu.

  Znów jeden stan i dwóch właścicieli: skrypt wiedział o builderze
  (`isBuilder && openBuilder`), arkusz decydował sam. Teraz stan ma jednego
  właściciela — skrypt zaznacza panel klasą, a arkusz tylko maluje to, co
  zaznaczone. Na froncie ta ścieżka nie istnieje w ogóle.

- **Kliknięcie przełącznika w builderze potrafiło zawiesić stronę.**
  Podnoszenie przełącznika (z 1.85.0) przenosi węzeł w drzewie, a kanwa
  buildera jest cudzym drzewem: Bricks pilnuje jej własnym obserwatorem
  i przerysowuje element, gdy DOM się zmieni. Wychodziła z tego para, w której
  każda strona reaguje na ruch drugiej.

  Podnoszenie jest teraz wyłączone w builderze — ta sama zasada, którą stosuje
  już portal panelu. Problem, który ta opcja rozwiązuje (kontekst układania
  nagłówka), na kanwie i tak nie występuje.

## [1.86.0] — 2026-08-13

### Naprawione (obie regresje z 1.85.0)

- **Przełącznik nie animował się przy OTWIERANIU — tylko przy zamykaniu.**
  Bez przejścia kolorów i bez ruchu kresek: przeskok wprost do wyglądu
  otwartego.

  Przeniesienie węzła w drzewie **kasuje stan przejść** — element, którego nie
  było w dokumencie przy poprzednim przeliczeniu stylu, nie ma od czego
  animować. Klasa stanu dochodziła zaraz po przeniesieniu, więc przejście nie
  miało punktu wyjścia. Przy zamykaniu wszystko grało, bo tam klasa schodzi,
  gdy węzeł od dawna siedzi w `<body>` — stąd asymetria ze zgłoszenia.

  Po przeniesieniu wymuszamy teraz przeliczenie stylu, więc przełącznik dostaje
  stan wyjściowy. Zmierzone: bez tego kolor skacze wprost do docelowego, z tym
  w połowie czasu jest w połowie drogi.

- **Zawartość nagłówka przesuwała się przy otwieraniu i zamykaniu.**
  Przekładka wstawiana w miejsce przełącznika była jego kopią z **usuniętym
  identyfikatorem** — a Bricks stylizuje elementy właśnie po identyfikatorze,
  więc kopia gubiła szerokość, wysokość i wypełnienie i zapadała się.
  Zmierzone przesunięcie sąsiada: **43 px**, tak samo w nagłówku elastycznym
  jak liniowym.

  Przekładka dostaje teraz pudełko wpisane wprost z pomiaru oryginału razem
  z wypełnieniem i obramowaniem — dzięki temu nie zależy od tego, które reguły
  ją ominą. Wypełnienie musiało być KOPIOWANE, a nie wyzerowane: przy zerowym
  tekst siadał wyżej i linia bazowa całego wiersza wypadała o piksel inaczej.

  Identyfikatora świadomie nie zostawiamy na kopii — dwa te same w dokumencie
  psują `getElementById` i cudze skrypty.

## [1.85.0] — 2026-08-13

### Dodane

- **Circular Menu: „Przełącznik nad panelem".** Zgłoszone z użycia: burger
  siedzi w nagłówku, nagłówek jest w `<body>` i nie jest ani `fixed`, ani
  `absolute`, a panel go przykrywa. Podniesienie samego burgera z-indeksem nie
  pomaga — pomaga dopiero wyciągnięcie na wierzch CAŁEGO nagłówka, czyli razem
  z jego tłem.

  To nie jest kwestia za małej liczby. Nagłówek tworzy **kontekst układania**
  (wystarczy `position: relative` z własnym `z-index`, `transform`, `filter`,
  `will-change` albo `opacity` poniżej jedynki), a wtedy `z-index` dziecka
  rywalizuje wyłącznie z rodzeństwem: z panelem rywalizuje cały nagłówek jako
  jedna warstwa. Zmierzone `elementFromPoint` w środku burgera przy otwartym
  menu — przy `z-index: 99999` na burgerze na wierzchu jest **panel**, a po
  przeniesieniu węzła do `<body>` — **burger**.

  Opcja na czas otwarcia przenosi sam przełącznik na koniec strony i ustawia go
  dokładnie tam, gdzie stał, z z-indeksem odczytanym **z panelu** (plus jeden),
  a przy zamknięciu odkłada na miejsce. W nagłówku zostaje przekładka, więc nic
  się nie przebudowuje. Domyślnie wyłączona — przenosi węzeł w drzewie, więc
  musi być decyzją.

  Dwie rzeczy opisane przy kontrolce: reguły pisane przez potomka nagłówka
  (`.header .burger`) przestają na ten czas pasować — style Bricksa (po
  identyfikatorze) i wtyczki (po klasach) to przeżywają; a bez blokady
  przewijania przełącznik zostaje w miejscu, gdy strona pod panelem się
  przewija.

  Offcanvas świadomie poza tą partią.

### Naprawione

- **Nasłuch „klik poza panelem" trzymał własną kopię listy przełączników.**
  Wyszło przy podnoszeniu: wbudowany przełącznik szukał się przez
  `root.querySelectorAll`, a podniesiony siedzi w `<body>` — więc wypadał
  z listy dokładnie wtedy, gdy menu jest otwarte, i kliknięcie w niego było
  brane za kliknięcie poza panelem. Menu zamykało się w tej samej klatce,
  w której je otwarto. Lista idzie teraz z jednego miejsca dla wszystkich
  trzech zastosowań.

## [1.84.0] — 2026-08-13

### Naprawione

- **Kreski burgera znikały, gdy kolor po otwarciu był nie do użycia.** Zgłoszone
  jako „krzyżyk zmienia kolor na inny niż ustawiony i inny niż kolor
  zamkniętego" — i ten trzeci kolor nie był żadnym kolorem. Był
  PRZEZROCZYSTOŚCIĄ, przez którą widać panel menu.

  Gdy podstawiona wartość zmiennej okaże się nie do użycia — najczęściej kolor
  z palety Bricksa, którego zmienna nie dociera do tego elementu — deklaracja
  `background: var(…)` staje się nieprawidłowa **na etapie wartości obliczonej**.
  Właściwość wraca wtedy do `unset`, a `background-color` nie jest dziedziczone,
  więc `unset` znaczy `transparent`. Zmierzone: `rgba(0, 0, 0, 0)` zamiast
  koloru.

  Napis przy DOKŁADNIE tej samej usterce zachowywał się poprawnie, bo `color`
  JEST dziedziczone i `unset` cofa go do koloru rodzica — czyli do czegoś
  widocznego. To jest cała asymetria z tego zgłoszenia: **kreski były jedyną
  częścią przycisku malowaną właściwością niedziedziczoną, więc jedyną, która
  potrafiła zniknąć zamiast się zdegradować.** Ikona i napis tej wady nigdy
  nie miały.

  Kolor kresek jedzie teraz przez `color` + `background: currentColor`, a kolor
  spoczynkowy stoi dodatkowo na pudełku rysunku jako siatka bezpieczeństwa —
  dzięki temu przy nieużytecznej wartości kreski wracają dokładnie do koloru
  sprzed otwarcia, zamiast do koloru dokumentu albo do przezroczystości.
  Przy poprawnych wartościach nic się nie zmienia.

  `var(--x, zapas)` by tego nie załatwiło: zapas wchodzi, gdy zmienna jest
  NIEZDEFINIOWANA, a nie gdy jest zdefiniowana wartością nie do użycia.

  **Uwaga: to jest poprawka o ODPORNOŚCI, nie o Twoim kolorze.** Jeśli wartość
  jest nie do użycia, kreski będą po niej widoczne, ale w kolorze sprzed
  otwarcia. Przyczyną jest sama wartość — sprawdź w DevTools na przycisku
  (Computed, filtr `--evk-burger`), czy przy `--evk-burger-color-open` nie stoi
  `var(--bricks-color-…)` zamiast koloru. Wpisanie koloru wprost rozwiązuje to
  od ręki.

### Zmienione (komentarz w kodzie)

- **Sprostowanie w arkuszu burgera.** Komentarz przy liście `transition`
  twierdził, że kolor musi stać na trzeciej pozycji. Mutacja pokazała, że to
  nieprawda — zamiana `opacity` z `color` nie zmienia niczego, bo oba mają
  zerowe opóźnienie. Wiążące jest wyłącznie to, że `transform` stoi PIERWSZY
  (tam trafia opóźnienie dwutaktów) i że lista ma pięć wpisów. Komentarz mówi
  teraz to, co jest naprawdę pilnowane.

## [1.83.0] — 2026-08-13

### Zmienione

- **Pola kolorów burgera mówią teraz, co malują.** Zgłoszone z użycia: „tekst
  zmienia kolor na zadany, a burger nie".

  Mechanizm był sprawny — kreski nie zmieniały koloru dlatego, że ich pole
  zostało puste, a puste znaczy „ten sam co przed". Ale wdepnąć w to było łatwo
  i to jest wina zmiany z 1.81.0: do wtedy pole „po otwarciu" było JEDNO
  i malowało wszystko, co przycisk pokazywał. Dołożenie węższego pola dla napisu
  zrobiło z tamtego połowę pary, zostawiając mu ogólną nazwę — powstało
  zestawienie, w którym pole węższe brzmi konkretniej niż ogólne, więc czytało
  się je jako to właściwe.

  | Dawniej | Teraz |
  |---|---|
  | Kolor kresek | **Kolor kresek i ikony** |
  | Kolor po otwarciu | **Kolor kresek i ikony po otwarciu** |
  | Kolor napisu po otwarciu | *(bez zmian)* |

  „Kolor kresek" i tak malował także własną ikonę — nazwa była nieprawdziwa już
  wcześniej. Zasada „pusty = ten sam co przed" zeszła z nagłówka sekcji do
  opisów samych pól, czyli tam, gdzie się na nią patrzy.

  **Zachowanie bez zmian.** Kusiło, żeby jedno pole malowało cały przycisk, ale
  kontrolka koloru ma wartość domyślną `#000000`, którą Bricks wypisuje do CSS-a
  nawet nietkniętą — napis przemalowałby się wtedy na czarno KAŻDEMU, kto ma
  jasny tekst i nigdy nie dotknął kolorów.

### Naprawione (testy)

- **Niezależność obu par kolorów nie była pilnowana ANI JEDNYM sprawdzeniem.**
  Każde mierzyło swój kawałek osobno, więc pole malujące cudzy kawałek
  przeszłoby bez śladu. Nowa sekcja mierzy kreskę i napis w tym samym pomiarze,
  w obie strony, plus sprawdza w PHP, że oba pola celują w różne zmienne — tej
  pomyłki z kopiowania pomiar w przeglądarce sam by nie złapał, bo przy jednej
  ustawionej wartości wszystko wyglądałoby poprawnie.

## [1.82.0] — 2026-08-13

### Dodane

- **Kolor pasków przeglądarki** — nowa podzakładka we Frontend. Rozwiązuje
  zgłoszenie „menu zmienia kolor górnego i dolnego paska w Safari po otwarciu".

  Safari koloruje swoje paski pod stronę. Gdy strona **nie mówi mu**, jakiego
  koloru mają być, przeglądarka bierze go z tego, co widzi — więc cokolwiek
  zamaluje kadr, przemalowuje przy okazji paski. Circular Menu i Offcanvas Menu
  kładą na cały kadr nieprzezroczysty panel, stąd zmiana po otwarciu. To nie
  jest usterka menu, tylko domyślne zachowanie przeglądarki wobec strony, która
  o kolorze nic nie powiedziała.

  Podany kolor obowiązuje niezależnie od tego, co jest namalowane, więc jest to
  naprawa PRZYCZYNY, a nie objawu: działa też poza menu — na pełnoekranowej
  galerii, sekcji z ciemnym tłem, filmie na cały ekran — i na podstronach bez
  żadnego menu. **Ani Circular, ani Offcanvas nie wymagały zmian.**

  Osobny kolor dla trybu ciemnego; pusty znaczy „ten sam zawsze". Moduł jest
  domyślnie WYŁĄCZONY — dokłada znacznik do każdej strony, więc włączenie musi
  być decyzją, a nie skutkiem aktualizacji.

  Czego to nie przeskoczy: przeglądarka bierze PIERWSZY taki znacznik
  w dokumencie, więc kolor wpisany na sztywno w szablon motywu wygra z tym
  ustawieniem. Napisane wprost w panelu razem ze wskazówką, gdzie szukać.

### Naprawione (testy)

- **Atrapa `sanitize_hex_color()` była ŁAGODNIEJSZA od funkcji, którą udaje.**
  Oddawała `''` dla każdej nieprawidłowej wartości, podczas gdy oryginał zwraca
  `null` dla niepustej, ale nieprawidłowej — a `''` tylko dla pustej.

  Kod porównujący wynik z `''` przechodził przez to w teście, a na żywej stronie
  dostawał `null` i wpuszczał go dalej. Złapane przy pisaniu tego modułu:
  mutacja, która miała paść, przechodziła na zielono. Atrapa łagodniejsza od
  oryginału nie jest uproszczeniem, tylko ukrytą różnicą.

## [1.81.0] — 2026-08-13

### Naprawione

- **Odstępu napisu od ikony nie dało się ZMNIEJSZYĆ.** Wpisanie wartości
  ujemnej nie robiło nic — nawet nie wracało do zera.

  Odstęp jechał właściwością `gap`, a ta nie przyjmuje wartości ujemnych: cała
  deklaracja jest wtedy nieprawidłowa i wypada razem z ustawieniem. Zmierzone
  przy `-12px`: policzony `column-gap` wychodził `normal`, a przycisk miał tę
  samą szerokość co przy zerze — 83 px w obu przypadkach.

  Minus jest tu potrzebny częściej, niż się wydaje. Pudełko rysunku jest
  KWADRATEM o boku pola klikalnego (od 1.79.0, bo przy tekście procentowa
  szerokość kresek nie ma się do czego odnieść), więc kreski węższe niż pełna
  szerokość zostawiają w nim pustkę, której nie widać. Napis stoi wtedy od
  kresek dalej, niż mówi ustawienie: zmierzone **9 px przy kreskach 60%
  i odstępie zero**.

  Odstęp jedzie teraz marginesem, więc pustkę da się odjąć. Kierunek idzie za
  pozycją napisu — cztery reguły, a nie jedna logiczna, bo „przed" i „nad"
  odwracają układ i przerwa wypada po przeciwnej stronie napisu, a właściwości
  logiczne idą za kierunkiem PISMA, nie układu.

### Dodane

- **Kolor napisu po otwarciu.** Napis był jedyną widoczną częścią przycisku bez
  koloru stanu otwartego: kreski mają swój, ikony mają swój, a tekst dziedziczył
  kolor przycisku i zostawał z nim do końca.

  Pusty znaczy „ten sam co przed otwarciem", więc nieustawiony nie zmienia
  niczego na istniejących stronach. Kolor stanu zamkniętego zostaje przy
  natywnej typografii Bricksa — napis dziedziczy go z przycisku i to już
  działa, więc druga kontrolka tylko dublowałaby jedno ustawienie w dwóch
  miejscach.

  Napisu nie podpinamy pod istniejące „Kolor kresek" — przemalowałoby to tekst
  każdemu, kto ustawił kolor kresek.

## [1.80.0] — 2026-08-13

### Naprawione

- **Własna ikona miała „tło, którego nie dało się usunąć".** To nie było tło —
  to była Twoja ikona zalana na płasko, przez jedną linijkę z 1.79.0:
  `.evk-burger__icon svg { fill: currentColor; }`.

  `fill="none"` w pliku ikony to **atrybut prezentacyjny**, a te przegrywają
  z KAŻDĄ regułą arkusza. Nasza reguła kasowała więc `fill="none"` z korzenia
  `<svg>`, a wypełnienie dziedziczyło się w dół na wszystkie kształty. Ikona
  rysowana obrysem — Lucide, Feather, Tabler, Heroicons w wariancie outline —
  zamieniała się w plamę.

  Zmierzone na ikonie „X w kółku": `fill` kółka `rgb(0, 0, 0)` zamiast `none`,
  czyli pełne koło pod krzyżykiem.

  **Dlaczego było widać głównie ikonę otwartą.** Zamknięta to zwykle hamburger
  z trzech `<line>` — odcinek nie ma pola, więc wypełnienie nic z nim nie
  robiło. Otwarta to X w kółku albo w kwadracie, czyli kształt zamknięty —
  i ten zalewał się w całości.

  Wypełniamy teraz tylko wtedy, gdy plik sam nie powiedział „bez wypełnienia".
  Warunek jest wąski celowo: ikony z kolorem wpisanym na sztywno mają dalej
  słuchać kontrolek koloru, tak jak w 1.79.0. Ikony obrysowe piszą
  `stroke="currentColor"`, więc „Kolor kresek" i „Kolor po otwarciu" działają
  na nich dalej, mimo że przestaliśmy je wypełniać.

  Czego to nie naprawi: pliku SVG, który niesie własny prostokąt tła w środku.
  Tam tło jest treścią pliku — napisane wprost w opisie kontrolki, żeby nie
  było zgadywania.

### Dodane

- **Wewnętrzny odstęp napisu** — do wyrównania tekstu z ikoną, gdy krój
  odstawia go od jej linii. Cztery strony, więc jedno pole załatwia i napis
  obok ikony, i napis nad nią albo pod nią.

  Padding samego przycisku Bricks daje natywnie w zakładce Styl, ale odsuwa
  napis RAZEM z rysunkiem — nie da się nim ustawić jednego względem drugiego
  i to jest jedyny powód, dla którego ta kontrolka istnieje.

  Jedna rzecz do wiedzenia przy strojeniu, opisana też przy kontrolce: przycisk
  ŚRODKUJE pudełko napisu, więc nierówna góra i dół przesuwają go o POŁOWĘ
  różnicy — cztery piksele u góry dają dwa piksele w dół.

## [1.79.0] — 2026-08-13

### Dodane

- **Burger pokazuje to, co mu każesz — nie tylko kreski.** Dochodzi jedna oś:
  **co pokazuje przycisk**. Kreski (szesnaście dotychczasowych stylów), własna
  ikona zamknięta i otwarta, albo nic.

  To nie są trzy funkcje doklejone obok siebie. Wartością tego elementu nigdy
  nie były kreski, tylko INSTALACJA STANU: spięcie z menu, tryb celu,
  `aria-expanded`, redukcja ruchu, powrót do stanu zamkniętego przy Esc.
  Wszystko to jest niezależne od tego, CO przycisk rysuje — więc kreski
  przestały być wbudowanym założeniem i stały się jedną z możliwości.

- **Tekst przy ikonie — osobna oś, dochodzi do każdego źródła.** Dwa pola:
  napis przy zamkniętym (np. `MENU`) i przy otwartym (`ZAMKNIJ`). Puste drugie
  znaczy „ten sam co pierwsze". Do tego pozycja (przed / za / nad / pod ikoną)
  i odstęp.

  „Nic" plus tekst daje wariant czysto tekstowy — **bez ani jednej nowej gałęzi
  kodu**, bo to po prostu źródło bez rysunku.

  Oba napisy leżą NA SOBIE, więc przycisk ma szerokość dłuższego z nich
  i przełączenie nie przesuwa niczego, co stoi obok.

- **Cztery style asymetryczne, razem dwadzieścia.** „Zygzak" (krótka przy
  lewej, pełna po środku, krótka przy prawej), „Schodki" (pełna, średnia,
  krótka — wszystkie od lewej), „Nierówne z prawej", „Rozstrzelone" (obie
  krótkie, górna przy lewej, dolna przy prawej).

  Dokłada się tu drugi wymiar asymetrii: nie tylko JAK DŁUGA jest kreska, ale
  DO KTÓREJ KRAWĘDZI przylega. Bez tego byłyby nieodróżnialne od „nierównych"
  z 1.78.0. Średnia w „schodkach" wylicza się z krótkiej, żeby jedno pole
  sterowało całą proporcją.

  `burger.js` **znów bez zmian** — i napisy, i ikony reagują na tę samą klasę
  `brx-open`, którą element już czytał.

### Naprawione (dostępność)

- **Opis dla czytnika ekranu nie wychodzi już razem z widocznym tekstem.**
  `aria-label` PRZYKRYWA treść przycisku, więc przy napisie „MENU" i opisie
  „Menu" czytnik ogłaszał opis, a nie to, co widać. Nazwa inna od widocznej
  etykiety psuje sterowanie głosem (WCAG 2.5.3): użytkownik mówi „kliknij
  MENU", a przeglądarka szuka czegoś innego. Przy wpisanym tekście opis nie
  wychodzi wcale — nazwą staje się sam napis.

- **Ukryty napis wypada z drzewa dostępności.** Oba napisy są w znaczniku od
  początku, więc przezroczysty niewidoczny byłby dalej ogłaszany i czytnik
  czytałby „MENU ZAMKNIJ". Stąd `visibility`, a nie samo `opacity`.

### Zmienione

- **Bok pola klikalnego zszedł z przycisku na pudełko z rysunkiem.** Wychodziło
  dotąd na to samo, bo przycisk wyśrodkowywał węższe pudełko. Przestaje, gdy
  obok stanie tekst: przycisk mierzy się wtedy treścią, a procentowa szerokość
  kresek nie ma się już do czego odnieść i pudełko zapadłoby się do zera.
  Przycisk BEZ tekstu wygląda dokładnie jak dotąd.

## [1.78.0] — 2026-08-12

### Naprawione

- **Marquee mieliło w tle, gdy strona urosła po jego uruchomieniu.** To była
  prawdziwa usterka i to ona odpowiada za zgłoszenie „marquee nie pauzuje się,
  jeśli jest dalej w treści".

  ScrollTrigger liczy położenia raz i sam odświeża się tylko przy zmianie
  rozmiaru okna oraz przy `load`. Zmiana wysokości dokumentu PO tym czasie
  przechodziła bez echa — a zdarza się stale: obrazki bez podanych wymiarów,
  webfonty zmieniające łamanie, treść doładowana AJAX-em, akordeon rozwinięty
  wyżej na stronie. Marquee mieszczące się w kadrze w chwili startu zostawało
  zepchnięte daleko w dół, a pętla kręciła się dalej, bo wyzwalacz nadal widział
  je na starym miejscu.

  Zmierzone przed poprawką: treść przejeżdżała 100 px w pół sekundy przy
  marquee stojącym trzy tysiące pikseli pod ekranem.

  Jeden obserwator wysokości strony na cały dokument przelicza teraz wyzwalacze
  po każdej zmianie, z odbiciem 150 ms — bo przy doładowywaniu treści wysokość
  zmienia się kilkanaście razy pod rząd.

### Zmienione

- **Zapas pauzy marquee przyjmuje wartości UJEMNE.** Był zaciśnięty do zera
  w dwóch miejscach naraz (PHP i JS), więc dało się nim tylko poszerzać strefę
  grania. A domyślne 200 px URUCHAMIA marquee, zanim wjedzie w kadr — zjeżdżając
  do niego stroną widzi się je już rozpędzone i wygląda to dokładnie jak brak
  pauzy. To druga, znacznie częstsza przyczyna tego samego wrażenia.

  Ujemny zapas robi rzecz odwrotną: przy `-150` marquee rusza dopiero, gdy widać
  już sto pięćdziesiąt pikseli. Do sprawdzenia na żywej stronie, czy pauza
  w ogóle działa — i do normalnego użycia, gdy pętla ma ruszać dopiero
  w wyraźnym kadrze.

### Dodane

- **Burger: pięć nowych stylów, razem szesnaście.**

  **Nierówne** (`Nierówne — 2 kreski`, `Nierówne — 3 kreski`) to nowa cecha,
  nie tylko nowy wygląd: pierwsze style, w których kreski mają RÓŻNE długości
  już w stanie zamkniętym. Proporcję ustawia nowa kontrolka „Długość krótszej
  kreski" (domyślnie 60%).

  **Trzy nowe drogi do krzyżyka**: „Po kolei" (skrajne krzyżują się jedna po
  drugiej), „Ściągnięcie" (kreski najpierw wciągają się do połowy, potem się
  krzyżują — wychodzi mniejszy krzyżyk), „Minięcie" (górna nadrabia pełny obrót
  i mija dolną w drodze).

  `burger.js` znów bez zmian — style to wyłącznie arkusz.

## [1.77.0] — 2026-08-12

### Dodane

- **Burger: dziesięć nowych stylów, w dwóch rodzinach.** Razem jedenaście.

  **Trzykreskowe:** krzyżyk *(był)*, ściśnięcie (środkowa zwęża się do zera
  zamiast gasnąć), złożenie (najpierw zjazd do środka, dopiero potem obrót),
  strzałka (skrajne skracają się o połowę, środkowa zostaje trzonem), plus,
  zsunięcie (trzy kreski w jedną).

  **Dwukreskowe:** krzyżyk, minus, daszek (obie skracają się i schodzą ostrzem
  w prawo), plus, zjazd (górna wyjeżdża bokiem, dolna zostaje ukośnie).

  Ten sam „Odstęp między kreskami" daje w OBU rodzinach tę samą przerwę —
  dwukreskowe rozsuwają się o połowę tego, co skrajne kreski trzykreskowych.
  Inaczej jedno ustawienie znaczyłoby dwie różne rzeczy zależnie od stylu.

- **Kontrolka „Obrót po otwarciu"** — obraca cały rysunek niezależnie od tego,
  co robią kreski. To mnożnik listy, nie ozdoba: krzyżyk z obrotem 90° to
  krzyżyk stojący, daszek z obrotem 90° pokazuje w dół. Dzięki niej lista nie
  puchnie o pozycje różniące się wyłącznie kierunkiem — zamiast czterech
  „daszków" jest jeden i pole liczbowe.

### Zmienione

- **Kreska jest przypięta przez `left` + `width`, a nie `left` + `right`.**
  Powód nie jest ten, który wydawał się oczywisty. Przypięcie do obu krawędzi
  wcale NIE unieważnia `width`: przy trzech podanych wartościach układ jest
  nadokreślony i przeglądarka po prostu ignoruje jedną — skracanie kresek
  działało i tak (zmierzone).

  Problem jest inny: ignorowana jest ta wartość, która odpowiada KOŃCOWI
  linijki pisma. Przy `dir="rtl"` wypada `left`, więc skrócone ostrze strzałki
  przyklejało się do PRAWEJ krawędzi zamiast do lewej — zmierzone, 21 px
  zamiast 0. `left` + `width` daje ten sam wynik w obu kierunkach pisma.

## [1.76.0] — 2026-08-12

### Dodane

- **Burger umie sterować CUDZYM elementem** — tak jak przełącznik Bricksa.
  Nowe pole „Selektor celu": kliknięcie nakłada wskazanemu elementowi klasę
  `brx-open` (plus dowolne własne, dopisane obok).

  **I druga połowa, bez której byłoby to przepisanie cudzego pomysłu razem
  z jego usterką: burger IDZIE ZA CELEM.** Gdyby tylko nakładał klasę, a swój
  wygląd trzymał osobno, zamknięcie panelu czymkolwiek innym — własnym
  skryptem, klawiszem, przyciskiem „zamknij" w środku — zostawiłoby krzyżyk na
  przycisku. Czyli dokładnie usterka, którą przez cztery wersje naprawialiśmy
  w menu. Obserwator na klasie celu sprawia, że właściciel jest **jeden**.

  - Gdy cel ma identyfikator, burger dostaje `aria-controls` — czytnik ekranu
    wie, czym ten przycisk steruje.
  - Selektor pasujący do kilku elementów: klasę dostają wszystkie, ale stan
    czytamy z **pierwszego**. Rozjechane cele dawałyby przycisk migający
    między stanami.
  - Wskazanie menu Evoke wypisuje ostrzeżenie w konsoli: ono pilnuje
    `brx-open` samo, więc stan miałby dwóch właścicieli. Właściwa droga to
    pole „Własny przełącznik → Selektor CSS" w samym menu.
  - Selektor błędny albo wskazujący w próżnię mówi, o co chodzi, i nie psuje
    strony.

### Zmienione

- **Checkbox „Sam się przełącza" zastąpiła lista „Co przełącza"** — trzy
  zachowania nie mieszczą się w dwóch stanach. Do wyboru: nic (stan z menu
  Evoke, domyślne), wskazany element, tylko siebie. Strony zapisane ze starym
  checkboxem jadą dalej bez zmian: przy braku nowego pola włączony checkbox
  nadal znaczy „tylko siebie".

## [1.75.0] — 2026-08-12

### Dodane

- **Nowy element: Burger.** Animowany przycisk menu — na razie JEDEN styl
  („Krzyżyk — trzy kreski"), bo to przelot przez całą architekturę przed
  dołożeniem reszty.

  **Ten przycisk nie ma własnego stanu i to jest cały jego sens.** Gotowe
  burgery wiążą sobie własny `click` i same przełączają swoją klasę. Wygląda to
  niewinnie, dopóki nie postawi się obok czegoś, co też chce wiedzieć, czy menu
  jest otwarte — a wtedy jeden stan ma dwóch właścicieli i wygrywa ten,
  którego nasłuch zarejestrował się później. Kosztowało to cztery wersje
  poprawek (1.70.0 → 1.74.0) i za każdym razem objaw był inny.

  Tutaj stan wystawia **menu**, a burger go tylko **czyta** — z klasy
  `brx-open`, którą Circular Menu i Offcanvas Menu nakładają swoim
  przełącznikom. Dzięki temu kreski wracają na miejsce także wtedy, gdy menu
  zamknie Esc, kliknięcie poza panelem albo kliknięcie w link — bez jednej
  linijki kodu po stronie przycisku.

  - **Konfigurowalne wszystko**: pole klikalne, szerokość kresek, grubość,
    odstęp, zaokrąglenie, kolor przed otwarciem, kolor po otwarciu, czas
    i krzywa (ze wspólnej listy wtyczki, przeliczanej na zapis CSS-a).
    Każde ustawienie jedzie zmienną CSS, więc Bricks umie je ustawić
    **osobno na breakpoincie**.
  - **Odstęp liczy się od KRAWĘDZI kresek, nie od ich środków** — inaczej przy
    grubszych kreskach przerwa znikałaby mimo niezmienionego ustawienia.
  - **Tryb „sam się przełącza"** dla użycia bez naszego menu (akordeon, panel
    filtrów). Domyślnie wyłączony, bo przy naszym menu przywracałby dokładnie
    ten problem, dla którego ten element powstał.
  - `<button type="button">` z `aria-expanded` i `aria-label` od pierwszego
    renderowania.
  - Przy redukcji ruchu kreski **przeskakują, ale nadal pokazują stan** —
    burger wyglądający tak samo przy otwartym i zamkniętym menu nie mówi nic.

  Style dokłada się **wierszem w tablicy** `Evk_Burger::styles()`, nie gałęzią
  kodu: znacznik jest wyliczany z liczby kresek. Dwukreskowe i trzykreskowe
  różnią się w tym elemencie wyłącznie tą liczbą.

## [1.74.0] — 2026-08-12

### Naprawione

- **Burger zostawał krzyżykiem, bo animacja kresek stoi na `is-active`.**
  Trzecia runda tej samej rodziny usterek i za każdym razem chodziło o INNĄ
  klasę: najpierw `brx-open` nie było wcale, potem lądowało na opakowaniu
  zamiast na `.brxe-toggle`, potem nie było go na korzeniu menu. Teraz okazało
  się, że przycisk animuje się na `is-active` — zgłoszone wprost: „trzeba zdjąć
  klasę is-active z przełącznika, wtedy się zamyka".

  **Przestajemy zgadywać, która konwencja obowiązuje, i nakładamy obie.**
  Przełącznik dostaje przy otwarciu `brx-open` (konwencja Bricksa), `is-active`
  (konwencja burgerów) oraz swoją pierwszą klasę z końcówką `--opened`
  (konwencja Evoke). Klasa stanu na przycisku, którego rolą jest otwieranie
  menu, nie ma jak zaszkodzić — a każde kolejne zgłoszenie kosztowało wersję.

### Dodane

- **Kontrolka „Klasy otwarcia przełącznika"** w obu menu. Gdyby czwarta
  konwencja też się znalazła, wpisuje się ją w pole zamiast czekać na
  poprawkę — kilka klas oddziela się spacją, dochodzą do wbudowanych.
  Wszystkie schodzą przy każdym zamknięciu: klawiszem Esc, kliknięciem poza
  panelem i kliknięciem w link.

## [1.73.0] — 2026-08-12

### Naprawione

- **Przełącznik zostawał w stanie „otwarte" po zamknięciu menu z Esc.**
  Zgłoszone dla Circular Menu; dotyczyło obu menu.

  Przyczyna nie siedziała w obsłudze Esc — ta działała i była zmierzona.
  Siedziała w tym, **czego Bricks nie widział**. Klasę `brx-open` nakładaliśmy
  wyłącznie na PRZYCISK, a Bricks trzyma stan na elemencie, który OTWIERA —
  wygląd przełącznika bywa z niego wyprowadzony, regułą typu
  `.brx-open .brxe-toggle` albo własną logiką przełącznika, która pyta o stan
  celu. Nasze menu nie zgłaszało się tam w ogóle: `is-open` to nazwa Evoke,
  dla Bricksa nic nie znacząca. Przycisk nie miał więc od czego wrócić do
  burgera — z jego punktu widzenia menu nigdy się nie otworzyło.

  **Korzeń elementu** (`.evk-cm`, `.evk-oc`) niesie teraz `brx-open` przez
  cały czas otwarcia. Korzeń, a nie panel czy powłoka: te jadą do `<body>`
  i przestają być czymkolwiek w okolicy przełącznika, a reguły Bricksa czytają
  stan przez pokrewieństwo w drzewie. Klasa schodzi tą samą drogą co reszta
  stanu — przy Esc, kliku poza panelem i kliku w link.

  Dzięki temu burger zbudowany w Bricksie animuje się bez żadnej konfiguracji,
  a natywny przełącznik Bricksa wskazujący nasze menu dostaje stan, którego
  szuka.

## [1.72.1] — 2026-08-12

### Naprawione

- **404 na `lenis.min.js.map` w konsoli.** Zminifikowany Lenis kończy się
  komentarzem `sourceMappingURL`, więc przeglądarka pyta o mapę źródeł za
  każdym razem, gdy ktoś otworzy narzędzia deweloperskie. Z unpkg mapa leżała
  obok pliku; przy przenoszeniu na własny serwer wzięliśmy sam skrypt.
  Odwiedzającym to nie szkodziło — mapy nie pobiera nikt z zamkniętą konsolą —
  ale właścicielowi strony wisiał w niej czerwony błąd bez związku z niczym.

  Mapa jedzie teraz razem z plikiem. NIE wycinamy komentarza z dystrybucji:
  to znaczyłoby modyfikowanie cudzego wydania i pamiętanie o tym przy każdym
  podbiciu wersji. Z plików GSAP-a o mapę nie prosi żaden, więc dotyczyło to
  wyłącznie Lenisa — ale test pilnuje REGUŁY, nie tego jednego pliku, bo
  kolejne wydanie może ten komentarz dołożyć.

## [1.72.0] — 2026-08-12

### Zmienione

- **GSAP i Lenis jadą z własnego serwera, nie z CDN-u.** Zmierzone na żywej
  stronie: **53 KiB w sumie, a 900–1650 ms na plik**. To nie jest koszt bajtów,
  tylko koszt POŁĄCZEŃ — cdnjs i unpkg to dwa obce hosty, każdy z własnym
  DNS + TCP + TLS przed pierwszym bajtem. Z własnego serwera te same pliki jadą
  po już otwartym połączeniu HTTP/2.

  Argument „użytkownik ma to już w pamięci podręcznej z innej strony" **nie
  obowiązuje od 2020**: przeglądarki dzielą cache per witryna, więc każda strona
  i tak pobiera swoje. Znika też zależność od cudzej dostępności i wyciek
  adresów IP odwiedzających do zewnętrznego CDN-u.

  Pliki leżą w `assets/vendor/` (GSAP 3.15.0 + pięć wtyczek, Lenis 1.3.26 —
  najnowsze wydania obu). Skąd się biorą i jak je podbić: `assets/vendor/README.md`.

### Naprawione

- **Marquee dociągał ScrollTriggera z cdnjs na każdej stronie, na której był.**
  Element używa i Observera (prędkość przewijania), i ScrollTriggera
  (zatrzymanie poza kadrem), ale deklarował tylko tego pierwszego. Brakującą
  bibliotekę dobierał więc loader awaryjny w `marquee.js` — osobnym żądaniem
  do cdnjs, i to dopiero po wykonaniu skryptu, czyli najpóźniej jak można.
  Teraz obie idą normalną kolejką WordPressa.

- **Adresy cdnjs wpisane na sztywno w `marquee.js` i `hscroll.js`.** Loadery
  awaryjne biorą adres z `window.evkGsapBase`, który PHP wystawia tuż przed
  samym GSAP-em. Bez adresu skrypt nie zgaduje ścieżki, tylko mówi w konsoli,
  czego mu brakuje — żądanie pod nieistniejący adres byłoby ciszą zamiast
  wskazówki.

  Testy ładują teraz **te same pliki, które jadą na stronę** (`assets/vendor/`),
  a nie kopię z `node_modules` — inaczej sprawdzałyby co innego.

## [1.71.0] — 2026-08-12

### Dodane

- **Circular Menu: klasa `is-open` na panelu** — zaczep dla własnego CSS-a,
  którego to menu nie miało. Offcanvas ma ją na powłoce od początku; Circular
  Menu chowa panel OBCIĘCIEM (`clip-path`), a nie klasą, więc wszystko, co ma
  reagować na otwarcie, a nie da się tego wyrazić animacją Animatora, wisiało
  w próżni. Selektor: `.evk-cm-content.is-open`.

  - **Klasa siedzi na PANELU, nie na korzeniu**, i to nie jest dowolne: przy
    domyślnie włączonym portalu panel jedzie do `<body>` i przestaje być
    potomkiem korzenia, więc `.evk-cm.is-open .evk-cm-content` nie miałoby
    czego dopasować.
  - **Schodzi dopiero, gdy kadr zaczyna się zwijać**, a nie w chwili
    kliknięcia. Przez czas wychodzenia treści panel stoi na ekranie jak stał,
    więc styl otwartego menu ma go dalej dotyczyć — inaczej wygląd
    przeskakiwałby pod nieruchomym panelem. Tak samo działa `is-open` na
    powłoce offcanvas.
  - W builderze przy „Trzymaj otwarte" klasa też jest — inaczej styl na niej
    zaczepiony nie byłby widoczny dokładnie tam, gdzie się go ustawia.

  Oba zaczepy są opisane w panelu, w sekcji „Styl zawartości".

## [1.70.1] — 2026-08-12

### Naprawione

- **`brx-open` lądowało o poziom za wysoko, gdy burger jest divem.** Poprawka
  z 1.70.0 szukała przycisku (`button`, `a`, `[role="button"]`) w środku
  wskazanego elementu — a burger Bricksa **nie zawsze jest przyciskiem**:
  bywa zwykłym divem bez roli. Szukanie kończyło się wtedy niczym, więc klasa
  siadała na opakowaniu. W drzewie `brx-open` było, tyle że arkusz Bricksa
  z animacją kresek wisi na `.brxe-toggle` i nadal go nie widział — stąd
  „w circular nadal nie ma brx-open".

  `.brxe-toggle` jest teraz na liście tego, co uznajemy za sam przełącznik.
  Dotyczy obu menu.

## [1.70.0] — 2026-08-12

### Naprawione

- **Zewnętrzny przełącznik nie dostawał klasy `brx-open`** — ani w Circular
  Menu, ani w Offcanvas Menu. To na niej wisi cała animacja burgera
  zbudowanego w Bricksie (kreski składające się w krzyżyk), więc przycisk
  zostawał burgerem przy otwartym menu i wyglądało to, jakby kliknięcie nie
  zadziałało. Klasy nie było w kodzie wtyczki **wcale**.

  Pod spodem siedziała druga, cichsza przyczyna: Circular Menu szukało
  przycisku **wewnątrz** wskazanego elementu i wychodziło, gdy nic nie
  znalazło. Selektor zewnętrznego przełącznika celuje zwykle wprost
  w przycisk, więc tą drogą nie działo się **nic** — ani klasy, ani
  `aria-expanded`. Obie postacie działają teraz tak samo: selektor na
  przycisku i selektor na jego opakowaniu.

  Dotychczasowa konwencja Evoke (`<pierwsza-klasa>--opened`) zostaje bez
  zmian — czyjeś arkusze mogą już na niej stać. Offcanvas Menu dostał ją
  przy okazji, żeby oba menu zachowywały się identycznie.

- **`aria-expanded` przeniesione z opakowania na sam przycisk** (Offcanvas
  Menu). Div z tym atrybutem nie jest dla czytnika ekranu żadnym przyciskiem —
  stan należy do sterującego, a nie do pudełka wokół niego.

### Dodane

- **„Czekanie na wyjście (s)" — w obu menu.** Do tej pory menu czekało z
  zamknięciem na CAŁĄ animację treści, więc oba ruchy szły jeden po drugim.
  Teraz da się je puścić razem.

  - **Zero znaczy „naraz"**: kadr zamyka się RÓWNOCZEŚNIE z animacją linków.
  - **Puste pole** to dotychczasowe zachowanie — cały czas animacji, nie
    dłużej niż sekundę.
  - **Wartość większa niż sama animacja** daje chwilę ciszy przed zamknięciem.

  Rozdzielone są tu dwie rzeczy, które wcześniej były jedną: moment, w którym
  treść rusza (zawsze od razu), i to, ile menu na nią czeka. Przy redukcji
  ruchu czekanie wychodzi zero **mimo jawnie ustawionej wartości** — inaczej
  menu wisiałoby otwarte, czekając na animację, której nie ma.

## [1.69.0] — 2026-08-12

### Naprawione

- **Animacje w Circular Menu grały raz na całe życie strony.** Panel jest
  `position: fixed; inset: 0`, a chowa go OBCIĘCIE (`clip-path: circle(0px)`) —
  leży więc w kadrze od załadowania strony razem ze wszystkim, co ma w środku.
  Wyzwalacz „wejście w viewport" jest jednorazowy, więc wystrzeliwał przy
  starcie strony, w panelu, którego nikt jeszcze nie widział, i po pierwszym
  razie znikał. Po otwarciu menu treść po prostu BYŁA. Ta sama przyczyna co
  w offcanvas przed 1.59.0, tylko objaw inny: tam panel stał poza ekranem,
  tu jest przycięty do zera.

  **Animacje wejściowe odgrywają się teraz przy KAŻDYM otwarciu.** Przy
  włączonym portalu do `<body>` dochodzi jeszcze przeliczenie wyzwalaczy po
  przeprowadzce — zbudowane wcześniej trzymały współrzędne sprzed niej.

- **Trigger Circular Menu mówił odwrotnie, niż jest.** `aria-expanded`
  i klasa `--opened` były ustawiane PRZED przestawieniem stanu, więc po
  otwarciu menu przycisk raportował „zamknięte", a po zamknięciu „otwarte".
  Do tego atrybut pojawiał się dopiero przy pierwszym kliknięciu — do tej
  chwili czytnik ekranu nie miał skąd wiedzieć, że burger cokolwiek rozwija.

### Dodane

- **Wyjście treści przy zamykaniu — w OBU menu** (Circular Menu i Offcanvas
  Menu). Nowa kontrolka „Animuj wyjście treści": po kliknięciu ✕ najpierw
  wychodzi zawartość, a dopiero potem zwija się kadr.

  - **Bez ustawiania czegokolwiek treść wychodzi TĄ SAMĄ animacją, którą
    weszła — tylko od końca.** Co wjechało, tą samą drogą wyjeżdża.
  - **Chcesz innego wyjścia?** Nowy wyzwalacz **„Zamknięcie menu"** na liście
    Animatora. Animacja z nim ustawiona nie gra sama z siebie — czeka, aż menu
    ją zawoła — i wygrywa z cofaniem. To osobna pozycja, a nie wariant
    „wyjścia z kadru": tamto wisi na ScrollTriggerze i mierzy opuszczanie
    okna, a przy zamykaniu menu żadnego kadru się nie opuszcza.
  - **Na zamknięcie czekamy najwyżej sekundę.** Animacja ustawiona na osiem
    sekund trzymałaby menu otwarte przez osiem sekund po kliknięciu ✕ — resztę
    treść dokańcza pod zamykającym się kadrem.
  - Przy redukcji ruchu czekanie wychodzi zero i menu zamyka się natychmiast.

  Domyślnie **wyłączone**, więc zamykanie zachowuje się dokładnie jak dotąd.

- **Circular Menu: „Opóźnienie treści (s)".** Kadr ma swoje tempo, treść
  swoje. Bez odstępu oba ruchy startują w tej samej klatce i nie widać, co po
  czym następuje — ten sam problem, który w offcanvas rozwiązało opóźnienie
  panelu podrzędnego. Przez czas odstępu treść stoi w stanie POCZĄTKOWYM
  swojej animacji, więc nic nie miga.

### Zmienione

- **Circular Menu bierze krzywe ze wspólnej listy wtyczki** (`evk_anim_easings()`
  — tej samej, co Animator i Offcanvas Menu). Wcześniej miał własną kopię
  z samymi RODZINAMI GSAP-a („power2", „back") plus pole na wartość wpisywaną
  ręcznie. Rodzina bez kierunku to nie to samo co krzywa: wspólna lista niesie
  `power2.out`, `power2.inOut`, `back.out(1.7)` i `elastic.out(1, 0.5)` —
  warianty, po które trzeba było wcześniej sięgać osobnym polem tekstowym.
  Pole „Własny easing" przez to zniknęło; strony zapisane z jego wartością
  jadą dalej bez zmian.

  **Bez przeliczania na CSS.** Offcanvas jedzie na przejściach CSS i musi
  tłumaczyć nazwy przez `evk_anim_easing_css()`; Circular Menu animuje
  GSAP-em, który rozumie te nazwy wprost. Wspólna jest LISTA, nie tłumaczenie.

- **Circular Menu dostał pierwszy w historii zestaw testów** (54 sprawdzenia).
  Element działał bez żadnego pomiaru i to jest część odpowiedzi na zgłoszenie
  „animacje w środku nie działają" — nic tego nie pilnowało. Poza nowymi
  funkcjami testy obejmują też otwieranie, zamykanie, portal do `<body>`,
  `aria-expanded` i redukcję ruchu.

## [1.68.0] — 2026-08-11

### Dodane

- **Kolor liter przenika razem z tłem przy scrollu.** Moduł przewijał tło od
  sekcji do sekcji i NIE DOTYKAŁ treści, więc na stronie z ciemnymi i jasnymi
  sekcjami naprzemiennie litery zostawały w jednym kolorze i na części tła
  przestawały być czytelne.

  - **Kolor wskazujesz przy sekcji** — nowa kontrolka „Kolor liter" obok
    włącznika tła. Zostawiony pusty (domyślnie) oznacza **automat**: silnik
    dobiera jeden z dwóch kolorów z panelu (Frontend → Tło przy scrollu),
    ten o **wyższym kontraście** wobec tła sekcji. Wybór po kontraście, nie po
    progu jasności — próg zakłada, że oba kolory są skrajne, i przy tłach
    pośrednich potrafi wskazać gorszy z dwóch.
  - **Zasięg to sekcje z włączonym tłem**, nie cały dokument. Sekcja pominięta
    (tło graficzne, gradient) traci `evk-bg-handoff`, więc wypada
    i z przemalowywania liter — jedno i drugie z tego samego powodu.
  - **Ruch jest jeden.** Kolor liter jedzie TYM SAMYM tweenem co tło, nie
    osobnym: jedno okno, jedna krzywa, nic do rozjechania. Zmierzone —
    postęp obu ruchów zgadza się co do setnej na całej długości przejścia.
    Przy redukcji ruchu litery przeskakują razem z tłem, w tym samym punkcie.

  Kolor schodzi na sekcję **dziedziczeniem**, więc element z własnym kolorem
  ustawionym w builderze zostaje nietknięty — inaczej wtyczka odbierałaby
  kontrolę nad typografią. Kto chce, żeby taki element mimo to podążał za
  tłem, dodaje mu klasę `evk-bg-text`.

## [1.67.0] — 2026-08-11

### Dodane

- **Przenoszenie ustawień Evoke z elementu na element — przez „Kopiuj
  atrybuty" Bricksa.** Ta droga w praktyce już istniała, bo oba silniki Evoke
  czytają zwykłe atrybuty `data-*`, ale nic o niej nie mówiło i nie była
  pewna. Bricks kopiuje prawym przyciskiem WYŁĄCZNIE natywną kontrolkę
  Atrybuty (schowek niesie `source: bricksCopiedElementAttributes`), a nie
  kontrolki dokładane przez wtyczki — więc wystarczy wpisać tam:

  - `data-evk-anim` = nazwa animacji (`wjazd`), obiekt JSON z dopasowaniami
    (`{"animation":"wjazd","delay":0.2}`) albo tablica takich obiektów przy
    kilku animacjach naraz;
  - `data-parallax` (siła) i `data-skala`;
  - `data-evk-bg` (tło przy scrollu).

  **Wpis ręczny wygrywa z kontrolkami.** Bez tej zasady wynik zależałby od
  kolejności, w jakiej Bricks nakłada `_attributes` i filtr
  `render_attributes` — a tej kolejności wtyczka nie kontroluje. Kto właśnie
  wkleił atrybuty, ma prawo oczekiwać, że zadziałają.

  Opisy kontrolek mówią teraz o tej drodze wprost, razem z formatami.

## [1.66.0] — 2026-08-11

### Zmienione

- **Animator w elemencie Bricks: jedna lista zamiast listy PLUS kompletu pól
  obok.** Do tej pory tę samą animację dało się ustawić na dwa sposoby —
  polem „Animacja" z całym zestawem nadpisań pod spodem albo wierszem
  repeatera. Przy przenoszeniu ustawień na inny element trzeba było przez to
  przepisywać każde pole z osobna.

  Wszystkie nadpisania przeniosły się do wiersza listy — doszły **stagger,
  scrub, easing, cel i selektor, trójstanowe „powtarzaj / zapętl / odbicie /
  pin" oraz lista słów**. Płaskie kontrolki `evkAnim*` zniknęły razem
  z obsługującą je ścieżką w PHP; wtyczka jest w testach, więc dane, które
  niosły, zniknęły z nimi. Cała konfiguracja animacji elementu siedzi teraz
  w jednej kontrolce.

  Test pilnuje obu stron tej zmiany: że stare klucze płaskie **nie działają**
  (druga, niewidoczna w panelu droga wróciłaby po cichu) i że pola dokładane
  wierszom przez builder — m.in. `id` — nie wychodzą na stronę.

## [1.65.1] — 2026-08-11

### Naprawione

- **Offcanvas z lewej: podmenu wychodzi teraz SPOD panelu głównego.**
  Zgłoszone z użycia — po 1.65.0 z lewej nadal pokazywał się tylko panel
  główny. Poprzednie podejście robiło prawdziwe lustro: odwracało kolumny
  (`row-reverse`) i przesuwało kolumnę podrzędną do lewej krawędzi. W pomiarach
  wychodziło poprawnie, na żywej stronie nie — a przy okazji panel główny
  przeskakiwał na drugą połowę menu, czego nikt nie zamawiał.

  Kolumny leżą teraz w tej samej kolejności przy obu stronach, bo kadr z lewej
  i tak rośnie w prawo. Różni je **wyłącznie kierunek wjazdu**: z lewej podmenu
  startuje pod panelem głównym (slot je tam obcina, więc go nie widać)
  i wysuwa się spod niego w prawo; z prawej wjeżdża zza krawędzi ekranu.
  Panel główny **stoi w miejscu**. Rozwiązanie z podpowiedzi użytkownika.

## [1.65.0] — 2026-08-11

### Naprawione

- **Offcanvas z LEWEJ nie otwierał podmenu.** Zgłoszone z użycia. Gdy w oknie
  mieściła się tylko jedna kolumna, taśma jechała w tę samą stronę co przy
  menu z prawej — i wywoziła OBA panele poza kadr, więc po kliknięciu nie
  było widać nic. Przy menu z lewej kolumna podrzędna jest na taśmie PIERWSZA
  (odwrócona kolejność), a taśma zaczyna się przy lewej krawędzi kadru, więc
  okno i tak stoi na niej: nie trzeba niczego przesuwać.

- **Offcanvas z GÓRY i z DOŁU wysuwał podmenu bokiem.** Zgłoszone z użycia.
  Kadr wysuwa się tam pionowo, a przechodzenie między panelami było
  zahardkodowane w poziomie. Panele układają się teraz w kolumnę, a taśma
  przesuwa się na osi Y.

- **`focus()` przewijał kadr i rozwalał układ paneli.** Przyczyna obu objawów
  wyżej była częściowo wspólna i wyszła dopiero przy pomiarze: `overflow:
  hidden` OBCINA, ale zostawia pudełko przewijalne **programowo** — a
  przeglądarka z tego korzysta. Ustawienie fokusu na elemencie w panelu
  stojącym poza kadrem przewijało kadr, żeby ten element pokazać. Zmierzone:
  `scrollTop` 241 px przy menu z góry, przez co panele stały we właściwych
  miejscach, a i tak widać było nie te, co trzeba.

  Kadr i kolumna podrzędna jadą teraz na `overflow: clip` (z `hidden` jako
  zapasem dla starszych przeglądarek): obcina tak samo, ale nie tworzy
  pudełka przewijalnego. Fokus ustawiany z kodu dostał dodatkowo
  `preventScroll: true` — dwie zapory, bo `clip` nie działa wszędzie.

## [1.64.0] — 2026-08-11

### Zmienione

- **Offcanvas: panel podrzędny jest ZAWSZE JEDEN.** Zgłoszone z użycia:
  „przy otwieraniu drugiego panelu podrzędnego pierwszy musi zacząć wyjeżdżać
  w stronę, z której wyjechał, a drugi zastąpić jego miejsce"; „nie może być
  sytuacji, żeby otwarte były dwa podrzędne". Wejście w kolejne podmenu
  PODMIENIA otwarte: poprzednie wyjeżdża tam, skąd przyjechało, a nowe wjeżdża
  na jego miejsce. Kadr przy tym nie rośnie.

  Znika przez to cały stos poziomów wprowadzony w 1.63.0 — razem z nakładaniem
  i przygaszaniem panelu pod spodem. **„Wstecz" i Esc wracają teraz wprost do
  panelu głównego**, bo nie ma już poziomu pośredniego, do którego dałoby się
  cofnąć.

### Naprawione

- **Offcanvas: menu z lewej jedzie w lustrzanym odbiciu.** Zgłoszone z użycia.
  Przy menu z prawej kolumna podrzędna leży przy prawej krawędzi — tej,
  z której menu wyjechało — i panel wjeżdża stamtąd. Z lewej było tak samo,
  więc podmenu przyjeżdżało z przeciwnego końca ekranu niż samo menu. Teraz
  kolumny są odwrócone (`row-reverse`), slot siedzi przy lewej krawędzi,
  a panele wjeżdżają i wyjeżdżają w lewo. Zmierzone: podmenu staje na 0 px,
  panel główny przesuwa się z 0 na 420 px.

- **Offcanvas: wyjeżdżający panel nie wychodzi już poza swoją kolumnę.**
  Druga kolumna dostała własne pudełko z `overflow: hidden`. Wcześniej ruch
  „w tył" wylewał się na panel główny — z ekranu wyglądało to jak pasek
  doklejony do menu, a nie jak wyjeżdżanie („trzeci panel jakoś dziwnie
  najeżdża").

## [1.63.0] — 2026-08-11

### Dodane

- **Offcanvas: trzeci poziom NAJEŻDŻA na drugi.** Kolumny są dwie i tylko dwie
  — panel główny i bieżący podrzędny. Kolejny poziom nie poszerza już menu,
  tylko wjeżdża na ten otwarty, a tamten chowa się pod spodem: cofa się
  i przygasa. Zgłoszone z użycia („otwarty panel animuje się, jakby się chował,
  a nowy na niego najeżdża"). Trzecia kolumna po 420 px i tak nie zmieściłaby
  się na typowym laptopie. Przykryty poziom dostaje `inert` — nie widać go,
  więc nie ma go łapać tabulator; panel główny zostaje dostępny.

- **Offcanvas: panel podrzędny dojeżdża z opóźnieniem.** Nowa kontrolka
  „Opóźnienie panelu podrzędnego". Bez odstępu poszerzanie kadru i wjazd panelu
  zaczynają i kończą się równo, więc nie widać, co po czym następuje — całość
  wygląda sztywno. Zgłoszone z użycia. Puste pole znaczy **45% czasu
  przejścia**, a nie stałą liczbę sekund: ułamek trzyma proporcję niezależnie
  od tempa, a stała przy 0,9 s byłaby niezauważalna, przy 0,15 s zjadałaby
  całe przejście.

- **Offcanvas: kontrolki tła menu i przyciemnienia strony.** Element nie miał
  ich dotąd wcale.

### Naprawione

- **Offcanvas: zamykany panel „zmieniał kolor na biały".** Zgłoszone z użycia.
  Panel znikał natychmiast (`display: none`), a kadr zwężał się jeszcze przez
  cały czas przejścia — przez ten czas widać było samo tło kadru, domyślnie
  białe. Panel zostaje teraz narysowany do końca ruchu, a **wynosi go zwężający
  się kadr**: własnego przesunięcia nie dostaje, bo uciekłby szybciej niż kadr
  i znów odsłonił tło. Odjeżdża sam tylko przy powrocie spod kolejnego
  poziomu, gdzie kadr się nie zmienia i nikt by go stąd nie wyniósł.

  Do tego **tło kadru bierze się teraz wprost z panelu**, na którym się jest.
  Pasek odsłonięty na czas opóźnienia jest przez to niewidoczny bez ustawiania
  czegokolwiek; kontrolka „Tło menu" wygrywa, gdy ktoś ustawi ją ręcznie.

## [1.62.0] — 2026-08-11

### Dodane

- **Offcanvas: kadr poszerza się przy wejściu w podmenu.** Nowe domyślne
  zachowanie i jedyne, które odpowiada wzorowi (nextbricks): menu rośnie
  o szerokość jednego panelu na poziom, a rodzic przesuwa się w lewo
  i ZOSTAJE WIDOCZNY obok podmenu. Do tej pory rodzic wyjeżdżał całkiem poza
  kadr — zgłoszone jako „nadal nie przepycha panelu dalej", bo rodzica po
  prostu nie było widać. Zmierzone: kadr 420 → 840 px, rodzic z 780 na
  360 px i nadal w całości na ekranie.

  Poprzednie zachowanie zostaje pod kontrolką **„Wejście w podmenu"** jako
  „Rodzic wyjeżdża całkiem". Menu z góry i z dołu zawsze jedzie tym trybem —
  poszerzanie ma sens tylko w poziomie.

  Trzy rzeczy wynikające z tego, że rodzic zostaje na ekranie:
  poszerzanie bierze **tempo taśmy**, nie kadru (to ruch między panelami,
  nie wysuwanie menu); rodzic zostaje **dostępny tabulatorem**, a pułapka
  fokusu obejmuje cały kadr zamiast jednego panelu; na wąskim ekranie menu
  **samo wraca** do jednego panelu, bo dwa po 420 px nie zmieszczą się na
  telefonie.

### Naprawione

- **Offcanvas: kawałek menu migał przy ładowaniu strony.** Zgłoszone
  z użycia. Korzeń ma `display: contents`, więc do chwili uruchomienia
  skryptu panele są zwyczajnymi blokami W TREŚCIE strony — widać je
  w miejscu wstawienia elementu i rozpychają układ, dopóki JS ich nie
  przeniesie. Chowa je teraz arkusz (`.evk-oc > .evk-oc-panel`), bo JS jest
  właśnie tym, na co strona czeka. Reguła kasuje się sama: po przeniesieniu
  panel nie jest już dzieckiem korzenia, więc selektor przestaje w niego
  trafiać — nie ma tu flagi do posprzątania ani klasy do zdjęcia.

## [1.61.0] — 2026-08-11

### Naprawione

- **Offcanvas: wybranie krzywej gasiło całe przejście.** Zgłoszone z użycia
  („animacje są, ale przy wybraniu własnej krzywej przestają działać" oraz
  „nadal nie przesuwa się pierwszy panel, drugi go zasłania" — jedna
  przyczyna, dwa objawy). Lista krzywych jest wspólna z Animatorem, więc jej
  wartości są w zapisie GSAP-a: `power2.out`, `back.out(1.7)`. CSS takiej
  funkcji czasu nie zna, a nieznana wartość unieważnia CAŁĄ deklarację
  `transition` — nie tylko krzywą, ale i czas trwania. Zmierzone:
  `transition-duration` spadał z 0,6 s na **0 s**. Kadr przestawał wyjeżdżać,
  a taśma paneli przeskakiwała od razu na miejsce, przez co podmenu
  wyglądało, jakby zasłaniało rodzica zamiast go wypychać.

  Krzywe są teraz przeliczane na zapis CSS-a (`evk_anim_easing_css()`,
  jedno przeliczenie na całą wtyczkę). Odbicie i sprężyna nie dają się
  zapisać jedną krzywą Béziera — idą jako `linear()` z próbkowania
  prawdziwego wzoru GSAP-a, więc odbicie zostaje odbiciem. Strona sprawdza
  wartość jeszcze przez `CSS.supports()`, zanim ją ustawi: to, czego
  przeglądarka nie przyjmie, wraca do domyślnej krzywej z arkusza, zamiast
  gasić ruch razem z czasem.

- **Offcanvas: taśma paneli nie trzyma już stałej warstwy kompozytora.**
  `will-change: transform` siedziało na niej przez całe życie strony, choć
  rusza się ona co kilka kliknięć. Warstwa kompozytora bywa rasteryzowana
  osobno i ten sam kolor potrafi wyjść odrobinę inaczej — najbardziej na
  telefonach z szerokim gamutem. Zgłoszone jako „w mobilnym zmienia się
  kolor pierwszego [panelu]". Ruch na tym nie traci: przeglądarka promuje
  element na czas trwania przejścia sama.

  **Samego przesunięcia barwy nie da się zmierzyć w tym harnessie** —
  headless Chromium rasteryzuje wszystko jednakowo — więc test pilnuje
  reguły (`will-change` ma zostać `auto`), a nie objawu. Jeśli kolor nadal
  się zmienia, przyczyna jest inna i trzeba jej szukać dalej.

## [1.60.0] — 2026-08-11

### Naprawione

- **OpenGraph: przeciąganie warstw nie działało.** Zgłoszone z użycia. Kod
  inicjujący przeciąganie stał w bloku `<script>` w TREŚCI zakładki, a
  biblioteka `sortablejs` (i `wp.media`) jadą ze STOPKI. Skrypt zakładki
  uruchamiał się więc, zanim biblioteka w ogóle trafiła na stronę, a cichy
  warunek `if (typeof Sortable !== 'undefined')` połykał to bez śladu
  w konsoli — z zewnątrz wyglądało to na element, który po prostu nie
  reaguje. Ta sama przyczyna co usterka 1.37.1, inne miejsce.

  Poprawką nie jest odroczenie do zdarzenia `load` — to leczy objaw i wraca
  przy każdym kolejnym skrypcie dopisanym do zakładki. Cały skrypt zakładki
  (przeciąganie, dodawanie warstwy, wybór z biblioteki mediów, regeneracja
  masowa) przeniesiony do `assets/admin/admin.js`, który ma `sortablejs`
  w ZALEŻNOŚCIACH — a tylko zależność wymusza kolejność drukowania. Zakładka
  nie niesie już własnego skryptu i test tego pilnuje.

### Zmienione

- Lista typów warstw OG mieszka w jednym miejscu (`evk_og_layer_types()`)
  i tą samą drogą trafia do zakładki i do panelu. Dwie kopie rozjechałyby
  się przy pierwszym dołożonym typie, a dodana warstwa dostałaby nazwę
  „qr" zamiast „Kod QR".

## [1.59.0] — 2026-08-11

### Naprawione

- **Animacja „wejście w kadr" w menu offcanvas grała tylko raz.** Zgłoszone
  z użycia. Wyzwalacz wejścia w kadr jest z założenia jednorazowy — strona
  przewija się w jedną stronę, więc po pierwszym wejściu ScrollTrigger kończy
  pracę (`once: true`). W panelu, który się otwiera i zamyka, to założenie nie
  obowiązuje: treść animowała się przy pierwszym otwarciu, a przy każdym
  następnym po prostu była. Silnik animacji zapamiętuje teraz swoje osie czasu
  na elemencie i wystawia `window.evkAnimatorReplay(korzeń)`, a offcanvas woła
  to przy otwarciu menu i przy wejściu w podmenu. Powtórka idzie przez
  `restart(true)`, więc ustawione opóźnienia są honorowane i druga odsłona
  wygląda tak samo jak pierwsza.

## [1.58.0] — 2026-08-11

### Naprawione

- **Drugi panel zasłaniał pierwszy zamiast go przesuwać.** Zgłoszone z użycia.
  Panele leżały jeden na drugim (`position: absolute; inset: 0`), więc wejście
  w podmenu było przykryciem, a nie ruchem. Leżą teraz OBOK SIEBIE na taśmie
  (`.evk-oc-track`, flex), a przejście przesuwa całą taśmę — rodzic realnie
  odjeżdża w lewo. Zmierzone: panel startowy przechodzi z 0 na −543 px.

- **Panel i przejścia między panelami dzieliły jeden czas.** To dawało ruch
  liniowy: menu wjeżdżało i panele przesuwały się dokładnie tak samo.
  Rozdzielone na dwa niezależne ruchy — KADR (wysuwanie menu) i TAŚMA
  (przechodzenie) — każdy z własnym czasem i krzywą. Nowe kontrolki „Czas
  przejścia między panelami" i „Krzywa przejścia między panelami"; puste
  znaczy „to samo, co wysuwanie".

- **Jawnie ustawione zero czasu nie przenosiło się na przejścia paneli.**
  `frameTime || 0.35` traktowało zero jak brak wartości, więc taśma jechała
  0,35 s mimo wyłączonego ruchu.

- **Powłoka menu dostawała `display: contents`.** Dodawaliśmy jej klasę
  `.evk-oc`, żeby trafiały w nią selektory `[data-side]` — a razem z klasą
  łapała `display: contents` z tej samej reguły i traciła własne pudełko mimo
  `position: fixed`. Strona menu jedzie teraz własną klasą stanu
  (`.is-side-right` i pozostałe).

- **Szerokość panelu ustawiona w builderze nie docierała do przeniesionej
  powłoki** — z tego samego powodu, co wcześniej czasy: powłoka jedzie do
  `<body>` i przestaje dziedziczyć po korzeniu. Przepisywana razem z resztą.

## [1.57.1] — 2026-08-11

### Naprawione

- **Panel offcanvas nie wysuwał się — pojawiał się natychmiast.** Zgłoszone
  z użycia. Powłoka była chowana `display: none`, a przejścia CSS **nie da się
  uruchomić z `display: none`**: przeglądarka nie ma stanu wyjściowego do
  interpolacji i skacze prosto do końcowego. Ukrywanie idzie teraz
  `visibility` z opóźnieniem równym czasowi przejścia, żeby powłoka znikała
  dopiero PO wyjechaniu panelu, plus `pointer-events`, żeby niewidoczna
  warstwa na całym ekranie nie łapała kliknięć.

- **Ustawienie „czas wysuwania" nie robiło nic.** Zmienne CSS były ustawiane
  na korzeniu elementu, a powłoka jedzie do `<body>` i przestaje być jego
  potomkiem — nie dziedziczyła więc niczego i przejście brało wartość zapasową
  z arkusza. Pomiar pokazał 0,35 s przy ustawionych 0,6 s. Zmienne trafiają
  teraz również na powłokę.

### Zmienione

- **Opis w kontrolce mówi wprost, jak otworzyć drugi panel.** Dotychczasowy
  wymieniał nazwy atrybutów, ale nie mówił, gdzie się je wpisuje ani że bez
  żadnej konfiguracji działa `data-evk-oc-go="1"` — panele bez nazwy liczą się
  po kolejności. To była droga, którą idzie każdy przed przeczytaniem
  czegokolwiek, i jedyna, której nie sprawdzał żaden test. Teraz sprawdza.

## [1.57.0] — 2026-08-11

### Zmienione

- **Trzydzieści osiem opisów schowanych w akordeony.** Neutralna ramka
  informacyjna to treść dla kogoś, kto pyta — nie dla kogoś, kto przyszedł
  zmienić jedno pole. Zwinięte `<details>` zamiast bloku na pół ekranu.

  **Dziewięć ramek zostało widocznych** i to jest sedno tej zmiany, a nie
  efekt uboczny: pięć ze stanem (`is-ok`, `is-warn`, `is-err`) i cztery
  renderowane warunkowo — brak SMTP, wykryte fonty, stan logów 404,
  brak przekierowań. Ostrzeżenie w akordeonie jest bezużyteczne, bo nikt go
  nie rozwinie, zanim nie zobaczy problemu.

- **Pięć najdłuższych podpowiedzi (140–272 znaki) zamienionych w dymki.**
  Widoczne po najechaniu ORAZ na fokusie, z `aria-label` — dymek osiągalny
  wyłącznie myszą nie jest podpowiedzią, tylko ozdobą.

### Uwagi z pomiaru

- **`getClientRects()` na zwiniętym `<details>` NADAL zwraca prostokąt.**
  Chromium trzyma treść w `::details-content` z `content-visibility: hidden`,
  więc pudełko istnieje, choć nic nie widać — sprawdzenie „opisy zwinięte"
  świeciło na czerwono przy całkowicie poprawnym markupie. Mierzona jest teraz
  wysokość: zwinięty `<details>` jest dokładnie tak wysoki jak jego `summary`.

- **Sprawdzenie „ramki ze stanem zostają widoczne" początkowo nie miało zębów.**
  Łapało ramkę UKRYTĄ, ale nie taką, którą ktoś zamienił w akordeon — wtedy
  ramka znika z pomiaru i „wszystkie widoczne" jest prawdą przy zerze ramek.
  Potwierdzone celowym zepsuciem: podmiana `is-ok` na `evo-note` nie zapaliła
  niczego. Teraz sprawdzana jest LICZBA, która rośnie tylko przy dokładaniu
  zakładek i nigdy nie spada sama.

## [1.56.1] — 2026-08-11

### Naprawione

- **Przełącznik „Offcanvas Menu" nie włączał modułu.** Zgłoszone z użycia.
  Handler AJAX `evk_ajax_toggle` ma własną białą listę „opcja → dozwolone
  pola" i lista elementów była w niej **przepisana ręcznie**. Nowy element miał
  wpis w rejestrze i włącznik w panelu, ale przełącznik odbijał się z
  `not_allowed: evk_elements/offcanvas_menu`. Panel rysował się poprawnie,
  więc jedyną drogą do zauważenia było kliknięcie.

  Lista powstaje teraz **z rejestru elementów**, więc nie ma czego zapomnieć.
  Test woła prawdziwy handler dla każdej pozycji rejestru — porównanie dwóch
  list byłoby tautologią, odkąd jedna powstaje z drugiej.

## [1.56.0] — 2026-08-11

### Dodane

- **Nowy element Bricks: Offcanvas Menu.** Wysuwany panel w dwóch trybach:
  „swobodny panel" (jeden panel, treść w całości z buildera) i „poziomy"
  (kilka paneli, rodzic wyjeżdża, podmenu zajmuje jego miejsce). Projekt
  i uzasadnienie decyzji: `docs/offcanvas-menu-szkic.md`.

  Jeden element, nie dwa: oba tryby dzielą całą trudną część — wysuwanie,
  przyciemnienie, blokadę przewijania, pułapkę fokusu, Esc, powrót fokusu
  na trigger, redukcję ruchu. Różni je tylko liczba paneli w środku.

  Panele są dziećmi nestable, nie są generowane z menu WordPressa. Pozycją
  menu może być więc cokolwiek — kafelek z obrazkiem, siatka, blok kontaktowy
  — bo element nie narzuca znaczników. Przejścia robią atrybuty na dowolnym
  elemencie: `data-evk-oc-go="ID"`, `data-evk-oc-back`, `data-evk-oc-close`.

  Osobny włącznik w zakładce Elementy, domyślnie wyłączony, jak reszta.

### Uwagi z budowy

- **Panel wysunięty poza ekran nadal łapie fokus.** `transform: translateX(-100%)`
  nie usuwa niczego z kolejności tabulacji, więc odnośniki z panelu, którego
  nie widać, są osiągalne tabulatorem. Panele niebieżące dostają `inert`.
  Wizualnie wszystko wygląda poprawnie — widać to dopiero tabulatorem albo
  testem, i to jedyny powód, dla którego to sprawdzenie istnieje.

- **Sprawdzenie „blokada przewijania nie przesuwa układu" okazało się puste.**
  Headless Chromium rysuje pasek nakładkowy, więc `innerWidth − clientWidth`
  wychodzi 0 nawet na stronie wysokiej na 3000 px — pomiar przechodził TAKŻE
  po usunięciu kompensaty z kodu (potwierdzone celowym zepsuciem; jawne
  `::-webkit-scrollbar { width: 15px }` niczego nie zmieniło). Zamiast zielonego
  pomiaru bez treści zostało to, co realnie mierzalne: blokada zakłada się,
  zdejmuje i nie zostawia po sobie wcięcia. Kompensata w kodzie została —
  po prostu nie udajemy, że ją sprawdzamy.

## [1.55.0] — 2026-08-11

### Zmienione

- **Meta SEO: stronicowanie i szukanie po stronie serwera.** Zakładka ładowała
  WSZYSTKIE opublikowane wpisy i strony naraz (`posts_per_page => -1`), na
  każdy typ treści osobno, rysując po trzy pola i sześć checkboksów na wpis.
  Przy pięciuset wpisach to ~4500 kontrolek w DOM. Teraz jeden typ treści
  naraz, dwadzieścia wpisów na stronę.

  Szukanie musiało wejść RAZEM ze stronicowaniem, nie po nim: dawna
  wyszukiwarka filtrowała już załadowane wiersze, więc samo stronicowanie
  zamieniłoby ją w narzędzie przeszukujące bieżącą stronę — zabrałoby funkcję,
  która wcześniej działała. Test szuka wpisu, którego na pierwszej stronie
  **nie ma**; inaczej przechodziłby także dla filtra po stronie przeglądarki.

- **„Zapisz wszystkie" → „Zapisz zmienione".** Przycisk wysyłał każdy wiersz
  na stronie, także nietknięty. Przy dwóch osobach w panelu jedna nadpisywała
  drugiej świeżo wpisane wartości tymi sprzed swojego załadowania — to nie
  było „odświeżenie danych", tylko cofnięcie cudzej pracy. Wiersze z niezapisaną
  zmianą są oznaczone kreską na krawędzi, a gdy nie ma czego zapisać, leci
  komunikat zamiast pustego żądania.

- **Skrypt zakładki przeniesiony do `admin.js`.** Malował przycisk zapisu
  literałami koloru (`#16a34a`, `#dc2626`) — ten sam problem, który usuwaliśmy
  z atrybutów `style=`, tylko o poziom wyżej. Stan idzie klasą, kolor siedzi
  w arkuszu.

- **`input[type=search]` dopisany do skóry Fields.** Skóra wylicza typy pól po
  nazwie, więc nieuwzględniony wypada z niej po cichu: nowa wyszukiwarka miała
  30 px zamiast 38 i wyglądała jak z innego panelu. Złapane pomiarem przy
  pierwszym uruchomieniu.

### Naprawione

- **`:is()` w selektorze delegowanym jQuery.** Silnik selektorów jQuery go nie
  zna i rzuca „unsupported pseudo" — przy delegacji na `document` leci to przy
  każdym zdarzeniu, więc śledzenie zmian nie podpinało się wcale. Wyszło
  z testu przy pierwszym przebiegu, nie z użycia.

## [1.54.0] — 2026-08-10

### Dodane

- **Piętnaście kolejnych animacji wyjścia** — z sześciu do dwudziestu jeden.
  Wyjścia były wyraźnie uboższe od wejść (21 pozycji), więc doszły lustrzane
  odpowiedniki tamtych: zanik w lewo i w prawo, powiększenie, obrót, skew,
  wychylenie, wytoczenie, flip 3D w obu osiach, rozmycie w dół, zasłona
  w cztery strony oraz zjazd za maskę w lewo i w prawo.

  „Powiększenie" stoi obok „zmniejszenia" celowo: element rosnący przy
  zanikaniu czyta się jak oddalenie, a malejący jak znikanie w punkt — to dwa
  różne efekty, nie wariant jednego.

## [1.53.1] — 2026-08-10

### Dodane

- **`docs/timeline-szkic.md`** — projekt wizualnej osi czasu w Animatorze,
  bez kodu. Rozstrzyga, co taka oś w ogóle może pokazać: tylko wyzwalacz
  „Load", bo tylko tam animacje składają się we wspólną oś. Arytmetyka jest
  policzalna w panelu co do sekundy (i dwa elementy z tą samą animacją niczego
  nie zmieniają), z jednym wyjątkiem — stagger, bo panel nie wie, ile celów ma
  tween. Reszta wyzwalaczy jest wypisana z powodem, dla którego na osi nie stoi.

## [1.53.0] — 2026-08-10

### Dodane

- **Moment zmiany tła przy scrollu da się przesunąć.** Nowe ustawienie
  „Początek przejścia (%)" mówi, na jakiej wysokości ekranu nadchodząca sekcja
  przejmuje tło: **100** = w chwili, gdy jej górna krawędź wjeżdża od dołu
  (dotychczasowe zachowanie), mniej = zmiana następuje **później**, gdy sekcja
  jest już wyżej.

  Do tej pory `start: 'top bottom'` było zahardkodowane w silniku, a ustawienie
  „długość" ruszało **wyłącznie koniec** przejścia — początku nie dało się
  przesunąć wcale. Nowy wzór to `koniec = początek − długość·100`; przy
  początku 100 daje dokładnie dawne `(1 − długość)·100`, więc strony bez
  nadpisania wyglądają co do piksela tak jak wcześniej.

- **Nadpisanie per sekcja.** `data-evk-bg` był pustym znacznikiem i teraz może
  nieść procent — „przełącz tło, gdy TA sekcja dojdzie do X%". Pusty nadal
  znaczy „wartość globalna", bo taki niosą wszystkie istniejące strony.
  W Bricks: pole „Początek przejścia (%)" pod przełącznikiem sekcji.

### Zmienione

- **Ścieżka redukcji ruchu przeskakuje w punkcie końca przejścia** zamiast
  twardego `'top center'`. Przy domyślnych (początek 100, długość 0,5) wypada
  to na 50%, czyli dokładnie tam, gdzie stała stara wartość — uogólnienie,
  nie przestawienie.

- Test tła mierzy teraz **punkt odjazdu koloru**, a nie kolor w losowym
  miejscu. Różnica jest istotna: koniec przejścia liczy się od początku, więc
  zepsucie samego startu przesuwa całe okno i pomiar „gdzieś w środku" pokazuje
  zmianę nawet wtedy, gdy start nie działa. Pierwsza wersja tego bloku świeciła
  na zielono z przywróconym `'top bottom'` — dopiero pomiar punktu odjazdu
  zapalił się na czerwono. Wcześniej test nie sprawdzał ani `length`, ani
  `smooth`, ani momentu startu.

## [1.52.0] — 2026-08-10

### Dodane

- **Repeater „Wiele animacji" w panelu elementu Bricks.** Element dostaje listę
  animacji zamiast jednego zestawu pól; każdy wiersz ma własną animację,
  wyzwalacz, czas, opóźnienie, kolejność, start/koniec i cel. Wyjście z kadru
  jest zwykłą pozycją listy — wystarczy preset z grupy „Wyjścia".

  `default => []` jest tu warunkiem bezpieczeństwa, nie preferencją: kontrolka
  wchodzi filtrem do **każdego** zarejestrowanego elementu Bricks, więc
  domyślny wiersz dołożyłby animację wszystkiemu na stronie.

### Zmienione

- **Budowa atrybutu wydzielona do funkcji na jeden wiersz.** Obie drogi —
  repeater i dotychczasowe pola płaskie — przechodzą przez ten sam
  `evk_bricks_anim_cfg()`, więc nie ma dwóch miejsc, które mogą się rozejść.

- **Stare pola `evkAnim*` zostają i działają dalej.** Niosą je wszystkie strony
  zbudowane przed repeaterem; ich utrata znaczyłaby, że aktualizacja wtyczki
  gasi animacje wszędzie tam, gdzie ktoś ich użył. Repeater wygrywa dopiero,
  gdy jest niepusty — inaczej nie dałoby się przejść na listę bez czyszczenia
  starych ustawień, których panel nawet nie pokazuje.

- **Jedna animacja jedzie jako obiekt, nie jednoelementowa tablica.** Trzy
  linie kupujące odporność na najczęstszy układ przy aktualizacji: nowe PHP już
  działa, a `animator.js` siedzi jeszcze w cache przeglądarki. Stary silnik
  uznaje JSON zaczynający się od `[` za goły slug i element przestaje się
  animować bez śladu w konsoli.

- Wiersze repeatera czytane są po **whiteliście kluczy**, nie pętlą po całym
  wierszu — builder dokłada im własne pola, które nie mają prawa wyjść
  na stronę jako część konfiguracji.

## [1.51.0] — 2026-08-10

### Dodane

- **Wiele animacji na jednym elemencie.** `data-evk-anim` przyjmuje teraz
  tablicę konfiguracji, a element z kilkoma klasami `evk-anim-*` dostaje
  wszystkie, nie pierwszą. Stare formaty — goły slug i pojedynczy obiekt —
  działają bez zmian; są zapisane na istniejących stronach, więc test mierzy
  je osobno i wymaga, żeby dawały DOKŁADNIE JEDNĄ animację.

- **Animacje wychodzące.** Nowy wyzwalacz „Wyjście z kadru" i sześć presetów
  wyjściowych (zanik, zanik w górę/w dół, zmniejszenie, rozmycie, zasłona).
  Wyjście jest OSOBNĄ konfiguracją z własnym presetem, czasem i krzywą — a nie
  cofnięciem wejścia.

  Gra w obie strony: element ucieka górą przy przewijaniu w dół i dołem przy
  powrocie do góry (`toggleActions` ma cztery sloty i wypełnione są wszystkie).
  Powrót w kadr cofa wyjście. `once: false` jest warunkiem działania — przy
  `once: true` pierwsze wyjście zabiłoby wyzwalacz i element z `opacity: 0`
  zostałby niewidzialny na zawsze.

- **Krzywe `power2.in` i `power3.in`** — wejście zwalnia ku końcowi, wyjście
  ma przyspieszać. Bez nich sanityzacja odrzucałaby easing presetów wyjściowych.

- **Presety w panelu podzielone na „Wejścia" i „Wyjścia".** Preset wyjściowy
  wybrany przez pomyłkę z płaskiej listy czterdziestu pozycji wygląda jak
  zepsuta wtyczka: element znika bez wyjaśnienia.

### Naprawione

- **`clearProps` po animacji cofał wyjście.** Znalezione testem, nie z użycia:
  „zasłona w górę" pod wyzwalaczem wejścia kończyła z `clip-path: none`, bo
  clipPath jest na liście czyszczonych właściwości — element wracał widoczny
  mimo poprawnie odegranej animacji. Zanik ocalał tylko dlatego, że `opacity`
  nie jest czyszczone. Sprzątanie omija teraz wszystko, co kończy niewidocznie,
  po znaczniku z tablicy presetów — sam wyzwalacz jako kryterium nie wystarcza.

- **Redukcja ruchu gasiła element na stałe.** Ścieżka „bez ruchu, ale stan
  końcowy widoczny" nakładała `cfg.to`, a dla wyjścia to `opacity: 0`.
  Konfiguracje wyjściowe są w tej gałęzi pomijane w całości, jak hover i klik.

- **`_evkAnimAbort` był pojedynczym slotem** (i martwym zapisem — dwa
  przypisania, zero odczytów w całym repozytorium). Przy dwóch animacjach
  interaktywnych na jednym elemencie drugi zapis gubił uchwyt pierwszej.
  Teraz lista.

- **Drugi podział tekstu na tym samym elemencie** dzieliłby już podzielony DOM,
  a `autoSplit` przy zmianie szerokości okna odbudowywałby kawałki, na których
  wisi pierwsza oś czasu. Kolejne konfiguracje tracą podział z ostrzeżeniem.

- **Kawałki SplitText nie są slugami.** Noszą klasy `evk-anim-line|word|char`,
  więc przy czytaniu wszystkich klas każdy kawałek zgłaszałby „brak animacji
  w bibliotece".

## [1.50.0] — 2026-08-10

### Dodane

- **Cel animacji poza elementem wyzwalającym.** Przewinięcie do sekcji może
  teraz animować coś zupełnie innego — nagłówek, tło, element w innej części
  strony. Nowa pozycja „Element poza tym (cała strona)" w polu „Cel animacji",
  w builderze i w bibliotece.

  Silnik rozdzielał wyzwalacz od celu od zawsze: `scrollTrigger.trigger` to
  element, a to, co się rusza, wybiera `resolveTargets()`. Brakowało wyłącznie
  **zasięgu** — `el.querySelectorAll()` widzi tylko potomków.

  Przy celu zewnętrznym **nie ma powrotu do siebie**, gdy selektor nic nie
  trafi. Przy celu wewnętrznym „nie znalazłem nic w środku" i „animuj całość"
  są bliskimi kuzynami; przy zewnętrznym oznaczałoby to animowanie zupełnie
  innego elementu niż ten, o który proszono, i bez żadnego znaku, że coś poszło
  nie tak. Zamiast tego ostrzeżenie w konsoli i brak animacji.

### Naprawione

- **Podgląd w Animatorze wychodzi spod dwudziestu pól.** Poprawka z 1.49.0
  postawiła ▶ przy kadrze, ale cały blok nadal dopisywał się na KOŃCU siatki
  pól — a wiersz ma 891 px przy oknie 520 px, więc przy pracy nad polami kadr
  był poza kadrem okna. Zgłoszenie „przy otwartym akordeonie nie widać jednego
  albo drugiego" było więc dalej prawdziwe.

  Blok stoi teraz zaraz pod nagłówkiem i **przykleja się** przy przewijaniu.
  Musiał w tym celu wyjść z siatki: `position: sticky` na dziecku siatki nic
  nie daje, bo blokiem zawierającym jest wtedy jego własny obszar siatki,
  wysoki na jedno pudełko. Jako rodzeństwo siatki dostaje za blok zawierający
  cały wiersz — a wiersz świadomie nie ma `overflow: hidden`.

## [1.49.0] — 2026-08-10

### Naprawione

- **▶ w Animatorze stał w pasku nagłówka, a pudełko z przykładem kilkaset
  pikseli niżej.** Przy rozwiniętym akordeonie widać było albo jedno, albo
  drugie. Przycisk siedzi teraz w bloku podglądu, tuż obok kadru — i zostaje
  przy nim także na telefonie: blok zawija się (`flex-wrap`), więc do drugiego
  wiersza schodzi podpis, a nie przycisk.

  Zgłoszone z użycia, nie z pomiaru — bo pomiar pilnował właśnie tamtego
  miejsca („▶ sąsiaduje z «Usuń»"). Sprawdzenie mówi teraz, o co naprawdę
  chodzi: ▶ jest wewnątrz `.evo-anim-preview`, ma najwyżej 16 px odstępu od
  kadru **i leży na jego wysokości**. Sam odstęp poziomy nie miałby zębów —
  przycisk zwinięty POD kadrem też ma odstęp bliski zeru.

- **Przyciski „Logów zdarzeń" w raportach nie mieściły się na ekranie.**
  Zmierzone: przy oknie 360 px wiersz potrzebował **324 px, a miał 300**,
  i „Wyczyść logi" znikało za krawędzią karty.

  Karta ma `overflow-x: hidden`, więc pasek nie rozpychał strony — był
  **odcinany**. Dlatego sprawdzenie wystawania poza okno świeciło na zielono:
  nic nigdy nie przekraczało krawędzi okna. Wiersz zawija się teraz razem
  z paskiem, a `.evk-nl-row-flush` zastępuje inline'owe `padding:0`
  (kampanie miały tę samą łatkę wpisaną w atrybut).

- **Lista „Typ działalności" w Schemie rozpychała stronę do 436 px** — tak
  samo przy oknie 390 px, jak i 360 px, bo o szerokości decydowała najdłuższa
  opcja („Miejsce zakwaterowania — LodgingBusiness"), a nie okno. Dziecko
  siatki ma domyślnie `min-width: auto`. Siatki dostały `min-width: 0`,
  a listy rozwijane w panelu `max-width: 100%`.

### Zmienione

- **Zakładki SEO przemiecione: `schema` 32 → 4 atrybuty `style=`,
  `tab-meta` 7 → 0, `tab-sitemap` 7 → 0.** Zostały wyłącznie zmienne siatki
  (`--evo-col`, `--evo-gap`) — one są konfiguracją układu, nie wyglądem.
  Zero literałów koloru w atrybutach: `#f8fafc`/`#d7dde7` na kartach wyboru,
  `#888`, `#475569` i `#6b7280` na podpisach ustąpiły tokenom.

### Dodane

- **Trzy zakładki SEO w harnessie** (`schema`, `sitemap`, `seo-meta`) —
  z atrapami `WP_Query`, `get_posts` i meta wpisów, bo bez wpisów tabela
  meta tagów rysuje się pusta, a to w jej wierszach siedzą pola.
- **Sprawdzenie „nic nie jest ucięte przez kontener".** Wystawanie poza okno
  to tylko połowa; druga jest gorsza, bo niewidoczna — kontener z ukrytym
  nadmiarem chowa to, co się w nim nie mieści, i przycisk przestaje istnieć
  dla klikającego. Pola formularza są wyłączone (`scrollWidth` mierzy tam
  długość wpisanej wartości), tak samo skracanie wielokropkiem — tam ucinanie
  jest zamierzone.
- **Pomiar także przy 360 px.** Pasek logów mieścił się na 390 px co do
  piksela, a w prawdziwym panelu wp-admin dokłada własne wcięcia. Usterka
  ze zgłoszenia była widoczna dopiero przy węższym oknie.
- Sprawdzenie „jest co mierzyć" — zakładka „Mapa strony" to same checkboxy
  i przyciski, więc dotychczasowe „są kontrolki" wywracało się na poprawnym
  ekranie, a na pustym renderze cała reszta bloku przechodziła na pustej liście.

## [1.48.0] — 2026-08-08

### Naprawione

- **Zdublowany atrybut `class` w 37 miejscach.** Przeglądarka bierze
  **pierwszy** `class` i po cichu ignoruje resztę, więc klasa dopisana jako
  drugi atrybut nie działa — a w źródle wygląda, jakby działała. Weszły tak
  przy przemiataniu zakładek w 1.44.0 i 1.45.0, między innymi `is-ok` i `is-err`
  na ramkach informacyjnych OpenGraph (renderowały się jako neutralne)
  oraz `evo-w-120/130/140` w konstruktorze paska White Label.

  Żaden pomiar wyglądu tego nie widział: klasa nie miała złej wartości,
  tylko nie istniała. Skan `tests/php/dup-class.php` sprawdza to teraz
  w źródle — z **maskowaniem bloków PHP**, bo `<?php … 'a' => 'b' … ?>`
  w atrybucie urywa znacznik na pierwszym `>` i drugi `class` wypada poza
  pole widzenia (pierwsza wersja skanu zgłosiła przez to zero przy siedmiu
  realnych).

- **Newsletter na telefonie.** Raporty rozpychały stronę do **682 px** przy
  oknie 390 px, szablony do 449 px. Trzy przyczyny, każda zmierzona:
  podział `220px 1fr` trzymał się poniżej 900 px; kolumny siatki miały
  domyślne `min-width: auto`, więc nie schodziły poniżej najszerszej rzeczy
  w środku; tabele nie miały własnego przewijania.

### Zmienione

- **Trzy bloki `<style>` z zakładek newslettera → `admin.css`.** Powielały się
  przy tym **między sobą**, i to z rozbieżnościami: `.evk-nl-card` miało
  `overflow:hidden` w listach i szablonach, a `padding:20px` w kampaniach;
  `.evk-nl-label-mt` raz 12 px, raz 14 px. Teraz jest jedna definicja plus
  jawny wariant `.is-padded` — rozbieżność, która była przypadkiem, stała się
  decyzją.

- **Stany newslettera jako NAZWY, nie kody koloru.** Zakładki trzymały mapę
  `stan → '#94a3b8'` w PHP i wstrzykiwały hex prosto w atrybut. Kod
  szesnastkowy to wygląd, nie dane: leżąc w PHP nie reagował na zmianę palety
  i odcinał te miejsca od tokenów. PHP niesie teraz nazwę stanu
  (`evk-nl-s-draft`, `evk-nl-e-open`, `evk-nl-k-sent`), a kolor jest w arkuszu.

- **Kafelki statystyk `auto-fit` zamiast `repeat(5, 1fr)`.** To **nie** jest
  poprawka przepełnienia — sprawdzone celowym cofnięciem: pięć kolumn `1fr`
  mieści się na 390 px, bo `1fr` je po prostu ściska. Chodzi o czytelność:
  kafelek schodził do **62 px** i etykieta łamała się na trzy linie.

- **Zakładki newslettera: 179 → 83 atrybutów `style=`.**
  Raporty 35 → 7, szablony 24 → 7.

### Dodane

- Pięć zakładek newslettera dołączyło do harnessu (`tests/php/tab.php`)
  wraz z **danymi próbnymi**: pusta baza rysuje ekrany listowe bez wierszy,
  a to w nich siedzi większość znaczników.
- Blok mierzący **wąski ekran**: co wystaje poza okno przy 390 px, z jawnym
  wyjątkiem dla elementów w kontenerze z własnym przewijaniem — tam przewija
  się tabela, a nie strona, i to jest poprawne.

## [1.47.1] — 2026-08-08

### Naprawione

- **Podgląd animacji wyjeżdżał poza wiersz** — zgłoszone z panelu, potwierdzone
  pomiarem: preset „fade z lewej" wypychał scenę **13 px poza wiersz**, i to
  już 50 ms po starcie. Tak samo na 1200 px, jak i na 390 px.

  Przyczyna: domyślnym celem animacji jest **sam element**, więc GSAP przesuwa
  scenę — a `overflow: hidden` na scenie obcina wyłącznie jej **dzieci**, nie
  ją samą. Scena siedzi teraz w nieruchomym **kadrze**, który ją obcina.

  Test mierzy to, co WIDAĆ: scenę przyciętą przez wszystkich przodków
  z `overflow: hidden`. Sama geometria niczego by nie pokazała —
  `getBoundingClientRect()` ignoruje obcięcie, więc scena „wystaje" zawsze.
  Do pary idzie kontrola sensowności: gdyby scena w ogóle się nie ruszała,
  „nic nie wystaje" byłoby prawdą bez zasługi kadru.

- **Migotanie testu presetów najechania.** Sprawdzenie „po najechaniu nadal
  wszystko widoczne" próbkowało stan po sztywnych 900 ms. W izolacji to
  wystarczało, ale przy pełnym zestawie animacja bywała jeszcze w drodze.
  Czekamy teraz na **warunek**, nie na zegar — a że usterka, której to
  sprawdzenie broni, nie kończy się nigdy, czekanie niczego nie osłabia.

## [1.47.0] — 2026-08-08

### Dodane

- **Podgląd animacji w bibliotece.** Przycisk ▶ przy nagłówku wiersza odgrywa
  animację w pudełku obok pól — bez wychodzenia na stronę i bez odświeżania.
  Czyta **żywe wartości pól**, nie zapisane: zmiana czasu czy easingu jest
  widoczna od razu, jeszcze przed zapisem.

  **Podgląd nie ma własnej kopii logiki animacji.** Panel podaje wartości pól
  w `data-evk-anim` i woła `evkAnimatorPreview()` — dalej dzieje się dokładnie
  to, co na stronie: `buildConfig()`, `tweenVars()`, `startVars()`, ten sam GSAP.
  Druga implementacja w panelu rozjechałaby się z silnikiem i podgląd
  pokazywałby coś, czego odwiedzający nigdy nie zobaczy. Pilnuje tego test,
  porównując parametry podglądu z tablicą presetów z PHP.

  Czego podgląd **nie** udaje: wyzwalaczy. W pudełku 120×80 nie ma czego
  przewijać ani przypinać, więc presety scrollowe grają jako zwykła animacja —
  i panel mówi to wprost pod sceną, zamiast sugerować, że pokazuje całość.

  Redukcja ruchu obowiązuje tak samo jak na stronie: bez ruchu, ze stanem
  końcowym.

### Zmienione

- **Rejestracja wtyczek GSAP wyjęta z `waitForGSAP()`** do osobnej funkcji.
  W panelu `start()` kończy od razu (pusta biblioteka, brak elementów
  z `data-evk-anim`), więc rejestracja tamtą drogą nigdy by nie zaszła
  i SplitText leżałby załadowany, ale nieaktywny.

- **Parser `opacity: 0` doczekał się odpowiednika w JS** (`evkAnimatorParseProps`),
  przy silniku — format należy do animacji, nie do panelu. Dwie implementacje
  jednego formatu to dwa miejsca do rozejścia się, więc test porównuje je na
  pięciu przypadkach brzegowych.

### Usunięte

- **`stopPropagation()` przy kliknięciu ▶.** Dołożyłem je „żeby nagłówek się nie
  zwijał", ale obsługa zwijania i tak wyklucza kliknięcia w przyciski — jego
  usunięcie nie zmieniało niczego. Zamiast dublować zabezpieczenie, testem
  pilnowany jest teraz ten filtr.

## [1.46.0] — 2026-08-08

### Dodane

- **Każda zakładka zapisuje się bez przeładowania.** Jeden endpoint
  (`wp_ajax_evk_settings_save`) obsługuje **dowolną** grupę ustawień: białą
  listę bierze z rejestru WordPressa, więc dziewiętnasta zakładka zadziała
  bez dopisywania czegokolwiek. Osiemnaście endpointów na osiemnaście zakładek
  to osiemnaście miejsc do pomylenia.

  Formularz zostaje **zwykłym formularzem celującym w `options.php`** —
  przechwytujemy tylko wysłanie, a gdy AJAX padnie, puszczamy je dalej normalną
  drogą. Awaria skryptu nie może być jedyną drogą zapisu.

- **Pasek zapisu jest teraz w każdym formularzu ustawień.** Brakowało go
  w dwóch: „Przewijany kolor tła" i ustawieniach newslettera — tam przycisk
  siedział na końcu strony i przy długiej zakładce trzeba było go szukać.

### Naprawione

- **Wejście na Skrzynkę wiadomości oznaczało pierwsze zgłoszenie jako
  przeczytane.** `loadList()` otwierało pierwszy element z listy, a otwarcie
  gasi kropkę — zgłoszenie znikało z „nieprzeczytanych", zanim ktokolwiek je
  zobaczył. Prawy panel pokazuje teraz stan pusty do chwili kliknięcia.

- **Skrzynka wiadomości na telefonie.** Lista miała stałe 300 px, więc poniżej
  ~700 px zjadała połowę ekranu, a na treść zostawał pas nie do czytania.
  Poniżej 782 px widać **albo listę, albo wiadomość**, z wyjściem z powrotem
  do listy — bez niego otwarta wiadomość była ślepą uliczką. Przy okazji: pasek
  narzędzi łamie się na wiersze zamiast wypychać eksport poza ekran,
  wyszukiwarka dostała własny wiersz (dzielona z listą formularzy ucinała
  własną podpowiedź w połowie słowa), a przyciski urosły z 32 do 38 px.

  **Styli tej strony NIE przeniosłem do `admin.css`** — mimo że taki był plan.
  `admin.css` ładuje się wyłącznie na ekranie ustawień (`settings_page_evoke-one`),
  a Skrzynka to osobna strona najwyższego poziomu. Przeniesienie rozwaliłoby ją
  albo wymusiło doładowanie całego arkusza tam, gdzie nic z niego nie jest
  potrzebne.

### Usunięte

- **`grid-template-columns: 1fr` na polach wiadomości w wersji mobilnej.**
  Dopisałem tę regułę „na wszelki wypadek" i nie miała pokrycia: poniżej 600 px
  siatka `minmax(280px, 1fr)` i tak daje jedną kolumnę, a między 600 a 782 px
  reguła **pogarszała** — wymuszała jedną kolumnę tam, gdzie mieszczą się dwie.
  Wyszło to dopiero przy celowym psuciu: usunięcie reguły nie zapaliło żadnego
  testu na czerwono.

## [1.45.0] — 2026-08-08

### Zmienione

- **Cały panel dostał podział na boksy z zakładki OpenGraph.** To ona wyglądała
  najlepiej — biały boks z ramką i wersalikową etykietą zamiast luźnej treści
  poprzedzielanej kreskami. Reguła siedziała w **lokalnym `<style>`**, więc
  obowiązywała jedną zakładkę i nikt inny nie mógł jej użyć. Teraz jest
  `.evo-box` w `admin.css`, na tokenach, i niosą ją **wszystkie 25 zakładek**:
  55 boksów, zero pozostałych `evo-section-title`.

  Kreski `<hr class="evo-divider">` rozdzielające sekcje zniknęły — ramka boksu
  robi to samo i nie zostawia sekcji bez początku i końca.

  Przy przenoszeniu wyszło, że oryginał miał w nagłówku `font-size` **dwa razy**
  (13 px, potem 11 px). Wygrywała druga, więc etykiety są 11 px — i takie
  zostają; test pilnuje teraz tej wartości jawnie, zamiast zostawiać ją
  przypadkowi.

- **Zakładka OpenGraph straciła swój blok `<style>` (23 reguły).** Stał
  w dokumencie po arkuszu i wygrywał przy równej specyficzności — dokładnie ta
  pułapka co w Animatorze w 1.43.0. Warstwy generatora są teraz w `admin.css`
  na tokenach, a pola w warstwach przestały nadpisywać skórę własnym
  `border-radius:5px` i wreszcie mają kształt Evoke Fields.

- **`.evo-section-title` usunięte z arkusza** — nic już go nie używa,
  a `.evo-box > h3` niesie ten sam kształt.

### Naprawione

- **Czyszczenie logów 404 chowało sekcję na trzy raty** — osobno tabelę, kreskę
  i tytuł (`$('p.evo-section-title').last().hide()`). Każda zmiana znaczników
  to psuła. Teraz chowa się jeden boks.

### Dodane

- **Boksy są mierzone, nie zakładane.** `tests/admin-tabs.test.js` sprawdza tło,
  ramkę, promień oraz to, że **każdy boks ma nagłówek** i że nagłówek jest
  wersalikową etykietą 11/700 — bez tego boks jest tylko ramką wokół treści.
  Zakładka OpenGraph dołączyła do harnessu (`tests/php/tab.php`).

- **Konwersja była sprawdzana dwoma niezmiennikami niezależnymi od PHP:**
  treść tekstowa pliku po zdjęciu znaczników musi być identyczna, a bilans
  `<div>` ma się przesunąć dokładnie o liczbę boksów. Dla pięciu zakładek
  w harnessie doszło porównanie **tekstu wyrenderowanej strony** sprzed i po.

## [1.44.0] — 2026-08-07

### Zmienione

- **Cztery najgęstsze zakładki panelu przeszły na warstwę komponentów.**
  Skrzynka wiadomości, White Label, Dark Mode i Dostępność miały razem
  **342 atrybuty `style=`**; zostało **55**, i to wyłącznie wymiary konkretnego
  pola (`width:150px`) albo zmienne siatki (`--evo-col:190px`).

  Sedno nie jest kosmetyczne. Atrybut inline **wygrywa z każdym arkuszem**,
  więc skóra Evoke Fields z 1.43.0 nie miała jak dosięgnąć pola, które niosło
  własne `border-radius:5px;padding:5px 8px`. Reguła dla niego istniała i była
  poprawna — i nie robiła nic. Ta sama usterka co w Animatorze w 1.43.0, tylko
  że tam winowajcą był blok `<style>`, a tu atrybut przy kontrolce.

  Drugi koszt jest cichszy: `color:#6b7280` wygląda dziś jak token, ale
  przestaje za nim nadążać. Cała obietnica przejścia na tokeny — „zmiana
  palety to podmiana jednego bloku" — kończy się na pierwszym takim atrybucie.
  W czterech zakładkach było ich **130**.

- **Trzy bloki `<style>` z White Label (68 linii) wróciły do `admin.css`**,
  z kolorami zamienionymi na tokeny. Stały w dokumencie po arkuszu i wygrywały
  przy równej specyficzności — warstwa komponentów była wobec nich bezsilna.

- **Wiersz dodany przyciskiem wygląda tak samo jak zapisany.** Szablony wierszy
  w JS niosły własną kopię tych samych stylów co PHP; obie strony dostały te
  same klasy.

- **Stan karty wyboru bierze się z `:has(:checked)`, nie z PHP.** Klasa
  dopisywana przy renderowaniu opisywała stan **z chwili wysłania strony**
  i po kliknięciu zostawała nieaktualna aż do przeładowania.

- **Wartość próbnika koloru wchodzi zmienną (`--evo-swatch`), nie deklaracją
  tła.** Inline z danymi użytkownika jest w porządku; inline z `background:#…`
  zamyka element na wszelki dalszy CSS bez `!important`.

### Dodane

- **`tests/admin-tabs.test.js`** — 40 sprawdzeń, cztery zakładki. Renderuje
  **prawdziwy plik zakładki** przez `tests/php/tab.php` i **mierzy** wysokość,
  promień, ramkę i rozmiar tekstu każdej kontrolki, promień przycisków oraz
  akcent przycisku głównego. Nie szuka tekstu `style="` w źródle — sprawdza
  wynik, więc świadoma decyzja o innym kształcie pola też będzie widoczna
  i trzeba ją będzie dopisać jako wyjątek.

  Osobny blok pilnuje, żeby żaden atrybut `style` nie malował się **literałem
  koloru**. `var(--evo-…)` w inline jest w porządku — zmienna nadal wiąże
  element z paletą.

- **`tests/php/tab.php`** — generyczny renderer zakładek. Ładuje **prawdziwe
  moduły** (`includes/…`), a atrapą jest tylko to, czego moduł szuka na
  zewnątrz: baza i funkcje WordPressa.

- **Warstwa komponentów w `admin.css`**: `.evo-choice` (karta wyboru),
  `.evo-table` z `.evo-empty` (tabele mapowań), `.evo-grid` / `.evo-grid-2`
  (siatki pól ze zmienną szerokością kolumny), `.evo-toolbar`, `.evo-chip`,
  `.evo-preview`, `.evo-fold`, `.evo-callout`, `.evo-btn-plain`, `.evo-hint`,
  `.evo-ico*`, warianty `.evo-info-box.is-ok` / `.is-warn` i skala odstępów.

## [1.43.1] — 2026-08-07

### Poprawione

Trzy usterki wizualne zgłoszone po 1.43.0 — każda o innej przyczynie, wszystkie
zmierzone, nie zgadnięte.

- **Pasek zwiniętego wiersza siedział krzywo.** Nagłówek miał `padding` 12 px
  u góry i **0 u dołu**: reguła zwijania z 1.42.0 zerowała dolny, bo powstała,
  gdy wiersz miał jeszcze własny padding. Po przejściu na kartę Fields (wiersz
  bez paddingu) treść zjechała 6 px w dół. Przy zwinięciu znika teraz **tylko
  kreska**.

- **Listy rozwijane straciły strzałkę.** Skrót `background: #fff` skasował
  `background-image`, a to nim rysowana jest strzałka `<select>` — razem
  ze skrótem `padding` zniknął też zapas po prawej. Strzałkę **rysujemy teraz
  sami** (inline SVG) zamiast liczyć, że cudza reguła przetrwa nasze
  nadpisania; przy okazji da się ją sprawdzić testem.

- **Checkboxy nie stały w linii z polami.** Ich etykieta dziedziczyła
  `margin-bottom: 5px` po etykietach nagłówkowych i podnosiła checkbox
  dokładnie o tyle nad sąsiednie pole — zmierzone 703,5 przy polu na 708,5.
  Siatka dostała też `align-items: end`, bo pola mają nad sobą etykietę,
  a checkboxy nie.

## [1.43.0] — 2026-08-07

### Zmienione

- **Panel wygląda jak Evoke Fields.** Paleta była już wspólna — obie wtyczki
  stoją na tym samym `#2563eb`, `#d7dde7`, `#f8fafc`, `#eef2f7` — więc różnice
  siedziały w **kształtach**: jednolita wysokość kontrolki **38 px**, promień
  **7 px** na przyciskach, pierścień fokusu `0 0 0 3px rgba(37,99,235,.12)`,
  wersalikowe etykiety pól, ciemny dymek podpowiedzi, przyciski „dodaj" jako
  ghost z kreską, wiersz biblioteki jako **biała karta z promieniem 12 px**.

  Zestaw tokenów urósł o obramowania, stany, cienie i wymiary, więc dalsze
  zmiany wyglądu to nadal podmiana wartości w jednym bloku.

- **Zakładka Animator nie ma już własnego bloku `<style>`.** Stał on
  w dokumencie **po** arkuszu i wygrywał przy równej specyficzności — dopóki
  tam był, warstwa komponentów była wobec niego bezsilna i skóra nie miała jak
  zadziałać. Reguły przeniesione do `assets/admin/admin.css`.

  To samo dotyczy pozostałych zakładek i jest następnym krokiem; ta wersja
  domyka Animator jako wzorzec.

### Uwaga o teście

`tests/admin-style.test.js` zmienił rolę. Zaczął jako „przejście na tokeny
niczego nie przemalowało" (1.41.0); teraz pilnuje, że **skóra nie dryfuje** —
mierzy akcent, promienie, tła i wysokość kontrolki. Ta wersja przemalowuje
celowo, więc wzorzec został przestawiony na wartości z Fields.

## [1.42.0] — 2026-08-07

### Dodane

- **Wiersze biblioteki animacji można zwijać.** Kliknięcie nagłówka zwija
  i rozwija wiersz, a **stan jest zapamiętywany** — przeżywa przeładowanie
  strony i zapis przez AJAX. Nowe wiersze startują rozwinięte, bo dopiero się
  je wypełnia.

  Nagłówek pełni dwie role naraz: jest uchwytem przeciągania i przełącznikiem.
  Rozstrzyga je **dystans**, nie typ zdarzenia — biblioteka przeciągania
  wypuszcza `click` także po upuszczeniu wiersza, więc bez progu każde
  przeciągnięcie zwijałoby go przy okazji.

- **Opisy zeszły z ekranu.** Długa pomoc w zakładce Animator jest teraz sekcją
  zwijaną (`<details>`, domyślnie zamkniętą), a wskazówki przy polach —
  podpowiedziami przy ikonce „?".

  Podpowiedź pokazuje się na **najechaniu i na fokusie**. Sam hover wykluczałby
  klawiaturę, a na dotyku nie istnieje w ogóle; `aria-label` niesie tę samą
  treść, więc czytnik ekranu nie zostaje z niczym.

## [1.41.0] — 2026-08-07

### Poprawione

- **Checkboxy w Animatorze bywały spłaszczone.** Etykieta jest kontenerem
  `flex`, a pole wyboru bez `flex-shrink: 0` może się kurczyć — przy długim
  tekście i wąskiej kolumnie siatki ściskało się w poziomie i przestawało być
  kwadratem. Zmierzone: przy 480 px schodziło do 21×25, przy 782 px do 24×25.
  Poniżej 782 px WordPress powiększa pola do 25 px, więc widać to tam najlepiej
  — ale usterka nie zaczyna się na telefonie.

  Reguła obowiązuje **wszystkie** checkboxy panelu, nie tylko te w Animatorze.

### Zmienione

- **Kolory panelu przeniesione do tokenów CSS.** Jeden blok zmiennych na
  `.wrap` jest odtąd jedynym miejscem z kolorami; komponenty sięgają wyłącznie
  po zmienne. **Wygląd bez zmian** — to refaktor, nie przemalowanie, i pilnuje
  tego osobny test porównujący wyliczone wartości kluczowych komponentów.

  Dzięki temu zmiana wyglądu całego panelu sprowadza się do podmiany wartości
  w jednym bloku, zamiast przemiatania kilkuset deklaracji i trzydziestu plików.

- **Dopracowanie mobilne.** Zakładki przewijają się w poziomie zamiast łamać
  w cztery rzędy zjadające pół ekranu; przyciski panelu mają pełny cel dotykowy;
  pasek zapisu przestaje przykrywać ostatnie pole formularza; karta statusu
  przenosi przełącznik pod tekst zamiast go ściskać.

## [1.40.0] — 2026-08-07

### Dodane

- **Panel elementu w Bricks nadgania bibliotekę.** Doszły nadpisania, których
  dotąd brakowało: **easing**, **koniec** i **scrub**, **cel animacji**
  z **selektorem**, **powtarzanie**, **zapętlenie** i **odbicie**, **pin**
  oraz **lista słów** (per element ma sens — każdy może cyklować po innych).

  Świadomie poza zakresem zostają **from/to** — dwa pola wielolinijkowe,
  których miejsce jest w bibliotece; przy każdym elemencie byłyby ścianą tekstu.

  **Przełączniki są trójstanowe**, nie zwykłymi checkboxami: „z biblioteki",
  „Tak", „Nie". Checkbox ma dwa stany, a odznaczony znaczyłby „Nie" i odbierał
  możliwość zwykłego dziedziczenia — jednocześnie nie dając rady **wyłączyć**
  w elemencie czegoś, co w bibliotece jest włączone.

## [1.39.0] — 2026-08-07

### Dodane

- **Zapętlanie animacji.** Dwa nowe pola w wierszu biblioteki: **Zapętl**
  i **Pętla z odbiciem**. Pierwsze puszcza animację bez końca, drugie sprawia,
  że zamiast skakać do stanu początkowego wraca płynnie tam i z powrotem.

  To **co innego niż „Powtarzaj przy każdym wejściu"**, które odtwarza animację
  dopiero po powrocie elementu w kadr. Nazwy są rozłączne, żeby nie było
  wątpliwości, które pole robi co.

  Przy `prefers-reduced-motion` pętla **nie startuje wcale** — ruch ciągły jest
  dokładnie tym, czego ta preferencja dotyczy. Treść zostaje widoczna w stanie
  końcowym, jak przy pozostałych animacjach.

  Uwaga o sekwencji startowej: zapętlona pozycja **wypada z rachunku kroków**
  zamiast go zatrzymywać. Nieskończona animacja nie ma końca, na który dałoby
  się czekać, więc kolejny krok rusza natychmiast. Wewnętrznie zapętlone
  pozycje dostają własną oś czasu — wpuszczenie ich do wspólnej dałoby jej
  czas trwania 1e10 (wartownik nieskończoności GSAP-a) i cała reszta sekwencji
  stanęłaby po cichu na zawsze.

## [1.38.0] — 2026-08-06

### Dodane

- **Biblioteka animacji zapisuje się bez przeładowania strony.** Przycisk
  „Zapisz bibliotekę animacji" wysyła teraz formularz przez AJAX — strona nie
  skacze, a pozycja przewinięcia i rozwinięte wiersze zostają na miejscu.
  Plakietki `.evk-anim-{slug}` w nagłówkach odświeżają się po zapisie.

  **Sanityzacja jest wspólna z dotychczasową drogą** — endpoint woła tę samą
  metodę, którą wywołuje `options.php`. Drugi zestaw reguł czyszczenia
  rozjechałby się z pierwszym, a różnica wyszłaby dopiero na żywej stronie.
  Pilnuje tego test porównujący obie drogi.

  **Awaria AJAX-a nie blokuje zapisu**: przy błędzie formularz wysyła się
  zwykłą drogą, z przeładowaniem. Przełącznik „włączony" żyje poza formularzem
  i zapis biblioteki go nie gasi.

  Ograniczenie bez zmian wobec dotychczasowego zapisu: `max_input_vars`
  (domyślnie 1000) tnie bardzo duże biblioteki tak samo przy AJAX-ie, jak przy
  zwykłym POST — przy ~20 polach na wiersz limit zaczyna się liczyć od około
  50 wierszy.

### Poprawione

- **Przeciągnięcie wiersza ginęło przy zapisie formularza.** Ładunek szedł jako
  obiekt JavaScriptu, a klucze wyglądające na liczby są w obiekcie porządkowane
  **numerycznie** — `animations` w kolejności `{1, 2, 0}` wracało do `0, 1, 2`
  i przestawienie znikało bez śladu. Pola jadą teraz listą par w kolejności DOM.

- **W żądaniu zapisu były dwa pola `action`.** Formularz ustawień dokłada własne
  `action=update`, które zderzało się z akcją AJAX-a — o routingu decydowało to,
  które wygra przy parsowaniu. Pola formularza ustawień nie są już wysyłane.

## [1.37.1] — 2026-08-06

### Poprawione

- **Przeciąganie wierszy biblioteki animacji w ogóle nie działało.** Skrypt
  panelu deklarował zależność `['jquery']`, a bibliotekę przeciągania brał
  osobnym `wp_enqueue_script` **niżej w tej samej funkcji**. WordPress drukuje
  skrypty w kolejności zgłoszeń, więc `admin.js` lądował pierwszy — w chwili
  jego uruchomienia biblioteki jeszcze nie było. Warunek sprawdzający jej
  obecność połykał to **bez jednego słowa w konsoli**.

  Biblioteka jest teraz **zadeklarowaną zależnością**, a nie kwestią kolejności,
  i stoi w kodzie przed skryptem panelu. Jej brak **krzyczy w konsoli** zamiast
  znikać w warunku — to ta cisza sprawiła, że usterka pojechała dalej.

### Zmienione

- **Przeciąganie idzie na SortableJS, nie na jQuery UI.** Panel używał już
  SortableJS w Białych etykietach i w warstwach OG; wprowadzenie drugiej
  biblioteki było niepotrzebne. Te same klasy `evk-drag-ghost` / `evk-drag-chosen`
  dają teraz ten sam wygląd przeciągania w całym panelu.

- **Podwójny enqueue SortableJS usunięty.** Był zgłaszany dwa razy, w tym raz
  z numerem wersji rozjeżdżającym się z adresem (`1.15.2` przy pliku `1.15.7`).

## [1.37.0] — 2026-08-06

### Dodane

- **Wiersze biblioteki animacji można przeciągać.** Uchwytem jest nagłówek
  wiersza, a nowa kolejność zapisuje się od razu przez AJAX — bez klikania
  „Zapisz bibliotekę animacji".

  Kolejność jest **wyłącznie porządkowa**: silnik czyta bibliotekę po slugu,
  a o sekwencji na stronie decyduje pole „Kolejność" w wierszu. To zmiana dla
  wygody przy dłuższej liście, nie zmiana zachowania strony.

  Zapis **nie może zgubić konfiguracji**. Wiersz dodany przyciskiem i jeszcze
  niezapisany nie ma sluga, więc serwer go pomija; wiersz zapisany, którego nie
  było na przysłanej liście (np. druga otwarta karta), ląduje na końcu zamiast
  wypaść. Zwykły zapis formularza działa po przestawieniu jak dotąd — PHP
  zachowuje kolejność pól z ciała żądania.

## [1.36.0] — 2026-08-06

### Dodane

- **Marquee: kontrolki pauzy poza kadrem.** Przełącznik „Pauzuj poza ekranem"
  (domyślnie włączony, czyli dotychczasowe zachowanie) i „Zapas (px)" z domyślnym
  200. Wyłączenie ma sens tylko wtedy, gdy dwa marquee mają zostać ze sobą
  zsynchronizowane.

### Poprawione

- **Marquee jechało od załadowania strony, nawet stojąc daleko pod ekranem.**
  Pauza reagowała na wyjście z kadru, ale element, w który nigdy nie wjechano,
  takiego sygnału nie dostawał — ScrollTrigger zgłasza **zmianę** stanu, a nie
  stan początkowy. Przy kilku marquee na długiej stronie wszystkie mieliły w tle,
  zanim ktokolwiek je zobaczył. Stan początkowy jest teraz nakładany jawnie.

- **Przewijanie strony poza kadrem nie wykonuje już pracy.** `Observer` łapie
  każde przewinięcie i przy każdym zabijał tweeny oraz tworzył nowy na
  `timeScale` — także gdy marquee stało dawno poza ekranem i było wstrzymane.
  To tam siedziało realne obciążenie procesora, nie w samej wstrzymanej osi czasu.

## [1.35.0] — 2026-08-06

### Poprawione

- **Animator nie zostawia już po sobie śladu w stylu inline.** GSAP kończył
  animację wejścia na `transform: translate(0px, 0px)` i `filter: blur(0px)`.
  To **nie jest** `none`: element pozostawał przez to na stałe blokiem
  zawierającym dla potomków pozycjonowanych absolutnie i osobnym kontekstem
  układania — długo po tym, jak animacja się skończyła. Na zwykłym divie nie
  widać tego wcale, ale wystarczyło przypiąć animację do czegoś, czego układem
  steruje inny skrypt (slider, popup, sticky), żeby zaczęło się psuć bez powodu.

  Po wejściu **bez powtarzania** czyszczone są teraz `transform`, `filter`
  i `clip-path` — tylko te trzy, bo tylko one tworzą blok zawierający, a własne
  `to` wpisane w panelu może celowo zostawiać kolor czy tło. Wygląd bez zmian:
  stan końcowy wejścia to z definicji przesunięcie 0, skala 1 i rozmycie 0.

  Nie dotyczy hovera, kliku, scruba ani powtarzanych wejść — tam stan końcowy
  jest znaczący i czyszczenie zdejmowałoby efekt.

- **Efekt tekstowy odmawia pracy na kontenerze.** `TextPlugin` wpisuje tekst
  przez `innerHTML`, a docelowy tekst brany był z `textContent`. Na elemencie
  z zawartością jedno z drugim dawało katastrofę: treść wszystkich potomków
  sklejała się w jeden ciąg bez odstępów i **zastępowała cały ich znacznik**.
  Zgłoszone na korzeniu slidera — wychodziły z tego wszystkie slajdy sklejone
  w jeden akapit, bez stylów.

  Silnik pomija teraz takie elementy i mówi w konsoli, co zrobić zamiast tego.
  Na pojedynczym nagłówku czy akapicie efekt działa jak dotąd.

## [1.34.0] — 2026-08-06

### Dodane

- **Sześć gotowych palet Wave Background**: Zorza, Zachód, Ocean, Żar, Mięta
  i Monochrom. Wariantów geometrii element miał już sześć plus w pełni własny —
  brakowało szybkiego wyboru kolorystyki, bo dotąd trzeba było ustawić sześć
  pickerów po kolei, żeby wyjść poza jedno domyślne zestawienie.

  **Domyślnie nic się nie zmienia**: paletą domyślną są „Własne kolory", więc
  tła już wstawione na strony wyglądają dokładnie tak jak dotąd. Po wybraniu
  gotowej palety pola kolorów znikają z panelu — czytanie ich dawałoby wtedy
  kolory, których nikt nie widzi.

## [1.33.0] — 2026-08-06

### Dodane

- **Dwa efekty wskaźnika: przyciąganie i przechył 3D.** Element idzie za
  kursorem albo odchyla się od niego — klasyczny efekt „magnetycznego"
  przycisku, którego dotąd nie było czym zrobić.

  Nie są tweenem od–do, tylko śledzeniem kursora, więc **działają niezależnie
  od wyzwalacza** — pole „Wyzwalacz" nic dla nich nie znaczy. Siłę niesie
  preset; można ją nadpisać na elemencie przez `data-evk-anim`.

  Wychylenie liczone jest w połówkach pudełka, więc ta sama siła znaczy to
  samo dla małego przycisku i dla karty na pół ekranu.

  **Na dotyku efekt się nie włącza.** `pointermove` przychodzi tam dopiero
  przy przeciąganiu palcem, więc element odskakiwałby przy próbie przewinięcia
  strony. Przy `prefers-reduced-motion` element dostaje stan spoczynku i nie
  reaguje na kursor w ogóle.

## [1.32.0] — 2026-08-06

### Dodane

- **Trzy presety tekstowe** na darmowych wtyczkach GSAP: **maszyna do pisania**,
  **losowe znaki** i **zmieniające się słowa**.

  Pracują na treści elementu, więc mają sens wyłącznie tam, gdzie w środku jest
  czysty tekst — znaczniki w środku zostaną zastąpione.

  „Zmieniające się słowa" potrzebują listy w nowym polu **Słowa** w wierszu
  biblioteki (po jednym na linię, maksymalnie 20). Pole „Czas" steruje wtedy
  samym przejściem; każde słowo stoi 1,4 s. Bez listy preset nic nie robi
  i mówi o tym w konsoli.

  `TextPlugin` i `ScrambleTextPlugin` dociągają się **tylko na stronach, gdzie
  któryś wiersz biblioteki faktycznie ich używa** — tak samo jak `SplitText`.

### Zmienione

- **Varsy tweenu składa jedna funkcja zamiast czterech kopii.** Każdy z czterech
  wyzwalaczy budował je u siebie, więc nowa właściwość działałaby tylko w tym,
  o którym ktoś pamiętał. Bez zmian w zachowaniu — przy scrubie nadal decyduje
  scroll, a nie pole „Czas".

## [1.31.0] — 2026-08-06

### Dodane

- **Dziesięć nowych presetów Animatora** — biblioteka rośnie z 20 do 30.

  Wejścia: **Wychylenie**, **Odbicie**, **Wtoczenie**, **Rozmycie z dołu**,
  **Wjazd zza maski** (z lewej i z prawej).

  Stany najechania na istniejącym wyzwalaczu „Hover": **uniesienie**,
  **poświata**, **podkreślenie**, **rysowana ramka**. Stanem spoczynku jest
  tam `from`, a `to` — stanem najechania; oś czasu gra do przodu na wejściu
  wskaźnika i na fokusie, cofa się na wyjściu. Podkreślenie rysuje się
  gradientem tła w `currentColor`, więc bierze kolor tekstu i nie wymaga
  osobnego pola. Ramka idzie cieniem wewnętrznym, nie `border` — `border`
  zmieniałby rozmiar pudełka i przy najechaniu przesuwał sąsiadów.

- **Preset może narzucić krzywą easingu.** Pole „Easing" w wierszu biblioteki
  ma nową wartość **„— z presetu —"** i to ona jest odtąd domyślna dla nowych
  wierszy. Bez tego „Odbicie" nie odbijało: twarda wartość domyślna wiersza
  zawsze przykrywała krzywą presetu — dokładnie ten sam problem, który wcześniej
  dotyczył czasu i staggera.

  **Wiersze zapisane wcześniej nie zmieniają zachowania** — mają jawnie
  zapisane `power2.out` i tak zostaje. Żeby wiersz przejął krzywą presetu,
  trzeba w nim wybrać „— z presetu —".

  Do listy easingów doszły `back.out(2.2)` i `bounce.out`.

### Poprawione

- **Redukcja ruchu nie zostawia już przycisku trwale w stanie najechania.**
  Silnik nakładał przy `prefers-reduced-motion` stan `to` każdej animacji, co
  dla wyzwalaczy „Hover" i „Klik" oznaczało wygląd po najechaniu — na stałe.
  Teraz przy tych dwóch wyzwalaczach element nie jest ruszany wcale: nie ma
  wejścia do dokończenia, a zostawiony tak, jak wyrenderował go CSS, jest na
  pewno widoczny. Dla pozostałych wyzwalaczy bez zmian — stan końcowy nadal
  jest nakładany od razu.

## [1.30.0] — 2026-08-06

### Dodane

- **Redukcja ruchu obowiązuje w całej wtyczce.** Dotąd `prefers-reduced-motion`
  respektowały **tylko** Animator i Tło przy scrollu. Marquee, Horizontal Scroll,
  Scroll Reading, Circular Title, Circular Menu, Stacking Cards, Wave Background,
  Parallax, Lenis i Kursor animowały niezależnie od preferencji użytkownika.

  Doszedł nowy przełącznik **Dostępność → Ruch na stronie**. Opcja jest osobna od
  ustawień widżetu dostępności, mimo że stoi w tej samej zakładce: widżet bywa
  wyłączony, a polityka ruchu ma obowiązywać zawsze. Dotychczasowa wartość
  z Animatora jest przejmowana, więc nikt nie traci konfiguracji.

  Zasada wspólna dla wszystkich silników: **ruch znika, stan końcowy zostaje**.
  Nic nie może stać się niewidoczne ani niedostępne.

  | Moduł | Przy redukcji ruchu |
  |---|---|
  | Stacking Cards | zwykły przepływ — ta sama ścieżka co poniżej breakpointu |
  | Marquee | treść stoi, pętla nie rusza |
  | Horizontal Scroll | pionowy przepływ zamiast pinu |
  | Scroll Reading | od razu kolor **docelowy**, nie przygaszony |
  | Circular Title | tekst po okręgu bez obrotu |
  | Circular Menu | otwiera się natychmiast — nawigacja musi zostać dostępna |
  | Wave Background | jedna klatka, bez pętli |
  | Parallax | transform raz, z zerowym przesunięciem; skala zachowana |
  | Lenis | przewijanie natywne |
  | Kursor | własny kursor nie powstaje |

  Polityka żyje w jednym miejscu (`includes/anim/motion.php`) i trafia na front
  jako `window.evkMotion.reduced()`. Każdy silnik ma własny kilkulinijkowy
  fallback pytający wprost `matchMedia`, więc nie zależy od kolejności ładowania
  skryptów — a przy braku helpera domyślnie **szanuje** preferencję.

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
