<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Żądania AJAX Bricksa na stronach w języku (1.253.1) — stan między
 * żądaniami testu tl-filtry-bricksa.
 *
 *   php tests/php/tl-filtry-bricksa.php wp
 *   php tests/php/tl-filtry-bricksa.php przygotuj http://127.0.0.1:<port>
 *   php tests/php/tl-filtry-bricksa.php przeplucz http://127.0.0.1:<port>   (osobny proces: Tłumaczenia wczytane)
 *   php tests/php/tl-filtry-bricksa.php sprzataj
 *
 * Bricksa tu nie ma, więc mu-plugin stawia jego atrapy w tych miejscach, które
 * widzi wtyczka:
 *   - skrypt `bricks-scripts` z `bricksData.restApiUrl` (jak drukuje go Bricks);
 *   - trasa `bricks/v1/query_result` — oddaje tablicę przez serwer REST;
 *   - trasa `bricks/v1/load_popup_content` — sama wysyła JSON (`wp_send_json` + `die`),
 *     bo nie wiadomo, którą drogą idzie prawdziwy Bricks.
 * Obie składają HTML jak Bricks: nazwa kategorii (get_term), tytuł i link
 * wpisu, fraza słownika przez `bricks/frontend/render_data`, `{tl:…}`, obrazek
 * z mapy obrazów w HTML-u i w stylach, pusty obiekt `popups`.
 */

if (in_array($argv[1] ?? '', ['przygotuj', 'przeplucz'], true) && preg_match('#^http://127\.0\.0\.1:\d+$#', $argv[2] ?? '')) {
    define('WP_HOME', $argv[2]);
    define('WP_SITEURL', $argv[2]);
}
require __DIR__ . '/_testowy-wp.php';

$krok   = $argv[1] ?? '';
$plik   = sys_get_temp_dir() . '/evk-t-tl-filtry-bricksa.json';
$mu     = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
$muPlik = $mu . '/evk-t-tl-filtry-bricksa.php';
$jsPlik = $mu . '/evk-t-tl-filtry-bricksa.js';
$opcje  = ['permalink_structure', 'show_on_front', 'evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'tl_url_slugs', 'tl_images'];
$out    = ['krok' => $krok];

/** Pamięć podręczna Tłumaczeń (nazwy jak w evoke-one.php). */
function evk_t_flt_bez_pamieci(): void {
    foreach (['tl_compiled_slugs', 'tl_compiled_config', 'tl_compiled_tokens_en', 'tl_compiled_tokens_de'] as $t) delete_transient($t);
}

/** Mały JPEG do biblioteki mediów. */
function evk_t_flt_obrazek(string $nazwa, array $kolor): int {
    $img = imagecreatetruecolor(40, 30);
    imagefilledrectangle($img, 0, 0, 39, 29, imagecolorallocate($img, $kolor[0], $kolor[1], $kolor[2]));
    ob_start();
    imagejpeg($img, null, 80);
    $dane = (string) ob_get_clean();
    imagedestroy($img);
    $plik = wp_upload_bits($nazwa, null, $dane);
    $id = (int) wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => $nazwa, 'post_status' => 'inherit'], $plik['file']);
    wp_update_attachment_metadata($id, ['width' => 40, 'height' => 30, 'file' => _wp_relative_upload_path($plik['file'])]);
    return $id;
}

/* Mu-plugin: atrapy Bricksa. Wynik niesie też wykryty język (`jezyk`) —
   do diagnozy, gdy sprawdzenie treści nie przejdzie. */
const EVK_T_FLT_MU = <<<'PHP'
<?php
// Wyłącznie test tl-filtry-bricksa (tests/php/tl-filtry-bricksa.php) — usuwany po teście.
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_script('bricks-scripts', content_url('mu-plugins/evk-t-tl-filtry-bricksa.js'), [], null, true);
    wp_localize_script('bricks-scripts', 'bricksData', ['restApiUrl' => rest_url('bricks/v1/'), 'language' => '']);
});
function evk_t_flt_wynik(): array {
    $term = get_term_by('slug', 'uslugi-flt', 'category');
    $wpis = get_page_by_path('wpis-flt', OBJECT, 'post');
    $obraz = (string) wp_get_attachment_url((int) get_option('evk_t_flt_obraz_pl'));
    $nazwa = $term instanceof WP_Term ? $term->name : '?';
    $html = '<ul class="t-wyniki"><li class="t-term">' . esc_html($nazwa) . '</li>'
        . '<li class="t-tytul">' . esc_html(get_the_title($wpis)) . '</li>'
        . '<li class="t-link"><a href="' . esc_url((string) get_permalink($wpis)) . '">link</a></li>'
        . '<li class="t-fraza">Zobacz więcej FLT</li>'
        . '<li class="t-obraz"><img src="' . esc_url($obraz) . '" alt=""></li></ul>';
    $html = apply_filters('bricks/frontend/render_data', $html, $wpis);
    return [
        'html'            => $html,
        'styles'          => '<style>.t-tlo{background-image:url(' . $obraz . ')}</style>',
        'popups'          => new stdClass(),
        'updated_filters' => ['f1' => '<label>' . esc_html($nazwa) . '</label><span class="t-inline">{tl:pl=Tak FLT|en=Yes FLT|de=Ja FLT}</span>'],
        'jezyk'           => get_current_lang(),
    ];
}
add_action('rest_api_init', function () {
    register_rest_route('bricks/v1', '/query_result', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => 'evk_t_flt_wynik']);
    register_rest_route('bricks/v1', '/load_popup_content', ['methods' => 'POST', 'permission_callback' => '__return_true',
        'callback' => function () { wp_send_json(evk_t_flt_wynik()); }]);
});
PHP;

