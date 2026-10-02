<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Obrazy WebP/AVIF w renderze PRAWDZIWEGO Bricksa — piąty testowy WordPress
 * (bricks.test, tools/testowy-wp.sh). Atrapa `_bricks-stubs.php` nie mówi,
 * jak Bricks składa `<img>` (leniwe ładowanie, `<picture>` z własnymi
 * źródłami, `<figure>` z podpisem), więc render idzie przez prawdziwy motyw.
 *
 *   php tests/php/obrazy-bricks.php wp
 *   php tests/php/obrazy-bricks.php ustaw     kopia opcji; moduł, Tłumaczenia EN, obrazy, strona
 *   php tests/php/obrazy-bricks.php modul '{"enabled":0|1}'
 *   php tests/php/obrazy-bricks.php sprzataj
 */

$evk_krok = $argv[1] ?? '';
$evk_arg  = $argv[2] ?? '';
$evk_piaty = true;
require __DIR__ . '/_testowy-wp.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

const EVK_TOB_TYTUL = 'evk-obrazy-bricks';
$evk_plik  = sys_get_temp_dir() . '/evk-t-obrazy-bricks.json';
$evk_opcje = ['evk_obrazy', 'evk_tl_module_enabled', 'tl_languages'];
$out = ['krok' => $evk_krok];

function evk_tob_zapis(): array {
    global $evk_plik;
    return is_file($evk_plik) ? (json_decode((string) file_get_contents($evk_plik), true) ?: []) : [];
}

/** Zdjęciopodobny JPEG z GD (szum i figury, jak w obrazy-webp.php). */
function evk_tob_wgraj(string $nazwa, int $w, int $h, string $alt, string $alt_en): int {
    $tmp = wp_tempnam($nazwa . '.jpg');
    $im = imagecreatetruecolor($w, $h);
    mt_srand($w + $h);
    for ($y = 0; $y < $h; $y += 4) {
        for ($x = 0; $x < $w; $x += 4) {
            $c = imagecolorallocate($im, (int) (128 + 100 * sin($x / 41)) & 255, (int) (128 + 90 * cos($y / 29)) & 255, mt_rand(0, 255));
            imagefilledrectangle($im, $x, $y, $x + 3, $y + 3, $c);
        }
    }
    imagejpeg($im, $tmp, 90);
    imagedestroy($im);
    $id = media_handle_sideload(['name' => $nazwa . '.jpg', 'tmp_name' => $tmp], 0, EVK_TOB_TYTUL);
    if (is_wp_error($id)) return 0;
    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    if ($alt_en !== '') update_post_meta($id, '_evk_tl_en__alt', $alt_en);
    wp_update_post(['ID' => $id, 'post_excerpt' => 'Podpis ' . $nazwa]);
    return (int) $id;
}

