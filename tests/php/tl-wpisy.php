<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Tłumaczenia wpisów i stron (1.252.0) — stan między żądaniami testu tl-wpisy.
 *
 *   php tests/php/tl-wpisy.php wp
 *   php tests/php/tl-wpisy.php przygotuj http://127.0.0.1:<port>
 *   php tests/php/tl-wpisy.php przeplucz http://127.0.0.1:<port>   (osobny proces: Tłumaczenia wczytane)
 *   php tests/php/tl-wpisy.php stan                                wpisy testu: metadane, pola, mapa adresów
 *   php tests/php/tl-wpisy.php front <lang>                        tytuł, zajawka, menu w języku (bez HTTP)
 *   php tests/php/tl-wpisy.php zmien <wpis> <pole> <wartość>        wp_update_post (zmiana polskiego tekstu / adresu)
 *   php tests/php/tl-wpisy.php sprzataj
 *
 * Klasyczny edytor jak na stronach zgłaszającego — mu-plugin wyłącza edytor
 * blokowy na czas testu. Wpisy:
 *   W1  wpis: treść na dwie strony (<!--nextpage-->), zajawka, kategoria;
 *   W2  wpis: treść EN bez zajawki EN, polska zajawka jest (zajawka EN z treści EN);
 *   S0  strona-rodzic z członem w mapie (rodzic-w → parent-w);
 *   S1  strona „O nas W" pod S0 — słownik zna jej tytuł; tytuł SEO po polsku;
 *   B1  strona z treścią w Bricksie (_bricks_editor_mode = bricks);
 *   K1  strona „kontakt-w" — jej polski adres jako adres EN innego wpisu to konflikt.
 * Mapa adresów ma też „cennik-w → pricing-w" (drugi rodzaj konfliktu).
 */

$evk_krok = $argv[1] ?? '';
if (in_array($evk_krok, ['przygotuj', 'przeplucz'], true) && preg_match('#^http://127\.0\.0\.1:\d+$#', $argv[2] ?? '')) {
    define('WP_HOME', $argv[2]);
    define('WP_SITEURL', $argv[2]);
}
require __DIR__ . '/_testowy-wp.php';

$plik   = sys_get_temp_dir() . '/evk-t-tl-wpisy.json';
$mu     = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
$muPlik = $mu . '/evk-t-tl-wpisy.php';
$opcje  = ['permalink_structure', 'show_on_front', 'posts_per_page', 'evk_tl_module_enabled', 'tl_languages',
           'tl_translations', 'tl_dd_keys', 'tl_url_slugs'];
$out    = ['krok' => $evk_krok];

/** Pamięć podręczna Tłumaczeń (nazwy jak w evoke-one.php). */
function evk_t_tlw_bez_pamieci(): void {
    foreach (['tl_compiled_slugs', 'tl_compiled_config', 'tl_compiled_tokens_en', 'tl_compiled_tokens_de'] as $t) delete_transient($t);
}

$zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
$id = static function (string $k) use ($zapis): int { return (int) ($zapis['wpisy'][$k] ?? 0); };

switch ($evk_krok) {

case 'wp':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'przygotuj':
    if (!defined('WP_HOME')) { $out['brak'] = 'przygotuj bez adresu serwera testowego'; break; }
    if (!$zapis) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        $zapis = ['opcje' => $przed, 'wpisy' => [], 'terminy' => [], 'mu_bylo' => is_dir($mu)];
    }
    if (!is_dir($mu)) mkdir($mu, 0755, true);
    file_put_contents($muPlik, "<?php\n// Wyłącznie test tl-wpisy (tests/php/tl-wpisy.php) — usuwany po teście.\n"
        . "add_filter('use_block_editor_for_post_type', '__return_false', 100);\n");

    $kat = wp_insert_term('Kategoria W', 'category', ['slug' => 'kategoria-w']);
    $katId = is_wp_error($kat) ? 0 : (int) $kat['term_id'];
    $nowy = static function (array $a): int { return (int) wp_insert_post($a + ['post_status' => 'publish']); };
    $w = [];
    $w['W1'] = $nowy(['post_title' => 'Wpis W1', 'post_name' => 'wpis-w1', 'post_category' => [$katId],
        'post_content' => "Pierwsza strona W1.\n<!--nextpage-->\nDruga strona W1.", 'post_excerpt' => 'Zajawka W1']);
    // Polska zajawka jest: bez zajawki EN, a z treścią EN wersja EN ma dostać zajawkę z treści EN, nie tę.
    $w['W2'] = $nowy(['post_title' => 'Wpis W2', 'post_name' => 'wpis-w2', 'post_content' => 'Treść wpisu W2 po polsku.',
        'post_excerpt' => 'Zajawka W2 po polsku',
        'post_date' => gmdate('Y-m-d H:i:s', time() - 3600)]);
    $w['S0'] = $nowy(['post_type' => 'page', 'post_title' => 'Rodzic W', 'post_name' => 'rodzic-w', 'post_content' => 'Rodzic.']);
    $w['S1'] = $nowy(['post_type' => 'page', 'post_title' => 'O nas W', 'post_name' => 'o-nas-w', 'post_parent' => $w['S0'],
        'post_content' => 'Treść strony O nas.']);
    $w['B1'] = $nowy(['post_type' => 'page', 'post_title' => 'Bricks W', 'post_name' => 'bricks-w', 'post_content' => '']);
    $w['K1'] = $nowy(['post_type' => 'page', 'post_title' => 'Kontakt W', 'post_name' => 'kontakt-w', 'post_content' => 'Kontakt.']);
    update_post_meta($w['B1'], '_bricks_editor_mode', 'bricks');
    update_post_meta($w['S1'], '_evoke_seo_title', 'O nas — SEO PL');
    // W2: treść EN bez zajawki EN — zajawka EN ma powstać z treści EN.
    update_post_meta($w['W2'], '_evk_tl_en__post_content', 'English content of W2.');

    $zapis['wpisy'] = $w;
    $zapis['terminy'] = [['category', $katId]];
    file_put_contents($plik, wp_json_encode($zapis));

    update_option('tl_translations', ['groups' => [['id' => 'g-w', 'name' => 'Wpisy testu', 'rows' => [
        ['id' => 'r1', 'pl' => 'O nas W', 'en' => 'About us W', 'de' => 'Über uns W'],
        ['id' => 'r2', 'pl' => 'Zajawka W1', 'en' => 'Excerpt W1 from dictionary', 'de' => ''],
    ]]]]);
    update_option('tl_url_slugs', [
        ['pl' => 'rodzic-w', 'en' => 'parent-w', 'de' => ''],
        ['pl' => 'cennik-w', 'en' => 'pricing-w', 'de' => ''],
    ]);
    update_option('tl_languages', [
        ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'],
        ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
    ]);
    update_option('evk_tl_module_enabled', 1);
    update_option('show_on_front', 'posts');
    update_option('posts_per_page', 10);
    /* Pudełko „Zajawka" WordPress domyślnie chowa (opcje ekranu) — administrator
       testu ma je widoczne, jak ktoś, kto zajawek używa. */
    $admin = get_user_by('login', 'admin');
    if ($admin) {
        foreach (['metaboxhidden_post', 'metaboxhidden_page'] as $m) {
            if (!array_key_exists($m, $zapis['uzytkownik'] ?? [])) $zapis['uzytkownik'][$m] = get_user_meta($admin->ID, $m, true);
            update_user_meta($admin->ID, $m, ['slugdiv']);
        }
        file_put_contents($plik, wp_json_encode($zapis));
    }
    update_post_meta($w['K1'], '_evoke_seo_title', 'Kontakt — SEO PL');
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
    flush_rewrite_rules(false);
    evk_t_tlw_bez_pamieci();
    $out['wpisy'] = $w;
    $out['gotowe'] = !in_array(0, $w, true) && $katId > 0;
    break;

