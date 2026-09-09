<?php
namespace Bricks;

// Tylko z wiersza poleceń — patrz _wp-stubs.php.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

/**
 * Atrapy Bricksa WYŁĄCZNIE dla analizy statycznej.
 *
 * DLACZEGO OSOBNY PLIK, a nie dopisek do `_bricks-stubs.php` — i to nie jest
 * porządkowanie, tylko unikanie realnej usterki w testach:
 *
 * `includes/bricks-elements/loader.php:222` ma wprost
 * `if (!class_exists('\Bricks\Frontend')) return;`. Gdyby ta klasa trafiła do
 * atrapy, którą wciągają testy, warunek odwróciłby się w środowisku testowym
 * i loader zacząłby wykonywać gałąź, której na sucho wykonywać nie ma prawa.
 * Test świeciłby na zielono na ścieżce, której na żywej stronie nie ma.
 *
 * PHPStan tych plików nie uruchamia — tylko je czyta — więc dla niego różnicy
 * nie ma. Dla testów jest, i to zasadnicza. Stąd podział: co potrzebne
 * testom idzie do `_bricks-stubs.php`, co potrzebne wyłącznie analizie — tutaj.
 *
 * Sygnatury są celowo luźne. Nie znamy prawdziwych typów Bricksa i nie ma sensu
 * ich zgadywać: `mixed` mówi „nie wiem", a zgadnięty typ mówiłby nieprawdę
 * i zapalałby błędy, których nie ma.
 */

if (!class_exists('Bricks\\Query')) {
    class Query {
        /** Obiekt bieżącej pętli zapytania albo `null` poza pętlą. */
        public static function get_loop_object(): mixed { return null; }
    }
}

if (!class_exists('Bricks\\Frontend')) {
    class Frontend {
        /** @param mixed $children */
        public static function render_children($children): string { return ''; }
    }
}
