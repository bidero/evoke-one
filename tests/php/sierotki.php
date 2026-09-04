<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Sierotki — wyjście PRAWDZIWEGO modułu.
 *
 * Test podaje tekst i dostaje to, co moduł z nim zrobi. Wzorce nie są nigdzie
 * przepisane: kopia rozjechałaby się przy pierwszej poprawce i sprawdzenia
 * pilnowałyby wtedy testu, nie wtyczki.
 *
 *   php tests/php/sierotki.php '<p>Idziemy w las</p>' '{"jednostki":1}'
 *
 * Drugi argument to ustawienia (JSON) doklejane do domyślnych.
 */

require __DIR__ . '/_wp-stubs.php';

$ustawienia = json_decode($argv[2] ?? '{}', true) ?: [];
$GLOBALS['options']['evk_sierotki'] = array_merge(['enabled' => 1], $ustawienia);

require EVK_TEST_ROOT . '/includes/91-sierotki.php';

/* Wołamy przez `popraw()`, a nie przez filtr: filtry WordPressa wymagałyby
   pełnego środowiska (`is_admin`, `is_feed`, kolejność priorytetów), a badana
   jest tu sama zamiana. To, ŻE moduł wpina się w te filtry — i w które —
   sprawdza osobna sekcja testu, czytając źródło. */
echo EVK_Sierotki::get_instance()->popraw($argv[1] ?? '');
