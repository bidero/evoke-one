<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — AJAX handlery
 */

// =========================================================================
// HELPER
// =========================================================================

function evk_nl_ajax_check(): void {
    check_ajax_referer('evk_nl_nonce', 'nonce');
    // Dostęp jak strona Newsletter (menu.php): admin LUB rola z evk_access_newsletter (Role Manager)
    if (!current_user_can('manage_options') && !current_user_can('evk_access_newsletter')) {
        wp_send_json_error(['msg' => 'Brak uprawnień.'], 403);
    }
}

// =========================================================================
// LISTY
// =========================================================================

add_action('wp_ajax_evk_nl_save_list', function () {
    evk_nl_ajax_check();
    $id     = (int) ($_POST['id'] ?? 0);
    $name   = sanitize_text_field($_POST['name'] ?? '');
    $fields = json_decode(stripslashes($_POST['fields_config'] ?? '[]'), true) ?: [];

    if (empty($name)) wp_send_json_error(['msg' => 'Nazwa listy jest wymagana.']);

    if ($id > 0) {
        $ok = evk_nl_update_list($id, ['name' => $name, 'fields_config' => $fields]);
        wp_send_json($ok ? ['success' => true] : ['success' => false, 'msg' => 'Błąd aktualizacji.']);
    } else {
        $new_id = evk_nl_create_list($name, $fields);
        $new_id ? wp_send_json_success(['id' => $new_id]) : wp_send_json_error(['msg' => 'Błąd tworzenia listy.']);
    }
});

add_action('wp_ajax_evk_nl_delete_list', function () {
    evk_nl_ajax_check();
    $id = (int) ($_POST['id'] ?? 0);
    wp_send_json(evk_nl_delete_list($id) ? ['success' => true] : ['success' => false, 'msg' => 'Błąd usuwania.']);
});

add_action('wp_ajax_evk_nl_toggle_list', function () {
    evk_nl_ajax_check();
    $id     = (int) ($_POST['id'] ?? 0);
    $status = (int) ($_POST['status'] ?? 1);
    wp_send_json(evk_nl_update_list($id, ['status' => $status]) ? ['success' => true] : ['success' => false]);
});

// =========================================================================
// IMPORT SUBSKRYBENTÓW
// =========================================================================

add_action('wp_ajax_evk_nl_import_subscribers', function () {
    evk_nl_ajax_check();
    $list_id = (int) ($_POST['list_id'] ?? 0);
    $type    = sanitize_key($_POST['import_type'] ?? 'textarea');
    $raw     = stripslashes($_POST['content'] ?? '');

    if (!$list_id) wp_send_json_error(['msg' => 'Brak ID listy.']);

    $emails = [];
    if ($type === 'csv') {
        $emails = evk_nl_parse_csv($raw);
    } else {
        $emails = evk_nl_parse_textarea($raw);
    }

    if (empty($emails)) wp_send_json_error(['msg' => 'Nie znaleziono żadnych adresów email.']);

    $result = evk_nl_import_emails($list_id, $emails);
    wp_send_json_success($result);
});

add_action('wp_ajax_evk_nl_import_csv_file', function () {
    evk_nl_ajax_check();
    $list_id = (int) ($_POST['list_id'] ?? 0);
    if (!$list_id) wp_send_json_error(['msg' => 'Brak ID listy.']);

    if (empty($_FILES['csv_file']['tmp_name'])) wp_send_json_error(['msg' => 'Brak pliku.']);

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
    $content = file_get_contents($_FILES['csv_file']['tmp_name']);
    $emails  = evk_nl_parse_csv($content);
    if (empty($emails)) wp_send_json_error(['msg' => 'Nie znaleziono emaili w pliku.']);

    $result = evk_nl_import_emails($list_id, $emails);
    wp_send_json_success($result);
});

// =========================================================================
// SUBSKRYBENCI — usunięcie, paginacja
// =========================================================================

add_action('wp_ajax_evk_nl_delete_subscriber', function () {
    evk_nl_ajax_check();
    $id = (int) ($_POST['id'] ?? 0);
    wp_send_json(evk_nl_delete_subscriber($id) ? ['success' => true] : ['success' => false, 'msg' => 'Błąd usuwania.']);
});

