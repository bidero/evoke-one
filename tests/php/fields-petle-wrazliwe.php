<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Pętle Bricksa nad „Polem wrażliwym" (Evoke FIELDS 1.74.0): gość bez klucza dostaje pustą
 * pętlę, jak tag tego pola; redakcja i gość z kluczem TEGO wpisu — pełną. Czwarty testowy
 * WordPress (pola.test, obie wtyczki).
 *
 *   php tests/php/fields-petle-wrazliwe.php ustaw      grupy, wpis z wartościami, drugi wpis, obrazy, term, wpis powiązany
 *   php tests/php/fields-petle-wrazliwe.php petle      każda pętla: gość, redakcja
 *   php tests/php/fields-petle-wrazliwe.php klucz      gość z kluczem wpisu (osobny proces — klucz liczony raz na żądanie)
 *   php tests/php/fields-petle-wrazliwe.php sprzataj
 *
 * Pętle idą przez `bricks/query/run`, jak w Bricksie. Bricksa tu nie ma.
 */
$evk_czwarty = true;
$evk_tryb    = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';

if (!function_exists('evk_rep_loops') || !function_exists('evk_protect_field_blocked')) {
    echo json_encode(['brak' => 'Evoke FIELDS nieaktywny na ' . home_url() . ' — tools/testowy-wp.sh (repozytorium Fields: EVK_FIELDS_REPO, domyślnie ../evoke-fields)']);
    exit;
}

$plik  = sys_get_temp_dir() . '/evk-t-fields-pw.json';
$zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
$out   = ['tryb' => $evk_tryb];

function evk_pw_grupa(string $klucz, string $etykieta, array $pola, string $typ): int {
    $id = (int) wp_insert_post(['post_type' => 'evk_field_group', 'post_status' => 'publish', 'post_title' => $etykieta]);
    update_post_meta($id, '_evk_test_pw', '1');
    update_post_meta($id, '_evk_key', $klucz);
    update_post_meta($id, '_evk_fields', wp_slash(wp_json_encode($pola)));
    update_post_meta($id, '_evk_post_types', ['post']);
    update_post_meta($id, '_evk_object_type', $typ);
    update_post_meta($id, '_evk_repeater', 0);
    return $id;
}

function evk_pw_obrazek(string $nazwa): int {
    $img = imagecreatetruecolor(160, 100);
    imagefilledrectangle($img, 0, 0, 159, 99, imagecolorallocate($img, 90, 30, 60));
    ob_start();
    imagejpeg($img, null, 80);
    $dane = (string) ob_get_clean();
    imagedestroy($img);
    $plik = wp_upload_bits($nazwa, null, $dane);
    $id = (int) wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => $nazwa, 'post_status' => 'inherit'], $plik['file']);
    wp_update_attachment_metadata($id, ['width' => 160, 'height' => 100, 'file' => _wp_relative_upload_path($plik['file'])]);
    update_post_meta($id, '_evk_test_pw', '1');
    return $id;
}

function evk_pw_sprzataj(): void {
    foreach (get_posts(['post_type' => ['evk_field_group', 'post', 'attachment'], 'post_status' => 'any', 'numberposts' => -1,
        'meta_key' => '_evk_test_pw', 'meta_value' => '1']) as $p) {
        wp_delete_post($p->ID, true);
    }
    $t = get_term_by('slug', 'kat-pw', 'category');
    if ($t) wp_delete_term((int) $t->term_id, 'category');
    delete_option('evk_rep_opt_pw_opcje');
    if (function_exists('evk_groups_cache_clear')) evk_groups_cache_clear();
    unset($GLOBALS['evk_rep_loops_memo']);
}

/** Liczba wierszy pętli `bricks/query/run` dla wpisu $pid (z wierszem repeatera na stosie, gdy podany). */
function evk_pw_petla(string $klucz, int $pid, ?array $wiersz_listy = null): int {
    $GLOBALS['evk_rep_stack'] = $wiersz_listy === null ? [] : [[
        'qid' => 1, 'path' => 'lista_pw', 'fields' => [], 'row' => $wiersz_listy, 'post_id' => $pid,
    ]];
    $q = new stdClass();
    $q->object_type = $klucz;
    $q->post_id     = $pid;
    $q->settings    = [];
    $wynik = apply_filters('bricks/query/run', [], $q);
    $GLOBALS['evk_rep_stack'] = [];
    return is_array($wynik) ? count($wynik) : -1;
}

/** Wszystkie badane pętle dla wpisu $pid. */
function evk_pw_wszystkie(array $zapis, int $pid): array {
    $wiersz = get_post_meta($pid, 'lista_pw', true);
    $wiersz = is_array($wiersz) && isset($wiersz[0]) ? $wiersz[0] : [];
    return [
        'galeria'          => evk_pw_petla('gal_pw', $pid),
        'galeria_kategorie' => evk_pw_petla('evk_galcat_gal_pw', $pid),
        'relacja'          => evk_pw_petla('rel_pw', $pid),
        'uzytkownicy'      => evk_pw_petla('uz_pw', $pid),
        'termy'            => evk_pw_petla('kat_pw', $pid),
        'galeria_w_wierszu' => evk_pw_petla('lista_pw.galr_pw', $pid, $wiersz),
        'galeria_plaska'   => evk_pw_petla('evk_galflat_lista_pw.galr_pw', $pid),
        'kategorie_plaskie' => evk_pw_petla('evk_galcatflat_lista_pw.galr_pw', $pid),
        'jawna'            => evk_pw_petla('gal_jawna', $pid),
        'jawna_relacja'    => evk_pw_petla('rel_jawna', $pid),
        'opcje'            => evk_pw_petla('evk_opt_pw_opcje.galo_pw', 0),
    ];
}

