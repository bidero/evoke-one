<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia — tekst alternatywny obrazów z biblioteki mediów w każdym
 * języku (1.253.0).
 *
 * Pola „Tekst alternatywny EN/DE" stoją w oknie mediów i na ekranie edycji
 * obrazu (attachment_fields_to_edit — WordPress sam zapisuje je razem
 * z załącznikiem). Słownik alt-ów nie tłumaczył: atrybut nie jest tekstem
 * strony, a ten sam obrazek bywa w wielu miejscach.
 *
 * Strona: alt czytany z biblioteki — wp_get_attachment_image(), obrazki
 * wyróżniające, elementy Bricksa, które biorą alt z załącznika — w języku
 * strony. Pusty = polski. Obrazek wstawiony w treść ma alt w samym HTML-u:
 * w treści języka poprawia się go w edytorze.
 */

add_filter('attachment_fields_to_edit', function ($pola, $post) {
    if (!($post instanceof WP_Post) || !wp_attachment_is_image($post)) return $pola;
    $pl = trim((string) get_post_meta($post->ID, '_wp_attachment_image_alt', true));
    foreach (evk_tlw_jezyki() as $kod => $nazwa) {
        $pola['evk_tl_alt_' . $kod] = [
            'label' => 'Tekst alternatywny ' . strtoupper($kod),
            'input' => 'text',
            'value' => (string) get_post_meta($post->ID, '_evk_tl_' . $kod . '__alt', true),
            'helps' => 'Pusty = polski' . ($pl !== '' ? ': „' . $pl . '”' : ' (dziś pusty)') . '.',
        ];
    }
    return $pola;
}, 10, 2);

/* Dane przychodzą jak z formularza (z ukośnikami). Uprawnienie do edycji
   załącznika sprawdza WordPress przed tym filtrem. */
add_filter('attachment_fields_to_save', function ($post, $dane) {
    if (!is_array($dane) || empty($post['ID'])) return $post;
    foreach (array_keys(evk_tlw_jezyki()) as $kod) {
        if (!isset($dane['evk_tl_alt_' . $kod]) || !is_string($dane['evk_tl_alt_' . $kod])) continue;
        $alt = sanitize_text_field(wp_unslash($dane['evk_tl_alt_' . $kod]));
        if ($alt === '') {
            delete_post_meta((int) $post['ID'], '_evk_tl_' . $kod . '__alt');
        } else {
            update_post_meta((int) $post['ID'], '_evk_tl_' . $kod . '__alt', wp_slash($alt));
        }
    }
    return $post;
}, 10, 2);

/**
 * Alt z biblioteki w języku strony: odczyt `_wp_attachment_image_alt` dostaje
 * tłumaczenie, gdy jest (i polski alt nie jest pusty). Każdy inny klucz
 * metadanych wychodzi od razu — filtr stoi na każdym odczycie.
 */
add_filter('get_post_metadata', function ($wartosc, $id, $klucz, $pojedyncza) {
    static $wewnatrz = false;
    if ($klucz !== '_wp_attachment_image_alt' || $wewnatrz) return $wartosc;
    $lang = evk_tlw_jezyk();
    if ($lang === '') return $wartosc;
    $wewnatrz = true;   // odczyt polskiego alt-u niżej woła ten sam filtr
    $pl = (string) get_post_meta((int) $id, '_wp_attachment_image_alt', true);
    $t  = (string) get_post_meta((int) $id, '_evk_tl_' . $lang . '__alt', true);
    $wewnatrz = false;
    if (trim($pl) === '' || trim($t) === '') return $wartosc;
    return [$t];   // WordPress przy $single oddaje pierwszy element
}, 10, 4);