add_action('wp_ajax_evk_nl_get_subscribers', function () {
    evk_nl_ajax_check();
    $list_id = (int) ($_POST['list_id'] ?? 0);
    $page    = max(1, (int) ($_POST['page'] ?? 1));
    $search  = sanitize_text_field($_POST['search'] ?? '');
    $status  = isset($_POST['status']) && $_POST['status'] !== '' ? (int) $_POST['status'] : null;
    $limit   = 20;
    $offset  = ($page - 1) * $limit;

    $args  = ['limit' => $limit, 'offset' => $offset, 'search' => $search, 'status' => $status];
    $items = evk_nl_get_subscribers($list_id, $args);
    $total = evk_nl_count_subscribers($list_id, $args);

    wp_send_json_success([
        'items' => $items,
        'total' => $total,
        'pages' => (int) ceil($total / $limit),
        'page'  => $page,
    ]);
});

// =========================================================================
// SZABLONY
// =========================================================================

add_action('wp_ajax_evk_nl_save_template', function () {
    evk_nl_ajax_check();
    $id   = (int) ($_POST['id'] ?? 0);
    $data = [
        'name'        => sanitize_text_field($_POST['name'] ?? ''),
        'subject'     => sanitize_text_field($_POST['subject'] ?? ''),
        'body_html'   => wp_kses_post(stripslashes($_POST['body_html'] ?? '')),
        'attachments' => json_decode(stripslashes($_POST['attachments'] ?? '[]'), true) ?: [],
    ];

    if (empty($data['name']) || empty($data['subject'])) {
        wp_send_json_error(['msg' => 'Nazwa i temat są wymagane.']);
    }

    if ($id > 0) {
        $ok = evk_nl_update_template($id, $data);
        wp_send_json($ok ? ['success' => true] : ['success' => false, 'msg' => 'Błąd aktualizacji.']);
    } else {
        $new_id = evk_nl_create_template($data);
        $new_id ? wp_send_json_success(['id' => $new_id]) : wp_send_json_error(['msg' => 'Błąd tworzenia szablonu.']);
    }
});

add_action('wp_ajax_evk_nl_delete_template', function () {
    evk_nl_ajax_check();
    $id = (int) ($_POST['id'] ?? 0);
    wp_send_json(evk_nl_delete_template($id) ? ['success' => true] : ['success' => false]);
});

add_action('wp_ajax_evk_nl_get_template', function () {
    evk_nl_ajax_check();
    $id  = (int) ($_POST['id'] ?? 0);
    $tpl = evk_nl_get_template($id);
    $tpl ? wp_send_json_success($tpl) : wp_send_json_error(['msg' => 'Nie znaleziono szablonu.']);
});

// =========================================================================
// KAMPANIE
// =========================================================================

add_action('wp_ajax_evk_nl_save_campaign', function () {
    evk_nl_ajax_check();
    $id   = (int) ($_POST['id'] ?? 0);
    $data = [
        'name'             => sanitize_text_field($_POST['name'] ?? ''),
        'template_id'      => (int) ($_POST['template_id'] ?? 0),
        'lists'            => json_decode(stripslashes($_POST['lists'] ?? '[]'), true) ?: [],
        'scheduled_at'     => sanitize_text_field($_POST['scheduled_at'] ?? ''),
        'batch_size'       => (int) ($_POST['batch_size'] ?? 50),
        'batch_interval'   => (int) ($_POST['batch_interval'] ?? 5),
        'tracking_enabled' => !empty($_POST['tracking_enabled']) ? 1 : 0,
    ];

    if (empty($data['name']) || !$data['template_id']) {
        wp_send_json_error(['msg' => 'Nazwa i szablon są wymagane.']);
    }

    if ($id > 0) {
        $ok = evk_nl_update_campaign($id, $data);
        wp_send_json($ok ? ['success' => true, 'id' => $id] : ['success' => false, 'msg' => 'Błąd aktualizacji.']);
    } else {
        $new_id = evk_nl_create_campaign($data);
        $new_id ? wp_send_json_success(['id' => $new_id]) : wp_send_json_error(['msg' => 'Błąd tworzenia kampanii.']);
    }
});

add_action('wp_ajax_evk_nl_delete_campaign', function () {
    evk_nl_ajax_check();
    $id = (int) ($_POST['id'] ?? 0);
    wp_send_json(evk_nl_delete_campaign($id) ? ['success' => true] : ['success' => false]);
});

