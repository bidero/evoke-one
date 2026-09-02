<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Security: Limit prób logowania
 * Blokuje adresy IP po przekroczeniu limitu nieudanych prób.
 * Obsługuje: wp-login.php, XML-RPC, Bricks Builder form submit.
 */

// =========================================================================
// HELPERY
// =========================================================================

function evk_login_get_ip(): string {
    return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '');
}

function evk_login_is_enabled(): bool {
    return !empty(get_option('evk_security', [])['limit_login_enabled']);
}

function evk_login_max_attempts(): int {
    return max(1, (int)(get_option('evk_security', [])['max_attempts'] ?? 5));
}

function evk_login_reset_hours(): int {
    return max(1, (int)(get_option('evk_security', [])['reset_hours'] ?? 24));
}

/**
 * Ile adresów najwyżej trzymamy w każdej z dwóch tablic.
 *
 * DLACZEGO W OGÓLE. `evk_failed_logins` i `evk_blocked_ips` są indeksowane
 * adresem IP i do 1.131.0 nie były przycinane niczym poza wygasaniem
 * pojedynczych wpisów przy okazji odczytu. Kto ma pulę IPv6 — a ma ją każdy
 * VPS — generował nieudane logowania z tysięcy adresów i rozdmuchiwał opcję do
 * megabajtów. Opcje były przy tym AUTOLOADOWANE, czyli wczytywane przy KAŻDYM
 * żądaniu do serwisu: ochrona przed zgadywaniem haseł zamieniała się w dźwignię
 * do przewrócenia strony.
 *
 * Limit obcina od najstarszych. Przy ataku z tysiąca adresów starsze blokady
 * wypadną wcześniej, niż wygasłyby z zegara — to świadoma cena za to, żeby
 * rozmiar opcji miał sufit.
 */
const EVK_LOGIN_MAX_WPISOW = 500;

/**
 * Wyrzuca wpisy starsze niż okno resetu i przycina resztę do sufitu.
 * `$pole` to nazwa pola z czasem: `last` dla prób, `blocked_at` dla blokad.
 */
function evk_login_przytnij(array $wpisy, string $pole): array {
    $prog = current_time('timestamp') - (evk_login_reset_hours() * HOUR_IN_SECONDS);
    foreach ($wpisy as $ip => $dane) {
        if ((int) ($dane[$pole] ?? 0) < $prog) unset($wpisy[$ip]);
    }
    if (count($wpisy) > EVK_LOGIN_MAX_WPISOW) {
        uasort($wpisy, static function ($a, $b) use ($pole) {
            return (int) ($b[$pole] ?? 0) <=> (int) ($a[$pole] ?? 0);
        });
        $wpisy = array_slice($wpisy, 0, EVK_LOGIN_MAX_WPISOW, true);
    }
    return $wpisy;
}

/**
 * Zapis obu tablic. Trzeci argument `false` zdejmuje AUTOLOAD — bez niego
 * WordPress wczytuje te opcje przy każdym żądaniu do serwisu, także wtedy, gdy
 * nikt się nie loguje. Potrzebne są wyłącznie na ścieżce logowania.
 */
function evk_login_zapisz(string $opcja, array $wpisy, string $pole): void {
    update_option($opcja, evk_login_przytnij($wpisy, $pole), false);
}

function evk_login_get_blocked(): array {
    $v = get_option('evk_blocked_ips', []);
    return is_array($v) ? $v : [];
}

function evk_login_block_expired(array $block): bool {
    return (current_time('timestamp') - $block['blocked_at'])
        > (evk_login_reset_hours() * HOUR_IN_SECONDS);
}

function evk_login_is_blocked(string $ip): bool {
    if (!evk_login_is_enabled()) return false;
    $blocked = evk_login_get_blocked();
    if (!isset($blocked[$ip])) return false;

    if (evk_login_block_expired($blocked[$ip])) {
        unset($blocked[$ip]);
        evk_login_zapisz('evk_blocked_ips', $blocked, 'blocked_at');
        $attempts = get_option('evk_failed_logins', []);
        if (isset($attempts[$ip])) {
            unset($attempts[$ip]);
            evk_login_zapisz('evk_failed_logins', $attempts, 'last');
        }
        return false;
    }
    return true;
}

