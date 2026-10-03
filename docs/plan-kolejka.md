# Kolejka prac i decyzje zgłaszającego

Spisane 02.10.2026, po 1.276.0. Poprzednia lista zginęła razem z rozmową —
ta leży w repozytorium (`docs/` nie jedzie w paczce wtyczki).

## Kolejność

Stan po 1.291.0: 2FA (1.287.0), ukryty adres (1.288.0), Logowanie z limitem i wygląd 2FA w Bricksie (1.289.0), menu „Evoke” w pasku (1.290.0), dostęp „Hotspoty” i konserwacja na telefonie (1.291.0) zrobione; dalej 1.292.0 — panel Evoke ONE dla roli Manager (decyzje 03.10: SEO, kopie zapasowe bez przywracania, przekierowania i 404, 2FA kont); potem repeater w CSV Fields. Do przegadania: szablony maili. Stan po 1.286.0 (ustalone 02.10): **statystyki i hotspoty (1.284 etap 1, 1.285 etap 2, 1.286 hotspoty i 404 — zrobione; później: edytowalna siatka kafli raportu 1/1, 2/1, 1/2;
szczegóły etapów: DNT/GPC przełącznikiem, domyślnie szanowane; licznik w pasku: ta strona dziś / 30 dni;
wykresy własne SVG; zakładka „Statystyki” w panelu, menu raportów osobną pozycją)
→ logowanie + 2FA → WebP/AVIF → repeater w CSV → generowanie treści AI
→ język główny**. Numery niżej to numery pozycji z pierwszego spisu.


1. **DeepL** — czwarty dostawca tłumaczenia AI (1.277.0).
2. **Zdolności MCP** (1.278.0) — tłumaczenie z Claude Desktop przez MCP Adapter
   (Abilities API w rdzeniu WordPressa). Trzy zdolności: strony z brakami,
   teksty do przetłumaczenia z kontekstem, zapis ze znacznikiem
   „Do sprawdzenia” i strażnikiem szkieletu (tagi HTML, `{…}`, shortcody).
   **Najważniejsze dla zgłaszającego: Claude Desktop OPRÓCZ API** — tłumaczy
   sam Claude z subskrypcji, bez klucza. Droga: MCP Adapter
   (`/wp-json/mcp/mcp-adapter-default-server`) + `@automattic/mcp-wordpress-remote`
   (npx) + hasło aplikacji WordPressa; Bricks 2.4 ma gotową instrukcję
   w swojej zakładce AI („Claude Desktop”, `claude_desktop_config.json`).
   Decyzje (02.10): zakres — to co hurt AI, pola Fields, SEO i OG, menu
   i frazy panelu (w przyszłości też generowanie treści); zapis ze znacznikiem
   „Do sprawdzenia”; uprawnienie jak zakładka Tłumaczenia; własna instrukcja
   w Evoke (macOS: stan adaptera, przycisk „Zainstaluj” z wydania na GitHubie,
   hasło aplikacji, gotowy JSON); prompty MCP: „Przetłumacz stronę”,
   „Przetłumacz wszystkie braki”, „Sprawdź tłumaczenia AI”; „Sprawdzone”
   klika tylko człowiek.
2b. **Frazy i menu w tłumaczeniu AI** (1.279.0, zrobione; decyzje 02.10): frazy słownika
   tłumaczy hurt w zakładce „Tłumaczenie AI” (wybór dostawcy i modelu na
   przebieg) i Claude Desktop przez MCP; etykiety wszystkich menu WordPressa
   dopisują się same do grupy „Menu” w słowniku fraz; fraza z AI ma znacznik
   „AI” (znika po poprawce albo „Sprawdzone”) i trafia na listę
   „Do sprawdzenia”.
2a. **Google Cloud Translation v3 (Advanced)** — piąty dostawca (1.280.0, zrobione).
   Darmowe 500 000 znaków/mies. (kredyt 10 $) jak w v2; v3 NIE przyjmuje
   klucza API — plik JSON konta usługi (rola „Cloud Translation API
   Editor”), token OAuth podpisywany na serwerze (JWT RS256). Glosariusz
   ze słowniczka jak w DeepL (tworzenie bez opłat).
3. **Nagłówki bezpieczeństwa** (1.280.0, zrobione; Permissions-Policy: kamera, mikrofon,
   geolokalizacja, płatności — bez płatności przy WooCommerce) — przełączniki w panelu. Domyślnie włączone:
   `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`,
   `frame-ancestors 'self'`. HSTS domyślnie wyłączony, z wyborem czasu,
   bez `preload`. Pełne CSP — później.
