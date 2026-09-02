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
/* Dwóch użytkowników: jeden z własnym avatarem, jeden z Gravatarem — obie
   gałęzie kafelka mają się wyrenderować, bo różnią się klasą stanu. */
function get_users($args = []) {
    return [
        (object) ['ID' => 1, 'display_name' => 'Anna Kowalska'],
        (object) ['ID' => 2, 'display_name' => 'Jan Nowak'],
    ];
}
function wp_roles() { return new class { public $roles = []; }; }
function get_editable_roles() {
    return [
        'editor'   => ['name' => 'Redaktor', 'capabilities' => ['edit_posts' => true, 'read' => true]],
        'menedzer' => ['name' => 'Menedżer', 'capabilities' => ['read' => true]],
    ];
}
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
/* Adres MUSI być lokalny. Przy `https://example.test/…` headless próbuje wyjść
   w sieć przez proxy i test zgłasza ERR_TUNNEL_CONNECTION_FAILED jako „błąd JS"
   — usterka nie w kodzie panelu, tylko w atrapie. Jednopikselowy GIF w data:
   rysuje się bez żadnego żądania. */
function wp_get_attachment_image_url($id, $size = 'thumbnail') {
    return 'data:image/gif;base64,R0lGODlhAQABAAAAACw=';
}
function wp_upload_dir() { return ['basedir' => '/tmp', 'baseurl' => 'https://example.test/uploads']; }
function add_query_arg(...$a) { return 'https://example.test/wp-admin/admin.php?page=evk-newsletter'; }
function esc_attr__($s, $d = '') { return $s; }
function wp_kses_post($s) { return $s; }
function mysql2date($f, $d, $t = true) { return '2026-08-08 12:00'; }
/* Prawdziwe `current_time()` oddaje LICZBĘ dla 'timestamp' i datę tekstem dla
   reszty. Atrapa oddawała zawsze tekst, więc kod liczący wygasanie blokad
   odejmował łańcuch od liczby. Ostrzeżenie leciało do stderr, a zakładka i tak
   się rysowała — usterka nie do zauważenia bez zajrzenia w wyjście błędów. */
function current_time($type = 'mysql') {
    return $type === 'timestamp' ? time() : '2026-08-08 12:00:00';
}
function human_time_diff($a, $b = 0) { return '2 godziny'; }
// `evk_nl_base_url()` siedzi w menu.php razem z rejestracją ekranu i całym
// routerem — ładowanie tego pliku pociągnęłoby pół modułu tylko po adres.
function evk_nl_base_url() { return 'https://example.test/wp-admin/admin.php?page=evk-newsletter'; }
// ── Wpisy i strony — dla zakładek SEO ──
// Dwa wpisy na typ wystarczą: chodzi o to, żeby pętla wyrenderowała wiersz
// tabeli razem z polami, a nie żeby udawać bazę.
// Adres ekranu ustawień — zakładki podrzędne budują z niego własne odnośniki.
$GLOBALS['base'] = 'https://example.test/wp-admin/options-general.php?page=evoke-one';
$base = $GLOBALS['base'];

$GLOBALS['posts'] = [
    11 => ['title' => 'Strona główna', 'name' => 'strona-glowna'],
    12 => ['title' => 'O nas',         'name' => 'o-nas'],
];
function get_posts($args = []) {
    return array_map(function ($id) {
        return (object) ['ID' => $id, 'post_title' => $GLOBALS['posts'][$id]['title'],
                         'post_name' => $GLOBALS['posts'][$id]['name'],
                         // Zakładka mapy strony rozdziela strony od wpisów po
                         // `post_type`; bez niego PHP wpisuje ostrzeżenie PROSTO
                         // W MIERZONY MARKUP.
                         'post_type' => $id % 2 ? 'page' : 'post'];
    }, array_keys($GLOBALS['posts']));
}
function get_the_title($p = 0) {
    $id = is_object($p) ? $p->ID : (int) ($p ?: $GLOBALS['cur_post']);
    return $GLOBALS['posts'][$id]['title'] ?? 'Bez tytułu';
}
function get_the_ID() { return $GLOBALS['cur_post'] ?? 0; }
function the_title() { echo esc_html(get_the_title()); }
function the_permalink() { echo 'https://example.test/wpis'; }
function get_permalink($p = 0) { return 'https://example.test/wpis'; }
function get_edit_post_link($p = 0) { return 'https://example.test/wp-admin/post.php?post=' . (int) $p; }
function get_post_meta($id, $key = '', $single = false) { return $single ? '' : []; }
function wp_reset_postdata() { $GLOBALS['cur_post'] = 0; }

