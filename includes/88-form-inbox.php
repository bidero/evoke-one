<?php
if (!defined('ABSPATH')) exit;

/** Czy biezacy uzytkownik moze czytac wiadomosci (skrzynke). */
function evk_inbox_can(): bool {
    return current_user_can('manage_options') || current_user_can('evk_access_messages');
}

/**
 * Evoke ONE — Moduł Skrzynki Formularzy Bricks (Form Inbox)
 */

define('EVK_INBOX_OPTION',      'evk_forminbox');
define('EVK_INBOX_READ_OPTION', 'evk_forminbox_read');

// =========================================================================
// USTAWIENIA
// =========================================================================

function evk_inbox_defaults(): array {
    return [
        'enabled'       => 0,
        'menu_label'    => 'Wiadomości',
        'menu_icon'     => 'dashicons-email-alt',
        'menu_position' => 25,
        'menu_badge'    => 1,
        'per_page'      => 25,
        'field_labels'  => [],   // ['form-field-abc' => 'Imię']
        'hidden_fields' => [],   // ['form-field-xyz']
        'email_field'      => '',   // klucz pola z e-mailem (auto-detect jeśli puste)
        'message_template' => '',   // szablon z {{klucz}} placeholderami
        'name_template'    => '',   // szablon nazwy w sidebarze, np. {{nazwisko}} {{imie}}
        'preview_field'    => '',   // klucz pola dla linii podglądu w sidebarze
        'subject_field'    => '',   // klucz pola tematu (nagłówek detalu); auto jeśli puste
        'header_layout'    => [],   // [ ['key'=>'fonlfr','type'=>'subtitle'], ... ]
        'sidebar_layout'   => [],   // [ ['key'=>'imie','type'=>'name'], ... ]
        'form_names'       => [],   // ['form_id' => 'Czytelna nazwa']
    ];
}

function evk_inbox_get_settings(): array {
    return wp_parse_args(get_option(EVK_INBOX_OPTION, []), evk_inbox_defaults());
}

function evk_inbox_table(): string {
    global $wpdb;
    return $wpdb->prefix . 'bricks_form_submissions';
}

function evk_inbox_table_exists(): bool {
    global $wpdb;
    return (bool) $wpdb->get_var("SHOW TABLES LIKE '" . evk_inbox_table() . "'");
}

/** Liczba nieprzeczytanych wiadomosci (cache 60s). */
function evk_inbox_unread_count(): int {
    $cached = get_transient('evk_inbox_unread');
    if ($cached !== false) return (int) $cached;
    if (!evk_inbox_table_exists()) return 0;
    global $wpdb;
    $t    = evk_inbox_table();
    $read = evk_inbox_get_read();
    if (empty($read)) {
        $n = (int) $wpdb->get_var("SELECT COUNT(*) FROM $t");
    } else {
        $ph = implode(',', array_fill(0, count($read), '%d'));
        $n  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $t WHERE id NOT IN ($ph)", $read));
    }
    set_transient('evk_inbox_unread', $n, 30);
    return $n;
}

// =========================================================================
// REJESTRACJA USTAWIEŃ
// =========================================================================

add_action('admin_init', function () {
    register_setting(EVK_INBOX_OPTION . '_group', EVK_INBOX_OPTION, [
        'sanitize_callback' => 'evk_inbox_sanitize_settings',
    ]);
});

