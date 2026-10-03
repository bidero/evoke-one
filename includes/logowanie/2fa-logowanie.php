<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — 2FA: drugi krok logowania (1.287.0).
 *
 * wp-login.php: po haśle osobny ekran kodu w wyglądzie WordPressa. Formularz
 * wysyła TOKEN i kod — hasło nie wraca do przeglądarki ani na serwer.
 *
 * Formularz logowania Bricksa: serwer odpowiada „podaj kod” z tokenem
 * (`evk2fa` w odpowiedzi AJAX — własny komunikat błędu Bricksa go nie
 * przykrywa), a `assets/js/2fa-bricks.js` przebudowuje TEN formularz w miejscu:
 * chowa jego pola, wstawia klon jego pola na kod (te same klasy — ten sam
 * wygląd) i zostawia jego przycisk. Wysłanie idzie zwykłą drogą Bricksa
 * z tokenem i kodem, więc przekierowanie i akcje po logowaniu działają jak
 * bez 2FA. Pola hasła nikt nie czyta — leży w przeglądarce tak jak przed
 * pierwszym wysłaniem.
 */

/** Ekran kodu na wp-login.php. */
function evk_2fa_ekran_kodu(WP_User $u, string $token, string $blad): void {
    $bledy = new WP_Error();
    if ($blad !== '') $bledy->add('evk_2fa', esc_html($blad));
    login_header('Logowanie dwuetapowe', '', $bledy);
    $pamietaj = !empty(evk_2fa_ustawienia()['pamietaj']);
    ?>
    <style>
        #evk-2fa-form .evk-2fa-kod { font: 600 26px/1.3 ui-monospace, Menlo, Consolas, monospace; letter-spacing: .3em; text-align: center; }
        #evk-2fa-form .evk-2fa-zapasowy { margin: 12px 0 0; }
        #evk-2fa-form .evk-2fa-zapasowy button { padding: 0; border: 0; background: none; color: #2271b1; text-decoration: underline; cursor: pointer; font-size: 13px; min-height: 24px; }
    </style>
    <form name="evk_2fa" id="evk-2fa-form" action="<?php echo esc_url(site_url('wp-login.php?action=evk_2fa', 'login_post')); ?>" method="post" autocomplete="off">
        <p><label for="evk-2fa-kod" class="evk-2fa-etykieta">Kod z aplikacji</label>
            <input type="text" name="evk_2fa_kod" id="evk-2fa-kod" class="input evk-2fa-kod" inputmode="numeric" autocomplete="one-time-code" maxlength="9" required autofocus></p>
        <p class="description evk-2fa-opis">Otwórz aplikację uwierzytelniającą (Google Authenticator, 1Password…) i przepisz 6 cyfr dla konta <strong><?php echo esc_html($u->user_login); ?></strong>.</p>
        <?php if ($pamietaj): ?>
        <p class="forgetmenot"><input name="evk_2fa_pamietaj" type="checkbox" id="evk-2fa-pamietaj" value="1"> <label for="evk-2fa-pamietaj">Zapamiętaj to urządzenie na <?php echo (int) EVK_2FA_URZADZENIE_DNI; ?> dni</label></p>
        <?php endif; ?>
        <input type="hidden" name="evk_2fa_token" value="<?php echo esc_attr($token); ?>">
        <p class="submit"><input type="submit" class="button button-primary button-large" value="Zaloguj"></p>
        <p class="evk-2fa-zapasowy"><button type="button" aria-controls="evk-2fa-kod">Nie masz telefonu? Użyj kodu zapasowego</button></p>
    </form>
    <script>
    document.querySelector('.evk-2fa-zapasowy button').addEventListener('click', function () {
        var p = document.getElementById('evk-2fa-kod');
        document.querySelector('.evk-2fa-etykieta').textContent = 'Kod zapasowy';
        document.querySelector('.evk-2fa-opis').textContent = 'Wpisz jeden z kodów zapasowych (np. 4f7k-2m9q). Każdy działa raz.';
        p.setAttribute('inputmode', 'text'); p.setAttribute('autocomplete', 'off'); p.value = ''; p.focus();
        this.parentNode.hidden = true;
    });
    </script>
    <p id="backtoblog"><a href="<?php echo esc_url(wp_login_url()); ?>">← Wróć do logowania</a></p>
    <?php
    login_footer('evk-2fa-kod');
}

/* Wysłanie ekranu kodu (wp-login.php?action=evk_2fa). */
add_action('login_form_evk_2fa', function (): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_safe_redirect(wp_login_url()); exit; }
    $token = sanitize_key((string) ($_POST['evk_2fa_token'] ?? ''));
    $t = evk_2fa_token($token);
    $u = $t ? get_userdata((int) $t['id']) : false;
    if (!$t || !$u || !evk_2fa_wlaczone()) {
        wp_safe_redirect(add_query_arg('evk_2fa', 'wygasl', wp_login_url()));
        exit;
    }
    $rodzaj = evk_2fa_weryfikuj($u->ID, (string) wp_unslash($_POST['evk_2fa_kod'] ?? ''));
    if ($rodzaj === '') {
        $zostalo = evk_2fa_token_proba($token);
        do_action('wp_login_failed', $u->user_login, new WP_Error('evk_2fa_kod', 'Zły kod 2FA'));
        if (!$zostalo) { wp_safe_redirect(add_query_arg('evk_2fa', 'proby', wp_login_url())); exit; }
        evk_2fa_ekran_kodu($u, $token, sprintf('Nieprawidłowy kod. Zostało prób: %d.', $zostalo));
        exit;
    }
    evk_2fa_token_usun($token);
    if (!empty($_POST['evk_2fa_pamietaj']) && !empty(evk_2fa_ustawienia()['pamietaj'])) evk_2fa_zapamietaj($u->ID);
    wp_set_auth_cookie($u->ID, !empty($t['pamietaj_mnie']));
    wp_set_current_user($u->ID);
    do_action('wp_login', $u->user_login, $u);
    $cel = (string) ($t['cel'] ?? '');
    $cel = apply_filters('login_redirect', $cel !== '' ? $cel : admin_url(), $cel, $u);
    wp_safe_redirect($cel ?: admin_url());
    exit;
});

/* Komunikat po wygaśnięciu albo wyczerpaniu prób. */
add_filter('login_message', function ($m) {
    $s = sanitize_key($_GET['evk_2fa'] ?? '');
    if ($s === 'wygasl') return $m . '<div id="login_error" class="notice notice-error"><p>Weryfikacja wygasła. Zaloguj się jeszcze raz.</p></div>';
    if ($s === 'proby') return $m . '<div id="login_error" class="notice notice-error"><p>Za dużo nieudanych kodów. Zaloguj się jeszcze raz.</p></div>';
    return $m;
});

/* Skrypt drugiego kroku dla formularzy Bricksa — tylko dla niezalogowanych, gdy 2FA działa. */
add_action('wp_enqueue_scripts', function (): void {
    if (is_user_logged_in() || !evk_2fa_wlaczone()) return;
    wp_enqueue_script('evk-2fa-bricks', evk_zasob_url(EVOKE_ONE_URL . 'assets/js/2fa-bricks.js'), [], EVOKE_ONE_VERSION, ['in_footer' => true, 'strategy' => 'defer']);
    wp_add_inline_script('evk-2fa-bricks', 'window.evk2fa=' . wp_json_encode(['dni' => EVK_2FA_URZADZENIE_DNI]) . ';', 'before');
});