add_action('wp_ajax_evk_nl_launch_campaign', function () {
    evk_nl_ajax_check();
    $id     = (int) ($_POST['id'] ?? 0);
    $action = sanitize_key($_POST['campaign_action'] ?? 'launch');

    $result = match ($action) {
        'launch'  => evk_nl_launch_campaign($id),
        'pause'   => evk_nl_pause_campaign($id),
        'resume'  => evk_nl_resume_campaign($id),
        'restart' => evk_nl_restart_campaign($id),
        'cancel'  => evk_nl_cancel_campaign($id),
        default   => false,
    };

    $campaign = evk_nl_get_campaign($id);
    wp_send_json([
        'success' => (bool) $result,
        'status'  => $campaign['status'] ?? '',
        'msg'     => $result ? 'OK' : 'Błąd operacji.',
    ]);
});

add_action('wp_ajax_evk_nl_campaign_stats', function () {
    evk_nl_ajax_check();
    $id    = (int) ($_POST['id'] ?? 0);
    $stats = evk_nl_campaign_stats($id);
    wp_send_json_success($stats);
});

add_action('wp_ajax_evk_nl_campaign_queue', function () {
    evk_nl_ajax_check();
    $id     = (int) ($_POST['id'] ?? 0);
    $status = sanitize_key($_POST['status'] ?? '');
    $page   = (int) ($_POST['page'] ?? 1);
    wp_send_json_success(evk_nl_campaign_queue($id, $status, $page));
});

// =========================================================================
// LOGI — eksport CSV
// =========================================================================

add_action('wp_ajax_evk_nl_export_logs', function () {
    evk_nl_ajax_check();
    $campaign_id = (int) ($_POST['campaign_id'] ?? 0);
    $event       = sanitize_key($_POST['event'] ?? '');

    $logs = evk_nl_get_logs($campaign_id, $event, 9999);

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="kampania-' . $campaign_id . '-logi.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // BOM dla Excela
    $san = function ($v) { $v = (string) $v; return preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v; };
    fputcsv($out, ['ID', 'Event', 'Subscriber ID', 'Data', 'Czas']);
    foreach ($logs as $row) {
        fputcsv($out, array_map($san, [$row['id'], $row['event'], $row['subscriber_id'], $row['data_json'], $row['created_at']]));
    }
    fclose($out);
    exit;
});

// =========================================================================
// BULK ACTIONS — subskrybenci
// =========================================================================

add_action('wp_ajax_evk_nl_bulk_subscribers', function () {
    evk_nl_ajax_check();
    $action  = sanitize_key($_POST['bulk_action'] ?? '');
    $ids_raw = json_decode(stripslashes($_POST['ids'] ?? '[]'), true) ?: [];
    $ids     = array_map('intval', $ids_raw);

    if (empty($ids) || !in_array($action, ['delete', 'unsubscribe', 'reactivate'], true)) {
        wp_send_json_error(['msg' => 'Nieprawidłowe dane.']);
    }

    global $wpdb;
    $t       = evk_nl_table('subscribers');
    $count   = 0;

    foreach ($ids as $id) {
        switch ($action) {
            case 'delete':
                if ($wpdb->delete($t, ['id' => $id])) $count++;
                break;
            case 'unsubscribe':
                if ($wpdb->update($t, ['status' => 0, 'unsubscribed_at' => current_time('mysql')], ['id' => $id, 'status' => 1])) $count++;
                break;
            case 'reactivate':
                if ($wpdb->update($t, ['status' => 1, 'unsubscribed_at' => null], ['id' => $id, 'status' => 0])) $count++;
                break;
        }
    }

    wp_send_json_success(['count' => $count]);
});

// =========================================================================
// BULK ACTIONS — kampanie
// =========================================================================

add_action('wp_ajax_evk_nl_bulk_campaigns', function () {
    evk_nl_ajax_check();
    $action  = sanitize_key($_POST['bulk_action'] ?? '');
    $ids_raw = json_decode(stripslashes($_POST['ids'] ?? '[]'), true) ?: [];
    $ids     = array_map('intval', $ids_raw);

    if (empty($ids) || !in_array($action, ['delete', 'clear_logs'], true)) {
        wp_send_json_error(['msg' => 'Nieprawidłowe dane.']);
    }

    global $wpdb;
    $count = 0;

    foreach ($ids as $id) {
        switch ($action) {
            case 'delete':
                if (evk_nl_delete_campaign($id)) $count++;
                break;
            case 'clear_logs':
                $wpdb->delete(evk_nl_table('logs'), ['campaign_id' => $id]);
                $count++;
                break;
        }
    }

    wp_send_json_success(['count' => $count]);
});
