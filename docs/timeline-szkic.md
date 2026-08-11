# Wizualna oś czasu w Animatorze — szkic

Dokument projektowy. **Kodu nie ma** i celowo go tu nie ma: przed pisaniem
warto rozstrzygnąć, co ta oś w ogóle pokazuje, bo połowa wyzwalaczy na oś
czasu się nie nadaje.

---

## Po co

Pole **„Kolejność"** jest dziś nieczytelne bez rysunku. Niesie dwupoziomową
strukturę, której nie widać w formularzu:

* **numer** to KROK sekwencji — ten sam numer znaczy „razem", wyższy „dopiero
  po zakończeniu poprzedniego kroku";
* **opóźnienie** liczy się od początku SWOJEGO kroku, nie od wczytania strony.

Podpowiedź przy polu mówi to wprost i mimo to jest to najczęstsze źródło
zdziwienia: przy trzech krokach i kilku opóźnieniach nikt nie policzy w głowie,
kiedy co rusza. Rysunek odpowiada na to jednym spojrzeniem — i przy okazji
pokazuje **dziury**, czyli martwy czas, którego w formularzu nie widać wcale.

---

## Co dokładnie da się narysować

Tylko wyzwalacz **„Load strony"**. To jedyne miejsce, gdzie animacje realnie
składają się we wspólną oś — `runLoadQueue()` w `assets/js/animator.js`.

Matematyka jest w całości policzalna w panelu, bez zgadywania:

```
kolejka posortowana po `order`
krok zmienia się, gdy zmienia się `order`
początek kroku      = koniec kroku poprzedniego
pozycja pozycji     = początek kroku + delay
koniec kroku        = początek kroku + max(delay + duration) w tym kroku
```

To nie jest przybliżenie — dokładnie tak liczy silnik (`runLoadQueue`,
pozycja podawana jako LICZBA BEZWZGLĘDNA, nie `'+='`; ta różnica była usterką
naprawioną w 1.28.1 i test `animator.test.js` jej pilnuje).

**Dwa elementy z tą samą animacją nic nie zmieniają** — wchodzą do kolejki jako
osobne pozycje o identycznych czasach, więc granice kroków są te same. Oś liczona
z samej biblioteki jest więc wierna, a nie „przy założeniu jednego elementu".

### Jedna rzecz, której panel NIE wie

**Stagger.** `tweenVars()` wpuszcza go do GSAP, więc tween z N celami trwa
`duration + stagger·(N−1)`. Panel nie wie, ile elementów na stronie niesie daną
klasę, ani ile mają dzieci. Przy niezerowym staggerze pasek ma więc **koniec
nieoznaczony** — rysowany kreskowaniem od `duration` w prawo, z podpisem
„+ stagger × (liczba celów − 1)". Udawanie konkretnej liczby byłoby gorsze niż
przyznanie się do niewiadomej: użytkownik ustawiałby czasy pod rysunek, który
kłamie.

---

## Co zostaje poza osią — i dlaczego

Każda z tych pozycji jest wypisana **pod** osią, z powodem. Milczące pominięcie
wyglądałoby jak usterka rysunku.

| Wyzwalacz | Dlaczego nie na osi |
|---|---|
| **Scrub** | czasem jest scroll, nie sekundy — oś sekundowa nie ma dla niego jednostki |
| **Wejście w kadr** | każdy element ma własny punkt wyzwolenia, zależny od pozycji na stronie; wspólnej osi nie tworzą |
| **Wyjście z kadru** | jak wyżej |
| **Hover / Klik** | zaczynają się od działania użytkownika, więc nie mają pozycji na osi wczytania |
| **Pętla** | ma WŁASNĄ oś (`gsap.timeline({ repeat: -1 })`, odsunięta o tę samą pozycję) i celowo nie wchodzi do wspólnej — dziecko z `repeat: -1` daje rodzicowi czas trwania 1e10 i kolejny krok ruszyłby po dziesięciu miliardach sekund (usterka z 1.39.0, pilnowana przez `loop.test.js`) |

Pętla jest wyjątkiem częściowym: **ma** pozycję startu, więc rysujemy ją
w osobnym pasie pod osią główną — pasek zaczyna się we właściwym miejscu
i wybiega poza prawą krawędź ze znakiem `∞`. Nie przesuwa granic kroków.

