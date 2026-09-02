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

/**
 * Górna granica wgrywanego pliku z adresami.
 *
 * 2 MB to około 60 tysięcy adresów — więcej niż ma jakakolwiek lista, którą ten
 * moduł obsłuży w rozsądnym czasie. Granica jest po to, żeby import nie był
 * dźwignią: do 1.131.0 zawartość szła prosto do `file_get_contents()`, stamtąd
 * `preg_split()` robił z niej tablicę linii, a `evk_nl_import_emails()` kolejną
 * tablicę adresów. Każdy z tych kroków to osobna kopia w pamięci, więc plik
 * mieszczący się w `upload_max_filesize` potrafił wywrócić proces PHP.
 */
const EVK_NL_CSV_MAX = 2097152;   // 2 MiB

/**
 * Sprawdza wgrany plik. Zwraca pusty łańcuch, gdy jest w porządku, albo
 * komunikat dla użytkownika.
 *
 * Do 1.131.0 nie było tu ŻADNEGO sprawdzenia poza „czy `tmp_name` niepuste":
 * ani kodu błędu z PHP, ani rozmiaru, ani typu. `is_uploaded_file()` jest
 * zabezpieczeniem na zapas — `$_FILES` wypełnia PHP, więc ścieżki nie da się
 * podstawić zwykłym żądaniem — ale to jedna linijka, która pilnuje, żeby ta
 * funkcja nigdy nie przeczytała pliku spoza katalogu uploadów, gdyby kiedyś
 * ktoś zawołał ją z ręcznie złożoną tablicą.
 */
