<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Meta w <head> na PRAWDZIWYM WordPressie (1.236.0) — stan między żądaniami
 * testu seo-meta-glowa.
 *
 *   php tests/php/seo-meta-glowa.php wp
 *   php tests/php/seo-meta-glowa.php przygotuj http://127.0.0.1:<port>
 *   php tests/php/seo-meta-glowa.php przeplucz        (osobny proces: reguły archiwów)
 *   php tests/php/seo-meta-glowa.php bez-obrazka
 *   php tests/php/seo-meta-glowa.php tl-wl
 *   php tests/php/seo-meta-glowa.php tl-przeplucz     (osobny proces: Tłumaczenia już wczytane)
 *   php tests/php/seo-meta-glowa.php sprzataj
 *
 * Strona jak prawdziwa: ładne adresy, język pl_PL, slogan, strona główna
 * z ostatnimi wpisami (po jednym na stronę — jest stronicowanie), kategoria
 * z opisem w HTML-u, tag bez opisu, typ treści z archiwum (mu-plugin), obrazki
 * w bibliotece mediów z tekstem alternatywnym, obrazek „z generatora OG"
 * (plik w uploads, nie załącznik) i domyślny obrazek OG.
 * Opcje sprzed sondy leżą w pliku tymczasowym do „sprzataj".
 */

/* Adresy (obrazki, uploads, odnośniki) muszą wskazywać serwer testowy:
   `stara.test` nie rozwiązuje się tutaj (CLAUDE.md), więc „przygotuj" dostaje
   adres serwera i stawia WP_HOME przed wczytaniem WordPressa, jak router. */
if (in_array($argv[1] ?? '', ['przygotuj', 'przeplucz'], true) && preg_match('#^http://127\.0\.0\.1:\d+$#', $argv[2] ?? '')) {
    define('WP_HOME', $argv[2]);
    define('WP_SITEURL', $argv[2]);
}
require __DIR__ . '/_testowy-wp.php';

$krok  = $argv[1] ?? '';
$plik  = sys_get_temp_dir() . '/evk-t-seo-glowa.json';
$mu    = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
$muPlik = $mu . '/evk-t-seo-typ.php';
$opcje = ['permalink_structure', 'show_on_front', 'page_on_front', 'WPLANG', 'blogdescription', 'posts_per_page',
          'evk_og', 'evk_tl_module_enabled', 'tl_languages'];
$out   = ['krok' => $krok];

/** Typ treści z archiwum — ten sam kod w mu-pluginie (żądania HTTP) i w sondzie (reguły adresów). */
function evk_t_seo_typ(): void {
    register_post_type('evk_t_katalog', ['public' => true, 'label' => 'Katalog SEO', 'has_archive' => 'katalog-seo',
        'description' => 'Opis archiwum typu SEO-7743', 'rewrite' => ['slug' => 'katalog-seo-pozycja']]);
}

