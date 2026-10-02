# Kolejka prac i decyzje zgłaszającego

Spisane 02.10.2026, po 1.276.0. Poprzednia lista zginęła razem z rozmową —
ta leży w repozytorium (`docs/` nie jedzie w paczce wtyczki).

## Kolejność

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
2a. **Google Cloud Translation v3 (Advanced)** — piąty dostawca, następny (1.280.0).
   Darmowe 500 000 znaków/mies. (kredyt 10 $) jak w v2; v3 NIE przyjmuje
   klucza API — plik JSON konta usługi (rola „Cloud Translation API
   Editor”), token OAuth podpisywany na serwerze (JWT RS256). Glosariusz
   ze słowniczka jak w DeepL (tworzenie bez opłat).
3. **Nagłówki bezpieczeństwa** — przełączniki w panelu. Domyślnie włączone:
   `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`,
   `frame-ancestors 'self'`. HSTS domyślnie wyłączony, z wyborem czasu,
   bez `preload`. Pełne CSP — później.
4. **Logowanie (Bricks)**:
   - adres: `wp-login.php` i `/wp-admin/` dla niezalogowanych zamknięte
     (wyjątki: `admin-ajax.php`, `admin-post.php`, wylogowanie); tajny klucz
     odsłania `wp-login.php`; `?brx_use_wp_login` bez klucza nie działa;
   - reset hasła: na stronie Bricksa (wp-login.php zostaje zamknięty);
   - 2FA: TOTP z aplikacji + kody zapasowe, **dobrowolne** (użytkownik
     włącza w profilu), „zapamiętaj to urządzenie” na **30 dni** (zmiana
     hasła unieważnia); formularz logowania Bricksa prosi o kod po haśle.
5. **WebP/AVIF** — konwersja przy wgrywaniu, oryginał zostaje; opcja
   przerobienia biblioteki; jakość w panelu; podawanie przez `<picture>`:
   AVIF → WebP → oryginał (AVIF tylko, gdy serwer umie). Tła CSS — później.
6. **Repeater w CSV Fields** — JSON w jednej komórce; „Zastąp dane”
   (zastępuje albo dopisuje wiersze); obrazy po ID albo dopasowanym adresie;
   pola tłumaczeń wierszy.
7. **Generowanie treści AI** — popup z długością, zarysem, tonem, stylem
   i zapisanymi „przepisami”. Wynik do: treści wpisu (i CPT), pól Fields
   (także wierszy repeatera) i elementów Bricksa. Dodatkowo: tytuł,
   zajawka, SEO. Generują dostawcy AI, nie DeepL.

   **Własne, nie wtyczka AI WordPressa** (02.10): jej funkcje nie działają
   w klasycznym edytorze, którego używamy. Lista funkcji do odtworzenia
   (priorytety do ustalenia):
   - generowanie i edycja obrazów (modele obrazów: Gemini, OpenAI);
   - tekst alternatywny z wizji — jest od 1.271.0 („Opisz obraz (AI)”);
   - klasyfikacja: tagi i kategorie, nowe albo tylko z istniejących;
   - zmiana długości: skróć, rozwiń, parafrazuj zaznaczony fragment;
   - podsumowanie treści;
   - notatki redakcyjne (dostępność, czytelność, gramatyka, SEO) i ich
     zastosowanie;
   - zajawka, metaopis (moduł SEO Evoke), uproszczona nazwa (slug), tytuł;
   - autouzupełnianie szarym tekstem przy pisaniu (w klasycznym edytorze
     TinyMCE — do sprawdzenia, czy warto).
8. **Statystyki** — własne w Evoke, bez cookies (odsłony, strony, źródła,
   urządzenia) i własne heatmapy (kliknięcia, głębokość przewinięcia,
   osobno dla szerokości ekranu). Matomo odpada: heatmapy to płatna wtyczka
   premium (InnoCraft, nie GPL), a Matomo for WordPress waży kilkadziesiąt MB.
9. **Język główny inny niż polski** — tylko nowe strony (stare, jeśli
   migracja będzie łatwa); główny bez prefiksu, pozostałe (polski też)
   z prefiksem; polski jako zwykły język z polami „… PL” tylko po dodaniu;
   jeden język główny, konfigurowalny; `hreflang` / `x-default` i mapa
   strony. Bez tłumaczenia samych wtyczek. `'pl'` na sztywno: 192 miejsca
   w `includes/` (stan 1.276.0).
10. **Przełącznik języków w popupie AJAX** — odłożone na zdecydowanie później.
