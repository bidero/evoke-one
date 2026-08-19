# Offcanvas Menu — projekt nowego elementu Bricks

Dokument projektowy, **przed kodem**. Powstał z dwóch dem podesłanych jako
wzorzec:

* `nextbricks.io/offcanvas-menu-demo` — jeden panel, treść swobodna
  (sekcje „Socials", „Menu", „Get in touch");
* `nextbricks.io/multioffcanvas-menu-demo` — hierarchia, panel na poziom
  („What we do", „Projects", „Company", „Socials", każdy z własnym zamknięciem).

---

## Rozstrzygnięcia

Wszystkie cztery potwierdzone przez użytkownika. **Dwa wyszły inaczej, niż
zakładałem** — pierwsza wersja tego dokumentu miała menu WordPressa i warstwy
widoczne pod spodem, i obie te rzeczy zostały odwrócone.

| # | Decyzja |
|---|---|
| 1 | **Jeden element z przełącznikiem trybu**: „swobodny panel" albo „poziomy" |
| 2 | **Panele składane w builderze**, nie generowane z menu WordPressa |
| 3 | ~~**Rodzic wyjeżdża całkiem**, podmenu zajmuje jego miejsce~~ — **odwrócone w 1.62.0**, patrz niżej |
| 4 | **Trigger wbudowany ORAZ selektor** |

Decyzja 1 jest tu najważniejsza, bo trzyma resztę razem: **oba dema dzielą całą
trudną część.** Wysuwanie, warstwa przyciemniająca, blokada przewijania strony,
pułapka fokusu, zamykanie Esc, powrót fokusu na trigger, obsługa redukcji ruchu
— to jest ~80% pracy i w obu trybach identyczne. Różni je wyłącznie to, co
siedzi w panelu, i czy paneli jest jeden, czy kilka.

Decyzja 2 upraszcza element bardziej, niż widać na pierwszy rzut oka: znika
generowanie znaczników z `wp_nav_menu`, znika mapowanie hierarchii na panele
i znika kontrolka wyboru menu. Zostaje **jeden mechanizm dla obu trybów** —
panele są dziećmi nestable, a różnica między trybami sprowadza się do tego,
ile ich jest i czy istnieją odnośniki przechodzące między nimi.

Decyzja 3 zdejmuje z układu całą arytmetykę głębokości (`--evk-oc-depth`,
przesunięcia per poziom, przygaszanie). Zostaje jedna klasa stanu na panelu.

### Decyzja 3 wróciła — 1.62.0

**„Rodzic wyjeżdża całkiem" okazało się nie tym, o co chodziło.** Wybrane na
początku, wytrzymało trzy rundy poprawek i dopiero czwarta pokazała dlaczego:
zgłoszenia brzmiały „drugi panel zasłania pierwszy" i „nadal nie przepycha
panelu dalej", choć pomiar ze strony pokazywał, że taśma jedzie o pełne
420 px. Rodzic BYŁ wypychany — tyle że natychmiast poza kadr, więc z ekranu
nie dało się tego odróżnić od przykrycia. Ruchu nie widać, jeśli to, co się
rusza, znika w tej samej chwili.

Domyślne jest teraz **poszerzanie kadru**: menu rośnie o szerokość jednego
panelu na poziom, rodzic przesuwa się w lewo i zostaje na ekranie. Wzór
z nextbricks robi dokładnie to. Stare zachowanie zostaje pod kontrolką
„Wejście w podmenu" — kosztuje jedną gałąź w `applyState()`.

Wraca przez to część arytmetyki, której decyzja 3 miała nie być: szerokość
panelu w pikselach (bo „100% kadru" przestaje być stałą, gdy kadr rośnie),
liczba paneli mieszczących się w oknie i kolejność ŚCIEŻKI na taśmie —
ścieżka potrafi przeskakiwać (0 → 2 → 1), a układ z kolejności DOM
pokazałby wtedy panele w złej kolejności albo z dziurą pośrodku.

I jedna rzecz, która przestaje być prawdą razem z tą decyzją: **rodzic
zostaje dostępny tabulatorem**. `inert` na wszystkim poza bieżącym panelem
odcinałby połowę tego, co widać na ekranie, więc pułapka fokusu obejmuje
teraz cały kadr, a `inert` dostają wyłącznie panele spoza ścieżki.

### Drugi efekt otwierania — 1.97.0

Szkic zakładał jeden sposób wjazdu: panel wysuwa się zza krawędzi i **wwozi
treść ze sobą**. Doszedł drugi — **odsłanianie**: treść stoi w oknie od
pierwszej klatki, a przesuwa się sama płaszczyzna panelu.

Nie jest to nowy mechanizm, tylko druga strona tego samego. Kadr jedzie
o własną szerokość, a opakowanie treści (`.evk-oc-hold`) dostaje transformację
dokładnie przeciwną; `overflow: clip` na kadrze robi resztę. Wybór siedzi
w kontrolce `openEffect`, domyślnie `slide`.

Osobny węzeł, a nie taśma, bo taśma ma już właściciela transformu —
`applyState()`. Ta sama zasada, którą decyzja 3 i jej powrót wbijały tu przez
cztery rundy: **jeden stan, jeden właściciel**.

### Wygięta ściana — 1.98.0

Krawędź wjazdu wygina się w trakcie ruchu i prostuje na końcu. Kontrolki:
włącznik i siła (0–1), domyślnie wyłączone.

Niezależne od efektu otwierania: dotyczy kształtu KADRU, a nie tego, co robi
treść, więc składa się z obydwoma. Kształt daje `border-radius` na kadrze —
obcina potomków, bo kadr ma `overflow: clip` od 1.62.0. Dwustopniowy profil
(wybrzuszenie na 66% czasu, potem powrót) wymaga klatek, nie przejścia.

Wzór z nextbricks robi to ścieżką SVG animowaną GSAP-em. Nie powtarzam tego
świadomie: to menu jedzie na przejściach CSS i nie ma zależności od GSAP-a,
a przeniesienie go na oś czasu w JS przepisałoby wszystko, na czym stoją testy.
Cena: łuk eliptyczny zamiast Béziery — bardzo blisko, nie identycznie.

---

## Miejsce w istniejącym układzie

Wtyczka ma siedem elementów i ustalony wzorzec — nowy wchodzi tą samą drogą:

* katalog `includes/bricks-elements/evoke-offcanvas-menu/` z `element.php`
  i `assets/offcanvas-menu.{css,js}` (dokładnie jak `evoke-circular-menu`);
* wpis w `evk_elements_registry()` (`loader.php:29`) — etykieta, ikona, klasa,
  nazwa, stałe wersji, `script` i `style`. Loader rejestruje handle sam
  (`:198-203`), a osobny włącznik pojawia się w zakładce Elementy
  automatycznie (`includes/admin/tab-elementy.php`);
* **etykieta w rejestrze i `get_label()` w klasie muszą być identyczne** —
  loader mówi o tym wprost i to jedyna rzecz wpisywana dwa razy;
* `public $nestable = true` i `get_nestable_children()`;
* `$this->scripts = ['evk_offcanvas_menu_init']` — nazwa funkcji, którą Bricks
  woła po wyrenderowaniu elementu w canvasie.

Wzorcem jest `evoke-circular-menu`: ma trigger i panel jako dzieci, przenosi
panel do `<body>` opcją („nie jest wtedy ograniczany przez `overflow:hidden`
ani `position` rodziców") i przekazuje wartości z buildera zmiennymi CSS
(`--evk-cm-from-top`), które JS odczytuje przez `getComputedStyle`. Wszystkie
trzy chwyty przenoszą się tu bez zmian.

---

## Budowa

```
.evk-oc                     korzeń (zostaje w miejscu wstawienia)
├── .evk-oc-trigger         miejsce na burger — dziecko nestable
└── .evk-oc-root            przenoszone do <body>
    ├── .evk-oc-scrim       przyciemnienie, zamyka kliknięciem
    └── .evk-oc-panels
        ├── .evk-oc-panel[data-panel="0"]   ← startowy
        ├── .evk-oc-panel[data-panel="uslugi"]
        └── …
```

**Tryb „swobodny panel"** — jeden panel, w środku dziecko nestable. Wszystko,
co widać w drugim demie (kolumny, ikony społecznościowe, adres e-mail),
składasz w builderze. Zero logiki przechodzenia.

**Tryb „poziomy"** — paneli jest kilka, każdy to osobne dziecko nestable
z własnym identyfikatorem. Przejście robi dowolny element z atrybutem
`data-evk-oc-go="uslugi"`; powrót — `data-evk-oc-back`. Dzięki temu „pozycja
menu" może być czymkolwiek: odnośnikiem, kafelkiem z obrazkiem, całą siatką.
Element **nie narzuca znaczników pozycji** — to jest cena i zarazem sens
decyzji „składane w builderze".

Panel startowy to pierwszy w kolejności albo wskazany kontrolką.

**Jak otworzyć drugi panel — bez żadnej konfiguracji:** zaznacz przycisk
w panelu startowym, w zakładce Style → Atrybuty dodaj atrybut o nazwie
`data-evk-oc-go` i wartości `1`. Panele bez nazwy liczą się po kolejności,
więc `1` to drugi panel. Powrót: `data-evk-oc-back` (bez wartości).
Zamknięcie: `data-evk-oc-close`. Nazwy zamiast numerów: nadaj panelowi
atrybut `data-panel` i tę samą wartość wpisz w `data-evk-oc-go`.

### Przechodzenie

Rodzic wysuwa się w bok, dziecko wjeżdża na jego miejsce. Jedna klasa stanu
(`.is-current` / `.is-out`), zero arytmetyki głębokości. Stos odwiedzonych
paneli trzymany w JS — potrzebny na „wstecz" i na Esc.

Dwie rzeczy, które trzeba zrobić świadomie, bo wyglądają dobrze i są zepsute:

* **Panel wysunięty poza ekran nadal łapie fokus.** `transform:
  translateX(-100%)` nie usuwa niczego z kolejności tabulacji — odnośniki
  z panelu, którego nie widać, są wciąż osiągalne tabulatorem. Panele inne niż
  bieżący muszą dostać `inert`. Tego nie widać na oko ani na zrzucie ekranu;
  widać dopiero tabulatorem albo testem.
* **Fokus przy powrocie.** Wraca **na pozycję, z której się weszło**, nie na
  początek listy. Bez tego „wstecz" gubi miejsce w menu przy każdym użyciu.

### Zamykanie i klawiatura

* **Esc** — cofa o JEDEN panel, a na startowym zamyka. Zamykanie z trzeciego
  poziomu od razu gubi kontekst.
* **Fokus** zamknięty w bieżącym panelu; przy wejściu w podmenu przechodzi na
  pierwszy element nowego panelu.
* Po zamknięciu fokus wraca na trigger.
* `aria-expanded` na triggerze, `aria-modal` i `role="dialog"` na korzeniu.

Repo ma test dostępności (`tests/aria.test.js`) i wie, że domyślne ustawienia
potrafią odebrać nazwy nagłówkom — nowy element musi tam dojść.

### Blokada przewijania

`overflow: hidden` na `<body>` przesuwa układ o szerokość paska przewijania,
co widać jako skok przy otwarciu. Kompensata paddingiem albo
`scrollbar-gutter: stable` — i **to jest rzecz do zmierzenia testem**, bo
gołym okiem widać ją tylko na desktopie z widocznym paskiem.

### Redukcja ruchu

Wspólna polityka wtyczki: `window.evkMotion.reduced()` (patrz
`includes/anim/motion.php`), z tym samym awaryjnym `matchMedia`, co w silniku
animacji. Przy redukcji panel pojawia się bez wysuwania — ale **pojawia się**.
Do testu obowiązkowo kontrola negatywna, inaczej „nic się nie rusza" jest
nie do odróżnienia od elementu, który się nie uruchomił.

---

## Kontrolki

| Klucz | Typ | Co robi |
|---|---|---|
| `mode` | select | swobodny panel / poziomy |
| `startPanel` | text | identyfikator panelu startowego (puste = pierwszy) |
| `side` | select | lewo / prawo / góra / dół |
| `width` | number+unit | szerokość panelu |
| `duration` | number | czas wysuwania |
| `easing` | select | z `evk_anim_easings()` — ta sama lista, co w Animatorze |
| `triggerSelector` | text | dodatkowy trigger poza elementem |
| `closeOnLinkClick` | checkbox | zamknij po kliknięciu w odnośnik |
| `escGoesBack` | checkbox | Esc cofa zamiast zamykać (domyślnie tak) |
| `toBody` | checkbox | przenieś do `<body>` (jak w Circular Menu) |
| `openInBuilder` | checkbox | trzymaj otwarte podczas edycji |

Easingi biorę z `evk_anim_easings()`, a nie z własnej listy: jedna lista dla
całej wtyczki znaczy, że dorzucenie krzywej działa wszędzie naraz. Tak właśnie
zadziałało dopisanie `power2.in` przy animacjach wyjścia w 1.51.0.

Kontrolki `menu` (wybór menu WP) i `offset` (przesunięcie warstw) **nie
powstają** — pierwsza odpadła z decyzją 2, druga z decyzją 3.

---

## Co sprawdzić testem

Harness renderuje elementy Bricks przez atrapy (`tests/php/`), a zachowanie
mierzy w przeglądarce.

1. **Panele niebieżące są `inert`** — odnośnik z panelu startowego przy
   otwartym podmenu nie łapie fokusu tabulatorem. Sprawdzenie, którego nie da
   się zrobić na oko: wizualnie wszystko wygląda poprawnie.
2. **Esc cofa o jeden, na startowym zamyka.**
3. **Fokus wraca na pozycję, z której się weszło**, nie na początek listy.
4. **Po zamknięciu fokus wraca na trigger.**
5. **Blokada przewijania nie przesuwa układu** — szerokość `<body>` przed
   otwarciem i po otwarciu ta sama.
6. **Redukcja ruchu: panel widoczny, bez ruchu** + kontrola negatywna.
7. **Wąski ekran** — 390 i 360 px: nic nie wystaje poza okno i nic nie jest
   ucięte przez kontener (`__overflow` i `__clipped` z `admin-tabs`).
8. **Tryb „swobodny panel" nie buduje stosu** — brak przycisku „wstecz",
   Esc zamyka od razu.

Każdy z tych bloków ma być **zobaczony na czerwono** po celowym zepsuciu
chronionej reguły.

---

## Czego szkic świadomie nie obejmuje

Przewijania wewnątrz panelu przy bardzo długich listach (na razie zwykłe
`overflow-y: auto`), animacji pojedynczych pozycji przy wjeździe panelu oraz
generowania paneli z menu WordPressa. To ostatnie zostało świadomie odrzucone
przy decyzji 2, ale gdyby kiedyś wróciło, wchodzi jako TRZECI tryb obok dwóch
istniejących, a nie jako przebudowa — panele i tak są tylko kontenerami.