/**
 * Atrapa WP_Query — pętla po $GLOBALS['posts'], bez zapytań.
 *
 * Zna `s`, `paged` i `posts_per_page`, bo zakładka meta SEO od 1.55.0 szuka
 * i stronicuje PO STRONIE SERWERA. Atrapa, która ignoruje te argumenty,
 * przepuściłaby stronicowanie, które nic nie stronicuje.
 */
class WP_Query {
    public $found_posts;
    public $max_num_pages;
    private $ids;
    private $i = 0;

    public function __construct($args = []) {
        $ids = array_keys($GLOBALS['posts']);

        $s = (string) ($args['s'] ?? '');
        if ($s !== '') {
            $ids = array_values(array_filter($ids, static function ($id) use ($s) {
                return stripos($GLOBALS['posts'][$id]['title'], $s) !== false;
            }));
        }

        $this->found_posts = count($ids);
        $per = (int) ($args['posts_per_page'] ?? -1);
        if ($per > 0) {
            $this->max_num_pages = (int) ceil($this->found_posts / $per);
            $paged = max(1, (int) ($args['paged'] ?? 1));
            $ids = array_slice($ids, ($paged - 1) * $per, $per);
        } else {
            $this->max_num_pages = 1;
        }
        $this->ids = $ids;
    }

    public function have_posts() { return $this->i < count($this->ids); }
    public function the_post()   { $GLOBALS['cur_post'] = $this->ids[$this->i++]; }
}

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

/* Stałe czasu z rdzenia WP — zakładki liczą na nich wygasanie blokad. */
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('DAY_IN_SECONDS'))  define('DAY_IN_SECONDS', 86400);
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);

/* Zakładka Logów 404 obsługuje własny POST i czyta metodę żądania wprost. */
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* Rejestr tras REST — moduł czyta je z serwera WP, żeby wypisać endpointy.
   Atrapa oddaje kilka prawdziwych w kształcie, bo o kształt listy tu chodzi. */
/* Role Manager sięga po rdzeń ról WP. Atrapy oddają dwie role — jedną core,
   jedną własną — bo tabela ról i wiersz „chroniony" to właśnie to, co mierzymy. */
if (!function_exists('settings_errors'))    { function settings_errors($slug = '') {} }
if (!function_exists('current_user_can'))   { function current_user_can($cap) { return true; } }
if (!function_exists('translate_user_role')) { function translate_user_role($n) { return $n; } }
if (!function_exists('get_role')) {
    function get_role($slug) {
        return new class {
            public $capabilities = ['edit_posts' => true, 'read' => true];
            public function has_cap($c) { return $c === 'evk_access_translations'; }
        };
    }
}
if (!class_exists('WP_Roles')) {
    class WP_Roles {
        public $roles = [];
        public function get_names() { return ['editor' => 'Redaktor', 'menedzer' => 'Menedżer']; }
    }
}

