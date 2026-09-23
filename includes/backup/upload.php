<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — wgrywanie kopii z przeglądarki, w kawałkach.
 *
 * Kopia bywa większa niż cokolwiek, co przejdzie jednym żądaniem
 * (upload_max_filesize, post_max_size, limity proxy i Cloudflare), więc
 * przeglądarka tnie plik (File.slice) i wysyła kawałki po kolei. Serwer
 * dopisuje je do części `.wgrywanie-<id>.part` w katalogu kopii (chronionym
 * jak kopie), a po ostatnim sprawdza archiwum i dopiero wtedy wpisuje je na
 * listę.
 *
 * WZNOWIENIE (decyzja z 1.228.0): identyfikator jest DETERMINISTYCZNY —
 * HMAC z nazwy, rozmiaru, czasu modyfikacji pliku i użytkownika — więc ten
 * sam plik wybrany ponownie po zamknięciu karty trafia w tę samą część,
 * a start oddaje, ile już jest. Części porzucone na dłużej niż dobę znikają.
 *
 * KAWAŁEK DOPISYWANY TYLKO NA SWOJE MIEJSCE: `offset` musi równać się
 * rozmiarowi części. Inaczej (kawałek powtórzony po zgubionej odpowiedzi,
 * dwie karty) serwer niczego nie pisze i oddaje rzeczywisty offset — klient
 * przeskakuje do niego. Plik nie może się rozjechać nawet przy ponowieniach.
 *
 * Rdzeń (funkcje *_core) nie woła wp_send_json — testuje go wprost
 * tests/php/backup-wgrywanie.php; żądania AJAX są cienką warstwą niżej.
 */

const EVK_BACKUP_UPLOAD_CHUNK_MAX = 8388608;   // 8 MB
const EVK_BACKUP_UPLOAD_TTL       = DAY_IN_SECONDS;

/**
 * Wielkość kawałka: najwyżej 8 MB, ale nie więcej, niż przyjmie ten serwer —
 * post_max_size liczy całe żądanie z nagłówkami części formularza (zapas 64 KB).
 */
function evk_backup_upload_chunk_size(): int {
    $wg = evk_backup_ini_bytes((string) ini_get('upload_max_filesize'));
    $post = evk_backup_ini_bytes((string) ini_get('post_max_size'));
    $n = EVK_BACKUP_UPLOAD_CHUNK_MAX;
    if ($wg > 0) $n = min($n, $wg);
    if ($post > 0) $n = min($n, $post - 65536);
    return max(65536, (int) apply_filters('evk_backup_upload_chunk', $n));
}

function evk_backup_upload_part(string $id): string {
    return evk_backup_dir() . '/.wgrywanie-' . $id . '.part';
}

function evk_backup_upload_valid_id(string $id): bool {
    return (bool) preg_match('/^[a-f0-9]{40}$/', $id);
}

/**
 * Początek (albo wznowienie) wgrywania. Oddaje {id, offset, chunk}.
 * Rzuca RuntimeException z czytelnym powodem.
 */
function evk_backup_upload_start_core(string $nazwa, int $rozmiar, int $mtime, int $uzytkownik): array {
    if (!preg_match('/\.zip$/i', $nazwa)) throw new \RuntimeException('Kopia to plik .zip — ten ma inne rozszerzenie.');
    if ($rozmiar < 22) throw new \RuntimeException('Plik jest za mały, żeby był archiwum ZIP.');
    $dir = evk_backup_dir();
    if (!evk_backup_ensure_dir($dir)) throw new \RuntimeException('Nie można zapisać w katalogu kopii.');
    evk_backup_upload_cleanup();

    $id = hash_hmac('sha1', $nazwa . '|' . $rozmiar . '|' . $mtime . '|' . $uzytkownik, evk_backup_loopback_key());
    $part = evk_backup_upload_part($id);
    clearstatcache(true, $part);
    $jest = is_file($part) ? (int) filesize($part) : 0;
    if ($jest > $rozmiar) { @unlink($part); $jest = 0; }   // nie ta część — od nowa

    $wolne = apply_filters('evk_backup_upload_free_space', function_exists('disk_free_space') ? @disk_free_space($dir) : false);
    $brakuje = $rozmiar - $jest;
    // Zapas: kopia zaraz potrzebuje miejsca także na rozpakowanie przy przywracaniu — tu pilnujemy tylko wgrania.
    if ($wolne !== false && (float) $wolne < $brakuje + 16 * 1048576) {
        throw new \RuntimeException(sprintf('Za mało miejsca na serwerze: do wgrania %s, wolne %s.',
            evk_backup_bytes_label((float) $brakuje), evk_backup_bytes_label((float) $wolne)));
    }

    file_put_contents($part . '.json', wp_json_encode(['name' => $nazwa, 'size' => $rozmiar, 'mtime' => $mtime,
        'user' => $uzytkownik, 'created' => time()]));
    if (!is_file($part)) touch($part);
    return ['id' => $id, 'offset' => $jest, 'chunk' => evk_backup_upload_chunk_size()];
}

