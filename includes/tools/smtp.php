<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — SMTP i logi maili
 * Konfiguracja SMTP przez phpmailer_init, test wysyłki, log ostatnich maili.
 */

// =========================================================================
// OPCJE
// =========================================================================

function evk_smtp_get(): array {
    return wp_parse_args((array) get_option('evk_smtp', []), [
        'enabled'      => 0,
        'host'         => '',
        'port'         => 587,
        'encryption'   => 'tls',
        'username'     => '',
        'password'     => '',
        'from_email'   => '',
        'from_name'    => '',
        'log_enabled'  => 1,
        'log_max'      => 100,
    ]);
}

add_action('admin_init', function () {
    register_setting('evk_smtp_settings', 'evk_smtp', [
        'sanitize_callback' => function ($input) {
            $input = is_array($input) ? $input : [];
            // Hasło: jeśli puste — zachowaj stare
            $old = evk_smtp_get();
            return [
                'enabled'     => evk_preserve_toggle($input, 'evk_smtp'),
                'host'        => sanitize_text_field($input['host']       ?? ''),
                'port'        => absint($input['port']                    ?? 587),
                'encryption'  => in_array($input['encryption'] ?? '', ['tls','ssl','none']) ? $input['encryption'] : 'tls',
                'username'    => sanitize_text_field($input['username']   ?? ''),
                'password'    => !empty($input['password']) ? $input['password'] : $old['password'],
                'from_email'  => sanitize_email($input['from_email']      ?? ''),
                'from_name'   => sanitize_text_field($input['from_name']  ?? ''),
                'log_enabled' => !empty($input['log_enabled']) ? 1 : 0,
                'log_max'     => max(10, absint($input['log_max']         ?? 100)),
            ];
        },
    ]);
});

// =========================================================================
// PHPMAILER — konfiguracja SMTP
// =========================================================================

add_action('phpmailer_init', function ($phpmailer) {
    $s = evk_smtp_get();
    if (empty($s['enabled'])) return;

    $phpmailer->isSMTP();
    $phpmailer->Host       = $s['host'];
    $phpmailer->SMTPAuth   = true;
    $phpmailer->Port       = (int) $s['port'];
    $phpmailer->Username   = $s['username'];
    $phpmailer->Password   = $s['password'];
    $phpmailer->SMTPSecure = ($s['encryption'] !== 'none') ? $s['encryption'] : '';
    $phpmailer->Timeout    = 10;
    $phpmailer->Sender     = ''; // Fix WP 6.9

    $from = !empty($s['from_email']) ? $s['from_email']
          : (filter_var($s['username'], FILTER_VALIDATE_EMAIL) ? $s['username'] : '');
    if ($from) $phpmailer->From = $from;
    $phpmailer->FromName = !empty($s['from_name']) ? $s['from_name'] : get_bloginfo('name');
});

// =========================================================================
// LOG MAILI — przechwytuj każdą wysyłkę
// =========================================================================

add_action('wp_mail', function (array $args): array {
    $s = evk_smtp_get();
    if (empty($s['log_enabled'])) return $args;

    // Zapisz dane maila do przetworzenia po wysyłce
    add_filter('wp_mail_succeeded', function ($mail_data) use ($args) {
        evk_smtp_log_mail($args, true);
        return $mail_data;
    });
    add_action('wp_mail_failed', function ($error) use ($args) {
        evk_smtp_log_mail($args, false, $error->get_error_message());
    });

    return $args;
}, 10, 1);

function evk_smtp_log_mail(array $args, bool $success, string $error = ''): void {
    $s   = evk_smtp_get();
    $max = (int) ($s['log_max'] ?? 100);
    $log = (array) get_option('evk_smtp_log', []);

    array_unshift($log, [
        'time'    => current_time('mysql'),
        'to'      => is_array($args['to']) ? implode(', ', $args['to']) : ($args['to'] ?? ''),
        'subject' => $args['subject'] ?? '',
        'success' => $success,
        'error'   => $error,
    ]);

    if (count($log) > $max) $log = array_slice($log, 0, $max);
    update_option('evk_smtp_log', $log);
}

// =========================================================================
// AJAX — test wysyłki
// =========================================================================

add_action('wp_ajax_evk_smtp_test', function () {
    check_ajax_referer('evk_tools_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();

    $to   = sanitize_email($_POST['email'] ?? get_option('admin_email'));
    $sent = wp_mail($to, 'Test SMTP — Evoke ONE', 'Ten email potwierdza poprawną konfigurację SMTP.');
    wp_send_json(['success' => $sent, 'to' => $to]);
});

add_action('wp_ajax_evk_smtp_clear_log', function () {
    check_ajax_referer('evk_tools_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error();
    delete_option('evk_smtp_log');
    wp_send_json_success();
});
