<?php
if (!defined('ABSPATH')) exit;

/**
 * EVOKE Tłumaczenia - context and safety helpers
 */

// ====================================================================
// 0. CONTEXT / SAFETY
// ====================================================================
function tl_is_bricks_editor(): bool {
    return isset($_GET['bricks']) && !isset($_GET['bricks_preview']) && !isset($_GET['preview']);
}
function tl_is_bricks_preview(): bool {
    return isset($_GET['bricks_preview']) ||
        (isset($_GET['bricks']) && isset($_GET['preview'])) ||
        (defined('BRICKS_IS_BUILDER_IFRAME') && BRICKS_IS_BUILDER_IFRAME);
}
function tl_is_wp_admin(): bool { return is_admin(); }

/**
 * Czy rysujemy się w builderze Bricks — W DOWOLNYM Z JEGO DWÓCH OKIEN.
 *
 * `bricks_is_builder_main()` jest prawdziwe TYLKO w zewnętrznej powłoce
 * buildera, a powłoka nie rysuje treści. Treść idzie w ramce (canvas) i tam ta
 * funkcja zwraca fałsz — więc warunek zbudowany na niej samej nie zadziała
 * dokładnie tam, gdzie zależy nam najbardziej. Zgłoszone z użycia: „animacje
 * odpalają się w builderze mimo odznaczonego »animuj w builderze«".
 *
 * Cztery drogi, bo Bricks daje cztery i każda łapie inny przypadek. Własne
 * elementy wtyczki (marquee, Horizontal Scroll) od dawna sprawdzają trzy z nich
 * naraz i w kanwie działają poprawnie — to stamtąd wzięty wzorzec.
 *
 * ŚWIADOMIE BEZ `?bricks_preview`: podgląd szablonu otwarty na froncie ma
 * pokazywać stronę taką, jaka będzie — z animacjami. Elementy robią tak samo,
 * pytają o `bricks=run`, nie o podgląd.
 */
function evk_w_builderze(): bool {
    if (defined('BRICKS_IS_BUILDER') && BRICKS_IS_BUILDER) return true;
    if (defined('BRICKS_IS_BUILDER_IFRAME') && BRICKS_IS_BUILDER_IFRAME) return true;
    if (isset($_GET['bricks']) && $_GET['bricks'] === 'run') return true;
    if (function_exists('bricks_is_builder') && bricks_is_builder()) return true;
    if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) return true;
    return false;
}
