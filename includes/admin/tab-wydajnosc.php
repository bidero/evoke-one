<?php
if (!defined('ABSPATH')) exit;
/**
 * Evoke ONE — Zakładka: Frontend (efekty i zachowanie publicznej strony)
 * Klucz URL pozostaje 'wydajnosc' (kompatybilność linków). Konserwacja przeniesiona
 * do Narzędzi, Tłumaczenia (włącznik modułu) przeniesione tutaj z Panelu admina.
 */

$sub      = sanitize_key($_GET['sub'] ?? 'parallax');

/* Lista mieszka w evoke_one_ekrany() (includes/admin/helpers.php), bo
   czytają ją także pasek boczny i wyszukiwarka — patrz komentarz tam. */
$subs = evoke_one_ekrany()['wydajnosc'];

if (!array_key_exists($sub, $subs)) $sub = 'parallax';

/* Paska podzakładek tu nie ma od 1.139.1. Wypisywał ekrany tej sekcji nad
   treścią, a od 1.138.0 pasek boczny pokazuje dokładnie tę samą listę — te
   same pozycje i te same adresy, obie z `evoke_one_ekrany()`. Dwa identyczne
   spisy jeden pod drugim czytało się jak dwa poziomy nawigacji, którymi nie
   były. `$subs` zostaje: rozstrzyga, czy `?sub=` z adresu istnieje. */

$parallax_value = evk_get_parallax_value();
$scale_value    = evk_get_parallax_scale();

if ($sub === 'tlumaczenia') {
    require EVOKE_ONE_DIR . 'includes/admin/other-tlumaczenia.php';
} else {
    $sub_file = EVOKE_ONE_DIR . 'includes/admin/tab-' . $sub . '.php';
    if (file_exists($sub_file)) require $sub_file;
}
