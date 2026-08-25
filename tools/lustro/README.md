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
node tools/lustro/zmierz.js                               # pomiar
```

`zmierz.js` wypisuje dla każdego elementu z animacją pod przypiętą sekcją dwie
liczby: przy jakim przewinięciu naprawdę wjechał w kadr i gdzie ScrollTrigger
uważa, że ma zagrać. Różnica to jest dokładnie to, co widać na ekranie.

Pobrana strona **nie wchodzi do repozytorium** — patrz `.gitignore` obok.
