<?php
if (!defined('ABSPATH')) exit;

/**
 * Evoke ONE Backup — sprawdzenie środowiska serwera.
 *
 * Dwie warstwy, rozdzielone po to, żeby dało się je sprawdzić bez serwera:
 *
 *   evk_backup_environment_facts()   ZBIERA fakty (ini, klasy, dysk, serwer),
 *   evk_backup_environment_checks()  OCENIA je — czysta funkcja na tablicy.
 *
 * Test podaje ocenie fakty wymyślone („brak ZipArchive", „nginx") i patrzy,
 * co z tego wychodzi — bez podmieniania PHP-a.
 *
 * Status wiersza: ok | warn | err | info. `err` BLOKUJE moduł: bez tego
 * kopia nie powstanie albo nie da się jej odtworzyć, więc włącznik jest
 * wtedy nieaktywny, zamiast pozwalać na ciche niepowodzenie w nocy.
 */

function evk_backup_environment_facts(): array {
    global $wpdb;
    $content = defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH . 'wp-content';
    $wolne   = function_exists('disk_free_space') ? @disk_free_space($content) : false;
    $tabela  = null;
    /* Czy katalog kopii da się pobrać z sieci — sprawdzone kanarkiem
       (storage.php), z pamięcią na 12 h. Tylko przy włączonym module:
       wyłączony nie zakłada katalogu. */
    $wyst = '';
    if (evk_backup_enabled() && function_exists('evk_backup_exposure_cached')) {
        $r = evk_backup_exposure_cached();
        $wyst = $r === true ? 'tak' : ($r === false ? 'nie' : 'nieznane');
    }
    if (evk_backup_enabled() && isset($wpdb)) {
        $tabela = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', evk_backup_jobs_table())) !== '';
    }
    return [
        'php'            => PHP_VERSION,
        'zip'            => class_exists('ZipArchive'),
        'libzip'         => class_exists('ZipArchive') && defined('ZipArchive::LIBZIP_VERSION') ? (string) constant('ZipArchive::LIBZIP_VERSION') : '',
        'deflate'        => function_exists('deflate_init'),
        'multisite'      => function_exists('is_multisite') && is_multisite(),
        'server'         => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
        'max_execution'  => (int) ini_get('max_execution_time'),
        'memory_limit'   => (string) ini_get('memory_limit'),
        'upload_max'     => (string) ini_get('upload_max_filesize'),
        'post_max'       => (string) ini_get('post_max_size'),
        'disk_free'      => $wolne === false ? null : (float) $wolne,
        'content_write'  => is_writable($content),
        'wp_cron_off'    => defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
        'jobs_table'     => $tabela,
        'dir_exposed'    => $wyst,
    ];
}

/** „128M" → bajty. -1 (bez limitu) zostaje -1. */
function evk_backup_ini_bytes(string $v): int {
    $v = trim($v);
    if ($v === '' ) return 0;
    if ($v === '-1') return -1;
    $n = (int) $v;
    switch (strtolower(substr($v, -1))) {
        case 'g': $n *= 1024;
        // no break
        case 'm': $n *= 1024;
        // no break
        case 'k': $n *= 1024;
    }
    return $n;
}

function evk_backup_bytes_label(float $b): string {
    $j = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($b >= 1024 && $i < count($j) - 1) { $b /= 1024; $i++; }
    return ($i === 0 ? (string) (int) $b : number_format($b, 1, ',', '')) . ' ' . $j[$i];
}

/**
 * Ocena faktów. Każdy wiersz: label, value, status, note.
 */
