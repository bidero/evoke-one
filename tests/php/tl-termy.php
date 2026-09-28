<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Tłumaczenia kategorii, tagów i tekstów alternatywnych (1.253.0) — stan między
 * żądaniami testu tl-termy.
 *
 *   php tests/php/tl-termy.php wp
 *   php tests/php/tl-termy.php przygotuj http://127.0.0.1:<port>
 *   php tests/php/tl-termy.php przeplucz http://127.0.0.1:<port>   (osobny proces: Tłumaczenia wczytane)
 *   php tests/php/tl-termy.php stan                                metadane termów, mapa adresów, alt
 *   php tests/php/tl-termy.php front <lang>                        nazwy i opisy, lista kategorii wpisu, menu, alt (bez HTTP)
 *   php tests/php/tl-termy.php zmien-term <klucz> <pole> <wartość>  wp_update_term (zmiana polskiego adresu)
 *   php tests/php/tl-termy.php sprzataj
 *
 * C1 „Usługi T" (z opisem) z podkategorią C2, tag T1 „Etykieta T" (słownik
 * zna jego nazwę), wpis P1 w C1 z tagiem T1 i obrazkiem wyróżniającym A1
 * (polski alt), strona K „Strona T" (jej polski adres jako adres EN
 * kategorii to konflikt).
 */

$evk_krok = $argv[1] ?? '';
if (in_array($evk_krok, ['przygotuj', 'przeplucz'], true) && preg_match('#^http://127\.0\.0\.1:\d+$#', $argv[2] ?? '')) {
    define('WP_HOME', $argv[2]);
    define('WP_SITEURL', $argv[2]);
}
require __DIR__ . '/_testowy-wp.php';

$plik  = sys_get_temp_dir() . '/evk-t-tl-termy.json';
$opcje = ['permalink_structure', 'show_on_front', 'evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'tl_url_slugs'];
$out   = ['krok' => $evk_krok];
$zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];

/** Pamięć podręczna Tłumaczeń (nazwy jak w evoke-one.php). */
function evk_t_tlt_bez_pamieci(): void {
    foreach (['tl_compiled_slugs', 'tl_compiled_config', 'tl_compiled_tokens_en', 'tl_compiled_tokens_de'] as $t) delete_transient($t);
}

switch ($evk_krok) {

case 'wp':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'przygotuj':
    if (!defined('WP_HOME')) { $out['brak'] = 'przygotuj bez adresu serwera testowego'; break; }
    if (!$zapis) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        $zapis = ['opcje' => $przed, 'wpisy' => [], 'terminy' => [], 'pliki' => []];
    }
    $t = [];
    $c1 = wp_insert_term('Usługi T', 'category', ['slug' => 'uslugi-t', 'description' => 'Opis usług T']);
    $t['C1'] = is_wp_error($c1) ? 0 : (int) $c1['term_id'];
    $c2 = wp_insert_term('Projektowanie T', 'category', ['slug' => 'projektowanie-t', 'parent' => $t['C1']]);
    $t['C2'] = is_wp_error($c2) ? 0 : (int) $c2['term_id'];
    $t1 = wp_insert_term('Etykieta T', 'post_tag', ['slug' => 'etykieta-t']);
    $t['T1'] = is_wp_error($t1) ? 0 : (int) $t1['term_id'];

    // Obrazek z polskim tekstem alternatywnym.
    $img = imagecreatetruecolor(400, 300);
    imagefilledrectangle($img, 0, 0, 399, 299, imagecolorallocate($img, 30, 120, 90));
    ob_start();
    imagejpeg($img, null, 80);
    $dane = (string) ob_get_clean();
    imagedestroy($img);
    $plikObrazka = wp_upload_bits('evk-t-tl-termy.jpg', null, $dane);
    $a1 = (int) wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => 'Obrazek T', 'post_status' => 'inherit'], $plikObrazka['file']);
    wp_update_attachment_metadata($a1, ['width' => 400, 'height' => 300, 'file' => _wp_relative_upload_path($plikObrazka['file'])]);
    update_post_meta($a1, '_wp_attachment_image_alt', 'Alt PL obrazka T');

    $p1 = (int) wp_insert_post(['post_title' => 'Wpis T', 'post_name' => 'wpis-t', 'post_status' => 'publish',
        'post_category' => [$t['C1']], 'tags_input' => ['Etykieta T'], 'post_content' => 'Treść wpisu T.']);
    set_post_thumbnail($p1, $a1);
    $k = (int) wp_insert_post(['post_type' => 'page', 'post_title' => 'Strona T', 'post_name' => 'strona-t', 'post_status' => 'publish']);

    $zapis['terminy'] = [['category', $t['C2']], ['category', $t['C1']], ['post_tag', $t['T1']]];
    $zapis['wpisy'] = ['P1' => $p1, 'K' => $k, 'A1' => $a1];
    $zapis['termy'] = $t;
    $zapis['pliki'] = [$plikObrazka['file']];
    file_put_contents($plik, wp_json_encode($zapis));

    update_option('tl_translations', ['groups' => [['id' => 'g-t', 'name' => 'Termy testu', 'rows' => [
        ['id' => 'r1', 'pl' => 'Etykieta T', 'en' => 'Label T', 'de' => 'Etikett T'],
    ]]]]);
    update_option('tl_url_slugs', []);
    update_option('tl_languages', [
        ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'],
        ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
    ]);
    update_option('evk_tl_module_enabled', 1);
    update_option('show_on_front', 'posts');
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
    flush_rewrite_rules(false);
    evk_t_tlt_bez_pamieci();
    $out['termy'] = $t;
    $out['wpisy'] = $zapis['wpisy'];
    $out['gotowe'] = !in_array(0, $t, true) && $p1 > 0 && $a1 > 0 && $k > 0;
    break;

