<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Stan testowego WordPressa dla testu panelu newslettera w przeglądarce
 * (tests/newsletter-panel.test.js).
 *
 *   php tests/php/newsletter-panel.php przygotuj
 *   php tests/php/newsletter-panel.php stan <lista>
 *   php tests/php/newsletter-panel.php sprzataj <lista>
 *
 * Przeglądarka pracuje MIĘDZY wywołaniami, więc sprzątanie to osobny krok;
 * opcje sprzed testu leżą w pliku tymczasowym do czasu „sprzataj".
 */

require __DIR__ . '/_testowy-wp.php';

$krok  = $argv[1] ?? '';
$lista = (int) ($argv[2] ?? 0);
$plik  = sys_get_temp_dir() . '/evk-t-newsletter-panel.json';
$out   = ['krok' => $krok];

switch ($krok) {

case 'przygotuj':
    if (!is_file($plik)) {
        file_put_contents($plik, wp_json_encode(['evk_newsletter' => get_option('evk_newsletter', null),
                                                 'evk_nl_wykluczenia' => get_option('evk_nl_wykluczenia', null)]));
    }
    update_option('evk_newsletter', array_merge((array) get_option('evk_newsletter', []), ['enabled' => 1]));
    evk_nl_create_tables();
    delete_option('evk_nl_wykluczenia');
    $id = (int) evk_nl_create_list('Panel importu ' . wp_rand());
    evk_nl_add_subscriber($id, 'jest.panel@example.com');
    evk_nl_wyklucz('wykluczony.panel@example.com', 'reczny');
    $out['wp']    = untrailingslashit(ABSPATH);
    $out['lista'] = $id;
    break;

case 'stan':
    global $wpdb;
    $out['subskrybenci'] = [];
    foreach ($wpdb->get_results($wpdb->prepare('SELECT email, status, fields_json FROM ' . evk_nl_table('subscribers') . ' WHERE list_id=%d ORDER BY id', $lista), ARRAY_A) ?: [] as $r) {
        $pola = json_decode((string) $r['fields_json'], true) ?: [];
        unset($pola['_consent_source']);
        $out['subskrybenci'][$r['email']] = ['status' => (int) $r['status'], 'pola' => (object) $pola];
    }
    $out['wykluczenia'] = array_keys(evk_nl_wykluczenia());
    break;

case 'sprzataj':
    if ($lista) evk_nl_delete_list($lista);
    $przed = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
    foreach (['evk_newsletter', 'evk_nl_wykluczenia'] as $opcja) {
        if (!array_key_exists($opcja, $przed)) continue;
        $przed[$opcja] === null ? delete_option($opcja) : update_option($opcja, $przed[$opcja]);
    }
    @unlink($plik);
    $out['ok'] = true;
    break;

default:
    $out['blad'] = 'nieznany krok';
}

echo wp_json_encode($out);
