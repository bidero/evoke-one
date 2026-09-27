<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Adresy wersji językowych z członami z mapy adresów (1.251.0) — stan między
 * żądaniami testu tl-adresy.
 *
 *   php tests/php/tl-adresy.php wp
 *   php tests/php/tl-adresy.php przygotuj http://127.0.0.1:<port>
 *   php tests/php/tl-adresy.php przeplucz http://127.0.0.1:<port>   (osobny proces: reguły archiwów)
 *   php tests/php/tl-adresy.php sprzataj
 *
 * Strona: ładne adresy, Tłumaczenia (EN, DE), kategoria z podkategorią, tag,
 * dwa wpisy w kategorii (po jednym na stronę — jest stronicowanie), własny typ
 * treści z archiwum (mu-plugin), strona. Mapa adresów tłumaczy człon każdego
 * z nich. Opcje sprzed sondy leżą w pliku tymczasowym do „sprzataj".
 */

/* Adresy muszą wskazywać serwer testowy (`stara.test` nie rozwiązuje się
   tutaj — CLAUDE.md), więc sonda dostaje adres serwera i stawia WP_HOME przed
   wczytaniem WordPressa, jak router. */
if (in_array($argv[1] ?? '', ['przygotuj', 'przeplucz'], true) && preg_match('#^http://127\.0\.0\.1:\d+$#', $argv[2] ?? '')) {
    define('WP_HOME', $argv[2]);
    define('WP_SITEURL', $argv[2]);
}
require __DIR__ . '/_testowy-wp.php';

$krok   = $argv[1] ?? '';
$plik   = sys_get_temp_dir() . '/evk-t-tl-adresy.json';
$mu     = rtrim(wp_normalize_path(WPMU_PLUGIN_DIR), '/');
$muPlik = $mu . '/evk-t-tl-adresy.php';
$opcje  = ['permalink_structure', 'show_on_front', 'posts_per_page', 'evk_tl_module_enabled', 'tl_languages', 'tl_url_slugs'];
$out    = ['krok' => $krok];

/** Typ treści z archiwum — ten sam kod w mu-pluginie (żądania HTTP) i w sondzie (reguły adresów). */
const EVK_T_ADR_TYP = "register_post_type('evk_t_realizacja', ['public' => true, 'label' => 'Realizacje ADR', "
    . "'has_archive' => 'realizacje-adr', 'rewrite' => ['slug' => 'realizacje-adr']]);";
function evk_t_adr_typ(): void {
    register_post_type('evk_t_realizacja', ['public' => true, 'label' => 'Realizacje ADR',
        'has_archive' => 'realizacje-adr', 'rewrite' => ['slug' => 'realizacje-adr']]);
}

/** Pamięć podręczna Tłumaczeń (nazwy jak w evoke-one.php) — po każdej zmianie mapy i języków z sondy. */
function evk_t_adr_bez_pamieci(): void {
    foreach (['tl_compiled_slugs', 'tl_compiled_config', 'tl_compiled_tokens_en', 'tl_compiled_tokens_de'] as $t) delete_transient($t);
}

/** Człony mapy adresów, które dokłada test: polski => [en, de]. */
$mapa = [
    'uslugi-adr'        => ['services-adr', 'dienstleistungen-adr'],
    'projektowanie-adr' => ['design-adr', ''],
    'etykieta-adr'      => ['label-adr', ''],
    'realizacje-adr'    => ['projects-adr', ''],
    'dom-adr'           => ['house-adr', ''],
    'strona-adr'        => ['page-adr', ''],
    'wpis-adr-1'        => ['post-adr-1', ''],
];

