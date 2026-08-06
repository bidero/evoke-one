<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Co Animator faktycznie wystawia na stronę dla danej biblioteki.
 *
 * Idzie PRAWDZIWĄ ścieżką: sanitize_settings() → get_option() → enqueue_assets(),
 * na atrapach WordPressa zapisujących enqueue'y. Sprawdzamy przez to dwie rzeczy,
 * których nie widać z przeglądarki: że wtyczki GSAP dociągają się tylko tam,
 * gdzie są użyte, i że lista słów przeżywa zapis w panelu.
 *
 * Argument: JSON z tablicą wierszy biblioteki.
 */
require __DIR__ . '/_wp-stubs.php';

define('EVOKE_ONE_URL',     'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', 'test');

function evk_preserve_toggle($input, $key, $field = 'enabled', $default = 0) {
    return isset($input[$field]) ? (int) !empty($input[$field]) : $default;
}
function esc_textarea($s) { return $s; }
function bricks_is_builder_main() { return false; }

require EVK_TEST_ROOT . '/includes/anim/animator.php';

$rows = json_decode($argv[1] ?? '[]', true) ?: [];
$anim = EVK_Animator::get_instance();

// Przez sanityzację, a nie obok niej — to ona decyduje, co ląduje w opcji.
$GLOBALS['options']['evk_animator'] = $anim->sanitize_settings([
    'enabled'    => 1,
    'animations' => $rows,
]);

$anim->enqueue_assets();

$payload = null;
foreach ($GLOBALS['inline'] as $item) {
    if ($item['handle'] === 'evk-animator'
        && preg_match('/^window\.evkAnimator = (.*);$/s', $item['data'], $m)) {
        $payload = json_decode($m[1], true);
    }
}

echo json_encode([
    'deps'    => $GLOBALS['enqueued']['evk-animator']['deps'] ?? null,
    'saved'   => $GLOBALS['options']['evk_animator']['animations'] ?? [],
    'library' => $payload['library'] ?? null,
], JSON_UNESCAPED_UNICODE), "\n";
