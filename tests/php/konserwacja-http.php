<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Tryb konserwacji na PRAWDZIWYM WordPressie (1.234.0) — stan między
 * żądaniami HTTP testu.
 *
 *   php tests/php/konserwacja-http.php przygotuj
 *   php tests/php/konserwacja-http.php wlacz
 *   php tests/php/konserwacja-http.php bez-strony
 *   php tests/php/konserwacja-http.php log404
 *   php tests/php/konserwacja-http.php sprzataj
 *
 * „przygotuj" włącza konserwację ze stroną zasłony, bez klucza obejścia
 * i bez wykluczeń, i stawia zwykłą stronę do odpytania. `blog_public` = 1:
 * strona niepubliczna ma w robots.txt `Disallow: /` od samego WordPressa,
 * a test ma widzieć, co robi Evoke. Opcje sprzed sondy leżą w pliku
 * tymczasowym do „sprzataj".
 *
 * Od 1.234.1 strona jak prawdziwa: ładne adresy, strona główna ustawiona na
 * stronę o slugu „home", wpis ze starym slugiem, reguła modułu 301 i włączony
 * log 404. Rdzeń przekierowuje z każdego z tych adresów w `template_redirect`,
 * czyli PRZED zasłoną — na testowym WordPressie bez ładnych adresów i bez
 * strony głównej żadne z tych przekierowań nie miało szansy zajść, więc
 * 1.234.0 przeszło, a evoke.pl odpowiadało na `/home` 301 na `/`.
 * „bez-strony" zdejmuje stronę zasłony (zasłona zapasowa wtyczki): wtedy
 * zapytanie nie jest podmieniane i 404 zostaje 404 — tak jak na evoke.pl.
 */

require __DIR__ . '/_testowy-wp.php';

global $wpdb;
$krok  = $argv[1] ?? '';
$plik  = sys_get_temp_dir() . '/evk-t-konserwacja-http.json';
$opcje = ['maintenance_mode', 'maintenance_page_id', 'maintenance_bypass_password',
          'maintenance_excluded_paths', 'blog_public', 'permalink_structure', 'show_on_front',
          'page_on_front', 'evk_301_enabled', 'evk_404_enabled'];
$out   = ['krok' => $krok];

switch ($krok) {

case 'przygotuj':
    if (!is_file($plik)) {
        $przed = [];
        foreach ($opcje as $o) $przed[$o] = get_option($o, null);
        file_put_contents($plik, wp_json_encode(['opcje' => $przed, 'strony' => []]));
    }
    $zapis = json_decode((string) file_get_contents($plik), true);
    $zaslona = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'Przerwa techniczna testowa', 'post_content' => 'Wracamy niebawem.']);
    $zwykla = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'Zwykła strona testowa', 'post_name' => 'zwykla-strona-testowa', 'post_content' => 'Treść zwykłej strony.']);
    $glowna = (int) wp_insert_post(['post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'Home', 'post_name' => 'home', 'post_content' => 'Strona główna testowa.']);
    $wpis = (int) wp_insert_post(['post_type' => 'post', 'post_status' => 'publish',
        'post_title' => 'Wpis po zmianie sluga', 'post_name' => 'nowy-slug-konserwacja', 'post_content' => 'Treść wpisu.']);
    add_post_meta($wpis, '_wp_old_slug', 'stary-slug-konserwacja');
    $zapis['strony'] = array_merge($zapis['strony'], [$zaslona, $zwykla, $glowna, $wpis]);

    // Przez obiekt, nie samą opcję: $wp_rewrite wczytał strukturę przy starcie
    // i flush_rewrite_rules() zbudowałby reguły pod starą.
    $GLOBALS['wp_rewrite']->set_permalink_structure('/%postname%/');
    flush_rewrite_rules(false);
    update_option('show_on_front', 'page');
    update_option('page_on_front', $glowna);
    update_option('evk_301_enabled', 1);
    $regula = evk_301_dodaj('/t-konserwacja-stary-adres', '/zwykla-strona-testowa/');
    $zapis['reguly'] = array_merge((array) ($zapis['reguly'] ?? []), is_wp_error($regula) ? [] : [$regula]);
    update_option('evk_404_enabled', 1);
    evk_404_maybe_upgrade();
    file_put_contents($plik, wp_json_encode($zapis));

    // Konserwacja rusza dopiero krokiem „wlacz": test najpierw sprawdza, że bez
    // niej rdzeń naprawdę przekierowuje z tych adresów (inaczej nic by nie mierzył).
    update_option('maintenance_mode', 0);
    update_option('maintenance_page_id', $zaslona);
    update_option('maintenance_bypass_password', '');
    update_option('maintenance_excluded_paths', '');
    update_option('blog_public', 1);
    $out += ['wp' => untrailingslashit(ABSPATH), 'zwykla' => $zwykla,
             'glowna' => '/' . get_post_field('post_name', $glowna) . '/', 'regula' => !is_wp_error($regula)];
    break;

case 'wlacz':
    update_option('maintenance_mode', 1);
    break;

case 'bez-strony':
    update_option('maintenance_page_id', 0);
    break;

case 'log404':
    $out['wiersze'] = evk_404_tabela_gotowa()
        ? $wpdb->get_col("SELECT url FROM " . evk_404_table() . " WHERE url LIKE '/t-konserwacja-%'") : null;
    break;

case 'sprzataj':
    $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['strony'] ?? []) as $id) wp_delete_post((int) $id, true);
    foreach ((array) ($zapis['reguly'] ?? []) as $id) wp_delete_post((int) $id, true);
    if (function_exists('evk_301_clear_cache')) evk_301_clear_cache();
    if (evk_404_tabela_gotowa()) $wpdb->query("DELETE FROM " . evk_404_table() . " WHERE url LIKE '/t-konserwacja-%'");
    $GLOBALS['wp_rewrite']->init();   // reguły pod przywróconą strukturę adresów
    flush_rewrite_rules(false);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
