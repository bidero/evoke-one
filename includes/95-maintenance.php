<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł Konserwacji
 */

// =========================================================================
// REJESTRACJA USTAWIEŃ
// =========================================================================

add_action('admin_init', function () {
    // UWAGA: 'maintenance_mode' celowo NIE jest rejestrowany w tej grupie.
    // Jest zapisywany wyłącznie przez AJAX toggle (evk_ajax_toggle) — rejestracja
    // powodowała zerowanie stanu przy zapisie formularza options.php.
    // Sanityzacja przy zapisie. Cztery te ustawienia szły do 1.127.0 do bazy
    // takie, jakie przyszły z formularza — a klucz bypass trafia potem do
    // podpisu i do linku drukowanego w panelu.
    register_setting('evoke_one_maintenance', 'maintenance_bypass_password', [
        'sanitize_callback' => 'evoke_one_wpm_sanitize_key',
        'default'           => '',
    ]);
    register_setting('evoke_one_maintenance', 'maintenance_bypass_hours', [
        'sanitize_callback' => 'evoke_one_wpm_sanitize_hours',
        'default'           => 1,
    ]);
    register_setting('evoke_one_maintenance', 'maintenance_page_id', [
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ]);
    register_setting('evoke_one_maintenance', 'maintenance_excluded_paths', [
        'sanitize_callback' => 'sanitize_textarea_field',
        'default'           => '',
    ]);
});

// =========================================================================
// ADMIN BAR TOGGLE
// =========================================================================

add_action('admin_bar_menu', function ($wp_admin_bar) {
    if (!current_user_can('manage_options') && !current_user_can('evk_access_maintenance')) return;

    $status     = (int) get_option('maintenance_mode', 0);
    $bg_color   = $status === 1 ? '#6e00a5' : '#72777c';
    $dot_pos    = $status === 1 ? 'left: 18px;' : 'left: 2px;';
    $text_color = $status === 1 ? '#000' : '#fff';

    $title = '<div style="display:flex;align-items:center;gap:10px;padding:0 5px;color:' . $text_color . '">'
        . 'Konserwacja'
        . '<span style="display:inline-block;width:34px;height:18px;border-radius:18px;background:' . $bg_color . ';position:relative;transition:background 0.3s;">'
        . '<span style="display:inline-block;width:14px;height:14px;border-radius:50%;background:#fff;position:absolute;top:2px;' . $dot_pos . 'transition:left 0.3s;"></span>'
        . '</span></div>';

    $wp_admin_bar->add_node([
        'id'    => 'maintenance_toggle_node',
        'title' => $title,
        'href'  => '#',
        'meta'  => [
            'onclick' => 'toggleMaintenanceMode(event);',
            'title'   => 'Włącz/wyłącz tryb konserwacji',
        ],
    ]);

    if ($status === 1) {
        add_action('wp_head',    'evoke_one_adminbar_orange_style');
        add_action('admin_head', 'evoke_one_adminbar_orange_style');
    }
}, 999);

function evoke_one_adminbar_orange_style(): void {
    echo '<style>#wpadminbar #wp-admin-bar-maintenance_toggle_node>.ab-item{background:#ea580c!important;color:#fff!important;}#wpadminbar #wp-admin-bar-maintenance_toggle_node>.ab-item:hover{background:#c2410c!important;}</style>';
}

add_action('admin_enqueue_scripts', 'evoke_one_maintenance_bar_js');
add_action('wp_enqueue_scripts',    'evoke_one_maintenance_bar_js');

function evoke_one_maintenance_bar_js(): void {
    if (!current_user_can('manage_options') && !current_user_can('evk_access_maintenance')) return;
    ?>
    <script>
    function toggleMaintenanceMode(e){
        e.preventDefault();
        var n = document.getElementById('wp-admin-bar-maintenance_toggle_node');
        if (!n) return;
        n.style.opacity = '0.5';
        var x = new XMLHttpRequest();
        x.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
        x.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded;');
        x.onload = function () {
            if (x.status === 200) location.reload();
            else { alert('Błąd.'); n.style.opacity = '1'; }
        };
        x.send('action=toggle_maintenance_status&nonce=<?php echo wp_create_nonce('maintenance_bar_nonce'); ?>');
    }
    </script>
    <?php if ((int) get_option('maintenance_mode', 0) === 1): ?>
    <style>#wpadminbar #wp-admin-bar-maintenance_toggle_node>.ab-item{background:#ffd64f!important;}#wpadminbar #wp-admin-bar-maintenance_toggle_node:hover>.ab-item{background:#ffd13b!important;}</style>
    <?php endif;
}

