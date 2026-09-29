<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Płaska galeria jako tag (Evoke FIELDS 1.73.0): `{evk_galflatopt_grupa.lista.galeria}` —
 * obrazy ze WSZYSTKICH wierszy listy w natywnej „Image Gallery", bez pętli. Czwarty
 * testowy WordPress (pola.test, obie wtyczki).
 *
 *   php tests/php/fields-galeria-plaska.php ustaw      grupy (opcje: pole-lista i grupa-lista; wpis), wiersze, obrazy
 *   php tests/php/fields-galeria-plaska.php tagi       kontekst obrazu, tekst, treść mieszana, kolejność jak pętla, podpowiedzi
 *   php tests/php/fields-galeria-plaska.php sprzataj
 *
 * Tagi idą przez funkcje, które Bricks woła filtrami `bricks/dynamic_data/render_tag`
 * i `render_content`; pętla przez `bricks/query/run`. Bricksa tu nie ma.
 */
$evk_czwarty = true;
$evk_tryb    = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';

if (!function_exists('evk_rep_render_tag') || !function_exists('evk_rep_loops')) {
    echo json_encode(['brak' => 'Evoke FIELDS nieaktywny na ' . home_url() . ' — tools/testowy-wp.sh (repozytorium Fields: EVK_FIELDS_REPO, domyślnie ../evoke-fields)']);
    exit;
}

$plik  = sys_get_temp_dir() . '/evk-t-fields-gp.json';
$zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
$out   = ['tryb' => $evk_tryb];

function evk_gp_grupa(string $klucz, string $etykieta, array $pola, string $typ, bool $lista = false): int {
    $id = (int) wp_insert_post(['post_type' => 'evk_field_group', 'post_status' => 'publish', 'post_title' => $etykieta]);
    update_post_meta($id, '_evk_test_gp', '1');
    update_post_meta($id, '_evk_key', $klucz);
    update_post_meta($id, '_evk_fields', wp_slash(wp_json_encode($pola)));
    update_post_meta($id, '_evk_post_types', ['post']);
    update_post_meta($id, '_evk_object_type', $typ);
    update_post_meta($id, '_evk_repeater', $lista ? 1 : 0);
    return $id;
}

function evk_gp_obrazek(string $nazwa): int {
    $img = imagecreatetruecolor(200, 120);
    imagefilledrectangle($img, 0, 0, 199, 119, imagecolorallocate($img, 40, 90, 140));
    ob_start();
    imagejpeg($img, null, 80);
    $dane = (string) ob_get_clean();
    imagedestroy($img);
    $plik = wp_upload_bits($nazwa, null, $dane);
    $id = (int) wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => $nazwa, 'post_status' => 'inherit'], $plik['file']);
    wp_update_attachment_metadata($id, ['width' => 200, 'height' => 120, 'file' => _wp_relative_upload_path($plik['file'])]);
    update_post_meta($id, '_evk_test_gp', '1');
    return $id;
}

function evk_gp_sprzataj(): void {
    foreach (get_posts(['post_type' => ['evk_field_group', 'post', 'attachment'], 'post_status' => 'any', 'numberposts' => -1,
        'meta_key' => '_evk_test_gp', 'meta_value' => '1']) as $p) {
        wp_delete_post($p->ID, true);
    }
    delete_option('evk_rep_opt_gp_opcje');
    delete_option('evk_rep_opt_gp_lista');
    if (function_exists('evk_groups_cache_clear')) evk_groups_cache_clear();
    unset($GLOBALS['evk_rep_loops_memo']);
}

/** Obrazy pętli `bricks/query/run` w kolejności wierszy — to, co dostałby Isotope. */
function evk_gp_petla(string $klucz, int $pid = 0): array {
    $q = new stdClass();
    $q->object_type = $klucz;
    $q->post_id     = $pid;
    $q->settings    = [];
    $wiersze = apply_filters('bricks/query/run', [], $q);
    return array_values(array_map(function ($w) { return (int) ($w['img'] ?? 0); }, is_array($wiersze) ? $wiersze : []));
}