switch ($krok) {

case 'wp':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'przygotuj':
    if (!defined('WP_HOME')) { $out['brak'] = 'przygotuj bez adresu serwera testowego'; break; }
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'wpisy' => [], 'terminy' => [], 'mu_bylo' => is_dir($mu)]));
    }
    $zapis = json_decode((string) file_get_contents($plik), true);

    if (!is_dir($mu)) mkdir($mu, 0755, true);
    file_put_contents($muPlik, "<?php\n// Wyłącznie test tl-adresy (tests/php/tl-adresy.php) — usuwany po teście.\n"
        . "add_action('init', function () { " . EVK_T_ADR_TYP . " });\n");
    evk_t_adr_typ();

    $kat  = wp_insert_term('Usługi ADR', 'category', ['slug' => 'uslugi-adr']);
    $katId = is_wp_error($kat) ? 0 : (int) $kat['term_id'];
    $pod  = wp_insert_term('Projektowanie ADR', 'category', ['slug' => 'projektowanie-adr', 'parent' => $katId]);
    $tag  = wp_insert_term('Etykieta ADR', 'post_tag', ['slug' => 'etykieta-adr']);
    $podId = is_wp_error($pod) ? 0 : (int) $pod['term_id'];
    $tagId = is_wp_error($tag) ? 0 : (int) $tag['term_id'];

    $wpis1  = (int) wp_insert_post(['post_title' => 'Wpis ADR 1', 'post_name' => 'wpis-adr-1', 'post_status' => 'publish',
        'post_category' => [$katId, $podId], 'tags_input' => ['Etykieta ADR'], 'post_content' => 'Treść.']);
    $wpis2  = (int) wp_insert_post(['post_title' => 'Wpis ADR 2', 'post_name' => 'wpis-adr-2', 'post_status' => 'publish',
        'post_category' => [$katId], 'post_content' => 'Treść.', 'post_date' => gmdate('Y-m-d H:i:s', time() - 3600)]);
    $dom    = (int) wp_insert_post(['post_type' => 'evk_t_realizacja', 'post_title' => 'Dom ADR', 'post_name' => 'dom-adr', 'post_status' => 'publish']);
    $strona = (int) wp_insert_post(['post_type' => 'page', 'post_title' => 'Strona ADR', 'post_name' => 'strona-adr', 'post_status' => 'publish']);

    $zapis['wpisy']   = array_merge($zapis['wpisy'], [$wpis1, $wpis2, $dom, $strona]);
    $zapis['terminy'] = array_merge($zapis['terminy'], [['category', $podId], ['category', $katId], ['post_tag', $tagId]]);
    file_put_contents($plik, wp_json_encode($zapis));

    // Mapa: wpisy sprzed testu zostają, test dokłada swoje (update_option czyści pamięć podręczną Tłumaczeń).
    $slugi = array_values(array_filter((array) get_option('tl_url_slugs', []), static function ($w) use ($mapa) {
        return !isset($mapa[$w['pl'] ?? '']);
    }));
    foreach ($mapa as $pl => [$en, $de]) $slugi[] = ['pl' => $pl, 'en' => $en, 'de' => $de];
    update_option('tl_url_slugs', $slugi);
    update_option('tl_languages', [
        ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'],
        ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
    ]);
    update_option('evk_tl_module_enabled', 1);
    update_option('show_on_front', 'posts');
    update_option('posts_per_page', 1);
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
    flush_rewrite_rules(false);
    /* Moduł Tłumaczeń nie był wczytany (włącza go dopiero ta sonda), więc
       zapis mapy nie wyczyścił jego pamięci podręcznej — serwer widziałby
       mapę sprzed testu. */
    evk_t_adr_bez_pamieci();
    $out['gotowe'] = $wpis1 && $wpis2 && $dom && $strona && $katId && $podId && $tagId;
    break;

case 'przeplucz':
    /* Osobny proces: reguły kategorii, tagów i typów treści WordPress
       rejestruje na `init` tylko przy już włączonych ładnych adresach, a moduł
       Tłumaczeń wczytuje się tylko przy zapisanej opcji (CLAUDE.md). */
    flush_rewrite_rules(false);
    $out['tl'] = function_exists('tl_uri_po_polsku');
    break;

case 'sprzataj':
    $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['wpisy'] ?? []) as $id) wp_delete_post((int) $id, true);
    foreach ((array) ($zapis['terminy'] ?? []) as [$tax, $id]) if ($id) wp_delete_term((int) $id, (string) $tax);
    evk_t_adr_bez_pamieci();
    @unlink($muPlik);
    if (empty($zapis['mu_bylo'])) @rmdir($mu);
    $GLOBALS['wp_rewrite']->init();   // reguły pod przywróconą strukturę adresów
    flush_rewrite_rules(false);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