/**
 * Jeden kawałek z pliku $kawalek na pozycję $offset. Oddaje
 * {offset: rozmiar części po operacji, done, archive?}. Kawałek nie na swoim
 * miejscu nie jest zapisywany — oddajemy wtedy rzeczywisty offset.
 */
function evk_backup_upload_chunk_core(string $id, int $offset, string $kawalek): array {
    if (!evk_backup_upload_valid_id($id)) throw new \RuntimeException('Zły identyfikator wgrywania.');
    $part = evk_backup_upload_part($id);
    $meta = json_decode((string) @file_get_contents($part . '.json'), true);
    if (!is_array($meta) || !is_file($part)) throw new \RuntimeException('Wgrywanie wygasło albo zostało anulowane — wybierz plik jeszcze raz.');
    $rozmiar = (int) $meta['size'];

    $fh = fopen($part, 'ab');
    if (!$fh) throw new \RuntimeException('Nie można zapisać części kopii.');
    try {
        flock($fh, LOCK_EX);
        clearstatcache(true, $part);
        $jest = (int) filesize($part);
        if ($offset !== $jest) return ['offset' => $jest, 'done' => false, 'resync' => true];

        $dl = (int) @filesize($kawalek);
        if ($dl <= 0) throw new \RuntimeException('Pusty kawałek.');
        if ($jest + $dl > $rozmiar) throw new \RuntimeException('Kawałek wychodzi poza zapowiedziany rozmiar pliku.');
        $in = fopen($kawalek, 'rb');
        if (!$in) throw new \RuntimeException('Nie można odczytać kawałka.');
        $zapisane = stream_copy_to_stream($in, $fh);
        fclose($in);
        fflush($fh);
        if ($zapisane !== $dl) {
            ftruncate($fh, $jest);   // bez połowy kawałka — klient wyśle go jeszcze raz
            throw new \RuntimeException('Zapis kawałka nie powiódł się (brak miejsca na dysku?).');
        }
        $jest += $dl;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }

    if ($jest < $rozmiar) return ['offset' => $jest, 'done' => false];

    // Całość: sprawdzenie, że to kopia tej wtyczki, zanim trafi na listę.
    try {
        evk_restore_read_manifest($part);
    } catch (\RuntimeException $e) {
        @unlink($part);
        @unlink($part . '.json');
        throw new \RuntimeException('To nie jest kopia tej wtyczki: ' . $e->getMessage());
    }
    $archiwum = evk_backup_register_upload($part, (string) $meta['name']);
    @unlink($part . '.json');
    if ($archiwum === '') throw new \RuntimeException('Nie udało się przenieść wgranej kopii do katalogu kopii.');
    return ['offset' => $jest, 'done' => true, 'archive' => $archiwum];
}

function evk_backup_upload_cancel_core(string $id): bool {
    if (!evk_backup_upload_valid_id($id)) return false;
    $part = evk_backup_upload_part($id);
    $byla = is_file($part);
    @unlink($part);
    @unlink($part . '.json');
    return $byla;
}

/** Części porzucone na dłużej niż dobę. Oddaje liczbę usuniętych. */
function evk_backup_upload_cleanup(): int {
    $n = 0;
    foreach (glob(evk_backup_dir() . '/.wgrywanie-*.part') ?: [] as $p) {
        if (time() - (int) filemtime($p) < EVK_BACKUP_UPLOAD_TTL) continue;
        @unlink($p);
        @unlink($p . '.json');
        $n++;
    }
    return $n;
}

// =========================================================================
// ŻĄDANIA Z PANELU
// =========================================================================

add_action('wp_ajax_evk_backup_upload_start', function () {
    evk_backup_ajax_guard();
    try {
        wp_send_json_success(evk_backup_upload_start_core(sanitize_file_name(wp_unslash($_POST['name'] ?? '')),
            absint($_POST['size'] ?? 0), absint($_POST['mtime'] ?? 0), get_current_user_id()));
    } catch (\RuntimeException $e) {
        wp_send_json_error(['msg' => $e->getMessage()]);
    }
});

add_action('wp_ajax_evk_backup_upload_chunk', function () {
    evk_backup_ajax_guard();
    $plik = $_FILES['chunk'] ?? null;
    if (!is_array($plik) || (int) ($plik['error'] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $plik['tmp_name'])) {
        wp_send_json_error(['msg' => 'Kawałek nie dotarł (błąd wgrywania PHP: ' . (int) ($plik['error'] ?? -1) . ').', 'retry' => true]);
    }
    try {
        $wynik = evk_backup_upload_chunk_core(sanitize_key(wp_unslash($_POST['id'] ?? '')), absint($_POST['offset'] ?? 0),
            (string) $plik['tmp_name']);
        if (!empty($wynik['done'])) $wynik['html'] = evk_backup_render_list();
        wp_send_json_success($wynik);
    } catch (\RuntimeException $e) {
        wp_send_json_error(['msg' => $e->getMessage()]);
    }
});

add_action('wp_ajax_evk_backup_upload_cancel', function () {
    evk_backup_ajax_guard();
    wp_send_json_success(['cancelled' => evk_backup_upload_cancel_core(sanitize_key(wp_unslash($_POST['id'] ?? '')))]);
});
