<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Elementy Evoke dodawane w PRAWDZIWYM builderze (1.276.0): piąty testowy
 * WordPress z licencją, test tests/bricks-builder-elementy.test.js.
 *
 *   php tests/php/bricks-builder-elementy.php wp
 *   php tests/php/bricks-builder-elementy.php ustaw      wszystkie elementy Evoke, strona z sekcją i STARYM Offcanvasem (bez znacznika)
 *   php tests/php/bricks-builder-elementy.php sprzataj
 */

$krok = $argv[1] ?? '';
$evk_piaty = true;
require __DIR__ . '/_testowy-wp.php';

$plik  = sys_get_temp_dir() . '/evk-t-bricks-builder-elementy.json';
$opcje = ['evk_elements', 'bricks_global_settings'];
$out   = ['krok' => $krok];
const EVK_TBE_TYTUL = 'Builder elementy Evoke';

function evk_tbe_zapis(): array {
    global $plik;
    return is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
}

if ($krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

switch ($krok) {

case 'wp':
    $licencja = null;
    if (class_exists('\Bricks\License')) {
        \Bricks\License::$license_key = \Bricks\License::get_license_key();
        $licencja = \Bricks\License::license_is_valid();
    }
    $out += ['wp' => untrailingslashit(ABSPATH), 'motyw' => wp_get_theme()->get('Name'), 'licencja' => $licencja];
    break;

/* Elementy rejestrują się na `init` — działa od następnego procesu (serwer). */
case 'ustaw':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed]));
    }
    $zapis = evk_tbe_zapis();
    update_option('evk_elements', array_fill_keys(array_keys(evk_elements_registry()), 1));
    $s = get_option('bricks_global_settings', []);
    $s = is_array($s) ? $s : [];
    $s['postTypes'] = ['page'];
    update_option('bricks_global_settings', $s);
    global $wpdb;
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TBE_TYTUL)) as $stary) wp_delete_post((int) $stary, true);
    $id = (int) wp_insert_post(['post_title' => EVK_TBE_TYTUL, 'post_type' => 'page', 'post_status' => 'publish']);
    update_post_meta($id, '_bricks_editor_mode', 'bricks');
    update_post_meta($id, '_bricks_page_content_2', wp_slash([
        ['id' => 'bes001', 'name' => 'section', 'parent' => 0, 'children' => ['beoc01'], 'settings' => []],
        /* Offcanvas zapisany przed 1.276.0: bez znacznika i bez „Trzymaj otwarte”. */
        ['id' => 'beoc01', 'name' => 'evk-offcanvas-menu', 'parent' => 'bes001', 'children' => [], 'settings' => ['mode' => 'single']],
    ]));
    $zapis['strona'] = $id;
    file_put_contents($plik, wp_json_encode($zapis));
    $out += ['strona' => $id, 'gotowe' => $id > 0];
    break;

case 'sprzataj':
    $zapis = evk_tbe_zapis();
    if (!empty($zapis['strona'])) wp_delete_post((int) $zapis['strona'], true);
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    @unlink($plik);
    $out['ok'] = true;
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
