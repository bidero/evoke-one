<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Dopasowanie klas CSS przy View Transitions na liście wpisów.
 *
 * Bricks przechowuje `_cssClasses` raz jako TABLICĘ, raz jako JEDEN ŁAŃCUCH —
 * zależnie od tego, którędy element powstał. Moduł musi rozpoznać oba kształty,
 * bo inaczej u części użytkowników przejścia po prostu nie działają i nic o tym
 * nie mówi.
 *
 * Wołamy PRAWDZIWY `inject_post_trans_attrs()`, ten sam, który Bricks podpina
 * pod `bricks/element/render_attributes`. Kopia logiki w atrapie sprawdzałaby
 * kopię: martwy warunek `is_string()` po rzutowaniu `(array)` przeżył w kodzie
 * kilkanaście wydań właśnie dlatego, że nikt nie pytał funkcji o wynik.
 *
 * Argument 1: JSON `{"klasy": <string|array>, "szukane": "hero-title"}`.
 */
require __DIR__ . '/_wp-stubs.php';

function evk_preserve_toggle($input, $key, $field = 'enabled', $default = 0) {
    return isset($input[$field]) ? (int) !empty($input[$field]) : $default;
}

require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';

$wejscie  = json_decode($argv[1] ?? '{}', true) ?: [];
$szukane  = $wejscie['szukane'] ?? 'hero-title';

$GLOBALS['options']['evk_darkmode'] = [
    'enabled'                 => 1,
    'post_trans_enabled'      => 1,
    'post_trans_title_class'  => $szukane,
    'post_trans_image_class'  => 'hero-img',
];

/* Pętla wpisów, nie singiel — funkcja obsługuje wyłącznie listę (na singlu
   przejścia jadą CSS-em z `wp_head`). */
function in_the_loop() { return true; }
function is_singular() { return false; }
function get_the_ID() { return 42; }

require EVK_TEST_ROOT . '/includes/93-darkmode.php';

$element = (object) ['settings' => ['_cssClasses' => $wejscie['klasy'] ?? []]];

/* Atrybuty w kształcie, w jakim podaje je Bricks: pogrupowane po kluczu. */
$wynik = EVK_DarkMode::get_instance()->inject_post_trans_attrs(
    ['_root' => ['class' => ['brxe-heading']]],
    '_root',
    $element
);

echo json_encode([
    'style'    => $wynik['_root']['style'] ?? null,
    'dopasowane' => isset($wynik['_root']['style']),
], JSON_UNESCAPED_UNICODE), "\n";