function evk_inbox_sanitize_settings($input): array {
    $d = evk_inbox_defaults();
    $c = [];
    $c['enabled']       = evk_preserve_toggle($input, EVK_INBOX_OPTION);
    $c['menu_label']    = sanitize_text_field($input['menu_label']    ?? $d['menu_label'])    ?: $d['menu_label'];
    $c['menu_icon']     = sanitize_text_field($input['menu_icon']     ?? $d['menu_icon'])     ?: $d['menu_icon'];
    $c['menu_position'] = max(1, min(100, intval($input['menu_position'] ?? 25)));
    $c['menu_badge']    = !empty($input['menu_badge']) ? 1 : 0;
    $c['per_page']      = max(5, min(100, intval($input['per_page']   ?? 25)));
    $c['email_field']      = sanitize_key($input['email_field'] ?? '');
    $c['message_template'] = sanitize_textarea_field($input['message_template'] ?? '');
    $c['name_template']    = sanitize_text_field($input['name_template']    ?? '');
    $c['preview_field']    = sanitize_text_field(trim($input['preview_field'] ?? ''));
    $c['preview_field']    = preg_replace('/[^a-z0-9_-]/i', '', $c['preview_field']);
    $c['subject_field']    = preg_replace('/[^a-z0-9_-]/i', '', sanitize_text_field(trim($input['subject_field'] ?? '')));
    $c['header_layout']    = evk_inbox_sanitize_layout($input['header_layout_keys']  ?? [], $input['header_layout_types']  ?? [], ['title', 'subtitle', 'meta']);
    $c['sidebar_layout']   = evk_inbox_sanitize_layout($input['sidebar_layout_keys'] ?? [], $input['sidebar_layout_types'] ?? [], ['name', 'preview', 'meta']);

    $form_names = [];
    $fn_keys = $input['form_names_keys'] ?? [];
    $fn_vals = $input['form_names_vals'] ?? [];
    if (is_array($fn_keys)) {
        foreach ($fn_keys as $i => $k) {
            $k = sanitize_text_field(trim($k));
            $v = sanitize_text_field(trim($fn_vals[$i] ?? ''));
            if ($k && $v) $form_names[$k] = $v;
        }
    }
    $c['form_names'] = $form_names;

    // Mapowanie pól: dwie równoległe tablice keys[] + vals[]
    $labels = [];
    $keys_arr = $input['field_labels_keys'] ?? [];
    $vals_arr = $input['field_labels_vals'] ?? [];
    if (is_array($keys_arr)) {
        foreach ($keys_arr as $i => $k) {
            $k = sanitize_text_field(trim($k));
            $v = sanitize_text_field(trim($vals_arr[$i] ?? ''));
            // Akceptuj alfanumeryczne + myślniki (krótkie klucze Bricks np. "fonlfr")
            $k = preg_replace('/[^a-z0-9_-]/i', '', $k);
            if ($k) $labels[$k] = $v; // wartość może być pusta (nie ma labell → auto)
        }
    }
    // Fallback: stary format field_labels[key] => val (np. import)
    if (empty($labels) && !empty($input['field_labels']) && is_array($input['field_labels'])) {
        foreach ($input['field_labels'] as $k => $v) {
            $k = sanitize_text_field(trim($k));
            $k = preg_replace('/[^a-z0-9_-]/i', '', $k);
            if ($k) $labels[$k] = sanitize_text_field($v);
        }
    }
    $c['field_labels'] = $labels;

    $hidden = [];
    if (!empty($input['hidden_fields']) && is_array($input['hidden_fields'])) {
        foreach ($input['hidden_fields'] as $k) {
            $k = sanitize_text_field(trim($k));
            $k = preg_replace('/[^a-z0-9_-]/i', '', $k);
            if ($k) $hidden[] = $k;
        }
    }
    $c['hidden_fields'] = array_values(array_unique($hidden));
    return $c;
}

// =========================================================================
// REJESTRACJA MENU
// =========================================================================

add_action('admin_menu', function () {
    $s = evk_inbox_get_settings();
    if (empty($s['enabled'])) return;

    $menu_title = $s['menu_label'];
    if (!empty($s['menu_badge'])) {
        $n = evk_inbox_unread_count();
        if ($n > 0) {
            $menu_title .= ' <span class="awaiting-mod"><span class="pending-count">' . (int) $n . '</span></span>';
        }
    }

    add_menu_page(
        $s['menu_label'],
        $menu_title,
        'evk_access_messages',
        'evk-form-inbox',
        'evk_inbox_render_page',
        $s['menu_icon'],
        (int) $s['menu_position']
    );
});

add_action('admin_enqueue_scripts', function (string $hook) {
    if (strpos($hook, 'evk-form-inbox') === false) return;
    // Admin.js/css dla evo-toggle i evo-status-card na stronie ustawień
    // (nie jest potrzebny na stronie inbox — ma własny CSS/JS)
});