4. **Logowanie (Bricks)** — po statystykach. Decyzje (02.10):
   - adres: `wp-login.php` i `/wp-admin/` dla niezalogowanych bez klucza —
     **strona 404** (wyjątki: `admin-ajax.php`, `admin-post.php`,
     wylogowanie); tajny klucz to **własny adres** (np. `/panel-xyz`,
     ustawiany w panelu) — ustawia ciasteczko na 10 min i otwiera
     `wp-login.php`; `?brx_use_wp_login` bez klucza nie działa;
   - strona logowania: **z ustawień Bricksa** („Custom authentication
     pages”: logowanie, rejestracja, reset hasła) — Evoke dokłada 2FA
     i reset hasła na stronie Bricksa;
   - 2FA: TOTP z aplikacji + kody zapasowe, **dobrowolne**; administrator
     może je **wymusić dla wybranych ról** (włączenie przy następnym
     logowaniu); „zapamiętaj to urządzenie” na **30 dni** (zmiana hasła
     unieważnia); formularz Bricksa prosi o kod po haśle.
   Doprecyzowane 03.10 (kolejność wydań: **1.287.0 2FA → 1.288.0 ukryty adres
   logowania → 1.289.0 menu „Evoke” w pasku**):
   - 2FA włącza się w **Profilu** kokpitu, sekcja „Logowanie dwuetapowe” —
     trzy kroki na jednym ekranie: kod QR rysowany na miejscu (bez
     zewnętrznych serwisów) z kluczem do przepisania, pole kodu i „Włącz”,
     potem kody zapasowe;
   - pole kodu: JEDNO pole, duże cyfry z odstępem (`inputmode=numeric`,
     `autocomplete=one-time-code`, wklejanie działa);
   - drugi krok na stronie logowania Bricksa: w tym samym miejscu, bez
     przeładowania, zbudowany z elementów TEGO formularza (sklonowane pole
     i przycisk Bricksa — te same klasy, ten sam wygląd); sprawdzane
     w prawdziwym builderze i na stronie; hasło nie wraca do przeglądarki;
     na `wp-login.php` — wygląd WordPressa;
   - kody zapasowe: 10, jednorazowe, pokazane raz, Pobierz .txt / Kopiuj /
     Drukuj, w bazie tylko skróty, „Nowe kody” unieważnia stare;
   - wymuszenie dla ról: bez 2FA po zalogowaniu tylko Profil, dopóki nie
     włączy (każda inna strona kokpitu i strona z paskiem odsyła na Profil);
   - odzyskanie: inny administrator resetuje (Użytkownicy i profil, wpis
     w dzienniku) + awaryjna stała `EVK_2FA_WYLACZ` w wp-config.php (czerwone
     ostrzeżenie w panelu, dopóki jest); BEZ linku e-mailem;
   - hasła aplikacji (MCP z Claude Desktop) działają jak dziś; zwykłe hasło
     konta z 2FA przez XML-RPC i REST odrzucone;
   - „Zapamiętaj to urządzenie na 30 dni” domyślnie ODZNACZONE; w profilu
     lista zapamiętanych urządzeń i „Zapomnij wszystkie”;
   - panel: nowa zakładka **„Logowanie”** (włącznik, role z wymuszeniem,
     zapamiętywanie, później ukryty adres, lista kont z 2FA i reset);
     limit logowań zostaje w Bezpieczeństwie.
   **Ukryty adres (1.288.0, zrobione).** Rozstrzygnięte przy pracy, DO
   POTWIERDZENIA przez zgłaszającego:
   - `/wp-admin/` niezalogowanego → przekierowanie na 404 strony
     (`/?error=404`): kokpit nie wyrenderuje strony motywu w miejscu
     (WP_ADMIN), a przekierowanie na wp-login.php zdradzałoby logowanie;
   - klucz po zalogowaniu żyje tyle, co sesja logowania (także logowanie
     stroną Bricksa) — inaczej wylogowanie i „Sesja wygasła” dawały 404;
   - wylogowanie niezalogowanego (`action=logout`) → 404: zalogowanego
     blokada nie dotyczy, więc wylogowanie działa zawsze;
   - wyjątki z e-maili (reset hasła, potwierdzenie prośby o dane) — tylko
     z ważnym kluczem z e-maila; hasło wpisu — tylko wysłany formularz;
   - linki „Zaloguj”, „Nie pamiętasz hasła?”, „Zarejestruj się” → strony
     Bricksa, gdy są w jego ustawieniach;
   - przy zwykłych adresach WordPressa klucz to `/?adres`;
   - awaryjnie stała `EVK_UKRYTY_ADRES_WYLACZ`.
   **1.289.0 (03.10):** limit logowań do „Logowania” (trzy podstrony,
   stary adres przekierowuje); krok kodu 2FA w Bricksie — kontrolki
   w elemencie Formularz (teksty z EN, typografia, hover, układ, położenie
   pod polem/przyciskiem, klasy, komunikat złej próby, podgląd w builderze),
   domyślnie linki jak etykiety formularza; zmienne CSS `--evk-2fa-…`.
   **Menu „Evoke” (1.290.0, zrobione)** — przyjęte bez odpowiedzi
   zgłaszającego, DO POTWIERDZENIA: licznik jak dotąd (ta strona dziś /
   30 dni) w tytule menu; każda rola widzi tylko swoje grupy; Tłumaczenia =
   dotychczasowe „Sprawdź tłumaczenia (EN/DE…)”; na telefonie samo „Evoke”
   z rozwijaną listą.
   Menu „Evoke” w pasku admina na stronie (1.290.0): jedno rozwijane menu
   z grupami Statystyki (licznik w tytule), Hotspoty, Tłumaczenia, link do
   panelu; przełącznik konserwacji zostaje osobno na wierzchu; widoczne
   także na telefonie.
