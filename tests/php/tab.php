<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Markup dowolnej zakładki panelu, wyrenderowany przez PRAWDZIWY plik zakładki.
 *
 * `anim-tab.php` robi to samo dla Animatora, ale osobno — bo zakładka
 * Animatora niesie własne dane biblioteki i test buduje je z argumentu.
 * Tutaj chodzi o coś innego: o WYGLĄD zakładek, których nikt nie sprawdzał.
 * Zakładek jest trzydzieści i każda ma inne zależności, więc atrapy modułów
 * siedzą w jednym miejscu zamiast w trzydziestu plikach generatorów.
 *
 * Moduły ładujemy PRAWDZIWE (`includes/…`), a nie podstawiamy ich getterów —
 * dzięki temu kształt ustawień jest ten, który zakładka naprawdę dostaje.
 * Atrapą jest wyłącznie to, czego moduł szuka poza sobą: baza i funkcje WP.
 *
 * Argument 1: slug zakładki (`forminbox`, `a11y`, `darkmode`, `whitelabel`).
 */
require __DIR__ . '/_wp-stubs.php';

// ── Atrapy WP potrzebne zakładkom (formularze ustawień, adresy, nonce) ──
function wp_create_nonce($action = -1) { return 'testnonce'; }
function wp_nonce_field($a = -1, $n = '_wpnonce', $r = true, $echo = true) {
    $html = '<input type="hidden" name="' . $n . '" value="testnonce">';
    if ($echo) echo $html;
    return $html;
}
function admin_url($path = '') { return 'https://example.test/wp-admin/' . $path; }
function home_url($path = '') { return 'https://example.test' . $path; }
function site_url($path = '') { return 'https://example.test' . $path; }
function settings_fields($group) {
    echo '<input type="hidden" name="option_page" value="' . $group . '">';
    echo '<input type="hidden" name="action" value="update">';
    echo '<input type="hidden" name="_wpnonce" value="testnonce">';
}
function submit_button($text = '', $type = 'primary', $name = 'submit', $wrap = true) {
    echo '<button type="submit" class="button button-' . ($type ?: 'primary') . '" name="' . $name . '">'
       . ($text ?: 'Zapisz') . '</button>';
}
function esc_textarea($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
// `selected()` bywa wołane z $echo = false i wtedy MUSI zwrócić łańcuch —
// w atrapie z anim-tab.php zwracało null i opcje wychodziły bez zaznaczenia.
function selected($a, $b = true, $echo = true) {
    $out = ((string) $a === (string) $b) ? ' selected' : '';
    if ($echo) echo $out;
    return $out;
}
function disabled($a, $b = true, $echo = true) {
    $out = ($a == $b) ? ' disabled' : '';
    if ($echo) echo $out;
    return $out;
}
function get_bloginfo($what = '') { return 'Witryna testowa'; }
function wp_get_theme() { return new class { public function get($k) { return 'Bricks'; } }; }
function get_transient($k) { return false; }
function set_transient($k, $v, $t = 0) { return true; }
function get_users($args = []) { return []; }
function wp_roles() { return new class { public $roles = []; }; }
function get_editable_roles() { return []; }
function number_format_i18n($n, $d = 0) { return number_format((float) $n, $d, ',', ' '); }
function size_format($b, $d = 0) { return $b . ' B'; }
function get_locale() { return 'pl_PL'; }
function plugins_url($p = '', $f = '') { return 'https://example.test/wp-content/plugins/evoke-one/' . ltrim($p, '/'); }

/** Atrapa $wpdb — tyle, ile potrzeba, by zakładka narysowała tabelę. */
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    public function get_var($q)     { return 'wp_bricks_form_submissions'; }
    public function get_results($q, $out = null) { return []; }
    public function get_col($q)     { return []; }
    public function prepare($q, ...$a) { return $q; }
};

$slug = $argv[1] ?? '';

// ── Moduł + dane, które sprawiają, że zakładka renderuje PEŁNY markup ──
// Pusta konfiguracja rysuje zakładkę bez wierszy repeaterów, a to właśnie
// w wierszach siedzi większość pól, o które w tym teście chodzi.
$TABS = [
    'forminbox' => [
        'module' => 'includes/88-form-inbox.php',
        'file'   => 'includes/admin/tab-forminbox.php',
        'seed'   => function () {
            $GLOBALS['options'][EVK_INBOX_OPTION] = [
                'enabled'        => 1,
                'field_labels'   => ['fonlfr' => 'Temat', '436dec' => 'E-mail'],
                'hidden_fields'  => ['436dec'],
                'form_names'     => ['yrckyz' => 'Formularz kontaktowy'],
                'header_layout'  => [['key' => '{{imie}} {{nazwisko}}', 'type' => 'title']],
                'sidebar_layout' => [['key' => 'fonlfr', 'type' => 'preview']],
            ];
        },
    ],
    'a11y' => [
        // Zakładka dostępności miesza dwa moduły: widget a11y i politykę ruchu.
        'module' => ['includes/anim/motion.php', 'includes/98-accessibility.php'],
        'file'   => 'includes/admin/tab-a11y.php',
        'seed'   => function () { $GLOBALS['options']['evk_a11y'] = ['enabled' => 1]; },
    ],
    'darkmode' => [
        'module' => 'includes/93-darkmode.php',
        'file'   => 'includes/admin/tab-darkmode.php',
        'seed'   => function () { $GLOBALS['options']['evk_darkmode'] = ['enabled' => 1]; },
    ],
    'whitelabel' => [
        'module' => 'includes/interface/white-label.php',
        'file'   => 'includes/admin/admin-whitelabel.php',
        'seed'   => function () { $GLOBALS['options']['evk_white_label'] = ['enabled' => 1]; },
    ],
];

if (!isset($TABS[$slug])) {
    fwrite(STDERR, "Nieznana zakładka: '$slug'. Znane: " . implode(', ', array_keys($TABS)) . "\n");
    exit(2);
}

$tab = $TABS[$slug];
foreach ((array) $tab['module'] as $module) { require EVK_TEST_ROOT . '/' . $module; }
$tab['seed']();
require EVK_TEST_ROOT . '/' . $tab['file'];
