<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Reguła warstwy parallaksy — wypisana przez PRAWDZIWY moduł.
 *
 * Fixture przeglądarkowy wstrzykuje to, co zwróci ten plik, zamiast trzymać
 * kopię reguły u siebie. Kopia zaczęłaby żyć własnym życiem: sprawdzenie
 * „tło widać od pierwszego malowania" przechodziłoby na zielono także wtedy,
 * gdyby moduł przestał tę regułę drukować.
 *
 *   php tests/php/parallax.php [skala]
 */
require __DIR__ . '/_wp-stubs.php';

require EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';

$GLOBALS['options']['evk_parallax']       = ['enabled' => 1];
$GLOBALS['options']['evk_parallax_scale'] = (float) ($argv[1] ?? 1.2);
$GLOBALS['options']['evk_parallax_value'] = (float) ($argv[2] ?? 0.3);

require EVK_TEST_ROOT . '/includes/92-parallax.php';

/* Moduł zapina `print_layer_css` na `wp_head` w konstruktorze — odpalamy ten
   hak, a nie metodę z ręki, żeby sprawdzenie obejmowało także to, czy reguła
   w ogóle trafia do nagłówka. */
foreach ($GLOBALS['hooks']['wp_head'] ?? [] as $cb) $cb();

/* Te same wartości, które na prawdziwej stronie jadą przez
   `wp_localize_script`. Fixture MUSI je dostać stąd, a nie mieć własnej kopii:
   przy dwóch kopiach nagłówek liczy inną siłą niż skrypt, a sprawdzenie
   porównujące oba wzory zapala się na różnicy, której w kodzie nie ma.
   Zdarzyło się to przy pisaniu tego sprawdzenia i kosztowało jedno fałszywe
   rozpoznanie. */
$evk_p = EVK_Parallax::get_instance();
echo '<script>window.evkParallaxSettings = ', json_encode([
    'defaultValue' => $evk_p->get_parallax_value(),
    'defaultScale' => $evk_p->get_scale_value(),
]), ";</script>\n";
