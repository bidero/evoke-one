<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Modyfikatory tagów Evoke FIELDS 1.72.0 (jak w Bricksie: `{evk_field_opis:plain:20}`)
 * na CZWARTYM testowym WordPressie (pola.test, obie wtyczki).
 *
 *   php tests/php/fields-modyfikatory.php ustaw      grupy, wpis z wartościami, obrazy, term, wpis powiązany
 *   php tests/php/fields-modyfikatory.php tagi       każdy modyfikator, łańcuch, zgodność __prop, treść mieszana, kontekst obrazu
 *   php tests/php/fields-modyfikatory.php jezyk      tłumaczenie wartości (1.70) + modyfikator
 *   php tests/php/fields-modyfikatory.php sprzataj
 *
 * Tagi idą przez te same funkcje, które Bricks woła filtrami
 * `bricks/dynamic_data/render_tag` (jeden tag) i `bricks/dynamic_data/render_content`
 * (tekst z tagami). Bricksa tu nie ma.
 */
$evk_czwarty = true;
$evk_tryb    = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';

if (!function_exists('evk_rep_render_tag') || !function_exists('evk_rep_formatuj')) {
    echo json_encode(['brak' => 'Evoke FIELDS 1.72.0+ nieaktywny na ' . home_url() . ' — tools/testowy-wp.sh (repozytorium Fields: EVK_FIELDS_REPO, domyślnie ../evoke-fields)']);
    exit;
}

$plik  = sys_get_temp_dir() . '/evk-t-fields-gm.json';
$zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
$out   = ['tryb' => $evk_tryb];
/* Własny rozmiar obrazka — `:evk_gm` ma przejść, choć `__evk_gm` nie przechodzi whitelisty. */
add_image_size('evk_gm', 120, 80, true);

function evk_gm_grupa(string $klucz, string $etykieta, array $pola, string $typ): int {
    $id = (int) wp_insert_post(['post_type' => 'evk_field_group', 'post_status' => 'publish', 'post_title' => $etykieta]);
    update_post_meta($id, '_evk_test_gm', '1');
    update_post_meta($id, '_evk_key', $klucz);
    update_post_meta($id, '_evk_fields', wp_slash(wp_json_encode($pola)));
    update_post_meta($id, '_evk_post_types', ['post']);
    update_post_meta($id, '_evk_object_type', $typ);
    return $id;
}

function evk_gm_obrazek(string $nazwa): int {
    $img = imagecreatetruecolor(240, 160);
    imagefilledrectangle($img, 0, 0, 239, 159, imagecolorallocate($img, 120, 60, 30));
    ob_start();
    imagejpeg($img, null, 80);
    $dane = (string) ob_get_clean();
    imagedestroy($img);
    $plik = wp_upload_bits($nazwa, null, $dane);
    $id = (int) wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => $nazwa, 'post_status' => 'inherit'], $plik['file']);
    wp_update_attachment_metadata($id, ['width' => 240, 'height' => 160, 'file' => _wp_relative_upload_path($plik['file'])]);
    update_post_meta($id, '_evk_test_gm', '1');
    return $id;
}

function evk_gm_sprzataj(array $zapis): void {
    foreach (get_posts(['post_type' => ['evk_field_group', 'post', 'attachment'], 'post_status' => 'any', 'numberposts' => -1,
        'meta_key' => '_evk_test_gm', 'meta_value' => '1']) as $p) {
        wp_delete_post($p->ID, true);
    }
    $t = get_term_by('slug', 'kat-gm-slug', 'category');
    if ($t) wp_delete_term((int) $t->term_id, 'category');
    delete_option('evk_rep_opt_gm_opcje');
    if (function_exists('evk_groups_cache_clear')) evk_groups_cache_clear();
}

