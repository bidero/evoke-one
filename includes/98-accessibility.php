<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke One — Moduł Dostępności (Accessibility Widget)
 * Bazuje na: https://github.com/sinanisler/accessibility-widgets
 */

// =========================================================================
// DOMYŚLNA KONFIGURACJA
// =========================================================================

function evk_a11y_defaults(): array {
    return [
        'enabled'             => 0,

        // Włączone funkcje
        'enable_high_contrast'      => 1,
        'enable_bigger_text'        => 1,
        'enable_text_spacing'       => 1,
        'enable_pause_animations'   => 1,
        'enable_hide_images'        => 1,
        'enable_dyslexia_font'      => 1,
        'enable_bigger_cursor'      => 1,
        'enable_line_height'        => 1,
        'enable_text_align'         => 1,
        'enable_screen_reader'      => 1,
        'enable_voice_control'      => 1,
        'enable_font_selection'     => 1,
        'enable_color_filter'       => 1,
        'enable_saturation'         => 1,

        // Pozycja
        'position_side'   => 'right',
        'position_right'  => '20px',
        'position_left'   => '20px',
        'position_bottom' => '20px',

        // Kolory
        'color_primary'     => '#1663d7',
        'color_secondary'   => '#ffffff',
        'color_option_bg'   => '#ffffff',
        'color_option_text' => '#333333',
        'color_option_icon' => '#000000',

        // Przycisk
        'button_size'          => '50px',
        'button_border_radius' => '100px',
        'button_icon_size'     => '40px',

        // Menu
        'widget_width'         => '450px',
        'grid_columns'         => '1fr 1fr',
        'grid_gap'             => '5px',

        // Wykluczenia z filtrów CSS
        'filter_exclusions' => "#snn-accessibility-widget-container\n#brx-header",
        'contrast_exclusions' => "#snn-accessibility-widget-container",

        // Wykluczenia z filtrów saturacji/kolorów (osobna lista)
        'saturation_exclusions' => "#snn-accessibility-widget-container",

        // Wykluczone strony — jedna ścieżka URL na linię (np. /kontakt, /en/)
        'exclude_urls' => '',
    ];
}

function evk_a11y_get_settings(): array {
    return wp_parse_args(get_option('evk_a11y', []), evk_a11y_defaults());
}

// =========================================================================
// REJESTRACJA USTAWIEŃ
// =========================================================================

add_action('admin_init', function () {
    register_setting('evoke_one_a11y', 'evk_a11y', [
        'type'              => 'array',
        'sanitize_callback' => 'evk_a11y_sanitize',
    ]);
});

function evk_a11y_sanitize($input): array {
    $clean    = [];
    $defaults = evk_a11y_defaults();

    $clean['enabled'] = evk_preserve_toggle($input, 'evk_a11y');

    // Checkboxy funkcji
    $features = [
        'enable_high_contrast', 'enable_bigger_text', 'enable_text_spacing',
        'enable_pause_animations', 'enable_hide_images', 'enable_dyslexia_font',
        'enable_bigger_cursor', 'enable_line_height', 'enable_text_align',
        'enable_screen_reader', 'enable_voice_control', 'enable_font_selection',
        'enable_color_filter', 'enable_saturation',
    ];
    foreach ($features as $f) {
        $clean[$f] = !empty($input[$f]) ? 1 : 0;
    }

    // Pozycja
    $clean['position_side']   = in_array($input['position_side'] ?? '', ['left', 'right'], true) ? $input['position_side'] : 'right';
    $clean['position_right']  = sanitize_text_field($input['position_right']  ?? '20px');
    $clean['position_left']   = sanitize_text_field($input['position_left']   ?? '20px');
    $clean['position_bottom'] = sanitize_text_field($input['position_bottom'] ?? '20px');

    // Kolory
    foreach (['color_primary', 'color_secondary', 'color_option_bg', 'color_option_text', 'color_option_icon'] as $k) {
        $val = $input[$k] ?? $defaults[$k];
        $clean[$k] = preg_match('/^#[0-9a-fA-F]{3,8}$/', $val) ? $val : $defaults[$k];
    }

    // Wymiary
    $clean['button_size']          = sanitize_text_field($input['button_size']          ?? $defaults['button_size']);
    $clean['button_border_radius'] = sanitize_text_field($input['button_border_radius'] ?? $defaults['button_border_radius']);
    $clean['button_icon_size']     = sanitize_text_field($input['button_icon_size']     ?? $defaults['button_icon_size']);
    $clean['widget_width']         = sanitize_text_field($input['widget_width']         ?? $defaults['widget_width']);
    $clean['grid_columns']         = sanitize_text_field($input['grid_columns']         ?? $defaults['grid_columns']);
    $clean['grid_gap']             = sanitize_text_field($input['grid_gap']             ?? $defaults['grid_gap']);

    // Wykluczenia — textarea, każda linia to osobny selektor CSS
    $clean['filter_exclusions']    = sanitize_textarea_field($input['filter_exclusions']    ?? $defaults['filter_exclusions']);
    $clean['contrast_exclusions']  = sanitize_textarea_field($input['contrast_exclusions']  ?? $defaults['contrast_exclusions']);
    $clean['saturation_exclusions'] = sanitize_textarea_field($input['saturation_exclusions'] ?? $defaults['saturation_exclusions']);

    // Wykluczone URL-e stron
    $clean['exclude_urls'] = sanitize_textarea_field($input['exclude_urls'] ?? '');

    return $clean;
}

