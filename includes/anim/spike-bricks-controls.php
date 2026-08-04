<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE — Animator: SPIKE Fazy 0 (diagnostyka, nie funkcjonalność)
 *
 * Sprawdza, czy da się doklejać kontrolki do CUDZYCH elementów Bricks — to
 * jedyna rzecz w planie Animatora, której nie można zweryfikować bez żywej
 * instalacji Bricks. Wynik przesądza, czy Faza 2 (kontrolki w panelu elementu)
 * idzie tą drogą, czy trzeba szukać obejścia.
 *
 * URUCHOMIENIE — w wp-config.php dopisz:
 *     define('EVK_ANIM_CONTROLS_SPIKE', true);
 *
 * CO SPRAWDZIĆ:
 *   1. Otwórz w builderze dowolny nagłówek (element „heading").
 *   2. W zakładce Content ma być grupa „EVK SPIKE" z jednym polem tekstowym.
 *   3. Wpisz cokolwiek, zapisz, otwórz stronę na froncie.
 *   4. Element nagłówka ma mieć atrybut data-evk-spike z tą wartością.
 *   5. W logu PHP (WP_DEBUG_LOG) pojawi się linia „[EVK SPIKE] elementów: N".
 *
 * WYNIK:
 *   - wszystkie 5 punktów OK  → Faza 2 zgodnie z planem
 *   - grupa się nie pokazuje  → filtry mają inną nazwę/timing, trzeba sprawdzić
 *                               źródło Bricks (includes/elements/base.php)
 *   - atrybut nie dojeżdża    → problem po stronie render_attributes, nie kontrolek
 *
 * PO TEŚCIE: usuń define z wp-config.php. Ten plik można wtedy skasować —
 * nic z produkcyjnego kodu na nim nie polega.
 */

if (!defined('EVK_ANIM_CONTROLS_SPIKE') || !EVK_ANIM_CONTROLS_SPIKE) return;

add_action('init', function (): void {
    if (!class_exists('\Bricks\Elements')) {
        error_log('[EVK SPIKE] \Bricks\Elements nie istnieje na init/20.');
        return;
    }

    $elements = \Bricks\Elements::$elements ?? null;
    if (!is_array($elements)) {
        error_log('[EVK SPIKE] \Bricks\Elements::$elements nie jest tablicą.');
        return;
    }

    error_log('[EVK SPIKE] elementów: ' . count($elements));

    // Celowo tylko jeden element — spike ma odpowiedzieć na pytanie,
    // a nie obciążyć buildera na całej instalacji.
    $target = 'heading';
    if (!isset($elements[$target])) {
        $target = (string) array_key_first($elements);
        error_log('[EVK SPIKE] brak "heading", testuję na: ' . $target);
    }

    add_filter("bricks/elements/{$target}/control_groups", function ($groups) {
        $groups['evk_spike'] = [
            'title' => 'EVK SPIKE',
            'tab'   => 'content',
        ];
        return $groups;
    });

    add_filter("bricks/elements/{$target}/controls", function ($controls) {
        $controls['evk_spike_value'] = [
            'tab'   => 'content',
            'group' => 'evk_spike',
            'label' => 'Wartość testowa',
            'type'  => 'text',
        ];
        return $controls;
    });
}, 20);

// Czy ustawienie z kontrolki dojeżdża do renderu?
add_filter('bricks/element/render_attributes', function ($attributes, $key, $element) {
    if ($key !== '_root') return $attributes;
    $value = $element->settings['evk_spike_value'] ?? '';
    if ($value !== '') {
        $attributes['data-evk-spike'] = [sanitize_text_field($value)];
    }
    return $attributes;
}, 10, 3);