function evk_backup_environment_checks(array $f): array {
    $w = [];

    $w[] = ['label' => 'ZipArchive', 'value' => $f['zip'] ? ('jest' . ($f['libzip'] !== '' ? ', libzip ' . $f['libzip'] : '')) : 'brak',
            'status' => $f['zip'] ? 'ok' : 'err',
            'note' => $f['zip'] ? 'Odczyt archiwów przy przywracaniu.' : 'Bez rozszerzenia zip nie da się przywrócić kopii. Poproś hosting o włączenie rozszerzenia PHP „zip".'];

    $w[] = ['label' => 'Kompresja (zlib)', 'value' => $f['deflate'] ? 'jest' : 'brak',
            'status' => $f['deflate'] ? 'ok' : 'err',
            'note' => $f['deflate'] ? 'Zapis archiwum w kawałkach między krokami.' : 'Bez zlib (deflate_init) kopia nie powstanie. Poproś hosting o rozszerzenie PHP „zlib".'];

    $w[] = ['label' => 'WordPress Multisite', 'value' => $f['multisite'] ? 'tak' : 'nie',
            'status' => $f['multisite'] ? 'err' : 'ok',
            'note' => $f['multisite'] ? 'Sieć stron nie jest obsługiwana — kopia jednej strony z sieci nie dałaby się odtworzyć.' : ''];

    $w[] = ['label' => 'Zapis do wp-content', 'value' => $f['content_write'] ? 'możliwy' : 'zablokowany',
            'status' => $f['content_write'] ? 'ok' : 'err',
            'note' => $f['content_write'] ? '' : 'Katalog kopii powstaje w wp-content — bez prawa zapisu nie ma gdzie ich trzymać.'];

    $w[] = ['label' => 'PHP', 'value' => $f['php'], 'status' => 'info', 'note' => ''];

    /* Serwer: na nginx .htaccess nie działa, więc katalog kopii chroni
       wyłącznie jego losowa nazwa — warto to wiedzieć, a nie zakładać. */
    $nginx = stripos($f['server'], 'nginx') !== false;
    $w[] = ['label' => 'Serwer WWW', 'value' => $f['server'] !== '' ? $f['server'] : 'nieznany',
            'status' => $nginx ? 'warn' : 'info',
            'note' => $nginx ? 'nginx nie czyta .htaccess — katalog kopii chroni losowa nazwa, a pliki pobiera się wyłącznie przez panel.' : ''];

    $me = (int) $f['max_execution'];
    $w[] = ['label' => 'Limit czasu PHP', 'value' => $me === 0 ? 'brak (0)' : $me . ' s',
            'status' => 'info',
            'note' => 'Tylko punkt wyjścia: serwer WWW ma własne limity, niewidoczne z PHP. Kopia mierzy, ile kroków przeżywa, i sama dobiera ich długość.'];

    $ml = evk_backup_ini_bytes((string) $f['memory_limit']);
    $w[] = ['label' => 'Limit pamięci', 'value' => $ml === -1 ? 'bez limitu (-1)' : $f['memory_limit'],
            'status' => ($ml !== -1 && $ml > 0 && $ml < 64 * 1048576) ? 'warn' : 'info',
            'note' => ($ml !== -1 && $ml > 0 && $ml < 64 * 1048576) ? 'Mniej niż 64 MB — duże wiersze bazy (np. układy Bricksa) mogą się nie zmieścić.' : ''];

    $up = min(array_filter([evk_backup_ini_bytes((string) $f['upload_max']), evk_backup_ini_bytes((string) $f['post_max'])],
        static function ($x) { return $x > 0; }) ?: [0]);
    $w[] = ['label' => 'Największy plik z przeglądarki', 'value' => $up > 0 ? evk_backup_bytes_label((float) $up) : 'bez limitu',
            'status' => 'info',
            'note' => 'Kopie wgrywane z panelu pójdą w kawałkach, więc ten limit ich nie ogranicza.'];

    if ($f['disk_free'] === null) {
        $w[] = ['label' => 'Wolne miejsce', 'value' => 'hosting nie podaje', 'status' => 'info', 'note' => ''];
    } else {
        $malo = $f['disk_free'] < 1073741824;
        $w[] = ['label' => 'Wolne miejsce', 'value' => evk_backup_bytes_label((float) $f['disk_free']),
                'status' => $malo ? 'warn' : 'ok',
                'note' => $malo ? 'Poniżej 1 GB — kopia strony z mediami może się nie zmieścić.' : ''];
    }

    $w[] = ['label' => 'WP-Cron', 'value' => $f['wp_cron_off'] ? 'wyłączony (DISABLE_WP_CRON)' : 'włączony',
            'status' => 'info',
            'note' => $f['wp_cron_off'] ? 'Nocna kopia potrzebuje wtedy crona systemowego — instrukcja pojawi się tu razem z harmonogramem.' : ''];

    $wyst = (string) ($f['dir_exposed'] ?? '');
    if ($wyst !== '') {
        $w[] = [
            'label'  => 'Katalog kopii z sieci',
            'value'  => ['tak' => 'DOSTĘPNY', 'nie' => 'zablokowany', 'nieznane' => 'nie sprawdzono'][$wyst] ?? $wyst,
            'status' => ['tak' => 'warn', 'nie' => 'ok'][$wyst] ?? 'info',
            'note'   => [
                'tak' => 'Serwer podaje pliki z katalogu kopii mimo .htaccess (albo go nie czyta). Kopie chroni wtedy tylko losowa, 20-znakowa nazwa katalogu — pobieraj je wyłącznie przez panel.',
                'nie' => 'Sprawdzone plikiem próbnym: serwer odmawia dostępu z sieci.',
            ][$wyst] ?? 'Serwer nie odpowiedział sam sobie (zapora, Basic Auth, DNS). To samo może blokować pracę kopii w tle — kopia pójdzie wtedy przez WP-Cron i otwartą kartę.',
        ];
    }

    if ($f['jobs_table'] !== null) {
        $w[] = ['label' => 'Tabela zadań', 'value' => $f['jobs_table'] ? 'jest' : 'brak',
                'status' => $f['jobs_table'] ? 'ok' : 'err',
                'note' => $f['jobs_table'] ? '' : 'Nie udało się utworzyć tabeli evk_backup_jobs — sprawdź uprawnienia użytkownika bazy (CREATE TABLE).'];
    }

    return $w;
}

/** Czy któryś wiersz blokuje moduł. */
function evk_backup_environment_blocked(array $checks): bool {
    foreach ($checks as $c) {
        if ($c['status'] === 'err') return true;
    }
    return false;
}