add_action('wp_ajax_toggle_maintenance_status', function () {
    check_ajax_referer('maintenance_bar_nonce', 'nonce');
    if (!current_user_can('manage_options') && !current_user_can('evk_access_maintenance')) wp_send_json_error();
    update_option('maintenance_mode', (int) get_option('maintenance_mode', 0) === 1 ? 0 : 1);
    wp_send_json_success();
});

// =========================================================================
// LOGIKA KONSERWACJI
// =========================================================================

function evoke_one_wpm_sanitize_key($input): string {
    return substr(sanitize_text_field((string) $input), 0, 100);
}

function evoke_one_wpm_sanitize_hours($input): int {
    return max(1, min(8760, absint($input)));
}

/**
 * Ścieżki przepuszczane mimo konserwacji — znormalizowane do wiodącego `/`,
 * bo porównanie idzie OD POCZĄTKU adresu.
 */
function evoke_one_wpm_get_excluded_paths(): array {
    $hardcoded  = ['/wp-login.php', '/wp-admin', '/wp-cron.php'];
    $custom     = [];
    foreach (explode("\n", (string) get_option('maintenance_excluded_paths', '')) as $path) {
        $path = trim($path);
        if ($path === '') continue;
        $custom[] = $path[0] === '/' ? $path : '/' . $path;
    }
    return array_merge($hardcoded, $custom);
}

/**
 * Czy adres jest wykluczony z konserwacji.
 *
 * Dopasowanie do CAŁEGO SEGMENTU od początku adresu — a nie gdziekolwiek
 * i nie byle jakim przedrostkiem. Dwie rzeczy, które to załatwia:
 *
 * * Do 1.127.0 porównanie szło przez `strpos(...) !== false`, więc wpis
 *   `/wp-admin` przepuszczał KAŻDY adres, który ten ciąg gdzieś zawierał —
 *   choćby `/blog/wp-admin-po-polsku`. Wykluczenie znaczy „ten adres omija
 *   zasłonę", więc wystarczyło mieć taki wpis, żeby strona w konserwacji
 *   stała otworem.
 * * Samo „od początku" też nie wystarcza: strona pod adresem
 *   `/wp-administracja` zaczyna się od `/wp-admin` i wychodziłaby spod
 *   zasłony bez niczyjej wiedzy. Dlatego po dopasowanym przedrostku musi
 *   kończyć się adres albo stać `/`.
 */
function evoke_one_wpm_is_excluded(string $uri): bool {
    foreach (evoke_one_wpm_get_excluded_paths() as $path) {
        if ($uri === $path) return true;
        if (strpos($uri, $path) === 0 && ($uri[strlen($path)] ?? '') === '/') return true;
    }
    return false;
}

/**
 * Treść ciasteczka wpuszczającego za zasłonę: `<termin>|<podpis>`.
 *
 * DLACZEGO NIE SAMO HASŁO, jak było do 1.127.0. Po pierwsze: ciasteczko czyta
 * się łatwiej niż zgaduje, a jego wartość BYŁA hasłem — kto je zobaczył
 * (wspólny komputer, kopia profilu, XSS na stronie), znał klucz, a nie tylko
 * miał wejście. Po drugie: termin ważności był wyłącznie datą wygaśnięcia
 * ciasteczka, czyli ustawieniem po stronie przeglądarki. Kto ją zignorował,
 * wchodził bezterminowo. Teraz termin jest w podpisanej treści i sprawdza go
 * serwer, więc ustawienie „Czas trwania sesji bypass" zaczyna coś znaczyć.
 *
 * Podpis jak w śledzeniu newslettera (`newsletter/tracking.php`): HMAC na
 * `wp_salt('auth')`, sprawdzany przez `hash_equals()`.
 */
function evoke_one_wpm_sign(int $termin, string $klucz): string {
    return $termin . '|' . hash_hmac('sha256', $termin . '|' . $klucz, wp_salt('auth'));
}

/**
 * Atrybuty ciasteczka bypass. Osobno od wywołania, bo `setcookie()` jest
 * funkcją wbudowaną: w teście nie da się jej podmienić atrapą, a w CLI nie
 * zostawia śladu w `headers_list()`. Wyciągnięte tutaj wartości są tym samym,
 * co dostaje przeglądarka — i tym, co sprawdza `tests/php/konserwacja.php`.
 *
 * `secure` idzie za `is_ssl()`, a nie jest przybite na sztywno: strona bez
 * certyfikatu przestałaby wtedy wpuszczać kogokolwiek, a strona z certyfikatem
 * do 1.127.0 wysyłała to ciasteczko także po HTTP.
 */
