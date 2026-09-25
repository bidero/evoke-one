<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Strony techniczne na PRAWDZIWYM WordPressie (1.235.0): Kokpit i strona
 * zasłony konserwacji tylko dla zalogowanych — stan między żądaniami testu.
 *
 *   php tests/php/zapis-wp-strony-techniczne.php przygotuj
 *   php tests/php/zapis-wp-strony-techniczne.php konserwacja-wl
 *   php tests/php/zapis-wp-strony-techniczne.php konserwacja-wyl
 *   php tests/php/zapis-wp-strony-techniczne.php glowna-kokpit
 *   php tests/php/zapis-wp-strony-techniczne.php sprzataj
 *
 * Strona jak prawdziwa: ładne adresy (bez nich przekierowania kanoniczne
 * i zgadywanie adresu przy 404 nie mają jak zajść — CLAUDE.md), strona
 * publiczna do porównania i strona Kokpitu włączonego w panelu. Każda ma
 * w treści własny znacznik, a w tytule wspólne słowo do wyszukiwania.
 * Opcje sprzed sondy leżą w pliku tymczasowym do „sprzataj".
 */

require __DIR__ . '/_testowy-wp.php';

$krok  = $argv[1] ?? '';
$plik  = sys_get_temp_dir() . '/evk-t-strony-techniczne.json';
$opcje = ['evoke_dashboard_page_id', 'evoke_dashboard_active', 'maintenance_page_id', 'maintenance_mode',
          'maintenance_bypass_password', 'maintenance_excluded_paths', 'permalink_structure',
          'show_on_front', 'page_on_front', 'blog_public', 'evk_404_enabled'];
$out   = ['krok' => $krok];

switch ($krok) {

case 'przygotuj':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'strony' => []]));
    }
    $zapis = json_decode((string) file_get_contents($plik), true);
    $nowa = static function (string $tytul, string $slug, string $tresc): int {
        return (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish',
            'post_title' => $tytul, 'post_name' => $slug, 'post_content' => $tresc]);
    };
    $kokpit  = $nowa('Kokpit testowy Techxq', 'kokpit-testowy', 'TRESC-KOKPITU-7731');
    $zaslona = $nowa('Zasłona testowa Techxq', 'zaslona-testowa', 'TRESC-ZASLONY-7732');
    $zwykla  = $nowa('Zwykła strona Techxq', 'zwykla-strona-techtest', 'TRESC-ZWYKLA-7733');
    $zapis['strony'] = array_merge($zapis['strony'], [$kokpit, $zaslona, $zwykla]);
    file_put_contents($plik, wp_json_encode($zapis));

    // Przez obiekt, nie samą opcję: $wp_rewrite wczytał strukturę przy starcie.
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
    flush_rewrite_rules(false);
    update_option('show_on_front', 'posts');
    update_option('blog_public', 1);          // mapa strony tylko na stronie publicznej
    update_option('evoke_dashboard_active', '1');
    update_option('evoke_dashboard_page_id', $kokpit);
    update_option('maintenance_page_id', $zaslona);
    update_option('maintenance_mode', 0);
    update_option('maintenance_bypass_password', '');
    update_option('maintenance_excluded_paths', '');
    update_option('evk_404_enabled', 1);
    evk_404_maybe_upgrade();
    $out += ['wp' => untrailingslashit(ABSPATH), 'kokpit' => $kokpit, 'zaslona' => $zaslona, 'zwykla' => $zwykla];
    break;

case 'log404':
    $out['wiersze'] = $GLOBALS['wpdb']->get_col('SELECT url FROM ' . evk_404_table() . " WHERE url LIKE '%techtest%' OR url LIKE '/kokpit-test%' OR url LIKE '/zaslona-testowa%'");
    sort($out['wiersze'], SORT_STRING);
    break;

case 'konserwacja-wl':
    update_option('maintenance_mode', 1);
    break;

case 'konserwacja-wyl':
    update_option('maintenance_mode', 0);
    break;

case 'glowna-kokpit':
    // Strona główna wybrana omyłkowo jako Kokpit — bezpiecznik ma ją zostawić publiczną.
    update_option('show_on_front', 'page');
    update_option('page_on_front', (int) get_option('evoke_dashboard_page_id'));
    break;

case 'sprzataj':
    $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['strony'] ?? []) as $id) wp_delete_post((int) $id, true);
    if (evk_404_tabela_gotowa()) {
        $GLOBALS['wpdb']->query('DELETE FROM ' . evk_404_table() . " WHERE url LIKE '%techtest%' OR url LIKE '/kokpit-test%' OR url LIKE '/zaslona-testowa%'");
    }
    $GLOBALS['wp_rewrite']->init();   // reguły pod przywróconą strukturę adresów
    flush_rewrite_rules(false);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