5. **WebP/AVIF** — konwersja przy wgrywaniu, oryginał zostaje; jakość
   w panelu, domyślnie **WebP 80, AVIF 60**; przy wgrywaniu także
   **zmniejszanie zbyt dużych** (maks. bok w panelu, np. 2560 px); EXIF
   bez zmian; przerobienie biblioteki **przyciskiem z paskiem postępu,
   dokańczane w tle (cron)**; podawanie przez `<picture>`: AVIF → WebP →
   oryginał (AVIF tylko, gdy serwer umie). Tła CSS — później, osobno.
6. **Repeater w CSV Fields** — JSON w jednej komórce; „Zastąp dane”
   (zastępuje albo dopisuje wiersze); obrazy po ID albo dopasowanym adresie;
   pola tłumaczeń wierszy; **eksport w tym samym formacie** (da się od razu
   zaimportować z powrotem).
7. **Generowanie treści AI** — własne, nie wtyczka AI WordPressa (jej
   funkcje nie działają w klasycznym edytorze). Generują dostawcy AI, nie
   DeepL ani Google Translation.
   - **Etap 1:** popup (długość, zarys, ton, styl, zapisane „przepisy”) →
     treść wpisu (i CPT), pola Fields (także wiersze repeatera), elementy
     Bricksa; tytuł, zajawka, metaopis (SEO Evoke), slug z treści;
     skróć / rozwiń / parafrazuj zaznaczenie (klasyczny edytor i Bricks);
     tagi i kategorie z treści (nowe albo tylko z istniejących).
     **Razem z etapem 1 — te same funkcje jako zdolności MCP** (Claude
     Desktop pisze, Evoke zapisuje jako szkic „Do sprawdzenia”).
   - **Etap 2:** notatki redakcyjne (dostępność, czytelność, gramatyka, SEO)
     + zastosowanie; podsumowanie treści; generowanie i edycja obrazów
     (Gemini, OpenAI) do biblioteki mediów.
   - Alt z wizji jest od 1.271.0. Autouzupełniania szarym tekstem nie robimy.
