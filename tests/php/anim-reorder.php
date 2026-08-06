<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Przestawianie kolejności wierszy biblioteki animacji.
 *
 * Idzie przez PRAWDZIWY handler `wp_ajax_evk_anim_reorder`, a nie przez jego
 * opis. Ważne jest głównie to, czego handler NIE może zrobić: zgubić wiersza.
 *
 * Argumenty: JSON z zapisanymi slugami, JSON z kolejnością przysłaną z panelu.
 */
require __DIR__ . '/_wp-stubs.php';

// Handler siedzi w pliku, który przy ładowaniu rejestruje mnóstwo innych rzeczy.
// Atrapy add_action/add_filter tylko je zbierają, więc nic się nie wykonuje.
function tl_get_active_lang_codes() { return ['pl', 'en']; }

require EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';

$saved = json_decode($argv[1] ?? '[]', true) ?: [];
$order = json_decode($argv[2] ?? '[]', true) ?: [];

$GLOBALS['options']['evk_animator'] = [
    'enabled'    => 1,
    'animations' => array_map(function ($slug) {
        // Etykieta niesie ślad tożsamości wiersza — po niej widać, czy handler
        // przestawił wiersze, czy je podmienił.
        return ['slug' => $slug, 'label' => 'etykieta-' . $slug, 'preset' => 'fade'];
    }, $saved),
];

$_POST = ['order' => $order];

$cb  = null;
foreach ($GLOBALS['hooks']['wp_ajax_evk_anim_reorder'] ?? [] as $c) { $cb = $c; }

$res = null;
try { $cb(); }
catch (EVK_Test_Json $e) { $res = $e->payload; }

echo json_encode([
    'response' => $res,
    'stored'   => array_map(function ($r) { return $r['slug'] . '/' . $r['label']; },
                            $GLOBALS['options']['evk_animator']['animations']),
], JSON_UNESCAPED_UNICODE), "\n";
