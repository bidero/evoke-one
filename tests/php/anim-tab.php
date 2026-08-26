<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Markup zakładki Animator, wyrenderowany przez PRAWDZIWY tab-animator.php.
 *
 * Test przeglądarkowy ładuje go tak, jak zobaczyłby go użytkownik. Przepisanie
 * uproszczonego HTML-a do fixture'a sprawdzałoby tylko naszą wersję panelu,
 * a usterka 1.37.0 siedziała właśnie w tym, jak markup łączy się ze skryptem.
 *
 * Argument: JSON z listą slugów, z których zbudować wiersze biblioteki.
 */
require __DIR__ . '/_wp-stubs.php';

function evk_preserve_toggle($input, $key, $field = 'enabled', $default = 0) {
    return isset($input[$field]) ? (int) !empty($input[$field]) : $default;
}
function settings_fields($group) {
    echo '<input type="hidden" name="option_page" value="' . $group . '">';
    echo '<input type="hidden" name="action" value="update">';
    echo '<input type="hidden" name="_wpnonce" value="testnonce">';
}
function submit_button($text = '', $type = '', $name = '', $wrap = true) {
    echo '<button type="submit" class="button button-primary" name="' . $name . '">' . $text . '</button>';
}
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function selected($a, $b = true, $echo = true) { if ($a == $b) echo ' selected'; }

/* Wspólne wykrywanie buildera — moduły frontowe pytają o nie przez
   `evk_w_builderze()`. Plik jest liściem: potrzebuje tylko `is_admin()`
   z atrap i niczego więcej. */
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';

require EVK_TEST_ROOT . '/includes/anim/animator.php';

$slugs = json_decode($argv[1] ?? '[]', true) ?: [];

$GLOBALS['options']['evk_animator'] = [
    'enabled'    => 1,
    'animations' => array_map(function ($slug) {
        return ['slug' => $slug, 'label' => 'Etykieta ' . $slug, 'preset' => 'fade-up'];
    }, $slugs),
];

require EVK_TEST_ROOT . '/includes/admin/tab-animator.php';
