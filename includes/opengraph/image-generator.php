<?php
if (!defined('ABSPATH')) exit;


// =========================================================================
// GENEROWANIE OBRAZU
// =========================================================================

function evk_og_get_font_path(array $s): string {
    // Jeśli font_path jest ustawiona ręcznie i istnieje — użyj jej
    if (!empty($s['font_path']) && file_exists($s['font_path'])) {
        return $s['font_path'];
    }
    // Jeśli font_url — zamień URL na ścieżkę
    if (!empty($s['font_url'])) {
        $upload_dir = wp_upload_dir();
        $path = str_replace($upload_dir['baseurl'], $upload_dir['basedir'], $s['font_url']);
        if (file_exists($path)) return $path;
    }
    return '';
}

function evk_og_get_image_path(int $attachment_id): string {
    if (!$attachment_id) return '';
    $path = get_attached_file($attachment_id);
    return ($path && file_exists($path)) ? $path : '';
}

function evk_og_hex_to_rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

function evk_og_apply_blend($base_img, $top_img, int $dst_x, int $dst_y, string $mode, int $opacity): void {
    $src_w = imagesx($top_img);
    $src_h = imagesy($top_img);

    if ($mode === 'normal' && $opacity === 100) {
        imagecopy($base_img, $top_img, $dst_x, $dst_y, 0, 0, $src_w, $src_h);
        return;
    }

    for ($x = 0; $x < $src_w; $x++) {
        for ($y = 0; $y < $src_h; $y++) {
            $src_rgba = imagecolorat($top_img, $x, $y);
            $src_a = ($src_rgba >> 24) & 0x7F;
            if ($src_a >= 127) continue;

            $src_r = ($src_rgba >> 16) & 0xFF;
            $src_g = ($src_rgba >> 8)  & 0xFF;
            $src_b =  $src_rgba        & 0xFF;

            $dst_rgba = imagecolorat($base_img, $dst_x + $x, $dst_y + $y);
            $dst_r = ($dst_rgba >> 16) & 0xFF;
            $dst_g = ($dst_rgba >> 8)  & 0xFF;
            $dst_b =  $dst_rgba        & 0xFF;

            switch ($mode) {
                case 'multiply':
                    $res_r = ($src_r * $dst_r) / 255;
                    $res_g = ($src_g * $dst_g) / 255;
                    $res_b = ($src_b * $dst_b) / 255;
                    break;
                case 'screen':
                    $res_r = 255 - ((255 - $src_r) * (255 - $dst_r) / 255);
                    $res_g = 255 - ((255 - $src_g) * (255 - $dst_g) / 255);
                    $res_b = 255 - ((255 - $src_b) * (255 - $dst_b) / 255);
                    break;
                case 'overlay':
                    $res_r = ($dst_r < 128) ? (2 * $src_r * $dst_r / 255) : (255 - 2 * (255-$src_r) * (255-$dst_r) / 255);
                    $res_g = ($dst_g < 128) ? (2 * $src_g * $dst_g / 255) : (255 - 2 * (255-$src_g) * (255-$dst_g) / 255);
                    $res_b = ($dst_b < 128) ? (2 * $src_b * $dst_b / 255) : (255 - 2 * (255-$src_b) * (255-$dst_b) / 255);
                    break;
                default: // normal
                    $res_r = $src_r;
                    $res_g = $src_g;
                    $res_b = $src_b;
            }

            $layer_alpha   = 1 - ($src_a / 127);
            $opacity_factor = $opacity / 100;
            $alpha_pct = $layer_alpha * $opacity_factor;

            $fin_r = (int) max(0, min(255, $res_r * $alpha_pct + $dst_r * (1 - $alpha_pct)));
            $fin_g = (int) max(0, min(255, $res_g * $alpha_pct + $dst_g * (1 - $alpha_pct)));
            $fin_b = (int) max(0, min(255, $res_b * $alpha_pct + $dst_b * (1 - $alpha_pct)));

            imagesetpixel($base_img, $dst_x + $x, $dst_y + $y, imagecolorallocate($base_img, $fin_r, $fin_g, $fin_b));
        }
    }
}

