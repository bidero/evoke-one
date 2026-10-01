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

/* Pola języków (1.253.0) — od 1.271.0 z ✦ „Przetłumacz”, znakiem „AI — do
   sprawdzenia” i ukrytym źródłem (`ai` z ✦, `teraz` po „Sprawdzone”). Pole
   `html` zamiast `text`: WordPress nie dokleja przycisków do zwykłego pola,
   a nazwę `attachments[ID][…]` składamy tak samo jak on, więc zapis się nie
   zmienia (okno mediów zapisuje pola przy zmianie, ekran obrazu — formularzem).
   Przy polskim alcie — ✦ „Opisz obraz (AI)” (AI ogląda obraz, 61). */
add_filter('attachment_fields_to_edit', function ($pola, $post) {
    if (!($post instanceof WP_Post) || !wp_attachment_is_image($post)) return $pola;
    $id = (int) $post->ID;
    $pl = trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
    $ai = function_exists('evk_tl_ai_dane_przyciskow') ? evk_tl_ai_dane_przyciskow() : null;
    $ikona = '<svg class="evk-alt-ai-ikona" viewBox="0 0 16 16" width="14" height="14" aria-hidden="true" focusable="false"><path fill="currentColor" d="M8 0C8.6 4.6 11.4 7.4 16 8C11.4 8.6 8.6 11.4 8 16C7.4 11.4 4.6 8.6 0 8C4.6 7.4 7.4 4.6 8 0Z"/></svg>';
    if ($ai !== null) {
        $z_ai = function_exists('evk_tl_ai_alt_pl_z_ai') && evk_tl_ai_alt_pl_z_ai($id);
        $pola['evk_tl_alt_pl_ai'] = [
            'label' => 'Tekst alternatywny PL',
            'input' => 'html',
            'html'  => '<div class="evk-alt-ai" data-id="' . $id . '" data-lang="pl"' . ($z_ai ? ' data-ai="1"' : '') . '>'
                . '<button type="button" class="button button-small evk-alt-opisz" aria-label="' . esc_attr('Opisz obraz (AI) — tekst alternatywny PL') . '">' . $ikona
                . '<span>Opisz obraz (AI)</span></button> <span class="evk-alt-ai-znak">AI — do sprawdzenia</span>'
                . ' <span class="evk-alt-ai-stan" role="status"></span></div>',
            'helps' => $pl === '' ? 'Brak polskiego altu — AI obejrzy obraz i zaproponuje opis do pola „Tekst alternatywny”.' : 'AI obejrzy obraz i zaproponuje nowy opis.',
        ];
    }
    foreach (evk_tlw_jezyki() as $kod => $nazwa) {
        $K = strtoupper((string) $kod);
        $w = (string) get_post_meta($id, '_evk_tl_' . $kod . '__alt', true);
        $z = (string) get_post_meta($id, '_evk_tl_' . $kod . '__alt__zrodlo', true);
        $n = 'attachments[' . $id . '][evk_tl_alt_' . $kod . ']';
        $pid = 'attachments-' . $id . '-evk_tl_alt_' . $kod;
        $znak = $w !== '' && evk_tlw_ai($z);
        $html = '<div class="evk-alt-ai" data-id="' . $id . '" data-lang="' . esc_attr((string) $kod) . '" data-pl="' . esc_attr($pl) . '"' . ($znak ? ' data-ai="1"' : '') . '>'
            . '<input type="text" class="text evk-alt-pole" id="' . esc_attr($pid) . '" name="' . esc_attr($n) . '" value="' . esc_attr($w) . '">'
            . '<input type="hidden" class="evk-alt-zrodlo" name="' . esc_attr('attachments[' . $id . '][evk_tl_alt_' . $kod . '__zrodlo]') . '" value="">';
        if ($ai !== null && $pl !== '') {
            $html .= ' <button type="button" class="button button-small evk-alt-tlumacz" aria-label="' . esc_attr('Przetłumacz (AI) — tekst alternatywny ' . $K) . '">'
                . $ikona . '<span>Przetłumacz</span></button>';
        }
        $html .= ' <span class="evk-alt-ai-znak">AI — do sprawdzenia</span>'
            . ' <button type="button" class="button button-small evk-alt-sprawdzone" aria-label="' . esc_attr('Sprawdzone: tekst alternatywny ' . $K) . '">Sprawdzone</button>'
            . ' <span class="evk-alt-ai-stan" role="status"></span></div>';
        $pola['evk_tl_alt_' . $kod] = [
            'label' => 'Tekst alternatywny ' . $K,
            'input' => 'html',
            'html'  => $html,
            'helps' => 'Pusty = polski' . ($pl !== '' ? ': „' . $pl . '”' : ' (dziś pusty)') . '.',
        ];
    }
    return $pola;
}, 10, 2);

