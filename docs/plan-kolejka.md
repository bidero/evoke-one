# Kolejka prac i decyzje zgłaszającego

Spisane 02.10.2026, po 1.276.0. Poprzednia lista zginęła razem z rozmową —
ta leży w repozytorium (`docs/` nie jedzie w paczce wtyczki).

## Kolejność

1. **DeepL** — czwarty dostawca tłumaczenia AI (1.277.0).
2. **Zdolności MCP** — tłumaczenie z Claude Desktop przez MCP Adapter
   (Abilities API w rdzeniu WordPressa). Trzy zdolności: strony z brakami,
   teksty do przetłumaczenia z kontekstem, zapis ze znacznikiem
   „Do sprawdzenia” i strażnikiem szkieletu (tagi HTML, `{…}`, shortcody).
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
