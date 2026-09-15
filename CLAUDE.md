# Evoke ONE — co wiedzieć przed dotknięciem kodu

Ten plik zbiera rzeczy, które **kosztowały już czas** — pułapki, w które wpada
się raz, potem drugi raz, bo wiedza o nich siedziała wyłącznie w komentarzu
wewnątrz zepsutego pliku. Czyli tam, gdzie zagląda się PO awarii.

Nie powtarza dokumentacji z plików źródłowych — te komentarze są obszerne
i mają zostać. Tu jest tylko to, co trzeba wiedzieć, ZANIM się coś napisze.

---

## Pułapki składniowe w kodzie generowanym

Elementy Bricks drukują swój JavaScript z PHP-a, a shadery siedzą w literałach
szablonowych JS-a wewnątrz łańcuchów PHP-a. Znak użyty w **komentarzu** potrafi
przez to wywrócić cały moduł.

| Plik | Czego NIE wolno | Co się dzieje |
|---|---|---|
| `includes/bricks-elements/evoke-wave-bg/element.php`, shadery | **odwrotnego apostrofu** (`` ` ``) nigdzie, komentarze włącznie | zamyka literał szablonowy; moduł nie parsuje się wcale |
| `includes/96-lenis.php`, blok JS | prostego cudzysłowa (`"`) ani `%` | blok jest w `sprintf` na łańcuchu w cudzysłowach — `%` zjada `sprintf`, `"` kończy łańcuch |

**Z przeglądarki widać wyłącznie objaw** („element nie wystartował"), nigdy
przyczynę. Dlatego `tests/wave-bg.test.js` ma sekcję *„moduł elementu parsuje
się jako JavaScript"* — wyciąga moduł z prawdziwego `render()` i przepuszcza
przez parser Node'a, bez stawiania przeglądarki. Ta sekcja odpowiada w sekundy;
nie czekaj z tym na pełny przebieg.

Do cytowania nazw w komentarzach używaj polskich cudzysłowów „…" — są bezpieczne
w obu kontekstach.

---

## Testy: co kosztuje, a co jest darmowe

```
node tests/run.js                 wszystko, ~12 min
node tests/run.js schema          czysty PHP, ~1,3 s — praktycznie darmowe
node tests/run.js wave-bg         ~5 min (fixtury czekają setki razy po 450 ms)
node tests/run.js offcanvas       ~2 min
vendor/bin/phpstan analyse        ~8 s
```

**Filtr dopasowuje FRAGMENT NAZWY PLIKU, nie sekcji.** `t.section()` to
wyłącznie etykieta drukowana na konsolę — praca dzieje się liniowo pomiędzy
sekcjami. Nie da się puścić jednej sekcji z pliku; jeśli plik jest za duży, żeby
iterować, właściwym ruchem jest **podzielić go na dwa pliki**, a nie kombinować
z filtrowaniem.

### Jeden przebieg, wiele odczytów

Przebieg idzie **do pliku**, potem czyta się go dowolną liczbę razy. Puszczenie
tego samego zestawu drugi raz po to, żeby obejrzeć inny fragment wyniku, to
czysta strata — a łatwo w to wpaść, gdy pierwszy `grep` był za wąski.

### Zanim puścisz przeglądarkę

Te odpowiadają w sekundy i łapią większość wpadek:

```
php -l <plik>                                   składnia PHP-a
node --check <plik.js>                          składnia JS-a
node tests/run.js drobiazgi                     wersje, PHPStan, wytwory
EVK_TEST_ROOT=$PWD php tests/php/<sonda>.php     sonda wprost
```

---

## Mierzenie: najpierw zobacz liczby, potem ustaw próg

**To jest najdroższa lekcja w historii tego repozytorium.** Sprawdzenie
z progiem napisane PRZED zobaczeniem wartości to zgadywanie — a każde
zgadywanie kosztuje pełny przebieg.

Trzy kolejne wersje pomiaru ziarna były nietrafione, każda z tego samego powodu
(opis w `CHANGELOG.md` przy 1.192.0):

1. mierzyła narożniki przy założeniu, że fala tam nie sięga — sięga;
2. mierzyła cały kadr, więc efekt był rozcieńczony obszarem, gdzie ziarno i tak
   było;
3. porównywała z zerem bezwzględnym, opisując stan, którego nigdy nie było.

Kolejność, która działa: **pomiar wypisujący wartości → obejrzenie ich →
próg wokół tego, co widać → mutacja**. Nigdy odwrotnie.

Dwie rzeczy, które trzeba wiedzieć, mierząc falę:

- **Drabina jakości musi być wyłączona** (`auto_jakosc: 'nie'`). `rysujRaz()`
  woła `composer.render()` wyłącznie na poziomie zerowym, a ziarno,
  zniekształcenie i reakcja na mysz siedzą właśnie w composerze. Chromium
  w testach rasteryzuje programowo i schodzi o trzy szczeble w kilka sekund —
  bez wyłączenia drabiny mierzy się scenę bez tego, co się bada.
- **Porównania muszą być statystyczne, nie bajtowe.** `uNoiseSeed` losuje się co
  klatkę, więc dwa zrzuty tego samego ustawienia nigdy nie są identyczne.

---

## Mutacja jest częścią sprawdzenia, nie formalnością

Po dopisaniu sprawdzenia zepsuj kod naumyślnie i potwierdź, że **to sprawdzenie
i tylko ono** zapala. Mutacja przechodząca na zielono kończy się przepisaniem
sprawdzenia albo uproszczeniem kodu — nie zaliczeniem.

Zepsutej wersji **nigdy nie commituj**: gałąź jedzie aktualizatorem na żywe
strony.

Trzy rzeczy, które robią z tego rutynę zamiast pola minowego:

- **Kopia i `trap EXIT`.** Skrypt mutacyjny odkłada dobrą wersję obok
  i przywraca ją w `trap`, więc nawet przerwany przebieg nie zostawia zepsutego
  pliku w drzewie.
- **Kilka mutacji w JEDNYM skrypcie w tle**, z przywróceniem między nimi.
  Pilnowanie tego ręcznie przez kolejne tury kosztuje więcej niż sam przebieg.
- **Różnicowanie.** Gdy sprawdzeń jest kilka, mutacje mają zapalać RÓŻNE ich
  podzbiory. Dwie mutacje gasnące na tym samym zestawie nie dowodzą, że
  sprawdzenia mierzą różne rzeczy.

---

## Polecenia powłoki, które strzelają we własną stopę

```
pkill -f "tests/run.js"          ← ZABIJA WŁASNĄ POWŁOKĘ (wzorzec pasuje do
                                    jej wiersza poleceń); kod wyjścia 143/144
```

Zamiast tego:

```
ps -eo pid,args | grep "[t]ests/run.js" | awk '{print $1}' | while read P; do kill $P; done
```

Kontener usypia między turami, więc **długie przebiegi w tle stają**. Puszczaj
partiami do ~600 s i czekaj na nie jawnie.

---

## Wydania

- Numer wersji w **trzech miejscach**: `evoke-one.php` (nagłówek + stała)
  i `CHANGELOG.md`. Pilnuje tego `tests/drobiazgi.test.js`.
- Każdy commit to wydanie. Wyjątkiem są commity wyraźnie oznaczone
  *„(bez wydania)"* — praca w toku, której przebieg jeszcze nie potwierdził.
- Gałąź robocza jedzie na żywe strony aktualizatorem, razem z katalogiem
  `tests/`. Dlatego **każdy plik w `tests/php/` zaczyna się od**
  `if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }` — bez tego byłby
  osiągalny przez HTTP.

## Wytwory, które muszą nadążać za źródłem

| Wytwór | Buduje | Pilnuje |
|---|---|---|
| `assets/js/animator.min.js` | `node tools/minifikuj.js` | `animator.test.js` |

Cichy rozjazd wytworu ze źródłem wygląda zupełnie normalnie — stąd strażnicy.

---

## Zasady pracy, które obowiązują

- Rozmowa **po polsku**, do zgłaszającego w liczbie pojedynczej.
- **Pytać jak najwięcej** o szczegóły.
- **Żadnej optymalizacji bez pomiaru.**
- Cel projektu: **mniej wtyczek zewnętrznych.**
- Czego NIE da się sprawdzić na tej maszynie: **Bricks**. Nie ma go tu, a atrapa
  w `tests/php/_bricks-stubs.php` mówi o sobie wprost, że nie odpowiada na
  pytania „czy Bricks przyjmie ten typ kontrolki". Wnioski o zachowaniu
  buildera wymagają dowodu ze strony, nie rozumowania.
