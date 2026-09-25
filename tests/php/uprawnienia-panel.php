<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Role Manager w PRAWDZIWYM panelu (1.233.3) — stan ról między krokami
 * przeglądarki.
 *
 *   php tests/php/uprawnienia-panel.php przygotuj
 *   php tests/php/uprawnienia-panel.php stan
 *   php tests/php/uprawnienia-panel.php sprzataj
 *
 * „przygotuj" stawia pustą rolę `evk_t_panel` (samo `read`) i usuwa
 * `evk_t_nowa`, którą test dodaje z panelu. Ograniczenia stron obu ról
 * znikają przy sprzątaniu — zapis roli w panelu dotyka i tej opcji.
 */

require __DIR__ . '/_testowy-wp.php';

$krok = $argv[1] ?? '';
$out  = ['krok' => $krok];
$role = ['evk_t_panel', 'evk_t_nowa'];

$bez_ograniczen = static function () use ($role) {
    $o = evk_role_get_restrictions();
    foreach ($role as $r) unset($o[$r]);
    update_option(EVK_ROLE_RESTRICTIONS_OPTION, $o);
};

switch ($krok) {

case 'przygotuj':
    foreach ($role as $r) remove_role($r);
    $bez_ograniczen();
    add_role('evk_t_panel', 'Rola panelu testowa', ['read' => true]);
    $out['wp'] = untrailingslashit(ABSPATH);
    break;

case 'stan':
    $panel = get_role('evk_t_panel');
    $out['panel_edit_posts'] = $panel ? $panel->has_cap('edit_posts') : null;
    $out['nowa_jest']        = get_role('evk_t_nowa') !== null;
    break;

case 'sprzataj':
    foreach ($role as $r) remove_role($r);
    $bez_ograniczen();
    break;
}

echo wp_json_encode($out);
