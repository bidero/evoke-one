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

const EVK_GSAP_VERSION = '3.15.0';

function evk_register_gsap_libs(): void {
    if (wp_script_is('evk-gsap', 'registered')) return; // idempotentne

    $cdn = 'https://cdnjs.cloudflare.com/ajax/libs/gsap/' . EVK_GSAP_VERSION . '/';

    wp_register_script('evk-gsap',          $cdn . 'gsap.min.js',          [],           EVK_GSAP_VERSION, true);
    wp_register_script('evk-scrolltrigger', $cdn . 'ScrollTrigger.min.js', ['evk-gsap'], EVK_GSAP_VERSION, true);
    wp_register_script('evk-observer',      $cdn . 'Observer.min.js',      ['evk-gsap'], EVK_GSAP_VERSION, true);
    wp_register_script('evk-splittext',     $cdn . 'SplitText.min.js',     ['evk-gsap'], EVK_GSAP_VERSION, true);

    // Efekty tekstowe Animatora. Od GSAP 3.13 wszystkie wtyczki są darmowe
    // i leżą na cdnjs obok reszty — nie ma potrzeby hostowania ich u siebie.
    wp_register_script('evk-textplugin',    $cdn . 'TextPlugin.min.js',    ['evk-gsap'], EVK_GSAP_VERSION, true);
    wp_register_script('evk-scrambletext',  $cdn . 'ScrambleTextPlugin.min.js', ['evk-gsap'], EVK_GSAP_VERSION, true);

    // Na telefonie chowanie i pokazywanie paska adresu wypala `resize` w trakcie
    // przewijania. Bez tego ScrollTrigger przemierza wtedy wszystkie triggery na
    // stronie i scroll widocznie się zacina — mimo że zmieniła się sama wysokość
    // widoku, a układ ani drgnął. Dotyczy całej wtyczki: Animatora, Horizontal
    // Scroll, Scroll Reading i Stacking Cards.
    //
    // Skrypt inline drukuje się wyłącznie tam, gdzie handle jest enqueue'owany.
    wp_add_inline_script(
        'evk-scrolltrigger',
        'if (window.ScrollTrigger) ScrollTrigger.config({ ignoreMobileResize: true });'
    );
}

// Priorytet 1 — przed loaderem elementów (5) i przed enqueue Animatora (20).
add_action('wp_enqueue_scripts', 'evk_register_gsap_libs', 1);
