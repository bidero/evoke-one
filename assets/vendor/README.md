# assets/vendor — biblioteki obce, hostowane u siebie

Tu leżą **niezmienione** pliki dystrybucyjne GSAP-a i Lenisa. Nic w nich nie
poprawiamy: każda zmiana zniknęłaby przy pierwszym podbiciu wersji i nie byłoby
jak jej odtworzyć.

**Wyjątkiem jest three.js** — tam leży paczka *zbudowana*, nie skopiowana. Wolno
jej tu być, bo powstaje **jedną udokumentowaną komendą z pliku źródłowego, który
też jest w repozytorium** (`three/wejscie.js`), więc da się ją odtworzyć co do
bajta. Powód niżej, w sekcji o three.js.

## Dlaczego lokalnie, a nie z CDN-u

Do 1.72.0 GSAP jechał z cdnjs, a Lenis z unpkg. Zmierzone na żywej stronie:
**53 KiB w sumie, 900–1650 ms na plik**. To nie jest koszt bajtów — to koszt
połączeń. Dwa obce hosty znaczą dwa razy DNS + TCP + TLS, zanim przyjdzie
pierwszy bajt; z własnego serwera te same pliki jadą po **już otwartym**
połączeniu HTTP/2.

Argument „użytkownik ma to już w pamięci podręcznej z innej strony" **nie
obowiązuje od 2020**: przeglądarki dzielą cache per witryna, więc każda strona
i tak pobiera swoje. Do tego znika zależność od cudzej dostępności i wyciek
adresów IP odwiedzających do zewnętrznego CDN-u (RODO).

## Skąd się biorą pliki

Obie biblioteki są w `package.json` jako `devDependencies` — nie dlatego, że
potrzebuje ich kod, tylko **żeby npm pilnował wersji i pokazywał aktualizacje**.
Pliki kopiujemy z paczki, nie pobieramy z sieci pojedynczo: paczka npm jest
tym, co autor faktycznie wydał.

### Podbicie GSAP-a

```sh
npm install gsap@latest
for f in gsap ScrollTrigger Observer SplitText TextPlugin ScrambleTextPlugin; do
  cp "node_modules/gsap/dist/$f.min.js" assets/vendor/gsap/
done
```

Potem **zmień `EVK_GSAP_VERSION`** w `includes/89-gsap.php` na nowy numer.
Ta stała jest jednocześnie cache-busterem w adresie skryptu — bez jej zmiany
przeglądarki zostaną przy starym pliku.

### Podbicie three.js

three.js **nie jest kopiowany, tylko budowany** — i to jedyna taka biblioteka tutaj.

Powód jest w liczbach. Oficjalny `three.module.min.js` nie działa sam: importuje
jeszcze `three.core.min.js`, a pliki post-processingu (`EffectComposer`,
`RenderPass`, `ShaderPass`) importują `three` jako **nazwę pakietu**, której
przeglądarka nie umie rozwiązać bez mapy importów. Komplet oficjalny to więc
**trzy pliki, 190 KB po gzipie i kaskada zależności**. Paczka zbudowana z tego,
czego element naprawdę używa, to **jeden plik, 133 KB i jedno żądanie**.

Sprawdzone, że to nie zmienia obrazu: zrzuty przy **ustalonej chwili animacji**
(`window.__kadr()` w fixturze pomiarowym) z paczki i z oficjalnych plików —
zminifikowanych i nie — są **identyczne co do piksela**. Bez ustalania chwili
takie porównanie nie ma sensu: dwa przebiegi tego samego kodu potrafią różnić się
średnio o 10 poziomów jasności, bo kadr zależy od tego, ile klatek zdążyło wypaść.

```sh
npm install three@latest esbuild
V=$(node -p "require('./node_modules/three/package.json').version")
mkdir -p "assets/vendor/three/$V"
./node_modules/.bin/esbuild assets/vendor/three/wejscie.js --bundle --format=esm --minify \
  --outfile="assets/vendor/three/$V/evoke-three.min.js"
```

Potem **zmień `EVK_THREE_VERSION`** w `includes/bricks-elements/evoke-wave-bg/element.php`
i **skasuj katalog starej wersji**.

Numer wersji siedzi w **ścieżce**, a nie w `?ver=` jak przy GSAP-ie. Nie jest to
kaprys: gdyby paczka kiedyś przestała być samowystarczalna i zaczęła importować
plik obok, ten import poszedłby adresem względnym — bez parametru wersji — i można
by dostać świeży moduł ze starym plikiem obok. Katalog busta wszystko naraz.
Że paczka niczego nie dociąga, pilnuje sprawdzenie „i nie dociąga niczego dalej"
w `tests/wave-bg.test.js`.

`wejscie.js` wylicza **dokładnie te symbole, których używa element**. Dołożenie
w elemencie czegoś nowego z three.js wymaga dopisania tego tam — inaczej paczka
tego nie będzie miała i moduł wywróci się na `undefined`.

### Podbicie Lenisa

```sh
npm install lenis@latest
cp node_modules/lenis/dist/lenis.min.js     assets/vendor/lenis/
cp node_modules/lenis/dist/lenis.min.js.map assets/vendor/lenis/
```

Potem **zmień `EVK_LENIS_VERSION`** w `includes/96-lenis.php` — i sprawdź, czy
nie zmienił się arkusz `dist/lenis.css`, bo jego odpowiednik trzymamy inline
w `render_css()` (żeby nie dokładać osobnego żądania).

## Kto tego używa

| Plik | Handle WordPressa | Kto rejestruje |
|---|---|---|
| `gsap/*.min.js` | `evk-gsap`, `evk-scrolltrigger`, `evk-observer`, `evk-splittext`, `evk-textplugin`, `evk-scrambletext` | `includes/89-gsap.php` |
| `lenis/lenis.min.js` | `evk-lenis-lib` | `includes/96-lenis.php` |
| `three/<wersja>/evoke-three.min.js` | brak — moduł ES ładowany dynamicznym `import()` | `includes/bricks-elements/evoke-wave-bg/element.php` |

Adres katalogu GSAP-a jest wystawiany na stronie jako `window.evkGsapBase` —
korzystają z niego loadery awaryjne w `marquee.js` i `hscroll.js`. Do 1.72.0
miały tam wpisany na sztywno adres cdnjs.

Testy (`tests/fixtures/*.html`) ładują **te same pliki**, a nie kopię
z `node_modules` — inaczej sprawdzałyby coś innego, niż jedzie na stronę.

## Licencje

* **GSAP** — [standard license](https://gsap.com/standard-license) GreenSocka.
  Od 3.13 (przejęcie przez Webflow) wszystkie wtyczki są bezpłatne, także
  SplitText i ScrambleText. Licencja dopuszcza użycie i rozpowszechnianie
  w ramach własnego produktu; zabrania odsprzedawania samego GSAP-a.
* **Lenis** — MIT (Studio Freight / darkroom.engineering).
* **three.js** — MIT (Three.js Authors). Licencja dopuszcza rozpowszechnianie
  także w postaci zbudowanej; paczka zawiera fragmenty `three` i trzech klas
  z `examples/jsm/postprocessing`.
