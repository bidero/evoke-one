<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Przekierowania 301
 * Zarządzanie regułami redirect z licznikiem kliknięć, wildcards i cache transient.
 * Zintegrowane z modułem Logów 404 (pomija URLe z aktywnym redirectem).
 */

// =========================================================================
// OPCJE
// =========================================================================

function evk_301_is_enabled(): bool {
    return !empty(get_option('evk_301_enabled'));
}

// =========================================================================
// REJESTRACJA CPT
// =========================================================================

add_action('init', function () {
    register_post_type('evk_301_redirect', [
        'public'   => false,
        'show_ui'  => false,
        'supports' => ['title'],
    ]);
    register_post_type('evk_301_log', [
        'public'   => false,
        'show_ui'  => false,
        'supports' => ['title'],
    ]);
});

// =========================================================================
// HELPERY
// =========================================================================

function evk_301_normalize(string $url): string {
    $url = preg_replace('/^https?:\/\/[^\/]+/i', '', $url);
    if ($url === '' || $url[0] !== '/') $url = '/' . $url;
    if ($url !== '/' && substr($url, -1) === '/') $url = rtrim($url, '/');
    return function_exists('mb_strtolower') ? mb_strtolower($url) : strtolower($url);
}

function evk_301_get_all(): array {
    if (!is_admin()) {
        $cached = get_transient('evk_301_cache');
        if ($cached !== false) return $cached;
    }

    $posts = get_posts([
        'post_type'      => 'evk_301_redirect',
        'posts_per_page' => -1,
        'post_status'    => 'publish',
    ]);

    $redirects = [];
    foreach ($posts as $post) {
        $redirects[$post->ID] = [
            'ID'     => $post->ID,
            'from'   => get_post_meta($post->ID, 'redirect_from',   true),
            'to'     => get_post_meta($post->ID, 'redirect_to',     true),
            'clicks' => (int) get_post_meta($post->ID, 'redirect_clicks', true),
            'date'   => get_post_meta($post->ID, 'created_date',    true),
        ];
    }

    if (!is_admin()) {
        set_transient('evk_301_cache', $redirects, 12 * HOUR_IN_SECONDS);
    }
    return $redirects;
}

function evk_301_clear_cache(): void {
    delete_transient('evk_301_cache');
}

function evk_301_has_redirect(string $uri): bool {
    if (!evk_301_is_enabled()) return false;
    // Zdekodowana, jak w wykonaniu przekierowania niżej — reguły leżą zdekodowane.
    $path = evk_301_normalize(rawurldecode((string) strtok($uri, '?')));
    foreach (evk_301_get_all() as $r) {
        if (($r['from'] ?? '') === $path) return true;
    }
    return false;
}

// =========================================================================
// WYKONYWANIE PRZEKIEROWAŃ
// =========================================================================

