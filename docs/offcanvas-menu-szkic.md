# Offcanvas Menu — projekt nowego elementu Bricks

Dokument projektowy, **przed kodem**. Powstał z dwóch dem podesłanych jako
wzorzec:

* `nextbricks.io/offcanvas-menu-demo` — jeden panel, treść swobodna
  (sekcje „Socials", „Menu", „Get in touch");
* `nextbricks.io/multioffcanvas-menu-demo` — hierarchia, panel na poziom
  („What we do", „Projects", „Company", „Socials", każdy z własnym zamknięciem).

---

## ZAŁOŻENIA DO POTWIERDZENIA

Pytania zadałem dwukrotnie i nie dostałem odpowiedzi, więc przyjąłem warianty,
które sam poleciłem. **Każde z tych czterech da się odwrócić jednym zdaniem**,
ale im później, tym drożej — najtańszy moment jest przed pierwszą linią kodu.

| # | Założenie | Co znaczy odwrócenie |
|---|---|---|
| 1 | **Jeden element z przełącznikiem trybu**: „swobodny panel" albo „poziomy" | Dwa osobne elementy = wspólna mechanika w dwóch kopiach |
| 2 | **Poziomy z menu WordPressa** (`wp_nav_menu`) | Panele składane w builderze = pełna swoboda, ręczna robota przy każdej podstronie |
| 3 | **Rodzic zostaje widoczny za spodem, przesunięty** | Rodzic wyjeżdża całkiem albo akordeon w miejscu |
| 4 | **Trigger wbudowany ORAZ selektor** | Tylko jedno z dwóch |

Uzasadnienie założenia 1, bo jest najważniejsze: **oba dema dzielą całą trudną
część.** Wysuwanie, warstwa przyciemniająca, blokada przewijania strony,
pułapka fokusu, zamykanie Esc, powrót fokusu na trigger, obsługa redukcji ruchu
— to jest ~80% pracy i w obu trybach identyczne. Różni je wyłącznie to, co
siedzi w panelu. Dwa elementy znaczyłyby dwie kopie tej mechaniki i poprawkę
blokady przewijania trzeba by robić dwa razy.

---

## Miejsce w istniejącym układzie

Wtyczka ma siedem elementów i ustalony wzorzec — nowy wchodzi tą samą drogą,
nic tu nie trzeba wymyślać:

* katalog `includes/bricks-elements/evoke-offcanvas-menu/` z `element.php`
  i `assets/offcanvas-menu.{css,js}` (dokładnie jak `evoke-circular-menu`);
* wpis w `evk_elements_registry()` (`loader.php:29`) — etykieta, ikona, klasa,
  nazwa, stałe wersji, `script` i `style`. Loader rejestruje handle sam
  (`:198-203`), a osobny włącznik pojawia się w zakładce Elementy
  automatycznie (`includes/admin/tab-elementy.php`);
* **etykieta w rejestrze i `get_label()` w klasie muszą być identyczne** —
  loader mówi o tym wprost i to jedyna rzecz, którą trzeba wpisać dwa razy;
* `public $nestable = true` i `get_nestable_children()` na miejsca składane
  w builderze;
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
    └── .evk-oc-stack       stos paneli
        ├── .evk-oc-panel[data-level="0"]
        ├── .evk-oc-panel[data-level="1"]
        └── …
```

**Tryb „swobodny panel"** — jeden `.evk-oc-panel` na poziomie 0, w środku
dziecko nestable. Wszystko, co widać w drugim demie (kolumny, ikony
społecznościowe, adres e-mail), składasz w builderze.

**Tryb „poziomy"** — panele generowane z menu WP. Pozycja z dziećmi dostaje
`<button aria-expanded aria-controls>` zamiast gołego odnośnika; pozycja bez
dzieci zostaje odnośnikiem. Nad listą i pod nią zostają dwa **puste miejsca
nestable** — to stamtąd wezmą się „Socials" i „Get in touch" z drugiego dema,
więc oba wzorce dają się złożyć bez trzeciego trybu.

### Warstwy

Głębokość jako zmienna CSS na stosie: `--evk-oc-depth`. Panel na poziomie `n`
przy głębokości `d` przesuwa się o `(d − n) × var(--evk-oc-offset, 48px)`
i przygasa. Wszystko jedną regułą, bez klas na panel.

Wynikają z tego dwie rzeczy, które trzeba zrobić świadomie:

* **Powrót kliknięciem w widoczną krawędź rodzica.** To główny zysk z warstw
  i jednocześnie pułapka: panel pod spodem jest widoczny, więc bez `inert`
  jego odnośniki zostają w kolejności tabulacji i klikalne. Panele poniżej
  bieżącej głębokości dostają `inert` **i** obsługę kliknięcia „wróć tutaj".
* **Wąski ekran.** Przy trzech poziomach i przesunięciu 48 px na telefonie
  zostaje niewiele miejsca. Poniżej ~600 px przesunięcie schodzi do ~16 px
  albo do zera — do rozstrzygnięcia pomiarem, nie na oko.

### Zamykanie i klawiatura

* **Esc** — cofa o JEDEN poziom, a na poziomie zerowym zamyka. Zamykanie
  z trzeciego poziomu od razu gubi kontekst.
* **Fokus** zamknięty w bieżącym panelu; przy wejściu w podmenu przechodzi na
  pierwszy element nowego panelu, przy powrocie **wraca na pozycję, z której
  się weszło** — nie na początek listy.
* Po zamknięciu fokus wraca na trigger.
* `aria-expanded` na triggerze, `aria-modal` i `role="dialog"` na korzeniu.

Repo ma na to test (`tests/aria.test.js`) i wie, że domyślne ustawienia
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
| `menu` | select | menu WP (tylko w trybie „poziomy") |
| `side` | select | lewo / prawo / góra / dół |
| `width` | number+unit | szerokość panelu |
| `offset` | number | przesunięcie warstw |
| `duration` | number | czas wysuwania |
| `easing` | select | z `evk_anim_easings()` — ta sama lista, co w Animatorze |
| `triggerSelector` | text | dodatkowy trigger poza elementem |
| `closeOnLinkClick` | checkbox | zamknij po kliknięciu w odnośnik |
| `toBody` | checkbox | przenieś do `<body>` (jak w Circular Menu) |
| `openInBuilder` | checkbox | trzymaj otwarte podczas edycji |

Easingi biorę z `evk_anim_easings()`, a nie z własnej listy: jedna lista dla
całej wtyczki znaczy, że dorzucenie krzywej działa wszędzie naraz. Tak właśnie
zadziałało dopisanie `power2.in` przy animacjach wyjścia w 1.51.0.

---

## Co sprawdzić testem

Harness renderuje elementy Bricks przez atrapy (`tests/php/`), a zachowanie
mierzy w przeglądarce. Dla tego elementu:

1. **Głębokość steruje przesunięciem** — po wejściu o dwa poziomy panel zerowy
   stoi dwa razy dalej niż pierwszy. Bez tego „warstwy" są słowem, nie stanem.
2. **Panele pod spodem są `inert`** — odnośnik z panelu 0 przy otwartym
   panelu 1 nie łapie fokusu tabulatorem. To sprawdzenie, którego nie da się
   zrobić na oko, bo wizualnie wszystko wygląda dobrze.
3. **Esc cofa o jeden, na zerowym zamyka.**
4. **Fokus wraca na pozycję, z której się weszło**, nie na początek listy.
5. **Blokada przewijania nie przesuwa układu** — szerokość `<body>` przed
   otwarciem i po otwarciu ta sama.
6. **Redukcja ruchu: panel widoczny, bez ruchu** + kontrola negatywna.
7. **Wąski ekran** — 390 i 360 px: nic nie wystaje poza okno i nic nie jest
   ucięte przez kontener (`__overflow` i `__clipped` z `admin-tabs`).
8. **Tryb „swobodny panel" nie generuje niczego z menu WP** — a tryb
   „poziomy" nie gubi bloków nestable nad listą i pod nią.

Każdy z tych bloków ma być **zobaczony na czerwono** po celowym zepsuciu
chronionej reguły.

---

## Czego szkic świadomie nie obejmuje

Przewijania wewnątrz panelu przy bardzo długich listach (na razie zwykłe
`overflow-y: auto`), zagnieżdżenia głębszego niż trzy poziomy oraz animacji
pojedynczych pozycji listy przy wjeździe panelu. To trzy osobne decyzje,
z których żadna nie blokuje pierwszej wersji.
