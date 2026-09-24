<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — RODO: eksport i usuwanie danych osoby (Narzędzia → Eksport /
 * Usuń dane osobowe w WordPressie) oraz tekst do polityki prywatności.
 * Wydanie 1.232.0, audyt 1.229.6 — wcześniej wtyczka nie zgłaszała
 * WordPressowi żadnych swoich danych.
 *
 * Co wtyczka wie o osobie, po adresie e-mail:
 *   — newsletter: zapisy na listy (z zapisem zgody: czas, IP, treść),
 *     wysyłki (wysłano, otwarto), otwarcia i kliknięcia,
 *   — log wysyłki e-maili (SMTP): czas, temat, wynik,
 *   — ochrona logowania: blokada adresu IP, gdy próbowano się zalogować
 *     loginem albo adresem tej osoby (same próby mają tylko IP i licznik).
 * Logi 404 mają adres IP i przeglądarkę, ale nie adres e-mail — nie da się
 * ich przypisać osobie. Polityka prywatności mówi o nich wprost.
 *
 * USUNIĘCIE Z NEWSLETTERA ZOSTAWIA SKRÓT ADRESU (decyzja zgłaszającego):
 * jednokierunkowy HMAC z losowym kluczem strony, na liście blokady importu.
 * Bez niego import z pliku dopisałby osobę z powrotem. Adresu ze skrótu nie da
 * się odtworzyć; blokadę zdejmuje wyłącznie zapis samej osoby (formularz,
 * potwierdzenie) albo dodanie ręczne w panelu.
 */

// =========================================================================
// SKRÓT ADRESU — blokada importu po usunięciu danych
// =========================================================================

function evk_nl_skrot(string $email): string {
    $klucz = (string) get_option('evk_nl_skrot_klucz', '');
    if (strlen($klucz) < 32) {
        $klucz = bin2hex(random_bytes(32));
        update_option('evk_nl_skrot_klucz', $klucz, false);
    }
    return hash_hmac('sha256', strtolower(trim($email)), $klucz);
}

function evk_nl_zablokowany(string $email): bool {
    $lista = get_option('evk_nl_zablokowane', []);
    if (!is_array($lista) || !$lista) return false;   // bez listy nie ma czego liczyć
    return isset($lista[evk_nl_skrot($email)]);
}

function evk_nl_zablokuj(string $email): void {
    $lista = get_option('evk_nl_zablokowane', []);
    $lista = is_array($lista) ? $lista : [];
    $lista[evk_nl_skrot($email)] = gmdate('Y-m-d');   // kiedy — bez adresu
    update_option('evk_nl_zablokowane', $lista, false);
}

function evk_nl_odblokuj(string $email): void {
    $lista = get_option('evk_nl_zablokowane', []);
    if (!is_array($lista) || !$lista) return;
    $skrot = evk_nl_skrot($email);
    if (!isset($lista[$skrot])) return;
    unset($lista[$skrot]);
    update_option('evk_nl_zablokowane', $lista, false);
}

// =========================================================================
// REJESTRACJA W NARZĘDZIACH PRYWATNOŚCI WORDPRESSA
// =========================================================================

add_filter('wp_privacy_personal_data_exporters', static function (array $e): array {
    $e['evoke-one-newsletter'] = ['exporter_friendly_name' => 'Evoke ONE — newsletter', 'callback' => 'evk_rodo_eksport_newsletter'];
    $e['evoke-one-smtp']       = ['exporter_friendly_name' => 'Evoke ONE — log wysyłki e-maili', 'callback' => 'evk_rodo_eksport_smtp'];
    $e['evoke-one-logowanie']  = ['exporter_friendly_name' => 'Evoke ONE — ochrona logowania', 'callback' => 'evk_rodo_eksport_logowanie'];
    return $e;
});

