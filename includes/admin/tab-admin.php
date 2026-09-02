<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Zakładka: Admin
 */

$sub      = sanitize_key($_GET['sub'] ?? 'interface');
$base_url = add_query_arg('tab', 'admin_panel', admin_url('options-general.php?page=evoke-one'));

/* Lista mieszka w evoke_one_ekrany() (includes/admin/helpers.php), bo
   czytają ją także pasek boczny i wyszukiwarka — patrz komentarz tam. */
$subs = evoke_one_ekrany()['admin_panel'];

if (!array_key_exists($sub, $subs)) $sub = 'interface';

evoke_one_render_subtabs($subs, $sub, $base_url);

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