add_action('template_redirect', function () {
    // W konserwacji zasłona stoi pod adresem, o który pytano (95-maintenance.php).
    if (is_admin() || !evk_301_is_enabled() || !empty($GLOBALS['wpm_show_maintenance'])) return;

    global $wpdb;
    $uri     = $_SERVER['REQUEST_URI'] ?? '/';
    $parsed  = parse_url($uri);
    $raw_path = evk_301_normalize(rawurldecode($parsed['path'] ?? '/'));
    $qs      = $parsed['query'] ?? '';
    $all     = evk_301_get_all();

    if (empty($all)) return;

    // Wykryj język z URL (np. /en/pricelist → lang=en, path=/pricelist)
    $current_lang = 'pl';
    $path         = $raw_path;
    if (function_exists('tl_get_languages')) {
        $langs = array_keys(tl_get_languages());
        foreach ($langs as $lang) {
            if ($lang === 'pl') continue;
            if (strpos($raw_path, '/' . $lang . '/') === 0 || $raw_path === '/' . $lang) {
                $current_lang = $lang;
                $path = '/' . ltrim(substr($raw_path, strlen('/' . $lang)), '/');
                if ($path === '/') $path = '';
                break;
            }
        }
    }

    // Exact match → wildcard match
    $exact    = array_filter($all, fn($r) => substr($r['from'] ?? '', -2) !== '/*');
    $wildcard = array_filter($all, fn($r) => substr($r['from'] ?? '', -2) === '/*');

    // Funkcja pomocnicza: dopasuj i wykonaj redirect (z obsługą języka)
    $do_redirect = function(string $from, string $to, int $id) use ($wpdb, $qs, $current_lang, $raw_path) {
        // Jeśli mamy język inny niż PL — przetłumacz from/to
        $match_path = $raw_path;
        if ($current_lang !== 'pl' && function_exists('tl_translate_url_path')) {
            $translated_from = tl_translate_url_path($from, 'pl', $current_lang);
            $lang_prefix = '/' . $current_lang;
            $match_path_without_lang = '/' . ltrim(substr($raw_path, strlen($lang_prefix)), '/');
            if (evk_301_normalize($translated_from) !== evk_301_normalize($match_path_without_lang)) {
                return false; // nie pasuje po tłumaczeniu
            }
            $to_translated = tl_translate_url_path($to, 'pl', $current_lang);
            $to = '/' . $current_lang . $to_translated;
        } else {
            // Polski — sprawdź czy pasuje
            if (evk_301_normalize($from) !== evk_301_normalize($raw_path)) {
                return false;
            }
        }

        $dest = $to;
        if ($qs) $dest .= (strpos($dest, '?') !== false ? '&' : '?') . $qs;
        if (strpos($dest, 'http') !== 0) $dest = home_url($dest);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = 'redirect_clicks'",
            $id
        ));
        evk_301_log($from, $dest);
        nocache_headers();
        wp_redirect($dest, 301);
        exit;
    };

    foreach ($exact as $r) {
        // Sprawdź dopasowanie — najpierw bezpośrednie, potem z tłumaczeniem
        $match = ($r['from'] === $path || $r['from'] === $raw_path);
        if (!$match && $current_lang !== 'pl' && function_exists('tl_translate_url_path')) {
            $translated_from = '/' . $current_lang . tl_translate_url_path($r['from'], 'pl', $current_lang);
            $match = (evk_301_normalize($translated_from) === $raw_path);
        }
        if (!$match) continue;

        $to = $r['to'];
        // Tłumacz cel jeśli inny język
        if ($current_lang !== 'pl' && function_exists('tl_translate_url_path')) {
            $to = '/' . $current_lang . tl_translate_url_path($r['to'], 'pl', $current_lang);
        }
        if ($qs) $to .= (strpos($to, '?') !== false ? '&' : '?') . $qs;
        if (strpos($to, 'http') !== 0) $to = home_url($to);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = 'redirect_clicks'",
            $r['ID']
        ));
        evk_301_log($r['from'], $to);
        nocache_headers();
        wp_redirect($to, 301);
        exit;
    }

    // Stara logika wildcard (bez tłumaczenia na razie)
    foreach ($wildcard as $r) {
        $base_from = substr($r['from'], 0, -2);
        if ($path !== $base_from && strpos($path, $base_from . '/') !== 0) continue;

        $leftover = ltrim(substr($path, strlen($base_from)), '/');
        if (strpos($leftover, '..') !== false) continue;

        $base_to = substr($r['to'], -2) === '/*' ? substr($r['to'], 0, -2) : $r['to'];
        $dest    = rtrim($base_to, '/') . ($leftover ? '/' . $leftover : '');
        if ($qs) $dest .= (strpos($dest, '?') !== false ? '&' : '?') . $qs;
        if (strpos($dest, 'http') !== 0) $dest = home_url($dest);

        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = 'redirect_clicks'",
            $r['ID']
        ));
        evk_301_log($r['from'], $dest);
        nocache_headers();
        wp_redirect($dest, 301);
        exit;
    }
}, 0);

function evk_301_log(string $from, string $to): void {
    $max = 500;
    global $wpdb;
    $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='evk_301_log'");
    if ($count >= $max) {
        $old = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type='evk_301_log' ORDER BY post_date ASC LIMIT 1");
        if ($old) {
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE post_id={$old[0]}");
            $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID={$old[0]}");
        }
    }
    wp_insert_post([
        'post_type'   => 'evk_301_log',
        'post_status' => 'publish',
        'post_title'  => $from . ' → ' . $to,
        'meta_input'  => ['from' => $from, 'to' => $to, 'logged_at' => current_time('mysql')],
    ]);
}