function evk_inbox_render_page(): void {
    if (!evk_inbox_can()) return;
    require EVOKE_ONE_DIR . 'includes/admin/forminbox-page.php';
}

// =========================================================================
// HELPERY
// =========================================================================

function evk_inbox_get_read(): array {
    $r = get_option(EVK_INBOX_READ_OPTION, []);
    return is_array($r) ? array_map('intval', $r) : [];
}

function evk_inbox_mark_read(array $ids): void {
    $read = evk_inbox_get_read();
    $read = array_values(array_unique(array_merge($read, array_map('intval', $ids))));
    if (count($read) > 10000) $read = array_slice($read, -10000);
    update_option(EVK_INBOX_READ_OPTION, $read, false);
    delete_transient('evk_inbox_unread');
}

function evk_inbox_mark_unread(array $ids): void {
    $read = evk_inbox_get_read();
    $ids  = array_map('intval', $ids);
    $read = array_values(array_diff($read, $ids));
    update_option(EVK_INBOX_READ_OPTION, $read, false);
    delete_transient('evk_inbox_unread');
}

function evk_inbox_format_date(string $dt): string {
    $ts   = strtotime($dt);
    $diff = time() - $ts;
    if ($diff < 60)        return 'Przed chwilą';
    if ($diff < 3600)      return round($diff / 60) . ' min temu';
    if ($diff < 86400)     return round($diff / 3600) . ' godz. temu';
    if ($diff < 86400 * 2) return 'Wczoraj ' . date('H:i', $ts);
    if ($diff < 86400 * 7) return date('j M', $ts);
    return date('j M Y', $ts);
}

/**
 * Sanityzacja układu pól (nagłówek / sidebar) z równoległych tablic keys[]+types[].
 */
function evk_inbox_sanitize_layout($keys, $types, array $allowed): array {
    $out = [];
    if (!is_array($keys)) return $out;
    foreach ($keys as $i => $k) {
        $k = sanitize_text_field(trim($k));
        if ($k === '') continue;
        $t = sanitize_key($types[$i] ?? $allowed[0]);
        if (!in_array($t, $allowed, true)) $t = $allowed[0];
        $out[] = ['key' => $k, 'type' => $t];
    }
    return $out;
}

/**
 * Wartość pola po kluczu (pełnym lub krótkim bez prefiksu form-field-).
 */
function evk_inbox_value_by_key(array $fields, string $key): string {
    if ($key === '') return '';
    foreach ($fields as $k => $v) {
        $short = preg_replace('/^form-field-/', '', $k);
        if ($k === $key || $short === $key) return evk_inbox_extract_value($v);
    }
    return '';
}

/**
 * Etykieta pola po kluczu (z danych Bricks lub konfiguracji).
 */
function evk_inbox_label_for_key(array $fields, string $key, array $s): string {
    foreach ($fields as $k => $v) {
        $short = preg_replace('/^form-field-/', '', $k);
        if ($k === $key || $short === $key) return evk_inbox_bricks_label($k, $v, $s);
    }
    return evk_inbox_field_label($key, $s);
}

/**
 * Temat wiadomości — skonfigurowane pole lub auto-detekcja (label/klucz: temat/subject/tytuł).
 */
function evk_inbox_get_subject(array $fields, array $s): string {
    if (!empty($s['subject_field'])) {
        $v = evk_inbox_value_by_key($fields, $s['subject_field']);
        if (trim($v)) return $v;
    }
    $hints = ['temat', 'subject', 'tytul', 'title'];
    foreach ($fields as $k => $v) {
        $label = (is_array($v) && !empty($v['name'])) ? $v['name'] : '';
        $short = preg_replace('/^form-field-/', '', $k);
        $hay   = mb_strtolower(remove_accents($label . ' ' . $short));
        foreach ($hints as $h) {
            if (mb_strpos($hay, $h) !== false) {
                $val = evk_inbox_extract_value($v);
                if (trim($val)) return $val;
            }
        }
    }
    return '';
}

/**
 * Renderuje jedną linię układu: pojedynczy klucz pola LUB szablon z {{klucz}}.
 * Pozwala łączyć kilka pól w jednej linii, np. "{{nazwisko}} {{imie}}".
 */
