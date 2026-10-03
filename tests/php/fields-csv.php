<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Repeatery w CSV Evoke FIELDS (Fields 1.79.0) na CZWARTYM testowym
 * WordPressie (pola.test, obie wtyczki — tools/testowy-wp.sh). Import
 * i eksport idą w teście przez prawdziwy panel w Chromium; sonda stawia dane
 * i czyta, co naprawdę zapisało się w bazie.
 *
 *   php tests/php/fields-csv.php ustaw      typ pola_csv, grupy, dwa obrazy, wpisy B i C, użytkownik, term, strona ustawień
 *   php tests/php/fields-csv.php stan       wiersze repeaterów wpisów testu i wartości strony ustawień
 *   php tests/php/fields-csv.php sprzataj   wszystko, co postawił „ustaw”
 */
$evk_czwarty = true;
$evk_tryb    = $argv[1] ?? '';
require __DIR__ . '/_testowy-wp.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if (!function_exists('evk_csv_rep_targets')) {
    echo json_encode(['brak' => 'Evoke FIELDS 1.79.0+ (repeatery w CSV) nieaktywny na ' . home_url() . ' — tools/testowy-wp.sh (repozytorium Fields: EVK_FIELDS_REPO, domyślnie ../evoke-fields)']);
    exit;
}

const EVK_TC_ZNACZNIK = '_evk_test_csv';

function evk_tc_cpt(): void {
    if (!post_type_exists('pola_csv')) register_post_type('pola_csv', ['label' => 'Pola CSV', 'public' => true, 'show_ui' => true, 'show_in_rest' => false, 'supports' => ['title', 'editor']]);
}

function evk_tc_grupa(string $klucz, string $etykieta, array $pola, array $typy, array $meta = []): int {
    $id = (int) wp_insert_post(['post_type' => 'evk_field_group', 'post_status' => 'publish', 'post_title' => $etykieta]);
    update_post_meta($id, EVK_TC_ZNACZNIK, '1');
    update_post_meta($id, '_evk_key', $klucz);
    update_post_meta($id, '_evk_fields', wp_slash(wp_json_encode($pola)));
    update_post_meta($id, '_evk_post_types', $typy);
    update_post_meta($id, '_evk_object_type', 'post');
    foreach ($meta as $k => $v) update_post_meta($id, $k, $v);
    return $id;
}

/** Obraz PNG w uploads jako załącznik (z rozmiarami — adres „-150x150” istnieje naprawdę). */
function evk_tc_obraz(string $nazwa, array $kolor): int {
    $im = imagecreatetruecolor(400, 300);
    imagefill($im, 0, 0, imagecolorallocate($im, $kolor[0], $kolor[1], $kolor[2]));
    ob_start(); imagepng($im); $png = (string) ob_get_clean();
    $u = wp_upload_bits($nazwa . '.png', null, $png);
    $id = (int) wp_insert_attachment(['post_title' => $nazwa, 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], $u['file']);
    update_post_meta($id, EVK_TC_ZNACZNIK, '1');
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $u['file']));
    return $id;
}

function evk_tc_wpis(string $slug): int {
    $p = get_posts(['post_type' => 'pola_csv', 'name' => $slug, 'post_status' => 'any', 'numberposts' => 1, 'fields' => 'ids']);
    return $p ? (int) $p[0] : 0;
}

function evk_tc_sprzataj(): void {
    global $wpdb;
    foreach ((array) $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'pola_csv'") as $id) wp_delete_post((int) $id, true);
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", EVK_TC_ZNACZNIK)) as $id) {
        get_post_type((int) $id) === 'attachment' ? wp_delete_attachment((int) $id, true) : wp_delete_post((int) $id, true);
    }
    if ($u = get_user_by('login', 'csv_autor')) wp_delete_user($u->ID);
    foreach (['kategoria-csv', 'druga-csv'] as $slug) { $t = get_term_by('slug', $slug, 'category'); if ($t) wp_delete_term((int) $t->term_id, 'category'); }
    foreach (['csv_opcje', 'csv_tabela'] as $g) delete_option('evk_rep_opt_' . $g);
    $strony = get_option('evk_rep_settings_pages', []);
    if (is_array($strony)) { unset($strony['csv-ustawienia']); update_option('evk_rep_settings_pages', $strony); }
    @unlink(WP_CONTENT_DIR . '/mu-plugins/evk-pola-csv.php');
    if (function_exists('evk_groups_cache_clear')) evk_groups_cache_clear();
}

