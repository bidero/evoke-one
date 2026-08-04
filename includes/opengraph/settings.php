<?php
if (!defined('ABSPATH')) exit;


/**
 * Evoke One — Moduł OpenGraph
 *
 * Generuje obrazy OG na podstawie konfigurowalnych warstw.
 * Każda warstwa ma typ, pozycję, rozmiar, kolor i opcje mieszania.
 */

// =========================================================================
// DOMYŚLNA KONFIGURACJA
// =========================================================================

function evk_og_defaults(): array {
    return [
        // Włącznik
        'enabled'     => 0,

        // Globalne
        'width'       => 1200,
        'height'      => 630,
        'format'      => 'jpg',
        'quality'     => 85,
        'font_url'    => '',          // attachment URL
        'font_path'   => '',          // ścieżka na dysku (wypełniana automatycznie)
        'post_types'  => ['post'],
        'fallback_url' => '',

        // Warstwy — kolejność = kolejność renderowania
        'layers' => [
            [
                'id'      => 'bg_color',
                'enabled' => true,
                'type'    => 'rect',
                'label'   => 'Tło bazowe',
                'x'       => 0, 'y' => 0,
                'width'   => 1200, 'height' => 630,
                'color'   => '#ffffff',
                'opacity' => 100,
                'blend'   => 'normal',
            ],
            [
                'id'      => 'photo',
                'enabled' => true,
                'type'    => 'photo',
                'label'   => 'Zdjęcie wyróżniające',
                'x'       => 0, 'y' => 0,
                'width'   => 1200, 'height' => 630,
                'offset_x' => 110,
                'opacity' => 100,
                'blend'   => 'normal',
            ],
            [
                'id'      => 'left_rect',
                'enabled' => true,
                'type'    => 'rect',
                'label'   => 'Lewa sekcja',
                'x'       => 0, 'y' => 0,
                'width'   => 240, 'height' => 630,
                'color'   => '#174d55',
                'opacity' => 100,
                'blend'   => 'normal',
            ],
            [
                'id'      => 'gradient_top',
                'enabled' => true,
                'type'    => 'gradient',
                'label'   => 'Gradient górny',
                'x'       => 240, 'y' => 0,
                'width'   => 960, 'height' => 630,
                'color'   => '#1f6671',
                'direction' => 'top',
                'alpha_start' => 70,
                'alpha_end'   => 0,
                'pos_pct'     => 20,
                'opacity' => 100,
                'blend'   => 'normal',
            ],
            [
                'id'      => 'gradient_bottom',
                'enabled' => true,
                'type'    => 'gradient',
                'label'   => 'Gradient dolny',
                'x'       => 240, 'y' => 0,
                'width'   => 960, 'height' => 630,
                'color'   => '#1f6671',
                'direction' => 'bottom',
                'alpha_start' => 0,
                'alpha_end'   => 95,
                'pos_pct'     => 70,
                'opacity' => 100,
                'blend'   => 'normal',
            ],
            [
                'id'        => 'logo1',
                'enabled'   => true,
                'type'      => 'image',
                'label'     => 'Logo 1',
                'image_id'  => 0,
                'x'         => 20,  'y' => 30,
                'width'     => 200, 'height' => 0,   // height=0 → auto
                'opacity'   => 100,
                'blend'     => 'normal',
            ],
            [
                'id'        => 'logo2',
                'enabled'   => true,
                'type'      => 'image',
                'label'     => 'Logo 2',
                'image_id'  => 0,
                'x'         => 815, 'y' => 40,
                'width'     => 350, 'height' => 0,
                'opacity'   => 100,
                'blend'     => 'normal',
            ],
            [
                'id'        => 'overlay1',
                'enabled'   => true,
                'type'      => 'image',
                'label'     => 'Nakładka 1',
                'image_id'  => 0,
                'x'         => 230, 'y' => 0,
                'width'     => 30,  'height' => 1000,
                'opacity'   => 100,
                'blend'     => 'normal',
            ],
            [
                'id'        => 'overlay2',
                'enabled'   => true,
                'type'      => 'image',
                'label'     => 'Nakładka 2',
                'image_id'  => 0,
                'x'         => 620, 'y' => 550,
                'width'     => 550, 'height' => 49,
                'opacity'   => 100,
                'blend'     => 'normal',
            ],
            [
                'id'          => 'text',
                'enabled'     => true,
                'type'        => 'text',
                'label'       => 'Tytuł',
                'x'           => 275,
                'y_from_bottom' => 120,
                'max_width'   => 900,
                'font_size'   => 80,
                'color'       => '#ffffff',
                'shadow_enabled' => true,
                'shadow_color'   => '#000000',
                'shadow_offset_x' => 3,
                'shadow_offset_y' => 5,
                'shadow_alpha'    => 4,
                'shadow_blur'     => 2,
                'opacity'     => 100,
                'blend'       => 'normal',
            ],
            [
                'id'           => 'qr',
                'enabled'      => true,
                'type'         => 'qr',
                'label'        => 'Kod QR',
                'x'            => 25,   // od prawej krawędzi (margin-right)
                'y'            => 426,
                'size'         => 170,
                'bg_color'     => '#174d55',
                'fg_color'     => '#ffffff',
                'opacity'      => 100,
                'blend'        => 'normal',
            ],
        ],
    ];
}

