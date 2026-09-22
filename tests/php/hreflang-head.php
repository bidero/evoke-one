<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Tagi `hreflang` w `<head>` — drugie źródło deklaracji językowych.
 *
 * PO CO OSOBNA SONDA. Mapa strony i `<head>` mówią wyszukiwarce to samo
 * i muszą mówić to samo: rozbieżność jest sygnałem sprzecznym, rozstrzyganym
 * po stronie wyszukiwarki, a nie po naszej. Sekcję mapy sprawdza
 * `sitemap-hreflang.php`; tu jest ta druga połowa, do 1.223.2 nie sprawdzana
 * przez nic.
 *
 * Argument 1: kod języka dla `x-default` (`pl` albo `en`).
 */
require __DIR__ . '/_wp-stubs.php';

$x_default = $argv[1] ?? 'pl';

function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function home_url($path = '')  { return 'https://example.test' . $path; }
function get_post($id = 0)     { return null; }
function get_posts($args = []) { return []; }
function get_post_meta($pid, $key = '', $single = false) { return $key === '' ? [] : ($single ? '' : []); }

/* Silnik języków — tyle, ile czyta moduł `<head>`. Ścieżki przekładamy
   słownikiem, tak jak robi to prawdziwy `tl_translate_url_path()`. */
function tl_is_bricks_editor() { return false; }
function get_current_lang()    { return 'pl'; }
function tl_get_languages(): array {
    return [
        'en' => ['name' => 'Angielski', 'html' => 'en'],
        'de' => ['name' => 'Niemiecki', 'html' => 'de-DE'],
    ];
}
function tl_remove_lang_prefix_from_path($path) { return $path; }
function tl_get_original_path_from_translated($path, $lang) { return $path; }
function tl_translate_url_path($path, $z, $do) {
    $slowniki = ['/oferta/' => ['en' => '/offer/', 'de' => '/angebot/']];
    return $slowniki[$path][$do] ?? $path;
}

require_once EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';
require_once EVK_TEST_ROOT . '/includes/80-sitemap.php';   // evk_sitemap_jezyk_domyslny()
require_once EVK_TEST_ROOT . '/includes/12-seo-url-filters.php';

$GLOBALS['options']['home'] = 'https://example.test';
$GLOBALS['options']['tl_sitemap_settings'] = ['hreflang_default' => $x_default];
$_SERVER['REQUEST_URI'] = '/oferta/';

$head = evk_test_fire('wp_head');

/* Wyjście rozkładamy na pary [tag => adres] — test ma mówić o deklaracjach,
   a nie o kolejności znaków w markupie. */
preg_match_all('#<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"#', $head, $m, PREG_SET_ORDER);
$tagi = [];
foreach ($m as $dopasowanie) $tagi[] = ['tag' => $dopasowanie[1], 'url' => $dopasowanie[2]];

preg_match('#<link rel="canonical" href="([^"]+)"#', $head, $c);

echo json_encode([
    'tagi'      => $tagi,
    'canonical' => $c[1] ?? '',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
