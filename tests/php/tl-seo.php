<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * SEO w wersjach językowych (1.251.0) — stan między żądaniami testu tl-seo.
 *
 *   php tests/php/tl-seo.php wp
 *   php tests/php/tl-seo.php przygotuj http://127.0.0.1:<port>
 *   php tests/php/tl-seo.php przeplucz http://127.0.0.1:<port>   (osobny proces: Tłumaczenia wczytane)
 *   php tests/php/tl-seo.php meta                                 pola SEO stron testu
 *   php tests/php/tl-seo.php ajax <akcja> '<json POST>'            punkt AJAX jako administrator
 *   php tests/php/tl-seo.php sprzataj
 *
 * Strony J1–J6 (typ `page`, tytuły „Strona SEO J…"):
 *   J1  zakładka SEO: tytuł, opis i słowa PL; tytuł i opis EN; DE nic;
 *   J2  Bricks: tytuł `{tl_seo_j2_tytul}`, opis `{tl:pl=…|en=…}`;
 *   J3  Bricks: tytuł i opis do udostępnień po polsku; tytuł SEO EN;
 *   J4  nic — tytuł strony;
 *   J5  zakładka: tytuł `{tl_seo_j5} | Firma`, opis `{tl_seo_j5_opis}` i już wpisany opis EN;
 *   J6  Bricks: tytuł i tytuł do udostępnień `{tl_seo_j6}`; zakładka: słowa ze znacznikiem bez frazy.
 * Słownik ma frazy tych znaczników (DE tylko dla J5). Opcje sprzed sondy
 * leżą w pliku tymczasowym do „sprzataj".
 */

$evk_krok = $argv[1] ?? '';
/* Adresy muszą wskazywać serwer testowy (`stara.test` nie rozwiązuje się tutaj
   — CLAUDE.md), więc sonda dostaje adres serwera i stawia WP_HOME przed
   wczytaniem WordPressa, jak router. */
if (in_array($evk_krok, ['przygotuj', 'przeplucz'], true) && preg_match('#^http://127\.0\.0\.1:\d+$#', $argv[2] ?? '')) {
    define('WP_HOME', $argv[2]);
    define('WP_SITEURL', $argv[2]);
}
// Punkt AJAX tak jak z admin-ajax.php: wp_send_json() kończy przez wp_die(), które niżej rzuca wyjątkiem.
if ($evk_krok === 'ajax') define('DOING_AJAX', true);
require __DIR__ . '/_testowy-wp.php';

$plik  = sys_get_temp_dir() . '/evk-t-tl-seo.json';
$opcje = ['permalink_structure', 'show_on_front', 'evk_tl_module_enabled', 'tl_languages', 'tl_translations', 'tl_dd_keys'];
$out   = ['krok' => $evk_krok];

class EVK_T_Seo_Koniec extends Exception {}

/** Pamięć podręczna Tłumaczeń (nazwy jak w evoke-one.php) — po każdej zmianie słownika i języków z sondy. */
function evk_t_seo_bez_pamieci(): void {
    foreach (['tl_compiled_slugs', 'tl_compiled_config', 'tl_compiled_tokens_en', 'tl_compiled_tokens_de'] as $t) delete_transient($t);
}

/** Klucze metadanych, które test czyta i sprząta. */
function evk_t_seo_klucze(): array {
    $k = ['_evoke_seo_title', '_evoke_seo_desc', '_evoke_seo_keywords', '_evoke_seo_robots', '_bricks_page_settings'];
    foreach (['en', 'de', 'fr'] as $l) foreach (['seo_title', 'seo_desc', 'seo_keywords'] as $p) $k[] = '_evk_tl_' . $l . '__' . $p;
    return $k;
}

switch ($evk_krok) {

case 'wp':
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'przygotuj':
    if (!defined('WP_HOME')) { $out['brak'] = 'przygotuj bez adresu serwera testowego'; break; }
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'strony' => []]));
    }
    $zapis = json_decode((string) file_get_contents($plik), true);

    $strony = [];
    foreach (['j1', 'j2', 'j3', 'j4', 'j5', 'j6'] as $j) {
        $strony[$j] = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish',
            'post_title' => 'Strona SEO ' . strtoupper($j), 'post_name' => 'strona-seo-' . $j, 'post_content' => 'Treść.']);
    }
    $m = static function (string $j, string $k, $v) use ($strony) { update_post_meta($strony[$j], $k, wp_slash($v)); };
    $m('j1', '_evoke_seo_title', 'Tytuł SEO PL J1');
    $m('j1', '_evoke_seo_desc', 'Opis SEO PL J1');
    $m('j1', '_evoke_seo_keywords', 'slowa, pl');
    $m('j1', '_evk_tl_en__seo_title', 'SEO title EN J1');
    $m('j1', '_evk_tl_en__seo_desc', 'SEO description EN J1');
    $m('j2', '_bricks_page_settings', ['documentTitle' => '{tl_seo_j2_tytul}', 'metaDescription' => '{tl:pl=Opis inline PL|en=Inline description EN}']);
    $m('j3', '_bricks_page_settings', ['sharingTitle' => 'Tytuł do udostępnień J3', 'sharingDescription' => 'Opis do udostępnień J3']);
    $m('j3', '_evk_tl_en__seo_title', 'SEO title EN J3');
    $m('j5', '_evoke_seo_title', '{tl_seo_j5} | Firma');
    $m('j5', '_evoke_seo_desc', '{tl_seo_j5_opis}');
    $m('j5', '_evk_tl_en__seo_desc', 'Istniejący opis EN J5');
    $m('j6', '_bricks_page_settings', ['documentTitle' => '{tl_seo_j6}', 'sharingTitle' => '{tl_seo_j6}']);
    $m('j6', '_evoke_seo_keywords', '{tl_brak_klucza_j6}');
    $zapis['strony'] = array_values(array_unique(array_merge($zapis['strony'], array_values($strony))));
    file_put_contents($plik, wp_json_encode($zapis));

    update_option('tl_translations', ['groups' => [['id' => 'g-seo', 'name' => 'SEO testu', 'rows' => [
        ['id' => 'r1', 'pl' => 'Tytuł ze słownika J2', 'en' => 'Title from dictionary J2', 'de' => '', 'dd_key' => 'seo_j2_tytul'],
        ['id' => 'r2', 'pl' => 'Tytuł J5', 'en' => 'Title J5', 'de' => 'Titel J5', 'dd_key' => 'seo_j5'],
        ['id' => 'r3', 'pl' => 'Opis J5', 'en' => 'Description J5', 'de' => '', 'dd_key' => 'seo_j5_opis'],
        ['id' => 'r4', 'pl' => 'Tytuł J6', 'en' => 'Title J6', 'de' => '', 'dd_key' => 'seo_j6'],
    ]]]]);
    update_option('tl_dd_keys', ['seo_j2_tytul' => 'Tytuł ze słownika J2', 'seo_j5' => 'Tytuł J5',
                                 'seo_j5_opis' => 'Opis J5', 'seo_j6' => 'Tytuł J6']);
    update_option('tl_languages', [
        ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'],
        ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
    ]);
    update_option('evk_tl_module_enabled', 1);
    update_option('show_on_front', 'posts');
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
    flush_rewrite_rules(false);
    evk_t_seo_bez_pamieci();
    $out['strony'] = $strony;
    $out['gotowe'] = !in_array(0, $strony, true);
    break;

