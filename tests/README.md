# Testy Evoke ONE

```
npm install          # raz — playwright-core i gsap
npm test             # wszystko
node tests/run.js bg motion     # tylko pasujące nazwą
```

Kod wyjścia 1 przy pierwszym błędzie, więc nadaje się pod CI.

Testy potrzebują Chromium. Kolejność szukania: zmienna `EVK_CHROMIUM`, katalog
z `PLAYWRIGHT_BROWSERS_PATH`, `~/.cache/ms-playwright`, typowe lokalizacje
systemowe. Gdy nic nie pasuje, dostajesz komunikat z instrukcją, a nie ślepy
wyjątek.

```
EVK_CHROMIUM=/usr/bin/chromium npm test
```

## Dlaczego przeglądarka, a nie testy jednostkowe

Usterki, które te testy łapią, to geometria `position: sticky`, kolory
w trakcie przejść CSS, pozycje na osi czasu GSAP i drzewo dostępności.
Żadnej z nich nie widać na atrapach — wszystkie wyszły dopiero z pomiaru
w prawdziwym silniku układu.

## Dwie zasady, obie okupione znalezionymi błędami

**CSS i JS czytamy z plików wtyczki, nigdy z kopii w teście.** Kopia zaczyna żyć
własnym życiem i test przestaje cokolwiek sprawdzać. Fragmenty generowane przez
PHP bierzemy z PHP (`tests/php/*.php` + `phpOutput()`), a nie regexem ze źródła —
regex sprawdzałby naszą interpretację pliku, nie jego wynik.

**Strony otwieramy przez `goto` na pliku, nigdy przez `setContent`.** Silniki
startują na `DOMContentLoaded`; przy `setContent` zdarzenie wypala, zanim
dołożymy skrypty, i silnik nie rusza wcale. Test wygląda wtedy na zielony, bo
„nic się nie animuje” jest właśnie tym, czego często oczekujemy. **Dlatego każdy
blok o redukcji ruchu ma parę: przypadek badany i kontrolę negatywną.** Bez
kontroli nieuruchomiony silnik jest nie do odróżnienia od wyłączonego.

Trzecia, mniejsza: **kolory porównujemy z tolerancją**, bo przeglądarka
zaokrągla kanały przy interpolacji.

## Co jest pokryte i czego broni