function evk_inbox_render_line(string $tpl, array $fields): string {
    if ($tpl === '') return '';
    if (strpos($tpl, '{{') === false) {
        return evk_inbox_value_by_key($fields, $tpl); // zwykły klucz
    }
    $out = evk_inbox_render_template($tpl, $fields, true);  // raw, {{x}} podmienione
    $out = preg_replace('/\{\{[^}]*\}\}/', '', $out);    // usuń nierozwiązane
    $out = preg_replace('/[ \t]{2,}/', ' ', $out);
    return trim($out);
}

/**
 * Buduje dane sidebaru: nazwa (pogrubiona) + linie (preview/meta).
 * Pusty sidebar_layout → fallback do auto-nazwy + podglądu.
 */
function evk_inbox_build_sidebar(array $fields, array $s): array {
    $layout = $s['sidebar_layout'] ?? [];
    $name   = '';
    $lines  = [];
    foreach ($layout as $row) {
        $val = evk_inbox_render_line($row['key'], $fields);
        if (!trim($val)) continue;
        if ($row['type'] === 'name' && $name === '') {
            $name = $val;
        } else {
            $lines[] = ['text' => mb_substr($val, 0, 120), 'type' => $row['type']];
        }
    }
    if ($name === '') $name = evk_inbox_get_name($fields, $s);
    if (empty($layout)) {
        $prev = evk_inbox_get_preview($fields, $s);
        if (trim($prev) !== '' && $prev !== "—") {
            $lines[] = ['text' => $prev, 'type' => 'preview'];
        }
    }
    return ['name' => $name, 'lines' => $lines];
}

/**
 * Buduje nagłówek detalu: tytuł + podtytuł (temat) + linie meta.
 * Pusty header_layout → auto-nazwa jako tytuł + auto-temat jako podtytuł.
 */
function evk_inbox_build_header(array $fields, array $s): array {
    $layout   = $s['header_layout'] ?? [];
    $title    = '';
    $subtitle = '';
    $lines    = [];
    foreach ($layout as $row) {
        $val = evk_inbox_render_line($row['key'], $fields);
        if (!trim($val)) continue;
        if ($row['type'] === 'title' && $title === '') {
            $title = $val;
        } elseif ($row['type'] === 'subtitle' && $subtitle === '') {
            $subtitle = $val;
        } else {
            $is_tpl = strpos($row['key'], '{{') !== false;
            $lines[] = [
                'label' => $is_tpl ? '' : evk_inbox_label_for_key($fields, $row['key'], $s),
                'value' => $val,
            ];
        }
    }
    if ($title === '')    $title = evk_inbox_get_name($fields, $s);
    if ($subtitle === '') $subtitle = evk_inbox_get_subject($fields, $s);
    return ['title' => $title, 'subtitle' => $subtitle, 'lines' => $lines];
}

function evk_inbox_field_label(string $key, array $s): string {
    // Sprawdź pełny klucz
    if (!empty($s['field_labels'][$key])) return $s['field_labels'][$key];
    // Sprawdź krótki klucz (bez prefiksu form-field-)
    $short = preg_replace('/^form-field-/', '', $key);
    if (!empty($s['field_labels'][$short])) return $s['field_labels'][$short];
    // Auto-generuj z krótkiego klucza
    return ucwords(str_replace(['-', '_'], ' ', $short));
}

/**
 * Renderuje szablon wiadomości — zastępuje {{klucz}} wartościami pól.
 * Escape HTML wbudowany — bezpieczny output.
 */
function evk_inbox_render_template(string $tpl, array $raw_fields, bool $raw_output = false): string {
    $map = [];
    foreach ($raw_fields as $key => $value) {
        // Stripuj prefiks Bricks "type, value"
        $v = evk_inbox_extract_value($value);
        $map[$key] = $v;
        $short = preg_replace('/^form-field-/', '', $key);
        if ($short !== $key) $map[$short] = $v;
    }
    $output = $tpl;
    foreach ($map as $k => $v) {
        $output = str_replace('{{' . $k . '}}', $v, $output);
    }
    if ($raw_output) return $output; // dla get_name — bez escape
    return esc_html($output);
}

