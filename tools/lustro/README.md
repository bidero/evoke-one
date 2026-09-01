# Lustro żywej strony

Ściąga stronę razem z zasobami, serwuje ją z `localhost` i mierzy w tym samym
headless Chromium, na którym chodzą testy.

Powstało, bo atrapy z `tests/fixtures/` **nie odtworzyły** błędu, który na żywej
stronie był widoczny gołym okiem: ten sam element dostawał punkt startu 3181 albo
4926 — zależnie wyłącznie od tego, gdzie stała strona, gdy leciało odświeżenie
ScrollTriggera. Wszystkie próby zbudowania tego w atrapie (Lenis, `scrub`, `snap`,
przewijanie kółkiem) wychodziły na zielono także na wersji z błędem.

Wniosek na przyszłość: **przy usterce zgłoszonej z żywej strony pierwszy krok to
lustro, nie atrapa.** Trzy wersje z rzędu poprawiałem tu nie to, co trzeba,
właśnie dlatego, że mierzyłem na czymś, co nie odtwarzało warunków.

Przeglądarka w środowisku roboczym nie ma wyjścia na sieć — `curl` ma. Stąd cała
konstrukcja: pobrać `curl`-em, podać z dysku.

## Użycie

```sh
tools/lustro/pobierz.sh "https://przyklad.pl/?haslo=…"   # → tools/lustro/strona/
tools/lustro/serwuj.sh                                    # http://127.0.0.1:8765
node tools/lustro/zmierz.js                               # punkty startu animacji
node tools/lustro/zmierz-start.js                         # czekanie na treść
```

`zmierz.js` wypisuje dla każdego elementu z animacją pod przypiętą sekcją dwie
liczby: przy jakim przewinięciu naprawdę wjechał w kadr i gdzie ScrollTrigger
uważa, że ma zagrać. Różnica to jest dokładnie to, co widać na ekranie.

`zmierz-start.js` odpowiada na zgłoszenie „elementy pojawiają się z opóźnieniem":
podaje, po ilu milisekundach schodzi zasłona Animatora, ile elementów chowała
i kto ją zdjął — silnik czy bezpiecznik czasowy. Dławi przy tym PROCESOR (4×, 6×)
**i** SIEĆ, bo to dwie różne przyczyny: procesor kosztuje przy parsowaniu ~200 KiB
JS-a, a sieć przy jego pobieraniu. Lustro stoi na localhoście, więc bez dławienia
sieci wyszłoby, że pobieranie nic nie kosztuje — a to warunki, w których nikt tej
strony nie ogląda.

Pobrana strona **nie wchodzi do repozytorium** — patrz `.gitignore` obok.