if ($evk_krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

switch ($evk_krok) {

case 'wp':
    $t = wp_get_theme();
    $out += ['wp' => untrailingslashit(ABSPATH), 'motyw' => $t->get('Name'), 'bricks' => defined('BRICKS_VERSION') ? BRICKS_VERSION : null,
        'avif' => function_exists('evk_obrazy_obslugiwany') && evk_obrazy_obslugiwany('avif')];
    break;

/* Moduły wczytują się przy starcie procesu — moduł obrazów musi być włączony
   PRZED wgraniem, a Tłumaczenia przed renderem. Dlatego dwa kroki. */
case 'modul':
    if (!is_file($evk_plik)) {
        $przed = [];
        foreach ($evk_opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($evk_plik, wp_json_encode(['opcje' => $przed]));
    }
    $u = json_decode($evk_arg, true) ?: [];
    update_option('evk_obrazy', array_merge(['enabled' => 1, 'webp' => 1, 'avif' => 1, 'jakosc_webp' => 80, 'jakosc_avif' => 60, 'max_bok' => 2560], $u));
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US']]);
    $out['gotowe'] = true;
    break;

case 'ustaw':
    $zapis = evk_tob_zapis();
    global $wpdb;
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TOB_TYTUL)) as $stary) {
        get_post_type((int) $stary) === 'attachment' ? wp_delete_attachment((int) $stary, true) : wp_delete_post((int) $stary, true);
    }
    $a = evk_tob_wgraj('bricks-a', 1600, 1067, 'Opis A', 'Description A');
    $b = evk_tob_wgraj('bricks-b', 1200, 800, 'Opis B', '');
    $c = evk_tob_wgraj('bricks-c', 1200, 900, 'Opis C', '');
    $d = evk_tob_wgraj('bricks-d', 1600, 1067, 'Opis D (daleko)', '');
    $img = static function (int $id, string $rozmiar = 'large'): array {
        return ['id' => $id, 'filename' => wp_basename((string) get_attached_file($id)), 'size' => $rozmiar,
            'full' => wp_get_attachment_image_url($id, 'full'), 'url' => wp_get_attachment_image_url($id, $rozmiar)];
    };
    $strona = (int) wp_insert_post(['post_title' => EVK_TOB_TYTUL, 'post_type' => 'page', 'post_status' => 'publish']);
    update_post_meta($strona, '_bricks_editor_mode', 'bricks');
    update_post_meta($strona, '_bricks_page_content_2', wp_slash([
        ['id' => 'obs001', 'name' => 'section', 'parent' => 0, 'children' => ['obk001'], 'settings' => []],
        ['id' => 'obk001', 'name' => 'container', 'parent' => 'obs001', 'children' => ['obi001', 'obi002', 'obi003', 'obg001', 'obi005', 'obd001', 'obi004'],
            'settings' => ['_direction' => 'row', '_flexWrap' => 'wrap', '_columnGap' => '10px']],
        /* Zwykły obraz: `<img>` jest korzeniem elementu (klasa brxe-image, id). */
        ['id' => 'obi001', 'name' => 'image', 'parent' => 'obk001', 'children' => [], 'settings' => ['image' => $img($a), '_width' => '400px']],
        /* Z podpisem: korzeniem jest `<figure>`, obraz w środku. */
        ['id' => 'obi002', 'name' => 'image', 'parent' => 'obk001', 'children' => [], 'settings' => ['image' => $img($b, 'medium'), 'caption' => 'custom', 'captionCustom' => 'Podpis B']],
        /* Z odnośnikiem: `<a>` wokół obrazu. */
        ['id' => 'obi003', 'name' => 'image', 'parent' => 'obk001', 'children' => [], 'settings' => ['image' => $img($c, 'medium'), 'link' => 'url', 'url' => ['type' => 'external', 'url' => 'https://example.com/']]],
        ['id' => 'obg001', 'name' => 'image-gallery', 'parent' => 'obk001', 'children' => [], 'settings' => ['items' => ['images' => [$img($a, 'thumbnail'), $img($b, 'thumbnail'), $img($c, 'thumbnail')], 'size' => 'thumbnail'], 'columns' => 3]],
        /* Bez podpisu i odnośnika: `<img>` jest KORZENIEM elementu (id, brxe-image)
           i dzieckiem flexa, z szerokością w procentach — tu owinięcie w <picture>
           bez `display: contents` zmienia układ. */
        ['id' => 'obi005', 'name' => 'image', 'parent' => 'obk001', 'children' => [], 'settings' => ['image' => $img($c), 'caption' => 'none',
            '_width' => '50%', '_height' => '200px', '_objectFit' => 'cover']],
        /* Odstęp, za którym obraz D czeka na przewinięcie (leniwe ładowanie). */
        ['id' => 'obd001', 'name' => 'div', 'parent' => 'obk001', 'children' => [], 'settings' => ['_height' => '4000px', '_width' => '100%']],
        ['id' => 'obi004', 'name' => 'image', 'parent' => 'obk001', 'children' => [], 'settings' => ['image' => $img($d), '_width' => '400px', '_cssId' => 'obraz-daleko']],
    ]));
    $zapis['strona'] = $strona;
    $zapis['obrazy'] = compact('a', 'b', 'c', 'd');
    file_put_contents($evk_plik, wp_json_encode($zapis));
    $out += ['strona' => $strona, 'obrazy' => $zapis['obrazy'], 'meta' => get_post_meta($a, EVK_OBRAZY_META, true),
        'gotowe' => $strona > 0 && $a && $b && $c && $d];
    break;

case 'sprzataj':
    $zapis = evk_tob_zapis();
    global $wpdb;
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TOB_TYTUL)) as $stary) {
        get_post_type((int) $stary) === 'attachment' ? wp_delete_attachment((int) $stary, true) : wp_delete_post((int) $stary, true);
    }
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    if (is_file($evk_plik)) unlink($evk_plik);
    $out['gotowe'] = true;
    break;

default:
    $out['brak'] = 'nieznany krok';
}

echo wp_json_encode($out);