add_filter('wp_privacy_personal_data_erasers', static function (array $e): array {
    $e['evoke-one-newsletter'] = ['eraser_friendly_name' => 'Evoke ONE — newsletter', 'callback' => 'evk_rodo_usun_newsletter'];
    $e['evoke-one-smtp']       = ['eraser_friendly_name' => 'Evoke ONE — log wysyłki e-maili', 'callback' => 'evk_rodo_usun_smtp'];
    $e['evoke-one-logowanie']  = ['eraser_friendly_name' => 'Evoke ONE — ochrona logowania', 'callback' => 'evk_rodo_usun_logowanie'];
    return $e;
});

add_action('admin_init', static function (): void {
    if (function_exists('wp_add_privacy_policy_content')) wp_add_privacy_policy_content('Evoke ONE', evk_rodo_tekst_polityki());
});

function evk_rodo_pusty_wynik(): array {
    return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true];
}

function evk_rodo_tabela_jest(string $nazwa): bool {
    global $wpdb;
    return (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like(evk_nl_table($nazwa))));
}

/** Pary nazwa–wartość do eksportu WordPressa, bez pustych pozycji. */
function evk_rodo_pola(array $pary): array {
    $out = [];
    foreach ($pary as $nazwa => $wartosc) {
        if ($wartosc === null || $wartosc === '') continue;
        $out[] = ['name' => $nazwa, 'value' => (string) $wartosc];
    }
    return $out;
}

// =========================================================================
// NEWSLETTER
// =========================================================================

function evk_rodo_eksport_newsletter(string $email, int $strona = 1): array {
    global $wpdb;
    if (!evk_rodo_tabela_jest('subscribers')) return ['data' => [], 'done' => true];
    $sub = evk_nl_table('subscribers');
    $lis = evk_nl_table('lists');
    $kol = evk_nl_table('queue');
    $log = evk_nl_table('logs');
    $kam = evk_nl_table('campaigns');
    $stany  = [0 => 'wypisany', 1 => 'aktywny', 2 => 'czeka na potwierdzenie'];
    $zgoda  = ['_consent_at' => 'Zgoda — kiedy', '_consent_ip' => 'Zgoda — adres IP', '_consent_text' => 'Zgoda — treść',
               '_consent_source' => 'Zgoda — skąd', '_confirmed_at' => 'Potwierdzono'];
    $dane = [];

    $zapisy = $wpdb->get_results($wpdb->prepare(
        "SELECT s.*, l.name AS lista FROM $sub s LEFT JOIN $lis l ON l.id = s.list_id WHERE s.email = %s", $email), ARRAY_A) ?: [];
    foreach ($zapisy as $z) {
        $pola = ['Lista' => $z['lista'], 'Adres e-mail' => $z['email'], 'Stan' => $stany[(int) $z['status']] ?? $z['status'],
                 'Zapisano' => $z['subscribed_at'], 'Wypisano' => $z['unsubscribed_at']];
        foreach ((json_decode((string) $z['fields_json'], true) ?: []) as $k => $v) {
            if (is_scalar($v)) $pola[$zgoda[$k] ?? (string) $k] = $v;
        }
        $dane[] = ['group_id' => 'evk-newsletter', 'group_label' => 'Newsletter — zapisy',
                   'item_id' => 'evk-nl-zapis-' . $z['id'], 'data' => evk_rodo_pola($pola)];

        $wysylki = $wpdb->get_results($wpdb->prepare(
            "SELECT q.*, c.name AS kampania FROM $kol q LEFT JOIN $kam c ON c.id = q.campaign_id WHERE q.subscriber_id = %d", $z['id']), ARRAY_A) ?: [];
        foreach ($wysylki as $w) {
            $dane[] = ['group_id' => 'evk-newsletter-wysylki', 'group_label' => 'Newsletter — wysyłki',
                       'item_id' => 'evk-nl-wysylka-' . $w['id'],
                       'data' => evk_rodo_pola(['Kampania' => $w['kampania'], 'Stan' => $w['status'], 'Wysłano' => $w['sent_at'], 'Otwarto' => $w['opened_at']])];
        }
        $zdarzenia = $wpdb->get_results($wpdb->prepare(
            "SELECT g.*, c.name AS kampania FROM $log g LEFT JOIN $kam c ON c.id = g.campaign_id WHERE g.subscriber_id = %d", $z['id']), ARRAY_A) ?: [];
        foreach ($zdarzenia as $g) {
            $d = json_decode((string) $g['data_json'], true) ?: [];
            $dane[] = ['group_id' => 'evk-newsletter-zdarzenia', 'group_label' => 'Newsletter — otwarcia i kliknięcia',
                       'item_id' => 'evk-nl-zdarzenie-' . $g['id'],
                       'data' => evk_rodo_pola(['Kampania' => $g['kampania'], 'Zdarzenie' => $g['event'], 'Kiedy' => $g['created_at'],
                                                'Adres' => is_scalar($d['url'] ?? null) ? $d['url'] : null])];
        }
    }
    /* Lista wykluczeń (1.233.0) to też dane osoby: adres, powód i data —
       także gdy na żadnej liście już jej nie ma. */
    $wyk = evk_nl_wykluczenia()[strtolower($email)] ?? null;
    if (is_array($wyk)) {
        $powody = ['odbity' => 'adres odrzucony przez serwer odbiorcy przy wysyłce', 'reczny' => 'dopisany przez administratora'];
        $dane[] = ['group_id' => 'evk-newsletter-wykluczenia', 'group_label' => 'Newsletter — lista wykluczeń',
                   'item_id' => 'evk-nl-wykluczenie', 'data' => evk_rodo_pola(['Adres e-mail' => $email,
                   'Powód' => $powody[$wyk['powod'] ?? ''] ?? (string) ($wyk['powod'] ?? ''), 'Od kiedy' => $wyk['kiedy'] ?? ''])];
    }
    return ['data' => $dane, 'done' => true];
}