function evoke_one_wpm_cookie_args(int $termin): array {
    return [
        'expires'  => $termin,
        'path'     => '/',
        'secure'   => is_ssl(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function evoke_one_wpm_cookie_ok(string $wartosc, string $klucz): bool {
    if ($klucz === '' || strpos($wartosc, '|') === false) return false;
    [$termin] = explode('|', $wartosc, 2);
    if (!ctype_digit($termin) || (int) $termin < time()) return false;
    return hash_equals(evoke_one_wpm_sign((int) $termin, $klucz), $wartosc);
}

add_action('parse_request', function () {
    global $wpm_show_maintenance;
    $wpm_show_maintenance = false;

    if ((int) get_option('maintenance_mode', 0) !== 1) return;
    if (is_user_logged_in()) return;

    // `strtok` na pustym łańcuchu oddaje `false` — rzutowanie trzyma typ.
    $request_uri = (string) strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    if (evoke_one_wpm_is_excluded($request_uri)) return;

    $klucz = (string) get_option('maintenance_bypass_password', '');
    $hours = evoke_one_wpm_sanitize_hours(get_option('maintenance_bypass_hours', 1));

    // Klucz z adresu. `hash_equals`, bo `===` na sekrecie kończy porównanie
    // na pierwszym różnym bajcie i mierzalnie zdradza, ile znaków się zgadza.
    if ($klucz !== '' && isset($_GET['haslo'])
        && hash_equals($klucz, (string) wp_unslash($_GET['haslo']))) {
        $termin = time() + ($hours * HOUR_IN_SECONDS);
        setcookie('maintenance_bypass', evoke_one_wpm_sign($termin, $klucz),
                  evoke_one_wpm_cookie_args($termin));
        /* Adres NIESIE KLUCZ, a przeglądarka wysyła adres bieżącej strony
           w nagłówku `Referer` do wszystkiego, co strona ciągnie z obcych
           domen — fontów, map, analityki. Ta jedna linijka zamyka jedyną
           drogę wycieku, którą da się zamknąć kodem; logi serwera i historia
           przeglądarki zostają, i o tym mówi ostrzeżenie w panelu. */
        header('Referrer-Policy: no-referrer');
        wp_safe_redirect(wp_validate_redirect($request_uri, home_url('/')));
        exit;
    }

    if ($klucz !== '' && isset($_COOKIE['maintenance_bypass'])
        && evoke_one_wpm_cookie_ok((string) $_COOKIE['maintenance_bypass'], $klucz)) {
        return;
    }

    if ($request_uri !== '/' && $request_uri !== '') {
        wp_safe_redirect(home_url('/'), 302);
        exit;
    }

    $wpm_show_maintenance = true;
});

add_action('wp', function () {
    global $wpm_show_maintenance, $wp_query, $post;
    if (empty($wpm_show_maintenance)) return;

    $page_id = (int) get_option('maintenance_page_id', 0);
    if (!$page_id || get_post_status($page_id) !== 'publish') return;

    $maintenance_post = get_post($page_id);
    $wp_query->init();
    $wp_query->queried_object    = $maintenance_post;
    $wp_query->queried_object_id = $page_id;
    $wp_query->is_page           = true;
    $wp_query->is_singular       = true;
    $wp_query->is_home           = false;
    $wp_query->is_front_page     = false;
    $wp_query->is_404            = false;
    $wp_query->posts             = [$maintenance_post];
    $wp_query->post              = $maintenance_post;
    $wp_query->post_count        = 1;
    $wp_query->found_posts       = 1;
    $post = $maintenance_post;
    setup_postdata($post);
});

add_filter('template_include', function ($template) {
    global $wpm_show_maintenance;
    if (empty($wpm_show_maintenance)) return $template;

    $page_id = (int) get_option('maintenance_page_id', 0);
    status_header(503);
    nocache_headers();
    header('Retry-After: 3600');

    if ($page_id && get_post_status($page_id) === 'publish') {
        $slug = get_post_meta($page_id, '_wp_page_template', true);
        if ($slug && $slug !== 'default') {
            $located = locate_template($slug);
            if ($located) return $located;
        }
        $fallback = locate_template(['page.php', 'singular.php', 'index.php']);
        if ($fallback) return $fallback;
    }

    echo '<!DOCTYPE html><html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Przerwa techniczna</title>'
        . '<style>*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#fff;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}.wpm-fallback{text-align:center;padding:40px 20px}.wpm-fallback h1{font-size:clamp(28px,5vw,52px);font-weight:700;color:#111827;letter-spacing:-.02em;margin-bottom:16px}.wpm-fallback p{font-size:clamp(14px,2vw,18px);color:#6b7280}</style>'
        . '</head><body><div class="wpm-fallback"><h1>Przerwa techniczna</h1><p>Niedługo wracamy.</p></div></body></html>';
    exit;
}, 999);

add_filter('robots_txt', function ($output, $public) {
    if ((int) get_option('maintenance_mode', 0) === 1) {
        return "User-agent: *\nDisallow: /\n";
    }
    return $output;
}, 10, 2);
