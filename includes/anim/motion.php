<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — wspólna polityka ruchu
 *
 * Jedno miejsce, w którym rozstrzyga się, czy wtyczka szanuje systemowe
 * `prefers-reduced-motion`. Wcześniej pytał o to wyłącznie Animator (własne
 * ustawienie `reduced_motion`), a dziesięć pozostałych silników animowało
 * niezależnie od preferencji użytkownika.
 *
 * Opcja jest OSOBNA od `evk_a11y`, mimo że przełącznik stoi w zakładce
 * Dostępność: tamten moduł to widżet z paskiem narzędzi i bywa wyłączony,
 * a polityka ruchu ma obowiązywać zawsze. Rejestrujemy ją tylko w tej samej
 * grupie ustawień, żeby zapisywała się z tego samego formularza.
 *
 * Na front idzie funkcja, nie wartość — sam PHP nie wie, co ustawił użytkownik
 * w systemie. Dopiero `window.evkMotion.reduced()` łączy naszą politykę
 * z `matchMedia`. Silniki wołają ją przez własny, kilkulinijkowy fallback,
 * dzięki czemu każdy zostaje niezależny i nie trzeba pilnować kolejności
 * ładowania skryptów.
 */

/** Domyślnie szanujemy preferencję — wyłączenie musi być świadomą decyzją. */
function evk_motion_respect_reduced(): bool {
    $opt = get_option('evk_motion', null);
    if (!is_array($opt) || !array_key_exists('respect_reduced', $opt)) {
        // Migracja: przed 1.30.0 pytał o to wyłącznie Animator.
        $anim = get_option('evk_animator', []);
        if (is_array($anim) && array_key_exists('reduced_motion', $anim)) {
            return !empty($anim['reduced_motion']);
        }
        return true;
    }
    return !empty($opt['respect_reduced']);
}

add_action('admin_init', function (): void {
    register_setting('evoke_one_a11y', 'evk_motion', [
        'type'              => 'array',
        'sanitize_callback' => 'evk_motion_sanitize',
    ]);
});

function evk_motion_sanitize($input): array {
    return ['respect_reduced' => !empty($input['respect_reduced']) ? 1 : 0];
}

/**
 * Wspólny helper na froncie. Drukowany w <head>, więc jest gotowy zanim
 * ruszy którykolwiek silnik ze stopki.
 */
add_action('wp_head', function (): void {
    if (evk_w_builderze()) return;
    $respect = evk_motion_respect_reduced() ? 'true' : 'false';
    echo '<script id="evk-motion">window.evkMotion={respect:' . $respect . ','
       . 'reduced:function(){return this.respect&&!!(window.matchMedia&&'
       . 'window.matchMedia("(prefers-reduced-motion: reduce)").matches);}};</script>' . "\n";
}, 1);
