<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Menu „Evoke” w pasku admina (1.290.0) — prawdziwy WordPress; pasek
 * test ogląda przez serwer (php -S) w Chromium.
 *
 *   php tests/php/pasek-evoke.php wp
 *   php tests/php/pasek-evoke.php przygotuj     statystyki z licznikiem, Tłumaczenia (EN, DE), strona, konta pasek_czyt (Statystyki), pasek_tlum (Tłumaczenia), pasek_nikt
 *   php tests/php/pasek-evoke.php licznik <0|1>
 *   php tests/php/pasek-evoke.php sprzataj
 */
require __DIR__ . '/_testowy-wp.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$krok = $argv[1] ?? '';
$plik = sys_get_temp_dir() . '/evk-t-pasek.json';
$zap  = is_file($plik) ? (json_decode((string) file_get_contents($plik), true) ?: []) : [];
$out  = ['krok' => $krok];
$opcje = ['evk_statystyki', 'tl_languages', 'evk_tl_module_enabled'];
$konta = ['pasek_czyt' => ['author', 'evk_access_stats'], 'pasek_tlum' => ['editor', 'evk_access_translations'], 'pasek_nikt' => ['author', '']];

switch ($krok) {
case 'wp':
    $out['wp'] = rtrim(ABSPATH, '/');
    break;

case 'przygotuj':
    if (!isset($zap['opcje'])) foreach ($opcje as $o) $zap['opcje'][$o] = get_option($o, null);
    /* Przez zapis opcji z `enabled` — tak jak włącznik w panelu, który zakłada tabele. */
    $byly = get_option('evk_statystyki', null);
    if (!is_array($byly) || empty($byly['enabled'])) { delete_option('evk_statystyki'); add_option('evk_statystyki', ['enabled' => 1, 'licznik' => 1]); }
    else update_option('evk_statystyki', array_merge($byly, ['licznik' => 1]));
    update_option('tl_languages', [['code' => 'en', 'name' => 'Angielski', 'html' => 'en-US'], ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE']]);
    update_option('evk_tl_module_enabled', 1);
    if (empty($zap['strona']) || !get_post((int) $zap['strona'])) {
        $zap['strona'] = (int) wp_insert_post(['post_title' => 'Pasek Evoke', 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<p>Strona testu paska.</p>']);
    }
    foreach ($konta as $login => [$rola, $cap]) {
        if ($u = get_user_by('login', $login)) wp_delete_user($u->ID);
        $id = wp_insert_user(['user_login' => $login, 'user_pass' => 'test-haslo', 'role' => $rola, 'user_email' => $login . '@example.test']);
        if ($cap !== '') (new WP_User($id))->add_cap($cap);
    }
    file_put_contents($plik, (string) wp_json_encode($zap));
    $out['strona'] = $zap['strona'];
    break;

case 'licznik':
    update_option('evk_statystyki', array_merge((array) get_option('evk_statystyki', []), ['licznik' => (int) ($argv[2] ?? 0)]));
    $out['ust'] = get_option('evk_statystyki');
    break;

case 'sprzataj':
    foreach ($konta as $login => $x) if ($u = get_user_by('login', $login)) wp_delete_user($u->ID);
    if (!empty($zap['strona'])) wp_delete_post((int) $zap['strona'], true);
    foreach ((array) ($zap['opcje'] ?? []) as $o => $v) { if ($v === null) delete_option($o); else update_option($o, $v); }
    @unlink($plik);
    $out['ok'] = true;
    break;

default:
    $out['blad'] = 'nieznany krok';
}
echo wp_json_encode($out);
