<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Evoke FIELDS 1.71.0 na CZWARTYM testowym WordPressie (pola.test, obie wtyczki):
 * galeria w natywnej „Image Gallery" Bricksa, pola pętli w podpowiedziach,
 * lokalizacja „Tylko strona ustawień".
 *
 *   php tests/php/fields-galeria-lokalizacja.php ustaw        grupy, wpis, obrazy, strona ustawień
 *   php tests/php/fields-galeria-lokalizacja.php galeria      tagi galerii w kontekście obrazu i tekstu, kolejność jak pętla
 *   php tests/php/fields-galeria-lokalizacja.php podpowiedzi  lista tagów Bricksa (dynamic_tags_list)
 *   php tests/php/fields-galeria-lokalizacja.php petle        pętle grup: wpisowe i „Opcje"
 *   php tests/php/fields-galeria-lokalizacja.php konflikt     ten sam klucz w grupie opcji i grupie wpisu
 *   php tests/php/fields-galeria-lokalizacja.php eksport      eksport i import zachowują „Tylko strona ustawień"
 *   php tests/php/fields-galeria-lokalizacja.php stan         strony ustawień i identyfikatory (test w przeglądarce)
 *   php tests/php/fields-galeria-lokalizacja.php sprzataj
 *
 * Bricksa tu nie ma: tagi idą przez te same funkcje, które Bricks woła filtrami
 * `bricks/dynamic_data/render_tag` i `bricks/query/run` (kontekst 'image' =
 * element Image albo Image Gallery).
 */
$evk_czwarty = true;
$evk_tryb    = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';

if (!function_exists('evk_rep_render_tag') || !function_exists('evk_rep_object_types')) {
    echo json_encode(['brak' => 'Evoke FIELDS 1.71.0+ nieaktywny na ' . home_url() . ' — tools/testowy-wp.sh (repozytorium Fields: EVK_FIELDS_REPO, domyślnie ../evoke-fields)']);
    exit;
}

$plik = sys_get_temp_dir() . '/evk-t-fields-gl.json';
$zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
$out = ['tryb' => $evk_tryb];

function evk_gl_grupa(string $klucz, string $etykieta, array $pola, string $typ, int $kolejnosc): int {
    $id = (int) wp_insert_post(['post_type' => 'evk_field_group', 'post_status' => 'publish', 'post_title' => $etykieta, 'menu_order' => $kolejnosc]);
    update_post_meta($id, '_evk_test_gl', '1');
    update_post_meta($id, '_evk_key', $klucz);
    update_post_meta($id, '_evk_fields', wp_slash(wp_json_encode($pola)));
    update_post_meta($id, '_evk_post_types', ['post']);
    update_post_meta($id, '_evk_object_type', $typ);
    return $id;
}

function evk_gl_obrazek(string $nazwa, int $n): int {
    $img = imagecreatetruecolor(60, 40);
    imagefilledrectangle($img, 0, 0, 59, 39, imagecolorallocate($img, (40 * $n) % 255, 90, 160));
    ob_start();
    imagejpeg($img, null, 80);
    $dane = (string) ob_get_clean();
    imagedestroy($img);
    $plik = wp_upload_bits($nazwa, null, $dane);
    $id = (int) wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => $nazwa, 'post_status' => 'inherit'], $plik['file']);
    wp_update_attachment_metadata($id, ['width' => 60, 'height' => 40, 'file' => _wp_relative_upload_path($plik['file'])]);
    update_post_meta($id, '_evk_test_gl', '1');
    return $id;
}

function evk_gl_sprzataj(array $zapis): void {
    foreach (get_posts(['post_type' => ['evk_field_group', 'post', 'attachment'], 'post_status' => 'any', 'numberposts' => -1,
        'meta_key' => '_evk_test_gl', 'meta_value' => '1']) as $p) {
        wp_delete_post($p->ID, true);
    }
    $imp = function_exists('evk_tools_find_group_post_by_key') ? evk_tools_find_group_post_by_key('gl_import') : 0;
    if ($imp) wp_delete_post($imp, true);
    delete_option('evk_rep_opt_gl_opcje');
    if (array_key_exists('strony', $zapis)) {
        $zapis['strony'] === null ? delete_option('evk_rep_settings_pages') : update_option('evk_rep_settings_pages', $zapis['strony']);
    }
    if (function_exists('evk_groups_cache_clear')) evk_groups_cache_clear();
}

/** Tag w kontekście jak z Bricksa. */
function evk_gl_tag(string $tag, int $pid, string $kontekst) {
    return evk_rep_render_tag($tag, get_post($pid), $kontekst);
}

