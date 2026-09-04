<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Minimalne atrapy WordPressa — tyle, ile potrzebują testowane pliki.
 *
 * Sens: sprawdzamy PRAWDZIWE filtry i sanityzację wtyczki, a nie ich opis.
 * Zarejestrowane callbacki lądują w $GLOBALS['hooks'], żeby test mógł je wywołać.
 */
define('ABSPATH', 1);
// Stałe formatu wyniku $wpdb — moduły przekazują je do get_results()/get_row().
if (!defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');
if (!defined('ARRAY_N')) define('ARRAY_N', 'ARRAY_N');
if (!defined('OBJECT'))  define('OBJECT',  'OBJECT');

$GLOBALS['hooks']    = [];
$GLOBALS['options']  = [];
$GLOBALS['enqueued']   = [];
$GLOBALS['registered'] = [];
$GLOBALS['inline']    = [];
$GLOBALS['localized'] = [];
$GLOBALS['sanitizers'] = [];
$GLOBALS['new_allowed_options'] = [];

function add_filter($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $cb; }
function add_action($hook, $cb, $prio = 10, $args = 1) { $GLOBALS['hooks'][$hook][] = $cb; }
/* Pod strażą, bo `tests/php/svg-sanityzacja.php` potrzebuje wersji, która
   NAPRAWDĘ odpala zarejestrowane filtry — sanityzacja SVG rozszerza listę
   dozwolonych właściwości CSS przez `safe_style_css`. Tutaj zostaje wersja
   przepuszczająca wartość bez zmian: podmiana jej globalnie kazałaby odpalić
   wszystkie filtry we wszystkich testach naraz, a to osobna robota. */
if (!function_exists('apply_filters')) {
    function apply_filters($hook, $value) { return $value; }
}
if (!function_exists('remove_filter')) {
    function remove_filter($hook, $cb, $prio = 10) {
        foreach (($GLOBALS['hooks'][$hook] ?? []) as $i => $zarejestrowany) {
            if ($zarejestrowany === $cb) unset($GLOBALS['hooks'][$hook][$i]);
        }
        return true;
    }
}

function get_option($key, $default = false) {
    return array_key_exists($key, $GLOBALS['options']) ? $GLOBALS['options'][$key] : $default;
}
// Sanityzacja odpala się PRZY ZAPISIE, nie przed nim — tak jak w WordPressie,
// gdzie update_option() woła sanitize_option(). Endpointy, które sanityzują
// z ręki i dopiero potem zapisują, przepuszczają dane przez sanityzator DWA
// RAZY; atrapa musi to pokazywać, a nie ukrywać.
function update_option($key, $value, $autoload = null) {
    if (isset($GLOBALS['sanitizers'][$key])) {
        $value = call_user_func($GLOBALS['sanitizers'][$key], $value);
    }
    $GLOBALS['options'][$key] = $value;
    /* Trzeci argument zapisujemy osobno: to on decyduje, czy WordPress wczytuje
       opcję przy KAŻDYM żądaniu do serwisu. Bez tego atrapa nie odróżniałaby
       opcji autoloadowanej od zwykłej, a różnica bywa całą treścią naprawy. */
    $GLOBALS['autoload'][$key] = $autoload;
    return true;
}
/**
 * Rejestr ustawień — na tyle wierny, na ile potrzebuje tego generyczny zapis.
 *
 * WordPress trzyma listę opcji należących do grupy w $new_allowed_options
 * (przed 5.5: $new_whitelist_options) i podpina `sanitize_callback` pod filtr
 * `sanitize_option_{$opcja}`, który odpala `update_option()`. Endpoint zapisu
 * stoi na obu tych rzeczach, więc atrapa musi je odwzorować — inaczej test
 * sprawdzałby zapis do tablicy, a nie zapis ustawień.
 */
function register_setting($group, $option, $args = []) {
    $GLOBALS['new_allowed_options'][$group][] = $option;
    if (!empty($args['sanitize_callback'])) {
        $GLOBALS['sanitizers'][$option] = $args['sanitize_callback'];
    }
}

function __($s, $d = '') { return $s; }
function _e($s, $d = '') { echo $s; }
function esc_html__($s, $d = '') { return $s; }
function esc_html_e($s, $d = '') { echo $s; }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES); }
function esc_url($s) { return (string) $s; }
function esc_js($s) { return $s; }
function sanitize_key($s) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $s)); }
function sanitize_title($s) {
    $s = strtolower(trim((string) $s));
    return trim(preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', $s)), '-');
}
function sanitize_text_field($s) { return trim(strip_tags((string) $s)); }
function sanitize_textarea_field($s) { return trim(strip_tags((string) $s)); }
/**
 * Zwraca `''` dla pustej wartości, a `null` dla niepustej, ale nieprawidłowej
 * — dokładnie jak oryginał. Ta atrapa oddawała wcześniej `''` w obu
 * przypadkach i była przez to ŁAGODNIEJSZA od funkcji, którą udaje: kod
 * porównujący wynik z `''` przechodził w teście, a na żywej stronie dostawał
 * `null` i wpuszczał go dalej. Atrapa łagodniejsza od oryginału nie jest
 * uproszczeniem, tylko ukrytą różnicą.
 */
function sanitize_hex_color($s) {
    if ($s === '' || $s === null) return '';
    return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', (string) $s) ? $s : null;
}
function sanitize_email($s) { return filter_var((string) $s, FILTER_VALIDATE_EMAIL) ?: ''; }
function esc_url_raw($s) { return (string) $s; }
function wp_parse_args($a, $d) { return array_merge($d, is_array($a) ? $a : []); }
function wp_json_encode($v) { return json_encode($v, JSON_UNESCAPED_UNICODE); }
// Enqueue'y zapisujemy zamiast połykać: literówka w nazwie handle'a niczego
// nie wywala — skrypt po prostu nie trafia na stronę i efekt cicho nie działa.
function wp_enqueue_script($handle, $src = '', $deps = [], $ver = false, $args = false) {
    $GLOBALS['enqueued'][$handle] = ['src' => $src, 'deps' => (array) $deps, 'ver' => $ver];
    if ($src !== '') wp_register_script($handle, $src, $deps, $ver, $args);
}
// Rejestracja to nie to samo co enqueue: skrypt zarejestrowany jest znany
// WordPressowi, ale trafia na stronę dopiero przez wp_enqueue_script($handle).
// Panel korzysta z tej różnicy — GSAP rejestruje moduł, a panel tylko dociąga.
function wp_register_script($handle, $src = '', $deps = [], $ver = false, $args = false) {
    $GLOBALS['registered'][$handle] = ['src' => $src, 'deps' => (array) $deps, 'ver' => $ver];
}

/*
 * Kolejka skryptów tak, jak widzi ją kod wtyczki.
 *
 * Potrzebna preloadowi w Animatorze: adres do `<link rel="preload">` MUSI być
 * co do znaku tym samym, który WordPress wydrukuje w `<script src>` — inaczej
 * przeglądarka pobiera plik dwa razy. Dlatego wtyczka czyta go z kolejki,
 * a nie składa drugi raz z własnych stałych; atrapa musi tę kolejkę mieć.
 */
function wp_scripts() {
    $q = new stdClass();
    $q->registered = [];
    foreach (($GLOBALS['registered'] ?? []) as $handle => $dane) {
        $q->registered[$handle] = (object) $dane;
    }
    return $q;
}

/* Pod strażą `function_exists`, bo `tests/php/tab.php` ma własną atrapę tej
   funkcji — zwracającą stały adres podstrony newslettera — i deklaruje ją,
   zanim dojdzie do `require` tego pliku. Bez straży: „Cannot redeclare". */
if (!function_exists('add_query_arg')) {
    /* Oryginał ma DWA kształty wywołania: `(tablica, adres)` i `(klucz, wartość,
       adres)`. Atrapa obsługiwała tylko drugi, więc kod newslettera — który
       składa adresy tablicą — wywalał się na „Too few arguments". Atrapa węższa
       od funkcji, którą udaje, przewraca test w miejscu niezwiązanym z tym, co
       test bada. */
    function add_query_arg($a, $b = null, $c = null) {
        $args = is_array($a) ? $a : [$a => $b];
        $url  = is_array($a) ? (string) $b : (string) $c;
        foreach ($args as $klucz => $wartosc) {
            $sep = strpos($url, '?') === false ? '?' : '&';
            $url .= $sep . rawurlencode((string) $klucz) . '=' . rawurlencode((string) $wartosc);
        }
        return $url;
    }
}
function wp_script_is($handle, $list = 'enqueued') {
    if ($list === 'registered') return isset($GLOBALS['registered'][$handle]);
    return isset($GLOBALS['enqueued'][$handle]);
}
function wp_add_inline_script($handle, $data, $position = 'after') {
    $GLOBALS['inline'][] = ['handle' => $handle, 'data' => $data, 'position' => $position];
}
function wp_localize_script($handle, $name, $data) {
    $GLOBALS['localized'][$name] = $data;
}
/* Domyślnie żądanie frontu. Harness, który potrzebuje panelu, podnosi
   `$GLOBALS['is_admin']` — inaczej warunki po `is_admin()` w modułach są
   nieosiągalne i mutacja w nich przechodzi na zielono. */
function is_admin() { return !empty($GLOBALS['is_admin']); }

/*
 * Biblioteka mediów i meta wpisów — na tyle, ile potrzebuje `render()`
 * elementu rysującego obrazy.
 *
 * Załączniki celowo NIE są wymyślane w locie: `wp_get_attachment_image_url()`
 * oddaje pusty adres dla numeru, którego test nie wstawił. Inaczej „obraz,
 * którego nie ma w bibliotece, znika" byłoby nie do zmierzenia — każdy numer
 * dawałby obrazek.
 */
$GLOBALS['attachments'] = [];
$GLOBALS['post_meta']   = [];
$GLOBALS['current_post'] = 0;

/*
 * Bloki `function_exists` NIE są tu ostrożnością na wyrost. Część harnessów
 * (np. tab.php) trzyma własne, prostsze wersje tych trzech funkcji — a PHP
 * definiuje bezwarunkowe deklaracje z góry pliku, ZANIM wykona `require` tych
 * atrap. Bez tych bloków taki harness wywracał się na „Cannot redeclare".
 */
if (!function_exists('get_the_ID')) {
    function get_the_ID() { return (int) $GLOBALS['current_post']; }
}

/*
 * Wpis w `$GLOBALS['attachments']` bywa dwojaki i to jest celowe:
 *   'adres'                → załącznik BEZ zapisanych wymiarów,
 *   ['adres', 800, 600]    → z wymiarami.
 * Bez tego rozróżnienia „obraz niesie wymiary z załącznika" i „bez metadanych
 * nie dostaje pustych atrybutów" byłyby jednym i tym samym przypadkiem.
 */
if (!function_exists('wp_get_attachment_image_url')) {
    function wp_get_attachment_image_url($id, $size = 'thumbnail') {
        $a = $GLOBALS['attachments'][(int) $id] ?? '';
        return is_array($a) ? ($a[0] ?? '') : $a;
    }
}

if (!function_exists('wp_get_attachment_image_src')) {
    function wp_get_attachment_image_src($id, $size = 'thumbnail') {
        $a = $GLOBALS['attachments'][(int) $id] ?? '';
        if (is_array($a)) {
            return [$a[0] ?? '', (int) ($a[1] ?? 0), (int) ($a[2] ?? 0), false];
        }
        return ('' === $a) ? false : [$a, 0, 0, false];
    }
}

if (!function_exists('get_post_meta')) {
    function get_post_meta($id, $key = '', $single = false) {
        $v = $GLOBALS['post_meta'][(int) $id][$key] ?? '';
        return $single ? $v : ($v === '' ? [] : [$v]);
    }
}

/*
 * MAGAZYN WPISÓW — na tyle, ile potrzeba modułom trzymającym dane we wpisach
 * (dziś snippety). Nie udajemy WP_Query: obsługujemy te argumenty, których
 * moduły naprawdę używają — `post_type`, `post_status`, `name`, `fields`,
 * `orderby` po `menu_order`. Argument, którego atrapa nie zna, jest po prostu
 * pomijany, więc zapytanie zbudowane na wyrost zwróci ZA DUŻO, a nie za mało;
 * to lepszy kierunek pomyłki niż cicho pusty wynik.
 */
$GLOBALS['posts_store'] = [];
$GLOBALS['posts_next_id'] = 1000;

/* KAŻDA FUNKCJA POD WŁASNYM `function_exists`, nie sześć pod jednym.
   Harnessy trzymają własne, prostsze wersje pojedynczych funkcji (tl-eksport
   ma swój `get_posts`), a wspólna bramka sprawdza tylko pierwszą z brzegu —
   przy cudzym `get_posts` i naszym `wp_insert_post` PHP wywala się na
   „Cannot redeclare". Wyszło od razu po dołożeniu tego bloku. */
if (!function_exists('wp_insert_post')) {
    function wp_insert_post($dane) {
        $id = ++$GLOBALS['posts_next_id'];
        $GLOBALS['posts_store'][$id] = (object) array_merge([
            'ID' => $id, 'post_title' => '', 'post_content' => '', 'post_status' => 'publish',
            'post_type' => 'post', 'post_name' => '', 'menu_order' => 0,
        ], (array) $dane);
        $GLOBALS['posts_store'][$id]->ID = $id;
        return $id;
    }
}
if (!function_exists('wp_update_post')) {
    function wp_update_post($dane) {
        $id = (int) ($dane['ID'] ?? 0);
        if (!$id || !isset($GLOBALS['posts_store'][$id])) return 0;
        foreach ((array) $dane as $k => $v) $GLOBALS['posts_store'][$id]->$k = $v;
        return $id;
    }
}
if (!function_exists('get_post')) {
    function get_post($id = 0) { return $GLOBALS['posts_store'][(int) $id] ?? null; }
}
if (!function_exists('get_posts')) {
    function get_posts($args = []) {
        $out = [];
        foreach ($GLOBALS['posts_store'] as $post) {
            if (isset($args['post_type'])   && $post->post_type   !== $args['post_type'])   continue;
            if (isset($args['post_status']) && $post->post_status !== $args['post_status']) continue;
            if (isset($args['name'])        && $post->post_name   !== $args['name'])        continue;
            $out[] = $post;
        }
        if (isset($args['orderby']) && is_array($args['orderby']) && isset($args['orderby']['menu_order'])) {
            usort($out, function ($a, $b) {
                return [$a->menu_order, $a->ID] <=> [$b->menu_order, $b->ID];
            });
        }
        if (($args['posts_per_page'] ?? -1) > 0) $out = array_slice($out, 0, (int) $args['posts_per_page']);
        if (($args['fields'] ?? '') === 'ids') $out = array_map(function ($p) { return $p->ID; }, $out);
        return $out;
    }
}
if (!function_exists('update_post_meta')) {
    function update_post_meta($id, $key, $value) {
        $GLOBALS['post_meta'][(int) $id][$key] = $value;
        return true;
    }
}
if (!function_exists('delete_post_meta')) {
    function delete_post_meta($id, $key) {
        unset($GLOBALS['post_meta'][(int) $id][$key]);
        return true;
    }
}

/* ── Rewizje ─────────────────────────────────────────────────────────────
   Rewizje to w WordPressie ZWYKŁE WPISY typu `revision` z `post_parent`
   wskazującym rodzica — i tak samo tutaj. Trzymanie ich w osobnej tablicy
   rozjechałoby się z `get_post()`, którego `wp_get_post_revision()` używa. */
if (!function_exists('wp_get_post_revisions')) {
    function wp_get_post_revisions($id, $args = []) {
        $out = [];
        foreach ($GLOBALS['posts_store'] as $p) {
            if (($p->post_type ?? '') !== 'revision') continue;
            if ((int) ($p->post_parent ?? 0) !== (int) $id) continue;
            $out[$p->ID] = $p;
        }
        // Najnowsze pierwsze — tak samo jak w WordPressie.
        uasort($out, function ($a, $b) {
            return [$b->post_date ?? '', $b->ID] <=> [$a->post_date ?? '', $a->ID];
        });
        if (($args['posts_per_page'] ?? -1) > 0) {
            $out = array_slice($out, 0, (int) $args['posts_per_page'], true);
        }
        return $out;
    }
}
if (!function_exists('wp_get_post_revision')) {
    function wp_get_post_revision($id) {
        $p = get_post($id);
        return ($p && ($p->post_type ?? '') === 'revision') ? $p : null;
    }
}
if (!function_exists('wp_delete_post_revision')) {
    function wp_delete_post_revision($id) {
        if (!wp_get_post_revision($id)) return false;
        unset($GLOBALS['posts_store'][(int) $id]);
        return true;
    }
}
/** Zakłada rewizję. W WordPressie robi to `wp_insert_post()` sam. */
function evk_test_rewizja($rodzic, $tresc, $data, $autor = 1) {
    return wp_insert_post([
        'post_type' => 'revision', 'post_parent' => (int) $rodzic, 'post_content' => $tresc,
        'post_date' => $data, 'post_author' => $autor, 'post_status' => 'inherit',
    ]);
}
if (!function_exists('mysql2date')) {
    function mysql2date($format, $data, $translate = true) {
        $ts = strtotime((string) $data);
        return $ts ? date($format, $ts) : '';
    }
}
if (!function_exists('get_the_author_meta')) {
    function get_the_author_meta($pole, $id = 0) {
        return $GLOBALS['autorzy'][(int) $id][$pole] ?? '';
    }
}
$GLOBALS['autorzy'] = [1 => ['display_name' => 'Radek']];

function checked($a, $b = true, $echo = true) { if ($a == $b) echo ' checked'; }

// ── AJAX ────────────────────────────────────────────────────────────────
// wp_send_json_* normalnie kończą żądanie — tu rzucamy wyjątkiem, żeby test
// mógł zobaczyć odpowiedź zamiast tracić cały proces.
class EVK_Test_Json extends Exception {
    public $payload;
    public function __construct(array $payload) { $this->payload = $payload; parent::__construct('json'); }
}
function wp_send_json_success($data = null) { throw new EVK_Test_Json(['success' => true,  'data' => $data]); }
function wp_send_json_error($data = null, $code = 0) { throw new EVK_Test_Json(['success' => false, 'data' => $data]); }
// Test może chcieć wiedzieć, O JAKI nonce endpoint prosi — nazwa musi zgadzać
// się z tym, co drukuje settings_fields(). Własna nazwa oznaczałaby, że każdy
// zapis pada w produkcji, a testy dalej świecą na zielono.
if (!function_exists('check_ajax_referer')) {
    function check_ajax_referer($action, $field = false, $die = true) {
        $GLOBALS['nonce_asked'] = $action;
        return 1;
    }
}
/**
 * Uprawnienia. DOMYŚLNIE WSZYSTKO WOLNO — testy, które nie są o uprawnieniach,
 * nie mają się nimi zajmować i nie muszą nic ustawiać.
 *
 * Test od uprawnień wpisuje do $GLOBALS['caps'] tablicę „cap => true" i od tej
 * chwili atrapa odpowiada tylko na wypisane uprawnienia. Bez tego przełącznika
 * atrapa byłaby ŁAGODNIEJSZA od WordPressa: każda bramka przechodziłaby
 * w teście niezależnie od tego, o co pyta.
 */
$GLOBALS['caps'] = null;
function current_user_can($cap) {
    if ($GLOBALS['caps'] === null) return true;
    return !empty($GLOBALS['caps'][$cap]);
}
// `wp_die()` kończy żądanie. W atrapach rzuca wyjątkiem, żeby dało się odróżnić
// „handler odmówił" od „handler poszedł dalej" — inaczej odmowa wyglądałaby
// w teście dokładnie jak zgoda.
class EVK_Test_Die extends Exception {}
if (!function_exists('wp_die')) {
    function wp_die($message = '', $title = '', $args = []) { throw new EVK_Test_Die((string) $message); }
}
function wp_unslash($v) { return $v; }
function absint($v) { return abs((int) $v); }
function wp_list_pluck($list, $field) {
    return array_map(function ($row) use ($field) { return is_array($row) ? ($row[$field] ?? null) : null; }, $list);
}

/** Odpala wszystkie callbacki podpięte pod hook i zwraca wypisaną treść. */
function evk_test_fire($hook) {
    ob_start();
    foreach ($GLOBALS['hooks'][$hook] ?? [] as $cb) { $cb(); }
    return ob_get_clean();
}

/** Pierwszy callback podpięty pod hook — do filtrów, które coś zwracają. */
function evk_test_filter($hook) {
    return $GLOBALS['hooks'][$hook][0] ?? null;
}

const EVK_TEST_ROOT = __DIR__ . '/../..';
