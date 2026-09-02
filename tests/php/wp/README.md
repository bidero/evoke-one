# Kawałek WordPressa na potrzeby testów

`kses.php` to **nietknięta kopia** `wp-includes/kses.php` z WordPressa
(gałąź 6.7, GPLv2 — ta sama licencja co wtyczka). Nie edytuj tego pliku;
przy odświeżaniu pobierz go na nowo:

```
curl -o tests/php/wp/kses.php \
  https://raw.githubusercontent.com/WordPress/WordPress/6.7-branch/wp-includes/kses.php
```

## Po co on tu leży

`tl_sanitize_svg()` w `includes/70-bricks-language-switcher.php` stoi na
`wp_kses()`. Testy chodzą na atrapach WordPressa, a **atrapa sita
bezpieczeństwa byłaby bezwartościowa**: sprawdzałaby, czy moja własna imitacja
usuwa to, co sama uznała za groźne. Jedyny sposób, żeby test mówił cokolwiek
o rzeczywistości, to puścić przez PRAWDZIWY `wp_kses` — stąd ta kopia.

Plik potrzebuje kilku funkcji spoza siebie (`apply_filters`, `_deep_replace`,
`wp_allowed_protocols`, `esc_attr`, `wp_parse_str`, `did_action`,
`current_user_can`, `__`, `_x`); atrapy siedzą w `tests/php/svg-sanityzacja.php`.
