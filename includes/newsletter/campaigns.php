<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — Kampanie i szablony
 */

// =========================================================================
// SZABLONY
// =========================================================================

function evk_nl_get_templates(): array {
    global $wpdb;
    $t = evk_nl_table('templates');
    return $wpdb->get_results("SELECT * FROM $t ORDER BY name ASC", ARRAY_A) ?: [];
}

function evk_nl_get_template(int $id): ?array {
    global $wpdb;
    $t   = evk_nl_table('templates');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);
    return $row ?: null;
}

function evk_nl_create_template(array $data): int|false {
    global $wpdb;
    $wpdb->insert(evk_nl_table('templates'), [
        'name'             => sanitize_text_field($data['name'] ?? ''),
        'subject'          => sanitize_text_field($data['subject'] ?? ''),
        'body_html'        => wp_kses_post($data['body_html'] ?? ''),
        'attachments_json' => wp_json_encode(array_map('intval', (array) ($data['attachments'] ?? []))),
    ]);
    return $wpdb->insert_id ?: false;
}

function evk_nl_update_template(int $id, array $data): bool {
    global $wpdb;
    $clean = [];
    if (isset($data['name']))        $clean['name']             = sanitize_text_field($data['name']);
    if (isset($data['subject']))     $clean['subject']          = sanitize_text_field($data['subject']);
    if (isset($data['body_html']))   $clean['body_html']        = wp_kses_post($data['body_html']);
    if (isset($data['attachments'])) $clean['attachments_json'] = wp_json_encode(array_map('intval', (array) $data['attachments']));
    if (empty($clean)) return false;
    return (bool) $wpdb->update(evk_nl_table('templates'), $clean, ['id' => $id]);
}

function evk_nl_delete_template(int $id): bool {
    global $wpdb;
    return (bool) $wpdb->delete(evk_nl_table('templates'), ['id' => $id]);
}

// =========================================================================
// KAMPANIE
// =========================================================================

function evk_nl_get_campaigns(): array {
    global $wpdb;
    $t = evk_nl_table('campaigns');
    return $wpdb->get_results("SELECT * FROM $t ORDER BY created_at DESC", ARRAY_A) ?: [];
}

function evk_nl_get_campaign(int $id): ?array {
    global $wpdb;
    $t   = evk_nl_table('campaigns');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);
    return $row ?: null;
}

function evk_nl_create_campaign(array $data): int|false {
    global $wpdb;
    $wpdb->insert(evk_nl_table('campaigns'), [
        'name'             => sanitize_text_field($data['name'] ?? ''),
        'template_id'      => (int) ($data['template_id'] ?? 0),
        'lists_json'       => wp_json_encode(array_map('intval', (array) ($data['lists'] ?? []))),
        'status'           => 'draft',
        'scheduled_at'     => !empty($data['scheduled_at']) ? sanitize_text_field($data['scheduled_at']) : null,
        'batch_size'       => max(1, (int) ($data['batch_size'] ?? 50)),
        'batch_interval'   => max(1, (int) ($data['batch_interval'] ?? 5)),
        'tracking_enabled' => !empty($data['tracking_enabled']) ? 1 : 0,
    ]);
    return $wpdb->insert_id ?: false;
}

function evk_nl_update_campaign(int $id, array $data): bool {
    global $wpdb;
    $clean = [];
    if (isset($data['name']))             $clean['name']             = sanitize_text_field($data['name']);
    if (isset($data['template_id']))      $clean['template_id']      = (int) $data['template_id'];
    if (isset($data['lists']))            $clean['lists_json']        = wp_json_encode(array_map('intval', (array) $data['lists']));
    if (isset($data['status']))           $clean['status']           = sanitize_key($data['status']);
    if (isset($data['scheduled_at']))     $clean['scheduled_at']     = $data['scheduled_at'] ?: null;
    if (isset($data['batch_size']))       $clean['batch_size']       = max(1, (int) $data['batch_size']);
    if (isset($data['batch_interval']))   $clean['batch_interval']   = max(1, (int) $data['batch_interval']);
    if (isset($data['tracking_enabled'])) $clean['tracking_enabled'] = !empty($data['tracking_enabled']) ? 1 : 0;
    if (empty($clean)) return false;
    return (bool) $wpdb->update(evk_nl_table('campaigns'), $clean, ['id' => $id]);
}

