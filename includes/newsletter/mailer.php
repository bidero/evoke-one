<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — Mailer
 * Wysyłanie maili przez konfigurację evk_smtp (PHPMailer).
 */

function evk_nl_send_mail(array $subscriber, array $campaign, array $template, array $queue_row) {
    $smtp = evk_smtp_get();
    if (empty($smtp['enabled'])) {
        return new WP_Error('smtp_disabled', 'SMTP Evoke ONE jest wyłączony.');
    }

    $fields    = json_decode($subscriber['fields_json'] ?? '{}', true) ?: [];
    $unsub_url = evk_nl_unsubscribe_url($subscriber['token']);

    $view_url = evk_nl_view_url((int) $campaign['id'], $subscriber['token']);

    $merge = array_merge([
        '{email}'            => $subscriber['email'],
        '{unsubscribe_url}'  => $unsub_url,
        '{site_name}'        => get_bloginfo('name'),
        '{site_url}'         => preg_replace('#^https?://#', '', home_url()),
        '{site_url_full}'     => home_url(),
        '{unsubscribe_url_plain}' => preg_replace('#^https?://#', '', $unsub_url),
        '{view_in_browser}'  => '<a href="' . esc_url($view_url) . '" style="color:#64748b;font-size:12px;">Zobacz w przeglądarce</a>',
        '{view_url}'         => $view_url,
        '{view_url_plain}'   => preg_replace('#^https?://#', '', $view_url),
    ], evk_nl_fields_to_merge_tags($fields));

    $subject = evk_nl_replace_merge_tags($template['subject'], $merge);
    $body    = evk_nl_replace_merge_tags($template['body_html'], $merge);

    // Załączniki PDF — dodaj linki w treści maila
    $attachment_ids = json_decode($template['attachments_json'] ?? '[]', true) ?: [];
    if (!empty($attachment_ids)) {
        $body = evk_nl_append_attachment_links($body, $attachment_ids, $subscriber['token'], !empty($campaign['tracking_enabled']), (int) $campaign['id']);
    }

    if (!empty($campaign['tracking_enabled'])) {
        $body = evk_nl_inject_tracking($body, $subscriber['token'], (int) $campaign['id']);
    }

    return evk_nl_phpmailer_send([
        'to'          => $subscriber['email'],
        'subject'     => $subject,
        'body'        => $body,
        'smtp'        => $smtp,
        'attachments' => $attachment_ids,
        'unsub_url'   => $unsub_url,
    ]);
}

/**
 * Współdzielona instancja PHPMailer z SMTPKeepAlive — jedno połączenie SMTP
 * na całą paczkę zamiast osobnego na każdy mail (mniejsze ryzyko limitów
 * połączeń u dostawcy). Zamykana przez evk_nl_mailer_close() po paczce;
 * przy pojedynczych mailach (np. potwierdzenie zapisu) zamyka ją destruktor.
 */
function evk_nl_mailer_instance(?array $smtp, bool $close = false): ?PHPMailer\PHPMailer\PHPMailer {
    static $mailer = null;
    static $sig    = '';

    if ($close) {
        if ($mailer instanceof PHPMailer\PHPMailer\PHPMailer) {
            $mailer->smtpClose();
            $mailer = null;
            $sig    = '';
        }
        return null;
    }

    $new_sig = md5(wp_json_encode([$smtp['host'], $smtp['port'], $smtp['username'], $smtp['encryption']]));
    if ($mailer instanceof PHPMailer\PHPMailer\PHPMailer && $sig === $new_sig) {
        return $mailer;
    }
    if ($mailer instanceof PHPMailer\PHPMailer\PHPMailer) {
        $mailer->smtpClose();
    }

    require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
    require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
    require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';

    $mailer = new PHPMailer\PHPMailer\PHPMailer(true);
    $mailer->isSMTP();
    $mailer->SMTPKeepAlive = true;
    $mailer->XMailer    = ' '; // nie ujawniaj biblioteki (jak Gmail)
    $mailer->Host       = $smtp['host'];
    $mailer->SMTPAuth   = true;
    $mailer->Port       = (int) $smtp['port'];
    $mailer->Username   = $smtp['username'];
    $mailer->Password   = $smtp['password'];
    $mailer->SMTPSecure = ($smtp['encryption'] !== 'none') ? $smtp['encryption'] : '';
    $mailer->Timeout    = 15;
    $mailer->CharSet    = PHPMailer\PHPMailer\PHPMailer::CHARSET_UTF8;
    $mailer->Encoding   = PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64;

    $sig = $new_sig;
    return $mailer;
}