function evk_og_get_settings(): array {
    $saved = get_option('evk_og', []);
    $defaults = evk_og_defaults();
    if (empty($saved)) return $defaults;

    // Scalenie ustawień globalnych
    $merged = wp_parse_args($saved, $defaults);

    // Jeśli użytkownik ma zapisane warstwy, używamy ich (nie nadpisujemy domyślnymi)
    if (!empty($saved['layers']) && is_array($saved['layers'])) {
        $merged['layers'] = $saved['layers'];
    }

    return $merged;
}

// =========================================================================
// REJESTRACJA FONTÓW JAKO DOZWOLONE TYPY UPLOADÓW
// =========================================================================

add_filter('upload_mimes', function ($mimes) {
    $mimes['ttf']   = 'font/ttf';
    $mimes['otf']   = 'font/otf';
    $mimes['woff']  = 'font/woff';
    $mimes['woff2'] = 'font/woff2';
    $mimes['svg']   = 'image/svg+xml';
    $mimes['svgz']  = 'image/svg+xml';
    return $mimes;
});

// WordPress 5.1+ sprawdza też rzeczywisty MIME przez finfo — nadpisujemy dla fontów i SVG
add_filter('wp_check_filetype_and_ext', function ($data, $file, $filename, $mimes) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $font_types = [
        'ttf'   => 'font/ttf',
        'otf'   => 'font/otf',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'svg'   => 'image/svg+xml',
        'svgz'  => 'image/svg+xml',
    ];
    if (isset($font_types[$ext])) {
        $data['ext']  = $ext;
        $data['type'] = $font_types[$ext];
    }
    return $data;
}, 10, 4);

add_action('admin_init', function () {
    register_setting('evoke_one_og', 'evk_og', [
        'type'              => 'array',
        'sanitize_callback' => 'evk_og_sanitize_settings',
    ]);
});

