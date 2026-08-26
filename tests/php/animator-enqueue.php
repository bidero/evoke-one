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
 * Argument 1: JSON z tablicą wierszy biblioteki.
 * Argument 2: kontekst — `front` (domyślnie), `powloka`, `kanwa`.
 * Argument 3: `1` = zaznaczone „Animuj w builderze".
 */
require __DIR__ . '/_wp-stubs.php';

define('EVOKE_ONE_URL',     'https://example.test/wp-content/plugins/evoke-one/');
define('EVOKE_ONE_VERSION', 'test');

function evk_preserve_toggle($input, $key, $field = 'enabled', $default = 0) {
    return isset($input[$field]) ? (int) !empty($input[$field]) : $default;
}
function esc_textarea($s) { return $s; }

/*
 * KONTEKST BUDOWANIA STRONY.
 *
 * `kanwa` ustawia WYŁĄCZNIE `?bricks=run` i zostawia obie funkcje motywu na
 * fałszu. To najostrzejszy z możliwych przypadków i dokładnie ten, którego
 * dotychczasowy warunek nie łapał: powłoka mówi „jestem builderem", ramka nie
 * mówi nic, a to właśnie ramka rysuje treść.
 */
$GLOBALS['kontekst'] = $argv[2] ?? 'front';
if ($GLOBALS['kontekst'] === 'kanwa') { $_GET['bricks'] = 'run'; }

function bricks_is_builder_main() { return $GLOBALS['kontekst'] === 'powloka'; }
function bricks_is_builder()      { return $GLOBALS['kontekst'] === 'powloka'; }

require EVK_TEST_ROOT . '/includes/00-context-safety.php';
require EVK_TEST_ROOT . '/includes/anim/animator.php';

$rows = json_decode($argv[1] ?? '[]', true) ?: [];
$anim = EVK_Animator::get_instance();

// Przez sanityzację, a nie obok niej — to ona decyduje, co ląduje w opcji.
$GLOBALS['options']['evk_animator'] = $anim->sanitize_settings([
    'enabled'         => 1,
    'animations'      => $rows,
    'builder_preview' => !empty($argv[3]) ? 1 : 0,
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
    // Czy silnik w ogóle wszedł na stronę w tym kontekście.
    'wystawiony' => isset($GLOBALS['enqueued']['evk-animator']),
    'kontekst'   => $GLOBALS['kontekst'],
    'deps'    => $GLOBALS['enqueued']['evk-animator']['deps'] ?? null,
    'saved'   => $GLOBALS['options']['evk_animator']['animations'] ?? [],
    'library' => $payload['library'] ?? null,
], JSON_UNESCAPED_UNICODE), "\n";
