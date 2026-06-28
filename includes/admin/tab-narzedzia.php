<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Zakładka: Narzędzia
 */

$sub      = sanitize_key($_GET['sub'] ?? 'snippets');
$base_url = add_query_arg('tab', 'narzedzia', admin_url('options-general.php?page=evoke-one'));

$subs = [
    'snippets' => ['label' => 'Skrypty PHP',        'icon' => 'dashicons-editor-code'],
    'smtp'     => ['label' => 'SMTP',                'icon' => 'dashicons-email-alt'],
    'redirect' => ['label' => 'Przekierowania 301',  'icon' => 'dashicons-redo'],
    'logs404'  => ['label' => 'Logi 404',            'icon' => 'dashicons-warning'],
    'maintenance' => ['label' => 'Konserwacja',      'icon' => 'dashicons-admin-tools'],
    'io'       => ['label' => 'Eksport / Import',    'icon' => 'dashicons-database-import'],
];

if (!array_key_exists($sub, $subs)) $sub = 'snippets';

evoke_one_render_subtabs($subs, $sub, $base_url);

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
    case 'maintenance':
        require EVOKE_ONE_DIR . 'includes/admin/tab-maintenance.php';
        break;
    case 'io':
        $sub_file = EVOKE_ONE_DIR . 'includes/admin/tab-io.php';
        if (file_exists($sub_file)) require $sub_file;
        break;
}