switch ($evk_tryb) {

case 'ustaw':
    evk_gp_sprzataj();
    $o = [];
    for ($i = 1; $i <= 7; $i++) $o[] = evk_gp_obrazek('evk-t-gp-' . $i . '.jpg');
    $galeria = function (array $ids) { return array_map(function ($id) { return ['img' => $id, 'cat' => '']; }, $ids); };

    /* Grupa strony ustawień z polem-listą (tak jak u zgłaszającego): dwie galerie w wierszu,
       druga losowana raz na dobę — kolejność tagu ma się zgadzać z pętlą. */
    evk_gp_grupa('gp_opcje', 'Opcje GP', [
        'lista_gp' => ['type' => 'repeater', 'label' => 'Realizacje GP', 'sub_fields' => [
            'tytul_gp'    => ['type' => 'text',    'label' => 'Tytuł GP'],
            'galeria_gp'  => ['type' => 'gallery', 'label' => 'Galeria GP'],
            'losowa_gp'   => ['type' => 'gallery', 'label' => 'Losowa GP', 'gallery_sort' => 'random_day'],
        ]],
    ], 'options');
    update_option('evk_rep_opt_gp_opcje', ['lista_gp' => [
        ['tytul_gp' => 'Pierwsza', 'galeria_gp' => $galeria([$o[0], $o[1]]),       'losowa_gp' => $galeria([$o[0], $o[1], $o[2]])],
        ['tytul_gp' => 'Pusta',    'galeria_gp' => [],                              'losowa_gp' => []],
        ['tytul_gp' => 'Trzecia',  'galeria_gp' => $galeria([$o[2]]),               'losowa_gp' => $galeria([$o[3], $o[4], $o[5], $o[6]])],
    ]], false);

    /* Grupa-lista na stronie ustawień: opcja to sama lista wierszy. */
    evk_gp_grupa('gp_lista', 'Lista GP', [
        'nazwa_l'   => ['type' => 'text',    'label' => 'Nazwa L'],
        'galeria_l' => ['type' => 'gallery', 'label' => 'Galeria L'],
    ], 'options', true);
    update_option('evk_rep_opt_gp_lista', [
        ['nazwa_l' => 'A', 'galeria_l' => $galeria([$o[4]])],
        ['nazwa_l' => 'B', 'galeria_l' => $galeria([$o[5], $o[6]])],
    ], false);

    /* Grupa wpisów z polem-listą, w tym galeria wrażliwa (tylko z kluczem wpisu). */
    evk_gp_grupa('gp_wpis', 'Wpis GP', [
        'lista_w' => ['type' => 'repeater', 'label' => 'Lista W', 'sub_fields' => [
            'gal_w'    => ['type' => 'gallery', 'label' => 'Galeria W'],
            'tajna_w'  => ['type' => 'gallery', 'label' => 'Tajna W', 'sensitive' => true],
        ]],
    ], 'post');
    $wpis = (int) wp_insert_post(['post_title' => 'Wpis GP', 'post_status' => 'publish']);
    update_post_meta($wpis, '_evk_test_gp', '1');
    update_post_meta($wpis, 'lista_w', [
        ['gal_w' => $galeria([$o[6]]), 'tajna_w' => $galeria([$o[0]])],
        ['gal_w' => $galeria([$o[5], $o[4]]), 'tajna_w' => []],
    ]);
    evk_groups_cache_clear();
    file_put_contents($plik, wp_json_encode(['wpis' => $wpis, 'obrazy' => $o]));
    $out['gotowe'] = $wpis > 0 && count(array_filter($o)) === 7;
    break;

case 'tagi':
    $o    = array_map('intval', $zapis['obrazy'] ?? []);
    $post = get_post((int) ($zapis['wpis'] ?? 0));
    $tag  = static function (string $t, string $kontekst = 'text', $p = null) { return evk_rep_render_tag($t, $p, $kontekst); };
    $out['obrazy'] = $o;
    $out['url_1']  = (string) wp_get_attachment_image_url($o[0] ?? 0, 'full');

    // Kontekst obrazu (natywna Image Gallery): cała lista.
    $out['obraz'] = [
        'opcje'        => $tag('{evk_galflatopt_gp_opcje.lista_gp.galeria_gp}', 'image'),
        'opcje_ids'    => $tag('{evk_galflatopt_gp_opcje.lista_gp.galeria_gp:ids}', 'image'),
        'opcje_id'     => $tag('{evk_galflatopt_gp_opcje.lista_gp.galeria_gp:id}', 'image'),
        'grupa_lista'  => $tag('{evk_galflatopt_gp_lista.galeria_l}', 'image'),
        'wpis'         => $tag('{evk_galflat_lista_w.gal_w}', 'image', $post),
        'nieznana'     => $tag('{evk_galflatopt_gp_opcje.lista_gp.nie_ma}', 'image'),
        'pole_z_listy' => $tag('{evk_field_galeria_gp}', 'image'),
        'losowa'       => $tag('{evk_galflatopt_gp_opcje.lista_gp.losowa_gp}', 'media'),
    ];
    // Kolejność: ta sama co wiersze pętli „EVK Galeria — wszystkie wiersze" (Isotope).
    $out['petla'] = [
        'opcje'  => evk_gp_petla('evk_galflatopt_gp_opcje.lista_gp.galeria_gp'),
        'losowa' => evk_gp_petla('evk_galflatopt_gp_opcje.lista_gp.losowa_gp'),
        'wpis'   => evk_gp_petla('evk_galflat_lista_w.gal_w', (int) ($zapis['wpis'] ?? 0)),
    ];
    $out['losowa_zapisana'] = [$o[0], $o[1], $o[2], $o[3], $o[4], $o[5], $o[6]];

    // Galeria wrażliwa: gość nic, redakcja wszystko.
    $out['tajna_gosc'] = $tag('{evk_galflat_lista_w.tajna_w}', 'image', $post);
    wp_set_current_user(1);
    $out['tajna_admin'] = $tag('{evk_galflat_lista_w.tajna_w}', 'image', $post);
    wp_set_current_user(0);

    // Tekst: jak pole galerii.
    $out['tekst'] = [
        'domyslnie' => $tag('{evk_galflatopt_gp_opcje.lista_gp.galeria_gp}'),
        'ids'       => $tag('{evk_galflatopt_gp_opcje.lista_gp.galeria_gp__ids}'),
        'count'     => $tag('{evk_galflatopt_gp_opcje.lista_gp.galeria_gp__count}'),
        'count_mod' => $tag('{evk_galflatopt_gp_opcje.lista_gp.galeria_gp:count}'),
        'nieznana'  => $tag('{evk_galflatopt_gp_opcje.lista_gp.nie_ma}'),
    ];
    $out['mieszana'] = evk_rep_render_content('Zdjęć: {evk_galflatopt_gp_opcje.lista_gp.galeria_gp__count}, w liście: {evk_galflatopt_gp_lista.galeria_l:count}, ID: {evk_galflat_lista_w.gal_w__ids}.', $post);

    // Podpowiedzi Bricksa.
    $podp = [];
    foreach ((array) apply_filters('bricks/dynamic_tags_list', []) as $t) {
        if (strpos((string) ($t['name'] ?? ''), '{evk_galflat') === 0) $podp[$t['name']] = ($t['group'] ?? '') . ' | ' . ($t['label'] ?? '');
    }
    $out['podpowiedzi'] = $podp;

    // Ściąga pola galerii w kreatorze (ekran kreatora ma już funkcje ról z wp-admin).
    require_once ABSPATH . 'wp-admin/includes/user.php';
    ob_start();
    evk_rep_builder_field_row('evk_fields[0]', ['_key' => 'galeria_x', 'type' => 'gallery', 'label' => 'Galeria X'], true);
    $sciaga = (string) ob_get_clean();
    $out['sciaga'] = [
        'plaska'      => strpos($sciaga, '{evk_galflat_lista.galeria_x}') !== false,
        'plaska_opcji' => strpos($sciaga, '{evk_galflatopt_grupa.lista.galeria_x}') !== false,
        'tylko_w_petli' => strpos($sciaga, 'działa tylko w pętli po wierszach') !== false,
    ];
    break;

case 'sprzataj':
    evk_gp_sprzataj();
    @unlink($plik);
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