switch ($evk_tryb) {

case 'ustaw':
    evk_gl_sprzataj($zapis);
    $zapis = ['strony' => get_option('evk_rep_settings_pages', null)];
    wp_set_current_user(1);

    // Kolejność: grupa opcji przed grupą wpisu — „pierwsze trafienie wygrywa" (konflikt).
    $opcje = evk_gl_grupa('gl_opcje', 'Opcje GL', [
        'haslo_gl'    => ['type' => 'text', 'label' => 'Hasło GL'],
        'galeria_opc' => ['type' => 'gallery', 'label' => 'Galeria opcji GL', 'gallery_sort' => 'random_day'],
        'galeria_wsp' => ['type' => 'gallery', 'label' => 'Galeria wspólna (opcje)'],
        'wspolne_gl'  => ['type' => 'select', 'label' => 'Wspólne GL', 'options' => "a : Alfa\nb : Beta"],
        'lista_gl'    => ['type' => 'repeater', 'label' => 'Lista GL', 'sub_fields' => [
            'pozycja_gl' => ['type' => 'text', 'label' => 'Pozycja GL'],
        ]],
    ], 'options', 0);
    $wpisG = evk_gl_grupa('gl_wpis', 'Wpis GL', [
        'galeria_gl'  => ['type' => 'gallery', 'label' => 'Galeria wpisu GL'],
        'galeria_wsp' => ['type' => 'gallery', 'label' => 'Galeria wspólna (wpis)'],
        'obraz_gl'    => ['type' => 'image', 'label' => 'Obraz GL'],
        'pusta_gl'    => ['type' => 'gallery', 'label' => 'Pusta galeria GL'],
        'wspolne_gl'  => ['type' => 'text', 'label' => 'Wspólne GL (wpis)'],
    ], 'post', 1);
    $inna = evk_gl_grupa('gl_inna', 'Inna GL', ['inne_gl' => ['type' => 'text', 'label' => 'Inne GL'],
        'galeria_inna' => ['type' => 'gallery', 'label' => 'Galeria innej GL']], 'post', 2);
    /* Grupa opcji ZA grupą wpisu z tym samym kluczem galerii: jej pętla wpisowa
       musi wrócić do stanu z grupy wpisu, a nie zniknąć. */
    $opcje2 = evk_gl_grupa('gl_opcje2', 'Opcje GL 2', ['galeria_inna' => ['type' => 'gallery', 'label' => 'Galeria opcji GL 2']], 'options', 3);

    $obrazy = [];
    for ($i = 1; $i <= 5; $i++) $obrazy[] = evk_gl_obrazek('evk-t-gl-' . $i . '.jpg', $i);
    $wpis = (int) wp_insert_post(['post_title' => 'Wpis GL', 'post_status' => 'publish', 'post_content' => '']);
    update_post_meta($wpis, '_evk_test_gl', '1');
    update_post_meta($wpis, 'galeria_gl', [['img' => $obrazy[0]], ['img' => $obrazy[1]], ['img' => $obrazy[2]]]);
    update_post_meta($wpis, 'obraz_gl', $obrazy[3]);
    update_post_meta($wpis, 'wspolne_gl', 'a');
    update_option('evk_rep_opt_gl_opcje', [
        'haslo_gl'    => 'Hasło',
        'galeria_opc' => array_map(static function ($id) { return ['img' => $id]; }, $obrazy),
        'lista_gl'    => [['pozycja_gl' => 'P1']],
    ], false);

    $strony = is_array($zapis['strony']) ? $zapis['strony'] : [];
    $strony['strona-gl'] = ['label' => 'Strona GL', 'slug' => 'strona-gl', 'icon' => 'dashicons-admin-generic', 'capability' => 'manage_options',
        'parent' => '', 'hide_title' => 0, 'tabs' => [
            ['label' => 'Ogólne', 'groups' => ['gl_inna']],
            ['label' => 'Galeria', 'groups' => []],
        ]];
    update_option('evk_rep_settings_pages', $strony);
    evk_groups_cache_clear();
    file_put_contents($plik, wp_json_encode($zapis + ['grupy' => ['opcje' => $opcje, 'wpis' => $wpisG, 'inna' => $inna, 'opcje2' => $opcje2], 'wpis' => $wpis, 'obrazy' => $obrazy]));
    $out['gotowe'] = $opcje && $wpisG && $inna && $opcje2 && $wpis && count(array_filter($obrazy)) === 5;
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'galeria':
    $pid = (int) ($zapis['wpis'] ?? 0);
    $o = array_map('intval', (array) ($zapis['obrazy'] ?? []));
    $out['obrazy'] = $o;
    $out['obraz_galeria']   = evk_gl_tag('{evk_field_galeria_gl}', $pid, 'image');
    $out['obraz_ids']       = evk_gl_tag('{evk_field_galeria_gl__ids}', $pid, 'image');
    $out['obraz_pusta']     = evk_gl_tag('{evk_field_pusta_gl}', $pid, 'image');
    $out['obraz_pojedynczy'] = evk_gl_tag('{evk_field_obraz_gl__id}', $pid, 'image');
    $out['obraz_licznik']   = evk_gl_tag('{evk_field_galeria_gl__count}', $pid, 'image');
    $out['tekst_ids']       = evk_gl_tag('{evk_field_galeria_gl__ids}', $pid, 'text');
    $out['tekst_url']       = evk_gl_tag('{evk_field_galeria_gl}', $pid, 'text');
    $out['url_pierwszego']  = (string) wp_get_attachment_image_url($o[0] ?? 0, 'full');
    $out['opcje_obraz']     = evk_gl_tag('{evk_opt_gl_opcje_galeria_opc}', 0, 'image');
    // Pętla tej samej galerii — tak jak Bricks pyta o wiersze.
    $wiersze = apply_filters('bricks/query/run', [], (object) ['object_type' => 'evk_opt_gl_opcje.galeria_opc', 'post_id' => 0, 'settings' => []]);
    $out['opcje_petla'] = array_map(static function ($w) { return (int) ($w['img'] ?? 0); }, is_array($wiersze) ? $wiersze : []);
    break;

case 'podpowiedzi':
    $tagi = apply_filters('bricks/dynamic_tags_list', []);
    $grupy = [];
    foreach ((array) $tagi as $t) $grupy[(string) ($t['group'] ?? '')][] = (string) ($t['name'] ?? '');
    $out['galeria']     = $grupy['EVK Pętla: galeria'] ?? [];
    $out['kategorie']   = $grupy['EVK Pętla: kategorie galerii'] ?? [];
    $out['opcje_evk']   = $grupy['EVK: Opcje GL'] ?? [];
    $out['opcje_opcje'] = $grupy['EVK Opcje: Opcje GL'] ?? [];
    $out['wpis_evk']    = $grupy['EVK: Wpis GL'] ?? [];
    break;

case 'petle':
    $petle = evk_rep_loops();
    $klucze = ['galeria_gl', 'galeria_opc', 'evk_opt_gl_opcje.galeria_opc', 'evk_galcat_galeria_opc', 'evk_galcatopt_gl_opcje.galeria_opc',
        'lista_gl', 'evk_opt_gl_opcje.lista_gl', 'galeria_wsp', 'evk_opt_gl_wpis.galeria_wsp', 'evk_opt_gl_opcje.galeria_wsp',
        'galeria_inna', 'evk_opt_gl_opcje2.galeria_inna'];
    foreach ($klucze as $k) $out['petle'][$k] = isset($petle[$k]) ? (string) $petle[$k]['label'] : null;
    break;

case 'konflikt':
    $pid = (int) ($zapis['wpis'] ?? 0);
    $out['etykieta'] = evk_gl_tag('{evk_field_wspolne_gl__label}', $pid, 'text');
    $out['kontekst'] = ($k = evk_rep_find_single_field_ctx('wspolne_gl')) ? ($k['object_type'] . ':' . ($k['field']['type'] ?? '')) : null;
    $out['haslo_z_wpisu'] = evk_gl_tag('{evk_field_haslo_gl}', $pid, 'text');
    $out['haslo_z_opcji'] = evk_gl_tag('{evk_opt_gl_opcje_haslo_gl}', 0, 'text');
    break;

case 'eksport':
    $grupa = null;
    foreach (evk_tools_export_groups() as $g) if (($g['key'] ?? '') === 'gl_opcje') $grupa = $g;
    $out['eksport'] = $grupa ? ($grupa['object_type'] ?? null) : 'brak grupy';
    if ($grupa) {
        $grupa['key'] = 'gl_import';
        $grupa['label'] = 'Import GL';
        evk_tools_run_import(['groups' => [$grupa]], true, ['groups' => true]);
        $imp = evk_tools_find_group_post_by_key('gl_import');
        $out['import'] = $imp ? (string) get_post_meta($imp, '_evk_object_type', true) : 'brak';
        if ($imp) wp_delete_post($imp, true);
        evk_groups_cache_clear();
    }
    break;

case 'stan':
    $strona = (array) (get_option('evk_rep_settings_pages', [])['strona-gl'] ?? []);
    $out['zakladki'] = array_map(static function ($t) { return [(string) ($t['label'] ?? ''), array_values((array) ($t['groups'] ?? []))]; }, (array) ($strona['tabs'] ?? []));
    $out['grupy'] = $zapis['grupy'] ?? [];
    $out['wpis'] = $zapis['wpis'] ?? 0;
    $out['typ_opcji'] = (string) get_post_meta((int) ($zapis['grupy']['opcje'] ?? 0), '_evk_object_type', true);
    $out['pola_opcji'] = count((array) json_decode((string) get_post_meta((int) ($zapis['grupy']['opcje'] ?? 0), '_evk_fields', true), true));
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'sprzataj':
    evk_gl_sprzataj($zapis);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
