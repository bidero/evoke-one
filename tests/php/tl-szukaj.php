<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Wyszukiwarka na wersjach językowych (1.259.0) — stan dla testu tl-szukaj.
 *
 *   php tests/php/tl-szukaj.php wp
 *   php tests/php/tl-szukaj.php przygotuj http://127.0.0.1:<port>
 *   php tests/php/tl-szukaj.php przeplucz http://127.0.0.1:<port>
 *   php tests/php/tl-szukaj.php szukaj <pl|en|de> "<fraza>"   (WP_Query w procesie, gość)
 *   php tests/php/tl-szukaj.php panel en "<fraza>"             (to samo z WP_ADMIN)
 *   php tests/php/tl-szukaj.php sprzataj
 *
 * Wpisy (litera: polski / tłumaczenia), daty rosną od A do B:
 *   A strona „Kontakt”, „Napisz do nas.” / EN „Contact”, „Write to us.” /
 *     DE treść „Schreiben Sie uns.” — najstarszy
 *   C „Formularz kontaktowy” / EN „Contact form”, „Fill in the fields.”
 *   B „Oferta”, „Nasze usługi.” / EN „Offer”, „Contact us for details.” — najnowszy
 *   D z hasłem / EN „Contact secret”
 *   E „Galeria” bez tłumaczeń
 * Wynik to litery w kolejności WP_Query — inne wpisy testowej bazy odpadają.
 */

if (in_array($argv[1] ?? '', ['przygotuj', 'przeplucz'], true) && preg_match('#^http://127\.0\.0\.1:\d+$#', $argv[2] ?? '')) {
    define('WP_HOME', $argv[2]);
    define('WP_SITEURL', $argv[2]);
}
if (($argv[1] ?? '') === 'panel') define('WP_ADMIN', true);
require __DIR__ . '/_testowy-wp.php';

$krok   = $argv[1] ?? '';
$plik   = sys_get_temp_dir() . '/evk-t-tl-szukaj.json';
$mu     = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
$muPlik = $mu . '/evk-t-tl-szukaj.php';
$opcje  = ['permalink_structure', 'evk_tl_module_enabled', 'tl_languages'];
$out    = ['krok' => $krok];

switch ($krok) {

case 'wp':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'przygotuj':
    if (!defined('WP_HOME')) { $out['brak'] = 'przygotuj bez adresu serwera testowego'; break; }
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'wpisy' => [], 'mu_bylo' => is_dir($mu)]));
    }
    $zapis = json_decode((string) file_get_contents($plik), true);

    if (!is_dir($mu)) mkdir($mu, 0755, true);
    file_put_contents($muPlik, "<?php\n// Wyłącznie test tl-szukaj (tests/php/tl-szukaj.php) — usuwany po teście.\n"
        . "add_action('wp_footer', function () { if (!is_search()) return; global \$wp_query;\n"
        . "    printf('<i id=\"evk-t-szukaj\" data-ids=\"%s\"></i>', esc_attr(implode(',', wp_list_pluck(\$wp_query->posts, 'ID')))); }, 999);\n");

    $wpisy = [
        'A' => ['post_type' => 'page', 'post_title' => 'Kontakt SZK', 'post_content' => 'Napisz do nas.', 'post_date' => '2026-01-01 10:00:00',
                'tl' => ['en' => ['post_title' => 'Contact SZK', 'post_content' => 'Write to us.'], 'de' => ['post_content' => 'Schreiben Sie uns.']]],
        'C' => ['post_title' => 'Formularz kontaktowy SZK', 'post_content' => 'Wypełnij pola.', 'post_date' => '2026-02-01 10:00:00',
                'tl' => ['en' => ['post_title' => 'Contact form SZK', 'post_content' => 'Fill in the fields.']]],
        'B' => ['post_title' => 'Oferta SZK', 'post_content' => 'Nasze usługi.', 'post_date' => '2026-03-01 10:00:00',
                'tl' => ['en' => ['post_title' => 'Offer SZK', 'post_content' => 'Contact us for details.']]],
        'D' => ['post_title' => 'Tajne SZK', 'post_content' => 'Tylko z hasłem.', 'post_password' => 'haslo', 'post_date' => '2026-01-15 10:00:00',
                'tl' => ['en' => ['post_title' => 'Contact secret SZK']]],
        'E' => ['post_title' => 'Galeria SZK', 'post_content' => 'Zdjęcia.', 'post_date' => '2026-01-20 10:00:00', 'tl' => []],
    ];
    $mapa = [];
    foreach ($wpisy as $litera => $w) {
        $tl = $w['tl'];
        unset($w['tl']);
        $id = (int) wp_insert_post($w + ['post_status' => 'publish']);
        foreach ($tl as $lang => $pola) {
            foreach ($pola as $pole => $wartosc) update_post_meta($id, '_evk_tl_' . $lang . '__' . $pole, $wartosc);
        }
        $mapa[$litera] = $id;
    }
    $zapis['wpisy'] = array_merge($zapis['wpisy'], array_values($mapa));
    $zapis['mapa']  = $mapa;
    file_put_contents($plik, wp_json_encode($zapis));

    update_option('tl_languages', [
        ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'],
        ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
    ]);
    update_option('evk_tl_module_enabled', 1);
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
    flush_rewrite_rules(false);
    $out['mapa']   = $mapa;
    $out['gotowe'] = count(array_filter($mapa)) === 5;
    break;

case 'przeplucz':
    flush_rewrite_rules(false);
    $out['tl'] = function_exists('evk_tlw_szukaj_kolumny');
    break;

case 'szukaj':
case 'panel':
    $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    $litery = array_flip(array_map('intval', (array) ($zapis['mapa'] ?? [])));
    wp_set_current_user(0);
    $lang = (string) ($argv[2] ?? 'pl');
    $GLOBALS['lang_code'] = $lang === 'pl' ? '' : $lang;
    $q = new WP_Query(['s' => (string) ($argv[3] ?? ''), 'post_type' => 'any', 'posts_per_page' => 100, 'fields' => 'ids']);
    $out['litery'] = implode('', array_map(static function ($id) use ($litery) { return $litery[(int) $id] ?? ''; }, $q->posts));
    $out['admin']  = is_admin();
    break;

case 'sprzataj':
    $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['wpisy'] ?? []) as $id) wp_delete_post((int) $id, true);
    @unlink($muPlik);
    if (empty($zapis['mu_bylo'])) @rmdir($mu);
    $GLOBALS['wp_rewrite']->init();
    flush_rewrite_rules(false);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
