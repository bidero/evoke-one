<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Newsletter — Listy i subskrybenci
 * CRUD operacje na evk_nl_lists i evk_nl_subscribers.
 */

// =========================================================================
// LISTY
// =========================================================================

function evk_nl_get_lists(): array {
    global $wpdb;
    $t = evk_nl_table('lists');
    return $wpdb->get_results("SELECT * FROM $t ORDER BY name ASC", ARRAY_A) ?: [];
}

function evk_nl_get_list(int $id): ?array {
    global $wpdb;
    $t = evk_nl_table('lists');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);
    return $row ?: null;
}

function evk_nl_create_list(string $name, array $fields_config = []): int|false {
    global $wpdb;
    $wpdb->insert(evk_nl_table('lists'), [
        'name'          => sanitize_text_field($name),
        'fields_config' => wp_json_encode($fields_config),
        'status'        => 1,
    ]);
    return $wpdb->insert_id ?: false;
}

function evk_nl_update_list(int $id, array $data): bool {
    global $wpdb;
    $allowed = ['name', 'fields_config', 'status'];
    $clean   = [];
    if (isset($data['name']))          $clean['name']          = sanitize_text_field($data['name']);
    if (isset($data['fields_config'])) $clean['fields_config'] = is_string($data['fields_config']) ? $data['fields_config'] : wp_json_encode($data['fields_config']);
    if (isset($data['status']))        $clean['status']        = (int) $data['status'];
    if (empty($clean)) return false;
    return (bool) $wpdb->update(evk_nl_table('lists'), $clean, ['id' => $id]);
}

function evk_nl_delete_list(int $id): bool {
    global $wpdb;
    // Usuń najpierw subskrybentów
    $wpdb->delete(evk_nl_table('subscribers'), ['list_id' => $id]);
    return (bool) $wpdb->delete(evk_nl_table('lists'), ['id' => $id]);
}

function evk_nl_list_count(int $list_id, bool $active_only = true): int {
    global $wpdb;
    $t = evk_nl_table('subscribers');
    if ($active_only) {
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $t WHERE list_id=%d AND status=1", $list_id
        ));
    }
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $t WHERE list_id=%d", $list_id
    ));
}

// =========================================================================
// SUBSKRYBENCI
// =========================================================================

function evk_nl_get_subscribers(int $list_id, array $args = []): array {
    global $wpdb;
    $t       = evk_nl_table('subscribers');
    $limit   = (int) ($args['limit'] ?? 50);
    $offset  = (int) ($args['offset'] ?? 0);
    $search  = $args['search'] ?? '';
    $status  = $args['status'] ?? null;

    $where = $wpdb->prepare("WHERE list_id=%d", $list_id);
    if ($status !== null) {
        $where .= $wpdb->prepare(" AND status=%d", (int) $status);
    }
    if ($search !== '') {
        $like   = '%' . $wpdb->esc_like($search) . '%';
        $where .= $wpdb->prepare(" AND email LIKE %s", $like);
    }

    return $wpdb->get_results(
        "SELECT * FROM $t $where ORDER BY subscribed_at DESC LIMIT $limit OFFSET $offset",
        ARRAY_A
    ) ?: [];
}

function evk_nl_count_subscribers(int $list_id, array $args = []): int {
    global $wpdb;
    $t      = evk_nl_table('subscribers');
    $search = $args['search'] ?? '';
    $status = $args['status'] ?? null;

    $where = $wpdb->prepare("WHERE list_id=%d", $list_id);
    if ($status !== null) $where .= $wpdb->prepare(" AND status=%d", (int) $status);
    if ($search !== '') {
        $like   = '%' . $wpdb->esc_like($search) . '%';
        $where .= $wpdb->prepare(" AND email LIKE %s", $like);
    }

    return (int) $wpdb->get_var("SELECT COUNT(*) FROM $t $where");
}

function evk_nl_get_subscriber(int $id): ?array {
    global $wpdb;
    $t   = evk_nl_table('subscribers');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE id=%d", $id), ARRAY_A);
    return $row ?: null;
}

function evk_nl_get_subscriber_by_token(string $token): ?array {
    global $wpdb;
    $t   = evk_nl_table('subscribers');
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $t WHERE token=%s", $token), ARRAY_A);
    return $row ?: null;
}

