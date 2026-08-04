<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — wspólne biblioteki GSAP
 *
 * Jeden zestaw handle'ów dla całej wtyczki: elementy Bricks
 * (includes/bricks-elements/loader.php) i Animator (includes/anim/animator.php)
 * enqueue'ują te same handle, więc GSAP ładuje się raz niezależnie od tego,
 * ile funkcji jest włączonych.
 *
 * Rejestracja ≠ pobranie — samo zarejestrowanie handle'a nic nie emituje.
 * Skrypt trafia na stronę dopiero gdy ktoś zrobi wp_enqueue_script().
 */

const EVK_GSAP_VERSION = '3.13.0';

function evk_register_gsap_libs(): void {
    if (wp_script_is('evk-gsap', 'registered')) return; // idempotentne

    $cdn = 'https://cdnjs.cloudflare.com/ajax/libs/gsap/' . EVK_GSAP_VERSION . '/';

    wp_register_script('evk-gsap',          $cdn . 'gsap.min.js',          [],           EVK_GSAP_VERSION, true);
    wp_register_script('evk-scrolltrigger', $cdn . 'ScrollTrigger.min.js', ['evk-gsap'], EVK_GSAP_VERSION, true);
    wp_register_script('evk-observer',      $cdn . 'Observer.min.js',      ['evk-gsap'], EVK_GSAP_VERSION, true);
    wp_register_script('evk-splittext',     $cdn . 'SplitText.min.js',     ['evk-gsap'], EVK_GSAP_VERSION, true);
}

// Priorytet 1 — przed loaderem elementów (5) i przed enqueue Animatora (20).
add_action('wp_enqueue_scripts', 'evk_register_gsap_libs', 1);