if (!function_exists('rest_get_server')) {
    function rest_get_server() {
        return new class {
            public function get_routes() {
                return [
                    '/wp/v2/posts'  => [['methods' => ['GET' => true]]],
                    '/wp/v2/pages'  => [['methods' => ['GET' => true, 'POST' => true]]],
                    '/wp/v2/users'  => [['methods' => ['GET' => true]]],
                    '/oembed/1.0/embed' => [['methods' => ['GET' => true]]],
                ];
            }
        };
    }
}
if (!function_exists('get_pages'))           { function get_pages($args = []) { return []; } }
// Zakładka Konserwacji losuje kandydata na klucz bypass przy renderowaniu.
if (!function_exists('wp_generate_password')) { function wp_generate_password($len = 12, $special = true) { return str_repeat('x', (int) $len); } }
if (!function_exists('register_activation_hook'))   { function register_activation_hook($f, $cb) {} }
if (!function_exists('register_deactivation_hook')) { function register_deactivation_hook($f, $cb) {} }
if (!function_exists('flush_rewrite_rules'))        { function flush_rewrite_rules($hard = true) {} }
if (!function_exists('add_rewrite_rule'))           { function add_rewrite_rule($r, $q, $a = 'top') {} }
if (!function_exists('add_rewrite_tag'))            { function add_rewrite_tag($t, $r, $q = '') {} }
if (!function_exists('trailingslashit'))     { function trailingslashit($s) { return rtrim($s, '/\\') . '/'; } }
if (!function_exists('wp_dropdown_pages')) {
    function wp_dropdown_pages($args = []) {
        $name = is_array($args) ? ($args['name'] ?? 'page_id') : $args;
        echo '<select name="' . $name . '"><option value="0">— wybierz —</option>'
           . '<option value="11">Strona główna</option></select>';
    }
}
if (!function_exists('get_user_meta'))       { function get_user_meta($id, $k = '', $single = false) { return $id === 1 ? 7 : ''; } }
if (!function_exists('get_avatar_url'))      { function get_avatar_url($id, $a = []) { return 'data:image/gif;base64,R0lGODlhAQABAAAAACw='; } }
if (!function_exists('check_admin_referer')) { function check_admin_referer($a = -1, $n = '_wpnonce') { return true; } }
if (!function_exists('date_i18n'))           { function date_i18n($f, $t = null) { return date($f, $t ?? time()); } }

/* Loader elementów Bricks skanuje katalog wtyczki — bez tych stałych nie ma
   od czego zacząć. */
if (!defined('EVOKE_ONE_DIR')) define('EVOKE_ONE_DIR', EVK_TEST_ROOT . '/');
if (!defined('EVOKE_ONE_URL')) define('EVOKE_ONE_URL', 'https://example.test/wp-content/plugins/evoke-one/');
/* Stałe modułu Tłumaczeń — definiowane normalnie w `evoke-one.php`, którego
   harness nie ładuje (ciągnąłby całą wtyczkę). */
if (!defined('EVOKE_TL_FILE'))  define('EVOKE_TL_FILE', EVK_TEST_ROOT . '/evoke-one.php');
if (!defined('TL_MENU_SLUG'))   define('TL_MENU_SLUG', 'evoke-tlumaczenia');
if (!defined('TL_MENU_TITLE'))  define('TL_MENU_TITLE', 'Tłumaczenia');
if (!defined('TL_VERSION'))     define('TL_VERSION', '1.126.0');
if (!defined('EVOKE_ONE_VERSION')) define('EVOKE_ONE_VERSION', '1.126.0');

$slug = $argv[1] ?? '';

