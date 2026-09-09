<?php
// Tylko z wiersza poleceń — patrz _wp-stubs.php.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

/**
 * Stałe dla analizy statycznej (PHPStan).
 *
 * PO CO OSOBNY PLIK, skoro te stałe powstają w kodzie: PHPStan czyta pliki,
 * nie uruchamia ich. Stała powołana `define()` wewnątrz `evoke-one.php` albo
 * w nagłówku elementu Bricksa nie istnieje więc w chwili analizy i każde jej
 * użycie jest zgłaszane jako „Constant … not found" — 60 razy przy pierwszym
 * przebiegu. To był największy pojedynczy pakiet szumu.
 *
 * Wartości nie mają znaczenia i celowo nie udają prawdziwych: PHPStan
 * potrzebuje wyłącznie TYPU. Ścieżki są tu łańcuchami, liczby liczbami —
 * tyle wystarczy, żeby sprawdził, że nie sklejamy ścieżki z tablicą.
 *
 * Stałe WordPressa (ARRAY_A, HOUR_IN_SECONDS…) siedzą w `wp-includes`, a nie
 * w pakiecie atrap, który podaje wyłącznie funkcje i klasy. Dlatego są tutaj
 * razem z naszymi.
 */

// ── WordPress ────────────────────────────────────────────────────────────
define('ARRAY_A', 'ARRAY_A');
define('OBJECT', 'OBJECT');
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('WPINC', 'wp-includes');
define('WP_DEBUG', false);

// ── Wtyczka ──────────────────────────────────────────────────────────────
define('EVOKE_ONE_DIR', '/ścieżka/do/wtyczki/');
define('EVOKE_ONE_URL', 'https://example.test/wp-content/plugins/evoke-one/');

// ── Elementy Bricksa, które powołują własne stałe w nagłówku pliku ───────
define('EVK_CIRCULAR_URL', 'https://example.test/');
define('EVK_CIRCULAR_VERSION', '0.0.0');
define('EVK_CIRCULAR_MENU_URL', 'https://example.test/');
define('EVK_CIRCULAR_MENU_VERSION', '0.0.0');
define('EVK_OFFCANVAS_MENU_URL', 'https://example.test/');
define('EVK_OFFCANVAS_MENU_VERSION', '0.0.0');
