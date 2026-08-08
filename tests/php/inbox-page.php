<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Markup strony Skrzynki wiadomości, wyrenderowany przez PRAWDZIWY plik.
 *
 * To osobny ekran wp-admin, nie zakładka panelu: nie ma `.wrap`, nie ładuje
 * `admin.css` i niesie własny blok stylów. Dlatego renderuje go osobny
 * generator, a nie `tab.php` — tamten zakłada otoczkę panelu.
 */
require __DIR__ . '/_wp-stubs.php';

function wp_create_nonce($a = -1) { return 'testnonce'; }
function wp_nonce_url($url, $a = -1) { return $url . '&_wpnonce=testnonce'; }
function admin_url($p = '') { return 'https://example.test/wp-admin/' . $p; }

$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public function get_var($q) { return 'wp_bricks_form_submissions'; }
    public function get_results($q, $o = null) { return []; }
    public function prepare($q, ...$a) { return $q; }
};

require EVK_TEST_ROOT . '/includes/88-form-inbox.php';
$GLOBALS['options'][EVK_INBOX_OPTION] = ['enabled' => 1];
require EVK_TEST_ROOT . '/includes/admin/forminbox-page.php';