---

## Układ

```
 ┌─ Sekwencja startowa ────────────────────────────────── 2,4 s ─┐
 │  0     0,5      1,0      1,5      2,0                          │
 │  ├───────┼────────┼────────┼────────┼────────                  │
 │                                                                │
 │  KROK 0                                                        │
 │  Nagłówki     ▓▓▓▓▓▓▓▓                                         │
 │  Podtytuł     ····▓▓▓▓▓▓▓▓                                     │
 │  Karty        ········▓▓▓▓▓▓▓▓▒▒▒▒                             │
 │                              ╎                                 │
 │  KROK 1                      ╎← krok 1 startuje tutaj          │
 │  Stopka                      ╎▓▓▓▓▓▓                           │
 │                                                                │
 │  ∞ Tło pulsujące   ▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓→  │
 └────────────────────────────────────────────────────────────────┘
   Poza osią: „Parallax" (scrub), „Menu" (hover), „Sekcje" (wejście w kadr)
```

* `····` — opóźnienie: cienka linia od początku kroku do startu paska.
  To ono pokazuje dziury.
* `▓▓▓▓` — czas trwania.
* `▒▒▒▒` — kreskowanie: niepewny ogon od staggera.
* `╎` — granica kroku, pionowa kreska przez całą wysokość.
* Nagłówek podaje **łączny czas sekwencji** — liczba, której dziś nie ma nigdzie.

### Gdzie i kiedy

Nad biblioteką w zakładce Animator, jako `.evo-box` z nagłówkiem
„Sekwencja startowa". Pokazywana, gdy **co najmniej dwie** pozycje mają
wyzwalacz „Load" — przy jednej nie ma sekwencji, jest pojedyncza animacja
i rysunek byłby ozdobą.

### Skąd dane

Z **żywych wartości pól**, nie z zapisanych ustawień — oś ma się przeliczać
w trakcie pisania, bo w tym jest cała jej wartość. `assets/admin/admin.js` ma
już do tego gotowe `rowConfig($row)`: czyta bieżące wartości wiersza
i zwraca konfigurację w kształcie, którego używa silnik. To samo, co zasila
podgląd animacji od 1.47.0.

Przeliczenie na: `input`/`change` w kontenerze repeatera, dodanie i usunięcie
wiersza, oraz przeciągnięcie (kolejność wierszy jest porządkowa i osi nie
zmienia, ale usunięcie w trakcie przeciągania — tak).

### Dostępność

Rysunek nie może być jedynym nośnikiem. Pod nim **lista tekstowa**:
„Nagłówki — start 0,00 s, trwa 0,80 s (krok 0)". Ta sama treść jako
`aria-label` paska. Bez ruchu i bez animacji samego wykresu — to diagram,
nie efekt.

---

## Czego szkic świadomie NIE obejmuje

**Przeciągania pasków.** Kusi, ale niesie pytania, na które nie ma dziś
odpowiedzi: co robi przeciągnięcie paska poza swój krok (zmienia `order`
czy `delay`?), co ze zmianą długości przy staggerze o nieznanym ogonie, jak
pokazać kolizję dwóch animacji na tym samym elemencie. Wersja do odczytu
rozwiązuje realny problem — nieczytelność pola „Kolejność" — i można ją wydać
osobno. Edycję warto zaprojektować dopiero wtedy, gdy będzie wiadomo, czy
rysunek w ogóle jest używany.

## Co sprawdzić testem, gdy dojdzie do kodu

1. **Pozycje z rysunku zgadzają się z silnikiem.** Nie „wyglądają dobrze" —
   dosłownie: ta sama biblioteka przepuszczona przez `runLoadQueue()`
   w przeglądarce ma dawać te same momenty startu, co arytmetyka panelu.
   To jedyne sprawdzenie, które łapie ROZJAZD, a nie awarię; dokładnie ta sama
   zasada, na której stoi test podglądu animacji.
2. **Zero jest znaczące** — `order: 0` i `delay: 0` nie mogą wypaść jak puste.
3. **Pętla nie przesuwa granic kroków.**
4. Pozycje spoza osi są **wypisane**, a nie pominięte.
