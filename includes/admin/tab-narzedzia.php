<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Zakładka: Narzędzia
 */

$sub      = sanitize_key($_GET['sub'] ?? 'snippets');

/* Lista mieszka w evoke_one_ekrany() (includes/admin/helpers.php), bo
   czytają ją także pasek boczny i wyszukiwarka — patrz komentarz tam. */
$subs = evoke_one_ekrany()['narzedzia'];

if (!array_key_exists($sub, $subs)) $sub = 'snippets';

/* Paska podzakładek tu nie ma od 1.139.1. Wypisywał ekrany tej sekcji nad
   treścią, a od 1.138.0 pasek boczny pokazuje dokładnie tę samą listę — te
   same pozycje i te same adresy, obie z `evoke_one_ekrany()`. Dwa identyczne
   spisy jeden pod drugim czytało się jak dwa poziomy nawigacji, którymi nie
   były. `$subs` zostaje: rozstrzyga, czy `?sub=` z adresu istnieje. */

switch ($sub) {
    case 'snippets':
        evk_snippets_render_tab();
        break;
    case 'smtp':
        require EVOKE_ONE_DIR . 'includes/admin/tools-smtp.php';
        break;
    case 'redirect':
        require EVOKE_ONE_DIR . 'includes/admin/tools-redirect301.php';
        break;
    case 'logs404':
        require EVOKE_ONE_DIR . 'includes/admin/tools-logs404.php';
        break;
    case 'rewizje':
        require EVOKE_ONE_DIR . 'includes/admin/tools-rewizje.php';
        break;
    case 'maintenance':
        require EVOKE_ONE_DIR . 'includes/admin/tab-maintenance.php';
        break;
    case 'io':
        $sub_file = EVOKE_ONE_DIR . 'includes/admin/tab-io.php';
        if (file_exists($sub_file)) require $sub_file;
        break;
}