8. **Statystyki i hotspoty** — własne w Evoke, bez cookies. Decyzje (02.10):
   - zbieranie: skrypt ~2 KB, `sendBeacon` (działa przy cache stron);
     unikalni z dobowo zmienianej soli (skrót IP + przeglądarka), bez cookies
     i bez baneru zgody; boty odfiltrowane;
   - dane: odsłony, unikalni, strony, źródła, urządzenia, języki, kampanie
     UTM, zdarzenia automatyczne (tel:, mailto:, pobrania, linki wychodzące,
     formularze; własne przez `data-evk-zdarzenie`), czas na stronie
     i przewinięcie, kraj z własnej bazy IP (DB-IP Lite, CC BY — podpis
     w panelu, aktualizacja co miesiąc);
   - surowe wpisy: czas trzymania ustawiany w panelu (domyślnie 90 dni);
     zbiorcze dzienne — na zawsze; własne tabele;
   - nie liczyć: zalogowanych redaktorów i adminów, listy adresów IP;
   - raporty: osobne menu „Statystyki” jak Tłumaczenia (wybór miejsca menu),
     zakładka z wykresami, porównaniem okresów, „teraz na stronie”, CSV;
     widżet na Kokpicie; licznik odsłon w pasku admina na stronie
     (włączany/wyłączany);
   - hotspoty: kliknięcia (względem elementu, osobno telefon/tablet/komputer),
     głębokość przewinięcia, rage i martwe kliknięcia; nagrywane dla
     WYBRANYCH stron na czas (np. 14 dni albo N wizyt); podgląd jako
     nakładka na stronie; BEZ nagrań sesji i ruchu myszy;
   - dostęp: administrator i rola z nowym uprawnieniem „Statystyki”
     (moduł Uprawnienia);
   - cele: proste (zdarzenie albo wizyta na stronie), konwersja per źródło
     i kampania; bez lejków;
   - kolejność: ZARAZ PO 1.280.0 (przed logowaniem/2FA i resztą).
   - etap 1 wydany jako 1.284.0. Etap 2 (1.285.0), decyzje z 02.10:
     kraj z DB-IP Lite pobierany przez stronę co miesiąc (WP-Cron, import
     porcjami do własnej tabeli, podpis CC BY w raporcie); widżet Kokpitu:
     7 dni — liczby, porównanie z poprzednimi 7 dniami, mini wykres,
     5 stron; zdarzenia automatyczne od razu: tel:/mailto:, pobrania plików,
     linki wychodzące, wysłane formularze (każde z osobna do wyłączenia);
     „teraz na stronie” = ostatnie 5 min; cele: zdarzenie albo wizyta na
     adresie, konwersja = wizyty z celem / wszystkie, osobno dla źródła
     i kampanii.
     Dopisane 02.10 wieczorem: odstęp pod wykresem (zrobione), kasowanie
     statystyk za okres w zakładce Statystyki — od–do i „Usuń wszystkie”,
     tylko administrator, z potwierdzeniem (zrobione, gałąź
     claude/statystyki); wykres z przełącznikiem Słupki / Linie (wybór
     zapamiętany w przeglądarce; linie: odsłony i unikalni, z legendą);
     porównanie okresów — poprzedni okres tej samej długości jako przerywana
     linia odsłon, przy liczbach zmiana w %.
   Poprawki do 1.286.0 (razem z hotspotami; decyzje z 02.10 po zrzutach 1.285.0):
     - pudełka list w raporcie RÓWNE: po 5 pozycji + przycisk „Pokaż
       wszystkie (N)” rozwijający listę w miejscu (aria-expanded);
       edytowalna siatka kafli 1/1, 2/1, 1/2 — później, osobno;
     - daty pod wykresem (pierwszy, środkowy, ostatni dzień);
     - zdarzenia w dwóch liniach: rodzaj małym szarym napisem nad etykietą;
     - źródła: także PEŁNE adresy odsyłające, bez części po „?” (tokeny,
       dane osobowe) — rozwijane pod domeną; Google/Bing i tak dają samą
       domenę;
     - widżet Kokpitu: BEZ listy adresów; Odsłony, Unikalni, Teraz na
       stronie, pod nimi wykres z przełącznikiem Słupki / Linie, zakładki
       okresu (Dziś / 7 dni / 30 dni) i przycisk „Pełny raport”;
       mini wykres na całą szerokość ze skrótami dni.
   Hotspoty (1.286.0), decyzje z 02.10 wieczorem:
     - włączanie: z paska admina na stronie („Nagrywaj tę stronę”)
       i z listy w zakładce Statystyki (adres, czas, liczba wizyt, stan);
       domyślnie 14 dni albo 1000 wizyt, co pierwsze;
     - po zakończeniu dane ZOSTAJĄ do ręcznego usunięcia; nowe nagranie
       dopisuje albo zaczyna od zera;
     - nakładka: mapa ciepła kliknięć, mapa przewinięcia, złość i martwe
       kliknięcia, lista najczęściej klikanych elementów;
     - podgląd w ramce o szerokości urządzenia (telefon / tablet / komputer);
     - dostęp: ogląda rola z „Statystyki”, włącza i kasuje administrator;
     - polityka prywatności: zdanie o hotspotach, gdy nagranie trwa — tylko
       na własne potrzeby, dane nie są nikomu przekazywane.
   Matomo odpada: heatmapy to płatna wtyczka premium (InnoCraft, nie GPL),
   a Matomo for WordPress waży kilkadziesiąt MB.
9. **Język główny inny niż polski** — **tylko nowe strony** (wybór przy
   pierwszej konfiguracji; istniejące zostają z polskim); **główny bez
   prefiksu** (`/` = główny, `/pl/` = polski); polski jako zwykły język
   z polami „… PL” tylko po dodaniu; jeden język główny, konfigurowalny;
   `hreflang` / `x-default` i mapa strony. Bez tłumaczenia samych wtyczek.
   `'pl'` na sztywno: 192 miejsca w `includes/` (stan 1.276.0).
10. **Przełącznik języków w popupie AJAX** — odłożone na zdecydowanie później.