function evk_inbox_get_preview(array $fields, array $s = []): string {
    // Skonfigurowane pole podglądu
    if (!empty($s['preview_field'])) {
        $key = $s['preview_field'];
        // Szukaj pełnego klucza i krótkiego
        foreach ($fields as $k => $v) {
            $short = preg_replace('/^form-field-/', '', $k);
            if ($k === $key || $short === $key) {
                $val = evk_inbox_extract_value($v);
                if (trim($val)) return mb_substr($val, 0, 100);
            }
        }
    }
    // Auto: pierwsze niepuste pole (pomiń e-mail i IP)
    foreach ($fields as $v) {
        $val = evk_inbox_extract_value($v);
        if (trim($val) && !is_email($val) && !filter_var($val, FILTER_VALIDATE_IP)) {
            return mb_substr($val, 0, 100);
        }
    }
    return '—';
}

function evk_inbox_find_email(array $fields, string $forced_key = ''): string {
    if ($forced_key) {
        foreach ($fields as $k => $v) {
            $short = preg_replace('/^form-field-/', '', $k);
            if ($k === $forced_key || $short === $forced_key) {
                $val = trim(evk_inbox_extract_value($v));
                if (is_email($val)) return $val;
            }
        }
    }
    // Auto-detect: szukaj pola typu email lub wartości będącej e-mailem
    foreach ($fields as $v) {
        if (is_array($v) && ($v['type'] ?? '') === 'email') {
            $val = trim(evk_inbox_extract_value($v));
            if (is_email($val)) return $val;
        }
    }
    foreach ($fields as $v) {
        $val = trim(evk_inbox_extract_value($v));
        if (is_email($val)) return $val;
    }
    return '';
}

/**
 * Bricks zapisuje wartości jako "type, value" (np. "select, Rezerwacja noclegu").
 * Stripujemy prefiks aby uzyskać czystą wartość.
 */
function evk_inbox_extract_value($raw): string {
    if (is_array($raw)) {
        // Bricks zapisuje pola jako {"type":"select","value":"Rezerwacja noclegu","name":"Temat"}
        if (array_key_exists('value', $raw)) {
            $v = $raw['value'];
            return is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v;
        }
        // Zwykła tablica (checkbox multi) — łącz wartości
        return implode(', ', array_map('evk_inbox_extract_value', array_values($raw)));
    }
    $raw = (string) $raw;
    // Fallback: flat format "type, value" (starsze wersje Bricks)
    static $types = ['textarea','select','checkbox','radio','email','tel','number','date','file','password','url','text','hidden','range','color','time','week','month','datetime-local'];
    foreach ($types as $t) {
        if (strncasecmp($raw, $t . ', ', strlen($t) + 2) === 0) {
            return substr($raw, strlen($t) + 2);
        }
    }
    return $raw;
}

/**
 * Wyciąga label pola z danych Bricks (jeśli zapisany) lub konfiguracji.
 */
function evk_inbox_bricks_label(string $key, $raw, array $s): string {
    // Bricks może zapisać label w {"name": "Temat", ...}
    if (is_array($raw) && !empty($raw['name'])) {
        return sanitize_text_field($raw['name']);
    }
    return evk_inbox_field_label($key, $s);
}

function evk_inbox_get_name(array $fields, array $s = []): string {
    // Szablon nazwy skonfigurowany przez użytkownika
    if (!empty($s['name_template'])) {
        $rendered = evk_inbox_render_template($s['name_template'], $fields, true);
        $rendered = strip_tags($rendered);
        $rendered = trim(str_replace('&nbsp;', ' ', html_entity_decode($rendered, ENT_QUOTES, 'UTF-8')));
        if ($rendered && $rendered !== $s['name_template']) return $rendered;
    }
    // Auto-detect: szukaj po kluczu lub po type=text/name w danych Bricks
    $name_hints = ['name', 'imie', 'imię', 'nazwisko', 'fullname', 'full_name', 'first_name', 'last_name'];
    foreach ($fields as $k => $v) {
        $short = preg_replace('/^form-field-/', '', $k);
        $val   = evk_inbox_extract_value($v);
        if (!trim($val) || is_email($val) || strlen($val) > 100) continue;
        foreach ($name_hints as $h) {
            if (stripos($short, $h) !== false) return sanitize_text_field($val);
        }
    }
    // Pierwsze krótkie pole tekstowe (nie e-mail, nie URL, nie numer telefonu)
    foreach ($fields as $v) {
        $val = evk_inbox_extract_value($v);
        if (trim($val) && strlen($val) < 80 && !is_email($val) && !is_numeric(str_replace([' ','-','+'], '', $val))) {
            return sanitize_text_field($val);
        }
    }
    return 'Anonimowy';
}

