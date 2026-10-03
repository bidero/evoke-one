<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Zakładka: Bezpieczeństwo
 */

$sub      = sanitize_key($_GET['sub'] ?? '');

/* Lista mieszka w evoke_one_ekrany() (includes/admin/helpers.php), bo
   czytają ją także pasek boczny i wyszukiwarka — patrz komentarz tam. */
$subs = evoke_one_ekrany()['bezpieczenstwo'];

/* Limit logowań przeszedł do zakładki Logowanie (1.289.0); stary adres
   `?sub=login` przekierowuje `includes/security/login-limit.php`. */
if (!array_key_exists($sub, $subs)) $sub = (string) array_key_first($subs);

/* Paska podzakładek tu nie ma od 1.139.1. Wypisywał ekrany tej sekcji nad
   treścią, a od 1.138.0 pasek boczny pokazuje dokładnie tę samą listę — te
   same pozycje i te same adresy, obie z `evoke_one_ekrany()`. Dwa identyczne
   spisy jeden pod drugim czytało się jak dwa poziomy nawigacji, którymi nie
   były. `$subs` zostaje: rozstrzyga, czy `?sub=` z adresu istnieje. */

$evk_sec   = evk_security_get();
$sec_nonce = wp_create_nonce('evk_security_nonce');

$sub_file = EVOKE_ONE_DIR . 'includes/admin/security-' . $sub . '.php';
if (file_exists($sub_file)) {
    require $sub_file;
}

// Globalny JS dla AJAX save — po załadowaniu subtaba
require EVOKE_ONE_DIR . 'includes/admin/security-zapis.php';