function evk_rodo_usun_newsletter(string $email, int $strona = 1): array {
    global $wpdb;
    $wynik = evk_rodo_pusty_wynik();
    if (!evk_rodo_tabela_jest('subscribers')) return $wynik;
    $ids = array_map('intval', $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . evk_nl_table('subscribers') . ' WHERE email = %s', $email)) ?: []);
    /* Wpis na liście wykluczeń znika razem z resztą — przed ponownym importem
       chroni dalej skrót adresu (evk_nl_zablokuj niżej), bez samego adresu. */
    $byl_wykluczony = evk_nl_usun_wykluczenie($email);
    if (!$ids) {
        if ($byl_wykluczony) {
            evk_nl_zablokuj($email);
            $wynik['items_removed'] = true;
        }
        return $wynik;
    }
    $lista = implode(',', $ids);
    $wpdb->query('DELETE FROM ' . evk_nl_table('queue') . " WHERE subscriber_id IN ($lista)");
    $wpdb->query('DELETE FROM ' . evk_nl_table('logs') . " WHERE subscriber_id IN ($lista)");
    $wpdb->query('DELETE FROM ' . evk_nl_table('subscribers') . " WHERE id IN ($lista)");
    evk_nl_zablokuj($email);
    $wynik['items_removed'] = true;
    $wynik['messages'][] = 'Newsletter: adres usunięty z list razem z historią wysyłek. Zostaje tylko jednokierunkowy skrót adresu, żeby import z pliku nie dopisał go ponownie.';
    return $wynik;
}

// =========================================================================
// LOG WYSYŁKI E-MAILI (SMTP)
// =========================================================================

/** Adresy z pola „do" wpisu logu (także w postaci „Imię <adres>"), małymi literami. */
function evk_rodo_adresy(string $do): array {
    $out = [];
    foreach (explode(',', $do) as $a) {
        $a = trim($a);
        if (preg_match('/<([^>]+)>/', $a, $m)) $a = $m[1];
        if ($a !== '') $out[] = strtolower(trim($a));
    }
    return $out;
}