wp_set_current_user(1);
evk_tc_cpt();
$wynik = [];

switch ($evk_tryb) {

case 'ustaw':
    evk_tc_sprzataj();
    $mu = WP_CONTENT_DIR . '/mu-plugins';
    wp_mkdir_p($mu);
    file_put_contents($mu . '/evk-pola-csv.php', "<?php\n// Test fields-csv (Evoke ONE, tests/php/fields-csv.php): typ treści testu.\n"
        . "add_action('init', function () {\n    register_post_type('pola_csv', ['label' => 'Pola CSV', 'public' => true, 'show_ui' => true, 'show_in_rest' => false, 'supports' => ['title', 'editor']]);\n});\n");
    /* Języki z Evoke ONE (filtr evk_fields_jezyki): angielski. */
    update_option('evk_tl_module_enabled', 1);
    update_option('tl_languages', [['code' => 'en', 'name' => 'English', 'html' => 'en-US']]);

    $t = wp_insert_term('Kategoria CSV', 'category', ['slug' => 'kategoria-csv']);
    $wynik['term'] = is_wp_error($t) ? 0 : (int) $t['term_id'];
    $wynik['autor'] = (int) wp_insert_user(['user_login' => 'csv_autor', 'user_pass' => 'test-haslo', 'user_email' => 'csv_autor@example.test', 'role' => 'author']);
    $wynik['img1'] = evk_tc_obraz('evk-csv-czerwony', [200, 30, 30]);
    $wynik['img2'] = evk_tc_obraz('evk-csv-niebieski', [30, 30, 200]);
    $wynik['img2_maly'] = (string) (wp_get_attachment_image_src($wynik['img2'], 'thumbnail')[0] ?? '');
    $wynik['img1_url'] = (string) wp_get_attachment_url($wynik['img1']);

    evk_tc_grupa('csv_pakiety', 'Pakiety', [
        'tytul'      => ['type' => 'text', 'label' => 'Tytuł pakietu'],
        'cena'       => ['type' => 'number', 'label' => 'Cena'],
        'ikona'      => ['type' => 'image', 'label' => 'Ikona'],
        'galeria'    => ['type' => 'gallery', 'label' => 'Galeria'],
        'polecane'   => ['type' => 'relationship', 'label' => 'Polecane', 'rel_post_types' => ['pola_csv'], 'rel_multiple' => true],
        'kategoria'  => ['type' => 'taxonomy', 'label' => 'Kategoria', 'taxonomy' => 'category'],
        'autor'      => ['type' => 'user', 'label' => 'Autor'],
        'przycisk'   => ['type' => 'link', 'label' => 'Przycisk'],
        'wyrozniony' => ['type' => 'checkbox', 'label' => 'Wyróżniony'],
        'kolor'      => ['type' => 'select', 'label' => 'Kolor', 'options' => "czerwony : Czerwony\nzielony : Zielony"],
    ], ['pola_csv'], ['_evk_repeater' => 1]);
    evk_tc_grupa('csv_dane', 'Dane wpisu', [
        'kod' => ['type' => 'text', 'label' => 'Kod'],
        'faq' => ['type' => 'repeater', 'label' => 'FAQ', 'sub_fields' => [
            'pytanie'   => ['type' => 'text', 'label' => 'Pytanie'],
            'odpowiedz' => ['type' => 'textarea', 'label' => 'Odpowiedź'],
        ]],
    ], ['pola_csv']);
    /* Strona ustawień: grupa zwykła z polem-repeaterem i grupa-repeater (tabela). */
    evk_tc_grupa('csv_opcje', 'Opcje CSV', [
        'slogan' => ['type' => 'text', 'label' => 'Slogan'],
        'logo'   => ['type' => 'image', 'label' => 'Logo'],
        'kontakt' => ['type' => 'link', 'label' => 'Kontakt'],
        'linki'  => ['type' => 'repeater', 'label' => 'Linki', 'sub_fields' => [
            'nazwa' => ['type' => 'text', 'label' => 'Nazwa'],
            'adres' => ['type' => 'link', 'label' => 'Adres'],
        ]],
    ], ['evk_brak']);
    evk_tc_grupa('csv_tabela', 'Oddziały', [
        'miasto'  => ['type' => 'text', 'label' => 'Miasto'],
        'telefon' => ['type' => 'text', 'label' => 'Telefon'],
        'zdjecie' => ['type' => 'image', 'label' => 'Zdjęcie'],
    ], ['evk_brak'], ['_evk_repeater' => 1]);
    $strony = get_option('evk_rep_settings_pages', []);
    $strony = is_array($strony) ? $strony : [];
    $strony['csv-ustawienia'] = ['label' => 'Ustawienia CSV', 'slug' => 'csv-ustawienia', 'icon' => 'dashicons-admin-generic',
        'capability' => 'manage_options', 'parent' => '', 'hide_title' => 0, 'tabs' => [['label' => 'Ogólne', 'groups' => ['csv_opcje', 'csv_tabela']]]];
    update_option('evk_rep_settings_pages', $strony);
    evk_groups_cache_clear();

    /* Wpis B ma już dwa wiersze pakietów (jeden z tłumaczeniem AI do sprawdzenia) i FAQ — do „dopisz” i „zastąp”. */
    $b = (int) wp_insert_post(['post_title' => 'Wpis B', 'post_name' => 'wpis-b', 'post_type' => 'pola_csv', 'post_status' => 'publish']);
    update_post_meta($b, 'csv_pakiety', [
        ['tytul' => 'Stary 1', 'cena' => 10, 'evk_tl_en__tytul' => 'Old 1', 'evk_tl_en__tytul__zrodlo' => 'ai-' . evk_rep_tl_src('Stary 1')],
        ['tytul' => 'Stary 2', 'cena' => 20],
    ]);
    update_post_meta($b, 'faq', [['pytanie' => 'Stare pytanie', 'odpowiedz' => 'Stara odpowiedź']]);
    $c = (int) wp_insert_post(['post_title' => 'Wpis C', 'post_name' => 'wpis-c', 'post_type' => 'pola_csv', 'post_status' => 'publish']);
    update_post_meta($c, 'csv_pakiety', [['tytul' => 'Zostaje', 'cena' => 5]]);
    update_post_meta($c, 'faq', [['pytanie' => 'Też zostaje']]);
    $wynik['b'] = $b;
    $wynik['c'] = $c;
    $wynik['wp'] = rtrim(ABSPATH, '/');
    $wynik['jezyki'] = evk_rep_tl_langs();
    break;

case 'stan':
    foreach (['csv-nowy', 'wpis-b', 'wpis-c'] as $slug) {
        $id = evk_tc_wpis($slug);
        $wynik['wpisy'][$slug] = $id ? ['id' => $id, 'kod' => get_post_meta($id, 'kod', true),
            'pakiety' => get_post_meta($id, 'csv_pakiety', true), 'faq' => get_post_meta($id, 'faq', true)] : null;
    }
    $wynik['liczba'] = count(get_posts(['post_type' => 'pola_csv', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids']));
    $wynik['opcje']  = get_option('evk_rep_opt_csv_opcje', null);
    $wynik['tabela'] = get_option('evk_rep_opt_csv_tabela', null);
    break;

case 'opcje-ustaw':
    /* Wartości strony ustawień do eksportu (i porównania po imporcie). */
    $img1 = (int) ($argv[2] ?? 0);
    update_option('evk_rep_opt_csv_opcje', ['slogan' => 'Najlepsi w mieście', 'evk_tl_en__slogan' => 'Best in town', 'evk_tl_en__slogan__zrodlo' => evk_rep_tl_src('Najlepsi w mieście'),
        'logo' => $img1, 'kontakt' => ['url' => 'https://example.test/kontakt', 'title' => 'Napisz'], 'linki' => [['nazwa' => 'Facebook', 'adres' => ['url' => 'https://facebook.test/x', 'title' => 'FB', 'target' => '_blank']], ['nazwa' => 'Blog']]], false);
    update_option('evk_rep_opt_csv_tabela', [['miasto' => 'Kraków', 'telefon' => '+48 12 000 00 00', 'zdjecie' => $img1], ['miasto' => 'Gdańsk', 'telefon' => '+48 58 000 00 00']], false);
    $wynik['ok'] = true;
    break;

case 'opcje-wyczysc':
    delete_option('evk_rep_opt_csv_opcje');
    delete_option('evk_rep_opt_csv_tabela');
    $wynik['ok'] = true;
    break;

case 'sprzataj':
    evk_tc_sprzataj();
    $wynik['ok'] = true;
    break;

default:
    $wynik['blad'] = 'nieznany tryb';
}
echo wp_json_encode($wynik);
