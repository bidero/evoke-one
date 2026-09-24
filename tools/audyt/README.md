# Audyt — sondy do powtórzenia

Skrypty z audytu 1.229.6. Każdy odpowiada na jedno pytanie i wypisuje wynik,
nie ustawia progów — to narzędzia do obejrzenia, nie testy.

Wymagają testowego WordPressa (`tools/testowy-wp.sh`, katalog z `EVK_WP_PATH`
albo `~/.cache/evk-testowy-wp`). Sondy PHP PISZĄ do jego bazy (tworzą i kasują
wpisy, role, listy) — nie puszczaj ich równolegle z testami kopii. Wyniki
przeglądarkowe lądują w `tools/audyt/wyniki/` (poza gitem).

Każdy plik PHP zaczyna się od bramki `PHP_SAPI !== 'cli'` — katalog `tools/`
jedzie aktualizatorem na strony klientów.

## Sondy PHP (`php tools/audyt/sondy/<plik>.php`)

| Plik | Pytanie |
|---|---|
| `import-fatal.php` | Czy import ustawień przeżywa wyłączony moduł Tłumaczeń? (`Z_TL=1` — z tłumaczeniami w paczce) |
| `draft-slash.php` | Czy wersja robocza i synchronizacja zachowują `\`? |
| `snippet-slash.php` | Czy zapis snippetu zachowuje `\`? |
| `snippet-io.php` | Czy snippet PHP wraca z eksportu/importu jako PHP? |
| `role-ograniczenia.php` | Czy „Ograniczenie edycji stron” blokuje inne strony? |
| `nl-csv.php` | Jak import CSV czyta plik z polskiego Excela? |
| `nl-wypisani.php` | Czy import przywraca osobę wypisaną? |
| `nl-amp.php` | Czy śledzenie kliknięć zachowuje parametry `&amp;`? |
| `og-fallback.php` | Co dostaje `og:image` strona bez wygenerowanego obrazka? |

## Przeglądarka (`node tools/audyt/<plik>.js`)

| Plik | Co robi |
|---|---|
| `panel-crawl.js` | Każdy ekran panelu × 5 szerokości: poziomy scroll, wystające elementy, ostrzeżenia PHP, błędy JS, nieudane żądania, zrzuty (`SZER`, `ZRZUTY`, `TYLKO`). |
| `panel-a11y.js` | Po `panel-crawl.js`: pola bez etykiety, przyciski bez nazwy, zdublowane id, H1. |
| `front-waga.js` | Strona główna z modułami frontu wyłączonymi i włączonymi: pliki, wbudowany JS/CSS. **Przestawia opcje modułów.** |
| `inline-duze.js` | Największe wbudowane bloki `<script>`/`<style>` na stronie głównej. |
| `canonical.js` | Ile tagów canonical i hreflang dostaje strona przy włączonych Tłumaczeniach. |
| `ctrlk.js` | Co otwiera Ctrl+K na ekranie Evoke ONE. |
