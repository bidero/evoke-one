<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Sekcja `hreflang` w mapie — prawdziwe wyjście XML.
 *
 * OSOBNA SONDA, bo renderer kończy się `exit`: musi, skoro wypisuje cały
 * dokument zamiast szablonu strony. `exit` nie da się przechwycić, więc
 * wywołanie tej funkcji musi mieć proces dla siebie i całym wyjściem sondy
 * jest XML.
 *
 * Argumenty (test woła sondę kilka razy, za każdym razem inaczej):
 *   1. kod języka dla `x-default` — `pl` albo `en`
 *   2. tryb: `tylko-tlumaczone` (pomijaj wersje bez przetłumaczonego sluga)
 *      albo `inna-sekcja` (żądanie innej sekcji mapy — renderer ma NIE
 *      przechwycić i zejść z drogi rdzeniowi)
 */
require __DIR__ . '/_wp-stubs.php';

$x_default = $argv[1] ?? 'pl';
$tryb      = $argv[2] ?? '';

function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function trailingslashit($s)   { return rtrim((string) $s, '/\\') . '/'; }
function home_url($path = '')  { return 'https://example.test' . $path; }
function post_type_exists($t)  { return in_array($t, ['page', 'post'], true); }
function get_post_meta($pid, $key = '', $single = false) { return $key === '' ? [] : ($single ? '' : []); }
function get_post_ancestors($id) { return []; }
function get_post($id = 0) { return null; }
function get_permalink($id = 0) { return 'https://example.test/'; }

function get_query_var($klucz, $domyslna = '') {
    return $GLOBALS['query_vars'][$klucz] ?? $domyslna;
}

/* Serwer map rdzenia — potrzebny wyłącznie po to, by sekcja wzięła stamtąd
   adres arkusza stylów. Bez niego plik jest poprawnym XML-em, który Safari
   pokazuje jako zlepek adresów i dat, a Chrome jako drzewko z ostrzeżeniem
   „no style information" — czyli wygląda na zepsuty, będąc poprawnym. */
function wp_sitemaps_get_server() {
    return new class {
        public $renderer;
        public function __construct() {
            $this->renderer = new class {
                public function get_sitemap_stylesheet_url() { return 'https://example.test/wp-sitemap.xsl'; }
            };
        }
    };
}

/* Silnik języków — atrapa odwzorowująca to, co robi prawdziwy: nieznany slug
   oddaje bez zmian, i właśnie po tym `tl_has_translated_path()` poznaje brak
   tłumaczenia. */
function tl_get_languages(): array {
    return [
        'en' => ['name' => 'Angielski', 'html' => 'en-US'],
        'de' => ['name' => 'Niemiecki', 'html' => 'de-DE'],
    ];
}
function tl_translate_slug($slug, $lang) {
    $slowniki = [
        'o-nas'   => ['en' => 'about-us', 'de' => 'ueber-uns'],
        'kontakt' => ['en' => 'contact'], // po niemiecku brak — celowo
    ];
    return $slowniki[$slug][$lang] ?? $slug;
}

/* Wpisy jako WP_Post, bo `tl_get_post_pl_path()` ma tę klasę w sygnaturze.
   Atrapa na `stdClass` przechodzi cicho tylko do pierwszej deklaracji typu —
   a ta już tam jest. */
class WP_Post {
    public $ID = 0;
    public $post_type = 'page';
    public $post_name = '';
    public $post_parent = 0;
    public $post_modified_gmt = '';
    public function __construct(array $pola = []) {
        foreach ($pola as $k => $v) $this->$k = $v;
    }
}

function get_posts($args = []) {
    return [
        new WP_Post(['ID' => 11, 'post_name' => 'o-nas',   'post_modified_gmt' => '2026-04-01 09:00:00']),
        new WP_Post(['ID' => 12, 'post_name' => 'kontakt', 'post_modified_gmt' => '2026-04-02 09:00:00']),
    ];
}

require_once EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';
require_once EVK_TEST_ROOT . '/includes/80-sitemap.php';

$GLOBALS['options']['home'] = 'https://example.test';
$GLOBALS['options']['tl_sitemap_settings'] = [
    'enabled'               => 1,
    'include_home'          => 1,
    'include_pages'         => 1,
    'include_posts'         => 0,
    'include_polish'        => 1,
    'only_translated_slugs' => $tryb === 'tylko-tlumaczone' ? 1 : 0,
    'auto_exclude_noindex'  => 0,
    'excluded_ids'          => [],
    'hreflang_default'      => $x_default,
];

$GLOBALS['query_vars'] = [
    'sitemap' => $tryb === 'inna-sekcja' ? 'posts' : 'hreflang',
    'paged'   => 1,
];

evk_sitemap_hreflang_renderuj();

/* Dotąd dochodzi wyłącznie żądanie, którego renderer NIE przejął — przy
   przejęciu wcześniej wypada `exit`. Znacznik mówi to wprost, zamiast zostawiać
   puste wyjście do interpretacji. */
echo 'NIE-PRZEJETO';
