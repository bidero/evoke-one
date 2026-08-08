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
function get_post_types($args = [], $output = 'names') {
    // Dwa typy wystarczą: zakładki rysują po jednym polu na typ, a chodzi
    // o to, żeby pętla w ogóle się wykonała.
    $mk = function ($name, $label, $one) {
        return (object) [
            'name'   => $name,
            'label'  => $label,
            'public' => true,
            // Zakładki sięgają po `->labels->singular_name`; bez tego PHP sypie
            // ostrzeżeniami PROSTO W RENDEROWANY MARKUP, a test mierzyłby stronę
            // z komunikatami błędów wmieszanymi w treść.
            'labels' => (object) ['name' => $label, 'singular_name' => $one],
        ];
    };
    return ['post' => $mk('post', 'Wpisy', 'Wpis'), 'page' => $mk('page', 'Strony', 'Strona')];
}
function wp_get_attachment_image_url($id, $size = 'thumbnail') { return 'https://example.test/obraz.jpg'; }
function wp_upload_dir() { return ['basedir' => '/tmp', 'baseurl' => 'https://example.test/uploads']; }
function add_query_arg(...$a) { return 'https://example.test/wp-admin/admin.php?page=evk-newsletter'; }
function esc_attr__($s, $d = '') { return $s; }
function wp_kses_post($s) { return $s; }
function mysql2date($f, $d, $t = true) { return '2026-08-08 12:00'; }
function current_time($type = 'mysql') { return '2026-08-08 12:00:00'; }
function human_time_diff($a, $b = 0) { return '2 godziny'; }
// `evk_nl_base_url()` siedzi w menu.php razem z rejestracją ekranu i całym
// routerem — ładowanie tego pliku pociągnęłoby pół modułu tylko po adres.
function evk_nl_base_url() { return 'https://example.test/wp-admin/admin.php?page=evk-newsletter'; }
function wp_enqueue_editor() {}
function wp_editor($content, $id, $settings = []) {
    echo '<textarea id="' . $id . '" name="' . ($settings['textarea_name'] ?? $id) . '" rows="'
       . ($settings['textarea_rows'] ?? 10) . '">' . htmlspecialchars((string) $content) . '</textarea>';
}

/** Atrapa $wpdb — tyle, ile potrzeba, by zakładka narysowała tabelę.
 *
 * Wiersze dobierane po NAZWIE TABELI w zapytaniu. Pusta baza rysuje zakładki
 * bez repeaterów i tabel, a to właśnie w nich siedzi większość znaczników,
 * o które w tym teście chodzi — zwłaszcza w newsletterze, gdzie trzy z pięciu
 * zakładek są ekranami listowymi. */
$GLOBALS['wpdb'] = new class {
    public $prefix = 'wp_';
    /** @var array<string, array<int, array<string, mixed>>> */
    public $seed = [];

    private function pick($q) {
        foreach ($this->seed as $table => $rows) {
            if (strpos($q, $table) !== false) return $rows;
        }
        return [];
    }
    public function get_var($q)     { return 'wp_bricks_form_submissions'; }
    public function get_results($q, $out = null) { return $this->pick($q); }
    public function get_row($q, $out = null)     { $r = $this->pick($q); return $r[0] ?? null; }
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
    'og' => [
        'module' => 'includes/opengraph/settings.php',
        'file'   => 'includes/admin/seo/tab-og.php',
        'seed'   => function () {
            $GLOBALS['options']['evk_og'] = [
                'enabled' => 1,
                'layers'  => [
                    ['type' => 'rect', 'enabled' => 1, 'color' => '#111827'],
                    ['type' => 'text', 'enabled' => 1, 'color' => '#ffffff'],
                ],
            ];
        },
    ],
    // ── Newsletter ──
    // Osobny ekran (`includes/newsletter/menu.php`), ale ta sama otoczka:
    // `.wrap` + `.evo-tabs` + `.evo-panel` i ten sam `admin.css`. Zakładki
    // renderują się więc w tych samych warunkach co reszta panelu.
    'nl-lists'     => ['module' => 'NL', 'file' => 'includes/admin/newsletter/tab-lists.php'],
    'nl-campaigns' => ['module' => 'NL', 'file' => 'includes/admin/newsletter/tab-campaigns.php'],
    'nl-templates' => ['module' => 'NL', 'file' => 'includes/admin/newsletter/tab-templates.php'],
    'nl-reports'   => ['module' => 'NL', 'file' => 'includes/admin/newsletter/tab-reports.php'],
    'nl-settings'  => ['module' => 'NL', 'file' => 'includes/admin/newsletter/tab-settings.php'],

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

// Newsletter ma wspólny zestaw modułów i wspólne dane — pięć zakładek czyta
// te same tabele, więc powtarzanie tego przy każdej byłoby pustym hałasem.
if (($tab['module'] ?? null) === 'NL') {
    foreach (['tables.php', 'settings.php', 'lists.php', 'campaigns.php'] as $m) {
        require EVK_TEST_ROOT . '/includes/newsletter/' . $m;
    }
    $GLOBALS['wpdb']->seed = [
        'evk_nl_lists' => [
            ['id' => 1, 'name' => 'Newsletter główny', 'description' => 'Lista podstawowa',
             'created_at' => '2026-01-01 10:00:00'],
            ['id' => 2, 'name' => 'Klienci', 'description' => '', 'created_at' => '2026-02-01 10:00:00'],
        ],
        'evk_nl_campaigns' => [
            ['id' => 1, 'subject' => 'Nowości sierpniowe', 'status' => 'sent',
             'template_id' => 1, 'lists_json' => '[1]', 'body' => '<p>Treść</p>',
             'sent_at' => '2026-08-01 09:00:00', 'created_at' => '2026-07-30 09:00:00',
             'scheduled_at' => null, 'name' => 'Nowości sierpniowe',
             'from_name' => 'Evoke', 'from_email' => 'redakcja@example.test'],
            ['id' => 2, 'subject' => 'Szkic wrześniowy', 'status' => 'draft',
             'template_id' => 1, 'lists_json' => '[1,2]', 'body' => '',
             'sent_at' => null, 'created_at' => '2026-08-05 09:00:00',
             'scheduled_at' => '2026-09-01 08:00:00', 'name' => 'Szkic wrześniowy',
             'from_name' => 'Evoke', 'from_email' => 'redakcja@example.test'],
        ],
        'evk_nl_templates' => [
            ['id' => 1, 'name' => 'Podstawowy', 'subject' => 'Temat domyślny',
             'html' => '<html><body>{{content}}</body></html>',
             'created_at' => '2026-01-01 10:00:00'],
        ],
        'evk_nl_subscribers' => [
            ['id' => 1, 'email' => 'ktos@example.test', 'name' => 'Ktoś', 'status' => 'confirmed',
             'list_id' => 1, 'created_at' => '2026-03-01 10:00:00',
             'unsubscribed_at' => null, 'confirmed_at' => '2026-03-01 10:05:00'],
        ],
        'evk_nl_logs' => [
            ['id' => 1, 'campaign_id' => 1, 'email' => 'ktos@example.test', 'event' => 'open',
             'subscriber_id' => 1, 'name' => 'Ktoś', 'url' => 'https://example.test/',
             'created_at' => '2026-08-01 10:00:00'],
        ],
    ];
} else {
    foreach ((array) $tab['module'] as $module) { require EVK_TEST_ROOT . '/' . $module; }
}
if (isset($tab['seed'])) $tab['seed']();
require EVK_TEST_ROOT . '/' . $tab['file'];