function evk_login_get_block_message(string $ip): string {
    $blocked    = evk_login_get_blocked();
    $remaining  = evk_login_reset_hours() * HOUR_IN_SECONDS
                  - (current_time('timestamp') - ($blocked[$ip]['blocked_at'] ?? current_time('timestamp')));
    $hours_left = max(1, (int) ceil($remaining / HOUR_IN_SECONDS));
    $hours_str  = $hours_left === 1 ? 'godzinę' : ($hours_left < 5 ? 'godziny' : 'godzin');

    $sec    = evk_security_get();
    $custom = trim($sec['limit_login_message'] ?? '');

    if ($custom) {
        // Podstaw dostępne zmienne
        $custom = str_replace(
            ['{hours}', '{hours_str}'],
            [$hours_left, $hours_str],
            $custom
        );
        /* `span` BEZ atrybutu `style`. Komunikat blokady wyświetla się na
           PUBLICZNEJ stronie logowania, a dopuszczony `style` pozwalał wstrzyknąć
           w nią dowolny CSS — wystarczyło zapisać go w ustawieniach. Formatowanie
           tekstu zostaje, malowanie strony nie. */
        return wp_kses($custom, [
            'strong' => [], 'em' => [], 'br' => [],
            'a'      => ['href' => [], 'title' => []],
            'p'      => [], 'span' => [],
        ]);
    }

    return sprintf(
        '<strong>Dostęp zablokowany.</strong> Zbyt wiele nieudanych prób logowania. Spróbuj ponownie za <strong>%d %s</strong>.',
        $hours_left, $hours_str
    );
}

function evk_login_die(string $ip): void {
    // Przekieruj na stronę logowania z parametrem — komunikat pojawi się
    // przez login_errors zamiast wp_die (zachowuje style i padding WP)
    $redirect = add_query_arg('evk_blocked', '1', wp_login_url());
    wp_safe_redirect($redirect);
    exit;
}

function evk_login_block_ip(string $ip, string $username = ''): void {
    $blocked  = evk_login_get_blocked();
    $attempts = get_option('evk_failed_logins', []);
    $blocked[$ip] = [
        'blocked_at' => current_time('timestamp'),
        'username'   => sanitize_text_field($username),
        'attempts'   => (int)($attempts[$ip]['count'] ?? 0),
    ];
    evk_login_zapisz('evk_blocked_ips', $blocked, 'blocked_at');
    do_action('evk_ip_blocked', $ip, $username);
}

function evk_login_record_failure(string $username): void {
    if (!evk_login_is_enabled()) return;
    $ip       = evk_login_get_ip();
    $attempts = get_option('evk_failed_logins', []);

    if (!isset($attempts[$ip])) {
        $attempts[$ip] = ['count' => 0, 'last' => current_time('timestamp')];
    }

    $elapsed = current_time('timestamp') - $attempts[$ip]['last'];
    if ($elapsed > evk_login_reset_hours() * HOUR_IN_SECONDS && !evk_login_is_blocked($ip)) {
        $attempts[$ip]['count'] = 0;
    }

    $attempts[$ip]['count']++;
    $attempts[$ip]['last'] = current_time('timestamp');
    evk_login_zapisz('evk_failed_logins', $attempts, 'last');

    if ($attempts[$ip]['count'] >= evk_login_max_attempts()) {
        evk_login_block_ip($ip, $username);
    }
}

function evk_login_active_blocks(): array {
    $active = [];
    foreach (evk_login_get_blocked() as $ip => $data) {
        if (!evk_login_block_expired($data)) $active[$ip] = $data;
    }
    return $active;
}