switch ($evk_tryb) {

case 'ustaw':
    evk_gm_sprzataj($zapis);
    evk_gm_grupa('gm_opcje', 'Opcje GM', ['haslo_gm' => ['type' => 'text', 'label' => 'Hasło GM']], 'options');
    evk_gm_grupa('gm_wpis', 'Wpis GM', [
        'opis_gm'  => ['type' => 'wysiwyg', 'label' => 'Opis GM'],
        'tytul_gm' => ['type' => 'text', 'label' => 'Tytuł GM'],
        'tlum_gm'  => ['type' => 'text', 'label' => 'Tłumaczony GM'],
        'adres_gm' => ['type' => 'text', 'label' => 'Adres GM'],
        'data_gm'  => ['type' => 'date', 'label' => 'Data GM'],
        'czas_gm'  => ['type' => 'datetime', 'label' => 'Czas GM'],
        'wybor_gm' => ['type' => 'select', 'label' => 'Wybór GM', 'options' => "a : Alfa\nb : Beta"],
        'link_gm'  => ['type' => 'link', 'label' => 'Link GM'],
        'obraz_gm' => ['type' => 'image', 'label' => 'Obraz GM'],
        'gal_gm'   => ['type' => 'gallery', 'label' => 'Galeria GM'],
        'kat_gm'   => ['type' => 'taxonomy', 'label' => 'Kategoria GM', 'taxonomy' => 'category'],
        'rel_gm'   => ['type' => 'relationship', 'label' => 'Powiązany GM'],
        'autor_gm' => ['type' => 'user', 'label' => 'Autor GM'],
    ], 'post');
    $o1 = evk_gm_obrazek('evk-t-gm-1.jpg');
    $o2 = evk_gm_obrazek('evk-t-gm-2.jpg');
    /* Slug inny niż nazwa: `:slug` taksonomii to prop (slug termu), nie sanitize_title nazwy. */
    $t = wp_insert_term('Kategoria GM', 'category', ['slug' => 'kat-gm-slug']);
    $tid = is_wp_error($t) ? 0 : (int) $t['term_id'];
    $pow = (int) wp_insert_post(['post_title' => 'Powiązany GM', 'post_name' => 'powiazany-gm', 'post_status' => 'publish']);
    update_post_meta($pow, '_evk_test_gm', '1');
    update_post_meta($pow, 'kolor_gm', 'Zielony Kolor');
    update_post_meta($pow, 'plain', 'meta-o-nazwie-plain');
    $wpis = (int) wp_insert_post(['post_title' => 'Wpis GM', 'post_status' => 'publish']);
    update_post_meta($wpis, '_evk_test_gm', '1');
    foreach ([
        'opis_gm'  => '<p>Pierwsze <strong>drugie</strong> trzecie &amp; czwarte piąte szóste</p>',
        'tytul_gm' => 'Zażółć Gęślą Jaźń',
        'tlum_gm'  => 'Witaj przetłumaczony świecie tutaj', 'evk_tl_en__tlum_gm' => 'Hello translated world here',
        'adres_gm' => 'https://evoke.pl/kontakt',
        'data_gm'  => '2026-09-29',
        'czas_gm'  => '2026-09-29 14:05',
        'wybor_gm' => 'b',
        'obraz_gm' => $o1,
    ] as $k => $v) update_post_meta($wpis, $k, $v);
    update_post_meta($wpis, 'link_gm', ['url' => 'https://example.com/x', 'title' => 'Przykład', 'target' => '']);
    update_post_meta($wpis, 'gal_gm', [['img' => $o1], ['img' => $o2]]);
    update_post_meta($wpis, 'kat_gm', [$tid]);
    update_post_meta($wpis, 'rel_gm', [$pow]);
    update_post_meta($wpis, 'autor_gm', [1]);
    update_option('evk_rep_opt_gm_opcje', ['haslo_gm' => 'Hasło Opcji'], false);
    evk_groups_cache_clear();
    file_put_contents($plik, wp_json_encode(['wpis' => $wpis, 'pow' => $pow, 'term' => $tid, 'obrazy' => [$o1, $o2]]));
    $out['gotowe'] = $wpis && $pow && $tid && $o1 && $o2;
    break;

case 'tagi':
    $post = get_post((int) ($zapis['wpis'] ?? 0));
    $tag = static function (string $t, string $kontekst = 'text') use ($post) { return evk_rep_render_tag($t, $post, $kontekst); };
    $o1 = (int) ($zapis['obrazy'][0] ?? 0);
    $out['oczekiwane'] = [
        'url_obrazu'    => (string) wp_get_attachment_image_url($o1, 'full'),
        'url_rozmiaru'  => (string) wp_get_attachment_image_url($o1, 'evk_gm'),
        'link_termu'    => (string) get_term_link((int) ($zapis['term'] ?? 0)),
        'link_wpisu'    => (string) get_permalink((int) ($zapis['pow'] ?? 0)),
        'znacznik_daty' => (string) strtotime('2026-09-29'),
        'obrazy'        => $zapis['obrazy'] ?? [],
    ];
    foreach ([
        'plain'        => '{evk_field_opis_gm:plain}',
        'plain_3'      => '{evk_field_opis_gm:plain:3}',
        'slowa_3'      => '{evk_field_opis_gm:3}',
        'slug'         => '{evk_field_tytul_gm:slug}',
        'nieznany'     => '{evk_field_tytul_gm:nieznany}',
        'data'         => '{evk_field_data_gm:d.m.Y}',
        'godzina'      => '{evk_field_czas_gm:H:i}',
        'data_godzina' => '{evk_field_czas_gm:d.m.Y H:i}',
        'data_slug'    => '{evk_field_data_gm:slug:j F Y}',
        'znacznik'     => '{evk_field_data_gm:timestamp}',
        'data_raw'     => '{evk_field_data_gm:raw}',
        'data_raw_old' => '{evk_field_data_gm__raw}',
        'etykieta'     => '{evk_field_wybor_gm:label}',
        'etykieta_old' => '{evk_field_wybor_gm__label}',
        'wartosc'      => '{evk_field_wybor_gm:value}',
        'surowa'       => '{evk_field_wybor_gm:raw}',
        'link_url'     => '{evk_field_link_gm:url}',
        'link_a'       => '{evk_field_link_gm:link}',
        'link_etyk'    => '{evk_field_link_gm:label}',
        'obraz_id'     => '{evk_field_obraz_gm:id}',
        'obraz_id_old' => '{evk_field_obraz_gm__id}',
        'obraz_rozm'   => '{evk_field_obraz_gm:evk_gm}',
        'obraz_rozm_old' => '{evk_field_obraz_gm__evk_gm}',
        'kat_slug'     => '{evk_field_kat_gm:slug}',
        'kat_url'      => '{evk_field_kat_gm:url}',
        'kat_link'     => '{evk_field_kat_gm:link}',
        'rel_url'      => '{evk_field_rel_gm:url}',
        'rel_link'     => '{evk_field_rel_gm:link}',
        'meta'         => '{evk_field_rel_gm__meta:kolor_gm}',
        'meta_plain'   => '{evk_field_rel_gm__meta:plain}',
        'meta_slug'    => '{evk_field_rel_gm__meta:kolor_gm:slug}',
        'adres_link'   => '{evk_field_adres_gm:link}',
        'opcja_slug'   => '{evk_opt_gm_opcje_haslo_gm:slug}',
    ] as $nazwa => $t) {
        $out['tagi'][$nazwa] = $tag($t);
    }
    $out['obraz_ctx']  = $tag('{evk_field_obraz_gm:large}', 'image');
    $out['galeria_ctx'] = $tag('{evk_field_gal_gm:ids}', 'image');
    $out['awatar_ctx']  = $tag('{evk_field_autor_gm:avatar}', 'image');
    $out['oczekiwane']['awatar'] = (string) get_avatar_url(1);
    $out['mieszana'] = evk_rep_render_content('Data: {evk_field_data_gm:d.m.Y}, wybór: {evk_field_wybor_gm:label}, meta: {evk_field_rel_gm__meta:kolor_gm}, opcja: {evk_opt_gm_opcje_haslo_gm:slug}.', $post);
    break;

case 'jezyk':
    $post = get_post((int) ($zapis['wpis'] ?? 0));
    foreach (['pl' => '', 'en' => 'en'] as $nazwa => $kod) {
        $GLOBALS['lang_code'] = $kod;
        $out[$nazwa] = evk_rep_render_tag('{evk_field_tlum_gm:2}', $post, 'text');
    }
    $GLOBALS['lang_code'] = '';
    break;

case 'sprzataj':
    evk_gm_sprzataj($zapis);
    @unlink($plik);
    break;
}

echo wp_json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
