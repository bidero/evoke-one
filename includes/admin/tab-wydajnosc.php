<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Zakładka: Frontend (efekty i zachowanie publicznej strony)
 * Klucz URL pozostaje 'wydajnosc' (kompatybilność linków). Konserwacja przeniesiona
 * do Narzędzi, Tłumaczenia (włącznik modułu) przeniesione tutaj z Panelu admina.
 */

$sub      = sanitize_key($_GET['sub'] ?? 'parallax');
$base_url = add_query_arg('tab', 'wydajnosc', admin_url('options-general.php?page=evoke-one'));

$subs = [
    'parallax'    => ['label' => 'Parallax',           'icon' => 'dashicons-image-flip-vertical'],
    'darkmode'    => ['label' => 'Tryb ciemny',        'icon' => 'dashicons-lightbulb'],
    'cursor'      => ['label' => 'Kursor',             'icon' => 'dashicons-arrow-up-alt'],
    'lenis'       => ['label' => 'Płynne przewijanie', 'icon' => 'dashicons-sort'],
    'fonts'       => ['label' => 'Czcionki (FOUT)',    'icon' => 'dashicons-editor-textcolor'],
    'a11y'        => ['label' => 'Dostępność',         'icon' => 'dashicons-universal-access'],
    'elementy'    => ['label' => 'Elementy Bricks',    'icon' => 'dashicons-screenoptions'],
    'tlumaczenia' => ['label' => 'Tłumaczenia',        'icon' => 'dashicons-translation'],
];

if (!array_key_exists($sub, $subs)) $sub = 'parallax';

evoke_one_render_subtabs($subs, $sub, $base_url);

$parallax_value = evk_get_parallax_value();
$scale_value    = evk_get_parallax_scale();

if ($sub === 'tlumaczenia') {
    require EVOKE_ONE_DIR . 'includes/admin/other-tlumaczenia.php';
} else {
    $sub_file = EVOKE_ONE_DIR . 'includes/admin/tab-' . $sub . '.php';
    if (file_exists($sub_file)) require $sub_file;
}