// =========================================================================
// HOOKI BLOKUJĄCE
// =========================================================================

// 1. wp-login.php — sprawdź blokadę przy inicjalizacji
add_action('login_init', function () {
    if (!evk_login_is_enabled()) return;
    $ip = evk_login_get_ip();
    if (!evk_login_is_blocked($ip)) return;

    // Jeśli nie jesteśmy jeszcze z parametrem evk_blocked — przekieruj
    if (empty($_GET['evk_blocked'])) {
        evk_login_die($ip);
    }
    // Jeśli już jesteśmy na stronie logowania z ?evk_blocked=1 — nie rób nic,
    // login_errors pokaże komunikat poniżej
});

// Wstrzyknij komunikat w standardowy formularz logowania WP
add_filter('login_errors', function (string $errors): string {
    if (empty($_GET['evk_blocked'])) return $errors;
    $ip = evk_login_get_ip();
    if (!evk_login_is_blocked($ip)) return $errors;
    return evk_login_get_block_message($ip);
});

// Zablokuj wysłanie formularza nawet jeśli ktoś ominął redirect
add_filter('authenticate', function ($user, $username, $password) {
    if (!evk_login_is_enabled()) return $user;
    $ip = evk_login_get_ip();
    if (!evk_login_is_blocked($ip)) return $user;
    return new WP_Error('evk_ip_blocked', evk_login_get_block_message($ip));
}, 30, 3);

// 2. XML-RPC
add_filter('authenticate', function ($user, $username, $password) {
    if (!evk_login_is_enabled()) return $user;
    if (!defined('XMLRPC_REQUEST') || !XMLRPC_REQUEST) return $user;
    $ip = evk_login_get_ip();
    if (evk_login_is_blocked($ip)) {
        return new WP_Error('evk_too_many_retries', wp_strip_all_tags(evk_login_get_block_message($ip)));
    }
    return $user;
}, 1, 3);

// 3. Bricks Builder (action=bricks_form_submit)
add_action('wp_ajax_nopriv_bricks_form_submit', function () {
    if (!evk_login_is_enabled()) return;
    $ip = evk_login_get_ip();
    if (!evk_login_is_blocked($ip)) return;
    wp_send_json([
        'success' => false,
        'data'    => [
            'action'  => 'login',
            'type'    => 'error',
            'message' => wp_strip_all_tags(evk_login_get_block_message($ip)),
        ],
    ], 403);
}, 1);

// 4. Zliczaj nieudane próby
add_action('wp_login_failed', function ($username) {
    evk_login_record_failure((string) $username);
});

// 5. Reset po udanym logowaniu (ban pozostaje)
add_action('wp_login', function ($username) {
    if (!evk_login_is_enabled()) return;
    $ip       = evk_login_get_ip();
    $attempts = get_option('evk_failed_logins', []);
    if (isset($attempts[$ip])) {
        unset($attempts[$ip]);
        evk_login_zapisz('evk_failed_logins', $attempts, 'last');
    }
}, 10, 2);

// =========================================================================
// AJAX — odblokuj IP ręcznie
// =========================================================================

add_action('wp_ajax_evk_unblock_ip', function () {
    check_ajax_referer('evk_security_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.');
    $ip = sanitize_text_field(wp_unslash($_POST['ip'] ?? ''));
    $blocked = evk_login_get_blocked();
    unset($blocked[$ip]);
    evk_login_zapisz('evk_blocked_ips', $blocked, 'blocked_at');
    $attempts = get_option('evk_failed_logins', []);
    unset($attempts[$ip]);
    evk_login_zapisz('evk_failed_logins', $attempts, 'last');
    wp_send_json_success('Odblokowano.');
});

add_action('wp_ajax_evk_clear_all_blocks', function () {
    check_ajax_referer('evk_security_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Brak uprawnień.');
    delete_option('evk_blocked_ips');
    delete_option('evk_failed_logins');
    wp_send_json_success('Wyczyszczono wszystkie blokady.');
});