case 'przeplucz':
    flush_rewrite_rules(false);
    evk_t_tlw_bez_pamieci();
    $out['tl'] = function_exists('evk_tlw_tlumaczenie');
    break;

case 'stan':
    foreach ((array) ($zapis['wpisy'] ?? []) as $k => $pid) {
        $meta = [];
        foreach ((array) get_post_meta((int) $pid) as $klucz => $v) {
            if (strpos((string) $klucz, '_evk_tl_') === 0) $meta[$klucz] = maybe_unserialize($v[0]);
        }
        $p = get_post((int) $pid);
        $out['wpisy'][$k] = ['meta' => $meta, 'post_name' => $p ? $p->post_name : null, 'post_title' => $p ? $p->post_title : null];
    }
    $out['mapa'] = get_option('tl_url_slugs', []);
    break;

case 'front':
    // Bez HTTP: język jak na stronie wersji językowej, po init.
    $GLOBALS['lang_code'] = (string) ($argv[2] ?? 'en');
    $w1 = get_post($id('W1'));
    $w2 = get_post($id('W2'));
    $out['tytul_w1'] = get_the_title($id('W1'));
    $out['zajawka_w1'] = get_the_excerpt($w1);
    $out['zajawka_w2'] = get_the_excerpt($w2);
    $out['tytul_s1'] = get_the_title($id('S1'));
    // Menu: pozycja strony bez własnej etykiety bierze tytuł strony.
    $menu = wp_create_nav_menu('Menu W ' . wp_rand());
    if (!is_wp_error($menu)) {
        wp_update_nav_menu_item($menu, 0, ['menu-item-object-id' => $id('S1'), 'menu-item-object' => 'page',
            'menu-item-type' => 'post_type', 'menu-item-status' => 'publish']);
        $out['menu'] = array_map(static function ($i) { return $i->title; }, (array) wp_get_nav_menu_items($menu));
        wp_delete_nav_menu($menu);
    }
    break;

case 'zmien':
    $pid = $id((string) ($argv[2] ?? ''));
    $pole = (string) ($argv[3] ?? '');
    if (!$pid || !in_array($pole, ['post_title', 'post_name', 'post_excerpt', 'post_content'], true)) { $out['brak'] = 'zły wpis albo pole'; break; }
    $admin = get_user_by('login', 'admin');
    if ($admin) wp_set_current_user($admin->ID);
    $out['wynik'] = wp_update_post(['ID' => $pid, $pole => (string) ($argv[4] ?? '')]);
    $out['post_name'] = get_post_field('post_name', $pid);
    break;

case 'sprzataj':
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['wpisy'] ?? []) as $pid) wp_delete_post((int) $pid, true);
    $admin = get_user_by('login', 'admin');
    foreach ((array) ($zapis['uzytkownik'] ?? []) as $m => $v) {
        if (!$admin) break;
        ($v === '' || $v === null) ? delete_user_meta($admin->ID, $m) : update_user_meta($admin->ID, $m, $v);
    }
    foreach ((array) ($zapis['terminy'] ?? []) as [$tax, $tid]) if ($tid) wp_delete_term((int) $tid, (string) $tax);
    @unlink($muPlik);
    if (empty($zapis['mu_bylo'])) @rmdir($mu);
    evk_t_tlw_bez_pamieci();
    $GLOBALS['wp_rewrite']->init();
    flush_rewrite_rules(false);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