/** Obrazek JPEG w uploads; jako załącznik (z alt) albo sam plik. */
function evk_t_seo_obrazek(string $nazwa, int $w, int $h, ?string $alt): array {
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w - 1, $h - 1, imagecolorallocate($img, 40, 90, 160));
    ob_start();
    imagejpeg($img, null, 80);
    $dane = (string) ob_get_clean();
    imagedestroy($img);
    $wynik = wp_upload_bits($nazwa, null, $dane);
    $obrazek = ['url' => $wynik['url'], 'plik' => $wynik['file'], 'id' => 0];
    if ($alt !== null) {
        $id = (int) wp_insert_attachment(['post_mime_type' => 'image/jpeg', 'post_title' => $nazwa, 'post_status' => 'inherit'], $wynik['file']);
        wp_update_attachment_metadata($id, ['width' => $w, 'height' => $h, 'file' => _wp_relative_upload_path($wynik['file'])]);
        update_post_meta($id, '_wp_attachment_image_alt', $alt);
        $obrazek['id'] = $id;
    }
    return $obrazek;
}

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
    file_put_contents($muPlik, "<?php\n// Wyłącznie test seo-meta-glowa (tests/php/seo-meta-glowa.php) — usuwany po teście.\n"
        . "add_action('init', function () { register_post_type('evk_t_katalog', ['public' => true, 'label' => 'Katalog SEO', "
        . "'has_archive' => 'katalog-seo', 'description' => 'Opis archiwum typu SEO-7743', 'rewrite' => ['slug' => 'katalog-seo-pozycja']]); });\n");
    evk_t_seo_typ();

    $domyslny = evk_t_seo_obrazek('evk-t-seo-domyslny.jpg', 1200, 630, 'Alt obrazka domyślnego SEO');
    $miniatura = evk_t_seo_obrazek('evk-t-seo-miniatura.jpg', 800, 500, 'Alt miniatury SEO');
    $generator = evk_t_seo_obrazek('evk-t-seo-wygenerowany.jpg', 1200, 630, null);

    $kat = wp_insert_term('Kategoria SEO', 'category', ['slug' => 'kategoria-seo',
        'description' => "Opis kategorii SEO-7742 <strong>z HTML-em</strong>\n\ni drugim akapitem."]);
    $tag = wp_insert_term('Tag SEO', 'post_tag', ['slug' => 'tag-seo']);
    $katId = is_wp_error($kat) ? 0 : (int) $kat['term_id'];
    $tagId = is_wp_error($tag) ? 0 : (int) $tag['term_id'];

    $czas = time();
    $wpis = static function (array $a, int $minuty) use ($czas): int {
        return (int) wp_insert_post($a + ['post_status' => 'publish', 'post_date' => gmdate('Y-m-d H:i:s', $czas - $minuty * 60 + (int) (get_option('gmt_offset') * 3600))]);
    };
    $wpis1 = $wpis(['post_title' => 'Wpis SEO z miniaturą', 'post_name' => 'wpis-seo-miniatura', 'post_category' => [$katId],
                    'tags_input' => ['Tag SEO'], 'post_content' => 'Treść.'], 1);
    // Generator OG wyłączony dla tego wpisu: og:image to miniatura (załącznik z alt).
    update_post_meta($wpis1, '_evk_og_disable', '1');
    set_post_thumbnail($wpis1, $miniatura['id']);
    $wpis2 = $wpis(['post_title' => 'Wpis SEO z generatora', 'post_name' => 'wpis-seo-generator', 'post_category' => [$katId], 'post_content' => 'Treść.'], 2);
    update_post_meta($wpis2, '_evk_og_url', $generator['url']);
    $strona = $wpis(['post_type' => 'page', 'post_title' => 'Strona SEO', 'post_name' => 'strona-seo', 'post_content' => 'Treść strony.'], 3);
    $pozycja = $wpis(['post_type' => 'evk_t_katalog', 'post_title' => 'Pozycja katalogu SEO', 'post_name' => 'pozycja-seo'], 4);

    $zapis['wpisy']   = array_merge($zapis['wpisy'], [$wpis1, $wpis2, $strona, $pozycja, $domyslny['id'], $miniatura['id']]);
    $zapis['terminy'] = array_merge($zapis['terminy'], [['category', $katId], ['post_tag', $tagId]]);
    $zapis['pliki']   = array_merge($zapis['pliki'], [$domyslny['plik'], $miniatura['plik'], $generator['plik']]);
    file_put_contents($plik, wp_json_encode($zapis));

    /* Wprost do bazy: update_option() przepuszcza WPLANG tylko dla języka
       z zainstalowaną paczką, a testowy WordPress ma sam angielski. */
    $GLOBALS['wpdb']->query($GLOBALS['wpdb']->prepare("UPDATE {$GLOBALS['wpdb']->options} SET option_value = %s WHERE option_name = 'WPLANG'", 'pl_PL'));
    if (!$GLOBALS['wpdb']->rows_affected && get_option('WPLANG', null) === null) {
        $GLOBALS['wpdb']->insert($GLOBALS['wpdb']->options, ['option_name' => 'WPLANG', 'option_value' => 'pl_PL', 'autoload' => 'yes']);
    }
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('WPLANG', 'options');
    update_option('blogdescription', 'Slogan strony SEO-7740');
    update_option('show_on_front', 'posts');
    update_option('posts_per_page', 1);
    update_option('evk_tl_module_enabled', 0);
    update_option('evk_og', array_merge(evk_og_get_settings(), ['enabled' => 1, 'fallback_url' => $domyslny['url'], 'post_types' => ['post']]));
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
    flush_rewrite_rules(false);

    $out += [
        'wp' => untrailingslashit(ABSPATH),
        'nazwa' => get_bloginfo('name'),
        'wpis1' => get_permalink($wpis1), 'wpis2' => get_permalink($wpis2), 'strona' => get_permalink($strona),
        'domyslny' => $domyslny['url'], 'miniatura' => wp_get_attachment_image_url($miniatura['id'], 'full'),
        'generator' => $generator['url'],
    ];
    break;

case 'przeplucz':
    /* Osobny proces, bo reguły kategorii, tagów i typów treści WordPress
       rejestruje na `init` tylko przy włączonych ładnych adresach — w procesie,
       który je dopiero włączył, `init` już minął i reguł by nie było. */
    flush_rewrite_rules(false);
    $zapis = json_decode((string) file_get_contents($plik), true);
    [$kat, $tag] = [(int) ($zapis['terminy'][0][1] ?? 0), (int) ($zapis['terminy'][1][1] ?? 0)];
    $out += ['kategoria' => get_term_link($kat, 'category'), 'tag' => get_term_link($tag, 'post_tag'),
             'katalog' => get_post_type_archive_link('evk_t_katalog')];
    break;

case 'bez-obrazka':
    update_option('evk_og', array_merge(evk_og_get_settings(), ['fallback_url' => '']));
    break;

case 'tl-wl':
    update_option('tl_languages', [
        ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'],
        ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
        ['code' => 'fr', 'name' => 'Francuski', 'html' => 'fr'],   // bez regionu — og:locale go pomija
    ]);
    update_option('evk_tl_module_enabled', 1);
    break;

case 'tl-przeplucz':
    // Tu Tłumaczenia są już wczytane: ich reguły prefiksów (/en/…) stoją od init.
    flush_rewrite_rules(false);
    $out['tl'] = function_exists('tl_get_languages');
    break;

case 'sprzataj':
    $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    $uploads = wp_upload_dir(null, false);
    foreach ((array) ($zapis['wpisy'] ?? []) as $id) {
        // Obrazki generatora OG powstałe przy wejściu na wpis w trakcie testu.
        foreach ((array) glob($uploads['basedir'] . '/og-images/og-' . (int) $id . '.*') as $f) @unlink((string) $f);
        wp_delete_post((int) $id, true);
    }
    foreach ((array) ($zapis['terminy'] ?? []) as [$tax, $id]) if ($id) wp_delete_term((int) $id, (string) $tax);
    foreach ((array) ($zapis['pliki'] ?? []) as $f) @unlink((string) $f);
    @unlink($muPlik);
    if (empty($zapis['mu_bylo'])) @rmdir($mu);
    $GLOBALS['wp_rewrite']->init();   // reguły pod przywróconą strukturę adresów
    flush_rewrite_rules(false);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
