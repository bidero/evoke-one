# Szkic wpisu do CHANGELOG — Obrazy WebP/AVIF (pozycja 5 kolejki)

Gałąź `claude/webp-avif`, commity „(bez wydania)”. Numer wersji nada sesja
główna przy scaleniu (po pełnym przebiegu). Poniżej gotowy tekst wpisu —
`[1.2xx.0]` do podmiany.

---

## [1.2xx.0] — 2026-10-xx

Obrazy WebP/AVIF: lżejsze wersje zdjęć z biblioteki, podawane przez `<picture>`.

Decyzje zgłaszającego (02.10): konwersja przy wgrywaniu, oryginał zostaje;
AVIF tylko, gdy serwer umie; jakość w panelu (WebP 80, AVIF 60);
zmniejszanie zbyt dużych progiem WordPressa; przerobienie biblioteki
przyciskiem z paskiem postępu, a po zamknięciu karty — cronem; `<picture>`
w treści wpisów i w renderze Bricksa; usunięcie załącznika zabiera jego
wersje; tła CSS — później. Bez bibliotek zewnętrznych: tylko edytory obrazów
WordPressa (GD, Imagick).

### Dodane

- **Frontend → „Obrazy WebP/AVIF”** (nowy ekran z przełącznikiem). Przy
  wgrywaniu obok każdego pliku JPEG i PNG, czyli pliku głównego
  i wszystkich rozmiarów z `srcset`, powstają `zdjecie-300x200.jpg.webp`
  i `zdjecie-300x200.jpg.avif`. Oryginał zostaje bez zmian.
- **AVIF tylko, gdy serwer umie** (Imagick z AVIF albo GD z `imageavif`).
  Panel mówi, czym serwer zapisuje każdy format, a przy braku AVIF tłumaczy,
  czego brakuje. Na takim serwerze powstają same WebP i nic się nie psuje.
- **Jakość w panelu**: WebP 80, AVIF 60 (1–100).
- **Zmniejszanie zbyt dużych przy wgrywaniu**: najdłuższy bok w panelu
  (domyślnie 2560 px, 0 = bez zmniejszania). To próg samego WordPressa
  (`big_image_size_threshold`): plik „-scaled”, a oryginał zostaje obok.
  EXIF bez zmian.
- **Przerobienie istniejącej biblioteki**: przycisk z paskiem postępu.
  Do wyboru „tylko obrazy bez wersji” albo „wszystkie od nowa” (np. po
  zmianie jakości), do tego „Zatrzymaj”. Porcje idą przez AJAX z karty.
  Gdy karta milczy ponad minutę, przebieg dokańcza WP-Cron, a po powrocie
  do panelu pasek pokazuje stan.
- **`<picture>` na stronie**: `<source type="image/avif">` →
  `<source type="image/webp">` → oryginalny `<img>`, z nietkniętymi
  klasami, `alt` (także tłumaczonym), `srcset`, `sizes`, `loading`,
  `decoding` i wymiarami. Działa w treści wpisów (`the_content`),
  w miniaturach (`post_thumbnail_html`) i w renderze Bricksa (Image,
  Image Gallery i każdy inny `<img>` z biblioteki). Źródło danego formatu
  pojawia się tylko wtedy, gdy wersję ma KAŻDY kandydat ze `srcset`.
  `picture.evk-obraz { display: contents }`, więc układ strony się nie
  zmienia.
- **Leniwe ładowanie Bricksa**: skrypt Bricksa obsługuje tylko `<img>`,
  nie `<source>`. Obraz w `<picture>` dostaje więc prawdziwe adresy
  i natywne `loading="lazy"`, które obejmuje cały `<picture>`. Pierwszy
  obraz strony (`fetchpriority="high"` od WordPressa) zostaje bez
  `loading="lazy"`. Podpis pod leniwie ładowanym obrazem widać od razu
  (Bricks chował go do załadowania). Miejsce na obraz rezerwują
  `width`/`height`, więc układ się nie zmienia.
- **Usunięcie załącznika** zabiera też jego pliki WebP/AVIF (działa także
  przy wyłączonym module). **Odinstalowanie z „Usuń dane”** kasuje wersje
  WebP/AVIF z biblioteki, meta `_evk_obrazy`, ustawienia i zadanie crona
  (spis w `includes/dane-wtyczki.php`).

### Uwagi techniczne

- WebP zapisuje GD, nawet gdy jest Imagick. ImageMagick 6.9.12 (Ubuntu
  24.04, częsty na hostingach) zapisuje WebP ze stałą jakością: q30 i q90
  dały plik co do bajtu ten sam, także `convert -quality`. GD: 26 KB vs
  163 KB. AVIF idzie przez Imagick, bo tam jakość działa.
- WordPress przy zmianie typu resetuje jakość do domyślnej (WebP 86).
  Jakość z panelu podaje filtr `wp_editor_set_quality` na czas zapisu.
- Wgrywanie zdjęcia 3000×2000 z WebP i AVIF: ok. 5,2 s wobec 2,2 s bez
  modułu (sam WebP: 3,5 s). Prawie cały narzut to kodowanie AVIF siedmiu
  plików.