switch ($evk_tryb) {

case 'ustaw':
    evk_pw_sprzataj();
    $o = [];
    for ($i = 1; $i <= 3; $i++) $o[] = evk_pw_obrazek('evk-t-pw-' . $i . '.jpg');
    $t   = wp_insert_term('Kategoria PW', 'category', ['slug' => 'kat-pw']);
    $tid = is_wp_error($t) ? 0 : (int) $t['term_id'];
    $pow = (int) wp_insert_post(['post_title' => 'Powiązany PW', 'post_status' => 'publish']);
    update_post_meta($pow, '_evk_test_pw', '1');
    $kategorie = "a : Alfa\nb : Beta";
    $gal = function (array $ids) { return array_map(function ($id, $i) { return ['img' => $id, 'cat' => $i % 2 ? 'b' : 'a']; }, $ids, array_keys($ids)); };

    evk_pw_grupa('pw_wpis', 'Wpis PW', [
        'gal_pw'    => ['type' => 'gallery', 'label' => 'Galeria PW', 'sensitive' => true, 'gallery_categories' => $kategorie],
        'rel_pw'    => ['type' => 'relationship', 'label' => 'Relacja PW', 'sensitive' => true],
        'uz_pw'     => ['type' => 'user', 'label' => 'Użytkownik PW', 'sensitive' => true],
        'kat_pw'    => ['type' => 'taxonomy', 'label' => 'Kategoria PW', 'taxonomy' => 'category', 'sensitive' => true],
        'lista_pw'  => ['type' => 'repeater', 'label' => 'Lista PW', 'sub_fields' => [
            'galr_pw' => ['type' => 'gallery', 'label' => 'Galeria w wierszu PW', 'sensitive' => true, 'gallery_categories' => $kategorie],
        ]],
        'gal_jawna' => ['type' => 'gallery', 'label' => 'Galeria jawna'],
        'rel_jawna' => ['type' => 'relationship', 'label' => 'Relacja jawna'],
    ], 'post');
    evk_pw_grupa('pw_opcje', 'Opcje PW', [
        'galo_pw' => ['type' => 'gallery', 'label' => 'Galeria opcji PW', 'sensitive' => true],
    ], 'options');

    $wartosci = function (int $pid) use ($o, $gal, $pow, $tid) {
        update_post_meta($pid, '_evk_test_pw', '1');
        update_post_meta($pid, 'gal_pw', $gal($o));
        update_post_meta($pid, 'rel_pw', [$pow]);
        update_post_meta($pid, 'uz_pw', [1]);
        update_post_meta($pid, 'kat_pw', [$tid]);
        update_post_meta($pid, 'lista_pw', [['galr_pw' => $gal([$o[0], $o[1]])]]);
        update_post_meta($pid, 'gal_jawna', $gal([$o[2]]));
        update_post_meta($pid, 'rel_jawna', [$pow]);
    };
    $wpis  = (int) wp_insert_post(['post_title' => 'Wpis PW', 'post_status' => 'publish']);
    $drugi = (int) wp_insert_post(['post_title' => 'Drugi PW', 'post_status' => 'publish']);
    $wartosci($wpis);
    $wartosci($drugi);
    update_option('evk_rep_opt_pw_opcje', ['galo_pw' => $gal([$o[1]])], false);
    evk_groups_cache_clear();
    // Klucz dostępu wpisu (metabox „kopiuj link") — zwykły wpis dostaje go dopiero na żądanie.
    $klucz = evk_protect_ensure_key($wpis);
    evk_protect_ensure_key($drugi);
    file_put_contents($plik, wp_json_encode(['wpis' => $wpis, 'drugi' => $drugi, 'klucz' => $klucz]));
    $out['gotowe'] = $wpis && $drugi && $pow && $tid && $klucz !== '';
    break;

case 'petle':
    $wpis = (int) ($zapis['wpis'] ?? 0);
    wp_set_current_user(0);
    $out['gosc'] = evk_pw_wszystkie($zapis, $wpis);
    // Tag tej samej galerii — punkt odniesienia (bramka tagów istniała przed 1.74.0).
    $out['tag_gosc'] = evk_rep_render_tag('{evk_field_gal_pw}', get_post($wpis), 'image');
    wp_set_current_user(1);
    $out['redakcja'] = evk_pw_wszystkie($zapis, $wpis);
    wp_set_current_user(0);
    break;

case 'klucz':
    // Gość wchodzi na stronę wpisu z jego kluczem: ?key= i obiekt zapytania = ten wpis.
    $wpis = (int) ($zapis['wpis'] ?? 0);
    wp_set_current_user(0);
    $_GET['key'] = (string) ($zapis['klucz'] ?? '');
    $GLOBALS['wp_query']->queried_object    = get_post($wpis);
    $GLOBALS['wp_query']->queried_object_id = $wpis;
    $out['autoryzowany'] = evk_protect_authorized_post() === $wpis;
    $out['ten_wpis']     = evk_pw_wszystkie($zapis, $wpis);
    $out['inny_wpis']    = evk_pw_wszystkie($zapis, (int) ($zapis['drugi'] ?? 0));
    break;

case 'sprzataj':
    evk_pw_sprzataj();
    @unlink($plik);
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