switch ($krok) {

case 'wp':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'przygotuj':
    if (!defined('WP_HOME')) { $out['brak'] = 'przygotuj bez adresu serwera testowego'; break; }
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'wpisy' => [], 'terminy' => [], 'pliki' => [], 'mu_bylo' => is_dir($mu)]));
    }
    $zapis = json_decode((string) file_get_contents($plik), true);

    if (!is_dir($mu)) mkdir($mu, 0755, true);
    file_put_contents($muPlik, EVK_T_FLT_MU);
    file_put_contents($jsPlik, "/* atrapa bricks.min.js — test tl-filtry-bricksa */\n");

    $kat   = wp_insert_term('Usługi FLT', 'category', ['slug' => 'uslugi-flt']);
    $katId = is_wp_error($kat) ? 0 : (int) $kat['term_id'];
    update_term_meta($katId, '_evk_tl_en__name', 'Services FLT');
    $wpis  = (int) wp_insert_post(['post_title' => 'Wpis FLT', 'post_name' => 'wpis-flt', 'post_status' => 'publish',
        'post_category' => [$katId], 'post_content' => 'Treść.']);
    update_post_meta($wpis, '_evk_tl_en__post_title', 'Post FLT');
    $strona = (int) wp_insert_post(['post_type' => 'page', 'post_title' => 'Strona FLT', 'post_name' => 'strona-flt', 'post_status' => 'publish']);
    $obrazPl = evk_t_flt_obrazek('evk-t-flt-pl.jpg', [200, 30, 30]);
    $obrazEn = evk_t_flt_obrazek('evk-t-flt-en.jpg', [30, 30, 200]);
    update_option('evk_t_flt_obraz_pl', $obrazPl, false);

    $zapis['wpisy']   = [$wpis, $strona, $obrazPl, $obrazEn];
    $zapis['terminy'] = [['category', $katId]];
    $zapis['pliki']   = array_filter([get_attached_file($obrazPl), get_attached_file($obrazEn)]);
    file_put_contents($plik, wp_json_encode($zapis));

    update_option('tl_translations', ['groups' => [['id' => 'g-flt', 'name' => 'Filtry testu', 'rows' => [
        ['id' => 'r1', 'pl' => 'Zobacz więcej FLT', 'en' => 'See more FLT', 'de' => 'Mehr sehen FLT'],
    ]]]]);
    update_option('tl_url_slugs', [['pl' => 'wpis-flt', 'en' => 'post-flt', 'de' => '']]);
    update_option('tl_images', ['o1' => ['pl' => $obrazPl, 'en' => $obrazEn, 'de' => 0]]);
    update_option('tl_languages', [
        ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'],
        ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
    ]);
    update_option('evk_tl_module_enabled', 1);
    update_option('show_on_front', 'posts');
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
    flush_rewrite_rules(false);
    evk_t_flt_bez_pamieci();
    $out['obrazy'] = ['pl' => (string) wp_get_attachment_url($obrazPl), 'en' => (string) wp_get_attachment_url($obrazEn)];
    $out['gotowe'] = $katId && $wpis && $strona && $obrazPl && $obrazEn;
    break;

case 'przeplucz':
    flush_rewrite_rules(false);
    evk_t_flt_bez_pamieci();
    $out['tl'] = function_exists('tl_przetworz_html') && function_exists('evk_tl_rest_obrob');
    break;

case 'sprzataj':
    $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    delete_option('evk_t_flt_obraz_pl');
    foreach ((array) ($zapis['wpisy'] ?? []) as $id) wp_delete_post((int) $id, true);
    foreach ((array) ($zapis['terminy'] ?? []) as [$tax, $id]) if ($id) wp_delete_term((int) $id, (string) $tax);
    foreach ((array) ($zapis['pliki'] ?? []) as $f) @unlink((string) $f);
    evk_t_flt_bez_pamieci();
    @unlink($muPlik);
    @unlink($jsPlik);
    if (empty($zapis['mu_bylo'])) @rmdir($mu);
    $GLOBALS['wp_rewrite']->init();
    flush_rewrite_rules(false);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