/* Dane przychodzą jak z formularza (z ukośnikami). Uprawnienie do edycji
   załącznika sprawdza WordPress przed tym filtrem. Źródło (1.271.0): `ai` —
   skrót polskiego altu ze znacznikiem; `teraz` albo zmieniony tekst — sam
   skrót; bez zmiany — zostaje. */
add_filter('attachment_fields_to_save', function ($post, $dane) {
    if (!is_array($dane) || empty($post['ID'])) return $post;
    $id = (int) $post['ID'];
    $pl = isset($post['_wp_attachment_image_alt']) && is_string($post['_wp_attachment_image_alt'])
        ? trim(wp_unslash($post['_wp_attachment_image_alt'])) : trim((string) get_post_meta($id, '_wp_attachment_image_alt', true));
    foreach (array_keys(evk_tlw_jezyki()) as $kod) {
        if (!isset($dane['evk_tl_alt_' . $kod]) || !is_string($dane['evk_tl_alt_' . $kod])) continue;
        $alt = sanitize_text_field(wp_unslash($dane['evk_tl_alt_' . $kod]));
        $meta = '_evk_tl_' . $kod . '__alt';
        if ($alt === '') {
            delete_post_meta($id, $meta);
            delete_post_meta($id, $meta . '__zrodlo');
            continue;
        }
        $przed = (string) get_post_meta($id, $meta, true);
        update_post_meta($id, $meta, wp_slash($alt));
        $z = is_string($dane['evk_tl_alt_' . $kod . '__zrodlo'] ?? null) ? (string) $dane['evk_tl_alt_' . $kod . '__zrodlo'] : '';
        if ($z === 'ai') {
            update_post_meta($id, $meta . '__zrodlo', 'ai-' . evk_tlw_zrodlo($pl));
        } elseif ($z === 'teraz' || $alt !== $przed) {
            update_post_meta($id, $meta . '__zrodlo', evk_tlw_zrodlo($pl));
        }
    }
    return $post;
}, 10, 2);

/* ✦ w oknie mediów i edycji obrazu (1.271.0): skrypt na każdym ekranie
   panelu, bo okno mediów otwiera się z wielu miejsc; tylko z kluczem API. */
add_action('admin_enqueue_scripts', function () {
    $ai = function_exists('evk_tl_ai_dane_przyciskow') ? evk_tl_ai_dane_przyciskow() : null;
    if ($ai === null) return;
    wp_enqueue_style('evk-tl-alt-ai', EVOKE_ONE_URL . 'assets/admin/tl-alt-ai.css', [], EVOKE_ONE_VERSION);
    wp_enqueue_script('evk-tl-alt-ai', EVOKE_ONE_URL . 'assets/admin/tl-alt-ai.js', ['jquery'], EVOKE_ONE_VERSION, true);
    wp_add_inline_script('evk-tl-alt-ai', 'window.evkAltAi = ' . wp_json_encode($ai) . ';', 'before');
});

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
