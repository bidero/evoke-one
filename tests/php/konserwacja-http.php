<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Tryb konserwacji na PRAWDZIWYM WordPressie (1.234.0) — stan między
 * żądaniami HTTP testu.
 *
 *   php tests/php/konserwacja-http.php przygotuj
 *   php tests/php/konserwacja-http.php sprzataj
 *
 * „przygotuj" włącza konserwację ze stroną zasłony, bez klucza obejścia
 * i bez wykluczeń, i stawia zwykłą stronę do odpytania. `blog_public` = 1:
 * strona niepubliczna ma w robots.txt `Disallow: /` od samego WordPressa,
 * a test ma widzieć, co robi Evoke. Opcje sprzed sondy leżą w pliku
 * tymczasowym do „sprzataj".
 */

require __DIR__ . '/_testowy-wp.php';

$krok  = $argv[1] ?? '';
$plik  = sys_get_temp_dir() . '/evk-t-konserwacja-http.json';
$opcje = ['maintenance_mode', 'maintenance_page_id', 'maintenance_bypass_password',
          'maintenance_excluded_paths', 'blog_public'];
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
    $zapis['strony'] = array_merge($zapis['strony'], [$zaslona, $zwykla]);
    file_put_contents($plik, wp_json_encode($zapis));

    update_option('maintenance_mode', 1);
    update_option('maintenance_page_id', $zaslona);
    update_option('maintenance_bypass_password', '');
    update_option('maintenance_excluded_paths', '');
    update_option('blog_public', 1);
    $out += ['wp' => untrailingslashit(ABSPATH), 'zwykla' => $zwykla];
    break;

case 'sprzataj':
    $zapis = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach ((array) ($zapis['opcje'] ?? []) as $o => $w) {
        $w === null ? delete_option($o) : update_option($o, $w);
    }
    foreach ((array) ($zapis['strony'] ?? []) as $id) wp_delete_post((int) $id, true);
    @unlink($plik);
    break;
}

echo wp_json_encode($out);
