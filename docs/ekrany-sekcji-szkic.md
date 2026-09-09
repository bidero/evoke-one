# Ekrany sekcji z przełącznikami — szkic

Notatka przekazująca do osobnej rozmowy. Spisana 2026-09-09, przy wersji
**1.162.0**.

## Zastrzeżenie na wstępie

**Nie wiem, co dokładnie znaczy „ekrany sekcji z przełącznikami".** Ta pozycja
stała na liście zgłaszającego od dawna, została przez niego odłożona i nigdzie
w repozytorium — ani w CHANGELOG-u, ani w `docs/`, ani w komentarzach — nie ma
jej definicji. Poniżej jest **rekonstrukcja z nazwy i z kształtu panelu**,
a nie zapis ustaleń. Pierwszą rzeczą w nowej rozmowie ma być pytanie, czy
zgadza się z tym, co zgłaszający miał na myśli, a nie kod.

Hipoteza: **każda sekcja panelu dostaje ekran przeglądowy z przełącznikami
swoich modułów** — to, co pulpit robi dziś globalnie, powtórzone na poziomie
zakładki.

## Stan zastany

### Panel

`includes/admin/page.php` — „Control Center". Pulpit plus osiem zakładek
(l. 206):

| klucz | plik |
|---|---|
| `dashboard` | — (pulpit, rysowany w `page.php`) |
| `wydajnosc` | `tab-wydajnosc.php` |
| `strona` | `tab-strona.php` |
| `bezpieczenstwo` | `tab-bezpieczenstwo.php` |
| `narzedzia` | `tab-narzedzia.php` |
| `admin_panel` | `tab-admin.php` |
| `newsletter` | `tab-newsletter.php` |
| `forminbox` | `tab-forminbox.php` |

Zakładki SEO (`schema`, `sitemap`, `seo-meta`) i newslettera mają własne
podzakładki w `includes/admin/seo/` i `includes/admin/newsletter/`.

### Wzorzec przełącznika — jest gotowy, nie trzeba go wymyślać

Powtarza się w całym panelu, np. `includes/admin/seo/tab-schema.php` l. 34–49:

```php
<div class="evo-status-card">
    <div class="evo-status-icon on|off">…</div>
    <div class="evo-status-text"><h3>Moduł …: WŁĄCZONY</h3><p>…</p></div>
    <div class="evo-status-actions">
        <label class="evo-toggle">
            <input type="checkbox" data-option="evk_schema" data-field="enabled" value="1">
            <span class="evo-slider"></span>
        </label>
    </div>
</div>
```

Przełącznik jedzie AJAX-em, nie formularzem.

### Granica bezpieczeństwa, o którą trzeba zahaczyć

`evk_toggle_allowlist()` w `includes/30-admin-settings-ajax.php` to **lista
dozwolonych opcji** dla tego AJAX-a. Bez niej handler pozwalałby przestawić
dowolne ustawienie WordPressa jednym zapytaniem.

**Każdy nowy przełącznik musi trafić na tę listę** — inaczej po cichu nie
zadziała. I odwrotnie: nie wolno rozluźnić listy, żeby „działało od ręki".

### Pulpit i jego liczniki

Pulpit wypisuje „N aktywnych z 9". `tests/panel-start.test.js` sprawdza to
modułami **osobno**, z uzasadnieniem, które warto przeczytać przed dokładaniem
kafli: licznik zbiorczy przechodzi także wtedy, gdy jedna nazwa opcji jest
błędna, a inna liczy się podwójnie. Ekran startowy przyszedł kiedyś od innego
agenta **bez testów** i pulpit sięgający po nieistniejącą opcję **kłamie po
cichu** — pokazuje zero i nikt się nie dowiaduje.

To jest najważniejsza pułapka tego zadania: ekran przeglądowy to głównie
liczniki i stany, a te potrafią być fałszywe bez żadnego objawu.

## Pytania przed pisaniem kodu

1. **Co to ma być?** Ekran przeglądowy dla każdej zakładki, czy jeden ekran
   z wszystkimi modułami pogrupowanymi w sekcje? A może coś zupełnie innego,
   czego nie odgadłem.
2. **Co jest „modułem sekcji"?** Dziś nie każda zakładka ma moduły
   z przełącznikami — część to zwykłe ustawienia. Czy ekran ma pokazywać tylko
   przełączalne, czy wszystko?
3. **Przełącznik na ekranie przeglądowym a przełącznik w zakładce** — jeden
   stan w dwóch miejscach. Czy mają się odświeżać nawzajem bez przeładowania?
4. **Czy ekran zastępuje pulpit, czy staje obok niego?**

## Zasady pracy, które obowiązują dalej

- Rozmowa **po polsku**, do zgłaszającego w liczbie pojedynczej.
- **Pytać jak najwięcej** o szczegóły.
- **Żadnej optymalizacji bez pomiaru.**
- **Każde sprawdzenie dowiedzione mutacją**: zepsuć kod naumyślnie
  i potwierdzić, że zapala. Mutacja przechodząca na zielono kończy się
  przepisaniem sprawdzenia **albo uproszczeniem kodu**.
- Każdy plik w `tests/php/` zaczyna się od
  `if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }` — pliki jadą
  aktualizatorem na żywe strony.
- Wydania na `claude/evoke-one-functionality-vssnl3`, scalenie fast-forward
  do `main`, push obu. Numer wersji w trzech miejscach.
- Cel projektu: **mniej wtyczek zewnętrznych**.

## Uruchamianie sprawdzeń

```
npm install && composer install     # raz, po świeżym klonie
node tests/run.js                   # wszystko: 51 plików, ~12 min
node tests/run.js panel-start admin # tylko pasujące nazwą pliku
vendor/bin/phpstan analyse          # analiza statyczna PHP, ~8 s
```

Sekcja w trakcie pracy, pełny zestaw przed commitem wydania.

## Co jeszcze leży odłożone

Zgłaszający odłożył świadomie, w tej kolejności nie ma pilnych:

1. **Ziarno na całym elemencie Wave BG przez CSS** — tylko po pomiarze;
   `feTurbulence` potrafi kosztować więcej niż oszczędzany kadr.
2. **Parallax: pusta warstwa zamiast `::before`** — zmierzone 202 → ~15 ms
   przeliczania stylu na pełne przewinięcie. **W PSI niewidoczne**, bo
   Lighthouse nie przewija. Wyłącznie płynność u prawdziwego użytkownika.
3. **21 KB CSS TinyMCE na froncie** — obserwacja z raportu PSI,
   **niezdiagnozowana**; nie wiadomo, czy źródłem jest ta wtyczka.

Osobno, z analizy statycznej: **`includes/93-darkmode.php:671`** —
`is_string()` po rzutowaniu `(array)`, więc martwy warunek i klasy podane jako
łańcuch nigdy się nie dopasują. Prawdziwy uśpiony błąd, zamrożony
w `phpstan-baseline.neon`, do zdjęcia jako pierwszy. Naprawa to jedna linijka,
ale wymaga sprawdzenia dowodzącego zachowania.

I zawsze aktualne: **Schema** — `docs/schema-szkic.md`.
