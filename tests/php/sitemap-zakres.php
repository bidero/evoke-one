<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Zakres mapy strony — typy treści, taksonomie, użytkownicy, kotwice.
 *
 * Sonda pracuje na PRAWDZIWYCH filtrach modułu: wyciąga callback z rejestru
 * atrap i woła go z takim argumentem, z jakim wołałby go WordPress. Dzięki
 * temu sprawdzenie pada, gdy zmieni się nazwa hooka albo kształt argumentu —
 * a nie tylko wtedy, gdy ktoś przepisze opis w komentarzu.
 *
 * Dwie rzeczy, które trzeba tu wiedzieć:
 *
 * 1. FLAGA Z EVOKE FIELDS jest symulowana funkcją `evk_noindex_post_types()`
 *    zdefiniowaną niżej. Moduł pyta o nią przez `function_exists()`, bo FIELDS
 *    to osobna wtyczka i na połowie stron jej nie ma. Sonda definiuje ją PRZED
 *    załadowaniem modułu, czyli odtwarza wariant „obie wtyczki zainstalowane".
 *
 * 2. SANITYZACJA ZOSTAWIA KLUCZE, KTÓRYCH NIE DOSTAŁA. Ten sam wpis w opcjach
 *    zapisują dwa ekrany panelu i tylko jeden z nich zna nowe pola; budowanie
 *    tablicy od zera kasowałoby ustawienia drugiego ekranu po cichu.
 */
require __DIR__ . '/_wp-stubs.php';

// ── Atrapy, których potrzebuje moduł ─────────────────────────────────────
function untrailingslashit($s) { return rtrim((string) $s, '/'); }
function post_type_exists($t)  { return in_array($t, ['page', 'post', 'slajd', 'menu', 'realizacja'], true); }
function get_permalink($id = 0) {
    $strony = [7 => 'https://example.test/oferta/', 9 => 'https://example.test/o-nas/'];
    return $strony[(int) $id] ?? false;
}
/* Bez klucza — mapa wszystkich metadanych (tego woła skan noindex);
   z kluczem i `$single` — goła wartość, jak w oryginale. Atrapa oddająca
   tablicę w obu przypadkach kazałaby PHP-owi rzutować ją na łańcuch
   i zasypywała wyjście ostrzeżeniami, w których ginie wynik sondy. */
function get_post_meta($pid, $key = '', $single = false) {
    if ($key === '') return $GLOBALS['meta'][$pid] ?? [];
    $wartosc = $GLOBALS['meta'][$pid][$key] ?? '';
    return $single ? $wartosc : (array) $wartosc;
}
function get_the_title($p = 0) { return 'Tytuł'; }
function has_post_thumbnail($p = null) { return false; }
function get_the_post_thumbnail_url($p = null, $size = 'full') { return ''; }
function get_post_type($p = null) { return $GLOBALS['typ_biezacy'] ?? 'page'; }

/** Wpisy typu — tyle, ile potrzebuje budowanie kotwic. */
function get_posts($args = []) {
    $typ = (array) ($args['post_type'] ?? []);
    $baza = [
        'menu'  => [
            (object) ['ID' => 101, 'post_name' => 'danie-dnia',  'post_modified_gmt' => '2026-01-05 10:00:00'],
            (object) ['ID' => 102, 'post_name' => 'zupy',        'post_modified_gmt' => '2026-01-06 10:00:00'],
            (object) ['ID' => 103, 'post_name' => '',            'post_modified_gmt' => '2026-01-07 10:00:00'],
        ],
        'slajd' => [
            (object) ['ID' => 201, 'post_name' => 'tlo-hero', 'post_modified_gmt' => '2026-02-01 10:00:00'],
        ],
    ];
    $out = [];
    foreach ($typ as $t) {
        foreach ($baza[$t] ?? [] as $wpis) $out[] = $wpis;
    }
    return $out;
}

/* Flaga z Evoke FIELDS — wariant „obie wtyczki zainstalowane". */
function evk_noindex_post_types(): array { return ['slajd']; }
function evk_noindex_taxonomies(): array { return ['kolor-slajdu']; }

require_once EVK_TEST_ROOT . '/includes/30-admin-settings-ajax.php';
require_once EVK_TEST_ROOT . '/includes/80-sitemap.php';
require_once EVK_TEST_ROOT . '/includes/85-seo.php';

/** Callback filtra spod hooka (n-ty w kolejności rejestracji). */
function filtr(string $hook, int $idx = 0) {
    $cb = $GLOBALS['hooks'][$hook][$idx] ?? null;
    if (!$cb) throw new RuntimeException('Brak callbacku pod hookiem ' . $hook . ' [' . $idx . ']');
    return $cb;
}

/** Ustawia opcję mapy strony (pełny zapis, z pominięciem sanityzacji). */
function ustaw(array $ustawienia): void {
    $GLOBALS['options']['tl_sitemap_settings'] = $ustawienia;
}

$GLOBALS['options']['home'] = 'https://example.test';

$out = [];

// ── Typy treści i taksonomie ─────────────────────────────────────────────
ustaw([
    'excluded_types'      => ['realizacja'],
    'noindex_taxonomies'  => ['rodzaj'],
]);

$typy_wejscie = ['page' => 'page', 'post' => 'post', 'slajd' => 'slajd', 'menu' => 'menu', 'realizacja' => 'realizacja'];
$out['typy_w_mapie'] = array_keys(call_user_func(filtr('wp_sitemaps_post_types'), $typy_wejscie));