// =========================================================================
// AJAX — zarządzanie regułami
// =========================================================================

/**
 * Cel przekierowania z pola formularza: adres bez protokołu i bez ukośnika
 * („nowa-strona") to ścieżka w serwisie. Do 1.233.5 szedł prosto do
 * sanitize_url(), który dokleja „http://" — i przekierowanie prowadziło na
 * nieistniejący host „http://nowa-strona".
 */
function evk_301_cel(string $to): string {
    $to = trim($to);
    if ($to !== '' && $to[0] !== '/' && !preg_match('#^[a-z][a-z0-9+.-]*:#i', $to)) $to = '/' . $to;
    return (string) sanitize_url($to);
}

/**
 * Nowe przekierowanie albo nowy cel istniejącego z tym samym „Z" (bez
 * duplikatów). Zwraca ID reguły albo WP_Error. Wspólne dla ekranu
 * przekierowań i przycisku „Przekieruj" w logach 404 (1.234.0).
 *
 * @return int|WP_Error
 */
function evk_301_dodaj(string $from, string $to) {
    $from = evk_301_normalize(sanitize_text_field($from));
    $to   = evk_301_cel($to);
    if ($from === '' || $to === '') return new WP_Error('evk_301_puste', 'Podaj oba pola.');
    if ($to[0] === '/' && strpos($to, '//') !== 0 && evk_301_normalize(rawurldecode((string) strtok($to, '?'))) === $from) {
        return new WP_Error('evk_301_petla', 'Adres docelowy to ten sam adres — przekierowanie kręciłoby się w kółko.');
    }
    foreach (evk_301_get_all() as $r) {
        if (($r['from'] ?? '') === $from) {
            update_post_meta((int) $r['ID'], 'redirect_to', $to);
            evk_301_clear_cache();
            return (int) $r['ID'];
        }
    }
    $pid = wp_insert_post([
        'post_type'   => 'evk_301_redirect',
        'post_status' => 'publish',
        'post_title'  => $from,
        'meta_input'  => [
            'redirect_from'   => $from,
            'redirect_to'     => $to,
            'redirect_clicks' => 0,
            'created_date'    => current_time('mysql'),
        ],
    ], true);
    if (is_wp_error($pid)) return $pid;
    evk_301_clear_cache();
    return (int) $pid;
}

add_action('wp_ajax_evk_301_save', function () {
    check_ajax_referer('evk_tools_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();

    $id = absint($_POST['id'] ?? 0);
    if ($id) {
        $from = evk_301_normalize(sanitize_text_field(wp_unslash($_POST['from'] ?? '')));
        $to   = evk_301_cel((string) wp_unslash($_POST['to'] ?? ''));
        if (empty($from) || empty($to)) wp_send_json_error('Podaj oba pola.');
        update_post_meta($id, 'redirect_from', $from);
        update_post_meta($id, 'redirect_to',   $to);
        evk_301_clear_cache();
        wp_send_json_success();
    }

    $wynik = evk_301_dodaj((string) wp_unslash($_POST['from'] ?? ''), (string) wp_unslash($_POST['to'] ?? ''));
    if (is_wp_error($wynik)) wp_send_json_error($wynik->get_error_message());
    wp_send_json_success();
});

add_action('wp_ajax_evk_301_delete', function () {
    check_ajax_referer('evk_tools_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    $id = absint($_POST['id'] ?? 0);
    if ($id) wp_delete_post($id, true);
    evk_301_clear_cache();
    wp_send_json_success();
});

add_action('wp_ajax_evk_301_clear_logs', function () {
    check_ajax_referer('evk_tools_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    global $wpdb;
    $wpdb->query("DELETE pm FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID WHERE p.post_type = 'evk_301_log'");
    $wpdb->query("DELETE FROM {$wpdb->posts} WHERE post_type = 'evk_301_log'");
    wp_send_json_success();
});