function evk_og_wrap_text(string $text, int $font_size, string $font_path, int $max_width): array {
    $words = explode(' ', $text);
    if (empty($words)) return [];

    $greedy_lines = [];
    $current = '';
    foreach ($words as $word) {
        $test = $current === '' ? $word : $current . ' ' . $word;
        $bbox = imagettfbbox($font_size, 0, $font_path, $test);
        if (abs($bbox[2] - $bbox[0]) <= $max_width) {
            $current = $test;
        } else {
            if ($current !== '') $greedy_lines[] = $current;
            $current = $word;
        }
    }
    if ($current !== '') $greedy_lines[] = $current;

    $num_lines = count($greedy_lines);
    if ($num_lines <= 1) return $greedy_lines;

    // Balansowanie linii — szukamy węższego max_width który nie zwiększa liczby linii
    $balanced = $greedy_lines;
    $test_width = $max_width;
    while ($test_width > 100) {
        $test_width -= 10;
        $test_lines = [];
        $curr = '';
        foreach ($words as $word) {
            $t = $curr === '' ? $word : $curr . ' ' . $word;
            $b = imagettfbbox($font_size, 0, $font_path, $t);
            if (abs($b[2] - $b[0]) <= $test_width) {
                $curr = $t;
            } else {
                if ($curr !== '') $test_lines[] = $curr;
                $curr = $word;
            }
        }
        if ($curr !== '') $test_lines[] = $curr;

        if (count($test_lines) === $num_lines) {
            $balanced = $test_lines;
        } else {
            break;
        }
    }

    return $balanced;
}

function evk_og_is_svg(string $path): bool {
    return strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'svg';
}

/**
 * Rasteryzuje SVG do zasobu GD przez Imagick.
 * Zwraca false jeśli Imagick niedostępny lub konwersja się nie powiedzie.
 *
 * @return resource|GdImage|false
 */
function evk_og_svg_to_gd(string $svg_path, int $dst_w, int $dst_h) {
    if (!extension_loaded('imagick')) {
        error_log('Evoke OG: SVG wymaga rozszerzenia Imagick — nie jest zainstalowane.');
        return false;
    }

    try {
        $im = new Imagick();
        $im->setBackgroundColor(new ImagickPixel('transparent'));

        // Ustaw rozdzielczość przed wczytaniem — SVG jest wektorowy
        $im->setResolution(300, 300);
        $im->readImage($svg_path);
        $im->setImageFormat('png32');

        // Przeskaluj do żądanego rozmiaru
        $im->resizeImage($dst_w, $dst_h, Imagick::FILTER_LANCZOS, 1, true);
        $im->setImageAlphaChannel(Imagick::ALPHACHANNEL_ACTIVATE);

        $png_blob = $im->getImageBlob();
        $im->destroy();

        $gd = @imagecreatefromstring($png_blob);
        return $gd ?: false;

    } catch (Exception $e) {
        error_log('Evoke OG: błąd rasteryzacji SVG — ' . $e->getMessage());
        return false;
    }
}


