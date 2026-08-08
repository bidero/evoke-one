<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Znaczniki z DWOMA atrybutami `class` w plikach panelu.
 *
 * Przeglądarka bierze pierwszy i po cichu ignoruje resztę: klasa dopisana jako
 * drugi atrybut nie działa, a w źródle wygląda, jakby działała. Tak weszło
 * 37 takich miejsc przy przemiataniu zakładek — w tym `is-ok` i `is-err`
 * na ramkach informacyjnych, które przez to renderowały się jako neutralne.
 * Wydane w 1.44.0 i 1.45.0, niezauważone przez żaden pomiar wyglądu.
 *
 * Skanujemy ŹRÓDŁO, nie wyrenderowaną stronę: usterka jest w znaczniku,
 * a większości tych plików harness nie renderuje. Bloki PHP maskujemy —
 * `<?php … 'a' => 'b' … ?>` w atrybucie urywa znacznik na pierwszym `>`
 * i drugi `class` wypada poza pole widzenia (na tym potknęła się pierwsza
 * wersja tego skanu: zgłosiła zero przy siedmiu realnych).
 */
require __DIR__ . '/_wp-stubs.php';

$php = '/<\?(?:php|=).*?\?>/s';
$tag = '/<[a-zA-Z][^>]*>/s';

$found = [];
$files = array_merge(
    glob(EVK_TEST_ROOT . '/includes/admin/*.php'),
    glob(EVK_TEST_ROOT . '/includes/admin/*/*.php'),
    glob(EVK_TEST_ROOT . '/includes/*.php')
);

foreach ($files as $file) {
    $src    = file_get_contents($file);
    $masked = preg_replace($php, '@@PHP@@', $src);
    if (!preg_match_all($tag, $masked, $m)) continue;
    foreach ($m[0] as $t) {
        if (preg_match_all('/\sclass\s*=/', $t, $c) > 1) {
            $found[] = [
                'file' => str_replace(EVK_TEST_ROOT . '/', '', $file),
                'tag'  => substr(preg_replace('/\s+/', ' ', $t), 0, 90),
            ];
        }
    }
}

echo json_encode(['count' => count($found), 'items' => array_slice($found, 0, 8)],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
