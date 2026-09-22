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

### Wąsko podczas pracy, PEŁNY przed pushem

Puszczanie wszystkiego przy każdej iteracji to strata — i o to poszło zgłoszenie
o „dużej ilości niepotrzebnych przebiegów". Ale wąski filtr przed **wypchnięciem
gałęzi** to co innego: gałąź jedzie aktualizatorem na żywe strony.

Reguła: **wąsko, dopóki się iteruje — pełny przebieg przed `git push`.**

Wpadka, która to napisała (1.199.0–1.202.0): cztery wydania poszły na podstawie
przebiegów z `controls`, `drobiazgi`, `grain`, `wave-bg` i `bricks-required`.
Jedno z nich dopisało `require_once` do `loader.php`, czyli do pliku ładującego
WSZYSTKIE dziesięć elementów, a dwa kolejne ruszyły sondy PHP-owe współdzielone
z innymi zestawami. Sprawdzenia burgera, circular-menu, offcanvas, marquee,
stacking-cards i całego panelu nie widziały tych zmian ani razu. Wyszło na
zielono, ale to był łut szczęścia, nie wynik.

Pełny przebieg idzie **partiami po ~600 s**, bo kontener usypia między turami.
Podział, który się mieści (67 plików, cztery partie):

```
node tests/run.js admin- anim animator aria backup- bg-shift bricks-required builder-context burger circular-menu controls
node tests/run.js darkmode drobiazgi grain hscroll inbox konserwacja kursor loop marquee motion
node tests/run.js newsletter odpornosc odswiezanie offcanvas og-layers panel-start parallax potwierdzenie presets przeglad-sekcji przelaczniki rewizje
node tests/run.js schema-graf scroll-lock seo-meta settings-save sierotki sitemap snippety splide stacking-cards svg theme-color tl- uprawnienia vendor-libs wave-bg
```

**Że partie pokrywają wszystko, trzeba SPRAWDZIĆ, a nie założyć** — dopisany
plik testowy nie pasujący do żadnego filtra nie zgłosi się sam, a przebieg
wypisze wtedy „wszystko przeszło" o zbiorze bez niego. Jedno polecenie, ta sama
lista filtrów co wyżej:

```
FILTRY="admin- anim animator aria backup- bg-shift bricks-required builder-context
burger circular-menu controls darkmode drobiazgi grain hscroll inbox
konserwacja kursor loop marquee motion newsletter odpornosc odswiezanie
offcanvas og-layers panel-start parallax potwierdzenie presets przeglad-sekcji
przelaczniki rewizje schema-graf scroll-lock seo-meta settings-save sierotki
sitemap snippety splide stacking-cards svg theme-color tl- uprawnienia vendor-libs
wave-bg"

ls tests/*.test.js | sed 's|tests/||;s|\.test\.js||' | while read -r P; do
  for F in $FILTRY; do case "$P" in *"$F"*) continue 2;; esac; done
  echo "POMINIĘTY: $P"
done
```

### Jeden przebieg, wiele odczytów

Przebieg idzie **do pliku**, potem czyta się go dowolną liczbę razy. Puszczenie
tego samego zestawu drugi raz po to, żeby obejrzeć inny fragment wyniku, to
czysta strata — a łatwo w to wpaść, gdy pierwszy `grep` był za wąski.

### PHPStan, gdy `composer install` odmawia

W kontenerze sesji zdalnej composer pada na `Could not authenticate against
github.com` — dist-y paczek idą przez `api.github.com`, a tamten adres przez
proxy oddaje 403. **`git` przez to samo proxy działa**, więc narzędzia zaciąga
się wprost z repozytoriów (obie paczki trzymają gotowe wytwory w gicie):

```
git clone --depth 1 --branch 2.1.17 https://github.com/phpstan/phpstan.git vendor/phpstan/phpstan
git clone --depth 1 https://github.com/php-stubs/wordpress-stubs.git vendor/php-stubs/wordpress-stubs
mkdir -p vendor/bin && ln -sf ../phpstan/phpstan/phpstan.phar vendor/bin/phpstan
vendor/bin/phpstan analyse --no-progress
```

Bez tego sekcja „analiza statyczna" w `drobiazgi` świeci na czerwono przez CAŁĄ
sesję, a analiza nie przechodzi ani razu — i tak właśnie przejechało kilka wydań
z łańcucha mapy strony, zanim ktoś powiedział, że PHPStan da się uruchomić.

### Testy kopii zapasowych potrzebują prawdziwej bazy

`backup-baza` (i kolejne testy modułu kopii) gada z prawdziwym MariaDB/MySQL
przez prawdziwego WordPressa — atrapa `$wpdb` nie odpowie na pytania o
`SHOW CREATE TABLE`, kolację przy stronicowaniu ani o to, co baza oddaje
z kolumny BLOB. Środowisko stawia jedno polecenie:

```
tools/testowy-wp.sh              postaw albo sprawdź (~15 s za pierwszym razem)
tools/testowy-wp.sh --od-nowa    wyczyść bazę i katalog, postaw jeszcze raz
```

W kontenerze sesji zdalnej serwera bazy nie ma — raz na sesję
`apt-get install -y mariadb-server`, dalej skrypt sam go uruchamia. WordPress
ląduje w `~/.cache/evk-testowy-wp` (zmienna `EVK_WP_PATH`), wtyczka jest do
niego DOWIĄZANA, więc testy widzą bieżący kod.

Brak środowiska **zapala test na czerwono** z instrukcją, a nie pomija go po
cichu — ta sama umowa co przy PHPStanie w `drobiazgi`.

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