function evk_nl_mailer_close(): void {
    evk_nl_mailer_instance(null, true);
}

function evk_nl_phpmailer_send(array $args) {
    $smtp   = $args['smtp'];
    $mailer = evk_nl_mailer_instance($smtp);

    try {
        // Wyczyść stan po poprzednim mailu z tej samej paczki
        $mailer->clearAllRecipients();
        $mailer->clearAttachments();
        $mailer->clearCustomHeaders();
        $mailer->clearReplyTos();

        $from_email = !empty($smtp['from_email']) ? $smtp['from_email']
            : (filter_var($smtp['username'], FILTER_VALIDATE_EMAIL) ? $smtp['username'] : get_option('admin_email'));
        $from_name  = !empty($smtp['from_name']) ? $smtp['from_name'] : get_bloginfo('name');

        $mailer->setFrom($from_email, $from_name);
        $mailer->addReplyTo($from_email, $from_name);
        $mailer->addAddress($args['to']);

        // List-Unsubscribe + one-click (wymogi Gmail/Yahoo dla masowej wysylki)
        if (!empty($args['unsub_url'])) {
            $nl_opts = get_option('evk_newsletter', []);
            $mailto  = trim((string) ($nl_opts['unsub_mailto'] ?? ''));
            $lu = ($mailto !== '' && is_email($mailto))
                ? '<mailto:' . $mailto . '?subject=unsubscribe>, <' . $args['unsub_url'] . '>'
                : '<' . $args['unsub_url'] . '>';
            $mailer->addCustomHeader('List-Unsubscribe', $lu);
            $mailer->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        }

        $mailer->isHTML(true);
        $mailer->Subject = $args['subject'];
        $mailer->Body    = $args['body'];

        // HTML → plain text
        $alt = $args['body'];
        $alt = preg_replace('/<br\s*\/?>\s*/i', "\n", $alt);
        $alt = preg_replace('/<\/p>\s*/i', "\n\n", $alt);
        $alt = preg_replace('/<\/tr>\s*/i', "\n", $alt);
        $alt = preg_replace('/<\/td>\s*/i', "\t", $alt);
        $alt = html_entity_decode(wp_strip_all_tags($alt), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $alt = preg_replace("/\n{3,}/", "\n\n", trim($alt));
        $mailer->AltBody = $alt;

        foreach ((array) ($args['attachments'] ?? []) as $attachment_id) {
            $file_path = get_attached_file((int) $attachment_id);
            if ($file_path && file_exists($file_path)) {
                $mailer->addAttachment($file_path, basename($file_path));
            }
        }

        $mailer->send();
        return true;

    } catch (\Throwable $e) {
        // "data not accepted" itp. to ogólne komunikaty PHPMailera — dopisz
        // faktyczną odpowiedź serwera SMTP (kod + powód, np. limit wysyłki)
        $msg  = trim($e->getMessage());
        $conn = $mailer->getSMTPInstance();
        if ($conn) {
            $err   = $conn->getError();
            $extra = trim((string) ($err['detail'] ?? ''));
            if ($extra === '') $extra = trim((string) ($err['error'] ?? ''));
            if ($extra === '') $extra = trim((string) $conn->getLastReply());
            if ($extra !== '' && stripos($msg, $extra) === false) {
                $msg .= ' | Odpowiedź serwera: ' . $extra;
            }
        }
        // Po błędzie połączenie może być w złym stanie — zamknij,
        // kolejny mail otworzy świeże
        $mailer->smtpClose();
        return new WP_Error('mail_error', $msg);
    }
}

// =========================================================================
// MERGE TAGI
// =========================================================================

function evk_nl_fields_to_merge_tags(array $fields): array {
    $tags = [];
    foreach ($fields as $key => $val) {
        $tags['{' . sanitize_key($key) . '}'] = (string) $val;
    }
    return $tags;
}

function evk_nl_replace_merge_tags(string $text, array $merge): string {
    return str_replace(array_keys($merge), array_values($merge), $text);
}

// =========================================================================
// LINKI DO ZAŁĄCZNIKÓW W TREŚCI
// =========================================================================

function evk_nl_append_attachment_links(string $body, array $attachment_ids, string $token, bool $track = true, int $campaign_id = 0): string {
    $links = [];

    foreach ($attachment_ids as $att_id) {
        $att_id  = (int) $att_id;
        $url     = wp_get_attachment_url($att_id);
        $name    = get_the_title($att_id) ?: basename(get_attached_file($att_id) ?: '');
        $mime    = get_post_mime_type($att_id) ?: '';

        if (!$url) continue;

        // Ikona wg typu MIME
        $icon = match (true) {
            str_contains($mime, 'pdf')        => '📄',
            str_contains($mime, 'word')       => '📝',
            str_contains($mime, 'excel')      => '📊',
            str_contains($mime, 'spreadsheet')=> '📊',
            str_contains($mime, 'zip')        => '🗜',
            str_contains($mime, 'image')      => '🖼',
            default                           => '📎',
        };

        // Przez tracker kliknięć jeśli tracking włączony
        $href = $track ? evk_nl_click_url($token, $url, $campaign_id) : $url;

        $links[] = '<a href="' . esc_url($href) . '" '
                 . 'style="display:inline-block;margin:4px 8px 4px 0;padding:8px 16px;'
                 . 'background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;'
                 . 'color:#2563eb;text-decoration:none;font-size:13px;font-family:sans-serif;">'
                 . $icon . ' ' . esc_html($name)
                 . '</a>';
    }

    if (empty($links)) return $body;

    $block = '<div style="margin:24px 0 8px;padding:16px;background:#f8fafc;'
           . 'border:1px solid #e2e8f0;border-radius:8px;font-family:sans-serif;">'
           . '<p style="margin:0 0 10px;font-size:12px;color:#64748b;font-weight:600;'
           . 'text-transform:uppercase;letter-spacing:.05em;">Załączniki</p>'
           . implode('', $links)
           . '</div>';

    // Wstaw przed </body> jeśli jest, wpp na końcu
    if (stripos($body, '</body>') !== false) {
        return str_ireplace('</body>', $block . '</body>', $body);
    }

    return $body . $block;
}

// =========================================================================
// TRACKING
// =========================================================================

function evk_nl_inject_tracking(string $body, string $token, int $campaign_id): string {
    $body = preg_replace_callback(
        '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>/i',
        function ($m) use ($token, $campaign_id) {
            $url = $m[1];

            // Napraw podwójny protokół (TinyMCE bug)
            $url = preg_replace('#^https?://https?://#i', 'https://', $url);
            $url = preg_replace('#^https?://http://#i', 'http://', $url);

            if (
                strpos($url, '#') === 0 ||
                strpos($url, 'mailto:') === 0 ||
                strpos($url, '/nl/click/') !== false ||
                strpos($url, '/nl/unsub/') !== false ||
                strpos($url, '/nl/open/') !== false ||
                !wp_http_validate_url($url)
            ) {
                return $m[0];
            }

            $track_url = evk_nl_click_url($token, $url, $campaign_id);
            return str_replace($m[1], $track_url, $m[0]);
        },
        $body
    );

    $pixel_url = evk_nl_open_url($token, $campaign_id);
    $pixel     = '<img src="' . esc_url($pixel_url) . '" width="1" height="1" border="0" alt="" '
               . 'style="display:block;width:1px;height:1px;max-width:1px;max-height:1px;'
               . 'margin:0;padding:0;line-height:0;border:none;" />';

    if (stripos($body, '<body') !== false) {
        $body = preg_replace('/(<body[^>]*>)/i', '$1' . $pixel, $body, 1);
    } else {
        $body .= $pixel;
    }

    return $body;
}

// =========================================================================
// SPRAWDZENIE SMTP
// =========================================================================

function evk_nl_smtp_is_configured(): bool {
    $s = evk_smtp_get();
    return !empty($s['enabled']) && !empty($s['host']) && !empty($s['username']);
}
