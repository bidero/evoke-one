# assets/vendor — biblioteki obce, hostowane u siebie

Tu leżą **niezmienione** pliki dystrybucyjne GSAP-a i Lenisa. Nic w nich nie
poprawiamy: każda zmiana zniknęłaby przy pierwszym podbiciu wersji i nie byłoby
jak jej odtworzyć.

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

### Podbicie Lenisa

```sh
npm install lenis@latest
cp node_modules/lenis/dist/lenis.min.js assets/vendor/lenis/
```

Potem **zmień `EVK_LENIS_VERSION`** w `includes/96-lenis.php` — i sprawdź, czy
nie zmienił się arkusz `dist/lenis.css`, bo jego odpowiednik trzymamy inline
w `render_css()` (żeby nie dokładać osobnego żądania).

## Kto tego używa

| Plik | Handle WordPressa | Kto rejestruje |
|---|---|---|
| `gsap/*.min.js` | `evk-gsap`, `evk-scrolltrigger`, `evk-observer`, `evk-splittext`, `evk-textplugin`, `evk-scrambletext` | `includes/89-gsap.php` |
| `lenis/lenis.min.js` | `evk-lenis-lib` | `includes/96-lenis.php` |

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