function evk_nl_delete_campaign(int $id): bool {
    global $wpdb;
    // Usuń kolejkę, logi i stan backoffu
    $wpdb->delete(evk_nl_table('queue'), ['campaign_id' => $id]);
    $wpdb->delete(evk_nl_table('logs'),  ['campaign_id' => $id]);
    evk_nl_backoff_reset($id);
    return (bool) $wpdb->delete(evk_nl_table('campaigns'), ['id' => $id]);
}

// =========================================================================
// STATYSTYKI KAMPANII
// =========================================================================

function evk_nl_campaign_stats(int $campaign_id): array {
    global $wpdb;
    $q = evk_nl_table('queue');
    $l = evk_nl_table('logs');

    $total   = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d", $campaign_id));
    $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status='pending'", $campaign_id));
    $failed  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status='failed'", $campaign_id));

    // Wysłane = wszystkie ze statusem innym niż pending/failed
    $sent    = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND status IN ('sent','opened','clicked')",
        $campaign_id
    ));

    // Unikalne otwarcia (po subscriber_id, nie po logach)
    $opened  = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $q WHERE campaign_id=%d AND opened_at IS NOT NULL",
        $campaign_id
    ));

    // Unikalne kliknięcia (unikalne subscriber_id w logach)
    $clicked = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT subscriber_id) FROM $l WHERE campaign_id=%d AND event='click'",
        $campaign_id
    ));

    // Unikalne wypisy
    $unsubs  = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(DISTINCT subscriber_id) FROM $l WHERE campaign_id=%d AND event='unsubscribe'",
        $campaign_id
    ));

    return compact('total', 'sent', 'failed', 'opened', 'clicked', 'unsubs', 'pending');
}

// =========================================================================
// LOGI
// =========================================================================

function evk_nl_log(int $campaign_id, string $event, ?int $subscriber_id = null, array $data = []): void {
    global $wpdb;
    $wpdb->insert(evk_nl_table('logs'), [
        'campaign_id'   => $campaign_id,
        'event'         => $event,
        'subscriber_id' => $subscriber_id,
        'data_json'     => !empty($data) ? wp_json_encode($data) : null,
    ]);
}

function evk_nl_get_logs(int $campaign_id, string $event = '', int $limit = 100, int $offset = 0): array {
    global $wpdb;
    $t     = evk_nl_table('logs');
    $where = $wpdb->prepare("WHERE campaign_id=%d", $campaign_id);
    if ($event !== '') $where .= $wpdb->prepare(" AND event=%s", $event);
    return $wpdb->get_results(
        "SELECT * FROM $t $where ORDER BY created_at DESC LIMIT $limit OFFSET $offset",
        ARRAY_A
    ) ?: [];
}

// =========================================================================
// KOLEJKA PER-SUBSKRYBENT (status wysyłki)
// =========================================================================

function evk_nl_campaign_queue(int $campaign_id, string $status = '', int $page = 1, int $per = 50): array {
    global $wpdb;
    $q = evk_nl_table('queue');
    $s = evk_nl_table('subscribers');
    $page = max(1, $page);
    $per  = max(1, min(200, $per));
    $off  = ($page - 1) * $per;

    $where = 'q.campaign_id = %d';
    $args  = [$campaign_id];
    if ($status !== '' && in_array($status, ['pending', 'sent', 'opened', 'clicked', 'failed', 'cancelled'], true)) {
        $where .= ' AND q.status = %s';
        $args[] = $status;
    }

    $total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $q q WHERE $where", $args));
    $rows  = $wpdb->get_results($wpdb->prepare(
        "SELECT q.status, q.attempts, q.sent_at, q.opened_at, q.error_message, s.email
         FROM $q q LEFT JOIN $s s ON s.id = q.subscriber_id
         WHERE $where ORDER BY q.id ASC LIMIT %d OFFSET %d",
        array_merge($args, [$per, $off])
    ), ARRAY_A) ?: [];

    return ['rows' => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'pages' => (int) ceil($total / $per)];
}