// Argument 2 (opcjonalny): JSON z parametrami zapytania. Zakładki, które
// stronicują albo szukają, czytają $_GET — bez tego dałoby się wyrenderować
// wyłącznie pierwszą stronę bez frazy, czyli jedyny przypadek, w którym
// stronicowanie nie ma nic do roboty.
if (!empty($argv[2])) {
    $_GET = array_merge($_GET, (array) json_decode($argv[2], true));
}

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
                // Trzy warstwy, a nie dwie: przy dwóch „przestawienie" i
                // „odwrócenie" dają ten sam wynik, więc pomyłka w kolejności
                // po upuszczeniu przeszłaby niezauważona.
                'layers'  => [
                    ['type' => 'rect',  'enabled' => 1, 'color' => '#111827'],
                    ['type' => 'photo', 'enabled' => 1, 'offset_x' => 110],
                    ['type' => 'text',  'enabled' => 1, 'color' => '#ffffff'],
                ],
            ];
        },
    ],
    'schema' => [
        'module' => 'includes/90-schema.php',
        'file'   => 'includes/admin/seo/tab-schema.php',
        'seed'   => function () {
            // Repeater encji podrzędnych i lista walut per język rysują się
            // tylko przy danych — a to w nich siedzi połowa pól tej zakładki.
            $GLOBALS['options']['evk_schema'] = [
                'enabled'         => 1,
                'site_name'       => 'Stanica Wodna',
                'sub_entities'    => '[{"type":"Service","name":"Spływy","description":"Krutynia"}]',
                'social_links'    => '["https://example.test/fb"]',
                'descriptions'    => '{"pl":"Opis","en":"Description"}',
                'lang_currencies' => '{"en":"EUR"}',
            ];
        },
    ],
    'sitemap' => [
        'module' => 'includes/30-admin-settings-ajax.php',
        'file'   => 'includes/admin/seo/tab-sitemap.php',
        'seed'   => function () {
            $GLOBALS['options']['tl_sitemap_settings'] = [
                'enabled' => 1, 'include_pages' => 1, 'excluded_ids' => [11],
            ];
        },
    ],
    'seo-meta' => [
        // Zakładka nie ma własnego modułu — czyta wprost z WP_Query i meta.
        'module' => [],
        'file'   => 'includes/admin/seo/tab-meta.php',
        // Dwadzieścia pięć wpisów przy stronie po dwadzieścia: dwie strony,
        // druga niepełna. Przy równym podziale ostatnia strona wyglądałaby
        // jak pierwsza i pomyłka w arytmetyce mogłaby przejść niezauważona.
        'seed'   => function () {
            $GLOBALS['posts'] = [];
            for ($i = 1; $i <= 25; $i++) {
                $GLOBALS['posts'][100 + $i] = [
                    'title' => sprintf('Wpis %02d', $i),
                    'name'  => 'wpis-' . $i,
                ];
            }
            // Jeden tytuł do wyszukania — celowo inny niż reszta.
            $GLOBALS['posts'][200] = ['title' => 'Kontakt i dojazd', 'name' => 'kontakt'];
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

    /* ── Tłumaczenia (osobny ekran, ale ten sam `admin.css`) ─────────────
       Podstrony dostają komplet zmiennych od `tl_render_page()` — harness
       podaje je niżej, tak jak zakładka. */
    'tl-translations' => ['module' => 'TL', 'file' => 'includes/admin/tl/tab-translations.php'],
    'tl-images'       => ['module' => 'TL', 'file' => 'includes/admin/tl/tab-images.php'],
    'tl-slugs'        => ['module' => 'TL', 'file' => 'includes/admin/tl/tab-slugs.php'],
    'tl-dd'           => ['module' => 'TL', 'file' => 'includes/admin/tl/tab-dd.php'],
    'tl-languages'    => ['module' => 'TL', 'file' => 'includes/admin/tl/tab-languages.php'],
    'tl-sitemap'      => ['module' => 'TL', 'file' => 'includes/admin/tl/tab-sitemap.php'],
    'tl-io'           => ['module' => 'TL', 'file' => 'includes/admin/tl/tab-io.php'],

    /* ── Frontend (zakładka „wydajnosc") ─────────────────────────────────
       Każda z tych zakładek bierze ustawienia z singletonu swojego modułu,
       więc moduł ładujemy PRAWDZIWY — atrapą jest tylko to, czego szuka poza
       sobą. Ziarno ustawia opcję, żeby zakładka narysowała pełny markup,
       a nie stan „wyłączone". */
    'fe-cursor' => [
        'module' => 'includes/94-cursor.php',
        'file'   => 'includes/admin/tab-cursor.php',
        'seed'   => function () {
            $GLOBALS['options']['evk_cursor'] = [
                'enabled'  => 1,
                /* Komplet pól wiersza — zakładka czyta je bez `??`, więc
                   niepełne ziarno sypie ostrzeżeniami do stderr. */
                'elements' => [[
                    'selector' => '.karta', 'label' => 'Karta', 'size' => 40,
                    'text' => 'Zobacz', 'backgroundColor' => 'rgba(255,255,255,1)',
                    'textColor' => '#111827', 'cursorBackdropFilter' => 'blur(10px)',
                    'cursorBlendMode' => 'normal', 'textBlendMode' => 'normal',
                    'mixBlendMode' => 'normal', 'borderRadius' => '50%',
                    'border' => 'none', 'transitionDuration' => '0.2s',
                ]],
            ];
        },
    ],
    'fe-lenis' => [
        'module' => 'includes/96-lenis.php',
        'file'   => 'includes/admin/tab-lenis.php',
        'seed'   => function () { $GLOBALS['options']['evk_lenis'] = ['enabled' => 1]; },
    ],
    'fe-bgshift' => [
        'module' => 'includes/anim/bgshift.php',
        'file'   => 'includes/admin/tab-bgshift.php',
        'seed'   => function () { $GLOBALS['options']['evk_bgshift'] = ['enabled' => 1]; },
    ],
    'fe-fonts' => [
        'module' => 'includes/91-fonts.php',
        'file'   => 'includes/admin/tab-fonts.php',
        'seed'   => function () { $GLOBALS['options']['evk_fonts'] = ['enabled' => 1]; },
    ],
    'fe-themecolor' => [
        'module' => 'includes/91-theme-color.php',
        'file'   => 'includes/admin/tab-themecolor.php',
        'seed'   => function () { $GLOBALS['options']['evk_theme_color'] = ['enabled' => 1]; },
    ],
    'fe-parallax' => [
        'module' => 'includes/92-parallax.php',
        'file'   => 'includes/admin/tab-parallax.php',
        'seed'   => function () { $GLOBALS['options']['evoke_parallax_enabled'] = 1; },
    ],

    'fe-elementy' => [
        'module' => 'includes/bricks-elements/loader.php',
        'file'   => 'includes/admin/tab-elementy.php',
        'seed'   => function () { $GLOBALS['options']['evk_elements'] = ['evk-marquee' => 1]; },
    ],
    'fe-newsletter' => [
        /* Bez `menu.php`: definiuje `evk_nl_base_url()`, którą atrapa wyżej
           już podstawiła — zakładka potrzebuje tylko adresu, nie całego menu. */
        'module' => ['includes/newsletter/tables.php', 'includes/newsletter/settings.php',
                     'includes/tools/smtp.php', 'includes/newsletter/mailer.php'],
        'file'   => 'includes/admin/tab-newsletter.php',
        /* Moduł WYŁĄCZONY — zakładka pokazuje wtedy duży stan pusty. */
        'seed'   => function () { $GLOBALS['options']['evk_newsletter'] = ['enabled' => 0]; },
    ],
    'fe-newsletter-on' => [
        /* Ta sama zakładka z modułem WŁĄCZONYM. Obie gałęzie warto mierzyć:
           przy wyłączonym rysuje się stan pusty, przy włączonym — ramka
           ostrzegawcza o nieskonfigurowanym SMTP. Jedno ziarno pokazałoby
           tylko połowę znaczników. */
        'module' => ['includes/newsletter/tables.php', 'includes/newsletter/settings.php',
                     'includes/tools/smtp.php', 'includes/newsletter/mailer.php'],
        'file'   => 'includes/admin/tab-newsletter.php',
        'seed'   => function () { $GLOBALS['options']['evk_newsletter'] = ['enabled' => 1]; },
    ],

    /* ── Panel admina ────────────────────────────────────────────────────
       Podstrony ładuje `tab-admin.php` jawnym `require` w `switch`, ale
       zmienne ($evk_sec, $evk_iface) podaje im tak samo jak zakładka
       Bezpieczeństwa — bez nich renderują się z pustymi polami. */
    'adm-interface' => [
        'module' => 'includes/interface/thumbnails.php',
        'file'   => 'includes/admin/other-interface.php',
        'seed'   => function () { $GLOBALS['options']['evk_interface'] = ['post_thumbnails_enabled' => 1]; },
    ],
    'adm-dashboard' => [
        'module' => 'includes/interface/thumbnails.php',
        'file'   => 'includes/admin/other-dashboard.php',
        'seed'   => function () { $GLOBALS['options']['evoke_dashboard_page_id'] = 11; },
    ],
    'adm-avatar' => [
        'module' => 'includes/86-avatar.php',
        'file'   => 'includes/admin/other-avatar.php',
        /* Bez użytkowników lista kafelków w ogóle się nie rysuje, a to w niej
           siedzi większość przemiecionych znaczników. */
        'seed'   => function () { $GLOBALS['evk_test_users'] = 2; },
    ],
    'adm-content' => [
        'module' => 'includes/interface/thumbnails.php',
        'file'   => 'includes/admin/other-content.php',
        'seed'   => function () { $GLOBALS['options']['evoke_disable_global_comments'] = 1; },
    ],
    'adm-roles' => [
        'module' => 'includes/admin/role-manager-logic.php',
        'file'   => 'includes/admin/admin-roles.php',
    ],
    'adm-tlumaczenia' => [
        'module' => 'includes/interface/thumbnails.php',
        'file'   => 'includes/admin/other-tlumaczenia.php',
        'seed'   => function () { $GLOBALS['options']['evk_tl_module_enabled'] = 1; },
    ],

    /* ── Bezpieczeństwo ──────────────────────────────────────────────────
       Podstrony ładuje `tab-bezpieczenstwo.php` przez `security-$sub.php`,
       więc renderujemy je tak jak zakładka: plik po pliku, ze zmiennymi,
       które ta zakładka im podaje ($evk_sec, $sec_nonce). */
    'sec-login' => [
        'module' => ['includes/security/settings.php', 'includes/security/login-limit.php'],
        'file'   => 'includes/admin/security-login.php',
        'seed'   => function () {
            $GLOBALS['options']['evk_security'] = ['limit_login_enabled' => 1];
            /* Z pustą listą blokad tabela w ogóle się nie rysuje, a to ona
               niesie większość przemiecionych znaczników. */
            $GLOBALS['options']['evk_blocked_ips'] = [
                '10.0.0.1' => ['attempts' => 5, 'blocked_at' => time() - 600, 'username' => 'admin'],
                '10.0.0.2' => ['attempts' => 7, 'blocked_at' => time() - 900, 'username' => ''],
            ];
        },
    ],
    'sec-rest' => [
        'module' => ['includes/security/settings.php', 'includes/security/rest-api.php'],
        'file'   => 'includes/admin/security-rest.php',
        'seed'   => function () { $GLOBALS['options']['evk_security'] = ['rest_block_all' => 1]; },
    ],
    'sec-hardening' => [
        'module' => 'includes/security/settings.php',
        'file'   => 'includes/admin/security-hardening.php',
        'seed'   => function () { $GLOBALS['options']['evk_security'] = ['hide_wp_version' => 1]; },
    ],
    'sec-cleanup' => [
        'module' => 'includes/97-security.php',
        'file'   => 'includes/admin/security-cleanup.php',
        'seed'   => function () { $GLOBALS['options']['evk_cleanup'] = ['disable_xmlrpc' => 1]; },
    ],

    /* ── Narzędzia ───────────────────────────────────────────────────────── */
    'tools-smtp' => [
        'module' => 'includes/tools/smtp.php',
        'file'   => 'includes/admin/tools-smtp.php',
        'seed'   => function () {
            $GLOBALS['options']['evk_smtp'] = ['enabled' => 1, 'log_enabled' => 1];
            /* Log pusty = brak tabeli = brak połowy tego, co mierzymy. */
            $GLOBALS['options']['evk_smtp_log'] = [
                ['time' => '2026-08-27 10:00', 'to' => 'a@example.test', 'subject' => 'Test', 'success' => 1],
                ['time' => '2026-08-27 10:05', 'to' => 'b@example.test', 'subject' => 'Test 2', 'success' => 0, 'error' => 'SMTP connect() failed'],
            ];
        },
    ],
    'tools-redirect' => [
        'module' => 'includes/tools/redirect-301.php',
        'file'   => 'includes/admin/tools-redirect301.php',
        'seed'   => function () { $GLOBALS['options']['evk_301_enabled'] = 1; },
    ],
    'tools-logs404' => [
        'module' => 'includes/tools/logs-404.php',
        'file'   => 'includes/admin/tools-logs404.php',
        'seed'   => function () {
            $GLOBALS['options']['evk_404_enabled']   = 1;
            $GLOBALS['options']['evk_404_skip_bots'] = 1;
        },
    ],
    'tools-io' => [
        'module' => 'includes/admin/page.php',
        'file'   => 'includes/admin/tab-io.php',
    ],
    'tools-maintenance' => [
        'module' => 'includes/tools/draft-revision.php',
        'file'   => 'includes/admin/tab-maintenance.php',
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
        /* Wspólne wykrywanie buildera — moduły frontowe pytają o nie przez
   `evk_w_builderze()`. Plik jest liściem: potrzebuje tylko `is_admin()`
   z atrap i niczego więcej. */
require_once EVK_TEST_ROOT . '/includes/00-context-safety.php';

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
} elseif (($tab['module'] ?? null) === 'TL') {
    /* Siedem zakładek Tłumaczeń czyta te same opcje i te same helpery, więc
       moduły i dane stoją w jednym miejscu zamiast w siedmiu wpisach. */
    foreach (['includes/10-language-system.php', 'includes/30-admin-settings-ajax.php',
              'includes/admin/tl/bootstrap.php'] as $m) {
        require EVK_TEST_ROOT . '/' . $m;
    }
    $GLOBALS['options']['tl_languages'] = [
        'en' => ['code' => 'en', 'name' => 'Angielski', 'html' => 'en-GB'],
        'de' => ['code' => 'de', 'name' => 'Niemiecki', 'html' => 'de-DE'],
    ];
    $GLOBALS['options']['tl_translations'] = ['groups' => [
        ['id' => 'g1', 'name' => 'Nagłówki', 'rows' => [
            ['id' => 'r1', 'pl' => 'Kontakt', 'en' => 'Contact', 'de' => 'Kontakt'],
        ]],
    ]];
    $GLOBALS['options']['tl_images']   = [['pl' => 5, 'en' => 6]];
    $GLOBALS['options']['tl_url_slugs'] = [['pl' => 'kontakt', 'en' => 'contact']];
    $GLOBALS['options']['tl_dd_keys']  = ['cennik' => 'Kontakt'];
} else {
    foreach ((array) $tab['module'] as $module) { require EVK_TEST_ROOT . '/' . $module; }
}
if (isset($tab['seed'])) $tab['seed']();

/* Podstrony Bezpieczeństwa dostają te zmienne od `tab-bezpieczenstwo.php`
   i liczą na nie bez sprawdzania. Bez nich renderowały się z pustymi polami
   i ostrzeżeniami — czyli mierzyłoby się coś innego niż to, co widzi
   użytkownik. */
if (function_exists('evk_security_get')) {
    $evk_sec   = evk_security_get();
    $sec_nonce = wp_create_nonce('evk_security_nonce');
}
if (function_exists('evk_interface_get')) {
    $evk_iface = evk_interface_get();
}
/* `tab-wydajnosc.php` liczy te dwie wartości i podaje je podstronom. */
if (function_exists('evk_get_parallax_value')) {
    $parallax_value = evk_get_parallax_value();
    $scale_value    = function_exists('evk_get_parallax_scale') ? evk_get_parallax_scale() : 1.0;
}

/* Komplet zmiennych, który `tl_render_page()` przekazuje swoim zakładkom.
   Bez nich renderują się z pustymi listami — czyli mierzyłoby się co innego
   niż to, co widzi użytkownik. */
if (($tab['module'] ?? null) === 'TL') {
    $data             = get_option('tl_translations', ['groups' => []]);
    $images           = get_option('tl_images', []);
    $url_slugs        = get_option('tl_url_slugs', []);
    $sitemap_settings = tl_get_sitemap_settings();
    $dd_keys          = get_option('tl_dd_keys', []);
    $dd_by_phrase     = [];
    foreach ($dd_keys as $k => $phrase) {
        $phrase = trim((string) $phrase);
        if ($phrase !== '') $dd_by_phrase[$phrase] = $k;
    }
    $langs            = tl_get_languages();
    $codes            = array_keys($langs);
    $tab_slug         = 'translations';
    $base             = 'https://example.test/wp-admin/options-general.php?page=evoke-tlumaczenia';
    $menu_location    = 'options-general.php';
    $stats            = tl_coverage_stats();
    $nonce            = 'testnonce';
    $ajax_url         = 'https://example.test/wp-admin/admin-ajax.php';
}

require EVK_TEST_ROOT . '/' . $tab['file'];