/**
 * Dodaje subskrybenta. Gdy email już istnieje na liście — zwraca ID istniejącego
 * wpisu (reaktywując wypisanego). Parametr $created (by-ref) pozwala odróżnić
 * nowy wpis od duplikatu — używane przez import do rzetelnego liczenia.
 */
function evk_nl_add_subscriber(int $list_id, string $email, array $fields = [], ?bool &$created = null): int|false {
    global $wpdb;
    $created = false;
    $email = sanitize_email($email);
    if (!is_email($email)) return false;

    $t = evk_nl_table('subscribers');

    // Sprawdź czy istnieje
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, status FROM $t WHERE list_id=%d AND email=%s", $list_id, $email
    ), ARRAY_A);

    if ($existing) {
        // Reaktywuj jeśli wypisany
        if ((int) $existing['status'] === 0) {
            $wpdb->update($t, [
                'status'           => 1,
                'unsubscribed_at'  => null,
                'fields_json'      => wp_json_encode($fields),
            ], ['id' => $existing['id']]);
            evk_nl_odblokuj($email);
        }
        return (int) $existing['id'];
    }

    $token = wp_generate_password(32, false);
    // Upewnij się unikalność tokenu
    while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE token=%s", $token))) {
        $token = wp_generate_password(32, false);
    }

    $wpdb->insert($t, [
        'list_id'    => $list_id,
        'email'      => $email,
        'fields_json'=> wp_json_encode($fields),
        'status'     => 1,
        'token'      => $token,
    ]);
    if ($wpdb->insert_id) {
        $created = true;
        /* Zapis przez formularz albo dodanie ręczne w panelu to decyzja — zdejmuje
           blokadę po usunięciu danych (RODO). Import tu nie dochodzi: adres
           zablokowany odrzuca wcześniej (evk_nl_import_emails). */
        evk_nl_odblokuj($email);
        return $wpdb->insert_id;
    }
    return false;
}

function evk_nl_unsubscribe_by_token(string $token): bool {
    global $wpdb;
    $sub = evk_nl_get_subscriber_by_token($token);
    if (!$sub) return false;

    $wpdb->update(evk_nl_table('subscribers'), [
        'status'          => 0,
        'unsubscribed_at' => current_time('mysql'),
    ], ['id' => $sub['id']]);

    return true;
}

function evk_nl_delete_subscriber(int $id): bool {
    global $wpdb;
    return (bool) $wpdb->delete(evk_nl_table('subscribers'), ['id' => $id]);
}

// =========================================================================
// IMPORT
// =========================================================================

/**
 * Adres e-mail z jednego pola — ŚCIŚLE, bez „naprawiania".
 *
 * `sanitize_email()` nie odrzuca śmieci, tylko je przerabia na coś, co przechodzi
 * `is_email()`. Wiersz z polskiego Excela („;" jako separator) czytany po
 * przecinku dawał z „jan.kowalski@firma.pl;Jan;Kowalski" adres
 * „jan.kowalski@firma.plJanKowalski" — i import liczył go jako DODANY (audyt
 * 1.229.6, tools/audyt/sondy/nl-csv.php). Wysyłka na takie adresy odbija się
 * i psuje reputację nadawcy.
 *
 * Zasada: po przycięciu spacji, cudzysłowów i nawiasów ostrych
 * `sanitize_email()` nie może niczego zmienić. Jeśli zmienia — to nie jest
 * adres, tylko pole z adresem w środku, i ma trafić do „błędnych".
 */
function evk_nl_czysty_email(string $pole): string {
    $pole = trim($pole, " \t\n\r\0\x0B\"'<>");
    if ($pole === '' || strpos($pole, '@') === false) return '';
    $czysty = sanitize_email($pole);
    return ($czysty === $pole && is_email($czysty)) ? $czysty : '';
}

/**
 * Stan adresu na liście: null = brak, 0 = wypisany, 1 = aktywny, 2 = oczekuje.
 */
function evk_nl_status_na_liscie(int $list_id, string $email): ?int {
    global $wpdb;
    $t = evk_nl_table('subscribers');
    $status = $wpdb->get_var($wpdb->prepare(
        "SELECT status FROM $t WHERE list_id=%d AND email=%s", $list_id, $email
    ));
    return $status === null ? null : (int) $status;
}

