<?php
// Tylko z wiersza poleceń. Pliki jadą do repozytorium, a stamtąd aktualizatorem
// na żywe strony — bez tej bramki byłyby osiągalne przez HTTP.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
/**
 * Obrazy WebP/AVIF na PRAWDZIWYM WordPressie (pierwszy testowy, stara.test):
 * prawdziwe pliki z GD, prawdziwe wgrywanie (`media_handle_sideload`, czyli
 * `wp_generate_attachment_metadata` jak przy wgraniu w panelu), prawdziwe
 * edytory obrazów (Imagick, GD).
 *
 *   php tests/php/obrazy-webp.php wp                         edytory i obsługa formatów
 *   php tests/php/obrazy-webp.php ustaw '{"enabled":1,…}'    ustawienia modułu (kopia przy pierwszym)
 *   php tests/php/obrazy-webp.php wgraj '{"w":…,"h":…,"typ":"jpg|png","nazwa":…}'
 *   php tests/php/obrazy-webp.php pliki ID                   pliki załącznika na dysku: typ, wymiary, rozmiar
 *   php tests/php/obrazy-webp.php tresc '{"id":…,"html":…}'  treść wpisu przez `the_content`
 *   php tests/php/obrazy-webp.php usun ID                    wp_delete_attachment i co zostało
 *   php tests/php/obrazy-webp.php przebieg '{"co":…}'        start, krok, stan, postarz (karta zamknięta)
 *   php tests/php/obrazy-webp.php zapamietaj                 cudze obrazy z wersjami (przed przebiegiem)
 *   php tests/php/obrazy-webp.php skasuj '{"id":…,"plik":…}' jeden plik obok załącznika
 *   php tests/php/obrazy-webp.php panel                      ekran panelu (HTML)
 *   php tests/php/obrazy-webp.php sprzataj
 *
 * Zmienna EVK_TEST_EDYTOR=GD: tylko edytor GD (serwer bez AVIF — najczęstszy
 * hosting). Bez niej WordPress wybiera Imagick, jak na serwerze, który go ma.
 */

$evk_krok = $argv[1] ?? '';
$evk_arg  = $argv[2] ?? '';
require __DIR__ . '/_testowy-wp.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
require_once ABSPATH . 'wp-admin/includes/image.php';

const EVK_TOW_TYTUL = 'evk-obrazy-test';
$evk_kopia = sys_get_temp_dir() . '/evk-t-obrazy-webp.json';

if (getenv('EVK_TEST_EDYTOR') === 'GD') {
    add_filter('wp_image_editors', static function () { return ['WP_Image_Editor_GD']; }, PHP_INT_MAX);
}
if ($evk_krok !== 'wp') wp_set_current_user((int) get_user_by('login', 'admin')->ID);

/** Typ pliku z jego bajtów (nie z rozszerzenia) i wymiary. */
function evk_tow_plik(string $p): array {
    $mime = (string) wp_get_image_mime($p);
    $w = $h = 0;
    $wym = @getimagesize($p);
    if ($wym && $wym[0] > 0) {
        [$w, $h] = $wym;
    } elseif (class_exists('Imagick')) {
        try { $i = new Imagick($p); $w = $i->getImageWidth(); $h = $i->getImageHeight(); } catch (\Throwable $e) {}
    }
    /* Krycie przy prawej krawędzi (PNG z alfą: tam przezroczysty; 1 = kryjący). */
    $alfa = null;
    if (class_exists('Imagick')) {
        try { $i = new Imagick($p); $alfa = round($i->getImagePixelColor($i->getImageWidth() - 2, (int) ($i->getImageHeight() / 2))->getColorValue(Imagick::COLOR_ALPHA), 2); } catch (\Throwable $e) {}
    }
    return ['plik' => wp_basename($p), 'mime' => $mime, 'w' => (int) $w, 'h' => (int) $h, 'bajty' => (int) filesize($p), 'alfa' => $alfa];
}