case 'przeplucz':
    // Osobny proces: moduł Tłumaczeń wczytuje się tylko przy zapisanej opcji (CLAUDE.md).
    flush_rewrite_rules(false);
    evk_t_seo_bez_pamieci();
    $out['tl'] = function_exists('evk_seo_tl_kandydaci') && function_exists('tl_get_languages');
    break;

case 'meta':
    $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach ((array) ($zapis['strony'] ?? []) as $id) {
        $wpis = [];
        foreach (evk_t_seo_klucze() as $k) {
            $v = get_post_meta((int) $id, $k, true);
            if ($v !== '' && $v !== []) $wpis[$k] = $v;
        }
        $out['meta'][get_post_field('post_name', (int) $id)] = $wpis;
    }
    break;

case 'ajax':
    $akcja = (string) ($argv[2] ?? '');
    $post  = json_decode((string) ($argv[3] ?? '{}'), true) ?: [];
    $admin = get_user_by('login', 'admin');
    if (!$admin) { $out['brak'] = 'brak użytkownika admin'; break; }
    wp_set_current_user($admin->ID);
    $post['nonce'] = ($post['nonce'] ?? '') === 'zly' ? 'zly' : wp_create_nonce('evoke_seo_nonce');
    $_POST = $_REQUEST = wp_slash($post);   // jak w żądaniu: WordPress dokłada ukośniki do $_POST
    add_filter('wp_die_ajax_handler', static function () {
        // Jak _ajax_wp_die_handler(): komunikat (odmowa nonce'a to -1) na wyjście, potem koniec.
        return static function ($komunikat = '') {
            if (is_scalar($komunikat)) echo $komunikat;
            throw new EVK_T_Seo_Koniec();
        };
    });
    ob_start();
    try {
        do_action('wp_ajax_' . $akcja);
    } catch (EVK_T_Seo_Koniec $e) {
        // wp_send_json() i odmowa nonce'a kończą tu.
    }
    $wyjscie = (string) ob_get_clean();
    $out['odp'] = json_decode($wyjscie, true) ?? $wyjscie;
    break;

case 'sprzataj':
    $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['strony'] ?? []) as $id) wp_delete_post((int) $id, true);
    evk_t_seo_bez_pamieci();
    $GLOBALS['wp_rewrite']->init();   // reguły pod przywróconą strukturę adresów
    flush_rewrite_rules(false);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