/**
 * Importuje listę adresów (tablica) do listy.
 * Zwraca ['added' => N, 'skipped' => N, 'unsubscribed' => N, 'invalid' => N]:
 * added = faktycznie NOWE wpisy w bazie; skipped = duplikaty (w pliku lub już
 * na liście); unsubscribed = adresy WYKLUCZONE, których import nie rusza;
 * invalid = nieprawidłowe adresy.
 *
 * WYPISANI ZOSTAJĄ WYPISANI. Do 1.229.6 import przywracał ich na listę jako
 * aktywnych, kasował zapis zgody i wypisu, a w wyniku pokazywał ich jako
 * „pominiętych" (audyt 1.229.6, tools/audyt/sondy/nl-wypisani.php). Wypisanie
 * to decyzja tej osoby — wraca wyłącznie sama, przez formularz zapisu.
 *
 * Od 1.233.0 to ta sama droga co import z podglądem (includes/newsletter/
 * import.php): wykluczony jest także adres wypisany z INNEJ listy, odbity przy
 * wysyłce, usunięty na żądanie RODO albo wpisany na listę wykluczeń.
 */
function evk_nl_import_emails(int $list_id, array $emails): array {
    $tabela = ['naglowek' => null, 'wiersze' => array_map(static fn($e) => [(string) $e], array_values($emails)), 'kolumny' => 1];
    $w = evk_nl_import_przejdz($list_id, $tabela, ['email' => 0, 'pola' => []], true, 0);
    return ['added' => $w['added'], 'skipped' => $w['skipped'], 'unsubscribed' => $w['unsubscribed'], 'invalid' => $w['invalid']];
}

/**
 * Separator pliku CSV: przecinek, średnik albo tabulator.
 *
 * Polski Excel zapisuje CSV ze ŚREDNIKIEM (przecinek jest tam separatorem
 * dziesiętnym), Google Sheets i Numbers z przecinkiem, a wklejka z arkusza
 * przychodzi z tabulatorem. Wygrywa ten, który dzieli pierwsze wiersze na
 * najwięcej pól; bez żadnego podziału (jedna kolumna) — przecinek.
 */
function evk_nl_csv_separator(array $linie): string {
    $proba = array_slice(array_values(array_filter($linie, static fn($l) => trim($l) !== '')), 0, 20);
    $wynik = [',' => 0, ';' => 0, "\t" => 0];
    foreach (array_keys($wynik) as $sep) {
        foreach ($proba as $linia) $wynik[$sep] += count(str_getcsv($linia, $sep, '"', '')) - 1;
    }
    arsort($wynik);
    $najlepszy = (string) array_key_first($wynik);
    return $wynik[$najlepszy] > 0 ? $najlepszy : ',';
}

/**
 * Parsuje CSV: separator wykrywany, BOM z Excela zdejmowany, adres brany
 * z PIERWSZEGO pola, które jest adresem — nie z pierwszej kolumny na ślepo,
 * więc plik z imieniem przed adresem też się wczyta.
 *
 * Wiersz bez adresu: pierwszy wiersz bez „@" to nagłówek i wypada po cichu;
 * każdy inny idzie dalej jako błędny, żeby liczba „błędnych" mówiła prawdę.
 */
function evk_nl_parse_csv(string $csv_content): array {
    $tresc = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv_content);
    $linie = preg_split('/\r\n|\r|\n/', trim($tresc)) ?: [];
    $sep   = evk_nl_csv_separator($linie);

    $emails   = [];
    $pierwszy = true;
    foreach ($linie as $linia) {
        if (trim($linia) === '') continue;
        $pola = array_map(static fn($p) => trim((string) $p), str_getcsv($linia, $sep, '"', ''));

        $adres = '';
        foreach ($pola as $pole) {
            $adres = evk_nl_czysty_email($pole);
            if ($adres !== '') break;
        }

        if ($adres !== '') {
            $emails[] = $adres;
        } elseif (!($pierwszy && strpos($linia, '@') === false)) {
            // Błędny wiersz: pole z „@", a jak go nie ma — pierwsze niepuste.
            $z_malpa  = array_values(array_filter($pola, static fn($p) => strpos($p, '@') !== false));
            $niepuste = array_values(array_filter($pola, static fn($p) => $p !== ''));
            $emails[] = $z_malpa[0] ?? ($niepuste[0] ?? $linia);
        }
        $pierwszy = false;
    }
    return $emails;
}

