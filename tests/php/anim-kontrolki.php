<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * KSZTAŁT kontrolek dokładanych filtrem do KAŻDEGO elementu Bricks.
 *
 * Nie o wartości tu chodzi — te sprawdza tests/php/controls.php przez filtr
 * atrybutów — tylko o to, czego w definicji NIE MA i co w niej jest.
 *
 * ZGŁOSZONE Z UŻYCIA: „gdy dodam nowy element w builderze, zawsze pojawia się
 * żółta kropka obok atrybutów, tak jakby było coś ustawione. A ani parallax,
 * ani animacje nie są". Kropka w Bricks znaczy „ta grupa ma zapisane
 * ustawienia", a kontrolka z kluczem `default` raportuje wartość także wtedy,
 * gdy w ustawieniach elementu nie ma nic. Kontrolki Evoke siedzą w natywnej
 * grupie Atrybuty i wchodzą do każdego elementu, więc to one tam tę kropkę
 * zapalały — na każdym świeżo wstawionym elemencie.
 *
 * Tego nie widać ani w HTML, ani w przeglądarce: panel buildera jest aplikacją
 * Vue w drugim oknie. Widać to wyłącznie stąd, po kształcie tablicy.
 */
require __DIR__ . '/_wp-stubs.php';

function evk_preserve_toggle($input, $key, $field = 'enabled', $default = 0) {
    return isset($input[$field]) ? (int) !empty($input[$field]) : $default;
}
function evk_register_gsap_libs() {}
function esc_textarea($s) { return $s; }
function bricks_is_builder_main() { return false; }

define('EVOKE_ONE_URL',     'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', 'test');

// Wszystkie trzy moduły włączone — inaczej część kontrolek nie powstaje
// i „żadna nie ma default" byłoby prawdą przez nieobecność.
$GLOBALS['options']['evk_animator'] = [
    'enabled'    => 1,
    'animations' => [['slug' => 'wejscie', 'preset' => 'fade-up', 'trigger' => 'viewport']],
];
$GLOBALS['options']['evk_bgshift']  = ['enabled' => 1];
$GLOBALS['options']['evk_parallax'] = ['enabled' => 1];

require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';
require EVK_TEST_ROOT . '/includes/anim/motion.php';
require EVK_TEST_ROOT . '/includes/anim/bgshift.php';
require EVK_TEST_ROOT . '/includes/anim/presets.php';
require EVK_TEST_ROOT . '/includes/92-parallax.php';
require EVK_TEST_ROOT . '/includes/anim/animator.php';
require EVK_TEST_ROOT . '/includes/anim/bricks-controls.php';

/* Grupa docelowa wykrywana jest z tablicy grup, którą Bricks podaje filtrowi —
   podajemy ją tak, jak podaje ją builder, żeby kontrolki trafiły tam, gdzie
   trafiają naprawdę (grupa Atrybuty), a nie do zapasowej własnej. */
evk_bricks_control_groups(['_attributes' => ['title' => 'Attributes']]);

$controls = evk_bricks_controls([]);

/** Płaska lista `[ścieżka => definicja]`, razem z polami wierszy repeaterów. */
function evk_test_splaszcz(array $controls, string $prefiks = ''): array {
    $out = [];
    foreach ($controls as $nazwa => $def) {
        if (!is_array($def)) continue;
        $out[$prefiks . $nazwa] = $def;
        if (!empty($def['fields']) && is_array($def['fields'])) {
            $out += evk_test_splaszcz($def['fields'], $prefiks . $nazwa . '/');
        }
    }
    return $out;
}

$plaskie = evk_test_splaszcz($controls);

echo json_encode([
    // Pokrycie: bez tej liczby „żadna nie ma default" przechodziłoby także
    // wtedy, gdyby filtr nie dołożył ani jednej kontrolki.
    'kontrolek'   => count($plaskie),
    'grupy'       => array_values(array_unique(array_filter(
        array_column($controls, 'group')))),
    'zDefault'    => array_keys(array_filter(
        $plaskie, fn($d) => array_key_exists('default', $d))),
    'szukalne'    => array_keys(array_filter($plaskie, fn($d) => !empty($d['searchable']))),
    // Lista select-ów w ogóle — do kontroli negatywnej: `searchable` ma być
    // na długich listach, a nie na wszystkim jak leci.
    'selecty'     => array_keys(array_filter(
        $plaskie, fn($d) => ($d['type'] ?? '') === 'select')),
    // Pierwsza pozycja listy animacji jest tym, co kontrolka bez `default`
    // pokaże — musi znaczyć „nic nie wybrano".
    'pierwszaAnimacja' => array_key_first($plaskie['evkAnimList/animation']['options'] ?? ['brak' => 1]),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
