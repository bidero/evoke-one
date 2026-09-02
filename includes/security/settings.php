<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Security: Rejestracja ustawień i helpery
 * Wspólna baza dla wszystkich modułów security.
 */

add_action('admin_init', function () {
    register_setting('evoke_one_security', 'evk_security', [
        'sanitize_callback' => 'evk_security_sanitize',
        'default'           => [],
    ]);
});

/**
 * Komunikat blokady logowania — jedna lista dla obu dróg zapisu.
 *
 * Do 1.132.0 zapis przez `register_setting()` przepuszczał go przez
 * `wp_kses_post()` (lista dla treści wpisów, z atrybutami `style` na wielu
 * znacznikach), a zapis przez AJAX przez własną, wąską listę. Ten sam tekst
 * dostawał więc różne sita zależnie od tego, którym przyciskiem go zapisano —
 * a wyświetla się na PUBLICZNEJ stronie logowania.
 */
function evk_security_sanitize_message($tekst): string {
    return wp_kses((string) $tekst, [
        'strong' => [], 'em' => [], 'br' => [],
        'a'      => ['href' => [], 'title' => []],
        'p'      => [], 'span' => [],
    ]);
}

function evk_security_sanitize($input): array {
    $input = is_array($input) ? $input : [];
    return [
        'limit_login_enabled'     => !empty($input['limit_login_enabled'])     ? 1 : 0,
        'max_attempts'            => max(1, min(100, absint($input['max_attempts']   ?? 5))),
        'reset_hours'             => max(1, min(720, absint($input['reset_hours']    ?? 24))),
        'limit_login_message'     => evk_security_sanitize_message($input['limit_login_message'] ?? ''),
        'hide_wp_version'         => !empty($input['hide_wp_version'])         ? 1 : 0,
        'disable_bundled_themes'  => !empty($input['disable_bundled_themes'])  ? 1 : 0,
        'rest_block_all'          => !empty($input['rest_block_all'])          ? 1 : 0,
        'disabled_rest_endpoints' => isset($input['disabled_rest_endpoints']) && is_array($input['disabled_rest_endpoints'])
            ? array_map('sanitize_text_field', $input['disabled_rest_endpoints'])
            : [],
    ];
}

function evk_security_get(): array {
    return wp_parse_args((array) get_option('evk_security', []), [
        'limit_login_enabled'     => 0,
        'max_attempts'            => 5,
        'reset_hours'             => 24,
        'limit_login_message'     => '',
        'hide_wp_version'         => 0,
        'disable_bundled_themes'  => 0,
        'rest_block_all'          => 0,
        'disabled_rest_endpoints' => [],
    ]);
}

// =========================================================================
// AJAX SAVE — merge z istniejącymi ustawieniami
// =========================================================================

add_action('wp_ajax_evk_save_security_section', function () {
    check_ajax_referer('evk_security_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.');

    $section = sanitize_key($_POST['section'] ?? '');
    $raw     = $_POST['data'] ?? [];
    if (!is_array($raw)) $raw = [];

    // Pobierz aktualne ustawienia
    $current = evk_security_get();

    // Merge tylko pól należących do danej sekcji
    $merged = array_merge($current, evk_security_sanitize_section($section, $raw));

    update_option('evk_security', $merged);
    wp_send_json_success(['saved' => $section]);
});

function evk_security_sanitize_section(string $section, array $raw): array {
    switch ($section) {
        case 'login':
            return [
                'limit_login_enabled' => !empty($raw['limit_login_enabled']) ? 1 : 0,
                'max_attempts'        => max(1, min(100, absint($raw['max_attempts'] ?? 5))),
                'reset_hours'         => max(1, min(720, absint($raw['reset_hours'] ?? 24))),
                'limit_login_message' => evk_security_sanitize_message($raw['limit_login_message'] ?? ''),
            ];
        case 'rest':
            return [
                'rest_block_all'          => !empty($raw['rest_block_all']) ? 1 : 0,
                'disabled_rest_endpoints' => isset($raw['disabled_rest_endpoints']) && is_array($raw['disabled_rest_endpoints'])
                    ? array_map('sanitize_text_field', $raw['disabled_rest_endpoints'])
                    : [],
            ];
        case 'hardening':
            return [
                'hide_wp_version'        => !empty($raw['hide_wp_version']) ? 1 : 0,
                'disable_bundled_themes' => !empty($raw['disable_bundled_themes']) ? 1 : 0,
            ];
        default:
            return [];
    }
}
