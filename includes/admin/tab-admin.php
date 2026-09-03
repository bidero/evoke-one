<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Zakładka: Admin
 */

$sub      = sanitize_key($_GET['sub'] ?? 'interface');

/* Lista mieszka w evoke_one_ekrany() (includes/admin/helpers.php), bo
   czytają ją także pasek boczny i wyszukiwarka — patrz komentarz tam. */
$subs = evoke_one_ekrany()['admin_panel'];

if (!array_key_exists($sub, $subs)) $sub = 'interface';

/* Paska podzakładek tu nie ma od 1.139.1. Wypisywał ekrany tej sekcji nad
   treścią, a od 1.138.0 pasek boczny pokazuje dokładnie tę samą listę — te
   same pozycje i te same adresy, obie z `evoke_one_ekrany()`. Dwa identyczne
   spisy jeden pod drugim czytało się jak dwa poziomy nawigacji, którymi nie
   były. `$subs` zostaje: rozstrzyga, czy `?sub=` z adresu istnieje. */

$evk_sec   = evk_security_get();
$evk_iface = evk_interface_get();

switch ($sub) {
    case 'interface':
        require EVOKE_ONE_DIR . 'includes/admin/other-interface.php';
        break;
    case 'dashboard':
        require EVOKE_ONE_DIR . 'includes/admin/other-dashboard.php';
        break;
    case 'avatar':
        require EVOKE_ONE_DIR . 'includes/admin/other-avatar.php';
        break;
    case 'content':
        require EVOKE_ONE_DIR . 'includes/admin/other-content.php';
        break;
    case 'whitelabel':
        require EVOKE_ONE_DIR . 'includes/admin/admin-whitelabel.php';
        break;
    case 'roles':
        require EVOKE_ONE_DIR . 'includes/admin/admin-roles.php';
        break;
}