case 'przeplucz':
    flush_rewrite_rules(false);
    evk_t_tlt_bez_pamieci();
    $out['tl'] = function_exists('evk_tlt_tlumaczenie') && function_exists('evk_tlw_jezyk');
    break;

case 'stan':
    foreach ((array) ($zapis['termy'] ?? []) as $k => $tid) {
        $meta = [];
        foreach ((array) get_term_meta((int) $tid) as $klucz => $v) {
            if (strpos((string) $klucz, '_evk_tl_') === 0) $meta[$klucz] = $v[0];
        }
        $term = get_term((int) $tid);
        $out['termy'][$k] = ['meta' => $meta, 'slug' => $term instanceof WP_Term ? $term->slug : null];
    }
    // Termy dodane w teście formularzem.
    foreach (['Nowa T', 'Druga T'] as $nazwa) {
        $term = get_term_by('name', $nazwa, 'category');
        $out['dodane'][$nazwa] = $term ? (string) get_term_meta($term->term_id, '_evk_tl_en__name', true) : null;
    }
    $a1 = (int) ($zapis['wpisy']['A1'] ?? 0);
    $out['alt'] = ['en' => (string) get_post_meta($a1, '_evk_tl_en__alt', true), 'de' => (string) get_post_meta($a1, '_evk_tl_de__alt', true)];
    $out['mapa'] = get_option('tl_url_slugs', []);
    break;

case 'front':
    $GLOBALS['lang_code'] = (string) ($argv[2] ?? 'en');
    $t = (array) ($zapis['termy'] ?? []);
    $p1 = (int) ($zapis['wpisy']['P1'] ?? 0);
    $c1 = get_term((int) ($t['C1'] ?? 0));
    $out['nazwa_c1'] = $c1 instanceof WP_Term ? $c1->name : null;
    $out['opis_c1'] = trim(wp_strip_all_tags(term_description((int) ($t['C1'] ?? 0))));
    $out['kategorie_p1'] = trim(wp_strip_all_tags(get_the_category_list(', ', '', $p1)));
    $out['tagi_p1'] = trim(wp_strip_all_tags((string) get_the_tag_list('', ', ', '', $p1)));
    preg_match('/\balt="([^"]*)"/', wp_get_attachment_image((int) ($zapis['wpisy']['A1'] ?? 0), 'full'), $alt);
    $out['alt'] = $alt[1] ?? null;
    $menu = wp_create_nav_menu('Menu T ' . wp_rand());
    if (!is_wp_error($menu)) {
        wp_update_nav_menu_item($menu, 0, ['menu-item-object-id' => (int) ($t['C1'] ?? 0), 'menu-item-object' => 'category',
            'menu-item-type' => 'taxonomy', 'menu-item-status' => 'publish']);
        $out['menu'] = array_map(static function ($i) { return $i->title; }, (array) wp_get_nav_menu_items($menu));
        wp_delete_nav_menu($menu);
    }
    break;

case 'zmien-term':
    $tid = (int) ($zapis['termy'][$argv[2] ?? ''] ?? 0);
    $pole = (string) ($argv[3] ?? '');
    if (!$tid || !in_array($pole, ['slug', 'name', 'description'], true)) { $out['brak'] = 'zły term albo pole'; break; }
    $term = get_term($tid);
    $wynik = $term instanceof WP_Term ? wp_update_term($tid, $term->taxonomy, [$pole => (string) ($argv[4] ?? '')]) : null;
    $out['wynik'] = is_wp_error($wynik) ? $wynik->get_error_message() : $wynik;
    $out['slug'] = ($t2 = get_term($tid)) instanceof WP_Term ? $t2->slug : null;
    break;

case 'sprzataj':
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['wpisy'] ?? []) as $pid) wp_delete_post((int) $pid, true);
    foreach ((array) ($zapis['terminy'] ?? []) as [$tax, $tid]) if ($tid) wp_delete_term((int) $tid, (string) $tax);
    foreach (['Nowa T', 'Druga T'] as $nazwa) {
        $term = get_term_by('name', $nazwa, 'category');
        if ($term) wp_delete_term($term->term_id, 'category');
    }
    foreach ((array) ($zapis['pliki'] ?? []) as $f) @unlink((string) $f);
    evk_t_tlt_bez_pamieci();
    $GLOBALS['wp_rewrite']->init();
    flush_rewrite_rules(false);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