/** Wszystkie pliki z katalogu załącznika, których nazwa zaczyna się od jego nazwy bazowej. */
function evk_tow_pliki(int $id, string $baza = ''): array {
    $glowny = (string) get_attached_file($id);
    if ($baza === '') $baza = (string) preg_replace('/(-scaled)?\.(jpe?g|png)$/i', '', wp_basename($glowny));
    $kat = $glowny !== '' ? dirname($glowny) : '';
    $out = [];
    foreach ((array) glob($kat . '/' . $baza . '*') as $p) $out[wp_basename($p)] = evk_tow_plik($p);
    ksort($out);
    return $out;
}

/** Obraz „jak zdjęcie”: gradient, szum, figury — kompresuje się jak prawdziwe zdjęcie, nie jak jednolita plama. */
function evk_tow_obraz(int $w, int $h, string $typ, string $plik, bool $alfa = false): void {
    $im = imagecreatetruecolor($w, $h);
    if ($alfa) {
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
    }
    mt_srand($w * 31 + $h);
    for ($y = 0; $y < $h; $y += 2) {
        for ($x = 0; $x < $w; $x += 2) {
            $r = (int) (128 + 100 * sin($x / 37) + mt_rand(-20, 20));
            $g = (int) (128 + 100 * cos($y / 53) + mt_rand(-20, 20));
            $b = (int) (128 + 80 * sin(($x + $y) / 71) + mt_rand(-20, 20));
            $a = $alfa ? (int) max(0, min(127, ($x / max(1, $w)) * 127)) : 0;
            $c = imagecolorallocatealpha($im, max(0, min(255, $r)), max(0, min(255, $g)), max(0, min(255, $b)), $a);
            imagefilledrectangle($im, $x, $y, $x + 1, $y + 1, $c);
        }
    }
    for ($i = 0; $i < 12; $i++) {
        $c = imagecolorallocatealpha($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), $alfa ? 40 : 0);
        imagefilledellipse($im, mt_rand(0, $w), mt_rand(0, $h), mt_rand(20, (int) ($w / 3)), mt_rand(20, (int) ($h / 3)), $c);
    }
    $typ === 'png' ? imagepng($im, $plik) : imagejpeg($im, $plik, 90);
    imagedestroy($im);
}

$out = ['krok' => $evk_krok];

