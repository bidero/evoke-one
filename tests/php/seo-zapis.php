<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Zapis meta SEO — czy w ogóle pyta o nonce.
 *
 * Do 1.129.0 oba punkty (`evoke_save_seo_ajax`, `evoke_save_seo_bulk`)
 * sprawdzały wyłącznie uprawnienie. Nonce jechał z panelu od dawna
 * (`window.evoSeoAjax.nonce` w `assets/admin/admin.js`), tylko serwer go nie
 * czytał — ktoś zaczął to robić i nie dokończył.
 *
 * Skutkiem był CSRF: zalogowany administrator odwiedzający cudzą stronę mógł
 * w tle dostać nadpisane tytuły, opisy i `robots` — MASOWO, bo zapis zbiorczy
 * przyjmuje tablicę wierszy z dowolnymi `post_id`. `noindex` na wszystkim to
 * skasowanie widoczności serwisu w wyszukiwarce.
 *
 * Atrapa nonce'a niczego nie weryfikuje, więc test nie sprawdza „czy nonce jest
 * poprawny" — sprawdza DWIE rzeczy, których atrapa nie zafałszuje: że handler
 * o nonce PYTA i że pyta o TĘ nazwę, którą drukuje `wp_localize_script()`.
 * Własna nazwa oznaczałaby, że każdy zapis pada w produkcji, a test i tak
 * świeciłby na zielono.
 */
require __DIR__ . '/_wp-stubs.php';

$GLOBALS['meta'] = [];
function update_post_meta($pid, $key, $value) {
    $GLOBALS['meta'][$pid][$key] = $value;
    return true;
}
function get_post_meta($pid, $key = '', $single = false) {
    return $GLOBALS['meta'][$pid][$key] ?? '';
}
function has_post_thumbnail($pid = null) { return false; }
function get_the_post_thumbnail_url($pid = null, $size = 'full') { return ''; }
function get_permalink($pid = null) { return 'https://example.test/wpis/'; }
function get_bloginfo($co = 'name') { return 'Serwis testowy'; }
function home_url($path = '') { return 'https://example.test' . $path; }
function is_singular() { return true; }
function is_home() { return false; }
function get_queried_object_id() { return 0; }
function get_post_type($pid = null) { return 'page'; }
function wp_get_document_title() { return 'Tytuł'; }

/* Atrapa nonce'a, która potrafi ODMÓWIĆ — inaczej „handler pyta o nonce"
   i „handler go ignoruje" wyglądałyby w teście identycznie. Oryginał przy
   `$die = true` kończy żądanie; tu rzucamy wyjątkiem. */
class EVK_Test_Nonce extends Exception {}
function check_ajax_referer($action, $field = false, $die = true) {
    $GLOBALS['nonce_asked'] = $action;
    $przyslany = $field ? ($_POST[$field] ?? $_GET[$field] ?? '') : '';
    if ($przyslany !== 'testnonce') {
        if ($die) throw new EVK_Test_Nonce('bad nonce');
        return false;
    }
    return 1;
}

require_once EVK_TEST_ROOT . '/includes/85-seo.php';

/** Woła punkt zapisu i mówi, czym się skończył. */
function zapisz(string $akcja, array $post): array {
    $handler = null;
    foreach ($GLOBALS['hooks']['wp_ajax_' . $akcja] ?? [] as $cb) { $handler = $cb; }
    $_POST = $post;
    $GLOBALS['nonce_asked'] = null;
    try {
        $handler();
    } catch (EVK_Test_Nonce $e) {
        return ['co' => 'odbity_nonce', 'pytano_o' => $GLOBALS['nonce_asked']];
    } catch (EVK_Test_Json $e) {
        return ['co' => $e->payload['success'] ? 'zapisano' : 'odmowa',
                'pytano_o' => $GLOBALS['nonce_asked'], 'dane' => $e->payload['data'] ?? null];
    }
    return ['co' => 'bez_odpowiedzi', 'pytano_o' => $GLOBALS['nonce_asked']];
}

$WIERSZ = [
    'post_id'      => 42,
    'seo_title'    => 'Tytuł strony',
    'seo_desc'     => 'Opis strony',
    'seo_keywords' => 'a, b',
    'seo_robots'   => json_encode(['noindex', 'follow']),
];

$out = [];

// ── Bez nonce'a ───────────────────────────────────────────────────────────
$GLOBALS['meta'] = [];
$out['pojedynczy_bez_nonce'] = zapisz('evoke_save_seo_ajax', $WIERSZ);
$out['zbiorczy_bez_nonce']   = zapisz('evoke_save_seo_bulk', [
    'rows' => json_encode([['post_id' => 42, 'seo_title' => 'Z CSRF']]),
]);
$out['meta_po_probach_bez_nonce'] = $GLOBALS['meta'];

// ── Z nonce'em ────────────────────────────────────────────────────────────
$GLOBALS['meta'] = [];
$out['pojedynczy_z_nonce'] = zapisz('evoke_save_seo_ajax', $WIERSZ + ['nonce' => 'testnonce']);
$out['meta_pojedynczy']    = $GLOBALS['meta'][42] ?? null;

$GLOBALS['meta'] = [];
$out['zbiorczy_z_nonce'] = zapisz('evoke_save_seo_bulk', [
    'nonce' => 'testnonce',
    'rows'  => json_encode([
        ['post_id' => 7,  'seo_title' => 'Siódemka', 'seo_robots' => ['index']],
        ['post_id' => 11, 'seo_title' => 'Jedenastka', 'seo_robots' => ['nosnippet', 'wymyslona-wartosc']],
    ]),
]);
$out['meta_zbiorczy'] = $GLOBALS['meta'];

// ── Bez uprawnień, ale z nonce'em ─────────────────────────────────────────
$GLOBALS['caps'] = ['edit_posts' => true];
$out['bez_uprawnien'] = zapisz('evoke_save_seo_ajax', $WIERSZ + ['nonce' => 'testnonce']);
$GLOBALS['caps'] = null;

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