function evk_rodo_eksport_smtp(string $email, int $strona = 1): array {
    $dane = [];
    foreach ((array) get_option('evk_smtp_log', []) as $i => $w) {
        if (!is_array($w) || !in_array(strtolower($email), evk_rodo_adresy((string) ($w['to'] ?? '')), true)) continue;
        $dane[] = ['group_id' => 'evk-smtp', 'group_label' => 'Log wysyłki e-maili', 'item_id' => 'evk-smtp-' . $i,
                   'data' => evk_rodo_pola(['Czas' => $w['time'] ?? '', 'Temat' => $w['subject'] ?? '',
                                            'Wynik' => !empty($w['success']) ? 'wysłano' : 'błąd', 'Błąd' => $w['error'] ?? ''])];
    }
    return ['data' => $dane, 'done' => true];
}

function evk_rodo_usun_smtp(string $email, int $strona = 1): array {
    $wynik = evk_rodo_pusty_wynik();
    $log = get_option('evk_smtp_log', []);
    if (!is_array($log) || !$log) return $wynik;
    $szukany = strtolower($email);
    $nowy = [];
    foreach ($log as $w) {
        $adresy = is_array($w) ? evk_rodo_adresy((string) ($w['to'] ?? '')) : [];
        if (!in_array($szukany, $adresy, true)) { $nowy[] = $w; continue; }
        $wynik['items_removed'] = true;
        /* Wpis do kilku osób zostaje — bez tej jednej, a pozostali odbiorcy
           w swojej pierwotnej postaci („Imię <adres>"). */
        $reszta = array_values(array_filter(array_map('trim', explode(',', (string) $w['to'])),
            static function ($a) use ($szukany) { return $a !== '' && evk_rodo_adresy($a) !== [$szukany]; }));
        if ($reszta) { $w['to'] = implode(', ', $reszta); $nowy[] = $w; }
    }
    if ($wynik['items_removed']) update_option('evk_smtp_log', $nowy);
    return $wynik;
}

// =========================================================================
// OCHRONA LOGOWANIA
// =========================================================================

/** Nazwy, którymi ta osoba mogła się logować: login i adres. */
function evk_rodo_nazwy_logowania(string $email): array {
    $nazwy = [strtolower($email)];
    $u = get_user_by('email', $email);
    if ($u) $nazwy[] = strtolower($u->user_login);
    return array_values(array_unique($nazwy));
}

/** Blokady IP założone przy próbie logowania nazwą tej osoby: ip => wpis. */
function evk_rodo_blokady(string $email): array {
    $nazwy = evk_rodo_nazwy_logowania($email);
    $out = [];
    foreach ((array) get_option('evk_blocked_ips', []) as $ip => $b) {
        if (is_array($b) && in_array(strtolower((string) ($b['username'] ?? '')), $nazwy, true)) $out[(string) $ip] = $b;
    }
    return $out;
}

function evk_rodo_eksport_logowanie(string $email, int $strona = 1): array {
    $dane = [];
    foreach (evk_rodo_blokady($email) as $ip => $b) {
        $dane[] = ['group_id' => 'evk-logowanie', 'group_label' => 'Ochrona logowania — blokady', 'item_id' => 'evk-blokada-' . md5($ip),
                   'data' => evk_rodo_pola(['Adres IP' => $ip, 'Nazwa użyta przy logowaniu' => $b['username'] ?? '',
                                            'Zablokowano' => !empty($b['blocked_at']) ? wp_date('Y-m-d H:i:s', (int) $b['blocked_at']) : '',
                                            'Nieudane próby' => $b['attempts'] ?? ''])];
    }
    return ['data' => $dane, 'done' => true];
}