function evk_og_sanitize_settings($input): array {
    $clean = [];

    $clean['enabled'] = evk_preserve_toggle($input, 'evk_og');

    $clean['width']   = max(400, min(2400, intval($input['width']   ?? 1200)));
    $clean['height']  = max(200, min(1400, intval($input['height']  ?? 630)));
    $clean['format']  = in_array($input['format'] ?? '', ['jpg', 'png', 'webp'], true) ? $input['format'] : 'jpg';
    $clean['quality'] = max(10, min(100, intval($input['quality']   ?? 85)));
    $clean['font_url']  = esc_url_raw($input['font_url']  ?? '');
    // Przelicz font_url → font_path automatycznie przy każdym zapisie
    if (!empty($clean['font_url'])) {
        $upload_dir = wp_upload_dir();
        $resolved   = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $clean['font_url']);
        $clean['font_path'] = file_exists($resolved) ? $resolved : sanitize_text_field($input['font_path'] ?? '');
    } else {
        $clean['font_path'] = sanitize_text_field($input['font_path'] ?? '');
    }
    $clean['fallback_url'] = esc_url_raw($input['fallback_url'] ?? '');

    // Post types
    $all_types = array_keys(get_post_types(['public' => true]));
    $raw_types = is_array($input['post_types'] ?? null) ? $input['post_types'] : [];
    $clean['post_types'] = array_values(array_intersect($raw_types, $all_types));

    // Warstwy
    $clean['layers'] = [];
    if (!empty($input['layers']) && is_array($input['layers'])) {
        foreach ($input['layers'] as $layer) {
            $type = sanitize_key($layer['type'] ?? 'rect');
            $l = [
                'id'      => sanitize_key($layer['id'] ?? uniqid('layer_')),
                'enabled' => !empty($layer['enabled']),
                'type'    => $type,
                'label'   => sanitize_text_field($layer['label'] ?? ''),
                'x'       => intval($layer['x'] ?? 0),
                'y'       => intval($layer['y'] ?? 0),
                'width'   => intval($layer['width']  ?? 0),
                'height'  => intval($layer['height'] ?? 0),
                'opacity' => max(0, min(100, intval($layer['opacity'] ?? 100))),
                'blend'   => sanitize_key($layer['blend'] ?? 'normal'),
            ];

            switch ($type) {
                case 'rect':
                    $l['color'] = evk_og_sanitize_color($layer['color'] ?? '#000000');
                    break;

                case 'photo':
                    $l['offset_x'] = intval($layer['offset_x'] ?? 0);
                    break;

                case 'gradient':
                    $l['color']       = evk_og_sanitize_color($layer['color'] ?? '#000000');
                    $l['direction']   = in_array($layer['direction'] ?? '', ['top', 'bottom', 'left', 'right'], true)
                                        ? $layer['direction'] : 'bottom';
                    $l['alpha_start'] = max(0, min(100, intval($layer['alpha_start'] ?? 0)));
                    $l['alpha_end']   = max(0, min(100, intval($layer['alpha_end']   ?? 100)));
                    $l['pos_pct']     = max(0, min(100, intval($layer['pos_pct']     ?? 50)));
                    break;

                case 'image':
                    $l['image_id'] = absint($layer['image_id'] ?? 0);
                    break;

                case 'text':
                    $l['y_from_bottom']  = intval($layer['y_from_bottom'] ?? 120);
                    $l['max_width']      = max(100, intval($layer['max_width'] ?? 900));
                    $l['font_size']      = max(12, min(300, intval($layer['font_size'] ?? 80)));
                    $l['color']          = evk_og_sanitize_color($layer['color'] ?? '#ffffff');
                    $l['shadow_enabled'] = !empty($layer['shadow_enabled']);
                    $l['shadow_color']   = evk_og_sanitize_color($layer['shadow_color'] ?? '#000000');
                    $l['shadow_offset_x'] = intval($layer['shadow_offset_x'] ?? 3);
                    $l['shadow_offset_y'] = intval($layer['shadow_offset_y'] ?? 5);
                    $l['shadow_alpha']    = max(0, min(100, intval($layer['shadow_alpha'] ?? 50)));
                    $l['shadow_blur']     = max(0, min(20, intval($layer['shadow_blur']  ?? 2)));
                    break;

                case 'qr':
                    $l['size']     = max(50, min(500, intval($layer['size'] ?? 170)));
                    $l['bg_color'] = evk_og_sanitize_color($layer['bg_color'] ?? '#ffffff');
                    $l['fg_color'] = evk_og_sanitize_color($layer['fg_color'] ?? '#000000');
                    // x = margin-right dla QR
                    break;
            }

            $clean['layers'][] = $l;
        }
    }

    return $clean;
}

function evk_og_sanitize_color(string $val): string {
    // Akceptuje #rgb, #rrggbb, #rrggbbaa
    return preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3}(?:[0-9a-fA-F]{2})?)?$/', $val) ? $val : '#000000';
}