/**
 * Parsuje textarea — jeden adres na linię, także w postaci
 * „Jan Kowalski <jan@firma.pl>" albo „jan@firma.pl; Jan".
 *
 * Do 1.229.6 cała linia szła przez `sanitize_email()`, które z „Jan Kowalski
 * <jan@firma.pl>" robiło „JanKowalskijan@firma.pl", a linie bez adresu znikały
 * bez śladu. Teraz adres jest wyłuskiwany, a linia, w której go nie ma, liczy
 * się jako błędna.
 */
function evk_nl_parse_textarea(string $text): array {
    $emails = [];
    foreach (preg_split('/\r\n|\r|\n/', trim($text)) ?: [] as $linia) {
        if (trim($linia) === '') continue;
        preg_match_all('/[^\s,;<>"\'()\[\]]+@[^\s,;<>"\'()\[\]]+/u', $linia, $m);
        $adres = '';
        foreach ($m[0] as $kandydat) {
            $adres = evk_nl_czysty_email($kandydat);
            if ($adres !== '') break;
        }
        $emails[] = $adres !== '' ? $adres : trim($linia);
    }
    return $emails;
}

// =========================================================================
// AKTYWNI SUBSKRYBENCI DLA KAMPANII
// =========================================================================

/**
 * Pobiera wszystkich aktywnych subskrybentów z podanych list (bez duplikatów emaili).
 */
function evk_nl_get_campaign_subscribers(array $list_ids): array {
    if (empty($list_ids)) return [];
    global $wpdb;
    $t            = evk_nl_table('subscribers');
    $ids_escaped  = implode(',', array_map('intval', $list_ids));

    // Dedup po email — bierzemy SPOJNY wiersz (MIN(id) i jego wlasny token/fields)
    return $wpdb->get_results(
        "SELECT s.id, s.email, s.token, s.fields_json
         FROM $t s
         INNER JOIN (
             SELECT MIN(id) AS mid FROM $t
             WHERE list_id IN ($ids_escaped) AND status=1
             GROUP BY email
         ) m ON s.id = m.mid",
        ARRAY_A
    ) ?: [];
}

// =========================================================================
// DOUBLE OPT-IN — pending subscriber + potwierdzenie
// =========================================================================

/**
 * Dodaje subskrybenta w stanie oczekujacym (status=2) do potwierdzenia.
 * Zwraca ['ok'=>bool, 'status'=>int, 'token'=>string].
 */
function evk_nl_add_pending_subscriber(int $list_id, string $email, array $consent = []): array {
    global $wpdb;
    $email = sanitize_email($email);
    if (!is_email($email)) return ['ok' => false];

    $t = evk_nl_table('subscribers');
    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT id, status, token FROM $t WHERE list_id=%d AND email=%s", $list_id, $email
    ), ARRAY_A);

    if ($existing) {
        if ((int) $existing['status'] === 1) {
            return ['ok' => true, 'status' => 1, 'token' => $existing['token']];
        }
        // pending lub wypisany → ustaw pending i odswiez zgode
        $wpdb->update($t, [
            'status'          => 2,
            'fields_json'     => wp_json_encode($consent),
            'unsubscribed_at' => null,
        ], ['id' => $existing['id']]);
        return ['ok' => true, 'status' => 2, 'token' => $existing['token']];
    }

    $token = wp_generate_password(32, false);
    while ($wpdb->get_var($wpdb->prepare("SELECT id FROM $t WHERE token=%s", $token))) {
        $token = wp_generate_password(32, false);
    }
    $wpdb->insert($t, [
        'list_id'     => $list_id,
        'email'       => $email,
        'fields_json' => wp_json_encode($consent),
        'status'      => 2,
        'token'       => $token,
    ]);
    return ['ok' => (bool) $wpdb->insert_id, 'status' => 2, 'token' => $token];
}

/**
 * Potwierdza zapis (status 2 -> 1) i zapisuje czas potwierdzenia.
 */
function evk_nl_confirm_subscriber(string $token): bool {
    global $wpdb;
    $sub = evk_nl_get_subscriber_by_token($token);
    if (!$sub) return false;
    $fields = json_decode($sub['fields_json'] ?? '{}', true) ?: [];
    $fields['_confirmed_at'] = current_time('mysql');
    evk_nl_odblokuj((string) $sub['email']);   // potwierdzenie samej osoby zdejmuje blokadę RODO
    return (bool) $wpdb->update(evk_nl_table('subscribers'), [
        'status'          => 1,
        'unsubscribed_at' => null,
        'fields_json'     => wp_json_encode($fields),
    ], ['id' => $sub['id']]);
}