function evk_rodo_usun_logowanie(string $email, int $strona = 1): array {
    $wynik = evk_rodo_pusty_wynik();
    $ips = array_keys(evk_rodo_blokady($email));
    if (!$ips) return $wynik;
    foreach (['evk_blocked_ips', 'evk_failed_logins'] as $opcja) {
        $v = get_option($opcja, []);
        if (!is_array($v)) continue;
        foreach ($ips as $ip) unset($v[$ip]);
        update_option($opcja, $v, false);
    }
    $wynik['items_removed'] = true;
    return $wynik;
}

// =========================================================================
// TEKST DO POLITYKI PRYWATNOŚCI
// =========================================================================

/**
 * Propozycja tekstu (Ustawienia → Prywatność → Przewodnik po polityce).
 * Tylko o modułach, które na tej stronie działają — tekst o newsletterze na
 * stronie bez newslettera wprowadzałby w błąd.
 */
function evk_rodo_tekst_polityki(): string {
    $akapity = [];
    $nl = (array) get_option('evk_newsletter', []);
    if (!empty($nl['enabled'])) {
        $akapity[] = '<h3>Newsletter</h3><p>Zapisując się do newslettera, podajesz adres e-mail (i dane z formularza zapisu, np. imię). '
            . 'Zapisujemy czas i treść zgody oraz adres IP, z którego ją wyrażono. Wiadomości zawierają znacznik otwarcia i śledzone '
            . 'odnośniki — zapisujemy, czy i kiedy otworzono wiadomość i w które odnośniki kliknięto. Wypisać się można w każdej chwili '
            . 'odnośnikiem w każdej wiadomości. Zapis zgody zawiera też miejsce, z którego przyszedł (formularz na stronie, import z pliku). '
            . 'Adresy, na które wiadomości nie da się doręczyć (skrzynka nie istnieje), i adresy wyłączone z wysyłki przechowujemy na liście '
            . 'wykluczeń, żeby nie wysyłać na nie ponownie. Po żądaniu usunięcia danych usuwamy adres razem z historią wysyłek i wpisem na '
            . 'liście wykluczeń; zostaje wyłącznie jednokierunkowy skrót adresu, z którego nie da się go odtworzyć, a który nie pozwala '
            . 'dopisać go ponownie z pliku.</p>';
    }
    $smtp = (array) get_option('evk_smtp', []);
    if (!empty($smtp['enabled'])) {
        $akapity[] = '<h3>Wysyłka e-maili</h3><p>Serwis prowadzi dziennik wysłanych wiadomości: odbiorca, temat, czas i wynik wysyłki '
            . '(ostatnie ' . (int) ($smtp['log_max'] ?? 100) . ' wpisów).</p>';
    }
    $bez = (array) get_option('evk_security', []);
    if (!empty($bez['limit_login_enabled'])) {
        $akapity[] = '<h3>Ochrona logowania</h3><p>Przy nieudanych próbach logowania zapisujemy adres IP i liczbę prób. Po przekroczeniu '
            . 'limitu adres IP jest blokowany na ' . (int) ($bez['reset_hours'] ?? 24) . ' godz., a zapis blokady zawiera nazwę użytą '
            . 'przy logowaniu.</p>';
    }
    if (get_option('evk_404_enabled')) {
        $akapity[] = '<h3>Nieistniejące strony</h3><p>Przy wejściu na nieistniejącą stronę zapisujemy jej adres, adres IP, przeglądarkę '
            . 'i stronę, z której nastąpiło przejście (ostatnie ' . (int) get_option('evk_404_max_logs', 200) . ' wpisów). Te zapisy nie są '
            . 'powiązane z adresem e-mail.</p>';
    }
    $kopie = (array) get_option('evk_backup', []);
    if (!empty($kopie['enabled'])) {
        $akapity[] = '<h3>Kopie zapasowe</h3><p>Kopie zapasowe zawierają pełną bazę danych serwisu, a więc także dane osobowe. '
            . 'Przechowujemy je na serwerze' . (get_option('evk_backup_gdrive') ? ' i na Dysku Google' : '') . ' przez okres wynikający '
            . 'z ustawień przechowywania kopii.</p>';
    }
    return implode("\n", $akapity);
}