// =========================================================================
// PARSOWANIE WYKLUCZEŃ DO CSS :not(...)
// =========================================================================

function evk_a11y_parse_exclusions(string $raw): string {
    $lines = array_filter(array_map('trim', explode("\n", $raw)));
    if (empty($lines)) return '#snn-accessibility-widget-container';
    return implode(', ', $lines);
}

/**
 * Buduje selektor :not() dla CSS.
 * Zwraca np. :not(#foo, #bar)
 */
function evk_a11y_not(string $exclusions_raw): string {
    $lines = array_filter(array_map('trim', explode("\n", $exclusions_raw)));
    if (empty($lines)) return '';
    return ':not(' . implode(', ', $lines) . ')';
}

// =========================================================================
// GENEROWANIE CSS FRONTENDU
// =========================================================================

function evk_a11y_generate_css(array $s): string {
    $fe  = evk_a11y_not($s['filter_exclusions']);
    $ce  = evk_a11y_not($s['contrast_exclusions']);
    $sat = evk_a11y_not($s['saturation_exclusions']);

    // Upewnij się że widget container jest zawsze wykluczony
    $widget_id = '#snn-accessibility-widget-container';

    return "
/* ===== Evoke One Accessibility Widget CSS ===== */

/* Color Filters */
.snn-filter-protanopia { filter: none !important; }
.snn-filter-protanopia body > *{$fe} { filter: url('#protanopia-filter') !important; }
.snn-filter-deuteranopia { filter: none !important; }
.snn-filter-deuteranopia body > *{$fe} { filter: url('#deuteranopia-filter') !important; }
.snn-filter-tritanopia { filter: none !important; }
.snn-filter-tritanopia body > *{$fe} { filter: url('#tritanopia-filter') !important; }
.snn-filter-grayscale { filter: none !important; }
.snn-filter-grayscale body > *{$fe} { filter: grayscale(100%) !important; }

/* Saturation Filters */
.snn-saturation-low { filter: none !important; }
.snn-saturation-low body > *{$sat} { filter: saturate(0.5) !important; }
.snn-saturation-high { filter: none !important; }
.snn-saturation-high body > *{$sat} { filter: saturate(10) !important; }
.snn-saturation-none { filter: none !important; }
.snn-saturation-none body > *{$sat} { filter: grayscale(100%) saturate(0) !important; }

/* High Contrast */
.snn-high-contrast-medium{$ce} { filter: none !important; }
.snn-high-contrast-medium *{$ce}:not({$widget_id} *) { filter: contrast(1.3) !important; }
.snn-high-contrast-high{$ce} { background-color: #000 !important; color: #fff !important; filter: none !important; }
.snn-high-contrast-high *{$ce}:not({$widget_id} *) { background-color: #000 !important; color: #fff !important; filter: contrast(1.5) !important; }
.snn-high-contrast-ultra{$ce} { background-color: #000 !important; color: #ffff00 !important; filter: none !important; }
.snn-high-contrast-ultra *{$ce}:not({$widget_id} *) { background-color: #000 !important; color: #ffff00 !important; filter: contrast(2.0) !important; }

/* Text Size */
.snn-bigger-text-medium * { font-size: 20px !important; }
.snn-bigger-text-large * { font-size: 24px !important; }
.snn-bigger-text-xlarge * { font-size: 28px !important; }

/* Text Spacing */
.snn-text-spacing-light * { letter-spacing: 0.1em !important; word-spacing: 0.5em !important; }
.snn-text-spacing-medium * { letter-spacing: 0.15em !important; word-spacing: 1em !important; }
.snn-text-spacing-heavy * { letter-spacing: 0.25em !important; word-spacing: 2em !important; }

/* Pause Animations */
.snn-pause-animations *, .snn-pause-animations *::before, .snn-pause-animations *::after {
  animation: none !important; transition: none !important;
}

/* Dyslexia Font */
.snn-dyslexia-font, .snn-dyslexia-font * {
  font-family: 'Comic Sans MS', 'Chalkboard SE', 'Bradley Hand', fantasy !important;
}

/* Line Height */
.snn-line-height-2em * { line-height: 2 !important; }
.snn-line-height-3em * { line-height: 3 !important; }
.snn-line-height-4em * { line-height: 4 !important; }

/* Text Alignment */
.snn-text-align-left * { text-align: left !important; }
.snn-text-align-center * { text-align: center !important; }
.snn-text-align-right * { text-align: right !important; }

/* Bigger Cursor */
.snn-bigger-cursor, .snn-bigger-cursor * {
  cursor: url('data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0idXRmLTgiPz48IURPQ1RZUEUgc3ZnIFBVQkxJQyAiLS8vVzNDLy9EVEQgU1ZHIDEuMS8vRU4iICJodHRwOi8vd3d3LnczLm9yZy9HcmFwaGljcy9TVkcvMS4xL0RURC9zdmcxMS5kdGQiPjxzdmcgdmVyc2lvbj0iMS4xIiBpZD0iTGF5ZXJfMSIgeG1sbnM9Imh0dHA6Ly93d3cudzMub3JnLzIwMDAvc3ZnIiB4bWxuczp4bGluaz0iaHR0cDovL3d3dy53My5vcmcvMTk5OS94bGluayIgeD0iMHB4IiB5PSIwcHgiIHdpZHRoPSIyOS4xODhweCIgaGVpZ2h0PSI0My42MjVweCIgdmlld0JveD0iMCAwIDI5LjE4OCA0My42MjUiIGVuYWJsZS1iYWNrZ3JvdW5kPSJuZXcgMCAwIDI5LjE4OCA0My42MjUiIHhtbDpzcGFjZT0icHJlc2VydmUiPjxnPjxwb2x5Z29uIGZpbGw9IiNGRkZGRkYiIHN0cm9rZT0iI0Q5REFEOSIgc3Ryb2tlLXdpZHRoPSIxLjE0MDYiIHN0cm9rZS1taXRlcmxpbWl0PSIxMCIgcG9pbnRzPSIyLjgsNC41NDkgMjYuODQ3LDE5LjkwMiAxNi45NjQsMjIuNzAxIDI0LjIzOSwzNy43NDkgMTguMjc4LDQyLjAxNyA5Ljc0MSwzMC43MjQgMS4xMzgsMzUuODA5ICIvPjxnPjxnPjxnPjxwYXRoIGZpbGw9IiMyMTI2MjciIGQ9Ik0yOS4xNzUsMjEuMTU1YzAuMDcxLTAuNjEzLTAuMTY1LTEuMjUzLTAuNjM1LTEuNTczTDIuMTY1LDAuMjU4Yy0wLjQyNC0wLjMyLTAuOTg4LTAuMzQ2LTEuNDM1LTAuMDUzQzAuMjgyLDAuNDk3LDAsMS4wMywwLDEuNjE3djM0LjE3MWMwLDAuNjEzLDAuMzA2LDEuMTQ2LDAuNzc2LDEuNDM5YzAuNDcxLDAuMjY3LDEuMDU5LDAuMjEzLDEuNDgyLTAuMTZsNy40ODItNi4zNDRsNi44NDcsMTIuMTU1YzAuMjU5LDAuNDgsMC43MjksMC43NDYsMS4yLDAuNzQ2YzAuMjM1LDAsMC40OTQtMC4wOCwwLjcwNi0wLjIxM2w2Ljk4OC00LjU4NWMwLjMyOS0wLjIxMywwLjU2NS0wLjU4NiwwLjY1OS0xLjAxM2MwLjA5NC0wLjQyNiwwLjAyNC0wLjg4LTAuMTg4LTEuMjI2bC02LjM3Ni0xMS4zODJsOC42MTEtMi43NDVDMjguNzA1LDIyLjI3NCwyOS4xMDUsMjEuNzY4LDI5LjE3NSwyMS4xNTV6IE0xNi45NjQsMjIuNzAxYy0wLjQyNCwwLjEzMy0wLjc3NiwwLjUwNi0wLjk0MSwwLjk2Yy0wLjE2NSwwLjQ4LTAuMTE4LDEuMDEzLDAuMTE4LDEuNDM5bDYuNTg4LDExLjc4MWwtNC41NDEsMi45ODVsLTYuODk0LTEyLjMxNWMtMC4yMTItMC4zNzMtMC41NDEtMC42NC0wLjk0MS0wLjcyYy0wLjA5NC0wLjAyNy0wLjE2NS0wLjAyNy0wLjI1OS0wLjAyN2MtMC4zMDYsMC0wLjU4OCwwLjEwNy0wLjg0NywwLjMyTDIuOCwzMi41OVY0LjU0OWwyMS41OTksMTUuODA2TDE2Ljk2NCwyMi43MDF6Ii8+PC9nPjwvZz48L2c+PC9nPjwvc3ZnPg=='), auto !important;
}

/* Font Selection */
.snn-font-arial, .snn-font-arial * { font-family: Arial, sans-serif !important; }
.snn-font-times, .snn-font-times * { font-family: 'Times New Roman', serif !important; }
.snn-font-verdana, .snn-font-verdana * { font-family: Verdana, sans-serif !important; }

/* Protect widget from page styles */
{$widget_id}, {$widget_id} * {
  filter: none !important;
  background-color: initial;
  color: initial;
}
";
}

// =========================================================================
// ENQUEUE FRONTENDU
// =========================================================================

add_action('wp_enqueue_scripts', function () {
    $s = evk_a11y_get_settings();
    if (empty($s['enabled'])) return;
    if (function_exists('bricks_is_builder_main') && bricks_is_builder_main()) return;

    // Wykluczenia stron po ścieżce URL
    if (!empty($s['exclude_urls'])) {
        $current = $_SERVER['REQUEST_URI'] ?? '';
        foreach (array_filter(array_map('trim', explode("\n", $s['exclude_urls']))) as $excl) {
            if ($excl !== '' && strpos($current, $excl) !== false) return;
        }
    }

    // Enqueue JS widgetu
    wp_enqueue_script(
        'evk-accessibility',
        EVOKE_ONE_URL . 'assets/js/accessibility.js',
        [],
        EVOKE_ONE_VERSION,
        true
    );

    // Przekaż konfigurację do JS
    wp_localize_script('evk-accessibility', 'ACCESSIBILITY_WIDGET_CONFIG', [
        'enableHighContrast'    => (bool) $s['enable_high_contrast'],
        'enableBiggerText'      => (bool) $s['enable_bigger_text'],
        'enableTextSpacing'     => (bool) $s['enable_text_spacing'],
        'enablePauseAnimations' => (bool) $s['enable_pause_animations'],
        'enableHideImages'      => (bool) $s['enable_hide_images'],
        'enableDyslexiaFont'    => (bool) $s['enable_dyslexia_font'],
        'enableBiggerCursor'    => (bool) $s['enable_bigger_cursor'],
        'enableLineHeight'      => (bool) $s['enable_line_height'],
        'enableTextAlign'       => (bool) $s['enable_text_align'],
        'enableScreenReader'    => (bool) $s['enable_screen_reader'],
        'enableVoiceControl'    => (bool) $s['enable_voice_control'],
        'enableFontSelection'   => (bool) $s['enable_font_selection'],
        'enableColorFilter'     => (bool) $s['enable_color_filter'],
        'enableSaturation'      => (bool) true, // saturation zawsze mapujemy z enable_saturation
        'widgetWidth'           => $s['widget_width'],
        'widgetPosition'        => [
            'side'   => $s['position_side'],
            'right'  => $s['position_right'],
            'left'   => $s['position_left'],
            'bottom' => $s['position_bottom'],
        ],
        'colors' => [
            'primary'    => $s['color_primary'],
            'secondary'  => $s['color_secondary'],
            'optionBg'   => $s['color_option_bg'],
            'optionText' => $s['color_option_text'],
            'optionIcon' => $s['color_option_icon'],
        ],
        'button' => [
            'size'         => $s['button_size'],
            'borderRadius' => $s['button_border_radius'],
            'iconSize'     => $s['button_icon_size'],
            'shadow'       => '0 4px 8px rgba(0,0,0,0.2)',
        ],
        'gridLayout' => [
            'columns' => $s['grid_columns'],
            'gap'     => $s['grid_gap'],
        ],
    ]);

    // Inline CSS z dynamicznymi wykluczeniami
    wp_add_inline_style('evk-accessibility-css', evk_a11y_generate_css($s));
}, 20);

// Rejestrujemy pusty styl żeby mieć handle do inline style
add_action('wp_enqueue_scripts', function () {
    $s = evk_a11y_get_settings();
    if (empty($s['enabled'])) return;

    wp_register_style('evk-accessibility-css', false, [], EVOKE_ONE_VERSION);
    wp_enqueue_style('evk-accessibility-css');
}, 19);