function evk_nl_sprawdz_csv($plik): string {
    if (!is_array($plik)) return 'Brak pliku.';

    $blad = (int) ($plik['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($blad === UPLOAD_ERR_INI_SIZE || $blad === UPLOAD_ERR_FORM_SIZE) {
        return 'Plik jest za duży dla tego serwera.';
    }
    if ($blad === UPLOAD_ERR_NO_FILE) return 'Brak pliku.';
    if ($blad !== UPLOAD_ERR_OK)      return 'Nie udało się wgrać pliku.';

    /* KOLEJNOŚĆ MA ZNACZENIE i nie jest przypadkowa: najpierw to, co widać
       w samych metadanych żądania, a dopiero na końcu cokolwiek, co dotyka
       dysku. Przy odwrotnej kolejności każda odmowa wyglądała jednakowo
       („Nieprawidłowy plik"), bo `is_uploaded_file()` przecinało sprawę przed
       sprawdzeniem rozmiaru i rozszerzenia — użytkownik nie wiedział, co
       poprawić, a test nie miał jak odróżnić jednej przyczyny od drugiej. */
    $rozmiar = (int) ($plik['size'] ?? 0);
    if ($rozmiar <= 0)             return 'Plik jest pusty.';
    if ($rozmiar > EVK_NL_CSV_MAX) return 'Plik jest za duży (maksimum 2 MB).';

    /* Rozszerzenie, nie nagłówek `Content-Type` z żądania — ten podaje
       przeglądarka i bywa czym popadnie (Excel wysyła CSV jako
       `application/vnd.ms-excel`). */
    $rozszerzenie = strtolower(pathinfo((string) ($plik['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($rozszerzenie, ['csv', 'txt'], true)) {
        return 'Dozwolone są pliki .csv i .txt.';
    }

    $tmp = (string) ($plik['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) return 'Nieprawidłowy plik.';
    if (!evk_nl_wyglada_na_tekst($tmp))         return 'To nie jest plik tekstowy.';

    return '';
}

/**
 * Czy plik wygląda na tekstowy: pierwszy kilobajt bez bajtu zerowego.
 *
 * Nie udaje rozpoznawania typu — ma odsiać kogoś, kto podmienił rozszerzenie
 * archiwum albo obrazu. Osobna funkcja, bo `is_uploaded_file()` jest funkcją
 * wbudowaną i w teście z wiersza poleceń zawsze oddaje `false`: bez tego
 * rozdzielenia ta kontrola byłaby nieosiągalna dla testu.
 */
function evk_nl_wyglada_na_tekst(string $sciezka): bool {
    $probka = (string) file_get_contents($sciezka, false, null, 0, 1024);
    return strpos($probka, "\0") === false;
}

add_action('wp_ajax_evk_nl_import_csv_file', function () {
    evk_nl_ajax_check();
    $list_id = (int) ($_POST['list_id'] ?? 0);
    if (!$list_id) wp_send_json_error(['msg' => 'Brak ID listy.']);

    $plik = $_FILES['csv_file'] ?? null;
    $blad = evk_nl_sprawdz_csv($plik);
    if ($blad !== '') wp_send_json_error(['msg' => $blad]);

    // Czytamy najwyżej tyle, ile wolno — nawet gdyby rozmiar z $_FILES kłamał.
    $content = (string) file_get_contents($plik['tmp_name'], false, null, 0, EVK_NL_CSV_MAX);
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
    fputcsv($out, ['ID', 'Event', 'Subscriber ID', 'Data', 'Czas']);
    foreach ($logs as $row) {
        fputcsv($out, array_map('evk_nl_csv_bezpieczna',
            [$row['id'], $row['event'], $row['subscriber_id'], $row['data_json'], $row['created_at']]));
    }
    fclose($out);
    exit;
});

/**
 * Komórka CSV, która nie wykona się jako formuła.
 *
 * Excel i Arkusze Google traktują wartość zaczynającą się od `=`, `+`, `-`
 * albo `@` jako formułę — łącznie z takimi, które sięgają do sieci albo do
 * innych plików. Treść w eksporcie adresów pochodzi od osób spoza serwisu,
 * więc to jest dokładnie to miejsce, gdzie ma znaczenie. Apostrof z przodu
 * każe arkuszowi potraktować całość jako tekst.
 *
 * Było domknięciem wewnątrz eksportu logów; przy drugim eksporcie zrobiła się
 * z tego funkcja, żeby nie istniały dwie kopie tej samej reguły.
 */
function evk_nl_csv_bezpieczna($wartosc): string {
    $wartosc = (string) $wartosc;
    return preg_match('/^[=+\-@]/', $wartosc) ? "'" . $wartosc : $wartosc;
}

// =========================================================================
// SUBSKRYBENCI — eksport CSV
// =========================================================================

/** Ile wierszy naraz czytamy z bazy przy eksporcie. */
const EVK_NL_EKSPORT_PARTIA = 500;

/**
 * Nazwy kolumn dla pól własnych — z konfiguracji listy, jeśli ją ma.
 * Klucz pola → etykieta pokazywana w nagłówku CSV.
 */
function evk_nl_etykiety_pol(array $lista): array {
    $cfg = $lista['fields_config'] ?? [];
    if (is_string($cfg)) $cfg = json_decode($cfg, true) ?: [];
    $etykiety = [];
    foreach ((array) $cfg as $pole) {
        if (!is_array($pole)) continue;
        $klucz = (string) ($pole['key'] ?? $pole['name'] ?? '');
        if ($klucz === '') continue;
        $etykiety[$klucz] = (string) ($pole['label'] ?? $pole['title'] ?? $klucz);
    }
    return $etykiety;
}

/**
 * Klucze pól własnych występujące u eksportowanych subskrybentów.
 *
 * DLACZEGO OSOBNY PRZEBIEG PO DANYCH. Nagłówek CSV trzeba wypisać PRZED
 * wierszami, a pola własne siedzą w `fields_json` każdego subskrybenta — więc
 * bez wcześniejszego przejrzenia całości nie wiadomo, ile jest kolumn. Trzymanie
 * wszystkiego w pamięci załatwiłoby sprawę jednym przebiegiem, ale to ta sama
 * pomyłka, którą naprawialiśmy w imporcie CSV w 1.132.0: lista adresów bywa
 * długa. Dwa przebiegi o stałym zużyciu pamięci są tu tańsze niż jeden, który
 * rośnie razem z listą.
 *
 * Klucze zaczynające się od podkreślenia to zapis wewnętrzny (zgoda, jej data
 * i adres IP) — mają własne, nazwane kolumny i nie wchodzą tutaj.
 */
function evk_nl_klucze_pol(int $list_id): array {
    $klucze = [];
    $offset = 0;
    do {
        $partia = evk_nl_get_subscribers($list_id, [
            'status' => 1, 'limit' => EVK_NL_EKSPORT_PARTIA, 'offset' => $offset,
        ]);
        foreach ($partia as $sub) {
            foreach ((array) (json_decode($sub['fields_json'] ?? '{}', true) ?: []) as $k => $_) {
                if (strpos((string) $k, '_') === 0) continue;
                $klucze[(string) $k] = true;
            }
        }
        $offset += EVK_NL_EKSPORT_PARTIA;
    } while (count($partia) === EVK_NL_EKSPORT_PARTIA);

    return array_keys($klucze);
}

add_action('wp_ajax_evk_nl_export_subscribers', function () {
    evk_nl_ajax_check();
    $list_id = (int) ($_POST['list_id'] ?? 0);
    $lista   = $list_id ? evk_nl_get_list($list_id) : null;
    if (!$lista) wp_die('Nie znaleziono listy.', 404);

    $etykiety = evk_nl_etykiety_pol($lista);
    $klucze   = evk_nl_klucze_pol($list_id);

    $nazwa = sanitize_title($lista['name'] ?? '') ?: ('lista-' . $list_id);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $nazwa . '-' . current_time('Y-m-d') . '.csv"');

    while (ob_get_level()) ob_end_clean();
    $out = fopen('php://output', 'w');
    fprintf($out, "\xEF\xBB\xBF"); // BOM dla Excela

    /* TOKENU NIE EKSPORTUJEMY. To sekret linku wypisu i potwierdzenia zapisu —
       kto go ma, może wypisać kogoś z listy albo potwierdzić za niego zgodę.
       W pliku, który z założenia wychodzi poza serwis, nie ma dla niego miejsca. */
    $naglowek = ['E-mail', 'Data zapisu', 'Data potwierdzenia', 'Treść zgody', 'Data zgody'];
    foreach ($klucze as $k) $naglowek[] = $etykiety[$k] ?? $k;
    fputcsv($out, $naglowek);

    $offset = 0;
    do {
        $partia = evk_nl_get_subscribers($list_id, [
            'status' => 1, 'limit' => EVK_NL_EKSPORT_PARTIA, 'offset' => $offset,
        ]);
        foreach ($partia as $sub) {
            $pola  = (array) (json_decode($sub['fields_json'] ?? '{}', true) ?: []);
            $wiersz = [
                $sub['email'] ?? '',
                $sub['subscribed_at'] ?? '',
                $pola['_confirmed_at'] ?? '',
                $pola['_consent_text'] ?? '',
                $pola['_consent_at'] ?? '',
            ];
            foreach ($klucze as $k) $wiersz[] = $pola[$k] ?? '';
            fputcsv($out, array_map('evk_nl_csv_bezpieczna', $wiersz));
        }
        $offset += EVK_NL_EKSPORT_PARTIA;
    } while (count($partia) === EVK_NL_EKSPORT_PARTIA);

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