switch ($evk_krok) {

case 'wp':
    $out += ['wp' => untrailingslashit(ABSPATH), 'wersja' => get_bloginfo('version'), 'imagick' => class_exists('Imagick'),
        'webp' => evk_obrazy_obslugiwany('webp'), 'avif' => evk_obrazy_obslugiwany('avif'),
        'edytor_webp' => evk_obrazy_edytor('webp'), 'edytor_avif' => evk_obrazy_edytor('avif'),
        'imagick_avif' => class_exists('Imagick') && in_array('AVIF', Imagick::queryFormats('AVIF'), true),
        'gd_avif' => function_exists('imageavif'), 'modul' => function_exists('evk_obrazy_przerob')];
    break;

case 'ustaw':
    $kopia = is_file($evk_kopia) ? (json_decode((string) file_get_contents($evk_kopia), true) ?: []) : [];
    if (!array_key_exists('evk_obrazy', $kopia)) {
        file_put_contents($evk_kopia, wp_json_encode($kopia + ['evk_obrazy' => get_option('evk_obrazy', null),
            'evk_obrazy_przebieg' => get_option('evk_obrazy_przebieg', null)]));
    }
    $u = json_decode($evk_arg, true) ?: [];
    update_option('evk_obrazy', array_merge(['enabled' => 1, 'webp' => 1, 'avif' => 1, 'jakosc_webp' => 80, 'jakosc_avif' => 60, 'max_bok' => 2560], $u));
    $out['ustawienia'] = evk_obrazy_ustawienia();
    break;

case 'wgraj':
    $a = json_decode($evk_arg, true) ?: [];
    $typ = ($a['typ'] ?? 'jpg') === 'png' ? 'png' : 'jpg';
    $nazwa = sanitize_file_name(($a['nazwa'] ?? 'obraz') . '.' . $typ);
    $tmp = wp_tempnam($nazwa);
    evk_tow_obraz((int) ($a['w'] ?? 1200), (int) ($a['h'] ?? 800), $typ, $tmp, !empty($a['alfa']));
    $t0 = microtime(true);
    $id = media_handle_sideload(['name' => $nazwa, 'tmp_name' => $tmp], 0, EVK_TOW_TYTUL);
    $out['czas_s'] = round(microtime(true) - $t0, 2);
    if (is_wp_error($id)) { $out['blad'] = $id->get_error_message(); break; }
    if (!empty($a['alt'])) update_post_meta($id, '_wp_attachment_image_alt', $a['alt']);
    $m = wp_get_attachment_metadata($id);
    $out += ['id' => $id, 'plik' => $m['file'] ?? '', 'w' => $m['width'] ?? 0, 'h' => $m['height'] ?? 0,
        'oryginal' => $m['original_image'] ?? '', 'rozmiary' => array_map(static function ($r) { return $r['file']; }, $m['sizes'] ?? []),
        'meta' => get_post_meta($id, EVK_OBRAZY_META, true), 'pliki' => evk_tow_pliki((int) $id)];
    break;

case 'pliki':
    $out['pliki'] = evk_tow_pliki((int) $evk_arg);
    $out['meta'] = get_post_meta((int) $evk_arg, EVK_OBRAZY_META, true);
    break;

case 'tresc':
    $a = json_decode($evk_arg, true) ?: [];
    $id = (int) ($a['id'] ?? 0);
    $html = (string) ($a['html'] ?? '');
    if ($html === '') {
        /* Tak wstawia obraz klasyczny edytor: duży rozmiar, klasa wp-image-ID —
           srcset, sizes i loading dokłada dopiero `wp_filter_content_tags`. */
        $src = wp_get_attachment_image_src($id, $a['rozmiar'] ?? 'large');
        $html = '<p>Tekst przed.</p><p><img class="alignnone size-large wp-image-' . $id . '" src="' . esc_url($src[0]) . '" alt="Opis obrazu" width="'
            . (int) $src[1] . '" height="' . (int) $src[2] . '" /></p>';
    }
    $wpis = (int) wp_insert_post(['post_title' => EVK_TOW_TYTUL, 'post_status' => 'publish', 'post_content' => $html]);
    if ($id) set_post_thumbnail($wpis, $id);
    $GLOBALS['post'] = get_post($wpis);
    setup_postdata($GLOBALS['post']);
    $out['html'] = apply_filters('the_content', get_post_field('post_content', $wpis));
    $out['miniatura'] = $id ? get_the_post_thumbnail($wpis, 'medium') : '';
    wp_delete_post($wpis, true);
    break;

case 'usun':
    $id = (int) $evk_arg;
    $glowny = (string) get_attached_file($id);
    $baza = (string) preg_replace('/(-scaled)?\.(jpe?g|png)$/i', '', wp_basename($glowny));
    $out['przed'] = array_keys(evk_tow_pliki($id));
    $out['usuniety'] = (bool) wp_delete_attachment($id, true);
    $out['po'] = array_map('wp_basename', (array) glob(dirname($glowny) . '/' . $baza . '*'));
    break;

case 'przebieg':
    $a = json_decode($evk_arg, true) ?: [];
    $co = (string) ($a['co'] ?? 'stan');
    if ($co === 'start') evk_obrazy_start((string) ($a['tryb'] ?? 'brakujace'));
    if ($co === 'krok') evk_obrazy_porcja(EVK_OBRAZY_KROK_ILE, EVK_OBRAZY_KROK_S, 'karta');
    if ($co === 'stop') evk_obrazy_stop();
    /* Karta zamknięta X sekund temu: znacznik „żyję” i termin crona cofnięte —
       bez czekania minuty na prawdziwy zegar. Krok crona odpala potem
       PRAWDZIWY wp-cron.php przez serwer. */
    if ($co === 'postarz') {
        delete_transient('doing_cron');   // blokada WP-Cron z odwiedzin strony
        $p = get_option(EVK_OBRAZY_PRZEBIEG, []);
        $p['krok'] = (int) ($p['krok'] ?? 0) - (int) ($a['s'] ?? 120);
        update_option(EVK_OBRAZY_PRZEBIEG, $p, false);
        $kiedy = wp_next_scheduled(EVK_OBRAZY_HAK);
        if ($kiedy) {
            wp_unschedule_event($kiedy, EVK_OBRAZY_HAK);
            wp_schedule_single_event(time() - 1, EVK_OBRAZY_HAK);
        }
    }
    $out['przebieg'] = evk_obrazy_przebieg();
    wp_cache_delete(EVK_OBRAZY_BLOKADA, 'options');
    $out['blokada'] = get_option(EVK_OBRAZY_BLOKADA, null) !== null;
    $out['liczby'] = evk_obrazy_liczby();
    wp_cache_delete('evk_obrazy', 'options');
    wp_cache_delete('alloptions', 'options');
    $out['ustawienia'] = evk_obrazy_ustawienia();
    $out['cron'] = wp_next_scheduled(EVK_OBRAZY_HAK) ? wp_next_scheduled(EVK_OBRAZY_HAK) - time() : null;
    break;

/* Przed przerabianiem biblioteki: które CUDZE obrazy (z innych testów na tej
   stronie) już mają wersje. Przebieg przerobi całą bibliotekę, więc
   sprzątanie zdejmuje wersje tylko z tych, które dostały je od nas. */
case 'zapamietaj':
    global $wpdb;
    $kopia = is_file($evk_kopia) ? (json_decode((string) file_get_contents($evk_kopia), true) ?: []) : [];
    if (!isset($kopia['_cudze'])) {
        $kopia['_cudze'] = array_map('intval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", EVK_OBRAZY_META)));
        file_put_contents($evk_kopia, wp_json_encode($kopia));
    }
    $out['cudze_z_wersjami'] = $kopia['_cudze'];
    break;

/* Jeden plik obok załącznika (np. wersja WebP jednego rozmiaru) — skasowany. */
case 'skasuj':
    $a = json_decode($evk_arg, true) ?: [];
    $p = dirname((string) get_attached_file((int) ($a['id'] ?? 0))) . '/' . wp_basename((string) ($a['plik'] ?? ''));
    $out['byl'] = is_file($p);
    if ($out['byl']) unlink($p);
    break;

/* Meta obrazów testu (do sprawdzenia, które przerobił przebieg). */
case 'metas':
    global $wpdb;
    $ids = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_title = %s ORDER BY ID", EVK_TOW_TYTUL)));
    $out['metas'] = [];
    foreach ($ids as $id) $out['metas'][$id] = get_post_meta($id, EVK_OBRAZY_META, true);
    break;

case 'panel':
    require_once ABSPATH . 'wp-admin/includes/template.php';
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    ob_start();
    require EVOKE_ONE_DIR . 'includes/admin/tab-obrazy.php';
    $out['html'] = ob_get_clean();
    break;

case 'sprzataj':
    global $wpdb;
    foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_title = %s", EVK_TOW_TYTUL)) as $id) {
        get_post_type((int) $id) === 'attachment' ? wp_delete_attachment((int) $id, true) : wp_delete_post((int) $id, true);
    }
    if (is_file($evk_kopia)) {
        $kopia = (array) json_decode((string) file_get_contents($evk_kopia), true);
        if (isset($kopia['_cudze'])) {
            /* Wersje, które przebieg zrobił cudzym obrazom — pliki i meta precz. */
            foreach ((array) $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", EVK_OBRAZY_META)) as $id) {
                if (in_array((int) $id, (array) $kopia['_cudze'], true)) continue;
                foreach (evk_obrazy_zrodla((int) $id) as $src) {
                    foreach (['webp', 'avif'] as $fmt) if (is_file($src . '.' . $fmt)) unlink($src . '.' . $fmt);
                }
                delete_post_meta((int) $id, EVK_OBRAZY_META);
                $out['cudze_posprzatane'][] = (int) $id;
            }
            unset($kopia['_cudze']);
        }
        foreach ($kopia as $o => $w) {
            $w === null ? delete_option($o) : update_option($o, $w, false);
        }
        unlink($evk_kopia);
    }
    wp_clear_scheduled_hook(EVK_OBRAZY_HAK);
    delete_option(EVK_OBRAZY_BLOKADA);
    $out['gotowe'] = true;
    break;

default:
    $out['brak'] = 'nieznany krok';
}

echo wp_json_encode($out);
