<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — zakładka „Logowanie”: logowanie dwuetapowe (1.287.0), ukryty
 * adres (1.288.0) i limit logowań (z Bezpieczeństwa, 1.289.0) — wszystko,
 * co pilnuje wejścia do panelu. Lista podstron w evoke_one_ekrany().
 */
$sub  = sanitize_key($_GET['sub'] ?? '');
$subs = evoke_one_ekrany()['logowanie'];
if (!array_key_exists($sub, $subs)) $sub = (string) array_key_first($subs);

if ($sub === 'limit') {
    /* Podstrona Bezpieczeństwa — te same zmienne i ten sam zapis co tam. */
    $evk_sec   = evk_security_get();
    $sec_nonce = wp_create_nonce('evk_security_nonce');
    require EVOKE_ONE_DIR . 'includes/admin/security-login.php';
    require EVOKE_ONE_DIR . 'includes/admin/security-zapis.php';
} else {
    require EVOKE_ONE_DIR . 'includes/admin/logowanie-' . $sub . '.php';
}