$taks_wejscie = ['category' => 'category', 'post_tag' => 'post_tag', 'rodzaj' => 'rodzaj', 'kolor-slajdu' => 'kolor-slajdu'];
$out['taksonomie_w_mapie'] = array_keys(call_user_func(filtr('wp_sitemaps_taxonomies'), $taks_wejscie));

// ── Sekcja użytkowników ──────────────────────────────────────────────────
$provider = new stdClass();

/* Sekcje obce pytamy przy WYŁĄCZONYCH użytkownikach — i tylko tak to pytanie
   ma sens. Filtr dostaje każdy provider po kolei; gdyby nie patrzył na nazwę,
   odznaczony checkbox użytkowników skasowałby z mapy także strony i wpisy.
   Zapytane przy włączonych użytkownikach sprawdzenie świeci na zielono
   niezależnie od tego, czy warunek nazwy w ogóle istnieje. */
ustaw(['include_users' => 0]);
$out['users_wylaczeni']     = call_user_func(filtr('wp_sitemaps_add_provider'), $provider, 'users') === false ? 'odcięty' : 'przeszedł';
$out['users_inny_provider'] = call_user_func(filtr('wp_sitemaps_add_provider'), $provider, 'posts') === $provider ? 'przeszedł' : 'odcięty';

ustaw(['include_users' => 1]);
$out['users_wlaczeni']  = call_user_func(filtr('wp_sitemaps_add_provider'), $provider, 'users') === $provider ? 'przeszedł' : 'odcięty';

// ── Wykluczone pojedyncze wpisy — także w CPT ────────────────────────────
ustaw(['excluded_ids' => [42, 77], 'auto_exclude_noindex' => 0]);
$out['wykluczenia_cpt']    = call_user_func(filtr('wp_sitemaps_posts_query_args'), [], 'realizacja');
$out['wykluczenia_strony'] = call_user_func(filtr('wp_sitemaps_posts_query_args'), ['post__not_in' => [5]], 'page');

// ── Rejestracja typu: exclude_from_search ────────────────────────────────
ustaw(['noindex_types' => ['menu']]);
$out['rejestracja_menu']  = call_user_func(filtr('register_post_type_args'), ['public' => true], 'menu');
$out['rejestracja_strony'] = call_user_func(filtr('register_post_type_args'), ['public' => true], 'page');

// ── Kotwice ──────────────────────────────────────────────────────────────
ustaw(['anchor_types' => ['menu' => 7], 'excluded_ids' => [102], 'auto_exclude_noindex' => 0]);
$out['kotwice'] = evk_sitemap_kotwice_urls();

ustaw(['anchor_types' => ['menu' => 999], 'auto_exclude_noindex' => 0]);
$out['kotwice_bez_strony'] = evk_sitemap_kotwice_urls();

ustaw(['anchor_types' => [], 'auto_exclude_noindex' => 0]);
$out['kotwice_bez_konfiguracji'] = evk_sitemap_kotwice_urls();

// ── Meta robots dla typu „poza indeksem" ─────────────────────────────────
ustaw(['noindex_types' => ['menu']]);
$GLOBALS['typ_biezacy'] = 'menu';
$out['robots_menu'] = evk_seo_get_meta(101)['robots'];
$GLOBALS['typ_biezacy'] = 'page';
$out['robots_strona'] = evk_seo_get_meta(5)['robots'];
$GLOBALS['typ_biezacy'] = 'slajd'; // flaga z FIELDS, nie z panelu
$out['robots_slajd'] = evk_seo_get_meta(201)['robots'];

// ── Sanityzacja: brak klucza nie kasuje ustawienia ───────────────────────
ustaw([
    'enabled'        => 1,
    'excluded_types' => ['realizacja'],
    'noindex_types'  => ['slajd'],
    'anchor_types'   => ['menu' => 7],
    'excluded_ids'   => [42],
]);

// Zapis ze starszego ekranu Tłumaczeń — zna wyłącznie pola sekcji tłumaczeń.
$out['zapis_starym_ekranem'] = tl_sanitize_sitemap_settings([
    'enabled'      => 0,
    'include_home' => 1,
    'excluded_ids' => [42],
]);

// Zapis z nowego ekranu — pola przychodzą, więc nadpisują.
$out['zapis_nowym_ekranem'] = tl_sanitize_sitemap_settings([
    'enabled'             => 1,
    'excluded_types'      => ['Realizacja!', ''],
    'noindex_types'       => ['slajd', 'slajd'],
    'excluded_taxonomies' => ['rodzaj'],
    'anchor_types'        => ['menu' => '7', 'cennik' => '0'],
    'excluded_ids'        => ['42', 'x', 0],
]);

// ── Martwy generator /sitemap.xml ma nie wrócić ──────────────────────────
$zrodlo = file_get_contents(EVK_TEST_ROOT . '/includes/80-sitemap.php');
$out['stary_generator'] = [
    'rewrite_rule'      => strpos($zrodlo, 'add_rewrite_rule') !== false,
    'query_var'         => isset($GLOBALS['hooks']['query_vars']),
    'template_redirect' => isset($GLOBALS['hooks']['template_redirect']),
    'robots_txt'        => call_user_func(filtr('robots_txt'), "User-agent: *\n"),
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