function evk_og_render_layer($img, array $layer, int $post_id, array $s): void {
    if (empty($layer['enabled'])) return;

    $type    = $layer['type']    ?? 'rect';
    $opacity = $layer['opacity'] ?? 100;
    $blend   = $layer['blend']   ?? 'normal';
    $x       = $layer['x']       ?? 0;
    $y       = $layer['y']       ?? 0;
    $w       = $layer['width']   ?? 0;
    $h       = $layer['height']  ?? 0;

    switch ($type):

        case 'rect':
            [$r, $g, $b] = evk_og_hex_to_rgb($layer['color'] ?? '#000000');
            if ($opacity >= 100) {
                $c = imagecolorallocate($img, $r, $g, $b);
            } else {
                $gd_alpha = (int)(127 * (1 - $opacity / 100));
                $c = imagecolorallocatealpha($img, $r, $g, $b, $gd_alpha);
            }
            imagefilledrectangle($img, $x, $y, $x + $w - 1, $y + $h - 1, $c);
            break;

        case 'photo':
            $thumb_id   = get_post_thumbnail_id($post_id);
            $thumb_path = get_attached_file($thumb_id);
            if (!$thumb_path || !file_exists($thumb_path)) break;

            $info   = getimagesize($thumb_path);
            $source = null;
            try {
                if ($info[2] === IMAGETYPE_JPEG)     $source = imagecreatefromjpeg($thumb_path);
                elseif ($info[2] === IMAGETYPE_PNG)  $source = imagecreatefrompng($thumb_path);
                elseif ($info[2] === IMAGETYPE_WEBP) $source = imagecreatefromwebp($thumb_path);
            } catch (Exception $e) {
                error_log('Evoke OG: błąd wczytywania miniatury — ' . $e->getMessage());
            }
            if (!$source) break;

            $dst_w    = $w ?: $s['width'];
            $dst_h    = $h ?: $s['height'];
            $ratio    = max($dst_w / $info[0], $dst_h / $info[1]);
            $crop_w   = $dst_w / $ratio;
            $crop_h   = $dst_h / $ratio;
            $offset_x = (int)(($layer['offset_x'] ?? 0) / $ratio);
            $src_x    = (int)(($info[0] - $crop_w) / 2) - $offset_x;
            $src_y    = (int)(($info[1] - $crop_h) / 2);

            if ($opacity >= 100 && $blend === 'normal') {
                imagecopyresampled($img, $source, $x, $y, $src_x, $src_y, $dst_w, $dst_h, (int)$crop_w, (int)$crop_h);
            } else {
                $tmp = imagecreatetruecolor($dst_w, $dst_h);
                imagealphablending($tmp, false);
                imagesavealpha($tmp, true);
                imagecopyresampled($tmp, $source, 0, 0, $src_x, $src_y, $dst_w, $dst_h, (int)$crop_w, (int)$crop_h);
                evk_og_apply_blend($img, $tmp, $x, $y, $blend, $opacity);
            }
            break;

        case 'gradient':
            [$r, $g, $b] = evk_og_hex_to_rgb($layer['color'] ?? '#000000');
            $direction   = $layer['direction']   ?? 'bottom';
            $alpha_start = $layer['alpha_start'] ?? 0;
            $alpha_end   = $layer['alpha_end']   ?? 100;
            $pos_pct     = $layer['pos_pct']     ?? 0;
            $dst_w       = $w ?: $s['width'];
            $dst_h       = $h ?: $s['height'];

            if ($direction === 'top') {
                $end_y = (int)($dst_h * $pos_pct / 100);
                for ($gy = 0; $gy < $end_y; $gy++) {
                    $pct      = $gy / max(1, $end_y);
                    $alpha    = $alpha_start + ($alpha_end - $alpha_start) * $pct;
                    $gd_alpha = (int)(127 - (127 * ($alpha / 100)));
                    $c = imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $gd_alpha)));
                    imageline($img, $x, $y + $gy, $x + $dst_w - 1, $y + $gy, $c);
                }
            } elseif ($direction === 'bottom') {
                $start_y = (int)($dst_h * $pos_pct / 100);
                for ($gy = $start_y; $gy < $dst_h; $gy++) {
                    $pct      = ($gy - $start_y) / max(1, $dst_h - $start_y);
                    $alpha    = $alpha_start + ($alpha_end - $alpha_start) * $pct;
                    $gd_alpha = (int)(127 - (127 * ($alpha / 100)));
                    $c = imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $gd_alpha)));
                    imageline($img, $x, $y + $gy, $x + $dst_w - 1, $y + $gy, $c);
                }
            } elseif ($direction === 'left') {
                $start_x = (int)($dst_w * $pos_pct / 100);
                for ($gx = $start_x; $gx < $dst_w; $gx++) {
                    $pct      = ($gx - $start_x) / max(1, $dst_w - $start_x);
                    $alpha    = $alpha_start + ($alpha_end - $alpha_start) * $pct;
                    $gd_alpha = (int)(127 - (127 * ($alpha / 100)));
                    $c = imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $gd_alpha)));
                    imageline($img, $x + $gx, $y, $x + $gx, $y + $dst_h - 1, $c);
                }
            } elseif ($direction === 'right') {
                $end_x = (int)($dst_w * $pos_pct / 100);
                for ($gx = 0; $gx < $end_x; $gx++) {
                    $pct      = $gx / max(1, $end_x);
                    $alpha    = $alpha_start + ($alpha_end - $alpha_start) * $pct;
                    $gd_alpha = (int)(127 - (127 * ($alpha / 100)));
                    $c = imagecolorallocatealpha($img, $r, $g, $b, max(0, min(127, $gd_alpha)));
                    imageline($img, $x + $gx, $y, $x + $gx, $y + $dst_h - 1, $c);
                }
            }
            break;

        case 'image':
            $img_path = evk_og_get_image_path((int)($layer['image_id'] ?? 0));
            if (!$img_path) break;

            $orig_w  = 0;
            $orig_h  = 0;
            $src_img = null;

            if (evk_og_is_svg($img_path)) {
                $pre_w   = $w ?: ($h ? 0 : 1200);
                $pre_h   = $h ?: ($w ? 0 : 630);
                $src_img = evk_og_svg_to_gd($img_path, $pre_w ?: 1200, $pre_h ?: 630);
                if ($src_img) {
                    $orig_w = imagesx($src_img);
                    $orig_h = imagesy($src_img);
                }
            } else {
                $info = getimagesize($img_path);
                if ($info) {
                    if ($info[2] === IMAGETYPE_JPEG)     $src_img = imagecreatefromjpeg($img_path);
                    elseif ($info[2] === IMAGETYPE_PNG)  $src_img = imagecreatefrompng($img_path);
                    elseif ($info[2] === IMAGETYPE_WEBP) $src_img = imagecreatefromwebp($img_path);
                    elseif ($info[2] === IMAGETYPE_GIF)  $src_img = imagecreatefromgif($img_path);
                    if ($src_img) {
                        $orig_w = $info[0];
                        $orig_h = $info[1];
                    }
                }
            }

            if (!$src_img) break;

            if ($w && $h) {
                $dst_w = $w;
                $dst_h = $h;
            } elseif ($w) {
                $dst_w = $w;
                $dst_h = $orig_h > 0 ? (int)($orig_h * ($w / $orig_w)) : $w;
            } elseif ($h) {
                $dst_h = $h;
                $dst_w = $orig_w > 0 ? (int)($orig_w * ($h / $orig_h)) : $h;
            } else {
                $dst_w = $orig_w;
                $dst_h = $orig_h;
            }

            $tmp = imagecreatetruecolor($dst_w, $dst_h);
            imagealphablending($tmp, false);
            imagesavealpha($tmp, true);
            $transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
            imagefill($tmp, 0, 0, $transparent);
            imagecopyresampled($tmp, $src_img, 0, 0, 0, 0, $dst_w, $dst_h, $orig_w, $orig_h);
            evk_og_apply_blend($img, $tmp, $x, $y, $blend, $opacity);
            break;

        case 'text':
            $font_path = evk_og_get_font_path($s);
            if (!$font_path) break;

            $font_size  = $layer['font_size']    ?? 80;
            $max_width  = $layer['max_width']     ?? 900;
            $y_from_bot = $layer['y_from_bottom'] ?? 120;
            [$tr, $tg, $tb] = evk_og_hex_to_rgb($layer['color'] ?? '#ffffff');

            $title       = html_entity_decode(get_the_title($post_id), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $lines       = evk_og_wrap_text($title, $font_size, $font_path, $max_width);
            $line_height = $font_size * 1.3;
            $curr_y      = $s['height'] - $y_from_bot - ((count($lines) - 1) * $line_height);
            $text_color  = imagecolorallocate($img, $tr, $tg, $tb);

            foreach ($lines as $line) {
                if (!empty($layer['shadow_enabled'])) {
                    [$sr, $sg, $sb]   = evk_og_hex_to_rgb($layer['shadow_color'] ?? '#000000');
                    $s_alpha_pct      = $layer['shadow_alpha'] ?? 50;
                    $gd_alpha         = (int)(127 - (127 * ($s_alpha_pct / 100)));
                    $shadow_color     = imagecolorallocatealpha($img, $sr, $sg, $sb, max(0, min(127, $gd_alpha)));
                    $blur             = $layer['shadow_blur']     ?? 2;
                    $off_x            = $layer['shadow_offset_x'] ?? 3;
                    $off_y            = $layer['shadow_offset_y'] ?? 5;
                    for ($ox = -$blur; $ox <= $blur; $ox++) {
                        for ($oy = -$blur; $oy <= $blur; $oy++) {
                            imagettftext($img, $font_size, 0,
                                $x + $off_x + ($ox * $blur),
                                (int)$curr_y + $off_y + ($oy * $blur),
                                $shadow_color, $font_path, $line
                            );
                        }
                    }
                }
                imagettftext($img, $font_size, 0, $x, (int)$curr_y, $text_color, $font_path, $line);
                $curr_y += $line_height;
            }
            break;

        case 'qr':
            /* Kod rysowany na miejscu (qr.php). Do 1.234.1 szedł z api.qrserver.com:
               adres wpisu trafiał do obcej usługi przy każdym generowaniu, a gdy ta
               nie odpowiedziała w 5 s, obrazek wychodził bez kodu. Poziom korekcji L
               jak tam (największe moduły przy tym samym rozmiarze); położenie
               i kolory bez zmian, strefa ciszy 2 moduły zamiast 2 px. */
            $size  = (int) ($layer['size'] ?? 170);
            $qr_x  = $s['width'] - $size - $x;
            $kod   = evk_qr_koduj((string) get_permalink($post_id), 'L');
            if ($kod === null) {
                error_log('Evoke OG: adres za długi na kod QR, post ID ' . $post_id);
                break;
            }
            [$fr, $fg, $fb] = evk_og_hex_to_rgb($layer['fg_color'] ?? '#ffffff');
            [$br, $bg, $bb] = evk_og_hex_to_rgb($layer['bg_color'] ?? '#000000');
            // Przezroczyste tło (1.238.0): bez kwadratu, moduły na warstwach pod spodem.
            evk_qr_rysuj($img, $kod['moduly'], $qr_x, $y, $size,
                (int) imagecolorallocate($img, $fr, $fg, $fb),
                !empty($layer['bg_transparent']) ? null : (int) imagecolorallocate($img, $br, $bg, $bb));
            break;

    endswitch;
}