// =========================================================================
// AJAX: Lista formularzy (z liczbą nieprzeczytanych)
// =========================================================================

add_action('wp_ajax_evk_inbox_forms', function () {
    check_ajax_referer('evk_inbox_nonce', 'nonce');
    if (!evk_inbox_can()) wp_send_json_error('forbidden', 403);
    if (!evk_inbox_table_exists()) wp_send_json_error('no_table');

    global $wpdb;
    $table = evk_inbox_table();
    $read  = evk_inbox_get_read();

    $rows = $wpdb->get_results("SELECT form_id, COUNT(*) as cnt FROM {$table} GROUP BY form_id ORDER BY cnt DESC");

    $s = evk_inbox_get_settings();
    $forms = []; $all_cnt = 0; $all_unread = 0;
    foreach ($rows as $r) {
        $ids    = array_map('intval', $wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE form_id = %s", $r->form_id)));
        $unread = count(array_diff($ids, $read));
        $forms[]     = [
            'form_id'    => $r->form_id,
            'form_label' => $s['form_names'][$r->form_id] ?? $r->form_id,
            'count'      => (int)$r->cnt,
            'unread'     => $unread,
        ];
        $all_cnt    += (int)$r->cnt;
        $all_unread += $unread;
    }
    wp_send_json_success(['forms' => $forms, 'total' => $all_cnt, 'total_unread' => $all_unread]);
});

// =========================================================================
// AJAX: Lista wiadomości (z paginacją, filtrem i wyszukiwaniem)
// =========================================================================

add_action('wp_ajax_evk_inbox_list', function () {
    check_ajax_referer('evk_inbox_nonce', 'nonce');
    if (!evk_inbox_can()) wp_send_json_error('forbidden', 403);
    if (!evk_inbox_table_exists()) wp_send_json_error('no_table');

    global $wpdb;
    $table    = evk_inbox_table();
    $s        = evk_inbox_get_settings();
    $read     = evk_inbox_get_read();
    $form_id  = sanitize_text_field($_GET['form_id'] ?? '');
    $search   = sanitize_text_field($_GET['search']  ?? '');
    $page     = max(1, intval($_GET['page'] ?? 1));
    $per_page = (int) $s['per_page'];
    $offset   = ($page - 1) * $per_page;

    $where = ['1=1']; $params = [];
    if ($form_id && $form_id !== 'all') { $where[] = 'form_id = %s'; $params[] = $form_id; }
    if ($search) { $where[] = 'form_data LIKE %s'; $params[] = '%' . $wpdb->esc_like($search) . '%'; }
    $wsql = implode(' AND ', $where);

    $total = (int) ($params
        ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE {$wsql}", ...$params))
        : $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$wsql}"));

    $rows = $params
        ? $wpdb->get_results($wpdb->prepare("SELECT id, form_id, form_data, created_at FROM {$table} WHERE {$wsql} ORDER BY created_at DESC LIMIT %d OFFSET %d", ...array_merge($params, [$per_page, $offset])))
        : $wpdb->get_results($wpdb->prepare("SELECT id, form_id, form_data, created_at FROM {$table} WHERE {$wsql} ORDER BY created_at DESC LIMIT %d OFFSET %d", $per_page, $offset));

    $items = [];
    foreach ($rows as $row) {
        $fields  = json_decode($row->form_data, true) ?: [];
        $form_label = $s['form_names'][$row->form_id] ?? $row->form_id;
        $sb         = evk_inbox_build_sidebar($fields, $s);
        $items[] = [
            'id'         => (int) $row->id,
            'form_id'    => $row->form_id,
            'form_label' => $form_label,
            'name'       => $sb['name'],
            'lines'      => $sb['lines'],
            'email'      => evk_inbox_find_email($fields, $s['email_field']),
            'date'       => evk_inbox_format_date($row->created_at),
            'is_read'    => in_array((int) $row->id, $read, true),
        ];
    }
    wp_send_json_success(['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page, 'pages' => (int) ceil($total / max(1, $per_page))]);
});

// =========================================================================
// AJAX: Szczegóły pojedynczej wiadomości
// =========================================================================

add_action('wp_ajax_evk_inbox_detail', function () {
    check_ajax_referer('evk_inbox_nonce', 'nonce');
    if (!evk_inbox_can()) wp_send_json_error('forbidden', 403);
    if (!evk_inbox_table_exists()) wp_send_json_error('no_table');

    global $wpdb;
    $id    = intval($_GET['id'] ?? 0);
    $table = evk_inbox_table();
    $s     = evk_inbox_get_settings();
    $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $id));
    if (!$row) wp_send_json_error('not_found');

    $fields = json_decode($row->form_data, true) ?: [];
    evk_inbox_mark_read([$id]);

    $display = [];
    foreach ($fields as $k => $v) {
        $short = preg_replace('/^form-field-/', '', $k);
        if (in_array($k, $s['hidden_fields'], true) || in_array($short, $s['hidden_fields'], true)) continue;
        $display[] = [
            'key'   => $k,
            'label' => evk_inbox_bricks_label($k, $v, $s),
            'value' => evk_inbox_extract_value($v),
        ];
    }
    $form_label = $s['form_names'][$row->form_id] ?? $row->form_id;

    $user_name = '';
    if (!empty($row->user_id) && ($u = get_userdata((int)$row->user_id))) {
        $user_name = $u->display_name . ' (' . $u->user_email . ')';
    }

    $has_tpl  = !empty($s['message_template']);
    $rendered = $has_tpl ? evk_inbox_render_template($s['message_template'], $fields) : '';
    $hdr      = evk_inbox_build_header($fields, $s);

    wp_send_json_success([
        'id'         => (int) $row->id,
        'form_id'    => $row->form_id,
        'form_label' => $form_label,
        'fields'       => $display,
        'has_template' => $has_tpl,
        'rendered'     => $rendered,
        'email'        => evk_inbox_find_email($fields, $s['email_field']),
        'name'         => $hdr['title'],
        'subtitle'     => $hdr['subtitle'],
        'header_lines' => $hdr['lines'],
        'meta'         => [
            'date'     => date_i18n('j F Y, H:i', strtotime($row->created_at)),
            'ip'       => $row->ip       ?? '',
            'browser'  => $row->browser  ?? '',
            'os'       => $row->os       ?? '',
            'referrer' => $row->referrer ?? '',
            'user'     => $user_name,
        ],
    ]);
});

// =========================================================================
// AJAX: Oznacz przeczytane / nieprzeczytane
// =========================================================================

add_action('wp_ajax_evk_inbox_mark', function () {
    check_ajax_referer('evk_inbox_nonce', 'nonce');
    if (!evk_inbox_can()) wp_send_json_error('forbidden', 403);
    $ids   = array_map('intval', (array)($_POST['ids'] ?? []));
    $state = sanitize_key($_POST['state'] ?? 'read');
    if ($state === 'unread') evk_inbox_mark_unread($ids);
    else                     evk_inbox_mark_read($ids);
    wp_send_json_success();
});

// =========================================================================
// AJAX: Usuń wiadomości
// =========================================================================

add_action('wp_ajax_evk_inbox_delete', function () {
    check_ajax_referer('evk_inbox_nonce', 'nonce');
    if (!evk_inbox_can()) wp_send_json_error('forbidden', 403);
    if (!evk_inbox_table_exists()) wp_send_json_error('no_table');

    global $wpdb;
    $ids   = array_map('intval', (array)($_POST['ids'] ?? []));
    $table = evk_inbox_table();
    $count = 0;
    foreach ($ids as $id) { if ($wpdb->delete($table, ['id' => $id], ['%d'])) $count++; }
    evk_inbox_mark_unread($ids); // usuń z listy przeczytanych
    wp_send_json_success(['deleted' => $count]);
});

// =========================================================================
// AJAX: Pobierz klucze pól (dla ustawień)
// =========================================================================

add_action('wp_ajax_evk_inbox_field_keys', function () {
    check_ajax_referer('evk_inbox_nonce', 'nonce');
    if (!evk_inbox_can()) wp_send_json_error('forbidden', 403);

    if (!evk_inbox_table_exists()) { wp_send_json_success(['keys' => []]); }

    global $wpdb;
    $s    = evk_inbox_get_settings();
    $rows = $wpdb->get_results("SELECT form_data FROM " . evk_inbox_table() . " ORDER BY id DESC LIMIT 300");
    $keys = [];
    foreach ($rows as $row) {
        $fields = json_decode($row->form_data, true) ?: [];
        foreach (array_keys($fields) as $k) $keys[$k] = true;
    }
    $result = [];
    foreach (array_keys($keys) as $k) {
        $result[] = ['key' => $k, 'label' => evk_inbox_field_label($k, $s), 'hidden' => in_array($k, $s['hidden_fields'], true)];
    }
    wp_send_json_success(['keys' => $result, 'settings' => $s]);
});

// =========================================================================
// EKSPORT CSV (nie-AJAX — direct download)
// =========================================================================

add_action('admin_init', function () {
    if (
        !is_admin()
        || ($_GET['action'] ?? '') !== 'evk_inbox_export'
        || !evk_inbox_can()
        || empty($_GET['_wpnonce'])
        || !wp_verify_nonce($_GET['_wpnonce'], 'evk_inbox_export')
    ) return;

    if (!evk_inbox_table_exists()) wp_die('Tabela Bricks nie istnieje.');

    global $wpdb;
    $table   = evk_inbox_table();
    $s       = evk_inbox_get_settings();
    $form_id = sanitize_text_field($_GET['form_id'] ?? '');
    $from    = sanitize_text_field($_GET['from'] ?? '');
    $to      = sanitize_text_field($_GET['to'] ?? '');

    $cond = []; $params = [];
    if ($form_id && $form_id !== 'all') { $cond[] = 'form_id = %s';     $params[] = $form_id; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $cond[] = 'created_at >= %s'; $params[] = $from . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   { $cond[] = 'created_at <= %s'; $params[] = $to . ' 23:59:59'; }
    $where = $cond ? ('WHERE ' . implode(' AND ', $cond)) : '';
    $rows  = $where
        ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} {$where} ORDER BY created_at DESC", $params))
        : $wpdb->get_results("SELECT * FROM {$table} ORDER BY created_at DESC");

    $all_keys = [];
    foreach ($rows as $row) {
        $fields = json_decode($row->form_data, true) ?: [];
        foreach (array_keys($fields) as $k) {
            if (!in_array($k, $s['hidden_fields'], true)) $all_keys[$k] = true;
        }
    }
    $all_keys = array_keys($all_keys);

    $fn = 'submissions-' . sanitize_file_name($form_id ?: 'all') . '-' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fn . '"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // UTF-8 BOM

    $hdr = ['ID', 'Formularz', 'Data'];
    foreach ($all_keys as $k) $hdr[] = evk_inbox_field_label($k, $s);
    $hdr = array_merge($hdr, ['IP', 'Przeglądarka', 'OS', 'Referer', 'Użytkownik']);
    fputcsv($out, $hdr, ';');

    foreach ($rows as $row) {
        $fields = json_decode($row->form_data, true) ?: [];
        $line   = [$row->id, $row->form_id, $row->created_at];
        foreach ($all_keys as $k) {
            $v = $fields[$k] ?? '';
            $line[] = is_array($v) ? implode(', ', $v) : (string) $v;
        }
        $user_name = '';
        if (!empty($row->user_id) && ($u = get_userdata((int)$row->user_id))) $user_name = $u->display_name;
        $line = array_merge($line, [$row->ip ?? '', $row->browser ?? '', $row->os ?? '', $row->referrer ?? '', $user_name]);
        fputcsv($out, $line, ';');
    }
    fclose($out);
    exit;
});
