<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Zakładka: Admin
 */

$sub      = sanitize_key($_GET['sub'] ?? 'interface');
$base_url = add_query_arg('tab', 'admin_panel', admin_url('options-general.php?page=evoke-one'));

$subs = [
    'interface'    => ['label' => 'Interfejs',     'icon' => 'dashicons-admin-appearance'],
    'dashboard'    => ['label' => 'Kokpit',         'icon' => 'dashicons-dashboard'],
    'avatar'       => ['label' => 'Avatar',         'icon' => 'dashicons-admin-users'],
    'content'      => ['label' => 'Treść',          'icon' => 'dashicons-admin-comments'],
    'whitelabel'   => ['label' => 'White label',    'icon' => 'dashicons-admin-customizer'],
    'roles'        => ['label' => 'Role Manager',   'icon' => 'dashicons-groups'],
];

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