function evk_og_create($post_id, bool $force = false): void {
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    $s = evk_og_get_settings();

    // Sprawdź post type
    $post_type = get_post_type($post_id);
    if (!in_array($post_type, (array)($s['post_types'] ?? ['post']), true)) return;

    // Sprawdź flagę "użyj standardowego"
    if (get_post_meta($post_id, '_evk_og_disable', true) === '1') return;

    if (!has_post_thumbnail($post_id)) {
        error_log('Evoke OG: brak miniatury dla post ID ' . $post_id);
        return;
    }

    $upload_dir = wp_upload_dir();
    $og_dir     = $upload_dir['basedir'] . '/og-images';
    $ext        = ($s['format'] === 'png') ? 'png' : (($s['format'] === 'webp') ? 'webp' : 'jpg');
    $file_path  = $og_dir . '/og-' . $post_id . '.' . $ext;

    if (!file_exists($og_dir) && !wp_mkdir_p($og_dir)) {
        error_log('Evoke OG: brak uprawnień do katalogu ' . $og_dir);
        return;
    }

    if (file_exists($file_path) && !$force) return;

    ini_set('memory_limit', '512M');

    $img = imagecreatetruecolor($s['width'], $s['height']);
    imagealphablending($img, true);
    imagesavealpha($img, true);

    // Renderuj warstwy w kolejności
    foreach (($s['layers'] ?? []) as $layer) {
        evk_og_render_layer($img, $layer, $post_id, $s);
    }

    // Zapisz plik
    $saved = false;
    switch ($s['format']) {
        case 'png':
            $saved = imagepng($img, $file_path);
            break;
        case 'webp':
            $saved = imagewebp($img, $file_path, $s['quality']);
            break;
        default:
            $saved = imagejpeg($img, $file_path, $s['quality']);
    }

    if ($saved) {
        update_post_meta($post_id, '_evk_og_url', $upload_dir['baseurl'] . '/og-images/og-' . $post_id . '.' . $ext);
    } else {
        error_log('Evoke OG: nie udało się zapisać pliku ' . $file_path);
    }
}