| Plik | Broni regresji |
|---|---|
| `animator.test.js` | opóźnienia w kolejce startowej sumowały się z czasami poprzednich animacji — `'+='` liczy się w GSAP od końca osi (1.28.1) |
| `stacking-cards.test.js` | sticky zwalnia karty w kolejności odwrotnej do `top`; `padding-bottom` nie przedłuża przyklejenia; nasłuch `resize` wołający `refresh()` zacinał scroll na telefonie (1.27.3, 1.28.0) |
| `aria.test.js` | `SplitText` z domyślnym `aria` odbierał nazwy nagłówkom i odnośnikom w kontenerze nestable — ale dla pojedynczego nagłówka jego zachowanie jest poprawne i ma zostać (1.28.1) |
| `bg-shift.test.js` | `getComputedStyle` w trakcie trwającego `transition` zwraca wartość animowaną, więc warstwa zostawała o jeden motyw w tyle (1.29.2) |
| `motion.test.js` | dziesięć silników ignorowało `prefers-reduced-motion`; ruch ma zniknąć, ale stan końcowy ma zostać widoczny (1.30.0) |
| `controls.test.js` | atrybuty zapisywane płasko nie docierały na stronę; „Kolejność” równa zero jest znacząca i nie może wypaść jak pusta; trójstanowe przełączniki wysyłają `'0'`, które dla `!empty()` jest puste — jawne „Nie" cicho wracałoby do wartości z biblioteki (1.24.0, 1.28.1, 1.40.0) |
| `presets.test.js` | literówka w tablicy presetów nie wywala niczego głośno — po prostu cicho nie działa; easing z presetu był przykrywany domyślną wartością wiersza; stan najechania nałożony przy redukcji ruchu zostawiał przycisk trwale uniesiony (1.31.0–1.33.0) |
| `admin-style.test.js` | checkbox w etykiecie flex bez `flex-shrink: 0` ściska się w poziomie i przestaje być kwadratem; skóra panelu nie może dryfować — mierzone są akcent, promienie, tła i jednolita wysokość kontrolki; symetria paska zwiniętego wiersza, strzałka listy rozwijanej i wspólna dolna krawędź pól i checkboxów w wierszu siatki (1.41.0, 1.43.0, 1.43.1) |
| `settings-save.test.js` | zapis AJAX-em musi dać wynik IDENTYCZNY z options.php, bo to options.php jest drogą zapasową; brak pola w żądaniu ZNACZY „wyłącz" — odznaczony samotny checkbox nie przysyła nic i przy „pomiń" nie dałoby się go wyłączyć; nazwa nonce'a musi zgadzać się z `settings_fields()` (1.46.0) |
| `inbox.test.js` | wejście na stronę otwierało pierwszą wiadomość, a otwarcie oznacza ją jako przeczytaną — zgłoszenia gasły, zanim ktokolwiek je przeczytał; poniżej 782 px lista o stałych 300 px zjadała pół ekranu i nie było wyjścia z otwartej wiadomości (1.46.0) |
| `anim-preview.test.js` | podgląd, który pokazuje co innego niż strona, jest gorszy niż jego brak — dlatego varsy podglądu porównywane są z tablicą presetów z PHP, a oba parsery `opacity: 0` (PHP i JS) muszą dawać ten sam wynik; SplitText przebudowuje DOM i wtyczki tekstowe nadpisują `innerHTML`, więc scena musi być przywracana przez SILNIK, nie przez wołającego (1.47.0) |
| `admin-tabs.test.js` | atrybut `style=` przy kontrolce wygrywa z każdym arkuszem — skóra Fields miała regułę dla pola i nie robiła nic, bo pole niosło własne `border-radius:5px`; literał koloru w inline wygląda jak token, ale przestaje za nim nadążać przy zmianie palety (1.44.0) |
| `admin-panel.test.js` | skrypt panelu deklarował zależność `['jquery']`, a bibliotekę przeciągania brał osobnym enqueue niżej — WordPress drukował admin.js pierwszy i biblioteki jeszcze nie było; cichy warunek połykał to bez śladu w konsoli (1.37.1) |
| `anim-order.test.js` | przestawienie wierszy w panelu nie może zgubić konfiguracji — ani wiersza świeżo dodanego i niezapisanego, ani takiego, którego nie było na przysłanej liście; zapis przez AJAX musi dawać wynik identyczny z zapisem przez `options.php` (1.37.0, 1.38.0) |
| `loop.test.js` | zapętlona pozycja wpuszczona do wspólnej osi kolejki startowej daje rodzicowi czas trwania 1e10 — kolejny krok sekwencji ruszyłby po dziesięciu miliardach sekund, czyli nigdy (1.39.0) |
| `marquee.test.js` | pętla jechała od załadowania strony, choć marquee stało 2400 px niżej — ScrollTrigger zgłasza zmianę stanu, a nie stan początkowy; Observer reagował na każde przewinięcie także poza kadrem (1.36.0) |
| `splide.test.js` | animator zostawiał po sobie inline `transform: translate(0px,0px)` i `filter: blur(0px)` — to nie jest `none`, więc element na stałe zostawał blokiem zawierającym dla potomków pozycjonowanych absolutnie; efekt tekstowy na kontenerze spłaszczał całą jego zawartość do jednego węzła tekstowego (1.35.0) |
| `wave-bg.test.js` | gotowa paleta jako domyślna przemalowałaby wszystkie tła już wstawione na strony; paleta z niepełnym zestawem zostawia w gradiencie czerń (1.34.0) |

## Układ

```
tests/
  run.js              runner — zbiera *.test.js, kod wyjścia 1 przy błędzie
  lib/harness.js      przeglądarka, asercje, ścieżka do Chromium, wyjście z PHP
  fixtures/*.html     strony ładowane przez goto; ścieżki względne do korzenia
  php/_wp-stubs.php   atrapy WordPressa — tyle, ile potrzebują testowane pliki
  php/tab.php         renderer dowolnej zakładki panelu (prawdziwe moduły)
  php/*.php           generatory wyjścia PHP (nagłówki, atrybuty)
```

## Dopisywanie testu

Plik `tests/nazwa.test.js` eksportujący `async function (t)`. Do dyspozycji
`t.section(tytuł)`, `t.check(nazwa, warunek, szczegół)` i `t.open(fixture, opcje)`
— opcje to `viewport`, `reduce`, `query`, `head`, `settle`. Runner znajdzie
plik sam.

Po napisaniu testu **zepsuj celowo kod, który ma chronić, i sprawdź, że
świeci na czerwono**. Test, którego nigdy nie widziało się czerwonego, nie ma
dowodu, że cokolwiek sprawdza.
