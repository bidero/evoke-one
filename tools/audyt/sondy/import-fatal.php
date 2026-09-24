<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Czy import ustawień (Narzędzia → Eksport/Import) przeżywa wyłączony moduł Tłumaczeń?
define('DOING_AJAX', true);
$_SERVER['HTTP_HOST'] = 'stara.test'; $_SERVER['REQUEST_URI'] = '/wp-admin/admin-ajax.php';
require (getenv('EVK_WP_PATH') ?: getenv('HOME') . '/.cache/evk-testowy-wp') . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
wp_set_current_user(1);
echo "TL włączony: " . var_export(get_option('evk_tl_module_enabled', 0), true) . "\n";
echo "tl_get_active_lang_codes istnieje: " . var_export(function_exists('tl_get_active_lang_codes'), true) . "\n";
add_filter('wp_die_ajax_handler', function () { return function ($m) { throw new Exception('wp_die: ' . (is_string($m) ? $m : print_r($m, true))); }; });
do_action('admin_init');
$paczka = ['_evoke_one_export' => true, 'evk_darkmode' => ['enabled' => 0, 'x' => 1]];
if (getenv('Z_TL')) $paczka['tl_translations'] = ['groups' => ['g1' => ['name' => 'A', 'rows' => ['r1' => ['pl' => 'Kot', 'en' => 'Cat']]]]];
$_POST = $_REQUEST = ['action' => 'tl_import', 'nonce' => wp_create_nonce('tl_ajax_nonce'), 'json' => wp_slash(json_encode($paczka)), 'decisions' => '{}'];
ob_start();
try { do_action('wp_ajax_tl_import'); echo "brak wyjątku\n"; }
catch (Throwable $e) { echo get_class($e) . ': ' . $e->getMessage() . "\n"; }
$out = ob_get_clean();
echo "WYJŚCIE: " . substr($out, 0, 400) . "\n";
echo "evk_darkmode po imporcie: " . json_encode(get_option('evk_darkmode')) . "\n";
